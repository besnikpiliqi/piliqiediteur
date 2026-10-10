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
            // Toujours en métropole : un code postal tiré au hasard pourrait
            // tomber en Corse ou outre-mer, et changer le taux de TVA
            // proposé (voir TerritoireTva).
            'code_postal' => fake()->numerify('75###'),
            'ville' => fake()->city(),
            'pays' => 'FR',
            'email' => fake()->unique()->companyEmail(),
            'telephone' => fake()->phoneNumber(),
        ];
    }
}
