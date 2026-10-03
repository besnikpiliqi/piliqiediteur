<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\ClotureCaisse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClotureCaisse>
 */
class ClotureCaisseFactory extends Factory
{
    protected $model = ClotureCaisse::class;

    public function definition(): array
    {
        return [
            'date_cloture' => now()->toDateString(),
        ];
    }
}
