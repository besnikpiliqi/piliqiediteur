<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les gardes des droits en base (voir GardesDesDroits) sous deux écritures
 * simultanées, chacune dans son processus, sur une vraie base PostgreSQL :
 * chaque garde verrouille d'abord la ligne parente qu'elle lit (le compte
 * pour une affectation ou une autorisation de société, l'affectation pour
 * une case), si bien que la seconde écriture attend la première, puis la
 * voit et se refuse. Aucun état incohérent ne passe entre les deux, et
 * chaque test échoue sans le verrou de sa garde.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class GardesDesDroitsEnBaseConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Le test rétrograde un administrateur en membre (ses affectations
     * effacées, puis son rôle) pendant qu'un autre processus lui écrit une
     * affectation à tous les magasins : l'écriture attend la rétrogradation,
     * puis la base la refuse, le compte étant devenu membre.
     */
    public function test_une_retrogradation_et_une_affectation_globale_simultanees_ne_laissent_jamais_une_affectation_globale_a_un_membre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        $ligne = ['societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'magasin_id' => null, 'role' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
        $concurrente = $this->concurrencerDesQue('update "users" set "role_societe"', static function () use ($ligne): void {
            DB::table('affectations')->insert($ligne);
        });

        DB::transaction(function () use ($administrateur): void {
            DB::table('affectations')->where('user_id', $administrateur->id)->delete();
            DB::table('users')->where('id', $administrateur->id)->update(['role_societe' => RoleSociete::Membre->value]);
        });

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('seul un administrateur a une affectation sans rôle', $resultat->errorOutput());
        $this->assertTrue($administrateur->refresh()->estMembre());
        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->count());
    }

    /**
     * Le test affecte un administrateur sans affectation à un magasin
     * pendant qu'un autre processus l'affecte à tous les magasins : la
     * seconde écriture attend la première, la voit et se refuse. Une seule
     * reste.
     */
    public function test_deux_affectations_simultanees_globale_et_par_magasin_d_un_administrateur_n_en_retiennent_qu_une(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        DB::table('affectations')->where('user_id', $administrateur->id)->delete();
        $ligne = static fn (?int $magasinId): array => ['societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'magasin_id' => $magasinId, 'role' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
        $globale = $ligne(null);
        $concurrente = $this->concurrencerDesQue('insert into "affectations"', static function () use ($globale): void {
            DB::table('affectations')->insert($globale);
        });

        DB::transaction(fn () => DB::table('affectations')->insert($ligne($magasin->id)));

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('jamais aux deux', $resultat->errorOutput());
        $this->assertSame([$magasin->id], Affectation::query()->where('user_id', $administrateur->id)->pluck('magasin_id')->all());
    }

    /**
     * Le test promeut responsable un vendeur sans case pendant qu'un autre
     * processus coche une case sur son affectation : la case attend la
     * promotion (sa garde verrouille d'abord l'affectation), puis la base
     * la refuse, l'affectation étant devenue celle d'un responsable.
     */
    public function test_une_promotion_en_responsable_et_une_case_simultanees_ne_laissent_jamais_une_case_a_un_responsable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, []);
        $affectationId = Affectation::query()->where('user_id', $vendeur->id)->sole()->id;
        $concurrente = $this->concurrencerDesQue('update "affectations"', static function () use ($affectationId): void {
            DB::table('autorisations_affectations')->insert(['affectation_id' => $affectationId, 'autorisation' => PermissionRegistry::FACTURE_VIEW]);
        });

        DB::transaction(fn () => DB::table('affectations')->where('id', $affectationId)->update(['role' => RoleMagasin::Responsable->value]));

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('un responsable n\'a de case que celles que seul le propriétaire coche', $resultat->errorOutput());
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->whereKey($affectationId)->sole()->role);
        $this->assertSame(0, DB::table('autorisations_affectations')->where('affectation_id', $affectationId)->count());
    }

    /**
     * Le test rétrograde un administrateur en membre (ses affectations
     * effacées, puis son rôle) pendant qu'un autre processus lui accorde une
     * autorisation de société : l'autorisation attend la rétrogradation (sa
     * garde verrouille d'abord le compte), puis la base la refuse, le compte
     * étant devenu membre.
     */
    public function test_une_retrogradation_et_une_autorisation_de_societe_simultanees_ne_laissent_jamais_une_autorisation_a_un_membre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        $ligne = ['societe_id' => $magasin->societe_id, 'user_id' => $administrateur->id, 'autorisation' => AutorisationsSociete::RESPONSABLES_NOMMER];
        $concurrente = $this->concurrencerDesQue('update "users" set "role_societe"', static function () use ($ligne): void {
            DB::table('autorisations_societe')->insert($ligne);
        });

        DB::transaction(function () use ($administrateur): void {
            DB::table('affectations')->where('user_id', $administrateur->id)->delete();
            DB::table('users')->where('id', $administrateur->id)->update(['role_societe' => RoleSociete::Membre->value]);
        });

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('seul un administrateur a des autorisations de société', $resultat->errorOutput());
        $this->assertTrue($administrateur->refresh()->estMembre());
        $this->assertSame(0, DB::table('autorisations_societe')->where('user_id', $administrateur->id)->count());
    }

    /**
     * Démarre l'écriture concurrente dès que le test exécute cette requête,
     * dans sa transaction, et attend qu'elle attende un verrou du test.
     *
     * @param  Closure(): void  $operation
     * @return Closure(): ?InvokedProcess
     */
    private function concurrencerDesQue(string $debutDeRequete, Closure $operation): Closure
    {
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $debutDeRequete, $operation): void {
            if ($concurrente === null && str_starts_with($requete->sql, $debutDeRequete)) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operation);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        return static function () use (&$concurrente): ?InvokedProcess {
            return $concurrente;
        };
    }

    /**
     * @param  Closure(): ?InvokedProcess  $concurrente
     */
    private function attendre(Closure $concurrente): ProcessResult
    {
        $processus = $concurrente();
        $this->assertInstanceOf(InvokedProcess::class, $processus);

        return $processus->wait();
    }
}
