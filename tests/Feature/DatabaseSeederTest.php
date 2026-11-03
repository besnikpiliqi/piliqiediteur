<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_seed_s_execute_sans_erreur_et_ne_seme_aucune_donnee(): void
    {
        $this->artisan('db:seed')
            ->expectsOutputToContain('Aucune donnée de démonstration')
            ->assertSuccessful();

        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('magasins');
    }
}
