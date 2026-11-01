# Piliqi Editeur

Logiciel de gestion vendu en SaaS à des entreprises (sociétés ou entrepreneurs individuels, appelées « sociétés » dans le logiciel) pour leurs magasins. Chaque société est strictement isolée des autres et, dans une société, chaque magasin travaille dans son propre espace : clients, produits, fournisseurs, documents, stock, caisse et réglages locaux ne sont jamais partagés ; l'identité légale et les réglages fiscaux communs sont ceux de la société.

Il couvre :

- les clients, les fournisseurs, les produits et les services du magasin ;
- les devis, les factures et les avoirs, numérotés sans doublon et imprimables en PDF ;
- les paiements des clients et leur répartition sur les factures ;
- la caisse, la fermeture de caisse de chaque vendeur et la clôture de la journée ;
- la caisse sécurisée (art. 286 I-3° bis du CGI) : ventes, encaissements et journal des événements signés et chaînés, consultables et exportables par le magasin ;
- les factures d'achat, les avoirs fournisseur, le stock et son coût moyen pondéré ;
- l'envoi des documents par e-mail et les relances des factures impayées ;
- l'aperçu de l'e-reporting de chaque journée clôturée, dont l'envoi attend le raccordement de la plateforme agréée, et la transmission des factures électroniques, par une passerelle factice d'ici là.

## Technique

Laravel 13, Filament 3 (panneau des magasins sous `/app`), PHP 8.3 ou plus avec les extensions openssl, zip et pdo_pgsql. Aucun outil Node n'est nécessaire : Filament publie ses propres fichiers.

PostgreSQL (17 ou 18) en production, SQLite en développement et pour les tests rapides. La suite complète passe sur les deux moteurs, en local avant chaque commit et en CI. La base PostgreSQL est en UTF8 avec la collation déterministe C.UTF-8 : elle trie dans l'ordre binaire, comme SQLite. Les recherches ignorent la casse et les accents par une même fonction SQL sur les deux moteurs, que crée une migration sous PostgreSQL : aucune extension à installer.

**Heure.** L'application travaille en UTC : chaque horodatage s'enregistre en UTC (`config/app.php`), et la session PostgreSQL aussi (`config/database.php`). Les journaux techniques de Laravel sont donc en UTC. Le calendrier de chaque magasin (journée de caisse, échéances, année des numéros, affichage) suit l'heure de son territoire : Paris pour la métropole et la Corse, la sienne pour chaque territoire d'outre-mer accepté (aujourd'hui la Guadeloupe, la Martinique et La Réunion). Ce territoire est fixé au premier contrôle Sirene de l'établissement et ne change plus. La société, ses courriers et l'éditeur suivent l'heure de Paris. Seule la classe `App\Domain\Calendrier\Calendrier` connaît un fuseau. Le planificateur suit l'heure de Paris (`schedule_timezone`) ; les tâches du calendrier des magasins passent toutes les heures. Aucun code ne dépend du fuseau de la session de base. Le fuseau par défaut de la base, celui des sessions ouvertes hors de l'application (psql, sauvegardes, scripts, répartiteur de connexions qui perd le réglage de la session), est fixé à UTC par les migrations, sous PostgreSQL (`ALTER DATABASE` sur la base courante) : aucune commande manuelle n'est à retenir, mais le rôle qui lance les migrations doit être propriétaire de la base, sinon la migration échoue en disant quoi faire, sauf si ce réglage est déjà à UTC. La migration ne passe qu'une fois : une sauvegarde logique (`pg_dump`) restaurée dans une nouvelle base sans `--create` perd ce réglage, que `php artisan migrate` ne refait pas. `php artisan caisse:diagnostiquer` vérifie le fuseau de l'application et le fuseau par défaut que reçoit une session de la base (`pg_settings.reset_val`), qu'un réglage du rôle ou des options de connexion remplaceraient ; en échec, il en nomme la cause (réglage de la base absent ou faux, réglage du rôle, options de connexion) et donne la commande qui la corrige. `SHOW TIMEZONE` ne le montre pas : il rend l'UTC que l'application fixe à chaque connexion.

## Installation locale

1. **PHP 8.3 ou plus et ses extensions.** Activez dans `php.ini` (une ligne `extension=…` par extension, sans le `;` qui la commente) : curl, fileinfo, intl, mbstring, openssl, pdo_pgsql, pdo_sqlite, sqlite3 et zip. `php -m` liste les extensions chargées : chacune doit y figurer (sous Windows, `php -m | findstr /i "curl fileinfo intl mbstring openssl pdo_pgsql pdo_sqlite sqlite3 zip"`). `composer check-platform-reqs` ne suffit pas : il ne contrôle que les extensions qu'exigent `composer.json` et les dépendances, jamais pdo_sqlite, sqlite3 ni curl, et tient mbstring pour présente grâce à une bibliothèque de remplacement ; sans pdo_sqlite, `php artisan migrate` échoue sur SQLite avec « could not find driver ».
2. **Sous Windows (XAMPP compris).** Le fichier `php.ini` de XAMPP est `C:\xampp\php\php.ini`. PHP n'y trouve le fichier `openssl.cnf` que si la variable d'environnement `OPENSSL_CONF` le désigne : sans elle, aucune clé ne se génère, et toute la suite de tests échoue avec « OpenSSL n'a pas pu générer de clé ECDSA P-256 ». Réglez-la une fois dans une invite de commandes :

   ```bat
   setx OPENSSL_CONF "C:\xampp\php\extras\ssl\openssl.cnf"
   ```

   `setx` règle une variable de l'utilisateur, que seuls voient les programmes lancés ensuite : fermez et rouvrez le terminal, l'éditeur de code qui l'ouvre (VS Code, par exemple) et le XAMPP Control Panel, puis redémarrez Apache s'il sert l'application. Un Apache installé comme service Windows (compte Système local) ne voit pas une variable de l'utilisateur : réglez-la alors pour tout le système, dans une invite de commandes lancée en administrateur, puis redémarrez Windows, car un service ne voit une variable du système qu'après un redémarrage :

   ```bat
   setx /M OPENSSL_CONF "C:\xampp\php\extras\ssl\openssl.cnf"
   ```

   Selon la version de XAMPP, le fichier peut aussi être `C:\xampp\apache\conf\openssl.cnf` ; avec PHP seul, c'est le fichier `extras\ssl\openssl.cnf` de son dossier.
3. **Dépendances.** `composer install`, et jamais `composer update` : `composer.lock` fixe les versions que vérifient la suite de tests et la CI, et changer une dépendance demande l'accord de l'éditeur.
4. **Environnement.** Copiez `.env.example` en `.env` (`copy .env.example .env` sous Windows), puis lancez `php artisan key:generate`. En développement, la base est SQLite (`database/database.sqlite`, que `php artisan migrate` propose de créer) et les e-mails s'écrivent dans `storage/logs/laravel.log` au lieu de partir (`MAIL_MAILER=log`).
5. **Clé de signature fiscale.** `php artisan caisse:generer-cle` épingle sa clé publique et affiche une seule fois la ligne `CAISSE_SECURISEE_CLE_PRIVEE=…`, à recopier dans `.env` (voir plus bas).
6. **Clé du journal d'audit.** `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` affiche une clé aléatoire : recopiez-la dans `JOURNAL_AUDIT_CLE`, et donnez-lui un identifiant de votre choix dans `JOURNAL_AUDIT_CLE_ID` (par exemple `dev-1`). Sans elle, aucune action auditée ne s'enregistre : la confirmation d'une inscription échoue (`JournalAuditNonConfigureException`).
7. **Base.** `php artisan migrate`.
8. **Contrôle.** `php artisan caisse:diagnostiquer` vérifie OpenSSL (une clé s'y génère et s'exporte), zip, les clés de signature, la clé du journal d'audit et les fuseaux, sans jamais afficher une clé ; chaque ligne doit dire OK, et une ligne en échec dit quoi faire.
9. **Tests.** `php artisan test`, ou `php artisan test --parallel`.
10. **Lancement.** Les trois commandes du développement (voir plus bas) : `php artisan serve`, `php artisan queue:listen` et `php artisan schedule:work`.
11. **Première inscription.** Sur `/app/register` (voir plus bas), le lien de confirmation de l'inscription, valable 24 heures, s'écrit dans `storage/logs/laravel.log` : ouvrez l'adresse en `/inscription/…` de l'e-mail « Confirmez l'inscription de votre société », le dernier du fichier.

## Tests, commandes et fonctionnement

Pour lancer la suite sur PostgreSQL en local, créez une fois le rôle des tests, autorisé à créer les bases des tests parallèles, et sa base. Ces bases se créent depuis `template1` : le serveur doit avoir été initialisé en C.UTF-8 (`initdb --locale=C.UTF-8`), comme celui de la CI. Le rôle des tests est propriétaire de chacune : les migrations y fixent le fuseau par défaut à UTC.

```sh
sudo service postgresql start
sudo -u postgres createuser --createdb --pwprompt piliqi
sudo -u postgres createdb --owner=piliqi --template=template0 --encoding=UTF8 --lc-collate=C.UTF-8 --lc-ctype=C.UTF-8 piliqi_test
```

Puis, avant chaque commit, la suite sur SQLite et sur PostgreSQL (le mot de passe choisi ci-dessus dans `PILIQI_PG_MOT_DE_PASSE`) :

```sh
php artisan test --parallel
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=piliqi_test DB_USERNAME=piliqi DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --parallel
```

**Suite sous faketime.** Avant un commit qui touche aux dates, la suite passe aussi à des instants choisis (`sudo apt-get install faketime`). `TZ=UTC` fixe le fuseau de l'instant donné à faketime ; `NO_FAKE_STAT=1` laisse aux fichiers leur vraie date, comme sur l'horloge réelle.

```sh
for t in '2026-10-25 00:30:00' '2026-10-25 01:30:00' '2027-03-28 00:59:00' '2027-03-28 01:30:00' \
         '2027-01-02 10:00:00' '2026-12-31 22:59:00' '2026-10-25 00:59:00' '2028-02-29 12:00:00' \
         '2026-01-31 12:00:00' '2026-12-31 23:30:00' '2026-07-14 22:30:00' '2026-10-25 22:59:30' \
         '2026-12-31 20:30:00' '2027-01-01 03:30:00' '2026-07-14 03:30:00' '2026-11-17 03:59:30' \
         '2026-12-31 19:59:30'; do
  TZ=UTC NO_FAKE_STAT=1 faketime -f "@$t" php artisan test --parallel; echo "$t : $?"
done
```

Chaque ligne doit finir par 0. Sous PostgreSQL, ajoutez les variables de la commande ci-dessus et `FAKETIME_DONT_RESET=1` : les tests de concurrence lancent des processus, qui sans elle repartiraient de l'instant donné et verraient l'horloge reculer (`HorlogeRegressiveException`). L'horloge du serveur de base n'est pas décalée, et les tests qui comparent une expiration à cette horloge la lisent (`TestCase::maintenantSelonLaBase()`).

`caisse:generer-cle` épingle une clé publique de signature fiscale dans `resources/caisse-securisee/cles` et affiche une seule fois la clé privée, à placer dans `CAISSE_SECURISEE_CLE_PRIVEE`. Sans elle, le module fiscal refuse tout ce qu'il doit signer, ouverture de session dans un magasin comprise (voir `php artisan caisse:diagnostiquer`). Une clé générée pour le développement ne se versionne jamais : seules les clés de production de l'éditeur sont épinglées dans le dépôt. Les tests génèrent leur propre clé.

`caisse:verifier` vérifie l'intégrité de la caisse sécurisée de chaque magasin (chaînes signées, documents, numérotation), l'inscrit au journal des événements et échoue au moindre défaut ; le planificateur la lance chaque nuit à 3 h, complète une fois par mois pour chaque magasin.

En développement, trois commandes tournent côte à côte : le serveur du panneau, la file d'attente (e-mails, courriers et transmissions à la plateforme) et le planificateur (clôture automatique de minuit et caisses personnelles, factures en retard, vérification de la caisse sécurisée, relances, revérifications Sirene, suivi des courriers recommandés, clôture des inscriptions sans code, démonstrations inactives, effacement des traces, des pièces, des jetons et des demandes d'inscription expirés, journal d'audit expiré).

```sh
php artisan serve
php artisan queue:listen
php artisan schedule:work
```

Une société s'inscrit depuis `/app/register` avec son SIREN, son type d'activité (« Commerce ou atelier », « Services, sans vente d'articles » ou « Cabinet d'expertise comptable », encore modifiable sur la page du lien, puis définitif), l'adresse e-mail et le mot de passe de son propriétaire, sans magasin, par un lien envoyé à cette adresse ; en local, le répertoire Sirene factice donne à tout SIREN fictif le code d'activité 47.11B, et un cabinet s'inscrit avec `FauxRepertoireSirene::SIREN_CABINET` (000000943, code 69.20Z) ; sa confirmation fait partir un courrier recommandé au représentant légal de l'entreprise, à l'adresse officielle de son siège, et mène le propriétaire dans l'administration de la société (`/societe/{uuid}`), sur la liste de ses premières étapes. Tous ses magasins s'y créent, en choisissant leur établissement parmi ceux que le répertoire Sirene liste pour son SIREN (ou en saisissant son NIC). Elle ne vend qu'une fois validée, par le code du courrier saisi sur la page `/code` (que le QR code du courrier ouvre), et dans un magasin réel, et s'essaie avant dans un magasin de démonstration. En développement, le prestataire d'envoi factice écrit chaque courrier en PDF dans `storage/app/private/courriers-factices`, où lire son code. Les procédures exceptionnelles de l'éditeur : `php artisan list editeur` (opérateurs déclarés dans `EDITEUR_OPERATEURS`) ; le code d'un courrier d'une procédure se saisit sur la page de son motif (`/code/recuperation`, `/code/liberation_siret`, `/code/revocation`, `/code/examen_siren`), qui prévient l'éditeur. L'espace d'un cabinet d'expertise comptable est `/cabinet/{uuid}` (clients liés, collaborateurs, invitations et demandes). L'inscription à l'Ordre se vérifie par la procédure `php artisan editeur:cabinet:verifier-ordre` tant qu'aucun accès automatique à l'annuaire de l'Ordre n'existe (pilote `ORDRE_PILOTE`). Le cabinet invite un client par son SIREN : un SIREN libre reçoit une invitation, acceptée sur `/invitation-cabinet/{jeton}` ; un SIREN déjà inscrit reçoit une demande de rattachement, ouverte par son propriétaire depuis `/rattachement-cabinet/{jeton}`. Les fournisseurs externes (INSEE, INPI, envoi recommandé, annuaire de l'Ordre des experts-comptables) se choisissent par les variables `*_PILOTE` de `.env.example` ; `faux` sert au développement et aux tests, jamais en production.

Chaque compte se connecte avec son identifiant de 8 chiffres, que PILIQI tire au hasard à sa création ; le propriétaire peut aussi utiliser son adresse e-mail. Le premier mot de passe d'un compte créé par la société est son identifiant : il n'ouvre ce compte que depuis un poste du magasin reconnu par le propriétaire ou un responsable (paramètres du magasin, « Postes reconnus »), et un responsable ou un administrateur choisit le sien à sa première connexion ; un collaborateur d'un cabinet d'expertise comptable, qui n'a aucun poste reconnu, choisit le sien en sa présence, sur l'écran du propriétaire du cabinet qui crée son compte ou lui redonne l'accès. Les essais manqués font attendre (jamais sur un poste où le compte s'est déjà connecté) ; ils se comptent par identifiant et par adresse IP. En production, derrière un répartiteur de charge, déclarez ses adresses exactes dans `PROXIES_DE_CONFIANCE` (séparées par des virgules ; `*`, `**` et `REMOTE_ADDR` sont ignorés) : sans elles, l'application ne croit aucun en-tête `X-Forwarded-For`, même sur Laravel Cloud, Forge ou Vapor, et compte les essais sur l'adresse de la connexion. `AUTH_DELAI_CONSTANT_US` (500 000 µs par défaut) fixe la durée minimale d'un essai manqué, au-dessus de la vérification d'un mot de passe haché au coût `BCRYPT_ROUNDS` : le délai de la réponse ne dit jamais qu'un identifiant existe.
