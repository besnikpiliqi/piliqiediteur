# Calcul et affichage d'une facture à un professionnel : ce qu'imposent les textes et les normes

Recherche du 02/11/2026, faite après la remarque de l'éditeur sur la facture FAC-PARIS-2026-000001 : sur la ligne « Coque Iphone 16 », 2 × 8,33 € HT affichés donnent 16,66 € à la calculatrice, alors que la facture imprime 16,67 €.

Fichiers liés :
- script : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/scripts/controle_normes.py` (lancé avec `python3 -I`) ;
- sortie complète : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/resultats_controle_normes.md` ;
- sources téléchargées et lues : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/telechargements/` (empreintes SHA-256 en fin de document).

## En bref

1. Aucun texte fiscal ni commercial français ne fixe une méthode d'arrondi, ni ne dit qu'il faut calculer depuis le HT ou depuis le TTC. La Cour de justice de l'Union laisse chaque État membre en décider. Le CGI exige, pour chaque ligne, la quantité, le prix unitaire HT et le taux, puis, pour chaque taux, le total HT et la TVA. Il n'exige pas de total HT par ligne et ne limite pas le nombre de décimales du prix unitaire. Le BOFiP précise une seule chose : la TVA s'obtient en appliquant le taux au prix HT (§ 330).
2. La norme de la facture électronique (EN 16931) et Peppol ne bloquent pas la règle actuelle de PILIQI. Il faut seulement que le prix unitaire HT parte avec ses 6 décimales (8.333333), et non arrondi au centime comme sur le PDF. Arrondi au centime, il ferait échouer la règle Peppol R120 dès 8 coques sur une même ligne.
3. Les tolérances des validateurs officiels sont larges : ± 1,00 € (et non 1 centime) entre la TVA d'un taux et « base × taux arrondi », et 0,02 € entre le total HT d'une ligne et « quantité × prix unitaire ». Les règles de somme sont en revanche exactes : la somme des lignes doit égaler la base de chaque taux, et HT + TVA doit égaler le TTC.
4. Avec la règle actuelle, il y aura toujours des écarts d'un centime. Ils sont permis par les normes, mais visibles :
   - la quantité × le prix unitaire HT affiché ne donne pas le total de la ligne ;
   - deux lignes identiques reçoivent 20,83 € et 20,84 € ;
   - à 20 %, pour 1 total TTC sur 6, la TVA d'un taux vaut 1 centime de moins que « base × 20 % arrondi ». C'est ce que demande le BOFiP au § 330 et la lettre de BR-CO-17, mais le schematron le tolère.
5. Un calcul sans aucun centime d'écart, qui garde en plus le même total TTC pour un particulier et pour un professionnel, est mathématiquement impossible pour 1 total TTC sur 6 à 20 %. Le choix revient à l'éditeur. Soit l'écart reste dans la facture (règle actuelle). Soit il passe entre le prix TTC de l'étiquette et le TTC facturé au professionnel, si la facture d'un professionnel se calcule depuis le HT : 2 × 8,33 = 16,66, base 41,66, TVA 8,33, total 49,99 € au lieu de 50,00 €. C'est la pratique que documentent Odoo et EBP (mode de facturation HT ou TTC).

---

## 1. Mentions obligatoires et arrondi : CGI, code de commerce, BOFiP, CJUE

### 1.1 CGI, annexe II, art. 242 nonies A (version en vigueur depuis le 01/01/2025, décret n° 2024-1195)

URL : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000050811276 (lue le 02/11/2026, page « En vigueur au 02/11/2026 »)

> I-8° « Pour chacun des biens livrés ou des services rendus, la quantité, la dénomination précise, le prix unitaire hors taxes et le taux de taxe sur la valeur ajoutée légalement applicable ou, le cas échéant, le bénéfice d'une exonération »

> I-9° « Tous rabais, remises, ristournes ou escomptes acquis et chiffrables lors de l'opération et directement liés à cette opération »

> I-11° « Le montant de la taxe à payer et, par taux d'imposition, le total hors taxe et la taxe correspondante mentionnés distinctement »

L'article ne contient aucune règle d'arrondi ni aucun nombre de décimales.

### 1.2 Code de commerce, art. L441-9 (en vigueur depuis le 26/04/2019)

URL : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000038414397

> « la facture mentionne le nom des parties ainsi que leur adresse et leur adresse de facturation si elle est différente, la date de la vente ou de la prestation de service, la quantité, la dénomination précise, et le prix unitaire hors TVA des produits vendus et des services rendus »

### 1.3 BOFiP, BOI-TVA-DECLA-30-20-20-10-20131018 (mentions obligatoires générales, version toujours en ligne)

URL : https://bofip.impots.gouv.fr/bofip/140-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-10-20131018

> § 150 « Il convient de distinguer les mentions qui doivent figurer sur chaque « ligne de facture » concernant un bien ou un service donné et les mentions concernant l'ensemble des opérations (généralement portées sur le « pied de la facture »). »

> § 220 « Le prix hors taxe des biens livrés ou services rendus à mentionner sur les factures est celui qui est retenu pour déterminer la base d'imposition à la TVA (BOI-TVA-BASE-10-10). Le prix unitaire hors taxe des biens livrés ou des services rendus doit être déterminé hors rabais, remises ou ristournes consentis, lesquels doivent faire l'objet d'une mention particulière. »

> § 260 Le taux « doit figurer sur chaque ligne de facture pour chacun des biens livrés ou des services rendus […] À condition qu'il n'en résulte aucune ambiguïté, la mention ainsi prescrite, au lieu d'être répétée pour chaque ligne, peut être indiquée une seule fois sur la facture pour chaque rubrique de fournitures soumises au même taux d'imposition ou à la même mesure d'exonération. »

> § 320 « Le total hors taxe correspond à la base d'imposition à la TVA des opérations taxables […]. Ce montant, ainsi que la taxe correspondante, doivent apparaître pour chaque taux d'imposition différent. »

> § 330 « Pour calculer le montant de la TVA, il convient d'appliquer les taux légaux de la taxe au prix « hors taxe » des biens livrés ou services rendus. Une fois déterminé, le montant total de la TVA afférente aux différents biens ou services faisant l'objet de la facturation doit être clairement indiqué sur la facture. »

La page ne contient pas le mot « arrondi ». Aucune autre doctrine du BOFiP sur l'arrondi de la TVA d'une facture n'a été trouvée. Le BOFiP des déclarations (arrondi à l'euro des bases et des montants de la CA3) ne concerne pas les factures.

### 1.4 CJUE, 5 mars 2009, J D Wetherspoon, C-302/07 (dispositif, cité en anglais d'après la reprise de vatupdate.com ; texte officiel sur EUR-Lex, CELEX 62007CJ0302, non joignable depuis cet environnement)

URL : https://www.vatupdate.com/2021/08/25/flashback-on-ecj-cases-c-302-07-j-d-wetherspoon-rules-on-rounding-of-vat-amounts/

> 1) La directive « contains no specific requirement concerning the method of rounding amounts of value added tax » ; « it is for Member States to decide on the rules and methods for rounding amounts of value added tax » […] elle « does not require that taxable persons be allowed to round down any amount of value added tax ».
> 2) « In a sale at a price inclusive of value added tax, in the absence of specific Community legislation, each Member State is obliged to determine, within the limits of Community law, in particular in compliance with the principles of fiscal neutrality and proportionality, the level at which the rounding of an amount of value added tax which includes a fraction of the smallest unit of national currency may or must occur. »
> 3) Les commerçants qui calculent leurs prix TVA comprise sont dans une situation différente de ceux qui vendent hors taxe et ne peuvent pas invoquer la neutralité fiscale pour revendiquer d'arrondir à l'inférieur « at line level and basket level » la TVA due.

### Ce qui en découle pour PILIQI

- Les textes n'interdisent pas de calculer une facture à un professionnel depuis le TTC. Ils ne demandent rien d'autre que des montants cohérents et la mention des éléments listés.
- Le total HT par ligne n'est pas une mention légale. Ce qui est obligatoire par ligne : quantité, désignation, PU HT, taux, rabais ou remise. Par taux : total HT et TVA. Le total HT par ligne que PILIQI imprime est un usage, imposé en revanche par la facture électronique (BT-131, voir § 2).
- Aucune limite de décimales pour le PU HT : afficher 8,333333 € est permis par les textes.
- Seul point qui tire vers le HT : le BOFiP § 330 dit que la TVA s'obtient « en appliquant les taux légaux au prix hors taxe ». Avec la règle actuelle (TVA = TTC − HT arrondi), la TVA d'un taux vaut, pour 1 total TTC sur 6 à 20 %, 1 centime de moins que « HT × 20 % arrondi » (voir § 6). Ce n'est pas une illégalité tranchée, faute de règle d'arrondi, mais c'est un écart avec la lettre de la doctrine.

---

## 2. EN 16931 : artefacts officiels v1.3.17 (09/10/2026)

Dépôt : https://github.com/ConnectingEurope/eInvoicing-EN16931 (README : « Latest release UBL & CII: v1.3.17 (2026-10-09) »). Fichiers lus : `ubl/schematron/abstract/EN16931-model.sch`, `ubl/schematron/UBL/EN16931-UBL-model.sch`, `cii/schematron/CII/EN16931-CII-model.sch`, `ubl/schematron/abstract/EN16931-syntax.sch`, `ubl/schematron/UBL/EN16931-UBL-syntax.sch`. Le paquet français FNFE v1.3.1 embarque, lui, les artefacts EN 16931 v1.3.15, dont les règles citées ici sont identiques.

### 2.1 Montant net de ligne BT-131 = BT-129 × BT-146 / BT-149 + charges − remises

Ce n'est **pas** une règle de validation de l'EN 16931. Aucune assertion des artefacts officiels ne compare BT-131 à la quantité × le prix. Les seules règles qui portent sur le prix sont :

> « [BR-26]-Each Invoice line (BG-25) shall contain the Item net price (BT-146). »
> « [BR-27]-The Item net price (BT-146) shall NOT be negative. »

La formule n'apparaît que comme formule de calcul du modèle sémantique. Elle n'est contrôlée que par Peppol (R120, § 3).

### 2.2 Règles de somme : exactes, avec arrondi à 2 décimales

> « [BR-CO-10]-Sum of Invoice line net amount (BT-106) = Σ Invoice line net amount (BT-131). »
> UBL : `xs:decimal(cbc:LineExtensionAmount) = xs:decimal(round(sum(//(cac:InvoiceLine|cac:CreditNoteLine)/xs:decimal(cbc:LineExtensionAmount)) * 10 * 10) div 100)`

> « [BR-CO-13]-Invoice total amount without VAT (BT-109) = Σ Invoice line net amount (BT-131) - Sum of allowances on document level (BT-107) + Sum of charges on document level (BT-108). » (égalité exacte après arrondi)

> « [BR-CO-14]-Invoice total VAT amount (BT-110) = Σ VAT category tax amount (BT-117). » (exacte)

> « [BR-CO-15]-Invoice total amount with VAT (BT-112) = Invoice total amount without VAT (BT-109) + Invoice total VAT amount (BT-110). » (exacte)

> « [BR-S-08]-For each different value of VAT category rate (BT-119) where the VAT category code (BT-118) is "Standard rated", the VAT category taxable amount (BT-116) in a VAT breakdown (BG-23) shall equal the sum of Invoice line net amounts (BT-131) plus the sum of document level charge amounts (BT-99) minus the sum of document level allowance amounts […] »
> - **CII (donc Factur-X)** : égalité exacte, `xs:decimal(../ram:BasisAmount) = (round(sum(...LineTotalAmount)) * 10 * 10) div 100 + ...` ;
> - **UBL** : tolérance ± 1, `(../xs:decimal(cbc:TaxableAmount - 1) < (sum(...)) and (../xs:decimal(cbc:TaxableAmount + 1) > (sum(...))`.

### 2.3 TVA par taux : BR-CO-17 et BR-S-09, tolérance de ± 1 unité monétaire (1,00 €)

> « [BR-CO-17]-VAT category tax amount (BT-117) = VAT category taxable amount (BT-116) x (VAT category rate (BT-119) / 100), rounded to two decimals. »

Test UBL, avec bornes strictes :
```
(abs(xs:decimal(cbc:TaxAmount)) - 1 < round(abs(xs:decimal(cbc:TaxableAmount)) * (…/xs:decimal(cbc:Percent) div 100) * 10 * 10) div 100 )
and (abs(xs:decimal(cbc:TaxAmount)) + 1 > round(abs(xs:decimal(cbc:TaxableAmount)) * (…/xs:decimal(cbc:Percent) div 100) * 10 * 10) div 100 )
```
Test CII (repris dans le schematron Factur-X 1.08 EN16931 et dans l'EXTENDED-CTC-FR) : mêmes bornes, mais incluses (`<=`, `>=`).

> « [BR-S-09]-The VAT category tax amount (BT-117) in a VAT breakdown (BG-23) where VAT category code (BT-118) is "Standard rated" shall equal the VAT category taxable amount (BT-116) multiplied by the VAT category rate (BT-119). » (même tolérance ± 1)

Les montants sont exprimés en unités de la devise (8.33). La tolérance vaut donc **1,00 €**, et non 1 centime. Le texte de la règle dit « rounded to two decimals », mais le test officiel accepte tout écart inférieur à 1 €.

### 2.4 Décimales

> « [UBL-DT-01]-Amounts shall be decimal up to two fraction digits » : elle s'applique à tout élément `*Amount`, **sauf** `*PriceAmount` (`//*[ends-with(name(), 'Amount') and not(ends-with(name(),'PriceAmount'))…]`).
> « [BR-DEC-23]-The allowed maximum number of decimals for the Invoice line net amount (BT-131) is 2. »
> « [BR-DEC-19] / [BR-DEC-20] » : 2 décimales pour BT-116 et BT-117.

Le prix unitaire (BT-146) n'a donc aucune limite de décimales dans l'EN 16931 v1.3.17. Peppol l'écrit aussi : « Unit price amount does not set restrictions on number of decimals, as contrast to the Amount type » (§ 7.2.2, https://docs.peppol.eu/poacc/billing/3.0/bis/). La limite française est de 6 décimales (§ 3.3).

### Ce qui en découle pour PILIQI

- La répartition du HT par cumul, sur laquelle porte la remarque des « 20,83 / 20,84 », est justement ce qui satisfait BR-S-08 en CII et Factur-X, où l'égalité est exacte. Arrondir chaque ligne isolément donnerait 20,83 + 20,83 = 41,66, contre une base de 41,67 calculée sur le total du taux : refus en CII.
- BR-CO-17 et BR-S-09 ne peuvent pas échouer avec la règle actuelle. L'écart y vaut au plus 0,01 €, pour une tolérance de 1,00 €.
- BT-146 peut porter le PU HT enregistré avec 6 décimales.

---

## 3. Peppol BIS Billing 3.0, CIUS français, Factur-X, spécifications de la DGFiP, SUPER PDP

### 3.1 Peppol BIS Billing 3.0.21 (mai 2026)

Fichier lu : https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/master/rules/sch/PEPPOL-EN16931-UBL.sch (« Last update: 2026 May release 3.0.21 »). Documentation : https://docs.peppol.eu/poacc/billing/3.0/bis/

> « [PEPPOL-EN16931-R120]-Invoice line net amount MUST equal (Invoiced quantity * (Item net price/item price base quantity) + Sum of invoice line charge amount - sum of invoice line allowance amount » — test : `u:slack($lineExtensionAmount, ($quantity * ($priceAmount div $baseQuantity)) + $chargesTotal - $allowancesTotal, 0.02)`, avec `u:slack` = `xs:decimal($exp + $slack) >= $val and xs:decimal($exp - $slack) <= $val`. La tolérance vaut donc **0,02 €, bornes incluses**. Les remises de ligne sont additionnées puis arrondies à 2 décimales avant la comparaison.

> « [PEPPOL-EN16931-R121]-Base quantity MUST be a positive number above zero. »

> « [PEPPOL-EN16931-R046]-Item net price MUST equal (Gross price - Allowance amount) when gross price is provided. » Égalité **exacte** : `xs:decimal(../cbc:PriceAmount) = xs:decimal(cbc:BaseAmount) - xs:decimal(cbc:Amount)`.

> § 9 Rounding : « All document level amounts shall be rounded to two decimals for accounting » ; « Invoice line net amount shall be rounded to two decimals » ; « Results from calculations involving already rounded amounts are not subject to rounding, like payable amounts and total amounts included VAT. »

> § 10.2.2 : « As the line net amount must be rounded to two decimals, please note that the different parts of the calculation must be rounded separately. I.e the result of "Item line net amount" = (("Item net price (BT-146)" div "Item price base quantity (BT-149)") times ("Invoiced Quantity (BT-129)") must be rounded to two decimals, and the allowance/charge amounts are also rounded separately. »

> § 10.4 : « "VAT category tax amount (BT-117)" = "VAT category taxable amount (BT-116)" times ("VAT rate (BT-119)" div 100) »

Est-ce que ces règles s'appliquent aux factures françaises ? Le schematron 3.0.21 range les profils `urn:peppol:france:billing:regulated` et `urn:peppol:france:billing:non-regulated` dans le profil « 01 », celui des règles de la BIS Billing. R120 a pour contexte `cac:InvoiceLine | cac:CreditNoteLine`, sans condition de pays. Aucune règle propre à la France n'y figure (pays présents : DE, DK, GR, IS, IT, NL, NO, SE).

### 3.2 SUPER PDP (OpenAPI 1.38.0.beta, https://api.superpdp.tech/openapi/superpdp.json)

> `POST /v1.beta/validation_reports` : « Send one or more invoices as multipart and get a validation report for each invoice. The invoice format will be automatically detected and the appropriate validation rules will be used. » Le schéma `subreport.validator` donne en exemple `"PEPPOL-EN16931-UBL.xsl"`.

> `api:validated` : « Invoice passed semantic checks (Schematron). »

> Annuaire : « In production, for french identifiers, it is only possible to create directory entries in the `ppf` directory — we handle the creation of the corresponding entries in the Peppol directory. »

> Formats acceptés : « an XML file (format CII or UBL), a PDF (format Factur-X) » ; formats de conversion `cii`, `ubl`, `en16931`, `factur-x` (`ubl-peppol-bis` pour les factures de test).

Conclusion : une facture UBL déposée chez SUPER PDP sera très probablement contrôlée par le schematron Peppol, donc par R120. Pour CII et Factur-X, ce sont les schematrons EN 16931 CII et FNFE (§ 3.3) qui s'appliquent : sans R120, mais avec BR-S-08 exact.

### 3.3 CIUS français, Factur-X et EXTENDED-CTC-FR : paquet FNFE-MPE v1.3.1 du 30/04/2026

URL : https://fnfe-mpe.org/wp-content/uploads/2026/05/2026_04_30_FNFE_SCHEMATRONS_FR_CTC_V1.3.1.zip (page https://fnfe-mpe.org/ressources/). Fichiers lus : `20260430_BR-FR-Flux2-Schematron-UBL_V1.3.1.sch`, `...-CII_V1.3.1.sch`, `20260430_EXTENDED-CTC-FR-UBL-V1.3.1.sch`, `...-CII-V1.3.1.sch`, `Factur-X_1.08_EN16931.sch`.

> « BR-FR-DEC-03 — Format des montants positifs (max 19 caractères, 6 décimales, séparateur « . ») » pour BT-146 : « [BR-FR-DEC-03/BT-146] : Le montant « … » est invalide. Il doit : - être un nombre strictement positif (sans signe « - »), - comporter au plus 6 décimales (séparateur « . »), - contenir au maximum 19 caractères (hors séparateur). » (`flag="warning"`)

> « BR-FR-DEC-01 — Format des montants numériques (max 19 caractères, 2 décimales, séparateur « . ») » pour BT-131, entre autres : « Il doit comporter au plus 2 décimales ».

> « BR-FR-DEC-02 — Format des quantités numériques (max 19 caractères, 4 décimales, séparateur « . ») » pour BT-129.

> « BR-FR-DEC-04 — Format des taux de TVA (max 4 caractères, 2 décimales, séparateur « . ») ».

Les règles BR-FR (01 à 31, CO-03 à CO-15, DEC-01 à 04, MV-01 à 13) ne contiennent **aucune règle de cohérence entre quantité, prix et montant de ligne**. Le profil EXTENDED-CTC-FR admet, lui, un écart de 0,01 € par ligne sur les totaux :
> « [BR-FREXT-CO-10] - Écart absolu entre le montant total des lignes (BT-106) et la somme des montants nets ligne (BT-131) ≤ 0,01 € * nombre de lignes, pour lesquels le sous-type est absent ou égal à DETAIL »

### 3.4 Spécifications externes de la DGFiP v3.2 : annexe 7, règles de gestion v1.9 (30/04/2026)

URL : https://www.impots.gouv.fr/specifications-externes-b2b (archive `specifications-externes-v3.2.zip`, fichier `20260430_Annexe 7 - Règles de gestion - V1.9.xlsx`).

> G1.53 « Cohérence montants de TVA » : « Le montant total hors TVA des factures (TT-51) ou des transactions (TT-82) doit être égal à la somme des montants de base d'imposition du type de TVA (TT-54 / TT-87). Une tolérance de 1 centime sera possible pour éviter les problèmes d'arrondi. Le montant total de TVA des factures (TT-52) ou des transactions (TT-83) doit être égal à la somme des totaux de TVA par taux (TT-55 / TT-88)*. Une tolérance de 1 centime sera possible pour éviter les problèmes d'arrondi. »

> G1.55 « Cohérence du prix de l'article » : « Si le prix brut de l'article (BT-148 ou TT-71) et le rabais sur le prix de l'article (BT-147 ou TT-70) sont renseignés alors le prix net de l'article (BT-146 pour le flux 1, TT-69 pour le flux 10) doit être égal au prix brut de l'article (BT-148 ou TT-71) - le rabais sur le prix de l'article (BT-147 ou TT-70) Une tolérance de 1 centime sera possible pour éviter les problèmes d'arrondi »

L'annexe reprend BR-CO-17 sans le modifier : « Montant de la TVA pour chaque type de TVA (BT-117) = Base d'imposition du type de TVA (BT-116) x (Taux de type de TVA (BT-119) / 100), arrondi à deux décimales. » Le dossier général v3.2 (PDF) ne contient pas le mot « arrondi ».

### Ce qui en découle pour PILIQI

- Aucun outil de facture électronique n'est encore écrit dans le dépôt (aucune occurrence de `PriceAmount`, `LineExtensionAmount` ni `BT-146` dans `app/`). Le choix de la correspondance des données est donc encore libre.
- R120 passe si BT-146 porte le PU HT enregistré avec 6 décimales. Il échoue si BT-146 reprend le PU HT affiché, arrondi au centime, dès 8 coques à 10,00 € TTC (§ 6, H).
- Une remise de ligne doit partir en BT-136 (remise de ligne) et non en prix brut et rabais (BT-148 / BT-147). R046 et G1.55 imposent en effet « prix net = prix brut − rabais » au centime près, ou exactement.

---

## 4. Tickets de caisse et factures aux particuliers

### 4.1 Prix affichés au consommateur : arrêté du 3 décembre 1987, art. 1er (en vigueur depuis le 01/01/2002)

URL : https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000006883501

> « Toute information sur les prix de produits ou de services doit faire apparaître, quel que soit le support utilisé, » la somme totale toutes taxes comprises qui devra être effectivement payée par le consommateur, exprimée en euros (cité en partie, l'outil de lecture coupant la citation).

Le prix TTC affiché engage donc le commerçant envers un **consommateur**. L'arrêté ne vise pas un client professionnel.

### 4.2 Caisse sécurisée : CGI art. 286 I 3° bis, BOFiP BOI-TVA-DECLA-30-10-30-20260325

URL : https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20260325

> § 50 « Sont ainsi visées les données de détail d'une transaction, qui doivent être enregistrées ligne par ligne, qui comprennent : le numéro du justificatif ; la date (année-mois-jour-heure-minute) ; le numéro de la caisse ; le montant total toutes taxes comprises ; le détail des articles ou prestations (libellé, quantité, prix unitaire, total hors taxe de la ligne, taux de TVA associé) ; toutes les données liées à la réception (immédiate ou attendue) du paiement en contrepartie (mode de règlement notamment) ; les traces de modifications et corrections apportées aux transactions enregistrées. »

La page ne contient pas le mot « arrondi ». Elle impose d'**enregistrer** le total HT de chaque ligne dans les données de caisse, ce que fait la répartition par cumul de PILIQI. Elle n'impose pas de l'**imprimer** sur le ticket.

### 4.3 Ce qui en découle pour PILIQI

- Aucun texte trouvé n'oblige à afficher le HT par ligne sur un ticket de caisse. Un ticket n'est pas une facture au sens de l'art. 242 nonies A.
- Le calcul depuis le TTC est le bon calcul pour un consommateur : le prix payé doit être le prix TTC affiché.
- Les déclarations des ventes aux particuliers (e-reporting, flux 10) n'admettent qu'1 centime d'écart entre totaux et ventilation par taux (G1.53). La règle actuelle est déjà exacte sur ce point (sommes de montants enregistrés).

---

## 5. Pratique des logiciels de facturation et de caisse

- **Odoo**, documentation « B2B (tax excluded) and B2C (tax included) pricing » (https://www.odoo.com/documentation/18.0/applications/finance/accounting/taxes/B2B_B2C.html). L'éditeur conseille de « always store the default price tax excluded on the product form » et de laisser le logiciel calculer l'autre prix. Il prévient que « there is not a symmetrical relationship with prices included and prices excluded » : un produit à 10 € TTC (21 %) stocké à 8,26 € HT donne 8,26 + 1,74 = 10,00 € pour un particulier, mais 8,26 + 1,73 = 9,99 € pour un professionnel. Par défaut, Odoo arrondit la TVA par ligne (`tax_calculation_rounding_method` : `round_per_line`, code source 18.0 `addons/account/models/company.py`). Dans ses fichiers UBL et CII, il écrit BT-146 = total HT de la ligne ÷ quantité, avec la précision des prix (`addons/account_edi_ubl_cii/models/account_edi_xml_ubl_20.py`, `'price_amount': round(gross_price_unit, 10)`), pour rester sous la tolérance de R120.
- **EBP Gestion commerciale Hubbix 2024** (fiche officielle, https://www.ebp.com/wp-content/uploads/2023/11/ebp-logiciel-gestion-commerciale-hubbix-2024-bd.pdf) : « Mode de facturation (HT ou TTC) ».
- **Sage 50** : la réponse d'un membre sur le forum officiel (https://communityhub.sage.com/fr/sage-50/f/discussion-generale/245500/erreurs-sur-les-factures-de-vente) cite une option « facturer en TTC » en pied de pièce, qui change le calcul de la TVA. Ce n'est pas une documentation officielle.
- Les sites pratiques (invoicing.plus, calculatrice.com, abby.fr) décrivent deux méthodes, « somme des arrondis » par ligne et « arrondi de la somme » par taux, et arrondissent l'un des montants (HT ou TVA) avant de déduire l'autre du TTC. Ils ne sont pas officiels. L'affirmation d'invoicing.plus selon laquelle la facture électronique imposerait l'arrondi de la somme n'est confirmée par aucun texte ni par aucun schematron lu.

Pratique observée : le mode de calcul se choisit **par document**, HT ou TTC. Une boutique qui affiche des prix TTC facture en général ses clients professionnels en mode HT, à partir d'un prix HT enregistré, et accepte qu'ils paient quelques centimes de plus ou de moins que l'étiquette. Aucun exemple documenté n'a été trouvé d'un logiciel qui facture un professionnel en mode TTC tout en imprimant un PU HT à 2 décimales qui retombe toujours juste.

---

## 6. Exemples chiffrés (script `controle_normes.py`, arithmétique exacte, tolérances des schematrons)

Règle simulée, celle de CLAUDE.md :
- TTC de ligne = quantité × PU TTC − remise ;
- HT du taux = TTC du taux ÷ (1 + taux), arrondi une fois, le demi-centime vers le haut, puis réparti par cumul ;
- TVA = TTC − HT ;
- PU HT enregistré avec 6 décimales.

| Cas | Constat sur le PDF | R120 (0,02) | BR-CO-17 officiel (± 1 €) | BR-CO-17 à la lettre / BOFiP § 330 | BR-S-08 CII (exact) |
|---|---|---|---|---|---|
| A. Facture de l'éditeur : 2 × coque 10,00 TTC + écran 30,00 TTC | 2 × 8,33 = 16,66 affiché ≠ 16,67 | OK (écart 0,003334 avec BT-146 = 8.333333 ; 0,01 avec 8.33) | OK | OK (41,67 × 20 % = 8,334 → 8,33) | OK |
| B. Deux lignes identiques de 25,00 TTC | 20,83 et 20,84 ; 1 × 20,83 affiché ≠ 20,84 | OK (écart 0,006667) | OK | OK | OK (c'est le cumul qui le permet) |
| C. 10 coques, BT-146 arrondi à 8.33 | 10 × 8,33 = 83,30 ≠ 83,33 | **ÉCHEC** (écart 0,03) | OK | OK | OK |
| C bis. 10 coques, BT-146 = 8.333333 | idem sur le PDF | OK (écart 0,00333) | OK | OK | OK |
| D. 1 article à 0,03 TTC | HT 0,03, TVA 0,00 à 20 % | OK (0,005) | OK | **ÉCHEC** (0,03 × 20 % = 0,006 → 0,01) | OK |
| E. 1 article à 10,05 TTC | HT 8,38, TVA 1,67 | OK (0,005) | OK | **ÉCHEC** (8,38 × 20 % = 1,676 → 1,68) | OK |
| F. 3 × 19,99 TTC − 5,00 de remise, puis 1 article à 0,03 | 2e ligne : HT 0,02 et TVA 0,01 à 20 %, alors que 1 × 0,03 est affiché | OK (BT-136 = 4.16 ; écarts 0,005) | OK | OK | OK |

**Seuil d'échec de R120 si BT-146 reprend le PU HT arrondi au centime** (cas H) :

| PU TTC | Taux | PU HT arrondi | Échec dès |
|---|---|---|---|
| 10,00 € | 20 % | 8,33 € | 8 articles (66,67 contre 66,64) |
| 9,99 € | 20 % | 8,33 € | 6 articles (49,95 contre 49,98) |
| 2,50 € | 20 % | 2,08 € | 8 articles |
| 19,99 € | 20 % | 16,66 € | 16 articles |
| 0,01 € | 20 % | 0,01 € | 16 articles |
| 10,00 € | 5,5 % | 9,48 € | 19 articles |

Avec BT-146 à 6 décimales, le pire écart R120 trouvé, cumul compris (cas I, lignes de 1 à 10 000 articles, PU TTC de 0,01 à 99,99 €, précédées d'une ligne qui maximise l'erreur du cumul), reste sous la tolérance de 0,02 € :

| Taux | Pire écart R120 |
|---|---|
| 20 % | 0,010000 € |
| 10 % | 0,010000 € |
| 5,5 % | 0,010900 € |
| 8,5 % | 0,011935 € |

**Fréquence de l'écart « TVA PILIQI ≠ base × taux arrondi »** (totaux TTC d'un taux de 0,01 € à 1 000,00 €, un par centime ; écart toujours d'1 centime exactement) :

| Taux | Totaux concernés | Premier exemple | Totaux TTC qu'aucun calcul depuis le HT ne peut atteindre |
|---|---|---|---|
| 20 % | 16,67 % | 0,03 → HT 0,03, TVA 0,00 (attendu 0,01) | 16,67 % |
| 13 % (Corse) | 11,50 % | 0,04 → HT 0,04, TVA 0,00 | 11,50 % |
| 10 % | 9,09 % | 0,05 → HT 0,05, TVA 0,00 | 9,09 % |
| 8,5 % (DOM) | 7,83 % | 0,06 → HT 0,06, TVA 0,00 | 7,83 % |
| 5,5 % | 5,21 % | 0,10 → HT 0,09, TVA 0,01 (attendu 0,00) | 5,21 % |
| 2,1 % | 2,06 % | 0,24 | 2,06 % |
| 1,75 % (DOM) | 1,72 % | 0,29 | 1,72 % |
| 1,05 % (DOM) | 1,04 % | 0,48 | 1,04 % |
| 0,9 % (Corse) | 0,89 % | 0,56 | 0,89 % |

Pourquoi les deux colonnes coïncident, à 20 %. Notons le TTC en centimes 6m + r :
- depuis un HT entier H, la TVA arrondie de H × 20 % donne les TTC 6m, 6m+1, 6m+2, 6m+4 et 6m+5 ;
- le TTC 6m + 3 n'est jamais atteint : son HT exact vaut 5m + 2,5 centimes ;
- si le HT est arrondi vers le haut (règle actuelle), la TVA vaut m, alors que 20 % du HT arrondi donne m + 1 ;
- si le HT est arrondi vers le bas, la TVA vaut m + 1, alors que 20 % du HT arrondi donne m.

Pour ces totaux, **aucun** couple (HT, TVA) à 2 décimales ne satisfait à la fois « HT + TVA = le TTC de l'étiquette » et « TVA = HT × 20 % arrondi ».

**Même panier calculé depuis le HT** (cas G : PU HT de la coque arrondi une fois à 8,33, ligne = quantité × PU HT) :
- 2 × 8,33 = 16,66 ; 1 × 25,00 = 25,00 ;
- base 41,66 ; TVA 8,33 (8,332) ; TTC 49,99 € ;
- tous les contrôles passent, avec un écart nul pour R120 et pour BR-CO-17 à la lettre ;
- le professionnel paie 49,99 € pour un panier qu'un particulier paie 50,00 € (50,02 € si le PU HT est arrondi à 8,34).

---

## 7. Ce que la règle actuelle risque, et les choix à soumettre à l'éditeur

### 7.1 Risques de la règle actuelle (calcul depuis le TTC pour tous)

1. **Rejet par la plateforme**, seulement si la correspondance est mal faite. BT-146 doit porter le PU HT enregistré avec 6 décimales (BR-FR-DEC-03 en autorise 6). Repris arrondi au centime comme sur le PDF, il ferait échouer R120 dès 6 à 19 articles par ligne selon le prix. Une remise de ligne doit partir en BT-136, calculée comme `arrondi(quantité × BT-146 − BT-131)`. Ainsi faite, aucune règle trouvée n'échoue.
2. **TVA d'1 centime sous « base × taux »** pour 16,67 % des totaux à 20 %. Le schematron officiel le tolère (± 1 €). C'est pourtant contraire à la lettre de BR-CO-17 (« rounded to two decimals ») et au BOFiP § 330. Le logiciel du client qui recalcule la TVA depuis la base trouvera 1 centime d'écart. Un validateur plus strict que les artefacts officiels refuserait la facture. Aucun n'est connu, mais rien ne l'interdit.
3. **Incohérences visibles sur le PDF**, qui ne disparaîtront pas avec la règle actuelle :
   - la quantité × le PU HT affiché ne donne pas le total HT de la ligne (A, C) ;
   - deux lignes identiques reçoivent des HT différents (B) ;
   - une petite ligne peut porter 0,02 € HT et 0,01 € de TVA à 20 % (F).
   La note de calcul les explique, mais ne les supprime pas.
4. **Révision EN 16931-1:2026**, approuvée par le CEN le 13/03/2026. D'après eConnect (source tierce), BT-146 y serait limité à « ISO 4217 minor unit + 2 extra », soit 4 décimales pour l'euro, et BR-CO-17 serait retiré. Avec 4 décimales, l'erreur du prix (au plus 0,00005 € par article) s'ajoute à celle du cumul (moins de 0,01 €). La tolérance de R120 ne serait donc garantie, dans le pire cas, que jusqu'à 200 articles par ligne sans remise. À vérifier sur le texte officiel avant d'écrire la correspondance (§ 8).

### 7.2 Choix possibles (règle métier à trancher par l'éditeur, protocole de rigueur point 4)

L'éditeur veut zéro centime d'écart. Les cinq égalités suivantes ne peuvent pas tenir toutes ensemble pour tous les paniers :
- (1) TTC payé = somme des prix TTC de l'étiquette ;
- (2) total HT de ligne = quantité × PU HT affiché à 2 décimales ;
- (3) base = somme des lignes ;
- (4) TVA = base × taux arrondi ;
- (5) TTC = base + TVA.

(3) et (5) sont imposées par l'EN 16931 (exactes). (4) est la lettre de BR-CO-17 et du BOFiP. (1) n'est imposée par la loi qu'envers un consommateur. (2) est ce que l'éditeur vérifie à la calculatrice.

- **Choix A, garder le TTC pour tous** (règle actuelle). On peut imprimer le PU HT avec plus de décimales (jusqu'à 6, permis par les textes et le CIUS) : 2 × 8,333333 = 16,666666, ce qui rend 16,67 lisible. Cela ne supprime ni le cas B (20,833333 s'arrondit à 20,83, pas à 20,84), ni le centime de TVA du § 7.1 point 2. Il resterait donc des écarts, que l'éditeur refuse.
- **Choix B, calculer depuis le HT les factures et avoirs à un professionnel**, en gardant le TTC pour les tickets et les ventes aux particuliers :
  - PU HT à 2 décimales, tiré une fois du prix TTC de la fiche (arrondi au plus proche, 8,33), ou enregistré à part ;
  - HT de ligne = quantité × PU HT − remise HT ;
  - base = somme des lignes, TVA = base × taux arrondie une fois, TTC = base + TVA.
  Plus aucun écart dans la facture : (2), (3), (4) et (5) sont exactes, R120 et BR-CO-17 donnent un écart nul. En contrepartie, (1) tombe pour le professionnel : 49,99 € au lieu de 50,00 € dans l'exemple. C'est la pratique documentée d'Odoo et d'EBP. Il faudrait revoir les décisions de CLAUDE.md qui en dépendent :
  - « un même panier a les mêmes montants pour tout client » ;
  - le calcul des retours sur une facture ;
  - les clôtures, qui gardent les factures aux professionnels à part et ne font qu'additionner ;
  - les achats, déjà calculés depuis le HT et inchangés.
- **Choix C, TTC pour tous, HT arrondi ligne par ligne et TVA du taux = TTC − somme des HT.** Le cas B disparaît, mais l'écart avec « base × taux » grandit d'1/2 centime par ligne. C'est pire pour le BOFiP § 330, donc à écarter.

---

## 8. Ce qui reste incertain

- **Texte officiel de l'EN 16931-1:2026** (décimales de BT-146, sort de BR-CO-17, règles d'arrondi par ligne) : seule une source tierce (eConnect) a été lue. Les artefacts de validation v1.3.17 du 09/10/2026 gardent BR-CO-17 avec sa tolérance de ± 1. Peppol annonce la BIS Billing 4.0 pour 2027 (même source tierce).
- **Validateurs exacts de SUPER PDP** : la documentation ne les liste pas. Seul l'exemple `PEPPOL-EN16931-UBL.xsl` est donné. À vérifier dans le bac à sable par `/v1.beta/validation_reports`, avec un UBL et un CII des cas A à F.
- **Factur-X** : le PDF affiche 8,33 € et le XML 8.333333. Aucune règle trouvée n'interdit que le PDF arrondisse l'affichage d'une donnée du XML. Aucune ne l'autorise expressément non plus.
- **Arrêt Wetherspoon** cité en anglais d'après une reprise : EUR-Lex et curia n'étaient pas lisibles depuis cet environnement.
- **Doctrine française sur l'arrondi de la TVA d'une facture** : aucune trouvée, en dehors du § 330. Une réponse ministérielle ou un rescrit peuvent exister sans avoir été trouvés.
- **Facture à un particulier** (émise à sa demande) : on suppose qu'elle porte les mentions de l'art. 242 nonies A, PU HT compris, comme toute facture. Aucun passage du BOFiP ne l'a confirmé ou infirmé dans cette recherche.
- **Pratique « mode HT pour les professionnels »** : elle est documentée par Odoo (exemple chiffré) et EBP (option par document). Elle n'a pas été trouvée dans une documentation officielle de logiciel de caisse français. Le reste de la pratique décrite vient de forums ou de sites non officiels.

---

## Empreintes des sources téléchargées (SHA-256)

```
949095661fada023665f5d8589d85cc0543f8c50d47c0b214616622b05b3eb72  en16931/ubl-model.sch (EN16931 v1.3.17, abstract model)
895b0e412bf16d13ca22530844937ca9d93511ca587c2d12dbef4a6b2f37b0ff  en16931/ubl_schematron_UBL_EN16931-UBL-model.sch
864f96dff4819b39e0bdbda76c5396c78aee26d0f12676bb0b689df3230965ed  en16931/cii_schematron_CII_EN16931-CII-model.sch
e1bb698a2cae0ea492d15256f404841abd449c2d55df77f59b7b9050eed123b9  en16931/ubl-syntax-abstract.sch
9616cfffb418f349b532c1e25d3c831b8af6a47f4f5607ca0df4b1336f48e5f3  peppol/PEPPOL-EN16931-UBL-master.sch (BIS 3.0.21)
6cf338c995a4c3ec14681604f53919bc6d6a5bc22bea66a3b608e97daba095fe  fnfe/fnfe-1.3.1.zip
cd8f6e817e37f329e6f62a35aa131b78a51379bec953445b774fa8adbaaa3862  dgfip/v32.zip
4d1c5bba7cdb7642c31570d5fbf4deb9e4f98b957043f9566c622cf495275ba3  superpdp/superpdp.json (1.38.0.beta)
b97a067c553ba9d6e8d401ac5ad43921c9c6fa18e435d70bb99647c0feeade0c  legi/bofip140.html (BOI-TVA-DECLA-30-20-20-10-20131018)
934a0c2bcdbd24fa83d3cbe320f46e51b51cf8ea0265f04d160b3d56a203c023  legi/caisse.html (BOI-TVA-DECLA-30-10-30-20260325)
```
