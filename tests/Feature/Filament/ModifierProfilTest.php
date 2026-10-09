<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chacun change lui-même son nom, son adresse de connexion et son mot de
 * passe en donnant son mot de passe actuel ; s'il l'a oublié, il passe par
 * « Mot de passe oublié » (voir MotDePasseOublieTest). Ce sont les seuls
 * chemins pour le propriétaire, dont le mot de passe ne se réinitialise
 * pas depuis l'équipe.
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

    /**
     * La nouvelle adresse ouvre le compte, l'ancienne plus ; le lien
     * « Mot de passe oublié » déjà envoyé à l'ancienne ne sert plus, celui
     * d'un autre compte reste valable.
     */
    public function test_un_membre_change_son_nom_et_son_adresse_avec_son_mot_de_passe_actuel(): void
    {
        $vendeur = $this->creerVendeur();
        $autreCompte = User::factory()->create(['email' => 'autre@example.test']);
        Password::broker()->createToken($vendeur);
        Password::broker()->createToken($autreCompte);
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => 'Paul Martin',
                'email' => 'paul.martin@example.test',
                'mot_de_passe_actuel' => 'ancien-mot-de-passe',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $vendeur->refresh();
        $this->assertSame('Paul Martin', $vendeur->name);
        $this->assertSame('paul.martin@example.test', $vendeur->email);
        $this->assertTrue(Hash::check('ancien-mot-de-passe', $vendeur->password));
        $this->assertTrue(Auth::validate(['email' => 'paul.martin@example.test', 'password' => 'ancien-mot-de-passe']));
        $this->assertFalse(Auth::validate(['email' => 'paul@example.test', 'password' => 'ancien-mot-de-passe']));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'paul@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'autre@example.test']);
    }

    public function test_changer_son_nom_et_son_adresse_sans_le_mot_de_passe_actuel_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => 'Paul Martin',
                'email' => 'paul.martin@example.test',
            ])
            ->call('save')
            ->assertHasFormErrors(['mot_de_passe_actuel' => 'Saisissez votre mot de passe actuel.']);

        $vendeur->refresh();
        $this->assertSame('Paul', $vendeur->name);
        $this->assertSame('paul@example.test', $vendeur->email);
    }

    /**
     * L'adresse d'un autre compte, même supprimé, est refusée sans dire à
     * qui elle appartient.
     *
     * @return array<string, array{Closure(): User}>
     */
    public static function comptesQuiOntDejaLAdresse(): array
    {
        return [
            'compte actif' => [static fn (): User => User::factory()->create(['email' => 'prise@example.test'])],
            'compte supprimé' => [static fn (): User => User::factory()->trashed()->create(['email' => 'prise@example.test'])],
        ];
    }

    /**
     * @param  Closure(): User  $creerLeCompte
     */
    #[DataProvider('comptesQuiOntDejaLAdresse')]
    public function test_une_adresse_deja_prise_est_refusee(Closure $creerLeCompte): void
    {
        $creerLeCompte();
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'email' => 'prise@example.test',
                'mot_de_passe_actuel' => 'ancien-mot-de-passe',
            ])
            ->call('save')
            ->assertHasFormErrors(['email' => 'Cette adresse e-mail ne peut pas être utilisée.']);

        $this->assertSame('paul@example.test', $vendeur->fresh()->email);
    }

    public function test_un_nom_vide_est_refuse(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm([
                'name' => '',
                'mot_de_passe_actuel' => 'ancien-mot-de-passe',
            ])
            ->call('save')
            ->assertHasFormErrors(['name' => 'Le champ nom est obligatoire.']);

        $this->assertSame('Paul', $vendeur->fresh()->name);
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

    /**
     * Le nouveau mot de passe est facultatif : laissé vide, l'ancien
     * reste.
     */
    public function test_enregistrer_sans_nouveau_mot_de_passe_garde_l_ancien(): void
    {
        $vendeur = $this->creerVendeur();
        $this->actingAs($vendeur);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['mot_de_passe_actuel' => 'ancien-mot-de-passe'])
            ->call('save')
            ->assertHasNoFormErrors();

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
