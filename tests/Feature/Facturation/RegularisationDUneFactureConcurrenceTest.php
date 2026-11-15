<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EmettreLaFactureRectificativeAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FaireLAvoirDeRegularisationAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux régularisations simultanées de la même facture, chacune dans son
 * processus, sur une vraie base PostgreSQL (voir
 * FaireLAvoirDeRegularisationAction et EmettreLaFactureRectificativeAction)
 * : la seconde attend le verrou du registre fiscal du magasin que tient la
 * première, puis relit sous ce verrou ce que la première a émis. Deux
 * avoirs de régularisation, ou deux factures rectificatives, ne naissent
 * jamais ; la rectificative demandée pendant que l'avoir s'émet l'attend,
 * puis s'émet, réglée une seule fois de son crédit ; demandée la première,
 * au même instant, elle est refusée, car elle ne s'émet qu'après l'avoir
 * (T68.1) ; deux contre-passations simultanées de deux paiements de la
 * facture régularisée reportent son crédit sans jamais le compter deux
 * fois (T68.3).
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class RegularisationDUneFactureConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
    }

    #[Group('concurrence')]
    public function test_deux_avoirs_de_regularisation_simultanes_de_la_meme_facture_n_en_emettent_qu_un(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $proprietaire = $this->proprietaireDe($magasin);
        $magasinId = $magasin->id;
        $factureId = $facture->id;
        $proprietaireId = $proprietaire->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $factureId, $proprietaireId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(FaireLAvoirDeRegularisationAction::class)->handle(User::query()->findOrFail($proprietaireId), Facture::query()->findOrFail($factureId));
        }, $concurrente);

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($proprietaire, $facture);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString(RegularisationDUneFacture::REFUS_AVOIR_DEJA_EMIS, $resultat->errorOutput());
        $this->assertSame([$avoir->id], Avoir::query()->pluck('id')->all());
        $this->assertSame([[$facture->id, '240.00']], AvoirAllocation::query()->get()->map(fn (AvoirAllocation $allocation): array => [(int) $allocation->facture_id, $allocation->montant_alloue])->all());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    #[Group('concurrence')]
    public function test_deux_factures_rectificatives_simultanees_de_la_meme_facture_n_en_emettent_qu_une(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $responsable = self::responsableDeTest($magasin);
        app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture);
        $magasinId = $magasin->id;
        $factureId = $facture->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $factureId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->findOrFail($factureId));
        }, $concurrente);

        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh());

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString(RegularisationDUneFacture::REFUS_RECTIFICATIVE_DEJA_EMISE, $resultat->errorOutput());
        $this->assertSame([$rectificative->id], Facture::query()->whereNotNull('facture_rectifiee_id')->pluck('id')->all());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    /**
     * La facture est payée en entier : l'avoir de régularisation et la
     * rectificative, demandés au même instant, naissent tous deux, et le
     * crédit de l'avoir règle une seule fois toute la rectificative, dans
     * la transaction de celui qui passe second.
     */
    #[Group('concurrence')]
    public function test_l_avoir_et_la_rectificative_simultanes_reglent_une_seule_fois_la_rectificative(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        app(EnregistrerPaiementAction::class)->handle(self::responsableDeTest($magasin), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => '240.00',
            'recu_le' => '2026-11-02',
        ], [['facture_id' => $facture->id, 'montant' => '240.00']]);
        $magasinId = $magasin->id;
        $factureId = $facture->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $factureId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->findOrFail($factureId));
        }, $concurrente);

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $rectificative = Facture::query()->whereNotNull('facture_rectifiee_id')->sole();
        $this->assertSame([[$rectificative->id, '240.00']], AvoirAllocation::query()->where('avoir_id', $avoir->id)->get()->map(fn (AvoirAllocation $allocation): array => [(int) $allocation->facture_id, $allocation->montant_alloue])->all());
        $this->assertSame([StatutFacture::Payee, 0], [$rectificative->statut, $avoir->fresh()->creditEnCentimes()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    /**
     * L'ordre inverse : la rectificative, demandée au même instant que
     * l'avoir de régularisation, passe la première et est refusée, car elle
     * ne s'émet qu'après lui ; l'avoir, passé second dans l'autre processus,
     * naît, et la rectificative demandée ensuite reçoit une seule fois tout
     * son crédit.
     */
    #[Group('concurrence')]
    public function test_la_rectificative_demandee_avant_l_avoir_au_meme_instant_est_refusee(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $this->payer($magasin, $facture, '240.00');
        $magasinId = $magasin->id;
        $factureId = $facture->id;
        $proprietaireId = $this->proprietaireDe($magasin)->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $factureId, $proprietaireId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(FaireLAvoirDeRegularisationAction::class)->handle(User::query()->findOrFail($proprietaireId), Facture::query()->findOrFail($factureId));
        }, $concurrente);

        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh()), LogicException::class, sprintf(RegularisationDUneFacture::REFUS_RECTIFICATIVE_AVANT_L_AVOIR, $facture->numero));

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame(0, Facture::query()->whereNotNull('facture_rectifiee_id')->count());
        $avoir = Avoir::query()->sole();
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());
        $this->assertSame([[$rectificative->id, '240.00']], $this->imputationsDe($avoir));
        $this->assertSame([StatutFacture::Payee, 0], [$rectificative->fresh()->statut, $avoir->fresh()->creditEnCentimes()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    /**
     * Une facture de 600,00 € payée 500,00 € puis 100,00 €, régularisée
     * (la rectificative a reçu les 600,00 € de l'avoir) : les deux
     * paiements contre-passés au même instant, chacun dans son processus,
     * la seconde contre-passation attendant la première, reportent chacun
     * sur la facture ce qu'il a rouvert, une seule fois : la facture est
     * soldée par l'avoir, la rectificative, dont l'avoir ne règle plus
     * rien, est due de ses 600,00 €, et le registre reste intègre.
     */
    #[Group('concurrence')]
    public function test_deux_contre_passations_simultanees_reportent_le_credit_une_seule_fois(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee(quantite: 5);
        $cinqCents = $this->payer($magasin, $facture, '500.00');
        $cent = $this->payer($magasin, $facture, '100.00');
        $proprietaire = $this->proprietaireDe($magasin);
        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($proprietaire, $facture->fresh());
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());
        $magasinId = $magasin->id;
        $centId = $cent->id;
        $proprietaireId = $proprietaire->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $centId, $proprietaireId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ContrePasserPaiementAction::class)->handle(User::query()->findOrFail($proprietaireId), Paiement::query()->findOrFail($centId), 'Chèque impayé');
        }, $concurrente);

        app(ContrePasserPaiementAction::class)->handle($proprietaire, $cinqCents->fresh(), 'Chèque impayé');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $imputations = $this->imputationsDe($avoir);
        $netSur = fn (int $factureId): int => array_sum(array_map(fn (array $imputation): int => (int) str_replace('.', '', $imputation[1]), array_filter($imputations, fn (array $imputation): bool => $imputation[0] === $factureId)));
        $this->assertSame([60000, 0], [$netSur($facture->id), $netSur($rectificative->id)]);
        $this->assertSame([StatutFacture::Payee, 0, 60000, 0], [$facture->fresh()->statut, $facture->fresh()->resteDuEnCentimes(), $rectificative->fresh()->resteDuEnCentimes(), $avoir->fresh()->creditEnCentimes()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    private function payer(Magasin $magasin, Facture $facture, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::responsableDeTest($magasin), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'recu_le' => '2026-11-02',
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    /**
     * Les imputations de l'avoir, dans leur ordre : la facture et le
     * montant de chacune.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function imputationsDe(Avoir $avoir): array
    {
        return AvoirAllocation::query()->where('avoir_id', $avoir->id)->orderBy('id')->get()
            ->map(fn (AvoirAllocation $allocation): array => [(int) $allocation->facture_id, $allocation->montant_alloue])
            ->all();
    }

    /**
     * Une facture de ce nombre d'articles à 120,00 € TTC, émise à 9 h 55 par le
     * responsable du magasin de Lyon à un client professionnel, puis le
     * SIREN de la société corrigé par l'éditeur à 10 h, après un examen qui
     * l'a désignée (l'examen ne liste que les documents émis avant lui,
     * jamais dans sa seconde).
     *
     * @return array{0: Magasin, 1: Facture}
     */
    private function factureDUneSocieteCorrigee(int $quantite = 2): array
    {
        $this->travelTo(self::aParis('2026-11-02 09:55:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']));
        app(TenantContext::class)->set($magasin->fresh());
        $responsable = self::responsableDeTest($magasin);
        $client = Client::factory()->professionnel()->create(['nom' => 'Dupont SAS']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => $produit->id, 'quantite' => $quantite]]));
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $this->corrigerLeSirenParLEditeur($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);
        app(TenantContext::class)->set($magasin->fresh());

        return [$magasin->fresh(), $facture->fresh()];
    }
}
