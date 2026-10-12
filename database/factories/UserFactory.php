<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Le propriétaire d'une société qui vient de s'inscrire, sans magasin
 * encore (voir SocieteFactory::commeApresLInscription()), son adresse
 * confirmée par le lien de son inscription (voir CreerSocieteAction) : un
 * magasin s'ouvre ensuite dans sa société (voir
 * TestCase::ouvrirUneSociete()). Avec ->for($societe), le propriétaire de
 * cette société. Les membres, les administrateurs et les experts-comptables
 * se créent par la société (voir CreerMembreAction,
 * CreerAdministrateurAction et CreerExpertComptableAction).
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'societe_id' => Societe::factory()->commeApresLInscription(),
            'role_societe' => RoleSociete::Proprietaire,
            'statut' => StatutCompte::Actif,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
