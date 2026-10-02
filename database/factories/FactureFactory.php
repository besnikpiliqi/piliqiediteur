<?php

namespace Database\Factories;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facture>
 */
class FactureFactory extends Factory
{
    protected $model = Facture::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'date_echeance' => now()->addDays(30)->toDateString(),
        ];
    }
}
