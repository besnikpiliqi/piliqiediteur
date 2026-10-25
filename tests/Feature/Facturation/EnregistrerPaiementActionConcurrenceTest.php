<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Deux opérations simultanées, chacune dans son processus, sur une vraie
 * base PostgreSQL : la seconde attend le verrou du registre fiscal que
 * tient la première, puis relit sous ce verrou ce qu'elle règle. Deux
 * caisses qui encaissent le même ticket : un seul des deux paiements
 * passe, et le total imputé ne dépasse jamais le TTC. Une
 * contre-passation et l'émission d'une facture du même client : son
 * crédit d'avoir ne s'impute qu'une fois.
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

        return self::emettreUneFacture((int) Client::factory()->create()->id, Produit::factory()->create(['prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false])->id);
    }

    /**
     * Une facture de 1 article du produit, émise pour le client, non réglée.
     */
    private static function emettreUneFacture(int $clientId, int $produitId): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::query()->findOrFail($clientId), [['produit_id' => $produitId, 'quantite' => 1]]));
    }

    private static function payer(int $clientId, int $factureId, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            Client::query()->findOrFail($clientId),
            ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $montant, 'recu_le' => Calendrier::deParis()->aujourdhui()],
            [['facture_id' => $factureId, 'montant' => $montant]],
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
            self::payer($clientId, $factureId, '60.00');
        }, $concurrente);

        $premier = self::payer($clientId, $factureId, '60.00');

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
        $errone = self::payer((int) $ticket->client_id, $ticket->id, '60.00');
        $magasinId = $ticket->magasin_id;
        $paiementId = $errone->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $paiementId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ContrePasserPaiementAction::class)->handle(self::auteur(), Paiement::query()->findOrFail($paiementId), 'Saisi deux fois');
        }, $concurrente);

        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $errone, 'Saisi deux fois');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('Ce paiement a déjà été contre-passé.', $resultat->errorOutput());
        $this->assertSame(['-60.00', '60.00'], Paiement::query()->orderBy('montant')->pluck('montant')->all());
        $this->assertSame([StatutFacture::Emise, 100.0], [$ticket->fresh()->statut, $ticket->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    /**
     * Complément 1 : le client a 12,00 € de crédit d'avoir et une facture
     * de 100,00 € réglée par un paiement saisi par erreur. Au même
     * instant, ce paiement est contre-passé, ce qui impute le crédit sur la
     * facture rouverte, et une nouvelle facture de 20,00 € lui est émise :
     * elle ne trouve plus de crédit et reste due en entier.
     */
    public function test_une_contre_passation_et_une_facture_emise_au_meme_instant_n_imputent_le_credit_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        app(TenantContext::class)->set($magasin);
        $client = Client::factory()->create();
        $reprise = self::emettreUneFacture((int) $client->id, Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id);
        self::payer((int) $client->id, $reprise->id, '12.00');
        $rouverte = self::emettreUneFacture((int) $client->id, Produit::factory()->create(['prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false])->id);
        $paiementErrone = self::payer((int) $client->id, $rouverte->id, '100.00');
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $reprise->lignes()->sole()->id, 'quantite' => 1]], 'Retour');
        $this->assertSame([0.0, 12.0], [$rouverte->fresh()->montantRestant(), $client->soldeCrediteur()]);
        $magasinId = $magasin->id;
        $clientId = (int) $client->id;
        $produitId = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $clientId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::emettreUneFacture($clientId, $produitId);
        }, $concurrente);

        $contrePassation = app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiementErrone, 'Saisi sur la mauvaise facture');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $nouvelle = Facture::query()->whereKeyNot([$reprise->id, $rouverte->id])->sole();
        $this->assertSame(
            [[$rouverte->id, $contrePassation->id, '12.00']],
            AvoirAllocation::query()->where('avoir_id', $avoir->id)->get()->map(fn (AvoirAllocation $credit): array => [$credit->facture_id, $credit->paiement_id, $credit->montant_alloue])->all(),
        );
        $this->assertSame([StatutFacture::PartiellementPayee, 0.0, 12.0, 88.0], [$rouverte->fresh()->statut, $rouverte->fresh()->montantPaye(), $rouverte->fresh()->montantCrediteParAvoir(), $rouverte->fresh()->montantRestant()]);
        $this->assertSame([StatutFacture::Emise, 0.0, 20.0], [$nouvelle->statut, $nouvelle->montantCrediteParAvoir(), $nouvelle->montantRestant()]);
        $this->assertSame(0.0, $client->soldeCrediteur());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
