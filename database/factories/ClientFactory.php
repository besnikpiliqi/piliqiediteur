<?php

namespace Database\Factories;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 *
 * magasin_id n'apparaît volontairement pas ici : BelongsToMagasin l'impose
 * depuis le TenantContext actif au moment du create(). Positionnez le
 * contexte (app(TenantContext::class)->set($magasin)) avant d'appeler cette
 * factory.
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->name(),
            'type_client' => TypeClient::Particulier,
            'telephone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'pays' => 'FR',
        ];
    }

    /**
     * Une entreprise établie en France, identifiée par son SIREN.
     */
    public function professionnel(): static
    {
        return $this->state(fn () => [
            'nom' => fake()->company(),
            'type_client' => TypeClient::Professionnel,
            'siren' => '123456789',
            'siret' => '12345678900011',
            'numero_tva' => 'FR32123456789',
        ]);
    }

    /**
     * Une entreprise sans établissement en France, identifiée par son n°
     * de TVA, sans SIREN.
     */
    public function etranger(string $pays, string $numeroTva): static
    {
        return $this->state(fn () => [
            'nom' => fake()->company(),
            'type_client' => TypeClient::Professionnel,
            'pays' => $pays,
            'etabli_en_france' => false,
            'numero_tva' => $numeroTva,
        ]);
    }
}
