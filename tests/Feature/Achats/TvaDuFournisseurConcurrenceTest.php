<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux confirmations simultanées du même écart sur la même facture d'achat
 * (deux onglets, ou deux personnes), chacune dans son processus, sur une
 * vraie base PostgreSQL : la facture se verrouille avant que sa TVA ne se
 * retienne, et la seconde demande attend la première, puis relit l'écart
 * déjà confirmé, qu'elle ne reconfirme pas (voir TvaDuFournisseur). Le
 * journal d'audit n'inscrit qu'une confirmation et qu'une pose de la
 * marque.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class TvaDuFournisseurConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    #[Group('concurrence')]
    public function test_deux_confirmations_simultanees_du_meme_ecart_n_en_inscrivent_qu_une(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['reference' => 'COQUE-16', 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [self::ligne($produit->id)], ['date_facture' => '2026-01-15']);
        $magasinId = $magasin->id;
        $factureId = $brouillon->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('factures_achat', 'for update', static function () use ($magasinId, $factureId, $produitId): void {
            self::confirmer($magasinId, $factureId, $produitId);
        }, $concurrente);

        self::confirmer($magasinId, $factureId, $produitId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->output().$resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $facture = FactureAchat::query()->findOrFail($factureId);
        $this->assertSame(['4.10', true], [$facture->total_tva, $facture->tva_a_verifier]);
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::EcartsDuFournisseurConfirmes));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::TvaAVerifierPosee));
    }

    /**
     * La facture de 2 x 10,00 € HT, dont la TVA du fournisseur (4,10 €)
     * s'écarte de 10 centimes du calcul (4,00 €), confirmée par le
     * responsable de test du magasin, obtenu par le test avant de lancer
     * l'autre processus (voir auteur()).
     */
    private static function confirmer(int $magasinId, int $factureId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $facture = FactureAchat::query()->findOrFail($factureId);

        app(ModifierFactureAchatAction::class)->handle(self::responsableDeTest($magasin), $facture, $facture->fournisseur, [self::ligne($produitId)], [], MontantsDuFournisseur::depuisLaSaisie([
            'taux' => [['taux_tva' => '20', 'montant_tva' => '4.10']],
            'confirmation' => true,
        ]));
    }

    /**
     * @return array{produit_id: int, quantite: int, prix_unitaire_ht: string}
     */
    private static function ligne(int $produitId): array
    {
        return ['produit_id' => $produitId, 'quantite' => 2, 'prix_unitaire_ht' => '10.00'];
    }
}
