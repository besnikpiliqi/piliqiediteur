<?php

/*
 * Contrôle, dans un processus PHP à part, le fichier dont le chemin est
 * donné (voir ControleDesFichiers), comme un dépôt de l'application, sous
 * la limite de mémoire que ce processus a reçue (php -d memory_limit=…) :
 * écrit « accepté », ou la raison du refus (voir RaisonDuRefus). Une
 * erreur fatale, faute de mémoire, se voit au code de sortie et à la
 * sortie d'erreur.
 */

use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\PieceDeposee;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$application = require __DIR__.'/../../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$chemin = (string) ($argv[1] ?? '');

try {
    $application->make(ControleDesFichiers::class)->controler(new PieceDeposee(basename($chemin), (string) file_get_contents($chemin)));
    echo 'accepté';
} catch (FichierRefuseException $refus) {
    echo $refus->raison->value;
}
