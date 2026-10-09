<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\IdentiteMagasin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentiteMagasinTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_enregistre_l_identite_legale_et_les_coordonnees_du_magasin(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->assertFormSet(['nom' => 'Ma Boutique', 'pays' => 'FR'])
            ->fillForm($this->identiteComplete())
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Identité du magasin enregistrée');

        $this->assertSame($this->identiteComplete(), $magasin->fresh()->only(array_keys($this->identiteComplete())));
    }

    /**
     * @return array<string, array{RoleMagasin}>
     */
    public static function rolesSansAccesALIdentite(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur],
            'vendeur' => [RoleMagasin::Vendeur],
        ];
    }

    /**
     * L'identité imprimée sur tous les documents engage le magasin : elle
     * se règle avec « Paramètres du magasin », qu'aucun modèle de rôle ne
     * coche d'office.
     */
    #[DataProvider('rolesSansAccesALIdentite')]
    public function test_un_membre_sans_les_parametres_du_magasin_n_accede_pas_a_l_identite(RoleMagasin $role): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Membre', 'email' => 'membre@ma-boutique.fr', 'password' => 'mot-de-passe-sur'], $role);

        $this->actingAs($membre)
            ->get(IdentiteMagasin::getUrl(tenant: $magasin))
            ->assertForbidden();
    }

    /**
     * Le propriétaire coche « Paramètres du magasin » pour son
     * administrateur : celui-ci règle alors l'identité.
     */
    public function test_un_administrateur_a_qui_le_proprietaire_coche_les_parametres_accede_a_l_identite(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur, [
            ...RoleMagasin::Administrateur->permissionsParDefaut(),
            PermissionRegistry::PARAMETRES_GERER,
        ]);

        $this->actingAs($administrateur)
            ->get(IdentiteMagasin::getUrl(tenant: $magasin))
            ->assertSee('Identité légale');
    }

    public function test_le_proprietaire_ouvre_la_page_de_l_identite(): void
    {
        $magasin = $this->connecterLeProprietaire();

        $this->get(IdentiteMagasin::getUrl(tenant: $magasin))
            ->assertOk()
            ->assertSee('Identité légale');
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function saisiesInvalides(): array
    {
        return [
            'SIREN de 8 chiffres' => [['siren' => '12345678'], 'siren', 'Le SIREN compte 9 chiffres, sans espace.'],
            'SIRET de 13 chiffres' => [['siren' => '123456789', 'siret' => '1234567890001'], 'siret', 'Le SIRET compte 14 chiffres, sans espace.'],
            'SIRET d\'un autre SIREN' => [['siren' => '123456789', 'siret' => '98765432100012'], 'siret', 'Le SIRET commence par les 9 chiffres du SIREN.'],
            'SIRET sans SIREN' => [['siren' => '', 'siret' => '12345678900012'], 'siren', 'Indiquez le SIREN, par lequel commence le SIRET.'],
            'n° de TVA avec espaces' => [['numero_tva' => 'FR 12 123456789'], 'numero_tva', 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de chiffres ou de lettres, sans espace.'],
            'pays en minuscules' => [['pays' => 'fr'], 'pays', 'Le code du pays compte deux lettres majuscules : FR, BE, CH...'],
            'e-mail sans domaine' => [['email' => 'contact@'], 'email', 'Cette adresse e-mail n\'est pas valide.'],
            'e-mail de 151 caractères' => [['email' => 'contact@'.str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 12).'.fr'], 'email', 'Le champ e-mail ne doit pas dépasser 150 caractères.'],
        ];
    }

    /**
     * @param  array<string, string>  $saisie
     */
    #[DataProvider('saisiesInvalides')]
    public function test_une_saisie_invalide_est_refusee_sans_rien_enregistrer(array $saisie, string $champ, string $message): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm($saisie)
            ->call('save')
            ->assertHasFormErrors([$champ])
            ->assertSee($message);

        $this->assertNull($magasin->fresh()->siret);
        $this->assertSame('FR', $magasin->fresh()->pays);
    }

    /**
     * Un établissement n'appartient qu'à un seul magasin du logiciel, même
     * supprimé ; le magasin garde en revanche son propre SIRET d'un
     * enregistrement à l'autre.
     */
    public function test_le_siret_d_un_autre_magasin_meme_supprime_est_refuse(): void
    {
        $autreMagasin = Magasin::factory()->create(['siren' => '123456789', 'siret' => '12345678900012']);
        $autreMagasin->delete();
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['siren' => '123456789', 'siret' => '12345678900012'])
            ->call('save')
            ->assertHasFormErrors(['siret'])
            ->assertSee('Ce SIRET est déjà celui d\'un autre magasin.');

        $this->assertNull($magasin->fresh()->siret);
        $this->assertSame('12345678900012', Magasin::withTrashed()->find($autreMagasin->id)->siret);
    }

    public function test_le_magasin_reenregistre_son_propre_siret(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $magasin->update(['siren' => '123456789', 'siret' => '12345678900012']);

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['raison_sociale' => 'Ma Boutique SARL'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Ma Boutique SARL', $magasin->fresh()->raison_sociale);
        $this->assertSame('12345678900012', $magasin->fresh()->siret);
    }

    /**
     * Un champ vidé ne garde pas une chaîne vide : deux magasins sans SIRET
     * ne se heurtent pas à l'unicité du SIRET.
     */
    public function test_un_champ_vide_est_enregistre_sans_valeur(): void
    {
        Magasin::factory()->create(['siren' => null, 'siret' => null]);
        $magasin = $this->connecterLeProprietaire();
        $magasin->update(['siren' => '123456789', 'siret' => '12345678900012', 'telephone' => '04 91 00 00 00']);

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['siren' => '', 'siret' => '', 'telephone' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $magasin->refresh();
        $this->assertSame([null, null, null], [$magasin->siren, $magasin->siret, $magasin->telephone]);
    }

    /**
     * L'identité d'un magasin ne touche jamais celle d'un autre.
     */
    public function test_l_identite_reste_celle_du_magasin_actif(): void
    {
        $autreMagasin = app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)->fillForm($this->identiteComplete())->call('save');

        $autreMagasin->refresh();
        $this->assertSame('Autre boutique', $autreMagasin->nom);
        $this->assertNull($autreMagasin->raison_sociale);
        $this->assertNull($autreMagasin->siret);
    }

    /**
     * @return array<string, string>
     */
    private function identiteComplete(): array
    {
        return [
            'nom' => 'Boutique du Port',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'siren' => '123456789',
            'siret' => '12345678900012',
            'numero_tva' => 'FR32123456789',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => 'Bâtiment B',
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'email' => 'contact@boutique-du-port.fr',
            'telephone' => '04 91 00 00 00',
        ];
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
