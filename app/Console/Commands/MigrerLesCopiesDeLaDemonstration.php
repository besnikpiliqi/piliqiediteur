<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Construction\MigrationDesCopies;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sur le site de démonstration, après la mise en production, à chaque
 * mise à jour qui ajoute des migrations (lot DEMO-COPIE, E.5) : chaque
 * copie servie reçoit les migrations, après l'archive de sa caisse ; une
 * copie qu'on ne peut pas migrer est refaite depuis le nouveau modèle,
 * avec le même lien (voir MigrationDesCopies). Les dossiers de migrations
 * sont ceux du logiciel, sauf --dossier.
 */
#[Signature('demonstration:migrer-les-copies {--dossier=* : Un dossier de migrations (par défaut ceux du logiciel)}')]
#[Description('Passe les migrations nouvelles sur chaque copie de démonstration, ou la refait')]
class MigrerLesCopiesDeLaDemonstration extends Command
{
    public function handle(MigrationDesCopies $migration): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $dossiers = array_values(array_filter(array_map('strval', (array) $this->option('dossier'))));
        $tableau = $migration->migrer($dossiers === [] ? EmpreinteDuSchema::DOSSIERS : $dossiers);

        $this->table(['Migrées', 'Refaites', 'Laissées faute d\'archive', 'Durée (s)'], [[$tableau['migrees'], $tableau['refaites'], $tableau['laissees'], $tableau['duree_en_secondes']]]);

        return $tableau['laissees'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
