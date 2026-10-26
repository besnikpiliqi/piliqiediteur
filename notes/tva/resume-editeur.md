# Moteur de TVA centralisé : résumé pour l'éditeur

Rédigé le 11/10/2026, en réponse à votre demande du même jour.

C'est une **proposition**. Rien n'a été développé ni modifié dans le logiciel. La conception complète, pour les développeurs, est dans `scratchpad/tva/conception-finale.md`. Elle a été relue trois fois par des relecteurs chargés d'y chercher des erreurs ; 54 défauts ont été trouvés, vérifiés et corrigés (son annexe C).

Ce résumé dit :
1. ce qui existe déjà ;
2. ce qui manque ;
3. la solution proposée ;
4. trois exemples, tels qu'ils apparaîtront à l'écran ;
5. le plan de travail ;
6. ce qui reste pour plus tard ;
7. les **16 questions** que vous devez trancher (T1 à T16) ;
8. huit points appliqués sauf si vous vous y opposez ;
9. les points à faire confirmer par votre expert-comptable.

---

## 1. Ce qui existe déjà

**Pour les ventes, la base est solide.**
- Six régimes de TVA existent : vente en France, export hors de l'Union européenne, livraison vers un département d'outre-mer (DOM), livraison à une entreprise d'un autre pays de l'Union, prestation à une entreprise de l'Union, prestation à une entreprise hors de l'Union.
- Le vendeur choisit le régime dans une liste. À l'émission, le logiciel vérifie ses conditions. Par exemple, il vérifie le n° de TVA du client sur **VIES**, le service européen qui dit si un n° de TVA est valide.
- Les calculs que vous avez validés sont exacts au centime : prix TTC arrondi une seule fois, vente sans TVA française payée au prix hors taxe, franchise à 0 % au même prix TTC, retours partiels.
- Les mentions légales s'impriment. Les preuves d'export et de livraison se notent après l'émission.

**Pour les achats, il n'y a aucune règle de TVA.**
- Une ligne d'achat n'a qu'un taux : celui de la fiche du produit, qui sert aussi à la vente.
- Une ligne d'achat est toujours un produit : on ne peut pas saisir des frais de transport ou un service.
- Le logiciel ne connaît ni l'origine des marchandises, ni l'**autoliquidation** (quand c'est vous qui déclarez la TVA à la place du fournisseur étranger), ni l'importation, ni les droits de douane.

**Quatre erreurs réelles ont été trouvées dans le code actuel.**
- Saisir un achat allemand à 0 % fait vendre le produit à 0 % en caisse.
- Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon, la Guyane et Mayotte sont traités comme s'ils appliquaient la TVA française, ce qui est faux.
- Un magasin des Antilles ou de La Réunion est toujours supposé expédier depuis la métropole.
- Toute facture à un professionnel partirait en facture électronique, même quand la loi prévoit un autre circuit.

Enfin, la même règle est écrite à plusieurs endroits du code, avec des formes différentes. C'est ce qui crée le risque de « calculs contradictoires » que vous voulez éviter.

## 2. Ce qui manque, par rapport à votre demande

| Votre demande | Aujourd'hui |
|---|---|
| Origine réelle des marchandises achetées | Absente |
| Distinguer marchandises et services à l'achat | Impossible |
| TVA facturée, autoliquidation, TVA déductible, montant à payer, coût du stock | Seuls le HT, la TVA facturée et le TTC existent ; le coût du stock ignore le transport et les droits de douane |
| Destination réelle des marchandises vendues, lieu des services | Saisie seulement dans certains régimes ; le lieu d'un service n'est jamais demandé |
| Ventes à distance et guichet OSS | Absents : rien ne surveille le seuil de 10 000 € |
| Préremplir, ne demander que l'utile | Le vendeur choisit tout dans une liste |
| Afficher le régime et sa justification | Non |
| Bloquer ou signaler | Les refus arrivent un par un, à l'émission |
| Traçabilité des décisions | Partielle pour les ventes, nulle pour les achats |
| Un moteur partagé par tous les modules | Non : règles dispersées |

## 3. La solution proposée

**Un seul moteur décide de la TVA et calcule tous les montants.** Les écrans, la caisse, l'API, le stock et, plus tard, les déclarations l'appellent tous. Aucun ne refait un calcul à sa façon. On lui donne des faits (le client, la destination, l'origine, le n° de TVA vérifié, la date…), il rend une décision : le régime, les montants, la mention à imprimer, la transmission prévue et la justification.

**Le pays ne décide jamais seul : il déclenche une question.** Une vente sans TVA française n'est retenue que si toutes ses conditions sont réunies. Sinon, la vente reste à la TVA française, comme vous l'avez décidé (réponse I1).

**Pour une vente en France, rien ne change.** Pas de champ en plus, pas de question en caisse. Un petit lien « Expédier hors de France ou livrer à une autre adresse ? » reste disponible.

**Ce que le logiciel sait déjà se préremplit.** Une valeur préremplie affiche toujours d'où elle vient (« comme sa facture du 02/10/2026 »). Les questions n'apparaissent que si elles servent.

**Un panneau « Régime de TVA »** dit, en mots simples : le régime, qui paie la TVA, la mention imprimée, chaque condition (« oui » ou « non »), ce qui manque, et la transmission prévue. Aucun code technique n'est affiché.

**Deux niveaux de contrôle.**
- **Bloquant** : la facture ne s'émet pas (ou l'achat ne se valide pas) tant qu'un fait indispensable manque. Tous les manques sont dits d'un coup.
- **Avertissement** : rien n'est refusé, mais le message reste visible et il est gardé.

**Traçabilité.** La décision d'une vente est signée avec le document, comme les autres données de la caisse sécurisée. Celle d'un achat est figée à sa validation, et chaque modification s'inscrit au journal d'audit de la société, avec l'avant et l'après. Tout est gardé 10 ans.

**Les règles qui changent sont des données datées** : taux, mentions, seuils. Par exemple, au 1/1/2027, les articles du code général des impôts (CGI) passent dans un nouveau code (CIBS). Une facture émise garde pour toujours le texte de son jour.

**Pour les achats**, la facture d'achat reçoit :
- un champ « **Origine des marchandises** » (France · autre pays de l'Union · hors de l'Union), affiché dès qu'il y a un produit, prérempli « France » pour un fournisseur français ;
- trois sortes de lignes : produits, frais de transport, autres services ;
- les montants que vous demandez : TVA facturée, TVA autoliquidée, **TVA déductible** (celle que vous retirez de la TVA que vous devez), montant à payer au fournisseur, TVA à payer à l'État, et **coût d'entrée en stock** (ce que coûte vraiment un article à son arrivée, transport et droits de douane compris).

**Le taux d'une ligne d'achat ne change plus jamais le taux de vente du produit.**

## 4. Trois exemples, tels qu'ils apparaîtront à l'écran

### 4.1 Achat dans l'Union : 100 gourdes à un grossiste allemand

Spielwaren AG, à Berlin, vous facture 100 gourdes à 8,00 € HT, plus 40,00 € de port. Sa facture ne porte pas de TVA, comme la loi le prévoit.

1. Vous choisissez le fournisseur. « Origine des marchandises » se remplit seul : « Allemagne (comme sa facture du 02/10/2026) ». La première fois, il faut le confirmer.
2. Une question : « La facture porte-t-elle de la TVA ? » Vous répondez « Non ».
3. Les totaux s'affichent :

```
Total HT des marchandises et frais ....................... 840,00 €
TVA facturée par le fournisseur ..........................   0,00 €
À payer au fournisseur ................................... 840,00 €
TVA que vous déclarez vous-même (autoliquidation, 20 %) .. 168,00 €
TVA déductible sur la même déclaration ................... 168,00 €
TVA à payer à l'État pour cet achat ......................   0,00 €
Coût d'entrée en stock ....................... 840,00 € (8,40 € l'article)
```

4. Le panneau explique : « Vous déclarez la TVA à la place du fournisseur et la déduisez sur la même déclaration : rien à payer en plus. » Il liste les conditions vérifiées : fournisseur établi en Allemagne, n° de TVA valide sur VIES, marchandises parties d'Allemagne, votre société a un n° de TVA français.
5. Le port est réparti sur les articles : chaque gourde entre en stock à 8,40 € et non 8,00 €.
6. Le taux de vente de la gourde (20 %) ne bouge pas.

### 4.2 Importation : 100 articles achetés en Chine

Un fournisseur chinois facture 100 articles à 6,60 € (660,00 €). Votre transitaire dédouane la marchandise. La déclaration en douane indique 24,00 € de droits et 136,80 € de TVA à l'importation.

1. « Origine des marchandises » : « Hors de l'Union, Chine ».
2. Question : « Qui a dédouané les marchandises ? » Vous répondez « Nous, ou notre transitaire ».
3. Vous saisissez la déclaration : référence, droits, TVA. Si elle n'est pas encore arrivée, vous pouvez valider quand même (question T12) : la facture entre dans une liste « Dédouanement à compléter ».
4. Les totaux s'affichent :

```
Total HT ................................................. 660,00 €
À payer au fournisseur ................................... 660,00 €
TVA d'importation (déclaration en douane) ................ 136,80 €
TVA déductible sur la même déclaration ................... 136,80 €
TVA à payer à l'État pour cet achat ......................   0,00 €
À payer à la douane ou au transitaire : droits ...........  24,00 €
Coût d'entrée en stock ....................... 684,00 € (6,84 € l'article)
```

5. Le logiciel vérifie la TVA de la déclaration : (660,00 + 24,00) × 20 % = 136,80 €. En cas d'écart, il avertit, mais le montant de la déclaration reste la référence.
6. Si un transporteur vous facture ensuite le trajet du Havre à Lyon (50,00 €) et que vous rattachez sa facture à celle des marchandises (question T14), le coût passe à 7,34 € l'article.

### 4.3 Vente à une entreprise d'un autre pays de l'Union

Müller GmbH, à Berlin, achète 10 gourdes à 12,00 € TTC. Vous les expédiez.

1. Vous choisissez le client. Une question apparaît : « Les marchandises quittent-elles la France ? » Vous répondez « Oui, nous les expédions ».
2. « Où sont-elles livrées ? » : l'adresse du client est préremplie (Berlin). « Date de livraison ? » : la date du jour est proposée.
3. Le n° de TVA du client est valide sur VIES aujourd'hui. Le panneau devient vert :

```
Livraison intracommunautaire appliquée d'après vos réponses :
le client paie 100,00 € au lieu de 120,00 €          [Rester à la TVA française]

Qui paie la TVA     le client, dans son pays
Mention imprimée    « Exonération de TVA, article 262 ter I du CGI »
Pourquoi            client établi en Allemagne ...................... oui
                    n° de TVA valide sur VIES ce jour à 10 h 32 ..... oui
                    marchandises expédiées de Lyon à Berlin ......... oui
                    date de livraison : 14/10/2026 .................. oui
Après l'émission    deux documents de transport, d'organismes indépendants de vous et du client
À faire             facture au plus tard le 15/11/2026 ; relevé du mois pour la douane
Transmission        SUPER PDP la déclare pour vous
```

4. Si le n° de TVA n'est pas reconnu, la vente reste à la TVA française (120,00 €) et le panneau dit pourquoi. Si VIES ne répond pas, la facture n'est pas émise et le message dit qu'elle peut l'être jusqu'au 15/11.

## 5. Le plan de travail

Le travail est coupé en petits lots, appelés M0 à M9. Chaque lot est testé sur les deux bases de données, relu, puis enregistré.

| Lot | Contenu, en mots simples | Attend vos réponses ? |
|---|---|---|
| M1 | La calculatrice et la vérification VIES passent dans le moteur, sans rien changer | Non |
| M2 | Le moteur des ventes remplace les règles dispersées, sans changer un seul centime | Non |
| M0 | Vos réponses sont écrites dans les règles du projet | Oui |
| M3 | Statut du client (assujetti, public), transmission selon la loi, choix du régime enregistré | Oui |
| M4 | Les écrans de vente : panneau, questions, relevé du mois pour la douane | Oui |
| M5 | Les achats : règles et calculs | Oui |
| M6 | Les achats : écrans | Oui |
| M7 | Le coût du stock : transport et droits rattachés, valeur du stock | Oui |
| M8 | Les ventes à distance : compteur du seuil de 10 000 € | Oui |
| M9 | Après SUPER PDP, si vous le confirmez : guichet OSS, export emporté par le client, DOM, cartes cadeaux | Oui |

**Place dans la feuille de route** (question T1) :
- après la chaîne en cours sur l'architecture des sociétés (lots A4 à A7) et son lot de fin (lot 21) ;
- M1 et M2 peuvent commencer tout de suite après, sans attendre vos réponses ;
- les petits lots de compléments déjà décidés s'intercalent (C-a, puis C-b après M3) ;
- l'étape 1 de la feuille de route n'est cochée qu'après M8 ;
- puis vient la campagne de tests initiale (étape 1 bis), qui éprouvera ainsi la forme définitive des ventes et des achats ;
- puis l'étape 2 (SUPER PDP).

## 6. Ce qui reste pour plus tard

**À l'étape 2 (SUPER PDP, la plateforme qui transmet à l'administration)** :
- envoyer les factures électroniques et les déclarations de ventes (« e-reporting ») en lisant les décisions enregistrées, sans rien recalculer ;
- traduire chaque régime dans les codes de la norme européenne de la facture ;
- déclarer les achats faits à l'étranger ;
- confirmer, dans le bac à sable de SUPER PDP, les règles de transmission avant la mise en production.

**À l'étape 5 (comptabilité)** :
- l'état de TVA (collectée, déductible, à payer), les écritures et le fichier des écritures comptables (FEC) ;
- les dates exactes de déclaration et de déduction des achats ;
- le lien avec les lignes de la déclaration de TVA (CA3), à vérifier sur la notice 2026.

**À l'étape 7 (stock)** : les sorties de stock hors vente (vol, casse, démarque, don), et la TVA à reverser éventuellement.

**Au lot M9, après l'étape 2, si vous le confirmez** : guichet OSS, export emporté par le client, régimes propres aux DOM, cartes cadeaux.

---

## 7. Les questions à trancher

Elles sont classées par urgence. « Avant M3 » veut dire : la réponse est nécessaire avant de commencer le lot M3. Ces questions T1 à T16 ne sont pas celles de la série 4 (campagne de tests), déjà répondues.

### T1. Où placer ce travail dans la feuille de route ? (avant la fin du lot 21)
**Contexte.** Le moteur change la façon d'émettre les ventes et de valider les achats. La campagne de tests initiale doit éprouver ces chemins dans leur forme définitive.
- **(a) Avant la campagne de tests initiale**, juste après le travail en cours sur les sociétés.
- (b) Après la campagne, avant SUPER PDP.
- (c) Pendant SUPER PDP.

**Recommandation : (a).** On évite de tester deux fois des chemins qui vont changer, et SUPER PDP a besoin du moteur. La campagne sera de toute façon relancée après SUPER PDP.

### T2. Le régime s'applique-t-il seul ? (avant M3)
**Contexte.** Vous demandez une TVA « calculée automatiquement ». Mais changer de régime change le prix payé par le client : la TVA française est retirée du prix.
- **(a) Seul quand vos réponses le justifient** (« oui, nous expédions en Allemagne », adresse, date), avec le nouveau total affiché et un lien « Rester à la TVA française » ; proposé avec un bouton pour une prestation à une entreprise étrangère ; à l'émission, un choix imposé s'il reste en suspens.
- (b) Toujours proposé, avec un clic « Appliquer » à chaque fois.
- (c) Toujours seul, même pour les prestations.
- (d) Choix manuel dans une liste, comme aujourd'hui.

**Recommandation : (a).** C'est le plus automatique sans changer un prix en silence. Pour une prestation, rien dans le document ne prouve que le client agit comme entreprise : un clic le confirme. Personne ne peut forcer une exonération dont une condition manque.

### T3. Que demander à chaque document ? (avant M3)
**Contexte.** Le pays ne suffit pas : il faut savoir si les marchandises quittent la France (vente) et d'où elles viennent (achat). Une TVA française facturée à tort reste due, et le client étranger ne peut pas la récupérer.
- **(a) Vente** : la question « Les marchandises quittent-elles la France ? » pour un client étranger, sans réponse par défaut, avec la réponse de sa dernière facture proposée ; **achat** : origine toujours affichée, « France » d'office pour un fournisseur français, reprise de la dernière facture validée sinon, confirmée la première fois.
- (b) Des valeurs par défaut : « Non » à la vente, le pays du fournisseur à l'achat.
- (c) Poser les deux questions à chaque fois, sans rien préremplir.

**Recommandation : (a).** (b) laisserait le pays décider seul, ce que vous excluez ; (c) alourdit presque toutes les saisies.

### T4. Prix d'un service vendu sans TVA française (avant M4)
**Contexte.** Un produit vendu à l'étranger sans TVA française se paie à son prix TTC moins la TVA. Pour un service, le vendeur tape aujourd'hui le prix à la main.
- **(a) Comme un produit** : le prix de référence TTC du service, moins la TVA, sans autorisation spéciale.
- (b) Un vendeur autorisé tape le prix à chaque fois.

**Recommandation : (a).** Une seule règle pour tout le catalogue, et un vendeur sans autorisation peut vendre le service à l'étranger.

### T5. Produit à 0 % vendu en France (avant M4)
**Contexte.** Il n'existe pas de taux zéro en France, et PILIQI ne gère pas les ventes exonérées (santé, enseignement…). Aujourd'hui, on peut saisir 0 % sur une fiche produit.
- **(a) Refuser 0 % sur la fiche** du produit ou du service (société qui facture la TVA), et refuser aussi l'émission par sécurité.
- (b) Laisser vendre, avec un avertissement.

**Recommandation : (a).** Une vente à 0 % en France fausserait la déclaration. Refuser sur la fiche évite de découvrir le problème en caisse, devant le client.

### T6. Une seule façon de taxer par facture ? (avant M3)
**Contexte.** La norme européenne permettrait de mettre sur une même facture, par exemple, des marchandises livrées en Allemagne et un service autoliquidé. C'est rare en magasin.
- **(a) Un seul régime par facture** ; une vente en France mêlant produits et services reste possible ; sinon, un bouton « Déplacer ces lignes vers une nouvelle facture ».
- (b) Plusieurs régimes sur une même facture.

**Recommandation : (a).** Une facture, une mention, une transmission : plus simple à lire et à contrôler. Le cas rare coûte un clic.

### T7. Additionner des chiffres de plusieurs magasins (avant M4)
**Contexte.** Le seuil des ventes à distance et les relevés pour la douane se comptent pour toute la société. Les règles du projet interdisent aujourd'hui ces lectures entre magasins.
- **(a) Les autoriser, limitées à des totaux** ; les montants visibles du seul propriétaire et des administrateurs autorisés ; un vendeur ne voit que « seuil non atteint / proche / atteint ».
- (b) Les interdire : le propriétaire tient lui-même le total.

**Recommandation : (a).** Un total tenu à la main se trompe ou s'oublie, et le seuil se franchit sans prévenir.

### T8. Quand construire le guichet OSS ? (avant M8)
**Contexte.** Le guichet OSS est le guichet européen qui permet de déclarer, depuis la France, la TVA due dans les autres pays de l'Union. Au-delà de 10 000 € par an de ventes expédiées à des particuliers de l'Union, pour toute la société, c'est la TVA du pays du client qui s'applique.
- **(a) Plus tard (après SUPER PDP), et d'ici là refuser** toute expédition qui ferait dépasser le seuil, avec un compteur et un avertissement dès 9 000 €.
- (b) Tout de suite.
- (c) Jamais : refus définitif au-delà du seuil.
- (d) Un simple avertissement, en gardant la TVA française.

**Recommandation : (a).** On ne facture jamais une TVA fausse. Conséquence à connaître : une fois 10 000 € atteints, plus aucune expédition à un particulier de l'Union jusqu'au 31 décembre, ni toute l'année suivante, tant que l'OSS n'existe pas. La vente au comptoir reste possible. Une société déjà inscrite à l'OSS serait refusée pour toute vente à distance jusque-là.

### T9. Cartes cadeaux et bons d'achat (avant M4)
**Contexte.** Une carte cadeau utilisable sur des produits à plusieurs taux n'est pas taxée à sa vente, seulement quand elle sert. PILIQI la taxerait aujourd'hui à la vente, comme un produit.
- **(a) Refuser leur vente** tant que le traitement n'est pas construit (après SUPER PDP).
- (b) Construire le traitement tout de suite.
- (c) Ne jamais vendre de cartes cadeaux dans PILIQI.

**Recommandation : (a).** Refuser vaut mieux que mal taxer, et le sort d'une carte jamais utilisée est à confirmer par votre expert-comptable. (b) reste possible si vous en avez besoin tôt.

### T10. Société en franchise de TVA : ventes et achats hors de France (avant M4)
**Contexte.** La « franchise en base » est le régime des petites entreprises qui ne facturent pas de TVA. Ses règles pour l'étranger dépendent de seuils et restent en partie incertaines.
- **(a) Ventes** : les marchandises gardent la mention de franchise ; une prestation à une entreprise étrangère suit les règles du pays du client. **Achats** : achats de marchandises dans l'Union et importations refusés pour l'instant ; services étrangers calculés (TVA due, non déductible).
- (b) Tout garder en « vente en France » et refuser tous les achats à l'étranger.
- (c) Tout traiter comme une société qui facture la TVA.

**Recommandation : (a).** La règle est claire pour les services, incertaine pour les marchandises. À faire confirmer par votre expert-comptable.

### T11. Achats que le logiciel ne sait pas calculer seul (avant M5)
**Contexte.** Quelques cas rares : sous-traitance du bâtiment, hôtel ou salon à l'étranger, achat « triangulaire » entre trois pays, alcools et tabacs, achat d'un magasin des DOM venu d'ailleurs. Il faut pourtant pouvoir faire entrer ces marchandises en stock.
- **(a) Saisie à la main** de la TVA, avec une **autorisation dédiée** donnée à personne au départ (le propriétaire l'a toujours), un motif obligatoire et une trace au journal d'audit.
- (b) La même saisie, ouverte à ceux qui valident les achats (les responsables).
- (c) Blocage pur : la facture ne se valide pas.

**Recommandation : (a).** Sans saisie à la main, la marchandise n'entre jamais en stock. Comme la saisie fixe une TVA déductible, elle mérite sa propre autorisation, comme les remises.

### T12. Importation : valider avant la déclaration en douane ? (avant M5)
**Contexte.** Le transitaire envoie souvent ses papiers après la livraison. Sans eux, les droits de douane ne sont pas encore dans le coût du stock.
- **(a) Oui** : la marchandise se vend, avec un coût provisoire et une liste « Dédouanement à compléter » ; le coût se corrige à la saisie de la déclaration, sauf après un avoir fournisseur sur cette facture.
- (b) Non : pas de validation sans la déclaration.

**Recommandation : (a).** Bloquer empêcherait de vendre une marchandise déjà en rayon.

### T13. Calcul de la TVA d'un achat et de ses retours (avant M5)
**Contexte.** La TVA autoliquidée se déclare taux par taux. Calculée ligne par ligne, elle peut différer d'un centime.
- **(a) Calculée sur le total de chaque taux, puis répartie** entre les lignes, comme pour vos ventes (0,17 € + 0,66 € = 0,83 €) ; un retour ne rend jamais plus que la part de sa ligne.
- (b) Calculée ligne par ligne (0,17 € + 0,67 € = 0,84 €).
- (c) TVA facturée reprise telle quelle de la facture du fournisseur, et autoliquidée comme en (a).

**Recommandation : (a).** C'est la méthode déjà validée pour vos ventes. C'est une règle d'arrondi : elle vous revient.

### T14. Frais de transport et de douane dans le coût du stock (avant M5)
**Contexte.** Le transport, le transit, l'assurance et les droits de douane font partie de ce que coûte une marchandise. Ils peuvent être facturés par le fournisseur ou par un transporteur à part.
- **(a) Dans le coût, répartis selon la valeur** des articles, y compris les frais d'un transporteur rattachés à la facture des marchandises.
- (b) Dans le coût, répartis selon le nombre d'articles.
- (c) Seuls les frais du fournisseur et les droits de douane dans le coût ; ceux d'un transporteur en charges.

**Recommandation : (a).** C'est la règle comptable du coût d'un stock, et la répartition selon la valeur évite de charger un article bon marché du transport d'un article cher.

### T15. Un fournisseur de l'Union a facturé la TVA de son pays (avant M5)
**Contexte.** Un fournisseur de l'Union doit facturer sans sa TVA quand vous lui donnez votre n° de TVA français. S'il la facture quand même, elle n'est pas déductible en France.
- (a) Bloquer la validation jusqu'à une facture corrigée.
- **(b) Valider avec une régularisation** : la TVA étrangère est notée à part, « à faire rectifier ou rembourser », la TVA française est calculée quand même, et la facture entre dans une liste « à régulariser ».
- (c) Passer par la saisie à la main (T11).

**Recommandation : (b).** On ne bloque pas une marchandise déjà en rayon, et les montants restent justes. À faire confirmer par votre expert-comptable.

### T16. Factures d'achat dans une autre monnaie (avant M5)
**Contexte.** La TVA se déclare en euros. La date du taux de change à retenir n'est pas encore confirmée.
- **(a) Taux saisi sur la facture** (celui de la déclaration en douane pour une importation).
- (b) Taux officiel du jour, récupéré automatiquement.
- (c) Euros seulement pour l'instant.

**Recommandation : (a).** Un taux saisi, avec sa source, reste vérifiable. À faire confirmer par votre expert-comptable.

---

## 8. Huit points appliqués sauf objection

Ils corrigent une erreur ou appliquent une règle déjà décidée. Dites-le si vous n'êtes pas d'accord.

- **O1.** La valeur du stock s'affiche au coût d'achat, sans TVA ajoutée (aujourd'hui, une TVA au taux de vente s'y ajoute, ce qui ne correspond à aucune valeur comptable). Pour une société en franchise, le coût comprend la TVA qu'elle ne déduit pas.
- **O2.** Une association non assujettie à la TVA reçoit une facture comme un professionnel, mais elle n'entre pas dans la facture électronique : elle va dans la déclaration des ventes aux particuliers. Un client public se facture sur Chorus Pro, hors de PILIQI, avec un message.
- **O3.** Le n° de TVA d'un client de l'Union reste vérifié sur VIES le jour de l'émission (votre décision I2). Un avertissement s'ajoute quand la livraison a eu lieu avant, sans vérification à sa date.
- **O4.** Les articles du code général des impôts restent imprimés tant que ceux du nouveau code (CIBS) ne sont pas confirmés. D'après des sources concordantes, ils restent admis jusqu'au 30/06/2028.
- **O5.** Le nom et l'adresse du client deviennent obligatoires sur toute facture, comme la loi l'exige.
- **O6.** Un magasin des DOM ne peut pas expédier hors de son département, et une prestation à une entreprise des DOM est refusée, avec un message, jusqu'à un régime dédié (après SUPER PDP). Refuser vaut mieux que mal taxer.
- **O7.** Le régime de TVA de la société (franchise ou non) porte une date : « Depuis le ». Un achat est toujours calculé avec le régime en vigueur à sa date.
- **O8.** Une facture issue d'un devis reprend ses réponses (expédition, adresse) et son choix de régime ; tout est revérifié le jour de la facture.

## 9. À faire confirmer par votre expert-comptable

Ces points ne bloquent pas le début du travail. Chacun est vérifié avant de coder la règle qui en dépend. La liste complète (20 points) est dans l'annexe B de la conception. Les principaux :
- les lignes de la déclaration de TVA (CA3) sur la notice 2026 ;
- la date à laquelle la TVA d'un achat autoliquidé est due ;
- le traitement d'une TVA étrangère facturée par erreur (T15) ;
- les achats à l'étranger d'une société en franchise (T10) ;
- le sort d'une carte cadeau jamais utilisée (T9) ;
- la date du taux de change d'un achat en devise (T16) ;
- la TVA à reverser sur une marchandise volée ou détruite (étape 7) ;
- la conduite à tenir pour les documents émis entre la fin réelle d'une franchise et sa saisie dans PILIQI (O7).
