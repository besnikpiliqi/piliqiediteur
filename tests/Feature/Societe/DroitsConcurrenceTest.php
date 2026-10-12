<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Deux modifications des droits simultanées, chacune dans son processus,
 * sur une vraie base PostgreSQL (voir JournalDesDroits) : la seconde
 * attend les verrous des registres et des comptes que tient la première,
 * puis relit l'auteur et le compte, et applique les règles à ce qu'elle
 * relit. Aucun droit retiré n'est jamais donné ensuite, et le journal des
 * événements reste cohérent.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class DroitsConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Deux responsables du même magasin changent au même instant les cases
     * du même vendeur : la seconde modification attend la première, puis
     * part de ce qu'elle a écrit, si bien que le journal des événements
     * enchaîne les droits sans trou.
     */
    public function test_deux_responsables_modifiant_le_meme_vendeur_se_suivent_et_le_jet_130_reste_coherent(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->responsable($magasin);
        $second = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLIENT_VIEW]);
        [$secondId, $vendeurId, $magasinId] = [$second->id, $vendeur->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($secondId, $vendeurId, $magasinId): void {
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($secondId), User::query()->findOrFail($vendeurId), [$magasinId => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::PRODUIT_VIEW]]]);
        }, $concurrente);

        app(ModifierMembreAction::class)->handle($premier, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]]]);

        $this->attendreLaReussite($concurrente);
        $siens = array_values(array_filter($this->modificationsDesDroits($magasin), fn (array $details): bool => $details['membre']['id'] === $vendeur->id));
        $this->assertSame([
            [null, [PermissionRegistry::CLIENT_VIEW]],
            [[PermissionRegistry::CLIENT_VIEW], [PermissionRegistry::FACTURE_VIEW]],
            [[PermissionRegistry::FACTURE_VIEW], [PermissionRegistry::PRODUIT_VIEW]],
        ], array_map(fn (array $details): array => [$details['avant']['permissions'] ?? null, $details['apres']['permissions']], $siens));
        $this->assertSame([PermissionRegistry::PRODUIT_VIEW], Affectation::query()->where('user_id', $vendeur->id)->sole()->autorisations());
    }

    /**
     * Deux créations au même instant avec la même adresse : la seconde
     * attend la première, puis reçoit la réponse neutre ; un seul compte
     * existe.
     */
    public function test_deux_creations_simultanees_avec_la_meme_adresse_n_en_retiennent_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        [$proprietaireId, $societeId, $magasinId] = [$proprietaire->id, $magasin->societe_id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $societeId, $magasinId): void {
            app(CreerMembreAction::class)->handle(User::query()->findOrFail($proprietaireId), Societe::query()->findOrFail($societeId), ['name' => 'Copie', 'email' => 'paul@example.test'], 'provisoire-des-tests', [$magasinId => ['role' => RoleMagasin::Observateur]]);
        }, $concurrente);

        app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $resultat = $this->attendreLEchec($concurrente);
        $this->assertStringContainsString(CreationDesComptes::MESSAGE_ADRESSE_INUTILISABLE, $resultat);
        $this->assertSame(['Paul'], User::query()->where('email', 'paul@example.test')->pluck('name')->all());
    }

    /**
     * Le propriétaire rétrograde un responsable pendant que celui-ci coche
     * toutes les cases d'un vendeur : la modification du responsable attend
     * la rétrogradation, relit son auteur, et est refusée.
     */
    public function test_un_retrait_des_droits_d_un_responsable_et_sa_modification_d_un_vendeur_simultanes_ne_donnent_jamais_un_droit_retire(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLIENT_VIEW]);
        [$responsableId, $vendeurId, $magasinId] = [$responsable->id, $vendeur->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($responsableId, $vendeurId, $magasinId): void {
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($responsableId), User::query()->findOrFail($vendeurId), [$magasinId => ['role' => RoleMagasin::Vendeur, 'autorisations' => PermissionRegistry::toutes()]]);
        }, $concurrente);

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $responsable, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertStringContainsString(PorteeDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame([PermissionRegistry::CLIENT_VIEW], Affectation::query()->where('user_id', $vendeur->id)->sole()->autorisations());
    }

    /**
     * Le propriétaire retire à un administrateur « nommer et retirer les
     * responsables » pendant que celui-ci nomme un responsable : la
     * nomination attend, relit l'administrateur, et est refusée.
     */
    public function test_un_retrait_de_l_autorisation_de_nommer_et_une_nomination_simultanees_ne_nomment_jamais_apres_le_retrait(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        [$administrateurId, $vendeurId, $magasinId] = [$administrateur->id, $vendeur->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($administrateurId, $vendeurId, $magasinId): void {
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($administrateurId), User::query()->findOrFail($vendeurId), [$magasinId => ['role' => RoleMagasin::Responsable]]);
        }, $concurrente);

        app(ModifierAdministrateurAction::class)->modifier($this->proprietaireDe($magasin), $administrateur, null, PermissionRegistry::modeleDeLAdministrateur(), []);

        $this->assertStringContainsString(PorteeDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
        $this->assertSame([], $administrateur->refresh()->autorisationsDeSociete());
    }

    private function attendreLaReussite(?InvokedProcess $concurrente): void
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
    }

    /**
     * Le processus concurrent a échoué : son message d'erreur.
     */
    private function attendreLEchec(?InvokedProcess $concurrente): string
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful(), 'L\'opération concurrente aurait dû être refusée.');

        return $resultat->errorOutput();
    }
}
