# Relecture adversariale du diagnostic « 419 Page Expired » (fenêtres « Chercher un client » et « Ajouter un produit »)

Date : 02/11/2026. Copie isolée au commit poussé 53ed088 (git worktree détaché), base SQLite jetable, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`, pilotes factices, serveur `php artisan serve` sur 127.0.0.1:8765. Je n'ai rien modifié dans /home/user/piliqiediteur : j'y ai seulement lu des fichiers et utilisé le worktree.

## Verdict

**Le diagnostic est confirmé : hypothèse H1, un défaut du code. Je n'ai pas pu le réfuter.**

Les composants `App\Filament\App\Livewire\SelecteurDeClients` et `SelecteurDeProduits` ne sont enregistrés nulle part auprès de Livewire. Les vues des fenêtres les montent par leur classe, et Livewire leur donne alors le nom généré `app.filament.app.livewire.selecteur-de-…`. À la requête suivante, Livewire ne sait pas retrouver la classe à partir de ce nom : `ReleaseToken::verify` attrape la `ComponentNotFoundException` et lève à la place `LivewireReleaseTokenMismatchException`, une erreur HTTP 419.

Ce n'est pas un refus du jeton CSRF : la session et le jeton ne bougent pas. Le 419 se produit dans un seul onglet, avec un seul compte, au premier clic sur une rangée et à la première frappe dans la recherche. Il touche aussi les pages de création de facture et de caisse, ce que j'ai prouvé par des tests HTTP. Le premier agent, lui, l'avait seulement déduit du code.

H2 (un second compte dans un autre onglet) et H3 (session effacée) ne causent pas ce 419 sur 127.0.0.1. Une fois le défaut corrigé, H2 affiche une fenêtre « 404 Not Found » et H3 renvoie à la page de connexion. Un vrai 419 CSRF n'apparaît que sur une adresse que le navigateur ne juge pas sûre (http avec un nom d'hôte). H4 est écartée : aucune erreur SQLite ni verrou, et la session reste la même.

## Expériences refaites (avec mes propres scripts et sans réutiliser ceux du premier agent)

J'ai remis la copie dans l'état exact de 53ed088 : le premier agent avait laissé la correction appliquée. J'ai ajouté un seul journal de diagnostic, qui ne change aucune réponse : un `render` dans `bootstrap/app.php` qui inscrit, pour chaque réponse 419 ou 404, la classe de l'exception et sa précédente, le nom du composant, `Sec-Fetch-Site`, la session, le jeton de la session, le jeton envoyé et l'utilisateur. Je l'ai retiré avant de lancer la suite.

### A. Défaut présent (53ed088), sur http://127.0.0.1:8765

| Scénario | Fenêtre | Requête qui échoue | Réponse | Exception côté serveur | Jetons (session / envoyé) |
|---|---|---|---|---|---|
| un seul onglet | clients | `choisir(["1"])` sur `app.filament.app.livewire.selecteur-de-clients` | 419, question « This page has expired… », puis fenêtre « 419 Page Expired » (identique aux captures de l'éditeur) | `LivewireReleaseTokenMismatchException`, aucune précédente | GcUM17E0 / GcUM17E0, **égaux** ; session va1efYmY inchangée, user 1 |
| un seul onglet | produits | `basculer(["1"])` sur `…selecteur-de-produits` | 419, même question | idem | XpO1l6A1 / XpO1l6A1, **égaux** |
| un seul onglet | clients, recherche « comp » | `updates=tableSearch` | 419, même question | idem | — |
| second compte (onglet 2 : déconnexion puis connexion à seconde@exemple.test) | clients | `choisir` | 419 | `LivewireReleaseTokenMismatchException` | L4Zc8bcc / 87p3Pu2U, différents, user 2 ; Sec-Fetch-Site=same-origin, donc le contrôle CSRF est passé |
| second compte | produits | `basculer` | 419 | idem | PZ4q696W / WBC4MMMp, user 2 |
| session effacée (`delete from sessions`) | clients | `choisir` | 419 | idem | yalA8Ys7 / wTqms60q, user NULL |
| session effacée | produits | `basculer` | 419 | idem | pczCSByW / ENDZ2Atg, user NULL |

Avec le défaut, c'est donc toujours le même 419 Livewire, quel que soit le scénario.

### B. Avec la correction (`->discoverLivewireComponents(...)` dans AppPanelProvider), sur 127.0.0.1

| Scénario | Clients | Produits |
|---|---|---|
| un seul onglet | `choisir` 200, `__dispatch client-choisi` 200, `getFormSelectOptions` 200 ; `data.client_id = 1` dans le devis, fenêtre fermée | `basculer` 200, `ajouterLaSelection` 200, `__dispatch produits-choisis` 200 ; ligne {produit 1, quantité 1, PU TTC 12.00} dans le devis |
| second compte | 404 « Not Found » dans une fenêtre, sans question (`NotFoundHttpException` levée par `Filament\Http\Middleware\IdentifyTenant` ligne 35 : le compte 2 n'a pas accès à ce magasin) | 404, idem |
| session effacée | 302, puis page de connexion | 302, puis page de connexion |

### C. Avec la correction, sur une adresse non sûre (http://piliqi-diag.test:8765, que Chromium résout vers 127.0.0.1 : le navigateur n'envoie pas `Sec-Fetch-Site`)

- Un seul onglet : clients et produits fonctionnent (200 et client ou ligne posés).
- Second compte et session effacée : 419, mais cette fois `HttpException` avec pour précédente `Illuminate\Session\TokenMismatchException`, un vrai refus CSRF (par exemple session CXS5ECgf contre jeton envoyé f0Ob1A16).

### D. Défaut présent, sur l'adresse non sûre

- Un seul onglet : 419 `LivewireReleaseTokenMismatchException`, avec des jetons **égaux** (H6FAFDTM / H6FAFDTM). Même là où le jeton CSRF est réellement contrôlé, le 419 au clic vient du défaut et non de la session.

### E. Tests HTTP (PHPUnit, sans `Livewire::test`)

- `tests/Feature/Filament/FenetresDesSelecteursTest.php`, laissé dans la copie et reproduit ci-dessous. Sans la correction : 3 tests en échec (419 à la ligne 54 et à la ligne 68, `ComponentNotFoundException` dans le test du registre), code de sortie 2. Avec la correction : 3 réussis, 103 assertions, code de sortie 0. En test, `PreventRequestForgery` ne contrôle aucun jeton (`runningUnitTests()`, ligne 99) : un 419 n'y peut donc pas venir du CSRF. C'est une seconde preuve que H1 est la cause.
- Mon test complémentaire couvre les trois pages qui montent les fenêtres : création de devis, création de facture et caisse, avec un responsable, pour les deux fenêtres. Sans la correction, les 6 tests échouent avec un 419. Avec la correction, les 6 réussissent (27 assertions, code de sortie 0).
- `SelecteurDeClientsTest` et `SelecteurDeProduitsTest` réussissent avec la correction : 25 tests, 390 assertions, code de sortie 0.
- La suite complète sur SQLite avec la correction : voir la section « Suite complète » plus bas.

### F. Tinker dans la copie, sans la correction

- `getName(SelecteurDeClients::class)` = `app.filament.app.livewire.selecteur-de-clients`.
- `getClass()` de ce nom lève `ComponentNotFoundException`. Même résultat pour les produits.
- `create-devis` se résout bien en `CreateDevis`, que Filament enregistre.
- `livewire.class_namespace` = `App\Livewire`.

## Lignes de code citées par le diagnostic : toutes vérifiées

- `resources/views/filament/app/livewire/fenetre-du-selecteur-de-clients.blade.php` ligne 1 et `fenetre-du-selecteur-de-produits.blade.php` ligne 2 : `@livewire(\App\Filament\App\Livewire\…::class, …)`.
- `app/Providers/Filament/AppPanelProvider.php` lignes 119 à 127 (53ed088) : seulement `discoverResources`, `discoverPages`, `pages` et `discoverWidgets`. Aucun `discoverLivewireComponents`, `livewireComponents` ni `Livewire::component` n'existe dans le dépôt, et il n'y a pas de `config/livewire.php`. C'est vrai aussi dans l'arbre de travail actuel du lot A7b, lu sans rien modifier.
- `vendor/livewire/livewire/src/Mechanisms/ComponentRegistry.php` : `generateClassFromName` commence à la ligne 165, et la `ComponentNotFoundException` est levée à la ligne 116.
- `vendor/livewire/livewire/src/Features/SupportReleaseTokens/ReleaseToken.php` lignes 17 à 21 : l'exception est attrapée et remplacée par `LivewireReleaseTokenMismatchException` (HttpException 419). `SupportReleaseTokens.php` ligne 19 montre que cette vérification se fait à `checksum.verify`, avant les intergiciels persistants (`snapshot-verified`). C'est pourquoi, avec le défaut, H2 et H3 donnent aussi ce 419.
- `vendor/livewire/livewire/dist/livewire.esm.js` lignes 8857 à 8860 : sur un 419, `handlePageExpiry()` affiche le `confirm` en anglais (ligne 8881), puis `showFailureModal`.
- `vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php` lignes 101 et 143 à 148 : avec `Sec-Fetch-Site: same-origin`, la requête est acceptée sans comparer le jeton. `VerifyCsrfToken` n'est qu'une sous-classe dépréciée de ce middleware.
- `vendor/livewire/livewire/src/Features/SupportTesting/Testable.php` ligne 80 : `Livewire::test()` enregistre lui-même le composant. Le test `test_la_loupe_sert_aussi_aux_factures_et_aux_devis` appelle `recevoirLeClientChoisi` directement sur la page.
- Historique : les sélecteurs datent de 59dea41 (lot S8-L, 18/10/2026), et Livewire 3.8.10 est installé depuis 2dad268 (02/10/2026). Aucun commit n'a jamais enregistré ces composants.
- Filament enregistre les composants de tous les panneaux dans le registre global de Livewire, pas seulement ceux du panneau courant : `Panel::register()` puis `registerLivewireComponents()`, qui appelle `Livewire::component($nom, $classe)`. La correction vaut donc aussi pour les requêtes `/livewire/update`, qui ne passent pas par une route du panneau.

## Objections et compléments au diagnostic

1. **Une partie était déduite, elle est maintenant prouvée.** Le premier agent n'avait rejoué que « Créer un devis ». Les tests HTTP prouvent maintenant que la caisse et la création de facture sont touchées de la même façon, pour les deux fenêtres.
2. **Le point 3 de la correction proposée n'est pas nécessaire pour ce défaut, et une partie peut nuire.** `LivewireReleaseTokenMismatchException` ne garde pas la `ComponentNotFoundException` comme exception précédente. Un rendu serveur qui transforme tout 419 Livewire en rechargement de la page, avec l'avis « Votre session a expiré, ou un autre compte s'est connecté », ne distinguerait donc pas un composant non enregistré d'une vraie expiration. Il masquerait le prochain défaut de ce type sous un message faux, et chaque clic rechargerait la page en perdant la saisie. Le garde-fou de ce défaut est le test du registre, qui suffit. Pour une vraie expiration, je recommande plutôt :
   - remplacer la question anglaise par du français, par un hook Livewire côté navigateur qui garde le même comportement (proposer de recharger) ;
   - ajouter une vue `errors/419` en français ;
   - inscrire au journal, au niveau warning, chaque 419 d'une requête `/livewire/update`, sans jeton ni identifiant. Laravel ne signale jamais une HttpException (`internalDontReport`), et c'est pour cela que ce défaut ne laissait aucune trace dans `laravel.log`.
3. **Une limite qui reste après la correction.** Plusieurs comptes ouverts dans les onglets d'un même profil de navigateur partagent un seul cookie de session. La dernière connexion vaut donc pour tous les onglets. Un onglet resté sur un magasin du premier compte reçoit alors une fenêtre « 404 Not Found » (`IdentifyTenant`), ou une redirection vers la connexion. C'est le comportement normal et sûr. On peut seulement l'habiller en français, par le même hook côté navigateur (404 sur `/livewire/update` : recharger la page).
4. **Cela ne concerne pas les e-mails.** La demande de l'utilisateur relayée par ce flux porte sur les e-mails qui ne partent pas (facture, ticket, devis). Ce diagnostic ne l'explique pas. Il n'existe que deux composants imbriqués dans tout le dépôt, et le test du registre, qui passe en revue tous les composants Livewire de `app/`, réussit avec la correction : l'action d'envoi par e-mail n'est pas touchée par ce défaut. Une autre copie, `diag-courriel`, avec un serveur sur le port 8766, semble traiter ce sujet. Je n'y ai pas touché.

## Correction finale (à faire dans /home/user/piliqiediteur par la chaîne d'agents)

1. Dans `app/Providers/Filament/AppPanelProvider.php`, juste après `->discoverWidgets(...)`, ajouter :

```php
            // Les composants que montent les fenêtres des pages (les tableaux
            // des clients et des produits, voir SelecteurDeClients et
            // SelecteurDeProduits) : Livewire doit les retrouver par leur nom
            // à chacune de leurs requêtes, sinon chaque clic dans la fenêtre
            // répond 419 « Page Expired ».
            ->discoverLivewireComponents(in: app_path('Filament/App/Livewire'), for: 'App\\Filament\\App\\Livewire')
```

   La variante explicite, `->livewireComponents([SelecteurDeClients::class, SelecteurDeProduits::class])`, marche aussi. La découverte couvre en plus les futurs composants du dossier. Aucun `filament:cache-components` ni `optimize` n'est utilisé dans le dépôt. S'il l'était un jour, il faudrait le relancer après ce changement.

2. Ajouter le test `tests/Feature/Filament/FenetresDesSelecteursTest.php` suivant. Il échoue sans la correction et réussit avec, preuves ci-dessus :

```php
<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Livewire\SelecteurDeProduits;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\Mechanisms\ComponentRegistry;
use ReflectionClass;
use Tests\TestCase;

/**
 * Les fenêtres « Chercher un client » et « Ajouter un produit » suivies
 * par de vraies requêtes Livewire, comme le navigateur les envoie : la
 * page, l'ouverture de la fenêtre, puis les requêtes du composant imbriqué
 * qu'elle monte. Aucun Livewire::test() : il enregistrerait lui-même le
 * composant et masquerait un composant que Livewire ne retrouve pas par
 * son nom, qui répond 419 « Page Expired » à chaque clic.
 */
class FenetresDesSelecteursTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_composant_livewire_de_l_application_se_retrouve_par_son_nom(): void
    {
        $registre = app(ComponentRegistry::class);

        foreach (File::allFiles(app_path()) as $fichier) {
            $classe = 'App\\'.str_replace('/', '\\', substr($fichier->getRelativePathname(), 0, -4));

            if (! class_exists($classe) || ! is_subclass_of($classe, Component::class) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $this->assertSame($classe, ltrim($registre->getClass($registre->getName($classe)), '\\'), $classe);
        }
    }

    public function test_un_clic_dans_la_fenetre_des_clients_envoie_le_client_choisi(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        $comptoir = Client::withoutGlobalScopes()->where('magasin_id', $magasin->getKey())->where('est_comptoir', true)->sole();

        $page = $this->get('/app/'.$magasin->uuid.'/devis/create')->assertOk();
        $fenetre = $this->requeteLivewire($this->instantaneLivewire($page->getContent(), 'create-devis'), [], 'mountFormComponentAction', ['data.client_id', 'chercherUnClient'])->assertOk();
        $clic = $this->requeteLivewire($this->instantaneLivewire((string) $fenetre->json('components.0.effects.html'), 'selecteur-de-clients'), [], 'choisir', [(string) $comptoir->getKey()]);

        $clic->assertOk();
        $this->assertSame(SelecteurDeClients::EVENEMENT, $clic->json('components.0.effects.dispatches.0.name'));
        $this->assertSame($comptoir->getKey(), $clic->json('components.0.effects.dispatches.0.params.clientId'));
    }

    public function test_un_clic_dans_la_fenetre_des_produits_puis_ajouter_envoie_la_selection(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['nom' => 'Bougie parfumée']);

        $page = $this->get('/app/'.$magasin->uuid.'/devis/create')->assertOk();
        $fenetre = $this->requeteLivewire($this->instantaneLivewire($page->getContent(), 'create-devis'), [], 'mountFormComponentAction', ['data.ajouterUnProduitAction', 'ajouterUnProduit'])->assertOk();
        $clic = $this->requeteLivewire($this->instantaneLivewire((string) $fenetre->json('components.0.effects.html'), 'selecteur-de-produits'), [], 'basculer', [(string) $produit->getKey()])->assertOk();
        $ajout = $this->requeteLivewire((string) $clic->json('components.0.snapshot'), [], 'ajouterLaSelection');

        $ajout->assertOk();
        $this->assertSame(SelecteurDeProduits::EVENEMENT, $ajout->json('components.0.effects.dispatches.0.name'));
        $this->assertSame([['produit_id' => $produit->getKey(), 'quantite' => 1]], $ajout->json('components.0.effects.dispatches.0.params.selection'));
    }
}
```

   Il utilise les aides existantes de `tests/TestCase.php` : `ouvrirUneSociete`, `responsableDeTest`, `instantaneLivewire` et `requeteLivewire`. Le test du registre est le garde-fou général : il échoue pour tout futur composant monté par sa classe hors de `App\Livewire` et non enregistré.

3. Recommandé, mais hors de ce correctif, voir l'objection 2 :
   - la question d'expiration de Livewire en français (hook `Livewire.hook('request', ({ fail }) => fail(({ status, preventDefault }) => …))` posé par un render hook du panneau) ;
   - une vue `resources/views/errors/419.blade.php` en français ;
   - une ligne de journal au niveau warning pour chaque 419 d'une requête Livewire.

   Pas de redirection serveur silencieuse sur tout 419.

## Consigne à l'éditeur

- **En attendant la correction :**
  - choisir le client avec la liste déroulante du champ « Client » ;
  - ajouter les produits avec la douchette ou le champ « Scanner ou taper une référence ».
- **Après la correction :** recharger les pages restées ouvertes.
- **Pour travailler avec plusieurs comptes en même temps** (propriétaire d'une société, propriétaire d'un cabinet, compte d'un magasin) : utiliser un profil de navigateur distinct ou une fenêtre privée par compte. Dans un même profil, tous les onglets partagent une seule session : se connecter à un autre compte dans un onglet déconnecte le précédent dans tous les autres. Sur un onglet resté ouvert, l'éditeur verra alors, même après la correction, une fenêtre « 404 » ou un retour à la connexion.

## Suite complète

J'ai lancé `php artisan test --parallel` sur SQLite, dans la copie, avec seulement la correction et `FenetresDesSelecteursTest`. Le journal de diagnostic et mon test complémentaire étaient retirés. Le `.env` de la copie était remis dans les conditions de test du dépôt : clés de caisse et du journal d'audit vides, `VIES_URL` commenté, et la clé publique épinglée ajoutée par le premier agent mise de côté.

Résultat : 5015 tests, 4635 réussis, 380 ignorés, 614 494 assertions, 0 échec, **code de sortie 0**. Le premier agent avait obtenu 21 échecs sur sa suite complète, dus à son seul `.env` (`VIES_URL` pointé sur 127.0.0.1:9 et clés renseignées), pas à la correction. Je n'ai pas lancé la suite sur PostgreSQL : elle n'était pas indispensable au diagnostic, et la chaîne d'agents doit la lancer avant le commit (protocole, point 3).

## Nettoyage

- Serveur `php artisan serve` du port 8765 et son `php -S` enfant arrêtés (kill), aucun processus restant sur ce port. Le serveur du port 8766, qui appartient à la copie `diag-courriel` d'un autre agent, n'a pas été touché.
- `git -C /home/user/piliqiediteur worktree remove --force …/diag-419/wt` puis `git worktree prune` : codes de sortie 0. `git worktree list` ne montre plus la copie : il reste le dépôt, sur 53ed088, et la copie `diag-courriel` de l'autre agent.
- Le dossier `…/scratchpad/diag-419` est supprimé en entier (base SQLite, scripts, captures, journaux, sauvegarde du `.env`). Seul ce rapport reste.
- Aucune base PostgreSQL n'a été créée. Dans /home/user/piliqiediteur, je n'ai fait que des lectures, et aucun fichier n'y a changé de mon fait. Le vendor partagé n'a jamais été modifié : la copie l'utilisait par liens durs, sans écrire dedans, sauf `vendor/composer`, qui était copié.
