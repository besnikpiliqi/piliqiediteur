<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClotureCaisseResourceTest extends TestCase
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

    public function test_le_proprietaire_cloture_une_journee_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(); // particulier par défaut

        // La clôture ne porte jamais sur une date choisie : uniquement sur
        // "hier" au moment de l'action (voir
        // ListClotureCaisses::getHeaderActions()). La facture doit donc
        // être émise hier pour être agrégée.
        $this->travelTo(now()->subDay());
        $facture = $this->emettreFactureParticulier($client);
        $this->travelBack();

        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->firstOrFail();
        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame($cloture->id, $facture->fresh()->cloture_caisse_id);
    }

    /**
     * Le gérant doit voir avant de confirmer exactement ce qui sera
     * regroupé dans la clôture, pas seulement un avertissement générique
     * (voir ListClotureCaisses::getHeaderActions()).
     */
    public function test_la_modale_de_confirmation_annonce_le_nombre_et_le_total_des_ventes(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(); // particulier par défaut

        $this->travelTo(now()->subDay());
        $this->emettreFactureParticulier($client); // 50 € HT, TVA 20 % => 60 € TTC
        $this->travelBack();

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('1 vente(s) au comptoir pour un total de 60,00 €');
    }

    public function test_la_modale_de_confirmation_previent_quand_aucune_vente_nest_en_attente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee("Aucune vente au comptoir n'a été enregistrée ce jour-là");
    }

    /**
     * Exigence explicite : aucun choix de date -- la clôture manuelle
     * cible toujours exactement la veille, le seul jour "terminé" en
     * attente, et redevient indisponible dès que cette journée est
     * clôturée (ici manuellement, mais ce serait pareil si la commande
     * automatique l'avait fait à minuit, voir ClotureAutomatiqueCaisse).
     */
    public function test_laction_cloturer_disparait_une_fois_la_veille_deja_cloturee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListClotureCaisses::class)
            ->assertActionVisible('cloturer')
            ->callAction('cloturer');

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    public function test_laction_cloturer_nest_pas_visible_pour_un_vendeur(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([PermissionRegistry::CLOTURE_CAISSE_VIEW]);

        $vendeur = User::factory()->create();
        $vendeur->assignRole($roleVendeur);
        $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    public function test_la_page_de_detail_affiche_les_factures_agregees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        $this->travelTo(now()->subDay());
        $facture = $this->emettreFactureParticulier($client);
        $this->travelBack();

        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->firstOrFail();

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($facture->numero);
    }

    /**
     * Un vendeur qui clôture sa propre caisse (ex: fin de service d'un
     * caissier) ne doit voir que ses propres clôtures, pas celles
     * déclenchées par un autre vendeur -- même confidentialité que les
     * factures.
     */
    public function test_un_vendeur_ne_voit_que_les_clotures_quil_a_lui_meme_declenchees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_VIEW, 'web');
        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_GERER, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);

        $vendeurA = User::factory()->create();
        $vendeurB = User::factory()->create();
        $vendeurA->assignRole($roleVendeur);
        $vendeurB->assignRole($roleVendeur);
        foreach ([$vendeurA, $vendeurB] as $vendeur) {
            $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);
        }

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        // Un jour réel s'écoule entre les deux clôtures : l'action ne
        // porte jamais sur une date choisie, seulement sur "hier" au
        // moment où elle est déclenchée (voir
        // ListClotureCaisses::getHeaderActions()).
        $this->travelTo(now()->addDay());

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $this->travelBack();

        $clotureA = ClotureCaisse::where('cree_par', $vendeurA->id)->firstOrFail();
        $clotureB = ClotureCaisse::where('cree_par', $vendeurB->id)->firstOrFail();

        Livewire::test(ListClotureCaisses::class)
            ->assertCanSeeTableRecords([$clotureB])
            ->assertCanNotSeeTableRecords([$clotureA]);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaisses::class)
            ->assertCanSeeTableRecords([$clotureA, $clotureB]);
    }
}
