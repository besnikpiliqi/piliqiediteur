# Environnement de travail de PILIQI : installer une nouvelle session

Cette pièce dit ce que contenait le conteneur de la session qui a développé PILIQI du 2 au 26 octobre 2026, et comment une nouvelle session, sur un autre compte et dans un conteneur neuf, obtient exactement les mêmes outils. Elle complète la fiche de reprise (`README.md` de la branche `claude/pilotage-reprise`), qui reste à lire en premier pour la méthode de travail.

Deux fichiers vont ensemble :

- `ENVIRONNEMENT.md` : cette fiche ;
- `installer-session.sh` : le script qui refait toute l'installation (`bash installer-session.sh`) ou qui la contrôle sans rien modifier (`bash installer-session.sh --verifier`).

Dans la branche de reprise, les trouver par :
`git -C /home/user/piliqiediteur ls-tree -r --name-only origin/claude/pilotage-reprise | grep -E '(^|/)(ENVIRONNEMENT\.md|installer-session\.sh)$'`

## 1. En bref

1. L'éditeur règle l'environnement cloud du nouveau compte (section 4) : accès au dépôt GitHub, accès réseau aux gestionnaires de paquets, et, de préférence, le script d'installation dans le champ « Setup script ».
2. La nouvelle session suit la fiche de reprise § 4.1 (branche de travail), récupère la branche de reprise (§ 4.3), puis lance :
   `bash <chemin>/installer-session.sh --verifier`
   S'il manque quelque chose (code de sortie 1), elle lance `bash <chemin>/installer-session.sh`, puis de nouveau `--verifier`, qui doit finir par « Tout est en place » et le code 0.
3. Elle lance deux tests ciblés (section 6) avant toute chaîne.

Le script ne lance jamais la suite de tests, `composer update`, ni `caisse:generer-cle`, ne supprime jamais un cluster ou une base qui contient des données, et n'affiche jamais le mot de passe de la base de test.

## 2. Ce que contenait l'ancien conteneur (relevé du 26/10/2026)

### Système et outils

| Élément | Version exacte | Origine |
|---|---|---|
| Système | Ubuntu 24.04.4 LTS (noble), noyau 6.18, x86_64 | image de l'environnement |
| Machine | 4 processeurs, 15 Gio de mémoire, pas de swap | image |
| Disque `/` | 252 Go annoncés, 6,9 Go libres le 26/10 (voir piège 7) | image |
| Utilisateur | `root` (HOME `/root`), `sudo` présent | image |
| PHP | 8.3.6 (paquets Ubuntu `php8.3-*` 8.3.6-0ubuntu0.24.04.10, NTS) | image (23/07/2026) |
| Composer | 2.8.12, dans `/usr/local/bin/composer` | image |
| PostgreSQL | 16.14 (paquet `postgresql-16` 16.14-0ubuntu0.24.04.1) | image (15/07/2026) |
| faketime / libfaketime | 0.9.10-2.1 | installé par la session le 18/10/2026 |
| tzdata | 2026b-0ubuntu0.24.04.1 | image |
| Node / npm | 22.22.0 / 10.9.4 (sert seulement à `node --check` des scripts de la chaîne) | image |
| Python | `python3` = 3.11.15 (sert à modifier les scripts de la chaîne et à lire les journaux) | image |
| Redis | 7.0.15, installé mais arrêté, pas encore utilisé | image |
| Autres | git, curl, unzip, openssl, jq, strace, poppler-utils 24.02 (`pdftotext`, `pdftoppm`), navigateurs Playwright (`/opt/pw-browsers` : chromium-1194) | image |

### PHP

- Paquets : `php8.3-cli common curl dev gd igbinary intl mbstring mysql opcache pgsql readline redis sqlite3 xml zip`.
- Extensions chargées : calendar, Core, ctype, curl, date, dom, exif, FFI, fileinfo, filter, ftp, gd, gettext, hash, iconv, igbinary, intl, json, libxml, mbstring, mysqli, mysqlnd, openssl, pcntl, pcre, PDO, pdo_mysql, pdo_pgsql, pdo_sqlite, pgsql, Phar, posix, random, readline, redis, Reflection, session, shmop, SimpleXML, sockets, sodium, SPL, sqlite3, standard, sysvmsg, sysvsem, sysvshm, tokenizer, xml, xmlreader, xmlwriter, xsl, Zend OPcache, zip, zlib.
- Extensions dont le projet a besoin : celles que déclare `composer.json` (openssl, pdo_pgsql, zip), celles qu'exigent les paquets de `composer.lock` (ctype, dom, fileinfo, filter, hash, iconv, intl, json, libxml, mbstring, pcre, phar, reflection, session, simplexml, tokenizer, xml, xmlreader, xmlwriter, zip), celles de la CI (curl, pcntl, pdo, pdo_sqlite) et sqlite3, gd. Le script les contrôle toutes.
- `php.ini` de la ligne de commande non modifié : `memory_limit = -1` (valeur d'Ubuntu pour la ligne de commande), `date.timezone` non réglé (UTC par défaut ; l'application fixe UTC elle-même), opcache désactivé en ligne de commande, ni xdebug ni pcov.
- PHP d'Ubuntu lit les fuseaux horaires du système (`timezone_version_get()` = `0.system`) : tzdata est indispensable aux magasins d'outre-mer (America/Guadeloupe, America/Martinique, Indian/Reunion).

### Dépendances Composer (composer show --direct)

bacon/bacon-qr-code 3.1.1, barryvdh/laravel-dompdf 3.1.2, filament/filament 3.3.55, laravel/framework 13.34.0, laravel/tinker 3.0.2 ; en développement : brianium/paratest 7.20.0, fakerphp/faker 1.24.1, laravel/boost 2.10.1, laravel/pail 1.2.7, laravel/pao 1.1.5, laravel/pint 1.32.1, mockery/mockery 1.6.15, nunomaduro/collision 8.9.5, phpunit/phpunit 12.5.37. `composer.lock` (110 paquets, 41 de développement) fait foi ; aucun n'est un greffon Composer.

### PostgreSQL

- Cluster `16/main`, port 5432, créé par l'image le 15/07/2026 directement en UTF8 et C.UTF-8 (fournisseur libc) : l'ancienne session n'a jamais eu besoin de `pg_dropcluster`.
- `template0`, `template1` et toutes les bases en UTF8, collation et ctype C.UTF-8.
- `pg_hba.conf` et `postgresql.conf` d'origine : connexions locales en `peer`, connexions TCP sur 127.0.0.1 et ::1 en `scram-sha-256`, `listen_addresses = localhost`, `max_connections = 100`, `timezone = 'Etc/UTC'`.
- Rôle `piliqi` : LOGIN, CREATEDB, pas superutilisateur (comme la CI). Base `piliqi_test` : propriétaire `piliqi`, créée depuis `template0`, UTF8, C.UTF-8. Les tests parallèles créent et réutilisent `piliqi_test_test_1` à `piliqi_test_test_4` (4 processus), depuis `template1`.
- Mot de passe du rôle : dans `~/.profile` (`export PILIQI_PG_MOT_DE_PASSE=…`), jamais dans le dépôt ni dans les notes. Une copie inutile se trouvait aussi en fin de `~/.bashrc` (piège 3).

### Fichiers personnels et réglages (noms seulement)

- `~/.profile` : lit `~/.bashrc` sous bash, `. "$HOME/.local/bin/env"` (chemin de `~/.local/bin`), `export PILIQI_PG_MOT_DE_PASSE=…`.
- `~/.bashrc` : réglages d'Ubuntu, `NVM_DIR`, `BUN_INSTALL`, `PATH`, `PILIQI_PG_MOT_DE_PASSE`. Il s'arrête dès sa 6e ligne quand le shell n'est pas interactif (`[ -z "$PS1" ] && return`).
- `~/.config/composer/auth.json` : vide (aucun jeton).
- `.git/info/exclude` du dépôt : les lignes ajoutées par Claude Code (`**/.claude/…`), puis `tests/Feature/ScratchVerifTest.php`, `tests/Feature/Scratch*` et `tests/Feature/Scratch/`.
- `.env` du dépôt : copié le 02/10 et jamais rafraîchi ; `APP_KEY` renseignée ; il n'a pas les variables ajoutées depuis à `.env.example` (sans effet sur les tests, qui prennent leurs valeurs dans `phpunit.xml`).
- Variables de l'environnement cloud (noms seulement) : `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, `SUPERPDP_TRICATEL_CLIENT_ID`, `SUPERPDP_TRICATEL_CLIENT_SECRET` (bac à sable SUPER PDP, étape 2). Aucune autre n'est nécessaire.

### Claude Code

- Rien à installer côté projet : les compétences du projet sont suivies par git (`.claude/skills/` : deploying-to-cloud, infer-conventions, laravel-best-practices, testing-best-practices ; `boost.json`). Il n'y a pas de `.mcp.json`.
- `~/.claude/settings.json` est vide. Le crochet d'arrêt (`~/.claude/stop-hook-git-check.sh`, qui demande de committer quand l'arbre est sale) vient de la plateforme, pas du projet : il revient tout seul dans une nouvelle session. La fiche de reprise (§ 2) dit comment lui répondre pendant qu'un agent travaille.
- L'outil Bash de Claude Code ne lit pas `~/.profile` : `PILIQI_PG_MOT_DE_PASSE` n'existe que sous `bash -lc '…'`.

### CI (`.github/workflows/tests.yml`)

- Pint (`vendor/bin/pint --test`) sur PHP 8.3.
- Suite complète sur SQLite avec PHP 8.3, 8.4 et 8.5 (`php artisan test --parallel`).
- Suite complète sur PostgreSQL 17 (image `postgres:17`, `POSTGRES_INITDB_ARGS: --encoding=UTF8 --locale=C.UTF-8`, rôle `piliqi` LOGIN CREATEDB, base `piliqi_test`), PHP 8.3.
- Extensions installées par la CI : dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, pdo_pgsql.

### Restes de l'ancien conteneur à ne pas refaire

- `mariadb-server` 10.11, installé le 10/10 pour comparer les moteurs (arrêté) : `CLAUDE.md` interdit tout autre moteur que PostgreSQL et SQLite.
- Un cluster d'essai fait par `initdb` dans `/var/tmp/campagne-piliqi/pg` (port 55432, authentification `trust`), pour la mesure de capacité du 10/10.
- Les rôles `relecteur`, `relecteur_ce84` et les bases `relecture_*`, `instables_pg*`, `piliqi_banc*`, faits pour des relectures et des mesures.
- Les copies de travail du code dans le dossier temporaire de la session (`banc`, `wt`, `campagne`…).

## 3. Ce que l'ancienne session a réellement installé (historique)

Relevé dans les journaux d'apt et dans la transcription de la session (aucune valeur de mot de passe recopiée) :

1. 02/10 : création du projet ; `composer require` de Filament, DomPDF et d'autres paquets (plusieurs retirés depuis) ; Pest refusé (Pest 5 exige PHP 8.4) ; Larastan abandonné parce que son téléchargement depuis GitHub échouait (« Could not authenticate against github.com », piège 6) ; Laravel Boost ajouté.
2. 08/10 : exclusions locales `tests/Feature/ScratchVerifTest.php`, puis `tests/Feature/Scratch*` et `tests/Feature/Scratch/` dans `.git/info/exclude`.
3. 10/10 : `composer require --dev brianium/paratest:^7.20` (suites parallèles).
4. 10/10 : `mariadb-server` et un cluster PostgreSQL d'essai pour le choix du moteur (à ne pas refaire).
5. 10/10, 10 h 29 UTC, passage à PostgreSQL : le compte `postgres` crée le rôle et la base :
   `CREATE ROLE piliqi LOGIN CREATEDB PASSWORD '…'` puis `CREATE DATABASE piliqi_test OWNER piliqi TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8'`. Un essai d'`unaccent` est fait puis retiré : le projet n'utilise aucune extension (une migration crée sa propre fonction `sans_casse_ni_accents`).
6. 10/10, 14 h 12 UTC, à une reprise de la session : `service postgresql start`, puis la ligne `export PILIQI_PG_MOT_DE_PASSE=…` ajoutée à `~/.bashrc` et à `~/.profile`, contrôlée par `bash -lc`.
7. Du 10/10 au 26/10 : `service postgresql start` à chaque reprise, après les redémarrages du conteneur (18/10, 19/10, 24/10 notamment).
8. 18/10 : une suite échoue sur le disque plein (`errno=28` en écrivant `storage/logs/laravel.log`) ; nettoyage des copies de travail.
9. 18/10, 16 h 48 UTC : `apt-get install -y -q faketime`.
10. 19/10 : `composer require bacon/bacon-qr-code` (QR code du courrier recommandé, accordé par l'éditeur).
11. 25-26/10 (lot T2) : suites sous faketime ; sous PostgreSQL, `FAKETIME_DONT_RESET=1` devient obligatoire (piège 5).

## 4. Régler l'environnement cloud du nouveau compte (à faire par l'éditeur)

### Accès au dépôt

Le compte GitHub de l'éditeur doit être relié au nouveau compte Claude, avec accès au dépôt `besnikpiliqi/piliqiediteur` ; l'environnement le clone dans `/home/user/piliqiediteur`. Si le dépôt manque ou si une poussée est refusée : fiche de reprise § 4.1.

### Accès réseau

Réglage : menu de l'environnement cloud dans la barre de titre de la session, puis Modifier, rubrique « Network access ». L'accès doit permettre :

- apt : `archive.ubuntu.com`, `security.ubuntu.com` (faketime, et PostgreSQL ou PHP si l'image ne les a pas) ;
- Composer : `repo.packagist.org` (liste des paquets) ;
- GitHub : `github.com` (le dépôt, et les clones git des dépendances, piège 6).

Avec un niveau limité, ajouter ces domaines dans « Allowed domains » en laissant cochée la case qui autorise les gestionnaires de paquets. Documentation : https://code.claude.com/docs/en/cloud-environments#network-access

### Script d'installation (« Setup script »)

Réglage : menu de l'environnement cloud dans la barre de titre de la session, puis Modifier, champ « Setup script ». Ce script tourne au démarrage de chaque nouvelle session, avant que Claude ne commence, à partir du dossier au-dessus du dépôt (d'où les chemins absolus). Il ne peut lire ni les variables ni les secrets enregistrés dans l'environnement : `installer-session.sh` n'en a pas besoin (il tire lui-même le mot de passe de la base de test locale). S'il échoue, la session le signale ; on le corrige dans le même champ, et les nouvelles sessions prennent la version corrigée.

Version recommandée, autonome : coller dans le champ le texte suivant, où la ligne du milieu est remplacée par le contenu entier de `installer-session.sh`, sans rien y changer (la nouvelle session peut préparer ce texte complet pour l'éditeur) :

```bash
#!/bin/bash
mkdir -p /root/piliqi-outils
cat > /root/piliqi-outils/installer-session.sh <<'FIN_INSTALLER_PILIQI'
(contenu entier de installer-session.sh)
FIN_INSTALLER_PILIQI
bash /root/piliqi-outils/installer-session.sh
```

Version courte, si le script d'installation peut lire le dépôt (à essayer ; s'il ne le peut pas, garder la version autonome) :

```bash
#!/bin/bash
cd /home/user/piliqiediteur || { echo "Dépôt absent : installation PILIQI non lancée"; exit 1; }
git fetch -q origin claude/pilotage-reprise || { echo "Branche de reprise inaccessible"; exit 1; }
chemin=$(git ls-tree -r --name-only origin/claude/pilotage-reprise | grep -E '(^|/)installer-session\.sh$' | head -n 1)
[ -n "$chemin" ] || { echo "installer-session.sh introuvable dans la branche de reprise"; exit 1; }
mkdir -p /root/piliqi-outils
git show "origin/claude/pilotage-reprise:$chemin" > /root/piliqi-outils/installer-session.sh
bash /root/piliqi-outils/installer-session.sh
```

Dans les deux cas, le script reste ensuite dans le conteneur : `bash /root/piliqi-outils/installer-session.sh --verifier` le contrôle à tout moment.

L'étape Composer peut être longue (piège 6). Si le script d'installation dépasse le temps permis, remplacer sa dernière ligne par `bash /root/piliqi-outils/installer-session.sh --sans-composer`, et la session lance elle-même `bash /root/piliqi-outils/installer-session.sh` (qui ne refait alors que Composer et `.env`).

Sans script d'installation, rien n'est perdu : la session lance elle-même `bash installer-session.sh` (elle est `root` et a le réseau), mais elle doit le refaire à chaque nouveau conteneur.

### Variables et secrets

Les tests n'en ont jamais besoin : INSEE, INPI, courrier recommandé et SUPER PDP y passent par des implémentations factices (`*_PILOTE=faux` dans `phpunit.xml`), et les tests génèrent leur propre clé de signature. Les identifiants du bac à sable SUPER PDP (`SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, `SUPERPDP_TRICATEL_CLIENT_ID`, `SUPERPDP_TRICATEL_CLIENT_SECRET`, puis `SUPERPDP_URL` et `SUPERPDP_REDIRECT_URI` à l'étape 2) se mettent, le moment venu et par l'éditeur lui-même, dans les variables de l'environnement cloud : jamais dans la conversation, jamais dans le dépôt, jamais dans les notes. Le script d'installation ne les voit pas, et n'en a pas besoin.

## 5. Installation pas à pas (ce que fait le script, dans l'ordre)

Toutes les commandes se lancent en `root`. La vérification de chaque étape est donnée juste après. `bash installer-session.sh --verifier` contrôle tout d'un coup.

### 5.1 Paquets système

L'image par défaut avait déjà PHP 8.3, Composer et PostgreSQL 16. Il ne manque en général que faketime.

```bash
apt-get update -q
DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 LC_ALL=C.UTF-8 apt-get install -y -q --no-install-recommends faketime
# seulement s'ils manquent :
#   postgresql-16
#   php8.3-cli php8.3-common php8.3-curl php8.3-gd php8.3-intl php8.3-mbstring php8.3-pgsql php8.3-sqlite3 php8.3-xml php8.3-zip php8.3-readline php8.3-opcache
#   composer (paquet Ubuntu, si /usr/local/bin/composer manque), git, unzip, curl, ca-certificates, tzdata
```

`LANG` et `LC_ALL` en C.UTF-8 : si le paquet PostgreSQL crée son cluster pendant l'installation, il le crée alors en C.UTF-8.

Vérifier : `php -v` (8.3.x), `faketime --version 2>&1 | grep Version` (0.9.10), `psql --version` (16.x), `dpkg -l tzdata`.

### 5.2 Cluster PostgreSQL en UTF8 et C.UTF-8

```bash
pg_lsclusters                                   # 16 main 5432 online …
# s'il n'y a pas de cluster : pg_createcluster --locale=C.UTF-8 --encoding=UTF8 16 main
service postgresql start
sudo -u postgres psql -Atc "select pg_encoding_to_char(encoding), datcollate, datctype from pg_database where datname='template1'"
```

Attendu : `UTF8|C.UTF-8|C.UTF-8`. Sinon, et seulement si le cluster ne contient aucune donnée (aucune base ni aucun rôle ajoutés) : `pg_dropcluster --stop 16 main && pg_createcluster --locale=C.UTF-8 --encoding=UTF8 16 main && service postgresql start`. Le script vérifie lui-même que le cluster est vide (aucune base, aucun rôle, aucune table ajoutés) et refuse de supprimer un cluster qui contient des données.

### 5.3 Mot de passe de la base de test locale

Tiré au hasard, écrit une seule fois dans `~/.profile`, jamais affiché :

```bash
grep -q '^export PILIQI_PG_MOT_DE_PASSE=' ~/.profile \
  || printf '\n# Base PostgreSQL locale de test de PILIQI\nexport PILIQI_PG_MOT_DE_PASSE=%s' "$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" >> ~/.profile
```

Vérifier sans afficher la valeur : `bash -lc 'test -n "$PILIQI_PG_MOT_DE_PASSE" && echo défini'`. Ne pas reprendre le mot de passe de l'ancien conteneur : il était trop court (piège 15). Le mot de passe d'un conteneur neuf n'a de sens que dans ce conteneur ; s'il change, le script réaligne le rôle sur `~/.profile`.

### 5.4 Rôle piliqi et base piliqi_test

La commande du README du dépôt (`createuser --pwprompt`) demande le mot de passe au clavier et ne marche pas dans l'outil Bash de Claude Code, qui n'a pas de terminal. Équivalent sans saisie, où le mot de passe passe par une variable de psql (`\getenv`, psql 15 ou plus) et n'apparaît sur aucune ligne de commande :

```bash
bash -lc 'cd / && runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 -d postgres' <<'SQL'
\getenv mdp PILIQI_PG_MOT_DE_PASSE
SET log_min_error_statement = 'panic';
CREATE ROLE piliqi LOGIN CREATEDB PASSWORD :'mdp';
SQL
sudo -u postgres createdb --owner=piliqi --template=template0 --encoding=UTF8 --lc-collate=C.UTF-8 --lc-ctype=C.UTF-8 piliqi_test
```

Si le rôle existe déjà avec un autre mot de passe, remplacer `CREATE ROLE piliqi LOGIN CREATEDB PASSWORD` par `ALTER ROLE piliqi PASSWORD`. `SET log_min_error_statement = 'panic'` empêche le serveur d'écrire l'instruction, donc le mot de passe, dans son journal en cas d'erreur. Le rôle n'est pas superutilisateur, comme en CI : un test qui ne passerait que grâce à ce droit casserait en CI.

Vérifier : `bash -lc 'PGPASSWORD="$PILIQI_PG_MOT_DE_PASSE" psql -X -h 127.0.0.1 -U piliqi -d piliqi_test -Atc "select current_user"'` rend `piliqi`.

### 5.5 Essai de faketime

```bash
TZ=UTC NO_FAKE_STAT=1 faketime -f '@2027-01-02 10:00:00' date -u '+%F %T'
```

Attendu : `2027-01-02 10:00:00`.

### 5.6 Exclusion locale des tests jetables

```bash
for l in 'tests/Feature/Scratch*' 'tests/Feature/Scratch/'; do
  grep -qxF -- "$l" /home/user/piliqiediteur/.git/info/exclude || printf '%s\n' "$l" >> /home/user/piliqiediteur/.git/info/exclude
done
```

Vérifier : `grep -n Scratch /home/user/piliqiediteur/.git/info/exclude`.

### 5.7 Dépendances Composer

```bash
cd /home/user/piliqiediteur
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-interaction --no-progress
```

Jamais `composer update`, jamais `composer require` sans l'accord de l'éditeur (`CLAUDE.md` : aucune dépendance changée sans approbation). Les lignes « Failed to download … from dist … Now trying to download from source » sont normales ici (piège 6). En cas d'échec, le script refait un essai avec `--prefer-source`.

Vérifier : `bash installer-session.sh --verifier` dit « vendor conforme à composer.lock » ; `vendor/bin/phpunit`, `vendor/bin/paratest` et `vendor/bin/pint` existent.

### 5.8 Fichier .env et clé de l'application

```bash
cd /home/user/piliqiediteur
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction
```

Ne pas lancer `php artisan caisse:generer-cle` dans l'arbre de travail : il écrit une clé publique de développement, non suivie, dans `resources/caisse-securisee/cles`, ce qui rend l'arbre sale (poussée refusée, chaîne arrêtée) ; les tests génèrent leur propre clé. `php artisan migrate` n'est utile que pour essayer l'application à la main, pas pour les tests.

### 5.9 Contrôle final

`bash installer-session.sh --verifier` : chaque point est marqué `[ok]`, `[attention]` (à lire, ne bloque pas) ou `[MANQUE]` (à corriger) ; le code de sortie vaut 0 quand rien ne manque, 1 sinon. Ce mode n'écrit aucun fichier : il lit `~/.profile` sans l'exécuter, lit la version de Composer dans son fichier, n'essaie pas faketime (libfaketime crée des objets temporaires dans `/dev/shm`) et ne fait que des lectures dans PostgreSQL. Dans l'ancien conteneur, il finit par « Tout est en place (2 point(s) d'attention) » (l'espace disque et le mot de passe court) et le code 0, sans aucune écriture (contrôlé avec `strace` et des sommes de contrôle). Le mode d'installation n'a jamais été lancé dans l'ancien conteneur, où tout était déjà en place : sa première exécution réelle sera celle du conteneur neuf ; lire sa sortie étape par étape.

## 6. Premiers essais, puis les suites

Deux tests ciblés, un par moteur, avant toute chaîne :

```bash
cd /home/user/piliqiediteur
php artisan test --compact tests/Unit/CalculateurLigneTest.php; echo $?
bash -lc 'cd /home/user/piliqiediteur && DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=piliqi_test DB_USERNAME=piliqi DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --compact tests/Feature/Recherche/RechercheSansAccentsTest.php'; echo $?
```

Les suites complètes (protocole de `CLAUDE.md`) se lancent sous `bash -lc` pour lire le mot de passe, et le code de sortie se contrôle juste après (`echo $?`). Dans cet environnement, le résumé de PHPUnit sort en une ligne JSON (`{"tool":"paratest","result":"passed",…}`, laravel/pao). Ordres de grandeur dans l'ancien conteneur, avec 4 processus, le 25/10 : SQLite environ 4 min 30 (4 517 tests, dont 356 sautés parce qu'ils ne valent que pour PostgreSQL), PostgreSQL environ 11 minutes (4 517 tests, 4 sautés). La grille faketime du README du dépôt (17 instants) se lance avant un commit qui touche aux dates : `TZ=UTC NO_FAKE_STAT=1 faketime -f "@$t" …`, et, sous PostgreSQL, avec en plus les variables de connexion et `FAKETIME_DONT_RESET=1`.

## 7. Pièges connus

1. **Collation.** Les bases des tests parallèles se créent depuis `template1` : tout le cluster doit être en C.UTF-8 (ordre binaire, comme SQLite et comme la CI), pas seulement `piliqi_test`. Contrôler `template1` (encodage, collation et ctype), jamais seulement `piliqi_test`. Ne jamais supprimer un cluster ou une base qui contient des données.
2. **Rôle des tests.** LOGIN et CREATEDB (bases `piliqi_test_test_N` des processus parallèles), jamais superutilisateur, comme en CI.
3. **Mot de passe.** L'outil Bash de Claude Code ne lit pas `~/.profile` : toute commande qui en a besoin passe par `bash -lc '…'`. Une ligne dans `~/.bashrc` seule ne sert à rien : ce fichier s'arrête dès le début quand le shell n'est pas interactif. Si un `~/.bash_profile` ou un `~/.bash_login` apparaît, bash -l ne lit plus `~/.profile` (le script le signale). La valeur ne s'affiche jamais, ne va ni dans le dépôt ni dans les notes ; `pilotage-sync.sh` refuse de pousser les notes s'il la trouve dans un fichier.
4. **Redémarrage du conteneur.** PostgreSQL ne redémarre pas tout seul. Au début de chaque reprise (après une limite d'usage, une pause ou une interruption longue) : `service postgresql status`, puis `service postgresql start` s'il est arrêté. Redémarrages notés dans `notes/etat-chaines.md` : 18/10 (deux fois), 19/10 (deux fois), 24/10 ; la session a aussi relancé PostgreSQL les 10, 11, 12, 17 et 25 octobre. Un redémarrage garde le travail non commité dans l'arbre mais coupe les agents en cours (fiche de reprise § 5). `bash installer-session.sh --verifier` dit aussi si le serveur est arrêté.
5. **faketime.** Toujours `TZ=UTC` (fuseau de l'instant donné) et `NO_FAKE_STAT=1` (vraie date des fichiers). Sous PostgreSQL, `FAKETIME_DONT_RESET=1` est obligatoire : sans lui, les processus lancés par les tests de concurrence repartent de l'instant donné et voient l'horloge reculer (au premier essai à 2026-10-25 00:30 : 46 échecs, dont 45 `HorlogeRegressiveException`). L'horloge du serveur PostgreSQL n'est pas truquée : les tests qui comparent une expiration à cette horloge la lisent par `TestCase::maintenantSelonLaBase()`.
6. **Composer et GitHub.** Le proxy de l'environnement refuse les archives (dist) de GitHub (réponse 403 : « GitHub access to this repository is not enabled for this session »). Composer se rabat sur des clones git, qui passent : c'est lent (plusieurs minutes, sans doute davantage dans un conteneur sans cache) et lourd (dans l'ancien conteneur, `vendor` faisait 4,6 Go et le cache `~/.cache/composer` 4,3 Go). D'où `COMPOSER_PROCESS_TIMEOUT=1800`. Le message « Composer plugins have been disabled for safety » (root, non interactif) est sans effet : `composer.lock` n'a aucun greffon. C'est aussi pourquoi Larastan a été abandonné le 02/10. Ne jamais lancer `composer update`.
7. **Disque plein.** Le 18/10, une suite a échoué en écrivant `storage/logs/laravel.log` (`errno=28`). Le 26/10, il restait 6,9 Go libres, le dossier temporaire de la session pesait 16 Go (copies du code, copies figées pour les ateliers) et `storage/logs/laravel.log` 25 Mo. Avant une longue course : `df -h /` ; supprimer les copies de travail devenues inutiles du dossier temporaire ; vider `storage/logs/laravel.log` (ignoré par git) si besoin.
8. **Tests jetables.** Sans l'exclusion de `tests/Feature/Scratch*`, un fichier jetable oublié par un agent rend l'arbre sale : la poussée refuse et la chaîne s'arrête, ou le fichier part dans un commit.
9. **Clé de développement.** `caisse:generer-cle` écrit dans `resources/caisse-securisee/cles` : jamais dans l'arbre de travail (le script signale une clé non suivie).
10. **Version de PostgreSQL.** 16 en local (accepté pour les tests locaux), 17 en CI, 17 ou 18 en production. Une différence de comportement entre versions se voit en CI : vérifier la CI de chaque tête poussée (fiche de reprise § 5).
11. **memory_limit.** PHP en ligne de commande était sans limite (`-1`). Si une nouvelle image en fixe une, des processus de la suite parallèle peuvent mourir : le script le signale en `[attention]`.
12. **Script d'installation du cloud.** Il ne voit pas les variables de l'environnement, part du dossier au-dessus du dépôt et a besoin du réseau vers apt, Packagist et GitHub (section 4).
13. **Moteurs.** Ne pas réinstaller MariaDB ni aucun autre moteur : PostgreSQL et SQLite seulement (`CLAUDE.md`, puce « Outillage »).
14. **Valeurs secrètes.** Ne jamais demander à l'éditeur de coller une clé, un jeton ou un mot de passe dans la conversation ; les identifiants passent seulement par les variables de l'environnement (fiche de reprise § 3).
15. **Contrôle des valeurs secrètes de `pilotage-sync.sh`.** Au 26/10, il ne marche pas : la commande `grep -rlF -- "$val" "$W" --exclude-dir=.git` place `--exclude-dir=.git` après `--`, donc grep le prend pour un fichier, échoue, et le script conclut qu'aucune valeur n'est présente. Seul le premier contrôle (en-têtes de clé, jetons, adresses de messagerie) fonctionne. Correction : écrire `grep -rlF --exclude-dir=.git -- "$val" "$W"`. Avant de la faire, garder un mot de passe de base de test tiré au hasard (comme le fait `installer-session.sh`) : l'ancien était un mot court qui figure partout dans les notes, et le contrôle corrigé aurait alors refusé chaque poussée.

## 8. Ce que le script ne fait pas

- Relier GitHub, choisir la branche de travail et vérifier la tête poussée : fiche de reprise § 4.1.
- Récupérer la branche de reprise et copier `notes/` dans le dossier temporaire de la nouvelle session, puis adapter les chemins et le lien de session : fiche de reprise § 4.3 et § 4.4.
- Activer l'outil Workflow (ultracode) sur le nouveau compte : demande à l'éditeur, fiche de reprise § 4.8.
- Mettre les identifiants SUPER PDP dans l'environnement : l'éditeur, à l'étape 2.
- Lancer les suites de tests : c'est le travail de chaque lot, selon `CLAUDE.md`.
