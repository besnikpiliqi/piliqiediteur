# Méthode HT pour les professionnels : impact sur le code

Lecture seule du dépôt `/home/user/piliqiediteur` au commit `81de7e8`. Les chiffres cités viennent de `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/resultats.md` (programme `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/scripts/calculs_exhaustifs.py`).

## 0. Ce que l'éditeur demande, et ce qui est possible

Le constat de l'éditeur (FAC-PARIS-2026-000001 : 2 × 8,33 € imprimés, total 16,67 €) n'est pas un défaut d'affichage isolé. Avec la règle actuelle, une facture à un professionnel montre au moins un écart que la calculatrice refait dans 7 à 9 cas sur 10 du domaine énuméré (70 % à 93 % selon les domaines, voir resultats.md, section 1). Deux causes :

1. le total HT de la ligne vient du TTC (10,00 × 2 ÷ 1,2 = 16,666… → 16,67 €), alors que le PU HT imprimé est arrondi (8,33 €) : quantité × PU imprimé ≠ total HT ;
2. la TVA est TTC − HT : base × taux, arrondi, ne redonne pas la TVA imprimée pour une part t ÷ (1 + t) des montants (1 sur 6 à 20 %, 1 sur 11 à 10 %) : 9,99 € TTC → base 8,33 €, TVA 1,66 €, quand 8,33 × 20 % = 1,666 → 1,67 €.

Option intermédiaire (ii), imprimer le PU HT à 6 décimales (8,333333 €) sans rien changer au calcul : la facture de l'éditeur ne montre plus d'écart (2 × 8,333333 = 16,666666 → 16,67 €), et les documents à une ligne avec un écart passent de 91 % à 7,4 % ; mais la cause 2 reste entière (9,99 € garde son écart de TVA), deux lignes identiques gardent leurs HT différents (un document sur deux en D4), et un PU HT à 6 décimales est peu lisible. Elle ne répond pas à « pas de centime d'écart ».

La méthode HT (iii) supprime **tous** les écarts visibles d'une facture ou d'un devis à un professionnel, sur tout le domaine énuméré, quand la remise est en euros ou quand l'arrondi d'une remise en % porte sur le net de la ligne (voir 2.4). Il reste trois limites, qu'aucune méthode ne lève, et que l'éditeur doit connaître avant de décider :

- **Professionnel et particulier ne paient plus toujours le même prix pour le même panier** : le même TTC n'est garanti, pour toute quantité, que quand le HT du PU TTC tombe juste (à 20 % un multiple de 0,06 €, soit 1 prix sur 6 ; à 10 % un multiple de 0,11 €). Preuve sur l'exemple de l'éditeur : 2 coques à 10,00 € TTC font 20,00 € TTC pour un particulier. Pour qu'une facture à un professionnel se vérifie, il faut HT = 2 × PU HT (un nombre pair de centimes) et TVA = HT × 20 % arrondie ; or 1 666 + 333 = 1 999 et 1 668 + 334 = 2 002 : aucun PU HT au centime ne donne 20,00 €. Le professionnel paie 19,99 €. De même 9,99 € TTC (8,33 € HT + 1,67 €) lui coûtent 10,00 €, et 4 × 65,00 € TTC lui coûtent 260,02 € (216,68 € + 43,34 €). L'écart grandit avec la quantité : pour 100 articles d'un même PU TTC, de −0,40 € à +0,60 € à 20 %, environ ± 0,50 € aux autres taux (voir resultats.md, section 2) ; le TTC n'est le même que pour 18 % des lignes à 20 % (5 % à 11 % aux autres taux). Le test `test_une_facture_professionnelle_n_ajoute_jamais_un_centime_au_prix` (tests/Feature/Facturation/PolitiqueDArrondiTest.php:326) verrouille l'inverse : c'est une décision de l'éditeur de la série 8 qu'il remet en cause.
- **Les avoirs partiels.** Rendre 1 coque sur 2 puis la seconde : pour que chaque avoir se vérifie, chacun doit rendre 8,33 € + 1,67 € ; ensemble ils rendent 3,34 € de TVA pour 3,33 € facturés. Il faut choisir entre « chaque avoir juste à la calculatrice » (option B, la somme des avoirs peut rendre quelques centimes de TVA de plus ou de moins que la facture) et « la somme des avoirs égale exactement la facture » (option A, environ un avoir partiel sur quatre montre un écart d'un centime). Voir 2.3 et resultats.md, section 5.
- **Les tickets** : un ticket se calcule depuis le TTC ; sa base est toujours le TTC ÷ (1 + taux) arrondi, mais base × taux s'écarte d'un centime de la TVA imprimée pour 1 TTC sur 6 à 20 %. Aucun calcul qui part du TTC ne l'évite (la TVA calculée d'abord, TTC × t ÷ (1 + t), donne le même défaut dans l'autre sens). Seul un ticket calculé en HT l'éviterait, au prix d'un TTC différent du prix affiché, ce qui est exclu pour un particulier.

Ces trois points sont des règles métier (calcul, arrondi, obligation légale) : selon le protocole de CLAUDE.md, ils se soumettent à l'éditeur, et la règle des avoirs à son expert-comptable.

## 1. Le code actuel (chemins et lignes)

### Calcul

| Fichier | Lignes | Rôle |
|---|---|---|
| `app/Domain/Facturation/Services/CalculateurLigne.php` | 7-60 | Commentaire de classe : la règle actuelle (points 1 à 5) |
| | 92-118 | `chiffrerUneVente()` : TTC de ligne, HT de chaque taux depuis le cumul des TTC, TVA = différence |
| | 139-161 | `retourDeLigne()` : retour partiel d'une ligne de vente (HT encadré par la ligne, cumul moins déjà rendu) |
| | 175-196 | `chiffrerUnAchat()` : **le calcul de la méthode HT existe déjà**, pour les achats (HT de ligne = quantité × PU HT − remise ; TVA du taux = HT × taux arrondi, répartie par cumul) |
| | 215-229 | `retourDAchat()` : retour au fournisseur, option A de la méthode HT |
| | 245-251 | `prixUnitaireHtDepuisTtc()` : PU TTC ÷ (1 + taux) arrondi au centime, **le PU HT de la méthode HT** (déjà utilisé par la fiche produit, le barème, la marge) |
| | 273-282 | `prixUnitaireHtExact()` : PU HT au millionième (Q1-b), à supprimer |
| | 707-748 | `prixUnitaireAuCentime()`, `prixUnitaireAffiche()`, `prixUnitaireDansUnChamp()` : affichage au centime d'un prix au millionième (restent pour le coût moyen au dix-millième) |
| | 780-789 | `montantNetCentimes()` : remise arrondie seule puis déduite (cause des écarts de remise, voir 2.4) ; partagé par ventes et achats |
| | 795-807 | `htDepuisTtc()`, `tvaDuHt()` |
| `app/Domain/Facturation/Services/ConstructeurLigneDocument.php` | 25-31 | « Un même panier a les mêmes montants pour tout client » |
| | 50-70, 88-111 | `construireToutes()` (enregistrement), `apercu()` (écran et caisse) |
| | 121-133 | `chiffrer()` : appelle `chiffrerUneVente()` pour tout document |
| | 180-240 | `construire()` : taux (201-208), PU TTC saisi ou de fiche (212-214), remise (220-224), PU HT au millionième (233) |
| `app/Domain/Facturation/Services/DocumentChiffre.php` | 311-331 | Montants écrits comme les colonnes |
| `app/Domain/Facturation/Services/ContexteDeVente.php` | 14-33, 94-118, 133-155 | Type de client, franchise, nature ; « ses montants, eux, sont les mêmes pour tout client » (141-142) ; refus d'émettre un brouillon dont le client a changé de type |
| `app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php` | 28-91 | Récapitulatif par taux = somme des lignes (inchangé) |
| `app/Domain/Facturation/Models/LigneFacture.php` | 157-190 | `montantsDuRetour()` → `retourDeLigne()` |
| `app/Domain/Facturation/Actions/CreerAvoirAction.php` | 149-174, 197 | Ligne d'avoir : prix copiés, montants de `montantsDuRetour()`, totaux additionnés ; refus de mélanger tickets et factures (déjà en place) |
| `app/Domain/Facturation/Actions/CreerFactureAction.php` | 85 | `construireToutes()` (la caisse passe par ici, voir `EnregistrerVenteCaisseAction`) |
| `app/Domain/Facturation/Actions/ModifierFactureAction.php` | 131 | idem |
| `app/Domain/Facturation/Actions/CreerDevisAction.php`, `ModifierDevisAction.php` | 82, 112 | idem |
| `app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php` | 17-28, 92-118 | Copie les montants du devis (aucun recalcul) |
| `app/Domain/Facturation/Services/PanierDeCaisse.php` | 486 | Total TTC du panier, par `apercu()` |
| `app/Filament/App/Pages/Caisse.php` | 517-543 | Un client professionnel à la caisse fait une facture |
| `app/Domain/Facturation/Services/DerogationsDePrix.php` | 12-37, 118-161 | Dérogation = PU TTC différent de la fiche ou du prix de référence |
| `app/Domain/Facturation/Services/LignesRetirees.php` | 7-40 | Baisse de prix au JET 323, comparée sur le PU TTC |
| `app/Domain/Catalogue/Models/Service.php` | 98-135 | Prix de référence sans TVA ; « trois services à 50,00 € TTC à 20 % font 125,00 € HT, et non 3 × 41,67 € » (règle inverse de la méthode HT) |
| `app/Domain/Catalogue/Services/BaremePrixDeVente.php` + `CalculateurLigne::prixTtcArrondi()` (302-311) | | Prix de vente TTC de fiche depuis coût HT + bénéfice (inchangé) |
| `app/Domain/Catalogue/Services/MargeDuCatalogue.php` | 22, 43 | Marge sur le PU HT au centime (déjà celui de la méthode HT) |
| `app/Domain/Facturation/Services/RegularisationDUneFacture.php` | 193-253 | Avoir de régularisation (reste à créditer de chaque ligne) et lignes de la rectificative (reste des montants) |
| `app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php` | 114-144 | Rectificative : lignes et totaux copiés |
| `app/Domain/Facturation/Services/RecapitulatifDeCloture.php` | 21-41, 97-101 | Clôture : factures aux professionnels « à titre d'information », sommes des montants enregistrés |
| `app/Domain/Facturation/Services/EReportingDesParticuliers.php` | 217 | E-reporting : tickets seulement |
| `app/Domain/CaisseSecurisee/Services/ContenuFiscal.php` | 299-330 | Contenu signé : PU HT en millionièmes, PU TTC, taux du prix, remise, montants |

### Affichage

| Fichier | Lignes | Rôle |
|---|---|---|
| `app/Domain/Facturation/Services/TableauDesLignes.php` | 15-41, 97-130 | Colonnes du PDF : en TTC pour un particulier (100), PU HT au centime (122), total HT ou TTC (125) |
| `app/Domain/Facturation/Services/MentionsFiscales.php` | 258-266, 364-375 | Notes de calcul (`CALCUL_VENTE`, `CALCUL_RETOUR`, `CALCUL_SANS_TVA`, `CALCUL_RETOUR_SANS_TVA`, `PRIX_UNITAIRES_AU_CENTIME`) |
| `resources/views/documents/pdf/lignes.blade.php` | 13-15, 49-51 | Note de calcul sous les lignes |
| `resources/views/documents/pdf/totaux.blade.php` | 13-61 | Base HT, TVA, TTC par taux ; totaux |
| `app/Filament/App/Resources/FactureResource.php` | 529-560 | Fiche : PU HT, PU TTC, taux, HT, TVA, TTC de chaque ligne |
| `app/Filament/Concerns/AjouteDesLignesDeVente.php` | 241-262, 400-425, 487-500, 569, 730 | Formulaire : PU HT non saisissable, suit le PU TTC saisi ; réouverture d'un brouillon |
| `app/Filament/Concerns/SaisitLesPrixDeLigne.php` | 35-56 | Totaux à l'écran, par taux |

### Colonnes en base (migrations de création)

`database/migrations/2026_10_03_100002_create_lignes_devis_table.php:31-48`, `2026_10_03_100004_create_lignes_facture_table.php:31-48`, `2026_10_05_100002_create_lignes_avoir_table.php:30-47` : `prix_unitaire_ht decimal(18,6)`, `prix_unitaire_ttc decimal(12,2)`, `taux_tva`, `taux_tva_du_prix`, `remise_pourcentage decimal(5,2)`, `montant_ht`, `montant_tva`, `montant_ttc decimal(12,2)`. Le type de client est figé sur la facture, le devis et l'avoir (`type_client`, voir `FigeLeClient`), avec `franchise_en_base` et `nature_operation`.

### Tests qui verrouillent la règle actuelle

- `tests/Unit/CalculateurLigneTest.php` : 144-325 (50,00 € → 41,67 + 8,33 « quel que soit le découpage », 2 × 17,50, 9,99 → 8,33 + 1,66, plusieurs taux, remise sur le TTC, demi-centime), 358-435 (retours partiels de vente), 565-627 (PU HT au millionième et son affichage), 645-707 (conversions exhaustives de 0,01 € à 100 €), 709-940 (10 000 lignes et 2 000 ventes, factures et séries de retours aléatoires : « se calculent taux par taux sur leur TTC »).
- `tests/Feature/Facturation/PolitiqueDArrondiTest.php` : 193 (« quel que soit le découpage et le client »), 254 (4 × 65,00 € → 216,67 €), 303 (« le même panier donne les mêmes montants à un particulier et à un professionnel »), 326 (« une facture professionnelle n'ajoute jamais un centime au prix »), 340, 364, 421.
- `tests/Feature/Facturation/RepartitionTvaParCumulTest.php` : 72, 89, 121.
- `tests/Feature/Facturation/ConstructeurLigneDocumentTest.php` : 72, 95.
- `tests/Feature/Facturation/FacturePdfGeneratorTest.php` : 536, 653, 689, 711 (54,17 € pour 54,166667 €), 733, 755, 787.
- `tests/Feature/Facturation/MentionsFiscalesTest.php` : 34, 54.
- Montants de factures à un professionnel ou de leurs avoirs dans : `AvoirActionsTest`, `AvoirPdfGeneratorTest`, `DevisPdfGeneratorTest`, `CreerFactureActionTest`, `DevisConversionTest`, `NatureOperationTest`, `FranchiseEnBaseTest`, `RegularisationDUneFactureTest`, `CloturerCaisseActionTest`, `ContrePasserPaiementActionTest`, `ImputerAvoirSurSesFacturesActionTest`, `ReimputationALaContrePassationTest`, `EmettreFactureActionTest`, `TypeClientFigeTest` (tests/Feature/Facturation) ; `CaisseTest`, `FactureResourceTest`, `AvoirResourceTest`, `DevisResourceTest`, `SelecteurDeProduitsTest`, `ClotureCaisseResourceTest` (tests/Feature/Filament) ; `EmissionSigneeTest`, `InalterabiliteTest` (tests/Feature/CaisseSecurisee) ; `TableauDeBordDuCabinetTest`, `FacturesAEncaisserDeLaSocieteTest`, `SirenErroneTest`. À relire un par un : beaucoup prennent des prix ronds dont le HT tombe juste (12,00 €, 120,00 €) et ne changeront pas.
- Fichiers de référence du contenu signé : `tests/Fixtures/contenu-signe/*.json` (chacun porte une facture FAC- ; PU HT en millionièmes), lus par `tests/Feature/Calendrier/ContenuSigneDeReferenceTest.php` : à réécrire si le PU HT passe au centime.

## 2. Impact de la méthode (iii) pour les factures, avoirs sur facture et devis à un professionnel

### 2.1 Quels documents

Proposition : la méthode HT s'applique à **tout document qui imprime un PU HT** : facture, avoir sur facture et devis à un professionnel, et toute vente sans TVA française, même à un particulier (export, livraison vers un DOM : `TableauDesLignes` imprime déjà le PU HT et le total HT sur ces tickets, lignes 25-28, et ils ont aujourd'hui le même écart que la facture de l'éditeur). Les tickets, avoirs sur ticket et devis à un particulier vendus avec la TVA française gardent la méthode TTC. La méthode se déduit de ce que le document a déjà figé (`type_client`, `nature_operation`, `franchise_en_base`) : aucune colonne nouvelle, une seule méthode du domaine (par exemple `seCalculeEnHt()` sur la facture, le devis et l'avoir, et sur `ContexteDeVente`). En franchise en base, rien ne change (PU HT = PU TTC, 0 %).

Conséquence à faire valider : sans TVA française, la règle s'inverse. Aujourd'hui 3 services à 50,00 € TTC à 20 % font 125,00 € (`Service.php:120-128`, 3 × 50,00 ÷ 1,2 arrondi une fois) ; en méthode HT ils font 3 × 41,67 € = 125,01 €, et 2 × 40,00 € TTC font 66,66 € au lieu de 66,67 €.

### 2.2 Calcul

- `chiffrerUnAchat()` et la vente à un professionnel deviennent le même calcul : le renommer (par exemple `chiffrerDepuisLeHt()`) et l'appeler pour les achats et pour les ventes en HT ; `chiffrerUneVente()` devient `chiffrerDepuisLeTtc()`, pour les particuliers. Une ligne de vente en HT prend comme PU HT `prixUnitaireHtDepuisTtc(PU TTC de la fiche ou du prix de référence, taux du prix)`, au centime, demi-centime au centime supérieur. Sans TVA française, la TVA de la ligne est nulle et le TTC est le HT.
- `ConstructeurLigneDocument::construire()` (180-240) : pour une vente en HT, le prix saisi est un PU HT (voir 2.4), le PU HT de la ligne est au centime ; `chiffrer()` (121-133) choisit le calcul selon le contexte.
- `DocumentChiffre` et `VentileLaTvaParTaux` ne changent pas : les totaux restent la somme des lignes, et en méthode HT la TVA de chaque taux est par construction base × taux arrondie.
- Arrondi du PU HT : au centime le plus proche, demi-centime au centime supérieur, comme partout. Avec un PU TTC à un demi-centime de HT (à 20 %, un PU TTC dont le nombre de centimes vaut 3 modulo 6 : 0,99 €, 3,99 €, 6,99 €, 9,99 €, 12,99 €…, un tiers des prix en ,99), le professionnel paie 1 centime de plus dès 1 article ; arrondir au centime inférieur lui ferait payer 1 centime de moins : aucun sens d'arrondi n'évite l'écart.

### 2.3 Retours partiels et avoirs sur facture

Le retour de toute une facture dans un seul avoir reprend exactement ses montants, quelle que soit l'option. Pour les retours partiels, il faut trancher (resultats.md, section 5) :

- **Option A** (règle demandée dans la mission, celle de `retourDAchat()`) : un retour rend, pour les articles d'une ligne rendus jusqu'ici, quantité × PU HT remise déduite, et la TVA de ce HT sans dépasser celle de la ligne, moins ce que les retours précédents ont rendu ; le retour de toute la ligne rend exactement ses montants. Le HT d'une ligne d'avoir se vérifie toujours (quantité × PU HT), mais la TVA d'un avoir partiel s'écarte souvent d'un centime de base × taux : environ un avoir sur quatre quand une ligne se rend article par article, et 25 % à 36 % des avoirs de la ligne 2 seule, du reste d'une ligne ou d'un article de chaque ligne (la part de TVA d'une ligne dans son taux n'est pas la TVA de son propre HT). Exemple : 2 coques (16,66 € HT, 3,33 € de TVA) rendues une par une : 8,33 € + 1,67 €, puis 8,33 € + 1,66 € ; le second avoir montre 8,33 × 20 % = 1,666 → 1,67 ≠ 1,66.
- **Option B** : chaque avoir se calcule comme une facture en HT de ses propres lignes (HT = quantité × PU HT − remise, TVA de chaque taux = HT de l'avoir × taux arrondie, répartie par cumul). Aucun avoir ne montre jamais d'écart, et le HT rendu retombe toujours exactement sur la ligne ; mais la TVA rendue par plusieurs avoirs partiels peut s'écarter de celle de la facture d'un demi-centime au plus par avoir et par taux, plus un demi-centime (± 1 centime sur les suites de deux ou trois avoirs étudiées ; jusqu'à ± 0,40 € à 20 % et ± 0,50 € aux autres taux pour 100 articles rendus chacun par son avoir). La TVA rendue peut donc dépasser de quelques centimes la TVA facturée.
- **Option A'** (B, mais sans jamais rendre plus que la TVA restante de la ligne, et le dernier retour d'une ligne la solde) : en général seul le dernier avoir montre un écart, de la taille de l'écart de l'option B, qui peut dépasser un centime (100 coques rendues une par une : 99 avoirs de 8,33 € + 1,67 €, puis un dernier de 8,33 € + 1,27 €) ; sur de petits prix en grande quantité, le plafond peut aussi toucher les avoirs d'avant.

À soumettre à l'éditeur et à l'expert-comptable de la société (somme exacte ou chaque avoir juste). `LigneFacture::montantsDuRetour()` et `CalculateurLigne::retourDeLigne()` choisissent le calcul selon la méthode du document ; `CreerAvoirAction` refuse déjà de mélanger tickets et factures (197), donc un avoir n'a qu'une méthode. Le refus devra aussi couvrir un avoir qui mêlerait une vente en France et une vente sans TVA française à un même particulier, si la méthode HT couvre ces ventes (2.1).

### 2.4 Remises et prix dérogatoires

- **Remise en %** (colonne `remise_pourcentage`, la seule de PILIQI) : `montantNetCentimes()` arrondit la remise, puis la déduit. La calculatrice, elle, fait quantité × PU × (1 − remise) et arrondit le net. Les deux diffèrent quand la remise tombe sur un demi-centime, ce qui est fréquent avec une remise ronde (9 % des lignes remisées de 10 % ou de 15 % du domaine D2) : c'est le seul écart que garde la méthode HT avec une remise en %, et **il existe déjà sur les tickets** (resultats.md, D2 : 9,95 € remisé de 10 % → la remise 0,995 € s'arrondit à 1,00 €, le ticket imprime 8,95 € ; la calculatrice, 9,95 × 0,9 = 8,955 → 8,96 €). Correction : arrondir une fois le net de la ligne (quantité × PU × (1 − remise)), pas la remise, pour les ventes en TTC comme en HT ; ou imprimer la remise en euros. `montantNetCentimes()` sert aussi aux achats, où la facture du fournisseur fait foi : à décider séparément.
- **Remise en euros** (si l'éditeur la veut) : une remise HT sur une ligne en HT ne s'arrondit pas (HT = quantité × PU HT − remise), aucun écart (D3) ; il faudrait remplacer `remise_pourcentage` par une colonne de montant dans `lignes_devis`, `lignes_facture`, `lignes_avoir`, les lignes du panier de caisse (`PanierCaisse`), le contenu signé et les retours (remise rendue au prorata des articles rendus, ce qui rouvre un arrondi).
- **Prix dérogatoire** : pour un professionnel, le prix se négocie et se lit en HT : le champ saisissable d'une ligne en HT devient le PU HT, au centime, et le PU TTC un affichage (`AjouteDesLignesDeVente.php:400-425`, `suivreLePrixUnitaireHt()` 487-500 s'inverse). `DerogationsDePrix` (118-161) compare, pour une ligne en HT, le PU HT saisi au PU HT de la fiche ou du prix de référence (le PU TTC ÷ (1 + taux) arrondi), et sa clé (155-161) porte le prix de la méthode. `LignesRetirees` compare les baisses de prix dans l'unité de la ligne : changer le client du panier en professionnel ne doit pas s'inscrire comme une baisse de 10,00 € à 8,33 €.
- L'autorisation « accorder des remises et fixer le prix d'un service » ne change pas.

### 2.5 Barème et catalogue

Rien ne change dans `BaremePrixDeVente` ni dans `prixTtcArrondi()` : la fiche garde un prix TTC arrondi une fois, le prix du particulier. Le PU HT d'une vente à un professionnel en découle (déjà montré par la fiche et le barème via `prixUnitaireHtDepuisTtc()`, et utilisé par `MargeDuCatalogue`). L'arrondi commercial au X9 (19,00 €, 29,00 €…) ne garantit pas un HT juste : 19,00 € → 15,83 € HT. Option possible, non nécessaire : un arrondi qui vise un PU TTC dont le HT tombe juste (à 20 %, multiple de 0,06 €), pour lequel professionnel et particulier paient le même prix.

### 2.6 Client professionnel à la caisse

La caisse fait une facture pour un professionnel (`Caisse.php:517-543`) : elle la calcule en HT. Le total à payer n'est plus la somme des PU TTC × quantités affichés : la caisse affiche, pour un professionnel, le PU HT et le total HT de chaque ligne et le total TTC calculé (HT + TVA) ; `PanierDeCaisse::total` (486) le lit déjà dans `apercu()`, qui suit le contexte ; les parts du règlement et les bons d'avoir égalent ce total. Changer de client dans le panier recalcule tout (avec le message que le total change). L'abandon de la vente (JET 320) inscrit le total de la méthode du panier.

### 2.7 Document qui change de type de client

`ContexteDeVente::refuserSiLeTypeDeClientAChange()` (146-155) force déjà un nouvel enregistrement, qui recalculera les lignes dans la nouvelle méthode : le message doit dire que les montants changent, et le commentaire « ses montants, eux, sont les mêmes pour tout client » (141-142) disparaît. Un devis converti copie ses montants et son type (`CreerFactureDepuisDevisAction`) ; si le client a changé de type, l'émission le refuse et le nouvel enregistrement recalcule : inchangé. Un avoir prend la méthode de ses factures (type figé).

### 2.8 Clôtures, e-reporting, facture électronique

- Clôture du jour (`RecapitulatifDeCloture`) : les factures aux professionnels et leurs avoirs y sont « à titre d'information », sommes de montants enregistrés : aucun code ne change, seuls les montants. Grands totaux, relevés « Fermer ma caisse », chiffre d'affaires : sommes de montants enregistrés, inchangés.
- E-reporting : seuls les tickets aux particuliers (`EReportingDesParticuliers.php:217`) : inchangé. Une vente sans TVA française à un particulier qui passerait en méthode HT entre dans l'e-reporting avec ses montants enregistrés, sans recalcul.
- Facture électronique (étape 2) : la méthode HT est le modèle de calcul de la norme EN 16931 (net de ligne = quantité × prix net − remise ; TVA de chaque catégorie = base × taux, arrondie). La note sur les normes posée dans ce dossier par une autre recherche (`/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/normes.md`) conclut que la règle actuelle passe les validateurs grâce à leurs tolérances (± 1,00 € sur BR-CO-17, 0,02 € sur Peppol R120, à condition d'envoyer le PU HT à 6 décimales) et que la lettre de BR-CO-17 demande TVA = base × taux arrondie ; la méthode HT respecte ces règles à la lettre, sans tolérance, avec un PU HT au centime. À confirmer avec la documentation SUPER PDP à l'étape 2.

### 2.9 Avoir de régularisation et facture rectificative

`RegularisationDUneFacture::lignesDeLAvoir()` crédite ce qui reste de chaque ligne par `montantsDuRetour()` : il suit l'option des retours. `lignesDeLaRectificative()` (214-253) copie le reste des montants : sans avoir antérieur, la rectificative reprend la facture et se vérifie ; après un avoir partiel, son HT restant ((q − k) × PU HT) se vérifie, mais sa TVA restante (TVA de la ligne − TVA rendue) peut s'écarter d'un centime de base × taux, comme un avoir en option A. Le recalculer comme une facture en HT des quantités restantes rendrait la rectificative juste, mais différente de ce que l'avoir de régularisation crédite. À trancher avec le constat 3 de la relecture A7b (« règle d'arrondi de la rectificative à soumettre à l'éditeur », tâche en attente de l'autre chaîne).

### 2.10 Colonnes à modifier dans les migrations de création

- `prix_unitaire_ht` de `lignes_devis`, `lignes_facture`, `lignes_avoir` : `decimal(18,6)` → `decimal(12,2)`. En méthode HT c'est un prix au centime ; en méthode TTC il n'est imprimé nulle part (le ticket imprime le PU TTC) et ne sert qu'à l'affichage de la fiche, où il s'arrondissait déjà au centime. Alors `prixUnitaireHtExact()`, `enMillioniemes()` pour les lignes de vente, `enDecimalAuMillionieme()` et la note `PRIX_UNITAIRES_AU_CENTIME` disparaissent ; `prixUnitaireAffiche()` reste pour le coût moyen (`cmup_ht decimal(14,4)`). Le contenu signé (`ContenuFiscal::lignesEtTotaux()`) signe le PU HT en centimes.
- `remise_pourcentage` : inchangée si la remise reste en % (seul l'arrondi change) ; remplacée par une colonne de montant si l'éditeur choisit une remise en euros (2.4).
- Aucune colonne de méthode : elle se déduit de `type_client`, `nature_operation` et `franchise_en_base`, déjà figés.
- `prix_unitaire_ttc` et `taux_tva_du_prix` restent (prix de fiche, dérogations, journal des baisses, ventes sans TVA française).
- Puis `php artisan migrate:fresh` (règle du projet).

### 2.11 Tests à réécrire

- `tests/Unit/CalculateurLigneTest.php` : séparer les tests de la méthode TTC (particuliers, inchangés sauf la remise si son arrondi change) et écrire ceux de la méthode HT : 2 × 10,00 € TTC → 2 × 8,33 = 16,66 € + 3,33 € = 19,99 € ; 9,99 € → 8,33 + 1,67 = 10,00 € ; 4 × 65,00 € → 216,68 + 43,34 ; 50,00 € en une ligne → 41,67 + 8,33 = 50,00 €, en 2 × 25,00 € → 41,66 + 8,33 = 49,99 € ; plusieurs taux ; remise ; retours partiels selon l'option ; sans TVA française (2 × 40,00 € → 66,66 €). Remplacer les tests du PU HT au millionième (565-627). Les tests exhaustifs et aléatoires (645-940) gagnent une propriété vérifiable en méthode HT : pour toute facture, quantité × PU HT − remise = HT de ligne, base × taux arrondi = TVA, TTC ÷ (1 + taux) arrondi = base.
- `PolitiqueDArrondiTest` : 193, 254, 303 et 326 affirment l'inverse de la nouvelle règle (mêmes montants pour tout client, jamais un centime de plus) : à réécrire en « un professionnel paie HT + TVA, vérifiable » et « un particulier paie le prix affiché ».
- `RepartitionTvaParCumulTest` (répartition du HT par cumul sur une facture professionnelle) : devient la répartition de la TVA par cumul.
- `ConstructeurLigneDocumentTest` 72, 95 ; `FacturePdfGeneratorTest` 536, 689, 711, 733, 755, 787 (PU HT au millionième imprimé au centime, note de calcul) ; `MentionsFiscalesTest` 34, 54 ; `AvoirPdfGeneratorTest`, `DevisPdfGeneratorTest`.
- Les tests de la liste du point 1 qui assertent des montants de factures à un professionnel avec un prix dont le HT ne tombe pas juste.
- Fichiers de référence `tests/Fixtures/contenu-signe/*.json` (PU HT en centimes, montants des factures FAC-).
- Nouveaux tests : caisse avec un client professionnel (total HT + TVA, parts du règlement) ; brouillon qui change de type de client (montants recalculés) ; dérogation saisie en HT ; journal des baisses de prix en HT ; avoir partiel selon l'option retenue ; remise sur un demi-centime.

### 2.12 Fichiers touchés

Domaine : `app/Domain/Facturation/Services/CalculateurLigne.php`, `ConstructeurLigneDocument.php`, `ContexteDeVente.php`, `DerogationsDePrix.php`, `LignesRetirees.php`, `PanierDeCaisse.php`, `MentionsFiscales.php`, `TableauDesLignes.php`, `RegularisationDUneFacture.php`, `DocumentChiffre.php` (commentaire) ; `app/Domain/Facturation/Models/LigneFacture.php`, `LigneAvoir.php`, `LigneDevis.php` (casts du PU HT), `Facture.php`, `Avoir.php`, `Devis.php` (méthode du document) ; `app/Domain/Facturation/Actions/CreerAvoirAction.php`, `CreerFactureDepuisDevisAction.php` (commentaire), `EmettreLaFactureRectificativeAction.php` ; `app/Domain/Catalogue/Models/Service.php` (vente sans TVA à son prix de référence) ; `app/Domain/CaisseSecurisee/Services/ContenuFiscal.php` ; `app/Domain/Tenancy/Services/CatalogueDeDemonstration.php` (prix des lignes de démonstration).

Écrans et vues : `app/Filament/Concerns/AjouteDesLignesDeVente.php`, `SaisitLesPrixDeLigne.php`, `ChoisitLaNatureDeLOperation.php` ; `app/Filament/App/Pages/Caisse.php` ; `app/Filament/App/Resources/FactureResource.php` (fiche : un ticket montre le PU TTC, un document en HT le PU HT, comme leurs PDF), `AvoirResource`, `DevisResource` ; `resources/views/documents/pdf/lignes.blade.php` (note de calcul de la méthode) ; `totaux.blade.php` inchangé.

Base : les trois migrations de création des lignes de vente (et la table du panier de caisse si la remise passe en euros).

Tests : voir 2.11. Règles : `CLAUDE.md`, puces « Prix et TVA des ventes » et « Documents de vente » (phrase du PU HT à six décimales et de la note de calcul).

## 3. Projet de texte pour CLAUDE.md

### Puce « Prix et TVA des ventes » (remplace la puce entière)

- Prix et TVA des ventes : seul le prix de vente TTC d'un produit est un prix arrondi, une seule fois, quand la facture d'achat ou l'inventaire d'ouverture enregistre le produit ; le prix de référence d'un service est un prix TTC saisi au centime. Le taux de TVA se saisit librement, 20 % par défaut (8,5 % pour un magasin dont le territoire est la Guadeloupe, la Martinique ou La Réunion) ; une ligne de produit prend le taux de sa fiche. Accorder une remise et fixer le prix d'un service (son prix de référence au catalogue comme celui d'une ligne) et le prix d'une ligne de produit différent de sa fiche exigent l'autorisation « accorder des remises et fixer le prix d'un service », que les responsables du magasin et le propriétaire ont toujours (le propriétaire, qui ne vend jamais, pour le prix de référence d'un service et les lignes d'un devis), précochée pour les administrateurs, pour qui elle ne vaut sur une ligne de ticket ou de facture que si le propriétaire leur a aussi coché la vente, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et qu'un cabinet lié n'a jamais ; changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ; sans elle, une ligne de produit prend le prix de sa fiche, une ligne de service le prix de référence de sa fiche, et un service sans prix de référence ne se vend pas. Toute vente se calcule une seule fois, à son enregistrement, par l'une de deux méthodes, que le document garde : la méthode TTC pour un ticket, un avoir sur ticket et un devis à un particulier vendus avec la TVA française ; la méthode HT pour toute facture, tout avoir sur facture et tout devis à un professionnel, et pour toute vente sans TVA française, même à un particulier. Méthode TTC : le TTC de chaque ligne est quantité × PU TTC, remise déduite, le net de la ligne arrondi une fois au centime, et le TTC payé est la somme des lignes ; pour chaque taux, le HT est le TTC de ses lignes ÷ (1 + taux), arrondi une fois au centime le plus proche (un demi-centime exact s'arrondit au centime supérieur), et se répartit sur ses lignes par cumul, dans leur ordre ; la TVA est TTC − HT ; le document imprime par ligne le PU TTC et le total TTC, que la calculatrice retrouve toujours, et par taux une base qui est toujours le TTC ÷ (1 + taux) arrondi, mais dont le produit par le taux peut s'écarter d'un centime de la TVA imprimée (une fois sur six à 20 %), ce qu'aucun calcul parti du TTC n'évite ; ses lignes n'impriment pas de PU HT. Méthode HT : le PU HT de chaque ligne est un prix au centime, celui de la fiche ou du prix de référence, PU TTC ÷ (1 + taux) arrondi une fois au centime le plus proche, un demi-centime au centime supérieur (10,00 € TTC à 20 % : 8,33 € ; 9,99 € : 8,33 €), ou le PU HT saisi pour un prix dérogatoire ; le HT de chaque ligne est quantité × PU HT, remise déduite, le net arrondi une fois ; la TVA de chaque taux est le total HT du taux × taux, arrondie une fois et répartie sur ses lignes par cumul, dans leur ordre ; le TTC est HT + TVA ; sans TVA française, le client paie le HT, sans TVA (2 × 40,00 € TTC à 20 % : 2 × 33,33 € = 66,66 €). Ainsi tout ce qu'imprime un document en HT se vérifie à la calculatrice : quantité × PU HT imprimé, remise déduite, est le total HT de la ligne, la somme des lignes la base, la base × taux la TVA, la base + la TVA le TTC, et le TTC ÷ (1 + taux) la base. Un professionnel paie donc HT + TVA, qui peut différer de quelques centimes de la quantité × PU TTC que paierait un particulier pour le même panier : 2 × 10,00 € TTC à 20 % lui font 16,66 € HT + 3,33 € = 19,99 €, un article à 9,99 €, 8,33 € + 1,67 € = 10,00 €, 4 × 65,00 €, 216,68 € + 43,34 € = 260,02 €, et ses montants dépendent du découpage des lignes (50,00 € TTC en une ligne : 41,67 € + 8,33 € ; en 2 × 25,00 € : 41,66 € + 8,33 € = 49,99 €) ; seul un PU TTC dont le HT tombe juste au centime (un multiple de 0,06 € à 20 %, de 0,11 € à 10 %) donne le même prix à tout client. Un brouillon de facture ou un devis dont le client change de type se recalcule par la méthode de son nouveau type à son nouvel enregistrement, et l'écran dit que ses montants changent ; un avoir prend la méthode des documents qu'il corrige et n'en mélange jamais deux. Un retour se calcule par la méthode de la vente. En TTC, il rend, pour tous les articles d'une ligne rendus jusqu'ici, le même calcul sur cette quantité, sans jamais dépasser le HT ni la TVA de la ligne, moins ce que les retours précédents ont rendu. En HT, chaque avoir se calcule comme une facture en HT de ses lignes rendues (HT = quantité rendue × PU HT, remise déduite, TVA de chaque taux = HT de l'avoir × taux, arrondie une fois et répartie par cumul), si bien que chaque avoir se vérifie à la calculatrice et que le HT rendu retombe exactement sur la ligne, la TVA rendue par plusieurs avoirs partiels pouvant s'écarter de celle de la facture d'un demi-centime au plus par avoir et par taux, plus un demi-centime (2 coques rendues une par une : 1,67 € + 1,67 € pour 3,33 € facturés) ; cette règle est à faire confirmer par l'expert-comptable de la société. Dans les deux méthodes, le retour de tout un document en un avoir rend exactement ses montants. Un magasin en franchise en base vend à 0 % au même prix TTC, avec la mention « TVA non applicable, art. 293 B du CGI » : son PU HT est son PU TTC. Un achat se calcule par la méthode HT, depuis le PU HT du fournisseur ; un retour au fournisseur rend, pour tous les articles d'une ligne retournés jusqu'ici, la TVA de leur HT, sans dépasser celle de la ligne, moins ce que les retours précédents ont rendu, et le retour de toute la ligne rend exactement ses montants. Ensuite plus rien ne s'arrondit ni ne se recalcule : les documents affichent les montants tels qu'ils sont enregistrés, et une clôture, un relevé, un tableau de bord ou une déclaration additionne les montants enregistrés de ses documents, sans jamais recalculer la TVA d'un total ; cette somme peut donc s'écarter de « TTC ÷ (1 + taux) » ou de « HT × taux » d'au plus un demi-centime par document et par taux (deux tickets de 25,00 € font 41,66 € HT et 8,34 € de TVA). Tout prix unitaire montré en lecture (documents, fiches, listes, aides, messages, caisse ; ventes comme achats) s'affiche au centime, et un PU HT de vente, enregistré au centime, s'imprime tel quel. Les montants se calculent en centimes entiers, et aucun total ne s'obtient par une somme que la base de données ferait sur une colonne décimale.

Variante si l'éditeur préfère la somme exacte des avoirs (option A), à la place de la phrase « En HT, chaque avoir se calcule… » : « En HT, il rend, pour tous les articles d'une ligne rendus jusqu'ici, quantité × PU HT, remise déduite, et la TVA de ce HT, sans dépasser celle de la ligne, moins ce que les retours précédents ont rendu, et le retour de toute la ligne rend exactement ses montants ; la TVA d'un avoir partiel peut alors s'écarter d'un centime de sa base × taux, ce que sa note de calcul explique. »

### Puce « Documents de vente », phrase à remplacer

Remplacer « Le PU HT, enregistré avec six décimales, s'y imprime arrondi au centime, comme le PU TTC, et la note de calcul imprimée sous les lignes de toute vente hors franchise en base dit que les prix unitaires sont affichés arrondis au centime et que les totaux, calculés sans arrondi intermédiaire, peuvent différer de la quantité × le prix unitaire affiché. » par : « Le PU HT d'un document calculé en HT est un prix au centime, imprimé tel quel ; la note de calcul imprimée sous les lignes de toute vente hors franchise en base dit sa méthode : en HT, que le total HT de chaque ligne est la quantité × le PU HT, remise déduite, et la TVA de chaque taux la base × le taux, arrondie une fois au centime ; en TTC, que le HT de chaque taux est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes, et la TVA la différence. »

### Autres phrases de CLAUDE.md qui suivent

- Puce « Ventes hors de France » : « Une vente exonérée (export, livraison vers un DOM, livraison intracommunautaire) se paie au prix hors taxe, calculé de la même façon, sans TVA » devient « …calculé par la méthode HT, sans TVA » (dans « Prix et TVA des ventes », reprise ci-dessus).
- Feuille de route, étape 1 : « politique d'arrondi unique des ventes et des achats » devient « méthode HT des ventes aux professionnels et des achats, méthode TTC des ventes aux particuliers ».

### Points à trancher par l'éditeur avant d'adopter ce texte

1. La méthode HT pour les professionnels, avec ce qu'elle implique : un professionnel paie HT + TVA, qui peut s'écarter de quelques centimes du prix TTC affiché (19,99 € pour 2 coques à 10,00 €, 10,00 € pour un article à 9,99 €), ce qui renverse la décision de la série 8 (« un même panier a les mêmes montants pour tout client »).
2. La méthode HT aussi pour les ventes sans TVA française à un particulier (export, livraison vers un DOM), dont le ticket imprime déjà le PU HT : proposé.
3. Les avoirs partiels en HT : option B (chaque avoir juste, proposée dans le texte), option A (somme exacte, variante ci-dessus) ou option A' ; à faire confirmer par l'expert-comptable.
4. La remise en % : arrondir le net de la ligne plutôt que la remise (proposé dans le texte : corrige aussi l'écart actuel des tickets remisés), ou passer à une remise en euros ; et la même question pour les achats, où la facture du fournisseur fait foi.
5. La facture rectificative après la correction d'un SIREN, dont le reste des montants peut porter un écart de TVA (à joindre au constat 3 de la relecture A7b).
