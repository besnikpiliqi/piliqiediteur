<?php

namespace Database\Factories;

use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un relevé que le vendeur a fermé lui-même, sans aucune opération.
 *
 * @extends Factory<ClotureCaissePersonnelle>
 */
class ClotureCaissePersonnelleFactory extends Factory
{
    protected $model = ClotureCaissePersonnelle::class;

    public function definition(): array
    {
        return [
            'vendeur_id' => User::factory(),
            'mode_fermeture' => ModeFermetureCaisse::Vendeur,
            'fermee_par' => static fn (array $attributs): int => $attributs['vendeur_id'],
            'periode_debut' => now()->subHours(4),
            'jusqu_au' => now(),
            'cloturee_a' => now(),
            'encaissements' => [],
        ];
    }
}
