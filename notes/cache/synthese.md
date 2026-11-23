# Cache des cartes du tableau de bord : synthèse

23/11/2026. Ce document réunit les quatre analyses du même dossier (`analyse-ventes.md`, `analyse-achats.md`, `analyse-tva.md`, `analyse-mecanique.md`). Là où elles divergeaient, j'ai tranché en relisant le code. Le code lu est celui de la branche de travail (36b2388c), de GRAPH-AN (0257979e, copie `wt-demo`) et de TVA27 (fbdf759a, copie `wt-tva`). Je n'ai rien modifié dans le dépôt et je n'ai lancé aucun test.

Trois mots reviennent dans tout le document :

- une **partie** : ce que le cache garde pour un magasin et un mois, par exemple « les ventes de chaque jour de janvier 2026 au magasin de Lyon ». C'est toujours une somme en centimes, jamais le résultat d'une carte ;
- un **numéro de version** (ou empreinte) : un petit code calculé depuis les données d'un mois. Il change dès que l'une d'elles change. Il est relu à chaque affichage ;
- le **mois local** : le mois dans le calendrier du magasin. Le 1er décembre à 2 h à Paris, c'est encore le 30 novembre à Fort-de-France.

## En bref

1. **Ta proposition** garde les années et les mois anciens « parce qu'ils ne changent plus », et donne un numéro de version aux 3 à 6 derniers mois. Elle est juste pour les ventes. Elle est fausse pour tout le reste :
   - une facture d'achat se date du jour choisi par le fournisseur, et se corrige après sa validation ;
   - une charge se dépose pour un mois ancien et se corrige sans limite de temps ;
   - un paiement se saisit avec une date de réception passée, même ancienne ;
   - une correction d'achat change la valeur du stock et la marge de tous les mois qui suivent ;
   - le crédit de TVA reporté de TVA27 enchaîne tous les mois depuis l'arrivée de la société.

   Une année finie peut donc encore changer. Gardée sans numéro de version, elle resterait fausse pour toujours.
2. **Ma position est confirmée.** Chaque mois de chaque magasin a son numéro de version, pour chaque source de données, quel que soit son âge. Ce numéro est relu à chaque affichage, par une requête rapide : 7,7 ms pour 286 mois de ventes sur la base semée. Un mois ancien reste en cache tant que ses données ne bougent pas. Un changement tardif ne fait recalculer que le mois touché. Le mois en cours de chaque magasin se calcule toujours en direct.
3. **On ne garde jamais le résultat d'une carte.** On garde des sommes par magasin et par mois, et, pour le stock, par produit. Chaque carte se recalcule à chaque affichage depuis ces sommes. Les droits, les noms et la date du jour sont lus en direct. Un chiffre d'un autre rôle ou d'une autre société ne peut donc pas sortir du cache : rien n'y est rangé par compte ni par société, et les droits sont contrôlés avant toute lecture.
4. **Écart des prix d'achat** : on garde la liste brute des achats de chaque mois. Les deux affichages se refont à chaque fois depuis les 12 listes, en mémoire : chaque mois comparé à son premier achat, ou les 12 mois comparés au premier achat de la période. Voir le § 3.
5. **Jamais en cache** :
   - les factures à encaisser et le restant dû ;
   - les charges ;
   - le crédit de TVA déclaré et le report ;
   - les droits, les noms et les réglages ;
   - tout ce qui dépend du jour.
6. **Travail.** Trois lots, plus quelques prérequis courts :
   - ventes ;
   - achats, écart des prix et TVA ;
   - stock et marge, le plus délicat.

   **Gain.** Il est grand pour les longues périodes : le report de TVA relit aujourd'hui toutes les ventes depuis l'arrivée de la société, le découpage par année lit dix ans. Il est peu visible sur la base semée, qui a peu de documents, et nul pour les cartes « à ce jour ».
7. **À trancher par toi** :
   - le principe ;
   - l'ordre des lots ;
   - l'affichage des paiements saisis avec une date passée ;
   - hors cache, trois points du report de TVA de TVA27.

## 1. Les cartes une par une

Le risque se lit ainsi :

- **aucun** : aucun cas ne peut donner un chiffre faux ou celui d'un autre, et la justesse ne dépend d'aucun détail d'écriture du code ;
- **faible** : aucun cas connu non plus, mais la justesse dépend d'un point d'écriture du code, par exemple un numéro de version qui couvre toutes les tables lues ou une règle de libellé écrite. Des tests précis et un contrôle de nuit le vérifient (§ 5 et § 6).

Trois cas reviennent dans le tableau sous le nom de **ventes tardives du dernier jour** :

- une vente horodatée à 23 h 59 et validée juste après minuit ;
- un magasin d'outre-mer encore dans son mois quand Paris est au mois suivant ;
- une facture à un professionnel émise après le ticket Z du jour, ce que le code permet jusqu'à minuit.

Le numéro de version voit ces trois cas.

| Carte | Ce qui peut changer son passé | Ce qu'on garde | Risque |
|---|---|---|---|
| Chiffre d'affaires HT par mois ; avec GRAPH-AN, aussi par trimestre ou par année, avec cumul, écart, tendance et détail des jours | Rien dans les ventes : une vente émise ne change plus, et une correction est un avoir daté du jour où il est émis. Seulement les ventes tardives du dernier jour. Le mois de fin d'exercice change le regroupement (exercices, cumul), jamais les sommes. | Les sommes de chaque jour, par magasin et par mois. Trimestres, années, exercices, cumul, écart et tendance se refont à chaque affichage. | aucun |
| Chiffre d'affaires HT par an (branche de travail ; GRAPH-AN le remplace par « Découper par : année ») | Comme ci-dessus. Un changement du mois de fin d'exercice regroupe autrement les mêmes mois. | Les mêmes sommes. Une année n'est jamais gardée telle quelle. | aucun |
| Chiffre d'affaires par magasin | Comme ci-dessus. Un magasin renommé, fermé, cédé ou rouvert change l'étiquette et l'ordre, pas les sommes. | Les sommes. Les étiquettes « (fermé) » et « (cédé) » et l'ordre sont relus. | aucun |
| Ventes et achats HT par mois | Ventes : comme ci-dessus. Achats : une facture validée aujourd'hui avec une date ancienne du fournisseur ; une facture validée puis corrigée (montants, lignes, date qui la fait changer de mois) ; un avoir fournisseur. | Les sommes de chaque jour, ventes et achats à part. | faible |
| TVA à payer par mois, réelle et estimée ; avec TVA27, le crédit reporté | Tout ce qui change les ventes et les achats. Toute charge : déposée pour un mois ancien, chiffres saisis, renvoyée, validée, corrigée, mois de TVA changé ou supprimée. Le crédit déclaré, modifié ou corrigé. Le régime. Un mois ancien changé change le report de toutes les périodes qui suivent. | La TVA de chaque jour des ventes et des achats. Les charges, le crédit déclaré et le régime sont lus en direct. Le report est recalculé à chaque affichage. | faible |
| Marge brute HT par mois | Une réception corrigée change le coût de toutes les ventes du produit qui la suivent. Tout nouveau mouvement de stock. Le coût moyen actuel du produit sert de point de départ au calcul (cas rare). | Une entrée par produit : tout son historique rejoué, avec son propre numéro de version (lot 3). | faible |
| Ventes par taux de TVA | Rien : les lignes sont figées. Seulement les ventes tardives du dernier jour. | Le HT net par taux, par mois. | aucun |
| Particuliers et professionnels | Rien : le type du client est figé sur le document. | Le HT net par type de client, par mois. | aucun |
| Encaissements par mode de paiement | Un paiement saisi aujourd'hui avec une date de réception passée, même d'il y a un an ; une contre-passation ; un crédit d'avoir imputé juste après minuit. | Le montant par mode et le crédit d'avoir utilisé, par mois. | aucun |
| Meilleurs produits | Rien dans les montants. Mais le libellé d'un produit renommé et l'ordre de deux produits à égalité dépendent aujourd'hui de l'ordre dans lequel la base rend les lignes. | Par mois : le HT et la quantité de chaque produit ou service, avec son libellé et la date de ce libellé. Le classement se refait. | faible ; la règle du libellé est à écrire avant (§ 8) |
| Meilleurs clients | Rien dans les montants. Le nom actuel du client, s'il est renommé ou archivé. | Le HT de chaque client, repéré par son identifiant. Les noms sont relus et le classement refait. | faible ; le départage des égalités est à écrire |
| Ventes par vendeur (GRAPH-AN) | Rien : les noms sont figés. Un avoir se déduit du vendeur de la vente d'origine, dans le mois de l'avoir. | Le HT de chaque compte, avec son nom figé et la date de ce nom. | faible ; la règle du libellé est à écrire |
| Achats par fournisseur | Un achat validé tard avec une date ancienne ; une correction (montants, fournisseur, date) ; un avoir fournisseur ; un fournisseur renommé. | Le HT de chaque fournisseur, par mois. Les noms sont relus. | faible |
| Factures à encaisser par ancienneté | Chaque paiement, imputation ou contre-passation ; le passage en retard ; chaque jour qui passe. | Rien : relu à chaque affichage. | aucun |
| Valeur du stock au coût moyen | Une réception corrigée (la valeur change depuis la réception) ; chaque mouvement ; une cession et son annulation. | Une entrée par produit (lot 3). | faible |
| Nombre de ventes et panier moyen | Seulement les ventes tardives du dernier jour. | Le nombre et le TTC de chaque jour. Le panier moyen est recalculé, jamais gardé. | aucun |
| Avoirs émis par mois | Un avoir validé juste après minuit. | Le HT et le nombre de chaque jour. | aucun |
| Ventes par jour de la semaine | Les ventes tardives du dernier jour. Le nombre de lundis jusqu'à aujourd'hui change chaque semaine. | Les sommes de chaque jour. Le nombre de chaque jour de la semaine se compte à l'affichage. | aucun |
| Articles et services | Rien : la nature de chaque ligne est figée. | Le HT des articles et celui des services, par mois. | aucun |
| Écart des prix d'achat, « un total par mois » | Un achat validé tard avec une date ancienne ; une correction (prix, quantité, remise, escompte, date, ligne retirée ou ajoutée) ; un produit ou un magasin renommé. | La liste brute des achats de chaque mois. Le calcul est refait à chaque affichage. | faible |
| Écart des prix d'achat, « un seul total pour les 12 mois » | Tout achat des 12 mois. Le glissement de la période chaque mois, qui change le premier achat de chaque produit. | Rien de plus : le total est recalculé depuis les 12 listes. | faible |
| Chiffre clé « Chiffre d'affaires HT » et sa variation sur un an | Le mois en cours change à chaque vente. L'an dernier ne change que par les ventes tardives. | Le mois en cours est en direct. L'an dernier vient des sommes de chaque jour, jusqu'au même jour. | aucun |
| Chiffre clé « Achats HT » et sa variation | Un achat du même mois de l'an dernier, validé tard ou corrigé. | Le mois en cours est en direct. L'an dernier vient du cache des achats. | faible |
| Chiffre clé « TVA à payer » | Comme la carte de la TVA. | Comme la carte de la TVA. | faible |
| Chiffre clé « Restant dû des clients », avec sa part en retard | Chaque règlement, le passage en retard, chaque jour. | Rien. | aucun |
| TVA de la page des charges et de l'état des charges (PDF, Excel) | Comme la carte de la TVA. | Le même calcul que le tableau de bord, par un seul chemin. | faible |
| Colonnes du tableau de bord du cabinet (factures à encaisser, charges à valider, crédit à vérifier) | Tout : ce sont des chiffres « à ce jour ». | Rien. | aucun |

Deux cartes n'existent que dans une société « Commerce ou atelier » : les achats et le stock. Rien ne change pour les autres types.

## 2. La conception retenue

### 2.1 Le principe

On garde de petites sommes, par magasin et par mois de ce magasin, et un numéro de version calculé sur les données mêmes qui ont servi à les faire. À chaque affichage, on relit les numéros de version de tous les mois de la période :

- un mois dont le numéro n'a pas bougé est repris du cache ;
- un mois dont le numéro a changé est recalculé et remplacé dans le cache.

Les cartes se calculent toujours depuis ces sommes, qu'elles viennent du cache ou de la base. Il n'y a qu'un seul chemin de calcul : le cache ne peut pas donner un autre résultat que le calcul direct.

### 2.2 Ce que garde chaque partie

| Partie | Contenu, pour un magasin et un mois local | Cartes qui s'en servent |
|---|---|---|
| ventes, jours | Pour chaque jour, les tickets et factures émis : nombre, HT, TVA, TTC | Chiffre d'affaires (par mois, trimestre, année, magasin), Ventes et achats, TVA, Nombre de ventes et panier, Jours de la semaine, chiffres clés |
| avoirs, jours | Pour chaque jour, les avoirs émis : nombre, HT, TVA | les mêmes cartes, et Avoirs émis, que la source « Avoirs » montre aussi à qui ne voit pas toutes les ventes |
| ventes, répartitions | Le HT net par taux de TVA, par type de client et par nature (article ou service) | Ventes par taux, Particuliers et professionnels, Articles et services |
| ventes, produits | Pour chaque produit ou service : HT net, quantité nette, libellé et date de ce libellé | Meilleurs produits ; partie lue seulement si la carte est affichée |
| ventes, clients | Le HT net de chaque client, repéré par son identifiant, sans aucun nom | Meilleurs clients |
| ventes, vendeurs | Le HT net de chaque compte, avec son nom figé et la date de ce nom | Ventes par vendeur |
| encaissements | Le montant par mode de paiement (par date de réception) et le crédit d'avoir utilisé | Encaissements par mode |
| achats, jours | Pour chaque jour, les factures d'achat validées et les avoirs fournisseur émis (HT, TVA) | Ventes et achats, TVA, chiffre clé des achats |
| achats, fournisseurs | Le HT net de chaque fournisseur | Achats par fournisseur |
| achats, lignes | Pour chaque achat : produit, jour, rang de validation, quantité, prix net en centimes, sans aucun nom | Écart des prix d'achat |
| stock, produit (lot 3) | Pour un produit, tout son historique rejoué : la valeur après chaque jour où elle change, le coût des sorties et le chiffre d'affaires des articles sortis, par jour | Valeur du stock, Marge brute |

Une partie ne contient que des nombres entiers et des textes, jamais un objet, comme le veut la configuration du cache (`serializable_classes` à `false`). Les empreintes n'additionnent que des identifiants : la base n'additionne jamais un montant décimal, conformément à la règle des centimes.

### 2.3 La clé

```
tdb:{version du code}:{base}:{partie}:m{magasin}:{territoire}:{AAAA-MM}
```

Pour le stock, le dernier élément est `p{produit}` au lieu du mois.

- **version du code** : une constante du calcul, plus la version déployée (`PILIQI_VERSION`, la date et le numéro court du commit), plus la version de la base des fuseaux horaires (`timezone_version_get()`). Voir le § 2.10.
- **base** : le nom de la base de la connexion, lu dans la configuration, sans requête. C'est un second verrou, puisque le cache est déjà rangé dans la base elle-même (§ 2.8).
- **partie**, **magasin**, **territoire** (qui donne le calendrier) et **mois local**.

Ne figurent jamais dans la clé, ni dans une partie :

- le compte, le rôle, l'écran ;
- le filtre du magasin, la période, le découpage ;
- le mois de fin d'exercice, la date du jour.

La société n'est pas nécessaire : l'identifiant d'un magasin est unique dans la base.

La valeur rangée porte aussi son numéro de version. Il est dans la valeur et non dans la clé : chaque mois n'a qu'une entrée, que l'on remplace, et les anciennes versions ne s'accumulent pas.

### 2.4 Le numéro de version de chaque mois, par source

Les requêtes exactes et les index sont dans l'annexe.

| Source | Ce qui peut bouger | Numéro de version du mois | Pourquoi il suffit |
|---|---|---|---|
| Tickets, factures et avoirs émis, avec leurs lignes | Seulement des ajouts. La base refuse de modifier ou de supprimer un document numéroté et ses lignes (vérifié dans `GardesDesDocumentsEmis`) : sur une facture, seuls changent encore le statut de règlement, le ticket Z et la date de mise à jour ; sur un avoir, le ticket Z et la date de mise à jour ; aucune de ces colonnes n'est additionnée par une carte | Le nombre de documents numérotés du mois, la somme et le plus grand de leurs identifiants | Un mois ne peut que gagner des documents, et chaque ajout change le nombre. La date de mise à jour n'y entre jamais : sinon un mois ancien se recalculerait à chaque paiement. |
| Paiements et crédits d'avoir imputés | Seulement des ajouts. La base les tient en ajout seul (`ajoutSeul`, vérifié). Une contre-passation est un nouveau paiement | Le nombre, la somme et le plus grand des identifiants du mois (date de réception pour un paiement, mois local de l'inscription pour une imputation) | Même raison. Un paiement saisi avec une date ancienne ajoute une ligne au mois ancien, ce que le nombre voit. |
| Factures d'achat validées, leurs lignes, avoirs fournisseur émis | Tout. Aucune garde en base sur ces tables : une correction change les montants, le fournisseur, l'escompte et la date, et efface puis recrée les lignes (vérifié dans `ModifierFactureAchatAction` et `ConstructeurLigneAchat`) | Le nombre et la somme des identifiants, plus un résumé (md5) de la **version de ligne** que PostgreSQL donne à chaque ligne à chaque écriture, pour la facture, chacune de ses lignes, et la facture que crédite chaque avoir | Toute écriture donne à la ligne une nouvelle version, quel que soit son chemin : écran, requête directe, travail en file, procédure. La correction d'une seule ligne se voit donc, même dans la même seconde et même sans changer la date de mise à jour. |
| Stock d'un produit (lot 3) | Chaque mouvement réécrit la fiche du produit : la base y pose le dernier mouvement (vérifié dans `GardesDuStock`). Une correction d'achat change ses lignes d'achat | La version de ligne du produit, plus le résumé des versions de ses lignes d'achat et de leurs factures | Chaque vente, retour, réception, cession ou correction du produit change ce numéro. Les autres produits gardent le leur. |
| Charges | Tout, à tout moment, suppression comprise | Aucun : lues en direct (§ 4) | Une requête par la société et le mois de TVA, avec un index qui existe déjà, aussi rapide qu'un numéro de version. |

Les bornes de chaque mois sont calculées en PHP par la classe `Calendrier` de chaque magasin, puis passées à la requête : aucun fuseau horaire n'est écrit en SQL. Ce sont les mêmes bornes pour le numéro de version et pour les données. Un même constructeur de requête sert aux deux, donc ils ne peuvent pas compter des documents différents.

Une facture d'achat se range :

- dans le mois de la date du fournisseur ;
- sans date, dans le mois local de sa validation.

### 2.5 La lecture, pas à pas

1. On contrôle les droits et on calcule le périmètre, comme aujourd'hui. Le cache ne reçoit que la liste des magasins déjà retenus pour chaque carte.
2. On lit en une fois les numéros de version de tous les mois passés que lisent les cartes affichées, comparaison comprise, pour chaque magasin et chaque source utile.
3. On lit en une fois les parties en cache (`Cache::many`).
4. Pour chaque mois dont le numéro diffère, ou qui manque :
   - on lit ses données ;
   - on relit son numéro de version ;
   - on ne range le mois que si les deux numéros sont égaux, avec ce numéro.

   Si une vente s'enregistre pendant la lecture, les deux numéros diffèrent. L'affichage se fait quand même, mais le mois n'est pas rangé : l'affichage suivant le refera. Ces numéros ne reviennent jamais en arrière : une table en ajout seul ne fait que grandir, et chaque écriture donne une nouvelle version de ligne. Deux numéros égaux prouvent donc que rien n'a changé pendant la lecture. Ce contrôle marche de la même façon en production et dans les tests, où tout se passe dans une transaction.
5. Le mois en cours de chaque magasin, pris dans son propre calendrier, et les mois à venir sont lus en direct. Ils ne s'écrivent jamais.
6. On relit en direct ce qui n'est jamais gardé (§ 4), puis on assemble les cartes en mémoire.
7. L'écriture du cache se fait après la lecture, en une seule fois (`putMany`). Si elle échoue, l'affichage n'en souffre jamais.

### 2.6 Les dépendances en chaîne

**Le report du crédit de TVA (TVA27).** Le crédit d'une période dépend de celui de la période d'avant : le crédit de mars vaut le crédit de février moins la TVA due en mars, sans descendre sous zéro. Exemple :

- crédit de 500 € fin janvier ;
- février doit 200 €, donc 300 € reportés ;
- mars doit 400 €, donc 0 € reporté et 100 € à payer.

En avril, on corrige une charge de janvier : elle ajoute 50 € de TVA déductible. Janvier passe à 550 €, février à 350 €, et mars n'a plus que 50 € à payer.

C'est pourquoi on ne garde que la TVA de chaque mois : celle des ventes et des achats en cache, celle des charges en direct. La chaîne se refait à chaque affichage, depuis l'arrivée de la société : quelques additions en mémoire, au plus douze par année. Aucun crédit reporté n'est jamais gardé. Un mois ancien qui change ne se relit qu'une fois, et le calcul porte le changement jusqu'au bout de la chaîne.

**Coût moyen, valeur du stock et marge.** Exemple :

- 10 articles achetés 4,00 € HT en janvier, 10 à 5,00 € en mars ;
- des ventes en février et en avril ;
- en mai, la facture de janvier est corrigée à 4,50 €.

Le coût des ventes de février et d'avril change, et la valeur du stock de janvier à mai aussi, alors qu'aucune donnée datée de février n'a bougé. On ne garde donc jamais une marge ou une valeur de stock par mois. On garde une entrée par produit, qui contient tout son historique rejoué. Elle a un numéro de version qui change à chaque mouvement de ce produit et à chaque changement de ses achats. Une vente ne fait rejouer que son produit.

Ce numéro contient aussi le coût moyen et le stock actuels de la fiche, parce que le rejeu part d'eux (vérifié dans `StockLedgerService::coutMoyenRejoue`). Cela couvre le cas rare où un achat d'aujourd'hui change le coût d'une vente ancienne.

**Cumul, exercices, trimestres, années, 12 derniers mois, comparaison, tendance (GRAPH-AN).** Ce ne sont que des additions de jours ou de mois, refaites à chaque affichage avec le mois de fin d'exercice du moment. GRAPH-AN range déjà les montants par jour (`SommesParJour`) : c'est exactement ce que le cache garde.

**Écart des prix d'achat sur 12 mois** : voir le § 3.

### 2.7 Durée de vie

- Chaque entrée vit 40 jours, plus longtemps qu'un mois et que la vie d'une copie de démonstration. Un mois non affiché pendant 40 jours se recalcule à son prochain affichage.
- Une tâche de nuit efface les entrées expirées, hors de la plage de 2 h à 3 h, parce que le cache en base n'efface pas de lui-même une entrée qu'on ne relit plus.
- Les entrées d'une ancienne version du code ne sont plus jamais lues, puisque la version est dans la clé : elles disparaissent à leur expiration.

### 2.8 Où le cache est rangé

Dans la base elle-même, table `cache`, par le magasin de cache « database », épinglé pour ces parties :

- sur le site réel, c'est la base réelle ;
- dans une copie de démonstration, c'est la base de la copie : `OuvertureDUneCopie` y bascule le cache, et `BrancherUneBase` oublie le cache ouvert avant.

Ce choix ferme d'un coup les risques de remise à zéro :

- `migrate:fresh` recrée la table vide ;
- une nouvelle copie part d'une base neuve ;
- une restauration remet le cache au même instant que les données, donc en accord avec elles.

Jamais un magasin de cache commun à plusieurs bases :

- ni le cache du registre du site de démonstration, commun à toutes les copies ;
- ni des fichiers sur disque ;
- ni Redis, même quand l'étape 15 l'adoptera pour le reste.

Après une restauration, une base remise en arrière redonne des identifiants et des versions déjà vus, avec d'autres montants. Un cache rangé hors de la base servirait alors des chiffres faux.

La procédure de reprise après restauration (étape 15) vide de plus ce cache. Cela couvre une restauration partielle.

### 2.9 Dans une copie de démonstration

- Chaque copie a sa base, donc son cache. Deux copies du même modèle, qui ont les mêmes identifiants de magasin, ne partagent rien.
- Le modèle vide déjà sa table `cache` à sa construction (étape 8 de `ConstruireUnModele`). « Recommencer à zéro » et la réfection donnent une base neuve, et la suppression emporte le cache.
- La place d'une copie se mesure sur la taille de sa base (`pg_database_size`), cache compris. Les parties restent donc petites : les parties lourdes, comme les produits, ne s'écrivent que si leur carte est affichée. Une copie en lecture seule lit son cache mais ne l'écrit plus. Aujourd'hui, la lecture seule ne bloque que les écritures des modèles lors d'un geste : le cache, qui passe par une requête directe, doit le vérifier lui-même.
- Les tâches du calendrier des copies (clôtures) ne touchent pas le cache. `demonstration:migrer-les-copies` garde la base et son cache, mais la version du code dans la clé écarte les anciennes entrées.

### 2.10 Quand le code d'une carte change

- **Un changement de l'assemblage** (une nouvelle façon de grouper, une carte nouvelle, un nouveau texte) ne touche pas le cache : l'assemblage n'est jamais gardé.
- **Un changement de ce qu'une partie additionne** (une colonne, un arrondi de coût, le jour d'un document, la règle d'un libellé) doit invalider les parties :
  - en production et sur le site de démonstration, la version déployée change à chaque mise en ligne. Toutes les clés changent d'elles-mêmes et tout se recalcule au premier affichage, sans compter sur un vidage. Sur le site de démonstration, `cache:clear` ne viderait d'ailleurs que le cache du registre ;
  - en développement, sans version déployée, la version prend en plus une empreinte des fichiers du calcul (leur date de modification) ;
  - les tests partent toujours d'un cache vide ;
  - la constante du calcul se change en plus à la main, par prudence.

### 2.11 Pourquoi ni un autre rôle ni une autre société

- Une partie ne contient que les données d'un magasin et d'une table, toutes, jamais une vue filtrée (« mes ventes », « les factures que je vois »).
- Elle n'est lue que pour un magasin que le périmètre a déjà retenu pour la carte. Toute personne qui la reçoit voit donc déjà, en base, tout ce qu'elle contient. Chaque source qui se sert d'une partie exige de voir toutes les lignes de sa table dans ce magasin. Le propriétaire, l'administrateur dans sa portée, le cabinet lié et l'expert-comptable déclaré reçoivent les mêmes parties d'un même magasin, puisqu'ils y voient les mêmes documents.
- Un droit retiré (case décochée, lien du cabinet terminé, déclaration retirée) agit dès la requête suivante, puisque le périmètre n'est jamais gardé.
- Une société ne voit jamais la partie d'une autre : les identifiants des magasins sont uniques dans la base, et chaque copie de démonstration a sa base.
- Aucune somme de plusieurs magasins n'est gardée. Un administrateur qui voit deux magasins sur trois ne peut donc pas recevoir un total qui compte le troisième.

### 2.12 Où les analyses divergeaient, et ce que j'ai retenu

| Point | Ce que disaient les analyses | Retenu, et pourquoi |
|---|---|---|
| Numéro de version des achats | Achats et TVA : un résumé du contenu des colonnes lues. Mécanique : un résumé des versions de ligne de PostgreSQL | **La version de ligne.** Elle couvre toutes les colonnes et tous les chemins d'écriture. Et elle ne revient jamais à une ancienne valeur, alors qu'un résumé du contenu y revient si une correction est annulée par une autre pendant la lecture : le mois serait alors rangé faux pour toujours. Seul inconvénient : un recalcul inutile quand une colonne non lue change, par exemple une pièce jointe ou « TVA à vérifier ». Il est sans danger. |
| Lecture cohérente | Achats et TVA : un instantané de la base (REPEATABLE READ). Mécanique : relire le numéro de version avant et après | **Relire avant et après.** Le même code marche dans les tests, qui tournent dans une transaction où l'instantané est impossible, et en production. C'est juste parce que les numéros retenus ne reviennent jamais en arrière. |
| Stock et marge | Achats : une entrée par produit. Mécanique : un bloc par magasin | **Par produit.** Un bloc par magasin serait recalculé à presque chaque vente pendant les heures d'ouverture, donc sans gain. Les deux sont sûrs. Le stock vient au lot 3, après une mesure. D'ici là, stock et marge restent calculés comme aujourd'hui. |
| Mois précédent pendant le premier jour d'un mois | Ventes : toujours le recalculer | **Inutile.** Son numéro de version voit déjà une vente tardive. La règle qui reste : le mois en cours et les mois à venir de chaque magasin ne s'écrivent jamais. |
| Bornes d'un mois pour les documents horodatés | Achats et TVA : un jour de marge de chaque côté. Ventes et mécanique : les bornes exactes du calendrier | **Les bornes exactes**, les mêmes pour le numéro de version et pour les données. |
| Filtre des ventes | Le code compte une facture dont le statut n'est pas « brouillon », alors que la base dit « émis » dès qu'il y a un numéro | **Lire « numéroté »** : de tout ce que lit le tableau de bord, le statut est la seule colonne d'une facture émise que la base laisse changer, et c'est celle du filtre. Ajouter aussi la contrainte « brouillon si et seulement si sans numéro » dans la création des tables des factures et des avoirs. |
| Identité de la base dans la clé | Ventes : nom et identifiant interne. Achats : site, base, génération, commit. Mécanique : nom de la base | **Le cache rangé dans la base**, verrou principal, plus le nom de la base dans la clé, second verrou, plus la version du code. Une « génération » de la base est inutile, puisque le cache est restauré avec elle. |
| Vidage au déploiement | Ventes, achats et TVA : vider le cache. Mécanique : insuffisant | **La version dans la clé**, sans compter sur un vidage. |
| Charges | TVA et mécanique : jamais en cache. Achats : rien dit | **Jamais en cache.** |
| Encaissements par mode | Seule la mécanique en parle | **Cache par mois**, avec la remarque sur les paiements antidatés (§ 8). |

## 3. La carte « Écart des prix d'achat » (ta question)

Ce que fait la carte, relu dans `EcartDesPrixDAchat::calculer` :

- **Affichage « un total par mois »** : dans chaque mois, chaque achat d'un produit est comparé au premier achat de ce produit dans ce même mois.
- **Affichage « un seul total pour les 12 mois »** : chaque achat est comparé au premier achat du produit dans toute la période. Le résultat dépend donc du début de la période, comme tu le dis. Avec « 12 derniers mois », la période avance d'un mois chaque mois. Quand le premier achat d'un produit sort de la fenêtre, son prix de départ change, et toutes ses comparaisons avec lui.
- Le calcul repère aussi chaque produit par sa place dans toute la période. Le résultat d'un mois tel que la carte le calcule n'est donc pas un morceau qu'on pourrait recoller.

Ce qu'on fait :

1. On ne garde **jamais le résultat** de la carte, ni par mois, ni sur 12 mois.
2. On garde, pour chaque mois et chaque magasin, la **liste brute** de ses achats validés. Pour chacun : le produit, le jour, le rang de validation, la quantité et le prix net en centimes, remise et escompte déduits, arrondi une seule fois. Aucun nom.
3. À chaque affichage :
   - on reprend les 12 listes ;
   - on relit les noms des produits et des magasins ;
   - on refait le calcul complet (`AchatsParProduit` puis `EcartDesPrixDAchat::calculer`), qui ne lit pas la base : quelques millisecondes en mémoire.

   Les deux affichages et le mois choisi sortent de ce même calcul. Le calcul trie lui-même les produits et les achats par jour et par rang : l'ordre des listes ne change rien au résultat.
4. Un mois d'achats n'est **jamais tenu pour fini**. Une facture de janvier saisie en mars, ou une correction de prix, d'escompte, de date ou de ligne, change le numéro de version de ce mois, et de celui qu'elle quitte si sa date change. Seuls ces mois se relisent.

Exemple de glissement. En décembre 2026, la période va de janvier à décembre 2026, et le départ d'un produit est son achat du 3 janvier 2026. En janvier 2027, la période va de février 2026 à janvier 2027 : son départ devient son premier achat de février. Tout le total sur 12 mois change, sans qu'aucune donnée ne change. Avec la méthode retenue, c'est automatique, puisque le départ se recalcule depuis les 12 listes.

Autre exemple : une facture datée du 2 janvier 2026, validée en mars. Elle entre dans la liste de janvier, dont le numéro change. Au prochain affichage, elle devient le nouveau départ de l'année, et c'est bien ce que montre la carte.

Dans GRAPH-AN, « Découper par : année » fait lire les 12 derniers mois à cette carte. C'est la même méthode.

Un défaut relevé par l'analyse des achats est à corriger à part, sans lien avec le cache. Un achat daté d'un mois à venir de la période compte dans cette carte et dans « Achats par fournisseur », mais pas dans « Ventes et achats », qui laisse ce mois vide.

## 4. Ce qui ne va jamais en cache

| Jamais en cache | Pourquoi |
|---|---|
| Factures à encaisser par ancienneté ; restant dû des clients et sa part en retard | Ce sont des chiffres « à ce jour » : ils changent à chaque paiement et chaque jour, avec le passage en retard. La lecture ne porte que sur les factures non soldées : elle est déjà légère. |
| Toutes les charges (dépôt, chiffres, renvoi, validation, correction, mois de TVA, suppression) | Elles changent n'importe quand, y compris pour un magasin fermé ou une société suspendue. Les lire est aussi rapide qu'un numéro de version (index sur la société et le mois de TVA). Une suppression pourrait faire revenir un ancien numéro. |
| Le crédit de TVA déclaré, le régime, la cadence, la date d'arrivée, et le report lui-même | C'est une ligne à lire. Le report est une chaîne, recalculée à chaque fois (§ 2.6). |
| Les droits et le périmètre : cases, liens du cabinet, déclaration de l'expert-comptable, portée des administrateurs, filtre du magasin | Ils doivent valoir dès la requête suivante. |
| Les noms des clients, produits, fournisseurs et magasins ; « (fermé) », « (cédé) » ; l'ordre des magasins | Ils changent sans qu'aucun document ne change. |
| Les préférences : cartes affichées, ordre, type, filtres | Elles sont déjà en base et relues. |
| Le mois de fin d'exercice et tout regroupement (exercice, trimestre, année, cumul) | La procédure de l'éditeur peut changer le mois de fin d'exercice et regrouper autrement les mêmes mois. |
| Ce qui dépend du jour : mois en cours de chaque magasin, mois à venir, barre en cours, « mêmes jours de l'an dernier », nombre de chaque jour de la semaine, couleur d'un chiffre clé | Ils changent chaque jour. On les recalcule en mémoire depuis les sommes de chaque jour, sans relire la base. |
| La liste des documents d'un jour (détail de GRAPH-AN) | C'est une liste de documents, pas une somme : elle se lit en direct. |
| « Mes ventes » du vendeur, et tout ce qui est propre à un compte | Le cache ne garde jamais rien par compte. |

Rien ne demande un cache « pour la journée » : tout ce qui dépend du jour se refait en mémoire en quelques millisecondes.

## 5. Les tests à écrire

Le temps est toujours figé à un instant écrit avec son fuseau, et Faker est semé, comme le veut le projet. Pour chaque test qui change des données, on compare trois choses : la carte avec le cache coupé, la carte avec le cache vide, et la carte avec le cache plein après le changement. Les trois doivent donner exactement le même résultat.

### 5.1 Une source qui change un chiffre, un test par cas

| Domaine | Cas à jouer |
|---|---|
| Ventes | ticket du jour ; facture à un professionnel après le ticket Z ; vente horodatée 23:59:59 et validée à 00:00:01, à Paris et à Fort-de-France ; affichage depuis Paris à 0 h 30 avec un magasin de Martinique qui vend encore le 31 ; La Réunion déjà au mois suivant ; avoir sur une vente ancienne ; facture numérotée remise en brouillon par une requête directe (refusée par la nouvelle contrainte) |
| Encaissements | paiement saisi aujourd'hui avec une date de réception d'il y a 14 mois ; contre-passation ; crédit d'avoir imputé juste après minuit |
| Achats | facture validée aujourd'hui datée d'il y a 14 mois ; facture sans date reçue par l'API ; correction à totaux égaux (prix de deux lignes échangés) ; escompte changé ; date déplacée d'un mois à l'autre (les deux mois changent) ; fournisseur changé ; ligne retirée, ligne ajoutée ; correction par une requête directe qui ne touche pas la date de mise à jour ; avoir fournisseur ; suppression d'un brouillon (rien ne change) ; pièce jointe après la validation (recalcul, même chiffre) |
| Stock et marge (lot 3) | réception de janvier corrigée en mai (valeur et marge de janvier à mai) ; cas du coût moyen actuel (inventaire sans suivi, suivi coché, vente sans stock, puis achat) ; inventaire d'ouverture ; cession et annulation de la cession |
| Charges et TVA | charge déposée pour un mois ancien, puis saisie, renvoyée, validée, corrigée (mois de TVA et magasin changés), supprimée ; crédit déclaré, modifié, corrigé, confirmé ; régime passé du mensuel au trimestriel ; le report de toutes les périodes qui suivent change |
| Réglages et noms | mois de fin d'exercice changé par la procédure de l'éditeur ; client renommé puis archivé ; produit, fournisseur et magasin renommés ; magasin fermé, cédé, rouvert |

### 5.2 Isolation et droits

- Deux sociétés aux données différentes, cache chauffé par l'une : l'autre a ses propres chiffres.
- Deux copies de démonstration du même modèle, donc avec les mêmes identifiants. On en modifie une : l'autre ne change pas. Après « Recommencer à zéro », le cache est vide.
- Le même magasin vu par chaque compte du semis, dans les deux ordres (le propriétaire chauffe le cache, puis l'administrateur à portée partielle le lit, et l'inverse). Comptes à couvrir : propriétaire, administrateur avec une sélection de magasins, administrateur sans la case des graphiques, responsable avec et sans la case, vendeur et observateur (aucun graphique), cabinet lié avec des consultations partielles, collaborateur à qui le client est ou n'est pas confié, expert-comptable déclaré. Chacun obtient exactement le calcul direct.
- Un droit retiré entre deux affichages (case décochée, lien du cabinet terminé, déclaration retirée) : au deuxième affichage, le magasin n'est plus compté.
- « Mes ventes » ne lit jamais une partie.
- Un test d'inventaire : chaque table lue par une partie figure dans son numéro de version, et chaque partie n'est utilisée que par des cartes dont la source exige de voir toute sa table dans ce magasin.

### 5.3 Calendrier, concurrence, version du code

- Minuit de chaque territoire : métropole, Corse, Guadeloupe, Martinique, La Réunion. Changement d'heure du dernier dimanche d'octobre. Changement d'année. La suite passe aussi sous faketime, comme aujourd'hui.
- Groupe de concurrence :
  - une vente validée entre les deux lectures du numéro de version : le mois n'est pas rangé ;
  - deux affichages simultanés écrivent la même clé : la valeur reste cohérente ;
  - une vente tombe entre les trois lectures du stock (lot 3).
- Hors transaction, dans le groupe de concurrence : le numéro de version d'un mois d'achats ne change pas après un `VACUUM FREEZE`. C'est un détail interne de PostgreSQL, qu'un test doit garantir.
- Cache vide, table absente, écriture refusée : l'affichage est identique.
- Copie en lecture seule : le cache se lit, mais ne s'écrit pas.
- Changement de la constante du calcul, de la version déployée ou de la version des fuseaux : aucune ancienne entrée n'est servie.
- Plafond de requêtes : le test existant (`PlafondsDeRequetesTest`) reste vrai avec un magasin ou trois. Avec le cache plein, aucune table de lignes n'est lue pour un mois passé.
- Purge de nuit : les entrées expirées sont effacées, et jamais entre 2 h et 3 h.

### 5.4 Sur tout le semis, et chaque nuit

- Une commande `tableau-de-bord:verifier-le-cache` :
  - pour chaque entrée dont le numéro de version est encore bon, elle refait le calcul depuis la base et compare ;
  - au moindre écart, elle vide le cache des graphiques et prévient l'éditeur.
- Elle ne peut pas trouver une faute de calcul, puisque cache et calcul direct ont le même code. Elle trouve exactement la faute à craindre : un changement qu'un numéro de version n'aurait pas vu.
- Elle tourne chaque nuit, hors de la plage de 2 h à 3 h, comme `app:verifier-isolation`, sur le site réel et sur les bases modèles.
- Le test du semis la lance aussi sur la base semée. Avant cela, il affiche toutes les cartes de tous les comptes sur tous leurs écrans, avec chaque période, chaque découpage, la comparaison, le cumul, chaque affichage de l'écart et chaque mesure des meilleurs produits. Il le fait trois fois : cache vide, cache plein, puis après une série de changements (un par source du § 5.1). Le résultat doit être identique au calcul direct.

## 6. Les risques qui restent

| Risque | Probabilité | Ce qui le couvre |
|---|---|---|
| Une table ou une jointure oubliée dans un numéro de version, au moment d'écrire le code, ou plus tard quand un lot ajoute une source à une carte : SUPER PDP (étape 2), fiches de comptage (étape 7), API (étape 10), achats aux particuliers (étape 14) | moyenne à l'écriture, faible ensuite | Un seul constructeur de requête pour le numéro de version et pour les données. Version de ligne pour les tables modifiables. Test d'inventaire, test sur tout le semis, contrôle de nuit. Une règle à ajouter au protocole : « une source ajoutée à une carte s'ajoute à son numéro de version ». |
| Le code d'une partie change sans que la version change | faible | La version déployée dans la clé, l'empreinte des fichiers en développement, des tests qui partent d'un cache vide. |
| Une partie calculée un jour sur une vue filtrée par compte, puis servie à un autre | faible | Une partie se lit toujours sur tout le magasin, sans filtre de compte. Tests du vendeur et de l'administrateur à portée partielle. |
| Une partie lue avant le contrôle des droits | très faible | Le cache ne reçoit que les magasins déjà retenus par le périmètre. Tests des droits retirés. |
| Le cache déplacé hors de la base (Redis à l'étape 15) ou une restauration partielle | faible | Magasin de cache épinglé dans la base. Vidage dans la procédure de reprise. Le README le dira. |
| Deux écritures d'une même ligne reçoivent la même version de PostgreSQL, parce que son compteur a fait le tour | négligeable | Il faudrait plus de quatre milliards de transactions pendant les 40 jours de vie d'une entrée, puis que la réécriture tombe exactement sur le même numéro. |
| Le coût des numéros de version au grand volume : ils comptent, par un index, toutes les ventes de la période. C'est bien moins cher que de lire les ventes elles-mêmes, mais pas gratuit sur dix ans | moyenne pour les vues de dix ans | Index partiels à ajouter. Mesure à la campagne de tests 1 bis. Si besoin, un lot 4 : par exemple, pour les ventes, une marque « mois clos » tirée du registre fiscal, dont l'heure ne recule jamais, ou des compteurs tenus par la base. |
| Une copie de démonstration dépasse sa place à cause du cache | faible | Pas d'écriture du cache dans une copie en lecture seule. Parties compactes. Parties lourdes écrites seulement si leur carte est affichée. Durée de vie de 40 jours. |
| Libellés et égalités des trois classements | certaine aujourd'hui, même sans cache | Une règle à écrire avant d'y mettre le cache (§ 8). |
| Lecture du stock en trois requêtes séparées : une vente qui tombe entre deux d'entre elles fausse le calcul. Défaut qui existe déjà : aujourd'hui l'erreur disparaît au rechargement, avec un cache elle resterait | faible | Double lecture du numéro de version. Correction du défaut au lot 3. |
| Hors cache : achats datés d'un mois à venir comptés par deux cartes sur trois ; dans GRAPH-AN, charges comparées au mois entier, alors que ventes et achats sont comparés aux mêmes jours ; trois points du report de TVA27 | relevés, pas encore corrigés | À corriger dans leurs lots (§ 8). |

## 7. Le travail et le gain

### 7.1 Les lots

| Lot | Contenu | Difficulté |
|---|---|---|
| Prérequis | Finir et fusionner GRAPH-AN et TVA27, sinon le cache serait écrit deux fois. Lire « numéroté » et ajouter la contrainte. Écrire la règle des libellés des classements. Hors cache : les points de TVA27 et les achats datés d'un mois à venir. | petits, sauf les points de TVA27 |
| CACHE-1 : socle et ventes | Le mécanisme commun : clé, version du code, magasin épinglé, double lecture, purge, interrupteur qui coupe le cache pour les tests et le contrôle de nuit. Les parties des ventes, des avoirs, des encaissements et des imputations. Toutes les cartes de ventes et le chiffre clé. Le test sur tout le semis et le contrôle de nuit. | gros |
| CACHE-2 : achats, écart des prix et TVA | Les parties des achats, avec la version de ligne. L'écart des prix refait depuis les listes. La TVA et le report depuis les mois. La page et l'état des charges par le même chemin. | moyen |
| CACHE-3 : stock et marge | Le rejeu par produit, son numéro de version, la lecture cohérente, le cas du coût moyen actuel. | gros, le plus délicat |
| CACHE-4, seulement si la mesure l'exige | Le grand volume, voir le § 6. | à mesurer |

Chaque lot touche des montants et l'isolation des magasins. Il lance donc toute la suite sur PostgreSQL avant son commit, comme le veut le protocole.

**Ce qui est simple** :

- les sommes par jour, qui existent déjà dans GRAPH-AN (`SommesParJour`) ;
- la clé, la durée de vie, l'endroit où ranger le cache ;
- les cartes faites de sommes simples.

**Ce qui ne l'est pas** :

- faire passer chaque carte par « magasin × mois ». Aujourd'hui, les lectures prennent toute une période pour plusieurs magasins d'un coup : c'est une réécriture de la lecture dans `SeriesDesGraphiques`, `ChiffresDuTableauDeBord` et `TvaDeLaPeriode` ;
- des numéros de version qui suivent exactement chaque lecture : achats sans date, avoirs fournisseur et leur facture ;
- la règle des libellés ;
- le rejeu du stock par produit ;
- le test sur tout le semis pour tous les comptes, toutes les cartes et toutes les périodes : il est long, et il lui faut sans doute un groupe à part dans la CI ;
- les tests de concurrence.

### 7.2 Le gain

| Gain | Cartes et situations |
|---|---|
| grand | Le report de TVA de TVA27. Dès que le compte voit le report (en général le propriétaire, le cabinet lié et l'expert-comptable déclaré), toutes les ventes et tous les achats depuis l'arrivée de la société sont relus à chaque affichage et à chaque geste, quelles que soient les cartes affichées (vérifié : `premierJourDeLaTva`). Le découpage par année (dix ans). Le chiffre d'affaires par an (cinq ans). La comparaison (24 mois). Les trois classements et l'écart des prix sur de longues périodes. Après le lot 3, la valeur du stock et la marge, qui rejouent aujourd'hui tout le grand livre du magasin à chaque affichage. |
| moyen | Les vues de 12 mois : onze mois sur douze ne sont plus relus. Les numéros de version et le mois en cours restent à lire. |
| nul | Les factures à encaisser, le restant dû, les charges, les noms. Le premier affichage après une mise en ligne, ou après un changement dans un mois. La base semée actuelle : 4 007 factures pour 22 magasins, c'est déjà rapide. L'aller-retour d'un geste : le lot « Gestes des cartes sans attente » le supprime pour les gestes qui ne changent aucune donnée. |

Un seul chiffre est mesuré à ce jour : 7,7 ms pour les numéros de version de 286 mois de ventes sur la base semée, contre 45 ms pour une simple jointure. Le reste se mesurera au volume de la campagne de tests 1 bis, avant de choisir s'il faut un lot 4.

## 8. Ce que tu dois trancher

1. **Le principe.** Un numéro de version pour chaque mois, quel que soit son âge, au lieu de « période finie figée, et versions pour les 3 à 6 derniers mois ». C'est ma recommandation : ta règle laisserait des chiffres faux pour toujours dans les achats, les charges, le stock, la marge, la TVA et les encaissements, alors qu'un numéro de version coûte quelques millisecondes.
2. **L'ordre.** Je propose :
   - d'abord le lot « Gestes des cartes sans attente » : il supprime l'attente des gestes sans aucun risque sur les chiffres ;
   - puis CACHE-1, CACHE-2 et CACHE-3, une fois GRAPH-AN et TVA27 fusionnés ;
   - le lot 4 seulement après la mesure.
3. **Les paiements saisis avec une date passée.** La carte « Encaissements par mode » range un paiement au jour de sa réception. Un paiement saisi aujourd'hui avec une date d'il y a un an change donc un mois déjà passé, alors que la clôture de caisse le compte au jour où il est enregistré. Le cache suit les deux choix. C'est une règle d'affichage, à toi de choisir :
   - garder le jour de réception, et le dire dans la ligne grise ;
   - ou limiter la date de réception.
4. **Hors cache, trois points du report de TVA de TVA27**, relevés par l'analyse de la TVA. Ce sont des règles de calcul de la TVA. Dis-moi si tu les tranches toi-même, ou si je les inscris en décision de Claude (D76), avec ces propositions :
   - **la chaîne part toujours de l'arrivée de la société**, quel que soit l'historique du régime (vérifié : `CreditsDeTva::premierePeriode`, aucune date d'effet du régime). Après une sortie de la franchise, les mois de franchise créent un crédit qui n'existe pas. Après un passage du mensuel au trimestriel, le dernier crédit mensuel est oublié. Risque élevé. Proposition : une date d'effet du régime ; la chaîne part de la première période du régime actuel ; au changement de cadence, le dernier crédit déclaré sert de départ ;
   - **une seule déclaration sert d'ancre**, la plus récente. En déclarer une plus récente change des périodes passées déjà vues. Proposition : pour chaque période, l'ancre est la déclaration la plus récente qui ne la suit pas ;
   - **un administrateur coché « Charges : ajouter » dans une partie des magasins seulement** voit un autre crédit reporté que le propriétaire. Proposition : pas de report sans les charges de tous les magasins, avec la note « sans le report ».

   Les remboursements de crédit, encore à concevoir, s'ajouteront à la même chaîne.

Je propose de trancher moi-même ces points d'affichage. Tu peux les contester :

- **la règle des libellés des classements.** Le libellé est celui de la vente la plus récente du produit, du service ou du vendeur. À égalité de valeur, l'ordre des libellés, puis celui des identifiants. Pour les clients, c'est le nom actuel, puis l'identifiant ;
- **les achats datés d'un mois à venir.** Aucune carte ne les compte avant que leur mois n'arrive, comme « Ventes et achats » le fait déjà.

## Annexe technique : requêtes, index, valeur rangée

Ces formes sont indicatives. Le lot les écrit avec le constructeur qui sert aussi à lire les données.

**Ventes**, par magasin et mois local. Les bornes sont calculées par `Calendrier` et passées en UTC : minuit du 1er janvier 2026 à Paris, c'est le 31 décembre 2025 à 23 h UTC ; à Fort-de-France, le 1er janvier à 4 h UTC.

```sql
WITH bornes(magasin_id, mois, debut, fin) AS (
  VALUES (12, '2026-01', '2025-12-31 23:00:00', '2026-01-31 23:00:00'),
         (31, '2026-01', '2026-01-01 04:00:00', '2026-02-01 04:00:00')
)
SELECT b.magasin_id, b.mois, f.nombre, f.somme, f.dernier, a.nombre, a.somme, a.dernier
FROM bornes b
CROSS JOIN LATERAL (
  SELECT count(*) AS nombre, coalesce(sum(id), 0) AS somme, coalesce(max(id), 0) AS dernier
  FROM factures
  WHERE magasin_id = b.magasin_id AND numero IS NOT NULL
    AND date_emission >= b.debut AND date_emission < b.fin
) f
CROSS JOIN LATERAL (
  SELECT count(*) AS nombre, coalesce(sum(id), 0) AS somme, coalesce(max(id), 0) AS dernier
  FROM avoirs
  WHERE magasin_id = b.magasin_id AND numero IS NOT NULL
    AND date_emission >= b.debut AND date_emission < b.fin
) a;
```

Index à ajouter dans les migrations de création :

- `factures (magasin_id, date_emission) INCLUDE (id) WHERE numero IS NOT NULL`, et le même sur `avoirs` : le décompte se fait alors dans l'index seul ;
- la contrainte `CHECK ((statut = 'brouillon') = (numero IS NULL))` sur les deux tables.

**Encaissements**. La date de réception est un jour sans heure, donc sans fuseau.

```sql
SELECT magasin_id, to_char(recu_le, 'YYYY-MM') AS mois, count(*), sum(id), max(id)
FROM paiements
WHERE magasin_id IN (12, 31) AND recu_le BETWEEN '2025-12-01' AND '2026-10-31'
GROUP BY magasin_id, to_char(recu_le, 'YYYY-MM');
```

Pour les imputations d'avoir, on prend la même forme que les ventes, sur `avoir_allocations.created_at`. Index : `paiements (magasin_id, recu_le) INCLUDE (id)` et `avoir_allocations (magasin_id, created_at) INCLUDE (id)`. Les index sans `INCLUDE` existent déjà.

**Achats**, avec la version de ligne (`xmin`) de chaque facture et de chacune de ses lignes :

```sql
WITH bornes(magasin_id, mois, premier_jour, dernier_jour, debut, fin) AS (VALUES (...))
SELECT b.magasin_id, b.mois, e.nombre, e.somme, e.empreinte
FROM bornes b
CROSS JOIN LATERAL (
  SELECT count(*) AS nombre, coalesce(sum(fa.id), 0) AS somme,
         md5(coalesce(string_agg(fa.id || ':' || fa.xmin::text || ':' || coalesce(l.versions, ''), ',' ORDER BY fa.id), '')) AS empreinte
  FROM factures_achat fa
  LEFT JOIN LATERAL (
    SELECT string_agg(la.id || '/' || la.xmin::text, ';' ORDER BY la.id) AS versions
    FROM lignes_achat la
    WHERE la.magasin_id = fa.magasin_id AND la.facture_achat_id = fa.id
  ) l ON true
  WHERE fa.magasin_id = b.magasin_id AND fa.statut = 'validee'
    AND (fa.date_facture BETWEEN b.premier_jour AND b.dernier_jour
         OR (fa.date_facture IS NULL AND fa.date_validation >= b.debut AND fa.date_validation < b.fin))
) e;
```

Pour les avoirs fournisseur émis, on prend le même principe sur le mois local de leur émission, avec la version de ligne de la facture qu'ils créditent. Les index existants suffisent : `factures_achat (magasin_id, statut, date_facture)` et `lignes_achat (magasin_id, facture_achat_id)`.

**Stock, par produit** (lot 3). La base réécrit la fiche du produit à chaque mouvement, donc sa version change avec lui.

```sql
SELECT p.id, p.xmin::text AS version_du_produit,
       md5(coalesce(string_agg(la.id || ':' || la.xmin::text || ':' || fa.xmin::text, ',' ORDER BY la.id), '')) AS receptions
FROM produits p
LEFT JOIN lignes_achat la ON la.magasin_id = p.magasin_id AND la.produit_id = p.id
LEFT JOIN factures_achat fa ON fa.magasin_id = la.magasin_id AND fa.id = la.facture_achat_id
WHERE p.magasin_id = 12
GROUP BY p.id, p.xmin::text;
```

Les produits archivés sont compris, comme dans le calcul d'aujourd'hui.

**Valeur rangée**, par exemple sous la clé `tdb:c1.2026-11-23.36b2388c.2025b:piliqi:ventes-jours:m12:metropole:2026-01` :

```
['empreinte' => 'f:1532:1173458:1907|a:41:39012:1899',
 'donnees'   => ['2026-01-02' => [nombre, ht, tva, ttc, avoirs_ht, avoirs_tva], ...]]
```

L'empreinte rangée est celle qui a été lue avant et après les données, et trouvée égale.
