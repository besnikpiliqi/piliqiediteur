<?php

/*
 * Exécute, dans un processus PHP à part, une opération sur la base d'un
 * test, comme une seconde caisse qui agit au même instant (voir
 * TestCase::demarrerUnProcessusConcurrent()). La demande arrive sérialisée
 * sur l'entrée standard : la base du test (celle de son processus de test
 * parallèle), la configuration de la caisse sécurisée et du journal
 * d'audit des tests, le préfixe de son cache (propre à son processus de
 * test parallèle : un cache en base est alors le même pour les deux
 * processus), l'heure figée du test, s'il l'a figée, et
 * l'opération. Le processus réussit si l'opération réussit ; sinon il
 * écrit l'erreur et échoue, y compris quand la demande ne se reconstruit
 * pas (une opération qui ne se désérialise pas n'a rien fait).
 */

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$application = require __DIR__.'/../../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

try {
    $demande = unserialize(base64_decode((string) stream_get_contents(STDIN)));

    config([
        'database.connections.'.config('database.default').'.database' => $demande['base'],
        'caisse_securisee' => $demande['caisse_securisee'],
        'journal_audit' => $demande['journal_audit'],
        'cache.prefix' => $demande['prefixe_du_cache'],
    ]);
    DB::purge();

    if ($demande['maintenant'] !== null) {
        Carbon::setTestNow(CarbonImmutable::parse($demande['maintenant']));
    }

    $demande['operation']->getClosure()();
} catch (Throwable $erreur) {
    fwrite(STDERR, $erreur::class.' : '.$erreur->getMessage());

    exit(1);
}

exit(0);
