# TVA des ventes hors de France, déclaration à zéro, magasins d'un même SIREN

Synthèse vérifiée pour le développeur, état au 9 octobre 2026.

**Lecture.** Ce document ne garde que ce qui a résisté aux contre-vérifications. Quand un passage est marqué **Corrigé**, il remplace une affirmation des recherches initiales jugée fausse ou incomplète.

Niveaux de certitude utilisés :
- **TO** : texte officiel. Il a le plus souvent été lu dans un extrait de moteur de recherche, car le proxy bloquait les sites gouv.fr.
- **Doc** : doctrine (BOFiP, FAQ DGFiP, douane).
- **SS** : source secondaire (éditeurs, cabinets, implémentations).
- **Inc** : incertain.

La spécification SUPER PDP citée est la copie locale `/home/user/piliqiediteur/tests/Fixtures/SuperPdp/openapi.json` (v1.36.0.beta). Le budget de recherche web était épuisé au moment de cette synthèse : aucun point n'a pu être revérifié en ligne à ce stade (voir 4.4).

---

## 0. Textes et calendrier applicables

| Point | Retenu | Certitude |
|---|---|---|
| Recodification de la TVA dans le CIBS | Reportée du 1/9/2026 au **1/1/2027** par l'ordonnance n° 2026-671 du 27/07/2026. Jusqu'au 31/12/2026, les articles du CGI cités ici restent en vigueur. Seules les dispositions législatives migrent : les annexes restent dans le CGI, dont l'art. 242 nonies A de l'annexe II (mentions des factures). | SS ([fiscalonline](https://fiscalonline.com/Entreprise/report-1er-janvier-2027-recodification-tva-cibs), [cabinet Métay](https://www.cabinetmetay.com/le-transfert-des-dispositions-relatives-a-la-tva-dans-le-cibs-est-reporte-au-1er-janvier-2027/)) |
| Tolérance pour les anciennes références sur les factures | 30/06/2028 selon LégiFiscal et Aequitas, 31/12/2027 selon Abby : **à confirmer**. | SS |
| Futurs numéros d'articles du CIBS | L. 213-14 (export), L. 213-13 (LIC), L. 211-92 (autoliquidation), L. 211-7 (DOM), L. 233-3 (franchise, et non L. 223-3 selon la majorité des sources). Ce sont des **prévisions non vérifiées**. Un décret sur les mentions par nature d'opération est attendu. | SS |
| Décret n° 2026-677 du 27/07/2026 | Réécrit les art. 242 nonies B et suivants de l'annexe II : plateformes, annuaire, e-reporting des transactions (242 nonies O), données de paiement (242 nonies P). Ne change pas le calendrier. | TO (extraits) |
| Calendrier de la réforme | Réception obligatoire pour toutes les entreprises depuis le 1/9/2026. Émission et e-reporting : GE et ETI depuis le 1/9/2026, PME et micro-entreprises au **1/9/2027**. | TO |
| Nouvelles mentions obligatoires (SIREN du client, adresse de livraison si différente, catégorie de l'opération, option pour les débits) | GE et ETI au 1/9/2026, PME au 1/9/2027. | SS ([Lefebvre Dalloz](https://formation.lefebvre-dalloz.fr/actualite/facturation-electronique-lajout-des-nouvelles-mentions-sur-les-factures-egalement-reportee-2026)) |
| BOFiP sur l'e-reporting | BOI-TVA-DECLA-20-30-50 (et -20, -30) du 30/09/2026, [ACTU-2026-00145](https://bofip.impots.gouv.fr/bofip/15176-PGP.html/ACTU-2026-00145). Les commentaires sur l'art. 289 bis sont annoncés pour plus tard. | Doc |

**Recommandation de codage**
- Écrire les mentions d'exonération dans une table datée : référence au CGI jusqu'au 31/12/2026, puis référence au CIBS une fois confirmée.
- Ou bien utiliser la référence de la directive 2006/112/CE. Elle est admise par l'art. 242 nonies A de l'annexe II et par l'art. 226-11° de la directive : « la référence à la disposition pertinente du CGI ou de la directive, ou toute autre mention indiquant que l'opération est exonérée ».
- Le mot « Autoliquidation » est imposé tel quel (art. 242 nonies A I 13° ; art. 226 11 bis de la directive) et ne varie jamais.

---

## 1. TVA des ventes hors de France

### 1.1 Principe

Le taux ne dépend jamais de la nationalité ni du pays du client. Une opération ne sort de la TVA française que dans trois cas :
1. le bien quitte réellement le territoire de TVA français, et le vendeur peut le prouver : export, livraison vers un DOM, livraison intracommunautaire, vente à distance taxable dans le pays d'arrivée ;
2. le client est un professionnel établi hors de France et le service relève de la règle générale (art. 259-1° du CGI) ;
3. le magasin, ou le lieu de l'opération, relève d'un territoire à taux propre (Corse, Guadeloupe, Martinique, La Réunion) ou sans TVA (Guyane, Mayotte).

Restent au taux français :
- la vente en magasin à un touriste qui emporte ses achats : la détaxe est un remboursement après coup ;
- le particulier de l'UE qui emporte ses achats ;
- le professionnel étranger livré en France ;
- Monaco, traité comme la France ;
- le résident d'un DOM qui achète en métropole.

### 1.2 Choix du circuit de la réforme (Corrigé)

**Corrigé :** la recherche initiale envoyait en e-reporting B2BInt un client assujetti « absent de l'annuaire ». C'est faux, et les deux contre-vérifications concordent. Le circuit dépend de l'**établissement** du client et du **lieu** de l'opération (art. 289 bis et 290 du CGI), jamais de sa présence dans l'annuaire. L'annuaire sert seulement à trouver l'adresse 0225 du destinataire. Une adresse introuvable est une anomalie à traiter : vérifier le SIREN, alerter, et à titre exceptionnel envoyer par courriel ou par courrier selon les guides. Ce n'est jamais une bascule vers l'e-reporting.

| Situation | Circuit |
|---|---|
| Client assujetti établi en France, opération située en France (y compris une livraison exonérée vers la Guadeloupe, la Martinique ou La Réunion) | Facture électronique domestique (flux 2), avec alerte si l'adresse est introuvable |
| Client établi en France qui fait livrer dans un autre État membre sous un n° de TVA étranger | Livraison intracommunautaire (catégorie K) en flux 2, pas en B2BInt |
| Client assujetti **non établi** en France, que l'opération soit taxée en France ou non | E-reporting B2BInt, facture par facture : flux 10.1 (transactions) et 10.2 (paiements) |
| Client étranger dont un établissement en France intervient dans l'opération | Flux 2 |
| Client assujetti établi en Guyane ou à Mayotte (ou dans les COM, les TAAF) | E-reporting (art. 290 I) |
| Non-assujetti | E-reporting B2C : flux 10.3 (agrégé par jour, par catégorie TLB1, TPS1, TNT1 ou TMA1, par taux et par devise) et flux 10.4 (paiements) |
| Opération située hors de France entre deux assujettis établis en France (par exemple une prestation sur un immeuble à l'étranger pour un client français) | Aucun circuit |
| Entité publique | B2G par Chorus Pro. SUPER PDP ne traite que le B2B, le B2C et le B2BInt. |
| Monaco | Voir N1 |

**Règles transversales**
- **Champ de l'e-reporting.** Les exportations et les livraisons intracommunautaires restent dans le champ. L'exclusion exige deux conditions cumulées : une exonération fondée sur les art. 261 à 261 E, **et** une dispense de facture (art. 289) (Doc, ACTU-2026-00145). Une phrase de la page impots.gouv sur le B2B international semble exclure l'export et la LIC. La lecture retenue (inclus) est la plus cohérente, mais reste à confirmer.
- **Données de paiement.** Elles ne concernent que les prestations de services dont la TVA est exigible à l'encaissement, acomptes compris, hors option pour les débits et hors autoliquidation. Pour un ticket mixte, seule la part TPS1 va au flux 10.4.
- **Données de paiement d'une facture B2B domestique (Corrigé).** Elles ne passent pas par un flux d'e-reporting mais par le statut de cycle de vie **fr:212 « Encaissée »**, envoyé par le vendeur. La recherche initiale disait qu'elles étaient « transmises par la plateforme ».
- **Identifiant de l'acheteur en flux 10.1** (règle G1.73, SS d'après l'implémentation gobl.fr.ctc) :
  - 0223 : n° de TVA de l'UE ;
  - 0227 : client hors UE, code pays suivi d'au plus 16 caractères alphanumériques en majuscules tirés de son nom (aucun n° fiscal exigé) ;
  - 0228 : RIDET (Nouvelle-Calédonie) ;
  - 0229 : TAHITI (Polynésie française).
  
  Le vendeur s'identifie par son SIREN, schéma 0002.
- **Taux acceptés.** Les flux français n'acceptent que les taux français (règle G1.24 : 0 ; 0,9 ; 1,05 ; 1,75 ; 2,1 ; 5,5 ; 7 ; 8,5 ; 9,2 ; 9,6 ; 10 ; 13 ; 19,6 ; 20 ; 20,6 %). Une TVA étrangère n'est jamais transmise dans un flux français (SS).
- **Facture en devise.** La TVA doit être exprimée en euros (art. 230 de la directive) : BT-6 = EUR et total de TVA en euros en BT-111 (règle BR-53). Les contrôles des flux 2 et 10 rejettent une facture non convertible. L'agrégat B2C se fait aussi par devise (SS).

### 1.3 Règles par nature d'opération

Le taux « du territoire » est détaillé en 1.4.

Les règles EN 16931 suivantes ont été vérifiées mot pour mot sur le schematron officiel : BR-G-02, BR-IC-02, BR-IC-11, BR-IC-12, BR-AE-02, BR-O-02, BR-O-11 et BR-E-02. Les numéros « BR-FR-… » viennent de sources secondaires et varient selon les versions de l'annexe 7.

#### N1. Vente en France (valeur par défaut)

| | |
|---|---|
| Couvre | Tout ticket de caisse. Toute remise en magasin, quel que soit le client : français, résident de l'UE, résident hors UE sans détaxe, résident d'un DOM ou de Monaco. Toute livraison en métropole, en Corse ou à Monaco. Un professionnel étranger livré, ou qui retire, en France sans transport hors de France. Un professionnel de l'UE sans n° de TVA valide, même si le bien part dans un autre État membre. Une vente à distance UE sous le seuil, sans option ni OSS. Les services à un particulier, français ou étranger, hors exceptions. Les services à un client de l'UE sans n° valide, ou hors UE sans preuve de son statut. Les services de l'art. 259 A dont le lieu est en France. |
| Conditions | Biens : lieu de livraison en France (art. 258 I a et b du CGI). Services : lieu du prestataire (art. 259-2°). Un n° de TVA étranger ne suffit jamais à exonérer sans transport prouvé. Client de l'UE sans n° de TVA : livraison taxée « conformément au a du I de l'art. 258 » (BOFiP du 01/07/2026) ; ce n'est pas une vente à distance. Services : un preneur de l'UE sans n° est présumé non assujetti (art. 18 §2 du règlement 282/2011), mais une autre preuve est admise s'il a demandé son n° (art. 18 §1 b) ; hors UE, l'art. 18 §3 s'applique. Le vendeur établi en France reste redevable : l'art. 283-1 ne joue que pour un fournisseur non établi en France. |
| TVA | Taux fixé par le territoire du magasin et la catégorie fiscale du produit. Les taux de l'art. 297 s'appliquent aussi aux expéditions du continent vers la Corse des produits listés. |
| Mention | Mentions habituelles. Pour un client étranger, son identité et son adresse. Aucune mention d'exonération. |
| EN 16931 | Catégorie S, taux de la ligne, sans code VATEX. |
| Circuit | Particulier : B2C, TLB1 (biens) ou TPS1 (services), plus le flux 10.4 pour les services. Professionnel établi en France : flux 2. Professionnel non établi : B2BInt 10.1, identifiant 0223 ou 0227. Pour un client de l'UE sans n° valide, on ignore quel identifiant SUPER PDP accepte : à lui demander. |
| Monaco | TVA française (convention du 18/05/1963). Ce n'est ni une exportation ni une LIC. Les entreprises monégasques ont un n° de TVA en FR. Les résidents de Monaco sont exclus de la détaxe. Circuit pour un professionnel monégasque : non immatriculé au SIE de Menton, e-reporting B2BInt par le vendeur français ; établi en France ou entré volontairement dans le dispositif, flux 2 (SS). |
| Données | Territoire du magasin ; catégorie fiscale du produit ; bien ou service ; type et pays d'établissement du client ; n° de TVA ou identifiant étranger (pour le circuit) ; historique des consultations VIES. |
| Sources | [BOI-TVA-CHAMP-30-20-10 (01/07/2026)](https://bofip.impots.gouv.fr/bofip/697-PGP.html/identifiant=BOI-TVA-CHAMP-30-20-10-20260701) ; [BOI-TVA-CHAMP-20-50-20](https://bofip.impots.gouv.fr/bofip/1488-PGP.html/identifiant=BOI-TVA-CHAMP-20-50-20-20190925) ; [règlement 282/2011](https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX%3A02011R0282-20220701) ; [BOI-TVA-DECLA-20-30-50-20](https://bofip.impots.gouv.fr/bofip/13899-PGP.html/identifiant=BOI-TVA-DECLA-20-30-50-20-20260930) ; [Mathez (Monaco)](https://www.mathez-compliance.com/e-reporting-obligations-entreprises-monegasques/) |
| Certitude | TO, sauf Monaco (SS) |

#### N2. Vente en détaxe (voyageur résidant hors UE, BVE PABLO)

| | |
|---|---|
| Fondement | Art. 262 I 2° du CGI ; art. 24 bis de l'annexe IV ; arrêté d'abaissement du seuil (NOR CPAD2015083A : seuil passé de 175 € à 100 € au 1/1/2021) ; circulaire DGDDI DA 26-044 du 24/04/2026, qui abroge les circulaires 21-056 et 24-055. |
| Acheteur | Domicile ou résidence habituelle hors UE. C'est **la résidence qui compte, jamais la nationalité** : un Français résidant hors UE est éligible, et le BOFiP admet le cas d'un transfert de résidence vers un pays tiers. Exclus : résidents de l'UE, de Monaco et des cinq DROM (**Corrigé** : la recherche initiale n'excluait pas clairement les DOM, et une source commerciale qui affirme le contraire est fausse). Admis : résidents des collectivités d'outre-mer. Séjour en France de moins de 6 mois. 16 ans au moins. |
| Seuil | Valeur globale TTC supérieure à 100 €, soit 100,01 € en pratique. Regroupement possible des achats faits sur 3 jours consécutifs dans les magasins d'une même ville identifiés sous le même n° de TVA. Le regroupement dans un même centre commercial (circulaire du 10/12/2021, point 12) est **à revérifier** dans DA 26-044, qui abroge cette circulaire. |
| Biens | Biens sans caractère commercial, emportés dans les bagages personnels et sortis de l'UE avant la fin du 3e mois suivant l'achat. Exclus : tabacs manufacturés, biens d'approvisionnement commercial, biens dont la sortie est prohibée, biens d'équipement ou d'avitaillement de moyens de transport privés. |
| Procédure | BVE électronique émis dans PABLO, par un commerçant adhérent à PABLO-I ou par un opérateur de détaxe agréé. Il est émis sur présentation du passeport, jamais sur un duplicata de ticket. Seul le visa électronique obtenu à la sortie de l'UE prouve l'exportation. |
| TVA | Le ticket est établi TTC au taux français. Une fois le BVE visé, la vente devient une exportation exonérée : la TVA collectée est régularisée et le montant HT est déclaré en exportation sur la CA3. Sans visa, la TVA reste due. |
| Mention | Aucune n'est imposée. Mention conseillée : « Vente en détaxe – BVE n° … ». Le BVE ne peut être remplacé ni par un ticket ni par une facture. |
| EN 16931 | Sans objet (cadre B2C). Fiscalement, la vente devient une exportation après le visa. |
| Circuit | B2C 10.3, TLB1 avec la TVA française le jour de la vente. La déclaration de la bascule en exonération après le visa **n'est pas documentée**. |
| À coder | Seulement le **remboursement après visa**. Ne pas coder la détaxe immédiate (vente HT dès l'achat) tant que la DGFiP ou la plateforme n'a pas précisé sa déclaration en flux 10.3. Le remboursement au voyageur n'est pas un avoir commercial. Si le voyageur rend un article après l'émission du BVE, il faut annuler ou rectifier le BVE dans PABLO, en plus de l'avoir. |
| Non vérifié | Transmission des données au plus tard dans l'heure (note DGDDI du 3/4/2025) ; délai de 6 mois au-delà duquel la TVA d'un BVE non visé devient définitive ; conservation 10 ans ; contenu détaillé de DA 26-044 ; nouvelle version de PABLO-I au 31/03/2026 ; règles propres à un magasin situé dans un DOM. |
| Données | Pays de résidence et n° de passeport (saisis dans PABLO) ; cumul TTC sur la période autorisée ; n° et statut du BVE (émis, visé, non visé, expiré) ; lien entre le ticket et le BVE ; mode et frais de remboursement. |
| Sources | [DA 26-044](https://www.douane.gouv.fr/la-douane/informations/bulletins-officiels-des-douanes/da/26-044) ; [arrêté de 2020](https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000042334423) ; [circulaire du 19/08/2024](https://www.douane.gouv.fr/sites/default/files/ba_files/da/2024-08/Circulaire%20du%2019%20ao%C3%BBt%202024_0.pdf) ; [circulaire du 10/12/2021](https://www.douane.gouv.fr/sites/default/files/2021-12/14/Circulaire-10-12-2021-relative-a-la-procedure-des-BVE.pdf) ; [fiche douane PABLO](https://www.douane.gouv.fr/en/fiche/tax-exemption-france-tourists-pablo) |
| Certitude | TO pour les conditions ; Inc pour l'e-reporting |

#### N3. Export hors UE expédié par le magasin (client professionnel ou particulier)

| | |
|---|---|
| Fondement | Art. 262 I 1° du CGI ; art. 146 de la directive. L'exonération ne dépend pas du statut du client. |
| Conditions | Le bien quitte physiquement l'UE, transporté par le vendeur ou pour son compte. Preuve : déclaration d'exportation électronique avec la certification de sortie ECS (MRN), ou exemplaire 3 en cas de panne ; une preuve alternative est admise depuis 2013 ; pour un colis, le CN23 rempli en ligne. Sans preuve, la TVA française est due. L'adresse de livraison est contrôlée par le pays **et par le code postal** (territoires exclus, voir 1.4). |
| TVA | Exonération, avec droit à déduction de la TVA d'amont. |
| Mention | « Exonération de TVA, article 262 I du CGI » ou « Exonération de TVA – article 146 de la directive 2006/112/CE ». |
| EN 16931 | Catégorie G, VATEX-EU-G, taux 0. BR-G-02 : n° de TVA du vendeur (BT-31) ou de son représentant fiscal (BT-63). Raison d'exonération obligatoire (BT-121 ou BT-120). G peut coexister avec S dans un même document. |
| Circuit | Client professionnel non établi : B2BInt 10.1. Particulier : B2C 10.3 en **TNT1**, seule catégorie adaptée et valeur par défaut des implémentations, mais aucune source officielle ne cite l'export à un particulier. Pas de données de paiement pour des biens. |
| Preuve absente | **Corrigé :** on ne « repasse » pas la facture émise en vente taxée. Il faut un avoir et une nouvelle facture (ou une facture rectificative), puis une correction de l'e-reporting. |
| Pays d'arrivée | À signaler au magasin, sans calcul : Royaume-Uni (TVA britannique collectée par le vendeur sur les envois de 135 £ ou moins), Suisse (immatriculation au-delà de 100 000 CHF de petits envois), Norvège (régime VOEC). |
| Non vérifié | Format EORI FR + SIREN au 2e semestre 2026 ; seuil de 1 000 € au-delà duquel La Poste dépose la déclaration en douane ; « comptabilité propre aux exportateurs ». |
| Données | Adresse et pays de livraison ; mode « expédié par le magasin » ; date d'expédition ; MRN ou n° de CN23 ; EORI ; Incoterm ; valeur de l'envoi. |
| Sources | [BOI-TVA-CHAMP-30-30-10-10](https://bofip.impots.gouv.fr/bofip/2266-PGP.html/identifiant=BOI-TVA-CHAMP-30-30-10-10-20131118) ; [ACTU-2013-00127](https://bofip.impots.gouv.fr/bofip/7891-PGP.html/identifiant=ACTU-2013-00127) ; [douane, TVA à l'export](https://www.douane.gouv.fr/fiche/tva-lexportation) ; [schematron EN 16931](https://raw.githubusercontent.com/ConnectingEurope/eInvoicing-EN16931/master/ubl/schematron/abstract/EN16931-model.sch) ; [ACTU-2026-00145](https://bofip.impots.gouv.fr/bofip/15176-PGP.html/ACTU-2026-00145) |
| Certitude | TO (TNT1 : SS) |

#### N4. Export de biens enlevés en magasin par un acquéreur non établi en France

| | |
|---|---|
| Fondement | Art. 262 I 2° du CGI (BOI-TVA-CHAMP-30-30-10-20). **Corrigé :** ce régime vise tout acquéreur non établi en France, pas seulement un professionnel. Le BVE (N2) est la modalité propre aux voyageurs. |
| Conditions | L'acquéreur ou son transporteur transporte les biens hors de l'UE. Preuve : déclaration d'exportation au nom de l'acquéreur, avec la certification de sortie ECS. Sont exclus les biens d'équipement ou d'avitaillement de moyens de transport privés. Sans preuve, la TVA française est due. |
| TVA, mention, EN 16931 | Comme N3 : mention « article 262 I 2° du CGI » ou art. 146 de la directive ; catégorie G, VATEX-EU-G ; BT-31 obligatoire. |
| Garantie de TVA | **Corrigé :** ne jamais émettre une facture en catégorie G en ajoutant de la TVA à côté. Deux voies possibles : facturer en S (TTC), puis émettre un avoir et une facture G à réception de la preuve ; ou facturer en G et encaisser une consignation hors facture. Les conséquences en e-reporting diffèrent : choix à faire valider par l'expert-comptable. |
| Circuit | B2BInt 10.1 (professionnel non établi) ; B2C TNT1 (particulier non établi, hors détaxe). |
| Données | Statut et pays d'établissement de l'acquéreur ; transporteur ; MRN et certification de sortie ; date de réception de la preuve. |
| Sources | [BOI-TVA-CHAMP-30-30-10-20](https://bofip.impots.gouv.fr/bofip/1545-PGP.html/identifiant=BOI-TVA-CHAMP-30-30-10-20-20260211) |
| Certitude | Doc |

#### N5. Livraison de biens expédiés de la métropole vers un DOM (art. 294)

| | |
|---|---|
| Conditions | Les DOM sont des territoires d'exportation par rapport à la métropole et entre eux (art. 294-2 du CGI). Il faut une déclaration d'exportation (ou un CN23) et une preuve d'expédition (LTA, CMR, connaissement). La TVA du DOM (8,5 % ou 2,1 %, aucune en Guyane et à Mayotte) et l'octroi de mer sont perçus à l'importation dans le DOM. Ne s'applique pas au résident d'un DOM qui achète en magasin en métropole : TVA française, sans détaxe. |
| TVA | Exonération en métropole. |
| Mention | « Exonération de TVA en application de l'article 294 du Code général des impôts » (formule de la FAQ impots.gouv). |
| EN 16931 | Catégorie G avec VATEX-EU-G : plausible, mais **sans source officielle**. Une catégorie E avec un VATEX propre n'est pas exclue. À confirmer avant de coder. |
| Circuit (Corrigé) | Client professionnel établi en Guadeloupe, en Martinique ou à La Réunion : **flux 2**, car les deux parties sont établies en France (FAQ DROM du 21/01/2026, fiche DOM-COM). La recherche initiale indiquait à tort l'e-reporting B2BInt. Client professionnel en Guyane ou à Mayotte : e-reporting (art. 290 I). Particulier : B2C TNT1. |
| Données | Département de livraison (971 à 976) ; mode « expédié » ; MRN ou CN23 ; document de transport. |
| Sources | [FAQ impots.gouv, livraison vers les DOM](https://www.impots.gouv.fr/professionnel/questions/depuis-la-metropole-je-fais-une-livraison-de-marchandises-dans-les-dom-dois) ; [FAQ DROM](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/faq_drom.pdf) ; [fiche DOM-COM](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/fiches_reforme/fiche-domcom.pdf) ; [douane, TVA dans les DOM](https://www.douane.gouv.fr/fiche/tva-dans-les-dom) |
| Certitude | Doc (catégorie EN 16931 : Inc) |

#### N6. Livraison intracommunautaire (LIC) à un professionnel identifié dans un autre État membre

| | |
|---|---|
| Fondement | **Corrigé :** art. 262 ter I 1° du CGI (la référence « art. 262 I ter 1° » n'existe pas) ; art. 138 de la directive. |
| Conditions de fond | 1. L'acquéreur est un assujetti, ou une personne morale non assujettie, identifié à la TVA dans un **autre** État membre ; un n° FR ne permet pas l'exonération. Selon le Conseil d'État, il ne doit pas relever d'un régime dérogatoire. 2. Il a communiqué son n° **avant le début du transport**, et ce n° est valide à la date de la livraison. La validité se vérifie sur VIES, et la consultation horodatée est conservée. 3. Le bien est réellement transporté de France vers un autre État membre. 4. Le vendeur dépose l'état récapitulatif (art. 289 B), mensuel, dès le premier euro, auprès des douanes. C'est une condition de fond, sauf manquement dûment justifié. L'EMEBI statistique n'est due que sur sollicitation des douanes. |
| Preuve du transport (Corrigé) | Présomption de l'art. 45 bis du règlement 282/2011 : soit **deux documents de la catégorie a)** (CMR signée, connaissement, facture de fret, facture du transporteur), soit **un document de la catégorie a) et un de la catégorie b)** (assurance du transport, preuve bancaire du paiement du transport, document d'une autorité publique, récépissé d'entrepositaire). Ces documents doivent émaner de deux parties indépendantes du vendeur et de l'acquéreur. À défaut, la preuve reste libre (l'arrêt CJUE C-639/24 du 13/11/2025 n'a pas été vérifié). |
| Enlèvement par l'acquéreur | En plus des deux éléments de preuve : **déclaration écrite de l'acquéreur remise au plus tard le 10 du mois suivant la livraison**. Elle indique la date d'émission, le nom et l'adresse de l'acquéreur, la quantité et la nature des biens, la date et le lieu d'arrivée, le moyen de transport et l'identité de la personne qui accepte les biens. Le risque reste au vendeur : consigner la TVA tant que les preuves manquent. |
| Délai de facture (ajout) | Au plus tard le **15 du mois suivant** la livraison (art. 289 I 3 du CGI, art. 222 de la directive). Cette date fixe aussi l'exigibilité. |
| TVA | Exonération. L'acquéreur autoliquide la TVA de son pays au titre d'une acquisition intracommunautaire. |
| Mention | « Exonération de TVA, article 262 ter I du CGI » ou « Exonération de TVA – article 138 de la directive 2006/112/CE ». Le n° de TVA du vendeur **et** celui de l'acquéreur sont obligatoires ; leur présence ne prouve pas à elle seule que les conditions sont remplies. **Corrigé :** LégiFiscal qualifie à tort cette mention d'« autoliquidation » ; c'est une exonération. |
| EN 16931 | Catégorie K, VATEX-EU-IC, taux 0. BR-IC-02 : n° de TVA du vendeur (ou de son représentant fiscal) et de l'acheteur (BT-48). BR-IC-11 : date de livraison (BT-72) ou période de facturation (BG-14). BR-IC-12 : pays de livraison (BT-80). |
| Circuit | Acquéreur non établi en France : B2BInt 10.1, en plus de l'état récapitulatif. Client établi en France qui présente un n° étranger : catégorie K en flux 2. |
| Irlande du Nord | Biens : LIC possible avec un client identifié en XI. Services : régime hors UE. |
| N° absent ou invalide | TVA française (N1). Un résultat « invalide » peut venir d'un retard de synchronisation des bases nationales : réessayer, demander au client une attestation de son administration, conserver chaque consultation. Ne jamais appliquer l'exonération avant d'avoir vérifié le n°. |
| Données | N° de TVA du client, avec la date, le résultat et le n° de chaque consultation VIES ; pays de destination (XI accepté pour les biens) ; adresse de livraison ; date d'expédition ; transporteur et documents de transport ; attestation de l'acquéreur ; données de l'état récapitulatif. |
| Sources | [BOI-TVA-CHAMP-30-20-10 (01/07/2026)](https://bofip.impots.gouv.fr/bofip/697-PGP.html/identifiant=BOI-TVA-CHAMP-30-20-10-20260701) ; [règlement 282/2011](https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX%3A02011R0282-20220701) ; [schematron EN 16931](https://raw.githubusercontent.com/ConnectingEurope/eInvoicing-EN16931/master/ubl/schematron/abstract/EN16931-model.sch) ; [liste VATEX](https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/) |
| Certitude | TO |

#### N7. Vente à distance intracommunautaire à un particulier (bien expédié par le magasin)

| | |
|---|---|
| Fondement | Art. 258 A I 1° et 259 D du CGI, applicables depuis le 1/7/2021. Si le particulier emporte lui-même le bien, c'est N1. |
| Calcul | La TVA française s'applique si **toutes** ces conditions sont réunies. (a) Le cumul des ventes à distance dans l'UE et des services de télécommunication, de radiodiffusion et électroniques fournis à des particuliers de l'UE reste sous 10 000 € HT, sur l'année civile précédente **et** sur l'année en cours ; les ventes en France sont exclues du calcul. (b) Le vendeur est établi dans un seul État membre. (c) Il n'a pas opté pour la taxation à l'arrivée. (d) Il **n'est pas inscrit au guichet OSS régime Union**. **Corrigé (ajout) :** l'inscription à l'OSS vaut présomption d'option (BOI-TVA-CHAMP-20-20-30) ; l'option vaut au moins deux années civiles et se renouvelle tacitement. Sinon, la TVA du pays d'arrivée s'applique dès l'opération qui fait franchir le seuil. |
| TVA | Sous le seuil : taux français. Au-delà, ou en cas d'option ou d'inscription OSS : taux du pays d'arrivée pour la catégorie du produit, déclaré via l'OSS (art. 298 sexdecies G, déclaration trimestrielle) ou par une immatriculation locale. |
| Facture | Sous OSS : **dispense de facture**, transposition confirmée à l'art. 289 I 1 b du CGI (art. 220 §1 2° de la directive). Sans OSS : règles de facturation de l'État d'arrivée. |
| Mention | Sous le seuil : mentions habituelles. Au-delà : aucune mention légale propre. Mention conseillée : « TVA [pays] x % – vente à distance intracommunautaire (art. 258 A du CGI), déclarée via le guichet unique OSS ». |
| EN 16931 | Catégorie S au taux du pays d'arrivée, si un document est émis. |
| Circuit | Sous le seuil : B2C TLB1. Au-delà : B2C **TNT1**, avec le montant HT, **sans TVA française**, et sans jamais transmettre la TVA étrangère (refusée par la règle G1.24). L'exclusion de l'e-reporting citée par la fiche DGFiP vise les vendeurs **non établis** en France inscrits à l'OSS, pas un magasin français. Certitude moyenne : un cabinet affirme l'inverse. |
| Avoirs | La rectification se fait dans une déclaration OSS ultérieure, pendant 3 ans, sans modifier la déclaration d'origine. |
| Exclusions | Produits soumis à accises (alcools, tabacs) : le lieu est toujours l'État d'arrivée, sans seuil (art. 258 B), et les accises ne passent pas par l'OSS. Moyens de transport neufs (art. 298 sexies) : LIC exonérée même vers un particulier. Ces deux cas sont exclus des natures automatiques. |
| Données | Pays d'arrivée ; table des taux de chaque État membre par catégorie de produit ; compteur du seuil (année précédente et année en cours) ; établissements dans d'autres États membres ; option ; inscription OSS (date) ; registre OSS conservé 10 ans. |
| Sources | [art. 258 A](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000048835152) ; [BOI-TVA-CHAMP-20-20-30](https://bofip.impots.gouv.fr/bofip/13159-PGP.html/identifiant=BOI-TVA-CHAMP-20-20-30-20211222) ; [art. 289](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000046195645) ; [notes explicatives de la Commission](https://vat-one-stop-shop.ec.europa.eu/system/files/2021-07/vatecommerceexplanatory_notes_28102020_en.pdf) ; [fiche DGFiP e-reporting des transactions](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/fiches_reforme/fiche-e-reporting_transactions.pdf) |
| Certitude | TO (catégorie e-reporting : SS) |

#### N8. Prestation à un professionnel établi dans un autre État membre (règle générale)

| | |
|---|---|
| Conditions | Art. 259-1° du CGI (art. 44 de la directive) : le lieu est l'État du preneur. Le preneur communique un n° de TVA valide, vérifié sur VIES (art. 18 §1 du règlement 282/2011) ; sinon, c'est N1. Les services de l'art. 259 A sont exclus (voir N10). Le prestataire dépose la DES, mensuelle, auprès des douanes, dès le premier euro. Facture au plus tard le 15 du mois suivant. |
| TVA | Pas de TVA française. Le preneur autoliquide la TVA de son pays (art. 196 de la directive). |
| Mention (Corrigé) | « Autoliquidation » (texte imposé), avec les deux n° de TVA. Peut être complétée par « art. 259-1° du CGI – art. 44 et 196 de la directive 2006/112/CE ». **Ne pas citer l'art. 283-2 du CGI**, que proposait la recherche initiale : il vise le cas inverse (preneur établi en France, prestataire non établi). |
| EN 16931 | Catégorie AE, VATEX-EU-AE, taux 0. BR-AE-02 : BT-31, BT-32 ou BT-63 pour le vendeur ; BT-48 ou BT-47 pour l'acheteur. AE peut coexister avec K ou S. |
| Circuit | B2BInt 10.1. Pas de données de paiement, puisque l'opération est autoliquidée. |
| Données | N° de TVA du preneur et consultation VIES ; pays d'établissement ; catégorie du service (qui ne doit pas être « lieu spécial ») ; données de la DES. |
| Sources | [BOI-TVA-CHAMP-20-50-20](https://bofip.impots.gouv.fr/bofip/1488-PGP.html/identifiant=BOI-TVA-CHAMP-20-50-20-20190925) ; [question écrite AN 19197](https://questions.assemblee-nationale.fr/dyn/14/questions/QANR5L14QE19197.pdf) ; [schematron EN 16931](https://raw.githubusercontent.com/ConnectingEurope/eInvoicing-EN16931/master/ubl/schematron/abstract/EN16931-model.sch) |
| Certitude | TO |

#### N9. Prestation à un professionnel établi hors UE (règle générale)

| | |
|---|---|
| Conditions | Art. 259-1° du CGI : le lieu est hors de France, l'opération est hors du champ de la TVA française. Le statut d'assujetti se prouve par un certificat, un n° de TVA ou un équivalent, ou tout autre élément (art. 18 §3 du règlement 282/2011). La facture suit les règles françaises (art. 219 bis §2 b de la directive). Pas de DES. Les services à un professionnel d'Irlande du Nord relèvent de cette nature. |
| TVA | Hors champ. |
| Mention | Usage, aucune formule n'étant imposée : « TVA non applicable – article 259-1° du CGI » (ou « art. 44 de la directive 2006/112/CE »). |
| EN 16931 | Catégorie O, VATEX-EU-O. **Contraintes confirmées mot pour mot, toutes deux bloquantes :** BR-O-02 interdit BT-31, BT-63 et BT-48 sur une facture en catégorie O ; BR-O-11 interdit toute autre catégorie dans une facture qui contient du O. Conséquences : **document séparé obligatoire**, pour les factures comme pour les avoirs ; le n° de TVA FR du vendeur doit être porté en BT-32, avec le SIREN en BT-30. Cette dernière piste est compatible avec la norme mais doit être confirmée par SUPER PDP. |
| Circuit | B2BInt 10.1, acheteur identifié en 0227. |
| Données | Pays hors UE ; preuve du statut d'entreprise (identifiant fiscal étranger, certificat). |
| Sources | [BOI-TVA-CHAMP-20-50-20](https://bofip.impots.gouv.fr/bofip/1488-PGP.html/identifiant=BOI-TVA-CHAMP-20-50-20-20190925) ; [schematron EN 16931](https://raw.githubusercontent.com/ConnectingEurope/eInvoicing-EN16931/master/ubl/schematron/abstract/EN16931-model.sch) ; [gobl flow10 org.go](https://raw.githubusercontent.com/invopop/gobl.fr.ctc/main/addon/flow10/org.go) |
| Certitude | TO (BT-32 : Inc) |

#### N10. Prestation dont le lieu est hors de France (saisie manuelle)

| | |
|---|---|
| Cas (a) : lieu spécial à l'étranger (art. 259 A) | Location de courte durée d'un moyen de transport (30 jours au plus, 90 pour un bateau), au lieu de mise à disposition. Services liés à un immeuble, au lieu de l'immeuble. Transport de passagers, selon les distances parcourues. Accès à une manifestation pour un assujetti, au lieu de la manifestation. Ventes à consommer sur place, au lieu d'exécution. La numérotation des alinéas (1°, 2°, 3°, 5° bis, 6° a) est donnée de mémoire et **reste à vérifier** sur la version issue de la loi du 29/12/2023 (qui a modifié au 1/1/2025 le lieu des manifestations virtuelles pour les non-assujettis). |
| Cas (b) : service immatériel (art. 259 B) à un particulier domicilié hors UE | Conseil, publicité, traitement de données, location de biens meubles corporels autres que des moyens de transport, cession de droits, mise à disposition de personnel. Interprétation stricte (le rescrit du 14/01/2026 n'a pas été vérifié). L'art. 259 C peut relocaliser la prestation en France. Ne jamais l'appliquer automatiquement. |
| Si le lieu est en France | N1 : TVA française, même pour un client étranger. |
| TVA | Pas de TVA française. La TVA du pays concerné est due, ce qui peut imposer une immatriculation sur place, ou une autoliquidation si le droit local la prévoit : **en avertir le magasin**. |
| Mention | Lieu spécial : selon le droit local (« Autoliquidation » si le pays la prévoit). Art. 259 B : usage « TVA non applicable – article 259 B du CGI ». |
| EN 16931 | Catégorie O, VATEX-EU-O, avec les mêmes contraintes BR-O-02 et BR-O-11 qu'en N9 ; ou AE si le pays prévoit l'autoliquidation. Pour l'art. 259 B en B2C, aucune facture structurée n'est émise : la réserve sur BR-O-02 ne bloque pas. |
| Circuit (précisé) | Client non établi en France : B2BInt 10.1. Client établi en France avec un lieu à l'étranger : **aucun circuit**. Art. 259 B : B2C **TNT1**, catégorie qui vise expressément cet article. |
| Ajout | Location de longue durée d'un moyen de transport à un particulier : imposée au lieu de son domicile (art. 259 A, à vérifier). |
| Sources | [art. 259 A](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000048835181) ; [BOI-TVA-CHAMP-20-50-30](https://bofip.impots.gouv.fr/bofip/1490-PGP.html/identifiant=BOI-TVA-CHAMP-20-50-30-20201204) ; [BOI-TVA-CHAMP-20-50-50](https://bofip.impots.gouv.fr/bofip/1495-PGP.html/identifiant=BOI-TVA-CHAMP-20-50-50-20190925) ; [gobl flow10](https://pkg.go.dev/github.com/invopop/gobl.fr.ctc/addon/flow10) |
| Certitude | TO sur le principe ; Doc pour l'art. 259 B |

#### N11. Prestation de la métropole à un client établi dans un DOM

| | |
|---|---|
| Conditions | Pour les services, les DOM ne sont pas des territoires d'exportation : on applique les règles de lieu entre territoires (BOI-TVA-GEO-20-40). |
| TVA | Client assujetti en Guadeloupe, en Martinique ou à La Réunion : taux du DOM (8,5 % ou 2,1 %), **collecté par le prestataire**, sans autoliquidation. Client assujetti en Guyane ou à Mayotte : pas de TVA. Particulier : taux de la métropole. Les exceptions de l'art. 259 A suivent le lieu matériel. |
| Mention | Guadeloupe, Martinique, La Réunion : mentions habituelles au taux du DOM. Guyane, Mayotte : usage « TVA non applicable – article 294-1 du CGI ». |
| EN 16931 | Guadeloupe, Martinique, La Réunion : catégorie S au taux du DOM (8,5 % est accepté par la règle G1.24). Guyane, Mayotte : catégorie O, VATEX-EU-O, document séparé (BR-O-11), n° de TVA du vendeur hors BT-31 (BR-O-02). |
| Circuit | Guadeloupe, Martinique, La Réunion : flux 2. Guyane, Mayotte : e-reporting (art. 290 I). Particulier : B2C TPS1. (FAQ DROM) |
| Sources | [impots.gouv, prestations métropole-DOM](https://www.impots.gouv.fr/professionnel/tva-sur-prestations-de-services-metropoledom) ; [BOI-TVA-GEO-20-40](https://bofip.impots.gouv.fr/bofip/792-PGP.html/identifiant=BOI-TVA-GEO-20-40-20211222) ; [FAQ DROM](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/faq_drom.pdf) |
| Certitude | Doc |

### 1.4 Taux selon le territoire du magasin, et destinations particulières

| Territoire | Taux retenus | Remarques |
|---|---|---|
| Métropole | 20 ; 10 ; 5,5 ; 2,1 % | |
| Corse (art. 297, [BOI-TVA-GEO-10-10](https://bofip.impots.gouv.fr/bofip/903-PGP.html/identifiant=BOI-TVA-GEO-10-10-20241120)) | 13 % : produits pétroliers. 10 % : ensemble des travaux immobiliers (sauf rénovation énergétique, à 5,5 %), matériels agricoles d'une liste fixée par arrêté, ventes à consommer sur place. 2,1 % : liste de l'art. 297 I 1 2°. 0,9 % : opérations des art. 281 quater et 281 sexies. Hors de ces listes : taux du continent. | **Corrigé :** la liste à 2,1 % **exclut** margarines, graisses végétales, caviar, confiseries, chocolat (sauf exceptions) et boissons alcooliques ; la recherche initiale citait au contraire margarines et caviar comme exemples. Boissons alcooliques consommées sur place : **10 %** selon la première contre-vérification (BOFiP), tandis que la seconde demande de le vérifier en priorité. Ne pas coder avant confirmation. Électricité au-delà de 36 kVA à 10 % : non confirmé. Les taux corses s'appliquent aussi aux expéditions du continent vers la Corse (ventes franco-domicile ; franco-quai et départ sous conditions). |
| Guadeloupe, Martinique, La Réunion (art. 296) | 8,5 % ; 2,1 % ; 1,75 % (animaux vivants de boucherie et de charcuterie vendus à des non-assujettis) ; 1,05 % (140 premières représentations de l'art. 281 quater ; publications de presse de l'art. 298 septies) | Entièrement dans la réforme. L'octroi de mer est hors du champ du logiciel. |
| Guyane, Mayotte (art. 294-1) | TVA non applicable | Hors du champ de la facture électronique. Les opérations avec un client assujetti qui y est établi relèvent de l'e-reporting (art. 290 I). La catégorie O impose des documents séparés. Pour un magasin guyanais ou mahorais, l'e-reporting de l'art. 290 II peut s'appliquer à certaines livraisons vers la métropole. |
| Monaco | Comme la métropole | Voir N1. |

Conséquence : le taux d'une ligne se résout par le couple **territoire × catégorie fiscale du produit**, jamais par une simple liste de taux. La table des catégories fiscales pour la Corse et les DOM doit être validée par un expert-comptable.

**Destinations à traiter comme pays tiers** (export N3 pour les biens), repérées par le pays **et** le code postal :
- territoires exclus du territoire de TVA de l'UE : Canaries, Ceuta, Melilla, îles Åland, mont Athos, Heligoland, Büsingen, Livigno, Campione d'Italia ;
- collectivités d'outre-mer : Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon, Polynésie française, Nouvelle-Calédonie, Wallis-et-Futuna ;
- Royaume-Uni, île de Man (traitée comme le Royaume-Uni), îles Anglo-Normandes, Gibraltar ;
- Andorre, Saint-Marin, Vatican, îles Féroé, Groenland ;
- les DOM, qui suivent l'art. 294 (N5).

Irlande du Nord : règles de l'UE pour les biens (LIC avec un n° XI), régime hors UE pour les services. Les catégories EN 16931 L (Canaries) et M (Ceuta, Melilla) ne servent qu'aux vendeurs établis dans ces territoires. Identifiants B2BInt : RIDET (schéma 0228, 9 ou 10 caractères) et TAHITI (schéma 0229, 9 caractères). (Doc, [BOI-TVA-CHAMP-20-10](https://bofip.impots.gouv.fr/bofip/1340-PGP.html/identifiant=BOI-TVA-CHAMP-20-10-20210813))

### 1.5 Franchise en base (réglage du magasin, pas une nature d'opération)

| | |
|---|---|
| Seuils 2026 | Ventes : 85 000 € (seuil majoré 93 500 €). Services : 37 500 € (seuil majoré 41 250 €). Le seuil unique de 25 000 € a été supprimé par la loi du 3/11/2025. Les seuils s'apprécient sur le chiffre d'affaires de **toute l'entreprise** (le SIREN). |
| Mention | « TVA non applicable, art. 293 B du CGI » jusqu'au 31/12/2026. Ensuite, art. L. 233-3 du CIBS selon la majorité des sources (non vérifié), l'ancienne mention restant tolérée jusqu'au 30/06/2028 ou au 31/12/2027. Exigée sur la facture ; les textes consultés ne l'imposent pas sur un simple ticket. |
| EN 16931 | Catégorie E, VATEX-FR-FRANCHISE, taux 0 (règle BR-FR-CO-16 selon Dolibarr). BR-E-10 : raison d'exonération obligatoire. **BR-E-02** exige BT-31, BT-32 ou BT-63. Un franchisé qui n'a pas de n° de TVA intracommunautaire doit donc en demander un (il peut l'obtenir sans perdre la franchise) ou renseigner BT-32, ce que SUPER PDP doit valider. Dans le transcodage des flux 1 et 10.1, la catégorie BT-118 deviendrait Z, sans VATEX (annexe 7 v1.6, SS). |
| Circuit | Flux 2, B2BInt ou B2C selon le client. Catégorie B2C : TNT1, retenue par des éditeurs et acceptée par le bac à sable. **Corrigé :** certitude Inc, et non TO. TNT1 signifie « non soumis à la TVA en France », alors qu'une vente en franchise est située en France et seulement dispensée de paiement. |
| Paiements (Corrigé) | Ils ne sont pas « sans objet », contrairement à ce que disait la recherche initiale. L'art. 242 nonies P prévoit une transmission **bimestrielle** pour les franchisés. Un franchisé qui encaisse des prestations de services auprès de particuliers doit la prévoir, au taux 0. |
| International | Une LIC réalisée par un franchisé est couverte par la franchise, pas par l'art. 262 ter. Une vente à distance au-delà de 10 000 € est taxable dans le pays d'arrivée. Un service rendu à un professionnel de l'UE est taxable chez le preneur, ce qui suppose un n° de TVA et une DES. La franchise européenne (suffixe EX, plafond de 100 000 € dans l'UE) existe depuis le 1/1/2025. |
| Sources | [BOI-TVA-DECLA-20-30-50](https://bofip.impots.gouv.fr/bofip/13897-PGP.html/identifiant=BOI-TVA-DECLA-20-30-50-20260930) ; [art. 242 nonies P](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000054552572/2026-07-30) ; [FAQ DGFiP](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/faq---fe_je-decouvre-la-facturation-electronique.pdf) ; [EuroCompta](https://eurocompta.eu/fr/guides/franchise-en-base-tva-seuils-2026/) ; [Synapx](https://synapx.fr/blog/mention-tva-franchise-cibs-facture/) |

### 1.6 Frais accessoires, avoirs, changement de nature

- **Frais accessoires.** L'accessoire suit le principal : le port, l'emballage et l'assurance facturés avec la vente prennent la nature, la catégorie et la mention du bien vendu (par exemple K pour des frais de port sur une LIC).
- **Avoir.** Il reprend la nature, le taux, la catégorie, le VATEX et la mention de la facture d'origine.
  - Un avoir (code 381) cite au moins une facture d'origine avec sa date (BT-25, BT-26 ; règle BR-FR-CO-05). Une facture rectificative (code 384) en cite une seule (BR-FR-CO-04). Ces deux règles sont bloquantes selon Dolibarr.
  - Un avoir multi-factures ne peut pas mélanger une facture en catégorie O avec une autre catégorie (BR-O-11).
- **Corrections annexes.**
  - LIC : l'avoir corrige aussi l'état récapitulatif.
  - Vente via l'OSS : correction dans une déclaration ultérieure, pendant 3 ans.
  - Détaxe : le remboursement au voyageur n'est pas un avoir commercial.
- **Changement de nature après émission** (preuve d'export absente, BVE non visé, LIC non prouvée) : **jamais par modification**. Il faut un avoir et un nouveau document, cohérents avec la caisse sécurisée (art. 286) et avec l'e-reporting déjà transmis.

### 1.7 Cas exclus des natures automatiques (saisie manuelle ou refus)

| Cas | Règle retenue | Certitude |
|---|---|---|
| Produits soumis à accises vendus à distance dans l'UE | Lieu dans l'État d'arrivée, sans seuil (art. 258 B). Les tabacs sont aussi exclus de la détaxe. | SS |
| Moyens de transport neufs (art. 298 sexies) | LIC exonérée, même vers un particulier de l'UE | SS |
| Régime de la marge (étape 14) | B2C en TMA1. Facture en catégorie E avec VATEX-EU-F, I ou J. Mention « Régime particulier – Biens d'occasion » (ou objets d'art, antiquités). Pas d'exonération de LIC (art. 297 A), mais l'exportation est exonérée. | SS |
| Vente à un exportateur qui achète en franchise (art. 275) | Facture HT avec la mention de l'art. 275, sur attestation d'achat en franchise | SS |
| Représentations diplomatiques, organismes internationaux | Art. 151 de la directive, codes VATEX-EU-151-1A à 1E. Pour un acheteur d'un autre État membre : certificat de l'annexe II du règlement 282/2011 (art. 51). | SS |
| Livraisons en chaîne, opérations triangulaires (art. 141 de la directive), livraison avec montage hors de France (art. 258 I c) | Exclues des natures automatiques | SS |
| Transfert de biens propres vers un autre État membre (art. 256 III) | Assimilé à une LIC, sauf utilisation temporaire ; registre à tenir | SS |
| Bons d'achat et cartes cadeaux à usages multiples | La vente du bon n'est pas une livraison taxable, seule son utilisation l'est. Elle ne compte ni comme une vente ni comme une transaction d'e-reporting. | SS |

### 1.8 Côté achats (factures d'achat du logiciel)

Aucune règle ne couvre aujourd'hui le côté achat. À prévoir (SS) :
- **Acquisitions intracommunautaires** (art. 256 bis) : le magasin autoliquide la TVA (collectée et déductible) et les déclare en e-reporting B2BInt, rôle BY (art. 290 II, 2e cas), facture par facture (SUPER PDP : `POST /v1.beta/b2bint_invoices`, direction `in`).
- **Services achetés à un prestataire non établi en France** et taxables en France (art. 283-2) : même autoliquidation, même e-reporting.
- **Importations** : autoliquidation obligatoire de la TVA à l'importation depuis 2022 (art. 1695 II). Elles sont exclues de l'e-reporting.

### 1.9 Natures d'opération proposées pour le logiciel

La nature se choisit au niveau du document. Chaque ligne en déduit sa catégorie EN 16931 selon qu'elle porte sur un bien ou sur un service.

| # | Nature | Proposée automatiquement quand | Confirmation du vendeur et pièce |
|---|---|---|---|
| 1 | Vente en France | Toujours, par défaut : ticket, remise en magasin, livraison en France, en Corse ou à Monaco, professionnel étranger livré en France, vente à distance sous le seuil | Non |
| 2 | Vente en détaxe | Jamais | Case cochée et n° de BVE. Mode « remboursement après visa » seulement. |
| 3 | Export, expédié par le magasin | Adresse de livraison hors du territoire de TVA de l'UE (pays et code postal) et mode « expédié par nos soins » | Oui. MRN avec certification de sortie, ou CN23, ensuite ; sinon avoir et facture en S. |
| 4 | Export, enlevé par un acquéreur non établi en France | Jamais | Oui. Preuve douanière ; garantie de TVA selon la voie validée. |
| 5 | Livraison vers un DOM (art. 294) | Biens expédiés vers les départements 971 à 976 | Oui. MRN ou CN23, document de transport. |
| 6 | Livraison intracommunautaire | Client professionnel + pays de l'UE autre que la France (XI pour les biens) + n° de TVA d'un autre État membre valide sur VIES + livraison hors de France | Oui. Transport par le magasin, ou attestation de l'acquéreur s'il enlève les biens. |
| 7 | Vente à distance UE (particulier) | Particulier livré par le magasin dans un autre État membre. TVA française ou du pays d'arrivée calculée d'après le seuil, les établissements, l'option et l'inscription OSS (qui vaut option). | Oui, si la TVA du pays d'arrivée découle d'une option |
| 8 | Prestation à un professionnel de l'UE (autoliquidation) | Lignes de service, client professionnel de l'UE au n° valide, service non marqué « lieu spécial » | Un clic |
| 9 | Prestation à un professionnel hors UE | Lignes de service, statut prouvé (identifiant fiscal étranger), service non marqué « lieu spécial » | Oui. **Document séparé** imposé (catégorie O). |
| 10 | Prestation hors de France (lieu spécial à l'étranger, art. 259 B) | Jamais | Oui. Avertissement « immatriculation possible à l'étranger ». Pour l'art. 259 B, justificatif du domicile hors UE. |
| 11 | Prestation à un professionnel d'un DOM | Client professionnel établi en 971, 972 ou 974 (taux du DOM), ou en 973 ou 976 (hors champ, document séparé) | Non |

**Corrigé** par rapport à la proposition initiale :
- nature 4 ouverte à tout acquéreur non établi en France ;
- nature 7 qui tient compte de la présomption d'option par l'OSS ;
- document séparé imposé pour la nature 9, et pour les natures 10 et 11 quand elles produisent du O (Guyane, Mayotte) ;
- nature 2 limitée au remboursement après visa ;
- nature 5 en flux 2 pour un client professionnel de Guadeloupe, de Martinique ou de La Réunion.

### 1.10 Ce que le logiciel déduit seul

- Le circuit (1.2), à partir du type de client, de son pays, de son établissement en France, du lieu de l'opération et du territoire du magasin. Jamais à partir de la présence dans l'annuaire.
- La catégorie EN 16931 de chaque ligne (S, G, K, AE, O, E), le VATEX, la mention d'exonération (table datée), les n° de TVA à porter, et la date et le pays de livraison obligatoires en catégorie K.
- La catégorie B2C : TLB1 ou TPS1 si la TVA est française ; TNT1 si l'opération n'est pas taxable en France (export, vente à distance au-delà du seuil, art. 259 B) ; TMA1 pour le régime de la marge.
- Le taux, par le couple territoire × catégorie fiscale.
- La nature des frais accessoires, et la nature et la catégorie d'un avoir (celles de la facture d'origine).
- La séparation des documents dès qu'une ligne serait en catégorie O.
- L'échéance de facturation au 15 du mois suivant pour les catégories K et AE, et le rappel de l'état récapitulatif ou de la DES.
- Le calcul de la vente à distance : compteur de l'année précédente et de l'année en cours, OSS, option, établissements.

### 1.11 Contrôles bloquants

| Nature ou catégorie | Contrôle |
|---|---|
| LIC (K) | Client assujetti. N° de TVA d'un **autre** État membre (jamais FR), valide sur VIES à la date, consultation conservée. Pays de livraison dans l'UE et différent de la France. Adresse de livraison hors de France. Mode de transport renseigné. À défaut, la nature est refusée et « Vente en France » est proposée. Sur la facture : BT-31 et BT-48 (BR-IC-02), BT-72 ou BG-14 (BR-IC-11), BT-80 (BR-IC-12). Monaco ne peut jamais être la destination d'une LIC ni d'un export. |
| Autoliquidation (AE) | N° de TVA de l'UE valide pour le preneur. Service non marqué « lieu spécial ». Vendeur en BT-31, BT-32 ou BT-63 ; acheteur en BT-48 ou BT-47 (BR-AE-02). |
| Export (G) | Destination hors du territoire de TVA de l'UE (pays et code postal). BT-31 ou BT-63 (BR-G-02). Raison d'exonération (BT-120 ou BT-121). |
| Hors champ (O) | Aucune autre catégorie dans le même document, ni dans un avoir multi-factures (BR-O-11). Aucun BT-31, BT-63 ni BT-48 (BR-O-02). Pour la nature 9, l'identifiant prouvant le statut du client est obligatoire. |
| Franchise (E) | BT-31, BT-32 ou BT-63 (BR-E-02). VATEX ou texte de la mention (BR-E-10). |
| Détaxe | Montant TTC supérieur à 100 €. Résidence hors UE, ni à Monaco ni dans un DROM. N° de BVE saisi. Aucun tabac. Ticket original, jamais un duplicata. |
| Tous documents | Taux de la liste G1.24 dans les flux. TVA en euros (BT-6 = EUR, BT-111) si la facture est en devise. Aucune modification après émission. Tout avoir cite le numéro et la date de la facture d'origine. |
| Flux 2 | SIREN du vendeur et de l'acheteur au schéma 0002. BT-49 au schéma 0225, valeur commençant par le SIREN de l'acheteur. Notes PMT, PMD et AAB (pénalités de retard, indemnité forfaitaire, escompte). Cadre de facturation BT-23 (B1, S1, M1, B2, S2, M2, B4…). N° de facture de 35 caractères au plus. Les numéros de règles correspondants (BR-FR-05, -10, -11, -21, -01/02) sont SS : à revérifier dans l'annexe 7. |

### 1.12 Données à ajouter

- **Client**
  - type : particulier, assujetti, personne morale non assujettie ;
  - pays et subdivision : 971 à 976, 20, 2A ou 2B (Corse), MC, XI, territoires exclus repérés par le code postal ;
  - **SIREN**, absent aujourd'hui de la table `clients`, qui n'a que `siret` et `numero_tva` ;
  - adresse électronique de facturation choisie par le client (BT-49) ;
  - n° de TVA, et consultations VIES (date, résultat, n°) ;
  - identifiant fiscal étranger, et schéma d'identifiant B2BInt (0223, 0227, 0228, 0229) ;
  - établi en France : oui ou non.
- **Livraison** : mode (emporté, expédié par le magasin, enlevé par l'acquéreur ou son transporteur) ; adresse et pays ; date d'expédition ; Incoterm.
- **Preuves** : MRN et certification de sortie ; CN23 ; documents de transport (catégorie a ou b de l'art. 45 bis) ; attestation de l'acquéreur ; n° et statut du BVE.
- **Produits** : catégorie fiscale au lieu d'un taux fixe, avec un taux résolu par territoire ; indicateurs « soumis à accises », « moyen de transport neuf », « régime de la marge ».
- **Services** : marqueurs « lieu spécial » (art. 259 A), « immatériel » (art. 259 B), « télécommunication, radiodiffusion ou électronique ».
- **Magasin et entreprise** : régime de TVA avec sa date d'effet ; option pour les débits ; inscription à l'OSS (date) ; compteur des ventes à distance ; établissements dans d'autres États membres ; EORI ; adhésion à PABLO-I ou à un opérateur de détaxe ; catégorie d'entreprise du SIREN (PME, ETI ou GE) et date d'entrée en obligation.

**Constat dans le code.** `Facture::relevantDeLeInvoicing()` (`/home/user/piliqiediteur/app/Domain/Facturation/Models/Facture.php`) renvoie `$this->client->estProfessionnel()` et ignore la colonne `clients.pays`. Toute vente à un professionnel étranger part donc en facture électronique domestique au lieu du B2BInt.

---

## 2. Déclaration à zéro

### 2.1 Réponse nette

| Obligation | Réponse | Fondement | Certitude |
|---|---|---|---|
| E-reporting des transactions (B2C, B2BInt) pour une période sans aucune opération | **Inutile : aucune transmission n'est requise.** Ne rien envoyer : ni flux à blanc, ni transaction à 0 €. Aucune amende de l'art. 1788 D n'est encourue, puisque rien n'est dû. | Art. 242 nonies O de l'annexe II (décret n° 2026-677) : « En l'absence de transactions mentionnées aux I et II de l'article 290 du code général des impôts, aucune transmission n'est requise de la part de l'assujetti ». Repris par [PwC Avocats](https://www.pwcavocats.com/fr/ealertes/ealertes-france/2026/juillet/decret-et-arrete-du-27-juillet-2026-la-reforme-franchit-sa-derniere-etape-reglementaire.html), [Quadient](https://www.quadient.com/fr/blog/le-ereporting-en-4-questions) et [Compta-Online](https://www.compta-online.com/ereporting-experts-comptables-ao8954), qui cite le BOI-TVA-DECLA-20-30-50-30 du 30/09/2026 ; simplification annoncée en septembre 2025 ([KPMG](https://kpmg.com/av/fr/avocats/eclairages/2025/09/facturation-electronique-mesures-de-simplification-et-de-tolerance.html)). | TO (lu dans un extrait) |
| E-reporting des paiements pour une période sans encaissement concerné | **Inutile** : rien à transmettre. | Logique de l'art. 290 A, qui porte sur des encaissements. Aucune clause équivalente n'a été trouvée dans le corps de l'art. 242 nonies P. | SS |
| Période avec des opérations mais un total net de 0 € (ventes compensées par des avoirs) | **Obligatoire** : ce n'est pas une absence de transaction. | Art. 242 nonies O | TO |
| Ticket Z un jour sans aucune opération | **Admis, ni exigé ni interdit.** S'il est produit, il entre dans la chaîne sécurisée : numéro suivant sans trou, chaînage, signature, report du grand total et du total perpétuel, événement dans le JET, archivage. Le choix retenu doit être décrit dans la documentation de l'attestation de l'éditeur. | Aucun texte, BOFiP ou extrait NF525 ne règle le cas, et les pratiques des éditeurs divergent. | Inc |
| Clôtures journalière, mensuelle et annuelle (ou par exercice) | **Obligatoires**, « cumulatives et impératives », avec le grand total de la période et un total perpétuel jamais remis à zéro. | [BOI-TVA-DECLA-30-10-30](https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20251001), version du 01/10/2025. Une version du 25/03/2026 et le n° de paragraphe 170 sont cités sans confirmation concordante. | Doc |
| Déclaration de TVA (CA3) « néant » | **Obligatoire au réel normal**, mais hors logiciel : elle se dépose dans l'espace professionnel du magasin ou par son comptable, jamais via la caisse ni via SUPER PDP. Rien à déposer en franchise. Le code de case « 0010 » n'est confirmé par aucune source : ne pas le citer. | Art. 287 du CGI, BOI-TVA-DECLA-20-20-20 | Doc |

### 2.2 Précisions qui changent le code

- **Périmètre de « aucune transaction » (Corrigé).** L'absence s'apprécie sur **toutes** les opérations des I et II de l'art. 290, et **pour l'assujetti (le SIREN)**, pas pour un magasin. Elle couvre :
  - les ventes B2C passées en caisse ;
  - les ventes B2C facturées hors caisse ;
  - les ventes à des assujettis non établis en France (B2BInt, rôle SE) ;
  - les achats auprès de fournisseurs non établis (acquisitions intracommunautaires, services étrangers ; rôle BY).
  
  Le critère proposé par la recherche initiale, « somme de nombre_ventes et nombre_avoirs des clôtures = 0 », est **insuffisant**.
- **Contenu B2C.** Les données sont agrégées **par jour**, par catégorie (TLB1, TPS1, TNT1, TMA1) et par taux (HT et TVA) : art. 41 septies L, II, de l'annexe IV et art. 242 nonies M, I.
  - **Corrigé :** pour les ventes aux non-assujettis, le nombre de transactions quotidiennes et le numéro de facture ont été **supprimés** de la liste réglementaire (Lefebvre Dalloz). Ils ne sont pas « devenus facultatifs ».
  - Un ticket mixte donne deux entrées le même jour, l'une en TLB1, l'autre en TPS1.
  - **Corrigé :** les codes S, E ou Z d'EN 16931 ne concernent pas le flux 10.3 agrégé, seulement les factures.
- **Fréquences** (art. 242 nonies O, confirmé), appréciées pour chaque plateforme :
  - réel normal mensuel : 3 envois par mois, par décade ;
  - réel normal trimestriel : 1 envoi par mois ;
  - régimes simplifiés : 1 envoi par mois ;
  - franchise en base et remboursement forfaitaire agricole : 1 envoi par bimestre civil.
- **Échéances** (arrêté du 27/07/2026 non lu ; SS) :
  - décade du 1 au 10 : au plus tard le 20 ;
  - décade du 11 au 20 : au plus tard le 30 (« dans les dix jours suivant le 20 », formule de l'arrêté de 2022, reconduite selon les sources) ;
  - du 21 à la fin du mois : au plus tard le 10 du mois suivant ;
  - réel normal trimestriel : le 10 du mois suivant ;
  - simplifié : du 25 au 30 du mois suivant ;
  - franchise : du 25 au 30 (France Num), ou du 25 à la fin du mois suivant (Compta-Online).
  
  **Écart avec le code :** `CalculateurPeriodeEReporting` retient le dernier jour du mois pour la 2e décade. Pour les mois de 31 jours, son échéance tombe un jour après l'échéance légale. L'enjeu est limité si SUPER PDP tient seul le calendrier.
- **Paiements.** 1 envoi par mois (avant le 10 au réel normal, du 25 au 30 au simplifié), 1 par bimestre en franchise (**Corrigé** : dû aussi en franchise). Le critère porte sur les encaissements de la période, pas sur les clôtures : une période sans vente peut contenir l'encaissement d'une facture de service émise plus tôt.
- **Régime simplifié supprimé au 1/1/2027** (loi n° 2025-127, art. 38 ; [actualité impots.gouv](https://www.impots.gouv.fr/actualite/le-regime-simplifie-dimposition-la-tva-est-supprime-compter-du-1er-janvier-2027)). Les redevables basculent d'office au réel normal : trimestriel sous un seuil de chiffre d'affaires, mensuel au-delà. Un magasin PME, soumis à l'e-reporting au 1/9/2027, ne relèvera **jamais** de la cadence « simplifié », qui ne subsiste que pour le régime simplifié agricole. Le docblock de `RegimeTva` est **exact** sur ce point, alors que la recherche initiale le disait non vérifié. Une dernière CA12 reste due pour 2026.
- **Entrée en obligation.** La catégorie d'entreprise s'apprécie au niveau du **SIREN**, au 1/1/2025, sur le dernier exercice clos avant cette date (à défaut, sur le premier exercice clos ensuite) (FAQ DGFiP). Aucun envoi automatique avant la date d'obligation, sauf adhésion volontaire explicite. Émettre volontairement des factures électroniques avant l'échéance ne fait pas naître l'obligation d'e-reporting.
- **Caisse.**
  - Le BOFiP admet une tolérance pour les logiciels de facturation dotés d'une fonction caisse : ils peuvent se passer des clôtures s'ils fournissent sur demande le chiffre d'affaires d'une période. La feuille de route ne la retient pas, mais il faut la connaître.
  - L'attestation individuelle de l'éditeur est rétablie par la LF 2026 (loi n° 2026-103 du 19/02/2026, art. 125), applicable depuis le 21/02/2026 ([ACTU-2026-00073](https://bofip.impots.gouv.fr/bofip/15035-PGP.html/ACTU-2026-00073)). Modèle : BOI-LETTRE-000242.
  - Faute de certificat ou d'attestation, l'amende est de 7 500 € par logiciel (art. 1770 duodecies).
- **Amende e-reporting.** 500 € par transmission, plafonnée à 15 000 € par an (art. 1788 D, LF 2026, art. 123) : SS. Elle vise aussi les transmissions **erronées**. L'exonération de la première infraction régularisée n'est pas confirmée.

### 2.3 Mécanique SUPER PDP

- **Aucune route de « déclaration ».** Le logiciel envoie :
  - des transactions B2C (`POST /v1.beta/b2c_transactions` : date, `category_code`, montants HT et TVA, `tax_subtotals` par taux, `role_code`, `tax_due_date_type_code`) ;
  - des paiements B2C (`POST /v1.beta/b2c_payments`).
  
  SUPER PDP agrège ces données et les transmet selon le `vat_regime` de l'entreprise (`monthly`, `quarterly`, `simplified` ou `vat_exemption`, avec `has_vat_on_debits`).
- `GET /v1.beta/ereportings/preview` exige `date`, `kind` et `role_code`, et répond 204 quand il n'y a rien. C'est un contrôle technique, pas une preuve légale.
- **Factures B2C passées par `/invoices`.**
  - Une facture B2C envoyée par `POST /v1.beta/invoices` alimente seule l'e-reporting (v1.26.0) : ne pas la reprendre dans les transactions agrégées.
  - Son encaissement passe par l'événement fr:212 (`POST /v1.beta/invoice_events`, v1.30.0), pas par `b2c_payments`.
  - Depuis la v1.34.0, une facture dont l'acheteur a une adresse électronique de type e-mail n'est plus marquée B2C.
- **B2BInt.** `POST /v1.beta/b2bint_invoices` et `/b2bint_payments` (v1.32.0), direction `in` ou `out`, numéro de facture obligatoire. L'alimentation automatique par `/invoices` n'est documentée que pour le B2C.
- **Idempotence.** `b2c_transactions` n'a **ni identifiant externe ni route DELETE**. Il faut :
  - conserver l'identifiant rendu ;
  - poster une seule fois par jour ;
  - vérifier par GET (filtre `ppf_ereporting_id`) avant tout nouvel essai ;
  - corriger une erreur par une entrée négative.
- **Statuts à surveiller** : `ppf:ereporting-ack-error` et `ppf:ereporting-rejected`, en plus de `ppf:ereporting-ack` et `ppf:ereporting-response-ok`.

### 2.4 Défauts du code confirmés (lecture seule)

| Fichier | Défaut |
|---|---|
| `app/Domain/Facturation/Actions/GenererSoumissionEReportingAction.php` | Crée la soumission et déclenche `SoumissionEReportingGeneree` même sans clôture, ou avec des clôtures à zéro. Plus grave : il agrège toute une période en un seul total (`soumissions_ereporting` : total_ht, total_tva, total_ttc ; ventilation recalculée sur toute la période par `SoumissionEReporting::lignesAVentiler`), alors que la loi et SUPER PDP attendent une entrée **par jour, par catégorie et par taux**. Enfin, une clôture créée après la génération de la soumission de sa période (jour rattrapé) n'est jamais reprise. |
| `app/Domain/Facturation/Listeners/DeclencherTransmissionPdpApresSoumissionEReporting.php` | Lance l'envoi dès qu'un mandat existe, sans vérifier qu'il y a des opérations. |
| `app/Console/Commands/SoumettreEReportingPeriodique.php` | Ne filtre que sur le statut actif et un `regime_tva` non nul, sans tenir compte de la date d'entrée en obligation. Ne traite que `periodePrecedente()`, sans rattrapage. |
| `app/Console/Commands/ClotureAutomatiqueCaisse.php` (et le bouton manuel) | Ne clôturent que la veille : un jour sauté ne peut plus être clôturé, et ses ventes ne sont jamais déclarées. |
| `app/Domain/Facturation/Actions/CloturerCaisseAction.php` (ligne 59) | Numérote avec `now()->format('Y')` au lieu de l'année de `date_cloture`. Le Z du 31/12, créé à 00:00 le 1er janvier, prend un numéro de l'année suivante : la continuité et la clôture annuelle sont faussées. |
| `app/Domain/Tenancy/Enums/RegimeTva.php` | Le docblock cite à tort l'art. 286 I 3° bis et l'« annexe II art. 242 nonies B ». Les bonnes références sont les art. 290 et 290 A du CGI et les art. 242 nonies O et P de l'annexe II. La phrase sur la suppression du régime simplifié est exacte. |
| `app/Integrations/Pdp/Services/CalculateurPeriodeEReporting.php` | Échéance de la 2e décade au dernier jour du mois au lieu du 30. Ce calendrier interne double celui de SUPER PDP. |
| Tables `clotures_caisse` et `soumissions_ereporting` | Ne stockent que des totaux, sans ventilation journalière par catégorie et par taux. |

### 2.5 Recommandation

1. Garder le Z quotidien automatique, même à zéro, ainsi que les Z mensuels et annuels : chaînés, signés, archivés et tracés dans le JET. Rattraper dans l'ordre les jours manqués et numéroter selon la date de la journée.
2. Envoyer à SUPER PDP une `b2c_transaction` pour chaque jour qui a au moins une opération, ventilée par catégorie et par taux. Ignorer les jours sans vente ni avoir. Ne pas reprendre les factures B2C déjà passées par `/invoices`.
3. Déclarer aussi le B2BInt (ventes en rôle SE, achats en rôle BY) et les encaissements de services.
4. Ne rien envoyer avant la date d'obligation du SIREN. Garder une trace interne « période sans opération, rien à transmettre », tenue au niveau du SIREN.
5. Remplacer le calendrier interne par celui de SUPER PDP. C'est une proposition de conception, déduite de la spécification SUPER PDP, pas d'un texte.

---

## 3. Plusieurs magasins d'un même SIREN

Le choix de l'éditeur, une adresse de réception par établissement, est licite et compatible avec SUPER PDP, aux conditions qui suivent.

### 3.1 Annuaire

| | |
|---|---|
| Formats | Les lignes d'adressage commencent toujours par le SIREN : `SIREN`, `SIREN_SIRET`, `SIREN_SIRET_CODEROUTAGE`, `SIREN_SUFFIXE`. Sur Peppol, elles s'écrivent `0225:{valeur}` (schéma `french_directory_entry` : « 0225:{siren}* »). Le SIRET seul n'est pas une adresse valide. (SS ; formats confirmés par la spécification SUPER PDP) |
| Ligne SIREN | Chaque assujetti en a une par défaut, rattachée à une plateforme fictive « 9998 » tant qu'aucune plateforme n'est choisie (point non vérifié). Elle doit rester **active**, sinon les factures adressées au SIREN n'arrivent pas. Le logiciel ne propose à **aucun** magasin de la supprimer. |
| Modèle retenu | Une ligne `SIREN_SIRET` par magasin. Le SIRET est obligatoire avant toute connexion. La FNFE-MPE préfère les adresses au SIREN ou au suffixe, car un déménagement donne un nouveau SIRET et oblige à refaire la ligne. Prévoir alors : création de la nouvelle ligne avec sa date d'effet, suppression de l'ancienne, information des fournisseurs. |
| Caractères | Une adresse `SIREN_SIRET` ne contient que des chiffres. Pour un suffixe, les sources divergent : seulement « . » et « - », ou lettres, chiffres et « + - _ . » jusqu'à 100 caractères selon Dolibarr (règles BR-FR-24 et BR-FR-26). |
| Création | `POST /v1.beta/directory_entries` avec `{directory, identifier, effective_date}`. En production : `directory = ppf`, identifiant sans préfixe `0225:`, et SUPER PDP crée lui-même l'entrée Peppol. Dans le bac à sable : `peppol` seulement. Statut `pending`, puis `created` ou `error` (avec `status_message`). Les adresses reply-to sont techniques : on ne les supprime jamais et on ne s'en sert jamais pour la réception. `GET /directory_entries` liste les entrées de toute l'entreprise : chaque magasin retrouve **la sienne** par identifiant exact, jamais en prenant « la première ». L'effet d'un DELETE sur une ligne déjà publiée au PPF n'est pas documenté. (TO pour la spécification) |

### 3.2 Accord formel et habilitation (Corrigé)

C'est le destinataire qui choisit sa plateforme et ses lignes, et la plateforme n'agit qu'avec son accord formel (art. 242 nonies H de l'annexe II). Les délais de 2 et 15 jours ouvrés en cas de changement de plateforme et le numéro « E ter » ne sont pas vérifiés ; certitude SS, et non TO.

**Corrigé :** le consentement OAuth enregistré par le logiciel **n'est pas** cet accord formel. C'est SUPER PDP qui le recueille, dans son tunnel de vérification KYC et KYB ; pour l'inscription par un cabinet comptable, un PDF `formal_agreement` est exigé. Les champs de `mandats_pdp` (utilisateur, date, IP, navigateur, adresse demandée) prouvent seulement la **demande**, pour l'audit.

La vérification KYB (`company_verification_status = verified`) exige que l'utilisateur ait le pouvoir légal de gérer l'entreprise. Chaque magasin doit donc être connecté par le **représentant légal ou un délégataire**, pas par un simple responsable de magasin. Les statuts `needs_review` et `failed` renvoient 403 sur toutes les routes et doivent être gérés magasin par magasin.

### 3.3 OAuth SUPER PDP

- **Une entreprise par SIREN.** Une entreprise SUPER PDP correspond à une structure juridique, donc à un SIREN. Un jeton donne accès à une seule entreprise (`GET /v1.beta/companies/me`). Deux magasins du même SIREN obtiennent deux jetons sur la même entreprise : chacun voit toutes les factures, tous les statuts, toutes les entrées d'annuaire et tout l'e-reporting de l'entreprise. (TO)
- **Réglages de l'entreprise.** `PATCH /v1.beta/companies` (`vat_regime` obligatoire, `has_vat_on_debits`) réécrit le réglage de toute l'entreprise. Avant tout PATCH, comparer avec `companies/me` et bloquer en cas d'écart.
- **Paramètres d'autorisation retenus** :
  - `login_hint` = e-mail de la personne qui passera la vérification KYB ;
  - `superpdp_company_number` = SIREN ;
  - `superpdp_company_number_scheme` = `fr_siren` (`sandbox` dans le bac à sable) ;
  - `state` obligatoire, client confidentiel, et **aucun** paramètre `scope` (rejeté selon Dolibarr).
- **Ce qu'il ne faut pas envoyer (Corrigé)** :
  - pas de PKCE tant qu'un test ne l'a pas validé : il n'est pas documenté et l'exemple officiel `erp.go` ne l'utilise pas ;
  - pas de `superpdp_directory_entry_identifier` : son format est inconnu (Dolibarr se contredit entre un suffixe seul et `SIREN_suffixe`), et envoyer `SIREN_SIRET` pourrait créer une adresse `SIREN_SIREN_SIRET` ;
  - pas de `superpdp_send_and_receive` pour le 2e magasin d'un SIREN : l'effet de `receive` sur une ligne existante n'est pas documenté ;
  - `superpdp_only_future` est sans objet depuis le 1/9/2026.
- **Au retour de l'autorisation** :
  1. vérifier `oauth2_sessions/me` (statut `verified`) ;
  2. refuser, sans garder aucun jeton, si `companies/me.number` diffère du SIREN du magasin (dans le bac à sable, comparer au numéro fictif configuré) ;
  3. mémoriser l'identifiant de l'entreprise ;
  4. retrouver ou créer l'entrée `SIREN_SIRET`, puis suivre son statut.
- **Inconnu.** SUPER PDP accepte-t-il plusieurs sessions, ou plusieurs utilisateurs, sur une même entreprise ? Une révocation ne coupe-t-elle que la session concernée ? Les « sessions multiples » décrites par Dolibarr viennent des ré-authentifications d'un même client. Un test en production est nécessaire.
- **Mandats.** `company_mandates` (v1.31.0) sert à émettre ou à recevoir pour une **autre** entreprise (autre SIREN), après vérification par le support. C'est utile si des magasins sont des sociétés distinctes, sans objet entre établissements d'un même SIREN. Seule exception à « un jeton = une entreprise » : l'en-tête `X-Impersonate-Company`, réservé aux cabinets comptables.

### 3.4 Une connexion par magasin ou une connexion partagée : décision de l'utilisateur

| | Une connexion par magasin (recommandée par la recherche) | Une connexion par SIREN, partagée |
|---|---|---|
| Avantages | Conforme à `mandats_pdp`, unique par magasin, et à l'isolation. Aucun jeton partagé. Révocation par magasin, si SUPER PDP le permet. | Une seule lecture de la boîte de réception, réglages fiscaux regroupés. Précédent : Dolibarr en multisociété utilise le jeton de l'entité maître. |
| Inconvénients | N lectures de la même boîte, alors que les limites de débit ne sont pas documentées. Attribuer les factures oblige à lire les adresses des autres magasins du SIREN. Le même représentant légal doit faire chaque connexion. La possibilité de plusieurs sessions n'est pas prouvée. | Crée un niveau « entreprise » au-dessus des magasins, contraire à la règle « aucun réglage partagé ». Exige un propriétaire commun. |

Ce n'est pas une règle juridique mais un choix de conception : décision à soumettre à l'utilisateur, après un essai en production.

### 3.5 Routage des factures reçues

1. **Lecture.** `GET /v1.beta/invoices?direction=in` n'offre aucun filtre par adresse, et l'objet `invoice` ne porte que `company_id`. **Corrigé :** demander `expand[]=en_invoice&expand[]=en_invoice.buyer`, et non `en_invoice.buyer` seul.
2. **Normalisation.** Lire `en_invoice.buyer.electronic_address` (BT-49), vérifier le schéma 0225, retirer un éventuel préfixe `0225:` et écarter les adresses reply-to.
3. **Attribution** :
   - valeur égale à l'adresse `SIREN_SIRET` d'un magasin, ou commençant par `SIREN_SIRET_` : ce magasin ;
   - valeur égale au SIREN seul, ou à une adresse du SIREN qui n'appartient à aucun magasin : le magasin **désigné** du SIREN, avec l'affichage « adressée à l'entreprise » ;
   - BT-49 absent (possible pour un émetteur Peppol étranger) : même traitement, avec un signalement ;
   - BT-49 d'un autre SIREN : anomalie, sauf mandat de réception (`company_mandates`, direction `in`).
   
   Le SIRET de l'acheteur (BT-46), le lieu de livraison (BT-71) et la référence acheteur (BT-10) sont affichés comme indices, sans jamais servir à une attribution automatique.
4. **Facture non attribuée** (pas de magasin désigné, ou magasin désigné non connecté) : pas d'import, mais une file d'attente séparée qui ne bloque pas le curseur, et un nouvel essai à chaque lecture. Alerter le propriétaire **en indiquant l'échéance** : juridiquement, la facture est reçue, donc le délai de paiement et le droit à déduction courent. Les plateformes produisent fr:202 et fr:203 sans attendre le logiciel.
5. **Curseur.** Relire avec une marge (Dolibarr relit 12 h) et dédoublonner.
6. **Statuts.** N'en envoyer que pour les factures importées. **Corrigé :** l'acheteur émet fr:204, 205, 206, 207, 208, 210 et 211, et seul fr:210 est obligatoire ; fr:209 et fr:212 sont des statuts du **vendeur**. Ne pas utiliser fr:208 avec le motif ROUTAGE_ERR pour un tri interne entre magasins : fr:208 demande au fournisseur de compléter la facture.
7. **Décisions à soumettre à l'utilisateur** :
   - la lecture technique des adresses des autres magasins du SIREN (`withoutGlobalScopes` filtré par SIREN), au regard de la règle d'isolation ;
   - une unicité **globale** des factures reçues (identifiant de l'entreprise SUPER PDP + identifiant de la facture). Une contrainte sur `(magasin_id, id)` n'empêche pas deux magasins d'importer la même facture : la TVA serait déduite deux fois dans la CA3 unique et le stock compté deux fois ;
   - le transfert d'une facture non importée vers un autre magasin du SIREN, avec une trace dans le journal d'audit.

### 3.6 Émission au nom d'un magasin

- **Identifiants du vendeur** dans `en_invoice.seller` :
  - `electronic_address` (BT-34, obligatoire) = `{scheme: "0225", value: "SIREN_SIRET"}`, sans `0225:` dans la valeur ; en CII : `URIUniversalCommunication/URIID schemeID="0225"` ;
  - BT-30 = `{0002, SIREN}` ; BT-29 = `{0009, SIRET}` ; BT-31 = n° de TVA ;
  - adresse postale du magasin.
  
  Le refus d'un BT-34 qui n'est pas une entrée d'annuaire de l'entreprise ne repose que sur un commentaire du code Dolibarr (SS).
- **Format d'envoi (Corrigé).** `POST /v1.beta/invoices` n'accepte pas le JSON `en_invoice`, seulement du CII ou de l'UBL (application/xml), du Factur-X (application/pdf) ou un envoi multipart. Le JSON doit d'abord passer par `POST /v1.beta/invoices/convert`.
- **`external_id`.** 36 caractères au plus en paramètre de `POST /invoices` (64 dans le schéma). Par exemple `m{magasin_id}-f{facture_id}`, pour reconnaître ses propres factures dans `/invoices?direction=out` et `/invoice_events`, qui couvrent toute l'entreprise.
- **Statut fr:212.** Chaque magasin l'envoie pour **ses** factures de services sans option pour les débits, avec un montant négatif en cas de remboursement, y compris quand le client paie dans un autre magasin du SIREN.

### 3.7 Numérotation : défaut confirmé

`DocumentNumberingService::prefixe()` renvoie `{code}-{année}-` (FAC, TK, AV, DEV, Z, ER, FA, AF), et chaque magasin a sa propre séquence. Deux magasins d'un même SIREN émettraient donc tous deux `FAC-2026-000001`. Or l'unicité se contrôle sur le triplet SIREN du vendeur + numéro + année (rejets DOUBLON et REJ_UNI).

Plusieurs séries sont admises par le texte même de l'art. 242 nonies A, I-7° de l'annexe II et de l'art. 226, 2) de la directive (« une ou plusieurs séries »).

**Corrigé :** le segment propre au magasin ne doit **pas** être le NIC, qui change à chaque déménagement, mais un **code magasin interne immuable**, unique dans le SIREN. Le format, et la longueur maximale du BT-1 (35 caractères, à confirmer), sont une décision de l'utilisateur. Le numéro sert aussi au B2BInt (`b2bint_invoice.number`, obligatoire).

### 3.8 Ce qui est commun à tout le SIREN

- **Un seul assujetti.** Un régime de TVA, une CA3, un n° de TVA, un seuil de franchise apprécié sur le chiffre d'affaires cumulé (un dépassement fait basculer tous les magasins en même temps), un exercice (donc une clôture annuelle de caisse commune), **un seul FEC** (fichier `SirenFECAAAAMMJJ`, art. A47 A-1 du LPF, numérotation continue des écritures), un état de TVA consolidé.
- **Option pour les débits.** Sa portée sur « l'ensemble des opérations » ne repose que sur un miroir non officiel : certitude SS, et non doctrine. SUPER PDP n'a de toute façon qu'un `has_vat_on_debits` par entreprise.
- **Exception (ajout) : assujetti unique** (groupe TVA, art. 256 C). Plusieurs SIREN partagent le n° de TVA du groupe, et les opérations entre membres sont hors du champ de la TVA. Il faut l'identifiant 0231 en BT-29 et le bloc BG-11 (BR-FR-CO-15). Le contrôle « n° de TVA = FR + clé + SIREN » doit admettre ce cas.
- **Identité.** Le nom commercial et l'enseigne peuvent différer d'un magasin à l'autre. La dénomination sociale, la forme juridique et le capital sont communs.
- **Réglages actuels.** `regime_tva`, `option_debits_tva`, `numero_tva` et `raison_sociale` sont aujourd'hui stockés par magasin. Décision de l'utilisateur : les tenir au niveau du SIREN, ou contrôler leur cohérence entre magasins.
- **E-reporting.** Il est rattaché à l'entreprise (`ppf_ereporting.company_id` ; aucun champ d'établissement dans `b2c_transaction`). Chaque magasin envoie ses propres transactions sans remplacer celles des autres, et ne présente jamais comme les siens les totaux de `/ereportings`. L'agrégation « par SIREN » ou « par SIRET » reste à confirmer dans l'annexe 6 des spécifications.

### 3.9 Unicité et cohérence des identifiants

- **Magasins.**
  - `magasins.siret` est unique ; avec les `softDeletes`, la réutilisation d'un SIRET après suppression est à trancher.
  - `siren` n'est pas unique.
  - La règle « le SIRET commence par le SIREN » est déjà contrôlée dans `app/Filament/App/Pages/IdentiteMagasin.php`.
  - Il manque la clé de Luhn et la cohérence entre n° de TVA et SIREN : le n° de TVA n'est vérifié que par une expression régulière.
  - **Corrigé :** l'exception de La Poste ne vise pas le SIREN 356000000, qui respecte la clé de Luhn, mais les SIRET de ses établissements (`356000000xxxxx`), contrôlés par une somme des chiffres multiple de 5.
- **Adresse d'annuaire.** Unique dans tout le logiciel, comme un identifiant national.
- **Magasin désigné.** Au plus un par SIREN : colonne nullable unique, remplie seulement pour lui, ou colonne générée indexée (MySQL n'a pas d'index partiel).
- **Clients.** Stocker le SIREN et l'adresse BT-49, éventuellement une par site.
- **Fournisseurs.** Rattacher les factures reçues par le **SIREN** du vendeur (BT-30, toujours présent ; le BT-29 ne l'est pas toujours), alors que la table `fournisseurs` n'a qu'un SIRET, unique par magasin.

### 3.10 Autres conséquences

- **Transfert de marchandises entre magasins d'un même SIREN.** Ce n'est ni une vente ni un achat (art. 256 I) : pas de facture, pas de TVA. Refuser un client ou un fournisseur qui a le SIREN du magasin. Le transfert serait un mouvement de stock interne, valorisé au CMUP. L'isolation l'interdit aujourd'hui : décision de l'utilisateur.
- **Retour ou paiement dans un autre magasin du même SIREN.** L'avoir doit citer la facture d'origine (BT-25 et BT-26), que l'autre magasin ne voit pas ; le statut fr:212 doit être émis sur la facture d'origine. Décision de l'utilisateur.
- **Vie d'un magasin.**
  - Déménagement : nouveau SIRET, nouvelle ligne d'annuaire ; les documents déjà émis gardent leur SIRET.
  - Fermeture : suppression de l'entrée d'annuaire, réattribution des factures restantes, désignation d'un nouveau magasin destinataire si besoin.
  - Cession : nouveau SIREN, donc nouvelle entreprise SUPER PDP et nouvelle connexion OAuth.
- **B2G.** Il passe par Chorus Pro, à signaler dans le logiciel : l'API SUPER PDP ne permet pas de créer d'adresse avec code de routage.
- **Magasins d'un même SIREN dans des territoires différents** (DOM) : taux propres, une seule CA3 (certitude faible).

---

## 4. Points incertains et vérifications à faire

### 4.1 À faire confirmer par un expert-comptable (ou par la DGFiP ou la DGDDI)

1. **Détaxe** :
   - déclaration en flux 10.3 de la bascule de TLB1 vers l'exonération après le visa ;
   - enregistrement du remboursement au voyageur dans la caisse sécurisée ;
   - délai au-delà duquel un BVE non visé rend la TVA définitive (6 mois ?) ;
   - maintien du regroupement des achats dans un centre commercial sous DA 26-044 ;
   - règles applicables à un magasin situé dans un DOM.
2. **Garantie de TVA** pour un export ou une LIC dont les biens sont enlevés par l'acquéreur : facture S puis avoir et facture G, ou facture G et consignation.
3. **Catégories EN 16931** :
   - catégorie O pour une opération hors champ, face à BR-O-02, et place du n° de TVA du vendeur (BT-32) ;
   - catégorie d'une livraison vers un DOM : G, ou E avec un VATEX propre.
4. **Catégories B2C** :
   - TNT1 pour un export à un particulier ;
   - TNT1 pour une vente à distance taxée dans le pays d'arrivée, et confirmation que la vente à distance d'un magasin français sous OSS reste dans l'e-reporting ;
   - TNT1 pour la franchise en base.
5. **Taux** :
   - Corse : boissons alcooliques consommées sur place, électricité au-delà de 36 kVA, liste exacte des produits à 2,1 % ;
   - DOM : périmètre des taux de 1,75 % et 1,05 % ;
   - table des catégories fiscales par territoire.
6. **Option pour les débits** : portée exacte (art. 77 de l'annexe III, BOI-TVA-BASE-20-50-10).
7. **Circuits incertains** : client professionnel monégasque ; magasin situé en Guyane ou à Mayotte.
8. **Références** : numéros d'articles du CIBS, date de fin de la tolérance pour les anciennes références (30/06/2028 ou 31/12/2027), décret attendu sur les mentions par nature d'opération.
9. **E-reporting** : échéances exactes de l'arrêté du 27/07/2026 (2e décade, franchise) ; détails de l'amende de l'art. 1788 D.
10. **Autres régimes** : bons d'achat à usages multiples, régime de la marge, achats internationaux (autoliquidation et e-reporting en rôle BY), assujetti unique.
11. **Ticket Z à zéro** : choix à décrire dans l'attestation de l'éditeur (un Z chaque jour calendaire, ou seulement les jours d'ouverture).

### 4.2 Règles métier à trancher par l'utilisateur (protocole de rigueur, point 4)

- Format de numérotation, avec un code magasin immuable.
- Réglages fiscaux tenus au niveau du SIREN, ou contrôle de cohérence entre magasins ; suivi du seuil de franchise sur le chiffre d'affaires cumulé, qui croise les données de plusieurs magasins.
- Connexion OAuth par magasin ou partagée.
- Lecture technique des adresses des autres magasins du SIREN ; unicité globale d'une facture reçue ; transfert d'une facture entre magasins.
- Transferts de stock, retours et paiements entre magasins d'un même SIREN.
- Réutilisation d'un SIRET après la suppression douce d'un magasin.
- Détaxe : seulement le remboursement après visa (recommandé), ou aussi la détaxe immédiate.
- Ticket Z à zéro : chaque jour calendaire (comportement actuel) ou seulement les jours d'ouverture.

### 4.3 À vérifier dans le bac à sable SUPER PDP (Tricatel, Burger Queen)

1. Laisser une décade sans transaction pour Burger Queen (réel normal mensuel), puis consulter `GET /v1.beta/ereportings` : SUPER PDP génère-t-il un flux vide ?
2. `b2c_transactions` : montants négatifs (jour avec seulement des avoirs) ; deux entrées le même jour (TLB1 et TPS1) ; refus attendu d'un taux étranger (règle G1.24).
3. `b2c_payments` : `category_code` et `company_id` sont requis mais non définis dans la spécification ; provoquent-ils une erreur 400 ? Cohérence de `tax_due_date_type_code` avec `has_vat_on_debits`.
4. Idempotence : comportement lors d'un nouvel essai, et recherche par `ppf_ereporting_id`.
5. Facture B2C envoyée par `POST /invoices` : alimentation automatique de l'e-reporting (v1.26), statut fr:212 (v1.30), acheteur avec une adresse e-mail qui n'est plus marqué B2C (v1.34).
6. Validation des catégories :
   - facture O avec le n° de TVA du vendeur en BT-32 ;
   - facture E sans BT-31 (franchisé) ;
   - factures K, AE et G avec leurs règles BR ;
   - facture en devise avec BT-6 et BT-111.
7. B2BInt :
   - `POST /b2bint_invoices` en `out` (clients 0223 et 0227) et en `in` (achat intracommunautaire) ;
   - identifiant accepté pour un client de l'UE sans n° valide ;
   - une facture B2BInt envoyée par `/invoices` alimente-t-elle l'e-reporting ?
8. Statuts `ppf:ereporting-ack-error` et `ppf:ereporting-rejected` : quand ils apparaissent et ce qu'ils contiennent.
9. Annuaire : création d'entrées `peppol` supplémentaires (`0225:315143296_xxx`), suivi du statut, effet d'un DELETE ; refus éventuel d'un BT-34 absent de l'annuaire.
10. Limite réelle d'`external_id` (36 ou 64 caractères) ; limites de débit.
11. Seulement en production, car les entreprises du bac à sable sont fictives :
    - deuxième session, ou deuxième utilisateur, sur une entreprise déjà inscrite ;
    - création d'une entrée au format `SIREN_SIRET` ;
    - format attendu de `superpdp_directory_entry_identifier` ;
    - PKCE.

### 4.4 Textes à relire quand les sites gouv.fr seront accessibles

- Art. 242 nonies O, P, M, G, H et « E ter » de l'annexe II (décret n° 2026-677) et arrêté du 27/07/2026.
- Art. 41 septies L de l'annexe IV.
- Ordonnance n° 2026-671.
- BOI-TVA-DECLA-30-10-30 (version et paragraphe) ; BOI-TVA-DECLA-30-20-20-10 (version en vigueur).
- Art. 297 du CGI (Corse).
- Fiche DOM-COM et FAQ DROM.
- Circulaire DA 26-044.
- Numéros des règles BR-FR de l'annexe 7 (v3.2).
- Format EORI au SIREN ; seuil postal de 1 000 €.
- Arrêt CJUE C-639/24 ; rescrit du 14/01/2026.
- Art. 77 de l'annexe III.

Le budget de recherche web était épuisé pendant cette synthèse : ces vérifications demandent une nouvelle session.

### 4.5 Récapitulatif des corrections apportées aux recherches

| Affirmation initiale | Correction retenue |
|---|---|
| Client assujetti absent de l'annuaire : e-reporting B2BInt | Le circuit dépend de l'établissement en France. Un client absent de l'annuaire est une anomalie et reste en flux 2. |
| Livraison de la métropole vers la Guadeloupe, la Martinique ou La Réunion : e-reporting | Flux 2 pour un client professionnel établi dans ces DOM ; e-reporting seulement pour la Guyane et Mayotte. |
| Référence « art. 262 I ter 1° » | Art. 262 ter I 1° |
| Autoliquidation des services UE : « art. 259-1° et 283-2 du CGI » | « Autoliquidation », complétée par « art. 259-1° du CGI – art. 44 et 196 de la directive ». L'art. 283-2 vise le cas inverse. |
| Corse : margarines et caviar à 2,1 % ; boissons alcooliques consommées sur place à 2,1 % | Ces produits sont exclus de la liste à 2,1 %. Ventes à consommer sur place à 10 % ; alcool sur place à 10 % selon une contre-vérification, à confirmer. |
| Art. 45 bis : liste de preuves mélangée | Deux documents de la catégorie a), ou un a) et un b), émanant de deux parties indépendantes |
| Export enlevé : acquéreur « professionnel » | Tout acquéreur non établi en France |
| Vente à distance : option et inscription OSS indépendantes | L'inscription à l'OSS vaut présomption d'option. |
| Dispense de facture sous OSS non vérifiée en droit français | Transposée à l'art. 289 I 1 b du CGI |
| Franchise : données de paiement « sans objet » ; TNT1 de certitude « texte officiel » | Paiements bimestriels prévus par l'art. 242 nonies P ; TNT1 incertain |
| Nombre de transactions B2C « devenu facultatif » | Supprimé de la liste réglementaire |
| Critère « rien à transmettre » : ventes + avoirs des clôtures = 0 | Toutes les opérations de l'art. 290 I et II (dont ventes et achats B2BInt), appréciées au niveau du SIREN ; envoi par jour et non par période |
| Suppression du régime simplifié « non vérifiée » | Supprimé au 1/1/2027 (confirmé) |
| Preuve d'export absente : la vente « repasse » en vente taxée | Avoir et nouveau document |
| Consentement OAuth = accord formel | L'accord est recueilli par SUPER PDP (KYB) ; le logiciel ne trace que la demande. |
| Préremplir `superpdp_directory_entry_identifier` avec SIREN_SIRET ; utiliser PKCE | Ne pas l'envoyer (format inconnu) ; pas de PKCE sans test ; aucun `scope` |
| `expand[]=en_invoice.buyer` | `expand[]=en_invoice&expand[]=en_invoice.buyer` |
| Statuts de l'acheteur : fr:204 à fr:212 | fr:204 à 208, 210 et 211 ; fr:209 et fr:212 sont des statuts du vendeur. |
| Émission en envoyant le JSON `en_invoice` | XML CII ou UBL, Factur-X ou multipart ; le JSON passe par `/invoices/convert`. |
| Segment de numérotation fondé sur le NIC | Code magasin interne immuable |
| Exception La Poste sur le SIREN 356000000 | Elle porte sur les SIRET de ses établissements (somme des chiffres multiple de 5). |
| Données de paiement B2B « transmises par la plateforme » | Statut fr:212 envoyé par le vendeur |
| Unicité d'une facture reçue limitée au magasin | Insuffisante, car deux magasins peuvent importer la même facture ; unicité globale à trancher |