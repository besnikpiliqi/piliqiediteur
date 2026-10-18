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

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan caisse:generer-cle
php artisan migrate
php artisan test
```

Pour lancer la suite sur PostgreSQL en local, créez une fois le rôle des tests, autorisé à créer les bases des tests parallèles, et sa base. Ces bases se créent depuis `template1` : le serveur doit avoir été initialisé en C.UTF-8 (`initdb --locale=C.UTF-8`), comme celui de la CI.

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

`caisse:generer-cle` épingle une clé publique de signature fiscale dans `resources/caisse-securisee/cles` et affiche une seule fois la clé privée, à placer dans `CAISSE_SECURISEE_CLE_PRIVEE`. Sans elle, le module fiscal refuse tout ce qu'il doit signer, ouverture de session dans un magasin comprise (voir `php artisan caisse:diagnostiquer`). Une clé générée pour le développement ne se versionne jamais : seules les clés de production de l'éditeur sont épinglées dans le dépôt. Les tests génèrent leur propre clé.

`caisse:verifier` vérifie l'intégrité de la caisse sécurisée de chaque magasin (chaînes signées, documents, numérotation), l'inscrit au journal des événements et échoue au moindre défaut ; le planificateur la lance chaque nuit à 3 h, complète une fois par mois pour chaque magasin.

En développement, trois commandes tournent côte à côte : le serveur du panneau, la file d'attente (e-mails, courriers et transmissions à la plateforme) et le planificateur (clôture automatique de minuit et caisses personnelles, factures en retard, vérification de la caisse sécurisée, relances, revérifications Sirene, démonstrations inactives, effacement des traces, des pièces, des jetons et des demandes d'inscription expirés, journal d'audit expiré).

```sh
php artisan serve
php artisan queue:listen
php artisan schedule:work
```

Une société s'inscrit depuis `/app/register` avec son SIREN et son propriétaire, sans magasin, par un lien envoyé à l'adresse du propriétaire, qui arrive ensuite dans l'administration de la société (`/societe/{uuid}`), sur la liste de ses premières étapes. Tous ses magasins s'y créent, en choisissant leur établissement parmi ceux que le répertoire Sirene liste pour son SIREN (ou en saisissant son NIC). Elle ne vend qu'une fois validée (Sirene, qualité de représentant) et dans un magasin réel, et s'essaie avant dans un magasin de démonstration. Les procédures exceptionnelles de l'éditeur : `php artisan list editeur` (opérateurs déclarés dans `EDITEUR_OPERATEURS`). Les fournisseurs externes (INSEE, INPI, vérification d'identité, envoi recommandé) se choisissent par les variables `*_PILOTE` de `.env.example` ; `faux` sert au développement et aux tests, jamais en production.
