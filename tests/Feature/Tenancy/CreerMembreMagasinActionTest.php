<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\DefinirPermissionsSupplementairesMembreAction;
use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Actions\ReinitialiserMotDePasseMembreAction;
use App\Domain\Tenancy\Actions\RetirerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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
     * Un compte est global : le propriétaire de B qui saisit l'adresse du
     * propriétaire de A ne doit ni changer son mot de passe (il ouvrirait
     * alors le magasin A avec), ni l'attacher à B, ni apprendre à quel
     * magasin cette adresse appartient.
     */
    public function test_ladresse_du_compte_dun_autre_magasin_est_refusee_sans_toucher_a_ce_compte(): void
    {
        $alice = User::factory()->create([
            'name' => 'Alice',
            'email' => 'alice@boutique-a.test',
            'password' => 'mot-de-passe-dalice',
        ]);
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $alice);
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());

        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($magasinB, [
                'name' => 'Pirate',
                'email' => 'alice@boutique-a.test',
                'password' => 'choisi-par-b',
            ], RoleMagasin::Observateur),
            ValidationException::class,
            'Cette adresse e-mail ne peut pas être utilisée pour un nouveau membre.',
        );

        $alice->refresh();
        $this->assertSame('Alice', $alice->name);
        $this->assertTrue(Hash::check('mot-de-passe-dalice', $alice->password));
        $this->assertFalse(Auth::validate(['email' => 'alice@boutique-a.test', 'password' => 'choisi-par-b']));
        $this->assertFalse($magasinB->membres()->where('user_id', $alice->id)->exists());
    }

    /**
     * Recréer un membre suspendu (ou invité) heurtait l'unicité du
     * rattachement et finissait en erreur 500.
     */
    public function test_recreer_un_membre_suspendu_demande_de_modifier_son_statut(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $donnees = ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'];
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, $donnees, RoleMagasin::Vendeur);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        app(MettreAJourMembreMagasinAction::class)->handle($membre, RoleMagasin::Vendeur, StatutMembreMagasin::Suspendu);

        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($magasin, $donnees, RoleMagasin::Administrateur),
            ValidationException::class,
            'Cette personne fait déjà partie de votre équipe : modifiez son statut.',
        );

        $membre->refresh();
        $this->assertSame(StatutMembreMagasin::Suspendu, $membre->statut);
        $this->assertSame(RoleMagasin::Vendeur, $membre->role);
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
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        app(ReinitialiserMotDePasseMembreAction::class)->handle($membre, 'nouveau-mot-de-passe');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    /**
     * Le mot de passe d'un comptable rattaché aux magasins A et B ouvre
     * les deux : le propriétaire de B qui le changerait ouvrirait A.
     */
    public function test_reinitialiser_le_mot_de_passe_dun_compte_rattache_a_un_autre_magasin_est_refuse(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasinA, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-de-claire',
        ], RoleMagasin::Comptable);
        $membreDeB = $this->rattacherAussi($comptable, $magasinB, RoleMagasin::Comptable);

        $this->assertThrows(
            fn () => app(ReinitialiserMotDePasseMembreAction::class)->handle($membreDeB, 'choisi-par-b'),
            LogicException::class,
            'Ce mot de passe ne se réinitialise pas depuis l\'équipe',
        );

        $this->assertTrue(Hash::check('mot-de-passe-de-claire', $comptable->fresh()->password));
    }

    public function test_reinitialiser_le_mot_de_passe_du_proprietaire_est_refuse(): void
    {
        $proprietaire = User::factory()->create(['password' => 'mot-de-passe-du-patron']);
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $membre = $magasin->membres()->where('user_id', $proprietaire->id)->firstOrFail();

        $this->assertThrows(
            fn () => app(ReinitialiserMotDePasseMembreAction::class)->handle($membre, 'nouveau-mot-de-passe'),
            LogicException::class,
        );

        $this->assertTrue(Hash::check('mot-de-passe-du-patron', $proprietaire->fresh()->password));
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
     * Sans ce nettoyage, ses permissions supplémentaires restaient en base
     * et revenaient dès qu'il était de nouveau rattaché à ce magasin. Celles
     * d'un autre magasin ne le regardent pas.
     */
    public function test_retirer_un_membre_retire_ses_permissions_supplementaires_de_ce_magasin_seulement(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasinA, [
            'name' => 'Paul', 'email' => 'paul5@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);
        $membreDeA = $magasinA->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $membreDeB = $this->rattacherAussi($vendeur, $magasinB, RoleMagasin::Vendeur);
        app(DefinirPermissionsSupplementairesMembreAction::class)->handle($membreDeA, [PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::EQUIPE_VIEW]);
        app(DefinirPermissionsSupplementairesMembreAction::class)->handle($membreDeB, [PermissionRegistry::AVOIR_VIEW]);

        app(RetirerMembreMagasinAction::class)->handle($membreDeA);

        $this->assertSame([], $this->permissionsSupplementaires($vendeur, $magasinA));
        $this->assertSame([PermissionRegistry::AVOIR_VIEW], $this->permissionsSupplementaires($vendeur, $magasinB));
    }

    /**
     * Un comptable qui quitte un magasin perd ses notifications à propos de
     * ce magasin, qui ne le regardent plus, et garde celles des autres.
     */
    public function test_retirer_un_membre_efface_ses_notifications_de_ce_magasin_seulement(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasinA, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Comptable);
        $this->rattacherAussi($comptable, $magasinB, RoleMagasin::Comptable);
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
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasinA, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Comptable);
        $this->rattacherAussi($comptable, $magasinB, RoleMagasin::Comptable);
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

    /**
     * Plus aucun écran ne rattache un compte existant à un second magasin
     * (voir EmailLibrePourUnNouveauMembre) : un compte présent dans deux
     * magasins se construit donc ici directement, pour vérifier ce que les
     * actions font d'un tel compte.
     */
    private function rattacherAussi(User $utilisateur, Magasin $magasin, RoleMagasin $role): MagasinUser
    {
        $magasin->utilisateurs()->attach($utilisateur->getKey(), [
            'role' => $role->value,
            'statut' => StatutMembreMagasin::Actif->value,
            'joined_at' => now(),
        ]);
        app(TenantContext::class)->runAs($magasin, fn () => $utilisateur->assignRole($role->value));

        return $magasin->membres()->where('user_id', $utilisateur->getKey())->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function permissionsSupplementaires(User $utilisateur, Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs(
            $magasin,
            fn (): array => $utilisateur->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        );
    }
}
