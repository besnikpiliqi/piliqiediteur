<?php

/*
 * La matrice décision → test (lot de décisions 21 étendu, fin de l'étape 1
 * de la feuille de route de CLAUDE.md).
 *
 * « regles » : chaque règle que CLAUDE.md écrit, sous la partie qui la
 * porte (« puce » : le titre de la puce des décisions de l'éditeur, ou
 * « Protocole de rigueur », « Développement », « Produit »), ou que seul
 * le code applique (« Hors de CLAUDE.md » : décisions de la conversation
 * que l'éditeur n'a pas encore fait écrire dans CLAUDE.md), avec les tests
 * qui la prouvent, chacun nommé « chemin::méthode » pour un test PHPUnit,
 * « chemin › nom » pour un test Pest.
 *
 * « decisions » : chaque décision de l'éditeur, par le fichier de notes qui
 * la porte (page d'arbitrages du 09/10/2026 ; séries 4, 5, 6 et 8 ;
 * décisions du 19/10/2026 sur la vérification par courrier et l'espace des
 * cabinets ; réponses du 24/10/2026 sur la relecture du lot V2, les
 * cabinets et les types d'activité ; réponses du 25/10/2026 des lots T2,
 * T3 et PU ; décision du 01/11/2026 sur l'expert-comptable de la société ;
 * réponses du 26/10/2026 sur deux règles d'écran et du 01/11/2026 sur le
 * client comptoir ; directives et décisions de la conversation réunies
 * dans historique/DIRECTIVES.md ; série 9), avec les règles qui la font
 * vivre (« regles »), ou la raison pour laquelle aucun test ne la porte :
 * « hors_logiciel » (une façon de travailler, que suivent le protocole de
 * rigueur, la CI et les relectures), « remplacee » (par la décision qui la
 * remplace), « a_venir » (une étape de la feuille de route qui n'est pas
 * faite), « explication » (une réponse donnée à l'éditeur, qui ne décide
 * rien). Une décision remplacée en partie porte « remplacee » et ses
 * « regles », celles de la part qui tient. Une décision qui tient, ou la
 * part d'une décision qui tient, que le code contredit porte
 * « a_trancher » : la question proposée à l'éditeur et ce que le code fait
 * en attendant sa réponse.
 *
 * MatriceDesDecisionsTest échoue si une partie de CLAUDE.md n'a aucune
 * règle, si une règle ne nomme aucun test ou nomme un test qui n'existe
 * pas, ou si une décision ne renvoie à aucune règle connue sans dire
 * pourquoi. Chaque lot qui ajoute ou change une décision tient cette
 * matrice à jour dans le même commit.
 */

return [
    'regles' => [
        // Protocole de rigueur.
        'protocole.modeles-isoles' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'Tout modèle de données d\'un magasin utilise BelongsToMagasin et tout modèle propre à une société BelongsToSociete ; sans contexte, la lecture échoue fermée.',
            'tests' => [
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_chaque_modele_de_magasin_est_liste_ici',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_chaque_modele_de_societe_est_liste_ici',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_chaque_modele_d_un_cabinet_ou_d_un_lien_est_isole_par_sa_societe',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_magasin_id_est_toujours_force_par_le_contexte_actif',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_acces_sans_contexte_tenant_echoue_fermee',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_societe_scope_echoue_ferme_sans_contexte',
            ],
        ],
        'protocole.lectures-hors-du-scope-filtrees' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'Toute requête qui contourne MagasinScope ou SocieteScope (retrait du scope, job, tâche planifiée, route hors panneau) filtre explicitement par magasin ou par société.',
            'tests' => [
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_retirer_explicitement_magasin_scope_lit_tous_les_magasins_sans_contexte',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_les_lectures_hors_du_client_courant_filtrent_par_cabinet_et_ne_lisent_que_leurs_colonnes',
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_le_job_envoie_dans_le_magasin_de_l_envoi_quel_que_soit_le_contexte',
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_un_job_qui_designe_l_envoi_d_un_autre_magasin_ne_l_envoie_pas',
                'tests/Feature/Calendrier/IsolationDesCalendriersTest.php::test_un_passage_horaire_ne_clot_que_les_magasins_dont_le_minuit_est_passe',
                'tests/Feature/Calendrier/IsolationDesCalendriersTest.php::test_le_calendrier_d_un_document_est_celui_de_son_magasin_et_jamais_celui_du_contexte',
            ],
        ],
        'protocole.unicites-limitees' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'Toute règle unique ou exists est limitée au magasin ou à la société, sauf les unicités voulues dans tout le logiciel (SIREN, SIRET réservé, identifiant de connexion, adresse de connexion d\'un propriétaire), qu\'aucun écran ni aucun message ne révèle à un tiers.',
            'tests' => [
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_les_jetons_sont_isoles_par_magasin',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_un_identifiant_de_magasin_d_une_autre_societe_force_dans_un_formulaire_est_refuse',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_garde_du_siren_ne_lit_d_une_autre_societe_qu_un_booleen',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_un_siren_deja_pris_est_refuse_par_le_seul_message_qui_ne_revele_rien',
                'tests/Feature/Societe/IdentifiantsTest.php::test_deux_comptes_n_ont_jamais_le_meme_identifiant_meme_dans_deux_societes',
                'tests/Feature/Societe/IdentifiantsTest.php::test_un_jeton_de_creation_rejoue_par_un_autre_auteur_ne_revele_rien',
            ],
        ],
        'protocole.cles-composees' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'En base, toute référence d\'une table d\'un magasin vers une autre table d\'un magasin est une clé composée (magasin_id, …) vers une unicité (magasin_id, id).',
            'tests' => [
                'tests/Feature/Tenancy/ReferencesEntreMagasinsEnBaseTest.php::test_toute_reference_entre_deux_tables_d_un_magasin_est_une_cle_composee',
                'tests/Feature/Tenancy/ReferencesEntreMagasinsEnBaseTest.php::test_la_base_refuse_qu_une_ligne_designe_une_ligne_d_un_autre_magasin',
            ],
        ],
        'protocole.fiche-relue-dans-le-magasin-actif' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'Une action qui reçoit une fiche déjà chargée (client, fournisseur, produit) la relit dans le magasin actif avant toute écriture.',
            'tests' => [
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_le_client_d_un_autre_magasin_deja_charge_est_refuse_sans_rien_ecrire',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_le_fournisseur_d_un_autre_magasin_deja_charge_est_refuse_sans_rien_ecrire',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_la_caisse_l_encaissement_et_l_avoir_refusent_le_client_d_un_autre_magasin',
                'tests/Feature/Facturation/LignesUniquesTest.php::test_un_produit_d_un_autre_magasin_n_est_jamais_compare_ni_vendu',
            ],
        ],
        'protocole.code-complet' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'Jamais de raccourci : aucun commentaire du code ne laisse une marque de travail à finir, une ligne de points de suspension ni un « reste inchangé » à la place du code.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_aucun_commentaire_du_code_ne_laisse_un_raccourci',
            ],
        ],
        'protocole.suite-complete-sans-test-saute' => [
            'puce' => 'Protocole de rigueur',
            'regle' => 'La suite complète passe sur PostgreSQL au code de sortie 0 ; aucun test ne se saute hors d\'une raison permise, et la CI échoue sur un test sauté.',
            'tests' => [
                'tests/Feature/Qualite/TestsSautesTest.php::test_un_test_ne_se_saute_que_pour_une_raison_permise',
                'tests/Feature/Qualite/TestsSautesTest.php::test_un_test_saute_a_revoir_fait_echouer_la_regle',
                'tests/Feature/Qualite/TestsSautesTest.php::test_le_resume_de_la_ci_compte_les_tests_et_echoue_sur_un_test_saute',
                'tests/Feature/OutillageTest.php::test_aucun_commentaire_des_tests_n_avale_une_fonction',
            ],
        ],

        // Développement : toujours repartir de zéro.
        'developpement.une-migration-de-creation-par-table' => [
            'puce' => 'Développement',
            'regle' => 'Chaque table est définie par une seule migration de création qui contient son schéma final ; aucune migration n\'ajoute, ne renomme ni ne retire une colonne d\'une autre table, et la base se recrée par migrate:fresh.',
            'tests' => [
                'tests/Unit/MigrationsDeCreationTest.php::test_chaque_table_est_definie_par_sa_seule_migration_de_creation',
                'tests/Unit/MigrationsDeCreationTest.php::test_la_regle_refuse_une_migration_qui_change_une_table_d_une_autre',
                'tests/Feature/RetourArriereDesMigrationsTest.php::test_toutes_les_migrations_se_defont_une_par_une_puis_se_rejouent',
            ],
        ],
        'developpement.aucune-donnee-reelle' => [
            'puce' => 'Développement',
            'regle' => 'Aucune donnée réelle n\'existe : les seules données semées sont les données de démonstration, toutes fictives (SIREN fictifs, adresses sur le domaine réservé exemple.test), qui ne se sèment qu\'en développement local et dans les tests, jamais en production (décision de l\'éditeur du 13/11/2026, lot SEED).',
            'tests' => [
                'tests/Feature/DatabaseSeederTest.php::test_le_semis_est_refuse_en_production_sans_rien_ecrire',
                'tests/Feature/DatabaseSeederTest.php::test_le_semis_remplit_la_base_par_les_vraies_actions_et_donne_la_liste_des_comptes',
            ],
        ],
        'developpement.commentaires-devenus-inutiles-supprimes' => [
            'puce' => 'Développement',
            'regle' => 'Les commentaires devenus inutiles sont supprimés : aucun texte du code, des migrations ni des vues ne garde une formule que la conception de la société a retirée (« Super Admin », « un seul magasin », « magasin principal », « écran de sélection », « seul le propriétaire coche »), même coupée en fin de ligne.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_aucun_texte_du_code_ne_garde_une_formule_retiree_meme_coupee_en_fin_de_ligne',
            ],
        ],

        // Produit.
        'produit.adresse-d-une-fiche-de-magasin-illisible' => [
            'puce' => 'Produit',
            'regle' => 'L\'adresse d\'une fiche de magasin de l\'espace de la société qui ne porte pas un identifiant lisible répond 404, jamais 500, sans interroger la base.',
            'tests' => [
                'tests/Feature/Societe/MagasinResourceTest.php::test_l_adresse_d_une_fiche_de_magasin_qui_ne_porte_pas_un_identifiant_repond_404',
            ],
        ],
        'produit.magasins-isoles' => [
            'puce' => 'Produit',
            'regle' => 'Dans une société, chaque magasin est strictement isolé : aucun client, produit, fournisseur, service, document, stock, caisse ni réglage local n\'est partagé ni mélangé entre magasins.',
            'tests' => [
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_ne_voit_pas_les_enregistrements_dun_autre',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_une_fiche_archivee_d_un_autre_magasin_reste_invisible',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_le_fournisseur_archive_d_un_autre_magasin_ne_se_lit_pas_par_la_relation',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_de_la_meme_societe_ne_fait_pas_d_avoir_sur_une_vente_d_un_autre',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_de_la_meme_societe_n_encaisse_pas_une_vente_d_un_autre',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_de_la_meme_societe_ne_justifie_pas_une_vente_d_un_autre',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_de_la_meme_societe_ne_corrige_pas_la_fiche_d_un_autre',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_l_url_d_un_enregistrement_d_un_autre_magasin_repond_404',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_les_clients_produits_documents_et_stock_d_un_magasin_restent_invisibles_dans_l_autre',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_l_url_d_un_enregistrement_d_un_autre_magasin_de_la_societe_repond_404_meme_pour_un_compte_autorise_dans_les_deux',
                'tests/Browser/IsolationTest.php › le vendeur d’un magasin qui tape l’adresse d’un autre magasin de sa société reçoit la page introuvable',
            ],
        ],
        'produit.societes-isolees' => [
            'puce' => 'Produit',
            'regle' => 'Chaque société est strictement isolée des autres : rien n\'est partagé ni copié d\'une société à une autre.',
            'tests' => [
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_une_societe_ne_lit_ni_le_journal_d_audit_ni_les_verifications_ni_les_demandes_d_une_autre',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_l_espace_d_une_autre_societe_repond_404',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_le_proprietaire_d_une_societe_n_ouvre_aucun_magasin_d_une_autre',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_base_refuse_de_rattacher_une_ligne_de_societe_au_magasin_d_une_autre_societe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_base_refuse_d_affecter_un_compte_au_magasin_d_une_autre_societe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_les_autorisations_de_societe_ne_valent_jamais_hors_de_sa_societe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_run_as_d_un_magasin_d_une_autre_societe_est_refuse_dans_un_contexte_de_societe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_le_type_d_une_societe_ne_change_rien_dans_une_autre',
                'tests/Browser/IsolationTest.php › le propriétaire d’une société qui tape l’adresse d’une facture ou de l’espace d’une autre société reçoit la page introuvable',
            ],
        ],
        'produit.lectures-hors-du-magasin-permises' => [
            'puce' => 'Produit',
            'regle' => 'Les seules lectures hors du magasin ou de la société courants sont celles que CLAUDE.md liste, explicites, filtrées, limitées aux champs nécessaires et testées.',
            'tests' => [
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_aucune_somme_d_une_autre_societe_n_entre_dans_les_factures_a_encaisser',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_aucune_caisse_d_une_autre_societe_n_entre_dans_les_caisses_de_la_societe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_lecture_de_la_premiere_vente_ne_compte_que_les_magasins_reels_de_la_societe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_connexion_ne_lit_d_un_identifiant_que_le_compte_qu_il_designe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_page_du_lien_de_transmission_ne_lit_que_la_demande_qu_il_designe',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_le_tableau_de_bord_de_la_societe_ne_rend_l_adresse_d_aucun_magasin_designe_par_le_navigateur',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_un_code_ne_valide_que_la_societe_de_son_courrier',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_un_code_de_procedure_ne_vaut_que_pour_la_societe_de_son_courrier',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_les_lectures_des_declarations_d_un_siren_ne_lisent_que_leur_identifiant_et_leur_societe',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_la_page_du_lien_ne_lit_que_le_compte_sa_societe_et_sa_declaration',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_la_connexion_et_le_nom_fige_d_un_expert_comptable_ne_lisent_que_la_declaration_de_sa_societe',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_les_liens_actifs_d_un_cabinet_ne_lisent_du_client_que_son_siren_et_sa_denomination',
                'tests/Feature/Calendrier/IsolationDesCalendriersTest.php::test_les_caisses_de_la_societe_ne_lisent_que_les_magasins_de_la_portee_quel_que_soit_leur_fuseau',
            ],
        ],
        'produit.cabinets-isoles' => [
            'puce' => 'Produit',
            'regle' => 'Les comptes d\'un cabinet appartiennent au cabinet et n\'entrent chez un client que par le lien de leur cabinet ; aucune donnée d\'un client n\'est visible d\'un autre client.',
            'tests' => [
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_aucune_donnee_d_un_client_n_est_visible_depuis_un_autre_client',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_le_proprietaire_du_cabinet_voit_tous_ses_clients_et_jamais_ceux_d_un_autre_cabinet',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_une_action_forgee_du_cabinet_sur_un_client_non_lie_est_refusee',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_une_requete_livewire_forgee_de_la_fiche_d_un_client_ne_rend_l_adresse_d_aucun_autre_magasin',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_un_compte_retire_d_un_cabinet_n_a_aucun_lien_ni_droit_meme_avec_un_client_encore_confie',
                'tests/Browser/IsolationTest.php › le collaborateur d’un cabinet qui tape l’adresse du magasin d’une société qui n’est pas son client reçoit la page introuvable',
            ],
        ],
        'produit.une-personne-deux-comptes' => [
            'puce' => 'Produit',
            'regle' => 'Une personne qui travaille dans deux magasins a deux comptes et ne voit dans chacun que ce qu\'elle y a fait.',
            'tests' => [
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_une_personne_de_deux_magasins_a_deux_comptes_et_ne_voit_dans_chacun_que_ce_qu_il_y_a_fait',
            ],
        ],
        'produit.inscriptions-du-meme-siren-isolees' => [
            'puce' => 'Produit',
            'regle' => 'Pour un même SIREN, une inscription close et la nouvelle ne voient rien l\'une de l\'autre ; seule l\'existence d\'une autre inscription se lit, sans son identité.',
            'tests' => [
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_aucun_reglage_d_une_inscription_close_du_meme_siren_n_est_lu_ni_revele',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_une_inscription_close_et_la_nouvelle_du_meme_siren_ne_voient_rien_l_une_de_l_autre',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_cloture_d_une_inscription_ne_retire_que_l_adresse_de_son_proprietaire',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_cloture_d_une_inscription_n_annule_que_les_transmissions_de_sa_societe',
            ],
        ],

        // Outillage.
        'outillage.postgresql-seul' => [
            'puce' => 'Outillage',
            'regle' => 'PostgreSQL est le seul moteur, en production, en développement et pour les tests, en UTF8 avec la collation déterministe C.UTF-8.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_postgresql_est_le_seul_moteur_de_la_base',
                'tests/Feature/OutillageTest.php::test_une_ancienne_base_sqlite_ne_s_ouvre_ni_pour_les_migrations_ni_pour_le_diagnostic_ni_pour_l_application',
                'tests/Feature/OutillageTest.php::test_la_base_trie_les_textes_dans_l_ordre_binaire',
            ],
        ],
        'outillage.aucun-paquet-inutilise' => [
            'puce' => 'Outillage',
            'regle' => 'Aucun paquet inutilisé : un paquet retiré n\'expose ni route ni commande, et rien de ce que lisent les agents ne renvoie à un outil absent.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_aucune_route_d_un_paquet_retire_n_est_exposee',
                'tests/Feature/OutillageTest.php::test_aucune_commande_d_un_paquet_retire_n_est_proposee',
                'tests/Feature/OutillageTest.php::test_les_consignes_des_agents_ne_renvoient_a_aucun_outil_absent',
                'tests/Feature/OutillageTest.php::test_aucun_skill_d_un_paquet_absent_n_est_propose_aux_agents',
            ],
        ],
        'outillage.instants-en-utc' => [
            'puce' => 'Outillage',
            'regle' => 'Les instants s\'enregistrent, se comparent et se calculent en UTC, fuseau de l\'application et de la session PostgreSQL, sans aucune heure par défaut en base ; les migrations fixent le fuseau par défaut de la base à UTC, que contrôle caisse:diagnostiquer.',
            'tests' => [
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_la_session_postgresql_est_en_utc',
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_l_application_refuse_un_fuseau_autre_que_utc',
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_un_instant_s_ecrit_en_utc_quel_que_soit_le_fuseau_de_la_date_donnee',
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_les_colonnes_d_horodatage_ont_leur_cast_et_aucune_valeur_par_defaut',
                'tests/Feature/Calendrier/FuseauParDefautDeLaBaseTest.php::test_les_migrations_fixent_a_utc_le_fuseau_par_defaut_de_la_base',
                'tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php::test_diagnostiquer_echoue_quand_le_fuseau_par_defaut_de_la_base_n_est_pas_utc',
                'tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php::test_diagnostiquer_echoue_quand_le_fuseau_de_l_application_n_est_pas_utc',
            ],
        ],
        'outillage.seul-le-calendrier-connait-un-fuseau' => [
            'puce' => 'Outillage',
            'regle' => 'Un jour est une date « AAAA-MM-JJ » de son calendrier ; seule la classe Calendrier connaît un fuseau et donne le jour, l\'année, minuit, les décalages en jours (la même heure murale n jours plus tard) et l\'affichage, et un jour s\'affiche tel quel.',
            'tests' => [
                'tests/Unit/CalendrierTest.php::test_un_decalage_en_jours_garde_l_heure_de_paris_a_travers_un_changement_d_heure',
                'tests/Unit/CalendrierTest.php::test_un_decalage_en_jours_outre_mer_garde_l_heure_locale',
                'tests/Feature/Calendrier/HeureRepeteeTest.php::test_un_code_de_courrier_expire_trente_jours_apres_son_envoi_a_la_meme_heure_de_paris',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_seul_le_calendrier_connait_un_fuseau_et_met_les_dates_en_forme',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_aucune_requete_n_extrait_une_date_en_sql_ni_ne_compare_par_where_date',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_les_colonnes_de_date_ont_les_casts_du_calendrier_et_aucune_heure_par_defaut',
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_un_jour_civil_s_ecrit_et_se_relit_tel_quel_et_refuse_un_objet_date',
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_un_jour_s_affiche_tel_quel_dans_le_panneau_d_un_magasin_de_la_martinique',
            ],
        ],
        'outillage.heure-locale-du-territoire' => [
            'puce' => 'Outillage',
            'regle' => 'Chaque magasin suit l\'heure locale de son territoire (métropole et Corse à l\'heure de Paris, chaque territoire d\'outre-mer à la sienne) ; la société, ses e-mails, le journal d\'audit et les tâches techniques suivent l\'heure de Paris ; une page de plusieurs calendriers nomme toute heure qui n\'est pas celle de Paris.',
            'tests' => [
                'tests/Feature/Calendrier/HeureLocaleTest.php::test_un_magasin_de_la_martinique_vend_a_20_heures_au_jour_local_et_cloture_a_minuit_local',
                'tests/Feature/Calendrier/HeureLocaleTest.php::test_une_societe_avec_un_magasin_a_paris_et_un_en_guadeloupe_suit_deux_calendriers',
                'tests/Feature/Calendrier/NuitDeParisTest.php::test_une_vente_apres_minuit_a_paris_appartient_a_la_journee_de_paris',
                'tests/Feature/Calendrier/NuitDeParisTest.php::test_le_pied_des_e_mails_porte_l_annee_de_paris',
                'tests/Feature/Calendrier/HeuresDeLaSocieteTest.php::test_les_heures_de_la_societe_restent_a_l_heure_de_paris_et_celles_d_un_magasin_d_outre_mer_sont_nommees',
                'tests/Feature/Calendrier/HorodatageEnUtcTest.php::test_le_panneau_d_un_magasin_d_outre_mer_affiche_ses_instants_a_son_heure',
            ],
        ],
        'outillage.heure-repetee' => [
            'puce' => 'Outillage',
            'regle' => 'Le journal des événements et le journal d\'audit ajoutent « (heure d\'été) » ou « (heure d\'hiver) » à une heure répétée au passage à l\'heure d\'hiver, et aucun autre affichage ne le fait.',
            'tests' => [
                'tests/Feature/Calendrier/HeureRepeteeTest.php::test_le_journal_des_evenements_dit_l_heure_d_ete_ou_d_hiver_pendant_l_heure_repetee',
                'tests/Feature/Calendrier/HeureRepeteeTest.php::test_les_releves_et_les_journaux_se_trient_dans_l_ordre_reel_des_deux_passages',
            ],
        ],
        'outillage.planificateur' => [
            'puce' => 'Outillage',
            'regle' => 'Le planificateur suit l\'heure de Paris, sans tâche quotidienne entre 2 h et 3 h, et les tâches du calendrier des magasins passent toutes les heures et ne traitent que ce que le calendrier de chaque magasin rend dû.',
            'tests' => [
                'tests/Feature/Calendrier/PlanificateurTest.php::test_aucune_tache_quotidienne_n_est_planifiee_entre_2_et_3_heures',
                'tests/Feature/Calendrier/PlanificateurTest.php::test_les_taches_quotidiennes_et_mensuelles_partent_a_l_heure_de_paris_en_ete_en_hiver_et_les_jours_de_changement_d_heure',
                'tests/Feature/Calendrier/PlanificateurTest.php::test_chaque_minuit_local_tombe_sur_un_passage_horaire',
                'tests/Feature/Calendrier/PlanificateurTest.php::test_les_taches_du_calendrier_des_magasins_ne_se_chevauchent_pas',
                'tests/Feature/Calendrier/IsolationDesCalendriersTest.php::test_un_passage_horaire_sans_journee_due_ne_verrouille_aucun_registre',
            ],
        ],
        'outillage.tests-sans-hasard-ni-heure-reelle' => [
            'puce' => 'Outillage',
            'regle' => 'Aucun test ne dépend du hasard ni de la date ou de l\'heure réelles : Faker est semé par test, toute heure écrite porte son fuseau, toute attente liée au calendrier fige le temps.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_faker_est_seme_par_test_et_redonne_les_memes_valeurs_a_chaque_execution',
                'tests/Feature/OutillageTest.php::test_str_random_est_seme_par_test_et_redonne_les_memes_chaines_a_chaque_execution',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_aucun_test_ne_voyage_en_jours_ni_ne_part_de_l_heure_reelle',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_un_voyage_dans_le_temps_exige_une_horloge_figee',
                'tests/Feature/Calendrier/ReglesDuCalendrierTest.php::test_les_tests_lisent_le_jour_et_le_fuseau_par_le_calendrier',
            ],
        ],
        'outillage.horloge-du-serveur' => [
            'puce' => 'Outillage',
            'regle' => 'Chaque serveur qui écrit au registre fiscal est à l\'heure exacte, contrôlée par caisse:diagnostiquer, qui contrôle aussi gd, qpdf (11 ou plus) et l\'antivirus.',
            'tests' => [
                'tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php::test_le_diagnostic_controle_l_horloge_du_serveur_sur_celle_du_serveur_de_base',
                'tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php::test_le_diagnostic_controle_gd_qpdf_et_l_antivirus',
                'tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php::test_le_diagnostic_exige_qpdf_11_ou_plus',
                'tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php::test_le_diagnostic_echoue_avec_l_antivirus_factice_en_production_ou_sans_qpdf',
            ],
        ],
        // Comptes et équipe.
        'comptes.un-compte-une-societe' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un compte appartient pour toujours à une seule société et n\'en change jamais ; une personne qui rejoint une autre société y reçoit un nouveau compte.',
            'tests' => [
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_les_actions_des_membres_refusent_un_auteur_ou_un_compte_d_une_autre_societe',
                'tests/Feature/Authorization/MembrePolicyTest.php::test_le_proprietaire_ne_voit_ni_ne_gere_un_compte_d_une_autre_societe',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_la_propriete_ne_va_ni_a_un_compte_d_une_autre_societe_ni_a_un_compte_suspendu_ni_a_un_simple_membre',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_la_base_refuse_d_affecter_un_compte_au_magasin_d_une_autre_societe',
            ],
        ],
        'comptes.identifiant-de-huit-chiffres' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Chaque compte a un identifiant de connexion de 8 chiffres tiré au hasard (jamais une suite), unique dans tout le logiciel, qui ne change jamais et n\'est jamais redonné, même après le retrait du compte.',
            'tests' => [
                'tests/Feature/Societe/IdentifiantsTest.php::test_chaque_compte_recoit_un_identifiant_de_huit_chiffres_dont_le_premier_n_est_pas_zero',
                'tests/Feature/Societe/IdentifiantsTest.php::test_les_identifiants_tires_a_la_suite_ne_se_suivent_jamais',
                'tests/Feature/Societe/IdentifiantsTest.php::test_aucun_identifiant_trivial_n_est_jamais_tire',
                'tests/Feature/Societe/IdentifiantsTest.php::test_deux_comptes_n_ont_jamais_le_meme_identifiant_meme_dans_deux_societes',
                'tests/Feature/Societe/IdentifiantsTest.php::test_un_identifiant_n_est_jamais_redonne_meme_apres_le_retrait_du_compte',
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_un_compte_retire_ne_libere_jamais_son_identifiant',
            ],
        ],
        'comptes.qui-voit-l-identifiant' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'L\'identifiant ne se montre qu\'à la personne, au propriétaire, au responsable de son magasin pour un vendeur ou un observateur, et à un administrateur seulement par un geste inscrit au journal d\'audit ; jamais dans un document, un e-mail, le journal d\'audit ni un journal technique.',
            'tests' => [
                'tests/Feature/Filament/EquipeDuMagasinTest.php::test_un_responsable_ne_voit_jamais_l_identifiant_d_un_compte_auquel_il_ne_peut_pas_redonner_l_acces',
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_un_administrateur_ne_voit_l_identifiant_que_par_une_action_tracee_et_le_proprietaire_en_est_prevenu',
                'tests/Feature/Authorization/LimitesDesDroitsTest.php::test_le_proprietaire_ne_voit_jamais_l_identifiant_d_un_compte_d_une_autre_societe',
                'tests/Feature/Societe/LimitationDesConnexionsTest.php::test_les_essais_ou_le_mot_de_passe_egale_l_identifiant_alertent_l_editeur_sans_identifiant_dans_le_journal',
                'tests/Feature/Courriels/EssayerUnCourrielTest.php::test_des_identifiants_refuses_ne_montrent_jamais_l_identifiant',
                'tests/Feature/Societe/MembresTest.php::test_le_premier_mot_de_passe_n_est_jamais_journalise_ni_envoye_par_e_mail',
            ],
        ],
        'comptes.connexion-par-identifiant' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Le propriétaire se connecte avec son adresse e-mail ou son identifiant ; tous les autres comptes seulement avec leur identifiant et leur mot de passe ; l\'adresse d\'un autre compte n\'est qu\'un contact et n\'ouvre jamais ce compte.',
            'tests' => [
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_le_proprietaire_se_connecte_avec_son_adresse_ou_son_identifiant',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_membre_se_connecte_avec_son_identifiant_avec_ou_sans_espaces_ni_tirets',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_l_adresse_de_contact_d_un_autre_compte_n_ouvre_jamais_ce_compte',
                'tests/Feature/Filament/ModifierProfilTest.php::test_une_adresse_de_contact_s_enregistre_sans_confirmation_et_peut_etre_celle_d_un_autre_compte',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_l_expert_comptable_ne_se_connecte_jamais_avec_son_adresse_ni_avec_son_identifiant_pour_mot_de_passe',
            ],
        ],
        'comptes.adresse-de-connexion-du-proprietaire' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'L\'adresse e-mail du propriétaire est son adresse de connexion, qu\'aucun autre propriétaire n\'utilise et qui ne sert qu\'une fois confirmée par un lien envoyé à cette adresse.',
            'tests' => [
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_l_adresse_choisie_en_saisissant_le_code_n_ouvre_rien_avant_son_lien',
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_le_lien_de_l_adresse_choisie_ne_se_suit_que_connecte_a_ce_compte',
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_un_lien_qui_ne_part_pas_a_la_saisie_du_code_laisse_la_personne_connectee_avec_son_identifiant',
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_une_adresse_prise_par_un_autre_proprietaire_avant_la_confirmation_est_refusee',
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_un_lien_expire_se_redemande_depuis_le_profil',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_une_adresse_en_attente_ou_d_une_inscription_close_coute_comme_une_adresse_inconnue',
            ],
        ],
        'comptes.adresse-de-l-expert-comptable' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'L\'adresse professionnelle d\'un expert-comptable de la société est obligatoire tant que son compte n\'est pas retiré, se corrige par le propriétaire tant que la personne n\'a pas choisi son mot de passe, reçoit les liens de son mot de passe et ne sert jamais à se connecter.',
            'tests' => [
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_le_proprietaire_corrige_l_adresse_tant_que_la_personne_n_a_pas_choisi_son_mot_de_passe',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_l_adresse_ne_se_corrige_plus_une_fois_le_mot_de_passe_choisi_meme_sans_connexion',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_le_profil_garde_l_adresse_obligatoire_d_un_expert_comptable',
            ],
        ],
        'comptes.adresses-en-minuscules' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Une adresse e-mail s\'enregistre et se compare en minuscules, sans espaces autour.',
            'tests' => [
                'tests/Feature/Tenancy/FournisseurDesComptesTest.php::test_l_adresse_d_un_compte_s_enregistre_en_minuscules_sans_espaces',
                'tests/Feature/Filament/MotDePasseOublieTest.php::test_la_demande_avec_l_adresse_tapee_en_majuscules_envoie_le_lien',
            ],
        ],
        'comptes.profil' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Chacun change lui-même son nom, son adresse e-mail et son mot de passe dans son profil, en donnant son mot de passe actuel ; la nouvelle adresse de connexion ne prend effet qu\'après confirmation par un lien, et l\'ancienne est prévenue.',
            'tests' => [
                'tests/Feature/Filament/ModifierProfilTest.php::test_changer_son_nom_et_son_adresse_sans_le_mot_de_passe_actuel_est_refuse',
                'tests/Feature/Filament/ModifierProfilTest.php::test_une_nouvelle_adresse_ne_prend_effet_qu_apres_confirmation_et_l_ancienne_est_prevenue',
                'tests/Feature/Filament/ModifierProfilTest.php::test_une_adresse_prise_avant_la_confirmation_est_refusee_sans_rien_changer',
            ],
        ],
        'comptes.regle-du-mot-de-passe' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un mot de passe choisi compte au moins 8 caractères, n\'est jamais l\'identifiant ni ne le contient, et n\'est ni un seul caractère répété ni une suite de chiffres.',
            'tests' => [
                'tests/Feature/Filament/ModifierProfilTest.php::test_un_mot_de_passe_egal_a_l_identifiant_qui_le_contient_trop_court_ou_trivial_est_refuse',
                'tests/Unit/RegleDuMotDePasseTest.php::test_seule_une_suite_de_chiffres_de_bout_en_bout_est_refusee',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_le_mot_de_passe_choisi_suit_la_regle_et_ne_contient_pas_l_identifiant',
            ],
        ],
        'comptes.inscription-par-lien' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Une société s\'inscrit par un lien envoyé à l\'adresse de son propriétaire, valable 24 heures ; après ce lien, le propriétaire arrive dans l\'espace de sa société, sur une liste d\'étapes.',
            'tests' => [
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_la_demande_envoie_un_lien_et_ne_cree_rien_avant_sa_confirmation',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_apres_le_lien_le_proprietaire_arrive_sur_la_liste_de_ses_premieres_etapes',
                'tests/Feature/Calendrier/HeureRepeteeTest.php::test_un_lien_d_inscription_demande_au_premier_passage_expire_24_heures_plus_tard',
                'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
            ],
        ],
        'comptes.proprietaire-sans-vente' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Le propriétaire a tous les droits sans qu\'aucune autorisation ne lui soit demandée, sauf celui de vendre, qu\'il n\'a dans aucun magasin réel : ni ticket, ni facture, ni encaissement, ni bon d\'avoir, ni caisse ; il contre-passe, envoie et relance, clôture la journée, ferme la caisse d\'un vendeur, fait les avoirs et les devis.',
            'tests' => [
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_n_a_aucune_autorisation_de_vente_dans_aucun_de_ses_magasins_reels',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_n_ouvre_pas_la_caisse_et_n_a_pas_de_caisse_personnelle',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_ne_vend_pas_par_livewire',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_ne_vend_pas_par_l_action_appelee_directement',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_garde_la_consultation_la_cloture_du_jour_la_fermeture_d_une_caisse_la_contre_passation_et_l_envoi',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_contre_passe_un_paiement_mais_n_enregistre_pas_le_bon_paiement',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_fait_les_avoirs_et_les_devis',
            ],
        ],
        'comptes.proprietaire-dans-la-demonstration' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Dans le magasin de démonstration de sa société, tant qu\'il est ouvert, le propriétaire a toutes les autorisations qui existent, celles de la vente comprises (décision du 12/11/2026).',
            'tests' => [
                'tests/Feature/Authorization/ProprietaireEnDemonstrationTest.php::test_le_proprietaire_vend_encaisse_fait_un_avoir_utilise_un_bon_ferme_sa_caisse_et_cloture_dans_la_demonstration',
                'tests/Browser/Parcours/ProprietaireEnDemonstrationTest.php › le propriétaire vend à la caisse de la démonstration, encaisse en espèces et par carte, ferme sa caisse et clôture la journée',
            ],
        ],
        'comptes.administrateurs-du-proprietaire' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Seul le propriétaire crée et gère les administrateurs : il choisit l\'accès de chacun à tous les magasins, présents et futurs, ou à une sélection, et coche ses autorisations.',
            'tests' => [
                'tests/Feature/Societe/AdministrateursTest.php::test_le_proprietaire_cree_un_administrateur_avec_sa_portee_et_ses_autorisations_en_une_fois',
                'tests/Feature/Societe/AdministrateursTest.php::test_aucune_portee_n_est_preselectionnee',
                'tests/Feature/Societe/AdministrateursTest.php::test_un_administrateur_de_tous_les_magasins_a_ses_autorisations_dans_un_magasin_cree_apres_lui',
                'tests/Feature/Societe/AdministrateursTest.php::test_un_administrateur_d_une_selection_n_a_rien_hors_de_sa_selection',
                'tests/Feature/Societe/AdministrateursTest.php::test_un_administrateur_d_une_selection_n_obtient_rien_dans_un_magasin_cree_apres_lui',
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_un_administrateur_ne_touche_ni_au_proprietaire_ni_a_un_administrateur_ni_a_lui_meme',
            ],
        ],
        'comptes.administrateur-sans-vente-par-defaut' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un administrateur ne vend pas, sauf si le propriétaire lui coche les autorisations de vente, jamais précochées pour lui, pas même pour l\'ancien propriétaire ; sans elles, il n\'a pas de caisse personnelle ; avec elles, il n\'encaisse que dans un magasin à la fois.',
            'tests' => [
                'tests/Feature/Societe/AdministrateursTest.php::test_le_modele_de_l_administrateur_ne_precoche_aucune_autorisation_de_vente',
                'tests/Feature/Societe/AdministrateursTest.php::test_un_administrateur_a_qui_le_proprietaire_coche_la_vente_vend_avec_une_caisse_a_la_fois',
                'tests/Feature/Authorization/AutorisationsDesRolesTest.php::test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_les_autorisations_de_vente',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_l_ancien_proprietaire_devenu_administrateur_n_a_aucune_autorisation_de_vente',
                'tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php::test_un_administrateur_sans_vente_n_a_pas_fermer_ma_caisse_mais_ferme_celle_d_un_vendeur_s_il_peut_cloturer',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_un_administrateur_vendeur_n_encaisse_dans_b_que_s_il_y_a_acces_au_moment_de_la_vente',
            ],
        ],
        'comptes.administrateur-et-equipe-des-magasins' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un administrateur ne crée ni ne gère jamais les vendeurs et les observateurs, sauf pour nommer responsable l\'un d\'eux et, si le propriétaire l\'y autorise, pour leur redonner l\'accès dans les magasins réels de sa portée.',
            'tests' => [
                'tests/Feature/Filament/EquipeDuMagasinTest.php::test_un_administrateur_ne_cree_jamais_un_vendeur_ou_un_observateur',
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_un_administrateur_ne_coche_jamais_les_autorisations_d_un_vendeur_ou_d_un_observateur',
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_un_administrateur_ne_modifie_ne_suspend_ni_ne_retire_aucun_vendeur_ou_observateur',
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_un_administrateur_autorise_par_le_proprietaire_redonne_l_acces_d_un_vendeur_de_sa_portee_et_ne_fait_rien_d_autre_sur_ce_compte',
                'tests/Feature/Societe/AdministrateursTest.php::test_l_autorisation_redonner_l_acces_n_est_jamais_precochee_et_reste_permise_en_lecture_seule',
            ],
        ],
        'comptes.nomination-des-responsables' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un administrateur ne nomme et ne retire les responsables que si le propriétaire l\'y autorise, et seulement dans les magasins réels de sa portée ; sa nomination prend effet aussitôt, le propriétaire en est prévenu et peut l\'annuler en un clic, et l\'administrateur peut annuler sa propre nomination.',
            'tests' => [
                'tests/Feature/Societe/ResponsablesTest.php::test_un_administrateur_sans_l_autorisation_ne_nomme_aucun_responsable',
                'tests/Feature/Societe/ResponsablesTest.php::test_un_administrateur_autorise_nomme_et_retire_les_responsables_de_ses_seuls_magasins',
                'tests/Feature/Societe/ResponsablesTest.php::test_un_administrateur_autorise_d_une_selection_ne_nomme_aucun_responsable_hors_de_sa_selection',
                'tests/Feature/Societe/ResponsablesTest.php::test_le_proprietaire_est_prevenu_de_chaque_responsable_nomme_par_un_administrateur',
                'tests/Feature/Societe/ResponsablesTest.php::test_le_proprietaire_annule_une_nomination_en_un_clic',
                'tests/Feature/Societe/ResponsablesTest.php::test_l_administrateur_auteur_annule_sa_propre_nomination',
                'tests/Feature/Societe/ResponsablesTest.php::test_un_autre_administrateur_n_annule_pas_la_nomination',
                'tests/Feature/Societe/AdministrateursTest.php::test_le_modele_d_un_nouvel_administrateur_precoche_gerer_les_magasins_et_pas_nommer_les_responsables',
            ],
        ],
        'comptes.administrateur-et-responsables' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un administrateur ne suspend, ne réactive ni ne rétrograde jamais un responsable ; il peut seulement retirer de la société le compte d\'un responsable d\'un magasin de sa portée, et ne touche jamais au propriétaire ni à un autre administrateur.',
            'tests' => [
                'tests/Feature/Societe/ResponsablesTest.php::test_un_administrateur_ne_suspend_ni_ne_reactive_ni_ne_retrograde_un_responsable',
                'tests/Feature/Societe/ResponsablesTest.php::test_un_administrateur_autorise_ne_retire_que_les_responsables_des_magasins_de_sa_portee',
                'tests/Feature/Societe/ResponsablesTest.php::test_un_administrateur_autorise_ne_nomme_jamais_le_proprietaire_ni_un_administrateur',
                'tests/Feature/Authorization/PorteeDesDroitsTest.php::test_les_regles_de_la_matrice_sont_respectees_geste_par_geste',
            ],
        ],
        'comptes.responsables' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un magasin peut avoir plusieurs responsables, et une même personne peut être responsable de plusieurs magasins, avec un compte et un identifiant dans chacun ; un responsable a tous les droits dans son magasin, sans case, sauf le téléchargement des documents par période, et jamais ceux de la société.',
            'tests' => [
                'tests/Feature/Societe/ResponsablesTest.php::test_le_proprietaire_nomme_plusieurs_responsables_dans_un_magasin_et_une_personne_responsable_de_deux_magasins_a_deux_comptes',
                'tests/Feature/Authorization/DroitsDuResponsableTest.php::test_un_responsable_a_tous_les_droits_de_son_magasin_sans_case',
                'tests/Feature/Authorization/DroitsDuResponsableTest.php::test_un_responsable_n_a_aucun_droit_de_societe',
                'tests/Feature/Authorization/DroitsDuResponsableTest.php::test_un_responsable_n_a_aucun_droit_dans_un_autre_magasin',
                'tests/Feature/Authorization/DroitsDuResponsableTest.php::test_deux_responsables_du_meme_magasin_ont_les_memes_droits',
                'tests/Feature/Filament/EquipeDuMagasinTest.php::test_un_responsable_ne_cree_ni_ne_touche_un_autre_responsable_ni_un_administrateur_ni_le_proprietaire',
            ],
        ],
        'comptes.un-compte-de-membre-un-magasin' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un compte de responsable, de vendeur ou d\'observateur ne vaut que pour un magasin, qu\'il ne quitte jamais ; seuls le propriétaire et un responsable du magasin créent ses vendeurs et ses observateurs et leur cochent des autorisations, un responsable jamais au-delà des siennes ; retirer un compte de son magasin le retire de la société.',
            'tests' => [
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_compte_de_membre_n_ouvre_que_son_magasin',
                'tests/Feature/Societe/MembresTest.php::test_le_proprietaire_cree_un_vendeur_pour_un_magasin_avec_son_identifiant_pour_premier_mot_de_passe',
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_retirer_un_compte_de_son_magasin_le_retire_de_la_societe',
                'tests/Feature/Filament/EquipeDuMagasinTest.php::test_retirer_un_vendeur_de_son_magasin_le_retire_de_la_societe_apres_confirmation',
                'tests/Feature/Societe/ResponsablesTest.php::test_retirer_un_responsable_de_son_magasin_le_retire_de_la_societe',
            ],
        ],
        'comptes.changement-de-role' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un changement de rôle garde le compte, son identifiant et son historique, et s\'inscrit au journal d\'audit avec l\'ancien et le nouveau rôle, la date et son auteur ; un compte d\'entraînement et un compte d\'expert-comptable de la société ne changent jamais de rôle de société.',
            'tests' => [
                'tests/Feature/Societe/ChangementDeRoleTest.php::test_un_vendeur_devient_responsable_de_son_magasin_avec_le_meme_compte_et_le_meme_identifiant',
                'tests/Feature/Societe/ChangementDeRoleTest.php::test_un_vendeur_devient_administrateur_avec_le_meme_compte_sans_aucune_autorisation_de_vente',
                'tests/Feature/Societe/ChangementDeRoleTest.php::test_un_administrateur_ne_redevient_membre_que_dans_le_magasin_de_son_identifiant',
                'tests/Feature/Societe/ChangementDeRoleTest.php::test_un_changement_de_role_s_inscrit_au_journal_d_audit_avec_l_ancien_et_le_nouveau_role_la_date_et_l_auteur',
                'tests/Feature/Societe/ChangementDeRoleTest.php::test_un_compte_d_entrainement_ne_devient_pas_administrateur',
            ],
        ],
        'comptes.decisions-du-proprietaire' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un compte suspendu ou retiré par le propriétaire n\'est réactivé ou réintégré que par le propriétaire, sa décision tenant même après une transmission de la propriété ; personne ne modifie ses propres droits ni ceux du propriétaire.',
            'tests' => [
                'tests/Feature/Authorization/PorteeDesDroitsTest.php::test_un_compte_suspendu_par_le_proprietaire_ne_se_retire_pas_par_un_autre',
                'tests/Feature/Authorization/PorteeDesDroitsTest.php::test_les_decisions_du_proprietaire_tiennent_apres_la_transmission_de_la_propriete',
                'tests/Feature/Societe/AutorisationsDesMembresTest.php::test_un_administrateur_ne_touche_ni_au_proprietaire_ni_a_un_administrateur_ni_a_lui_meme',
                'tests/Feature/Societe/AdministrateursTest.php::test_le_proprietaire_reintegre_un_administrateur_retire_depuis_sa_fiche',
            ],
        ],
        'comptes.premier-mot-de-passe' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un compte créé par la société a pour premier mot de passe son identifiant, remis en main propre, jamais envoyé par e-mail (sauf l\'expert-comptable de la société, qui choisit le sien par un lien de 7 jours) ; une alerte le conseille à la première connexion, le profil le rappelle, la liste de l\'équipe l\'indique ; un responsable ou un administrateur choisit le sien à sa première connexion.',
            'tests' => [
                'tests/Feature/Societe/MembresTest.php::test_le_premier_mot_de_passe_n_est_jamais_journalise_ni_envoye_par_e_mail',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_compte_dont_le_mot_de_passe_est_l_identifiant_se_connecte_et_recoit_l_alerte_a_sa_premiere_connexion',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_responsable_un_administrateur_ou_un_collaborateur_choisit_son_mot_de_passe_avant_d_agir',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_compte_promu_choisit_son_mot_de_passe_a_sa_connexion_suivante',
                'tests/Feature/Filament/ModifierProfilTest.php::test_le_profil_rappelle_que_le_mot_de_passe_est_encore_l_identifiant',
                'tests/Feature/Filament/EquipeDuMagasinTest.php::test_la_liste_de_l_equipe_signale_au_responsable_un_mot_de_passe_encore_egal_a_l_identifiant_et_un_compte_jamais_connecte',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_la_declaration_cree_un_compte_de_la_societe_dont_le_mot_de_passe_n_est_jamais_l_identifiant',
            ],
        ],
        'comptes.poste-reconnu' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Tant que le mot de passe d\'un compte est son identifiant, il n\'ouvre ce compte que depuis un poste du magasin reconnu par le propriétaire ou un responsable ; un collaborateur d\'un cabinet choisit son mot de passe en présence du propriétaire du cabinet.',
            'tests' => [
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_le_mot_de_passe_egal_a_l_identifiant_n_ouvre_le_compte_que_depuis_un_poste_reconnu_du_magasin',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_poste_se_retire_depuis_la_liste_des_postes_du_magasin',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_un_collaborateur_ne_peut_pas_se_connecter_avec_son_identifiant_faute_de_poste_reconnu',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_la_fenetre_du_compte_cree_propose_d_office_au_collaborateur_de_choisir_son_mot_de_passe',
            ],
        ],
        'comptes.premiere-connexion-signalee' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Le propriétaire est prévenu de la première connexion de chaque compte ; à chaque connexion, la personne voit la date de sa connexion précédente.',
            'tests' => [
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_le_proprietaire_est_prevenu_de_la_premiere_connexion_de_chaque_compte',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_une_connexion_refusee_ne_compte_ni_comme_premiere_ni_comme_derniere_connexion',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_la_personne_voit_sa_connexion_precedente_a_chaque_connexion',
            ],
        ],
        'comptes.creation-repetee' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Une demande de création répétée après une réponse perdue ne crée jamais un second compte.',
            'tests' => [
                'tests/Feature/Societe/IdentifiantsTest.php::test_une_creation_de_compte_repetee_ne_cree_qu_un_compte',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_la_creation_repetee_d_un_collaborateur_ne_cree_jamais_un_second_compte',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_une_creation_repetee_avec_le_meme_jeton_ne_cree_ni_un_second_compte_ni_un_second_lien',
            ],
        ],
        'comptes.acces-redonne' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un accès perdu se redonne par le propriétaire pour tout compte, par un responsable pour un vendeur ou un observateur de son magasin, par un administrateur autorisé dans sa portée ; chaque renouvellement ferme toutes les sessions du compte, s\'inscrit au journal d\'audit, et le propriétaire est prévenu de chaque renouvellement qu\'il n\'a pas fait ; personne ne redonne l\'accès du propriétaire, qui le retrouve par « Mot de passe oublié ».',
            'tests' => [
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_redonner_l_acces_ferme_les_sessions_coupe_le_rappel_et_s_inscrit_au_journal_d_audit',
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_seul_le_proprietaire_redonne_l_acces_d_un_administrateur',
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_un_administrateur_autorise_a_nommer_les_responsables_redonne_l_acces_d_un_responsable_de_sa_portee',
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_personne_ne_redonne_l_acces_du_proprietaire',
                'tests/Feature/Societe/RenouvellementDAccesTest.php::test_le_proprietaire_est_prevenu_d_un_acces_redonne_par_un_autre_puis_du_premier_mot_de_passe_choisi_ensuite',
                'tests/Feature/Filament/MotDePasseOublieTest.php::test_la_demande_envoie_un_lien_de_reinitialisation_au_proprietaire',
            ],
        ],
        'comptes.essais-de-connexion' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Les essais de connexion manqués font attendre, jamais bloquer pour de bon, jamais sur un poste où le compte s\'est déjà connecté, et reçoivent la même réponse, au même délai, pour un identifiant qui n\'existe pas.',
            'tests' => [
                'tests/Feature/Societe/LimitationDesConnexionsTest.php::test_trop_d_echecs_pour_un_identifiant_et_une_adresse_ip_font_attendre_de_plus_en_plus',
                'tests/Feature/Societe/LimitationDesConnexionsTest.php::test_aucun_compte_n_est_jamais_bloque_definitivement',
                'tests/Feature/Societe/LimitationDesConnexionsTest.php::test_les_connexions_reussies_d_un_meme_poste_ne_bloquent_pas_les_vendeurs_suivants',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_identifiant_inconnu_un_mot_de_passe_faux_un_compte_suspendu_ou_retire_recoivent_la_meme_reponse',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_un_identifiant_inconnu_fait_la_meme_verification_de_hachage',
            ],
        ],
        'comptes.magasin-cede-ferme-et-entrainement' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Les comptes d\'un magasin cédé ou fermé définitivement sont gardés ; dans le magasin de démonstration, le propriétaire et un responsable créent des comptes d\'entraînement, qui n\'ouvrent jamais un magasin réel et sont retirés quand la démonstration se ferme pour de bon.',
            'tests' => [
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_les_comptes_d_un_magasin_cede_ou_ferme_pour_cessation_sont_gardes',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_le_proprietaire_et_un_responsable_creent_des_comptes_d_entrainement_dans_la_demonstration',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_un_administrateur_ne_cree_aucun_compte_d_entrainement',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_les_comptes_d_entrainement_sont_retires_a_la_validation_ou_a_la_cloture_et_gardes_a_la_fermeture_pour_inactivite',
            ],
        ],
        'comptes.se-souvenir-de-moi' => [
            'puce' => 'Comptes et équipe',
            'regle' => '« Se souvenir de moi » ne vaut que pour le compte du propriétaire ; tout autre compte se connecte à chaque fois.',
            'tests' => [
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_se_souvenir_de_moi_ne_vaut_que_pour_le_proprietaire',
                'tests/Feature/Societe/ConnexionParIdentifiantTest.php::test_le_cookie_de_rappel_d_un_compte_qui_n_est_plus_proprietaire_n_ouvre_rien',
            ],
        ],
        'comptes.nom-fige-dans-l-historique' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'L\'historique garde le nom de chaque personne tel qu\'il était au moment de chaque opération.',
            'tests' => [
                'tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php::test_les_ecrans_de_l_historique_affichent_le_nom_tel_qu_il_etait_au_moment_de_l_operation',
                'tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php::test_chaque_colonne_qui_designe_une_personne_dans_l_historique_a_son_nom_fige',
                'tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php::test_le_nom_fige_ne_se_modifie_jamais_apres_coup',
                'tests/Feature/Tenancy/NomPourLHistoriqueTest.php::test_le_nom_fige_d_un_compte_de_cabinet_porte_le_nom_du_cabinet_au_moment_de_l_operation',
            ],
        ],
        'comptes.tracabilite-des-ventes' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Chaque vente enregistre le magasin où elle est faite, avec son SIRET, et le compte autorisé à vendre dans ce magasin à ce moment, avec le nom de la personne ; la base refuse une vente à son nom dans tout autre magasin.',
            'tests' => [
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_une_vente_enregistre_le_magasin_de_l_adresse_et_son_siret_jamais_un_autre_magasin_du_vendeur',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_le_contenu_signe_porte_le_siret_du_magasin_et_l_operateur',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_retirer_le_vendeur_de_b_pendant_qu_un_onglet_est_ouvert_refuse_la_vente_suivante_dans_b',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_deux_onglets_de_deux_magasins_n_echangent_jamais_leur_magasin',
            ],
        ],
        'comptes.une-caisse-a-la-fois' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un compte autorisé à vendre n\'encaisse que dans un magasin à la fois : un administrateur autorisé à vendre dans plusieurs magasins ferme sa caisse d\'un magasin avant d\'encaisser dans un autre ; une personne qui a un compte dans deux magasins peut avoir une caisse ouverte avec chacun.',
            'tests' => [
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_un_vendeur_n_encaisse_dans_b_qu_apres_avoir_ferme_sa_caisse_de_a',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_un_administrateur_autorise_a_vendre_dans_deux_magasins_n_encaisse_que_dans_un_a_la_fois',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_deux_premieres_ventes_simultanees_du_meme_vendeur_dans_a_et_b_n_en_laissent_passer_qu_une',
                'tests/Feature/Tenancy/TracabiliteDesVentesTest.php::test_une_meme_personne_avec_deux_comptes_peut_avoir_une_caisse_ouverte_avec_chacun',
                'tests/Feature/Societe/AdministrateursTest.php::test_un_administrateur_a_qui_le_proprietaire_coche_la_vente_vend_avec_une_caisse_a_la_fois',
            ],
        ],
        'comptes.e-mail-reverifie-a-l-envoi' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Un e-mail demandé par une personne ne part que si, au moment de l\'envoi, elle a encore accès au magasin et le droit d\'envoyer ; sinon il ne part pas et le propriétaire en est prévenu.',
            'tests' => [
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_un_envoi_demande_par_un_membre_retire_ou_suspendu_entre_temps_ne_part_pas_et_le_proprietaire_est_prevenu',
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_un_envoi_ne_part_pas_si_le_magasin_a_ete_suspendu_entre_temps',
            ],
        ],
        'comptes.gestes-reserves-au-proprietaire' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Céder un magasin, consulter le journal d\'audit et transmettre la propriété restent réservés au propriétaire.',
            'tests' => [
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_seul_le_proprietaire_cede_un_magasin_en_retapant_son_nom',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_seul_le_proprietaire_consulte_le_journal_et_les_traces',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_le_proprietaire_transmet_la_propriete_a_un_administrateur_par_un_lien_sans_preuve_de_qualite',
            ],
        ],
        'comptes.transmission-de-la-propriete' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Le propriétaire transmet la propriété, en redonnant son mot de passe, à un administrateur actif qui a choisi son mot de passe et renseigné lui-même son adresse ; un lien de 24 heures part à cette adresse et la propriété ne passe que quand cet administrateur le suit, connecté à son compte ; l\'ancien propriétaire devient administrateur, garde son identifiant et ne se connecte plus qu\'avec lui ; une inscription close ne se transmet plus.',
            'tests' => [
                'tests/Feature/Societe/TransfererProprieteTest.php::test_la_transmission_ne_prend_effet_qu_au_lien_suivi_par_l_administrateur_connecte_a_son_compte',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_un_lien_de_transmission_expire_annule_deja_suivi_ou_ouvert_par_un_autre_compte_ne_change_rien',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_la_transmission_exige_un_administrateur_actif_qui_a_renseigne_lui_meme_son_adresse',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_la_transmission_exige_un_administrateur_qui_a_choisi_son_mot_de_passe',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_l_ancien_proprietaire_garde_son_identifiant_perd_le_rappel_et_ne_se_connecte_plus_par_son_adresse',
                'tests/Feature/Societe/TransfererProprieteTest.php::test_la_propriete_d_une_inscription_close_ne_se_transmet_plus',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_l_ancien_proprietaire_du_cabinet_devient_collaborateur_avec_tous_les_clients_confies',
            ],
        ],
        'comptes.signature-des-attestations' => [
            'puce' => 'Comptes et équipe',
            'regle' => 'Signer les attestations dans l\'application reste réservé au propriétaire qui a validé la société en saisissant le code du courrier ; un propriétaire par transmission ou validé par l\'invitation d\'un cabinet signe à la main.',
            'tests' => [
                'tests/Feature/Societe/VerificationSocieteTest.php::test_seul_le_proprietaire_qui_a_saisi_le_code_signe_les_attestations_dans_l_application',
                'tests/Feature/Cabinets/AccepterLInvitationTest.php::test_le_proprietaire_valide_par_une_invitation_signe_les_attestations_a_la_main',
            ],
        ],

        // Société ou magasin suspendu, fermé, résilié ou révoqué.
        'statuts.lecture-seule' => [
            'puce' => 'Société ou magasin suspendu, fermé, résilié ou révoqué',
            'regle' => 'Un magasin ou une société suspendus, fermés, résiliés ou révoqués passent en lecture seule (consultation et téléchargements), sans vente ni e-mail aux clients ; clôtures, statuts, déclarations et preuves d\'exonération continuent.',
            'tests' => [
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_le_magasin_reste_consultable_et_l_annonce',
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_aucune_saisie_n_est_possible',
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_le_pdf_d_une_vente_emise_se_telecharge_mais_ne_s_envoie_plus',
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_la_journee_se_cloture_toujours_a_la_main',
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_le_vendeur_ferme_toujours_sa_caisse',
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_un_envoi_en_file_ne_part_pas_si_la_societe_a_ete_suspendue_entre_temps',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_un_magasin_en_lecture_seule_se_telecharge_et_une_societe_non_validee_n_a_aucun_document_reel',
            ],
        ],
        'statuts.societe-suspendue' => [
            'puce' => 'Société ou magasin suspendu, fermé, résilié ou révoqué',
            'regle' => 'Une société suspendue ou résiliée met tous ses magasins en lecture seule ; son espace d\'administration reste consultable et son équipe gérable.',
            'tests' => [
                'tests/Feature/Filament/EquipeDuMagasinTest.php::test_l_equipe_reste_geree_dans_un_magasin_ferme_ou_une_societe_suspendue_mais_pas_dans_une_societe_revoquee',
                'tests/Feature/Authorization/ControleDesDroitsTest.php::test_dans_l_espace_d_une_societe_suspendue_restent_l_equipe_et_le_cabinet_jusqu_a_la_revocation',
                'tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php::test_aucune_relance_automatique_pour_une_societe_suspendue_ou_non_validee',
                'tests/Feature/Societe/CaissesDeLaSocieteTest.php::test_la_page_reste_consultable_dans_une_societe_en_lecture_seule',
            ],
        ],
        'statuts.fermer-et-rouvrir' => [
            'puce' => 'Société ou magasin suspendu, fermé, résilié ou révoqué',
            'regle' => 'La société ferme et rouvre elle-même un magasin (propriétaire ou administrateur autorisé, mot de passe redemandé), après le contrôle de son établissement au répertoire Sirene, même avant la validation, sans période d\'activité tant qu\'elle n\'est pas validée ; une réouverture ne lève jamais une suspension de l\'éditeur.',
            'tests' => [
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_la_fermeture_exige_l_autorisation_dans_la_portee_et_le_mot_de_passe',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_la_reouverture_d_un_siret_ferme_a_l_insee_est_refusee',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_la_reouverture_attend_la_confirmation_sirene_si_l_insee_ne_repond_pas',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_une_reouverture_ne_leve_jamais_la_suspension_de_l_editeur',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_avant_la_validation_un_magasin_ferme_se_rouvre_sans_periode_d_activite_et_ne_se_cede_pas',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_ni_un_responsable_ni_un_compte_de_cabinet_ne_ferment_ne_rouvrent_ni_ne_cedent_un_magasin',
            ],
        ],
        'statuts.ceder-un-magasin' => [
            'puce' => 'Société ou magasin suspendu, fermé, résilié ou révoqué',
            'regle' => 'Seul le propriétaire cède un magasin (nom retapé, mot de passe redemandé), une fois la société validée, qu\'il soit ouvert ou déjà fermé, sans le rouvrir ni le recontrôler ; un magasin cédé ne se rouvre jamais.',
            'tests' => [
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_seul_le_proprietaire_cede_un_magasin_ferme_en_retapant_son_nom',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_un_magasin_ferme_temporairement_se_cede_sans_etre_rouvert_ni_recontrole_au_repertoire_sirene',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_un_magasin_cede_ne_se_rouvre_pas',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_un_magasin_cede_le_reste_meme_par_une_ecriture_directe',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_un_magasin_ferme_avant_la_validation_se_cede_une_fois_la_societe_validee',
            ],
        ],
        'statuts.jamais-supprimes' => [
            'puce' => 'Société ou magasin suspendu, fermé, résilié ou révoqué',
            'regle' => 'Une société et un magasin ne se suppriment jamais ; un SIRET réservé par un magasin d\'une société validée ne sert jamais à un autre magasin, sauf libération par la procédure de l\'éditeur.',
            'tests' => [
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_un_magasin_ne_se_supprime_jamais_et_la_base_refuse_de_l_effacer',
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_un_siret_reserve_ne_sert_jamais_a_un_autre_magasin',
                'tests/Feature/Editeur/LibererSiretTest.php::test_un_siret_saisi_par_erreur_libere_sert_au_magasin_qui_doit_le_porter',
                'tests/Feature/Editeur/LibererSiretTest.php::test_le_siret_d_un_magasin_ouvert_ne_se_libere_pas_ni_sans_le_code_ni_sans_deux_operateurs',
            ],
        ],

        // Société et établissements.
        'societe.membre-d-un-assujetti-unique' => [
            'puce' => 'Société et établissements',
            'regle' => 'Une société membre d\'un assujetti unique (groupe TVA) le coche dans ses réglages fiscaux communs, changement inscrit au journal d\'audit et au journal des événements de chaque magasin : PILIQI refuse alors toute opération commerciale réelle, avec son message, que l\'« À faire » de chaque magasin réel répète, le magasin de démonstration restant utilisable ; la page d\'inscription le dit (P3.1).',
            'tests' => [
                'tests/Feature/Societe/AssujettiUniqueTest.php::test_la_case_s_inscrit_au_journal_d_audit_et_au_journal_des_evenements_de_chaque_magasin',
                'tests/Feature/Societe/AssujettiUniqueTest.php::test_case_cochee_chaque_operation_commerciale_reelle_est_refusee',
                'tests/Feature/Societe/AssujettiUniqueTest.php::test_case_decochee_la_vente_se_fait',
                'tests/Feature/Societe/AssujettiUniqueTest.php::test_le_magasin_de_demonstration_reste_utilisable',
                'tests/Feature/Societe/AssujettiUniqueTest.php::test_la_page_d_inscription_le_dit_sans_champ',
                'tests/Feature/Societe/AssujettiUniqueTest.php::test_l_a_faire_du_magasin_dit_pourquoi_rien_ne_se_vend',
                'tests/Browser/Parcours/AssujettiUniqueTest.php › le propriétaire déclare dans « Paramètres fiscaux » la société membre d’un assujetti unique, et le vendeur, à qui son « À faire » le dit, ne peut plus émettre sa facture',
            ],
        ],
        'societe.immatriculation-lue-au-registre-national' => [
            'puce' => 'Société et établissements',
            'regle' => 'L\'immatriculation lue au registre national des entreprises est « {registre} {lieuRegistre} » quand les deux sont remplis, sinon le propriétaire la complète (T99).',
            'tests' => [
                'tests/Feature/Integrations/InpiRneTest.php::test_l_identite_d_une_societe_se_lit_dans_la_formalite',
                'tests/Feature/Integrations/InpiRneTest.php::test_sans_le_lieu_du_registre_l_immatriculation_reste_nulle',
            ],
        ],
        'societe.magasins-crees-dans-l-espace-de-la-societe' => [
            'puce' => 'Société et établissements',
            'regle' => 'Une société s\'inscrit sans magasin ; ses magasins se créent dans son espace par le propriétaire ou un administrateur autorisé de tous les magasins, avec le SIREN de la société : on ne saisit que le NIC, et le SIRET, valide, actif et rattaché à ce SIREN au répertoire Sirene, n\'est jamais celui d\'un autre magasin.',
            'tests' => [
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_le_proprietaire_cree_un_magasin_avec_le_siren_de_la_societe',
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_sans_autorisation_aucun_magasin_ne_se_cree',
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_un_societe_id_force_dans_la_requete_est_ignore',
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_un_etablissement_ferme_ou_inconnu_a_l_insee_est_refuse_sans_rien_creer',
                'tests/Feature/Societe/MagasinResourceTest.php::test_seuls_le_proprietaire_et_un_administrateur_de_tous_les_magasins_autorise_creent_un_magasin',
                'tests/Feature/Societe/MagasinResourceTest.php::test_la_creation_se_fait_par_le_nic_apres_le_siren_fixe',
                'tests/Feature/Societe/MagasinResourceTest.php::test_un_siret_reserve_par_une_autre_societe_est_refuse_sans_rien_en_dire',
                'tests/Feature/Societe/IdentiteSocieteTest.php::test_le_siren_ne_se_saisit_dans_aucun_ecran_de_magasin',
            ],
        ],
        'societe.liste-des-etablissements' => [
            'puce' => 'Société et établissements',
            'regle' => 'À la création d\'un magasin, PILIQI propose la liste des établissements actifs du SIREN lue au répertoire Sirene ; choisir un établissement préremplit son NIC, son nom et son adresse et propose un code ; la saisie du NIC reste possible.',
            'tests' => [
                'tests/Feature/Societe/MagasinResourceTest.php::test_la_liste_des_etablissements_actifs_vient_du_repertoire_sirene',
                'tests/Feature/Societe/MagasinResourceTest.php::test_choisir_un_etablissement_preremplit_le_nic_le_nom_l_adresse_et_le_code',
                'tests/Feature/Societe/MagasinResourceTest.php::test_sans_la_liste_du_repertoire_le_nic_se_saisit',
                'tests/Feature/Societe/MagasinResourceTest.php::test_aucune_information_sur_une_autre_societe_n_apparait_dans_la_liste_ni_dans_les_messages',
            ],
        ],
        'societe.identite-et-reglages-communs' => [
            'puce' => 'Société et établissements',
            'regle' => 'La société porte l\'identité légale et les réglages fiscaux communs (régime de TVA, option pour les débits, mois de fin d\'exercice) ; chaque changement d\'un réglage qui touche la conformité s\'inscrit au journal des événements de chacun de ses magasins ; aucun magasin n\'a de privilège, un établissement principal n\'étant qu\'une indication.',
            'tests' => [
                'tests/Feature/Societe/IdentiteSocieteTest.php::test_le_proprietaire_enregistre_l_identite_legale_de_la_societe',
                'tests/Feature/Societe/ParametresFiscauxSocieteTest.php::test_le_regime_de_la_societe_s_applique_a_tous_ses_magasins',
                'tests/Feature/Societe/ParametresFiscauxSocieteTest.php::test_un_changement_de_regime_s_inscrit_au_journal_de_chaque_magasin_avec_l_ancienne_et_la_nouvelle_valeur',
                'tests/Feature/Societe/ParametresFiscauxSocieteTest.php::test_un_parametre_inchange_n_est_pas_journalise',
                'tests/Feature/Societe/IdentiteSocieteTest.php::test_aucun_magasin_n_a_de_privilege_sur_les_reglages',
                'tests/Feature/Societe/MagasinResourceTest.php::test_l_etablissement_principal_n_a_aucun_effet_sur_les_reglages',
            ],
        ],
        'societe.code-du-magasin' => [
            'puce' => 'Société et établissements',
            'regle' => 'Chaque magasin a un code court, unique dans la société, définitif dès son premier document, inséré dans tous ses numéros (FAC-LYON1-2026-000001).',
            'tests' => [
                'tests/Feature/Societe/CreerEtablissementActionTest.php::test_le_code_est_unique_dans_la_societe_mais_libre_dans_une_autre',
                'tests/Feature/Societe/MagasinResourceTest.php::test_le_code_se_verrouille_au_premier_document_numerote',
                'tests/Feature/Societe/MagasinResourceTest.php::test_le_premier_document_d_un_autre_magasin_ne_rend_pas_le_code_definitif',
                'tests/Feature/Facturation/DocumentNumberingServiceTest.php::test_un_numero_de_document_porte_le_code_du_magasin_et_l_annee',
                'tests/Feature/Facturation/DocumentNumberingServiceTest.php::test_deux_magasins_du_meme_siren_ont_chacun_leur_serie_et_des_numeros_distincts',
            ],
        ],
        'societe.retour-dans-le-magasin-qui-a-vendu' => [
            'puce' => 'Société et établissements',
            'regle' => 'Un retour ou un paiement se fait dans le magasin qui a vendu.',
            'tests' => [
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_de_la_meme_societe_ne_fait_pas_d_avoir_sur_une_vente_d_un_autre',
                'tests/Feature/Tenancy/TenantIsolationTest.php::test_un_magasin_de_la_meme_societe_n_encaisse_pas_une_vente_d_un_autre',
            ],
        ],
        'societe.siren-definitif-et-procedure' => [
            'puce' => 'Société et établissements',
            'regle' => 'Le SIREN d\'une société ne change jamais ; une erreur ou une usurpation de SIREN d\'une société validée ne se traite que par la procédure exceptionnelle de l\'éditeur, qui ne modifie ni ne supprime aucun document émis.',
            'tests' => [
                'tests/Feature/Editeur/SirenErroneTest.php::test_l_examen_liste_les_documents_concernes_sans_en_modifier_aucun',
                'tests/Feature/Editeur/SirenErroneTest.php::test_la_correction_exige_un_examen_concluant_a_une_erreur_et_deux_operateurs',
                'tests/Feature/Editeur/SirenErroneTest.php::test_aucun_document_emis_ne_change',
                'tests/Feature/Editeur/SirenErroneTest.php::test_la_correction_journalise_dans_chaque_magasin_et_garde_les_anciens_siret_reserves',
                'tests/Feature/Editeur/RevoquerSocieteTest.php::test_la_revocation_libere_le_siren_et_les_siret_sans_toucher_aux_documents',
            ],
        ],
        'societe.regularisation-apres-correction-du-siren' => [
            'puce' => 'Société et établissements',
            'regle' => 'Après une correction du SIREN, l\'avoir de régularisation reprend l\'émetteur figé de la facture d\'origine, avec sa mention, la facture rectificative porte le nouveau SIREN et est émise par un compte autorisé à vendre, jamais par le propriétaire ; aucun mouvement de stock ni encaissement compté deux fois.',
            'tests' => [
                'tests/Feature/Facturation/RegularisationDUneFactureTest.php::test_l_avoir_de_regularisation_porte_l_emetteur_choisi_par_l_editeur',
                'tests/Feature/Facturation/RegularisationDUneFactureTest.php::test_la_regularisation_ne_fait_aucun_mouvement_de_stock',
                'tests/Feature/Facturation/RegularisationDUneFactureTest.php::test_la_regularisation_ne_fait_jamais_payer_deux_fois',
                'tests/Feature/Facturation/RegularisationDUneFactureTest.php::test_une_regularisation_ne_declare_aucun_encaissement_supplementaire',
                'tests/Feature/Facturation/RegularisationDUneFactureTest.php::test_le_proprietaire_fait_l_avoir_de_regularisation_mais_jamais_la_facture_rectificative',
            ],
        ],
        'societe.changement-de-siret' => [
            'puce' => 'Société et établissements',
            'regle' => 'Un changement de SIRET (déménagement) se fait par la société, contrôlé au répertoire Sirene, prend effet le jour même, garde le même magasin et son historique, et l\'ancien SIRET reste réservé ; un déménagement qui change le territoire passe par un nouveau magasin.',
            'tests' => [
                'tests/Feature/Societe/MagasinResourceTest.php::test_changer_le_siret_exige_sirene_garde_le_magasin_et_reserve_l_ancien',
                'tests/Feature/Societe/MagasinResourceTest.php::test_un_changement_de_siret_s_inscrit_au_jet_270_et_les_documents_emis_gardent_l_ancien',
                'tests/Feature/Societe/MagasinResourceTest.php::test_un_changement_d_adresse_qui_change_le_territoire_de_tva_est_refuse',
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_un_nouvel_etablissement_d_un_autre_territoire_est_refuse_meme_au_meme_taux',
            ],
        ],
        'societe.cession-de-l-activite' => [
            'puce' => 'Société et établissements',
            'regle' => 'Le magasin cédé est fermé pour cession, son stock sort au coût moyen, journalisé, et cette valeur hors taxe est remise au propriétaire ; ni les factures, ni les paiements, ni les archives ne bougent.',
            'tests' => [
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_une_fermeture_pour_cession_sort_le_stock_au_cout_moyen_et_remet_la_valeur_hors_taxe',
                'tests/Feature/Editeur/AnnulerCessionTest.php::test_une_cession_annulee_rentre_le_stock_au_meme_cout_et_seulement_sans_operation_posterieure',
            ],
        ],

        // Vérification de la société.
        'verification.siren-unique-et-inscription' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Un SIREN n\'appartient qu\'à une seule société dans tout PILIQI ; une société s\'inscrit avec son SIREN, son type d\'activité et l\'adresse e-mail et le mot de passe de son propriétaire, rien de plus.',
            'tests' => [
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_la_demande_ne_demande_que_le_siren_le_type_d_activite_l_adresse_et_le_mot_de_passe',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_la_base_refuse_une_seconde_societe_du_meme_siren_tant_que_la_premiere_le_garde',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_une_seule_des_deux_demandes_du_meme_siren_devient_une_inscription',
                'tests/Feature/Societe/InscriptionConcurrenceTest.php::test_deux_inscriptions_simultanees_du_meme_siren_n_en_creent_qu_une',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_un_siren_a_cle_invalide_ou_fait_de_zeros_est_refuse',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_l_ecran_repond_pareil_que_l_adresse_soit_libre_ou_non',
            ],
        ],
        'verification.courrier-recommande' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Une fois l\'adresse confirmée, PILIQI contrôle au répertoire Sirene que l\'entreprise existe et est active, lit à l\'INPI le représentant légal et l\'adresse officielle du siège, et y envoie une lettre recommandée au nom du représentant légal, avec un code unique, à usage unique, valable 30 jours, et un QR code qui ouvre la page où le saisir.',
            'tests' => [
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_la_confirmation_cree_la_societe_qui_attend_son_code_et_envoie_le_courrier',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_une_entreprise_cessee_ou_un_numero_inconnu_est_refuse',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_le_courrier_part_au_representant_legal_du_registre_a_l_adresse_officielle_du_siege',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_une_personne_morale_dirigeante_recoit_le_courrier_a_l_attention_de_son_representant',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_le_courrier_est_une_page_a4_avec_le_code_l_adresse_courte_et_un_qr_code_qui_n_ouvre_que_la_page',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_le_code_est_hache_unique_valable_trente_jours_et_a_usage_unique',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_la_page_du_code_s_ouvre_par_le_qr_code_avec_le_siren_rempli_et_connecte_le_proprietaire',
            ],
        ],
        'verification.saisie-du-code' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Saisir le code valide la société qui s\'inscrit elle-même, seule preuve pour elle ; la personne qui le saisit devient propriétaire ; si ce n\'est pas la personne inscrite, tous les comptes créés avant sont retirés et l\'adresse qu\'elle choisit ne sert qu\'une fois confirmée.',
            'tests' => [
                'tests/Feature/Societe/VerificationSocieteTest.php::test_la_personne_inscrite_qui_saisit_le_code_valide_la_societe_et_garde_ses_comptes',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_une_autre_personne_qui_saisit_le_code_devient_proprietaire_et_les_comptes_d_avant_sont_retires',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_une_autre_personne_choisit_un_mot_de_passe_conforme_et_une_adresse_libre',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_un_siren_inconnu_sans_courrier_ou_mal_forme_recoit_la_meme_reponse_qu_un_code_faux',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_la_page_du_code_limite_les_saisies_par_adresse_ip',
                'tests/Feature/Societe/ValiderLaSocieteConcurrenceTest.php::test_deux_saisies_simultanees_du_bon_code_ne_valident_qu_une_fois',
            ],
        ],
        'verification.inscription-close-sans-code' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Sans code au bout de 30 jours après son dernier courrier, ou par la procédure de l\'éditeur, l\'inscription est close, le SIREN libéré et l\'adresse de son propriétaire cesse d\'être une adresse de connexion ; un seul nouveau courrier peut être demandé, au plus tôt 15 jours après le premier, puis l\'éditeur traite le cas sur pièces, effacées dès sa décision.',
            'tests' => [
                'tests/Feature/Societe/VerificationSocieteTest.php::test_sans_code_trente_jours_apres_le_courrier_l_inscription_se_clot_et_libere_le_siren',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_un_nouveau_courrier_repousse_la_cloture_a_trente_jours_apres_lui',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_un_seul_nouveau_courrier_au_plus_tot_quinze_jours_apres_le_premier',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_les_pieces_ne_se_deposent_qu_apres_le_nouveau_courrier',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_les_pieces_deposees_sont_chiffrees_et_effacees_a_la_decision',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_le_refus_de_l_editeur_clot_l_inscription_efface_les_pieces_et_libere_le_siren',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_l_adresse_d_une_inscription_close_recoit_le_lien_d_une_nouvelle_inscription',
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_la_cloture_sans_code_retire_l_adresse_de_connexion_et_le_compte_s_ouvre_par_son_identifiant',
                'tests/Feature/Societe/AdresseDeConnexionDuProprietaireTest.php::test_la_cloture_previent_par_e_mail_l_adresse_retiree_sans_dire_l_identifiant',
            ],
        ],
        'verification.limite-des-courriers' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Un courrier ne part qu\'après la confirmation de l\'adresse, une seule fois par SIREN tant qu\'une inscription attend, pour une entreprise active, dans la limite d\'inscriptions permise par adresse IP.',
            'tests' => [
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_au_dela_de_cinq_courriers_par_jour_depuis_une_adresse_ip_la_confirmation_attend',
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_un_tiers_ne_sature_pas_le_siren_d_une_entreprise',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_un_nouveau_courrier_ne_part_que_pour_une_entreprise_encore_active_au_repertoire',
            ],
        ],
        'verification.avant-la-validation' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Avant la validation, aucune opération commerciale réelle (ticket, facture, avoir, paiement, devis numéroté, PDF, e-mail à un client, transmission) : la société se configure (identité, magasins, équipe, catalogue, achats).',
            'tests' => [
                'tests/Feature/Societe/PorteDeValidationTest.php::test_une_societe_a_verifier_n_emet_ni_ticket_ni_facture_ni_avoir_ni_devis_numerote',
                'tests/Feature/Societe/PorteDeValidationTest.php::test_une_societe_a_verifier_n_enregistre_aucun_paiement_et_n_ecrit_a_aucun_client',
                'tests/Feature/Societe/PorteDeValidationTest.php::test_une_societe_a_verifier_se_configure_catalogue_equipe_parametres_et_achats',
                'tests/Feature/Societe/PorteDeValidationTest.php::test_le_registre_refuse_toute_vente_d_un_magasin_dont_la_societe_n_est_pas_validee',
                'tests/Feature/Societe/PorteDeValidationTest.php::test_aucun_pdf_d_un_magasin_reel_ne_se_telecharge_avant_la_validation',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_la_societe_se_valide_sans_aucun_magasin_et_ne_vend_rien_avant_son_premier_magasin',
            ],
        ],
        'verification.magasin-de-demonstration' => [
            'puce' => 'Vérification de la société',
            'regle' => 'La société s\'essaie dans un magasin de démonstration : identité fictive, territoire de son siège, documents marqués « Démonstration — sans valeur », numéros et registre à lui, entrée et sortie au journal des événements, aucun e-mail ni transmission, jamais facturé ; il se ferme à la validation et rien n\'en passe aux magasins réels.',
            'tests' => [
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_le_proprietaire_ouvre_une_seule_demonstration_avant_la_validation',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_ses_documents_portent_une_identite_fictive_et_le_filigrane',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_ses_numeros_portent_le_code_demo_et_ne_touchent_aucune_serie_reelle',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_l_ouverture_et_la_fin_de_la_demonstration_s_inscrivent_en_jet_100_et_105',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_aucun_e_mail_ni_aucune_transmission_ne_part_de_la_demonstration',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_rien_ne_passe_du_magasin_de_demonstration_aux_magasins_reels',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_la_validation_ferme_la_demonstration_qui_reste_consultable',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_la_demonstration_prend_le_territoire_du_siege_et_le_garde',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_la_demonstration_vend_encaisse_et_cloture_dans_son_propre_registre',
            ],
        ],
        'verification.controle-sirene-et-territoire' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Chaque nouveau magasin, changement de SIRET ou réouverture est contrôlé au répertoire Sirene avant toute vente ; le premier contrôle concluant fixe le territoire, lu à la commune de l\'établissement, qui ne change plus ; une revérification qui lit un autre territoire alerte sans rien changer ; l\'éditeur corrige un territoire mal lu tant qu\'aucun document n\'est numéroté.',
            'tests' => [
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_la_creation_fixe_le_territoire_lu_a_la_commune_de_l_etablissement',
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_un_magasin_cree_quand_l_insee_ne_repond_pas_ne_numerote_rien',
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_la_base_et_le_modele_refusent_de_changer_un_territoire_fixe',
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_une_reverification_qui_lit_un_autre_territoire_alerte_sans_rien_changer',
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_l_editeur_corrige_un_territoire_mal_lu_avant_le_premier_document_numerote_et_jamais_apres',
                'tests/Feature/Societe/TerritoireDuMagasinTest.php::test_apres_un_document_numerote_le_siret_libere_par_l_editeur_sert_a_un_nouveau_magasin',
                'tests/Feature/Societe/PorteDeValidationTest.php::test_un_magasin_dont_l_etablissement_n_est_pas_verifie_ne_vend_pas',
            ],
        ],
        'verification.reverification-et-ecarts' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Une revérification négative après la validation alerte le propriétaire et l\'éditeur sans rien bloquer ; chaque écart relevé reste, une seule fois, dans l\'« À faire » de l\'espace de la société jusqu\'à « C\'est vérifié », geste inscrit au journal d\'audit.',
            'tests' => [
                'tests/Feature/Societe/VerificationSocieteTest.php::test_une_reverification_negative_n_arrete_ni_les_ventes_ni_les_paiements',
                'tests/Feature/Societe/ReverifierSireneTest.php::test_une_adresse_ou_une_categorie_differente_se_signale_au_proprietaire_et_a_l_editeur',
                'tests/Feature/Societe/AFaireDeLEspaceDeLaSocieteTest.php::test_un_ecart_dit_verifie_ne_revient_pas_a_la_reverification_suivante_qui_relit_le_meme',
                'tests/Feature/Societe/AFaireDeLEspaceDeLaSocieteTest.php::test_l_ecart_au_repertoire_sirene_reste_jusqu_a_c_est_verifie_par_qui_gere_ce_magasin',
            ],
        ],

        // Type d'activité.
        'type.choix-a-l-inscription' => [
            'puce' => 'Type d\'activité',
            'regle' => 'À l\'inscription, un menu sans valeur par défaut fait choisir le type d\'activité, dans l\'ordre « Commerce ou atelier », « Services, sans vente d\'articles », « Cabinet d\'expertise comptable » ; le choix se confirme sur la page du lien, où il peut encore changer, avec une case qui dit qu\'il sera définitif.',
            'tests' => [
                'tests/Feature/Societe/TypeDActiviteTest.php::test_le_menu_propose_les_trois_types_dans_l_ordre_sans_valeur_par_defaut',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_la_demande_sans_type_ou_avec_un_type_inconnu_est_refusee',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_la_page_du_lien_rappelle_le_type_et_permet_de_le_changer_sans_nouvel_e_mail',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_la_confirmation_sans_la_case_du_choix_definitif_ne_cree_rien',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_la_societe_nait_avec_le_type_confirme_et_societe_inscrite_retient_le_type_et_le_code',
            ],
        ],
        'type.definitif' => [
            'puce' => 'Type d\'activité',
            'regle' => 'Le type devient définitif à la création de la société : aucun écran ni aucune procédure ne le change et la base refuse toute modification ; une inscription faite avec un mauvais type se clôt, et la même personne se réinscrit sous un autre compte.',
            'tests' => [
                'tests/Feature/Authorization/GardesDesTypesDActiviteTest.php::test_la_base_refuse_un_type_inconnu_et_toute_modification_du_type',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_la_verification_et_l_identite_rappellent_le_type_sans_permettre_de_le_changer',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_apres_une_cloture_pour_type_errone_la_meme_personne_se_reinscrit_avec_le_meme_siren_la_meme_adresse_et_un_autre_type',
            ],
        ],
        'type.code-d-activite' => [
            'puce' => 'Type d\'activité',
            'regle' => 'Le code d\'activité lu au répertoire Sirene ne bloque que le cabinet (69.20Z, un code absent ou d\'une autre nomenclature étant contrôlé par l\'éditeur) ; pour les autres types, il n\'affiche que des avertissements.',
            'tests' => [
                'tests/Feature/Societe/TypeDActiviteTest.php::test_un_cabinet_hors_69_20z_est_refuse_au_formulaire_et_a_la_confirmation_sans_courrier_et_le_siren_reste_libre',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_un_cabinet_sans_code_ou_d_une_autre_nomenclature_n_est_pas_refuse',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_les_avertissements_du_code_d_activite_ne_bloquent_jamais',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_aucun_avertissement_pour_un_code_absent_ou_d_une_autre_nomenclature',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_le_rappel_du_choix_definitif_prend_en_charge_la_franchise_en_base_et_garde_le_refus_des_activites_exonerees',
            ],
        ],
        'type.familles-d-autorisations' => [
            'puce' => 'Type d\'activité',
            'regle' => 'Les autorisations de magasin se rangent en deux familles, « articles » et « commune », portées par le référentiel en base ; dans une société de services, les autorisations de la famille « articles » n\'existent pas.',
            'tests' => [
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_chaque_autorisation_est_dans_une_seule_famille_et_le_referentiel_en_base_porte_la_sienne',
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_sans_articles_aucun_role_n_a_d_autorisation_d_articles',
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_les_cases_proposees_cochables_et_precochees_sont_celles_du_type',
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_la_base_refuse_une_case_d_articles_dans_une_societe_sans_articles',
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_les_services_se_voient_se_creent_et_se_modifient_par_leurs_propres_autorisations',
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_le_commerce_garde_toutes_les_autorisations_d_aujourd_hui',
            ],
        ],
        'type.societe-de-services' => [
            'puce' => 'Type d\'activité',
            'regle' => 'Une société de services n\'a ni produits, ni stock, ni achats de marchandises, ni avoirs fournisseur, ni inventaire d\'ouverture, ni barème, ni vente sans stock, ni écran des fournisseurs, ni natures d\'opération qui portent sur des biens ; la base refuse ses produits, factures d\'achat, avoirs fournisseur et inventaires d\'ouverture.',
            'tests' => [
                'tests/Feature/Authorization/GardesDesTypesDActiviteTest.php::test_la_base_refuse_produit_facture_d_achat_avoir_fournisseur_et_inventaire_sans_articles',
                'tests/Feature/Filament/EcransSelonLeTypeTest.php::test_sans_articles_la_navigation_n_a_ni_produits_ni_achats_ni_fournisseurs_ni_inventaire_ni_prix_de_vente',
                'tests/Feature/Filament/EcransSelonLeTypeTest.php::test_sans_articles_les_adresses_des_ecrans_d_articles_repondent_403',
                'tests/Feature/Filament/EcransSelonLeTypeTest.php::test_sans_articles_la_caisse_les_devis_et_les_factures_n_ajoutent_que_des_services',
                'tests/Feature/Filament/EcransSelonLeTypeTest.php::test_sans_articles_seules_les_natures_sans_biens_sont_proposees_et_acceptees_au_brouillon',
                'tests/Feature/Filament/EcransSelonLeTypeTest.php::test_sans_articles_les_apercus_de_stock_n_apparaissent_pas',
            ],
        ],
        'type.cabinet-sans-magasin' => [
            'puce' => 'Type d\'activité',
            'regle' => 'Un cabinet d\'expertise comptable n\'a ni magasin ni démonstration et ne configure que son identité et ses collaborateurs ; la base refuse un magasin dans un cabinet.',
            'tests' => [
                'tests/Feature/Societe/TypeDActiviteTest.php::test_un_cabinet_n_a_jamais_de_magasin_ni_de_demonstration',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_un_cabinet_ne_configure_que_son_identite_et_ses_collaborateurs_ni_equipe_de_magasin_ni_reglages_fiscaux',
                'tests/Feature/Authorization/GardesDesTypesDActiviteTest.php::test_la_base_refuse_un_magasin_dans_un_cabinet',
                'tests/Feature/Societe/TypeDActiviteTest.php::test_les_textes_de_la_verification_ne_parlent_a_un_cabinet_ni_de_magasins_ni_de_demonstration',
            ],
        ],
        'type.lu-pour-la-societe-courante' => [
            'puce' => 'Type d\'activité',
            'regle' => 'Le type ne se lit que pour la société courante ou celle du magasin courant, hors les lectures d\'un cabinet listées dans « Produit » ; le type d\'une société ne change rien dans une autre.',
            'tests' => [
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_le_type_d_une_societe_ne_change_rien_dans_une_autre',
                'tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php::test_le_jet_130_ne_liste_que_les_autorisations_du_type',
            ],
        ],

        // Cabinets d'expertise comptable.
        'cabinets.fiche-de-fournisseur-dans-un-magasin-ouvert' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Une fiche de fournisseur nomme qui l\'a créée et qui l\'a modifiée en dernier ; la base refuse qu\'un compte d\'un cabinet lié ou un expert-comptable de la société en écrive une dans un magasin que le client ne lui ouvre pas.',
            'tests' => [
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_la_base_refuse_une_fiche_de_fournisseur_ecrite_dans_un_magasin_non_choisi',
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_chaque_table_ou_un_compte_agit_dans_un_magasin_porte_la_garde_des_magasins_ouverts',
            ],
        ],
        'cabinets.inscription-et-verification' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Un cabinet s\'inscrit et se valide comme toute société qui s\'inscrit elle-même, avec le code d\'activité 69.20Z contrôlé avant tout courrier, et son inscription au tableau de l\'Ordre vérifiée par l\'annuaire de l\'Ordre ou, à défaut, une fois par l\'éditeur ; son propriétaire arrive dans l\'espace du cabinet, sur sa liste d\'étapes.',
            'tests' => [
                'tests/Feature/Cabinets/CabinetInscriptionTest.php::test_un_cabinet_inscrit_a_sa_ligne_cabinets_avec_le_code_lu_et_son_activite_controlee',
                'tests/Feature/Cabinets/CabinetInscriptionTest.php::test_une_nomenclature_inconnue_laisse_l_activite_a_controler_par_l_editeur',
                'tests/Feature/Cabinets/CabinetInscriptionTest.php::test_le_proprietaire_d_un_cabinet_arrive_dans_l_espace_du_cabinet_et_jamais_dans_l_espace_societe',
                'tests/Feature/Cabinets/CabinetInscriptionTest.php::test_la_liste_d_etapes_du_cabinet_ne_parle_ni_de_magasins_ni_de_demonstration',
                'tests/Feature/Cabinets/VerificationOrdreTest.php::test_avec_l_annuaire_automatique_l_ordre_se_verifie_au_meme_siren',
                'tests/Feature/Cabinets/VerificationOrdreTest.php::test_une_inscription_absente_ou_radiee_de_l_annuaire_est_refusee_sans_rien_verifier',
                'tests/Feature/Cabinets/VerificationOrdreTest.php::test_sans_annuaire_automatique_la_demande_alerte_l_editeur_et_attend_sa_procedure',
            ],
        ],
        'cabinets.rien-avant-d-etre-verifie-et-actif' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Tant que le cabinet n\'est pas validé, contrôlé 69.20Z, vérifié à l\'Ordre et actif, il n\'invite, ne lie, ne voit ni n\'ouvre aucun client, son propriétaire pouvant seulement mettre fin à ses liens et retirer ses invitations et demandes ouvertes ; tant qu\'il est suspendu, aucun client n\'accepte son invitation ni sa demande, qu\'il peut seulement refuser.',
            'tests' => [
                'tests/Feature/Cabinets/VerificationOrdreTest.php::test_tant_que_le_cabinet_n_est_pas_verifie_il_n_invite_ni_ne_lie_aucun_client',
                'tests/Feature/Cabinets/ClientsConfiesTest.php::test_un_cabinet_qui_n_est_plus_verifie_ne_confie_aucun_client',
                'tests/Feature/Cabinets/InvitationsTest.php::test_l_invitation_ne_part_pas_si_le_cabinet_n_est_plus_verifie_au_moment_de_l_envoi',
                'tests/Feature/Cabinets/InvitationsTest.php::test_un_cabinet_suspendu_ou_resilie_retire_ses_invitations_et_ses_demandes_ouvertes',
                'tests/Feature/Cabinets/FinDuLienTest.php::test_un_cabinet_suspendu_met_fin_a_un_lien_mais_ne_modifie_plus_son_identite',
                'tests/Feature/Cabinets/CabinetSuspenduTest.php::test_l_invitation_d_un_cabinet_suspendu_ne_s_accepte_pas_mais_se_refuse',
                'tests/Feature/Cabinets/CabinetSuspenduTest.php::test_la_demande_de_lien_d_un_cabinet_suspendu_ne_s_accepte_pas_mais_se_refuse',
                'tests/Feature/Cabinets/CabinetSuspenduTest.php::test_un_cabinet_suspendu_n_invite_ni_n_accepte_aucune_demande',
            ],
        ],
        'cabinets.correction-revocation-radiation' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Une correction du SIREN d\'un cabinet remet à faire le contrôle 69.20Z et la vérification à l\'Ordre ; sa révocation, ou sa radiation de l\'Ordre constatée par l\'éditeur, met fin à tous ses liens, clients et cabinet prévenus.',
            'tests' => [
                'tests/Feature/Editeur/SirenErroneTest.php::test_le_siren_d_un_cabinet_se_corrige_et_remet_a_faire_son_controle_69_20z_et_sa_verification_a_l_ordre',
                'tests/Feature/Editeur/RevoquerSocieteTest.php::test_la_revocation_d_un_cabinet_met_fin_a_tous_ses_liens_et_previent_clients_et_cabinet',
                'tests/Feature/Editeur/RevoquerSocieteTest.php::test_la_revocation_d_un_cabinet_retire_ses_demandes_et_ses_invitations_ouvertes',
                'tests/Feature/Editeur/ProceduresTest.php::test_la_radiation_d_un_cabinet_constatee_par_l_editeur_met_fin_a_tous_ses_liens',
            ],
        ],
        'cabinets.collaborateurs' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Un cabinet n\'a ni magasin, ni démonstration, ni administrateur, ni membre : son propriétaire, qui voit tous ses clients, et ses collaborateurs, dont il crée, suspend, réactive, retire et réintègre seul les comptes et redonne l\'accès, en présence du collaborateur, et à qui il confie chaque client lié ; sa propriété se transmet à un collaborateur actif.',
            'tests' => [
                'tests/Feature/Cabinets/CollaborateursTest.php::test_seul_le_proprietaire_du_cabinet_cree_suspend_reactive_retire_et_reintegre_un_collaborateur',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_un_collaborateur_ne_peut_pas_se_connecter_avec_son_identifiant_faute_de_poste_reconnu',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_seul_le_proprietaire_du_cabinet_redonne_l_acces_a_un_collaborateur_et_seulement_en_sa_presence',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_la_base_refuse_un_collaborateur_hors_d_un_cabinet_et_un_administrateur_ou_un_membre_dans_un_cabinet',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_la_propriete_d_un_cabinet_se_transmet_a_un_collaborateur_qui_suit_le_lien',
                'tests/Feature/Cabinets/ClientsConfiesTest.php::test_le_proprietaire_du_cabinet_confie_et_retire_un_client_et_l_acces_suit_aussitot',
                'tests/Feature/Cabinets/ClientsConfiesTest.php::test_un_collaborateur_ne_confie_rien',
            ],
        ],
        'cabinets.comptes-du-cabinet-chez-le-client' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Un compte d\'un cabinet n\'est jamais membre de la société d\'un client, n\'y a ni affectation, ni case, ni autorisation de société, et n\'entre chez un client que par le lien de son cabinet ; un collaborateur ne voit que les clients qui lui sont confiés, et y ouvre chaque magasin réel comme s\'il y était connecté, selon les autorisations du client.',
            'tests' => [
                'tests/Feature/Cabinets/CollaborateursTest.php::test_la_base_refuse_une_affectation_ou_une_autorisation_de_societe_a_un_collaborateur',
                'tests/Feature/Cabinets/CollaborateursTest.php::test_un_collaborateur_n_ouvre_jamais_l_espace_societe_et_ne_change_jamais_de_role',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_un_collaborateur_ouvre_les_magasins_reels_des_clients_confies_comme_s_il_y_etait_connecte',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_un_collaborateur_ne_voit_pas_un_client_qui_ne_lui_est_pas_confie_et_le_proprietaire_du_cabinet_les_voit_tous',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_un_collaborateur_n_ouvre_ni_l_espace_de_la_societe_du_client_ni_sa_demonstration_ni_sa_caisse',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_un_collaborateur_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur_du_client',
            ],
        ],
        'cabinets.espace-du-cabinet' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'L\'espace du cabinet est un panneau à lui : la liste de ses clients liés, un tableau de bord qui les réunit dans la limite de ce que chacun autorise, et l\'accès à chaque client.',
            'tests' => [
                'tests/Feature/Cabinets/PanneauCabinetTest.php::test_chaque_page_et_ressource_du_panneau_cabinet_declare_son_acces_et_a_une_policy',
                'tests/Feature/Cabinets/PanneauCabinetTest.php::test_un_compte_d_une_societe_qui_n_est_pas_un_cabinet_n_ouvre_jamais_le_panneau_cabinet',
                'tests/Feature/Cabinets/PanneauCabinetTest.php::test_un_collaborateur_voit_les_pages_du_cabinet_sans_les_actions_du_proprietaire',
                'tests/Feature/Cabinets/TableauDeBordDuCabinetTest.php::test_le_tableau_de_bord_reunit_les_clients_ouverts_au_compte_et_rien_d_autre',
                'tests/Feature/Cabinets/TableauDeBordDuCabinetTest.php::test_les_factures_a_encaisser_ne_se_montrent_que_pour_les_clients_qui_accordent_les_factures_de_toute_l_equipe',
                'tests/Feature/Cabinets/TableauDeBordDuCabinetTest.php::test_les_totaux_se_calculent_en_centimes_sans_nom_de_client_ni_numero_de_document',
                'tests/Feature/Cabinets/TableauDeBordDuCabinetTest.php::test_la_demonstration_d_un_client_n_entre_jamais_dans_les_totaux',
            ],
        ],
        'cabinets.invitation-d-un-siren' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Seul le propriétaire du cabinet invite, uniquement par le SIREN du client et l\'adresse à laquelle part le lien, en redonnant son mot de passe ; l\'e-mail nomme le cabinet ; une invitation sans réponse expire après 7 jours et se retire tant qu\'elle n\'est pas acceptée ; un SIREN inscrit comme cabinet est refusé (« Ce SIREN ne peut pas être invité par votre cabinet. »).',
            'tests' => [
                'tests/Feature/Cabinets/InvitationsTest.php::test_le_proprietaire_du_cabinet_invite_un_siren_libre_et_actif_en_redonnant_son_mot_de_passe',
                'tests/Feature/Cabinets/InvitationsTest.php::test_un_mot_de_passe_faux_n_envoie_aucune_invitation',
                'tests/Feature/Cabinets/InvitationsTest.php::test_le_cabinet_n_invite_que_par_un_siren',
                'tests/Feature/Cabinets/InvitationsTest.php::test_l_e_mail_d_invitation_nomme_le_cabinet_et_le_siren_invite',
                'tests/Feature/Cabinets/InvitationsTest.php::test_une_invitation_expire_apres_sept_jours',
                'tests/Feature/Cabinets/InvitationsTest.php::test_le_cabinet_retire_une_invitation_ouverte_et_son_lien_ne_vaut_plus',
                'tests/Feature/Cabinets/InvitationsTest.php::test_un_siren_tenu_par_un_cabinet_est_refuse_par_un_message_neutre',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_seul_le_proprietaire_du_cabinet_voit_et_retire_ses_invitations',
            ],
        ],
        'cabinets.invitation-d-une-entreprise-non-inscrite' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Une entreprise pas encore inscrite est contrôlée au répertoire Sirene à l\'invitation puis à l\'acceptation ; en acceptant, la personne choisit son mot de passe et le type d\'activité, jamais « Cabinet d\'expertise comptable », et devient propriétaire de la société, validée sans courrier, la preuve étant l\'invitation ; les données légales sont lues à l\'INPI, ou à défaut au répertoire Sirene.',
            'tests' => [
                'tests/Feature/Cabinets/InvitationsTest.php::test_une_entreprise_cessee_ou_inconnue_n_est_pas_invitee',
                'tests/Feature/Cabinets/AccepterLInvitationTest.php::test_accepter_l_invitation_cree_la_societe_validee_sans_courrier_avec_la_preuve_de_l_invitation_du_cabinet',
                'tests/Feature/Cabinets/AccepterLInvitationTest.php::test_l_identite_de_la_societe_est_lue_au_registre_national_a_l_acceptation',
                'tests/Feature/Cabinets/AccepterLInvitationTest.php::test_un_siren_inconnu_du_registre_ou_sans_representant_legal_s_accepte_avec_l_identite_du_repertoire_sirene',
                'tests/Feature/Cabinets/AccepterLInvitationTest.php::test_un_registre_national_injoignable_refuse_l_acceptation_sans_rien_creer_et_l_invitation_reste_ouverte',
                'tests/Feature/Cabinets/AccepterLInvitationTest.php::test_l_acceptation_sans_type_avec_le_type_cabinet_ou_sans_la_case_ne_cree_rien',
            ],
        ],
        'cabinets.societe-deja-inscrite' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Quand le SIREN invité est déjà pris, l\'invitation devient une demande de rattachement, dont la page montre le cabinet et ses choix (accepter par défaut, en ajustant, ou refuser) ; tant que le lien n\'est pas actif, le cabinet ne connaît de cette société que le SIREN qu\'il a saisi ; le propriétaire d\'une société peut aussi désigner un cabinet vérifié, cherché par SIREN ou par nom, et le lien naît quand le cabinet accepte ; une demande sans réponse expire après 7 jours.',
            'tests' => [
                'tests/Feature/Cabinets/InvitationsTest.php::test_un_siren_pris_envoie_a_l_adresse_saisie_une_demande_de_rattachement_qui_nomme_le_cabinet',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_la_page_de_rattachement_accepte_avec_les_autorisations_par_defaut_ou_ajustees_ou_refuse',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_le_proprietaire_d_une_societe_trouve_un_cabinet_verifie_par_siren_ou_par_nom_sans_casse_ni_accents',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_la_recherche_ne_montre_ni_un_cabinet_non_verifie_ni_une_societe_qui_n_est_pas_un_cabinet_et_se_limite_a_vingt',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_designer_un_cabinet_envoie_la_demande_et_le_lien_nait_quand_le_cabinet_accepte',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_la_page_de_rattachement_d_une_autre_societe_ou_d_une_demande_close_est_neutre',
                'tests/Feature/Cabinets/CabinetsConcurrenceTest.php::test_une_invitation_d_un_siren_pris_et_une_designation_simultanees_ne_creent_qu_une_demande',
            ],
        ],
        'cabinets.rattachement-sans-validation' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Un rattachement ne valide jamais une société qui attend son code ; le lien et les demandes d\'une société non validée prennent fin quand son inscription est close ou qu\'une autre personne saisit le code, et le cabinet en est prévenu.',
            'tests' => [
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_un_rattachement_ne_valide_jamais_une_societe_qui_attend_son_code',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_un_lien_prend_fin_quand_une_autre_personne_saisit_le_code_du_courrier',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_un_lien_prend_fin_quand_l_inscription_est_close_sans_validation',
                'tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php::test_la_cloture_d_une_inscription_previent_le_cabinet_de_la_fin_de_sa_demande_sans_le_motif',
            ],
        ],
        'cabinets.autorisations-accordees-par-le-client' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Par défaut, le cabinet a, dans chacun des magasins réels du client, présents et futurs, toutes les autorisations de consultation, jamais le journal d\'audit ni les traces ; le propriétaire du client en retire et, pour un client « Commerce ou atelier », en ajoute parmi celles des achats et du stock, jamais celles de la vente, de la gestion des comptes, des réglages ou de la caisse ; « voir les factures » et « voir les clôtures » sont une seule case.',
            'tests' => [
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_par_defaut_le_cabinet_a_les_consultations_dans_chaque_magasin_reel_du_client',
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_le_cabinet_lit_un_magasin_reel_cree_apres_le_lien_et_jamais_la_demonstration',
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_le_proprietaire_du_client_retire_des_consultations_et_ajoute_des_autorisations_d_achat_et_de_stock',
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_voir_les_factures_ou_les_clotures_accorde_toutes_celles_du_magasin_et_jamais_l_une_sans_l_autre',
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_aucune_autorisation_de_vente_de_compte_de_reglage_ni_de_caisse_ne_s_accorde_au_cabinet',
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_un_client_de_services_ne_propose_ni_n_accorde_aucune_autorisation_d_articles',
                'tests/Feature/Cabinets/AutorisationsDuCabinetTest.php::test_chaque_reglage_des_autorisations_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_deux_journaux',
                'tests/Feature/Authorization/GardesDesCabinetsEnBaseTest.php::test_la_base_refuse_une_autorisation_hors_du_cabinet_ou_sur_un_lien_ni_actif_ni_declare',
                'tests/Feature/Authorization/GardesDesCabinetsEnBaseTest.php::test_la_base_refuse_une_autorisation_d_articles_pour_un_client_sans_articles',
            ],
        ],
        'cabinets.magasins-choisis' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Le propriétaire du client choisit les magasins réels que voit son cabinet ou son expert-comptable déclaré : tous, présents et futurs, ou une sélection d\'au moins un magasin, avec une seule liste de cases ; un magasin non choisi n\'est ni listé, ni ouvert, ni compté, et chaque changement s\'inscrit aux journaux et au JET 130.',
            'tests' => [
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_un_magasin_non_choisi_n_est_ni_liste_ni_ouvert_et_tous_les_magasins_couvre_les_futurs',
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_le_tableau_de_bord_et_la_fiche_du_client_ignorent_un_magasin_non_choisi',
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_retirer_un_magasin_ferme_son_acces_et_s_inscrit_au_jet_130_et_aux_deux_journaux',
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_une_selection_vide_un_magasin_d_une_autre_societe_ou_la_demonstration_sont_refuses',
                'tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php::test_la_base_refuse_une_autorisation_du_cabinet_utilisee_dans_un_magasin_non_choisi',
            ],
        ],
        'cabinets.au-nom-de-la-personne-et-du-cabinet' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Chaque action d\'un compte du cabinet ou d\'un expert-comptable de la société chez un client se fait en son nom, suivi du nom du cabinet, et suit les règles du client.',
            'tests' => [
                'tests/Feature/Tenancy/NomPourLHistoriqueTest.php::test_le_nom_fige_d_un_compte_de_cabinet_porte_le_nom_du_cabinet_au_moment_de_l_operation',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_un_collaborateur_telecharge_les_pdf_et_exporte_le_journal_a_son_nom_avec_celui_du_cabinet',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_deux_collaborateurs_du_meme_cabinet_signent_chacun_leurs_consultations',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_telecharge_les_pdf_et_exporte_le_journal_des_evenements_a_son_nom_avec_son_cabinet',
            ],
        ],
        'cabinets.fin-du-lien' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Le propriétaire du client ou celui du cabinet met fin au lien quand il veut : l\'accès s\'arrête aussitôt, chaque session le perd dès sa requête suivante, et la fin s\'inscrit au journal d\'audit des deux sociétés ; un client n\'a qu\'un cabinet à la fois, lié ou déclaré.',
            'tests' => [
                'tests/Feature/Cabinets/FinDuLienTest.php::test_le_client_met_fin_au_lien_et_l_acces_du_cabinet_s_arrete_aussitot',
                'tests/Feature/Cabinets/FinDuLienTest.php::test_le_cabinet_met_fin_au_lien',
                'tests/Feature/Cabinets/FinDuLienTest.php::test_une_session_ouverte_perd_l_acces_au_client_des_sa_requete_suivante',
                'tests/Feature/Cabinets/FinDuLienTest.php::test_la_fin_du_lien_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_journaux_des_deux_societes',
                'tests/Feature/Cabinets/FinDuLienTest.php::test_la_fin_du_lien_efface_les_clients_confies_et_previent_l_autre_proprietaire_et_les_collaborateurs',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_lier_un_autre_cabinet_met_fin_a_la_declaration_et_retire_ses_comptes',
                'tests/Feature/Cabinets/ExpertsComptablesConcurrenceTest.php::test_une_declaration_et_l_acceptation_de_la_demande_d_un_autre_cabinet_simultanees_ne_laissent_qu_un_lien_en_cours',
            ],
        ],
        'cabinets.expert-comptable-declare' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Seul le propriétaire d\'une société validée déclare son expert-comptable, en redonnant son mot de passe, par le SIREN de son cabinet (existant, actif, 69.20Z, dénomination lue au répertoire) et le nom de chaque personne ; le SIREN d\'un cabinet déjà vérifié ne se déclare pas mais se désigne, sans révéler un cabinet inscrit qui ne l\'est pas ; déclarer remplace le cabinet lié ou déclaré après confirmation et retire la demande de lien envoyée.',
            'tests' => [
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_seul_le_proprietaire_declare_son_expert_comptable_en_redonnant_son_mot_de_passe',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_le_siren_declare_doit_exister_etre_actif_et_avoir_le_code_69_20z',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_le_siren_d_un_cabinet_deja_verifie_ne_se_declare_pas_et_mene_a_la_designation',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_un_cabinet_inscrit_mais_pas_encore_verifie_se_declare_comme_un_siren_inconnu_de_piliqi',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_declarer_alors_qu_un_cabinet_est_lie_le_remplace_apres_confirmation',
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_declarer_retire_la_demande_de_lien_envoyee_a_un_autre_cabinet_et_le_previent',
                'tests/Feature/Societe/CabinetComptableTest.php::test_une_societe_qui_attend_son_code_lit_pourquoi_elle_ne_declare_pas_et_peut_designer_ou_inviter',
            ],
        ],
        'cabinets.comptes-de-l-expert-comptable-declare' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Pour chaque personne déclarée, le propriétaire crée un compte de la société au rôle d\'expert-comptable de la société, qui n\'a jamais son identifiant pour mot de passe et le choisit par un lien de 7 jours, ne vend jamais, n\'envoie aucun e-mail aux clients, ne reçoit aucune alerte de stock, n\'ouvre ni l\'espace de la société ni la démonstration, ne voit ni le journal d\'audit ni les traces, lit l\'identité et les réglages fiscaux, et ouvre chaque magasin réel avec les autorisations de la déclaration.',
            'tests' => [
                'tests/Feature/Cabinets/ExpertComptableDeclareTest.php::test_la_declaration_cree_un_compte_de_la_societe_dont_le_mot_de_passe_n_est_jamais_l_identifiant',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_le_lien_vaut_sept_jours_a_la_meme_heure_de_paris_meme_au_changement_d_heure',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_choisir_son_mot_de_passe_consomme_le_lien_connecte_la_personne_et_montre_son_identifiant',
                'tests/Feature/Cabinets/LienDeLExpertComptableTest.php::test_ouvrir_le_lien_n_ecrit_rien_et_ne_montre_ni_l_identifiant_ni_l_adresse_ni_le_proprietaire',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_n_ouvre_ni_l_espace_de_la_societe_ni_la_demonstration_ni_la_caisse_ni_l_espace_d_un_cabinet',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_voit_l_identite_et_les_reglages_fiscaux_de_la_societe_en_lecture_seule',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_n_envoie_aucun_e_mail_a_un_client_et_ne_recoit_aucune_alerte_de_stock',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_suit_la_lecture_seule_de_la_societe_et_de_ses_magasins',
            ],
        ],
        'cabinets.rattachement-de-la-declaration' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Le jour où un cabinet de ce SIREN est inscrit et vérifié, la déclaration devient d\'elle-même son lien avec la société, avec les mêmes autorisations, et les comptes de l\'expert-comptable de la société sont retirés, leur historique restant ; les deux propriétaires en sont prévenus et chaque personne dont le compte se ferme reçoit un e-mail ; jusque-là le cabinet n\'apprend rien des sociétés qui ont déclaré son SIREN.',
            'tests' => [
                'tests/Feature/Cabinets/RattachementDesExpertsComptablesTest.php::test_quand_le_cabinet_du_siren_devient_verifie_la_declaration_devient_le_lien_avec_les_memes_autorisations_sans_copie',
                'tests/Feature/Cabinets/RattachementDesExpertsComptablesTest.php::test_au_rattachement_les_comptes_d_expert_comptable_sont_retires_avec_leur_historique_et_leurs_liens_effaces',
                'tests/Feature/Cabinets/RattachementDesExpertsComptablesTest.php::test_le_rattachement_previent_les_deux_proprietaires_et_les_personnes_dont_le_compte_est_ferme',
                'tests/Feature/Cabinets/RattachementDesExpertsComptablesTest.php::test_le_rattachement_retire_les_demandes_en_cours_entre_la_societe_et_ce_cabinet',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_le_cabinet_n_apprend_rien_des_societes_qui_ont_declare_son_siren_avant_le_rattachement',
                'tests/Feature/Cabinets/IsolationDesCabinetsTest.php::test_une_societe_n_apprend_pas_qu_une_autre_a_declare_le_meme_siren',
            ],
        ],
        'cabinets.limites-des-invitations' => [
            'puce' => 'Cabinets d\'expertise comptable',
            'regle' => 'Au plus 50 invitations et demandes de lien par jour et par cabinet ; après un refus, un retrait ou une expiration, une partie ne renouvelle son invitation ou sa demande à la même autre partie qu\'après 7 jours.',
            'tests' => [
                'tests/Feature/Cabinets/InvitationsTest.php::test_un_cabinet_n_envoie_pas_plus_de_cinquante_invitations_par_jour',
                'tests/Feature/Cabinets/InvitationsTest.php::test_une_demande_compte_dans_les_cinquante_par_jour_et_ne_se_renouvelle_pas_avant_sept_jours',
                'tests/Feature/Cabinets/InvitationsTest.php::test_une_invitation_refusee_ne_se_renouvelle_pas_avant_sept_jours',
            ],
        ],

        // Clients, produits, fournisseurs.
        'fiches.identifiants-du-client-professionnel' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Un client professionnel établi en France a un SIREN (ou son SIRET) ; un client établi à l\'étranger, un n° de TVA ou un autre identifiant.',
            'tests' => [
                'tests/Feature/Filament/ClientResourceTest.php::test_un_professionnel_etabli_en_france_sans_siren_ni_siret_est_refuse',
                'tests/Feature/Filament/ClientResourceTest.php::test_un_professionnel_de_guadeloupe_est_propose_comme_etabli_en_france_et_doit_avoir_un_siren',
                'tests/Feature/Contacts/ClientTest.php::test_un_professionnel_repasse_etabli_en_france_perd_son_identifiant_etranger',
                'tests/Feature/Contacts/ClientTest.php::test_un_client_repasse_en_particulier_perd_ses_identifiants_d_entreprise',
            ],
        ],
        'fiches.mode-de-paiement-prefere' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Le mode de paiement préféré du client est présélectionné à l\'encaissement.',
            'tests' => [
                'tests/Feature/Filament/PaiementResourceTest.php::test_le_mode_de_paiement_prefere_du_client_est_preselectionne',
                'tests/Feature/Filament/CaisseTest.php::test_choisir_un_client_preselectionne_son_mode_de_paiement_prefere',
            ],
        ],
        'fiches.archivees-jamais-supprimees' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Une fiche ne se supprime pas : elle s\'archive, n\'est plus proposée, reste lisible sur les anciens documents et se restaure.',
            'tests' => [
                'tests/Feature/Authorization/ClientPolicyTest.php::test_un_client_archive_ne_se_modifie_pas_avant_sa_restauration_et_ne_s_efface_jamais',
                'tests/Feature/Filament/PaiementResourceTest.php::test_le_client_archive_reste_affiche_sur_ses_paiements_sans_etre_propose',
                'tests/Feature/Filament/SelecteurDeClientsTest.php::test_un_client_archive_ou_d_un_autre_magasin_n_apparait_jamais',
                'tests/Feature/Facturation/EnvoyerDocumentActionTest.php::test_la_facture_d_un_client_archive_s_envoie_et_se_relance',
            ],
        ],
        'fiches.client-comptoir' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Le client comptoir ne s\'archive jamais, n\'apparaît ni dans la liste des clients ni dans leurs sélecteurs, reste « Client comptoir » sur ses tickets et ses avoirs, et ses avoirs sont des bons d\'avoir, jamais un crédit du client.',
            'tests' => [
                'tests/Feature/Contacts/ClientTest.php::test_le_client_comptoir_ne_s_archive_jamais',
                'tests/Feature/Filament/CaisseTest.php::test_le_formulaire_propose_le_client_comptoir_par_defaut',
                'tests/Feature/Facturation/AppliquerAvoirsDisponiblesActionTest.php::test_le_client_comptoir_naccumule_et_napplique_jamais_de_credit',
                'tests/Feature/Facturation/ReimputationALaContrePassationTest.php::test_le_credit_d_un_bon_d_avoir_du_client_comptoir_ne_s_impute_jamais_sur_une_autre_vente',
                'tests/Feature/Filament/FactureResourceTest.php::test_le_client_comptoir_est_refuse_par_une_facture_ou_un_devis_forge',
            ],
        ],
        'fiches.recherches-sans-casse-ni-accents' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Les recherches ignorent la casse et les accents (« creme » trouve « Crème »).',
            'tests' => [
                'tests/Feature/Recherche/RechercheSansAccentsTest.php::test_la_recherche_ignore_la_casse_et_les_accents',
                'tests/Feature/Recherche/RechercheSansAccentsTest.php::test_les_ligatures_et_lettres_barrees_se_cherchent_sans_accents',
                'tests/Feature/Recherche/RechercheSansAccentsTest.php::test_la_base_plie_chaque_caractere_selon_unicode',
            ],
        ],
        'fiches.reference-produit-unique' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Une référence produit est unique dans le magasin sans tenir compte de la casse et s\'enregistre telle que saisie.',
            'tests' => [
                'tests/Feature/Catalogue/ProduitTest.php::test_une_reference_deja_prise_sous_une_autre_casse_est_refusee',
                'tests/Feature/Catalogue/ProduitTest.php::test_une_reference_accentuee_deja_prise_sous_une_autre_casse_est_refusee',
                'tests/Feature/Catalogue/ProduitTest.php::test_la_base_refuse_une_reference_deja_prise_sous_une_autre_casse',
                'tests/Feature/Filament/ProduitResourceTest.php::test_une_reference_deja_prise_sous_une_autre_casse_est_refusee',
            ],
        ],

        // Documents de vente.
        'documents.note-de-calcul-a-moins-de-deux-centimes' => [
            'puce' => 'Documents de vente',
            'regle' => 'La note de calcul d\'un document qui montre le PU HT ne dit « à moins de deux centimes près » que si chaque ligne le vérifie ; sinon elle dit qu\'un écart de quelques centimes est possible (T105).',
            'tests' => [
                'tests/Feature/Facturation/MentionsFiscalesTest.php::test_la_note_ne_dit_moins_de_deux_centimes_que_si_chaque_ligne_le_verifie',
                'tests/Feature/Facturation/MentionsFiscalesTest.php::test_le_dernier_avoir_d_une_ligne_rendue_en_trois_fois_retombe_a_moins_de_deux_centimes',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_au_dela_de_dix_mille_articles_la_note_de_calcul_dit_qu_un_petit_ecart_est_possible',
                'tests/Unit/CalculateurLigneTest.php::test_le_pu_ht_retombe_sur_le_ht_de_la_ligne_a_moins_de_deux_centimes',
            ],
        ],
        'documents.client-fige-et-facture-immuable' => [
            'puce' => 'Documents de vente',
            'regle' => 'Le client (nom, adresse, identifiants) et son type sont figés à l\'émission ; une facture émise ne change plus jamais, ne se supprime pas et se corrige par un avoir.',
            'tests' => [
                'tests/Feature/Facturation/TypeClientFigeTest.php::test_une_facture_garde_le_siren_fige_de_son_client_redevenu_particulier',
                'tests/Feature/Facturation/TypeClientFigeTest.php::test_un_ticket_reste_le_ticket_de_son_client_d_origine_quand_la_fiche_devient_professionnelle',
                'tests/Feature/CaisseSecurisee/InalterabiliteTest.php::test_une_facture_emise_ne_se_modifie_plus',
                'tests/Feature/CaisseSecurisee/InalterabiliteTest.php::test_les_lignes_d_une_facture_emise_ne_se_modifient_ni_ne_se_suppriment_ni_ne_s_ajoutent',
                'tests/Feature/Facturation/FactureNeSePasSupprimeTest.php::test_meme_le_proprietaire_ne_peut_pas_supprimer_une_facture_emise',
                'tests/Feature/Facturation/FactureNeSePasSupprimeTest.php::test_une_facture_corrigee_par_un_avoir_total_reste_definitivement_non_supprimable',
            ],
        ],
        'documents.echeance' => [
            'puce' => 'Documents de vente',
            'regle' => 'Échéance = date d\'émission + délai du client, sinon délai du magasin (30 jours, réglable).',
            'tests' => [
                'tests/Feature/Facturation/EmettreFactureActionTest.php::test_l_echeance_est_la_date_d_emission_plus_le_delai_du_client_sinon_celui_du_magasin',
                'tests/Feature/Filament/FactureResourceTest.php::test_l_echeance_s_annonce_fixee_a_l_emission_avec_le_delai_du_client',
            ],
        ],
        'documents.identite-legale-complete' => [
            'puce' => 'Documents de vente',
            'regle' => 'Une facture à un professionnel exige l\'identité légale complète de la société (capital pour toute société commerciale) et le SIRET et l\'adresse du magasin ; les tickets restent possibles.',
            'tests' => [
                'tests/Feature/Facturation/EmettreFactureActionTest.php::test_une_facture_a_un_professionnel_attend_l_identite_complete_du_magasin',
                'tests/Feature/Facturation/AvoirActionsTest.php::test_l_avoir_d_une_facture_professionnelle_attend_l_identite_complete_du_magasin',
                'tests/Feature/Societe/IdentiteSocieteTest.php::test_une_identite_incomplete_bloque_les_factures_professionnelles_de_tous_les_magasins_mais_pas_les_tickets',
                'tests/Feature/Societe/IdentiteSocieteTest.php::test_le_capital_est_exige_pour_toute_societe_commerciale',
                'tests/Feature/Societe/IdentiteSocieteTest.php::test_un_entrepreneur_individuel_n_a_pas_de_capital',
            ],
        ],
        'documents.mentions-et-assurance' => [
            'puce' => 'Documents de vente',
            'regle' => 'Une facture à un professionnel porte les mentions réglables (pénalités, indemnité de 40 €, pas d\'escompte) ; l\'assurance professionnelle, facultative, s\'imprime quand elle est renseignée et se fige à l\'émission ; la mention « EI » s\'ajoute quand elle manque, le capital s\'imprime quand il est renseigné et le siège n\'est pas répété quand il est l\'adresse du magasin.',
            'tests' => [
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_seule_une_facture_a_un_professionnel_imprime_les_mentions_entre_professionnels',
                'tests/Feature/Facturation/AssuranceProfessionnelleTest.php::test_sans_assurance_un_devis_se_numerote_et_une_vente_s_emet_dans_le_commerce_et_les_services',
                'tests/Feature/Facturation/AssuranceProfessionnelleTest.php::test_l_assurance_renseignee_s_imprime_dans_le_pied_des_devis_des_factures_des_tickets_et_des_avoirs',
                'tests/Feature/Facturation/AssuranceProfessionnelleTest.php::test_l_assurance_se_fige_a_l_emission_et_son_changement_s_inscrit_au_journal_d_audit',
                'tests/Feature/Facturation/EmetteurFigeTest.php::test_la_mention_ei_est_ajoutee_quand_elle_manque_et_jamais_doublee',
                'tests/Feature/Facturation/EmetteurFigeTest.php::test_un_entrepreneur_individuel_imprime_la_mention_ei_son_adresse_d_entreprise_et_aucun_capital',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_un_capital_social_non_renseigne_ne_s_imprime_pas',
                'tests/Feature/Facturation/EmetteurFigeTest.php::test_le_siege_identique_a_l_adresse_du_magasin_n_est_imprime_qu_une_fois',
                'tests/Feature/Facturation/EmetteurFigeTest.php::test_le_pdf_d_une_facture_emise_garde_le_siege_la_forme_et_le_capital_du_jour_de_son_emission',
            ],
        ],
        'documents.pdf-en-a4' => [
            'puce' => 'Documents de vente',
            'regle' => 'Le PDF d\'une facture, d\'un ticket, d\'un avoir ou d\'un devis est une vraie page A4 en portrait, jamais réduite : le magasin en tête, l\'identité de la société et toutes les mentions dans un pied fixé en bas de chaque page, avec « Page x sur y » ; chaque ligne porte la référence du produit, entière sur une seule ligne.',
            'tests' => [
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_le_pdf_est_une_page_a4_meme_avec_une_seule_ligne',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_l_identite_de_la_societe_et_toutes_les_mentions_sont_dans_le_pied_de_page',
                'tests/Feature/Facturation/AvoirPdfGeneratorTest.php::test_le_pdf_d_un_avoir_est_une_page_a4_numerotee',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_la_reference_du_produit_est_imprimee_dans_sa_colonne_sans_retour_a_la_ligne',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_une_reference_de_60_caracteres_et_de_grands_montants_laissent_toutes_les_colonnes_dans_la_page',
            ],
        ],
        'documents.colonnes-ht-ou-ttc' => [
            'puce' => 'Documents de vente',
            'regle' => 'Toute facture, tout avoir sur facture et tout devis à un professionnel montrent par ligne le PU HT, la remise, le taux et le total HT, le PU HT tel qu\'il est enregistré, jusqu\'à six décimales ; un ticket, un avoir sur ticket et un devis à un particulier montrent le PU TTC et le total TTC ; une vente sans TVA française montre le PU HT et le total HT.',
            'tests' => [
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_une_facture_a_un_professionnel_imprime_le_pu_ht_de_54_166667_euros_tel_qu_il_est_enregistre',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_une_facture_n_imprime_ni_pu_ttc_ni_tva_ni_total_ttc_par_ligne',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_un_ticket_imprime_le_pu_ttc_et_le_total_ttc_de_chaque_ligne',
                'tests/Feature/Facturation/AvoirPdfGeneratorTest.php::test_un_avoir_sur_facture_imprime_les_colonnes_ht_et_un_avoir_sur_ticket_les_colonnes_ttc',
                'tests/Feature/Facturation/DevisPdfGeneratorTest.php::test_un_devis_a_un_professionnel_imprime_les_colonnes_ht_et_un_devis_a_un_particulier_les_colonnes_ttc',
                'tests/Feature/Facturation/NatureOperationTest.php::test_le_pdf_d_un_export_a_un_professionnel_n_imprime_que_le_pu_ht_et_le_total_ht',
                'tests/Unit/CalculateurLigneTest.php::test_un_pu_ht_s_affiche_tel_qu_il_est_enregistre_sans_zero_inutile_au_dela_de_deux_decimales',
            ],
        ],
        'documents.note-de-calcul' => [
            'puce' => 'Documents de vente',
            'regle' => 'La note de calcul imprimée sous les lignes de toute vente hors franchise dit que les totaux se calculent depuis les prix TTC, sans arrondi intermédiaire, et l\'écart qui peut en résulter.',
            'tests' => [
                'tests/Feature/Facturation/MentionsFiscalesTest.php::test_chaque_variante_de_la_note_de_calcul_finit_par_la_phrase_des_prix_unitaires_de_son_document',
                'tests/Feature/Facturation/MentionsFiscalesTest.php::test_une_vente_en_franchise_en_base_n_a_pas_de_note_de_calcul',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_la_note_de_calcul_explique_le_pu_ht_tel_qu_il_est_enregistre_et_les_mentions_obligatoires_restent',
            ],
        ],
        'documents.emettre-et-encaisser' => [
            'puce' => 'Documents de vente',
            'regle' => 'Une facture ou un ticket fait hors de la caisse s\'émet et s\'encaisse d\'un seul geste (« Émettre et encaisser »), en un ou plusieurs modes, en tout ou en partie.',
            'tests' => [
                'tests/Feature/Facturation/EmettreEtEncaisserTest.php::test_une_facture_s_emet_et_s_encaisse_d_un_seul_geste_dans_un_seul_mode',
                'tests/Feature/Facturation/EmettreEtEncaisserTest.php::test_un_brouillon_s_encaisse_en_plusieurs_modes_prelevement_compris',
                'tests/Feature/Facturation/EmettreEtEncaisserTest.php::test_un_paiement_partiel_laisse_le_reste_du_avec_l_echeance_de_la_facture',
                'tests/Feature/Facturation/EmettreEtEncaisserConcurrenceTest.php::test_deux_emettre_et_encaisser_simultanes_du_meme_brouillon_n_emettent_et_n_encaissent_qu_une_fois',
                'tests/Browser/Parcours/EmettreEtEncaisserTest.php › le responsable émet et encaisse une facture en espèces et par carte depuis sa fiche, et son PDF imprime chaque règlement à gauche du Total TTC',
            ],
        ],
        'documents.reglements-imprimes-et-recus' => [
            'puce' => 'Documents de vente',
            'regle' => 'Les règlements reçus à l\'émission s\'impriment par mode, avec le crédit d\'avoir imputé et le restant dû, dans un bloc à gauche des totaux ; chaque encaissement et chaque contre-passation faits après l\'émission donnent un reçu de paiement en PDF, qui n\'est pas une facture et ne modifie jamais le document.',
            'tests' => [
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_les_reglements_de_l_emission_s_impriment_par_mode',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_les_reglements_s_impriment_a_gauche_des_totaux_sur_la_meme_bande',
                'tests/Feature/Facturation/RecusDePaiementTest.php::test_le_pdf_du_recu_dit_tout_ce_qu_il_atteste',
                'tests/Feature/Facturation/RecusDePaiementTest.php::test_un_recu_ne_se_modifie_ni_ne_se_supprime',
            ],
        ],
        'documents.devis' => [
            'puce' => 'Documents de vente',
            'regle' => 'Seul un devis brouillon jamais numéroté se supprime ; un brouillon dont la validité est dépassée ne s\'envoie pas ; un devis envoyé expire automatiquement ; modifier un devis envoyé ou accepté le repasse en brouillon et efface son acceptation ; un devis refusé, expiré ou converti reste figé.',
            'tests' => [
                'tests/Feature/Authorization/DevisPolicyTest.php::test_supprimer_un_brouillon_jamais_envoye_selon_le_role',
                'tests/Feature/Facturation/DevisConversionTest.php::test_un_devis_dont_la_validite_est_depassee_ne_part_pas',
                'tests/Feature/Facturation/DevisConversionTest.php::test_un_devis_dont_la_validite_est_depassee_ne_s_accepte_plus',
                'tests/Feature/Facturation/ExpirerDevisTest.php::test_un_devis_envoye_expire_le_lendemain_du_dernier_jour_de_sa_validite',
                'tests/Feature/Facturation/ModifierDevisActionTest.php::test_un_devis_envoye_modifie_repasse_en_brouillon_et_repart_sous_son_numero',
                'tests/Feature/Facturation/ModifierDevisActionTest.php::test_un_devis_accepte_modifie_repasse_en_brouillon_et_perd_son_acceptation',
                'tests/Feature/Facturation/EmetteurFigeTest.php::test_un_devis_repasse_en_brouillon_perd_son_emetteur_fige_et_fige_celui_de_son_nouvel_envoi',
            ],
        ],
        'documents.reponse-du-client-au-devis' => [
            'puce' => 'Documents de vente',
            'regle' => 'Le client répond à un devis envoyé par un lien secret propre à cet envoi, valable jusqu\'à la fin de la validité du devis et caduc dès sa réponse, sa modification ou un nouvel envoi : il l\'accepte en tapant son nom et en cochant « Bon pour accord », ou le refuse ; sa réponse est enregistrée une seule fois, avec ses traces, et le magasin en est prévenu.',
            'tests' => [
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_l_envoi_d_un_devis_porte_un_lien_secret_dont_seule_l_empreinte_se_garde',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_accepter_exige_le_nom_tape_et_bon_pour_accord',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_accepter_enregistre_le_nom_la_date_l_empreinte_et_les_traces_une_seule_fois',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_refuser_enregistre_son_motif_facultatif_confirme_et_previent',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_le_lien_vaut_jusqu_au_dernier_jour_de_la_validite_du_devis',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_un_devis_modifie_rend_son_lien_caduc_meme_renvoye_sans_e_mail',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_un_nouvel_envoi_donne_un_nouveau_lien_et_rend_l_ancien_caduc',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_le_lien_d_un_magasin_ne_montre_ni_ne_touche_rien_d_un_autre',
                'tests/Feature/Facturation/ReponseAuDevisConcurrenceTest.php::test_deux_reponses_simultanees_par_le_meme_lien_n_en_enregistrent_qu_une',
            ],
        ],
        'documents.devis-transforme-en-facture' => [
            'puce' => 'Documents de vente',
            'regle' => 'Un devis accepté se transforme en facture, une seule fois, par un compte autorisé à vendre ; la facture reprend ses lignes et ses prix et porte « Établie d\'après le devis n° … accepté le … » ; un devis ne réserve aucun stock, ses lignes et l\'avis de son acceptation signalent un stock insuffisant.',
            'tests' => [
                'tests/Feature/Facturation/TransformerUnDevisEnFactureTest.php::test_la_facture_reprend_les_lignes_du_devis_accepte_et_dit_d_ou_elle_vient',
                'tests/Feature/Facturation/TransformerUnDevisEnFactureTest.php::test_un_devis_ne_se_transforme_qu_une_fois',
                'tests/Feature/Facturation/TransformerUnDevisEnFactureTest.php::test_les_lignes_reprises_du_devis_ne_se_modifient_qu_avec_l_autorisation_des_remises_et_des_prix',
                'tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php::test_l_avis_d_une_acceptation_dit_quels_produits_n_ont_plus_assez_de_stock',
                'tests/Feature/Facturation/DevisConversionTest.php::test_impossible_de_convertir_deux_fois_le_meme_devis',
            ],
        ],
        'documents.un-produit-une-ligne' => [
            'puce' => 'Documents de vente',
            'regle' => 'Un produit n\'a qu\'une ligne d\'un ticket, d\'une facture ou d\'un devis : le choisir de nouveau augmente la quantité de sa ligne ; un service suit la même règle, sauf un service sans prix de référence à un autre prix ; aucun document ne s\'enregistre avec deux lignes du même produit, ni du même service au même prix et au même taux, quel que soit l\'écran ou l\'API ; un avoir garde une ligne par ligne du document qu\'il corrige (décision du 26/10/2026).',
            'tests' => [
                'tests/Feature/Facturation/LignesUniquesTest.php::test_une_facture_avec_deux_lignes_du_meme_produit_est_refusee_meme_a_des_conditions_differentes',
                'tests/Feature/Facturation/LignesUniquesTest.php::test_un_brouillon_et_un_devis_modifies_avec_deux_lignes_du_meme_produit_sont_refuses',
                'tests/Feature/Facturation/LignesUniquesTest.php::test_un_service_n_a_qu_une_ligne_par_prix_et_par_taux',
                'tests/Feature/Facturation/LignesUniquesTest.php::test_le_retour_article_par_article_d_une_ligne_de_deux_rend_les_montants_de_deux_lignes',
                'tests/Feature/Filament/UnProduitUneLigneTest.php::test_le_meme_produit_ajoute_deux_fois_donne_une_seule_ligne',
                'tests/Feature/Filament/UnProduitUneLigneTest.php::test_chaque_clic_sur_un_produit_ajoute_un_a_sa_quantite_choisie',
                'tests/Feature/Filament/UnProduitUneLigneTest.php::test_sans_l_autorisation_l_ajout_a_une_ligne_remisee_ou_a_un_autre_prix_est_refuse',
                'tests/Feature/Filament/UnProduitUneLigneTest.php::test_un_brouillon_rouvert_garde_ses_lignes',
            ],
        ],

        // Prix et TVA des ventes.
        'prix.taux-par-defaut' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Le taux de TVA se saisit librement, 20 % par défaut (8,5 % pour un magasin de la Guadeloupe, de la Martinique ou de La Réunion) ; une ligne de produit prend le taux de sa fiche.',
            'tests' => [
                'tests/Unit/TerritoireTvaTest.php::test_le_taux_propose_est_le_taux_normal_du_territoire',
                'tests/Unit/TerritoireTvaTest.php::test_un_taux_hors_de_ceux_en_vigueur_est_signale',
                'tests/Feature/Societe/ParametresFiscauxSocieteTest.php::test_un_magasin_de_la_martinique_propose_8_5_pour_cent_sur_un_service',
                'tests/Feature/Facturation/CreerFactureActionTest.php::test_une_ligne_de_service_prend_le_taux_de_sa_fiche',
            ],
        ],
        'prix.prix-de-vente-arrondi-une-fois' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Seul le prix de vente TTC est un prix arrondi, une seule fois, quand la facture d\'achat ou l\'inventaire d\'ouverture enregistre le produit, au barème du magasin.',
            'tests' => [
                'tests/Unit/BaremePrixDeVenteTest.php::test_le_prix_de_vente_ajoute_le_benefice_au_prix_dachat_puis_la_tva',
                'tests/Unit/BaremePrixDeVenteTest.php::test_l_arrondi_ramene_le_prix_au_x9_le_plus_proche',
                'tests/Unit/BaremePrixDeVenteTest.php::test_un_montant_du_bareme_plus_precis_que_le_centime_est_refuse_jamais_arrondi',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_le_bareme_du_magasin_propose_le_prix_de_vente_dun_nouveau_produit',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_le_prix_de_vente_saisi_ne_change_jamais_le_prix_dachat',
            ],
        ],
        'prix.remises-et-prix' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Accorder une remise, fixer le prix d\'un service et vendre un produit à un autre prix que sa fiche exigent l\'autorisation « accorder des remises et fixer le prix d\'un service », que les responsables et le propriétaire ont toujours, précochée pour les administrateurs, jamais pour les vendeurs et les observateurs, et qu\'un cabinet n\'a jamais ; sans elle, une ligne prend le prix de sa fiche.',
            'tests' => [
                'tests/Feature/Facturation/RemisesEtPrixTest.php::test_le_proprietaire_et_le_responsable_ont_toujours_remises_et_prix_le_modele_de_l_administrateur_la_precoche_et_aucun_autre_modele',
                'tests/Feature/Facturation/RemisesEtPrixTest.php::test_un_vendeur_n_accorde_aucune_remise_meme_par_requete_forgee',
                'tests/Feature/Facturation/RemisesEtPrixTest.php::test_seul_un_membre_autorise_vend_un_produit_a_un_autre_prix_que_celui_de_sa_fiche',
                'tests/Feature/Facturation/RemisesEtPrixTest.php::test_un_vendeur_vend_un_service_a_son_prix_de_reference_sans_pouvoir_le_changer',
                'tests/Feature/Facturation/DerogationsDePrixTest.php::test_une_ligne_deja_accordee_ne_couvre_qu_une_ligne_en_tout_point_identique',
            ],
        ],
        'prix.calcul-depuis-le-ttc' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Toute vente se calcule une seule fois, à son enregistrement, depuis les prix TTC : le HT de chaque taux est le TTC de ses lignes ÷ (1 + taux), arrondi une fois au centime le plus proche (un demi-centime au centime supérieur), réparti sur ses lignes par cumul ; la TVA est la différence ; un même panier a les mêmes montants pour tout client.',
            'tests' => [
                'tests/Feature/Facturation/PolitiqueDArrondiTest.php::test_cinquante_euros_ttc_font_41_67_ht_a_l_ecran_et_sur_la_vente_quel_que_soit_le_decoupage_et_le_client',
                'tests/Feature/Facturation/PolitiqueDArrondiTest.php::test_quatre_articles_a_65_euros_ttc_font_216_67_ht_et_43_33_de_tva_en_une_ou_quatre_lignes',
                'tests/Feature/Facturation/RepartitionTvaParCumulTest.php::test_une_facture_professionnelle_repartit_le_ht_de_chaque_taux_par_cumul_au_centime',
                'tests/Feature/Facturation/RepartitionTvaParCumulTest.php::test_le_contenu_signe_et_le_pdf_portent_les_parts_ainsi_reparties',
                'tests/Unit/CalculateurLigneTest.php::test_un_demi_centime_exact_arrondit_le_ht_au_centime_superieur',
                'tests/Unit/CalculateurLigneAuHasardTest.php::test_2000_ventes_aleatoires_se_calculent_taux_par_taux_sur_leur_ttc',
                'tests/Feature/Facturation/ExempleDeQuinzeLignesTest.php::test_le_meme_panier_en_ticket_a_les_memes_montants_et_imprime_les_pu_ttc',
            ],
        ],
        'prix.pu-ht-a-six-decimales' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Le PU HT d\'une ligne est son PU TTC ÷ (1 + taux), enregistré avec au plus six décimales : la quantité × le PU HT s\'écarte du HT de la ligne de moins de deux centimes jusqu\'à 10 000 articles ; il s\'affiche au centime, sauf sur les documents qui montrent le PU HT, où il s\'affiche tel qu\'il est enregistré.',
            'tests' => [
                'tests/Unit/CalculateurLigneTest.php::test_le_pu_ht_se_calcule_avec_au_plus_six_decimales',
                'tests/Unit/CalculateurLigneTest.php::test_un_prix_unitaire_s_affiche_au_centime_un_demi_centime_au_centime_superieur',
                'tests/Unit/CalculateurLigneAuHasardTest.php::test_10000_lignes_aleatoires_egalent_le_calcul_exact',
                'tests/Feature/Facturation/FacturePdfGeneratorTest.php::test_un_pu_ht_a_un_demi_centime_exact_s_imprime_tel_qu_il_est_enregistre',
                'tests/Browser/Parcours/PuHtExactTest.php › le responsable lit le PU HT tel qu’il est enregistré sur la fiche et sur le PDF d’une facture à un professionnel, et au centime sur la fiche d’un ticket',
            ],
        ],
        'prix.retours' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Un retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le même calcul sur cette quantité, sans jamais dépasser le HT ni la TVA de la ligne, moins ce que les retours précédents ont rendu ; le retour de toute la ligne rend exactement ses montants.',
            'tests' => [
                'tests/Unit/CalculateurLigneTest.php::test_les_retours_partiels_rendent_le_calcul_de_la_vente_encadre_par_la_ligne',
                'tests/Unit/CalculateurLigneTest.php::test_un_retour_rend_le_calcul_de_la_vente_sur_les_articles_rendus',
                'tests/Unit/CalculateurLigneTest.php::test_un_retour_ne_rend_jamais_plus_que_la_tva_de_la_ligne',
                'tests/Unit/CalculateurLigneTest.php::test_aucun_retour_ne_rend_un_montant_negatif',
                'tests/Feature/Facturation/RepartitionTvaParCumulTest.php::test_le_retour_d_une_ligne_ne_depasse_jamais_sa_part_de_tva',
            ],
        ],
        'prix.exoneration-et-franchise' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Une vente exonérée se paie au prix hors taxe, calculé de la même façon, sans TVA ; un magasin en franchise en base vend à 0 % au même prix TTC, avec la mention « TVA non applicable, art. 293 B du CGI ».',
            'tests' => [
                'tests/Feature/Facturation/NatureOperationTest.php::test_un_export_a_un_professionnel_se_paie_au_prix_hors_taxe_sans_tva_avec_sa_mention',
                'tests/Feature/Facturation/FranchiseEnBaseTest.php::test_un_ticket_en_franchise_est_a_zero_pour_cent_au_meme_prix_ttc_avec_la_mention',
                'tests/Feature/Facturation/FranchiseEnBaseTest.php::test_le_meme_ticket_au_reel_coute_le_meme_prix_ttc_sans_la_mention',
                'tests/Feature/Facturation/CreerFactureActionTest.php::test_une_vente_a_un_professionnel_en_franchise_est_sans_tva_au_meme_prix',
            ],
        ],
        'prix.achats-aux-montants-du-fournisseur' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Un achat garde les montants qu\'écrit la facture du fournisseur : son PU HT s\'enregistre avec au plus six décimales ; PILIQI propose le HT de chaque ligne et la TVA de chaque taux, que la personne remplace par ceux de la facture, acceptés jusqu\'à 0,02 € par ligne pour le HT et 1 centime par ligne du taux pour la TVA ; au-delà, une confirmation au journal d\'audit marque le document « TVA à vérifier » ; un avoir fournisseur garde de même les montants de la note d\'avoir, sans jamais rendre plus que la TVA restante du taux.',
            'tests' => [
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_un_pu_ht_a_six_decimales_se_garde_tel_quel_et_fait_le_cout_moyen',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_un_ht_de_ligne_s_accepte_jusqu_a_deux_centimes_d_ecart',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_une_tva_s_accepte_jusqu_a_un_centime_par_ligne_du_taux',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_sans_montant_ecrit_la_facture_garde_le_calcul_de_piliqi',
                'tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php::test_la_tva_proposee_suit_le_ht_qu_ecrit_la_note_d_avoir',
                'tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php::test_un_avoir_ne_rend_jamais_plus_que_la_tva_restante_du_taux',
                'tests/Unit/CalculateurLigneAuHasardTest.php::test_2000_achats_aleatoires_ont_la_tva_du_total_ht_de_chaque_taux',
            ],
        ],
        'prix.sommes-des-montants-enregistres' => [
            'puce' => 'Prix et TVA des ventes',
            'regle' => 'Ensuite plus rien ne s\'arrondit ni ne se recalcule : une clôture, un relevé, un tableau de bord additionne les montants enregistrés de ses documents ; les montants se calculent en centimes entiers, et aucun total ne s\'obtient par une somme que la base ferait sur une colonne décimale.',
            'tests' => [
                'tests/Feature/Facturation/PolitiqueDArrondiTest.php::test_deux_tickets_de_25_euros_font_41_66_ht_dans_la_cloture_qui_additionne_les_tickets',
                'tests/Feature/Filament/FacturesAEncaisserTest.php::test_le_restant_du_se_calcule_en_centimes_sans_somme_sql',
                'tests/Unit/CalculateurLigneLimitesTest.php::test_les_conversions_en_centimes_arrondissent_au_bon_endroit',
                'tests/Unit/CalculateurLigneAuHasardTest.php::test_larrondi_au_centime_est_exact_sur_tous_les_demi_centimes_jusqua_100_euros',
            ],
        ],

        // Ventes hors de France.
        'hors-france.natures-d-operation' => [
            'puce' => 'Ventes hors de France',
            'regle' => 'La nature d\'opération de la facture (export, livraison vers un DOM, livraison intracommunautaire, prestation autoliquidée à un professionnel de l\'UE, prestation hors UE) n\'exonère que si toutes ses conditions sont réunies, dont un n° de TVA de l\'UE valide sur VIES le jour même, consultation conservée ; une société de services ne voit et n\'accepte que les natures qui ne portent pas sur des biens.',
            'tests' => [
                'tests/Feature/Facturation/NatureOperationTest.php::test_une_livraison_intracommunautaire_retient_la_consultation_vies_du_jour',
                'tests/Feature/Facturation/NatureOperationTest.php::test_une_livraison_intracommunautaire_sans_toutes_ses_conditions_est_refusee',
                'tests/Feature/Facturation/NatureOperationTest.php::test_une_livraison_vers_un_dom_se_paie_au_prix_hors_taxe_avec_sa_mention',
                'tests/Feature/Facturation/NatureOperationTest.php::test_une_prestation_autoliquidee_exige_un_client_etabli_dans_un_autre_etat_membre',
                'tests/Feature/Facturation/NatureOperationTest.php::test_une_prestation_hors_ue_exige_l_identifiant_du_client',
                'tests/Feature/Filament/EcransSelonLeTypeTest.php::test_sans_articles_seules_les_natures_sans_biens_sont_proposees_et_acceptees_au_brouillon',
            ],
        ],
        'hors-france.preuves-d-export' => [
            'puce' => 'Ventes hors de France',
            'regle' => 'Les références des preuves d\'export et de livraison sont enregistrées sur la facture et celles qui manquent sont rappelées : une vente hors de l\'Union sans sa preuve de douane est en orange dans l\'« À faire » jusqu\'au 30e jour, puis en rouge à partir du 31e ; les noter exige l\'autorisation « noter les preuves d\'exonération et les bordereaux de détaxe », possible même dans un magasin fermé.',
            'tests' => [
                'tests/Feature/Facturation/PreuvesOperationTest.php::test_un_export_avec_sa_declaration_d_export_est_complet',
                'tests/Feature/Facturation/PreuvesOperationTest.php::test_une_livraison_intracommunautaire_avec_un_seul_document_de_transport_attend_une_seconde_preuve',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_la_preuve_d_export_est_en_orange_jusqu_au_30e_jour_puis_en_rouge_a_partir_du_31e',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_la_preuve_d_un_export_se_note_encore_apres_la_fermeture_du_magasin',
                'tests/Feature/Facturation/PreuvesOperationTest.php::test_le_bouton_ajouter_une_preuve_est_reserve_a_qui_est_autorise_a_noter_les_preuves_d_une_vente_exoneree',
                'tests/Feature/Facturation/FacturePolicyTest.php::test_justifier_une_vente_selon_le_role',
            ],
        ],
        'hors-france.exoneration-non-prouvee' => [
            'puce' => 'Ventes hors de France',
            'regle' => 'Sans preuve de sortie 150 jours après la vente, un export ou une livraison vers un DOM se régularise d\'un seul geste d\'un compte autorisé à vendre (jamais le propriétaire hors du magasin de démonstration) : un avoir sans retour en stock et une facture à la TVA française au même prix TTC, que le crédit de l\'avoir règle ; la vente entre dans l\'« À faire » ce jour-là, en rouge.',
            'tests' => [
                'tests/Feature/Facturation/ExonerationNonProuveeTest.php::test_un_export_sans_preuve_au_150e_jour_se_regularise_a_la_tva_francaise_au_meme_prix_ttc',
                'tests/Feature/Facturation/ExonerationNonProuveeTest.php::test_avant_le_150e_jour_la_regularisation_est_refusee',
                'tests/Feature/Facturation/ExonerationNonProuveeTest.php::test_une_vente_dont_la_preuve_de_sortie_est_enregistree_ne_se_regularise_pas',
                'tests/Feature/Facturation/ExonerationNonProuveeTest.php::test_le_proprietaire_ne_regularise_pas_hors_du_magasin_de_demonstration',
                'tests/Feature/Facturation/ExonerationNonProuveeTest.php::test_une_demande_repetee_rend_la_meme_facture_sans_doublon',
                'tests/Feature/Facturation/ExonerationNonProuveeTest.php::test_l_a_faire_et_la_liste_filtree_montrent_l_exoneration_non_prouvee',
            ],
        ],
        'hors-france.detaxe' => [
            'puce' => 'Ventes hors de France',
            'regle' => 'Détaxe : le ticket reste à la TVA française, on note le n° du bordereau, la TVA n\'est régularisée qu\'après le visa, dont la date se note une fois par bordereau, inscrite au journal d\'audit ; jusque-là, le ticket attend son visa dans l\'« À faire ».',
            'tests' => [
                'tests/Feature/Facturation/BordereauDetaxeTest.php::test_noter_le_bordereau_ne_change_pas_le_ticket',
                'tests/Feature/Facturation/BordereauDetaxeTest.php::test_le_bouton_noter_le_bordereau_est_reserve_a_qui_est_autorise_a_noter_les_preuves_sur_un_ticket_detaxable',
                'tests/Feature/Facturation/VisaDuBordereauTest.php::test_le_visa_se_note_une_fois_sur_le_bordereau_qui_fait_foi_et_s_inscrit_au_journal_d_audit',
                'tests/Feature/Facturation/VisaDuBordereauTest.php::test_le_ticket_detaxe_attend_son_visa_dans_l_a_faire_jusqu_a_ce_qu_il_soit_note',
                'tests/Feature/Facturation/VisaDuBordereauTest.php::test_la_fiche_note_le_visa_meme_dans_un_magasin_ferme',
            ],
        ],

        // Paiements et avoirs.
        'paiements.montant-egal-au-total-impute' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Le montant d\'un paiement est égal au total imputé sur les factures, sans jamais dépasser le restant dû.',
            'tests' => [
                'tests/Feature/Facturation/EnregistrerPaiementActionTest.php::test_un_montant_recu_superieur_au_total_impute_est_refuse',
                'tests/Feature/Facturation/EnregistrerPaiementActionTest.php::test_un_montant_recu_egal_au_total_impute_sur_deux_factures_les_regle_toutes_les_deux',
                'tests/Feature/Facturation/EnregistrerPaiementActionTest.php::test_impossible_dallouer_plus_que_le_solde_restant_dune_facture',
                'tests/Feature/Facturation/EnregistrerPaiementActionTest.php::test_impossible_dallouer_un_paiement_a_la_facture_dun_autre_client',
            ],
        ],
        'paiements.plusieurs-modes' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Une vente se règle en un ou plusieurs modes (espèces, carte bancaire, chèque, virement, prélèvement, qui n\'est pas proposé à la caisse), d\'un seul geste, chaque part étant un paiement ; à la caisse, les parts et le crédit d\'avoir égalent exactement le total et seule la part en espèces rend la monnaie ; sur une facture, leur somme ne dépasse jamais le restant dû.',
            'tests' => [
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_ticket_se_regle_moitie_en_especes_moitie_par_carte',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_la_somme_des_parts_doit_egaler_le_reste_a_payer',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_seule_la_part_en_especes_rend_la_monnaie',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_une_facture_s_encaisse_en_deux_modes_d_un_seul_geste',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_les_parts_d_une_facture_ne_depassent_jamais_ce_qui_reste_du',
                'tests/Feature/Filament/CaisseTest.php::test_le_prelevement_n_est_pas_propose_a_la_caisse',
                'tests/Unit/ReglementComptantTest.php::test_a_la_caisse_le_prelevement_est_refuse_et_hors_de_la_caisse_chaque_mode_regle',
            ],
        ],
        'paiements.mode-avoir' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Le crédit d\'avoir ne fait entrer aucun argent : il s\'affiche comme le mode « Avoir » (crédit du client imputé d\'office, ou bon d\'avoir dont on saisit le numéro) et s\'enregistre comme une imputation, jamais comme un paiement d\'un mode.',
            'tests' => [
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_le_credit_du_client_s_affiche_comme_avoir_et_s_impute_d_office',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_reglement_par_avoir_ne_cree_aucun_paiement_d_un_mode',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_bon_d_avoir_et_deux_modes_reglent_un_meme_ticket',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_credit_consomme_entre_l_ecran_et_la_validation_refuse_la_vente_sans_rien_enregistrer',
            ],
        ],
        'paiements.contre-passation' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Un paiement erroné se corrige par une contre-passation (paiement opposé, lié à l\'original et signé), puis par le bon paiement ; à la contre-passation, le crédit d\'avoir disponible du client s\'impute aussitôt sur ce qui reste dû, dans la même transaction signée.',
            'tests' => [
                'tests/Feature/Facturation/ContrePasserPaiementActionTest.php::test_la_contre_passation_annule_le_paiement_errone_et_rouvre_la_facture',
                'tests/Feature/Facturation/ContrePasserPaiementActionTest.php::test_un_paiement_ne_se_contre_passe_qu_une_fois',
                'tests/Feature/Facturation/ContrePasserPaiementActionTest.php::test_la_contre_passation_est_signee_dans_la_chaine_des_encaissements',
                'tests/Feature/Facturation/ContrePasserPaiementActionTest.php::test_le_paiement_contre_passe_reste_inalterable_en_base',
                'tests/Feature/Facturation/ReimputationALaContrePassationTest.php::test_le_credit_d_avoir_disponible_s_impute_sur_la_facture_rouverte_par_la_contre_passation',
                'tests/Feature/Facturation/ReimputationALaContrePassationTest.php::test_la_reimputation_est_signee_avec_la_contre_passation_et_le_verificateur_la_retrouve',
            ],
        ],
        'paiements.rien-n-est-rembourse' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Rien n\'est jamais remboursé : un retour donne un crédit, imputé d\'abord sur ce qui reste dû des factures qu\'il corrige, puis sur les autres factures impayées du client, de la plus ancienne à la plus récente (pour le client comptoir, seulement sur les tickets qu\'il corrige).',
            'tests' => [
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionTest.php::test_un_avoir_reduit_le_reste_a_payer_de_sa_facture_impayee',
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionTest.php::test_l_avoir_d_une_facture_payee_regle_la_plus_ancienne_facture_impayee_du_client',
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionTest.php::test_l_avoir_solde_sa_facture_puis_les_suivantes_et_garde_le_reste_en_credit',
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionTest.php::test_l_avoir_d_un_ticket_du_client_comptoir_ne_regle_aucun_autre_ticket_comptoir',
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionTest.php::test_le_credit_ne_regle_jamais_une_facture_d_un_autre_magasin_meme_avec_un_client_id_force',
                'tests/Feature/Facturation/AppliquerAvoirsDisponiblesActionTest.php::test_plusieurs_avoirs_se_consomment_du_plus_ancien_au_plus_recent',
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionConcurrenceTest.php::test_deux_avoirs_simultanes_ne_reglent_jamais_deux_fois_le_meme_reste_du',
            ],
        ],
        'paiements.bon-d-avoir' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Un avoir fait au client comptoir sert de bon d\'avoir : son numéro impute son crédit sur une prochaine vente à la caisse, quel qu\'en soit le client, ou sur un ticket du client comptoir resté dû, avec au moins une part payée, jamais sur une autre facture ; plusieurs bons peuvent régler une même vente.',
            'tests' => [
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_deux_bons_d_avoir_reglent_une_meme_vente',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_ticket_du_client_comptoir_reste_du_se_regle_avec_un_bon_d_avoir_et_une_part',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_bon_d_avoir_ne_regle_hors_de_la_caisse_qu_un_ticket_du_client_comptoir_avec_une_part_payee',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_bon_qui_couvre_tout_ce_que_doit_un_ticket_deja_emis_est_refuse_sans_rien_enregistrer',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_contre_passer_la_part_qui_signe_le_bon_le_rend_et_contre_passer_une_autre_part_le_laisse_impute',
            ],
        ],
        'paiements.facture-en-retard' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Une facture en retard le reste tant qu\'un montant reste dû après l\'échéance.',
            'tests' => [
                'tests/Feature/Facturation/MarquerFacturesEnRetardTest.php::test_une_facture_emise_dont_lecheance_est_depassee_passe_en_retard',
                'tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionTest.php::test_une_facture_en_retard_encore_due_apres_l_imputation_reste_en_retard',
                'tests/Feature/Facturation/ContrePasserPaiementActionTest.php::test_une_facture_echue_rouverte_par_la_contre_passation_est_en_retard',
            ],
        ],
        'paiements.avoirs-partiels-et-multiples' => [
            'puce' => 'Paiements et avoirs',
            'regle' => 'Un avoir crédite les seuls articles choisis, dans la limite de ce qui a été facturé, et plusieurs avoirs successifs peuvent reprendre le reliquat d\'une ligne.',
            'tests' => [
                'tests/Feature/Facturation/AvoirActionsTest.php::test_creer_un_avoir_partiel_ne_credite_que_la_quantite_demandee',
                'tests/Feature/Facturation/AvoirActionsTest.php::test_impossible_de_crediter_plus_que_la_quantite_facturee',
                'tests/Feature/Facturation/AvoirActionsTest.php::test_un_second_avoir_peut_crediter_le_reliquat_dune_ligne_deja_partiellement_creditee',
            ],
        ],

        // Demandes répétées.
        'demandes-repetees.jeton-unique' => [
            'puce' => 'Demandes répétées',
            'regle' => 'Chaque émission, vente à la caisse, paiement, contre-passation, avoir, envoi, fermeture de caisse, clôture du jour et validation d\'achat porte un jeton unique, lié au contenu de la demande ; une demande répétée renvoie le résultat déjà enregistré, sans doublon ; le même jeton avec une demande différente est refusé.',
            'tests' => [
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_une_emission_repetee_avec_le_meme_jeton_renvoie_la_meme_facture_sans_second_numero',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_un_paiement_repete_n_est_enregistre_qu_une_fois',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_un_avoir_repete_n_est_emis_qu_une_fois',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_un_envoi_repete_ne_part_qu_une_fois',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_une_vente_a_la_caisse_repetee_ne_cree_qu_un_ticket_et_un_paiement',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_recharger_la_caisse_apres_une_reponse_perdue_ne_cree_pas_un_second_ticket',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_le_meme_jeton_avec_un_autre_montant_est_refuse',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_un_jeton_reutilise_pour_une_autre_action_ou_par_un_autre_compte_est_refuse',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_une_cloture_manuelle_repetee_ne_cree_qu_une_cloture',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_valider_deux_fois_une_facture_d_achat_n_entre_le_stock_qu_une_fois',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_le_meme_jeton_avec_d_autres_parts_est_refuse',
                'tests/Feature/Facturation/ActionsUniquesCourseConcurrenceTest.php::test_la_demande_qui_perd_la_course_est_annulee_et_rend_le_resultat_de_l_autre',
            ],
        ],
        'demandes-repetees.autres-gestes' => [
            'puce' => 'Demandes répétées',
            'regle' => 'Les autres gestes sont protégés par leur état ou une contrainte d\'unicité ; une action échouée n\'inscrit pas son jeton, et les jetons s\'effacent après 30 jours.',
            'tests' => [
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_fermer_deux_fois_un_magasin_dit_deja_fait_sans_rien_refaire',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_une_action_echouee_n_inscrit_pas_son_jeton',
                'tests/Feature/Facturation/ActionsUniquesTest.php::test_les_jetons_de_plus_de_30_jours_sont_effaces',
                'tests/Feature/Facturation/DevisConversionTest.php::test_impossible_de_convertir_deux_fois_le_meme_devis',
            ],
        ],

        // Vendeurs.
        'vendeurs.qui-vend' => [
            'puce' => 'Vendeurs',
            'regle' => 'Les ventes sont faites par les responsables et les vendeurs, et par un administrateur ou un observateur seulement si on lui a coché les autorisations de vente, jamais par le propriétaire, sauf dans le magasin de démonstration.',
            'tests' => [
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_coche_les_autorisations_de_vente_d_un_vendeur_et_d_un_administrateur',
                'tests/Feature/Societe/AdministrateursTest.php::test_un_administrateur_a_qui_le_proprietaire_coche_la_vente_vend_avec_une_caisse_a_la_fois',
                'tests/Feature/Authorization/ProprietaireSansVenteTest.php::test_le_proprietaire_ne_voit_aucun_bouton_de_vente_a_l_ecran',
            ],
        ],
        'vendeurs.ses-factures' => [
            'puce' => 'Vendeurs',
            'regle' => 'Un vendeur ne voit et n\'encaisse que ses factures, sauf autorisation « voir celles de toute l\'équipe » ; son tableau de bord montre « Mes ventes » ; le chiffre d\'affaires est net des avoirs, en HT avec le TTC à côté.',
            'tests' => [
                'tests/Feature/Facturation/FacturePolicyTest.php::test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_factures',
                'tests/Feature/Facturation/ReglementEnPlusieursModesTest.php::test_un_vendeur_n_encaisse_en_plusieurs_modes_que_ses_factures',
                'tests/Feature/Filament/FacturesApercuWidgetTest.php::test_un_vendeur_ne_lit_que_ses_ventes',
                'tests/Feature/Filament/FacturesApercuWidgetTest.php::test_le_chiffre_d_affaires_du_magasin_est_net_des_avoirs',
                'tests/Feature/Filament/FacturesApercuWidgetTest.php::test_le_widget_additionne_le_chiffre_daffaires_de_la_periode_en_ht',
            ],
        ],
        'vendeurs.releve-de-caisse' => [
            'puce' => 'Vendeurs',
            'regle' => 'Le relevé de caisse de chaque compte qui vend (« Fermer ma caisse ») sépare le TTC vendu, les encaissements par mode, le crédit d\'avoir utilisé et le reste dû.',
            'tests' => [
                'tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php::test_le_releve_montre_le_vendu_l_encaisse_par_mode_le_credit_d_avoir_et_le_reste_du',
                'tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php::test_un_vendeur_ne_voit_que_ses_propres_releves',
                'tests/Feature/Facturation/FermerSaCaisseActionTest.php::test_le_releve_part_de_la_plus_ancienne_vente_qu_il_compte',
            ],
        ],
        'vendeurs.tableau-de-bord' => [
            'puce' => 'Vendeurs',
            'regle' => 'Le tableau de bord dit le rôle de la personne après « Bonjour », puis réunit « À faire » et « À surveiller », chaque ligne avec un nombre, une phrase et le bouton qui mène à la fiche d\'un document seul ou à la liste filtrée ; ce qui se fera tout seul est en gris ; aucun menu n\'affiche de nombre ; un compte d\'un cabinet ou un expert-comptable de la société n\'en voit rien.',
            'tests' => [
                'tests/Feature/Filament/BienvenueApercuTest.php::test_le_proprietaire_lit_proprietaire',
                'tests/Feature/Filament/BienvenueApercuTest.php::test_un_vendeur_lit_vendeur',
                'tests/Feature/Filament/BienvenueApercuTest.php::test_un_responsable_lit_responsable_et_son_magasin',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_un_brouillon_de_facture_entre_dans_l_a_faire_des_sa_creation',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_un_devis_envoye_entre_sept_jours_avant_la_fin_de_sa_validite',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_un_devis_accepte_entre_tout_de_suite_et_se_transforme_par_qui_cree_les_factures',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_un_seul_produit_en_stock_negatif_ou_bas_mene_a_sa_fiche',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_ce_qui_se_fera_tout_seul_est_en_gris_apres_le_reste',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_aucun_menu_n_affiche_de_nombre',
                'tests/Feature/Filament/AFaireDuMagasinTest.php::test_l_a_faire_ne_lit_que_le_magasin_actif',
                'tests/Feature/Filament/FacturesAEncaisserTest.php::test_un_compte_de_cabinet_ne_voit_rien_de_l_a_faire',
                'tests/Feature/Cabinets/AccesDeLExpertComptableTest.php::test_l_expert_comptable_ne_voit_rien_de_l_a_faire_meme_avec_les_factures_de_toute_l_equipe',
                'tests/Browser/Parcours/AFaireTest.php › le vendeur encaisse sa facture depuis « À faire » et ouvre la liste filtrée ; le propriétaire la surveille avec « Voir »',
            ],
        ],
        'vendeurs.factures-a-encaisser' => [
            'puce' => 'Vendeurs',
            'regle' => 'Les factures à encaisser figurent avec leur nombre, leur restant dû, celles en retard et celles dont un paiement a été contre-passé : toutes celles du magasin pour le propriétaire, un responsable et un administrateur qui voit celles de toute l\'équipe, les siennes pour tout compte qui encaisse ; dans l\'espace de la société, magasin par magasin, sans nom de client.',
            'tests' => [
                'tests/Feature/Filament/FacturesAEncaisserTest.php::test_le_proprietaire_et_le_responsable_voient_toutes_les_factures_du_magasin',
                'tests/Feature/Filament/FacturesAEncaisserTest.php::test_un_administrateur_ne_voit_l_alerte_que_s_il_voit_toutes_les_factures',
                'tests/Feature/Filament/FacturesAEncaisserTest.php::test_une_facture_rouverte_par_une_contre_passation_apparait_avec_son_badge',
                'tests/Feature/Societe/FacturesAEncaisserDeLaSocieteTest.php::test_le_proprietaire_voit_une_ligne_par_magasin_reel',
                'tests/Feature/Societe/FacturesAEncaisserDeLaSocieteTest.php::test_un_administrateur_d_une_selection_ne_voit_que_ses_magasins',
                'tests/Feature/Societe/FacturesAEncaisserDeLaSocieteTest.php::test_la_lecture_ne_rend_aucun_nom_de_client',
            ],
        ],
        'vendeurs.a-faire-de-la-societe' => [
            'puce' => 'Vendeurs',
            'regle' => 'Dans l\'espace d\'administration, le propriétaire et chaque administrateur voient ce qui reste à faire dans chaque magasin, en nombres par groupe, sans nom de client ni numéro de document, seulement là où ils voient les documents comptés.',
            'tests' => [
                'tests/Feature/Societe/AFaireDeLaSocieteTest.php::test_le_proprietaire_lit_les_nombres_de_chaque_magasin_sans_nom_ni_numero',
                'tests/Feature/Societe/AFaireDeLaSocieteTest.php::test_un_administrateur_ne_lit_que_sa_portee_et_les_groupes_qu_il_voit',
                'tests/Feature/Societe/AFaireDeLaSocieteTest.php::test_les_nombres_sont_isoles_entre_societes_et_sans_le_cabinet',
            ],
        ],
        'vendeurs.avoirs-non-utilises' => [
            'puce' => 'Vendeurs',
            'regle' => 'Le tableau de bord montre, à qui voit les avoirs du magasin, les avoirs non utilisés (bons d\'avoir du client comptoir et crédits des clients), et, dans l\'espace d\'administration, leur nombre et leur montant magasin par magasin, sans nom de client ni numéro.',
            'tests' => [
                'tests/Feature/Filament/AvoirsNonUtilisesTest.php::test_un_bon_d_avoir_en_partie_utilise_montre_ce_qui_reste',
                'tests/Feature/Filament/AvoirsNonUtilisesTest.php::test_le_credit_d_un_client_professionnel_se_montre_et_un_credit_entierement_impute_jamais',
                'tests/Feature/Filament/AvoirsNonUtilisesTest.php::test_les_avoirs_non_utilises_se_montrent_a_qui_voit_les_avoirs_et_jamais_au_cabinet',
                'tests/Feature/Filament/AvoirsNonUtilisesTest.php::test_l_espace_de_la_societe_montre_leur_nombre_et_leur_montant_par_magasin_isoles',
            ],
        ],

        // Achats et stock.
        'achats.produit-ne-d-un-achat-ou-d-un-inventaire' => [
            'puce' => 'Achats et stock',
            'regle' => 'Seule une société « Commerce ou atelier » a des produits ; un produit naît seulement de la validation d\'une facture d\'achat ou d\'un inventaire d\'ouverture, qui exige aussi le droit de créer des produits ; aucun écran ni aucun autre code ne crée un produit autrement.',
            'tests' => [
                'tests/Feature/Catalogue/OrigineDesProduitsTest.php::test_un_produit_cree_hors_d_un_achat_ou_d_un_inventaire_est_refuse',
                'tests/Feature/Catalogue/OrigineDesProduitsTest.php::test_valider_un_achat_cree_ses_produits',
                'tests/Feature/Catalogue/OrigineDesProduitsTest.php::test_valider_un_inventaire_d_ouverture_cree_ses_produits',
                'tests/Feature/Catalogue/OrigineDesProduitsTest.php::test_une_requete_livewire_forgee_vers_la_creation_d_un_produit_ne_cree_rien',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_valider_une_facture_qui_cree_des_produits_exige_le_droit_de_creer_des_produits',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_valider_une_facture_sans_nouveau_produit_n_exige_pas_le_droit_de_creer_des_produits',
                'tests/Feature/Authorization/GardesDesTypesDActiviteTest.php::test_la_base_refuse_produit_facture_d_achat_avoir_fournisseur_et_inventaire_sans_articles',
            ],
        ],
        'achats.piece-du-fournisseur' => [
            'puce' => 'Achats et stock',
            'regle' => 'Une facture d\'achat ou un avoir fournisseur saisi à la main porte la pièce du fournisseur, jointe à la saisie, jamais retirée après la validation, et la date de la facture du fournisseur ; sans elle, il se valide mais reste marqué « pièce du fournisseur manquante » jusqu\'à ce qu\'elle soit jointe ; un achat reçu par l\'API n\'en exige pas.',
            'tests' => [
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_une_facture_saisie_a_la_main_exige_la_date_de_la_facture_du_fournisseur',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_une_piece_pdf_se_joint_a_la_saisie_rangee_par_magasin_avec_son_nom_reecrit_et_son_empreinte',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_sans_piece_la_facture_s_enregistre_et_se_valide_marquee_jusqu_a_l_ajout_de_la_premiere',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_une_piece_d_une_facture_validee_ou_d_un_avoir_emis_ne_se_retire_jamais',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_un_achat_recu_par_l_api_n_est_jamais_marque_et_peut_recevoir_une_piece',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_un_avoir_fournisseur_porte_sa_note_d_avoir_ou_en_est_marque_a_son_emission',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_une_piece_d_un_magasin_est_introuvable_dans_un_autre',
                'tests/Feature/Achats/PiecesDuFournisseurConcurrenceTest.php::test_un_effacement_de_piece_pendant_la_validation_de_sa_facture_l_attend_puis_est_refuse',
            ],
        ],
        'achats.inventaire-d-ouverture' => [
            'puce' => 'Achats et stock',
            'regle' => 'Un inventaire d\'ouverture se saisit et se valide jusqu\'à la première vente du magasin, par une personne autorisée à valider les achats et à créer des produits ; sa validation crée ses produits, entre leur stock au coût saisi, sans TVA ni fournisseur, et s\'inscrit au journal d\'audit.',
            'tests' => [
                'tests/Feature/Stock/InventaireOuvertureTest.php::test_un_produit_nait_de_la_validation_d_un_inventaire_d_ouverture_avec_son_cout',
                'tests/Feature/Stock/InventaireOuvertureTest.php::test_l_inventaire_d_ouverture_entre_le_stock_au_cout_saisi_et_s_inscrit_au_journal_d_audit',
                'tests/Feature/Stock/InventaireOuvertureTest.php::test_un_inventaire_d_ouverture_n_est_plus_possible_apres_la_premiere_vente',
                'tests/Feature/Stock/InventaireOuvertureTest.php::test_valider_un_inventaire_exige_les_droits_choisis',
                'tests/Feature/Stock/InventaireOuvertureTest.php::test_un_compte_de_cabinet_consulte_un_inventaire_et_ne_le_saisit_que_si_le_client_lui_accorde_achats_et_produits',
                'tests/Feature/Stock/InventaireOuvertureConcurrenceTest.php::test_une_premiere_vente_simultanee_passe_avant_ou_apres_la_validation_d_un_inventaire',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_le_cout_d_un_inventaire_d_ouverture_se_garde_a_six_decimales',
            ],
        ],
        'achats.fiche-produit-et-fournisseur-du-dernier-achat' => [
            'puce' => 'Achats et stock',
            'regle' => 'Un produit se modifie dans « Produits », sauf son fournisseur et ses données d\'achat, que seuls les achats, les retours et les inventaires font évoluer ; le fournisseur d\'un produit est celui de son dernier achat validé, même saisi à la main ; modifier une facture d\'achat validée exige l\'autorisation de valider.',
            'tests' => [
                'tests/Feature/Filament/ProduitResourceTest.php::test_le_fournisseur_et_le_cout_ne_se_modifient_pas_dans_produits',
                'tests/Feature/Filament/ProduitResourceTest.php::test_un_fournisseur_force_sur_la_fiche_est_ignore',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_valider_une_facture_dachat_rattache_le_produit_a_son_fournisseur',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_un_achat_saisi_a_la_main_donne_son_fournisseur_a_un_produit_recu_par_api',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_corriger_une_ancienne_facture_garde_le_fournisseur_du_dernier_achat',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_un_produit_retire_de_sa_derniere_facture_reprend_le_fournisseur_de_l_achat_d_avant_ou_aucun',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_sans_droit_de_valider_une_facture_dachat_validee_ne_se_corrige_pas',
                'tests/Feature/Authorization/FactureAchatPolicyTest.php::test_avec_le_droit_de_valider_une_facture_validee_se_corrige',
            ],
        ],
        'achats.une-ligne-par-produit' => [
            'puce' => 'Achats et stock',
            'regle' => 'Une facture d\'achat et un inventaire d\'ouverture n\'ont qu\'une ligne par produit (décision du 26/10/2026) : choisir de nouveau un produit ajoute sa quantité à sa ligne, au prix moyen, et PILIQI refuse, quel que soit l\'écran ou l\'API, un achat ou un inventaire qui porte deux lignes du même produit.',
            'tests' => [
                'tests/Feature/Facturation/LignesUniquesTest.php::test_une_facture_d_achat_avec_deux_lignes_du_meme_produit_est_refusee',
                'tests/Feature/Facturation/LignesUniquesTest.php::test_un_identifiant_ecrit_autrement_designe_le_meme_produit_d_une_facture_d_achat',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_un_produit_ajoute_de_nouveau_rejoint_sa_ligne_au_prix_moyen',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_avec_le_bareme_le_produit_ajoute_de_nouveau_prend_le_prix_de_vente_du_prix_moyen',
                'tests/Browser/Parcours/UnProduitUneLigneTest.php › sur une facture d’achat, le responsable ajoute de nouveau un produit, dont la ligne compte la quantité totale au prix moyen, et « Modifier » une autre ligne ne lui prend pas ce produit',
                'tests/Browser/Parcours/UnProduitUneLigneTest.php › sur un inventaire d’ouverture, le propriétaire choisit sur une seconde ligne un produit déjà inventorié : il rejoint sa ligne, dont la quantité monte',
            ],
        ],
        'achats.avoir-fournisseur' => [
            'puce' => 'Achats et stock',
            'regle' => 'Un avoir fournisseur se crée à la main, depuis le menu « Avoirs fournisseur » ou depuis la fiche d\'une facture d\'achat ; il ne porte que des lignes d\'une facture d\'achat validée du magasin, chacune liée à sa ligne d\'achat, dans la limite de ce qui reste retournable et en stock ; il garde le n° de l\'avoir du fournisseur, ne modifie jamais la facture d\'achat, s\'inscrit au journal d\'audit, et la fiche de la facture d\'achat montre sous chaque ligne, en orange, ce qui en a été retourné et par quel avoir.',
            'tests' => [
                'tests/Feature/Filament/AvoirFournisseurResourceTest.php::test_un_avoir_fournisseur_se_cree_depuis_le_menu_avoirs_fournisseur',
                'tests/Feature/Filament/AvoirFournisseurResourceTest.php::test_un_avoir_fournisseur_se_cree_depuis_la_fiche_de_la_facture_d_achat',
                'tests/Feature/Filament/AvoirFournisseurResourceTest.php::test_le_choix_d_une_facture_d_achat_se_limite_au_magasin_et_aux_factures_validees_retournables',
                'tests/Feature/Filament/AvoirFournisseurResourceTest.php::test_chaque_retour_s_affiche_en_orange_sous_sa_ligne_avec_le_numero_de_l_avoir',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_impossible_de_creer_un_avoir_fournisseur_sur_une_facture_dachat_en_brouillon',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_une_ligne_ne_retourne_pas_plus_que_le_stock_restant_apres_des_ventes',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_impossible_de_retourner_plus_que_la_quantite_recue',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_une_ligne_qui_nappartient_pas_a_la_facture_dachat_est_refusee',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_le_numero_de_l_avoir_du_fournisseur_s_enregistre',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_l_emission_d_un_avoir_fournisseur_s_inscrit_au_journal_d_audit',
                'tests/Feature/Stock/RetoursConcurrenceTest.php::test_deux_avoirs_fournisseur_simultanes_ne_retournent_jamais_plus_que_la_ligne_d_achat',
            ],
        ],
        'achats.retour-au-cout-de-la-ligne' => [
            'puce' => 'Achats et stock',
            'regle' => 'Un retour au fournisseur sort au coût de la ligne d\'achat retournée et recalcule le coût moyen ; la valeur d\'un stock ne descend jamais sous 0 €.',
            'tests' => [
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_un_retour_fournisseur_sort_au_cout_de_sa_ligne_d_achat',
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_le_cmup_apres_un_retour_s_arrondit_une_seule_fois',
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_un_retour_qui_rendrait_la_valeur_du_stock_negative_la_ramene_a_zero',
                'tests/Feature/Achats/AvoirFournisseurActionsTest.php::test_un_retour_sort_du_stock_au_cout_de_sa_ligne_et_recalcule_le_cmup',
            ],
        ],
        'achats.grand-livre-du-stock' => [
            'puce' => 'Achats et stock',
            'regle' => 'Le grand livre du stock est en ajout seul et le stock d\'un produit en est la somme, tenue par la base : chaque mouvement désigne le document de son magasin qui le justifie, dans la limite de ses lignes, au nom de qui a fait ce document ; la base refuse tout autre mouvement et toute autre écriture du stock, donc tout ajustement sans document.',
            'tests' => [
                'tests/Feature/Stock/GrandLivreEnBaseTest.php::test_la_base_refuse_de_reecrire_ou_de_supprimer_un_mouvement_du_grand_livre',
                'tests/Feature/Stock/GrandLivreEnBaseTest.php::test_le_stock_d_un_produit_ne_change_que_par_un_mouvement_du_grand_livre',
                'tests/Feature/Stock/ProvenanceDuStockTest.php::test_la_base_refuse_un_mouvement_sans_document',
                'tests/Feature/Stock/ProvenanceDuStockTest.php::test_la_base_refuse_un_mouvement_justifie_par_un_document_qui_ne_le_justifie_pas',
                'tests/Feature/Stock/ProvenanceDuStockTest.php::test_la_base_refuse_un_mouvement_au_dela_de_son_document',
                'tests/Feature/Stock/ProvenanceDuStockTest.php::test_un_mouvement_survient_a_l_instant_de_son_document_au_nom_de_son_auteur',
                'tests/Feature/Stock/ProvenanceDuStockTest.php::test_la_cession_et_son_annulation_ne_sortent_et_ne_rentrent_que_tout_le_stock_une_fois',
                'tests/Feature/Stock/ProvenanceDuStockConcurrenceTest.php::test_un_second_mouvement_au_dela_de_la_ligne_attend_le_premier_puis_est_refuse',
            ],
        ],
        'achats.verification-de-chaque-nuit' => [
            'puce' => 'Achats et stock',
            'regle' => 'La vérification d\'intégrité de chaque nuit rapproche le stock, le coût moyen et le fournisseur de chaque produit de son grand livre et des achats ; un écart, ou un achat validé sans trace au journal d\'audit, prévient l\'éditeur et le propriétaire de la société, sans rien bloquer.',
            'tests' => [
                'tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php::test_un_stock_qui_ne_correspond_plus_a_son_grand_livre_est_releve',
                'tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php::test_un_achat_valide_sans_son_entree_au_journal_d_audit_est_releve',
                'tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php::test_les_parcours_legitimes_du_stock_ne_presentent_aucun_ecart',
                'tests/Feature/CaisseSecurisee/VerifierIntegriteActionTest.php::test_un_ecart_dans_le_stock_previent_le_proprietaire_sans_rien_bloquer',
            ],
        ],
        'achats.vente-sans-stock' => [
            'puce' => 'Achats et stock',
            'regle' => 'Une vente au-delà du stock disponible est refusée, sauf pour une personne autorisée à « vendre sans stock » dans ce magasin (les responsables toujours, le propriétaire seulement dans la démonstration, jamais précochée pour les autres, jamais pour un cabinet lié) : elle voit un avertissement qui se renouvelle si le stock a encore baissé, une alerte part, la quantité négative reste visible jusqu\'à sa régularisation, la vente se journalise, et des ventes simultanées laissent le stock cohérent.',
            'tests' => [
                'tests/Feature/Stock/VenteSansStockTest.php::test_sans_l_autorisation_une_vente_au_dela_du_stock_est_refusee_sans_numero_ni_signature',
                'tests/Feature/Stock/VenteSansStockTest.php::test_avec_l_autorisation_la_vente_exige_la_confirmation_de_l_avertissement',
                'tests/Feature/Stock/VenteSansStockTest.php::test_une_confirmation_devenue_insuffisante_redemande_l_avertissement',
                'tests/Feature/Stock/VenteSansStockTest.php::test_la_vente_confirmee_rend_le_stock_negatif_alerte_et_se_journalise',
                'tests/Feature/Stock/VenteSansStockTest.php::test_le_stock_negatif_reste_visible_jusqu_a_un_achat_qui_le_regularise',
                'tests/Feature/Stock/VenteSansStockTest.php::test_un_responsable_vend_sans_stock_apres_l_avertissement',
                'tests/Feature/Stock/VenteSansStockTest.php::test_vendre_sans_stock_n_est_precoche_dans_aucun_modele_et_jamais_accordee_a_un_cabinet_lie',
                'tests/Feature/Stock/VenteSansStockConcurrenceTest.php::test_deux_ventes_simultanees_de_la_derniere_unite_sans_autorisation_n_en_laissent_passer_qu_une',
                'tests/Feature/Stock/VenteSansStockConcurrenceTest.php::test_deux_ventes_simultanees_avec_autorisation_passent_toutes_deux_et_le_stock_reste_coherent',
            ],
        ],
        'achats.tva-des-achats-recopiee' => [
            'puce' => 'Achats et stock',
            'regle' => 'Un achat garde les montants qu\'écrit la facture du fournisseur (rattachée aussi à « Prix et TVA des ventes ») : PU HT à six décimales, HT et TVA proposés puis remplacés, acceptés jusqu\'à 0,02 € par ligne et 1 centime par ligne du taux, au-delà une confirmation inscrite au journal d\'audit marque « TVA à vérifier » ; l\'avoir fournisseur garde de même sa note d\'avoir, sans jamais rendre plus que la TVA restante du taux.',
            'tests' => [
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_un_pu_ht_a_six_decimales_se_garde_tel_quel_et_fait_le_cout_moyen',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_un_ht_de_ligne_s_accepte_jusqu_a_deux_centimes_d_ecart',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_une_tva_s_accepte_jusqu_a_un_centime_par_ligne_du_taux',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_un_ecart_confirme_s_enregistre_marque_la_facture_et_s_inscrit_au_journal_d_audit',
                'tests/Feature/Achats/TvaDuFournisseurTest.php::test_la_tva_retenue_se_repartit_sans_ligne_negative_ni_au_dela_de_son_ht',
                'tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php::test_la_note_d_avoir_du_fournisseur_prime_dans_la_tolerance',
                'tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php::test_un_avoir_ne_rend_jamais_plus_que_la_tva_restante_du_taux',
                'tests/Feature/Achats/TvaDuFournisseurConcurrenceTest.php::test_deux_confirmations_simultanees_du_meme_ecart_n_en_inscrivent_qu_une',
            ],
        ],
        // E-mails.
        'emails.alertes-de-stock' => [
            'puce' => 'E-mails',
            'regle' => 'Les alertes de stock bas ou négatif vont au propriétaire, aux responsables du magasin et à toute autre personne qui a accès au magasin et y est autorisée à gérer le stock (administrateurs, vendeurs et observateurs), jamais à un compte d\'un cabinet.',
            'tests' => [
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_l_alerte_de_stock_va_au_proprietaire_et_aux_personnes_autorisees_du_magasin',
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_l_alerte_de_stock_va_aux_responsables_du_magasin_sans_case',
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_l_alerte_de_stock_ne_va_ni_a_un_compte_suspendu_ni_retire_ni_a_un_administrateur_hors_de_sa_selection_ni_a_un_compte_du_cabinet_lie',
                'tests/Feature/Stock/StockLedgerServiceTest.php::test_un_stock_deja_bas_ne_previent_pas_a_chaque_mouvement',
            ],
        ],

        // Caisse et clôtures.
        'caisse.calendrier-du-magasin' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Le calendrier d\'un magasin est celui de son territoire : sa journée de caisse et ses clôtures, la date de ses documents et l\'année de ses numéros suivent son heure locale, et minuit est son minuit.',
            'tests' => [
                'tests/Feature/Calendrier/HeureLocaleTest.php::test_un_magasin_de_la_martinique_vend_a_20_heures_au_jour_local_et_cloture_a_minuit_local',
                'tests/Feature/Calendrier/HeureLocaleTest.php::test_la_caisse_de_la_martinique_refuse_toute_vente_apres_sa_cloture_jusqu_a_minuit_local',
                'tests/Feature/Calendrier/HeureLocaleTest.php::test_un_magasin_de_la_reunion_numerote_sur_la_nouvelle_annee_des_minuit_local',
                'tests/Feature/Calendrier/NuitDeParisTest.php::test_une_vente_apres_minuit_a_paris_appartient_a_la_journee_de_paris',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_journee_de_caisse_va_de_minuit_a_minuit_a_paris',
            ],
        ],
        'caisse.cloture-jamais-bloquee' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Le magasin clôture sa journée par un bouton, selon les autorisations, sans jamais être bloqué par une caisse personnelle restée ouverte : la clôture la ferme d\'office avec la mention « fermée automatiquement », son minuit ferme toute caisse personnelle qui ne l\'a pas été, et le propriétaire, un responsable ou une personne autorisée à clôturer peut la fermer pour le vendeur ; la fenêtre prévient qu\'ensuite plus aucune vente n\'est possible ce jour-là, et la caisse refuse toute vente jusqu\'à son minuit.',
            'tests' => [
                'tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php::test_la_cloture_du_jour_n_est_jamais_bloquee_et_ferme_d_office_les_caisses_personnelles_ouvertes',
                'tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php::test_le_releve_ferme_d_office_porte_la_mention_fermee_automatiquement',
                'tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php::test_minuit_ferme_les_caisses_personnelles_restees_ouvertes_meme_apres_une_cloture_manuelle',
                'tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php::test_un_responsable_ferme_la_caisse_d_un_vendeur',
                'tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php::test_un_vendeur_ne_ferme_pas_la_caisse_d_un_autre',
                'tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php::test_un_responsable_ne_ferme_jamais_la_caisse_d_un_compte_d_un_autre_magasin',
                'tests/Feature/Filament/ClotureCaisseResourceTest.php::test_la_modale_annonce_les_ventes_du_jour_et_previent_qu_ensuite_plus_aucune_vente_n_est_possible',
                'tests/Feature/Filament/ClotureCaisseResourceTest.php::test_le_bouton_reste_actif_et_la_fenetre_nomme_les_caisses_qui_seront_fermees_automatiquement',
                'tests/Feature/Filament/ClotureCaisseResourceTest.php::test_le_bouton_est_absent_pour_un_vendeur_sans_l_autorisation_de_cloturer',
                'tests/Feature/Calendrier/NuitDeParisTest.php::test_la_caisse_refuse_une_vente_apres_la_cloture_du_jour_de_paris',
            ],
        ],
        'caisse.cloture-automatique-et-rattrapage' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Sans clôture manuelle, la clôture automatique le fait, passé son minuit, et les journées manquées sont rattrapées dans l\'ordre ; un ticket Z est produit chaque jour du calendrier à partir de la première vente, même à zéro, tant que le magasin n\'est pas fermé, numéroté selon l\'année de la journée clôturée.',
            'tests' => [
                'tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php::test_cloture_la_veille_pour_un_magasin_qui_ne_la_pas_fait_lui_meme',
                'tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php::test_ne_cloture_jamais_le_jour_meme',
                'tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php::test_aucune_journee_n_est_cloturee_avant_la_premiere_vente',
                'tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php::test_rattrape_dans_l_ordre_les_journees_que_la_commande_a_manquees',
                'tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php::test_la_cloture_du_31_decembre_est_numerotee_dans_la_serie_de_son_annee',
                'tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php::test_la_panne_d_un_magasin_n_empeche_pas_de_cloturer_les_autres',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_cloture_a_zero_inscrit_aussi_l_evenement_50',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_journee_dont_la_veille_n_est_pas_cloturee_est_refusee',
                'tests/Feature/Calendrier/IsolationDesCalendriersTest.php::test_un_passage_horaire_ne_clot_que_les_magasins_dont_le_minuit_est_passe',
            ],
        ],
        'caisse.contenu-de-la-cloture' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Chaque clôture du jour garde, calculés une seule fois depuis les montants enregistrés et jamais recalculés : ventes aux particuliers et leurs avoirs par taux, puis leur net, base de l\'e-reporting ; factures aux professionnels et leurs avoirs à titre d\'information ; encaissements par mode, contre-passations déduites, chaque paiement dans une seule clôture ; crédit d\'avoir utilisé à part ; grands totaux perpétuels.',
            'tests' => [
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_agrege_uniquement_les_ventes_a_des_clients_particuliers',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_la_cloture_separe_les_ventes_et_les_avoirs_par_taux',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_les_factures_aux_professionnels_sont_dans_le_recapitulatif_mais_pas_dans_le_net_declare',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_la_cloture_totalise_les_encaissements_de_la_journee_par_mode_de_paiement',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_contre_passation_se_deduit_du_mode_du_paiement_contre_passe',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_un_paiement_enregistre_apres_la_cloture_compte_dans_la_cloture_suivante',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_un_paiement_ne_compte_jamais_dans_deux_clotures',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_le_credit_d_avoir_impute_se_montre_a_part_des_encaissements_par_mode',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_la_cloture_reprend_les_montants_enregistres_sans_rien_recalculer',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_l_evenement_50_porte_les_grands_totaux_perpetuels_du_registre',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_deux_magasins_ne_melangent_jamais_leurs_encaissements',
            ],
        ],
        'caisse.ticket-z-inalterable-et-signe' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Un ticket Z s\'écrit en une seule fois, numéroté, ne se modifie ni ne se supprime ; tout son contenu, séquences comprises, est signé au journal des événements ; un ticket Z qui n\'a pas été produit par la clôture de sa journée se retire sur pièces par la procédure de l\'éditeur, puis le vrai ticket Z de la journée est produit.',
            'tests' => [
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_numerote_la_cloture',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_journee_deja_cloturee_ne_peut_pas_letre_deux_fois',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_cloture_faite_ne_se_modifie_plus_ni_ne_se_supprime',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_l_evenement_50_signe_tout_le_recapitulatif_et_les_sequences',
                'tests/Feature/Editeur/RetirerUnTicketZNonSigneTest.php::test_la_procedure_retire_le_ticket_z_non_signe_puis_produit_le_vrai_ticket_z_de_sa_journee',
                'tests/Feature/Editeur/RetirerUnTicketZNonSigneTest.php::test_seul_un_ticket_z_que_rien_ne_signe_se_retire',
                'tests/Feature/Editeur/RetirerUnTicketZNonSigneTest.php::test_la_commande_refuse_sans_confirmation_ni_deux_operateurs_distincts',
                'tests/Feature/CaisseSecurisee/RegistreFiscalTest.php::test_chaque_enregistrement_signe_la_signature_du_precedent_de_sa_chaine',
                'tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php::test_un_registre_intact_ne_presente_aucune_anomalie',
            ],
        ],
        'caisse.fiche-de-la-cloture-et-apercu-de-l-e-reporting' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'La fiche d\'une clôture ouvre chaque ticket et chaque avoir qu\'elle compte pour qui peut voir ce document ; une personne qui ne voit que ses ventes y voit les totaux du magasin et le détail de ses seuls documents ; la fiche montre en français l\'aperçu de ce que l\'e-reporting déclarera pour la journée, opérations non soumises à la TVA en France comprises : une vente en franchise en base, comme une vente en France à 0 % hors franchise, s\'y range selon la nature de sa ligne, au taux 0 %, et un export ou une livraison vers un DOM à un particulier parmi les opérations non soumises à la TVA en France, au taux 0 %.',
            'tests' => [
                'tests/Feature/Filament/ClotureCaisseResourceTest.php::test_les_numeros_des_tickets_et_des_avoirs_ouvrent_leur_fiche',
                'tests/Feature/Filament/ClotureCaisseResourceTest.php::test_un_vendeur_qui_ne_voit_que_ses_ventes_ne_voit_dans_la_cloture_que_le_detail_de_ses_documents',
                'tests/Feature/Filament/ClotureCaisseResourceTest.php::test_la_fiche_montre_l_apercu_de_l_e_reporting_en_francais',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_journee_donne_une_ligne_par_categorie_et_par_taux',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_l_apercu_egale_le_net_de_la_cloture',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_vente_sans_tva_francaise_se_range_dans_les_operations_non_soumises_au_taux_zero',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_vente_en_franchise_en_base_se_range_par_la_nature_de_sa_ligne',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_vente_en_france_a_zero_pour_cent_se_range_par_la_nature_de_sa_ligne',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_journee_sans_operation_n_a_rien_a_declarer',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_l_apercu_ne_lit_que_les_documents_du_magasin_de_la_cloture',
            ],
        ],
        'caisse.menu-ma-caisse-ou-les-caisses' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Le menu des relevés de caisse s\'appelle « Ma caisse » pour qui tient une caisse sans voir les relevés de toute l\'équipe, « Les caisses » pour les autres, qui y voient aussi les caisses encore ouvertes ; dans l\'espace d\'administration, la page « Les caisses » réunit les relevés et les caisses ouvertes de chaque magasin réel de la portée où la personne voit les relevés de toute l\'équipe.',
            'tests' => [
                'tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php::test_le_menu_s_appelle_les_caisses_sauf_pour_qui_ne_voit_que_sa_caisse',
                'tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php::test_les_caisses_ouvertes_s_affichent_en_tete_de_la_page_des_caisses',
                'tests/Feature/Societe/CaissesDeLaSocieteTest.php::test_le_proprietaire_voit_les_releves_et_les_caisses_ouvertes_de_chaque_magasin_reel',
                'tests/Feature/Calendrier/IsolationDesCalendriersTest.php::test_les_caisses_de_la_societe_ne_lisent_que_les_magasins_de_la_portee_quel_que_soit_leur_fuseau',
            ],
        ],
        'caisse.journal-des-evenements' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'L\'abandon d\'une vente, chaque ligne retirée et chaque baisse du prix d\'une ligne avant validation s\'inscrivent au journal des événements, comme chaque clôture du jour avec son numéro et ses totaux ; les simples gestes d\'écran ne s\'y inscrivent pas.',
            'tests' => [
                'tests/Feature/CaisseSecurisee/JournalEvenementsTest.php::test_supprimer_un_brouillon_inscrit_la_vente_abandonnee',
                'tests/Feature/CaisseSecurisee/JournalEvenementsTest.php::test_baisser_le_prix_d_une_ligne_avant_validation_s_inscrit_au_journal',
                'tests/Feature/CaisseSecurisee/JournalEvenementsTest.php::test_monter_le_prix_d_un_service_d_un_brouillon_ou_en_ajouter_ne_retire_rien',
                'tests/Feature/Filament/CaisseTest.php::test_retirer_une_ligne_renseignee_du_panier_l_inscrit_au_journal',
                'tests/Feature/Filament/UnProduitUneLigneTest.php::test_completer_une_ligne_n_inscrit_rien_au_journal_des_evenements',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_chaque_cloture_inscrit_l_evenement_50_avec_son_numero_et_ses_totaux',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_une_cloture_refusee_n_inscrit_aucun_evenement',
            ],
        ],
        'caisse.exercice-de-la-societe' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Le mois de fin d\'exercice, réglage de la société, est verrouillé dès la première vente de l\'un de ses magasins ; seul un changement légal sur procès-verbal le modifie ensuite, par la procédure de l\'éditeur, journalisée dans chaque magasin, avec l\'organe qui l\'a décidé, la date et la pièce de la décision, le nom d\'un expert-comptable consulté étant facultatif, sans toucher un exercice clos ni repousser une clôture obligatoire : seul un exercice raccourci, terminé le dernier jour d\'un mois, est accepté (T28.1, T28.2).',
            'tests' => [
                'tests/Feature/Societe/ParametresFiscauxSocieteTest.php::test_le_mois_de_fin_d_exercice_se_verrouille_des_la_premiere_vente_d_un_magasin',
                'tests/Feature/Editeur/ChangerFinExerciceTest.php::test_hors_procedure_le_mois_reste_verrouille_apres_la_premiere_vente',
                'tests/Feature/Editeur/ChangerFinExerciceTest.php::test_le_changement_legal_s_inscrit_dans_chaque_magasin_avec_sa_piece',
                'tests/Feature/Editeur/ChangerFinExerciceTest.php::test_une_fin_avant_la_derniere_cloture_d_exercice_est_refusee',
                'tests/Feature/Editeur/ChangerFinExerciceTest.php::test_une_fin_qui_repousserait_la_cloture_est_refusee',
                'tests/Feature/Editeur/ChangerFinExerciceTest.php::test_un_changement_sans_expert_comptable_consulte_est_accepte',
                'tests/Feature/Editeur/ChangerFinExerciceTest.php::test_un_changement_sans_organe_de_la_decision_est_refuse',
            ],
        ],
        'caisse.fermeture-et-reouverture-du-magasin' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'À la fermeture d\'un magasin, ses journées en attente sont clôturées et plus aucun ticket Z n\'est produit tant qu\'il est fermé ; à sa réouverture, sa caisse reprend au premier jour non clôturé, avec le même registre, les mêmes séries et les mêmes grands totaux.',
            'tests' => [
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_la_fermeture_cloture_les_journees_en_attente_et_s_inscrit_au_jet_270',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_aucun_ticket_z_n_est_produit_pour_les_jours_de_fermeture',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_la_reouverture_reprend_le_meme_registre_les_memes_series_et_les_memes_grands_totaux',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_le_premier_ticket_z_apres_la_reouverture_est_celui_du_premier_jour_non_cloture',
                'tests/Feature/Societe/FermerEtRouvrirMagasinTest.php::test_fermer_et_rouvrir_le_meme_jour_ne_produit_qu_un_z_et_reprend_les_ventes_le_lendemain',
            ],
        ],
        'caisse.conservation-et-effacements' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Les documents, registres, journaux des événements et le journal d\'audit des opérations se conservent 10 ans sans aucune purge ; seules s\'effacent, à leur échéance, les traces techniques (adresse IP et navigateur, après 1 an), les jetons des demandes répétées, les téléchargements par période, les invitations d\'un cabinet, les entrées d\'audit de gestion des accès et celles de la vérification d\'une société.',
            'tests' => [
                'tests/Feature/CaisseSecurisee/PurgerTracesTest.php::test_les_traces_de_plus_d_un_an_sont_effacees',
                'tests/Feature/Societe/JournalAuditTest.php::test_la_base_refuse_de_modifier_ou_d_effacer_une_entree_non_expiree',
                'tests/Feature/Societe/JournalAuditTest.php::test_l_effacement_des_entrees_expirees_garde_la_chaine_verifiable',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_la_fin_de_conservation_d_une_entree_de_la_verification_suit_le_reglage_puis_la_date_de_la_societe',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_le_lien_ne_sert_que_son_auteur_pendant_sept_jours_puis_s_efface_avec_son_fichier',
                'tests/Feature/Stock/GrandLivreEnBaseTest.php::test_la_base_refuse_de_reecrire_ou_de_supprimer_un_mouvement_du_grand_livre',
            ],
        ],
        'caisse.demonstration-mode-distinct' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Le magasin de démonstration est un mode d\'entraînement distinct (registre et séries propres, documents marqués, exclu des totaux réels, entrée et sortie inscrites au journal des événements, JET 100 et 105).',
            'tests' => [
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_l_ouverture_et_la_fin_de_la_demonstration_s_inscrivent_en_jet_100_et_105',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_le_verificateur_d_integrite_controle_aussi_la_demonstration',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_le_magasin_de_demonstration_n_a_rien_a_declarer',
            ],
        ],

        'emails.limites-et-relances' => [
            'puce' => 'E-mails',
            'regle' => 'Au plus 60 e-mails par heure et par membre et 200 par heure et par magasin, une relance par jour et par facture ; les relances partent dans l\'ordre, chacune pas avant son délai, en respectant l\'écart entre deux relances.',
            'tests' => [
                'tests/Feature/Facturation/EnvoyerDocumentActionTest.php::test_un_membre_envoie_au_plus_60_e_mails_par_heure',
                'tests/Feature/Facturation/EnvoyerDocumentActionTest.php::test_un_magasin_envoie_au_plus_200_e_mails_par_heure',
                'tests/Feature/Facturation/RelancerFacturesImpayeesTest.php::test_chaque_niveau_echu_part_une_seule_fois',
                'tests/Feature/Facturation/RelancerFacturesImpayeesTest.php::test_une_facture_deja_tres_en_retard_recoit_les_relances_dans_l_ordre_et_au_rythme_des_delais',
                'tests/Feature/Facturation/RelancerFacturesImpayeesTest.php::test_une_relance_manuelle_n_est_jamais_redoublee',
                'tests/Feature/Facturation/RelancerFacturesImpayeesTest.php::test_un_magasin_suspendu_ne_relance_rien',
                'tests/Feature/Facturation/RelancerFacturesImpayeesTest.php::test_chaque_magasin_relance_selon_ses_reglages_et_ses_factures',
            ],
        ],
        // Facture électronique (SUPER PDP) : la connexion de chaque société, l'adresse de réception des magasins et la réception des factures sont à l'étape 2 (décisions « a_venir »).
        'pdp.transmission-par-la-passerelle' => [
            'puce' => 'Facture électronique (SUPER PDP)',
            'regle' => 'Une facture à un professionnel se transmet par la plateforme agréée, derrière son interface, avec une implémentation factice jusqu\'à l\'étape 2, qui branche la connexion unique de la société faite par son propriétaire ; une vente à un particulier ne se transmet jamais comme facture (elle se déclare par sa clôture journalière), une facture déjà transmise ne se dépose jamais deux fois, et rien ne part du magasin de démonstration.',
            'tests' => [
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_une_facture_a_un_client_professionnel_avec_mandat_actif_declenche_la_transmission',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_une_facture_a_un_client_particulier_ne_declenche_jamais_la_transmission',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_le_job_execute_reellement_cree_une_transmission_acceptee_via_la_fake_gateway',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_le_job_est_idempotent_si_rejoue_sur_une_facture_deja_transmise',
                'tests/Feature/Societe/MagasinDeDemonstrationTest.php::test_aucun_e_mail_ni_aucune_transmission_ne_part_de_la_demonstration',
            ],
        ],
        'pdp.reprise-apres-une-panne' => [
            'puce' => 'Facture électronique (SUPER PDP)',
            'regle' => 'Une transmission interrompue par une panne se reprend d\'elle-même, sous la même référence de dépôt, sans jamais déposer deux fois la même facture ; elle ne s\'affiche « rejetée » que sur un rejet de la plateforme, et après la dernière tentative automatique elle est marquée « non transmise : incident technique », relancée chaque heure ; le propriétaire et les administrateurs qui suivent la plateforme en sont prévenus et peuvent la relancer.',
            'tests' => [
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_le_job_est_idempotent_si_rejoue_sur_une_facture_deja_transmise',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_une_panne_reseau_au_premier_essai_est_reprise_au_suivant_avec_la_meme_reference',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_un_depot_recu_avant_la_panne_n_est_jamais_redepose',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_l_echec_de_toutes_les_tentatives_passe_en_echec_technique',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_un_rejet_de_la_plateforme_reste_final',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_l_echec_technique_previent_le_proprietaire_et_les_administrateurs_qui_suivent_la_plateforme_une_seule_fois',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_la_relance_a_la_main_est_permise_au_proprietaire_et_aux_administrateurs_qui_suivent_la_plateforme',
                'tests/Feature/Facturation/TransmissionPdpTest.php::test_la_relance_de_chaque_heure_reprend_les_transmissions_en_echec_de_chaque_magasin',
            ],
        ],
        'pdp.e-reporting-et-encaissements-des-services' => [
            'puce' => 'Facture électronique (SUPER PDP)',
            'regle' => 'Les ventes aux particuliers se déclarent par clôture journalière qui a des opérations ; les paiements de particuliers pour des services, sans option pour les débits, se déclarent par jour d\'encaissement, TTC par taux, seule la part des services d\'une vente mêlée, un paiement partiel réparti au prorata des TTC restant après les avoirs, en cumul, chaque part arrondie au centime le plus proche ; un crédit d\'avoir utilisé compte comme un paiement, l\'avoir d\'une prestation déjà payée se déduisant le jour où son crédit règle une autre vente ; une société qui a opté pour les débits n\'en déclare aucun ; dans l\'aperçu, une vente en franchise ou en France à 0 % se range selon la nature de sa ligne, au taux 0 %, et un export ou une livraison vers un DOM parmi les opérations non soumises à la TVA en France (décisions de Claude T1.1 à T1.4, T2.4, T2.5, T9.3, T107, T108, T112.1, D76).',
            'tests' => [
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_journee_sans_operation_n_a_rien_a_declarer',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_l_encaissement_d_un_service_est_ventile_par_taux',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_un_service_regle_par_un_credit_d_avoir_compte_parmi_les_encaissements_a_la_date_de_son_utilisation',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_le_credit_d_un_avoir_impute_sur_le_ticket_qu_il_corrige_n_est_pas_un_encaissement',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_la_contre_passation_d_un_paiement_de_service_se_deduit_a_sa_date',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_un_reglement_partiel_d_un_ticket_mixte_se_repartit_au_prorata',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_un_acompte_se_repartit_entre_les_taux_de_services_au_prorata',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_des_paiements_successifs_se_repartissent_sur_l_encaisse_cumule',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_un_acompte_se_repartit_sur_le_ttc_restant_apres_un_avoir',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_un_credit_d_avoir_utilise_se_repartit_comme_un_paiement',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_la_contre_passation_d_un_acompte_reparti_se_deduit_des_memes_parts',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_l_avoir_d_une_prestation_deja_encaissee_se_deduit_a_l_utilisation_de_son_credit',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_le_credit_jamais_utilise_d_une_prestation_annulee_ne_deduit_rien',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_societe_qui_a_opte_pour_les_debits_ne_declare_aucun_encaissement_de_services',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_vente_sans_tva_francaise_se_range_dans_les_operations_non_soumises_au_taux_zero',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_vente_en_franchise_en_base_se_range_par_la_nature_de_sa_ligne',
                'tests/Feature/Facturation/EReportingDesParticuliersTest.php::test_une_vente_en_france_a_zero_pour_cent_se_range_par_la_nature_de_sa_ligne',
                'tests/Unit/CalculateurLigneTest.php::test_un_montant_se_repartit_au_prorata_en_cumul',
            ],
        ],

        // Règles légales et réglementaires.
        'regles-legales.decisions-de-claude-appliquees' => [
            'puce' => 'Règles légales et réglementaires',
            'regle' => 'Chaque point légal ou réglementaire reçoit une décision de Claude, appliquée sans attendre : aucun code, écran, mention ni règle n\'attend l\'avis d\'un expert-comptable ou d\'un professionnel (D76).',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_aucun_texte_n_attend_l_avis_d_un_professionnel',
            ],
        ],

        // Journal d'audit.
        'audit.ajout-seul-chaine' => [
            'puce' => 'Journal d\'audit',
            'regle' => 'Les opérations importantes s\'inscrivent au journal d\'audit de la société, en ajout seul, chaîné par magasin et par société et protégé contre toute modification ; un échec d\'écriture annule l\'action ; la suppression des dernières entrées est détectée ; la clé se renouvelle sans perdre la vérification des anciennes entrées.',
            'tests' => [
                'tests/Feature/Societe/JournalAuditTest.php::test_la_base_refuse_de_modifier_ou_d_effacer_une_entree_non_expiree',
                'tests/Feature/Societe/JournalAuditTest.php::test_les_entrees_sont_chainees_par_magasin_par_societe_et_par_categorie',
                'tests/Feature/Societe/JournalAuditTest.php::test_un_echec_d_ecriture_du_journal_annule_l_action',
                'tests/Feature/Societe/JournalAuditTest.php::test_la_suppression_des_dernieres_entrees_est_detectee',
                'tests/Feature/Societe/JournalAuditTest.php::test_la_rotation_de_la_cle_garde_les_anciennes_entrees_verifiables',
                'tests/Feature/Societe/JournalAuditTest.php::test_l_effacement_des_entrees_expirees_garde_la_chaine_verifiable',
            ],
        ],
        'audit.consultable-par-le-proprietaire-seul' => [
            'puce' => 'Journal d\'audit',
            'regle' => 'Le journal d\'audit se consulte par le propriétaire seul (celui d\'un cabinet pour son cabinet), jamais par un administrateur, un collaborateur ni un expert-comptable de la société, et jamais celui d\'une autre société ; l\'auteur s\'y lit par son nom au moment de l\'opération.',
            'tests' => [
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_seul_le_proprietaire_consulte_le_journal_et_les_traces',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_le_journal_d_une_autre_societe_ne_se_lit_jamais',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_le_proprietaire_d_un_cabinet_consulte_le_journal_de_son_cabinet_et_pas_un_collaborateur',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_l_expert_comptable_de_la_societe_n_ouvre_jamais_le_journal_d_audit',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_l_auteur_s_affiche_par_son_nom_au_moment_de_l_operation',
                'tests/Feature/Societe/JournalAuditResourceTest.php::test_l_heure_repetee_dit_l_heure_d_ete_ou_l_heure_d_hiver',
            ],
        ],
        'audit.traces-a-part-sans-donnee-inutile' => [
            'puce' => 'Journal d\'audit',
            'regle' => 'Sans donnée personnelle inutile : aucun secret ni code n\'entre au journal ; l\'adresse IP et le navigateur, du journal d\'audit comme du journal des événements de la caisse, sont rangés à part, jamais dans un contenu signé, et effacés après 1 an ; chaque catégorie se conserve selon sa durée.',
            'tests' => [
                'tests/Feature/Societe/JournalAuditTest.php::test_aucun_secret_ni_code_n_entre_jamais_au_journal',
                'tests/Feature/Societe/JournalAuditTest.php::test_l_ip_et_le_navigateur_vont_dans_la_trace_separee',
                'tests/Feature/Societe/JournalAuditTest.php::test_aucun_contenu_signe_ne_contient_d_adresse_ip_ni_de_navigateur',
                'tests/Feature/Societe/JournalAuditTest.php::test_les_traces_de_plus_d_un_an_sont_effacees_sans_toucher_aux_entrees',
                'tests/Feature/CaisseSecurisee/PurgerTracesTest.php::test_les_traces_de_plus_d_un_an_sont_effacees',
                'tests/Feature/Filament/EnregistrementFiscalResourceTest.php::test_la_fiche_annonce_la_duree_de_conservation_reglee_des_traces',
            ],
        ],

        // Téléchargement des documents par période.
        'telechargement.autorisation-du-proprietaire' => [
            'puce' => 'Téléchargement des documents par période',
            'regle' => 'L\'autorisation « télécharger les documents par période » n\'appartient d\'office qu\'au propriétaire, qui la coche compte par compte (jamais précochée, même pour un responsable), et pour le cabinet lié par le propriétaire du client ; chacun ne reçoit que les documents qu\'il peut voir encore au moment du téléchargement.',
            'tests' => [
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_seul_le_proprietaire_a_l_autorisation_d_office_et_la_coche_compte_par_compte',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_un_vendeur_autorise_ne_recoit_que_les_types_qu_il_voit_et_ses_propres_documents',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_le_cabinet_lie_ne_telecharge_que_si_le_client_le_lui_coche',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_le_fichier_ne_compte_que_les_documents_que_le_compte_voit_encore',
                'tests/Feature/Filament/TelechargerLesDocumentsTest.php::test_la_page_du_magasin_s_ouvre_au_proprietaire_et_a_qui_il_coche_l_autorisation',
                'tests/Feature/Filament/TelechargerLesDocumentsTest.php::test_le_lien_est_refuse_a_qui_ne_voit_plus_les_documents_du_fichier',
                'tests/Feature/Filament/TelechargerLesDocumentsTest.php::test_le_lien_d_un_compte_du_cabinet_se_ferme_avec_le_lien_du_client',
                'tests/Browser/Parcours/TelechargerLesDocumentsTest.php › la page ne s’offre qu’au propriétaire et à qui il coche l’autorisation, ni au cabinet lié sans l’accord du client',
            ],
        ],
        'telechargement.calendrier-types-zip-et-csv' => [
            'puce' => 'Téléchargement des documents par période',
            'regle' => 'Dans chaque magasin, dans l\'espace de la société et dans celui du cabinet, un calendrier et des cases par type (avec l\'origine des achats) listent les documents, qu\'on coche un à un ou tous, puis qu\'on télécharge en PDF dans un ZIP ou en récapitulatif CSV ; chaque téléchargement s\'inscrit au journal d\'audit.',
            'tests' => [
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_le_proprietaire_trouve_chaque_type_de_la_periode_dans_le_calendrier_du_magasin',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_l_origine_des_achats_et_la_periode_choisissent_les_documents',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_le_zip_range_chaque_document_par_type_et_par_mois_avec_les_pieces_telles_que_deposees',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_le_recapitulatif_csv_reprend_chaque_taux_tel_qu_enregistre_et_ce_qui_reste_du_a_la_fin_de_la_periode',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_la_preparation_previent_la_personne_et_s_inscrit_au_journal_d_audit_de_la_societe',
                'tests/Feature/Facturation/TelechargementDesDocumentsTest.php::test_une_autre_societe_ne_trouve_ni_ne_lit_rien_de_ce_magasin',
                'tests/Feature/Filament/TelechargerLesDocumentsTest.php::test_tout_selectionner_prend_tous_les_documents_trouves_au_dela_de_la_page_affichee',
                'tests/Feature/Filament/TelechargerLesDocumentsTest.php::test_l_espace_de_la_societe_propose_les_magasins_de_la_portee_ou_la_personne_telecharge',
                'tests/Feature/Filament/TelechargerLesDocumentsTest.php::test_l_espace_du_cabinet_propose_les_seuls_clients_qui_lui_accordent_le_telechargement',
                'tests/Browser/Parcours/TelechargerLesDocumentsTest.php › le propriétaire choisit le trimestre, coche tout et télécharge le ZIP des documents du magasin',
                'tests/Browser/Parcours/TelechargerLesDocumentsTest.php › dans l’espace de la société, le propriétaire télécharge le récapitulatif CSV des magasins cochés',
            ],
        ],

        // Fichiers reçus.
        'fichiers.type-lu-et-nom-reecrit' => [
            'puce' => 'Fichiers reçus',
            'regle' => 'Toute pièce déposée est contrôlée avant d\'être enregistrée : type lu dans son contenu (PDF, JPEG ou PNG), nom réécrit avec l\'extension de ce type, sans caractère invisible ; elle se télécharge toujours en pièce jointe.',
            'tests' => [
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_un_pdf_nomme_facture_bat_s_enregistre_sous_le_nom_facture_pdf',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_le_nom_enregistre_est_nettoye_et_prend_l_extension_du_type_reel',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_un_autre_type_un_fichier_vide_ou_trop_gros_est_refuse',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_l_en_tete_de_telechargement_porte_un_nom_ascii_et_le_nom_utf8',
                'tests/Feature/Fichiers/EnvoisTemporairesTest.php::test_les_envois_temporaires_restent_sur_le_disque_prive_jamais_servi',
                'tests/Feature/Achats/PiecesDuFournisseurTest.php::test_seuls_un_pdf_une_photo_jpeg_ou_png_sont_admis_d_apres_leur_contenu',
            ],
        ],
        'fichiers.pdf-sans-code-actif' => [
            'puce' => 'Fichiers reçus',
            'regle' => 'Un PDF qui contient du JavaScript, un lancement de programme, une action automatique autre qu\'un affichage, un formulaire qui s\'envoie, un contenu multimédia ou un fichier joint autre que le XML d\'une Factur-X, ou qui ne se lit pas en entier, est refusé, avec un message qui dit comment obtenir une copie propre.',
            'tests' => [
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_un_pdf_qui_contient_du_code_actif_est_refuse_en_disant_pourquoi',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_le_code_d_un_flux_d_objets_ou_d_une_mise_a_jour_incrementale_se_voit',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_une_simple_destination_un_lien_et_une_facturx_sont_admis',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_une_facturx_dont_le_xml_a_un_doctype_ou_une_entite_est_refusee',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_un_pdf_tronque_ou_suivi_d_un_autre_contenu_est_refuse',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_un_contenu_glisse_apres_la_fin_ou_dans_une_mise_a_jour_est_refuse',
                'tests/Feature/Fichiers/PiecesRecuesTest.php::test_l_ecran_dit_le_refus_et_comment_obtenir_une_copie_propre',
            ],
        ],
        'fichiers.photo-sans-metadonnees' => [
            'puce' => 'Fichiers reçus',
            'regle' => 'Une photo est décodée et réenregistrée sans ses métadonnées (position GPS comprise), son orientation appliquée, l\'empreinte du fichier reçu restant au journal d\'audit.',
            'tests' => [
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_une_photo_est_reenregistree_sans_ses_metadonnees_ni_ses_donnees_ajoutees',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_l_orientation_de_l_appareil_est_appliquee',
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_une_image_demesuree_ou_illisible_est_refusee',
                'tests/Feature/Fichiers/PiecesDeVerificationTest.php::test_une_piece_acceptee_porte_le_nom_de_son_type_et_l_audit_le_fichier_recu',
            ],
        ],
        'fichiers.antivirus-au-depot-et-au-telechargement' => [
            'puce' => 'Fichiers reçus',
            'regle' => 'Chaque fichier passe un antivirus (ClamAV, une implémentation factice pour les tests), au dépôt et de nouveau au téléchargement ; un antivirus injoignable refuse le dépôt ; chaque refus s\'inscrit au journal d\'audit.',
            'tests' => [
                'tests/Feature/Fichiers/ControleDesFichiersTest.php::test_l_antivirus_refuse_une_menace_et_un_antivirus_injoignable_refuse_le_depot',
                'tests/Feature/Fichiers/PiecesRecuesTest.php::test_un_refus_de_securite_s_inscrit_au_journal_d_audit_sans_rien_enregistrer',
                'tests/Feature/Fichiers/PiecesRecuesTest.php::test_une_menace_au_depot_est_refusee_et_previent_le_proprietaire',
                'tests/Feature/Fichiers/PiecesRecuesTest.php::test_une_piece_se_relit_au_telechargement_quand_sa_derniere_analyse_a_plus_de_24_heures',
                'tests/Feature/Fichiers/PiecesRecuesTest.php::test_une_piece_reconnue_plus_tard_comme_une_menace_est_bloquee_et_gardee',
                'tests/Feature/Fichiers/PiecesRecuesTest.php::test_un_autre_magasin_ne_touche_ni_la_piece_ni_son_analyse',
                'tests/Feature/Fichiers/ClamAvTest.php::test_un_clamd_injoignable_le_dit',
                'tests/Feature/Fichiers/PiecesDeVerificationTest.php::test_un_refus_de_securite_s_inscrit_au_journal_d_audit_sans_rien_enregistrer',
            ],
        ],

        // Hors de CLAUDE.md : décisions de la conversation appliquées dans le code, que CLAUDE.md n'écrit pas encore (historique/DIRECTIVES.md, § 2.2, et réponses du 26/10/2026 sur deux règles d'écran) ; leur texte est proposé à l'éditeur.
        'produit.aucun-ecran-de-magasin-ne-devine-les-autres' => [
            'puce' => 'Produit',
            'regle' => 'Aucun écran ni aucun message du panneau d\'un magasin ne laisse deviner qu\'il existe d\'autres magasins de la même société, ni d\'autres sociétés : ni nom, ni nombre, ni identifiant, ni refus qui révélerait qu\'une donnée appartient à un autre magasin ou à une autre société (réponse 2 du 26/10/2026).',
            'tests' => [
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_l_url_d_un_enregistrement_d_un_autre_magasin_de_la_societe_repond_404_meme_pour_un_compte_autorise_dans_les_deux',
                'tests/Feature/Tenancy/IsolationDesSocietesTest.php::test_l_espace_d_une_autre_societe_repond_404',
                'tests/Feature/Filament/RequetesRefuseesTest.php::test_la_page_d_un_magasin_que_le_compte_connecte_n_ouvre_pas_reste_introuvable_en_francais',
                'tests/Feature/Facturation/DocumentIntrouvableTest.php::test_un_geste_sur_un_devis_d_un_autre_magasin_le_dit_sans_rien_ecrire',
                'tests/Feature/Filament/AvoirResourceTest.php::test_un_numero_d_un_autre_magasin_est_introuvable',
                'tests/Feature/Filament/CaisseTest.php::test_le_bon_d_avoir_d_un_autre_magasin_est_introuvable',
                'tests/Feature/Societe/MagasinResourceTest.php::test_aucune_information_sur_une_autre_societe_n_apparait_dans_la_liste_ni_dans_les_messages',
                'tests/Feature/Societe/MagasinResourceTest.php::test_un_siret_reserve_par_une_autre_societe_est_refuse_sans_rien_en_dire',
                'tests/Browser/IsolationTest.php › le vendeur d’un magasin qui tape l’adresse d’un autre magasin de sa société reçoit la page introuvable',
                'tests/Browser/IsolationTest.php › le propriétaire d’une société qui tape l’adresse d’une facture ou de l’espace d’une autre société reçoit la page introuvable',
            ],
        ],
        'ecrans.erreurs-metier-en-alertes-claires' => [
            'puce' => 'Écrans',
            'regle' => 'Une erreur métier s\'affiche partout en alerte claire, en français, qui dit pourquoi le geste n\'a pas été accepté, jamais en erreur technique (trace, requête, page d\'erreur par défaut) (DIRECTIVES § 2.2, point 2).',
            'tests' => [
                'tests/Feature/Facturation/DocumentIntrouvableTest.php::test_un_geste_sur_un_brouillon_supprime_entre_temps_le_dit_sans_rien_ecrire',
                'tests/Feature/Filament/DocumentDisparuDeLEcranTest.php::test_un_geste_sur_la_page_d_un_document_supprime_entre_temps_revient_a_la_liste_qui_dit_pourquoi',
                'tests/Feature/Filament/CaisseTest.php::test_une_horloge_qui_recule_refuse_la_vente_avec_un_message_sans_rien_enregistrer',
                'tests/Feature/Filament/FactureResourceTest.php::test_laction_avoir_sans_aucune_ligne_affiche_une_notification',
                'tests/Feature/Filament/RequetesRefuseesTest.php::test_chaque_panneau_pose_l_avis_en_francais_des_requetes_refusees',
                'tests/Feature/Filament/ProduitResourceTest.php::test_la_reference_d_un_produit_archive_est_refusee_avec_un_message_qui_dit_ou_le_retrouver',
                'tests/Browser/AidesCommunesTest.php › une erreur du serveur fait échouer la vérification',
                'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            ],
        ],
        'ecrans.champ-en-cours-de-frappe' => [
            'puce' => 'Écrans',
            'regle' => 'Le champ en cours de frappe n\'est jamais modifié ; les autres champs se calculent en direct (DIRECTIVES § 2.2, point 3).',
            'tests' => [
                'tests/Feature/Filament/FactureResourceTest.php::test_les_champs_calcules_en_direct_ne_sont_jamais_reecrits_pendant_la_frappe',
                'tests/Feature/Filament/FactureResourceTest.php::test_un_taux_de_tva_en_cours_de_frappe_ne_fait_pas_tomber_la_ligne_a_zero',
                'tests/Feature/Filament/TvaDesAchatsResourceTest.php::test_les_montants_recopies_du_fournisseur_ne_sont_jamais_reecrits_pendant_la_frappe',
                'tests/Feature/Filament/UnProduitUneLigneTest.php::test_un_ajout_pendant_la_frappe_d_une_quantite_ne_cree_pas_de_seconde_ligne',
            ],
        ],
        'ecrans.retour-a-la-page-parente' => [
            'puce' => 'Écrans',
            'regle' => 'Après chaque enregistrement d\'une page de création ou de modification, retour à sa page parente, la liste de sa ressource (DIRECTIVES § 2.2, point 4, et D34).',
            'tests' => [
                'tests/Unit/RetourALaListeTest.php::test_chaque_page_de_creation_ou_de_modification_revient_a_la_liste_de_sa_ressource',
                'tests/Unit/RetourALaListeTest.php::test_revenir_a_la_liste_mene_a_l_index_de_la_ressource',
                'tests/Feature/Filament/ClientResourceTest.php::test_un_proprietaire_peut_creer_un_client_via_le_formulaire',
                'tests/Feature/Filament/FactureResourceTest.php::test_modifier_un_brouillon_depuis_le_panneau_remplace_les_lignes_et_recalcule_les_totaux',
                'tests/Feature/Filament/InventaireOuvertureResourceTest.php::test_le_proprietaire_saisit_puis_valide_un_inventaire_depuis_ses_ecrans',
                'tests/Feature/Filament/InventaireOuvertureResourceTest.php::test_un_brouillon_se_corrige_depuis_sa_page_de_modification',
                'tests/Feature/Societe/MagasinResourceTest.php::test_enregistrer_la_page_d_un_magasin_revient_a_la_liste_des_magasins',
            ],
        ],
        'documents.facture-brouillon-numerotee-a-l-emission' => [
            'puce' => 'Documents de vente',
            'regle' => 'Une facture brouillon n\'a pas de numéro définitif : elle est numérotée à son émission ; un brouillon se modifie et se supprime en entier, lignes comprises, d\'un seul geste ; une facture émise ne se supprime jamais (DIRECTIVES § 2.2, point 6).',
            'tests' => [
                'tests/Feature/Facturation/CreerFactureActionTest.php::test_une_facture_est_creee_en_brouillon_avec_ses_lignes_et_ses_totaux',
                'tests/Feature/Facturation/EmettreFactureActionTest.php::test_emettre_une_facture_lui_attribue_un_numero_et_change_son_statut',
                'tests/Feature/Facturation/FactureBrouillonSuppressionTest.php::test_supprimer_un_brouillon_supprime_aussi_ses_lignes',
                'tests/Feature/Facturation/FactureNeSePasSupprimeTest.php::test_seul_le_brouillon_est_modifiable_pas_une_facture_emise',
                'tests/Feature/Facturation/FactureNeSePasSupprimeTest.php::test_meme_le_proprietaire_ne_peut_pas_supprimer_une_facture_emise',
            ],
        ],
        'fiches.reference-produit-automatique' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'La référence d\'un produit est facultative : quand elle manque, y compris pour un produit reçu par l\'API, elle se génère comme une séquence du magasin, sans code de magasin ni année, en sautant un numéro déjà pris (DIRECTIVES § 2.2, point 7).',
            'tests' => [
                'tests/Feature/Catalogue/ProduitTest.php::test_une_reference_vide_est_generee_automatiquement',
                'tests/Feature/Catalogue/ProduitTest.php::test_deux_produits_sans_reference_dans_le_meme_magasin_recoivent_des_numeros_distincts',
                'tests/Feature/Catalogue/ProduitTest.php::test_la_reference_automatique_saute_un_numero_deja_saisi_a_la_main',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_les_references_automatiques_suivent_l_ordre_des_validations',
                'tests/Feature/Facturation/DocumentNumberingServiceTest.php::test_une_reference_de_produit_ne_porte_ni_code_de_magasin_ni_annee',
            ],
        ],
        'achats.deux-numeros-d-une-facture-d-achat' => [
            'puce' => 'Achats et stock',
            'regle' => 'Une facture d\'achat porte deux numéros : celui de la séquence du magasin, donné à sa validation, et le numéro d\'origine du fournisseur, facultatif (DIRECTIVES § 2.2, point 8).',
            'tests' => [
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_creer_une_facture_dachat_depuis_le_panneau',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_valider_une_facture_dachat_lui_attribue_un_numero_et_incremente_le_stock',
            ],
        ],
        'ecrans.quantites-sans-decimales' => [
            'puce' => 'Écrans',
            'regle' => 'Les quantités s\'affichent sans décimales (« 2 », jamais « 2.000 ») et ne se saisissent qu\'entières (DIRECTIVES § 2.2, point 9).',
            'tests' => [
                'tests/Feature/Filament/FactureResourceTest.php::test_quantites_et_taux_saffichent_sans_zeros_inutiles_partout',
                'tests/Unit/QuantiteEntiereTest.php::test_une_quantite_entiere_est_acceptee_telle_quelle',
                'tests/Unit/QuantiteEntiereTest.php::test_une_quantite_non_entiere_ou_inferieure_a_1_est_refusee',
            ],
        ],
        'achats.achat-recu-par-l-api' => [
            'puce' => 'Achats et stock',
            'regle' => 'Une facture d\'achat reçue automatiquement (API, puis SUPER PDP) ne se modifie jamais ; un produit qui en vient garde ce que son fournisseur en dit (référence, code-barres, nom, taille, couleur), son prix de vente, sa TVA et son suivi du stock restant modifiables (DIRECTIVES § 2.2, point 10).',
            'tests' => [
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable',
                'tests/Feature/Authorization/FactureAchatPolicyTest.php::test_une_facture_qu_on_ne_voit_pas_ou_recuperee_par_api_ne_se_modifie_pas',
                'tests/Feature/Achats/FactureAchatActionsTest.php::test_la_fiche_dun_produit_recu_par_api_garde_ce_que_son_fournisseur_en_dit',
                'tests/Feature/Filament/ProduitResourceTest.php::test_un_produit_recu_par_api_garde_ce_que_son_fournisseur_en_dit',
                'tests/Feature/Filament/FactureAchatResourceTest.php::test_la_fiche_dun_produit_recu_par_api_ne_se_corrige_pas_depuis_une_ligne',
            ],
        ],
        'fiches.code-barres-scanne' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Un produit se cherche aussi par son code-barres scanné, à la caisse comme dans les formulaires de vente (DIRECTIVES § 2.2, point 11).',
            'tests' => [
                'tests/Feature/Filament/CaisseTest.php::test_scanner_un_code_barres_ajoute_le_produit_sans_ouvrir_le_tableau',
                'tests/Feature/Filament/CaisseTest.php::test_un_long_code_inconnu_reste_dans_la_douchette_sans_bloquer_l_encaissement',
                'tests/Feature/Filament/DevisResourceTest.php::test_rechercher_un_produit_par_code_barres_le_retrouve',
                'tests/Feature/Filament/SelecteurDeProduitsTest.php::test_la_recherche_trouve_par_nom_reference_et_code_barres_sans_accents',
            ],
        ],
        'fiches.client-cree-depuis-la-vente' => [
            'puce' => 'Clients, produits, fournisseurs',
            'regle' => 'Toute l\'équipe qui en a le droit crée un client depuis la vente (caisse, facture, devis), dans une fenêtre, sans quitter la vente (DIRECTIVES § 2.2, point 12).',
            'tests' => [
                'tests/Feature/Filament/CaisseTest.php::test_creer_un_nouveau_client_a_la_volee_depuis_la_caisse',
                'tests/Feature/Filament/FactureResourceTest.php::test_creer_un_nouveau_client_a_la_volee_depuis_le_formulaire_facture',
                'tests/Feature/Filament/DevisResourceTest.php::test_creer_un_nouveau_client_a_la_volee_depuis_le_formulaire_devis',
                'tests/Feature/Filament/SelecteurDeClientsTest.php::test_nouveau_client_cree_le_client_dans_le_magasin_et_le_choisit',
            ],
        ],

        'ecrans.numeros-de-documents-cliquables' => [
            'puce' => 'Écrans',
            'regle' => 'Tout numéro de document affiché (avoir, ticket ou facture crédité ou réglé, clôture, facture d\'achat, avoir fournisseur, rectificative) ouvre sa fiche d\'un clic pour qui peut voir ce document ; les autres en lisent le numéro, sans lien (DIRECTIVES D38, constat C2 du 02/11/2026).',
            'tests' => [
                'tests/Feature/Filament/LiensVersLesDocumentsTest.php::test_sur_la_fiche_de_l_avoir_chaque_document_est_un_lien_pour_qui_peut_le_voir',
                'tests/Feature/Filament/LiensVersLesDocumentsTest.php::test_un_vendeur_ne_recoit_pas_de_lien_vers_un_document_qu_il_ne_voit_pas',
                'tests/Browser/Parcours/LiensVersLesDocumentsTest.php › le responsable passe d’un document à l’autre par leurs numéros : avoir, ticket crédité, ticket réglé par le crédit',
                'tests/Browser/Parcours/LiensVersLesDocumentsTest.php › un vendeur qui ne voit pas l’avoir de son ticket en lit le numéro, sans lien',
            ],
        ],
        'hors.rangees-choisies-lisibles' => [
            'puce' => 'Hors de CLAUDE.md',
            'regle' => 'La rangée choisie, la rangée survolée et la ligne cochée d\'un tableau de sélection se lisent bien, en clair et en sombre (contraste AA ; constat C5 du 03/11/2026).',
            'tests' => [
                'tests/Browser/Parcours/RangeesChoisiesTest.php › la rangée choisie, la rangée survolée et la ligne cochée se lisent bien, en clair et en sombre',
                'tests/Browser/Parcours/RangeesChoisiesTest.php › chaque texte d’une rangée choisie se lit, stock épuisé et « À régulariser » compris ; le service coché aussi, pas les autorisations',
            ],
        ],
        'hors.recu-le-passe-permis-futur-refuse' => [
            'puce' => 'Hors de CLAUDE.md',
            'regle' => 'Le champ « Reçu le » d\'un paiement (encaissement d\'une facture, page des paiements) accepte une date passée, comme un virement vu sur le relevé de la veille, et refuse une date future, au jour du calendrier du magasin (décision de Claude du 09/10/2026, constat X0 de son audit, appliquée sans attendre selon D76 et lue par l\'éditeur le 13/11/2026 sans la contester).',
            'tests' => [
                'tests/Feature/Filament/PaiementResourceTest.php::test_laction_rapide_encaisser_accepte_une_date_de_reception_passee',
                'tests/Feature/Filament/PaiementResourceTest.php::test_laction_rapide_encaisser_refuse_une_date_de_reception_future',
                'tests/Feature/Filament/PaiementResourceTest.php::test_le_formulaire_des_paiements_accepte_une_date_de_reception_passee',
                'tests/Feature/Filament/PaiementResourceTest.php::test_le_formulaire_des_paiements_refuse_une_date_de_reception_future',
                'tests/Feature/Calendrier/HeureLocaleTest.php::test_un_reglement_recu_le_jour_local_est_accepte_et_le_lendemain_local_refuse',
            ],
        ],
        'ecrans.aucun-site-tiers' => [
            'puce' => 'Écrans',
            'regle' => 'Aucune page de PILIQI ne charge rien d\'un autre site : la police est servie par PILIQI et les initiales du compte sont dessinées par le serveur (Q-TIERS du 03/11/2026).',
            'tests' => [
                'tests/Browser/AucunSiteTiersTest.php › les pages publiques ne chargent rien d’un autre site',
                'tests/Browser/AucunSiteTiersTest.php › chaque panneau ne charge rien d’un autre site, et les initiales du compte sont dessinées par PILIQI',
                'tests/Feature/Filament/RessourcesServiesParPiliqiTest.php::test_chaque_panneau_charge_la_police_servie_par_piliqi',
            ],
        ],
        'emails.envoi-des-e-mails' => [
            'puce' => 'E-mails',
            'regle' => 'Les e-mails partent par le service d\'essai choisi par l\'éditeur (Mailtrap) ou par le serveur SMTP réglé dans .env ; un envoi raté repart aussitôt, puis 30 secondes et 2 minutes plus tard, chaque cause se dit en clair et l\'expéditeur est prévenu de l\'échec (lot ML, réponses du 01/11 et du 02/11/2026).',
            'tests' => [
                'tests/Feature/Integrations/TransportMailtrapTest.php::test_le_message_part_a_la_boite_de_test_avec_ses_adresses_son_contenu_et_sa_piece_jointe',
                'tests/Feature/Integrations/TransportMailtrapTest.php::test_sans_jeton_ni_boite_rien_ne_part_et_l_exception_dit_ce_qui_manque',
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_un_serveur_muet_fait_trois_tentatives_a_trente_secondes_puis_deux_minutes_d_ecart',
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_chaque_refus_du_serveur_se_dit_par_sa_cause_sans_l_identifiant',
                'tests/Feature/Facturation/EnvoyerDocumentJobTest.php::test_un_echec_passe_l_envoi_en_echec_et_previent_l_expediteur',
            ],
        ],
        'hors.ecrans-de-la-vente' => [
            'puce' => 'Hors de CLAUDE.md',
            'regle' => 'Ce qu\'il reste à répartir ne s\'affiche qu\'une fois, avant comme après le clic sur « Encaisser » ; la section « Paiements reçus » d\'une fiche ne se montre qu\'à qui voit les paiements du document, « Payé » et « Restant dû » restant visibles ; « Modifier » et « Émettre » se trouvent sur la fiche d\'un brouillon pour qui peut le modifier et l\'émettre (constats C1, C8 et C9, T41 et T42 du 10/11/2026).',
            'tests' => [
                'tests/Feature/Filament/CaisseTest.php::test_ce_qu_il_reste_a_repartir_ne_s_affiche_qu_une_fois_avant_comme_apres_le_clic',
                'tests/Feature/Filament/FicheDUneFactureTest.php::test_un_vendeur_sans_le_droit_de_voir_les_paiements_ne_voit_pas_la_section_mais_voit_le_paye_et_le_restant_du',
                'tests/Feature/Filament/FicheDUneFactureTest.php::test_le_brouillon_se_modifie_et_s_emet_depuis_sa_fiche_pour_qui_le_peut',
                'tests/Feature/Filament/FicheDUneFactureTest.php::test_sans_le_droit_ni_modifier_ni_emettre_sur_la_fiche_du_brouillon',
                'tests/Browser/Parcours/FicheDUneFactureTest.php › « Paiements reçus » ne se montre qu’à qui voit les paiements du document ; « Payé » et « Restant dû » restent visibles',
                'tests/Browser/Parcours/FicheDUneFactureTest.php › « Modifier » et « Émettre » ne se montrent sur la fiche d’un brouillon qu’à qui peut le modifier et l’émettre',
            ],
        ],
        'hors.nom-du-compte-inscrit' => [
            'puce' => 'Hors de CLAUDE.md',
            'regle' => 'Le compte de la personne inscrite prend le nom du représentant légal lu au registre national (INPI), la dénomination d\'une personne morale dirigeante ; le compte d\'une autre personne qui saisit le code prend le nom du destinataire du courrier ; chacun change ensuite son nom dans son profil (réponses du 24/10/2026 au lot V2, point 1).',
            'tests' => [
                'tests/Feature/Societe/InscriptionSocieteTest.php::test_la_confirmation_cree_la_societe_qui_attend_son_code_et_envoie_le_courrier',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_une_personne_morale_dirigeante_recoit_le_courrier_a_l_attention_de_son_representant',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_une_autre_personne_qui_saisit_le_code_devient_proprietaire_et_les_comptes_d_avant_sont_retires',
            ],
        ],
        'hors.code-du-courrier' => [
            'puce' => 'Hors de CLAUDE.md',
            'regle' => 'Le code du courrier compte 12 caractères de l\'alphabet de Crockford et ne se garde que haché ; il n\'est jamais annulé après des saisies fausses, qui restent comptées et limitées par adresse IP (réponses du 24/10/2026 au lot V2, point 4).',
            'tests' => [
                'tests/Feature/Societe/VerificationSocieteTest.php::test_le_code_est_hache_unique_valable_trente_jours_et_a_usage_unique',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_un_code_faux_se_compte_et_s_inscrit_sans_jamais_annuler_le_courrier',
                'tests/Feature/Societe/VerificationSocieteTest.php::test_la_page_du_code_limite_les_saisies_par_adresse_ip',
            ],
        ],
        'outillage.licence-et-extensions' => [
            'puce' => 'Outillage',
            'regle' => 'Licence propriétaire ; extensions PHP gd, openssl, zip et pdo_pgsql déclarées dans composer.json.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_composer_declare_la_licence_proprietaire_et_les_extensions_php',
            ],
        ],
        'caisse.une-seule-caisse-par-magasin' => [
            'puce' => 'Caisse et clôtures',
            'regle' => 'Une seule caisse par magasin : chaque magasin tient son propre registre, dont chaque chaîne suit sa séquence depuis 1.',
            'tests' => [
                'tests/Feature/CaisseSecurisee/RegistreFiscalTest.php::test_chaque_chaine_suit_sa_propre_sequence_depuis_1_dans_chaque_magasin',
                'tests/Feature/Facturation/CloturerCaisseActionTest.php::test_deux_magasins_ne_melangent_jamais_leurs_encaissements',
            ],
        ],
        'vendeurs.chiffre-d-affaires-net-des-avoirs' => [
            'puce' => 'Vendeurs',
            'regle' => 'Le chiffre d\'affaires est net des avoirs, en HT avec le TTC à côté.',
            'tests' => [
                'tests/Feature/Filament/FacturesApercuWidgetTest.php::test_le_chiffre_d_affaires_du_magasin_est_net_des_avoirs',
            ],
        ],
        'vendeurs.envoi-selon-les-autorisations' => [
            'puce' => 'Vendeurs',
            'regle' => 'Envoyer un document par e-mail est une autorisation de magasin que l\'on coche à chaque compte ; sans elle, les actions d\'envoi n\'apparaissent pas, et un cabinet lié n\'envoie jamais.',
            'tests' => [
                'tests/Feature/Filament/EnvoieLesDocumentsTest.php::test_sans_la_permission_d_envoyer_les_actions_d_envoi_n_apparaissent_pas',
                'tests/Feature/Authorization/AvoirPolicyTest.php::test_un_compte_de_cabinet_voit_les_avoirs_de_toute_l_equipe_sans_les_envoyer',
                'tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php::test_un_collaborateur_n_envoie_aucun_e_mail_au_client_du_magasin',
            ],
        ],

        'societe.parametres-locaux-du-magasin' => [
            'puce' => 'Société et établissements',
            'regle' => 'Chaque magasin garde ses paramètres locaux (coordonnées, e-mail et téléphone, références d\'acheteur, barème, relances, échéance et mentions), réglés par qui gère le magasin, jamais par un cabinet lié ni dans un magasin fermé.',
            'tests' => [
                'tests/Feature/Filament/CoordonneesMagasinTest.php::test_le_responsable_regle_l_e_mail_et_le_telephone_du_magasin',
                'tests/Feature/Filament/CoordonneesMagasinTest.php::test_un_vendeur_n_y_accede_pas',
                'tests/Feature/Filament/CoordonneesMagasinTest.php::test_les_coordonnees_restent_celles_du_magasin_actif',
                'tests/Feature/Filament/CoordonneesMagasinTest.php::test_ni_un_compte_de_cabinet_ni_un_magasin_ferme_ne_reglent_les_coordonnees',
                'tests/Feature/Societe/MagasinResourceTest.php::test_l_etablissement_principal_n_a_aucun_effet_sur_les_reglages',
            ],
        ],

        'achats.cout-visible-avec-la-valeur-du-stock' => [
            'puce' => 'Achats et stock',
            'regle' => 'Le coût d\'un produit et le bénéfice d\'une vente ne s\'affichent qu\'à qui voit la valeur du stock (série 8, Q9).',
            'tests' => [
                'tests/Feature/Filament/SelecteurDeProduitsTest.php::test_le_cout_et_le_benefice_ne_s_affichent_qu_avec_l_autorisation_de_voir_la_valeur_du_stock',
            ],
        ],

        'outillage.tests-en-navigateur' => [
            'puce' => 'Outillage',
            'regle' => 'Aucun outil Node tant qu\'aucun thème d\'écran personnalisé n\'existe, sauf Playwright, qui ne sert qu\'aux tests en navigateur ; chaque action du code figure dans l\'inventaire des actions, qui nomme les tests en navigateur qui la cliquent.',
            'tests' => [
                'tests/Feature/OutillageTest.php::test_package_json_ne_declare_que_playwright_pour_les_tests_en_navigateur',
                'tests/Browser/InventaireDesActionsTest.php › chaque action déclarée dans le code figure dans l’inventaire',
                'tests/Browser/InventaireDesActionsTest.php › chaque entrée de l’inventaire nomme le lot de ses tests et ne cite que des tests qui existent',
            ],
        ],
        'outillage.fournisseurs-externes-derriere-une-interface' => [
            'puce' => 'Vérification de la société',
            'regle' => 'Chaque fournisseur externe (INSEE, INPI, envoi recommandé, annuaire de l\'Ordre, SUPER PDP, antivirus) est derrière une interface, avec une implémentation factice pour les tests et la démonstration, jamais acceptée en production ; ses clés ne sont jamais dans le dépôt.',
            'tests' => [
                'tests/Feature/Providers/FournisseursExternesServiceProviderTest.php::test_aucun_pilote_factice_n_est_accepte_en_production',
                'tests/Feature/Providers/FournisseursExternesServiceProviderTest.php::test_chaque_fournisseur_suit_son_pilote_et_un_pilote_inconnu_est_refuse',
                'tests/Feature/Providers/FournisseursExternesServiceProviderTest.php::test_la_plateforme_agreee_suit_son_pilote',
            ],
        ],
        'hors.donnees-de-demonstration' => [
            'puce' => 'Hors de CLAUDE.md',
            'regle' => 'php artisan migrate:fresh --seed remplit la base de développement par les vraies actions et les fournisseurs factices, et donne la liste des comptes pour se connecter, chacun avec un mot de passe tiré au hasard ou celui, commun, de PILIQI_DEMO_MOT_DE_PASSE ; le planificateur ne lance aucune tâche tant qu\'il dure ; il refuse, sans rien écrire, la production, un fournisseur réel (répertoire Sirene, registre national des entreprises, courrier recommandé, annuaire de l\'Ordre, plateforme agréée, antivirus), une base qui a déjà des sociétés et un mot de passe commun hors de la règle.',
            'tests' => [
                'tests/Feature/DatabaseSeederTest.php::test_le_semis_remplit_la_base_par_les_vraies_actions_et_donne_la_liste_des_comptes',
                'tests/Feature/DatabaseSeederTest.php::test_le_planificateur_ne_lance_aucune_tache_pendant_le_semis',
                'tests/Feature/Calendrier/PlanificateurTest.php::test_aucune_tache_planifiee_ne_part_pendant_le_semis_des_donnees_de_demonstration',
                'tests/Feature/DatabaseSeederTest.php::test_le_semis_est_refuse_en_production_sans_rien_ecrire',
                'tests/Feature/DatabaseSeederTest.php::test_le_semis_est_refuse_avec_un_fournisseur_reel_sans_rien_ecrire',
                'tests/Feature/DatabaseSeederTest.php::test_le_semis_est_refuse_dans_une_base_qui_a_deja_des_societes',
                'tests/Feature/DatabaseSeederTest.php::test_un_mot_de_passe_commun_hors_de_la_regle_est_refuse_sans_rien_ecrire',
                'tests/Feature/DatabaseSeederTest.php::test_le_mot_de_passe_commun_va_a_chaque_compte_sauf_s_il_contient_son_identifiant',
                'tests/Feature/DatabaseSeederTest.php::test_sans_mot_de_passe_commun_chaque_compte_recoit_un_mot_de_passe_tire_au_hasard',
                'tests/Browser/Parcours/DonneesDeDemonstrationTest.php › les comptes de la liste du semis se connectent et retrouvent leurs données',
                'tests/Browser/Parcours/DonneesDeDemonstrationTest.php › le vendeur qui garde son identifiant pour mot de passe ne se connecte que depuis un poste reconnu',
            ],
        ],
    ],

    'decisions' => [
        'Page d\'arbitrages du 09/10/2026 (decisions-editeur.md)' => [
            'A1' => [
                'decision' => 'Créer un membre avec l\'adresse e-mail d\'un compte qui existe déjà : Refuser l\'adresse : chaque compte n\'appartient qu\'à un seul magasin.',
                'remplacee' => 'Remplacée par l\'architecture société (série 4, S1 et V1-1 ; CLAUDE.md, « Comptes et équipe ») : un compte appartient à une société, son identifiant le désigne, et l\'adresse d\'un compte non propriétaire n\'est qu\'un contact facultatif, qui peut être celle de plusieurs comptes.',
            ],
            'A2' => [
                'decision' => 'Magasin suspendu ou fermé : Lecture seule : consultation et téléchargements possibles, plus aucune vente ; clôtures et déclarations continuent jusqu\'au bout.',
                'regles' => ['statuts.lecture-seule'],
            ],
            'B1' => [
                'decision' => 'Taux de TVA d\'une ligne de produit à la vente : Autre choix (note de l\'éditeur : « ca depend ou il vend car des fois il peux vendre à l\'etranger ou par exemple hors de la france, et je crois que dans ces peut changer la tva, je te laisse confimer toi qu\'est ce que c\'est ces regles sur les sites francais »)',
                'regles' => ['prix.taux-par-defaut', 'hors-france.natures-d-operation'],
            ],
            'B2' => [
                'decision' => 'Taux de TVA autorisés : Autre choix (note de l\'éditeur : « Garder la saisie libre mais par defaut 20% car le logiciel va etre utiliser en france, et si le logiciel sera utiliser ailleurs alors selon les regles du pay »)',
                'regles' => ['prix.taux-par-defaut'],
            ],
            'B3' => [
                'decision' => 'Paiement supérieur aux factures qu\'il règle : Refuser : le montant reçu doit être égal au total imputé sur les factures.',
                'regles' => ['paiements.montant-egal-au-total-impute'],
            ],
            'B4' => [
                'decision' => 'Paiement saisi par erreur : Contre-passation : un paiement d\'annulation, lié à l\'original et tracé, annule ses imputations ; on saisit ensuite le bon paiement.',
                'regles' => ['paiements.contre-passation'],
            ],
            'B5' => [
                'decision' => 'Remboursement d\'un retour : Seulement un crédit à valoir, comme aujourd\'hui.',
                'regles' => ['paiements.rien-n-est-rembourse'],
            ],
            'B6' => [
                'decision' => 'Crédit d\'avoir d\'un client qui a déjà une facture impayée : Appliquer automatiquement le crédit à ses factures impayées, de la plus ancienne à la plus récente.',
                'regles' => ['paiements.rien-n-est-rembourse'],
            ],
            'B7' => [
                'decision' => 'Date d\'échéance des factures : Échéance = date d\'émission + délai du client, sinon 30 jours (réglable dans les paramètres), recalculée au moment de l\'émission.',
                'regles' => ['documents.echeance'],
            ],
            'B8' => [
                'decision' => 'Mode de paiement préféré du client : Le présélectionner à l\'encaissement (choisi dans la liste des modes de paiement).',
                'regles' => ['fiches.mode-de-paiement-prefere'],
            ],
            'B9' => [
                'decision' => 'Mentions obligatoires entre professionnels : Pénalités : 3 fois le taux d\'intérêt légal ; indemnité forfaitaire de 40 € ; « Pas d\'escompte pour paiement anticipé ». Textes réglables par magasin.',
                'regles' => ['documents.mentions-et-assurance'],
            ],
            'B10' => [
                'decision' => 'Magasin en franchise en base de TVA : Ventes à 0 % avec la mention « TVA non applicable, art. 293 B du CGI » ; le prix TTC des produits ne change pas, le client paie le même prix.',
                'regles' => ['prix.exoneration-et-franchise'],
            ],
            'B11' => [
                'decision' => 'Identité légale du magasin incomplète : Bloquer l\'émission d\'une facture à un professionnel tant que ces mentions manquent ; les tickets restent possibles.',
                'regles' => ['documents.identite-legale-complete'],
            ],
            'B12' => [
                'decision' => 'SIREN des clients professionnels : Obligatoire pour un professionnel français, format contrôlé (9 ou 14 chiffres) ; n° de TVA ou autre identifiant pour un client étranger.',
                'regles' => ['fiches.identifiants-du-client-professionnel'],
            ],
            'B13' => [
                'decision' => 'Client imprimé sur une facture déjà émise : Figer à l\'émission le nom, l\'adresse, les identifiants et le type du client : une facture émise ne change plus jamais.',
                'regles' => ['documents.client-fige-et-facture-immuable'],
            ],
            'B14' => [
                'decision' => 'Suppression d\'un devis : Seul un devis brouillon se supprime ; un devis numéroté reste (refusé ou expiré).',
                'regles' => ['documents.devis'],
            ],
            'B15' => [
                'decision' => 'Devis dont la date de validité est dépassée : Passage automatique à « Expiré », plus d\'acceptation possible ; modifier un devis envoyé le repasse en brouillon.',
                'regles' => ['documents.devis'],
            ],
            'B16' => [
                'decision' => 'Confidentialité entre vendeurs : Un vendeur ne voit et n\'encaisse que ses factures ; son tableau de bord montre « Mes ventes ». La permission « voir toutes les factures » donne accès au reste.',
                'regles' => ['vendeurs.ses-factures'],
            ],
            'B17' => [
                'decision' => 'Chiffre d\'affaires du tableau de bord : Net des avoirs, en HT, avec le TTC à côté.',
                'regles' => ['vendeurs.chiffre-d-affaires-net-des-avoirs'],
            ],
            'B18' => [
                'decision' => 'Relevé « Fermer ma caisse » du vendeur : Afficher séparément le TTC vendu, les encaissements par mode de paiement, le crédit d\'avoir utilisé et le reste dû.',
                'regles' => ['vendeurs.releve-de-caisse'],
            ],
            'B19' => [
                'decision' => 'Supprimer un client ou un produit déjà utilisé : Il ne se supprime pas : il s\'archive, n\'est plus proposé, et reste lisible sur les anciens documents. Le client comptoir ne se supprime jamais.',
                'regles' => ['fiches.archivees-jamais-supprimees', 'fiches.client-comptoir'],
            ],
            'C1' => [
                'decision' => 'Retour de marchandise au fournisseur : Sortir le retour au prix de la ligne d\'achat retournée et recalculer le coût moyen.',
                'regles' => ['achats.retour-au-cout-de-la-ligne'],
            ],
            'C2' => [
                'decision' => 'Modifier une facture d\'achat déjà validée (stock déjà entré) : Exige la permission « valider les factures d\'achat ».',
                'regles' => ['achats.fiche-produit-et-fournisseur-du-dernier-achat'],
            ],
            'C3' => [
                'decision' => 'Achat manuel d\'un produit reçu par facture électronique : Autre choix (note de l\'éditeur : « Il devient celui du dernier achat, même saisi à la main. la reconnaissace du fourniseur se fait par son siret, s\'il se trouve deja le fournisseur dans la fiche fourniseur »)',
                'regles' => ['achats.fiche-produit-et-fournisseur-du-dernier-achat'],
            ],
            'C4' => [
                'decision' => 'Brouillon de facture d\'achat supprimé : Les nouveaux produits ne sont créés qu\'à la validation de la facture.',
                'regles' => ['achats.produit-ne-d-un-achat-ou-d-un-inventaire'],
            ],
            'D1' => [
                'decision' => 'Factures aux professionnels dans les clôtures de caisse : Oui : toutes les ventes et leurs règlements entrent dans les clôtures et les grands totaux ; l\'e-reporting ne déclare que la part des particuliers.',
                'regles' => ['caisse.contenu-de-la-cloture'],
            ],
            'D2' => [
                'decision' => 'Journée sans aucune vente : Autre choix (note de l\'éditeur : « il faut avoir une trace de tickte Z, donc meme zero, pour montrer que cette jour il n\'avais de vente, comme meme pour la gestion dans le magasin pour regader les jounees avec ces ventes, comme ca il marque zero. mais pour envoyer à l\'etat… »)',
                'regles' => ['caisse.cloture-automatique-et-rattrapage'],
            ],
            'D3' => [
                'decision' => 'Journée de caisse et clôture : Autre choix (note de l\'éditeur : « Créer un bouton pour clôture manuelle par le magasin en fin de journée. Si la clôture manuelle n\'est pas faite, déclencher une clôture automatique à minuit. et la cloture doit etre par rapport aux permissions selon le rôle des employés »)',
                'regles' => ['caisse.cloture-jamais-bloquee', 'caisse.cloture-automatique-et-rattrapage'],
            ],
            'D4' => [
                'decision' => 'Exercice comptable du magasin : Mois de fin d\'exercice réglable (décembre par défaut), verrouillé après la première vente.',
                'regles' => ['caisse.exercice-de-la-societe'],
            ],
            'D5' => [
                'decision' => 'Durée de conservation des données de caisse et des factures électroniques : 10 ans pour tout, sans aucune purge, même après la résiliation du magasin.',
                'regles' => ['caisse.conservation-et-effacements'],
            ],
            'D6' => [
                'decision' => 'Résiliation d\'un magasin : Clôture et archive signée finales, automatiques, à la résiliation.',
                'a_venir' => 'Étape 3 (caisse sécurisée, fin) et étape 12 (résiliation de la société) : clôtures journalière, mensuelle et de l\'exercice et archive signées finales, automatiques.',
            ],
            'D7' => [
                'decision' => 'Numéro de la clôture du 31 décembre : Numéroter selon l\'année de la journée clôturée.',
                'regles' => ['caisse.cloture-automatique-et-rattrapage'],
            ],
            'D8' => [
                'decision' => 'PDF d\'une facture ou d\'un ticket : Le PDF reproduit le document exactement comme émis (une réimpression porte la mention « Duplicata ») ; « Payé / Restant dû » reste visible sur la fiche.',
                'regles' => ['documents.client-fige-et-facture-immuable', 'documents.reglements-imprimes-et-recus'],
            ],
            'D9' => [
                'decision' => 'Ventes abandonnées en caisse : Inscrire au journal des événements l\'abandon d\'une vente et chaque ligne retirée avant validation.',
                'regles' => ['caisse.journal-des-evenements'],
            ],
            'D10' => [
                'decision' => 'Postes de caisse : Une seule caisse par magasin pour l\'instant.',
                'regles' => ['caisse.une-seule-caisse-par-magasin'],
            ],
            'D11' => [
                'decision' => 'Attestation de conformité de l\'éditeur : Une attestation par magasin. (note de l\'éditeur : « mais si la meme entreprise utilise plusieurs magasin alors comment faire? faut il enregister chaque magasin mais alors sera toujours le meme siren? »)',
                'a_venir' => 'Étape 3 : une attestation de l\'éditeur par magasin, même pour plusieurs magasins d\'une même société.',
            ],
            'D12' => [
                'decision' => 'Signature du volet 2 par le magasin : Signature électronique dans l\'application, ou à la main sur le PDF.',
                'regles' => ['comptes.signature-des-attestations'],
            ],
            'D13' => [
                'decision' => 'Extensions PHP de signature et d\'archivage : Les déclarer dans composer.json.',
                'regles' => ['outillage.licence-et-extensions'],
            ],
            'E1' => [
                'decision' => 'TVA des factures électroniques entre professionnels : Factures aux professionnels : HT de la ligne = quantité × prix TTC ÷ (1 + taux), arrondi une fois ; TVA = total HT × taux. Exemple : 833,33 € HT + 166,67 € = 1 000,00 €. Le total reste celui du prix affiché, à quelques centimes près sur une facture de nombreuses lignes.',
                'remplacee' => 'Remplacée par la série 8 (R6, politique d\'arrondi unique) : toute vente, à un professionnel comme à un particulier, se calcule depuis les prix TTC, le HT de chaque taux arrondi une fois (CLAUDE.md, « Prix et TVA des ventes »).',
            ],
            'E2' => [
                'decision' => 'Ordre de la feuille de route : Après les corrections : SUPER PDP (connexion et réception des factures d\'abord), puis la caisse sécurisée et la suite de la feuille de route.',
                'hors_logiciel' => 'Ordre de la feuille de route de CLAUDE.md, suivi par la chaîne des lots.',
            ],
            'E3' => [
                'decision' => 'Factures d\'achat reçues par la plateforme : Importées en brouillon ; l\'entrée en stock reste une validation manuelle ; un produit inconnu est créé automatiquement ; les cas non gérés (quantités décimales, kg, litres, remises de pied de facture) passent en saisie manuelle.',
                'a_venir' => 'Étape 2 (SUPER PDP) : réception des factures d\'achat, importées en brouillon, l\'entrée en stock restant une validation manuelle.',
            ],
            'E4' => [
                'decision' => 'Qui peut refuser ou contester une facture reçue : Le propriétaire et l\'administrateur.',
                'a_venir' => 'Étape 2 (SUPER PDP) : refuser ou contester une facture reçue, réservé au propriétaire et aux administrateurs autorisés.',
            ],
            'E5' => [
                'decision' => 'Statut « encaissée » des factures de services : Envoyé automatiquement à chaque paiement d\'une facture de services, avec le montant et la date, sauf pour un magasin qui a opté pour les débits.',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services'],
            ],
            'E6' => [
                'decision' => 'Paiements des particuliers pour des services : Les déclarer une fois la règle confirmée avec votre expert-comptable ; rien n\'est envoyé d\'ici là.',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services'],
            ],
            'E7' => [
                'decision' => 'Clients publics (mairies…) et professionnels non assujettis : Ces factures restent hors plateforme, avec un message sur la facture.',
                'a_venir' => 'Étape 2 (SUPER PDP) : factures aux clients publics et aux professionnels non assujettis hors plateforme, avec un message.',
            ],
            'E8' => [
                'decision' => 'Facture rejetée par la plateforme : Redéposer la facture corrigée sous le même numéro.',
                'a_venir' => 'Étape 2 (SUPER PDP) : redépôt sous le même numéro d\'une facture rejetée.',
            ],
            'E9' => [
                'decision' => 'Crédit d\'avoir dans le « montant déjà payé » de la facture électronique : Compté comme déjà payé, avec la note « dont X € imputés de l\'avoir N ».',
                'a_venir' => 'Étape 2 (SUPER PDP) : crédit d\'avoir compté dans le montant déjà payé de la facture électronique.',
            ],
            'E10' => [
                'decision' => 'Connecter ou déconnecter le compte SUPER PDP du magasin : Le propriétaire du magasin seulement.',
                'remplacee' => 'Remplacée par la série 4, S2 : une seule connexion par société, faite par son propriétaire (CLAUDE.md, « Facture électronique (SUPER PDP) ») ; à brancher à l\'étape 2.',
            ],
            'E11' => [
                'decision' => 'Plusieurs magasins de la même société (même SIREN) : Autorisé, avec une adresse de réception par établissement (SIRET).',
                'regles' => ['societe.magasins-crees-dans-l-espace-de-la-societe', 'societe.code-du-magasin'],
            ],
            'E12' => [
                'decision' => 'Documents émis avant la connexion à SUPER PDP : Bouton « Transmettre » pour les factures émises avant la connexion ; pas de rattrapage des clôtures antérieures.',
                'a_venir' => 'Étape 2 (SUPER PDP) : bouton « Transmettre » des factures émises avant la connexion, sans rattrapage des clôtures antérieures.',
            ],
            'E13' => [
                'decision' => 'Ventes à 0 % de TVA (export, autoliquidation…) : Transmission refusée, avec un message clair, tant que le motif d\'exonération n\'est pas géré.',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services'],
            ],
            'E14' => [
                'decision' => 'Déclaration des ventes aux particuliers (e-reporting) : Une déclaration par clôture journalière, suivie jusqu\'à l\'accusé de réception ; les journées manquées sont rattrapées.',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services', 'caisse.contenu-de-la-cloture'],
            ],
            'E15' => [
                'decision' => 'Vérification des envois contre la spécification SUPER PDP dans les tests : Un petit vérificateur intégré aux tests, sans nouvelle dépendance.',
                'a_venir' => 'Étape 2 (SUPER PDP) : vérificateur des envois contre la spécification, intégré aux tests, sans nouvelle dépendance.',
            ],
            'F1' => [
                'decision' => 'Paquets installés mais jamais utilisés : Les retirer de composer.json.',
                'regles' => ['outillage.aucun-paquet-inutilise'],
            ],
            'K1' => [
                'decision' => 'Retour au fournisseur qui ferait passer la valeur du stock sous zéro : Le valoriser à 0 € : un stock ne vaut jamais moins que rien.',
                'regles' => ['achats.retour-au-cout-de-la-ligne'],
            ],
            'K2' => [
                'decision' => 'Retour partiel sur une facture à un professionnel : Chaque retour se recalcule comme la facture, le dernier soldant la ligne au centime : 1er article 33,33 € HT + 6,67 € = 40,00 €, 2e article 33,34 € + 6,66 € = 40,00 €.',
                'regles' => ['prix.retours'],
            ],
            'K3' => [
                'decision' => 'Prix payé pour une vente exonérée (export, DOM, livraison intracommunautaire) : Le client paie le prix hors taxe : prix TTC du catalogue ÷ (1 + taux). Exemple : 1 000 articles à 1,00 € TTC à 20 % font 833,33 € pour un professionnel.',
                'regles' => ['prix.exoneration-et-franchise'],
            ],
            'K4' => [
                'decision' => 'Vente après la clôture manuelle de la journée : La caisse refuse tout nouveau ticket jusqu\'à minuit, avec le message « La journée du 09/10/2026 est clôturée : les ventes reprennent demain. » Chaque clôture couvre exactement sa journée. (note de l\'éditeur : « mais comme le message alert dit que si vous fermez alors vourer plus faires des ventes »)',
                'regles' => ['caisse.cloture-jamais-bloquee'],
            ],
            'K5' => [
                'decision' => 'Qui change un réglage commun à plusieurs magasins d\'une même société : Le magasin principal le change ; les autres ne peuvent plus émettre de facture tant qu\'ils ne se sont pas alignés.',
                'remplacee' => 'Remplacée par l\'architecture société (série 4) : les réglages fiscaux communs n\'existent qu\'une fois, sur la société, et aucun magasin n\'a de privilège sur les autres (CLAUDE.md, « Société et établissements »).',
            ],
            'K6' => [
                'decision' => 'Avoir sur un ticket anonyme (client comptoir) : Le n° de l\'avoir sert de bon d\'avoir : à une prochaine vente au comptoir, la caisse propose « Utiliser un bon d\'avoir » et impute son crédit restant.',
                'regles' => ['paiements.bon-d-avoir'],
            ],
            'K7' => [
                'decision' => 'Mention d\'immatriculation (RCS, RM) sur les factures : L\'exiger, comme les autres mentions, avant toute facture à un professionnel.',
                'regles' => ['documents.identite-legale-complete'],
            ],
            'K8' => [
                'decision' => 'Devis déjà accepté par le client : Modifiable comme un devis envoyé : il repasse en brouillon et perd son acceptation.',
                'regles' => ['documents.devis'],
            ],
            'K9' => [
                'decision' => 'Crédit d\'avoir disponible après une contre-passation : La contre-passation annule exactement l\'erreur ; quand le bon paiement est saisi (ex. 10,00 € en espèces), le crédit solde le reste de la facture (90,00 €) et il reste 10,00 € de crédit.',
                'regles' => ['paiements.contre-passation'],
            ],
            'K10' => [
                'decision' => 'Facture rejetée par la plateforme, redéposée sous le même numéro : Le redépôt ne corrige que les données de transmission ajoutées hors du document signé (adresse électronique du client, cadre de facturation) ; une erreur dans le document lui-même (montants, client, lignes) se corrige par un avoir et une nouvelle facture.',
                'a_venir' => 'Étape 2 (SUPER PDP) : le redépôt ne corrige que les données de transmission ajoutées hors du document signé.',
            ],
            'I1' => [
                'decision' => 'Natures d\'opération proposées sur une facture : D\'abord : Vente en France, Export hors UE expédié par le magasin, Livraison vers un DOM, Livraison intracommunautaire à un professionnel, Prestation à un professionnel de l\'UE (autoliquidation), Prestation à un professionnel hors UE. Ensuite : la vente à distance UE au-delà du seuil (OSS) et l\'export enlevé par le client.',
                'regles' => ['hors-france.natures-d-operation'],
            ],
            'I2' => [
                'decision' => 'Vérification du n° de TVA d\'un client professionnel de l\'UE : Vérification automatique sur VIES (service de la Commission européenne), consultation datée conservée ; sans n° valide, la vente reste à la TVA française.',
                'regles' => ['hors-france.natures-d-operation'],
            ],
            'I3' => [
                'decision' => 'Détaxe des voyageurs résidant hors UE : Le ticket reste à la TVA française ; on y note le n° du bordereau de détaxe ; la TVA est régularisée seulement après le visa.',
                'regles' => ['hors-france.detaxe'],
            ],
            'I4' => [
                'decision' => 'Preuves d\'export et de livraison intracommunautaire : Enregistrer les références des preuves sur la facture et rappeler celles qui manquent.',
                'regles' => ['hors-france.preuves-d-export'],
            ],
            'J1' => [
                'decision' => 'Numérotation des factures dans une société à plusieurs magasins : Chaque magasin a un code court, unique dans la société et définitif dès son premier document (ex. LYON1), inséré dans tous ses numéros : FAC-LYON1-2026-000001.',
                'regles' => ['societe.code-du-magasin'],
            ],
            'J2' => [
                'decision' => 'Réglages fiscaux communs à la société : Contrôle bloquant : un magasin ne peut pas enregistrer un réglage différent de celui des autres magasins du même SIREN (le logiciel lit seulement ces réglages chez eux).',
                'remplacee' => 'Remplacée par l\'architecture société (série 4) : les réglages fiscaux communs sont portés par la société, en un seul exemplaire (CLAUDE.md, « Société et établissements »).',
            ],
            'J3' => [
                'decision' => 'Connexion SUPER PDP de plusieurs magasins d\'une même société : Une connexion par magasin, avec sa propre adresse de réception (SIRET). Le logiciel lit seulement les adresses de réception des autres magasins du même SIREN pour ranger chaque facture reçue au bon endroit, et une même facture ne peut jamais être importée dans deux magasins.',
                'remplacee' => 'Remplacée par la série 4, S2 : une connexion par société, une adresse de réception par magasin (SIREN_SIRET) ; à brancher à l\'étape 2.',
            ],
            'J4' => [
                'decision' => 'Facture reçue adressée à la société sans préciser le magasin : Elle va au « magasin principal » que la société désigne (un seul par SIREN), qui est alerté.',
                'remplacee' => 'Remplacée par la série 4, S2 (note de l\'éditeur) : attribution automatique au magasin que désignent avec certitude le SIRET destinataire, l\'adresse électronique ou le code de routage, la liste « À attribuer » de la société pour le reste ; à brancher à l\'étape 2.',
            ],
            'J5' => [
                'decision' => 'Transfert de marchandises entre magasins de la même société : Pas pour l\'instant : chaque magasin reste séparé.',
                'regles' => ['societe.retour-dans-le-magasin-qui-a-vendu', 'produit.magasins-isoles'],
            ],
            'J6' => [
                'decision' => 'Retour ou paiement dans un autre magasin de la même société : Refusé : un retour ou un paiement se fait dans le magasin qui a vendu.',
                'regles' => ['societe.retour-dans-le-magasin-qui-a-vendu'],
            ],
            'J7' => [
                'decision' => 'SIRET d\'un magasin supprimé : Jamais réutilisable par un autre magasin.',
                'regles' => ['statuts.jamais-supprimes'],
            ],
            'J8' => [
                'decision' => 'Clôture Z à zéro : quels jours : Chaque jour du calendrier à partir de la première vente, dimanches et jours de fermeture compris.',
                'regles' => ['caisse.cloture-automatique-et-rattrapage'],
            ],
            'H1' => [
                'decision' => 'Nom et adresse e-mail de connexion d\'un membre : Le membre les change lui-même dans son profil, en donnant son mot de passe actuel.',
                'regles' => ['comptes.profil'],
            ],
            'H2' => [
                'decision' => 'Capital social sur les factures : Ajouter le capital social à l\'identité du magasin et l\'imprimer quand il est renseigné.',
                'regles' => ['documents.identite-legale-complete'],
            ],
            'H3' => [
                'decision' => 'Licence du logiciel déclarée dans composer.json : Licence propriétaire (« proprietary ») : le code reste à vous.',
                'regles' => ['outillage.licence-et-extensions'],
            ],
            'H4' => [
                'decision' => 'Outils de développement d\'interface inutilisés (Vite, Tailwind CSS…) : Les retirer maintenant ; les réinstaller seulement si un thème d\'écran personnalisé est créé (étape 15).',
                'regles' => ['outillage.aucun-paquet-inutilise'],
            ],
            'G1' => [
                'decision' => 'Facture en retard qui reçoit un paiement partiel : Elle reste « En retard » tant qu\'il reste un montant dû après l\'échéance (appliqué).',
                'regles' => ['paiements.facture-en-retard'],
            ],
            'G2' => [
                'decision' => 'Plafonds d\'envoi d\'e-mails : 60 e-mails par heure et par membre, 200 par heure et par magasin, une relance par jour et par facture (appliqué).',
                'regles' => ['emails.limites-et-relances'],
            ],
            'G3' => [
                'decision' => 'Rythme des relances automatiques : Toujours dans l\'ordre, la n°1 d\'abord, chacune pas avant son délai ; après un démarrage tardif, l\'écart entre deux relances est respecté (appliqué).',
                'regles' => ['emails.limites-et-relances'],
            ],
            'G4' => [
                'decision' => 'Qui peut envoyer les documents par e-mail : Une autre répartition (précisez dans la note). (note de l\'éditeur : « apliquer quand le magasin creer son equipe et la il leur donnes des autorisation ce qu\'il veut. donc tu ajoute tous les options des roles »)',
                'regles' => ['vendeurs.envoi-selon-les-autorisations'],
            ],
            'G5' => [
                'decision' => 'Destinataires des alertes de stock bas : Le propriétaire et l\'administrateur, et tout membre à qui vous donnez la permission de gérer le stock (appliqué).',
                'regles' => ['emails.alertes-de-stock'],
            ],
        ],
        'Quatrième série, réponses du 10/10/2026 (societe/reponses-serie4.md)' => [
            'S1' => [
                'decision' => 'Arrivée des membres : création directe, avec un mot de passe à changer à la première connexion ; un compte d\'une autre société ne se rattache pas (note de l\'éditeur).',
                'regles' => ['comptes.premier-mot-de-passe', 'comptes.un-compte-une-societe'],
            ],
            'S2' => [
                'decision' => 'Connexion SUPER PDP par société : une connexion par société, faite par le propriétaire, une adresse de réception par magasin ; attribution automatique au magasin que désignent avec certitude le SIRET destinataire, l\'adresse électronique ou le code de routage, la liste « À attribuer » pour le reste, chaque attribution journalisée (note de l\'éditeur).',
                'a_venir' => 'Étape 2 (SUPER PDP) : plan SUPER PDP révisé par le lot 21 selon cette réponse.',
            ],
            'S3' => [
                'decision' => 'Adresse du siège sur les factures : enregistrée sur la société, exigée avant une facture à un professionnel, pas répétée quand elle est celle du magasin, figée à l\'émission.',
                'regles' => ['documents.identite-legale-complete', 'documents.client-fige-et-facture-immuable'],
            ],
            'S4' => [
                'decision' => 'Capital social : sur la société, exigé et imprimé pour les formes commerciales, jamais pour un entrepreneur individuel, figé à l\'émission.',
                'regles' => ['documents.identite-legale-complete'],
            ],
            'S5' => [
                'decision' => 'Entrepreneur individuel (micro-entrepreneur compris) : mention « EI », pas de capital, adresse de l\'entreprise à la place du siège, plusieurs établissements sous son SIREN.',
                'regles' => ['documents.identite-legale-complete'],
            ],
            'S6' => [
                'decision' => 'Vérification de l\'identité de la société : contrôle au répertoire Sirene à l\'inscription et à chaque nouveau magasin, opérations commerciales réelles désactivées avant la validation, démonstration ; vérification par courrier (note de l\'éditeur, puis décision du 19/10/2026 sur le courrier recommandé).',
                'regles' => ['verification.controle-sirene-et-territoire', 'verification.avant-la-validation', 'verification.courrier-recommande'],
            ],
            'S7' => [
                'decision' => 'SIREN usurpé et SIRET saisi par erreur : procédure de l\'éditeur sur justificatif, documents jamais modifiés, SIREN et SIRET libérés, tout journalisé ; vérification par courrier recommandé (note de l\'éditeur).',
                'regles' => ['societe.siren-definitif-et-procedure', 'statuts.jamais-supprimes'],
            ],
            'S8' => [
                'decision' => 'Qui ferme et qui rouvre un magasin : la société elle-même ; l\'abonnement s\'arrête à la fermeture et reprend à la réouverture (note de l\'éditeur ; facturation à l\'étape 12, périodes d\'activité tenues dès l\'étape 1).',
                'regles' => ['statuts.fermer-et-rouvrir', 'caisse.fermeture-et-reouverture-du-magasin'],
            ],
            'S9' => [
                'decision' => 'Fin de la caisse d\'un magasin fermé : journées en attente clôturées à la fermeture, plus de ticket Z ; clôtures et archive finales à une fermeture définitive (étape 3).',
                'regles' => ['caisse.fermeture-et-reouverture-du-magasin'],
            ],
            'S10' => [
                'decision' => 'Transfert d\'un établissement à une autre société : rien ne suit ; la société qui reprend saisit son catalogue et fait un inventaire d\'ouverture ; le stock du magasin cédé sort au coût moyen, journalisé.',
                'regles' => ['societe.cession-de-l-activite', 'statuts.ceder-un-magasin'],
            ],
            'S11' => [
                'decision' => 'SIREN corrigé après le premier document : pas de correction ordinaire ; vérification avant tout document réel, SIREN verrouillé ; erreur ou usurpation découverte après la validation : procédure réservée à l\'éditeur, justificatifs, traçabilité, examen des documents (note de l\'éditeur).',
                'regles' => ['societe.siren-definitif-et-procedure', 'societe.regularisation-apres-correction-du-siren'],
            ],
            'S12' => [
                'decision' => 'Retirer le paquet spatie/laravel-permission : retiré, les autorisations de PILIQI rangées avec leur référentiel en base.',
                'regles' => ['outillage.aucun-paquet-inutilise', 'type.familles-d-autorisations'],
            ],
            'S13' => [
                'decision' => 'Adresses IP et navigateurs du journal d\'audit : rangés à part, jamais dans un contenu signé, effacés après 1 an.',
                'regles' => ['audit.traces-a-part-sans-donnee-inutile'],
            ],
            'S14' => [
                'decision' => 'Changement légal de la date de fin d\'exercice : procédure de l\'éditeur sur procès-verbal, jamais rétroactive, journalisée dans chaque magasin.',
                'regles' => ['caisse.exercice-de-la-societe'],
            ],
            'V1-1' => [
                'decision' => 'Interprétation 1 contestée : un membre est strictement rattaché au magasin où il encaisse au moment de la vente, la caisse sait toujours dans quel magasin et par qui (note de l\'éditeur) ; devenu un compte par magasin pour chaque membre (avenant S8V-A4c).',
                'regles' => ['comptes.tracabilite-des-ventes', 'comptes.un-compte-de-membre-un-magasin'],
            ],
            'V1-2' => [
                'decision' => 'Interprétation 2 contestée : l\'administrateur n\'a pas d\'office accès à toute la société ; le propriétaire choisit à la carte ses magasins et ses autorisations (note de l\'éditeur).',
                'regles' => ['comptes.administrateurs-du-proprietaire'],
            ],
            'V1-3' => [
                'decision' => 'Le responsable remplace l\'ancien administrateur de magasin ; une alerte de stock va à toute personne autorisée dans le magasin ; les factures reçues relèvent de l\'administrateur autorisé de la société.',
                'regles' => ['comptes.responsables', 'emails.alertes-de-stock'],
            ],
            'V1-4' => [
                'decision' => 'L\'établissement principal n\'est qu\'une indication, sans aucun effet.',
                'regles' => ['societe.parametres-locaux-du-magasin', 'societe.identite-et-reglages-communs'],
            ],
            'V1-5' => [
                'decision' => 'Une société suspendue ou résiliée se comporte comme un magasin suspendu ou fermé : lecture seule ; son équipe reste gérable.',
                'regles' => ['statuts.societe-suspendue'],
            ],
            'V1-6' => [
                'decision' => 'Aucun écran ne change le statut d\'une société avant l\'administration de l\'éditeur.',
                'a_venir' => 'Étape 13 (administration de l\'éditeur) : suspension, résiliation et réactivation d\'une société.',
            ],
            'V1-7' => [
                'decision' => 'Le journal d\'audit est consultable par le propriétaire seul.',
                'regles' => ['audit.consultable-par-le-proprietaire-seul'],
            ],
            'V1-8' => [
                'decision' => 'Le propriétaire transmet lui-même la propriété à un administrateur actif ; le transfert forcé par l\'éditeur vient à l\'étape 13.',
                'regles' => ['comptes.transmission-de-la-propriete'],
            ],
            'V1-9' => [
                'decision' => 'Une personne retirée d\'une société peut rejoindre une autre société avec le même compte ; un magasin ne change jamais de société.',
                'regles' => ['statuts.ceder-un-magasin'],
                'remplacee' => 'Remplacée en partie par la série 6 et l\'avenant A4 (CLAUDE.md, « Comptes et équipe ») : un compte appartient pour toujours à une seule société ; une personne qui rejoint une autre société y reçoit un nouveau compte. Qu\'un magasin ne change jamais de société tient : une activité reprise s\'ouvre dans le compte de l\'autre société, et le magasin cédé se ferme.',
            ],
            'V1-10' => [
                'decision' => 'L\'e-mail et le téléphone du magasin sont des réglages du magasin.',
                'regles' => ['societe.parametres-locaux-du-magasin'],
            ],
            'V1-11' => [
                'decision' => 'Un changement de SIRET prend effet le jour même ; un déménagement qui change le territoire de TVA passe par un nouveau magasin.',
                'regles' => ['societe.changement-de-siret'],
            ],
            'V1-12' => [
                'decision' => 'La double authentification des propriétaires et administrateurs est reportée à la mise en production.',
                'a_venir' => 'Étape 15 (mise en production) : double authentification des propriétaires et administrateurs.',
            ],
            'V2-1' => [
                'decision' => 'PostgreSQL en production, SQLite pour le développement et les tests rapides, la suite sur les deux.',
                'remplacee' => 'Remplacée par la décision de l\'éditeur du 08/11/2026 (D55) : PostgreSQL seul, en production, en développement, pour les tests et en CI.',
            ],
            'V2-2' => [
                'decision' => 'Une adresse e-mail s\'enregistre et se compare en minuscules.',
                'regles' => ['comptes.adresses-en-minuscules'],
            ],
            'V2-3' => [
                'decision' => 'Les recherches ignorent la casse et les accents.',
                'regles' => ['fiches.recherches-sans-casse-ni-accents'],
            ],
            'V2-4' => [
                'decision' => 'Une référence produit est unique dans le magasin sans tenir compte de la casse.',
                'regles' => ['fiches.reference-produit-unique'],
            ],
            'V2-5' => [
                'decision' => 'Devis : un brouillon à la validité dépassée ne s\'envoie pas ; un devis envoyé échu est expiré dès l\'échéance ; un devis repassé en brouillon perd sa date d\'émission et son PDF l\'indique.',
                'regles' => ['documents.devis'],
            ],
            'T1' => [
                'decision' => 'Place de la campagne : campagne initiale juste après l\'architecture société et les lots de décisions.',
                'a_venir' => 'Étape 1 bis (campagne de tests initiale), relancée aux étapes 2, 3, 5, 9 et 13 ; scénarios dans campagne/strategie/finale.md.',
            ],
            'T2' => [
                'decision' => 'Volume des données fictives : sur cette machine au plus grand volume qu\'elle tient, résultats au-delà signalés comme extrapolés ; campagne finale sur une machine dédiée.',
                'a_venir' => 'Étape 1 bis (campagne de tests initiale) et étape 15 (campagne finale).',
            ],
            'T3' => [
                'decision' => 'Configuration testée : d\'abord la configuration actuelle, puis Redis.',
                'a_venir' => 'Étape 1 bis (campagne de tests initiale).',
            ],
            'T4' => [
                'decision' => 'Composition des équipes fictives : par société un propriétaire, des administrateurs avec et sans vente ; par magasin un responsable et deux vendeurs, dont une même personne dans deux magasins ; comptes d\'entraînement ; un cabinet vérifié lié à deux sociétés.',
                'a_venir' => 'Étape 1 bis (campagne de tests initiale) ; composition écrite dans la feuille de route de CLAUDE.md.',
            ],
            'T5' => [
                'decision' => 'Gravité des anomalies : Claude classe et corrige, le rapport montre chaque classement, l\'éditeur ne valide que le déclassement d\'une anomalie bloquante.',
                'hors_logiciel' => 'Façon de mener la campagne (étape 1 bis), écrite dans la feuille de route de CLAUDE.md.',
            ],
            'T6' => [
                'decision' => 'Publication des preuves : après chaque palier, dans une page privée, avec leur empreinte enregistrée dans le dépôt.',
                'hors_logiciel' => 'Façon de mener la campagne (étape 1 bis), écrite dans la feuille de route de CLAUDE.md.',
            ],
            'R1' => [
                'decision' => 'Vente au-delà du stock : refusée, sauf pour qui a « vendre sans stock », avertissement, alerte, quantité négative visible, ventes simultanées cohérentes (note de l\'éditeur).',
                'regles' => ['achats.vente-sans-stock'],
            ],
            'R2' => [
                'decision' => 'Remises et prix des lignes de service : autorisation « accorder des remises et fixer le prix d\'un service ».',
                'regles' => ['prix.remises-et-prix'],
            ],
            'R3' => [
                'decision' => 'Demande répétée après une réponse perdue : un jeton unique par action, lié au contenu, le résultat déjà enregistré renvoyé sans doublon.',
                'regles' => ['demandes-repetees.jeton-unique', 'demandes-repetees.autres-gestes'],
            ],
            'R4' => [
                'decision' => 'Répartition de la TVA d\'un taux sur ses lignes : par cumul, dans l\'ordre des lignes.',
                'regles' => ['prix.calcul-depuis-le-ttc'],
            ],
            'R5' => [
                'decision' => 'Caisse personnelle restée ouverte : la clôture du jour n\'est jamais bloquée et la ferme d\'office ; minuit ferme celle qui ne l\'a pas été (note de l\'éditeur).',
                'regles' => ['caisse.cloture-jamais-bloquee'],
            ],
        ],
        'Cinquième série, réponses du 11/10/2026 (societe/reponses-serie5.md)' => [
            'Q1' => [
                'decision' => 'Prix d\'une ligne de produit : seul qui a « accorder des remises et fixer le prix d\'un service » met sur une ligne de produit un autre prix que sa fiche.',
                'regles' => ['prix.remises-et-prix'],
            ],
            'Q2' => [
                'decision' => 'Deux caisses : une personne n\'encaisse que dans un magasin à la fois et ferme sa caisse d\'un magasin avant d\'encaisser dans un autre.',
                'regles' => ['comptes.une-caisse-a-la-fois'],
                'remplacee' => 'Remplacée en partie par l\'avenant A4 (CLAUDE.md, « Comptes et équipe ») : la règle vaut par compte autorisé à vendre, et une personne qui a un compte dans deux magasins peut avoir une caisse ouverte avec chacun.',
            ],
            'Q3' => [
                'decision' => 'Verrou du SIREN : il se corrige librement avant la validation de la société et se verrouille à sa validation.',
                'remplacee' => 'Remplacée par la décision de l\'éditeur du 19/10/2026 sur la vérification par courrier et par CLAUDE.md : le SIREN d\'une société ne change jamais ; une inscription faite sous un SIREN erroné se clôt faute de code, ce qui libère ce SIREN.',
            ],
            'Q4' => [
                'decision' => 'Durées du journal d\'audit : opérations, réglages et procédures 10 ans ; gestion des accès 5 ans ; vérifications de la société et codes de courrier 5 ans après la fin de la relation ; IP et navigateur 1 an.',
                'regles' => ['caisse.conservation-et-effacements', 'audit.traces-a-part-sans-donnee-inutile'],
            ],
            'Q5' => [
                'decision' => 'Adresse prise à l\'insu de la personne : un compte jamais utilisé peut être refusé par la personne à sa première connexion.',
                'remplacee' => 'Remplacée par l\'avenant A4 et l\'avenant S8V-A4c (CLAUDE.md, « Comptes et équipe ») : seule l\'adresse du propriétaire sert à se connecter ; celle d\'un autre compte n\'est qu\'un contact facultatif que la personne renseigne elle-même, et qui peut être celle de plusieurs comptes.',
            ],
            'Q6' => [
                'decision' => 'Avoir de régularisation après correction exceptionnelle du SIREN : il reprend l\'émetteur figé de la facture d\'origine avec sa mention ; la facture rectificative porte le nouveau SIREN.',
                'regles' => ['societe.regularisation-apres-correction-du-siren'],
            ],
            'Q7' => [
                'decision' => 'Fermeture temporaire : elle ne clôture que ses journées et arrête les tickets Z ; clôtures mensuelle et de l\'exercice et archive finales seulement pour une fermeture définitive (étape 3).',
                'regles' => ['caisse.fermeture-et-reouverture-du-magasin'],
            ],
        ],
        'Série 6, réponses du 11/10/2026 (societe/reponses-serie6.md) et compléments de l\'avenant A4 (societe/avenant-A4-complements.md)' => [
            'roles' => [
                'decision' => 'Organisation de l\'équipe : le propriétaire peut tout faire sans vérification d\'autorisation et seul crée les administrateurs ; l\'administrateur gère la société sans créer de responsables sauf autorisation du propriétaire ; le responsable a tous les droits dans son magasin et crée ses vendeurs ; le vendeur est limité à son magasin.',
                'regles' => ['comptes.proprietaire-sans-vente', 'comptes.administrateurs-du-proprietaire', 'comptes.nomination-des-responsables', 'comptes.responsables'],
            ],
            'responsables-par-magasin' => [
                'decision' => 'Plusieurs responsables par magasin, tous avec tous les droits du magasin ; une même personne peut être responsable de plusieurs magasins.',
                'regles' => ['comptes.responsables'],
            ],
            'droits-du-responsable' => [
                'decision' => 'Le responsable a tout le magasin, pas la société.',
                'regles' => ['comptes.responsables', 'comptes.gestes-reserves-au-proprietaire'],
            ],
            'membres-crees-par-le-responsable' => [
                'decision' => 'Le responsable crée aussi les observateurs de son magasin et leur coche des autorisations du magasin ; le propriétaire le peut aussi.',
                'regles' => ['comptes.responsables', 'comptes.un-compte-de-membre-un-magasin'],
            ],
            'administrateur-nomme-et-retire' => [
                'decision' => 'L\'administrateur autorisé nomme et retire les responsables, seulement dans les magasins auxquels il a accès, jamais le propriétaire ni un administrateur.',
                'regles' => ['comptes.nomination-des-responsables', 'comptes.administrateur-et-responsables'],
            ],
            'refus-d-un-compte' => [
                'decision' => 'Un compte créé par une société peut être refusé tant que son adresse n\'a jamais été confirmée.',
                'remplacee' => 'Remplacée par l\'avenant S8V-A4c (CLAUDE.md, « Comptes et équipe ») : un compte non propriétaire se connecte par son identifiant, son adresse n\'est qu\'un contact que la personne renseigne elle-même ; personne ne prend l\'adresse d\'un autre, et il n\'y a plus de compte à refuser.',
            ],
            'e-mail-reverifie' => [
                'decision' => 'Un e-mail demandé par une personne qui perd son accès avant l\'envoi ne part pas ; le propriétaire est prévenu.',
                'regles' => ['comptes.e-mail-reverifie-a-l-envoi'],
            ],
            'spatie' => [
                'decision' => 'Retrait de spatie/laravel-permission (décision déléguée à Claude) : PILIQI a son propre système d\'autorisations.',
                'regles' => ['outillage.aucun-paquet-inutilise', 'type.familles-d-autorisations'],
            ],
            'expert-comptable-du-proprietaire' => [
                'decision' => 'L\'expert-comptable de la société est créé uniquement par le propriétaire, qui seul peut le retirer (précision corrigée de l\'éditeur).',
                'regles' => ['cabinets.comptes-de-l-expert-comptable-declare'],
            ],
            'expert-comptable-en-lecture' => [
                'decision' => 'L\'expert-comptable voit tous les magasins de la société, présents et futurs, en lecture seule : il ne vend, ne modifie et ne supprime rien.',
                'regles' => ['cabinets.comptes-de-l-expert-comptable-declare', 'cabinets.autorisations-accordees-par-le-client'],
            ],
            'un-seul-role-d-expert-comptable' => [
                'decision' => 'Plus de rôle « comptable » de magasin : un seul rôle d\'expert-comptable de société.',
                'regles' => ['cabinets.comptes-de-l-expert-comptable-declare'],
            ],
            'export-comptable' => [
                'decision' => 'L\'expert-comptable produit et télécharge lui-même l\'export comptable de la société, chaque export inscrit au journal d\'audit.',
                'a_venir' => 'Étape 5 (comptabilité) : export comptable de la société (FEC).',
            ],
            'inscription-sans-magasin' => [
                'decision' => 'Inscription sans magasin : tous les magasins se créent ensuite dans l\'espace de la société, en choisissant l\'établissement dans la liste Sirene des établissements actifs du SIREN.',
                'regles' => ['societe.magasins-crees-dans-l-espace-de-la-societe', 'societe.liste-des-etablissements'],
            ],
            'plusieurs-experts-comptables' => [
                'decision' => 'Plusieurs experts-comptables : chaque personne du cabinet a son propre compte, créé et retiré par le propriétaire seul, et signe ses propres consultations.',
                'regles' => ['cabinets.comptes-de-l-expert-comptable-declare', 'cabinets.au-nom-de-la-personne-et-du-cabinet'],
            ],
            'nomination-immediate' => [
                'decision' => 'La nomination d\'un responsable par un administrateur autorisé prend effet tout de suite ; le propriétaire, prévenu, peut l\'annuler en un clic ; l\'administrateur peut annuler sa propre nomination faite par erreur.',
                'regles' => ['comptes.nomination-des-responsables'],
            ],
            'personne-dans-deux-magasins' => [
                'decision' => 'Seul le propriétaire affecte une même personne à plusieurs magasins ; un responsable ne gère que les comptes de son magasin.',
                'remplacee' => 'Remplacée par l\'avenant S8V-A4c (CLAUDE.md, « Comptes et équipe ») : un compte de membre ne vaut que pour un magasin ; une personne qui travaille dans deux magasins a un compte dans chacun, créé par qui peut y créer ce rôle ; un responsable ne voit ni ne touche jamais les comptes d\'un autre magasin.',
            ],
            'complement-1' => [
                'decision' => 'Compléments de l\'avenant A4, point 1 : plusieurs experts-comptables par société, chacun son compte, en lecture seule sur tous les magasins réels.',
                'regles' => ['cabinets.comptes-de-l-expert-comptable-declare'],
            ],
            'complement-2' => [
                'decision' => 'Compléments de l\'avenant A4, point 2 : nomination immédiate d\'un responsable par un administrateur autorisé, annulable en un clic par le propriétaire.',
                'regles' => ['comptes.nomination-des-responsables'],
            ],
            'complement-3' => [
                'decision' => 'Compléments de l\'avenant A4, point 3 : seul le propriétaire affecte une même personne à plusieurs magasins.',
                'remplacee' => 'Remplacée par l\'avenant S8V-A4c : un compte par magasin pour chaque membre (voir « personne-dans-deux-magasins »).',
            ],
            'complement-4' => [
                'decision' => 'Compléments de l\'avenant A4, point 4 : inscription sans magasin, traitée au lot A4b.',
                'regles' => ['societe.magasins-crees-dans-l-espace-de-la-societe'],
            ],
        ],
        'Série 8, réponses du 17/10/2026 et précisions des 17 et 18/10/2026 (serie8/reponses-serie8.md)' => [
            'Q1-a' => [
                'decision' => 'Un seul calcul pour toutes les ventes, particuliers et professionnels, depuis les prix TTC.',
                'regles' => ['prix.calcul-depuis-le-ttc'],
            ],
            'Q2' => [
                'decision' => 'Un demi-centime exact arrondit le HT au centime supérieur (2,97 € TTC à 20 % : 2,48 € HT et 0,49 € de TVA).',
                'regles' => ['prix.calcul-depuis-le-ttc'],
            ],
            'Q1-b' => [
                'decision' => 'Le PU HT exact, PU TTC ÷ (1 + taux), enregistré avec au plus six décimales, signé, imprimé avec ses décimales utiles ; quantité × PU HT retombe sur le total HT à moins de deux centimes.',
                'regles' => ['prix.pu-ht-a-six-decimales', 'documents.colonnes-ht-ou-ttc'],
            ],
            'Q5' => [
                'decision' => 'Le PDF imprime les règlements reçus à l\'émission ; les paiements reçus ensuite restent sur la fiche, avec leur mode.',
                'regles' => ['documents.reglements-imprimes-et-recus'],
            ],
            'P3' => [
                'decision' => 'Ticket en TTC (référence, désignation, quantité, PU TTC, remise, taux, total TTC, puis le tableau de TVA) ; une facture passe aux colonnes HT.',
                'regles' => ['documents.colonnes-ht-ou-ttc', 'documents.pdf-en-a4'],
            ],
            'Q3-a' => [
                'decision' => 'Les autorisations de l\'administrateur se donnent à l\'ouverture de son compte ; il gère et contrôle, il ne vend que si le propriétaire le lui coche (notes de l\'éditeur).',
                'regles' => ['comptes.administrateur-sans-vente-par-defaut', 'comptes.administrateurs-du-proprietaire'],
            ],
            'Q3-b' => [
                'decision' => 'Un administrateur autorisé garde la contre-passation d\'un paiement erroné et l\'envoi ou la relance d\'un document déjà émis.',
                'regles' => ['comptes.administrateur-sans-vente-par-defaut', 'paiements.contre-passation'],
            ],
            'Q3-c' => [
                'decision' => 'Un administrateur autorisé fixe le prix de référence d\'une prestation au catalogue.',
                'regles' => ['prix.remises-et-prix'],
            ],
            'P7' => [
                'decision' => 'Dans l\'espace de la société, une page réunit les relevés et les caisses encore ouvertes de tous les magasins de la portée de la personne.',
                'regles' => ['caisse.menu-ma-caisse-ou-les-caisses'],
            ],
            'Q4-a' => [
                'decision' => 'L\'identifiant est un nombre de 8 chiffres tiré au hasard, jamais une suite, unique dans tout le logiciel (note de l\'éditeur).',
                'regles' => ['comptes.identifiant-de-huit-chiffres'],
            ],
            'Q4-b' => [
                'decision' => 'Le propriétaire se connecte avec son adresse ou son identifiant, tous les autres avec leur identifiant et leur mot de passe ; un oubli se règle auprès du propriétaire ou de qui peut redonner l\'accès (note de l\'éditeur).',
                'regles' => ['comptes.connexion-par-identifiant', 'comptes.acces-redonne'],
            ],
            'Q4-c' => [
                'decision' => 'Un compte appartient à une seule société et n\'est jamais transféré ; une personne qui rejoint une nouvelle société y reçoit un nouveau compte (note de l\'éditeur).',
                'regles' => ['comptes.un-compte-une-societe'],
            ],
            'Q4-d' => [
                'decision' => 'L\'accès d\'un responsable se renouvelle aussi par un administrateur autorisé à nommer les responsables, la personne présente tapant elle-même son mot de passe, le propriétaire prévenu.',
                'regles' => ['comptes.acces-redonne'],
            ],
            'Q4-e' => [
                'decision' => 'Le propriétaire ne vend pas : il supervise ; « une caisse à la fois » vaut pour les comptes qui vendent (note de l\'éditeur).',
                'regles' => ['comptes.proprietaire-sans-vente', 'comptes.une-caisse-a-la-fois'],
            ],
            'Q4-f' => [
                'decision' => 'Les comptes d\'un magasin cédé ou fermé définitivement sont gardés.',
                'regles' => ['comptes.magasin-cede-ferme-et-entrainement'],
            ],
            'Q4-g' => [
                'decision' => 'Comptes d\'entraînement dans le magasin de démonstration, créés par le propriétaire ou un responsable, retirés à sa fermeture.',
                'regles' => ['comptes.magasin-cede-ferme-et-entrainement'],
            ],
            'Q4-h' => [
                'decision' => 'Le propriétaire ne réalise pas de ventes en caisse : les ventes sont faites par les responsables, les vendeurs et qui en a l\'autorisation (note de l\'éditeur ; depuis la décision du 12/11/2026, il vend dans le magasin de démonstration).',
                'regles' => ['comptes.proprietaire-sans-vente', 'comptes.proprietaire-dans-la-demonstration', 'vendeurs.qui-vend'],
            ],
            'Q4-i' => [
                'decision' => 'Le premier mot de passe est l\'identifiant ; l\'utilisateur le change ensuite depuis son profil (note de l\'éditeur).',
                'regles' => ['comptes.premier-mot-de-passe'],
            ],
            'Q4-j' => [
                'decision' => 'Un administrateur autorisé crée un compte de responsable et en connaît le mot de passe provisoire ; le propriétaire est prévenu de chaque compte créé et de sa première connexion.',
                'regles' => ['comptes.nomination-des-responsables', 'comptes.premiere-connexion-signalee'],
            ],
            'Q4-k' => [
                'decision' => 'Un changement de rôle garde le compte, son identifiant et son historique ; seuls le rôle et les autorisations changent (note de l\'éditeur).',
                'regles' => ['comptes.changement-de-role'],
            ],
            'Q6' => [
                'decision' => 'Un bon d\'avoir s\'utilise à la caisse (vente de tout client) et sur un ticket du client comptoir resté dû, jamais sur une facture faite depuis « Factures ».',
                'regles' => ['paiements.bon-d-avoir'],
            ],
            'Q7' => [
                'decision' => 'Paiements mixtes : une vente ou une facture se règle par plusieurs moyens à la fois (note de l\'éditeur).',
                'regles' => ['paiements.plusieurs-modes', 'paiements.mode-avoir'],
            ],
            'Q8' => [
                'decision' => 'La baisse du prix d\'une ligne avant validation s\'inscrit au journal des événements, avec le prix d\'avant et d\'après.',
                'regles' => ['caisse.journal-des-evenements'],
            ],
            'Q13' => [
                'decision' => 'Une prestation de services réglée par un crédit d\'avoir compte comme encaissée à la date où le crédit est utilisé, sauf l\'avoir de régularisation.',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services'],
            ],
            'Q14' => [
                'decision' => 'Les cinq modes seulement (et « Avoir », usage d\'un crédit), sans moyen propre au magasin.',
                'regles' => ['paiements.plusieurs-modes', 'paiements.mode-avoir'],
            ],
            'Q9' => [
                'decision' => 'Le coût et le bénéfice ne s\'affichent qu\'à qui voit la valeur du stock.',
                'regles' => ['achats.cout-visible-avec-la-valeur-du-stock'],
            ],
            'Q10-a' => [
                'decision' => 'On ne retourne au fournisseur que ce qui est encore en stock.',
                'regles' => ['achats.avoir-fournisseur'],
            ],
            'Q10-b' => [
                'decision' => 'Pas d\'avoir fournisseur sans retour de marchandise pour l\'instant.',
                'regles' => ['achats.avoir-fournisseur'],
            ],
            'Q11-a' => [
                'decision' => 'L\'inventaire d\'ouverture se saisit jusqu\'à la première vente du magasin.',
                'regles' => ['achats.inventaire-d-ouverture'],
            ],
            'Q11-b' => [
                'decision' => 'Par une personne autorisée à valider les achats et à créer des produits.',
                'regles' => ['achats.inventaire-d-ouverture'],
            ],
            'Q12' => [
                'decision' => 'Aucune facture d\'achat de marchandises ne se valide sans sa pièce jointe.',
                'remplacee' => 'Remplacée par la décision de l\'éditeur F1 = b (lot EX, decision-ex.md) : sans sa pièce, une facture d\'achat se valide mais reste marquée « pièce du fournisseur manquante » dans « À faire » jusqu\'à ce qu\'elle soit jointe.',
            ],
            'P8' => [
                'decision' => 'La pièce jointe des factures d\'achat arrive avec le moteur de TVA (lot CH1).',
                'remplacee' => 'Remplacée par le lot EX (pièces du fournisseur), fait avant le moteur de TVA : voir la règle achats.piece-du-fournisseur.',
            ],
            'M1' => [
                'decision' => 'Un magasin fermé, temporairement ou pour cessation, se cède sans être rouvert ni recontrôlé, par le propriétaire seul (nom retapé, mot de passe redemandé), son stock sortant au coût moyen.',
                'regles' => ['statuts.ceder-un-magasin', 'societe.cession-de-l-activite'],
            ],
            'M2' => [
                'decision' => 'Avant la validation de la société, un magasin fermé se rouvre après le contrôle au répertoire Sirene, sans période d\'activité tant que la société n\'est pas validée ; il ne se cède pas avant.',
                'regles' => ['statuts.fermer-et-rouvrir', 'statuts.ceder-un-magasin'],
            ],
            'precision-1' => [
                'decision' => 'Le propriétaire ne vend jamais (hors du magasin de démonstration depuis le 12/11/2026) ; un entrepreneur qui vend lui-même se crée un compte de responsable.',
                'regles' => ['comptes.proprietaire-sans-vente', 'comptes.proprietaire-dans-la-demonstration'],
            ],
            'precision-2' => [
                'decision' => 'Un vendeur qui travaille dans deux magasins de la même société a un compte et un identifiant par magasin.',
                'regles' => ['comptes.un-compte-de-membre-un-magasin', 'produit.une-personne-deux-comptes'],
            ],
            'precision-3' => [
                'decision' => 'Le mot de passe donné à la création est égal à l\'identifiant.',
                'regles' => ['comptes.premier-mot-de-passe'],
            ],
            'precision-4' => [
                'decision' => 'L\'accès d\'un vendeur ou d\'un observateur se redonne aussi par un administrateur que le propriétaire y autorise, dans les magasins réels de sa portée.',
                'regles' => ['comptes.acces-redonne'],
            ],
            'precision-5' => [
                'decision' => 'Le changement du premier mot de passe est facultatif pour un vendeur ou un observateur ; une alerte le conseille à la première connexion (précision du 18/10/2026).',
                'regles' => ['comptes.premier-mot-de-passe'],
            ],
            'Q-V1' => [
                'decision' => 'L\'avoir et le devis ne sont pas des ventes : le propriétaire fait les retours et les devis, précochés pour un administrateur.',
                'regles' => ['comptes.proprietaire-sans-vente', 'comptes.administrateur-sans-vente-par-defaut'],
            ],
            'Q-V2' => [
                'decision' => 'Une autorisation à part, « noter les preuves d\'exonération et les bordereaux de détaxe ».',
                'regles' => ['hors-france.preuves-d-export', 'hors-france.detaxe'],
            ],
            'Q-A' => [
                'decision' => 'Le mot de passe égal à l\'identifiant n\'ouvre le compte que depuis un poste du magasin reconnu, et les essais manqués font attendre.',
                'regles' => ['comptes.poste-reconnu', 'comptes.essais-de-connexion'],
            ],
        ],
        'Décision du 19/10/2026 : vérification des sociétés par courrier recommandé (serie8/decision-verification-courrier.md)' => [
            '1' => [
                'decision' => 'Un SIREN n\'appartient qu\'à une seule société dans tout PILIQI ; la page d\'inscription dit seulement que ce SIREN ne peut pas être inscrit.',
                'regles' => ['verification.siren-unique-et-inscription', 'protocole.unicites-limitees'],
            ],
            '2-A1' => [
                'decision' => 'Inscription par le SIREN, l\'adresse e-mail et le mot de passe du propriétaire, rien de plus ; l\'adresse se confirme par le lien d\'inscription, valable 24 heures.',
                'regles' => ['verification.siren-unique-et-inscription', 'comptes.inscription-par-lien'],
            ],
            '3' => [
                'decision' => 'Une fois l\'adresse confirmée : contrôle au répertoire Sirene, lecture au registre national des entreprises du représentant légal et de l\'adresse du siège, envoi aussitôt de la lettre recommandée.',
                'regles' => ['verification.courrier-recommande'],
            ],
            '4-B1' => [
                'decision' => 'Au nom du représentant légal inscrit au registre, à l\'adresse officielle du siège ; une personne morale dirigeante, à l\'attention de son représentant.',
                'regles' => ['verification.courrier-recommande'],
            ],
            '5' => [
                'decision' => 'Remise contre signature du destinataire en personne, option portée par l\'interface d\'envoi (condition à confirmer avec La Poste lors du contrat).',
                'regles' => ['verification.courrier-recommande'],
            ],
            '6' => [
                'decision' => 'Un code unique, à usage unique, valable 30 jours, et un QR code qui ouvre la page où le saisir sans contenir le code.',
                'regles' => ['verification.courrier-recommande', 'verification.saisie-du-code'],
            ],
            '7' => [
                'decision' => 'Saisir le code valide la société : seule preuve, sans prestataire de vérification d\'identité, sans accord d\'un dirigeant, sans pièces ni connexion à la plateforme agréée.',
                'regles' => ['verification.saisie-du-code'],
            ],
            '8-C1' => [
                'decision' => 'Une inscription garde le SIREN 30 jours au plus après son dernier courrier ; la personne qui saisit le code devient propriétaire ; sans code, l\'inscription se clôt et libère le SIREN.',
                'regles' => ['verification.saisie-du-code', 'verification.inscription-close-sans-code'],
            ],
            '9-D1' => [
                'decision' => 'Un seul nouveau courrier, au plus tôt 15 jours après le premier, qui annule le premier code ; ensuite l\'éditeur traite le cas à la main, sur pièces.',
                'regles' => ['verification.limite-des-courriers'],
            ],
            '10' => [
                'decision' => 'Un courrier ne part qu\'après la confirmation de l\'adresse, une fois par SIREN tant qu\'une inscription attend, pour une entreprise active, dans la limite d\'inscriptions par adresse IP.',
                'regles' => ['verification.limite-des-courriers'],
            ],
            '11-E2' => [
                'decision' => 'La propriété se transmet à un administrateur par un lien envoyé à son adresse, sans preuve de qualité de représentant.',
                'regles' => ['comptes.transmission-de-la-propriete'],
            ],
            '12' => [
                'decision' => 'Attestations signées dans l\'application par le propriétaire qui a validé la société en saisissant le code, sinon à la main.',
                'regles' => ['comptes.signature-des-attestations'],
            ],
            '13' => [
                'decision' => 'Avant la validation, aucune opération commerciale réelle ; chaque magasin réel, changement de SIRET ou réouverture est contrôlé au répertoire Sirene.',
                'regles' => ['verification.avant-la-validation', 'verification.controle-sirene-et-territoire'],
            ],
            '14' => [
                'decision' => 'Chaque fournisseur externe derrière une interface, avec une implémentation factice, sans clé dans le dépôt.',
                'regles' => ['outillage.fournisseurs-externes-derriere-une-interface'],
            ],
            '15' => [
                'decision' => 'Bibliothèque de QR code bacon/bacon-qr-code, accordée par l\'éditeur le 19/10/2026.',
                'regles' => ['verification.courrier-recommande', 'outillage.aucun-paquet-inutilise'],
            ],
            'exception-cabinet' => [
                'decision' => 'Exception : une société invitée par un cabinet d\'expertise comptable vérifié est validée sans courrier.',
                'regles' => ['cabinets.invitation-d-une-entreprise-non-inscrite'],
            ],
        ],
        'Décision du 19/10/2026 : espace des cabinets d\'expertise comptable (serie8/decision-espace-cabinets.md)' => [
            '1-F1' => [
                'decision' => 'Un cabinet est une société de type « cabinet », inscrite et validée comme toute société, code 69.20Z, inscription à l\'Ordre vérifiée.',
                'regles' => ['cabinets.inscription-et-verification', 'cabinets.rien-avant-d-etre-verifie-et-actif'],
            ],
            '2' => [
                'decision' => 'L\'espace du cabinet : un panneau à lui, la liste de ses clients liés, un tableau de bord, l\'accès à chaque client selon ses autorisations.',
                'regles' => ['cabinets.espace-du-cabinet'],
            ],
            '3-J1' => [
                'decision' => 'Le propriétaire du cabinet crée les comptes de ses collaborateurs et choisit les clients que chacun voit.',
                'regles' => ['cabinets.collaborateurs'],
            ],
            '4a-G2' => [
                'decision' => 'Invitation d\'une entreprise pas encore inscrite par son SIREN et l\'adresse de son futur propriétaire, contrôlée au répertoire Sirene, validée sans courrier.',
                'regles' => ['cabinets.invitation-d-un-siren', 'cabinets.invitation-d-une-entreprise-non-inscrite'],
            ],
            '4b' => [
                'decision' => 'Société déjà inscrite : son propriétaire désigne un cabinet vérifié ou accepte sa demande de rattachement ; l\'invitation expire après 7 jours.',
                'regles' => ['cabinets.societe-deja-inscrite', 'cabinets.limites-des-invitations'],
            ],
            '5-H1' => [
                'decision' => 'Par défaut, le cabinet a toutes les autorisations de consultation dans les magasins réels du client ; le propriétaire du client en retire, et en ajoute parmi les achats et le stock ; jamais la vente.',
                'regles' => ['cabinets.autorisations-accordees-par-le-client'],
            ],
            '6' => [
                'decision' => 'Fin du lien par le propriétaire du client ou du cabinet, aussitôt, sessions comprises, inscrite au journal d\'audit des deux.',
                'regles' => ['cabinets.fin-du-lien'],
            ],
            '7-I1' => [
                'decision' => 'Le compte d\'expert-comptable créé par le propriétaire dans sa société disparaît : l\'expert-comptable entre par le lien de son cabinet.',
                'regles' => ['cabinets.comptes-du-cabinet-chez-le-client', 'cabinets.rattachement-de-la-declaration'],
                'remplacee' => 'Remplacée en partie par la décision du 01/11/2026 (ec2/decision-expert-comptable-societe.md) : tant que son cabinet n\'est pas vérifié, l\'expert-comptable entre par un compte que la société lui crée, déclaré par le SIREN de son cabinet et fermé quand ce cabinet s\'y rattache.',
            ],
            '8-K2' => [
                'decision' => 'Isolation : un cabinet ne voit que ses clients liés et ce que chacun autorise ; un collaborateur, seulement ses clients confiés ; rien d\'un client n\'est visible d\'un autre.',
                'regles' => ['produit.cabinets-isoles', 'cabinets.comptes-du-cabinet-chez-le-client'],
            ],
            '9' => [
                'decision' => 'Facturation d\'un cabinet et des clients qu\'il invite.',
                'a_venir' => 'Étape 12 (abonnements).',
            ],
            '10' => [
                'decision' => 'Typologie des entreprises : l\'espace des cabinets n\'en dépend pas ; un client est une société « Commerce ou atelier » ou « Services, sans vente d\'articles ».',
                'regles' => ['type.choix-a-l-inscription', 'type.cabinet-sans-magasin'],
            ],
        ],
        'Réponses du 24/10/2026 aux points laissés par la relecture du lot V2 (serie8/reponses-v2.md)' => [
            '1' => [
                'decision' => 'Nom du compte inscrit : le nom du représentant légal lu au registre national, ou la dénomination d\'une personne morale dirigeante ; une autre personne qui saisit le code prend le nom du destinataire du courrier ; chacun change son nom dans son profil.',
                'regles' => ['hors.nom-du-compte-inscrit', 'comptes.profil'],
            ],
            '2' => [
                'decision' => 'L\'adresse choisie par une autre personne qui saisit le code ne sert à la connexion et à « Mot de passe oublié » qu\'après confirmation par un lien de 24 heures suivi connecté à ce compte ; en attendant, la personne se connecte avec son identifiant ; un lien expiré se redemande depuis le profil ; une adresse de connexion d\'un autre propriétaire est refusée sans rien révéler et revérifiée à la confirmation.',
                'regles' => ['comptes.adresse-de-connexion-du-proprietaire', 'verification.saisie-du-code'],
            ],
            '3' => [
                'decision' => 'À la clôture de l\'inscription, l\'adresse de son propriétaire cesse d\'être une adresse de connexion : le compte ne se connecte plus qu\'avec son identifiant et l\'adresse peut servir à une nouvelle inscription.',
                'regles' => ['verification.inscription-close-sans-code', 'comptes.adresse-de-connexion-du-proprietaire'],
            ],
            '4' => [
                'decision' => 'Un code n\'est jamais annulé après des saisies fausses ; les essais restent limités par adresse IP et comptés ; le code compte 12 caractères de l\'alphabet de Crockford.',
                'regles' => ['hors.code-du-courrier', 'verification.saisie-du-code'],
            ],
            '5' => [
                'decision' => 'Un propriétaire devenu propriétaire par transmission signe les attestations à la main.',
                'regles' => ['comptes.signature-des-attestations'],
            ],
        ],
        'Réponses du 24/10/2026 aux questions du cahier des charges des cabinets (serie8/reponses-cabinets.md)' => [
            'Q-EC1' => [
                'decision' => 'Le cabinet invite son client par son seul SIREN et un lien envoyé par e-mail qui nomme le cabinet ; le client accepte ou refuse ; une entreprise pas encore inscrite, contrôlée au répertoire Sirene, est validée sans courrier ni QR code ; une société déjà inscrite se rattache par une page qui lui montre ses choix ; le courrier ne sert qu\'aux sociétés qui s\'inscrivent elles-mêmes.',
                'regles' => ['cabinets.invitation-d-un-siren', 'cabinets.invitation-d-une-entreprise-non-inscrite', 'cabinets.societe-deja-inscrite', 'cabinets.rattachement-sans-validation'],
            ],
            'Q-EC1-qui-invite' => [
                'decision' => 'Dans le cabinet, seul son propriétaire invite.',
                'regles' => ['cabinets.invitation-d-un-siren'],
            ],
            'Q-EC2' => [
                'decision' => 'À l\'inscription, un menu fait choisir le type d\'activité de la société (cabinet d\'expertise comptable, services sans stock, activités avec stock), qui ne change plus ensuite.',
                'regles' => ['type.choix-a-l-inscription', 'type.definitif'],
            ],
            'Q-EC3' => [
                'decision' => 'La propriété d\'un cabinet se transmet à un collaborateur actif, aux mêmes conditions qu\'à un administrateur ; l\'ancien propriétaire devient collaborateur, avec tous les clients du cabinet confiés.',
                'regles' => ['cabinets.collaborateurs', 'comptes.transmission-de-la-propriete'],
            ],
            'Q-EC4' => [
                'decision' => 'Choix de Claude, que l\'éditeur peut contester : la fin d\'un lien ne ferme pas les sessions, chacune perd l\'accès à sa requête suivante ; au plus 50 invitations par jour et par cabinet ; le mot de passe est redemandé pour inviter.',
                'regles' => ['cabinets.fin-du-lien', 'cabinets.limites-des-invitations', 'cabinets.invitation-d-un-siren'],
            ],
            'Q-EC5' => [
                'decision' => 'Un cabinet qui n\'est plus vérifié n\'a plus aucun accès à ses clients ni aucun nouveau lien, son propriétaire pouvant seulement mettre fin à ses liens ; sa révocation ou sa radiation met fin à ses liens ; sa suspension ou sa résiliation suspend seulement l\'accès.',
                'regles' => ['cabinets.rien-avant-d-etre-verifie-et-actif', 'cabinets.correction-revocation-radiation'],
            ],
            'Q-EC6' => [
                'decision' => 'Une demande de rattachement sans réponse expire après 7 jours, comme l\'invitation.',
                'regles' => ['cabinets.societe-deja-inscrite', 'cabinets.invitation-d-un-siren'],
            ],
            'Q-EC7' => [
                'decision' => 'Le lien et les demandes d\'une société non validée prennent fin quand son inscription est close sans validation ou quand une autre personne saisit le code du courrier ; le cabinet est prévenu ; le nouveau propriétaire choisit lui-même son cabinet.',
                'regles' => ['cabinets.rattachement-sans-validation'],
            ],
        ],
        'Réponses du 24/10/2026 aux questions des types d\'activité (serie8/reponses-types-activite.md)' => [
            'Q-TA1' => [
                'decision' => 'Le menu ne garde que les activités qui ont besoin de stock et les activités de services sans stock, plus le cabinet d\'expertise comptable ; les métiers du bâtiment s\'inscrivent dans les services, sans règle propre.',
                'regles' => ['type.choix-a-l-inscription', 'type.societe-de-services'],
            ],
            'Q-TA2' => [
                'decision' => 'Une société de services ne passe jamais au type commerce, ni l\'inverse, ni vers ou depuis le cabinet, par aucun écran ni aucune procédure de l\'éditeur.',
                'regles' => ['type.definitif'],
            ],
            'TA-2' => [
                'decision' => 'Aucune exception pour les articles fabriqués : un produit naît seulement d\'une facture d\'achat validée ou d\'un inventaire d\'ouverture.',
                'regles' => ['achats.produit-ne-d-un-achat-ou-d-un-inventaire'],
            ],
            'TA-4' => [
                'decision' => 'Choix de Claude, que l\'éditeur peut contester : l\'assurance professionnelle reste facultative pour tous les types qui ont un magasin, imprimée dans le pied des devis et des factures quand elle est renseignée, et figée à l\'émission.',
                'regles' => ['documents.mentions-et-assurance'],
            ],
        ],
        'Réponses du 25/10/2026 aux questions Q1 à Q3 du lot T2 (t2/reponses-t2.md)' => [
            'Q1' => [
                'decision' => 'Chaque magasin suit l\'heure locale de son territoire : journée de caisse et clôture à minuit local, date de ses documents, année de ses numéros, échéances ; un seul endroit connaît le fuseau, la classe Calendrier.',
                'regles' => ['outillage.heure-locale-du-territoire', 'outillage.seul-le-calendrier-connait-un-fuseau', 'caisse.calendrier-du-magasin'],
            ],
            'Q2' => [
                'decision' => 'Un délai en jours est la même heure, n jours plus tard.',
                'regles' => ['outillage.seul-le-calendrier-connait-un-fuseau'],
            ],
            'Q3' => [
                'decision' => 'Pendant l\'heure répétée, seuls le journal des événements et le journal d\'audit ajoutent « (heure d\'été) » ou « (heure d\'hiver) ».',
                'regles' => ['outillage.heure-repetee'],
            ],
            '4' => [
                'decision' => 'Le texte de la puce « Outillage » de CLAUDE.md sur les instants, les jours, le Calendrier, l\'heure répétée, le planificateur et les tests sans heure réelle, approuvé par l\'éditeur.',
                'regles' => ['outillage.instants-en-utc', 'outillage.seul-le-calendrier-connait-un-fuseau', 'outillage.heure-repetee', 'outillage.planificateur', 'outillage.tests-sans-hasard-ni-heure-reelle'],
            ],
        ],
        'Réponses du 25/10/2026 aux questions A, B et C du lot T3 (t3/reponses-t3.md)' => [
            'A' => [
                'decision' => 'Tout changement de territoire passe par un nouveau magasin ; un changement de SIRET qui change le territoire est refusé.',
                'regles' => ['societe.changement-de-siret'],
            ],
            'B' => [
                'decision' => 'Un magasin dont l\'établissement n\'est pas encore confirmé ne valide ni facture d\'achat, ni avoir fournisseur, ni inventaire d\'ouverture : il attend la confirmation, qui fixe son territoire et l\'année de ses numéros.',
                'regles' => ['verification.controle-sirene-et-territoire'],
            ],
            'C' => [
                'decision' => 'Avant le premier document numéroté du magasin, l\'éditeur corrige un territoire mal lu par sa procédure, inscrite aux deux journaux ; après, la société ouvre un nouveau magasin et l\'éditeur libère le SIRET pour lui.',
                'regles' => ['verification.controle-sirene-et-territoire'],
            ],
        ],
        'Décision du 25/10/2026 : calcul exact, prix unitaires affichés au centime, note de calcul gardée (serie8/reponses-affichage-pu.md)' => [
            '1' => [
                'decision' => 'Calcul inchangé : pour chaque taux, le HT est le TTC de ses lignes ÷ (1 + taux), arrondi une seule fois, puis réparti par cumul ; le PU HT, enregistré avec six décimales, ne sert jamais à recalculer un total.',
                'regles' => ['prix.calcul-depuis-le-ttc', 'prix.pu-ht-a-six-decimales'],
            ],
            '2' => [
                'decision' => 'Tout prix unitaire affiché en lecture, PU HT comme PU TTC, ventes comme achats, s\'affiche arrondi au centime ; un champ de saisie montre la valeur telle qu\'elle est enregistrée.',
                'regles' => ['prix.pu-ht-a-six-decimales'],
                'remplacee' => 'Remplacée en partie le 09/11/2026 (CLAUDE.md, « Prix et TVA des ventes » et « Documents de vente ») : sur les documents qui montrent le PU HT (factures, avoirs sur facture et devis à un professionnel, ventes sans TVA française), le PU HT s\'affiche tel qu\'il est enregistré, comme le PU HT d\'un achat ; ailleurs, l\'affichage au centime tient.',
            ],
            '3' => [
                'decision' => 'La note de calcul des PDF est gardée et explique l\'écart d\'un centime possible entre la quantité × le prix unitaire affiché et le total d\'une ligne.',
                'regles' => ['documents.note-de-calcul'],
            ],
            '4' => [
                'decision' => 'Les puces « Prix et TVA des ventes » et « Documents de vente » de CLAUDE.md disent que le PU HT, enregistré avec six décimales, s\'affiche arrondi au centime, et que la note de calcul l\'explique.',
                'regles' => ['documents.note-de-calcul', 'documents.colonnes-ht-ou-ttc'],
                'remplacee' => 'Remplacée en partie le 09/11/2026 : CLAUDE.md dit désormais que les documents qui montrent le PU HT l\'impriment tel qu\'il est enregistré, jusqu\'à six décimales.',
            ],
        ],
        'Décision du 01/11/2026 : l\'expert-comptable de la société, déclaré par le SIREN de son cabinet (ec2/decision-expert-comptable-societe.md)' => [
            'choix-1' => [
                'decision' => 'Premier mot de passe par un lien valable 7 jours envoyé à l\'adresse professionnelle de l\'expert-comptable.',
                'regles' => ['cabinets.comptes-de-l-expert-comptable-declare', 'comptes.adresse-de-l-expert-comptable'],
            ],
            'choix-2' => [
                'decision' => 'SIREN déclaré contrôlé : existant, actif au répertoire Sirene et au code 69.20Z.',
                'regles' => ['cabinets.expert-comptable-declare'],
            ],
            'choix-3' => [
                'decision' => 'Rattachement : le jour où un cabinet de ce SIREN est inscrit et vérifié, la déclaration devient son lien, et les comptes de l\'expert-comptable de la société se ferment.',
                'regles' => ['cabinets.rattachement-de-la-declaration'],
            ],
            'choix-4' => [
                'decision' => 'Un seul cabinet (un SIREN) déclaré à la fois, plusieurs personnes de ce cabinet.',
                'regles' => ['cabinets.expert-comptable-declare', 'cabinets.comptes-de-l-expert-comptable-declare'],
            ],
            'constat-10' => [
                'decision' => 'Refus d\'inviter le SIREN d\'une société inscrite comme cabinet : « Ce SIREN ne peut pas être invité par votre cabinet. »',
                'regles' => ['cabinets.invitation-d-un-siren'],
            ],
            'ecran-1' => [
                'decision' => 'Phrase des activités exonérées sous le menu du type d\'activité de l\'inscription.',
                'regles' => ['type.code-d-activite', 'type.choix-a-l-inscription'],
            ],
            'ecran-2' => [
                'decision' => 'Astérisque du mot de passe obligatoire de l\'inscription.',
                'regles' => ['comptes.inscription-par-lien', 'comptes.regle-du-mot-de-passe'],
            ],
            'ecran-3' => [
                'decision' => 'Intitulé de l\'étape des magasins dans « Vos premières étapes » de l\'espace de la société.',
                'regles' => ['societe.magasins-crees-dans-l-espace-de-la-societe'],
            ],
            'ecran-4' => [
                'decision' => '« Voir les siennes » n\'est pas proposé à un cabinet : « voir les factures » et « voir les clôtures » sont une seule case, celles de tout le magasin.',
                'regles' => ['cabinets.autorisations-accordees-par-le-client'],
            ],
            'ecran-5' => [
                'decision' => 'Étapes d\'une société validée par l\'invitation de son cabinet.',
                'regles' => ['cabinets.invitation-d-une-entreprise-non-inscrite'],
            ],
            '5-bis' => [
                'decision' => 'Un cabinet suspendu (ou non vérifié) peut encore retirer ses invitations et ses demandes ouvertes et mettre fin à ses liens (09/11/2026).',
                'regles' => ['cabinets.rien-avant-d-etre-verifie-et-actif'],
            ],
            '5-ter' => [
                'decision' => 'Magasins choisis par le client pour son cabinet ou son expert-comptable déclaré : tous, présents et futurs, ou une sélection, avec une seule liste de cases (T44 et T104, 11/11/2026).',
                'regles' => ['cabinets.magasins-choisis'],
            ],
            '5-quater' => [
                'decision' => 'Un cabinet suspendu : aucun client n\'accepte son invitation ni sa demande, qu\'il peut seulement refuser (T45, 11/11/2026).',
                'regles' => ['cabinets.rien-avant-d-etre-verifie-et-actif'],
            ],
        ],
        'Réponses du 26/10/2026 sur deux anciennes règles d\'écran (decisions/reponses-regles-ecran-26-10.md)' => [
            '1' => [
                'decision' => 'Messages d\'un magasin sans « ce magasin » (messages 19 et 20 du 3 octobre) : « Abandonner cette règle ».',
                'remplacee' => 'Abandonnée par l\'éditeur le 26/10/2026 : un message peut dire « ce magasin » ou « dans ce magasin » quand c\'est plus clair.',
            ],
            '2' => [
                'decision' => 'Aucun écran ni aucun message du panneau d\'un magasin ne laisse deviner qu\'il existe d\'autres magasins de la même société ni d\'autres sociétés : « Oui, elle tient ».',
                'regles' => ['produit.aucun-ecran-de-magasin-ne-devine-les-autres'],
            ],
        ],
        'Client comptoir et ticket transformé en facture, réponses du 01/11/2026 (decisions/client-comptoir-01-11.md)' => [
            '1a' => [
                'decision' => 'L\'avoir d\'annulation créé automatiquement quand un ticket anonyme se transforme en facture, dans le même clic, entièrement consommé par la facture.',
                'a_venir' => 'Lot C-b de la chaîne du moteur de TVA (compléments des lots 14 à 20, points 14 et 15) : « Transformer en facture » sur un ticket anonyme déjà émis, à faire avant de cocher l\'étape 1.',
            ],
            '2a' => [
                'decision' => 'Les bons d\'avoir des retours au comptoir restent, jamais affichés comme un solde du client comptoir ; les avoirs non utilisés (bons d\'avoir et crédits des clients) s\'affichent au tableau de bord.',
                'regles' => ['paiements.bon-d-avoir', 'vendeurs.avoirs-non-utilises'],
            ],
            '3' => [
                'decision' => 'Le client comptoir sort de la liste des clients et de leurs sélecteurs ; sa fiche ne se consulte ni ne se modifie.',
                'regles' => ['fiches.client-comptoir'],
            ],
        ],
        'Décisions de la conversation absentes de tout fichier de décision et de CLAUDE.md (historique/DIRECTIVES.md, § 2.2)' => [
            '1' => [
                'decision' => 'Messages à l\'utilisateur sans le mot « magasin », et aucun message ne laisse deviner d\'autres magasins ou sociétés (messages 19 et 20) ; tranché le 26/10/2026 : première règle abandonnée, seconde maintenue.',
                'regles' => ['produit.aucun-ecran-de-magasin-ne-devine-les-autres'],
            ],
            '2' => [
                'decision' => 'Erreurs métier affichées en alertes claires, jamais en erreur technique, partout (message 67).',
                'regles' => ['ecrans.erreurs-metier-en-alertes-claires'],
            ],
            '3' => [
                'decision' => 'Le champ en cours de frappe n\'est jamais modifié ; les autres champs se calculent en direct (messages 58 et 87).',
                'regles' => ['ecrans.champ-en-cours-de-frappe'],
            ],
            '4' => [
                'decision' => 'Après chaque enregistrement, retour à la page parente (messages 71 et 110).',
                'regles' => ['ecrans.retour-a-la-page-parente'],
            ],
            '5' => [
                'decision' => 'Échéance préremplie à la date du jour, et aucune date passée dans les champs de date concernés (messages 72 et 75).',
                'regles' => ['hors.recu-le-passe-permis-futur-refuse'],
                'remplacee' => 'Remplacée en partie par la décision B7 du 09/10/2026 (CLAUDE.md, « Documents de vente ») : l\'échéance se calcule à l\'émission (date d\'émission + délai du client, sinon du magasin), aucun champ ne la saisit ; et, pour le champ « Reçu le » d\'un paiement, par la décision de Claude du 09/10/2026 (constat X0 de son audit), appliquée sans attendre selon D76 et lue par l\'éditeur le 13/11/2026 sans la contester : une date passée est permise, une date future refusée.',
            ],
            '6' => [
                'decision' => 'Facture brouillon sans numéro définitif, numérotée à la validation ; brouillon modifiable et supprimable en entier d\'un seul geste (messages 14 et 19).',
                'regles' => ['documents.facture-brouillon-numerotee-a-l-emission'],
            ],
            '7' => [
                'decision' => 'Référence produit facultative, générée automatiquement comme une séquence quand elle manque, y compris pour un produit reçu par l\'API (message 55).',
                'regles' => ['fiches.reference-produit-automatique'],
            ],
            '8' => [
                'decision' => 'Deux numéros pour chaque facture d\'achat : celui de la séquence du logiciel et celui d\'origine du fournisseur (message 55).',
                'regles' => ['achats.deux-numeros-d-une-facture-d-achat'],
            ],
            '9' => [
                'decision' => 'Quantités affichées sans décimales (« 2 », jamais « 2.000 ») (message 98).',
                'regles' => ['ecrans.quantites-sans-decimales'],
            ],
            '10' => [
                'decision' => 'Une facture d\'achat récupérée automatiquement ne se modifie jamais ; un produit qui en vient garde ce que son fournisseur en dit, son prix de vente, sa TVA et son suivi du stock restant modifiables (messages 20, 110 et 112).',
                'regles' => ['achats.achat-recu-par-l-api'],
            ],
            '11' => [
                'decision' => 'Un produit se cherche aussi par son code-barres scanné (message 18).',
                'regles' => ['fiches.code-barres-scanne'],
            ],
            '12' => [
                'decision' => 'Toute l\'équipe qui en a le droit crée un client depuis la vente, dans une fenêtre (message 15).',
                'regles' => ['fiches.client-cree-depuis-la-vente'],
            ],
        ],
        'Directives permanentes de l\'éditeur sur la façon de travailler (historique/DIRECTIVES.md, § 1, et ses entrées datées du 12/11/2026)' => [
            'D1' => [
                'decision' => 'Il est l\'éditeur, jamais le propriétaire d\'un magasin : « le propriétaire » est le propriétaire d\'une société cliente.',
                'hors_logiciel' => 'Vocabulaire des réponses et des commentaires ; dans le logiciel, l\'éditeur n\'a ni société ni magasin (CLAUDE.md, « Produit »), ce que montrent les procédures de l\'éditeur, qui n\'ouvrent aucune société.',
            ],
            'D2' => [
                'decision' => 'PILIQI est un logiciel indépendant ; l\'éditeur n\'aura jamais de magasin, et l\'attestation de l\'éditeur va à chaque magasin client.',
                'a_venir' => 'L\'attestation de l\'éditeur par magasin se produit à l\'étape 3 (caisse sécurisée, fin) ; aucune donnée ni aucun exemple ne vient de la boutique où il travaille, ce que les relectures contrôlent.',
            ],
            'D3' => [
                'decision' => 'Le logiciel est à lui seul : dépôt privé, licence propriétaire, aucun secret dans le dépôt.',
                'regles' => ['outillage.licence-et-extensions', 'outillage.fournisseurs-externes-derriere-une-interface'],
            ],
            'D4' => [
                'decision' => 'Au tout début : proposer l\'architecture et attendre sa validation.',
                'remplacee' => 'Remplacée par D5 et D6 : l\'éditeur ne valide plus chaque étape technique.',
            ],
            'D5' => [
                'decision' => 'Faire mieux que ce qu\'il a écrit, lui dire quand il se trompe et proposer mieux.',
                'hors_logiciel' => 'Façon de travailler.',
            ],
            'D6' => [
                'decision' => 'Mandat d\'autonomie totale : enchaîner les lots de la feuille de route sans attendre son feu vert, s\'arrêter seulement pour une règle métier à trancher, un geste qu\'il doit faire lui-même ou un refus du classificateur.',
                'hors_logiciel' => 'Façon de travailler (README, § 2).',
            ],
            'D7' => [
                'decision' => 'Les choix techniques sont délégués, justifiés par la fiabilité et la sécurité, puis expliqués simplement.',
                'hors_logiciel' => 'Façon de travailler ; les paquets suivent D54.',
            ],
            'D8' => [
                'decision' => 'Ne jamais deviner une règle métier : calcul, arrondi, obligation légale ou droits non tranchés se posent à l\'éditeur, avec des options et une recommandation.',
                'hors_logiciel' => 'Protocole de rigueur, point 4 ; chaque compte rendu nomme les règles soumises.',
            ],
            'D9' => [
                'decision' => 'Quand il dit d\'arrêter, on arrête, jusqu\'à son ordre de reprise.',
                'hors_logiciel' => 'Conduite de la chaîne de lots.',
            ],
            'D10' => [
                'decision' => 'Le travail d\'une autre session se supprime s\'il n\'est pas bon, par git revert, jamais par une poussée forcée ; une seule session à la fois sur la branche.',
                'hors_logiciel' => 'Conduite du dépôt (README, § 1).',
            ],
            'D11' => [
                'decision' => 'Du vrai code, jamais simplifié, jamais compliqué pour rien : code complet, fonctionnalités natives de Laravel et de Filament d\'abord.',
                'regles' => ['protocole.code-complet'],
            ],
            'D12' => [
                'decision' => 'Les treize questions avant de dire qu\'une fonctionnalité est finie, posées par les relectures adversariales.',
                'hors_logiciel' => 'Méthode des relectures.',
            ],
            'D13' => [
                'decision' => 'Faire propre, pour toujours et partout : chercher la cause de chaque défaut, corriger la source commune et tous les endroits touchés ; une même règle vaut pour tous les clients.',
                'hors_logiciel' => 'Méthode de correction ; l\'égalité des clients est une règle des calculs (prix.calcul-depuis-le-ttc).',
            ],
            'D14' => [
                'decision' => 'Le protocole de rigueur de CLAUDE.md est prioritaire sur tout (code complet, isolation, tests ciblés puis suite complète au code 0, correction autonome, pint).',
                'regles' => ['protocole.code-complet', 'protocole.modeles-isoles', 'protocole.suite-complete-sans-test-saute'],
            ],
            'D15' => [
                'decision' => 'La voie la plus sûre, sans rien alléger : implémentation, deux relectures indépendantes, correction, suite complète, pint, poussée, CI verte.',
                'hors_logiciel' => 'Méthode des lots (README, § 2), allégée pour l\'analyse statique, la couverture et la mutation par D63 jusqu\'à l\'étape 15.',
            ],
            'D16' => [
                'decision' => 'Ne jamais sauter les tests.',
                'regles' => ['protocole.suite-complete-sans-test-saute'],
            ],
            'D17' => [
                'decision' => 'Une explication ne remplace jamais un test : chercher le chemin testable.',
                'hors_logiciel' => 'Méthode ; cette matrice nomme un test pour chaque règle du logiciel.',
            ],
            'D18' => [
                'decision' => 'Des milliers de cas pour les calculs : tests de propriétés à graine fixe, comparés à un calcul de référence exact.',
                'regles' => ['prix.calcul-depuis-le-ttc', 'outillage.tests-sans-hasard-ni-heure-reelle'],
            ],
            'D19' => [
                'decision' => 'Les fournisseurs externes : tout préparer selon leur documentation, tester avec des faux qui simulent toutes les réponses ; aucune vraie requête.',
                'regles' => ['outillage.fournisseurs-externes-derriere-une-interface'],
            ],
            'D20' => [
                'decision' => 'Les règles de la campagne de tests et de tout compte rendu (scénarios, volumes, concurrence, erreurs et corrections, temps de réponse, limites, commandes pour reproduire).',
                'a_venir' => 'Campagne de tests initiale (étape 1 bis), dont les scénarios sont écrits dans campagne/strategie/finale.md.',
            ],
            'D21' => [
                'decision' => 'Dire ce qu\'on fait des échecs : la cause, la correction, réglé ou encore ouvert.',
                'hors_logiciel' => 'Forme des comptes rendus.',
            ],
            'D22' => [
                'decision' => 'On repart de zéro à chaque changement et on code comme pour la production : une seule migration de création par table, aucun code de reprise de données.',
                'regles' => ['developpement.une-migration-de-creation-par-table', 'developpement.aucune-donnee-reelle'],
            ],
            'D23' => [
                'decision' => 'Réécrire le code dès qu\'il devient plus simple et plus lisible ; le code reste lisible par lui.',
                'hors_logiciel' => 'Méthode (CLAUDE.md, « Développement »), appliquée par chaque lot et ses relectures.',
            ],
            'D24' => [
                'decision' => 'CLAUDE.md suit les décisions, avec son accord : proposer le texte et attendre son accord, sauf les textes déjà approuvés dans un fichier de décision.',
                'hors_logiciel' => 'Tenue de CLAUDE.md.',
            ],
            'D25' => [
                'decision' => 'Pas de comptable : chercher les règles sur les sites officiels et citer la source.',
                'hors_logiciel' => 'Méthode de recherche des règles légales.',
            ],
            'D26' => [
                'decision' => 'Justifier une affirmation sur un tiers par sa source ; corriger aussitôt une affirmation fausse.',
                'hors_logiciel' => 'Forme des réponses.',
            ],
            'D27' => [
                'decision' => 'Ne pas coder les règles françaises en dur partout : les règles fiscales passent par le moteur de TVA centralisé ; le taux par défaut de 20 % reste réglable. Le moteur de TVA centralisé (série 7) reste à faire avant de cocher l\'étape 1.',
                'regles' => ['prix.taux-par-defaut'],
            ],
            'D28' => [
                'decision' => 'Isolation absolue entre sociétés et entre magasins ; même deux services identiques dans deux magasins restent deux fiches distinctes.',
                'regles' => ['protocole.modeles-isoles', 'protocole.unicites-limitees', 'produit.magasins-isoles', 'produit.societes-isolees'],
            ],
            'D29' => [
                'decision' => 'Chaque magasin doit croire qu\'il a son propre logiciel ; tranché le 26/10/2026 : un message peut dire « ce magasin », mais aucun écran ne laisse deviner d\'autres magasins ou sociétés.',
                'regles' => ['produit.aucun-ecran-de-magasin-ne-devine-les-autres'],
            ],
            'D30' => [
                'decision' => 'Les principes de sécurité du prompt maître (accès explicite et audité de l\'éditeur, secrets hors du dépôt, conservation et RGPD, cache et jobs) guident chaque lot et chaque relecture de sécurité.',
                'regles' => ['protocole.lectures-hors-du-scope-filtrees', 'outillage.fournisseurs-externes-derriere-une-interface', 'caisse.conservation-et-effacements'],
            ],
            'D31' => [
                'decision' => 'Les identifiants secrets ne passent jamais par la conversation ; seulement des variables d\'environnement.',
                'hors_logiciel' => 'Conduite de la conversation (README, § 3).',
            ],
            'D32' => [
                'decision' => 'Jamais d\'erreur technique à l\'écran : une alerte claire.',
                'regles' => ['ecrans.erreurs-metier-en-alertes-claires'],
            ],
            'D33' => [
                'decision' => 'Ne jamais changer ce que la personne tape ; calculer en direct les champs voisins.',
                'regles' => ['ecrans.champ-en-cours-de-frappe'],
            ],
            'D34' => [
                'decision' => 'Après un enregistrement, retour à la page parente.',
                'regles' => ['ecrans.retour-a-la-page-parente'],
            ],
            'D35' => [
                'decision' => 'Cases à cocher plutôt que menus déroulants quand c\'est plus rapide.',
                'explication' => 'Préférence d\'écran appliquée au cas par cas (autorisations, services, choix des magasins), sans règle propre ; chaque écran qui l\'applique a son test.',
            ],
            'D36' => [
                'decision' => 'Des écrans compacts, des nombres propres, des libellés exacts : quantités entières sans décimales, prix unitaires affichés au centime.',
                'regles' => ['ecrans.quantites-sans-decimales', 'prix.pu-ht-a-six-decimales'],
            ],
            'D37' => [
                'decision' => 'Les dates proposées et les dates interdites : la clôture sans choix de date ; l\'échéance préremplie à la date du jour ; aucune date passée dans les champs de date concernés.',
                'regles' => ['caisse.cloture-jamais-bloquee', 'hors.recu-le-passe-permis-futur-refuse'],
                'remplacee' => 'Remplacée en partie par la décision B7 du 09/10/2026 (CLAUDE.md, « Documents de vente ») : l\'échéance se calcule à l\'émission, aucun champ ne la saisit ; et, pour le champ « Reçu le » d\'un paiement, par la décision de Claude du 09/10/2026 (constat X0 de son audit), appliquée sans attendre selon D76 et lue par l\'éditeur le 13/11/2026 sans la contester : une date passée est permise, une date future refusée.',
            ],
            'D38' => [
                'decision' => 'Tout document cité s\'ouvre d\'un clic ; l\'important se voit en orange (retours sous les lignes d\'achat, numéros des tickets et des avoirs dans les clôtures).',
                'regles' => ['caisse.fiche-de-la-cloture-et-apercu-de-l-e-reporting', 'achats.avoir-fournisseur'],
            ],
            'D39' => [
                'decision' => 'Créer sur place ce qui manque, chercher vite.',
                'regles' => ['fiches.client-cree-depuis-la-vente', 'fiches.recherches-sans-casse-ni-accents'],
            ],
            'D40' => [
                'decision' => 'Avertir avant un geste irréversible.',
                'regles' => ['caisse.cloture-jamais-bloquee', 'statuts.ceder-un-magasin'],
            ],
            'D41' => [
                'decision' => 'Aucune action manuelle à retenir : tout ce qui doit être fait au déploiement est automatisé ou fait échouer le déploiement de façon visible (fuseau UTC de la base fixé par les migrations).',
                'regles' => ['outillage.instants-en-utc'],
            ],
            'D42' => [
                'decision' => 'Toutes les notes, tout de suite, pour qu\'un autre compte continue à l\'identique.',
                'hors_logiciel' => 'Tenue des notes.',
            ],
            'D43' => [
                'decision' => 'Tout en français : réponses, code, commentaires, commits, documents, interface.',
                'hors_logiciel' => 'Langue du travail, contrôlée par les relectures.',
            ],
            'D44' => [
                'decision' => 'Filament comme cadre des écrans (Filament 3.3).',
                'hors_logiciel' => 'Choix de la pile.',
            ],
            'D45' => [
                'decision' => 'SUPER PDP comme plateforme agréée.',
                'regles' => ['pdp.transmission-par-la-passerelle'],
            ],
            'D46' => [
                'decision' => 'PostgreSQL à la place de MySQL ; la part « SQLite en développement, suite sur les deux moteurs » est remplacée par D55.',
                'regles' => ['outillage.postgresql-seul'],
            ],
            'D47' => [
                'decision' => 'Le modèle Fable pour les écrans, si nécessaire.',
                'hors_logiciel' => 'Réglage de la session, choisi par l\'éditeur.',
            ],
            'D48' => [
                'decision' => 'L\'ordre du prompt maître est remplacé par la feuille de route de CLAUDE.md et la chaîne de lots.',
                'hors_logiciel' => 'Conduite du projet.',
            ],
            'D49' => [
                'decision' => 'Modèle et effort de la session : c\'est lui qui les règle.',
                'hors_logiciel' => 'Réglage de la session.',
            ],
            'D50' => [
                'decision' => 'La grille faketime reportée à la fin du logiciel ; chaque test fige toujours le temps à un instant écrit avec son fuseau.',
                'regles' => ['outillage.tests-sans-hasard-ni-heure-reelle'],
            ],
            'D51' => [
                'decision' => 'Tests en navigateur de toutes les actions, et audit final complet : chaque lot ajoute les tests en navigateur de ce qu\'il crée ou change ; les lots NAV-2 à NAV-5 balaient chaque action de chaque écran pour chaque rôle à l\'étape 15.',
                'regles' => ['outillage.tests-en-navigateur'],
            ],
            'D52' => [
                'decision' => 'Tout geste à faire à la main sur le PC de l\'éditeur lui est toujours dit, à la fin de chaque compte rendu.',
                'hors_logiciel' => 'Forme des comptes rendus.',
            ],
            'D53' => [
                'decision' => 'Mise à jour des paquets : faille connue mise à jour aussitôt ; mises à jour mineures dans un lot dédié à chaque relance de la campagne ; version majeure sur décision de l\'éditeur.',
                'hors_logiciel' => 'Entretien des dépendances, contrôlé par l\'audit des dépendances de la CI.',
            ],
            'D54' => [
                'decision' => 'Les paquets utiles s\'installent sans demander (sécurité, fiabilité, qualité, performances), s\'ils sont maintenus, compatibles, sans faille connue et sous licence compatible.',
                'regles' => ['outillage.aucun-paquet-inutilise', 'outillage.licence-et-extensions'],
            ],
            'D55' => [
                'decision' => 'PostgreSQL seulement, plus de SQLite, en production, en développement et pour les tests.',
                'regles' => ['outillage.postgresql-seul'],
            ],
            'D56' => [
                'decision' => 'Analyse statique au plus strict (Larastan niveau 10 et ses règles strictes, Psalm niveau 1 et son analyse des flux).',
                'a_venir' => 'Étape 15 (lots TY3, PS1 et PS2), reportée par D63.',
            ],
            'D57' => [
                'decision' => 'Note « Ce qui reste à trancher » : tout point à trancher ou à soumettre vit dans le document de l\'éditeur.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'D58' => [
                'decision' => 'Le document « Ce qui reste à trancher » a trois onglets.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'D59' => [
                'decision' => 'Un onglet à part pour tout ce qui est à faire vérifier par un professionnel.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'D60' => [
                'decision' => 'Chaque question posée dans le document a sa case de réponse vide.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'D61' => [
                'decision' => 'Le moins de commits possible dans chaque lot, la suite complète toujours avant chacun.',
                'hors_logiciel' => 'Méthode des lots, précisée par D63.',
            ],
            'D62' => [
                'decision' => 'Consommer moins sans rien changer aux tests ni aux relectures : sorties longues dans un fichier, travail par parties.',
                'hors_logiciel' => 'Méthode des agents.',
            ],
            'D63' => [
                'decision' => 'Méthode rapide : ni analyse statique, ni couverture, ni mutation pendant les lots ; tests ciblés puis une seule suite complète avant le commit du lot ; un seul commit par lot.',
                'hors_logiciel' => 'Méthode des lots jusqu\'à l\'étape 15 (CLAUDE.md, protocole de rigueur, point 3).',
            ],
            'D64' => [
                'decision' => 'Un onglet du document pour tout ce qui se fait à la fin du logiciel.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'D65' => [
                'decision' => 'Le numéro T se voit dans l\'onglet des professionnels, et le numéro P dans l\'onglet 1.',
                'remplacee' => 'Remplacée dans sa forme par D66 ; son but tient.',
            ],
            'D66' => [
                'decision' => 'Une seule question par ligne, numérotée T58.1, T58.2…, sous le même numéro chez les professionnels.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'D67' => [
                'decision' => 'Ses propres règles pour la facture électronique et l\'e-reporting, sans attendre un professionnel (19 règles du 11/11/2026).',
                'remplacee' => 'Remplacée le 12/11/2026 par D72 : ses 19 textes sont remplacés par les décisions corrigées de Claude, que le code suit par le lot PRO et l\'étape 2.',
            ],
            'D68' => [
                'decision' => 'Rien de la démonstration ne doit rester après la validation : tout supprimer.',
                'remplacee' => 'Remplacée par D70, elle-même remplacée par D71 : rien n\'est supprimé.',
            ],
            'D69' => [
                'decision' => 'Le code suit les réponses de l\'onglet professionnel, sans attendre aucune confirmation.',
                'a_venir' => 'Lot PRO, après la chaîne en cours : retirer du code, des écrans et de CLAUDE.md chaque « en attente de l\'avis de l\'expert-comptable ».',
            ],
            'D70' => [
                'decision' => 'Garder la démonstration, réservée au propriétaire, qui peut tout y faire.',
                'remplacee' => 'Remplacée par D71.',
            ],
            'D71' => [
                'decision' => 'Dans la démonstration, le propriétaire a toutes les autorisations, vente comprise ; le reste ne change pas.',
                'regles' => ['comptes.proprietaire-dans-la-demonstration', 'verification.magasin-de-demonstration'],
            ],
            'D72' => [
                'decision' => 'Une colonne à part pour les décisions de Claude dans l\'onglet professionnel ; « Réponse du professionnel » reste vide ; ses 19 textes du 11/11 supprimés.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur ; le code suit par le lot PRO et par l\'étape 2.',
            ],
            'D73' => [
                'decision' => 'Le 12/11/2026 vers 02 h 50 UTC : toute nouvelle question part avec la décision de Claude, écrite dans le document de l\'éditeur et appliquée sans attendre ; le point 4 du protocole de CLAUDE.md (« la soumettre à l\'utilisateur au lieu de choisir ») est à réécrire.',
                'a_venir' => 'Lot PRO, après la chaîne en cours : réécrire le point 4 du protocole de CLAUDE.md et appliquer les décisions des questions déjà proposées.',
            ],
            'D74' => [
                'decision' => 'Le 12/11/2026 vers 02 h 55 UTC : chaque nouvelle question est toujours proposée à l\'éditeur, dans le message de la session et dans son document, avec la décision de Claude, qui s\'applique s\'il ne répond pas.',
                'hors_logiciel' => 'Façon de travailler : chaque compte rendu de lot propose ses questions à l\'éditeur.',
            ],
            'D75' => [
                'decision' => 'Le 12/11/2026 vers 03 h 00 UTC : Claude ne décide seul que sur une source officielle lue et sûre ; sinon sa décision attend celle de l\'éditeur.',
                'remplacee' => 'Remplacée par D76 : Claude décide toujours, sa certitude et ses sources écrites dans sa cellule.',
            ],
            'T105-12-11' => [
                'decision' => 'Le 12/11/2026 vers 13 h UTC : T105 tranchée (a) ; toute question sur un prix ou un montant qui change avec la multiplication ou l\'arrondi va aussi dans l\'onglet professionnel, pour information.',
                'a_venir' => 'Petit lot T105, après la chaîne en cours et avant la campagne 1 bis.',
            ],
            'VER-12-11' => [
                'decision' => 'Le 12/11/2026 vers 14 h 30 UTC : seulement les dernières versions, PHP 8.5 et PostgreSQL 18, en développement, en test, en CI et en production.',
                'a_venir' => 'Lot VER, après la chaîne en cours (CI, composer.json, CLAUDE.md, README, conteneur des agents).',
            ],
            'PASSAGE-12-11' => [
                'decision' => 'Le 12/11/2026 vers 16 h 35 UTC : le passage à PHP 8.5 et PostgreSQL 18 attend la fin de toute la chaîne.',
                'hors_logiciel' => 'Conduite de la chaîne.',
            ],
            'D76' => [
                'decision' => 'Le 12/11/2026 vers 20 h 45 UTC : jamais attendre l\'avis du comptable ; chaque point de lois reçoit une décision de Claude, que PILIQI applique sans attendre ; une réponse du professionnel, même après la mise en production, prime ; Claude entretient le logiciel pour toujours ; remplace D75.',
                'regles' => ['regles-legales.decisions-de-claude-appliquees', 'pdp.e-reporting-et-encaissements-des-services', 'caisse.fiche-de-la-cloture-et-apercu-de-l-e-reporting', 'hors-france.exoneration-non-prouvee'],
            ],
            'ONGLET-12-11' => [
                'decision' => 'Le 12/11/2026 vers 22 h 45 UTC : onglet « À faire de votre côté (PC et mise en ligne) » du document de l\'éditeur.',
                'hors_logiciel' => 'Tenue du document de l\'éditeur.',
            ],
            'SEED-13-11' => [
                'decision' => 'Le 13/11/2026 vers 08 h 50 UTC : « donne moi la liste de --seed pour voir ici chez moi avec les donnes remplir par --seed » ; vers 09 h UTC, les identifiants pour s\'y connecter : un mot de passe commun facultatif, PILIQI_DEMO_MOT_DE_PASSE, sinon un mot de passe tiré au hasard par compte, affiché à la fin du semis (lot SEED, a-faire-apres-chaine.md).',
                'regles' => ['hors.donnees-de-demonstration'],
            ],
        ],
        'Décisions métier tranchées dans la conversation (historique/DIRECTIVES.md, § 2.1)' => [
            '2.1-001' => [
                'decision' => 'Message 3, 4 (02/10) : Factures émises et reçues par l\'API SUPER PDP ; achats et retours fournisseur récupérés automatiquement, stock mis à jour',
                'regles' => ['pdp.transmission-par-la-passerelle', 'achats.achat-recu-par-l-api'],
            ],
            '2.1-002' => [
                'decision' => 'Message 5 (02/10) : Mandat accepté d\'un clic sur le site',
                'remplacee' => 'Remplacée par la délégation OAuth de chaque société à SUPER PDP (messages 136 et 137 ; CLAUDE.md, étape 2).',
            ],
            '2.1-003' => [
                'decision' => 'Message 8 (02/10) : Envoi automatique de chaque facture à sa validation, pas groupé ; clôture automatique à minuit',
                'regles' => ['pdp.transmission-par-la-passerelle', 'caisse.cloture-automatique-et-rattrapage'],
            ],
            '2.1-004' => [
                'decision' => 'Message 8 (02/10) : Les comptables ont déjà accès par la réforme : pas d\'espace à eux',
                'remplacee' => 'Remplacée par l\'espace des cabinets d\'expertise comptable (message 230 ; décision du 19/10/2026).',
            ],
            '2.1-005' => [
                'decision' => 'Message 12 (03/10) : Devis et lignes libres sans produit enregistré',
                'remplacee' => 'Remplacée : une ligne vend un produit existant ou un service (messages 106 et 110).',
            ],
            '2.1-006' => [
                'decision' => 'Message 12 (03/10) : « Une facture ne doit jamais se supprimer car c’est interdit par la loi. »',
                'regles' => ['documents.client-fige-et-facture-immuable'],
            ],
            '2.1-007' => [
                'decision' => 'Message 12 (03/10) : Vendeurs créés par le propriétaire, sans confirmation d\'e-mail',
                'regles' => ['comptes.identifiant-de-huit-chiffres', 'comptes.premier-mot-de-passe'],
            ],
            '2.1-008' => [
                'decision' => 'Message 14 (03/10) : Facture brouillon sans numéro définitif ; numéro à la validation ; plus de suppression ensuite',
                'regles' => ['documents.facture-brouillon-numerotee-a-l-emission'],
            ],
            '2.1-009' => [
                'decision' => 'Message 15 (03/10) : Aucune modification ni suppression d\'une facture',
                'regles' => ['documents.client-fige-et-facture-immuable', 'documents.facture-brouillon-numerotee-a-l-emission'],
            ],
            '2.1-010' => [
                'decision' => 'Message 15 (03/10) : Clients visibles par toute l\'équipe ; création d\'un client depuis la facture, dans une fenêtre',
                'regles' => ['fiches.client-cree-depuis-la-vente'],
            ],
            '2.1-011' => [
                'decision' => 'Message 15 (03/10) : Valeur du stock et TVA du stock visibles du propriétaire et de qui il autorise',
                'regles' => ['achats.cout-visible-avec-la-valeur-du-stock'],
            ],
            '2.1-012' => [
                'decision' => 'Message 15 (03/10) : Le propriétaire donne des autorisations par écran à ses salariés',
                'regles' => ['type.familles-d-autorisations', 'comptes.administrateurs-du-proprietaire'],
            ],
            '2.1-013' => [
                'decision' => 'Message 18 (03/10) : Une facture peut venir d\'un devis, jamais obligatoirement ; un devis se modifie',
                'regles' => ['documents.devis', 'documents.devis-transforme-en-facture'],
            ],
            '2.1-014' => [
                'decision' => 'Message 18 (03/10) : Recherche d\'un produit par lecture du code-barres',
                'regles' => ['fiches.code-barres-scanne'],
            ],
            '2.1-015' => [
                'decision' => 'Message 19 (03/10) : Avoir fournisseur sans toucher la facture d\'achat, lié à sa facture',
                'regles' => ['achats.avoir-fournisseur'],
            ],
            '2.1-016' => [
                'decision' => 'Message 19 (03/10) : Avoir de vente sur les seuls articles choisis',
                'regles' => ['paiements.avoirs-partiels-et-multiples'],
            ],
            '2.1-017' => [
                'decision' => 'Message 19 (03/10) : Brouillon modifiable, et supprimable en entier d\'un geste',
                'regles' => ['documents.facture-brouillon-numerotee-a-l-emission'],
            ],
            '2.1-018' => [
                'decision' => 'Message 19 (03/10) : Clôtures de caisse visibles par le vendeur, le propriétaire et les autorisés',
                'regles' => ['caisse.menu-ma-caisse-ou-les-caisses', 'vendeurs.releve-de-caisse'],
            ],
            '2.1-019' => [
                'decision' => 'Message 19 (03/10) : Messages sans le mot « magasin »',
                'remplacee' => 'Abandonnée par l\'éditeur le 26/10/2026 (réponses sur deux règles d\'écran, règle 1) ; la règle 2, aucun écran ne laisse deviner d\'autres magasins ou sociétés, tient.',
            ],
            '2.1-020' => [
                'decision' => 'Message 19 (03/10) : E-reporting non quotidien ; données obligatoires à chercher',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services', 'caisse.fiche-de-la-cloture-et-apercu-de-l-e-reporting'],
            ],
            '2.1-021' => [
                'decision' => 'Message 20 (04/10) : Un vendeur ne clôture pas la journée sans autorisation ; il ferme sa caisse',
                'regles' => ['caisse.cloture-jamais-bloquee'],
            ],
            '2.1-022' => [
                'decision' => 'Message 20 (04/10) : Devis figé une fois converti en facture',
                'regles' => ['documents.devis'],
            ],
            '2.1-023' => [
                'decision' => 'Message 20 (04/10) : Un avoir peut reprendre plusieurs factures ; plusieurs avoirs successifs possibles',
                'regles' => ['paiements.avoirs-partiels-et-multiples'],
            ],
            '2.1-024' => [
                'decision' => 'Message 20 (04/10) : Facture d\'achat modifiable si saisie à la main, pas si reçue par API ; avoirs non modifiables',
                'regles' => ['achats.achat-recu-par-l-api'],
            ],
            '2.1-025' => [
                'decision' => 'Message 39 (04/10) : Clôture du jour sans choix de date',
                'regles' => ['caisse.cloture-jamais-bloquee'],
            ],
            '2.1-026' => [
                'decision' => 'Message 41 (04/10) : « une vraie caisse et donc chaque vendeur sa caisse »',
                'regles' => ['vendeurs.releve-de-caisse'],
            ],
            '2.1-027' => [
                'decision' => 'Message 42, 43 (04/10) : Ticket pour un particulier, facture pour un professionnel ; numérotations séparées',
                'regles' => ['societe.code-du-magasin', 'documents.colonnes-ht-ou-ttc'],
            ],
            '2.1-028' => [
                'decision' => 'Message 55 (04/10) : Référence produit facultative, sinon générée comme un numéro de séquence',
                'regles' => ['fiches.reference-produit-automatique'],
            ],
            '2.1-029' => [
                'decision' => 'Message 55 (04/10) : Une facture d\'achat porte deux numéros : celui du logiciel et celui du fournisseur',
                'regles' => ['achats.deux-numeros-d-une-facture-d-achat'],
            ],
            '2.1-030' => [
                'decision' => 'Message 56 (04/10) : Calcul en centimes entiers',
                'regles' => ['prix.sommes-des-montants-enregistres'],
            ],
            '2.1-031' => [
                'decision' => 'Message 57, 77 (04-05/10) : Le prix de vente se saisit en TTC',
                'regles' => ['prix.calcul-depuis-le-ttc'],
            ],
            '2.1-032' => [
                'decision' => 'Message 60 (04/10) : Un avoir donne un crédit imputé sur les achats suivants ; type 381 pour l\'avoir électronique',
                'regles' => ['paiements.rien-n-est-rembourse'],
            ],
            '2.1-033' => [
                'decision' => 'Message 61 (04/10) : Alerter et attendre que toutes les caisses soient fermées avant la clôture',
                'remplacee' => 'Remplacée par R5 de la série 4 : la clôture du jour ferme d\'office les caisses personnelles ouvertes.',
            ],
            '2.1-034' => [
                'decision' => 'Message 63 (04/10) : Le PDF montre le paiement par crédit d\'avoir',
                'regles' => ['paiements.mode-avoir', 'documents.reglements-imprimes-et-recus'],
            ],
            '2.1-035' => [
                'decision' => 'Message 64, 66 (04/10) : Aucun paiement supérieur au restant dû, pas de trop-perçu en crédit',
                'regles' => ['paiements.montant-egal-au-total-impute', 'paiements.plusieurs-modes'],
            ],
            '2.1-036' => [
                'decision' => 'Message 76 (05/10) : Une facture émise ne s\'annule pas',
                'regles' => ['documents.client-fige-et-facture-immuable'],
            ],
            '2.1-037' => [
                'decision' => 'Message 77 (05/10) : « le client comptoir ne doit jamais avoir un credit »',
                'regles' => ['fiches.client-comptoir', 'paiements.bon-d-avoir'],
            ],
            '2.1-038' => [
                'decision' => 'Message 78 à 97 (05-06/10) : Calculer HT, TVA et TTC « séparément », article par article',
                'remplacee' => 'Remplacée par la politique d\'arrondi unique des ventes (série 8, Q1-a et R4 ; CLAUDE.md, « Prix et TVA des ventes »).',
            ],
            '2.1-039' => [
                'decision' => 'Message 98, 99 (06/10) : Quantités en nombres entiers',
                'regles' => ['ecrans.quantites-sans-decimales'],
            ],
            '2.1-040' => [
                'decision' => 'Message 106, 109 (07/10) : Bouton « Ajouter un service », prix TTC saisi ; un produit est un bien, un service est un service',
                'regles' => ['documents.un-produit-une-ligne', 'prix.remises-et-prix'],
            ],
            '2.1-041' => [
                'decision' => 'Message 107 (07/10) : Logiciel indépendant ; aucun mélange ; repartir de zéro',
                'regles' => ['produit.magasins-isoles', 'developpement.aucune-donnee-reelle'],
            ],
            '2.1-042' => [
                'decision' => 'Message 110 (08/10) : Une ligne de vente ne change ni la description ni le prix de la fiche',
                'regles' => ['prix.remises-et-prix'],
            ],
            '2.1-043' => [
                'decision' => 'Message 110, 111 (08/10) : Un produit naît d\'une facture d\'achat',
                'regles' => ['achats.produit-ne-d-un-achat-ou-d-un-inventaire'],
            ],
            '2.1-044' => [
                'decision' => 'Message 111, 112 (08/10) : Achats auprès de particuliers : « on laisse tomber pour l\'instant […] on va revenir »',
                'a_venir' => 'Étape 14 (achats auprès de particuliers), reportée par l\'éditeur.',
            ],
            '2.1-045' => [
                'decision' => 'Message 112 (08/10) : Coût moyen pondéré (CMUP)',
                'regles' => ['achats.retour-au-cout-de-la-ligne', 'achats.verification-de-chaque-nuit'],
            ],
            '2.1-046' => [
                'decision' => 'Message 112 à 115 (08/10) : Barème de prix de vente réglable par le magasin, exemples réels proposés, désactivé par défaut mais visible',
                'regles' => ['prix.prix-de-vente-arrondi-une-fois'],
            ],
            '2.1-047' => [
                'decision' => 'Message 116, 121 à 124 (08/10) : Seul le prix de vente TTC s\'arrondit, une fois, à l\'enregistrement de l\'achat ; ensuite rien ne se recalcule',
                'regles' => ['prix.prix-de-vente-arrondi-une-fois', 'prix.sommes-des-montants-enregistres'],
            ],
            '2.1-048' => [
                'decision' => 'Message 136, 137 (08/10) : Connexion SUPER PDP par OAuth, préparée selon la documentation et testée avec des faux',
                'regles' => ['outillage.fournisseurs-externes-derriere-une-interface'],
            ],
            '2.1-049' => [
                'decision' => 'Message 156 (10/10) : Campagne de tests très approfondie',
                'a_venir' => 'Campagne de tests initiale (étape 1 bis).',
            ],
            '2.1-050' => [
                'decision' => 'Message 157, 158 (10/10) : PostgreSQL',
                'regles' => ['outillage.postgresql-seul'],
            ],
            '2.1-051' => [
                'decision' => 'Message 167 (11/10) : SUPER PDP regroupe et transmet au PPF ; PILIQI ne tient pas de calendrier de déclaration',
                'regles' => ['pdp.e-reporting-et-encaissements-des-services'],
            ],
            '2.1-052' => [
                'decision' => 'Message 171 (11/10) : Ticket transformé en facture : réglé en totalité par l\'avoir d\'annulation ; avoirs sur tickets par un vendeur autorisé',
                'a_venir' => 'Lot C-b de la chaîne TVA (transformation d\'un ticket en facture, réglée par l\'avoir d\'annulation) ; les avoirs sur tickets existent déjà (paiements.avoirs-partiels-et-multiples).',
            ],
            '2.1-053' => [
                'decision' => 'Message 172 (11/10) : Moteur de TVA centralisé',
                'a_venir' => 'Moteur de TVA centralisé (série 7, chaîne TVA), à faire avant de cocher l\'étape 1.',
            ],
            '2.1-054' => [
                'decision' => 'Message 173, 174 (11/10) : Retrait de Spatie, autorisations propres',
                'regles' => ['type.familles-d-autorisations'],
            ],
            '2.1-055' => [
                'decision' => 'Message 176 (11/10) : « chaque magasin a un seul responsable » ; l\'administrateur ne crée pas de responsable',
                'remplacee' => 'Remplacée le jour même : plusieurs responsables par magasin, qu\'un administrateur autorisé peut nommer (CLAUDE.md, « Comptes et équipe »).',
            ],
            '2.1-056' => [
                'decision' => 'Message 177, 178 (11/10) : « l\'export comptable […] creer uniqument par le proptietaire », corrigé en « Je voulais dire expert comptable »',
                'remplacee' => 'Remplacée par l\'espace des cabinets ; l\'export comptable de la société se fait à l\'étape 5.',
            ],
            '2.1-057' => [
                'decision' => 'Message 179 (11/10) : Espace « charges du magasin »',
                'a_venir' => 'Chaîne TVA et étape 5 (charges du magasin, comptabilité).',
            ],
            '2.1-058' => [
                'decision' => 'Message 181 (11/10) : Inscription d\'une société sans magasin',
                'regles' => ['comptes.inscription-par-lien', 'societe.magasins-crees-dans-l-espace-de-la-societe'],
            ],
            '2.1-059' => [
                'decision' => 'Message 210 (17/10) : Les 26 retours de la série 8 (mode « Avoir », retours par numéro, PDF, rôle après « Bonjour », administrateur sans vente, « Les caisses », pièce jointe d\'achat, import automatique, mentions à 0 %, tableaux de sélection, journal, clôtures détaillées, factures à encaisser, équipe, produits nés d\'un achat, avoirs fournisseur à la main, identifiant de connexion, e-reporting)',
                'explication' => 'Chacun des 26 retours est repris un par un dans la source « série 8 » de cette matrice, avec ses règles.',
            ],
            '2.1-060' => [
                'decision' => 'Message 210, point 6 (17/10) : « Administratori nuk duhet leju me ba shitje » (l\'administrateur ne doit pas vendre)',
                'regles' => ['comptes.administrateur-sans-vente-par-defaut'],
            ],
            '2.1-061' => [
                'decision' => 'Message 212 (18/10) : Premier mot de passe égal à l\'identifiant, changement facultatif avec une alerte',
                'regles' => ['comptes.premier-mot-de-passe'],
            ],
            '2.1-062' => [
                'decision' => 'Message 227 à 229 (19/10) : SIREN unique ; vérification par le seul courrier recommandé, remis au dirigeant en personne ; réponses A1, B1, C1, D1, E2',
                'regles' => ['verification.siren-unique-et-inscription', 'verification.courrier-recommande', 'verification.saisie-du-code'],
            ],
            '2.1-063' => [
                'decision' => 'Message 230, 231 (19/10) : Espace des cabinets d\'expertise comptable ; réponses F1, G2, H1, I1, J1, K2',
                'regles' => ['cabinets.espace-du-cabinet', 'cabinets.expert-comptable-declare'],
            ],
            '2.1-064' => [
                'decision' => 'Message 232 (19/10) : Accord pour bacon/bacon-qr-code',
                'regles' => ['verification.courrier-recommande', 'outillage.aucun-paquet-inutilise'],
            ],
            '2.1-065' => [
                'decision' => 'Message 255 à 260 (25/10) : Prix unitaires affichés au centime, calcul exact inchangé',
                'regles' => ['prix.pu-ht-a-six-decimales'],
            ],
            '2.1-066' => [
                'decision' => 'Message 256 (25/10) : « En fait les mentions je suis contre mais je sais pas si c’est mieux ou pas »',
                'remplacee' => 'Remplacée : la note de calcul des PDF est gardée et complétée (documents.note-de-calcul).',
            ],
            '2.1-067' => [
                'decision' => 'Message 275 (26/10) : Fuseau UTC de la base fixé par les migrations, contrôlé par caisse:diagnostiquer',
                'regles' => ['outillage.instants-en-utc'],
            ],
            '2.1-068' => [
                'decision' => 'Message 321 (01/11) : « je veux que cette option existe […] l\'ajoute doit se faire par son nom et par son siren de l\'expert comptable […] il se rattache tout de suite des que l\'expert comptable ouvre son compte chez piliqi […] il doit choisir que peut voir avec des autorisation » ; choix par la fenêtre de questions : premier mot de passe par un lien envoyé à l\'adresse professionnelle (7 jours), SIREN actif et 69.20Z, compte fermé au rattachement (l\'accès passe par le cabinet), plusieurs personnes d\'un seul SIREN',
                'regles' => ['cabinets.expert-comptable-declare', 'cabinets.comptes-de-l-expert-comptable-declare', 'cabinets.rattachement-de-la-declaration'],
            ],
            '2.1-069' => [
                'decision' => 'Message 323, 325 (01/11) : Phrase de l\'inscription sur les activités exonérées réécrite (« a) d\'accord pour cette phrase, et le refus reste ») : la franchise en base (art. 293 B) est prise en charge, les activités exonérées par la loi (art. 261 à 261 E) restent refusées ; astérisque du mot de passe de l\'inscription',
                'regles' => ['type.code-d-activite'],
            ],
            '2.1-070' => [
                'decision' => 'Message 324, 325 (01/11) : TVA sur la marge : case « Bien d\'occasion – TVA sur la marge » près du prix du produit, mention et article de loi sur le PDF et la facture électronique ; « 1. Quand le faire ? le b) » (dans la chaîne TVA, avant l\'étape 2 ; achats auprès de particuliers toujours à l\'étape 14) ; « 2. Comment calculer la marge ? le a) » (article par article)',
                'a_venir' => 'Chaîne TVA (TVA sur la marge, avant l\'étape 2) et étape 14 (achats auprès de particuliers).',
            ],
            '2.1-071' => [
                'decision' => 'Message 327 (01/11) : « je veut utilier le https://mailtrap.io pour les email […] tu peux faire quelque testes mais pas à chaque fois »',
                'regles' => ['emails.envoi-des-e-mails'],
            ],
            '2.1-072' => [
                'decision' => 'Message 01/11 vers 16 h 15 UTC (01/11) : Capture de php artisan queue:listen coupé au bout de 60 s (ProcessTimedOutException) sur son PC, .env réglé sur Mailtrap',
                'regles' => ['emails.envoi-des-e-mails'],
            ],
            '2.1-073' => [
                'decision' => 'Message 01/11 vers 17 h 20 UTC (01/11) : Deux captures : SIREN d\'un vrai cabinet refusé en local (« n\'est pas 69.20Z ») ; « Créer votre premier magasin » reste affiché après le premier magasin, « laisser un texte par défaut comme "Ajouter un magasin" ou écrire par deuxième, troisième »',
                'explication' => 'Refus expliqué à l\'éditeur (le SIREN essayé n\'a pas le code d\'activité 69.20Z au répertoire) ; le libellé du premier magasin a été corrigé.',
            ],
            '2.1-074' => [
                'decision' => 'Message 01/11 vers 17 h 40 UTC (01/11) : « 1a 2a 3 oui » (questions laissées par le lot A7 : émetteur de la facture rectificative, SIREN d\'un cabinet, trois choix d\'adaptation)',
                'regles' => ['societe.regularisation-apres-correction-du-siren', 'cabinets.correction-revocation-radiation'],
            ],
            '2.1-075' => [
                'decision' => 'Message 01/11 vers 18 h 00 UTC (01/11) : Capture : « 403 Forbidden » sur /societe/{uuid} après la saisie du code du courrier d\'un cabinet',
                'regles' => ['cabinets.espace-du-cabinet'],
            ],
            '2.1-076' => [
                'decision' => 'Message 01/11 vers 18 h 55 UTC (01/11) : « pour le Le cahier des charges du lot EC2: « tout comme recommandé » » (P1 a, P2 b, P3 b, P4 à P13 a)',
                'regles' => ['cabinets.expert-comptable-declare', 'cabinets.comptes-de-l-expert-comptable-declare'],
            ],
            '2.1-077' => [
                'decision' => 'Message 01/11 vers 19 h 20 UTC (01/11) : « les durées de conservation: laisse comme ils sont » (question sur les variables CONSERVATION_* de .env.example, en commentaire, valeurs par défaut dans config/conservation.php : option c)',
                'regles' => ['caisse.conservation-et-effacements'],
            ],
            '2.1-078' => [
                'decision' => 'Message 01/11 vers 21 h 30 UTC (01/11) : Deux captures : « comment pour un comptable voir les siennes. elle peut pas faire les siennes. elle vois normalemnt tout les factures de tout ou peut etre tu as d autre proposition »',
                'regles' => ['cabinets.autorisations-accordees-par-le-client'],
            ],
            '2.1-079' => [
                'decision' => 'Message 01/11 vers 22 h 10 UTC (01/11) : Trois captures : une société validée par l\'invitation de son cabinet voit « Saisir le code du courrier recommandé » et « Essayer le magasin de démonstration », « pas besoin d\'être affichés » ; ouvrir un client depuis l\'espace du cabinet mène à une page 404',
                'regles' => ['cabinets.invitation-d-une-entreprise-non-inscrite', 'cabinets.espace-du-cabinet'],
            ],
            '2.1-080' => [
                'decision' => 'Message 01/11 vers 22 h 35 UTC (01/11) : « le client comptoir […] ne doit pas pouvoir aller voir ou editer […] transformer son ticket en facture […] uniquement la transformation en facture en un clique sans pouvoir creer d\'abort l\'avoir et apres la facture […] ce client ne doit jamais avoir un avoir car c\'est inconnu »',
                'regles' => ['fiches.client-comptoir'],
            ],
            '2.1-081' => [
                'decision' => 'Message 01/11 vers 22 h 50 UTC (01/11) : « 1a 2a( mais la il faut afficher qq part (peut etre dans le tableau de bord ) les avoir des client comptoires et professionel s\'il reste des avoir sans regler […] ) 3 oui »',
                'regles' => ['vendeurs.avoirs-non-utilises', 'fiches.client-comptoir'],
            ],
            '2.1-082' => [
                'decision' => 'Message 01/11 vers 22 h 58 UTC (01/11) : Trois captures : la fenêtre « Chercher un client » (et celle des produits) s\'ouvre, mais cliquer une ligne donne « This page has expired » puis « 419 Page Expired »',
                'hors_logiciel' => 'Diagnostic d\'un écran sur le PC de l\'éditeur, corrigé par le lot SL ; les tests en navigateur rejouent ces fenêtres.',
            ],
            '2.1-083' => [
                'decision' => 'Message 01/11 vers 23 h 05 UTC (01/11) : « il faut faire tous les testes en navigateur et pas que le code […] » ; « je parle de tous les actions qui se font sur navigateurs, à chaque clique il y a un comportement donc qqch qui doit se finir comme prevu »',
                'regles' => ['outillage.tests-en-navigateur'],
            ],
            '2.1-084' => [
                'decision' => 'Message 01/11 vers 23 h 10 UTC (01/11) : « 1a 2 oui 3 oui » (Pest 4 et son module browser ; chaque action testée en navigateur, balayage, chaque lot suivant ; ordre SL, NAV, ML, EC2, S9-L, S9-A, 21)',
                'regles' => ['outillage.tests-en-navigateur'],
            ],
            '2.1-085' => [
                'decision' => 'Message 01/11 vers 23 h 20 UTC (01/11) : « il faut pas que tu oublie de notes que à la fin pour l\'audit de faire tous ces testes en navigateur et les testes que tu as deja fait comme d\'habitude »',
                'a_venir' => 'Étape 15 (lots NAV-2 à NAV-5 : chaque action de chaque écran testée en navigateur pour chaque rôle).',
            ],
            '2.1-086' => [
                'decision' => 'Message 01/11 vers 23 h 30 UTC (01/11) : « mais tout les testes il faut qu\'il passe deja à partir de mtn mais aussi à la fin »',
                'regles' => ['protocole.suite-complete-sans-test-saute', 'outillage.tests-en-navigateur'],
            ],
            '2.1-087' => [
                'decision' => 'Message 01/11 vers 23 h 40 UTC (01/11) : « fait ce qui est le meilleur donc s\'il faut ajouter alors ajoute tout pour que le logiciel soit hyper performant avec zero de faille ou erreurs. je veux que le logiciel soit le meilleur de tout »',
                'a_venir' => 'Étape 15 (couverture, tests de mutation et analyse statique au plus strict, reportés par D63).',
            ],
            '2.1-088' => [
                'decision' => 'Message 01/11 vers 23 h 50 UTC (01/11) : « mais toi tu teste tout les options alors comment peux encore avoir des inantendu »',
                'explication' => 'Réponse à une question : les combinaisons sont trop nombreuses pour être toutes écrites ; des tests au hasard à graine fixe vérifient des invariants (prix.calcul-depuis-le-ttc).',
            ],
            '2.1-089' => [
                'decision' => 'Message 02/11 vers 01 h 05 UTC (02/11) : Capture « Envoyer par e-mail : Ticket de caisse TK-PARIS-2026-000001 » : « tous les envois des mails ils s\'envoient pas, comme ex une facture ou ticket ou devis donc rien ne s\'envoie »',
                'regles' => ['emails.envoi-des-e-mails'],
            ],
            '2.1-090' => [
                'decision' => 'Message 02/11 vers 01 h 40 UTC (02/11) : « je vois que le vendeur ne vois pas ce qui reste à payer ou Factures à encaisser » ; « si je coche « Factures : voir celles de toute l\'équipe » alors il voit tous les factures des autres et je veux pas comme ca. donc par defaut il faut que chacun voit tout les siennes »',
                'regles' => ['vendeurs.factures-a-encaisser', 'vendeurs.ses-factures'],
            ],
            '2.1-091' => [
                'decision' => 'Message 02/11 vers 01 h 50 UTC (02/11) : Capture de la caisse : « Il reste 20,00 € à répartir. » affiché deux fois après le clic sur « Encaisser » ; « il faut affiche une seul fois même si cliquer sur encaisser »',
                'regles' => ['hors.ecrans-de-la-vente'],
            ],
            '2.1-092' => [
                'decision' => 'Message 02/11 vers 01 h 55 UTC (02/11) : Capture de la fiche d\'un avoir : « il faut que les factures sur Crédit appliqué soit cliquable pour aller voir la facture ou ticket comme les avoirs »',
                'regles' => ['ecrans.numeros-de-documents-cliquables'],
            ],
            '2.1-093' => [
                'decision' => 'Message 02/11 vers 02 h 30 UTC (02/11) : (suite du constat des e-mails) envoi réussi avec QUEUE_CONNECTION=sync, PDF trouvé',
                'regles' => ['emails.envoi-des-e-mails'],
            ],
            '2.1-094' => [
                'decision' => 'Message 02/11 vers 07 h 55 UTC (02/11) : « Fais comme tu proposes - recommandé » (rythme des nouvelles tentatives d\'un envoi d\'e-mail raté : option b, 0, +30 s, +2 min)',
                'regles' => ['emails.envoi-des-e-mails'],
            ],
            '2.1-095' => [
                'decision' => 'Message 02/11 vers 08 h 55 UTC (02/11) : Devis acceptés ou refusés par le client en ligne, puis payés à distance (carte, Apple Pay, Google Pay, Pay by Bank, PayPal) ; « oui » pour le lien Accepter / Refuser plus tôt, « si tu peux faire hyper sécurisé »',
                'regles' => ['documents.reponse-du-client-au-devis'],
            ],
            '2.1-096' => [
                'decision' => 'Message 02/11 vers 10 h 00 UTC (02/11) : Stock d\'un devis accepté : « je choisis le a) » ; « le devis accepté n\'a pas l\'option de le transformer en facture ! […] ajouter sur la facture […] à partir du devis accepté numéro de devis […] le devis reste toujours »',
                'regles' => ['documents.devis-transforme-en-facture'],
            ],
            '2.1-097' => [
                'decision' => 'Message 02/11 vers 10 h 15 UTC (02/11) : « j\'accepte tes propositions de 1 à 5 » (stock prévenu sans réservation, « Transformer en facture » sur la fiche et grisé expliqué, mention du devis sur la facture, devis gardé et lié, prix du devis repris)',
                'regles' => ['documents.devis-transforme-en-facture'],
            ],
            '2.1-098' => [
                'decision' => 'Message 02/11 vers 10 h 30 et 10 h 45 UTC (02/11) : « dans les factures et même dans les tickets ne marque pas les méthodes de paiements […] placer tout à gauche du TTC total » ; « je parle si le règlement de la facture est fait en plusieurs méthodes, dont par carte ou espèces etc »',
                'regles' => ['documents.reglements-imprimes-et-recus', 'paiements.plusieurs-modes'],
            ],
            '2.1-099' => [
                'decision' => 'Message 02/11 vers 11 h 25 UTC (02/11) : Capture d\'une facture (2 × 8,33 € imprimé 16,67 €) : « je suis pas content avec les calculs […] il y aura toujours des centimes d\'écart mais moi je veux pas »',
                'remplacee' => 'Tranchée ensuite : Q-CALC2 (a) et PU HT imprimé tel qu\'enregistré (réponses du 09/11/2026).',
            ],
            '2.1-100' => [
                'decision' => 'Message 02/11 vers 11 h 35 UTC (02/11) : « je veux être rassuré de ne pas avoir de problème avec l\'État […] ce que tu as récolté et ce que tu as payé comme TVA »',
                'regles' => ['prix.achats-aux-montants-du-fournisseur', 'achats.tva-des-achats-recopiee'],
            ],
            '2.1-101' => [
                'decision' => 'Message 02/11 vers 22 h 10 UTC (02/11) : « T1 = oui, T2 = oui, T3 = oui, r1 = oui, r2 = oui, les C = non »',
                'regles' => ['achats.tva-des-achats-recopiee', 'prix.achats-aux-montants-du-fournisseur'],
            ],
            '2.1-102' => [
                'decision' => 'Message 02/11 vers 22 h 25 UTC (02/11) : « s\'il y a quelque chose à faire moi à la main comme .env alors tu me dis toujours »',
                'hors_logiciel' => 'Forme des comptes rendus (D52).',
            ],
            '2.1-103' => [
                'decision' => 'Message 03/11 vers 00 h 15 UTC (03/11) : Q-TIERS (police et initiales chargées par Filament depuis des sites tiers) : « oui recommandé »',
                'regles' => ['ecrans.aucun-site-tiers'],
            ],
            '2.1-104' => [
                'decision' => 'Message 03/11 vers 00 h 50 UTC (03/11) : Q-MAJ (failles aussitôt, mises à jour régulières à chaque relance de la campagne et avant la production, versions majeures sur décision) : « oui »',
                'hors_logiciel' => 'Entretien des dépendances (D53), contrôlé par l\'audit des dépendances de la CI.',
            ],
            '2.1-105' => [
                'decision' => 'Message 03/11 vers 00 h 55 UTC (03/11) : Captures : rangée d\'un produit choisi « très blanc brillant […] on voit presque rien » ; « changer la couleur belle et le texte soit visible »',
                'regles' => ['hors.rangees-choisies-lisibles'],
            ],
            '2.1-106' => [
                'decision' => 'Message 03/11 vers 01 h 10 et 01 h 35 UTC (03/11) : Téléchargement des documents par période (calendrier, « Tout sélectionner ») ; réponses : « e1 = tout (… chaque création [saisie manuelle] doit avoir sa vraie facture en pièce jointe … pareil pour les avoirs des fournisseurs … option pour sélectionner uniquement "Saisie manuelle") ; E2 les deux options (zip ou CSV) ; E3 = oui c\'est le propriétaire qui doit donner leur autorisation pour chacun ; E4 = même logique ; E5 = chaque magasin pour tout le monde et le reste comme tu as dit ; E6 = comme tu veux »',
                'regles' => ['telechargement.calendrier-types-zip-et-csv', 'telechargement.autorisation-du-proprietaire', 'achats.piece-du-fournisseur'],
            ],
            '2.1-107' => [
                'decision' => 'Message 03/11 vers 01 h 55 UTC (03/11) : « F1 = b), F2 = oui »',
                'regles' => ['achats.piece-du-fournisseur'],
            ],
            '2.1-108' => [
                'decision' => 'Message 07/11 vers 22 h 35 UTC (07/11) : « ecoute je retire ce que j\'ai dit pour ca. donc tu peux installer ce que tu veux […] installe tout ce que juge utile pour securise le logiciel et rendre perfromant et sans faille et sans erreurs. donc tu est libre d installler tout ce que tu vois utile pour le logiciel »',
                'regles' => ['outillage.aucun-paquet-inutilise'],
            ],
            '2.1-109' => [
                'decision' => 'Message 07/11 vers 23 h 25 UTC (07/11) : « Un PDF peut contenir du JavaScript, des actions automatiques ou des fichiers joints, comme le XML d\'une Factur-X. Rien de cela n\'est contrôlé. alors il faut controlle les fichier car peuvent pirate le logiciel ou le sit e ou lle system »',
                'regles' => ['fichiers.pdf-sans-code-actif', 'fichiers.type-lu-et-nom-reecrit'],
            ],
            '2.1-110' => [
                'decision' => 'Message 07/11 vers 23 h 40 UTC (07/11) : Trois archives d\'audits (corrections, cabinets, audit PG17) : « il faut que tu lise tout ces que te donne. c\'est tres important pour logiciel »',
                'hors_logiciel' => 'Lecture des audits remis par l\'éditeur, appliqués par le lot AUD.',
            ],
            '2.1-111' => [
                'decision' => 'Message 12/11 à 02 h 22 et 02 h 26 UTC (12/11) : « donc cette colonne tu laisse vide car il faut remplire par l expert comptable ou etc. mais tu mets tes decision dans une colenne à part comme t as nomé toi "Réponse préparée et decidé par Claude" », puis « mais ce sont plus mes reponse car tu va enleve et tu va mettre tes reponse (decision) à la place. donc mes decision tu va juste supprimer »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-112' => [
                'decision' => 'Message 12/11 à 02 h 13 UTC (12/11) : « tu fait rien pour le demo, laisse comme est mtn et juste tu autorise que le proprietaire puisse faire tout dans le compte demo, vendre et tout tout les autorisation qui existe. donc les button tu laisse car les button s affiche uniquement quand on est sur le compte demo »',
                'regles' => ['comptes.proprietaire-dans-la-demonstration'],
            ],
            '2.1-113' => [
                'decision' => 'Message 12/11 à 02 h 06 et 02 h 07 UTC (12/11) : « je pense laisser tombre pour le demo car tu me parle des loi exigant. […] on garde le demo peut etre pour toujours car la loi exige. mais le proprietaire doit pouvour faire tout dans un compte demo car sinon comment il va s entrainer si fait pas des facture et tout le reste. »',
                'remplacee' => 'Remplacée par la décision de 02 h 13 UTC le même jour (D71) : la démonstration reste, le propriétaire y a toutes les autorisations.',
            ],
            '2.1-114' => [
                'decision' => 'Message 12/11 à 01 h 36 et 01 h 43 UTC (12/11) : « donc tu fait le code en se basant sur "Réponse préparée par Claude" et toi regarde au fur à mesure sur la case "Réponse du professionnel" afin que tu fait ce qui dit cette case […] », puis « qunad tu remplis alors n ecris pas tres courte les phrases car je veux comprendre tes decision en detaillant un peu »',
                'a_venir' => 'Lot PRO, après la chaîne en cours (D69 et D76).',
            ],
            '2.1-115' => [
                'decision' => 'Message 12/11 de 00 h 33 à 01 h 30 UTC (12/11) : « tout ce qui est fait ou creer sur demonstration ne doit jamais passer en magasin vrai quand la societe confirme avec le code », puis « il faut supprimer ce qui se fait avec demo. peut etre il faut cherche avec son uuid tout ces fait »',
                'regles' => ['verification.magasin-de-demonstration'],
                'remplacee' => 'Remplacée en partie par D71 : rien de la démonstration n\'est supprimé ; que rien n\'en passe aux magasins réels tient (CLAUDE.md, « Vérification de la société »).',
            ],
            '2.1-116' => [
                'decision' => 'Message 12/11 à 01 h 12 UTC (12/11) : « mais re rempli aussi celles que j ai remplis car tu m as propose meilleur, donc tu remplie meme les miennes »',
                'a_venir' => 'Étape 2 et étape 15 pour T106 à T114 ; l\'aperçu de l\'e-reporting de l\'étape 1 existe (caisse.fiche-de-la-cloture-et-apercu-de-l-e-reporting).',
            ],
            '2.1-117' => [
                'decision' => 'Message 11/11 de 21 h 54 à 23 h 55 UTC (11/11) : Écrit dans la colonne « Réponse du professionnel » de 19 lignes de l\'onglet « À faire vérifier par un professionnel » (T1.1 à T1.4, T1.7, T2.1 à T2.9, T3.1 à T3.4, T4.5) : « Règle validée et imposée par le concepteur (annule et remplace l\'attente de validation par un expert-comptable) » (T1.3, T1.4 ; textes complets dans scratchpad/onglet2-844/lignes.txt)',
                'remplacee' => 'Remplacée le 12/11/2026 par D72.',
            ],
            '2.1-118' => [
                'decision' => 'Message 11/11 vers 11 h 45 UTC (11/11) : « B », à ma question « préférez-vous chercher un expert-comptable dès maintenant, ou faire une seule revue fiscale avant la mise en production (T38) ? »',
                'a_venir' => 'Étape 15 (revue fiscale unique avant la mise en production).',
            ],
            '2.1-119' => [
                'decision' => 'Message 11/11 vers 10 h 15 UTC (11/11) : T45 (écrit dans la colonne « Votre réponse » du tableau « Le plus urgent ») : « les liens doivent restés bloqué / pendant que le cabinet et suspendu / donc les clients des cabinet ne peuvent pas accepter son invitation pendant ce temps mais les client peuvent refuser s ils veulent »',
                'regles' => ['cabinets.rien-avant-d-etre-verifie-et-actif'],
            ],
            '2.1-120' => [
                'decision' => 'Message 11/11 vers 10 h 35 UTC (11/11) : « il faut qe tu mettes tous à part pour tranchar un par un et non complet car je me perd », puis « oui » à ma proposition (T58.1 à T58.4)',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-121' => [
                'decision' => 'Message 11/11 vers 10 h 30 UTC (11/11) : « je t ai dit tout ce qui est comptable ou juriste etc tu mets la et tu note par comme T58 donc pour savoir tout de suite de quoi est à confirmer?!! »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-122' => [
                'decision' => 'Message 11/11 vers 10 h 15 UTC (11/11) : « tu creer un nouveau tab pour ce qu on va apliquer à la fin et que je moi je les vois et toi tu te base sur ca pour la fin du logiciel » (faketime oublié dans ma liste)',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-123' => [
                'decision' => 'Message 11/11 vers 09 h 35 UTC (11/11) : « il faut que change ta méthode pour que tu finisse le logiciel très rapidement […] les tests se font une fois à la fin d\'un lot ou tu laisses que pour github […] tu enlèves par exemple larastan et psalm, dont ceux tu gardes juste pour la fin du logiciel […] Donc tu fais comme tu as fait au début du logiciel »',
                'hors_logiciel' => 'Méthode rapide (D63).',
            ],
            '2.1-124' => [
                'decision' => 'Message 11/11 vers 03 h 40 UTC (11/11) : « 1 et 2 » (à mes propositions pour consommer moins)',
                'hors_logiciel' => 'Méthode des agents (D62).',
            ],
            '2.1-125' => [
                'decision' => 'Message 11/11 vers 03 h 32 UTC (11/11) : « oui » (à ma proposition de passer EC2, DV et S9-L de « extra » à « max »)',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-126' => [
                'decision' => 'Message 11/11 vers 03 h 05 UTC (11/11) : « donc tu fait pour tout jusqu à la fin du logiciel par 2 commit c est ca? »',
                'hors_logiciel' => 'Méthode des lots (D61).',
            ],
            '2.1-127' => [
                'decision' => 'Message 11/11 vers 02 h 39 UTC (11/11) : « t104:Choix A (ma proposition) : une seule liste de cases pour tous les magasins choisis. »',
                'regles' => ['cabinets.magasins-choisis'],
            ],
            '2.1-128' => [
                'decision' => 'Message 11/11 vers 02 h 00 UTC (11/11) : « il a fait quoi le TY1 car il a pris trop de temps !! » (après « il a fait qui le TY1 »)',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-129' => [
                'decision' => 'Message 11/11 entre 00 h 10 et 00 h 30 UTC (11/11) : « quand la societe soit lié avec / l\'expert comptable alors la societe / doit poivoir avoir plusieurs case pour / cocher selon par ex: ce comptable peut voir / et analyser et telecharger de tous les magasin ou que pour pour les magasin qui doit les cocher par magasin, donc il doit appuyer tout les magasins ou à cocher par magasin et quoi peut faire. donc meme la societe doit avoir les case pour lui permetrre de voir quelles menus du magasin ou de la societe car peut etre la societe veut pas que son comptable voit tout. » (écrit dans la colonne « Votre réponse » de T44)',
                'regles' => ['cabinets.magasins-choisis', 'cabinets.autorisations-accordees-par-le-client'],
            ],
            '2.1-130' => [
                'decision' => 'Message 11/11 vers 00 h 10 UTC (11/11) : « oui » (écrit dans la colonne « Votre réponse » de T43)',
                'regles' => ['ecrans.numeros-de-documents-cliquables', 'hors.rangees-choisies-lisibles'],
            ],
            '2.1-131' => [
                'decision' => 'Message 10/11 à 23 h 59 UTC (10/11) : « voila comme ca tu ajoute toujour dans cette endroit »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-132' => [
                'decision' => 'Message 10/11 à 23 h 56 UTC (10/11) : « il faut que tu mettes tout les "Le plus urgent" de la fiche dans un tableux pret à ecrire ma reponse. tu mets comme dans "mes question et vosreponses" dans le tableaux pret à remplir ma reponse car comme tu as fait mtn il aller chercher et copier et ajouter la colonne, c\'est pas pratique comme ca. »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-133' => [
                'decision' => 'Message 10/11 vers 23 h 50 UTC (10/11) : « oui » (écrit par l\'éditeur dans le document, ligne T42 qu\'il a ajoutée dans « Mes questions et vos réponses »)',
                'regles' => ['hors.ecrans-de-la-vente'],
            ],
            '2.1-134' => [
                'decision' => 'Message 10/11 à 23 h 33 UTC (10/11) : « « T41 : cacher » pour que cette partie soit cachée à qui n’a pas le droit de voir les paiements ; »',
                'regles' => ['hors.ecrans-de-la-vente'],
            ],
            '2.1-135' => [
                'decision' => 'Message 10/11 à 23 h 08 UTC (10/11) : « pour dans la "PILIQI — Ce qui reste à trancher" il faut que tu mette ou qqpart ensemble ou strict à part: donc quand tu m \'as proposé et ce que tu vas me proposer il faut mettre à part meme si moi j\'ai repondu autrement malgre que tu m\'as dit par exemple il faut voir avec l expert comptable,donc tu me mets tout tes question ou demande pour que je demand l\'avis de l expert comptable ou juriste ou etc. car je veux toujour avoir dans un tableaux tout ce genre de question meme si moi j\'ai repondu autrement malgre ton avertissement »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-136' => [
                'decision' => 'Message 10/11 à 18 h 16 UTC (10/11) : « T’as dit « • Quand vous avez tranché, je range le point dans « Déjà tranché », avec la date et vos mots, et j\'applique la décision dans le logiciel. » alors tu mets tout mes décisions que j’ai fait depuis le début et aussi à part tes questions avec mes reponses ( décision) et tu mets à part ce qui reste à faire et surtout ceux qui sont liés expert comptable et ceux pour les api etc »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-137' => [
                'decision' => 'Message 10/11 vers 15 h 20 UTC (10/11) : « Je veux que tu mettes dans une note spéciale pour tout ce que tu m’as dit et ce que tu va dire ce que reste à confirmer par mes propos moyens […] après moi ke dois trancher car si non je vais oublier ce que tu me dis. D’accord »',
                'hors_logiciel' => 'Façon de travailler ou tenue du document de l\'éditeur, sans règle du logiciel.',
            ],
            '2.1-138' => [
                'decision' => 'Message 09/11 vers 23 h 00 UTC (09/11) : « exact, A, oui » (réponses aux trois questions : affichage du PU HT des documents aux professionnels ; couverture des branches ; invitations ouvertes d\'un cabinet suspendu)',
                'regles' => ['prix.pu-ht-a-six-decimales', 'cabinets.rien-avant-d-etre-verifie-et-actif'],
            ],
            '2.1-139' => [
                'decision' => 'Message 09/11 vers 21 h 05 UTC (09/11) : « pourquoi le niveau est 6 et s’il peut monter au plus strict, 10 pour Larastan et 1 pour Psalm » (vers 20 h 34), puis, après les mesures et la proposition : « oui + Psalm 1 » (un premier envoi, interrompu et remplacé par l’éditeur, ajoutait « mais tu faise ce que tu vois la meilleurs option »)',
                'a_venir' => 'Étape 15 (lots TY3, PS1 et PS2), reportée par D63.',
            ],
            '2.1-140' => [
                'decision' => 'Message 09/11 vers 10 h 55 UTC (09/11) : « fais-le plus tôt, dans le lot suivant » (après le constat C7 : « Vos tickets de caisse restent possibles » affiché avant la validation)',
                'regles' => ['documents.identite-legale-complete', 'verification.avant-la-validation'],
            ],
            '2.1-141' => [
                'decision' => 'Message 09/11 vers 09 h 45 UTC (09/11) : « caisse à l\'étape 3 » (après « je vois que tu avance du tout avec QA ??? » et l\'explication de la durée des tests de mutation)',
                'a_venir' => 'Étape 3 (tests de mutation de la caisse sécurisée, du registre et des clôtures).',
            ],
            '2.1-142' => [
                'decision' => 'Message 09/11 vers 09 h 15 UTC (09/11) : « « (a) » pour garder l’affichage au centime ; et montre moi la meme pdf avec cette decision afin que je compare »',
                'regles' => ['prix.pu-ht-a-six-decimales', 'prix.calcul-depuis-le-ttc'],
            ],
            '2.1-143' => [
                'decision' => 'Message 09/11 vers 01 h 32 UTC (09/11) : « Et dans la facture que tu m as envoyé il y a apparemment +0.26€ de plus, alors sera accepté ou pas . Car comme j’aime bcp p’us »',
                'explication' => 'Question, pas une décision : l\'écart est accepté selon la tolérance européenne, et la règle des ventes reste celle de Q-CALC2 (a).',
            ],
            '2.1-144' => [
                'decision' => 'Message 09/11 vers 00 h 10 UTC (09/11) : « tout en a, Q-AUD6 oui, Q-AUD7 oui »',
                'regles' => ['protocole.lectures-hors-du-scope-filtrees', 'achats.verification-de-chaque-nuit'],
            ],
            '2.1-145' => [
                'decision' => 'Message 08/11 vers 19 h 30 UTC (08/11) : « Donc ces calcules seront appliquées pour tout »',
                'remplacee' => 'Remplacée le 09/11/2026 par Q-CALC2 (a).',
            ],
            '2.1-146' => [
                'decision' => 'Message 08/11 vers 19 h 20 UTC (08/11) : « Non tu va jamais recalculer donc tu va juste compter à partir des prix existants et pas recalculer comme par exemple tu recalcule pas à l\'inverse »',
                'remplacee' => 'Remplacée le 09/11/2026 par Q-CALC2 (a).',
            ],
            '2.1-147' => [
                'decision' => 'Message 08/11 vers 19 h 10 UTC (08/11) : « Non j\'ai dit tu fait séparément ht et ttc donc 8.33+8.33=16,66 et 10+10=20 »',
                'remplacee' => 'Remplacée le 09/11/2026 par Q-CALC2 (a).',
            ],
            '2.1-148' => [
                'decision' => 'Message 08/11 de 13 h 35 à 14 h 55 UTC (08/11) : Arrondi des factures (discussion avec un tiers sur « l\'arrondi à la ligne ») ; réponses : « non non tu calcule par linga la tva et pas par le total des article […] » ; « non tu calcule separement par ligne d accord 10€ttc et 8.33€ht » ; « mais juste il faut calcule la avec la tva ling par linge mais pas affiche 8.3333 mais 8.33€ht » ; « Je veux calcule ligne par ligne avec sa tva et arrondir par ligne et non par total. Donc 8.33ht*2=16,66 et pareil 10€ttc et après le total de calcul par rapport aux lignes »',
                'remplacee' => 'Remplacée le 09/11/2026 par Q-CALC2 (a).',
            ],
            '2.1-149' => [
                'decision' => 'Message 08/11 vers 13 h 20 UTC (08/11) : Conseil lu sur internet (précision des calculs financiers : bibliothèques décimales reconnues plutôt que des nombres à virgule flottante) : « je crois qu\'il a raison mais tu me dit aussi c\'est bien d\'apliquer ca »',
                'regles' => ['prix.calcul-depuis-le-ttc', 'prix.sommes-des-montants-enregistres'],
            ],
            '2.1-150' => [
                'decision' => 'Message 08/11 vers 00 h 00 UTC (08/11) : « et je veux pas que tu fasse sqlite car j\'utilis que postgresql pour la production et developemnet du coup pas besion sqlite »',
                'regles' => ['outillage.postgresql-seul'],
            ],
            '2.1-151' => [
                'decision' => 'Message 07/11 vers 23 h 57 UTC (07/11) : « q-fi1(a), q-fi2 (a) »',
                'regles' => ['fichiers.pdf-sans-code-actif', 'fichiers.photo-sans-metadonnees'],
            ],
            '2.1-152' => [
                'decision' => 'Message 07/11 vers 22 h 40 UTC (07/11) : « et avec phpstan tu doit alors controle tout le logiciel ? »',
                'explication' => 'Réponse à une question sur l\'analyse statique (tout le code, à chaque envoi), reportée à l\'étape 15 par D63.',
            ],
            '2.1-153' => [
                'decision' => 'Message 322 (01/11) : « 1a 2a 3a 4a » : constat 10 d\'EC (refus d\'inviter le SIREN d\'un cabinet gardé, exception écrite dans CLAUDE.md) ; Q-AF10 (rouge à partir du 31e jour sans preuve d\'export) ; Q-AF12 (preuve notée même dans un magasin fermé) ; Q-AF13 (« Garder », « C\'est vérifié », date du visa)',
                'regles' => ['cabinets.invitation-d-un-siren', 'hors-france.preuves-d-export', 'statuts.lecture-seule'],
            ],
        ],
        'Réponses de l\'éditeur à la série 9, du 26/10/2026 au 02/11/2026 (serie9/reponses-serie9.md)' => [
            'Q1' => [
                'decision' => 'Un produit n\'a qu\'une ligne partout où l\'on ajoute des produits (caisse, facture, devis, facture d\'achat, inventaire d\'ouverture) ; le choisir de nouveau, par n\'importe quel geste, augmente la quantité de sa ligne.',
                'regles' => ['documents.un-produit-une-ligne', 'achats.une-ligne-par-produit'],
            ],
            'Q2' => [
                'decision' => 'Produit déjà sur une ligne remisée ou à un autre prix que sa fiche : selon le droit ; avec l\'autorisation des remises, la quantité augmente au prix de la ligne ; sans elle, l\'ajout est refusé avec un message, jamais une seconde ligne.',
                'regles' => ['documents.un-produit-une-ligne', 'prix.remises-et-prix'],
            ],
            'Q3' => [
                'decision' => 'Services : même règle ; un service sans prix de référence saisi à un autre prix a sa propre ligne.',
                'regles' => ['documents.un-produit-une-ligne'],
            ],
            'Q4' => [
                'decision' => 'Qui voit quoi dans « À faire » : « À faire » et « À surveiller » ; l\'expert-comptable, cabinet lié comme expert-comptable de la société, n\'en voit rien.',
                'regles' => ['vendeurs.tableau-de-bord'],
            ],
            'Q5' => [
                'decision' => 'Facture d\'achat avec le même produit à deux prix : toujours une seule ligne, au prix moyen, l\'écart d\'un centime accepté ; à l\'étape 2, deux lignes du même produit d\'une facture reçue se réunissent ou passent en saisie manuelle, à trancher au lot de cette étape.',
                'regles' => ['achats.une-ligne-par-produit'],
            ],
            'Q6' => [
                'decision' => 'Vendeur qui encaisse sans pouvoir contre-passer : qui encaisse voit ses factures à encaisser.',
                'regles' => ['vendeurs.factures-a-encaisser'],
            ],
            'Q7' => [
                'decision' => 'Un brouillon ou un devis apparaît dans « À faire » tout de suite ; un devis envoyé 7 jours avant la fin de sa validité, un devis accepté aussitôt.',
                'regles' => ['vendeurs.tableau-de-bord'],
            ],
            'Q8' => [
                'decision' => 'Espace de la société : des nombres par magasin et par groupe, sans nom de client ni numéro de document.',
                'regles' => ['vendeurs.a-faire-de-la-societe'],
            ],
            'Q9' => [
                'decision' => 'Aucun menu n\'affiche plus de nombre et les listes n\'ont pas d\'onglets avec des nombres : le tableau de bord est le seul endroit où se voit ce qui reste à faire.',
                'regles' => ['vendeurs.tableau-de-bord'],
            ],
            'S9L-6' => [
                'decision' => 'Un brouillon rouvert n\'est jamais réuni d\'office ; seul un nouvel ajout se réunit ; le domaine refuse tout document qui porte deux lignes du même produit, quel que soit l\'écran ou l\'API.',
                'regles' => ['documents.un-produit-une-ligne'],
            ],
            'Q-AF10' => [
                'decision' => 'Une vente hors de l\'Union européenne sans sa preuve de douane est en orange jusqu\'au 30e jour après la date de la facture, en rouge à partir du 31e, en jours du calendrier du magasin.',
                'regles' => ['hors-france.preuves-d-export'],
            ],
            'Q-AF12' => [
                'decision' => 'Une preuve d\'exonération se note encore dans un magasin en lecture seule, par qui a l\'autorisation de la noter.',
                'regles' => ['hors-france.preuves-d-export', 'statuts.lecture-seule'],
            ],
            'Q-AF13' => [
                'decision' => '« Garder » une nomination d\'un responsable par un administrateur, « C\'est vérifié » pour un écart Sirene après la validation, date du visa d\'un ticket détaxé : chaque ligne quitte l\'« À faire » une fois le geste fait, inscrit au journal d\'audit.',
                'regles' => ['comptes.nomination-des-responsables', 'verification.reverification-et-ecarts', 'hors-france.detaxe'],
            ],
            'AVOIRS-01-11' => [
                'decision' => 'Le 01/11/2026 vers 22 h 50 : les avoirs non utilisés (bons d\'avoir du client comptoir et crédits des clients) se voient au tableau de bord du magasin et, en nombres et montants par magasin, dans l\'espace de la société ; le client comptoir n\'apparaît plus dans la liste des clients.',
                'regles' => ['vendeurs.avoirs-non-utilises', 'fiches.client-comptoir'],
            ],
            'SIENNES-02-11' => [
                'decision' => 'Le 02/11/2026 vers 01 h 40 : chacun voit toutes ses factures à encaisser ; « voir celles de toute l\'équipe » reste une autorisation à cocher.',
                'regles' => ['vendeurs.factures-a-encaisser', 'vendeurs.ses-factures'],
            ],
        ],
    ],
];
