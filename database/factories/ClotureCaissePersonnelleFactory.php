<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClotureCaissePersonnelle>
 */
class ClotureCaissePersonnelleFactory extends Factory
{
    protected $model = ClotureCaissePersonnelle::class;

    public function definition(): array
    {
        return [
            'periode_debut' => now()->subHours(4),
            'cloturee_a' => now(),
        ];
    }
}
