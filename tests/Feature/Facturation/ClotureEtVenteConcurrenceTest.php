<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * C101 : la clôture du jour et une vente à la caisse au même instant,
 * chacune dans son processus, sur une vraie base PostgreSQL. La seconde
 * attend le verrou du registre fiscal que tient la première. Une vente
 * qui attend la clôture de sa journée est refusée sans rien écrire, et le
 * ticket Z ne la compte pas ; une clôture qui attend la vente la compte.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ClotureEtVenteConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Le 09/10/2026 à 19 h 30, heure de Paris : un magasin, son
     * responsable, qui vend aussi sans stock, et un câble suivi en stock
     * qu'il n'a plus.
     *
     * @return array{0: Magasin, 1: User, 2: Produit}
     */
    private function magasinEnFinDeJournee(): array
    {
        $this->travelTo(self::aParis('2026-10-09 19:30:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['nom' => 'Câble USB', 'reference' => 'CABLE-USB', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);

        return [$magasin, $responsable, $produit];
    }

    /**
     * Un câble vendu au comptoir, payé en espèces, malgré le stock épuisé
     * (confirmé à -1).
     */
    private static function vendreLeCable(User $vendeur, int $produitId): Facture
    {
        return self::vendreAuComptant(
            $vendeur,
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            confirmation: new ConfirmationVenteSansStock([$produitId => -1]),
        );
    }

    public function test_une_vente_qui_attend_la_cloture_de_sa_journee_est_refusee_sans_rien_ecrire(): void
    {
        [$magasin, $responsable, $produit] = $this->magasinEnFinDeJournee();
        $magasinId = $magasin->id;
        $responsableId = $responsable->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $responsableId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreLeCable(User::query()->findOrFail($responsableId), $produitId);
        }, $concurrente);

        $cloture = app(CloturerCaisseAction::class)->handle($responsable, $magasin, '2026-10-09');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('La journée du 09/10/2026 est clôturée : les ventes reprennent demain.', $resultat->errorOutput());
        $this->assertSame([0, 0, 0], [Facture::query()->count(), Paiement::query()->count(), MouvementStock::query()->count()]);
        $this->assertSame([0, '0.00'], [$cloture->fresh()?->nombre_ventes, $cloture->fresh()?->total_ttc]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_une_cloture_qui_attend_une_vente_de_sa_journee_la_compte(): void
    {
        [$magasin, $responsable, $produit] = $this->magasinEnFinDeJournee();
        $magasinId = $magasin->id;
        $responsableId = $responsable->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $responsableId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(CloturerCaisseAction::class)->handle(User::query()->findOrFail($responsableId), $magasin, '2026-10-09');
        }, $concurrente);

        $ticket = self::vendreLeCable($responsable, $produit->id);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame([1, '12.00', $cloture->id], [$cloture->nombre_ventes, $cloture->total_ttc, $ticket->fresh()?->cloture_caisse_id]);
        $this->assertSame([1, 1], [Paiement::query()->count(), MouvementStock::query()->count()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
