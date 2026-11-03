<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Deux créations simultanées d'un magasin dans la même société, chacune
 * dans son processus, sur une vraie base PostgreSQL (voir
 * CreerEtablissementAction) : la seconde a passé ses contrôles avant que la
 * première n'enregistre son magasin, puis attend le verrou de la société
 * que tient la première ; elle refait alors ses contrôles et refuse le
 * code ou le SIRET déjà pris, avec le message de la validation, au lieu
 * d'heurter l'index unique de la base.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class CreerEtablissementConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function valeursPrisesParLaPremiereCreation(): array
    {
        return [
            'code' => ['code', CodeMagasinLibre::MESSAGE_DEJA_UTILISE],
            'SIRET' => ['nic', SiretsDesMagasins::MESSAGE_SIRET_RESERVE],
        ];
    }

    #[DataProvider('valeursPrisesParLaPremiereCreation')]
    public function test_deux_creations_simultanees_du_meme_code_ou_du_meme_siret_n_en_retiennent_qu_une(string $champ, string $message): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $societeId = $societe->id;
        $nicDuTest = substr(IdentifiantsInsee::avecSaCle($societe->siren.'0005'), 9);
        $concurrente = $champ === 'code'
            ? ['nom' => 'Paris bis', 'code' => 'PARIS', 'nic' => substr(IdentifiantsInsee::avecSaCle($societe->siren.'0006'), 9)]
            : ['nom' => 'Paris bis', 'code' => 'PARISB', 'nic' => $nicDuTest];
        $processus = null;
        DB::listen(function (QueryExecuted $requete) use (&$processus, $societeId, $concurrente): void {
            if ($processus === null && str_starts_with($requete->sql, 'insert into "magasins"')) {
                $processus = $this->demarrerUnProcessusConcurrent(static function () use ($societeId, $concurrente): void {
                    $societe = Societe::query()->findOrFail($societeId);
                    app(CreerEtablissementAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe, $concurrente);
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($processus);
            }
        });
        app(TenantContext::class)->clear();

        $magasin = app(CreerEtablissementAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe, ['nom' => 'Paris', 'code' => 'PARIS', 'nic' => $nicDuTest]);

        $this->assertInstanceOf(InvokedProcess::class, $processus);
        $resultat = $processus->wait();
        $this->assertTrue($resultat->failed());
        $this->assertSame(ValidationException::class.' : '.$message, $resultat->errorOutput());
        $this->assertSame([$magasin->id], Magasin::query()->where('societe_id', $societeId)->pluck('id')->all());
    }
}
