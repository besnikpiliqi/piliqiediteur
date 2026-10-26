<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Contracts\SourceDeHasard;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SourceDeHasardImposee;
use Tests\TestCase;

/**
 * Des créations de comptes et des changements de rôle simultanés, chacun
 * dans son processus, sur une vraie base PostgreSQL : deux tirages de la
 * même valeur se départagent sur l'index unique des identifiants, sans
 * interrompre la transaction de la seconde création ; deux demandes du même
 * jeton ne créent qu'un compte ; un magasin de rattachement ne s'écrit
 * qu'une fois ; la limite des créations du jour tient sous le verrou de
 * l'auteur.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class IdentifiantsConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const VALEUR_DISPUTEE = 48210937;

    private const VALEUR_DE_REPLI = 61530284;

    /**
     * Deux sociétés créent un compte au même instant et tirent la même
     * valeur : aucun registre commun, la course se joue sur l'index. La
     * seconde attend la première sur l'index, revient à son point de
     * sauvegarde, tire une autre valeur et aboutit : deux comptes, deux
     * identifiants distincts.
     */
    #[Group('concurrence')]
    public function test_deux_creations_simultanees_dans_deux_societes_qui_tirent_le_meme_identifiant_aboutissent_toutes_deux(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Première Boutique']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Seconde Boutique']);
        [$autreId, $autreProprietaireId] = [$autre->id, $this->proprietaireDe($autre)->id];
        $this->app->instance(SourceDeHasard::class, new SourceDeHasardImposee([self::VALEUR_DISPUTEE, self::VALEUR_DE_REPLI]));
        $concurrente = null;
        $this->concurrencerDesQueLaRequeteVerrouille('insert into "identifiants"', static function () use ($autreId, $autreProprietaireId): void {
            app()->instance(SourceDeHasard::class, new SourceDeHasardImposee([self::VALEUR_DISPUTEE, self::VALEUR_DE_REPLI]));
            $magasin = Magasin::query()->findOrFail($autreId);
            app(TenantContext::class)->runAs($magasin, fn () => app(CreerMembreAction::class)->handle(User::query()->findOrFail($autreProprietaireId), $magasin, ['name' => 'Second'], RoleMagasin::Vendeur));
        }, $concurrente);

        app(TenantContext::class)->runAs($magasin, fn () => app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Premier'], RoleMagasin::Vendeur));

        $this->attendreLaReussite($concurrente);
        $premier = User::query()->where('name', 'Premier')->sole();
        $second = User::query()->where('name', 'Second')->sole();
        $this->assertSame([(string) self::VALEUR_DISPUTEE, (string) self::VALEUR_DE_REPLI], [$premier->identifiantDeConnexion(), $second->identifiantDeConnexion()]);
        $this->assertSame([(int) $magasin->societe_id, (int) $autre->societe_id], [(int) $premier->societe_id, (int) $second->societe_id]);
        $this->assertSame(1, Affectation::query()->withoutGlobalScopes()->where('user_id', $second->id)->count());
    }

    /**
     * Deux demandes de création au même instant, du même auteur, avec le
     * même jeton d'écran et la même demande, dans le même magasin : elles
     * se suivent sur le registre du magasin, et la seconde relit puis rend
     * le compte de la première ; un seul compte existe.
     */
    #[Group('concurrence')]
    public function test_deux_creations_simultanees_du_meme_jeton_ne_creent_qu_un_compte(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $jeton = (string) Str::uuid();
        [$proprietaireId, $magasinId] = [$proprietaire->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $magasinId, $jeton): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            $compte = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerMembreAction::class)->handle(User::query()->findOrFail($proprietaireId), $magasin, ['name' => 'Paul Martin'], RoleMagasin::Vendeur, null, $jeton));
            fwrite(STDOUT, (string) $compte->getKey());
        }, $concurrente);

        $compte = app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul Martin'], RoleMagasin::Vendeur, null, $jeton);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame((string) $compte->id, $resultat->output());
        $this->assertSame(1, User::query()->where('societe_id', $magasin->societe_id)->where('name', 'Paul Martin')->count());
        $this->assertSame(1, Identifiant::query()->withoutGlobalScopes()->where('jeton_de_creation', $jeton)->count());
    }

    /**
     * Le propriétaire rétrograde au même instant, depuis deux écrans, un
     * administrateur né sans magasin, vers deux magasins différents : la
     * seconde rétrogradation attend la première sur le verrou du compte,
     * relit un compte déjà membre, et est refusée ; un seul magasin de
     * rattachement est écrit, et une seule affectation.
     */
    #[Group('concurrence')]
    public function test_deux_retrogradations_simultanees_d_un_administrateur_n_ecrivent_qu_un_magasin_de_rattachement(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin);
        [$proprietaireId, $administrateurId, $autreId] = [$proprietaire->id, $administrateur->id, $autre->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $administrateurId, $autreId): void {
            app(ChangerLeRoleDeSocieteAction::class)->versMembre(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($administrateurId), RoleMagasin::Vendeur, null, $autreId);
        }, $concurrente);

        app(ChangerLeRoleDeSocieteAction::class)->versMembre($proprietaire, $administrateur, RoleMagasin::Vendeur, null, $magasin->id);

        $this->assertStringContainsString(ChangerLeRoleDeSocieteAction::MESSAGE_DEJA_CE_ROLE, $this->attendreLEchec($concurrente));
        $this->assertSame([RoleSociete::Membre, (int) $magasin->id], [$administrateur->refresh()->role_societe, (int) $administrateur->magasinDeSonIdentifiant()?->id]);
        $this->assertSame([(int) $magasin->id], Affectation::query()->where('user_id', $administrateur->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Le même auteur crée deux comptes au même instant alors qu'il ne lui
     * en reste qu'un pour aujourd'hui : les deux créations se suivent sur
     * son verrou, la seconde recompte et est refusée ; la limite n'est
     * jamais dépassée.
     */
    #[Group('concurrence')]
    public function test_des_creations_simultanees_d_un_meme_auteur_ne_depassent_pas_la_limite_du_jour(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $limite = CreationDesComptes::limiteDuJour($magasin->societe);
        User::factory()->membreDe($magasin)->count($limite - 1)->create(['cree_par' => $proprietaire->id]);
        [$proprietaireId, $magasinId] = [$proprietaire->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $magasinId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->runAs($magasin, fn () => app(CreerMembreAction::class)->handle(User::query()->findOrFail($proprietaireId), $magasin, ['name' => 'Second'], RoleMagasin::Vendeur, null, (string) Str::uuid()));
        }, $concurrente);

        app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Premier'], RoleMagasin::Vendeur, null, (string) Str::uuid());

        $this->assertStringContainsString(CreationDesComptes::messageDeLaLimite($limite), $this->attendreLEchec($concurrente));
        $this->assertSame($limite, User::query()->where('cree_par', $proprietaire->id)->count());
        $this->assertFalse(User::query()->where('name', 'Second')->exists());
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test a exécuté
     * une requête qui commence ainsi (et tient donc ce qu'elle verrouille,
     * jusqu'à la fin de sa transaction), puis attend qu'elle attende un
     * verrou que tient le test.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLaRequeteVerrouille(string $debutDeLaRequete, Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $operationConcurrente, $connexionDuTest, $debutDeLaRequete): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, $debutDeLaRequete)) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
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
