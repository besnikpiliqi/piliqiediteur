<?php

namespace Tests\Feature\Editeur;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Rules\NumeroTvaAuFormat;
use App\Domain\Tenancy\Actions\CorrigerSirenAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ExaminerSirenAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\LibererUnSiretSaisiParErreurAction;
use App\Domain\Tenancy\Actions\RevoquerSocieteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
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
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
    }

    /**
     * Sans le code du courrier d'examen saisi, rien ne s'examine ; ensuite,
     * la commande liste chaque document émis, avec le SIREN qu'il a signé,
     * et l'examen s'inscrit au journal d'audit avec sa décision et le
     * résumé de ces documents (leur nombre, leur premier et leur dernier
     * numéro par magasin et par type, l'instant jusqu'auquel ils ont été
     * émis et l'empreinte de leur liste), sans toucher au registre du
     * magasin.
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
        $this->assertSame([
            'decision' => 'erreur',
            'documents' => [['dernier' => $ticket->numero, 'magasin' => 'LYON', 'nombre' => 1, 'premier' => $ticket->numero, 'type' => 'ticket']],
            'documents_emis_jusqu_au' => self::enUtc('2026-11-02 09:00:00')->format('Y-m-d H:i:s'),
            'empreinte_des_documents' => hash('sha256', "LYON\tticket\t{$ticket->numero}\n"),
            'siren_correct' => $sirenCorrect,
        ], $examen->nouvelles_valeurs);
        $this->assertSame(['editeur', ['alice']], [$examen->origine, $examen->operateur['operateurs']]);
        $this->assertSame($signatures, $this->signatures($magasin));
        $this->assertSame((string) $societe->siren, $societe->fresh()->siren);
    }

    /**
     * Par la commande, l'examen refuse sans la confirmation retapée, en
     * mode non interactif, avec un opérateur inconnu ou un secret faux, et
     * sans le code du courrier d'examen : rien ne s'inscrit, et le registre
     * du magasin garde ses signatures.
     */
    public function test_la_commande_d_examen_refuse_sans_confirmation_ni_operateur_authentifie_ni_code(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $societe = $magasin->societe;
        $this->vendre($magasin);
        $signatures = $this->signaturesDuRegistre($magasin);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $parametres = ['--siren-correct' => $sirenCorrect, '--decision' => 'erreur', '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE, '--confirmer' => $societe->siren];

        $this->lancerUneProcedureDeLEditeur('editeur:societe:examiner-siren', $societe, $parametres, [['alice', 'secret-d-alice']])
            ->expectsOutputToContain(ExaminerSirenAction::MESSAGE_SANS_CODE)
            ->assertFailed();
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->lancerUneProcedureDeLEditeur('editeur:societe:examiner-siren', $societe, [...$parametres, '--confirmer' => '000000018'], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:societe:examiner-siren', $societe, [...$parametres, '--confirmer' => null, '--no-interaction' => true], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:societe:examiner-siren', $societe, [...$parametres, '--no-interaction' => true], [])
            ->expectsOutputToContain('ne se lance que dans un terminal interactif')
            ->assertFailed();
        foreach ([['mallory', 'secret-d-alice'], ['alice', 'faux-secret']] as $operateur) {
            $this->lancerUneProcedureDeLEditeur('editeur:societe:examiner-siren', $societe, $parametres, [$operateur])
                ->expectsOutputToContain(RegistreDesOperateurs::MESSAGE_REFUS)
                ->assertFailed();
        }

        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::SirenExamine));
        $this->assertSame($signatures, $this->signaturesDuRegistre($magasin));
        $this->assertUnSeulProprietaireActif($societe);
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

    /**
     * Un n° de TVA qui n'était pas celui que formait l'ancien SIREN (celui
     * d'un assujetti unique, ou une faute de saisie) ne se garde pas en
     * silence : la correction exige le nouveau n°, ou la confirmation qu'il
     * reste celui de l'assujetti unique dont la société est membre.
     */
    public function test_un_numero_de_tva_qui_n_etait_pas_celui_de_l_ancien_siren_exige_le_nouveau_ou_la_confirmation_assujetti_unique(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $this->reglerLaSociete($magasin, ['numero_tva' => 'FR32123456789']);
        $societe = $magasin->societe;
        $sirenCorrect = SocieteFactory::sirenFictif();
        $sirets = ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')];
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);

        $this->assertThrows(fn () => $this->corriger($societe, $sirenCorrect, $sirets), VerificationRefuseeException::class, sprintf(CorrigerSirenAction::MESSAGE_NUMERO_TVA_A_CONFIRMER, 'FR32123456789'));
        $this->assertNotSame($sirenCorrect, $societe->fresh()->siren);

        app(TenantContext::class)->clear();
        app(CorrigerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $sirets, null, $this->editeur(), assujettiUnique: true);

        $this->assertSame([$sirenCorrect, 'FR32123456789'], [$societe->fresh()->siren, $societe->fresh()->numero_tva]);
    }

    /**
     * Le n° de TVA que donne l'éditeur se contrôle comme sur l'écran de
     * l'identité de la société : refusé s'il n'est pas au format d'un n° de
     * TVA, ou s'il vient avec la confirmation de l'assujetti unique ; il
     * s'enregistre sans ses espaces, points ni tirets.
     */
    public function test_le_numero_de_tva_donne_par_l_editeur_se_controle_et_se_normalise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $societe = $magasin->societe;
        $sirenCorrect = SocieteFactory::sirenFictif();
        $sirets = ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')];
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $corriger = fn (string $numeroTva, bool $assujettiUnique = false) => app(CorrigerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $sirets, $numeroTva, $this->editeur(), $assujettiUnique);

        $this->assertThrows(fn () => $corriger('XX-1'), VerificationRefuseeException::class, NumeroTvaAuFormat::MESSAGE);
        $this->assertThrows(fn () => $corriger('FR12345678901', true), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_NUMERO_TVA_OU_ASSUJETTI);
        $this->assertNotSame($sirenCorrect, $societe->fresh()->siren);

        $corriger('fr 12 345.678-901');

        $this->assertSame([$sirenCorrect, 'FR12345678901'], [$societe->fresh()->siren, $societe->fresh()->numero_tva]);
    }

    /**
     * Le résumé du courrier d'examen nomme l'entreprise à qui il part et
     * son destinataire officiel, lus au registre, et l'opérateur retape le
     * SIREN de cette entreprise, jamais celui de la société : une faute de
     * frappe dans --siren se voit avant qu'un recommandé ne parte.
     */
    public function test_le_courrier_d_examen_montre_son_destinataire_et_fait_retaper_son_siren(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $societe = $magasin->societe;
        $sirenCorrect = SocieteFactory::sirenFictif();
        $parametres = ['--motif' => 'examen_siren', '--siren' => $sirenCorrect, '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE];

        $this->lancerUneProcedureDeLEditeur('editeur:courrier:envoyer', $societe, [...$parametres, '--confirmer' => $societe->siren], [])
            ->expectsOutputToContain("Il part à l'entreprise de SIREN {$sirenCorrect}, et non à la société ; destinataire officiel : Jeanne DUPONT, ENTREPRISE {$sirenCorrect}, 1 RUE DE LA REPUBLIQUE, 75001 PARIS.")
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:courrier:envoyer', $societe, $parametres, [])
            ->expectsQuestion("Retapez le SIREN destinataire {$sirenCorrect} pour confirmer", (string) $societe->siren)
            ->assertFailed();
        $this->assertSame(0, app(TenantContext::class)->runAsSociete($societe, fn (): int => CodeConfirmation::query()->where('motif', MotifCode::ExamenSiren->value)->count()));

        $this->lancerUneProcedureDeLEditeur('editeur:courrier:envoyer', $societe, [...$parametres, '--confirmer' => $sirenCorrect], [['alice', 'secret-d-alice']])
            ->expectsOutputToContain('Courrier parti')
            ->assertSuccessful();

        $courrier = app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => CodeConfirmation::query()->where('motif', MotifCode::ExamenSiren->value)->sole());
        $this->assertSame($sirenCorrect, $courrier->siren);
    }

    /**
     * Le code du courrier d'examen prouve la remise pour les examens que
     * l'éditeur reprend dans les trente jours qui suivent sa saisie, et
     * plus ensuite : un nouvel examen exige un nouveau courrier.
     */
    public function test_le_code_d_examen_ne_vaut_que_trente_jours_apres_sa_saisie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->prouverLaRemiseDuCourrierDExamen($magasin->societe, $sirenCorrect);
        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Usurpation);
        $this->travelTo(self::aParis('2026-12-02 09:59:59'));
        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->travelTo(self::aParis('2026-12-02 10:00:00'));

        $this->assertThrows(fn () => $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::RienARegulariser), VerificationRefuseeException::class, ExaminerSirenAction::MESSAGE_SANS_CODE);

        $this->assertSame(['usurpation', 'erreur'], array_map(fn ($examen): string => $examen->nouvelles_valeurs['decision'], $this->entreesDAudit($magasin->societe, ActionAuditee::SirenExamine)));
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
     * Après la correction, le vrai titulaire de l'ancien SIREN s'inscrit,
     * se valide et crée son magasin : le code LYON, sous lequel la société
     * corrigée a émis des documents signés de l'ancien SIREN, lui est
     * refusé, et le code qu'on lui propose depuis le nom du magasin est un
     * autre : ses numéros ne répètent jamais ceux déjà émis sous ce SIREN.
     */
    public function test_apres_la_correction_le_titulaire_de_l_ancien_siren_ne_reprend_aucun_code_de_la_societe_corrigee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $ancienSiren = (string) $magasin->societe->siren;
        $this->vendre($magasin);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->prouverLaRemiseDuCourrierDExamen($magasin->societe, $sirenCorrect);
        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->corriger($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);
        $titulaire = $this->inscrireUneSociete($ancienSiren);
        $proprietaire = $this->saisirLeCodeDuCourrier($titulaire);
        app(TenantContext::class)->clear();
        $nic = substr(IdentifiantsInsee::avecSaCle($ancienSiren.'0042'), 9);

        $this->assertThrows(
            fn () => app(CreerEtablissementAction::class)->handle($proprietaire, $titulaire->fresh(), ['nom' => 'Lyon', 'code' => 'LYON', 'email' => null, 'nic' => $nic]),
            fn (ValidationException $refus): bool => $refus->errors() === ['code' => [CodeMagasinLibre::MESSAGE_DEJA_UTILISE]],
        );
        $nouveau = app(CreerEtablissementAction::class)->handle($proprietaire, $titulaire->fresh(), ['nom' => 'Lyon', 'code' => null, 'email' => null, 'nic' => $nic]);

        $this->assertSame('LYON2', $nouveau->code);
        $this->assertTrue($titulaire->fresh()->estValidee());
    }

    /**
     * Après la correction, l'ancien SIRET de la société corrigée, qu'elle
     * garde réservé, refuse le magasin que le vrai titulaire de l'ancien
     * SIREN ouvre sur son propre établissement ; le courrier de libération
     * de ce SIRET part à ce titulaire, sous son SIREN, à l'adresse
     * officielle de son siège ; le titulaire en saisit le code sur la page
     * de ce motif, et l'éditeur libère le SIRET, qui sert alors au magasin
     * du titulaire.
     */
    public function test_apres_la_correction_le_titulaire_de_l_ancien_siren_recoit_le_courrier_de_liberation_de_son_etablissement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $societe = $magasin->societe;
        $ancienSiren = (string) $societe->siren;
        $ancienSiret = (string) $magasin->siret;
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->corriger($societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);
        $titulaire = $this->inscrireUneSociete($ancienSiren);
        $proprietaire = $this->saisirLeCodeDuCourrier($titulaire);
        app(TenantContext::class)->clear();
        $creerLeMagasin = fn (): Magasin => app(CreerEtablissementAction::class)->handle($proprietaire, $titulaire->fresh(), ['nom' => 'Lyon Centre', 'code' => null, 'email' => null, 'nic' => substr($ancienSiret, 9)]);
        $this->assertThrows($creerLeMagasin, fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [SiretsDesMagasins::MESSAGE_SIRET_RESERVE]]);

        app(TenantContext::class)->clear();
        $courrier = app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::LiberationSiret, $this->editeur(['alice']), siretALiberer: $ancienSiret);
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure($ancienSiren, MotifCode::LiberationSiret, $this->codeDuCourrier($societe, MotifCode::LiberationSiret), '127.0.0.1');
        app(TenantContext::class)->clear();
        app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $ancienSiret, $this->editeur());

        $this->assertSame([$ancienSiren, $ancienSiret, "ENTREPRISE {$ancienSiren}"], [$courrier->siren, $courrier->siret, $courrier->destinataire['adresse']['denomination']]);
        $this->assertSame($ancienSiret, (string) $creerLeMagasin()->siret);
        $this->assertSame($sirenCorrect, (string) $societe->fresh()->siren);
    }

    /**
     * La correction vers un SIREN sous lequel une société révoquée a émis
     * des documents avec le même code de magasin est refusée : le magasin
     * corrigé répéterait ses numéros ; rien ne change.
     */
    public function test_la_correction_vers_un_siren_dont_une_societe_revoquee_a_numerote_le_meme_code_est_refusee(): void
    {
        $revoquee = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $sirenCorrect = (string) $revoquee->societe->siren;
        $this->vendre($revoquee);
        $this->revoquer($revoquee->societe);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $this->prouverLaRemiseDuCourrierDExamen($magasin->societe, $sirenCorrect);
        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Erreur);

        $this->assertThrows(fn () => $this->corriger($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0002')]), VerificationRefuseeException::class, sprintf(CorrigerSirenAction::MESSAGE_CODE_DEJA_EMIS, 'LYON'));

        $this->assertNotSame($sirenCorrect, $magasin->societe->fresh()->siren);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::SirenCorrige));
    }

    /**
     * Une facture émise depuis l'examen, que sa liste ne désigne pas, rend
     * la correction impossible : l'éditeur examine de nouveau le SIREN,
     * dont la liste la désigne alors, puis le corrige.
     */
    public function test_la_correction_est_refusee_quand_une_facture_a_ete_emise_depuis_l_examen(): void
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']));
        $societe = $magasin->societe;
        $sirenCorrect = SocieteFactory::sirenFictif();
        $sirets = ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')];
        $this->prouverLaRemiseDuCourrierDExamen($societe, $sirenCorrect);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->travelTo(self::aParis('2026-11-02 10:05:00'));
        app(TenantContext::class)->set($magasin->fresh());
        $responsable = self::responsableDeTest($magasin);
        $client = Client::factory()->professionnel()->create(['nom' => 'Dupont SAS']);
        app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]));

        $this->assertThrows(fn () => $this->corriger($societe, $sirenCorrect, $sirets), VerificationRefuseeException::class, CorrigerSirenAction::MESSAGE_FACTURES_DEPUIS_L_EXAMEN);
        $this->assertNotSame($sirenCorrect, $societe->fresh()->siren);
        $this->examiner($societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->corriger($societe, $sirenCorrect, $sirets);

        $this->assertSame($sirenCorrect, $societe->fresh()->siren);
    }

    /**
     * Le SIREN d'un cabinet d'expertise comptable se corrige par la
     * commande de deux opérateurs, sans SIRET à donner : son code
     * d'activité, relu sous le nouveau SIREN, est contrôlé 69.20Z, et la
     * vérification de son inscription à l'Ordre est remise à faire ; il
     * n'est plus vérifié et garde son lien ; sa vérification d'avant reste
     * au journal d'audit, avec la correction, le nouveau contrôle et la
     * vérification à refaire, origine « éditeur ».
     */
    public function test_le_siren_d_un_cabinet_se_corrige_et_remet_a_faire_son_controle_69_20z_et_sa_verification_a_l_ordre(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $lien = $this->lierLeCabinet($cabinet, $this->ouvrirUneSociete(['nom' => 'Cliente']));
        $sirenCorrect = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($sirenCorrect, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $this->prouverLaRemiseDuCourrierDExamen($cabinet, $sirenCorrect);
        $this->examiner($cabinet, $sirenCorrect, DecisionExamenSiren::Erreur);

        $this->corrigerParLaCommande($cabinet, $sirenCorrect, [], 'alice', 'bruno')
            ->expectsOutputToContain('Cabinet d\'expertise comptable : son code d\'activité et son inscription à l\'Ordre se vérifieront de nouveau sous ce SIREN ; d\'ici là, il garde ses liens mais n\'ouvre aucun client.')
            ->expectsOutputToContain("SIREN corrigé : {$sirenCorrect} ; les documents émis gardent l'ancien.")
            ->assertSuccessful();

        $corrige = $cabinet->fresh();
        $verification = $corrige->cabinet()->sole();
        $this->assertSame([$sirenCorrect, IdentifiantsInsee::numeroTvaPropose($sirenCorrect)], [$corrige->siren, $corrige->numero_tva]);
        $this->assertSame(['69.20Z', 'NAFRev2', true], [$verification->code_activite, $verification->nomenclature_activite, $verification->aSonActiviteControlee()]);
        $this->assertSame([null, null, null, null], [$verification->ordre_verifie_le, $verification->ordre_voie, $verification->ordre_demande_le, $verification->ordre_refuse_le]);
        $this->assertFalse($corrige->estUnCabinetVerifie());
        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::OrdreVerifie));
        $activite = collect($this->entreesDAudit($cabinet, ActionAuditee::ActiviteDuCabinetControlee))->last();
        $this->assertSame(['editeur', ['code_activite' => '69.20Z', 'nomenclature' => 'NAFRev2', 'resultat' => 'concluant']], [$activite->origine, $activite->nouvelles_valeurs]);
        [$aRefaire] = $this->entreesDAudit($cabinet, ActionAuditee::OrdreARefaire);
        $this->assertSame(['editeur', ['motif' => CorrigerSirenAction::MOTIF_ORDRE_A_REFAIRE]], [$aRefaire->origine, $aRefaire->nouvelles_valeurs]);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::SirenCorrige));
        $this->assertUnSeulProprietaireActif($cabinet);
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

    /**
     * La révocation de la société par deux opérateurs, une fois le code du
     * courrier de révocation saisi.
     */
    private function revoquer(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Revocation, $this->editeur(['alice']));
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $societe->siren, MotifCode::Revocation, $this->codeDuCourrier($societe, MotifCode::Revocation), '127.0.0.1');
        app(TenantContext::class)->clear();
        app(RevoquerSocieteAction::class)->handle($societe->fresh(), $this->editeur());
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
