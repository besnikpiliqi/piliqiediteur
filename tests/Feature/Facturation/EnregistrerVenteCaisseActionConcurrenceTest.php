<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Deux caisses qui encaissent au même instant, chacune dans son processus,
 * sur une vraie base PostgreSQL : la seconde attend le verrou du registre
 * fiscal que tient la première, puis relit sous ce verrou les crédits
 * qu'elle impute. Le même bon d'avoir (K6), comme le même crédit d'avoir
 * d'un client nommé (voir AppliquerAvoirsDisponiblesAction), ne s'impute
 * qu'une fois.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class EnregistrerVenteCaisseActionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private static function vendreVingtEuros(int $clientId, int $produitId, ?string $bonDAvoir = null): Facture
    {
        return app(EnregistrerVenteCaisseAction::class)->handle(
            Client::query()->findOrFail($clientId),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            bonDAvoir: $bonDAvoir,
        );
    }

    public function test_deux_caisses_qui_presentent_le_meme_bon_d_avoir_au_meme_instant_ne_l_imputent_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        app(TenantContext::class)->set($magasin);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = app(EnregistrerVenteCaisseAction::class)->handle($comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $bon = app(EnregistrerAvoirAction::class)->handle($comptoir, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $produit = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $magasinId = $magasin->id;
        $comptoirId = $comptoir->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $comptoirId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreVingtEuros($comptoirId, $produitId, 'AV-MABOUT-2026-000001');
        }, $concurrente);

        $premiere = self::vendreVingtEuros($comptoirId, $produitId, 'AV-MABOUT-2026-000001');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('Ce bon d\'avoir est introuvable ou déjà utilisé.', $resultat->errorOutput());
        $this->assertSame([$ticket->id, $premiere->id], Facture::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['12.00'], AvoirAllocation::query()->where('avoir_id', $bon->id)->pluck('montant_alloue')->all());
        $this->assertSame([12.0, 8.0, 0.0], [$premiere->montantCrediteParAvoir(), $premiere->montantPaye(), $bon->fresh()->montantNonAlloue()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Mme Martin a 12,00 € de crédit d'avoir ; deux caisses lui vendent
     * 20,00 € au même instant. La première impute les 12,00 € et encaisse
     * 8,00 €, la seconde, qui ne trouve plus de crédit, encaisse 20,00 €.
     */
    public function test_deux_ventes_simultanees_au_meme_client_n_imputent_son_credit_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        app(TenantContext::class)->set($magasin);
        $martin = Client::factory()->create(['nom' => 'Mme Martin']);
        $achatRendu = app(EnregistrerVenteCaisseAction::class)->handle($martin, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $avoir = app(EnregistrerAvoirAction::class)->handle($martin, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $produit = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $magasinId = $magasin->id;
        $martinId = $martin->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $martinId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreVingtEuros($martinId, $produitId);
        }, $concurrente);

        $premiere = self::vendreVingtEuros($martinId, $produitId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $seconde = Facture::query()->whereKeyNot([$achatRendu->id, $premiere->id])->sole();
        $this->assertSame(['12.00'], AvoirAllocation::query()->where('avoir_id', $avoir->id)->pluck('montant_alloue')->all());
        $this->assertSame([12.0, 8.0, 0.0], [$premiere->montantCrediteParAvoir(), $premiere->montantPaye(), $premiere->montantRestant()]);
        $this->assertSame([StatutFacture::Payee, 0.0, 20.0, 0.0], [$seconde->statut, $seconde->montantCrediteParAvoir(), $seconde->montantPaye(), $seconde->montantRestant()]);
        $this->assertSame([0.0, 0.0], [$avoir->fresh()->montantNonAlloue(), $martin->soldeCrediteur()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
