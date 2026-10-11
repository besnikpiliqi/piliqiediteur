<?php

namespace Tests\Feature\Contacts;

use App\Domain\Contacts\Actions\VerifierNumeroTvaAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Vies\ViesIndisponibleException;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La vérification d'un n° de TVA de l'Union sur VIES (I2) et sa preuve
 * conservée. Les réponses suivent la documentation de l'API REST de la
 * Commission (check-vat-number) ; aucune requête ne sort des tests.
 */
class VerifierNumeroTvaActionTest extends TestCase
{
    use RefreshDatabase;

    private const URL_VIES = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private const MESSAGE_INDISPONIBLE = 'Le service européen VIES ne répond pas pour ce pays : réessayez dans quelques minutes. Sans n° de TVA vérifié, la vente reste à la TVA française.';

    /**
     * Le magasin d'une société au n° de TVA FR88100000009 (SIREN
     * 100000009), et son propriétaire connecté.
     */
    private function magasinIdentifie(): User
    {
        $proprietaire = User::factory()->for(Societe::factory()->commeApresLInscription()->state(['siren' => '100000009', 'numero_tva' => 'FR88100000009']))->create();
        $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire));
        $this->actingAs($proprietaire);

        return $proprietaire;
    }

    /**
     * @param  array<string, mixed>  $champs
     * @return array<string, mixed>
     */
    private static function reponseVies(array $champs): array
    {
        return [
            'countryCode' => 'DE',
            'vatNumber' => '123456789',
            'requestDate' => '2026-10-09T12:32:10.000Z',
            'valid' => true,
            'requestIdentifier' => 'WAPIAAAAW1234567',
            'name' => '---',
            'address' => '---',
            ...$champs,
        ];
    }

    public function test_un_numero_valide_conserve_la_consultation_et_son_numero_de_preuve(): void
    {
        $proprietaire = $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $this->travelTo('2026-10-09 14:32:10');
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies(['name' => 'ACME GMBH', 'address' => "MUSTERSTRASSE 1\n10115 BERLIN"]))]);

        $consultation = app(VerifierNumeroTvaAction::class)->handle($client);

        $this->assertSame([
            'client_id' => $client->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'nom' => 'ACME GMBH',
            'adresse' => "MUSTERSTRASSE 1\n10115 BERLIN",
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => '09/10/2026 14:32:10',
            'consultee_par' => $proprietaire->id,
        ], [
            ...$consultation->fresh()->only(['client_id', 'numero_tva', 'valide', 'nom', 'adresse', 'identifiant_consultation']),
            'consultee_le' => $consultation->fresh()->consultee_le->format('d/m/Y H:i:s'),
            'consultee_par' => $consultation->fresh()->consultee_par,
        ]);
        $this->assertSame(self::reponseVies(['name' => 'ACME GMBH', 'address' => "MUSTERSTRASSE 1\n10115 BERLIN"]), json_decode($consultation->fresh()->reponse, true));
        $this->assertSame('2026-10-09 12:32:10', DB::table('consultations_vies')->value('consultee_le'));
        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL_VIES
            && $requete->method() === 'POST'
            && $requete->data() === ['countryCode' => 'DE', 'vatNumber' => '123456789', 'requesterMemberStateCode' => 'FR', 'requesterNumber' => '88100000009']);
    }

    public function test_un_numero_inconnu_conserve_une_consultation_invalide(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE999999999')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies(['vatNumber' => '999999999', 'valid' => false]))]);

        $consultation = app(VerifierNumeroTvaAction::class)->handle($client);

        $this->assertSame(
            ['numero_tva' => 'DE999999999', 'valide' => false, 'nom' => null, 'adresse' => null, 'identifiant_consultation' => 'WAPIAAAAW1234567'],
            $consultation->fresh()->only(['numero_tva', 'valide', 'nom', 'adresse', 'identifiant_consultation']),
        );
    }

    /**
     * Un n° mal formé pour son pays : VIES le refuse en INVALID_INPUT, ce
     * qui prouve qu'il n'est pas valide.
     */
    public function test_un_numero_que_vies_refuse_comme_mal_forme_conserve_une_consultation_invalide(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE12345')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'INVALID_INPUT', 'message' => 'Invalid input']]], 400)]);

        $consultation = app(VerifierNumeroTvaAction::class)->handle($client);

        $this->assertSame(['numero_tva' => 'DE12345', 'valide' => false], $consultation->fresh()->only(['numero_tva', 'valide']));
    }

    public function test_un_numero_grec_s_interroge_avec_le_prefixe_el(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('GR', 'EL123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies(['countryCode' => 'EL']))]);

        app(VerifierNumeroTvaAction::class)->handle($client);

        Http::assertSent(fn (Request $requete): bool => $requete['countryCode'] === 'EL' && $requete['vatNumber'] === '123456789');
    }

    /**
     * Un magasin sans n° de TVA (en franchise en base) ne s'identifie pas
     * auprès de VIES : la consultation se fait, sans n° de preuve.
     */
    public function test_un_magasin_sans_numero_de_tva_verifie_sans_s_identifier(): void
    {
        $this->actingAs($proprietaire = User::factory()->create());
        $this->reglerLaSociete($this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire), ['numero_tva' => null]);
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies(['requestIdentifier' => '']))]);

        $consultation = app(VerifierNumeroTvaAction::class)->handle($client);

        Http::assertSent(fn (Request $requete): bool => $requete->data() === ['countryCode' => 'DE', 'vatNumber' => '123456789']);
        $this->assertSame(['valide' => true, 'identifiant_consultation' => null], $consultation->fresh()->only(['valide', 'identifiant_consultation']));
    }

    /**
     * @return array<string, array{0: Closure(): mixed}>
     */
    public static function reponsesSansConclusion(): array
    {
        return [
            'État membre indisponible' => [static fn (): mixed => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE', 'message' => 'Member State unavailable']]], 500)],
            'service saturé' => [static fn (): mixed => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'GLOBAL_MAX_CONCURRENT_REQ']]])],
            'erreur du serveur sans réponse lisible' => [static fn (): mixed => Http::response('<html>Service Unavailable</html>', 503)],
            'réponse sans le résultat' => [static fn (): mixed => Http::response(['countryCode' => 'DE', 'vatNumber' => '123456789'])],
        ];
    }

    /**
     * @param  Closure(): mixed  $reponse
     */
    #[DataProvider('reponsesSansConclusion')]
    public function test_une_reponse_qui_ne_dit_pas_si_le_numero_est_valide_n_est_pas_conservee(Closure $reponse): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => $reponse()]);

        $this->assertThrows(fn () => app(VerifierNumeroTvaAction::class)->handle($client), ViesIndisponibleException::class, self::MESSAGE_INDISPONIBLE);

        $this->assertSame(0, ConsultationVies::query()->count());
    }

    /**
     * Injoignable, VIES est interrogé une seconde fois, une demi-seconde
     * plus tard, puis tenu pour indisponible.
     */
    public function test_vies_injoignable_est_reessaye_une_fois_puis_n_est_pas_conserve(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::failedConnection()]);

        $this->assertThrows(fn () => app(VerifierNumeroTvaAction::class)->handle($client), ViesIndisponibleException::class, self::MESSAGE_INDISPONIBLE);

        Sleep::assertSequence([Sleep::for(500)->milliseconds()]);
        $this->assertSame(0, ConsultationVies::query()->count());
    }

    /**
     * Le journal de l'application dit pourquoi la vérification a échoué,
     * sans le n° vérifié ni la réponse de VIES.
     */
    public function test_le_journal_ne_garde_ni_le_numero_ni_la_reponse(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE', 'message' => 'DE123456789']]], 500)]);
        Log::spy();

        $this->assertThrows(fn () => app(VerifierNumeroTvaAction::class)->handle($client), ViesIndisponibleException::class);

        Log::shouldHaveReceived('warning')->once()->with('VIES n\'a pas pu vérifier un n° de TVA.', ['pays' => 'DE', 'statut_http' => 500, 'erreurs' => ['MS_UNAVAILABLE']]);
    }

    public function test_vies_qui_refuse_le_numero_du_magasin_le_fait_controler(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'INVALID_REQUESTER_INFO']]], 400)]);

        $this->assertThrows(
            fn () => app(VerifierNumeroTvaAction::class)->handle($client),
            LogicException::class,
            'VIES refuse le n° de TVA de la société, qui identifie chaque vérification : contrôlez-le dans Administration de la société > Identité. Sans n° de TVA vérifié, la vente reste à la TVA française.',
        );

        $this->assertSame(0, ConsultationVies::query()->count());
    }

    /**
     * @return array<string, array{0: Closure(): Client, 1: string}>
     */
    public static function clientsQuiNeSeVerifientPas(): array
    {
        return [
            'particulier' => [static fn (): Client => Client::factory()->create(), 'Seul le n° de TVA d\'un client professionnel se vérifie sur VIES.'],
            'professionnel sans n° de TVA' => [static fn (): Client => Client::factory()->etranger('DE', '')->create(['identifiant_etranger' => 'HRB 12345']), 'Ce client n\'a pas de n° de TVA à vérifier : renseignez-le d\'abord sur sa fiche.'],
            'n° de TVA suisse' => [static fn (): Client => Client::factory()->etranger('CH', 'CHE123456789')->create(), 'VIES ne vérifie que les n° de TVA de l\'Union européenne (et XI pour l\'Irlande du Nord).'],
        ];
    }

    /**
     * @param  Closure(): Client  $creerClient
     */
    #[DataProvider('clientsQuiNeSeVerifientPas')]
    public function test_un_client_dont_le_numero_ne_se_verifie_pas_sur_vies_est_refuse_sans_interroger_vies(Closure $creerClient, string $message): void
    {
        $this->magasinIdentifie();
        $client = $creerClient();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies([]))]);

        $this->assertThrows(fn () => app(VerifierNumeroTvaAction::class)->handle($client), InvalidArgumentException::class, $message);

        Http::assertNothingSent();
        $this->assertSame(0, ConsultationVies::query()->count());
    }

    /**
     * Le client se relit dans le magasin actif : celui d'un autre magasin,
     * même chargé sans MagasinScope, n'est ni vérifié ni rattaché à une
     * consultation.
     */
    public function test_le_client_d_un_autre_magasin_n_est_pas_verifie(): void
    {
        $contexte = app(TenantContext::class);
        $contexte->set(Magasin::factory()->create());
        $clientDUnAutreMagasin = Client::factory()->etranger('DE', 'DE123456789')->create();
        $this->magasinIdentifie();
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies([]))]);

        $this->assertThrows(fn () => app(VerifierNumeroTvaAction::class)->handle($clientDUnAutreMagasin), ModelNotFoundException::class);

        Http::assertNothingSent();
        $this->assertSame(0, ConsultationVies::query()->withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * La vérification porte sur le n° enregistré sur la fiche, pas sur
     * celui d'une copie en mémoire que l'écran aurait changé sans
     * l'enregistrer.
     */
    public function test_le_numero_verifie_est_celui_de_la_fiche_enregistree(): void
    {
        $this->magasinIdentifie();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $client->numero_tva = 'DE999999999';
        Http::preventStrayRequests();
        Http::fake([self::URL_VIES => Http::response(self::reponseVies([]))]);

        $consultation = app(VerifierNumeroTvaAction::class)->handle($client);

        $this->assertSame('DE123456789', $consultation->numero_tva);
        Http::assertSent(fn (Request $requete): bool => $requete['vatNumber'] === '123456789');
    }
}
