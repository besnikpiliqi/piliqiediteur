<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\Societe\Pages\CabinetComptable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use LogicException;
use Tests\TestCase;

/**
 * La fin du lien entre un cabinet d'expertise comptable et un client, par
 * l'un ou l'autre propriétaire (voir MettreFinAuLienAction) : l'accès de
 * chaque compte du cabinet s'arrête dès sa requête suivante, sans fermer
 * ses sessions ; elle s'inscrit au JET 130 de chaque magasin réel du client
 * et aux journaux des deux sociétés, efface les clients confiés et prévient
 * l'autre propriétaire et les collaborateurs.
 */
class FinDuLienTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_client_met_fin_au_lien_et_l_acces_du_cabinet_s_arrete_aussitot(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)->callAction('mettreFin');

        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::ParLeClient], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
        $this->assertFalse($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin));
        $this->assertSame([], $lien->fresh()->autorisations());
    }

    public function test_le_cabinet_met_fin_au_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $lien);

        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::ParLeCabinet], [$lien->fresh()->etat, $lien->fresh()->motif_fin]);
        $this->assertContains('Lien avec votre cabinet terminé', $this->proprietaireDe($magasin)->notifications()->get()->pluck('data.title')->all());
    }

    public function test_une_session_ouverte_perd_l_acces_au_client_des_sa_requete_suivante(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $instantane = $this->instantaneLivewire($this->actingAs($collaborateur)->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk()->getContent(), app(ComponentRegistry::class)->getName(ListFactures::class));

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $lien);

        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertNotFound();
        $this->assertContains($this->requeteLivewire($instantane, [], '$refresh')->status(), [403, 404]);
        $this->assertTrue($this->isAuthenticated());
        $this->assertTrue($collaborateur->fresh()->ouvreLEspaceDuCabinet());
    }

    public function test_la_fin_du_lien_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_journaux_des_deux_societes(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Seconde boutique']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $lien);

        foreach ([$magasin, $autre] as $chacun) {
            $dernier = collect($this->modificationsDesDroits($chacun))->where('membre.id', $proprietaire->id)->last();
            $this->assertNull($dernier['apres'], $chacun->nom);
            $this->assertNotNull($dernier['avant'], $chacun->nom);
        }
        [$auClient] = $this->entreesDAudit($magasin->societe, ActionAuditee::LienTermine);
        [$auCabinet] = $this->entreesDAudit($cabinet, ActionAuditee::LienTermine);
        $this->assertSame(['par_le_client', 'par_le_client'], [$auClient->nouvelles_valeurs['motif'], $auCabinet->nouvelles_valeurs['motif']]);
        $this->assertArrayHasKey((string) $proprietaire->id, $auCabinet->anciennes_valeurs['droits']);
    }

    public function test_la_fin_du_lien_efface_les_clients_confies_et_previent_l_autre_proprietaire_et_les_collaborateurs(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $collaborateur->notify(new AvisDuCabinet($cabinet, 'Ancien avis sur ce client', 'Un avis.', clientId: $magasin->societe_id));

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $lien);

        $this->assertSame(0, DB::table('clients_confies')->where('lien_id', $lien->id)->count());
        $titres = $collaborateur->notifications()->get()->pluck('data.title')->all();
        $this->assertNotContains('Ancien avis sur ce client', $titres);
        $this->assertContains('Lien terminé', $titres);
        $this->assertContains('Lien terminé', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
    }

    public function test_un_cabinet_suspendu_met_fin_a_un_lien_mais_ne_modifie_plus_son_identite(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        app(MettreFinAuLienAction::class)->handle($proprietaire, $lien);

        $this->assertSame(EtatDuLien::Termine, $lien->fresh()->etat);
        $this->assertThrows(fn () => app(TenantContext::class)->runAsSociete($cabinet->fresh(), fn () => app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $cabinet->fresh(), ['raison_sociale' => 'Nouveau nom'])), LogicException::class);
        $this->assertNotSame('Nouveau nom', $cabinet->fresh()->raison_sociale);
    }

    public function test_mettre_fin_a_un_lien_deja_termine_ne_fait_rien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $lien);
        $audits = count($this->entreesDAudit($magasin->societe, ActionAuditee::LienTermine));

        app(TenantContext::class)->clear();
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $lien->fresh());

        $this->assertSame(MotifDeFinDuLien::ParLeClient, $lien->fresh()->motif_fin);
        $this->assertCount($audits, $this->entreesDAudit($magasin->societe, ActionAuditee::LienTermine));
    }

    public function test_une_societe_suspendue_met_quand_meme_fin_au_lien_et_une_societe_revoquee_ne_gere_plus_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $suspendue = $this->ouvrirUneSociete(['nom' => 'Suspendue']);
        $revoquee = $this->ouvrirUneSociete(['nom' => 'Révoquée']);
        $lienSuspendue = $this->lierLeCabinet($cabinet, $suspendue);
        $lienRevoquee = $this->lierLeCabinet($cabinet, $revoquee);
        $suspendue->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $revoquee->societe->forceFill(['statut' => StatutSociete::Revoquee])->save();

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($suspendue), $lienSuspendue);
        $this->assertSame(EtatDuLien::Termine, $lienSuspendue->fresh()->etat);

        $this->assertThrows(fn () => app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($revoquee), $lienRevoquee), AuthorizationException::class);
        $this->assertFalse($this->proprietaireDe($revoquee)->can('gererSonCabinet', $revoquee->societe->fresh()));
        $this->assertSame(EtatDuLien::Actif, $lienRevoquee->fresh()->etat);
    }

    public function test_la_liste_des_factures_d_un_magasin_du_client_se_ferme_au_compte_du_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListFactures::class)->assertSuccessful();

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $lien);

        $this->dansLeMagasin($magasin, $proprietaire->fresh());
        $this->assertFalse($proprietaire->fresh()->can('viewAny', Facture::class));
    }
}
