<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Actions\AfficherLIdentifiantAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Contracts\SourceDeHasard;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\GenerateurDIdentifiants;
use App\Domain\Tenancy\Services\SourceDeHasardCryptographique;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Les identifiants de connexion (voir GenerateurDIdentifiants et la table
 * identifiants) : 8 chiffres tirés au hasard, uniques dans tout le
 * logiciel, jamais triviaux, jamais modifiés ni redonnés, gardés par la
 * base ; et la création des comptes par la société, sans adresse, avec son
 * jeton d'écran et sa limite du jour.
 */
class IdentifiantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_compte_recoit_un_identifiant_de_huit_chiffres_dont_le_premier_n_est_pas_zero(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $comptes = [
            $this->proprietaireDe($magasin),
            $this->administrateur($magasin),
            $this->expertComptable($magasin),
            $this->responsable($magasin),
            $this->membre($magasin, RoleMagasin::Vendeur),
            $this->membre($magasin, RoleMagasin::Observateur),
        ];

        foreach ($comptes as $compte) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/D', (string) $compte->identifiantDeConnexion(), $compte->role_societe->value);
        }
        $this->assertSame(count($comptes), count(array_unique(array_map(fn (User $compte): string => (string) $compte->identifiantDeConnexion(), $comptes))));
    }

    /**
     * À l'inscription, le propriétaire a déjà choisi son mot de passe : le
     * tirage écarte la valeur qui lui serait égale.
     */
    public function test_le_proprietaire_recoit_un_identifiant_a_son_inscription_different_de_son_mot_de_passe(): void
    {
        $source = $this->sourceQuiRend([48210937, 59321048]);

        $societe = app(CreerSocieteAction::class)->handle(['siren' => SocieteFactory::sirenFictif()], ['name' => 'Jeanne Dupont', 'email' => 'jeanne@example.test', 'password' => '48210937']);

        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->assertSame('59321048', $proprietaire->identifiantDeConnexion());
        $this->assertTrue(Hash::check('48210937', $proprietaire->password));
        $this->assertFalse($proprietaire->mot_de_passe_est_l_identifiant);
        $this->assertCount(2, $source->appels);
    }

    public function test_le_conteneur_lie_la_source_de_hasard_a_random_int(): void
    {
        $source = app(SourceDeHasard::class);

        $this->assertInstanceOf(SourceDeHasardCryptographique::class, $source);
        foreach (range(1, 200) as $tirage) {
            $valeur = $source->entier(GenerateurDIdentifiants::PLUS_PETIT, GenerateurDIdentifiants::PLUS_GRAND);
            $this->assertGreaterThanOrEqual(GenerateurDIdentifiants::PLUS_PETIT, $valeur);
            $this->assertLessThanOrEqual(GenerateurDIdentifiants::PLUS_GRAND, $valeur);
        }
    }

    /**
     * Chaque identifiant écrit est une valeur rendue par la source, un
     * appel par essai, avec les mêmes bornes, quels que soient la société,
     * le magasin, le rôle et l'ordre de création.
     */
    public function test_le_generateur_ne_tire_que_de_la_source_un_tirage_par_essai(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $source = $this->sourceQuiRend([73519046, 20864517, 95173208, 41620937]);

        $comptes = [
            $this->membre($magasinB, RoleMagasin::Vendeur),
            $this->administrateur($magasinA),
            $this->expertComptable($magasinB),
            $this->membre($magasinA, RoleMagasin::Responsable),
        ];

        $this->assertSame(['73519046', '20864517', '95173208', '41620937'], array_map(fn (User $compte): string => (string) $compte->identifiantDeConnexion(), $comptes));
        $this->assertSame(array_fill(0, 4, [GenerateurDIdentifiants::PLUS_PETIT, GenerateurDIdentifiants::PLUS_GRAND]), $source->appels);
    }

    /**
     * Contrôle de fumée seulement : 1 000 tirages de la source ne se
     * suivent jamais (aucun égal au précédent plus un) et leurs écarts ne
     * sont pas constants.
     */
    public function test_les_identifiants_tires_a_la_suite_ne_se_suivent_jamais(): void
    {
        $source = app(SourceDeHasard::class);
        $tirages = array_map(fn (): int => $source->entier(GenerateurDIdentifiants::PLUS_PETIT, GenerateurDIdentifiants::PLUS_GRAND), range(1, 1000));
        $ecarts = [];

        for ($rang = 1; $rang < count($tirages); $rang++) {
            $this->assertNotSame($tirages[$rang - 1] + 1, $tirages[$rang]);
            $ecarts[] = $tirages[$rang] - $tirages[$rang - 1];
        }

        $this->assertGreaterThan(900, count(array_unique($ecarts)));
    }

    public function test_aucun_identifiant_trivial_n_est_jamais_tire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $source = $this->sourceQuiRend([11111111, 12345678, 98765432, 48210937]);

        $compte = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertSame('48210937', $compte->identifiantDeConnexion());
        $this->assertCount(4, $source->appels);
        foreach (['11111111', '99999999', '12345678', '23456789', '98765432', '87654321', '76543210'] as $trivial) {
            $this->assertTrue(GenerateurDIdentifiants::estTrivial($trivial), $trivial);
        }
        $this->assertFalse(GenerateurDIdentifiants::estTrivial('48210937'));
    }

    /**
     * Les identifiants sont uniques dans tout le logiciel : la base refuse
     * un doublon, même dans une autre société.
     */
    public function test_deux_comptes_n_ont_jamais_le_meme_identifiant_meme_dans_deux_societes(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $deA = $this->membre($magasinA, RoleMagasin::Vendeur);
        $this->membre($magasinB, RoleMagasin::Vendeur);
        $sansIdentifiant = DB::table('users')->insertGetId($this->ligneDeCompte($magasinB->societe, RoleSociete::Administrateur->value, null));

        $tous = Identifiant::withoutGlobalScopes()->pluck('identifiant')->all();
        $this->assertSame(count($tous), count(array_unique($tous)));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->insert(['identifiant' => $deA->identifiantDeConnexion(), 'societe_id' => $magasinB->societe_id, 'user_id' => $sansIdentifiant, 'created_at' => now()])), UniqueConstraintViolationException::class);
        $this->assertSame(1, Identifiant::withoutGlobalScopes()->where('identifiant', $deA->identifiantDeConnexion())->count());
    }

    /**
     * Une valeur déjà prise, tirée pour un compte d'une autre société, est
     * écartée dans un point de sauvegarde : un autre nombre se tire, et la
     * création va jusqu'au bout, sans interrompre sa transaction (sous
     * PostgreSQL comme sous SQLite).
     */
    public function test_un_doublon_d_identifiant_est_retire_sans_interrompre_la_creation(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $deA = $this->membre($magasinA, RoleMagasin::Vendeur);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $source = $this->sourceQuiRend([(int) $deA->identifiantDeConnexion(), 48210937]);

        $compte = app(TenantContext::class)->runAs($magasinB, fn (): User => app(CreerMembreAction::class)->handle($this->proprietaireDe($magasinB), $magasinB, ['name' => 'Paul'], RoleMagasin::Vendeur));

        $this->assertSame('48210937', $compte->identifiantDeConnexion());
        $this->assertCount(2, $source->appels);
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $compte->id)->sole()->role);
        $this->assertSame($compte->id, collect($this->modificationsDesDroits($magasinB))->last()['membre']['id']);
        $this->assertSame((int) $deA->id, (int) Identifiant::withoutGlobalScopes()->where('identifiant', $deA->identifiantDeConnexion())->value('user_id'));
    }

    /**
     * La base refuse toute modification de l'identifiant ou de son compte,
     * et toute suppression, même écrite directement.
     */
    public function test_un_identifiant_ne_se_modifie_ni_ne_s_efface_en_base(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $compte = $this->membre($magasin, RoleMagasin::Vendeur);
        $autre = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = $compte->identifiantDeConnexion();
        $administrateur = $this->administrateur($magasin);

        foreach ([['identifiant' => '48210937'], ['user_id' => $autre->id], ['jeton_de_creation' => (string) Str::uuid()]] as $modification) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $compte->id)->update($modification)), QueryException::class);
        }
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $compte->id)->delete()), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->insert(['identifiant' => '01234567', 'societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'created_at' => now()])), QueryException::class);

        $this->assertSame($identifiant, $compte->refresh()->identifiantDeConnexion());
    }

    public function test_un_identifiant_n_est_jamais_redonne_meme_apres_le_retrait_du_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $retire->identifiantDeConnexion();
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $retire);
        $this->sourceQuiRend([(int) $identifiant, 48210937]);

        $nouveau = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertSame('48210937', $nouveau->identifiantDeConnexion());
        $this->assertSame($identifiant, $retire->refresh()->identifiantDeConnexion());
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('identifiant', $identifiant)->delete()), QueryException::class);
    }

    /**
     * Le magasin de rattachement s'écrit une seule fois, de nul à une
     * valeur, pour un compte né sans magasin ; celui d'un compte de membre
     * ne change jamais.
     */
    public function test_le_magasin_d_un_identifiant_s_ecrit_une_seule_fois(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        DB::table('identifiants')->where('user_id', $administrateur->id)->update(['magasin_id' => $magasin->id]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $administrateur->id)->update(['magasin_id' => $autre->id])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $administrateur->id)->update(['magasin_id' => null])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $vendeur->id)->update(['magasin_id' => $autre->id])), QueryException::class);
        $this->assertSame([(int) $magasin->id, (int) $magasin->id], [(int) $administrateur->refresh()->magasinDeSonIdentifiant()?->id, (int) $vendeur->refresh()->magasinDeSonIdentifiant()?->id]);
    }

    public function test_la_base_refuse_un_membre_affecte_a_deux_magasins_ou_hors_du_magasin_de_son_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $sansAffectation = User::factory()->membreDe($magasin)->create();
        $ligne = fn (User $compte, Magasin $chez): array => ['societe_id' => $magasin->societe_id, 'user_id' => $compte->id, 'magasin_id' => $chez->id, 'role' => RoleMagasin::Vendeur->value, 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert($ligne($vendeur, $autre))), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert($ligne($sansAffectation, $autre))), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->where('user_id', $vendeur->id)->update(['magasin_id' => $autre->id])), QueryException::class);
        DB::table('affectations')->insert($ligne($sansAffectation, $magasin));

        $this->assertSame([[(int) $magasin->id], [(int) $magasin->id]], [
            Affectation::withoutGlobalScopes()->where('user_id', $vendeur->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all(),
            Affectation::withoutGlobalScopes()->where('user_id', $sansAffectation->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all(),
        ]);
    }

    /**
     * La base refuse un propriétaire sans adresse de connexion, à
     * l'insertion, au passage au rôle de propriétaire et à la mise à nul de
     * son adresse, et deux propriétaires de la même adresse.
     */
    public function test_la_base_refuse_un_proprietaire_sans_adresse_et_deux_proprietaires_de_la_meme_adresse(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'A']));
        $autreProprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'B']));
        $sansProprietaire = Societe::factory()->create();
        $membreSansAdresse = DB::table('users')->insertGetId($this->ligneDeCompte($sansProprietaire, RoleSociete::Membre->value, null));

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->insert($this->ligneDeCompte(Societe::factory()->create(), RoleSociete::Proprietaire->value, null))), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $membreSansAdresse)->update(['role_societe' => RoleSociete::Proprietaire->value])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $proprietaire->id)->update(['email' => null])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $autreProprietaire->id)->update(['email' => $proprietaire->email])), UniqueConstraintViolationException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->insert($this->ligneDeCompte(Societe::factory()->create(), RoleSociete::Proprietaire->value, $proprietaire->email))), UniqueConstraintViolationException::class);

        DB::table('users')->where('id', $membreSansAdresse)->update(['email' => $proprietaire->email]);
        $this->assertSame([$proprietaire->email, $autreProprietaire->email], [$proprietaire->refresh()->email, $autreProprietaire->refresh()->email]);
    }

    /**
     * La base refuse un propriétaire dont le mot de passe est encore son
     * identifiant : au passage au rôle de propriétaire comme au passage du
     * drapeau à vrai.
     */
    public function test_la_base_refuse_un_proprietaire_dont_le_mot_de_passe_est_l_identifiant(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'A']));
        $societe = Societe::factory()->create();
        $compte = DB::table('users')->insertGetId([...$this->ligneDeCompte($societe, RoleSociete::Membre->value, 'futur@example.test'), 'mot_de_passe_est_l_identifiant' => true]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $compte)->update(['role_societe' => RoleSociete::Proprietaire->value])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $proprietaire->id)->update(['mot_de_passe_est_l_identifiant' => true])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->insert([...$this->ligneDeCompte(Societe::factory()->create(), RoleSociete::Proprietaire->value, 'autre@example.test'), 'mot_de_passe_est_l_identifiant' => true])), QueryException::class);

        DB::table('users')->where('id', $compte)->update(['mot_de_passe_est_l_identifiant' => false]);
        DB::table('users')->where('id', $compte)->update(['role_societe' => RoleSociete::Proprietaire->value]);
        $this->assertFalse($proprietaire->refresh()->mot_de_passe_est_l_identifiant);
    }

    /**
     * La base refuse un rôle de société pour un compte d'entraînement, et le
     * rattachement après coup d'un compte réel à la démonstration.
     */
    public function test_la_base_refuse_un_role_de_societe_pour_un_compte_d_entrainement_et_le_rattachement_tardif_a_la_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($reel);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
        $entrainement = app(TenantContext::class)->runAs($demonstration, fn (): User => app(CreerMembreAction::class)->handle($societe->proprietaire, $demonstration, ['name' => 'Essai'], RoleMagasin::Vendeur));
        DB::table('affectations')->where('user_id', $entrainement->id)->delete();

        foreach ([RoleSociete::Administrateur, RoleSociete::ExpertComptable] as $role) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $entrainement->id)->update(['role_societe' => $role->value])), QueryException::class);
        }
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $administrateur->id)->update(['magasin_id' => $demonstration->id])), QueryException::class);

        $this->assertSame([RoleSociete::Membre, true, null], [$entrainement->refresh()->role_societe, $entrainement->estUnCompteDEntrainement(), $administrateur->refresh()->magasinDeSonIdentifiant()]);
    }

    /**
     * Aucun compte créé par la société ne reçoit d'adresse de qui le crée :
     * une adresse glissée dans la demande, à l'action comme par une requête
     * Livewire forgée, est ignorée.
     */
    public function test_aucun_compte_cree_par_la_societe_ne_recoit_d_adresse_de_son_createur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $comptes = [
            app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Membre', 'email' => 'membre@example.test'], RoleMagasin::Vendeur),
            app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Administrateur', 'email' => 'admin@example.test'], null, [], []),
            app(CreerExpertComptableAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Expert', 'email' => 'expert@example.test']),
        ];

        $this->dansLeMagasin($magasin, $proprietaire);
        $this->forgerLAction(Livewire::test(ListEquipeDuMagasin::class), 'creerUnCompte', ['jeton' => (string) Str::uuid(), 'name' => 'Forgé', 'email' => 'forge@example.test', 'role' => RoleMagasin::Vendeur->value, 'autorisations' => [], 'autorisations_vente' => []]);
        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        $this->forgerLAction(Livewire::test(ListMembres::class), 'creerUnAdministrateur', ['jeton' => (string) Str::uuid(), 'name' => 'Admin forgé', 'email' => 'admin-forge@example.test', 'portee' => 'tous', 'autorisations_societe' => [], 'autorisations_magasin' => [], 'autorisations_magasin_vente' => []]);
        $comptes[] = User::query()->where('name', 'Forgé')->sole();
        $comptes[] = User::query()->where('name', 'Admin forgé')->sole();

        foreach ($comptes as $compte) {
            $this->assertNull($compte->refresh()->email, $compte->name);
        }
        $this->assertSame(0, User::query()->where('email', 'like', '%@example.test')->where('role_societe', '<>', RoleSociete::Proprietaire->value)->count());
    }

    /**
     * La même demande de création répétée après une réponse perdue, avec le
     * même jeton d'écran, rend le compte déjà créé : un seul compte, une
     * seule ligne d'identifiant, l'identifiant réaffiché à son auteur, et la
     * limite du jour non entamée par la répétition.
     */
    public function test_une_creation_de_compte_repetee_ne_cree_qu_un_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $jeton = (string) Str::uuid();

        $premier = app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur, null, $jeton);
        $repete = app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur, null, $jeton);

        $this->assertTrue($premier->is($repete));
        $this->assertSame(1, User::query()->where('name', 'Paul')->count());
        $this->assertSame(1, Identifiant::withoutGlobalScopes()->where('jeton_de_creation', $jeton)->count());
        $this->assertSame(1, User::query()->where('cree_par', $proprietaire->id)->count());

        $this->dansLeMagasin($magasin, $proprietaire);
        $jetonDeLEcran = (string) Str::uuid();
        $creer = fn () => Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['name' => 'Léa', 'role' => RoleMagasin::Vendeur->value])
            ->setActionData(['jeton' => $jetonDeLEcran])
            ->callMountedAction()
            ->assertActionMounted('compteCree');
        $creer();
        $lea = User::query()->where('name', 'Léa')->sole();
        $creer()->assertSee("Identifiant : {$lea->identifiantDeConnexion()}");

        $this->assertSame(1, User::query()->where('name', 'Léa')->count());
        $this->assertSame(2, User::query()->where('cree_par', $proprietaire->id)->count());
    }

    public function test_le_meme_jeton_de_creation_avec_une_autre_demande_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $jeton = (string) Str::uuid();
        app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur, null, $jeton);

        foreach ([[['name' => 'Léa'], RoleMagasin::Vendeur], [['name' => 'Paul'], RoleMagasin::Observateur]] as [$identite, $role]) {
            $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin, $identite, $role, null, $jeton), LogicException::class, CreationDesComptes::MESSAGE_DEMANDE_DEJA_SERVIE);
        }

        $this->assertSame(['Paul'], User::query()->whereIn('name', ['Paul', 'Léa'])->pluck('name')->all());
    }

    /**
     * Un jeton de création rejoué par un autre auteur : ses droits se
     * contrôlent d'abord (un vendeur est refusé comme toujours), puis la
     * réponse dit seulement que la demande a déjà servi, sans
     * l'identifiant ni le nom du compte créé.
     */
    public function test_un_jeton_de_creation_rejoue_par_un_autre_auteur_ne_revele_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $jeton = (string) Str::uuid();
        $cree = app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Paul Secret'], RoleMagasin::Vendeur, null, $jeton);

        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($vendeur, $magasin, ['name' => 'Paul Secret'], RoleMagasin::Vendeur, null, $jeton), AuthorizationException::class);
        try {
            app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul Secret'], RoleMagasin::Vendeur, null, $jeton);
            $this->fail('Le jeton rejoué par un autre auteur aurait dû être refusé.');
        } catch (LogicException $refus) {
            $this->assertSame(CreationDesComptes::MESSAGE_DEMANDE_DEJA_SERVIE, $refus->getMessage());
        }

        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['name' => 'Paul Secret', 'role' => RoleMagasin::Vendeur->value])
            ->set('mountedActionsData.0.autorisations', [])
            ->setActionData(['jeton' => $jeton, 'autorisations' => RoleMagasin::Vendeur->permissionsParDefaut(), 'autorisations_vente' => []])
            ->callMountedAction()
            ->assertNotified(CreationDesComptes::MESSAGE_DEMANDE_DEJA_SERVIE)
            ->assertActionNotMounted('compteCree');
        $this->assertStringNotContainsString((string) $cree->identifiantDeConnexion(), CreationDesComptes::MESSAGE_DEMANDE_DEJA_SERVIE);
        $this->assertSame(1, User::query()->where('name', 'Paul Secret')->count());
    }

    /**
     * Chaque auteur crée au plus 20 comptes par jour, plus 10 par magasin
     * réel ouvert de la société (ni la démonstration ni un magasin fermé
     * ne comptent) ; les créations d'un autre auteur ne comptent pas pour
     * lui.
     */
    public function test_la_limite_du_jour_compte_par_auteur_et_suit_le_nombre_de_magasins(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);

        $this->assertSame(30, CreationDesComptes::limiteDuJour($societe));
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $this->assertSame(40, CreationDesComptes::limiteDuJour($societe));
        app(FermerMagasinAction::class)->handle($proprietaire, $autre->fresh(), MotifFermeture::Temporaire, 'password');
        $this->assertSame(30, CreationDesComptes::limiteDuJour($societe));

        $dejaCrees = User::query()->where('cree_par', $proprietaire->id)->count();
        User::factory()->membreDe($magasin)->count(30 - $dejaCrees)->create(['cree_par' => $proprietaire->id]);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'De trop'], RoleMagasin::Vendeur), ValidationException::class, CreationDesComptes::messageDeLaLimite(30));
        $this->assertTrue(app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Par le responsable'], RoleMagasin::Vendeur)->exists);

        $this->travel(1)->day();
        $this->assertTrue(app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Le lendemain'], RoleMagasin::Vendeur)->exists);
    }

    /**
     * Un compte retiré du même nom dans le même magasin est proposé avant
     * la création, jamais celui d'un autre magasin ; « Créer un nouveau
     * compte » reste possible.
     */
    public function test_un_compte_retire_du_meme_nom_dans_le_meme_magasin_est_propose_avant_la_creation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $ici = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Jean Durand']);
        $ailleurs = $this->membre($autre, RoleMagasin::Vendeur, null, ['name' => 'Jean Durand']);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $ici);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $ailleurs);

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['name' => 'JEAN DURAND ', 'role' => RoleMagasin::Vendeur->value])
            ->assertFormFieldExists('reprendre', 'mountedActionForm', fn (Radio $champ): bool => array_keys($champ->getOptions()) === [(int) $ici->id, 'nouveau'])
            ->setActionData(['reprendre' => 'nouveau'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionMounted('compteCree');

        $this->assertSame(3, User::query()->where('name', 'like', 'Jean Durand%')->orWhere('name', 'JEAN DURAND')->count());
        $this->assertTrue($ici->refresh()->estRetire());
    }

    /**
     * L'identifiant ne s'écrit jamais au journal d'audit, dans ses traces,
     * au journal des événements, dans une notification, dans un e-mail ni
     * dans les journaux techniques, en clair, à travers une création, un
     * accès redonné, un identifiant affiché par un administrateur, des
     * connexions manquées et une connexion réussie.
     */
    public function test_l_identifiant_n_entre_ni_au_journal_d_audit_ni_dans_un_e_mail_ni_dans_les_journaux_techniques(): void
    {
        Mail::fake();
        $journaux = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$journaux): void {
            $journaux[] = $message->message.' '.json_encode($message->context);
        });
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::ACCES_REDONNER]);
        [$compte, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        app(AfficherLIdentifiantAction::class)->handle($administrateur, $compte);
        $this->choisirSonMotDePasse($compte);
        app(RedonnerLAccesAction::class)->handle($this->proprietaireDe($magasin), $compte->refresh(), false);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        foreach (range(1, 3) as $essai) {
            Livewire::test(Connexion::class)->fillForm(['login' => $identifiant, 'password' => 'pas-le-bon'])->call('authenticate');
        }
        $this->choisirSonMotDePasse($compte->refresh());
        Livewire::test(Connexion::class)->fillForm(['login' => $identifiant, 'password' => self::MOT_DE_PASSE_CHOISI])->call('authenticate')->assertHasNoErrors();

        foreach ([
            'journal_audit' => ['anciennes_valeurs', 'nouvelles_valeurs'],
            'traces_audit' => ['ip', 'agent_utilisateur'],
            'enregistrements_fiscaux' => ['contenu'],
            'notifications' => ['data'],
        ] as $table => $colonnes) {
            foreach ($colonnes as $colonne) {
                $this->assertSame(0, DB::table($table)->where($colonne, 'like', "%{$identifiant}%")->count(), "{$table}.{$colonne}");
            }
        }
        $this->assertNotEmpty(DB::table('journal_audit')->count());
        foreach (Mail::sent(Mailable::class) as $courriel) {
            $this->assertStringNotContainsString($identifiant, $courriel->render());
        }
        foreach ($journaux as $journal) {
            $this->assertStringNotContainsString($identifiant, $journal);
        }
    }

    /**
     * Le modèle Identifiant reste dans sa société (BelongsToSociete) : dans
     * le contexte d'une société, il ne lit que ses identifiants ; sans
     * contexte, il échoue fermé.
     */
    public function test_le_modele_identifiant_reste_dans_sa_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $deA = $this->membre($magasinA, RoleMagasin::Vendeur);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $deB = $this->membre($magasinB, RoleMagasin::Vendeur);
        $contexte = app(TenantContext::class);
        $contexte->clear();

        $vusDeA = $contexte->runAsSociete($magasinA->societe, fn (): array => Identifiant::query()->pluck('user_id')->map(fn ($id): int => (int) $id)->all());

        $this->assertContains((int) $deA->id, $vusDeA);
        $this->assertNotContains((int) $deB->id, $vusDeA);
        $this->assertSame([(int) $magasinA->societe_id], $contexte->runAsSociete($magasinA->societe, fn (): array => Identifiant::query()->pluck('societe_id')->unique()->map(fn ($id): int => (int) $id)->values()->all()));
        $this->assertThrows(fn () => Identifiant::query()->count());
    }

    /**
     * Une source de hasard qui rend ces valeurs dans l'ordre, puis toujours
     * la dernière, et retient ses appels ; liée à la place de la vraie.
     *
     * @param  list<int>  $valeurs
     */
    private function sourceQuiRend(array $valeurs): SourceDeHasard
    {
        $source = new class($valeurs) implements SourceDeHasard
        {
            /** @var list<array{0: int, 1: int}> */
            public array $appels = [];

            /**
             * @param  list<int>  $valeurs
             */
            public function __construct(private array $valeurs) {}

            public function entier(int $min, int $max): int
            {
                $this->appels[] = [$min, $max];

                return count($this->valeurs) > 1 ? array_shift($this->valeurs) : $this->valeurs[0];
            }
        };
        $this->app->instance(SourceDeHasard::class, $source);

        return $source;
    }

    /**
     * Une ligne de la table des comptes, écrite directement.
     *
     * @return array<string, mixed>
     */
    private function ligneDeCompte(Societe $societe, string $role, ?string $email): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'societe_id' => $societe->id,
            'role_societe' => $role,
            'statut' => 'actif',
            'name' => 'Écrit directement',
            'email' => $email,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
