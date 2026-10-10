# Piliqi Editeur

Logiciel de gestion vendu en SaaS à des magasins. Chaque magasin travaille dans son propre espace, strictement isolé des autres : clients, produits, documents et réglages ne sont jamais partagés.

Il couvre :

- les clients, les fournisseurs, les produits et les services du magasin ;
- les devis, les factures et les avoirs, numérotés sans doublon et imprimables en PDF ;
- les paiements des clients et leur répartition sur les factures ;
- la caisse, la fermeture de caisse de chaque vendeur et la clôture de la journée ;
- la caisse sécurisée (art. 286 I-3° bis du CGI) : ventes, encaissements et journal des événements signés et chaînés, consultables et exportables par le magasin ;
- les factures d'achat, les avoirs fournisseur, le stock et son coût moyen pondéré ;
- l'envoi des documents par e-mail et les relances des factures impayées ;
- l'e-reporting et la transmission des factures électroniques, par une passerelle factice tant que la plateforme agréée n'est pas raccordée.

## Technique

Laravel 13, Filament 3 (panneau des magasins sous `/app`), PHP 8.3 ou plus avec les extensions openssl et zip. Aucun outil Node n'est nécessaire : Filament publie ses propres fichiers.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan caisse:generer-cle
php artisan migrate
php artisan test
```

`caisse:generer-cle` épingle une clé publique de signature fiscale dans `resources/caisse-securisee/cles` et affiche une seule fois la clé privée, à placer dans `CAISSE_SECURISEE_CLE_PRIVEE`. Sans elle, le module fiscal refuse tout ce qu'il doit signer, ouverture de session dans un magasin comprise (voir `php artisan caisse:diagnostiquer`). Une clé générée pour le développement ne se versionne jamais : seules les clés de production de l'éditeur sont épinglées dans le dépôt. Les tests génèrent leur propre clé.

`caisse:verifier` vérifie l'intégrité de la caisse sécurisée de chaque magasin (chaînes signées, documents, numérotation), l'inscrit au journal des événements et échoue au moindre défaut ; le planificateur la lance chaque nuit à 3 h, complète une fois par mois pour chaque magasin.

En développement, trois commandes tournent côte à côte : le serveur du panneau, la file d'attente (e-mails et transmissions à la plateforme) et le planificateur (clôture automatique de minuit, factures en retard, e-reporting, vérification de la caisse sécurisée, relances).

```sh
php artisan serve
php artisan queue:listen
php artisan schedule:work
```

Un magasin se crée depuis la page d'inscription du panneau (`/app/register`).

Les magasins d'une même société (même SIREN) partagent leurs réglages communs (régime de TVA, option pour les débits, n° de TVA, raison sociale, forme juridique, capital, immatriculation), que fixe le magasin principal : le plus ancien magasin non supprimé du SIREN, ou celui que l'éditeur désigne avec `php artisan app:designer-magasin-principal {magasin}`. Un autre magasin du SIREN n'enregistre que ces valeurs, n'émet ni facture ni ticket et ne quitte pas la société tant qu'il ne les a pas.
