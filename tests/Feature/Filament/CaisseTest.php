<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CaisseTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_formulaire_propose_le_client_comptoir_par_defaut(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        Livewire::test(Caisse::class)
            ->assertFormSet(['client_id' => $clientComptoir->id]);
    }

    public function test_un_vendeur_encaisse_une_vente_comptoir_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 10]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame('48.00', $facture->total_ttc); // 2 x 20 HT, TVA 20%
        $this->assertSame('8.000', $produit->fresh()->stock_quantite);

        $this->assertDatabaseHas('paiements', [
            'client_id' => $clientComptoir->id,
            'mode_paiement' => 'especes',
            'montant' => 48,
        ]);
    }

    /**
     * Exigence explicite : chaque vendeur a sa propre caisse -- un vendeur
     * avec les permissions par défaut (donc sans aucune permission de
     * clôture de la journée du magasin, voir SeedMagasinRolesAction) doit
     * tout de même pouvoir encaisser une vente comptoir.
     */
    public function test_un_vendeur_avec_les_permissions_par_defaut_peut_encaisser_une_vente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertTrue(Caisse::canAccess());

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 30, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
            ])
            ->call('encaisser');

        $facture = Facture::query()->where('cree_par', $vendeur->id)->latest('id')->firstOrFail();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
    }

    public function test_encaisser_sans_aucune_ligne_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [],
            ])
            ->call('encaisser')
            ->assertHasFormErrors(['lignes']);

        $this->assertDatabaseCount('factures', 0);
    }

    /**
     * Bug rapporté : deux lignes à 8,33 € HT (TVA 20 %) affichent chacune
     * 10,00 € TTC, donc 20,00 € au total -- mais le "Total à payer" et la
     * "Monnaie à rendre" affichaient 19,99 € / 5,01 € au lieu de 20,00 € /
     * 5,00 €, car ces deux aperçus sommaient les lignes en flottant non
     * arrondi au lieu de passer par CalculateurLigne comme la facture
     * réellement enregistrée. Voir CalculateurLigneTest pour le test unitaire
     * du calcul lui-même.
     */
    public function test_le_total_a_payer_correspond_exactement_a_la_somme_des_lignes_affichees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $clientComptoir = Client::query()->where('nom', 'Client comptoir')->firstOrFail();
        $produit = Produit::factory()->create(['prix_vente_ht' => 8.33, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(Caisse::class)
            ->fillForm([
                'client_id' => $clientComptoir->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 8.33, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 8.33, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
                'mode_paiement' => 'especes',
                'montant_recu' => 25,
            ])
            ->assertSeeHtml('20,00 €')
            ->assertSeeHtml('5,00 €')
            ->call('encaisser');

        $facture = Facture::query()->where('client_id', $clientComptoir->id)->latest('id')->firstOrFail();
        $this->assertSame('20.00', $facture->total_ttc);
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_la_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(Caisse::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Client fidèle',
                'type_client' => 'particulier',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Client fidèle']);
    }
}
