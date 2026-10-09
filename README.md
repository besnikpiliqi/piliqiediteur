# Piliqi Editeur

Logiciel de gestion vendu en SaaS à des magasins. Chaque magasin travaille dans son propre espace, strictement isolé des autres : clients, produits, documents et réglages ne sont jamais partagés.

Il couvre :

- les clients, les fournisseurs, les produits et les services du magasin ;
- les devis, les factures et les avoirs, numérotés sans doublon et imprimables en PDF ;
- les paiements des clients et leur répartition sur les factures ;
- la caisse, la fermeture de caisse de chaque vendeur et la clôture de la journée ;
- les factures d'achat, les avoirs fournisseur, le stock et son coût moyen pondéré ;
- l'envoi des documents par e-mail et les relances des factures impayées ;
- l'e-reporting et la transmission des factures électroniques, par une passerelle factice tant que la plateforme agréée n'est pas raccordée.

## Technique

Laravel 13, Filament 3 (panneau des magasins sous `/app`), PHP 8.3 ou plus.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

Un magasin se crée depuis la page d'inscription du panneau (`/app/register`).
