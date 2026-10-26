<?php

namespace Database\Factories;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Devis>
 *
 * Un devis du magasin actif, valable 30 jours de son calendrier : la fiche
 * n'a pas encore de magasin_id, que BelongsToMagasin pose à la création
 * depuis TenantContext ; le jour se lit donc dans le calendrier de ce même
 * magasin actif, qui la recevra.
 */
class DevisFactory extends Factory
{
    protected $model = Devis::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type_client' => fn (array $attributs): TypeClient => Client::query()->withTrashed()->findOrFail($attributs['client_id'])->type_client,
            'date_validite' => Calendrier::ajouterJours(app(TenantContext::class)->magasin()->calendrier()->aujourdhui(), 30),
        ];
    }
}
