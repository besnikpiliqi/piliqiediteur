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
 * Chacun change lui-même son mot de passe en connaissant l'actuel ; s'il
 * l'a oublié, il passe par « Mot de passe oublié » (voir
 * MotDePasseOublieTest). Ce sont les seuls chemins pour le propriétaire,
 * dont le mot de passe ne se réinitialise pas depuis l'équipe.
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
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Saisissez votre mot de passe actuel.']);

        $this->assertTrue(Hash::check('ancien-mot-de-passe', $vendeur->fresh()->password));
    }

    public function test_enregistrer_sans_nouveau_mot_de_passe_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['mot_de_passe_actuel' => 'ancien-mot-de-passe'])
            ->call('save')
            ->assertHasFormErrors(['password' => 'Saisissez le nouveau mot de passe.']);

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
     * Le profil ne sert qu'au mot de passe : le nom, affiché sur les
     * documents et dans l'équipe, et l'adresse de connexion restent ceux
     * de la création du compte, même par une requête forgée.
     */
    public function test_le_profil_ne_change_ni_le_nom_ni_ladresse_email(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->set('data.name', 'Marie Dupont')
            ->set('data.email', 'autre@example.test')
            ->fillForm([
                'password' => 'nouveau-mot-de-passe',
                'passwordConfirmation' => 'nouveau-mot-de-passe',
                'mot_de_passe_actuel' => 'ancien-mot-de-passe',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $vendeur->refresh();
        $this->assertSame('Paul', $vendeur->name);
        $this->assertSame('paul@example.test', $vendeur->email);
        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->password));
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
