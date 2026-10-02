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
            'actif' => true,
        ];
    }

    public function professionnel(): static
    {
        return $this->state(fn () => [
            'nom' => fake()->company(),
            'type_client' => TypeClient::Professionnel,
            'siret' => fake()->numerify('##############'),
            'numero_tva' => 'FR'.fake()->numerify('##').fake()->numerify('#########'),
        ]);
    }
}
