# Protocole de rigueur (prioritaire sur toutes les autres règles)

1. Jamais de raccourci : pas de `// ...`, de « reste inchangé », de TODO, de fonction vide ni de code incomplet. Chaque fichier écrit est complet.
2. À chaque modification, vérifier l'isolation des magasins : tout modèle de données d'un magasin utilise `BelongsToMagasin` ; toute requête qui contourne `MagasinScope` (`withoutGlobalScopes`, `DB::table`, requête brute, job, commande planifiée) filtre explicitement par magasin ; toute règle `unique`/`exists` est limitée au magasin.
3. Pendant le travail, lancer les tests ciblés (`php artisan test --compact <fichier>`) ; avant chaque commit, lancer toute la suite `php artisan test --compact`.
4. Si un test échoue, corriger soi-même la cause et relancer jusqu'à ce que tout soit vert. Ne jamais supprimer, affaiblir ou réécrire un test pour le faire passer. Si l'échec révèle une règle métier à trancher (calcul, arrondi, obligation légale), la soumettre à l'utilisateur au lieu de choisir.
5. Avant chaque commit, lancer `vendor/bin/pint --dirty --format agent`.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

<projet-piliqiediteur>
# Règles du projet

## Développement : toujours repartir de zéro

- Le logiciel est encore en développement et aucune donnée réelle n'existe : on ne garde aucune trace d'un état antérieur, mais on code tout comme pour la production.
- Chaque table est définie par UNE seule migration de création qui contient directement son schéma final. Pour changer une table, on modifie sa migration de création (jamais de migration `add_x_to_y`, de renommage de colonne ni de reprise de données), puis on recrée la base avec `php artisan migrate:fresh`.
- Aucun code, cast, règle de validation, commentaire ou test qui n'existe que pour gérer des données enregistrées avant un changement.
- À chaque modification ou ajout, si le code touché peut être réduit ou rendu plus lisible, on le réécrit proprement, même si cela supprime ou change ce qui existait déjà. On ne crée jamais une fonction, une classe, une table, une colonne ou quoi que ce soit d'autre uniquement pour éviter de supprimer ou de modifier l'existant : si réécrire donne un code plus simple, on réécrit. Les fonctions, colonnes, tests et commentaires devenus inutiles sont supprimés.
- Seulement une fois le logiciel en production, on écrira le code de façon à ne rien casser ni entrer en conflit avec ce qui existe déjà.

## Produit

- Logiciel indépendant (SaaS) vendu à des magasins : son créateur en est l'éditeur, il n'a pas de magasin.
- Chaque magasin est strictement isolé (`BelongsToMagasin` + `MagasinScope`) : aucun client, produit, service, document ou réglage n'est jamais partagé ni mélangé entre magasins.
- Prix : seul le prix de vente TTC est un prix arrondi, une seule fois, quand la facture d'achat enregistre le produit. Une vente se calcule depuis ce prix TTC, article par article (PU HT et PU TTC au centime, multipliés par la quantité). Un achat se calcule depuis le PU HT du fournisseur : HT = quantité x PU HT, puis la TVA sur ce HT. Ensuite plus rien ne s'arrondit ni ne se recalcule : les documents affichent les montants tels qu'ils sont enregistrés.

## Feuille de route

Le développeur (Claude) mène le projet en autonomie jusqu'à un logiciel prêt pour la production. Chaque étape suit le protocole de rigueur, puis : relecture adversariale, commit, push, CI verte. Cocher une étape seulement quand elle est terminée.

Fait :
- [x] Socle : magasins (tenants), utilisateurs, rôles et permissions par magasin, isolation testée, panel Filament du magasin.
- [x] Clients, fournisseurs, produits, services du magasin.
- [x] Devis, factures, avoirs (partiels, multi-factures), paiements et allocations, numérotation sans doublon, PDF, factures jamais supprimables.
- [x] Caisse, clôtures de caisse (journée, fermeture personnelle, clôture automatique), e-reporting, transmission PDP (passerelle factice).
- [x] Factures d'achat (saisie, API), avoirs fournisseur, stock (grand livre des mouvements), CMUP, barème de prix de vente.

À faire, dans cet ordre :
1. [ ] Corrections : ventes toujours au prix TTC et achats toujours au prix HT, retours partiels cumulés, défauts de l'audit complet.
2. [ ] Caisse sécurisée (art. 286 CGI) : plus d'annulation de facture émise, chaînage et signature des ventes, journal des événements (JET), clôtures mensuelles et annuelles avec totaux perpétuels, archives signées, attestation de l'éditeur.
3. [ ] Journal d'audit des actions importantes (utilisateur, magasin, action, document, anciennes et nouvelles valeurs, IP, navigateur), consultable par le propriétaire.
4. [ ] Envoi des devis, factures et avoirs par e-mail, notifications, échéances et relances des factures impayées.
5. [ ] Comptabilité : journaux (ventes, achats, caisse et banque), écritures générées depuis chaque document, plan de comptes du magasin, export FEC, état de TVA (collectée, déductible, à payer).
6. [ ] Tableau de bord et rapports : chiffre d'affaires, marges, TVA, meilleurs produits, stock, impayés.
7. [ ] Stock : inventaire (ouverture et comptage), ajustements, catégories, unités.
8. [ ] Pièces jointes isolées par magasin, contacts des clients et fournisseurs, coordonnées bancaires des fournisseurs.
9. [ ] RGPD : export et anonymisation des clients, durées de conservation (documents légaux conservés à part).
10. [ ] API v1 (jetons, ressources, Form Requests, limitation de débit, pagination) et webhooks.
11. [ ] Couche d'intégrations externes (paiement en ligne, outils comptables), branchée sur les événements. Plateforme agréée retenue par l'éditeur pour la facture électronique et l'e-reporting : SUPER PDP (API OAuth2, norme AFNOR XP Z12-013, documentation https://www.superpdp.tech/openapi/#superpdp), à brancher derrière PdpGatewayInterface à la place de la passerelle factice. Accès confirmé par la documentation SUPER PDP : chaque magasin délègue l'accès à son compte SUPER PDP à l'application OAuth de l'éditeur (flux OAuth 2.1 « authorization code », tunnel d'inscription si le magasin n'a pas encore de compte) ; une seule application OAuth créée par l'éditeur dans l'interface SUPER PDP (client_id, client_secret, URL de redirection identique à celle déclarée, aucun scope) ; autorisation https://api.superpdp.tech/oauth2/authorize, jetons https://api.superpdp.tech/oauth2/token ; préremplissage possible : login_hint (e-mail), superpdp_company_number + superpdp_company_number_scheme (sandbox, fr_siren, be_numero_entreprise), superpdp_directory_entry_identifier (adresse de réception d'une entreprise fr_siren), superpdp_send_and_receive (any par défaut, send, receive). Exemples officiels : https://github.com/superpdp/examples (erp.go pour l'OAuth, quick_start.js, facturx.js). Convention retenue avec l'éditeur : route de retour OAuth /superpdp/retour (URL déclarée dans l'application SUPER PDP : http://127.0.0.1:8000/superpdp/retour et http://localhost:8000/superpdp/retour en local), variables d'environnement SUPERPDP_URL, SUPERPDP_CLIENT_ID, SUPERPDP_CLIENT_SECRET, SUPERPDP_REDIRECT_URI ; format de conversion AFNOR préféré : Factur-X ; application de type confidentielle. Bac à sable de l'éditeur : entreprises fictives Tricatel (n° 000000001, adresses Peppol 0225:315143296_110451 et _replyto) et Burger Queen (n° 000000002, réel normal mensuel, adresses Peppol 0225:315143296_110452 et _replyto, mandat de facturation de Tricatel vérifié).
12. [ ] Abonnements SaaS : plans, limites, fonctionnalités par plan, facturation des magasins par l'éditeur.
13. [ ] Administration de l'éditeur : magasins, utilisateurs, abonnements, suspension, statistiques, journaux système ; accès aux données d'un magasin seulement explicite et audité.
14. [ ] Achats auprès de particuliers (registre des objets achetés, régime de TVA sur marge).
15. [ ] Mise en production : analyse statique, CI complète, configuration de production (MySQL, Redis, files d'attente, planificateur, sauvegardes), données de démonstration, finition des écrans.
</projet-piliqiediteur>
