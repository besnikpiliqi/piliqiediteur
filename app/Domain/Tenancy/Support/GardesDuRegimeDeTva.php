<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les gardes par lesquelles la base elle-même refuse toute opération
 * commerciale réelle d'une société dont le régime de TVA n'est pas encore
 * choisi (décision de Claude, D76, lot TVA27 ; voir
 * Magasin::refusDeVente()), même écrite par DB::table(), une requête brute
 * ou une action défectueuse :
 * - enregistrements_fiscaux : l'enregistrement signé d'une vente (ticket,
 *   facture, avoir) ou d'un encaissement (paiement, contre-passation) ;
 *   un événement du journal passe toujours ;
 * - devis : le numéro donné à un devis, à son envoi (voir
 *   DocumentNumberingService), qui n'a pas d'enregistrement signé.
 * Le régime de la société se lit par le magasin de la ligne. Il ne
 * redevient jamais vide une fois choisi (voir
 * ModifierParametresFiscauxSocieteAction), et sa modification se fait
 * sous le verrou des registres de tous les magasins de la société : une
 * vente qui attendait ce verrou lit le régime d'après.
 *
 * Une fonction plpgsql et son déclencheur BEFORE INSERT (et, pour les
 * devis, BEFORE UPDATE de leur numéro) par table, posés dans la migration
 * de création de la table et retirés avant elle.
 */
final class GardesDuRegimeDeTva
{
    public const MESSAGE = 'Le régime de TVA de la société n\'est pas choisi : aucune vente, aucun encaissement ni aucun devis numéroté ne s\'enregistre avant lui.';

    /**
     * Pour chaque table gardée : quand la garde se déclenche, et ce qui
     * fait de la ligne écrite une opération commerciale réelle, écrit en
     * instructions plpgsql qui rendent la ligne telle quelle quand elle
     * n'en est pas une.
     *
     * @var array<literal-string, array{quand: literal-string, hors_operation: literal-string}>
     */
    private const TABLES = [
        'enregistrements_fiscaux' => [
            'quand' => 'INSERT',
            'hors_operation' => "IF NEW.chaine NOT IN ('vente', 'encaissement') THEN RETURN NEW; END IF; ",
        ],
        'devis' => [
            'quand' => 'INSERT OR UPDATE OF numero',
            'hors_operation' => "IF NEW.numero IS NULL THEN RETURN NEW; END IF; IF TG_OP = 'UPDATE' THEN IF OLD.numero IS NOT NULL THEN RETURN NEW; END IF; END IF; ",
        ],
    ];

    /**
     * Pose la garde de cette table, une fois la table créée.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas gardée
     */
    public static function poser(string $table): void
    {
        $garde = self::garde($table);
        $nom = self::nom($table);
        $connexion = DB::connection();

        $connexion->unprepared("CREATE OR REPLACE FUNCTION {$nom}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            .$garde['hors_operation']
            .'IF EXISTS (SELECT 1 FROM magasins INNER JOIN societes ON societes.id = magasins.societe_id WHERE magasins.id = NEW.magasin_id AND societes.regime_tva IS NULL) '
            ."THEN RAISE EXCEPTION '%', ".LitteralSql::de(self::MESSAGE).'; END IF; '
            .'RETURN NEW; END $$');
        $connexion->unprepared("CREATE TRIGGER {$nom} BEFORE {$garde['quand']} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$nom}()");
    }

    /**
     * Retire ce qui reste de la garde une fois sa table supprimée : sa
     * fonction ; son déclencheur disparaît avec la table.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas gardée
     */
    public static function retirer(string $table): void
    {
        self::garde($table);

        DB::connection()->unprepared('DROP FUNCTION IF EXISTS '.self::nom($table).'()');
    }

    /**
     * @return array{quand: literal-string, hors_operation: literal-string}
     *
     * @throws InvalidArgumentException
     */
    private static function garde(string $table): array
    {
        return self::TABLES[$table] ?? throw new InvalidArgumentException("La table {$table} n'a pas de garde du régime de TVA.");
    }

    /**
     * @param  literal-string  $table
     * @return literal-string
     */
    private static function nom(string $table): string
    {
        return "garde_regime_tva_{$table}";
    }
}
