<?php

namespace Database\Factories;

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
            'forme_juridique' => 'SAS',
            'siren' => $siren = fake()->unique()->numerify('#########'),
            'siret' => $siren.fake()->numerify('#####'),
            'numero_tva' => 'FR'.fake()->numerify('##').$siren,
            'adresse' => fake()->streetAddress(),
            'code_postal' => fake()->postcode(),
            'ville' => fake()->city(),
            'pays' => 'FR',
            'email' => fake()->unique()->companyEmail(),
            'telephone' => fake()->phoneNumber(),
        ];
    }
}
