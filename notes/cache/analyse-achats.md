# Cache du tableau de bord : les cartes des achats et du stock

Analyse en lecture seule, le 23/11/2026, de :

- la branche de travail, `/home/user/piliqiediteur` (tête `36b2388c`) : `app/Domain/TableauDeBord`, `app/Filament/GrilleDesGraphiques.php`, les achats, le stock ;
- le lot GRAPH-AN (commit `0257979e`), copie `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/wt-demo` : calcul par jour, mois, trimestre, année, cumul, écart sur un an, tendance, détail ;
- le lot TVA27 (commit `fbdf759a`), copie `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/wt-tva` : crédit de TVA reporté.

Rien n'a été modifié. Chaque affirmation renvoie au fichier qui la prouve.

---

## 1. Réponse courte

**1. L'idée qu'une période terminée ne change plus est fausse pour les achats et pour le stock.** Le code le montre de quatre façons :

- **La date d'un achat est celle de la facture du fournisseur, saisie librement.** Aucune borne ni dans le formulaire (`FactureAchatResource.php` l. 137, un `DatePicker` sans `minDate` ni `maxDate`), ni en base (`date_facture` est une simple `date`). Si l'on valide aujourd'hui une facture datée de décembre 2025, d'il y a deux ans, ou de 2027 par une faute de frappe, l'achat entre dans ce mois-là. Les factures reçues de SUPER PDP (étape 2) et de l'API (étape 10) arriveront de la même façon, avec leur date d'origine.
- **Une facture d'achat validée saisie à la main se corrige**, tant qu'aucun avoir fournisseur ne la vise (`FactureAchat::estModifiable()`, `ModifierFactureAchatAction`). La correction peut changer :
  - le total ;
  - la date, donc le mois de l'achat, voire son année ;
  - le fournisseur ;
  - l'escompte ;
  - toutes les lignes : elles sont effacées par une requête directe puis recréées, avec de nouveaux identifiants (`ConstructeurLigneAchat.php` l. 215 et l. 229).
- **La valeur du stock et la marge viennent d'un rejeu.** On rejoue tout le grand livre de chaque produit avec les lignes **actuelles** de ses réceptions (`StockLedgerService::coutMoyenRejoue()`, l. 520-606). Si l'on corrige en mars une réception de janvier, la valeur du stock et le coût des ventes changent en janvier, février et mars. Aucun mouvement n'est pourtant daté de janvier.
- **Un magasin d'outre-mer ne finit pas son mois en même temps que Paris**, qui est le calendrier de l'espace de la société. Le 1er à 0 h 30 à Paris, la Martinique vend encore le 31 au soir. Le 1er janvier, c'est l'année entière que la règle « période finie » figerait trop tôt.

**2. La position de Claude, une version par mois et par source de données, est juste pour les cartes qui additionnent des documents.** Ces cartes sont :

- « Ventes et achats HT » ;
- « Achats par fournisseur » ;
- le chiffre clé « Achats HT du mois » ;
- « Écart des prix d'achat » affiché par mois ;
- la TVA déductible des achats.

Trois conditions sont nécessaires :

1. La version est calculée sur le contenu réellement lu, jamais sur `updated_at` seul ni sur « nombre + plus grand identifiant ».
2. Chaque mois de la fenêtre a sa version : passés, en cours et à venir. Aucun mois d'achats n'est jamais clos.
3. Pour un document horodaté (un avoir fournisseur, une facture sans date), le mois de la version se compte large, le jour d'avant et le jour d'après inclus, pour ne jamais manquer le mois local.

**3. Une version par mois est fausse pour la valeur du stock et la marge brute.** La valeur d'un mois dépend de toute l'histoire du produit : c'est une chaîne. Ces cartes ont besoin d'une **version par produit**, qui fait rejouer ce seul produit, en entier, quand elle change. Une solution plus simple mais plus lente existe : une version de tout le stock du magasin.

**4. L'écart des prix sur 12 mois ne se découpe pas par mois.** Le prix de départ d'un produit est son premier achat dans la fenêtre, et la fenêtre « 12 derniers mois » glisse chaque mois. Le calcul se refait pourtant en mémoire, sans lire la base, à partir des 12 mois en cache. Il n'y a rien de plus à garder.

**5. Six règles de fonctionnement sont nécessaires.** Sans elles, même avec une bonne version, on peut montrer un chiffre faux ou le chiffre d'un autre (§ 4) :

1. Les droits se relisent à chaque requête et ne vont jamais en cache.
2. Les entrées sont rangées par magasin et par source, sans jamais en mélanger deux.
3. Les noms se relisent à chaque affichage.
4. Les versions et les données se lisent dans un même instantané de la base.
5. La clé porte la base, le site et le code déployé.
6. Le mois en cours se prend dans le calendrier de chaque magasin.

---

## 2. Tableau des cartes

| Carte | Se découpe par mois | Ce qui change son passé | Mise en cache | Version | Risque restant | Verdict |
|---|---|---|---|---|---|---|
| Ventes et achats HT par mois | oui | un achat validé en retard ; un achat corrigé (total, date) ; jamais une vente | une entrée par (magasin, source, mois) qui garde les sommes par jour | empreinte du contenu par (magasin, source, mois) | faible | cache par mois |
| Achats par fournisseur | oui pour les sommes ; le classement se refait | un achat en retard ; un achat corrigé (fournisseur, total, date) ; un fournisseur ou un magasin renommé | (magasin, mois) : somme par fournisseur ; noms relus | la même empreinte des achats | faible | cache par mois |
| Valeur du stock au coût moyen | oui avec une dépendance en chaîne | la correction d'une réception passée (prix, remise, HT saisi, escompte, quantité, produit retiré ou ajouté) | une entrée par produit avec son rejeu entier ; le magasin et les barres se recomposent | version du produit : dernier mouvement compté, stock, coût moyen actuel, empreinte de ses réceptions | faible | cache par période |
| Marge brute HT par mois | oui avec une dépendance en chaîne | la même correction ; le coût moyen actuel qui sert de départ au rejeu (cas limite) | coûts : entrée par produit ; ventes des articles : (magasin, mois) | version du produit + version des lignes de vente du mois | faible | cache par période |
| Écart des prix d'achat, un total par mois | oui | un achat en retard, qui peut devenir le premier du mois ; une correction (lignes, escompte, date, produit d'une ligne) ; un produit renommé | (magasin, mois) : les achats bruts, sans nom ; calcul refait à l'affichage | empreinte des lignes et de leur facture par (magasin, mois) | faible | cache par mois |
| Écart des prix d'achat, un seul total pour 12 mois | non | tout achat ajouté ou corrigé dans les 12 mois ; la fenêtre qui glisse | rien de plus : recalcul en mémoire depuis les 12 mois | les 12 versions mensuelles et la liste des 12 mois | faible | cache par période |
| Chiffre clé « Achats HT » et sa variation | oui | un achat du même mois de l'an dernier, saisi ou corrigé aujourd'hui | le mois en cours recalculé ; le mois d'il y a un an en cache | la version des achats de chaque mois | faible | cache par mois |
| TVA déductible des achats, avec le report du lot TVA27 | oui avec une dépendance en chaîne | un achat en retard ou corrigé ; un crédit déclaré, modifié, corrigé ou confirmé ; la cadence du régime | la TVA de chaque mois en cache ; la chaîne du report recalculée | versions des mois ; aucune pour la chaîne | faible | cache par mois |

Le risque restant suppose la conception proposée ici. Avec la règle « période finie = figée », il serait **élevé** pour toutes ces cartes. Avec une version par mois, il resterait **élevé** pour le stock et la marge.

---

## 3. Chaque carte en détail

### 3.1 Ventes et achats HT par mois (`Graphique::VentesEtAchatsParMois`)

**Calcul.**
- Branche actuelle : `SeriesDesGraphiques.php` l. 119-122, sur `documentsDeVente()` et `documentsDAchat()` (l. 587-617), avec `CalculDesSeries::sommeParMois()`.
- GRAPH-AN : sommes par jour (`SommesParJour`), puis barres.

**Sources lues** (`VentesDuTableauDeBord::documents()`, `AchatsDuTableauDeBord::documents()` l. 48-98) :
- `factures` (statut ≠ brouillon) : `id`, `magasin_id`, `date_emission`, `total_ht` ;
- `avoirs` (émis) : les mêmes colonnes ;
- `factures_achat` (validées) : `id`, `magasin_id`, `date_facture`, `date_validation`, `total_ht`, `total_tva`, `total_ttc`, `fournisseur_id` ;
- `avoirs_fournisseur` (émis) : `id`, `magasin_id`, `date_emission`, totaux, et `factures_achat.fournisseur_id` par une jointure.

**Ce dont elle dépend.**
- Les magasins où le compte voit à la fois les ventes et les achats (`Perimetre::magasinsDe()`).
- Les mois de la période et, si la case est cochée, ceux d'un an plus tôt.
- Le mois en cours de l'écran : un mois à venir n'a pas de valeur (`CalculDesSeries::parMoisMultiple()` l. 234).
- Le jour de chaque document dans le calendrier de son magasin (`JoursDesMagasins`).
- Une société « Commerce ou atelier » seulement.

**Ce qui change son passé.**
- **Ventes : rien.** Un document émis ne change plus. Un avoir et une facture rectificative sont de nouveaux documents, datés du jour de leur émission.
- **Achats :**
  - la validation d'une facture dont la date est celle de n'importe quel mois ;
  - la correction d'une facture validée : son total, sa date (`fill([...$attributs])`, l. 199 de `ModifierFactureAchatAction.php`).

**Mise en cache.**
- Une entrée par (magasin, source, mois), qui garde la somme HT de chaque jour, signée (un avoir se déduit).
- À l'affichage, on additionne les mois et les magasins que compte la carte.
- Le mois en cours de chaque magasin se recalcule toujours.

**Version.** Une empreinte md5 du contenu lu, mois par mois (§ 5).

### 3.2 Achats par fournisseur (`Graphique::AchatsParFournisseur`)

**Calcul.**
- `SeriesDesGraphiques.php` l. 371-387.
- Les noms viennent de `AchatsDuTableauDeBord::nomsDesFournisseurs()` l. 194-209.
- Le classement vient de `classement()` l. 545-556 : valeur décroissante, puis nom, puis les 10 premiers.

**Sources lues.**
- Les mêmes documents d'achat que la carte 3.1. Le fournisseur d'une facture est `factures_achat.fournisseur_id`.
- Le fournisseur d'un avoir est celui de sa facture.
- `fournisseurs` : `id`, `nom`. C'est le nom actuel, archivés compris, puisque `DB::table` ignore l'archivage.
- `magasins.nom` : la carte l'ajoute après « · » quand elle compte plusieurs magasins.

**Ce dont elle dépend.**
- Toute la fenêtre. Dans GRAPH-AN, le découpage par année porte la fenêtre à 10 ans (`DecoupageDesPeriodes::fenetre()` l. 55, `ANNEES = 10`).
- Les magasins comptés.
- Le nom actuel des fournisseurs. Il sert d'étiquette et départage les égalités.
- Le nombre de magasins comptés.

**Ce qui change son passé.**
- Un achat validé en retard.
- La correction d'une facture : changement de fournisseur, de total ou de date.
- Un fournisseur ou un magasin renommé : seulement l'étiquette, mais sur tous les mois.
- Un avoir fournisseur ne change jamais de fournisseur : une facture qui porte un avoir, même brouillon, ne se corrige plus (`FactureAchat::estModifiable()` l. 345-356).

**Mise en cache.**
- Par (magasin, mois), la somme HT signée par identifiant de fournisseur.
- À l'affichage, on additionne les mois de la fenêtre et on relit les noms, en une requête sur les seuls fournisseurs classés.
- On classe et on garde les 10 premiers.
- Ni le classement ni les noms ne vont en cache.

**Version.** La même empreinte des achats par (magasin, mois). Elle contient `fournisseur_id`.

### 3.3 Valeur du stock au coût moyen (`Graphique::ValeurDuStock`)

**Calcul.**
- Branche actuelle : `SeriesDesGraphiques.php` l. 424-429, puis `StockDuTableauDeBord::historique()` l. 85-193.
- GRAPH-AN : `StockDuTableauDeBord::historique()` l. 83 rend un `HistoriqueDuStock` jour par jour, indépendant de la période. `valeurA()` donne la valeur au dernier jour d'une barre.

**Sources lues**, quatre requêtes sans aucune borne de date : toute l'histoire du magasin.
- `produits` (archivés compris) : `id`, `magasin_id`, `stock_quantite`, `cmup_ht`.
- `mouvements_stock` : `id`, `produit_id`, `type`, `quantite`, `cout_unitaire_ht`, `source_type`, `source_id`, `survenu_le`.
- Les réceptions (`receptions()` l. 250-272) :
  - `lignes_achat` des factures validées : quantité, PU HT, remise, HT saisi, ordre ;
  - leur facture : `rang_validation`, `repere_grand_livre`, `escompte_pourcentage`.

**Pourquoi c'est une chaîne.** Pour chaque produit, `coutMoyenRejoue()` repart :
- de `stock_quantite` moins la somme du grand livre, qui vaut 0 ;
- et de **`cmup_ht` actuel** (l. 534-535).

Il rejoue ensuite chaque mouvement dans l'ordre. Une réception entre « à sa place, celle de son premier mouvement, au coût actuel de ses lignes ». Sa quantité est celle de **tous** ses mouvements, y compris les ajustements écrits plus tard par ses corrections (l. 566-597). La valeur à la fin d'un mois est donc un niveau : le stock rejoué fois le coût moyen rejoué. Elle dépend de tout ce qui précède.

**Ce qui change son passé : la correction d'une facture d'achat validée, et elle seule.** Elle change ce qui suit :
- le prix, la remise, le HT saisi ou l'escompte : le coût moyen change dès la réception, donc la valeur de tous les mois qui suivent ;
- la quantité : un ajustement est daté d'aujourd'hui, mais le rejeu le compte à la place de la réception. Une réception de 10 articles en janvier, corrigée à 12 en juin, montre 12 dès janvier ;
- un produit retiré ou ajouté. Un produit retiré n'entre plus du tout : son stock passé baisse rétroactivement.

Tout le reste n'écrit que le présent :
- le grand livre est en ajout seul ;
- chaque mouvement est daté de son inscription (`GardesDuStock`, refus `REFUS_INSTANT`) ;
- cela vaut pour les ventes, les retours, les avoirs fournisseur, l'inventaire d'ouverture, la cession et l'annulation de cession.

**Mise en cache.**
- Pas par mois. **Une entrée par produit** garde son rejeu entier : la valeur à la fin de chaque jour où elle change, le coût des sorties par jour, et les clés des ventes sorties et des retours, pour la marge.
- La valeur d'un magasin et de chaque barre se recompose à l'affichage, en additionnant les produits.
- Seuls les produits dont la version a changé se rejouent : en pratique, ceux vendus ou reçus depuis le dernier affichage.

**Version du produit.** Elle a quatre parties :
- `produits.dernier_mouvement_stock_id`. La base le pose à chaque mouvement de ce produit (déclencheur `compter_le_mouvement_de_stock`, `GardesDuStock.php` l. 125-128). Il ne reprend jamais une valeur déjà prise : un identifiant ne sert qu'une fois.
- `produits.stock_quantite`.
- `produits.cmup_ht`, pour le cas limite de la carte 3.4.
- L'empreinte md5 de ses lignes d'achat validées (id, quantité, PU HT, remise, HT saisi, ordre, facture) avec le rang, le repère et l'escompte de leur facture.

**Solution de repli.** On peut prendre une version globale du magasin : plus grand identifiant et nombre des mouvements, empreinte de toutes ses réceptions, empreinte des coûts moyens de ses produits. Tout le magasin se rejoue alors à chaque changement, ce qui veut dire après chaque vente d'un article.

### 3.4 Marge brute HT par mois (`Graphique::MargeBruteParMois`)

**Calcul.**
- Branche actuelle : `SeriesDesGraphiques.php` l. 240-255.
- GRAPH-AN : `margeParPeriode()`. On obtient les ventes moins les coûts, et le taux de marge en info-bulle.

**Sources lues.**
- Celles du stock.
- Les lignes de vente des documents émis (`VentesDuTableauDeBord::lignes()`), avec `factures.facture_rectifiee_id` pour les rectificatives.
- Une ligne compte si sa vente a fait sortir le produit du stock, ce qu'on lit dans le grand livre. Le coût est le coût moyen rejoué au moment du mouvement.

**Ce dont elle dépend.**
- Les magasins où le compte voit les ventes **et** le stock.
- La chaîne du produit.
- Le calendrier du magasin.
- Le fait que la carte soit affichée : les lignes de la marge ne se lisent qu'alors (`historiqueDuStock()` l. 573-576). La clé d'un historique mis en cache doit le dire.

**Ce qui change son passé.**
- La correction d'une réception passée : le coût de toutes les ventes du produit depuis la réception.
- **Cas limite, une dépendance au présent.** Le rejeu part du coût moyen **actuel** du produit. Si une vente précède tout coût dans son grand livre, elle sort à ce coût actuel. Un achat d'aujourd'hui change alors le coût de ces anciennes ventes. Deux cas y mènent :
  - un produit né d'un inventaire d'ouverture sans suivi du stock prend son coût sans mouvement (`entrerAuCout()` l. 113-117) ; on coche ensuite le suivi (`ProduitResource.php` l. 95), puis on le vend sans stock ;
  - une réception qui fait entrer un produit, des ventes, puis une correction qui retire ce produit de la réception.
- Côté ventes : rien.

**Mise en cache.**
- Les coûts par jour viennent de l'entrée par produit de la carte 3.3.
- Les ventes des articles par jour viennent du cache des lignes de vente par (magasin, mois), filtré par les sorties du grand livre de chaque produit.
- Le taux de marge se calcule à l'affichage.

**Versions.** La version du produit, qui contient `cmup_ht` et couvre donc le cas limite, et la version des lignes de vente de chaque mois.

### 3.5 et 3.6 Écart des prix d'achat (`Graphique::EcartDesPrixDAchat`)

**Calcul.**
- `SeriesDesGraphiques::ecartDesPrixDAchat()` l. 397-409.
- `AchatsDuTableauDeBord::lignes()` l. 125-183.
- `AchatsParProduit::depuis()` puis `EcartDesPrixDAchat::calculer()`.
- La page choisit ce qu'elle écrit dans `GrilleDesGraphiques::ecartDesPrixDAchat()` l. 326-365 et `TextesDeLEcartDesPrixDAchat::lecture()`.

**Sources lues**, en une requête.
- `lignes_achat` : `magasin_id`, `produit_id`, `quantite`, `prix_unitaire_ht`, `remise_pourcentage`, `montant_ht_saisi`.
- Leur facture validée : `date_facture`, `date_validation`, `rang_validation`, `escompte_pourcentage`.
- `produits.nom`, le nom actuel.
- `magasins.nom` quand la carte compte plusieurs magasins.

**Prix d'un achat.** C'est le coût net d'un article, en centimes :
- le HT saisi ÷ la quantité, escompte déduit ;
- sinon le PU HT moins la remise, puis moins l'escompte.

Il n'y a qu'un arrondi (l. 176-178).

**Ce qui dépend de quoi.**

*Du magasin.*
- Les achats se groupent par (magasin, produit) : on ne mélange jamais les prix de deux magasins.
- Quand la carte compte plus d'un magasin, le nom d'un produit devient « nom · magasin ». Cela dépend donc du nombre de magasins comptés, c'est-à-dire du périmètre de la personne.

*De la période.*
- Seules entrent les lignes des 12 mois de la période. Dans GRAPH-AN, quand le découpage est l'année, ce sont les 12 derniers mois (`CalculDesSeries` l. 115).
- Un mois est rangé par son rang k, de 0 à 11.
- L'indice i d'un produit est sa place dans la liste de toute la fenêtre.

*Du mois, pour l'affichage par mois.*
- Pour le mois k, le premier achat du produit dans ce mois, par jour puis par rang de validation, est le prix de départ.
- Chaque achat suivant du même mois est comparé à lui : écart = quantité × (prix − prix de départ).
- Le mois k ne dépend que de ses propres lignes (`calculerLeMois()` l. 230-261) : **il se découpe par mois**.

*De toute la fenêtre, pour l'affichage sur 12 mois.*
- Le prix de départ est le premier achat du produit **dans la période**. Chaque achat suivant, de n'importe quel mois, est comparé à lui (`calculer()` l. 104-176).
- **Ce total ne se découpe pas par mois** et dépend du début de la fenêtre. Avec « 12 derniers mois », la fenêtre glisse au changement de mois. Si l'ancien premier achat d'un produit en sort, son prix de départ change, et donc toutes ses comparaisons.

*De l'affichage et du mois choisi.*
- L'affichage est retenu en base. Le mois choisi n'est qu'un état de la page, jamais enregistré.
- Ni l'un ni l'autre ne change le calcul, qui est fait une fois pour les deux affichages. Ils choisissent seulement ce qui s'écrit et se dessine.
- Le mois choisi par défaut est le dernier mois de la période qui n'est pas à venir (`moisDeLaCarte()`).

*Indépendances.* La carte ne dépend ni de « Comparer avec l'année précédente », ni du type de graphique (barres seulement), ni de l'heure (`TextesDeLEcartDesPrixDAchat` ne lit aucune date).

**Ce qui change si une ligne d'achat est corrigée** (`ModifierFactureAchatAction` sur une facture validée sans avoir).
- Toutes les lignes de la facture sont recréées avec de nouveaux identifiants. Le rang de validation de la facture ne change pas.
- **Quantité, PU HT, remise ou HT saisi.**
  - Le prix c de cet achat change.
  - S'il était le premier achat du produit dans son mois, tous les écarts du produit dans ce mois changent.
  - S'il était le premier dans la fenêtre, toutes ses comparaisons sur 12 mois changent.
  - Sinon, seul son propre écart change.
- **Escompte de la facture.** Le prix de **toutes** ses lignes change, sans que le total de la facture bouge. Une version faite des seuls totaux ne le verrait pas.
- **Date de la facture.**
  - L'achat quitte son mois pour un autre : deux mois changent.
  - S'il sort des 12 mois, il disparaît de la fenêtre.
  - Daté plus tôt dans le même mois, il peut en devenir le premier achat et changer la référence du produit.
- **Produit d'une ligne.** L'achat quitte le groupe d'un produit pour celui d'un autre. Une ligne peut aussi faire naître un nouveau produit.
- **Nom.** Une ligne peut corriger la fiche du produit (`ATTRIBUTS_DU_PRODUIT` contient `nom`, `ConstructeurLigneAchat.php` l. 92-100). Seule l'étiquette change.
- **Achat validé en retard**, daté d'un mois passé. Il entre dans ce mois. Avec un jour plus tôt, il devient le premier achat du mois, ou de la fenêtre.
- **Ce qui n'entre jamais dans cette carte** : un avoir fournisseur ou un inventaire d'ouverture.

**Mise en cache.**
- Par (magasin, mois), les achats bruts du mois : produit, jour, rang, quantité, prix en centimes, **sans nom**.
- À l'affichage :
  - on relit les noms des produits de la fenêtre en une petite requête ;
  - on refait `AchatsParProduit` et `EcartDesPrixDAchat::calculer()` sur les 12 mois. C'est un calcul pur, en mémoire, qui ne coûte presque rien.
- On ne met **jamais** en cache le résultat d'un mois tel que `calculer()` le rend : il porte l'indice i du produit dans toute la fenêtre, et les noms.
- Le total sur 12 mois se recalcule toujours depuis les 12 mois en cache. Si l'on voulait le garder, sa clé serait : les magasins comptés, les 12 mois de la fenêtre et les 12 versions.

**Version.** Par (magasin, mois), l'empreinte md5 des lignes (id, produit, quantité, PU HT, remise, HT saisi) jointes à leur facture validée (id, date, date de validation, rang, escompte), ordonnées par identifiant de ligne.

### 3.7 Chiffre clé « Achats HT » du mois et sa variation sur un an

**Calcul.** `ChiffresDuTableauDeBord::pour()` l. 64-66, sur le mois en cours de l'écran et le même mois un an plus tôt.

**Ce qui change son passé.** Un achat du même mois de l'an dernier, validé en retard ou corrigé aujourd'hui, change la variation.

**Mise en cache.** Le mois en cours se recalcule. Le mois d'il y a un an vient de l'entrée par (magasin, mois) des achats, protégée par sa version.

### 3.8 TVA déductible des achats et crédit de TVA reporté (TVA27)

Cette carte n'est pas l'objet de cette analyse, mais les achats l'alimentent.

**Ce qui se découpe par mois.**
- La TVA des factures d'achat validées et des avoirs fournisseur émis (`total_tva`).
- Ce que `TvaDeLaPeriode` additionne par mois.

**Ce qui forme une chaîne.** Le lot TVA27 calcule le report depuis la première période de la société, celle de son arrivée, et le dit lui-même : « la chaîne se calcule toujours depuis son début » (`ReportDuCreditDeTva.php`).

**Ce qui change son passé.**
- Un achat validé en retard ou corrigé dans un mois passé change la TVA de ce mois et le report de **toutes** les périodes qui suivent.
- Déclarer, modifier, corriger ou confirmer un crédit change l'ancre, donc tout ce qui la suit.
- La cadence du régime, mensuelle ou trimestrielle, regroupe les mois autrement.

**Mise en cache.**
- Seulement la TVA de chaque mois, par magasin (`MontantsDeTva`), à partir des entrées mensuelles des ventes, des achats et des charges.
- La chaîne se recalcule à chaque affichage. `ReportDuCreditDeTva::calculer()` est un calcul pur sur des montants déjà additionnés.
- On relit à chaque affichage les déclarations de crédit (`credits_de_tva`) et le régime.
- Jamais un solde reporté en cache.

---

## 4. Points de fonctionnement et cas difficiles

### 4.1 La date d'un achat : aucun mois d'achats n'est jamais clos

**Ce qui détermine le mois.**
- Le jour d'un achat est `date_facture`, une date sans heure tapée par la personne.
- À défaut, c'est le jour de validation dans le calendrier du magasin, pour une facture reçue par l'API (`AchatsDuTableauDeBord::documents()` l. 72). Le semis en crée, sans date, avec la source `api`.
- Un avoir fournisseur prend le jour de son émission (`EmettreAvoirFournisseurAction.php` l. 138-144).

**Conséquence.**
- Chaque validation peut écrire dans n'importe quel mois, y compris un mois à venir.
- Chaque mois de la fenêtre, à venir compris, doit donc avoir sa version, calculée à chaque affichage.

**Constat hors cache.** « Achats par fournisseur » et « Écart des prix » comptent un achat daté d'un mois à venir de la période (`dansLaPeriode()`). « Ventes et achats » laisse au contraire ce mois vide (`parMoisMultiple()`). Une facture datée de décembre 2026 par erreur figure donc dans le classement de « Année 2026 », alors que la barre de décembre est vide.

### 4.2 La correction d'une facture d'achat validée

**Qui et quand.** `ModifierFactureAchatAction` : il faut le droit de valider, une facture saisie à la main, aucun avoir sur elle et un magasin ouvert.

**Ce qu'elle écrit.**
1. `fill([...$attributs, 'fournisseur_id' => …])->save()` (l. 199-200) : fournisseur, date, escompte, numéro.
2. `ConstructeurLigneAchat::enregistrer()` :
   - efface toutes les lignes par une requête directe (l. 215) ;
   - les recrée (l. 229) ;
   - écrit les totaux (l. 235).
3. `corrigerLaReception()` :
   - pose un ajustement daté d'aujourd'hui quand la quantité change ;
   - rejoue le coût moyen ;
   - rattache le produit au fournisseur de son dernier achat.
4. `TvaDuFournisseur::retenir()` : la TVA de chaque taux.

**Piège.** Eloquent n'écrit que les colonnes changées. Si seules les lignes changent, et pas les totaux, `factures_achat.updated_at` **ne bouge pas**. Un échange de quantités entre deux lignes au même prix, ou un produit remplacé à montant égal, ne touche donc pas la facture.

### 4.3 Le coût moyen rejoué est une chaîne propre à chaque produit

**Ce qui est séparé, et ce qui ne l'est pas.**
- Le rejeu d'un produit ne lit que son propre grand livre, ses propres réceptions, sa fiche et le calendrier de son magasin (`StockDuTableauDeBord::historique()`). Les produits sont donc indépendants entre eux.
- À l'intérieur d'un produit, au contraire, chaque mois dépend de tous ceux qui précèdent.

**Conséquence.** C'est ce qui justifie une entrée et une version par produit. Le découpage par mois ne convient pas.

### 4.4 Le coût moyen actuel sert de départ au rejeu

**Le mécanisme.**
- `coutMoyenRejoue()` part de `$produit->cmup_ht`, le coût moyen actuel (l. 535).
- Il ne compte qu'avant le premier coût connu dans l'histoire du produit : une réception, un inventaire, une rentrée de cession, ou une réception sans suivi placée avant le mouvement.

**Ce qu'il touche.**
- Il est sans effet sur la valeur du stock : un stock nul ou négatif ne vaut rien, et la première entrée fixe le coût.
- Il change le coût des ventes faites avant ce premier coût (voir la carte 3.4 pour les deux chemins qui y mènent).

**Parade.** `cmup_ht` fait partie de la version du produit.

### 4.5 La valeur du stock à la fin d'un mois est un niveau, pas une somme

**Ce que vaut une fin de mois.**
- La fin d'un mois vaut l'état après le dernier mouvement de ce mois, ou du dernier mois qui en a eu un (branche actuelle l. 157-169).
- Dans GRAPH-AN, c'est la valeur au dernier jour de la barre (`HistoriqueDuStock::valeurA()`).
- Elle ne se cumule jamais : GRAPH-AN grise le cumul pour cette carte.

**Ce qui se compare.** La comparaison « un an plus tôt » lit la valeur au même jour d'il y a un an.

**Ce qui ne marche pas.** Une somme de mois mise en cache ne peut pas la donner.

### 4.6 L'avoir fournisseur

**Pourquoi il ne change pas le passé.**
- Il est daté de son émission, ni modifiable ni supprimable : `update()` et `delete()` rendent toujours faux (`AvoirFournisseurPolicy.php` l. 41-49).
- Il fige la facture qu'il crédite, qui ne se corrige plus. Son fournisseur est donc figé lui aussi.

**Où il entre.**
- Dans le mois en cours des cartes d'achats.
- Au stock, sous la forme d'un retour au fournisseur, au coût de sa ligne d'achat, gardé dans le mouvement.

**Conséquence.** La version du mois le voit. Il ne change pas le passé.

### 4.7 L'inventaire d'ouverture

**Ce qu'il fait.**
- Il se valide seulement jusqu'à la première vente du magasin.
- Son mouvement est daté de sa validation et garde son coût.
- Un inventaire validé ne se modifie ni ne se supprime (`InventaireOuverturePolicy.php` l. 46-58).

**Où il n'entre pas.** Dans aucune carte d'achats.

**Son seul lien avec un cas difficile.** Un produit né sans suivi du stock prend son coût sans mouvement (`ConstructeurLigneInventaire.php` l. 119-129). C'est l'origine du cas limite décrit au § 4.4.

### 4.8 La cession d'un magasin et son annulation

**Les deux mouvements.**
- La cession fait sortir tout le stock au coût moyen du moment, avec un mouvement daté de la cession (`FermerMagasinAction.php` l. 303).
- L'annulation est une procédure de l'éditeur, possible seulement si rien n'a suivi la cession. Elle fait rentrer le même stock au même coût, avec un mouvement daté de l'annulation (`AnnulerCessionAction`).

**Pourquoi le passé ne change pas.**
- Le magasin cédé reste compté.
- Il ne valide, ne corrige ni n'émet plus aucun achat ni avoir (`verifierQuIlEstOuvert()`).

### 4.9 L'ajustement du stock par un document

**Aujourd'hui.** Le seul ajustement est celui de la correction d'un achat, dont la source est la facture d'achat.

**À l'étape 7.** Les fiches de comptage et de casse écriront des mouvements datés de leur validation, au coût moyen du moment. Elles ne changeront pas le passé. Leurs tables devront entrer dans la version du produit, ce qui est automatique si cette version contient `dernier_mouvement_stock_id`.

### 4.10 Les noms actuels : produit, fournisseur, magasin

**D'où vient chaque nom.**
- L'écart des prix lit `produits.nom`.
- Le classement des fournisseurs lit `fournisseurs.nom`.
- Dès que plusieurs magasins sont comptés, les deux cartes lisent `magasins.nom`.

**Effet d'un renommage.** Il change l'étiquette de tous les mois passés et l'ordre des égalités.

**Parade.** On les relit à chaque affichage, en une petite requête. Ils ne vont jamais dans une entrée brute.

### 4.11 Le fournisseur d'un produit, celui de son dernier achat

**Aucune carte ne le lit.**
- « Achats par fournisseur » compte le fournisseur de chaque facture, jamais `produits.fournisseur_id`.
- La correction d'un achat le change (`rattacherAuFournisseur`), sans effet sur ces cartes.

### 4.12 Le mois en cours est celui de chaque magasin

**Le décalage.**
- Dans l'espace de la société, du cabinet et de l'expert-comptable, l'écran suit Paris (`PerimetreDesGraphiques` l. 76, 151 et 190).
- Chaque document est pourtant rangé au jour de son magasin (`JoursDesMagasins`).
- La Martinique et la Guadeloupe ferment leur mois 5 h (heure d'hiver) ou 6 h (heure d'été) après Paris. La Réunion le ferme 3 h ou 2 h avant.

**Conséquence.** On ne peut jamais tenir un mois pour clos d'après le calendrier de l'écran. Le « mois toujours recalculé » est le mois en cours **de chaque magasin**.

**Le territoire d'un magasin ne change plus une fois un document numéroté.**
- La numérotation refuse un magasin sans territoire (`DocumentNumberingService.php` l. 93).
- La correction du territoire par l'éditeur n'est possible qu'avant.
- Aucune donnée en cache ne peut donc changer de fuseau.

### 4.13 Le périmètre et les droits

**À chaque requête.**
- On relit qui voit quoi : `PerimetreDesGraphiques` et `Perimetre::exiger()`. Ce n'est jamais mis en cache.
- Le contrôle passe **avant** toute lecture du cache, comme il passe aujourd'hui avant toute lecture de la base.

**Les entrées.**
- Chaque entrée concerne un seul magasin et une seule source.
- Elle ne contient que des données que toute personne qui voit cette source dans ce magasin voit aussi.
- Elle peut donc servir à plusieurs comptes, mais seulement après leur propre contrôle.

**Ce qui ne va jamais en cache.**
- Une entrée qui réunit plusieurs magasins.
- Une carte assemblée.
- La phrase grise des magasins non comptés.
- Le suffixe « · magasin ».

**Conséquence.** Un droit retiré prend effet à la requête suivante, et rien d'un autre magasin, d'une autre société ou d'un autre rôle ne peut sortir du cache.

### 4.14 Lire versions et données dans un même instantané

**La course à éviter.** Supposons qu'une validation s'enregistre entre la lecture des données et celle de la version. Des données anciennes se retrouvent rangées sous une version nouvelle, et elles sont servies jusqu'au changement suivant.

**La règle.** Lire les versions puis les données dans **une seule transaction en lecture seule, en `REPEATABLE READ`**, ou au minimum toujours lire la version d'abord.

**Défaut qui existe déjà.** `StockDuTableauDeBord::historique()` lit les produits, le grand livre et les réceptions en trois requêtes séparées, en `READ COMMITTED` (l. 108-112).
- Une vente qui s'enregistre entre les deux premières fausse le stock de départ : « stock − somme du grand livre » ne vaut plus 0.
- Une validation qui s'enregistre entre la deuxième et la troisième fait passer sa réception pour une réception « sans stock ».

Sans cache, le rechargement suivant corrige l'erreur. Avec un cache, elle resterait affichée. L'instantané corrige les deux.

### 4.15 La version porte sur le contenu, y compris pour une mise à jour qui garde la date

**Pourquoi pas `updated_at`.**
- Il s'écrit à la seconde (`Illuminate/Database/Grammar::getDateFormat()` = `'Y-m-d H:i:s'`). Deux corrections dans la même seconde laissent sa valeur la plus grande inchangée.
- Il vient de l'horloge du serveur PHP.
- Il ne bouge pas quand seules les lignes changent.

**Pourquoi pas « nombre + plus grand identifiant », ni « nombre + somme des identifiants ».**
- Ils ne voient pas une correction du total sur place : le nombre et les identifiants restent les mêmes.
- Ils ne voient pas l'échange de deux achats entre deux mois.

**Ce qui marche.**
- Une empreinte du contenu lu : un md5 de `string_agg` ordonné par identifiant, sur exactement les colonnes et les filtres de la lecture.
- Ou un compteur tenu en base par des déclencheurs, qui voit aussi une requête directe.

### 4.16 La clé du cache : base, site, code, réglages

**Les copies de démonstration.**
- Dans une copie, le cache par défaut est la base de la copie (`config/cache.php` l. 18 et 37-55) : c'est bien.
- Avec un Redis ou un fichier partagés, toutes les copies d'un même modèle ont les **mêmes** identifiants de magasin et de produit. La clé doit alors porter le nom de la base, ou la référence de la copie.

**La restauration d'une sauvegarde et `migrate:fresh`.**
- Les deux remettent les identifiants en arrière.
- Une version faite de compteurs, comme `dernier_mouvement_stock_id`, pourrait retrouver une valeur déjà vue, avec un autre contenu.
- Deux solutions : garder ce cache dans la base, qui est restaurée avec elle, ou ajouter à la clé une « génération de la base » tirée à chaque recréation.
- Une empreinte du contenu, elle, ne pose aucun problème en cas de restauration.

**Le code.** Le commit déployé fait partie du préfixe : un calcul changé ne réutilise jamais un ancien résultat. GRAPH-AN change justement le calcul.

**Les réglages.** Période, découpage, cumul, comparaison, fin d'exercice, magasin filtré et mesure ne vont que dans l'assemblage, jamais dans les entrées brutes.

### 4.17 Suppression et archivage

**Ce qui peut être supprimé.**
- Seul un brouillon se supprime, et un brouillon n'est jamais compté (`FactureAchat::delete()` l. 129-144).
- Les lignes d'une facture validée sont effacées à chaque correction : l'empreinte le voit.
- Ni une facture validée, ni un avoir émis, ni un mouvement ne se suppriment.

**L'archivage.**
- Archiver un produit ou un fournisseur ne change aucun montant.
- Les deux restent nommés et comptés : `DB::table` et `withTrashed` les gardent.

### 4.18 Tâches planifiées et importations

**Les tâches planifiées.**
- Aucune n'écrit dans les achats ni dans le stock.
- La vérification de chaque nuit ne fait que lire (`VerificateurDuStock`).
- Les écouteurs du stock sont synchrones, dans la transaction du document : aucun mouvement n'arrive plus tard.

**Les importations.**
- Aucune n'existe aujourd'hui.
- SUPER PDP (étape 2) et l'API (étape 10) valideront des factures datées du passé. C'est le cas du § 4.1.

### 4.19 Compte retiré dont le nom reste figé

`cree_par_nom` n'est lu par aucune de ces cartes. Le point concerne « Ventes par vendeur » du lot GRAPH-AN, côté ventes.

### 4.20 Magasin fermé ou cédé

**Pendant la fermeture.**
- Le magasin reste dans le périmètre (`magasinsDeSaPortee()`).
- Ses données sont figées : plus de validation, de correction ni d'avoir.

**À la réouverture.** Les écritures reprennent, et les versions les voient.

### 4.21 Changement d'un réglage fiscal ou de la société

**Ce qui ne touche que l'assemblage ou la seule TVA.**
- La franchise et le régime ne touchent que la TVA.
- Le mois de fin d'exercice ne change que les bornes de la période et les barres par exercice.
- Le type d'activité est définitif.

**Ce qui ne joue pas.** Le suivi du stock coché ou décoché sur une fiche ne change pas le rejeu, sauf par le cas du § 4.4.

### 4.22 Ce que change le lot GRAPH-AN

**La granularité.** Elle devient le jour rangé par mois (`SommesParJour`). Les barres de jour, de mois, de trimestre ou d'année, le cumul et le détail se recomposent tous depuis les sommes par jour. La valeur du stock vient de `HistoriqueDuStock`, sans période, donc l'entrée par produit sert à toutes les barres.

**Ce qui dépend du jour même.** L'écart sur un an d'une barre en cours compare aux mêmes jours de l'an dernier (`Barre::unAnPlusTot()` l. 59-71). Il se calcule à l'affichage.

**Où le cache rapporte le plus.** Le découpage par année lit **10 ans** de chaque source.

**Une vérification gratuite.** La liste d'un jour (`ListesDesJours`) relit la base : un chiffre de barre faux s'y verrait aussitôt.

---

## 5. Les versions concrètement

### 5.1 Achats, par (magasin, mois)

Pour les cartes 3.1, 3.2, 3.7 et 3.8, une seule requête par source rend l'empreinte de chaque mois de la fenêtre.

```sql
SELECT magasin_id,
       to_char(date_facture, 'YYYY-MM') AS mois,
       md5(string_agg(concat_ws(':', id, date_facture, date_validation, total_ht, total_tva, total_ttc, fournisseur_id), ',' ORDER BY id)) AS empreinte
FROM factures_achat
WHERE magasin_id = ANY(:magasins) AND statut = 'validee' AND date_facture BETWEEN :du AND :au
GROUP BY 1, 2;
```

**Les cas qui demandent un mois « large ».** Une facture sans date et un avoir fournisseur (`date_emission`) entrent dans l'empreinte de chaque mois que touche leur instant, la veille et le lendemain compris (`date_validation BETWEEN :debut - 1 jour AND :fin + 1 jour`). Ce n'est jamais un mois de moins que celui où le calcul en PHP les range.

**La règle de cohérence.** La liste des colonnes de l'empreinte est exactement celle de la lecture. On les tient dans une seule constante, partagée par les deux.

### 5.2 Lignes d'achat de l'écart des prix, par (magasin, mois)

C'est la même requête, cette fois sur `lignes_achat` jointe à `factures_achat`.

- **Colonnes des lignes** : id, produit, quantité, PU HT, remise, HT saisi.
- **Colonnes de la facture** : id, date, date de validation, rang, escompte.
- **Ce qui n'y figure pas** : le nom, qui se relit.

### 5.3 Stock, par produit

On lit `produits.dernier_mouvement_stock_id`, `stock_quantite` et `cmup_ht`, qui viennent de la même ligne que celle que le rejeu lit déjà. On ajoute l'empreinte des réceptions de chaque produit :

```sql
SELECT l.produit_id,
       md5(string_agg(concat_ws(':', l.id, l.quantite, l.prix_unitaire_ht, l.remise_pourcentage, l.montant_ht_saisi, l.ordre, f.id, f.rang_validation, f.repere_grand_livre, f.escompte_pourcentage), ',' ORDER BY f.rang_validation, l.ordre, l.id))
FROM lignes_achat l
JOIN factures_achat f ON f.magasin_id = l.magasin_id AND f.id = l.facture_achat_id
WHERE l.magasin_id = ANY(:magasins) AND f.statut = 'validee'
GROUP BY l.produit_id;
```

On ne relit et ne rejoue que les produits dont la version a changé (`WHERE produit_id = ANY(:changes)`).

### 5.4 Solution plus robuste encore

On peut ajouter une table de versions tenue par des déclencheurs, par (magasin, source, mois) et par produit, sur `factures_achat`, `lignes_achat`, `avoirs_fournisseur`, `mouvements_stock` et `produits`.

**Avantages.** Elle voit toute écriture, même une requête directe, et se lit en O(1).

**Ce qu'elle impose.**
- Elle doit avancer à la fois l'ancien et le nouveau mois.
- Elle doit compter les mois larges.
- Elle a besoin, comme toute version faite de compteurs, de la « génération de la base » (§ 4.16).

L'empreinte du contenu suffit pour les achats, qui ont peu de lignes. On ne passerait aux déclencheurs que si les empreintes des ventes se révélaient lentes.

---

## 6. Garde-fous à livrer avec le lot

1. **Test d'inventaire des écritures.** Pour chaque geste qui écrit ces tables, un test vérifie que la version des mois ou des produits touchés change, et que la carte rendue par le cache égale celle d'un calcul sans cache. Les gestes à couvrir :
   - validation d'un achat, y compris avec une date passée ou future ;
   - correction d'un achat : total, date, fournisseur, escompte, une ligne seule, produit d'une ligne, produit retiré, produit ajouté ;
   - avoir fournisseur ;
   - inventaire d'ouverture ;
   - cession, et annulation de cession ;
   - renommage d'un produit, d'un fournisseur ou d'un magasin ;
   - suivi du stock coché ou décoché ;
   - une vente sans stock et le cas limite du § 4.4.

   Comme `InventaireDesActionsTest`, ce test échoue si une action nouvelle écrit l'une de ces tables sans y figurer.
2. **Test de course.** Une validation s'enregistre pendant le calcul ; le cache ne doit jamais garder l'ancien résultat sous la nouvelle version.
3. **Contrôle de chaque nuit**, à côté de `app:verifier-isolation`. On recalcule sans cache un échantillon d'entrées. Au moindre écart, on prévient l'éditeur et on vide le cache.
4. **Durée de vie bornée** des entrées, par exemple 30 jours, et cache vidé à chaque déploiement, puisque le commit fait partie de la clé.
