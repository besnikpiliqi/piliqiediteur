<?php

namespace Tests\Feature\Stock;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Deux caisses qui vendent le même produit au même instant, chacune dans
 * son processus, sur une vraie base PostgreSQL (R1) : la seconde attend
 * le verrou du registre fiscal que tient la première, puis contrôle le
 * stock sous le verrou du produit, tel que la première l'a laissé. Sans
 * l'autorisation de vendre sans stock, la dernière unité ne se vend
 * qu'une fois ; avec, chacune passe dans la limite qu'elle a confirmée, et
 * le stock reste celui de ses mouvements.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class VenteSansStockConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_ventes_simultanees_de_la_derniere_unite_sans_autorisation_n_en_laissent_passer_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->membre($magasin, RoleMagasin::Vendeur);
        $second = $this->membre($magasin, RoleMagasin::Vendeur);
        $produit = $this->cableEnStock(1);
        $magasinId = $magasin->id;
        $secondId = $second->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $secondId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreUnCable(User::query()->findOrFail($secondId), $produitId, null);
        }, $concurrente);

        self::vendreUnCable($premier, $produitId, null);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('Stock insuffisant : Câble USB, reste 0, demandé 1.', $resultat->errorOutput());
        $this->assertSame([(int) $premier->id], Facture::query()->whereNotNull('numero')->pluck('cree_par')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([0, 1], [$produit->fresh()->stock_quantite, MouvementStock::query()->where('type', 'vente')->count()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_deux_ventes_simultanees_avec_autorisation_passent_toutes_deux_et_le_stock_reste_coherent(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = self::responsableDeTest($magasin);
        $responsable = $this->responsable($magasin);
        $produit = $this->cableEnStock(0);
        $magasinId = $magasin->id;
        $responsableId = $responsable->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $responsableId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreUnCable(User::query()->findOrFail($responsableId), $produitId, -2);
        }, $concurrente);

        self::vendreUnCable($premier, $produitId, -2);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame(2, Facture::query()->whereNotNull('numero')->count());
        $this->assertSame([-2, -2], [$produit->fresh()->stock_quantite, -MouvementStock::query()->where('produit_id', $produitId)->where('type', 'vente')->sum('quantite')]);
        $this->assertSame([[0], [-1]], array_map(fn ($entree): array => array_column($entree->nouvelles_valeurs['produits'], 'stock_avant'), $this->entreesDAudit($magasin->societe, ActionAuditee::VenteSansStock)));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un câble suivi en stock du magasin actif, amené à cette quantité par
     * un ajustement d'inventaire.
     */
    private function cableEnStock(int $stock): Produit
    {
        $produit = Produit::factory()->create(['nom' => 'Câble USB', 'reference' => 'CABLE-USB', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);

        if ($stock !== 0) {
            app(StockLedgerService::class)->ajuster($produit, $stock, 'Inventaire d\'ouverture');
        }

        return $produit->fresh();
    }

    /**
     * Un câble vendu au comptoir, avec la confirmation d'un stock accepté
     * après la vente, ou sans.
     */
    private static function vendreUnCable(User $vendeur, int $produitId, ?int $stockAccepte): Facture
    {
        return self::vendreAuComptant(
            $vendeur,
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            confirmation: $stockAccepte === null ? null : new ConfirmationVenteSansStock([$produitId => $stockAccepte]),
        );
    }
}
