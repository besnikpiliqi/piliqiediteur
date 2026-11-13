<?php

namespace Tests\Feature\Qualite;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\LesCaisses;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Closure;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le nombre de requêtes des pages les plus chargées (décision de l'éditeur
 * du 01/11/2026, point 5) : dans le magasin, le tableau de bord, les
 * listes des ventes, des paiements, des avoirs, des clients, des produits,
 * des factures d'achat et des clôtures, la caisse, et le PDF d'une
 * facture ; dans l'espace de la société, le tableau de bord et « Les
 * caisses » ; dans celui d'un cabinet, le tableau de bord de ses clients.
 * Chacune lit la base le même nombre de fois avec peu ou beaucoup de
 * lignes, de magasins ou de clients (une requête par ligne serait une
 * requête « N+1 »), sous un plafond qui n'en dépend pas.
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

    /**
     * La liste des ventes dit, sur chaque vente à l'export, qu'elle attend
     * sa preuve de douane (colonne « À faire ») : ce drapeau se lit dans la
     * requête de la liste, jamais facture par facture, avec deux ventes à
     * l'export comme avec huit. Chacune est réglée par virement à son
     * émission, comme les ventes des autres pages.
     */
    public function test_la_liste_des_ventes_a_l_export_lit_la_base_autant_de_fois_avec_deux_lignes_ou_huit(): void
    {
        $requetes = [];
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => '50.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $adresse = '/app/'.$this->magasin->uuid.'/factures';

        foreach ([2, 8] as $lignes) {
            while (Facture::query()->count() < $lignes) {
                $brouillon = app(CreerFactureAction::class)->handle($this->responsable, $client, [['produit_id' => $produit->getKey(), 'quantite' => 1]], [
                    'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
                ]);
                $virement = new PartDeReglement(ModePaiement::Virement, app(CalculateurLigne::class)->enCentiemes($brouillon->total_ttc), 'VIR-'.$brouillon->getKey());
                app(EmettreFactureAction::class)->handle($this->responsable, $brouillon, ReglementComptant::horsDeLaCaisse([$virement]));
            }

            $this->get($adresse)->assertOk()->assertSee('Preuve à fournir');
            $requetes[$lignes] = $this->requetes(fn () => $this->get($adresse)->assertOk());
        }

        $this->assertSame($requetes[2], $requetes[8], 'La liste des ventes lit la base une fois de plus par vente à l\'export.');
        $this->assertLessThanOrEqual(90, $requetes[8]);
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
     * @return array<string, array{0: class-string<Page>, 1: int}>
     */
    public static function pagesDeLaSociete(): array
    {
        return [
            'tableau de bord de la société' => [TableauDeBord::class, 60],
            'les caisses de la société' => [LesCaisses::class, 60],
        ];
    }

    /**
     * Dans l'espace de la société, le tableau de bord (chaque magasin, avec
     * ses factures à encaisser) et « Les caisses » (les relevés et les
     * caisses encore ouvertes de chaque magasin) lisent la base autant de
     * fois avec un magasin ou trois, pour le propriétaire comme pour un
     * administrateur de tous les magasins.
     *
     * @param  class-string<Page>  $page
     */
    #[DataProvider('pagesDeLaSociete')]
    public function test_une_page_de_la_societe_lit_la_base_autant_de_fois_avec_un_magasin_ou_trois(string $page, int $plafond): void
    {
        $societe = $this->magasin->societe;
        $comptes = ['propriétaire' => $this->proprietaireDe($this->magasin), 'administrateur' => $this->administrateur($this->magasin)];
        $adresse = $page::getUrl(panel: 'societe', tenant: $societe);
        $magasins = [$this->magasin];
        $requetes = [];

        foreach ([1, 3] as $nombre) {
            while (count($magasins) < $nombre) {
                $magasins[] = $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Boutique '.count($magasins), 'code' => 'BOUT'.count($magasins)]);
            }

            foreach (array_slice($magasins, $nombre === 1 ? 0 : 1) as $magasin) {
                $this->remplirLaCaisseEtLesFactures($magasin);
            }

            foreach ($comptes as $role => $compte) {
                app(TenantContext::class)->clear();
                $this->flushSession();
                $this->actingAs($compte);
                $this->get($adresse)->assertOk();
                $requetes[$role][$nombre] = $this->requetes(fn () => $this->get($adresse)->assertOk()->assertSee($magasins[$nombre - 1]->nom));
            }
        }

        foreach ($requetes as $role => $parNombre) {
            $this->assertSame($parNombre[1], $parNombre[3], "La page {$page} lit la base une fois de plus par magasin pour le {$role}.");
            $this->assertLessThanOrEqual($plafond, $parNombre[3], $role);
        }
    }

    /**
     * Le tableau de bord d'un cabinet d'expertise comptable (chaque client
     * lié, avec les factures à encaisser de ses magasins) lit la base autant
     * de fois pour son propriétaire avec un client ou trois.
     */
    public function test_le_tableau_de_bord_du_cabinet_lit_la_base_autant_de_fois_avec_un_client_ou_trois(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $adresse = TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet);
        $clients = [$this->magasin];
        $this->lierLeCabinet($cabinet, $this->magasin);
        $this->remplirLaCaisseEtLesFactures($this->magasin);
        $requetes = [];

        foreach ([1, 3] as $nombre) {
            while (count($clients) < $nombre) {
                $client = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Cliente '.count($clients), 'raison_sociale' => 'CLIENTE '.count($clients)]));
                $this->remplirLaCaisseEtLesFactures($client);
                $this->lierLeCabinet($cabinet, $client);
                $clients[] = $client;
            }

            app(TenantContext::class)->clear();
            $this->actingAs($proprietaire);
            $this->get($adresse)->assertOk();
            $requetes[$nombre] = $this->requetes(fn () => $this->get($adresse)->assertOk()->assertSee($clients[$nombre - 1]->societe()->firstOrFail()->raison_sociale));
        }

        $this->assertSame($requetes[1], $requetes[3], 'Le tableau de bord du cabinet lit la base une fois de plus par client.');
        $this->assertLessThanOrEqual(60, $requetes[3]);
    }

    /**
     * Dans ce magasin, une facture à encaisser, une vente au comptant dont
     * la caisse est fermée (un relevé), puis une autre qui la laisse
     * ouverte.
     */
    private function remplirLaCaisseEtLesFactures(Magasin $magasin): void
    {
        app(TenantContext::class)->runAs($magasin, function () use ($magasin): void {
            $responsable = self::responsableDeTest($magasin);
            $produit = Produit::factory()->create(['prix_vente_ttc' => '12.50', 'taux_tva' => 20, 'suivi_stock' => false]);
            $client = Client::factory()->professionnel()->create();
            app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => $produit->getKey(), 'quantite' => 1]]));
            self::vendreAuComptant($responsable, $client, [['produit_id' => $produit->getKey(), 'quantite' => 1]]);
            app(FermerSaCaisseAction::class)->handle($responsable, $responsable);
            self::vendreAuComptant($responsable, $client, [['produit_id' => $produit->getKey(), 'quantite' => 2]]);
        });
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
