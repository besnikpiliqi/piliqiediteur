<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FacturerLeStockAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La facture du stock vendu seul d'un magasin fermé et sa cession au même
 * instant, chacune dans son processus, sur une vraie base PostgreSQL (voir
 * VenteDuStockSeul) : la seconde attend le verrou du registre fiscal que
 * tient la première. L'émission qui passe avant sort le stock facturé, et la
 * cession ne sort ensuite que ce qui reste ; l'émission qui attendait la
 * cession est refusée, le magasin étant cédé, et reste un brouillon : un
 * magasin cédé ne vend plus rien, ce que le contexte de la facture du stock
 * ne change pas. La préparation du brouillon qui attendait la cession, que la
 * ligne du magasin verrouillée par la cession fait attendre, est refusée de
 * même.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
#[Group('concurrence')]
class FactureDuStockEtCessionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiers(): array
    {
        return [
            'l\'émission d\'abord' => [true],
            'la cession d\'abord' => [false],
        ];
    }

    /**
     * Un magasin fermé temporairement, avec 10 stylos et 3 carnets en
     * stock : le brouillon de la facture du stock des seuls stylos est déjà
     * prêt, les carnets sortiront à la cession.
     *
     * @return array{0: int, 1: int}
     */
    private function magasinFermeAvecSonBrouillon(): array
    {
        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'LYON1']);
        $this->completerIdentiteLegale($magasin);
        $stylo = Produit::factory()->create(['reference' => 'STYLO', 'prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'cmup_ht' => '5.00', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 10]);
        Produit::factory()->create(['reference' => 'CARNET', 'prix_vente_ttc' => '24.90', 'taux_tva' => 20, 'cmup_ht' => '12.00', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 3]);
        $client = Client::factory()->professionnel()->create();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, 'password');
        app(TenantContext::class)->set($magasin->fresh());
        $brouillon = app(FacturerLeStockAction::class)->handle(self::auteur(), $client, [$stylo->id]);

        return [$magasin->id, $brouillon->id];
    }

    private static function ceder(int $magasinId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(FermerMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');
    }

    private static function emettre(int $magasinId, int $factureId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(EmettreFactureAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->findOrFail($factureId));
    }

    #[DataProvider('premiers')]
    public function test_une_emission_simultanee_a_la_cession_est_faite_avant_ou_refusee_apres(bool $lEmissionDAbord): void
    {
        [$magasinId, $factureId] = $this->magasinFermeAvecSonBrouillon();
        $ceder = static function () use ($magasinId): void {
            self::ceder($magasinId);
        };
        $emettre = static function () use ($magasinId, $factureId): void {
            self::emettre($magasinId, $factureId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($lEmissionDAbord ? $ceder : $emettre, $concurrente);

        $lEmissionDAbord ? $emettre() : $ceder();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertSame(! $lEmissionDAbord, $resultat->failed(), $resultat->errorOutput());
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $this->assertTrue($magasin->aEteCede());
        $sorties = MouvementStock::query()->where('type', TypeMouvementStock::SortieCession->value)->orderBy('id')->get()->map(fn (MouvementStock $mouvement): int => $mouvement->quantite)->all();

        if ($lEmissionDAbord) {
            $this->assertSame([StatutFacture::Emise, [3]], [Facture::query()->findOrFail($factureId)->statut, $sorties]);
            $this->assertSame(10, MouvementStock::query()->where('type', TypeMouvementStock::Vente->value)->sole()->quantite);
        } else {
            $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
            $this->assertSame([StatutFacture::Brouillon, [10, 3]], [Facture::query()->findOrFail($factureId)->statut, $sorties]);
            $this->assertSame(0, MouvementStock::query()->where('type', TypeMouvementStock::Vente->value)->count());
        }

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_une_preparation_qui_attendait_la_cession_est_refusee(): void
    {
        [$magasinId, $factureId] = $this->magasinFermeAvecSonBrouillon();
        app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
        app(SupprimerFactureAction::class)->handle(self::auteur(), Facture::query()->findOrFail($factureId));
        $clientId = Client::query()->where('type_client', 'professionnel')->value('id');
        $stylo = Produit::query()->where('reference', 'STYLO')->value('id');
        $preparer = static function () use ($magasinId, $clientId, $stylo): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);

            app(FacturerLeStockAction::class)->handle(self::responsableDeTest($magasin), Client::query()->findOrFail($clientId), [$stylo]);
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('magasins', 'for update', $preparer, $concurrente);

        self::ceder($magasinId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString('Ce magasin a été cédé : son stock est sorti, il ne facture plus rien.', $resultat->errorOutput());
        app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
        $this->assertSame(0, Facture::query()->count());
    }
}
