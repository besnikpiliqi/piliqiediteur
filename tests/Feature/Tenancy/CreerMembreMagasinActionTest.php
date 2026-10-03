<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Actions\ReinitialiserMotDePasseMembreAction;
use App\Domain\Tenancy\Actions\RetirerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/**
 * Le propriétaire crée lui-même les comptes de ses vendeurs/salariés et
 * leur communique les identifiants directement -- pas d'auto-inscription,
 * pas de vérification d'email.
 */
class CreerMembreMagasinActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_vendeur_avec_lemail_deja_verifie(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul Vendeur',
            'email' => 'paul@example.test',
            'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->assertNotNull($vendeur->email_verified_at, 'Aucune vérification email requise : le propriétaire se porte garant.');
        $this->assertTrue($vendeur->appartientA($magasin));
        $this->assertTrue(Hash::check('mot-de-passe-sur', $vendeur->password));

        $this->actingAs($vendeur);
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_CREATE));
        $this->assertFalse($vendeur->can(PermissionRegistry::MANDAT_PDP_GERER));
    }

    public function test_impossible_dattribuer_le_role_proprietaire_via_la_gestion_dequipe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->expectException(LogicException::class);
        app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Intrus',
            'email' => 'intrus@example.test',
            'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Proprietaire);
    }

    public function test_un_utilisateur_existant_rejoint_un_second_magasin_sans_toucher_a_son_mot_de_passe(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasinA, [
            'name' => 'Comptable Partagé',
            'email' => 'comptable@example.test',
            'password' => 'mot-de-passe-initial',
        ], RoleMagasin::Comptable);

        $proprietaireB = User::factory()->create();
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        app(CreerMembreMagasinAction::class)->handle($magasinB, [
            'name' => 'Comptable Partagé',
            'email' => 'comptable@example.test',
            'password' => 'un-autre-mot-de-passe-ignore',
        ], RoleMagasin::Comptable);

        $comptable->refresh();
        $this->assertTrue($comptable->appartientA($magasinA));
        $this->assertTrue($comptable->appartientA($magasinB));
        $this->assertTrue(Hash::check('mot-de-passe-initial', $comptable->password), 'Le mot de passe existant ne doit jamais être écrasé par un second rattachement.');
    }

    public function test_changer_le_role_dun_membre_resynchronise_ses_permissions_spatie(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul2@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        app(MettreAJourMembreMagasinAction::class)->handle($membre, RoleMagasin::Comptable, StatutMembreMagasin::Actif);

        $this->actingAs($vendeur->refresh());
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_VIEW_TOUTES));
        $this->assertFalse($vendeur->can(PermissionRegistry::FACTURE_CREATE), 'L\'ancien rôle (vendeur) ne doit plus donner de permissions.');
    }

    public function test_reinitialiser_le_mot_de_passe_dun_membre(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul3@example.test', 'password' => 'ancien-mot-de-passe',
        ], RoleMagasin::Vendeur);

        app(ReinitialiserMotDePasseMembreAction::class)->handle($vendeur, 'nouveau-mot-de-passe');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    public function test_retirer_un_membre_ne_supprime_pas_son_compte_mais_retire_lacces(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, [
            'name' => 'Paul', 'email' => 'paul4@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        app(RetirerMembreMagasinAction::class)->handle($membre);

        $this->assertNotNull(User::find($vendeur->id), 'Le compte utilisateur doit survivre.');
        $this->assertFalse($vendeur->fresh()->appartientA($magasin));
    }

    public function test_impossible_de_retirer_le_proprietaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $membre = $magasin->membres()->where('user_id', $proprietaire->id)->firstOrFail();

        $this->expectException(LogicException::class);
        app(RetirerMembreMagasinAction::class)->handle($membre);
    }
}
