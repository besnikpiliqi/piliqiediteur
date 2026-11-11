<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les totaux que contrôlent les règles de la norme européenne de la
 * facture électronique (EN 16931), que la plateforme agréée appliquera à
 * chaque facture et avoir transmis (décision de l'éditeur du 09/11/2026,
 * après un article sur le rejet BR-CO-14) : la TVA du document est
 * exactement la somme des TVA de ses taux (BR-CO-14, sans tolérance), son
 * TTC exactement son HT plus sa TVA (BR-CO-15), son HT la somme des HT de
 * ses lignes (BR-CO-10), et la TVA de chaque taux s'écarte d'au plus un
 * centime de la base du taux x taux, arrondie au centime (BR-CO-17 tolère
 * 1 €). Chaque total est la somme des montants enregistrés de ses lignes,
 * en centimes (voir CalculateurLigne::totaliser()), et le HT de chaque taux
 * d'une vente se calcule une fois sur son TTC (voir
 * CalculateurLigne::chiffrerUneVente()).
 *
 * Le panier mêle trois taux, des remises et des quantités : facture à un
 * professionnel, ticket à un particulier, devis, vente sans TVA française
 * (un export) et avoirs. Un avoir rend chaque ligne pour elle-même (voir
 * CalculateurLigne::retourDeLigne()) : la TVA de chacun de ses taux est la
 * somme des retours de ses lignes, à moins d'un centime de leur base x
 * taux pour une ligne par taux, comme ici ; sur plusieurs lignes d'un même
 * taux, ces écarts s'additionnent.
 */
class TotauxDeLaNormeEuropeenneTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{produit_id: int, quantite: int, remise_pourcentage: int}> */
    private array $panier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-10 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create());

        $article = fn (string $prixTtc, string $taux): int => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => $taux, 'suivi_stock' => false])->getKey();
        $this->panier = [
            ['produit_id' => $article('10.00', '20'), 'quantite' => 100, 'remise_pourcentage' => 0],
            ['produit_id' => $article('9.99', '20'), 'quantite' => 3, 'remise_pourcentage' => 0],
            ['produit_id' => $article('149.90', '20'), 'quantite' => 1, 'remise_pourcentage' => 5],
            ['produit_id' => $article('18.50', '10'), 'quantite' => 4, 'remise_pourcentage' => 0],
            ['produit_id' => $article('7.45', '10'), 'quantite' => 3, 'remise_pourcentage' => 12],
            ['produit_id' => $article('1.35', '5.5'), 'quantite' => 12, 'remise_pourcentage' => 0],
            ['produit_id' => $article('15.90', '5.5'), 'quantite' => 2, 'remise_pourcentage' => 0],
        ];
    }

    public function test_une_facture_a_un_professionnel_tient_les_totaux_de_la_norme(): void
    {
        $this->assertTotauxDeLaNorme($this->emettre(Client::factory()->professionnel()->create()), 3);
    }

    public function test_un_ticket_a_un_particulier_tient_les_totaux_de_la_norme(): void
    {
        $this->assertTotauxDeLaNorme($this->emettre(Client::factory()->create()), 3);
    }

    public function test_un_devis_tient_les_totaux_de_la_norme(): void
    {
        $this->assertTotauxDeLaNorme(app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), $this->panier), 3);
    }

    /**
     * Sans la TVA française, le client paie le HT de chaque taux du prix
     * de son catalogue (voir ContexteDeVente::retireLaTvaDuPrix()) : un
     * seul taux, 0 %, sans TVA.
     */
    public function test_une_vente_sans_tva_francaise_tient_les_totaux_de_la_norme(): void
    {
        $export = $this->emettre(Client::factory()->create(), [
            'nature_operation' => NatureOperation::ExportHorsUe,
            'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]);

        $this->assertSame(['0.00'], array_column($export->ventilation_tva, 'montant_tva'));
        $this->assertTotauxDeLaNorme($export, 1);
    }

    /**
     * Deux avoirs sur la facture d'un professionnel, une ligne par taux
     * chacun : l'un rend une partie de trois lignes (37 des 100 articles à
     * 10,00 €, 3 des 4 à 18,50 €, 5 des 12 à 1,35 €), l'autre toute la
     * ligne remisée à 149,90 €, toute celle remisée à 7,45 € et toute celle
     * à 15,90 €.
     */
    public function test_les_avoirs_tiennent_les_totaux_de_la_norme(): void
    {
        $client = Client::factory()->professionnel()->create();
        $lignes = $this->emettre($client)->lignes->sortBy('ordre')->values();

        $partiel = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $lignes[0]->getKey(), 'quantite' => 37],
            ['ligne_facture_id' => $lignes[3]->getKey(), 'quantite' => 3],
            ['ligne_facture_id' => $lignes[5]->getKey(), 'quantite' => 5],
        ]);
        $complet = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $lignes[2]->getKey(), 'quantite' => 1],
            ['ligne_facture_id' => $lignes[4]->getKey(), 'quantite' => 3],
            ['ligne_facture_id' => $lignes[6]->getKey(), 'quantite' => 2],
        ]);

        $this->assertTotauxDeLaNorme($partiel, 3);
        $this->assertTotauxDeLaNorme($complet, 3);
    }

    /**
     * Le panier, vendu à ce client avec ces attributs, émis.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function emettre(Client $client, array $attributs = []): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->panier, $attributs));
    }

    /**
     * BR-CO-10, BR-CO-14, BR-CO-15 et BR-CO-17 sur les montants enregistrés
     * du document, en centimes, et le nombre de ses taux.
     */
    private function assertTotauxDeLaNorme(Facture|Avoir|Devis $document, int $nombreDeTaux): void
    {
        $calculateur = app(CalculateurLigne::class);
        $centimes = fn (string $montant): int => $calculateur->enCentiemes($montant);
        $ventilation = $document->ventilation_tva;
        $nom = $document->numero ?? 'Le devis';

        $this->assertCount($nombreDeTaux, $ventilation);
        $this->assertSame($centimes($document->total_ht), $document->lignes->sum(fn (LigneFacture|LigneAvoir|LigneDevis $ligne): int => $centimes($ligne->montant_ht)), "{$nom} : BR-CO-10, HT différent de la somme de ses lignes.");
        $this->assertSame($centimes($document->total_tva), array_sum(array_map(fn (array $taux): int => $centimes($taux['montant_tva']), $ventilation)), "{$nom} : BR-CO-14, TVA différente de la somme de ses taux.");
        $this->assertSame($centimes($document->total_ht) + $centimes($document->total_tva), $centimes($document->total_ttc), "{$nom} : BR-CO-15, TTC différent de HT + TVA.");

        foreach ($ventilation as $taux) {
            $base = $centimes($taux['montant_ht']);
            $tvaDeLaBase = intdiv(2 * $base * $centimes($taux['taux_tva']) + 10000, 20000);

            $this->assertLessThanOrEqual(1, abs($centimes($taux['montant_tva']) - $tvaDeLaBase), "{$nom} : BR-CO-17, la TVA à {$taux['taux_tva']} % s'écarte de plus d'un centime de sa base x taux.");
        }
    }
}
