# Décision de l'éditeur du 25/10/2026 : calcul exact, prix unitaires affichés au centime, note de calcul gardée

Ce fichier fait foi pour le lot PU.

## Textes d'origine de l'éditeur (dans l'ordre, le dernier tranche)

- « Donc un prix TTC par exemple 50 € doit se diviser avec la TVA qu'il doit pas être arrondi, par exemple 50/1.2 = 41,666666667, donc jamais perdre rien des milli-centimes, et si sont plusieurs comme ça alors tu calcules avec ce résultat réel sans arrondir car tu arrondis juste le résultat à la fin […] tu arrondis pas un par un mais au total. Et une autre chose importante : tu fais comme ça les calculs, mais quand tu affiches un par un alors tu affiches arrondi juste pour l'œil humain, mais tu calcules derrière comme je te dis. »
- Exemples de l'éditeur : 10 ÷ 1,2 calculé 8,3333… affiché 8,33 ; 50 ÷ 1,2 calculé 41,6666… affiché 41,67 ; 70 ÷ 1,2 calculé 58,3333… affiché 58,33 ; 65 ÷ 1,2 calculé 54,1666… affiché 54,17 ; 4 × 65,00 € TTC : 54,1666… × 4 = 216,666… affiché 216,67 € HT + 43,33 € de TVA = 260,00 € TTC.
- « Bon tu retournes comme il était jusqu'à tout à l'heure et tu mets la mention comme t'avais mis aux PDF, peut-être c'est mieux d'être expliqué car il y aura toujours des écarts de centimes. »
- « Non pas de décimale, donc tu fais 54.17 € et non 54.1667. »

## Règles

1. Calcul : inchangé (règle « Prix et TVA des ventes » de CLAUDE.md) : pour chaque taux, le HT est le TTC de ses lignes ÷ (1 + taux), arrondi une seule fois au total du taux, puis réparti sur les lignes par cumul ; aucun arrondi ligne par ligne avant le total ; montants en centimes entiers. Le PU HT reste enregistré avec six décimales et ne sert jamais à recalculer un total. Les achats gardent leur règle. Aucun montant enregistré, aucun contenu signé ne change.
2. Affichage : tout prix unitaire affiché en lecture (PDF des factures, avoirs, devis et tickets, listes, fiches, caisse, e-mails ; PU HT comme PU TTC ; ventes comme achats) s'affiche arrondi au centime le plus proche, un demi-centime exact au centime supérieur : « 54,17 € » pour 54,166667 €, jamais « 54,1667 € » ni « 54,166667 € ». Un champ de saisie montre la valeur telle qu'elle est enregistrée. Les montants (HT, TVA, TTC des lignes et des taux) s'affichent comme aujourd'hui.
3. Note de calcul des PDF : gardée (MentionsFiscales::calcul), avec une courte phrase de plus, dans chacune de ses variantes, qui dit que les prix unitaires sont affichés arrondis au centime et que les totaux sont calculés sans arrondi intermédiaire, ce qui explique un écart d'un centime possible entre « quantité × prix unitaire affiché » et le total d'une ligne. Les mentions obligatoires restent toutes.
4. CLAUDE.md : la puce « Prix et TVA des ventes » (phrase du PU HT) et la puce « Documents de vente » (colonnes du PDF) disent que le PU HT, enregistré avec six décimales, s'affiche arrondi au centime, et que la note de calcul du PDF l'explique.
