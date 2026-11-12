<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande planifiée toutes les 5 minutes (voir bootstrap/app.php) qui
 * prévient l'éditeur quand la file d'attente ne part plus (diagnostic
 * vérifié des e-mails du 02/11/2026) : des tâches disponibles depuis plus
 * de 2 minutes, que personne ne prend (aucun travailleur ne tourne), ou
 * des tâches réservées depuis plus que le délai de reprise de leur
 * connexion (retry_after), laissées par un travailleur tombé en route
 * (`queue:listen` coupé), que creationTimeOfOldestPendingJob() de Laravel
 * ne voit pas. Elle lit, pour chaque connexion de file en base de données
 * (config/queue.php), le seul nombre de ces tâches, jamais leur contenu :
 * la table des tâches est commune à toutes les sociétés, et rien d'un
 * magasin n'en sort.
 *
 * Tant que l'incident dure, chaque passage l'écrit au journal de
 * l'application au niveau critique ; l'éditeur n'en reçoit qu'une alerte
 * (voir AlerteEditeur), la première fois, et une nouvelle seulement après
 * un passage où la file est repartie.
 */
#[Signature('app:surveiller-la-file-d-attente')]
#[Description('Prévient l\'éditeur quand des tâches de la file d\'attente ne partent plus')]
class SurveillerLaFileDAttente extends Command
{
    /**
     * Au-delà de ce délai, une tâche disponible que personne n'a prise
     * signale qu'aucun travailleur ne tourne.
     */
    public const ATTENTE_MAXIMALE_EN_SECONDES = 120;

    public const CLE_DE_L_INCIDENT = 'file-d-attente:incident-signale';

    public function handle(AlerteEditeur $alerteEditeur): int
    {
        $retards = $this->retards();

        if ($retards === []) {
            Cache::forget(self::CLE_DE_L_INCIDENT);
            $this->info('La file d\'attente part normalement.');

            return self::SUCCESS;
        }

        $lignes = array_map(static fn (array $retard): string => "File « {$retard['file']} » : {$retard['en_attente']} tâche(s) disponible(s) depuis plus de 2 minutes, que personne ne prend, et {$retard['reservees']} tâche(s) réservée(s) depuis plus de {$retard['retry_after']} secondes, laissée(s) par un travailleur arrêté.", $retards);
        Log::critical('La file d\'attente ne part plus : '.implode(' ', $lignes));

        if (Cache::add(self::CLE_DE_L_INCIDENT, now()->getTimestamp())) {
            $alerteEditeur->alerterDeLaPlateforme('La file d\'attente ne part plus', [
                ...$lignes,
                'Les e-mails des documents et les autres tâches attendent : vérifiez que les travailleurs tournent (php artisan queue:work, et php artisan queue:work telechargements --timeout=1800), puis php artisan queue:monitor default,telechargements.',
                'Aucune autre alerte ne partira pour cet incident ; le journal de l\'application le note à chaque passage.',
            ]);
        }

        foreach ($lignes as $ligne) {
            $this->error($ligne);
        }

        return self::FAILURE;
    }

    /**
     * Pour chaque file en base de données qui a des tâches en retard : son
     * nom, le nombre de tâches disponibles que personne ne prend, celui des
     * tâches réservées au-delà du délai de reprise, et ce délai.
     *
     * @return list<array{file: string, en_attente: int, reservees: int, retry_after: int}>
     */
    private function retards(): array
    {
        $maintenant = now()->getTimestamp();
        $retards = [];

        foreach (ValeursLues::tableau(config('queue.connections')) as $connexion) {
            if (! is_array($connexion) || ($connexion['driver'] ?? null) !== 'database') {
                continue;
            }

            $file = is_string($connexion['queue'] ?? null) ? $connexion['queue'] : 'default';
            $retryAfter = is_int($connexion['retry_after'] ?? null) ? $connexion['retry_after'] : 90;
            $taches = DB::connection(is_string($connexion['connection'] ?? null) ? $connexion['connection'] : null)
                ->table(is_string($connexion['table'] ?? null) ? $connexion['table'] : 'jobs')
                ->where('queue', $file);
            $enAttente = (clone $taches)->whereNull('reserved_at')->where('available_at', '<=', $maintenant - self::ATTENTE_MAXIMALE_EN_SECONDES)->count();
            $reservees = (clone $taches)->whereNotNull('reserved_at')->where('reserved_at', '<=', $maintenant - $retryAfter)->count();

            if ($enAttente + $reservees > 0) {
                $retards[] = ['file' => $file, 'en_attente' => $enAttente, 'reservees' => $reservees, 'retry_after' => $retryAfter];
            }
        }

        return $retards;
    }
}
