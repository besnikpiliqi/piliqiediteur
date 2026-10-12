<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;

/**
 * Les autorisations de la société elle-même, dans son espace
 * d'administration, que le propriétaire coche pour un administrateur (voir
 * CreerAdministrateurAction) : le propriétaire les a toutes sans case ; un
 * membre ou un expert-comptable n'en a aucune, quelle que soit la ligne
 * qu'en garderait la base (voir User::autorisationsDeSociete()). Aucune
 * chaîne magique ailleurs : on référence toujours ces constantes. La base
 * ne connaît qu'elles : la migration du référentiel des autorisations de
 * société les y inscrit, et une case qui n'y figure pas est refusée par sa
 * clé étrangère.
 *
 * Aucune ne suffit seule pour un geste qui porte sur un magasin : il faut
 * aussi ce magasin dans la portée de l'administrateur (voir
 * User::administreLeMagasin()) :
 * - gérer les magasins : modifier, changer le SIRET, fermer et rouvrir
 *   ceux de sa portée ; en créer, seulement avec tous les magasins ;
 * - nommer et retirer les responsables : nommer responsable, dans un
 *   magasin de sa portée, un nouveau compte ou un vendeur ou un
 *   observateur de ce magasin, lui retirer ce magasin ou le déplacer vers
 *   un autre magasin de sa portée ; retirer de la société une personne
 *   qui n'est que responsable de magasins tous dans sa portée, et lui
 *   envoyer un lien pour renouveler son accès ; annuler sa propre
 *   nomination. Jamais suspendre, réactiver ni rétrograder un responsable,
 *   ni affecter une personne à plusieurs magasins : cela reste au
 *   propriétaire, prévenu de chaque nomination (voir PorteeDesDroits) ;
 * - gérer les paramètres de la société (identité légale hors SIREN, siège,
 *   TVA, mois de fin d'exercice) : seulement avec tous les magasins (voir
 *   exigentTousLesMagasins()).
 */
final class AutorisationsSociete
{
    public const MAGASINS_GERER = 'societe.magasins.gerer';

    public const RESPONSABLES_NOMMER = 'societe.responsables.nommer';

    public const PARAMETRES_GERER = 'societe.parametres.gerer';

    /**
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [self::MAGASINS_GERER, self::RESPONSABLES_NOMMER, self::PARAMETRES_GERER];
    }

    /**
     * Les autorisations qui ne se cochent que pour un administrateur de
     * tous les magasins : elles touchent toute la société.
     *
     * @return list<string>
     */
    public static function exigentTousLesMagasins(): array
    {
        return [self::PARAMETRES_GERER];
    }

    /**
     * Les autorisations qui restent permises quand la société est en
     * lecture seule (suspendue, résiliée, refusée ; voir ControleDesDroits) :
     * nommer et retirer les responsables, car l'équipe reste gérable, ne
     * serait-ce que pour couper un accès. Une société révoquée, en lecture
     * seule totale, n'en garde aucune.
     *
     * @return list<string>
     */
    public static function permisesEnLectureSeule(bool $societeRevoquee): array
    {
        return $societeRevoquee ? [] : [self::RESPONSABLES_NOMMER];
    }

    /**
     * Le modèle des autorisations de société d'un nouvel administrateur :
     * gérer les magasins, décochable (voir CreerAdministrateurAction).
     * Nommer et retirer les responsables, et les paramètres de la société,
     * se cochent à la main : un responsable a tous les droits de son
     * magasin.
     *
     * @return list<string>
     */
    public static function modeleDeLAdministrateur(): array
    {
        return [self::MAGASINS_GERER];
    }

    /**
     * Les autorisations de société cochées, refusées dès que l'une d'elles
     * est inconnue, sans doublon, dans l'ordre alphabétique.
     *
     * @param  array<array-key, mixed>  $autorisations
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function verifierConnues(array $autorisations): array
    {
        foreach ($autorisations as $autorisation) {
            if (! is_string($autorisation) || ! in_array($autorisation, self::toutes(), true)) {
                throw new InvalidArgumentException('Autorisation inconnue : '.(is_scalar($autorisation) ? $autorisation : get_debug_type($autorisation)));
            }
        }

        $connues = array_values(array_unique($autorisations));
        sort($connues);

        return $connues;
    }

    /**
     * @return array<string, string>
     */
    public static function libelles(): array
    {
        return [
            self::MAGASINS_GERER => 'Magasins : créer (avec tous les magasins), modifier, fermer et rouvrir',
            self::RESPONSABLES_NOMMER => 'Responsables : nommer et retirer les responsables de ses magasins, et changer leur magasin (un responsable a tous les droits de son magasin)',
            self::PARAMETRES_GERER => 'Paramètres de la société : identité légale, siège, TVA et fin d\'exercice (avec tous les magasins)',
        ];
    }
}
