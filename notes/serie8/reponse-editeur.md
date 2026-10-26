# Réponse à vos retours du 17 octobre 2026 (série 8)

Bonjour,

Voici ma réponse à vos retours, point par point. Votre message va de 1 à 26 mais saute le point 10 : il y a donc 25 retours, que je garde sous leurs numéros.

Rien de cette série n'est encore modifié dans PILIQI. Pour chaque point, je vous dis ce que fait le logiciel aujourd'hui, ce qui va changer et quand. Les explications que vous avez demandées sont aux points 14, 15, 21, 24 et 26. Les questions qui me restent sont regroupées à la fin : répondre « comme vous recommandez » suffit pour lancer tous les travaux. Vous avez déjà tranché les deux questions de l'arrondi (Q1-a et Q2) : la correction de l'écart d'un centime peut commencer tout de suite.

---

## En bref

- **L'écart d'un centime ne vient pas de l'affichage, mais de la règle de calcul des tickets.** PILIQI arrondit le prix hors taxe d'un seul article, puis le multiplie par la quantité. Il sera corrigé en premier, avec une seule règle pour toutes les ventes, et la même correction sera faite sur les achats (point 15).
- **Déjà en place** : l'organisation de l'équipe (point 17). **En partie** : la mention de loi des ventes sans TVA (point 11) et l'avoir fournisseur saisi à la main (point 19).
- **Le reste** se fait dans les étapes 2 à 13 de la liste ci-dessous (l'étape 6 était déjà prévue), avant la fin de l'étape 1 de la feuille de route. La pièce jointe des factures d'achat (point 8) vient avec les « Charges du magasin », ou plus tôt si vous le souhaitez. Le point 9 attend vos règles, comme vous l'avez demandé.
- **En relisant le logiciel, j'ai trouvé deux façons pour un administrateur de vendre malgré tout** : il peut se créer un compte de responsable dont il connaît le mot de passe, et l'ancien propriétaire, après une transmission de la propriété, reçoit toutes les autorisations de vente. J'ai aussi vu que l'identifiant prévu « par magasin » ne colle pas avec certains changements de rôle que le logiciel permet aujourd'hui. Les trois sont traités (points 6 et 20, questions Q4-j et Q4-k).
- **Les questions** sont à la fin, chacune avec ma recommandation ; les deux de l'arrondi sont déjà tranchées.

## L'ordre des travaux

Deux étapes de la chaîne des sociétés ont été terminées aujourd'hui : la fermeture, la réouverture et la cession des magasins (relue et publiée), puis la vente sans stock, les remises et les prix, et les demandes envoyées deux fois (en relecture). Ensuite, dans cet ordre :

1. Une seule règle d'arrondi pour les ventes et pour les achats ; plus de total sous chaque ligne (points 12 et 15).
2. L'administrateur ne vend plus ; « Les caisses » au lieu de « Ma caisse » (points 6 et 7).
3. La connexion par identifiant (point 20).
4. Le tableau de bord : le rôle après « Bonjour », les factures à encaisser (points 5 et 16).
5. Les PDF des ventes (points 3 et 4).
6. Les paiements en plusieurs modes et le mode « Avoir » (points 1 et 23).
7. Les clôtures détaillées (points 14, 15, 21, 24 et 25).
8. L'aperçu de l'e-reporting (point 26).
9. Les retours cherchés par numéro (point 2).
10. Les grands tableaux des produits et des clients (points 13 et 22).
11. Les avoirs fournisseur à la main (point 19).
12. Les produits nés d'un achat ou d'un inventaire d'ouverture (point 18).

Viennent ensuite la fin de la chaîne des sociétés (procédures de l'éditeur, journal d'audit consultable, vérification de toutes vos décisions), puis le moteur de TVA, où se trouve le point 8.

---

## Vos points, un par un

### 1. « Avoir » parmi les modes de paiement

**Aujourd'hui** : à la caisse, un bon d'avoir passe par un bouton à part, et le crédit d'un client est déduit du total sans apparaître comme un choix.

**Ce qui change** : « Avoir » apparaît dans la liste des modes, à la caisse et dans « Encaisser » d'une facture. Le crédit d'un client s'affiche comme une part « Avoir », remplie d'office ; un bon d'avoir se choisit par « Avoir » puis son numéro, et plusieurs bons peuvent régler une même vente.

Un avoir ne fait pourtant entrer aucun argent dans la caisse. PILIQI continuera donc de l'enregistrer comme l'usage d'un crédit, et la clôture le montrera sur une ligne à part, « crédit d'avoir utilisé », à côté des espèces, de la carte, etc.

Une question fiscale reste ouverte : quand un client paie une **prestation de service** avec un avoir, faut-il compter ce paiement comme un encaissement pour la TVA ? Rien n'est envoyé à l'administration avant le raccordement à SUPER PDP, donc rien ne presse, mais il faut le faire trancher par l'expert-comptable (question Q13).

**Quand** : étape 6 de la liste. Questions Q6 et Q13.

### 2. Un retour se cherche par le numéro du ticket ou de la facture

**Aujourd'hui** : l'écran « Nouvel avoir » part du client. Il propose tous les clients, même ceux qui n'ont rien acheté, et pour le client comptoir il propose d'un coup toutes les lignes de tous ses tickets. Vous avez raison : on ne sait pas de quelle vente il s'agit.

**Ce qui change** : un seul champ, « N° du ticket ou de la facture ». Le client s'affiche ensuite d'après le document (pour le comptoir : « Client comptoir — ticket TK-… du 17/10/2026 à 10 h 32 »), avec les lignes encore à rendre. Le champ « Client » disparaît. On peut ajouter un autre ticket du même client ; un document d'un autre client est refusé. La même recherche s'ouvre depuis la fiche d'une facture ou d'un ticket, et par un bouton « Retour d'un ticket » à la caisse. Un vendeur ne retrouve que ses ventes ; un numéro d'un autre magasin est introuvable.

**Quand** : étape 9.

### 3. PDF : référence, modes de paiement, colonnes

**La référence du produit** s'imprime dans sa propre colonne, entière, sur une seule ligne ; la désignation passe à la ligne si la place manque.

**Les colonnes** :
- Sur une **facture** (et sur l'avoir d'une facture, et sur un devis à un professionnel) : Référence · Désignation · Qté · PU HT · Remise (seulement s'il y en a une) · Taux · Total HT. Le PU TTC, la TVA et le Total TTC de chaque ligne disparaissent, comme vous le demandez : ils sont dans le tableau de TVA et dans les totaux du bas. Le PU HT et le Total HT restent, parce que la loi exige sur une facture, pour chaque ligne, la quantité, la désignation, le prix unitaire hors taxe et le taux.
- Sur un **ticket** (et son avoir) : Référence · Désignation · Qté · PU TTC · Remise · Taux · Total TTC, puis le tableau de TVA. Le client y paie le prix affiché TTC. Si vous voulez aussi les colonnes HT sur les tickets, dites-le-moi : ce n'est qu'un choix de présentation.
- Une vente **sans TVA française** (export, livraison vers un DOM…) imprime toujours les colonnes HT, même sur un ticket, puisque le client paie le prix hors taxe.

**Le prix unitaire hors taxe** : aujourd'hui il est arrondi au centime. Sur une facture de 1 000 vis à 1,00 € TTC, PILIQI imprime « 1 000 × 0,83 € » pour un total HT de 833,33 € : 3,33 € d'écart que le lecteur ne comprend pas. Je propose d'enregistrer ce prix avec ses décimales utiles (0,833333 €), pour que quantité × prix retombe toujours sur le total, à deux centimes près au plus (question Q1-b).

**Les modes de paiement** : un bloc « Règlements » sous les totaux, une ligne par paiement (« 17/10/2026 · Espèces · 40,00 € », « Carte bancaire · 60,00 € »), puis le crédit d'avoir utilisé et le reste à payer. Pour les paiements reçus après l'émission, voir la question Q5.

**L'échéance** : elle ne s'imprime plus sur un ticket payé à la caisse. Elle reste sur toute facture à un professionnel, même payée tout de suite, car le Code de commerce exige sur une facture entre professionnels la date à laquelle le paiement doit intervenir.

**Quand** : étape 5.

### 4. L'identité de la société et les mentions en bas, une vraie page A4

**Aujourd'hui** : j'ai vérifié, le PDF est déjà une vraie page A4 (595 × 842 points), même avec une seule ligne. Mais l'identité de la société est en haut, et les mentions suivent directement les lignes : sur une facture d'une ligne, elles s'arrêtent vers le milieu de la page et tout le bas reste vide. C'est sans doute ce qui donne l'impression d'une page « réduite ». Si votre lecteur de PDF ou votre imprimante est réglé sur « ajuster à la page », la page peut aussi être réellement réduite à l'impression : vous pouvez le vérifier de votre côté.

**Ce qui change** : un pied de page fixé en bas de chaque page, avec l'identité de la société (dénomination, forme, capital, « à capital variable » le cas échéant, siège s'il n'est pas le magasin, SIREN, immatriculation, n° de TVA), toutes les mentions (pénalités de retard, indemnité de 40 €, escompte, catégorie de l'opération, franchise ou exonération, option pour les débits), les conditions de paiement et « Page 1 sur 3 ». En haut reste le magasin qui vend (nom, adresse, SIRET, téléphone, e-mail). J'ai déjà essayé la mise en page : une facture d'une ligne tient sur une page A4 avec le pied tout en bas, et une facture de 60 lignes fait 4 pages, avec le pied au même endroit sur chacune, sans jamais recouvrir une ligne.

**Quand** : étape 5.

### 5. Le rôle après « Bonjour »

**Ce qui change** : « Bonjour Paul Martin », puis « Vendeur · Boutique Lyon 1 » (ou « Responsable », « Propriétaire de Ma Boutique SAS », « Administrateur », « Expert-comptable », et « (démonstration) » dans le magasin de démonstration). La même phrase s'affiche en tête de l'espace de la société.

**Quand** : étape 4.

### 6. Un administrateur ne vend pas

**Aujourd'hui** : un administrateur reçoit par défaut toutes les autorisations, celles de vendre comprises ; j'ai pu ouvrir la caisse avec un compte d'administrateur et émettre un ticket. En vérifiant, j'ai trouvé deux autres chemins :
- après une **transmission de la propriété**, l'ancien propriétaire devient administrateur avec toutes les autorisations, ventes comprises ;
- un administrateur autorisé à nommer les responsables peut **créer un compte de responsable en choisissant lui-même son mot de passe provisoire**, s'y connecter et vendre. Je l'ai essayé : un administrateur à qui j'avais retiré toutes les autorisations de vente a quand même émis un ticket sous le compte qu'il venait de créer.

**Ce qui change** : une règle nouvelle, « un administrateur ne vend jamais ». Elle est tenue partout : la liste des autorisations qu'un administrateur peut recevoir devient une liste fermée (toute autorisation ajoutée plus tard lui est refusée tant qu'on ne l'a pas classée), l'écran ne les propose plus, une demande fabriquée est refusée, une ancienne case restée en base ne donne rien, la base de données elle-même les refuse, et l'ancien propriétaire ne reçoit que les autorisations d'un administrateur. L'administrateur garde la gestion, s'il y est autorisé : achats, stock, clôture du jour, fermeture de la caisse d'un vendeur. Le cas du compte de responsable qu'il crée se règle par la question Q4-j.

**Quand** : étape 2 (et étape 3 pour Q4-j). Questions Q3 et Q4-j.

### 7. « Les caisses » au lieu de « Ma caisse »

**Ce qui change** : le menu s'appelle « Les caisses » pour qui voit les relevés de toute l'équipe (propriétaire, responsable, administrateur autorisé, expert-comptable), et reste « Ma caisse » pour les autres. La page montre aussi les caisses encore ouvertes. L'administrateur n'a plus de caisse à lui.

La page vaut pour le magasin ouvert. Une page qui réunirait les caisses de tous les magasins dans l'espace de la société n'est pas prévue : dites-le-moi si vous la voulez.

**Quand** : étape 2.

### 8. Joindre la facture du fournisseur

**Aujourd'hui** : aucune pièce jointe n'existe.

**Ce qui change** : c'est déjà conçu en entier, avec les « Charges du magasin » : PDF, Factur-X, photo (JPEG, PNG) ou fichier XML d'une facture électronique, 10 Mo et 20 pièces au plus par facture ; les pièces restent rangées dans leur magasin et ne se suppriment jamais ; on ne peut en retirer une que sur un brouillon, et une pièce déposée par erreur se remplace avec un motif ; chaque ajout est inscrit au journal d'audit. L'écran rappelle que c'est une copie pour consultation, et que l'original se garde 10 ans. Je propose d'y ajouter les avoirs fournisseur.

**Quand** : avec le moteur de TVA, ou juste après les avoirs fournisseur (étape 11) si vous le préférez. Questions Q12 et P8.

### 9. Créer les produits automatiquement depuis une facture d'achat

Plus tard, comme vous l'avez demandé. Une partie est déjà prévue : à l'étape 2 de la feuille de route, les factures reçues par SUPER PDP arriveront en brouillon, avec création des produits inconnus, et l'entrée en stock restera une validation à la main. Je recommande un seul système d'import : d'abord SUPER PDP, puis un fichier de facture électronique déposé à la main, puis un modèle Excel, la lecture d'un PDF libre venant en dernier (elle demande un prestataire extérieur).

Mes questions sont prêtes pour le jour où vous voudrez la lancer : quelles sources (facture électronique, Excel avec un modèle imposé, PDF) ; comment reconnaître un produit déjà connu (référence du fournisseur, code-barres, nom) ; quel prix de vente et quel taux pour un produit nouveau ; entrée en stock directe ou brouillon à valider (je recommande le brouillon) ; quantités au poids, cartons, remises de pied de facture, facture importée deux fois ; qui peut importer.

### 10. (absent de votre message)

### 11. Le texte de loi d'une vente sans TVA

**Aujourd'hui** : la mention de loi s'imprime déjà automatiquement sur le PDF, et elle est signée avec le document. Sur la fiche de la facture, la mention de la nature apparaît, mais pas celle de la franchise.

**Votre réponse** (« sur la facture, automatique ») est appliquée : on ne choisit pas un type de TVA par ligne ; le motif vient de la nature de la vente, et sa mention s'imprime d'elle-même.

**Les ventes sans TVA, et ce que fait PILIQI** :

| Cas | Mention imprimée | État |
|---|---|---|
| Société en franchise en base | « TVA non applicable, art. 293 B du CGI » | En place |
| Export hors de l'Union, expédié par le magasin | « Exonération de TVA, article 262 I du CGI » | En place ; les preuves viendront avec le moteur de TVA |
| Export emporté par le client lui-même | — | Non géré : la vente reste à la TVA française, avec une information |
| Livraison vers un DOM | « Exonération de TVA en application de l'article 294 du CGI » | En place (pour la Guyane et Mayotte, où la TVA ne s'applique pas, la mention exacte est à vérifier) |
| Livraison à un professionnel d'un autre pays de l'Union | « Exonération de TVA, article 262 ter I du CGI » | En place, avec un n° de TVA vérifié le jour même |
| Prestation à un professionnel de l'Union | « Autoliquidation » | En place |
| Prestation à un professionnel hors de l'Union | « TVA non applicable – article 259-1° du CGI » | En place |
| Prestation à un particulier domicilié hors de l'Union | — | Non géré : vendue avec TVA française ; un avertissement viendra avec le moteur de TVA |
| Vente à distance à un particulier d'un autre pays de l'Union, au-delà du seuil | — | Sera refusée avec le moteur de TVA (le guichet européen n'est pas géré) |
| Autoliquidations propres à certains métiers (sous-traitance du bâtiment, déchets…) | « Autoliquidation » | Non géré |
| Biens d'occasion (régime de la marge) | « Régime particulier – Biens d'occasion » | Étape 14 de la feuille de route, ligne par ligne |
| Exonérations de la santé, de l'enseignement… | — | Toujours refusées, comme vous l'avez décidé |
| Détaxe d'un voyageur | — | Pas une vente sans TVA : le ticket reste avec TVA, régularisée après le visa |

**Ce qui change** : le motif de chaque taux à 0 % s'imprime aussi dans le tableau de TVA du PDF, à côté du taux qu'il explique ; la fiche affiche aussi la mention de franchise.

**Quand** : le motif dans le tableau de TVA à l'étape 5 (les PDF des ventes) ; la mention de franchise sur la fiche avec le moteur de TVA, qui fige aussi chaque mention dans le document au moment de son émission.

**Une précision sur les textes** : à partir du 1er janvier 2027, les articles de loi sur la TVA passent du Code général des impôts à un nouveau code (le code des impositions sur les biens et services). Les anciennes références restent acceptées sur les factures jusqu'au 30 juin 2028. Les nouveaux numéros ne sont pas encore confirmés de façon sûre : PILIQI les adoptera quand ils le seront, sans toucher aux documents déjà émis.

### 12. Plus de total sous chaque ligne

**Ce qui change** : le bloc « Total TTC … HT + … de TVA » sous chaque ligne disparaît ; les totaux du bas gagnent une ligne par taux de TVA dès qu'il y en a plusieurs.

**Quand** : étape 1, avec la correction de l'arrondi, qui change de toute façon ces lignes.

### 13. Le grand tableau des produits

**Ce qui change** : un clic sur « Ajouter un produit » ouvre une grande fenêtre, qui peut occuper tout l'écran : référence, nom, stock restant, prix HT et TTC, taux de TVA, bénéfice, et, au choix, code-barres, taille, couleur, seuil d'alerte, coût moyen, taux de marque, fournisseur, date d'entrée. 50 produits, puis « Voir 50 de plus » ; recherche par nom, référence ou code-barres ; tri par les plus récents ou par nom ; filtres « en stock seulement » et « hors stock seulement », ce dernier seulement pour qui a le droit de vendre sans stock. On sélectionne plusieurs produits d'un clic, et le total HT et TTC de la sélection s'affiche avant de tout ajouter d'un coup. Un champ « Scanner ou taper une référence » garde la douchette.

**Quand** : étape 10. Question Q9 (qui voit le coût et le bénéfice).

### 14. Pourquoi l'abandon d'une vente est inscrit au journal

**La raison, avec un exemple.** Un client achète un article de 50 € et paie en espèces. Le vendeur saisit l'article, prend le billet, puis appuie sur « Abandonner la vente » au lieu d'« Encaisser ». Aucun ticket n'existe : 50 € sortent des recettes, et 8,33 € de TVA ne sont jamais reversés. Même chose avec une ligne retirée : le client paie trois articles, le vendeur en retire un avant de valider et garde la différence.

C'est exactement la fraude que vise l'obligation de caisse sécurisée : la loi interdit une caisse qui permet d'effacer une opération sans laisser de trace, et, d'après les commentaires de l'administration, les données protégées vont « de la prise de commande jusqu'à l'enregistrement du règlement » (le texte exact de ces commentaires est à revérifier, le site officiel étant injoignable d'ici). Sans cette trace, l'attestation de conformité que vous signez comme éditeur serait fausse.

**Ce que la trace apporte** : chaque abandon et chaque ligne retirée sont inscrits, signés et chaînés, avec le vendeur, l'heure, le nombre d'articles et le montant. Un contrôleur, ou le propriétaire, voit par exemple qu'un vendeur abandonne dix ventes payées en espèces par jour. Ce n'est pas une accusation : abandonner une vente est normal (client parti, erreur de saisie) ; seule la trace est demandée. Ces codes suivent, d'après PILIQI, ceux de la norme NF525 ; la loi n'impose pas cette norme, elle impose le résultat.

**Le nouveau texte proposé pour l'alerte** : « Abandonner la vente ? Les lignes saisies sont effacées. Pour la sécurité de la caisse, l'abandon est inscrit au journal des événements avec votre nom, le nombre d'articles et le montant. »

**Quand** : le nouveau texte, à l'étape 7. Question Q8 (la baisse du prix d'une ligne avant validation).

### 15. Les modes de paiement dans la clôture, et l'écart d'un centime

**D'où vient l'écart, exactement.** Pour un particulier, PILIQI calcule le prix hors taxe d'**un seul** article et l'arrondit au centime : 25,00 € ÷ 1,2 = 20,8333… → 20,83 €. Puis il multiplie ce prix déjà arrondi par la quantité : 20,83 × 2 = 41,66 € HT, et la TVA est la différence : 50,00 − 41,66 = 8,34 €. De même, deux articles à 17,50 € donnent 29,16 € HT et 5,84 € de TVA au lieu de 5,83 €. Un seul article à 50,00 € donne bien 41,67 € et 8,33 € : c'est pourquoi l'écart n'apparaît qu'avec une quantité de 2 ou plus, ou plusieurs lignes. C'est la règle écrite aujourd'hui dans PILIQI (« article par article »), pas une erreur d'affichage : la caisse, le ticket, son PDF, la clôture et le tableau de bord ne font que recopier ou additionner ce montant.

Pour un professionnel, une autre règle produit l'écart inverse : un article affiché 9,99 € lui est facturé 10,00 €, et deux lignes de 25,00 € font 49,99 €. Le même panier n'a donc pas les mêmes montants selon le client.

Et dans les achats, la TVA se calcule ligne par ligne : trois lignes à 10,01 € HT donnent 6,00 € de TVA, alors que la facture du fournisseur, calculée sur le total, en porte 6,01 €.

**La correction : une seule règle, documentée.**
- Pour toute vente (ticket, facture, devis, avoir ; particulier ou professionnel) : le total payé est la somme des prix affichés ; pour chaque taux de TVA, le hors taxe est le TTC de ce taux ÷ (1 + taux), arrondi **une seule fois par document**, au centime le plus proche (un demi-centime au centime supérieur, « l'arrondi classique » que vous demandez) ; la TVA est la différence ; les montants se répartissent ensuite sur les lignes.
- Votre cas obligatoire : **50 € TTC à 20 % font 41,67 € HT et 8,33 € de TVA**, que le ticket ait une ligne de 50 €, une ligne de 2 × 25 € ou deux lignes de 25 €. 35 € font 29,17 € et 5,83 €. Un professionnel paie 9,99 € un article affiché 9,99 €.
- Pour les achats, la règle que vous avez déjà décidée le 12 octobre (« comme vos ventes, partout ») : la TVA de chaque taux se calcule sur le total hors taxe du taux, une seule fois (trois lignes à 10,01 € : 30,03 € HT et 6,01 € de TVA).

**Ce que cette règle ne change pas, et pourquoi c'est normal.** Chaque document est arrondi pour lui-même. Une clôture, le relevé d'une caisse, le tableau de bord et l'e-reporting **additionnent** les documents, comme vous l'exigez (votre exigence 8 : additionner les ventes et les avoirs sans recalculer la TVA du total). Une somme de plusieurs documents peut donc s'écarter un peu de « total TTC ÷ 1,2 », d'au plus un demi-centime par document.

Exemple : deux tickets séparés d'un article à 25,00 € font chacun 20,83 € HT et 4,17 € de TVA, ce qui est juste pour chacun. La clôture du soir montre leur somme : 41,66 € HT et 8,34 € de TVA. Ce n'est pas une erreur : recalculer la TVA du total ferait diverger la clôture des tickets qu'elle résume. Un test vérifiera aussi ce cas.

**Vos dix exigences** :
1. Prix d'achat et prix de vente séparés : c'est déjà le cas ; je supprime en plus un doublon du prix de vente qui ne sert à rien.
2. Prix de référence gardés, jamais réutilisés pour une vente faite : déjà le cas.
3. Montants calculés et enregistrés une fois, d'une seule méthode : ils sont déjà calculés une fois, mais avec deux méthodes ; il n'y en aura plus qu'une.
4. Calculs exacts, sans nombres à virgule flottante : les calculs se font déjà en centimes entiers. La correction fait aussi passer en centimes la façon dont les ventes et les achats transportent leurs montants. Quelques fonctions qui additionnent des totaux (clôture, tableau de bord, paiements) calculent déjà en centimes mais reçoivent et rendent encore des nombres à virgule : elles passeront en centimes avec le moteur de TVA, qui les réécrit de toute façon. Aucune erreur n'a été observée sur 20 000 paniers simulés.
5. Une vente validée, une facture, un avoir, le tableau de bord et la clôture réutilisent les montants enregistrés : **oui, déjà partout**, comme vous le pensiez.
6. HT + TVA = TTC : déjà vrai sur chaque ligne, chaque taux, chaque document et chaque clôture.
7. Un avoir reprend les montants de la vente : déjà vrai ; un retour complet rend exactement les montants de la ligne, et des retours partiels ne rendent jamais plus que la ligne.
8. La clôture additionne sans recalculer : déjà vrai (avec la conséquence expliquée ci-dessus).
9. Les moyens de paiement dans la clôture : à faire (point 24). Pour les « autres moyens configurés », question Q14 ; pour les remboursements, question Q7.
10. Vérifier tous les modules : fait, module par module ; le seul autre écart trouvé est celui des achats, corrigé en même temps.

**Les tests** vérifieront votre cas obligatoire sur l'écran de la caisse, le ticket enregistré, son contenu signé, son PDF, sa fiche et la clôture, avec plusieurs lignes, plusieurs taux, des quantités, des remises, des retours partiels et deux caisses qui vendent le même panier au même instant.

**Les documents déjà émis** : aucune donnée réelle n'existe encore. En production, un changement de règle ne touchera jamais un document émis : chaque document gardera la règle qui l'a calculé, et une erreur se corrigera par un avoir et une nouvelle facture.

**Quand** : étape 1, dès la fin de la relecture en cours. Vous avez tranché Q1-a (un seul calcul pour tous) et Q2 (le hors taxe au centime supérieur sur un demi-centime) ; reste Q1-b.

### 16. Les factures à encaisser au tableau de bord

**Aujourd'hui** : rien ne les signale. J'ai vérifié votre exemple : une facture payée par virement, puis rouverte parce que ce virement a été contre-passé, redevient « émise » avec son montant dû, sans aucune alerte.

**Ce qui change** : une alerte en tête du tableau de bord : le nombre de factures à encaisser et le montant dû, dont celles « en retard » en rouge, les dix plus anciennes avec un badge « Paiement contre-passé », et un lien vers la liste. Selon votre demande : le propriétaire voit tout ; un responsable, tout son magasin ; un administrateur, les magasins de sa portée où il voit toutes les factures ; un vendeur ou un observateur, seulement s'il a le droit de contre-passer (ses factures, ou toutes s'il voit celles de l'équipe) ; l'expert-comptable la voit aussi. Dans l'espace de la société, le propriétaire et les administrateurs voient le total magasin par magasin, sans nom de client. En plus, quand un paiement est contre-passé, le crédit d'avoir du client s'imputera tout seul sur la facture rouverte (décision déjà prise le 11 octobre).

**Quand** : étape 4.

### 17. Administrateur et responsable

**Déjà conforme**, comme vous l'avez confirmé : le rôle « Responsable » existe ; un administrateur ne crée ni vendeur, ni observateur, ni administrateur ; il nomme un responsable seulement s'il y est autorisé ; un responsable crée et modifie ses vendeurs. La case « modifier les membres » que vous aviez vue n'existe plus : vos constats venaient de l'ancienne version.

**Un point à trancher, lié au point 6** : quand un administrateur autorisé crée un nouveau compte de responsable, il choisit son mot de passe provisoire, et peut donc s'y connecter et vendre (question Q4-j). Petit ajout : une phrase de l'espace de la société dira qu'un responsable se nomme depuis « Comptes ».

### 18. Un produit naît d'un achat ou d'un inventaire d'ouverture

**Aujourd'hui** : le bouton « Nouveau » existe dans « Produits », et j'ai pu créer un produit sans aucun achat.

**Ce qui change**, selon votre réponse : plus de création dans « Produits ». Un produit naît seulement de la validation d'une facture d'achat, ou d'un **inventaire d'ouverture** : le stock déjà là au démarrage du magasin, saisi avec son coût, en brouillon puis validé ; la validation crée les produits, entre leur stock et s'inscrit au journal d'audit. La règle est tenue au cœur du logiciel : une création de produit hors de ces deux validations est refusée, quel que soit l'écran qui la tente. Le produit reste modifiable dans « Produits », sauf le fournisseur et les données d'achat. L'inventaire complet (comptage, ajustements, import) reste prévu plus tard.

**Quand** : étape 12. Question Q11.

### 19. Créer un avoir fournisseur à la main

**Aujourd'hui** : un avoir fournisseur manuel existe déjà, lié à la facture d'achat et à sa ligne, avec sortie du stock et coût moyen recalculé ; mais il ne se crée que depuis la liste des factures d'achat, ce qui explique que vous ne l'ayez pas trouvé. Le menu « Avoirs fournisseur » est en lecture seule. Aucune connexion par API n'existe encore : aucun avoir n'« arrive par l'API » pour l'instant.

**Une précision sur septembre** : d'après nos notes sur la réforme (à revérifier sur les sources officielles), la **réception** des factures électroniques est obligatoire pour toutes les entreprises depuis le 1er septembre 2026 ; c'est l'**émission** qui le devient pour les PME au 1er septembre 2027. Les avoirs de vos grands fournisseurs arrivent donc déjà par une plateforme agréée. Quand PILIQI sera raccordé à SUPER PDP, un avoir saisi à la main puis reçu par la plateforme ne devra pas sortir deux fois le stock : il faudra les rapprocher, et le numéro de l'avoir de votre fournisseur le permettra. L'écran vous le demandera donc avec insistance (un avertissement s'il manque, sans blocage).

**Ce qui change** : un bouton « Créer un avoir fournisseur » dans le menu, la même action sur la fiche d'une facture d'achat, et « Retourner ce produit » sur chaque ligne. On ne retourne que des produits d'une facture d'achat validée, chaque ligne de l'avoir étant liée à sa ligne d'achat. Sous chaque ligne retournée, sur la fiche de la facture d'achat, une mention en orange : « Retourné : 4 (avoir AF-… du 17/10/2026) ». Chaque avoir s'inscrit au journal d'audit. Les avoirs reçus plus tard par SUPER PDP ou par l'API apparaîtront dans le même menu.

**Quand** : étape 11. Question Q10.

### 20. Se connecter avec un identifiant

**Votre décision** (« tous sauf le propriétaire ») est appliquée : responsables, vendeurs et observateurs ont un identifiant et un mot de passe, un compte par magasin ; administrateurs et experts-comptables ont un identifiant rattaché à la société ; seul le propriétaire se connecte avec son adresse e-mail.

**Comment** : l'identifiant est donné par PILIQI, tiré au hasard, unique dans tout le logiciel et jamais réutilisé. Je propose 12 chiffres, dont un chiffre de contrôle, affichés en trois groupes (« 4821 0937 5512 »), faciles à taper et à dicter. À la création, une **fiche d'accès** (identifiant et mot de passe provisoire valable 72 heures) s'affiche une seule fois, à remettre en main propre. La page de connexion n'a qu'un champ, « Identifiant ou adresse e-mail » ; l'adresse n'ouvre que le compte du propriétaire. Les essais ratés sont limités par identifiant et par adresse internet, et écrire l'identifiant avec ou sans espaces ou tirets ne permet pas de contourner cette limite. Une création de compte envoyée deux fois par erreur ne crée jamais deux comptes ; avant de créer un compte, PILIQI propose les anciens comptes du même magasin au même nom, pour réintégrer un ancien salarié. Le refus d'un compte et le rattachement d'un compte à une autre société n'ont plus d'objet (question Q4-c).

**Le principal risque, et ce que je propose.** Celui qui renouvelle l'accès d'un vendeur connaît son mot de passe provisoire : il pourrait se connecter avant lui, choisir le mot de passe et vendre en son nom. Je propose deux protections : si la personne est là, elle tape elle-même son nouveau mot de passe sur l'écran de celui qui renouvelle, et aucun mot de passe provisoire n'existe ; et le propriétaire est prévenu de chaque renouvellement qu'il n'a pas fait lui-même, puis du premier mot de passe choisi ensuite. S'y ajoutent la fermeture des sessions et le journal d'audit.

**Deux points du logiciel actuel à trancher** : un compte peut aujourd'hui changer de rôle (un vendeur devenu administrateur, un administrateur redevenu vendeur dans deux magasins…), ce qui ne colle pas avec un identifiant rattaché pour toujours à un magasin ou à la société (question Q4-k) ; et le compte de responsable créé par un administrateur (question Q4-j).

**Quand** : étape 3. Question Q4 (onze choix, de a à k).

### 21. La clôture dans « Caisse sécurisée » ; faut-il inscrire chaque clic ?

**Vous avez raison** : une clôture n'inscrit aujourd'hui rien dans le journal des événements.

**Je recommande de l'inscrire.** La loi exige des clôtures et, pour chacune, des totaux qui ne peuvent plus changer : le ticket Z signé les apporte déjà, c'est la trace légale. L'inscription au journal ajoute la trace de l'opération elle-même (qui a clôturé, quand, à la main ou à minuit) ; c'est la pratique de la norme NF525 (référentiel non lu de mon côté, à vérifier). Elle était prévue plus tard ; je l'avance, avec le numéro du Z, la journée, les totaux, les encaissements par mode et les grands totaux.

**Chaque clic : non.** Aucun texte ne le demande : la loi vise les ventes, les encaissements et leurs modifications, pas les gestes à l'écran. Tout inscrire noierait les vrais signaux, et garderait des années des traces du travail des vendeurs sans raison fiscale, ce que la protection des données personnelles déconseille. On inscrit les connexions, les clôtures, les exports, les réglages, les droits, les anomalies, le mode démonstration, et tout ce qui fait disparaître une vente ou une partie de vente avant son enregistrement ; rien d'autre. Un cas reste à trancher : la baisse du prix d'une prestation avant validation (question Q8).

**Quand** : étape 7.

### 22. Une loupe pour chercher le client

**Ce qui change** : une loupe à côté du champ « Client » ouvre le tableau des clients : nom, type, SIREN ou SIRET, n° de TVA, e-mail, téléphone, ville, crédit d'avoir ; recherche par nom, SIREN, SIRET, n° de TVA, e-mail, téléphone, ville ou code postal, sans tenir compte des majuscules ni des accents ; tri du plus récent au plus ancien ; filtres particuliers, professionnels, « avec un crédit d'avoir » ; bouton « Nouveau client ». Le client comptoir reste choisi par défaut. Le même tableau sert aux factures et aux devis.

**Quand** : étape 10.

### 23. Payer en plusieurs modes

**Aujourd'hui** : la caisse n'accepte qu'un mode par vente ; une facture peut recevoir plusieurs paiements, mais en plusieurs fois.

**Ce qui change** : un règlement en plusieurs parts, en une seule fois, à la caisse comme sur une facture, pour les particuliers comme pour les professionnels. La première part reprend le mode préféré du client et le montant à payer ; « Ajouter un autre mode » ajoute une part avec ce qui reste ; seule la part en espèces rend la monnaie. Modes proposés : espèces, carte bancaire, chèque, virement (le prélèvement n'est pas proposé à la caisse), et ceux que vous voudrez ajouter (question Q14). Chaque part est un paiement signé, et la clôture compte chaque part dans son mode.

**Quand** : étape 6.

### 24. Modes de paiement et somme des avoirs dans la clôture : l'État le demande-t-il ?

**Ce que l'État demande** (d'après nos recherches ; le texte exact des commentaires officiels est à revérifier) : une clôture par jour, par mois et par année (ou par exercice) ; pour chacune, des totaux récapitulatifs qui ne peuvent plus changer, dont le grand total de la période et un total perpétuel ; et les corrections (avoirs, retours) enregistrées « en moins », sans effacer la vente d'origine. Ces règles disent comment enregistrer, pas ce qu'un ticket Z doit afficher : je n'ai trouvé aucun texte officiel qui donne cette liste.

**Ma recommandation** : montrer la somme des avoirs à part des ventes, parce que c'est la façon la plus claire de rendre visibles ces corrections « en moins » ; et montrer les moyens de paiement, parce que c'est la pratique de tous les tickets Z et de la norme NF525, et que votre règle le demande déjà (« toutes les ventes et leurs règlements entrent dans les clôtures »).

**Ce que la clôture affichera** : les ventes aux particuliers et leurs avoirs (nombre et montants par taux), puis leur net, qui est la base de l'e-reporting ; les factures aux professionnels et leurs avoirs, pour information ; les encaissements du jour par moyen de paiement, contre-passations déduites ; le crédit d'avoir utilisé, à part, puisqu'il ne fait entrer aucun argent ; les grands totaux. Ventes et encaissements ne sont pas toujours égaux, et c'est normal : un client qui garde un crédit d'avoir ou qui paie plus tard crée l'écart. Pas de ligne « remboursements » tant que votre règle « rien n'est jamais remboursé » reste (question Q7).

**Quand** : étape 7.

### 25. Numéros cliquables dans les clôtures

**Ce qui change** : chaque numéro de ticket ou d'avoir ouvre le document complet, pour qui a le droit de le voir. Un vendeur qui ne voit que ses ventes voit dans la clôture les totaux du magasin et le détail de ses seuls tickets : aujourd'hui, la fiche de clôture lui montre aussi le numéro, le client et le montant des tickets de ses collègues, ce que votre règle des vendeurs interdit.

**Quand** : étape 7.

### 26. À quoi ressemble l'e-reporting, et pourquoi la page est vide

**Votre schéma est juste** : tickets et avoirs détaillés → clôture Z → totaux du jour par catégorie et par taux de TVA → envoi par la plateforme agréée. L'État ne reçoit ni chaque ticket, ni un seul total TTC.

**Pourquoi la page est vide** : aujourd'hui, une déclaration ne se prépare que la nuit, à 1 h, par une tâche automatique, et seulement le lendemain de la fin d'une période entière (une décade pour un régime mensuel : une vente du 5 octobre n'apparaît que le 11). Elle n'existe que pour un magasin réel d'une société validée dont le régime de TVA est renseigné ; le magasin de démonstration n'est jamais déclaré ; et en local, la tâche automatique ne tourne que si on la lance. Je ne peux pas savoir laquelle de ces raisons s'appliquait chez vous ; la plus probable : des ventes dans le magasin de démonstration, ou une tâche automatique arrêtée.

**Une journée en exemple** (magasin « Ma Boutique », lundi 5 octobre 2026) :

*Les documents* (ils restent dans PILIQI) :

| Document | Client | Contenu | HT | TVA | TTC | Règlement |
|---|---|---|---|---|---|---|
| Ticket 1 | comptoir | 2 câbles à 50 € (20 %) | 83,33 | 16,67 | 100,00 | espèces |
| Ticket 2 | comptoir | 1 livre à 10,55 € (5,5 %) et 1 réparation à 60 € (20 %) | 60,00 | 10,55 | 70,55 | carte |
| Ticket 3 | comptoir | 1 câble | 41,67 | 8,33 | 50,00 | carte |
| Facture 1 | professionnel | 1 câble | 41,67 | 8,33 | 50,00 | virement |
| Avoir 1 | comptoir | retour d'un câble du ticket 1 | −41,67 | −8,33 | −50,00 | le client garde son crédit |

*La clôture Z du soir* : ventes aux particuliers 185,00 € HT, 35,55 € de TVA, 220,55 € TTC ; avoirs −50,00 € TTC ; net 170,55 € TTC ; factures aux professionnels 50,00 € TTC ; encaissements : espèces 100,00 €, carte 120,55 €, virement 50,00 €.

*Ce que PILIQI enverra pour cette journée* (ventes aux particuliers seulement ; aucun nom, aucun numéro de ticket, aucun moyen de paiement) :

| Date | Catégorie | Taux | Base HT | TVA |
|---|---|---|---|---|
| 05/10/2026 | Livraisons de biens | 20 % | 83,33 | 16,67 |
| 05/10/2026 | Livraisons de biens | 5,5 % | 10,00 | 0,55 |
| 05/10/2026 | Prestations de services | 20 % | 50,00 | 10,00 |
| | **Total** | | **143,33** | **27,22** |

Le total égale le net de la clôture, parce que les deux additionnent les mêmes documents. La facture au professionnel n'y est pas : elle partira comme facture électronique. Une vente à un particulier sans TVA française (export, livraison vers un DOM, et sans doute la franchise, à confirmer) entre dans une quatrième catégorie, « opérations non soumises à la TVA en France » : elle est bien à déclarer, et sa transmission attendra seulement que PILIQI sache envoyer son motif. Pour la réparation, une prestation de service, PILIQI enverra aussi le paiement (« 05/10/2026, 20 %, 60,00 € encaissés »), une fois la règle confirmée par l'expert-comptable, comme votre règle le prévoit déjà. SUPER PDP regroupe ensuite ces lignes selon votre régime (ici par décade) et les transmet à l'administration.

**Ce qui change** : chaque fiche de clôture montrera « Aperçu de l'e-reporting (non transmis) », avec ce tableau en français ; le menu « Soumissions e-reporting » devient « E-reporting » : la liste des journées clôturées qui ont des opérations, chacune avec son aperçu et l'état « non transmis : plateforme pas encore raccordée ». Le calendrier par périodes de PILIQI disparaît, puisque vous avez déjà décidé que SUPER PDP le tient et que PILIQI envoie chaque clôture du jour.

**Quand** : étape 8.

---

## Vos questions

Chaque question donne les choix et ma recommandation. Répondre « comme vous recommandez » à tout suffit.

### Q1. Le calcul unique des ventes

**Q1-a. Vaut-il aussi pour les professionnels ?** Aujourd'hui, un professionnel paie 10,00 € un article affiché 9,99 €, et deux lignes de 25,00 € font 49,99 €.
- (a) Un seul calcul pour tous : le professionnel paie les prix affichés, et le même panier a les mêmes montants pour tout client. Deux effets visibles sur une facture : deux lignes identiques peuvent avoir des totaux hors taxe qui diffèrent d'un centime (20,83 € et 20,84 €), et la TVA d'un taux peut différer d'un centime de « hors taxe × taux ». Les règles européennes de la facture électronique tolèrent ce centime ; je vérifierai les contrôles de SUPER PDP lors du raccordement.
- (b) Les particuliers seulement : un professionnel paie encore 10,00 € un article à 9,99 €.
- (c) Pour les professionnels, le hors taxe calculé sur le total TTC de chaque taux, mais la TVA toujours égale à « hors taxe × taux » : un article à 9,99 € fait encore 10,00 €.
- **Je recommande (a).**

**Q1-b. Le prix unitaire hors taxe sur une facture.** La loi l'exige ; arrondi au centime, il ne retombe pas sur le total (1 000 vis : « 0,83 € » pour 833,33 €).
- (a) Le prix exact, avec ses décimales utiles (0,833333 €, mais 10,00 € quand il tombe juste) : quantité × prix retombe toujours sur le total, à deux centimes près au plus, ce que demandent aussi les règles de la facture électronique.
- (b) Le prix au centime, avec une note sous les lignes qui explique l'écart.
- **Je recommande (a).**

### Q2. Le demi-centime : déjà réglé par votre phrase

Votre phrase « 41,67 € HT et 8,33 € de TVA avec l'arrondi classique au centime » tranche la question : le hors taxe s'arrondit au centime le plus proche, un demi-centime au centime supérieur (2,97 € TTC font 2,48 € HT et 0,49 € de TVA). Je l'applique sans attendre.

### Q3. Ce que l'administrateur ne fait plus

- **Q3-a. Quels documents il ne crée jamais** : (1) tickets, factures et encaissements ; (2) en plus, les avoirs et les devis. **Je recommande (2)** : un administrateur supervise et ne fait aucune opération commerciale avec un client.
- **Q3-b. Les gestes de gestion qu'il garde, s'il y est autorisé** : contre-passer un paiement saisi par erreur ; envoyer ou relancer une facture ou un avoir déjà émis (ce ne sont pas des ventes). **Je recommande : oui pour les deux.**
- **Q3-c. Fixer le prix de référence d'une prestation au catalogue**, s'il y est autorisé (sans rien vendre). **Je recommande : oui.**

### Q4. La connexion par identifiant : onze choix

- **a. Le format** : 12 chiffres dont un de contrôle, ou 10 lettres et chiffres. **Je recommande les 12 chiffres.**
- **b. L'adresse e-mail facultative d'un compte autre que le propriétaire** : (1) un simple contact ; (2) recevoir des notifications, une fois confirmée par un lien ; (3) en plus, « Mot de passe oublié » par un lien quand elle est confirmée. **Je recommande (3)** : la personne peut alors retrouver son accès sans que son responsable connaisse son mot de passe. Dans tous les cas, la transmission de la propriété enverra un lien à l'adresse du futur propriétaire et ne prendra effet que quand il le suivra : c'est la seule façon de s'assurer que l'adresse qui deviendra sa connexion est bien la sienne.
- **c. Supprimer le refus d'un compte, le rattachement d'un compte à une autre société et la création d'une société avec un compte retiré**, qui n'ont plus d'objet (une personne reçoit un compte dans chaque société) ? **Je recommande : oui.**
- **d. Le renouvellement de l'accès** : celui d'un responsable, par le propriétaire seul, ou aussi par un administrateur autorisé ? **Je recommande le propriétaire seul**, sinon un administrateur pourrait se connecter comme responsable et vendre. Et pour tout compte : la personne présente tape elle-même son nouveau mot de passe ; absente, elle reçoit un mot de passe provisoire ; le propriétaire est prévenu de chaque renouvellement qu'il n'a pas fait et du premier mot de passe choisi ensuite. **Je recommande ces deux protections.**
- **e. « Une caisse à la fois »** ne vaudra plus que pour le propriétaire, seul compte qui vend dans plusieurs magasins ; une même personne pourra avoir deux caisses ouvertes sous ses deux comptes, dans deux magasins. **Je recommande : d'accord.**
- **f. Retirer d'office les comptes d'un magasin cédé ou fermé définitivement** (une fermeture temporaire les garde) ? **Je recommande : oui.**
- **g. Des comptes d'entraînement dans le magasin de démonstration**, créés par le propriétaire ou un responsable et retirés à sa fermeture ? **Je recommande : oui.**
- **h. « Se souvenir de moi »** : le retirer pour tous, propriétaire compris ; le retirer seulement pour les comptes de magasin ; ou le garder. **Je recommande de le retirer pour tous** : le propriétaire vend lui aussi à la caisse, et son compte, qui a tous les droits partout, resterait ouvert sur un poste partagé.
- **i. Les mots de passe** : au moins 12 caractères, sans l'identifiant ni le nom (ou 8 caractères) ; et faut-il vérifier auprès d'un service extérieur qu'un mot de passe n'a pas déjà été divulgué ? **Je recommande 12 caractères**, et ce contrôle extérieur seulement si vous l'acceptez.
- **j. Un compte de responsable créé par un administrateur** : (a) l'administrateur le crée, mais seul le propriétaire en produit et remet la fiche d'accès, et le compte ne s'ouvre pas avant ; (b) l'administrateur ne crée plus de compte de responsable, il nomme seulement responsable un vendeur ou un observateur du magasin qui a déjà son propre mot de passe ; (c) accepter le risque, le propriétaire étant prévenu de chaque compte créé et de sa première connexion. **Je recommande (a)** : l'administrateur garde le droit de créer des responsables, comme vous le souhaitiez, sans jamais connaître leur mot de passe.
- **k. Les changements de rôle** : (a) un compte garde pour toujours son rôle (membre d'un magasin, administrateur ou expert-comptable) ; une personne qui change de rôle reçoit un nouveau compte, et l'ancien est retiré ; seule exception, la transmission de la propriété ; (b) le compte reste le même et reçoit un nouvel identifiant à chaque changement. Dans les deux cas, passer de vendeur à responsable dans le même magasin garde le même compte. **Je recommande (a)**, cohérent avec « un compte par magasin ».

Une conséquence de votre choix, sans question : un collaborateur d'un cabinet d'expertise comptable qui suit dix sociétés aura dix identifiants.

### Q5. Les paiements reçus après l'émission, sur le PDF

Votre règle dit que le PDF reproduit le document tel qu'émis ; aujourd'hui, pourtant, il relit les paiements au moment de l'impression.
- (a) Le PDF imprime les règlements reçus à l'émission ; les paiements reçus ensuite restent sur la fiche, qui les montre avec leur mode.
- (b) Une réimpression ajoute, à part du document, une section datée « Situation des règlements au … ».
- **Je recommande (a).**

### Q6. Un bon d'avoir sur la facture d'un client nommé

Aujourd'hui, un bon d'avoir (avoir fait au client comptoir) s'utilise à la caisse, pour la vente de n'importe quel client.
- (a) Seulement à la caisse, et sur un ticket du client comptoir resté impayé.
- (b) Aussi sur une facture faite depuis « Factures » pour un client nommé.
- **Je recommande (a)**, fidèle à votre décision « à une prochaine vente au comptoir ».

### Q7. Les remboursements

Votre exigence 9 parle de remboursements ; votre règle dit « rien n'est jamais remboursé : un retour donne un crédit ».
- (a) Garder la règle : la clôture montre les avoirs et le crédit utilisé, sans ligne de remboursement.
- (b) Permettre de rembourser : c'est une fonction nouvelle, dont il me faudrait vos règles (qui, jusqu'à quel montant, en quel mode, avec quelle trace).
- **Je recommande (a).**

### Q8. La baisse du prix d'une ligne avant validation

Retirer une ligne avant de valider s'inscrit au journal ; baisser le prix d'une prestation avant de valider, pour qui en a le droit, ne s'inscrit pas.
- (a) L'inscrire, comme une ligne retirée, avec le prix d'avant et d'après.
- (b) Ne pas l'inscrire : seule l'autorisation de fixer les prix la limite.
- **Je recommande (a)** : elle fait disparaître une partie de la vente avant son enregistrement.

### Q9. Le coût et le bénéfice dans le tableau des produits

- (a) Seulement pour qui voit la valeur du stock (propriétaire, responsables, expert-comptable, et un vendeur si on le lui coche).
- (b) Pour toute personne qui ajoute des produits.
- **Je recommande (a)** : à la caisse, le client voit souvent l'écran.

### Q10. Les avoirs fournisseur

- **Q10-a.** Aujourd'hui, on ne retourne que ce qui est encore en stock. (a) Garder cette limite ; (b) permettre le retour d'un produit déjà vendu, le stock pouvant passer en négatif. **Je recommande (a).**
- **Q10-b.** Un avoir sans retour de marchandise (remise accordée après coup, erreur de prix du fournisseur) : (a) pas pour l'instant ; (b) oui, sa règle sur le coût des produits étant à faire confirmer par l'expert-comptable. **Je recommande (a).**

### Q11. L'inventaire d'ouverture

- **Quand** : (a) jusqu'à la première vente du magasin ; (b) à tout moment pour un produit jamais reçu ; (c) à tout moment. **Je recommande (a)**, « le stock existant au démarrage » comme vous l'avez écrit ; un produit oublié passera ensuite par une facture d'achat.
- **Par qui** : (a) une personne autorisée à valider les achats et à créer des produits ; (b) une autorisation dédiée. **Je recommande (a).**

### Q12. Une pièce est-elle exigée pour valider un achat de marchandises ?

- (a) Non, mais un rappel « aucune pièce jointe » à la validation.
- (b) Oui, aucune facture d'achat ne se valide sans sa pièce.
- **Je recommande (a).**

### Q13. Une prestation payée avec un crédit d'avoir compte-t-elle comme encaissée ?

Pour une prestation de service, la TVA est due à l'encaissement (sauf option pour les débits). Payer avec un avoir ne fait entrer aucun argent, mais éteint la dette du client.
- (a) La compter comme encaissée, à la date où le crédit est utilisé.
- (b) Ne jamais la compter : seuls les paiements en espèces, carte, chèque, etc. comptent.
- **Je recommande de le faire trancher par votre expert-comptable avant le raccordement à SUPER PDP**, comme votre règle le prévoit déjà pour les paiements de prestations des particuliers. D'ici là, PILIQI montre ces montants à part et n'envoie rien.

### Q14. D'autres moyens de paiement ?

Vous demandez d'afficher « espèces, carte bancaire, virement, chèque et autres moyens configurés ». PILIQI n'a aujourd'hui que cinq modes fixes.
- (a) En plus des cinq modes, chaque magasin peut ajouter ses propres moyens (par exemple « Titre-restaurant », « Chèque-vacances »), qui ne rendent jamais la monnaie et comptent chacun à part dans la clôture ; PILIQI ne contrôle pas leurs règles propres (par exemple, les produits acceptés en titre-restaurant).
- (b) Une liste fixe un peu plus longue : titre-restaurant et chèque-vacances.
- (c) Les cinq modes seulement.
- **Je recommande (a).** Les cartes et bons cadeaux restent à part dans tous les cas : leur TVA suit des règles particulières, à voir plus tard si vous en avez besoin.

---

## Ce que je vérifie encore de mon côté

- Les textes officiels (Légifrance, commentaires de l'administration) ne sont pas joignables depuis ma machine : je cite les articles de loi d'après nos notes et des extraits, et je les relirai dès que possible (mentions obligatoires d'une facture, date de paiement entre professionnels, contenu des clôtures, prestation payée par compensation).
- La norme NF525 : je n'ai pas lu son référentiel ; ce que j'en dis vient de la pratique des éditeurs.
- SUPER PDP : ses contrôles exacts (centime de TVA, prix unitaire) se vérifieront dans son environnement d'essai, lors du raccordement.
- Chez vous : la raison de la page « Soumissions e-reporting » vide, et le réglage d'impression de votre lecteur de PDF.
