<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClotureCaisse>
 *
 * Une clôture du magasin actif, du jour de son calendrier : la fiche n'a
 * pas encore de magasin_id, que BelongsToMagasin pose à la création depuis
 * TenantContext ; le jour se lit donc dans le calendrier de ce même
 * magasin actif, qui la recevra.
 */
class ClotureCaisseFactory extends Factory
{
    protected $model = ClotureCaisse::class;

    public function definition(): array
    {
        return [
            'date_cloture' => app(TenantContext::class)->magasin()->calendrier()->aujourdhui(),
            'recapitulatif' => RecapitulatifDeCloture::vide(),
        ];
    }
}
