# Constats de l'éditeur à corriger par les lots NAV (essais en navigateur sur son PC)

Chaque constat se corrige dans le lot NAV dont il relève (ou plus tôt, si un lot précédent touche le même écran), avec un test en navigateur qui le reproduit ; le lot dit dans « faits » lesquels il a corrigés.

## C1 — Caisse : « Il reste … à répartir » affiché deux fois (02/11/2026, vers 01 h 50 UTC, capture ; lot NAV-2)

Message de l'éditeur : « la ici affiche 2 fois "Il reste 20,00 € à répartir. Il reste 20,00 € à répartir." donc une fois quand on choisi l'avoir mais si je clique sur encaisser alors il affiche encore une le même message. donc il faut affiche une seul fois même si cliquer sur encaisser ».

Écran : caisse du magasin, section « Règlement », total à payer 350,00 €, une part en mode « Avoir » avec le bon AV-PARIS-2026-000001 (330,00 €) ; le texte d'aide en direct « Il reste 20,00 € à répartir. » (`ReglementComptant::RESTE_A_REPARTIR`, `app/Domain/Facturation/Services/ReglementComptant.php`, affiché par `app/Filament/Concerns/SaisitUnReglementEnParts.php`) s'affiche, puis, au clic sur « Encaisser », le refus de validation affiche le même texte une seconde fois.

À faire : le message ne s'affiche qu'une fois, avant comme après le clic (par exemple, le refus de validation remplace l'aide en direct au même endroit, ou l'aide se met en rouge au lieu d'être répétée) ; même règle partout où ce trait sert (caisse, encaissement d'une facture) ; test en navigateur : saisie incomplète, clic sur « Encaisser », un seul message visible ; saisie complétée, plus aucun message.

## C2 — Fiche d'un avoir : les documents de « Crédit appliqué » ne sont pas cliquables (02/11/2026, vers 01 h 55 UTC, capture ; lot NAV-2)

Message de l'éditeur : « et là il faut que les factures sur Crédit appliqué soit cliquable pour aller voir la facture ou ticket comme les avoirs ».

Écran : fiche d'un avoir (`/app/{magasin}/avoirs/{id}`), section « Crédit appliqué » : deux lignes « Facture TK-PARIS-2026-000002 — 20,00 € » et « Facture TK-PARIS-2026-000003 — 330,00 € », numéros en texte simple.

À faire :
- chaque numéro de document de cette section est un lien vers la fiche du document (ticket ou facture), pour qui a le droit de la voir ; sinon le numéro reste un texte simple, sans lien qui mènerait à un refus ;
- même règle partout où un numéro de document s'affiche sans lien alors que les autres le sont : imputations et paiements d'une facture, bons d'avoir utilisés, documents d'une clôture, avoirs d'une facture, facture d'origine d'un avoir, retours d'une facture d'achat (choix de Claude : tout numéro de document affiché devient un lien vers lui quand on peut le voir) ;
- un ticket est nommé « Ticket » et une facture « Facture » (aujourd'hui « Facture TK-… » pour un ticket) ;
- test en navigateur : le clic sur chaque numéro ouvre le bon document ; un vendeur qui ne voit pas ce document ne reçoit pas de lien.

## C3 — PDF des factures et des tickets : les modes de paiement ne se voient pas (02/11/2026, vers 10 h 30 et 10 h 45 UTC ; lot NAV-2, sauf si le diagnostic désigne un défaut à corriger plus tôt)

**Traité par le lot TR** (`tr/decision-tr.md`, § 4, réponses de l'éditeur « r1 = oui, r2 = oui » du 02/11 vers 22 h 10 UTC) : bloc à gauche des totaux, « Émettre et encaisser », reçu de paiement. Le lot NAV-2 le teste seulement en navigateur.

Constat de l'éditeur : « je vois que dans les factures et même dans les tickets ne marque pas les méthodes de paiements. et il faut placer tout à gauche du TTC total. donc tu mets pour tous les règlements dans cet endroit » ; précision, à la question de savoir ce que veut dire « tous les règlements » : « je parle si le règlement de la facture est fait en plusieurs méthodes, dont par carte ou espèces etc ».

Puis, vers 11 h 10 UTC : « pareil pour les tickets » : la même règle vaut pour les tickets que pour les factures.

Décision de l'éditeur (elle tranche la question métier) : il s'agit des règlements d'un même document en plusieurs modes (carte, espèces, chèque…). Chacun s'imprime sur sa ligne, et le bloc se place tout à gauche, sur la même bande que les totaux (Total HT, Total TVA, Total TTC), au lieu d'être en dessous. La règle de `CLAUDE.md` ne change pas : le PDF imprime les règlements reçus à l'émission et reproduit le document tel qu'émis ; un paiement reçu ensuite reste sur la fiche.

Diagnostic en cours (atelier wf_e6d5221e-141, rapport `paiements/diag-reglements-rapport.md`, captures dans `paiements/diag-reglements/captures/`) : il établit pourquoi l'éditeur ne voit aucun mode (règlements absents du contexte signé d'un ticket ou d'une facture réglée à l'émission, ou facture réglée après son émission) et mesure la nouvelle place avec dompdf. Le rapport vérifié fait foi pour la cause et la correction.

Diagnostic vérifié (02/11/2026 vers 11 h 50 UTC, rapport et section « Vérification » : `paiements/diag-reglements-rapport.md`) :
- aucun défaut dans les règlements retenus : une vente à la caisse en plusieurs modes, une facture réglée à la caisse, un bon d'avoir ou un crédit imputé à l'émission ont chacun leurs modes dans le contexte signé et les impriment ;
- cause principale : une facture (ou un ticket) faite par « Factures > Créer », émise, puis réglée par « Encaisser » n'imprime aucun règlement, par la règle « le PDF reproduit le document tel qu'émis » ; hors de la caisse, aucun geste n'encaisse au moment de l'émission (`FactureResource.php`, émission sans règlement) ;
- le bloc existant est petit, sous les totaux, se coupe entre deux pages près du bas de page (Total TTC seul en page 2 à 20 lignes) et coupe les références au milieu ;
- un paiement contre-passé après l'émission reste imprimé avec « Restant dû 0,00 € » : le libellé doit dire « à l'émission » ;
- la variante « à gauche du Total TTC » (une rangée de tableau : règlements à gauche en colonnes Date / Mode / Référence / Montant, totaux à droite) est compatible dompdf, jamais coupée, au-dessus du pied, sur 35 PDF réels mesurés ; 4 tests de `FacturePdfGeneratorTest` (lignes 418, 499, 830, 996) lisent l'ancienne structure et seront à réécrire avec les mêmes attentes ; diff dans `paiements/diag-reglements/captures/donnees/`.
Questions posées à l'éditeur (02/11 vers 11 h 55 UTC) : (1) un geste « Émettre et encaisser » sur les factures, et une part inférieure au total à l'émission est-elle permise ; (2) pour une facture réglée après son émission, un « reçu de paiement » PDF par encaissement (le PDF de la facture ne changeant jamais) ; (3) libellé « Restant dû à l'émission » (choix de Claude).

À faire :
- chaque règlement reçu à l'émission (date, mode, référence, montant), chaque crédit d'avoir (numéro de l'avoir, montant) et le restant dû s'impriment dans un bloc « Règlements » placé à gauche du tableau des totaux, sur la même bande, dans le PDF des tickets et des factures, duplicatas compris ;
- la variante tient sur une page A4 portrait avec un, deux et cinq règlements, une référence longue et un crédit d'avoir, sans chevaucher le pied fixé en bas, en HTML compatible dompdf (tableaux) ;
- tout défaut que le diagnostic confirme (par exemple des règlements reçus à l'émission absents du contexte signé) se corrige avec un test du domaine qui le reproduit ;
- test en navigateur : une vente à la caisse en espèces et par carte puis une facture réglée à l'émission en plusieurs modes, PDF téléchargés, chaque mode et son montant lus dans le PDF, à gauche du Total TTC.
