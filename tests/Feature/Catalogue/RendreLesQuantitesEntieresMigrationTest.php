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
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
     * Une quantité à virgule déjà enregistrée n'est jamais arrondie en
     * silence (une facture émise est un document légal) : la migration
     * s'arrête avant toute modification et dit quelle ligne corriger ; une
     * fois la quantité corrigée, elle passe.
     */
    public function test_une_quantite_a_virgule_arrete_la_migration_sans_rien_modifier(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        $produit = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $ligne = $facture->lignes->firstOrFail();

        $this->revenirAvantLaMigration();
        DB::table('lignes_facture')->where('id', $ligne->id)->update(['quantite' => 1.5]);

        try {
            Artisan::call('migrate');
            $this->fail('La migration aurait dû refuser une quantité à virgule.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("lignes_facture.quantite (id {$ligne->id})", $exception->getMessage());
        }

        $this->assertNotSame('integer', Schema::getColumnType('lignes_facture', 'quantite'));
        $this->assertEquals(1.5, DB::table('lignes_facture')->where('id', $ligne->id)->value('quantite'));
        $this->assertFalse(DB::table('migrations')->where('migration', self::MIGRATION)->exists());

        DB::table('lignes_facture')->where('id', $ligne->id)->update(['quantite' => 2]);
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame('integer', Schema::getColumnType('lignes_facture', 'quantite'));
        $this->assertSame(2, (int) DB::table('lignes_facture')->where('id', $ligne->id)->value('quantite'));
    }

    private function revenirAvantLaMigration(): void
    {
        $etapes = DB::table('migrations')->where('migration', '>=', self::MIGRATION)->count();

        $this->artisan('migrate:rollback', ['--step' => $etapes])->assertSuccessful();
    }
}
