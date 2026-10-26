# Série 7 : moteur de TVA centralisé — réponses de l'éditeur (2026-10-11)

Page : https://claude.ai/artifact/9zANxxhjsVNAzVmwFQPETJ (collection reponses7). Proposition : scratchpad/tva/resume-editeur.md et conception-finale.md. Aucune note libre.

## TVA1 — Place du moteur dans la feuille de route
- Choix : **a** (recommandation) — Avant la campagne de tests initiale, juste après la chaîne société (lots A4 à A7 et lot 21). Les petits lots de compléments déjà décidés s'intercalent ; l'étape 1 n'est cochée qu'après le lot M8.

## TVA2 — Le régime s'applique-t-il seul ?
- Choix : **a** (recommandation) — Seul quand vos réponses le justifient (« oui, nous expédions en Allemagne », adresse, date), avec le nouveau total affiché et un lien « Rester à la TVA française » ; proposé avec un bouton pour une prestation à une entreprise étrangère ; à l'émission, un choix imposé s'il reste en suspens. Personne ne peut forcer une exonération dont une condition manque.

## TVA3 — Que demander à chaque document ?
- Choix : **a** (recommandation) — Vente : la question « Les marchandises quittent-elles la France ? » pour un client étranger, sans réponse par défaut, avec la réponse de sa dernière facture proposée. Achat : origine toujours affichée, « France » d'office pour un fournisseur français, reprise de la dernière facture validée sinon, confirmée la première fois.

## TVA6 — Une seule façon de taxer par facture ?
- Choix : **a** (recommandation) — Un seul régime par facture ; une vente en France mêlant produits et services reste possible ; sinon, un bouton « Déplacer ces lignes vers une nouvelle facture ».

## TVA7 — Additionner des chiffres de plusieurs magasins
- Choix : **a** (recommandation) — Les autoriser, limitées à des totaux ; les montants visibles du seul propriétaire et des administrateurs autorisés ; un vendeur ne voit que « seuil non atteint, proche ou atteint ».

## TVA4 — Prix d'un service vendu sans TVA française
- Choix : **a** (recommandation) — Comme un produit : le prix de référence TTC du service, moins la TVA, sans autorisation spéciale.

## TVA5 — Produit à 0 % vendu en France
- Choix : **a** (recommandation) — Refuser 0 % sur la fiche du produit ou du service pour une société qui facture la TVA, et refuser aussi l'émission par sécurité.

## TVA8 — Quand construire le guichet OSS ?
- Choix : **a** (recommandation) — Plus tard (après SUPER PDP), et d'ici là refuser toute expédition qui ferait dépasser le seuil, avec un compteur et un avertissement dès 9 000 €. Une fois le seuil atteint, plus aucune expédition à un particulier de l'Union jusqu'à ce que l'OSS existe ; la vente au comptoir reste possible.

## TVA9 — Cartes cadeaux et bons d'achat
- Choix : **c** (≠ recommandation a) — Ne jamais vendre de cartes cadeaux dans PILIQI.

## TVA10 — Société en franchise de TVA : ventes et achats hors de France
- Choix : **a** (recommandation) — Ventes : les marchandises gardent la mention de franchise ; une prestation à une entreprise étrangère suit les règles du pays du client. Achats : achats de marchandises dans l'Union et importations refusés pour l'instant ; services étrangers calculés (TVA due, non déductible).

## TVA11 — Achats que le logiciel ne sait pas calculer seul
- Choix : **a** (recommandation) — Saisie à la main de la TVA, avec une autorisation dédiée donnée à personne au départ (le propriétaire et les responsables du magasin l'ont d'office), un motif obligatoire et une trace au journal d'audit.

## TVA12 — Importation : valider avant la déclaration en douane ?
- Choix : **a** (recommandation) — Oui : la marchandise se vend, avec un coût provisoire et une liste « Dédouanement à compléter » ; le coût se corrige à la saisie de la déclaration, sauf après un avoir fournisseur sur cette facture.

## TVA13 — Calcul de la TVA d'un achat et de ses retours
- Choix : **a** (recommandation) — Calculée sur le total de chaque taux, puis répartie entre les lignes, comme pour vos ventes (0,17 € + 0,66 € = 0,83 €) ; un retour ne rend jamais plus que la part de sa ligne.

## TVA14 — Frais de transport et de douane dans le coût du stock
- Choix : **a** (recommandation) — Dans le coût, répartis selon la valeur des articles, y compris les frais d'un transporteur rattachés à la facture des marchandises.

## TVA15 — Un fournisseur de l'Union a facturé la TVA de son pays
- Choix : **b** (recommandation) — Valider avec une régularisation : la TVA étrangère est notée à part, « à faire rectifier ou rembourser », la TVA française est calculée quand même, et la facture entre dans une liste « à régulariser ».

## TVA16 — Factures d'achat dans une autre monnaie
- Choix : **a** (recommandation) — Taux saisi sur la facture, avec sa source (celui de la déclaration en douane pour une importation).

## O — Huit corrections
- Choix : **a** (recommandation) — Je les accepte toutes.
  - O1. La valeur du stock s'affiche au coût d'achat, sans TVA ajoutée (aujourd'hui, une TVA au taux de vente s'y ajoute). Pour une société en franchise, le coût comprend la TVA qu'elle ne déduit pas.
  - O2. Une association non assujettie à la TVA reçoit une facture comme un professionnel, mais elle n'entre pas dans la facture électronique : elle va dans la déclaration des ventes aux particuliers. Un client public se facture sur Chorus Pro, hors de PILIQI, avec un message.
  - O3. Le n° de TVA d'un client de l'Union reste vérifié sur VIES le jour de l'émission (votre décision I2). Un avertissement s'ajoute quand la livraison a eu lieu avant, sans vérification à sa date.
  - O4. Les articles du code général des impôts restent imprimés tant que ceux du nouveau code (CIBS) ne sont pas confirmés ; d'après des sources concordantes, ils restent admis jusqu'au 30/06/2028.
  - O5. Le nom et l'adresse du client deviennent obligatoires sur toute facture, comme la loi l'exige.
  - O6. Un magasin des DOM ne peut pas expédier hors de son département, et une prestation à une entreprise des DOM est refusée, avec un message, jusqu'à un régime dédié (après SUPER PDP).
  - O7. Le régime de TVA de la société (franchise ou non) porte une date « Depuis le » ; un achat est toujours calculé avec le régime en vigueur à sa date.
  - O8. Une facture issue d'un devis reprend ses réponses (expédition, adresse) et son choix de régime ; tout est revérifié le jour de la facture.
