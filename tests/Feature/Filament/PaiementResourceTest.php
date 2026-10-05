<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\PaiementResource\Pages\CreatePaiement;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaiementResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée une facture émise (total TTC 120, via un produit à 100 € HT et
     * 20 % de TVA) depuis le panneau, pour servir de base aux tests
     * d'encaissement.
     */
    private function emettreFactureDepuisLePanneau(Client $client, float $prixUnitaireHt = 100): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => $prixUnitaireHt, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    public function test_enregistrer_un_paiement_complet_depuis_le_panneau_passe_la_facture_a_payee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Paiement::query()->count());
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
    }

    public function test_repartir_un_paiement_sur_plusieurs_factures_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $factureA = $this->emettreFactureDepuisLePanneau($client, 100); // total 120
        $factureB = $this->emettreFactureDepuisLePanneau($client, 50 / 1.2); // total 50

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 170,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $factureA->id, 'montant' => 120],
                    ['facture_id' => $factureB->id, 'montant' => 50],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(StatutFacture::Payee, $factureA->fresh()->statut);
        $this->assertSame(StatutFacture::Payee, $factureB->fresh()->statut);
    }

    public function test_laction_rapide_encaisser_depuis_la_liste_des_factures(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::CarteBancaire->value,
                'montant' => 120,
                'recu_le' => now()->toDateString(),
            ]);

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame(1, Paiement::query()->count());
    }

    public function test_laction_rapide_encaisser_accepte_un_montant_partiel(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 50,
                'recu_le' => now()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatutFacture::PartiellementPayee, $facture->fresh()->statut);
        $this->assertSame(70.0, $facture->fresh()->montantRestant());
    }

    /**
     * Le client a explicitement refusé qu'un trop-perçu devienne un crédit
     * (contrairement à un avoir) : la saisie d'un montant supérieur au
     * restant dû doit être bloquée proprement par la validation du
     * formulaire, sans créer de paiement ni faire planter la page.
     */
    public function test_laction_rapide_encaisser_bloque_un_montant_superieur_au_restant_du(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 150,
                'recu_le' => now()->toDateString(),
            ])
            ->assertHasTableActionErrors(['montant']);

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * Une date de réception dans le passé n'a pas de sens pour un
     * encaissement qui vient d'être saisi : seule une date à partir
     * d'aujourd'hui est acceptée, comme pour l'échéance d'une facture.
     */
    public function test_laction_rapide_encaisser_refuse_une_date_de_reception_dans_le_passe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(ListFactures::class)
            ->callTableAction('encaisser', $facture, data: [
                'mode_paiement' => ModePaiement::Especes->value,
                'montant' => 120,
                'recu_le' => now()->subDay()->toDateString(),
            ])
            ->assertHasTableActionErrors(['recu_le']);

        $this->assertSame(0, Paiement::query()->count());
    }

    /**
     * Même garde-fou que l'action rapide "Encaisser" (voir
     * test_laction_rapide_encaisser_bloque_un_montant_superieur_au_restant_du),
     * appliqué cette fois au formulaire multi-factures de PaiementResource :
     * une allocation ne doit jamais pouvoir dépasser le restant dû de la
     * facture choisie.
     */
    public function test_impossible_dallouer_plus_que_le_restant_du_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 150,
                'recu_le' => now()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 150],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['allocations.0.montant']);

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * Même garde-fou que l'action rapide "Encaisser" (voir
     * test_laction_rapide_encaisser_refuse_une_date_de_reception_dans_le_passe),
     * appliqué cette fois au formulaire multi-factures de PaiementResource.
     */
    public function test_impossible_denregistrer_un_paiement_avec_une_date_de_reception_dans_le_passe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client); // total TTC 120

        Livewire::test(CreatePaiement::class)
            ->fillForm([
                'client_id' => $client->id,
                'mode_paiement' => ModePaiement::Virement->value,
                'montant' => 120,
                'recu_le' => now()->subDay()->toDateString(),
                'allocations' => [
                    ['facture_id' => $facture->id, 'montant' => 120],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['recu_le']);

        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_paiements(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        Permission::findOrCreate(PermissionRegistry::FACTURE_CREATE, 'web');
        Permission::findOrCreate(PermissionRegistry::PAIEMENT_VIEW, 'web');
        Permission::findOrCreate(PermissionRegistry::PAIEMENT_CREER, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([
            PermissionRegistry::FACTURE_VIEW,
            PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::PAIEMENT_VIEW,
            PermissionRegistry::PAIEMENT_CREER,
        ]);

        $vendeurA = User::factory()->create();
        $vendeurB = User::factory()->create();
        $vendeurA->assignRole($roleVendeur);
        $vendeurB->assignRole($roleVendeur);
        foreach ([$vendeurA, $vendeurB] as $vendeur) {
            $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);
        }

        $client = Client::factory()->create();

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        $paiementA = Paiement::create([
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 10,
            'recu_le' => now()->toDateString(),
        ]);

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        $paiementB = Paiement::create([
            'client_id' => $client->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 20,
            'recu_le' => now()->toDateString(),
        ]);

        Livewire::test(ListPaiements::class)
            ->assertCanSeeTableRecords([$paiementB])
            ->assertCanNotSeeTableRecords([$paiementA]);
    }
}
