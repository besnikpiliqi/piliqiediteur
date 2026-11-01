<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\CabinetComptable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les autorisations qu'un client accorde au cabinet qu'il lie : par défaut
 * les consultations de son type, dans chacun de ses magasins réels,
 * présents et futurs, jamais la démonstration ; le propriétaire du client
 * en retire et n'ajoute que celles des achats et du stock, pour un client
 * qui a des articles ; jamais la vente, les comptes, les réglages ni la
 * caisse, ce que la base refuse aussi.
 */
class AutorisationsDuCabinetTest extends TestCase
{
    use RefreshDatabase;

    public function test_par_defaut_le_cabinet_a_les_consultations_dans_chaque_magasin_reel_du_client(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon autre boutique']);
        $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        foreach ([$magasin, $autre] as $chacun) {
            app(TenantContext::class)->set($chacun);
            $this->assertSame(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), $proprietaire->autorisationsDans($chacun), $chacun->nom);
            $this->assertContains(PermissionRegistry::SERVICE_VIEW, $proprietaire->autorisationsDans($chacun));
            $this->assertTrue($proprietaire->can('viewAny', Facture::class));
            $this->assertFalse($proprietaire->can('create', Facture::class));
        }
    }

    public function test_le_cabinet_lit_un_magasin_reel_cree_apres_le_lien_et_jamais_la_demonstration(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $this->lierLeCabinet($cabinet, $magasin);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe);
        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après le lien']);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        $this->assertTrue($proprietaire->peutOuvrir($nouveau));
        $this->assertFalse($proprietaire->peutOuvrir($demonstration));
        $this->assertSame([], $proprietaire->autorisationsDans($demonstration));
        $this->assertNotContains($demonstration->id, $proprietaire->magasinsAccessibles()->modelKeys());
    }

    public function test_un_nouveau_magasin_du_client_inscrit_les_droits_implicites_du_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après le lien']);

        $siens = array_values(array_filter($this->modificationsDesDroits($nouveau), fn (array $details): bool => $details['membre']['id'] === $proprietaire->id));
        $this->assertCount(1, $siens);
        $this->assertNull($siens[0]['avant']);
        $this->assertSame(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), $siens[0]['apres']['permissions']);
        $this->assertEquals(['id' => $cabinet->id, 'raison_sociale' => $cabinet->raison_sociale, 'siren' => $cabinet->siren], $siens[0]['apres']['cabinet']);
        $this->assertSame('Claire Martin (cabinet '.$cabinet->raison_sociale.')', $siens[0]['membre']['nom']);
    }

    public function test_le_proprietaire_du_client_retire_des_consultations_et_ajoute_des_autorisations_d_achat_et_de_stock(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $voulues = [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE];

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $lien, $voulues);

        $attendues = $voulues;
        sort($attendues);
        $this->assertSame($attendues, $lien->autorisations());
        $this->assertSame($attendues, $this->proprietaireDuCabinet($cabinet)->autorisationsDans($magasin));
        $this->assertContains('Autorisations changées', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
    }

    public function test_aucune_autorisation_de_vente_de_compte_de_reglage_ni_de_caisse_ne_s_accorde_au_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $interdites = array_values(array_diff(PermissionRegistry::toutes(), PermissionRegistry::duCabinet(TypeDActivite::Commerce)));
        $this->assertNotEmpty($interdites);

        foreach ($interdites as $autorisation) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $lien, [$autorisation]), InvalidArgumentException::class);
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $lien->id, 'autorisation' => $autorisation])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $page = Livewire::test(CabinetComptable::class);
        foreach ([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::PAIEMENT_CREER, PermissionRegistry::PARAMETRES_GERER, PermissionRegistry::CLOTURE_CAISSE_GERER] as $absente) {
            $page->assertDontSeeHtml('value="'.$absente.'"');
        }
        $page->assertSeeHtml('value="'.PermissionRegistry::FACTURE_ACHAT_CREER.'"');
    }

    public function test_du_cabinet_n_a_aucune_autorisation_de_vente_ni_de_gestion(): void
    {
        $jamais = [
            ...PermissionRegistry::deVente(),
            PermissionRegistry::CLIENT_CREATE, PermissionRegistry::CLIENT_UPDATE, PermissionRegistry::CLIENT_ARCHIVER,
            PermissionRegistry::PRODUIT_UPDATE, PermissionRegistry::PRODUIT_ARCHIVER, PermissionRegistry::PRODUIT_AJUSTER_STOCK, PermissionRegistry::PRIX_VENTE_REGLER, PermissionRegistry::VENTE_REMISES_ET_PRIX,
            PermissionRegistry::SERVICE_CREATE, PermissionRegistry::SERVICE_UPDATE,
            PermissionRegistry::DEVIS_CREATE, PermissionRegistry::DEVIS_UPDATE, PermissionRegistry::DEVIS_ENVOYER,
            PermissionRegistry::FACTURE_ENVOYER, PermissionRegistry::FACTURE_JUSTIFIER, PermissionRegistry::RELANCES_REGLER, PermissionRegistry::PARAMETRES_GERER,
            PermissionRegistry::PAIEMENT_CONTRE_PASSER, PermissionRegistry::AVOIR_CREER, PermissionRegistry::AVOIR_ENVOYER,
            PermissionRegistry::CLOTURE_CAISSE_GERER,
        ];

        $this->assertSame([], array_values(array_intersect(PermissionRegistry::duCabinet(TypeDActivite::Commerce), $jamais)));
        $this->assertSame([], array_values(array_intersect(PermissionRegistry::duCabinet(TypeDActivite::Services), PermissionRegistry::articles())));
        $this->assertSame([], PermissionRegistry::duCabinet(TypeDActivite::Cabinet));

        $garde = (new \ReflectionMethod(GardesDesCabinets::class, 'refus'))->invoke(null, GardesDesCabinets::AUTORISATIONS)[0][0];
        preg_match_all("/'([a-z_.]+)'/", $garde, $listees);
        $this->assertSame(PermissionRegistry::duCabinet(TypeDActivite::Commerce), $listees[1]);
    }

    public function test_un_client_de_services_ne_propose_ni_n_accorde_aucune_autorisation_d_articles(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services]);
        $lien = $this->lierLeCabinet($cabinet, $magasin);

        $this->assertSame([], array_values(array_intersect($lien->autorisations(), PermissionRegistry::articles())));
        foreach (array_intersect(PermissionRegistry::duCabinet(TypeDActivite::Commerce), PermissionRegistry::articles()) as $autorisation) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $lien, [$autorisation]), InvalidArgumentException::class);
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $lien->id, 'autorisation' => $autorisation])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(CabinetComptable::class)->assertFormFieldIsHidden('achats')->assertDontSeeText('Achats et stock');
    }

    public function test_le_collaborateur_a_exactement_les_autorisations_accordees_au_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->lierLeCabinet($cabinet, $magasin, [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::FACTURE_VIEW]);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);

        $this->assertSame([PermissionRegistry::CLIENT_VIEW, PermissionRegistry::FACTURE_VIEW], $collaborateur->autorisationsDans($magasin));
        $this->dansLeMagasin($magasin, $collaborateur);
        $this->assertTrue($collaborateur->can(PermissionRegistry::CLIENT_VIEW));
        $this->assertFalse($collaborateur->can(PermissionRegistry::PRODUIT_VIEW));
    }

    public function test_service_create_ne_s_accorde_jamais_au_cabinet_et_produit_create_ne_cree_jamais_un_service(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $lien = $this->lierLeCabinet($cabinet, $magasin, [PermissionRegistry::PRODUIT_CREATE, PermissionRegistry::SERVICE_VIEW]);

        foreach ([PermissionRegistry::SERVICE_CREATE, PermissionRegistry::SERVICE_UPDATE] as $autorisation) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $lien, [$autorisation]), InvalidArgumentException::class);
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $lien->id, 'autorisation' => $autorisation])), QueryException::class);
        }

        $this->dansLeMagasin($magasin, $this->proprietaireDuCabinet($cabinet));
        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->can('viewAny', Service::class));
        $this->assertFalse($this->proprietaireDuCabinet($cabinet)->can('create', Service::class));
    }

    public function test_chaque_reglage_des_autorisations_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_deux_journaux(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon autre boutique']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $lien, [PermissionRegistry::FACTURE_VIEW]);

        foreach ([$magasin, $autre] as $chacun) {
            $dernier = collect($this->modificationsDesDroits($chacun))->where('membre.id', $proprietaire->id)->last();
            $this->assertSame([PermissionRegistry::FACTURE_VIEW], $dernier['apres']['permissions'], $chacun->nom);
            $this->assertSame(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), $dernier['avant']['permissions'], $chacun->nom);
        }
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::AutorisationsDuCabinetModifiees));
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::AutorisationsDuCabinetModifiees));
    }

    public function test_un_compte_de_cabinet_n_a_que_les_autorisations_du_lien_quelles_que_soient_les_lignes_en_base(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $this->retirerLaGardeDesCabinets(GardesDesCabinets::AUTORISATIONS);
        DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $lien->id, 'autorisation' => PermissionRegistry::FACTURE_CREATE]);

        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->dansLeMagasin($magasin, $proprietaire);

        $this->assertNotContains(PermissionRegistry::FACTURE_CREATE, $proprietaire->autorisationsDans($magasin));
        $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_CREATE));
        $this->assertFalse($proprietaire->can('create', Facture::class));
        $this->assertFalse($proprietaire->can(AutorisationsSociete::MAGASINS_GERER));
    }

    /**
     * Retire une garde des cabinets comme le ferait un administrateur de la
     * base, pour montrer ce que les lectures refusent d'elles-mêmes.
     */
    private function retirerLaGardeDesCabinets(string $garde): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$garde} ON autorisations_cabinet");

            return;
        }

        foreach (GardesDesCabinets::declencheursSqlite($garde) as $declencheur) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$declencheur}");
        }
    }
}
