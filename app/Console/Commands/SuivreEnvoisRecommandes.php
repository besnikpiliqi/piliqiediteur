<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Actions\SuivreLesEnvoisAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chaque heure (voir bootstrap/app.php), interroge le prestataire d'envoi
 * sur chaque pli à code encore en route (voir SuivreLesEnvoisAction) : en
 * plus de ses rappels signés, au cas où l'un se perdrait.
 */
#[Signature('app:suivre-envois-recommandes')]
#[Description('Interroge le prestataire sur les plis recommandés à code encore en route')]
class SuivreEnvoisRecommandes extends Command
{
    public function handle(SuivreLesEnvoisAction $suivre): int
    {
        $this->info($suivre->suivreTout().' pli(s) suivi(s).');

        return self::SUCCESS;
    }
}
