<?php

namespace Tests\Feature\Editeur;

use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
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
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\VerificateurJournalAudit;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Societe\Resources\JournalAuditResource;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * La révocation par l'éditeur d'une société validée qui a pris le SIREN
 * d'une autre entreprise (voir RevoquerSocieteAction) : deux opérateurs,
 * le code du courrier de révocation saisi ; la société passe en lecture
 * seule pour toujours, ses documents intacts et consultables ; son SIREN
 * et ses SIRET se libèrent pour le vrai titulaire, qui s'inscrit ensuite
 * sans reprendre aucun code de magasin de la société révoquée ; ses liens
 * avec un cabinet prennent fin, même quand la messagerie est en panne,
 * et la révocation d'un cabinet interrompue se termine quand on la
 * relance ; ses données de vérification se gardent cinq ans, puis
 * s'effacent. Le temps est figé au 2 novembre 2026, à Paris, sauf où un
 * test le fait voyager.
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
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
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
     * Un magasin que la société crée pendant sa révocation, entre la
     * lecture de ses magasins réels et leurs verrous, n'y échappe pas : la
     * révocation reprend sur tous ses magasins, et le nouveau se ferme,
     * motif « révocation de la société », et libère son SIRET comme
     * l'autre.
     */
    public function test_un_magasin_cree_pendant_la_revocation_est_revoque_avec_les_autres(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $societe = $magasin->societe;
        $this->prouverLaRemiseDuCourrierDeRevocation($societe);
        $proprietaire = $this->proprietaireDe($magasin);
        $nouveau = null;
        $cree = false;
        DB::listen(function (QueryExecuted $requete) use ($societe, $proprietaire, &$nouveau, &$cree): void {
            if ($cree || ! str_contains($requete->sql, 'from "magasins"') || str_contains($requete->sql, ' for ')) {
                return;
            }

            $cree = true;
            $nouveau = app(CreerEtablissementAction::class)->handle($proprietaire, $societe, ['nom' => 'Ouvert pendant la révocation', 'code' => null, 'email' => null, 'nic' => MagasinFactory::nicLibre($societe)]);
        });

        $revocation = app(RevoquerSocieteAction::class)->handle($societe->fresh(), $this->editeur());

        $this->assertInstanceOf(Magasin::class, $nouveau);
        $this->assertEqualsCanonicalizing([(string) $magasin->siret, (string) $nouveau->siret], $revocation['sirets']);
        $this->assertSame(StatutSociete::Revoquee, $societe->fresh()->statut);
        foreach ([$magasin, $nouveau] as $revoque) {
            $this->assertFalse(SiretsDesMagasins::estReserve((string) $revoque->siret), $revoque->nom);
            $periode = app(TenantContext::class)->runAs($revoque->fresh(), fn (): PeriodeActivite => PeriodeActivite::query()->where('magasin_id', $revoque->id)->sole());
            $this->assertSame(MotifFermeture::RevocationSociete, $periode->motif_fin, $revoque->nom);
        }
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
     * La révocation annule, au nom de l'éditeur, la transmission de la
     * propriété en attente : le journal d'audit nomme son destinataire,
     * qui en est prévenu.
     */
    public function test_la_revocation_annule_la_transmission_de_la_propriete_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $societe = $magasin->societe;
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $this->demanderLaTransmission($this->proprietaireDe($magasin), $administrateur);
        app(TenantContext::class)->clear();

        $this->revoquer($societe);

        $this->assertNotNull(app(TenantContext::class)->runAsSociete($societe->fresh(), fn () => TransmissionPropriete::query()->where('destinataire_id', $administrateur->id)->sole()->annulee_le));
        [$annulation] = $this->entreesDAudit($societe, ActionAuditee::TransmissionAnnulee);
        $this->assertSame(['editeur', ['destinataire' => ['id' => $administrateur->id, 'nom' => $administrateur->name]]], [$annulation->origine, $annulation->nouvelles_valeurs]);
        $this->assertContains('Transmission de la propriété annulée', $administrateur->notifications()->get()->pluck('data.title')->all());
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
     * La messagerie en panne (un serveur SMTP qui ne répond pas) pendant la
     * révocation d'un cabinet lié à deux clients, qui a aussi une demande
     * de rattachement et une invitation ouvertes : chaque avis qui ne part
     * pas est signalé, sans arrêter la fin de chaque lien (le second
     * compris, avec la perte des droits de ses comptes au journal des
     * événements du client), le retrait de la demande et de l'invitation,
     * ni l'avis au propriétaire du cabinet dans sa cloche.
     */
    public function test_une_messagerie_en_panne_n_arrete_pas_la_revocation_d_un_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $premier = $this->ouvrirUneSociete(['nom' => 'Première cliente']);
        $second = $this->ouvrirUneSociete(['nom' => 'Seconde cliente']);
        $liens = [$this->lierLeCabinet($cabinet, $premier), $this->lierLeCabinet($cabinet, $second)];
        $rattachee = $this->ouvrirUneSociete(['nom' => 'Cliente rattachée']);
        $this->demanderLeRattachement($cabinet, $rattachee->societe, 'proprietaire@rattachee.test');
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'futur@client.test');
        $this->prouverLaRemiseDuCourrierDeRevocation($cabinet);
        $this->mettreLaMessagerieEnPanne();
        Exceptions::fake();

        $revocation = app(RevoquerSocieteAction::class)->handle($cabinet->fresh(), $this->editeur());

        $this->assertSame(['sirets' => [], 'reprise' => false, 'en_echec' => 0], $revocation);
        foreach ($liens as $lien) {
            $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::SocieteRevoquee], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
        }
        $this->assertSame([EtatDuLien::Retire], LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $rattachee->societe_id)->pluck('etat')->all());
        $this->assertSame([EtatInvitation::Retiree], InvitationCabinet::query()->withoutGlobalScopes()->where('societe_id', $cabinet->id)->pluck('etat')->all());
        $this->assertNull(collect($this->modificationsDesDroits($second))->where('membre.id', $this->proprietaireDuCabinet($cabinet)->id)->last()['apres']);
        $this->assertContains('Lien avec votre cabinet terminé', $this->proprietaireDe($second)->notifications()->get()->pluck('data.title')->all());
        $this->assertContains('Votre société est révoquée', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
        Exceptions::assertReported(TransportException::class);
    }

    /**
     * La messagerie en panne pendant la révocation d'un client lié à un
     * cabinet : l'avis au cabinet, qui part après la transaction, ne
     * l'interrompt pas ; la caisse personnelle restée ouverte du vendeur se
     * ferme d'office et le propriétaire est prévenu.
     */
    public function test_une_messagerie_en_panne_n_arrete_pas_les_suites_de_la_revocation_d_un_client(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente usurpatrice']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $this->vendre($magasin);
        $this->prouverLaRemiseDuCourrierDeRevocation($magasin->societe);
        $this->mettreLaMessagerieEnPanne();
        Exceptions::fake();

        app(RevoquerSocieteAction::class)->handle($magasin->societe->fresh(), $this->editeur());

        $this->assertSame(EtatDuLien::Termine, $lien->fresh()->etat);
        $this->assertSame(1, app(TenantContext::class)->runAs($magasin->fresh(), fn (): int => ClotureCaissePersonnelle::query()->count()));
        $this->assertContains('Votre société est révoquée', $this->proprietaireDe($magasin)->notifications()->get()->pluck('data.title')->all());
        Exceptions::assertReported(TransportException::class);
    }

    /**
     * Le premier lien d'un cabinet ne prend pas fin pendant sa révocation
     * (ses droits changent au même instant) : la commande le dit et
     * échoue, le cabinet étant révoqué et son second lien terminé ;
     * relancée, sans nouveau courrier, elle termine le premier lien ; une
     * fois tout terminé, elle répond que la société est déjà révoquée.
     */
    public function test_la_revocation_d_un_cabinet_interrompue_se_termine_quand_on_la_relance(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        [$premier, $second] = [$this->lierLeCabinet($cabinet, $this->ouvrirUneSociete(['nom' => 'Première cliente'])), $this->lierLeCabinet($cabinet, $this->ouvrirUneSociete(['nom' => 'Seconde cliente']))];
        $this->prouverLaRemiseDuCourrierDeRevocation($cabinet);
        $this->app->instance(MettreFinAuLienAction::class, new class(app(JournalDesDroits::class), app(AccesDesCabinets::class), app(RetirerLaDemandeDeLienAction::class)) extends MettreFinAuLienAction
        {
            public int $echecs = 1;

            public function parLEditeur(LienCabinet $lien, MotifDeFinDuLien $motif, InterventionDeLEditeur $editeur, Societe $visee): void
            {
                if ($this->echecs-- > 0) {
                    throw new LogicException('Les droits de ce cabinet changent en ce moment : recommencez.');
                }

                parent::parLEditeur($lien, $motif, $editeur, $visee);
            }
        });

        $this->revoquerParLaCommande($cabinet, 'alice', 'bruno')
            ->expectsOutputToContain('Société révoquée, mais 1 lien(s), demande(s) ou invitation(s) de ce cabinet n\'ont pas pu prendre fin')
            ->assertFailed();
        $this->assertSame([StatutSociete::Revoquee, EtatDuLien::Actif, EtatDuLien::Termine], [$cabinet->fresh()->statut, $premier->fresh()->etat, $second->fresh()->etat]);

        $this->revoquerParLaCommande($cabinet, 'alice', 'bruno')
            ->expectsOutputToContain('Révocation terminée : les liens, demandes et invitations qui restaient à ce cabinet ont pris fin.')
            ->assertSuccessful();
        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::SocieteRevoquee], [$premier->fresh()->etat, $premier->fresh()->motif_fin]);

        $this->revoquerParLaCommande($cabinet, 'alice', 'bruno')
            ->expectsOutputToContain(RevoquerSocieteAction::MESSAGE_DEJA_REVOQUEE)
            ->assertFailed();
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::SocieteRevoquee));
        $this->assertSame(1, $this->proprietaireDuCabinet($cabinet)->notifications()->get()->where('data.title', 'Votre société est révoquée')->count());
    }

    /**
     * Un cabinet qui a demandé le rattachement d'une société inscrite n'en
     * connaît que le SIREN : quand l'éditeur révoque cette société, sa
     * demande prend fin, et l'avis qu'il reçoit le dit sans en donner le
     * motif ; il n'apprend pas que la société est révoquée.
     */
    public function test_le_cabinet_n_apprend_pas_la_revocation_d_une_societe_qu_il_ne_connait_que_par_son_siren(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'proprietaire@usurpatrice.test');

        $this->revoquer($magasin->societe);

        $avis = $this->proprietaireDuCabinet($cabinet)->notifications()->get()->firstWhere('data.title', 'Demande de lien retirée');
        $this->assertSame("La demande de lien avec le SIREN {$magasin->societe->siren} a pris fin.", $avis->data['body']);
    }

    /**
     * La justification et les opérateurs d'une révocation ne s'inscrivent
     * qu'au journal de la société révoquée : au journal de l'autre partie
     * d'un lien qui prend fin, le cabinet d'un client révoqué ou le client
     * d'un cabinet révoqué, l'entrée dit seulement que l'éditeur l'a faite.
     */
    public function test_la_justification_d_une_revocation_ne_s_inscrit_qu_au_journal_de_la_societe_revoquee(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Cliente usurpatrice']);
        $this->lierLeCabinet($cabinet, $client);
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $autreClient = $this->ouvrirUneSociete(['nom' => 'Cliente du cabinet révoqué']);
        $this->lierLeCabinet($autreCabinet, $autreClient);

        $this->revoquer($client->societe);
        $this->revoquer($autreCabinet);

        foreach ([[$cabinet, $client->societe], [$autreClient->societe, $autreCabinet]] as [$autrePartie, $revoquee]) {
            [$fin] = $this->entreesDAudit($autrePartie, ActionAuditee::LienTermine);
            $this->assertSame(['editeur', null, null], [$fin->origine, $fin->justification, $fin->operateur]);
            [$finChezLaRevoquee] = $this->entreesDAudit($revoquee, ActionAuditee::LienTermine);
            $this->assertSame(['editeur', self::JUSTIFICATION, ['alice', 'bruno']], [$finChezLaRevoquee->origine, $finChezLaRevoquee->justification, $finChezLaRevoquee->operateur['operateurs']]);
        }
    }

    /**
     * Le code d'un courrier envoyé à la société après sa révocation (pour
     * récupérer le compte de son propriétaire) reçoit la fin de
     * conservation qu'elle porte, et s'efface avec ses autres données de
     * vérification.
     */
    public function test_un_courrier_envoye_apres_la_fin_de_la_relation_s_efface_avec_les_donnees_de_verification(): void
    {
        $this->travelTo(self::aParis('2019-03-04 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($societe);
        $this->revoquer($societe);
        $this->travelTo(self::aParis('2019-03-05 10:00:00'));

        $courrier = app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Recuperation, $this->editeur());

        $this->assertMemeInstant(self::aParis('2024-03-04 10:00:00'), $courrier->conserver_jusqu_au);
        $this->travelTo(self::aParis('2024-03-05 10:00:00'));
        $this->artisan('app:purger-verifications')->assertSuccessful();
        $this->assertFalse(CodeConfirmation::withoutGlobalScopes()->whereKey($courrier->id)->exists());
    }

    /**
     * Le code du courrier de révocation ne prouve la remise que dans les
     * trente jours qui suivent sa saisie, et la révocation le consomme.
     */
    public function test_le_code_de_revocation_ne_vaut_que_trente_jours_et_une_fois(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Usurpatrice']);
        $societe = $magasin->societe;
        $this->prouverLaRemiseDuCourrierDeRevocation($societe);
        $this->travelTo(self::aParis('2026-12-02 10:00:01'));

        $this->assertThrows(fn () => app(RevoquerSocieteAction::class)->handle($societe->fresh(), $this->editeur()), VerificationRefuseeException::class, RevoquerSocieteAction::MESSAGE_SANS_CODE);
        $this->assertSame(StatutSociete::Active, $societe->fresh()->statut);

        $this->prouverLaRemiseDuCourrierDeRevocation($societe);
        app(RevoquerSocieteAction::class)->handle($societe->fresh(), $this->editeur());

        $preuves = app(TenantContext::class)->runAsSociete($societe, fn () => CodeConfirmation::query()->where('motif', MotifCode::Revocation->value)->orderBy('id')->get());
        $this->assertSame([false, true], $preuves->map(fn (CodeConfirmation $code): bool => $code->procedure_faite_le !== null)->all());
    }

    /**
     * La fin de la conservation des données de vérification ne se pose,
     * même par une requête qui contourne le modèle, que sur une société
     * révoquée ou résiliée : sur une société active, une date passée est
     * refusée, et ses données de vérification ne s'effacent pas.
     */
    public function test_la_fin_de_la_conservation_ne_se_pose_pas_sur_une_societe_active(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($societe);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): int => DB::table('societes')->where('id', $societe->id)->update(['verification_conservee_jusqu_au' => self::enUtc('2000-01-01 00:00:00')->format('Y-m-d H:i:s')])), QueryException::class, 'societes.verification_conservee_jusqu_au : valeur refusée sur cette ligne');

        $this->assertNull($societe->fresh()->verification_conservee_jusqu_au);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): int => DB::table('journal_audit')->where('societe_id', $societe->id)->where('categorie', CategorieAudit::Verification->value)->delete()), QueryException::class, 'journal_audit : suppression refusée avant expiration');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): int => DB::table('verifications_societe')->where('societe_id', $societe->id)->delete()), QueryException::class, 'verifications_societe : suppression refusée avant expiration');
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
     * La messagerie de l'application en panne : chaque e-mail, notifications
     * comprises, échoue comme devant un serveur SMTP qui ne répond pas, et
     * l'erreur remonte à qui l'envoie. Une messagerie simulée par le test
     * (Mail::fake()) rend d'abord la place à la vraie, et les canaux des
     * notifications se refont sur elle.
     */
    private function mettreLaMessagerieEnPanne(): void
    {
        if (Mail::isFake()) {
            Mail::swap(Mail::getFacadeRoot()->manager);
        }

        app(ChannelManager::class)->forgetDrivers();
        Mail::extend('en_panne', fn (): TransportInterface => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Connexion au serveur SMTP impossible : délai dépassé.');
            }

            public function __toString(): string
            {
                return 'en_panne://';
            }
        });
        config(['mail.mailers.en_panne' => ['transport' => 'en_panne'], 'mail.default' => 'en_panne']);
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
