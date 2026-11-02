# TVA déductible et déclaration CA3 : ce que fait PILIQI, ce que disent les textes, ce qu'il faut corriger

Rédigée le 02/11/2026 pour répondre à la question de l'éditeur (`a-verifier-apres.md`, points 2 et 3) : « être rassuré de ne pas avoir de problème avec l'État » sur la TVA collectée et la TVA payée. Lecture seule du dépôt `/home/user/piliqiediteur` au commit `f9b669c` ; aucun code PHP lancé. Les calculs sont refaits en Python, en centimes entiers (`scripts/tva_deductible.py`, sortie complète dans `scripts/tva_deductible.sortie.txt`).

## En bref

1. **TVA collectée : rien à reprocher au code.** Les clôtures du jour, leur net et l'aperçu de l'e-reporting additionnent, en centimes entiers, les TVA enregistrées sur les lignes et les totaux des tickets, factures et avoirs. Aucun ne recalcule une TVA sur un total (section 4.3). L'état de TVA de l'étape 5 n'existe pas encore : la section 4.4 donne les règles qu'il doit suivre.
2. **TVA déductible : PILIQI n'enregistre pas la TVA écrite par le fournisseur, il la recalcule.** La TVA d'une facture d'achat et celle d'un avoir fournisseur sont toujours recalculées depuis les lignes (quantité × PU HT, puis TVA du total HT de chaque taux). Aucun écran ni aucun appel ne permet de saisir la TVA de la facture. Quand le fournisseur arrondit autrement, l'écart passe **en silence** : aucun refus, aucun avertissement, et un total TTC différent de la somme à payer au fournisseur.
3. **Le centime est fréquent :** 25,6 % des factures de 3 lignes à 20 % d'un fournisseur qui arrondit la TVA ligne par ligne, et 53 % pour 10 lignes. Il est petit (au plus 1 centime pour 3 lignes). Mais **trois autres causes font des écarts en euros** : un PU HT à plus de 2 décimales, arrondi au centime sans prévenir (1 000 vis à 0,1234 € : 3,40 € de HT et 0,68 € de TVA perdus) ; la remise de pied de facture, qu'on ne peut pas saisir ; l'escompte pris par le magasin, qui ne baisse jamais la TVA enregistrée (4,00 € sur une facture de 1 000 € HT).
4. **Les textes sont nets.** La TVA déductible est « celle qui figure sur les factures » (CGI, art. 271, II-1-a), et la mention de la TVA sur une facture « ouvre droit […] à une déduction d'un montant égal » (BOFiP BOI-TVA-DECLA-30-20-20-30 § 340). Déduire un centime de plus, c'est déduire une taxe qu'aucune facture ne porte. Le BOFiP vise aussi l'entreprise qui « en modifie les indications » pour déduire (BOI-TVA-DED-40-10-10 § 50). Déduire un centime de moins n'est pas une faute, mais c'est une perte pour le commerçant, qui peut la rattraper pendant deux ans.
5. **Correction proposée :** garder, pour chaque taux, la base et la TVA de la facture du fournisseur. PILIQI les propose calculées depuis les lignes et contrôle l'écart. Jusqu'à 1 centime par ligne du taux, l'écart est accepté sans rien demander, car c'est l'arrondi du fournisseur. Au-delà, il faut le confirmer, et la confirmation s'inscrit au journal d'audit. Il n'y a pas de refus, sauf si les montants sont incohérents. S'y ajoutent un PU HT d'achat à 6 décimales, comme les ventes, et la même règle pour les avoirs fournisseur. **À décider maintenant et à corriger à l'étape 1, avant la campagne de tests 1 bis.** L'étape 2 (SUPER PDP) n'aura plus qu'à remplir les mêmes champs (section 3.5).
6. **CA3 :** la notice officielle 2026 (n° 3310 NOT-CA3-SD, Cerfa 50449#29) dit en toutes lettres que les montants se déclarent en euros arrondis : « La base imposable et le montant de l'impôt sont arrondis à l'euro le plus proche. Les bases et cotisations inférieures à 0,50 euro sont négligées et celles supérieures ou égales à 0,50 euro sont comptées pour 1. » (section 4.1).

---

## 1. TVA déductible : ce que fait PILIQI aujourd'hui

### 1.1 Par où une facture d'achat et un avoir fournisseur reçoivent leur TVA

| Chemin | Ce qui se passe | Code |
|---|---|---|
| **Saisie à l'écran** (Filament, « Factures d'achat ») | On saisit, par ligne, la quantité, le « Prix d'achat HT », la remise en % et le taux du produit. **Aucun champ ne reçoit la TVA, la base par taux ni le total de la facture du fournisseur.** Le formulaire ne montre que le total calculé, sans le détail par taux. La fiche enregistrée montre ensuite ce détail, toujours recalculé. | `app/Filament/Concerns/AjouteDesLignesDAchat.php` l. 274-296 (champs de prix) ; `app/Filament/App/Resources/FactureAchatResource.php` l. 88-124 (formulaire, sans champ de TVA), l. 152-162 (fiche : totaux et ventilation) ; `app/Filament/Concerns/SaisitLesPrixDeLigne.php` l. 28-56 (« une facture d'achat n'en montre que le total ») |
| **« API »** | Il n'existe **aucune route HTTP** pour les factures d'achat (`routes/web.php` n'en a pas, et il n'y a pas de `routes/api.php`). « API » n'est qu'une valeur de `SourceFactureAchat`, passée en attribut à la même action que l'écran. Elle rend la facture non modifiable une fois validée. La TVA est recalculée de la même façon. | `app/Domain/Achats/Enums/SourceFactureAchat.php` ; `app/Domain/Achats/Actions/CreerFactureAchatAction.php` l. 55-73 ; tests : `tests/Feature/Achats/FactureAchatActionsTest.php` l. 458, 467 |
| **Import** (SUPER PDP) | Pas encore construit (étape 2). | — |
| **Enregistrement** (commun aux deux chemins) | `ConstructeurLigneAchat::enregistrer()` supprime les lignes, prépare chacune, puis calcule tout le document par `chiffrer()`, qui appelle `CalculateurLigne::chiffrerUnAchat()`. Il enregistre ensuite les lignes avec leurs montants calculés, puis les totaux par `forceFill`. Les totaux ne sont pas en *mass assignment* : aucun attribut passé à l'action ne peut les fixer. | `app/Domain/Achats/Services/ConstructeurLigneAchat.php` l. 174-207 (dont l. 194 et 199), l. 367-378 (`chiffrer()`) ; `app/Domain/Achats/Models/FactureAchat.php` l. 56-67 (`$fillable` sans totaux) |
| **PU HT lu au centime** | `prixDeLaLigne()` lit le PU HT par `SaisieDecimale::enCentiemes()`. Une saisie plus précise que le centime s'arrondit une fois, **sans message** : « 0.1234 » devient 0,12 €. Les colonnes sont en `decimal(12,2)`, alors que les lignes de vente sont en `decimal(18,6)`. | `ConstructeurLigneAchat.php` l. 389-413 (l. 391) ; `app/Domain/Facturation/Services/SaisieDecimale.php` l. 13-20 et 67-68 ; `database/migrations/2026_10_07_100002_create_lignes_achat_table.php` (`prix_unitaire_ht` 12,2) ; `…_100004_create_lignes_avoir_fournisseur_table.php` (idem) ; ventes : `…_create_lignes_facture_table.php` l. 41 (18,6) |
| **Calcul** | HT de chaque ligne = quantité × PU HT, remise déduite, arrondie une fois. TVA de chaque taux = total HT du taux × taux, arrondie une fois, puis répartie par cumul sur les lignes. TTC = HT + TVA. Les totaux sont les sommes exactes des lignes. | `app/Domain/Facturation/Services/CalculateurLigne.php` l. 36-43 (règle), 175-196 (`chiffrerUnAchat()`), 780-789 (`montantNetCentimes()`), 804-807 (`tvaDuHt()`), 835-850 (`ventiler()`) |
| **Avoir fournisseur** | Créé à la main, depuis des lignes d'une facture d'achat validée. Pour tous les articles retournés jusqu'ici, il rend le HT et la TVA de ce HT, sans dépasser la TVA de la ligne, moins les retours déjà émis. Ses montants sont recalculés à l'émission. Le n° de l'avoir du fournisseur est demandé, mais **sa TVA ne l'est pas**. | `CalculateurLigne.php` l. 215-229 (`retourDAchat()`) ; `app/Domain/Achats/Models/LigneAchat.php` l. 125-148 (`montantsDuRetour()`) ; `app/Domain/Achats/Actions/CreerAvoirFournisseurAction.php` l. 113-132 ; `app/Domain/Achats/Actions/EmettreAvoirFournisseurAction.php` l. 131-153 |
| **Inventaire d'ouverture** | Sans TVA ni fournisseur : hors sujet pour la TVA déductible. | `app/Domain/Stock/Actions/CreerInventaireOuvertureAction.php` |
| **Escompte** | « Escompte obtenu » : il baisse le coût d'achat des produits (coût moyen), **pas les totaux de la facture, donc pas sa TVA**. | `database/migrations/2026_10_07_100001_create_factures_achat_table.php` (commentaire avant la l. 52) ; `LigneAchat.php` l. 93-100 ; `CalculateurLigne.php` l. 354-367 ; libellé à l'écran : `FactureAchatResource.php` l. 111-118 |

La règle est aussi écrite dans `CLAUDE.md` (puce « Prix et TVA des ventes ») : « Un achat se calcule depuis le PU HT du fournisseur : le HT de chaque ligne est quantité × PU HT, remise déduite, arrondi une fois ; la TVA de chaque taux est le total HT du taux × taux, arrondie une fois et répartie sur ses lignes par cumul ». Un test unitaire la fixe : `tests/Unit/CalculateurLigneTest.php` l. 458-471 (3 × 10,01 € HT font 6,01 € de TVA).

### 1.2 Quand la facture du fournisseur porte une autre TVA

- **Peut-on enregistrer la TVA du fournisseur telle quelle ?** Non, par aucun chemin. Elle n'a ni champ, ni attribut, ni colonne, et les totaux sont toujours écrasés par le calcul (`ConstructeurLigneAchat.php` l. 199).
- **Que se passe-t-il ?** Un écart silencieux. La facture s'enregistre et se valide avec la TVA de PILIQI. Rien ne la compare à celle du fournisseur, puisque PILIQI ne la connaît pas. Le total TTC enregistré diffère de la somme réellement due au fournisseur. Pour s'en apercevoir, il faut comparer soi-même la fiche au papier.
- **Contournement possible ?** Aucun d'honnête. Changer un PU pour retomber sur la TVA du fournisseur fausserait le HT et le coût du stock.

### 1.3 Exemples chiffrés (script `scripts/tva_deductible.py`)

| # | Facture du fournisseur | Fournisseur (HT / TVA / TTC) | PILIQI aujourd'hui (HT / TVA / TTC) | Écart de TVA (PILIQI − fournisseur) |
|---|---|---|---|---|
| 1 | 3 lignes de 1 × 10,01 € HT à 20 %, TVA arrondie ligne par ligne (3 × 2,00 €) | 30,03 / **6,00** / 36,03 | 30,03 / **6,01** / 36,04 (lignes 2,00 + 2,00 + 2,01) | **+0,01 € : PILIQI déduirait 1 centime qui ne figure sur aucune facture** ; le magasin doit 36,03 €, PILIQI affiche 36,04 € |
| 2 | 3 × 10,03 € HT à 20 %, TVA arrondie ligne par ligne (3 × 2,01 €) | 30,09 / 6,03 / 36,12 | 30,09 / 6,02 / 36,11 | −0,01 € (perte pour le commerçant) |
| 3 | 3 × 10,04 € HT à 20 %, TVA **tronquée** ligne par ligne (3 × 2,00 €) | 30,12 / 6,00 / 36,12 | 30,12 / 6,02 / 36,14 | +0,02 € |
| 4 | 1 000 vis à **0,1234 €** HT, 20 % | 123,40 / 24,68 / 148,08 | PU enregistré 0,12 € : 120,00 / 24,00 / 144,00 | **−0,68 €**, HT −3,40 €, et un stock valorisé à 0,12 € l'unité au lieu de 0,1234 € |
| 5 | 3 × 1,18 € HT, remise de 15 % appliquée au PU (PU net arrondi à 1,00 €) | 3,00 / 0,60 / 3,60 | 3,01 / 0,60 / 3,61 | 0 (HT +0,01 €) |
| 6 | Lignes 1,08 + 2,16 + 9,99 € HT, **remise de pied de 3 %** (0,40 €) | base 12,83 / 2,57 / 15,40 | 3 % saisis sur chaque ligne, faute de remise de pied : 12,84 / 2,57 / 15,41 | 0 (HT +0,01 €) |
| 7 | 1 000,00 € HT + 200,00 € de TVA, escompte conditionnel de 2 % pris, avec la mention « seule la taxe correspondant au prix effectivement payé ouvre droit à déduction » | TVA déductible 196,00 € | `total_tva` reste 200,00 € | **+4,00 €** si l'on déduit la TVA enregistrée |

**Fréquence de l'écart dû à l'arrondi seul** : factures tirées au hasard, graine fixe 20261102, PU de 0,01 € à 200,00 €, quantité de 1 à 20, sans remise.

| Lignes du taux | 20 % : fournisseur qui arrondit par ligne | écart max | 20 % : fournisseur qui tronque par ligne | écart max | 5,5 % : arrondit par ligne | écart max |
|---|---|---|---|---|---|---|
| 1 | 0 % | 0 ct | 32,2 % | 1 ct | 0 % | 0 ct |
| 2 | 15,5 % | 1 ct | 61,4 % | 2 ct | 24,9 % | 1 ct |
| 3 | 25,6 % | 1 ct | 80,0 % | 2 ct | 33,3 % | 1 ct |
| 5 | 38,0 % | 2 ct | 95,4 % | 4 ct | 44,9 % | 2 ct |
| 10 | 53,3 % | 3 ct | 99,9 % | 7 ct | 59,0 % | 4 ct |
| 20 | 66,0 % | 5 ct | 100 % | 11 ct | 70,6 % | 6 ct |

Les taux de 10 % et 2,1 % donnent des chiffres voisins (sortie complète du script). Ces écarts dus à l'arrondi sont nuls en moyenne : sur un mois, ils se compensent en grande partie. Les causes 4 et 7, elles, font des écarts dans un seul sens et en euros.

---

## 2. Ce que disent les textes sur le droit à déduction

**CGI, art. 271** (Légifrance, version en vigueur depuis le 21/02/2026, modifiée par la loi n° 2026-103 du 19/02/2026, art. 100) : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053545646

> I-1 « La taxe sur la valeur ajoutée qui a grevé les éléments du prix d'une opération imposable est déductible de la taxe sur la valeur ajoutée applicable à cette opération. »
>
> I-2 « Le droit à déduction prend naissance lorsque la taxe déductible devient exigible chez le redevable. »
>
> II-1 [la taxe dont les redevables peuvent opérer la déduction est] « a) Celle qui figure sur les factures établies conformément aux dispositions de l'article 289 et si la taxe pouvait légalement figurer sur lesdites factures ; »
>
> II-2 a [la déduction peut être opérée] « si les redevables sont en possession des factures »
>
> II-3 « Lorsque ces factures ou ces documents font l'objet d'une rectification, les redevables doivent apporter les rectifications correspondantes dans leurs déductions et les mentionner sur la déclaration qu'ils souscrivent au titre du mois au cours duquel ils ont eu connaissance de cette rectification. »

La page indique aussi que l'article est **abrogé au 1er janvier 2027** par l'ordonnance n° 2025-1247 du 17 décembre 2025 (recodification de la TVA dans le code des impositions sur les biens et services, CIBS), « à l'exception » des 3 du I, 2 et 3 du II et IV, maintenus jusqu'à leur reprise réglementaire. Le rescrit BOI-RES-TVA-000253 (publié le 07/10/2026) le confirme : « À compter du 1er janvier 2027, le code des impositions sur les biens et services comprendra également les dispositions législatives relatives à la TVA ». La numérotation CIBS des règles de déduction n'a pas été relevée ici. Le fond ne change pas, mais il faudra mettre à jour les références (y compris `RegimeTva::ARTICLE_FRANCHISE`, déjà prévu pour cela).

**BOFiP BOI-TVA-DED-40-10-10** (« Mentions sur les factures d'achat », version du 08/01/2025, la plus récente listée) : https://bofip.impots.gouv.fr/bofip/1111-PGP.html/identifiant=BOI-TVA-DED-40-10-10-20250108

> § 50 « Toute entreprise qui, dans le but d'opérer une déduction, mentionne elle-même la TVA sur les factures de ses fournisseurs ou en modifie les indications s'expose à un rappel sans préjudice des pénalités fiscales et éventuellement des poursuites correctionnelles. »
>
> § 55 « La taxe grevant les dépenses supportées par un assujetti ne peut être déduite qu'à la triple condition que : […] le montant de la TVA correspondante figure distinctement sur une facture établie conformément aux dispositions de l'article 289 du CGI […] ; la taxe ait pu légalement figurer sur ladite facture (CGI, art. 271, II-1). »
>
> § 60 « L'existence de la facture mentionnant la TVA ne crée pas en soi le droit à déduction. Ce droit n'existe que dans la mesure où la taxe figurant sur la facture était légalement due par l'émetteur de ce document. » La taxe facturée à tort « n'est pas pour autant déductible par le destinataire » ; pour la récupérer, « l'acquéreur doit prioritairement s'adresser […] à son fournisseur ».

**BOFiP BOI-TVA-DECLA-30-20-20-30** (version du 08/01/2025) : https://bofip.impots.gouv.fr/bofip/1531-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-30-20250108

> § 340 « La mention de la TVA sur une facture délivrée à un redevable de ladite taxe ouvre droit, chez ce dernier, à une déduction d'un montant égal. Il est donc essentiel que le montant de taxe facturé corresponde exactement à celui qui découle d'une application correcte de la taxe qui frappe légalement l'opération faisant l'objet de la facturation. »

**BOFiP BOI-TVA-DECLA-30-20-20-20** (version du 19/01/2022, toujours en vigueur), sur les avoirs et l'escompte : https://bofip.impots.gouv.fr/bofip/142-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-20-20220119

> § 260 « […] le client acheteur assujetti à la TVA est tenu, s'il a déjà opéré la déduction de la taxe figurant sur la facture initiale, d'atténuer le montant de cette déduction à concurrence de la taxe figurant sur la note d'avoir. »
>
> § 270 rabais « net de taxes » : sous réserve de la mention sur la note d'avoir, « il est admis […] de dispenser le client de procéder à la rectification de la déduction opérée au vu de cette facture. »
>
> § 280 escompte conditionnel : le vendeur est dispensé d'avoir « sous réserve qu'une mention apposée sur la facture, précise que, dans le cas où ceux-ci usent de la faculté qui leur est offerte de bénéficier d'un escompte, seule la taxe correspondant au prix effectivement payé par eux ouvre droit à déduction ».

**Notice de la CA3 2026, ligne 21** (voir la section 4.1 pour la référence) : on peut y déduire « la taxe dont la déduction a été omise sur les déclarations déposées depuis le 1er janvier de la deuxième année précédant celle du dépôt de la déclaration ».

**Spécifications externes de la facture électronique** (DGFiP, dossier général v3.2, archive de https://www.impots.gouv.fr/specifications-externes-b2b, déjà téléchargée dans `telechargements/dgfip/v32/`). La réforme vise à « faciliter leurs obligations déclaratives en matière de TVA grâce à un pré-remplissage des déclarations » et à « lutter contre la fraude fiscale et diminuer l'écart de TVA au moyen de recoupements automatisés ». Aucun calendrier officiel du pré-remplissage n'a été trouvé. Mais l'administration recevra les montants de TVA de chaque facture électronique que le commerçant reçoit.

**Ce que cela veut dire pour un centime :**
- **Un centime de plus que la facture.** PILIQI enregistre une TVA déductible qui ne figure sur aucune facture (art. 271, II-1-a ; BOFiP § 55 et § 340). Lors d'un contrôle, le vérificateur rapproche le compte de TVA déductible des factures (et, demain, des factures électroniques que la DGFiP aura reçues). L'excédent se rappelle avec l'intérêt de retard. Le montant est négligeable, mais le principe est celui que vise le § 50. Un logiciel ne doit pas enregistrer en TVA déductible autre chose que ce qu'écrit le fournisseur.
- **Un centime de moins.** Ce n'est pas une infraction. Le commerçant perd la différence, sauf s'il la rattrape (ligne 21, pendant deux ans).
- **Le compte fournisseur.** Le TTC que PILIQI enregistre n'est pas la somme due. Le futur rapprochement des paiements (étape 5) laisserait des restes d'un centime.
- **Sur la CA3**, chaque ligne est arrondie à l'euro sur le total du mois (section 4.1). Des centimes dus à l'arrondi, qui se compensent, ne changent donc presque jamais le montant déclaré. Les écarts en euros (exemples 4 et 7), eux, peuvent le changer.

---

## 3. Correction proposée

### 3.1 Principe

**La base et la TVA de chaque taux d'une facture d'achat sont celles que la facture du fournisseur écrit.** Il en va de même pour un avoir fournisseur. Le calcul depuis les lignes ne sert plus qu'à les **proposer** et à les **contrôler**. C'est ce que font les textes (art. 271, II-1-a ; § 260 pour l'avoir) et la facture électronique : la base et la TVA par taux (BT-116 et BT-117) y sont des données de la facture, que le destinataire reçoit telles quelles.

### 3.2 Saisie et contrôle

1. **Lignes.**
   - Le PU HT se saisit et s'enregistre avec 6 décimales, comme sur les lignes de vente (`decimal(18,6)`). Il ne s'arrondit plus jamais en silence.
   - Le HT de la ligne est proposé : quantité × PU HT, remise déduite, arrondi une fois.
   - Il peut être remplacé par le montant écrit sur la facture quand les deux s'écartent de **0,02 € au plus**. C'est la tolérance de la règle Peppol R120, que SUPER PDP appliquera aux factures UBL (voir `normes.md` § 3). Elle couvre le PU net arrondi par le fournisseur (exemple 5).
   - Au-delà, la saisie est refusée : la personne a mal lu le PU ou la remise.
2. **TVA de chaque taux.**
   - La base de chaque taux est la somme de ses lignes. C'est la règle exacte BR-S-08 de la norme EN 16931.
   - La TVA est proposée : base × taux, arrondie une fois (le calcul actuel). La personne la remplace par celle de la facture si elle diffère.
   - **Tolérance : 1 centime par ligne du taux.**
     - Un fournisseur qui arrondit la TVA de chaque ligne s'écarte de la TVA du total d'au plus ⌈n/2⌉ centimes environ pour n lignes (simulation : 1 ct pour 3 lignes, 3 ct pour 10, 5 ct pour 20).
     - S'il la tronque, l'écart peut atteindre n centimes (exemple 3 ; contrôle exhaustif du script : 3 ct pour 3 lignes).
     - « n centimes pour n lignes » couvre donc toutes ces façons d'arrondir, et rien de plus.
     - Une faute de frappe (6,10 € au lieu de 61,00 €) la dépasse toujours.
     - La tolérance de 1,00 € de BR-CO-17 n'est pas une bonne référence : c'est une marge de validation de la norme, pas un arrondi.
   - **Jusqu'à la tolérance**, la TVA du fournisseur est acceptée sans rien demander. La fiche montre les deux montants : « TVA du fournisseur 6,00 € (calcul depuis les lignes : 6,01 €) ».
   - **Au-delà**, PILIQI avertit : « La TVA saisie s'écarte de X € de la base × taux. Vérifiez la facture. Si sa TVA est fausse, demandez une facture rectificative au fournisseur : une TVA facturée à tort n'est pas déductible (BOFiP BOI-TVA-DED-40-10-10 § 60). » Pour valider, il faut cocher une confirmation, qui s'inscrit au journal d'audit (auteur, montants, écart). La facture reste marquée « TVA à vérifier » pour l'expert-comptable (étape 5).
   - **Pas de refus**, pour trois raisons :
     - la facture existe et se paie à son montant ;
     - la marchandise doit entrer en stock ;
     - un refus pousserait à maquiller les lignes, c'est-à-dire à « modifier les indications » de la facture (§ 50).
   - **Refus** seulement pour des montants qui ne peuvent figurer sur aucune facture valable :
     - une TVA négative pour une base positive ;
     - une TVA non nulle à 0 % ;
     - une TVA supérieure à la base ;
     - un TTC saisi pour contrôle qui ne vaut pas HT + TVA (règle exacte BR-CO-15).
3. **TVA des lignes.** La TVA saisie pour le taux se répartit sur ses lignes par cumul, dans leur ordre. Chaque ligne reçoit la TVA du taux × (cumul des HT jusqu'à elle ÷ base), arrondie, moins celle des lignes précédentes. La somme des lignes est donc exactement la TVA de la facture, et les retours gardent un plafond par ligne.
4. **Totaux.** Ils restent la somme exacte des taux : HT, TVA saisie, TTC = la somme due au fournisseur. Le champ « total TTC de la facture », saisi pour contrôle, attrape les erreurs de saisie.
5. **Avoir fournisseur.** Même schéma. La TVA de chaque taux est proposée par le calcul actuel (`retourDAchat()`) et se remplace par celle de la note d'avoir du fournisseur (§ 260), avec la même tolérance. Le plafond devient la TVA restante du taux sur la facture d'achat, et non plus celle de chaque ligne, pour qu'un avoir arrondi ligne par ligne passe. Une case « avoir net de taxes » met sa TVA à 0 sans toucher à la déduction de la facture (§ 270). Tant que l'avoir du fournisseur n'est pas reçu, sa TVA reste « proposée ». C'est ce document qui fixe la rectification (art. 271, II-3), et il se rapprochera par son numéro, déjà demandé.
6. **Escompte.** Deux cas à distinguer à l'écran :
   - **escompte déduit sur la facture elle-même** : c'est une remise de pied. La base et la TVA saisies sont alors celles de la facture, après escompte ;
   - **escompte conditionnel pris ensuite** (§ 280) : une case « la facture porte la mention : seule la TVA du prix payé est déductible ». La régularisation de la TVA (TVA de l'escompte) se fait au paiement, à l'étape 5. D'ici là, la fiche signale que la TVA déductible sera réduite si l'escompte est pris. Avec la mention « net de taxes » (§ 290), rien ne change.

### 3.3 Modèle de données

Conformément aux règles du projet, chaque changement se fait dans la migration de création de la table, puis `migrate:fresh`.

- `lignes_achat` et `lignes_avoir_fournisseur` : `prix_unitaire_ht` passe en `decimal(18,6)`. `montant_ht` devient le HT retenu pour la ligne (calculé, ou écrit sur la facture dans la tolérance de 0,02 €). `montant_tva` devient la part de la TVA du taux saisie, répartie par cumul.
- Nouvelle table par document, par exemple `taux_factures_achat` et `taux_avoirs_fournisseur`, avec `BelongsToMagasin`. Une ligne par taux : `taux_tva`, `base_ht`, `montant_tva` (celle de la facture), `montant_tva_calcule` (celle de PILIQI, gardée pour la preuve), `ecart_confirme_par` / `ecart_confirme_le`. Contrainte d'unicité (document, taux), limitée au magasin par le document. On peut aussi mettre une colonne JSON sur le document, mais la table se lit mieux par l'état de TVA.
- `CalculateurLigne` : `chiffrerUnAchat()` reçoit en plus la TVA imposée par taux (nulle : le calcul actuel), et `coutUnitaireNet()` accepte un PU en millionièmes. Aujourd'hui, il convertit le PU en centimes : `versUnites($prixUnitaireHt, 100)`, l. 361.
- `CLAUDE.md`, puce « Prix et TVA des ventes » : la phrase sur l'achat devient « un achat se calcule depuis le PU HT du fournisseur […] ; la base et la TVA de chaque taux sont celles de la facture du fournisseur, que PILIQI propose et contrôle […] ».

### 3.4 Ce que cela change ailleurs

- **Coût moyen (CMUP).** La TVA n'y entre pas pour une société qui la déduit : seul le coût HT compte. Le PU à 6 décimales rend le coût exact (exemple 4 : 0,1234 € au lieu de 0,12 €). Si le HT d'une ligne vient de la facture, le coût unitaire est ce HT ÷ quantité, escompte déduit, au dix-millième comme aujourd'hui. Une remise de pied (étape 2) se répartit sur les coûts des lignes au prorata du HT.
- **Retours au fournisseur.** Ils sortent toujours au coût de la ligne retournée (`StockLedgerService`). Leur TVA est celle de l'avoir du fournisseur (3.2, point 5). Le dernier retour d'une ligne rend toujours exactement ses montants restants.
- **Journal des achats (étape 5).** Les écritures reprennent les montants de la facture, comme une écriture doit le faire avec sa pièce :
  - au débit, le compte d'achat de marchandises pour le HT de chaque taux, et la TVA déductible (44566) pour la TVA saisie ;
  - au crédit, le fournisseur (401) pour le TTC écrit ;
  - l'avoir passe en sens inverse, avec ses montants écrits.
  - La ligne 20 de la CA3 (« Autres biens et services ») est la somme des TVA saisies des achats dont la TVA est exigible dans la période (marchandises : à la livraison), arrondie une fois à l'euro.
  - Les factures « TVA à vérifier » sont listées pour l'expert-comptable.
- **Factures reçues par SUPER PDP (étape 2).** Elles portent leurs montants :
  - HT de ligne BT-131, prix net BT-146 et quantité de base BT-149 ;
  - remises et frais de pied BG-20 / BG-21 ;
  - base et TVA par taux BT-116 / BT-117, total de TVA BT-110, TTC BT-112.

  L'import les recopie dans les mêmes champs, sans rien recalculer. Les contrôles de PILIQI ne font qu'avertir. Une facture électronique peut porter une TVA qui s'écarte jusqu'à 1,00 € de base × taux (tolérance de BR-CO-17) : PILIQI l'accepte, puisque c'est elle qui est déductible si elle est due, mais il avertit au-delà de n centimes. L'administration reçoit les mêmes données : la TVA déductible de PILIQI sera donc exactement celle que la DGFiP voit.

### 3.5 Peut-on attendre l'étape 2 ?

**Non pour la décision et pour la saisie à la main. Oui pour l'import et les remises de pied.** Raisons :

1. **C'est une règle de l'éditeur à changer.** `CLAUDE.md` impose aujourd'hui le recalcul. Le protocole de rigueur demande de soumettre une règle de calcul ou une obligation légale à l'utilisateur plutôt que de choisir. Le sujet appartient d'ailleurs à l'étape 1 : « achats toujours au prix HT », « politique d'arrondi unique des ventes et des achats » (série 8), qui touchent les mêmes fichiers et les mêmes tests (`CalculateurLigne`, `ConstructeurLigneAchat`, `CalculateurLigneTest`). Les réécrire une fois plutôt que deux.
2. **La saisie à la main restera longtemps le chemin principal.**
   - L'émission de factures électroniques n'est obligatoire pour les PME et micro-entreprises qu'au 1er septembre 2027, d'après le calendrier de la réforme. Ce point vient de sources secondaires, non relues sur Légifrance.
   - D'ici là, les fournisseurs des commerces envoient encore souvent des PDF ou du papier.
   - Les fournisseurs étrangers ne passeront jamais par la plateforme.
3. **Le PU au centime est un défaut, pas un choix d'arrondi.** Il fausse le HT, la TVA et la valeur du stock de plusieurs euros (exemple 4), sans rapport avec SUPER PDP.
4. **La campagne de tests 1 bis** va éprouver les achats. Autant qu'elle éprouve la règle définitive.
5. **Aucune donnée réelle n'existe**, donc aucun risque fiscal aujourd'hui. La date limite stricte est la mise en production. La date raisonnable est la fin de l'étape 1.

**Ce qui peut attendre l'étape 2 :**
- les remises et frais de pied de facture (BG-20/21), communs à la saisie et à l'import. D'ici là, la saisie à la main met la remise en % sur chaque ligne et corrige la TVA du taux, si bien que la TVA déductible est déjà exacte ;
- la quantité de base (BT-149) ;
- le rapprochement des avoirs reçus par leur numéro ;
- les unités et quantités décimales.

### 3.6 Questions fermées pour l'éditeur

1. La TVA de chaque taux d'une facture d'achat et d'un avoir fournisseur est-elle celle écrite par le fournisseur, PILIQI la proposant et la contrôlant ? **Oui** (recommandé) / non.
2. Au-delà de 1 centime par ligne du taux : **avertissement avec confirmation inscrite au journal d'audit** (recommandé) / refus.
3. PU HT d'achat : **6 décimales comme les ventes** (recommandé) / 4 décimales.
4. HT d'une ligne remplaçable par celui de la facture **à 0,02 € près** (recommandé) / jamais.
5. Calendrier : **étape 1, avant la campagne 1 bis** (recommandé) / étape 2.
6. Escompte : **distinguer l'escompte déduit sur la facture et l'escompte conditionnel pris ensuite, régularisé à l'étape 5** (recommandé) / laisser tel quel.
7. Avoir fournisseur « net de taxes » : **une case** (recommandé) / non.
8. Société en franchise en base : **coût d'achat TTC**, puisque la TVA n'y est pas récupérable (voir 5.1) / garder le HT. À faire confirmer par l'expert-comptable.

---

## 4. Déclaration CA3

### 4.1 Les montants se déclarent en euros, sans centimes

**Notice officielle 2026** : « N° 3310 NOT-CA3-SD, N° 50449#29, Notice pour remplir la déclaration n° 3310-CA3 et ses annexes », PDF créé le 06/02/2026, téléchargé le 02/11/2026 : https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5426.pdf (sha256 `dc276d6e…85c5`).

> Cadre I, « LES ARRONDIS FISCAUX » : « La base imposable et le montant de l'impôt sont arrondis à l'euro le plus proche. Les bases et cotisations inférieures à 0,50 euro sont négligées et celles supérieures ou égales à 0,50 euro sont comptées pour 1. »
>
> « CADRE B : DÉCOMPTE DE LA TVA À PAYER — Les bases d'imposition et la TVA correspondante sont arrondies à l'euro le plus proche. Les fractions d'euro inférieures à 0,50 sont négligées, celles supérieures ou égales à 0,50 sont comptées pour 1. »
>
> « Lignes 19 et 20 : Déterminer les droits à déduction de la période en indiquant la taxe déductible correspondant aux biens et services ouvrant droit à déduction. La TVA déduite au titre de chacune des catégories est arrondie à l'unité la plus proche. Les fractions d'euro inférieures à 0,50 sont négligées, celles supérieures ou égales à 0,50 sont comptées pour 1. »
>
> « Ne jamais indiquer de sommes négatives. Pour la TVA récupérable (opérations résiliées ou impayées) ou acquittée à tort (factures rectificatives ou notes d'avoirs) se reporter à la page 9, ligne 21. »

Le formulaire lui-même (n° 3310-CA3-SD, Cerfa 10963*31, PDF du 04/12/2025 : https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5377.pdf, sha256 `1d216219…cb5`) renvoie à la notice.

**Base légale** (Légifrance, en vigueur au 02/11/2026, sans mention d'abrogation) :
- CGI, art. 1649 undecies : « Les bases des impositions de toute nature sont arrondies à l'euro le plus proche. La fraction d'euro égale à 0,50 est comptée pour 1. » https://www.legifrance.gouv.fr/codes/section_lc/LEGITEXT000006069577/LEGISCTA000006147278/
- CGI, art. 1724 : « La liquidation de toutes sommes à recevoir, à quelque titre et pour quelque cause que ce soit, est arrondie à l'euro le plus proche. La fraction d'euro égale à 0,50 est comptée pour 1. Il est procédé à cet arrondissement au niveau du décompte de chaque impôt ou taxe. » https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006312863

**Comment arrondir :** une seule fois, à l'euro le plus proche, 0,50 € compté pour 1. On arrondit chaque montant porté sur une ligne de la déclaration (base et TVA de chaque taux aux lignes 08, 09, 9B… ; ligne 19 ; ligne 20 ; ligne 21), sur la somme exacte en centimes des documents de la période. Les totaux du formulaire (16, 23, TD, 28…) se calculent ensuite sur ces lignes arrondies. On n'arrondit jamais document par document.

### 4.2 Comment se détermine la TVA collectée à déclarer

- **TVA facturée : elle est due du seul fait de sa mention.** CGI, art. 283-3 (Légifrance, en vigueur depuis le 14/03/2026, lui aussi abrogé au 01/01/2027 au profit du CIBS) : « Toute personne qui mentionne la taxe sur la valeur ajoutée sur une facture est redevable de la taxe du seul fait de sa facturation. » https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051214570. La TVA d'une facture se déclare donc telle qu'elle est imprimée, pas recalculée sur la base de la période.
- **Exigibilité : elle donne la période de déclaration.** CGI, art. 269-2 (en vigueur depuis le 01/01/2023, abrogé au 01/01/2027) : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044983827
  - « a) Pour les livraisons […] lors de la réalisation du fait générateur. Toutefois, en cas de versement préalable d'un acompte, la taxe devient exigible au moment de son encaissement, à concurrence du montant encaissé. »
  - « c) Pour les prestations de services […] lors de l'encaissement des acomptes, du prix, de la rémunération ou, sur option du redevable, d'après les débits. »
  - Les biens vendus se déclarent donc le mois de la vente. Les services se déclarent le mois de leur encaissement, sauf option pour les débits, que PILIQI connaît déjà (réglage de la société).
- **Ventes aux particuliers sans facture (tickets).** L'art. 283-3 ne joue pas, puisqu'il n'y a pas de facture. La taxe due est celle de l'opération : base hors taxe × taux.
  - Aucun texte français ne dit à quel niveau arrondir une TVA comprise dans un prix TTC. La CJUE a laissé ce choix aux États (CJUE, 5 mars 2009, *J D Wetherspoon*, C-302/07, cité dans `normes.md` § 1.4).
  - PILIQI calcule la TVA une fois par ticket et par taux, puis additionne. C'est un calcul admis, et c'est celui que montre le ticket.
  - Surtout, c'est **le même montant partout** : sur le ticket Z (clôture du jour), dans l'e-reporting envoyé chaque jour à l'administration, et demain dans la CA3 et le FEC. La DGFiP recevra l'e-reporting (art. 290 du CGI) et pourra le comparer à la CA3. **La règle à tenir est donc : CA3 = somme des e-reportings de la période = somme des clôtures**, sans recalculer « recettes TTC × taux ÷ (1 + taux) ». Ce dernier calcul est courant chez les détaillants, mais il s'écarterait de l'e-reporting d'un centime par ticket et par taux au plus.

### 4.3 Vérification dans le code : clôtures et e-reporting

| Ce qui est vérifié | Où | Constat |
|---|---|---|
| Récapitulatif d'une clôture du jour (tickets et avoirs aux particuliers, factures et avoirs aux professionnels, par taux) | `app/Domain/Facturation/Services/RecapitulatifDeCloture.php` l. 21-25 (règle « depuis les montants enregistrés, jamais recalculés »), l. 96-105, l. 153-168 (`rubrique()`), l. 178-183 (`montants()`) | Somme, en centimes entiers (`enCentiemes()`), des colonnes `total_ht` / `total_tva` / `total_ttc` des documents et `montant_*` des lignes. Le détail par taux additionne les lignes. **Aucune TVA recalculée.** |
| Net de la clôture (base de l'e-reporting), journal des événements | `app/Domain/Facturation/Actions/CloturerCaisseAction.php` l. 158-166, l. 194-205 | Net = ventes aux particuliers + leurs avoirs (en négatif), rubrique par rubrique (HT, TVA, TTC séparément). **Aucune TVA recalculée.** |
| Aperçu de l'e-reporting (transactions par catégorie, taux et motif) | `app/Domain/Facturation/Services/EReportingDesParticuliers.php` l. 227-247 (`transactions()`), l. 257-275 (`transaction()`), l. 113-117 (`egaleLeNetDe()`) | Somme des `montant_ht` / `montant_tva` enregistrés de chaque ligne de ticket, moins celles des avoirs. Le contrôle de la fiche compare l'aperçu au net de la clôture. **Aucune TVA recalculée.** Le schéma de la DGFiP attend précisément le HT et la TVA par taux (TT-82, TT-83, TT-87, TT-88, `transaction.xsd` v3.2). Sa règle G1.53 (annexe 7 v1.9) ne contrôle que les sommes (total = somme des taux, à 1 centime près), pas « TVA = base × taux ». |
| Encaissements des prestations de services (e-reporting des paiements) | `EReportingDesParticuliers.php` l. 286-355 | Montants **TTC** encaissés par date et par taux, sans TVA, comme le demande le schéma (TT-95 « Montant encaissé », `payment.xsd`). Aucune TVA calculée ici. Voir 4.4, point 3. |
| Contenu signé de la caisse | `app/Domain/CaisseSecurisee/Services/ContenuFiscal.php` l. 320-352 | Lignes signées avec leurs HT, TVA et TTC enregistrés. La ventilation additionne les lignes, et les totaux sont ceux du document. |
| Ventilation par taux des fiches, chiffre d'affaires, relevé de caisse | `app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php` l. 74-91 ; `app/Domain/Facturation/Services/ChiffreDAffaires.php` l. 53-87 ; `app/Domain/Facturation/Actions/FermerSaCaisseAction.php` l. 198 ; `CalculateurLigne.php` l. 483-524 (`totaliser()`, `cumuler()`) | Sommes en centimes des montants enregistrés. |
| Sommes faites par la base de données | recherche de `sum(` / `SUM(` dans `app/` | Aucune somme SQL sur une colonne de TVA. La seule somme SQL d'un montant (`app/Domain/Contacts/Models/Client.php` l. 323) porte sur des centimes entiers convertis par une expression, pour les crédits d'avoir. |
| Seul calcul d'une TVA sur un total | `CalculateurLigne::valeurAuCoutMoyen()` l. 331-343, utilisé par `app/Domain/Tenancy/Actions/FermerMagasinAction.php` l. 291-333, `AnnulerCessionAction.php` l. 101 et le widget `ValeurStockApercu` | TVA calculée sur la **valeur d'un stock** (cession, valeur affichée). Il s'agit d'une information remise au propriétaire en HT, jamais déclarée. Ce n'est pas un défaut, mais ce montant ne doit jamais entrer dans l'état de TVA. |

**Conclusion :** pour la TVA collectée, le code fait déjà ce qu'il faut. Chaque montant déclaré ou déclarable est la somme exacte des TVA imprimées.

### 4.4 Règles à imposer au futur état de TVA (étape 5)

1. **Lignes 08 à 5B (TVA brute).** Somme, en centimes, des TVA enregistrées des documents dont la TVA est exigible dans la période, par taux et par territoire, puis un seul arrondi à l'euro par ligne. Les territoires ont leurs propres lignes :
   - métropole : lignes 08, 09 et 9B ;
   - Guadeloupe, Martinique, La Réunion : lignes 10 et 11 ;
   - Corse : lignes TC et T3 à T5.

   Jamais « base arrondie × taux ». Si le formulaire en ligne ou le fichier EDI propose la TVA calculée sur la base, il faut y porter la somme des TVA facturées. Le comportement de l'outil en ligne et les contrôles du cahier des charges EDI-TVA n'ont pas été trouvés : **à vérifier** avant l'étape 5.
2. **Ligne 20 (TVA déductible).** Somme des TVA saisies des factures d'achat, moins celles des avoirs fournisseur (section 3), un seul arrondi à l'euro.
3. **Services exigibles à l'encaissement.**
   - La TVA de chaque encaissement se tire de la TVA enregistrée du document : TVA du document × part encaissée, par cumul.
   - Le dernier encaissement qui solde le document prend le reste, comme `retourDeLigne()` le fait pour les retours.
   - La TVA déclarée sur la vie du document vaut ainsi exactement celle qu'il imprime (art. 283-3).
   - Ne jamais calculer la TVA sur la somme des encaissements du mois (TTC × taux ÷ (1 + taux)).
4. **Avoirs de vente d'une période déjà déclarée.** La notice interdit les montants négatifs. La TVA d'une réduction de prix consentie après la facture va à la ligne 21, rubrique « dont régularisation sur de la TVA collectée ». Le traitement des avoirs de la même période (nets des ventes ou ligne 21) est à faire valider par l'expert-comptable.
5. **Cohérence à contrôler et à afficher :** somme des e-reportings de la période = somme des clôtures = TVA brute des tickets déclarée = compte de TVA collectée du FEC, au centime près avant l'arrondi.

---

## 5. Points annexes relevés en chemin

1. **Société en franchise en base.** Elle ne récupère pas la TVA de ses achats, mais son coût d'achat (CMUP) et ses marges sont calculés en HT (`MargeDuCatalogue::de()`, `app/Domain/Catalogue/Services/MargeDuCatalogue.php` l. 39-47 ; `LigneAchat::coutUnitaireHt()`). Le PCG (art. 213-31, règlement ANC n° 2014-03, non relu dans cette note) exclut du coût d'acquisition des stocks les seules taxes « ultérieurement récupérables ». Dans ce cas, le coût devrait donc comprendre la TVA, et le futur journal des achats (étape 5) ne devra pas la porter en TVA déductible. Question 8 de la section 3.6.
2. **Ristournes et remises de fin d'année sans retour de marchandise.** Un avoir fournisseur ne porte aujourd'hui que des lignes retournées (`CreerAvoirFournisseurAction`). Une ristourne reçue (§ 260 : avoir qui fait référence « à un ensemble de factures ») ne peut pas être saisie, alors qu'elle oblige à réduire la déduction. À prévoir avec l'étape 2 (avoirs reçus) ou l'étape 5.
3. **Références légales qui changent au 1er janvier 2027.** Les art. 269, 271 et 283 du CGI sont abrogés au profit du CIBS. Les mentions, les messages et `RegimeTva::ARTICLE_FRANCHISE` devront suivre la nouvelle numérotation.

---

## 6. Sources, fichiers et empreintes

Téléchargés le 02/11/2026 dans `telechargements/tva-ded-ca3/` (texte extrait par `scripts/texte_html.py`, bibliothèque standard, `python3 -I`) :

| Fichier | Source | sha256 |
|---|---|---|
| `3310-ca3-sd_5426.pdf` (+ `.txt`) | notice CA3 2026, https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5426.pdf | `dc276d6ef1579d12b6bb1271103b40184f9dfff2122edb2bc3473e2da98385c5` |
| `3310-ca3-sd_5377.pdf` (+ `.txt`) | formulaire CA3 2026, https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5377.pdf | `1d216219ce476a9a57f7f75a1da87ae42dc1ff65bd086ba0956051f4ca9accb5` |
| `BOI-TVA-DED-40-10-10-20250108.html` (+ `.txt`) | https://bofip.impots.gouv.fr/bofip/1111-PGP.html/identifiant=BOI-TVA-DED-40-10-10-20250108 | `6a273f579dd7430bcb09d67626841c9f545dee5fb28985477660175bbd50afb4` |
| `BOI-TVA-DECLA-30-20-20-30-20250108.html` (+ `.txt`) | https://bofip.impots.gouv.fr/bofip/1531-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-30-20250108 | `0753524b740380b7f40c151e3674f80439222fd02606ff89e9d49862b474bdf1` |
| `BOI-TVA-DECLA-30-20-20-20.html` (+ `.txt`) | https://bofip.impots.gouv.fr/bofip/142-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-20-20220119 | `fccf519286db892bd26347ce860d7bfa2cdd5cf1cfcafaf4454d27368d3f2177` |

Lus en ligne sur Légifrance le 02/11/2026 (citations recopiées des pages « en vigueur ») :
- art. 271 CGI : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053545646
- art. 283 CGI : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051214570
- art. 269 CGI : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044983827
- art. 1724 CGI : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006312863
- art. 1649 undecies CGI : https://www.legifrance.gouv.fr/codes/section_lc/LEGITEXT000006069577/LEGISCTA000006147278/
- rescrit BOI-RES-TVA-000253 du 07/10/2026 : https://bofip.impots.gouv.fr/bofip/14901-PGP.html/identifiant=BOI-RES-TVA-000253-20261007

Déjà présents dans le dossier (téléchargés par l'analyse précédente) : spécifications externes de la DGFiP v3.2 (`telechargements/dgfip/v32/`, dossier général et annexe 7, règles de gestion v1.9 : règle G1.53 ; XSD de l'e-reporting `transaction.xsd` et `payment.xsd`).

Scripts : `scripts/tva_deductible.py` (sha256 `604eeeef879c30d138f7ea80ca08d4a5c572fb64b466c68afa30f37574310a25`), sortie `scripts/tva_deductible.sortie.txt` ; `scripts/texte_html.py` (sha256 `1a2ab34e58c298266f865d42bb8fc21a2f45b85b12b30b6dad328bf6c9e59847`).

**Ce qui reste incertain :**
- la numérotation des articles du CIBS à partir du 01/01/2027 ;
- le calendrier du pré-remplissage de la CA3 (aucune source officielle trouvée) ;
- les contrôles de cohérence « base × taux » du formulaire en ligne et de l'EDI-TVA ;
- le calendrier d'émission obligatoire pour les PME, lu dans des sources secondaires ;
- le texte exact de l'art. 213-31 du PCG.

---

## Vérification (contre-vérification adversariale du 02/11/2026)

Faite sans rien modifier dans `/home/user/piliqiediteur`, ni test, ni artisan, ni pint (dépôt relu au commit `f9b669c`). Les modifications non commitées présentes dans le dépôt touchent les panneaux Filament et les pages d'erreur, pas les achats. Fichiers de cette vérification, dans `verif-tva-ded/` :
- `relance.sortie.txt` : nouvelle exécution de `scripts/tva_deductible.py` (sha256 inchangé, `604eeeef…0a25`). Elle est **identique octet pour octet** à `scripts/tva_deductible.sortie.txt`.
- `contre_verif.py` et sa sortie `contre_verif.sortie.txt` : pire cas théorique, répartition, avoirs.

### V.1 Verdict par point

| Point du rapport | Verdict | Commentaire |
|---|---|---|
| 1. TVA collectée : rien ne recalcule une TVA sur un total | **Confirmé** | Relu : `RecapitulatifDeCloture::rubrique()` et `montants()` (l. 153-183), `EReportingDesParticuliers::transactions()` et `transaction()` (l. 227-275). Ils font des sommes en centimes de `montant_*` et `total_*`. Les autres calculs de TVA (`prixUnitaireHtDepuisTtc`, `prixUnitaireTtcDepuisHt`, `valeurAuCoutMoyen`) ne servent qu'à l'affichage d'un prix unitaire, d'une marge ou de la valeur d'un stock, jamais à une déclaration. |
| 2. TVA déductible recalculée, aucun champ, même calcul à l'écran et par « API » | **Confirmé** | Il n'existe aucune route : `routes/` ne contient que `web.php`, qui ne parle pas d'achats. `SourceFactureAchat::Api` n'est passé que par les tests. Aucun autre code n'écrit `total_tva` d'une facture d'achat ou d'un avoir fournisseur (recherche dans `app/`, `routes/`, `database/seeders`). Le champ « Prix d'achat HT » est `numeric()`, donc `step="any"` dans Filament : le navigateur accepte 0.1234, et `SaisieDecimale` l'arrondit à 0,12 € **sans message**. Confirmé. |
| Chemins oubliés : modification d'une facture validée | **Confirmé, avec un manque** | `ModifierFactureAchatAction` supprime puis recrée les lignes et refait le même calcul (`ConstructeurLigneAchat::enregistrer()`). Une facture qui porte un avoir, même en brouillon, ne se modifie plus (`FactureAchat::estModifiable()`). **Manque :** ni la validation ni la correction d'une facture d'achat validée ne s'inscrivent au journal d'audit (`ActionAuditee` n'a que `AvoirFournisseurEmis` et `InventaireOuvertureValide`). Une correction peut donc changer, sans trace, la TVA d'une facture déjà déclarée. Voir V.3. |
| Chemins oubliés : avoirs fournisseur | **Confirmé, avec une précision** | `EnregistrerAvoirFournisseurAction` est le seul chemin de l'écran : création puis émission, qui recalcule (`arreterLesMontants()`). `SourceAvoirFournisseur::Api` n'est jamais utilisé. **Précision que le rapport n'a pas vue :** l'avoir ne suit pas la règle de la facture d'achat. Chaque ligne y reçoit la TVA de son propre HT retourné (`retourDAchat()`), et `totaliser()` les additionne. Il n'y a aucune TVA calculée sur le total du taux. Exemple : sur une facture de 3 lignes de 2 × 10,01 € HT, un avoir qui retourne 1 article de chaque ligne fait 6,00 € de TVA dans PILIQI. Un fournisseur qui calcule comme la facture d'achat de PILIQI (total du taux × taux) écrit 6,01 €. L'écart existe donc **même avec un fournisseur qui arrondit comme PILIQI** (`contre_verif.sortie.txt`, point 3). |
| Chemins oubliés : inventaire d'ouverture | **Confirmé, hors TVA** | Il n'a pas de TVA. Mais `cout_unitaire_ht` y est aussi en `decimal(12,2)` et lu par `SaisieDecimale` : un coût de 0,1234 € y devient 0,12 € sans message, comme le PU d'achat. Cela fausse la valeur du stock, pas la TVA. La correction « 6 décimales » doit couvrir aussi cette colonne. La remise en % est elle aussi arrondie au centième sans message (12,345 % devient 12,35 %). C'est marginal. |
| 3. Exemple 3 × 10,01 € et fréquences | **Confirmé** | 6,00 € chez le fournisseur et 6,01 € dans PILIQI (lignes 2,00 + 2,00 + 2,01), 36,04 € TTC contre 36,03 €. Le test unitaire `CalculateurLigneTest` l. 458-471 fixe bien 601 centimes. Les fréquences (25,6 % pour 3 lignes, 53,3 % pour 10 lignes à 20 %) sont reproduites. **Correction :** les « écarts max » du tableau sont ceux qu'ont donnés les tirages, pas les pires cas. 10 lignes de 0,02 € HT à 20 % font 4 ct d'écart (le tirage en donnait 3), et 20 lignes en font 8 (le tirage en donnait 5). À 2,1 %, on atteint 5 ct pour 10 lignes et 10 ct pour 20. La tolérance proposée de n centimes pour n lignes couvre ces pires cas, arrondi comme troncature. La démonstration : l'écart reste strictement inférieur à n + ½, donc il vaut au plus n. Pour 3 lignes arrondies, « au plus 1 centime » est exact dans le pire cas. |
| 4. Trois causes en euros (PU arrondi, remise de pied, escompte) | **Confirmé** | Exemples 4 à 7 recalculés : −3,40 € HT et −0,68 € de TVA ; +0,01 € HT ; +4,00 € de TVA. Pour l'escompte, le commentaire de la migration et le libellé à l'écran le disent bien : « baisse le coût d'achat des produits, pas le total de la facture ». Il faut ajouter qu'un escompte régularisé par une note d'avoir du fournisseur ne peut pas non plus se saisir aujourd'hui : un avoir fournisseur ne porte que des articles retournés (cf. 5.2). |
| 5. Textes | **Citations exactes, une portée exagérée** | Tout a été relu sur les fichiers téléchargés, dont les sha256 sont identiques à ceux de la section 6 : BOI-TVA-DED-40-10-10 § 50, 55 et 60 ; BOI-TVA-DECLA-30-20-20-30 § 340 ; BOI-TVA-DECLA-30-20-20-20 § 260, 270, 280 et 290 ; notice CA3, lignes 15, 19-20 et 21. Sur Légifrance, j'ai relu l'art. 271 (en vigueur depuis le 21/02/2026, abrogé par l'ord. 2025-1247), l'art. 283 (en vigueur depuis le 14/03/2026, abrogé au 01/01/2027 sauf le 2 septies) et l'art. 1724 (sans abrogation). **Portée exagérée :** le § 50 vise l'entreprise qui falsifie la facture de son fournisseur (« mentionne elle-même la TVA […] ou en modifie les indications »). Un logiciel qui enregistre en comptabilité 1 centime de plus n'est pas dans ce cas : il s'agit d'une déduction excédentaire, rappelée au titre de l'art. 271, II-1-a (§ 55). Le même raisonnement vaut pour l'argument « un refus pousserait à modifier les indications » (3.2) : changer une ligne dans PILIQI ne modifie pas la facture du fournisseur. Il faut citer le § 55, pas le § 50. Il faut aussi rappeler le § 55, alinéa 5 : une simple inexactitude n'entraîne pas forcément la remise en cause de la déduction. |
| 6. Correction proposée | **Juste sur le fond, à corriger sur 4 points** | Voir V.2. |
| 7. Calendrier | **Conclusion confirmée, motif à corriger** | Corriger à l'étape 1 est justifié : la feuille de route y place déjà « achats toujours au prix HT » et la « politique d'arrondi unique des ventes et des achats ». En revanche, « la saisie à la main restera le chemin principal jusqu'au 1er septembre 2027 » est inexact. **Depuis le 1er septembre 2026**, toutes les entreprises doivent pouvoir recevoir des factures électroniques, et les grandes entreprises et ETI doivent en émettre. C'est confirmé par plusieurs sources secondaires, mais pas relu sur impots.gouv.fr. Les grossistes envoient donc déjà aux magasins des factures électroniques qui portent la base et la TVA de chaque taux (BT-116 et BT-117), et la DGFiP reçoit ces mêmes montants. Tant que l'étape 2 n'existe pas, le commerçant les ressaisit dans PILIQI. C'est une raison de plus pour pouvoir y recopier la TVA écrite dès maintenant. Aujourd'hui, PILIQI ne déclare aucune TVA déductible et ne produit ni CA3 ni FEC. « PILIQI déduirait 1 centime » (1.3, exemple 1) veut dire : le futur journal des achats (étape 5) porterait 1 centime de trop. |
| 8. CA3 | **Citations confirmées, une erreur** | « La base imposable et le montant de l'impôt sont arrondis à l'euro le plus proche […] » se lit mot pour mot dans la notice n° 3310 NOT-CA3-SD (50449#29), créée le 06/02/2026. Le formulaire est le Cerfa 10963*31. **Erreur, section 4.4, point 2 :** la notice place la TVA des avoirs reçus des fournisseurs à la **ligne 15** (« reversement de la TVA antérieurement déduite […] réception de factures d'avoirs des fournisseurs »). Elle ne les déduit pas de la ligne 20. Elle interdit aussi tout montant négatif. La ligne 20 est donc la somme des TVA des factures d'achat, et la ligne 15 celle des avoirs fournisseur, chacune arrondie une fois. Retrancher de la ligne 20 les avoirs de la même période est une pratique courante, mais elle est à faire valider par l'expert-comptable. Deux précisions mineures : en Corse, les opérations à 20 % et 5,5 % vont aux lignes 08 et 09, et seuls les taux propres à la Corse vont en TC et T3 à T5 ; la notice demande, pour les lignes 08 à 11, « la base hors TVA et l'impôt correspondant » sans dire comment calculer l'impôt, donc la règle « somme des TVA facturées » reste défendable, mais à vérifier sur le formulaire en ligne. |

### V.2 Ce que la correction proposée casse ou risque

1. **Plus d'une règle de `CLAUDE.md` change, pas une seule phrase.**
   - Puce « Prix et TVA des ventes », phrase sur l'achat : le HT de la ligne (point 1 de 3.2), la TVA du taux et sa répartition changent.
   - La phrase du retour au fournisseur change aussi : « sans dépasser celle de la ligne » et « le retour de toute la ligne rend exactement ses montants » (point 5 de 3.2).
   - Surtout, la règle d'affichage s'applique aux achats : « Il s'affiche, comme tout prix unitaire montré en lecture (… ventes comme achats), arrondi au centime ». Un PU d'achat à 6 décimales s'afficherait donc 0,12 € sur la fiche pour 0,1234 € saisi, et 1 000 × 0,12 € ne donnerait plus 123,40 €. C'est exactement l'écart visible que l'éditeur refuse sur les ventes.
   - Il faut donc décider aussi que la fiche d'une facture d'achat et d'un avoir fournisseur montre le PU HT tel que le fournisseur l'écrit.
2. **Les tests existants changent, et seulement après la décision.**
   - La répartition au prorata (3.2, point 3) ne donne pas les mêmes lignes que la règle actuelle, même quand la TVA saisie vaut la TVA calculée : 3 × 10,01 € donne 2,00 + 2,01 + 2,00, contre 2,00 + 2,00 + 2,01 aujourd'hui (`contre_verif.sortie.txt`, point 1).
   - `CalculateurLigneTest` (l. 458-471) et les tests des retours fournisseur devront donc être réécrits.
   - Le protocole l'interdit sans une décision explicite de l'éditeur sur la règle. La décision doit nommer la répartition retenue.
3. **Contradiction interne sur l'avoir fournisseur (3.2, point 5, et 3.4).**
   - Le rapport veut à la fois « la TVA de l'avoir est celle de la note d'avoir du fournisseur » et « le dernier retour d'une ligne rend toujours exactement ses montants restants ». Les deux ne tiennent pas ensemble.
   - Exemple : une ligne de 3 × 10,01 € a 6,01 € de TVA, et un premier avoir de 2 articles en rend 4,00 €. Pour le dernier article, le fournisseur écrit 2,00 €, alors que le reste de la ligne vaut 2,01 €.
   - Le BOFiP tranche (§ 260) : le client atténue sa déduction « à concurrence de la taxe figurant sur la note d'avoir ». C'est donc la note d'avoir qui prime, et le centime restant sur la facture reste déduit.
   - Le contrôle doit porter sur ce que l'avoir ne doit pas dépasser : la TVA restante du taux sur la facture, avec la tolérance. Ce n'est pas une égalité exacte.
4. **Refuser un HT de ligne au-delà de 0,02 € (3.2, point 1) peut bloquer des factures authentiques.**
   - Cas concernés : prix pour 100 unités (BT-149), PU imprimé avec moins de décimales que celles du calcul du fournisseur, HT tiré d'un prix TTC par un grossiste qui vend au détail.
   - R120 est une règle de validation pour qui émet la facture. PILIQI, qui la reçoit, ne doit pas refuser ce que le fournisseur a écrit, puisque c'est ce HT qui forme la base déductible.
   - Il faut appliquer au HT de ligne le même schéma qu'à la TVA : accepté sans question jusqu'à 0,02 €, confirmation inscrite au journal d'audit au-delà.
   - De même, « la base = la somme des lignes » n'est exacte qu'en CII et Factur-X. En UBL, BR-S-08 tolère ± 1,00 € (`telechargements/en16931/ubl_schematron_UBL_EN16931-UBL-model.sch`). L'import de l'étape 2 doit donc pouvoir garder la base BT-116 telle qu'écrite.
5. **La marque « TVA à vérifier » doit être un champ du document, pas seulement une entrée du journal d'audit.** Le journal d'audit n'est consultable que par le propriétaire, et un cabinet lié n'y a jamais accès (`CLAUDE.md`, « Cabinets d'expertise comptable »). Or c'est l'expert-comptable qui doit voir la marque.
6. **Le choix entre 6 et 4 décimales n'est pas neutre.**
   - Le coût entre en stock au dix-millième : `cmup_ht` et `mouvements_stock.cout_unitaire_ht` sont en `decimal(14,4)`. Avec 6 décimales, le coût s'arrondit encore à 4 décimales, au plus 0,50 € sur 10 000 articles, sans effet sur la TVA.
   - 4 décimales irait avec ce CMUP et avec la limite annoncée par la révision EN 16931-1:2026 (non vérifiée, `synthese.md` § 2).
   - 6 décimales irait avec les ventes et avec la limite FNFE.
   - Les deux options sont défendables. Il faut seulement dire à l'éditeur ce que chacune laisse arrondir.
7. **Aucun conflit avec l'isolation ni avec les autres règles**, sous trois conditions :
   - la nouvelle table par taux utilise `BelongsToMagasin` ;
   - ses sommes se font en PHP, en centimes, jamais par un `SUM` SQL sur une colonne décimale ;
   - les nouveaux champs se saisissent avec les lignes, à la création ou à la modification. Le jeton de la validation (R3) ne porte que l'identifiant de la facture (`ValiderFactureAchatAction`), il n'y a donc rien à y changer.

   Une société de services n'a pas de factures d'achat : la garde de la base, posée sur `factures_achat`, couvre la nouvelle table par sa clé étrangère.

### V.3 Ce qui manquait

1. **Une facture d'achat validée corrigée après sa déclaration.**
   - Aujourd'hui, la correction réécrit les lignes et les totaux sans laisser de trace d'audit.
   - L'art. 271, II-3 veut qu'une rectification se reporte sur la déclaration du mois où elle est connue.
   - Avec la correction proposée, toute modification de la base ou de la TVA d'une facture validée doit s'inscrire au journal d'audit, avec les anciennes et les nouvelles valeurs (c'est le périmètre de l'étape 4, à lier ici).
   - L'état de TVA de l'étape 5 devra déclarer l'écart au mois de la correction, sans réécrire la période passée.
2. **L'autoliquidation (acquisitions intracommunautaires, importations).**
   - Une marchandise achetée à un fournisseur de l'Union européenne arrive à 0 %. Le magasin doit pourtant déclarer la TVA due et la déduire : lignes B2, 17 et 20 de la CA3, ou I1 à I6 pour une importation.
   - PILIQI n'a pas de « nature d'achat » : un achat à 0 % d'un fournisseur allemand ne se distingue pas d'un achat français à 0 %.
   - Ce n'est pas bloquant à l'étape 1. Mais l'étape 5 ne pourra pas rassurer l'éditeur sur la « TVA payée » sans cette donnée, à saisir sur la facture d'achat.
3. **Le moment de la déduction.** Une marchandise se déduit au mois où la TVA devient exigible, c'est-à-dire à la livraison, et à condition d'avoir la facture. `date_facture` est aujourd'hui facultative (`nullable`). L'état de TVA de l'étape 5 aura besoin d'une date fiable : date de la facture ou date de réception.

### V.4 Questions fermées à poser à l'éditeur (réduites à l'essentiel)

1. Pour chaque taux d'une facture d'achat et d'un avoir fournisseur, PILIQI enregistre-t-il la base et la TVA **écrites par le fournisseur** ? PILIQI propose son calcul, accepte sans question jusqu'à 1 centime par ligne du taux et, au-delà, demande une confirmation inscrite au journal d'audit et marque le document « TVA à vérifier », marque visible de l'expert-comptable. Pour un avoir, la note d'avoir du fournisseur prime sur le reste exact de la ligne. **Oui** (recommandé) / non, garder le recalcul.
2. Le PU HT d'achat et le coût unitaire de l'inventaire d'ouverture s'enregistrent-ils avec plus de 2 décimales et **s'affichent-ils tels que le fournisseur les écrit**, par exception à la règle d'affichage au centime ? **6 décimales** / 4 décimales.
3. Correction faite **à l'étape 1, avant la campagne 1 bis** (recommandé) / à l'étape 2.

Les autres questions de la section 3.6 (escompte, avoir « net de taxes », franchise en base) peuvent attendre l'étape 5. La question de la franchise en base relève de l'expert-comptable.
