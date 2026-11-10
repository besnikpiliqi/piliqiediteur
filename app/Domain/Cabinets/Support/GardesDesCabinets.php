<?php

namespace App\Domain\Cabinets\Support;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient l'espace des cabinets
 * d'expertise comptable, même écrit par DB::table(), une requête brute ou
 * une action défectueuse :
 * - cabinets_de_type_cabinet (cabinets) : la ligne de vérification d'une
 *   société qui n'est pas de type cabinet (voir Cabinet) ;
 * - invitations_d_un_cabinet (invitations_cabinet) : une invitation d'une
 *   société qui n'est pas un cabinet ; une invitation close sans sa date
 *   de fin, ou ouverte avec une date de fin (la purge n'en oublie aucune) ;
 * - liens_entre_un_cabinet_et_un_client (liens_cabinet) : un lien dont le
 *   cabinet n'est pas un cabinet, ou dont le client en est un (un lien
 *   vers soi est donc toujours refusé, par l'un ou l'autre de ces refus) ;
 *   un jeton hors d'une demande du cabinet en cours ; un lien qui naît dans
 *   un autre état qu'une demande, ou actif hors d'une invitation acceptée ;
 *   un client, un cabinet ou une origine qui change ; une transition qui
 *   n'est pas permise (une demande devient active, refusée, retirée ou
 *   expirée, un lien actif devient terminé, tout autre état est final) ;
 * - autorisations_cabinet_permises (autorisations_cabinet) : une
 *   autorisation hors de la liste de celles qu'un client peut accorder à
 *   un cabinet (PermissionRegistry::duCabinet() pour un client qui a des
 *   articles, écrite en toutes lettres), une autorisation de la famille
 *   des articles chez un client qui n'en a pas, une ligne sur un lien qui
 *   n'est pas actif : un compte d'un cabinet ne vend jamais ;
 * - clients_confies_a_un_collaborateur (clients_confies) : un compte qui
 *   n'est pas collaborateur, un lien qui n'est pas actif.
 *
 * Chacune se pose dans la migration de création de sa table, et se retire
 * avant elle, fonction comprise, par retirer(). Le type d'une société ne
 * changeant jamais (voir GardesDesTypesDActivite), une garde qui le lit ne
 * verrouille rien : aucune écriture simultanée ne peut le rendre faux.
 *
 * Chaque garde est une fonction plpgsql et son déclencheur BEFORE (INSERT,
 * ou INSERT OR UPDATE OF les colonnes qu'elle lit).
 */
final class GardesDesCabinets
{
    public const CABINETS = 'cabinets_de_type_cabinet';

    public const INVITATIONS = 'invitations_d_un_cabinet';

    public const LIENS = 'liens_entre_un_cabinet_et_un_client';

    public const AUTORISATIONS = 'autorisations_cabinet_permises';

    public const CLIENTS_CONFIES = 'clients_confies_a_un_collaborateur';

    /**
     * Un refus vaut à l'insertion et à la modification, à l'insertion
     * seulement, ou à la modification seulement (il lit alors OLD).
     */
    private const TOUJOURS = 'toujours';

    private const INSERTION = 'INSERT';

    private const MODIFICATION = 'UPDATE';

    /**
     * La table que protège chaque garde, et les colonnes dont la
     * modification la déclenche, séparées par des virgules (null : à
     * l'insertion seulement).
     */
    private const TABLES = [
        self::CABINETS => ['cabinets', 'societe_id'],
        self::INVITATIONS => ['invitations_cabinet', 'societe_id, etat, close_le'],
        self::LIENS => ['liens_cabinet', 'societe_id, cabinet_id, origine, etat, jeton_hache'],
        self::AUTORISATIONS => ['autorisations_cabinet', 'societe_id, lien_id, autorisation'],
        self::CLIENTS_CONFIES => ['clients_confies', null],
    ];

    /**
     * @param  key-of<self::TABLES>  $garde
     */
    public static function poser(string $garde): void
    {
        [$table, $colonnes] = self::TABLES[$garde];
        $controles = implode(' ', array_map(
            static fn (array $un): string => match ($un[2]) {
                self::TOUJOURS => '',
                default => "IF TG_OP = '{$un[2]}' THEN ",
            }."IF {$un[0]} THEN RAISE EXCEPTION '%', ".self::message($garde, $un[1]).'; END IF;'.($un[2] === self::TOUJOURS ? '' : ' END IF;'),
            self::refus($garde),
        ));
        $moments = $colonnes === null ? 'INSERT' : "INSERT OR UPDATE OF {$colonnes}";
        $connexion = DB::connection();

        $connexion->unprepared("CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$controles} RETURN NEW; END \$\$");
        $connexion->unprepared("CREATE TRIGGER {$garde} BEFORE {$moments} ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_{$garde}()");
    }

    /**
     * Retire le déclencheur de la garde puis sa fonction, que le
     * déclencheur empêcherait sinon de retirer : dans le down() de la
     * migration, avant sa table.
     *
     * @param  key-of<self::TABLES>  $garde
     */
    public static function retirer(string $garde): void
    {
        [$table] = self::TABLES[$garde];
        $connexion = DB::connection();

        $connexion->unprepared("DROP TRIGGER IF EXISTS {$garde} ON {$table}");
        $connexion->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");
    }

    /**
     * Les refus de chaque garde : la condition qui refuse, en SQL (NEW
     * désigne la ligne écrite, OLD la ligne d'avant une modification), son
     * message, et quand elle vaut : toujours, à l'insertion seulement, ou à
     * la modification seulement.
     *
     * @param  key-of<self::TABLES>  $garde
     * @return list<array{0: literal-string, 1: literal-string, 2: literal-string}>
     */
    private static function refus(string $garde): array
    {
        $cabinet = "'".TypeDActivite::Cabinet->value."'";
        $demandes = self::liste(array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::demandes()));
        $avecDesArticles = self::liste(array_map(static fn (TypeDActivite $type): string => $type->value, array_filter(TypeDActivite::cases(), static fn (TypeDActivite $type): bool => $type->aDesArticles())));
        $etatDuLien = "COALESCE((SELECT etat FROM liens_cabinet WHERE id = NEW.lien_id), '')";
        $actif = "'".EtatDuLien::Actif->value."'";

        return match ($garde) {
            self::CABINETS => [
                [self::typeDe('societe_id')." <> {$cabinet}", 'seul un cabinet d\'expertise comptable a une vérification de cabinet', self::TOUJOURS],
            ],
            self::INVITATIONS => [
                [self::typeDe('societe_id')." <> {$cabinet}", 'seul un cabinet d\'expertise comptable invite une entreprise', self::TOUJOURS],
                ["(NEW.etat = '".EtatInvitation::Ouverte->value."' AND NEW.close_le IS NOT NULL) OR (NEW.etat <> '".EtatInvitation::Ouverte->value."' AND NEW.close_le IS NULL)", 'une invitation close a toujours sa date de fin, une invitation ouverte n\'en a pas', self::TOUJOURS],
            ],
            self::LIENS => [
                [self::typeDe('cabinet_id')." <> {$cabinet}", 'un lien ne se fait qu\'avec un cabinet d\'expertise comptable', self::TOUJOURS],
                [self::typeDe('societe_id')." = {$cabinet}", 'un cabinet d\'expertise comptable n\'est jamais le client d\'un cabinet', self::TOUJOURS],
                ["NEW.jeton_hache IS NOT NULL AND NEW.etat <> '".EtatDuLien::DemandeParLeCabinet->value."'", 'le jeton d\'un lien ne vaut que pour une demande de rattachement en cours', self::TOUJOURS],
                ["NEW.etat NOT IN ({$demandes}) AND NOT (NEW.etat = {$actif} AND NEW.origine = '".OrigineDuLien::Invitation->value."')", 'un lien naît d\'une demande, ou actif par une invitation acceptée', self::INSERTION],
                ['NEW.societe_id <> OLD.societe_id OR NEW.cabinet_id <> OLD.cabinet_id OR NEW.origine <> OLD.origine', 'le client, le cabinet et l\'origine d\'un lien ne changent jamais', self::MODIFICATION],
                ["NEW.etat <> OLD.etat AND NOT ((OLD.etat IN ({$demandes}) AND NEW.etat IN ({$actif}, '".EtatDuLien::Refuse->value."', '".EtatDuLien::Retire->value."', '".EtatDuLien::Expire->value."')) OR (OLD.etat = {$actif} AND NEW.etat = '".EtatDuLien::Termine->value."'))", 'transition du lien non permise', self::MODIFICATION],
            ],
            self::AUTORISATIONS => [
                ['NEW.autorisation NOT IN ('.self::liste(PermissionRegistry::duCabinet(TypeDActivite::Commerce)).')', 'une autorisation hors de celles d\'un cabinet lié ne s\'accorde jamais', self::TOUJOURS],
                ["COALESCE((SELECT famille FROM referentiel_autorisations_magasin WHERE cle = NEW.autorisation), '') = '".PermissionRegistry::FAMILLE_ARTICLES."' AND ".self::typeDe('societe_id')." NOT IN ({$avecDesArticles})", 'une autorisation des articles ne s\'accorde pas chez un client sans articles', self::TOUJOURS],
                ["{$etatDuLien} <> {$actif}", 'une autorisation ne s\'accorde que sur un lien actif', self::TOUJOURS],
            ],
            self::CLIENTS_CONFIES => [
                ["COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), '') <> '".RoleSociete::Collaborateur->value."'", 'un client ne se confie qu\'à un collaborateur du cabinet', self::INSERTION],
                ["{$etatDuLien} <> {$actif}", 'seul un client lié se confie', self::INSERTION],
            ],
        };
    }

    /**
     * Le type d'activité de la société que désigne cette colonne de la
     * ligne écrite, ou un texte vide sans société.
     *
     * @param  literal-string  $colonne
     * @return literal-string
     */
    private static function typeDe(string $colonne): string
    {
        return "COALESCE((SELECT type_activite FROM societes WHERE id = NEW.{$colonne}), '')";
    }

    /**
     * Ces valeurs, en liste SQL.
     *
     * @param  array<array-key, literal-string>  $valeurs
     * @return literal-string
     */
    private static function liste(array $valeurs): string
    {
        return implode(', ', array_map(static fn (string $valeur): string => "'{$valeur}'", $valeurs));
    }

    /**
     * Le message d'un refus, préfixé du nom de sa garde, en littéral SQL
     * (voir LitteralSql).
     *
     * @param  key-of<self::TABLES>  $garde
     * @param  literal-string  $raison
     * @return literal-string
     */
    private static function message(string $garde, string $raison): string
    {
        return LitteralSql::de("{$garde} : {$raison}");
    }
}
