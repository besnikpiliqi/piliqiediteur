<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
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
 * Deux caisses qui présentent le même bon d'avoir au même instant, chacune
 * dans son processus, sur une vraie base PostgreSQL (K6) : la seconde
 * attend le verrou du registre fiscal que tient la première, puis relit
 * sous ce verrou le bon et son crédit restant. Le bon ne s'impute qu'une
 * fois.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class EnregistrerVenteCaisseActionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private static function vendreVingtEurosAvecLeBon(int $comptoirId, int $produitId): Facture
    {
        return app(EnregistrerVenteCaisseAction::class)->handle(
            Client::query()->findOrFail($comptoirId),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            bonDAvoir: 'AV-MABOUT-2026-000001',
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
            self::vendreVingtEurosAvecLeBon($comptoirId, $produitId);
        }, $concurrente);

        $premiere = self::vendreVingtEurosAvecLeBon($comptoirId, $produitId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('Ce bon d\'avoir est introuvable ou déjà utilisé.', $resultat->errorOutput());
        $this->assertSame([$ticket->id, $premiere->id], Facture::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['12.00'], AvoirAllocation::query()->where('avoir_id', $bon->id)->pluck('montant_alloue')->all());
        $this->assertSame([12.0, 8.0, 0.0], [$premiere->montantCrediteParAvoir(), $premiere->montantPaye(), $bon->fresh()->montantNonAlloue()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
