# Base réglementaire du moteur de TVA centralisé (achats, ventes, mentions, déclarations)

Rédigé le 11/10/2026, pour la demande de l'éditeur du même jour (`scratchpad/tva/demande-editeur.md`). C'est une analyse, pas un développement : aucun fichier du projet n'est modifié.

Le code cité est celui de l'instantané en lecture seule (commit 9c9ee25). Les chemins sont relatifs à `scratchpad/tva/instantane`.

Ce document complète la synthèse `scratchpad/regles-tva-international.md` du 09/10/2026, appelée ici « la recherche », sans la refaire. Il porte d'abord sur les achats, qui n'y étaient traités qu'en dix lignes (section 1.8).

---

## 0. Méthode, accès aux sources et niveaux de certitude

**Accès réseau ce jour.** Les sites suivants sont restés injoignables depuis la machine : legifrance.gouv.fr, bofip.impots.gouv.fr, impots.gouv.fr, douane.gouv.fr, eur-lex.europa.eu, taxation-customs.ec.europa.eu, entreprendre.service-public.fr, economie.gouv.fr. L'outil de lecture ne résout pas leur nom de domaine, et le proxy refuse le tunnel à curl (code 403). Seul raw.githubusercontent.com répond.

Les textes officiels n'ont donc été vus qu'en **extraits de moteur de recherche**, jamais en page complète, sauf quatre documents lus en entier :
- les deux PDF fournis par l'éditeur : l'art. 290 du CGI, version en vigueur depuis le 21/02/2026, imprimée le 11/10/2026 à 12:01 ; la FAQ impots.gouv.fr « Mes clients sont à la fois des entreprises et des particuliers », publiée le 15/11/2024 et modifiée le 16/01/2026 ;
- le schematron officiel EN 16931 (dépôt ConnectingEurope), téléchargé ce jour ;
- la liste de codes VATEX (dépôt OpenPEPPOL, `structure/codelist/VATEX.xml`), téléchargée ce jour ;
- la spécification SUPER PDP 1.36.0.beta de l'instantané (`tests/Fixtures/SuperPdp/openapi.json`).

**Niveaux de certitude**

| Code | Sens |
|---|---|
| **TO-lu** | Texte officiel lu en entier. |
| **TO-ext** | Texte officiel vu en extrait de moteur de recherche ; sa formulation exacte n'a pas été relue. |
| **Doc-ext** | Doctrine (BOFiP, douane, FAQ DGFiP, Bercy Infos) vue en extrait. |
| **SS** | Source secondaire (cabinet, éditeur, blog). « SS concordantes » : au moins deux sources indépendantes disent la même chose. |
| **Rech** | Repris de la recherche du 09/10/2026 sans nouvelle vérification. |
| **À vérifier** | Ne doit pas être codé comme une règle avant confirmation. |
| **Proposition** | Choix de conception. Ce n'est pas une règle de droit. |

**Règle de lecture.** Un point « À vérifier » n'est jamais présenté comme une règle. Un numéro de ligne de la CA3 n'est donné que s'il est sûr, ou il est marqué.

---

## 1. Calendrier, références d'articles et versions

| # | Ce qui est établi | Certitude | Source |
|---|---|---|---|
| 1 | **Recodification.** L'ordonnance n° 2025-1247 du 17/12/2025 (JO du 20/12/2025) transfère dans le code des impositions sur les biens et services (CIBS) les dispositions législatives de la TVA. L'art. 290 du CGI (version du 21/02/2026, modifiée par la loi n° 2026-103 du 19/02/2026, art. 123) porte « Abrogé par Ordonnance n° 2025-1247 du 17 décembre 2025 – art. 9 ». Son NOTA dit qu'il est abrogé à compter du 1er janvier 2027, mais maintenu en vigueur jusqu'à sa reprise par les mesures réglementaires prévues aux art. L. 215-39, L. 216-56 (second alinéa) et L. 216-55 (dernier alinéa) du CIBS. | TO-lu | PDF de l'éditeur |
| 2 | **Report.** L'ordonnance n° 2026-671 du 27/07/2026 (JO du 28/07/2026) décale l'entrée en vigueur du transfert du 1/9/2026 au **1/1/2027**. | Doc-ext | BOFiP ACTU-2026-00126 ; rapport au Président de la République (JORFTEXT000054497139) |
| 3 | **Anciennes références sur les factures.** Les références au CGI restent admises jusqu'au **30/06/2028**, au lieu du 31/12/2027. Il s'agit d'une équivalence prévue par l'art. 46 II de l'ordonnance 2025-1247 modifiée, et non d'une simple tolérance. **Tranche le point 8 de la section 4.1 de la recherche** : le 31/12/2027 était la date antérieure à l'ordonnance de juillet 2026. | Doc-ext, SS concordantes | ACTU-2026-00126 ; Legifiscal ; Aequitas ; phprince-avocat |
| 4 | **Textes réglementaires.** Les annexes II, III et IV du CGI restent applicables jusqu'à leur reprise dans la partie réglementaire du CIBS. L'art. 242 nonies A de l'annexe II (mentions des factures) reste donc la base. | Doc-ext | BOI-RES-TVA-000253 du 18/02/2026 |
| 5 | **Décret attendu.** Un décret doit fixer les mentions des factures selon la nature des opérations. Les formules CIBS qui circulent sont provisoires. | SS | Legifiscal (actualité 4650, septembre 2026) |
| 6 | **Numéros CIBS annoncés.** Aucun n'a été lu sur Légifrance : 262 ter I → L. 213-13 ; 262 I → L. 213-14 ; 259-1° et 283-2 → L. 211-92 ; 294 → L. 211-7 ; 293 B → L. 233-3 (Aequitas, Synapx), mais L. 223-3 ou L. 233-1 selon d'autres sources. | SS divergentes, **À vérifier** | Legifiscal ; Aequitas ; Portail auto-entrepreneur |
| 7 | **Calendrier de la réforme.** Réception obligatoire pour toutes les entreprises depuis le 1/9/2026. Émission et e-reporting : GE et ETI depuis le 1/9/2026 ; PME et micro-entreprises au 1/9/2027. | Rech (TO) ; FAQ (PDF) | Recherche §0 |
| 8 | **Quatre nouvelles mentions** (art. 242 nonies A de l'annexe II, modifié par le décret n° 2022-1299 du 7/10/2022) : SIREN du client ; adresse de livraison si elle diffère de celle du client ; catégorie de l'opération (biens, services ou les deux) ; option pour les débits lorsqu'elle est exercée. Elles suivent le calendrier de l'émission : GE et ETI au 1/9/2026, PME et micro-entreprises au 1/9/2027. | SS concordantes | Ma facture électronique ; Legifiscal 3925 ; Lefebvre Dalloz (cité par la recherche) |
| 9 | **Régime simplifié** supprimé au 1/1/2027 (loi n° 2025-127, art. 38). | Rech (TO) | Recherche §2.2 |
| 10 | **EORI.** Format FR + SIREN. Les EORI fondés sur le SIRET seront désactivés au second semestre 2026, à une date que la douane (bureau COMINT1) communiquera. L'EORI est donc une donnée de la **société**, pas du magasin. | Doc-ext (Bercy Infos), La Poste concordante | economie.gouv.fr, fiche « Numéro EORI » |

**Conséquence pour le moteur (Proposition)**
- Toute mention qui cite un article est lue dans une **table datée**, choisie selon la date d'émission du document : CGI jusqu'au 31/12/2026, puis CIBS une fois les numéros confirmés.
- Un document émis garde la mention signée à son émission ; c'est déjà la règle de `MentionsFiscales`.
- L'art. 242 nonies A admet aussi « la référence à la disposition pertinente du CGI **ou de la directive** 2006/112/CE, ou toute autre mention indiquant que l'opération bénéficie d'une exonération ». Une référence à la directive (art. 138, 146, 44 et 196) **ne change pas avec la recodification** (TO-ext ; recherche §0). C'est une option à soumettre à l'éditeur.
- Aujourd'hui, ces références sont écrites en dur : `app/Domain/Facturation/Enums/NatureOperation.php:50-60` et `app/Domain/Tenancy/Enums/RegimeTva.php:39-44`.

---

## 2. Règles de droit qui structurent le moteur (achats et ventes)

### 2.1 Le pays ne suffit jamais

Le pays du fournisseur ou du client ne détermine jamais seul la TVA.

| Ce qui décide | Pourquoi | Certitude |
|---|---|---|
| **Biens : d'où part le bien et où il arrive**, pas le pays du fournisseur. | AIC : bien expédié d'un autre État membre vers la France (art. 256 bis et 258 C). Importation : bien qui entre sur le territoire de TVA de l'UE. Un fournisseur allemand peut livrer depuis un stock en France (art. 283-1, al. 2) ; un fournisseur chinois peut avoir dédouané lui-même en France et livrer en l'état (BOI-TVA-DECLA-10-10-20 : autoliquidation par le client identifié en France). | TO-ext, Doc-ext |
| **Territoire réel, repéré par le pays et le code postal.** | Canaries, Ceuta et Melilla, Åland, mont Athos, collectivités d'outre-mer et DOM sont hors du territoire de TVA de l'UE : un achat qui en vient est une importation. L'Irlande du Nord (XI) suit les règles de l'UE pour les biens et celles d'un pays tiers pour les services. Monaco est traité comme la France. | Rech (Doc) ; COM(2020) 360 pour XI (TO-ext) |
| **Qui a transporté, et qui a dédouané.** | Dédouanement par le fournisseur, puis livraison en France : livraison interne d'un fournisseur non établi (art. 283-1). Dédouanement par le magasin : importation, TVA autoliquidée par lui. L'Incoterm (DDP, DAP…) est un indice ; la déclaration en douane est la preuve. | Doc-ext (BOI-TVA-DECLA-10-10-20) ; douane |
| **Services : statut du preneur et nature du service**, pas le pays du prestataire. | Règle générale entre assujettis : lieu du preneur (art. 259-1°). Exceptions de l'art. 259 A : immeuble, transport de passagers, restauration, location de courte durée d'un moyen de transport, accès à une manifestation. Ce dernier article a été modifié au 1/1/2025 par la loi n° 2023-1322. | TO-ext |
| **Établissement du fournisseur en France.** | Un fournisseur étranger qui a un n° de TVA FR (représentant fiscal) n'est pas pour autant « établi en France » au sens de l'art. 283-1. Il faut savoir s'il a un établissement qui intervient dans l'opération. | Doc-ext (BOI-TVA-DECLA-10-10-20, version du 22/10/2025). Détail **À vérifier** |
| **Statut du fournisseur de l'UE.** | Pas d'AIC si le vendeur bénéficie de la franchise dans son État (art. 256 bis, I-1°). | TO-ext |

### 2.2 Biens, services et frais accessoires

- La loi traite différemment les biens (art. 256, 256 bis, 258, 258 C, importation) et les services (art. 259 et suivants).
- Les frais facturés **par le même fournisseur** avec le bien (port, emballage, assurance) entrent dans la base imposable du bien et suivent son régime : AIC, importation ou livraison interne (Rech §1.6).
- Un transport facturé **à part par un transporteur** est une prestation de services, avec son propre régime : voir le cas A17.

### 2.3 Qui paie la TVA au Trésor (redevable)

| Situation | Redevable | Certitude |
|---|---|---|
| Fournisseur établi en France | Le fournisseur (art. 283-1, al. 1) | TO-ext |
| Fournisseur non établi en France, opération située en France, client assujetti identifié à la TVA en France | Le client (art. 283-1, al. 2), sauf dispositions contraires | Doc-ext |
| Services de la règle générale (art. 259-1°) rendus par un prestataire non établi | Le preneur (art. 283-2) | Doc-ext |
| Acquisition intracommunautaire (AIC) | L'acquéreur | TO-ext ; art. 290 I 3° a (TO-lu) |
| Importation par un assujetti identifié à la TVA en France | L'importateur, par autoliquidation **obligatoire** sur sa déclaration de TVA depuis le 1/1/2022. Généralisée par l'art. 181 de la loi de finances pour 2020, sans autorisation préalable. La douane cite les art. 291 à 293 A quater et 1695 du CGI. | Doc-ext (douane) |
| Autoliquidations internes (art. 283-2 bis à 2 nonies : sous-traitance du BTP, déchets et métaux, gaz et électricité, communications électroniques entre opérateurs…) | Le client | SS, Doc-ext ; numérotation **À vérifier** |

### 2.4 Exigibilité et droit à déduction

| Point | Règle | Certitude |
|---|---|---|
| AIC : exigibilité | Le 15 du mois suivant celui du fait générateur, ou à la date de la facture si elle est antérieure (factures d'acompte exclues). **Correction** : c'est l'art. 269-**2 d** du CGI, et non le 2 b cité dans la mission. | TO-ext ; Doc-ext (BOI-TVA-BASE-20-30) |
| Livraison de biens interne : exigibilité | À la livraison. | Rech, TO-ext |
| Services : exigibilité | À l'encaissement, sauf option du prestataire pour les débits. | Rech, TO-ext |
| Services autoliquidés par le preneur : exigibilité | La directive retient la réalisation du service (art. 66) ; l'article du CGI n'a pas été retrouvé. | **À vérifier** |
| Importation | Exigible à l'importation. La base de la ligne A4 de la CA3 est préremplie le 14 du mois suivant, d'après les déclarations en douane qui ont obtenu le bon à enlever (BAE) ; la CA3 se dépose alors au plus tard le 24. | SS concordantes, Doc-ext (notice impots.gouv « TVA à l'importation ») |
| Naissance de la déduction | Elle naît quand la taxe devient exigible chez le redevable (art. 271, I-2) et s'exerce sur la déclaration de la période où elle est née, en détenant la facture ou le document qui en tient lieu (art. 271, II). | Doc-ext (BOI-TVA-DED-10-30 du 18/01/2023) |
| Conséquence pour les achats de services à un fournisseur établi en France | TVA déductible au **paiement**, sauf si le fournisseur a opté pour les débits. La nouvelle mention « option pour les débits » sert précisément à le dire à l'acheteur. | Doc-ext + Proposition : le moteur lit cette mention |
| Mentions et déduction | L'omission d'une mention n'invalide pas forcément la déduction si l'opération est réelle et les autres conditions remplies ; la facture doit permettre de justifier le droit. | Doc-ext (BOI-TVA-DED-40-10-10 du 08/01/2025) |
| Facture papier reçue d'un fournisseur soumis à l'émission électronique | Débat ouvert. WebLex : la seule absence de passage par une plateforme agréée ne remet pas en cause la déduction. Fiducial rapporte la thèse inverse, qu'il ne partage pas. | SS divergentes, **À vérifier** |
| Franchise en base | Aucune déduction : toute TVA payée ou autoliquidée est un coût. | Doc-ext (BOI-TVA-DECLA-40-10-20, « Conséquences de la franchise ») |
| Coefficient de déduction (assujetti partiel, secteurs distincts, exclusions de l'art. 206 de l'annexe II) | Hors du champ du moteur. Le moteur suppose un droit à déduction total pour des marchandises achetées pour la revente, et le signale. | Proposition |

### 2.5 Devise

- La taxe se détermine **en euros** (art. 289 IV selon une source secondaire ; art. 230 de la directive selon la recherche).
- La date du taux de change est incertaine : les sources citent le taux BCE au jour de l'exigibilité, le taux douanier ou le taux du jour de la facture. **À vérifier**.
- Constat dans le code : `factures_achat.devise` existe (`database/migrations/2026_10_07_100001_create_factures_achat_table.php:31`, défaut `EUR`), sans taux de change et sans saisie à l'écran. Une facture en devise alimenterait le CMUP avec des montants non convertis.

---

## 3. Achats

### 3.0 Ce que le moteur doit savoir (Proposition) et qui le fournit

| Donnée | Qui la fournit | Préremplissage possible |
|---|---|---|
| Pays d'établissement du fournisseur, établissement en France (oui/non), n° de TVA, SIREN/SIRET (France), statut « franchise » (France ou UE) | Fiche fournisseur, mise à jour depuis la facture reçue (BT-29, BT-30, BT-31) | Oui, depuis la dernière facture validée |
| **Pays et code postal de départ réel des biens**, pays d'arrivée (le magasin) | Saisie à la facture d'achat, contrôlée sur les documents de transport | Proposé depuis l'adresse du fournisseur, jamais retenu sans confirmation pour une facture étrangère |
| Bien ou service, par ligne | Ligne de la facture | Oui (produit ou service du catalogue) |
| Qui a dédouané (le magasin ou le fournisseur), référence de la déclaration en douane (MRN), date du BAE, droits de douane, base et montant de la TVA d'importation | Transitaire ou déclaration en douane ; relevé ATVAI de l'espace douane | Non |
| Date de livraison ou d'arrivée (exigibilité de l'AIC) | Saisie ou facture | Date de la facture |
| Devise et taux de change | Facture et règle de conversion | Taux BCE, si la règle est confirmée |
| Catégorie EN 16931 et mention de la facture reçue (S, E, AE, K, G, O) | Facture électronique reçue par SUPER PDP | Oui |
| Régime de TVA de la société, n° de TVA FR, EORI, compteur des AIC de l'année (franchise), option AIC | Société (réglages fiscaux communs) | Oui |
| Taux français du produit dans le territoire d'arrivée (métropole ou Corse) | Fiche produit, et plus tard catégorie fiscale | Oui |

### 3.1 Tableau A : qualification et preuves (une ligne par cas)

| Cas | Situation | Conditions cumulatives | Informations nécessaires (qui) | Preuves à conserver |
|---|---|---|---|---|
| **A1** | Fournisseur établi en France, opération taxable | Fournisseur établi en France et redevable (non franchisé) ; opération située en France ; facture avec TVA française | Fiche fournisseur (SIREN, n° TVA FR) ; facture (n°, date, HT et TVA par taux, mentions) ; bien ou service ; option du fournisseur pour les débits (services) | Facture conforme, reçue par la plateforme quand le fournisseur est soumis à l'émission électronique |
| **A2** | Fournisseur établi en France, en franchise en base | Facture sans TVA portant la mention de franchise | Mention « TVA non applicable, art. 293 B du CGI » (référence CIBS après le 1/1/2027) | Facture |
| **A3** | Fournisseur établi en France, autoliquidation interne (sous-traitance du BTP, déchets, etc.) | Opération d'un secteur visé à l'art. 283-2 bis à 2 nonies ; client assujetti identifié en France | Mention « Autoliquidation » ; secteur | Facture, contrat (sous-traitance). **Exclu de l'automatique**, voir 3.6 |
| **A4** | Fournisseur établi à Monaco | Fournisseur monégasque (n° de TVA FR) ; Monaco est traité comme la France | n° de TVA FR du fournisseur ; pays MC | Facture |
| **A5** | **AIC** : biens expédiés d'un autre État membre (ou d'Irlande du Nord, n° XI) vers un magasin de métropole ou de Corse | (1) Vendeur assujetti agissant en tant que tel, identifié dans un autre État membre, sans la franchise de son État (art. 256 bis, I-1°) ; (2) bien meuble corporel expédié ou transporté, depuis cet autre État membre vers la France, par le vendeur, l'acheteur ou pour leur compte ; (3) acheteur assujetti identifié en France, qui a communiqué son n° FR ; (4) acheteur hors du régime dérogatoire, ou au-delà du seuil, ou ayant opté (voir A6 et A7) ; (5) ni opération triangulaire, ni livraison en chaîne (exclues) | Pays de **départ réel** (magasin, documents de transport) ; n° de TVA du vendeur (facture), consultation VIES conseillée (Proposition) ; date de livraison ; HT et frais accessoires en euros ; taux français du produit | Facture portant les deux n° de TVA et la mention d'exonération ; documents de transport (CMR, bon de livraison) ; paiement ; consultation VIES (Proposition) |
| **A6** | AIC par une société **en franchise en base**, sous le régime dérogatoire | Société sans droit à déduction (franchise) ; AIC hors taxe de la **société** (tous magasins) ≤ 10 000 € l'année civile précédente **et** dans l'année en cours au moment de l'acquisition ; pas d'option ; ni moyen de transport neuf, ni produit soumis à accise | Compteur des AIC de la société (années N-1 et N) ; option (oui/non) | Facture du fournisseur, avec la TVA de son pays |
| **A7** | AIC par une société en franchise au-delà de 10 000 €, ou sur option, ou de produits soumis à accise | Seuil dépassé (l'acquisition qui le fait dépasser et les suivantes, puis toute l'année suivante) ou option ; la société doit avoir un n° de TVA FR | Compteur ; option et sa date ; n° de TVA FR | Comme A5 ; dépôt d'une CA3 pour ces opérations |
| **A8** | **Livraison en France par un fournisseur non établi** : bien déjà en France, bien importé par le fournisseur puis livré en l'état, ou bien installé ou monté en France | Fournisseur sans établissement en France qui intervienne ; lieu de livraison en France ; client assujetti identifié en France | Lieu de départ (France) ; qui a dédouané (le fournisseur) ; facture HT portant « Autoliquidation » | Facture ; document de transport ; déclaration en douane au nom du fournisseur s'il y a eu importation |
| **A9** | Fournisseur d'un autre État membre en **franchise dans son État** (régime des petites entreprises, suffixe EX en cas de franchise transfrontalière) | Vendeur non redevable dans son État : pas d'AIC (art. 256 bis, I-1°) | Statut du fournisseur (mention de sa facture) | Facture. Qualification **SS/Inc** |
| **A10** | Fournisseur d'un autre État membre qui a facturé **sa propre TVA** (n° FR non communiqué, ou erreur) | Anomalie | Facture | Facture ; demande de facture rectifiée. Traitement **À vérifier** avec l'expert-comptable |
| **A11** | **Services** d'un prestataire établi dans un autre État membre, règle générale | Prestataire assujetti établi dans un autre État membre, sans établissement en France qui intervienne ; preneur assujetti (la société) ; service hors art. 259 A | Pays d'établissement du prestataire ; nature du service ; n° de TVA des deux parties | Facture « Autoliquidation » portant les deux n° de TVA |
| **A12** | **Services** d'un prestataire établi hors UE (Royaume-Uni, Irlande du Nord, Suisse, États-Unis…), règle générale | Mêmes conditions qu'A11 | Pays du prestataire ; nature du service | Facture étrangère, sans mention française exigée |
| **A13** | Services à lieu particulier (art. 259 A) | Lieu fixé par la loi : immeuble, transport de passagers, restauration, location de courte durée, accès à une manifestation | Lieu d'exécution | **Exclu de l'automatique** |
| **A14** | **Importation** : biens venus d'un pays ou territoire hors du territoire de TVA de l'UE, ou d'un DOM vers la métropole ; dédouanés par le magasin | Mise en libre pratique et à la consommation en France ; la société est l'importateur déclaré ; elle est identifiée à la TVA en France (n° FR porté sur la déclaration : code 1008 en rubrique 44 pour Delta G, ou FR7 pour Delta H7) ; EORI valide | Référence de la déclaration (MRN), date du BAE ; valeur en douane ; droits de douane ; autres taxes ; frais jusqu'au premier lieu de destination ; base et montant de la TVA d'importation (transitaire, relevé ATVAI) ; facture du fournisseur ; facture du transitaire | Déclaration en douane (DAU ou H7) ; relevé ATVAI ; factures du fournisseur et du transitaire |
| **A15** | Importation par une société **en franchise en base** | Comme A14 ; n° de TVA FR à obtenir | Comme A14 | Comme A14. Une source estime que la franchise exclut l'autoliquidation : **À vérifier** |
| **A16** | Achat par un magasin de **Guadeloupe, Martinique ou La Réunion** (venant de métropole, de l'UE ou d'ailleurs) | Les DOM sont hors du territoire de TVA de l'UE : importation dans le DOM, avec la TVA du DOM, l'octroi de mer et l'octroi de mer régional | Déclaration d'importation dans le DOM | **Exclu de l'automatique** |
| **A17** | **Transport** facturé à part par un transporteur | Service entre assujettis : lieu du preneur. Transporteur français : TVA française. Transporteur de l'UE : A11. Transporteur hors UE : A12 | Facture du transporteur ; achats qu'il dessert | Facture |
| **A18** | Services achetés à un prestataire étranger par une société **en franchise** | Le franchisé reste un assujetti : lieu en France, il autoliquide, sans déduction | n° de TVA FR à demander | Facture |

### 3.2 Tableau B : calcul (une ligne par cas)

Notations : HT = montant net du fournisseur (remise déduite), converti en euros ; t = taux français du produit dans le territoire d'arrivée.

| Cas | Qui paie la TVA | TVA facturée par le fournisseur | TVA autoliquidée par la société | TVA déductible | Montant dû au fournisseur | Coût du stock (unitaire, ramené à la quantité) |
|---|---|---|---|---|---|---|
| A1 | Fournisseur | Σ par taux (HT × taux), selon la facture | 0 | = TVA facturée. Biens : à la livraison ou à la facture. Services : au paiement, sauf option du fournisseur pour les débits | TTC | HT net (remise, escompte) + frais accessoires |
| A2 | Personne | 0 | 0 | 0 | Montant facturé | Montant facturé |
| A3 | Société (client) | 0 | HT × taux | = autoliquidée | HT | HT |
| A4 | Fournisseur | TVA française | 0 | = TVA facturée | TTC | HT net |
| A5 | Société | 0 | (HT + frais accessoires du fournisseur) × t, exigible le 15 du mois suivant la livraison ou à la facture antérieure | = autoliquidée, sur **la même déclaration** | HT + frais | HT net + frais (la TVA est neutre) |
| A6 | Fournisseur, dans son État | TVA de son État | 0 | 0 (TVA étrangère non déductible en France) | TTC étranger | **TTC étranger** |
| A7 | Société | 0 | (HT + frais) × t | **0** | HT + frais | **HT + frais + TVA autoliquidée** |
| A8 | Société | 0 | HT × t | = autoliquidée | HT | HT net |
| A9 | Personne en France | 0 | 0 | 0 | Prix | Prix |
| A10 | Anomalie | TVA étrangère | Peut rester due en France (**Inc**) | TVA étrangère non déductible sur la CA3 (remboursement par la procédure européenne, **À vérifier**) | Selon facture | **Bloquer** la validation automatique |
| A11 | Société | 0 | HT × taux du service | = autoliquidée | HT | Charge. Si elle est directement attribuable à l'achat de marchandises (transport, par exemple), elle entre dans le coût (PCG) |
| A12 | Société | 0 | HT × taux du service | = autoliquidée | HT | Comme A11 |
| A13 | Selon le lieu | Éventuellement TVA étrangère | Éventuelle | TVA étrangère non déductible | Selon facture | Saisie manuelle |
| A14 | Société (importateur) | 0 | TVA d'importation = base douanière (valeur en douane + droits et taxes hors TVA + frais accessoires jusqu'au premier lieu de destination) × t. Le montant de référence est **celui de la déclaration en douane** (Proposition : reprendre ce montant au lieu de le recalculer, et signaler tout écart) | = TVA d'importation | Prix facturé (selon l'Incoterm) ; le transitaire est payé à part pour les droits avancés et ses frais | Prix + **droits de douane** + transport, assurance et frais de dédouanement attribuables + taxes non récupérables. La TVA d'importation n'en fait pas partie |
| A15 | Société | 0 | TVA d'importation, déclarée sur la CA3 | **0** | Comme A14 | Comme A14 **+ TVA d'importation** |
| A16 | Société (dans le DOM) | 0 | TVA du DOM à l'importation (autoliquidation **À vérifier**) | TVA du DOM : oui ; octroi de mer : **À vérifier** | Prix | Prix + droits + octroi de mer s'il n'est pas récupérable (**À vérifier**) |
| A17 | Selon le transporteur (A1, A11 ou A12) | Selon le cas | Selon le cas | Selon le cas | Selon le cas | **Ajouté au coût des marchandises transportées** (frais d'acheminement) |
| A18 | Société | 0 | HT × taux | **0** | HT | Charge TTC (HT + TVA autoliquidée) |

**Arrondi (Proposition, cohérente avec la règle du projet).** CLAUDE.md, « Prix et TVA des ventes » : « Un achat se calcule depuis le PU HT du fournisseur : HT = quantité x PU HT, puis la TVA sur ce HT ».
- La TVA autoliquidée se calcule de la même façon, au taux français.
- Le schematron impose qu'une facture AE ou K porte une TVA de 0 et un taux de 0 (BR-AE-05, BR-AE-09, BR-IC-05, BR-IC-09 ; TO-lu). La TVA autoliquidée **n'est donc jamais lue sur la facture du fournisseur** : c'est la société qui la calcule.
- Le taux de la facture (0) et le taux de la TVA due en France (t) sont **deux données distinctes**.

### 3.3 Tableau C : mention, catégorie, circuit, déclaration (une ligne par cas)

Les numéros de lignes de la CA3 suivent la codification de 2022 (A1, A3, A4, B2, B4…). SS concordantes (Legifiscal, GERESO, Rodco, L'Expert-Comptable) ; la notice 2026 (n° 50449#29) n'a pas pu être ouverte. **À vérifier** sur cette notice avant tout export comptable.

| Cas | Mention attendue sur la facture reçue | Catégorie EN 16931 / VATEX de la facture reçue | Circuit (réception, e-reporting) | CA3 | Sources (certitude) |
|---|---|---|---|---|---|
| A1 | Mentions générales ; « Option pour le paiement de la taxe d'après les débits » si le fournisseur a opté (services) ; catégorie de l'opération et SIREN du client aux dates de la section 1 | S, taux > 0 (BR-S-05) | Facture électronique reçue (flux 2) par la plateforme de la société. Rien à déclarer en e-reporting par l'acheteur | TVA déductible : ligne 20 (autres biens et services) ou 19 (immobilisations) | Art. 271 (Doc-ext) ; schematron (TO-lu) |
| A2 | « TVA non applicable, art. 293 B du CGI » | E, VATEX-FR-FRANCHISE (BR-E-02 : BT-31, BT-32 ou BT-63 ; BR-E-10 : motif d'exonération) | Flux 2 reçu | Rien | VATEX (TO-lu) ; BOI-TVA-DECLA-40-10-20 (Doc-ext) |
| A3 | « Autoliquidation » (art. 242 nonies A, I-13°) | AE ; VATEX-EU-AE, ou VATEX-FR-AE (« Exempt based on 2 of article 283 of the CGI ») | Flux 2 reçu | Ligne de base **À vérifier** (A2 ?) | VATEX (TO-lu) ; SS |
| A4 | Mentions générales | S | E-reporting des acquisitions auprès d'un fournisseur monégasque (**art. 290 I 4° a**, TO-lu), sauf s'il émet par le réseau français (cas à éclaircir pour ne pas déclarer deux fois) | Comme A1 | Art. 290 (TO-lu) ; SS Monaco |
| A5 | Deux n° de TVA ; « Exonération de TVA – art. 138 de la directive 2006/112/CE » ou référence nationale du pays du vendeur | K, VATEX-EU-IC ; BR-IC-02 (BT-31 ou BT-63, et BT-48), BR-IC-11 (BT-72 ou BG-14), BR-IC-12 (BT-80) | **E-reporting B2BInt, sens « in », rôle BY**, facture par facture (art. 290 I 3° a, TO-lu ; SUPER PDP `POST /v1.beta/b2bint_invoices`, `direction` = `in`). Selon les sources secondaires, la TVA autoliquidée recalculée par l'acheteur fait partie des données ; catégorie à transmettre **À vérifier**. Pas d'état récapitulatif, réservé au vendeur. EMEBI (introductions) seulement sur lettre-avis de la douane | Base **B2** ; TVA dans les lignes de taux (08 à 20 %, 9B à 10 %, 09 à 5,5 %…) ; « dont TVA sur acquisitions intracommunautaires » **ligne 17** ; déduction **ligne 20** | Art. 256 bis, 258 C, 269-2 d (TO-ext) ; BOI-TVA-CHAMP-10-10-40-20 et BOI-TVA-BASE-20-30 (Doc-ext) ; schematron (TO-lu) |
| A6 | Facture « domestique » du pays du fournisseur | S, au taux étranger, si la facture est structurée (sans objet en France) | Art. 290 I 4° b : « acquisitions intracommunautaires non soumises à la TVA en application du I de l'article 258 D » (TO-lu). Le contenu du 258 D I n'a pas été relu : **À vérifier** si ce sont ces AIC non taxées | Rien, sauf réponse contraire sur le 258 D | Art. 256 bis, I-2° (TO-ext) ; BOI-TVA-DECLA-40-10-20 (Doc-ext) |
| A7 | Comme A5 | K | Comme A5 (le franchisé est soumis à l'e-reporting) | CA3 déposée pour ces opérations : B2 et lignes de taux, **sans déduction** | BOI-TVA-DECLA-40-10-20 et -20-10-20 (Doc-ext) |
| A8 | « Autoliquidation » | AE, VATEX-EU-AE ; BR-AE-02 (BT-31, BT-32 ou BT-63 ; BT-48 ou BT-47) | E-reporting B2BInt « in » (art. 290 I 3° b, TO-lu) | Base **B4** ; TVA par taux ; déduction ligne 20 | BOI-TVA-DECLA-10-10-20 (Doc-ext) ; art. 290 (TO-lu) |
| A9 | Mention de franchise de l'État du fournisseur | E, si la facture est structurée | Rien (**Inc**) | Rien | Art. 256 bis, I-1° (TO-ext) |
| A10 | (anomalie) | S, au taux étranger | Bloquer et demander une facture rectifiée | — | **À vérifier** |
| A11 | « Autoliquidation » + deux n° de TVA (art. 196 de la directive, côté prestataire) | AE, VATEX-EU-AE | E-reporting B2BInt « in » (art. 290 I 3° c, TO-lu). Le prestataire dépose la DES | Base **A3** (« achats de prestations de services réalisés auprès d'un assujetti non établi en France », libellé depuis 2023) ; TVA par taux ; ligne 20 | Art. 259-1° et 283-2 (Doc-ext) ; Legifiscal (SS) |
| A12 | Aucune mention française exigée | Souvent aucune (facture hors EN 16931) | E-reporting B2BInt « in » (art. 290 I 3° c) | Base **A3** (SS ; une source place ces achats en B4 : **À vérifier**) | Idem |
| A13 | Selon le droit local | — | Si le service est situé en France et acquis auprès d'un non-établi : e-reporting (art. 290 I 3° c vise aussi l'art. 259 A) | Saisie manuelle | Art. 259 A (TO-ext) ; art. 290 (TO-lu) |
| A14 | Aucune mention française. Facture d'un fournisseur d'un DOM : « Exonération de TVA en application de l'article 294 du CGI » (et L. 211-7 du CIBS selon une source) | Hors flux français | **Aucun e-reporting** : l'art. 290 I ne vise pas les importations (TO-lu), couvertes par les formalités douanières (SS) | Base **A4**, préremplie, à rapprocher du relevé ATVAI ; TVA par taux (cases I1 à I6 selon une source) ; déduction ligne 20 et « dont TVA déductible sur importations » **ligne 24**, à compléter si elle n'est pas préremplie | Art. 292 (TO-ext) ; douane, Q&R ATVAI (Doc-ext) ; Rodco, L'Expert-Comptable (SS) |
| A15 | Comme A14 | — | Aucun | TVA d'importation déclarée sur la 3310-CA3 au titre du mois d'exigibilité, **sans déduction** | impots.gouv « TVA à l'importation » (Doc-ext) ; SS concordantes sur la non-déduction |
| A16 | Facture de métropole : mention de l'art. 294 | — | Hors automatique | — | impots.gouv FAQ DOM (Doc-ext) ; Legifiscal (SS) |
| A17 | Selon le transporteur | S, AE ou rien | Selon le cas (A1, A11, A12) | Selon le cas | PCG (voir 3.4) |
| A18 | « Autoliquidation » | AE | E-reporting B2BInt « in » | A3 et lignes de taux, sans déduction | SS concordantes |

### 3.4 Coût d'acquisition du stock

**Règle comptable**
- Coût d'acquisition = prix d'achat, droits de douane et autres taxes **non récupérables** compris, après déduction des rabais commerciaux, remises, escomptes de règlement et éléments similaires, plus les frais de transport, de manutention et autres coûts directement attribuables. Les pertes et gaspillages sont exclus.
- Doc-ext : BOI-BIC-PDSTK-20-20-10-10, qui cite l'ancien art. 321-20 du PCG.
- Le règlement ANC 2014-03 traite ce coût dans sa sous-section « Coût d'entrée des stocks », aux art. 213-30 à 213-32 (SS ; texte exact **À vérifier** dans le PCG consolidé au 1/1/2026 de l'ANC).
- L'art. **213-8** cité dans la mission vise les **immobilisations**, pas les stocks (**À vérifier**).
- Sur le plan fiscal, l'art. 38 nonies de l'annexe III du CGI suit la même logique (Doc-ext).

**Conséquences par régime** (déjà intégrées au tableau B) :
- TVA récupérable (A1, A5, A8, A14) : hors du coût ;
- TVA non récupérable (A6, A7, A15, et tout achat d'une société en franchise) : **dans le coût** ;
- droits de douane et frais d'acheminement (A14, A17) : **dans le coût**.

**Code actuel**
- `LigneAchat::coutUnitaireHt()` (`app/Domain/Achats/Models/LigneAchat.php:85-99`) renvoie toujours le PU HT net de la remise de ligne et de l'escompte. Son docblock cite « PCG art. 213-31 ».
- Ce coût alimente le CMUP (`app/Domain/Achats/Listeners/IncrementerStockApresValidationFactureAchat.php:32` → `app/Domain/Stock/Services/StockLedgerService.php:49-69`).
- Il n'ajoute jamais :
  - la TVA non récupérable d'une société en franchise : c'est **faux en franchise**, où le coût est TTC ;
  - les droits de douane ;
  - les frais de transport facturés à part.

### 3.5 Circuit des factures reçues et e-reporting des achats (art. 290 I 3° et 4°)

| Origine de l'achat | Arrivée de la facture | Déclaration par la société | Fondement |
|---|---|---|---|
| Fournisseur établi en France (A1, A2, A3) | Facture électronique reçue par SUPER PDP (réception obligatoire depuis le 1/9/2026), une fois le fournisseur soumis à l'émission | Rien de plus : la facture domestique alimente seule l'administration | FAQ du 16/01/2026 (PDF) ; recherche |
| AIC (A5, A7) | Facture étrangère par courriel ou papier (rarement par Peppol) | **B2BInt « in »**, facture par facture, rôle BY | Art. 290 I 3° a (TO-lu) |
| Livraison en France par un non-établi (A8) | Idem | **B2BInt « in »** | Art. 290 I 3° b (TO-lu) |
| Services d'un non-établi (A11, A12, A18, A13 situé en France) | Idem | **B2BInt « in »** | Art. 290 I 3° c (TO-lu) |
| Monaco (A4) | Facture monégasque | E-reporting des acquisitions auprès de Monaco | Art. 290 I 4° a (TO-lu) |
| AIC non soumises (art. 258 D, I) | — | E-reporting prévu par le texte | Art. 290 I 4° b (TO-lu) ; contenu du 258 D **À vérifier** |
| Importations (A14, A15) | Déclaration en douane | **Aucun e-reporting** | Absentes de l'art. 290 I (TO-lu) ; SS |
| Opérations exonérées par les art. 261 à 261 E | — | Hors e-reporting | Art. 290 I, chapeau (TO-lu) |

**Précisions**
- Le décret prévu au III de l'art. 290 fixe données, périodicité et modalités ; c'est la plateforme agréée choisie par l'assujetti qui transmet. Décision de l'éditeur (point 11 de `reponses-complements-14-20.md`) : SUPER PDP tient le calendrier.
- Côté SUPER PDP (spécification 1.36.0.beta, TO-lu) :
  - `b2bint_invoice` exige `direction`, `number`, `issue_date`, `type_code`, `tax_due_date_type_code`, `notes`, `business_process`, `seller`, `buyer`, `total` et `tax_subtotals` ;
  - `b2bint_tax_category` porte `code`, `percent`, `exemption_reason` et `exemption_reason_code` ;
  - la description de la route indique un envoi automatique selon le calendrier du régime de TVA de l'entreprise.
- **À vérifier dans le bac à sable** : quelle catégorie et quelle TVA porter en sens « in » pour une AIC (K, au taux 0 de la facture, ou TVA autoliquidée au taux français) ; si une facture étrangère reçue par Peppol alimente seule l'e-reporting, pour ne jamais déclarer deux fois (point 12 des compléments).

### 3.6 Cas exclus du calcul automatique (blocage ou signalement)

Pour chacun, la validation automatique est refusée, avec un message et une saisie par un utilisateur autorisé (Proposition) :
- autoliquidations internes de l'art. 283-2 bis à 2 nonies (A3) ;
- services de l'art. 259 A (A13) ;
- achats d'un magasin d'outre-mer (A16) ;
- opérations triangulaires et livraisons en chaîne ;
- facture étrangère portant une TVA étrangère (A10) ;
- produits soumis à accise ;
- moyens de transport neufs ;
- achats d'occasion à des particuliers (étape 14) ;
- importations de faible valeur (150 € ou moins) : procédure particulière, **À vérifier** ;
- assujetti partiel (coefficient de déduction).

---

## 4. Ventes : vérification et compléments de la recherche

### 4.1 Vérifications du jour

| Nature (recherche) | Résultat | Compléments et corrections | Certitude |
|---|---|---|---|
| N1 Vente en France | Confirmée | Côté achats, l'art. 290 I 4° a vise **aussi les acquisitions** auprès de Monaco, pas seulement les ventes (voir A4) | TO-lu |
| N2 Détaxe | Non revérifiée | Aucun changement trouvé | Rech |
| N3 Export expédié | Confirmée | EORI au format FR + SIREN ; les EORI au SIRET disparaissent au second semestre 2026 | Doc-ext |
| N4 Export enlevé | Non revérifiée (BOI-TVA-CHAMP-30-30-10-20 du 11/02/2026, cité par la recherche) | Second temps de la décision I1 (« ensuite ») | Rech |
| N5 Livraison vers un DOM | Confirmée sur le principe : les DOM sont des territoires d'exportation (art. 294-2) | La liste VATEX contient **VATEX-FR-CGI295** (art. 295 du CGI, exonérations propres aux DOM), qui ne vise pas, à notre connaissance, la livraison de la métropole vers un DOM. La catégorie G avec VATEX-EU-G reste plausible mais non confirmée. Guadeloupe et Martinique : « marché unique » pour l'octroi de mer ; effet en TVA **À vérifier** | VATEX TO-lu ; Inc |
| N6 LIC | Confirmée ; règles BR-IC-02, 11 et 12 relues mot pour mot ce jour | **Écart à arbitrer** : `ControleNatureOperation` vérifie le n° de TVA sur VIES « le jour de l'émission » (`app/Domain/Facturation/Services/ControleNatureOperation.php:119-131` et `231-243`). Or la condition légale est un n° communiqué **avant le début du transport** et valide **à la date de la livraison**, et la facture peut être émise jusqu'au 15 du mois suivant. Proposition : garder la consultation à l'émission et exiger aussi une consultation valide à la date de livraison (`livraison_date`) | TO-lu (schematron) ; Rech pour la condition |
| N7 Vente à distance UE | Confirmée sur le seuil de 10 000 €, apprécié sur l'année civile précédente ou l'année en cours (art. 258 A, version du 23/02/2026 selon Advizexperts) | La fiche BOI-TVA-CHAMP-20-20-30-20211222 citée par la recherche **n'est plus publiée depuis le 24/07/2024** : citer la version en vigueur (**À vérifier**). Ne pas suivre la source Legifiscal qui parle d'un seuil apprécié « vers l'État de destination » : c'est la règle d'avant le 1/7/2021. Existence de l'art. 258 B après 2021 (accises) : **À vérifier** | TO-ext ; SS |
| N8 Prestation UE (AE) | Confirmée ; BR-AE-02, 05, 09 et 10 relues ce jour | L'art. L. 211-92 du CIBS annoncé vise l'autoliquidation **par le preneur établi en France**. Pour une prestation vendue, la référence pertinente reste la directive (art. 44 et 196) ou la seule mention « Autoliquidation » | TO-lu ; SS |
| N9 Prestation hors UE (O) | Confirmée ; BR-O-02, 05, 10, 11 et 12 relues ce jour | BR-O-05 : une ligne O **ne porte pas de taux** (et non « taux 0 ») | TO-lu |
| N10 Lieu hors de France (art. 259 A et 259 B) | Art. 259 A modifié au 1/1/2025 (loi n° 2023-1322) | Numérotation des alinéas toujours **À vérifier** | TO-ext |
| N11 Prestation vers un DOM | Non revérifiée | — | Rech |
| Franchise en base | Seuils 2026 confirmés : 85 000 € pour les ventes (BOI-TVA-DECLA-40-10-10-20260701), 37 500 € pour les services, seuils majorés 93 500 € et 41 250 € | Le seuil unique de 25 000 € a été abandonné par la loi n° 2025-1044 du 3/11/2025 (SS). Depuis le 1/1/2025, un dépassement du seuil majoré met fin à la franchise **à la date du dépassement**, et non plus au premier jour du mois (SS, Legifiscal). Le régime de la société doit donc porter une **date d'effet au jour près**. Franchise européenne : en place depuis le 1/1/2025 (directive 2020/285 ; page impots.gouv « Je souhaite adhérer à la franchise en base TVA européenne », maj. 23/04/2026) ; plafond de 100 000 € dans l'Union et suffixe EX, tirés de la directive, non relus en ligne | Doc-ext ; SS |
| Services à des particuliers (art. 259-2°) ; services électroniques (art. 259 B et 259 D) | Inchangé | Les services électroniques comptent dans le même seuil de 10 000 € que les ventes à distance (art. 258 A, I-1°, qui renvoie aux 10°, 11° et 12° de l'art. 259 B, TO-ext). PILIQI n'en vend pas : à exclure | TO-ext |

### 4.2 Récapitulatif des ventes (une ligne par cas)

Colonnes « Mention » et « CA3 » : voir les réserves de la section 5 et de 3.3.

| Cas | Conditions cumulatives | Preuves | Calcul | Mention | EN 16931 / VATEX | Circuit | CA3 | Source |
|---|---|---|---|---|---|---|---|---|
| V1 Vente en France | Lieu en France (biens) ; prestataire en France (services, hors exceptions) | Facture ou ticket | TVA française, au taux du territoire du magasin et du produit | Mentions générales | S | Particulier : B2C (TLB1, TPS1). Professionnel établi en France : flux 2. Professionnel non établi : B2BInt « out » | A1, lignes de taux (SS) | Recherche N1 |
| V2 Détaxe | Résident hors UE ; montant TTC supérieur à 100 € ; BVE PABLO ; visa de sortie | BVE visé | TVA française à la vente, régularisée après le visa | Conseillée : « Vente en détaxe – BVE n° … » | — | B2C, TLB1 | Puis E1 après le visa | Recherche N2 |
| V3 Export expédié | Bien qui quitte l'UE, transporté par le magasin ou pour son compte | MRN avec certification de sortie, ou CN23 | 0 % | « Exonération de TVA, article 262 I du CGI » (L. 213-14 du CIBS, à vérifier) ou art. 146 de la directive | G, VATEX-EU-G ; BR-G-02, 05, 09, 10 | Particulier : B2C TNT1. Professionnel non établi : B2BInt « out » | E1 (SS) | Recherche N3 ; schematron (TO-lu) |
| V4 Export enlevé | Acquéreur non établi en France qui transporte lui-même | Déclaration d'export au nom de l'acquéreur, avec certification de sortie | 0 % (garantie de TVA à trancher) | Art. 262 I 2° ou art. 146 de la directive | G | Idem | E1 | Recherche N4 |
| V5 Métropole → DOM | Biens expédiés vers 971 à 976 | MRN ou CN23 ; document de transport | 0 % | « Exonération de TVA en application de l'article 294 du Code général des impôts » | G (plausible, à vérifier) | Professionnel en 971, 972 ou 974 : flux 2. En 973 ou 976 : e-reporting. Particulier : TNT1 | E1 ou E2, **À vérifier** | Recherche N5 |
| V6 LIC | Acquéreur identifié dans un autre État membre ; n° communiqué avant le transport et valide à la livraison ; transport réel ; état récapitulatif déposé | Art. 45 bis : deux documents a), ou un a) et un b), de parties indépendantes ; attestation de l'acquéreur s'il enlève lui-même | 0 % | « Exonération de TVA, article 262 ter I du CGI » (L. 213-13, à vérifier) ou art. 138 de la directive | K, VATEX-EU-IC | Non établi : B2BInt « out ». Établi en France avec un n° étranger : flux 2 | F2 (SS) | Recherche N6 ; schematron |
| V7 Vente à distance sous le seuil | Particulier livré par le magasin dans un autre État membre ; seuil de l'UE non dépassé ; ni option ni OSS | Expédition | TVA française | Générales | S | B2C TLB1 | A1 | Recherche N7 |
| V8 Vente à distance au-delà du seuil (étape ultérieure, I1) | Seuil dépassé, option ou inscription à l'OSS | Registre OSS | TVA du pays d'arrivée, déclarée par l'OSS | Aucune légale | S, au taux étranger | B2C TNT1, sans TVA française | E3 (SS) | Recherche N7 |
| V9 Prestation UE | Preneur assujetti de l'UE, n° valide sur VIES ; service hors art. 259 A | VIES ; DES | 0 % | « Autoliquidation » | AE, VATEX-EU-AE | B2BInt « out » | Ligne **À vérifier** (E2 ?) | Recherche N8 |
| V10 Prestation hors UE | Preneur assujetti hors UE, statut prouvé | Identifiant fiscal étranger | Hors champ | Usage : « TVA non applicable – article 259-1° du CGI » | O, VATEX-EU-O, **document séparé** (BR-O-11, BR-O-12) | B2BInt « out », identifiant 0227 | E2 ? (**À vérifier**) | Recherche N9 |
| V11 Service à un particulier | Hors art. 259 A et 259 B | — | TVA française | Générales | S | B2C TPS1 et paiements (flux 10.4) | A1 | Recherche N1 |
| V12 Art. 259 B, particulier hors UE | Domicile hors UE prouvé | Justificatif de domicile | Hors champ | Usage | — | B2C TNT1 | E2 ? | Recherche N10 |
| V13 Services vers un client d'un DOM | Voir la recherche, N11 | — | Taux du DOM, ou hors champ (973, 976) | — | S ou O | Flux 2 ou e-reporting | — | Recherche N11 |
| V14 Magasin en franchise | Société en franchise (régime avec sa date d'effet) | — | 0 %, au même prix TTC (décision de l'éditeur) | « TVA non applicable, art. 293 B du CGI » jusqu'au 31/12/2026 | **E**, VATEX-FR-FRANCHISE. Jamais S à 0 % : BR-S-05 exige un taux supérieur à 0 | Selon le client ; B2C TNT1 (Inc) | Rien | Recherche 1.5 ; schematron |
| V15 Client monégasque | TVA française | — | TVA française | Générales | S | Art. 290 I 4° a | A1, et « dont TVA sur opérations à destination de Monaco » (ligne 18, SS) | Art. 290 (TO-lu) |

---

## 5. Mentions obligatoires par régime (à versionner par date)

| Situation | Jusqu'au 31/12/2026 | À partir du 1/1/2027 (provisoire) | Référence stable (directive) | Base | Certitude |
|---|---|---|---|---|---|
| Franchise en base | « TVA non applicable, art. 293 B du CGI » | « TVA non applicable, art. L. 233-3 du CIBS » (ou L. 223-3) ; l'ancienne reste admise jusqu'au 30/06/2028 | — | Fondement de l'obligation dans l'annexe II : **À vérifier** ; BOFiP | SS divergentes sur le numéro |
| Export | « Exonération de TVA, article 262 I du CGI » | L. 213-14 du CIBS (à vérifier) | Art. 146 de la directive | Art. 242 nonies A : référence au CGI **ou à la directive** | TO-ext ; SS |
| LIC | « Exonération de TVA, article 262 ter I du CGI » | L. 213-13 (à vérifier) | Art. 138 de la directive | Idem | TO-ext ; SS |
| Livraison vers un DOM | « Exonération de TVA en application de l'article 294 du Code général des impôts » | L. 211-7 (SS) | — | FAQ impots.gouv | Doc-ext |
| Autoliquidation, vendue ou reçue | « Autoliquidation » : texte imposé, invariable | Inchangé | Art. 226, 11 bis de la directive | Art. 242 nonies A, I-13° | TO-ext |
| Prestation hors UE (O) | « TVA non applicable – article 259-1° du CGI » (usage) | **À vérifier** | Art. 44 de la directive | Usage, aucune formule imposée | Rech |
| Option pour les débits | « Option pour le paiement de la taxe d'après les débits » | Inchangé | — | Nouvelle mention de la réforme | SS concordantes |
| Catégorie de l'opération | Biens, services ou les deux | Inchangé | — | Nouvelle mention de la réforme | SS concordantes |
| SIREN du client | Pour un client assujetti établi en France | Inchangé | — | Nouvelle mention de la réforme | SS concordantes |
| Adresse de livraison | Si elle diffère de l'adresse de facturation (livraisons de biens) | Inchangé | — | Nouvelle mention de la réforme | SS concordantes |
| Régime de la marge (étape 14) | « Régime particulier – Biens d'occasion » (ou objets d'art, de collection et d'antiquité) | Inchangé | — | Art. 242 nonies A, I-16° (décret n° 2024-1195) | TO-ext |

**Dates d'application des quatre nouvelles mentions** : GE et ETI au 1/9/2026, PME et micro-entreprises au 1/9/2027. Un PILIQI qui les imprime dès maintenant reste conforme (Proposition).

**Dans le code**
- `MentionsFiscales::pour()` (`app/Domain/Facturation/Services/MentionsFiscales.php:61-80`) imprime déjà la catégorie de l'opération (`app/Domain/Facturation/Enums/CategorieOperation.php:12-25`) et l'option pour les débits.
- La mention d'option s'imprime sur **tout** document hors franchise dont l'émetteur a opté, y compris un document qui ne vend que des biens. Le texte ne vise que les opérations soumises à l'option (les services) : portée **À vérifier**.

**Côté achats.** Le moteur peut contrôler à la réception la présence de ces mentions sur les factures des fournisseurs : n° de TVA du vendeur et de l'acheteur pour les catégories K et AE, « Autoliquidation », option pour les débits. Une mention manquante donne un signalement, jamais un refus de déduction automatique (BOI-TVA-DED-40-10-10, Doc-ext).

---

## 6. Catégories EN 16931 et codes VATEX

| Catégorie | Sens | Ventes PILIQI | Achats (facture reçue) | VATEX | Règles relues ce jour (TO-lu) |
|---|---|---|---|---|---|
| S | Taux normal ou réduit | Vente en France | A1, A4 | Aucun (BR-S-10 l'interdit) | BR-S-02 ; BR-S-05 (taux > 0) ; BR-S-08 et 09 (TVA de chaque taux = base du taux × taux) |
| Z | Taux zéro | Non utilisé : pas de taux zéro en France | Fournisseur étranger éventuel | Aucun (BR-Z-10) | BR-Z-02, 05, 09 |
| E | Exonéré | Franchise en base (VATEX-FR-FRANCHISE) ; régime de la marge (VATEX-EU-F, I, J), étape 14 | A2 ; A9 | Obligatoire, code ou texte (BR-E-10) | BR-E-02 (BT-31, BT-32 ou BT-63) ; BR-E-05 et 09 (taux et TVA nuls) |
| AE | Autoliquidation | Prestation UE (VATEX-EU-AE) | A3, A8, A11 | VATEX-EU-AE ; VATEX-FR-AE (« Exempt based on 2 of article 283 of the CGI ») | BR-AE-02 ; 05 et 09 (taux et TVA nuls) ; 10 (motif obligatoire) |
| K | Livraison intracommunautaire | LIC (VATEX-EU-IC) | A5, A7 (facture du fournisseur) | VATEX-EU-IC | BR-IC-02, 05, 09, 10, 11, 12 |
| G | Export hors UE | Export ; livraison vers un DOM (à confirmer) | — | VATEX-EU-G | BR-G-02, 05, 09, 10 |
| O | Hors champ | Prestation hors UE ; art. 259 B ; Guyane et Mayotte | — | VATEX-EU-O | BR-O-02 (ni BT-31, ni BT-63, ni BT-48) ; 05 (pas de taux) ; 10 ; 11 et 12 (aucune autre catégorie dans le document) |
| L, M | Canaries (IGIC), Ceuta et Melilla (IPSI) | Jamais (vendeurs établis dans ces territoires) | Fournisseur des Canaries : achat traité comme une importation (A14) | — | — |

**Autres codes VATEX de la liste OpenPEPPOL, branche master, lue le 11/10/2026**
- VATEX-FR-CNWVAT : avoirs domestiques sans TVA ;
- VATEX-FR-CGI295 ;
- VATEX-FR-CGI275 : achats en franchise des exportateurs ;
- VATEX-FR-CGI261-1 à 261E-2 : exonérations des art. 261 à 261 E, hors du champ de PILIQI ;
- VATEX-FR-CGI277A ;
- VATEX-FR-298SEXDECIESA ;
- VATEX-EU-151-1A à 1E : diplomates et organismes internationaux ;
- VATEX-EU-143-* : importations exonérées.

**Changement à surveiller.** Plusieurs codes FR citent un **article du CGI**. La liste devra évoluer avec le CIBS : la table des VATEX est donc, elle aussi, à versionner (**À vérifier** à chaque version de la liste).

**SUPER PDP (spécification 1.36.0.beta, TO-lu)**
- `vat_category_code` : AE, E, G, K, L, M, O, S, Z.
- `vat_exemption_reason_code` (BT-121) : texte libre.
- Par ligne : extensions françaises EXT-FR-FE-178 (motif d'exonération), 179 (code du motif) et 180 (code de date d'exigibilité).
- Transactions B2C : catégories TLB1, TPS1, TNT1, TMA1 (règle G1.68). L'implémentation gobl.fr.ctc range sous TNT1 les ventes à distance des art. 258 A et 259 B (SS).

---

## 7. Taux en vigueur et caractère révisable

| Territoire | Taux | Certitude | Dans le code |
|---|---|---|---|
| Métropole | 20 ; 10 ; 5,5 ; 2,1 % | Rech. Aucune modification trouvée dans la loi de finances pour 2026 : un amendement du Sénat remplaçant 2,1 % par 5,5 % au 1/1/2026 n'est **pas confirmé adopté** | `app/Domain/Facturation/Enums/TerritoireTva.php:80-88` (liste en dur) |
| Corse (art. 297) | 13 ; 10 ; 2,1 ; 0,9 %, pour des listes fermées, et les taux du continent ailleurs | Rech (BOI-TVA-GEO-10-10 du 20/11/2024) ; listes à faire valider | Idem |
| Guadeloupe, Martinique, La Réunion (art. 296) | 8,5 ; 2,1 ; 1,75 ; 1,05 % | Rech | Idem ; taux normal proposé aux lignes 65-72 |
| Guyane, Mayotte | TVA non applicable (art. 294-1) | Rech | `estPrisEnCharge()` renvoie faux (ligne 57-60) |

**Conséquences (Proposition)**
- Un taux est **révisable par une loi de finances** : chaque taux se lit avec une date de début et une date de fin.
- La TVA autoliquidée d'une AIC, d'une importation ou d'un achat autoliquidé prend le taux français du produit **au lieu d'arrivée** (métropole ou Corse) à la date d'exigibilité, et non le taux porté sur la facture du fournisseur, qui vaut 0.
- Aujourd'hui, `ConstructeurLigneAchat` donne à la ligne d'achat le taux saisi ou, à défaut, celui de la fiche produit (`app/Domain/Achats/Services/ConstructeurLigneAchat.php:267`). Un nouveau produit prend le taux par défaut du magasin (ligne 383). Le même champ `taux_tva` (`database/migrations/2026_10_07_100002_create_lignes_achat_table.php:36`) devrait porter deux notions distinctes : le taux facturé par le fournisseur et le taux de la TVA due en France.

---

## 8. Écarts entre le code et la réglementation (lecture seule, côté achats surtout)

| Constat (fichier:ligne) | Règle concernée | Conséquence |
|---|---|---|
| `database/migrations/2026_10_07_100002_create_lignes_achat_table.php:32-40` : une ligne d'achat n'a qu'un taux, un HT, une TVA et un TTC | Autoliquidation (A3, A5, A7, A8, A11), importation (A14), non-déduction (franchise) | Aucune place pour la TVA autoliquidée, la TVA déductible ni le régime retenu, ni pour leur justification (traçabilité demandée par l'éditeur) |
| `database/migrations/2026_10_07_100001_create_factures_achat_table.php:23-39` : ni pays de départ, ni date de livraison, ni référence douanière, ni droits de douane, ni régime ; `devise` sans taux de change (ligne 31) | Art. 256 bis, 258 C, 269-2 d, 292 ; TVA en euros | Impossible de qualifier une AIC ou une importation, ou de dater son exigibilité ; montants en devise non convertis |
| `database/migrations/2026_10_02_200002_create_fournisseurs_table.php:15-23` : SIRET, n° de TVA et pays seulement | Établissement en France (art. 283-1) ; statut de franchise (art. 256 bis, I-1°) ; SIREN pour rattacher les factures reçues (BT-30, recherche §3.9) | Le pays du fournisseur ne suffit pas à qualifier l'achat : il manque l'établissement, le statut et le SIREN |
| `database/migrations/2026_10_02_200006_create_consultations_vies_table.php:33` : la consultation VIES n'est liée qu'à un client | Contrôle de la qualité d'assujetti d'un fournisseur de l'UE (A5, Proposition) | Aucune trace de vérification d'un fournisseur |
| `app/Domain/Achats/Services/ConstructeurLigneAchat.php:24-36` : chaque ligne reçoit un produit du catalogue | Services achetés (A11, A12, A17) ; frais d'acheminement dans le coût (PCG) | Impossible de saisir un service, un transport ou des frais de dédouanement, donc de les porter au coût du stock |
| `app/Domain/Achats/Models/LigneAchat.php:85-99` : coût = PU HT net, toujours | Franchise : coût TTC ; droits de douane et frais dans le coût (PCG) | CMUP faux pour une société en franchise et pour une importation |
| `app/Domain/Facturation/Services/CalculateurLigne.php:93-103` : TVA d'achat = HT × taux de la ligne | Facture AE ou K : TVA de 0 sur la facture (BR-AE-09, BR-IC-09), TVA autoliquidée au taux français | Il faut deux calculs distincts : celui de la facture, et celui de la TVA due ou déductible |
| `app/Domain/Facturation/Enums/NatureOperation.php:50-60` ; `app/Domain/Tenancy/Enums/RegimeTva.php:39-44` | Recodification au 1/1/2027 ; anciennes références admises jusqu'au 30/06/2028 | Mentions écrites en dur, non datées |
| `app/Domain/Facturation/Enums/TerritoireTva.php:65-88` | Taux révisables | Taux écrits en dur, non datés |
| `app/Domain/Facturation/Services/ControleNatureOperation.php:231-243` | LIC : n° valide à la date de la livraison et communiqué avant le transport | Contrôle fait à la date d'émission seulement (voir 4.1, N6) |
| `database/migrations/0001_01_00_000000_create_societes_table.php:50, 60, 63` : n° de TVA, régime et option pour les débits sur la société, mais régime **sans date d'effet** | Fin de la franchise à la date du dépassement ; compteur des AIC (régime dérogatoire) ; EORI | Le moteur ne peut pas savoir quel régime s'appliquait à la date d'une opération |

---

## 9. Sources consultées le 11/10/2026

Les pages officielles n'ont été vues qu'en extraits de moteur de recherche (voir section 0).

**Textes**
- [Art. 290 du CGI](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000046195617), version en vigueur depuis le 21/02/2026 : PDF fourni par l'éditeur, lu en entier.
- [Art. 256 bis](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044983608), [art. 269](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044983827), [art. 259 A](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000048835181), [art. 262 ter](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051764907), [art. 242 nonies A de l'annexe II](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000050811276) : extraits.
- Ordonnance n° 2025-1247 ([Doctrine](https://www.doctrine.fr/l/texts/ordonnances/JORFTEXT000053091516), [CMS](https://cms.law/fr/fra/news-information/la-tva-integre-le-code-des-impositions-sur-les-biens-et-services-cibs)) ; [rapport sur l'ordonnance n° 2026-671](https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000054497139).
- [Schematron EN 16931](https://raw.githubusercontent.com/ConnectingEurope/eInvoicing-EN16931/master/ubl/schematron/abstract/EN16931-model.sch) et [liste VATEX](https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/master/structure/codelist/VATEX.xml), branches master : téléchargés et lus en entier le 11/10/2026, copies non conservées.
- Spécification SUPER PDP 1.36.0.beta (instantané).

**Doctrine**
- [ACTU-2026-00126](https://bofip.impots.gouv.fr/bofip/15140-PGP.html/ACTU-2026-00126).
- [BOI-RES-TVA-000253 du 18/02/2026](https://bofip.impots.gouv.fr/bofip/14901-PGP.html/identifiant=BOI-RES-TVA-000253-20260218).
- [BOI-TVA-CHAMP-10-10-40-20 du 19/01/2022](https://bofip.impots.gouv.fr/bofip/537-PGP.html/identifiant=BOI-TVA-CHAMP-10-10-40-20-20220119).
- [BOI-TVA-BASE-20-30](https://bofip.impots.gouv.fr/bofip/241-PGP.html/identifiant=BOI-TVA-BASE-20-30-20120912).
- [BOI-TVA-DECLA-40-10-20 du 30/06/2021](https://bofip.impots.gouv.fr/bofip/1079-PGP.html/identifiant=BOI-TVA-DECLA-40-10-20-20210630).
- [BOI-TVA-DECLA-20-10-20 du 16/02/2022](https://bofip.impots.gouv.fr/bofip/1149-PGP.html/identifiant=BOI-TVA-DECLA-20-10-20-20220216).
- [BOI-TVA-DECLA-10-10-20 du 22/10/2025](https://bofip.impots.gouv.fr/bofip/3218-PGP.html/identifiant=BOI-TVA-DECLA-10-10-20-20251022).
- [BOI-TVA-DED-10-30 du 18/01/2023](https://bofip.impots.gouv.fr/bofip/1505-PGP.html/identifiant=BOI-TVA-DED-10-30-20230118).
- [BOI-TVA-DED-40-10-10 du 08/01/2025](https://bofip.impots.gouv.fr/bofip/1111-PGP.html/identifiant=BOI-TVA-DED-40-10-10-20250108).
- [BOI-TVA-DECLA-40-10-10 du 01/07/2026](https://bofip.impots.gouv.fr/bofip/849-PGP.html/identifiant=BOI-TVA-DECLA-40-10-10-20260701).
- [BOI-TVA-BASE-10-20-60-20 du 24/02/2021](https://bofip.impots.gouv.fr/bofip/10303-PGP.html/identifiant=BOI-TVA-BASE-10-20-60-20-20210224).
- [BOI-BIC-PDSTK-20-20-10-10](https://bofip.impots.gouv.fr/bofip/1497-PGP.html).
- Douane : [actualité ATVAI 2022](https://www.douane.gouv.fr/actualites/partir-du-1er-janvier-2022-autoliquider-sa-tva-limportation-devient-obligatoire-et), [questions-réponses ATVAI](https://www.douane.gouv.fr/sites/default/files/2021-12/27/Questions-Reponses-Dispositif-ATVAI-2022-Operateurs.pdf), [TVA à l'importation : les essentiels](https://www.douane.gouv.fr/fiche/tva-limportation-les-essentiels).
- impots.gouv.fr : [TVA à l'importation](https://www.impots.gouv.fr/tva-limportation-0), [notice TVA à l'importation](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/210_declarer_payer/tva_a_limportation_-_notice.pdf), [FAQ livraison d'un DOM vers la métropole](https://www.impots.gouv.fr/professionnel/questions/je-fais-une-livraison-de-marchandises-en-metropole-depuis-un-dom-dois-je), [franchise européenne](https://www.impots.gouv.fr/je-souhaite-adherer-la-franchise-en-base-tva-europeenne), [notice CA3 2026 n° 50449#29](https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5426.pdf) (non ouverte).
- [Bercy Infos : numéro EORI](https://www.economie.gouv.fr/entreprises/importer-et-exporter-linternational/numero-eori-pourquoi-et-comment-lobtenir).
- [PCG consolidé au 1/1/2026 (ANC)](https://www.anc.gouv.fr/files/anc/files/1_Normes_fran%C3%A7aises/recueil/2026/PCG--1er-janvier-2026.pdf) (non ouvert).

**Sources secondaires**
- Legifiscal : [actualité 4650](https://www.legifiscal.fr/actualites-fiscales/4650-echanges-internationaux-nouvelles-mentions-obligatoires-factures-2027.html), [CA3 depuis 2022](https://www.legifiscal.fr/tva/declarations-tva/nouvelle-declaration-tva-ca3-2022.html), [franchise 2025](https://www.legifiscal.fr/tva/tva-francaise/regimes-specifiques/regime-franchise-base-tva-2025.html), [opérations avec les DROM](https://www.legifiscal.fr/tva/tva-internationale/operations-biens-drom-exemple.html), [autoliquidations françaises](https://www.legifiscal.fr/tva/tva-francaise/operations-imposables-exonerees/tva-cas-autoliquidation-operations-francaises.html).
- [Aequitas](https://www.aequitas.fr/facturation-report-des-nouvelles-mentions-obligatoires-au-1er-janvier-2027/) ; [Ma facture électronique](https://ma-facture-electronique.org/reforme-2026/nouvelles-mentions-obligatoires/).
- [Rodco](https://www.rodco.fr/memo-comment-fonctionne-l-autoliquidation-de-la-tva-a-l-importation) ; [L'Expert-Comptable](https://www.l-expert-comptable.com/a/7748-tva-sur-les-importations-guide-complet.html).
- [WebLex](https://www.weblex.fr/weblex-actualite/facturation-electronique-quel-sort-pour-les-factures-recues-par-mail) ; [Fiducial](https://www.fiducial.fr/facturation-electronique/actualites-conseils/facturation-electronique-sanctions-obligations-2026).
- [Hayot Expertise sur l'EMEBI](https://hayot-expertise.fr/blog/emebi-deb-des-echanges-intracommunautaires-2026) ; [Mathez Compliance](https://www.mathez-compliance.com/declarations-e-reporting/) ; [FactPulse](https://factpulse.fr/docs/article-e-reporting/).
- [gobl.fr.ctc](https://raw.githubusercontent.com/invopop/gobl.fr.ctc/main/addon/flow10/extensions.go) ; [COM(2020) 360](https://www.senat.fr/ue/pac/EUR000006232.html) ; [amendement I-1017 au PLF 2026](https://www.senat.fr/enseance/2025-2026/138/Amdt_I-1017.html) ; [Advizexperts, art. 258 A](https://advizexperts.fr/code-general-impots/article-258-a-cgi-ventes-distance-intracommunautaires-tva/).

---

## 10. Paramètres qui changent dans le temps (avec leur date d'effet connue)

| Paramètre | Valeur actuelle | Date d'effet connue | Porté par (Proposition) |
|---|---|---|---|
| Taux de métropole, de Corse et des DOM | Section 7 | En vigueur au 11/10/2026 ; révisables par toute loi de finances | Table datée (territoire × catégorie fiscale × période) |
| Références d'articles imprimées | CGI | Jusqu'au 31/12/2026 ; CIBS à partir du 1/1/2027 (ordonnance n° 2026-671) ; anciennes références admises jusqu'au 30/06/2028 | Table des mentions datée |
| Mentions par nature d'opération | Formules du CGI | Décret attendu, date inconnue | Idem |
| Quatre nouvelles mentions | Section 5 | GE et ETI au 1/9/2026 ; PME et micro-entreprises au 1/9/2027 | Catégorie d'entreprise du SIREN |
| Seuils de franchise | 85 000 € (93 500 €) pour les ventes ; 37 500 € (41 250 €) pour les services | 2026 (BOI du 01/07/2026) ; fin de franchise au jour du dépassement depuis le 1/1/2025 | Société (régime daté) |
| Franchise européenne | Plafond de 100 000 € dans l'Union, suffixe EX | Depuis le 1/1/2025 | Société |
| Seuil des ventes à distance et des services électroniques | 10 000 € HT par an pour l'UE entière | Depuis le 1/7/2021 | Société (compteur N-1 et N) |
| Seuil du régime dérogatoire des AIC | 10 000 € HT par an | Art. 256 bis, I-2° ; date d'effet non relue | Société (compteur N-1 et N, option) |
| Seuil de la détaxe | Plus de 100 € TTC | Depuis le 1/1/2021 | Magasin |
| Régime simplifié | Supprimé | 1/1/2027 | Société |
| Calendrier de l'e-reporting | Tenu par SUPER PDP | Émission et e-reporting des PME au 1/9/2027 | SUPER PDP |
| Format de l'EORI | FR + SIREN | EORI au SIRET désactivés au second semestre 2026 | Société |
| Libellés des lignes de la CA3 | Codification de 2022 (A3 renommée en 2023) | Notice millésimée (2026 : n° 50449#29) | Export comptable (étape 5) |
| Liste VATEX | Branche master du 11/10/2026 | Évolue par versions ; codes FR liés aux articles du CGI | Table versionnée |
| Taux de change | Règle à confirmer | — | Par facture |
| Taux des autres États membres (OSS) | Hors périmètre actuel | — | Table datée quand V8 sera traité |

---

## 11. Points à vérifier

1. **Numéros CIBS** des mentions : 293 B (L. 233-3, L. 223-3 ou L. 233-1 ?), 262 I (L. 213-14 ?), 262 ter I (L. 213-13 ?), 294 (L. 211-7 ?), 259-1° pour une prestation hors UE. Décret attendu sur les mentions par nature d'opération.
2. **Base légale de la mention de franchise** dans l'annexe II, et numérotation actuelle des 12°, 13° et 16° de l'art. 242 nonies A (version LEGIARTI000050811276).
3. **Lignes de la CA3** sur la notice 2026 (n° 50449#29) : A2 pour une autoliquidation interne ; A3 ou B4 pour des services hors UE ; E2 pour une prestation UE ; E1 ou E2 pour une livraison vers un DOM ; E3 pour une vente à distance ; F2 pour une LIC ; cases I1 à I6 de la TVA d'importation ; lignes 17, 18, 20 et 24.
4. **Exigibilité des services autoliquidés** (art. 283-2) : réalisation ou encaissement ; article du CGI à retrouver.
5. **Art. 258 D, I** et e-reporting des AIC non soumises (art. 290 I 4° b) : visent-ils les AIC d'un franchisé sous le seuil de 10 000 € ?
6. **Importation par une société en franchise** : autoliquidation possible ou paiement en douane ; maintien de la franchise.
7. **Importations dans un DOM** : autoliquidation applicable ; caractère récupérable de l'octroi de mer pour un commerçant ; effet en TVA des échanges entre Guadeloupe et Martinique.
8. **Taux de change** des factures d'achat en devise (date et source du taux).
9. **E-reporting « in »** (bac à sable SUPER PDP) : catégorie et montant de TVA à transmettre pour une AIC et pour un service autoliquidé ; facture étrangère reçue par Peppol déjà déclarée ou non ; achats auprès de Monaco.
10. **Facture papier** reçue d'un fournisseur soumis à l'émission électronique : effet sur la déduction (position de l'administration).
11. **Fournisseur de l'UE ayant facturé sa TVA** (A10) et **fournisseur franchisé dans son État** (A9) : traitement à faire valider par l'expert-comptable.
12. **Art. 283-1** : définition de « non établi » pour un fournisseur qui a un n° de TVA FR ou un représentant fiscal ; exceptions de la version du 22/10/2025 du BOFiP.
13. **Numérotation des art. 283-2 bis à 2 nonies** (autoliquidations internes), pour la liste d'exclusion.
14. **Durée et forme de l'option** d'un franchisé pour la taxation de ses AIC.
15. **PCG** : texte exact des art. 213-30 à 213-32 (coût d'entrée des stocks) dans la version consolidée au 1/1/2026 ; l'art. 213-8 vise les immobilisations.
16. **LIC** : faut-il une consultation VIES valide à la date de livraison en plus de celle du jour de l'émission (écart de `ControleNatureOperation`) ? Arrêt CJUE C-639/24 sur la preuve, non lu.
17. **Livraison vers un DOM** : catégorie EN 16931 (G et VATEX-EU-G, ou E avec un code propre) ; portée de VATEX-FR-CGI295.
18. **Ventes à distance** : version en vigueur du BOI-TVA-CHAMP-20-20-30 (celle de 2021 n'est plus publiée) ; maintien de l'art. 258 B (produits soumis à accise).
19. **Portée de la mention « option pour les débits »** sur un document qui ne vend que des biens.
20. **Taux** : absence de modification par la loi de finances pour 2026 (sort de l'amendement I-1017 sur le taux de 2,1 %) ; listes de la Corse et des DOM à faire valider par un expert-comptable.
21. **Importations de faible valeur** (150 € ou moins) faites par un assujetti : procédure et preuve.
22. **Franchise européenne** : plafond de 100 000 € et suffixe EX, à relire dans la directive 2020/285 et sur la page impots.gouv.
23. Points de la recherche (section 4.1) restés ouverts et non traités ici : détaxe, garantie de TVA d'un export enlevé, catégories B2C (TNT1), circuit d'un professionnel monégasque, assujetti unique.
