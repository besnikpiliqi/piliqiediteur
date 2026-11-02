# Diagnostic C3 : pourquoi les modes de paiement ne se voient pas sur les PDF des factures et des tickets

Date : 02/11/2026. J'ai travaillé dans une copie isolée au commit poussé 53ed088, celui que l'éditeur essaie sur son PC (git worktree détaché dans `…/paiements/diag-reglements/wt`). Le vendor du dépôt y était partagé par liens durs, sauf `vendor/composer`, que j'ai copié. La copie avait son propre `.env` : base SQLite jetable dans la copie, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`, pilotes factices (Sirene, RNE, recommandé, Ordre). Elle avait aussi sa clé de caisse et sa clé du journal d'audit, générées dans la copie ; `caisse:diagnostiquer` répondait OK partout. Serveur : `php artisan serve` sur 127.0.0.1:8767. Navigateur : Chromium par Playwright. Dans /home/user/piliqiediteur, je n'ai fait que des lectures, plus `git worktree add/remove/prune`. Je n'y ai lancé ni test ni pint, et je n'y ai rien écrit.

État de départ : `git status --short` du dépôt, relevé avant mon passage, montrait 21 lignes. Il est conservé dans `captures/donnees/diag-reglements-status-avant.txt`.

## Verdict

1. **Aucun défaut dans la conservation des règlements.** Tout ce qui est reçu à l'émission est bien retenu dans le contexte signé et s'imprime : vente à la caisse en plusieurs modes, facture à un professionnel réglée à la caisse, bon d'avoir, crédit du client imputé à l'émission. H2 est réfutée par le navigateur, par la base et par les tests du domaine déjà présents.
2. **Première cause (H1, confirmée, voulue par la règle).** Une facture faite par « Factures > Créer » puis « Émettre » n'imprime jamais aucun règlement. C'est aussi le cas d'un ticket fait de la même façon pour un particulier ou pour le client comptoir. Son règlement passe ensuite par « Encaisser », dans une autre transaction, après la signature. Hors de la caisse, l'interface n'offre aucun moyen d'encaisser au moment de l'émission. C'est le parcours normal d'une facture à un professionnel, ce qui explique « dans les factures ». D'après les constats C1 et C2 du fichier `navigateur/constats-editeur.md`, c'est aussi le cas d'au moins un ticket de l'éditeur.
3. **Seconde cause (H3, confirmée en partie).** Quand le bloc existe (ventes à la caisse), il s'imprime sous le tableau des totaux, aligné à droite, avec un titre gris de 8 px. Près du bas de la page, ce bloc est coupé entre deux pages, et le tableau des totaux lui-même peut l'être (Total HT et Total TVA sur la page 1, Total TTC sur la page 2). Une référence est aussi coupée au milieu (« CB- » puis « TRX-4521 »).
4. **H4 est réfutée.** La fiche montre chaque paiement avec son mode, sa date, sa référence et son montant (section « Paiements reçus »).
5. **La nouvelle place demandée (« tout à gauche du Total TTC ») tient sur une page A4.** Je l'ai mesurée avec dompdf dans la copie : 41 PDF, 1, 2 et 5 lignes de règlement, une référence de 100 caractères, un crédit d'avoir et le restant dû. La bande n'est jamais coupée et ne chevauche jamais le pied de page. Elle règle aussi les deux coupures décrites au point 3.

## Méthode

- **Données créées dans la copie** par les actions du domaine (script lancé hors de la copie, sur l'application de la copie) :
  - une société « Commerce ou atelier » validée, avec son identité légale complète, et un magasin réel « Boutique Paris » (code PARIS) ;
  - un responsable, « Rémi Responsable » (CreerMembreAction), dont le mot de passe a été choisi (ChangerSonMotDePasseAction) ;
  - 4 produits nés d'un inventaire d'ouverture validé (CARNET-A5, STYLO-BLEU, CASQUE-AUDIO, LIVRE-POCHE à 5,5 %) ;
  - un client professionnel (Atelier Dupont SARL), une particulière (Marie Martin) et le client comptoir.
- **Toutes les ventes, émissions, encaissements et avoirs ont été faits en cliquant**, connecté comme ce responsable, ce que ferait l'éditeur (le propriétaire ne vend jamais).
  - Les produits ont été ajoutés par la douchette, et le client choisi par le champ de recherche du Select. Je n'ai pas utilisé les fenêtres « Ajouter un produit » et « Chercher un client », qui renvoient un 419 à 53ed088 (voir `navigateur/diag-419-rapport.md`).
- **Pour chaque document :**
  - capture PNG de la fiche ;
  - PDF téléchargé par le bouton « PDF » ;
  - texte extrait avec `pdftotext -layout` ;
  - rendu PNG de chaque page avec `pdftoppm` ;
  - en base, lecture du bloc « contexte » signé (clés `reglements` et `credits_avoir`, par `FigeSonEmetteur::contexteSigne()`) et des paiements enregistrés (mode, référence, date de réception, instant d'enregistrement), avec l'instant de l'enregistrement signé.
- **Toutes les données relevées sont dans `captures/donnees/contexte-signe.json`.** Les captures sont dans `paiements/diag-reglements/captures/`.

## Tableau : scénario → fiche → PDF → contexte signé

| # | Scénario (fait au navigateur) | Document | Fiche | PDF | Contexte signé (`reglements` / `credits_avoir`) | Paiements en base |
|---|---|---|---|---|---|---|
| a | Caisse, client comptoir, casque + stylo (62,40 €), 20,00 € en espèces + 42,40 € par carte, réf. CB-TRX-4521 | TK-PARIS-2026-000001 | « Paiements reçus » : Espèces 20,00 €, Carte bancaire 42,40 € avec sa réf. (`a3-ticket-especes-carte-fiche.png`) | Bloc « RÈGLEMENTS » sous les totaux, à droite : « 02/11/2026 · Espèces 20,00 € », « 02/11/2026 · Carte bancaire · CB- / TRX-4521 42,40 € » (référence coupée), « Restant dû 0,00 € » (`a3-…-page-1.png`) | 2 paiements (especes 2000, carte_bancaire 4240, réf. CB-TRX-4521) / [] | 2 paiements, enregistrés à 10:20:26, même instant que l'enregistrement signé |
| b | Facture à Atelier Dupont SARL créée, émise depuis la liste, puis « Encaisser » : chèque 40,00 € (CHQ 1234567) + virement 28,80 € (réf. longue) | FAC-PARIS-2026-000001 | « Paiements reçus » : les 2 modes, dates et références (`b3-…-fiche.png`) | **Aucun règlement**, avant comme après le paiement : texte identique (`b1-…`, `b3-…`) | [] / [] | 2 paiements à 10:22:35, signature à 10:21:54 (41 s avant) |
| b' | Second téléchargement du même PDF (« duplicata ») | FAC-PARIS-2026-000001 | — | Même texte que b, octets différents (horodatage du PDF), **aucune mention « Duplicata »** (`b4-…pdf`) | — | — |
| c | Caisse, client Atelier Dupont SARL (facture à un professionnel réglée à l'émission) : carte 50,00 € (CB-778899) + espèces 14,80 € | FAC-PARIS-2026-000002 | Les 2 paiements | Bloc « RÈGLEMENTS » sous les totaux : carte (référence coupée « CB- / 778899 »), espèces, restant dû 0,00 € ; échéance imprimée (`c2-…`) | 2 paiements / [] | 2 paiements, même instant que la signature |
| c' | Facture réglée **en partie** à l'émission, en argent | — | — | Impossible dans l'interface : la caisse exige que les parts égalent exactement le total (`EmettreFactureAction::encaisserComptant`, lignes 361 à 368), et « Émettre » n'accepte aucun règlement (`FactureResource.php` ligne 882) | — | — |
| d1 | Avoir sur le casque de TK-1, client comptoir : bon d'avoir AV-PARIS-2026-000001 de 59,90 € ; puis caisse, client comptoir, casque + livre (68,80 €) : part « Avoir » avec ce bon + 8,90 € en espèces | TK-PARIS-2026-000002 | Espèces 8,90 € ; « Crédité par avoir » AV-…-000001 59,90 € | Bloc : « 02/11/2026 · Espèces 8,90 € », « Avoir AV-PARIS-2026-000001 59,90 € », « Restant dû 0,00 € » (`d3-…`) | 1 paiement (especes 890) / 1 crédit (AV-…-000001, 5990) | 1 paiement + 1 imputation (`paiement_id` NULL), même instant |
| d2 | Avoir sur la facture payée FAC-1 (crédit de 59,90 € au client), puis nouvelle facture à Atelier (casque + stylo, 62,40 €) émise : le crédit s'impute à l'émission | FAC-PARIS-2026-000003 | « Partiellement payée », crédit 59,90 € | Bloc : « Avoir AV-PARIS-2026-000002 59,90 € », « Restant dû 2,50 € » (`d6-…`) | [] / 1 crédit (5990) | aucun paiement à l'émission |
| d3 | Le reste de FAC-3 réglé ensuite : chèque 2,50 € | FAC-PARIS-2026-000003 | Payée, restant dû 0,00 €, chèque visible | **Inchangé : toujours « Restant dû 2,50 € »**, et le chèque est absent (`d8-…`) | inchangé | chèque enregistré 22 s après la signature |
| e | Ticket de Marie Martin (particulière) par « Factures > Créer », émis, puis réglé en partie : 20,00 € en espèces + 10,00 € par carte | TK-PARIS-2026-000003 | Partiellement payée, les 2 modes, restant dû 29,90 € | **Aucun règlement**, échéance imprimée (`e3-…`) | [] / [] | 2 paiements après la signature |
| f | Reconstitution de ce qu'a fait l'éditeur (C1, C2). Ticket du client comptoir par « Factures > Créer » (3 casques, 179,70 €), émis, 100,00 € en espèces ensuite. Avoir de 2 casques (119,80 €) : 79,70 € imputés sur ce ticket, le reste devient le bon AV-…-000003. Puis caisse : casque avec ce bon (40,10 €) + 19,80 € par carte | TK-PARIS-2026-000004, puis TK-PARIS-2026-000005 | TK-4 : espèces 100,00 € + crédit 79,70 € ; TK-5 : carte + bon | **TK-4 : aucun règlement** (`f5-…`) ; TK-5 : bloc carte « CB- / 990011 » 19,80 €, « Avoir AV-…-000003 40,10 € », restant dû 0,00 € (`f6-…`) | TK-4 : [] / [] ; TK-5 : 1 paiement / 1 crédit | conformes |

## Verdict sur chaque hypothèse

- **H1 : la facture réglée après son émission n'imprime aucun règlement, par construction.** CONFIRMÉE, et c'est voulu par la règle.
  - Preuves : scénarios b, d3, e et f (TK-4). Le PDF de FAC-1 a le même texte avant et après l'encaissement.
  - Le contexte signé est figé à l'émission. `ContenuFiscal::contexteDeLaFacture()` (lignes 199 à 233) lit `allocationsPaiement` et `allocationsAvoir` au moment de la signature (`EmettreFactureAction`, lignes 272 à 279).
  - `ReglementsALEmission::depuisLeContexte()` (lignes 38 à 55) ne lit que ce contexte, et `Facture::reglementsALEmission()` (lignes 564 à 570) le passe au PDF (`FacturePdfGenerator::html()`, ligne 58).
  - Le docblock de `ReglementsALEmission` (lignes 13 à 15) et celui de `reglements.blade.php` (lignes 6 à 8) disent qu'un paiement reçu ensuite reste sur la fiche, conformément à CLAUDE.md.
  - Le test `test_un_avoir_impute_apres_l_emission_ne_s_imprime_pas_sur_le_pdf` le prouve aussi ; je l'ai lancé dans la copie à 53ed088 et il réussit.
  - Dans l'interface, hors de la caisse, rien ne permet de régler à l'émission. L'action « Émettre » (`FactureResource::emettre()`, lignes 862 à 892) appelle `EmettreFactureAction::handle()` sans `ReglementComptant` (ligne 882), et « Encaisser » (`FactureResource::encaisser()`, lignes 818 à 850) appelle `EncaisserEnPlusieursModesAction`, en dehors de l'émission. Seule la caisse passe un règlement (`EnregistrerVenteCaisseAction`, lignes 132 à 137).
- **H2 : les règlements d'un ticket de la caisse ou d'une facture réglée à l'émission seraient absents du contexte signé.** RÉFUTÉE.
  - Preuves : scénarios a, c, d1, d2 et f (TK-5). Le contexte signé contient chaque part avec son mode, sa référence et son montant, ainsi que chaque crédit, et le PDF les imprime.
  - L'ordre dans `EmettreFactureAction::emettre()` le garantit : `encaisserComptant()` (lignes 262 à 264) passe avant `contenuFiscal->document()` puis `registre->enregistrerVente()` (lignes 273 à 279). Les paiements ont le même instant d'enregistrement que l'enregistrement signé.
  - Les 6 tests de `FacturePdfGeneratorTest` sur les règlements réussissent dans la copie à 53ed088, entre autres `test_les_reglements_de_l_emission_s_impriment_par_mode` et `test_une_facture_a_un_professionnel_payee_a_l_emission_imprime_son_echeance` (6 tests, 13 assertions).
  - Les clés `reglements` et `credits_avoir` existent depuis 13a3c8d (09/10), et le bloc du PDF depuis 243eceb (18/10). D'anciennes données de l'éditeur ne peuvent donc pas expliquer l'absence d'un bloc sur un document émis à 53ed088.
- **H3 : le bloc s'imprime, mais ailleurs que là où l'éditeur regarde.** CONFIRMÉE EN PARTIE.
  - Il s'imprime sous le tableau des totaux, aligné à droite (`factures/pdf.blade.php` lignes 54 et 56 ; `styles.blade.php` ligne 52 : `width: 260px; margin-left: auto; margin-top: 16px`), sous un petit titre gris de 8 px en capitales (ligne 54).
  - Près du bas de la page, il est **coupé entre deux pages**. Cas mesurés : brouillons de 14 à 18 lignes avec 5 règlements, et de 18 lignes avec 1 règlement ; titre « RÈGLEMENTS » sur la page 1, « Restant dû » sur la page 2 (`captures/avant-N16-R5-reglements-coupes-entre-deux-pages.png`).
  - Le **tableau des totaux lui-même** est coupé à 20 et 21 lignes : Total HT et Total TVA sur la page 1, Total TTC sur la page 2 (`captures/avant-N20-R1-totaux-coupes-entre-deux-pages.png`).
  - La date, le mode et la référence sont réunis dans une seule case d'environ 180 px (`reglements.blade.php` ligne 24). Une référence à tiret s'y coupe donc au milieu, à chaque essai (« CB- / TRX-4521 », « CB- / 778899 », « CB- / 990011 »).
- **H4 : la fiche ne montrerait pas les modes de paiement.** RÉFUTÉE.
  - La section « Paiements reçus » (`FactureResource::infolist()`, lignes 260 à 284) montre la nature, la date de réception, le mode (badge), le montant imputé et la référence de chaque paiement, contre-passations comprises. La section « Crédité par avoir » (lignes 286 à 305) montre les crédits.
  - Captures : `a3-…-fiche.png`, `b3-…-fiche.png`, `d8-…-fiche.png`, `e3-…-fiche.png`, `f5-…-fiche.png`.
  - À noter (déjà dans C2) : la fiche d'un ticket s'intitule « Afficher Facture ».
- **H5 : autre cause.** Trois éléments s'ajoutent, aucun n'empêche à lui seul l'impression des modes :
  - **(a) Le « Restant dû » du PDF est celui de l'émission, sans le dire.** Après un paiement ultérieur, le PDF imprime toujours « Restant dû 2,50 € » quand la fiche dit 0,00 € (scénario d3), ce qui peut faire croire à l'éditeur que le PDF ne suit pas les paiements.
  - **(b) Aucune mention « Duplicata ».** CLAUDE.md la demande à toute réimpression, mais elle n'existe pas à 53ed088 : `grep -i duplicata` ne trouve rien dans `app/` ni `resources/`. L'étape 3 de la feuille de route (« justificatifs et duplicatas produits depuis le contenu signé ») n'est pas encore faite.
  - **(c) Les modes ne peuvent pas être sur le PDF d'un ticket fait hors de la caisse.** La caisse est le seul endroit où un document se règle à son émission (scénario f, TK-4).

## Ce que l'éditeur voit exactement (reconstitution)

D'après `navigateur/constats-editeur.md`, les données de l'éditeur contiennent :

- l'avoir AV-PARIS-2026-000001, dont le « Crédit appliqué » porte 20,00 € sur TK-PARIS-2026-000002 et 330,00 € sur TK-PARIS-2026-000003 (C2) ;
- une vente à la caisse de 350,00 € réglée par ce bon (330,00 €) et 20,00 € (C1).

Un ticket qui reste dû après son émission (TK-2 avec ses 20,00 €) n'a pas pu être fait à la caisse, où toute vente est soldée. Il a donc été fait par « Factures > Créer » et réglé ensuite. C'est ce que reproduit le scénario f :

- **le PDF de TK-2 ne porte aucun règlement**, alors que sa fiche montre ses paiements et son crédit ;
- le PDF de TK-3 porte, sous le Total TTC, à droite, « 02/11/2026 · <mode> 20,00 € », « Avoir AV-PARIS-2026-000001 330,00 € » et « Restant dû 0,00 € ».

Pour ses factures à des professionnels (créées, émises, puis encaissées), **aucun PDF ne porte jamais de règlement**. Je n'ai pas accès à son PC. Le détail de chacun de ses documents reste donc à confirmer par la liste de ses PDF, mais il n'existe pas d'autre chemin à 53ed088 qui donne un PDF sans modes.

## Causes confirmées et corrections proposées

1. **Pas d'encaissement à l'émission hors de la caisse : cause principale côté factures.**
   - Fichiers : `app/Filament/App/Resources/FactureResource.php` lignes 862 à 892 (ligne 882), `app/Domain/Facturation/Actions/EmettreFactureAction.php` lignes 187 à 206 et 361 à 380.
   - Correction proposée, sans toucher à la règle « tel qu'émis » : un geste « Émettre et encaisser » sur la fiche ou la liste d'un brouillon.
     - Il reprendrait la saisie en parts de la caisse (`SaisitUnReglementEnParts`) et passerait un `ReglementComptant` à `EmettreFactureAction`, qui l'accepte déjà et exige alors `paiement.creer` (ligne 200).
     - Les parts reçues à ce moment entreraient dans le contexte signé et s'imprimeraient.
   - **Règle à faire trancher par l'éditeur avant de coder** : faut-il permettre une part inférieure au total, c'est-à-dire un acompte reçu à l'émission ? Aujourd'hui, `encaisserComptant()` refuse tout écart (lignes 364 à 368).
   - Tests : un test du domaine (facture à un professionnel émise avec deux parts, dont une partielle si l'éditeur l'accepte : PDF et contexte signé) et un test en navigateur.
2. **Bloc et tableau des totaux coupés entre deux pages, référence coupée : défaut de mise en page.**
   - Fichiers : `resources/views/documents/pdf/styles.blade.php` lignes 47 à 57, `totaux.blade.php` lignes 48 à 61, `reglements.blade.php` lignes 17 à 39.
   - Correction : la variante décrite plus bas (une bande d'une seule rangée de tableau, et la référence dans sa propre colonne).
   - Test : un brouillon de 20 lignes et un de 16 lignes avec 5 règlements. On vérifie que « Total HT », « Total TTC », « Règlements » et « Restant dû » sont sur la même page (`pagesDuPdf()` de TestCase), et que chaque texte tient entre les marges (`assertChaqueTexteTientEntreLesMarges()`).
3. **« Restant dû » ambigu sur le PDF après un paiement ultérieur.**
   - Fichier : `reglements.blade.php` ligne 35.
   - Proposition, à soumettre à l'éditeur puisqu'elle touche un libellé imprimé : « Restant dû à l'émission ». C'est le libellé que j'ai utilisé dans la variante.
   - Le test `test_aucune_mention_de_reglement_sur_une_facture_non_payee` (lignes 503 à 518) reste valable avec ce libellé.
4. **Mention « Duplicata » absente.**
   - C'est une exigence de CLAUDE.md, rattachée à l'étape 3. Ce n'est pas une régression, mais une réimpression d'aujourd'hui ne se distingue pas de l'original.
   - Elle demande de compter les impressions (première impression contre réimpression), ce qui relève de l'étape 3.

## Variante de mise en page « tout à gauche du Total TTC »

Faite **dans la copie seulement**. Le diff complet est dans `captures/donnees/variante-mise-en-page.diff` (4 vues, environ 70 lignes). Après les mesures, j'ai remis les vues de la copie dans leur état de 53ed088.

### Structure (compatible dompdf : tableaux, ni flex ni grid)

- `factures/pdf.blade.php` : un seul `@include('documents.pdf.totaux', ['document' => $facture, 'reglements' => $reglements, 'dateEmission' => …, 'calendrier' => …])`. L'include séparé du bloc disparaît.
- `documents/pdf/totaux.blade.php` : le tableau par taux reste au-dessus, puis vient la bande :

```html
<table class="bande-des-totaux">
    <tr>
        <td class="bande-gauche">@isset($reglements) @include('documents.pdf.reglements', …) @endisset</td>
        <td class="bande-droite"><table class="totaux">Total HT / Total TVA / Total TTC</table></td>
    </tr>
</table>
```

- L'avoir et le devis, qui appellent `totaux` sans `$reglements`, gardent une case gauche vide. Leurs totaux restent à la même place : « Total » commence à x = 366,6 pt sur l'avoir AV-PARIS-2026-000003 rendu avec la variante, comme avant (`captures/apres-avoir-AV3-page-1.png`).
- `documents/pdf/reglements.blade.php` : un titre `<div class="titre-reglements">Règlements</div>` hors du tableau. En disposition fixe, dompdf prend les largeurs de la première rangée, et un titre en `colspan` rendait les colonnes égales. Puis un tableau `thead` (Date, Mode, Référence, Montant), une rangée par paiement (date d'émission, libellé du mode, référence, montant) et une rangée par crédit d'avoir (date, « Avoir », numéro, montant). Enfin « Restant dû à l'émission », sur 3 colonnes, et son montant.
- CSS :

```css
table.bande-des-totaux { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
table.bande-des-totaux td.bande-gauche { vertical-align: top; padding: 0 14px 0 0; }
table.bande-des-totaux td.bande-droite { vertical-align: top; width: 260px; padding: 0; }
table.totaux { width: 100%; border-collapse: collapse; }          /* plus de margin-left:auto */
table.reglements { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9px; }
table.reglements th, table.reglements td { padding: 3px 6px; text-align: left; vertical-align: top; }
.titre-reglements { font-size: 8px; text-transform: uppercase; color: #7b8794; padding: 4px 6px 2px 6px; }
table.reglements tr.entetes th { font-size: 7px; font-weight: normal; text-transform: uppercase; color: #7b8794; border-bottom: 1px solid #d9e2ec; }
table.reglements .date { width: 15%; white-space: nowrap; }  .mode { width: 20%; }
table.reglements .reference { width: 48%; word-wrap: break-word; }  .montant { width: 17%; text-align: right; white-space: nowrap; }
table.reglements .restant-du td { font-weight: bold; border-top: 1px solid #d9e2ec; }
```

Les largeurs des colonnes doivent être en pourcentage. En px, dompdf les ignore en disposition fixe : j'ai essayé, et les colonnes restaient égales. Le tableau des lignes utilise déjà des pourcentages.

### Mesures (dompdf, MiseEnPageA4, TableauDesLignes ; positions lues par `pdftotext -bbox`, en points depuis le haut de la page A4 de 841,89 pt)

Jeux de règlements :

- R1 : 1 paiement en espèces, qui solde le document.
- R2 : espèces + carte avec une référence.
- R5 : espèces, carte (CB-778899), chèque (« CHQ 1234567 BNP PARIBAS »), virement avec une **référence de 100 caractères** (la limite de `PartDeReglement::LONGUEUR_DE_LA_REFERENCE`), un crédit d'avoir AV-PARIS-2026-000123 et un restant dû non nul.

Les documents réels sont ceux du tableau plus haut. Les « Nxx » sont des brouillons de xx lignes, qui amènent la bande vers le bas de la page. Le PDF d'un brouillon n'a pas de date d'émission, donc sa colonne Date est vide. Les tableaux complets sont dans `captures/donnees/mesures-avant.md` et `mesures-apres.md` (41 PDF par phase). Le script de mesure est `captures/donnees/analyse-mesures.py`.

| Cas | Avant (53ed088) | Après (variante) |
|---|---|---|
| TK-1 réel, 2 règlements | totaux 334,5 → bas du bloc 468,1 (133,6 pt de haut), à droite sous les totaux | bande 334,5 → 398,9 (64,4 pt), règlements à gauche (bord droit 345,8 pt), totaux à droite (366,6 pt) |
| TK1-R1 | 106,1 pt de haut | 49,6 pt (la hauteur du seul tableau des totaux) |
| TK1-R5 (référence de 100 caractères) | 193,2 pt ; la référence élargit le bloc vers la gauche | 140,2 pt ; la référence passe à la ligne dans sa colonne |
| N10-R5 | une page, 72,6 pt de jeu au-dessus du pied | une page, 125,7 pt de jeu |
| N14-R5 | **2 pages, bloc coupé** (« Restant dû » page 2) | **1 page**, 46,6 pt de jeu |
| N16 à N18-R5 | **bloc coupé** entre les pages 1 et 2 | bande entière en haut de la page 2 |
| N18-R1 | **bloc coupé** | 1 page, 58,1 pt de jeu |
| N20, N21-R1 | **tableau des totaux coupé** (TTC page 2) | N20 : 1 page, 18,6 pt de jeu (le minimum de tous les cas) ; N21 : bande entière en page 2 |
| N40-R5 | 2 pages, bloc entier | 2 pages, bande entière, 219,3 pt de jeu |
| FAC-1 réel, sans règlement | totaux à droite | identique (totaux à droite, case gauche vide) |

Dans les 41 PDF de la variante :

- « Total HT », « Total TTC », « Règlements » et « Restant dû » sont toujours sur la même page ;
- le bas de la bande reste au-dessus du haut du pied de page, avec au moins 18,6 pt de jeu ;
- le bloc des règlements n'atteint jamais le tableau des totaux : son bord droit est à 345,8 ou 345,9 pt, et les totaux commencent à 366,6 pt ;
- aucun texte ne sort des marges de 14 mm (39,69 à 555,59 pt).

dompdf ne coupe pas une rangée de tableau : quand la bande ne tient plus, il la porte entière sur la page suivante.

Captures avant et après (page(s) avant à gauche, après à droite) :

- documents réels : `captures/mep-avant-apres-reel-TK1-especes-carte.png`, `…-reel-TK2-especes-bon.png`, `…-reel-FAC3-credit-restant-du.png`, `…-reel-FAC1-sans-reglement.png` ;
- jeux de règlements : `…-TK1-R1.png`, `…-TK1-R5.png` ;
- bas de page : `…-N10-R5.png`, `…-N14-R5.png`, `…-N16-R5.png`, `…-N20-R1.png` ;
- agrandissements : `captures/mep-avant-TK1-R5-zoom-1.png` et `captures/mep-apres-TK1-R5-zoom-1.png`.

### Limites de la variante

- Quand la bande ne tient plus en bas de la page, elle passe **seule** en haut de la page suivante, séparée du tableau par taux. Elle n'est jamais coupée, mais le récapitulatif par taux reste en page 1 (N16-R5, N21-R1). Si l'éditeur veut les garder ensemble, on peut placer le tableau par taux dans la même rangée (deuxième rangée de la bande), au prix d'une bande plus haute.
- La colonne Référence fait environ 145 pt. Une référence de 100 caractères y prend 4 lignes. Le jeu R5 reste lisible, mais une seule part avec une référence aussi longue grandit la bande d'autant.
- La date imprimée est celle de l'émission pour toutes les rangées, puisque ce sont les règlements reçus à l'émission. Elle se répète, mais CLAUDE.md demande « date, mode, référence, montant ».
- Le libellé « Restant dû à l'émission » est une proposition, à faire valider (point 3 des corrections).
- **Tests à adapter** si la variante est retenue. Quatre tests de `tests/Feature/Facturation/FacturePdfGeneratorTest.php` lisent l'ancienne structure : `reglementsImprimes()` (lignes 436 à 447) cherche `<td class="libelle">…</td><td class="montant">`. Ils échouent avec la variante, ce que j'ai vérifié dans la copie : lignes 416 et 418, 499, 830 et 996. Il faudra réécrire ce lecteur pour les 4 colonnes, avec les mêmes attentes (mode, référence, montant, crédit, restant dû). Les 51 autres tests de `FacturePdfGeneratorTest` et `AvoirPdfGeneratorTest` passent avec la variante.

## Question métier : « pour tous les règlements »

**Précision déjà donnée par l'éditeur.** Dans `navigateur/constats-editeur.md`, C3 : « je parle si le règlement de la facture est fait en plusieurs méthodes, dont par carte ou espèces etc », puis « pareil pour les tickets ». Ce fichier note la décision : un document réglé en plusieurs modes, chaque mode sur sa ligne, le bloc à gauche des totaux, et la règle de CLAUDE.md inchangée. Je l'expose ci-dessous sans la trancher, comme demandé.

Ce que la phrase pourrait vouloir dire :

- **(a) Seulement les règlements reçus à l'émission, mais tous, chacun sur sa ligne, à la nouvelle place.** C'est déjà ce que retient le contexte signé. Rien ne s'y oppose. Pour qu'une facture à un professionnel en profite, il faut un geste qui encaisse à l'émission (correction 1).
- **(b) Aussi les règlements reçus après l'émission, sur le PDF ou sur le duplicata.** Ce qui s'y oppose :
  - **CLAUDE.md**, dans « Documents de vente », « Le PDF reproduit le document tel qu'émis, une réimpression porte la mention « Duplicata », et « Payé / Restant dû » reste visible sur la fiche ». Dans « Documents de vente » aussi : « une facture émise ne change plus jamais ». Enfin, « Les règlements reçus à l'émission s'impriment par mode […] ; les paiements reçus ensuite restent sur la fiche ».
  - **Le contenu signé.** Le PDF se produit depuis le bloc « contexte » signé à l'émission (`FigeSonEmetteur::contexteSigne()`). Y ajouter des paiements reçus ensuite reviendrait à imprimer des données qui ne sont pas celles du document signé.
  - **Le droit des factures.** Le V de l'article 289 du CGI impose d'assurer l'authenticité de l'origine, l'intégrité du contenu et la lisibilité de la facture, à compter de son émission et jusqu'à la fin de sa conservation. Le VII en charge l'émetteur et le destinataire. Le BOFiP précise qu'il faut entendre par « intégrité du contenu » que « l'intégralité des mentions, obligatoires ou non, figurant sur la facture d'origine » n'ont pas été modifiées (BOI-TVA-DECLA-30-20-30-10, § 100). Un règlement imprimé est une mention facultative : une fois imprimé, il fait partie du contenu, et un PDF qui changerait après un paiement modifierait la facture. L'article 242 nonies A de l'annexe II du CGI, dans sa version du décret n° 2024-1195, liste les mentions obligatoires. Le mode de paiement n'en fait pas partie, ni des mentions de l'article L441-9 du Code de commerce (échéance, pénalités, indemnité forfaitaire, escompte). L'imprimer reste donc une information commerciale, figée comme le reste. Une correction passe par une note rectificative qui renvoie à la facture d'origine (article 242 nonies A, et CLAUDE.md : correction par un avoir).
  - **La caisse.** Le 3° bis du I de l'article 286 du CGI exige un logiciel ou système de caisse qui satisfait des conditions d'inaltérabilité, de sécurisation, de conservation et d'archivage des données. Le BOI-TVA-DECLA-30-10-30 précise trois points : chaque règlement s'enregistre avec son mode (§ 30 et § 50) ; une correction se fait par des opérations de « plus » et de « moins », jamais en réécrivant (§ 90) ; le logiciel conserve les données permettant de produire les pièces justificatives émises (§ 130). Le mot « duplicata » n'apparaît pas dans ce BOI (version du 04/07/2018, vérifiée), mais un duplicata doit reproduire le justificatif émis, sinon il n'en est pas un.
  - **Les logiciels de caisse certifiés NF525.** Ils marquent chaque réimpression « Duplicata » et en comptent le nombre (exigence d'Infocert pour NF525, rapportée par la documentation d'un éditeur de caisses), et ne réécrivent pas le ticket émis. Un paiement ultérieur y donne lieu à un nouveau justificatif de règlement, pas à une modification du ticket. Je n'ai pas pu lire le référentiel AFNOR lui-même : c'est la pratique rapportée, pas un texte officiel.
  - **Solutions qui respectent ces règles, si l'éditeur veut que le client ait une trace des paiements ultérieurs :**
    - les paiements sur la fiche (déjà fait) ;
    - un **reçu de paiement** ou « relevé de règlement » distinct, produit depuis le contenu signé de l'encaissement. `ContenuFiscal::encaissement()` signe déjà le mode, le montant, la référence, la date de réception, le client et ce qu'il impute sur chaque facture. Il citerait la facture réglée et son restant dû après ce paiement, sans toucher la facture ;
    - un **relevé de compte client** (factures, paiements, crédits, solde) ;
    - le geste « Émettre et encaisser » (correction 1), pour qu'un paiement reçu au comptoir au moment de la facture soit un règlement à l'émission ;
    - à l'étape 2, le statut « encaissée » transmis à la plateforme pour les services, qui est un flux à part et ne touche pas la facture.

### Sources

- [Article 286 du CGI, Légifrance](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051764897)
- [Article 242 nonies A de l'annexe II du CGI, Légifrance](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000050811276)
- [Code de commerce, articles L441-9 à L441-16, Légifrance](https://www.legifrance.gouv.fr/codes/id/LEGISCTA000038411051/2019-04-26)
- [BOI-TVA-DECLA-30-20-30-10 (intégrité du contenu, article 289 V et VII du CGI), BOFiP](https://bofip.impots.gouv.fr/bofip/8862-PGP.html)
- [BOI-TVA-DECLA-30-10-30 (logiciels et systèmes de caisse), BOFiP, version du 04/07/2018](https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20180704)
- [BOI-TVA-DECLA-30-20-20-10 (mentions obligatoires générales), BOFiP](https://bofip.impots.gouv.fr/bofip/140-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-10-20121231)
- [RetailForce, ReprintCount (duplicata et compteur de réimpressions pour NF525)](https://support.retailforce.cloud/hc/en-gb/articles/10331867832081-ReprintCount)
- Sur l'attestation et la certification des caisses en 2025 et 2026 (contexte seulement, pas utilisé pour conclure) : [Légifiscal, loi de finances pour 2026](https://www.legifiscal.fr/actualites-fiscales/4460-loi-finances-2026-retablissement-auto-certification-logiciels-caisse.html)

## Nettoyage

- **Serveur.** J'ai arrêté le serveur du port 8767 (`php artisan serve`, pid 21439) et son processus `php -S 127.0.0.1:8767` (pid 21443), et seulement eux. Plus aucun processus n'écoute sur 8767 (curl : connexion refusée).
- **Copie.** `git -C /home/user/piliqiediteur worktree remove --force …/diag-reglements/wt` puis `git worktree prune` : codes de sortie 0. `git worktree list` ne montre plus que le dépôt. Avec la copie ont disparu sa base SQLite, sa clé de caisse de développement et son `.env`.
- **Fichiers supprimés.** Mes scripts, la sauvegarde des vues et le journal du serveur.
- **Fichiers gardés :**
  - `paiements/diag-reglements/captures/` : captures, PDF et rendus, plus `captures/donnees/` (contexte signé, mesures, script de mesure, diff de la variante, état git avant et après) ;
  - ce rapport.
- **Ni base PostgreSQL, ni vendor.** Aucune base PostgreSQL créée. Aucun fichier du vendor du dépôt n'a été modifié : `find vendor -newer` ne trouve rien depuis le début des essais.
- **`git status --short` n'est PAS identique avant et après**, et ce n'est pas de mon fait.
  - 8 lignes se sont ajoutées pendant mon passage : `ConstaterLInscriptionALOrdreAction.php`, `VerifierLOrdreAction.php`, `AvisAuxClientsDuCabinet.php` (supprimé, indexé), `EmettreLaFactureRectificativeAction.php`, `Societe.php`, `CorrectionDuSirenDUnCabinetTest.php`, `SirenErroneTest.php` et `RegularisationDUneFactureTest.php`.
  - Ces fichiers ont été modifiés entre 10:28 et 10:39 UTC par la chaîne d'agents du lot A7b, qui travaille sur le dépôt (corrections de la rectificative et de l'Ordre). Je n'ai jamais ouvert ces fichiers en écriture.
  - HEAD est resté 81de7e8, aucun stash n'a été créé, et aucune vue (`resources/views`) n'est modifiée dans le dépôt.
  - Les deux relevés sont dans `captures/donnees/diag-reglements-status-avant.txt` et `diag-reglements-status-apres.txt`.

## Vérification (contre-relecture du 02/11/2026, 10:43 à 11:10 UTC)

Les captures et les données de cette vérification sont dans `paiements/diag-reglements/verif/`, avec les chemins absolus sous `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/paiements/diag-reglements/verif/` :

- `captures/` : PNG, PDF, `contexte-signe-verif.json`, `plan-stress.json`, `mesures-avant-verif.md`, `mesures-apres-verif.md`, `mise-en-page/avant|apres/` ;
- `scripts/` : scripts de préparation, de rendu et de mesure, et scénarios Playwright ;
- `tests-variante.txt`.

Le texte du premier agent, plus haut, n'est pas modifié.

### Méthode

- **Copie isolée.** `git worktree add --detach …/diag-reglements/wt-verif 53ed088`. Ce commit est bien la tête poussée : `git ls-remote origin` donne `refs/heads/claude/saas-invoice-editor-architecture-q6eizh = 53ed0884…`.
  - Vendor par liens durs (`cp -al`). `vendor/composer` et `vendor/autoload.php` sont de vraies copies, puis `composer dump-autoload --no-scripts` a été lancé dans la copie. `find vendor -newer` sur le dépôt : 0 fichier modifié.
  - `.env`, base SQLite, clé fiscale et clé du journal d'audit propres à la copie. `caisse:diagnostiquer` répond OK partout.
  - Serveur `php artisan serve` sur 127.0.0.1:8768. Navigateur : Chromium par Playwright (`/opt/pw-browsers`), sans `playwright install`.
- **Données.** Une société validée et le magasin PARIS, un responsable et 4 produits nés d'un inventaire d'ouverture, créés par les actions du domaine. Un client professionnel et une particulière.
- **Scénarios au navigateur.** Les scénarios a à g sont faits **au navigateur**, connecté comme le responsable (douchette, recherche du client, « Émettre », « Encaisser », « Avoir », « Contre-passer », bouton « PDF »).
- **Documents de contrainte.** 27 documents émis réels (caisse) servent à la mise en page, avec 1 à 22 lignes et 1, 2 ou 5 règlements, dont une référence de 100 caractères et un bon d'avoir.
  - Ils sont faits par `EnregistrerVenteCaisseAction` : l'écran ne permet pas de saisir vite 22 lignes.
  - Ils sont rendus par `FacturePdfGenerator`, comme le bouton « PDF ». Deux d'entre eux ont été téléchargés en plus au navigateur, avec un rendu identique.

### Verdict par point du résumé

1. **H2 réfutée : CONFIRMÉ.** Chaque mode est dans le contexte signé et s'imprime (`captures/contexte-signe-verif.json`) :
   - TK-1 : espèces 20,00 et carte 42,40 (CB-TRX-4521) ;
   - FAC-2, facture à un professionnel réglée à la caisse : carte 50,00 (CB-778899) et espèces 18,80 ;
   - TK-2 : espèces 8,90 et bon AV-1 59,90 ;
   - FAC-3 : crédit AV-2 59,90 à l'émission.

   Les 6 tests du domaine réussissent dans la copie, avec **14** assertions et non 13 (correction mineure).
2. **H1 : CONFIRMÉ.**
   - FAC-1 a été créée par « Factures > Créer », émise depuis la liste, puis encaissée par chèque (40,00) et virement (27,30). Le texte du PDF est identique avant et après l'encaissement (`diff` vide). Le contexte signé contient `reglements []` et `credits_avoir []`. Les paiements sont enregistrés à 10:51:40, la signature date de 10:51:27.
   - Même résultat pour TK-3, ticket de la particulière réglé en partie après son émission.
   - Toutes les lignes de code citées ont été relues et sont exactes : `FactureResource` 818-850, 862-892 et 882 ; `EmettreFactureAction` 187-206, 200, 262-264, 272-279, 361-368 et 364-368 ; `ContenuFiscal` 199-233 ; `ReglementsALEmission` 13-15 et 38-55 ; `Facture` 564-570 ; `FacturePdfGenerator` 58 ; `EnregistrerVenteCaisseAction` 132-137 ; `reglements.blade.php` 6-8, 17-39, 24 et 35 ; `styles.blade.php` 47-57 ; `factures/pdf.blade.php` 54 et 56.
   - Autres chemins d'encaissement, contrôlés :
     - aucune API de facture ni de paiement (`route:list` : seulement le panneau, `/code`, `/inscription`, `/profil/adresse` et `/rappels/recommande`) ;
     - la seule action groupée supprime des brouillons (`FactureResource.php` 769-772) ;
     - un devis converti donne un brouillon (`CreerFactureDepuisDevisAction`), donc le cas H1 ;
     - seule la caisse passe un `ReglementComptant` à l'émission (grep).
3. **H3 : CONFIRMÉ, et reproduit sur de vrais documents émis**, pas seulement sur des brouillons (`captures/mesures-avant-verif.md`).
   - Bloc coupé entre deux pages : TK de 14 à 20 lignes avec 5 règlements, TK de 19 et 20 lignes avec 1 règlement, FAC de 14 et 16 lignes avec 2 règlements.
   - **Tableau des totaux coupé**, Total TTC seul en page 2 : FAC de 18 lignes avec 2 règlements, et TK de 22 lignes.
   - Références coupées : « CB- / TRX-4521 », « CB- / 778899 ».
   - **Correction de méthode.** Les cas « Nxx » du rapport sont des brouillons auxquels des règlements ont été injectés dans la vue. Or un brouillon n'en a jamais : `reglementsALEmission()` rend un contexte nul, et le bouton PDF est masqué tant qu'il n'y a pas de numéro. De même, le jeu R5 (quatre paiements et un restant dû non nul) n'est pas atteignable à 53ed088 : à la caisse, les parts égalent le total, et le crédit imputé à l'émission arrive sans paiement. Le défaut reste réel sur des documents atteignables ; les seuils diffèrent seulement d'une ligne (N19 au lieu de N18 pour R1).
4. **H4 : CONFIRMÉ** (`captures/vb5-facture-pro-apres-paiement-fiche.png`). Une fiche de ticket s'intitule bien « Afficher Facture ».
5. **Autres constats : CONFIRMÉ.**
   - FAC-3 : le PDF dit « Restant dû 2,50 € » et la fiche 0,00 € (`captures/vd5-facture-credit-puis-cheque.pdf`, `vd5-zoom-fiche.png`).
   - `git grep -i duplicata 53ed088 -- app resources` ne trouve rien.
6. **Corrections proposées : CONFIRMÉ.** Elles sont cohérentes avec le code : l'émission accepte déjà un `ReglementComptant` et exige alors `paiement.creer`. La question de l'acompte à l'émission reste à poser à l'éditeur.
7. **Variante : CONFIRMÉ.** Le diff a été appliqué sans changement (`git apply`) et mesuré sur 35 PDF réels (`captures/mesures-apres-verif.md`).
   - Aucune coupure : la bande est entière en page 1 ou entière en page 2.
   - Bord droit des règlements à 345,8 pt, gauche des totaux à 366,6 pt. Aucun texte hors des marges. Jeu minimal de 18,6 pt sous la bande (TK de 17 lignes avec 5 règlements).
   - Avoir, devis et factures sans règlement : positions de chaque mot **identiques** avant et après (`pdftotext -bbox`).
   - La tenue sur une page tient aussi à la construction, pas seulement aux mesures :
     - dompdf 3.1.6 (`FrameDecorator/Page::_page_break_allowed`) refuse toute coupure dans une case de tableau et dans un tableau imbriqué (`_in_table > 1`). La rangée unique de la bande part donc entière sur la page suivante, tant qu'elle est moins haute qu'une page.
     - Le pied ne peut pas être chevauché : la marge basse de `@page` vaut 12 + h + 4 mm, et le pied est fixé 4 mm sous la zone de contenu.
   - Mes rendus concordent avec ceux du rapport (`captures/mep-avant-apres-TK1-R5.png` contre `verif/captures/mep-verif-avant-apres-TK-N01-R5.png`).
   - Le rendu téléchargé au navigateur avec la variante est le même (`verif/captures/vv2-variante-FAC2-caisse-navigateur-page-1.png`).
   - Puisque `totaux.blade.php` est commun aux quatre documents, la variante empêche aussi, par construction, la coupure du tableau des totaux d'un avoir ou d'un devis long. Je ne l'ai pas mesurée.
8. **Limites : CONFIRMÉ.**
   - Bande seule en page 2 : TK de 18 à 21 lignes avec 5 règlements, FAC de 17 à 19 lignes.
   - Avec la variante, 4 tests échouent : lignes 418, 499, 830 et 996. La ligne 416 n'est que l'appel du test de la ligne 418. Les 51 autres tests de `FacturePdfGeneratorTest` et `AvoirPdfGeneratorTest` passent (55 au total, `tests-variante.txt`).
   - `test_aucune_mention_de_reglement_sur_une_facture_non_payee` reste valable : le libellé « Restant dû à l'émission » ne s'imprime que s'il y a des règlements.
9. **Question métier.** Elle est déjà tranchée par l'éditeur dans C3 (`navigateur/constats-editeur.md`) : sens (a). Les sources ont été ouvertes :
   - **CONFIRMÉ** :
     - BOI-TVA-DECLA-30-20-30-10, § 100 (cité mot pour mot) ;
     - art. 289 V du CGI ; le VII vise l'assujetti qui émet ou reçoit les factures ;
     - art. 242 nonies A de l'annexe II (décret n° 2024-1195, en vigueur au 01/01/2025) : aucun mode de paiement parmi les mentions ;
     - art. L441-9 du Code de commerce : échéance, escompte, pénalités, indemnité forfaitaire, pas de mode de paiement ;
     - BOI-TVA-DECLA-30-10-30 : le mot « duplicata » n'y figure pas, et le § 90 parle d'opérations de « plus » et de « moins ».
   - **CORRIGÉ** :
     - La note rectificative qui renvoie à la facture d'origine ne vient pas de l'art. 242 nonies A, qui n'en dit rien. Elle vient de l'**art. 289 I-5 du CGI**.
     - Dans le BOI-TVA-DECLA-30-10-30, le mode de règlement est au **§ 50**. Le § 30 dit seulement « quel que soit le mode de règlement ».
     - Le **§ 130** porte sur la *sécurisation* des données qui permettent de produire les justificatifs, pas sur leur conservation.
     - Le lien de l'art. 286 mène aujourd'hui à la version en vigueur depuis le 27/06/2026 (LEGIARTI000054337091, loi n° 2026-534). Le 3° bis est inchangé sur le fond. Légifrance annonce l'abrogation de certaines dispositions au 01/01/2027, à surveiller pour l'étape 3.
   - **NON VÉRIFIABLE** : la page RetailForce répond 403. Le rapport la donnait déjà comme une pratique rapportée, pas comme un texte officiel.
10. **Nettoyage du premier agent : CONFIRMÉ.** `…/diag-reglements/wt` n'existait plus, et rien n'écoutait sur le port 8767.

### Ce que le rapport avait manqué

- **Contre-passation après une vente à la caisse.** Scénario g, au navigateur, sur TK-1 : la carte de 42,40 € est contre-passée comme erreur, puis le ticket est réglé par chèque. Le PDF imprime toujours « Carte bancaire · CB-TRX-4521 42,40 € » et « Restant dû 0,00 € », sans le chèque. La fiche montre le paiement, sa contre-passation en rouge et le chèque (`verif/captures/vg3-ticket-carte-contre-passee-puis-cheque.pdf`, `vg3-zoom-fiche.png`).
  - C'est conforme à « tel qu'émis », mais le PDF montre alors un mode annulé. Cela renforce le libellé « Restant dû à l'émission » à faire valider, et la piste du reçu de paiement.
  - Cela réfute aussi un raisonnement du rapport : « un ticket qui reste dû après son émission n'a pas pu être fait à la caisse ». Un ticket de caisse redevient dû après une contre-passation : TK-1 devait 42,40 € avant le chèque. La reconstitution des données de l'éditeur (TK-2 fait par « Factures > Créer ») reste plausible, mais ce n'est pas la seule possible.
- **Autres causes côté PC de l'éditeur, écartées.**
  - Version : la tête poussée est 53ed088.
  - Vues Blade : aucun `config/view.php`, donc `view.check_cache_timestamps` vaut `true` par défaut, et une vue compilée se refait dès que sa source change.
  - Le PDF est produit à chaque clic (`EnvoieLesDocuments::telechargerLePdf` → `generer()->output()` → `streamDownload`), sans cache serveur. L'e-mail joint un PDF produit par le même générateur.
  - Reste seulement, invérifiable d'ici, la réouverture sous Windows d'un ancien fichier téléchargé sous le même nom.
- **Ticket fait à la caisse avec le bon d'avoir (C1).** Son PDF imprime bien le bon et la part de 20,00 €, sous les totaux. Si l'éditeur dit « même dans les tickets », c'est qu'il regardait un ticket fait hors de la caisse ou réglé ensuite (cause 1), ou qu'il n'a pas vu le petit bloc sous les totaux (cause 2).

### Verdict final : CORRIGÉ

Les conclusions du rapport tiennent :

- cause 1 (H1, voulue par la règle) ;
- cause 2 (H3, place du bloc et coupures entre pages) ;
- H2 et H4 réfutées ;
- la variante « à gauche du Total TTC » est compatible avec dompdf et tient sur une page A4 avec 1, 2 et 5 règlements.

Corrections apportées :

- le nombre d'assertions (14 et non 13) ;
- les cas « Nxx » sont des brouillons aux règlements injectés, mais le défaut est reproduit sur des documents émis ;
- le ticket dû après son émission peut aussi venir d'une contre-passation ;
- l'article de la note rectificative est le 289 I-5, et non le 242 nonies A ;
- les nuances sur les § 30 et § 130 du BOI caisse.

Ajouts :

- un paiement contre-passé reste imprimé sur le PDF ;
- les causes liées au PC de l'éditeur sont écartées.

### Nettoyage de cette vérification

- **Serveur.** Le groupe de processus du port 8768 est arrêté (`php artisan serve`, pid 27816, et `php -S`, pid 27822) ; curl répond « connexion refusée ».
- **Copie.** `git worktree remove --force …/wt-verif` puis `git worktree prune` : codes de sortie 0. Le vendor du dépôt est intact (0 fichier plus récent que le début des essais). Aucune base PostgreSQL n'a été créée, rien n'a été commité ni poussé.
- **`git status --short` : 30 lignes après, contre 29 avant.**
  - La ligne ajoutée est ` M tests/Feature/Facturation/RegularisationDUneFactureConcurrenceTest.php`, modifié à 10:50:10 UTC par la chaîne A7b. Je n'ai rien écrit dans le dépôt.
  - HEAD est toujours 81de7e8, sans stash.
  - Le worktree `nouvelle-session/nav-A7b-relecture/wt`, créé à 10:53 UTC par la chaîne A7b, est laissé en place.
  - Relevés : `paiements/status-avant-verif.txt` et `paiements/status-apres-verif.txt`.
