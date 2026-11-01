<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterSonCabinetACreerSonEspaceAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Mail\InvitationACreerUnEspaceMail;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\RechercheDesCabinets;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use App\Http\Controllers\RattachementAuCabinetController;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le lien entre un cabinet d'expertise comptable et une société déjà
 * inscrite : la demande de rattachement du cabinet, à laquelle son
 * propriétaire répond sur la page de rattachement, ou la désignation du
 * cabinet par la société, que le propriétaire du cabinet accepte ; un
 * client n'a qu'un cabinet lié à la fois ; une société qui change de mains
 * ou dont l'inscription se clôt avant sa validation perd lien et demandes.
 */
class LiensAvecUneSocieteInscriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_d_une_societe_trouve_un_cabinet_verifie_par_siren_ou_par_nom_sans_casse_ni_accents(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        app(TenantContext::class)->runAsSociete($cabinet, fn (): bool => $cabinet->forceFill(['raison_sociale' => 'Cabinet Étienne Comptabilité'])->save());

        $recherche = app(RechercheDesCabinets::class);

        $this->assertSame([$cabinet->id], $recherche->pour((string) $cabinet->siren)->modelKeys());
        $this->assertSame([$cabinet->id], $recherche->pour('etienne compta')->modelKeys());
        $this->assertSame([$cabinet->id], $recherche->pour('ÉTIENNE')->modelKeys());
        $this->assertSame([], $recherche->pour('et')->modelKeys());
    }

    public function test_la_recherche_ne_montre_ni_un_cabinet_non_verifie_ni_une_societe_qui_n_est_pas_un_cabinet_et_se_limite_a_vingt(): void
    {
        $verifie = $this->ouvrirUnCabinet();
        $nonVerifie = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $nonVerifie->cabinet()->sole()->forceFill(['ordre_retire_le' => now()])->save();
        $boutique = $this->ouvrirUneSociete(['nom' => 'Boutique', 'raison_sociale' => 'SOCIETE FICTIVE BOUTIQUE'])->societe;
        $colonnes = [];
        DB::listen(function ($requete) use (&$colonnes): void {
            if (str_starts_with($requete->sql, 'select') && str_contains($requete->sql, 'from "societes"')) {
                $colonnes[] = $requete->sql;
            }
        });

        $trouves = app(RechercheDesCabinets::class)->pour('SOCIETE FICTIVE');

        $this->assertSame([$verifie->id], $trouves->modelKeys());
        $this->assertNotContains($boutique->id, $trouves->modelKeys());
        $this->assertStringContainsString('select "id", "uuid", "siren", "raison_sociale", "siege_code_postal", "siege_ville" from "societes"', $colonnes[0]);
        $this->assertSame(20, RechercheDesCabinets::RESULTATS_AU_PLUS);
        $this->assertStringContainsString('limit 20', $colonnes[0]);
    }

    public function test_designer_un_cabinet_envoie_la_demande_et_le_lien_nait_quand_le_cabinet_accepte(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        Mail::fake();

        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);

        $this->assertSame([EtatDuLien::DemandeParLeClient, OrigineDuLien::DesignationDuClient, null], [$demande->etat, $demande->origine, DB::table('liens_cabinet')->value('jeton_hache')]);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::LienDemande));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::LienDemande));
        $this->assertContains('Demande de lien d\'un client', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());

        $lien = app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);

        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);
        $this->assertSame(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), $lien->autorisations());
        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin));
    }

    public function test_le_lien_de_la_demande_mene_apres_la_connexion_du_proprietaire_a_la_page_de_rattachement(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente', 'raison_sociale' => 'BOULANGERIE SECRETE']);
        $jeton = $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $url = route('cabinets.rattachement.afficher', ['jeton' => $jeton]);

        $this->get($url)->assertOk()->assertSeeText([(string) $cabinet->raison_sociale, (string) $magasin->societe->siren])->assertDontSeeText('BOULANGERIE SECRETE');
        $this->assertSame($url, session('url.intended'));

        $this->actingAs($this->proprietaireDe($magasin))->get($url)->assertRedirect(RattachementDUnCabinet::getUrl(['lien' => $demande->uuid], panel: 'societe', tenant: $magasin->societe));

        foreach ([$this->administrateur($magasin, null, AutorisationsSociete::toutes()), $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre'])), $this->proprietaireDuCabinet($cabinet)] as $autre) {
            $this->actingAs($autre)->get($url)->assertForbidden()->assertSeeText(RattachementAuCabinetController::MESSAGE_AUTRE_COMPTE)->assertDontSeeText('BOULANGERIE SECRETE');
        }

        $this->get(route('cabinets.rattachement.afficher', ['jeton' => str_repeat('x', 64)]))->assertStatus(410)->assertSeeText(RattachementAuCabinetController::MESSAGE_LIEN_INVALIDE);
        app(RetirerLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);
        $this->get($url)->assertStatus(410)->assertSeeText(RattachementAuCabinetController::MESSAGE_LIEN_INVALIDE);
    }

    public function test_la_page_de_rattachement_accepte_avec_les_autorisations_par_defaut_ou_ajustees_ou_refuse(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $demande->uuid])
            ->assertActionVisible('accepterParDefaut')->assertActionVisible('accepterEnAjustant')->assertActionVisible('refuser')
            ->mountAction('accepterEnAjustant')
            ->set('mountedActionsData.0.consultations', [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES])
            ->set('mountedActionsData.0.achats', [PermissionRegistry::FACTURE_ACHAT_CREER])
            ->callMountedAction()
            ->assertRedirect(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe));

        $lien = $demande->fresh();
        $this->assertSame([EtatDuLien::Actif, null], [$lien->etat, DB::table('liens_cabinet')->where('id', $lien->id)->value('jeton_hache')]);
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_ACHAT_CREER], $lien->autorisations());
        $this->assertCount(1, collect($this->modificationsDesDroits($magasin))->where('membre.id', $this->proprietaireDuCabinet($cabinet)->id));
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::LienActive);
        $this->assertSame('ajustees', $entree->nouvelles_valeurs['autorisations']);

        $autre = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $this->demanderLeRattachement($cabinet, $autre->societe, 'compta@autre.test');
        $refusee = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $autre->societe_id)->sole();
        $this->dansLEspaceDeLaSociete($autre->societe, $this->proprietaireDe($autre));
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $refusee->uuid])->callAction('refuser');
        $this->assertSame([EtatDuLien::Refuse, null], [$refusee->fresh()->etat, DB::table('liens_cabinet')->where('id', $refusee->id)->value('jeton_hache')]);

        $troisieme = $this->ouvrirUneSociete(['nom' => 'Troisième boutique']);
        $this->demanderLeRattachement($cabinet, $troisieme->societe, 'compta@troisieme.test');
        $parDefaut = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $troisieme->societe_id)->sole();
        $this->dansLEspaceDeLaSociete($troisieme->societe, $this->proprietaireDe($troisieme));
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $parDefaut->uuid])->callAction('accepterParDefaut');
        $this->assertSame(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), $parDefaut->fresh()->autorisations());
        $this->assertSame('par_defaut', collect($this->entreesDAudit($troisieme->societe, ActionAuditee::LienActive))->sole()->nouvelles_valeurs['autorisations']);
    }

    public function test_la_page_de_rattachement_ne_propose_les_achats_et_le_stock_qu_a_un_client_qui_a_des_articles(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Atelier de services', 'type' => TypeDActivite::Services]);
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@services.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(RattachementDUnCabinet::class, ['lien' => $demande->uuid])
            ->mountAction('accepterEnAjustant')
            ->assertFormFieldIsHidden('achats', 'mountedActionForm')
            ->assertFormFieldIsVisible('consultations', 'mountedActionForm');

        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDe($magasin), $demande, [PermissionRegistry::FACTURE_ACHAT_CREER]), InvalidArgumentException::class);
        $this->assertSame(EtatDuLien::DemandeParLeCabinet, $demande->fresh()->etat);
    }

    public function test_la_page_de_rattachement_d_une_autre_societe_ou_d_une_demande_close_est_neutre(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $this->demanderLeRattachement($cabinet, $autre->societe, 'compta@autre.test');
        $demandeDeLAutre = LienCabinet::query()->withoutGlobalScopes()->sole();
        $actif = $this->lierLeCabinet($cabinet, $magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        foreach ([$demandeDeLAutre->uuid, $actif->uuid, 'inconnu'] as $uuid) {
            Livewire::test(RattachementDUnCabinet::class, ['lien' => $uuid])
                ->assertSeeText(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE)
                ->assertActionHidden('accepterParDefaut')
                ->assertActionHidden('refuser');
        }
    }

    public function test_designer_le_cabinet_qui_a_deja_demande_le_lien_l_accepte(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');

        $lien = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);

        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);
        $this->assertSame(1, DB::table('liens_cabinet')->count());
    }

    public function test_une_demande_se_refuse_ou_se_retire_et_s_inscrit_aux_deux_journaux(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $designation = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);

        app(RefuserLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $designation);

        $this->assertSame(EtatDuLien::Refuse, $designation->fresh()->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::LienRefuse));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::LienRefuse));
        $this->assertContains('Demande de lien refusée', $this->proprietaireDe($magasin)->notifications()->get()->pluck('data.title')->all());

        $autre = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        app(TenantContext::class)->clear();
        $retiree = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($autre), $autre->societe, $cabinet);
        app(RetirerLaDemandeDeLienAction::class)->handle($this->proprietaireDe($autre), $retiree);
        $this->assertSame(EtatDuLien::Retire, $retiree->fresh()->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienRetiree));
        $this->assertCount(1, $this->entreesDAudit($autre->societe, ActionAuditee::DemandeDeLienRetiree));
    }

    /**
     * Le propriétaire du cabinet répond dans son espace aux demandes de
     * deux clients, par de vraies requêtes de son navigateur : son adresse
     * IP et son navigateur restent au journal d'audit de son cabinet,
     * jamais à celui d'un client ni au journal des événements de ses
     * magasins, que le refus ou l'acceptation y inscrivent aussi.
     */
    public function test_la_reponse_du_cabinet_a_une_demande_ne_laisse_sa_trace_qu_au_journal_du_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $refuse = $this->ouvrirUneSociete(['nom' => 'Boutique refusée']);
        $accepte = $this->ouvrirUneSociete(['nom' => 'Boutique acceptée']);
        app(TenantContext::class)->clear();
        $aRefuser = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($refuse), $refuse->societe, $cabinet);
        $aAccepter = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($accepte), $accepte->societe, $cabinet);
        $jet130 = fn (): array => app(TenantContext::class)->runAs($accepte, fn (): array => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ModificationDesDroits->value)->pluck('id')->all());
        $avant = $jet130();
        $this->actingAs($this->proprietaireDuCabinet($cabinet));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])->withHeader('User-Agent', 'NavigateurDuCabinet/1.0');

        foreach (['refuserLaDemande' => $aRefuser, 'accepterLaDemande' => $aAccepter] as $geste => $demande) {
            $page = $this->get(InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet))->assertOk()->getContent();
            $montee = $this->requeteLivewire($this->instantaneLivewire($page, app(ComponentRegistry::class)->getName(InvitationsEtDemandes::class)), [], 'mountAction', [$geste, ['uuid' => $demande->uuid]])->assertOk();
            $this->requeteLivewire($montee->json('components.0.snapshot'), [], 'callMountedAction')->assertOk();
        }

        $this->assertSame([EtatDuLien::Refuse, EtatDuLien::Actif], [$aRefuser->fresh()->etat, $aAccepter->fresh()->etat]);
        $traces = fn (array $entrees): array => DB::table('traces_audit')->whereIn('entree_audit_id', array_map(fn ($entree): int => (int) $entree->id, $entrees))->pluck('ip')->all();
        $this->assertSame(['203.0.113.88', '203.0.113.88'], $traces([...$this->entreesDAudit($cabinet, ActionAuditee::LienRefuse), ...$this->entreesDAudit($cabinet, ActionAuditee::LienActive)]));
        $this->assertSame([], $traces([...$this->entreesDAudit($refuse->societe, ActionAuditee::LienRefuse), ...$this->entreesDAudit($accepte->societe, ActionAuditee::LienActive)]));
        $nouveaux = array_values(array_diff($jet130(), $avant));
        $this->assertNotSame([], $nouveaux);
        $this->assertFalse(app(TenantContext::class)->runAs($accepte, fn (): bool => DB::table('traces_evenements')->whereIn('enregistrement_fiscal_id', $nouveaux)->exists()));
    }

    /**
     * Une désignation dont l'échéance est passée, que la tâche horaire n'a
     * pas encore expirée, n'est jamais rendue comme une demande en cours :
     * la désigner de nouveau la lit expirée et refuse une nouvelle demande
     * avant 7 jours après son échéance, sans rien écrire ; ensuite, elle
     * expire, aux deux journaux, et la nouvelle demande part.
     */
    public function test_designer_de_nouveau_apres_l_echeance_expire_la_demande_perimee_sans_attendre_la_tache_horaire(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $designation = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);

        $this->travelTo(self::aParis('2026-10-19 10:30:00'));
        $this->assertThrows(fn () => app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet), LogicException::class, DesignerUnCabinetAction::MESSAGE_DEJA_DEMANDE);

        $this->assertSame(EtatDuLien::DemandeParLeClient, $designation->fresh()->etat);
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->count());
        $this->assertCount(0, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienExpiree));

        $this->travelTo(self::aParis('2026-10-26 10:00:01'));
        $nouvelle = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);

        $this->assertTrue($nouvelle->isNot($designation));
        $this->assertTrue($nouvelle->estUneDemandeValable());
        $this->assertSame(EtatDuLien::Expire, $designation->fresh()->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienExpiree));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::DemandeDeLienExpiree));
    }

    public function test_une_demande_expire_apres_sept_jours(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $jeton = $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');
        app(TenantContext::class)->clear();
        $designation = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($autre), $autre->societe, $cabinet);

        $this->travelTo(self::aParis('2026-10-19 10:00:01'));
        $this->artisan('app:expirer-invitations-cabinets')->assertSuccessful();

        $this->assertSame([EtatDuLien::Expire, EtatDuLien::Expire], [LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->sole()->etat, $designation->fresh()->etat]);
        $this->assertSame(0, DB::table('liens_cabinet')->whereNotNull('jeton_hache')->count());
        $this->assertCount(2, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienExpiree));
        $this->get(route('cabinets.rattachement.afficher', ['jeton' => $jeton]))->assertStatus(410);
    }

    public function test_un_client_n_a_qu_un_cabinet_lie_et_en_lier_un_autre_met_fin_au_precedent(): void
    {
        $ancien = $this->ouvrirUnCabinet();
        $nouveau = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $premier = $this->lierLeCabinet($ancien, $magasin);
        $collaborateur = $this->confier($this->collaborateur($ancien), [$magasin]);
        $collaborateur->notify(new AvisDuCabinet($ancien, 'Avis sur ce client', 'Un avis.', clientId: $magasin->societe_id));

        $second = $this->lierLeCabinet($nouveau, $magasin);

        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::Remplace, EtatDuLien::Actif], [$premier->fresh()->etat, $premier->fresh()->motif_fin, $second->etat]);
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', 'actif')->count());
        $this->assertSame(0, DB::table('clients_confies')->count());
        $fin = collect($this->modificationsDesDroits($magasin))->filter(fn (array $jet): bool => $jet['apres'] === null)->pluck('membre.id')->sort()->values()->all();
        $this->assertSame([$this->proprietaireDuCabinet($ancien)->id, $collaborateur->id], $fin);
        [$termineAuCabinet] = $this->entreesDAudit($ancien, ActionAuditee::LienTermine);
        $this->assertSame('remplace', $termineAuCabinet->nouvelles_valeurs['motif']);
        $this->assertStringNotContainsString((string) $nouveau->raison_sociale, json_encode($termineAuCabinet->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertSame([], array_diff(array_keys($termineAuCabinet->nouvelles_valeurs['droits']), [$this->proprietaireDuCabinet($ancien)->id, $collaborateur->id]));
        [$activeAuNouveau] = $this->entreesDAudit($nouveau, ActionAuditee::LienActive);
        $this->assertStringNotContainsString((string) $ancien->raison_sociale, json_encode($activeAuNouveau->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::LienTermine));
        $this->assertNotContains('Avis sur ce client', $collaborateur->notifications()->get()->pluck('data.title')->all());
        $this->assertContains('Lien terminé', $collaborateur->notifications()->get()->pluck('data.title')->all());
    }

    public function test_le_proprietaire_du_cabinet_accepte_la_demande_d_un_client_controle_dans_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);

        foreach ([fn () => app(AccepterLaDemandeDeLienAction::class)->handle($collaborateur, $demande), fn () => app(RefuserLaDemandeDeLienAction::class)->handle($collaborateur, $demande)] as $geste) {
            $this->assertThrows($geste, AuthorizationException::class);
        }
        $this->assertSame(EtatDuLien::DemandeParLeClient, $demande->fresh()->etat);

        app(TenantContext::class)->set($magasin);
        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);
        $this->assertSame(EtatDuLien::Actif, $demande->fresh()->etat);
    }

    public function test_un_rattachement_ne_valide_jamais_une_societe_qui_attend_son_code(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $enAttente = $this->inscrireUneSociete();
        $proprietaire = $enAttente->proprietaire()->sole();

        $demande = app(DesignerUnCabinetAction::class)->handle($proprietaire, $enAttente, $cabinet);
        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);

        $this->assertTrue($enAttente->fresh()->attendSonCode());
        $this->assertCount(0, $this->entreesDAudit($enAttente, ActionAuditee::SocieteValidee));

        $autre = $this->inscrireUneSociete();
        $this->demanderLeRattachement($cabinet, $autre, 'compta@autre.test');
        $demandeDuCabinet = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $autre->id)->sole();
        $this->dansLEspaceDeLaSociete($autre, $autre->proprietaire()->sole());
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $demandeDuCabinet->uuid])
            ->assertSeeText('Accepter ce rattachement ne valide pas votre société : saisissez le code du courrier envoyé à votre siège.')
            ->callAction('accepterParDefaut');
        $this->assertTrue($autre->fresh()->attendSonCode());
        $this->assertSame(EtatDuLien::Actif, $demandeDuCabinet->fresh()->etat);
    }

    public function test_seul_le_proprietaire_du_client_designe_accepte_regle_et_met_fin_au_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $responsable = $this->responsable($magasin);
        $lien = $this->lierLeCabinet($cabinet, $magasin);

        foreach ([$administrateur, $responsable] as $compte) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(DesignerUnCabinetAction::class)->handle($compte, $magasin->societe, $cabinet), AuthorizationException::class);
            $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($compte, $lien, []), AuthorizationException::class);
            $this->assertThrows(fn () => app(MettreFinAuLienAction::class)->handle($compte, $lien), AuthorizationException::class);
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->get(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);
    }

    public function test_seul_le_proprietaire_du_cabinet_accepte_ou_met_fin_au_lien_du_cote_du_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);

        $this->assertThrows(fn () => app(MettreFinAuLienAction::class)->handle($collaborateur, $lien), AuthorizationException::class);

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $lien);
        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::ParLeCabinet], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
    }

    public function test_une_societe_invite_son_cabinet_a_creer_son_espace_sans_garder_son_adresse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        Mail::fake();

        app(InviterSonCabinetACreerSonEspaceAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, 'expert@cabinet.test');

        $mail = Mail::sent(InvitationACreerUnEspaceMail::class)->sole();
        $this->assertTrue($mail->hasTo('expert@cabinet.test'));
        $this->assertStringContainsString('type=cabinet', html_entity_decode($mail->render()));
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::EspaceCabinetSuggere);
        $this->assertSame(['envoye' => true], $entree->nouvelles_valeurs);
        $this->assertStringNotContainsString('cabinet.test', json_encode(DB::table('journal_audit')->get()->all(), JSON_THROW_ON_ERROR));

        foreach (range(2, 5) as $numero) {
            app(InviterSonCabinetACreerSonEspaceAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, "expert{$numero}@cabinet.test");
        }
        $this->assertThrows(fn () => app(InviterSonCabinetACreerSonEspaceAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, 'expert6@cabinet.test'), LogicException::class);
    }

    public function test_un_lien_prend_fin_quand_une_autre_personne_saisit_le_code_du_courrier(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $societe = $this->inscrireUneSociete();
        $lien = $this->lierLeCabinet($cabinet, $societe);
        $demande = app(DesignerUnCabinetAction::class)->handle($societe->proprietaire()->sole(), $societe->fresh(), $autreCabinet);

        $nouveau = $this->saisirLeCodeCommeUneAutrePersonne($societe, 'reprise@societe.test');

        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::ProprietaireRemplace], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
        $this->assertSame(EtatDuLien::Retire, $demande->fresh()->etat);
        $this->assertContains('Lien terminé', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
        $this->assertContains('Demande de lien retirée', $this->proprietaireDuCabinet($autreCabinet)->notifications()->get()->pluck('data.title')->all());

        app(TenantContext::class)->clear();
        $nouveau->forceFill(['email' => 'reprise@societe.test', 'email_verified_at' => now()])->save();
        $nouvelle = app(DesignerUnCabinetAction::class)->handle($nouveau, $societe->fresh(), $cabinet);
        $this->assertSame(EtatDuLien::DemandeParLeClient, $nouvelle->etat);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cloturesDUneInscription(): array
    {
        return ['30 jours sans code' => ['delai'], 'refus de l\'éditeur' => ['refus'], 'type erroné' => ['type_errone']];
    }

    #[DataProvider('cloturesDUneInscription')]
    public function test_un_lien_prend_fin_quand_l_inscription_est_close_sans_validation(string $cloture): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        $lien = $this->lierLeCabinet($cabinet, $societe);
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $demande = app(DesignerUnCabinetAction::class)->handle($societe->proprietaire()->sole(), $societe->fresh(), $autreCabinet);
        app(TenantContext::class)->clear();

        match ($cloture) {
            'delai' => (function (): void {
                $this->travelTo(self::aParis('2026-11-02 10:00:00'));
                $this->artisan('app:clore-inscriptions-sans-code')->assertSuccessful();
            })(),
            default => app(CloreLInscriptionAction::class)->parLEditeur($societe->fresh(), new InterventionDeLEditeur('Courrier revenu, pièces insuffisantes.', 'DOSSIER-2026-0070', ['alice']), MotifCloture::from($cloture)),
        };

        $this->assertTrue($societe->fresh()->estClose());
        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::InscriptionClose, EtatDuLien::Retire], [$lien->fresh()->etat, $lien->fresh()->motif_fin, $demande->fresh()->etat]);
        $this->assertContains($this->proprietaireDuCabinet($cabinet)->id, collect($this->modificationsDesDroits($magasin))->where('apres', null)->pluck('membre.id')->all());
        $this->assertContains('Lien terminé', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
        $this->assertFalse($societe->proprietaire()->sole()->can('gererSonCabinet', $societe->fresh()));
    }

    public function test_la_base_refuse_une_demande_de_lien_rattachee_au_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([
            'uuid' => (string) Str::uuid(), 'societe_id' => $cabinet->id, 'cabinet_id' => $cabinet->id, 'origine' => 'designation_du_client',
            'etat' => 'demande_par_le_client', 'demande_le' => now(), 'expire_le' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])), QueryException::class);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->count());
    }
}
