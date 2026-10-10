<?php

namespace Tests\Feature\Contacts;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Le client comptoir est la fiche des ventes anonymes du magasin (voir
 * CreerMagasinAction) : il se reconnaît à son marqueur, jamais à son nom,
 * et ne se renomme, ne change de type ni ne s'archive.
 */
class ClientTest extends TestCase
{
    use RefreshDatabase;

    private function creerMagasin(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
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
     * Un particulier n'a ni SIRET ni numéro de TVA : ses factures ne les
     * impriment plus.
     */
    public function test_un_client_repasse_en_particulier_perd_son_siret_et_son_numero_de_tva(): void
    {
        $this->creerMagasin();
        $client = Client::factory()->professionnel()->create();

        $client->update(['type_client' => TypeClient::Particulier]);

        $this->assertNull($client->fresh()->siret);
        $this->assertNull($client->fresh()->numero_tva);
    }

    public function test_un_client_professionnel_garde_son_siret_et_son_numero_de_tva(): void
    {
        $this->creerMagasin();

        $client = Client::factory()->professionnel()->create(['siret' => '12345678900012', 'numero_tva' => 'FR12123456789']);

        $this->assertSame('12345678900012', $client->fresh()->siret);
        $this->assertSame('FR12123456789', $client->fresh()->numero_tva);
    }
}
