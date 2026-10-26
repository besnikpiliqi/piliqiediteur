# Série 8, point 15 : l'écart d'un centime et l'audit des calculs monétaires

Rédigé le 17/10/2026 sur la copie de travail `serie8/banc` (HEAD 30844db, SQLite). Rien n'a été modifié dans le dépôt `/home/user/piliqiediteur`, et PostgreSQL n'a pas été utilisé. Chaque affirmation renvoie au code lu (chemin:ligne, identique dans le dépôt puisque la copie est HEAD 30844db), à un essai exécuté (sortie citée) ou à une source consultée ; ce qui n'a pas pu être vérifié est marqué « à vérifier ».

Fichiers produits pendant l'étude (tous dans le bloc-notes, hors du dépôt) :
- essai de reproduction : `serie8/banc/tests/Feature/Scratch/ReproductionArrondiTest.php`, sortie complète `serie8/reproduction-sortie.txt` ;
- simulation de la politique proposée contre la vraie classe `CalculateurLigne` : `serie8/simulation/simuler.php`, sortie `serie8/simulation/resultat.txt` ; cas du prorata négatif : `serie8/simulation/cas-prorata.php` ;
- copies des schematrons officiels EN 16931 consultés : `serie8/sources/ubl-model.sch` et `serie8/sources/cii-model.sch` ;
- prototype de la correction (copie séparée, jamais le dépôt) : `serie8/prototype/`, fichiers modifiés aussi rangés dans `serie8/modifs/`, résultats des suites `serie8/suite-avant.txt` et `serie8/suite-apres.txt`, parcours rejoué `serie8/reproduction-sortie-prototype.txt`. Son dossier `vendor` (liens vers celui de `banc`, plus des copies de `phpunit`, `brianium` et `laravel/pao`, nécessaires pour qu'il charge son propre code) a été retiré après les essais pour rendre l'espace disque (94 % occupé) ; pour rejouer, le recréer ainsi.

(Chemins relatifs au dossier `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/`.)

---

## 0. Résumé

1. **Reproduit, au centime près, sur l'écran de la caisse, le ticket enregistré, son PDF, la clôture Z et le tableau de bord.** 2 articles à 25,00 € TTC (20 %) donnent 41,66 € HT + 8,34 € de TVA ; 2 articles à 17,50 € TTC donnent 29,16 € HT + **5,84 €** de TVA. Un seul article à 50,00 € donne bien 41,67 € + 8,33 €, et un seul à 35,00 € donne 29,17 € + 5,83 €.
2. **La source exacte n'est pas un défaut d'affichage : c'est la règle de calcul des ventes aux particuliers**, écrite dans `CLAUDE.md` (ligne 167 : « Une vente à un particulier se calcule depuis le prix TTC, article par article (PU HT et PU TTC au centime, multipliés par la quantité) ») et appliquée à un seul endroit : `app/Domain/Facturation/Services/ConstructeurLigneDocument.php:197` (PU HT = PU TTC ÷ 1,2 arrondi au centime : 25,00 → 20,83 ; 17,50 → 14,58), puis `:218` → `CalculateurLigne::montantsDeVente()` (`app/Domain/Facturation/Services/CalculateurLigne.php:73-81`), qui multiplie ce PU HT **déjà arrondi** par la quantité (20,83 × 2 = 41,66 ; 14,58 × 2 = 29,16) et prend la TVA par différence (50,00 − 41,66 = 8,34 ; 35,00 − 29,16 = 5,84). Deux lignes séparées de 25,00 € donnent le même écart, parce que les totaux additionnent des HT de ligne arrondis un à un (`CalculateurLigne::totaliser()`, `:443-460`).
3. **Les ventes aux professionnels ont un autre défaut, plus grave**, venu de la règle R4/E1 (même ligne 167) : le TTC facturé n'est pas toujours le prix affiché. Essais exécutés : 1 article à 9,99 € TTC est facturé **10,00 €** à un professionnel ; 3 articles à 0,99 € sont facturés **2,98 €** au lieu de 2,97 € ; deux lignes de 25,00 € sont facturées **49,99 €** ; une ligne de 0,10 € apparaît à 0,09 € TTC. Le même panier n'a donc pas les mêmes montants selon le type de client (simulation exécutée sur la vraie classe : 18 426 paniers sur 20 000 tirés au hasard diffèrent entre particulier et professionnel ; 8 488 ont un TTC professionnel différent de la somme des prix).
4. **Tout le reste additionne bien les montants enregistrés** : PDF, fiche de la facture, contenu signé, clôture Z, relevé « Fermer ma caisse », e-reporting, tableau de bord, avoirs. Aucun écran ne recalcule un document validé depuis le catalogue, et HT + TVA = TTC tient partout (vérifié sur chaque document de l'essai). Les calculs se font en centimes entiers ; les flottants PHP ne servent que de transport (entrées et sorties de `CalculateurLigne`, écrans), sans erreur observée, mais l'exigence 4 de l'éditeur demande de les retirer.
5. **Proposition** : une seule politique pour toute vente, particulier ou professionnel, ticket ou facture : le TTC payé est la somme des prix affichés (quantité × PU TTC, remise arrondie une fois) ; pour chaque taux, HT = TTC du taux ÷ (1 + taux), arrondi une fois au centime, réparti sur les lignes par cumul dans leur ordre ; TVA = TTC − HT. Elle donne 41,67 + 8,33 pour 50,00 € quel que soit le découpage (1 × 50, 2 × 25, 25 + 25), 29,17 + 5,83 pour 35,00 €, et les mêmes montants pour un particulier et un professionnel. Deux choix restent à l'éditeur (règle 4 du protocole) : appliquer aussi cette politique aux professionnels (recommandé), et la règle du demi-centime exact (recommandé : HT arrondi au demi-centime supérieur, TVA par différence).
6. **Les clôtures n'affichent aucun mode de paiement** (`app/Filament/App/Resources/ClotureCaisseResource.php:101-153`), la caisse n'accepte qu'un mode par vente (`app/Filament/App/Pages/Caisse.php:257-262`, `ReglementComptant.php:22-25`), et « Avoir » n'est pas un mode (`ModePaiement.php`). Le relevé personnel les a déjà (`FermerSaCaisseAction.php:231-240`). Proposition en 3.6.
7. **Le plan du moteur de TVA (lots M1 et M2) ne règle pas le problème** : il déplace la calculatrice et branche le moteur sur elle « sans changer un seul résultat », et ses tests de référence fixent justement l'écart (V01 : 2 câbles à 40,00 € = 66,66 € HT + 13,34 € ; V03 : 13,99 € TTC pour un panier de 14,00 €). La correction doit passer avant M0 (texte de `CLAUDE.md`) et M1, dans un lot à part ; le plan doit être mis à jour (section 3.9).

---

## 1. Reproduction

### 1.1 Ce qui a été fait

Essai jetable `tests/Feature/Scratch/ReproductionArrondiTest.php` (copie de travail), lancé par `php artisan test --compact tests/Feature/Scratch/ReproductionArrondiTest.php` (2 tests verts). Il suit le parcours d'un utilisateur :

1. une société validée et son magasin (`ouvrirUneSociete()`, identité légale complétée), le propriétaire connecté ;
2. des produits créés **par une facture d'achat validée** (`CreerFactureAchatAction` puis `ValiderFactureAchatAction`), prix de vente TTC saisi à la main : 50,00 ; 35,00 ; 9,99 ; 0,99 ; 25,00 ; 17,50 € à 20 % ; un livre à 10,00 € à 5,5 % ;
3. des produits **dont le barème du magasin donne le prix** (bénéfice au-delà de 40 €, sans puis avec arrondi X9), pour des prix d'achat de 1,6667 €, 1,66 € et 1,67 € ;
4. un produit créé **depuis l'écran Produits** (`CreateProduit`, Livewire) à 50 € TTC ;
5. des **ventes à la caisse** par la vraie page Livewire `Caisse` : remplissage du formulaire, lecture du HTML affiché (total de chaque ligne et totaux), puis « Encaisser » ;
6. le **PDF** (HTML du générateur) de trois tickets ;
7. des **factures à un professionnel** (`CreerFactureAction` + `EmettreFactureAction`) ;
8. des **avoirs** (`EnregistrerAvoirAction`) : retour complet, retours partiels successifs, ticket et facture, et le PDF d'un avoir ;
9. l'aperçu du **relevé « Fermer ma caisse »** (`FermerSaCaisseAction::apercu()`) ;
10. la **clôture du jour** (`CloturerCaisseAction`) et sa ventilation ;
11. le **tableau de bord** (`ChiffreDAffaires::netSurPeriode()` et le widget `FacturesApercu` rendu par Livewire).

### 1.2 Ce qui est affiché et enregistré

Extraits de `serie8/reproduction-sortie.txt` (HT / TVA / TTC) :

| Vente | Écran de la caisse (ligne et totaux) | Ticket enregistré | PDF |
|---|---|---|---|
| 50,00 × 1 | 41,67 + 8,33 = 50,00 | 41,67 / 8,33 / 50,00 | 41,67 / 8,33 / 50,00 |
| 35,00 × 1 | 29,17 + 5,83 = 35,00 | 29,17 / 5,83 / 35,00 | — |
| **25,00 × 2** | **41,66 + 8,34** = 50,00 | **41,66 / 8,34** / 50,00 | **41,66 / 8,34** / 50,00 |
| **17,50 × 2** | **29,16 + 5,84** = 35,00 | **29,16 / 5,84** / 35,00 | — |
| **25,00 + 25,00 (deux lignes)** | 20,83 + 4,17 deux fois ; totaux **41,66 / 8,34** / 50,00 | **41,66 / 8,34** / 50,00 | — |
| 9,99 × 1 | 8,33 + 1,66 = 9,99 | 8,33 / 1,66 / 9,99 | — |
| 0,99 × 3 | 2,49 + 0,48 = 2,97 | 2,49 / 0,48 / 2,97 | — |
| 50,00 remisé de 10 % | 37,50 + 7,50 = 45,00 | 37,50 / 7,50 / 45,00 | — |
| 35,00 (20 %) + livre 10,00 (5,5 %) | totaux 38,65 / 6,35 / 45,00 | 38,65 / 6,35 / 45,00 ; ventilation 29,17/5,83/35,00 et 9,48/0,52/10,00 | 38,65 / 6,35 / 45,00 |
| Produit créé à l'écran, 50 € | 41,67 + 8,33 | 41,67 / 8,33 / 50,00 | — |
| Barème, achat 1,6667 € (cible 41,6667 € HT) | 41,67 + 8,33 = 50,00 | 41,67 / 8,33 / 50,00 | — |
| Barème, achat 1,66 € (cible 41,66 € HT) | 41,66 + 8,33 = **49,99** | 41,66 / 8,33 / 49,99 | — |

Factures à un professionnel (même catalogue) :

| Facture | Enregistrée |
|---|---|
| 50,00 × 1 | 41,67 / 8,33 / 50,00 |
| 25,00 × 2 | 41,67 / 8,33 / 50,00 (le particulier : 41,66 / 8,34) |
| 25,00 + 25,00 | 41,66 / 8,33 / **49,99** (lignes 20,83 + 4,17 = 25,00 et 20,83 + 4,16 = **24,99**) |
| 0,99 × 3 | 2,48 / 0,50 / **2,98** |
| **9,99 × 1** | 8,33 / 1,67 / **10,00** |
| 0,10 sur trois lignes | 0,24 / 0,05 / **0,29** (la 2e ligne à 0,09 € TTC) |
| 50,00 remisé de 10 % + livre | 46,98 / 8,02 / 55,00 |

Avoirs, relevé, clôture, tableau de bord :

- Retour complet du ticket 50,00 € : 41,67 / 8,33 / 50,00 (exactement la vente) ; son PDF : −41,67 / −8,33 / −50,00.
- Retours successifs d'un article de « 25,00 × 2 » : 20,83 / 4,17 / 25,00 puis 20,83 / 4,17 / 25,00 (somme = la ligne vendue, 41,66 / 8,34 / 50,00).
- Retour complet de la facture professionnelle de 35,00 € : 29,17 / 5,83 / 35,00.
- Relevé « Fermer ma caisse » : 18 ventes, 598,92 / 117,00 / 715,92 ; encaissements `{"especes":"472.95"}` ; crédit d'avoir 60,00 ; reste dû 182,97.
- Clôture Z : 12 tickets, 3 avoirs, 311,96 / 60,99 / 372,95 = somme des 12 tickets (395,29 / 77,66 / 472,95) moins les 3 avoirs sur tickets (83,33 / 16,67 / 100,00), au centime. Ventilation 20 % : 302,48 / 60,47 / 362,95 ; 5,5 % : 9,48 / 0,52 / 10,00.
- Tableau de bord du jour : 465,59 € HT, 555,92 € TTC, 18 factures, 5 avoirs = 715,92 − 160,00 de TTC et 598,92 − 133,33 de HT, au centime.

Conclusion : **les 41,66 € et les 5,84 € naissent au moment où la ligne est chiffrée** ; ensuite, le ticket, le PDF, la clôture, le relevé et le tableau de bord recopient ou additionnent fidèlement ce qui a été enregistré.

### 1.3 Source exacte

**A. Ventes aux particuliers : le PU HT est arrondi avant d'être multiplié par la quantité.**

- `ConstructeurLigneDocument.php:189-191` : le PU TTC de la ligne (celui de la fiche, 25,00 €).
- `ConstructeurLigneDocument.php:197` : `$prixUnitaireHt = $this->calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, $tauxTvaDuPrix);` → `CalculateurLigne.php:219-227` : PU HT = PU TTC × 10 000 ÷ (10 000 + 2 000), arrondi au centime : 2 500 × 10 000 / 12 000 = 2 083,33 → **20,83 €**.
- `ConstructeurLigneDocument.php:216-218` : pour un particulier (`! calculeDepuisLeHtDeLigne()`, `ContexteDeVente.php:179-182`), `montantsDeVente($prixUnitaireHt, $prixUnitaireTtcPaye, $quantite, $remise)`.
- `CalculateurLigne.php:73-81` et `:633-642` : HT de la ligne = 2 083 × 2 = 4 166 centimes = **41,66 €** ; TTC = 2 500 × 2 = 5 000 ; `montants()` (`:594-601`) : TVA = 5 000 − 4 166 = **8,34 €**.
- De même 17,50 € : PU HT 1 458 ; 1 458 × 2 = 2 916 = **29,16 €** ; TVA 3 500 − 2 916 = **5,84 €**.
- Le docblock le dit en toutes lettres (`CalculateurLigne.php:12-17`) : « 2 x 40,00 € TTC = 66,66 € HT + 13,34 € de TVA = 80,00 € TTC ». C'est la règle de `CLAUDE.md`, ligne 167.

**B. Les totaux additionnent des HT de ligne arrondis un à un.** `CalculateurLigne::totaliser()` (`:443-460`), appelé par `CreerFactureAction.php:74` : deux lignes de 25,00 € à 20,83 € HT font 41,66 €. Pour un particulier, aucune étape ne recalcule le HT sur le total d'un taux.

**C. Ventes aux professionnels : le TTC est recalculé depuis le HT.** `ConstructeurLigneDocument.php:217` → `CalculateurLigne::montantHtDeLigneProfessionnelle()` (`:117-127`, `:649-658`) arrondit le HT de chaque ligne (9,99 ÷ 1,2 = 8,325 → 8,33) ; `repartirLaTvaParTaux()` (`ConstructeurLigneDocument.php:234-252`) → `CalculateurLigne::repartirLaTva()` (`:148-165`) calcule la TVA sur le total HT du taux (8,33 × 20 % = 1,666 → 1,67) ; le TTC devient HT + TVA (`ConstructeurLigneDocument.php:247`) = **10,00 €** pour un article affiché 9,99 €. C'est la règle R4/E1 de `CLAUDE.md`, ligne 167 (« HT de la ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA de chaque taux = total HT du taux × taux… »).

**D. Écarté, preuves à l'appui :**
- *La fiche du produit* : `prix_vente_ht` est toujours la conversion au centime de `prix_vente_ttc` (`Produit.php:381-397`) : 50,00 → 41,67 ; 35,00 → 29,17 ; 17,50 → 14,58 (sortie de l'essai). Et cette colonne n'est lue nulle part ailleurs : `grep -rn prix_vente_ht app resources` ne trouve que `Produit.php` (une ligne de vente repart du TTC, `ConstructeurLigneDocument.php:191,197`).
- *Le barème* : un prix rond reste rond (achat 1,6667 € + 40 € de bénéfice → 50,00 € TTC, 41,67 € HT). Seule une cible HT déjà « non ronde » (41,66 € HT) donne 49,99 € TTC (`BaremePrixDeVente.php:129`, `CalculateurLigne::prixTtcArrondi()` `:247-256`) : c'est l'arrondi voulu du prix cible, pas un écart.
- *L'écran de la caisse* : il calcule avec le même constructeur que l'enregistrement (`AjouteDesLignesDeVente.php:111-119` → `ConstructeurLigneDocument::apercu()` `:91-115`) ; l'essai montre les mêmes chiffres à l'écran et en base. Aucun calcul dans le navigateur (`grep toFixed|Math.|parseFloat` : rien).
- *Le ticket, le PDF, la clôture, le tableau de bord* : ils lisent les montants enregistrés (section 2).
- *Un flottant* : les calculs se font en centimes entiers ; l'essai et 20 000 paniers simulés n'ont montré aucun écart dû à un flottant (section 2.3).

L'écart est donc **voulu par la règle actuelle** : le corriger, c'est changer une décision écrite de l'éditeur. Il l'a demandé (cas obligatoire 50 € → 41,67 + 8,33), mais deux points de la nouvelle règle restent à trancher par lui (3.1).

---

## 2. Audit, module par module

### 2.1 Les dix exigences de l'éditeur, une par une

| # | Exigence | État actuel (vérifié) | Écart |
|---|---|---|---|
| 1 | Prix d'achat et prix de vente séparés | Coût : `produits.cmup_ht` (4 décimales) et `lignes_achat.prix_unitaire_ht` ; vente : `produits.prix_vente_ttc` (référence) et `prix_vente_ht` (conversion). Le barème calcule un prix de vente depuis le coût sans jamais écrire le coût (`ConstructeurLigneAchat.php:40-43`, `:281-288`). | `prix_vente_ht` n'est lu par aucun écran ni aucun calcul : donnée en double (principe P14 du plan TVA). L'écran Produits fait saisir un « Prix d'achat HT » à la création (`ProduitResource.php:243-252`), qui disparaîtra avec le point 18. |
| 2 | Prix de référence conservés, jamais réutilisés pour une vente faite | Chaque ligne fige produit, PU HT, PU TTC, taux, remise et montants (`ConstructeurLigneDocument.php:13-18`, `:203-219`) ; un avoir repart de la ligne vendue, jamais de la fiche (`CreerAvoirAction.php:147-160`, `LigneFacture.php:172-186`). Seul un brouillon rouvert relit le taux de la fiche (`AjouteDesLignesDeVente.php:165-180`), ce qui est voulu. | Aucun. |
| 3 | Montants calculés et enregistrés à la validation, arrondi cohérent | Calculés à la création du brouillon (`CreerFactureAction.php:62-77`) et jamais recalculés à l'émission (`EmettreFactureAction` ne rappelle pas le constructeur ; un brouillon dont le régime a changé est refusé, `ContexteDeVente.php:161-166`). Caisse : création et émission dans la même transaction (`EnregistrerVenteCaisseAction.php:75-99`). | **L'arrondi n'est pas cohérent** : deux méthodes selon le client (1.3 A et C). |
| 4 | Calculs décimaux exacts, jamais de flottants | Le calcul se fait en entiers (`CalculateurLigne.php:48-53`, `:664-713`), le cast `decimal:2` de Laravel 13 rend des chaînes exactes (`vendor/.../HasAttributes.php:1542-1549`, BigDecimal arrondi « demi vers le haut »). | Flottants de transport : voir 2.3. Somme SQL sur une colonne décimale : `FactureResource.php:97-98` (`withSum`), `VerificateurIntegrite.php:1158`. Sous SQLite, une colonne `decimal` est rangée en `real` (essai : `typeof(total_ht)` = `real`). |
| 5 | Un document validé, le tableau de bord et la clôture réutilisent les montants enregistrés | Oui partout (2.2). | Aucun. |
| 6 | HT + TVA = TTC | Par ligne (`montants()` `:594-601` ; professionnel : `ConstructeurLigneDocument.php:247` ; achat `:99-102`), donc par taux et par document (sommes séparées, `totaliser()`), par clôture (`deduire()` `:494-501`). Vérifié sur tous les documents de l'essai. | Aucun sur l'égalité. Mais pour un professionnel, le TTC n'est pas le prix payé affiché (1.3 C). |
| 7 | Avoirs : reprendre les montants de la vente | Retour complet d'une ligne = exactement ses montants (essai : 41,67 / 8,33 / 50,00) ; retours partiels par différence de cumul, le dernier soldant la ligne (`CalculateurLigne.php:409-437`, `LigneFacture.php:158-186`) ; montants arrêtés de nouveau sous verrou à l'émission (`EmettreAvoirAction.php:166-187`). | Deux méthodes de retour (particulier et professionnel), comme pour la vente. |
| 8 | Clôture Z = somme des données des ventes et avoirs, sans recalcul | `CloturerCaisseAction.php:134-148` : `deduire(cumuler(ventes), cumuler(avoirs))` ; ventilation depuis les lignes (`ClotureCaisse.php:170-173`, `VentileLaTvaParTaux.php:81-113`). Essai : égalité au centime. | Aucun. |
| 9 | Moyens de paiement dans la clôture, ventes et encaissements distincts, paiements mixtes, remboursements | La clôture Z n'a aucune colonne ni aucun affichage de paiement (`ClotureCaisseResource.php:101-153`). Le relevé personnel a les encaissements par mode (`FermerSaCaisseAction.php:178-195`, `:231-240`), le crédit d'avoir et le reste dû. Un seul mode par vente (`Caisse.php:257-262`, `ReglementComptant.php:22-25`, `EmettreFactureAction.php:270-284`). Modes : virement, chèque, espèces, carte bancaire, prélèvement (`ModePaiement.php`), pas d'« Avoir ». | À construire (3.6). Il n'y a pas de remboursement : « Rien n'est jamais remboursé » (`CLAUDE.md`, « Paiements et avoirs ») ; la clôture montre donc les avoirs émis et le crédit d'avoir utilisé, pas des remboursements. |
| 10 | Vérifier tous les modules | Section 2.2. | — |

### 2.2 Module par module

| Module | Où se calcule le montant | Ce qu'il lit ensuite | Constat |
|---|---|---|---|
| **Achats** | `ConstructeurLigneAchat::enregistrerUneLigne()` (`ConstructeurLigneAchat.php:268-306`) → `CalculateurLigne::montantsDAchat()` (`:93-103`) : HT = quantité × PU HT (remise arrondie une fois), TVA sur le HT **de chaque ligne** ; totaux par `totaliser()` (`ConstructeurLigneAchat.php:150-153`). Écran : même calcul (`AjouteDesLignesDAchat.php:401-413`). | Fiche et liste : colonnes enregistrées. | Conforme à la règle actuelle (« puis la TVA sur ce HT »). L'éditeur a décidé le 12/10 de passer à la TVA par taux répartie par cumul (lot M5c du plan TVA) : pas de lien avec le point 15. |
| **Ventes (factures, devis)** | `ConstructeurLigneDocument::construireToutes()` (`:53-73`), appelé par `CreerFactureAction.php:68`, `ModifierFactureAction.php:109`, `CreerDevisAction.php:49`, `ModifierDevisAction.php:73` ; facture depuis un devis : copie des montants (`CreerFactureDepuisDevisAction.php:15-20`, `:80-82`). | PDF, fiche, contenu signé. | **Source de l'écart** (1.3). |
| **Caisse** | Même constructeur (`EnregistrerVenteCaisseAction.php:92-96` → `CreerFactureAction`) ; écran : `apercu()` ; reste à payer et monnaie : `Caisse.php:521-547` (sommes exactes, `soustraire()`). | Panier : total par le même `apercu()` (`PanierDeCaisse.php:274-285`). | Écran = enregistrement. Un seul mode de paiement. |
| **Factures (PDF, fiche)** | — | `resources/views/documents/pdf/lignes.blade.php:63-79` et `totaux.blade.php:23-46` : colonnes enregistrées ; ventilation par `VentileLaTvaParTaux` (somme des lignes). | Aucun recalcul. |
| **Avoirs** | `CreerAvoirAction.php:147-160` → `LigneFacture::montantsDuRetour()` (`:172-186`), arrêté de nouveau à l'émission (`EmettreAvoirAction.php:166-187`). | PDF : mêmes vues, signe moins. | Reprend la ligne vendue (2.1, ex. 7). |
| **Stocks** | Valeur au coût moyen : `CalculateurLigne::valeurAuCoutMoyen()` (`:276-288`), CMUP au dix-millième (`:347-407`). | Widget `ValeurStockApercu.php:54-78`. | Exact. Remarque : le titre du widget donne la valeur du stock **TTC** ; un stock se valorise HT en comptabilité (la TVA d'un achat se récupère) : à présenter en HT (point mineur, sans lien avec le centime). |
| **Tableau de bord** | `ChiffreDAffaires::netSurPeriode()` (`ChiffreDAffaires.php:41-62`) : cumul des totaux enregistrés, moins les avoirs. | Widget `FacturesApercu.php:53-64`. | Exact (essai). |
| **Clôtures Z** | `CloturerCaisseAction.php:134-148`. | Fiche `ClotureCaisseResource.php:101-153`. | Exact ; aucun mode de paiement. |
| **Fermer ma caisse** | `FermerSaCaisseAction.php:178-195` : cumul des ventes, encaissements par mode en centimes, crédit d'avoir, reste dû. | — | Exact. |
| **E-reporting** | `GenererSoumissionEReportingAction.php:74-77` : cumul des clôtures. | — | Exact. |
| **Contenu signé** | `ContenuFiscal.php:280-334` : montants enregistrés convertis en centimes (`enCentiemes()`), ventilation = somme des lignes signées, « rien n'est recalculé ici ». | Vérificateur d'intégrité. | Exact. |
| **Paiements** | `Facture::montantPaye()`, `montantRestant()` (`Facture.php:415-461`), `Avoir::montantNonAlloue()` (`Avoir.php:242-266`), `Client::soldeCrediteur()` (`Client.php:257-268`) : sommes en centimes par `sommer()` / `soustraire()`. | Liste des factures : `withSum` SQL (`FactureResource.php:97-98`), resommé par `sommer()` (`:629`). | Exact ; la somme SQL est à remplacer (2.3). |

### 2.3 Où des flottants PHP interviennent encore

Le calcul lui-même est en entiers : `versUnites()` convertit l'entrée avec un pré-arrondi à 6 décimales (`CalculateurLigne.php:684-693`), puis tout se fait par `multiplier()` et `diviserArrondi()` (`:664-708`). Les flottants restent comme **type de transport** :

- l'API publique de `CalculateurLigne` : paramètres `float` et retours `float` (`centimesVersMontant()` rend `centimes / 100`, `:710-713`) — `montantsDeVente`, `montantsDAchat`, `montantHtDeLigneProfessionnelle`, `repartirLaTva`, `prixUnitaireHtDepuisTtc`, `prixTtcArrondi`, `totaliser`, `sommer`, `soustraire`… ;
- les appelants qui convertissent une chaîne en `float` avant l'appel : `ConstructeurLigneDocument.php:179-201` (taux, PU TTC, remise), clé de taux par `number_format()` d'un flottant (`:239`), `ConstructeurLigneAchat.php:272-279`, `:186`, `:313-316`, `LigneFacture.php:183`, `Produit.php:383-396`, `VentileLaTvaParTaux.php:86-88` (`-(float)`), `ClotureCaisseResource.php:151-153` ;
- le barème : paliers et bénéfices en `float`, addition `prixAchatHt + benefice` en flottant (`BaremePrixDeVente.php:26-31`, `:129-130`), rattrapée par le pré-arrondi au dix-millième ;
- les écrans : `Caisse.php:521-569` (`min()` entre flottants), `SaisitLesPrixDeLigne.php:48-51`, les vues PDF (`number_format((float) …)`) ;
- la base SQLite de développement : colonnes `decimal` rangées en `real` (essai) ; deux sommes SQL sur une colonne décimale (`FactureResource.php:97-98`, `VerificateurIntegrite.php:1158`), contraires à la règle 0.3.7 du plan TVA.

Aucune erreur n'a été observée (l'essai, et 20 000 paniers simulés comparés au calcul en entiers), parce que chaque entrée est ramenée au centime exact avant le calcul. Mais l'exigence 4 de l'éditeur est formelle : la correction doit passer l'API en centimes entiers (ou en chaînes décimales exactes, avec `enCentiemes()` / `enDecimal()` qui existent déjà, `:551-589`), et remplacer les deux sommes SQL par des sommes PHP en centimes. Le paquet `brick/math` est présent comme dépendance de Laravel, mais l'utiliser directement serait une dépendance nouvelle à déclarer, soumise à l'accord de l'éditeur ; les entiers suffisent.

---

## 3. Proposition de correction à la source

### 3.1 Une politique d'arrondi unique (texte proposé pour `CLAUDE.md`, à valider par l'éditeur)

À la place des phrases « Une vente à un particulier se calcule depuis le prix TTC, article par article (PU HT et PU TTC au centime, multipliés par la quantité). Une vente à un professionnel : HT de la ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA de chaque taux = total HT du taux × taux, arrondie une fois et répartie sur ses lignes par cumul, dans leur ordre : chaque ligne reçoit la TVA arrondie du cumul des HT jusqu'à elle, moins celle des lignes précédentes. Un retour partiel se recalcule comme la facture, par différence de cumul, le dernier retour soldant la ligne au centime. » :

> Toute vente, à un particulier comme à un professionnel, ticket, facture ou devis, se calcule une seule fois, à l'enregistrement, depuis le prix TTC payé : le TTC de chaque ligne est quantité × PU TTC, remise déduite, la remise arrondie une fois au centime ; pour chaque taux, le HT est le TTC de ses lignes ÷ (1 + taux), arrondi une fois au centime le plus proche (un demi-centime exact s'arrondit au centime supérieur), et se répartit sur ses lignes par cumul, dans leur ordre : chaque ligne reçoit le HT arrondi du cumul des TTC jusqu'à elle, moins celui des lignes précédentes ; la TVA de chaque ligne et de chaque taux est son TTC moins son HT. Le PU HT imprimé n'est qu'une indication (PU TTC ÷ (1 + taux), au centime). 50,00 € TTC à 20 % font toujours 41,67 € HT et 8,33 € de TVA, en une ligne, en deux lignes de 25,00 € ou en 2 × 25,00 €. Un retour rend, pour tous les articles rendus jusqu'ici, le même calcul sur cette quantité, sans jamais dépasser le HT ni la TVA de la ligne, moins ce que les retours précédents ont rendu ; le retour de toute la ligne rend exactement ses montants.

Le reste de la phrase ne change pas (vente exonérée au prix hors taxe « calculé de la même façon », franchise à 0 % au même prix TTC, achats depuis le PU HT, « Ensuite plus rien ne s'arrondit ni ne se recalcule »).

**Deux décisions à demander à l'éditeur (règle 4 du protocole) :**

- **Q15-a. Les professionnels aussi ?** Recommandé : oui. Le client professionnel paie exactement les prix affichés (aujourd'hui : 10,00 € pour un article à 9,99 €, 49,99 € pour deux lignes de 25,00 €), et un même panier a les mêmes montants pour tous (exigence de l'éditeur : « les mêmes opérations donnent les mêmes montants partout »). Contrepartie, vérifiée sur la source officielle : la TVA d'un taux peut différer d'**un centime** de « HT × taux » (exemple : 3 × 0,99 € = 2,97 € TTC → 2,48 € HT + 0,49 € de TVA, quand 2,48 × 20 % = 0,496 → 0,50). Les règles européennes de la facture électronique l'acceptent : dans les schematrons officiels EN 16931 (dépôt ConnectingEurope/eInvoicing-EN16931, branche master, fichiers `ubl/schematron/UBL/EN16931-UBL-model.sch` et `cii/schematron/CII/EN16931-CII-model.sch`, consultés le 17/10/2026), BR-CO-17 et BR-S-09 tolèrent un écart de 1 unité monétaire (UBL : « TaxAmount − 1 < round(TaxableAmount × Percent ÷ 100) < TaxAmount + 1 » ; CII : même test avec ≤ et ≥ ; BR-S-08, base de chaque taux = somme des HT de ligne, a la même tolérance), tandis que BR-CO-10 (somme des HT de ligne = total HT) et BR-CO-15 (TTC = HT + TVA) sont strictes — la politique proposée les respecte par construction. **À vérifier** : les règles françaises propres (AFNOR XP Z12-012) et les contrôles de SUPER PDP, sur son bac à sable, à l'étape 2. Fréquence de l'écart d'un centime pour une ligne seule (simulation, TTC de 0,01 € à 10 000 €) : 16,7 % des montants à 20 %, 9,1 % à 10 %, 5,2 % à 5,5 %. Option B, si l'éditeur préfère une TVA toujours égale à « HT × taux » arrondi : garder le calcul actuel des professionnels, en calculant au moins leur HT sur le TTC de chaque taux (deux lignes de 25,00 € → 50,00 €) ; un professionnel paierait encore 10,00 € un article à 9,99 €.
- **Q15-b. Le demi-centime exact.** À 20 %, un TTC dont le nombre de centimes vaut 3 modulo 6 (0,03 ; 2,97 ; 1,05…) tombe pile sur un demi-centime de HT (2,97 ÷ 1,2 = 2,475). Recommandé : arrondir le HT au centime supérieur (2,48 € HT, 0,49 € de TVA), comme le fait déjà `prixUnitaireHtDepuisTtc()` et `diviserArrondi()` (« demi vers l'extérieur », `:695-708`). L'autre choix (arrondir la TVA : 0,50 € de TVA, 2,47 € HT) n'est pas plus juste ; il faut seulement n'en garder qu'un.

Effet à montrer à l'éditeur : comme la TVA aujourd'hui pour un professionnel (R4), le centime d'arrondi se répartit dans l'ordre des lignes ; trois lignes identiques à 0,10 € reçoivent 0,08, 0,09 et 0,08 € HT (total 0,25 € HT + 0,05 € de TVA = 0,30 €), et deux lignes de 25,00 € reçoivent 20,83 et 20,84 € HT.

Ce n'est pas une règle comptable à faire valider par l'expert-comptable : c'est une convention de calcul d'un prix TTC, que l'éditeur fixe ; elle se cite dans la notice.

### 3.2 Une formule, un endroit

| Avant | Après |
|---|---|
| `CalculateurLigne::montantsDeVente()` (`:73-81`) pour les particuliers et la franchise ; `montantHtDeLigneProfessionnelle()` (`:117-127`) + `repartirLaTva()` (`:148-165`) pour les professionnels ; deux branches dans `ConstructeurLigneDocument` (`:72`, `:106-114`, `:216-218`, `:234-252`) | Une méthode `CalculateurLigne::montantsDUneVente(list<ligne>)` en centimes (`montantsDepuisLeTtc()` dans le prototype de 3.8) : TTC de chaque ligne, HT de chaque taux depuis le TTC, réparti par cumul ; une seule méthode `ConstructeurLigneDocument::chiffrer()` appelée par `construireToutes()` et `apercu()`. |
| Retours : `retourDeLigneProfessionnelle()` (`:191-217`) ou `montantsDeVente()` selon le document (`LigneFacture.php:181-183`) | Une méthode `CalculateurLigne::retourDeLigne(ligne enregistrée, quantité rendue)` : le calcul de la vente sur la quantité rendue, encadré par le HT et la TVA de la ligne ; `retourCumule()` (`:428-437`) inchangé. |
| `ContexteDeVente::calculeDepuisLeHtDeLigne()` et `documentCalculeDepuisLeHtDeLigne()` (`ContexteDeVente.php:179-194`) ; note de calcul réservée aux professionnels (`MentionsFiscales.php:47-51`, `:124-133`) ; « Total HT / TVA calculée sur le total » pour un professionnel à l'écran (`AjouteDesLignesDeVente.php:352-373`) | Supprimés : une seule note de calcul pour tout document (« HT de chaque taux = TTC du taux ÷ (1 + taux), arrondi une fois ; le PU HT est indicatif »), un seul affichage de ligne (son TTC, son HT et sa TVA). |
| `prixUnitaireHtDepuisTtc()` pour le PU HT de la ligne | Gardé, pour le seul PU HT indicatif. |
| `repartirLaTva()` | Gardé seulement si le lot M5c des achats l'emploie (TVA d'un achat par taux, répartie par cumul, décision du 12/10) ; sinon supprimé. |
| `produits.prix_vente_ht` (jamais lu) | Supprimé de la migration de création des produits, avec son calcul dans `Produit::synchroniserPrixDeVente()` ; l'écran affiche le HT indicatif calculé (`ProduitResource::detailPrixDeVente()`, `:188-207`, existe déjà). |

Le moteur de TVA du plan (lot M2) reprendra cette méthode telle quelle (`Calcul/CalculDesLignesDeVente`, `Calcul/CalculDesRetours`) : son principe P9 (« la calculatrice passe dans le domaine sans changer ses règles ») s'appliquera à la nouvelle règle.

Pourquoi ce retour encadré, plutôt qu'un simple prorata des montants de la ligne : le prorata peut rendre une TVA négative. Essai exécuté (`serie8/simulation/cas-prorata.php` et calcul direct) : 10 articles à 0,02 € remisés de 25 % font une ligne de 0,15 € TTC = 0,13 € HT + 0,02 € de TVA ; au prorata, le 2e retour rendrait 0,02 € HT et **−0,01 €** de TVA. Le calcul « comme la vente, encadré par la ligne » rend sur ce cas 0,01 / 0 / 0,01, puis 0,02 / 0 / 0,02, puis 0 / 0,01 / 0,01…, jamais rien de négatif, et la somme des dix retours retombe sur 0,13 / 0,02 / 0,15. Sur les 20 000 paniers de la simulation, chaque ligne rendue article par article : aucun montant négatif, aucune somme différente de la ligne.

### 3.3 Sans flottant

Dans le même lot, pour les méthodes touchées : entrées en chaînes décimales exactes ou en entiers (centimes, centièmes de point), sorties en centimes ; les appelants convertissent avec `enCentiemes()` / `enDecimal()`. Le plan TVA prévoit l'« API en centimes » au lot M2 (`plan-execution.md`, 3.2 et 3.14) : la faire ici pour les méthodes de vente, la généraliser au lot M2. Remplacer les sommes SQL de `FactureResource.php:97-98` et `VerificateurIntegrite.php:1158` par des sommes en centimes côté PHP (règle 0.3.7 du plan TVA).

### 3.4 Montants enregistrés, aucune modification rétroactive

- Rien ne change dans le stockage : chaque ligne et chaque document enregistrent déjà leurs montants à la création ; l'émission ne recalcule pas ; les avoirs repartent de la ligne enregistrée. La nouvelle méthode ne s'applique qu'aux documents créés après elle.
- Aucune donnée réelle n'existe (règle « toujours repartir de zéro ») : pas de code de compatibilité, pas de reprise ; `php artisan migrate:fresh` après la suppression de `prix_vente_ht`.
- Une fois en production, un changement de règle ne doit jamais retoucher un document émis : le plan TVA fige déjà la version des règles et la méthode de calcul dans la décision de chaque document (principes P10 et P11, colonne `decision_tva`) ; les retours d'un document relisent la méthode figée. C'est la « correction traçable » demandée : un document faux se corrige par un avoir et une nouvelle facture, jamais par une réécriture.

### 3.5 Exemples chiffrés de la politique proposée (simulation exécutée)

Sortie de `serie8/simulation/resultat.txt` (HT / TVA / TTC) :

| Panier | Particulier aujourd'hui | Professionnel aujourd'hui | Proposé (les deux) |
|---|---|---|---|
| 50,00 × 1 | 41,67 / 8,33 / 50,00 | 41,67 / 8,33 / 50,00 | 41,67 / 8,33 / 50,00 |
| 25,00 × 2 | 41,66 / 8,34 / 50,00 | 41,67 / 8,33 / 50,00 | 41,67 / 8,33 / 50,00 |
| 25,00 + 25,00 (deux lignes) | 41,66 / 8,34 / 50,00 | 41,66 / 8,33 / 49,99 | 41,67 / 8,33 / 50,00 (lignes 20,83 + 4,17 et 20,84 + 4,16) |
| 17,50 × 2 | 29,16 / 5,84 / 35,00 | 29,17 / 5,83 / 35,00 | 29,17 / 5,83 / 35,00 |
| 9,99 × 1 | 8,33 / 1,66 / 9,99 | 8,33 / 1,67 / 10,00 | 8,33 / 1,66 / 9,99 |
| 0,99 × 3 | 2,49 / 0,48 / 2,97 | 2,48 / 0,50 / 2,98 | 2,48 / 0,49 / 2,97 |
| 40,00 × 2 (V01 et V04 du plan TVA) | 66,66 / 13,34 / 80,00 | 66,67 / 13,33 / 80,00 | 66,67 / 13,33 / 80,00 |
| 9,99 × 3 remisé de 15 % | 21,24 / 4,23 / 25,47 | 21,23 / 4,25 / 25,48 | 21,23 / 4,24 / 25,47 |
| 1,00 (20 %) + 2 × 4,50 (5,5 %) + 2 × 2,00 (20 %) (V03) | 12,71 / 1,29 / 14,00 | 12,69 / 1,30 / 13,99 | 12,70 / 1,30 / 14,00 (lignes 0,83 + 0,17 ; 8,53 + 0,47 ; 3,34 + 0,66) |
| 1 000 × 1,00 (V02) | 830,00 / 170,00 / 1 000,00 | 833,33 / 166,67 / 1 000,00 | 833,33 / 166,67 / 1 000,00 |
| 3 × 0,10 sur trois lignes | 0,24 / 0,06 / 0,30 | 0,24 / 0,05 / 0,29 | 0,25 / 0,05 / 0,30 |
| 3 × 12,90 (10 %) + 2 × 3,99 (5,5 %) + 19,99 (20 %) | 59,41 / 7,26 / 66,67 | 59,40 / 7,27 / 66,67 | 59,40 / 7,27 / 66,67 |

Retours proposés : 2 × 40,00 € rendus un par un : 33,33 + 6,67 = 40,00 puis 33,34 + 6,66 = 40,00 ; 3 × 0,99 € : 0,83 + 0,16, 0,82 + 0,17, 0,83 + 0,16 (somme 2,48 + 0,49 = 2,97) ; 3 × 9,99 € remisés de 15 %, 1 puis 2 : 7,08 + 1,41 = 8,49 puis 14,15 + 2,83 = 16,98 (somme = la ligne).

Sur 20 000 paniers tirés au hasard (1 à 8 lignes, 5 taux, remises), la politique proposée tient tous les invariants : HT + TVA = TTC par ligne, par taux et au total ; TTC = somme des prix payés ; HT du taux = TTC du taux ÷ (1 + taux) arrondi une fois ; retours jamais négatifs, le dernier soldant la ligne ; écart maximal à « HT × taux » : 1 centime.

### 3.6 Clôture Z, modes de paiement, paiements mixtes (exigence 9, points 1, 23, 24)

Proposition, à faire valider par l'éditeur avec le reste de la série 8 :

- La clôture Z garde ses totaux de **ventes** (tickets moins avoirs sur tickets, inchangés) et ajoute un bloc **encaissements de la journée**, par mode (espèces, carte bancaire, chèque, virement, prélèvement), contre-passations déduites, calculé en centimes comme le relevé personnel (`FermerSaCaisseAction::encaissementsParMode()`, à déplacer dans un service commun), plus le **crédit d'avoir utilisé** (imputations d'avoirs et de bons d'avoir du jour) et le nombre et le total des **avoirs émis**. Les encaissements sont enregistrés avec la clôture (colonne JSON comme `clotures_caisse_personnelles.encaissements`, montants en chaînes exactes), jamais recalculés à l'affichage. À trancher : la clôture compte-t-elle aussi les encaissements de factures à des professionnels reçus en caisse ce jour-là ? Recommandé : oui, dans le bloc des encaissements (c'est l'argent de la caisse), séparé du total des ventes qui ne compte que les tickets. Les numéros de tickets et d'avoirs deviennent des liens (point 25).
- « Avoir » (point 1) n'est pas un moyen de paiement mais une imputation de crédit (`AvoirAllocation`, distincte de `PaiementAllocation`) : il s'affiche comme un choix dans la caisse et les factures, mais s'enregistre comme aujourd'hui, et la clôture le montre à part (« crédit d'avoir utilisé »), sans le mêler aux encaissements déclarés.
- Paiements mixtes (point 23) : `ReglementComptant` devient une liste de parts (mode, montant, référence), dont la somme plus le crédit d'avoir égale le TTC (règle « le montant d'un paiement est égal au total imputé ») ; un paiement par part, chacun dans la même transaction et le même ticket signé.
- Pas de « remboursements » : l'éditeur a décidé que rien n'est jamais remboursé ; la clôture le dit par les avoirs et le crédit utilisé.

Ces points ne touchent pas au calcul des montants de vente : ils peuvent faire un lot à part, après la correction de l'arrondi.

### 3.7 Tests exigés par l'éditeur

Montants écrits en dur, recalculés à la main selon 3.1 (HT arrondi au demi-centime supérieur, à confirmer par Q15-b). Fichiers créés par `php artisan make:test --phpunit` (ou `--unit`), conventions du dépôt.

**Calcul pur — `tests/Unit/CalculateurLigneTest.php` (déplacé en `tests/Unit/Tva/CalculateurLigneTest.php` au lot M1)**
- `test_cinquante_euros_ttc_a_vingt_pour_cent_font_41_67_ht_et_8_33_de_tva` : 1 × 50,00 → 41,67 / 8,33 / 50,00 (cas obligatoire de l'éditeur).
- `test_le_meme_total_donne_les_memes_montants_quel_que_soit_le_decoupage` : 1 × 50,00 ; 2 × 25,00 ; 25,00 + 25,00 → 41,67 / 8,33 / 50,00 chacun ; lignes du dernier : 20,83 + 4,17 et 20,84 + 4,16.
- `test_trente_cinq_euros_en_deux_articles_font_5_83_de_tva` : 2 × 17,50 → 29,17 / 5,83 / 35,00.
- `test_le_ttc_paye_est_toujours_la_somme_des_prix` : 9,99 → 8,33 / 1,66 / 9,99 ; 3 × 0,99 → 2,48 / 0,49 / 2,97 ; 3 × 0,10 sur trois lignes → 0,25 / 0,05 / 0,30.
- `test_plusieurs_taux_se_calculent_chacun_sur_son_ttc` : V03 → 12,70 / 1,30 / 14,00, lignes 0,83 + 0,17 ; 8,53 + 0,47 ; 3,34 + 0,66 ; et 3 × 12,90 (10 %) + 2 × 3,99 (5,5 %) + 19,99 (20 %) → 59,40 / 7,27 / 66,67.
- `test_une_remise_s_arrondit_une_fois_sur_le_ttc_de_la_ligne` : 50,00 remisé de 10 % → 37,50 / 7,50 / 45,00 ; 3 × 9,99 remisé de 15 % → 21,23 / 4,24 / 25,47.
- `test_un_demi_centime_exact_arrondit_le_ht_au_centime_superieur` : 2,97 € TTC à 20 % → 2,48 / 0,49.
- `test_les_retours_partiels_rendent_le_calcul_de_la_vente_encadre_par_la_ligne` : 2 × 40,00 → 33,33 + 6,67 puis 33,34 + 6,66 ; 3 × 0,99 → 0,83 + 0,16, 0,82 + 0,17, 0,83 + 0,16 ; 3 × 9,99 remisé de 15 %, 1 puis 2 → 7,08 + 1,41 puis 14,15 + 2,83.
- `test_aucun_retour_ne_rend_un_montant_negatif` : 10 articles à 0,02 € remisés de 25 % (ligne 0,13 / 0,02 / 0,15), rendus un par un : aucun HT ni aucune TVA négatifs (le prorata simple rendrait −0,01 € de TVA au 2e retour), somme = la ligne.
- `test_le_calcul_n_accepte_pas_de_flottant` : un flottant en entrée est refusé, comme `enCentiemes()` le fait déjà.

**Par les actions — `tests/Feature/Facturation/PolitiqueDArrondiTest.php` (nouveau)**
- `test_un_ticket_de_cinquante_euros_porte_41_67_ht_partout` : vente à la caisse de 2 × 25,00 € → ticket enregistré, contenu signé (centimes 4 167 / 833 / 5 000), PDF, fiche, clôture Z du jour, tableau de bord : 41,67 / 8,33 / 50,00 partout.
- `test_le_meme_panier_donne_les_memes_montants_a_un_particulier_et_a_un_professionnel` : 25,00 + 25,00 et 9,99 vendus en ticket et en facture professionnelle → mêmes montants de ligne, de taux et de document.
- `test_une_facture_professionnelle_n_ajoute_jamais_un_centime_au_prix` : 9,99 € → 9,99 € TTC (aujourd'hui 10,00 €).
- `test_un_retour_complet_reprend_exactement_les_montants_de_la_vente` : ticket et facture, plusieurs lignes et deux taux.
- `test_les_retours_partiels_soldent_la_ligne_au_centime` : 3 × 0,99 € rendus un par un, ticket et facture.
- `test_la_cloture_additionne_les_tickets_et_les_avoirs_enregistres` : 25,00 × 2, 17,50 × 2, livre à 5,5 %, avoir partiel → Z = somme au centime, ventilation par taux = somme des lignes ; la TVA du Z n'est pas « TTC du jour ÷ 1,2 ».
- `test_hors_tva_et_franchise_gardent_leur_calcul` : export d'un particulier, 2 × 40,00 € → 66,67 € payés sans TVA (aujourd'hui 66,66 €) ; franchise, 2 × 40,00 € → 80,00 / 0,00 / 80,00.

**Écrans — `tests/Feature/Filament/CaisseTest.php`**
- `test_l_ecran_de_la_caisse_affiche_les_montants_qui_seront_enregistres` : 2 × 25,00 € → la ligne et les totaux affichent 41,67 € HT + 8,33 € de TVA, puis le ticket enregistré porte les mêmes.

**Concurrence — `tests/Feature/Facturation/EnregistrerVenteCaisseActionConcurrenceTest.php`** (PostgreSQL, `exigerUnServeurDeBaseDeDonnees()`, groupe `concurrence`)
- `test_deux_caisses_qui_vendent_le_meme_panier_au_meme_instant_enregistrent_les_memes_montants` : deux processus, même panier (25,00 + 25,00 et un livre à 5,5 %) → deux tickets aux montants identiques au centime, numéros distincts, clôture = deux fois le ticket.

**Clôture et paiements (lot 3.6)** — `tests/Feature/Facturation/CloturerCaisseActionTest.php` : `test_la_cloture_detaille_les_encaissements_par_mode` (espèces, carte, paiement mixte, contre-passation déduite), `test_le_credit_d_avoir_utilise_est_a_part_des_encaissements` ; `tests/Feature/Filament/ClotureCaisseResourceTest.php` : `test_la_fiche_de_la_cloture_affiche_les_modes_de_paiement`.

**Sommes sans SQL** — `tests/Feature/Filament/FactureResourceTest.php` : `test_le_paye_de_la_liste_se_somme_en_centimes` (la colonne « Payé » sans `withSum`).

### 3.8 Prototype : ce que la correction change dans la suite actuelle

Pour mesurer l'effet de la correction, elle a été écrite dans une **copie séparée** de la copie de travail (`serie8/prototype/`, jamais le dépôt), réduite à l'essentiel : `CalculateurLigne::montantsDepuisLeTtc()` et `retourDeLigne()` ajoutées, `ConstructeurLigneDocument::chiffrer()` à la place des deux branches, `LigneFacture::montantsDuRetour()` sur `retourDeLigne()` (fichiers dans `serie8/modifs/`). Les écrans, la note de calcul, la migration et l'API en centimes n'y sont pas.

**Suite complète sur SQLite** (`php artisan test --parallel --processes=4`), avant : 3 466 tests, 3 434 réussis, 32 ignorés (ceux qui exigent PostgreSQL), code de sortie 0 (`serie8/suite-avant.txt`) ; après : **30 échecs**, tous des montants écrits selon l'ancienne règle, aucune erreur ni exception (`serie8/suite-apres.txt`). Les 32 tests PostgreSQL n'ont pas été lancés (consigne : pas de PostgreSQL) : à faire dans le lot.

| Fichier | Tests qui échouent | Ancien → nouveau (HT / TVA / TTC) |
|---|---|---|
| `tests/Feature/Facturation/CreerFactureActionTest.php` | `test_une_vente_part_toujours_du_pu_ttc` ; `test_une_vente_a_un_professionnel_se_calcule_depuis_le_ht_de_chaque_ligne` (jeux « particulier, 1 000 x 1,00 € », « professionnel, trois lignes de 0,10 € », « particulier, trois lignes de 0,10 € », « particulier, 2 x 49,90 € à 20 % et 3 x 4,20 € à 5,5 % », « particulier, 7 x 3,99 € à 5,5 % remisés de 10 % ») ; `test_la_tva_de_chaque_taux_d_une_facture_professionnelle_est_celle_de_son_total_ht` | 66,66 / 13,34 → 66,67 / 13,33 ; 830,00 / 170,00 → 833,33 / 166,67 ; ligne 0,08 + 0,01 = 0,09 → 0,09 + 0,01 = 0,10 ; 83,16 / 16,64 → 83,17 / 16,63 ; 23,81 / 1,33 → 23,83 / 1,31 ; total du taux 0,24 / 0,05 / 0,29 → 0,25 / 0,05 / 0,30 |
| `tests/Feature/Facturation/AvoirActionsTest.php` | `test_le_dernier_avoir_emis_sur_une_ligne_en_credite_le_solde_exact_meme_saisi_avant` ; `test_le_dernier_retour_dune_ligne_ttc_en_credite_le_solde_exact` ; `test_le_dernier_retour_dune_ligne_remisee_en_credite_le_solde_exact` ; `test_le_retour_d_une_ligne_professionnelle_credite_sa_part_de_la_tva_de_son_taux` | 29,99 / 6,01 → 30,00 / 6,00 ; 33,33 / 6,67 → 33,34 / 6,66 (dernier retour, la ligne valant désormais 66,67 / 13,33) ; 59,99 / 12,01 → 60,00 / 12,00 ; 0,08 / 0,01 / 0,09 → 0,09 / 0,01 / 0,10 |
| `tests/Feature/Facturation/RepartitionTvaParCumulTest.php` | les trois tests | ligne 3,33 + 0,66 = 3,99 → 3,34 + 0,66 = 4,00 (contenu signé : 333 / 66 / 399 → 334 / 66 / 400) ; retour 1,66 / 0,33 / 1,99 → 1,67 / 0,33 / 2,00 |
| `tests/Feature/Facturation/NatureOperationTest.php` | `test_un_export_a_un_particulier_se_paie_0_83_euro_l_article` ; `test_une_livraison_vers_un_dom_se_paie_au_prix_hors_taxe_avec_sa_mention` ; `test_l_avoir_d_un_export_a_un_particulier_se_calcule_a_son_pu_ht` | 830,00 → 833,33 ; 124,74 → 124,75 ; 415,00 → 416,67 |
| `tests/Feature/Facturation/DevisConversionTest.php` | `test_un_devis_dont_le_client_a_change_de_type_ne_part_qu_une_fois_reenregistre` | 830,00 / 170,00 → 833,33 / 166,67 : le même devis ne change plus de montants quand le client change de type |
| `tests/Feature/Facturation/ConstructeurLigneDocumentTest.php` | `test_l_apercu_d_une_vente_professionnelle_repartit_la_tva_entre_les_lignes_completes` | ligne 0,08 / 0,01 / 0,09 → 0,09 / 0,01 / 0,10 |
| `tests/Feature/Facturation/AvoirPdfGeneratorTest.php` | `test_lavoir_sur_une_facture_professionnelle_explique_le_calcul_de_ses_lignes` | 0,08 / 0,01 / 0,09 → 0,09 / 0,01 / 0,10 |
| `tests/Feature/Filament/CaisseTest.php` | `test_la_caisse_montre_le_ht_et_la_tva_de_chaque_ligne_et_les_totaux` ; `test_une_vente_avec_un_service_enregistre_sa_ligne_depuis_le_ttc_saisi` | « 66,66 € HT + 13,34 € de TVA » → 66,67 + 13,33 ; « 12,53 € HT + 2,50 € de TVA » → nouveau montant de la ligne |
| `tests/Feature/Filament/FactureResourceTest.php` | `test_la_facture_du_magasin_totalise_84_99_ht_et_le_montre_ligne_par_ligne` ; `test_le_pu_ttc_saisi_est_garde_tel_quel` | 84,99 / 15,01 → 85,00 / 15,00 (le nom du test change) ; 25,14 / 5,01 → 25,13 / 5,02 (30,15 € TTC, demi-centime exact : Q15-b) |
| `tests/Feature/Filament/DevisResourceTest.php` | `test_creer_un_devis_de_livraison_vers_un_dom_via_le_formulaire` ; `test_la_fiche_du_devis_montre_chaque_ligne_et_la_tva_par_taux` | 124,74 → 124,75 ; 76,66 / 13,34 → 76,67 / 13,33 |
| `tests/Feature/Filament/ClotureCaisseResourceTest.php` | `test_la_cloture_ventile_ses_ventes_par_taux_de_tva` | 76,66 / 13,34 → 76,67 / 13,33 |
| `tests/Feature/Filament/FacturesApercuWidgetTest.php` | `test_le_chiffre_d_affaires_du_magasin_est_net_des_avoirs` ; `test_l_avoir_du_proprietaire_sur_la_vente_d_un_vendeur_reduit_les_ventes_du_vendeur` | « 133,33 € HT » et « 33,33 € HT » : nouveaux montants nets |
| `tests/Feature/Filament/ProduitResourceTest.php` | `test_un_produit_a_40_euros_ttc_vendu_par_2_totalise_80_euros_a_lecran_et_en_base` | 66,66 → 66,67 |

Chacun de ces tests fixe la règle que l'éditeur demande de changer : dans le lot, il se réécrit avec les nouveaux montants recalculés à la main, en nommant la décision dans le message de commit (règle 9 du plan TVA ; protocole, règle 4 : jamais pour faire passer un test, seulement parce que la règle change par décision). Les tests unitaires de `tests/Unit/CalculateurLigneTest.php` qui visent `montantsDeVente()`, `montantHtDeLigneProfessionnelle()` et `retourDeLigneProfessionnelle()` passent encore dans le prototype parce que ces méthodes y restent ; ils suivront leur suppression.

**Le parcours de reproduction rejoué sur le prototype** (`serie8/reproduction-sortie-prototype.txt`) : 2 × 25,00 € → 41,67 / 8,33 / 50,00 à l'écran, en base et sur le PDF ; 2 × 17,50 € → 29,17 / 5,83 / 35,00 ; deux lignes de 25,00 € → 20,83 + 4,17 et 20,84 + 4,16, total 41,67 / 8,33 / 50,00 ; professionnel 9,99 € → 8,33 / 1,66 / 9,99 ; professionnel 3 × 0,99 € → 2,48 / 0,49 / 2,97 ; retours d'un article de « 25,00 × 2 » : 20,83 + 4,17 puis 20,84 + 4,16 ; retour complet du ticket de 50 € : 41,67 / 8,33 / 50,00. Les TTC de la journée ne bougent pas (relevé 715,92 ; clôture 372,95 ; tableau de bord 555,92) : seuls le HT et la TVA se corrigent (clôture 311,96 / 60,99 → 311,97 / 60,98 ; tableau de bord 465,59 / 90,33 → 465,61 / 90,31), et chaque total reste la somme exacte des documents.

### 3.9 Place dans la chaîne et dans le plan du moteur de TVA

**Ce que les lots M1 et M2 couvrent déjà :**
- un seul endroit pour les calculs (`CalculateurLigne` déplacée dans `app/Domain/Tva/Calcul`, M1) et un seul moteur appelé par tous les écrans, la caisse et l'API (P1, M2) : l'exigence « formule unique, endroit unique » ;
- l'API en centimes (M2) : l'exigence 4, à généraliser ;
- un document émis relit sa décision figée, jamais le moteur (P10, P11) : l'exigence 5 et la traçabilité de 3.4 ;
- aucune somme SQL sur une colonne décimale (règle 0.3.7) ;
- la comparaison avant et après sans écart (3.23.4), et le test `VentilationTest::test_la_ventilation_signee_et_celle_des_ecrans_donnent_le_meme_resultat` (M1).

**Ce qu'ils ne couvrent pas :** la règle elle-même. M1 et M2 se font « sans changer un seul résultat » (`plan-execution.md`, 5.3 et 5.4), et leurs tests de référence fixent l'écart actuel : V01 (2 câbles à 40,00 € = 66,66 / 13,34 / 80,00), V03 (13,99 € TTC pour 14,00 € de prix), V10 (export à un particulier : 66,66 €) ; V04 (professionnel, 2 câbles, deux avoirs) reste juste avec la nouvelle règle ; l'énumération `MethodeDeCalcul` (`article_par_article`, `ht_de_ligne`, 3.2) perpétue les deux méthodes ; le texte de `CLAUDE.md` prévu au lot M0 (2.7) recopie les phrases à remplacer ; le lot C-b (facture demandée) s'appuie sur « article par article » pour reproduire un ticket. Rien ne couvre non plus les modes de paiement de la clôture ni les paiements mixtes.

**Ce qu'il faut faire avant eux :**
1. Faire trancher Q15-a et Q15-b par l'éditeur.
2. Un lot à part, « R6 : politique d'arrondi unique des ventes », dans la chaîne société : il touche `CalculateurLigne`, `ConstructeurLigneDocument`, `LigneFacture`, `ContexteDeVente`, `MentionsFiscales`, `AjouteDesLignesDeVente`, `Produit` et la migration des produits, plus les tests de 3.7 et 3.8. Les lots A5 et A4b ne touchent pas ces fichiers ; le lot A6 en touche une partie (`AjouteDesLignesDeVente`, `Caisse`, `CreerFactureAction`, R2). Recommandé : R6 juste après la relecture de A4 et le lot A4b, avant A5 et A6, puisque c'est le point le plus urgent de l'éditeur ; A6 se construit ensuite sur la nouvelle méthode (sa vérification des prix dérogatoires ne dépend pas de la façon de chiffrer).
3. Mettre à jour le plan TVA avant M0 : texte 2.7 (nouvelle phrase de 3.1), tableau 0.7 (R4 et E1 remplacés par la nouvelle règle, K2 réécrit avec le retour encadré), V01 → 66,67 / 13,33 / 80,00, V03 → 12,70 / 1,30 / 14,00, V10 → 66,67 €, V04 inchangé (facture 66,67 / 13,33 / 80,00, avoirs 33,33 / 6,67 puis 33,34 / 6,66), `MethodeDeCalcul` réduite (ou supprimée), lot C-b (« même méthode que le ticket »), 3.23.4 (montants de référence recalculés).
4. Le lot 3.6 (clôture, modes de paiement, paiements mixtes, « Avoir ») ensuite, avec les autres points de la série 8 qui touchent la caisse (1, 23, 24, 25).

---

## 4. Recoupements de la série 8 avec les plans (vérifiés dans les documents)

| Point | Ce qui existe déjà dans un plan | Ce qui manque |
|---|---|---|
| 3 (PDF : référence, modes de paiement, colonnes) | — | Si la politique de 3.1 est retenue, le PU HT d'une ligne devient indicatif : garder une note de calcul sur le PDF ; retirer les colonnes « PU TTC » et « Total TTC » des lignes reste possible (les totaux par taux et du document restent). |
| 8 (pièce jointe à une facture d'achat) | Lot CH1 du plan TVA (pièces justificatives, chiffrées, jamais supprimées) | — |
| 11 (mention légale d'une vente à 0 %) | Lots M3 et M4 (natures d'opération, mentions datées imprimées automatiquement) ; réponse de l'éditeur : « Sur la facture, automatique » | — |
| 18 (produit né d'un achat ou d'un inventaire d'ouverture) | Étape 7 de la feuille de route (inventaire d'ouverture) ; plan TVA 3.17 (achats) | Aucune mention de l'interdiction de créer un produit dans « Produits » trouvée dans les deux plans (recherche dans `plan-execution.md` et `conception-v2-finale.md`). |
| 19 (avoir fournisseur saisi à la main) | Lot M5e et section 3.17.6 du plan TVA : le calcul d'un avoir fournisseur (TVA rendue, sortie au coût de la ligne retournée) ; règle C1 | La saisie à la main d'un avoir lié à une ligne d'achat, et l'alerte orange sous la ligne retournée, ne sont décrites dans aucun lot : à ajouter à M5e ou M6. |
| 1, 15 (clôture), 23, 24, 25 | Aucun | Section 3.6. |
| 26 (e-reporting) | Étape 2 (SUPER PDP) | L'explication demandée : totaux journaliers par nature et par taux, issus des clôtures Z (déjà calculés par `GenererSoumissionEReportingAction`). |

---

## 5. Ce qui reste à vérifier

- Les contrôles propres à la France (AFNOR XP Z12-012) et ceux de SUPER PDP sur l'écart d'un centime entre la TVA d'un taux et « HT × taux » : à essayer sur le bac à sable à l'étape 2. La tolérance de 1 unité monétaire n'est vérifiée que dans les schematrons européens officiels.
- Le libellé exact de la note de calcul imprimée et la place des colonnes du PDF : à décider avec le point 3.
- Les encaissements de factures professionnelles dans la clôture Z (3.6) : décision de l'éditeur.
