<?php

namespace Tests\Feature\Contacts;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Le client comptoir est la fiche des ventes anonymes du magasin (voir
 * CreerEtablissementAction) : il se reconnaît à son marqueur, jamais à son nom,
 * et ne se renomme, ne change de type ni ne s'archive. Une fiche ne garde
 * que les identifiants d'entreprise qui correspondent à son type.
 */
class ClientTest extends TestCase
{
    use RefreshDatabase;

    private function creerMagasin(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
    }

    private function clientComptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    public function test_seule_la_fiche_creee_avec_le_magasin_est_le_client_comptoir(): void
    {
        $this->creerMagasin();

        $homonyme = Client::factory()->create(['nom' => Client::NOM_COMPTOIR]);

        $this->assertTrue($this->clientComptoir()->estComptoir());
        $this->assertFalse($homonyme->estComptoir());
    }

    public function test_le_client_comptoir_ne_se_renomme_pas(): void
    {
        $this->creerMagasin();
        $comptoir = $this->clientComptoir();

        $this->assertThrows(
            fn () => $comptoir->update(['nom' => 'Vente comptoir']),
            LogicException::class,
            'Le client comptoir ne se renomme pas et reste un particulier.',
        );

        $this->assertSame(Client::NOM_COMPTOIR, $comptoir->fresh()->nom);
    }

    public function test_le_client_comptoir_reste_un_particulier(): void
    {
        $this->creerMagasin();
        $comptoir = $this->clientComptoir();

        $this->assertThrows(
            fn () => $comptoir->update(['type_client' => TypeClient::Professionnel]),
            LogicException::class,
            'Le client comptoir ne se renomme pas et reste un particulier.',
        );

        $this->assertSame(TypeClient::Particulier, $comptoir->fresh()->type_client);
    }

    public function test_le_client_comptoir_ne_s_archive_jamais(): void
    {
        $this->creerMagasin();
        $comptoir = $this->clientComptoir();

        $this->assertThrows(fn () => $comptoir->delete(), LogicException::class, 'Le client comptoir ne s\'archive jamais.');

        $this->assertNotSoftDeleted($comptoir);
    }

    /**
     * Ses coordonnées et ses notes restent modifiables.
     */
    public function test_le_client_comptoir_garde_ses_autres_informations_modifiables(): void
    {
        $this->creerMagasin();
        $comptoir = $this->clientComptoir();

        $comptoir->update(['notes' => 'Ventes sans fiche client.']);

        $this->assertSame('Ventes sans fiche client.', $comptoir->fresh()->notes);
    }

    public function test_un_magasin_n_a_qu_un_client_comptoir(): void
    {
        $this->creerMagasin();

        $this->expectException(UniqueConstraintViolationException::class);

        Client::forceCreate(['nom' => 'Second comptoir', 'est_comptoir' => true]);
    }

    /**
     * Un particulier n'a aucun identifiant d'entreprise et compte comme
     * établi en France : ses factures n'impriment plus ceux qu'il avait.
     */
    public function test_un_client_repasse_en_particulier_perd_ses_identifiants_d_entreprise(): void
    {
        $this->creerMagasin();
        $client = Client::factory()->etranger('BE', 'BE0123456789')->create([
            'siren' => '123456789',
            'siret' => '12121212000020',
            'identifiant_etranger' => 'BCE 0123.456.789',
        ]);

        $client->update(['type_client' => TypeClient::Particulier]);

        $client->refresh();
        $this->assertSame([null, null, null, null, true], [$client->siren, $client->siret, $client->numero_tva, $client->identifiant_etranger, $client->etabli_en_france]);
    }

    /**
     * Établi en France, un professionnel est identifié par son SIREN :
     * l'identifiant étranger que le formulaire ne montre plus ne reste pas
     * en base.
     */
    public function test_un_professionnel_repasse_etabli_en_france_perd_son_identifiant_etranger(): void
    {
        $this->creerMagasin();
        $client = Client::factory()->etranger('CH', 'CHE123456789')->create(['identifiant_etranger' => 'CHE-123.456.789']);

        $client->update(['etabli_en_france' => true, 'siren' => '123456789']);

        $client->refresh();
        $this->assertSame([true, '123456789', null], [$client->etabli_en_france, $client->siren, $client->identifiant_etranger]);
    }

    /**
     * Une entreprise dont l'adresse est en France, outre-mer compris, y
     * est établie : elle s'identifie par son SIREN, jamais par un
     * identifiant étranger.
     */
    public function test_une_entreprise_dont_l_adresse_est_en_france_y_est_etablie(): void
    {
        $this->creerMagasin();

        $client = Client::factory()->professionnel()->create(['pays' => 'GP', 'etabli_en_france' => false, 'identifiant_etranger' => 'X']);

        $client->refresh();
        $this->assertSame([true, null], [$client->etabli_en_france, $client->identifiant_etranger]);
    }

    public function test_un_client_professionnel_garde_ses_identifiants(): void
    {
        $this->creerMagasin();

        $client = Client::factory()->professionnel()->create(['siren' => '100000009', 'siret' => '10000000900017', 'numero_tva' => 'FR88100000009']);

        $client->refresh();
        $this->assertSame(['100000009', '10000000900017', 'FR88100000009', true], [$client->siren, $client->siret, $client->numero_tva, $client->etabli_en_france]);
    }

    /**
     * La dernière vérification VIES d'une fiche est celle de son n° actuel
     * : après un changement de n°, celles de l'ancien ne disent plus rien.
     */
    public function test_la_derniere_verification_vies_est_celle_du_numero_actuel(): void
    {
        $this->creerMagasin();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $verifier = fn (string $numeroTva, CarbonImmutable $instant): ConsultationVies => ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => $numeroTva,
            'valide' => true,
            'consultee_le' => $instant,
            'reponse' => '{"valid":true}',
        ]);
        $verifier('DE123456789', self::aParis('2026-10-08 10:00:00'));
        $derniereDeLAncien = $verifier('DE123456789', self::aParis('2026-10-09 10:00:00'));
        $verifier('DE999999999', self::aParis('2026-10-09 12:00:00'));

        $this->assertTrue($derniereDeLAncien->is($client->derniereConsultationVies()));

        $client->update(['numero_tva' => 'DE 987 654 321']);

        $this->assertNull($client->derniereConsultationVies());
    }
}
