<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Construction\NettoyageDeLaDemonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Le nettoyage de chaque nuit du site de démonstration, après la
 * construction des modèles (lot DEMO-COPIE, C.2, voir
 * NettoyageDeLaDemonstration) : bases et dossiers orphelins, bases de
 * travail abandonnées, copies perdues. Un refus d'agir rend un code
 * d'échec et paraît dans l'état publié. Seulement sur le site de
 * démonstration, par demo_admin.
 */
#[Signature('demonstration:nettoyer')]
#[Description('Supprime, après l\'archive de leur caisse, les bases et les dossiers orphelins du site de démonstration.')]
class NettoyerLaDemonstration extends Command
{
    public function handle(NettoyageDeLaDemonstration $nettoyage): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $resultat = $nettoyage->nettoyer();

        if ($resultat['refus'] !== null) {
            $this->error($resultat['refus']);

            return self::FAILURE;
        }

        $this->info(count($resultat['supprimees']).' base(s) supprimée(s), '.count($resultat['gardees']).' gardée(s) faute d\'archive, '.count($resultat['perdues']).' copie(s) perdue(s), '.$resultat['dossiers'].' dossier(s) effacé(s).');

        foreach ($resultat['gardees'] as $base) {
            $this->warn('Gardée faute d\'archive : '.$base);
        }

        return $resultat['gardees'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
