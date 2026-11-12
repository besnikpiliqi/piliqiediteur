<?php

namespace Tests\Feature\Facturation;

use App\Console\Commands\RelancerTransmissionsPdp;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\RelancerLaTransmissionAction;
use App\Domain\Facturation\Jobs\SubmitFactureToPdpJob;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\TransmissionNonAboutie;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Contracts\ResultatMandatPdp;
use App\Integrations\Pdp\Contracts\ResultatSoumissionPdp;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use App\Integrations\Pdp\Models\TransmissionPdp;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

/**
 * Couvre la décision "doit-on transmettre cette facture au PDP ?" -- voir
 * DeclencherTransmissionPdpApresEmissionFacture -- puis l'exécution réelle
 * du job contre la FakePdpGateway.
 */
class TransmissionPdpTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-09 10:00'));
        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    private function emettreFacturePour(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    public function test_une_facture_a_un_client_professionnel_avec_mandat_actif_declenche_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();

        $facture = $this->emettreFacturePour($client);

        Queue::assertPushed(SubmitFactureToPdpJob::class, fn ($job) => $job->factureId === $facture->id);
    }

    public function test_une_facture_a_un_client_particulier_ne_declenche_jamais_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->create(); // particulier par défaut

        $this->emettreFacturePour($client);

        Queue::assertNotPushed(SubmitFactureToPdpJob::class);
    }

    public function test_sans_mandat_actif_rien_nest_transmis_meme_pour_un_client_professionnel(): void
    {
        Queue::fake();
        $client = Client::factory()->professionnel()->create();
        // Aucun mandat créé pour ce magasin.

        $this->emettreFacturePour($client);

        Queue::assertNotPushed(SubmitFactureToPdpJob::class);
    }

    public function test_un_mandat_revoque_bloque_aussi_la_transmission(): void
    {
        Queue::fake();
        MandatPdp::factory()->revoque()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();

        $this->emettreFacturePour($client);

        Queue::assertNotPushed(SubmitFactureToPdpJob::class);
    }

    public function test_le_job_execute_reellement_cree_une_transmission_acceptee_via_la_fake_gateway(): void
    {
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettreFacturePour($client);

        // Le listener a déjà dispatché le job (queue sync par défaut en
        // test, voir phpunit.xml) : la transmission doit déjà exister.
        $facture->refresh();
        $transmission = $facture->transmissionsPdp()->first();

        $this->assertNotNull($transmission);
        $this->assertSame(StatutTransmissionPdp::Acceptee, $transmission->statut);
        $this->assertNotNull($transmission->transmission_externe_id);
        $this->assertSame(1, $transmission->evenements()->count());
    }

    public function test_le_job_est_idempotent_si_rejoue_sur_une_facture_deja_transmise(): void
    {
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettreFacturePour($client);

        $this->assertSame(1, $facture->transmissionsPdp()->count());

        // Rejouer manuellement le job ne doit pas créer une deuxième transmission.
        (new SubmitFactureToPdpJob($this->magasin->id, $facture->id))->handle(app(PdpGatewayInterface::class));

        $this->assertSame(1, $facture->transmissionsPdp()->count());
    }

    /**
     * Une passerelle de test, posée à la place de la passerelle factice :
     * chaque dépôt joue, dans l'ordre, la réponse donnée (une panne lève
     * une exception), et la plateforme ne connaît que les dépôts que ces
     * réponses ont gardés.
     *
     * @param  list<Closure(string, object): ResultatSoumissionPdp>  $depots
     */
    private function poserLaPasserelle(array $depots): object
    {
        $passerelle = new class($depots) implements PdpGatewayInterface
        {
            /** @var list<string> */
            public array $referencesDeposees = [];

            /** @var array<string, ResultatSoumissionPdp> */
            public array $depotsConnus = [];

            public int $recherches = 0;

            /**
             * @param  list<Closure(string, object): ResultatSoumissionPdp>  $depots
             */
            public function __construct(private array $depots) {}

            public function creerMandat(Magasin $magasin): ResultatMandatPdp
            {
                return new ResultatMandatPdp(statut: StatutMandatPdp::Active, mandatExterneId: 'MANDAT-TEST');
            }

            public function revoquerMandat(MandatPdp $mandat): void {}

            public function soumettreFacture(Facture $facture, string $referenceDepot = ''): ResultatSoumissionPdp
            {
                $this->referencesDeposees[] = $referenceDepot;

                return array_shift($this->depots)($referenceDepot, $this);
            }

            public function retrouverDepot(TransmissionPdp $transmission): ?ResultatSoumissionPdp
            {
                $this->recherches++;

                return $this->depotsConnus[$transmission->reference_depot] ?? null;
            }
        };

        $this->app->instance(PdpGatewayInterface::class, $passerelle);

        return $passerelle;
    }

    private static function accepte(): Closure
    {
        return function (string $reference, object $passerelle): ResultatSoumissionPdp {
            return $passerelle->depotsConnus[$reference] = new ResultatSoumissionPdp(statut: StatutTransmissionPdp::Acceptee, transmissionExterneId: "EXT-{$reference}", format: 'facturx');
        };
    }

    private static function panne(): Closure
    {
        return static fn (): ResultatSoumissionPdp => throw new ConnectionException('cURL error 7: Failed to connect to plateforme port 443');
    }

    /**
     * Une facture émise à un professionnel, dans un magasin qui a un mandat
     * actif, dont la transmission n'est pas encore partie (la file est
     * retenue pendant l'émission).
     */
    private function factureATransmettre(): Facture
    {
        MandatPdp::factory()->create(['magasin_id' => $this->magasin->id]);
        Queue::fake();
        $facture = $this->emettreFacturePour(Client::factory()->professionnel()->create());
        Queue::assertPushed(SubmitFactureToPdpJob::class, fn ($job) => $job->factureId === $facture->id);

        return $facture;
    }

    private function transmissionDe(Facture $facture): TransmissionPdp
    {
        $this->assertSame(1, $facture->transmissionsPdp()->count());

        return $facture->transmissionsPdp()->firstOrFail();
    }

    /**
     * Une panne du réseau au premier essai laisse la transmission en
     * cours, jamais « rejetée » ; l'essai suivant demande d'abord à la
     * plateforme si elle a reçu le dépôt, puis dépose sous la même
     * référence (constat C33 des audits du 07/11/2026).
     */
    public function test_une_panne_reseau_au_premier_essai_est_reprise_au_suivant_avec_la_meme_reference(): void
    {
        $facture = $this->factureATransmettre();
        $passerelle = $this->poserLaPasserelle([self::panne(), self::accepte()]);
        $job = new SubmitFactureToPdpJob($this->magasin->id, $facture->id);

        $this->assertThrows(fn () => $job->handle($passerelle), ConnectionException::class, 'cURL error 7');

        $transmission = $this->transmissionDe($facture);
        $this->assertSame(StatutTransmissionPdp::Soumise, $transmission->statut);
        $this->assertNull($transmission->message_erreur);
        $this->assertNull($transmission->code_erreur);
        $this->assertStringNotContainsString('cURL', (string) json_encode($transmission->evenements()->pluck('charge_utile')));

        $job->handle($passerelle);

        $transmission = $this->transmissionDe($facture);
        $this->assertSame(StatutTransmissionPdp::Acceptee, $transmission->statut);
        $this->assertSame("EXT-{$transmission->reference_depot}", $transmission->transmission_externe_id);
        $this->assertSame([$transmission->reference_depot, $transmission->reference_depot], $passerelle->referencesDeposees);
        $this->assertSame(1, $passerelle->recherches);
    }

    /**
     * La plateforme a reçu le dépôt, puis la réponse s'est perdue : l'essai
     * suivant le retrouve par sa référence et ne dépose jamais une seconde
     * fois la même facture.
     */
    public function test_un_depot_recu_avant_la_panne_n_est_jamais_redepose(): void
    {
        $facture = $this->factureATransmettre();
        $passerelle = $this->poserLaPasserelle([
            function (string $reference, object $passerelle): ResultatSoumissionPdp {
                $passerelle->depotsConnus[$reference] = new ResultatSoumissionPdp(statut: StatutTransmissionPdp::Acceptee, transmissionExterneId: "EXT-{$reference}", format: 'facturx');

                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
            },
        ]);
        $job = new SubmitFactureToPdpJob($this->magasin->id, $facture->id);

        $this->assertThrows(fn () => $job->handle($passerelle), ConnectionException::class);
        $job->handle($passerelle);

        $transmission = $this->transmissionDe($facture);
        $this->assertSame(StatutTransmissionPdp::Acceptee, $transmission->statut);
        $this->assertSame("EXT-{$transmission->reference_depot}", $transmission->transmission_externe_id);
        $this->assertCount(1, $passerelle->referencesDeposees);
    }

    /**
     * Après la dernière tentative automatique, la transmission est « non
     * transmise : incident technique », avec une explication en français
     * qui ne recopie jamais le message technique.
     */
    public function test_l_echec_de_toutes_les_tentatives_passe_en_echec_technique(): void
    {
        $facture = $this->factureATransmettre();
        $passerelle = $this->poserLaPasserelle([self::panne()]);
        $job = new SubmitFactureToPdpJob($this->magasin->id, $facture->id);
        $this->assertThrows(fn () => $job->handle($passerelle), ConnectionException::class);

        $job->failed(new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $transmission = $this->transmissionDe($facture);
        $this->assertSame(StatutTransmissionPdp::EchecTechnique, $transmission->statut);
        $this->assertSame('Non transmise : incident technique', $transmission->statut->getLabel());
        $this->assertSame("La plateforme agréée n'a pas pu être jointe après plusieurs tentatives : c'est un incident technique, pas un refus de la facture. PILIQI la relance chaque heure, sous la même référence, sans jamais la déposer deux fois.", $transmission->message_erreur);
        $this->assertStringNotContainsString('cURL', (string) json_encode($transmission->evenements()->get()->toArray()));
    }

    /**
     * Un rejet de la plateforme est une réponse, pas une panne : la
     * transmission reste rejetée avec son motif, et rien ne la redépose
     * (un redépôt est une nouvelle transmission, par un geste de l'étape 2).
     */
    public function test_un_rejet_de_la_plateforme_reste_final(): void
    {
        $facture = $this->factureATransmettre();
        $passerelle = $this->poserLaPasserelle([
            static fn (): ResultatSoumissionPdp => new ResultatSoumissionPdp(statut: StatutTransmissionPdp::Rejetee, codeErreur: 'BR-FR-12', messageErreur: 'Le SIREN de l\'acheteur est absent.'),
        ]);
        $job = new SubmitFactureToPdpJob($this->magasin->id, $facture->id);

        $job->handle($passerelle);
        $job->handle($passerelle);

        $transmission = $this->transmissionDe($facture);
        $this->assertSame(StatutTransmissionPdp::Rejetee, $transmission->statut);
        $this->assertSame('Rejetée par la plateforme', $transmission->statut->getLabel());
        $this->assertSame('BR-FR-12', $transmission->code_erreur);
        $this->assertSame('Le SIREN de l\'acheteur est absent.', $transmission->message_erreur);
        $this->assertCount(1, $passerelle->referencesDeposees);
        $this->assertSame(0, $passerelle->recherches);
    }

    /**
     * Une facture dont la transmission a échoué après toutes les
     * tentatives automatiques.
     */
    private function factureEnEchecTechnique(): Facture
    {
        $facture = $this->factureATransmettre();
        $job = new SubmitFactureToPdpJob($this->magasin->id, $facture->id);
        $this->assertThrows(fn () => $job->handle($this->poserLaPasserelle([self::panne()])), ConnectionException::class);
        $job->failed(new ConnectionException('cURL error 7: Failed to connect'));
        $this->assertSame(StatutTransmissionPdp::EchecTechnique, $this->transmissionDe($facture)->statut);

        return $facture;
    }

    /**
     * À son entrée en échec technique, le propriétaire et les
     * administrateurs qui suivent la plateforme agréée dans ce magasin en
     * sont prévenus, une seule fois même si les relances de chaque heure
     * échouent encore ; ni un administrateur sans cette case, ni un
     * responsable du magasin (décision de l'éditeur, Q-AUD3).
     */
    public function test_l_echec_technique_previent_le_proprietaire_et_les_administrateurs_qui_suivent_la_plateforme_une_seule_fois(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $suitLaPlateforme = $this->administrateur($this->magasin, null, [AutorisationsSociete::PLATEFORME_SUIVRE]);
        $sansLaCase = $this->administrateur($this->magasin, null, AutorisationsSociete::modeleDeLAdministrateur());
        $responsable = $this->membre($this->magasin, RoleMagasin::Responsable);
        Notification::fake();

        $facture = $this->factureEnEchecTechnique();
        $job = new SubmitFactureToPdpJob($this->magasin->id, $facture->id);
        $this->assertThrows(fn () => $job->handle($this->poserLaPasserelle([self::panne()])), ConnectionException::class);
        $job->failed(new ConnectionException('cURL error 7: Failed to connect'));

        foreach ([$proprietaire, $suitLaPlateforme] as $prevenu) {
            Notification::assertSentToTimes($prevenu, TransmissionNonAboutie::class, 1);
        }
        foreach ([$sansLaCase, $responsable] as $tenuALEcart) {
            Notification::assertNotSentTo($tenuALEcart, TransmissionNonAboutie::class);
        }
        Notification::assertSentTo($proprietaire, TransmissionNonAboutie::class, fn (TransmissionNonAboutie $avis): bool => $avis->facture->is($facture)
            && $avis->toDatabase($proprietaire)['title'] === "{$this->magasin->nom} : Facture {$facture->numero} non transmise à la plateforme agréée");
    }

    /**
     * Le propriétaire et un administrateur qui suit la plateforme agréée
     * relancent à la main une transmission en échec technique ; personne
     * d'autre, et rien d'autre qu'une transmission en échec.
     */
    public function test_la_relance_a_la_main_est_permise_au_proprietaire_et_aux_administrateurs_qui_suivent_la_plateforme(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $suitLaPlateforme = $this->administrateur($this->magasin, null, [AutorisationsSociete::PLATEFORME_SUIVRE]);
        $sansLaCase = $this->administrateur($this->magasin, null, AutorisationsSociete::modeleDeLAdministrateur());
        $responsable = $this->membre($this->magasin, RoleMagasin::Responsable);
        $facture = $this->factureEnEchecTechnique();
        $relancer = app(RelancerLaTransmissionAction::class);

        foreach ([$sansLaCase, $responsable] as $refuse) {
            $this->assertFalse(RelancerLaTransmissionAction::permiseA($refuse, $facture));
            $this->assertThrows(fn () => $relancer->handle($refuse, $facture), AuthorizationException::class);
        }
        // Seul le dépôt de l'émission est parti : aucun refus n'a relancé.
        Queue::assertPushed(SubmitFactureToPdpJob::class, 1);

        foreach ([$proprietaire, $suitLaPlateforme] as $permis) {
            Queue::fake();
            $this->assertTrue(RelancerLaTransmissionAction::permiseA($permis, $facture));

            $relancer->handle($permis, $facture);

            Queue::assertPushed(SubmitFactureToPdpJob::class, fn ($job) => $job->factureId === $facture->id && $job->magasinId === $this->magasin->id);
        }

        $this->poserLaPasserelle([self::accepte()]);
        (new SubmitFactureToPdpJob($this->magasin->id, $facture->id))->handle(app(PdpGatewayInterface::class));
        $this->assertSame(StatutTransmissionPdp::Acceptee, $this->transmissionDe($facture)->statut);
        $this->assertThrows(fn () => $relancer->handle($proprietaire, $facture), LogicException::class, RelancerLaTransmissionAction::REFUS_RIEN_A_RELANCER);
    }

    /**
     * Chaque heure, chaque transmission en échec technique est relancée,
     * magasin par magasin, et seulement elle : une transmission acceptée
     * ou rejetée ne l'est jamais.
     */
    public function test_la_relance_de_chaque_heure_reprend_les_transmissions_en_echec_de_chaque_magasin(): void
    {
        $enEchec = $this->factureEnEchecTechnique();
        $acceptee = $this->emettreFacturePour(Client::factory()->professionnel()->create());
        (new SubmitFactureToPdpJob($this->magasin->id, $acceptee->id))->handle($this->poserLaPasserelle([self::accepte()]));

        $autreMagasin = Magasin::factory()->create();
        app(TenantContext::class)->set($autreMagasin);
        MandatPdp::factory()->create(['magasin_id' => $autreMagasin->id]);
        $ailleurs = $this->emettreFacturePour(Client::factory()->professionnel()->create());
        $job = new SubmitFactureToPdpJob($autreMagasin->id, $ailleurs->id);
        $this->assertThrows(fn () => $job->handle($this->poserLaPasserelle([self::panne()])), ConnectionException::class);
        $job->failed(new ConnectionException('cURL error 7: Failed to connect'));
        app(TenantContext::class)->clear();
        Queue::fake();

        $this->artisan(RelancerTransmissionsPdp::class)->expectsOutput('2 transmission(s) relancée(s).')->assertSuccessful();

        Queue::assertPushed(SubmitFactureToPdpJob::class, 2);
        Queue::assertPushed(SubmitFactureToPdpJob::class, fn ($job) => $job->factureId === $enEchec->id && $job->magasinId === $this->magasin->id);
        Queue::assertPushed(SubmitFactureToPdpJob::class, fn ($job) => $job->factureId === $ailleurs->id && $job->magasinId === $autreMagasin->id);
    }
}
