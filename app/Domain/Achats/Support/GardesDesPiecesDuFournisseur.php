<?php

namespace App\Domain\Achats\Support;

use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient les pièces du
 * fournisseur (voir PieceDuFournisseur), même écrites par DB::table(), une
 * requête brute ou une action défectueuse :
 * - une pièce est jointe à exactement un document : une facture d'achat ou
 *   un avoir fournisseur, jamais aux deux ni à aucun ;
 * - une pièce ne se modifie jamais : son fichier, son nom d'origine, son
 *   type, sa taille et son empreinte restent ceux de son dépôt ;
 * - une pièce ne s'efface que d'une facture d'achat encore en brouillon :
 *   jointe à une facture validée ou à un avoir fournisseur émis, elle est
 *   une pièce justificative, conservée comme le document (CLAUDE.md,
 *   « Caisse et clôtures »).
 * Le magasin d'une pièce est celui de son document : ses clés étrangères
 * composites le garantissent (voir la migration
 * create_pieces_fournisseur_table).
 *
 * Une fonction plpgsql par garde, appelée par un déclencheur BEFORE
 * INSERT, BEFORE UPDATE et BEFORE DELETE ; celle de l'effacement
 * verrouille d'abord, en partage, la ligne du document qu'elle lit, pour
 * qu'une validation simultanée passe tout entière avant ou après
 * l'effacement.
 */
final class GardesDesPiecesDuFournisseur
{
    private const TABLE = 'pieces_fournisseur';

    private const UN_SEUL_DOCUMENT = 'pieces_fournisseur_un_seul_document';

    private const JAMAIS_MODIFIEES = 'pieces_fournisseur_jamais_modifiees';

    private const EFFACEES_D_UN_BROUILLON_SEULEMENT = 'pieces_fournisseur_effacees_d_un_brouillon_seulement';

    private const REFUS_UN_SEUL_DOCUMENT = 'une pièce du fournisseur est jointe à une facture d\'achat ou à un avoir fournisseur, et à un seul';

    private const REFUS_MODIFICATION = 'une pièce du fournisseur ne se modifie jamais';

    private const REFUS_EFFACEMENT = 'une pièce du fournisseur ne s\'efface que d\'une facture d\'achat en brouillon';

    /**
     * La condition qui refuse l'effacement d'une pièce (OLD, la ligne
     * effacée) : son document n'est pas une facture d'achat en brouillon.
     */
    private const DOCUMENT_QUI_N_EST_PAS_UN_BROUILLON = "OLD.avoir_fournisseur_id IS NOT NULL OR COALESCE((SELECT statut FROM factures_achat WHERE id = OLD.facture_achat_id), '') <> 'brouillon'";

    public static function poser(): void
    {
        $connexion = DB::connection();
        $table = self::TABLE;

        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_'.self::UN_SEUL_DOCUMENT."() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF (NEW.facture_achat_id IS NULL) = (NEW.avoir_fournisseur_id IS NULL) THEN RAISE EXCEPTION '%', ".self::message(self::UN_SEUL_DOCUMENT, self::REFUS_UN_SEUL_DOCUMENT).'; END IF; RETURN NEW; END $$');
        $connexion->unprepared('CREATE TRIGGER '.self::UN_SEUL_DOCUMENT." BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_".self::UN_SEUL_DOCUMENT.'()');
        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_'.self::JAMAIS_MODIFIEES."() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION '%', ".self::message(self::JAMAIS_MODIFIEES, self::REFUS_MODIFICATION).'; END $$');
        $connexion->unprepared('CREATE TRIGGER '.self::JAMAIS_MODIFIEES." BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_".self::JAMAIS_MODIFIEES.'()');
        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_'.self::EFFACEES_D_UN_BROUILLON_SEULEMENT.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '
            .'PERFORM 1 FROM factures_achat WHERE id = OLD.facture_achat_id FOR SHARE; '
            .'IF '.self::DOCUMENT_QUI_N_EST_PAS_UN_BROUILLON." THEN RAISE EXCEPTION '%', ".self::message(self::EFFACEES_D_UN_BROUILLON_SEULEMENT, self::REFUS_EFFACEMENT).'; END IF; RETURN OLD; END $$');
        $connexion->unprepared('CREATE TRIGGER '.self::EFFACEES_D_UN_BROUILLON_SEULEMENT." BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_".self::EFFACEES_D_UN_BROUILLON_SEULEMENT.'()');
    }

    /**
     * Retire les déclencheurs, puis leurs fonctions.
     */
    public static function retirer(): void
    {
        $connexion = DB::connection();

        foreach ([self::UN_SEUL_DOCUMENT, self::JAMAIS_MODIFIEES, self::EFFACEES_D_UN_BROUILLON_SEULEMENT] as $garde) {
            $connexion->unprepared("DROP TRIGGER IF EXISTS {$garde} ON ".self::TABLE);
            $connexion->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");
        }
    }

    /**
     * Le message d'un refus, préfixé du nom de sa garde, en littéral SQL
     * (voir LitteralSql).
     *
     * @param  literal-string  $garde
     * @param  literal-string  $raison
     * @return literal-string
     */
    private static function message(string $garde, string $raison): string
    {
        return LitteralSql::de("{$garde} : {$raison}");
    }
}
