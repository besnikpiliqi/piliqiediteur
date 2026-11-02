<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
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
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux régularisations simultanées de la même facture, chacune dans son
 * processus, sur une vraie base PostgreSQL (voir
 * FaireLAvoirDeRegularisationAction et EmettreLaFactureRectificativeAction)
 * : la seconde attend le verrou du registre fiscal du magasin que tient la
 * première, puis relit sous ce verrou ce que la première a émis. Deux
 * avoirs de régularisation, ou deux factures rectificatives, ne naissent
 * jamais ; l'avoir et la rectificative faits en même temps naissent tous
 * deux, et le crédit de l'avoir ne règle la rectificative qu'une fois.
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
        $this->exigerUnServeurDeBaseDeDonnees();
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
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString(RegularisationDUneFacture::REFUS_AVOIR_DEJA_EMIS, $resultat->errorOutput());
        $this->assertSame([$avoir->id], Avoir::query()->pluck('id')->all());
        $this->assertSame([[$facture->id, '240.00']], AvoirAllocation::query()->get()->map(fn (AvoirAllocation $allocation): array => [(int) $allocation->facture_id, $allocation->montant_alloue])->all());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    #[Group('concurrence')]
    public function test_deux_factures_rectificatives_simultanees_de_la_meme_facture_n_en_emettent_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $responsable = self::responsableDeTest($magasin);
        $magasinId = $magasin->id;
        $factureId = $facture->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $factureId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->findOrFail($factureId));
        }, $concurrente);

        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
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
        $this->exigerUnServeurDeBaseDeDonnees();
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
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $rectificative = Facture::query()->whereNotNull('facture_rectifiee_id')->sole();
        $this->assertSame([[$rectificative->id, '240.00']], AvoirAllocation::query()->where('avoir_id', $avoir->id)->get()->map(fn (AvoirAllocation $allocation): array => [(int) $allocation->facture_id, $allocation->montant_alloue])->all());
        $this->assertSame([StatutFacture::Payee, 0], [$rectificative->statut, $avoir->fresh()->creditEnCentimes()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    /**
     * Une facture de deux articles à 120,00 € TTC, émise par le responsable
     * du magasin de Lyon à un client professionnel, puis le SIREN de la
     * société corrigé par l'éditeur après un examen qui l'a désignée.
     *
     * @return array{0: Magasin, 1: Facture}
     */
    private function factureDUneSocieteCorrigee(): array
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']));
        app(TenantContext::class)->set($magasin->fresh());
        $responsable = self::responsableDeTest($magasin);
        $client = Client::factory()->professionnel()->create(['nom' => 'Dupont SAS']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => $produit->id, 'quantite' => 2]]));
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->corrigerLeSirenParLEditeur($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);
        app(TenantContext::class)->set($magasin->fresh());

        return [$magasin->fresh(), $facture->fresh()];
    }
}
