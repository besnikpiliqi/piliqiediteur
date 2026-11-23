# Cache des cartes alimentées par les ventes : analyse

Le 23/11/2026, en lecture seule. Code lu :

- la branche de travail, dans `/home/user/piliqiediteur` (tête 36b2388c) : `app/Domain/TableauDeBord`, `app/Filament/GrilleDesGraphiques.php`, les actions d'émission, les gardes de la base, la démonstration ;
- GRAPH-AN 1 (commit 0257979e), dans la copie de lecture `.../scratchpad/wt-demo` : le calcul par mois, par trimestre ou par année, le cumul, l'écart, la tendance, le détail d'une barre et « Ventes par vendeur » ;
- TVA27 (commit fbdf759a), dans la copie de lecture `.../scratchpad/wt-tva` : il allonge la fenêtre de lecture des ventes jusqu'au début de la chaîne du crédit de TVA (`premierJourDeLaTva()`).

Aucun fichier du dépôt n'a été modifié. J'ai seulement lancé des requêtes de lecture sur la base semée `piliqi_semis` (4 007 factures, 118 avoirs, 22 magasins).

---

## 1. La réponse courte

**Côté ventes, la base ne laisse pas changer le passé.**

- Un ticket, une facture ou un avoir émis ne se modifie plus et ne se supprime jamais. Ses lignes non plus. Les déclencheurs posés par `GardesDesDocumentsEmis` le garantissent : après le numéro, seuls changent encore le statut de règlement d'une facture, le ticket Z qui l'a comptée et `updated_at`.
- La date d'émission est l'instant du registre au moment de l'émission. `RegistreFiscal::verrou()` prend `now()` à la seconde, et refuse un instant plus ancien que le dernier verrou du magasin.
- Une correction est donc un avoir daté du jour de son émission. C'est vrai aussi pour la facture rectificative et l'avoir de régularisation de la procédure du SIREN.
- Les noms restent figés sur les documents : celui du client (`client_nom`), celui du vendeur (`cree_par_nom`), et le libellé de chaque ligne (la ligne d'avoir reprend celui de la ligne vendue). Les montants sont figés en centimes.

**Mais « un mois fini ne change plus » est faux dans trois cas réels.** Avec une règle « mois passé = figé », le chiffre resterait faux pour toujours.

1. **Magasin d'outre-mer.** L'espace de la société, l'espace du cabinet et la page de l'expert-comptable comptent leurs mois depuis le jour de Paris (`PerimetreDesGraphiques` : `Calendrier::deParis()`). Chaque vente, elle, est rangée dans le jour de son magasin (`JoursDesMagasins::jourDe()`). Exemple : le 1er décembre à 4 h, heure de Paris, il est encore 23 h le 30 novembre à Fort-de-France (UTC−4 ; 5 h d'écart en hiver, 6 h en été). Pendant ces heures, la Martinique vend encore en novembre alors que Paris est déjà en décembre. Inversement, La Réunion (UTC+4) vend déjà en décembre quand Paris est encore en novembre.
2. **Vente validée juste après minuit.** L'instant est pris au verrou du registre, en tête de la transaction, et tronqué à la seconde. La validation n'arrive qu'après le contrôle du stock, la numérotation, les paiements et la signature. Une vente horodatée 23:59:59 le 31 peut donc n'apparaître en base qu'à 00:00:01 le 1er.
3. **La clôture du jour ne fige pas la journée.** Après le ticket Z, seuls les tickets et les avoirs qui corrigent des tickets sont refusés :
   - `EmettreFactureAction` (l. 278-280) n'appelle `ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()` que pour un ticket ;
   - `EmettreAvoirAction` (l. 125-127) ne l'appelle que si l'avoir corrige des tickets.

   Une facture ou un avoir à un professionnel s'émet donc encore le jour même, jusqu'à minuit du magasin. Le ticket Z ne peut pas servir de marque « journée finie ».

**Certaines choses changent ce qu'on affiche du passé, sans toucher aux ventes :**

- le nom actuel d'un client : « Meilleurs clients » le relit sur sa fiche ;
- le nom et l'état d'un magasin : « (fermé) », « (cédé) », et l'ordre par nom ;
- le mois de fin d'exercice, que la procédure de l'éditeur peut changer ;
- les droits, c'est-à-dire les magasins comptés ;
- le jour de l'écran : mois à venir, barre en cours, « mêmes jours de l'an dernier », nombre de lundis de la période.

**Enfin, des événements de la base elle-même :**

- `migrate:fresh --seed` ;
- une restauration de sauvegarde ;
- une copie de démonstration recommencée ou refaite (mêmes identifiants de magasin, autres données) ;
- un déploiement qui change un calcul.

**La position de Claude est confirmée pour les ventes, avec cinq règles :**

- **(a)** Mettre en cache par magasin et par mois du calendrier de ce magasin. Jamais par compte, jamais par écran, jamais par période déjà assemblée (trimestre, année, exercice, 12 mois).
- **(b)** Ne garder que des sommes partielles en centimes et des libellés figés sur les documents. Jamais :
  - une étiquette lue en direct ;
  - un droit ;
  - une moyenne ou un panier moyen ;
  - un classement terminé ;
  - une série dessinée.
- **(c)** Calculer chaque carte toujours depuis ces sommes partielles, qu'elles sortent du cache ou de la base. Avec un seul chemin de calcul, le cache ne peut pas donner autre chose que le calcul frais.
- **(d)** Protéger chaque mois de chaque magasin par une empreinte relue à chaque affichage : nombre, somme et plus grand identifiant des factures émises, et la même chose pour les avoirs émis. Le mois en cours de chaque magasin, pris dans son calendrier et non dans celui de l'écran, se recalcule toujours.
- **(e)** Ranger ce cache dans la base de la connexion (magasin de cache « database »). La clé porte :
  - l'identité de la base ;
  - le territoire du magasin ;
  - une version du calcul.

Avec ces règles, le risque restant est nul pour les cartes de sommes. Il est faible pour les trois classements (produits, vendeurs, clients), à une condition : écrire la règle du libellé et du départage des égalités. Aujourd'hui, le code laisse ces deux points à l'ordre de lecture (§ 6).

**Sur la proposition de l'éditeur.** Elle consiste à garder les années et les mois anciens sans version, et à versionner les 3 à 6 derniers mois. Pour les seules données de ventes, elle tiendrait : les trois cas de bord ci-dessus tombent tous dans les derniers jours. Elle ne tiendrait pas dans les cas suivants :

- une barre d'exercice gardée telle quelle après un changement du mois de fin d'exercice ;
- un `migrate:fresh` ou une restauration, si le cache vit hors de la base ;
- un calcul changé au déploiement ;
- les achats et les charges, qui changent vraiment le passé.

Comme l'empreinte de chaque mois ne coûte qu'une requête de regroupement (mesurée au § 5), autant l'appliquer à tous les mois et à toutes les sources.

---

## 2. Ce que lisent les cartes de ventes

**Ce qui est figé et lu en base**, par `VentesDuTableauDeBord` (branche de travail et GRAPH-AN) :

| Table | Colonnes lues | Filtre | Changent-elles après l'émission ? |
|---|---|---|---|
| `factures` (tickets et factures) | id, magasin_id, date_emission, total_ht, total_tva, total_ttc, type_client, client_id, client_nom, facture_rectifiee_id ; GRAPH-AN : cree_par, cree_par_nom | `statut <> 'brouillon'` (l. 222-226) | Non, sauf `statut`, `updated_at` et `cloture_caisse_id` (garde l. 46). Le statut ne sert qu'à dire « émise ou non ». |
| `avoirs` | id, magasin_id, date_emission, total_*, type_client, client_id, client_nom, motif_regularisation ; GRAPH-AN : cree_par, cree_par_nom | `statut = 'emise'` | Non, sauf `updated_at` et `cloture_caisse_id`. |
| `lignes_facture` | nature, description, quantite, taux_tva, montant_ht/tva/ttc, produit_id, service_id | jointure sur la facture émise (clé composée magasin_id) | Non : la garde refuse tout ajout, toute modification et toute suppression dès que la facture a un numéro. |
| `lignes_avoir` | mêmes colonnes, plus ligne_facture_id, qui mène à la ligne vendue (service_id, facture_id) puis, dans GRAPH-AN, à la vente d'origine (cree_par, cree_par_nom) | jointure sur l'avoir émis | Non. |

**Ce qui bouge et doit toujours se relire en direct :**

| Donnée | Où | Rôle dans les cartes |
|---|---|---|
| `clients.nom` (et `est_comptoir`, posé seulement à la création du magasin) | `VentesDuTableauDeBord::nomsDesClients()` (l. 80-95) | nom des « Meilleurs clients », départage des égalités, exclusion du client comptoir |
| `magasins.nom`, `statut`, `motif_fermeture` | `Perimetre::nomDuMagasin()` (l. 107-114), `Perimetre::magasins()` (l. 121-124), `classement()` | étiquettes, « (fermé) » et « (cédé) », ordre des magasins, suffixe « · magasin » |
| `magasins.territoire` | `Magasin::calendrier()` (l. 648-651), fixé une fois pour toutes (l. 267-269) | jour, mois et minuit de chaque vente |
| `societes.mois_fin_exercice` | `Perimetre::moisDeFinDExercice()` | bornes des périodes « exercice », cumul, barres par exercice |
| droits (cases, liens de cabinet, déclaration) | `PerimetreDesGraphiques`, `SourceDesGraphiques` | magasins comptés, cartes visibles, phrase grise |
| jour de l'écran | `Perimetre::aujourdhui()` : Paris, ou le jour du magasin dans son panneau | mois en cours, mois à venir, barre en cours, mêmes jours de l'an dernier, dénominateur des jours de la semaine |

---

## 3. Ce qui peut changer le passé : inventaire vérifié dans le code

| # | Événement | Effet sur les cartes de ventes | Où dans le code | Protection proposée |
|---|---|---|---|---|
| 1 | Émission d'une facture, d'un ticket ou d'un avoir | ajoute un document au jour d'aujourd'hui (instant du registre) | `EmettreFactureAction` l. 226 et 287-294 ; `EmettreAvoirAction` l. 96 et 134-139 ; `RegistreFiscal` l. 566-582 | empreinte du mois ; mois en cours de chaque magasin toujours recalculé |
| 2 | Validation qui arrive après minuit du magasin | une vente de 23:59:59 entre dans un mois déjà passé | instant pris au verrou, en tête de transaction (`startOfSecond`, l. 568) | empreinte du mois ; le mois précédent se recalcule aussi pendant le premier jour du mois du magasin |
| 3 | Magasin d'outre-mer quand Paris a changé de mois | jusqu'à 6 h de ventes dans un mois que l'écran de Paris croit fini | `PerimetreDesGraphiques` l. 76, 151, 190 (Paris) ; `JoursDesMagasins` l. 65 | découpe par mois du calendrier de chaque magasin, mois en cours pris dans le calendrier du magasin |
| 4 | Facture ou avoir à un professionnel après la clôture du jour | la journée « close » reçoit encore des ventes jusqu'à minuit | `EmettreFactureAction` l. 278-280 ; `EmettreAvoirAction` l. 125-127 | ne jamais se fier au ticket Z ; empreinte |
| 5 | Modification ou suppression d'un document émis ou de ses lignes | impossible : la base refuse | `GardesDesDocumentsEmis` ; migrations `factures` et `avoirs` (`restrictOnDelete`) | aucune |
| 6 | Statut de règlement (payée, en retard, contre-passation) ; ticket Z posé | aucun : les cartes ne lisent que « émise ou non » | `StatutFacture`, tâches du calendrier (passage en retard), `GardesDesClotures` | ne pas mettre `updated_at` dans l'empreinte, sinon un mois ancien se recalcule à chaque paiement |
| 7 | **Trou de la garde** : `statut` remis à « brouillon » sur une facture numérotée par une requête brute | la garde le laisse passer, puisque `statut` fait partie des colonnes qui suivent : le document sortirait des cartes | `GardesDesDocumentsEmis` l. 46 et 128 ; aucune contrainte ne lie `statut` et `numero` | l'empreinte le voit, car le nombre baisse. Durcissement : lire `numero IS NOT NULL` (la définition « émis » de la garde) et ajouter `CHECK ((statut = 'brouillon') = (numero IS NULL))` sur `factures` et `avoirs` |
| 8 | Document « émis » sans numéro (statut différent de brouillon, numero NULL) | compté par les cartes, avec des lignes encore modifiables | même trou ; la base semée est cohérente : 1 brouillon sans numéro ni date, 4 007 factures émises numérotées et datées | même durcissement |
| 9 | Client renommé, archivé ou restauré | « Meilleurs clients » lit le nom actuel ; un client archivé reste nommé ; le client comptoir est toujours exclu | `nomsDesClients()` | nom relu en direct, jamais gardé en cache |
| 10 | Magasin renommé, fermé, rouvert, cédé, ou cession annulée | étiquettes « (fermé) » et « (cédé) », ordre par nom, suffixe « · magasin » ; aucune vente ne bouge (`AnnulerCessionAction` : « factures, paiements et archives restent où ils sont ») | `Perimetre::nomDuMagasin()`, `classement()` | étiquettes et ordre relus en direct |
| 11 | Territoire du magasin | change le jour de chaque vente ; il ne change plus une fois fixé, sauf correction de l'éditeur tant qu'aucun document n'est numéroté | `Magasin` l. 267-269 et 296 | territoire dans la clé |
| 12 | Produit, service ou compte renommé ; compte retiré | rien : la ligne garde sa description (`ConstructeurLigneDocument` l. 296 ; la ligne d'avoir reprend celle de la ligne vendue, `CreerAvoirAction` l. 164) et la vente garde `cree_par_nom` (`FigeLeNomDeSesAuteurs`) | — | aucune |
| 13 | Mois de fin d'exercice changé (procédure de l'éditeur) | regroupe autrement les mêmes mois : « CA par an » par exercice, barres par année et trimestres « T1 2025-26 » de GRAPH-AN, cumul depuis le début de l'exercice, bornes « exercice en cours » et « exercice précédent » | `ChangerFinExerciceAction`, `CalculDesSeries::annees()`, `DecoupageDesPeriodes` | ne garder que des mois, jamais une période assemblée |
| 14 | Régime de TVA, franchise en base, option pour les débits | rien sur les montants émis (`franchise_en_base` est figée sur chaque document) ; seule la carte de la TVA apparaît ou disparaît | `Perimetre::voit()` | aucune |
| 15 | Correction du SIREN | un avoir de régularisation et une facture rectificative émis aujourd'hui ; le passé ne bouge pas | `EmettreLaFactureRectificativeAction` | aucune |
| 16 | Droits changés, lien de cabinet terminé, déclaration de l'expert-comptable retirée | change les magasins comptés, pas les ventes | `PerimetreDesGraphiques` | périmètre recalculé à chaque requête, avant toute lecture du cache |
| 17 | `migrate:fresh --seed` | mêmes identifiants, autres données. Si seuls les prix du semis changent, nombre et identifiants restent les mêmes : une empreinte par identifiants ne voit rien | — | cache rangé dans la base (la table `cache` est recréée vide) ; identité de la base dans la clé |
| 18 | Copie de démonstration créée, recommencée, refaite ; modèle refait chaque nuit | toutes les copies d'un type ont les mêmes identifiants de magasin, avec des données différentes | `CopiesDeLaDemonstration` (l. 116 : un nouveau nom de base à chaque fois) ; `OuvertureDUneCopie` (l. 134 : le cache par défaut devient « database » sur la base de la copie) ; `BrancherUneBase::oublierLesServicesResolus()` | cache rangé dans la base de la copie ; jamais le magasin « file », dont le dossier (`storage/framework/cache/data`) n'est pas rangé sous celui de la copie et serait donc partagé entre les copies. Une copie hérite des lignes de cache de son modèle : elles décrivent les mêmes données, et l'empreinte voit tout ajout fait dans la copie |
| 19 | Restauration d'une sauvegarde (étape 15) | des documents perdus puis reconstitués, des identifiants redonnés | — | cache dans la base, restauré avec elle ; la procédure de reprise vide en plus le cache du tableau de bord |
| 20 | Déploiement d'un calcul changé (GRAPH-AN change par exemple ce que compte un vendeur) | un partiel calculé par l'ancien code | — | version du calcul dans la clé, et vidage au déploiement |
| 21 | Importation d'un historique de ventes | n'existe pas ; l'API de l'étape 10 passera par les actions d'émission, datées d'aujourd'hui | — | une future importation datée dans le passé changerait l'empreinte (nouveaux identifiants) |
| 22 | Une carte qui rangerait un jour les ventes par date de livraison ou de prestation (T49), et non par date d'émission | aucune ne le fait aujourd'hui ; si une carte le faisait, une facture émise aujourd'hui changerait un mois passé | — | l'empreinte devrait alors porter sur cette date |

---

## 4. Le calendrier de chaque magasin

- Le jour d'une vente se calcule en PHP par `Calendrier` (`JoursDesMagasins::jourDe()`), dans le fuseau du territoire du magasin : Europe/Paris pour la métropole et la Corse, America/Martinique, America/Guadeloupe, Indian/Reunion.
- Le mois d'une vente est donc celui de son magasin. Le cache se découpe par **(magasin, mois du calendrier du magasin)**, jamais par mois de l'écran.
- Les bornes d'un mois (ses deux minuits locaux, en UTC) se calculent en PHP par `Calendrier`, puis se passent à la requête. Aucun fuseau n'est écrit en SQL : la règle « seule la classe Calendrier connaît un fuseau » est respectée.
- **« Mois en cours toujours recalculé »** veut dire le mois en cours de chaque magasin dans son propre calendrier, plus le mois précédent pendant le premier jour du mois du magasin (validations encore en vol). Pour le reste, l'empreinte suffit. Le mois en cours de l'écran (Paris) ne sert qu'à dire quelles barres sont à venir.
- Ce qui dépend du jour de l'écran se calcule toujours à l'affichage, depuis des sommes par jour :
  - les mois à venir ;
  - la barre « en cours » de GRAPH-AN (`Barre::enCours`) ;
  - « mêmes jours de l'an dernier » (`Barre::unAnPlusTot()` l. 59, `Mois::memeJourUnAnPlusTot()` l. 116, chiffres clés de GRAPH-AN l. 63) ;
  - le nombre de lundis à mardis de la période (« Ventes par jour de la semaine »).

  C'est pourquoi un mois gardé en cache porte **ses sommes jour par jour**, et pas seulement son total.
- **Passage à l'heure d'été ou d'hiver** : un minuit n'y tombe jamais, et `Calendrier` donne les bornes exactes. Rien à faire.
- **Mise à jour de la base des fuseaux** (tzdata) : elle ne change pas en pratique les dates passées de ces territoires. Le coût étant nul, on peut mettre `timezone_version_get()` dans la version du calcul.

---

## 5. Le numéro de version d'un mois : son empreinte

### Définition

Pour chaque (magasin, mois local), deux triplets :

- factures émises : (nombre, somme des id, plus grand id) ;
- avoirs émis : (nombre, somme des id, plus grand id).

Des documents émis ne se modifient pas, ne se suppriment pas et ne sortent pas de leur mois. Leur ensemble ne peut donc que grossir, et chaque ajout change le nombre.

- La somme et le plus grand identifiant couvrent le cas d'école d'une sortie suivie d'une entrée (trou de la garde, § 3 n° 7).
- Les lignes n'ont pas besoin d'empreinte : elles sont figées avec leur document, une fois le durcissement fait.
- `updated_at` est volontairement exclu : il change à chaque paiement et à chaque passage en retard, ce qui recalculerait des mois anciens pour rien.
- Aucune somme de la base sur une colonne décimale : on somme des identifiants entiers, ce qui respecte la règle des montants.

### Requête

Une requête par table pour toute la fenêtre, avec les bornes venues de PHP en `VALUES` et un `LATERAL` qui suit l'index `(magasin_id, date_emission)`.

```sql
SELECT b.magasin_id, b.mois, e.nombre, e.somme, e.dernier
FROM (VALUES (…, '2026-01', '2025-12-31 23:00:00', '2026-01-31 23:00:00'), …) AS b(magasin_id, mois, debut, fin)
CROSS JOIN LATERAL (
  SELECT count(f.id) AS nombre, coalesce(sum(f.id), 0) AS somme, max(f.id) AS dernier
  FROM factures f
  WHERE f.magasin_id = b.magasin_id AND f.numero IS NOT NULL
    AND f.date_emission >= b.debut AND f.date_emission < b.fin
) e;
```

Mesure sur `piliqi_semis` (requêtes à côté de cette analyse : `empreinte-lateral.sql` et `empreinte-naive.sql`) :

- 22 magasins × 13 mois = 286 mois, en **7,7 ms** ;
- parcours de l'index existant, puis lecture de la table pour `numero` et `id` ;
- un index partiel couvrant, `CREATE INDEX … ON factures (magasin_id, date_emission) INCLUDE (id) WHERE numero IS NOT NULL` (et le même sur `avoirs`), en ferait un parcours d'index seul ;
- la forme naïve, une jointure avec filtre, prenait 45 ms.

Il faudra mesurer au volume de la campagne de tests, surtout pour « Découper par : année » de GRAPH-AN, qui lit dix ans.

### Lecture cohérente

C'est le seul vrai piège d'écriture. Si l'on lisait les documents d'un mois, puis l'empreinte, et qu'une vente arrivait entre les deux, on rangerait sous l'empreinte récente un contenu qui ne l'a pas : chiffre périmé pour toujours.

La règle : **un partiel se range avec l'empreinte calculée sur les lignes mêmes qui l'ont produit.** On compte, on somme et on prend le maximum des identifiants des documents agrégés.

À l'affichage :

1. lire les empreintes actuelles (requête ci-dessus) ;
2. lire en une fois les partiels en cache (`Cache::many`) ;
3. réutiliser un partiel seulement si son empreinte, sa version du calcul et son territoire sont les mêmes ;
4. sinon le recalculer et l'écraser.

### Taille bornée

- Une seule entrée par (magasin, mois, partie), écrasée quand l'empreinte change. L'empreinte va dans la valeur, pas dans la clé : la table ne grossit pas d'une ligne à chaque vente.
- La table `cache` compte dans `pg_database_size` d'une copie de démonstration, dont la place est de trois fois son modèle (`PlaceDesCopies`). Il faut donc des parties compactes.

### Variante plus économe, non retenue par défaut

- Pour les ventes, un mois fini depuis plus de deux jours dans le calendrier de son magasin ne peut plus changer, une fois le durcissement fait (§ 3 n° 7-8) et le cache rangé dans la base.
- On pourrait donc ne plus relire son empreinte, et la vérifier une fois par nuit avec la vérification d'intégrité, en purgeant tout écart.
- Je ne la propose pas pour commencer : l'éditeur veut « aucun risque », et la relecture coûte quelques millisecondes.
- Elle ne vaut **jamais** pour les achats ni pour les charges.

---

## 6. Les classements

Les classements concernés : « Meilleurs produits », « Meilleurs clients », « Ventes par vendeur », et l'ordre de « Chiffre d'affaires par magasin ».

### Un classement des dix premiers n'est pas décomposable, ses sommes par clé le sont

Les dix premiers de l'année ne sont pas les dix premiers de chacun des mois. En revanche, les sommes par clé s'additionnent mois par mois.

- On garde donc, par mois, la somme de chaque clé : produit ou service, client, compte vendeur.
- Le classement se refait à chaque affichage sur la somme des mois de la période, après les filtres « valeur > 0 » et l'exclusion du client comptoir.
- Si les parties par clé deviennent trop grosses, il existe un repli : garder le classement fini d'une période sous la liste des empreintes de tous ses mois et de tous les magasins comptés.
  - Ce classement est invalidé à chaque vente du mois en cours.
  - Il ne vaut que pour les produits et les vendeurs : les noms des clients restent relus à chaque affichage.

### Le libellé d'un classement dépend de l'ordre de lecture

Aujourd'hui, ce libellé n'est défini que par l'ordre dans lequel les lignes sont lues.

- **« Meilleurs produits »** (branche l. 331-343). Le libellé est la description de la dernière ligne lue : toutes les lignes de factures, par numéro de facture, puis toutes les lignes d'avoirs, par numéro d'avoir.
  - Un avoir sur une vente ancienne porte l'ancien nom d'un produit renommé, et l'emporte.
  - Dans un même document, l'ordre des lignes n'est pas fixé (`orderBy` sur le document seulement). Un service sans prix de référence, vendu sur deux lignes, a donc un libellé que rien ne fixe.
- **« Ventes par vendeur »** (GRAPH-AN, `ventesParVendeur()` l. 592-609). Le libellé est le nom de l'entrée la plus récente de la période, avoirs compris. Le même jour, la ligne d'avoir, lue après les factures, l'emporte avec le nom figé de la vente d'origine, peut-être ancien.
- **Égalités.** `classement()` trie par (valeur, libellé). À valeur et libellé égaux, l'ordre est celui de la première apparition (tri stable de PHP 8) : il dépend lui aussi de l'ordre de lecture.

Si l'on assemble des mois venant du cache dans un autre ordre que la requête globale, ces trois résultats peuvent différer du calcul frais.

### Ce qu'il faut faire

- Écrire la règle (décision de Claude, D76). Par exemple :
  - libellé = celui de la ligne la plus récente selon (jour, genre, numéro du document, numéro de ligne) ;
  - égalité départagée en dernier par la clé.
- Garder dans le partiel de chaque mois, pour chaque clé, ce libellé et son rang, que l'assemblage compare.
- Toujours calculer depuis les partiels, frais ou en cache : un seul chemin de calcul.
- **« Meilleurs clients »** : le départage se fait sur le nom **actuel** du client. On garde en cache les sommes par client, jamais les noms ni le classement.
- **« Chiffre d'affaires par magasin »** : les sommes par magasin viennent des partiels ; les étiquettes et l'ordre par nom sont relus en direct.

### Ce qui ressemble à « Écart des prix d'achat » côté ventes

Aucune carte de ventes ne compare un document à une référence prise dans la période, comme le premier achat de la période. Plusieurs résultats dépendent pourtant des bornes de la période :

- le libellé « le plus récent de la période » (produits, vendeurs) ;
- les années vides du début, cachées (« CA par an » sur cinq ans, barres par année sur dix ans de GRAPH-AN) ;
- le nombre de chaque jour de la semaine jusqu'à aujourd'hui ;
- le cumul depuis le début de l'année ou de l'exercice ;
- les mêmes jours de l'an dernier ;
- la tendance sur trois barres.

Tous se calculent **après** l'assemblage des mois, à chaque affichage. C'est ce qui rend ces cartes sûres. Pour l'écart des prix d'achat, la même idée s'applique, mais c'est l'objet de l'analyse des achats : garder par mois ce qui permet de recomposer n'importe quelle période, jamais le résultat d'une période.

---

## 7. Où vit le cache, et ce que porte la clé

### Où

Le magasin de cache « database », sur la connexion par défaut. Ainsi :

- sur le site réel, la table `cache` de la base réelle ;
- dans une copie de démonstration, la table `cache` de la base de la copie (`OuvertureDUneCopie` l. 134 ; `BrancherUneBase` oublie les magasins de cache résolus) ;
- le cache naît et meurt avec sa base : `migrate:fresh`, nouvelle copie, copie recommencée ou refaite (nouveau nom de base à chaque fois), restauration ;
- le site réel et le site de démonstration ne se partagent rien.

Il faut épingler ce magasin pour ce cache. Même si l'on passe le cache par défaut du site réel à Redis (étape 15), les copies ne doivent jamais se partager un Redis avec les mêmes identifiants de magasin. Le magasin « file » est à proscrire (§ 3 n° 18).

### Clé

`tdb:ventes:v{VERSION_DU_CALCUL}:{nom de la base}:{oid de la base}:m{magasin_id}:{territoire}:{AAAA-MM}:{partie}`

- L'identité de la base (`current_database()` et l'oid de `pg_database`) protège si un jour quelqu'un change de magasin de cache.
- La version du calcul est une constante changée avec tout changement d'agrégation, plus `timezone_version_get()`. On vide aussi ce cache au déploiement.

### Valeur

- Des tableaux d'entiers et de chaînes seulement : `serializable_classes` vaut `false` (config/cache.php l. 155), donc aucun objet, ni `SommesParJour`, ni `MontantsDeTva`, ni Carbon.
- Avec : l'empreinte, la version, le territoire et les données.

### Parties d'un mois, chacune chargée seulement si une carte affichée la lit

| Partie | Contenu | Source exigée |
|---|---|---|
| `jours` | jour → [HT des ventes, TVA des ventes, TTC des ventes, nombre de ventes, HT des avoirs, TVA des avoirs, TTC des avoirs, nombre d'avoirs] | Ventes |
| `repartitions` | type de client → HT net ; taux → HT net (**garder les clés à 0**, comme le calcul frais) ; nature service ou autre → HT net | Ventes |
| `produits` | clé → [HT net, quantité nette, libellé, rang du libellé] | Ventes |
| `clients` | client_id → HT net (les noms restent hors du cache) | Ventes (les noms se relisent sous Clients) |
| `vendeurs` | compte (ou « sans ») → [HT net, libellé, rang], avec la règle de l'écart de centimes de l'avoir sur sa première ligne | Ventes |
| `avoirs` | jour → [HT des avoirs, nombre] | Avoirs |

### Droits

- Chaque lecture du cache passe d'abord par `Perimetre::exiger($magasins, Source)` (l. 238-247), comme la lecture en base aujourd'hui.
- Les partiels d'un magasin contiennent **toutes** ses ventes. Ils ne sont servis qu'à qui voit toutes les ventes de ce magasin (`SourceDesGraphiques::Ventes` : FACTURE_VIEW, FACTURE_VIEW_TOUTES et AVOIR_VIEW), ou tous ses avoirs pour la partie `avoirs`.
- Ils ne servent jamais à « Mes ventes » d'un vendeur.
- Rien n'est gardé par compte : ni périmètre, ni réglages, ni série finale.

---

## 8. Carte par carte

Notations :

- « J » : partie `jours`, « R » : partie `repartitions` ;
- « empreinte » : le couple de triplets du § 5, par (magasin, mois local) ;
- « en direct » : relu à chaque affichage, jamais gardé en cache.

| Carte | Sources | Dépend de | Décomposable | Ce qui peut changer son passé | Mise en cache | Risque restant | Verdict |
|---|---|---|---|---|---|---|---|
| Chiffre d'affaires HT par mois (GRAPH-AN : par mois, trimestre ou année ; cumul, comparaison, écart, tendance, détail en jours, liste d'un jour) | factures et avoirs : date_emission, total_ht | période, découpage, cumul et exercice, comparaison, jour de l'écran, magasins comptés, calendrier de chaque magasin | oui | rien côté données ; bords du § 3 n° 2 à 4 ; exercice (n° 13) pour le cumul et les barres d'exercice | J par mois ; barres, cumul, mêmes jours, écart et tendance recomposés en direct | aucun | cache par mois |
| Chiffre d'affaires HT par an (branche ; retiré par GRAPH-AN, remplacé par « Découper par : année ») | les mêmes, sur cinq ans | période (son dernier mois), mois de fin d'exercice, années vides du début cachées | oui | exercice changé : regroupement des mois | J par mois ; années ou exercices assemblés en direct | aucun | cache par mois |
| Chiffre d'affaires par magasin | les mêmes ; noms et états des magasins | magasins comptés, période, écran (jamais dans le panneau d'un magasin) | oui | nom, « (fermé) », « (cédé) », ordre par nom | J par mois et par magasin ; étiquettes et ordre en direct | aucun | cache par mois |
| Ventes par taux de TVA | lignes de factures et d'avoirs : taux_tva, montant_ht | période, magasins comptés | oui | rien | R (taux), clés à 0 gardées | aucun | cache par mois |
| Particuliers et professionnels | factures et avoirs : type_client (figé), total_ht | période, magasins comptés | oui | rien | R (type) | aucun | cache par mois |
| Meilleurs produits | lignes : produit_id, service_id (celui de la ligne vendue pour un avoir), description, quantite, montant_ht ; noms des magasins | période, mesure (HT ou quantité), magasins comptés | oui pour les sommes par clé ; le classement se refait | rien côté données ; le libellé « dernier lu » et les égalités dépendent de l'ordre de lecture | partie `produits` par mois ; classement, libellé selon une règle écrite, suffixe « · magasin » en direct | faible | cache par mois |
| Meilleurs clients | factures et avoirs : client_id, total_ht ; clients.nom (actuel), est_comptoir | période, magasins comptés, source Clients | oui pour les sommes ; noms et classement en direct | nom du client changé ou archivé (pas les ventes) | partie `clients` (sommes par id) ; noms relus et classement à chaque affichage | faible | cache par mois |
| Nombre de ventes et panier moyen | factures et tickets émis : date_emission, total_ttc, nombre | période, découpage, comparaison et écart, jour de l'écran | oui (nombres et TTC) ; le panier se calcule ensuite | rien | J (nombre, TTC) ; le panier = arrondi de la somme des TTC sur la somme des nombres, à chaque affichage, **jamais une moyenne gardée** | aucun | cache par mois |
| Ventes par jour de la semaine | factures et avoirs : jour du magasin, total_ht | période, jour de l'écran (dénominateur) | oui (numérateur) | rien | J : sommes par jour de semaine recomposées ; nombre de chaque jour de semaine en direct | aucun | cache par mois |
| Articles et services (société qui a des articles) | lignes : nature, montant_ht | période, magasins comptés | oui | rien | R (nature) | aucun | cache par mois |
| Avoirs émis par mois | avoirs émis : date_emission, total_ht | période, découpage, magasins où l'on voit tous les avoirs | oui | rien | partie `avoirs` | aucun | cache par mois |
| Ventes par vendeur (GRAPH-AN) | factures : cree_par, cree_par_nom, total_ht ; lignes d'avoirs : montant_ht, puis la vente d'origine (cree_par, cree_par_nom) ; total de l'avoir | période, magasins comptés | oui pour les sommes ; le classement se refait | rien (noms figés) ; le libellé « entrée la plus récente » dépend de l'ordre de lecture | partie `vendeurs` ; classement et suffixe en direct | faible | cache par mois |
| Chiffre clé « Chiffre d'affaires HT du mois » et sa variation | factures et avoirs : total_ht ; mois en cours de l'écran et même mois un an plus tôt (jusqu'au même jour dans GRAPH-AN) | jour de l'écran, magasins comptés | oui | rien | mois en cours recalculé ; l'an dernier sorti de J (jour par jour) | aucun | cache par mois |

Les parties « ventes » de « Ventes et achats HT par mois » et de la TVA (y compris la chaîne du report de TVA27) se lisent sur la même partie J (HT et TVA). Leurs parties achats, charges et report relèvent des autres analyses. La marge brute (ventes d'articles et coût rejoué du grand livre) relève de l'analyse du stock.

---

## 9. Les tests à écrire

1. **Équivalence.** Sur un semis vraisemblable de plusieurs magasins (dont un en Martinique et un à La Réunion), chaque carte, chaque écran, chaque période et chaque découpage :
   - calcul avec cache vide ;
   - puis cache plein ;
   - puis cache plein après un ajout ;
   - les trois doivent être égaux à un calcul sans cache. Faker semé.
2. **Bords du calendrier** : temps figé à des instants écrits avec leur fuseau.
   - Vente à 23:59:59 le 30 novembre en Martinique, affichée de Paris le 1er décembre à 4 h, puis à 5 h : novembre change.
   - Vente juste avant minuit, validée après minuit.
   - Facture à un professionnel après le ticket Z.
3. **Étiquettes en direct** : client renommé, magasin renommé ou fermé, ordre des magasins.
4. **Exercice changé par la procédure** : barres et cumul regroupés, sans aucun partiel recalculé.
5. **Droits** : un administrateur perd « voir celles de toute l'équipe ». Le magasin n'est plus compté, même si son partiel est en cache, et la phrase grise le dit.
6. **Isolation** : deux copies de démonstration aux mêmes identifiants de magasin ; `migrate:fresh` puis semis aux prix changés ; version du calcul changée. Aucun partiel d'une autre base ou d'un autre calcul n'est servi.
7. **Concurrence** (groupe de concurrence) : une vente validée entre la lecture de l'empreinte et celle des documents. Le partiel est rangé sous l'empreinte de ses propres documents.
8. **Plafonds de requêtes.** `PlafondsDeRequetesTest::test_la_grille_des_graphiques_lit_la_base_autant_de_fois_avec_un_magasin_ou_trois` devra rester constant en nombre de magasins et de mois. Pour cela, empreintes, `Cache::many` et recalculs sont faits en une requête chacun.

---

## 10. Remarques hors cache, relevées en lisant

Ce sont des défauts qui existent déjà, sans lien avec le cache.

- **Le libellé de « Meilleurs produits » et de « Ventes par vendeur »** est laissé à l'ordre de lecture (§ 6) :
  - un avoir sur une vente ancienne remet l'ancien nom d'un produit renommé ;
  - le nom figé ancien d'un vendeur revient par un avoir ;
  - l'ordre des lignes d'un même document n'est pas fixé.

  À trancher par une règle écrite (décision de Claude), cache ou pas.
- **Le filtre des ventes** est `statut <> 'brouillon'`, alors que la garde de la base définit « émis » par `numero IS NOT NULL`, et qu'aucune contrainte ne lie les deux (§ 3 n° 7-8).
- **« Ventes par jour de la semaine »** : pour un magasin de La Réunion vu de Paris en soirée, une vente du lendemain local peut entrer au numérateur alors que le dénominateur s'arrête à aujourd'hui (Paris). L'écart est de quelques heures par période.
- **Changements légaux de fin d'exercice.** Le tableau de bord ne connaît que le mois de fin d'exercice actuel, pas l'historique de ces changements (`ExercicesDeLaSociete`). Un exercice raccourci n'apparaît donc pas tel quel dans les barres d'exercice.
