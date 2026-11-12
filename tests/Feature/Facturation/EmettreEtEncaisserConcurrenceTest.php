<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux « Émettre et encaisser » (R1) du même brouillon au même instant,
 * chacun dans son processus et avec son propre jeton (deux onglets, ou deux
 * personnes), sur une vraie base PostgreSQL : l'émission verrouille
 * d'abord le registre fiscal du magasin, et la seconde demande attend la
 * première, puis relit le brouillon déjà émis et le refuse. Le document
 * n'est émis qu'une fois, sous un seul numéro, et ses deux parts ne sont
 * encaissées qu'une fois.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class EmettreEtEncaisserConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    #[Group('concurrence')]
    public function test_deux_emettre_et_encaisser_simultanes_du_meme_brouillon_n_emettent_et_n_encaissent_qu_une_fois(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);
        $magasinId = $magasin->id;
        $factureId = $brouillon->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $factureId): void {
            self::emettreEtEncaisser($magasinId, $factureId);
        }, $concurrente);

        self::emettreEtEncaisser($magasinId, $factureId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->failed(), $resultat->output());
        app(TenantContext::class)->set($magasin);
        $facture = Facture::query()->findOrFail($factureId);
        $this->assertStringContainsString(sprintf(EmettreFactureAction::REFUS_DEJA_EMISE, $facture->numero), $resultat->errorOutput());
        $this->assertSame([StatutFacture::Payee, 1], [$facture->statut, Facture::query()->whereNotNull('numero')->count()]);
        $this->assertSame([['especes', '40.00'], ['carte_bancaire', '60.00']], Paiement::query()->orderBy('id')->get()->map(fn (Paiement $paiement): array => [$paiement->mode_paiement->value, $paiement->montant])->all());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * « Émettre et encaisser » du responsable de test du magasin, obtenu
     * par le test avant de lancer l'autre processus (voir auteur()), avec
     * un jeton qui lui est propre : 40,00 € en espèces et 60,00 € par
     * carte.
     */
    private static function emettreEtEncaisser(int $magasinId, int $factureId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(EmettreFactureAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->findOrFail($factureId), ReglementComptant::horsDeLaCaisse([
            new PartDeReglement(ModePaiement::Especes, 4000),
            new PartDeReglement(ModePaiement::CarteBancaire, 6000),
        ]), jeton: (string) Str::uuid());
    }
}
