<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux caisses qui encaissent au même instant, chacune dans son processus,
 * sur une vraie base PostgreSQL : la seconde attend le verrou du registre
 * fiscal que tient la première, puis relit sous ce verrou les crédits
 * qu'elle impute. Le même bon d'avoir (K6), comme le même crédit d'avoir
 * d'un client nommé (voir AppliquerAvoirsDisponiblesAction), ne s'impute
 * qu'une fois ; le même panier, vendu des deux côtés, a les mêmes montants.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class EnregistrerVenteCaisseActionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private static function vendreVingtEuros(int $clientId, int $produitId, ?string $bonDAvoir = null): Facture
    {
        return self::vendreAuComptant(self::auteur(),
            Client::query()->findOrFail($clientId),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            bonsDAvoir: $bonDAvoir === null ? [] : [$bonDAvoir],
        );
    }

    /**
     * Le panier de deux T-shirts à 25,00 € en deux lignes et d'un livre à
     * 10,00 € à 5,5 %, vendu au comptoir.
     */
    private static function vendreLePanier(int $comptoirId, int $tshirtId, int $livreId): Facture
    {
        return self::vendreAuComptant(self::auteur(),
            Client::query()->findOrFail($comptoirId),
            [['produit_id' => $tshirtId, 'quantite' => 1], ['produit_id' => $tshirtId, 'quantite' => 1], ['produit_id' => $livreId, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }

    /**
     * Deux caisses vendent au même instant le même panier (point 15) : le
     * calcul ne dépend que du panier, pas de l'ordre des ventes ni du verrou
     * attendu. Chaque ticket porte, à 20 %, 50,00 ÷ 1,2 = 41,67 € HT
     * réparti 20,83 € + 20,84 €, et, à 5,5 %, 9,48 € HT, soit 51,15 € HT,
     * 8,85 € de TVA et 60,00 € TTC, sous deux numéros distincts ; la
     * clôture du jour additionne les deux tickets : 102,30 € HT, 17,70 € de
     * TVA, 120,00 € TTC.
     */
    #[Group('concurrence')]
    public function test_deux_caisses_qui_vendent_le_meme_panier_au_meme_instant_enregistrent_les_memes_montants(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        app(TenantContext::class)->set($magasin);
        $magasinId = $magasin->id;
        $comptoirId = Client::query()->where('est_comptoir', true)->sole()->id;
        $tshirtId = Produit::factory()->create(['nom' => 'T-shirt', 'prix_vente_ttc' => '25.00', 'taux_tva' => 20, 'suivi_stock' => false])->id;
        $livreId = Produit::factory()->create(['nom' => 'Livre', 'prix_vente_ttc' => '10.00', 'taux_tva' => 5.5, 'suivi_stock' => false])->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $comptoirId, $tshirtId, $livreId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreLePanier($comptoirId, $tshirtId, $livreId);
        }, $concurrente);

        $premiere = self::vendreLePanier($comptoirId, $tshirtId, $livreId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $seconde = Facture::query()->whereKeyNot($premiere->id)->sole();
        foreach ([$premiere, $seconde] as $ticket) {
            $this->assertSame(
                [['20.83', '4.17', '25.00'], ['20.84', '4.16', '25.00'], ['9.48', '0.52', '10.00']],
                $ticket->lignes()->orderBy('ordre')->get()->map(fn (object $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all(),
            );
            $this->assertSame(['51.15', '8.85', '60.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        }
        $this->assertSame($premiere->ventilation_tva, $seconde->fresh()->ventilation_tva);
        $this->assertNotSame($premiere->numero, $seconde->numero);
        $this->assertSame($premiere->date_emission->toDateString(), $seconde->date_emission->toDateString());
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $premiere->date_emission);
        $this->assertSame(['102.30', '17.70', '120.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertEqualsCanonicalizing([
            ['taux_tva' => '20.00', 'montant_ht' => 83.34, 'montant_tva' => 16.66, 'montant_ttc' => 100.0],
            ['taux_tva' => '5.50', 'montant_ht' => 18.96, 'montant_tva' => 1.04, 'montant_ttc' => 20.0],
        ], $cloture->ventilation_tva);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_deux_caisses_qui_presentent_le_meme_bon_d_avoir_au_meme_instant_ne_l_imputent_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        app(TenantContext::class)->set($magasin);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $bon = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
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
     * 20,00 € au même instant, chacune avec une part de 8,00 € en espèces,
     * ce que leur écran proposait. La première impute les 12,00 € et
     * encaisse 8,00 € ; la seconde, qui ne trouve plus de crédit, est
     * refusée, le refus disant que le total a changé et ce qui reste à
     * répartir, sans rien enregistrer (points 1 et 23 : les parts égalent
     * exactement le total à payer).
     */
    public function test_deux_ventes_simultanees_au_meme_client_n_imputent_son_credit_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        app(TenantContext::class)->set($magasin);
        $martin = Client::factory()->create(['nom' => 'Mme Martin']);
        $achatRendu = self::vendreAuComptant(self::auteur(), $martin, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
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
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('Le total à payer a changé (crédit d\'avoir du client) : 12,00 € à répartir.', $resultat->errorOutput());
        $this->assertSame([$achatRendu->id, $premiere->id], Facture::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['12.00'], AvoirAllocation::query()->where('avoir_id', $avoir->id)->pluck('montant_alloue')->all());
        $this->assertSame([StatutFacture::Payee, 12.0, 8.0, 0.0], [$premiere->statut, $premiere->montantCrediteParAvoir(), $premiere->montantPaye(), $premiere->montantRestant()]);
        $this->assertSame([0.0, 0.0], [$avoir->fresh()->montantNonAlloue(), $martin->soldeCrediteur()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
