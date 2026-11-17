<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Jobs\ArchiverPuisSupprimerUneCopie;
use App\Domain\Demonstration\Jobs\CreerUneCopie;
use App\Domain\Demonstration\Jobs\PreparerUneCopie;
use App\Domain\Demonstration\Jobs\RattraperLesTachesDUneCopie;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\Jobs\RenouvelerLeLienDUneCopie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use PDO;
use PDOException;
use Tests\DemonstrationTestCase;

/**
 * Les deux rôles PostgreSQL du site de démonstration (lot DEMO-COPIE,
 * choix 33, N30) : demo_app, le rôle des pages et des travaux des copies,
 * ne crée ni ne supprime aucune base ; demo_admin crée, copie et supprime,
 * même une copie ouverte par une visite (WITH (FORCE), grâce à
 * pg_signal_backend) ; les travaux de demo_admin passent par leur file à
 * eux, que seul son travailleur traite, et n'attendent jamais derrière les
 * travaux longs des copies.
 */
class RolesPostgresDeLaDemonstrationTest extends DemonstrationTestCase
{
    public function test_le_role_des_pages_ne_cree_ni_ne_supprime_aucune_base(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $pages = $this->connexionDesPages((string) $copie->nom_de_la_base);

        foreach (['create database demo_copie_00000000000000000000000000', 'drop database "'.$copie->nom_de_la_base.'"'] as $ordre) {
            try {
                $pages->exec($ordre);
                $this->fail('Le rôle des pages a pu exécuter : '.$ordre);
            } catch (PDOException $refus) {
                $this->assertContains($refus->getCode(), ['42501', '55006'], $ordre.' : '.$refus->getMessage());
            }
        }

        $this->assertTrue(app(BasesDeLaDemonstration::class)->existe((string) $copie->nom_de_la_base));
    }

    public function test_l_administration_supprime_une_copie_meme_pendant_qu_une_visite_la_tient_ouverte(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $visite = $this->connexionDesPages((string) $copie->nom_de_la_base);
        $this->assertSame(1, (int) $visite->query('select 1')->fetchColumn());

        app(BasesDeLaDemonstration::class)->supprimer((string) $copie->nom_de_la_base);

        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe((string) $copie->nom_de_la_base), 'WITH (FORCE) coupe la connexion de la visite.');
        $this->expectException(PDOException::class);
        $visite->query('select 1');
    }

    public function test_les_travaux_de_l_administration_ont_leur_file_a_eux(): void
    {
        $travaux = [
            new CreerUneCopie(['reference' => str_repeat('a', 32), 'repere' => 'K7M2QD', 'type' => 'services', 'validee' => true, 'empreinte_du_lien' => str_repeat('b', 64), 'generation' => 1, 'fin' => '2026-12-16T09:00:00Z']),
            new PreparerUneCopie(str_repeat('a', 32)),
            new RefaireUneCopie(str_repeat('a', 32)),
            new ArchiverPuisSupprimerUneCopie(str_repeat('a', 32), 'supprimer'),
            new RenouvelerLeLienDUneCopie(str_repeat('a', 32), str_repeat('c', 64), 2),
        ];

        foreach ($travaux as $travail) {
            $this->assertSame('demonstration', $travail->connection, $travail::class.' passe par la file du registre.');
            $this->assertSame(CreerUneCopie::FILE, $travail->queue, $travail::class.' passe par la file demonstration-admin, que seul le travailleur de demo_admin traite.');
        }

        $this->assertNotSame(CreerUneCopie::FILE, config('queue.connections.'.config('queue.default').'.queue'), 'Les travaux des copies ont une autre file : un travail de 30 minutes d\'une copie ne retarde jamais une création.');

        $rattrapage = new RattraperLesTachesDUneCopie(str_repeat('a', 32));
        $this->assertSame([RattraperLesTachesDUneCopie::FILE, RattraperLesTachesDUneCopie::FILE], [$rattrapage->connection, $rattrapage->queue], 'Le rattrapage des tâches d\'une copie, long de dix minutes au plus, a sa propre file : il ne retarde jamais une création.');
        $this->assertNotSame(CreerUneCopie::FILE, $rattrapage->queue);
        $configuration = require config_path('queue.php');
        $this->assertSame(['registre', RattraperLesTachesDUneCopie::FILE, true], [
            $configuration['connections'][RattraperLesTachesDUneCopie::FILE]['connection'],
            $configuration['connections'][RattraperLesTachesDUneCopie::FILE]['queue'],
            $configuration['connections'][RattraperLesTachesDUneCopie::FILE]['after_commit'],
        ]);
    }

    private function connexionDesPages(string $base): PDO
    {
        $acces = config('database.connections.registre');

        return new PDO('pgsql:host='.$acces['host'].';port='.$acces['port'].';dbname='.$base, $acces['username'], $acces['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
