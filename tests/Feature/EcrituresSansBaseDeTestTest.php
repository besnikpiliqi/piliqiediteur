<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Tenancy\RequeteDuModeleTest;
use Tests\Fixtures\EcrituresDurables;
use Tests\TestCase;

/**
 * Un test dont la classe n'a pas de base de test n'écrit jamais en base
 * (voir TestCase::surveillerLesEcrituresSansBaseDeTest()) : ses lignes
 * resteraient dans la base que partagent les tests de son processus, et,
 * premier de son processus à toucher la base, il écrirait dans une base que
 * rien n'a encore migrée (« relation "societes" does not exist »), un échec
 * qui ne se voit que selon l'ordre des tests. Les écritures se relèvent ici
 * au-dessus de la transaction de RefreshDatabase, niveau de départ de la
 * surveillance.
 */
class EcrituresSansBaseDeTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_seules_les_ecritures_hors_transaction_ou_validees_sont_durables(): void
    {
        $ecritures = new EcrituresDurables($this->app->make('events'), DB::transactionLevel());

        DB::statement('create temporary table temoin_des_ecritures (valeur integer)');
        DB::insert('insert into temoin_des_ecritures values (1)');
        DB::transaction(function (): void {
            DB::insert('insert into temoin_des_ecritures values (2)');
            DB::transaction(fn () => DB::update('update temoin_des_ecritures set valeur = 3 where valeur = 2'));
        });
        DB::beginTransaction();
        DB::insert('insert into temoin_des_ecritures values (4)');
        DB::rollBack();
        DB::select('select valeur from temoin_des_ecritures for update');

        $this->assertSame([
            'create temporary table temoin_des_ecritures (valeur integer)',
            'insert into temoin_des_ecritures values (1)',
            'insert into temoin_des_ecritures values (2)',
            'update temoin_des_ecritures set valeur = 3 where valeur = 2',
        ], $ecritures->durables());
        $this->assertSame([1, 3], DB::table('temoin_des_ecritures')->orderBy('valeur')->pluck('valeur')->all());
    }

    /**
     * Une écriture annulée par un point de sauvegarde (une transaction
     * imbriquée qui échoue) n'est pas durable, même quand la transaction
     * qui l'entoure est validée ensuite ; une écriture validée dans une
     * transaction imbriquée, puis annulée avec la transaction qui
     * l'entoure, non plus.
     */
    public function test_une_ecriture_annulee_par_un_point_de_sauvegarde_ne_devient_pas_durable_a_la_validation_de_la_transaction_qui_l_entoure(): void
    {
        $ecritures = new EcrituresDurables($this->app->make('events'), DB::transactionLevel());

        DB::statement('create temporary table temoin_des_ecritures (valeur integer)');
        DB::transaction(function (): void {
            DB::insert('insert into temoin_des_ecritures values (1)');

            try {
                DB::transaction(function (): void {
                    DB::insert('insert into temoin_des_ecritures values (9)');

                    throw new RuntimeException('Transaction imbriquée annulée.');
                });
            } catch (RuntimeException) {
            }

            DB::transaction(fn () => DB::transaction(fn () => DB::insert('insert into temoin_des_ecritures values (2)')));
        });
        DB::beginTransaction();
        DB::transaction(fn () => DB::insert('insert into temoin_des_ecritures values (5)'));
        DB::rollBack();

        $this->assertSame([
            'create temporary table temoin_des_ecritures (valeur integer)',
            'insert into temoin_des_ecritures values (1)',
            'insert into temoin_des_ecritures values (2)',
        ], $ecritures->durables());
        $this->assertSame([1, 2], DB::table('temoin_des_ecritures')->orderBy('valeur')->pluck('valeur')->all());
    }

    public function test_un_test_sans_base_de_test_qui_a_ecrit_est_refuse_en_nommant_ses_ecritures(): void
    {
        $ecritures = new EcrituresDurables($this->app->make('events'), DB::transactionLevel());

        $this->assertNull($ecritures->refusSansBaseDeTest('Un test qui n\'écrit pas'));

        DB::select('select 1');
        DB::statement('create temporary table temoin_des_ecritures (valeur integer)');

        $this->assertSame(
            RequeteDuModeleTest::class."::test_exemple écrit en base sans base de test : sa classe doit utiliser RefreshDatabase (ou DatabaseMigrations), sinon ses lignes restent dans la base que partagent les tests de son processus, et, premier de son processus à la toucher, il écrit dans une base que rien n'a encore migrée. Écritures :\ncreate temporary table temoin_des_ecritures (valeur integer)",
            $ecritures->refusSansBaseDeTest(RequeteDuModeleTest::class.'::test_exemple'),
        );
    }
}
