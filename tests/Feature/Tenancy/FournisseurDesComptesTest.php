<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Models\User;
use Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * L'adresse e-mail d'un compte s'enregistre en minuscules, sans espaces
 * autour, et la connexion la cherche ainsi : la casse tapée n'empêche
 * jamais d'entrer, et ne crée jamais un second compte.
 */
class FournisseurDesComptesTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_adresse_d_un_compte_s_enregistre_en_minuscules_sans_espaces(): void
    {
        $compte = User::factory()->create(['email' => '  Jeanne.Martin@MaBoutique.FR ']);

        $this->assertSame('jeanne.martin@maboutique.fr', $compte->fresh()->email);
    }

    public function test_la_connexion_reussit_quelle_que_soit_la_casse_de_l_adresse(): void
    {
        $proprietaire = User::factory()->create(['email' => 'jeanne@maboutique.fr', 'password' => 'mot-de-passe-de-jeanne']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'Jeanne@MaBoutique.FR', 'password' => 'mot-de-passe-de-jeanne'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($proprietaire);
    }

    public function test_un_mot_de_passe_faux_reste_refuse_quelle_que_soit_la_casse_de_l_adresse(): void
    {
        $proprietaire = User::factory()->create(['email' => 'jeanne@maboutique.fr', 'password' => 'mot-de-passe-de-jeanne']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'Jeanne@MaBoutique.FR', 'password' => 'mauvais-mot-de-passe'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }
}
