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
            // Toujours au format que le formulaire accepte (TextInput::tel()) :
            // fr_FR tire aussi « +33 (0)X … », qu'il refuserait.
            'telephone' => fake()->numerify('01 ## ## ## ##'),
            'email' => fake()->companyEmail(),
            'pays' => 'FR',
        ];
    }
}
