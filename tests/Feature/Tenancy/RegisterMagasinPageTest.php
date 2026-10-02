<?php

namespace Tests\Feature\Tenancy;

use App\Filament\App\Pages\Auth\RegisterMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterMagasinPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_linscription_cree_le_compte_et_son_magasin_en_une_fois(): void
    {
        Livewire::test(RegisterMagasin::class)
            ->fillForm([
                'nom_magasin' => 'Ma Boutique',
                'name' => 'Jeanne Dupont',
                'email' => 'jeanne@maboutique.fr',
                'password' => 'mot-de-passe-sur',
                'passwordConfirmation' => 'mot-de-passe-sur',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $utilisateur = User::where('email', 'jeanne@maboutique.fr')->firstOrFail();

        $this->assertAuthenticatedAs($utilisateur);
        $this->assertNotNull($utilisateur->current_magasin_id);
        $this->assertTrue($utilisateur->estProprietaireDe($utilisateur->magasinActuel));
    }
}
