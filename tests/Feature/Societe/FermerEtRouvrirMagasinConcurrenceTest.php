<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Une vente à la caisse, ou une validation d'achat, et la fermeture du
 * magasin au même instant, chacune dans son processus, sur une vraie base
 * PostgreSQL : la seconde attend le verrou que tient la première (celui
 * du registre fiscal pour une vente, celui de la ligne du magasin pour un
 * achat). Une vente qui passe avant entre dans la dernière clôture de la
 * fermeture ; une vente ou un achat qui attendait la fermeture est refusé,
 * le magasin étant fermé.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FermerEtRouvrirMagasinConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiers(): array
    {
        return [
            'la vente d\'abord' => [true],
            'la fermeture d\'abord' => [false],
        ];
    }

    #[DataProvider('premiers')]
    public function test_une_vente_simultanee_a_la_fermeture_est_faite_avant_ou_refusee_apres(bool $laVenteDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $fermer = static function () use ($magasinId): void {
            self::fermer($magasinId);
        };
        $vendre = static function () use ($magasinId, $produitId): void {
            self::vendre($magasinId, $produitId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($laVenteDAbord ? $fermer : $vendre, $concurrente);

        $laVenteDAbord ? $vendre() : $fermer();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertSame(! $laVenteDAbord, $resultat->failed(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(StatutMagasin::Ferme, $magasin->fresh()->statut);

        if ($laVenteDAbord) {
            $this->assertSame([1, '12.00'], [ClotureCaisse::query()->sole()->nombre_ventes, ClotureCaisse::query()->sole()->total_ttc]);
            $this->assertNotNull(Facture::query()->sole()->cloture_caisse_id);
        } else {
            $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
            $this->assertSame(0, Facture::query()->count());
        }

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * La validation d'une facture d'achat attend la cession du magasin, qui
     * verrouille sa ligne : elle est ensuite refusée, le magasin étant
     * fermé, et rien n'entre dans le stock que la cession a sorti.
     */
    public function test_une_validation_d_achat_qui_attendait_la_cession_est_refusee(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $produit = Produit::factory()->create(['cmup_ht' => 2, 'suivi_stock' => true, 'seuil_alerte' => 0]);
        app(StockLedgerService::class)->ajuster($produit, 10, 'Inventaire d\'ouverture');
        $factureAchat = app(CreerFactureAchatAction::class)->handle($proprietaire, Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20],
        ]);
        $magasinId = $magasin->id;
        $factureAchatId = $factureAchat->id;
        $valider = static function () use ($magasinId, $factureAchatId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(ValiderFactureAchatAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), FactureAchat::query()->findOrFail($factureAchatId));
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($valider, $concurrente);

        app(FermerMagasinAction::class)->handle($proprietaire, $magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([0, StatutFactureAchat::Brouillon], [$produit->fresh()->stock_quantite, $factureAchat->fresh()->statut]);
    }

    /**
     * Le propriétaire ferme le magasin, temporairement.
     */
    private static function fermer(int $magasinId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(FermerMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, MotifFermeture::Temporaire, 'password');
    }

    /**
     * Le propriétaire vend un ticket de 12,00 € au comptoir.
     */
    private static function vendre(int $magasinId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(EnregistrerVenteCaisseAction::class)->handle(
            User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }
}
