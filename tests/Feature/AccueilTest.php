<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccueilTest extends TestCase
{
    public function test_la_racine_du_site_redirige_vers_le_panneau_des_magasins(): void
    {
        $reponse = $this->get('/');

        $reponse->assertRedirect(route('filament.app.tenant'));
    }
}
