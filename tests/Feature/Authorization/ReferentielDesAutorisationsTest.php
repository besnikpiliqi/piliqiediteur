<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * La base ne connaît que les autorisations du registre (voir
 * PermissionRegistry et AutorisationsSociete) : chacune est une ligne d'un
 * référentiel que la migration seule inscrit, et chaque case la référence
 * par une clé étrangère. Une autorisation inconnue, ou de la mauvaise
 * sorte, ne s'enregistre ni par l'application ni par une écriture directe,
 * et une autorisation qui porte des cases ne disparaît pas du référentiel.
 * Le paquet de permissions qui les gardait autrefois n'a laissé aucune
 * trace.
 */
class ReferentielDesAutorisationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_referentiel_des_autorisations_est_exactement_celui_du_registre(): void
    {
        $magasin = DB::table('referentiel_autorisations_magasin')->orderBy('cle')->pluck('cle')->all();
        $societe = DB::table('referentiel_autorisations_societe')->orderBy('cle')->pluck('cle')->all();

        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $magasin);
        $this->assertCount(count(PermissionRegistry::toutes()), $magasin);
        $this->assertEqualsCanonicalizing(AutorisationsSociete::toutes(), $societe);
        $this->assertCount(count(AutorisationsSociete::toutes()), $societe);
    }

    /**
     * Par l'action, une autorisation inconnue est refusée avant toute
     * écriture : l'ancienne case reste. Par une écriture directe, la clé
     * étrangère du référentiel la refuse.
     */
    public function test_une_autorisation_inconnue_ne_s_enregistre_jamais(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $administrateur = $this->administrateur($magasin);
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->assertThrows(
            fn () => app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::FACTURE_VIEW, 'facture.tout_faire']]]),
            InvalidArgumentException::class,
            'Autorisation inconnue : facture.tout_faire',
        );
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $affectation->autorisations());

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation->id, 'autorisation' => 'facture.tout_faire'])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_societe')->insert(['societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'autorisation' => 'societe.tout_faire'])), QueryException::class);

        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $affectation->autorisations());
        $this->assertSame([], $administrateur->refresh()->autorisationsDeSociete());
    }

    /**
     * Une autorisation de société ne se coche jamais comme case d'un
     * magasin, ni une autorisation de magasin comme autorisation de
     * société : ni par les actions, ni par une écriture directe.
     */
    public function test_une_autorisation_de_societe_ne_se_coche_jamais_dans_un_magasin_et_inversement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $administrateur = $this->administrateur($magasin);
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->assertThrows(
            fn () => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [AutorisationsSociete::RESPONSABLES_NOMMER]]]),
            InvalidArgumentException::class,
        );
        $this->assertThrows(
            fn () => app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, null, [], [PermissionRegistry::FACTURE_EMETTRE]),
            InvalidArgumentException::class,
        );
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation->id, 'autorisation' => AutorisationsSociete::MAGASINS_GERER])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_societe')->insert(['societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'autorisation' => PermissionRegistry::FACTURE_EMETTRE])), QueryException::class);

        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $affectation->autorisations());
        $this->assertSame([], $administrateur->refresh()->autorisationsDeSociete());
        $this->assertFalse(User::query()->where('email', 'alix@example.test')->exists());
    }

    /**
     * Une autorisation qui porte encore des cases ne se retire pas du
     * référentiel : aucun droit ne disparaît sans passer par le journal des
     * événements des magasins touchés.
     */
    public function test_une_cle_portee_par_des_cases_ne_se_supprime_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $this->administrateur($magasin, null, [AutorisationsSociete::PARAMETRES_GERER]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('referentiel_autorisations_magasin')->where('cle', PermissionRegistry::FACTURE_VIEW)->delete()), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('referentiel_autorisations_societe')->where('cle', AutorisationsSociete::PARAMETRES_GERER)->delete()), QueryException::class);

        $this->assertTrue(DB::table('referentiel_autorisations_magasin')->where('cle', PermissionRegistry::FACTURE_VIEW)->exists());
        $this->assertTrue(DB::table('referentiel_autorisations_societe')->where('cle', AutorisationsSociete::PARAMETRES_GERER)->exists());
    }

    /**
     * Le paquet spatie/laravel-permission est retiré : ni ses tables, ni
     * ses classes, ni sa configuration, ni sa ligne dans composer.json et
     * composer.lock.
     */
    public function test_aucune_table_ni_aucune_classe_de_spatie_ne_subsiste(): void
    {
        foreach (['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertFalse(class_exists('Spatie\\Permission\\PermissionRegistrar'));
        $this->assertFalse(class_exists('Spatie\\Permission\\Models\\Permission'));
        $this->assertFalse(trait_exists('Spatie\\Permission\\Traits\\HasRoles'));
        $this->assertNull(config('permission'));
        $this->assertFileDoesNotExist(config_path('permission.php'));
        $this->assertStringNotContainsString('spatie/laravel-permission', File::get(base_path('composer.json')));
        $this->assertStringNotContainsString('spatie/laravel-permission', File::get(base_path('composer.lock')));
    }
}
