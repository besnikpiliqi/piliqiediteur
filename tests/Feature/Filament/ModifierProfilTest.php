<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chacun change lui-même son mot de passe : c'est le seul chemin pour le
 * propriétaire, dont le mot de passe ne se réinitialise pas depuis
 * l'équipe.
 */
class ModifierProfilTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_de_profil_souvre_depuis_le_panneau(): void
    {
        $vendeur = $this->creerVendeur();

        $this->actingAs($vendeur)
            ->get('/app/profile')
            ->assertSee('Mot de passe actuel');
    }

    public function test_un_membre_change_son_mot_de_passe_avec_son_mot_de_passe_actuel(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
                'mot_de_passe_actuel' => 'ancien-mot-de-passe',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    public function test_changer_son_mot_de_passe_sans_le_mot_de_passe_actuel_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
            ])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Saisissez votre mot de passe actuel pour changer l\'adresse e-mail ou le mot de passe.']);

        $this->assertTrue(Hash::check('ancien-mot-de-passe', $vendeur->fresh()->password));
    }

    public function test_un_mot_de_passe_actuel_faux_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
                'mot_de_passe_actuel' => 'devine-au-hasard',
            ])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Le mot de passe actuel est incorrect.']);

        $this->assertTrue(Hash::check('ancien-mot-de-passe', $vendeur->fresh()->password));
    }

    /**
     * L'adresse e-mail est l'identifiant de connexion et reçoit le lien
     * « Mot de passe oublié » : la changer revient à prendre le compte.
     */
    public function test_changer_son_adresse_email_sans_le_mot_de_passe_actuel_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['email' => 'autre@example.test'])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Saisissez votre mot de passe actuel pour changer l\'adresse e-mail ou le mot de passe.']);

        $this->assertSame('paul@example.test', $vendeur->fresh()->email);
    }

    public function test_changer_seulement_son_nom_ne_demande_pas_le_mot_de_passe_actuel(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['name' => 'Paul Martin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Paul Martin', $vendeur->fresh()->name);
    }

    private function creerVendeur(): User
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        return app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul',
            'email' => 'paul@example.test',
            'password' => 'ancien-mot-de-passe',
        ], RoleMagasin::Vendeur);
    }
}
