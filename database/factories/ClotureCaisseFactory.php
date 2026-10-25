<?php

namespace Database\Factories;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
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
            'date_cloture' => Calendrier::deParis()->aujourdhui(),
            'recapitulatif' => RecapitulatifDeCloture::vide(),
        ];
    }
}
