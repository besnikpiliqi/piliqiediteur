<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * L'outillage des tests de concurrence (constats C42 et C69 des audits du
 * 07/11/2026) : un autre processus qui dépasse sa limite fait échouer le
 * test avec ce que fait la base et la sortie de ce processus, au lieu d'un
 * simple dépassement ; et un processus qui tourne sans attendre le verrou
 * que tient le test le fait échouer, au lieu de le laisser devenir
 * séquentiel sans le dire. Le verrou est consultatif (pg_advisory_lock),
 * sur la base du test, qu'aucune table n'occupe.
 */
class ProcessusConcurrentTest extends TestCase
{
    /**
     * Une clé de verrou consultatif propre à ce test.
     */
    private const VERROU = 74_200_421;

    public function test_un_processus_bloque_au_dela_de_sa_limite_echoue_avec_l_etat_de_la_base_et_sa_sortie(): void
    {
        DB::select('select pg_advisory_lock(?)', [self::VERROU]);

        try {
            $processus = $this->demarrerUnProcessusConcurrent(static fn () => DB::select('select pg_advisory_lock(?)', [self::VERROU]));
            $this->attendreQueLeProcessusAttendeUnVerrou($processus);
            $echec = null;

            try {
                $this->attendreLeProcessusConcurrent($processus, 2);
            } catch (AssertionFailedError $erreur) {
                $echec = $erreur->getMessage();
            }

            $this->assertNotNull($echec, 'Le processus bloqué aurait dû faire échouer l\'attente.');
            $this->assertStringContainsString('L\'autre processus n\'a pas fini en 2 s.', $echec);
            $this->assertMatchesRegularExpression('/attente Lock\/advisory, bloquée par \{\d+\} : select pg_advisory_lock/', $echec);
            $this->assertMatchesRegularExpression('/- pid \d+ attend un verrou advisory ExclusiveLock/', $echec);
            $this->assertMatchesRegularExpression('/amorçage : \d+ ms\nconnexion à la base : \d+ ms/', $echec);
            $this->assertFalse($processus->running());
        } finally {
            DB::select('select pg_advisory_unlock(?)', [self::VERROU]);
        }
    }

    public function test_un_processus_qui_n_attend_aucun_verrou_fait_echouer_l_attente_du_verrou(): void
    {
        $processus = $this->demarrerUnProcessusConcurrent(static fn () => usleep(20_000_000));
        $echec = null;

        try {
            $this->attendreQueLeProcessusAttendeUnVerrou($processus, 2);
        } catch (AssertionFailedError $erreur) {
            $echec = $erreur->getMessage();
        }

        $this->assertNotNull($echec, 'Un processus qui n\'attend aucun verrou ne doit pas laisser le test continuer comme s\'il l\'attendait.');
        $this->assertStringContainsString('Au bout de 2 s, l\'autre processus tourne sans attendre de verrou que tient le test', $echec);
        $this->assertFalse($processus->running());
    }
}
