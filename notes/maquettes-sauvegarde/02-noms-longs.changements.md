# Maquette 02 : noms longs des graphiques en barres couchées

Fichier : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/02-noms-longs.html` (une seule page, CSS et JavaScript en ligne, aucune ressource d'un autre site, aucune police externe).
Captures : `.../maquettes/captures/02-*.png` (1 280 px et 390 px, clair et sombre, info-bulle ouverte, anneaux, légende longue).
Vérification : `.../maquettes/verif/verif-02.js` (Playwright), essai du canevas : `.../maquettes/verif/essai-chartjs.js`.

## 1. Ce que la maquette montre

Quatre cartes du tableau de bord de Maison Ferrand, chacune avec le menu du type (« Barres couchées » ou « Anneau »), « Monter », « Descendre », la croix, la ligne grise de ce que la carte compte et « Voir les chiffres » (tableau avec les noms en entier, comme aujourd'hui) :

- Meilleurs produits, Meilleurs clients, Achats par fournisseur : barres couchées par défaut ;
- Chiffre d'affaires par magasin : anneau par défaut (c'est son défaut dans le logiciel), donc sa légende.

Au-dessus : le menu « Écran » (espace de la société, panneau d'un magasin, espace du cabinet, page de l'expert-comptable ; la teinte du panneau change : vert, indigo, bleu ciel, indigo), le menu « Magasin » (absent du panneau d'un magasin, qui ne laisse deviner aucun autre magasin, et sans la carte « Chiffre d'affaires par magasin », qui n'y existe pas) et « Ajouter un graphique » (retrouve une carte retirée par la croix). Menu « Affichage de la page » (clair, sombre, comme l'appareil) : un confort de la maquette, pas du logiciel.

Comportements :
- le nom est du texte à gauche des barres, aligné à gauche ; trop long, il finit par « … » (le début reste visible, jamais coupé à gauche) ;
- souris au-dessus d'un nom, d'une barre, d'un nom de légende ou d'une part d'anneau : info-bulle avec le nom complet et le chiffre, la ligne survolée est grisée ;
- doigt (pointeur tactile) : un appui sur un nom, une barre, une légende ou une part ouvre l'info-bulle ; un appui sur un autre nom la déplace ; un second appui sur le même nom, ou un appui ailleurs, la ferme ;
- dans « Tous mes magasins », le nom porte le magasin à la fin (« Bougie parfumée coco · Maison Ferrand Fort-de-France ») comme dans le logiciel (`SeriesDesGraphiques::classement()`) ; avec un seul magasin choisi, ou dans le panneau d'un magasin, il ne le porte plus ;
- le « … » suit la largeur de la fenêtre (vérifié à 1 280, 1 024, 768, 520 et 390 px) ;
- les graduations du bas n'écrivent qu'une valeur sur deux, sur trois… quand la place manque.

Données (fictives) : 10 produits au plus par carte, des noms courts et des noms très longs (jusqu'à 85 caractères), le même produit, client ou fournisseur dans deux magasins (deux fiches distinctes), un magasin fermé (« Maison Ferrand Marché (fermé) »). Avec Maison Ferrand Fort-de-France, à 1 280 px, « Savonnerie Artisanale du Vieux Port » (35 caractères) tient en entier et « Rhum arrangé vanille-gingembre 35 cl » (36 caractères) est coupé ; à 390 px la limite passe entre 18 et 20 caractères. Ces noms servent de base au semis demandé (voir § 5).

## 2. Ce que l'éditeur a décidé, et où c'est dans la maquette

Mots de l'éditeur (message 472, 20/11/2026 08:10 UTC) : « tu vois ici les nom produit ne s affiche pas tout. si faut commencer ecrire le nom par à gauche et si il y en a bcp de lettre alors il faut chacher le reste mais quand la sourris et au dessus alors il doit afficher en tooltip tout le texte et meme il faut prendre en compte pour les telephones et tabletes, dans ces cas la il faut affiche en cliquant sur le textet diretct le tooltip ».

| Décision | Dans la maquette |
|---|---|
| 1. Le nom s'écrit depuis la gauche, début visible, fin cachée par « … », jamais le début (aujourd'hui : « …nnelle 100 g · Maison Ferrand Fort-de-France ») | Colonne `.nom` : `text-align: left`, `overflow: hidden`, `text-overflow: ellipsis`, une seule ligne. Contrôlé à chaque largeur : aucun nom défilé, tous alignés à gauche. |
| 2. Souris au-dessus du nom : info-bulle avec le nom COMPLET | `#bulle` : le nom complet en titre. Survol d'un nom coupé et d'un nom entier, contrôlés. |
| 3. Téléphone et tablette : un APPUI sur le nom affiche directement l'info-bulle | Contexte tactile (390 px, `hasTouch`) : `tap()` sur un nom ouvre `#bulle`, sans autre geste. La détection se fait par le type du pointeur de chaque événement (souris, stylet, doigt), pas par la largeur de l'écran : une tablette avec souris garde le survol. |
| Portée : toutes les cartes à noms (Meilleurs produits, Meilleurs clients, Achats par fournisseur, Chiffre d'affaires par magasin, légendes d'anneaux) | Les quatre cartes ; barres couchées et légendes d'anneau (passer une carte en « Anneau »). |
| Dans les espaces société, magasin, cabinet et la page de l'expert-comptable | Menu « Écran » : les quatre, chacun contrôlé (cartes, menu Magasin, noms avec ou sans magasin, teinte). |
| Clair et sombre, contraste AA de l'info-bulle | Voir § 4 : tous les textes contrôlés dans les deux modes, info-bulle comprise. |
| Test au format ordinateur ET téléphone, nom très long et nom court | La vérification de la maquette (`verif-02.js`) fait exactement cela en 1 280 px (souris) et 390 px (appui), clair et sombre ; elle sert de modèle au futur test en navigateur. |
| Semis : un produit, un client, un fournisseur au nom très long, de part et d'autre de la limite de coupure | Les données de la maquette (§ 1) ; à reprendre au semis (§ 5). |

## 3. Le choix de réalisation que le cahier laissait à la maquette : réalisation B

Le cahier (ligne 330) demandait de choisir entre A (raccourcir le nom dans la graduation du canevas Chart.js et ouvrir l'info-bulle sur la rangée entière) et B (écrire les noms en HTML à côté du canevas). Essai fait avec le canevas du logiciel lui-même (le Chart.js 3.9.1 que Filament sert, `public/js/filament/widgets/components/chart.js`, `indexAxis: 'y'`, 5 noms dont deux très longs) :

- les noms s'écrivent alignés à droite, contre les barres, et les longs sont coupés à gauche : c'est exactement ce que l'éditeur a vu ; la colonne des noms prend jusqu'à la moitié du canevas (280 px sur 560) et écrase les barres ;
- souris sur la barre : 1 élément actif, info-bulle ouverte ; souris sur le nom de la graduation : 0 élément actif, info-bulle fermée. Chart.js ne détecte rien hors de sa zone de dessin.

Réalisation B retenue par Claude (l'éditeur a demandé qu'on décide sans l'attendre) :
- le « … » est posé par le navigateur à la bonne place, à toute largeur, sans mesure en JavaScript, sans redessin au changement de mode clair ou sombre ;
- survol et appui sont des événements ordinaires sur du texte ; le nom est lu par un lecteur d'écran et se copie (le canevas porte seulement `role="img"` et le titre de la carte) ;
- le contraste de l'info-bulle et des noms se règle comme le reste de la page, par variables de couleur ;
- la réalisation A demande en plus du code qui devine que le pointeur est sur une graduation du canevas, un nom raccourci par mesure du texte à chaque changement de largeur, et reste un dessin.

Ce que B coûte, à dire à l'éditeur : chaque ligne a une hauteur fixe (36 px dans la maquette) pour rester alignée sur sa barre, donc la zone d'une carte à 10 noms mesure 36 × 10 + 26 = 386 px, au lieu des 16 rem (256 px) d'aujourd'hui ; en échange les barres ne sont plus écrasées. Le test en navigateur doit contrôler l'alignement nom/barre (la vérification de la maquette le fait : 36 px par ligne, centres à moins de 1 px). Pour ces types seulement (barres couchées et anneau des cartes à noms), l'info-bulle de Chart.js laisse la place à celle de la page, la même pour la barre, le nom et la légende ; les autres types et les autres cartes ne changent pas.

Dans la maquette, les barres, leur axe et l'anneau sont dessinés par la page (SVG et HTML) : une page autonome ne peut pas charger Chart.js d'un autre site. Dans le logiciel, ce sera le canevas, sans ses noms de graduation (`scales.y.display: false`, marges nulles, épaisseur de barre fixe).

## 4. Vérifications faites (Playwright, Chromium)

1 280 px, 1 024, 768, 520, 390 px ; clair et sombre ; souris et tactile :
- aucune erreur ni avertissement de console, aucune requête hors de la page (zéro) ;
- aucun débordement horizontal de la page, aucun élément qui sorte de sa carte (la capture du premier essai montrait le menu du type des cartes qui sortait à gauche à 390 px ; corrigé, les commandes passent à la ligne, et le contrôle des éléments qui sortent de leur carte a été ajouté) ;
- tous les noms visibles : alignés à gauche, avec « … », sans défilement ;
- lignes des barres : 36 px, centre du nom et de la barre à moins de 1 px ;
- contraste de tous les textes visibles (4,5:1, ou 3:1 pour un grand texte), dans chacun des quatre écrans, avec l'info-bulle ouverte, avec « Voir les chiffres » ouvert et les quatre cartes en anneau : aucun texte sous le seuil ;
- éléments graphiques (barres, pastilles de légende, parts d'anneau) et bordures des menus et boutons : 3:1 au moins (minimum constaté 4,1:1 en clair, 5,46:1 en sombre) ;
- info-bulle : texte sur fond 16,97:1 en clair, 20,12:1 en sombre ; bord de l'info-bulle sur la carte en sombre 5,46:1 (le fond noir de l'info-bulle ne se distingue pas du fond sombre de la carte, le bord la dessine) ;
- souris : survol d'un nom coupé, d'un nom entier, d'une barre, d'un nom de légende (coupé ou non), d'une part d'anneau ; l'info-bulle reste dans l'écran et se ferme quand la souris part ;
- tactile : appui sur un nom, sur un autre nom (déplacement), second appui sur le même (fermeture), appui ailleurs (fermeture), appui sur une barre, sur une légende, sur une légende longue.

Non vérifié : un vrai téléphone ou une vraie tablette (iOS Safari en particulier) ; l'émulation tactile de Chromium est la seule preuve. Le comportement ne dépend d'aucune API réservée (événements de pointeur, `click`).

## 5. Pour le lot (non montré, à reprendre)

- Semis (règle 6) : au moins un produit, un client et un fournisseur au nom très long, plus un nom juste en deçà et un juste au-delà de la limite. Candidats de la maquette (Maison Ferrand) : produits « Coffret découverte de savons artisanaux au lait de coco et à la fleur de tiaré, 250 g » (85 caractères), « Huile de coco vierge pressée à froid, flacon verre 500 ml » (57), « Rhum arrangé vanille-gingembre 35 cl » (36) ; clients « Mairie de Schœlcher, service de la restauration scolaire et des affaires sociales » (81), « Cabinet dentaire du Docteur Marie-Claude Rousseau-Delmas » (56), « Hôtel Les Trois-Îlets Résidence & Spa » (37) ; fournisseurs « Maison de la Céramique d'Art de Vallauris, atelier Pierre Sauvaire » (66), « Grossiste en cire de soja biologique, Compagnie Laurent-Renaud SA » (66), « Savonnerie Artisanale du Vieux Port » (35). À semer dans le magasin qui compte le plus de ventes, pour que le nom paraisse dans les dix premiers.
- Test en navigateur : ordinateur et téléphone, nom très long et nom court, dans les deux modes (clair, sombre), sur les quatre écrans ; contrôles du § 4.
- Cartes concernées dans le code : `resources/views/filament/grille-des-graphiques.blade.php` (le canevas des cartes à noms), `resources/views/filament/graphiques-du-panneau.blade.php` (`indexAxis: 'y'`, graduations, info-bulle), `public/css/app/graphiques.css`.

## 6. Propositions de Claude (idées à moi, NON décidées, absentes de la maquette)

1. Le magasin caché. « Tous mes magasins » écrit le magasin à la fin du nom ; coupé par la droite, il disparaît : deux fiches du même nom de deux magasins se ressemblent (dans « Meilleurs produits », « Bougie parfumée figue · Maison Fer… » deux fois, lignes 4 et 5, à 390 px comme à 1 280 px). Proposition : écrire le magasin sur une seconde ligne, petit et gris, sous le nom, seulement quand la carte compte plusieurs magasins ; la ligne passe à 44 px environ, le nom seul est coupé par « … », l'info-bulle garde les deux. Cela touche la donnée (le nom et le magasin cessent d'être une seule chaîne dans `classement()`).
2. Le clavier. Une info-bulle qui ne s'ouvre qu'à la souris et au doigt n'existe pas pour qui navigue au clavier : proposition de rendre chaque ligne et chaque nom de légende atteignable au clavier, avec l'info-bulle ouverte à la prise de focus et fermée par Échap (critère WCAG 1.4.13). Non fait dans la maquette, parce que l'éditeur n'en a pas parlé.
3. Les mots « (fermé) » et « (cédé) », que `Perimetre::nomDuMagasin()` ajoute au nom d'un magasin dans « Chiffre d'affaires par magasin », sont à la fin du nom : un nom coupé les cache. L'info-bulle les montre ; si on veut qu'ils se voient toujours, il faut les séparer du nom (même idée que la 1).
4. Hors sujet, constaté en lisant le code : l'anneau n'a que six teintes (`TEINTES` de `graphiques-du-panneau.blade.php`), donc dix noms de légende répètent leur couleur (les parts 1 et 7, etc.) ; seule l'info-bulle dit de quelle part il s'agit. Et l'info-bulle d'une part d'anneau de Chart.js n'a pas de titre : la maquette y met le nom (lui aussi complet), ce que le logiciel ne fait pas aujourd'hui, à ma connaissance du code de Chart.js, non vérifié sur un anneau.

## 7. Questions ouvertes (reprises en bas de la page)

1. L'info-bulle d'un nom montre le nom complet ET le chiffre (comme celle de la barre aujourd'hui) : seulement le nom ?
2. Un nom court, dont rien n'est caché, ouvre aussi l'info-bulle, parce que la ligne entière la porte avec son chiffre : seulement sur un nom coupé ?
3. La fin du nom (le magasin, « (fermé) ») est cachée : voir la proposition 1.
4. Barres verticales (les noms sont alors sous les barres) et camembert, que les quatre cartes proposent aussi : mêmes règles ? Non montrés, la décision ne les nomme pas. Le camembert a la même légende que l'anneau ; je propose les mêmes règles, à confirmer.
5. Fermeture de l'info-bulle au doigt : j'ai pris l'usage courant (appui ailleurs, ou second appui sur le même nom) ; l'éditeur n'a rien dit à ce sujet.

## 8. Choix de présentation de Claude (nécessaires pour montrer la règle, pas des décisions de l'éditeur)

- Info-bulle sombre dans les deux modes, avec un bord (comme l'info-bulle par défaut de Chart.js) ; elle suit la souris ; au doigt, elle s'ouvre sous la ligne touchée (au-dessus s'il n'y a pas de place).
- Colonne des noms : 42 % de la carte, entre 7,5 rem et 15 rem ; légende d'anneau : chaque nom plafonné à 17 rem ou à la largeur de la carte, sous le dessin (Filament place la légende de Chart.js en bas).
- Ligne survolée ou touchée grisée, pour montrer à quelle ligne l'info-bulle se rapporte.
- La maquette ne montre ni les chiffres clés, ni la période, ni « Comparer avec l'année précédente », ni le menu « Classer par » de « Meilleurs produits », ni les quinze autres cartes : sans lien avec les noms.
