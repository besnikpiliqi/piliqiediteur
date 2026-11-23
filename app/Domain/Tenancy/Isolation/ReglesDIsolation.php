<?php

namespace App\Domain\Tenancy\Isolation;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TeteJournalAudit;
use App\Integrations\Pdp\Models\EvenementTransmissionPdp;
use App\Models\User;

/**
 * Les règles d'isolation de chaque table de la base, en une liste fermée (voir
 * VerificationDeLIsolation) : chaque table du schéma y a sa portée, et une
 * table nouvelle sans règle est un problème, jusqu'à ce que sa règle soit
 * écrite ici. Portées :
 * - « magasin » : une table des données d'un magasin (magasin_id jamais nul),
 *   dont le modèle utilise BelongsToMagasin et MagasinScope ;
 * - « société » : une table des données d'une société (societe_id jamais nul,
 *   magasin_id facultatif quand la ligne peut viser un magasin de la
 *   société), dont le modèle utilise BelongsToSociete et SocieteScope ;
 * - « dérivée » : une table sans colonne de portée, qui suit la ligne de sa
 *   table parente (sa colonne parente, sa table parente) ;
 * - « globale » : un référentiel commun à tout PILIQI, ou une demande faite
 *   avant qu'aucune société n'existe ;
 * - « technique » : une table du cadre (file d'attente, cache, sessions),
 *   qui ne porte aucune donnée d'une société ;
 * - « racine » : la table des sociétés elle-même.
 */
final class ReglesDIsolation
{
    public const MAGASIN = 'magasin';

    public const SOCIETE = 'societe';

    public const DERIVEE = 'derivee';

    public const GLOBALE = 'globale';

    public const TECHNIQUE = 'technique';

    public const RACINE = 'racine';

    /**
     * Les règles de CLAUDE.md que les contrôles citent, en clair.
     */
    public const REGLE_SOCIETES = 'Produit : chaque société est strictement isolée des autres (BelongsToSociete + SocieteScope).';

    public const REGLE_MAGASINS = 'Produit : dans une société, chaque magasin est strictement isolé (BelongsToMagasin + MagasinScope).';

    public const REGLE_CLES_COMPOSEES = 'Protocole de rigueur, point 2 : toute référence d\'une table d\'un magasin vers une autre table d\'un magasin est une clé composée (magasin_id, …) vers une unicité (magasin_id, id).';

    public const REGLE_COMPTES = 'Comptes et équipe : un compte appartient pour toujours à une seule société ; un compte de responsable, de vendeur ou d\'observateur ne vaut que pour un magasin, qu\'il ne quitte jamais.';

    public const REGLE_VENTES = 'Comptes et équipe : chaque vente et chaque encaissement est au nom d\'un compte autorisé à vendre dans ce magasin, jamais le propriétaire, ni un compte d\'un cabinet, ni un expert-comptable de la société.';

    public const REGLE_CABINETS = 'Cabinets d\'expertise comptable : un compte d\'un cabinet n\'entre chez un client que par le lien de son cabinet, dans les magasins que le client lui ouvre.';

    public const REGLE_CHAINES = 'Caisse et clôtures, Journal d\'audit : chaque chaîne du registre, du journal des événements et du journal d\'audit ne contient que des maillons de son magasin ou de sa société.';

    public const REGLE_PROTECTIONS = 'Protocole de rigueur, point 2, et Outillage : la base porte ses gardes (ajout seul, documents émis, stock, registre, journaux, charges validées, affectations, autorisations d\'un cabinet et d\'un expert-comptable, ventes au nom d\'un compte autorisé), actives, chaque fonction avec son search_path fixé.';

    public const REGLE_TABLES = 'Protocole de rigueur, point 6 : chaque table a sa règle d\'isolation, vérifiée chaque nuit.';

    /**
     * Le réglage que chaque fonction de garde fixe (voir
     * DeclencheursDeProtection::CHEMIN_DE_RECHERCHE).
     */
    public const CHEMIN_DE_RECHERCHE = 'search_path=pg_catalog, public, pg_temp';

    /**
     * La portée de chaque table du schéma.
     *
     * @var array<string, string>
     */
    public const PORTEES = [
        'actions_traitees' => self::MAGASIN,
        'actions_traitees_de_la_societe' => self::SOCIETE,
        'affectations' => self::SOCIETE,
        'autorisations_affectations' => self::DERIVEE,
        'autorisations_cabinet' => self::SOCIETE,
        'autorisations_societe' => self::SOCIETE,
        'avoir_allocations' => self::MAGASIN,
        'avoirs' => self::MAGASIN,
        'avoirs_fournisseur' => self::MAGASIN,
        'bordereaux_detaxe' => self::MAGASIN,
        'cabinets' => self::SOCIETE,
        'cache' => self::TECHNIQUE,
        'cache_locks' => self::TECHNIQUE,
        'changements_fin_exercice' => self::SOCIETE,
        'charges' => self::SOCIETE,
        'clients' => self::MAGASIN,
        'clients_confies' => self::SOCIETE,
        'clotures_caisse' => self::MAGASIN,
        'clotures_caisse_personnelles' => self::MAGASIN,
        'clotures_caisse_personnelles_factures' => self::MAGASIN,
        'clotures_caisse_personnelles_paiements' => self::MAGASIN,
        'codes_confirmation' => self::SOCIETE,
        'comptes_expert_comptable' => self::SOCIETE,
        'consultations_vies' => self::MAGASIN,
        'demandes_inscription' => self::GLOBALE,
        'demonstrations' => self::SOCIETE,
        'devis' => self::MAGASIN,
        'ecarts_sirene' => self::SOCIETE,
        'enregistrements_fiscaux' => self::MAGASIN,
        'envois_documents' => self::MAGASIN,
        'evenements_transmission_pdp' => self::DERIVEE,
        'factures' => self::MAGASIN,
        'factures_achat' => self::MAGASIN,
        'failed_jobs' => self::TECHNIQUE,
        'fournisseurs' => self::MAGASIN,
        'identifiants' => self::SOCIETE,
        'inventaires_ouverture' => self::MAGASIN,
        'invitations_cabinet' => self::SOCIETE,
        'job_batches' => self::TECHNIQUE,
        'jobs' => self::TECHNIQUE,
        'journal_audit' => self::SOCIETE,
        'liens_cabinet' => self::SOCIETE,
        'liens_des_devis' => self::MAGASIN,
        'lignes_achat' => self::MAGASIN,
        'lignes_avoir' => self::MAGASIN,
        'lignes_avoir_fournisseur' => self::MAGASIN,
        'lignes_de_charge' => self::SOCIETE,
        'lignes_devis' => self::MAGASIN,
        'lignes_facture' => self::MAGASIN,
        'lignes_inventaire_ouverture' => self::MAGASIN,
        'lignes_recu_paiement' => self::MAGASIN,
        'magasins' => self::SOCIETE,
        'magasins_cabinet' => self::SOCIETE,
        'mandats_pdp' => self::MAGASIN,
        'messages_vers_la_demonstration' => self::SOCIETE,
        'migrations' => self::TECHNIQUE,
        'mouvements_stock' => self::MAGASIN,
        'notifications' => self::DERIVEE,
        'paiement_allocations' => self::MAGASIN,
        'paiements' => self::MAGASIN,
        'paniers_caisse' => self::MAGASIN,
        'password_reset_tokens' => self::TECHNIQUE,
        'periodes_activite' => self::MAGASIN,
        'pieces_de_charge' => self::SOCIETE,
        'pieces_fournisseur' => self::MAGASIN,
        'pieces_verification' => self::SOCIETE,
        'postes_reconnus' => self::MAGASIN,
        'preferences_tableau_de_bord' => self::SOCIETE,
        'preuves_operation' => self::MAGASIN,
        'produits' => self::MAGASIN,
        'recus_paiement' => self::MAGASIN,
        'referentiel_autorisations_magasin' => self::GLOBALE,
        'referentiel_autorisations_societe' => self::GLOBALE,
        'reponses_des_devis' => self::MAGASIN,
        'sequences_documents' => self::MAGASIN,
        'services' => self::MAGASIN,
        'sessions' => self::TECHNIQUE,
        'site_de_la_base' => self::GLOBALE,
        'sirets_magasins' => self::SOCIETE,
        'societes' => self::RACINE,
        'taux_avoirs_fournisseur' => self::MAGASIN,
        'taux_factures_achat' => self::MAGASIN,
        'telechargements_documents' => self::SOCIETE,
        'tetes_journal_audit' => self::SOCIETE,
        'tetes_registre_fiscal' => self::MAGASIN,
        'traces_audit' => self::SOCIETE,
        'traces_evenements' => self::MAGASIN,
        'transmissions_pdp' => self::MAGASIN,
        'transmissions_propriete' => self::SOCIETE,
        'types_de_charge_de_la_societe' => self::SOCIETE,
        'users' => self::SOCIETE,
        'verifications_societe' => self::SOCIETE,
        'visas_detaxe' => self::MAGASIN,
    ];

    /**
     * La ligne parente de chaque table dérivée : sa colonne, la table qu'elle
     * vise.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PARENTS = [
        'autorisations_affectations' => ['affectation_id', 'affectations'],
        'evenements_transmission_pdp' => ['transmission_pdp_id', 'transmissions_pdp'],
        'notifications' => ['notifiable_id', 'users'],
    ];

    /**
     * Les colonnes qui visent une autre société que celle de leur ligne, et
     * ce qu'elles visent : le cabinet d'un lien, la société du cabinet qui
     * invite ou à qui un client est confié. Toute autre colonne qui vise la
     * table des sociétés est la société de sa ligne.
     *
     * @var array<string, string>
     */
    public const SOCIETES_VISEES = [
        'liens_cabinet.cabinet_id' => 'le cabinet d\'expertise comptable du lien',
    ];

    /**
     * Les clés qui relient une ligne d'une société à une ligne d'une autre
     * société, par une colonne de la table visée qui n'est pas sa société
     * (clé : la table et les colonnes de la clé étrangère) : les clients
     * confiés d'un cabinet visent le lien de son client par (cabinet_id, id).
     *
     * @var array<string, string>
     */
    public const SOCIETE_PAR_UNE_AUTRE_COLONNE = [
        'clients_confies.societe_id,lien_id' => 'cabinet_id',
    ];

    /**
     * Les modèles d'une table d'un magasin ou d'une société qui, volontairement,
     * n'ont pas le scope de leur portée, avec la raison écrite dans leur
     * docblock ; toute requête qui les lit filtre explicitement.
     *
     * @var array<class-string, string>
     */
    public const MODELES_SANS_SCOPE = [
        User::class => 'le compte se lit à la connexion, avant qu\'aucune société ne soit ouverte, par son identifiant ou l\'adresse de connexion d\'un propriétaire',
        Magasin::class => 'le magasin est le contexte lui-même (TenantContext), lu par la société, la portée d\'un administrateur ou le lien d\'un cabinet',
        Societe::class => 'la société est la racine de l\'isolation',
        Affectation::class => 'les droits d\'un compte se lisent dans tous ses magasins, par le compte',
        SiretMagasin::class => 'la réservation d\'un SIRET se lit dans tout le logiciel, limitée à son existence',
        PeriodeActivite::class => 'chaque requête filtre explicitement par magasin',
        MandatPdp::class => 'donnée de configuration lue explicitement par magasin (tâches en file)',
        SequenceDocument::class => 'la numérotation verrouille explicitement la séquence de son magasin',
        TeteJournalAudit::class => 'la tête d\'une chaîne se lit toujours par sa société, explicitement',
        DemandeInscription::class => 'une demande d\'inscription précède la société',
        EvenementTransmissionPdp::class => 'un événement suit sa transmission, de son magasin',
    ];

    /**
     * Les déclencheurs que la base doit porter, par table, tels que les
     * migrations les posent : un déclencheur absent, désactivé, ou qui n'est
     * pas dans cette liste est un problème.
     *
     * @var array<string, list<string>>
     */
    public const DECLENCHEURS = [
        'affectations' => ['affectations_coherentes_avec_le_role'],
        'autorisations_affectations' => ['autorisations_affectations_sans_responsable', 'autorisations_d_articles_seulement_avec_des_articles'],
        'autorisations_cabinet' => ['autorisations_cabinet_par_paires', 'autorisations_cabinet_permises'],
        'autorisations_societe' => ['autorisations_societe_administrateur_seul'],
        'avoir_allocations' => ['avoir_allocations_ajout_seul_delete', 'avoir_allocations_ajout_seul_truncate', 'avoir_allocations_ajout_seul_update'],
        'avoirs' => ['avoirs_cloture_caisse_id_figee', 'garde_documents_emis_avoirs'],
        'avoirs_fournisseur' => ['avoirs_fournisseur_seulement_avec_des_articles', 'magasins_ouverts_a_l_expert_comptable'],
        'bordereaux_detaxe' => ['bordereaux_detaxe_ajout_seul_delete', 'bordereaux_detaxe_ajout_seul_truncate', 'bordereaux_detaxe_ajout_seul_update'],
        'cabinets' => ['cabinets_de_type_cabinet', 'cabinets_sans_suppression_delete', 'cabinets_sans_suppression_truncate'],
        'changements_fin_exercice' => ['changements_fin_exercice_ajout_seul_delete', 'changements_fin_exercice_ajout_seul_truncate', 'changements_fin_exercice_ajout_seul_update'],
        'charges' => ['charges_coherente', 'charges_d_une_societe_a_magasins', 'charges_validee_figee', 'charges_validee_jamais_effacee'],
        'clients_confies' => ['clients_confies_a_un_collaborateur'],
        'clotures_caisse' => ['clotures_caisse_ajout_seul_delete', 'clotures_caisse_ajout_seul_truncate', 'clotures_caisse_ajout_seul_update', 'clotures_caisse_signee'],
        'clotures_caisse_personnelles' => ['clotures_caisse_personnelles_ajout_seul_delete', 'clotures_caisse_personnelles_ajout_seul_truncate', 'clotures_caisse_personnelles_ajout_seul_update'],
        'clotures_caisse_personnelles_factures' => ['clotures_caisse_personnelles_factures_ajout_seul_delete', 'clotures_caisse_personnelles_factures_ajout_seul_truncate', 'clotures_caisse_personnelles_factures_ajout_seul_update'],
        'clotures_caisse_personnelles_paiements' => ['clotures_caisse_personnelles_paiements_ajout_seul_delete', 'clotures_caisse_personnelles_paiements_ajout_seul_truncate', 'clotures_caisse_personnelles_paiements_ajout_seul_update'],
        'codes_confirmation' => ['codes_confirmation_suppression_apres_conservation_delete', 'codes_confirmation_suppression_apres_conservation_truncate'],
        'comptes_expert_comptable' => ['comptes_expert_comptable_coherents', 'comptes_expert_comptable_sans_suppression_delete', 'comptes_expert_comptable_sans_suppression_truncate'],
        'consultations_vies' => ['consultations_vies_ajout_seul_delete', 'consultations_vies_ajout_seul_truncate', 'consultations_vies_ajout_seul_update'],
        'enregistrements_fiscaux' => ['enregistrements_fiscaux_ajout_seul_delete', 'enregistrements_fiscaux_ajout_seul_truncate', 'enregistrements_fiscaux_ajout_seul_update', 'garde_vente_enregistrements_fiscaux'],
        'factures' => ['factures_cloture_caisse_id_figee', 'factures_vente_du_stock_seul_figee', 'garde_documents_emis_factures', 'garde_vente_factures'],
        'factures_achat' => ['factures_achat_repere_grand_livre_figee', 'factures_achat_seulement_avec_des_articles', 'magasins_ouverts_a_l_expert_comptable'],
        'fournisseurs' => ['magasins_ouverts_a_l_expert_comptable'],
        'identifiants' => ['garde_identifiants', 'garde_identifiants_vidage'],
        'inventaires_ouverture' => ['inventaires_ouverture_seulement_avec_des_articles', 'magasins_ouverts_a_l_expert_comptable'],
        'invitations_cabinet' => ['invitations_d_un_cabinet'],
        'journal_audit' => ['journal_audit_effacement_apres_expiration_delete', 'journal_audit_effacement_apres_expiration_truncate', 'journal_audit_effacement_apres_expiration_update'],
        'liens_cabinet' => ['liens_cabinet_declaration_sans_compte_ouvert', 'liens_cabinet_sans_suppression_delete', 'liens_cabinet_sans_suppression_truncate', 'liens_cabinet_tous_les_magasins_ou_une_selection_liens', 'liens_entre_un_cabinet_et_un_client'],
        'lignes_achat' => ['lignes_achat_stock_coherent'],
        'lignes_avoir' => ['garde_documents_emis_lignes_avoir'],
        'lignes_de_charge' => ['charges_coherente_lignes', 'lignes_de_charge_d_une_charge_validee'],
        'lignes_facture' => ['garde_documents_emis_lignes_facture'],
        'lignes_recu_paiement' => ['lignes_recu_paiement_ajout_seul_delete', 'lignes_recu_paiement_ajout_seul_truncate', 'lignes_recu_paiement_ajout_seul_update'],
        'magasins' => ['magasins_jamais_dans_un_cabinet', 'magasins_sans_suppression_delete', 'magasins_sans_suppression_truncate', 'magasins_siret_libere_le_figee', 'magasins_territoire_figee'],
        'magasins_cabinet' => ['liens_cabinet_tous_les_magasins_ou_une_selection_magasins', 'magasins_cabinet_d_un_lien_actif'],
        'mouvements_stock' => ['magasins_ouverts_a_l_expert_comptable', 'mouvements_stock_achat_coherent', 'mouvements_stock_ajout_seul_delete', 'mouvements_stock_ajout_seul_truncate', 'mouvements_stock_ajout_seul_update', 'mouvements_stock_auteur_du_document', 'mouvements_stock_compte', 'mouvements_stock_justifies'],
        'paiement_allocations' => ['paiement_allocations_ajout_seul_delete', 'paiement_allocations_ajout_seul_truncate', 'paiement_allocations_ajout_seul_update'],
        'paiements' => ['garde_vente_paiements', 'paiements_ajout_seul_delete', 'paiements_ajout_seul_truncate', 'paiements_ajout_seul_update'],
        'periodes_activite' => ['periodes_activite_sans_suppression_delete', 'periodes_activite_sans_suppression_truncate'],
        'pieces_de_charge' => ['charges_coherente_pieces', 'pieces_de_charge_effacees_avant_la_validation', 'pieces_de_charge_jamais_modifiees'],
        'pieces_fournisseur' => ['magasins_ouverts_a_l_expert_comptable', 'pieces_fournisseur_effacees_d_un_brouillon_seulement', 'pieces_fournisseur_jamais_modifiees', 'pieces_fournisseur_un_seul_document'],
        'pieces_verification' => ['pieces_verification_sans_suppression_delete', 'pieces_verification_sans_suppression_truncate'],
        'preuves_operation' => ['preuves_operation_ajout_seul_delete', 'preuves_operation_ajout_seul_truncate', 'preuves_operation_ajout_seul_update'],
        'produits' => ['produits_nes_sans_stock', 'produits_seulement_avec_des_articles', 'produits_stock_par_le_grand_livre'],
        'recus_paiement' => ['recus_paiement_ajout_seul_delete', 'recus_paiement_ajout_seul_truncate', 'recus_paiement_ajout_seul_update'],
        'reponses_des_devis' => ['reponses_des_devis_ajout_seul_delete', 'reponses_des_devis_ajout_seul_truncate', 'reponses_des_devis_ajout_seul_update'],
        'sirets_magasins' => ['sirets_magasins_sans_suppression_delete', 'sirets_magasins_sans_suppression_truncate'],
        'site_de_la_base' => ['garde_site_de_la_base', 'garde_site_de_la_base_vidage'],
        'societes' => ['societes_sans_suppression_delete', 'societes_sans_suppression_truncate', 'societes_type_d_activite_connu_et_fixe', 'societes_verification_conservee_jusqu_au_figee'],
        'telechargements_documents' => ['magasins_ouverts_a_l_expert_comptable'],
        'tetes_journal_audit' => ['tetes_journal_audit_sans_suppression_delete', 'tetes_journal_audit_sans_suppression_truncate'],
        'traces_audit' => ['traces_audit_effacement_apres_expiration_delete', 'traces_audit_effacement_apres_expiration_truncate', 'traces_audit_effacement_apres_expiration_update'],
        'traces_evenements' => ['traces_evenements_effacement_apres_expiration_delete', 'traces_evenements_effacement_apres_expiration_truncate', 'traces_evenements_effacement_apres_expiration_update'],
        'users' => ['users_expert_comptable_de_sa_declaration', 'users_role_coherent_avec_les_droits'],
        'verifications_societe' => ['verifications_societe_effacement_apres_expiration_delete', 'verifications_societe_effacement_apres_expiration_truncate', 'verifications_societe_effacement_apres_expiration_update'],
        'visas_detaxe' => ['visas_detaxe_ajout_seul_delete', 'visas_detaxe_ajout_seul_truncate', 'visas_detaxe_ajout_seul_update'],
    ];

    /**
     * Les tables qui n'existent que dans les bases du site de démonstration
     * (database/migrations-demonstration, lot DEMO-COPIE, C.8 et C.9),
     * jamais dans la base réelle : connues de la vérification de
     * l'isolation sur ce site seulement, comme des tables de la
     * démonstration, et non comme un oubli.
     */
    public const TABLES_DE_LA_DEMONSTRATION = [
        'comptes_du_bandeau' => self::SOCIETE,
        'courriels_de_la_demonstration' => self::SOCIETE,
    ];

    /**
     * La portée de cette table, ou null quand elle n'a pas de règle.
     */
    public static function porteeDe(string $table): ?string
    {
        return self::portees()[$table] ?? null;
    }

    /**
     * La portée de cette table sur l'un ou l'autre site, ou null quand elle
     * n'a pas de règle : le code des deux sites est le même, et un modèle
     * d'une table du seul site de démonstration (voir
     * TABLES_DE_LA_DEMONSTRATION) porte aussi le scope de sa portée.
     */
    public static function porteeConnue(string $table): ?string
    {
        return self::PORTEES[$table] ?? self::TABLES_DE_LA_DEMONSTRATION[$table] ?? null;
    }

    /**
     * Les règles des tables de la base servie : sur le site de
     * démonstration, aussi celles de ses tables à lui.
     *
     * @return array<string, string>
     */
    public static function portees(): array
    {
        return SiteDePiliqi::estLaDemonstration() ? [...self::PORTEES, ...self::TABLES_DE_LA_DEMONSTRATION] : self::PORTEES;
    }

    /**
     * Les tables des données d'un magasin, par nom.
     *
     * @return list<string>
     */
    public static function tablesDesMagasins(): array
    {
        return array_keys(array_filter(self::PORTEES, static fn (string $portee): bool => $portee === self::MAGASIN));
    }
}
