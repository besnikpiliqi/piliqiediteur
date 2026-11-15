<?php

namespace Database\Seeders\Semis;

use App\Domain\Tenancy\Support\Reglage;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La file d'attente pendant le semis (voir pendantLeSemis()) : les tâches
 * que les gestes mettent en file (envois d'e-mails, transmissions à la
 * plateforme agréée) tournent d'ordinaire pendant le geste (connexion
 * « sync »), et la préparation des téléchargements des documents par
 * période attend dans la table jobs, comme en production, jusqu'à ce
 * qu'un passage du travailleur la prenne (voir passer()).
 *
 * Le temps d'un geste (plusTard()), les tâches de ce geste attendent elles
 * aussi dans la table jobs : un travailleur les prend ensuite, une à une,
 * à l'heure du semis, comme php artisan queue:work le ferait. Ces tâches
 * vont dans des files à elles (FILE_DU_SEMIS, FILE_DES_TELECHARGEMENTS),
 * qu'aucun travailleur du poste n'écoute (php artisan queue:listen peut
 * tourner pendant le semis) et que la surveillance de la file ne lit pas
 * (voir SurveillerLaFileDAttente) : seuls les passages du semis les
 * prennent. Une tâche qu'aucun passage ne prend reste en file à la fin
 * du semis, pour montrer son état : un envoi « en attente » de sa
 * tentative suivante, une transmission « soumise » à la plateforme agréée,
 * un téléchargement « en préparation ».
 */
final class FileDAttenteDuSemis
{
    /** La connexion de la file d'attente de la base (config/queue.php). */
    public const BASE = 'database';

    /** La connexion de la préparation des téléchargements des documents (voir PreparerLeTelechargementDesDocumentsJob::CONNEXION). */
    public const TELECHARGEMENTS = 'telechargements';

    /** La file des tâches du semis sur la connexion BASE. */
    public const FILE_DU_SEMIS = 'semis';

    /** La file des préparations des téléchargements du semis sur la connexion TELECHARGEMENTS. */
    public const FILE_DES_TELECHARGEMENTS = 'semis-telechargements';

    /** Le nombre de passages au plus d'un même appel de passerCeQuiEstDu(), au-delà duquel une tâche reviendrait sans fin. */
    private const PASSAGES_AU_PLUS = 20;

    /**
     * Le semis, ses tâches tournant pendant leur geste, sauf les
     * préparations des téléchargements, qui attendent dans leur file du
     * semis ; les réglages des files d'attente sont rendus ensuite.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $semis
     * @return TResultat
     */
    public static function pendantLeSemis(Closure $semis): mixed
    {
        $reglages = ['queue.default', 'queue.connections.'.self::BASE.'.queue', 'queue.connections.'.self::TELECHARGEMENTS.'.driver', 'queue.connections.'.self::TELECHARGEMENTS.'.queue'];
        $avant = array_combine($reglages, array_map(static fn (string $reglage): mixed => config($reglage), $reglages));
        config([
            'queue.default' => 'sync',
            'queue.connections.'.self::BASE.'.queue' => self::FILE_DU_SEMIS,
            'queue.connections.'.self::TELECHARGEMENTS.'.driver' => 'database',
            'queue.connections.'.self::TELECHARGEMENTS.'.queue' => self::FILE_DES_TELECHARGEMENTS,
        ]);

        try {
            return $semis();
        } finally {
            config($avant);
        }
    }

    /**
     * Ce geste, ses tâches mises dans la table jobs au lieu de tourner
     * aussitôt.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $geste
     * @return TResultat
     */
    public static function plusTard(Closure $geste): mixed
    {
        $avant = config('queue.default');
        config(['queue.default' => self::BASE]);

        try {
            return $geste();
        } finally {
            config(['queue.default' => $avant]);
        }
    }

    /**
     * Un passage du travailleur sur cette connexion : il prend la prochaine
     * tâche due de sa file, s'il y en a une, et la fait tourner.
     *
     * @throws LogicException quand le travailleur s'arrête en erreur
     */
    public static function passer(string $connexion = self::BASE): void
    {
        if (Artisan::call('queue:work', ['connection' => $connexion, '--queue' => Reglage::texte("queue.connections.{$connexion}.queue"), '--once' => true, '--sleep' => 0]) !== 0) {
            throw new LogicException('Le travailleur de la file d\'attente s\'est arrêté en erreur : '.trim(Artisan::output()));
        }
    }

    /**
     * Des passages du travailleur sur cette connexion tant qu'une tâche de
     * sa file est due à l'heure du semis.
     *
     * @throws LogicException quand le travailleur s'arrête en erreur
     */
    public static function passerCeQuiEstDu(string $connexion = self::BASE): void
    {
        $dues = static fn (): bool => DB::table(Reglage::texte("queue.connections.{$connexion}.table"))
            ->where('queue', Reglage::texte("queue.connections.{$connexion}.queue"))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();

        for ($passages = 0; $dues(); $passages++) {
            if ($passages === self::PASSAGES_AU_PLUS) {
                throw new LogicException('Des tâches de la file d\'attente restent dues après '.self::PASSAGES_AU_PLUS.' passages du travailleur.');
            }

            self::passer($connexion);
        }
    }
}
