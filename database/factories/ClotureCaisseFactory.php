<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClotureCaisse>
 *
 * Une clôture du magasin actif, du jour de son calendrier : la fiche n'a
 * pas encore de magasin_id, que BelongsToMagasin pose à la création depuis
 * TenantContext ; le jour se lit donc dans le calendrier de ce même
 * magasin actif, qui la recevra. Elle est numérotée d'après sa journée et
 * datée de maintenant, mais aucune clôture ne la signe au journal des
 * événements : la base la refuse à la validation d'une transaction (voir
 * GardesDesClotures), et elle ne sert qu'aux tests enveloppés dans une
 * transaction jamais validée (RefreshDatabase) ; un test qui valide ses
 * écritures passe par la vraie clôture (CloturerCaisseAction).
 */
class ClotureCaisseFactory extends Factory
{
    protected $model = ClotureCaisse::class;

    public function definition(): array
    {
        return [
            'date_cloture' => app(TenantContext::class)->magasin()->calendrier()->aujourdhui(),
            'numero' => fn (array $clotureCaisse): string => 'Z-FAB-'.str_replace('-', '', ValeursLues::texteDe($clotureCaisse, 'date_cloture')),
            'cloturee_a' => now(),
            'recapitulatif' => RecapitulatifDeCloture::vide(),
        ];
    }
}
