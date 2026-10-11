<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\ServiceResource\Pages\ManageServices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_liste_montre_les_services_du_magasin_et_jamais_ceux_d_un_autre(): void
    {
        $autreProprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique'], $autreProprietaire);
        $serviceAutreMagasin = Service::factory()->create(['nom' => 'Service de la concurrence']);
        $this->connecterProprietaire();
        $service = Service::factory()->create(['nom' => 'Diagnostic']);

        Livewire::test(ManageServices::class)
            ->assertCanSeeTableRecords([$service])
            ->assertCanNotSeeTableRecords([$serviceAutreMagasin]);
    }

    public function test_le_proprietaire_cree_un_service(): void
    {
        $magasin = $this->connecterProprietaire();

        Livewire::test(ManageServices::class)
            ->callAction('create', data: ['nom' => '  Remplacement  écran ', 'actif' => true])
            ->assertHasNoActionErrors();

        $service = Service::query()->sole();
        $this->assertSame('Remplacement écran', $service->nom);
        $this->assertSame($magasin->id, $service->magasin_id);
    }

    public function test_renommer_un_service_corrige_son_nom(): void
    {
        $this->connecterProprietaire();
        $service = Service::factory()->create(['nom' => 'Remplacment écran']);

        Livewire::test(ManageServices::class)
            ->callTableAction('edit', $service, data: ['nom' => 'Remplacement écran'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Remplacement écran', $service->fresh()->nom);
    }

    public function test_changer_seulement_la_casse_de_son_propre_nom_est_accepte(): void
    {
        $this->connecterProprietaire();
        $service = Service::factory()->create(['nom' => 'diagnostic']);

        Livewire::test(ManageServices::class)
            ->callTableAction('edit', $service, data: ['nom' => 'Diagnostic'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Diagnostic', $service->fresh()->nom);
    }

    public function test_renommer_vers_un_service_existant_ecrit_autrement_est_refuse(): void
    {
        $this->connecterProprietaire();
        Service::factory()->create(['nom' => 'Main d\'œuvre']);
        $service = Service::factory()->create(['nom' => 'Nettoyage']);

        Livewire::test(ManageServices::class)
            ->callTableAction('edit', $service, data: ['nom' => ' main d’œuvre'])
            ->assertHasTableActionErrors(['nom' => ['Le service « Main d\'œuvre » existe déjà.']]);

        $this->assertSame('Nettoyage', $service->fresh()->nom);
    }

    public function test_creer_un_service_au_nom_d_un_service_archive_invite_a_le_reactiver(): void
    {
        $this->connecterProprietaire();
        Service::factory()->archive()->create(['nom' => 'Désoxydation']);

        Livewire::test(ManageServices::class)
            ->callAction('create', data: ['nom' => 'désoxydation', 'actif' => true])
            ->assertHasActionErrors(['nom' => ['Le service « Désoxydation » existe déjà mais n\'est plus proposé à la caisse. Réactivez-le plutôt.']]);

        $this->assertSame(1, Service::query()->count());
    }

    public function test_un_nom_fait_seulement_d_espaces_insecables_est_refuse(): void
    {
        $this->connecterProprietaire();

        Livewire::test(ManageServices::class)
            ->callAction('create', data: ['nom' => "\u{00A0}", 'actif' => true])
            ->assertHasActionErrors(['nom' => ['Le nom du service ne peut pas être fait seulement d\'espaces.']]);

        $this->assertSame(0, Service::query()->count());
    }

    public function test_archiver_un_service_le_retire_de_la_caisse(): void
    {
        $this->connecterProprietaire();
        $service = Service::factory()->create(['nom' => 'Flashage']);

        Livewire::test(ManageServices::class)
            ->callTableAction('edit', $service, data: ['actif' => false])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($service->fresh()->actif);
    }

    public function test_un_service_ne_se_supprime_jamais_depuis_la_liste(): void
    {
        $this->connecterProprietaire();
        $service = Service::factory()->create();

        Livewire::test(ManageServices::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $this->assertModelExists($service);
    }

    public function test_un_vendeur_voit_les_services_sans_pouvoir_les_creer_ni_les_modifier(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $service = Service::factory()->create();

        Livewire::test(ManageServices::class)
            ->assertCanSeeTableRecords([$service])
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $service);
    }

    /**
     * Un service n'a pas de page à lui : on le modifie depuis la liste. Le
     * service d'un autre magasin, visé par son identifiant, n'y a pas
     * d'action « Modifier ».
     */
    public function test_le_service_d_un_autre_magasin_ne_se_modifie_pas_depuis_la_liste(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $serviceAutreMagasin = Service::factory()->create(['nom' => 'Service de la concurrence']);
        $this->connecterProprietaire();

        Livewire::test(ManageServices::class)
            ->assertTableActionHidden('edit', $serviceAutreMagasin->getKey());
    }

    /**
     * Ce qu'une vente hors de France fait d'un service (voir
     * ControleNatureOperation) se coche dans sa fiche.
     */
    public function test_le_proprietaire_marque_des_frais_accessoires_et_un_lieu_fixe_par_la_loi(): void
    {
        $this->connecterProprietaire();
        $port = Service::factory()->create(['nom' => 'Frais de port']);
        $salle = Service::factory()->create(['nom' => 'Location de salle']);

        Livewire::test(ManageServices::class)
            ->callTableAction('edit', $port, data: ['nom' => 'Frais de port', 'frais_accessoires' => true])
            ->assertHasNoTableActionErrors()
            ->callTableAction('edit', $salle, data: ['nom' => 'Location de salle', 'lieu_specifique' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame([true, false], [$port->fresh()->frais_accessoires, $port->fresh()->lieu_specifique]);
        $this->assertSame([false, true], [$salle->fresh()->frais_accessoires, $salle->fresh()->lieu_specifique]);
    }

    private function connecterProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
