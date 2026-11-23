# GRAPH-AN : changements et questions de la maquette

Fichier de la maquette : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/01-graph-an.html`
Captures : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/captures/01-graph-an-*.png` (43 images : 1 280 px et 390 px, clair et sombre).

Sources relues : `a-faire-apres-chaine.md` (lignes 414 à 457), `etat-chaines.md` (506-508, 515, 661), `ARRET-DEMANDE-2026-11-17.md`, `messages-editeur.md` (messages 354 à 361), `DIRECTIVES.md` (ligne 812), `modeles/graphiques-tableau-de-bord.html`, `modeles/options-des-graphiques.html`, la maquette validée de la carte « Écart des prix d'achat », et, pour les noms et les règles de l'écran réel, `app/Domain/TableauDeBord` et `resources/views/filament/grille-des-graphiques.blade.php` (lus seulement).

## 1. Ce que montre la maquette

Une page autonome (un seul fichier, aucune ressource d'un autre site, aucune police ni bibliothèque extérieure : les graphiques sont dessinés en SVG par la page). Elle contient :

1. En haut, la liste de ce que l'éditeur a décidé, avec des boutons « Voir » qui règlent l'écran sur chaque cas et y emmènent.
2. Un « pupitre de la maquette » (cadre en pointillés, rien de cela n'est dans PILIQI) : site réel ou de démonstration ; qui regarde (propriétaire, panneau du magasin Halles, administrateur coché, cabinet lié, expert-comptable déclaré, vendeur) ; l'historique de la société (8 années de ventes, une année vide au milieu, première année) ; fin d'exercice en décembre ou en juin ; mode clair ou sombre.
3. L'écran de PILIQI : en-tête, quatre chiffres clés, barre des filtres, cartes. Tout fonctionne (menus, cases, clics sur les barres, clavier, info-bulles). Tous les chiffres sortent d'un même jeu de ventes fictives (Maison Ferrand, trois magasins : Halles depuis mars 2019, République depuis février 2022, Fort-de-France depuis avril 2024), calculé jour par jour, additionné dans la page ; « aujourd'hui » est le 22/11/2026.
4. En bas, « À trancher avec vous », avec le point « garder ou retirer Chiffre d'affaires HT par an » jouable.

## 2. Ce que l'éditeur a décidé, et où c'est dans la maquette

| Décision | Source | Où le voir |
|---|---|---|
| Barre des filtres : le libellé de chaque filtre à gauche de son menu, une ligne compacte | message 358, point 0 | Bouton « Voir » n° 1. Panneau du magasin : une seule ligne à 1 280 px. Espace de la société : voir la question 4 |
| Ligne sous les chiffres clés colorée : variation sur un an verte si elle monte, rouge si elle baisse | message 358, point 0 | n° 2. CA +5,2 % (vert), Achats −5,3 % (rouge), calculés depuis le jeu de ventes |
| Cartes des chiffres plus compactes (libellé petit, chiffre, ligne) | point 0 | n° 2 (deux colonnes sur téléphone) |
| Menu « Magasin » absent du panneau d'un magasin | message 359 | « Qui regarde : Panneau du magasin Halles » |
| Menu « Découper par » : mois (défaut), trimestre, année | messages 354, 359 | n° 3 ; menu dans la barre. Le titre de la carte suit (« par trimestre », « par année ») |
| Par année : 10 dernières années ; années vides du début cachées ; année vide du milieu à 0 ; exercice décalé = une barre par exercice | message 354 | n° 4 : « Depuis 2019 » (8 barres, 2 années cachées, phrase grise), « Année vide au milieu » (2022 à 0, 2017 caché), « Première année », « Exercice qui finit en juin » (barres « 2018-19 » à « 2026-27 ») |
| Par trimestre : T1 à T4, ou trimestres de l'exercice | message 359 | n° 5 : « Année 2026 » (T1 à T4), « Exercice qui finit en juin » (T1 = juil.–sept.) |
| Case « Cumul depuis le début de l'année » (ou de l'exercice) ; avec « Comparer », l'année précédente cumulée en pointillés | message 359 | n° 6 (le libellé de la case devient « … de l'exercice » quand l'exercice finit en juin) |
| « Écart en % sur un an », dans le menu de la carte : une barre par période, verte au-dessus de 0, rouge en dessous, « – » quand l'année précédente vaut 0 | message 359 | n° 7 : menu « Afficher » de chaque carte par période ; « Par année » montre 2022 à −100 %, puis 2023 et la première année à « – » |
| Nouveau graphique « Ventes par vendeur », barres couchées, CA HT net, nom figé ; seulement les magasins où la personne voit toutes les ventes de l'équipe, phrase grise pour les autres, jamais pour un vendeur ni un observateur | a-faire:439-457 | n° 8 : propriétaire (3 magasins), administrateur (phrase « 1 magasin n'est pas compté : vous n'y voyez pas toutes les ventes. »), vendeur (aucun graphique). Sandrine Colin, partie en octobre 2025, apparaît tant que la période contient ses ventes : c'est le nom figé |
| « Cliquer pour voir le détail » : année → mois, trimestre → mois, mois → jours ; clic sur un jour → liste filtrée des documents ; « Revenir » | a-faire:439-457 | n° 9 : fil d'Ariane « Par année › 2025 › mars 2025 », « Revenir », clavier (Entrée), fenêtre de la liste filtrée (par magasin dans l'espace de la société) |
| « Tendance lissée (moyenne sur 3 mois) » : « Ajouter la tendance » dans le menu de la carte ; chaque point = moyenne de la période et des deux précédentes ; sans point pour les deux premières ; légende « Moyenne sur 3 mois » (trimestres, ans) | message 360 | n° 10 |
| Cartes ouvertes au départ : réel = Ventes et achats, TVA à payer, CA HT par mois, CA par magasin (plusieurs magasins), plus Factures à encaisser par ancienneté ; démonstration = toutes les cartes que le compte voit ; une préférence enregistrée prime | messages 359, 361 | n° 11 : site réel 5 cartes (4 dans le panneau d'un magasin), démonstration 20 cartes (menu « Ajouter » grisé : tout est affiché) |
| « Comparer avec l'année précédente » grisée par année avec phrase grise (proposition initiale de Claude, 6.4) | a-faire:420 | n° 12 |
| Garder ou retirer « Chiffre d'affaires HT par an » (à trancher dans la maquette) | a-faire:420 | n° 13 et fin de page |

Non retenus, donc absents : semaine, jour, « Du … au … », magasins côte à côte, par heure, objectif.

## 3. Vérifications faites (Playwright, Chromium)

- 92 vues contrôlées (18 réglages × 1 280 px et 390 px × clair et sombre, plus quatre autres personnes en démonstration et la fenêtre de la liste) : aucune erreur de console, aucune requête vers un autre site (la page ne contient aucune adresse `http`), aucun débordement horizontal de la page, des cartes ni des graphiques, aucun texte sous le contraste AA (4,5:1, 3:1 pour les grands textes), aucune marque de graphique sous 3:1 sur le fond de la carte. Le contrôle des contrastes a été éprouvé en dégradant volontairement des couleurs (il les trouve).
- 41 contrôles de comportement : cartes de départ réel et démonstration, panneau du magasin sur une ligne, libellé à gauche, couleurs des variations, trimestre, année (cases grisées, phrase), détail jusqu'à la liste, Revenir, fil d'Ariane, clavier, tendance, écart, cumul + comparer en pointillés, Monter, Retirer, Ajouter, vendeur, administrateur, exercice en juin, garder/retirer, info-bulle.
- Palette de séries : celle de la compétence « dataviz » (bleu, orange, vert d'eau, rose, violet, validée contre le daltonisme ; en mode clair, le vert d'eau et le rose sont un peu assombris pour passer 3:1 sur la carte), pas celle de la maquette G1, dont le vert et le rose se confondent pour un daltonien (le validateur donne ΔE 4,4, seuil 8). Le rouge et le vert de l'écart en % sont doublés par la position (au-dessus ou en dessous de 0) et par le signe dans l'info-bulle et le tableau.
- Mobile (390 px) : les menus de la barre passent à la ligne par paires (libellé et menu toujours ensemble), les tableaux défilent de côté dans leur cadre.

## 4. Propositions de Claude (mes idées, pas des décisions)

1. **Retirer « Chiffre d'affaires HT par an »** (question 1) : « Découper par : année » le remplace, avec dix ans au lieu de cinq et, en plus, trimestre, cumul, écart, tendance et détail.
2. Le titre de la carte suit le découpage (« Ventes et achats HT par trimestre », « par année », « par exercice »).
3. Le cumul se dessine en courbe (une courbe qui monte), même si le type choisi est « Barres » ; l'écart en % est toujours en barres (vertes au-dessus de 0, rouges en dessous).
4. Dans « Ventes par vendeur », le nom affiché est le nom figé sur la vente ; je n'ai pas ajouté « (compte retiré) ».
5. Une phrase grise sur chaque carte par année qui cache des années vides (« 2 années plus anciennes sans donnée ne sont pas affichées. »).
6. L'info-bulle d'une barre et le tableau « Voir les chiffres » disent la même chose au centime ; en mode « écart », ils montrent aussi les deux montants comparés.
7. « Nombre de ventes et panier moyen » garde son axe de droite d'aujourd'hui (la compétence « dataviz » déconseille deux axes ; je n'y ai pas touché, ce n'est pas l'objet de GRAPH-AN).

## 5. Questions ouvertes (ce que les décisions ne disent pas, et ce que la maquette a choisi pour pouvoir dessiner)

1. **Garder ou retirer « Chiffre d'affaires HT par an » ?** La page en bas montre les deux : le graphique actuel (5 années) et « Découper par année » (10 années). Boutons « Je le garde » / « Je le retire » : l'écran suit. Mon conseil : le retirer (proposition 1).
2. **Que devient « Période » quand on découpe par année ?** Choix de la maquette : « Par année » = les 10 dernières années pour tout l'écran ; le menu « Période » se grise (« 10 dernières années ») et les cartes en anneau et en classement comptent alors les 10 ans. Autre possibilité : « Par année » ne change que les graphiques par période, et les autres gardent la période choisie.
3. **Quels trimestres sur « 12 derniers mois » ?** Choix : quatre blocs de trois mois, le dernier finissant ce mois-ci (« déc.–févr. », « mars–mai », « juin–août », « sept.–nov. »). Sur une année ou un exercice : T1 à T4. Autre possibilité : griser « trimestre » sur « 12 derniers mois ».
4. **La barre des filtres sur une ligne.** Avec « Découper par » et « Cumul » en plus, les six commandes ne tiennent pas sur une ligne dans l'espace de la société à 1 280 px : cinq tiennent sur la première, « Ajouter un graphique » passe seul à la seconde, à droite. Dans le panneau d'un magasin (pas de menu « Magasin »), tout tient sur une ligne. L'écran réel a en plus le menu latéral de Filament (environ 250 px de moins) : il faudra accepter deux lignes, ou replier une commande. Je n'ai pas raccourci les libellés que vous avez dits.
5. **Le cumul.** (a) Sur « 12 derniers mois », il part du début de l'année (de l'exercice) en cours, comme dans votre exemple de janvier à novembre ; la carte montre donc l'année en cours, pas les 12 mois. (b) Par année, il n'y a rien à cumuler : la case se grise avec une phrase. (c) Sur « Valeur du stock », le cumul ne s'applique pas : phrase grise. (d) Avec le cumul, « Ajouter la tendance » se grise.
6. **Cartes à plusieurs séries.** « Comparer », « Écart en % » et la tendance portent sur la série principale (Ventes HT, À payer pour la TVA, nombre de ventes), pas sur chacune ; le cumul porte sur toutes.
7. **Les nouveaux choix entre eux.** « Écart en % » : ni cumul, ni comparaison, ni tendance sur cette carte. Dans le détail (mois, jours) : les montants seulement, sans comparaison, écart ni tendance (phrase grise). Dites si vous voulez autre chose.
8. **Le mois en cours** (22 jours sur 30) : l'écart en % et la comparaison le mettent en face des mêmes jours de l'an dernier, pas du mois entier ; les chiffres clés font de même. Sinon le mois en cours serait toujours en baisse.
9. **Comparer par trimestre** : même trimestre un an plus tôt. La phrase grise de « Comparer » par année dit « mois par mois ou trimestre par trimestre » (votre phrase initiale ne parlait que de mois).
10. **Couleurs sous les chiffres clés.** Je suis la maquette G1 : « collectée − déductible » et « dont … en retard » sont verts (comme elle). Un retard en vert est discutable : dites si « dont … en retard » doit être rouge. Pour les achats, une baisse est rouge, comme la règle générale « monte = vert, baisse = rouge », même si moins d'achats n'est pas forcément mauvais.
11. **Menu de la carte.** Pour « Écart en % sur un an » : menu « Afficher » (Les montants / Écart en % sur un an) ; pour la tendance : case « Ajouter la tendance (moyenne sur 3 mois) ». Vous avez dit « dans le menu de la carte » sans dire la forme.
12. **Liste filtrée d'un jour.** Dans l'espace de la société avec « Tous mes magasins », la fenêtre montre un tableau par magasin (la liste réelle est celle d'un magasin). « Valeur du stock » n'ouvre aucune liste.
13. **Les cartes qui ne sont pas par période** (anneaux, classements, jours de la semaine…) ne changent pas avec « Découper par » ; avec « par année » elles comptent 10 ans (question 2). La carte « Écart des prix d'achat », déjà validée le 21/11, est reprise ici en abrégé (barres hausses et baisses, losange de l'écart, tableau) ; par année, elle montre les 12 derniers mois avec une phrase grise.
14. **Données de la maquette** : Halles a une année fermée en 2022 dans l'historique « année vide au milieu » ; le semis réel devra, pour GRAPH-AN, couvrir au moins 3 ans dans une société et une année vide au milieu dans une autre (point 7 de la proposition du 16/11).
