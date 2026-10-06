<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DatabaseMigrations, pas RefreshDatabase : la migration doit tourner hors
 * de toute transaction, comme un vrai "php artisan migrate" (voir
 * AncrerPrixDeVenteSurLeTtcMigrationTest).
 */
class RendreLesQuantitesEntieresMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_10_13_100003_rendre_les_quantites_entieres';

    /**
     * Passer les colonnes en entiers reconstruit les tables sous SQLite :
     * rien ne doit se perdre au passage -- ni les quantités, ni le stock et
     * son historique, ni les liens entre une ligne d'avoir et la ligne
     * facturée qu'elle crédite.
     */
    public function test_les_quantites_deviennent_des_entiers_sans_rien_perdre(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => true, 'seuil_alerte' => 3]);
        $fournisseur = Fournisseur::factory()->create();
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 10, 'prix_unitaire_ht' => 20],
        ]));
        $ligneAchat = $factureAchat->lignes->firstOrFail();
        $avoirFournisseur = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 1],
        ]);

        $client = Client::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]));
        $ligneFacture = $facture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);

        $mouvementsAvant = DB::table('mouvements_stock')->orderBy('id')->pluck('quantite')->map(fn ($quantite) => (int) $quantite)->all();

        $this->revenirAvantLaMigration();
        $this->assertNotSame('integer', Schema::getColumnType('lignes_facture', 'quantite'));

        $this->artisan('migrate')->assertSuccessful();

        foreach (['lignes_devis', 'lignes_facture', 'lignes_avoir', 'lignes_achat', 'lignes_avoir_fournisseur', 'mouvements_stock'] as $table) {
            $this->assertSame('integer', Schema::getColumnType($table, 'quantite'), $table);
        }
        $this->assertSame('integer', Schema::getColumnType('produits', 'stock_quantite'));
        $this->assertSame('integer', Schema::getColumnType('produits', 'seuil_alerte'));

        $produit = Produit::query()->findOrFail($produit->id);
        $this->assertSame(8, $produit->stock_quantite);
        $this->assertSame(3, $produit->seuil_alerte);
        $this->assertSame($mouvementsAvant, DB::table('mouvements_stock')->orderBy('id')->pluck('quantite')->map(fn ($quantite) => (int) $quantite)->all());

        $this->assertSame(2, (int) DB::table('lignes_facture')->where('id', $ligneFacture->id)->value('quantite'));
        $this->assertSame($produit->id, (int) DB::table('lignes_facture')->where('id', $ligneFacture->id)->value('produit_id'));
        $this->assertSame($ligneFacture->id, (int) DB::table('lignes_avoir')->where('avoir_id', $avoir->id)->value('ligne_facture_id'));
        $this->assertSame($ligneAchat->id, (int) DB::table('lignes_avoir_fournisseur')->where('avoir_fournisseur_id', $avoirFournisseur->id)->value('ligne_achat_id'));

        $colonnesProduit = collect(Schema::getColumns('produits'))->keyBy('name');
        $this->assertSame('0', trim((string) $colonnesProduit['stock_quantite']['default'], "'"));
        $this->assertSame('0', trim((string) $colonnesProduit['seuil_alerte']['default'], "'"));
    }

    /**
     * Avant ce changement, une quantité à virgule était permise : une
     * facture déjà émise de 1,5 article à 40 € TTC (60,00 €) ne peut plus
     * être corrigée légalement. La migration ne bloque donc jamais : la
     * colonne qui la contient reste en décimal, et la quantité se relit
     * exactement -- jamais tronquée en 1, ni à l'écran ni dans un avoir ou
     * dans le stock.
     */
    public function test_une_ancienne_quantite_a_virgule_reste_exacte_sans_bloquer_la_migration(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => true]);
        $client = Client::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]));
        $ligne = $facture->lignes->firstOrFail();

        $this->revenirAvantLaMigration();
        DB::table('lignes_facture')->where('id', $ligne->id)->update(['quantite' => 1.5, 'montant_ht' => 50, 'montant_tva' => 10, 'montant_ttc' => 60]);
        DB::table('produits')->where('id', $produit->id)->update(['stock_quantite' => 8.5]);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertNotSame('integer', Schema::getColumnType('lignes_facture', 'quantite'));
        $this->assertNotSame('integer', Schema::getColumnType('produits', 'stock_quantite'));
        $this->assertSame('integer', Schema::getColumnType('lignes_devis', 'quantite'));
        $this->assertSame('integer', Schema::getColumnType('produits', 'seuil_alerte'));

        $ligne = LigneFacture::query()->findOrFail($ligne->id);
        $this->assertSame(1.5, $ligne->quantite);
        $this->assertSame(1.5, $ligne->quantiteCreditableRestante());

        $pdf = view('factures.pdf', ['facture' => $facture->fresh()->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir')])->render();
        $this->assertStringContainsString('<td class="montant">1,5</td>', $pdf);

        // Un article rendu sur 1,5 : PU de la ligne x 1 (33,33 € HT /
        // 40,00 € TTC), pas le solde de la ligne entière ; 0,5 reste dû.
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $ligne->id, 'quantite' => 1],
        ]));
        $this->assertSame(['33.33', '6.67', '40.00'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertSame(0.5, $ligne->fresh()->quantiteCreditableRestante());
        $this->assertSame(9.5, $produit->fresh()->stock_quantite);
    }

    private function revenirAvantLaMigration(): void
    {
        $etapes = DB::table('migrations')->where('migration', '>=', self::MIGRATION)->count();

        $this->artisan('migrate:rollback', ['--step' => $etapes])->assertSuccessful();
    }
}
