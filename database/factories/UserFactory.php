<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
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
 * CreerAdministrateurAction et CreerExpertComptableAction), sauf le
 * responsable de test de préparation (voir responsableDeTest()).
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
     * Le responsable de test d'un magasin (voir
     * TestCase::responsableDeTest()) : le compte d'un membre de la société
     * du magasin, nommé « Responsable de test », son mot de passe choisi,
     * responsable de ce seul magasin, sans case puisqu'il y a toutes les
     * autorisations. Écrit directement, comme le propriétaire de la
     * fabrique, sans passer par une action : ni registre fiscal, ni journal
     * des événements, ni journal d'audit, ni avis. Il prépare les ventes
     * des tests, que le propriétaire ne fait jamais ; un test qui éprouve
     * la création d'un responsable et ses traces passe par
     * CreerMembreAction.
     */
    public function responsableDeTest(Magasin $magasin, string $motDePasse): static
    {
        return $this->state(fn (array $attributes): array => [
            'societe_id' => $magasin->societe_id,
            'role_societe' => RoleSociete::Membre,
            'name' => 'Responsable de test',
            'email_verified_at' => null,
            'password' => Hash::make($motDePasse),
        ])->afterCreating(function (User $responsable) use ($magasin): void {
            $affectation = new Affectation;
            $affectation->forceFill([
                'societe_id' => $magasin->societe_id,
                'user_id' => $responsable->getKey(),
                'magasin_id' => $magasin->getKey(),
                'role' => RoleMagasin::Responsable,
            ])->save();
        });
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
