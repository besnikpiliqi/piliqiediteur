<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClotureCaissePersonnelleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_sans_permission_de_cloture_peut_quand_meme_fermer_sa_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse($vendeur->can(PermissionRegistry::CLOTURE_CAISSE_GERER), 'Prérequis du test : le vendeur ne doit pas avoir la permission de clôturer la journée.');

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $facture = Facture::query()->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->callAction('fermer_ma_caisse');

        $releve = ClotureCaissePersonnelle::query()->where('cree_par', $vendeur->id)->firstOrFail();
        $this->assertSame(1, $releve->nombre_ventes);
        $this->assertSame('60.00', $releve->total_ttc);
    }

    public function test_un_vendeur_ne_voit_que_ses_propres_releves(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeurA = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul', 'email' => 'paul2@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);
        $vendeurB = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Jean', 'email' => 'jean@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaissePersonnelles::class)->callAction('fermer_ma_caisse');

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaissePersonnelles::class)->callAction('fermer_ma_caisse');

        $releveA = ClotureCaissePersonnelle::where('cree_par', $vendeurA->id)->firstOrFail();
        $releveB = ClotureCaissePersonnelle::where('cree_par', $vendeurB->id)->firstOrFail();

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertCanSeeTableRecords([$releveB])
            ->assertCanNotSeeTableRecords([$releveA]);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertCanSeeTableRecords([$releveA, $releveB]);
    }
}
