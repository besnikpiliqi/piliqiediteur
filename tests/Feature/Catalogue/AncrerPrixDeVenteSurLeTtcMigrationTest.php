<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DatabaseMigrations, pas RefreshDatabase : la migration doit tourner hors
 * de toute transaction englobante, exactement comme un vrai "php artisan
 * migrate" -- sous SQLite, reconstruire une table dans une transaction
 * déclencherait les actions des clés étrangères (produit_id vidé, stock
 * supprimé), ce qu'un vrai déploiement ne fait jamais.
 */
class AncrerPrixDeVenteSurLeTtcMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Données créées avant le passage au TTC (cas de la capture d'écran du
     * magasin) : un produit enregistré à 33,33 € HT et une ligne vendue à
     * 33,3333 € HT. Les deux doivent ressortir à 40,00 € TTC, leur HT
     * ramené à 33,33 €, sans perdre le lien produit ni le stock.
     */
    public function test_les_prix_existants_obtiennent_leur_ttc_avant_que_le_ht_soit_ramene_au_centime(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        $produit = Produit::factory()->create(['prix_vente_ht' => 33.33, 'taux_tva' => 20, 'suivi_stock' => true]);
        $facture = app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        app(EmettreFactureAction::class)->handle($facture);
        $ligne = $facture->lignes()->firstOrFail();
        $mouvementsAvant = DB::table('mouvements_stock')->count();

        $this->revenirAvantLePassageAuTtc();

        DB::table('produits')->whereKey($produit->id)->update(['prix_vente_ht' => 33.33]);
        DB::table('lignes_facture')->whereKey($ligne->id)->update(['prix_unitaire_ht' => 33.3333]);

        $this->artisan('migrate')->assertSuccessful();

        $produit = Produit::query()->findOrFail($produit->id);
        $this->assertSame('40.00', $produit->prix_vente_ttc);
        $this->assertSame('33.33', $produit->prix_vente_ht);

        $ligneEnBase = DB::table('lignes_facture')->find($ligne->id);
        $this->assertEquals(40.0, (float) $ligneEnBase->prix_unitaire_ttc);
        $this->assertEquals(33.33, (float) $ligneEnBase->prix_unitaire_ht);
        $this->assertSame($produit->id, (int) $ligneEnBase->produit_id);
        $this->assertSame($mouvementsAvant, DB::table('mouvements_stock')->count());
        $this->assertSame('80.00', LigneFacture::query()->findOrFail($ligne->id)->montant_ttc);
    }

    /**
     * 1,00 € HT à 5,5 % = 1,055 € TTC tombe pile sur le demi-centime : il
     * doit être arrondi à 1,06 €, comme le ferait CalculateurLigne -- un
     * ROUND() SQL sur un flottant (1,05499999...) donnerait 1,05 €.
     */
    public function test_un_ttc_tombant_sur_le_demi_centime_est_arrondi_comme_le_calculateur(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        $produit = Produit::factory()->create(['prix_vente_ht' => 1, 'taux_tva' => 5.5]);

        $this->revenirAvantLePassageAuTtc();
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame('1.06', Produit::query()->findOrFail($produit->id)->prix_vente_ttc);
    }

    /**
     * Les lignes d'achat existantes, saisies en HT, reçoivent leur PU TTC au
     * centime et gardent le HT comme prix de référence ; les lignes de vente
     * gardent le TTC.
     */
    public function test_les_lignes_existantes_recoivent_leur_prix_de_reference(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        $produit = Produit::factory()->create(['prix_achat_ht' => 8.33, 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);
        $facture = app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->revenirAvant('2026_10_13_100002_ajouter_prix_reference_aux_lignes');
        $this->artisan('migrate')->assertSuccessful();

        $ligneAchat = DB::table('lignes_achat')->where('facture_achat_id', $factureAchat->id)->first();
        $this->assertSame('ht', $ligneAchat->prix_reference);
        $this->assertEquals(10.0, (float) $ligneAchat->prix_unitaire_ttc);

        $this->assertSame('ttc', DB::table('lignes_facture')->where('facture_id', $facture->id)->value('prix_reference'));
    }

    private function revenirAvantLePassageAuTtc(): void
    {
        $this->revenirAvant('2026_10_13_100001_ancrer_prix_de_vente_sur_le_ttc');
    }

    /**
     * Défait cette migration et toutes celles qui la suivent, quel que soit
     * leur nombre -- un nombre d'étapes fixe défairait d'autres migrations
     * dès qu'une nouvelle s'ajoute.
     */
    private function revenirAvant(string $migration): void
    {
        $etapes = DB::table('migrations')->where('migration', '>=', $migration)->count();

        $this->artisan('migrate:rollback', ['--step' => $etapes])->assertSuccessful();
    }
}
