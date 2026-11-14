<?php

namespace Tests\Feature\Stock;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * C101 : deux retours émis au même instant sur la même ligne, chacun dans
 * son processus, sur une vraie base PostgreSQL : ensemble, ils ne rendent
 * jamais plus que la ligne. Deux avoirs fournisseur de 3 articles sur une
 * ligne d'achat de 5 : le second attend le premier sur le verrou de la
 * facture d'achat, puis est refusé. Deux avoirs de 3 articles sur la ligne
 * d'un ticket de 5 : le second attend le premier sur le verrou du
 * registre, puis est refusé.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class RetoursConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Le 09/10/2026 à 10 h, heure de Paris : un magasin et son responsable
     * de test, un carnet suivi en stock.
     *
     * @return array{0: Magasin, 1: User, 2: Produit}
     */
    private function magasinAvecUnCarnet(): array
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $carnet = Produit::factory()->create(['reference' => 'CARNET', 'nom' => 'Carnet', 'prix_vente_ttc' => 6, 'taux_tva' => 20, 'suivi_stock' => true]);

        return [$magasin, self::auteur(), $carnet];
    }

    public function test_deux_avoirs_fournisseur_simultanes_ne_retournent_jamais_plus_que_la_ligne_d_achat(): void
    {
        [$magasin, $responsable, $carnet] = $this->magasinAvecUnCarnet();
        $achat = app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(), [
            ['produit_id' => $carnet->id, 'quantite' => 5, 'prix_unitaire_ht' => '2'],
        ], ['date_facture' => '2026-10-08']));
        $ligne = $achat->lignes()->sole();
        $premier = app(CreerAvoirFournisseurAction::class)->handle($responsable, $achat, [['ligne_achat_id' => $ligne->id, 'quantite' => 3]], 'Abîmés', 'AVF-1');
        $second = app(CreerAvoirFournisseurAction::class)->handle($responsable, $achat->fresh() ?? $achat, [['ligne_achat_id' => $ligne->id, 'quantite' => 3]], 'Abîmés', 'AVF-2');
        $magasinId = $magasin->id;
        $responsableId = $responsable->id;
        $secondId = $second->id;
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('factures_achat', 'for update', static function () use ($magasinId, $responsableId, $secondId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(EmettreAvoirFournisseurAction::class)->handle(User::query()->findOrFail($responsableId), AvoirFournisseur::query()->findOrFail($secondId));
        }, $concurrente);

        app(EmettreAvoirFournisseurAction::class)->handle($responsable, $premier);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('il ne reste que 2 article(s) à retourner.', $resultat->errorOutput());
        $this->assertSame([[3], 2], [MouvementStock::query()->where('type', TypeMouvementStock::RetourFournisseur->value)->pluck('quantite')->all(), $carnet->fresh()?->stock_quantite]);
        $this->assertNull($second->fresh()?->numero);
    }

    public function test_deux_avoirs_simultanes_sur_la_ligne_d_un_ticket_ne_rendent_jamais_plus_que_la_ligne(): void
    {
        [$magasin, $responsable, $carnet] = $this->magasinAvecUnCarnet();
        app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(), [
            ['produit_id' => $carnet->id, 'quantite' => 10, 'prix_unitaire_ht' => '2'],
        ], ['date_facture' => '2026-10-08']));
        $ticket = self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $carnet->id, 'quantite' => 5]]);
        $client = $ticket->client()->firstOrFail();
        $ligne = $ticket->lignes()->sole();
        $premier = app(CreerAvoirAction::class)->handle($responsable, $client, [['ligne_facture_id' => $ligne->id, 'quantite' => 3]]);
        $second = app(CreerAvoirAction::class)->handle($responsable, $client, [['ligne_facture_id' => $ligne->id, 'quantite' => 3]]);
        $magasinId = $magasin->id;
        $responsableId = $responsable->id;
        $secondId = $second->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $responsableId, $secondId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(EmettreAvoirAction::class)->handle(User::query()->findOrFail($responsableId), Avoir::query()->findOrFail($secondId));
        }, $concurrente);

        app(EmettreAvoirAction::class)->handle($responsable, $premier);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('il ne reste que 2 article(s) à créditer.', $resultat->errorOutput());
        $this->assertSame([[3], 8], [MouvementStock::query()->where('type', TypeMouvementStock::RetourClient->value)->pluck('quantite')->all(), $carnet->fresh()?->stock_quantite]);
        $this->assertNull($second->fresh()?->numero);
    }
}
