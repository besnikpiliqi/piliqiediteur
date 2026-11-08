# Lot CALC : documents aux professionnels calculés ligne par ligne depuis le PU HT au centime, TVA arrondie par ligne ; montants sans nombre à virgule flottante

## 1. Les messages de l'éditeur, mot pour mot (08/11/2026)

- Vers 13 h 20 UTC, conseil lu sur internet sur la précision des calculs (bibliothèques décimales plutôt que nombres à virgule flottante) : « je crois qu'il a raison mais tu me dit aussi c'est bien d'apliquer ca » (voir `calculs/sans-virgule-flottante.md`).
- Vers 13 h 35 UTC, discussion avec un tiers : « en fait chez moi il y pas de 10.5525 mais dans ce "10.5525" est 10.55 car il prend plus bas mais dans le calcule il prends et cumule 105525 et qui avec plusieur lignes ca changer le resultat, donc c est pas 10.55 * 2 = 21.1 mais 21.105, donc ca va cumuler », suivi du texte de ce tiers sur « l'arrondi à la ligne » (« si le client voit écrit 10,55 € sur la facture, pour lui, le calcul doit être 10,55 × 2 = 21,10 € »).
- Vers 13 h 50 UTC : « non non tu calcule par linga la tva et pas par le total des article car comme ca normale qui il y aura toujour des erreus de centimes. si on calcule par ligne c est mieux car meme quand le client calcule lui meme alors tombe pil poil. mais normal que Le PU HT exact est 8,333333 €. La facture l'imprime arrondi : 8,33 € doit se calcule comme ca ».
- Vers 14 h 05 UTC : « non tu calcule separement par ligne d accord 10€ttc et 8.33€ht ».
- Vers 14 h 25 UTC (après la proposition d'un PU HT à 4 décimales) : « mais juste il faut calcule la avec la tva ling par linge mais pas affiche 8.3333 mais 8.33€ht ».
- Vers 14 h 55 UTC (après la règle « 2 sur 3 » : prix imprimé au centime, calcul juste à la calculatrice, prix de l'étiquette) : « Je veux calcule ligne par ligne avec sa tva et arrondir par ligne et non par total. Donc 8.33ht*2=16,66 et pareil 10€ttc et après le total de calcul par rapport aux lignes ».

Décision retenue (Q-CALC, choix 1 avec la TVA par ligne) : le prix imprimé au centime et le calcul juste à la calculatrice passent avant le prix de l'étiquette, pour les documents aux professionnels. Elle remplace, pour ces documents, la décision du 02/11/2026 (« les C = non », statu quo). Lecture de Claude, redite à l'éditeur le 08/11 avec ses exemples chiffrés : 2 articles à 10,00 € TTC sur une ligne font 16,66 € HT, 3,33 € de TVA et 19,99 € TTC ; un article seul, 8,33 €, 1,67 € et 10,00 €. S'il corrige cette lecture avant le début du lot, sa correction s'écrit ici et fait foi.

## 2. La règle

1. **Documents concernés** : toute facture, tout avoir sur facture et tout devis à un professionnel, et toute vente sans TVA française (export, livraison vers un DOM, livraison intracommunautaire), même à un particulier, qui se paie déjà au prix hors taxe. La méthode se déduit du type de client, de la nature d'opération et de la franchise en base, déjà figés ; le document la garde.
2. **PU HT d'une ligne** : le PU TTC de la fiche du produit, ou le prix de référence du service, ÷ (1 + taux), arrondi une fois au centime le plus proche, un demi-centime au centime supérieur (10,00 € TTC à 20 % : 8,33 € ; 9,99 € : 8,33 € ; 12,66 € : 10,55 €) ; pour un prix dérogatoire, le PU HT saisi, au centime. C'est ce PU HT, au centime, que le document imprime et avec lequel il calcule.
3. **HT de la ligne** : quantité × PU HT, la remise en pourcentage déduite sur le net de la ligne, arrondi une seule fois au centime (2 × 8,33 € = 16,66 € ; 100 × 8,33 € = 833,00 €).
4. **TVA de la ligne** : HT de la ligne × taux, arrondie une fois au centime, ligne par ligne (16,66 € × 20 % = 3,33 € ; 833,00 € × 20 % = 166,60 €).
5. **TTC de la ligne** : HT + TVA de la ligne (19,99 € ; 999,60 €).
6. **Totaux** : le total HT, la base et la TVA de chaque taux et le TTC sont les sommes des lignes, sans aucun autre arrondi ; la base × le taux peut donc s'écarter de la TVA du taux d'au plus un demi-centime par ligne de ce taux (trois lignes d'un article à 10,00 € TTC : 24,99 € HT, 5,01 € de TVA, 30,00 € TTC, alors que 24,99 € × 20 % = 5,00 €), ce que dit la note de calcul.
7. **Ce que paie le professionnel** : la somme des lignes, qui peut s'écarter du prix de l'étiquette × la quantité (2 × 10,00 € → 19,99 € ; 100 × 10,00 € sur une ligne → 999,60 €) ; l'écran de la vente montre ce TTC avant l'émission. La phrase de `CLAUDE.md` « un même panier a les mêmes montants pour tout client » ne vaut plus pour ces documents.
8. **Tickets, avoirs sur ticket et devis à un particulier** vendus avec la TVA française : inchangés (méthode TTC actuelle), le particulier payant exactement le prix affiché (arrêté du 3 décembre 1987).
9. **Retours et avoirs** : un avoir prend la méthode des documents qu'il corrige et n'en mélange jamais deux. Sur un document calculé par cette méthode, un retour rend, pour les articles rendus, quantité rendue × PU HT de la ligne, remise déduite, et la TVA de ce HT arrondie une fois ; les retours d'une même ligne ne rendent jamais plus que son HT ni que sa TVA, et le retour de ce qui reste de la ligne rend exactement ce qui reste (une ligne de 2 articles, 16,66 € et 3,33 € : un premier retour d'un article rend 8,33 € et 1,67 €, le second 8,33 € et 1,66 €) ; la note de calcul de l'avoir le dit. La facture rectificative d'une régularisation de SIREN suit la même règle. Ce point est à faire confirmer par l'expert-comptable de la société.
10. **Brouillon dont le client change de type** (particulier ↔ professionnel) : il se recalcule par la méthode de son nouveau type à son enregistrement suivant, et l'écran prévient que ses montants peuvent changer.
11. **Ce qui ne change pas** : les achats (montants de la facture du fournisseur, lot TR) ; les clôtures, l'e-reporting, les grands totaux, les tableaux de bord et les déclarations, qui additionnent les montants enregistrés sans jamais les recalculer ; la TVA déclarée, qui reste exactement celle des documents.
12. **Facture électronique (étape 2)** : la ventilation par taux transmise est la somme des lignes ; son écart avec la base × le taux (au plus un demi-centime par ligne) se vérifie contre les contrôles de la norme EN 16931 (règle BR-CO-17 et sa révision annoncée) dans le bac à sable SUPER PDP ; à noter dans les points à vérifier de l'étape 2.

## 3. Affichage

- PDF et fiche à l'écran d'un document calculé par cette méthode : par ligne, la quantité, le PU HT au centime, la remise s'il y en a, le taux, le total HT, la TVA de la ligne et le total TTC de la ligne ; par taux, la base et la TVA (sommes des lignes) ; la note de calcul sous les lignes dit que chaque ligne se calcule depuis son PU HT au centime, sa TVA arrondie par ligne, et que les totaux sont les sommes des lignes.
- La note actuelle (« les prix unitaires sont affichés arrondis au centime et les totaux, calculés sans arrondi intermédiaire, peuvent différer de la quantité × le prix unitaire affiché ») ne reste que pour les documents calculés en TTC (tickets, avoirs sur ticket, devis à un particulier).

## 4. Montants sans nombre à virgule flottante (dans ce lot)

Appliquer en entier `calculs/sans-virgule-flottante.md` (le lot réécrit le calcul : c'est le bon moment ; ce point n'est plus dans le lot AUD).

## 5. Textes de `CLAUDE.md` (rédigés par Claude d'après la décision)

1. Puce « Prix et TVA des ventes » : les phrases de « Toute vente, à un particulier comme à un professionnel, ticket, facture ou devis, se calcule une seule fois […] » jusqu'à « […] (4 × 65,00 € TTC à 20 % font 216,67 € HT, quand 4 × 54,17 € feraient 216,68 €). » sont réécrites en deux méthodes : la méthode TTC actuelle, pour les tickets, les avoirs sur ticket et les devis à un particulier vendus avec la TVA française (texte actuel gardé pour eux) ; la méthode par ligne de la section 2 ci-dessus pour toute facture, tout avoir sur facture et tout devis à un professionnel et pour toute vente sans TVA française, avec ses exemples (2 × 10,00 € TTC : 16,66 € HT, 3,33 € de TVA, 19,99 € TTC ; trois lignes d'un article : 24,99 €, 5,01 €, 30,00 €). La phrase « un même panier a les mêmes montants pour tout client » devient « un même panier a les mêmes montants pour tout particulier ». La règle du retour suit la section 2, point 9.
2. Puce « Documents de vente » : « Toute facture, tout avoir sur facture et tout devis à un professionnel montrent par ligne la quantité, le PU HT, la remise s'il y en a, le taux et le total HT » devient « […] le taux, le total HT, la TVA et le total TTC de la ligne » ; la phrase sur le PU HT « enregistré avec six décimales, s'y imprime arrondi au centime » et la note de calcul suivent la section 3.
3. Puce « Ventes hors de France » : « se paie au prix hors taxe, calculé de la même façon, sans TVA » devient « se paie au prix hors taxe, calculé ligne par ligne depuis le PU HT au centime, sans TVA ».
4. Feuille de route, étape 1 : « politique d'arrondi unique des ventes et des achats » devient « méthode par ligne des documents aux professionnels, méthode TTC des ventes aux particuliers ».

## 6. Tests attendus

- Les valeurs attendues des documents aux professionnels changent par cette décision : chaque test réécrit se dit dans le compte rendu (« test réécrit par la décision Q-CALC du 08/11/2026 »), sans jamais affaiblir ce qu'il vérifie ; ceux des tickets et des particuliers ne changent pas.
- Nouveaux tests aux montants écrits : 1 × 10,00 € TTC (8,33 / 1,67 / 10,00) ; 2 × 10,00 € (16,66 / 3,33 / 19,99) ; 100 × 10,00 € (833,00 / 166,60 / 999,60) ; trois lignes d'un article (24,99 / 5,01 / 30,00, note du récapitulatif) ; 9,99 € et 12,66 € ; remise en pourcentage ; taux de 5,5 %, 10 % et 8,5 % (outre-mer) ; franchise en base ; vente sans TVA française ; retours partiels (8,33 / 1,67 puis 8,33 / 1,66) et retour du reste ; rectificative ; brouillon dont le client change de type ; PDF et fiche (colonnes TVA et TTC par ligne, note de calcul) ; e-reporting et clôtures inchangés ; tests de propriétés du lot QA adaptés (invariants : HT de ligne = quantité × PU HT remise déduite ; TVA de ligne = HT × taux arrondie ; totaux = sommes des lignes ; retours jamais au-delà de la ligne).
- Suite PostgreSQL au code 0 (D55), tests en navigateur compris (facture à un professionnel émise, PDF lu), outils de qualité du lot QA verts.

## 7. Place

Après PG, avant FI (choix de Claude : l'éditeur suit de près les calculs ; le lot réécrit le cœur du calcul avant les lots suivants), effort Max.
