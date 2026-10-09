<?php

namespace Database\Factories;

use App\Domain\Contacts\Models\Fournisseur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fournisseur>
 */
class FournisseurFactory extends Factory
{
    protected $model = Fournisseur::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->company(),
            'siret' => fake()->unique()->numerify('##############'),
            'telephone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'pays' => 'FR',
            'actif' => true,
        ];
    }
}
