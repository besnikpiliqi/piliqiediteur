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
}
