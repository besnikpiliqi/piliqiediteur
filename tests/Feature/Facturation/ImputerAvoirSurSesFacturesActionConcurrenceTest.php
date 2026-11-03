<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Deux avoirs du même client émis au même instant, chacun dans son
 * processus, sur une vraie base PostgreSQL (B6) : le second attend le
 * verrou du registre fiscal que tient le premier, puis relit sous ce
 * verrou ce qui reste dû de la facture impayée que le premier a réglée.
 * Le même reste dû n'est jamais crédité deux fois.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ImputerAvoirSurSesFacturesActionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Une facture de 1 article au prix TTC donné, à 20 %, émise pour le
     * client.
     */
    private function facture(Client $client, float $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function payee(Facture $facture): Facture
    {
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $facture->total_ttc,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]]);

        return $facture;
    }

    private static function avoirTotal(int $clientId, int $ligneFactureId): Avoir
    {
        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), Client::query()->findOrFail($clientId), [['ligne_facture_id' => $ligneFactureId, 'quantite' => 1]], 'Retour');
    }

    /**
     * Deux factures de 100,00 € payées, puis une facture de 120,00 €
     * impayée ; les deux premières sont reprises au même instant par deux
     * avoirs. Le premier règle 100,00 € de la facture impayée, le second
     * les 20,00 € qui en restent dus, et garde 80,00 € de crédit.
     */
    public function test_deux_avoirs_simultanes_ne_reglent_jamais_deux_fois_le_meme_reste_du(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        app(TenantContext::class)->set($magasin);
        $dupont = Client::factory()->professionnel()->create(['nom' => 'Dupont']);
        $premiereReprise = $this->payee($this->facture($dupont, 100));
        $secondeReprise = $this->payee($this->facture($dupont, 100));
        $impayee = $this->facture($dupont, 120);
        $magasinId = $magasin->id;
        $dupontId = $dupont->id;
        $ligneDeLaSeconde = $secondeReprise->lignes()->sole()->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $dupontId, $ligneDeLaSeconde): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::avoirTotal($dupontId, $ligneDeLaSeconde);
        }, $concurrente);

        $premier = self::avoirTotal($dupontId, $premiereReprise->lignes()->sole()->id);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $second = Avoir::query()->whereKeyNot($premier->id)->sole();
        $this->assertSame(
            [[$premier->id, '100.00'], [$second->id, '20.00']],
            AvoirAllocation::query()->where('facture_id', $impayee->id)->orderBy('id')->get()->map(fn (AvoirAllocation $allocation): array => [$allocation->avoir_id, $allocation->montant_alloue])->all(),
        );
        $impayee->refresh();
        $this->assertSame([StatutFacture::Payee, 120.0, 0.0], [$impayee->statut, $impayee->montantCrediteParAvoir(), $impayee->montantRestant()]);
        $this->assertSame([0.0, 80.0, 80.0], [$premier->fresh()->montantNonAlloue(), $second->montantNonAlloue(), $dupont->soldeCrediteur()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
