<?php

namespace Database\Factories;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Avoir>
 */
class AvoirFactory extends Factory
{
    protected $model = Avoir::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
        ];
    }
}
