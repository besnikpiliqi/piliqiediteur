# Décision de l'éditeur du 01/11/2026 : la TVA sur la marge (régime de la marge), dans la chaîne TVA

Ce fichier fait foi pour le régime de la marge. Il avance une partie de l'étape 14 de la feuille de route de `CLAUDE.md` (« Achats auprès de particuliers, pour les sociétés qui ont des articles (registre des objets achetés, régime de TVA sur marge) ») : le régime de la marge passe dans la chaîne TVA (`notes/tva-lots-M0-FIN.js`), juste après la chaîne actuelle ; les achats auprès de particuliers et leur registre restent à l'étape 14.

## 1. Les mots de l'éditeur

Message 324 (01/11/2026, 11 h 14 UTC), mot pour mot :

> "porte la mention obligatoire « TVA non applicable, art. 293 B du CGI »" il faut avoir une case à cocher quand se creer un produit alors il y en a des produit tva sur marge donc des produits d'ocasion meme s'il facture des tva mais existe des produits qu'on facture pas la tva. donc il faut avoir una case à cote du produit du prix pour montrer que ce produit est tva sur marge. et meme dans la facture pdf ou electronique montrer l'article la loi et le mention

Décision antérieure qu'il complète : série 8, point 11 (`serie8/demande-editeur.md`, `serie8/reponse-editeur.md`) : « TVA sur la marge ligne par ligne », mention « Régime particulier – Biens d'occasion ».

## 2. Ce que la session lui a présenté (non contesté)

- Le régime de la marge (art. 297 A du CGI) s'applique aux biens d'occasion, objets d'art, objets de collection et d'antiquité achetés à quelqu'un qui n'a pas facturé de TVA déductible (un particulier, ou un professionnel qui vend lui-même sous ce régime). La TVA n'est due que sur la marge (prix de vente − prix d'achat). Exemple : téléphone acheté 100 € à un particulier, revendu 160 € ; marge 60 €, dont 10 € de TVA à 20 % ; la facture affiche 160 € sans ligne de TVA, avec la mention « Régime particulier – Biens d'occasion » et son article de loi ; le magasin déclare les 10 €.
- La mention dépend de l'objet : « Régime particulier – Biens d'occasion », « Régime particulier – Objets d'art », « Régime particulier – Objets de collection et d'antiquité ».
- Une même facture peut porter des produits à la TVA normale et des produits sous le régime de la marge.
- Sur la fiche du produit, près du prix, une case « Bien d'occasion – TVA sur la marge », avec le choix de l'une des trois mentions. Un produit ne naît que d'une facture d'achat ou d'un inventaire d'ouverture : la case se coche sur la ligne d'achat ou d'inventaire, avec le prix d'achat de l'article, qui sert à calculer la marge.
- Sur le PDF et sur la facture électronique : aucune TVA sur ces lignes, la mention et l'article de loi imprimés automatiquement.
- Règles à faire confirmer par l'expert-comptable de la société.

## 3. Ses deux choix (message 325, 01/11/2026, 11 h 20 UTC : « 1. Quand le faire ? le b) et 2. Comment calculer la marge ? le a) »)

1. **Quand : b), dans la chaîne TVA**, juste après la chaîne actuelle, avant l'étape 2 (facture électronique), qui aura ainsi les bonnes mentions dès le départ. Les produits sous le régime de la marge viennent alors de l'inventaire d'ouverture et des achats à des professionnels qui vendent eux-mêmes sous ce régime ; les achats auprès de particuliers (registre des objets achetés) restent à l'étape 14.
2. **Calcul de la marge : a), article par article** : chaque article garde son propre prix d'achat. La méthode globale (marge de la période, permise pour les objets achetés 300 € ou moins) n'est pas faite ; elle pourra s'ajouter plus tard sur demande.

## 4. À trancher par l'atelier de conception de la chaîne TVA (questions métier soumises à l'éditeur avant le lot)

L'atelier qui met `tva-lots-M0-FIN.js` d'accord (fiche, § 6, « Chaîne TVA ») ajoute un lot du régime de la marge et vérifie sur les sources officielles (CGI art. 297 A à 297 G, annexe II art. 242 nonies A, BOFiP TVA-SECT-90, norme de la facture électronique et catégories de l'e-reporting) au moins : la mention exacte et son article ; le calcul de la TVA sur la marge depuis le prix TTC, en centimes, avec la règle d'arrondi unique de `CLAUDE.md` ; la marge négative (aucune TVA, aucune compensation dans la méthode article par article) ; le retour d'un article vendu sous ce régime ; la place de ce régime dans les clôtures, les grands totaux, l'e-reporting et la facture électronique ; le coût moyen pondéré face au prix d'achat de chaque article (un article identifié par sa ligne d'achat) ; la vente d'un tel article à un professionnel (il ne déduit aucune TVA) et hors de France ; les sociétés de services (aucun produit, donc sans objet) ; la franchise en base (sans objet : aucune TVA). Les questions métier qui en sortent sont posées à l'éditeur, en français simple, avec une recommandation, avant le lot.
