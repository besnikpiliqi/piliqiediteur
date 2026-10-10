<?php

/*
 * Exécute, dans un processus PHP à part, une opération sur la base d'un
 * test, comme une seconde caisse qui agit au même instant (voir
 * TestCase::demarrerUnProcessusConcurrent()). La demande arrive sérialisée
 * sur l'entrée standard : la base du test (celle de son processus de test
 * parallèle), la configuration de la caisse sécurisée des tests et
 * l'opération. Le processus réussit si l'opération réussit ; sinon il
 * écrit l'erreur et échoue.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$application = require __DIR__.'/../../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$demande = unserialize(base64_decode((string) stream_get_contents(STDIN)));

config([
    'database.connections.'.config('database.default').'.database' => $demande['base'],
    'caisse_securisee' => $demande['caisse_securisee'],
]);
DB::purge();

try {
    $demande['operation']->getClosure()();
} catch (Throwable $erreur) {
    fwrite(STDERR, $erreur::class.' : '.$erreur->getMessage());

    exit(1);
}

exit(0);
