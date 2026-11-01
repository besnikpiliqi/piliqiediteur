<?php

namespace Tests\Feature\Editeur;

use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\RevoquerSocieteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\VerificateurJournalAudit;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Societe\Resources\JournalAuditResource;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Tests\TestCase;

/**
 * La révocation par l'éditeur d'une société validée qui a pris le SIREN
 * d'une autre entreprise (voir RevoquerSocieteAction) : deux opérateurs,
 * le code du courrier de révocation saisi ; la société passe en lecture
 * seule pour toujours, ses documents intacts et consultables ; son SIREN
 * et ses SIRET se libèrent pour le vrai titulaire, qui s'inscrit ensuite
 * sans reprendre aucun code de magasin de la société révoquée ; ses liens
 * avec un cabinet prennent fin ; ses données de vérification se gardent
 * cinq ans, puis s'effacent.
 */
class RevoquerSocieteTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Courrier de vérification détourné, titulaire évincé reçu par l\'éditeur';

    private const PIECE = 'DOSSIER-2026-0077';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            'bruno' => password_hash('secret-de-bruno', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
    }

    /**
     * Sans le code du courrier de révocation saisi, pour une société qui
     * n'est pas validée, ou avec un seul opérateur, rien ne s'écrit ; la
     * commande refuse aussi le même opérateur deux fois.
     */
    public function test_la_revocation_exige_le_code_du_courrier_saisi_une_societe_validee_et_deux_operateurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $societe = $magasin->societe;
        $enAttente = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        $this->assertThrows(fn () => app(RevoquerSocieteAction::class)->handle($societe, $this->editeur()), VerificationRefuseeException::class, RevoquerSocieteAction::MESSAGE_SANS_CODE);
        $this->assertThrows(fn () => app(RevoquerSocieteAction::class)->handle($enAttente, $this->editeur()), VerificationRefuseeException::class, RevoquerSocieteAction::MESSAGE_PAS_VALIDEE);
        $this->assertSame($avant, $this->ecritures());

        $this->prouverLaRemiseDuCourrierDeRevocation($societe);
        $avant = $this->ecritures();
        $this->assertThrows(fn () => app(RevoquerSocieteAction::class)->handle($societe, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice'])), VerificationRefuseeException::class, RevoquerSocieteAction::MESSAGE_DEUX_OPERATEURS);
        $this->revoquerParLaCommande($societe, 'alice', 'alice')
            ->expectsOutputToContain('Cette procédure exige deux opérateurs distincts : rien n\'est fait.')
            ->assertFailed();
        $this->revoquerParLaCommande($societe, 'alice', 'mallory')->assertFailed();
        $this->assertSame($avant, $this->ecritures());
        $this->assertSame(StatutSociete::Active, $societe->fresh()->statut);
    }

    /**
     * Révoquée par la commande de deux opérateurs, la société garde ses
     * documents tels quels (leurs signatures et leur registre intacts) ;
     * son SIREN et ses SIRET se libèrent ; la journée de son magasin se
     * clôture, sa période d'activité se ferme, motif « révocation de la
     * société », et le statut de la société s'inscrit à son journal des
     * événements ; la révocation s'inscrit au journal d'audit avec les deux
     * opérateurs ; la société garde un seul propriétaire actif, prévenu.
     */
    public function test_la_revocation_libere_le_siren_et_les_siret_sans_toucher_aux_documents(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $societe = $magasin->societe;
        $ticket = $this->vendre($magasin);
        $signatures = $this->signatures($magasin);
        $this->prouverLaRemiseDuCourrierDeRevocation($societe);

        $this->revoquerParLaCommande($societe, 'alice', 'bruno')
            ->expectsOutputToContain("Société révoquée : son SIREN est libéré, ainsi que les SIRET {$magasin->siret}.")
            ->assertSuccessful();

        $revoquee = $societe->fresh();
        $this->assertSame(StatutSociete::Revoquee, $revoquee->statut);
        $this->assertFalse(Societe::sirenPris((string) $societe->siren));
        $this->assertFalse(SiretsDesMagasins::estReserve((string) $magasin->siret));
        $this->assertSame($signatures, array_intersect_key($this->signatures($magasin), $signatures));
        $this->assertSame([$ticket->numero, '12.00'], [$ticket->fresh()->numero, $ticket->fresh()->total_ttc]);
        $this->assertTrue(app(TenantContext::class)->runAs($magasin->fresh(), fn (): bool => app(VerificateurIntegrite::class)->verifier($magasin->fresh())->estConforme()));
        app(TenantContext::class)->runAs($magasin, function () use ($magasin): void {
            $this->assertSame(1, ClotureCaisse::query()->count());
            $periode = PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole();
            $this->assertSame(MotifFermeture::RevocationSociete, $periode->motif_fin);
        });
        $this->assertContains(['parametre' => 'statut_societe', 'ancienne_valeur' => 'active', 'nouvelle_valeur' => 'revoquee'], $this->parametresJournalises($magasin));
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::SocieteRevoquee);
        $this->assertSame(['editeur', self::JUSTIFICATION, ['alice', 'bruno']], [$entree->origine, $entree->justification, $entree->operateur['operateurs']]);
        $this->assertSame(['sirets_liberes' => [$magasin->siret], 'statut' => 'revoquee'], $entree->nouvelles_valeurs);
        $this->assertSame(1, User::query()->where('societe_id', $societe->id)->where('role_societe', RoleSociete::Proprietaire->value)->where('statut', StatutCompte::Actif->value)->count());
        $this->assertContains('Votre société est révoquée', $this->proprietaireDe($magasin)->notifications()->get()->pluck('data.title')->all());
    }

    /**
     * Les comptes de la société révoquée consultent encore (le propriétaire
     * ouvre son journal d'audit), mais plus rien ne s'y vend, ne s'y règle
     * ni ne s'y gère.
     */
    public function test_les_comptes_de_la_societe_revoquee_consultent_mais_ne_modifient_plus_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $this->revoquer($societe);

        $this->actingAs($proprietaire)->get(JournalAuditResource::getUrl('index', panel: 'societe', tenant: $societe))->assertOk();
        $this->assertThrows(fn () => $this->vendre($magasin->fresh()), VenteNonAutoriseeException::class, 'Ce magasin est en lecture seule, votre société étant révoquée');
        $this->assertThrows(fn () => app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe->fresh(), ['raison_sociale' => 'Nouveau nom']), LogicException::class, ModifierIdentiteSocieteAction::MESSAGE_SANS_AUTORISATION);
        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);
        $this->assertFalse(Gate::forUser($proprietaire)->allows('create', User::class));
        $this->assertTrue(Gate::forUser($proprietaire)->allows('viewAny', EntreeAudit::class));
    }

    /**
     * Après la révocation, le vrai titulaire inscrit son entreprise sous le
     * même SIREN, la valide par le code de son propre courrier, et crée un
     * magasin sur l'établissement que la société révoquée avait pris : son
     * SIRET est libre, mais le code que le magasin révoqué avait émis ne
     * lui est jamais redonné.
     */
    public function test_le_vrai_titulaire_s_inscrit_apres_la_revocation_sans_reprendre_un_code_de_l_usurpatrice(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre', 'code' => 'LYON']);
        $this->vendre($magasin);
        $this->revoquer($magasin->societe);

        $titulaire = $this->inscrireUneSociete((string) $magasin->societe->siren);
        $proprietaire = $this->saisirLeCodeDuCourrier($titulaire);
        app(TenantContext::class)->clear();
        $nouveau = app(CreerEtablissementAction::class)->handle($proprietaire, $titulaire->fresh(), ['nom' => 'Lyon Centre', 'code' => null, 'email' => null, 'nic' => substr((string) $magasin->siret, 9)]);

        $this->assertTrue($titulaire->fresh()->estValidee());
        $this->assertSame((string) $magasin->siret, (string) $nouveau->siret);
        $this->assertNotSame('LYON', $nouveau->code);
        $this->assertTrue(SiretsDesMagasins::estReserve((string) $magasin->siret));
    }

    /**
     * La révocation fixe la fin de la conservation des données de
     * vérification de la société à cinq ans, pour elle et pour ses codes ;
     * passé ce délai, ses vérifications, ses codes et les entrées de la
     * vérification de son journal d'audit s'effacent, ses autres entrées
     * restent, et ses chaînes restent vérifiables. La révocation est datée
     * dans un passé réel : la base n'efface qu'à son heure à elle.
     */
    public function test_la_revocation_fixe_la_fin_de_la_conservation_des_donnees_de_verification_et_les_efface_cinq_ans_apres(): void
    {
        $this->travelTo(self::aParis('2019-03-04 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($societe);
        $this->prouverLaRemiseDuCourrierDeRevocation($societe);
        app(RevoquerSocieteAction::class)->handle($societe->fresh(), $this->editeur());

        $revoquee = $societe->fresh();
        $this->assertMemeInstant(self::aParis('2024-03-04 10:00:00'), $revoquee->verification_conservee_jusqu_au);
        app(TenantContext::class)->runAsSociete($revoquee, function (): void {
            $this->assertNotSame(0, CodeConfirmation::query()->count());
            $this->assertSame(0, CodeConfirmation::query()->whereNull('conserver_jusqu_au')->count());
            $this->assertNotSame(0, VerificationSociete::query()->count());
        });
        $operations = app(TenantContext::class)->runAsSociete($revoquee, fn (): int => EntreeAudit::query()->where('categorie', CategorieAudit::Operations->value)->count());
        $this->assertNotSame(0, $operations);

        $this->travelTo(self::aParis('2024-03-05 10:00:00'));
        $this->artisan('app:purger-verifications')->assertSuccessful();
        $this->artisan('app:purger-journal-audit')->assertSuccessful();

        app(TenantContext::class)->runAsSociete($revoquee, function () use ($operations): void {
            $this->assertSame(0, CodeConfirmation::query()->count());
            $this->assertSame(0, VerificationSociete::query()->count());
            $this->assertSame(0, EntreeAudit::query()->where('categorie', CategorieAudit::Verification->value)->count());
            $this->assertSame($operations, EntreeAudit::query()->where('categorie', CategorieAudit::Operations->value)->count());
        });
        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($revoquee));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $revoquee->id)->update(['verification_conservee_jusqu_au' => self::enUtc('2020-01-01 00:00:00')->format('Y-m-d H:i:s')])), QueryException::class, 'societes.verification_conservee_jusqu_au : valeur figée, changement refusé');
    }

    /**
     * Les données de vérification d'une société dont la relation n'a pas
     * pris fin ne s'effacent jamais, ni par la purge ni par une requête qui
     * contournerait le modèle.
     */
    public function test_sans_fin_de_la_relation_les_donnees_de_verification_ne_s_effacent_pas(): void
    {
        $this->travelTo(self::aParis('2014-03-04 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($societe);
        $this->travelTo(self::aParis('2025-03-05 10:00:00'));

        $this->artisan('app:purger-verifications')->assertSuccessful();
        $this->artisan('app:purger-journal-audit')->assertSuccessful();

        app(TenantContext::class)->runAsSociete($societe, function (): void {
            $this->assertNotSame(0, VerificationSociete::query()->count());
            $this->assertNotSame(0, EntreeAudit::query()->where('categorie', CategorieAudit::Verification->value)->count());
        });
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('verifications_societe')->where('societe_id', $societe->id)->delete()), QueryException::class, 'verifications_societe : suppression refusée avant expiration');
    }

    /**
     * La révocation d'un cabinet met fin à chacun de ses liens actifs,
     * motif « société révoquée » ; le propriétaire de chaque client et
     * celui du cabinet sont prévenus.
     */
    public function test_la_revocation_d_un_cabinet_met_fin_a_tous_ses_liens_et_previent_clients_et_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $premier = $this->ouvrirUneSociete(['nom' => 'Première cliente']);
        $second = $this->ouvrirUneSociete(['nom' => 'Seconde cliente']);
        $liens = [$this->lierLeCabinet($cabinet, $premier), $this->lierLeCabinet($cabinet, $second)];

        $this->revoquer($cabinet);

        foreach ($liens as $lien) {
            $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::SocieteRevoquee], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
        }

        $this->assertFalse($cabinet->fresh()->estUnCabinetVerifie());
        foreach ([$premier, $second] as $magasin) {
            $this->assertContains('Lien avec votre cabinet terminé', $this->proprietaireDe($magasin)->notifications()->get()->pluck('data.title')->all());
        }

        $this->assertContains('Votre société est révoquée', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
        $fin = app(TenantContext::class)->runAsSociete($premier->societe, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::LienTermine->value)->sole());
        $this->assertSame(['societe_revoquee', 'editeur'], [$fin->nouvelles_valeurs['motif'], $fin->origine]);
    }

    /**
     * La révocation d'un cabinet retire ses demandes de lien en cours, dans
     * les deux sens, et ses invitations ouvertes ; le client qui l'avait
     * désigné en est prévenu.
     */
    public function test_la_revocation_d_un_cabinet_retire_ses_demandes_et_ses_invitations_ouvertes(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $designant = $this->ouvrirUneSociete(['nom' => 'Cliente qui désigne']);
        app(TenantContext::class)->clear();
        $designation = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($designant), $designant->societe, $cabinet);
        $rattachee = $this->ouvrirUneSociete(['nom' => 'Cliente rattachée']);
        $this->demanderLeRattachement($cabinet, $rattachee->societe, 'proprietaire@rattachee.test');
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'futur@client.test');

        $this->revoquer($cabinet);

        $demandes = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->id)->orderBy('id')->get();
        $this->assertSame([EtatDuLien::Retire, EtatDuLien::Retire], $demandes->pluck('etat')->all());
        $this->assertSame([null, null], $demandes->pluck('jeton_hache')->all());
        $this->assertSame([EtatInvitation::Retiree], InvitationCabinet::query()->withoutGlobalScopes()->where('societe_id', $cabinet->id)->pluck('etat')->all());
        $this->assertSame(EtatDuLien::Retire, $designation->fresh()->etat);
        $this->assertContains('Demande de lien retirée', $this->proprietaireDe($designant)->notifications()->get()->pluck('data.title')->all());
    }

    /**
     * La révocation d'un client met fin à son lien avec son cabinet, dans
     * la transaction de sa révocation, motif « société révoquée » ; le
     * cabinet est prévenu, et ses comptes perdent leurs droits dans chaque
     * magasin du client.
     */
    public function test_la_revocation_d_un_client_met_fin_a_son_lien_et_previent_le_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente usurpatrice']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);

        $this->revoquer($magasin->societe);

        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::SocieteRevoquee], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
        $this->assertContains('Lien terminé', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
        $this->assertNotContains('Lien avec votre cabinet terminé', $this->proprietaireDe($magasin)->notifications()->get()->pluck('data.title')->all());
        $this->assertNull(collect($this->modificationsDesDroits($magasin))->where('membre.id', $this->proprietaireDuCabinet($cabinet)->id)->last()['apres']);
    }

    /**
     * L'éditeur envoie le courrier de révocation au représentant légal, à
     * l'adresse officielle du siège, et son code est saisi sur sa page :
     * la preuve de sa remise.
     */
    private function prouverLaRemiseDuCourrierDeRevocation(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Revocation, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice']));
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $societe->siren, MotifCode::Revocation, $this->codeDuCourrier($societe, MotifCode::Revocation), '127.0.0.1');
        app(TenantContext::class)->clear();
    }

    /**
     * La remise du courrier de révocation prouvée, la révocation par deux
     * opérateurs.
     */
    private function revoquer(Societe $societe): void
    {
        $this->prouverLaRemiseDuCourrierDeRevocation($societe);
        app(RevoquerSocieteAction::class)->handle($societe->fresh(), $this->editeur());
        app(TenantContext::class)->clear();
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice', 'bruno']);
    }

    /**
     * La commande de la révocation, lancée par ces deux opérateurs.
     */
    private function revoquerParLaCommande(Societe $societe, string $premier, string $second): PendingCommand
    {
        $secrets = ['alice' => 'secret-d-alice', 'bruno' => 'secret-de-bruno', 'mallory' => 'secret-de-mallory'];
        $commande = $this->artisan('editeur:societe:revoquer', [
            'societe' => (string) $societe->id,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur (premier)', $premier)
            ->expectsQuestion('Secret de l\'opérateur (premier)', $secrets[$premier]);

        return $commande->expectsQuestion('Nom de l\'opérateur (second)', $second)->expectsQuestion('Secret de l\'opérateur (second)', $secrets[$second]);
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

    /**
     * Ce qu'une révocation écrirait : sociétés révoquées, SIRET libérés,
     * entrées du journal d'audit, enregistrements fiscaux.
     *
     * @return array<string, int>
     */
    private function ecritures(): array
    {
        return [
            'revoquees' => DB::table('societes')->where('statut', StatutSociete::Revoquee->value)->count(),
            'sirets_liberes' => DB::table('sirets_magasins')->whereNotNull('libere_le')->count(),
            'audit_revocations' => DB::table('journal_audit')->where('action', ActionAuditee::SocieteRevoquee->value)->count(),
            'registre' => DB::table('enregistrements_fiscaux')->count(),
        ];
    }
}
