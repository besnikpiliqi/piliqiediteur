<?php

namespace App\Domain\Facturation\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les gardes par lesquelles la base elle-même refuse une vente ou un
 * encaissement au nom du propriétaire de la société dans un magasin réel,
 * où il ne vend jamais (voir PermissionRegistry::duProprietaire()), ou au
 * nom d'un compte d'un cabinet d'expertise comptable ou d'un
 * expert-comptable de la société, qui n'ont jamais d'autorisation de vente
 * chez un client (voir PermissionRegistry::duCabinet()), même écrits par
 * DB::table(), une
 * requête brute ou une action défectueuse ; elles s'ajoutent aux droits,
 * qui ne leur donnent là aucune autorisation de vente, comme les gardes
 * des droits s'ajoutent aux lectures (voir GardesDesDroits). Seul le
 * magasin de démonstration de sa propre société accepte la vente et
 * l'encaissement du propriétaire, qui s'y entraîne à tout (voir
 * PermissionRegistry::duProprietaireEnDemonstration()) ; un magasin réel
 * ne devient jamais le magasin de démonstration (magasins.est_demonstration
 * figée, voir la migration de création de magasins). Sont gardées :
 * - enregistrements_fiscaux : l'enregistrement signé d'un ticket ou d'une
 *   facture (chaîne des ventes) ou d'un paiement (chaîne des
 *   encaissements) dont l'opérateur est le propriétaire ; un avoir, une
 *   contre-passation, un événement et un enregistrement du système (sans
 *   opérateur) passent ;
 * - factures : une facture ou un ticket créé au nom du propriétaire, qu'on
 *   lui attribue ensuite, ou qu'on émet alors qu'il en est l'auteur. Un
 *   brouillon créé par un administrateur devenu ensuite propriétaire reste
 *   à son nom tant qu'il n'est qu'un brouillon (il se modifie toujours),
 *   et devient à l'émission la vente de qui l'émet (voir
 *   EmettreFactureAction) ; une vente qu'il avait émise comme
 *   administrateur reste la sienne, et son règlement la modifie toujours ;
 * - paiements : un paiement enregistré au nom du propriétaire ; seule sa
 *   contre-passation d'un paiement saisi par erreur passe, quand elle est
 *   l'exact opposé de l'original (même magasin, même client, même mode,
 *   montant opposé, voir ContrePasserPaiementAction) : un « paiement »
 *   qui désigne un original sans en être l'opposé reste un encaissement.
 * Le devis n'a pas d'auteur en base : il n'a pas de garde.
 *
 * Le rôle se lit sur la ligne du compte, verrouillée FOR SHARE, verrou
 * compatible avec celui que la vente tient déjà sur la ligne de son auteur
 * (voir LecteurDesDroits) : une transmission de la propriété, qui
 * verrouille les registres de tous les magasins puis les comptes (voir
 * JournalDesDroits), passe avant ou après, jamais pendant.
 *
 * Une fonction plpgsql et son déclencheur BEFORE INSERT par table (et,
 * pour les factures, BEFORE UPDATE de leur auteur ou de leur numéro).
 */
final class GardesDeLaVente
{
    public const MESSAGE = 'Le propriétaire d\'une société ne vend que dans son magasin de démonstration, et l\'expert-comptable de la société et les comptes d\'un cabinet ne vendent jamais : aucune autre vente ni aucun autre encaissement ne s\'inscrit à leur nom.';

    /**
     * Pour chaque table gardée : la colonne de son auteur, la condition
     * qui fait d'une ligne ajoutée une vente ou un encaissement et, pour
     * une table dont les lignes se modifient, les colonnes dont la
     * modification se contrôle et la condition qui fait de cette
     * modification une vente attribuée à son auteur.
     *
     * @var array<literal-string, array{auteur: literal-string, vente: literal-string, modification: array{colonnes: list<literal-string>, condition: literal-string}|null}>
     */
    private const TABLES = [
        'enregistrements_fiscaux' => [
            'auteur' => 'operateur_id',
            'vente' => "((NEW.chaine = 'vente' AND NEW.type IN ('facture', 'ticket')) OR (NEW.chaine = 'encaissement' AND NEW.type = 'paiement'))",
            'modification' => null,
        ],
        'factures' => [
            'auteur' => 'cree_par',
            'vente' => '1 = 1',
            'modification' => [
                'colonnes' => ['cree_par', 'numero'],
                'condition' => '(OLD.cree_par IS NULL OR OLD.cree_par <> NEW.cree_par OR (OLD.numero IS NULL AND NEW.numero IS NOT NULL))',
            ],
        ],
        'paiements' => [
            'auteur' => 'cree_par',
            'vente' => '(NEW.contre_passe_paiement_id IS NULL OR NOT EXISTS (SELECT 1 FROM paiements AS original WHERE original.id = NEW.contre_passe_paiement_id'
                .' AND original.contre_passe_paiement_id IS NULL AND original.magasin_id = NEW.magasin_id AND original.client_id = NEW.client_id'
                .' AND original.mode_paiement = NEW.mode_paiement AND original.montant = -NEW.montant))',
            'modification' => null,
        ],
    ];

    /**
     * Pose la garde de cette table, une fois la table créée : sa fonction,
     * qui lit le rôle de l'auteur, sous le verrou FOR SHARE de sa ligne,
     * seulement quand la ligne écrite est une vente (à l'ajout, selon la
     * condition de la table ; à la modification, selon celle de la
     * modification, la seule qui lise OLD, la ligne d'avant, nulle à
     * l'ajout), et son déclencheur.
     *
     * @param  literal-string  $table
     *
     * @throws InvalidArgumentException quand la table n'est pas gardée
     */
    public static function poser(string $table): void
    {
        $garde = self::garde($table);
        $nom = self::nom($table);
        $auteur = $garde['auteur'];
        $quand = $garde['modification'] === null ? 'INSERT' : 'INSERT OR UPDATE OF '.implode(', ', $garde['modification']['colonnes']);
        $estUneVente = $garde['modification'] === null
            ? "IF NOT ({$garde['vente']}) THEN RETURN NEW; END IF; "
            : "IF TG_OP = 'UPDATE' THEN IF NOT ({$garde['modification']['condition']}) THEN RETURN NEW; END IF; "
                ."ELSIF NOT ({$garde['vente']}) THEN RETURN NEW; END IF; ";
        $connexion = DB::connection();

        $connexion->unprepared("CREATE OR REPLACE FUNCTION {$nom}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."IF NEW.{$auteur} IS NULL THEN RETURN NEW; END IF; "
            .$estUneVente
            ."PERFORM 1 FROM users WHERE id = NEW.{$auteur} FOR SHARE; "
            .'IF '.self::auteurQuiNeVendPasDansCeMagasin($auteur)." THEN RAISE EXCEPTION '%', ".self::message().'; END IF; '
            .'RETURN NEW; END $$');
        $connexion->unprepared("CREATE TRIGGER {$nom} BEFORE {$quand} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$nom}()");
    }

    /**
     * Retire ce qui reste de la garde une fois sa table supprimée : sa
     * fonction ; ses déclencheurs disparaissent avec la table.
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
     * @return array{auteur: literal-string, vente: literal-string, modification: array{colonnes: list<literal-string>, condition: literal-string}|null}
     *
     * @throws InvalidArgumentException
     */
    private static function garde(string $table): array
    {
        return self::TABLES[$table] ?? throw new InvalidArgumentException("La table {$table} n'a pas de garde de la vente.");
    }

    /**
     * @param  literal-string  $table
     * @return literal-string
     */
    private static function nom(string $table): string
    {
        return "garde_vente_{$table}";
    }

    /**
     * L'auteur de la ligne écrite est, à cet instant, un compte qui ne vend
     * pas dans le magasin de cette ligne : un collaborateur d'un cabinet ou
     * un expert-comptable de la société, qui ne vendent jamais, ou le
     * propriétaire d'une société (celui d'un
     * cabinet d'expertise comptable compris), sauf dans le magasin de
     * démonstration de sa société, le seul où il vend. Le rôle suffit : un
     * compte d'un cabinet a toujours l'un de ces deux rôles, la garde des
     * rôles l'impose (voir GardesDesDroits), et un cabinet n'a aucun
     * magasin, démonstration comprise (voir GardesDesTypesDActivite).
     * Chaque table gardée porte le magasin de sa ligne (magasin_id), dont
     * est_demonstration ne change jamais.
     *
     * @param  literal-string  $auteur
     * @return literal-string
     */
    private static function auteurQuiNeVendPasDansCeMagasin(string $auteur): string
    {
        return "EXISTS (SELECT 1 FROM users WHERE id = NEW.{$auteur} AND (role_societe IN ('".RoleSociete::Collaborateur->value."', '".RoleSociete::ExpertComptable->value."')"
            ." OR (role_societe = '".RoleSociete::Proprietaire->value."' AND NOT EXISTS (SELECT 1 FROM magasins"
            .' WHERE magasins.id = NEW.magasin_id AND magasins.est_demonstration AND magasins.societe_id = users.societe_id))))';
    }

    /**
     * Le message du refus, en littéral SQL (voir LitteralSql).
     *
     * @return literal-string
     */
    private static function message(): string
    {
        return LitteralSql::de(self::MESSAGE);
    }
}
