# Les centimes d'écart sur les factures : synthèse pour l'éditeur

Rédigée le 02/11/2026 après votre remarque sur la facture FAC-PARIS-2026-000001 (« je suis pas content avec les calculs […] moi je veux pas »). Elle reprend et contre-vérifie les trois notes de ce dossier (`normes.md`, `resultats.md`, `impact.md`). Rien n'a été modifié dans le logiciel.

## En bref

1. **Vous avez raison.** Sur votre facture, 2 × 8,33 € font bien 16,66 €, pas 16,67 €. Ce n'est pas une erreur d'affichage : c'est la règle actuelle. Le logiciel part du prix TTC (10,00 €) pour tout le monde, puis en déduit le HT. Avec cette règle, **7 à 9 factures sur 10 à un professionnel montrent au moins un centime qui ne tombe pas juste à la calculatrice.**
2. **On peut supprimer tous ces écarts sur les factures, avoirs complets et devis aux professionnels**, en les calculant depuis le prix HT (option A). Les tickets des particuliers restent calculés depuis le prix TTC affiché, comme la loi le demande pour un consommateur.
3. **Ma recommandation : l'option A, avec un PU HT imprimé à 4 décimales** (8,3333 € au lieu de 8,33 €). Votre facture devient vérifiable (2 × 8,3333 = 16,67 €). Le professionnel paie le plus souvent le même prix que le particulier : 8 fois sur 10 pour une ligne, 6 fois sur 10 pour deux lignes. Sinon, la différence est d'environ 1 centime par ligne, pour des quantités courantes.
4. **Deux centimes resteront toujours possibles, quelle que soit la méthode**, parce qu'on ne peut pas faire autrement en mathématiques : sur certains avoirs partiels (quand on rend une partie d'une facture) et sur le récapitulatif de TVA de certains tickets. La section 4 explique pourquoi et ce que vous devez choisir.
5. **Vis-à-vis de l'État**, rien ne change : dans toutes les options, PILIQI déclare exactement la TVA imprimée sur les documents. La méthode HT suit même le texte de l'administration à la lettre : « appliquer les taux légaux de la taxe au prix hors taxe » (BOFiP, voir section 2).

---

## 1. Pourquoi un prix rond comme 10,00 € ne donne pas un HT juste

- 10,00 € TTC à 20 % de TVA, c'est 10,00 ÷ 1,20 = **8,3333… €** HT. Ce nombre ne tombe jamais sur un centime, et une facture n'imprime que des centimes. Il faut donc arrondir quelque part.
- **L'endroit où l'on arrondit décide de l'endroit où le centime se voit :**
  - si on arrondit le prix unitaire à 8,33 € et qu'on calcule depuis lui, la facture tombe juste (2 × 8,33 = 16,66 €), mais 2 coques coûtent 19,99 € au lieu de 20,00 € ;
  - si on garde 20,00 € TTC (règle actuelle), le HT vaut 16,67 €, et ce n'est pas 2 × 8,33.
- **Preuve sur vos 2 coques.** Pour qu'une facture se vérifie, il faut HT = 2 × un prix au centime, et TVA = HT × 20 % arrondie. Avec 8,33 € : 16,66 + 3,33 = **19,99 €**. Avec 8,34 € : 16,68 + 3,34 = **20,02 €**. Aucun prix au centime ne donne 20,00 €.
- **C'est général.** À 20 %, **1 montant TTC sur 6** (0,03 €, 0,09 €, 9,99 €, 10,05 €…) ne peut s'écrire avec aucun HT « HT + TVA arrondie », même avec un prix unitaire très précis. Exemple : 9,99 € donne soit 8,32 + 1,66 = 9,98 €, soit 8,33 + 1,67 = 10,00 €. J'ai vérifié par programme tous les montants de 0,01 € à 2 000,00 € : 16,67 % à 20 %, 9,09 % à 10 %, 5,21 % à 5,5 %, 7,83 % à 8,5 %, 2,06 % à 2,1 %, 11,50 % à 13 %.
- **Conclusion.** « Aucun centime d'écart sur la facture » et « exactement le prix de l'étiquette pour tout client » ne peuvent pas être vrais en même temps. Il faut choisir lequel des deux on sacrifie, et pour quels clients.

---

## 2. Ce que disent vraiment les textes (sources rouvertes le 02/11/2026)

| Source | Ce qu'elle dit | Conséquence |
|---|---|---|
| CGI, annexe II, art. 242 nonies A (Légifrance, en vigueur au 02/11/2026) | Par ligne : la quantité, la dénomination, le **prix unitaire hors taxes** et le taux. Par taux : le total HT et la TVA. **Aucune règle d'arrondi, aucun nombre de décimales.** | Un PU HT à 4 décimales est permis. Le total HT par ligne n'est pas une mention obligatoire. |
| Code de commerce, art. L441-9 | Mêmes mentions : quantité, dénomination, prix unitaire hors TVA, réductions. Aucune règle d'arrondi. | Idem. |
| BOFiP BOI-TVA-DECLA-30-20-20-10, § 330 | « Pour calculer le montant de la TVA, il convient d'appliquer les taux légaux de la taxe au prix « hors taxe » ». Le mot « arrondi » n'apparaît pas. | La méthode HT suit ce texte à la lettre. La règle actuelle (TVA = TTC − HT) s'en écarte d'un centime une fois sur six à 20 %. |
| BOFiP caisse BOI-TVA-DECLA-30-10-30, § 50 | Le logiciel de caisse doit **enregistrer** pour chaque ligne le libellé, la quantité, le prix unitaire, le total HT et le taux. Rien sur l'arrondi ni sur l'impression. | Un ticket n'a pas à imprimer le HT par ligne. |
| Arrêté du 3 décembre 1987 (prix au consommateur) | Le consommateur paie le prix TTC affiché. | Les tickets restent calculés depuis le TTC. Cet arrêté ne vise pas les professionnels. |
| Norme européenne de facture électronique EN 16931 (fichiers de contrôle officiels v1.3.17, re-téléchargés : empreintes identiques) | Contrôles exacts : la somme des lignes doit égaler la base, et HT + TVA doit égaler le TTC. La règle « TVA = base × taux arrondie » (BR-CO-17) tolère jusqu'à 1,00 € d'écart. **Aucune règle ne compare le total de ligne à la quantité × le prix.** | La règle actuelle passe les contrôles grâce aux tolérances. La méthode HT les passe sans aucune tolérance. |
| Peppol BIS Billing 3.0.21 (règle R120) | Total de ligne = quantité × prix, à 0,02 € près. | Respectée par la méthode HT (écart d'au plus 1 centime à 4 décimales, nul au centime). Ne s'appliquerait de toute façon pas au format Factur-X que PILIQI enverra (voir section 9.1). |
| Règles françaises de la facture électronique (FNFE v1.3.1) | Prix unitaire : 6 décimales au plus (simple avertissement). Aucune règle de cohérence entre quantité, prix et total. | 4 décimales permises. |
| Révision EN 16931-1:2026 (connue seulement par des sources tierces) | Prix unitaire limité à 4 décimales en euros, règle BR-CO-17 retirée. | 4 décimales restent permises ; 6 décimales ne le seraient plus. **À vérifier sur le texte officiel.** |
| Pratique des logiciels | EBP : « Mode de facturation (HT ou TTC) » au choix par document. Odoo conseille d'enregistrer le prix HT et montre le même phénomène (10,00 € pour un particulier, 9,99 € pour un professionnel). | La facture HT aux professionnels est la pratique courante. |

---

## 3. Les options

Vocabulaire : un **écart visible** est un calcul que vous refaites à la calculatrice sur le PDF et qui ne retombe pas sur le montant imprimé.

### Option A : méthode HT pour les professionnels, TTC pour les particuliers

**Principe.** Les factures, avoirs sur facture et devis à un professionnel se calculent depuis le prix HT :
- le PU HT est le prix TTC de la fiche ÷ (1 + taux), arrondi une seule fois ;
- le total HT de la ligne est la quantité × le PU HT, remise déduite, arrondi au centime ;
- la TVA est la base × le taux, arrondie au centime ;
- le TTC est la base + la TVA.

Les tickets et les ventes aux particuliers ne changent pas.

Le PU HT peut être arrondi à deux précisions différentes. C'est un choix à faire (question 2).

| | A1 : PU HT au centime (8,33 €) | A2 : PU HT à 4 décimales (8,3333 €), **recommandée** |
|---|---|---|
| Écarts visibles sur une facture ou un devis à un professionnel | **Aucun** (vérifié sur 12,5 millions de documents énumérés sans remise et 60 000 tirés au hasard avec remises) | **Aucun** (vérifié sur 60 000 documents tirés au hasard avec remises) |
| Le professionnel paie le prix de l'étiquette (une ligne, 1 à 100 articles) | 18 % des cas à 20 % (5 à 11 % aux autres taux) | **78 % des cas à 20 %** (82 à 86 % aux autres taux) |
| Le professionnel paie le prix de l'étiquette (deux lignes de 1 à 3 articles, 20 %) | 35 % des cas, écarts de −2 à +4 centimes | **63 % des cas**, écarts de −1 à +1 centime |
| Écart de prix, sinon | Grandit avec la quantité : de −0,40 € à +0,60 € pour 100 articles, jusqu'à 60 € pour 10 000 articles d'une ligne | **1 centime** jusqu'à 100 articles par ligne ; 4 centimes pour 1 000 ; 40 centimes au plus pour 10 000 |
| Documents chargés tirés au hasard (jusqu'à 9 lignes et 10 000 articles par ligne) | Même prix dans 4 % des cas, écarts de −122 € à +106 € | Même prix dans 33 % des cas, écarts de −1,20 € à +0,98 € |
| Articles à quelques centimes (1 000 vis à 0,03 € TTC = 30,00 €) | 36,00 € (+20 %) ; à 0,05 € : 48,00 € au lieu de 50,00 € (−4 %) | 30,00 € ; à 0,05 € : 50,04 € |
| Lisibilité du prix unitaire | Très bonne (8,33 €) | Bonne (8,3333 €, courant entre professionnels ; 25,00 € reste 25,00 €) |
| Avoir partiel (une partie de la facture rendue) | Sans remise, le HT tombe toujours juste ; la TVA peut s'écarter d'1 centime, et le HT aussi avec une remise (voir section 4) | Le HT ou la TVA peut s'écarter d'1 centime (voir section 4) |

**Ce qui devient exact** (dans les deux variantes), sur toute facture, tout devis et tout avoir complet à un professionnel :
- la quantité × le PU HT, remise déduite, donne le total HT de la ligne ;
- la somme des lignes donne la base ;
- la base × le taux donne la TVA ;
- la base + la TVA donne le TTC ;
- le TTC ÷ (1 + taux) donne la base.

**Ce qui peut encore différer :**
- le prix payé par un professionnel et celui d'un particulier pour le même panier : voir le tableau ;
- la TVA ou le HT d'un **avoir partiel**, d'1 centime : c'est inévitable (section 4) ;
- sur les **tickets**, le récapitulatif de TVA, comme aujourd'hui : 1 cas sur 6 à 20 % (section 4).

**Conformité.**
- Elle respecte les textes français : PU HT sans limite de décimales, TVA calculée sur le HT (BOFiP § 330).
- Elle respecte toutes les règles de la facture électronique sans avoir besoin d'aucune tolérance.
- Elle reste dans la limite de 4 décimales annoncée pour la révision de 2026.

**Effort.**
- Le calcul existe déjà dans PILIQI pour les achats.
- Il faut l'appliquer aux ventes aux professionnels et changer la colonne du PU HT dans 3 tables.
- Il faut aussi adapter le PDF, les fiches à l'écran, la caisse (client professionnel), les prix négociés saisis en HT et le contenu signé de la caisse.
- Une trentaine de fichiers et beaucoup de tests sont à réécrire (liste dans `impact.md`, complétée en section 9.3).
- C'est un lot de travail comparable à un lot de décisions ordinaire.

**Risques.**
- Un professionnel peut payer 1 centime de plus ou de moins qu'un particulier pour le même panier. Cela revient sur votre décision de la série 8 (« un même panier a les mêmes montants pour tout client ») : avec A2, de très peu ; avec A1, nettement.
- Si un vendeur change le client d'un panier en cours de vente, le total peut changer d'un centime. La caisse doit le dire.

### Option B : garder le TTC pour tous, et imprimer le PU HT avec ses 6 décimales

**Principe.** Le calcul ne change pas. Seul l'affichage change : le PDF imprime 8,333333 € au lieu de 8,33 €.

**Ce qui devient exact.** Votre facture : 2 × 8,333333 = 16,666666, qui s'arrondit à 16,67 €. C'est aussi le cas de la plupart des factures d'une seule ligne.

**Ce qui peut encore différer.** Encore 7 % à 51 % des factures montrent un écart (64 % dans mes tirages au hasard, qui ont plus de lignes). Deux causes :
- **deux lignes identiques** reçoivent 20,83 € et 20,84 € HT, alors que la calculatrice donne 20,83 € pour les deux ;
- **la TVA** vaut 1 centime de moins que la base × le taux, une fois sur six à 20 % (9,99 € : base 8,33 €, TVA 1,66 €, alors que 8,33 × 20 % = 1,67 €).

**Conformité.**
- Elle est permise aujourd'hui (6 décimales au plus en France).
- La révision de 2026 de la norme limiterait le prix à 4 décimales : le PDF et le fichier électronique ne montreraient alors plus le même prix.
- La TVA reste éloignée du BOFiP § 330 une fois sur six.

**Effort.** Faible (affichage, note de calcul, quelques tests).

**Risque.** Elle ne répond pas à votre demande, et un prix à 6 décimales est peu lisible.

### Option C : garder la règle actuelle

- **Ce qui est exact :** le TTC payé est toujours le prix de l'étiquette, pour tout client.
- **Ce qui diffère :** 70 % à 93 % des factures à un professionnel montrent au moins un écart (97 % dans mes tirages au hasard).
- **Conformité :** les contrôles officiels passent grâce à leurs tolérances, à condition d'envoyer à la plateforme le prix précis et non le prix arrondi.
- **Effort :** aucun.
- **Risque :** c'est exactement ce que vous refusez.

### Options écartées

- **Calculer aussi les tickets depuis le HT.** Le particulier ne paierait plus le prix affiché, ce que l'arrêté de 1987 interdit.
- **Ajouter un « montant d'arrondi » pour retomber sur le prix de l'étiquette.** La norme prévoit ce montant (BT-114) pour arrondir la somme à payer. Ici, le client paierait un centime sans TVA, ce qui est fiscalement douteux.
- **Enregistrer le prix HT comme prix de référence de la fiche, à la place du prix TTC.** Ce n'est pas un usage de commerce de détail, et l'écart dû à la quantité reste le même qu'en A1.

---

## 4. Les deux centimes qu'aucune méthode ne supprime

### 4.1 Les avoirs partiels

Vous vendez 2 coques à un professionnel, puis il les rend une par une, sur deux avoirs.
- Avec la méthode HT (A1), la facture fait 16,66 € HT + 3,33 € de TVA = 19,99 €. La TVA d'une coque seule, à la calculatrice, vaut 8,33 × 20 % = 1,666, soit **1,67 €**. Or deux fois 1,67 € font 3,34 €, alors que la facture n'a compté que 3,33 € de TVA.
- Avec la variante recommandée A2 (PU 8,3333 €), la facture fait 16,67 € + 3,33 € = 20,00 €. Option A : avoir 1 = 8,33 € + 1,67 €, avoir 2 = 8,34 € + 1,66 €. La facture est soldée, mais le 2e avoir s'écarte d'1 centime sur le HT et sur la TVA. Option B : deux fois 8,33 € + 1,67 €, ce qui rend 3,34 € de TVA pour 3,33 € facturés.
- Il faut donc choisir (exemple en A1) :

| | Avoir 1 | Avoir 2 | Ce qui se passe |
|---|---|---|---|
| **A : somme exacte** (règle actuelle des retours, et celle des achats), **recommandée** | 8,33 € + 1,67 € | 8,33 € + **1,66 €** | La facture est soldée au centime près. Le 2e avoir montre 1 centime d'écart (8,33 × 20 % = 1,67 €, et non 1,66 €). Sa note de calcul l'explique. |
| **B : chaque avoir juste** | 8,33 € + 1,67 € | 8,33 € + 1,67 € | Chaque avoir se vérifie. Mais 20,00 € sont crédités pour une facture de 19,99 € : le client garde 1 centime de crédit. Dans l'autre sens, une facture peut rester due d'un centime, passer en retard et être relancée. |

Fréquence (lignes rendues article par article, mes calculs) :
- avec l'option A, **1 à 2 avoirs partiels sur 5** montrent 1 centime d'écart, jamais plus : 26 % en A1 et 31 % en A2 sans remise, 40 % avec une remise de 10 ou 15 % ; le premier avoir qui rend une partie d'une ligne est toujours juste ;
- avec l'option B, la facture n'est **pas soldée au centime** dans **77 %** des lignes en A1 et **17 %** en A2.

Le retour de toute une facture dans un seul avoir est toujours exact, dans les deux options.

**Je recommande l'option A** pour trois raisons :
- elle ne laisse jamais un centime dû ou crédité ;
- elle ne rend jamais plus de TVA qu'il n'en a été facturé ;
- c'est déjà la règle des retours d'achats.

Elle est à faire confirmer par l'expert-comptable de la société.

### 4.2 Le récapitulatif de TVA des tickets

Un ticket est payé au prix affiché, par exemple 9,99 €. Le logiciel imprime la base HT (8,33 €) et la TVA (1,66 €). À la calculatrice, 8,33 × 20 % = 1,67 €.
- La section 1 l'a montré : aucun HT au centime ne donne 9,99 € avec une TVA juste. C'est vrai pour 1 total sur 6 à 20 %.
- Pour l'éviter, il faudrait faire payer au particulier un autre prix que le prix affiché, ce que la loi interdit.
- Aucun texte trouvé n'oblige à imprimer le HT ligne par ligne sur un ticket. En revanche, une note de prestation de services doit indiquer le total HT et le total TTC (arrêté n° 83-50/A, d'après des réponses ministérielles ; texte non relu sur Légifrance).
- **Je recommande d'accepter ce centime sur les tickets**, comme aujourd'hui.

Petit défaut à corriger au passage : aujourd'hui, une remise en % sur un ticket peut déjà montrer un écart. Par exemple, 9,95 € remisé de 10 % : le ticket imprime 8,95 €, la calculatrice donne 8,96 €. Cela vient de l'arrondi de la remise seule. Arrondir une seule fois le montant net de la ligne le supprime : vérifié, 0 écart sur 60 000 documents (question 4).

---

## 5. Votre facture, recalculée dans chaque option

Facture FAC-PARIS-2026-000001 : 2 coques à 10,00 € TTC et 1 remplacement d'écran à 30,00 € TTC, TVA 20 %, à un professionnel.

| Ce que montre le PDF | C : actuelle | B : PU à 6 décimales | **A2 : HT, PU à 4 décimales** | A1 : HT, PU au centime |
|---|---|---|---|---|
| Coque | 2 × 8,33 = **16,67** ✗ (calculatrice : 16,66) | 2 × 8,333333 = 16,67 ✓ | 2 × 8,3333 = 16,67 ✓ | 2 × 8,33 = 16,66 ✓ |
| Remplacement écran | 1 × 25,00 = 25,00 ✓ | 1 × 25,00 = 25,00 ✓ | 1 × 25,00 = 25,00 ✓ | 1 × 25,00 = 25,00 ✓ |
| Base 20 % | 41,67 | 41,67 | 41,67 | 41,66 |
| TVA 20 % | 8,33 ✓ (41,67 × 20 % = 8,334) | 8,33 ✓ | 8,33 ✓ | 8,33 ✓ (41,66 × 20 % = 8,332) |
| TTC payé | 50,00 € | 50,00 € | **50,00 €** | 49,99 € |
| Particulier, même panier | 50,00 € | 50,00 € | 50,00 € | 50,00 € |

Trois autres paniers, pour voir où les options se séparent :

| Panier (TTC de l'étiquette) | C : actuelle | B : 6 décimales | A2 : 4 décimales | A1 : centime |
|---|---|---|---|---|
| 2 lignes de 1 × 25,00 € (particulier : 50,00 €) | lignes 20,83 et 20,84 avec un PU de 20,83 ✗ | PU 20,833333 : la 2e ligne à 20,84 ✗ | 20,83 + 20,83 = 41,66, plus 8,33 de TVA = **49,99 €** ✓ | 49,99 € ✓ |
| 1 × 9,99 € (particulier : 9,99 €) | base 8,33, TVA 1,66 ✗ (8,33 × 20 % = 1,67) | même écart ✗ | PU 8,325 ; ligne 8,33, plus 1,67 de TVA = **10,00 €** ✓ | 10,00 € ✓ |
| 4 × 65,00 € (particulier : 260,00 €) | 4 × 54,17 = 216,68, imprimé 216,67 ✗ | 4 × 54,166667 = 216,67 ✓ | 4 × 54,1667 = 216,67, plus 43,33 de TVA = **260,00 €** ✓ | 4 × 54,17 = 216,68, plus 43,34 de TVA = 260,02 € ✓ |

---

## 6. Recommandation

**L'option A2 : méthode HT, PU HT à 4 décimales, pour les factures, avoirs sur facture et devis aux professionnels. Méthode TTC inchangée pour les tickets et les particuliers. Remise arrondie une seule fois sur le montant net de la ligne. Avoirs partiels avec somme exacte (option A).**

Pourquoi :
1. Comme A1, elle supprime **tous** les écarts visibles sur les documents que vous vérifiez, ceux des professionnels. Je l'ai vérifié par un programme écrit indépendamment des deux notes précédentes : 0 écart sur 60 000 documents tirés au hasard (jusqu'à 9 lignes, 3 taux, 10 000 articles par ligne, remises quelconques). Ce résultat confirme celui des 12,5 millions de documents énumérés pour A1.
2. Elle garde presque intacte votre décision de la série 8 : le professionnel paie le prix de l'étiquette 8 fois sur 10 pour une ligne (6 fois sur 10 pour deux lignes), et sinon environ 1 centime de plus ou de moins par ligne pour une quantité normale. Avec A1, il paierait souvent plusieurs centimes de différence, et jusqu'à +20 % sur les articles à quelques centimes.
3. Elle suit à la lettre le BOFiP (TVA = taux × HT) et toutes les règles de la facture électronique, y compris la limite de 4 décimales annoncée pour 2026.
4. Les clôtures, les déclarations des ventes aux particuliers, les achats, les grands totaux et le chiffre d'affaires ne changent pas : ils additionnent les montants enregistrés sans jamais les recalculer.
5. La TVA déclarée reste exactement celle des documents.

---

## 7. Projet de texte pour CLAUDE.md (option recommandée)

Ce texte suppose les réponses recommandées aux questions de la section 8. Une autre réponse change la phrase correspondante : par exemple, avec un PU HT au centime, « arrondi une fois au centime » remplace « au dix-millième ».

### Puce « Prix et TVA des ventes » (remplace la puce entière)

- Prix et TVA des ventes : seul le prix de vente TTC d'un produit est un prix arrondi, une seule fois, quand la facture d'achat ou l'inventaire d'ouverture enregistre le produit ; le prix de référence d'un service est un prix TTC saisi au centime. Le taux de TVA se saisit librement, 20 % par défaut (8,5 % pour un magasin dont le territoire est la Guadeloupe, la Martinique ou La Réunion) ; une ligne de produit prend le taux de sa fiche. Accorder une remise et fixer le prix d'un service (son prix de référence au catalogue comme celui d'une ligne) et le prix d'une ligne de produit différent de sa fiche exigent l'autorisation « accorder des remises et fixer le prix d'un service », que les responsables du magasin et le propriétaire ont toujours (le propriétaire, qui ne vend jamais, pour le prix de référence d'un service et les lignes d'un devis), précochée pour les administrateurs, pour qui elle ne vaut sur une ligne de ticket ou de facture que si le propriétaire leur a aussi coché la vente, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et qu'un cabinet lié n'a jamais ; changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ; sans elle, une ligne de produit prend le prix de sa fiche, une ligne de service le prix de référence de sa fiche, et un service sans prix de référence ne se vend pas. Toute vente se calcule une seule fois, à son enregistrement, par l'une de deux méthodes, que le document garde et qui se déduit de son type de client, de sa nature d'opération et de la franchise en base, déjà figés : la méthode TTC pour un ticket, un avoir sur ticket et un devis à un particulier vendus avec la TVA française ; la méthode HT pour toute facture, tout avoir sur facture et tout devis à un professionnel, et pour toute vente sans TVA française, même à un particulier. Dans les deux méthodes, la remise d'une ligne est un pourcentage, et le net de la ligne, quantité × prix unitaire × (1 − remise), s'arrondit une seule fois au centime le plus proche, un demi-centime au centime supérieur. Méthode TTC : le TTC de chaque ligne est ce net calculé sur le PU TTC, et le TTC payé est la somme des lignes ; pour chaque taux, le HT est le TTC de ses lignes ÷ (1 + taux), arrondi une fois au centime le plus proche, et se répartit sur ses lignes par cumul, dans leur ordre ; la TVA est TTC − HT ; le document imprime par ligne le PU TTC et le total TTC, que la calculatrice retrouve toujours, et par taux une base qui est toujours le TTC ÷ (1 + taux) arrondi, mais dont le produit par le taux peut s'écarter d'un centime de la TVA imprimée (une fois sur six à 20 %), ce qu'aucun calcul qui fait payer exactement le prix TTC affiché n'évite. Méthode HT : le PU HT de chaque ligne est celui de la fiche ou du prix de référence, PU TTC ÷ (1 + taux), arrondi une fois au dix-millième d'euro le plus proche, un demi-dix-millième au dix-millième supérieur (10,00 € TTC à 20 % : 8,3333 € ; 9,99 € : 8,325 €), ou le PU HT saisi, à quatre décimales au plus, pour un prix dérogatoire ; le HT de chaque ligne est le net calculé sur ce PU HT ; la TVA de chaque taux est le total HT du taux × taux, arrondie une fois au centime et répartie sur ses lignes par cumul, dans leur ordre ; le TTC est HT + TVA ; sans TVA française, le client paie le HT, sans TVA. Tout ce qu'imprime un document en HT se vérifie donc à la calculatrice : la quantité × le PU HT, remise déduite, arrondie au centime, est le total HT de la ligne ; la somme des lignes, la base ; la base × le taux, arrondie, la TVA ; la base + la TVA, le TTC ; et le TTC ÷ (1 + taux), arrondi, la base. Un professionnel paie ainsi le plus souvent le prix que paierait un particulier pour le même panier, mais un centime de plus ou de moins quand aucun HT ne redonne exactement ce TTC (une fois sur six à 20 % : un article à 9,99 € TTC lui fait 8,33 € + 1,67 € = 10,00 €) ou selon le découpage des lignes (50,00 € TTC en une ligne : 41,67 € + 8,33 € ; en deux lignes de 25,00 € : 20,83 € + 20,83 € + 8,33 € = 49,99 €) ; le PU HT au dix-millième ajoute au plus 0,006 centime par article (0,40 € au plus pour 10 000 articles d'une même ligne à 20 %). Un brouillon de facture ou un devis dont le client change de type se recalcule par la méthode de son nouveau type à son nouvel enregistrement, et l'écran dit que ses montants peuvent changer ; un avoir prend la méthode des documents qu'il corrige et n'en mélange jamais deux. Un retour se calcule par la méthode de la vente : il rend, pour tous les articles d'une ligne rendus jusqu'ici, le calcul de la vente sur cette quantité (en TTC, le net de ces articles sur le PU TTC et son HT ; en HT, le net de ces articles sur le PU HT et la TVA de ce HT), sans jamais dépasser le HT ni la TVA de la ligne, moins ce que les retours précédents ont rendu ; le retour de toute la ligne rend exactement ses montants, et les avoirs d'une facture ne rendent jamais plus que ses montants ; le HT ou la TVA d'un avoir partiel peut donc s'écarter d'un centime de ce que la calculatrice refait sur cet avoir seul, ce qu'explique sa note de calcul ; cette règle est à faire confirmer par l'expert-comptable de la société. Un magasin en franchise en base vend à 0 % au même prix TTC, avec la mention « TVA non applicable, art. 293 B du CGI » : son PU HT est son PU TTC. Un achat se calcule par la méthode HT, depuis le PU HT du fournisseur, au centime : le HT de chaque ligne est quantité × PU HT, remise déduite, arrondi une fois ; la TVA de chaque taux est le total HT du taux × taux, arrondie une fois et répartie sur ses lignes par cumul, dans leur ordre ; un retour au fournisseur rend, pour tous les articles d'une ligne retournés jusqu'ici, la TVA de leur HT, sans dépasser celle de la ligne, moins ce que les retours précédents ont rendu, et le retour de toute la ligne rend exactement ses montants. Ensuite plus rien ne s'arrondit ni ne se recalcule : les documents affichent les montants tels qu'ils sont enregistrés, et une clôture, un relevé, un tableau de bord ou une déclaration additionne les montants enregistrés de ses documents, sans jamais recalculer la TVA d'un total ; cette somme peut donc s'écarter de « TTC ÷ (1 + taux) » ou de « HT × taux » d'au plus un demi-centime par document et par taux (deux tickets de 25,00 € font 41,66 € HT et 8,34 € de TVA). Le PU HT d'un document calculé en HT s'affiche avec ses quatre décimales, sans les zéros inutiles au-delà de deux (8,3333 € ; 25,00 €) ; tout autre prix unitaire montré en lecture (PU TTC, PU HT d'un document en TTC, coût moyen, prix d'achat ; documents, fiches, listes, aides, messages, caisse) s'affiche arrondi au centime le plus proche, un demi-centime au centime supérieur, seul un champ où un prix se saisit le montrant tel qu'il est enregistré, et aucun montant ne se calcule sur un prix affiché arrondi. Les montants se calculent en centimes entiers, et aucun total ne s'obtient par une somme que la base de données ferait sur une colonne décimale.

### Puce « Documents de vente » : phrase à remplacer

Remplacer « Le PU HT, enregistré avec six décimales, s'y imprime arrondi au centime, comme le PU TTC, et la note de calcul imprimée sous les lignes de toute vente hors franchise en base dit que les prix unitaires sont affichés arrondis au centime et que les totaux, calculés sans arrondi intermédiaire, peuvent différer de la quantité × le prix unitaire affiché. » par :

« Un document calculé en HT imprime le PU HT de chaque ligne avec ses quatre décimales, sans les zéros inutiles au-delà de deux, et un document calculé en TTC le PU TTC au centime. La note de calcul imprimée sous les lignes de toute vente hors franchise en base dit sa méthode : en HT, que le total HT de chaque ligne est la quantité × le PU HT, remise déduite, arrondi une fois au centime, et la TVA de chaque taux la base × le taux, arrondie une fois au centime ; en TTC, que le HT de chaque taux est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes, et la TVA la différence ; sur un avoir partiel, qu'il rend la part de la facture des articles rendus jusqu'ici, moins les avoirs précédents. La fiche d'un document à l'écran montre les colonnes de son PDF ; la TVA et le TTC n'y apparaissent que par taux. »

### Autres phrases qui suivent

- Puce « Ventes hors de France » : « se paie au prix hors taxe, calculé de la même façon, sans TVA » devient « se paie au prix hors taxe, calculé par la méthode HT, sans TVA ».
- Feuille de route, étape 1 : « politique d'arrondi unique des ventes et des achats » devient « méthode HT des ventes aux professionnels et des achats, méthode TTC des ventes aux particuliers ».

---

## 8. Questions à trancher (réponses fermées)

1. **Calcul des factures, avoirs sur facture et devis aux professionnels depuis le prix HT** : le professionnel paie HT + TVA, parfois 1 centime de plus ou de moins que l'étiquette. **Oui** (recommandé) / non (on garde le TTC pour tous, et les écarts restent).
2. **Précision du PU HT de ces documents** : **4 décimales** (8,3333 € ; recommandé : le plus souvent le prix de l'étiquette, sinon environ 1 centime par ligne) / au centime (8,33 € ; prix différent dans plus de 8 cas sur 10, de plusieurs centimes en quantité, et jusqu'à ±20 % sur les articles à quelques centimes).
3. **Ventes sans TVA française à un particulier** (export, livraison vers un DOM), dont le ticket imprime déjà le PU HT : même méthode HT. **Oui** (recommandé) / non.
4. **Remise en % sur les ventes** (tickets compris) : arrondir une seule fois le montant net de la ligne au lieu de la remise seule. Cela supprime l'écart actuel (9,95 € − 10 % : 8,96 € au lieu de 8,95 €). **Oui** (recommandé) / non.
5. **Même règle de remise pour les achats** : oui / **non, attendre l'étape 2** (recommandé : les factures reçues par SUPER PDP diront comment les fournisseurs calculent, et leur facture fait foi).
6. **Avoirs partiels** : **A, somme exacte** (recommandé : la facture est toujours soldée, 1 à 2 avoirs partiels sur 5 peuvent montrer 1 centime d'écart) / B, chaque avoir juste (la facture peut rester due ou créditrice d'un centime). À faire confirmer par l'expert-comptable de la société.
7. **Facture rectificative après la correction d'un SIREN** : même règle que les avoirs partiels (question 6). **Oui** (recommandé) / non. Cette question complète le constat 3 de la relecture A7b.
8. **Tickets** : accepter qu'à 20 %, une fois sur six, la base × le taux s'écarte d'1 centime de la TVA imprimée, ce qui est inévitable tant que le particulier paie exactement le prix affiché. **Oui** (recommandé) / non.
9. **Fiche à l'écran d'un document calculé en HT** : n'afficher la TVA et le TTC que par taux, comme le PDF, et non ligne par ligne. Ligne par ligne, la TVA répartie ne tombe pas juste dans un document de plusieurs lignes sur deux. **Oui** (recommandé) / non.

---

## 9. Vérification (contre-vérification adversariale, point par point)

Fichiers de cette vérification, dans `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/verif/` :
- `controle_independant.py` et sa sortie `resultats_controle_independant.md` ;
- `precision_du_pu_ht.py` et sa sortie `resultats_precision_du_pu_ht.md` ;
- `avoirs_union.py` et sa sortie `resultats_avoirs_union.md` ;
- `controle_normes_relance.md`, la relance du script des normes ;
- `resultats.md` / `resultats.json`, la relance du script exhaustif ;
- `dl/`, les sources re-téléchargées.

### 9.1 Les sources de `normes.md`

| Point | Verdict | Détail |
|---|---|---|
| CGI ann. II art. 242 nonies A, I-8°, 9°, 11° | **Confirmé** | Relu sur Légifrance par WebFetch (curl reçoit un refus 403) : citations exactes, en vigueur depuis le 01/01/2025, aucune règle d'arrondi ni de décimales. |
| Code de commerce L441-9 | **Confirmé** | Même méthode. |
| BOFiP BOI-TVA-DECLA-30-20-20-10, § 150, 220, 260, 320, 330 | **Confirmé** | Page re-téléchargée : citations exactes ; le mot « arrondi » est absent. |
| BOFiP caisse, § 50 | **Confirmé** | Données de ligne à enregistrer (dont le total HT de la ligne) ; aucune règle d'arrondi. |
| CJUE C-302/07 Wetherspoon | **Non revérifié** | EUR-Lex et curia ne sont pas lisibles d'ici. Le résumé donné correspond à la jurisprudence connue : l'arrondi relève de chaque État. |
| EN 16931 v1.3.17 : BR-CO-17 et BR-S-09 tolèrent ± 1 (bornes strictes en UBL, incluses en CII) | **Confirmé** | Fichiers officiels re-téléchargés depuis GitHub : SHA-256 identiques à ceux de `normes.md`. |
| BR-S-08 : exacte en CII, ± 1 en UBL ; BR-CO-10, BR-CO-13 à 15 exactes | **Confirmé** | Même source. |
| Aucune règle « total de ligne = quantité × prix » dans l'EN 16931 | **Confirmé**, et étendu | Elle est absente aussi des fichiers de contrôle Factur-X 1.08 EN16931, EXTENDED-CTC-FR et BR-FR (paquet FNFE v1.3.1). |
| Peppol R120 : 0,02 €, bornes incluses | **Confirmé** | Fichier Peppol 3.0.21 re-téléchargé, SHA-256 identique. Les remises de ligne sont additionnées puis arrondies avant la comparaison. |
| « R120 vaut pour les profils français » | **À nuancer** | Le fichier Peppol accepte bien les identifiants `urn:peppol:france:billing:regulated` et `non-regulated`. Mais la règle française BR-FR-08 demande dans ce même champ (BT-23) un code B1, S1, M1…, avec lequel le contrôle Peppol échouerait d'emblée (R007). Il n'est donc pas établi que les factures françaises passent par les règles Peppol BIS. PILIQI enverra du Factur-X (CII), qui n'est pas soumis à R120. Ce point est sans effet sur l'option A, qui respecte R120 sans tolérance. |
| SUPER PDP valide avec `PEPPOL-EN16931-UBL.xsl` | **Exemple seulement** | Le fichier OpenAPI 1.38.0.beta, re-téléchargé (SHA-256 identique), ne donne ce nom que comme exemple du champ « validator ». Ses validateurs réels restent à vérifier dans le bac à sable. |
| BR-FR-DEC-03 : 6 décimales au plus pour le prix (avertissement) | **Confirmé** | |
| DGFiP annexe 7, G1.53 et G1.55 : tolérance de 1 centime | **Confirmé**, et précisé | G1.53 ne s'applique qu'à l'e-reporting : la colonne e-invoicing a été décochée (« G1.53 : Suppression de la coche pour le e-invoicing »). |
| Odoo : prix HT conseillé, 10,00 € contre 9,99 € | **Confirmé** | La page Odoo contient elle-même une incohérence (TVA de 1,73 € ou 1,74 €). |
| Arrêté du 3 décembre 1987, art. 1er | **Confirmé** | Relu sur Légifrance par WebFetch : « la somme totale toutes taxes comprises qui devra être effectivement payée par le consommateur », en vigueur depuis le 01/01/2002. |
| EBP « Mode de facturation (HT ou TTC) » | **Non revérifié** | Fiche commerciale ; elle ne change pas la conclusion. |
| Révision EN 16931-1:2026 | **Toujours non officielle** | Plusieurs sources tierces concordent : prix à 4 décimales en euros, BR-CO-17 retirée. Le texte du CEN n'a pas été lu. Ce point pèse contre l'option B (6 décimales) et en faveur de la variante A2 (4 décimales). |
| 1 TTC sur 6 inatteignable à 20 % | **Confirmé** | Recalculé : 33 333 TTC sur 200 000 (de 0,01 € à 2 000,00 €), mêmes chiffres pour 10 %, 5,5 %, 8,5 %, 2,1 % et 13 %. |
| Seuils R120 avec un prix arrondi au centime (8 coques à 10,00 €) | **Confirmé** | Vérifié à la main : 66,67 € contre 8 × 8,33 = 66,64 €, écart de 0,03 € (7 coques : 0,02 €, donc accepté). |

### 9.2 Les calculs de `resultats.md`

| Point | Verdict | Détail |
|---|---|---|
| Reproductibilité | **Confirmée** | Les deux scripts ont été relancés avec `python3 -I`. Les sorties sont identiques, à la durée près (`resultats.json` : seule la clé `duree_s` diffère). |
| Règle actuelle : 70 % à 93 % des factures à un professionnel avec un écart | **Confirmée** | Programme indépendant (fractions exactes, autre code, tirages au hasard à graine fixe) : 97 % sur 60 000 documents plus chargés (jusqu'à 9 lignes et 10 000 articles). |
| PU HT à 6 décimales (ii) : 7 % à 51 % | **Confirmée** | 64 % dans mes tirages. Cette option ne suffit pas. |
| Méthode HT : 0 écart si le net de la ligne est arrondi une fois | **Confirmée** | 0 sur 60 000 documents, avec 9 taux (dont ceux de la Corse et des DOM), des remises de 0,01 % à 99,99 %, des quantités jusqu'à 10 000 et des montants de 0,01 €. Avec la remise arrondie seule (code actuel), 16 % des documents montrent un écart. Contrôle à rebours (TTC ÷ (1 + taux) = base) : 0 écart, ce que confirme la démonstration de `resultats.md`. Même résultat, 0 écart, pour la variante à 4 décimales (A2). |
| Exemples (9,99 € → 10,00 € ; 4 × 65,00 € → 260,02 € ; 2 × 25,00 € → 49,99 € ; 9,95 € − 10 % → 8,95 € contre 8,96 € ; 10,05 € → TVA 1,67 € au lieu de 1,68 €) | **Confirmés** | |
| Écart de prix en méthode HT à 2 décimales : de −0,40 € à +0,60 € pour 100 articles à 20 % | **Confirmé**, mais présenté de façon trop rassurante | Le +0,60 € vient des articles à 0,03 € (3,00 € → 3,60 €), soit +20 %. À 1 000 articles : de −4,00 € à +6,00 €. À 10 000 articles : de −40 € à +60 €. Ce constat a conduit à proposer la variante à 4 décimales (A2), absente des deux notes : de −0,01 € à +0,01 € jusqu'à 100 articles, et le prix de l'étiquette dans 78 % des cas à 20 %. |
| Avoirs, option A : environ 25 % d'avoirs partiels avec un écart de TVA | **Confirmé** | 26,5 % dans mon domaine. En comptant aussi les écarts de HT, la part monte à 40 % avec une remise. L'écart vaut toujours 1 centime au plus (`resultats_avoirs_union.md`). |

### 9.3 L'impact sur le code (`impact.md`)

| Point | Verdict | Détail |
|---|---|---|
| Références de lignes (CalculateurLigne, ConstructeurLigneDocument 25-31 et 121-133, ContexteDeVente 141-142, Service 120-128, PolitiqueDArrondiTest 193, 254, 303, 326, migrations) | **Confirmées** | Contrôlées par sondage sur le commit `81de7e8`. D'autres fichiers sont en cours de modification par l'autre chaîne. |
| Calcul de la méthode HT déjà présent (`chiffrerUnAchat`, `retourDAchat`) | **Confirmé** | |
| Remise : `montantNetCentimes()` arrondit la remise puis la déduit | **Confirmé** | Ce code est partagé par les ventes et les achats. |
| Option A des avoirs : « le HT d'une ligne d'avoir se vérifie toujours » | **Faux avec une remise** | Avec 10 % ou 15 % de remise, 24 % des avoirs d'un article montrent un écart de HT. Avec un PU à 4 décimales, c'est aussi le cas sans remise (25 %). |
| Option B des avoirs : « le HT rendu retombe toujours exactement sur la ligne » | **Faux avec une remise** | La somme des HT rendus diffère du HT de la ligne dans 71 % des lignes rendues article par article. |
| Option B des avoirs, ce qui manque | **Oubli** | Quand une facture est rendue en plusieurs avoirs, elle peut rester due ou créditrice d'un ou plusieurs centimes : 73 % à 77 % des lignes rendues article par article en A1 selon le domaine, 17 % en A2. Une facture due d'un centime passe en retard et est relancée. C'est pour cette raison que je recommande l'option A, alors qu'`impact.md` proposait B. |
| Fichiers oubliés | **À ajouter** | `app/Domain/Facturation/Actions/EmettreAvoirAction.php:190` recalcule `montantsDuRetour()` à l'émission. `database/factories/PanierCaisseFactory.php:65` appelle `prixUnitaireHtExact()`. `app/Filament/App/Livewire/SelecteurDeProduits.php:194` montre le PU TTC, même pour un client professionnel. `FactureResource::lignesEntry()` (fiches des factures, avoirs, devis et achats) montre la TVA et le TTC de chaque ligne : en méthode HT, la TVA répartie par cumul ne tombe pas juste dans 54 % des documents de plusieurs lignes (question 9). |
| Variante à 4 décimales | **À ajouter** | Le PU HT passe de `decimal(18,6)` à `decimal(16,4)`, et non à `decimal(12,2)`. Le contenu signé porte le PU HT en dix-millièmes. Il faut un affichage dédié du PU HT à 4 décimales (`prixUnitaireAffiche()` arrondit au centime). |
| Clôtures, e-reporting, grands totaux, achats inchangés | **Confirmé** | `RecapitulatifDeCloture` et `EReportingDesParticuliers` additionnent les montants enregistrés ; l'e-reporting ne prend que les documents à des particuliers. |
| Question de l'éditeur sur la TVA collectée (`a-verifier-apres.md`, point 1) | **Confirmé pour les clôtures** | La TVA y est la somme des TVA enregistrées, jamais recalculée sur un total. L'état de TVA de l'étape 5 devra suivre la même règle. |

### 9.4 Ce qui reste incertain

- **Validateurs exacts de SUPER PDP** : à tester dans leur bac à sable (`/v1.beta/validation_reports`) avec un Factur-X en méthode HT à 4 décimales, à l'étape 2.
- **Texte officiel de l'EN 16931-1:2026** : la limite de 4 décimales et le retrait de BR-CO-17 ne sont connus que par des sources tierces.
- **Facture remise à un particulier sur sa demande** : PILIQI y imprime le PU TTC. Je n'ai pas tranché si elle doit porter le PU HT de l'art. 242 nonies A. Ce point n'est pas lié aux centimes, mais il est à vérifier.
- **Arrêté n° 83-50/A** (notes de prestations de services, total HT et TTC) : connu seulement par des réponses ministérielles, texte en vigueur non relu.
