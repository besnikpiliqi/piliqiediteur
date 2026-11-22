<?php

namespace App\Domain\Tenancy\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * La liste des mots de passe courants, que la règle des mots de passe
 * choisis refuse toujours (voir RegleDuMotDePasse) et que le tirage d'un
 * identifiant de connexion évite (voir GenerateurDIdentifiants) : deux
 * fichiers de données rangés dans le dépôt, sous
 * resources/mots-de-passe-courants, dont le README donne la source, la
 * licence et la façon de les refaire.
 * - seclists-xato-100000.txt : les 100 000 mots de passe les plus fréquents
 *   de la liste « 10 million passwords » de Mark Burnett, telle que la
 *   publie SecLists, réduits aux mots de passe d'au moins 8 caractères (la
 *   règle refuse déjà les plus courts), en minuscules, sans doublon ;
 * - supplement-francais.txt : les mots de passe courants des francophones
 *   (claviers AZERTY, mots usuels, mois et années, termes du métier) et du
 *   logiciel, écrits pour PILIQI, en minuscules.
 * Un mot de passe est courant quand, passé en minuscules, il figure à
 * l'identique dans l'un des deux fichiers, avec ou sans les espaces qui
 * l'entourent. Les fichiers se lisent une fois par processus, depuis le dossier
 * resources de l'application, sans avoir besoin de son démarrage ; s'ils ne se
 * lisent pas, la règle échoue au lieu d'accepter tout mot de passe.
 */
final class MotsDePasseCourants
{
    public const DOSSIER = 'mots-de-passe-courants';

    /**
     * @var list<string>
     */
    public const FICHIERS = ['seclists-xato-100000.txt', 'supplement-francais.txt'];

    /**
     * @var array<array-key, true>|null
     */
    private static ?array $connus = null;

    /**
     * Ce mot de passe figure-t-il dans la liste des mots de passe courants ?
     *
     * @throws RuntimeException quand un fichier de la liste ne se lit pas
     */
    public static function contient(#[SensitiveParameter] string $motDePasse): bool
    {
        $liste = self::liste();
        $minuscules = mb_strtolower($motDePasse);

        return isset($liste[$minuscules]) || isset($liste[trim($minuscules)]);
    }

    /**
     * Les mots de passe courants, par mot de passe.
     *
     * @return array<array-key, true>
     *
     * @throws RuntimeException
     */
    public static function liste(): array
    {
        if (self::$connus !== null) {
            return self::$connus;
        }

        $connus = [];

        foreach (self::FICHIERS as $fichier) {
            $chemin = dirname(__DIR__, 4).'/resources/'.self::DOSSIER.'/'.$fichier;
            $lignes = is_file($chemin) ? file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

            if ($lignes === false || $lignes === []) {
                throw new RuntimeException("La liste des mots de passe courants ({$fichier}) ne se lit pas.");
            }

            $connus += array_fill_keys($lignes, true);
        }

        return self::$connus = $connus;
    }
}
