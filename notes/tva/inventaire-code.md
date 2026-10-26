# Inventaire du code TVA de PILIQI (instantané 9c9ee25) — préparation d'un moteur de TVA centralisé

Rédigé le 11/10/2026. Analyse seule : aucune ligne de code n'a été modifiée, aucun test lancé.

## 0. Cadre

### 0.1 Sources lues

- Code : instantané en lecture seule de la branche au commit 9c9ee25 (« Vérifie les sociétés et ouvre le magasin de démonstration (lot A3 de l'architecture société) »). Les chemins ci-dessous sont relatifs à cet instantané, suivis de leurs numéros de ligne. Tout le dossier `app/` a été passé au crible (453 fichiers), ainsi que les 52 migrations, les vues PDF et les 146 fichiers de tests.
- Règles : `CLAUDE.md`, identique à celui de la branche de travail. Le dossier `.ai/rules` (et donc `index.md`) **n'existe pas** dans l'instantané (ni dans la branche de travail) : aucune règle de chemin à appliquer.
- Demande de l'éditeur (`scratchpad/tva/demande-editeur.md`), décisions des séries 1 à 3 (B1, B2, B10, B12, C1, C2, E1, E5 à E7, E13, E14, I1 à I4, J2, K2, K3, K5), compléments 1 à 16 du 11/10 (`decisions/reponses-complements-14-20.md`), conception de l'architecture société (`societe/conception-v2-finale.md`), recherche fiscale (`regles-tva-international.md`, 713 lignes).
- PDF de l'éditeur : article 290 du CGI (version en vigueur depuis le 21/02/2026, lu en entier, 4 pages) et FAQ impots.gouv.fr « Mes clients sont à la fois des entreprises et des particuliers » (publiée le 15/11/2024, modifiée le 16/01/2026, 3 pages).

### 0.2 Résumé

1. **Ce qui existe est solide, mais ne couvre que les ventes.** Côté ventes, le calcul (prix TTC, règle R4, retours cumulés, franchise, ventes exonérées au prix hors taxe) est exact au centime, en entiers, et couvert par plus de 300 tests. Il en va de même pour les six natures d'opération du lot 19 (contrôles bloquants à l'émission), la vérification VIES (lot 18), les preuves et la détaxe (lot 20), et le contenu signé.
2. **Côté achats, il n'y a pas de règle fiscale du tout.** Une ligne d'achat n'a qu'un taux et une TVA, celle facturée par le fournisseur. Elle porte toujours un produit, donc jamais une prestation de service. Le logiciel ne connaît ni l'origine des marchandises, ni l'autoliquidation, ni l'importation, ni la TVA non déductible, ni les droits de douane. Le coût du stock se limite au prix d'achat HT, remise et escompte déduits.
3. **Les décisions fiscales sont dispersées.** La règle « vente sans TVA française » est codée quatre fois sous des formes différentes. Le taux de la fiche produit sert à la fois de taux de vente, de taux d'achat, de taux de valorisation du stock et de taux de barème. La franchise se lit directement dans les écrans au lieu de passer par le contexte de vente. Les mentions sont recalculées à l'impression au lieu d'être relues dans le contenu signé.
4. **Quatre contradictions ont un effet réel.**
   - Saisir à l'écran une facture d'achat à 0 % (acquisition intracommunautaire) passe le produit à 0 % **à la vente** (C1).
   - La notion « établi en France » (inscription au répertoire Sirene) est confondue avec le territoire de TVA : Saint-Barthélemy, Saint-Martin et Saint-Pierre-et-Miquelon (hors du territoire de TVA français), ainsi que la Guyane et Mayotte (où la TVA n'est pas applicable, art. 294-1), sont traités comme le territoire de TVA français (C2).
   - Le lieu de départ des biens est toujours supposé en métropole, ce qui est faux pour un magasin des Antilles ou de La Réunion (C3).
   - Toute facture à un professionnel part en facture électronique, 0 % compris, contre la décision E13 et les trois conditions officielles (C5).
5. **Aucun code de transmission n'est calculé** : ni catégorie EN 16931 (S, G, K, AE, O, E), ni VATEX, ni catégorie B2C (TLB1, TPS1, TNT1, TMA1). Le circuit n'est déduit que du type de client. Le calendrier interne d'e-reporting, voué à disparaître (complément 11), regroupe les ventes par période au lieu d'un envoi par jour (E14).
6. **Le point d'appui naturel du moteur est `ContexteDeVente`**, déjà l'objet « contexte fiscal » de la vente. On l'étend en une décision fiscale complète (régime, taux par ligne, catégories, mentions, circuit, conditions, justification, version des règles), signée dans le bloc « contexte ». `CalculateurLigne` reste le noyau arithmétique, sans aucun changement. Côté achats, le moteur se branche dans `ConstructeurLigneAchat::enregistrerUneLigne()` et dans `LigneAchat::coutUnitaireHt()`.

---

## 1. Inventaire par domaine

Légende de la colonne « Figé / signé » :
- **S** : signé au registre fiscal (bloc « document » ou « contexte » de `ContenuFiscal`) ;
- **F** : colonne figée à l'émission, protégée par `ProtegeLesDonneesValidees` et par les déclencheurs ;
- **J** : changement inscrit au journal des événements (JET 270) ;
- **—** : ni l'un ni l'autre.

### 1.1 Noyau arithmétique

| Élément | Rôle | Entrées → sorties | Appelé par | Figé / signé |
|---|---|---|---|---|
| `app/Domain/Facturation/Services/CalculateurLigne.php` (classe, docblock 7-54) | Tout calcul HT, TVA et TTC en entiers (centimes, centièmes de point), jamais en flottant intermédiaire. Seul l'arrondi final est au centime, demi vers l'extérieur (`diviserArrondi` 699-708). | — | Partout (voir ci-dessous) | — |
| `montantsDeVente()` 73-81 | Ligne vendue à un particulier, ou en franchise : PU HT et PU TTC × quantité, remise déduite. La TVA est la différence. | PU HT, PU TTC, quantité, remise → montants | `ConstructeurLigneDocument::construire()` 218 ; `LigneFacture::montantsDuRetour()` 183 | Résultat S (lignes) |
| `montantsDAchat()` 93-103 | Ligne d'achat : HT = quantité × PU HT, remise déduite, puis TVA = HT × taux, arrondie une fois (point 4 du docblock). | PU HT, taux, quantité, remise → montants | `ConstructeurLigneAchat::enregistrerUneLigne()` 299 ; `LigneAchat::montantsDuRetour()` 134 ; `AjouteDesLignesDAchat::montantsDeLigneSaisie()` 401 | — |
| `montantHtDeLigneProfessionnelle()` 117-127 et `htDeLigneProfessionnelleCentimes()` 649-658 | Décision E1 : HT de la ligne = quantité × PU TTC × (1 − remise) ÷ (1 + taux), avec un seul arrondi. | PU TTC, taux du prix, quantité, remise → HT | `ConstructeurLigneDocument::construire()` 217 | Résultat S |
| `repartirLaTva()` 148-165 | **R4** : TVA d'un taux calculée sur le cumul des HT, chaque ligne recevant la différence de cumul. | HT des lignes d'un taux, dans l'ordre, et taux → part de TVA de chaque ligne | `ConstructeurLigneDocument::repartirLaTvaParTaux()` 243 | Résultat S |
| `retourDeLigneProfessionnelle()` 191-217 | **K2** : un retour professionnel se recalcule comme la facture, par cumul ; sa TVA est plafonnée à celle de la ligne, et le dernier retour solde la ligne. Utilise `taux_tva_du_prix` pour le HT et `taux_tva` pour la TVA. | Ligne et quantité rendue cumulée → montants | `LigneFacture::montantsDuRetour()` 182 | Résultat S |
| `prixUnitaireHtDepuisTtc()` 219-227 et `prixUnitaireTtcDepuisHt()` 229-236 | Conversions unitaires au centime. | — | `ConstructeurLigneDocument` 197 ; `Produit::synchroniserPrixDeVente()` 388, 395 ; écrans (`AjouteDesLignesDeVente` 283, 335 ; `AjouteDesLignesDAchat` 148, 371 ; `ChoisitLaNatureDeLOperation` 262-263 ; `ProduitResource` 188 et suivantes) | — |
| `prixTtcArrondi()` 247-256 | Le **seul prix arrondi** : prix de vente TTC tiré d'un HT exact au dix-millième, arrondi au centime ou à la dizaine (prix en X9). | HT exact, taux, pas d'arrondi → TTC | `BaremePrixDeVente::prixDeVente()` 119-120 | — |
| `valeurAuCoutMoyen()` 276-288 | Valeur d'un stock : quantité × CMUP au dix-millième, arrondie une fois, **plus une TVA au taux donné**. | Quantité, CMUP, taux → HT, TVA, TTC | `ValeurStockApercu` 60 | — |
| `coutUnitaireNet()` 299-312 | Coût réel d'un article acheté : PU HT × (1 − remise) × (1 − escompte), au dix-millième. | — | `LigneAchat::coutUnitaireHt()` 92-99 ; `ConstructeurLigneAchat::prixDuBareme()` 310 ; `AjouteDesLignesDAchat::prixDAchatNet()` 419 | — |
| `coutMoyenPondere()` 347-368 et `coutMoyenApresUneSortie()` 385-407 | CMUP d'une entrée ; CMUP après un retour au fournisseur sorti au coût de sa ligne (C1), plancher à 0 € (K1). | Lots → CMUP | `StockLedgerService` 150, 323, 370 | — |
| `retourCumule()` 428-437 | Crédit d'un retour = montants du cumul rendu − ce que les retours déjà émis ont crédité. | — | `LigneFacture::montantsDuRetour()` 180 ; `LigneAchat::montantsDuRetour()` 133 | — |
| `totaliser()`, `cumuler()`, `deduire()`, `sommer()`, `soustraire()` 443-529 | Sommes séparées des HT, TVA et TTC (documents, clôtures, chiffre d'affaires). | — | Actions de vente et d'achat, clôtures, e-reporting, `ChiffreDAffaires` | — |
| `enCentiemes()` et `enDecimal()` 551-589 | Lecture et écriture exactes des colonnes `decimal(…,2)`, utilisées pour le contenu signé. | — | `ContenuFiscal`, `Emetteur::duMagasin()` 91 | — |
| `verifierTauxTva()` 622-627 | Seule validation métier du taux : de 0 à 100 %. Le taux reste libre (décision B2). | — | Méthodes ci-dessus | — |
| `app/Domain/Facturation/Services/QuantiteEntiere.php` | Quantités entières seulement. Les quantités décimales, poids et volumes sont hors périmètre (CLAUDE.md, SUPER PDP). | — | Constructeurs de lignes | — |

### 1.2 Contexte et construction des ventes

| Élément | Rôle | Entrées → sorties | Appelé par | Figé / signé |
|---|---|---|---|---|
| `app/Domain/Facturation/Services/ContexteDeVente.php` | **Seul objet « contexte fiscal » actuel.** Constructeur 35-40 : `typeClient`, `franchiseEnBase`, `tauxTvaParDefaut`, `natureOperation`. | Client, magasin, nature | Voir lignes suivantes | — |
| `pour()` 48-56 | Lit le type de client (particulier si aucun), `societe->estEnFranchiseDeTva()` et `magasin->tauxTvaParDefaut()`. | — | `PanierDeCaisse` 276 ; `AjouteDesLignesDeVente::contexteDeVente()` 420-427 ; `ChoisitLaNatureDeLOperation::sansTvaFrancaise()` 281-284 | — |
| `pourEnregistrer()` 75-86 | Refuse une nature non proposée à ce client ou à ce régime (`NatureOperation::proposees()`). | — | `CreerFactureAction` 40 ; `ModifierFactureAction` 77 ; `CreerDevisAction` 37 ; `ModifierDevisAction` 58 | — |
| `exonere()` 93-96 | Franchise **ou** nature exonérée : lignes à 0 %. | — | `ConstructeurLigneDocument` 185 | — |
| `retireLaTvaDuPrix()` 108-111, `documentRetireLaTvaDuPrix()` 121-124, `sansTvaFrancaise()` 215-218 | **K3** : hors franchise et hors vente en France, le client paie le prix TTC ÷ (1 + taux). | — | `ConstructeurLigneDocument` 182, 200 ; `MentionsFiscales::calcul()` 126 ; vues PDF ; écrans | — |
| `calculeDepuisLeHtDeLigne()` 179-182, `documentCalculeDepuisLeHtDeLigne()` 191-194, `depuisLeHtDeLigne()` 210-213 | Professionnel hors franchise : calcul depuis le HT de ligne (E1). | — | `ConstructeurLigneDocument` 72, 106, 216 ; `LigneFacture::montantsDuRetour()` 181 ; `MentionsFiscales::calcul()` 129 | — |
| `refuserSiLeTypeDeClientAChange()` 140-149 et `refuserSiLeRegimeAChange()` 161-166 | Refusent l'émission d'un brouillon ou d'un devis enregistré avec un autre type de client ou un autre régime. | — | `EmettreFactureAction` 131-132 ; `EmettreDevisAction` 57-58 | — |
| `colonnes()` 202-208 | Colonnes `type_client` et `franchise_en_base` écrites sur le document. | — | Actions de création et de modification | F + S |
| `app/Domain/Facturation/Services/ConstructeurLigneDocument.php` | Construit les lignes d'une vente et leurs montants : le même code sert à l'enregistrement (`construireToutes()` 53-73) et à l'aperçu à l'écran (`apercu()` 91-115). | Données saisies, contexte → tableaux de lignes | Actions de facture et de devis ; `PanierDeCaisse` ; `AjouteDesLignesDeVente::apercuDesLignes()` 110-116 | Résultat S |
| `construire()` 161-220 | **Décision du taux de chaque ligne**, 179-185 : franchise → 0 ; produit → taux de sa fiche ; service hors TVA française → 0 ; sinon taux saisi ou taux par défaut. Puis `taux_tva` = 0 si `exonere()`. Prix 189-200 : PU TTC saisi, sinon `prix_vente_ttc` de la fiche ; le PU TTC payé est le PU HT quand la TVA est retirée du prix. | — | — | — |
| `repartirLaTvaParTaux()` 234-252 | R4 appliquée aux lignes regroupées par `taux_tva`. **Clé de regroupement : le taux seul.** | — | `construireToutes()` 72 ; `apercu()` 111 | — |
| `app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php` | Récapitulatif par taux (`ventilationTva` 28-31, `ventilerParTaux()` 96-113, via `CalculateurLigne::totaliser`) et par bien ou service puis taux (`ventilationParNature` 41-52). Les avoirs sont comptés en négatif (`montantsDeduits()` 81-90). | Lignes → ventilation | `Facture`, `Avoir`, `Devis`, `FactureAchat`, `AvoirFournisseur`, `ClotureCaisse`, `SoumissionEReporting` ; PDF `documents/pdf/totaux.blade.php` 23 | Non signé (le contenu signé recalcule sa propre ventilation, voir D3) |
| `app/Domain/Facturation/Enums/NatureLigne.php` | Bien (produit) ou service. | — | Lignes de vente ; catégorie d'opération ; e-reporting | S (`nature` de chaque ligne) |
| `app/Domain/Facturation/Enums/CategorieOperation.php` | Mention « livraison de biens », « prestation de services » ou « les deux » (`depuisNatures()` 30-39). | — | `MentionsFiscales` 66 ; `ContenuFiscal` 200, 266 | S |

### 1.3 Natures d'opération (lot 19)

| Élément | Rôle | Détail | Figé / signé |
|---|---|---|---|
| `app/Domain/Facturation/Enums/NatureOperation.php` | Les six natures de la décision I1 (26-31). | `mention()` 50-60 : 262 I, 294, 262 ter I, « Autoliquidation », 259-1°. `conditions()` 67-77 : texte d'aide. `exonere()` 83-86 : tout sauf la vente en France. `porteSurDesBiens()` 93-96 et `porteSurDesServices()` 104-107. `reserveeAuxProfessionnels()` 114-117. `exigeVies()` 124-127 (LIC et autoliquidation). `exigeUneLivraison()` 133-136. `transportImpose()` 145-148 (export et DOM : le magasin). `proposees()` 167-174 : vente en France seule en franchise ; un particulier n'a ni la LIC ni les prestations. | `nature_operation` F + S |
| `app/Domain/Facturation/Services/ControleNatureOperation.php` | **Conditions bloquantes à l'émission** : tous les manquements sont rendus d'un coup. | `verifier()` 71-80 : refuse ou retourne la consultation VIES retenue. `manquements()` 89-109, dont le n° de TVA de la société obligatoire (107). Livraison 144-170 : adresse complète, date et transport (LIC), transport par le magasin (export, DOM), services refusés sauf frais accessoires. Destination 172-190 : DOM, Monaco, hors UE (biens), même DOM que le magasin, UE (biens). Prestations 200-222 : client établi hors de France, dans l'UE ou hors UE (services), identifiant hors UE, aucun bien, aucun service au lieu fixé par la loi. N° de TVA 231-245 : préfixe d'un autre État membre (XI seulement pour les biens), consultation du jour valide. | La consultation retenue est écrite dans `consultation_vies_id` (F + S) |
| `app/Domain/Facturation/Exceptions/NatureOperationRefuseeException.php` 21 | Message unique qui renvoie vers « Vente en France ». | — | — |
| `app/Domain/Facturation/Concerns/NoteSaNatureDOperation.php` | `noterSaNatureDOperation()` 31-58 : écrit la nature et la livraison **seulement si la nature l'exige** (sinon toutes les colonnes `livraison_*` sont remises à vide) ; le transport est imposé (52-54). `lignesDeLivraison()` 67-81 : texte imprimé. | Attributs du formulaire → colonnes | F + S |
| `app/Domain/Facturation/Enums/TransportPar.php` | Le magasin ou l'acquéreur. | — | S |
| Écran `app/Filament/Concerns/ChoisitLaNatureDeLOperation.php` | Liste des natures 56-73, section Livraison 75-106, retour à la vente en France 114-124 et 142-165, conversion du prix des services 203-221 et 246-272, livraison préremplie depuis l'adresse du client 295-314. | La nature est **choisie à la main** : seules les natures impossibles sont retirées. | — |

### 1.4 Géographie fiscale

| Élément | Rôle | Détail |
|---|---|---|
| `app/Domain/Facturation/Services/TerritoireFiscal.php` | Statique : pays ISO et code postal → territoire. | `PAYS_FRANCE` 22 : FR, GP, MQ, GF, RE, YT, BL, MF, PM (« identifié par un SIREN »). `PREFIXES_TVA_UE` 30-33 (EL, XI). `ETATS_MEMBRES_UE` 38-41 (GR). `TERRITOIRES_EXCLUS` 53-59. `DEPARTEMENTS_DOM` 65. `COLLECTIVITES_D_OUTRE_MER` 73 (PM, BL, MF, WF, PF, NC, TF). `CODES_POSTAUX_DES_COLLECTIVITES` 80. Fonctions : `estEnFrance()` 86-89, `prefixeTvaUe()` 97-102, `departementDom()` 112-129, `estUneCollectiviteDOutreMer()` 135-141, `estHorsUePourDesBiens()` 153-156, `estHorsUePourDesServices()` 164-167, `estDestinationUePourDesBiens()` 175-178, `estDansUnAutreEtatMembrePourDesServices()` 186-189, `estHorsDuTerritoireTvaUe()` 196-211. |
| `app/Domain/Facturation/Enums/TerritoireTva.php` | Territoire de TVA **du magasin** : métropole, Corse, Antilles-Réunion, Guyane-Mayotte. | `depuisAdresse()` 39-50 : pays FR obligatoire, codes postaux 971, 972, 974 / 973, 976, « 20 » pour la Corse (48). `estPrisEnCharge()` 57-60 : tout sauf la Guyane et Mayotte. `tauxNormal()` 65-72 : 20 / 20 / 8,5 / 0. `tauxEnVigueur()` 80-88. `aideSiTauxInhabituel()` 108-123 : alerte sans blocage (X8). |
| `app/Domain/Tenancy/Models/Magasin.php` | `territoireTva()` 453-456 et `tauxTvaParDefaut()` 465-468, avec repli sur la métropole. | Lu par 9 appelants (voir D8). |
| `app/Domain/Tenancy/Services/ControleSirene.php` 204-206 ; `app/Domain/Tenancy/Actions/CreerEtablissementAction.php` (docblock 52-53) ; `app/Domain/Tenancy/Actions/ModifierEtablissementAction.php` 119 et 136-138 | Refus d'un magasin hors des territoires gérés, et d'un changement d'adresse qui change le territoire (un tel déménagement passe par un nouveau magasin, CLAUDE.md). | — |

### 1.5 Identification TVA et VIES (lot 18)

| Élément | Rôle | Figé / signé |
|---|---|---|
| `app/Domain/Facturation/Services/NumeroTva.php` 28-39 | Normalisation (majuscules, sans séparateurs) et format `^[A-Z]{2}[0-9A-Z]{2,13}$`. Pas de contrôle de clé, pas de lien avec le SIREN (groupe TVA). | — |
| `app/Domain/Facturation/Rules/NumeroTvaAuFormat.php` | Règle de validation (client, fournisseur, société). | — |
| `app/Domain/Facturation/Casts/NumeroTvaNormalise.php` | Cast d'enregistrement. | — |
| `app/Domain/Contacts/Rules/HorsDeLEntrepriseDuMagasin.php` 69-79 | Refuse un client ou un fournisseur qui a le SIREN ou le n° de TVA de la société (art. 256 I). | — |
| `app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php` 38-67 | Consulte VIES hors transaction et conserve la preuve. Le demandeur est le n° de TVA de la société (54). | `consultations_vies` en ajout seul (déclencheurs) |
| `app/Integrations/Vies/ClientVies.php` 203-229, 235-261 | Client REST VIES. **Classe concrète, sans interface ni faux.** | — |
| `app/Integrations/Vies/ResultatVies.php` | Résultat de la consultation, réponse brute comprise. | — |
| `app/Domain/Contacts/Models/ConsultationVies.php` | Preuve inaltérable ; `scopeDuJour()` 145-150 (jour de Paris). | La consultation retenue est S (`ContenuFiscal` 202-207) |
| `app/Filament/App/Resources/FactureResource.php` 785-794 | `verifierLeNumeroTvaSurViesSiBesoin()` : **consulte VIES automatiquement avant l'émission, depuis l'écran seulement.** | — |

### 1.6 Preuves et détaxe (lot 20)

| Élément | Rôle | Figé / signé |
|---|---|---|
| `app/Domain/Facturation/Services/PreuvesRequises.php` | `typesAdmis()` 48-56 ; `concerne()` 62-67 ; `manquantes()` 76-96 (MRN ou CN23 ; transport de catégorie a) ; deux documents a), ou un a) et un b) ; attestation de l'acquéreur au 10 du mois suivant, 142-148) ; `incompletes()` 107-133 : la même règle traduite en SQL. | — |
| `app/Domain/Facturation/Models/PreuveOperation.php` ; `app/Domain/Facturation/Enums/TypePreuve.php` | Référence de preuve en ajout seul. **Hors du contenu signé** (docblock 161-170). | Inaltérable (déclencheurs) |
| `app/Domain/Facturation/Actions/AjouterPreuveOperationAction.php` 255-298 | Enregistre une preuve. « Établie par » est facultatif (`$emetteur` peut être vide, 258). Les doublons de référence sont comparés sans la casse. | — |
| `app/Domain/Facturation/Actions/NoterBordereauDetaxeAction.php` 50-91 | Décision I3 : seulement sur un ticket émis, vendu en France, hors franchise, avec un bien taxé non rendu (`LigneFacture::scopeBiensTaxes()` 153-156, `montant_tva > 0`). | Bordereau en ajout seul |
| `app/Domain/Facturation/Models/BordereauDetaxe.php` 35, 69-74 | Seuil de 100 € TTC : simple avertissement, pas de blocage. | — |

### 1.7 Tiers (clients et fournisseurs)

| Élément | Données TVA | Remarques |
|---|---|---|
| `app/Domain/Contacts/Models/Client.php` | `type_client`, `siren`, `siret`, `numero_tva`, `etabli_en_france`, `identifiant_etranger`, `pays` (fillable 41-59). `estEtabliEnFrance()` 109-112. `normaliserSesIdentifiants()` 124-147 : un particulier n'a aucun identifiant, et une adresse « en France » au sens de `PAYS_FRANCE` force `etabli_en_france` (140-142). `aUnNumeroTvaVerifiableSurVies()` 164-167. `derniereConsultationVies()` 183-194. | Aucun champ « assujetti à la TVA », « entité publique », schéma d'identifiant B2BInt (0223, 0227…), ni adresse électronique de facturation. |
| `app/Domain/Contacts/Enums/TypeClient.php` | Particulier ou professionnel. Le docblock (277-284) dit qu'il choisit la série **et le circuit**. | — |
| `app/Domain/Facturation/Services/CopieClient.php` 133-148 ; `app/Domain/Facturation/Concerns/FigeLeClient.php` 44-79 | Copie figée du client : nom, adresse, pays, SIREN, SIRET, n° de TVA, identifiant étranger, établi en France. | F + S (`ContenuFiscal` 344-352) |
| `app/Domain/Contacts/Models/Fournisseur.php` 18-29 | `siret`, `numero_tva`, adresse, `pays` (2 lettres, FR par défaut). | Ni SIREN, ni statut, ni établissement, ni régime habituel. |
| `app/Filament/App/Resources/ClientResource.php` | Établi en France selon le pays (96-99, 118, 140, 165-171) ; SIREN obligatoire en France (176-185) ; n° de TVA (201-205) ; identifiant étranger (207-212) ; **pays masqué pour un particulier** dans la création rapide (247). | — |
| `app/Filament/App/Resources/FournisseurResource.php` | SIRET, n° de TVA, refus de l'entreprise du magasin (39-60). | — |

### 1.8 Documents de vente et actions

| Élément | Rôle TVA | Figé / signé |
|---|---|---|
| `app/Domain/Facturation/Models/Facture.php` | `scopeTickets()` 284-287 (particulier = ticket) ; `serie()` 596-599 (type de client → TK ou FAC) ; `libelleDocument()` 572-575 ; **`relevantDeLeInvoicing()` 612-615 : renvoie `estPourUnProfessionnel()`** (simplification provisoire, voir son docblock). | — |
| `app/Domain/Facturation/Models/LigneFacture.php` | `montantsDuRetour()` 172-186 (cumul K2, particulier ou professionnel) ; `prixUnitaireTtcPaye()` 194-201 : **déduit « vente sans TVA française » de l'écart entre `taux_tva_du_prix` et `taux_tva`** ; `scopeBiensTaxes()` 153-156. | Lignes F + S |
| `app/Domain/Facturation/Models/Avoir.php`, `LigneAvoir`, `Devis`, `LigneDevis` | Mêmes colonnes TVA ; un avoir reprend `type_client`, `franchise_en_base` et `nature_operation`. | F + S (avoir) |
| `app/Domain/Facturation/Actions/CreerFactureAction.php` 36-53 ; `ModifierFactureAction.php` 77-97 ; `CreerDevisAction.php` 37-46 ; `ModifierDevisAction.php` 58-72 | Nature et livraison notées → contexte → lignes → totaux. | — |
| `app/Domain/Facturation/Actions/EmettreFactureAction.php` 116-183 | Verrou du registre ; contrôle du type de client et du régime (131-132) ; identité légale et SIREN du client pour un professionnel (134-136, 193-200) ; nature (144) ; numéro ; client figé ; VIES ; signature (173-179). | — |
| `app/Domain/Facturation/Actions/CreerAvoirAction.php` 59-158 | Refuse de mélanger types de client, franchises ou natures (101-117) ; lignes de l'avoir par `montantsDuRetour()` (139). | — |
| `app/Domain/Facturation/Actions/EmettreAvoirAction.php` 71-162 | Montants arrêtés de nouveau à l'émission (158) ; identité légale exigée pour un professionnel (87). | — |
| `app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php` 47-48 | Reprend la franchise, la nature et la livraison du devis. | — |
| `app/Domain/Facturation/Actions/EmettreDevisAction.php` 57-58 | Même refus que l'émission d'une facture si le type de client ou le régime a changé. | — |

### 1.9 Mentions, émetteur, contenu signé, PDF

| Élément | Rôle | Figé / signé |
|---|---|---|
| `app/Domain/Facturation/Services/MentionsFiscales.php` | `pour()` 61-80 : catégorie de l'opération (66) ; mention de la nature (69-71) ; mention de franchise lue sur le document (73-74) ; option pour les débits lue sur l'émetteur (75-77). `calcul()` 124-133 : note de calcul, **non signée**. Textes en constantes 45-55. `bonDAvoir()` 91-100. | Mentions S (`ContenuFiscal` 201, 267) |
| `app/Domain/Facturation/Services/Emetteur.php` | `duMagasin()` 77-112 : n° de TVA 96, `regimeTva` 108, `optionDebitsTva` 109 de la société ; adresse et pays du magasin (97-101). `duContenuSigne()` 166-195 ; `enTableau()` 207-238. Pas de champ « territoire » (il se déduit de l'adresse signée). | S (bloc `contexte.emetteur`) |
| `app/Domain/Facturation/Concerns/FigeSonEmetteur.php` 382-409 | Un document émis imprime l'émetteur signé. | — |
| `app/Domain/CaisseSecurisee/Services/ContenuFiscal.php` | Bloc `document` de la facture 159-187 : `franchise_en_base` 167, `nature_operation` 168, livraison 169-177, `consultation_vies_id` 178, lignes (taux, taux du prix, montants) 290-314, **ventilation recalculée** 317-327, totaux. Bloc `contexte` 192-226 : émetteur, catégorie, mentions, consultation VIES. Avoir 231-278. | C'est le contenu signé |
| `app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php` 679 et suivantes | Compare le bloc `document` recalculé avec le bloc signé. Toute nouvelle colonne fiscale figée doit y entrer. | — |
| `app/Domain/CaisseSecurisee/ModuleFiscal.php` 23 | `VERSION = '1.0'`. | — |
| `app/Domain/CaisseSecurisee/Services/RegistreFiscal.php` 286, 469-470 | Grands totaux **TTC** seulement (réel et absolu). | S |
| `app/Domain/CaisseSecurisee/Concerns/JournaliseSesParametresDeConformite.php` ; `Produit::parametresDeConformite()` 125-128 (`taux_tva`) ; `Societe::parametresDeConformite()` 421-424 (`regime_tva`, `option_debits_tva`, `mois_fin_exercice`, `siren`, `numero_tva`) | JET 270 avec l'ancienne et la nouvelle valeur. | J |
| Vues PDF : `resources/views/factures/pdf.blade.php` 40-41 et 105-106 ; `avoirs/pdf.blade.php` 41-42 et 53-54 ; `documents/pdf/lignes.blade.php` 40-78 ; `totaux.blade.php` 23-41 ; `livraison.blade.php` | Les colonnes « Prix catalogue TTC » et « TVA retirée » sont imprimées hors TVA française ; **les mentions sont recalculées par `MentionsFiscales::pour()` au rendu**. | — |

### 1.10 Caisse et clôtures

| Élément | Rôle TVA |
|---|---|
| `app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php` 63-80 | `CreerFactureAction` sans attribut : toujours une vente en France. Un professionnel est accepté (série FAC, calcul depuis le HT de ligne). |
| `app/Domain/Facturation/Services/PanierDeCaisse.php` 274-280 ; `app/Filament/App/Pages/Caisse.php` 519-522 | Total TTC calculé par le même constructeur que l'enregistrement. |
| `app/Domain/Facturation/Actions/CloturerCaisseAction.php` 89-100 | Tickets du jour moins leurs avoirs : totaux HT, TVA et TTC (`deduire(cumuler…)`). **Seulement les tickets de particuliers** (point 8 des compléments). |
| `app/Domain/Facturation/Models/ClotureCaisse.php` 170-173 | Ventilation par nature et par taux (biens et services), base future de TLB1 et TPS1. |
| `app/Domain/Facturation/Actions/FermerSaCaisseAction.php` 177 ; `app/Domain/Facturation/Services/ChiffreDAffaires.php` 39-71 | Totaux HT, TVA et TTC nets des avoirs. |

### 1.11 E-reporting et passerelle PDP

| Élément | Rôle |
|---|---|
| `app/Domain/Facturation/Actions/GenererSoumissionEReportingAction.php` 42-87 | Regroupe **toutes les clôtures d'une période** (58 : seulement celles qui ont une opération ; 77 : `cumuler`) en une soumission numérotée ER. |
| `app/Domain/Facturation/Models/SoumissionEReporting.php` 65-75 | Ventilation recalculée depuis les lignes des clôtures. |
| `app/Integrations/Pdp/Services/CalculateurPeriodeEReporting.php` 36-44, 49-60 | Décades, mois et bimestres. L'échéance de la 2e décade tombe au dernier jour du mois (57). **Voué à disparaître** (complément 11). |
| `app/Console/Commands/SoumettreEReportingPeriodique.php` | Parcourt les magasins des sociétés validées dont le régime est renseigné. |
| `app/Integrations/Pdp/Contracts/PdpGatewayInterface.php` 19-35 | `creerMandat`, `revoquerMandat`, `soumettreFacture`, `soumettreEReporting`. **Rien pour le B2BInt, les paiements ni les achats.** |
| `app/Integrations/Pdp/FakePdpGateway.php` | Accepte tout. |
| `app/Domain/Facturation/Listeners/DeclencherTransmissionPdpApresEmissionFacture.php` 28 | Transmet toute facture `relevantDeLeInvoicing()`, **0 % compris**. |
| `app/Domain/Facturation/Jobs/SubmitFactureToPdpJob.php` et `SubmitSoumissionEReportingToPdpJob.php` | Envoi idempotent. |
| Catégories TLB1, TPS1, TNT1, TMA1, codes S, G, K, AE, O, E, VATEX | **Absents** : une recherche de `TLB1|TPS1|VATEX|category_code` dans `app/`, `resources/`, `config/` et `tests/` (hors fixture openapi) ne trouve rien. |

### 1.12 Réglages de la société et du magasin

| Élément | Données TVA |
|---|---|
| `app/Domain/Tenancy/Models/Societe.php` | `regime_tva`, `option_debits_tva`, `numero_tva` ; `estEnFranchiseDeTva()` 289-292 ; `mentionsLegalesManquantes()` 379-396 (n° de TVA exigé hors franchise, 392) ; `parametresDeConformite()` 421-424. Ni date d'effet du régime, ni OSS, ni EORI, ni adhésion à PABLO, ni établissements dans d'autres États membres. |
| `app/Domain/Tenancy/Enums/RegimeTva.php` | Réel normal mensuel, réel normal trimestriel, réel simplifié, franchise. Docblock 8 : « Le régime de TVA **du magasin** » (périmé). `ARTICLE_FRANCHISE` 39, `MENTION_FRANCHISE` 44, `estFranchise()` 56-59. |
| `app/Domain/Tenancy/Actions/ModifierParametresFiscauxSocieteAction.php` ; `app/Filament/Societe/Pages/ParametresFiscauxSociete.php` 71-79 ; `IdentiteSociete.php` 129 ; `ModifierIdentiteSocieteAction.php` 145 | Enregistrement sous le verrou des registres de tous les magasins, avec JET 270 dans chacun. |
| `app/Domain/Tenancy/Models/Magasin.php` 482-512, 519-537 | Identité légale exigée pour un professionnel ; mentions entre professionnels. |

### 1.13 Catalogue et prix

| Élément | Données TVA |
|---|---|
| `app/Domain/Catalogue/Models/Produit.php` | `taux_tva` (un seul taux), `prix_vente_ttc` (prix de référence), `prix_vente_ht` (conversion), `cmup_ht` (4 décimales). `synchroniserPrixDeVente()` 381-398 : un TTC saisi donne le HT ; **un HT saisi donne un TTC calculé par `prixUnitaireTtcDepuisHt()`**. `parametresDeConformite()` 125-128 : JET 270 sur `taux_tva`. Migration `2026_10_02_200003_create_produits_table.php` : `taux_tva decimal(5,2)` « toujours donné à la création ». |
| `app/Domain/Catalogue/Models/Service.php` | `frais_accessoires`, `lieu_specifique` (art. 259 A). **Ni taux ni prix** : le prix se saisit TTC (ou sans TVA hors de France), le taux est saisi ou celui du territoire. Pas de marqueur « immatériel art. 259 B » ni « télécommunication, radiodiffusion, électronique ». |
| `app/Domain/Catalogue/Services/BaremePrixDeVente.php` 110-126 | (Prix d'achat HT + bénéfice) × (1 + taux), arrondi une seule fois, ou au X9. |
| `app/Filament/App/Resources/ProduitResource.php` 142-151, 160-170, 188 et suivantes ; `app/Filament/App/Pages/ParametresPrixDeVente.php` 62, 161-181 | Taux par défaut du territoire, alerte de taux inhabituel, rappel de la franchise (163). |

### 1.14 Achats

| Élément | Rôle TVA | Figé / signé |
|---|---|---|
| Migration `2026_10_07_100001_create_factures_achat_table.php` | `devise` (EUR), `total_ht`, `total_tva`, `total_ttc`, `escompte_pourcentage` (coût seulement, pas les totaux, 36-39), `source` (manuelle ou API). | Facture d'achat non signée |
| Migration `2026_10_07_100002_create_lignes_achat_table.php` | `produit_id` ou `nouveau_produit` (toujours un produit), `prix_unitaire_ht decimal(12,2)`, **un seul** `taux_tva`, `montant_tva`. | — |
| `app/Domain/Achats/Services/ConstructeurLigneAchat.php` | `enregistrerUneLigne()` 263-301 : **taux = taux saisi, sinon taux de la fiche produit** (267) ; PU HT saisi, sinon dernier prix d'achat (272-274) ; montants par `montantsDAchat()` (299). `produitDeLaLigne()` 353-386 : les attributs de la ligne (taux compris) **corrigent la fiche du produit** (364) ; un nouveau produit prend le taux par défaut du magasin (383). Barème au taux de la fiche (181, 282, 308-311). Totaux 145-148. `verrouillerLeRegistreSiUnTauxChange()` 102-113 (JET 270). | J (taux du produit) |
| `app/Filament/Concerns/AjouteDesLignesDAchat.php` | **484-485 : « Le taux de la fiche s'applique aussi à cette ligne. »** « Montant payé au fournisseur … TTC » (109-121). Bénéfice = PV TTC ÷ (1 + taux) − coût net (126-153, 359-378). | — |
| `app/Domain/Achats/Actions/CreerFactureAchatAction.php`, `ModifierFactureAchatAction.php` (118-158 : correction de la réception et nouveau CMUP), `ValiderFactureAchatAction.php` 78-113 | Aucun contrôle fiscal. | — |
| `app/Domain/Achats/Models/LigneAchat.php` | `coutUnitaireHt()` 92-99 (coût d'entrée en stock) ; `montantsDuRetour()` 123-142 (cumul) ; `quantiteRetournableRestante()` 154-170. | — |
| `app/Domain/Achats/Actions/CreerAvoirFournisseurAction.php` 79-90 ; `EmettreAvoirFournisseurAction.php` 171-193 | Reprennent le taux et le PU de la ligne achetée. | — |
| `app/Domain/Achats/Enums/SourceFactureAchat.php` | Valeur « API » ; **aucune route d'API d'achat n'existe** (`routes/web.php` seul fichier de routes). | — |

### 1.15 Stock

| Élément | Rôle |
|---|---|
| `app/Domain/Stock/Services/StockLedgerService.php` | `receptionner()` 49-69 (CMUP à l'entrée) ; `corrigerUneReception()` 100-131 (grand livre rejoué, 311-353) ; `retournerAuFournisseur()` 144-163 (sortie au coût de la ligne, C1 et K1) ; ventes et retours clients au CMUP du moment, qui ne change pas. |
| `app/Domain/Achats/Listeners/IncrementerStockApresValidationFactureAchat.php` 32 | Entrée au coût `LigneAchat::coutUnitaireHt()`. |
| `app/Domain/Achats/Listeners/DecrementerStockApresEmissionAvoirFournisseur.php` 27-33 | Sortie au coût de la ligne d'achat. |
| `app/Domain/Stock/Models/MouvementStock.php` | `cout_unitaire_ht` renseigné seulement pour un retour au fournisseur (`StockLedgerService` 266). |
| `app/Filament/App/Resources/ProduitResource/Widgets/ValeurStockApercu.php` 59-64 | « Valeur du stock … € **TTC** », au taux de vente de chaque produit. |

### 1.16 Écrans Filament (logique TVA présente dans l'interface)

- `app/Filament/Concerns/AjouteDesLignesDeVente.php` :
  - réouverture des lignes (164-178) : recode `franchise || nature exonérée` (174) ;
  - champ taux (288-316) : franchise lue directement sur la société (296, 298), taux du produit forcé à 0 en franchise (`appliquerLeTauxDuProduit()` 324-339, ligne 326) ;
  - total de ligne et alerte de taux (351-372) ;
  - contexte (379-394, 420-427).
- `app/Filament/Concerns/ChoisitLaNatureDeLOperation.php` : voir 1.3.
- `app/Filament/Concerns/AjouteDesLignesDAchat.php` : voir 1.14.
- `app/Filament/App/Resources/FactureResource.php` 785-794 (VIES), 386-396 (preuves) ; `ViewFacture.php` 80 (aide « deux parties indépendantes »).
- `app/Filament/App/Resources/ClientResource.php`, `FournisseurResource.php`, `ProduitResource.php`, `ServiceResource.php` 53-58 (aides sur les frais accessoires et le lieu fixé par la loi), `FactureAchatResource.php` 85-130.
- `app/Filament/App/Resources/ClotureCaisseResource.php` 116 et `SoumissionEReportingResource.php` 55 : ventilation par nature.

### 1.17 Colonnes TVA par table (migrations de création, schéma final)

| Table | Colonnes TVA |
|---|---|
| `societes` | `numero_tva`, `regime_tva`, `option_debits_tva` |
| `magasins` | adresse (`pays`, `code_postal`) → territoire |
| `clients` | `type_client`, `siren`, `siret`, `numero_tva`, `etabli_en_france`, `identifiant_etranger`, `pays` |
| `fournisseurs` | `siret`, `numero_tva`, `pays` |
| `produits` | `taux_tva`, `prix_vente_ttc`, `prix_vente_ht`, `cmup_ht` |
| `services` | `frais_accessoires`, `lieu_specifique` |
| `factures`, `avoirs`, `devis` | `type_client`, `franchise_en_base`, `nature_operation`, `livraison_*` (factures et devis), `consultation_vies_id` (factures), `client_*` figés (factures et avoirs), `total_*`, `devise` |
| `lignes_facture`, `lignes_avoir`, `lignes_devis` | `nature`, `prix_unitaire_ht`, `prix_unitaire_ttc`, `taux_tva`, `taux_tva_du_prix`, `remise_pourcentage`, `montant_*` |
| `factures_achat`, `avoirs_fournisseur` | `total_*`, `devise`, `escompte_pourcentage` (factures d'achat) |
| `lignes_achat`, `lignes_avoir_fournisseur` | `prix_unitaire_ht`, `taux_tva`, `remise_pourcentage`, `montant_*` |
| `consultations_vies`, `preuves_operation`, `bordereaux_detaxe` | Preuves en ajout seul |
| `clotures_caisse`, `soumissions_ereporting` | Totaux seuls, sans ventilation stockée |

Aucune colonne pour : catégorie TVA de ligne, VATEX, motif d'exonération, catégorie B2C, circuit de transmission, régime d'achat, pays d'expédition, document de douane, TVA autoliquidée, TVA déductible, coût d'entrée en stock par ligne.

### 1.18 Tests qui fixent ces comportements

Ils doivent rester verts, ou être réécrits proprement quand une règle change (protocole, point 4). Le nombre de tests de chaque fichier est indiqué entre parenthèses.

**Noyau, à ne pas toucher :**
- `tests/Unit/CalculateurLigneTest.php` (42) : R4, E1, K2, achats, CMUP, conversions exhaustives de 0,01 à 100 €, 10 000 lignes et 2 000 factures aléatoires.
- `tests/Unit/BaremePrixDeVenteTest.php` (9).
- `tests/Feature/Facturation/RepartitionTvaParCumulTest.php` (3).
- `tests/Feature/Facturation/AvoirActionsTest.php` (37, dont les retours à 40 € et la part de TVA d'une ligne professionnelle).
- `tests/Feature/Stock/StockLedgerServiceTest.php` (20).
- `tests/Feature/Achats/FactureAchatActionsTest.php` (68 ; « la TVA d'une ligne au prix HT se calcule sur son total HT », CMUP, escompte, 4 tests du registre quand un taux change).
- `tests/Feature/Achats/AvoirFournisseurActionsTest.php` (18).

**Natures, VIES, preuves, détaxe :**
- `tests/Feature/Facturation/NatureOperationTest.php` (29).
- `tests/Feature/Facturation/PreuvesOperationTest.php` (22).
- `tests/Feature/Facturation/BordereauDetaxeTest.php` (19).
- `tests/Feature/Contacts/VerifierNumeroTvaActionTest.php` (12), `ConsultationViesTest.php` (4), `ClientTest.php` (11).
- `tests/Unit/TerritoireFiscalTest.php` (3), `TerritoireTvaTest.php` (4).

**Franchise, contexte, construction :**
- `tests/Feature/Facturation/FranchiseEnBaseTest.php` (14), `ConstructeurLigneDocumentTest.php` (4).
- `tests/Feature/Facturation/CreerFactureActionTest.php` (20 : taux de la fiche, taux du territoire pour un service, calcul professionnel, franchise).
- `tests/Feature/Facturation/EmettreFactureActionTest.php` (24 : n° de TVA exigé hors franchise, SIREN du client).
- `tests/Feature/Facturation/TypeClientFigeTest.php` (6), `DevisConversionTest.php` (14), `CorrectionParAvoirTest.php` (2).

**Contenu signé et journal :**
- `tests/Feature/CaisseSecurisee/EmissionSigneeTest.php` (22 : franchise et mention signées, TVA calculée sur le total HT, LIC avec livraison et VIES signées).
- `VerificateurIntegriteTest.php` (49, dont « une franchise réécrite en base est détectée »), `JournalEvenementsTest.php` (27, dont 6 sur le taux d'un produit), `InalterabiliteTest.php` (11).
- `tests/Feature/Facturation/EmetteurFigeTest.php` (14).

**PDF :** `FacturePdfGeneratorTest.php` (19 : catégorie, option pour les débits, client étranger, note de calcul), `AvoirPdfGeneratorTest.php` (9), `DevisPdfGeneratorTest.php` (5).

**Clôtures et e-reporting :**
- `CloturerCaisseActionTest.php` (15, dont « la clôture sépare les biens et les services »).
- `GenererSoumissionEReportingActionTest.php` (10), `SoumettreEReportingPeriodiqueTest.php` (4), `tests/Unit/CalculateurPeriodeEReportingTest.php` (8) : ces trois fichiers **seront réécrits ou supprimés** avec le calendrier interne (complément 11, décision E14).
- `TransmissionPdpTest.php` (6) : à réécrire avec le circuit calculé.

**Écrans :**
- `tests/Feature/Filament/FactureResourceTest.php` (70, dont environ 34 sur la TVA, la nature, VIES et la conversion du prix des services).
- `ClientResourceTest.php` (54), `CaisseTest.php` (67), `DevisResourceTest.php` (32).
- `FactureAchatResourceTest.php` (39, dont « la TVA d'une ligne d'achat est celle de son total HT » et « la remise et la TVA font recalculer le prix de vente proposé »).
- `ProduitResourceTest.php` (34), `ParametresPrixDeVenteTest.php` (12), `FournisseurResourceTest.php` (14), `ValeurStockWidgetTest.php` (6), `ClotureCaisseResourceTest.php` (17), `SoumissionEReportingResourceTest.php` (3).

**Société :**
- `tests/Feature/Societe/ParametresFiscauxSocieteTest.php` (8), `IdentiteSocieteTest.php` (19).
- `ModifierEtablissementActionTest.php` (14, dont « un changement d'adresse qui change le territoire de TVA est refusé »), `CreerEtablissementActionTest.php` (18, dont « hors des territoires gérés le magasin est refusé »).

Les tests directement **visés par une contradiction** ci-dessous, et à réécrire si l'éditeur tranche dans le sens proposé :
- `FactureAchatResourceTest` : le taux de la ligne est celui de la fiche (C1) ;
- `ValeurStockWidgetTest::test_la_valeur_du_stock_est_sa_quantite_fois_son_cmup` : la tuile affiche le TTC (C7) ;
- `TransmissionPdpTest::test_une_facture_a_un_client_professionnel_avec_mandat_actif_declenche_la_transmission` (C5) ;
- `ClientResourceTest::test_un_professionnel_de_guadeloupe_est_propose_comme_etabli_en_france_et_doit_avoir_un_siren` : à garder, mais il faut distinguer l'identification du territoire de TVA (C2).

---

## 2. Carte des flux de décision fiscale actuels

```
                    SOCIÉTÉ (regime_tva, option_debits_tva, numero_tva)
                         │ estEnFranchiseDeTva()  Societe.php:289
MAGASIN (adresse) ──► TerritoireTva::depuisAdresse() ──► tauxTvaParDefaut()  Magasin.php:465
                         │
CLIENT (type, pays, établi, n° TVA) ──┐
NATURE choisie à la main (formulaire) ─┼──► ContexteDeVente::pour / pourEnregistrer   ContexteDeVente.php:48,75
                                      │          │ exonere(), retireLaTvaDuPrix(), calculeDepuisLeHtDeLigne()
PRODUIT.taux_tva / saisie service ────┘          ▼
                                   ConstructeurLigneDocument::construire  :179-218  (TAUX, PRIX PAYÉ)
                                                 │ repartirLaTvaParTaux (R4, clé = taux)  :234
                                                 ▼
                                   CalculateurLigne (arithmétique)
                                                 │
ÉMISSION ── EmettreFactureAction ── ControleNatureOperation::verifier (EXONÉRATION autorisée ?) :144
                                 ├─ MentionsFiscales::pour (MENTIONS)  ── ContenuFiscal (SIGNATURE)
                                 ├─ Facture::serie (SÉRIE = type de client)
                                 └─ FactureEmise → DeclencherTransmissionPdp (CIRCUIT = professionnel ?)
CLÔTURE ── CloturerCaisseAction (tickets) → SoumissionEReporting (période)  [aucune CATÉGORIE B2C]

ACHAT ── ConstructeurLigneAchat::enregistrerUneLigne : TAUX = saisi ?? taux de la fiche produit  :267
       │ montantsDAchat : TVA = TVA facturée (aucun RÉGIME, aucune DÉDUCTIBILITÉ)
       └─ ValiderFactureAchatAction → IncrementerStock : COÛT DU STOCK = PU HT − remise − escompte (LigneAchat:92)
```

| Décision | Où elle se prend | Données utilisées | Données ignorées |
|---|---|---|---|
| Taux d'une ligne de vente | `ConstructeurLigneDocument.php:179-185` | Franchise, taux de la fiche produit, saisie pour un service, territoire du magasin | Catégorie fiscale du produit, territoire de destination (Corse et DOM pour une livraison), pays d'arrivée (vente à distance) |
| Exonération | Choix manuel de la nature, puis `ControleNatureOperation` à l'émission | Type et pays du client, établi en France, n° de TVA et VIES du jour, adresse, date et transport de livraison, bien ou service, frais accessoires, lieu fixé par la loi | Lieu de départ des biens, territoire du magasin (sauf « même DOM »), statut d'assujetti d'un professionnel français, preuves (contrôlées seulement après l'émission) |
| Prix payé hors TVA | `ContexteDeVente::retireLaTvaDuPrix()` et `ConstructeurLigneDocument.php:200` | Franchise, nature | — |
| Méthode de calcul (article par article ou HT de ligne) | `ContexteDeVente::depuisLeHtDeLigne()` 210-213 | Type de client, franchise | Statut d'assujetti |
| Mentions | `MentionsFiscales::pour()` 61-80, et `NatureOperation::mention()` | Lignes, nature, franchise, option pour les débits | Date d'émission (pas de table datée pour le passage CGI → CIBS) |
| Série et intitulé | `Facture::serie()` 596-599, `libelleDocument()` 572-575 | Type de client | Facture demandée par un particulier (complément 13) |
| Circuit de transmission | `Facture::relevantDeLeInvoicing()` 612-615 | Type de client | Établissement, champ de la TVA, règles de facturation, assujetti, entité publique, nature |
| Catégorie de transmission (EN 16931, B2C) | **Nulle part** | — | — |
| Agrégation e-reporting | `GenererSoumissionEReportingAction` 42-87 et `CalculateurPeriodeEReporting` | Régime (période), clôtures avec opérations | Jour, catégorie, taux, devise |
| Taux et TVA d'un achat | `ConstructeurLigneAchat.php:267, 299` | Saisie, sinon taux de vente de la fiche | Fournisseur, origine, biens ou services, autoliquidation, importation, déductibilité |
| Coût du stock | `LigneAchat::coutUnitaireHt()` 92-99, puis `StockLedgerService::receptionner()` 49-69 | PU HT, remise, escompte | TVA non récupérable, droits de douane, frais d'approche |
| Valeur du stock affichée | `ValeurStockApercu.php:59-64` | CMUP × quantité + TVA au taux de vente | — |

---

## 3. Doublons et contradictions

### 3.1 Doublons (même règle codée plusieurs fois, constantes dispersées)

**D1. « Vente sans TVA française » est encodée quatre fois, sous quatre formes différentes.**
1. `ContexteDeVente.php:93-96` (`exonere` : franchise ou nature) et `:215-218` (`sansTvaFrancaise` : pas de franchise et nature exonérée).
2. `LigneFacture.php:194-201` (`prixUnitaireTtcPaye`) : la règle est **redéduite** de l'écart `taux_tva_du_prix ≠ taux_tva` au lieu de lire la franchise et la nature du document.
3. `AjouteDesLignesDeVente.php:174` : `($document->franchise_en_base || $document->nature_operation->exonere()) && $ligne->service_id !== null`, recodé dans l'écran.
4. `NoterBordereauDetaxeAction.php:86-88` teste séparément `nature_operation !== VenteEnFrance`, `franchise_en_base` et `LigneFacture::scopeBiensTaxes()` (`montant_tva > 0`, `LigneFacture.php:155`).

Les quatre formes concordent aujourd'hui. Une nouvelle nature taxée à l'étranger (vente à distance sous OSS : TVA étrangère, pas de TVA française, mais une « TVA » sur la ligne) les ferait diverger.

**D2. Franchise et taux 0 : la règle est dans le domaine et dans l'écran, et l'écran lit le régime directement.**
- Domaine : `ConstructeurLigneDocument.php:180`.
- Écran : `AjouteDesLignesDeVente.php:326`, plus des lectures directes de `societe->estEnFranchiseDeTva()` dans `AjouteDesLignesDeVente.php:296, 298, 366`, `ProduitResource.php:163` et `ChoisitLaNatureDeLOperation.php:151, 192`.
- Seule `ContexteDeVente` devrait porter cette lecture.

**D3. La ventilation par taux existe en deux implémentations.**
- `VentileLaTvaParTaux.php:96-113` (`CalculateurLigne::totaliser`, clé `number_format(taux, 2)`) : écran, PDF, clôtures.
- `ContenuFiscal.php:317-327` (`Collection::sum` sur les centimes, clé = taux en centièmes) : contenu signé.
- Le résultat est identique aujourd'hui, mais la clé de regroupement (le taux seul) devra devenir (catégorie, taux) pour la facture électronique : les deux implémentations devront changer ensemble.
- Même remarque pour `ConstructeurLigneDocument::repartirLaTvaParTaux()` 239 (clé = taux).

**D4. La catégorie de l'opération est calculée trois fois.**
- `MentionsFiscales.php:66` (texte de la mention), `ContenuFiscal.php:200` et `:266` (code).
- Elle est signée deux fois, en code et en texte.

**D5. Les mentions sont recalculées à l'impression au lieu d'être relues dans le contenu signé.**
- `resources/views/factures/pdf.blade.php:105` et `avoirs/pdf.blade.php:53` appellent `MentionsFiscales::pour()`.
- Or `ContenuFiscal.php:201, 267` a signé ces mentions, et l'émetteur comme le bon d'avoir sont, eux, relus dans le contenu signé (`FigeSonEmetteur.php:382-389`, `AvoirPdfGenerator.php:41`).
- Au passage des références du CGI à celles du CIBS (1/1/2027, recherche §0), un duplicata imprimerait le nouveau texte. Cela contredit la règle « Le PDF reproduit le document tel qu'émis » (CLAUDE.md, caisse). La note de calcul (`MentionsFiscales::calcul()`, non signée) est un choix assumé.

**D6. Les textes de mention sont dispersés dans trois classes, sans date d'effet.**
- `NatureOperation.php:50-60`, `RegimeTva.php:39-44` (repris par `ProduitResource.php:163`), `MentionsFiscales.php:45-55`.
- La recherche (§0) recommande une table datée.

**D7. Les listes géographiques sont dispersées.**
- `TerritoireFiscal.php:30-33` (préfixes, EL) et `:38-41` (États membres, GR) : deux listes des 27, non dérivées l'une de l'autre.
- Codes DOM dans `TerritoireFiscal.php:65` et à nouveau, en dur, dans `TerritoireTva.php:45-47`.
- Corse « 20 » en dur dans `TerritoireTva.php:48`.

**D8. Le taux par défaut a neuf appelants, dont trois côté achats.**
- Côté vente : `ContexteDeVente.php:53`, `AjouteDesLignesDeVente.php:175, 294`, `ProduitResource.php:148`, `ParametresPrixDeVente.php:62`, `CatalogueDeDemonstration.php:41`.
- Côté achat : `ConstructeurLigneAchat.php:383`, `AjouteDesLignesDAchat.php:456, 503`.
- La source est unique (`Magasin::tauxTvaParDefaut()`), mais **le taux de vente du territoire sert de taux d'achat**.

**D9. Le calendrier d'e-reporting est tenu deux fois.**
- `CalculateurPeriodeEReporting` et `GenererSoumissionEReportingAction` font double emploi avec SUPER PDP (complément 11) et contredisent la décision E14 (un envoi par clôture journalière).
- Écart déjà connu sur la 2e décade (`CalculateurPeriodeEReporting.php:57`).

**D10. Le contrôle VIES est coupé en deux couches.**
- La consultation automatique se fait dans l'écran (`FactureResource.php:785-794`).
- La condition de fond est dans le domaine (`ControleNatureOperation.php:231-245`).
- Une émission sans écran (future API v1, étape 10) n'interrogerait jamais VIES et serait refusée faute de consultation du jour.

**D11. La validation du taux est répétée.**
- `CalculateurLigne.php:622-627` (0 à 100), formulaires (`AjouteDesLignesDeVente.php:290-292`, `ProduitResource.php:144-146`).
- Alerte de taux inhabituel en deux endroits (`AjouteDesLignesDeVente.php:367`, `ProduitResource.php:167`), heureusement par la même fonction (`TerritoireTva::aideSiTauxInhabituel`).

**D12. Les totaux d'achat sont présentés comme « à payer au fournisseur ».**
- `AjouteDesLignesDAchat.php:109-121` (« Montant payé au fournisseur … TTC ») et `FactureAchatResource.php:98` (« Total de la facture d'achat (à payer au fournisseur) »).
- Cette présentation n'est juste que si la TVA facturée est la TVA payée : elle cessera de l'être avec l'autoliquidation.

### 3.2 Contradictions (règles divergentes ou contraires à une décision)

**C1. Un seul taux sur la fiche produit pour la vente, l'achat, le stock et le barème.**
- `AjouteDesLignesDAchat.php:484-485` : « Le taux de la fiche s'applique aussi à cette ligne. »
- `ligneDepuisLeFormulaire()` 475 fusionne le taux saisi dans les attributs du produit. `ConstructeurLigneAchat::produitDeLaLigne()` 364 les écrit sur la fiche, avec un JET 270 (`verrouillerLeRegistreSiUnTauxChange()` 102-113).
- Ce même taux sert à la valorisation du stock (`ValeurStockApercu.php:60`) et au barème (`ConstructeurLigneAchat.php:181, 282`).
- **Conséquence : pour saisir à l'écran une facture d'achat intracommunautaire, facturée à 0 % par le fournisseur, il faut mettre le produit à 0 %, ce qui le vend à 0 % en caisse.** Inversement, une ligne d'achat au taux de vente de 20 % fait croire à de la TVA facturée qui n'existe pas.
- Divergence entre l'écran et le domaine : `ConstructeurLigneAchat.php:267` accepte un taux de ligne différent de celui de la fiche (`$donnees['taux_tva'] ?? $produit->taux_tva`), que l'écran interdit.

**C2. « Établi en France » (identification Sirene) est confondu avec le territoire de TVA.**
- `TerritoireFiscal::PAYS_FRANCE` (22) contient BL, MF et PM, qui figurent aussi dans `COLLECTIVITES_D_OUTRE_MER` (73), hors du territoire de TVA français. GF et YT sont des DOM hors TVA (art. 294-1).
- `Client.php:140-142` force `etabli_en_france = true` pour ces pays.
- `ControleNatureOperation.php:211` refuse alors « Prestation à un professionnel hors UE » pour un client de Saint-Barthélemy ou de Saint-Martin (« le client doit être établi hors de France »).
- Les règles de lieu les placent pourtant hors de la TVA française (recherche §1.4 et N9). La Guyane et Mayotte (N11 : pas de TVA) sont dans le même cas.
- Le circuit de transmission devra lui aussi distinguer « établi en France » (facture électronique) de « dans le champ de la TVA française » (deuxième condition de la FAQ).

**C3. Le lieu de départ des biens est toujours supposé en métropole.**
- `ControleNatureOperation::manquementDeLaDestination()` 172-190 ne regarde que la destination.
- Un magasin des Antilles ou de La Réunion, territoire pris en charge (`TerritoireTva.php:57-60`), qui expédie en métropole voit son export refusé (« la livraison reste dans le territoire de TVA de l'Union », 180).
- S'il expédie en Allemagne, la vente est classée en livraison intracommunautaire (188), alors que les DOM sont hors du territoire de TVA de l'Union (art. 294 ; c'est une exportation).
- Seul le cas « même DOM » est prévu (185). Le pays d'expédition réel demandé par l'éditeur n'existe pas, ni à la vente ni à l'achat.

**C4. Un franchisé est enfermé dans la vente en France, prestations de l'UE comprises.**
- `NatureOperation::proposees()` 169-171 et `ContexteDeVente::pourEnregistrer()` 80-82 n'autorisent que « Vente en France » avec la mention 293 B.
- La recherche (§1.5, « International ») indique qu'une prestation à un professionnel de l'UE est **taxable chez le preneur** même pour un franchisé, ce qui suppose un n° de TVA, une DES et la mention « Autoliquidation ». Une vente à distance au-delà de 10 000 € est taxable dans le pays d'arrivée.
- Règle métier à trancher (voir Q1).

**C5. Circuit de transmission contraire aux trois conditions officielles et à la décision E13.**
- `Facture.php:612-615` envoie tout professionnel en facture électronique.
- `DeclencherTransmissionPdpApresEmissionFacture.php:28` transmet même les ventes à 0 %.
- Or :
  - E13 et CLAUDE.md (« Une vente à 0 % n'est pas transmise tant que son motif d'exonération n'est pas géré ») imposent le refus ;
  - E7 et CLAUDE.md imposent « hors plateforme, avec un message » pour les clients publics et non assujettis, que le logiciel ne sait pas reconnaître ;
  - la FAQ et le complément 9 réservent la facture électronique aux opérations entre assujettis établis en France, dans le champ de la TVA française et soumises aux règles de facturation françaises.
- Sans effet aujourd'hui : la passerelle est factice.

**C6. Le type de client décide de quatre choses distinctes.**
- La série (`Facture::serie()` 596-599), le mode de calcul (`ContexteDeVente::depuisLeHtDeLigne()` 210-213), le circuit (`relevantDeLeInvoicing()`) et l'entrée en clôture (`Facture::scopeTickets()` 284-287).
- La facture demandée par un particulier (complément 13 : série des factures, circuit B2C) et le professionnel non assujetti ou l'entité publique (complément 9) cassent ces équivalences. Elles doivent devenir quatre décisions séparées.

**C7. La valeur du stock est affichée TTC, au taux de vente.**
- `ValeurStockApercu.php:59-64` et `CalculateurLigne::valeurAuCoutMoyen()` 276-288.
- Le stock d'un assujetti redevable se valorise au coût d'acquisition HT, la TVA récupérable étant exclue. Celui d'un franchisé, au coût **TTC d'achat**, la TVA n'étant pas récupérable.
- Le CMUP × (1 + taux de vente) ne correspond à aucun des deux. Il contredit aussi la demande (« coût du stock ») et préparera mal l'étape 5 (comptabilité).

**C8. Achats d'un franchisé : coût et bénéfice comptés hors taxe.**
- `LigneAchat::coutUnitaireHt()` 92-99 et le barème (`ConstructeurLigneAchat.php:308-311`) partent du HT, même quand la TVA d'achat n'est pas déductible (franchise).
- Le bénéfice affiché (`AjouteDesLignesDAchat.php:142-147`, `ParametresPrixDeVente.php:176`) divise le prix de vente par (1 + taux), alors que le franchisé encaisse le TTC sans TVA (B10).
- La marge réelle est donc sous-estimée du côté de la vente et surestimée du côté de l'achat.

**C9. Fiche produit en franchise.**
- `Produit::synchroniserPrixDeVente()` 381-398 tient un `prix_vente_ht` au taux de la fiche.
- En franchise, le HT d'une ligne est le TTC (`ConstructeurLigneDocument.php:180, 197`) : la fiche affiche un HT que la vente n'appliquera jamais.

**C10. Le pays d'un magasin des DOM s'écrit d'une seule façon, celui d'un client de deux.**
- `TerritoireTva::depuisAdresse()` 39-50 exige `FR` et un code postal 97x.
- `TerritoireFiscal::departementDom()` 112-129 accepte aussi `GP`, `MQ`, `RE`… pour un client ou une livraison.
- La règle de lecture d'une adresse n'est donc pas la même partout.

**C11. Le docblock de `RegimeTva` est périmé et mélange deux notions.**
- « Le régime de TVA du magasin » (`RegimeTva.php:8`), alors que le régime est porté par la société.
- L'énumération mélange le **statut** (franchise ou redevable) et la **cadence déclarative** (mensuel, trimestriel, simplifié). Le régime simplifié disparaît au 1/1/2027 (recherche §2.2).

**C12. Décisions prises mais pas encore codées (contradiction du code avec la règle écrite).**
- Complément 4 : preuve annulable par une entrée tracée, « Établie par » obligatoire, deux parties différentes pour les deux documents de transport d'une LIC. Aujourd'hui `AjouterPreuveOperationAction.php:258` accepte un émetteur vide, et `PreuvesRequises::manquantes()` 76-96 compte les documents, pas les parties.
- Complément 6 : avertir au retour d'un article déjà détaxé.
- E14 et complément 11 : un envoi par jour.
- Complément 9 : champs « assujetti » et « entité publique ».
- Complément 12 : achats à l'étranger.
- Compléments 13 à 15 : facture demandée par un particulier, « Transformer en facture ».
- R2 : prix de référence d'un service, absent de `Service`. Il conditionne le taux d'un service.

**Écart à signaler, sans contradiction de code.** La décision I2 et CLAUDE.md retiennent un n° de TVA « valide sur VIES le jour même » de l'émission (`ControleNatureOperation.php:79, 119-134`). La recherche (N6, condition 2) parle d'un n° communiqué avant le transport et valide à la date de la livraison, alors qu'une LIC peut être facturée jusqu'au 15 du mois suivant. Le code suit la décision ; à confirmer par l'expert-comptable si l'éditeur le souhaite.

---

## 4. Manques par rapport à la demande de l'éditeur

### 4.1 Achats

| Demande | État actuel | Preuve |
|---|---|---|
| Champ clair d'origine réelle des marchandises (France, UE, hors UE) | Absent. Ni pays d'expédition, ni pays de livraison, ni Incoterm. Le fournisseur n'a qu'un pays et un n° de TVA. | Migrations `factures_achat` et `fournisseurs` ; `Fournisseur.php:18-29` |
| Distinguer marchandises et prestations | **Impossible** : toute ligne d'achat porte un produit du catalogue. Une prestation, des frais de port, de transit ou de douane ne peuvent pas être saisis. | `LigneAchat.php:55-62` ; `ConstructeurLigneAchat.php:378-380` |
| Utiliser fournisseur, pays, n° de TVA et documents pour déterminer le régime | Aucun régime d'achat. Pas de statut du fournisseur (assujetti, établi en France), pas de VIES fournisseur, pas de référence de document de douane. | — |
| TVA facturée, autoliquidation, TVA déductible, montant à payer | Une seule TVA par ligne = TVA facturée (`lignes_achat.montant_tva`). Rien pour l'autoliquidation (AIC art. 256 bis et 258 C, livraisons art. 283-1, services art. 283-2), l'importation (art. 1695 II, base art. 292), la TVA non déductible (franchise, exclusions) ou la date de déduction. Pas de « net à payer » distinct du TTC. | `ConstructeurLigneAchat.php:263-301` |
| Coût du stock | PU HT − remise − escompte, sans droits de douane, frais d'approche ni TVA non récupérable. Les frais ne sont pas répartis entre les lignes. Le PU HT est limité à 2 décimales (`decimal(12,2)`) alors que le CMUP en a 4. | `LigneAchat.php:92-99` ; migration `lignes_achat` |
| Devise | `factures_achat.devise` existe, sans taux de change ni TVA en euros. | Migration `factures_achat` |
| E-reporting B2BInt « in » (art. 290 I 3°) | Absent de `PdpGatewayInterface`. | `PdpGatewayInterface.php:19-35` |
| Traçabilité | La facture d'achat n'est ni signée ni journalisée (sauf le JET 270 d'un taux de produit corrigé). | — |

### 4.2 Ventes

| Demande | État actuel | Preuve |
|---|---|---|
| Pays de destination réelle des biens | Enregistré **seulement** pour l'export, la livraison vers un DOM et la LIC ; vide pour une vente en France, donc pour une vente à distance. | `NoteSaNatureDOperation.php:36-55` |
| Lieu pertinent pour les services | Un seul booléen `lieu_specifique` (art. 259 A), pas de lieu. Pas de marqueur art. 259 B ni TRE. | Migration `services` |
| Particuliers et professionnels | `TypeClient` existe, mais ni « assujetti à la TVA », ni « entité publique », ni pays de résidence d'un particulier distinct de son adresse (le pays est sur la fiche complète, `ClientResource.php:277`, mais masqué dans la création rapide, `:247`, utilisée par la caisse et les factures). | — |
| Contrôle des LIC et des exportations | Fait, et bloquant (lot 19). | `ControleNatureOperation.php:89-245` |
| Ventes à distance et OSS | Absents : phase 2 de la décision I1 (seuil de 10 000 €, compteur sur l'année précédente et l'année en cours, OSS, taux des 27 par catégorie). Aussi absents : l'export enlevé par le client (N4), N10 et N11. | — |
| Calcul automatique de la TVA et mentions appropriées | Calcul fait. Les mentions des six natures existent, sans table datée. Aucune catégorie EN 16931, aucun VATEX, aucune catégorie B2C. | Grep nul (1.11) |
| Taux par catégorie fiscale et par territoire | Taux libre sur la fiche (B2) ; un service n'a pas de taux. | `Produit`, `Service` |
| Obligations annexes | Ni échéance de facture au 15 du mois suivant (K et AE), ni rappel de l'état récapitulatif (LIC) ou de la DES (AE). | — |
| TVA sur les encaissements | L'option pour les débits n'est qu'imprimée (`MentionsFiscales.php:75-77`). Ni ventilation des paiements par taux ou par nature, ni fr:212, ni `b2c_payments` (E5, E6). | — |

### 4.3 Fonctionnement souhaité

| Demande | État actuel |
|---|---|
| Préremplir les informations connues | La livraison est préremplie depuis l'adresse du client (`ChoisitLaNatureDeLOperation.php:295-314`). **La nature n'est jamais proposée automatiquement** : seules les natures impossibles sont retirées (`:187-195`). La recherche (§1.9) prévoit une proposition automatique. |
| Demander seulement ce qui manque ou ce qui compte | Les manquements ne sont listés **qu'à l'émission** (`EmettreFactureAction.php:144`), pas pendant la saisie. Côté achats, rien. |
| Afficher le régime appliqué et sa justification | Libellé et mention de la nature, note de calcul (`MentionsFiscales::calcul()`), consultation VIES sur la fiche. Pas d'écran « décision fiscale » avec les critères remplis ou manquants et la règle appliquée. |
| Bloquer ou signaler | Blocage à l'émission (natures) et signalement après (preuves, `PreuvesRequises`). Rien sur les achats. |
| Le pays ne suffit jamais | **Respecté** : nature choisie et conditions cumulatives (n° de TVA, VIES, adresse, transport, nature des lignes). À conserver dans le moteur. |
| Traçabilité des décisions | La nature, la livraison, la consultation VIES, la franchise et les mentions sont signées (`ContenuFiscal.php:167-178, 199-207`). Ne sont signés ni les **données d'entrée** (statut du client, critères évalués), ni la **règle appliquée**, ni sa **version**, ni une éventuelle décision forcée par un utilisateur. Rien pour les achats. |
| Tests automatisés par scénario | Bons pour les six natures (`NatureOperationTest`, 29 tests). Aucun scénario d'achat fiscal. |
| Moteur partagé (Achats, Ventes, Stocks, déclarations) | Aucun module commun : ventes dans `Facturation`, achats dans `Achats` sans règle, stock dans `Stock`, déclarations inexistantes (étape 5). |

### 4.4 Autres manques techniques

- VIES n'est pas derrière une interface (`ClientVies.php:188`, classe concrète). Les tests passent par `Http::fake`. Un moteur testable et un mode de démonstration demandent une interface et un faux, comme pour les autres fournisseurs externes (CLAUDE.md).
- Aucun agrégat de TVA collectée ou déductible par période, par taux et par case de la CA3. Pas d'écriture comptable (étape 5).
- Aucune date d'effet du régime de TVA de la société. L'historique n'existe que dans le JET 270 de chaque magasin.

---

## 5. Ce qui est solide, validé par l'éditeur, et ne doit pas changer

| Règle | Décision ou source | Code | Tests qui la verrouillent |
|---|---|---|---|
| Calcul en entiers, arrondi unique, jamais de flottant intermédiaire | Audit, CLAUDE.md | `CalculateurLigne` (tout le fichier) | `CalculateurLigneTest` (42, dont des tests aléatoires exhaustifs) |
| Seul le prix de vente TTC s'arrondit, une seule fois, à l'enregistrement du produit | CLAUDE.md « Prix et TVA des ventes » | `CalculateurLigne::prixTtcArrondi()` 247-256 ; `BaremePrixDeVente` 110-126 | `test_le_prix_de_vente_ttc_ne_sarrondit_qu_une_fois`, `BaremePrixDeVenteTest` |
| Particulier : article par article depuis le TTC | CLAUDE.md | `montantsDeVente()` 73-81 | `test_une_vente_multiplie_separement_le_ht_et_le_ttc_par_la_quantite` |
| Professionnel : HT de ligne arrondi une fois ; TVA par taux répartie par cumul (**R4**) | E1, R4 | `montantHtDeLigneProfessionnelle()` 117-127, `repartirLaTva()` 148-165, `ConstructeurLigneDocument::repartirLaTvaParTaux()` 234-252 | `RepartitionTvaParCumulTest`, 2 000 factures aléatoires |
| Retours partiels recalculés comme la facture, le dernier soldant la ligne | K2 | `retourDeLigneProfessionnelle()` 191-217, `retourCumule()` 428-437 | `AvoirActionsTest`, `CalculateurLigneTest` |
| Vente exonérée payée au prix hors taxe | K3 | `ContexteDeVente::retireLaTvaDuPrix()`, `taux_tva_du_prix` | `NatureOperationTest` |
| Franchise : 0 % au même prix TTC, mention 293 B lue sur le document | B10 | `ConstructeurLigneDocument.php:180`, `MentionsFiscales.php:73-74` | `FranchiseEnBaseTest` (14) |
| Achat : HT = quantité × PU HT, puis TVA sur ce HT | CLAUDE.md | `montantsDAchat()` 93-103 | `test_un_achat_calcule_sa_tva_sur_le_ht_de_la_ligne` |
| CMUP au dix-millième, coût net de remise et d'escompte ; retour au fournisseur au coût de sa ligne ; valeur jamais négative | C1, K1, PCG 213-31 | `StockLedgerService`, `coutMoyenApresUneSortie()` | `StockLedgerServiceTest`, `AvoirFournisseurActionsTest` |
| Document émis jamais modifié ; correction par avoir et nouvelle facture | CLAUDE.md, B13 | `ProtegeLesDonneesValidees`, `FigeLeClient`, `FigeSonEmetteur` | `InalterabiliteTest`, `TypeClientFigeTest`, `EmetteurFigeTest` |
| Contenu signé : blocs « document » et « contexte », vérification de l'intégrité, grands totaux | Art. 286 (étape 3) | `ContenuFiscal`, `RegistreFiscal`, `VerificateurIntegrite` | `EmissionSigneeTest`, `VerificateurIntegriteTest` (49) |
| Une nature par document ; l'avoir la reprend, sans mélange de natures, de franchises ou de types | I1, BR-O-11 | `CreerAvoirAction.php:101-117` | `test_un_avoir_ne_melange_pas_des_factures_de_natures_differentes` |
| Refus d'émettre si le client ou le régime a changé depuis l'enregistrement | B10, E1 | `ContexteDeVente.php:140-166` | `FranchiseEnBaseTest`, `TypeClientFigeTest` |
| VIES : consultation du jour (Paris), conservée, en ajout seul ; la dernière du jour décide | I2 | `ControleNatureOperation::consultationDuJour()`, `ConsultationVies` | `NatureOperationTest`, `ConsultationViesTest` |
| Conditions cumulatives, tous les manquements dits d'un coup, retour proposé à la vente en France | I1, CLAUDE.md | `ControleNatureOperation`, `NatureOperationRefuseeException` | `test_un_export_refuse_dit_tout_ce_qui_manque` |
| Détaxe : le ticket reste à la TVA française, BVE noté | I3 | `NoterBordereauDetaxeAction` | `BordereauDetaxeTest` (19) |
| Preuves : références enregistrées en ajout seul, rappel de ce qui manque | I4 | `PreuvesRequises`, `AjouterPreuveOperationAction` | `PreuvesOperationTest` (22) |
| Taux libre, 20 % par défaut (8,5 % aux Antilles et à La Réunion), alerte sans blocage | B2, X8 | `TerritoireTva` | `TerritoireTvaTest`, `test_un_taux_de_service_inhabituel_est_signale_sans_etre_refuse` |
| Paramètres de conformité journalisés (JET 270) sous le verrou du registre | Étape 3 | `JournaliseSesParametresDeConformite` | `JournalEvenementsTest`, `ParametresFiscauxSocieteTest` |

**Contraintes que le moteur doit respecter :**
1. Il produit des **entrées** pour `CalculateurLigne` et ne recalcule jamais lui-même un montant.
2. Une fois émis, un document ne relit plus jamais le moteur. Il relit sa décision **signée**, comme il relit son émetteur.
3. Ajouter un champ fiscal figé, c'est l'ajouter au bloc `document` (et au vérificateur) ou au bloc `contexte`, et changer `ModuleFiscal::VERSION`.

---

## 6. Points d'intégration naturels d'un moteur centralisé

### 6.1 Où brancher le moteur

| # | Point d'intégration | Aujourd'hui | Demain |
|---|---|---|---|
| P1 | `ContexteDeVente` (`ContexteDeVente.php:33-219`) | Contexte à 4 champs, avec des prédicats dispersés | **Décision fiscale de vente** produite par le moteur : régime, circuit, méthode de calcul, prix hors taxe ou TTC, mentions, conditions, justification. Les méthodes `exonere()`, `retireLaTvaDuPrix()` et `calculeDepuisLeHtDeLigne()` deviennent des lectures de la décision. |
| P2 | `ConstructeurLigneDocument::construire()` 179-185 | Décide du taux | Reçoit du moteur, ligne par ligne : taux appliqué, taux du prix, catégorie EN 16931, VATEX, catégorie B2C. Garde la construction et l'appel à `CalculateurLigne`. |
| P3 | `ConstructeurLigneDocument::repartirLaTvaParTaux()` 234-252 ; `VentileLaTvaParTaux` ; `ContenuFiscal::lignesEtTotaux()` | Clé = taux | Clé = (catégorie, taux), fournie par le moteur, une seule implémentation (supprime D3). R4 est inchangée à l'intérieur de chaque clé. |
| P4 | `NatureOperation` | Énumération porteuse de règles | Catalogue des **régimes** du moteur, natures du lot 19 comprises, avec une table datée des mentions et des VATEX (supprime D6). |
| P5 | `ControleNatureOperation` | Appelé à l'émission seulement | Évaluateur des conditions du moteur, appelé **pendant la saisie** (aperçu : ce qui manque) et à l'émission (blocage). La consultation VIES automatique quitte l'écran pour le domaine (D10). |
| P6 | `TerritoireFiscal` et `TerritoireTva` | Statiques | Module « géographie fiscale » du moteur : pays et code postal → territoire de TVA, États membres, DOM, collectivités, territoires exclus. Il sépare clairement **identification** (SIREN) et **territoire de TVA** (C2), et tient le **territoire de départ** (C3). |
| P7 | `MentionsFiscales` et vues PDF | Recalcul au rendu | Mentions issues de la décision, signées, relues au rendu (supprime D5). |
| P8 | `ContenuFiscal::contexteDeLaFacture()` 192-226, `contexteDeLAvoir()` | Signe nature, VIES et mentions | Ajoute un bloc `decision_tva` : version des règles, régime, entrées utiles (statut et pays du client, pays de départ et d'arrivée, n° de TVA vérifié), critères évalués, décision forcée éventuelle (auteur, motif). |
| P9 | `Facture::serie()`, `relevantDeLeInvoicing()`, `scopeTickets()` | Type de client | Série, circuit (facture électronique, B2BInt sortant, B2C, hors plateforme, aucun) et clôture décidés séparément (C6), le circuit par le moteur (supprime C5). |
| P10 | `DeclencherTransmissionPdpApresEmissionFacture` et `PdpGatewayInterface` | Transmet tout professionnel | Lit le circuit et les codes de la décision ; refuse ce que le moteur marque « non transmissible » (E13, E7) ; ajoute B2BInt entrant et sortant, transactions et paiements B2C (étape 2). |
| P11 | `CloturerCaisseAction`, `ClotureCaisse::lignesAVentiler()` | Agrégat bien ou service + taux | Agrégat (jour, catégorie B2C, taux, devise) depuis les catégories signées des lignes. Suppression de `CalculateurPeriodeEReporting` (complément 11). |
| P12 | `ConstructeurLigneAchat::enregistrerUneLigne()` 263-301 et `produitDeLaLigne()` 353-386 | Taux = saisi ou celui de la fiche ; la ligne corrige le taux de vente | **Décision fiscale d'achat** : régime (France, AIC, autoliquidation 283-1 ou 283-2, importation, achat à un franchisé), taux facturé, TVA autoliquidée (collectée et déductible), TVA déductible, net à payer. Le taux d'achat cesse de corriger la fiche (supprime C1). |
| P13 | `LigneAchat::coutUnitaireHt()` 92-99 | PU HT net | **Coût d'entrée en stock** fourni par le moteur : net HT + TVA non récupérable + quote-part des droits de douane et des frais d'approche. `StockLedgerService::receptionner()` est inchangé ; il reçoit ce coût. |
| P14 | `ValeurStockApercu` et `CalculateurLigne::valeurAuCoutMoyen()` | TTC au taux de vente | Valeur au coût (CMUP), sans TVA ajoutée (supprime C7). |
| P15 | `Client`, `Fournisseur`, `CopieClient` | Champs épars | **Profil fiscal du tiers** : assujetti, entité publique, pays d'établissement, établi en France, n° de TVA et consultations VIES, identifiant étranger et son schéma. Figé à l'émission. |
| P16 | `Societe` (et `Magasin` pour le territoire) | `regime_tva`, `option_debits_tva`, `numero_tva` | **Profil fiscal du vendeur** : statut (franchise ou redevable), cadence, option pour les débits, et plus tard OSS (date), EORI, adhésion à PABLO. Le territoire reste celui de chaque magasin. |
| P17 | Écrans `AjouteDesLignesDeVente`, `ChoisitLaNatureDeLOperation`, `AjouteDesLignesDAchat` | Recodent des règles (D1, D2) | N'appellent plus que l'aperçu du moteur (déjà le modèle de `ConstructeurLigneDocument::apercu()`) : régime proposé, ce qui manque, justification. |
| P18 | Étape 5 (comptabilité, état de TVA) | — | Lit les décisions de vente et d'achat (collectée, déductible, autoliquidée, par taux et par case), sans rien recalculer. |

### 6.2 Forme proposée (à valider avant tout développement)

1. **Un module `app/Domain/Fiscalite`** (ou `Tva`), sans dépendance vers les écrans.
   - Une façade et trois entrées :
     - `decisionDeVente(DemandeVente): DecisionVente` ;
     - `decisionDAchat(DemandeAchat): DecisionAchat` ;
     - `coutDEntreeEnStock(DecisionAchat, ligne): string` (au dix-millième).
   - Plus des agrégateurs pour les déclarations.
   - Des objets valeur immuables (`readonly`), comme `ContexteDeVente`, `Emetteur` et `CopieClient` aujourd'hui.
2. **Des règles en données datées** : une table PHP versionnée (régime → mention, VATEX, catégorie EN 16931, catégorie B2C, circuit, preuves attendues, conditions), avec des dates d'effet pour le passage CGI → CIBS. La « version des règles » est signée avec chaque décision.
3. **Le calcul inchangé** : le moteur ne touche pas `CalculateurLigne`. Il lui donne `taux_tva`, `taux_tva_du_prix` et la méthode (article par article ou HT de ligne).
4. **Une décision tracée** : la décision de vente est signée (P8). La décision d'achat est enregistrée sur la facture d'achat à sa validation, figée (nouvelles colonnes), et inscrite au journal d'audit. Une décision forcée par un utilisateur autorisé exige un motif et s'inscrit au journal.
5. **Des interfaces pour l'extérieur** : `VerificateurNumeroTva` (VIES) avec un faux, comme les autres fournisseurs externes.
6. **Un découpage en lots**, chacun vert sur SQLite et PostgreSQL, en modifiant les migrations de création (aucune migration d'ajout ni code de compatibilité, CLAUDE.md) :
   1. L1 : extraire les règles existantes derrière le moteur sans changer un seul résultat (D1 à D11) ; tous les tests restent verts.
   2. L2 : profils fiscaux du tiers et du vendeur, géographie (C2, C3, C10), proposition automatique du régime, conditions pendant la saisie.
   3. L3 : achats (régimes, autoliquidation, importation, biens ou services, déductibilité, net à payer).
   4. L4 : coût du stock (C7, C8) et frais d'approche.
   5. L5 : catégories EN 16931 et B2C, circuit, E13 et E7 (préparation de l'étape 2).
   6. L6 : phase 2 de la décision I1 (vente à distance et OSS, export enlevé par le client), table des taux par catégorie et par territoire.

---

## 7. Vérification et compléments de la recherche fiscale

### 7.1 Ce que le code a corrigé depuis la recherche

- La numérotation porte maintenant le code du magasin (`DocumentNumberingService.php:103-105`, J1) : le défaut §3.7 est corrigé.
- La clôture est numérotée selon l'année de la journée (`CloturerCaisseAction.php:94`) : le défaut §2.4 sur la ligne 59 est corrigé.
- Les clôtures sans opération ne sont plus déclarées (`GenererSoumissionEReportingAction.php:58`) : corrigé en partie. Le regroupement par période demeure (E14 non appliquée).
- Le régime de TVA, l'option pour les débits et le n° de TVA sont portés par la société (§3.8) : corrigé. `IdentiteMagasin.php` (§3.9) n'existe plus.
- La table `clients` a un SIREN et un identifiant étranger (§1.12) : corrigé. Le docblock de `RegimeTva` cite désormais les art. 290 et 290 A et les art. 242 nonies O et P (§2.4) : corrigé, sauf le mot « magasin » (C11).
- Toujours vrai : `Facture::relevantDeLeInvoicing()` renvoie `estProfessionnel()` (§1.12) ; l'échéance de la 2e décade est le dernier jour du mois (§2.4).

### 7.2 Ce que les deux PDF confirment ou changent

**Art. 290 du CGI, version du 21/02/2026, lu dans le PDF : texte officiel.**
- **Monaco (I 4° a)** : les livraisons expédiées vers Monaco, les acquisitions en provenance de Monaco et les prestations dont le preneur, assujetti ou non, ou le prestataire est établi à Monaco relèvent **de l'e-reporting, par le texte**. La recherche (N1) le classait en source secondaire. La TVA reste française (convention de 1963). Seul le cas d'un professionnel monégasque immatriculé en France reste à préciser.
- **I 4° b** : les acquisitions intracommunautaires **non soumises** à la TVA (art. 258 D I, par exemple celles d'un franchisé sous le seuil de 10 000 €) sont dans le champ de l'e-reporting. Ce cas est absent de la recherche ; le complément 10 l'avait noté.
- **I 3° a, b et c** : acquisitions intracommunautaires (art. 258 C), livraisons en France par un assujetti non établi, services de l'art. 259 1° et de l'art. 259 A acquis auprès d'un non établi. Ce sont les trois cas d'achat à couvrir (B2BInt, direction « in »). L'importation n'y figure pas, ce qui confirme qu'elle est hors e-reporting (recherche §1.8).
- **I 2° a** : les ventes à distance « réputées ne pas se situer en France » (art. 258 A I 1°) sont **dans** l'e-reporting par le texte. Le principe est confirmé (recherche N7, « certitude moyenne ») ; la catégorie TNT1 reste à confirmer.
- **I 2° b** : les livraisons dont le lieu est en France (art. 258) à un non-assujetti couvrent aussi l'export à un particulier, dont le lieu est le départ en France. La catégorie (TNT1) reste en source secondaire.
- **Exclusion** : le texte exclut toute opération « exonérée en application des articles 261 à 261 E », sans autre condition. La recherche (§1.2, « Champ de l'e-reporting ») retient deux conditions cumulées (exonération des art. 261 à 261 E **et** dispense de facture), d'après la doctrine. L'écart est **à revérifier** ; il est sans effet pratique, PILIQI ne gérant aucune opération des art. 261 à 261 E (complément 10 : refus ou signalement).
- **NOTA** : l'article est abrogé au 1/1/2027 par l'ordonnance n° 2025-1247, mais maintenu jusqu'à sa reprise par les mesures réglementaires des art. L. 215-39, L. 216-55 et L. 216-56 du CIBS. C'est un argument de plus pour la table datée (P4).

**FAQ impots.gouv.fr (16/01/2026) : doctrine.** Elle confirme la recherche (§1.2) et le complément 9 :
- particuliers en France ou à l'étranger : e-reporting ;
- professionnels établis hors de France : e-reporting ;
- facture électronique si l'opération réunit les trois conditions (assujettis établis en France, champ de la TVA française, règles de facturation françaises).

Le code ne sait évaluer aucune des trois conditions (C2, C5).

### 7.3 Compléments côté achats

La recherche ne leur consacrait que trois puces (§1.8). Les règles ci-dessous sont de **connaissance générale**, non relues dans cette session, à faire confirmer par l'expert-comptable avant de coder.

| Cas | Règle à confirmer | Données nécessaires |
|---|---|---|
| Achat en France auprès d'un redevable | TVA facturée, déductible si la facture est conforme et l'achat affecté à l'activité taxée. Déduction des biens à la livraison ou à la facture ; des services au paiement, sauf option du fournisseur pour les débits. | Date de facture, date de paiement (services), option du fournisseur |
| Achat à un fournisseur en franchise | Aucune TVA (mention 293 B sur sa facture). | Statut du fournisseur |
| Acquisition intracommunautaire de biens (art. 256 bis, lieu art. 258 C) | Fournisseur identifié dans un autre État membre, bien transporté vers la France. L'acheteur autoliquide la TVA française au taux du bien (collectée et déductible), avec une exigibilité au 15 du mois suivant, ou à la facture si elle est antérieure (à vérifier). Coût du stock HT. Pour un franchisé : AIC soumise au-delà de 10 000 € (régime dérogatoire, art. 256 bis I 2°, à vérifier), avec une TVA autoliquidée **non déductible**, donc incluse dans le coût ; en deçà, AIC non soumise (art. 258 D I), mais dans l'e-reporting (art. 290 I 4° b). | Pays de départ, n° de TVA du fournisseur (VIES), date de livraison, taux du bien |
| Livraison de biens en France par un non-établi (art. 283-1) | Autoliquidation par l'acheteur assujetti identifié en France. E-reporting (art. 290 I 3° b). | Établissement du fournisseur |
| Services d'un prestataire non établi (art. 259-1° et 283-2) | Autoliquidation ; déductible selon les règles habituelles ; non déductible pour un franchisé. E-reporting (art. 290 I 3° c). | Biens ou services, établissement du prestataire |
| Importation (biens hors UE) | Autoliquidation obligatoire sur la déclaration de TVA depuis le 1/1/2022 (art. 1695 II). Base = valeur en douane + droits et taxes, TVA exclue, + frais accessoires jusqu'au premier lieu de destination (art. 292). **Droits de douane dans le coût du stock**, TVA hors coût si elle est déductible. Hors e-reporting. | N° et date de la déclaration en douane (MRN), valeur en douane, droits, frais, EORI |
| Escompte du fournisseur | Aujourd'hui il baisse le coût sans changer les totaux (migration `factures_achat`, 36-39). Faut-il réduire la base de la TVA déductible quand l'escompte figure sur la facture ? | Incertain |
| Coût d'acquisition des stocks | Prix d'achat, droits de douane, taxes non récupérables, frais de transport et de manutention, moins remises, rabais et escomptes (PCG art. 213-31, cité par `LigneAchat.php:85-91` pour les remises ; périmètre exact à vérifier). | Frais d'approche par facture et clé de répartition |

---

## 8. Règles métier à soumettre à l'éditeur (protocole, point 4)

- **Q1** (C4). Un franchisé peut-il facturer une prestation à un professionnel de l'UE en autoliquidation, ou un export avec la mention d'exonération, plutôt que d'être limité à « Vente en France » avec la mention 293 B ? À faire confirmer par l'expert-comptable.
- **Q2** (C1). Séparer sur la fiche produit le taux de vente du taux appliqué aux achats ? Proposition : le taux d'achat est porté par la ligne et décidé par le moteur, et ne corrige plus jamais la fiche.
- **Q3** (C7, C8). La valeur du stock s'affiche au coût, sans TVA, et le coût d'un franchisé inclut la TVA non récupérable. Confirmer, ainsi que l'affichage du bénéfice en franchise (sur le TTC encaissé).
- **Q4** (C6, complément 9). Un professionnel **non assujetti** (association, entité publique) : calcul depuis le HT de ligne ou article par article ? Série des factures, hors plateforme.
- **Q5** (C3). Un magasin des Antilles ou de La Réunion qui expédie en métropole ou dans l'UE : à gérer dès la refonte (régime d'export depuis un DOM), ou à refuser avec un message ?
- **Q6.** Proposer la nature automatiquement (recherche §1.9), tout en laissant le vendeur confirmer : oui ou non ?
- **Q7.** Qui peut forcer une décision du moteur (par exemple, garder la TVA française malgré des conditions d'exonération réunies) ? Avec quel motif et quelle trace ?
- **Q8.** Achats en devise : taux de change saisi, ou pris à une source officielle ? Base de la TVA en euros.
- **Q9.** Frais d'approche (transport, transit, droits) : répartis entre les lignes au prorata de la valeur ou de la quantité ?
- **Q10.** La facture d'achat validée doit-elle devenir inaltérable et journalisée comme une vente (décision d'achat figée), ou rester modifiable sous le droit de valider (décision C2 actuelle) avec un nouveau calcul de la décision ?
- **Q11** (écart VIES). Garder « valide le jour de l'émission » (décision I2), ou exiger aussi la validité à la date de livraison pour une LIC facturée après la livraison ?
