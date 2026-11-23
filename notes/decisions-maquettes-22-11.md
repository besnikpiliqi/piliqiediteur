# Décisions sur les trois maquettes (22/11/2026, après le message de l'éditeur : « tu tranches toi-même avec tes conseils »)

L'éditeur a laissé à Claude le choix de chaque point ouvert des maquettes publiées le 22/11. Ces décisions sont celles de Claude (D76) : elles s'appliquent sans attendre, et une réponse de l'éditeur postérieure prime. Chaque point reprend le numéro de la question ouverte de la maquette (fichiers `maquettes/0X-….changements.md`, section « Questions ouvertes »).

## 1. GRAPH-AN (maquette `01-graph-an.html`)

Le lot fait exactement ce que décrit `a-faire-apres-chaine.md` (lignes 414 à 458) et la maquette, avec ces réponses :

1. **« Chiffre d'affaires HT par an » : retiré.** « Découper par : année » le remplace (dix ans au lieu de cinq, avec trimestre, cumul, écart, tendance et détail). Les préférences déjà enregistrées qui le nomment sont ignorées sans erreur (une valeur inconnue reprend le défaut quand elle se lit en base). Le dix-neuf graphiques deviennent dix-huit plus « Ventes par vendeur » : le nombre de graphiques s'écrit une seule fois dans le code et se relit partout (CLAUDE.md, README, tests).
2. **« Période » quand on découpe par année** : choix de la maquette. « Par année » = les 10 dernières années pour tout l'écran ; le menu « Période » se grise (« 10 dernières années ») ; les anneaux et les classements comptent alors ces 10 ans.
3. **Trimestres sur « 12 derniers mois »** : quatre blocs de trois mois, le dernier finissant ce mois-ci. Sur une année ou un exercice : T1 à T4.
4. **Barre des filtres** : une ligne quand elle tient (panneau d'un magasin) ; quand elle ne tient pas (espace de la société, avec le menu latéral), « Ajouter un graphique » passe seul à la seconde ligne, à droite. Aucun libellé raccourci.
5. **Cumul** : sur « 12 derniers mois », il part du début de l'année (de l'exercice) en cours ; par année, la case se grise avec une phrase grise ; sur « Valeur du stock », phrase grise ; avec le cumul, « Ajouter la tendance » se grise.
6. **Cartes à plusieurs séries** : « Comparer », « Écart en % » et la tendance portent sur la série principale ; le cumul porte sur toutes les séries.
7. **Les nouveaux choix entre eux** : sur « Écart en % », ni cumul, ni comparaison, ni tendance ; dans le détail (mois, jours), les montants seuls (phrase grise).
8. **Mois en cours** : l'écart en % et la comparaison le mettent en face des mêmes jours de l'an dernier (comme les chiffres clés).
9. **Comparer par trimestre** : même trimestre un an plus tôt ; la phrase grise de « Comparer » par année dit « mois par mois ou trimestre par trimestre ».
10. **Couleurs sous les chiffres clés** : « dont … en retard » est **rouge** (un retard en vert est faux) ; « collectée − déductible » reste neutre ; une baisse des achats est rouge comme la règle générale (« monte = vert, baisse = rouge »), sauf que la phrase ne juge pas : la couleur suit le sens, pas le mérite. Contraste AA dans les deux modes.
11. **Menu de la carte** : « Afficher » (Les montants / Écart en % sur un an) et la case « Ajouter la tendance (moyenne sur 3 mois) ».
12. **Liste filtrée d'un jour** : dans l'espace de la société avec « Tous mes magasins », un tableau par magasin ; « Valeur du stock » n'ouvre aucune liste.
13. **Cartes qui ne sont pas par période** : elles ne changent pas avec « Découper par » ; la carte « Écart des prix d'achat », par année, montre les 12 derniers mois avec une phrase grise.
14. **Semis** : au moins une société avec trois ans de données, une autre avec une année vide au milieu, par les vraies actions.
15. **Cartes ouvertes au départ** (décidé le 16/11) : site de démonstration, toutes les cartes permises au compte ; site réel, les cartes principales plus « Factures à encaisser par ancienneté ».

## 2. Noms longs (maquette `02-noms-longs.html`, réalisation B)

Le lot écrans a déjà fait la base (début visible, « … », info-bulle au survol et à l'appui). Le lot « suite » ajoute, avec ces réponses :

1. **Info-bulle d'un nom** : le nom complet ET le chiffre, comme aujourd'hui (une seule info-bulle par ligne).
2. **Nom court** : l'info-bulle s'ouvre aussi (la ligne entière la porte avec son chiffre) : un seul comportement, plus simple à lire et à tester.
3. **Fin du nom cachée (magasin, « (fermé) », « (cédé) »)** : proposition 1 de la maquette retenue : le magasin s'écrit sur une seconde ligne, petit et gris, sous le nom, seulement quand la carte compte plusieurs magasins ; « (fermé) » et « (cédé) » vont avec lui ; le nom seul est coupé par « … » ; l'info-bulle garde les deux. Le nom et le magasin cessent d'être une seule chaîne dans `classement()`.
4. **Barres verticales et camembert** : mêmes règles (légende comme l'anneau).
5. **Fermeture de l'info-bulle au doigt** : appui ailleurs, ou second appui sur le même nom (usage courant).
6. **Clavier** (proposition 2 de la maquette) : chaque ligne et chaque nom de légende est atteignable au clavier ; l'info-bulle s'ouvre à la prise de focus et se ferme par Échap (WCAG 1.4.13).
7. **Anneau** (proposition 4, hors sujet mais vraie) : l'info-bulle d'une part d'anneau porte le nom complet en titre ; les teintes de l'anneau ne se répètent plus avant la dixième part (palette étendue, contraste AA dans les deux modes).

## 3. Fermeture de caisse (maquette `03-fermeture-de-caisse.html`)

Le lot écrans a déjà fait les boutons grisés avec l'info-bulle « Aucune vente ni encaissement à fermer. ». Le lot « suite » ajoute, avec ces réponses :

1. **Demande forgée pour la caisse d'un autre vendeur** : le message reste « La caisse de ce vendeur n'est plus ouverte. » (alerte orange).
2. **Info-bulle au clavier et au doigt** : confirmée (ouverte à la prise de focus et à l'appui, annoncée aux lecteurs d'écran).
3. **Propriétaire** : il n'a pas de bouton « Fermer ma caisse » (il ne vend jamais) : le bouton n'existe pas pour lui, il n'est pas grisé.
4. **Double clic** : la seconde demande rend le relevé déjà fermé, avec le message « Cette caisse vient d'être fermée. » (notification grise) au lieu du silence.
5. **Caisse restée ouverte la veille** : rien à changer (le relevé compte les opérations d'un jour précédent, la fermeture d'office de minuit la ferme).
6. **Proposition 1** (dire dans l'info-bulle quand la caisse s'est fermée) : NON retenue, le texte fixé par l'éditeur ne change pas.
7. **Proposition 2** : retenue : après « Fermer ma caisse », une notification verte « Votre caisse est fermée. ».
