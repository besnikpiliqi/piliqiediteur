<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RefuserLInvitationAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RetirerLInscriptionALOrdreAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Exceptions\InvitationInvalideException;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\RevoquerSocieteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SocieteFactory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * L'espace des cabinets d'expertise comptable face aux gestes simultanés,
 * chacun dans son processus, sur une vraie base PostgreSQL : les
 * acceptations, les invitations, les demandes, les fins de lien et les
 * gestes d'un compte du cabinet chez un client se sérialisent dans l'ordre
 * des verrous de l'espace des cabinets, sans interblocage, et ne laissent
 * qu'une issue cohérente.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
#[Group('concurrence')]
class CabinetsConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const MOT_DE_PASSE = 'mot-de-passe-sur';

    public function test_deux_acceptations_simultanees_pour_le_meme_siren_ne_creent_qu_une_societe(): void
    {
        $premier = $this->ouvrirUnCabinet();
        $second = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $siren = SocieteFactory::sirenFictif();
        $jetonDuPremier = $this->inviterUneEntreprise($premier, $siren, 'a@b.test');
        $jetonDuSecond = $this->inviterUneEntreprise($second, $siren, 'c@d.test');
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('societes', 'for update', static function () use ($jetonDuSecond): void {
            app(AccepterLInvitationAction::class)->handle($jetonDuSecond, self::donneesDAcceptation());
        }, $concurrente);

        app(AccepterLInvitationAction::class)->handle($jetonDuPremier, self::donneesDAcceptation());

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(AccepterLInvitationAction::MESSAGE_SIREN_INSCRIT, $resultat->errorOutput());
        $this->assertSame(1, Societe::query()->where('siren', $siren)->count());
        $client = Societe::query()->where('siren', $siren)->sole();
        $this->assertSame(1, User::query()->where('societe_id', $client->id)->count());
        $this->assertSame(1, DB::table('identifiants')->where('societe_id', $client->id)->count());
        $this->assertSame(0, User::query()->where('email', 'c@d.test')->count());
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Actif->value)->count());
        $this->assertSame([EtatInvitation::Acceptee, EtatInvitation::Caduque], InvitationCabinet::query()->withoutGlobalScopes()->orderBy('id')->pluck('etat')->all());
    }

    public function test_la_meme_invitation_acceptee_deux_fois_en_meme_temps_ne_cree_qu_une_societe(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'a@b.test');
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('invitations_cabinet', 'for update', static function () use ($jeton): void {
            app(AccepterLInvitationAction::class)->handle($jeton, self::donneesDAcceptation());
        }, $concurrente);

        app(AccepterLInvitationAction::class)->handle($jeton, self::donneesDAcceptation());

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(InvitationInvalideException::MESSAGE, $resultat->errorOutput());
        $this->assertSame(1, Societe::query()->where('siren', $siren)->count());
    }

    public function test_une_fin_de_lien_pendant_un_export_du_journal_passe_tout_entiere_avant_ou_apres(): void
    {
        [$cabinet, $magasin, $lien, $collaborateur] = $this->clientConfie();
        $proprietaireId = (int) $this->proprietaireDe($magasin)->getKey();
        $lienId = (int) $lien->getKey();
        $jour = Calendrier::deParis()->aujourdhui();

        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $lienId): void {
            app(MettreFinAuLienAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($lienId));
        }, $concurrente);
        app(TenantContext::class)->runAs($magasin, fn () => app(ExporterJournalDesEvenementsAction::class)->handle($collaborateur, $jour, $jour));
        $this->assertTrue($this->attendre($concurrente)->successful());
        $evenements = app(TenantContext::class)->runAs($magasin->fresh(), fn () => EnregistrementFiscal::query()->whereIn('type', [(string) CodeEvenement::ExportDeDonnees->value, (string) CodeEvenement::ModificationDesDroits->value])->orderBy('sequence')->pluck('type')->all());
        $this->assertSame([(string) CodeEvenement::ExportDeDonnees->value, (string) CodeEvenement::ModificationDesDroits->value, (string) CodeEvenement::ModificationDesDroits->value], array_slice($evenements, -3));

        [, $autreMagasin, $autreLien, $autreCollaborateur] = $this->clientConfie($cabinet);
        $autreCollaborateurId = (int) $autreCollaborateur->getKey();
        $autreMagasinId = (int) $autreMagasin->getKey();
        $exports = fn (): int => app(TenantContext::class)->runAs($autreMagasin->fresh(), fn (): int => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->count());
        $exportConcurrent = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($autreCollaborateurId, $autreMagasinId, $jour): void {
            app(TenantContext::class)->runAs(Magasin::query()->findOrFail($autreMagasinId), fn () => app(ExporterJournalDesEvenementsAction::class)->handle(User::query()->findOrFail($autreCollaborateurId), $jour, $jour));
        }, $exportConcurrent);
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($autreMagasin), $autreLien);
        $resultat = $this->attendre($exportConcurrent);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('AuthorizationException', $resultat->errorOutput());
        $this->assertSame(0, $exports());
    }

    public function test_une_fin_de_lien_pendant_la_validation_d_un_achat_par_le_cabinet_passe_avant_ou_apres(): void
    {
        [, $magasin, $lien, $collaborateur] = $this->clientConfie(null, [PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE]);
        $proprietaireId = (int) $this->proprietaireDe($magasin)->getKey();
        $lienId = (int) $lien->getKey();
        app(TenantContext::class)->set($magasin);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($collaborateur, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 2, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']);
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', 'for no key update', static function () use ($proprietaireId, $lienId): void {
            app(MettreFinAuLienAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($lienId));
        }, $concurrente);

        app(ValiderFactureAchatAction::class)->handle($collaborateur, $factureAchat);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(EtatDuLien::Termine, $lien->fresh()->etat);
        $this->assertSame('validee', DB::table('factures_achat')->where('id', $factureAchat->id)->value('statut'));
    }

    public function test_deux_invitations_simultanees_du_meme_siren_par_le_meme_cabinet_n_en_creent_qu_une(): void
    {
        Mail::fake();
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaireId = (int) $this->proprietaireDuCabinet($cabinet)->getKey();
        $cabinetId = (int) $cabinet->getKey();
        $siren = SocieteFactory::sirenFictif();
        app(TenantContext::class)->clear();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('societes', 'for update', static function () use ($proprietaireId, $cabinetId, $siren): void {
            app(InviterUneEntrepriseAction::class)->handle(User::query()->findOrFail($proprietaireId), Societe::query()->findOrFail($cabinetId), $siren, 'a@b.test', self::MOT_DE_PASSE);
        }, $concurrente);

        app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $siren, 'a@b.test', self::MOT_DE_PASSE);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(1, DB::table('invitations_cabinet')->where('siren', $siren)->count());
    }

    public function test_deux_acceptations_simultanees_de_demandes_de_deux_cabinets_ne_laissent_qu_un_lien_actif(): void
    {
        $premier = $this->ouvrirUnCabinet();
        $second = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $demandeDuPremier = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $premier);
        $demandeDuSecond = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $second);
        $secondId = (int) $this->proprietaireDuCabinet($second)->getKey();
        $demandeId = (int) $demandeDuSecond->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($secondId, $demandeId): void {
            app(AccepterLaDemandeDeLienAction::class)->handle(User::query()->findOrFail($secondId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($demandeId));
        }, $concurrente);

        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($premier), $demandeDuPremier);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Actif->value)->count());
        $this->assertSame([EtatDuLien::Termine, EtatDuLien::Actif], [$demandeDuPremier->fresh()->etat, $demandeDuSecond->fresh()->etat]);
        $this->assertSame('remplace', $demandeDuPremier->fresh()->motif_fin->value);
    }

    public function test_confier_un_client_pendant_la_fin_du_lien_ne_laisse_aucun_acces(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->inscrireUneSociete();
        $lien = $this->lierLeCabinet($cabinet, $client);
        $collaborateur = $this->collaborateur($cabinet);
        $proprietaireDuCabinetId = (int) $this->proprietaireDuCabinet($cabinet)->getKey();
        $collaborateurId = (int) $collaborateur->getKey();
        $lienId = (int) $lien->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($proprietaireDuCabinetId, $collaborateurId, $lienId): void {
            app(ConfierDesClientsAction::class)->handle(User::query()->findOrFail($proprietaireDuCabinetId), User::query()->findOrFail($collaborateurId), [$lienId]);
        }, $concurrente);

        app(MettreFinAuLienAction::class)->handle($client->proprietaire()->sole(), $lien);

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(ConfierDesClientsAction::MESSAGE_CLIENT_NON_LIE, $resultat->errorOutput());
        $this->assertSame(0, DB::table('clients_confies')->count());
    }

    public function test_suspendre_un_collaborateur_pendant_qu_il_valide_un_achat_chez_un_client(): void
    {
        [$cabinet, $magasin, , $collaborateur] = $this->clientConfie(null, [PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE]);
        app(TenantContext::class)->set($magasin);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($collaborateur, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 2, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']);
        $collaborateurId = (int) $collaborateur->getKey();
        $magasinId = (int) $magasin->getKey();
        $factureId = (int) $factureAchat->getKey();
        app(TenantContext::class)->clear();
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($collaborateurId, $magasinId, $factureId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ValiderFactureAchatAction::class)->handle(User::query()->findOrFail($collaborateurId), FactureAchat::query()->findOrFail($factureId));
        }, $concurrente);

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDuCabinet($cabinet), $collaborateur);

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertSame('brouillon', DB::table('factures_achat')->where('id', $factureId)->value('statut'));
    }

    public function test_une_invitation_d_un_siren_pris_et_une_designation_simultanees_ne_creent_qu_une_demande(): void
    {
        Mail::fake();
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $proprietaireClientId = (int) $this->proprietaireDe($magasin)->getKey();
        $clientId = (int) $magasin->societe_id;
        $cabinetId = (int) $cabinet->getKey();
        app(TenantContext::class)->clear();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($proprietaireClientId, $clientId, $cabinetId): void {
            app(DesignerUnCabinetAction::class)->handle(User::query()->findOrFail($proprietaireClientId), Societe::query()->findOrFail($clientId), Societe::query()->findOrFail($cabinetId));
        }, $concurrente);

        app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $magasin->societe->siren, 'compta@cliente.test', self::MOT_DE_PASSE);

        $resultat = $this->attendre($concurrente);
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->whereIn('etat', [EtatDuLien::DemandeParLeCabinet->value, EtatDuLien::Actif->value])->count());
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
    }

    public function test_une_acceptation_et_une_nouvelle_invitation_du_meme_cabinet_a_l_expiration_ne_s_interbloquent_pas(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'a@b.test');
        $proprietaireId = (int) $this->proprietaireDuCabinet($cabinet)->getKey();
        $cabinetId = (int) $cabinet->getKey();
        $autreSiren = SocieteFactory::sirenFictif();
        $this->travelTo(self::aParis('2026-10-19 09:59:59'));
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('invitations_cabinet', 'for update', static function () use ($proprietaireId, $cabinetId, $autreSiren): void {
            app(InviterUneEntrepriseAction::class)->handle(User::query()->findOrFail($proprietaireId), Societe::query()->findOrFail($cabinetId), $autreSiren, 'c@d.test', self::MOT_DE_PASSE);
        }, $concurrente);

        app(AccepterLInvitationAction::class)->handle($jeton, self::donneesDAcceptation());

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(1, Societe::query()->where('siren', $siren)->count());
        $this->assertSame(1, DB::table('invitations_cabinet')->where('siren', $autreSiren)->count());
    }

    public function test_deux_acceptations_simultanees_a_la_meme_adresse_pour_deux_siren_ne_creent_qu_une_societe(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $premier = SocieteFactory::sirenFictif();
        $second = SocieteFactory::sirenFictif();
        $jetonDuPremier = $this->inviterUneEntreprise($cabinet, $premier, 'meme@adresse.test');
        $jetonDuSecond = $this->inviterUneEntreprise($cabinet, $second, 'meme@adresse.test');
        $concurrente = null;
        $connexionDuTest = DB::getDefaultConnection();
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $connexionDuTest, $jetonDuSecond): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'insert into "users"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($jetonDuSecond): void {
                    app(AccepterLInvitationAction::class)->handle($jetonDuSecond, self::donneesDAcceptation());
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        app(AccepterLInvitationAction::class)->handle($jetonDuPremier, self::donneesDAcceptation());

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(AccepterLInvitationAction::MESSAGE_ADRESSE_PRISE, $resultat->errorOutput());
        $this->assertSame([1, 0], [Societe::query()->where('siren', $premier)->count(), Societe::query()->where('siren', $second)->count()]);
    }

    public function test_le_retrait_de_l_ordre_pendant_une_acceptation_de_demande_ne_s_interbloque_pas_et_n_oublie_aucun_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);
        $cabinetId = (int) $cabinet->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('cabinets', 'for share', static function () use ($cabinetId): void {
            app(RetirerLInscriptionALOrdreAction::class)->handle(Societe::query()->findOrFail($cabinetId), new InterventionDeLEditeur('Radiation publiée au tableau de l\'Ordre.', 'DOSSIER-2026-0101', ['alice']), 'Radiation publiée.');
        }, $concurrente);

        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(EtatDuLien::Termine, $demande->fresh()->etat);
        $this->assertSame('cabinet_plus_verifie', $demande->fresh()->motif_fin->value);
    }

    /**
     * L'éditeur révoque un cabinet pendant que son propriétaire accepte la
     * désignation d'un client : la révocation attend l'acceptation, qui
     * tient la vérification du cabinet sous un verrou partagé, puis met fin
     * au lien qu'elle a fait naître ; aucun lien ne reste actif.
     */
    public function test_une_acceptation_de_lien_pendant_la_revocation_du_cabinet_ne_laisse_aucun_lien_actif(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($cabinet->fresh(), MotifCode::Revocation, new InterventionDeLEditeur('Courrier de vérification détourné, titulaire reçu.', 'DOSSIER-2026-0102', ['alice']));
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $cabinet->siren, MotifCode::Revocation, $this->codeDuCourrier($cabinet, MotifCode::Revocation), '127.0.0.1');
        app(TenantContext::class)->clear();
        $cabinetId = (int) $cabinet->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('cabinets', 'for share', static function () use ($cabinetId): void {
            app(RevoquerSocieteAction::class)->handle(Societe::query()->findOrFail($cabinetId), new InterventionDeLEditeur('Courrier de vérification détourné, titulaire reçu.', 'DOSSIER-2026-0102', ['alice', 'bruno']));
        }, $concurrente);

        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame([EtatDuLien::Termine, 'societe_revoquee'], [$demande->fresh()->etat, $demande->fresh()->motif_fin->value]);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Actif->value)->count());
        $this->assertSame(StatutSociete::Revoquee, $cabinet->fresh()->statut);
    }

    public function test_une_acceptation_et_un_refus_simultanes_de_la_meme_invitation_ne_laissent_qu_une_issue(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'a@b.test');
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('invitations_cabinet', 'for update', static function () use ($jeton): void {
            app(RefuserLInvitationAction::class)->handle($jeton);
        }, $concurrente);

        app(AccepterLInvitationAction::class)->handle($jeton, self::donneesDAcceptation());

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(InvitationInvalideException::MESSAGE, $resultat->errorOutput());
        $this->assertSame([EtatInvitation::Acceptee, 1], [InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat, Societe::query()->where('siren', $siren)->count()]);
    }

    public function test_l_acceptation_d_un_rattachement_et_le_retrait_de_la_demande_simultanes_ne_laissent_qu_une_issue(): void
    {
        Mail::fake();
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $proprietaireDuCabinetId = (int) $this->proprietaireDuCabinet($cabinet)->getKey();
        $demandeId = (int) $demande->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireDuCabinetId, $demandeId): void {
            app(RetirerLaDemandeDeLienAction::class)->handle(User::query()->findOrFail($proprietaireDuCabinetId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($demandeId));
        }, $concurrente);

        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDe($magasin), $demande);

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE, $resultat->errorOutput());
        $this->assertSame([EtatDuLien::Actif, null], [$demande->fresh()->etat, DB::table('liens_cabinet')->value('jeton_hache')]);
    }

    public function test_la_cloture_d_une_inscription_pendant_l_acceptation_d_un_rattachement_ne_s_interbloque_pas(): void
    {
        Mail::fake();
        $cabinet = $this->ouvrirUnCabinet();
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $this->demanderLeRattachement($cabinet, $societe, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $societeId = (int) $societe->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($societeId): void {
            app(CloreLInscriptionAction::class)->parLEditeur(Societe::query()->findOrFail($societeId), new InterventionDeLEditeur('Courrier revenu, pièces insuffisantes.', 'DOSSIER-2026-0070', ['alice']), MotifCloture::Refus);
        }, $concurrente);

        app(AccepterLaDemandeDeLienAction::class)->handle($societe->proprietaire()->sole(), $demande);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertTrue($societe->fresh()->estClose());
        $this->assertSame([EtatDuLien::Termine, 'inscription_close'], [$demande->fresh()->etat, $demande->fresh()->motif_fin->value]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function liensDUneSocieteNonValidee(): array
    {
        return ['un lien actif' => ['lien'], 'une demande en cours' => ['demande']];
    }

    /**
     * L'inscription d'une société sans magasin réel, donc sans registre que
     * les deux transactions partageraient, se clôt (trente jours sans code)
     * pendant qu'un administrateur accepte la transmission de la propriété :
     * l'acceptation tient les comptes ; la clôture tient la ligne de la
     * société (« for no key update ») et attend ces comptes avant de mettre
     * fin à son lien ou à sa demande de lien, dont les entrées vont dans la
     * même chaîne du journal d'audit que celle de l'acceptation ; l'entrée
     * de l'acceptation, qui référence la société, passe, puis la clôture
     * voit le nouveau propriétaire. Aucun interblocage.
     */
    #[DataProvider('liensDUneSocieteNonValidee')]
    public function test_la_cloture_d_une_inscription_avec_un_cabinet_pendant_une_transmission_de_la_propriete_ne_s_interbloque_pas(string $avecLeCabinet): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $societe = $this->inscrireUneSociete(null, 'inscrite@example.test');
        $proprietaire = $societe->proprietaire()->sole();
        $lien = $avecLeCabinet === 'lien'
            ? $this->lierLeCabinet($cabinet, $societe)
            : app(DesignerUnCabinetAction::class)->handle($proprietaire, $societe->fresh(), $cabinet);
        app(TenantContext::class)->clear();
        $administrateur = $this->renseignerSonAdresse($this->choisirSonMotDePasse(app(CreerAdministrateurAction::class)->handle($proprietaire, $societe->fresh(), ['name' => 'Alice Martin'], null, PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), AutorisationsSociete::toutes())), 'repreneur@example.test');
        $this->travelTo(self::aParis('2026-11-04 09:00:00'));
        $jeton = $this->demanderLaTransmission($proprietaire->refresh(), $administrateur, self::MOT_DE_PASSE_CHOISI);
        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        app(TenantContext::class)->clear();
        $this->assertSame(0, $societe->magasinsReels()->count());
        $societeId = (int) $societe->getKey();
        $limite = self::aParis('2026-10-05 10:00:00')->getTimestamp();
        $connexionDuTest = DB::getDefaultConnection();
        $cloture = null;
        DB::listen(function (QueryExecuted $requete) use (&$cloture, $connexionDuTest, $societeId, $limite): void {
            if ($cloture === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'update "users" set "role_societe"')) {
                $cloture = $this->demarrerUnProcessusConcurrent(static function () use ($societeId, $limite): void {
                    if (! app(CloreLInscriptionAction::class)->cloreSansCode(Societe::query()->findOrFail($societeId), CarbonImmutable::createFromTimestamp($limite, date_default_timezone_get()))) {
                        throw new LogicException('L\'inscription n\'a pas été close.');
                    }
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($cloture);
            }
        });

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI);

        $resultat = $this->attendre($cloture);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertTrue(Societe::query()->findOrFail($societeId)->estClose());
        $this->assertSame([RoleSociete::Proprietaire, null], [$administrateur->fresh()->role_societe, $administrateur->fresh()->email]);
        $this->assertSame($avecLeCabinet === 'lien' ? EtatDuLien::Termine : EtatDuLien::Retire, $lien->fresh()->etat);
    }

    /**
     * Ce que la personne invitée envoie en acceptant.
     *
     * @return array<string, mixed>
     */
    private static function donneesDAcceptation(): array
    {
        return ['password' => self::MOT_DE_PASSE, 'password_confirmation' => self::MOT_DE_PASSE, 'type_activite' => TypeDActivite::Commerce->value, 'choix_definitif' => true];
    }

    /**
     * Un cabinet (celui-ci, sinon un nouveau), un client lié avec ces
     * autorisations en plus des consultations, et un collaborateur à qui
     * il est confié.
     *
     * @param  list<string>  $enPlus
     * @return array{0: Societe, 1: Magasin, 2: LienCabinet, 3: User}
     */
    private function clientConfie(?Societe $cabinet = null, array $enPlus = []): array
    {
        $cabinet ??= $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique '.fake()->unique()->numberBetween(1, 9999)]);
        $lien = $this->lierLeCabinet($cabinet, $magasin, [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), ...$enPlus]);
        $collaborateur = $this->confier($this->collaborateur($cabinet, 'Collaborateur '.fake()->unique()->numberBetween(1, 9999)), [$magasin]);
        app(TenantContext::class)->clear();

        return [$cabinet, $magasin, $lien, $collaborateur];
    }

    private function attendre(?InvokedProcess $concurrente): ProcessResult
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);

        try {
            return $this->attendreLeProcessusConcurrent($concurrente);
        } catch (Throwable $erreur) {
            $this->fail($erreur->getMessage());
        }
    }
}
