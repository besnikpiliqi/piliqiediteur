# Constats de l'éditeur à corriger par les lots NAV (essais en navigateur sur son PC)

Chaque constat se corrige dans le lot NAV dont il relève (ou plus tôt, si un lot précédent touche le même écran), avec un test en navigateur qui le reproduit ; le lot dit dans « faits » lesquels il a corrigés.

## C1 — Caisse : « Il reste … à répartir » affiché deux fois (02/11/2026, vers 01 h 50 UTC, capture ; lot NAV-2)

Message de l'éditeur : « la ici affiche 2 fois "Il reste 20,00 € à répartir. Il reste 20,00 € à répartir." donc une fois quand on choisi l'avoir mais si je clique sur encaisser alors il affiche encore une le même message. donc il faut affiche une seul fois même si cliquer sur encaisser ».

Écran : caisse du magasin, section « Règlement », total à payer 350,00 €, une part en mode « Avoir » avec le bon AV-PARIS-2026-000001 (330,00 €) ; le texte d'aide en direct « Il reste 20,00 € à répartir. » (`ReglementComptant::RESTE_A_REPARTIR`, `app/Domain/Facturation/Services/ReglementComptant.php`, affiché par `app/Filament/Concerns/SaisitUnReglementEnParts.php`) s'affiche, puis, au clic sur « Encaisser », le refus de validation affiche le même texte une seconde fois.

À faire : le message ne s'affiche qu'une fois, avant comme après le clic (par exemple, le refus de validation remplace l'aide en direct au même endroit, ou l'aide se met en rouge au lieu d'être répétée) ; même règle partout où ce trait sert (caisse, encaissement d'une facture) ; test en navigateur : saisie incomplète, clic sur « Encaisser », un seul message visible ; saisie complétée, plus aucun message.
