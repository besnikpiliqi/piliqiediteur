# Contre-épreuve : le cache des cartes peut-il montrer un chiffre faux ?

23/11/2026. J'ai attaqué la conception de `synthese.md` en relisant le code :
- la branche de travail (36b2388c) ;
- GRAPH-AN (0257979e, copie `wt-demo`) ;
- TVA27 (fbdf759a, copie `wt-tva`).

Je n'ai rien modifié et je n'ai lancé aucun test. La base de test ne m'était pas ouverte : son mot de passe n'est pas dans mon environnement. Ce que je dis de PostgreSQL vient donc de ses règles documentées, sans essai. Un seul contrôle a été exécuté : la version des fuseaux horaires que lit PHP sur cette machine (C4).

## En bref

- **Quatre scénarios réels :**
  - deux montrent un chiffre faux en production (S1, S2) ;
  - un troisième en montre un après une mise en ligne mal faite (S3) ;
  - le quatrième fausse les tests (S4).
- **Six contraintes** sont à écrire dans les lots. Aucune ne donne un chiffre faux aujourd'hui. Chacune, oubliée, en donnerait un ou casserait l'affichage.
- **Tout le reste tient**, vérifié dans le code :
  - les ventes, les paiements et les imputations d'avoir sont bien en ajout seul en base ;
  - les droits sont relus à chaque affichage ;
  - minuit, l'outre-mer et le changement d'heure sont couverts par le numéro de version ;
  - une autre société, un autre magasin ou une autre copie ne peut pas lire une partie.
- **Ta question sur l'« Écart des prix d'achat » :** la réponse de la synthèse est juste. Le § 3 la montre sur un exemple chiffré.
- **Verdict :** le cache est sûr si l'on applique les corrections de S1 à S4 et les six contraintes. Le contrôle de nuit reste le filet : il trouverait S1, S2 et S3 en moins d'un jour, mais il ne doit pas tenir lieu de correction.

## 1. Les scénarios réels

### S1. La double lecture ne prouve rien pour les achats

**Ce que dit la synthèse** (§ 2.5, étape 4, et § 2.12). On lit le numéro de version du mois, puis ses données, puis de nouveau son numéro. S'ils sont égaux, rien n'aurait changé, « puisque ces numéros ne reviennent jamais en arrière ».

**Pourquoi c'est faux pour les achats.** La phrase est vraie d'une ligne de la base : chaque écriture lui donne une nouvelle version. Elle est fausse d'un mois : une facture peut y entrer puis en sortir, et le mois retrouve alors exactement son état d'avant, donc son numéro d'avant.

Dans le code :
- une facture d'achat validée ne revient jamais au brouillon (`StatutFactureAchat::transitionsAutorisees()`) ;
- mais sa date se corrige après la validation : `ModifierFactureAchatAction` remplit l'en-tête de la facture validée, et `date_facture` figure dans `CHAMPS_AUDITES` ;
- et c'est cette date qui choisit le mois (`AchatsDuTableauDeBord::documents()` et `lignes()`).

**Étapes.**
1. Mars 2026 est passé. Une facture F, encore brouillon, porte la date du fournisseur 10/03/2026.
2. Un affichage lent commence : le premier après une mise en ligne, avec le report de TVA sur plusieurs années, donc toutes les parties à refaire. Il lit les numéros de tous les mois. F n'est pas dans mars.
3. Le propriétaire valide F : elle entre dans mars.
4. L'affichage lit les achats de mars : F y est.
5. Quelqu'un corrige la date en 10/04/2026 : F quitte mars.
6. L'affichage relit le numéro de mars. C'est celui de l'étape 2, puisque mars a retrouvé ses lignes d'avant. Il range donc mars **avec F**.

**Effet visible.** F compte deux fois : en mars, par le cache, et en avril, recalculé parce que son numéro a changé. Son HT et sa TVA déductible comptent donc deux fois dans :
- « Ventes et achats » et la TVA à payer ;
- le crédit reporté de toutes les périodes qui suivent (TVA27) ;
- « Achats par fournisseur » et l'écart des prix ;
- le chiffre clé des achats de l'année suivante.

L'erreur dure jusqu'au prochain changement de mars, jusqu'au contrôle de nuit, ou 40 jours au plus.

**Probabilité.** Faible : il faut deux enregistrements sur la même facture pendant un seul affichage. Elle monte avec un affichage lent ou avec l'API de l'étape 10.

Les ventes, les paiements et les imputations n'ont pas ce défaut : rien n'en sort jamais d'un mois.

**Correction.** Ne plus relire avant et après : lire les numéros et les données dans un seul instantané de la base.
- En production, quand aucune transaction n'est ouverte, la lecture des cartes ouvre une transaction en lecture seule, de niveau `REPEATABLE READ`. Le numéro rangé est alors exactement celui des données lues.
- L'écriture du cache se fait après la fin de cette transaction.
- Dans un test, la transaction du test suffit : il n'y a qu'une connexion, donc rien à protéger. L'objection de la synthèse, « dans les tests, l'instantané est impossible » (§ 2.12), ne tient donc pas.

Effet utile : le défaut des trois lectures séparées du stock (§ 6 de la synthèse) disparaît du même coup.

### S2. Lot 3 : une régularisation d'export change la marge sans toucher le produit

**Ce que dit la synthèse** (§ 2.2, § 2.4 et annexe). La partie d'un produit garde son historique rejoué, y compris « le chiffre d'affaires des articles sortis ». Son numéro de version est la version de la fiche du produit, plus celles de ses lignes d'achat et de leurs factures.

**Ce que dit le code.**
- `StockDuTableauDeBord::historique()` compte dans la marge la ligne d'une facture rectificative ou d'un avoir de régularisation, « qui ne bougent pas le stock » : « un export régularisé faute de preuve de sortie déduit de la marge du mois de sa régularisation le HT de son avoir et y ajoute celui de sa rectificative ».
- `RegularisationDUneExoneration` fait un avoir « sans retour en stock » et une rectificative « sans sortie de stock ».
- La fiche du produit ne se réécrit qu'à un mouvement du grand livre (`GardesDuStock`, déclencheur `compter_le_mouvement_de_stock`).

**Étapes.**
1. Un article suivi en stock part à l'export pour 500,00 € HT. La partie de ce produit est en cache.
2. 150 jours plus tard, sans preuve de sortie, le magasin régularise (`RegulariserUneExonerationNonProuveeAction`) :
   - un avoir de 500,00 € HT ;
   - une rectificative de 500,00 € TTC à 20 %, soit 416,67 € HT.
3. Aucun mouvement de stock : ni la fiche du produit ni ses achats ne changent, donc son numéro non plus.

**Effet visible.** La marge du mois de la régularisation reste trop haute de 83,33 €, son taux de marge aussi. L'erreur dure jusqu'au prochain mouvement du produit, jusqu'au contrôle de nuit, ou 40 jours au plus. La procédure de correction du SIREN (avoir de régularisation et facture rectificative) a le même défaut.

**Correction.** Deux façons :
- ajouter au numéro de version d'un produit ses lignes de vente et d'avoir numérotées : le nombre, la somme et le plus grand des identifiants de ses lignes de `lignes_facture` et de `lignes_avoir` dont le document a un numéro, qui sont en ajout seul ;
- ou ne pas garder le chiffre d'affaires dans la partie du produit : n'y garder que les coûts et la liste des ventes qui l'ont fait sortir, et prendre le chiffre d'affaires dans les parties des ventes.

Dans les deux cas, ajouter le cas au § 5.1 (stock et marge) et à la série de changements du test sur tout le semis.

### S3. La version du code dépend d'une ligne du .env écrite à la main

**Ce que dit la synthèse** (§ 2.3 et § 2.10). La version déployée (`PILIQI_VERSION`) change à chaque mise en ligne, donc toutes les clés changent.

**Ce que dit le code.**
- `config/piliqi.php` lit `env('PILIQI_VERSION')`.
- `.env.example` et `.env.demonstration.example` la laissent vide, et le README ne dit pas qui l'écrit.
- Elle ne sert aujourd'hui qu'au pied de page (`VersionServie`).
- Rien ne vérifie qu'elle change, ni qu'elle est la même sur les deux sites, qui ont chacun leur .env.

**Étapes.**
1. Le cache est en ligne.
2. Une correction du calcul part : le jour d'un document dans `JoursDesMagasins`, l'arrondi du coût dans `CalculateurLigne`, ou la règle des libellés.
3. `PILIQI_VERSION` n'est pas changée, ou seulement dans le .env du site réel.

**Effet visible.**
- La correction ne vaut que pour le mois en cours et pour les mois qui changent. Les autres mois gardent jusqu'à 40 jours les chiffres de l'ancien code.
- Le détail d'un jour, lu en direct, contredit alors sa barre.
- En développement, le défaut est le même : l'empreinte par date de modification ne couvre que les fichiers d'une liste. Il suffit d'oublier un fichier : `Calendrier`, `CalculateurLigne`, `Instant`, une énumération (`ModePaiement`, `TypeClient`, `NatureLigne`, `StatutFacture`), ou Carbon.

**Correction.** Une version calculée, jamais écrite à la main :
- le commit déployé et une empreinte de `composer.lock`, écrits par la commande de déploiement dans la configuration mise en cache ;
- sans cette version, pas de cache : les cartes se calculent en direct ;
- en développement et dans les tests, le cache est coupé par défaut : la base y est petite et il n'apporte rien. Il ne s'allume que dans ses propres tests.

### S4. Dans les tests, la version de ligne ne change pas

**Ce que dit la synthèse** (§ 2.5). « Ce contrôle marche de la même façon en production et dans les tests, où tout se passe dans une transaction. »

**Pourquoi c'est faux.** Une ligne écrite deux fois dans la même transaction garde la même version (`xmin`), celle de la transaction.
- Chaque test tourne dans une seule transaction (`RefreshDatabase`).
- Les fabriques et les `DB::table()->update()` du corps d'un test y écrivent tous avec la même version.
- Seule une action qui ouvre `DB::transaction()`, donc un point de sauvegarde, en reçoit une nouvelle.

**Étapes.**
1. Un test crée une facture d'achat par une fabrique.
2. Il affiche le tableau de bord : le cache se remplit.
3. Il change un prix par une requête directe. Le numéro du mois ne change pas.

**Effet.** Le cache sert l'ancien chiffre et le test échoue, alors que la production serait juste. Ce sont de faux échecs, sans aucun chiffre faux en production. Le danger est qu'un lot « répare » le test au lieu de comprendre.

**Correction.**
- Dans ces tests, chaque écriture qui joue « une autre personne » passe par une action ou par `DB::transaction()`.
- Les tests de la version de ligne vont dans le groupe de concurrence, qui valide vraiment ses écritures.

## 2. Six contraintes à écrire dans les lots

**C1. Le cache reste sur la connexion des données.** `config/cache.php` prend la connexion du magasin de cache « database » dans `DB_CACHE_CONNECTION`. Si quelqu'un la remplit, le cache quitte la base des données. Ce qui arrive alors :
1. un `migrate:fresh --seed` en développement redonne souvent les mêmes identifiants, avec des dates décalées ;
2. un mois dont aucun document ne change de mois garde le même nombre, la même somme et le même plus grand identifiant ;
3. le cache sert donc ses anciens chiffres par jour : jours de la semaine, barres par jour, mêmes jours de l'an dernier.

Correction : un magasin de cache propre aux cartes, dont la connexion est écrite dans le code, et un test qui le vérifie.

**C2. L'écriture du cache se fait hors de toute transaction, sans doublon et dans un ordre fixe.**
- `putMany` est un seul `upsert` (`DatabaseStore`, Laravel 13.42).
- La même clé deux fois dans un même lot fait refuser la requête par PostgreSQL.
- Deux affichages qui écrivent les mêmes clés dans deux ordres différents peuvent se bloquer l'un l'autre.
- Dans une transaction, un tel refus annule toute la transaction, et l'attraper en PHP ne suffit pas. C'est le cas dans les tests. Ce serait aussi le cas d'une action qui calculerait la TVA dans sa transaction ; aujourd'hui, `EtatDesCharges::telecharger()` reçoit la TVA déjà calculée, hors de la sienne.
- La lecture écrit aussi : `DatabaseStore::many()` efface les entrées expirées qu'il rencontre. Elle doit donc se faire hors de la transaction en lecture seule de S1, et ne jamais faire échouer l'affichage dans une copie en lecture seule.

Correction : des clés sans doublon et triées, et une écriture seulement quand aucune transaction n'est ouverte, ou dans un point de sauvegarde.

**C3. Le contrôle de nuit et la purge passent aussi dans chaque copie** de démonstration, par `PasserLesTachesDesCopies`, pas seulement sur le site réel et les bases modèles. Le contrôle lit chaque entrée dans un seul instantané, comme en S1 ; sinon, il crie au loup quand quelqu'un écrit pendant qu'il tourne.

**C4. La version des fuseaux horaires ne change jamais sur ce serveur.** Ici, `timezone_version_get()` rend « 0.system » (PHP 8.5.12, base des fuseaux du système ; contrôle exécuté), même après une mise à jour des fuseaux du système. Le risque est négligeable pour la France et l'outre-mer, dont les règles passées ne changent pas, mais le mécanisme annoncé ne marche pas. Correction : lire la version écrite dans `/usr/share/zoneinfo/tzdata.zi`, ou s'en remettre à la version du code de S3.

**C5. Les noms gardés dans le cache.**
- La partie « ventes, vendeurs » garde le nom figé du vendeur, et « ventes, produits » le libellé des lignes.
- Aujourd'hui, la base fige ces textes sur les documents émis (`GardesDesDocumentsEmis` compare toute la ligne) : le cache reste juste.
- Mais c'est une copie de données personnelles hors de la politique de conservation. Si l'étape 9 (RGPD) anonymise un jour un nom sur les documents, le cache le garderait 40 jours.
- Correction : garder l'identifiant du compte et relire le nom, ou vider le cache dans toute procédure d'anonymisation, et citer la table du cache dans l'analyse RGPD.

**C6. La clé porte l'identifiant du magasin, jamais son code** : un code n'est unique que dans sa société.

## 3. Ta question sur l'« Écart des prix d'achat »

La synthèse a raison :
- on garde, pour chaque mois et chaque magasin, la liste brute des achats validés ;
- on refait la carte à chaque affichage depuis les 12 listes.

Les deux affichages et le glissement de la période sont donc justes. Le code le permet sans rien lire de plus : `AchatsParProduit` ne prend jamais un achat d'avant la période comme prix de départ, donc les 12 listes suffisent.

Exemple : un produit acheté 10 unités à chaque date.

| Achat | Prix unitaire | « Un total par mois » | « 12 mois », de janvier à décembre 2026 | « 12 mois », de mars 2026 à février 2027 |
|---|---|---|---|---|
| 5 janvier | 4,00 € | départ de janvier | départ | hors de la période |
| 20 janvier | 4,20 € | +2,00 € | +2,00 € | hors de la période |
| 3 mars | 4,50 € | départ de mars | +5,00 € | départ |
| 10 décembre | 4,40 € | départ de décembre | +4,00 € | −1,00 € |
| **Total** | | **+2,00 €** | **+11,00 €** | **−1,00 €** |

En février 2027, aucun achat n'a changé, et pourtant le total sur 12 mois passe de +11,00 € à −1,00 €. Un résultat gardé serait faux : le total de +11,00 €, ou l'écart de décembre, +4,00 €. Les listes refaites donnent bien −1,00 €.

Le seul vrai risque de cette carte est S1, comme pour tous les achats.

## 4. Ce que j'ai attaqué et écarté

| Attaque | Pourquoi elle échoue (vérifié dans le code) |
|---|---|
| Vente validée juste après minuit, magasin d'outre-mer encore dans son mois, facture émise après le ticket Z | Les ventes sont en ajout seul. `GardesDesDocumentsEmis` refuse de supprimer un document numéroté, et de le modifier hors du statut, du ticket Z et de la date de mise à jour. Elle refuse aussi toute écriture sur ses lignes. Une vente de plus change le nombre du mois. |
| Facture repassée en brouillon, statut changé par le passage en retard | Le statut reste modifiable, mais la synthèse compte par le numéro, avec la contrainte « brouillon si et seulement si sans numéro ». `EmettreFactureAction` pose le numéro, le statut et la date en un seul enregistrement : la contrainte ne gêne pas l'émission. |
| Paiement antidaté, contre-passation, crédit rendu | `paiements` et `avoir_allocations` sont en ajout seul (`DeclencheursDeProtection::ajoutSeul`). Une contre-passation est un nouveau paiement négatif, au jour où elle est faite (`ContrePasserPaiementAction`). Un crédit rendu est une imputation négative, jamais une modification. |
| Lignes d'avoir jointes à la vente d'origine (service, vendeur, facture rectifiée) | Les lignes et les documents d'origine sont figés dès leur numéro. |
| Minuit, changement d'heure, arrondi d'un horodatage | `Instant` écrit à la seconde (« Y-m-d H:i:s ») : PostgreSQL n'arrondit jamais une heure au jour suivant. Les bornes des mois viennent de `Calendrier`. Un minuit ne tombe jamais dans l'heure qui change, et l'outre-mer n'a pas d'heure d'été. |
| Horloges des serveurs décalées : un mois encore ouvert rangé trop tôt | Le numéro de version est relu à chaque affichage, et une vente de plus le change. Cela coûte un calcul de plus, jamais un chiffre faux. Ne jamais ranger le mois en cours est une économie, pas une sécurité. |
| Territoire d'un magasin corrigé | La correction n'est possible que tant qu'aucun document du magasin n'est numéroté (`colonneFigee` sur `magasins.territoire`). Le territoire est en plus dans la clé. |
| Droits : case décochée, lien du cabinet terminé, déclaration retirée, portée réduite | `PerimetreDesGraphiques` se refait à chaque requête, et `Perimetre::exiger()` refuse un magasin hors du périmètre. Chaque source exige de voir tous les documents du magasin (`SourceDesGraphiques::autorisations()`), et une partie ne garde rien par compte. |
| Autre société, autre magasin | Les identifiants des magasins sont uniques dans la base, et toutes les jointures se font par le magasin et l'identifiant. Aucune somme de plusieurs magasins n'est gardée. |
| Deux copies du même modèle, avec les mêmes identifiants et les mêmes versions de ligne | Chaque copie a sa base. `OuvertureDUneCopie` passe le cache sur la base de la copie, et `BrancherUneBase` oublie la connexion et les caches ouverts avant. Chaque copie reçoit une base au nom neuf, même refaite (`CopiesDeLaDemonstration`). |
| Base modèle | Sa table de cache est vidée à l'étape 8 de `ConstruireUnModele`. Ce qui y serait écrit ensuite reste vrai pour une copie à sa naissance. |
| Restauration, serveur de secours, `VACUUM FREEZE`, réécriture d'une table, sauvegarde rechargée | Soit le cache revient au même instant que les données, soit les versions changent et tout se recalcule. Depuis PostgreSQL 9.4, figer une ligne garde sa version. Aucun de ces cas ne donne une fausse égalité. |
| Écritures du cache dans le désordre : un vieil affichage écrit après un récent | La version rangée est comparée à chaque affichage à la version de la base : au pire, un recalcul. |
| Retour à une version précédente du logiciel | Ses anciennes entrées gardent leur version : elles ne servent que si les données n'ont pas bougé. |
| Régime, franchise, cadence, fin d'exercice, assujetti unique, crédit déclaré | Jamais en cache. Les parties sont des sommes de documents qui n'en dépendent pas. |
| Charges | Jamais en cache. |
| Tâches planifiées (`bootstrap/app.php`) | Aucune ne crée ni ne change un document compté : le passage en retard ne touche que le statut, la clôture que le lien au ticket Z. La régularisation d'un export est un geste d'une personne (S2). |
| Démonstration : bandeau « Vous êtes : », « Recommencer à zéro », copie en lecture seule | Changer de compte change aussi le périmètre. « Recommencer à zéro » donne une base neuve. Pour la lecture seule, voir C2. |
| Avoir fournisseur dont la facture d'achat change de fournisseur | La version du mois de l'avoir contient la version de sa facture : la synthèse l'a vu. |
| Achat corrigé au lot 3 : une ligne passe d'un produit à un autre | La correction réécrit la fiche de chaque produit touché (un mouvement, ou un nouveau coût moyen), et cette version ne fait qu'avancer. |

## 5. Ce que la synthèse a laissé de côté, sans chiffre faux

- **Le gain sur les achats est surestimé.** La version de ligne n'est dans aucun index : la calculer oblige à lire toutes les lignes d'achat du mois. Pour le report de TVA sur des années, cela coûte presque autant que la lecture d'aujourd'hui. À mesurer à la campagne de tests ; sinon, une version par magasin tenue par la base.
- **D'autres lectures comptent les factures par leur statut**, comme `SeuilDuTrimestriel` (TVA27). Elles doivent passer au « numéroté » en même temps que les cartes, pour dire le même chiffre.
- **La liste des lectures hors du magasin courant** (CLAUDE.md, « Produit ») doit citer la table du cache : l'espace de la société y lit des parties de plusieurs magasins.
- **Tests à ajouter :**
  - la régularisation d'un export (S2) ;
  - une facture qui entre dans un mois puis en sort pendant un affichage, dans le groupe de concurrence (S1) ;
  - le magasin de cache sur la connexion des données (C1) ;
  - des clés en double dans une même écriture (C2) ;
  - le contrôle de nuit dans une copie (C3).

## 6. Verdict

Avec les corrections de S1 à S4 et les six contraintes, je ne trouve plus aucun cas où le cache montrerait un autre chiffre que le calcul direct, quels que soient :
- le compte, la société, le magasin ou la copie ;
- l'heure : minuit, outre-mer, changement d'heure ;
- un changement de réglage ou de droits ;
- une suppression, une correction, une tâche planifiée ou un geste dans une démonstration ;
- une mise en ligne.

Sans ces corrections :
- sans S1, un achat compté deux fois reste rare, mais peut tenir jusqu'au contrôle de nuit ;
- sans S2, le lot 3 se tromperait de marge à chaque régularisation d'export ;
- sans S3, une mise en ligne peut garder les anciens chiffres 40 jours.

Il reste le risque que la synthèse place en tête : une source ajoutée plus tard à une carte sans être ajoutée à son numéro de version. Il est couvert par :
- la règle à ajouter au protocole ;
- le test d'inventaire ;
- le test sur tout le semis ;
- le contrôle de nuit.
