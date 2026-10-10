<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\Auth\RegisterMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterMagasinPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_linscription_cree_le_compte_et_son_magasin_en_une_fois(): void
    {
        Livewire::test(RegisterMagasin::class)
            ->fillForm($this->inscription())
            ->call('register')
            ->assertHasNoFormErrors();

        $utilisateur = User::where('email', 'jeanne@maboutique.fr')->firstOrFail();
        $magasin = Magasin::query()->where('nom', 'Ma Boutique')->sole();

        $this->assertAuthenticatedAs($utilisateur);
        $this->assertSame([$utilisateur->id], $magasin->proprietaires()->get()->modelKeys());
        $this->assertSame('MABOUT', $magasin->code);
    }

    /**
     * Le code proposé depuis le nom se modifie avant l'inscription ; saisi
     * en minuscules, il s'enregistre en majuscules.
     */
    public function test_le_code_propose_se_modifie_avant_l_inscription(): void
    {
        Livewire::test(RegisterMagasin::class)
            ->fillForm(['nom_magasin' => 'Ma Boutique'])
            ->assertFormSet(['code' => 'MABOUT'])
            ->fillForm([...$this->inscription(), 'code' => 'lyon1'])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame('LYON1', Magasin::query()->where('nom', 'Ma Boutique')->sole()->code);
    }

    /**
     * Le code proposé suit le nom tant que la personne ne l'a pas changé ;
     * un code saisi n'est jamais écrasé.
     */
    public function test_le_code_propose_suit_le_nom_sans_ecraser_un_code_saisi(): void
    {
        Livewire::test(RegisterMagasin::class)
            ->fillForm(['nom_magasin' => 'Ma Boutique'])
            ->fillForm(['nom_magasin' => 'Piliqi Store'])
            ->assertFormSet(['code' => 'PILIQI'])
            ->fillForm(['code' => 'LYON1'])
            ->fillForm(['nom_magasin' => 'Boutique de Lyon'])
            ->assertFormSet(['code' => 'LYON1']);
    }

    /**
     * La page est ouverte à tous : ni le code proposé ni la validation du
     * champ seul, que Livewire laisse demander sans limite, ne disent
     * qu'un autre magasin porte déjà ce code.
     */
    public function test_ni_la_proposition_ni_la_validation_du_champ_ne_revelent_les_codes_des_autres_magasins(): void
    {
        Magasin::factory()->create(['code' => 'MABOUT']);

        Livewire::test(RegisterMagasin::class)
            ->fillForm(['nom_magasin' => 'Ma Boutique'])
            ->assertFormSet(['code' => 'MABOUT'])
            ->call('validateOnly', 'data.code')
            ->assertHasNoErrors(['data.code']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function codesRefuses(): array
    {
        return [
            'code avec un tiret' => ['LYON-1', 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.'],
            'code d\'un magasin supprimé' => ['ANCIEN', 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.'],
        ];
    }

    /**
     * Un code mal formé, ou déjà pris, même par un magasin supprimé, est
     * refusé à la soumission, avec le message de l'identité du magasin ;
     * refusé, il n'ouvre ni compte ni magasin.
     */
    #[DataProvider('codesRefuses')]
    public function test_un_code_refuse_n_ouvre_ni_compte_ni_magasin(string $code, string $message): void
    {
        Magasin::factory()->create(['code' => 'ANCIEN'])->delete();

        Livewire::test(RegisterMagasin::class)
            ->fillForm([...$this->inscription(), 'code' => $code])
            ->call('register')
            ->assertHasFormErrors(['code'])
            ->assertSee($message);

        $this->assertFalse(User::where('email', 'jeanne@maboutique.fr')->exists());
        $this->assertFalse(Magasin::query()->where('nom', 'Ma Boutique')->exists());
    }

    /**
     * L'adresse d'un compte existant, tapée sous une autre casse, est la
     * même adresse : l'inscription est refusée sans créer de second
     * compte.
     */
    public function test_une_adresse_deja_utilisee_sous_une_autre_casse_est_refusee(): void
    {
        User::factory()->create(['email' => 'jeanne@maboutique.fr']);

        Livewire::test(RegisterMagasin::class)
            ->fillForm([...$this->inscription(), 'email' => 'Jeanne@MaBoutique.FR'])
            ->call('register')
            ->assertHasFormErrors(['email' => 'unique']);

        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Magasin::query()->count());
    }

    /**
     * Un code pris par une inscription simultanée juste avant
     * l'enregistrement est refusé comme un code déjà pris, et l'inscription
     * s'annule entière : l'adresse e-mail reste libre pour recommencer.
     */
    public function test_un_code_pris_par_une_inscription_simultanee_est_refuse_sans_laisser_de_compte(): void
    {
        $concurrentEnCours = false;
        Magasin::creating(function (Magasin $magasin) use (&$concurrentEnCours): void {
            if (! $concurrentEnCours) {
                $concurrentEnCours = true;
                Magasin::factory()->create(['code' => $magasin->code]);
            }
        });

        Livewire::test(RegisterMagasin::class)
            ->fillForm($this->inscription())
            ->call('register')
            ->assertHasFormErrors(['code'])
            ->assertSee('Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.');

        $this->assertGuest();
        $this->assertFalse(User::where('email', 'jeanne@maboutique.fr')->exists());
    }

    /**
     * @return array<string, string>
     */
    private function inscription(): array
    {
        return [
            'nom_magasin' => 'Ma Boutique',
            'name' => 'Jeanne Dupont',
            'email' => 'jeanne@maboutique.fr',
            'password' => 'mot-de-passe-sur',
            'passwordConfirmation' => 'mot-de-passe-sur',
        ];
    }
}
