<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\OrigineDesProduits;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un produit naît seulement de la validation d'une facture d'achat ou d'un
 * inventaire d'ouverture (point 18 de la série 8) : le modèle lui-même
 * refuse toute autre création, quel que soit le code qui la tente (voir
 * OrigineDesProduits).
 */
class OrigineDesProduitsTest extends TestCase
{
    use RefreshDatabase;

    private const REFUS = 'Un produit naît seulement de la validation d\'une facture d\'achat ou d\'un inventaire d\'ouverture.';

    /**
     * Hors de ces deux validations, une création est refusée, par le
     * constructeur de requêtes comme par un modèle enregistré à la main,
     * avant de prendre la moindre référence automatique : rien n'est
     * enregistré, et aucun contexte d'origine ne reste ouvert.
     */
    public function test_un_produit_cree_hors_d_un_achat_ou_d_un_inventaire_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->assertThrows(fn () => Produit::query()->create(['nom' => 'Écran', 'prix_vente_ttc' => 10, 'taux_tva' => 20]), LogicException::class, self::REFUS);
        $this->assertThrows(fn () => (new Produit(['reference' => 'ECR-1', 'nom' => 'Écran', 'prix_vente_ttc' => 10, 'taux_tva' => 20]))->save(), LogicException::class, self::REFUS);

        $this->assertSame(0, Produit::withTrashed()->count());
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $magasin->id)->where('type_document', TypeDocument::Produit->value)->exists());
        $this->assertNull(app(OrigineDesProduits::class)->ouverte());
    }

    /**
     * Valider une facture d'achat crée ses nouveaux produits ; le contexte
     * d'origine se referme ensuite, même quand une validation est refusée
     * en cours de création (référence prise entre-temps).
     */
    public function test_valider_un_achat_cree_ses_produits(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $fournisseur = Fournisseur::factory()->create();
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit' => ['nom' => 'Câble USB-C', 'reference' => 'CBL-USBC', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 2],
        ]);
        $refuse = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit' => ['nom' => 'Autre câble', 'reference' => 'cbl-usbc', 'prix_vente_ttc' => 12, 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 3],
        ]);
        $this->assertSame(0, Produit::query()->count());

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $brouillon);
        $this->assertThrows(fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $refuse), InvalidArgumentException::class, 'La référence « cbl-usbc » est déjà celle d\'un autre produit.');

        $cable = Produit::query()->sole();
        $this->assertSame(['CBL-USBC', $fournisseur->id, '2.0000', 3], [$cable->reference, $cable->fournisseur_id, $cable->cmup_ht, $cable->stock_quantite]);
        $this->assertNull(app(OrigineDesProduits::class)->ouverte());
        $this->assertThrows(fn () => Produit::query()->create(['nom' => 'Écran', 'prix_vente_ttc' => 10, 'taux_tva' => 20]), LogicException::class, self::REFUS);
    }

    /**
     * Valider un inventaire d'ouverture crée ses nouveaux produits, sans
     * fournisseur, puis referme le contexte d'origine.
     */
    public function test_valider_un_inventaire_d_ouverture_cree_ses_produits(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);
        $this->assertSame(0, Produit::query()->count());

        app(ValiderInventaireOuvertureAction::class)->handle(self::auteur(), $brouillon);

        $carnet = Produit::query()->sole();
        $this->assertSame(['CARNET-A5', null, '2.0000', 10], [$carnet->reference, $carnet->fournisseur_id, $carnet->cmup_ht, $carnet->stock_quantite]);
        $this->assertNull(app(OrigineDesProduits::class)->ouverte());
    }

    /**
     * La liste des produits n'a pas d'action de création : une requête
     * Livewire forgée qui la demande quand même, avec la fiche d'un
     * produit, ne monte rien et ne crée rien.
     */
    public function test_une_requete_livewire_forgee_vers_la_creation_d_un_produit_ne_cree_rien(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $this->forgerLAction(Livewire::test(ListProduits::class), 'create', [
            'reference' => 'ECR-1',
            'nom' => 'Écran',
            'cmup_ht' => '10',
            'prix_vente_ttc' => '12',
            'taux_tva' => '20',
        ]);

        $this->assertSame(0, Produit::withTrashed()->count());
    }
}
