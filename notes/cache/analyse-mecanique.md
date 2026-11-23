# Cache des cartes du tableau de bord : la mécanique du calcul, carte par carte

Analyse du 23/11/2026, en lecture seule. Aucun fichier du dépôt ni des deux copies de lecture n'a été modifié.

Abréviations des chemins :

- **R** = `/home/user/piliqiediteur/` (branche de travail, tête 36b2388c) ;
- **D** = `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/wt-demo/` (GRAPH-AN 1, commit 0257979e) ;
- **T** = `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/wt-tva/` (TVA27, commit fbdf759a).

Je n'ai pas pu interroger la base de développement : l'accès m'a été refusé. Les durées citées viennent de l'analyse des ventes, rangée à côté de celle-ci (`analyse-ventes.md`, § 5). Tout le reste vient de la lecture du code.

---

## 1. La réponse courte

**Oui à un cache par mois, protégé par un numéro de version. Non à la règle « une période finie ne change plus ».**

Cette règle est fausse dans sept cas réels du code :

1. Une facture d'achat validée se corrige après coup : lignes, prix, quantité, escompte, fournisseur et date de la facture du fournisseur. Voir `R app/Domain/Achats/Actions/ModifierFactureAchatAction.php` et `FactureAchat::estModifiable()`, l. 345-355.
2. Une facture d'achat se range à la date écrite par le fournisseur, pas au jour de sa saisie. Une facture de décembre saisie en février change décembre (`AchatsDuTableauDeBord::documents()`, l. 60-77).
3. Un paiement se saisit avec une date de réception passée, aussi ancienne qu'on veut. Seule une date future est refusée (`EnregistrerPaiementAction`, l. 122-127). Un chèque reçu il y a huit mois et saisi aujourd'hui change la carte « Encaissements » de ce mois-là.
4. Les charges peuvent changer le passé de plusieurs façons :
   - une charge se dépose pour un mois ancien ;
   - son mois de TVA se fixe ou se change (`EcritureDUneCharge`, l. 86-88) ;
   - elle se valide, se renvoie, ou se supprime avant sa validation ;
   - elle se corrige après sa validation (`GardesDesCharges`).
5. Le coût moyen du stock se rejoue depuis le premier mouvement. Une réception corrigée reprend sa place d'origine dans l'histoire (`StockLedgerService::coutMoyenRejoue()`, l. 466-520). La marge et la valeur du stock changent alors pour tous les mois qui suivent cette réception, même ceux d'il y a deux ans.
6. Le crédit de TVA reporté (TVA27) se calcule en chaîne, depuis l'arrivée de la société. Tout changement d'un mois change le report de toutes les périodes suivantes, et la déclaration qui sert d'ancre se modifie (`T app/Domain/Charges/Services/TvaDeLaPeriode.php`, `CreditsDeTva::ancre()`).
7. D'autres choses ne touchent aucun document mais changent ce qui s'affiche pour une période :
   - le mois de fin d'exercice (procédure de l'éditeur), qui regroupe autrement les mêmes mois ;
   - un nom de produit, de client, de fournisseur ou de magasin ;
   - un changement du code d'une carte.

**Côté ventes, la base ne laisse pas changer le passé.** Les documents émis et leurs lignes sont figés par `GardesDesDocumentsEmis`. Il reste des cas de bord, tous dans le jour qui suit la fin du mois :

- une vente validée juste après minuit (l'instant est pris au verrou du registre) ;
- un magasin d'outre-mer vu de Paris ;
- une facture à un professionnel émise après le ticket Z.

**La règle retenue :**

- **Unité du cache.** Chaque mois de chaque magasin, pour chaque source, a son numéro de version. Ce numéro est calculé à chaque affichage par une requête de regroupement : une requête par table pour tous les mois et tous les magasins. Un changement tardif ne recalcule que le mois touché.
- **Mois en cours.** Le mois en cours de chaque magasin, dans son propre calendrier, se recalcule toujours et ne s'écrit pas.
- **Ce qui ne se garde jamais.** Les droits, les noms, les charges, l'ancre du crédit de TVA et les factures à encaisser se relisent toujours. Aucun résultat assemblé (série, classement, année, période) n'est jamais gardé.
- **Écart des prix d'achat.** On ne garde pas le calcul des douze mois. On garde, par mois, la liste brute des achats du mois. Les deux affichages, par mois et sur douze mois, se recalculent à chaque affichage depuis les douze listes (§ 12).
- **Où et comment.** Le cache vit dans la base des données : magasin de cache `database`, épinglé. Sur le site réel, c'est la base réelle ; dans une copie de démonstration, c'est la base de la copie, jamais le cache du registre. La clé porte la base, le magasin, son territoire, le mois, la partie et la version du code.

**Risque restant :**

- **aucun** pour les sommes de ventes et les encaissements ;
- **faible** pour les achats, la TVA, l'écart des prix d'achat et le stock (données modifiables, mais protégées par une empreinte qui voit toute écriture) ;
- **faible** pour les trois classements, tant que la règle du libellé et des égalités n'est pas écrite (§ 11).

---

## 2. Ce que fait une requête aujourd'hui

Ces quatre fichiers font tout le calcul :

- `R app/Filament/GrilleDesGraphiques.php` ;
- `R app/Domain/TableauDeBord/Support/CalculDesSeries.php` ;
- `R app/Domain/TableauDeBord/Services/SeriesDesGraphiques.php` ;
- `R app/Domain/TableauDeBord/Services/ChiffresDuTableauDeBord.php`.

### Chaque rendu Livewire recalcule tout

Le rendu passe par `getViewData()`, l. 281-315 :

1. **Le périmètre**, relu à chaque requête (`PerimetreDesGraphiques`) : les magasins de la portée, les autorisations dans chacun, l'accès aux charges et, pour un cabinet, les liens ouverts.
2. **Les préférences** du compte sur cet écran (`PreferencesDesGraphiques::lire`).
3. **Chaque source affichée**, lue une fois pour la page, sur toute la fenêtre :
   - les ventes : factures, avoirs et leurs lignes ;
   - les avoirs, relus à part pour la source « Avoirs » ;
   - les achats : factures d'achat, avoirs fournisseur et lignes d'achat ;
   - les paiements et les imputations d'avoirs ;
   - les factures à encaisser et leurs imputations ;
   - les charges ;
   - les noms des clients et des fournisseurs ;
   - le stock : tous les produits, **tout le grand livre** du magasin depuis son premier jour, et toutes les lignes d'achat validées (`StockDuTableauDeBord::historique()`, l. 108-112).
4. **Le classement en PHP** : chaque document se range dans le jour de son magasin (`JoursDesMagasins::jourDe()`, une conversion de fuseau par ligne), puis les sommes se font en centimes.

Le test `PlafondsDeRequetesTest`, l. 321-356, tient ce rendu à **45 requêtes au plus**, avec les dix-neuf cartes et la comparaison, quel que soit le nombre de magasins. Le nombre de requêtes n'est donc pas le problème : le coût vient du **volume lu** et du **travail PHP par ligne**.

### La fenêtre de lecture

| Ce que montre l'écran | Ce que lisent les sources |
|---|---|
| 12 mois | les 12 mois, plus le même mois un an plus tôt (chiffres clés) |
| 12 mois avec « Comparer » | 24 mois |
| Avec la carte « Chiffre d'affaires HT par an » (branche) | 5 ans (`CalculDesSeries::premierJour()`, l. 130-139) |
| « Découper par : année » (GRAPH-AN) | 120 mois, 132 avec la comparaison (`D DecoupageDesPeriodes::fenetre()`, l. 55-64) |
| La TVA avec le report du crédit (TVA27) | depuis l'arrivée de la société dans PILIQI (`T CalculDesSeries::premierJourDeLaTva()`, l. 162-167). Cette fenêtre grandit chaque mois. |
| Le stock | tout l'historique, toujours |

### Un geste recalcule aussi tout

Un geste passe par `modifier()`, l. 469-475 : le périmètre est lu, puis les préférences, puis l'enregistrement. Ensuite vient le rendu, qui relit le périmètre et recalcule tout. Les gestes concernés :

- « Monter », « Descendre », la croix ;
- le type, « Classer par », « Afficher » ;
- le mois choisi de la carte « Écart des prix d'achat » (`choisirLeMois()`, l. 246-258).

Aucun de ces gestes ne change les données. La grille est aussi chargée en différé (`lazy`), ce qui ajoute une requête Livewire à l'ouverture de la page.

---

## 3. Le principe : des partiels par magasin et par mois, un seul chemin de calcul

### Ce qu'on garde

Un **partiel** est ce qu'une source donne pour **un magasin** et **un mois de son calendrier**, en centimes :

- des sommes **par jour**, pour que « mêmes jours de l'an dernier », le détail en jours, le cumul et les jours de la semaine se recalculent à l'affichage ;
- des répartitions par taux, par type de client et par nature ;
- des sommes par clé : produit ou service, client, compte vendeur, fournisseur.

Les valeurs sont de simples tableaux d'entiers et de chaînes (`serializable_classes` vaut `false`, `R config/cache.php`).

### Ce qu'on ne garde jamais

- une série dessinée, une note ou une phrase grise ;
- un classement fini ;
- une année, un trimestre ou un exercice déjà assemblés ;
- un nom actuel (client, fournisseur, produit, magasin) ;
- un droit ;
- quoi que ce soit propre à un compte, un rôle, un écran ou un filtre.

### Un seul chemin de calcul

Les cartes se calculent **toujours** depuis les partiels, qu'ils viennent du cache ou de la base. Il n'existe pas de calcul « sans cache » à côté. Le cache ne peut donc rien donner d'autre que le calcul frais, sauf un partiel périmé, et c'est le numéro de version qui l'empêche.

La même règle vaut pour la page des charges et l'état des charges, qui montrent la TVA « telle que le tableau de bord de la personne la compte » (CLAUDE.md, « Charges et TVA ») : ils passent par `TvaDeLaPeriode`, donc par les mêmes partiels. Sinon, deux écrans montreraient deux chiffres.

### Le mois en cours

Le mois en cours de chaque magasin, pris **dans son calendrier**, se calcule en direct et ne s'écrit pas. Ce n'est pas une question de justesse, puisque la version suffit. C'est pour éviter d'écrire le cache à chaque vente d'un magasin qui vend.

---

## 4. Le numéro de version, source par source

### 4.1 Ce qui ne marche pas seul

- **Le plus grand identifiant.** Une facture garde l'identifiant de son brouillon, pris à sa création ; émise des semaines plus tard, elle entre dans le mois en cours avec un identifiant plus petit que d'autres. De même, deux mouvements du stock se valident dans l'ordre où leurs transactions prennent la ligne du produit, « qui n'est pas toujours celui de leurs identifiants » (`GardesDuStock`, en-tête).
- **La plus grande date de mise à jour (`updated_at`).** Elle a trois défauts :
  - elle ne voit pas une suppression ;
  - elle ne voit pas une écriture directe qui ne la touche pas, ni deux écritures dans la même seconde ;
  - sur les factures, elle change à chaque paiement et à chaque passage en retard (`GardesDesDocumentsEmis` : `statut`, `updated_at` et `cloture_caisse_id` « suivent » le document), ce qui recalculerait des mois anciens pour rien.

### 4.2 Les tables qui ne changent plus : nombre, somme des identifiants, plus grand identifiant

| Source | Garde qui la fige | Filtre de l'empreinte (le même que la lecture) |
|---|---|---|
| `factures` (tickets et factures) | `GardesDesDocumentsEmis` : rien ne change après le numéro, sauf `statut`, `updated_at` et `cloture_caisse_id` | `numero IS NOT NULL` et `statut <> 'brouillon'`, `date_emission` dans les deux minuits du mois local |
| `avoirs` | idem, `statut` figé aussi | `numero IS NOT NULL` et `statut = 'emise'` |
| `lignes_facture`, `lignes_avoir` | aucun ajout, aucune modification ni aucune suppression une fois le document numéroté | pas d'empreinte propre : elles sont figées avec leur document |
| `paiements` | ajout seul (`DeclencheursDeProtection::ajoutSeul`) | `recu_le` dans le mois (une date, aucun fuseau) |
| `avoir_allocations` | ajout seul | `created_at` dans les deux minuits du mois local |
| `mouvements_stock` | ajout seul, et `survenu_le` vaut `created_at` (`GardesDuStock`, l. 236) | par magasin (voir § 14) |

**Pourquoi ce triplet suffit.** Ces ensembles ne font que grandir, et chaque ajout change le nombre. La somme des identifiants voit même un retrait suivi d'un ajout.

**Le filtre doit être exactement celui de la lecture.** Exemple : une facture numérotée remise à « brouillon » par une requête brute passe la garde, puisque `statut` suit le document. Elle sort alors de la lecture. Si l'empreinte filtrait seulement `numero IS NOT NULL`, elle ne le verrait pas.

**Un test doit le garantir.** Il échoue si une colonne lue par un partiel devient modifiable après l'émission, autrement dit si les « colonnes qui suivent » de la garde croisent les colonnes lues ailleurs que dans `statut`.

### 4.3 Les tables qui changent : empreinte du contenu par le `xmin` de PostgreSQL

Ces tables n'ont aucune garde en base contre la modification :

- `factures_achat` (validées) et `lignes_achat` : seul `repere_grand_livre` est figé ;
- `avoirs_fournisseur` (émis) ;
- `produits`, pour le stock seulement.

Leur protection est celle des modèles (`estModifiable`, actions). Une correction efface et recrée les lignes (`ConstructeurLigneAchat`, l. 215-235).

**L'empreinte**, par (magasin, mois) :

```sql
count(*), sum(id), md5(string_agg(id::text || ':' || xmin::text, ',' ORDER BY id))
```

Elle porte sur chaque table que lit la carte, **jointures comprises** :

- les lignes d'achat des factures du mois ;
- pour un avoir fournisseur, le `xmin` de sa facture d'achat, dont il lit le fournisseur.

**Ce que voit `xmin`.** Toute écriture d'une ligne en PostgreSQL crée une nouvelle version, avec l'identifiant de la transaction qui l'écrit. L'empreinte voit donc :

- un ajout ou une suppression, par le nombre et la somme ;
- une facture qui change de mois quand on corrige sa date : elle sort d'un mois et entre dans l'autre ;
- **une mise à jour qui garde la même date, ou les mêmes totaux** ;
- une écriture directe, celle d'une tâche planifiée ou d'un déclencheur, même dans la même seconde.

Une écriture qui ne change rien (`SET x = x`) fait seulement recalculer un mois, sans aucun risque.

**Ce qui ne la trompe pas :**

- `VACUUM FREEZE` (étape 9 de `ConstruireUnModele`) : depuis PostgreSQL 9.4, le gel garde le `xmin` d'origine ;
- `pg_upgrade`.

**Ce qui la change entièrement**, toujours dans le sens sûr (tout se recalcule une fois) :

- un vidage et une restauration logique ;
- une réplication logique.

Les identifiants de transaction sont communs à toute la grappe PostgreSQL. Deux copies de démonstration n'en reçoivent donc jamais un même nouveau.

**Coût.** `xmin` se lit dans la table, jamais dans un index seul. Mais ces tables sont petites devant celles des ventes : quelques dizaines à quelques centaines de factures d'achat par mois et par magasin.

### 4.4 Ce qui se relit toujours en direct

| Donnée | Pourquoi jamais en cache |
|---|---|
| Périmètre et droits | Ils changent sans toucher aucun document. Un lien de cabinet qui prend fin doit couper l'accès « dès la requête suivante ». |
| Préférences | Elles sont propres au compte et à l'écran. |
| Noms : `clients.nom` (et `est_comptoir`), `fournisseurs.nom`, `produits.nom`, `magasins.nom` avec son état « (fermé) » ou « (cédé) » | Ils changent sans toucher les documents. Les cartes lisent le nom **actuel**. |
| Charges (`ChargesDuTableauDeBord::lire`) | Petit volume, une requête indexée par `(societe_id, mois_tva)`. Elles sont modifiables et supprimables avant leur validation, ce qui expose au piège « ajout puis suppression » du § 5. |
| Ancre du crédit de TVA, cadence (`regime_tva`), début de la chaîne (`societes.created_at`) | Une ligne ou une colonne chacune. Elles se modifient. |
| Factures à encaisser et leurs imputations | Elles valent « à ce jour » : le statut change à chaque paiement et au passage en retard (tâche `MarquerFacturesEnRetard`, chaque heure), et le retard se compte au jour de chaque magasin. La lecture ne porte que sur les factures non soldées. |

### 4.5 La requête d'empreinte

Les bornes de chaque mois se calculent en PHP par `Calendrier`, dans le fuseau de chaque magasin, puis passent à la requête. Aucun fuseau n'est écrit en SQL : la règle « seule la classe Calendrier connaît un fuseau » est respectée. Deux formes conviennent :

- **`VALUES` et `LATERAL`** (mesurée par l'analyse des ventes : 286 couples magasin-mois en 7,7 ms) ;
- **`width_bucket(date_emission, ARRAY[minuits du calendrier en UTC])`**, groupée par magasin : un seul parcours de l'index par magasin, utile pour les 132 mois de « Découper par : année ».

Dans les deux cas, il y a une requête par table, quel que soit le nombre de magasins. Le plafond de `PlafondsDeRequetesTest` tient.

---

## 5. Lire l'empreinte et les données sans course

### Le piège

Si l'on range un partiel sous une empreinte lue **après** ses données, une vente validée entre les deux lectures entre dans l'empreinte et pas dans les données. Le chiffre reste alors faux pour toujours : l'empreinte suivante est la même.

### Le protocole, pour chaque mois à recalculer

1. Lire l'empreinte E1 (celle de la requête groupée).
2. Lire les données du mois et faire le partiel.
3. Relire l'empreinte E2 du mois.
4. Écrire le partiel sous E1 **seulement si E1 = E2**.

### Pourquoi c'est sûr

Les données sont lues entre E1 et E2. Si les deux sont égales, aucune écriture n'a été validée entre-temps. Cela suppose que toute écriture change l'empreinte sans retour possible :

- c'est vrai pour les tables en ajout seul, avec des identifiants tirés d'une séquence ;
- c'est vrai pour le `xmin`.

Une vente « en vol », horodatée 23:59:59 et validée à 00:00:01, n'est ni dans E1 ni dans les données. Elle changera l'empreinte suivante.

Ce protocole marche hors de toute transaction. Il marche aussi dans un test enveloppé d'une transaction (`RefreshDatabase`), où une lecture en `REPEATABLE READ` serait impossible : une sous-transaction ne peut pas changer d'isolation. On garde ainsi **un seul chemin de code**.

### Le piège « ajout puis suppression » (dit ABA)

Une ligne ajoutée puis supprimée entre E1 et E2 rend E2 égal à E1, alors que les données lues l'ont peut-être comptée.

- Cela ne peut pas arriver aux tables en ajout seul.
- Cela ne peut pas arriver aux factures d'achat validées, qui ne se suppriment pas (`SupprimerFactureAchatAction`, l. 68-69 : seulement un brouillon).
- Leurs lignes recréées ont de nouveaux identifiants.
- **Les charges, elles, se suppriment.** C'est une raison de plus pour les relire toujours en direct.

### Les écritures

- Elles se font après la lecture, par une écriture groupée.
- Un échec d'écriture n'arrête jamais l'affichage.
- Deux requêtes qui écrivent le même partiel laissent chacune un couple (empreinte, données) cohérent. La lecture suivante compare et recalcule au besoin.

---

## 6. Ce qui change le passé : inventaire vérifié dans le code

| # | Événement | Cartes touchées | Où dans le code | Protection |
|---|---|---|---|---|
| 1 | Vente, ticket ou avoir émis | ventes du mois en cours | `EmettreFactureAction` l. 292 et `EmettreAvoirAction` l. 138 : `date_emission` est l'instant du registre | mois en cours en direct, empreinte |
| 2 | Validation juste après minuit du magasin | le mois qui vient de finir | instant pris au verrou du registre, en tête de transaction | empreinte (§ 5) |
| 3 | Magasin d'outre-mer vu de Paris | le mois que Paris croit fini | `PerimetreDesGraphiques` l. 76, 151, 190 (jour de Paris) ; `JoursDesMagasins::jourDe()` | découpage par mois **du magasin**, mois en cours de chaque magasin |
| 4 | Facture à un professionnel après le ticket Z | la journée close | le refus d'après la clôture ne vise que les tickets | empreinte ; ne jamais se fier au ticket Z |
| 5 | Facture d'achat validée saisie avec une date ancienne du fournisseur | achats, TVA, écart, achats par fournisseur, chiffre clé des achats | `AchatsDuTableauDeBord::documents()` l. 63-65, `lignes()` l. 144-146 | empreinte `xmin` du mois de la date |
| 6 | Facture d'achat validée corrigée (lignes, prix, quantité, remise, HT écrit, escompte, fournisseur, date) | les mêmes, plus la marge et la valeur du stock depuis cette réception | `ModifierFactureAchatAction` ; `ConstructeurLigneAchat` l. 215-235 ; `StockLedgerService::coutMoyenRejoue()` | empreinte `xmin` des factures et des lignes ; empreinte du stock par magasin (§ 14) |
| 7 | Achat reçu par l'API, ou plus tard par la plateforme agréée, daté du fournisseur | comme 5 | `AchatsDuTableauDeBord` (date de la facture, sinon jour de validation) | comme 5 |
| 8 | Avoir fournisseur émis | achats, TVA, achats par fournisseur | `EmettreAvoirFournisseurAction` l. 143 | empreinte `xmin`, y compris la facture jointe |
| 9 | Paiement saisi avec une date de réception passée | encaissements de ce mois | `EnregistrerPaiementAction` l. 122-127 (seule une date future est refusée) ; `EncaisserEnPlusieursModesAction` | empreinte des paiements par mois de `recu_le` |
| 10 | Contre-passation | encaissements du jour | `ContrePasserPaiementAction` l. 161 (`recu_le` = la journée) | empreinte |
| 11 | Charge déposée pour un mois ancien, mois de TVA changé, validée, renvoyée, corrigée, supprimée avant validation | TVA (graphique, chiffre clé, report) | `EcritureDUneCharge` l. 86-88 ; `GardesDesCharges` | charges relues en direct |
| 12 | Crédit de TVA déclaré, modifié, confirmé ou corrigé ; régime mensuel ou trimestriel changé | report de toutes les périodes depuis l'ancre | `T CreditsDeTva::ancre()` l. 151-165 ; `T TvaDeLaPeriode::reporter()` | ancre, cadence et pli de la chaîne en direct |
| 13 | Nouveau mouvement du stock (vente, retour, réception, cession, annulation de cession) | marge, valeur du stock | `StockLedgerService` l. 394-405 | empreinte du stock par magasin |
| 14 | Produit renommé ou archivé | écart des prix d'achat (nom actuel) | `AchatsDuTableauDeBord::lignes()` l. 139 et 151 | nom relu par identifiant |
| 15 | Client, fournisseur ou magasin renommé ; magasin fermé, rouvert, cédé, cession annulée | meilleurs clients, achats par fournisseur, CA par magasin, suffixe « · magasin » | `nomsDesClients()`, `nomsDesFournisseurs()`, `Perimetre::nomDuMagasin()` et `magasins()` | noms et ordre relus en direct |
| 16 | Compte retiré ou renommé | aucun : le nom est figé sur chaque vente (`cree_par_nom`) | `D VentesDuTableauDeBord::ventesParCompte()` | aucune |
| 17 | Mois de fin d'exercice changé (procédure de l'éditeur) | CA par an et exercices, cumul, barres par année et trimestres de GRAPH-AN, bornes des périodes | `Perimetre::moisDeFinDExercice()`, `CalculDesSeries::annees()`, `D DecoupageDesPeriodes` | on ne garde que des mois ; tout regroupement se fait à l'affichage |
| 18 | Territoire corrigé par l'éditeur (seulement avant tout document numéroté) | le jour de chaque mouvement d'un inventaire d'ouverture déjà validé | colonne figée `magasins.territoire` | territoire dans la clé |
| 19 | Tâches planifiées : clôture automatique, passage en retard, expiration des devis, relances, rattachement des experts-comptables | aucune somme ; le droit et les factures à encaisser | `bootstrap/app.php` l. 160-242 | colonnes non lues, ou relues en direct |
| 20 | `migrate:fresh --seed`, copie créée, recommencée ou refaite, restauration d'une sauvegarde | tout : mêmes identifiants, autres données | `CopiesDeLaDemonstration` (`nomDUneCopie()`, une base neuve à chaque fois) | cache rangé **dans** la base (§ 9) |
| 21 | Déploiement d'un calcul changé | tout partiel calculé par l'ancien code | — | version du code dans la clé (§ 10) |
| 22 | Une future importation d'historique ou d'inventaire (étapes 2, 7 et 10) | achats, stock | — | les identifiants nouveaux changent l'empreinte du mois |

---

## 7. Jour, heure, minuit

- **Le mois d'une opération est celui de son magasin.** Les jours se calculent dans le calendrier de chaque magasin (`JoursDesMagasins`) : Europe/Paris, America/Martinique, America/Guadeloupe ou Indian/Reunion.
- **L'écran a son propre jour.** Paris dans l'espace de la société, l'espace du cabinet et la page de l'expert-comptable ; le jour du magasin dans son panneau. Ce jour ne sert qu'à l'affichage, recalculé à chaque requête :
  - les mois et les barres à venir (`null`) ;
  - la barre en cours ;
  - « mêmes jours de l'an dernier » (`D Mois::memeJourUnAnPlusTot()`, `Barre::unAnPlusTot()`, et les chiffres clés de GRAPH-AN) ;
  - le diviseur de « Ventes par jour de la semaine » ;
  - les douze mois de l'écart en découpage par année.
- **Les partiels gardent donc des sommes jour par jour.**
- **Le mois en cours de chaque magasin** se recalcule toujours. Exemple : à 0 h 30 à Paris le 1er décembre, il est encore 19 h 30 le 30 novembre en Martinique. Novembre est alors le mois en cours de ce magasin.
- **Minuit et les changements d'heure.** Un minuit ne tombe jamais dans un changement d'heure de ces territoires, et `Calendrier` donne les bornes exactes. La version du fuseau (`timezone_version_get()`) entre dans la version du code, sans frais.
- **Les factures à encaisser par ancienneté** comptent le retard au jour **de chaque magasin** (`FacturesDuTableauDeBord::anciennete()`, l. 64). Elles ne se gardent jamais.

---

## 8. Droits, type de société, réglages

### Droits

Le périmètre est recalculé à chaque requête, **avant** toute lecture du cache. Il donne les magasins où le compte voit toutes les sources d'une carte (`Perimetre::magasinsDe()`). Il couvre :

- la case « Tableau de bord : voir les graphiques », cochée ou non pour un administrateur ou un responsable ;
- les consultations que le client accorde au cabinet ou à l'expert-comptable déclaré ;
- les magasins choisis par le client.

Un partiel contient **tous** les documents d'une source dans un magasin. Il ne se sert donc que par le même garde que la lecture en base (`Perimetre::exiger($magasins, Source)`), à qui voit toute cette source dans ce magasin.

On ne le calcule jamais sous une vue filtrée (« mes ventes », « les factures que je vois »), et on ne garde jamais une somme de plusieurs magasins. Le rôle ne change que le périmètre, jamais un partiel.

### Type d'activité

Il est définitif : la base refuse de le changer. Une société de services n'a ni achats ni stock : la base les refuse. La clé n'a pas besoin de lui. Le mettre ne coûterait rien.

### Réglages fiscaux

- **Franchise en base** : la carte de la TVA n'existe pas (`Perimetre::voit()`, l. 168-172) et le chiffre clé dit « TVA non applicable ». Les documents gardent leur TVA figée.
- **Régime de TVA et cadence** (mensuelle ou trimestrielle) : ils jouent dans le pli du report, toujours recalculé.
- **Mois de fin d'exercice** : il ne sert qu'au regroupement, à l'affichage.
- **Option pour les débits** : aucune carte ne la lit.
- **Territoire** : il entre dans la clé.

---

## 9. Où ranger le cache, la clé, l'isolation, la copie de démonstration

### Où

**Le magasin de cache `database`, épinglé dans le code (`Cache::store('database')`)**, sur la connexion par défaut. Jamais le magasin par défaut implicite.

- **Site réel.** La table `cache` de la base réelle. `migrate:fresh` la recrée vide (`0001_01_01_000002_create_cache_table.php`). Une restauration la remet au même instant que les données, donc chaque couple (empreinte, données) reste cohérent.
- **Copie de démonstration.** À l'ouverture d'une copie, `OuvertureDUneCopie::ouvrir()` (l. 134) met le cache par défaut sur `database`, et `BrancherUneBase` branche la connexion sur la base de la copie, puis oublie les magasins de cache déjà résolus. Le démarrage du site de démonstration refuse `DB_CACHE_CONNECTION`, `redis` et `file` (`SiteDePiliqiServiceProvider`, l. 132-146). Le partiel d'une copie vit donc dans sa base, et nulle part ailleurs.
- **Hors d'une copie**, le cache par défaut du site de démonstration est celui du registre (`CACHE_STORE=registre`, `R config/cache.php`). Ce cache est **commun à toutes les copies**, alors que les copies d'un même modèle ont les mêmes identifiants de société, de magasin et de compte. **C'est interdit pour ce cache.** Épingler `database` le garantit : hors d'une copie, ce magasin vise la base sentinelle, qui n'a pas de table, et la lecture échoue au lieu de fuir.
- **Le magasin `file` est interdit** : `storage/framework/cache/data` n'est pas rangé sous le dossier de la copie.
- **Le modèle** vide sa table `cache` avant d'être gelé (`ConstruireUnModele`, étape 8). Une copie neuve, recommencée ou refaite reçoit une base neuve, au nom neuf.
- **`demonstration:migrer-les-copies`** garde la base de la copie, donc sa table `cache`. C'est pourquoi la version du code est obligatoire dans la clé.
- **La place d'une copie** (trois fois son modèle, `PlaceDesCopies`) compte la table `cache`. Une seule entrée par (magasin, mois, partie), écrasée à chaque changement, garde la taille bornée.
- **Redis plus tard** (étape 15) ne vaut pas pour ce cache. Pour y aller, il faudrait trois choses :
  - une identité de génération de la base dans la clé, qui change à chaque `migrate:fresh` et à chaque restauration ;
  - le vidage de ce cache dans la procédure de reprise : sans lui, des identifiants redonnés après une restauration pourraient retrouver une empreinte égale ;
  - jamais un Redis partagé entre les copies.

  Il vaut mieux le garder en base.

### La clé

`tdb:v{version du code}:{current_database()}:{source}:{partie}:m{magasin_id}:{territoire}:{AAAA-MM}`

La valeur porte l'empreinte et les données.

| Si la clé oublie… | … alors |
|---|---|
| la base | deux copies d'un même modèle, aux mêmes identifiants, se mélangeraient dans un magasin partagé |
| le magasin, ou si elle assemble plusieurs magasins | un compte à la portée plus étroite verrait le total d'un magasin qu'il ne voit pas. **C'est une fuite.** |
| la source ou la partie | une carte lirait les données d'une autre |
| le territoire | un mois mal découpé après une correction de territoire |
| la version du code | un partiel calculé par l'ancien code serait servi |

La société n'a pas besoin d'être dans la clé : le magasin la désigne, et ses identifiants sont uniques dans la base. Le compte, le rôle, l'écran, le filtre et la période n'y sont **jamais**.

La table `cache` contient des données de magasins. Aucun écran ne la lit, et seule la couche des sources s'en sert, toujours derrière le garde du périmètre.

---

## 10. La version du code

La version du code entre dans chaque clé. Elle combine :

- une constante du calcul, changée à chaque changement d'une agrégation ;
- `PILIQI_VERSION` en production, donc chaque déploiement (`VersionServie::courante()`) ;
- en développement et en test, où `PILIQI_VERSION` est vide, une empreinte des fichiers du calcul (`app/Domain/TableauDeBord`, `TvaDeLaPeriode`, `CalculateurLigne`), calculée une fois par processus ;
- `timezone_version_get()`.

Un `cache:clear` au déploiement ne suffit pas : sur le site de démonstration, il ne vide que le cache du registre, jamais ceux des copies.

---

## 11. Carte par carte

Notations du tableau :

- « J » : partiel par jour ;
- « R » : partiel de répartitions ;
- « E3 » : l'empreinte (nombre, somme des id, plus grand id) ;
- « Ex » : l'empreinte `xmin` du § 4.3 ;
- « direct » : relu à chaque affichage.

| Carte | Sources | Dépend de | Par mois ? | Ce qui change son passé | Cache | Version | Risque | Verdict |
|---|---|---|---|---|---|---|---|---|
| Chiffre d'affaires HT par mois | factures et avoirs émis : date_emission, total_ht | période, découpage, cumul et exercice, comparaison (mêmes jours), jour de l'écran, magasins comptés, calendriers | oui | rien côté données ; cas de bord 2 à 4 du § 6 | J par magasin et par mois | E3 factures et avoirs | aucun | cache par mois |
| Ventes et achats HT par mois | ventes ci-dessus ; factures d'achat validées (date_facture ou date_validation, total_ht), avoirs fournisseur émis | idem, plus les achats vus | oui | achats datés du fournisseur, corrigés, reçus par l'API (§ 6, n° 5 à 8) | J ventes + J achats | E3 ventes ; Ex achats | faible | cache par mois |
| TVA à payer par mois (et report TVA27) | total_tva des ventes et des achats ; charges ; crédit déclaré ; régime ; arrivée de la société | magasins comptés, accès aux charges, charges de « Toute la société », franchise, cadence, ancre, jour de l'écran | oui, avec une dépendance en chaîne | achats ; charges ; crédit déclaré ; régime | TVA des ventes et des achats dans J ; charges, ancre et pli en direct | E3 ventes ; Ex achats | faible | cache par mois |
| Chiffre d'affaires HT par an (branche ; retiré par GRAPH-AN) | ventes sur 5 ans | dernier mois de la période, fin d'exercice, années vides cachées | oui | fin d'exercice (regroupement) | J ; années ou exercices assemblés en direct | E3 | aucun | cache par mois |
| Marge brute HT par mois | lignes de vente des articles, grand livre entier, produits, lots des réceptions validées | magasins comptés (ventes et stock), calendrier | oui, avec une dépendance en chaîne | correction d'une réception (tous les mois suivants) ; tout mouvement | historique rejoué du magasin, gardé entier par magasin et par fenêtre | empreinte du stock du magasin (§ 14) | faible | cache par période |
| Chiffre d'affaires par magasin | ventes ; noms et états des magasins | magasins comptés, écran (jamais le panneau d'un magasin), période | oui | noms, états, ordre | J ; étiquettes et ordre en direct | E3 | aucun | cache par mois |
| Ventes par taux de TVA | lignes : taux_tva, montant_ht | période, magasins comptés | oui | rien | R par taux, clés à 0 gardées | E3 | aucun | cache par mois |
| Particuliers et professionnels | factures et avoirs : type_client (figé), total_ht | période, magasins comptés | oui | rien | R par type | E3 | aucun | cache par mois |
| Encaissements par mode de paiement | paiements : recu_le, mode, montant ; avoir_allocations : created_at, montant | période, magasins où l'on voit les paiements de toute l'équipe | oui | paiement daté dans le passé ; contre-passation ; imputation après minuit | J paiements par mode (mois de recu_le) + J crédit (mois local) | E3 paiements ; E3 imputations | aucun | cache par mois |
| Meilleurs produits | lignes : produit, service, description, quantité, HT ; noms des magasins | période, mesure (HT ou quantité), magasins comptés | oui pour les sommes ; le classement se refait | rien côté données ; le libellé « dernière ligne lue » et les égalités dépendent de l'ordre de lecture | par mois, par clé : HT, quantité, libellé et son rang ; classement en direct | E3 | faible | cache par mois |
| Meilleurs clients | factures et avoirs : client_id, HT ; clients.nom, est_comptoir | période, magasins où l'on voit ventes et fiches | oui pour les sommes | noms changés, client archivé | par mois : HT par client ; noms, exclusion du comptoir et classement en direct | E3 | faible | cache par mois |
| Ventes par vendeur (GRAPH-AN) | factures : cree_par, cree_par_nom, HT ; lignes d'avoir et vente d'origine | période, magasins comptés | oui pour les sommes | rien (noms figés) ; à égalité de jour, le libellé dépend de l'ordre | par mois : HT par compte, nom du dernier jour ; classement en direct | E3 | faible | cache par mois |
| Achats par fournisseur | factures d'achat et avoirs fournisseur : fournisseur_id, HT ; fournisseurs.nom | période, achats vus | oui pour les sommes | achats datés, corrigés, fournisseur changé ; noms | par mois : HT par fournisseur ; noms et classement en direct | Ex | faible | cache par mois |
| Factures à encaisser par ancienneté | factures à encaisser : total_ttc, date_echeance, statut ; imputations | jour de chaque magasin, statuts, chaque règlement | non | chaque paiement, imputation ou passage en retard | aucun | — | aucun (rien n'est gardé) | jamais en cache |
| Valeur du stock au coût moyen | grand livre entier, produits, lots des réceptions | magasins où l'on voit le stock et sa valeur | oui, avec une dépendance en chaîne | correction d'une réception ; tout mouvement | comme la marge | empreinte du stock (§ 14) | faible | cache par période |
| Nombre de ventes et panier moyen | factures émises : nombre, total_ttc | période, découpage, comparaison, jour de l'écran | oui (nombre et TTC) | rien | J : nombre et TTC ; panier = arrondi de la somme des TTC sur la somme des nombres, à l'affichage | E3 | aucun | cache par mois |
| Avoirs émis par mois | avoirs émis : total_ht | magasins où l'on voit tous les avoirs | oui | rien | J avoirs, servi sous la source « Avoirs » | E3 avoirs | aucun | cache par mois |
| Ventes par jour de la semaine | factures et avoirs : jour du magasin, HT | période, jour de l'écran (diviseur) | oui (numérateur) | rien | J ; nombre de lundis à dimanches en direct | E3 | aucun | cache par mois |
| Articles et services | lignes : nature, montant_ht | période | oui | rien | R par nature | E3 | aucun | cache par mois |
| Écart des prix d'achat | lignes d'achat (produit, quantité, prix, remise, HT écrit) ; factures validées (date, rang, escompte) ; produits.nom | les 12 mois de la période (ou les 12 derniers en découpage par année), affichage, mois choisi, achats vus | selon l'affichage | achats corrigés ou datés ; produit renommé | liste brute des achats par magasin et par mois ; les deux calculs en direct (§ 12) | Ex factures et lignes | faible | cache par mois |
| Chiffre clé : CA HT du mois et variation | ventes du mois en cours et du même mois un an plus tôt (mêmes jours dans GRAPH-AN) | jour de l'écran, magasins comptés | oui | rien | mois en cours en direct ; l'an dernier depuis J | E3 | aucun | cache par mois |
| Chiffre clé : achats HT du mois et variation | achats | jour de l'écran | oui | achats datés ou corrigés | comme la carte des achats | Ex | faible | cache par mois |
| Chiffre clé : TVA à payer du mois | comme la carte de la TVA | comme elle | oui, avec une dépendance en chaîne | comme elle | comme elle | comme elle | faible | cache par mois |
| Chiffre clé : restant dû et part en retard | factures à encaisser | à ce jour | non | chaque règlement, passage en retard | aucun | — | aucun | jamais en cache |

### Les classements : une règle à écrire

Aujourd'hui, le libellé de « Meilleurs produits » est la description de la dernière ligne lue : factures par identifiant, puis avoirs par identifiant. L'ordre des lignes dans un même document n'est pas fixé.

Pour « Ventes par vendeur », à égalité de jour, c'est la dernière vente lue qui l'emporte.

Les égalités de valeur et de libellé gardent l'ordre de la première apparition (tri stable de PHP).

Un assemblage de mois ne retrouve ce résultat que si chaque partiel garde, pour chaque clé, le libellé et son rang. Mieux vaut écrire la règle, par une décision de Claude (D76) :

- le libellé est celui de la ligne la plus récente selon (jour, genre, numéro du document, numéro de ligne) ;
- une égalité se départage en dernier par la clé.

---

## 12. L'écart des prix d'achat (la question de l'éditeur)

La carte fait deux calculs à la fois, sur les mêmes douze mois (`EcartDesPrixDAchat::calculer()`) :

- **Par mois.** Chaque mois compare chaque achat d'un produit au **premier achat du même mois**. Un mois ne dépend que de lui-même : il se découpe parfaitement par mois.
- **Sur les 12 mois.** Le départ est le **premier achat de la période**. Quand la période glisse d'un mois (« 12 derniers mois » le 1er de chaque mois, ou l'année qui change), le départ change pour tous les produits dont le premier achat était dans le mois sorti de la période. Ce calcul ne se découpe pas par mois. Le garder sous une période, ce serait le servir faux le mois suivant.

**La solution.** Le cache ne garde **jamais** le résultat. Il garde, par (magasin, mois), la **liste brute** des achats du mois :

- pour chaque achat : le produit, le jour, le rang de validation, la quantité et le prix unitaire en centimes, déjà calculé par `CalculateurLigne` ;
- sans le nom du produit.

À chaque affichage :

1. lire les douze listes (du cache ou de la base, selon leur empreinte) ;
2. relire les noms actuels des produits par identifiant, en une requête, et ceux des magasins dans le périmètre ;
3. faire `AchatsParProduit::depuis()` puis `EcartDesPrixDAchat::calculer()`. C'est un calcul pur, en entiers, rapide.

Les deux affichages et le mois choisi sortent de ce même calcul. Changer d'affichage ou de mois ne lit plus rien en base. L'ordre est sûr : `AchatsParProduit` trie lui-même par (jour, rang) dans chaque produit, et par (produit, magasin) entre les produits. L'ordre dans lequel on assemble les mois n'y change donc rien.

**La version d'un mois** est l'empreinte `xmin` de ses factures d'achat validées et de leurs lignes. Elle voit :

- une facture corrigée, même à totaux égaux, puisque ses lignes sont recréées avec de nouveaux identifiants ;
- une facture qui change de mois quand on corrige sa date ;
- un escompte changé ;
- un achat validé aujourd'hui avec une date ancienne.

**Le risque est faible.** Les achats se modifient, mais l'empreinte voit toute écriture. Le seul danger serait qu'une lecture future ajoute une colonne d'une autre table sans l'ajouter à l'empreinte. Un test d'équivalence le couvre (§ 16).

---

## 13. La TVA et le crédit reporté (TVA27)

**Ce qui se garde.** La TVA collectée et la TVA déductible des achats, par magasin et par jour, dans les partiels des ventes et des achats.

**Ce qui se relit toujours :**

- les charges : `mois_tva` et l'état changent, une charge non validée se supprime, et le volume est petit (une requête indexée) ;
- l'ancre : la dernière déclaration de crédit, `CreditsDeTva::ancre()` ;
- la cadence, lue sur `regime_tva` ;
- le début de la chaîne (`CreditsDeTva::premierePeriode()`, depuis `societes.created_at`).

**Le report se recalcule à chaque affichage.** C'est un pli sur la suite des mois (`ReportDuCreditDeTva::calculer()`), pur et rapide. Avec TVA27, la fenêtre part de l'arrivée de la société et grandit chaque mois. Sans cache, chaque page relit donc des années de documents. Avec les partiels, seuls le mois en cours et les mois changés se relisent.

**Le report ne vaut que si la TVA compte toute la société**, d'après `T Perimetre::reporteLeCreditDeTva()` :

- tous ses magasins sont comptés ;
- les charges de « Toute la société » sont comptées.

Ce test se fait sur le périmètre, en direct.

---

## 14. Le stock : marge et valeur

Le coût moyen se rejoue produit par produit, depuis le premier mouvement (`StockLedgerService::coutMoyenRejoue()`) :

- avec les lots des réceptions **au coût actuel de leurs lignes**, à leur place d'origine ;
- avec, au départ, le coût moyen et le stock actuels du produit (l. 534-535). Ce point de départ ne pèse en pratique rien, puisqu'une première entrée sur un stock nul prend le coût du lot.

La valeur d'un mois dépend donc de toute l'histoire d'avant : **c'est une chaîne**.

### Ce qui est retenu

Un seul partiel par magasin, pour **tout l'historique rejoué** : la valeur, le coût et les ventes des articles suivis, jour par jour (`D HistoriqueDuStock`). Sa clé porte la fenêtre des lignes de vente de la marge.

Son empreinte, par magasin, réunit :

- les mouvements : nombre, somme des id, plus grand id ;
- les factures d'achat validées et leurs lignes (`xmin`) ;
- les produits (`xmin`), archivés compris ;
- les empreintes des ventes des mois de la fenêtre.

Chaque vente d'un article suivi ajoute un mouvement, donc invalide le partiel. Dans un magasin qui vend beaucoup, le gain se limite aux gestes et aux rechargements entre deux ventes, mais sans aucun risque. Le soir et pour les magasins calmes, le gain est entier.

### La variante en chaîne, plus économe, à trancher plus tard

Garder l'état (stock, coût moyen) de chaque produit à la fin de chaque mois, sous une empreinte en chaîne : l'empreinte du mois M combine celle du mois M−1 avec les mouvements du mois M et les réceptions placées dans le mois M. On ne rejouerait alors que le mois en cours.

Il faudrait pour cela que le rejeu sache repartir d'un état gardé. Sans mesure qui le demande, ce n'est pas utile.

---

## 15. Index, taille, purge

### Index qui servent déjà

| Table | Index |
|---|---|
| `factures`, `avoirs` | `(magasin_id, date_emission)` |
| `paiements` | `(magasin_id, recu_le)` |
| `avoir_allocations` | `(magasin_id, created_at)` |
| `factures_achat` | `(magasin_id, statut, date_facture)` |
| `avoirs_fournisseur` | `(magasin_id, statut, date_emission)` |
| `lignes_achat` | `(magasin_id, facture_achat_id)` |
| `charges` | `(societe_id, mois_tva)` |

### Index à ajouter, dans les migrations de création

| Table | Index | Ce qu'il permet |
|---|---|---|
| `factures` | `(magasin_id, date_emission) INCLUDE (id) WHERE numero IS NOT NULL AND statut <> 'brouillon'` | empreintes des ventes en parcours d'index seul |
| `avoirs` | le même, avec `statut = 'emise'` | idem |
| `paiements` | `(magasin_id, recu_le) INCLUDE (id)` | empreintes des paiements en index seul |
| `mouvements_stock` | `(magasin_id, id)` | nombre et plus grand identifiant par magasin, sans tout parcourir |

### Taille

Une seule entrée par (magasin, mois, partie), écrasée quand l'empreinte change. L'empreinte va dans la valeur, jamais dans la clé.

Il faut une durée de vie d'environ 40 jours, plus une purge de nuit des entrées d'une ancienne version du code. Sans elle, les entrées d'un ancien code resteraient jusqu'à leur fin de vie, puisque le magasin `database` ne les efface qu'à leur lecture.

### Charger seulement ce qui sert

Les parties lourdes (par produit, par client) ne se chargent que si une carte affichée les lit.

---

## 16. Les tests qui prouvent qu'il n'y a pas de risque

1. **Équivalence.** Pour chaque carte, chaque écran, chaque période, chaque découpage et chaque affichage de l'écart, trois calculs doivent être égaux :
   - avec le cache vide ;
   - avec le cache plein ;
   - avec le cache plein après un changement.

   Le semis est vraisemblable, avec des magasins en métropole, en Martinique et à La Réunion. Faker est semé. Les changements à jouer :
   - une vente ;
   - une facture d'achat validée corrigée, colonne par colonne, y compris à totaux égaux ;
   - un achat daté d'un mois ancien ;
   - un paiement antidaté ;
   - une charge changée de mois ;
   - un crédit de TVA déclaré ;
   - un produit renommé.
2. **Bords du calendrier**, temps figé à des instants écrits avec leur fuseau :
   - une vente validée juste après minuit ;
   - Paris le 1er décembre à 4 h face à la Martinique ;
   - une facture à un professionnel après le ticket Z.
3. **Concurrence**, dans le groupe de concurrence : une vente validée entre les deux lectures d'empreinte. Le partiel n'est pas écrit, et la page suivante est juste.
4. **Droits.** Un administrateur perd une case, un client retire une consultation au cabinet, un lien prend fin. Le magasin n'est plus compté, même avec son partiel en cache.
5. **Isolation.**
   - Deux copies d'un même modèle, avec un même magasin et des données différentes.
   - Une copie recommencée.
   - `migrate:fresh` avec d'autres prix.
   - Un changement de la version du code.

   Aucun partiel d'une autre base ni d'un autre code n'est servi. Hors d'une copie, la lecture échoue au lieu de lire le registre.
6. **Gardes.** Le test du § 4.2 sur les colonnes qui suivent un document. Un test que l'empreinte `xmin` reste la même après `VACUUM (FREEZE)`.
7. **Plafond de requêtes.** Le nombre reste le même avec un ou trois magasins. Avec le cache plein, aucune table de lignes n'est lue pour un mois passé.

---

## 17. Ce qui reste à trancher

- **La règle du libellé et des égalités** des trois classements. C'est une décision de Claude (D76), à écrire avant le cache, car elle compte aussi sans cache.
- **La variante en chaîne du stock** (§ 14), seulement si une mesure au volume de la campagne le demande.
- **La mesure au volume.** Il faut mesurer la requête d'empreinte sur les 132 mois du découpage par année, avec beaucoup de magasins, et le coût de l'empreinte du stock d'un gros magasin.

---

## 18. Remarques hors cache, relevées en lisant

- Les ventes se lisent sur `statut <> 'brouillon'`, alors que la garde définit « émis » par `numero IS NOT NULL`, et qu'aucune contrainte ne lie les deux. Il faudrait aligner les deux, ou ajouter une contrainte `CHECK ((statut = 'brouillon') = (numero IS NULL))`.
- Un paiement peut se dater de n'importe quel jour passé. Le tableau de bord le range à ce jour-là, alors que le ticket Z le compte le jour où il est saisi. C'est cohérent avec la règle « au jour où il est reçu », mais la carte « Encaissements » d'un mois clos peut donc bouger. À dire dans sa ligne grise, ou à borner (une date pas antérieure à la facture ?) : c'est à l'éditeur de choisir.
- Ce que je relève sur les gestes « Monter », « Descendre », type, « Afficher » et mois de l'écart rejoint le lot « Gestes des cartes sans attente ». Ces gestes ne changent aucune donnée. Avec le cache, leur rendu ne relit plus les sources, mais il relit encore deux fois le périmètre. Les faire dans le navigateur reste la vraie réponse.
