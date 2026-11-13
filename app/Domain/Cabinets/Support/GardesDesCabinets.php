<?php

namespace App\Domain\Cabinets\Support;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
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
 *   un jeton hors d'une demande du cabinet en cours ; un lien sans cabinet
 *   hors d'une déclaration du client en attente ou terminée, une
 *   déclaration en attente avec un cabinet, un SIREN déclaré hors d'une
 *   déclaration ou une déclaration sans SIREN ; une déclaration de son
 *   propre SIREN, ou dans une société qui n'est pas validée ; un lien qui
 *   naît dans un autre état qu'une demande ou une déclaration, ou actif
 *   hors d'une invitation acceptée ; un client, une origine, un SIREN
 *   déclaré ou un cabinet qui change, sauf le cabinet qu'une déclaration
 *   reçoit à son rattachement, qui doit être celui de son SIREN ; une
 *   transition qui n'est pas permise (une demande devient active,
 *   refusée, retirée ou expirée, une déclaration devient active ou
 *   terminée, un lien actif devient terminé, tout autre état est final) ;
 *   un lien qui naît ou devient actif avec un cabinet qui n'est pas
 *   vérifié (voir Societe::estUnCabinetVerifie()) : une déclaration ne se
 *   rattache qu'à un cabinet vérifié ;
 * - autorisations_cabinet_permises (autorisations_cabinet) : une
 *   autorisation hors de la liste de celles qu'un client peut accorder à
 *   un cabinet (PermissionRegistry::duCabinet() pour un client qui a des
 *   articles, écrite en toutes lettres), une autorisation de la famille
 *   des articles chez un client qui n'en a pas, une ligne sur un lien qui
 *   n'est ni actif ni une déclaration en attente : un compte d'un cabinet
 *   ou un expert-comptable de la société ne vend jamais ;
 * - autorisations_cabinet_par_paires (autorisations_cabinet, déclencheur
 *   de contrainte différé, voir poserLesPaires()) : « voir » les factures
 *   ou les clôtures de caisse sans « voir celles de toute l'équipe », ou
 *   l'inverse ;
 * - clients_confies_a_un_collaborateur (clients_confies) : un compte qui
 *   n'est pas collaborateur, un lien qui n'est pas actif ;
 * - magasins_cabinet_d_un_lien_actif (magasins_cabinet) : le magasin de
 *   démonstration, une ligne sur un lien qui n'est ni actif ni une
 *   déclaration en attente, ou qui ouvre tous les magasins ;
 * - liens_cabinet_tous_les_magasins_ou_une_selection (liens_cabinet et
 *   magasins_cabinet, déclencheurs de contrainte différés, voir
 *   poserLaSelectionDesMagasins()) : un lien actif ou une déclaration en
 *   attente qui n'ouvre ni tous les magasins ni au moins un magasin
 *   choisi, ou les deux ;
 * - comptes_expert_comptable_coherents (comptes_expert_comptable) : un
 *   compte attaché qui n'est pas un expert-comptable de la société, ou
 *   attaché à une déclaration qui n'est plus en attente ; un compte qui
 *   change de déclaration ; un lien du mot de passe sans échéance, ou
 *   hors d'une déclaration en attente ; la date du premier mot de passe
 *   choisi qui s'efface ou change ;
 * - users_expert_comptable_de_sa_declaration (users) : un expert-comptable
 *   de la société ouvert (non retiré) dont la déclaration n'est plus en
 *   attente, ou sans adresse ; un compte qui prend ou quitte ce rôle ;
 * - liens_cabinet_declaration_sans_compte_ouvert (liens_cabinet) : une
 *   déclaration qui prend fin ou se rattache avec un compte
 *   d'expert-comptable encore ouvert : ses comptes se retirent avant.
 * - magasins_ouverts_a_l_expert_comptable (factures_achat,
 *   avoirs_fournisseur, inventaires_ouverture, pieces_fournisseur,
 *   telechargements_documents, voir poserLaFonctionDesMagasinsOuverts()) :
 *   un compte d'un cabinet ou un expert-comptable de la société qui agit
 *   dans un magasin que le client ne lui ouvre pas.
 *
 * Chacune se pose dans la migration de création de sa table, et se retire
 * avant elle, fonction comprise, par retirer() ; celles de
 * l'expert-comptable de la société se posent avec comptes_expert_comptable,
 * la dernière table qu'elles lisent. Le type d'une société ne
 * changeant jamais (voir GardesDesTypesDActivite), une garde qui le lit ne
 * verrouille rien : aucune écriture simultanée ne peut le rendre faux.
 *
 * Chaque garde est une fonction plpgsql et son déclencheur BEFORE (INSERT,
 * ou INSERT OR UPDATE OF les colonnes qu'elle lit). Celle d'un
 * expert-comptable de la société ouvert prend d'abord sa déclaration en
 * partage (voir verrou()) : toute action qui rouvre un tel compte tient
 * déjà sa déclaration, si bien qu'une écriture fautive attend, puis voit
 * l'état d'après.
 */
final class GardesDesCabinets
{
    public const CABINETS = 'cabinets_de_type_cabinet';

    public const INVITATIONS = 'invitations_d_un_cabinet';

    public const LIENS = 'liens_entre_un_cabinet_et_un_client';

    public const AUTORISATIONS = 'autorisations_cabinet_permises';

    public const CLIENTS_CONFIES = 'clients_confies_a_un_collaborateur';

    public const PAIRES = 'autorisations_cabinet_par_paires';

    public const MAGASINS = 'magasins_cabinet_d_un_lien_actif';

    public const SELECTION = 'liens_cabinet_tous_les_magasins_ou_une_selection';

    public const COMPTES_EXPERT_COMPTABLE = 'comptes_expert_comptable_coherents';

    public const EXPERT_COMPTABLE_DE_SA_DECLARATION = 'users_expert_comptable_de_sa_declaration';

    public const DECLARATION_SANS_COMPTE_OUVERT = 'liens_cabinet_declaration_sans_compte_ouvert';

    /**
     * Garde des magasins que le client ouvre à son expert-comptable (voir
     * poserLaFonctionDesMagasinsOuverts() et poserLesMagasinsOuverts()).
     */
    public const MAGASINS_OUVERTS = 'magasins_ouverts_a_l_expert_comptable';

    /**
     * Les tables où un compte d'un cabinet ou un expert-comptable de la
     * société agit dans un magasin, et les colonnes qui nomment ce compte ;
     * telechargements_documents nomme ses magasins dans sa colonne JSON
     * magasins. Le grand livre du stock nomme qui a fait chaque mouvement
     * (voir StockLedgerService) : la validation d'une facture d'achat, qui
     * n'a pas de colonne de son validateur, et l'émission d'un avoir
     * fournisseur y écrivent leur auteur. Une fiche de fournisseur nomme
     * qui l'a créée et qui l'a modifiée, archivée ou restaurée en dernier
     * (voir CreerUneFicheAction, ModifierUneFicheAction et
     * ArchiverUneFicheAction). Un produit ne naît que d'une facture
     * d'achat ou d'un inventaire d'ouverture, gardés ici, et un compte d'un
     * cabinet ne le modifie jamais (voir PermissionRegistry::duCabinet()).
     */
    public const AUTEURS_DANS_UN_MAGASIN = [
        'factures_achat' => ['cree_par'],
        'avoirs_fournisseur' => ['cree_par'],
        'fournisseurs' => ['cree_par', 'modifie_par'],
        'inventaires_ouverture' => ['cree_par', 'valide_par'],
        'pieces_fournisseur' => ['ajoutee_par'],
        'mouvements_stock' => ['cree_par'],
        'telechargements_documents' => ['demande_par'],
    ];

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
        self::LIENS => ['liens_cabinet', 'societe_id, cabinet_id, origine, etat, jeton_hache, siren_cabinet'],
        self::AUTORISATIONS => ['autorisations_cabinet', 'societe_id, lien_id, autorisation'],
        self::CLIENTS_CONFIES => ['clients_confies', null],
        self::MAGASINS => ['magasins_cabinet', null],
        self::COMPTES_EXPERT_COMPTABLE => ['comptes_expert_comptable', 'lien_id, user_id, jeton_hache, jeton_expire_le, mot_de_passe_choisi_le'],
        self::EXPERT_COMPTABLE_DE_SA_DECLARATION => ['users', 'role_societe, statut, email'],
        self::DECLARATION_SANS_COMPTE_OUVERT => ['liens_cabinet', 'etat'],
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

        $connexion->unprepared("CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '.self::verrou($garde)." {$controles} RETURN NEW; END \$\$");
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
     * Les autorisations d'un lien vont par paires (voir
     * PermissionRegistry::PAIRES_DU_CABINET) : « voir celles de toute
     * l'équipe » des factures ou des clôtures est accordée si et seulement
     * si « voir » l'est. Contrôlée à la validation de chaque transaction qui
     * écrit ou efface une autorisation (déclencheur de contrainte différé),
     * le réglage effaçant puis réécrivant toutes celles du lien (voir
     * LienCabinet::accorder()) ; une paire incomplète est refusée. Posée
     * dans la migration de création de autorisations_cabinet, retirée dans
     * son down().
     */
    public static function poserLesPaires(): void
    {
        $paires = implode(' OR ', array_map(
            static fn (string $voir, string $toutes): string => "EXISTS (SELECT 1 FROM autorisations_cabinet WHERE lien_id = lien AND autorisation = '{$voir}') <> EXISTS (SELECT 1 FROM autorisations_cabinet WHERE lien_id = lien AND autorisation = '{$toutes}')",
            array_keys(PermissionRegistry::PAIRES_DU_CABINET),
            PermissionRegistry::PAIRES_DU_CABINET,
        ));
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_'.self::PAIRES.'() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ DECLARE lien bigint; BEGIN '
            ."IF TG_OP = 'DELETE' THEN lien := OLD.lien_id; ELSE lien := NEW.lien_id; END IF; "
            ."IF {$paires} THEN RAISE EXCEPTION '%', ".self::message(self::PAIRES, 'voir les factures ou les clôtures de caisse s\'accorde à un cabinet avec celles de toute l\'équipe, jamais l\'un sans l\'autre').'; END IF; RETURN NULL; END $$');
        $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::PAIRES.' AFTER INSERT OR UPDATE OR DELETE ON autorisations_cabinet DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION garde_'.self::PAIRES.'()');
    }

    /**
     * Un lien actif ouvre au cabinet tous les magasins réels du client,
     * présents et futurs, sans sélection, ou une sélection d'au moins un
     * magasin (voir magasins_cabinet ; décision de l'éditeur du
     * 11/11/2026) : contrôlé à la validation de chaque transaction qui
     * écrit le lien ou une ligne de sa sélection (déclencheurs de
     * contrainte différés), le réglage effaçant puis réécrivant la
     * sélection (voir LienCabinet::choisirLesMagasins()). Posée dans la
     * migration de création de magasins_cabinet, la dernière table qu'elle
     * lit, retirée dans son down().
     */
    public static function poserLaSelectionDesMagasins(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_'.self::SELECTION.'() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ DECLARE lien bigint; BEGIN '
            ."IF TG_TABLE_NAME = 'liens_cabinet' THEN lien := NEW.id; ELSIF TG_OP = 'DELETE' THEN lien := OLD.lien_id; ELSE lien := NEW.lien_id; END IF; "
            ."IF EXISTS (SELECT 1 FROM liens_cabinet l WHERE l.id = lien AND l.etat IN ('".EtatDuLien::Actif->value."', '".EtatDuLien::Declare->value."') AND l.tous_les_magasins = (SELECT count(*) > 0 FROM magasins_cabinet m WHERE m.lien_id = lien)) "
            ."THEN RAISE EXCEPTION '%', ".self::message(self::SELECTION, 'un lien actif ou une déclaration ouvre tous les magasins du client, ou une sélection d\'au moins un magasin').'; END IF; RETURN NULL; END $$');

        foreach (['liens' => 'INSERT OR UPDATE OF tous_les_magasins, etat ON liens_cabinet', 'magasins' => 'INSERT OR DELETE ON magasins_cabinet'] as $suffixe => $evenements) {
            $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::SELECTION."_{$suffixe} AFTER {$evenements} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION garde_".self::SELECTION.'()');
        }
    }

    public static function retirerLaSelectionDesMagasins(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::SELECTION.'_liens ON liens_cabinet');
        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::SELECTION.'_magasins ON magasins_cabinet');
        $connexion->unprepared('DROP FUNCTION IF EXISTS garde_'.self::SELECTION.'()');
    }

    /**
     * La fonction compte_ouvre_le_magasin(compte, magasin) dit si un compte
     * peut agir dans un magasin réel d'une société cliente (§ 5 ter de la
     * décision de l'éditeur du 01/11/2026, réponse du 11/11/2026) : un
     * compte d'un cabinet d'expertise comptable, seulement par le lien actif
     * de son cabinet avec la société du magasin, qui ouvre tous ses
     * magasins ou l'a choisi ; un expert-comptable de la société, seulement
     * par sa déclaration en attente, aux mêmes conditions ; tout autre
     * compte, toujours (ses propres droits le gardent). Faux pour un compte
     * ou un magasin inconnus seulement s'ils désignent un tel compte : nul
     * sinon, la clé étrangère refusant l'inconnu. Lue sans verrou, comme
     * l'accès des écrans : une requête déjà partie quand le client retire
     * un magasin s'achève, la suivante est refusée (« dès sa requête
     * suivante »). Posée dans la migration de création de
     * comptes_expert_comptable, la dernière table qu'elle lit, avec la
     * fonction de garde de poserLesMagasinsOuverts().
     */
    public static function poserLaFonctionDesMagasinsOuverts(): void
    {
        $ouvre = static fn (string $lien): string => "({$lien}.tous_les_magasins OR EXISTS (SELECT 1 FROM magasins_cabinet choisi WHERE choisi.lien_id = {$lien}.id AND choisi.magasin_id = m.id))";
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION compte_ouvre_le_magasin(compte bigint, magasin bigint) RETURNS boolean LANGUAGE sql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ SELECT CASE '
            ."WHEN u.role_societe = '".RoleSociete::ExpertComptable->value."' THEN EXISTS (SELECT 1 FROM comptes_expert_comptable c JOIN liens_cabinet d ON d.id = c.lien_id WHERE c.user_id = u.id AND d.etat = '".EtatDuLien::Declare->value."' AND d.societe_id = m.societe_id AND ".$ouvre('d').') '
            ."WHEN s.type_activite = '".TypeDActivite::Cabinet->value."' THEN EXISTS (SELECT 1 FROM liens_cabinet l WHERE l.cabinet_id = u.societe_id AND l.societe_id = m.societe_id AND l.etat = '".EtatDuLien::Actif->value."' AND ".$ouvre('l').') '
            .'ELSE TRUE END FROM users u JOIN societes s ON s.id = u.societe_id JOIN magasins m ON m.id = magasin WHERE u.id = compte $$');
        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_'.self::MAGASINS_OUVERTS.'() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ DECLARE colonne text; compte bigint; BEGIN '
            .'FOREACH colonne IN ARRAY TG_ARGV LOOP '
            ."IF TG_OP = 'UPDATE' AND (to_jsonb(OLD) ->> colonne) IS NOT DISTINCT FROM (to_jsonb(NEW) ->> colonne) THEN CONTINUE; END IF; "
            .'compte := (to_jsonb(NEW) ->> colonne)::bigint; '
            ."IF compte IS NOT NULL AND (CASE WHEN TG_TABLE_NAME = 'telechargements_documents' THEN EXISTS (SELECT 1 FROM jsonb_array_elements_text(to_jsonb(NEW) -> 'magasins') choisi WHERE compte_ouvre_le_magasin(compte, choisi::bigint) = FALSE) ELSE compte_ouvre_le_magasin(compte, (to_jsonb(NEW) ->> 'magasin_id')::bigint) = FALSE END) "
            ."THEN RAISE EXCEPTION '%', ".self::message(self::MAGASINS_OUVERTS, 'un compte d\'un cabinet d\'expertise comptable ou un expert-comptable de la société n\'agit que dans un magasin que le client lui ouvre').'; END IF; '
            .'END LOOP; RETURN NEW; END $$');
    }

    public static function retirerLaFonctionDesMagasinsOuverts(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP FUNCTION IF EXISTS garde_'.self::MAGASINS_OUVERTS.'()');
        $connexion->unprepared('DROP FUNCTION IF EXISTS compte_ouvre_le_magasin(bigint, bigint)');
    }

    /**
     * Pose sur une table de AUTEURS_DANS_UN_MAGASIN le déclencheur de la
     * garde des magasins ouverts : à l'insertion, et quand une colonne qui
     * nomme un compte change (seule la colonne changée est contrôlée : un
     * inventaire saisi par le cabinet se valide encore par le propriétaire
     * après que le client lui a retiré ce magasin). Dans la migration de
     * création de la table, retiré par retirerLesMagasinsOuverts() dans son
     * down().
     *
     * @param  key-of<self::AUTEURS_DANS_UN_MAGASIN>  $table
     */
    public static function poserLesMagasinsOuverts(string $table): void
    {
        $colonnes = self::AUTEURS_DANS_UN_MAGASIN[$table];

        DB::connection()->unprepared('CREATE TRIGGER '.self::MAGASINS_OUVERTS.' BEFORE INSERT OR UPDATE OF '.implode(', ', $colonnes)." ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_".self::MAGASINS_OUVERTS."('".implode("', '", $colonnes)."')");
    }

    /**
     * @param  key-of<self::AUTEURS_DANS_UN_MAGASIN>  $table
     */
    public static function retirerLesMagasinsOuverts(string $table): void
    {
        DB::connection()->unprepared('DROP TRIGGER IF EXISTS '.self::MAGASINS_OUVERTS." ON {$table}");
    }

    public static function retirerLesPaires(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::PAIRES.' ON autorisations_cabinet');
        $connexion->unprepared('DROP FUNCTION IF EXISTS garde_'.self::PAIRES.'()');
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
        $declare = "'".EtatDuLien::Declare->value."'";
        $enCours = "{$actif}, {$declare}";
        $termine = "'".EtatDuLien::Termine->value."'";
        $declaration = "'".OrigineDuLien::DeclarationDuClient->value."'";
        $expertComptable = "'".RoleSociete::ExpertComptable->value."'";
        $retire = "'".StatutCompte::Retire->value."'";

        return match ($garde) {
            self::CABINETS => [
                [self::typeDe('societe_id')." <> {$cabinet}", 'seul un cabinet d\'expertise comptable a une vérification de cabinet', self::TOUJOURS],
            ],
            self::INVITATIONS => [
                [self::typeDe('societe_id')." <> {$cabinet}", 'seul un cabinet d\'expertise comptable invite une entreprise', self::TOUJOURS],
                ["(NEW.etat = '".EtatInvitation::Ouverte->value."' AND NEW.close_le IS NOT NULL) OR (NEW.etat <> '".EtatInvitation::Ouverte->value."' AND NEW.close_le IS NULL)", 'une invitation close a toujours sa date de fin, une invitation ouverte n\'en a pas', self::TOUJOURS],
            ],
            self::LIENS => [
                ['NEW.cabinet_id IS NOT NULL AND '.self::typeDe('cabinet_id')." <> {$cabinet}", 'un lien ne se fait qu\'avec un cabinet d\'expertise comptable', self::TOUJOURS],
                [self::typeDe('societe_id')." = {$cabinet}", 'un cabinet d\'expertise comptable n\'est jamais le client d\'un cabinet', self::TOUJOURS],
                ["NEW.jeton_hache IS NOT NULL AND NEW.etat <> '".EtatDuLien::DemandeParLeCabinet->value."'", 'le jeton d\'un lien ne vaut que pour une demande de rattachement en cours', self::TOUJOURS],
                ["(NEW.origine = {$declaration}) <> (NEW.siren_cabinet IS NOT NULL)", 'seule une déclaration du client porte le SIREN de son cabinet', self::TOUJOURS],
                ["NEW.cabinet_id IS NULL AND NOT (NEW.origine = {$declaration} AND NEW.etat IN ({$declare}, {$termine}))", 'seule une déclaration du client, en attente ou terminée sans rattachement, n\'a pas de cabinet', self::TOUJOURS],
                ["NEW.etat = {$declare} AND NEW.cabinet_id IS NOT NULL", 'une déclaration en attente n\'a pas encore de cabinet', self::TOUJOURS],
                ["NEW.siren_cabinet IS NOT NULL AND NEW.siren_cabinet = COALESCE((SELECT siren FROM societes WHERE id = NEW.societe_id), '')", 'une société ne déclare pas son propre SIREN', self::INSERTION],
                ["NEW.origine = {$declaration} AND (SELECT validee_le FROM societes WHERE id = NEW.societe_id) IS NULL", 'seule une société validée déclare son expert-comptable', self::INSERTION],
                ["NEW.etat NOT IN ({$demandes}) AND NOT (NEW.etat = {$actif} AND NEW.origine = '".OrigineDuLien::Invitation->value."') AND NEW.etat <> {$declare}", 'un lien naît d\'une demande, d\'une déclaration du client, ou actif par une invitation acceptée', self::INSERTION],
                ["NEW.origine = {$declaration} AND NEW.etat <> {$declare}", 'une déclaration du client naît en attente de son cabinet', self::INSERTION],
                ['NEW.societe_id <> OLD.societe_id OR NEW.origine <> OLD.origine OR (NEW.siren_cabinet IS NULL) <> (OLD.siren_cabinet IS NULL) OR NEW.siren_cabinet <> OLD.siren_cabinet', 'le client, l\'origine et le SIREN déclaré d\'un lien ne changent jamais', self::MODIFICATION],
                ["(OLD.cabinet_id IS NOT NULL AND (NEW.cabinet_id IS NULL OR NEW.cabinet_id <> OLD.cabinet_id)) OR (OLD.cabinet_id IS NULL AND NEW.cabinet_id IS NOT NULL AND NOT (OLD.etat = {$declare} AND NEW.etat = {$actif}))", 'le cabinet d\'un lien ne change jamais ; celui d\'une déclaration ne se pose qu\'à son rattachement', self::MODIFICATION],
                ["OLD.etat = {$declare} AND NEW.etat = {$actif} AND COALESCE((SELECT siren FROM societes WHERE id = NEW.cabinet_id), '') <> NEW.siren_cabinet", 'une déclaration ne se rattache qu\'au cabinet de son SIREN', self::MODIFICATION],
                ["NEW.etat <> OLD.etat AND NOT ((OLD.etat IN ({$demandes}) AND NEW.etat IN ({$actif}, '".EtatDuLien::Refuse->value."', '".EtatDuLien::Retire->value."', '".EtatDuLien::Expire->value."')) OR (OLD.etat = {$actif} AND NEW.etat = {$termine}) OR (OLD.etat = {$declare} AND NEW.etat IN ({$actif}, {$termine})))", 'transition du lien non permise', self::MODIFICATION],
                ["NEW.etat = {$actif} AND NOT ".self::cabinetVerifie(), 'un lien ne naît actif qu\'avec un cabinet vérifié, actif et vérifié à l\'Ordre', self::INSERTION],
                ["NEW.etat = {$actif} AND OLD.etat <> {$actif} AND NOT ".self::cabinetVerifie(), 'un lien ne devient actif qu\'avec un cabinet vérifié, actif et vérifié à l\'Ordre', self::MODIFICATION],
            ],
            self::AUTORISATIONS => [
                ['NEW.autorisation NOT IN ('.self::liste(PermissionRegistry::duCabinet(TypeDActivite::Commerce)).')', 'une autorisation hors de celles d\'un cabinet lié ne s\'accorde jamais', self::TOUJOURS],
                ["COALESCE((SELECT famille FROM referentiel_autorisations_magasin WHERE cle = NEW.autorisation), '') = '".PermissionRegistry::FAMILLE_ARTICLES."' AND ".self::typeDe('societe_id')." NOT IN ({$avecDesArticles})", 'une autorisation des articles ne s\'accorde pas chez un client sans articles', self::TOUJOURS],
                ["{$etatDuLien} NOT IN ({$enCours})", 'une autorisation ne s\'accorde que sur un lien actif ou une déclaration en attente', self::TOUJOURS],
            ],
            self::CLIENTS_CONFIES => [
                ["COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), '') <> '".RoleSociete::Collaborateur->value."'", 'un client ne se confie qu\'à un collaborateur du cabinet', self::INSERTION],
                ["{$etatDuLien} <> {$actif}", 'seul un client lié se confie', self::INSERTION],
            ],
            self::MAGASINS => [
                ['COALESCE((SELECT est_demonstration FROM magasins WHERE id = NEW.magasin_id), TRUE)', 'le magasin de démonstration ne s\'ouvre jamais à un cabinet ni à un expert-comptable de la société', self::INSERTION],
                ["{$etatDuLien} NOT IN ({$enCours})", 'une sélection de magasins ne vaut que sur un lien actif ou une déclaration en attente', self::INSERTION],
                ['COALESCE((SELECT tous_les_magasins FROM liens_cabinet WHERE id = NEW.lien_id), TRUE)', 'un lien qui ouvre tous les magasins n\'a pas de sélection', self::INSERTION],
            ],
            self::COMPTES_EXPERT_COMPTABLE => [
                ["COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), '') <> {$expertComptable}", 'seul un compte d\'expert-comptable de la société s\'attache à une déclaration', self::TOUJOURS],
                ["{$etatDuLien} <> {$declare}", 'un compte d\'expert-comptable ne s\'attache qu\'à une déclaration en attente', self::INSERTION],
                ['NEW.lien_id <> OLD.lien_id OR NEW.user_id <> OLD.user_id', 'un compte d\'expert-comptable ne change jamais de déclaration', self::MODIFICATION],
                ['(NEW.jeton_hache IS NULL) <> (NEW.jeton_expire_le IS NULL)', 'un lien du mot de passe a toujours son échéance', self::TOUJOURS],
                ["NEW.jeton_hache IS NOT NULL AND {$etatDuLien} <> {$declare}", 'le lien du mot de passe ne vaut que tant que la déclaration est en attente', self::TOUJOURS],
                ['OLD.mot_de_passe_choisi_le IS NOT NULL AND (NEW.mot_de_passe_choisi_le IS NULL OR NEW.mot_de_passe_choisi_le <> OLD.mot_de_passe_choisi_le)', 'la date du premier mot de passe choisi ne change plus', self::MODIFICATION],
            ],
            self::EXPERT_COMPTABLE_DE_SA_DECLARATION => [
                ["NEW.role_societe = {$expertComptable} AND NEW.statut <> {$retire} AND COALESCE((SELECT liens_cabinet.etat FROM comptes_expert_comptable INNER JOIN liens_cabinet ON liens_cabinet.id = comptes_expert_comptable.lien_id WHERE comptes_expert_comptable.user_id = NEW.id), {$declare}) <> {$declare}", 'un compte d\'expert-comptable de la société n\'est ouvert que tant que sa déclaration est en attente', self::TOUJOURS],
                ["NEW.role_societe = {$expertComptable} AND NEW.statut <> {$retire} AND NEW.email IS NULL", 'un expert-comptable de la société a une adresse tant que son compte n\'est pas retiré, où partent les liens de son mot de passe', self::TOUJOURS],
                ["(OLD.role_societe = {$expertComptable}) <> (NEW.role_societe = {$expertComptable})", 'le rôle d\'expert-comptable de la société ne se prend ni ne se quitte', self::MODIFICATION],
            ],
            self::DECLARATION_SANS_COMPTE_OUVERT => [
                ["NEW.etat <> {$declare} AND EXISTS (SELECT 1 FROM comptes_expert_comptable INNER JOIN users ON users.id = comptes_expert_comptable.user_id WHERE comptes_expert_comptable.lien_id = NEW.id AND users.statut <> {$retire})", 'les comptes de l\'expert-comptable de la société se retirent avant que sa déclaration prenne fin ou se rattache', self::TOUJOURS],
            ],
        };
    }

    /**
     * Ce que la fonction d'une garde verrouille avant de lire : pour un
     * expert-comptable de la société ouvert, sa déclaration, en partage
     * (voir le docblock de la classe) ; rien pour les autres gardes, qui
     * lisent des lignes que l'action qui les écrit tient déjà, ou des
     * valeurs qui ne changent jamais.
     *
     * @param  key-of<self::TABLES>  $garde
     * @return literal-string
     */
    private static function verrou(string $garde): string
    {
        return match ($garde) {
            self::EXPERT_COMPTABLE_DE_SA_DECLARATION => "IF NEW.role_societe = '".RoleSociete::ExpertComptable->value."' AND NEW.statut <> '".StatutCompte::Retire->value."' THEN PERFORM 1 FROM liens_cabinet WHERE id = (SELECT lien_id FROM comptes_expert_comptable WHERE user_id = NEW.id) FOR SHARE; END IF;",
            default => '',
        };
    }

    /**
     * Le cabinet du lien écrit est-il vérifié : validé, actif (ni suspendu,
     * ni résilié, ni révoqué), son activité contrôlée et son inscription à
     * l'Ordre vérifiée sans radiation constatée, comme le dit
     * Societe::estUnCabinetVerifie() (le type étant contrôlé par un autre
     * refus) ? Un lien ne devient jamais actif sans lui : un cabinet
     * suspendu bloque ses invitations et ses demandes de lien, que ses
     * clients peuvent seulement refuser (décision de l'éditeur du
     * 11/11/2026).
     *
     * @return literal-string
     */
    private static function cabinetVerifie(): string
    {
        return "EXISTS (SELECT 1 FROM societes INNER JOIN cabinets ON cabinets.societe_id = societes.id WHERE societes.id = NEW.cabinet_id AND societes.validee_le IS NOT NULL AND societes.statut = '".StatutSociete::Active->value."' AND cabinets.activite_controlee_le IS NOT NULL AND cabinets.ordre_verifie_le IS NOT NULL AND cabinets.ordre_retire_le IS NULL)";
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
