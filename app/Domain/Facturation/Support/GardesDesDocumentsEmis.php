<?php

namespace App\Domain\Facturation\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les gardes par lesquelles la base elle-même refuse de supprimer ou de
 * réécrire une facture, un ticket ou un avoir émis, ou ses lignes, même
 * par DB::table(), une requête brute ou une action défectueuse : elles
 * s'ajoutent à la protection des modèles (voir ProtegeLesDonneesValidees),
 * qu'une requête directe ne voit pas. Un document est émis dès qu'il a un
 * numéro ; un brouillon, sans numéro, se modifie et se supprime avec ses
 * lignes.
 * - factures, avoirs : un document émis ne se supprime jamais, et seules
 *   changent encore les colonnes qui suivent le document après son
 *   émission (COLONNES_QUI_SUIVENT : le statut de règlement d'une facture,
 *   le ticket Z qui a compté le document, la date de mise à jour) ; la
 *   ligne entière est comparée, hors de ces colonnes, ce qui couvre toute
 *   colonne ajoutée plus tard. L'émission fige tout dans l'écriture qui
 *   numérote (voir EmettreFactureAction et EmettreAvoirAction) : rien
 *   d'autre ne s'écrit ensuite sur le document.
 * - lignes_facture, lignes_avoir : une ligne ne s'ajoute, ne se modifie ni
 *   ne se supprime quand son document (avant ou après l'écriture) est
 *   numéroté ; la ligne du document est d'abord verrouillée (FOR SHARE)
 *   pour se sérialiser avec son émission, qui la verrouille avant de la
 *   numéroter.
 * Une fonction plpgsql et son déclencheur BEFORE par table, posés à la
 * fin de la migration de création de la table, retirés dans son down().
 */
final class GardesDesDocumentsEmis
{
    /**
     * Pour chaque document : son message de suppression, son message de
     * modification et les colonnes qui le suivent encore une fois émis.
     *
     * @var array<literal-string, array{suppression: literal-string, modification: literal-string, colonnes_qui_suivent: list<literal-string>}>
     */
    private const DOCUMENTS = [
        'factures' => [
            'suppression' => 'factures : une facture ou un ticket émis ne se supprime jamais',
            'modification' => 'factures : une facture ou un ticket émis ne se modifie plus, seul son statut de règlement évolue',
            'colonnes_qui_suivent' => ['statut', 'updated_at', 'cloture_caisse_id'],
        ],
        'avoirs' => [
            'suppression' => 'avoirs : un avoir émis ne se supprime jamais',
            'modification' => 'avoirs : un avoir émis ne se modifie plus',
            'colonnes_qui_suivent' => ['updated_at', 'cloture_caisse_id'],
        ],
    ];

    /**
     * Pour chaque table de lignes : son document, la colonne qui le
     * désigne et son message de refus.
     *
     * @var array<literal-string, array{document: literal-string, colonne: literal-string, refus: literal-string}>
     */
    private const LIGNES = [
        'lignes_facture' => [
            'document' => 'factures',
            'colonne' => 'facture_id',
            'refus' => 'lignes_facture : les lignes d\'une facture ou d\'un ticket émis ne changent plus',
        ],
        'lignes_avoir' => [
            'document' => 'avoirs',
            'colonne' => 'avoir_id',
            'refus' => 'lignes_avoir : les lignes d\'un avoir émis ne changent plus',
        ],
    ];

    /**
     * Le message de suppression d'un document émis.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas celle d'un document gardé
     */
    public static function refusDeSuppression(string $table): string
    {
        return self::document($table)['suppression'];
    }

    /**
     * Le message de modification d'un document émis.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas celle d'un document gardé
     */
    public static function refusDeModification(string $table): string
    {
        return self::document($table)['modification'];
    }

    /**
     * Le message de refus d'une écriture sur une ligne d'un document émis.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas celle de lignes gardées
     */
    public static function refusSurUneLigne(string $table): string
    {
        return self::lignes($table)['refus'];
    }

    /**
     * Pose la garde de cette table, document ou lignes, une fois la table
     * créée.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas gardée
     */
    public static function poser(string $table): void
    {
        $connexion = DB::connection();
        $nom = self::nom($table);

        if (array_key_exists($table, self::DOCUMENTS)) {
            $garde = self::DOCUMENTS[$table];
            $hors = "ARRAY['".implode("', '", $garde['colonnes_qui_suivent'])."']";

            $connexion->unprepared("CREATE OR REPLACE FUNCTION {$nom}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
                ."IF OLD.numero IS NULL THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; "
                ."IF TG_OP = 'DELETE' THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
                ."IF (to_jsonb(NEW) - {$hors}::text[]) IS DISTINCT FROM (to_jsonb(OLD) - {$hors}::text[]) THEN RAISE EXCEPTION '%', TG_ARGV[1]; END IF; "
                .'RETURN NEW; END $$');
            $connexion->unprepared("CREATE TRIGGER {$nom} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$nom}(".LitteralSql::de($garde['suppression']).', '.LitteralSql::de($garde['modification']).')');

            return;
        }

        $garde = self::lignes($table);
        $document = $garde['document'];
        $colonne = $garde['colonne'];
        $numerote = fn (string $ligne): string => "PERFORM 1 FROM {$document} WHERE id = {$ligne}.{$colonne} FOR SHARE; "
            ."IF EXISTS (SELECT 1 FROM {$document} WHERE id = {$ligne}.{$colonne} AND numero IS NOT NULL) THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; ";

        $connexion->unprepared("CREATE OR REPLACE FUNCTION {$nom}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."IF TG_OP <> 'INSERT' THEN ".$numerote('OLD').'END IF; '
            ."IF TG_OP <> 'DELETE' THEN ".$numerote('NEW').'RETURN NEW; END IF; '
            .'RETURN OLD; END $$');
        $connexion->unprepared("CREATE TRIGGER {$nom} BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$nom}(".LitteralSql::de($garde['refus']).')');
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
        if (! array_key_exists($table, self::DOCUMENTS)) {
            self::lignes($table);
        }

        DB::connection()->unprepared('DROP FUNCTION IF EXISTS '.self::nom($table).'()');
    }

    /**
     * Le nom de la fonction et du déclencheur de la garde de cette table.
     *
     * @param  literal-string  $table
     * @return literal-string
     */
    public static function nom(string $table): string
    {
        return "garde_documents_emis_{$table}";
    }

    /**
     * @return array{suppression: literal-string, modification: literal-string, colonnes_qui_suivent: list<literal-string>}
     *
     * @throws InvalidArgumentException
     */
    private static function document(string $table): array
    {
        return self::DOCUMENTS[$table] ?? throw new InvalidArgumentException("La table {$table} n'est pas celle d'un document gardé.");
    }

    /**
     * @return array{document: literal-string, colonne: literal-string, refus: literal-string}
     *
     * @throws InvalidArgumentException
     */
    private static function lignes(string $table): array
    {
        return self::LIGNES[$table] ?? throw new InvalidArgumentException("La table {$table} n'est pas celle de lignes gardées.");
    }
}
