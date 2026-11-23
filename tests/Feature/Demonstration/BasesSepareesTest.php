<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Providers\SiteDePiliqiServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\DemonstrationTestCase;

/**
 * Sur le site de démonstration, chaque requête ne lit que le registre et
 * la base de sa copie, jamais la base du site réel (lot DEMO-COPIE, C.6,
 * N5) ; la base par défaut est la sentinelle vide, qui ne rend aucune
 * ligne à ce qui échapperait à l'ouverture de la copie.
 */
class BasesSepareesTest extends DemonstrationTestCase
{
    public function test_une_visite_ne_lit_que_le_registre_et_la_base_de_sa_copie(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $bases = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $requete) use (&$bases): void {
            $bases[$requete->connection->getDatabaseName()] = true;
        });

        $temoin = $this->entrerParLeLien($secret);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/app')->assertRedirect();
        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'etat', 'reference' => $copie->reference]))->assertOk();

        $this->assertSame(
            [self::nomDuRegistreDeTest(), (string) $copie->nom_de_la_base],
            array_values(array_intersect([self::nomDuRegistreDeTest(), (string) $copie->nom_de_la_base], array_keys($bases))),
        );
        $this->assertSame([], array_values(array_diff(array_keys($bases), [self::nomDuRegistreDeTest(), (string) $copie->nom_de_la_base])), 'Aucune requête ne part vers une autre base, celle du site réel moins que toute autre.');
    }

    public function test_la_base_sentinelle_ne_rend_aucune_ligne(): void
    {
        $this->assertSame(SiteDePiliqiServiceProvider::BASE_SENTINELLE, DB::connection()->getDatabaseName());
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_tables where schemaname = 'public'"), 'La base sentinelle n\'a aucune table.');

        $this->expectException(QueryException::class);
        DB::table('users')->count();
    }
}
