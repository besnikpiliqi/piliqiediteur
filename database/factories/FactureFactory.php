<?php

namespace Database\Factories;

use App\Domain\Contacts\Enums\TypeClient;
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
            'type_client' => fn (array $attributs): TypeClient => Client::query()->withTrashed()->findOrFail($attributs['client_id'])->type_client,
        ];
    }
}
