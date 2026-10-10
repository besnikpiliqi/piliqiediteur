<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Actions\ReinitialiserMotDePasseMembreAction;
use App\Domain\Tenancy\Actions\RetirerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le propriétaire crée lui-même les comptes de ses vendeurs/salariés et
 * leur communique les identifiants directement -- pas d'auto-inscription,
 * pas de vérification d'email. Chaque compte n'appartient qu'à son
 * magasin, et n'y a que les autorisations cochées pour lui.
 */
class CreerMembreMagasinActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_vendeur_avec_lemail_deja_verifie(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul Vendeur',
            'email' => 'paul@example.test',
            'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->assertNotNull($vendeur->email_verified_at, 'Aucune vérification email requise : le propriétaire se porte garant.');
        $this->assertTrue($vendeur->appartientA($magasin));
        $this->assertTrue(Hash::check('mot-de-passe-sur', $vendeur->password));

        $this->actingAs($vendeur);
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_CREATE));
        $this->assertFalse($vendeur->can(PermissionRegistry::PARAMETRES_GERER));
    }

    public function test_impossible_dattribuer_le_role_proprietaire_via_la_gestion_dequipe(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->expectException(LogicException::class);
        app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Intrus',
            'email' => 'intrus@example.test',
            'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Proprietaire);
    }

    /**
     * Sans liste, le membre reçoit exactement les autorisations du modèle
     * de son rôle, données directement : aucun rôle Spatie.
     */
    public function test_sans_liste_un_vendeur_recoit_exactement_le_modele_de_son_role(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->assertSame([
            'client.create', 'client.update', 'client.view',
            'devis.create', 'devis.envoyer', 'devis.update', 'devis.view',
            'facture.create', 'facture.emettre', 'facture.envoyer', 'facture.view',
            'paiement.creer', 'paiement.view',
            'produit.view',
        ], $this->autorisations($vendeur, $magasin));
        $this->assertSame([], $vendeur->fresh()->getRoleNames()->all());
    }

    /**
     * Le propriétaire a décoché l'envoi par e-mail et coché la création
     * d'avoirs : le vendeur n'a que ce qui est coché, rien de plus du
     * modèle de son rôle.
     */
    public function test_avec_une_liste_le_membre_n_a_que_les_autorisations_cochees(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]);

        $this->assertSame(['avoir.creer', 'facture.view'], $this->autorisations($vendeur, $magasin));
        $this->assertTrue($vendeur->can(PermissionRegistry::AVOIR_CREER));
        $this->assertFalse($vendeur->can(PermissionRegistry::FACTURE_ENVOYER));
    }

    public function test_une_autorisation_inconnue_est_refusee(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
                'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
            ], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, 'facture.tout']),
            InvalidArgumentException::class,
            'Autorisation inconnue : facture.tout',
        );

        $this->assertDatabaseMissing('users', ['email' => 'paul@example.test']);
        $this->assertSame(1, $magasin->membres()->count());
    }

    /**
     * Le propriétaire a laissé à son administrateur la gestion de l'équipe
     * et la lecture de ses propres factures : un compte « Administrateur »
     * que celui-ci crée ne reçoit, du modèle du rôle, que ces
     * autorisations-là -- jamais plus que son créateur, qui choisit son
     * mot de passe.
     */
    public function test_un_membre_qui_gere_l_equipe_cree_un_compte_sans_les_autorisations_qu_il_n_a_pas(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur, [
            PermissionRegistry::EQUIPE_VIEW, PermissionRegistry::EQUIPE_CREER, PermissionRegistry::FACTURE_VIEW,
        ]);

        $nouveau = app(CreerMembreMagasinAction::class)->handle($administrateur, $magasin, [
            'name' => 'Second compte', 'email' => 'second@example.test', 'password' => 'choisi-par-l-administrateur',
        ], RoleMagasin::Administrateur);

        $this->assertSame(['equipe.creer', 'equipe.view', 'facture.view'], $this->autorisations($nouveau, $magasin));
        $this->assertSame(RoleMagasin::Administrateur, $magasin->membres()->where('user_id', $nouveau->id)->sole()->role);
    }

    /**
     * Seul le propriétaire coche les autorisations d'un membre : une liste
     * envoyée par un autre membre qui gère l'équipe est refusée, et aucun
     * compte n'est créé.
     */
    public function test_seul_le_proprietaire_coche_les_autorisations_d_un_nouveau_membre(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);

        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($administrateur, $magasin, [
                'name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur',
            ], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]),
            LogicException::class,
            'Seul le propriétaire coche les autorisations d\'un membre.',
        );

        $this->assertDatabaseMissing('users', ['email' => 'paul@example.test']);
        $this->assertSame(2, $magasin->membres()->count());
    }

    /**
     * Chaque compte n'appartient qu'à son magasin : ce qui est coché pour
     * le membre de A n'existe pas dans B, ni l'inverse.
     */
    public function test_les_autorisations_donnees_dans_un_magasin_n_existent_pas_dans_un_autre(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $membreDeA = $this->membre($magasinA, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_VIEW]);
        $membreDeB = $this->membre($magasinB, RoleMagasin::Comptable);

        $this->assertSame([], $this->autorisations($membreDeA, $magasinB));
        $this->assertSame([], $this->autorisations($membreDeB, $magasinA));
        $this->assertSame(['avoir.view'], $this->autorisations($membreDeA, $magasinA));
        app(TenantContext::class)->set($magasinB);
        $this->assertFalse($membreDeA->fresh()->can(PermissionRegistry::AVOIR_VIEW));
    }

    /**
     * Un compte n'appartient qu'à un seul magasin : la base refuse un
     * second rattachement, et la gestion d'équipe d'un autre magasin
     * refuse son adresse sans toucher au compte.
     */
    public function test_un_compte_ne_se_rattache_jamais_a_un_second_magasin(): void
    {
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());
        $comptable = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasinA), $magasinA, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-de-claire',
        ], RoleMagasin::Comptable);
        $hashDuMotDePasse = $comptable->password;

        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => $magasinB->utilisateurs()->attach($comptable->getKey(), [
                'role' => RoleMagasin::Comptable->value,
                'statut' => StatutMembreMagasin::Actif->value,
                'joined_at' => now(),
            ])),
            UniqueConstraintViolationException::class,
        );
        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasinB), $magasinB, [
                'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'choisi-par-b',
            ], RoleMagasin::Comptable),
            ValidationException::class,
            'Cette adresse e-mail ne peut pas être utilisée pour un nouveau membre.',
        );

        $this->assertSame([$magasinA->id], $comptable->magasins()->pluck('magasins.id')->all());
        $this->assertSame($hashDuMotDePasse, $comptable->fresh()->password);
    }

    /**
     * Le propriétaire de B qui saisit l'adresse du propriétaire de A ne
     * doit ni changer son mot de passe (il ouvrirait alors le magasin A
     * avec), ni l'attacher à B, ni apprendre à quel magasin cette adresse
     * appartient.
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
            fn () => app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasinB), $magasinB, [
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
     * « Alice@Boutique-A.TEST » est la même adresse que
     * « alice@boutique-a.test » : elle ne sert pas davantage à un nouveau
     * membre d'un autre magasin.
     */
    public function test_une_adresse_deja_utilisee_sous_une_autre_casse_est_refusee(): void
    {
        $alice = User::factory()->create(['email' => 'alice@boutique-a.test']);
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $alice);
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], User::factory()->create());

        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasinB), $magasinB, [
                'name' => 'Pirate',
                'email' => ' Alice@Boutique-A.TEST ',
                'password' => 'choisi-par-b',
            ], RoleMagasin::Observateur),
            ValidationException::class,
            'Cette adresse e-mail ne peut pas être utilisée pour un nouveau membre.',
        );

        $this->assertSame(1, User::query()->where('email', 'alice@boutique-a.test')->count());
        $this->assertFalse($magasinB->membres()->where('user_id', $alice->id)->exists());
    }

    public function test_l_adresse_d_un_nouveau_membre_s_enregistre_en_minuscules_sans_espaces(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());

        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Paul',
            'email' => '  Paul.Vendeur@Example.TEST ',
            'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $this->assertSame('paul.vendeur@example.test', $vendeur->fresh()->email);
    }

    /**
     * Un membre suspendu reste rattaché au magasin : le recréer est refusé
     * par un message, sans toucher à son rattachement.
     */
    public function test_recreer_un_membre_suspendu_demande_de_modifier_son_statut(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $donnees = ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'];
        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, $donnees, RoleMagasin::Vendeur);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        app(MettreAJourMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $membre, RoleMagasin::Vendeur, StatutMembreMagasin::Suspendu);

        $this->assertThrows(
            fn () => app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, $donnees, RoleMagasin::Administrateur),
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
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul', 'email' => 'paul2@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        app(MettreAJourMembreMagasinAction::class)->handle($proprietaire, $membre, RoleMagasin::Comptable, StatutMembreMagasin::Actif);

        $this->actingAs($vendeur->refresh());
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_VIEW_TOUTES));
        $this->assertFalse($vendeur->can(PermissionRegistry::FACTURE_CREATE), 'L\'ancien rôle (vendeur) ne doit plus donner de permissions.');
    }

    /**
     * Refusée, la modification ne change rien : ni le rôle, ni les
     * autorisations, ni le journal des événements.
     */
    public function test_modifier_un_membre_avec_une_autorisation_inconnue_est_refuse_sans_rien_changer(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $evenementsAvant = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->count();

        $this->assertThrows(
            fn () => app(MettreAJourMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $membre, RoleMagasin::Administrateur, StatutMembreMagasin::Actif, ['facture.tout']),
            InvalidArgumentException::class,
            'Autorisation inconnue : facture.tout',
        );

        $this->assertSame(RoleMagasin::Vendeur, $membre->fresh()->role);
        $this->assertSame(['facture.view'], $this->autorisations($vendeur, $magasin));
        $this->assertSame($evenementsAvant, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->count());
    }

    /**
     * Le propriétaire a coché « Avoirs : créer » et décoché « Factures :
     * envoyer par e-mail et relancer » pour un vendeur. Un administrateur
     * qui gère l'équipe ne change ni son rôle (le modèle du nouveau rôle
     * effacerait ces choix, et un aller-retour Comptable puis Vendeur
     * rendrait l'envoi décoché), ni ses autorisations : refusée, la
     * modification ne change rien, journal des événements compris.
     *
     * @return array<string, array{RoleMagasin, list<string>|null, string}>
     */
    public static function modificationsDuProprietaire(): array
    {
        return [
            'changer son rôle' => [RoleMagasin::Comptable, null, 'Seul le propriétaire change le rôle d\'un membre.'],
            'cocher ses autorisations' => [RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_ENVOYER], 'Seul le propriétaire coche les autorisations d\'un membre.'],
        ];
    }

    /**
     * @param  list<string>|null  $permissions
     */
    #[DataProvider('modificationsDuProprietaire')]
    public function test_un_autre_membre_que_le_proprietaire_ne_change_ni_le_role_ni_les_autorisations(RoleMagasin $role, ?array $permissions, string $refus): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);
        $choixDuProprietaire = [
            ...array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [PermissionRegistry::FACTURE_ENVOYER]),
            PermissionRegistry::AVOIR_CREER,
        ];
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, $choixDuProprietaire);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        $evenementsAvant = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->count();

        $this->assertThrows(
            fn () => app(MettreAJourMembreMagasinAction::class)->handle($administrateur, $membre, $role, StatutMembreMagasin::Actif, $permissions),
            LogicException::class,
            $refus,
        );

        $this->assertSame(RoleMagasin::Vendeur, $membre->fresh()->role);
        $this->assertSame([
            'avoir.creer',
            'client.create', 'client.update', 'client.view',
            'devis.create', 'devis.envoyer', 'devis.update', 'devis.view',
            'facture.create', 'facture.emettre', 'facture.view',
            'paiement.creer', 'paiement.view',
            'produit.view',
        ], $this->autorisations($vendeur, $magasin));
        $this->assertSame($evenementsAvant, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->count());
    }

    public function test_reinitialiser_le_mot_de_passe_dun_membre(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul', 'email' => 'paul3@example.test', 'password' => 'ancien-mot-de-passe',
        ], RoleMagasin::Vendeur);
        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();

        app(ReinitialiserMotDePasseMembreAction::class)->handle($proprietaire, $membre, 'nouveau-mot-de-passe');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    /**
     * Ce formulaire ne demande pas le mot de passe actuel : une session
     * d'administrateur restée ouverte suffirait sinon à prendre son compte.
     */
    public function test_reinitialiser_son_propre_mot_de_passe_depuis_lequipe_est_refuse(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-admin',
        ], RoleMagasin::Administrateur);
        $membre = $magasin->membres()->where('user_id', $administrateur->id)->firstOrFail();

        $this->assertThrows(
            fn () => app(ReinitialiserMotDePasseMembreAction::class)->handle($administrateur, $membre, 'pris-sur-la-caisse-ouverte'),
            LogicException::class,
            'Changez votre propre mot de passe depuis votre profil, avec votre mot de passe actuel.',
        );

        $this->assertTrue(Hash::check('mot-de-passe-admin', $administrateur->fresh()->password));
    }

    /**
     * Le propriétaire a coché « Paramètres du magasin » pour son comptable,
     * pas pour l'administrateur : en lui donnant un mot de passe de son
     * choix, l'administrateur ouvrirait l'identité et la TVA du magasin
     * sous le compte du comptable.
     */
    public function test_un_autre_membre_que_le_proprietaire_ne_donne_pas_de_mot_de_passe_a_un_membre_plus_autorise_que_lui(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);
        $comptable = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-de-claire',
        ], RoleMagasin::Comptable, [...RoleMagasin::Comptable->permissionsParDefaut(), PermissionRegistry::PARAMETRES_GERER]);
        $membre = $magasin->membres()->where('user_id', $comptable->id)->firstOrFail();

        $this->assertThrows(
            fn () => app(ReinitialiserMotDePasseMembreAction::class)->handle($administrateur, $membre, 'choisi-par-l-administrateur'),
            LogicException::class,
            'Seul le propriétaire peut donner un nouveau mot de passe à ce membre : il a des autorisations que vous n\'avez pas.',
        );

        $this->assertTrue(Hash::check('mot-de-passe-de-claire', $comptable->fresh()->password));
    }

    /**
     * Un vendeur au modèle de son rôle n'a aucune autorisation que
     * l'administrateur n'ait aussi : l'administrateur lui donne un nouveau
     * mot de passe.
     */
    public function test_un_administrateur_donne_un_nouveau_mot_de_passe_a_un_membre_dont_il_a_toutes_les_autorisations(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        app(ReinitialiserMotDePasseMembreAction::class)->handle($administrateur, $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail(), 'nouveau-mot-de-passe');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $vendeur->fresh()->password));
    }

    public function test_reinitialiser_le_mot_de_passe_du_proprietaire_est_refuse(): void
    {
        $proprietaire = User::factory()->create(['password' => 'mot-de-passe-du-patron']);
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $administrateur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'mot-de-passe-admin',
        ], RoleMagasin::Administrateur);
        $membre = $magasin->membres()->where('user_id', $proprietaire->id)->firstOrFail();

        $this->assertThrows(
            fn () => app(ReinitialiserMotDePasseMembreAction::class)->handle($administrateur, $membre, 'nouveau-mot-de-passe'),
            LogicException::class,
            'Ce mot de passe ne se réinitialise pas depuis l\'équipe',
        );

        $this->assertTrue(Hash::check('mot-de-passe-du-patron', $proprietaire->fresh()->password));
    }

    public function test_retirer_un_membre_ne_supprime_pas_son_compte_mais_retire_lacces(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, [
            'name' => 'Paul', 'email' => 'paul4@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur);

        $membre = $magasin->membres()->where('user_id', $vendeur->id)->firstOrFail();
        app(RetirerMembreMagasinAction::class)->handle($membre);

        $this->assertNotNull(User::find($vendeur->id), 'Le compte utilisateur doit survivre.');
        $this->assertFalse($vendeur->fresh()->appartientA($magasin));
    }

    /**
     * Le compte retiré n'appartient plus à aucun magasin :
     * model_has_permissions ne garde rien de lui. La liste qu'il perd
     * reste inscrite au journal des événements (JET 130, voir
     * JournalEvenementsTest::test_le_retrait_d_un_membre_inscrit_les_droits_qu_il_perd).
     */
    public function test_retirer_un_membre_retire_ses_autorisations(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Paul', 'email' => 'paul5@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::EQUIPE_VIEW]);

        app(RetirerMembreMagasinAction::class)->handle($magasin->membres()->where('user_id', $vendeur->id)->firstOrFail());

        $this->assertDatabaseMissing('model_has_permissions', ['model_type' => $vendeur->getMorphClass(), 'model_id' => $vendeur->id]);
    }

    /**
     * Retiré de l'équipe, il perd ses notifications à propos du magasin,
     * qui ne le regardent plus.
     */
    public function test_retirer_un_membre_efface_ses_notifications(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $comptable = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Comptable);
        Notification::send($comptable, new FacturesPasseesEnRetard($magasin, ['F-A-1']));

        app(RetirerMembreMagasinAction::class)->handle($magasin->membres()->where('user_id', $comptable->id)->firstOrFail());

        $this->assertSame(0, $comptable->notifications()->count());
    }

    /**
     * Suspendu, un comptable ne peut plus ouvrir le magasin : sa cloche
     * n'en montre plus rien. Un simple changement de rôle ne touche pas à
     * ses notifications.
     */
    public function test_suspendre_un_membre_efface_ses_notifications(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $comptable = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Claire', 'email' => 'claire@cabinet.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Comptable);
        Notification::send($comptable, new FacturesPasseesEnRetard($magasin, ['F-A-1']));
        Notification::send($comptable, new FacturesPasseesEnRetard($magasin, ['F-A-2']));
        $membre = $magasin->membres()->where('user_id', $comptable->id)->firstOrFail();

        app(MettreAJourMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $membre, RoleMagasin::Administrateur, StatutMembreMagasin::Actif);
        $this->assertSame(2, $comptable->notifications()->count());

        app(MettreAJourMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $membre->fresh(), RoleMagasin::Administrateur, StatutMembreMagasin::Suspendu);

        $this->assertSame(0, $comptable->notifications()->count());
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
     * Les autorisations données directement à l'utilisateur dans ce
     * magasin, par ordre alphabétique.
     *
     * @return list<string>
     */
    private function autorisations(User $utilisateur, Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs(
            $magasin,
            fn (): array => $utilisateur->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        );
    }
}
