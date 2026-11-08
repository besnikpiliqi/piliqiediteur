# Lecture des audits remis le 7 novembre 2026

Rapport pour l'éditeur de PILIQI, 8 novembre 2026.

## En bref

- Le 7 novembre à 23 h 40 (heure UTC), vous avez remis trois archives : les corrections d'une autre session de Claude et les rapports d'audit (`corrections`), les journaux des campagnes lancées sur votre PC (`cabinets`) et ceux de l'audit de sécurité PostgreSQL 17 (`audit-pg17`), avec ce message : « il faut que tu lise tout ces que te donne. c'est tres important pour logiciel ».
- Tout a été examiné : les rapports et presque tous les fichiers de code lus en entier ; les très gros journaux (jusqu'à 7 Mo) traités par des programmes de tri, puis lus aux passages utiles. Ce qui n'a pas été lu en entier est dit au § 1. Aucun fichier des archives n'a été exécuté.
- Les remarques ont été regroupées en 112 constats, et chacun a été vérifié sur la dernière version poussée du logiciel (`c7b1ec3`, 7 novembre).
- Résultat : **45 constats sont encore vrais** (1 bloquant, 27 importants, 17 mineurs) ; **1 est déjà corrigé** (11 failles connues de paquets Symfony, corrigées le 8 novembre) ; **52 ne concernent pas le code du logiciel** (défauts des outils de test des auditeurs, ou des corrections de l'autre session, qui ne sont pas dans le logiciel) ; **4 demandent votre décision** ; **3 restent incertains** ; et 7 groupes de résultats confirment que des parties importantes fonctionnent.
- **Le plus grave** : un ticket Z (la clôture du jour, document fiscal) peut être modifié ou supprimé par une écriture directe dans la base de données, et la vérification de chaque nuit ne le verrait pas. Viennent ensuite : la base ne garantit pas qu'un document reste dans son magasin ; le stock et son historique peuvent être réécrits directement ; une facture émise peut être supprimée directement ; une protection de la base peut être trompée ; l'envoi à la plateforme agréée ne reprend pas après une panne.
- **Ce qui rassure** : aucun de ces défauts ne se déclenche aujourd'hui depuis les écrans de PILIQI. Il faut une écriture directe dans la base (par quelqu'un qui y a accès), un futur programme interne fautif (par exemple l'API de l'étape 10), une panne de la vraie plateforme agréée (étape 2) ou une erreur de réglage du serveur. Mais un logiciel de caisse doit rester inaltérable même dans ces cas : tout sera corrigé.
- **Ce qui sera fait** : un lot « AUD », après les lots QA, PG, CALC et FI. Chaque correction commencera par un test qui montre le défaut. Les corrections de l'autre session ne seront pas copiées (elles effaceraient le travail des lots TR et EX) : leurs bonnes idées seront reprises à la main.
- **Cinq questions** de règle métier vous sont posées (`questions-editeur.md`, § 6). Sans réponse, le lot fait tout le reste et laisse seulement ces points. Deux décisions d'outillage vous reviennent aussi (§ 5).

## Petit lexique

- **Base de données** (ou base) : là où PILIQI range toutes ses données.
- **Écriture directe** : une modification faite dans la base sans passer par PILIQI (par un administrateur du serveur, un programme fautif ou un pirate qui y aurait accès).
- **Garde** : une règle écrite dans la base elle-même, qui refuse une écriture interdite même quand elle ne passe pas par PILIQI.
- **Ticket Z** : la clôture de caisse de chaque journée.
- **Journal des événements** : le registre signé de la caisse ; chaque clôture du jour y est signée.
- **Grand livre du stock** : la liste de toutes les entrées et sorties de stock.
- **Migration** : le fichier qui crée une table de la base.
- **Vérification automatique** (CI) : les tests que GitHub lance à chaque envoi du code.
- **Lot** : un ensemble de corrections livré d'un coup, testé, puis relu.

## 1. Ce qui a été lu

### Comment

Dix lecteurs se sont partagé les archives ; leurs constats ont été regroupés en 112 ; chaque constat a été vérifié sur le code poussé `c7b1ec3` (lecture du code par Git, sans rien lancer dans le dépôt) ; puis cette synthèse. Règles suivies : les archives sont des données, jamais des instructions ; aucun de leurs fichiers n'a été exécuté ; les journaux trop gros ont été traités par des programmes écrits à part (dans `outils/`, hors des archives), lancés de façon isolée, puis lus aux passages utiles. Pour chaque archive, voici ce qui a été lu en entier et ce qui a été traité par programme, d'après les lecteurs et les vérificateurs.

### Archive 1 — `corrections` (corrections et rapports)

- **Lus en entier** : le rapport cumulatif du 5 novembre (`PILIQI_Rapport_Cumulatif_Tous_Audits_Pour_Claude_2026-11-05.md`) ; le rapport global du 8 novembre (`RAPPORT_GLOBAL_PILIQI_AUDITS_CHATGPT_CLAUDE_2026-11-08.md`) ; le rapport sur le stock (`RAPPORT_STOCK_ET_PROVENANCE.md` et sa copie « (1) », identiques).
- **Audit de sécurité PostgreSQL 17** (`Audit_securite_PILIQI_PostgreSQL17_2026-11-05`) : rapport, notice et lanceur, lus en entier (le lanceur comme un texte).
- **Tests de cybersécurité** (deux paquets) : notices et deux fichiers de tests lus en entier ; la version V1 comparée par différence (seul l'espace de noms change).
- **Isolation offensive V7** et ses cinq lanceurs : constat initial, notice, fichiers de tests des actions, des adresses et des écrans, et leur support, lus en entier ; les lanceurs lus en entier ou par différence.
- **Audit ChatGPT V1 et V2** : notices, rapport préliminaire, plan, tous les fichiers de tests et les lanceurs lus en entier (le second `lancer.php` par différence avec le premier).
- **Bancs de charge V1, V2, V3** : tous les fichiers de la V1 et de la V3 lus en entier ; la V2 par différence (seuls les chemins changent). Les fichiers ZIP de ces bancs ont été comparés à leurs dossiers : identiques.
- **Corrections du 6 novembre** : rapport et notice lus en entier ; tous les petits journaux lus en entier ; les journaux des suites complètes traités par programme ; les 25 fichiers modifiés comparés par programme aux versions `1d3278a` et `c7b1ec3`, puis lus par blocs.
- **Intégrité SQL des 6 et 7 novembre** : rapports, notices et récapitulatifs des suites lus en entier ; le journal des attaques sur le code d'origine lu en entier et compté par programme ; les journaux « après » traités par programme puis lus aux passages utiles ; le correctif du 7 novembre traité par programme (61 fichiers) ; plusieurs fichiers de tests lus en entier, d'autres par blocs.
- **Provenance du stock du 7 novembre** : notice et rapport lus en entier ; les fichiers clés (garde du stock, provenance, types de mouvements, grand livre, migrations, fabrique des produits, tests) lus en entier ; tous les petits journaux lus en entier ; le journal des 155 attaques traité par programme puis lu aux passages utiles ; le correctif comparé par programme (95 fichiers).

### Archive 2 — `cabinets` (journaux de vos campagnes)

- **Lus en entier** : la notice et le plan ChatGPT V2, les deux lanceurs (comme des textes), le README (copie du README du logiciel à la version `1d3278a`), les journaux de la concurrence, d'IndependentAudit et de la V6 (après conversion d'encodage).
- **Traités par programme** (convertis de l'encodage Windows, puis comptés et fouillés, et lus aux passages utiles) : le journal V5 (7 Mo, 18 033 lignes), celui d'AuditMassif (5 Mo, 19 497 lignes, conversion vérifiée ligne à ligne), celui des parcours (lu au début et à la fin ; le milieu répète 24 fois le même échec), celui des portées V5, et les journaux de facturation, des cabinets, de la société et du module fiscal.

### Archive 3 — `audit-pg17` (cinq lancements de l'audit PostgreSQL 17)

- **Lus en entier** : les récapitulatifs et tableaux de résultats ; les fichiers de version, d'audit des paquets (identiques entre les deux lancements du 5 novembre) ; les journaux de la plateforme agréée ; le journal de cybersécurité (après retrait des codes de couleur) ; les trois récapitulatifs du 6 novembre (aucun test lancé : dossier de tests introuvable).
- **Traités par programme** puis lus aux passages utiles : les autres journaux de tests (isolation V7, droits, caisse sécurisée, etc.).

### Ce qui n'a pas été lu en entier, et ce qui manque

- **Pas lus en entier** : la partie « fichier des paquets » des correctifs (traitée par programme) ; quelques gros fichiers des corrections, lus seulement par blocs de différences ; le correctif d'intégrité du 6 novembre (remplacé par celui du 7) ; les deux très gros journaux de `cabinets` (passages et échantillons) ; le journal des portées V5 et celui de l'isolation V7 (programme et passages).
- **Pas livrés** : les sources des suites V4, V5, V6 et AuditLaravelReel ; les journaux des passages ChatGPT V1 et V2 ; `tests.zip`, `migrations.zip` et les captures d'écran citées.
- **Contrôle refait** : l'audit des failles des paquets a été rejoué sur des copies : 11 avis sur la version `c7b1ec3`, 0 sur `0d37396`.

### Ce que cette synthèse a vérifié elle-même

J'ai lu en entier la liste des 112 constats regroupés et les 105 vérifications, les cahiers des lots QA, PG et FI, qui passent avant AUD, la place du lot AUD dans la chaîne et vos décisions récentes (D52 à D55) ; j'ai parcouru le cahier du lot CALC. Dans le logiciel poussé, j'ai relu les passages utiles de `CLAUDE.md` (protocole, outillage, étapes 1 bis et 15), les aides de test que le lot réutilisera, l'outil des colonnes figées, la liste des migrations, les colonnes des factures et les écritures en masse de l'application. Je n'ai rouvert aucun fichier des archives (seulement listé leurs dossiers) ; le message d'origine a été relu dans l'historique de vos messages.

## 2. Les constats, un par un

« Gravité réelle » est celle que la vérification a établie sur le logiciel poussé, pas celle qu'annonçaient les auditeurs.

### 2.1 Encore présents — bloquant (1)

| N° | Ce qui a été trouvé | Gravité | Ce qui sera fait |
|---|---|---|---|
| C24 | Le ticket Z peut être modifié ou supprimé directement dans la base, et la vérification de chaque nuit ne regarde pas les tickets Z. | bloquante | Lot AUD : la base refusera toute modification ou suppression ; la clôture s'écrira en une seule fois ; la vérification contrôlera chaque ticket Z avec sa signature. |

### 2.2 Encore présents — importants (27)

| N° | Ce qui a été trouvé | Gravité | Ce qui sera fait |
|---|---|---|---|
| C01 | Quatre actions internes (créer ou modifier une facture, créer ou modifier un devis) acceptent le client d'un autre magasin si un programme le leur passe directement ; les écrans, eux, l'empêchent. | importante | Lot AUD : l'action relit le client dans le magasin avant d'écrire. |
| C02 | Même défaut pour le fournisseur d'une facture d'achat. | importante | Lot AUD, de même. |
| C05 | La base accepte qu'une facture, un devis, un avoir ou un paiement désigne le client d'un autre magasin, même d'une autre société. | importante | Lot AUD : la base exigera le même magasin. |
| C06 | La base accepte qu'un mouvement de stock désigne le produit d'un autre magasin. | importante | Lot AUD, de même. |
| C07 | Au total, 45 liens entre tables d'un magasin ne sont pas gardés au même magasin par la base. | importante | Lot AUD : les 45. |
| C08 | Le grand livre du stock se modifie, se supprime et se vide directement. | importante | Lot AUD : on ne pourra plus qu'y ajouter, comme pour les paiements. |
| C10 | Le stock d'un produit se modifie directement, sans entrée ni sortie. | importante | Lot AUD : le stock sera tenu par la base, égal à la somme de ses mouvements. |
| C11 | Un produit peut être créé directement avec du stock, sans achat ni inventaire. | importante | Lot AUD : un produit naît toujours à zéro. |
| C12 | La base accepte des quantités nulles ou négatives, des coûts négatifs et des types de mouvement inconnus. | importante | Lot AUD : contrôles dans la base. |
| C13 | Un mouvement de stock peut citer un document inventé ou d'un autre magasin. | importante | Lot AUD : chaque mouvement devra désigner le vrai document qui le justifie. |
| C14 | Dans la correction de l'autre session, un ajustement rattaché à une facture d'achat échappe à toute limite (dans le logiciel, rien n'en limite aucun). | importante | Lot AUD : un tel ajustement devra égaler exactement la correction de la facture. |
| C15 | De même, une sortie pour cession y est acceptée pour un magasin jamais cédé. | importante | Lot AUD : seulement pour un magasin réellement cédé. |
| C23 | Les protections écrites dans la base peuvent être trompées par une table temporaire du même nom. | importante | Lot AUD : chaque protection ne lira que les vraies tables ; le droit de créer des tables temporaires sera retiré en production (étape 15). |
| C25 | Le lien d'un ticket ou d'un avoir à son ticket Z peut être changé ou effacé. | importante | Lot AUD : figé une fois posé, et seulement vers le ticket Z qui vient d'être fait. |
| C26 | Un faux ticket Z inséré directement est accepté et n'est pas détecté. | importante | Lot AUD : refusé par la base sans sa signature, détecté chaque nuit ; ce qu'on fait d'un faux ticket Z : question Q-AUD2. |
| C27 | Un faux ticket Z désorganise les clôtures (journées sautées, encaissements comptés deux fois). | importante | Lot AUD : l'état des clôtures se lira au registre signé ; la clôture s'arrêtera et alertera. |
| C28 | La signature d'un ticket Z ne couvre pas tout son contenu. | importante | Lot AUD : tout le contenu signé et vérifié. |
| C31 | Une facture, un ticket ou un avoir émis peut être supprimé ou réécrit directement. | importante | Lot AUD : la base le refusera ; seul un brouillon se supprime. |
| C32 | La base n'a pas encore deux comptes séparés (celui qui crée les tables et celui de l'application) : qui détient le premier peut lever toute protection. | importante | Étape 15 (déjà prévue), avec des précisions proposées (§ 5). |
| C33 | Un envoi à la plateforme agréée coupé par une panne réseau n'est jamais repris et s'affiche « Rejetée (format invalide) ». | importante | Lot AUD : reprise automatique sans double envoi, état « non transmise : incident technique » ; qui est prévenu : Q-AUD3. |
| C35 | Les branchements réels (plateforme agréée), les sauvegardes et les clés de production ne sont pas encore couverts. | importante | Étapes 2, 3 et 15. |
| C40 | La vérification automatique ne contrôle pas les failles connues des paquets. | importante | Lot QA (en cours) ; le lot AUD vérifie que c'est en place. |
| C48 | Copier les fichiers corrigés de l'autre session effacerait le travail des lots TR et EX. | importante | Lot AUD : ses bonnes idées reprises à la main, bloc par bloc (§ 4). |
| C76 | Aucune mesure de charge réelle n'a été faite : les performances ne sont pas démontrées. | importante | Campagne de tests 1 bis. |
| C101 | L'audit « V3 » proposé (stock et argent sous concurrence, pannes, fraude ciblée) n'a jamais été fait. | importante | Lot AUD : trois tests de concurrence qui manquent ; le reste à la campagne 1 bis. |
| C102 | L'outil de la campagne 1 bis reste à écrire. | importante | Campagne 1 bis. |
| C103 | Il reste des tests de sécurité à faire (test d'intrusion, PDF, en-têtes, fichiers). | importante | Lots QA (en-têtes) et FI (PDF, fichiers), petits réglages dans le lot AUD ; test d'intrusion : votre décision (§ 5). |

### 2.3 Encore présents — mineurs (17)

| N° | Ce qui a été trouvé | Gravité | Ce qui sera fait |
|---|---|---|---|
| C03 | La caisse, le paiement et l'avoir vérifient le client sur une copie en mémoire, pas dans la base. | mineure | Lot AUD. |
| C04 | Deux autres voies (avoir, reçu de paiement) se fient à leur appelant. | mineure | Lot AUD. |
| C09 | Supprimer un magasin effacerait en cascade clôtures, stock et achats (une autre protection l'empêche aujourd'hui). | mineure | Lot AUD : plus aucune cascade. |
| C16 | Correction externe : limites incomplètes des mouvements de stock (réception après correction, retours). | mineure | Lot AUD, avec C13. |
| C17 | Aucun contrôle de nuit ne compare le stock à ses mouvements, ni les achats au journal d'audit. | mineure | Lot AUD ; qui prévenir : Q-AUD1. |
| C18 | L'auteur d'un ajustement de stock peut être falsifié dans la base. | mineure | Lot AUD : aucun ajustement sans document ; règles de l'étape 7 : Q-AUD4. |
| C19 | Les lignes d'un achat validé peuvent être réécrites sans corriger le stock. | mineure | Lot AUD. |
| C20 | L'auteur et l'heure d'un mouvement de stock ne sont pas garantis par la base. | mineure | Lot AUD, avec C13. |
| C22 | Le coût moyen et le fournisseur d'un produit peuvent être réécrits directement. | mineure | Lot AUD : contrôlés chaque nuit (choix de Claude, § 5). |
| C30 | Un relevé de caisse personnelle peut être modifié ou supprimé directement. | mineure | Lot AUD. |
| C34 | La plateforme agréée « factice » (pour les tests) est branchée sans condition, même en production. | mineure | Lot AUD : refusée en production. |
| C39 | Le réglage des serveurs intermédiaires de confiance accepte des raccourcis dangereux. | mineure | Lot AUD. |
| C43 | Un test échoue sous Windows à cause des fins de ligne. | mineure | Lot AUD. |
| C45 | Un test en navigateur dépend de la langue des noms tirés au hasard. | mineure | Lot AUD. |
| C62 | Un test du logiciel prend pour attaquant un compte qui ne peut de toute façon pas vendre : il prouvait moins qu'il ne semblait. | mineure | Lot AUD. |
| C92 | Les tâches automatiques de chaque heure peuvent se chevaucher, et n'ont jamais été mesurées en volume. | mineure | Lot AUD (chevauchement) ; volume à la campagne 1 bis. |
| C100 | Les tests sautés ne sont ni publiés ni bornés. | mineure | Lot PG (plus de SQLite) et lot AUD. |

### 2.4 À trancher (4)

| N° | Ce qui a été trouvé | Gravité | Ce qui sera fait |
|---|---|---|---|
| C93 | Avec plusieurs serveurs, quelle horloge fait foi pour l'heure signée des ventes ? | mineure | Question Q-AUD5 ; le lot AUD ajoute dès maintenant un contrôle de l'heure. |
| C98 | Qui peut ajuster le stock à la main, et comment ? | mineure | Question Q-AUD4 (pour l'étape 7). |
| C104 | Faire relire par un tiers indépendant la conformité fiscale et la facture électronique ? | importante | Votre décision (§ 5) ; essai réel dans le bac à sable de SUPER PDP à l'étape 2. |
| C105 | Règles pour lire les résultats de la campagne (version testée, tests sautés, niveaux de preuve). | mineure | Textes proposés pour `CLAUDE.md` (§ 5). |

### 2.5 Incertains (3)

| N° | Ce qui a été trouvé | Gravité | Ce qui sera fait |
|---|---|---|---|
| C37 | Filament (l'outil des écrans) 3.3.55 : faille possible sur le nom du magasin, non confirmée, sans doute fermée par notre réglage actuel. | mineure | Lot AUD : un test en navigateur ; mise à jour à part si la faille se confirme. |
| C42 | Un test de concurrence a dépassé 60 s sur votre PC (lenteur probable de la machine). | mineure | Lot AUD : meilleur diagnostic de ces tests. |
| C75 | Deux anciennes captures d'échec en navigateur, sans le nom du test. | mineure | Lot AUD : captures nommées. |

### 2.6 Déjà corrigé (1)

| N° | Ce qui a été trouvé | Gravité | Ce qui a été fait |
|---|---|---|---|
| C36 | 11 failles connues dans six paquets Symfony. | mineure | Corrigé le 8 novembre (`0d37396`) ; contrôle rejoué : plus aucune faille connue. |

### 2.7 Sans objet pour le logiciel (52)

Le défaut est dans un outil de test des auditeurs ou dans une correction externe qui n'est pas dans le logiciel. Rien à corriger dans le code ; la leçon sert souvent à la reprise ou à la campagne.

| N° | Ce qui a été trouvé | Gravité | Suite |
|---|---|---|---|
| C21 | Provenance des anciens mouvements d'une base « déjà déployée ». | aucune | Rien : aucune base n'est déployée. |
| C29 | Correction externe : la vérification des tickets Z ferait une requête par ticket. | aucune | Le lot AUD l'écrira sans ce défaut. |
| C38 | Laravel 13.34 : faille d'une fonction que PILIQI n'utilise pas. | aucune | Prochain lot de mise à jour des paquets. |
| C41 | Les corrections externes changeaient 26 paquets sans accord. | mineure | Leur fichier des paquets n'est pas repris. |
| C44 | Une règle de nos tests (heures avec fuseau) échoue à cause des paquets d'audit déposés dans le dossier des tests. | aucune | La règle fait son travail ; ces paquets restent hors de ce dossier. |
| C46 | Aucune correction externe livrée avec toute la suite verte. | aucune | Rien : elles ne sont pas dans le logiciel. |
| C47 | Résultats d'une correction externe obtenus sur PostgreSQL 16. | aucune | Rien. |
| C49 | Tests externes à adapter (date de facture obligatoire, nouvelles tables). | aucune | Suivi à la reprise. |
| C50 | Preuves « avant correction » absentes pour certains tests externes. | aucune | Le lot AUD refait chaque preuve. |
| C51 | Journaux des corrections externes incomplets ou incohérents. | aucune | Rien. |
| C52 | Liste des tables protégées dans un test : divergences. | aucune | Mise à jour par le lot AUD. |
| C53 | Migrations « de mise à niveau » proposées, contraires à vos règles. | aucune | Non reprises. |
| C54 | Place d'une protection dans les migrations. | aucune | Convention déjà en place. |
| C55 | Lanceur des attaques SQL : bien protégé, mais quelques défauts. | aucune | Ses idées serviront à l'étape 15. |
| C56 | Isolation V7 : 88 « échecs » qui n'étaient que des refus de démarrer. | aucune | Les bons scénarios sont repris. |
| C57 | Lanceurs V7 « base neuve » : la base n'est jamais préparée. | aucune | Rien. |
| C58 | Lanceur V7 « base existante » : ne vérifie pas la version de la base. | aucune | Rien. |
| C59 | Tests V7 sans contrôle positif : un refus pour une autre raison les rendait verts. | aucune | Leçon suivie dans le lot AUD. |
| C60 | Paquets de tests externes hors des conventions du logiciel. | aucune | Scénarios réécrits selon nos règles. |
| C61 | Deux tests ChatGPT ne prouvaient rien (refus pour une autre raison). | aucune | Leçon suivie (C01). |
| C63 | Messages de refus vérifiés trop largement dans des tests externes. | aucune | Leçon suivie. |
| C64 | Tests de stock ChatGPT liés à une version externe. | mineure | Leçon suivie (préparation par le logiciel). |
| C65 | Pack ChatGPT V2 : aucun résultat livré, rôles dépassés. | aucune | Repris à la campagne 1 bis. |
| C66 | Lanceurs PowerShell ChatGPT fragiles. | aucune | Rien. |
| C67 | Copie en double d'une suite d'audit : erreur au chargement. | aucune | À supprimer sur votre copie. |
| C68 | Suite V5 : mémoire épuisée en fin de campagne. | aucune | La lancer classe par classe. |
| C69 | Un échec de concurrence des encaissements sans message (V5). | aucune | Démarrage lent ; aucun double encaissement possible ; diagnostic amélioré (C42). |
| C70 | Jeux de 2 000 cas qui répètent les mêmes combinaisons. | aucune | Rien. |
| C71 | Liste des tables écrite en dur dans une suite externe. | aucune | Nos tests lisent la base. |
| C72 | Campagnes sans le numéro de la version testée. | aucune | Règle pour la campagne 1 bis. |
| C73 | Trois lancements du 6 novembre arrêtés (tests introuvables). | aucune | Rien. |
| C74 | Journaux Windows mal décodés. | aucune | Rien. |
| C77 | Banc de charge : stock posé directement à 100 000. | mineure | Consigne pour l'outil de la campagne 1 bis. |
| C78 | Banc de charge : comptes créés sans les rôles de la campagne. | aucune | Idem. |
| C79 | Banc de charge : presque aucune concurrence dans un même magasin. | aucune | Idem. |
| C80 | Banc de charge : vérification finale partielle. | mineure | Idem. |
| C81 | Banc de charge : isolation des lectures jamais contrôlée. | aucune | Idem. |
| C82 | Banc de charge : réglages en mémoire, pas ceux du logiciel. | aucune | Idem. |
| C83 | Banc de charge : base jamais recréée à la version testée. | aucune | Idem. |
| C84 | Banc de charge : rapport sans provenance ni empreinte. | aucune | Idem. |
| C85 | Banc de charge : mesures biaisées. | aucune | Idem. |
| C86 | Banc de charge : demandes répétées jamais éprouvées. | mineure | Idem. |
| C87 | Banc de charge : refus attendus jamais éprouvés. | aucune | Idem. |
| C88 | Banc de charge : verrous et connexions non mesurés. | aucune | Idem. |
| C89 | Bancs V1 et V2 : Python requis. | aucune | Seule la V3 servira. |
| C90 | Banc de charge : données tirées au hasard, sans graine. | aucune | Consigne pour l'outil de la campagne. |
| C91 | Banc de charge : lectures trop simples, pas d'écrans réels. | aucune | Idem. |
| C94 | Correction externe : concurrence de la protection du stock non éprouvée. | aucune | Le lot AUD l'éprouvera. |
| C95 | Correction externe : contrôles faits avant la base trop grossiers. | aucune | Le lot AUD fera les contrôles complets. |
| C96 | Correction externe : fabrique de produits de test incorrecte. | aucune | Le lot AUD la refait. |
| C97 | Test externe de ligne d'achat forgée qui ne prouvait rien. | aucune | Réécrit dans le lot AUD. |
| C99 | Nom de fonction erroné dans un commentaire externe. | aucune | Corrigé à la reprise. |

### 2.8 Résultats sans défaut (7 groupes)

Ils sont rassurants, avec leurs limites :

- **C106, isolation** : les tests d'isolation du logiciel passent sous PostgreSQL 17 (107 et 39), comme ceux des auditeurs (V7 : modèles, adresses, écrans ; AuditMassif : 289). Limite : deux tests ChatGPT ne comptent pas (C61).
- **C107, droits** : 892 tests de droits du logiciel passent, et des milliers de cas des auditeurs (portées, espace de la société, cabinets). Limite : sources V4 et V5 non fournies.
- **C108, paiements et demandes répétées** : rejeu sans doublon, même demande envoyée 8 fois à la fois, 4 240 parcours, encaissements simultanés (le seul échec est C69, un défaut d'outil).
- **C109, caisse et clôtures** : 254 tests de la caisse sécurisée passent ; une seconde clôture du même jour et le vidage d'une table protégée sont refusés. Limite : un test vert ne l'était que sur la copie corrigée par l'autre session (C24).
- **C110, base de données** : 162 attaques sur 162 refusées, mais sur la copie corrigée par l'autre session, pas sur le logiciel poussé ; elles ne couvrent pas C23.
- **C111, sécurité des pages et des fichiers** : 14 attaques sur 22 refusées le 5 novembre (texte piégé dans un PDF, adresse d'un autre magasin, liens signés, attente après des échecs) ; les 8 autres sont C01. Limite : le PDF n'a été éprouvé qu'en HTML.
- **C112, le reste** : aucune faille dans les paquets npm, Dompdf à jour, vérification automatique verte le 3 novembre, 153 tests de facturation, 19 000 calculs, 21 090 tests d'audit après correction. Limite : les résultats attendus des calculs n'ont pas été fournis.

## 3. Les tests des auditeurs : repris ou non

| Tests | Repris ? | Pourquoi |
|---|---|---|
| Cybersécurité, attaques métier et isolation | En partie | Les 8 cas « client d'un autre magasin » sont la meilleure preuve de C01 : réécrits dans nos tests d'isolation. Les 3 essais d'injection SQL ne prouvent rien de plus que ce que le logiciel garantit déjà. |
| Cybersécurité, pages et documents | Non | Le PDF est traité par le lot FI ; le reste existe déjà dans nos tests. |
| Isolation offensive V7 | En partie | Les cas du client, et deux cas d'adresse si nos tests ne les couvrent pas ; le reste fait double. |
| ChatGPT V1 | En partie | Trois cas corrigés pour prouver vraiment quelque chose (C61, C62) ; les cas du ticket Z et du stock, avec des vérifications plus précises (C63, C64). |
| ChatGPT V2 | En partie | Un cas refait (C62) ; les ventes simultanées et la même demande répétée vont à la campagne 1 bis. |
| Attaques SQL de l'autre session (162) | Oui, adaptées | Le cœur des protections de la base : réécrites dans nos tests, complétées (C23, C14 à C16, tables récentes). Celles qui demandent deux comptes séparés iront à l'étape 15. |
| Tests de la provenance du stock | Oui, adaptés | Avec les trous corrigés (C14 à C16). |
| Tests du logiciel modifiés par l'autre session | En partie | Les ajouts utiles, fusionnés à la main dans nos fichiers actuels. |
| AuditMassif, IndependentAudit | En partie | Leurs scénarios utiles sont couverts par les corrections du lot AUD ; la copie en double, les heures sans fuseau et la grande matrice ne le sont pas. |
| V4, V5, V6 (sources non livrées) | En partie | Seuls deux scénarios sont réécrits : client d'un autre magasin (V4) et reprise après panne de la plateforme (V6). |
| Bancs de charge | Plus tard | Le squelette de la V3 servira à la campagne 1 bis. |
| Lanceurs Windows | Non | Outils de poste, pas des tests du logiciel. |

## 4. Les corrections de l'autre session de Claude

Ces corrections partent d'une version plus ancienne du logiciel (`1d3278a`). Leurs idées sont bonnes et leurs garde-fous soignés, mais aucune n'est dans le logiciel poussé.

**Ce qui sera repris, adapté aux règles du logiciel** :
- relire le client ou le fournisseur dans le magasin avant d'écrire (C01 à C03) ;
- les liens entre tables gardés au même magasin par la base, et les unicités qui vont avec, écrits directement dans les migrations de création (C05 à C07) ;
- le grand livre du stock et les tickets Z protégés en ajout seul, le lien d'un ticket à son Z figé (C08, C24, C25) ;
- la clôture écrite en une seule fois, et la vérification des tickets Z, complétée (C24, C26, C28) ;
- la garde du stock et celle de la provenance des mouvements, avec leurs trois trous corrigés (C10 à C16) ;
- leurs attaques SQL et plusieurs aides de test, réécrites dans nos dossiers de tests.

**Ce qui ne sera pas repris, et pourquoi** :
- **copier leurs fichiers ou appliquer leurs correctifs** : cela effacerait le travail des lots TR et EX (prix d'achat à 6 décimales, TVA recopiée du fournisseur, pièces du fournisseur, date obligatoire, reçus de paiement, détail des clôtures), et 13 morceaux ne s'appliquent même plus (C48) ;
- **leurs deux migrations « de mise à niveau des bases déployées »** : vos règles veulent une seule migration par table, modifiée directement, puisqu'aucune donnée réelle n'existe (C21, C53) ;
- **leur fichier des paquets** : il changeait 26 paquets sans votre accord ; les six utiles ont été mis à jour à part le 8 novembre (C41) ;
- **leur ancien fichier des clôtures** : il retirerait le détail des clôtures ajouté depuis ;
- **les trous de leur provenance du stock** (C14 à C16), leurs protections sans chemin fixé (C23), leur fabrique de produits (C96) ;
- **leurs lanceurs** (création de comptes et de bases, droits trop larges) et les paquets d'audit placés dans le dossier des tests (C44, C55, C67).

## 5. Ce qui reste hors de portée, et où cela se placera

**Audits proposés, jamais faits** :
- **mesures de charge réelle** (aucune n'existe ; les bancs n'ont jamais été lancés) → campagne de tests 1 bis, avec un outil écrit dans le logiciel (C76, C102 ; exigences relevées sur les bancs : C77 à C91) ;
- **audit « V3 »** (pannes, révocation sous charge, fraude SQL ciblée) → trois tests de concurrence dans le lot AUD, le reste à la campagne 1 bis (C101) ;
- **test d'intrusion indépendant** → votre décision, avant la mise en production (C103) ;
- **revue indépendante de la conformité fiscale et de la facture électronique** → votre décision ; essai réel dans le bac à sable de SUPER PDP à l'étape 2 (C104).

**Risques qui resteront après le lot AUD** :
- quelqu'un qui détient le compte qui a créé les tables peut encore lever les protections : deux comptes séparés à l'étape 15 (C32) ; d'ici là, la plupart de ces écritures seraient vues par la vérification de chaque nuit (jusqu'à une nuit de délai), et personne ne peut refaire une signature sans les clés du logiciel, qui ne sont pas dans la base ;
- l'heure des ventes si PILIQI tourne un jour sur plusieurs serveurs (Q-AUD5, étape 15) ;
- les ajustements de stock à la main, qui n'existent pas encore (Q-AUD4, étape 7) ;
- la vraie plateforme agréée, les sauvegardes et les clés de production (étapes 2, 3 et 15, C35) ;
- la faille possible de Filament, non confirmée (C37) ; un démarrage lent des tests sous Windows (C42).

**Décisions qui vous reviennent, hors règles métier** :
- **Q-AUD6** : un audit des paquets chaque jour, et les mises à jour proposées automatiquement par GitHub (Dependabot), demandent un petit fichier sur la branche principale `main`, qui n'a aujourd'hui que le tout premier commit. Recommandé : oui.
- **Q-AUD7** : accord pour écrire dans `CLAUDE.md` les textes proposés par Claude (garanties posées par le lot, précisions de l'étape 15, règles de lecture de la campagne 1 bis). Recommandé : oui.
- **Test d'intrusion indépendant** : par qui, quand, avec quel budget ? Recommandé : sur une copie d'essai aux données fictives, avant la campagne finale de l'étape 15.
- **Revue de conformité indépendante** : par un expert-comptable, un avocat fiscaliste ou un organisme certificateur, à inscrire à l'étape 15 si vous la voulez.
- **Objectifs chiffrés de la campagne 1 bis** : nombre de sociétés et de magasins, ventes par seconde en pointe, délai acceptable pour une vente et pour l'ouverture d'une page.
- **Clés de production** (étape 15) : où les garder (variables de l'hébergeur, coffre de secrets) et qui garde la copie de secours.

**Choix de Claude, que vous pouvez contester** :
- trois tests qui fabriquent exprès une erreur pour vérifier qu'elle est détectée vérifieront d'abord que la base la refuse, puis lèveront la protection seulement le temps du test, comme d'autres tests le font déjà ; leurs vérifications ne changent pas ;
- le coût moyen et le fournisseur d'un produit seront contrôlés chaque nuit plutôt que bloqués par la base (C22) ;
- le stock d'un produit sera tenu par la base elle-même, toujours égal à la somme de ses mouvements (C10).

**Place dans la feuille de route** : le lot AUD appartient à l'étape 1 (corrections), après QA, PG, CALC et FI ; puis campagne 1 bis (charge, concurrence en volume, outil) ; étape 2 (vraie plateforme, reprise après panne qualifiée) ; étape 3 (vérification des tickets Z recalculée depuis le registre) ; étape 7 (ajustements de stock) ; étape 13 (procédure de l'éditeur pour un faux ticket Z, si Q-AUD2 = a) ; étape 15 (deux comptes de base, clés, heure des serveurs, test d'intrusion et revue si vous les décidez).

## 6. Vos questions

Elles sont dans `questions-editeur.md`, chacune avec l'option recommandée :
- **Q-AUD1** : écart dans le stock trouvé la nuit : qui prévenir, faut-il bloquer ?
- **Q-AUD2** : faux ticket Z : le retirer ou le garder marqué ?
- **Q-AUD3** : envoi à la plateforme agréée qui échoue pour de bon : qui est prévenu, qui relance ?
- **Q-AUD4** : ajustements du stock à la main (étape 7) : comment, et par qui ?
- **Q-AUD5** : plusieurs serveurs : quelle horloge fait foi ?

**À faire sur votre PC** : rien pour l'instant. Après le lot AUD : `php artisan migrate:fresh` (la base de développement se recrée, puisque les migrations changent), et ajouter `PDP_PILOTE=faux` dans le `.env` si le lot le demande.
