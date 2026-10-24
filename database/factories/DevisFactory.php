<?php

namespace Database\Factories;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Devis>
 */
class DevisFactory extends Factory
{
    protected $model = Devis::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type_client' => fn (array $attributs): TypeClient => Client::query()->withTrashed()->findOrFail($attributs['client_id'])->type_client,
            'date_validite' => now()->addDays(30)->toDateString(),
        ];
    }
}
