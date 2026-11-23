# Lot de la marge (art. 297 A du CGI) : cahier (23/11/2026)

Lot NON lancé : tout est arrêté sur l'ordre de l'éditeur du 23/11 (« tu arrêtes tout… pour recommencer quand je te dis »). Moteur : TVA = Opus 5.5, effort max. Place : étape 1, après TVA27-bis, avant l'étape 2.

## Ce qui existe
Rien dans le code. Seulement les décisions de Claude (D76) de la matrice (`tests/Unit/matrice-des-decisions.php`) : T60.1 mentions (ligne sans taux ni TVA, « (art. 297 A du CGI) », VATEX-EU-F/I/J), T60.2 marge négative = 0 € de TVA, T60.3 avoir (annule la TVA de la marge, remet en stock au prix d'achat d'origine), T60.4 clôture/e-reporting/état de TVA déclarent la marge, T60.5 conditions de la case « marge » à l'achat, T60.6 vente sous la marge même à un professionnel, T60.7 jamais de LIC ni de DOM exonérés (l'export reste exonéré), T75.3 achat à un particulier (étape 14).

## Décision de l'éditeur (23/11/2026, après l'arrêt), dite avec ses mots
« ce genre de tva il faut laisser les options dans la facture d'achat quand on ajoute des produits, donc il faut avoir un menu déroulant qui parle de quelle tva entre produit et donc il doit être choisi par défaut tva normal de 20% et donne l'occasion de changer et donc le calcul de tva se fera aussi pour ce genre de tva à l'achat et à la vente. »

## Ce que Claude en tire (à appliquer par le lot ; réponse de l'éditeur postérieure prime)
1. **Facture d'achat (écran et API), une ligne = un choix de régime de TVA**, par un menu déroulant sur chaque ligne de produit : « TVA normale 20 % » (8,5 % pour un magasin dont le territoire est la Guadeloupe, la Martinique ou La Réunion) choisi par défaut ; les autres taux (10 %, 5,5 %, 2,1 %, 0 %) ; « Marge (art. 297 A du CGI) ». Le défaut est le taux normal ; la personne change si le fournisseur vend sous la marge.
2. **T60.5 précisée** : la case « marge » ne se refuse plus sur « un achat avec TVA » mais ligne par ligne. Une ligne « marge » n'a aucune TVA déductible (son total TTC = son HT, la facture du fournisseur n'en montre pas) ; une ligne à taux a sa TVA ; une même facture peut mêler les deux (fournisseur d'articles neufs et d'occasion). Refus, en alerte claire : une ligne « marge » avec une TVA saisie ; sans pièce qui prouve le prix d'achat, la TVA reste sur tout le prix (T60.5 inchangée).
3. **Le produit garde son régime** (celui de sa ligne d'achat) : un produit n'a qu'un régime ; un achat ultérieur du même produit sous un autre régime est refusé avec un message qui dit de créer un autre produit. La vente reprend le régime du produit, sans menu pour le vendeur (T60.6 inchangée : toujours sous la marge, même à un professionnel).
4. **Le calcul de TVA à l'achat et à la vente** (demande de l'éditeur) : à l'achat, une ligne « marge » ne porte pas de TVA déductible (le récapitulatif par taux n'a aucune ligne pour elle, un total « dont articles sous la marge » à part) ; à la vente, la TVA de la ligne est celle de sa marge (prix de vente TTC − prix d'achat), centimes entiers, un arrondi, jamais recalculée ensuite (T60.2 à T60.4).
5. **Semis** (règle 6) : au moins une facture d'achat qui mêle lignes à taux et lignes « marge », une vente à un particulier et une à un professionnel sous la marge, une marge négative, un avoir sur une ligne sous la marge, de chaque côté de la limite (marge positive / nulle / négative).

## Question ouverte pour l'éditeur (règle de calcul, point 4 du protocole : à lui)
Quel prix d'achat prendre pour calculer la marge d'un produit acheté plusieurs fois à des prix différents ?
- Option A (conseillée) : le coût moyen du produit, comme pour toute la gestion du stock (simple, déjà tenu par le grand livre) ; l'écart avec la marge réelle objet par objet est connu et dit sur l'écran.
- Option B : le prix de la dernière entrée encore en stock, objet par objet (plus exact, demande un suivi par lot dans le grand livre du stock : lot plus lourd).
Sans réponse de l'éditeur, le lot ne s'engage pas sur ce point.

## Suite de la question ouverte (23/11/2026, réponse de l'éditeur : il penche pour le dernier prix, et demande comment A donnerait la TVA exacte)
Claude a corrigé son conseil : **B (prix d'achat de l'objet vendu, objet par objet) devient le conseil**, A (coût moyen) est déconseillé.
- La TVA de la marge = (prix de vente TTC − prix d'achat de l'objet) × taux ÷ (1 + taux), centimes entiers, un arrondi, enregistrée à la vente et jamais recalculée. Exemple : acheté 100 €, vendu 150 € TTC : marge 50 €, TVA 8,33 €.
- Avec A, la marge d'un objet vendu avec perte (0 € de TVA, T60.2, jamais compensée) se mêle à celle des autres : exemple deux chaises achetées 40 € et 80 €, coût moyen 60 €, vendues 70 € chacune ; A donne 2 × 1,67 = 3,34 € de TVA, B donne 5,00 € (chaise à 40 € : marge 30 € ; chaise à 80 € : marge −10 €, donc 0 €). A déclare donc moins que la règle, au risque de la société : A n'est pas « exacte ».
- « Toujours le dernier prix » n'est exact que pour un objet unique (une pièce d'occasion = un produit à une unité) ; pour des objets identiques achetés à des prix différents, le dernier prix fausse les objets du lot précédent. Convention proposée pour des objets identiques : le lot le plus ancien encore en stock part le premier (premier entré, premier sorti), par lot d'achat tenu au grand livre du stock ; à confirmer par l'éditeur.
