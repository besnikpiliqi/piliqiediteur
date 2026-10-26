# Stratégie de la campagne de tests de PILIQI

*Version finale, destinée à l'éditeur. Rédigée le 10 octobre 2026. Elle part du code au commit c2845e2 (lot 13 des décisions relu). Elle tient compte aussi de l'arbre de travail : le lot « passage à PostgreSQL » est en cours de finalisation (tâches 248 à 251 terminées, 252 en cours : tests sur les deux moteurs, CI, CLAUDE.md, commit). Elle s'appuie enfin sur le plan des lots 14 à 21 et sur quatre reconnaissances exécutées dans des copies isolées du dépôt. Aucune ligne de la campagne n'a encore été lancée : ce document décrit ce qui sera fait.*

**Comment lire les niveaux de preuve** :
- **[prouvé ici]** : exécuté sur cette machine pendant la reconnaissance, avec un journal disponible.
- **[constaté]** : lu dans le code ou dans un fichier, sans exécution.
- **[à confirmer]** : plausible, mais pas encore essayé.
- **[supposé]** : estimation ou appréciation.

---

## Ce qui a changé depuis la version précédente

Deux critiques ont relu la version précédente. J'ai vérifié leurs remarques dans le code ou dans les fichiers de la reconnaissance, puis je les ai intégrées. Voici les principaux changements.

1. **Moteur de base de données.** La version précédente visait MariaDB 10.11 et MySQL 8.4. C'était périmé. Le lot « passage à PostgreSQL » retient PostgreSQL en production et SQLite en développement et en test, et il supprime toute branche MySQL et MariaDB. Le code le montre déjà [constaté] : `DeclencheursDeProtection.php` refuse désormais tout autre pilote. La campagne vise donc **SQLite et PostgreSQL 17**, la version du job de CI prévu par le lot. Les sections 0, 3, 7.8, 9, 11 et 14 sont réécrites. MySQL est abandonné. MariaDB 11.8 ne reviendrait que si vous changiez de moteur.
2. **Calendrier.** La campagne attend deux commits : celui du lot PostgreSQL et celui du lot 21. Une étape de recalage (R0) s'ajoute avant la préparation. Elle rejoue les preuves de la reconnaissance sous PostgreSQL et remesure les temps et les tailles. En effet, toutes les mesures précédentes datent du commit 204db25, sur MariaDB et SQLite.
3. **Constats reclassés** (section 0).
   - F1 est corrigé par le lot PostgreSQL, qui nomme l'index ; à confirmer au commit.
   - A2 ne compte plus.
   - F2, F3, A5 et A7 ne sont prouvés que sous InnoDB (MariaDB, MySQL) : ils sont à reprouver.
   - L'erreur 500 de F3 était déduite, pas observée.
   - A3 révèle un second problème : le code magasin est unique dans tout le logiciel, alors que la décision J1 dit « unique dans la société ».
   - Le parcours k6 de la reconnaissance avait échoué à son propre seuil, ce que la version précédente taisait.
4. **Garantie « jamais de vraie requête ».** La version précédente comptait sur le proxy du conteneur. Or `api.superpdp.tech` est joignable à travers lui, et la requête GET envoyée pendant la reconnaissance enfreignait déjà votre règle. La campagne fait désormais trois choses :
   - elle isole le réseau de ses processus ;
   - elle ajoute `Http::preventStrayRequests()` aux tests ;
   - elle publie un compteur des requêtes sortantes.

   Le critère « 0 requête sortante réelle » est ajouté.
5. **Catalogue élargi.** Nouveaux scénarios :
   - remises, escompte et barème ; services ;
   - usurpation de SIREN et règles fines des sociétés à plusieurs magasins ;
   - élévation de privilèges dans l'équipe ; confidentialité au-delà de l'encaissement ;
   - caisse personnelle et clôture ; contre-passation et journées ;
   - interactions entre fiches et documents ; détails des ventes hors de France ;
   - limites numériques et calendrier ;
   - configuration réellement livrée (cache, sessions et files d'attente en base) ;
   - connexions derrière une seule adresse IP ; voisin bruyant ;
   - restauration d'une sauvegarde face au registre ; rotation des clés.
6. **Erreurs corrigées.**
   - Le scénario du magasin suspendu (MAG-04, ancien MAG-05) suit la liste des autorisations permises en lecture seule.
   - L'invariant B6 « crédit et facture impayée » est limité aux moments où le crédit s'impute.
   - J5 signifie « aucun transfert de marchandises entre magasins ».
   - PLA-01 inclut la tâche de 00:30 (expiration des devis).
   - La matrice d'autorisations est générée depuis `PermissionRegistry`.
   - La décision 16(b) porte sur la remise et les lignes de service.
   - Le SQL d'exemple du registre détecte un doublon compensé par un trou.
   - La propriété « aucun flottant » est reformulée.
   - Chiffres rectifiés : 11 défauts probables, et non 12 (renommés L1 à L11 pour ne plus les confondre avec les décisions H1 à H4) ; 10 décisions partiellement testables ; nombre de comptes.
7. **Oracle renforcé.**
   - Il couvre désormais les remises, l'escompte, le barème, le chiffre d'affaires (B17), le relevé de caisse (B18) et les totaux des Z.
   - Arrondi « demi vers le haut » explicite (`ROUND_HALF_UP`) ; répartition de la TVA (E1) vérifiée par propriétés.
   - Registre des intentions pour détecter les doublons ; marqueurs uniques par magasin (« canaris ») pour détecter les fuites en lecture.
   - Invariant structurel : le verrou du registre est pris en premier.
   - Chaque invariant est validé avant usage ; 14 erreurs volontaires (« mutants ») sont plantées dans le code pour vérifier qu'elles sont détectées.
8. **Concurrence réelle.**
   - Points chauds : plusieurs processus sur le même magasin et sur le même SIREN.
   - Exploration systématique des entrelacements de deux actions.
   - Points d'arrêt aussi sur les effets hors SQL, repérés par l'empreinte de la requête.
   - Une base par test, créée depuis un modèle.
9. **Horloge.** Chronologie commune de génération ; horloge simulée partagée entre processus (libfaketime, à valider) ; traitement des colonnes datées par la base elle-même.
10. **Critères mesurables.**
    - Temps CPU, lignes lues et mémoire comparés entre paliers.
    - Validité des mesures jugée sur le CPU consommé par les processus de la campagne.
    - Efforts minimaux chiffrés.
    - Nouveaux critères : requêtes sortantes, exceptions inattendues, 85 décisions couvertes, `composer audit`, couverture de code.
11. **Budget et disque recalculés.** C1 demande 60 à 100 h de temps machine, et non 25 à 35 h. L'option 8(b) est abandonnée. Un plan disque explicite est ajouté. Les tailles sous PostgreSQL restent à mesurer.
12. **Outils.**
    - Versions publiées depuis au moins deux semaines.
    - Installation d'abord dans un projet d'outils séparé, hors du dépôt.
    - Larastan casserait `composer install` dans ce conteneur.
13. **Rapport.** Rubriques ajoutées :
    - décompte des tests par type et par moteur ;
    - ressources consommées par palier ;
    - matrice des 85 décisions ;
    - opérations métier par issue ;
    - correspondance avec votre demande ;
    - empreinte des preuves versionnée.
14. **Décisions.** 19 points au lieu de 16.
    - Retirés : tâches de CI MariaDB et MySQL, choix du moteur de production.
    - Ajoutés : configuration à tester, minimum de rôles par magasin, gravité validée par vous, règles métier nouvelles (idempotence, propriété du SIREN, unicité du code magasin, membre créé dans un magasin suspendu, répartition de la TVA, restauration), recette dans le bac à sable SUPER PDP, publication des preuves.

**Critiques retenues en partie** :
- Les seuils de mutation et de couverture sont publiés dès maintenant comme objectifs provisoires (critique 1), puis fixés après la première mesure (critique 2).
- PostgreSQL 18 n'est qu'une variante pour la campagne finale. La cible est la 17, celle de la CI.
- L'invariant « aucun document émis après l'archivage de sa fiche » est gardé, à confirmer selon la règle du lot 6 : un brouillon créé avant l'archivage peut-il être émis ?

---

## En bref

- **But.** Trouver le plus d'anomalies possible avant la commercialisation : montants et TVA, sécurité, intégrité, comportement sous concurrence et en panne. Ce n'est pas une mesure de capacité. Le rapport ne dira jamais « PILIQI est sans erreur ». Il dira ce qui a été exécuté, trouvé et corrigé, et ce qui n'a pas été couvert.
- **Moteurs.** SQLite pour la rapidité ; PostgreSQL 17 pour la concurrence, le volume et la représentativité de la production.
- **Quand.** Après les commits du lot PostgreSQL et du lot 21, sans interrompre les lots. D'abord le recalage (R0), puis la préparation (C0), puis la campagne initiale (C1). Ensuite, une relance après chaque grande étape, et une campagne finale avant la mise en production.
- **Ce que la reconnaissance a établi** (à reprouver sous PostgreSQL, sauf le dernier point) :
  - deux paiements simultanés de 60 € acceptés sur un ticket de 100 € (InnoDB) ;
  - un interblocage sur le premier numéro d'une série (InnoDB) ;
  - des codes magasin en double, et des inscriptions simultanées qui échouent sur une base neuve ;
  - un cache des autorisations dont le coût croît avec le nombre de magasins : 79 s et 1,9 Go à 22 540 magasins. Ce dernier point ne dépend pas du moteur et bloque toute montée en volume.
- **Prouvé faisable ici** :
  - PostgreSQL 16, déjà installé : le lot PostgreSQL y fait tourner la suite ;
  - Docker démarré à la main, avec des images tirées de Docker Hub ;
  - Redis, nginx et php-fpm, k6, Locust, Toxiproxy, Prism, pcov et xdebug, Chrome headless ;
  - `unshare -n`, commande acceptée.

  **À confirmer** : l'image `postgres:17`, libfaketime, et une pile complète isolée du réseau.
- **Limites de la machine** (4 processeurs, 15 Gio de mémoire, environ 15 Go de disque partagé et fluctuant) :
  - P0 à P2 tiennent ici ;
  - P3 tient ici seulement si les tailles PostgreSQL le permettent ;
  - 10 000 sociétés complètes et des milliers de requêtes simultanées exigent une machine dédiée.
- **Vos décisions sont attendues sur 19 points** (section 14). Les principales :
  - inscrire la campagne dans la feuille de route ;
  - choisir la configuration à tester (cache, sessions et files en base, ou Redis) ;
  - fixer le minimum de rôles par magasin ;
  - fixer le volume maximal ;
  - trancher neuf règles métier apparues pendant la préparation.

---

## 0. Point de départ

### 0.1 Constats de la reconnaissance, reclassés

Ces constats viennent de scripts exécutés dans des copies isolées (`scratchpad/campagne/…`), au commit 204db25, sur MariaDB 10.11, MySQL 8.4 et SQLite. Ce ne sont pas encore des tests de la suite.

| Réf. | Constat | Preuve | Après le passage à PostgreSQL | Gravité provisoire |
|---|---|---|---|---|
| F1 | Migrations en échec sur MariaDB et MySQL : un nom d'index fait 67 caractères pour une limite de 64. | [prouvé ici] | Corrigé par le lot PostgreSQL, qui nomme l'index `enregistrements_fiscaux_document_index` [constaté dans le diff ; à confirmer au commit]. Nouveau risque : PostgreSQL tronque sans erreur un identifiant de plus de 63 octets, et deux noms tronqués peuvent alors se confondre. Un contrôle automatique listera ces identifiants. | Résolu, à surveiller |
| F2 | Deux paiements simultanés de 60 € sur un ticket de 100 € sont acceptés (120 € imputés). Le code lit `$client->magasin` sans verrou avant de verrouiller le registre, ce qui fige trop tôt la vue des données d'InnoDB. `montantRestant()` relit ensuite cette vue périmée. Le vérificateur fiscal ne voit rien. | [prouvé ici], sous InnoDB en REPEATABLE READ, avec une pause forcée de 3 s | En READ COMMITTED, le niveau d'isolation par défaut de PostgreSQL, chaque requête relit les données à jour : sans doute non reproductible tel quel. Reproductible en REPEATABLE READ [supposé]. Le lot 14 réécrit les paiements. À reprouver en R0, puis à garder comme test contre le retour de cette erreur. | Bloquant si reprouvé |
| F3 | Deux premiers numéros simultanés d'une même série provoquent un interblocage (erreur 1213). Les données restent cohérentes. | Interblocage [prouvé ici] par un script en ligne de commande. **L'erreur 500 était déduite, pas observée en HTTP.** | Sous PostgreSQL, on attend une violation d'unicité (23505) ou un interblocage (40P01) [supposé]. À reprouver, en HTTP cette fois. | Majeur si reprouvé |
| A2 | Sous MySQL avec journal binaire, la création des déclencheurs exige le privilège SUPER. | [prouvé ici] | Ne compte plus. L'équivalent sous PostgreSQL est la séparation des rôles (7.8). | Retiré |
| A3 | Sur 472 inscriptions en 4 processus : 118 interblocages et 3 codes magasin en double (erreur 1062 sur LEVEQU4, CLERC, BERTRA2), sans nouvelle tentative. | [prouvé ici] | Deux problèmes distincts. (a) Les interblocages sont propres à InnoDB : à reprouver. (b) Une course dans la proposition de code fait échouer l'inscription (23505 sous PostgreSQL) ; elle ne dépend probablement pas du moteur [supposé]. De plus, `magasins.code` est unique dans toute la base (migration des magasins, ligne 100), alors que J1 dit « unique dans la société » : décision 14-17(d). | Majeur |
| A4 | Le cache des autorisations charge les rôles de tous les magasins. Reconstruction : 0,48 s pour 192 magasins, 5,6 s pour 2 254, 79 s et +1,9 Go pour 22 540. Au fil d'un essai de 191 magasins, une inscription passe de 78 à 461 ms, à nombre de requêtes constant (13,7). | [prouvé ici] | Ne dépend pas du moteur. Aggravé par la configuration livrée (`CACHE_STORE=database`) : le bloc entier serait relu depuis la table `cache`. Toute modification d'autorisations, dans n'importe quel magasin, vide le cache de tous. | Bloquant à l'échelle |
| A5 | Interblocages entre magasins **différents** en REPEATABLE READ (26, puis 37, sur environ 180 magasins), aucun en READ COMMITTED. | [prouvé ici], sous InnoDB | Causés par des verrous propres à InnoDB : ne se transpose pas tel quel. À reprouver sous PostgreSQL dans les deux niveaux. | À reprouver |
| A6 | Inscriptions simultanées sur une base neuve : 3 processus sur 4 échouent (1062 sur `permissions_name_guard_name_unique`). | [prouvé ici], deux fois | Course à la création des autorisations, qui ne dépend probablement pas du moteur [supposé] : 23505 attendu. | Majeur (premier démarrage, nouvelle autorisation) |
| A7 | L'ordre des autorisations écrit au journal dépend de la collation (l'ordre de tri des textes). | [constaté] | Le lot PostgreSQL fixe la collation C.UTF-8, qui trie dans le même ordre que SQLite : le problème devrait disparaître. À vérifier. | Mineur |
| A8 | Une dizaine de tests ne passaient que sous SQLite. | [prouvé ici] sur MariaDB et MySQL | Mesures caduques. Le lot PostgreSQL adapte lui-même ces tests. Son premier passage sous PostgreSQL 16 : 1 778 tests en 160 s avec paratest, 7 échecs en cours de correction à ce moment-là [constaté, `scratchpad/pg-run1.txt`]. À remesurer après son commit. | À remesurer |
| — | Un magasin s'est retrouvé une fois sans client comptoir. | Non reproduit | Devient un invariant global (8.2). | À surveiller |

**Le parcours k6 de la reconnaissance**, décrit exactement [prouvé ici, `campagne/charge/k6-session.txt`] :
- 726 vérifications sur 726 réussies, et 363 accès à un autre magasin refusés en 404 ;
- mais **il a échoué à son propre seuil** : p95 de 6,46 s pour un seuil de 3 s, sur une machine chargée par d'autres agents ;
- `http_req_failed` vaut 42,9 %, parce que les 404 attendus étaient comptés comme des échecs ;
- « 0 fuite » signifiait seulement « aucun code 200 sur la page d'index d'un autre magasin ». Une fuite à l'intérieur d'une page 200 restait invisible ;
- le parcours ne faisait aucune écriture.

### 0.2 Défauts probables repérés à la lecture du code (sans preuve)

Ils sont onze, et non douze comme l'annonçait la version précédente. Je les renomme L1 à L11, pour ne plus les confondre avec les décisions H1 à H4.

| Réf. | Défaut probable | Après le passage à PostgreSQL |
|---|---|---|
| L1 | La correction d'une facture d'achat validée efface le lien des avoirs fournisseur | Inchangé |
| L2 | Même mécanisme que F2 pour les avoirs et les crédits | À réévaluer après les lots 14 et 15 |
| L3 | Interblocages entre produits (vente et achat qui verrouillent dans l'ordre inverse) | À reprouver |
| L4 | Les jobs PDP ne retentent jamais | Inchangé |
| L5 | Les relances automatiques échappent au plafond de 200 e-mails par heure et par magasin | Inchangé |
| L6 | Double clic sur « Fermer ma caisse » | Le lot PostgreSQL relit `FermerSaCaisseAction` sous READ COMMITTED : à vérifier |
| L7 | Comparaison JSON sensible à l'ordre des clés | Disparaît si les colonnes restent en texte ou en `json` (pas en `jsonb`) : à vérifier |
| L8 | Différences de collation | C.UTF-8 retenue : à vérifier |
| L9 | Débordements numériques (`decimal(12,2)`, quantités) | Sous PostgreSQL, erreur 22003 au lieu de 1264 |
| L10 | Planificateur sans protection contre les doubles exécutions | Inchangé |
| L11 | Pas d'unicité entre un devis et sa facture | Inchangé |

### 0.3 Transparence

Pendant la reconnaissance :
- **Une requête GET sans authentification a été envoyée à la racine d'`api.superpdp.tech`** (réponse 404). C'était contraire à votre règle « jamais de vraie requête ». Aucun autre appel n'a été fait. La section 3.2 prévoit désormais un blocage technique.
- Le disque a été plein quelques secondes, à cause du cache Composer et d'un test d'écriture.
- Restes à nettoyer en C0, sauf si vous voulez garder MariaDB en repli :
  - le paquet `mariadb-server`, resté installé dans le conteneur ;
  - une base `piliqi_cout` sur l'instance MariaDB de campagne ;
  - l'image Docker MySQL, restée en cache.
- Trois copies de travail restent enregistrées (`campagne/wt`, `wth`, `inventaire`).

---

## 1. Objectifs et principes

### 1.1 Objectifs

1. **Exactitude** : montants, TVA, remises, arrondis, échéances, numéros, stocks, coût moyen, barème, crédits, statuts, totaux des clôtures, chiffre d'affaires, relevés de caisse.
2. **Sécurité** : authentification, autorisations cochées par le propriétaire, isolation stricte des magasins (y compris entre magasins d'un même SIREN), injections, fichiers, secrets.
3. **Intégrité** : documents émis immuables ; transactions complètes ou absentes ; aucun doublon de numéro, de paiement ou de déclaration ; registre fiscal chaîné et signé ; reprise propre après incident.
4. **Comportement global** :
   - concurrence entre milliers d'utilisateurs de sociétés différentes, et entre utilisateurs d'un même magasin ;
   - pannes, tâches planifiées, passages d'année et d'heure ;
   - coût qui croît avec le volume ;
   - équité entre magasins.

Hors objectif : mesurer la capacité maximale. Les temps sont relevés pour deux raisons seulement : détecter des anomalies et juger si l'environnement est représentatif.

### 1.2 Principes

1. **Le vrai code.** On passe par les vraies actions métier, les vraies policies, les vrais écrans Filament et Livewire, la vraie persistance et les vraies files d'attente. Seuls les services extérieurs sont simulés : SUPER PDP, VIES et l'envoi d'e-mails.
2. **Jamais de vraie requête vers l'extérieur**, garanti techniquement (3.2) et compté.
3. **Un oracle indépendant du code testé.** Les résultats sont contrôlés par :
   - des requêtes SQL directes ;
   - un modèle de référence et un registre des intentions, tenus par le générateur ;
   - une réimplémentation séparée des calculs, écrite à partir des décisions.

   Chaque invariant est d'abord validé (8.8).
4. **Les moteurs retenus.** SQLite sert à la rapidité : il traite les écritures une par une et ignore les verrous. La concurrence et le volume se testent sous PostgreSQL 17.
5. **Priorité au risque.** Le temps machine va d'abord au fiscal et au légal, puis à l'argent, puis à l'isolation, puis au reste (9.3).
6. **Reproductibilité.** Chaque exécution enregistre le commit, les versions, la graine du hasard, l'empreinte des scripts et les paramètres.
7. **Montée progressive.** On ne passe au palier suivant que si le précédent respecte tous les invariants.
8. **Isolation.** Jamais sur la base du dépôt. Une instance de base par essai lourd. Des données entièrement fictives. Un générateur qui refuse de tourner en production.
9. **Honnêteté.**
   - Chaque test porte l'un de ces quatre états : *exécuté et réussi*, *exécuté et échoué*, *non exécuté*, *attendu en échec* (règle pas encore construite).
   - Aucun résultat n'est annoncé sans journal.
   - La conclusion s'écrit : « aucune anomalie détectée par les scénarios exécutés, dans les limites listées ».
10. **Une anomalie, un test.**
    - Le test échoue avant la correction.
    - Aucun test n'est jamais supprimé ni affaibli.
    - Toute règle métier à trancher vous est soumise, **ainsi que la gravité des anomalies** (section 10).
11. **On teste aussi les tests**, de trois façons : des mutants plantés dans le code, des corruptions volontaires de données, des tests de mutation automatiques.
12. **Aucune interruption des lots, aucune dépendance nouvelle sans votre accord.**

---

## 2. Périmètre et calendrier

### 2.1 Ce qui se teste dès la fin de l'étape 1 (campagne C1)

Tout ce qui existera après le lot 21 et le lot PostgreSQL :
- sociétés et magasins : inscription, code, plusieurs magasins d'un même SIREN, magasin principal, réglages communs ;
- comptes, équipe, autorisations ; isolation ;
- clients, fournisseurs, produits, services, archivage ;
- devis, factures et tickets, caisse, avoirs et retours partiels, remises ;
- paiements, contre-passation, crédits et bons d'avoir (lots 14 et 15) ;
- confidentialité des vendeurs, chiffre d'affaires, relevé de caisse (lot 16) ;
- achats avec remises et escompte, barème, stock et coût moyen (lot 17) ;
- VIES simulé (lot 18), natures d'opération (lot 19), preuves et détaxe (lot 20) ;
- clôtures journalières actuelles, Z à zéro, rattrapage ;
- registre fiscal, journal des événements, vérificateur, diagnostic (`DiagnostiquerCaisseSecurisee`) ;
- e-mails, relances, notifications ; tâches planifiées ; PDF actuels ; widgets ;
- e-reporting et passerelle factice, testés seulement contre les régressions puisqu'ils seront remplacés à l'étape 2.

Les scénarios des lots 14 à 21 sont écrits d'après leur plan (`plan-decisions-final.json`). Ils seront recalés sur le code livré pendant C0.

Sur les 85 décisions :
- **61 sont testables en C1, dont 10 partiellement** : A2, B4, B10, B17, B18, C3, D2, E1, I1 (sa seconde vague, OSS et export enlevé par le client, n'est pas construite) et I3 ;
- **23 ne le seront qu'après une étape ultérieure** ;
- **1 ne se teste pas** : E2, l'ordre de la feuille de route.

Le détail est en 6.6.

### 2.2 Ce qui ne pourra être testé qu'après les étapes suivantes

| Étape | Ajouté à la campagne | Ce que l'étape peut casser dans l'existant (rejoué en premier) | Campagne |
|---|---|---|---|
| 2. SUPER PDP | Simulateur avec état : environ 190 cas en 14 familles et 17 invariants dédiés. Connexion OAuth de chaque magasin et sécurité du retour `/superpdp/retour`. Réception et rangement par SIRET ; facture reçue deux fois. Émission Factur-X ; redépôt sous le même numéro. E-reporting par clôture : un jour sans opération ne déclare rien, un jour à total net nul avec des opérations est déclaré. E-reporting B2B international (`/b2bint_invoices`, `/b2bint_payments`). Encaissements fr:212. Annuaire. Réponses 429 (trop de requêtes). Les mandats de facturation entre sociétés (`company_mandates`) sont déclarés hors périmètre. Décisions E3 à E15, J3, J4, K10. | Jobs, files d'attente, émission (statuts de transmission), magasin suspendu | C2 |
| 3. Caisse sécurisée, fin | Clôtures journalières, mensuelles et d'exercice calculées depuis le registre (D1) ; totaux perpétuels ; justificatifs et duplicatas produits depuis le contenu signé (D8) ; archives signées ; attestation et volet 2 (D11, D12) ; fin d'exercice (D4) ; clôture finale à la résiliation (D6) ; conservation (D5). | Totaux des Z actuels, relevé de caisse, rattrapage | C3 |
| 4. Journal d'audit | Exhaustivité, anciennes et nouvelles valeurs, isolation, lecture réservée au propriétaire. | Toutes les écritures (une ligne d'audit de plus dans chaque transaction) | C4 |
| 5. Comptabilité | Écritures équilibrées, journaux, plan de comptes, FEC, état de TVA recalculé, régularisation de la détaxe (I3). | Montants enregistrés, avoirs, détaxe | C4 |
| 6. Rapports | Rapports recoupés avec les documents. | — | C5 |
| 7. Stock | Inventaire, ajustements, catégories, **unités**. | **Quantités décimales** : les quantités entières (`QuantiteEntiere`) sont supposées partout. L'oracle et les invariants de stock et de retours cumulés sont à rejouer. | C5 |
| 8. Pièces jointes, contacts, banque | Fichiers isolés par magasin ; contacts ; validation de l'IBAN ; fraude au changement d'IBAN. | Isolation des fichiers, PDF | C5 |
| 9. RGPD | Export et anonymisation sans toucher aux documents légaux (D5). | Client figé (B13), registre | C5 |
| 10 à 13. API, intégrations, abonnements, administration | Jetons, débit, pagination, isolation par l'API, webhooks. Paiement en ligne : notification rejouée, idempotence (une même demande répétée ne produit qu'un seul effet), signature. Limites des plans contournées par des créations simultanées. Facturation des magasins par l'éditeur. Suspension et résiliation par l'écran d'administration. Accès de l'éditeur explicite et audité. | Paiements (doublons par notification rejouée), statut du magasin | C6 |
| 14. Achats aux particuliers | Registre des objets, TVA sur marge. | Calcul de TVA, stock | CF |
| 15. Mise en production | Configuration réelle, deux rôles PostgreSQL, sauvegarde et restauration, plusieurs serveurs, montée de version sur une base remplie. | Tout | CF |

D'ici là, la résiliation, la suspension et l'administration ne s'appuient que sur l'existant : statut modifié directement en base, `MandatPdp`, passerelle factice. Le rapport le signale.

### 2.3 Calendrier

1. **Maintenant** : lots 14 à 21 et lot PostgreSQL. Aucun travail de campagne dans le dépôt.
2. **R0, recalage**, après les deux commits (environ 4 à 6 h de machine [supposé]) :
   - suites SQLite et PostgreSQL 17 avec `--parallel` ; temps ; tests propres à un seul moteur ;
   - preuves F2, F3, A3, A5, A6 et A7 rejouées sous PostgreSQL, en READ COMMITTED et en REPEATABLE READ. Chacune est classée *confirmée*, *disparue* ou *transformée* ;
   - coût par opération et tailles remesurés (stockage des gros champs, index, journal d'écriture) ;
   - contrôleur d'invariants porté sur PostgreSQL. Le prototype contient `suivi_stock = 1`, que PostgreSQL refuse, et ne connaît que trois types de mouvements de sortie.
3. **C0, préparation** :
   - environnement versionné et outillage ;
   - tâche de CI pour le groupe `concurrence` sous PostgreSQL ;
   - correction des anomalies qui empêchent la campagne : A4, A6, A3, et ce que R0 confirme.
4. **C1, campagne initiale**, entre les étapes 1 et 2 : paliers P0 à P3, puis P4 selon votre décision.
5. **Relances** : C2 après l'étape 2, C3 après la 3, C4 après les 4 et 5, C5 après les 6 à 9, C6 après les 10 à 13. Chaque relance rejoue d'abord ce que l'étape peut casser (2.2), puis tout le reste, et les graines qui avaient trouvé des anomalies.
6. **CF, campagne finale**, à l'étape 15.
7. **Entre deux campagnes** : la suite et le groupe `concurrence` tournent en CI sous SQLite et PostgreSQL à chaque envoi.

---

## 3. Environnement de test isolé et représentatif

### 3.1 Composants

| Composant | Version | Comment | État |
|---|---|---|---|
| Copies de travail | HEAD du moment | `git worktree add --detach` dans `scratchpad/campagne/`. `vendor` est recopié par liens physiques (`cp -al`), avec une vraie copie de `vendor/composer`, `vendor/bin` et `autoload.php`. Jamais de `composer` dans une telle copie, dont les fichiers sont partagés avec le dépôt ; au besoin, une copie complète de 4,6 Go. | [prouvé ici] |
| SQLite | celle du système | En mémoire (suite) ou en fichier | [prouvé ici] |
| PostgreSQL 16.14 | apt, déjà installé | Utilisé par le lot PostgreSQL pour la suite | [constaté] |
| **PostgreSQL 17** | image Docker `postgres:17` | Démon Docker démarré à la main. Une instance par essai. Socket au chemin court dans `/var/tmp`. Locale C.UTF-8, comme la CI. Journal des attentes de verrous et des interblocages, statistiques par requête (`pg_stat_statements`), plans des requêtes lentes (`auto_explain`). | Docker et Docker Hub [prouvés ici] : l'image MySQL a été tirée en 11 s. `postgres:17` [à confirmer]. apt.postgresql.org est refusé par le proxy [constaté]. |
| MariaDB 11.8 | — | Seulement si vous revenez sur le choix du moteur | Non prévu |
| Serveur HTTP | nginx 1.24, php-fpm 8.3.6 | Extraits des paquets .deb sans les installer, car PHP est partagé avec l'autre agent. 16 processus, opcache, configuration et routes en cache, `APP_DEBUG=false`. nginx sert aussi de proxy inverse avec `X-Forwarded-For` pour le scénario d'adresse IP unique. | [prouvé ici] |
| Cache, sessions, files d'attente | **en base (configuration livrée)** et Redis 7.0.15 | Les deux configurations sont jouées (décision 14-6) | Redis [prouvé ici] |
| Workers et planificateur | Laravel | `queue:work` (N processus), planificateur sous horloge simulée | À construire |
| Horloge simulée entre processus | libfaketime 0.9.10 | Pour php-fpm, les workers et le planificateur. Proposé par apt dans le conteneur, mais pas installé. | [à confirmer] |
| E-mails | — | Transport `array` ou `log` dans les tests ; dans la pile HTTP, un transport qui ne fait que compter | À construire |
| Simulateur VIES | — | `Http::fake` et un petit serveur local | À construire en C0 |
| Simulateur SUPER PDP | — | `tests/Simulateurs/SuperPdp/`, état en mémoire ou en SQLite | Prototype à 4 308 req/s [prouvé ici] ; version complète avec l'étape 2 |
| Toxiproxy | 2.12.0 | Compilé via proxy.golang.org | [prouvé ici] |

### 3.2 Garde-fous « jamais de vraie requête, jamais de vraies données »

Le proxy du conteneur **ne protège pas** : `api.superpdp.tech` est joignable à travers lui [prouvé ici]. Une URL en `.invalid` part quand même vers le proxy. Aujourd'hui, aucune garantie technique n'existe. La campagne en ajoute quatre.

1. **Réseau isolé.** Toute la pile tourne dans un espace réseau qui n'a que l'interface locale (`unshare -n`) : base, Redis, php-fpm, workers, simulateurs, générateur, k6.
   - La commande est acceptée dans ce conteneur [prouvé ici].
   - Une pile complète à l'intérieur, Docker compris, reste [à confirmer]. Les images sont tirées avant l'isolation.
   - Repli : `HTTPS_PROXY` et `HTTP_PROXY` pointés vers un port mort, pour ces seuls processus.
2. **Dans les tests.** `Http::preventStrayRequests()` est ajouté à `tests/TestCase.php`, où il manque aujourd'hui [constaté]. `SUPERPDP_URL` pointe vers un domaine `.invalid`. Même règle pour VIES.
3. **Test de garde.** Il échoue si `api.superpdp.tech`, `www.superpdp.tech` ou `ec.europa.eu` est résolu ou contacté pendant la campagne.
4. **Compteur publié.** Il additionne les requêtes bloquées par `preventStrayRequests`, les tentatives de connexion hors interface locale et les envois SMTP. Le critère est 0 (section 11).

S'y ajoutent :
- le générateur refuse `APP_ENV=production`, ainsi que toute base dont le nom ne commence pas par `piliqi_campagne` ;
- le script de lancement refuse tout hôte de service extérieur autre que 127.0.0.1, et tout identifiant client qui ne commence pas par `campagne-`.

La recette dans le vrai bac à sable SUPER PDP (Schematron, PDF/A-3, délais réels) est **hors campagne**. Elle exige votre accord explicite (décision 14-18).

### 3.3 Mesure des ressources

Chaque exécution relève :
- le **temps CPU** de chaque processus (`getrusage`), et la part du CPU total consommée par les processus de la campagne (via les groupes de contrôle Linux, ou `pidstat` si `sysstat` est installé [à confirmer]) ;
- la mémoire résidente maximale des processus, et `docker stats` ;
- pour PostgreSQL : lignes lues et renvoyées par requête (`pg_stat_statements`), interblocages et conflits (`pg_stat_database`), attentes de verrous, taille de la base et du journal d'écriture (WAL) ;
- `INFO` de Redis, et la taille des tables `jobs`, `cache` et `sessions` ;
- les résumés k6 ;
- le disque (`df`) avant et après chaque palier, et les entrées-sorties.

**Règle de validité** :
- un temps n'est retenu que si les processus extérieurs à la campagne ont consommé moins de 10 % du CPU pendant l'essai, ou si aucun autre agent ne tournait ;
- sinon, il est publié avec la mention « non représentatif » ;
- la charge système (`loadavg`) ne suffit pas, car elle compte aussi les processus de la campagne. Pendant la reconnaissance, elle a varié de 0,7 à 8,7.

### 3.4 Pièges déjà rencontrés

1. Un `vendor` en lien symbolique fait charger le code du dépôt principal au lieu de la copie. Il faut des liens physiques et une vraie copie de l'autoload.
2. Jamais de `composer` dans une copie dont `vendor` est fait de liens physiques.
3. Jamais de `config:cache` dans la copie qui sert aux tests : un essai a été contaminé ainsi.
4. Des chemins de socket courts, dans `/var/tmp`.
5. Pas de serveur partagé entre agents : un autre agent a arrêté un serveur commun en plein essai, et 86 opérations ont échoué.
6. Surveiller le disque. Nettoyer après chaque palier les caches Composer et Go, les images Docker et les instantanés.
7. Jamais de `pkill -f` avec un motif présent dans sa propre ligne de commande.
8. Détacher les exécutions de plus de 2 h (`setsid`, `nohup`) : une commande lancée en arrière-plan par l'agent est arrêtée au bout de 2 h (7 200 000 ms).

### 3.5 Ce qui n'est pas possible ici

- MySQL et MariaDB : hors cible, et refusés par les migrations une fois le lot PostgreSQL commité.
- apt.postgresql.org : refusé, d'où l'image Docker pour la version 17.
- Octane, Swoole, FrankenPHP : bloqués par le proxy.
- Site officiel de k6, CDN de Playwright, pecl : refusés, déjà contournés.
- Archives zip servies par `api.github.com` : refusées (403). Cela empêche d'installer phpstan (4.2).

### 3.6 Survie de l'environnement et des preuves

Une nouvelle session démarre sur une machine neuve : le scratchpad, les paquets apt, les images Docker et les binaires compilés sont perdus.
- **Un script d'amorçage versionné** (décision 14-4) reconstruit l'environnement en une commande, avec des versions et des empreintes fixées : `postgres:17`, Redis, nginx et php-fpm extraits, k6 et Toxiproxy compilés.
- **Le générateur peut reprendre** là où il s'est arrêté, grâce à un index de société et un fichier d'état.
- **Les preuves sont publiées hors du scratchpad** à la fin de chaque palier (décision 14-19).

---

## 4. Outils retenus

### 4.1 Sans nouvelle dépendance dans le dépôt

| Outil | Usage | État |
|---|---|---|
| PHPUnit 12.5, paratest 7.20, Faker, Mockery | Suite, groupe `concurrence`, scénarios | Déjà dans le dépôt |
| `Http::fake`, `preventStrayRequests` | Simulation des services extérieurs | Fournis par Laravel |
| Générateur de données | Vraies actions, graine, chronologie commune, registre des intentions | Prototype (`mesurer.php`), à étendre |
| Contrôleur d'invariants | Environ 130 contrôles SQL et PHP, en lecture seule (8.2) | Prototype de 25 contrôles, **pas encore compatible PostgreSQL** |
| Détecteur de canaris | Cherche les marqueurs d'autres magasins dans les réponses HTTP, PDF, e-mails, exports, notifications, jobs et journaux (8.4) | À écrire |
| Banc de concurrence | Processus parallèles, barrières de synchronisation, pause forcée après chaque requête d'une action | Prototype (preuves F2 et F3) |
| Injecteur de pannes | Arrêt, exception ou coupure, repérés par l'empreinte de la requête, y compris sur les effets hors SQL (7.6) | À écrire |
| Simulateurs VIES et SUPER PDP | Conformes à la décision E15 | Conçus ; prototype SUPER PDP mesuré |
| Oracle de calcul | Python avec `decimal` en `ROUND_HALF_UP`, écrit depuis les décisions (8.3) | À écrire |
| k6 1.8.1 | Charge et parcours HTTP. Écritures Livewire. 403 et 404 comptés comme réponses attendues (`expectedStatuses(200, 403, 404)`). Épinglé sur un processeur avec `taskset` (présent [constaté]). | Compilé ; un parcours de lecture déjà essayé |
| Locust 2.46.7, wrk, ab | Alternatives et micro-mesures | Installés |
| Toxiproxy 2.12 | Pannes réseau | [prouvé ici] |
| Prism 5.16 | Second validateur des requêtes SUPER PDP | Installé ; ne gère ni OAuth ni l'état |
| pcov 1.0.11, xdebug 3.2 | Couverture de code | [prouvé ici] |
| Chrome for Testing 131 | Une douzaine de parcours dans un vrai navigateur | Affiche la page de connexion ; pilote à choisir |
| libfaketime | Horloge simulée partagée entre processus | [à confirmer] |

### 4.2 Outils qui ajoutent une dépendance (votre accord est requis)

**Constats** :
- Les versions essayées pendant la reconnaissance étaient trop récentes. Eris 1.2.1 avait été publiée le jour même, infection 0.35.6 le 2 octobre, larastan 3.13.0 le 7 octobre [constaté sur Packagist]. Règle retenue : une version publiée depuis au moins deux semaines. Par exemple eris 1.1.0 ou 1.2.0, infection 0.35.4, larastan 3.12.2 avec phpstan 2.2.x, à revérifier.
- **phpstan n'a aucune source Git sur Packagist**, seulement une archive zip servie par `api.github.com`, refusée ici. Or le `vendor` de ce conteneur est installé depuis les sources Git. Ajouter larastan à `composer.json` et à `composer.lock` casserait donc `composer install` **dans ce conteneur**, pour tous les agents. La CI GitHub, elle, ne serait pas touchée.
- L'essai « 22 paquets, aucune mise à jour » ne valait que pour les versions essayées.

**Proposition** : installer d'abord ces outils dans un **projet séparé** (`campagne/outils-php`), avec son propre `composer.json` qui charge l'autoload de la copie de travail. Le dépôt n'est pas touché. Les outils n'y entrent qu'avec votre accord.

| Paquet (développement) | Pourquoi | Sans lui |
|---|---|---|
| `giorgiosironi/eris` | Tests par propriétés, avec réduction automatique du contre-exemple au cas minimal | Générateur maison, sans réduction |
| `infection/infection` | Tests de mutation automatiques | Seuls les 14 mutants plantés (8.7) |
| `larastan/larastan` | Analyse statique, prévue à l'étape 15 et avancée | Repoussée. Casse `composer install` ici s'il est ajouté au dépôt. |
| `laravel/dusk` (facultatif) | Pilote de navigateur intégré à PHPUnit | Module navigateur de k6, hors dépôt |

---

## 5. Jeu de données fictif

### 5.1 Répartition des magasins par société

| Répartition | 1 magasin | 2 | 3 | 4 | 5 | 6 à 20 | Moyenne | Magasins pour 10 000 sociétés |
|---|---|---|---|---|---|---|---|---|
| **Réaliste (loi de Zipf tronquée à 20), recommandée** | 62,7 % | 15,7 % | 7,0 % | 3,9 % | 2,5 % | 8,3 % | 2,254 | **22 540** |
| Uniforme de 1 à 20 | 5 % chacune | | | | | | 10,5 | 105 000 |

Avec la loi de Zipf, on obtient environ 16 sociétés de 20 magasins sur 10 000. Mais sur 10 sociétés (palier P1), elle n'en tire presque jamais. **Une société de 20 magasins est donc forcée dès P1**, avec un magasin non aligné et un principal désigné par l'éditeur.

### 5.2 Profils de magasin

Les mesures ci-dessous ont été faites sous SQLite, au commit 204db25 [prouvé ici]. **Elles seront refaites sous PostgreSQL en R0** : les rapports de taille InnoDB donnés dans la version précédente (1,2 à 1,6) ne valent plus, et le contenu signé (environ 3 Ko par ligne) sera compressé par PostgreSQL.

| Profil | Contenu | Lignes | Taille SQLite | Génération |
|---|---|---|---|---|
| Socle (ancien) | 3 membres, 3 fournisseurs, 15 clients, 30 produits (1 achat validé), 10 tickets, 1 facture pro, 1 devis, 1 clôture | 366 | 144 Kio | 1,4 s |
| Type, sur 30 jours | 7 comptes, 50 clients, 200 produits, 30 achats validés, 300 tickets, 30 factures pro, 20 avoirs, 10 devis, 30 clôtures | environ 4 925 | environ 3,5 Mo | 16 s |
| Année | 365 jours à 10 tickets par jour | 52 312 | 40 Mo | 4 min ; vérification fiscale complète en 17 s |

**Socle enrichi**, nouveau profil minimal (décision 14-7). L'ancien socle ne contenait ni avoir, ni paiement partiel, ni contre-passation, ni achat corrigé. Désormais, chaque magasin a au moins :
- les rôles minimaux (5.4) ;
- un service et une remise ;
- un avoir partiel, un paiement partiel et une contre-passation ;
- un achat corrigé et un retour fournisseur ;
- un devis expiré.

Coût à mesurer en R0 [supposé : 1,5 à 2 fois l'ancien socle].

**Profils ciblés à créer en C0** :
- **hors de France** : export, livraisons intracommunautaires, DOM, n° de TVA valides et invalides, détaxe ;
- **contentieux** : devis expirés et modifiés, paiements contre-passés, avoirs multiples, crédits et bons d'avoir, relances ;
- **achats** : corrections de factures validées, retours fournisseur, cas chiffré K1 ;
- **limites** : montants extrêmes, Unicode, emoji, chaînes longues, quantités maximales ;
- **équipe** : membres retirés, droits modifiés, comptes de chaque rôle.

### 5.3 Variété des configurations

| Dimension | Valeurs |
|---|---|
| Régime de TVA | Réel normal mensuel, trimestriel, simplifié, franchise en base (au moins 10 % des magasins), option pour les débits ; principal qui n'a pas encore choisi son régime |
| Territoire | Métropole, Corse, Guadeloupe, Martinique, Réunion (8,5 %). Guyane, Mayotte et hors de France pour vérifier un refus propre. Changement de territoire après des ventes. |
| Plusieurs magasins | Principal le plus ancien, ou désigné par l'éditeur. Au moins un magasin non aligné par société de 3 magasins ou plus. Principal suspendu, fermé ou supprimé. |
| Statut | Actif (95 %), suspendu (3 %), fermé (2 %), modifié en base faute d'écran d'administration ; réactivation |
| Identité légale | Complète, incomplète (B11), sans immatriculation (K7), capital renseigné ou non |
| Paramètres | Délai par défaut (30, 45, 60 jours), délais clients variés, relances activées ou non, barème de prix de vente |
| Clients | Particuliers ; professionnels français (SIREN ou SIRET) ; UE avec n° de TVA valide ou invalide ; hors UE ; comptoir ; archivés |
| Produits et services | Taux 20, 10, 5,5, 2,1, 8,5 et taux libres ; 0 % en franchise ; prix de 0,01 € à la limite ; stock suivi ou non ; seuils d'alerte ; archivés ; services retirés de la caisse |

**Des opérations réparties comme dans la réalité**, pas seulement des volumes : taille des paniers, beaucoup de petits prix et quelques très gros, environ 5 % de retours, paiements partiels, délais de paiement, remises occasionnelles.

### 5.4 Utilisateurs et rôles

La demande parle de « plusieurs administrateurs, responsables et vendeurs par magasin ». La version précédente admettait 0 administrateur et 0 responsable. C'était un écart, et il n'était pas signalé.

**Minimum proposé par magasin** (décision 14-7) :
- 1 propriétaire, au moins 1 administrateur, au moins 1 responsable (modèle administrateur ou comptable, cases cochées sur mesure) et au moins 2 vendeurs : **5 comptes au moins** ;
- dans les magasins de profil type : 2 administrateurs, 2 responsables, 3 à 8 vendeurs (avec ou sans « voir les factures de toute l'équipe ») et 1 lecteur, soit 11,5 comptes en moyenne.

Moyenne du scénario étagé : 0,9 × 5 + 0,1 × 11,5 ≈ 5,7 comptes par magasin, soit **environ 127 000 comptes pour 10 000 sociétés**.

Variante « au moins 2 de chaque rôle » : 7 comptes au minimum et environ 7,5 en moyenne, soit environ 168 000 comptes.

### 5.5 Génération

- **Les vraies actions pour tout ce qui porte une règle.** Clients et fournisseurs passent par l'action si elle existe, sinon par le modèle.
- **Seule exception** : l'empreinte bcrypt précalculée d'un mot de passe connu. Un échantillon de comptes garde un vrai hachage pour les tests de sécurité.
- **Chronologie commune.** Chaque magasin termine son historique au même instant T0, avec ses clôtures produites au fil de l'eau. Les magasins « année » commencent donc plus tôt. Sans cela, deux problèmes :
  - l'oracle, les tâches de nuit et k6 se heurtent au refus d'une horloge qui recule ;
  - la clôture automatique rattrape d'un coup des journées vides. J'ai mesuré 1 337 clôtures pour 191 magasins sur 7 jours [prouvé ici], soit des millions pour 22 540 magasins.
- **Registre des intentions.** Le générateur note chaque opération voulue, une par intention, rejeux compris. Il note aussi ses entrées : fiche produit et prix au moment de la vente, quantité, type de client. L'oracle s'en sert (8.3).
- **Une graine par processus.** Chaque processus génère une plage de sociétés distincte. La concurrence au sein d'un même magasin relève des scénarios de stress (7.1).
- **Préchauffage** par un premier magasin seul, tant qu'A6 n'est pas corrigée.
- **Chaque erreur est journalisée immédiatement.** Les conflits sont réessayés au plus 3 fois, **et comptés comme anomalies**. Le générateur s'arrête si la base disparaît ou si le disque libre passe sous 3 Go.
- **Un instantané compressé** de la base (`pg_dump -Fc`) à chaque palier.
- **Non-déterminisme restant**, documenté : identifiants UUID, signatures ECDSA (même contenu, signature différente), entrelacement des processus. Le tirage aléatoire des produits (`inRandomOrder`) est remplacé par un tirage avec graine.

### 5.6 Garantie du caractère fictif

- **Identifiants générés.**
  - SIREN : `000` + 5 chiffres + clé de Luhn ; SIRET et n° de TVA dérivés avec leur clé.
  - 60 identifiants sur 60 sont valides au contrôle indépendant [prouvé ici].
  - Aucun ne peut coïncider avec les entreprises du bac à sable SUPER PDP (000000001, 000000002).
  - Reste à confirmer, dans le fichier Sirene de l'INSEE, que la plage `000…` n'est attribuée à personne (décision 14-14).
  - Le chevauchement de plages repéré dans le script parallèle sera corrigé.
- **La suite du dépôt n'est pas couverte par cette garantie.** `tests/TestCase.php` utilise le SIREN 732829320 et le n° de TVA FR44732829320, qui passent la clé de Luhn [constaté]. Il faut vérifier dans Sirene qu'ils n'appartiennent à aucune entreprise réelle, ou les déplacer dans la plage retenue.
- **Le reste.**
  - E-mails en `@piliqi-fictif.test`, domaine réservé par les RFC 2606 et 6761. Aucun envoi réel.
  - Téléphones laissés vides.
  - Raisons sociales préfixées « FICTIF ».
  - Noms de personnes tirés par Faker, sans autre identifiant réel attaché.
  - N° de TVA étrangers connus du seul VIES simulé.

---

## 6. Catalogue de scénarios

Chaque scénario a un code. Le rapport le relie à son test, son moteur, sa graine, son résultat et ses journaux. Les codes INV-… renvoient à la section 8.

### 6.1 Scénarios par module (campagne C1)

**MAG : magasins**
- **MAG-01** : l'inscription crée le magasin, le propriétaire, le rôle, le client comptoir, et l'événement 260 en premier. Inscriptions simultanées (A3, A6).
- **MAG-02** : code proposé depuis le nom ; collisions ; code de 10 caractères ; code définitif dès le premier document (J1) ; changement de code pendant une première numérotation.
- **MAG-03** : SIRET jamais réutilisé, même celui d'un magasin supprimé (J7). Aucun magasin n'est supprimé définitivement.
- **MAG-04** : magasin suspendu ou fermé (A2).
  - Chaque écriture **hors de la liste `PermissionRegistry::permisesEnLectureSeule()`** est refusée côté serveur.
  - Cette liste laisse permises `equipe.creer`, `equipe.modifier` et `cloture_caisse.gerer` [constaté]. Le test suit la liste. Pouvoir créer un membre dans un magasin suspendu vous est soumis (décision 14-17(c)).
  - Consultation et téléchargements restent possibles.
  - Aucun e-mail ne part, **pas même ceux déjà en file** (`EnvoyerDocumentJob`) au moment de la suspension.
  - Les clôtures, le passage en retard (00:15) et l'expiration des devis (00:30) continuent.
  - La réactivation est testée.
- **MAG-05** : territoire refusé (Guyane, Mayotte, hors de France, refusés par `IdentiteMagasin`), avec message ; changement de territoire après des ventes (taux par défaut).

**SOC : sociétés à plusieurs magasins**
- **SOC-01** : magasin principal, le plus ancien non supprimé ou celui que désigne l'éditeur.
  - Principal suspendu, fermé ou supprimé, puis bascule vers le suivant et réalignement des autres.
  - Deux magasins créés au même instant.
  - Désignation par l'éditeur pendant des émissions.
  - Régime que le principal n'a pas encore choisi.
- **SOC-02** : **chaque réglage de `ReglagesCommunsDeLaSociete::CHAMPS`, un par un** : régime, option pour les débits, n° de TVA, raison sociale, forme juridique, capital, immatriculation, mois de fin d'exercice. Pour chacun : refus à l'enregistrement (J2), puis blocage de l'émission des factures et des tickets (K5).
- **SOC-03** : magasin qui quitte la société ou change de SIREN (`refusDeQuitterLaSociete`).
- **SOC-04** : J5, aucun transfert de marchandises. Le stock n'est jamais commun. Un produit, un client ou un fournisseur d'un autre magasin du SIREN est inutilisable, même par une requête forgée.
- **SOC-05** : J6, un retour ou un paiement est refusé dans un autre magasin de la société.
- **SOC-06** : **usurpation et sondage d'un SIREN.**
  - Rien ne vérifie qu'un magasin possède le SIREN qu'il déclare.
  - Un magasin peut donc indiquer le SIREN d'une autre société cliente. Il lit alors la valeur du principal dans le message `messageDuChamp` (« prenez « … » ») [constaté].
  - Il peut aussi deviner les réglages par essais successifs, grâce à la liste des réglages différents que renvoie `messageDuSirenPourRejoindre`.
  - S'il s'inscrit avant la vraie société, il en devient le principal. À l'étape 2, il recevrait alors les factures adressées à la société sans magasin précis (J4).
  - Les deux exceptions d'isolation du CLAUDE.md (champs limités, filtre par SIREN) reçoivent un test dédié.
  - Décision 14-17(e).
- **SOC-07** : un refus de code à l'inscription révèle qu'un autre client l'utilise déjà, puisque le code est unique dans toute la base. Décision 14-17(d).

**EQU : comptes, authentification, équipe**
- **EQU-01** : une adresse déjà utilisée est refusée pour un nouveau membre (A1), quelles que soient la casse et les espaces. D'après son plan, le lot PostgreSQL enregistre les adresses en minuscules. Le refus vaut aussi au **changement d'adresse dans le profil** (H1), et pour la même adresse créée en même temps dans deux magasins.
- **EQU-02** : le membre modifie son profil en donnant son mot de passe actuel (H1).
- **EQU-03** : le rôle n'est qu'un modèle. Chaque case cochée ou décochée prend effet (G4). Les actions réservées au propriétaire le restent.
- **EQU-04** : **élévation de privilèges.** Un administrateur qui a `equipe.modifier` ou `equipe.creer` ne peut :
  - ni réinitialiser le mot de passe du propriétaire (`ReinitialiserMotDePasseMembreAction`) ;
  - ni modifier ni retirer le propriétaire ;
  - ni se retirer lui-même ;
  - ni donner une autorisation qu'il n'a pas ;
  - ni changer un rôle au-delà de ses droits.

  Le cas du dernier propriétaire est aussi traité.
- **EQU-05** : membre retiré. Il perd tout accès immédiatement, session ouverte comprise. `RetirerMembreMagasinAction` ne supprime que son appartenance au magasin : son compte peut-il inscrire un magasin ? Son adresse reste-t-elle gelée ?
- **EQU-06** : l'inscription est ouverte à tous, sans vérification de l'adresse (`User` n'implémente pas `MustVerifyEmail` [constaté]). Quelqu'un peut donc occuper l'adresse d'un tiers, qui ne pourra plus jamais servir (A1). Décision 14-17(e).
- **EQU-07** : connexion limitée à 5 tentatives par minute ; limitation de l'inscription et de la réinitialisation du mot de passe ; jeton de réinitialisation à usage unique et périmé ; même message que l'adresse existe ou non ; session régénérée à la connexion.
- **EQU-08** : un événement 130 à chaque création, modification ou retrait de membre.

**FIC : clients, fournisseurs, produits**
- **FIC-01** : SIREN obligatoire pour un professionnel français (9 ou 14 chiffres), identifiant étranger sinon (B12).
- **FIC-02** : archiver au lieu de supprimer. La fiche n'est plus proposée, reste lisible sur les anciens documents et se restaure. Le client comptoir ne s'archive jamais (B19).
- **FIC-03** : références uniques par magasin sans tenir compte de la casse (décision du lot PostgreSQL) ; références PRD automatiques continues ; SIRET fournisseur unique.
- **FIC-04** : un changement de taux d'un produit écrit l'événement 270, et la vente suivante prend le nouveau taux (B1).
- **FIC-05** : la recherche ignore la casse et les accents (« creme » trouve « Crème »), sur les deux moteurs.

**SRV : services**
- **SRV-01** : création à la volée (`TrouverOuCreerServiceAction`), y compris deux créations simultanées du même service.
- **SRV-02** : « retirer de la caisse » au lieu d'archiver.
- **SRV-03** : prix et taux libres sur une ligne de service ; `frais_accessoires` et `lieu_specifique` (lot 19).
- **SRV-04** : mention biens et services, et mention de l'option pour les débits sur la facture ; séparation des biens et des services dans les clôtures.

**DEV : devis**
- **DEV-01** : seul un brouillon jamais numéroté se supprime (B14).
- **DEV-02** : un devis envoyé dont la validité est dépassée expire automatiquement à 00:30, et ne peut plus être accepté (B15).
- **DEV-03** : modifier un devis envoyé ou accepté le repasse en brouillon et efface son acceptation (K8).
- **DEV-04** : un devis refusé, expiré ou converti reste figé. Une seule facture par devis, même pour deux conversions simultanées (L11).

**FAC : factures et tickets**
- **FAC-01** : calcul pour un particulier, article par article depuis le prix TTC ; pour un professionnel, selon E1 ; franchise à 0 % au même TTC avec la mention 293 B (B10) ; DOM à 8,5 % ; vente exonérée au prix hors taxe (K3).
- **FAC-02** : refus d'émettre dans quatre cas : identité légale incomplète (B11, K7), SIREN du client manquant (B12), magasin non aligné (K5), journée déjà clôturée (K4). Les tickets restent possibles malgré une identité incomplète. **Ordre des refus** quand plusieurs conditions manquent (lot 21).
- **FAC-03** : client figé à l'émission (B13). Modifier ensuite la fiche ne change ni la facture ni son PDF.
- **FAC-04** : échéance = date d'émission + délai du client, sinon délai du magasin (B7). Testée autour des changements d'heure, des fins de mois et du 29 février 2028.
- **FAC-05** : mentions réglables entre professionnels (B9) ; capital imprimé seulement s'il est renseigné (H2).
- **FAC-06** : numéros au format `FAC-CODE-AAAA-NNNNNN`, continus et sans doublon. Une facture émise ne se supprime jamais. La suppression d'un brouillon écrit l'événement 320, une ligne retirée l'événement 323.

**REM : remises, escompte, barème** (absents de la version précédente)
- **REM-01** : remise de ligne des ventes (`remise_pourcentage`, de 0 à 100 %, sur les devis, les factures et les avoirs). Croisée avec le calcul E1, la franchise et la vente exonérée (K3). Remise de 100 %.
- **REM-02** : retour partiel (K2) sur une ligne remisée.
- **REM-03** : remise de ligne d'achat et escompte de facture d'achat (`escompte_pourcentage`). Ils donnent le coût net, puis le coût moyen, puis le prix du barème. Cas du lot 17 : 2,35 € remisés de 15 % = 1,9975 €, soit un prix de vente de 14,40 € TTC.
- **REM-04** : l'arrondi du barème n'est appliqué qu'une fois, à l'enregistrement du produit.

**CAI : caisse**
- **CAI-01** : panier conservé par vendeur. L'abandon d'une vente (320) et chaque ligne retirée (323) s'inscrivent au journal (D9).
- **CAI-02** : après la clôture manuelle, refus jusqu'à minuit avec le message prévu (K4), **pour les tickets comme pour les avoirs sur ticket**.
- **CAI-03** : une seule caisse par magasin (D10), même avec plusieurs vendeurs qui vendent en même temps.
- **CAI-04** : mode de paiement préféré présélectionné (B8) ; bon d'avoir du client comptoir proposé et imputé (K6).
- **CAI-05** : horloge qui recule refusée ; montants maximaux ; quantités énormes refusées avant d'atteindre la base (pas d'erreur 22003) ; saisie au format français (virgule, espace insécable).

**AVO : avoirs et retours**
- **AVO-01** : retours partiels successifs jusqu'au solde. Chaque retour vaut la différence entre son cumul et le cumul précédent. Le dernier solde la ligne au centime (K2 : 33,33 € + 6,67 €, puis 33,34 € + 6,66 €).
- **AVO-02** : avoir sur plusieurs factures d'un même client. Jamais de mélange entre tickets et factures, entre franchise et régime normal, ni entre natures d'opération différentes.
- **AVO-03** : jamais de remboursement (B5). Le crédit s'impute automatiquement, de la facture impayée la plus ancienne à la plus récente (B6). L'ordre est vérifié explicitement.
- **AVO-04** : le stock est restauré ; un avoir sur ticket écrit l'événement 190.

**ENC : paiements**
- **ENC-01** : montant du paiement égal au total imputé (B3) ; paiement sur plusieurs factures ; date future refusée.
- **ENC-02** : contre-passation et journées.
  - La contre-passation est liée à l'original et signée, puis suivie du bon paiement (B4). Cas chiffré K9 : 100, puis 10, puis 90.
  - Elle est **datée du jour et ne touche jamais une journée déjà clôturée**.
  - Un paiement ou une contre-passation saisi après la clôture manuelle est rattaché à la bonne journée.
  - **Contre-passer une contre-passation est refusé.** Double contre-passation du même paiement.
- **ENC-03** : une facture en retard le reste tant qu'un montant reste dû (G1).

**CNF : confidentialité des vendeurs** (B16, au-delà de l'encaissement)
- **CNF-01** : un vendeur n'encaisse que ses factures, même en forgeant l'identifiant de la facture d'un collègue.
- **CNF-02** : sans l'autorisation `cloture_caisse.view_toutes`, un membre ne voit que les clôtures qu'il a faites.
- **CNF-03** : avoirs. `AvoirPolicy` se fonde sur le créateur de l'avoir. Une ligne d'avoir prise sur la facture d'un collègue par requête forgée doit être refusée (403, lot 16).
- **CNF-04** : envoi et relance de la facture d'un collègue refusés ; URL du PDF de la facture d'un collègue refusée.
- **CNF-05** : « Mes ventes » quand l'avoir sur sa facture vient d'un autre auteur, et pour un avoir de novembre sur une facture d'octobre (lot 16). Chiffre d'affaires net des avoirs, en HT avec le TTC à côté (B17).
- **CNF-06** : widget de valeur du stock réservé à `produit.voir_valeur_stock`.

**ACH : achats et stock**
- **ACH-01** : les nouveaux produits ne sont créés qu'à la validation. Un brouillon supprimé n'en laisse aucun (C4).
- **ACH-02** : le fournisseur d'un produit est celui du dernier achat validé, même saisi à la main, reconnu par son SIRET (C3).
- **ACH-03** : corriger une facture d'achat validée exige le droit de valider (C2). Une facture venue par l'API n'est jamais modifiable, même avec ce droit. La trace des avoirs fournisseur est conservée (L1).
- **ACH-04** : un retour fournisseur sort au coût de la ligne retournée et recalcule le coût moyen (C1). La valeur du stock ne descend jamais sous 0 € (K1 : 10 achetés à 2 €, 10 à 10 €, 15 vendus, 4 retournés à 10 €).
- **ACH-05** : quantité retournable = min(reliquat, stock) ; coût moyen rejoué depuis le grand livre des mouvements.
- **ACH-06** : alerte de stock bas, une par franchissement du seuil, aux bons destinataires (G5).
- **ACH-07** : fournisseur ou produit archivé entre le brouillon d'achat et sa validation (refus du lot 17).

**JOU : journées, clôtures, caisse personnelle**
- **JOU-01** : bouton de clôture soumis à l'autorisation, avec la fenêtre de confirmation (D3) ; clôture automatique à minuit.
- **JOU-02** : rattrapage des journées manquées dans l'ordre ; un Z par jour du calendrier à partir de la première vente, même à zéro (D2, J8).
- **JOU-03** : le Z du 31 décembre est numéroté dans l'année de la journée clôturée (D7).
- **JOU-04** : « Fermer ma caisse » détaille le TTC vendu, les encaissements par mode, le crédit d'avoir utilisé et le reste dû (B18). Double clic (L6).
- **JOU-05** : caisses personnelles et clôture du jour. `VerificateurCaissesPersonnelles` bloque la clôture manuelle tant qu'une caisse personnelle ou un encaissement du jour n'est pas fermé. Trois cas :
  - un vendeur absent ou retiré a laissé sa caisse ouverte : le blocage dure-t-il sans fin ?
  - la clôture automatique de minuit dans ce même cas ;
  - une contre-passation saisie par le propriétaire revient dans le relevé du vendeur d'origine (lot 16).

  Décision 14-17(i) si le comportement n'est pas tranché.

**FIS : registre fiscal et journal des événements**
- **FIS-01** : chaînage, signatures, séquences, grands totaux. `caisse:verifier --complet` est lancé en fin de palier (8.1).
- **FIS-02** : codes du journal :
  - 260, en premier ;
  - 130 ;
  - 270, pour un produit, un régime, l'option pour les débits ou une rotation de clé ;
  - 80 et 40 ; 110 ;
  - 250 et 255, au changement de `ModuleFiscal::VERSION` ;
  - 280, 90 et 95, écrits par le vérificateur ;
  - 320, 323 et 190.
- **FIS-03** : altération volontaire d'une copie de la base.
  - Le vérificateur doit signaler chaque cas.
  - Les déclencheurs bloquent la modification (UPDATE), la suppression (DELETE) et le vidage (TRUNCATE), sous SQLite et sous PostgreSQL.
  - Le compte de l'application ne peut ni modifier, ni supprimer, ni vider, ni désactiver un déclencheur (deux rôles, 7.8).
- **FIS-04** : rotation de clé, y compris pendant des émissions ; clé fiscale absente ou illisible au moment d'émettre ; journal identique sur les deux moteurs (A7).
- **FIS-05** : commande `DiagnostiquerCaisseSecurisee` ; widgets `ConformiteApercu`, `VentesEnAttenteApercu` et `CaissesPersonnellesOuvertesApercu`.

**MEL : e-mails et relances**
- **MEL-01** : plafonds de 60 e-mails par heure et par membre et de 200 par heure et par magasin, y compris sous concurrence et pour les relances automatiques (G2, L5).
- **MEL-02** : une relance par jour et par facture, dans l'ordre et en respectant les écarts (G3). Aucune relance d'une facture soldée. Aucun e-mail pour un magasin suspendu.
- **MEL-03** : droit d'envoi par autorisation (G4) ; job réessayé sans e-mail en double ; notification d'échec ; aucun retour à la ligne injecté dans l'objet.

**INT : ventes hors de France** (lots 18 à 20, d'après leur plan)
- **INT-01** : chaque nature d'opération n'exonère que si toutes ses conditions sont réunies (I1). Une seule nature par document. La seconde vague (OSS, export enlevé par le client) est déclarée non construite.
- **INT-02** : tests en tableau des exceptions de `TerritoireFiscal` : ES 35, 38, 51, 52 ; FI 22 ; GR 63086 ; DE 27498 et 78266 ; IT 23041 et 22061 ; collectivités d'outre-mer ; MC ; GB-BT et XI.
- **INT-03** : VIES simulé.
  - Réponses : valide, invalide, indisponible, délai dépassé, illisible.
  - Quotas `MS_MAX_CONCURRENT_REQ` et `GLOBAL_MAX_CONCURRENT_REQ`, et nouvelle tentative `retry(2, 500)` sous charge.
  - Consultation datée et conservée. Sans n° valide le jour même, la vente reste à la TVA française (I2).
  - Devis d'export converti alors que la consultation date de la veille.
- **INT-04** : retour partiel sur une vente exonérée (500 sur 1 000, soit 416,67 €) ; prix hors taxe (K3).
- **INT-05** : références des preuves enregistrées et rappels de celles qui manquent (I4) ; échéance de l'attestation de l'acquéreur au 10 du mois suivant.
- **INT-06** : détaxe. Le ticket reste à la TVA française et porte le n° du bordereau. La dernière note fait foi. Le n° est absent du PDF du ticket. Avertissement à partir de 100 € (I3).

**PDF**
- **PDF-01** :
  - montants identiques aux valeurs enregistrées, sans recalcul ;
  - mentions ; client figé ;
  - « Payé / Restant dû » visible sur la fiche ;
  - contenus hostiles (HTML, très longues chaînes) rendus sans faille ;
  - fichiers rangés sous le bon magasin.

**PLA : tâches planifiées**
- **PLA-01** : **00:00** clôtures, **00:15** retards, **00:30** expiration des devis, **01:00** e-reporting, **03:00** vérification, **09:00** relances, à l'heure de Paris. Y compris les nuits du 25 octobre 2026 et du 28 mars 2027, et des ventes pendant l'heure répétée (de 02:00 à 03:00 le 25 octobre).
- **PLA-02** : deux planificateurs en même temps (L10) ; reprise après trois jours d'arrêt, expiration des devis comprise ; durée des tâches à l'échelle du palier.

**LIM : limites numériques et calendrier**
- **LIM-01** : le compteur NNNNNN au millionième document d'une année (FAC-CODE-2026-1000000). Même question pour les numéros de Z et les références PRD.
- **LIM-02** : serveur réglé sur un autre fuseau que Paris. Horodatages produits par la base elle-même (`useCurrent()` sur `mouvements_stock.created_at`, `enregistrements_fiscaux.created_at`, `evenements_transmission_pdp.survenu_le` et `failed_jobs.failed_at` [constaté]) face à l'horloge simulée.
- **LIM-03** : montants proches de la limite de `decimal(12,2)` ; quantités maximales.

**CFG : configuration**
- **CFG-01** : deux configurations jouées (décision 14-6).
  - La configuration livrée : cache, sessions et files d'attente en base (`CACHE_STORE`, `SESSION_DRIVER` et `QUEUE_CONNECTION` dans `.env.example`). Le cache des autorisations d'A4 y serait relu depuis la table `cache`, et la table `jobs` est partagée par tous les magasins.
  - La configuration Redis.
- **CFG-02** : connexions derrière une seule adresse IP.
  - La limite de connexion de Filament (5 par minute) ne dépend que de l'adresse IP, et `bootstrap/app.php` ne déclare aucun proxy de confiance [constaté].
  - Derrière un répartiteur de charge, tous les magasins partageraient donc la même adresse, et 6 connexions par minute suffiraient à bloquer tout le monde. Une boutique de 8 vendeurs derrière une même box à l'ouverture est aussi concernée.
  - Scénario : nginx en proxy inverse avec `X-Forwarded-For`, tous les utilisateurs virtuels derrière une seule adresse.
  - Le contournement `--local-ips` de la reconnaissance cachait exactement ce risque.

### 6.2 Parcours de bout en bout

| Code | Parcours | Décisions principales |
|---|---|---|
| PBE-01 | Journée complète d'un magasin : achats validés, ventes en caisse, facture pro remisée, paiement partiel, avoir partiel, crédit imputé, clôture manuelle, tentative de vente refusée, minuit, clôture automatique le lendemain, vérification | B3, B6, K2, K4, D3, D2 |
| PBE-02 | Société de 20 magasins : principal, changement de régime, magasins bloqués puis alignés, numéros par code, retour refusé dans un autre magasin, SIRET d'un magasin fermé, principal supprimé | J1, J2, K5, J5, J6, J7 |
| PBE-03 | Achat remisé avec escompte, barème, vente, retour client, retour fournisseur, correction d'achat validée | C1 à C4, K1 |
| PBE-04 | Erreur de paiement : contre-passation, bon paiement, crédit restant, tentative de contre-passer la contre-passation | B4, K9 |
| PBE-05 | Devis : envoi, acceptation, modification, retour en brouillon, expiration à 00:30, conversion ; taux du produit et client modifiés entre-temps | B13, B14, B15, K8 |
| PBE-06 | Fin d'année et changements d'heure : ventes à 23:59:59 le 31/12, Z de l'ancienne année, nouvelles séries | D7, J8 |
| PBE-07 | Suspension en cours de journée, avec des e-mails en file, puis réactivation | A2 |
| PBE-08 | Vie de l'équipe : embauche, droits cochés, changement d'adresse, tentative d'élévation de privilèges, retrait, adresse réutilisée refusée | A1, H1, G4 |
| PBE-09 | Impayés sur 90 jours simulés : échéances, retards, paiements partiels, relances et plafonds | B7, G1, G2, G3 |
| PBE-10 | Ventes hors de France avec VIES en panne puis rétabli | I1 à I4, K3, B1 |
| PBE-11 | Franchise en base et DOM : mêmes prix TTC, mentions, taux ; passage de la franchise au réel entre le brouillon et l'émission | B2, B10 |
| PBE-12 | Vendeurs confidentiels : visibilité, encaissement, avoirs, « Mes ventes », relevé, vendeur absent à la clôture | B16, B17, B18 |
| PBE-13 | Client comptoir : avoir, bon d'avoir réutilisé, deux tentatives simultanées | K6, B5 |
| PBE-14 | Une année d'un magasin, puis vérification intégrale | tout le registre |
| PBE-15 | Usurpation d'un SIREN par une inscription faite avant celle de la vraie société | J2, J4 |
| PBE-16 et suivants | Facture électronique entre deux magasins PILIQI via le simulateur (C2), résiliation (C3, C6), mois comptable jusqu'au FEC (C4), parcours par l'API (C6) | |

### 6.3 Combinaisons de configurations

Le produit complet des dimensions de 5.3 est trop grand. On procède en trois temps.
1. **Toutes les paires de valeurs** apparaissent au moins une fois, parmi ces dimensions : régime, franchise, territoire, type de client, nature d'opération, option pour les débits, plusieurs magasins, statut, rôle, remise, configuration du cache et des files. Chaque configuration ainsi générée joue PBE-01.
2. **Le produit complet** est joué sur les dimensions qui changent les montants : type de client, franchise, nature d'opération, territoire et remise (nulle, partielle, 100 %). Chaque cas est comparé à l'oracle.
3. Toute combinaison qui a trouvé une anomalie devient un cas fixe.

### 6.4 Sécurité

- **SEC-01, matrice d'autorisations générée.**
  - Générée depuis `PermissionRegistry::toutes()`, `permisesEnLectureSeule()`, `RoleMagasin::permissionsParDefaut()` et les actions Filament découvertes. Le nombre de 43 autorisations, écrit en dur dans la version précédente, était exact au commit c2845e2, mais le lot 14 ajoute `paiement.contre_passer`.
  - Pour chaque autorisation : un membre qui a tout sauf elle est refusé ; un membre qui n'a qu'elle est accepté. Chaque modèle de rôle est joué, ainsi que les actions réservées au propriétaire et le magasin suspendu.
  - **Résultat attendu validé hors du code** : un tableau « autorisation × action » relu par vous. Sinon, la matrice ne ferait que recopier les policies.
  - Exécutée avant **et** après la correction d'A4, qui réécrit le contrôle des droits : la comparaison prouve que la réécriture n'ouvre aucun accès.
  - La CI échoue si une action nouvelle n'a pas de ligne.
- **SEC-02** : accès à un autre magasin par l'URL, pour toutes les ressources et actions, y compris le PDF, l'envoi d'e-mail et l'export du journal.
- **SEC-03, requêtes Livewire forgées** :
  - identifiants d'un autre magasin ;
  - appel de méthodes non affichées ;
  - quantités négatives ou énormes ;
  - **valeurs envoyées dans des champs désactivés à l'écran** (PU HT et PU TTC des lignes de produit, taux d'une ligne de produit) ;
  - remise hors de l'intervalle 0 à 100.
- **SEC-04** : magasin suspendu, refus côté serveur selon la liste de lecture seule.
- **SEC-05** : sonde SQL pendant toute la simulation. Toute lecture d'une table de magasin sans filtre par magasin est signalée. La **valeur** du filtre doit en plus être celle du magasin courant.
- **SEC-06, injections** : SQL par la recherche, le tri et la fonction de recherche sans accents ; HTML stocké dans les tableaux et les PDF ; formules dans l'export CSV ; retour à la ligne dans l'objet d'un e-mail.
- **SEC-07, fichiers** : routes `storage/{path}` (signature, expiration, chemin d'un autre magasin, `../`) ; envois de fichiers par Livewire ; routes d'export et d'import Filament inutilisées, inaccessibles.
- **SEC-08** : secrets absents des journaux, des pages d'erreur, des jobs échoués et des exports. **Pas de données personnelles dans les journaux** : n° de TVA et réponses de VIES, que le lot 18 interdit d'écrire.
- **SEC-09** : cookies, en-têtes HTTP, `composer audit`.
- **SEC-10** : jobs de magasins différents enchaînés dans un même worker, sans fuite de contexte.
- **SEC-11** : actions groupées Filament (`SupprimeEnMasseLigneParLigne`) sur une sélection qui mêle des enregistrements permis et interdits.
- **SEC-12** : notifications appartenant à un autre utilisateur.
- **SEC-13** : canaris (8.4) pendant toute la simulation.
- **SEC-14** : rotation de la clé de l'application (`APP_KEY`), qui touche les URL signées et les futurs jetons SUPER PDP chiffrés. Comportement attendu documenté.

### 6.5 Robustesse par module

Voir la section 7 : chaque action critique passe par l'exploration des points d'arrêt et des entrelacements.

### 6.6 Les 85 décisions : ce que la campagne vérifie et quand

La matrice complète (décision, scénario, test, moteur, état, journal) est **générée** depuis `decisions-editeur.md` et la section « Décisions de l'éditeur » du CLAUDE.md. La CI échoue si une décision n'a ni test, ni mention « attendu en échec », ni mention « non testable ». En résumé :

| Décision | Vérification | Campagne |
|---|---|---|
| A1 | Adresse déjà utilisée refusée, y compris au changement de profil et en simultané (EQU-01, EQU-06) | C1 |
| A2 | Lecture seule selon la liste, aucun e-mail (même déjà en file), statuts maintenus (MAG-04, PBE-07) | C1 en partie ; transmissions C2 ; résiliation C3 et C6 |
| B1 | Taux de la fiche à la vente (FIC-04, INT-01) | C1 |
| B2 | 20 % par défaut, 8,5 % en Guadeloupe, Martinique et Réunion (PBE-11) | C1 |
| B3 | Paiement = total imputé (ENC-01) | C1 |
| B4 | Contre-passation liée, signée, datée du jour (ENC-02) | C1 ; dans les clôtures C3 |
| B5 | Aucun remboursement (AVO-03) | C1 |
| B6 | Crédit imputé de la plus ancienne à la plus récente (AVO-03) | C1 |
| B7 | Échéance à l'émission (FAC-04) | C1 |
| B8 | Mode préféré présélectionné (CAI-04) | C1 |
| B9 | Mentions entre professionnels (FAC-05) | C1 |
| B10 | Franchise à 0 %, même TTC, mention 293 B (FAC-01) | C1 ; e-reporting C2 |
| B11 | Identité légale exigée pour un professionnel (FAC-02) | C1 |
| B12 | SIREN du client professionnel (FIC-01) | C1 |
| B13 | Client figé à l'émission (FAC-03) | C1 |
| B14 | Seul un brouillon jamais numéroté se supprime (DEV-01) | C1 |
| B15 | Expiration automatique à 00:30 (DEV-02, PLA-01) | C1 |
| B16 | Confidentialité des vendeurs (CNF-01 à CNF-06) | C1 |
| B17 | Chiffre d'affaires net, HT avec TTC (CNF-05, oracle) | C1 ; rapports C5 |
| B18 | Relevé de caisse détaillé (JOU-04, oracle) | C1 ; clôtures C3 |
| B19 | Archivage restaurable (FIC-02) | C1 |
| C1 | Retour au coût de la ligne, coût moyen recalculé (ACH-04) | C1 |
| C2 | Correction réservée au droit de valider, facture API figée (ACH-03) | C1 |
| C3 | Fournisseur du dernier achat (ACH-02) | C1 ; réception C2 |
| C4 | Produits créés à la validation (ACH-01) | C1 |
| D1 | Toutes les ventes dans les clôtures et les grands totaux | C3 |
| D2 | Z à zéro chaque jour (JOU-02) | C1 ; signé et archivé C3 |
| D3 | Clôture manuelle selon les droits, automatique à minuit (JOU-01) | C1 |
| D4 | Fin d'exercice réglable, verrouillée après la première vente | C3 |
| D5 | Conservation 10 ans sans purge | C3, C5, CF |
| D6 | Clôture et archive finales à la résiliation | C3, C6 |
| D7 | Z du 31/12 dans l'année de la journée (JOU-03) | C1 |
| D8 | PDF tel qu'émis, mention « Duplicata » | C3 |
| D9 | Abandons et lignes retirées au journal (CAI-01) | C1 |
| D10 | Une seule caisse par magasin (CAI-03) | C1 |
| D11, D12 | Attestation par magasin, signature du volet 2 | C3 |
| D13 | Extensions openssl et zip déclarées (contrôle statique) | C1 |
| E1 | Calcul professionnel, remises comprises (FAC-01, REM-01) | C1 ; contrôles BR-CO à la transmission C2 |
| E2 | Ordre de la feuille de route | Non testable |
| E3 à E15 | SUPER PDP | C2 |
| F1 | Aucun paquet inutilisé (contrôle statique) | C1 |
| G1 à G5 | Retard après paiement partiel, plafonds, rythme, droits d'envoi, destinataires des alertes | C1 |
| H1 | Profil modifié avec le mot de passe actuel (EQU-02) | C1 |
| H2 | Capital imprimé s'il est renseigné (FAC-05) | C1 |
| H3, H4 | Licence propriétaire, aucun outil Node (contrôles statiques) | C1 |
| I1 | Natures d'opération (INT-01, INT-02) | C1 en partie (seconde vague non construite) |
| I2, I4 | VIES, preuves (INT-03, INT-05) | C1 |
| I3 | N° de bordereau sur le ticket (INT-06) | C1 ; régularisation C4 |
| J1 | Code magasin (MAG-02, SOC-07) | C1 |
| J2 | Réglages communs, un par un (SOC-02) | C1 |
| J3, J4 | Connexion PDP par magasin, facture sans magasin précis | C2 |
| J5 | **Aucun transfert de marchandises entre magasins** (SOC-04) | C1 |
| J6 | Retour ou paiement dans le magasin vendeur (SOC-05) | C1 |
| J7 | SIRET jamais réutilisé (MAG-03) | C1 |
| J8 | Z chaque jour du calendrier (JOU-02) | C1 |
| K1 à K9 | Valeur jamais négative, retours partiels, prix hors taxe, refus après clôture (tickets et avoirs sur ticket), alignement, bon d'avoir, immatriculation, devis accepté, contre-passation | C1 |
| K10 | Redépôt ne corrigeant que les données de transmission | C2 |

**Décompte** :
- 61 décisions en C1, dont 10 partielles : A2, B4, B10, B17, B18, C3, D2, E1, I1, I3 ;
- 23 plus tard : D1, D4, D5, D6, D8, D11, D12, E3 à E15, J3, J4, K10 ;
- 1 non testable : E2.

---

## 7. Concurrence et robustesse

### 7.1 Trois niveaux

1. **Courses déterministes**, dans le groupe PHPUnit `concurrence`, sous PostgreSQL 17 : d'abord en READ COMMITTED, puis en REPEATABLE READ, puis un essai en SERIALIZABLE. Deux méthodes :
   - **rejouer les courses connues** (7.2), avec des pauses forcées, comme pour la preuve de F2 ;
   - **explorer systématiquement les entrelacements** : pour chaque paire d'actions qui partagent une ressource, le processus A s'arrête après chacune de ses requêtes pendant que B s'exécute en entier, puis on contrôle les invariants. Un tirage au hasard ne tombe presque jamais dans une fenêtre de quelques microsecondes : F2 n'a été obtenu qu'avec une pause de 3 s.
2. **Stress aléatoire avec points chauds.** 16 à 64 processus.
   - Au moins 30 % des opérations portent sur 1 % des magasins.
   - Plusieurs processus agissent sur le même magasin, le même SIREN, les mêmes factures et les mêmes clients.
   - Les tables communes à tous les magasins sont sollicitées : `users`, `magasins` (code et SIRET), autorisations, `cache`, `jobs`, `sessions`.

   Sans points chauds, des plages de sociétés séparées ne trouvent que les conflits sur ces tables communes.
3. **Niveau HTTP** (k6) : écritures Livewire (vente en caisse, paiement, avoir) sur au moins trois parcours, attaquants, voisin bruyant.

**Invariant structurel**, vérifiable sans charge : toute action qui écrit dans le stock, les paiements, les avoirs, la numérotation ou les clôtures prend d'abord le verrou du registre de son magasin. Concrètement, sa première lecture verrouillée porte sur `tetes_registre_fiscal`. Un test observe l'ordre des requêtes de chaque action d'une liste tenue à jour. Une action nouvelle absente de la liste fait échouer la CI.

### 7.2 Courses à attaquer

La liste des paires est **générée** : chaque paire parmi une vingtaine d'actions critiques qui partagent une ressource (document, client, produit, journée, membre, magasin, SIREN, adresse e-mail). La liste ci-dessous sert de point de départ et de rappel.

| Code | Course |
|---|---|
| CR-01 | Deux paiements sur la même facture (F2) |
| CR-02 | Paiement et imputation d'un avoir sur la même facture |
| CR-03 | Deux avoirs sur les mêmes lignes |
| CR-04 | Deux émissions qui consomment le même crédit (B6) |
| CR-05 | Le même bon d'avoir utilisé dans deux ventes au comptoir (K6) |
| CR-06 | Premier numéro d'une série (F3) : le 1er janvier, pour un nouveau magasin, pour des produits sans référence |
| CR-07 | Changement de code magasin pendant la première numérotation |
| CR-08 | Deux conversions du même devis (L11) |
| CR-09 | Modification ou acceptation d'un devis pendant sa conversion ou **pendant l'expiration de 00:30** |
| CR-10 | Vente et validation d'achat sur les mêmes produits, en ordre inverse (L3) |
| CR-11 | Retour fournisseur et vente du dernier article |
| CR-12 | Correction d'un achat validé et avoir fournisseur (L1) |
| CR-13 | Clôture manuelle pendant des ventes en cours (K4) |
| CR-14 | Clôture automatique de minuit et vente à 23:59:59 |
| CR-15 | Double clic sur « Fermer ma caisse » (L6), et fermeture pendant la clôture |
| CR-16 | Paiement sur plusieurs factures pendant le marquage des retards de 00:15 et une fermeture de caisse |
| CR-17 | Inscriptions et créations de membres simultanées (A3, A5, A6) |
| CR-18 | Envois simultanés au plafond (G2) ; relance manuelle et automatique en même temps |
| CR-19 | Suspension du magasin pendant une vente |
| CR-20 | Changement d'un réglage commun par le principal pendant une émission dans un autre magasin (K5) |
| CR-21 | Double contre-passation du même paiement |
| CR-22 | Droits retirés pendant qu'un membre agit |
| CR-23 | Taux de TVA d'un produit modifié pendant une vente |
| CR-24 | Archivage d'une fiche pendant son ajout à une vente |
| CR-25 | Deux planificateurs en même temps (L10) |
| CR-26 | Même SIRET ou même SIREN inscrit en même temps (J7, deux candidats au rôle de principal) |
| CR-27 | Même adresse e-mail dans deux magasins (A1) |
| CR-28 | Deux clôtures manuelles simultanées ; clôture manuelle et automatique à 00:00 |
| CR-29 | Deux ventes qui franchissent ensemble le seuil d'alerte (G5 : une seule alerte) |
| CR-30 | Barème modifié pendant une validation d'achat |
| CR-31 | Vérification de 03:00 pendant des ventes |
| CR-32 | Création simultanée du même service (`TrouverOuCreerServiceAction`) |
| CR-33 | (C2) Renouvellement concurrent des jetons SUPER PDP ; rangement concurrent d'une facture reçue entre magasins du même SIREN |

**Résultat attendu pour chaque course** :
- aucun doublon, aucune écriture partielle, aucun invariant violé ;
- l'utilisateur reçoit soit un succès, soit un refus métier clair ;
- un conflit technique prévisible (codes 40001, 40P01, 23505) est réessayé de façon transparente, ou traduit en message clair ;
- **aucune erreur 500** pour un conflit prévisible.

### 7.3 Milliers d'utilisateurs simultanés de sociétés différentes

Mesures de la reconnaissance, faites sous MariaDB sur une machine chargée, **à refaire sous PostgreSQL** :
- page de connexion : environ 115 requêtes par seconde ;
- pages authentifiées : environ 13 par seconde à 60 utilisateurs virtuels, processeur saturé.

Plan :
- **Au niveau des actions** : des milliers d'utilisateurs distincts actifs pendant un essai, mais au plus 16 à 64 transactions réellement simultanées.
- **Au niveau HTTP** : 1 000 à 2 000 sessions ouvertes, avec un temps de réflexion de 10 à 30 s. La demande dépasse alors la capacité de la machine : on teste les files d'attente, les délais et les erreurs 502, pas la capacité. k6 tourne sur un processeur, l'application sur les trois autres.
- **Voisin bruyant** : une société lourde (exports du journal, vérification complète, nombreux PDF, facture de milliers de lignes, modifications d'autorisations en boucle) pendant qu'on mesure les autres. Critère d'équité provisoire : le p95 des autres sociétés reste au plus à 2 fois leur p95 sans voisin bruyant.
- **Des milliers de requêtes réellement concurrentes** n'ont de sens que sur une machine dédiée.
- **Limite à connaître** : l'application n'a pas d'API. Chaque action HTTP se rejoue par des requêtes Livewire (jeton CSRF, état de l'écran). Ces scripts sont fragiles et seront limités aux parcours principaux.

### 7.4 Requêtes répétées et idempotence

Cas joués :
- même requête Livewire envoyée deux fois en parallèle (double clic) ;
- rejeu d'un ancien état d'écran après modification des données ;
- bouton « précédent » du navigateur ;
- nouvelle tentative automatique du client après un délai dépassé.

**Limite connue** : l'application ne sait pas reconnaître une même demande envoyée deux fois. La table `paiements` n'a pas de clé de requête unique, et `EnregistrerPaiementAction` crée sans condition [constaté]. Si l'enregistrement a eu lieu mais que la réponse s'est perdue, rejouer la requête crée un second paiement, une seconde vente ou un second avoir. C'est conforme au code actuel.

Le comportement attendu est une décision de conception (14-17(f)) : soit l'utilisateur constate d'abord ce qui a été enregistré, soit l'application porte un jeton qui rend chaque demande unique. D'ici là, chaque doublon de ce type est compté et publié comme « conforme au code, risque produit », jamais comme une réussite.

### 7.5 Coupures, délais, erreurs serveur

- **Toxiproxy** est placé entre le client et nginx, et entre l'application et PostgreSQL, Redis et les simulateurs. Il injecte de la latence, des coupures en pleine requête et des connexions qui ne répondent plus.
- **Côté base** :
  - délais d'attente des verrous et des requêtes réduits (codes 55P03 et 57014) ;
  - session coupée en pleine transaction (`pg_terminate_backend`).
- **Côté serveur** :
  - processus php-fpm tué, PostgreSQL redémarré ;
  - Redis redémarré avec et sans sauvegarde sur disque (AOF). Sans AOF, des pertes sont attendues : elles sont comptées à part ;
  - disque des PDF plein, seulement sur un petit système de fichiers dédié ; sinon, déclaré non couvert.

### 7.6 Processus tués en pleine action : exploration des points d'arrêt

Pour chacune d'une vingtaine d'actions critiques (émission, vente, paiement, contre-passation, avoir, validation d'achat, avoir fournisseur, clôture, fermeture de caisse, inscription, création de membre, envoi), on tue le processus (SIGKILL) à chaque point d'arrêt :
- **après chaque requête SQL**, repérée par l'**empreinte** de la requête et non par son rang. Le nombre de requêtes varie avec les caches (par exemple la reconstruction du cache des autorisations) : un rang ne serait pas reproductible ;
- **sur les effets hors SQL** : envoi d'un job dans la file (avant ou après la validation de la transaction), envoi d'un e-mail, écriture du PDF (`Storage::put`), écriture dans Redis, appel au simulateur. Un arrêt entre la validation de la transaction et l'envoi du job (une transmission PDP jamais envoyée) est le cas le plus probable de « job perdu ».

Contrôles après chaque arrêt :
1. tous les invariants tiennent : la transaction est complète ou absente ;
2. chaque intention atteint un état final, ou est reprise par un balayage ;
3. pour le rejeu après « enregistré, mais réponse perdue », voir 7.4.

Variantes : exception à la place de l'arrêt, coupure de connexion, arrêt juste après la validation et avant la réponse.

Volume : environ 3 000 cas par niveau d'isolation [supposé]. Tout est joué en READ COMMITTED ; en REPEATABLE READ, seulement les actions qui touchent l'argent.

### 7.7 Reprises

- Workers de file tués en plein job.
- Délai de relivraison (90 s) plus court que la durée d'un job.
- Jobs échoués, puis relancés.
- Planificateur arrêté trois jours, puis relancé.
- Sauts d'horloge, passages d'heure et d'année.
- Horloge en retard d'un serveur sur l'autre (CF).

**Restauration d'une sauvegarde** (jouée en CF, mais la question se pose dès maintenant, décision 14-17(h)). Après une restauration, les séquences et la chaîne du registre reculent. Des numéros déjà remis aux clients ou déclarés risquent d'être réattribués, et un e-reporting déjà transmis ne correspond plus à la base. Le scénario mesure cet effet et vérifie la procédure retenue.

En C2 s'ajoutent : une panne de SUPER PDP pendant trois heures, des réponses perdues alors que l'opération a eu lieu, et une reprise sans doublon de dépôt ni de déclaration.

### 7.8 Moteur, isolation et anomalies propres à PostgreSQL

**Cible** : PostgreSQL 17, locale C.UTF-8, en READ COMMITTED (le niveau par défaut). Variante REPEATABLE READ, pour garder F2 comme régression possible. Essai en SERIALIZABLE au niveau 1.

**Classes d'anomalies propres à PostgreSQL**, chacune avec un test :
- un `SELECT … FOR UPDATE` sur une ligne absente ne bloque rien, d'où des violations d'unicité (23505) ;
- `FOR UPDATE` est refusé avec un agrégat ;
- après une erreur, toute la transaction est annulée (25P02), sauf si un point de sauvegarde a été posé ;
- les codes 40P01 (interblocage), 40001 (échec de sérialisation), 55P03, 57014 et 22003 sont classés en « conflit réessayé », « délai dépassé » ou « débordement » ;
- les identifiants de plus de 63 octets sont tronqués sans erreur (script de contrôle) ;
- le typage est strict (booléen contre entier, texte contre nombre) ;
- `jsonb` réordonne les clés ;
- la recherche sans accents passe par l'extension `unaccent`, avec une fonction équivalente sous SQLite ;
- verrous consultatifs, s'ils sont utilisés.

**Deux rôles** (décision 6 du lot PostgreSQL) : le compte de l'application ne peut ni UPDATE, ni DELETE, ni TRUNCATE, ni désactiver un déclencheur sur les tables en ajout seul. Testé dès C1 dans l'environnement de campagne, même si la mise en place réelle relève de l'étape 15.

### 7.9 Horloge

- **Dans un processus** : l'horloge de test de Carbon.
- **Entre processus** (php-fpm, workers, planificateur) : libfaketime [à confirmer], ou un mécanisme réservé à l'environnement de test.
- **Fuseaux explicites** : UTC pour le registre (`CarbonImmutable::now('UTC')`), heure de Paris pour la journée de caisse et l'année des numéros.
- **Colonnes datées par la base elle-même** (`useCurrent()`) : elles s'écartent de l'horloge simulée. Les contrôles temporels les excluent ou les comparent à l'heure réelle, et le rapport le dit.

### 7.10 Isolation des tests de concurrence dans PHPUnit

Les mécanismes habituels ne conviennent pas :
- `RefreshDatabase` travaille dans une transaction invisible des autres processus ;
- `DatabaseTruncation` vide les tables, ce que les déclencheurs refusent sur les tables protégées.

Il faut donc une base par test ou par classe de tests, créée depuis un modèle (`CREATE DATABASE … TEMPLATE`), dont la configuration est transmise aux processus enfants. Paratest a le même besoin d'une base par processus, d'où le droit CREATEDB.

---

## 8. L'oracle : comment on sait qu'un résultat est faux

### 8.1 Trois moments de contrôle

1. **Pendant la simulation** : assertions de chaque scénario ; sonde d'isolation (présence et valeur du filtre par magasin) ; canaris ; contrôles légers par échantillonnage.
2. **Après chaque scénario** : invariants du magasin concerné.
3. **Après chaque palier**, dans cet ordre :
   - contrôle global en lecture seule, dans une transaction `REPEATABLE READ READ ONLY` pour lire une image cohérente de la base ;
   - comparaison avec le modèle de référence et le registre des intentions ;
   - empreintes des tables en ajout seul ;
   - enfin, `caisse:verifier --complet` sur chaque magasin.

**`caisse:verifier` n'est pas en lecture seule** [constaté] :
- il prend le verrou du registre ;
- il écrit les événements 280, 90 et 95 ;
- il lève `HorlogeRegressiveException` si l'horloge est antérieure à la dernière ligne du registre d'un magasin.

Il ne tourne donc qu'en fin de palier, la pile arrêtée, à une horloge postérieure à la dernière ligne de chaque registre. Ses écritures sont attendues et comptées.

### 8.2 Familles d'invariants (environ 130 contrôles)

Chaque invariant est validé avant usage (8.8).

| Famille | Contrôles |
|---|---|
| INV-ISO, isolation | Toute référence pointe vers une ligne du même magasin (environ 25 liens, plus les liens polymorphes). Mêmes client, type, régime et nature entre un avoir et ses factures. Un utilisateur dans un seul magasin. Rôles dans le bon magasin. **Les auteurs et opérateurs enregistrés (`cree_par`, `operateur_id`, et les `user_id` des rôles) sont membres du magasin.** Un seul propriétaire. Notifications, fichiers PDF et destinataires d'e-mails appartenant au bon magasin. |
| INV-SOC, sociétés | Un seul principal par SIREN. Chaque document d'un autre magasin a été émis alors que ce magasin était aligné, d'après le contenu signé (K5). Code unique dans la société et inchangé depuis le premier document (J1). SIRET unique, magasins supprimés compris (J7). Aucun stock ni aucune fiche partagés (J5). |
| INV-NUM, numérotation | Toutes les séries continues de 1 à n, sans trou ni doublon ; format et code ; année du numéro (heure de Paris) ; aucun numéro sur un brouillon ; au plus une facture par devis. |
| INV-REG, registre | Séquences continues ; chaînage ; signatures valides ; horodatage UTC non décroissant ; grands totaux ; exactement un enregistrement par document émis ; codes du journal attendus ; aucune vente d'un magasin suspendu. |
| INV-MNT, montants | HT + TVA = TTC par ligne ; totaux = somme des lignes ; règle E1 par taux ; remises ; franchise ; achats nets des remises et de l'escompte ; barème ; retours cumulés soldant au centime ; aucun montant hors de `decimal(12,2)`. |
| INV-PAI, paiements | Reste dû ≥ 0 ; paiement = total imputé (B3) ; avoir imputé ≤ son total ; statut cohérent avec le reste dû ; contre-passation opposée, liée, datée du jour, jamais sur une journée clôturée, jamais elle-même contre-passée. **B6, restreint aux moments d'imputation** : voir le détail sous ce tableau. |
| INV-STK, stock | Stock de la fiche = grand livre (tous les types de mouvements, pas seulement trois) ; ventes et retours égaux aux lignes ; retours ≤ ventes ; coût moyen rejoué ; valeur ≥ 0 (K1) ; fournisseur du dernier achat (C3) ; une alerte par franchissement. |
| INV-CLO, clôtures | Un Z par jour, sans trou ; totaux = documents rattachés ; aucune vente après la clôture du jour ; caisse personnelle cohérente ; chiffre d'affaires (B17) et relevé de caisse (B18) recalculés par l'oracle. |
| INV-DEV, devis | Aucun devis envoyé échu qui n'aurait pas expiré après 00:30 ; aucun devis refusé, expiré ou converti modifié. |
| INV-FIC, fiches | Exactement un client comptoir par magasin, jamais archivé ; aucun document émis après l'archivage de sa fiche (à confirmer selon la règle du lot 6). |
| INV-INT, international | Une consultation VIES du jour, pour le même n°, pour chaque livraison intracommunautaire et chaque autoliquidation ; rappels de preuves identiques à ceux de l'oracle. |
| INV-DRT, droits | Chaque document ou action a été fait par un membre qui détenait alors l'autorisation, d'après `cree_par` et l'historique des événements 130. |
| INV-MEL, e-mails | Plafonds respectés sur toute fenêtre d'une heure ; une relance par jour et par facture ; rien pour un magasin suspendu ; aucun doublon après une nouvelle tentative. |
| INV-JOB, files | Files vides en fin de palier ; chaque ligne de `failed_jobs` expliquée ; chaque intention d'envoi ou de transmission dans un état final. |
| INV-IMM, immutabilité | Empreintes avant et après des documents émis, lignes, paiements, imputations, registre, clôtures et grand livre : seuls des ajouts sont admis. |
| INV-PDP (C2, 17 contrôles), INV-CPT, INV-API, INV-ABO | Selon les étapes. |

**Détail de l'invariant B6.** Il ne s'applique que juste après une émission d'avoir ou de facture, ou après un paiement qui n'est pas une contre-passation. À ces moments-là, aucun crédit imputable ne doit rester à côté d'une facture impayée du même client, dans le même magasin, la même série (tickets ou factures) et le même régime. Sont exclus :
- le client comptoir, dont le crédit sert de bon d'avoir ;
- les brouillons ;
- la période entre une contre-passation et le bon paiement suivant (K9).

L'ordre d'imputation est vérifié par le registre des intentions.

**Exemples de requêtes, corrigées** :

```sql
-- INV-PAI-1 : aucune facture réglée au-delà de son total
SELECT f.id FROM factures f
LEFT JOIN (SELECT facture_id, SUM(montant_alloue) s FROM paiement_allocations GROUP BY facture_id) p ON p.facture_id = f.id
LEFT JOIN (SELECT facture_id, SUM(montant_alloue) s FROM avoir_allocations GROUP BY facture_id) a ON a.facture_id = f.id
WHERE f.total_ttc - COALESCE(p.s, 0) - COALESCE(a.s, 0) < 0;

-- INV-REG-1 : séquences continues par magasin et par chaîne.
-- La version précédente laissait passer 1, 2, 2, 4 (un doublon compensé par un trou).
SELECT magasin_id, chaine FROM enregistrements_fiscaux
GROUP BY magasin_id, chaine
HAVING MIN(sequence) <> 1
    OR MAX(sequence) <> COUNT(*)
    OR COUNT(DISTINCT sequence) <> COUNT(*);
```

Les deux requêtes s'exécutent sous SQLite et sous PostgreSQL : aucune ne compare un booléen à un entier.

### 8.3 Modèle de référence, registre des intentions, oracle de calcul

- **Modèle de référence** : pour chaque magasin, le stock par produit, le reste dû par facture, le crédit par client et les journées clôturées. Il est comparé à la base.
- **Registre des intentions.** Une seconde imputation de 60 € sur une facture de 200 € ne viole aucun invariant (120 ≤ 200). Seule la comparaison entre les opérations voulues et les lignes créées détecte un « paiement en double ». Les montants attendus sont recalculés **depuis les entrées du générateur** (fiche et prix au moment de la vente, quantité, type de client), et non depuis le prix relu en base : sinon, une mauvaise ligne recopiée passerait inaperçue.
- **Oracle de calcul**, en Python avec `decimal`.
  - Arrondi **« demi vers le haut » (`ROUND_HALF_UP`) explicite**, montants négatifs des avoirs compris. PHP arrondit la moitié en s'éloignant de zéro, alors que `decimal` arrondit par défaut au pair le plus proche : sans ce réglage, on aurait des faux positifs systématiques.
  - Il couvre les ventes aux particuliers, E1, K2, B10, K3, **les remises, l'escompte et le barème**, les achats, le coût moyen, **le chiffre d'affaires (B17), le relevé de caisse (B18) et les totaux des Z**.
- **Cas d'E1.** La décision ne fixe pas comment la TVA d'un taux se répartit sur ses lignes. Le code fait un cumul en centimes (`CalculateurLigne::repartirLaTva`). Écrire cette méthode dans l'oracle reviendrait à recopier le code. On vérifie donc trois propriétés : la somme est exacte, chaque ligne s'écarte d'au plus un centime, le résultat est toujours le même. Sauf si vous fixez la méthode (décision 14-17(g)).
- **Valeurs de référence**, contrôlées telles quelles :
  - les vôtres :
    - 1 000 × 1,00 € à 20 % : 833,33 € + 166,67 € pour un professionnel, 830,00 € + 170,00 € pour un particulier ;
    - retours un à un : 33,33 € + 6,67 €, puis 33,34 € + 6,66 € ;
    - achat de 10 × 1,18 € HT : 11,80 € + 2,36 € ;
    - K9 : 100, puis 10, puis 90 ;
    - K1 : valeur 0 ;
  - celles des lots : 66,67 € + 13,33 € ; 1,9975 € donnant 14,40 € ; 41,58 € pour un DOM ; 416,67 € pour un retour à l'export.
- **Tout écart d'un centime** donne lieu à un arbitrage tracé : erreur du code, erreur de l'oracle, ou règle à trancher par vous.

### 8.4 Canaris : détecter les fuites en lecture

Les invariants SQL ne voient que les fuites en écriture. Pour les fuites en lecture, un marqueur unique par magasin est placé dans chaque nom de client, de produit, de fournisseur, de service et dans chaque note.

Un balayage automatique cherche ensuite le marqueur d'un autre magasin partout :
- dans toutes les réponses HTTP, y compris les pages 200, les listes déroulantes, la recherche globale et les widgets ;
- dans les PDF, les e-mails et les exports CSV ;
- dans les notifications ;
- dans le contenu des jobs (`jobs`, `failed_jobs`) ;
- dans les journaux.

### 8.5 Règles pas encore construites

Elles sont marquées « attendu en échec », avec leur étape. Elles ne comptent pas comme anomalies, mais leur nombre figure au rapport.

### 8.6 Tests par propriétés

- **Calcul.** La propriété n'est plus « aucun flottant » : le code calcule en nombres à virgule flottante (`float` dans `CalculateurLigne`, conversions `(float)` dans `EnregistrerPaiementAction` [constaté]). Elle devient « aucun écart dû à la représentation flottante », testée sur des cas pièges : 0,1 + 0,2 ; 1,005 ; grands montants proches de la limite de `decimal(12,2)`. S'y ajoutent :
  - TVA ≥ 0 ;
  - somme des parts = TVA du taux ;
  - retours cumulés monotones qui soldent au centime ;
  - remises de 0 à 100 % ;
  - montants hors limites refusés avant la base.
- **Autres propriétés** :
  - numérotation et statuts, comparés à un modèle simple sur des suites d'opérations aléatoires ;
  - grand livre : stock et coût moyen rejoués identiques ;
  - dates : passages d'heure, fins de mois, 29 février ;
  - identifiants : clé de Luhn, clé de TVA, adresses en minuscules ;
  - autorisations : sans l'autorisation, l'action est toujours refusée.
- **Volume** : au moins 100 000 cas par propriété de calcul pur, 10 000 pour les propriétés qui touchent la base [supposé, à ajuster après mesure].
- Graines enregistrées. Tout contre-exemple est réduit au cas minimal puis figé en cas fixe.

### 8.7 Force des tests

- **14 mutants plantés dans le code.** Chacun est une erreur introduite exprès, rejouée dans toute la chaîne (générateur, stress, oracle), avec le détecteur qui doit la trouver et le temps mis à la trouver.

  | Mutant | Erreur introduite | Doit être détecté par |
  |---|---|---|
  | M1 | `montantRestant()` lu avant `verrouiller()` dans `EnregistrerPaiementAction` | INV-PAI-1, sous course forcée |
  | M2 | `DocumentNumberingService` lit `prochain_numero` sans verrou, ou l'incrémente de 2 | INV-NUM, avec et sans l'index unique |
  | M3 | `MagasinScope` retiré d'un seul modèle (Produit dans la caisse) | Canaris et INV-ISO |
  | M4 | `TenantContext` non remis à zéro entre deux jobs | INV-ISO et sonde SQL |
  | M5 | Arrondi au prix unitaire au lieu du HT de la ligne, ou arrondi au pair | Oracle, sur 1 000 × 1,00 € |
  | M6 | Pas de mouvement de stock sur un avoir | INV-STK |
  | M7 | Signature précédente recopiée, ou séquence sautée | `caisse:verifier` et INV-REG |
  | M8 | Crédit imputé de la plus récente à la plus ancienne | Contrôle de l'ordre B6 |
  | M9 | Deux relances le même jour | INV-MEL |
  | M10 | Contrôle d'autorisation retiré d'une action Filament | SEC-01 |
  | M11 | Unicité de l'adresse e-mail sensible à la casse | EQU-01 |
  | M12 | Garde « magasin suspendu » retirée d'une écriture | SEC-04 |
  | M13 | Échéance calculée depuis la création au lieu de l'émission | FAC-04 |
  | M14 | Dernier retour K2 qui ne solde pas au centime | Oracle cumulé |

  Critère : 100 % détectés. La liste est versionnée.
- **Mutation automatique** (infection, sous réserve de votre accord), sur le domaine critique :
  - calcul, numérotation, paiements et avoirs, stock, registre, clôtures ;
  - `ControleDesDroits`, policies, `ReglagesCommunsDeLaSociete`, `TerritoireFiscal`, `VerificateurCaissesPersonnelles` ;
  - `ControleNatureOperation` et `PreuvesRequises`, une fois livrés par les lots 19 et 20.

  Le score global et le score des mutants couverts par au moins un test sont publiés séparément. Une mutation sans effet sur le comportement est justifiée au cas par cas.
- **Corruptions de données** injectées dans une copie de la base (imputation en trop, stock décalé, chaîne cassée, numéro en double, ligne d'un autre magasin) : 100 % doivent être signalées.
- **Couverture** mesurée par module avec pcov et publiée. Le code non couvert est listé.

### 8.8 Validation de chaque invariant

Avant usage, chaque invariant passe deux épreuves :
- un cas planté doit le déclencher ;
- une base propre ne doit pas le déclencher.

La reconnaissance a déjà eu un contrôle de stock faux, corrigé depuis.

---

## 9. Montée progressive par paliers

### 9.1 Paliers

Les durées et les tailles ci-dessous partent des mesures SQLite et MariaDB de la reconnaissance. Elles seront **remesurées en R0**, et le tableau republié.

| Palier | Contenu | Volume | Où | Durée machine estimée [supposé] | Critère de sortie |
|---|---|---|---|---|---|
| P0 | Suites sous SQLite et PostgreSQL ; matrice d'autorisations ; propriétés ; points d'arrêt ; entrelacements ; invariant structurel ; mutants plantés ; mutation ; validation des invariants | aucun volume | ici | 20 à 35 h | Suites vertes sous les deux moteurs (code de sortie 0) ; 0 contre-exemple ; 100 % des mutants plantés détectés ; invariants validés |
| P1 | Tous les scénarios et parcours ; courses ; stress à 8 processus avec points chauds ; HTTP à 20 utilisateurs ; pannes ; deux configurations (base et Redis) | 10 sociétés tirées et 1 société de 20 magasins forcée, environ 43 magasins en profil type | ici | 3 graines × 3 à 4 h, plus la variante REPEATABLE READ : 10 à 15 h | 0 violation d'invariant ; vérificateur à 0 anomalie partout ; 0 anomalie bloquante ou majeure ouverte |
| P2 | Idem, plus stress à 16 à 32 processus, HTTP de 50 à 200 utilisateurs, 1 h d'endurance, voisin bruyant | 100 sociétés, environ 225 magasins, socle enrichi et 10 % en profil type | ici | 1 graine : 7 à 12 h | Idem, plus croissance du coût conforme (section 11) |
| P3 | Idem, plus tâches de nuit sur tous les magasins, 2 h d'endurance, 1 000 à 2 000 sessions ouvertes | 1 000 sociétés, environ 2 254 magasins | ici, **si** R0 confirme une base de 4 Go au plus | 1 graine : 10 à 18 h | Idem, plus mémoire ≤ 128 Mo par requête et tâches de nuit terminées |
| P4 | Volume complet | 10 000 sociétés, environ 22 540 magasins, environ 127 000 comptes | machine dédiée | 1 à 2 jours | Idem |

**Scénario étagé** : tous les magasins en socle enrichi, 10 % en profil type (toutes tailles de société représentées), 20 magasins sur une année, avec une chronologie commune.

**P4 est impossible avant la correction d'A4.** La variante « 10 000 sociétés en socle, sur cette machine » (ancienne option 8(b)) est abandonnée : elle réservait tout le disque libre et contredisait la règle des 3 fois (9.4).

### 9.2 Budget

La version précédente annonçait 25 à 35 h pour C1. C'était sous-estimé d'un facteur 2 à 3, puisque ses propres critères multipliaient les graines, les moteurs et les niveaux d'isolation. Réduction assumée :
- volume et concurrence sur **un seul moteur**, PostgreSQL ; SQLite pour la suite et les scénarios fonctionnels ;
- REPEATABLE READ seulement au niveau 1 et en P1 ; SERIALIZABLE seulement au niveau 1 ;
- **3 graines en P1, 1 graine en P2 et en P3**, plus les graines qui ont trouvé une anomalie.

Total de C1 sur cette machine : **60 à 100 h de temps machine** [supposé], relances comprises, à condition que la machine ne soit pas partagée.

### 9.3 Priorités et critère d'arrêt

Le temps machine de chaque palier se répartit par risque, dans cet ordre :
1. fiscal et légal : registre, numérotation, clôtures, TVA ;
2. argent : paiements, avoirs, crédits, valeur du stock ;
3. isolation ;
4. le reste.

Un palier s'arrête quand deux conditions sont remplies :
- tous ses scénarios ont été exécutés au moins une fois, avec les efforts minimaux de la section 11 ;
- **et** aucune nouvelle anomalie n'est apparue pendant les 4 dernières heures de stress, à effort constant [seuil provisoire].

### 9.4 Passage au palier suivant

- 0 violation d'invariant ;
- toute anomalie bloquante ou majeure corrigée et reprouvée ;
- croissance du coût conforme ;
- disque libre au moins 3 fois supérieur au besoin mesuré du palier suivant.

---

## 10. Traitement d'une anomalie

1. **Fiche** ANO-nnn : gravité proposée, palier, scénario, moteur, niveau d'isolation, graine, commit, commande exacte, extrait des journaux, instantané de la base conservé.
2. **Réduction** au cas minimal : moins de données, moins de processus, entrelacement forcé.
3. **Cause** établie dans le code, pas supposée.
4. **Test reproductible ajouté à la suite**, dans le groupe `concurrence` s'il exige PostgreSQL. On vérifie qu'il **échoue** sur le code non corrigé.
5. **Correction**, selon le protocole du CLAUDE.md :
   - isolation des magasins vérifiée ;
   - tests ciblés ;
   - `pint` ;
   - suite complète `--parallel` avec un code de sortie à 0, sous SQLite **et** sous PostgreSQL ;
   - relecture adversariale ;
   - commit, envoi, CI verte.
6. **Relance** du scénario d'origine avec la même graine, puis du palier entier.
7. **Non-régression** : le test et la graine restent pour toujours.
8. **Règle métier à trancher** (calcul, arrondi, obligation légale) : la question vous est posée avec les options chiffrées. Rien n'est choisi à votre place.
9. **Gravité validée par vous.** L'agent qui code propose une gravité. Or « 0 anomalie bloquante ou majeure ouverte » est un critère de sortie : chaque classement bloquant ou majeur, et chaque reclassement à la baisse, vous est donc soumis.

**Gravité** :
- **bloquant** : donnée perdue ou corrompue, fuite entre magasins, montant ou TVA faux, doublon de numéro ou de paiement, non-conformité légale, installation impossible ;
- **majeur** : erreur 500 sous concurrence sans incohérence des données, coût qui grossit avec le volume, reprise manuelle nécessaire ;
- **mineur** : défaut sans effet sur les données ;
- **observation**.

Jamais : supprimer, affaiblir ou réécrire un test pour qu'il passe.

---

## 11. Critères de réussite chiffrés

| Critère | Seuil | Mesure |
|---|---|---|
| Requêtes sortantes réelles | **0** vers SUPER PDP, VIES ou un serveur SMTP | Compteurs de 3.2 |
| Suite de tests | 100 % réussie sous SQLite et PostgreSQL 17 ; code de sortie 0 | JUnit XML par moteur |
| Exceptions inattendues | 0 dans `laravel.log`, `failed_jobs` et les alertes critiques du vérificateur, hors pannes injectées | Lecture des journaux après chaque palier, avertissements et avis d'obsolescence compris |
| Invariants bloquants (ISO, SOC, NUM, REG, MNT, PAI, STK, CLO, IMM, DRT) | 0 violation, sur chaque palier et chaque graine | Rapport du contrôleur |
| Vérificateur fiscal | 0 anomalie sur 100 % des magasins | `caisse:verifier --complet` |
| Isolation | 0 fuite (URL, Livewire, jobs, sonde SQL, canaris) | Compteurs k6, sonde, balayage |
| Matrice d'autorisations | 100 % conforme au résultat attendu validé par vous | Test généré |
| Montants | 0 écart non arbitré avec l'oracle ; 0 contre-exemple | Rapport d'écarts |
| Concurrence | 0 doublon (selon le registre des intentions), 0 écriture partielle, 0 erreur 500 non injectée après corrections | Base, journaux |
| Points d'arrêt | 100 % des arrêts laissent une base cohérente. Rejeu selon la décision 14-17(f) ; d'ici là, les doublons sont publiés. | Exploration |
| Pannes injectées | 0 incohérence. 0 intention perdue avec la file en base, ou avec Redis et l'AOF ; pertes sans AOF comptées à part. Un e-mail en double seulement dans la fenêtre documentée (worker tué juste après l'envoi), et compté. | Registre des intentions |
| Mutants plantés | 100 % détectés | Liste M1 à M14 |
| Mutation automatique | Objectifs provisoires, en score des mutants couverts : ≥ 85 % sur le calcul et la numérotation, ≥ 75 % sur le reste du domaine critique. Seuils définitifs fixés après la première mesure, et seulement relevables. | Rapport infection |
| Couverture de code | Objectifs provisoires : ≥ 90 % des lignes du domaine critique, ≥ 80 % de l'application. Fixés après la première mesure. | pcov |
| Décisions | 85 sur 85 : testées, ou « attendu en échec » avec leur étape, ou « non testable » justifié | Matrice générée |
| Dépendances | 0 vulnérabilité à `composer audit`, ou chacune justifiée | `composer audit` |
| Croissance du coût | Par opération, à travail identique : temps CPU, lignes lues et mémoire maximale au palier P3 au plus 1,5 fois ceux de P1. Tendance tracée sur les trois paliers (environ 43, 225 et 2 254 magasins). | Générateur, `pg_stat_statements` |
| Équité | p95 des autres sociétés au plus 2 fois leur p95 sans voisin bruyant (provisoire) | k6 |
| Mémoire | ≤ 128 Mo par requête et par worker | Relevés |
| Tâches de nuit | ≤ 1 h au volume de P4, mesurée, ou extrapolée et signalée comme telle | Chronométrage |
| Efforts minimaux (provisoires) | 100 % des codes de scénario de C1 exécutés ; pour chaque course, toutes les positions de pause explorées au niveau 1 et au moins 200 tentatives au niveau 2 ; 100 000 cas par propriété de calcul ; tous les points d'arrêt des 20 actions ; 50 000 opérations métier en P1 et 500 000 en P3 | Rapport |
| Simulateur SUPER PDP (C2) | 100 % des requêtes conformes ; les 17 invariants PDP à 0 | Journal du simulateur |
| Anomalies | 0 bloquante ou majeure ouverte en fin de campagne, gravité validée par vous ; chacune avec son test et son commit | Fiches |

Ces seuils sont des objectifs. Un seuil non atteint est publié tel quel, jamais arrondi. Les seuils k6 sont publiés même en cas d'échec, avec les réponses 403 et 404 comptées comme attendues (`expectedStatuses(200, 403, 404)`) pour ne plus compter les refus voulus comme des échecs.

---

## 12. Ressources nécessaires et limites

### 12.1 Cette machine

4 processeurs, 15 Gio de mémoire sans swap, environ 15 Go de disque libre sur un volume partagé. Ce disque est déjà descendu à 2,4 Go et a été plein une fois [prouvé ici].

- **Suffisant pour** R0, P0, P1 et P2.
- **Selon R0** : P3. Son besoin est à remesurer sous PostgreSQL ; d'après la règle des 3 fois, il ne tient ici que s'il reste sous 4 Go environ.
- **Insuffisant pour** P4 et pour des milliers de requêtes réellement concurrentes.

### 12.2 Plan disque

- Un moteur à la fois en P3. Graines enchaînées, base supprimée entre deux.
- PostgreSQL de campagne réglé pour limiter le journal d'écriture : `wal_level=minimal` (avec `max_wal_senders=0`), `max_wal_size` borné, archivage désactivé. `synchronous_commit` reste à `on`, pour rester représentatif.
- Instantanés compressés (`pg_dump -Fc`). On ne garde que le dernier palier et les bases des anomalies ouvertes.
- k6 limité à son résumé (`--summary-export`), jamais `--out json` pendant une endurance.
- Arrêt automatique si le disque libre passe sous 3 Go.
- Nettoyage des caches Composer et Go, des images Docker inutiles (MySQL), et de MariaDB s'il n'est pas gardé.

### 12.3 Machine dédiée (P4, charge HTTP massive, campagne finale)

- **Configuration** : une machine de 16 processeurs, 64 Go de mémoire et 300 Go de SSD. Ou trois machines : base (8 processeurs, 32 Go), application (8 à 16 processeurs), générateur de charge (4 processeurs).
- **Coût** : à chiffrer sur devis de l'hébergeur retenu. Ordre de grandeur non vérifié : quelques dizaines d'euros par jour en location à l'heure.
- **Comment Claude l'opérerait**, à choisir (décision 14-9) :
  - un environnement autohébergé pour Claude Code sur cette machine ;
  - ou des scripts remis à l'éditeur, avec leurs critères et le format des preuves à rapporter.

### 12.4 Durées estimées [supposé]

- **R0** : 4 à 6 h de machine.
- **C0, outillage** : 6 000 à 9 000 lignes, soit 2 à 4 semaines de travail en sessions successives. Au programme :
  - générateur étendu, contrôleur porté sur PostgreSQL ;
  - canaris, registre des intentions ;
  - banc de concurrence et explorateur d'entrelacements, injecteur de pannes ;
  - simulateur VIES, scripts k6 avec écritures, matrice d'autorisations ;
  - script d'amorçage.
- **C1** : 60 à 100 h de machine ici ; P4 : 1 à 2 jours sur machine dédiée.
- **Correction des anomalies** : imprévisible par nature. Chaque anomalie bloquante relance le palier.
- **Relances C2 à C6** : environ 1 à 2 jours de machine chacune.
- **CF** : 3 à 5 jours.

### 12.5 Limites connues (reprises au rapport)

- Le simulateur SUPER PDP reproduit l'API publiée, pas le comportement réel de la plateforme (Schematron, PDF/A-3, délais, PPF). La recette réelle est hors campagne.
- Machine partagée : temps indicatifs à ±30 à 50 %.
- Un seul serveur jusqu'à la campagne finale : pas de latence réseau réelle entre serveurs, pas de TLS en local.
- Rejeu Livewire fragile ; peu de parcours dans un vrai navigateur.
- Volumes de P4 extrapolés si P4 n'est pas joué.
- Octane hors périmètre ; MySQL et MariaDB hors cible.
- Horloge simulée entre processus pas encore validée ; colonnes datées par la base hors de l'horloge simulée.
- Scénarios des lots 14 à 21 écrits d'après leur plan.
- Une anomalie non reproduite (magasin sans client comptoir) reste ouverte.
- Un scénario ne trouve que ce qu'il provoque.

---

## 13. Format du rapport final et des preuves

### 13.1 Structure du rapport

1. Résumé : ce qui a été exécuté, trouvé, corrigé et non couvert. Aucune affirmation « sans erreur ».
2. Environnement : commit, versions exactes, machine, configuration de chaque moteur, validité des mesures.
3. Données : paliers, graines, nombre de sociétés, de magasins, de comptes et de documents, contrôlés contre le profil attendu.
4. **Décompte des tests** réussis, échoués, ignorés et attendus en échec, par type (PHPUnit, propriétés, concurrence, entrelacements, points d'arrêt, mutants, k6, navigateur) et par moteur, avec les tests qui échouaient avant leur correction.
5. Scénarios : état de chaque code, avec son moteur, sa graine et le lien vers son journal.
6. **Matrice des 85 décisions** : décision, scénario, test, état, journal.
7. **Opérations métier** par type et par issue : succès, refus métier, erreur technique.
8. Invariants par palier et par graine.
9. Concurrence et robustesse : nombre d'opérations, de processus et d'utilisateurs ; conflits par code d'erreur ; interblocages ; entrelacements et points d'arrêt explorés ; pannes injectées.
10. Temps de réponse : p50, p95 et p99 par action, par page et par tâche planifiée, avec la validité de chaque mesure.
11. **Ressources par palier** : CPU, mémoire résidente maximale, disque, entrées-sorties, taille de la base et du journal d'écriture.
12. Anomalies : une fiche par anomalie (cause, test ajouté avec son chemin et son nom, commit correctif, relance réussie).
13. Couverture et mutation.
14. Requêtes sortantes : compteurs.
15. Incidents d'infrastructure pendant la campagne.
16. Non couvert et limites.
17. Reproduction : commandes exactes.
18. **Correspondance avec votre demande** (13.3).

### 13.2 Preuves

Chaque exécution produit un dossier `campagne/executions/<date>-<palier>-<graine>/`, qui contient :
- `manifeste.json` : commit, versions, environnement hors secrets, graines, moteur, niveau d'isolation, empreinte SHA-256 de chaque script ;
- les journaux bruts, le JUnit XML, `invariants.json`, `metriques.json` ;
- les résumés k6, les rapports de conflits, les sorties de `caisse:verifier` ;
- un fichier `SHA256SUMS`.

L'archive est publiée hors du scratchpad à la fin de chaque palier. **L'empreinte de `SHA256SUMS` est versionnée dans le dépôt** (ou signée), pour qu'on ne puisse pas modifier les preuves après coup (décision 14-19).

### 13.3 Correspondance avec votre demande

| Votre demande | Section |
|---|---|
| Environnement indépendant, représentatif, aux ressources mesurables | 3, 12, 13.1 (rubrique 11) |
| 10 000 sociétés, 1 à 20 établissements, plusieurs rôles par magasin, données variées et fictives | 5, 9 |
| Vrais services, règles, permissions et persistance | 1.2, 5.5 |
| Tous les modules et les 85 décisions | 2, 6, 6.6 |
| Opérations simultanées, doublons, stocks faux, fuites | 7, 8 |
| Coupures, requêtes répétées, délais, erreurs, reprises | 7.4 à 7.7 |
| Vérification automatique des invariants, contrôles globaux | 8 |
| Tests unitaires, fonctionnels, d'intégration, de bout en bout, de concurrence, de charge, de robustesse, par propriétés | 4, 6, 7, 8.6, 8.7 |
| Vrais outils réellement exécutés | 3, 4, 13.2 |
| SUPER PDP jamais appelé, tout simulé | 2.2, 3.2 |
| Montée progressive | 9 |
| Cause, test, correction, relance, non-régression | 10 |
| Rapport détaillé et vérifiable | 13 |
| Ne jamais prétendre, ne jamais annoncer « sans erreur » | 1.2 (point 9), 11 |
| Stratégie, outils, critères, ressources | ce document |

### 13.4 Commandes types (à adapter après R0)

```
# Instance PostgreSQL 17 de campagne (image tirée avant l'isolation réseau)
docker run -d --name piliqi-pg17 -p 127.0.0.1:<port>:5432 -e POSTGRES_HOST_AUTH_METHOD=trust \
  -e POSTGRES_INITDB_ARGS="--locale=C.UTF-8 --encoding=UTF8" postgres:17

# Suite sous PostgreSQL
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=piliqi_campagne_tests \
  php vendor/bin/paratest --log-junit suite.xml

# Pile complète dans un espace réseau isolé (script à écrire en C0)
scripts/lancer-pile.sh --reseau-isole --config=base|redis

# Génération, contrôle, vérification, charge
php scripts/generer.php racine=<copie> societes=100 graine=42 profil=etage sortie=<dossier>
php scripts/invariants.php "pgsql:host=127.0.0.1;port=<port>;dbname=piliqi_campagne" <role_lecture> ""
php artisan caisse:verifier <magasin> --complet
taskset -c 0 tools/bin/k6 run charge/parcours-caisse.js --summary-export resume.json
```

---

## 14. Décisions à vous demander

1. **Feuille de route.** Inscrire :
   - « 1 bis. Campagne de tests initiale » entre les étapes 1 et 2, après le lot PostgreSQL ;
   - « relance de la campagne » dans la définition de fin des étapes 2, 3, 5, 9 et 13 ;
   - « campagne finale » dans l'étape 15.

   *Recommandé : oui.*
2. **Dépendances de test.** Eris, infection et larastan, en versions publiées depuis au moins deux semaines, installés d'abord dans un projet d'outils séparé, sans toucher au dépôt. Ils n'entreraient au dépôt que plus tard, avec votre accord. Attention : larastan casserait `composer install` dans ce conteneur, car phpstan n'a pas de source Git et les archives GitHub sont refusées ici ; la CI ne serait pas touchée. Dusk : non pour l'instant. *Recommandé : eris et infection dans le projet séparé dès C0 ; larastan repoussé, ou accepté en connaissance de cause.*
3. **Outillage versionné dans le dépôt** :
   - `tests/Campagne/` ;
   - `tests/Simulateurs/Vies/` en C1, puis `tests/Simulateurs/SuperPdp/` en C2 ;
   - en test seulement, un en-tête qui indique le magasin aux simulateurs.

   *Recommandé : oui, pour la reproductibilité.*
4. **Logiciels hors dépôt et script d'amorçage.** Garder Docker démarré à la main (pour `postgres:17`), nginx et php-fpm extraits, k6 et Toxiproxy compilés, Locust, Prism, pcov, xdebug et libfaketime. Un script d'amorçage versionné les réinstalle à chaque session. MariaDB 10.11, installé pendant la reconnaissance, serait désinstallé. *Recommandé : oui.*
5. **CI.** Le lot PostgreSQL ajoute déjà les tâches SQLite et PostgreSQL. La campagne y ajoute le groupe `concurrence` sous PostgreSQL 17, pour environ 10 minutes de plus [supposé]. Cela remplace l'ancienne demande de tâches MariaDB et MySQL. *Recommandé : oui.*
6. **Configuration à tester d'ici l'étape 15** : la configuration livrée (cache, sessions et files d'attente en base) et Redis. Cela remplace l'ancienne question du moteur de production, tranchée par le lot PostgreSQL. *Recommandé : les deux, la configuration livrée d'abord.*
7. **Données.**
   - (a) Répartition réaliste (loi de Zipf, environ 22 540 magasins), avec une société de 20 magasins forcée dès P1.
   - (b) Minimum de rôles par magasin : soit 1 propriétaire, au moins 1 administrateur, au moins 1 responsable et au moins 2 vendeurs (environ 127 000 comptes) ; soit au moins 2 de chaque rôle (environ 168 000 comptes).
   - (c) Socle enrichi pour tous les magasins.

   *Recommandé : (a), le premier minimum de (b), et (c).*
8. **Niveau d'isolation des transactions** : READ COMMITTED (le défaut de PostgreSQL) comme cible, REPEATABLE READ en variante. C'est un choix technique : je propose de le trancher sur les résultats et de vous en rendre compte.
9. **Volume maximal.** Deux options :
   - (a) une machine dédiée pour P4 et la charge massive, en précisant comment Claude l'opère (environnement autohébergé pour Claude Code, ou scripts remis à l'éditeur) ;
   - (c) sur cette machine, P3 selon R0, avec P4 extrapolé et signalé comme tel.

   L'ancienne option (b) est abandonnée. *Recommandé : (c) pour C1, (a) avant la mise en production.*
10. **Ordre de traitement.** R0 d'abord. Ensuite, avant toute montée en volume, corriger les anomalies qui empêchent la campagne : A4, A6, A3, et ce que R0 confirme. *Recommandé : oui.*
11. **Information de l'agent des lots 14 et 15.** La cause de F2 (une lecture sans verrou faite avant le verrou, qui fige la vue des données en REPEATABLE READ) reste une erreur latente, même sous PostgreSQL en READ COMMITTED. Je propose de la lui signaler sans interrompre son travail. *Recommandé : oui.*
12. **Portabilité des tests.** Si des tests ne passent encore que sur un moteur après le lot PostgreSQL, les rendre portables en conservant ou en renforçant leur intention, chaque changement relu. Votre accord est demandé parce que le protocole interdit de réécrire un test pour qu'il passe. *Recommandé : oui.*
13. **Gravité des anomalies.** Vous validez chaque classement bloquant ou majeur, et chaque reclassement à la baisse. *Recommandé : oui.*
14. **Identifiants fictifs.**
    - Confirmer la plage SIREN `000…` dans le fichier Sirene de l'INSEE.
    - Vérifier ou remplacer le SIREN 732829320 et le n° FR44732829320 de `tests/TestCase.php`.
    - Laisser les téléphones vides.

    *Recommandé : oui.*
15. **Questions SUPER PDP à trancher avant C2**, pour information dès maintenant :
    - facture reçue par un magasin suspendu ;
    - fr:212 négatif après une contre-passation ;
    - facture mixte de biens et de services ;
    - adresse de réception dont la date d'effet est future ;
    - e-reporting d'un magasin en franchise ;
    - journée déjà transmise puis corrigée ;
    - relecture périodique de l'historique des événements ;
    - périmètre de l'e-reporting B2B international ;
    - `company_mandates` hors périmètre.
16. **Délai.** Accepter que C0 et C1, et les corrections qu'elles révéleront, retardent le début de l'étape 2 d'une durée impossible à fixer à l'avance.
17. **Règles métier apparues pendant la préparation.** Ce sont des réponses métier : je n'en choisis aucune à votre place.
    - **(a)** Une vente peut-elle faire passer la quantité en stock sous zéro ? C'est possible aujourd'hui ; seule la valeur est encadrée (K1).
    - **(b)** Qui peut fixer la **remise** de 0 à 100 % d'une ligne, et le **prix et le taux d'une ligne de service** ? Les PU des lignes de produit sont désactivés à l'écran ; une requête forgée sur ces champs reste testée. Mais la remise et les lignes de service sont libres pour quiconque peut créer une facture.
    - **(c)** Créer ou modifier un membre dans un magasin suspendu est-il voulu ? `equipe.creer` et `equipe.modifier` y restent permises.
    - **(d)** Le code magasin doit-il être unique dans toute la base, comme aujourd'hui, ou seulement dans la société, comme le dit J1 ? L'unicité globale fait refuser à une société un code déjà pris par une autre, et révèle l'existence de cette autre.
    - **(e)** Faut-il vérifier qu'un magasin possède le SIREN qu'il déclare, et vérifier l'adresse e-mail à l'inscription ? Sans cela, une inscription faite avant la vraie société peut en devenir le principal, lire ses réglages communs et, à l'étape 2, recevoir ses factures sans magasin précis. Elle peut aussi occuper pour toujours l'adresse e-mail d'un tiers.
    - **(f)** Si une émission, un paiement, un avoir ou un envoi a bien été enregistré mais que la réponse s'est perdue : l'utilisateur doit-il d'abord constater ce qui a été enregistré, ou l'application doit-elle porter un jeton qui rend chaque demande unique (idempotence) ?
    - **(g)** Méthode de répartition de la TVA d'un taux sur ses lignes (E1) : la fixer (aujourd'hui, un cumul en centimes), ou accepter un contrôle par propriétés.
    - **(h)** Restauration d'une sauvegarde : quelle procédure pour le registre, les numéros déjà remis aux clients et les déclarations déjà transmises ? À trancher au plus tard à l'étape 15.
    - **(i)** Un vendeur absent ou retiré a laissé sa caisse personnelle ouverte : la clôture manuelle du jour reste-t-elle bloquée ?
18. **Recette dans le bac à sable SUPER PDP** : hors campagne, jamais sans votre accord explicite, avec ses propres règles. *Recommandé : la prévoir après C2, sur une décision séparée.*
19. **Publication des preuves.** L'archive de chaque palier est publiée hors du scratchpad (dans le dépôt ou dans un artefact privé), et son empreinte est versionnée dans le dépôt. *Recommandé : oui.*

---

*Fichiers cités :*
- `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/campagne/` :
  - journaux des suites : `suite-sqlite.log`, `suite-mariadb.log`, `suite-mysql84.log` ; `liste-tests.txt` ;
  - `cout-scripts/` (générateur, `invariants.php`) et `cout-resultats/` ;
  - `mariadb-inv/poc/` : preuves F2 et F3 (`payer.php`, `numeroter.php`, `na.log`) ;
  - `charge/` : `parcours-session.js`, `k6-session.txt` ;
  - `simulateur-pdp/proto/`.
- `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/` :
  - `moteurs/choix-moteur-finale.md`, `passage-postgresql.js`, `pg-run1.txt` ;
  - `plan-decisions-final.json`, `decisions-editeur.md`.
- Dans le dépôt `/home/user/piliqiediteur/` :
  - `app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php` ;
  - `app/Domain/Authorization/PermissionRegistry.php` ;
  - `app/Domain/Tenancy/Services/ReglagesCommunsDeLaSociete.php` ;
  - `app/Filament/Concerns/AjouteDesLignesDeVente.php` ;
  - `bootstrap/app.php`, `.env.example` ;
  - `database/migrations/0001_01_01_000000_create_magasins_table.php` ;
  - `tests/TestCase.php`.