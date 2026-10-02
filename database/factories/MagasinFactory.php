<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Magasin>
 */
class MagasinFactory extends Factory
{
    protected $model = Magasin::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->unique()->company(),
            'raison_sociale' => fake()->company(),
            'siren' => fake()->numerify('#########'),
            'siret' => fake()->numerify('##############'),
            'numero_tva' => 'FR'.fake()->numerify('##').fake()->numerify('#########'),
            'adresse' => fake()->streetAddress(),
            'code_postal' => fake()->postcode(),
            'ville' => fake()->city(),
            'pays' => 'FR',
            'email' => fake()->unique()->companyEmail(),
            'telephone' => fake()->phoneNumber(),
            'devise' => 'EUR',
            'locale' => 'fr',
            'statut' => StatutMagasin::Active,
        ];
    }

    public function enAttenteVerification(): static
    {
        return $this->state(fn () => ['statut' => StatutMagasin::EnAttenteVerification]);
    }
}
