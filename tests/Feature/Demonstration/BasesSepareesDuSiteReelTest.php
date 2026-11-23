<?php

namespace Tests\Feature\Demonstration;

use App\Console\Commands\DiagnostiquerCaisseSecurisee;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Le site réel ne connaît aucune base du site de démonstration (lot
 * DEMO-COPIE, choix 9, N5) : ses requêtes ne vont qu'à sa base, et, en
 * production, caisse:diagnostiquer rougit si son serveur PostgreSQL porte
 * une base demo_… ou si son rôle peut créer des bases.
 */
class BasesSepareesDuSiteReelTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_requetes_du_site_reel_ne_vont_qu_a_sa_base(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $bases = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $requete) use (&$bases): void {
            $bases[$requete->connection->getDatabaseName()] = true;
        });

        $this->get('/app/login')->assertOk();
        $this->assertLessThan(500, $this->actingAs($proprietaire)->get('/societe')->status());

        $this->assertSame([DB::connection()->getDatabaseName()], array_keys($bases));
        $this->assertArrayNotHasKey('registre', (array) config('database.connections'));
        $this->assertArrayNotHasKey('demo_admin', (array) config('database.connections'));
    }

    public function test_en_production_le_diagnostic_refuse_un_serveur_partage_avec_le_site_de_demonstration(): void
    {
        $base = 'demo_copie_'.str_pad((string) (getenv('TEST_TOKEN') ?: '0'), 26, 'x');
        config(['database.connections.service_des_bases' => config('database.connections.pgsql')]);
        $service = DB::connection('service_des_bases');
        $service->unprepared('drop database if exists "'.$base.'"');

        try {
            $service->unprepared('create database "'.$base.'"');
            $this->app->detectEnvironment(fn (): string => 'production');

            $this->assertSame(1, Artisan::call('caisse:diagnostiquer'));
            $sortie = (string) preg_replace('/\s+/', ' ', Artisan::output());
            $this->assertStringContainsString('Le serveur PostgreSQL du site réel porte', $sortie);
            $this->assertStringContainsString(DiagnostiquerCaisseSecurisee::MESSAGE_ROLE_QUI_CREE_DES_BASES, $sortie, 'Le rôle des tests peut créer des bases, comme celui que la production refuse.');
        } finally {
            $service->unprepared('drop database if exists "'.$base.'"');
            DB::purge('service_des_bases');
        }
    }
}
