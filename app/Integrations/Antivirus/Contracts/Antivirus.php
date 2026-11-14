<?php

namespace App\Integrations\Antivirus\Contracts;

use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\ResultatDeLAntivirus;

/**
 * L'antivirus qui analyse chaque fichier reçu (décision de l'éditeur du
 * 07/11/2026, lot FI, voir ControleDesFichiers), au dépôt et de nouveau au
 * téléchargement quand sa dernière analyse a plus de 24 heures (voir
 * RevueAntivirus) : ClamAV sur le serveur (voir ClamAv, pilote « clamav »),
 * ou une implémentation factice qui ne reconnaît que le fichier de test
 * EICAR (voir AntivirusFactice, pilote « factice »), pour les tests, la
 * démonstration et le développement, qui fait échouer caisse:diagnostiquer
 * en production (voir AntivirusServiceProvider). Il ne reçoit que le
 * contenu, jamais un nom ni un chemin.
 */
interface Antivirus
{
    /**
     * Le verdict de l'antivirus sur ce contenu.
     *
     * @throws AntivirusInjoignableException quand il ne répond pas, ou ne sait pas l'analyser
     */
    public function analyser(string $contenu): ResultatDeLAntivirus;

    /**
     * Ce qu'est cet antivirus, pour le diagnostic du serveur : sa version.
     *
     * @throws AntivirusInjoignableException quand il ne répond pas
     */
    public function description(): string;
}
