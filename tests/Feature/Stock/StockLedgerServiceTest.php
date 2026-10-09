<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ProduitResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class StockLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_reception_dachat_augmente_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10);

        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    public function test_une_vente_diminue_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_un_retour_fournisseur_diminue_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::RetourFournisseur, 4);

        $this->assertSame(6, $produit->fresh()->stock_quantite);
    }

    public function test_un_retour_client_augmente_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 5]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::RetourClient, 2);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_chaque_mouvement_est_trace_dans_le_ledger(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 5);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2);

        $this->assertSame(2, $produit->mouvementsStock()->count());
    }

    public function test_un_ajustement_calcule_le_delta_depuis_la_quantite_cible(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);

        app(StockLedgerService::class)->ajuster($produit, 7, 'Inventaire physique');

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_une_quantite_negative_ou_nulle_est_refusee(): void
    {
        $produit = Produit::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 0);
    }

    /**
     * Le passage au seuil d'alerte prévient ceux qui gèrent le stock du
     * magasin (permission d'ajuster le stock : le propriétaire par défaut),
     * pas les vendeurs, ni le propriétaire d'un autre magasin.
     */
    public function test_le_passage_au_seuil_d_alerte_previent_ceux_qui_gerent_le_stock(): void
    {
        $proprietaireAutreMagasin = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], $proprietaireAutreMagasin);
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr', 'password' => 'secret123'], RoleMagasin::Vendeur);
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'reference' => 'CAB-USBC', 'stock_quantite' => 7, 'seuil_alerte' => 5]);

        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2);

        $notification = $proprietaire->notifications()->sole();
        $this->assertSame(StockSousLeSeuilDAlerte::class, $notification->type);
        $this->assertSame('Ma Boutique : Stock bas : Câble USB-C', $notification->data['title']);
        $this->assertSame('Plus que 5 article(s) en stock (référence CAB-USBC, seuil d\'alerte : 5).', $notification->data['body']);
        $this->assertSame(ProduitResource::getUrl('edit', ['record' => $produit], panel: 'app', tenant: $magasin), $notification->data['actions'][0]['url']);
        $this->assertSame($magasin->id, $notification->data['magasin_id']);
        $this->assertSame(0, $vendeur->notifications()->count());
        $this->assertSame(0, $proprietaireAutreMagasin->notifications()->count());
    }

    /**
     * Une alerte au passage du seuil, pas une à chaque vente tant que le
     * stock reste bas ; une nouvelle après un réapprovisionnement.
     */
    public function test_un_stock_deja_bas_ne_previent_pas_a_chaque_mouvement(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['stock_quantite' => 5, 'seuil_alerte' => 5]);
        $stock = app(StockLedgerService::class);

        $stock->enregistrer($produit, TypeMouvementStock::Vente, 1);
        $stock->ajuster($produit, 2, 'Casse');
        $this->assertSame(0, $proprietaire->notifications()->count());

        $stock->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10);
        $stock->enregistrer($produit, TypeMouvementStock::Vente, 12);

        $this->assertSame('Plus aucun article en stock (référence '.$produit->reference.', seuil d\'alerte : 5).', $proprietaire->notifications()->sole()->data['body']);
    }

    /**
     * Une facture à deux lignes du même produit fait deux mouvements dans
     * la même transaction : l'alerte dit le stock qui reste vraiment, pas
     * celui du premier mouvement.
     */
    public function test_l_alerte_donne_le_stock_restant_apres_toute_la_transaction(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['reference' => 'CAB-USBC', 'stock_quantite' => 7, 'seuil_alerte' => 5]);

        DB::transaction(function () use ($produit) {
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2);
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 4);
        });

        $this->assertSame('Plus que 1 article(s) en stock (référence CAB-USBC, seuil d\'alerte : 5).', $proprietaire->notifications()->sole()->data['body']);
    }

    /**
     * Repassé au-dessus du seuil avant la fin de la transaction (une
     * réception corrigée, par exemple) : il n'y a plus rien à signaler.
     */
    public function test_un_stock_remonte_au_dessus_du_seuil_dans_la_transaction_ne_previent_personne(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['stock_quantite' => 6, 'seuil_alerte' => 5]);

        DB::transaction(function () use ($produit) {
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3);
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10);
        });

        $this->assertSame(13, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $proprietaire->notifications()->count());
    }

    public function test_un_mouvement_annule_par_sa_transaction_ne_previent_personne(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['stock_quantite' => 6, 'seuil_alerte' => 5]);

        $this->assertThrows(fn () => DB::transaction(function () use ($produit) {
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3);

            throw new RuntimeException('Vente abandonnée');
        }), RuntimeException::class);

        $this->assertSame(6, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $proprietaire->notifications()->count());
    }

    public function test_un_produit_sans_suivi_de_stock_refuse_tout_mouvement(): void
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->expectException(\LogicException::class);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 1);
    }
}
