# Cache du tableau de bord : les cartes de la TVA et des charges

Analyse en lecture seule, le 23/11/2026, de :

- la branche de travail, `/home/user/piliqiediteur` (tête `36b2388c`) : `app/Domain/Charges`, `app/Domain/TableauDeBord`, `app/Filament/GrilleDesGraphiques.php`, `app/Filament/PageDesCharges.php`, les migrations des charges, le planificateur (`bootstrap/app.php`) ;
- le lot GRAPH-AN (commit `0257979e`), copie `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/wt-demo` : la TVA par jour, mois, trimestre ou année (`SeriesDesGraphiques::tvaParPeriode()` l. 192-213, `tvaDeLIntervalle()` l. 241-271) ; le travail non validé de cette copie ne touche pas la TVA ;
- le lot TVA27 (commit `fbdf759a`), copie `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/wt-tva`, **avec sa relecture non validée** (fichiers modifiés le 22/11 entre 23 h 16 et 23 h 28 UTC, après le commit) : le report du crédit (`ReportDuCreditDeTva`, `CreditsDeTva`, `TvaDeLaPeriode::avecLeReport()`, `Perimetre::reporteLeCreditDeTva()`). La relecture ne change pas la mécanique décrite ici. Les numéros de ligne de TVA27 sont ceux de la copie de travail.

Rien n'a été modifié. L'accès à la base semée m'a été refusé : cette analyse ne donne aucune mesure de temps. Les mesures des empreintes des ventes sont dans `analyse-ventes.md`, à côté.

Les analyses sœurs couvrent les sources dont la TVA dépend aussi : `analyse-ventes.md` (TVA collectée) et `analyse-achats.md` (TVA déductible des achats). Ce document reprend d'elles seulement ce qu'il faut pour la TVA.

---

## 1. Réponse courte

### 1.1 « Une année finie ne change plus » est faux pour la TVA

Le code le montre de six façons.

1. **Une charge se dépose pour n'importe quel mois de TVA, sans limite dans le temps.**
   - `SaisieDeCharge::__construct()` (l. 54-60) vérifie seulement que la date est un jour valable.
   - `PropositionsDeTva::moisDeTva()` (l. 77-82) prend le mois de la pièce, ou, pour des services, celui du paiement.
   - Le comptable peut fixer un autre mois (`EcritureDUneCharge::remplir()` l. 84-89).
   - Une charge de décembre déposée en février entre donc dans décembre, et une charge de 2024 entre dans 2024.
2. **Une charge qui n'est pas validée change de chiffres, de magasin et de mois** (`SaisirUneChargeAction`). Elle quitte l'estimation quand elle est renvoyée (`RenvoyerUneChargeAction`) ou supprimée (`SupprimerUneChargeAction`). Elle passe de l'estimation à la TVA réelle quand elle est validée (`ValiderUneChargeAction`).
3. **Une charge validée se corrige sans limite de temps**, avec un motif (`CorrigerUneChargeAction`). La correction peut changer ses montants, son magasin, son mois de TVA, son autoliquidation et son type, donc sa part déductible. La base ne garantit qu'une chose : chaque correction est comptée (`GardesDesCharges` l. 104-110).
4. **Une facture d'achat validée se date librement et se corrige** : son `total_tva` et sa `date_facture` changent (voir `analyse-achats.md`).
5. **Le crédit de départ** (TVA27) se déclare pour n'importe quelle période terminée depuis l'arrivée de la société, puis se modifie ou se corrige à tout moment. Il change toute la chaîne du report.
6. **Le régime de TVA se change à tout moment** (`ModifierParametresFiscauxSocieteAction`). Mensuel ou trimestriel, il regroupe autrement toute l'histoire et fait choisir une autre ancre.

Seules les ventes ne changent pas le passé : un document émis est figé. Restent les bords de mois des magasins d'outre-mer (`analyse-ventes.md`).

### 1.2 La position de Claude est juste, avec six précisions

- **(a) La version d'un mois ne couvre que le contenu de ce mois**, pour une source et un magasin. Ce n'est jamais une version cumulée.
- **(b) Le report du crédit ne va jamais en cache.** Il se recalcule à chaque affichage, en mémoire, depuis les sommes de chaque mois. `ReportDuCreditDeTva::calculer()` est un calcul pur : une boucle sur au plus 12 périodes par année depuis l'arrivée. Quand un mois ancien change, seul ce mois se relit en base, et la chaîne recalculée porte d'elle-même le changement à toutes les périodes qui suivent (§ 3).
- **(c) Une version cumulée** (§ 3.3) ne servirait qu'à garder en cache le résultat d'une période de la chaîne. Elle demande de lire les versions de tous les mois depuis le début de la chaîne, soit autant de travail que de recalculer la chaîne. C'est donc à ne pas faire.
- **(d) Les charges n'ont pas besoin de cache.** Il suffit d'une requête indexée par (société, mois de TVA), qui rend six colonnes, dont les montants en centimes entiers. Leur empreinte coûterait autant que leur lecture.
- **(e) Se relisent à chaque affichage :** le crédit déclaré, le régime, la date d'arrivée, la liste des magasins de la société, les droits et le jour de l'écran.
- **(f) Ce qui va en cache :** les sommes de TVA de chaque jour, par (magasin, source, mois local), pour les ventes et les achats. Ce sont les mêmes entrées que celles des cartes des ventes et des achats, où l'on ajoute la TVA au HT et au TTC. Chacune est protégée par sa version.

### 1.3 Le gain attendu

- Avec TVA27, chaque affichage et chaque geste lit toutes les ventes et tous les achats depuis l'arrivée de la société. `CalculDesSeries::premierJourDeLaTva()` (l. 162) allonge la lecture partagée `documentsDeVente()` (l. 708) jusqu'au début de la chaîne, et cela pour toutes les cartes de ventes.
- `GrilleDesGraphiques::getViewData()` (l. 281-296) recalcule tout à chaque geste.
- Avec le cache, ne se relisent que le mois en cours de chaque magasin et les mois dont la version a changé.

### 1.4 Trois constats hors cache dans TVA27, à trancher (§ 7)

1. La chaîne part toujours de l'arrivée de la société, quel que soit l'historique de son régime. Après un passage de la franchise au réel, les mois de franchise apportent un crédit de TVA qui n'existe pas. Après un passage du mensuel au trimestriel, le crédit de la dernière déclaration mensuelle est oublié. Risque **élevé**.
2. Une seule déclaration sert d'ancre : la plus récente. En déclarer une plus récente change les chiffres des périodes situées entre l'ancienne ancre et la nouvelle. Risque **moyen**.
3. Le report se calcule avec les seules charges que le compte voit. Un administrateur à qui « Charges : ajouter » n'est cochée que dans une partie des magasins voit un autre crédit à reporter que le propriétaire, pour la même société et la même période. Risque **moyen**.

---

## 2. Tableau des cartes et des points de mécanique

| Carte ou point | Se découpe par mois | Ce qui change son passé | Mise en cache | Version | Risque restant | Verdict |
|---|---|---|---|---|---|---|
| TVA à payer par mois, sans le report (réelle et estimée ; mois, trimestre ou année) | oui | charges (tous les gestes) ; achats datés du passé ou corrigés ; jamais une vente | sommes de TVA par jour dans l'entrée (magasin, source, mois) ; charges lues en direct ; assemblage à chaque affichage | empreinte de chaque mois et de chaque source, grille complète | faible | cache par mois |
| TVA avec le crédit reporté (TVA27 : graphique, « Voir les chiffres », pastille) | oui avec une dépendance en chaîne | tout ce qui précède, plus la déclaration du crédit (déclarée, modifiée, corrigée), le régime, un nouveau magasin, les droits | sommes des mois depuis l'arrivée tirées du cache ; chaîne, ancre et état recalculés à chaque affichage | celle des mois ; aucune pour la chaîne | faible | cache par mois |
| Chiffre clé « TVA à payer » | oui avec une dépendance en chaîne | avec le report : tout mois ancien et l'ancre | mois en cours de chaque magasin toujours relu ; mois passés tirés du cache ; chaîne recalculée | celle des mois passés | faible | cache par mois |
| TVA de la page des charges et de l'état téléchargeable | oui avec une dépendance en chaîne | comme la carte avec le report | même chemin que le tableau de bord ; liste et tableau de la page lus en direct | celle des mois | faible | cache par mois |
| Source ventes : TVA collectée | oui | rien (documents figés) | entrée (magasin, mois local), par jour | nombre, somme et plus grand identifiant, avec le filtre de la lecture | aucun | cache par mois |
| Source achats : TVA déductible des achats | oui | validation datée du passé, correction (`total_tva`, date) | entrée (magasin, mois), par jour | empreinte du contenu, mois large | faible | cache par mois |
| Source charges : validées et à valider | oui | dépôt, écriture des chiffres, renvoi, validation, correction, mois de TVA changé, magasin changé, suppression, import de l'étape 2 | aucune : lecture directe, une requête indexée | si l'on y tient : empreinte par (magasin ou « Toute la société », mois de TVA) | aucun | jamais en cache |
| Autoliquidation (fournisseur établi hors de France) | oui | case cochée ou décochée, type, montants, mois | avec les charges, lue en direct | celle des charges | aucun | jamais en cache |
| Report du crédit (la chaîne, réelle et estimée) | oui avec une dépendance en chaîne | chaque mois depuis l'arrivée, l'ancre, la cadence, ce que le compte voit | jamais : recalcul pur en mémoire | sans objet ; cumulée si l'on gardait une période (§ 3.3) | aucun | jamais en cache |
| Crédit de départ déclaré (l'ancre) | non | déclarer, modifier, corriger ; confirmer (note seulement) ; régime changé | relu à chaque affichage (une ligne) | sans objet | aucun | jamais en cache |
| Régime mensuel ou trimestriel | oui | changement de régime : toute l'histoire est regroupée autrement, l'ancre change | régime relu ; trimestre additionné en mémoire | sans objet | aucun | jamais en cache |
| Franchise en base et régime à choisir | non | changement de régime : la carte disparaît ou apparaît | régime relu ; les sommes des mois n'en dépendent pas | sans objet | aucun | jamais en cache |
| Droits et charges de « Toute la société » | non | un droit change l'assemblage de tous les mois | jamais : droits relus, entrées par magasin, entrée à part pour « Toute la société » | sans objet | faible | jamais en cache |
| Remboursements de crédit (page TVA à venir) | oui avec une dépendance en chaîne | une demande saisie tard, accordée, refusée, corrigée | entrée de la chaîne, lue en direct | sans objet | moyen | à trancher par l'éditeur |
| Mois en cours, mois à venir, calendrier des magasins | oui | ventes d'outre-mer après la fin du mois de Paris ; achats et charges datés dans l'avenir | mois en cours de chaque magasin toujours relu ; version sur chaque mois de la fenêtre | celle de chaque mois | faible | jamais en cache |
| Empreinte d'un mois : suppression, mise à jour qui garde la date, instantané | oui | une charge supprimée (mois devenu vide) ; deux corrections dans la même seconde ; une écriture entre la version et les données | empreinte du contenu, grille complète, un seul instantané | décrite au § 6 | faible | cache par mois |
| Écritures hors des gestes : tâches, imports, requêtes directes, copies, restauration, déploiement | oui | travaux en file de l'étape 2, API, semis, « Recommencer à zéro », `migrate:fresh` | versions relues dans les données, jamais une invalidation par les actions ; clé avec la base et le code | empreinte du contenu | faible | cache par mois |
| Magasin fermé, cédé ou nouveau ; société suspendue ; compte retiré | oui | les charges d'un magasin fermé et d'une société suspendue s'écrivent encore ; un nouveau magasin change ce que compte le report | ne jamais tenir pour figé un magasin fermé ni une société suspendue | celle des mois ; droits relus | faible | cache par mois |
| Hors cache, TVA27 : début de la chaîne et changement de régime | oui avec une dépendance en chaîne | passage de la franchise au réel, du mensuel au trimestriel | sans objet | sans objet | élevé | à trancher par l'éditeur |
| Hors cache, TVA27 : une seule ancre | oui avec une dépendance en chaîne | déclarer une période plus récente | sans objet | sans objet | moyen | à trancher par l'éditeur |
| Hors cache, TVA27 : report avec les seules charges que le compte voit | oui avec une dépendance en chaîne | « Charges : ajouter » cochée dans une partie des magasins | sans objet | sans objet | moyen | à trancher par l'éditeur |

Ces risques supposent la conception décrite ici. Avec la règle « période finie = figée », le risque serait **élevé** pour toutes les cartes de TVA : une charge validée en retard laisserait faux son mois et, par le report, toutes les périodes qui le suivent.

---

## 3. Le report du crédit : comment un mois ancien change tous les mois suivants

### 3.1 La mécanique, dans le code

`ReportDuCreditDeTva::calculer()` (TVA27, l. 43-93) procède ainsi :

1. Il range les mois par période : un mois au mensuel, un trimestre civil au trimestriel (`CadenceDeLaTva::periodeDu()`).
2. Il saute les périodes antérieures à la première période, celle de l'arrivée de la société (`CreditsDeTva::premierePeriode()` l. 96-99, d'après `societes.created_at`).
3. Pour chaque période, il additionne la TVA de ses mois (`MontantsDeTva::additionner()`).
4. Il en retire le crédit reporté de la période d'avant (`solder()` l. 100-105). Si le résultat est positif, c'est la TVA à payer et le crédit à reporter vaut 0. S'il est négatif, il n'y a rien à payer et le crédit à reporter vaut l'opposé.
5. À la période de l'ancre, la déclaration la plus récente, le crédit à reporter devient celui qui a été déclaré, et la TVA à payer est inconnue (`null`).
6. La réelle (charges validées) et l'estimation (avec les charges à valider) ont chacune leur chaîne.

On a donc `crédit(P) = max(0, crédit(P−1) − TVA(P))` et `à payer(P) = max(0, TVA(P) − crédit(P−1))`. Le crédit d'une période dépend de **toutes** les périodes depuis le début de la chaîne, ou depuis l'ancre.

### 3.2 Un exemple chiffré

Régime mensuel, aucune déclaration. TVA du mois, réelle : janvier −300 €, février +100 €, mars +500 €, avril +200 €.

| | janvier | février | mars | avril |
|---|---|---|---|---|
| Crédit reporté | 0 | 300 | 200 | 0 |
| TVA à payer | 0 | 0 | 300 | 200 |
| Crédit à reporter | 300 | 200 | 0 | 0 |

En novembre, le comptable valide une charge de janvier, avec 50 € de TVA déductible. Janvier passe à −350 € :

| | janvier | février | mars | avril |
|---|---|---|---|---|
| Crédit reporté | 0 | 350 | 250 | 0 |
| TVA à payer | 0 | 0 | **250** | 200 |
| Crédit à reporter | **350** | **250** | 0 | 0 |

Le changement d'**un seul** mois ancien a changé février et mars. Il s'arrête en avril, parce que mars a tout absorbé : son crédit à reporter reste 0 dans les deux cas. Si chaque période avait été en crédit, il se serait propagé jusqu'au mois en cours, et donc jusqu'au chiffre clé.

Quatre variantes :

- **Une ancre en février** (crédit déclaré : 180 €). Le changement de janvier ne touche que janvier. Février, la période déclarée, garde son crédit déclaré : la copie de travail de TVA27 n'y montre plus de crédit reporté. Mars part de 180 € dans les deux cas.
- **Au trimestriel**, le changement de janvier change le 1er trimestre, puis les trimestres suivants.
- **Valider** une charge à valider change la chaîne réelle, mais pas la chaîne estimée, qui la comptait déjà.
- **Renvoyer** ou supprimer une charge à valider fait l'inverse : la chaîne estimée change, la réelle non.

### 3.3 La version cumulée : ce qu'elle devrait contenir, et pourquoi s'en passer

**Pour garder en cache le résultat R(P) d'une période P**, sa version W(P) devrait contenir tout ce qui suit :

- **la version de chaque mois**, depuis le premier mois de la chaîne jusqu'au dernier mois de P. Si P suit l'ancre, depuis le mois qui suit l'ancre. Pour chaque mois, ce sont les versions de toutes les entrées comptées : ventes et achats de chaque magasin compté, charges de chaque magasin et de « Toute la société » ;
- **l'ancre** : sa période et son solde. Une nouvelle déclaration change aussi les périodes situées avant elle (§ 7.2) ;
- **la cadence**, qui regroupe les mois ;
- **la première période**, donc la date d'arrivée ;
- **ce que le compte compte** : magasins des ventes et des achats, magasins des charges, et « Toute la société ». La chaîne d'un administrateur peut différer de celle du propriétaire (§ 7.3) ;
- **la présence des charges** (`avecLesCharges`) ;
- **si P est en cours, les mois déjà commencés**. Le trimestre en cours compte les mois commencés et se montre au mois en cours (`TvaReportee::fermeSaPeriode()`) ;
- **la version du code.**

On peut l'écrire comme une chaîne d'empreintes : W(P) = H(W(P−1), versions des mois de P, ancre si P est sa période, cadence, ensembles comptés, mois commencés, code).

**Pourquoi s'en passer.** Calculer W(P) demande de lire les versions de tous les mois depuis le début de la chaîne. C'est la même lecture que celle qui recharge les sommes des mois depuis le cache, et le calcul de la chaîne lui-même ne coûte presque rien. Garder un résultat de période en cache n'apporte donc aucun gain, et ajoute une clé à huit composantes dont l'oubli d'une seule serait fatal.

### 3.4 La règle retenue

1. La version d'un mois couvre **seulement** les données de ce mois, pour une source et un magasin. Elle n'est jamais cumulée.
2. Le cache garde les sommes des mois, jamais un report, un solde, un crédit ni une série assemblée.
3. À chaque affichage, on lit les versions de tous les mois depuis le début de la chaîne, on recharge ou recalcule chaque mois, puis on recalcule la chaîne.
4. On relit à chaque affichage l'ancre (`CreditsDeTva::ancre()`), le régime, la date d'arrivée, et plus tard les remboursements.

La propagation est alors exacte par construction : elle est faite par le calcul, pas par le cache.

**Une économie possible quand la chaîne dure des années.** On peut ajouter une version par (magasin, source, année). Si elle n'a pas changé, les douze versions mensuelles de cette année sont inchangées, et l'on ne les calcule pas.

- **Ventes :** documents en ajout seul, donc le nombre et le plus grand identifiant suffisent.
- **Achats :** il faut une empreinte du contenu, qui contient la date. Une facture qui change d'année change la version des deux années.

---

## 4. Les cartes en détail

### 4.1 « TVA à payer par mois », sans le report

**Calcul.**
- Branche actuelle : `SeriesDesGraphiques::tvaParMois()` (l. 152-171), `tvaDesMois()` (l. 183-193), `TvaDeLaPeriode::parMois()` (l. 62-94), `MontantsDeTva` (`reelle()` l. 41, `estimee()` l. 50).
- GRAPH-AN : `tvaParPeriode()` et `tvaDeLIntervalle()`. Les ventes et les achats se rangent par jour (`SommesParJour`). Les charges se comptent par mois de TVA, jamais par jour : la barre d'un jour ne les compte pas (l. 260).

**Sources.**
- `factures` (statut ≠ brouillon) et `avoirs` (émis) : `id`, `magasin_id`, `date_emission`, `total_tva` (un avoir se déduit).
- `factures_achat` (validées) : `id`, `magasin_id`, `date_facture`, ou à défaut `date_validation`, et `total_tva`.
- `avoirs_fournisseur` (émis) : `date_emission`, `total_tva`, à déduire.
- `charges` : `magasin_id`, `mois_tva`, `etat` (validée ou à valider), `fournisseur_hors_france`, `total_tva`, `total_tva_deductible` (`ChargesDuTableauDeBord::lire()` l. 58-88).
- `magasins.territoire`, qui donne le jour local, et `societes.regime_tva` et `type_activite`.

**Dépendances.**
- **Magasins comptés :** ceux où le compte voit les ventes, et les achats dans une société qui a des articles (`Perimetre::magasinsDe()`).
- **Charges comptées :** celles des magasins dont il voit les charges. Celles de « Toute la société » comptent seulement si l'écran montre tous ses magasins et qu'ils sont tous comptés (`Perimetre::chargesComptees()` l. 63-73, `compteLesChargesDeLaSociete()` l. 84-88).
- **Période :** mois de la période et, si la case est cochée, ceux d'un an plus tôt.
- **Jour de l'écran :** un mois à venir n'a pas de valeur ; dans GRAPH-AN, la barre en cours se compare aux mêmes jours.
- **Régime :** en franchise, la carte n'existe pas (`Perimetre::voit()` l. 158-175).
- **Type de société :** il dit si les achats comptent.
- **GRAPH-AN :** découpage, cumul, écart, tendance. Le découpage par année lit 10 ans (`DecoupageDesPeriodes::ANNEES`).

**Ce qui change le passé.**
- Charges : tous les gestes du § 5.3.
- Achats : § 5.2.
- Jamais une vente.

**Mise en cache.**
- L'entrée (magasin, source, mois local) des ventes et des achats garde, pour chaque jour, la TVA signée en centimes, à côté du HT et du TTC des analyses sœurs. Ce sont les mêmes entrées et la même version.
- Les charges se lisent en direct (§ 5.3).
- À chaque affichage :
  1. contrôler les droits ;
  2. choisir les entrées des magasins comptés ;
  3. additionner, puis construire les barres, le cumul, la comparaison et la tendance en mémoire.
- Le mois en cours de chaque magasin se relit toujours.
- Aucune série assemblée ne va en cache.

**Version.** § 6.

**Risque restant : faible.** Deux erreurs restent possibles :
- une version qui n'utiliserait pas exactement le filtre de la lecture ;
- une version lue après les données.

On les évite par une constante partagée et un seul instantané (§ 5.12).

**Verdict : cache par mois.**

### 4.2 « TVA à payer » avec le crédit reporté (TVA27)

**Calcul.**
- `SeriesDesGraphiques::tvaReportee()` (l. 271) et `tvaAvecLeReport()` (l. 211).
- `TvaDeLaPeriode::reporter()` (l. 122-137), puis `ReportDuCreditDeTva::calculer()`.
- L'état de l'ancre vient de `CreditsDeTva::etat()` (l. 172-179). La note et la pastille viennent de `TextesDuCreditDeTva::noteDeLAncre()` (l. 86-97) et `pastilleDeLAncre()` (l. 105).

**Sources.**
- Les mêmes sommes mensuelles, pour **tous les mois depuis l'arrivée** de la société.
- `credits_de_tva` : `cadence`, `periode`, `solde`, `verifie_le`.
- `societes` : `created_at`, `regime_tva`.
- La liste de **tous** les magasins de la société, fermés et cédés compris (`PerimetreDesGraphiques` l. 271-273).
- Le lien du cabinet ou la déclaration d'expert-comptable, qui donnent l'état de l'ancre : « à vérifier » ou « non vérifié » (`DroitsSurLesCharges::aUnComptable()`).

**Dépendances.**
- **Quand le report se calcule** (`Perimetre::reporteLeCreditDeTva()` l. 95-104) : au réel normal, si tous les magasins de la société sont comptés et si les charges de « Toute la société » le sont aussi.
- La cadence et l'ancre.
- **Le mois en cours de l'écran**, à Paris pour l'espace de la société, du cabinet et de l'expert-comptable. Au trimestriel, le trimestre en cours se montre au mois en cours.
- **Les droits**, pour les charges comptées.
- **L'écran**, pour les textes du comptable.

**Ce qui change le passé.**
- Tout ce qui change la carte 4.1.
- Déclarer, modifier ou corriger le crédit (`DeclarerUnCreditDeTvaAction`, `ModifierUnCreditDeTvaAction`, `CorrigerUnCreditDeTvaAction`).
- La confirmation (`ConfirmerUnCreditDeTvaAction`) : elle ne change que la note et la pastille.
- Le changement de régime.
- Un nouveau magasin : le report exige qu'il soit compté.
- Un lien de cabinet qui naît ou prend fin, y compris par la tâche horaire `RattacherLesExpertsComptables` : il change l'état « à vérifier » ou « non vérifié ».

**Mise en cache.**
- Les sommes des mois viennent des entrées de la carte 4.1.
- La chaîne, l'ancre, l'état, le régime, la date d'arrivée et la liste des magasins se relisent ou se recalculent à chaque affichage.

**Version.** Celles des mois. Aucune pour la chaîne (§ 3.4).

**Risque restant : faible** pour le cache. Les trois constats du § 7 sont **hors cache**.

**À noter pour la fusion avec GRAPH-AN.** Le report se calcule par la cadence du régime (mois ou trimestre). Pour un découpage par trimestre ou par année, ce que montre chaque barre (dernier solde, somme des TVA à payer) reste à définir. Quelle que soit la règle, elle s'assemble à l'affichage et ne va jamais en cache.

**Verdict : cache par mois** pour les entrées. La chaîne n'est jamais en cache.

### 4.3 Le chiffre clé « TVA à payer »

**Calcul.**
- Branche actuelle : `ChiffresDuTableauDeBord::pour()` (l. 68-72) et `tva()` (l. 90-101). C'est la TVA du mois en cours, avec son estimation quand des charges à valider la changent.
- TVA27 : `ChiffresDuTableauDeBord::tvaReportee()` (l. 127). C'est la période en cours, après le crédit reporté de la période d'avant.

**Ce qui change son passé.**
- Sans le report, seul le mois en cours compte, et il se relit toujours.
- Avec le report, tout mois ancien et toute déclaration changent le crédit reporté dans la période en cours. C'est la propagation du § 3.

**Mise en cache.**
- Le mois en cours de chaque magasin se relit toujours.
- Les mois passés viennent du cache.
- La chaîne se recalcule.

**Risque restant : faible. Verdict : cache par mois.**

### 4.4 La TVA de la page des charges et de l'état téléchargeable

**Calcul.**
- `TvaDeLaPeriode::pourLaPageDesCharges()` (TVA27 l. 258-285) et `avecLeReport()` (l. 218-236).
- Au trimestriel, le trimestre entier du mois choisi compte.
- Pour qui ne voit pas les graphiques, seules les charges qu'il voit comptent.
- L'état en PDF et en Excel reprend cette même TVA (`EtatDesCharges`).

**Deux chemins calculent aujourd'hui la même chaîne** : celui du tableau de bord (`SeriesDesGraphiques::tvaReportee()`) et celui de la page des charges (`TvaDeLaPeriode::avecLeReport()`). Avec un cache, les deux doivent lire **la même** source de sommes mensuelles, sinon la page et le tableau de bord pourraient ne pas montrer la même chose.

**Ce qui ne va pas en cache.** Le tableau « type de charge × mois », la liste et les lignes de l'« À faire » se lisent en direct : c'est la page de travail, et ces lectures sont paginées et peu coûteuses.

**Risque restant : faible. Verdict : cache par mois**, par le même chemin que le tableau de bord.

---

## 5. Les sources et les points de mécanique

### 5.1 Source « ventes » : la TVA collectée

**Ce qui est lu.** `VentesDuTableauDeBord::lire()` (l. 102-134) et `emis()` (l. 216-230). Les factures non brouillon et les avoirs émis, par jour local.

**Pourquoi le passé ne change pas.** Un document émis est figé (`GardesDesDocumentsEmis`). Sa date est l'instant du registre. Une correction est un avoir daté du jour de son émission.

**Version.** Le nombre, la somme et le plus grand identifiant par (magasin, mois local), avec **exactement** le filtre de la lecture. Elle ne contient ni le statut de règlement (payée, en retard : tâche horaire `MarquerFacturesEnRetard`) ni `cloture_caisse_id` (`ClotureAutomatiqueCaisse`). Ces colonnes changent sans changer la TVA, et ne feraient que relancer des calculs.

**Risque restant : aucun.** Voir `analyse-ventes.md`. **Verdict : cache par mois.**

### 5.2 Source « achats » : la TVA déductible des achats

**Ce qui change le passé** (`AchatsDuTableauDeBord::documents()` l. 48-98, et `analyse-achats.md`) :
- la validation d'une facture dont la date est libre, sans aucune borne (`FactureAchat::verifierLaDateDeLaSaisie()` l. 205-210 exige seulement une date pour une saisie à la main) ;
- la correction d'une facture validée (`ModifierFactureAchatAction` l. 199-200), qui peut changer `total_tva` et `date_facture`.

**Ce qui ne le change pas.** Un avoir fournisseur est daté de son émission et ne se modifie plus.

**Bientôt.** L'API et SUPER PDP valideront des factures datées du passé.

**Version.** L'empreinte du contenu par (magasin, mois). Une facture sans date se compte dans un mois « large », jour d'avant et jour d'après compris.

**Risque restant : faible. Verdict : cache par mois.**

### 5.3 Source « charges » : validées et à valider

Ce qui est lu : `charges` filtrées par société, `etat IN ('validee', 'a_valider')`, `mois_tva IN (…)`, magasins comptés et « Toute la société » (`ChargesDuTableauDeBord::lire()`). Une pièce sans chiffres n'a pas de montant. Une charge renvoyée sort de la TVA tant qu'elle est chez le client.

**Les gestes qui changent un mois, passé ou non :**

| Geste | Fichier | Ce qui change | Mois touchés | Chaîne réelle | Chaîne estimée |
|---|---|---|---|---|---|
| Déposer (client) | `DeposerUneChargeAction` l. 57-104 | nouvelle charge « à valider » ; une pièce sans chiffres n'a pas de montant | son mois de TVA, quel qu'il soit | non | oui |
| Ajouter (comptable, avec la pièce) | même action, l. 89-96 | la charge naît validée | son mois | oui | oui |
| Écrire les chiffres (client) | `SaisirUneChargeAction` l. 60-112, `EcritureDUneCharge::remplir()` | montants, magasin, mois (d'après les dates, sauf mois fixé) ; « pièce sans chiffres » ou « renvoyée » devient « à valider » | l'ancien et le nouveau mois | non | oui |
| Écrire les chiffres (comptable) | même action, l. 97-104 | validée d'un geste ; mois fixé possible | l'ancien et le nouveau | oui | oui |
| Renvoyer | `RenvoyerUneChargeAction` l. 48-80 | « à valider » devient « renvoyée » et sort de l'estimation | son mois | non | oui |
| Valider | `ValiderUneChargeAction` l. 50-84 | « à valider » devient « validée » | son mois | oui | non (elle était déjà comptée) |
| Corriger (charge validée, avec un motif) | `CorrigerUneChargeAction` l. 55-90 | montants, magasin, mois de TVA, autoliquidation, type (part déductible) | l'ancien et le nouveau | oui | oui |
| Supprimer (non validée) | `SupprimerUneChargeAction` l. 43-65 | la charge disparaît | son mois | non | oui |
| Gérer les types de la société | `GererLesTypesDeChargeAction` | rien sur les montants : la nature d'un type est fixée à sa création, et les montants sont enregistrés sur la charge | aucun | non | non |
| Étape 2 (SUPER PDP), à venir | un travail en file | une charge « à valider » naît d'une facture reçue, datée de sa facture | n'importe quel mois | non | oui |

**Aucune borne de temps** sur ces gestes :
- `SaisieDeCharge` accepte tout jour valable ;
- une charge validée se corrige à tout moment ;
- une société suspendue ou résiliée dépose et fait valider ses charges (`GestesSurLesCharges`, bloc de la classe l. 36-39 et `touchePlusASesCharges()` l. 123-126) ;
- seules une société révoquée et une société close ne touchent plus à leurs charges.

**Mise en cache : aucune.**
- La lecture directe est une requête sur l'index (`societe_id`, `mois_tva`) qui rend six colonnes, une ligne par charge des mois lus.
- L'empreinte d'un mois devrait parcourir les mêmes lignes : elle coûterait autant que la lecture, et apporterait ses pièges (mois devenu vide, charge qui change de mois ou de magasin) pour rien.
- **Une économie compatible avec la règle des montants**, si l'on veut : la règle n'interdit qu'une somme faite par la base sur une colonne **décimale**, et les montants des charges sont des entiers (`bigint`). La base pourrait donc additionner par (magasin, mois de TVA, état). La documentation de `TvaDeLaPeriode` dit aujourd'hui « additionnés ici » : c'est un choix à faire.

**Si l'on tient malgré tout à mettre les charges en cache :**
- une entrée par (société, magasin ou « Toute la société », mois de TVA) ;
- quatre sommes : autoliquidée validée, déductible validée, autoliquidée à valider, déductible à valider ;
- la version du § 6.2, calculée sur la grille complète.

**Risque restant : aucun** en lecture directe. **Verdict : jamais en cache.**

### 5.4 L'autoliquidation

**Où elle se calcule.** Dans `ChargesDuTableauDeBord::lire()` (l. 82-83) :
- pour une charge validée d'un fournisseur établi hors de France, `total_tva` entre dans la TVA collectée et `total_tva_deductible` dans la TVA déductible ;
- une charge à valider n'entre que dans l'estimation.

**Son effet.** Il est nul sur la TVA à payer seulement si toute la TVA est déductible. Avec un véhicule de tourisme, déductible à 0 %, l'autoliquidation **augmente** la TVA à payer.

**Ce qui la change.** Cocher ou décocher la case, changer le type, les montants ou le mois, par l'écriture des chiffres ou par la correction.

**Mise en cache.** Elle suit les charges et se lit en direct. Si l'on gardait des entrées, la part autoliquidée devrait y être une somme à part : elle va dans la TVA collectée, jamais dans la déductible.

**Risque restant : aucun. Verdict : jamais en cache.**

### 5.5 Le crédit de départ déclaré : l'ancre

**La table.** `credits_de_tva` a une ligne par (société, cadence, période), avec une unicité (migration l. 57). La base garantit que :
- une ligne ne s'efface jamais (l. 78) ;
- sa société, sa cadence et sa période ne changent jamais (`GardesDesCreditsDeTva`) ;
- son solde n'est jamais positif.

**Ce qui la change.**
- **Déclarer**, pour une période terminée depuis l'arrivée (`CreditsDeTva::periodesDeclarables()`). Si la période est plus récente que l'ancre actuelle, la nouvelle déclaration devient l'ancre (§ 7.2).
- **Modifier** (propriétaire) : le solde change et la vérification s'efface.
- **Corriger** (comptable) : le solde change, avec un motif.
- **Confirmer** : seules la note et la pastille changent.
- **Changer de régime** : les déclarations de l'autre cadence ne comptent plus (`ancre()` l. 151-165 filtre par la cadence).

**Mise en cache : aucune.** C'est une requête indexée par affichage, qui se fait déjà. L'état « vérifié », « à vérifier » ou « non vérifié » se calcule à l'instant : il dépend du lien du cabinet.

**Risque restant : aucun. Verdict : jamais en cache.**

### 5.6 Le régime mensuel ou trimestriel

**Ce que fait la cadence** (`RegimeTva::cadence()`, `CadenceDeLaTva`) :
- au trimestriel, elle additionne trois mois civils avant le report ;
- le trimestre en cours compte les mois déjà commencés et se montre au mois en cours ;
- elle choisit l'ancre.

**Ce qui la change.** `ModifierParametresFiscauxSocieteAction` change le régime à tout moment, sans date d'effet. Toute l'histoire se regroupe alors autrement, et l'ancre change.

**Mise en cache.** On relit le régime à chaque affichage et l'on regroupe les mois en mémoire. Aucun trimestre ne va en cache.

**Risque restant : aucun** pour le cache. Hors cache, voir le § 7.1. **Verdict : jamais en cache.**

### 5.7 La franchise en base et le régime à choisir

**Où elle se lit.**
- En franchise, ou avant le choix du régime, aucune TVA ne s'affiche (`Perimetre::montreLaTva()` l. 234, `montreLaCarteDeLaTva()`).
- Les ventes émises en franchise gardent leur TVA à 0, figée sur le document.

**Ce que la mise en cache n'a pas à suivre.** Les sommes des mois sont celles des documents et ne dépendent pas du régime : en changer n'invalide aucune entrée. Le régime se relit à chaque affichage.

**Risque restant : aucun** pour le cache. Hors cache, voir le § 7.1. **Verdict : jamais en cache.**

### 5.8 Les droits et les charges de « Toute la société »

**Qui compte quoi.** La TVA d'un compte compte :
- les ventes et les achats des magasins où il voit ces documents ;
- les charges des magasins où il voit les charges (`AccesAuxCharges::voitLeMagasin()` l. 59-62) ;
- celles de « Toute la société » seulement sur un écran de tous ses magasins, tous comptés.

Un administrateur à qui « Charges : ajouter » est cochée voit « Toute la société », mais seulement les magasins où la case est cochée (`DroitsSurLesCharges::delAdministrateur()` l. 149-159).

**La règle.**
- Ni les droits ni un assemblage ne vont en cache.
- Les entrées sont par magasin. Celle de « Toute la société » est rangée sous la société, jamais mêlée à un magasin.
- Une entrée ne contient que ce que voit toute personne qui voit cette source dans ce magasin.
- Le contrôle des droits passe avant toute lecture du cache.

**Le risque si l'on mettait en cache une chaîne par société seulement.** Un administrateur verrait la chaîne du propriétaire, qui compte les charges de magasins qu'il ne voit pas. Ou, à l'inverse, le propriétaire verrait celle de l'administrateur.

**Risque restant : faible. Verdict : jamais en cache.**

### 5.9 Les remboursements de crédit (page TVA à venir)

**Ce qu'ils font sur la déclaration.** Une demande de remboursement, jointe à une déclaration (formulaire n° 3519), réduit le crédit reporté sur la période suivante. Le crédit déclaré « à reporter », qui sert d'ancre, est déjà net du remboursement demandé sur sa propre déclaration. TVA27 le dit dans la note `SANS_LES_REMBOURSEMENTS`.

**Ce qui change le passé.** Une demande saisie tard, accordée, refusée ou corrigée change toutes les périodes qui suivent la sienne.

**Ce qu'il faut pour le cache.** Les remboursements sont des données de la société, par période, jamais par magasin ni par mois. Ils entrent dans le calcul de la chaîne, lus à chaque affichage. Ils n'entrent jamais dans les sommes des mois.

**Ce que l'éditeur doit trancher :**
- un remboursement à la période de l'ancre est-il déjà compté dans le crédit déclaré ? Proposition : oui ;
- un montant refusé revient-il au crédit, et à quelle période ?
- un remboursement reçu ne change pas la TVA, seulement la trésorerie : se montre-t-il sur la page TVA seulement ?

**Risque restant : moyen**, tant que ce n'est pas conçu. **Verdict : à trancher par l'éditeur.**

### 5.10 Le mois en cours, les mois à venir et le calendrier des magasins

**Les décalages.**
- L'espace de la société, celui du cabinet et la page de l'expert-comptable suivent Paris (`PerimetreDesGraphiques` l. 76, 151, 190).
- Les ventes et les achats se rangent au jour de leur magasin. La Martinique vend encore le 30 novembre quand Paris est déjà au 1er décembre.
- Le mois de TVA d'une charge est un texte « AAAA-MM », sans fuseau.
- Un achat ou une charge peuvent être datés dans un mois à venir : ils y entrent le jour où ce mois arrive.

**La règle.**
- Ne jamais tenir un mois pour clos d'après le calendrier de l'écran.
- Le mois en cours **de chaque magasin** se relit toujours.
- Chaque mois de la fenêtre, passé, en cours ou à venir, a sa version.
- La chaîne s'arrête au mois en cours de l'écran (`$unMois <= $ceMois`).

**Risque restant : faible. Verdict : jamais en cache** (le mois en cours de chaque magasin).

### 5.11 Les cas difficiles demandés

| Cas | Ce que montre le code | Ce que la conception doit faire |
|---|---|---|
| Une suppression | Une charge non validée supprimée disparaît de son mois. Aucun document compté des ventes ou des achats ne se supprime. Un brouillon supprimé n'était pas compté. | La requête de version groupée ne rend **aucune ligne** pour un mois devenu vide. Comparer donc sur la grille attendue (magasins + « Toute la société », mois), un mois absent valant « vide ». Sinon, la dernière somme d'un mois vidé resterait en cache. |
| Une mise à jour qui garde la date | Une charge validée corrigée dans le même mois et le même magasin. Une facture d'achat dont seules les lignes et la TVA changent. `updated_at` ne retient que la seconde. | L'empreinte porte sur le contenu lu : identifiant, état, autoliquidation, TVA, TVA déductible. Jamais `updated_at`, jamais « nombre et plus grand identifiant » pour une source qui se modifie. |
| Une tâche planifiée qui écrit | Aucune tâche n'écrit les charges, les crédits ni les achats (`bootstrap/app.php` l. 113-266). `MarquerFacturesEnRetard` et `ClotureAutomatiqueCaisse` écrivent hors des colonnes de la TVA. `RattacherLesExpertsComptables`, chaque heure, change les liens et les comptes, donc les droits et l'état de l'ancre. | Droits et état relus à chaque affichage ; colonnes de règlement hors de l'empreinte. |
| Une importation | L'étape 2 (SUPER PDP) ajoutera des charges « à valider » et des factures d'achat datées du passé, par un travail en file. L'étape 10 (API) ajoutera des factures d'achat sans date. | Des versions lues dans les données à chaque affichage. Jamais une invalidation par les actions, qui manquerait ces travaux. |
| Un compte retiré dont le nom figé reste | `deposee_par_nom`, `validee_par_nom`, `saisi_par_nom` et `verifie_par_nom` ne sont lus par aucune carte de TVA. Une charge validée par un compte retiré reste validée. Le retrait change seulement les droits et `aUnComptable()`. | Rien en cache. Droits et état relus. |
| Un magasin fermé | Ses ventes et ses achats sont figés (`verifierQuIlEstOuvert()`), **mais pas ses charges** : `EcritureDUneCharge::magasin()` (l. 140-160) accepte tout magasin de la société, fermé ou cédé, par exemple pour le loyer du dernier mois. Il reste compté, et le report l'exige. | Ne jamais tenir un magasin fermé pour figé. |
| Un nouveau magasin | Il entre dans la liste des magasins de la société. Un compte qui ne le voit pas perd le report. | Liste relue à chaque affichage. |
| Un changement de réglage fiscal | Régime (cadence, franchise, à choisir) : § 5.6 et 5.7. L'option pour les débits ne joue aujourd'hui sur aucune carte. Le mois de fin d'exercice change les barres par exercice de GRAPH-AN, jamais la chaîne. Une société membre d'un assujetti unique n'a pas de crédit (`CreditsDeTva::aLieuDansLa()`). | Tous relus à chaque affichage. Aucun n'entre dans une somme de mois. |
| Étape 5, à venir | Si la TVA collectée des services passe à l'encaissement, les paiements entrent dans la TVA. Or `paiements.recu_le` peut être daté du passé (`EnregistrerPaiementAction` l. 122-127 refuse seulement l'avenir). | Ajouter alors la source « paiements », avec sa version par (magasin, mois). |

### 5.12 L'empreinte d'un mois et l'instantané

**Quatre règles, sans quoi la version ment :**

1. **Une même définition pour la version et pour la lecture.** Le filtre et les colonnes sont tenus dans une constante partagée.
2. **Une empreinte du contenu**, jamais un horodatage.
3. **La grille complète** : un mois vide vaut « vide ».
4. **Un seul instantané.** On lit les versions et les données dans une transaction `REPEATABLE READ READ ONLY`. À défaut, on lit toujours la version **avant** les données.

**Pourquoi l'ordre compte.** Si l'on lit les données avant la version, une validation qui s'enregistre entre les deux range des données anciennes sous une version nouvelle, et elles restent servies. Dans l'autre ordre, au pire, on refait un calcul.

**Risque restant : faible. Verdict : cache par mois.**

### 5.13 Les écritures hors des gestes et l'identité de la base

**Ce qui écrit hors des gestes, ou remplace la base :**
- les travaux en file de l'étape 2 et l'API ;
- le semis ;
- « Recommencer à zéro » d'une copie de démonstration : mêmes identifiants, autres données ;
- `migrate:fresh` et la restauration d'une sauvegarde : les identifiants reviennent ;
- un déploiement qui change un calcul. C'est justement le cas de GRAPH-AN et de TVA27.

**La règle.**
- Les versions se lisent dans les données. On n'invalide jamais par un événement.
- La clé porte la version du code (le commit déployé) et l'identité de la base, ou bien le cache est gardé dans la base elle-même (magasin de cache `database`).
- Les entrées ont une durée de vie bornée, et le cache se vide à chaque déploiement.

**Risque restant : faible. Verdict : cache par mois.**

---

## 6. Les versions concrètement

### 6.1 Ventes et achats

Ce sont celles des analyses sœurs, sur la même entrée (magasin, source, mois local), qui gagne la TVA de chaque jour.

### 6.2 Charges, seulement si l'on choisit de les mettre en cache

```sql
SELECT magasin_id, mois_tva,
       md5(string_agg(concat_ws(':', id, etat, fournisseur_hors_france, total_tva, total_tva_deductible), ',' ORDER BY id)) AS empreinte
FROM charges
WHERE societe_id = :societe
  AND etat IN ('validee', 'a_valider')
  AND mois_tva BETWEEN :premier_mois AND :dernier_mois
GROUP BY magasin_id, mois_tva;
```

On la compare sur la grille {magasins de la société, « Toute la société »} × {mois}. Un couple absent vaut « vide ». L'index `(societe_id, mois_tva)` existe déjà.

### 6.3 Charges sans cache : la variante la plus simple

La base additionne des entiers, ce que la règle permet :

```sql
SELECT magasin_id, mois_tva,
       sum(total_tva)            FILTER (WHERE etat = 'validee'   AND fournisseur_hors_france) AS autoliquidee_validee,
       sum(total_tva_deductible) FILTER (WHERE etat = 'validee')                               AS deductible_validee,
       sum(total_tva)            FILTER (WHERE etat = 'a_valider' AND fournisseur_hors_france) AS autoliquidee_a_valider,
       sum(total_tva_deductible) FILTER (WHERE etat = 'a_valider')                             AS deductible_a_valider
FROM charges
WHERE societe_id = :societe
  AND etat IN ('validee', 'a_valider')
  AND mois_tva BETWEEN :premier_mois AND :dernier_mois
GROUP BY magasin_id, mois_tva;
```

L'assemblage reste le même : magasins comptés, plus « Toute la société » quand elle est comptée.

### 6.4 Crédit déclaré

C'est la requête actuelle de `CreditsDeTva::ancre()`, à chaque affichage.

### 6.5 Chaîne

Aucune version : elle se recalcule.

---

## 7. Constats hors cache, à trancher

### 7.1 La chaîne part de l'arrivée, quel que soit l'historique du régime (risque élevé)

**Le code.**
- `CreditsDeTva::premierePeriode()` (l. 96-99) part toujours de la période de l'arrivée.
- `societes.regime_tva` n'a pas de date d'effet : aucune migration ne garde l'historique du régime.
- `ancre()` filtre sur la cadence **actuelle**.

**Passage de la franchise au réel normal.**
- Les mois de franchise entrent dans la chaîne.
- Leurs ventes ont une TVA à 0, mais la TVA de leurs achats et de leurs charges compte comme déductible.
- Il naît un crédit fictif, qui diminue la TVA à payer des périodes suivantes jusqu'à la première déclaration indiquée.
- Une entreprise en franchise ne déduit aucune TVA.

**Passage du mensuel au trimestriel, ou l'inverse.**
- Le crédit de la dernière déclaration mensuelle ne compte plus.
- La chaîne repart de l'arrivée, à zéro, regroupée en trimestres.
- Une déclaration trimestrielle ne se saisit qu'à la fin du premier trimestre. Jusque-là, la TVA à payer du trimestre en cours ignore le crédit réellement reporté.

**Proposition.**
- Garder la date d'effet du régime, ou son historique.
- Faire partir la chaîne de la première période du régime actuel.
- Lors d'un changement de cadence, prendre pour crédit de départ le crédit déclaré de la dernière période de l'ancienne cadence.

### 7.2 Une seule ancre : la déclaration la plus récente (risque moyen)

**Le code.** `ReportDuCreditDeTva::calculer()` ne prend qu'une ancre. Les déclarations plus anciennes « restent en historique » et ne comptent plus, même pour les périodes qui les suivent.

**La conséquence.** Déclarer une période plus récente **change les chiffres des périodes situées entre l'ancienne ancre et la nouvelle**. Elles repartent de l'arrivée, avec un crédit de zéro, au lieu du crédit déclaré. L'ancienne période déclarée perd aussi son statut « déclarée ».

**Proposition.** Chaque déclaration remet la chaîne à son crédit, à sa propre période. Pour chaque période, la déclaration la plus récente qui ne la suit pas sert d'ancre.

### 7.3 Le report se calcule avec les seules charges que le compte voit (risque moyen)

**Le code.** `Perimetre::reporteLeCreditDeTva()` (l. 95-104) exige :
- que tous les magasins soient comptés pour les ventes et les achats ;
- que les charges de « Toute la société » soient comptées.

Il n'exige **pas** les charges de chaque magasin.

**La conséquence.** Prenons un administrateur dont la portée couvre tous les magasins et qui voit les graphiques partout, mais à qui « Charges : ajouter » n'est cochée que dans une partie des magasins. Il voit une chaîne sans les charges des autres magasins. Le « crédit à reporter » et la « TVA à payer » de la même société, pour la même période, diffèrent alors entre lui et le propriétaire. `LectureDeLaTva` lui donne même le montant du crédit.

**Proposition.** Exiger que les charges de tous les magasins de la société soient comptées. Sinon, pas de report, et la note `SANS_LE_REPORT`.

### 7.4 Deux remarques mineures

**GRAPH-AN.** La barre en cours se compare aux mêmes jours de l'an dernier pour les ventes et les achats, mais au mois entier pour les charges, puisqu'une charge n'a pas de jour (`tvaDeLIntervalle()` l. 260). La note « mêmes jours » ne le dit pas. Risque faible.

**TVA27.** Deux chemins calculent la même chaîne (§ 4.4). Il faudrait n'en garder qu'un.

---

## 8. Garde-fous à livrer avec le lot

1. **Un test d'inventaire des écritures.** Pour chaque geste des § 5.2, 5.3 et 5.5 et pour le changement de régime, la TVA servie par le cache doit égaler celle d'un calcul sans cache, et la version des mois touchés doit changer. Cas à couvrir :
   - une suppression qui vide un mois ;
   - un changement de mois de TVA ;
   - un changement de magasin, y compris vers « Toute la société » ;
   - un renvoi ;
   - une validation ;
   - une correction qui garde le mois ;
   - une écriture par `DB::table()`.

   Comme `InventaireDesActionsTest`, ce test échoue si une action nouvelle écrit ces tables sans y figurer.
2. **Un test de la chaîne.** On valide une charge d'un mois vieux de deux ans. Chaque période qui suit doit égaler le calcul sans cache, avec et sans ancre, au mensuel et au trimestriel.
3. **Un test des droits.** Le propriétaire, l'administrateur coché dans une partie des magasins, le cabinet et l'expert-comptable lisent les mêmes entrées, mais chacun les assemble selon ses droits. Aucun ne reçoit rien d'un magasin qu'il ne voit pas.
4. **Un test de course.** Une validation s'enregistre pendant le calcul. Le cache ne doit jamais garder l'ancien résultat sous la nouvelle version.
5. **Un contrôle chaque nuit**, à côté de `app:verifier-isolation`. On recalcule sans cache un échantillon d'entrées. Au moindre écart, on prévient l'éditeur et l'on vide le cache.
6. **La clé du cache** porte la base et le code. La durée de vie des entrées est bornée, et le cache se vide à chaque déploiement.
