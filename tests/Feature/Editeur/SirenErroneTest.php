<?php

namespace Tests\Feature\Editeur;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CorrigerSirenAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ExaminerSirenAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * L'examen et la correction exceptionnels du SIREN d'une société validée
 * par l'éditeur (voir ExaminerSirenAction et CorrigerSirenAction) : le
 * courrier d'examen part à l'entité du SIREN correct, et son code saisi le
 * prouve ; l'examen liste les documents émis concernés sans en modifier
 * aucun ; seule une erreur constatée se corrige, par deux opérateurs ; le
 * SIREN et le SIRET de chaque magasin qui peut encore vendre changent à
 * leur journal des événements, les anciens SIRET restent réservés, et
 * aucun document émis ne change.
 */
class SirenErroneTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'SIREN d\'une filiale saisi à la place de celui de la société';

    private const PIECE = 'DOSSIER-2026-0095';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            'bruno' => password_hash('secret-de-bruno', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
    }

    /**
     * Sans le code du courrier d'examen saisi, rien ne s'examine ; ensuite,
     * la commande liste chaque document émis, avec le SIREN qu'il a signé,
     * et l'examen s'inscrit au journal d'audit avec sa décision, sans
     * toucher au registre du magasin.
     */
    public function test_l_examen_liste_les_documents_concernes_sans_en_modifier_aucun(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $societe = $magasin->societe;
        $ticket = $this->vendre($magasin);
        $signatures = $this->signatures($magasin);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $jour = Calendrier::afficherJour($magasin->calendrier()->jourDe($ticket->date_emission), 'd/m/Y');

        $this->assertThrows(fn () => app(ExaminerSirenAction::class)->handle($societe, $sirenCorrect, DecisionExamenSiren::Erreur, $this->editeur(['alice'])), VerificationRefuseeException::class, ExaminerSirenAction::MESSAGE_SANS_CODE);
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);

        $this->artisan('editeur:societe:examiner-siren', [
            'societe' => (string) $societe->id,
            '--siren-correct' => $sirenCorrect,
            '--decision' => 'erreur',
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice')
            ->expectsOutputToContain("LYON · ticket {$ticket->numero} du {$jour} · {$ticket->client_nom} · 12,00 € TTC, dont 12,00 € réglés · SIREN signé {$societe->siren}")
            ->expectsOutputToContain('Examen inscrit : 1 document(s) concerné(s), décision « SIREN saisi par erreur, à corriger ».')
            ->assertSuccessful();

        [$examen] = $this->entreesDAudit($societe, ActionAuditee::SirenExamine);
        $this->assertSame(['decision' => 'erreur', 'documents' => [['magasin' => 'LYON', 'numero' => $ticket->numero, 'type' => 'ticket']], 'siren_correct' => $sirenCorrect], $examen->nouvelles_valeurs);
        $this->assertSame(['editeur', ['alice']], [$examen->origine, $examen->operateur['operateurs']]);
        $this->assertSame($signatures, $this->signatures($magasin));
        $this->assertSame((string) $societe->siren, $societe->fresh()->siren);
    }

    /**
     * Sans examen, après un examen qui conclut à une usurpation, pour un
     * autre SIREN, avec un seul opérateur ou sans le nouveau SIRET de chaque
     * magasin, rien ne se corrige ; la commande refuse aussi le même
     * opérateur deux fois.
     */
    public function test_la_correction_exige_un_examen_concluant_a_une_erreur_et_deux_operateurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $societe = $magasin->societe;
        $sirenCorrect = SocieteFactory::sirenFictif();
        $sirets = ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')];

        $this->assertThrows(fn () => $this->corriger($societe, $sirenCorrect, $sirets), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_SANS_EXAMEN);
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Usurpation);
        $this->assertThrows(fn () => $this->corriger($societe, $sirenCorrect, $sirets), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_SANS_EXAMEN);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->assertThrows(fn () => $this->corriger($societe, SocieteFactory::sirenFictif(), $sirets), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_SANS_EXAMEN);
        $this->assertThrows(fn () => app(CorrigerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $sirets, null, $this->editeur(['alice'])), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_DEUX_OPERATEURS);
        $this->assertThrows(fn () => $this->corriger($societe, $sirenCorrect, []), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_SIRETS);
        $this->corrigerParLaCommande($societe, $sirenCorrect, $sirets, 'alice', 'alice')
            ->expectsOutputToContain('Cette procédure exige deux opérateurs distincts : rien n\'est fait.')
            ->assertFailed();

        $this->assertSame((string) $magasin->siret, (string) $magasin->fresh()->siret);
        $this->assertNotSame($sirenCorrect, $societe->fresh()->siren);
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::SirenCorrige));
    }

    /**
     * Corrigé par la commande de deux opérateurs, le SIREN change avec le
     * n° de TVA qu'il formait, et chaque magasin qui peut encore vendre
     * passe à son nouveau SIRET, à son journal des événements ; un magasin
     * fermé pour cessation garde le sien ; les anciens SIRET restent
     * réservés ; la correction s'inscrit au journal d'audit avec les deux
     * opérateurs, la société garde un seul propriétaire actif, prévenu, et
     * le registre de chaque magasin reste intègre.
     */
    public function test_la_correction_journalise_dans_chaque_magasin_et_garde_les_anciens_siret_reserves(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne', 'code' => 'VILL']);
        $bron = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Bron', 'code' => 'BRON']);
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($lyon), $bron->fresh(), MotifFermeture::Cessation, 'password');
        $societe = $lyon->societe;
        $ancienSiren = (string) $societe->siren;
        $anciens = ['LYON' => (string) $lyon->siret, 'VILL' => (string) $villeurbanne->siret];
        $sirenCorrect = SocieteFactory::sirenFictif();
        $nouveaux = ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001'), 'VILL' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0002')];
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);

        $this->corrigerParLaCommande($societe, $sirenCorrect, $nouveaux, 'alice', 'bruno')
            ->expectsOutputToContain("SIREN corrigé : {$sirenCorrect} ; les documents émis gardent l'ancien.")
            ->assertSuccessful();

        $corrigee = $societe->fresh();
        $this->assertSame([$sirenCorrect, IdentifiantsInsee::numeroTvaPropose($sirenCorrect)], [$corrigee->siren, $corrigee->numero_tva]);
        $this->assertSame([$nouveaux['LYON'], $nouveaux['VILL'], (string) $bron->siret], [(string) $lyon->fresh()->siret, (string) $villeurbanne->fresh()->siret, (string) $bron->fresh()->siret]);
        foreach ([...array_values($anciens), ...array_values($nouveaux), (string) $bron->siret] as $siret) {
            $this->assertTrue(SiretsDesMagasins::estReserve($siret), $siret);
        }

        foreach ([[$lyon, 'LYON'], [$villeurbanne, 'VILL']] as [$magasin, $code]) {
            $parametres = $this->parametresJournalises($magasin);
            $this->assertContains(['parametre' => 'siren', 'ancienne_valeur' => $ancienSiren, 'nouvelle_valeur' => $sirenCorrect], $parametres);
            $this->assertContains(['parametre' => 'numero_tva', 'ancienne_valeur' => IdentifiantsInsee::numeroTvaPropose($ancienSiren), 'nouvelle_valeur' => IdentifiantsInsee::numeroTvaPropose($sirenCorrect)], $parametres);
            $this->assertContains(['parametre' => 'siret', 'ancienne_valeur' => $anciens[$code], 'nouvelle_valeur' => $nouveaux[$code]], $parametres);
            $this->assertTrue(app(TenantContext::class)->runAs($magasin->fresh(), fn (): bool => app(VerificateurIntegrite::class)->verifier($magasin->fresh())->estConforme()));
        }

        $this->assertContains(['parametre' => 'siren', 'ancienne_valeur' => $ancienSiren, 'nouvelle_valeur' => $sirenCorrect], $this->parametresJournalises($bron));
        $this->assertNotContains('siret', array_column($this->parametresJournalises($bron), 'parametre'));
        [$correction] = $this->entreesDAudit($societe, ActionAuditee::SirenCorrige);
        $this->assertSame(['editeur', self::JUSTIFICATION, ['alice', 'bruno']], [$correction->origine, $correction->justification, $correction->operateur['operateurs']]);
        $this->assertSame(['numero_tva' => IdentifiantsInsee::numeroTvaPropose($ancienSiren), 'siren' => $ancienSiren, 'sirets' => $anciens], $correction->anciennes_valeurs);
        $this->assertSame(['numero_tva' => IdentifiantsInsee::numeroTvaPropose($sirenCorrect), 'siren' => $sirenCorrect, 'sirets' => $nouveaux], $correction->nouvelles_valeurs);
        $this->assertSame(1, User::query()->where('societe_id', $societe->id)->where('role_societe', RoleSociete::Proprietaire->value)->where('statut', StatutCompte::Actif->value)->count());
        $this->assertContains('Le SIREN de votre société est corrigé', $this->proprietaireDe($lyon)->notifications()->get()->pluck('data.title')->all());
    }

    /**
     * Les documents émis avant la correction gardent l'ancien SIREN qu'ils
     * ont signé : leur registre et leur émetteur figé ne changent pas.
     */
    public function test_aucun_document_emis_ne_change(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $ancienSiren = (string) $magasin->societe->siren;
        $ticket = $this->vendre($magasin);
        $signatures = $this->signatures($magasin);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->prouverLaRemiseDuCourrierDExamen($magasin->societe, $sirenCorrect);
        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Erreur);

        $this->corriger($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);

        $this->assertSame($signatures, array_intersect_key($this->signatures($magasin), $signatures));
        app(TenantContext::class)->set($magasin->fresh());
        $relu = $ticket->fresh();
        $this->assertSame([$ticket->numero, '12.00', $ancienSiren, (string) $magasin->siret], [$relu->numero, $relu->total_ttc, $relu->emetteur()->siren, $relu->emetteur()->siret]);
    }

    public function test_un_siren_deja_valide_par_une_autre_societe_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $sirenPris = (string) $this->ouvrirUneSociete(['nom' => 'Autre'])->societe->siren;
        $this->prouverLaRemiseDuCourrierDExamen($magasin->societe, $sirenPris);
        $this->examiner($magasin->societe, $sirenPris, DecisionExamenSiren::Erreur);

        $this->assertThrows(fn () => $this->corriger($magasin->societe, $sirenPris, ['LYON' => IdentifiantsInsee::avecSaCle($sirenPris.'0003')]), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_SIREN_PRIS);

        $this->assertNotSame($sirenPris, $magasin->societe->fresh()->siren);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::SirenCorrige));
    }

    /**
     * Le code d'activité et l'inscription à l'Ordre d'un cabinet
     * d'expertise comptable ont été vérifiés sous son SIREN : cette
     * procédure ne le corrige pas.
     */
    public function test_le_siren_d_un_cabinet_ne_se_corrige_pas_par_cette_procedure(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $sirenCorrect = SocieteFactory::sirenFictif();

        $this->assertThrows(fn () => $this->corriger($cabinet, $sirenCorrect, []), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_CABINET);

        $this->assertNotSame($sirenCorrect, $cabinet->fresh()->siren);
    }

    /**
     * @param  list<string>  $operateurs
     */
    private function editeur(array $operateurs = ['alice', 'bruno']): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, $operateurs);
    }

    /**
     * L'éditeur envoie le courrier d'examen au représentant légal de
     * l'entité du SIREN correct, à l'adresse officielle de son siège, et son
     * code est saisi sur sa page, sous ce SIREN.
     */
    private function prouverLaRemiseDuCourrierDExamen(Societe $societe, string $sirenCorrect): void
    {
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::ExamenSiren, $this->editeur(['alice']), $sirenCorrect);
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure($sirenCorrect, MotifCode::ExamenSiren, $this->codeDuCourrier($societe, MotifCode::ExamenSiren), '127.0.0.1');
        app(TenantContext::class)->clear();
    }

    private function examiner(Societe $societe, string $sirenCorrect, DecisionExamenSiren $decision): void
    {
        app(ExaminerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $decision, $this->editeur(['alice']));
        app(TenantContext::class)->clear();
    }

    /**
     * @param  array<string, string>  $sirets
     */
    private function corriger(Societe $societe, string $sirenCorrect, array $sirets): void
    {
        app(TenantContext::class)->clear();
        app(CorrigerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $sirets, null, $this->editeur());
        app(TenantContext::class)->clear();
    }

    /**
     * La commande de la correction, lancée par ces deux opérateurs, avec
     * les codes des magasins saisis en minuscules.
     *
     * @param  array<string, string>  $sirets
     */
    private function corrigerParLaCommande(Societe $societe, string $sirenCorrect, array $sirets, string $premier, string $second): PendingCommand
    {
        $secrets = ['alice' => 'secret-d-alice', 'bruno' => 'secret-de-bruno'];
        app(TenantContext::class)->clear();

        return $this->artisan('editeur:societe:corriger-siren', [
            'societe' => (string) $societe->id,
            '--siren' => $sirenCorrect,
            '--siret' => array_map(fn (string $code, string $siret): string => strtolower($code).':'.$siret, array_keys($sirets), array_values($sirets)),
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur (premier)', $premier)
            ->expectsQuestion('Secret de l\'opérateur (premier)', $secrets[$premier])
            ->expectsQuestion('Nom de l\'opérateur (second)', $second)
            ->expectsQuestion('Secret de l\'opérateur (second)', $secrets[$second]);
    }

    /**
     * Un ticket de 12,00 € TTC au comptoir du magasin, payé en espèces.
     */
    private function vendre(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin->fresh());

        return self::vendreAuComptant(
            self::responsableDeTest($magasin),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }

    /**
     * La signature de chaque enregistrement du registre fiscal du magasin.
     *
     * @return array<int, string>
     */
    private function signatures(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()->orderBy('id')->pluck('signature', 'id')->all());
    }

    /**
     * Les paramètres de conformité inscrits au journal des événements du
     * magasin (JET 270), dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function parametresJournalises(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => array_intersect_key($evenement->contenuSigne()['donnees']['details'], array_flip(['parametre', 'ancienne_valeur', 'nouvelle_valeur'])))
            ->all());
    }
}
