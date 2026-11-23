<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Isolation\ProblemeDIsolation;
use App\Domain\Tenancy\Isolation\RapportDIsolation;
use App\Domain\Tenancy\Isolation\ResultatDUnControle;
use App\Domain\Tenancy\Isolation\VerificationDeLIsolation;
use App\Domain\Tenancy\Services\AlerteEditeur;
use Carbon\CarbonInterval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vérifie l'isolation des sociétés, des magasins, des comptes, du cabinet
 * et des comptables dans toute la base, par la
 * base et par l'application (voir VerificationDeLIsolation), en lecture
 * seule, à tout moment, en local comme en production. Elle affiche le
 * tableau des contrôles (contrôle, couche, lignes vérifiées, problèmes), puis
 * chaque problème (couche, contrôle, table, identifiant interne, colonne,
 * valeur attendue, valeur trouvée, règle de CLAUDE.md), sans aucune donnée
 * personnelle, et sort avec le code 0 si tout est juste, 1 sinon.
 *
 * Chaque passage écrit sa durée, ses lignes vérifiées et ses problèmes au
 * journal de l'application ; un passage sans problème se retient comme la
 * dernière réussite, que caisse:diagnostiquer contrôle (36 heures au plus).
 * La tâche de chaque nuit (4 h 40, heure de Paris, voir bootstrap/app.php)
 * la lance avec --prevenir-l-editeur : au moindre problème, ou si elle ne
 * peut pas finir, l'éditeur reçoit une alerte, une seule par nuit, avec le
 * nombre de problèmes par contrôle et les seuls identifiants internes ; rien
 * n'est bloqué ni corrigé (la correction est une procédure de l'éditeur).
 */
#[Signature('app:verifier-isolation {--prevenir-l-editeur : Prévient l\'éditeur au moindre problème, ou si la vérification ne finit pas (une alerte par nuit)}')]
#[Description('Vérifie l\'isolation des sociétés et des magasins dans toute la base, par la base (SQL) et par l\'application (Laravel)')]
class VerifierLIsolation extends Command
{
    public const ENTETES_DES_CONTROLES = ['Contrôle', 'Couche', 'Lignes vérifiées', 'Problèmes'];

    public const ENTETES_DES_PROBLEMES = ['Couche', 'Contrôle', 'Table', 'Identifiant', 'Colonne', 'Attendu', 'Trouvé', 'Règle'];

    /**
     * L'instant (horodatage Unix) du dernier passage qui n'a trouvé aucun
     * problème, que caisse:diagnostiquer lit.
     */
    public const CLE_DE_LA_DERNIERE_REUSSITE = 'isolation:derniere-reussite';

    /**
     * Préfixe de la clé qui retient l'alerte de la nuit, suivi du jour de
     * Paris : une seule alerte par nuit.
     */
    public const PREFIXE_DE_L_ALERTE_DE_LA_NUIT = 'isolation:alerte-de-la-nuit:';

    /**
     * Au-delà, caisse:diagnostiquer signale que la vérification n'a pas
     * réussi depuis trop longtemps.
     */
    public const HEURES_SANS_REUSSITE = 36;

    /**
     * Les problèmes que l'alerte détaille un à un ; les autres se comptent.
     */
    public const PROBLEMES_DETAILLES_DANS_L_ALERTE = 50;

    public const OBJET_DES_PROBLEMES = 'Isolation des sociétés et des magasins : problèmes trouvés';

    public const OBJET_NON_TERMINE = 'Contrôle de l\'isolation non terminé';

    public function handle(VerificationDeLIsolation $verification, AlerteEditeur $alerteEditeur): int
    {
        $debut = hrtime(true);

        try {
            $rapport = $verification->verifier();
        } catch (Throwable $erreur) {
            Log::critical(self::OBJET_NON_TERMINE.' : '.$erreur::class.' : '.$erreur->getMessage());
            $this->error(self::OBJET_NON_TERMINE.' : '.$erreur->getMessage());

            if ($this->option('prevenir-l-editeur') === true) {
                $this->alerterUneFoisParNuit($alerteEditeur, self::OBJET_NON_TERMINE, [
                    'La vérification de l\'isolation de chaque nuit s\'est arrêtée avant la fin ('.class_basename($erreur).') : l\'isolation des sociétés et des magasins n\'a pas été contrôlée cette nuit.',
                    'Le journal de l\'application donne la cause. Relancez-la avec php artisan app:verifier-isolation une fois la cause corrigée.',
                ]);
            }

            return self::FAILURE;
        }

        $secondes = number_format((hrtime(true) - $debut) / 1e9, 1, ',', '');
        Log::info("Vérification de l'isolation : {$rapport->lignesVerifiees()} lignes vérifiées en {$secondes} s, {$rapport->nombreDeProblemes()} problème(s).");
        $this->afficher($rapport);

        if ($rapport->estJuste()) {
            Cache::forever(self::CLE_DE_LA_DERNIERE_REUSSITE, now()->getTimestamp());

            return self::SUCCESS;
        }

        $parControle = array_map(static fn (string $controle, int $nombre): string => "{$controle} : {$nombre} problème(s).", array_keys($rapport->problemesParControle()), $rapport->problemesParControle());
        Log::critical("Isolation : {$rapport->nombreDeProblemes()} problème(s). ".implode(' ', $parControle));

        if ($this->option('prevenir-l-editeur') === true) {
            $this->alerterUneFoisParNuit($alerteEditeur, self::OBJET_DES_PROBLEMES, [
                "La vérification de l'isolation de cette nuit a trouvé {$rapport->nombreDeProblemes()} problème(s), sur {$rapport->lignesVerifiees()} lignes vérifiées. Rien n'a été bloqué ni corrigé.",
                ...$parControle,
                ...self::detailDesProblemes($rapport),
                'php artisan app:verifier-isolation les affiche tous ; leur correction est une procédure de l\'éditeur.',
            ]);
        }

        return self::FAILURE;
    }

    /**
     * Le tableau des contrôles, puis chaque problème en rouge, ou la phrase
     * qui dit que tout est juste.
     */
    public function afficher(RapportDIsolation $rapport): void
    {
        $this->table(self::ENTETES_DES_CONTROLES, array_map(static fn (ResultatDUnControle $controle): array => [
            $controle->nom,
            $controle->couche,
            (string) $controle->lignesVerifiees(),
            (string) count($controle->problemes()),
        ], $rapport->controles));

        if ($rapport->estJuste()) {
            $this->info("Isolation vérifiée : aucun problème ({$rapport->lignesVerifiees()} lignes vérifiées).");

            return;
        }

        $this->error("Isolation : {$rapport->nombreDeProblemes()} problème(s).");
        $this->table(self::ENTETES_DES_PROBLEMES, array_map(static fn (ProblemeDIsolation $probleme): array => $probleme->enLigne(), $rapport->problemes()));
    }

    /**
     * Les premiers problèmes, un par ligne, avec leurs seuls identifiants
     * internes (voir ProblemeDIsolation), et le nombre des autres.
     *
     * @return list<string>
     */
    private static function detailDesProblemes(RapportDIsolation $rapport): array
    {
        $problemes = $rapport->problemes();
        $lignes = array_map(static fn (ProblemeDIsolation $probleme): string => "{$probleme->couche}, {$probleme->controle} : table {$probleme->table}, ligne {$probleme->identifiant}, colonne {$probleme->colonne} ; attendu : {$probleme->attendu} ; trouvé : {$probleme->trouve}.", array_slice($problemes, 0, self::PROBLEMES_DETAILLES_DANS_L_ALERTE));
        $autres = count($problemes) - count($lignes);

        return $autres > 0 ? [...$lignes, "Et {$autres} autre(s) problème(s)."] : $lignes;
    }

    /**
     * Une seule alerte par nuit (jour de Paris), quel que soit le nombre de
     * passages ou leur issue.
     *
     * @param  list<string>  $lignes
     */
    private function alerterUneFoisParNuit(AlerteEditeur $alerteEditeur, string $objet, array $lignes): void
    {
        if (Cache::add(self::PREFIXE_DE_L_ALERTE_DE_LA_NUIT.Calendrier::deParis()->aujourdhui(), now()->getTimestamp(), Calendrier::deParis()->decaler(now(), CarbonInterval::days(2)))) {
            $alerteEditeur->alerterDeLaPlateforme($objet, $lignes);
        }
    }
}
