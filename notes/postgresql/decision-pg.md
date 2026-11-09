# Lot PG : PostgreSQL seulement, retrait de SQLite (décision de l'éditeur du 08/11/2026, D55) ; constat C7 de l'éditeur (09/11/2026)

## 1. Le message de l'éditeur, mot pour mot

08/11/2026 vers 00 h 00 UTC : « et je veux pas que tu fasse sqlite car j'utilis que postgresql pour la production et developemnet du coup pas besion sqlite ».

Lecture retenue (D55, `historique/DIRECTIVES.md`) : PILIQI n'utilise plus que PostgreSQL (17 ou 18), en production, en développement et pour les tests ; SQLite disparaît du protocole, de la CI, de la configuration, du README, du code et de `CLAUDE.md`. Dès la décision, les agents ne lancent plus la suite SQLite ; le lot QA a mis la CI sur PostgreSQL seulement ; ce lot retire tout le reste.

## 2. État mesuré sur le code poussé (`c7b1ec3`)

48 fichiers de `app/`, `database/`, `config/` et `tests/` nomment SQLite, notamment : `app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php` (déclencheurs écrits pour les deux moteurs), les gardes en base (`GardesDesDroits`, `GardesDesIdentifiants`, `GardesDesCabinets`, `GardesDesTypesDActivite`, `GardesDeLaVente`, `GardesDesPiecesDuFournisseur`), `app/Domain/Recherche/ConnecteurSqlite.php` et `RechercheSansAccents.php` (recherche sans casse ni accents), `app/Domain/Calendrier/Connexions/ConnexionSqlite.php` et `FuseauParDefautDeLaBase.php`, `app/Providers/AppServiceProvider.php`, `app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php`, `config/database.php`, `config/queue.php`, la migration `2026_10_02_100001_create_fonction_sans_casse_ni_accents.php`, `tests/TestCase.php`, `phpunit.xml` (SQLite en mémoire par défaut), `.github/workflows/tests.yml`, `README.md`, `CLAUDE.md`, et des tests propres à un moteur ou sautés sous SQLite (390 tests sautés dans la suite SQLite, 5 sous PostgreSQL).

## 3. Ce que le lot fait

1. **Code** : chaque branche, chaque déclencheur, chaque fonction et chaque contournement propres à SQLite sont retirés et le code restant réécrit simplement pour PostgreSQL seul (règle « on réécrit proprement » de `CLAUDE.md`) : migrations de création (une seule par table, modifiée directement), gardes en base, recherche sans accents, connexions du calendrier, fournisseur de services, vérificateur d'intégrité, configuration (`config/database.php` sans connexion sqlite, `config/queue.php`), aucun reste mort.
2. **Tests** : `phpunit.xml` et l'amorce des tests sur PostgreSQL (base de test du README, mot de passe toujours lu dans l'environnement, jamais écrit dans le dépôt) ; chaque test sauté « hors PostgreSQL » ou écrit pour un seul moteur devient un test ordinaire ; les tests qui n'existaient que pour SQLite (connexion, fonction de recherche, ordre des moteurs) sont retirés ou réécrits pour ce qu'ils protègent encore ; la suite complète passe sur PostgreSQL au code 0, tests en navigateur compris (leurs serveurs de test et leurs copies du dépôt sur une base PostgreSQL jetable).
3. **Grille faketime** : la procédure du README (changement d'année, changements d'heure, nuit) passe sur PostgreSQL ; un test qui compare l'horloge de PHP à celle du serveur de base (déclencheur qui lit `now()`) garde la règle de `CLAUDE.md` (suivre l'horloge du serveur de base) ; la procédure est décrite pour qu'un agent la lance seul.
4. **CI** : toutes les tâches sur PostgreSQL (17, et 18 si la CI le permet), pour chaque version de PHP déjà testée (8.3, 8.4, 8.5), avec la collation C.UTF-8 ; plus aucune tâche SQLite (le lot QA a pu commencer ce changement : le reprendre et le finir).
5. **README et poste de l'éditeur (D52)** : installation de PostgreSQL 17 ou 18 sous Windows (XAMPP n'en fournit pas), création de la base de développement et de la base de test en UTF8 avec la collation C.UTF-8 (fournisseur de locale « builtin » de PostgreSQL 17, qui la rend disponible sous Windows), variables de `.env` (`DB_CONNECTION=pgsql` et les autres, sans valeur secrète), extension PHP `pdo_pgsql` ; retrait de `pdo_sqlite` et `sqlite3` de la liste des extensions ; `.env.example` sur PostgreSQL.
6. **`CLAUDE.md`** (textes de Claude d'après la décision) :
   - protocole, point 3 : « Pendant le travail, lancer les tests ciblés (`php artisan test --compact <fichier>`) ; avant chaque commit, lancer toute la suite sur PostgreSQL (base de test décrite dans le README) : `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=piliqi_test DB_USERNAME=piliqi DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --parallel`. Le code de sortie doit valoir 0, contrôlé juste après (`echo $?`) : le résumé affiché peut indiquer « passed » alors qu'un avertissement PHPUnit fait échouer la suite. » (adapter la commande si `phpunit.xml` porte désormais ces valeurs) ;
   - puce « Outillage » : « PostgreSQL (17 ou 18) en production, en développement et pour les tests, aucun autre moteur (décision de l'éditeur du 08/11/2026) ; la CI passe la suite complète sur PostgreSQL » à la place de la phrase sur SQLite ; la phrase sur la collation C.UTF-8 garde son sens sans comparer à SQLite (« l'ordre binaire est stable et un contenu signé ne change jamais d'ordre ») ; la grille faketime sur PostgreSQL ;
   - toute autre mention de SQLite (feuille de route, campagne de tests) réécrite ou retirée.
7. **Chaîne** : plus aucune mention d'une suite SQLite dans ce qui reste à faire (les notes de reprise le diront).
8. **Constat C7 de l'éditeur, avancé dans ce lot** (demande de l'éditeur du 09/11/2026 vers 10 h 55 UTC : « fais-le plus tôt, dans le lot suivant ») : corrige en entier le constat C7 de `navigateur/constats-editeur.md` (l'encadré « Vos tickets de caisse restent possibles » de la page « Identité » et du tableau de bord de la société, faux avant la validation et en lecture seule), avec tous ses tests, dans un commit à part.

## 4. Tests attendus

Les tests du constat C7 (section 3, point 8). Suite complète PostgreSQL au code 0, tests en navigateur compris, et outils du lot QA verts (analyse statique, couverture, mutation) ; `git grep -i sqlite` ne trouve plus que ce qui doit rester (par exemple un paquet tiers dans `composer.lock`), chaque reste justifié dans le compte rendu ; grille faketime sur PostgreSQL décrite et lancée une fois aux instants de la grille.

## 5. Place

Juste après QA, avant FI et AUD (choix de Claude : les lots suivants n'écrivent plus de code pour deux moteurs), effort Max (déclencheurs et gardes de la base).
