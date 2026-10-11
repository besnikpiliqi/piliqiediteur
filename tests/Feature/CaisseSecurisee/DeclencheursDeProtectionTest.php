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
use RuntimeException;
use Tests\TestCase;

/**
 * Les déclencheurs de protection ne se posent que là où la base sait
 * refuser elle-même une modification, une suppression ou un vidage :
 * PostgreSQL et SQLite. La migration échoue partout ailleurs. Leur refus
 * des modifications et des suppressions est vérifié sur les tables de
 * l'application par RegistreFiscalTest et InalterabiliteTest (ajout seul),
 * par CreerMagasinActionTest (sans suppression) et par TraceEvenementTest
 * (effacement après expiration) ; celui du vidage l'est ici, sur une table
 * créée pour le test.
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
        ];
    }

    /**
     * Chaque protection et ce que la table doit refuser.
     *
     * @return array<string, array{0: Closure(string): void, 1: string}>
     */
    public static function protectionsEtExigences(): array
    {
        return array_merge_recursive(self::protections(), [
            'ajout seul' => ['refuser toute modification, toute suppression et tout vidage'],
            'sans suppression' => ['refuser toute suppression définitive et tout vidage'],
            'effacement après expiration' => ['refuser toute modification, tout vidage et toute suppression avant expiration'],
        ]);
    }

    /**
     * Ni TRUNCATE sous PostgreSQL, ni le DELETE en quoi le truncate() de
     * Laravel se traduit sous SQLite, ne vident une table protégée : le
     * refus vient de son déclencheur, dont le message commence par le nom
     * de la table.
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
     * MySQL, qui ne sait pas refuser un TRUNCATE par un déclencheur, n'est
     * plus pris en charge : la migration échoue au lieu de laisser la
     * table sans protection. La connexion MySQL n'est jamais ouverte ; la
     * connexion par défaut est rendue avant que RefreshDatabase n'annule
     * la transaction du test.
     *
     * @param  Closure(string): void  $poser
     */
    #[DataProvider('protectionsEtExigences')]
    public function test_la_migration_echoue_sur_un_pilote_de_base_non_pris_en_charge(Closure $poser, string $exigence): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        config(['database.connections.mysql_de_test' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'piliqi']]);
        DB::setDefaultConnection('mysql_de_test');

        try {
            $this->assertThrows(
                fn () => $poser('enregistrements_fiscaux'),
                RuntimeException::class,
                "La table enregistrements_fiscaux doit {$exigence} : le pilote de base de données « mysql » n'est pas pris en charge (PostgreSQL ou SQLite).",
            );
        } finally {
            DB::setDefaultConnection($connexionDuTest);
        }
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
