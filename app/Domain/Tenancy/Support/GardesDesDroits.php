<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même refuse un état des droits
 * incohérent avec le rôle de société d'un compte, même écrit par
 * DB::table(), une requête brute ou une action défectueuse. Elles
 * s'ajoutent aux lectures qui échouent fermées selon le rôle (voir
 * User::autorisationsDans()) et aux suppressions que font les actions des
 * droits, dans l'ordre de leurs transitions (voir JournalDesDroits) :
 * - affectations_coherentes_avec_le_role (affectations) : un rôle de
 *   magasin qui n'est ni nul ni responsable, vendeur ou observateur ;
 *   l'affectation du propriétaire, d'un collaborateur ou d'un
 *   expert-comptable de la société ; une
 *   affectation sans rôle hors d'un administrateur, ou avec rôle pour un
 *   administrateur ; une affectation à tous les magasins avec un rôle ;
 *   une affectation qui devient celle d'un responsable avec d'autres cases
 *   que celles que seul le propriétaire coche ; un
 *   administrateur à la fois affecté à tous les magasins et à une
 *   sélection ; une affectation de membre hors du magasin de rattachement
 *   de l'identifiant du compte (voir la table identifiants) : un compte de
 *   membre ne vaut que pour un magasin ;
 * - autorisations_affectations_sans_responsable (autorisations_affectations) :
 *   une case sur l'affectation d'un responsable, qui a sans case toutes
 *   les autorisations de son magasin, sauf celles que seul le propriétaire
 *   coche (voir PermissionRegistry::cocheesParLeProprietaire()) ;
 * - autorisations_societe_administrateur_seul (autorisations_societe) :
 *   une autorisation de société pour un compte qui n'est pas
 *   administrateur ;
 * - users_role_coherent_avec_les_droits (users) : un rôle de société
 *   inconnu ; devenir propriétaire, collaborateur ou expert-comptable de
 *   la société avec une affectation ou une autorisation de société ; devenir administrateur
 *   avec l'affectation d'un membre ; devenir membre avec l'affectation d'un
 *   administrateur ou une autorisation de société ; un propriétaire dont
 *   l'adresse de connexion n'est pas confirmée par un lien (sans date de
 *   confirmation ; un propriétaire sans adresse de connexion passe, voir
 *   SaisirCodeDeConfirmationAction et CloreLInscriptionAction), ou dont le
 *   mot de passe est encore son identifiant ; un expert-comptable de la
 *   société dont le mot de passe est son identifiant ; le rôle de propriétaire,
 *   d'administrateur ou de collaborateur pour un compte d'entraînement
 *   (rattaché au magasin de démonstration), qui n'ouvre jamais un magasin
 *   réel ; un collaborateur hors d'un cabinet d'expertise comptable, un
 *   administrateur, un membre ou un expert-comptable de la société dans
 *   un cabinet (le type d'une société ne change jamais : lu sans verrou).
 *   Les gardes propres à l'expert-comptable de la société (sa déclaration,
 *   son adresse, son rôle qui ne se prend ni ne se quitte) sont celles de
 *   GardesDesCabinets.
 *
 * Chaque garde est une fonction plpgsql et son déclencheur BEFORE INSERT
 * OR UPDATE ; celui de users ne se déclenche qu'à l'insertion et à la
 * modification de role_societe, email, email_verified_at ou
 * mot_de_passe_est_l_identifiant. Chaque fonction verrouille d'abord la
 * ligne parente qu'elle lit : la ligne du compte (FOR NO KEY UPDATE) pour
 * une affectation, si bien que deux écritures d'affectations du même
 * compte, ou une affectation et un changement de rôle, se suivent toujours
 * et que la seconde voit la première ; la ligne de l'affectation (FOR
 * SHARE) pour une case ; la ligne du compte (FOR SHARE) pour une
 * autorisation de société. Le FOR KEY SHARE d'une clé étrangère ne
 * suffirait pas : une garde et un changement simultanés passeraient tous
 * les deux. Ces verrous se prennent dans la transaction qui écrit, après
 * ceux qu'elle tient déjà (les registres, puis les comptes, voir
 * JournalDesDroits) : aucun interblocage.
 *
 * Elles se posent quand toutes les tables qu'elles lisent existent (voir
 * la migration des autorisations de société) et se retirent avant elles,
 * fonctions comprises, par retirer().
 */
final class GardesDesDroits
{
    public const AFFECTATIONS = 'affectations_coherentes_avec_le_role';

    public const CASES = 'autorisations_affectations_sans_responsable';

    public const AUTORISATIONS_SOCIETE = 'autorisations_societe_administrateur_seul';

    public const ROLES = 'users_role_coherent_avec_les_droits';

    /**
     * Les colonnes de users dont la modification déclenche la garde des
     * rôles.
     */
    private const COLONNES_DES_ROLES = 'role_societe, email, email_verified_at, mot_de_passe_est_l_identifiant';

    /**
     * La table que protège chaque garde.
     */
    private const TABLES = [
        self::AFFECTATIONS => 'affectations',
        self::CASES => 'autorisations_affectations',
        self::AUTORISATIONS_SOCIETE => 'autorisations_societe',
        self::ROLES => 'users',
    ];

    public static function poser(): void
    {
        $connexion = DB::connection();

        foreach (self::refus() as $garde => $refus) {
            $controles = implode(' ', array_map(
                static fn (array $un): string => "IF {$un[0]} THEN RAISE EXCEPTION '%', ".self::message($garde, $un[1]).'; END IF;',
                $refus,
            ));
            $table = self::TABLES[$garde];
            $quand = $garde === self::ROLES ? 'INSERT OR UPDATE OF '.self::COLONNES_DES_ROLES : 'INSERT OR UPDATE';

            $connexion->unprepared("CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '.self::verrou($garde)." {$controles} RETURN NEW; END \$\$");
            $connexion->unprepared("CREATE TRIGGER {$garde} BEFORE {$quand} ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_{$garde}()");
        }
    }

    /**
     * Retire les déclencheurs des gardes, puis leurs fonctions, que les
     * déclencheurs empêcheraient sinon de retirer.
     */
    public static function retirer(): void
    {
        $connexion = DB::connection();

        foreach (self::TABLES as $garde => $table) {
            $connexion->unprepared("DROP TRIGGER IF EXISTS {$garde} ON {$table}");
            $connexion->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");
        }
    }

    /**
     * Les refus de chaque garde : la condition qui refuse, en SQL (NEW
     * désigne la ligne écrite, dont l'identifiant est déjà tiré de sa
     * séquence quand le déclencheur d'une insertion la lit), et son
     * message.
     *
     * @return array<key-of<self::TABLES>, list<array{0: literal-string, 1: literal-string}>>
     */
    private static function refus(): array
    {
        $rolesDeMagasin = self::liste(array_map(static fn (RoleMagasin $role): string => $role->value, RoleMagasin::cases()));
        $rolesDeSociete = self::liste(array_map(static fn (RoleSociete $role): string => $role->value, RoleSociete::cases()));
        $sansAffectation = self::liste([RoleSociete::Proprietaire->value, RoleSociete::Collaborateur->value, RoleSociete::ExpertComptable->value]);
        $expertComptable = "'".RoleSociete::ExpertComptable->value."'";
        $administrateur = "'".RoleSociete::Administrateur->value."'";
        $membre = "'".RoleSociete::Membre->value."'";
        $responsable = "'".RoleMagasin::Responsable->value."'";
        $cocheesParLeProprietaire = self::liste(PermissionRegistry::cocheesParLeProprietaire(TypeDActivite::Commerce));
        $roleDuCompte = 'COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), \'\')';
        $autresAffectations = 'SELECT 1 FROM affectations WHERE user_id = NEW.user_id AND id <> NEW.id';
        $magasinDeSonIdentifiant = 'COALESCE((SELECT magasin_id FROM identifiants WHERE user_id = NEW.user_id), -1)';
        $horsDuMagasin = self::liste([RoleSociete::Proprietaire->value, RoleSociete::Administrateur->value, RoleSociete::Collaborateur->value]);
        $collaborateur = "'".RoleSociete::Collaborateur->value."'";
        $typeDeLaSociete = "COALESCE((SELECT type_activite FROM societes WHERE id = NEW.societe_id), '')";
        $cabinet = "'".TypeDActivite::Cabinet->value."'";
        $proprietaire = "'".RoleSociete::Proprietaire->value."'";
        $compteDEntrainement = 'EXISTS (SELECT 1 FROM identifiants INNER JOIN magasins ON magasins.id = identifiants.magasin_id WHERE identifiants.user_id = NEW.id AND magasins.est_demonstration = TRUE)';

        return [
            self::AFFECTATIONS => [
                ["NEW.role IS NOT NULL AND NEW.role NOT IN ({$rolesDeMagasin})", 'rôle de magasin inconnu'],
                ["{$roleDuCompte} IN ({$sansAffectation})", 'le propriétaire, un collaborateur d\'un cabinet et un expert-comptable de la société n\'ont aucune affectation'],
                ["NEW.role IS NULL AND {$roleDuCompte} <> {$administrateur}", 'seul un administrateur a une affectation sans rôle'],
                ["NEW.role IS NOT NULL AND {$roleDuCompte} = {$administrateur}", 'un administrateur n\'a aucun rôle de magasin'],
                ['NEW.magasin_id IS NULL AND NEW.role IS NOT NULL', 'une affectation à tous les magasins n\'a pas de rôle de magasin'],
                ["NEW.role = {$responsable} AND EXISTS (SELECT 1 FROM autorisations_affectations WHERE affectation_id = NEW.id AND autorisation NOT IN ({$cocheesParLeProprietaire}))", 'un responsable n\'a de case que celles que seul le propriétaire coche'],
                ["NEW.magasin_id IS NULL AND EXISTS ({$autresAffectations} AND magasin_id IS NOT NULL)", 'un administrateur a accès à tous les magasins ou à une sélection, jamais aux deux'],
                ["NEW.magasin_id IS NOT NULL AND EXISTS ({$autresAffectations} AND magasin_id IS NULL)", 'un administrateur a accès à tous les magasins ou à une sélection, jamais aux deux'],
                ["NEW.role IS NOT NULL AND {$magasinDeSonIdentifiant} <> COALESCE(NEW.magasin_id, -2)", 'un membre n\'a d\'affectation que dans le magasin de son identifiant'],
            ],
            self::CASES => [
                ["COALESCE((SELECT role FROM affectations WHERE id = NEW.affectation_id), '') = {$responsable} AND NEW.autorisation NOT IN ({$cocheesParLeProprietaire})", 'un responsable n\'a de case que celles que seul le propriétaire coche'],
            ],
            self::AUTORISATIONS_SOCIETE => [
                ["{$roleDuCompte} <> {$administrateur}", 'seul un administrateur a des autorisations de société'],
            ],
            self::ROLES => [
                ["NEW.role_societe NOT IN ({$rolesDeSociete})", 'rôle de société inconnu'],
                ["NEW.role_societe IN ({$sansAffectation}) AND (EXISTS (SELECT 1 FROM affectations WHERE user_id = NEW.id) OR EXISTS (SELECT 1 FROM autorisations_societe WHERE user_id = NEW.id))", 'le propriétaire, un collaborateur d\'un cabinet et un expert-comptable de la société n\'ont ni affectation ni autorisation de société'],
                ["NEW.role_societe = {$collaborateur} AND {$typeDeLaSociete} <> {$cabinet}", 'un collaborateur n\'existe que dans un cabinet d\'expertise comptable'],
                ["NEW.role_societe IN ({$administrateur}, {$membre}, {$expertComptable}) AND {$typeDeLaSociete} = {$cabinet}", 'un cabinet d\'expertise comptable n\'a ni administrateur, ni membre, ni expert-comptable de société'],
                ["NEW.role_societe = {$administrateur} AND EXISTS (SELECT 1 FROM affectations WHERE user_id = NEW.id AND role IS NOT NULL)", 'un administrateur n\'a aucune affectation de membre'],
                ["NEW.role_societe = {$membre} AND (EXISTS (SELECT 1 FROM affectations WHERE user_id = NEW.id AND role IS NULL) OR EXISTS (SELECT 1 FROM autorisations_societe WHERE user_id = NEW.id))", 'un membre n\'a ni affectation d\'administrateur ni autorisation de société'],
                ["NEW.role_societe = {$proprietaire} AND NEW.email IS NOT NULL AND NEW.email_verified_at IS NULL", 'l\'adresse de connexion d\'un propriétaire est confirmée par un lien'],
                ["NEW.role_societe = {$proprietaire} AND NEW.mot_de_passe_est_l_identifiant = TRUE", 'un propriétaire n\'a jamais pour mot de passe son identifiant'],
                ["NEW.role_societe = {$expertComptable} AND NEW.mot_de_passe_est_l_identifiant = TRUE", 'un expert-comptable de la société n\'a jamais pour mot de passe son identifiant'],
                ["NEW.role_societe IN ({$horsDuMagasin}) AND {$compteDEntrainement}", 'un compte d\'entraînement ne change jamais de rôle de société'],
            ],
        ];
    }

    /**
     * La ligne parente que chaque fonction verrouille avant de lire (voir
     * le docblock de la classe) ; aucune pour users, dont la ligne écrite
     * est déjà verrouillée.
     *
     * @return literal-string
     */
    private static function verrou(string $garde): string
    {
        return match ($garde) {
            self::AFFECTATIONS => 'PERFORM 1 FROM users WHERE id = NEW.user_id FOR NO KEY UPDATE;',
            self::CASES => 'PERFORM 1 FROM affectations WHERE id = NEW.affectation_id FOR SHARE;',
            self::AUTORISATIONS_SOCIETE => 'PERFORM 1 FROM users WHERE id = NEW.user_id FOR SHARE;',
            default => '',
        };
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

    /**
     * @param  list<literal-string>  $valeurs
     * @return literal-string
     */
    private static function liste(array $valeurs): string
    {
        return implode(', ', array_map(static fn (string $valeur): string => "'{$valeur}'", $valeurs));
    }
}
