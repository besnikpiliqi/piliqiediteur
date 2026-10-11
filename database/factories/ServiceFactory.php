<?php

namespace Database\Factories;

use App\Domain\Catalogue\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'nom' => ucfirst(fake()->unique()->words(3, true)),
            'actif' => true,
        ];
    }

    public function archive(): static
    {
        return $this->state(['actif' => false]);
    }

    /**
     * Des frais de port, d'emballage ou d'assurance, qui suivent la
     * livraison des biens qu'ils accompagnent.
     */
    public function fraisAccessoires(): static
    {
        return $this->state(['frais_accessoires' => true]);
    }

    /**
     * Un service dont la loi fixe le lieu (immeuble, restauration...).
     */
    public function lieuSpecifique(): static
    {
        return $this->state(['lieu_specifique' => true]);
    }
}
