# Quel moteur de base de données pour PILIQI ? Version finale pour l'éditeur

Repères utilisés :
- **[vérifié]** : constaté dans le dépôt (fichier:ligne) le 2026-10-10. HEAD d43fd9c, arbre de travail propre.
- **[constaté, autre campagne]** : résultats d'essais MariaDB 10.11 trouvés dans le scratchpad. J'ai lu les fichiers, je n'ai pas relancé les essais.
- **[probable]** : documentation ou extraits de recherche web du 2026-10-10. Les pages elles-mêmes n'ont pas pu être ouvertes.
- **[supposé]** : mon appréciation.

Je n'ai modifié aucun fichier du dépôt.

## Ce qui a changé après les critiques

- **La conclusion reste la même** : PostgreSQL en production, SQLite pour le développement et les tests.
- **Elle est moins forte qu'annoncée.** C'est une préférence argumentée, pas une nécessité, et l'écart avec MariaDB est faible.
  - 4 des 6 raisons de départ sont retirées ou affaiblies : la limite de 2038, l'interblocage des ventes, les réponses brutes de SUPER PDP et la sécurité au niveau des lignes (RLS).
  - L'hébergement ne départage plus PostgreSQL et MySQL, sauf en SecNumCloud.
- **Le choix de repli change** : MariaDB 11.8 LTS passe devant MySQL.

---

## 1. Réponse directe

**Production : PostgreSQL 17 ou 18, en service géré hébergé en France. Développement et tests : SQLite, jamais en production.**

Ordre recommandé :
1. PostgreSQL ;
2. MariaDB 11.8 LTS, le repli crédible ;
3. MySQL 8.4 ou 9.7.

Choisir PostgreSQL contredit l'étape 15 de la feuille de route (« MySQL, Redis », CLAUDE.md:203) : la décision vous revient.

**Pourquoi PostgreSQL l'emporte (raisons qui ont résisté à la critique)**

1. **Inaltérabilité mieux défendue dans la base.**
   - Laravel n'exécute les migrations dans une transaction que sous PostgreSQL (`Schema/Grammars/Grammar.php:31` contre `PostgresGrammar.php:18`) **[vérifié]**. Une migration qui échoue ne laisse donc jamais une table sans ses déclencheurs.
   - Un déclencheur peut refuser TRUNCATE, ce qui est impossible sous MySQL (`DeclencheursDeProtection.php:25-28`) **[vérifié]**.
   - Limite : DROP TABLE et `ALTER TABLE … DISABLE TRIGGER` restent possibles pour le propriétaire des tables. Il faut donc séparer deux comptes : un pour les migrations, un pour l'application.
2. **Même comportement qu'en test.** Comme SQLite, PostgreSQL compare les textes exactement et trie dans l'ordre binaire avec une collation C. Sous MariaDB (collation unicode_ci), l'ordre des autorisations change, et `JournalEvenementsTest` échoue **[constaté, autre campagne]**. Or cette liste est triée en SQL (`User.php:89-95`) et entre dans le journal signé (`JournalDesDroits.php:86`).
3. **Pas de verrou d'intervalle par défaut.**
   - Sous InnoDB (le moteur de stockage de MySQL et MariaDB), des interblocages (erreur 1213) ont été constatés sur MariaDB 10.11 avec 4 processus et 180 magasins **[constaté, autre campagne]** :
     - 5 en fermant une caisse ;
     - 16 en créant des membres (sur `tetes_registre_fiscal`) ;
     - 4 sur des factures d'achat.
   - Ils ne concernent pas les ventes : il n'y a qu'une caisse par magasin (CLAUDE.md:171), et le registre est verrouillé avant la numérotation (`RegistreFiscal.php:51-71`) **[vérifié]**.
   - Ils se corrigent en grande partie sous MySQL ou MariaDB avec `'isolation_level' => 'READ COMMITTED'` (`MySqlConnector.php:96-97`) **[vérifié]**. C'est donc un avantage modeste.
4. **Projet stable.** PostgreSQL a une gouvernance communautaire. Côté MySQL : Oracle a licencié environ 70 développeurs en septembre 2025, et le dépôt public n'a presque plus reçu de commits jusqu'en février 2026 ([InfoQ](https://www.infoq.com/news/2025/12/mysql-declining-development), [DevClass](https://devclass.com/2026/01/13/open-source-mysql-repository-has-no-commits-in-more-than-three-months/)) **[probable]**.
5. **Commodités pratiques.**
   - Hébergement SecNumCloud : PostgreSQL chez Cloud Temple, MariaDB à confirmer, MySQL introuvable **[probable]**.
   - PostgreSQL 16 est déjà installé dans le conteneur (`/usr/lib/postgresql/16`) **[vérifié]**. On peut donc lancer la suite de tests sur le vrai moteur.

**Ce que PostgreSQL coûte en contrepartie**
- Vous perdez la recherche insensible aux accents que MySQL et MariaDB donnent sans effort. Sous PostgreSQL, les tableaux Filament ignorent déjà la casse (`vendor/filament/support/src/helpers.php:205-216`) **[vérifié]**, mais pas les accents (il faut l'extension `unaccent`). Les 3 recherches écrites à la main restent sensibles à la casse.
- Son niveau d'isolation par défaut (READ COMMITTED) oblige à relire les lectures verrouillées qui ne passent pas par le registre, par exemple `FermerSaCaisseAction.php:30-34` **[vérifié]**.
- Une erreur SQL annule toute la transaction, ce qui oblige à adapter des tests.
- Il faut maintenir deux moteurs, SQLite et PostgreSQL.

**Coût estimé** :
- PostgreSQL : 3 à 5 jours ;
- MariaDB 11.8 : 1 à 2 jours, car la suite y passe presque entièrement après un seul correctif (voir §4) ;
- dans les deux cas, il faut ajouter une campagne de tests de concurrence.

Ces estimations sont **[supposé]**.

**Ce qui n'est plus une raison de choisir PostgreSQL**
- **Le nom d'index de 67 caractères** (`2026_10_14_100002_create_enregistrements_fiscaux_table.php:58`) est à nommer sur tous les moteurs. L'argument est neutre, mais il prouve que la cible actuelle n'a jamais été testée : la CI ne tourne que sur SQLite (`phpunit.xml:26-27`, `.github/workflows/tests.yml:31,60`) **[vérifié]**.
- **La limite de 2038.**
  - Le manuel MySQL 8.4 la confirme ([doc Oracle](https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/date-and-time-type-syntax.html)).
  - Elle se corrige en moins d'une journée en passant en DATETIME dans les 35 migrations concernées **[vérifié, nombre de fichiers]**.
  - Elle ne touche pas les pièces déjà enregistrées : ce sont les écritures postérieures au 19/01/2038 qui échoueraient.
  - MariaDB la repousse à 2106 depuis la 11.5, sur 64 bits ([notes 11.5.1](https://mariadb.com/kb/en/mdb-11-5-1-rn)) **[probable]**.
- **Les réponses brutes de SUPER PDP.** Le code les décode déjà en tableau avant de les enregistrer (`ResultatMandatPdp.php:12`, `MandatPdp.php:46`) **[vérifié]**. Aucun moteur ne garde donc les octets reçus.
- **La RLS** (sécurité au niveau des lignes) n'est qu'une option à concevoir.
- **Sauvegardes et restauration à un instant donné** : cela dépend de l'hébergeur, pas du moteur. OVHcloud la propose aussi pour MySQL **[probable]**.

**Pourquoi pas SQLite en production**
- Un seul écrivain à la fois.
- `lockForUpdate` n'y fait rien (`Query/Grammars/SQLiteGrammar.php:31-34`) **[vérifié]**.
- Chaque ouverture de session écrit au registre (`JournaliserOuvertureDeSession.php:48`) **[vérifié]**.

---

## 2. Tableau comparatif

| Critère | SQLite | MySQL 8.4 / 9.7 | MariaDB 11.8 LTS | PostgreSQL 17 / 18 |
|---|---|---|---|---|
| Concurrence | Un écrivain, verrous ignorés : exclu | Verrous d'intervalle, remède READ COMMITTED | Interblocages constatés (10.11) ; isolation stricte activée par défaut, à désactiver ou neutraliser | Pas de verrou d'intervalle ; lectures fantômes à surveiller |
| Inaltérabilité | Déclencheurs, mais fichier sans contrôle d'accès | TRUNCATE et DROP échappent aux déclencheurs ; migrations non atomiques | Comme MySQL | TRUNCATE bloqué, migrations atomiques ; DROP exige la séparation des rôles |
| Casse, accents, tri | Exacts (référence des tests) | Ignorés : recherche confortable, mais ordre différent des tests | Comme MySQL | Exacts, comme les tests ; accents avec `unaccent` |
| JSON | Texte | **Clés réordonnées** : la comparaison du panier échoue (`PanierDeCaisse.php:72`) **[probable]** | Texte conservé | Type `json` conservé |
| Dates | Sans limite | TIMESTAMP bloqué en 2038 (corrigeable en DATETIME) | 2106 (64 bits) | Sans limite |
| Hébergement géré en France | Aucun | OVHcloud, Scaleway, Clever Cloud | Cegedim.cloud, Cloud Temple (à confirmer) | OVHcloud, Scaleway, Clever Cloud, Cloud Temple SecNumCloud |
| Gouvernance | Domaine public | Oracle, en crise depuis 2025 | MariaDB plc (fonds K1) | Communautaire |
| Coût de passage | En place | 2 à 3 jours | 1 à 2 jours | 3 à 5 jours |

Ni les performances ni les licences ne départagent ces moteurs pour une telle charge **[supposé]**.

---

## 3. Ce que « SUPER PDP utilise PostgreSQL » change

**Techniquement, rien.**
- PILIQI ne parle à SUPER PDP que par API HTTP, derrière `PdpGatewayInterface` (`app/Integrations/Pdp/Contracts/PdpGatewayInterface.php:19-35`) **[vérifié]**.
- Il n'y a aucune connexion de base à base.
- La spécification fournie (`tests/Fixtures/SuperPdp/openapi.json`) n'utilise que des montants en chaînes décimales, des entiers, des dates et des fichiers **[vérifié]**.

**L'affirmation elle-même n'est pas confirmée.** Elle repose sur un extrait de https://www.superpdp.tech/fonctionnalites/ rapporté par une analyse précédente. Ma recherche du 2026-10-10 n'a rien trouvé **[probable au mieux]**. Choisir PostgreSQL « parce que SUPER PDP l'utilise » serait un bon choix pour une mauvaise raison.

**Ce qui compte vraiment, quel que soit le moteur :**
- enregistrer le corps HTTP brut en `text`, sans cast, avec son empreinte, à côté des colonnes décodées ;
- stocker les identifiants en `bigInteger` ;
- ne jamais faire passer un montant par un nombre à virgule flottante ;
- dater en UTC ;
- passer par une file d'attente pour respecter la limite d'appels.

**Droit fiscal** : ni le BOFiP, ni NF525, ni l'attestation de l'éditeur (rétablie par l'art. 125 de la loi n° 2026-103) n'imposent un moteur **[probable]**.

---

## 4. Conséquences concrètes et moment opportun

### Si vous choisissez PostgreSQL

**Indispensable pour que l'application fonctionne :**
- Ajouter une branche `pgsql` à `app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php:62-72` : une fonction plpgsql, des déclencheurs BEFORE UPDATE et DELETE par ligne, et un déclencheur BEFORE TRUNCATE. Aujourd'hui, ce fichier refuse PostgreSQL **[vérifié]**.
- Passer `notifications.data` en `json` (`2026_10_13_100001_create_notifications_table.php:23`). **Ce correctif bloque tout le panneau**, pas une seule action : la cloche de Filament filtre `data->format` à chaque page (`vendor/filament/notifications/src/Livewire/DatabaseNotifications.php:92`, `AppPanelProvider.php:74`), comme `User.php:105` **[vérifié]**.
- Nommer explicitement l'index (migration `2026_10_14_100002`, ligne 58).
- Régler le vérificateur (`VerificateurIntegrite.php:86-90`) en REPEATABLE READ READ ONLY. Il faut une garde : PostgreSQL refuse ce réglage dans un point de sauvegarde, ce qui est le cas dans les tests **[probable]**.
- Remplacer les recherches écrites à la main par `whereLike(..., caseSensitive: false)` : `ProduitResource.php:108-110`, `FactureResource.php:356-357`, `Caisse.php:193`.
- Retirer la branche de collation propre à MySQL dans `2026_10_02_200005_create_services_table.php:21-29`.
- Relire `FermerSaCaisseAction.php:30-34`, qui verrouille sans passer par le registre **[vérifié]**.
- Ajouter un point de sauvegarde dans `TrouverOuCreerServiceAction.php:36-44`.
- **Option abandonnée** : `lockForUpdate('for no key update')`. La méthode ne prend aucun argument (`Query/Builder.php:3418-3421`) **[vérifié]**, donc l'appel n'aurait eu aucun effet.

**Utile sur tous les moteurs :**
- Ajouter `orderBy('id')` dans `AppliquerAvoirsDisponiblesAction.php:57`.
- Corriger un défaut de concurrence indépendant du moteur : plus de 250 erreurs 1062 sur `permissions_name_guard_name_unique` pendant des inscriptions simultanées **[constaté, autre campagne ; cause non analysée]**.

**Tests à adapter.** Le moteur change les exigences ; aucun test n'est affaibli :
- `DeclencheursDeProtectionTest.php:45-55`, qui prend aujourd'hui `pgsql` comme exemple de pilote refusé ;
- 7 `DROP TRIGGER`, qui exigent `ON table` sous PostgreSQL ;
- 15 assertions qui attendent une erreur SQL, dans 8 fichiers, à placer dans un point de sauvegarde ;
- `VerificateurIntegriteTest.php:814-831`, qui insère « douze euros » dans une colonne décimale : PostgreSQL et MariaDB le refusent **[vérifié]**. Il faut le réécrire sans l'affaiblir. Si sa prémisse doit être tranchée, je vous la soumettrai.

**Règles métier à trancher par vous :**
- Mettre les e-mails en minuscules ? Le problème existe déjà sous SQLite : `EmailLibrePourUnNouveauMembre.php:31` compare sans normaliser **[vérifié]**.
- « ABC » et « abc » sont-elles la même référence produit ?
- La recherche doit-elle ignorer les accents ?

**Intégration continue :**
- garder le job SQLite et ajouter un job PostgreSQL ;
- définir `DB_CONNECTION` et `DB_DATABASE` dans l'environnement du job suffit, car `phpunit.xml` ne force pas ses valeurs (`vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:140-148`) **[vérifié]** ;
- `--parallel` exige le droit CREATE DATABASE ;
- déclarer `ext-pdo_pgsql` dans `composer.json` : c'est une dépendance, à valider par vous.

**Exploitation (étape 15) :**
- Collation de base **déterministe** (C, ou C.UTF-8 avec le fournisseur builtin de PostgreSQL 17+). N'utiliser une collation française ICU que pour les tris d'affichage, requête par requête. Ainsi, les contenus signés ne changent pas d'ordre et il n'y a pas de REINDEX quand ICU est mis à jour.
- Deux rôles : un propriétaire pour les migrations, et le compte de l'application, privé d'UPDATE, DELETE et TRUNCATE sur les tables en ajout seul (GRANT et droits par défaut, PostgreSQL 15+).
- Un pooler si les connexions deviennent nombreuses.
- Restauration à un instant donné, plus un export des archives signées indépendant du moteur.

**CLAUDE.md :**
- remplacer « MySQL, Redis » par « PostgreSQL, Redis » à la ligne 203 ;
- ajouter la décision à « Outillage » ;
- réécrire le docblock de `DeclencheursDeProtection.php:21-28`.

### Si vous préférez rester sur MariaDB 11.8

- nommer l'index ;
- régler `isolation_level` sur READ COMMITTED, ou `innodb_snapshot_isolation=OFF` ;
- régler le vérificateur en REPEATABLE READ explicite ;
- corriger les 10 tests en échec sur MariaDB **[constaté, autre campagne, copie de travail modifiée]** :
  - 6 tests qui vérifient le texte SQL avec des guillemets doubles (`EmissionSigneeTest`, `FactureAchatActionsTest`) ;
  - `ReglagesCommunsDeLaSocieteTest` ;
  - `JournalEvenementsTest` (ordre de tri) ;
  - `RetourArriereDesMigrationsTest` ;
  - le test « douze euros » ;
- ajouter un job de CI MariaDB ;
- vérifier que l'hébergeur autorise la création de déclencheurs.

En échange : pas de limite de 2038, JSON conservé tel quel, recherche sans accents sans effort.

### Quand le faire

- Juste après les lots en cours de l'étape 1, en lot dédié, **avant l'étape 3**. Changer de déclencheurs après l'attestation de l'éditeur toucherait le dispositif attesté **[supposé]**.
- C'est le moment le moins cher : aucune donnée réelle, et la règle du projet permet de corriger directement les migrations de création puis de lancer `migrate:fresh`.
- L'intégration SUPER PDP (étape 2) ne dépend pas du moteur et peut avancer en parallèle.
- La mention précédente d'un « lot de devis non validé » est périmée : il a été validé dans d43fd9c **[vérifié]**.

---

## 5. Incertitudes

1. **Interblocages** : constatés sur MariaDB 10.11 par une autre campagne, non relancés par moi. Rien n'a été mesuré sur MySQL 8.4 ni sur PostgreSQL.
2. **Lectures fantômes sous PostgreSQL** (READ COMMITTED) : le code est correct là où le registre est verrouillé en premier. Le reste n'est pas démontré : paniers, avoirs disponibles, stock.
3. **La suite complète n'a jamais tourné sur PostgreSQL.** Elle révélera peut-être des dépendances cachées, comme un ordre de lignes supposé sans ORDER BY.
4. **MariaDB 11.8** : l'isolation stricte (`innodb_snapshot_isolation`) est activée par défaut depuis la 11.6.2 ([notes 11.6.2](https://mariadb.com/docs/release-notes/community-server/old-releases/11.6/11.6.2), [MDEV-35124](https://jira.mariadb.org/browse/MDEV-35124)), et elle a cassé des applications PHP ([Arch Linux](https://gitlab.archlinux.org/archlinux/infrastructure/-/commit/18301f15553c2242a0f5eb961dd36c7bf303b5fb)) **[probable]**. Qu'elle soit sans effet sous READ COMMITTED est **[supposé]**.
5. **MySQL 9.7 LTS**, disponible depuis le 21/04/2026 ([blog Oracle](https://blogs.oracle.com/mysql/mysql-9-7-lts-is-here-upgrade-and-modernize-on-a-stronger-community-edition)) **[probable]**, n'a pas été testé.
6. **Comparaison du panier sous MySQL** : l'échec vient de la lecture du code (`paniers_caisse.lignes` en json, comparaison `===`) ; il n'a pas été exécuté.
7. **Prix** : il faut comparer des offres de production à haute disponibilité, en euros, et non des offres de développement. Par exemple, Scaleway DB-POP2-2C-8G coûte environ 105 €/mois par nœud, avant haute disponibilité ([tarifs](https://www.scaleway.com/en/pricing/managed-databases/)) **[probable]**. Pour OVHcloud, le plan Business est à relever dans la console. Le prix est le même pour PostgreSQL et MySQL. Laravel Cloud n'a aucune région en France.
8. **Hyperscalers** (AWS Paris, Azure France Central) : non examinés. Leur soumission au CLOUD Act est à trancher par vous.
9. **NF525** : son référentiel n'est pas public. L'idée qu'un changement de moteur après attestation impose une nouvelle attestation est **[supposé]**.
10. **Corrections proposées par les critiques et écartées faute de preuve** :
    - préférer PostgreSQL 18 pour ses collations non déterministes : non vérifié, gardé comme piste ;
    - MariaDB en SecNumCloud chez Cloud Temple : les extraits se contredisent, à confirmer ;
    - 200 €/mois pour deux nœuds chez Scaleway : non vérifié ;
    - PgBouncer 1.21 obligatoire : cité de mémoire ;
    - abandonner SQLite : c'est un choix pour vous, pas une correction.

Pièces hors dépôt :
- `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/moteurs/` (DDL MariaDB et PostgreSQL) ;
- `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/campagne/suite-mariadb.junit.xml` (1744 tests, 9 échecs, 1 erreur) ;
- `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/campagne/cout-resultats/maria-4p-agregat.json`.