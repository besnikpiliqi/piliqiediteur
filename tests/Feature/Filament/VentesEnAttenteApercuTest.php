<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\VentesEnAttenteApercu;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VentesEnAttenteApercuTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureParticulier(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    private function emettreAvoir(Client $client, Facture $facture): Avoir
    {
        return app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
    }

    public function test_affiche_le_nombre_et_le_total_des_ventes_en_attente_de_cloture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(); // particulier par défaut

        // 50 € HT, TVA 20 % => 60 € TTC, émise hier -- donc en attente de
        // la prochaine clôture (toujours "hier", voir ListClotureCaisses).
        $this->travelTo(now()->subDay());
        $this->emettreFactureParticulier($client);
        $this->travelBack();

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSuccessful()
            ->assertSee('Ventes en attente de clôture')
            ->assertSee('60,00 € au total');
    }

    public function test_deduit_du_total_les_avoirs_du_jour_en_attente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $this->travelTo(now()->subDay());
        $this->emettreFactureParticulier($client); // 60 € TTC
        $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        $this->travelBack();

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSee('1 avoir(s) déduit(s) · 60,00 € au total');
    }

    public function test_affiche_zero_quand_aucune_vente_nest_en_attente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSuccessful()
            ->assertSee('0,00 € au total');
    }

    public function test_un_vendeur_sans_la_permission_de_gerer_ne_voit_pas_le_widget(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(VentesEnAttenteApercu::canView());
    }
}
