# Cahier du lot ER-PRO (étape 1, après la chaîne en cours)

Préparé le 13/11/2026 pour la directive D76 de l'éditeur (12/11/2026). Ce cahier fusionne le lot « ER » de `scratchpad/nouvelle-session/a-faire-apres-chaine.md` (section « Lot « ER » ») et remplace tous ses passages « attend T107 / T108 / T112 / T113 » : ces questions ont reçu une décision (acceptée par l'éditeur le 12/11/2026, ou décidée par Claude sous D76). Rien dans ce lot n'attend plus l'avis d'un expert-comptable ni d'un professionnel.

## 0. Cadre

- Ordre après la chaîne en cours : ER-PRO (avec la note de calcul T105 et le correctif qpdf, § 10 et § 11), puis DEMO-V (T115, cahier dans `a-faire-apres-chaine.md`), puis VER (PHP 8.5 / PostgreSQL 18), puis la campagne de tests 1 bis.
- Dépôt lu en lecture seule à `f159294` (« Donne à la société un expert-comptable déclaré… », lot EC2). Les numéros de ligne cités viennent de ce commit ou de `inventaire-code.md` (fait à `b10d2e8`) : au lancement, retrouver chaque endroit par son nom (constante, méthode, test), jamais par le seul numéro.
- Sources : `scratchpad/pro-d76/inventaire-code.md` (sections A à F), champ « code » des décisions (`scratchpad/pro-d76/decisions-*.json`), onglet « À faire vérifier par un professionnel » du document de pilotage (colonne « Réponse préparée et décidée par Claude »), propositions T106 à T114 acceptées le 12/11/2026 (`scratchpad/onglet2-844/analyse/plan-rangement.md`, lignes 231 à 248).
- Protocole de CLAUDE.md : tests ciblés pendant le travail (`php artisan test --compact <fichier>`) ; une seule fois, juste avant le commit, la suite complète sur PostgreSQL (`DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --parallel`, puis `echo $?` qui doit valoir 0) ; `vendor/bin/pint --dirty --format agent` ; un commit pour le lot, puis celui de la correction des relectures (D61, D63) ; deux relectures en parallèle.
- Règle des tests : chaque test qui vérifie une mention d'attente est réécrit pour la règle décidée, avec des assertions au moins aussi précises (montants exacts, textes exacts). Aucun test n'est supprimé sans un test de remplacement qui vérifie la nouvelle règle ; aucun n'est affaibli.
- Règle de développement du projet : une table change par sa migration de création, puis `php artisan migrate:fresh` ; aucun code pour des données antérieures.
- Une « Réponse du professionnel » écrite plus tard dans l'onglet, même après la mise en production, prime : elle donne alors un lot qui refait le code concerné.

## 1. CLAUDE.md

### 1.1 Règle générale, en tête de la section « Décisions de l'éditeur »

Ajouter, comme première puce de « ## Décisions de l'éditeur » (avant « Comptes et équipe ») :

> - Règles légales et réglementaires : chaque point reçoit une décision de Claude, écrite dans l'onglet « À faire vérifier par un professionnel » du document de pilotage, que PILIQI applique sans attendre ; aucun code, écran, mention ni règle n'attend l'avis d'un expert-comptable ou d'un professionnel ; une réponse d'un professionnel écrite dans cet onglet, même après la mise en production, prime et se reporte dans le code (décision de l'éditeur du 12/11/2026, D76).

Le point 4 du protocole (« Si l'échec révèle une règle métier à trancher (calcul, arrondi, obligation légale), la soumettre à l'utilisateur au lieu de choisir ») reste tel quel : il vise l'éditeur. Une règle légale nouvelle va en plus dans l'onglet, avec une décision de Claude.

### 1.2 Phrases qui attendent un avis : à remplacer

| # | Puce (ligne à `f159294`) | Texte actuel | Texte à écrire | Lignes du document |
|---|---|---|---|---|
| C-1 | « Facture électronique (SUPER PDP) » (l. 176) | « … sauf option pour les débits ; les paiements de particuliers pour des services ne sont déclarés qu'une fois la règle confirmée par l'expert-comptable de la société. » | « … sauf option pour les débits ; les paiements de particuliers pour des services, sans option pour les débits, se déclarent dans l'e-reporting des paiements, agrégés par jour d'encaissement : le montant encaissé TTC, ventilé par taux de TVA (une réparation de 60,00 € TTC à 20 % se déclare 60,00 € au taux de 20 %) ; d'une vente qui mêle biens et services, seule la part des services se déclare ; un paiement partiel se répartit entre les taux de service au prorata des TTC du document d'origine, restant après un avoir, en parts calculées en cumul dans l'ordre des taux, arrondies chacune au centime le plus proche (un demi-centime au centime supérieur), leur somme égalant le paiement ; un crédit d'avoir utilisé compte comme un paiement ; quand le crédit d'un avoir qui annule une prestation déjà payée règle une autre vente, la part de service annulée se déduit, au taux de la ligne corrigée, des encaissements du jour de cette utilisation ; une société qui a opté pour les débits ne déclare aucun paiement de services. » | T1.1 à T1.4, T9.3, T107.1, T107.2, T112.1 |
| C-2 | même puce (l. 176) | « Une vente à 0 % n'est pas transmise tant que son motif d'exonération n'est pas géré. » | « Une vente à 0 % se transmet avec la catégorie de son motif : l'export hors UE et la livraison vers un DOM en G (VATEX-EU-G), la livraison intracommunautaire en K (VATEX-EU-IC), la prestation à un professionnel hors UE en E (jamais O), la vente en franchise en base en E (VATEX-FR-FRANCHISE et « TVA non applicable, art. 293 B du CGI ») ; une nature dont le motif n'a pas encore de code dans PILIQI n'est pas transmise avant l'étape 2, qui le code. » | T2.1 à T2.3, T2.6, T2.7, T2.10 |
| C-3 | « Caisse et clôtures » (l. 175) | « … l'aperçu de ce que l'e-reporting déclarera pour la journée (totaux par catégorie et par taux, opérations non soumises à la TVA en France comprises). » | « … l'aperçu de ce que l'e-reporting déclarera pour la journée (totaux par catégorie et par taux, opérations non soumises à la TVA en France comprises) : une vente en franchise en base, comme une vente en France à 0 % hors franchise, s'y range en livraison de biens ou en prestation de services selon la nature de la ligne, au taux 0 %, et un export ou une livraison vers un DOM à un particulier parmi les opérations non soumises à la TVA en France, au taux 0 %. » | T2.4, T2.5, T108.1, T108.2 |
| C-4 | « Société et établissements » (l. 163) | « … le crédit de cet avoir est réservé à la facture qu'il régularise, puis à sa rectificative ; cette règle est à faire confirmer par l'expert-comptable de la société le jour où le cas se présente. » | « … le crédit de cet avoir est réservé à la facture qu'il régularise, puis à sa rectificative (règle de l'onglet « À faire vérifier par un professionnel », T27.1 à T27.5). » Le lot de la régularisation (§ 12) ajoutera « , qui ne s'émet qu'après lui » (T68.1). | T27.1 à T27.5 |
| C-5 | « Caisse et clôtures » (l. 175) | « … avec la date de fin de l'exercice en cours fixée par le procès-verbal et une durée validée par l'expert-comptable de la société, sans jamais toucher un exercice clos ni une archive, ni repousser une clôture obligatoire ; » | « … avec la date de fin de l'exercice en cours fixée par le procès-verbal, l'organe qui l'a décidée, la date de la décision et sa pièce (le nom d'un expert-comptable consulté étant facultatif), sans jamais toucher un exercice clos ni une archive, ni repousser une clôture obligatoire : la procédure n'accepte qu'un exercice raccourci, terminé le dernier jour d'un mois ; » | T28.1, T28.2 |

### 1.3 Phrases nouvelles décidées

| # | Où | Texte à ajouter | Ligne |
|---|---|---|---|
| C-6 | « Facture électronique (SUPER PDP) », après C-2 | « Le code de date d'exigibilité de la TVA n'est envoyé que pour une société qui a opté pour les débits : sur ses factures électroniques qui contiennent un service (BT-8 = 3, sans note BT-22) et sur ses lignes TPS1 de l'e-reporting des ventes aux particuliers ; il est toujours envoyé dans l'e-reporting B2B international (3, 35 ou 432, ou 5, 29 et 72 en liste CII) et omis partout ailleurs. » | T3.1 à T3.4, T112.1 à T112.4 |
| C-7 | « Achats et stock » | « Un achat auprès d'un fournisseur établi à Monaco suit la TVA française, sans autoliquidation ; il se déclare dans l'e-reporting des achats, quel que soit le canal de la facture, sauf des biens déjà entreposés en France. » | T4.5, T113 |
| C-8 | « Facture électronique », phrase E7 | Remplacer « Les factures aux clients publics et aux professionnels non assujettis restent hors plateforme, avec un message. » par « Les factures aux clients publics et aux professionnels non assujettis restent hors plateforme, avec un message ; une vente à un professionnel non assujetti entre dans l'e-reporting du jour, comme une vente à un particulier, et le message d'une facture à un client public dit de la déposer sur Chorus Pro. » | T91.2 |
| C-9 | « Paiements et avoirs », après « Rien n'est jamais remboursé : un retour donne un crédit… » | « Le remboursement de la TVA au voyageur après le visa d'un bordereau de détaxe n'est pas un retour : c'est une opération de caisse à part, signée et chaînée, liée au ticket et au bordereau, comptée à part dans la clôture du jour (étape 3). » | T5.2 |
| C-10 | « Ventes hors de France » | « Sans preuve de sortie 150 jours après la vente, une vente exonérée se régularise par un avoir sans retour en stock et une facture à la TVA française au même prix TTC, sauf si le client accepte de payer la TVA en plus. » | T29.3 |
| C-11 | « Société et établissements » | « Une société membre d'un assujetti unique (groupe TVA, art. 256 C du CGI) le coche dans ses réglages fiscaux communs : PILIQI refuse alors toute opération commerciale réelle (ticket, facture, avoir, encaissement, devis numéroté), le magasin de démonstration restant utilisable ; la page d'inscription le dit. » | P3.1 |
| C-12 | « Documents de vente » et « Prix et TVA des ventes » (deux phrases sur « à moins de deux centimes près » et « jusqu'à 10 000 articles ») | Dire que la note de calcul n'imprime « à moins de deux centimes près » que si chaque ligne du document le vérifie, et sinon qu'un petit écart est possible (texte exact du § 10). | T105 |
| C-13 | Feuille de route, étape 5 | « l'état de TVA porte les ventes de la période sans en déduire les avoirs, et tous les avoirs clients en ligne B5 et en ligne 21 de la CA3 ; une prime d'assurance ou une opération bancaire exonérée achetée hors de France n'y figure sur aucune ligne ; la répartition d'un encaissement entre les taux reprend le calcul de l'e-reporting (T9.3) ; l'export comptable signale une société dont la clôture de caisse ne coïncide pas avec l'exercice déclaré (T28.2) » | T9.2, T6.1, T9.3, T28.2 |
| C-14 | Feuille de route, étape 15, après « Sauvegardes avec restauration à un instant donné » | « , sans perte d'un document validé, et procédure de reprise après restauration (journal des événements, relecture de SUPER PDP avant toute vente, séries reprises après le plus grand numéro remis, jamais un numéro redonné, documents perdus reconstitués sous leur numéro et signés, incident tracé et procès-verbal, procédure de l'éditeur testée) » | T76.1 à T76.3 |
| C-15 | Feuille de route, étape 15 | « audit de sécurité interne selon l'OWASP ASVS 5.0.0, niveau 2 (isolation des sociétés, des magasins et des clients d'un cabinet d'abord), sur une copie aux données fictives, rapport publié dans une page privée ; test d'intrusion extérieur si l'éditeur le commande ; la revue fiscale indépendante relit les décisions de l'onglet « À faire vérifier par un professionnel » sans qu'aucune n'en dépende » | T95.1, T38.1 |
| C-16 | Feuille de route, étape 2 | Remplacer « superpdp_directory_entry_identifier et superpdp_send_and_receive, absents de la spécification 1.36.0.beta, ne s'envoient qu'une fois confirmés par la documentation officielle » par « superpdp_directory_entry_identifier ne s'envoie pas : l'adresse SIREN_SIRET de chaque magasin se crée par POST /v1.beta/directory_entries (T87.1) » ; la partie sur superpdp_send_and_receive suit la décision de sa propre ligne de l'onglet. | T87.1 |
| C-17 | « Outillage », lot de mise à jour des paquets | Ajouter à chaque lot de mise à jour la relecture du Journal officiel (décret des mentions des factures par nature d'opération) et, pour décembre 2027, la nouvelle version datée du 01/01/2028 de la table des mentions (articles du CIBS relus sur Légifrance). | T40.1, T40.3 |

Après ces changements : `grep -nE "confirm(é|ée|er) par l'expert-comptable|faire confirmer|validée par l'expert-comptable|en attente de (la confirmation|l'avis)" CLAUDE.md` ne trouve plus rien.

## 2. Aperçu de l'e-reporting : encaissements des services (inventaire A ; T1.1 à T1.4, T1.6, T9.3, T107, T112.1)

### `app/Domain/Facturation/Services/EReportingDesParticuliers.php`

1. `MENTION_ENCAISSEMENTS` (l. 90) : retirer « En attente de la confirmation de l'expert-comptable de la société. » et « Point soumis à l'éditeur : … ». Nouveau texte, qui dit la règle appliquée : « Encaissements des prestations de services payées par des particuliers, déclarés par jour et par taux de TVA. Un crédit d'avoir compte à la date où il est utilisé, sauf la part qui annule les lignes que son avoir corrige sur ce ticket ; l'avoir d'une prestation déjà encaissée se déduit à la date où son crédit règle une autre vente. » (La phrase sur le crédit d'avoir garde la règle de l'éditeur, T1.6, tranchée autrement malgré l'avertissement : ne pas la changer.)
2. Docblock de la classe (l. 53 à 73) : réécrire sans « Tous attendent la confirmation de la règle par l'expert-comptable de la société » ni « point soumis à l'éditeur » : les encaissements se déclarent (T1.1), par jour et par taux (T1.2) ; un règlement partiel ou un reste dû se répartit au prorata (T1.3, T1.4, T107) ; plus de « reste à répartir ».
3. `encaissements()` (l. 356 à 421) :
   - Prorata : part d'un taux de service = paiement × TTC des services à ce taux ÷ TTC du document, calculé sur le TTC restant après les avoirs (T107.2) ; parts en cumul dans l'ordre des taux (part du cumul encaissé moins parts précédentes), chacune arrondie au centime le plus proche, un demi-centime au centime supérieur, la somme égalant le paiement (T107.1, T9.3) ; la part des biens ne se déclare jamais. Calcul en centimes entiers (règle « Prix et TVA des ventes »).
   - Un crédit d'avoir utilisé compte comme un paiement (T107.2) ; une contre-passation se déduit des mêmes parts, à sa date (T1.3).
   - Avoir d'une prestation déjà encaissée (inventaire A7, décision T1.3) : quand son crédit règle une autre vente, déduire à la date de cette utilisation la part de service annulée, au taux de la ligne corrigée ; un crédit jamais utilisé ne déduit rien.
   - Société qui a opté pour les débits (réglage fiscal commun) : aucun encaissement de services retenu (T112.1, inventaire A8).
   - Supprimer la branche `$aRepartir` et le second élément du tableau rendu ; `apercu()` (l. 100 à 110) ne construit plus `encaissementsARepartir`.
   - Garder le filtre `$groupe['taux'] > 0` (l. 380) : les paiements des services d'un magasin en franchise partent à l'étape 2, tous les deux mois (art. 242 nonies P ; T1.1, T65.5, inventaire A9) ; le noter dans le plan de l'étape 2.
4. `MENTION_A_REPARTIR` (l. 92) : supprimer.

### `app/Domain/Facturation/Services/ApercuEReporting.php`

- Supprimer la propriété `encaissementsARepartir` (l. 37), son `@param` (l. 30), la phrase du docblock « … dont la répartition attend la règle de l'expert-comptable de la société » (l. 15 à 20) et son terme dans `aDeclarer()` (l. 62).

### `app/Filament/App/Resources/EReportingResource.php`

- Bloc « Encaissements des prestations de services » (l. 150 à 155) : afficher la mention réécrite.
- Supprimer le bloc « Encaissements à répartir » (l. 168 à 187) et la ligne `'Encaissement à répartir le %s : %s'` de `lignesDeLApercu()` (l. 284 à 287).
- Retirer « (à confirmer) » de `'Prestations de services encaissées le %s à %s : %s (à confirmer)'` (l. 278).
- Docblocks (l. 105 à 110 et 258 à 262) réécrits sans attente.

### Tests (inventaire A, réécrits selon la règle, jamais affaiblis)

`tests/Feature/Facturation/EReportingDesParticuliersTest.php` :
- `test_l_encaissement_d_un_service_est_ventile_par_taux_et_marque_a_confirmer` (l. 329) : renommer `test_l_encaissement_d_un_service_est_ventile_par_taux` ; docblock sans « en attente de la confirmation de l'expert-comptable » ; asserter la nouvelle `MENTION_ENCAISSEMENTS` mot pour mot et les montants par taux.
- `test_l_avoir_d_une_prestation_deja_encaissee_ne_se_deduit_pas_de_ses_encaissements_en_attendant_l_editeur` (l. 379) : renommer `test_l_avoir_d_une_prestation_deja_encaissee_se_deduit_a_l_utilisation_de_son_credit` ; exemple de T1.3 : réparation 120,00 € à 20 % payée le 03/11 (+ 120,00 € ce jour), avoir le 05/11 (rien ce jour), crédit utilisé le 10/11 sur une autre réparation de 120,00 € à 20 % (+ 120,00 € et − 120,00 €, net 0,00 € le 10/11) ; total déclaré 120,00 € pour 120,00 € reçus ; second cas : crédit jamais utilisé, aucune déduction.
- `test_un_reglement_partiel_d_un_ticket_mixte_reste_a_repartir` (l. 496) : renommer `test_un_reglement_partiel_d_un_ticket_mixte_se_repartit_au_prorata` ; livre 10,55 € à 5,5 % + réparation 60,00 € à 20 %, 30,00 € reçus → part du service = 30,00 × 60,00 ÷ 70,55 = 25,51 € au taux de 20 % (arrondi T107.1), rien pour la livre.
- Assertions sur `encaissementsARepartir` (l. 229, 411, 432, 456, 487, 505, 542, 560) et docblock l. 530 : retirer le second membre de chaque tableau comparé, en gardant l'assertion exacte sur `encaissementsDeServices`.
- Nouveaux tests :
  - prorata sur plusieurs taux de services, somme exacte : ticket de 160,00 € (120,00 € à 20 %, 40,00 € à 10 %), acompte de 50,00 € → 37,50 € à 20 % et 12,50 € à 10 % ; un cas où l'arrondi tombe sur un demi-centime, la somme égale le paiement ;
  - deux paiements successifs (30,00 € puis 70,00 €) répartis en cumul (T9.3) ;
  - base après un avoir partiel (T107.2) ;
  - crédit d'avoir utilisé compté comme paiement ;
  - contre-passation d'un acompte réparti, déduite des mêmes parts à sa date ;
  - société qui a opté pour les débits : aucun encaissement de services dans l'aperçu (T112.1).

`tests/Feature/Filament/EReportingResourceTest.php` (l. 81) : `assertSee('Prestations de services encaissées le 07/10/2026 à 20 % : 60,00 €')` et `assertDontSee('(à confirmer)')` ; vérifier que le bloc « Encaissements à répartir » n'existe plus (`assertDontSee('Encaissements à répartir')`) sur une clôture qui porte un règlement partiel, et que la part répartie s'y affiche.

## 3. Aperçu de l'e-reporting : catégories des ventes sans TVA française (inventaire B ; T2.1, T2.2, T2.4, T2.5, T65.5, T108)

### `app/Domain/Facturation/Services/EReportingDesParticuliers.php`

- `transaction()` (l. 327 à 333) :
  - franchise en base (l. 331) : ranger par `CategorieEReporting::deLaNature` de la ligne (bien TLB1, service TPS1), au taux 0 ; `MOTIF_FRANCHISE` reste comme information, sans `MENTION_CATEGORIE_A_VERIFIER` (T2.5) ;
  - nature exonérée, export et livraison vers un DOM (l. 332) : TNT1 au taux 0 gardé, sans `MENTION_TRANSMISSION_EN_ATTENTE` (T2.1, T2.2, T2.4, T108.1, T108.2) ;
  - vente en France à 0 % hors franchise (l. 333) : même rangement que la franchise, par la nature de la ligne, au taux 0, `MOTIF_TAUX_ZERO` comme information, sans remarque (décision T2.5, qui tranche l'inventaire B4).
- `MENTION_CATEGORIE_A_VERIFIER` (l. 84) : supprimer. `MENTION_TRANSMISSION_EN_ATTENTE` (l. 82) : la garder seulement si une autre nature l'emploie encore (attente de l'étape 2, qui n'est pas un avis : inventaire F) ; sinon la supprimer.
- Docblock (l. 43 à 51) réécrit : franchise et vente à 0 % hors franchise rangées par nature ; export et DOM à un particulier en TNT1.

### `app/Domain/Facturation/Enums/CategorieEReporting.php`

- Docblock (l. 7 à 19) : une vente en franchise, ou à 0 % hors franchise, se range par sa nature, au taux 0.

### Tests (`tests/Feature/Facturation/EReportingDesParticuliersTest.php`)

- `test_une_vente_sans_tva_francaise_se_range_dans_les_operations_non_soumises_en_attente_de_son_motif` (l. 258) : renommer `…_operations_non_soumises_au_taux_zero` ; TNT1 au taux 0, aucune remarque (asserter la remarque nulle ou vide).
- `test_une_vente_en_franchise_en_base_se_range_dans_les_operations_non_soumises_categorie_a_verifier` (l. 282) : renommer `test_une_vente_en_franchise_en_base_se_range_par_la_nature_de_sa_ligne` ; un bien en TLB1 et un service en TPS1, au taux 0, motif franchise en information, aucune remarque.
- `test_une_vente_en_france_a_zero_pour_cent_se_range_dans_les_operations_non_soumises_categorie_a_verifier` (l. 306) : renommer `test_une_vente_en_france_a_zero_pour_cent_se_range_par_la_nature_de_sa_ligne` ; mêmes assertions.
- `tests/Fixtures/empreinte-de-la-caisse/apercu-e-reporting.txt` : régénérer si l'aperçu change (§ 2 et § 3), en le justifiant dans le message du commit, jamais pour masquer un écart non voulu.

## 4. Régularisation : texte d'attente (inventaire C1, C2 ; T27.1 à T27.5)

- `app/Domain/Facturation/Services/RegularisationDUneFacture.php` (docblock, l. 19 à 24) : remplacer « règle à faire confirmer par l'expert-comptable de la société le jour où le cas se présente » par « règle de l'onglet « À faire vérifier par un professionnel » (T27.1 à T27.5, T68.2), appliquée ; une réponse d'un professionnel la remplacerait ».
- CLAUDE.md : C-4 (§ 1.2).
- La règle de l'éditeur (Q6 = a) reste ; le risque SUPER PDP (ancien SIREN refusé, règle G1.45) est écrit dans la cellule de Claude de T27.1, sans changer la règle.
- Aucun test ne vérifie cette phrase (grep à `f159294`) : rien à réécrire.
- Le reste des décisions de la régularisation (T27.2, T68.1, T68.3) forme un lot voisin : § 12.

## 5. Changement légal de la fin d'exercice (inventaire D ; T28.1, T28.2)

- `app/Domain/Tenancy/Actions/ChangerFinExerciceAction.php` :
  - supprimer `MESSAGE_DUREE_NON_VALIDEE` (l. 72) et son refus (l. 129 à 133) ;
  - `$dureeValideePar` devient `?string` facultatif, documenté « expert-comptable consulté, s'il y en a un » (150 caractères au plus quand il est donné) ;
  - nouveau paramètre obligatoire : l'organe de la décision, énumération nouvelle `OrganeDeLaDecision` (assemblée générale extraordinaire, décision des associés, organe prévu par les statuts, associé unique, entrepreneur individuel), avec un message de refus en constante ;
  - référence du dépôt au registre facultative ;
  - tout est écrit dans `changements_fin_exercice` et au journal d'audit (l. 156, 169) ;
  - `MESSAGE_FIN_DE_MOIS` (l. 66) et `MESSAGE_CLOTURE_REPOUSSEE` (l. 70) complétés par « PILIQI n'accepte qu'un exercice raccourci, terminé le dernier jour d'un mois. » (T28.2).
- `app/Console/Commands/Editeur/ChangerLaFinDExercice.php` : option `--organe=` obligatoire ; `--duree-validee-par=` facultative (« Expert-comptable consulté, facultatif ») ; docblock (l. 13 à 22) et résumé (l. 41 à 55) sans « Durée validée par … la procédure le refusera » ; le résumé affiche l'organe et, s'il y en a un, le nom.
- `database/migrations/2026_10_02_100016_create_changements_fin_exercice_table.php` : `duree_validee_par` (l. 36) rendue `nullable()` ; colonne `organe_de_la_decision` ; référence du registre nullable ; docblock (l. 16 et 17) réécrit ; puis `migrate:fresh`.
- `app/Domain/Tenancy/Models/ChangementFinExercice.php` : docblock (« l'organe qui a décidé et, s'il y a lieu, l'expert-comptable consulté ») et casts.
- `app/Domain/Tenancy/Enums/ActionAuditee.php` (l. 352 à 357) : champs `['mois_fin_exercice', 'fin_exercice_en_cours', 'decision_du', 'organe_de_la_decision', 'duree_validee_par']`, commentaire réécrit.
- CLAUDE.md : C-5 (§ 1.2).
- Tests :
  - `tests/Feature/Editeur/ChangerFinExerciceTest.php` : docblock (l. 35) sans « validée par l'expert-comptable » ; constante `EXPERT_COMPTABLE` (l. 49) gardée comme nom facultatif ; appels (l. 82, 240, 319) avec l'organe ; `test_la_duree_doit_etre_validee_par_l_expert_comptable` (l. 273) réécrit en deux tests : `test_un_changement_sans_expert_comptable_consulte_est_accepte` (changement enregistré, colonne nulle, audit sans nom) et `test_un_changement_sans_organe_de_la_decision_est_refuse` (message exact, rien d'écrit) ; tests des deux refus T28.2 sur le nouveau texte exact ; lecture de la ligne enregistrée (l. 101 et 102) avec l'organe.
  - `tests/Feature/CaisseSecurisee/ProtectionDesTablesTest.php` (l. 171) : insertion avec l'organe (`duree_validee_par` peut rester nulle).

## 6. Registre national des entreprises (T99)

- `app/Integrations/Rne/InpiRne.php`, `identite()` : remplacer la lecture de `entreprise.greffeImmatriculation` (l. 122 et suivantes) par `registre` et `lieuRegistre` du même bloc : immatriculation « {registre} {lieuRegistre} » si les deux sont remplis, `null` sinon (le propriétaire la complète) ; docblocks des l. 28, 60 et 99 à 104 sans « à confirmer » (renvoi à la ligne T99 de l'onglet).
- `tests/Feature/Integrations/InpiRneTest.php` (l. 123) : la réponse fictive porte `registre` « RCS » et `lieuRegistre` « Lyon » → « RCS Lyon » ; nouveau test où `lieuRegistre` manque → immatriculation nulle.

## 7. Société membre d'un assujetti unique (P3.1)

- Migration de création de la table des sociétés : colonne booléenne `membre_assujetti_unique` (fausse par défaut), puis `migrate:fresh`.
- Réglages fiscaux communs de la société (page de l'espace d'administration) : la case « La société est membre d'un assujetti unique (groupe TVA, art. 256 C du CGI) » ; chaque changement s'inscrit au journal d'audit et au journal des événements de chaque magasin, comme tout réglage qui touche la conformité.
- Refus, case cochée, avec le message en constante « PILIQI ne gère pas encore les sociétés membres d'un assujetti unique (groupe TVA) : aucune vente, facture, avoir, encaissement ni devis numéroté réel n'est possible. », dans les actions d'émission d'un ticket, d'une facture, d'un avoir, d'encaissement et de numérotation d'un devis des magasins réels ; le magasin de démonstration reste utilisable.
- Page d'inscription : une phrase d'information, sans champ.
- CLAUDE.md : C-11.
- Tests : chaque refus case cochée ; émission permise case décochée ; démonstration permise ; journal d'audit et journal des événements du changement.

## 8. Exonération non prouvée (T29.3)

- Nouvelle action `RegulariserUneExonerationNonProuveeAction`, sur le modèle de `FaireLAvoirDeRegularisationAction` : avoir de la facture d'origine (nature export ou livraison vers un DOM) sans mouvement de stock, mention « Avoir de régularisation : preuve de sortie non reçue pour la facture n° X », puis facture `VenteEnFrance` qui reprend les lignes au même TTC (HT et TVA calculés depuis ce TTC selon la règle des ventes), le crédit de l'avoir imputé sur elle, dans une même transaction signée, avec jeton de demande et journal d'audit ; geste d'un compte autorisé à vendre (jamais le propriétaire hors démonstration).
- « À faire » : entrée « Exonération non prouvée » 150 jours après la vente sans preuve (le rappel du 31e jour reste).
- CLAUDE.md : C-10.
- Tests : export de 500,00 € sans preuve au 150e jour → avoir de 500,00 € sans retour en stock, facture de 500,00 € TTC à 20 % (416,67 € HT, 83,33 € de TVA) ; stock inchangé ; refus si une preuve est enregistrée ; refus au propriétaire hors démonstration ; demande répétée sans doublon.

## 9. Phrases d'information décidées

- Liste et formulaire « Avoirs fournisseur » : « Un rabais ou une ristourne sans retour de marchandise ne se saisit pas encore dans PILIQI : la TVA de la note d'avoir se reverse sur la CA3 (ligne 15). » Test Filament : la phrase s'affiche (T71.2).
- Formulaires de devis et de facture : « Un acompte reçu avant la facture ne s'enregistre pas encore dans PILIQI. » Test Filament (T50.1). Plan de l'étape 2 : document d'acompte (T50.1, T50.2).

## 10. Note de calcul (T105, décision de l'éditeur du 12/11/2026 : (a))

- `app/Domain/Facturation/Services/MentionsFiscales.php`, constante `PU_HT_TEL_QU_ENREGISTRE` : imprimée seulement si, pour chaque ligne du document, |quantité × PU HT, remise déduite − total HT de la ligne| < 0,02 € ; sinon la phrase nouvelle : « Les prix unitaires HT sont affichés tels qu'ils sont enregistrés et les totaux calculés sans arrondi intermédiaire : la quantité × le PU HT, remise déduite, peut s'écarter de quelques centimes du total HT de la ligne. »
- Docblocks de `MentionsFiscales` et de `CalculateurLigne` qui décrivent cette phrase.
- CLAUDE.md : C-12.
- Tests : facture de 100 000 articles à 1,00 € (seconde phrase) ; facture ordinaire (première phrase) ; dernier avoir d'une ligne rendue en trois fois ; empreinte des PDF régénérée si elle change, justifiée dans le commit.

## 11. Diagnostic qpdf sous Windows (constat de l'éditeur du 12/11/2026)

- `app/Console/Commands/DiagnostiquerCaisseSecurisee.php`, `controlerQpdf()` (l. 148) : retirer les fins de ligne de la première ligne lue (découpe sur `\R`, puis `trim`), au lieu de `strtok(…, "\n")` ; même correction partout où une sortie de programme est lue (`AnalyseurDePdf::versionAdmise`, antivirus).
- Test : sortie simulée « qpdf version 12.2.0\r\n… » → détail sans « \r ».
- Onglet « À faire de votre côté » : ligne « rien à faire ».

## 12. Lots voisins de l'étape 1, après ER-PRO (mêmes décisions, hors de ce commit)

Chacun a son propre commit, après ER-PRO, avant la campagne 1 bis :

- Régularisation (T27.2, T68.1, T68.3) : rectificative émise seulement après l'avoir de régularisation (refus en constante, crédit imputé dans la même transaction signée), suppression de `StatutFacture::EnAttenteDeRegularisation` et de ses branches (`ImputationDuCredit`, `AppliquerAvoirsDisponiblesAction`, `EnregistrerPaiementAction`, `EmettreFactureAction`, `FaireLAvoirDeRegularisationAction`, `FactureResource`), contre-passation d'un paiement d'une facture régularisée (crédit reporté, dû rouvert sur la rectificative, jamais de relance d'une facture régularisée), date de la livraison d'origine imprimée sur la rectificative ; `RegularisationDUneFactureTest` (l. 618, 627, 656) et `RegularisationDUneFactureConcurrenceTest` (l. 167) réécrits selon la règle (refus avant l'avoir ; 600,00 € payés → 0,00 € dû ; 200,00 € payés → 400,00 € dus ; contre-passations de 500,00 € et de 200,00 €, et deux simultanées sur PostgreSQL) ; CLAUDE.md, C-4 complété par « , qui ne s'émet qu'après lui ».
- Date de l'opération (T49) : colonnes `date_operation_debut` et `date_operation_fin` dans la migration de création des factures, saisie, reprise pour une facture qui remplace un ticket et pour une rectificative, mention « Date de la livraison ou de la prestation : … » ; contenu signé et empreinte des PDF ; CLAUDE.md, « Documents de vente ».
- Devis (T51.6, T86) : réglage « Dépannage, réparation ou entretien à domicile » du magasin et mentions du devis ; information sur la rétractation de 14 jours et case « commencer avant la fin du délai » sur la page de réponse d'un particulier.
- Mots de passe courants (T36.4) : liste rangée dans le dépôt (source et licence au README), refus partout où la règle s'applique, changement obligatoire à la connexion, identifiant jamais tiré dans la liste ; CLAUDE.md, « Comptes et équipe ».

Déjà rangés dans la chaîne TVA (ne pas les refaire ici) : lots M4 (T8.8), M5a (T19), M5c (T6.3, T8.2, T13.1, T20.x, T21.x, P41.3), M5e (T22.3, T23.x), M6 (T22.2), M7 (T8.1, T21.x, T24.x, P41.3), CH2 (T14, T20.x : plus aucun indicateur « à confirmer » ni message « à voir avec votre expert-comptable » sur les règles de récupération), CH5 (T59.1), C-b (T58.1 à T58.4), BTP (T51.1 à T51.3, T51.7), marge (T60.1 à T60.7).

## 13. Critères de fin du lot

- `grep -rnE "confirmation de l'expert-comptable|confirmée par l'expert-comptable|faire confirmer|validée par l'expert-comptable|Point soumis à l'éditeur|\(à confirmer\)|attend la règle|MENTION_A_REPARTIR|MENTION_CATEGORIE_A_VERIFIER|encaissementsARepartir|MESSAGE_DUREE_NON_VALIDEE|greffeImmatriculation" app database resources tests CLAUDE.md` : plus aucun résultat (sauf `EnAttenteDeRegularisation`, retiré par le lot de la régularisation, § 12).
- Les écartés de l'inventaire F restent tels quels (confirmation d'adresse par lien, contrôle Sirene, « TVA à vérifier » des achats, vérification à l'Ordre, points techniques et contractuels, attentes de l'éditeur Q6 et Q-AUD1).
- Tests ciblés verts, suite complète sur PostgreSQL à code de sortie 0 juste avant le commit, pint, un commit ; deux relectures en parallèle, puis le commit de leur correction.
- Après l'envoi : relire à chaque point de suivi la colonne « Réponse du professionnel » ; toute réponse nouvelle, même après la mise en production, donne un lot qui l'applique.
