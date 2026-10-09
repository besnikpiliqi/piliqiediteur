<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Aucun paquet inutilisé (décision de l'éditeur) : Filament Shield et
 * Sanctum, retirés, n'exposent plus ni route ni commande.
 */
class OutillageTest extends TestCase
{
    public function test_aucune_route_d_un_paquet_retire_n_est_exposee(): void
    {
        $reponse = $this->get('/sanctum/csrf-cookie');

        $reponse->assertNotFound();
    }

    public function test_aucune_commande_d_un_paquet_retire_n_est_proposee(): void
    {
        $commandes = array_keys(Artisan::all());

        $commandesDesPaquetsRetires = array_values(array_filter(
            $commandes,
            fn (string $commande): bool => str_starts_with($commande, 'shield:') || str_starts_with($commande, 'sanctum:'),
        ));

        $this->assertSame([], $commandesDesPaquetsRetires);
    }
}
