# Tests en navigateur de toutes les actions (directive de l'éditeur du 01/11/2026, vers 22 h 35 UTC)

## 1. Les messages de l'éditeur, mot pour mot

- « il faut faire tous les testes en navigateur et pas que le code car tu vois dans navigateur peut avoir d'autre erreurs. donc il faut que tu fasse tous les testes aussi dans les navigateur »
- (précision, après une interruption) « je parle de tous les actions qui se font sur navigateurs, à chaque clique il y a un comportement donc qqch qui doit se finir comme prevu »

Contexte : l'erreur 419 des fenêtres de recherche de clients et de produits (constat du 01/11/2026, 22 h 57), invisible des tests du code, parce que `Livewire::test()` enregistre lui-même le composant, alors que le navigateur suit un autre chemin (diagnostic : `scratchpad/diag-419-rapport.md`, atelier wf_876004e5-713).

## 2. Contraintes constatées sur la machine de développement (01/11/2026)

- Chromium « Google Chrome for Testing » 153 (`/opt/pw-browsers/chromium`, Playwright préinstallé, `require('playwright')` utilisable de partout) ; chromedriver 147 seulement (`/opt/node22/bin/chromedriver`), qui ne pilote pas ce Chromium 153 ; téléchargement d'un autre chromedriver bloqué par le proxy (storage.googleapis.com : 403). Laravel Dusk ne peut donc pas tourner ici, seulement dans la CI.
- `CLAUDE.md`, puce « Outillage » : « aucun paquet inutilisé ; aucun outil Node (Vite, Tailwind) tant qu'aucun thème d'écran personnalisé n'existe » ; et « Do not change the application's dependencies without approval ».

## 3. Questions posées à l'éditeur (01/11/2026, vers 22 h 45, dans le texte du message)

1. Outil : (a) Pest 4 et son extension « browser » (tests écrits en PHP, avec les mêmes fabriques et les mêmes aides que les 5 000 tests actuels, dans un vrai Chromium piloté par Playwright ; erreurs JavaScript, requêtes en échec et 419 de Livewire détectés), paquets de développement pestphp/pest, pestphp/pest-plugin-laravel, pestphp/pest-plugin-browser et le paquet npm playwright, jamais livrés en production, et la puce « Outillage » de `CLAUDE.md` complétée (« sauf Playwright, pour les tests en navigateur ») (recommandé) ; (b) Laravel Dusk (PHP, chromedriver), qui ne tourne pas sur la machine de développement et ne serait vérifié que par la CI ; (c) Playwright en JavaScript, tests écrits à part, sans les fabriques PHP.
2. Portée : chaque action de chaque écran (bouton, lien, fenêtre, formulaire), pour chaque rôle qui la voit, avec un test en navigateur qui vérifie qu'elle se termine comme prévu ; un balayage qui ouvre chaque page et chaque action de chaque panneau pour chaque rôle et échoue à la moindre erreur ; chaque lot suivant ajoute les tests en navigateur de ce qu'il touche, et ses relecteurs cliquent aussi dans un vrai navigateur.
3. Ordre : d'abord la correction de l'erreur 419 (lot SL, juste après A7b), puis ce lot des tests en navigateur (NAV), puis ML, EC2, S9-L, S9-A, 21.

## 4. Réponses de l'éditeur

(en attente)
