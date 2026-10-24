<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
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
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

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
        return app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
    }

    public function test_affiche_le_nombre_et_le_total_des_ventes_de_la_journee_en_attente_de_cloture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier(Client::factory()->create()); // 50 € HT, TVA 20 % => 60 € TTC

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSee('Ventes en attente de clôture')
            ->assertSee('Journée du 09/10/2026 · 60,00 € au total');
    }

    public function test_deduit_du_total_les_avoirs_du_jour_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier($client); // 60 € TTC
        $this->emettreAvoir($client, $this->emettreFactureParticulier($client));

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSee('Journée du 09/10/2026 · 1 avoir(s) déduit(s) · 60,00 € au total');
    }

    /**
     * Une journée oubliée (ni clôture manuelle, ni passage de la commande
     * de minuit) attend avec celle du jour, et ses ventes comptent.
     */
    public function test_affiche_une_journee_oubliee_avec_celle_du_jour(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $this->travelTo('2026-10-08 10:00:00');
        $this->emettreFactureParticulier(Client::factory()->create()); // 60 € TTC
        $this->travelTo('2026-10-09 10:00:00');

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSee('Journées du 08/10/2026 (oubliée) et 09/10/2026 · 60,00 € au total');
    }

    public function test_n_affiche_aucune_journee_tant_que_le_magasin_n_a_rien_vendu(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(VentesEnAttenteApercu::class)
            ->assertSee('Aucune journée à clôturer.');
    }

    public function test_un_vendeur_sans_la_permission_de_gerer_ne_voit_pas_le_widget(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(VentesEnAttenteApercu::canView());
    }
}
