<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Deux caisses qui encaissent le même ticket au même instant, chacune dans
 * son processus, sur une vraie base PostgreSQL : la seconde attend le
 * verrou du registre fiscal que tient la première, puis relit sous ce
 * verrou le ticket et ce qui en reste dû. Un seul des deux paiements passe,
 * et le total imputé ne dépasse jamais le TTC.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class EnregistrerPaiementActionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Un ticket de 100,00 € TTC émis dans le magasin actif, non réglé.
     */
    private function ticketDeCentEuros(): Facture
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private static function payerSoixanteEuros(int $clientId, int $factureId): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(
            Client::query()->findOrFail($clientId),
            ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '60.00', 'recu_le' => today()->toDateString()],
            [['facture_id' => $factureId, 'montant' => '60.00']],
        );
    }

    public function test_deux_paiements_simultanes_de_60_euros_sur_un_ticket_de_100_euros_n_en_acceptent_qu_un(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $ticket = $this->ticketDeCentEuros();
        $magasinId = $ticket->magasin_id;
        $clientId = (int) $ticket->client_id;
        $factureId = $ticket->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $clientId, $factureId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::payerSoixanteEuros($clientId, $factureId);
        }, $concurrente);

        $premier = self::payerSoixanteEuros($clientId, $factureId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString("Le montant imputé sur la facture {$ticket->numero} (60,00 €) dépasse ce qui en reste dû (40,00 €).", $resultat->errorOutput());
        $this->assertSame([$premier->id], Paiement::query()->pluck('id')->all());
        $this->assertSame(['60.00'], PaiementAllocation::query()->where('facture_id', $factureId)->pluck('montant_alloue')->all());
        $this->assertSame([StatutFacture::PartiellementPayee, 60.0, 40.0], [$ticket->fresh()->statut, $ticket->fresh()->montantPaye(), $ticket->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    public function test_deux_contre_passations_simultanees_du_meme_paiement_n_en_acceptent_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $ticket = $this->ticketDeCentEuros();
        $errone = self::payerSoixanteEuros((int) $ticket->client_id, $ticket->id);
        $magasinId = $ticket->magasin_id;
        $paiementId = $errone->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $paiementId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ContrePasserPaiementAction::class)->handle(Paiement::query()->findOrFail($paiementId), 'Saisi deux fois');
        }, $concurrente);

        app(ContrePasserPaiementAction::class)->handle($errone, 'Saisi deux fois');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('Ce paiement a déjà été contre-passé.', $resultat->errorOutput());
        $this->assertSame(['-60.00', '60.00'], Paiement::query()->orderBy('montant')->pluck('montant')->all());
        $this->assertSame([StatutFacture::Emise, 100.0], [$ticket->fresh()->statut, $ticket->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }
}
