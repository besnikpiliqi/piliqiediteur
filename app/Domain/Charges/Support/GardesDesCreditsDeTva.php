<?php

namespace App\Domain\Charges\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient le crédit de TVA
 * déclaré d'une société (lot TVA27, voir CreditDeTva), même écrit par
 * DB::table(), une requête brute ou une action défectueuse :
 * - credits_de_tva_d_une_societe_au_reel : une déclaration ne s'ajoute que
 *   dans une société qui n'est pas un cabinet d'expertise comptable, ni
 *   membre d'un assujetti unique (le groupe déclare pour elle, voir
 *   CreditsDeTva::aLieuDansLa()), au réel normal de la cadence de la
 *   déclaration (mensuel ou trimestriel) : jamais en franchise en base, ni
 *   avant le choix du régime ;
 * - credits_de_tva_figee : sa société, sa cadence, sa période, son uuid et
 *   l'instant de sa création ne changent jamais ;
 * et, posée par la migration (voir DeclencheursDeProtection::sansSuppression()),
 * jamais aucune ne s'efface. Le solde, jamais positif, et la cohérence de
 * sa vérification sont des contraintes CHECK de sa table.
 */
final class GardesDesCreditsDeTva
{
    private const SOCIETE = 'credits_de_tva_d_une_societe_au_reel';

    private const FIGEE = 'credits_de_tva_figee';

    public const REFUS_SOCIETE = 'un crédit de TVA ne se déclare que dans une société au réel normal de sa cadence, jamais dans un cabinet d\'expertise comptable, dans une société membre d\'un assujetti unique, en franchise en base ni avant le choix du régime';

    public const REFUS_FIGEE = 'la société, la cadence et la période d\'un crédit de TVA déclaré ne changent jamais';

    public static function poser(): void
    {
        $connexion = DB::connection();
        $cabinet = "'".TypeDActivite::Cabinet->value."'";
        $mensuelle = "'".CadenceDeLaTva::Mensuelle->value."'";
        $regimeMensuel = "'".RegimeTva::ReelNormalMensuel->value."'";
        $regimeTrimestriel = "'".RegimeTva::ReelNormalTrimestriel->value."'";

        // Une société qui n'existe pas : sa clé étrangère refuse la ligne.
        self::fonction(self::SOCIETE, 'DECLARE la_societe RECORD; BEGIN '
            .'SELECT type_activite, regime_tva, membre_assujetti_unique INTO la_societe FROM societes WHERE id = NEW.societe_id; '
            .'IF NOT FOUND THEN RETURN NEW; END IF; '
            ."IF la_societe.type_activite = {$cabinet} OR la_societe.membre_assujetti_unique OR la_societe.regime_tva IS DISTINCT FROM (CASE WHEN NEW.cadence = {$mensuelle} THEN {$regimeMensuel} ELSE {$regimeTrimestriel} END) "
            ."THEN RAISE EXCEPTION '%', ".LitteralSql::de(self::SOCIETE.' : '.self::REFUS_SOCIETE).'; END IF; '
            .'RETURN NEW; END');
        $connexion->unprepared('CREATE TRIGGER '.self::SOCIETE.' BEFORE INSERT ON credits_de_tva FOR EACH ROW EXECUTE FUNCTION garde_'.self::SOCIETE.'()');

        self::fonction(self::FIGEE, 'BEGIN '
            .'IF (NEW.id, NEW.uuid, NEW.societe_id, NEW.cadence, NEW.periode, NEW.created_at) IS DISTINCT FROM (OLD.id, OLD.uuid, OLD.societe_id, OLD.cadence, OLD.periode, OLD.created_at) '
            ."THEN RAISE EXCEPTION '%', ".LitteralSql::de(self::FIGEE.' : '.self::REFUS_FIGEE).'; END IF; '
            .'RETURN NEW; END');
        $connexion->unprepared('CREATE TRIGGER '.self::FIGEE.' BEFORE UPDATE ON credits_de_tva FOR EACH ROW EXECUTE FUNCTION garde_'.self::FIGEE.'()');
    }

    public static function retirer(): void
    {
        foreach ([self::SOCIETE, self::FIGEE] as $garde) {
            DB::connection()->unprepared("DROP TRIGGER IF EXISTS {$garde} ON credits_de_tva");
            DB::connection()->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");
        }
    }

    /**
     * @param  literal-string  $nom
     * @param  literal-string  $corps
     */
    private static function fonction(string $nom, string $corps): void
    {
        DB::connection()->unprepared("CREATE OR REPLACE FUNCTION garde_{$nom}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE." AS \$\$ {$corps} \$\$");
    }
}
