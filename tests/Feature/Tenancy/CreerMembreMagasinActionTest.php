<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
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
use Illuminate\Support\Facades\Notification;
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

    /**
     * Aucun comportement visible ne doit jamais laisser deviner au second
     * propriétaire que ce compte existe déjà ailleurs : le nom et le mot
     * de passe qu'il saisit s'appliquent exactement comme pour une
     * création, comme si ce compte lui était dédié (voir le docblock de
     * CreerMembreMagasinAction).
     */
    public function test_un_utilisateur_existant_rejoint_un_second_magasin_avec_le_mot_de_passe_saisi_par_ce_propriétaire(): void
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
            'name' => 'Nouveau Nom Depuis B',
            'email' => 'comptable@example.test',
            'password' => 'mot-de-passe-choisi-par-b',
        ], RoleMagasin::Comptable);

        $comptable->refresh();
        $this->assertTrue($comptable->appartientA($magasinA));
        $this->assertTrue($comptable->appartientA($magasinB));
        $this->assertSame('Nouveau Nom Depuis B', $comptable->name);
        $this->assertTrue(Hash::check('mot-de-passe-choisi-par-b', $comptable->password));
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

    /**
     * Un comptable qui quitte un magasin perd ses notifications à propos de
     * ce magasin, qui ne le regardent plus, et garde celles des autres.
     */
    public function test_retirer_un_membre_efface_ses_notifications_de_ce_magasin_seulement(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $donnees = ['name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-sur'];
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasinA, $donnees, RoleMagasin::Comptable);
        app(CreerMembreMagasinAction::class)->handle($magasinB, $donnees, RoleMagasin::Comptable);
        Notification::send($comptable, new FacturesPasseesEnRetard($magasinA, ['F-A-1']));
        Notification::send($comptable, new FacturesPasseesEnRetard($magasinB, ['F-B-1']));

        app(RetirerMembreMagasinAction::class)->handle($magasinA->membres()->where('user_id', $comptable->id)->firstOrFail());

        $this->assertSame([$magasinB->id], $comptable->notifications()->get()->pluck('data.magasin_id')->all());
    }

    /**
     * Suspendu dans un magasin, un comptable ne peut plus l'ouvrir : sa
     * cloche, commune à ses magasins, n'en montre plus rien. Un simple
     * changement de rôle ne touche pas à ses notifications.
     */
    public function test_suspendre_un_membre_efface_ses_notifications_de_ce_magasin_seulement(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $donnees = ['name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-sur'];
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasinA, $donnees, RoleMagasin::Comptable);
        app(CreerMembreMagasinAction::class)->handle($magasinB, $donnees, RoleMagasin::Comptable);
        Notification::send($comptable, new FacturesPasseesEnRetard($magasinA, ['F-A-1']));
        Notification::send($comptable, new FacturesPasseesEnRetard($magasinB, ['F-B-1']));
        $membreDeA = $magasinA->membres()->where('user_id', $comptable->id)->firstOrFail();

        app(MettreAJourMembreMagasinAction::class)->handle($membreDeA, RoleMagasin::Administrateur, StatutMembreMagasin::Actif);
        $this->assertSame(2, $comptable->notifications()->count());

        app(MettreAJourMembreMagasinAction::class)->handle($membreDeA->fresh(), RoleMagasin::Administrateur, StatutMembreMagasin::Suspendu);

        $this->assertSame([$magasinB->id], $comptable->notifications()->get()->pluck('data.magasin_id')->all());
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
