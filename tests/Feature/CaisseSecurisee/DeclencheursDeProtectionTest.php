<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les déclencheurs de protection font refuser par la base elle-même une
 * modification, une suppression ou un vidage. Leur refus des
 * modifications et des suppressions est vérifié sur les tables de
 * l'application par RegistreFiscalTest et InalterabiliteTest (ajout seul),
 * par CreerEtablissementActionTest (sans suppression), par
 * TraceEvenementTest (effacement après expiration) et par
 * VerificationSocieteTest (suppression après conservation, sur les codes
 * de courrier) ; celui du vidage et de la réécriture par une insertion en
 * conflit (INSERT ... ON CONFLICT DO UPDATE) l'est ici, sur une table créée
 * pour le test.
 */
class DeclencheursDeProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque protection, posée sur une table.
     *
     * @return array<string, array{0: Closure(string): void}>
     */
    public static function protections(): array
    {
        return [
            'ajout seul' => [static fn (string $table) => DeclencheursDeProtection::ajoutSeul($table)],
            'sans suppression' => [static fn (string $table) => DeclencheursDeProtection::sansSuppression($table)],
            'effacement après expiration' => [static fn (string $table) => DeclencheursDeProtection::effacementApresExpiration($table)],
            'suppression après conservation' => [static fn (string $table) => DeclencheursDeProtection::suppressionApresConservation($table, 'expire_le')],
        ];
    }

    /**
     * Chaque protection d'une table qui refuse toute modification, et son
     * refus.
     *
     * @return array<string, array{0: Closure(string): void, 1: string}>
     */
    public static function protectionsSansModification(): array
    {
        return [
            'ajout seul' => [static fn (string $table) => DeclencheursDeProtection::ajoutSeul($table), 'table_protegee : table en ajout seul, modification refusée'],
            'effacement après expiration' => [static fn (string $table) => DeclencheursDeProtection::effacementApresExpiration($table), 'table_protegee : modification refusée'],
        ];
    }

    /**
     * TRUNCATE ne vide pas une table protégée : le refus vient de son
     * déclencheur, dont le message commence par le nom de la table.
     *
     * @param  Closure(string): void  $poser
     */
    #[DataProvider('protections')]
    public function test_une_table_protegee_ne_se_vide_pas(Closure $poser): void
    {
        Schema::create('table_protegee', function (Blueprint $table) {
            $table->id();
            $table->string('valeur');
            $table->dateTime('expire_le')->nullable();
        });
        $poser('table_protegee');
        DB::table('table_protegee')->insert(['valeur' => 'conservée']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('table_protegee')->truncate()), QueryException::class, 'table_protegee : ');

        $this->assertSame(['conservée'], DB::table('table_protegee')->pluck('valeur')->all());
    }

    /**
     * Une insertion en conflit avec une ligne existante (INSERT ... ON
     * CONFLICT DO UPDATE) est une modification de cette ligne : une table
     * qui refuse toute modification la refuse, et la ligne reste telle
     * qu'elle a été écrite.
     *
     * @param  Closure(string): void  $poser
     */
    #[DataProvider('protectionsSansModification')]
    public function test_une_insertion_en_conflit_ne_reecrit_aucune_ligne_d_une_table_qui_refuse_toute_modification(Closure $poser, string $refus): void
    {
        Schema::create('table_protegee', function (Blueprint $table) {
            $table->id();
            $table->string('valeur');
            $table->dateTime('expire_le')->nullable();
        });
        $poser('table_protegee');
        DB::table('table_protegee')->insert(['id' => 1, 'valeur' => 'conservée']);

        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => DB::statement("INSERT INTO table_protegee (id, valeur) VALUES (1, 'remplacée') ON CONFLICT (id) DO UPDATE SET valeur = excluded.valeur")),
            QueryException::class,
            $refus,
        );

        $this->assertSame(['conservée'], DB::table('table_protegee')->pluck('valeur')->all());
    }

    public function test_un_nom_de_table_qui_n_est_pas_un_identifiant_simple_est_refuse(): void
    {
        $this->assertThrows(
            fn () => DeclencheursDeProtection::ajoutSeul('enregistrements_fiscaux; DROP TABLE magasins'),
            InvalidArgumentException::class,
            'Nom de table invalide pour un déclencheur : enregistrements_fiscaux; DROP TABLE magasins.',
        );
    }
}
