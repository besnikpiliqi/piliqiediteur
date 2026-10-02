<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MandatPdp>
 */
class MandatPdpFactory extends Factory
{
    protected $model = MandatPdp::class;

    public function definition(): array
    {
        return [
            'magasin_id' => Magasin::factory(),
            'fournisseur_pdp' => 'fake',
            'statut' => StatutMandatPdp::Active,
            'perimetre' => 'both',
            'mandat_externe_id' => 'FAKE-MANDAT-'.fake()->uuid(),
            'consentement_a' => now(),
            'active_a' => now(),
        ];
    }

    public function enAttente(): static
    {
        return $this->state(fn () => ['statut' => StatutMandatPdp::EnAttente, 'active_a' => null]);
    }

    public function revoque(): static
    {
        return $this->state(fn () => ['statut' => StatutMandatPdp::Revoque, 'revoque_a' => now()]);
    }
}
