<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ReverificationSirene;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reprend les contrôles du répertoire Sirene de l'INSEE de chaque société
 * (voir ReverificationSirene) : chaque heure (voir bootstrap/app.php), les
 * contrôles restés impossibles ; avec --toutes, chaque mois, les sociétés
 * validées et leurs magasins réels actifs, sans rien bloquer. La panne
 * d'une société n'arrête pas les suivantes : elle est signalée
 * (report()), et reprise au passage suivant.
 */
#[Signature('app:reverifier-sirene {--toutes : Revérifie aussi les sociétés validées et leurs magasins actifs}')]
#[Description('Reprend les contrôles Sirene restés impossibles, et revérifie les sociétés validées avec --toutes')]
class ReverifierSirene extends Command
{
    public function handle(ReverificationSirene $reverification): int
    {
        $enEchec = 0;

        Societe::query()->orderBy('id')->each(function (Societe $societe) use ($reverification, &$enEchec): void {
            try {
                $reverification->reprendre($societe, (bool) $this->option('toutes'));
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        if ($enEchec > 0) {
            $this->error("{$enEchec} société(s) en échec, reprise(s) au prochain passage.");

            return self::FAILURE;
        }

        $this->info('Contrôles Sirene repris.');

        return self::SUCCESS;
    }
}
