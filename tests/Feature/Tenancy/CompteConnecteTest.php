<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Support\CompteConnecte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Le compte connecté (voir CompteConnecte) : rendu tel quel quand il y en
 * a un ; sans compte connecté, l'appel est refusé par une exception.
 */
class CompteConnecteTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_compte_connecte_est_rendu_tel_quel(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $this->actingAs($proprietaire);

        $this->assertTrue(CompteConnecte::exiger()->is($proprietaire));
        $this->assertInstanceOf(User::class, CompteConnecte::exiger());
    }

    public function test_sans_compte_connecte_l_appel_est_refuse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CompteConnecte::REFUS);

        CompteConnecte::exiger();
    }
}
