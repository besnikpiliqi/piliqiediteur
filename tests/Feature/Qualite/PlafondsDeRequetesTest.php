<?php

namespace Tests\Feature\Qualite;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le nombre de requêtes des pages les plus chargées du magasin (décision
 * de l'éditeur du 01/11/2026, point 5) : le tableau de bord, les listes
 * des ventes, des paiements, des avoirs, des clients, des produits, des
 * factures d'achat et des clôtures, la caisse, et le PDF d'une facture.
 * Chacune lit la base le même nombre de fois avec deux lignes ou huit (une
 * requête par ligne serait une requête « N+1 »), sous un plafond qui ne
 * dépend pas du nombre de lignes.
 */
class PlafondsDeRequetesTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private User $responsable;

    /** Le jour de la dernière vente, compté depuis le 1er octobre 2026. */
    private int $jour = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $this->responsable = self::responsableDeTest($this->magasin);
        $this->actingAs($this->responsable);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function pages(): array
    {
        return [
            'tableau de bord' => ['', 120],
            'ventes' => ['/factures', 90],
            'paiements' => ['/paiements', 80],
            'avoirs' => ['/avoirs', 80],
            'clients' => ['/clients', 80],
            'produits' => ['/produits', 80],
            'factures d’achat' => ['/facture-achats', 80],
            'clôtures' => ['/cloture-caisses', 80],
            'caisse' => ['/caisse', 90],
        ];
    }

    #[DataProvider('pages')]
    public function test_une_page_principale_lit_la_base_autant_de_fois_avec_deux_lignes_ou_huit(string $page, int $plafond): void
    {
        $requetes = [];

        foreach ([2, 8] as $lignes) {
            $this->remplir($lignes);
            $adresse = '/app/'.$this->magasin->uuid.$page;
            $this->get($adresse)->assertOk();
            $requetes[$lignes] = $this->requetes(fn () => $this->get($adresse)->assertOk());
        }

        $this->assertSame($requetes[2], $requetes[8], "La page {$page} lit la base une fois de plus par ligne.");
        $this->assertLessThanOrEqual($plafond, $requetes[8]);
    }

    public function test_le_pdf_d_une_facture_lit_la_base_autant_de_fois_avec_deux_lignes_ou_vingt(): void
    {
        $requetes = [];

        foreach ([2, 20] as $lignes) {
            $produits = Produit::factory()->count($lignes)->create(['prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 100]);
            $vente = self::vendreAuComptant($this->responsable, Client::factory()->create(), $produits->map(fn (Produit $produit): array => ['produit_id' => $produit->getKey(), 'quantite' => 2])->all());
            $facture = Facture::query()->findOrFail($vente->getKey());
            $requetes[$lignes] = $this->requetes(fn () => app(FacturePdfGenerator::class)->generer($facture));
        }

        $this->assertSame($requetes[2], $requetes[20], 'Le PDF lit la base une fois de plus par ligne.');
        $this->assertLessThanOrEqual(40, $requetes[20]);
    }

    /**
     * Jusqu'à $lignes ventes, paiements, avoirs, clients, produits, factures
     * d'achat et clôtures dans le magasin, une par jour.
     */
    private function remplir(int $lignes): void
    {
        $fournisseur = Fournisseur::query()->first() ?? Fournisseur::factory()->create();

        while (Facture::query()->count() < $lignes) {
            $this->travelTo(self::aParis(Calendrier::ajouterJours('2026-10-01', ++$this->jour).' 10:00:00'));
            $produit = Produit::factory()->create(['prix_vente_ttc' => '12.50', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 10]);
            $client = Client::factory()->create();
            $vente = self::vendreAuComptant($this->responsable, $client, [['produit_id' => $produit->getKey(), 'quantite' => 2]], ModePaiement::CarteBancaire);
            app(EnregistrerAvoirAction::class)->handle($this->responsable, $client, [['ligne_facture_id' => $vente->lignes()->value('id'), 'quantite' => 1]], 'Retour');
            app(ValiderFactureAchatAction::class)->handle($this->responsable, app(CreerFactureAchatAction::class)->handle($this->responsable, $fournisseur, [
                ['produit_id' => $produit->getKey(), 'quantite' => 3, 'prix_unitaire_ht' => '5.00', 'taux_tva' => 20],
            ], ['date_facture' => now()->format('Y-m-d')]));
            app(CloturerCaisseAction::class)->handle($this->responsable, $this->magasin, $this->magasin->calendrier()->aujourdhui());
        }
    }

    private function requetes(Closure $geste): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $geste();
        $nombre = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $nombre;
    }
}
