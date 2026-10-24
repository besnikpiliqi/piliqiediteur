<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deux confirmations d'inscription du même SIREN au même instant, chacune
 * dans son processus, sur une vraie base PostgreSQL (voir
 * CreerSocieteAction) : la seconde passe les contrôles, aucune autre
 * inscription ne gardant encore ce SIREN en base (la transaction de la
 * première n'est pas terminée), puis attend, sur l'index
 * unique partiel des SIREN (societes_siren_unique), la transaction de la
 * première, et échoue avec le seul message du SIREN pris. Une seule
 * société, un seul propriétaire, un seul code ; le courrier de la seconde
 * ne part jamais, son insertion étant refusée avant l'envoi.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class InscriptionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_inscriptions_simultanees_du_meme_siren_n_en_creent_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $siren = SocieteFactory::sirenFictif();
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $siren): void {
            if ($concurrente === null && str_starts_with($requete->sql, 'insert into "societes"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($siren): void {
                    app(CreerSocieteAction::class)->handle($siren, 'seconde@exemple.fr', 'un-mot-de-passe-sur');
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        $premiere = app(CreerSocieteAction::class)->handle($siren, 'premiere@exemple.fr', 'un-mot-de-passe-sur');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(CreerSocieteAction::MESSAGE_SIREN_PRIS, $resultat->errorOutput());
        $this->assertSame([$premiere->id], Societe::query()->where('siren', $siren)->pluck('id')->all());
        $this->assertSame(['premiere@exemple.fr'], User::query()->pluck('email')->all());
        $this->assertSame([$premiere->id], CodeConfirmation::withoutGlobalScopes()->pluck('societe_id')->map(intval(...))->all());
    }
}
