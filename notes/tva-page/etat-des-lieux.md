# État des lieux de la TVA dans PILIQI (22/11/2026)

Tâche 1 sur 2, en lecture seule (rien n'a été modifié dans le dépôt ni dans `wt-tva`). La maquette de la page « TVA » est la tâche 2.

## 0. Ce qui a été lu

- **Code poussé** : `/home/user/piliqiediteur`, branche `claude/saas-invoice-editor-architecture-q6eizh`, tête `fc3786bc`. Toute référence `fichier:ligne` sans autre mention renvoie à ce code.
- **Lot TVA27** : commit `fbdf759a` de la branche `lot/tva27` (worktree `scratchpad/wt-tva`), fait le 22/11/2026 à 22 h 31 UTC, pendant cette lecture. Il n'est **pas encore fusionné** dans la branche poussée. Ses références sont marquées « (TVA27) ».
- **Lot GRAPH-AN** : commit `0257979e` de `lot/graph-an`, pas fusionné non plus. Il est cité seulement pour sa « liste filtrée d'un jour ».
- **Maquettes acceptées** : `nouvelle-session/reprises/tva-credit-report.html` (choix K2 de l'éditeur, 15/11/2026) et `maquettes/message-tva-avant-piliqi.html` (approuvée le 18/11/2026).
- **Règles** : `CLAUDE.md` poussé (lignes citées) et sa version TVA27.
- **Textes officiels** déjà téléchargés dans `nouvelle-session/tva-page/sources-brutes/`, lus comme de simples données :
  - la notice de la CA3 (`ca3-5426.txt`) et le formulaire CA3 (`ca3-5377.txt`) ;
  - les pages impots.gouv : `page-remb.txt` (remboursement), `faq-remb.txt` (modifiée le 15/10/2026) et `actu-rsi.txt` (suppression du régime simplifié) ;
  - la page service-public sur la déduction (`sp-F23569.txt`, vérifiée le 24/09/2026) ;
  - le BOFiP `BOI-CTX-DG-20-50-10`.

## Réponse courte à « est-ce que le code applique ces règles ? »

**En partie.**

1. **Le calcul de base est juste.** La TVA à payer d'un mois est la TVA collectée moins la TVA déductible, calculée en centimes depuis les documents. Le cas A donne bien 112,00 − 100,00 = 12,00 €.
2. **La vente sans TVA est gérée.** Une livraison vers un DOM ou un export se vend sans TVA, avec ses conditions. Le cas B donne bien −100,00 €.
3. **Le report du crédit (option 1) n'existe que dans le lot TVA27**, qui n'est pas encore fusionné. Dans le code poussé, un mois négatif s'affiche « TVA à payer (estimation) −100,00 € », sans aucun lien avec le mois suivant.
4. **Le remboursement (option 2) n'existe nulle part.** TVA27 l'écarte même exprès, par une phrase grise : « Sans les remboursements de crédit de TVA demandés : ils se noteront avec l'état de TVA. »
5. **Les trois conditions de déduction ne sont tenues qu'en partie :**
   - une charge ne se valide pas sans sa pièce, mais une facture d'achat validée sans pièce compte quand même toute sa TVA ;
   - la TVA retenue est bien celle de la pièce du fournisseur ;
   - PILIQI ne lit jamais la pièce : ni le nom de la société, ni « ticket ou facture », ni le seuil de 150 € HT ;
   - il ne peut pas savoir si un achat sert l'activité. Il ne fait que proposer 0 % ou 80 % de déduction pour quelques types de charge.
6. **Il n'y a ni page « TVA », ni entrée « TVA » dans aucun menu.**
   - La TVA se lit à quatre endroits épars : la carte et le graphique du tableau de bord, le bloc sous le tableau des charges, la fiche d'une facture d'achat, et la colonne « TVA déductible » d'une charge.
   - Aucun écran ne dit de quelles factures d'achat et de quelles charges vient la TVA déductible d'un mois, ni dans quel état elles sont.

---

## (a) Règle par règle

| # | Règle de l'éditeur | Code poussé | Lot TVA27 |
|---|---|---|---|
| 1 | Pas de virement par achat : collectée − déductible, à la déclaration | **FAIT** (estimation, par mois) | FAIT (par période) |
| 2 | Déclaration mensuelle ou trimestrielle (CA3) | **FAIT EN PARTIE** (tout au mois) | **FAIT** (mois ou trimestre civil) |
| 3 | CA12 (régime simplifié) | **PAS FAIT** (proposé mais calculé comme un mensuel) | Retiré (le régime simplifié disparaît au 01/01/2027) |
| 4 | Cas A : 112 − 100 = 12 € à reverser | **FAIT** | FAIT |
| 5 | Cas B : vente sans TVA (DOM, export) → −100 € | Exonération **FAIT** ; −100 € **FAIT EN PARTIE** (montré « à payer −100 € ») | **FAIT** (« crédit à reporter 100 € ») |
| 6 | Option 1 : report sur les mois suivants | **PAS FAIT** | **FAIT** |
| 7 | Option 2 : remboursement direct | **PAS FAIT** | **PAS FAIT** (écarté exprès) |
| 8 | Condition 1 : facture au nom de l'entreprise (ticket insuffisant au-delà de 150 € HT) | **FAIT EN PARTIE** | inchangé |
| 9 | Condition 2 : TVA clairement indiquée (auto-entrepreneur : 0 €) | **FAIT EN PARTIE** | inchangé |
| 10 | Condition 3 : achat lié à l'activité | **FAIT EN PARTIE** (ce qui peut l'être) | inchangé |
| 11 | Espace dédié à la TVA (message, texte, statut, d'où vient la TVA récupérée) | **PAS FAIT** | **PAS FAIT** (le bloc du crédit est dans la page des charges) |
| 12 | « TVA » écrit dans le menu | **PAS FAIT** | **PAS FAIT** |
| 13 | Noter « remboursement demandé et reçu », puis recalculer la chaîne du crédit | **PAS FAIT** | **PAS FAIT** (décision TVA27 « étape 5 » révisée par l'éditeur le 22/11) |

### Précisions et preuves

**1. Compensation collectée − déductible.**
- `app/Domain/Charges/Support/MontantsDeTva.php:27-53` :
  - `collectee()` = ventes nettes des avoirs + autoliquidation des charges validées ;
  - `deductible()` = achats + charges validées ;
  - `reelle()` = collectée − déductible ;
  - `estimee()` = la réelle, avec aussi les charges à valider.
- `app/Domain/Charges/Services/TvaDeLaPeriode.php:62-94` (`parMois`) : chaque vente et chaque achat compte dans le mois de son magasin, chaque charge dans son mois de TVA. Les avoirs clients et les avoirs fournisseur sont soustraits (l. 72). Tout s'additionne en centimes dans le code.
- Affichage :
  - carte « TVA à payer (estimation) — collectée X − déductible Y » (`resources/views/filament/grille-des-graphiques.blade.php:33-52`) ;
  - graphique « TVA à payer par mois (estimation) » (`app/Domain/TableauDeBord/Services/SeriesDesGraphiques.php:152-170`).
- C'est toujours une estimation. Une info-bulle le dit (l. 40) : « L'état de TVA officiel, avec l'exigibilité des services et les règles de la déclaration, viendra avec la comptabilité ».

**2. Mensuel ou trimestriel.**
- Code poussé : `TvaDeLaPeriode` ne connaît que le mois. Une société au trimestriel voit trois mois séparés, jamais son trimestre.
- (TVA27) `app/Domain/Tenancy/Enums/CadenceDeLaTva.php` et `RegimeTva::cadence()` (`RegimeTva.php:86-93`, TVA27).
- (TVA27) `app/Domain/Charges/Support/ReportDuCreditDeTva.php:49-63` : les trois mois d'un trimestre civil s'additionnent avant le report.

**3. CA12.**
- Code poussé : `RegimeTva::ReelSimplifie` existe (`app/Domain/Tenancy/Enums/RegimeTva.php:31`). `TvaDeLaPeriode` ne met à part que la franchise (l. 132-134), donc le simplifié se calcule mois par mois, sans déclaration annuelle ni acomptes.
- (TVA27) Le régime simplifié est retiré (`RegimeTva.php:14-18`, TVA27). C'est conforme à `actu-rsi.txt` :
  - le RSI est supprimé au 1er janvier 2027 (article 38 de la loi de finances 2025) ;
  - la CA3 est trimestrielle jusqu'à 1 000 000 € de chiffre d'affaires l'année précédente et 1 100 000 € l'année en cours ;
  - la dernière CA12 est à déposer au plus tard le 4 mai 2027.

**4. Cas A.**
- La vente se calcule depuis son TTC : 672,00 € TTC à 20 % donnent 112,00 € de TVA et 560,00 € HT (`app/Domain/Facturation/Services/CalculateurLigne.php:156`, `chiffrerUneVente`).
- L'achat se calcule depuis son HT, et la TVA de la facture du fournisseur prime : 500,00 € HT à 20 % donnent 100,00 € (`CalculateurLigne.php:410`, `chiffrerUnAchat` ; `app/Domain/Achats/Services/TvaDuFournisseur.php:21-47`).
- La carte affiche 12,00 €.

**5. Cas B.**
- **L'exonération est gérée.**
  - `app/Domain/Facturation/Enums/NatureOperation.php:28-29` (`ExportHorsUe`, `LivraisonDom`) et `:84-87` (`exonere()`).
  - Les conditions se contrôlent à l'émission (`app/Domain/Facturation/Services/ControleNatureOperation.php:54-64`) : adresse de livraison, transport par le magasin, n° de TVA valide sur VIES pour une livraison intracommunautaire.
  - La vente se fait au prix TTC du catalogue sans la TVA de son taux (`NatureOperation.php:16-18`).
  - Une preuve de sortie manquante est rappelée dans l'« À faire ». La vente se régularise après 150 jours (`app/Domain/Facturation/Services/RegularisationDUneExoneration.php:21, 83`).
- **Le résultat de −100 € :**
  - code poussé : la carte rend `reelle()` sans plancher (`app/Domain/TableauDeBord/Services/ChiffresDuTableauDeBord.php:90-100`). Elle affiche donc « TVA à payer (estimation) −100,00 € », et la courbe passe sous zéro, sans le mot « crédit » ;
  - (TVA27) la carte affiche 0,00 € avec la pastille « Crédit de TVA à reporter : 100,00 € » (`grille-des-graphiques.blade.php:36-53`, TVA27).
- **Remarque.** Un magasin situé en Guadeloupe, en Martinique ou à La Réunion vend à 8,5 %, pas à 0 %. Le cas B est une vente de la métropole vers ces départements.

**6. Option 1, le report.**
- Code poussé : chaque mois se calcule seul. La maquette acceptée le disait déjà : « octobre montre −16,40 € et novembre −21,00 €, sans lien entre eux ».
- (TVA27) La chaîne :
  - **`ReportDuCreditDeTva.php:43-105`** : en centimes, la réelle et l'estimation ont chacune leur chaîne, qui part de la période d'arrivée de la société, quelle que soit la période affichée ;
  - **point de départ** : le crédit d'une déclaration déposée (`app/Domain/Charges/Services/CreditsDeTva.php:140-154` `ancre()`, `app/Domain/Charges/Actions/DeclarerUnCreditDeTvaAction.php:56-87`). Un solde positif est refusé (l. 43) ;
  - **où** : seulement sur une TVA qui compte toute la société (`app/Domain/TableauDeBord/Support/Perimetre.php:95-103` `reporteLeCreditDeTva()`, TVA27). Jamais dans le panneau d'un magasin (`app/Filament/PageDesCharges.php:614`, TVA27).
- « Le même mois » est déjà vrai : toutes les ventes et tous les achats du mois, ou du trimestre, s'additionnent avant la soustraction.

**7. Option 2, le remboursement.** Il n'existe ni table, ni geste, ni état.
- (TVA27) Phrase grise `TextesDuCreditDeTva::SANS_LES_REMBOURSEMENTS` (`app/Domain/Charges/Support/TextesDuCreditDeTva.php:23`), montrée à trois endroits :
  - sous le graphique (`SeriesDesGraphiques.php:233`, TVA27) ;
  - sous le report de la page des charges (`resources/views/filament/page-des-charges.blade.php:213`, TVA27) ;
  - dans l'état téléchargeable (`app/Domain/Charges/Support/ContenuDeLEtat.php:102-118`, TVA27).
- (TVA27) `CLAUDE.md` l. 210, étape 5 : l'état de TVA « note les remboursements de crédit demandés (formulaire 3519, ligne 26), que le report du lot TVA27 ne compte pas encore ».
- **Seul contournement possible aujourd'hui (TVA27).** La société déclare le crédit de la déclaration où elle a demandé le remboursement, en tapant sa ligne 27, déjà nette de la demande. La chaîne repart alors de ce crédit, mais le remboursement lui-même ne se voit nulle part, et il faut le savoir pour le faire.

**8. Condition 1 : une facture au nom de l'entreprise.**
- **Charges** : pas de validation sans pièce.
  - `app/Domain/Charges/Actions/ValiderUneChargeAction.php:39` : « Cette charge n'a pas de pièce : une TVA ne se déduit pas sans pièce. » ; contrôle l. 67-68.
  - La base le garantit aussi (`app/Domain/Charges/Support/GardesDesCharges.php:122`).
  - Mais l'estimation compte aussi les charges « à valider » qui n'ont pas de pièce : `app/Domain/TableauDeBord/Services/ChargesDuTableauDeBord.php:62` lit les états validée et à valider, sans regarder la pièce.
- **Factures d'achat** : la pièce est facultative.
  - Une facture validée sans pièce est marquée « pièce du fournisseur manquante » (`app/Domain/Achats/Services/PiecesDuFournisseur.php:204-230`) et reste dans l'« À faire » (`app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php:905-924`).
  - **Sa TVA compte pourtant en entier dans la TVA « réelle »** : `app/Domain/TableauDeBord/Services/AchatsDuTableauDeBord.php:60-67` ne lit que le statut « validée ».
  - Un achat reçu par l'API ou la plateforme n'exige pas de pièce, et c'est voulu.
- **Nulle part dans le code :**
  - le contenu de la pièce : raison sociale et adresse de la société, mentions obligatoires, n° de TVA du fournisseur ;
  - la différence entre un ticket et une facture ;
  - un seuil de 150 € HT : aucun code.

  La pièce n'est contrôlée que pour la sécurité : type, PDF actif, antivirus.
- **Note de droit** :
  - service-public dit « seule une facture peut justifier la déduction », avec le montant ou le taux de TVA (`sp-F23569.txt`) ;
  - le seuil de 150 € HT est, à notre lecture, celui de la facture simplifiée, dont l'identification du client peut être omise ;
  - la règle et son article sont à faire vérifier par un professionnel.

**9. Condition 2 : une TVA clairement indiquée.**
- **Achats**.
  - La TVA retenue est celle de la facture du fournisseur, taux par taux (`TvaDuFournisseur.php:21-47`).
  - Un écart au-delà de la tolérance doit être confirmé. La confirmation pose la marque « TVA à vérifier » : sur la fiche (`app/Filament/App/Resources/FactureAchatResource.php:313-319`), dans une colonne et un filtre de la liste (l. 368-386), et au journal d'audit.
  - Une facture sans TVA se saisit à 0 %, donc 0 € déductible.
- **Charges**.
  - Une ligne par taux, dont « 0 % ou sans TVA » (`app/Domain/Charges/Enums/TauxDeCharge.php:14-33`).
  - La TVA et le TTC se recopient de la pièce à un centime près (`app/Domain/Charges/Services/ChiffrageDUneCharge.php:35, 67-116`).
- **Mais :**
  - aucune fiche de fournisseur ne dit « en franchise, sans TVA » : elle n'a que le nom, le SIRET, le n° de TVA et le pays ;
  - la première ligne d'une charge part à 20 % (`app/Filament/PageDesCharges.php:559`), même pour un magasin d'outre-mer. Qui ne tape que le HT d'une facture d'auto-entrepreneur reçoit donc une TVA déductible de 20 % proposée, jusqu'à ce que le comptable la corrige ;
  - une marque « TVA à vérifier » ne retire rien du total : la TVA compte quand même.

**10. Condition 3 : un achat lié à l'activité.**
- Une facture d'achat ne porte que des marchandises revendues, puisqu'un produit naît d'elle. Une charge est une dépense déclarée professionnelle.
- PILIQI propose 0 % de déduction pour un véhicule de tourisme, l'hébergement, le transport de personnes et les cadeaux, et 80 % pour le carburant d'un véhicule de tourisme (`app/Domain/Charges/Services/PropositionsDeTva.php:38-45`). Une phrase grise dit pourquoi (l. 61-71).
- Le comptable valide ou corrige (`app/Domain/Charges/Services/DroitsSurLesCharges.php:19-49`).
- Aucun contrôle ne peut dire qu'une dépense est personnelle.

**11. Espace dédié à la TVA.** La TVA se lit aujourd'hui seulement à ces endroits :
- **le tableau de bord** : la carte (`grille-des-graphiques.blade.php:33-52`), le graphique et « Voir les chiffres » (`SeriesDesGraphiques.php:152-170`), avec les séries TVA collectée, TVA déductible des achats, TVA déductible des charges, TVA à payer et estimation ;
- **la page des charges** : le bloc « La TVA du mois » (`page-des-charges.blade.php:118-173`) et, dans la liste, les colonnes TVA et TVA déductible avec « TVA à vérifier » (`PageDesCharges.php:434-437`) ;
- **la fiche d'une facture d'achat ou d'un avoir fournisseur** : « TVA par taux », avec la base, la TVA retenue et l'écart confirmé (`FactureAchatResource.php:329-354`). La liste des factures d'achat ne montre que le TTC (l. 266-274) ;
- **les documents de vente** : le récapitulatif par taux de chaque PDF, et la fiche d'une clôture du jour par taux avec l'aperçu de l'e-reporting (`app/Domain/Facturation/Services/RecapitulatifDeCloture.php`, en-tête) ;
- **le récapitulatif CSV** de « Télécharger les documents » : par document et par taux, avec la pièce du fournisseur, la TVA déductible et l'autoliquidation d'une charge (`app/Domain/Facturation/Services/RecapitulatifDesDocuments.php:72, 77`). Il demande l'autorisation « télécharger les documents par période », que seul le propriétaire a d'office.

Aucun écran ne liste, pour un mois, les factures d'achat, les avoirs fournisseur et les charges qui font sa TVA déductible, avec leur état. GRAPH-AN (`0257979e`, pas fusionné) ajoutera un clic sur un jour d'un graphique, qui ouvre la liste filtrée des documents de ce jour dans le magasin. Mais cette liste est celle du magasin, et elle ne montre pas la TVA de chaque document.

**12. Menu.** Aucune entrée ne contient « TVA », ni dans le code poussé ni dans TVA27 (recherche `navigationLabel` sans résultat). Les menus d'aujourd'hui :
- **Espace de la société** : Tableau de bord, Vérification (0), Identité (1), Magasins (1), Paramètres fiscaux (2), Les caisses (3), Charges (4), Télécharger les documents (4), Comptes, Cabinet comptable, Journal d'audit (90).
- **Panneau d'un magasin** :
  - Ventes : Caisse, Clients, Devis, Factures, Avoirs, Paiements, Ma caisse ou Les caisses, Clôtures, E-reporting, Caisse sécurisée, Télécharger les documents ;
  - Achats : Factures d'achat, Avoirs fournisseur, Inventaire d'ouverture, Fournisseurs ;
  - Catalogue ;
  - Paramètres ;
  - et, sans groupe : « Charges du magasin » (88) pour un responsable coché, puis « Tableau de bord de la société » (89), « Charges de la société » (90) et « La société » (90) pour l'expert-comptable déclaré.
- **Espace du cabinet** : Tableau de bord, Identité, Vérification, Clients (et, par client : Fiche, Graphiques, Charges, `ClientDuCabinetResource.php:322-328`), Invitations et demandes, Télécharger les documents, Collaborateurs, Journal d'audit.

**13. Noter un remboursement demandé, puis reçu, et recalculer.** Rien n'existe. Voici ce que disent les textes officiels téléchargés ; c'est utile pour la maquette.
- **Les lignes de la CA3.**
  - Ligne 25 : crédit de TVA (ligne 23 − ligne 16).
  - Ligne 26 : remboursement demandé sur le formulaire n° 3519.
  - Ligne 27 : « Le crédit de TVA dont le remboursement n'est pas demandé (ligne 25 – ligne 26) est reporté ligne 22 de la prochaine déclaration » (`ca3-5426.txt`, notice de la ligne 27).
- **La sortie du crédit se fait à la demande.** La notice de la ligne 22 dit : « Le crédit dont le remboursement a été demandé ne peut plus donner lieu à imputation. (CGI, ann II, art 242-0-E) ». Le crédit quitte donc la chaîne le jour de la demande, à la période de la déclaration qui la porte, et non le jour du virement. Le virement reçu ne change plus la TVA : il clôt la demande.
- **Un refus.**
  - La même notice précise : « Le montant du crédit dont le remboursement a été refusé pour des motifs d'irrecevabilité de la demande en la forme pourra être reporté sur la déclaration suivant la décision de rejet ».
  - Le sort d'un refus sur le fond est à vérifier.
- **Les seuils** (notice de la ligne 26 ; `page-remb.txt`) :
  - 760 € pour une demande mensuelle, de janvier à novembre, ou trimestrielle, pour les trois premiers trimestres ;
  - 150 € pour la demande annuelle, au titre de décembre ou du 4e trimestre.
- **Le geste réel.** La demande se fait dans l'espace professionnel : « Déclarer » > « TVA » > « Effectuez une demande de remboursement de crédit de TVA », formulaire 3519 (`faq-remb.txt`). Ce n'est donc pas une case de la CA3, comme le simplifie l'éditeur.
- **Les factures jointes.** Selon service-public (`sp-F23569.txt`), une demande trimestrielle joint les factures qui portent la TVA demandée. La liste des factures d'achat et des charges d'une période, avec leurs pièces, servirait donc aussi là.
- **Le délai.** Aucun délai de virement fixe n'est écrit. Le BOFiP rappelle que l'administration a six mois pour statuer sur une demande de remboursement de crédit d'impôt, et que son silence vaut rejet (`BOI-CTX-DG-20-50-10` § 155, LPF art. R*198-10). C'est à vérifier pour le crédit de TVA. Les « 2 à 4 semaines » de l'éditeur sont un ordre de grandeur, pas une règle.

---

## (b) Ce que le code sait dire de chaque euro de TVA

### b.1 Par famille

**TVA collectée des ventes (tickets, factures).**
- *Ce que la base garde* :
  - par document : magasin, numéro, jour d'émission au calendrier du magasin, totaux HT, TVA et TTC, type de client, client figé (nom, SIREN, n° TVA), nature d'opération et sa mention, consultation VIES ;
  - l'émetteur figé : franchise et option pour les débits dans le contenu signé ;
  - par ligne : bien ou service, produit ou service, taux, HT, TVA et TTC.
- *Ce que PILIQI montre* :
  - le total du mois, net des avoirs (carte, graphique, page des charges) ;
  - « Ventes par taux de TVA » en HT seulement (`SeriesDesGraphiques.php:274-285`) ;
  - le détail par taux d'un document (PDF) et d'une journée (clôture).
- *Ce qu'il ne montre nulle part* :
  - la TVA collectée par taux, par magasin côte à côte, par nature d'opération, et entre particuliers et professionnels, sur un mois ;
  - les avoirs à part ;
  - la TVA des services selon leur encaissement.

**Avoirs clients.**
- *Ce que la base garde* : chaque avoir, ses lignes liées aux lignes vendues, son jour d'émission et le motif d'une régularisation (exonération non prouvée, correction du SIREN).
- *Ce que PILIQI montre* : il les retire de la collectée du mois de leur émission (`TvaDeLaPeriode.php:72`).
- *Ce qu'il ne montre nulle part* : la TVA des avoirs du mois, seule.

**Ventes sans TVA française** (export, DOM, livraison intracommunautaire, prestations autoliquidées par le client, franchise).
- *Ce que la base garde* : la nature d'opération, la mention, la preuve de sortie (MRN ou CN23) et la régularisation.
- *Ce que PILIQI montre* : la vente à 0 % sur ses documents, et l'« À faire » des preuves.
- *Ce qu'il ne montre nulle part* : les bases exonérées du mois, que la CA3 déclare par ligne.

**Autoliquidation d'une charge** (fournisseur établi hors de France).
- *Ce que la base garde* : `fournisseur_hors_france` et la TVA de chaque ligne. Elle est due (`ChargesDuTableauDeBord.php:82`) et déductible selon la proposition.
- *Ce que PILIQI montre* : « dont X € autoliquidés » sous la collectée (`page-des-charges.blade.php:142`).
- *Ce qu'il ne montre nulle part* :
  - l'autoliquidation des **factures d'achat** de marchandises, qui n'existe pas ;
  - la phrase de la fenêtre « sans effet sur la TVA à payer » (`PageDesCharges.php:837`) est fausse quand la déduction n'est pas totale. Le semis TVA27 en a justement un cas : la location d'une voiture de direction à un loueur belge, dont la TVA due n'est pas déductible.

**TVA déductible des factures d'achat** (marchandises, société « Commerce ou atelier »).
- *Ce que la base garde* :
  - par facture : n° PILIQI, n° du fournisseur, fournisseur (nom, SIRET, n° TVA, pays), date de la facture, sinon jour de validation, origine (main, API, plateforme), escompte, pièces jointes avec leur empreinte, `piece_manquante` ;
  - par taux (`taux_factures_achat`) : base HT, TVA retenue, TVA calculée par PILIQI, TVA saisie, et l'écart confirmé (qui, quand) ;
  - `tva_a_verifier`.
- *Ce que PILIQI montre* : le total du mois (carte, graphique, page des charges), et la fiche « TVA par taux » de chaque facture.
- *Ce qu'il ne montre nulle part* :
  - la liste des factures qui font la TVA déductible d'un mois ;
  - la part de cette TVA « sans pièce » ou « à vérifier », qui compte pourtant dans la réelle.

**Avoirs fournisseur.**
- *Ce que la base garde* : TVA par taux, liens vers les lignes d'achat, n° du fournisseur, jour d'émission dans PILIQI, `tva_a_verifier` et `piece_manquante`.
- *Ce que PILIQI montre* : ils sont retirés de la déductible du mois de leur **émission dans PILIQI** (`AchatsDuTableauDeBord.php:79-86`).
- *Ce qu'il ne montre nulle part* : la date de la note d'avoir du fournisseur, qui n'a pas de colonne.

**TVA déductible des charges.**
- *Ce que la base garde* :
  - par charge : type (de la liste de PILIQI ou de la société) et sa nature (biens ou services), fournisseur, n° et date de la pièce, date de paiement, mois de TVA (fixé ou proposé), magasin ou « Toute la société », hors de France, véhicule utilitaire, état, origine, qui l'a validée et quand, renvoi avec le mot du comptable, corrections avec leur motif, pièce ;
  - par ligne : taux, HT, TVA, TTC, TVA déductible, « TVA à vérifier ».
- *Ce que PILIQI montre* :
  - le total du mois des validées, puis l'estimation avec les « à valider » ;
  - la colonne TVA déductible de la liste ;
  - l'état des charges en PDF ou Excel, avec la TVA par mois.
- *Ce qu'il ne montre nulle part* :
  - la TVA **non déductible** d'une charge (TVA − TVA déductible) et sa raison. La raison n'est écrite que dans la fenêtre, mais elle se recalcule depuis le type et le véhicule (`PropositionsDeTva::raisonDeLaDeduction`) ;
  - si le comptable a changé la proposition. On le sait en recalculant la proposition et en la comparant au montant retenu, qui seul est gardé ;
  - la TVA déductible par type de charge sur un mois.

**Le mois de TVA de chaque euro.**
- Ventes et avoirs : le jour de leur émission dans le magasin, services compris.
- Factures d'achat : la date de la facture du fournisseur.
- Avoirs fournisseur : le jour de leur émission dans PILIQI.
- Charges : `mois_tva`. PILIQI propose le mois de la pièce pour des biens, et celui du paiement pour des services, « à confirmer au paiement » tant qu'il manque (`PropositionsDeTva.php:77-91`).
- Ce mois n'est nulle part écrit en face de chaque euro. Les ventes de services ne suivent jamais leur encaissement.

**Réelle contre estimation.**
- Réelle = collectée (avec l'autoliquidation des charges validées) − déductible des achats et des charges validées.
- Estimation = la réelle + l'autoliquidation des charges à valider − la TVA déductible des charges à valider (`MontantsDeTva.php:41-53`).
- Hors de l'une comme de l'autre : les charges « pièce sans chiffres » et « renvoyées » (`ChargesDuTableauDeBord.php:62`).
- PILIQI montre « TVA à payer (réelle) » et « (estimation) » sur la page des charges, et l'estimation en pointillés sur le graphique. Mais « réelle » ne veut dire que « avec les seules charges validées » : ce n'est pas la TVA déclarée. Le mot peut tromper.

**Crédit de TVA (TVA27).**
- *Ce que la base garde* : `credits_de_tva`, une ligne par société, cadence et période. Elle porte le solde (≤ 0), qui l'a saisi, comme quoi et quand, qui l'a vérifié et quand, et le motif d'une correction (migration `2026_10_15_100005_create_credits_de_tva_table.php:28-59`).
- *Ce que PILIQI calcule à chaque affichage* : par période, la TVA, le crédit reporté, la TVA à payer et le crédit à reporter, en réelle et en estimation ; le statut (sans crédit déclaré, déclarée, avec crédit déclaré) ; l'état (vérifié, à vérifier, non vérifié).
- *Ce que PILIQI montre* : la carte avec la période et les pastilles, le graphique, « Voir les chiffres », le tableau du report et le bloc du crédit de la page des charges, le message de la TVA et l'« À faire ».
- *Ce qu'il ne montre nulle part* : les remboursements (demande, montant, date, virement reçu, refus) et le retour d'une part refusée.

### b.2 Les statuts que PILIQI connaît déjà

Une page TVA peut afficher ces statuts sans rien inventer.

**Factures d'achat et avoirs fournisseur.**

| Statut déjà en base ou recalculable | Compte-t-il dans la TVA ? | Source |
|---|---|---|
| Facture d'achat brouillon | non | `AchatsDuTableauDeBord.php:62` |
| Facture d'achat validée, pièce jointe | réelle | idem |
| Facture d'achat validée, « pièce du fournisseur manquante » | **réelle, aujourd'hui** | `PiecesDuFournisseur.php:204-230` |
| Facture d'achat ou avoir fournisseur « TVA à vérifier » (écart confirmé, par qui, quand) | réelle | `TvaDuFournisseur.php:21-47` ; `TauxFactureAchat` (`ecart_confirme_*`) |
| Avoir fournisseur émis | retire de la déductible | `AchatsDuTableauDeBord.php:79-86` |

**Charges.**

| Statut déjà en base ou recalculable | Compte-t-il dans la TVA ? | Source |
|---|---|---|
| « Pièce sans chiffres » | non | `EtatDeLaCharge.php:17` |
| « À valider » | estimation seulement | `MontantsDeTva.php:50-53` |
| « Validée par le cabinet », « par l'expert-comptable » ou « par le propriétaire » | réelle | `ValideePar.php` |
| « Renvoyée au client » (avec le mot du comptable) | **non, même en estimation** | `ChargesDuTableauDeBord.php:62` |
| Ligne « TVA à vérifier » | oui | `ChiffrageDUneCharge.php:77` |
| TVA déductible proposée inférieure à la TVA (véhicule de tourisme, hébergement, transport de personnes, cadeaux, carburant à 80 %), avec sa raison | la partie déductible seulement | `PropositionsDeTva.php:38-71` |
| Mois de TVA « à confirmer au paiement » | oui, au mois proposé | `PropositionsDeTva.php:84-91` |
| Charge autoliquidée | due et déductible | `ChargesDuTableauDeBord.php:82-83` |
| Charge corrigée après validation (nombre de corrections, motif) | la version en vigueur | `Charge::$corrections` |

**Ventes.**

| Statut déjà en base ou recalculable | Compte-t-il dans la TVA ? | Source |
|---|---|---|
| Vente exonérée, preuve manquante (orange jusqu'au 30e jour, rouge à partir du 31e), régularisée après 150 jours | 0 €, puis TVA française après régularisation | `ChosesAFaireDuMagasin.php:332, 424` |
| Ticket détaxé : bordereau noté, visa daté | sa TVA reste collectée | `ChosesAFaireDuMagasin.php:479` |

**Crédit (TVA27).**

| Statut déjà en base ou recalculable | Compte-t-il dans la TVA ? | Source |
|---|---|---|
| Crédit déclaré : vérifié, à vérifier ou non vérifié ; point de départ ou historique | sert de point de départ au report | `EtatDuCreditDeTva.php`, `CreditsDeTva.php:140-168` (TVA27) |

### b.3 Ce qui ne se montre nulle part aujourd'hui

Ces manques sont numérotés pour la maquette.

1. La liste, pour un mois ou une période, des **factures d'achat, avoirs fournisseur et charges** qui font la TVA déductible, avec leur état, leur pièce et un lien vers leur fiche.
2. La part de la TVA déductible qui compte **sans pièce** ou **à vérifier**.
3. La **TVA non déductible** des charges et sa raison, ainsi que les corrections du comptable par rapport à la proposition.
4. La TVA collectée **par taux**, par **magasin**, par **nature d'opération** (bases exonérées) et entre **particuliers et professionnels**, avec les avoirs à part.
5. Le **mois de TVA** de chaque euro, avec la règle qui l'a fixé.
6. Les **remboursements** de crédit (demande, état, virement, refus) et la chaîne recalculée.
7. Un texte qui **explique** la TVA : compensation, crédit, report, remboursement, exigibilité, conditions de déduction. Il n'existe que par bribes, dans des info-bulles et des phrases grises.
8. La **période en cours et son échéance** de déclaration. PILIQI ne connaît pas la date limite de dépôt, qui dépend du SIREN et du régime.

---

## (c) Qui voit quoi, aujourd'hui et dans une page dédiée

### c.1 Les règles de visibilité qui s'appliquent

- **`CLAUDE.md:158`, les lectures hors du magasin ou de la société courants.** Chacune doit être explicite, filtrée, limitée aux champs nécessaires et testée. Les exceptions déjà déclarées qui couvrent la TVA sont :
  - les chiffres clés et les graphiques du tableau de bord, **en montants agrégés**, « sans aucun nom de client hors des « Meilleurs clients » » ;
  - les charges de la société, avec leurs pièces ;
  - (TVA27) les déclarations du crédit de TVA ;
  - pour le téléchargement par période, « les documents des magasins de la portée de la personne qu'elle peut voir ».

  Une page qui **liste des documents de plusieurs magasins** (factures d'achat, avoirs fournisseur) dans l'espace de la société, du cabinet ou de l'expert-comptable est une **lecture nouvelle**. Elle doit être déclarée dans `CLAUDE.md:158` dans le même commit, sur le modèle du téléchargement.
- **`CLAUDE.md:159`, le panneau d'un magasin.** Aucun écran ni aucun message ne laisse deviner qu'il existe d'autres magasins ou d'autres sociétés.
  - TVA27 garde le crédit hors du magasin (`PageDesCharges.php:614`, TVA27).
  - Mais sa phrase `SANS_LE_REPORT` (« …qui ne se calcule que sur toute la société, tous ses magasins et ses charges compris… », `TextesDuCreditDeTva.php:29`, TVA27) s'affiche aussi dans le panneau d'un magasin. Elle ne nomme ni ne compte rien, mais une page TVA de magasin devrait dire « toute la société » sans « tous ses magasins ».
- **`CLAUDE.md:180`, les graphiques.**
  - Ils se montrent au propriétaire, à l'administrateur et au responsable à qui il coche la case, au cabinet lié et à l'expert-comptable déclaré, **jamais à un vendeur ni à un observateur**.
  - Une TVA ne compte que les magasins où la personne voit toutes les ventes (factures, celles de toute l'équipe et avoirs) et, dans une société qui a des articles, tous les achats (`app/Domain/TableauDeBord/Enums/SourceDesGraphiques.php:62-73`, `Graphique.php:149`).
  - Une phrase grise dit les magasins non comptés, sans les nommer.
- **`CLAUDE.md:182`, les charges.**
  - Les vendeurs et les observateurs n'ont rien.
  - Un responsable ne voit jamais les charges de « Toute la société » ni celles d'un autre magasin.
  - Les charges de « Toute la société » n'entrent dans une TVA que si elle compte tous les magasins de la personne (`Perimetre.php:63-100`).
  - (TVA27) Le report du crédit ne se montre que sur une TVA qui compte toute la société, et le montant du crédit seulement à qui peut l'indiquer ou voit cette TVA (`MessagesDuCreditDeTva.php:58-87`, TVA27).
- **`CLAUDE.md:179`.** « Aucun menu n'affiche de nombre ». Le tableau de bord est le seul endroit où se voit ce qui reste à faire. Une page TVA peut donc montrer des statuts, mais pas un second « À faire » avec des nombres, ni un nombre dans son entrée de menu.
- **`CLAUDE.md:185`.** Tout numéro de document affiché ouvre sa fiche pour qui peut la voir ; les autres lisent le numéro, sans lien. Chaque écran se lit en mode clair comme en mode sombre, avec le contraste AA.

### c.2 Espace de la société (panneau `societe`)

L'accès demande de pouvoir administrer la société (`PerimetreDesGraphiques.php:66-76`). Le responsable, le vendeur, l'observateur, l'expert-comptable déclaré et les comptes d'un cabinet n'y entrent jamais.

**Propriétaire.**
- *Aujourd'hui* : tout, sur tous ses magasins ou sur un seul (filtre).
  - La carte, le graphique et « Voir les chiffres », avec les charges ; les charges de « Toute la société » seulement sur « Tous mes magasins ».
  - Sur la page Charges : la liste et « La TVA du mois ».
  - Les paramètres fiscaux : le régime et l'option pour les débits.
  - Le CSV du téléchargement.
  - (TVA27) Le report, le bloc « Votre crédit de TVA » (déclarer, modifier), le message, l'étape et l'« À faire ».
- *Ce qu'une page TVA peut lui montrer* :
  - tout, y compris le crédit et les remboursements, qu'il **note**, comme il indique le crédit ;
  - le détail des factures d'achat, des avoirs fournisseur et des charges, avec le nom du fournisseur. C'est une lecture nouvelle à déclarer pour les achats ;
  - les ventes **en agrégats** (par magasin, taux, nature d'opération, particuliers ou professionnels), avec un lien vers les listes du magasin, sans nom de client.

**Administrateur à qui le propriétaire coche « Tableau de bord : voir les graphiques ».**
- *Aujourd'hui* : la carte et le graphique sur les magasins de sa portée où la case est cochée et où il voit toutes les ventes (et tous les achats). Une phrase grise dit les autres. Les charges n'y entrent que s'il a aussi « Charges : ajouter ».
- (TVA27) Il voit le report et le montant du crédit seulement si sa TVA compte tous les magasins et les charges de « Toute la société ». Il ne déclare jamais.
- *Ce qu'une page TVA peut lui montrer* : la même portée que ses graphiques et ses charges. Le crédit et les remboursements en lecture, seulement sur une TVA de toute la société. Il ne note jamais un remboursement, par analogie avec TVA27 : « jamais un administrateur ».

**Administrateur qui a « Charges : ajouter », sans les graphiques.**
- *Aujourd'hui* : la TVA déductible de ses seules charges, sur la page Charges. Le texte le dit : « Les seules charges que vous voyez, sans les ventes ni les achats » (`TvaDeLaPeriode.php:191-199`, `page-des-charges.blade.php:126, 161-166`).
- *Ce qu'une page TVA peut lui montrer* : ses charges seulement, sans les ventes ni les achats.

### c.3 Panneau d'un magasin (panneau `app`)

**Propriétaire.**
- *Aujourd'hui* : le widget « Graphiques du magasin » (`app/Filament/App/Widgets/GraphiquesDuMagasin.php:26-31`). Il montre la TVA de ce magasin, avec ses charges, jamais celles de « Toute la société ». Il voit aussi la fiche de chaque document (TVA par taux), la marque et le filtre « TVA à vérifier » des achats, les clôtures par taux et l'e-reporting. (TVA27) « Sans le report du crédit ».
- *Ce qu'une page « TVA du magasin » peut lui montrer* : la TVA de **ce magasin seulement** et ses documents, chaque numéro ouvrant sa fiche. Jamais le crédit, le report ni les remboursements, qui sont des données de la société. Jamais les charges de « Toute la société ». Aucune phrase qui laisse deviner d'autres magasins.

**Administrateur.**
- *Aujourd'hui* : le widget si la case est cochée dans ce magasin, et les documents selon ses cases.
- *Ce qu'une page TVA du magasin peut lui montrer* : la même chose, dans le même périmètre.

**Responsable.**
- *Aujourd'hui* : le widget si le propriétaire coche la case. « Charges du magasin » s'il a « Charges : ajouter » : la TVA de son tableau de bord s'il voit les graphiques, sinon celle de ses charges. Tous les documents de son magasin.
- *Ce qu'une page TVA du magasin peut lui montrer* : la même chose. Jamais « Toute la société ».

**Vendeur et observateur.**
- *Aujourd'hui* : ni graphiques (la base refuse la case), ni charges. Seulement la TVA des documents qu'ils peuvent ouvrir (fiche, PDF) et, s'ils y sont autorisés, la fiche des clôtures.
- *Page TVA* : **aucune**, et pas d'entrée de menu.

**Compte d'un cabinet lié qui ouvre le magasin.**
- *Aujourd'hui* : le widget, sans case, et les documents selon les consultations que le client accorde.
- *Ce qu'une page TVA du magasin peut lui montrer* : la même chose, dans la limite des consultations accordées.

**Expert-comptable déclaré.**
- *Aujourd'hui* : comme le cabinet, et en plus « Tableau de bord de la société » (89), « Charges de la société » (90) et « La société » (90, avec le régime en lecture).
- *Ce qu'une page TVA peut lui montrer* : voir c.5.

### c.4 Espace du cabinet (panneau `cabinet`)

Pour le propriétaire du cabinet et pour le collaborateur à qui le client est confié, tant que le cabinet est vérifié et actif.

- *Aujourd'hui* :
  - **Tableau de bord du cabinet**, par client : les factures à encaisser et la colonne « Charges » (« N à valider »). (TVA27) « Crédit de TVA de départ à vérifier », le seul fait, sans montant.
  - Une phrase y dit : « Les écritures, l'export comptable, l'état de TVA et les rapports de chaque client s'afficheront ici avec les prochaines étapes » (`tableau-de-bord-du-cabinet.blade.php:100`).
  - **Page d'un client**, onglet « Graphiques » : la carte et le graphique sur les magasins que le client ouvre, selon les consultations (`PerimetreDesGraphiques.php:136-152`).
  - Onglet « Charges », si le client accorde « Charges : voir, ajouter, saisir et valider » : la liste et « La TVA du mois » (`ClientDuCabinetResource.php:149-155`). (TVA27) Le bloc du crédit, avec confirmer, corriger et déclarer.
- *Ce qu'une page TVA peut leur montrer* : un onglet « TVA » par client.
  - Il couvre les magasins ouverts, avec les consultations accordées : la TVA ne compte un magasin que si le client y accorde les ventes et les achats.
  - Les charges, le crédit et les remboursements y figurent si le client accorde « Charges : voir, ajouter, saisir et valider ». Le comptable **confirme ou corrige** un remboursement, comme il le fait pour le crédit.
  - La liste des documents de plusieurs magasins est une lecture nouvelle à déclarer.
  - Jamais un autre client, ni le journal d'audit, ni les adresses IP et les navigateurs.
  - Au tableau de bord du cabinet : seulement le **fait** qu'un remboursement attend d'être confirmé, sans montant, comme pour le crédit dans TVA27.

### c.5 Page « Tableau de bord de la société » de l'expert-comptable déclaré

Elle s'ouvre dans le panneau d'un magasin de sa déclaration.

- *Aujourd'hui* : la carte et le graphique sur les magasins de sa déclaration (`PerimetreDesGraphiques.php:178-191`), « Charges de la société » et « La société ». (TVA27) Le bloc du crédit, le message et « Crédit de TVA de départ à vérifier ».
- *Ce qu'une page TVA peut lui montrer* : une page « TVA de la société » à côté de ces pages (sort 89 ou 90), avec les mêmes règles que le cabinet.

---

## (d) Les limites honnêtes de PILIQI sur la TVA aujourd'hui

1. **Ce n'est pas une CA3.**
   - PILIQI ne remplit aucune ligne de la CA3 : bases par taux, opérations exonérées, acquisitions intracommunautaires, ligne 19 « immobilisations » contre ligne 20 « autres biens et services », régularisations de la ligne 21, report de la ligne 22, remboursement de la ligne 26, crédit à reporter de la ligne 27.
   - Il ne dépose rien, ne paie rien et ne connaît pas l'échéance de dépôt.
   - L'état de TVA officiel vient avec la comptabilité, à l'étape 5 (`CLAUDE.md:210`).
2. **L'exigibilité n'est pas exacte.**
   - Les ventes de services comptent au jour de la facture (`app/Domain/TableauDeBord/Services/VentesDuTableauDeBord.php:102-133`), même sans l'option pour les débits, alors que leur TVA est due à l'encaissement (`sp-F23569.txt`). L'encaissement des services par jour et par taux existe pourtant déjà pour l'e-reporting des tickets (`app/Domain/Facturation/Services/EncaissementsDesPrestations.php`), sans être repris par la TVA.
   - Un achat compte à la date de la facture : c'est bon pour des biens.
   - Un avoir fournisseur compte au jour de son émission dans PILIQI, pas à la date de la note du fournisseur.
   - Une charge compte au mois de TVA que propose PILIQI et que le comptable fixe.
3. **Une déduction sans justificatif compte.** Une facture d'achat validée sans pièce, ou marquée « TVA à vérifier », compte dans la TVA « réelle ». Une charge « à valider » sans pièce compte dans l'estimation.
4. **PILIQI ne lit pas les pièces.** Il ne vérifie ni la raison sociale ni l'adresse de la société sur la facture, ni les mentions, ni le n° de TVA du fournisseur. Il ne distingue pas un ticket d'une facture et ne connaît pas le seuil de 150 € HT, dont la règle est à vérifier.
5. **Le lien avec l'activité est invérifiable.** PILIQI ne fait que proposer 0 % ou 80 % par type de charge, puis compter sur la validation du comptable. La TVA d'une dépense personnelle saisie comme charge se déduirait.
6. **Ce que PILIQI ne gère pas :**
   - le coefficient de déduction d'une activité en partie exonérée : aucun prorata dans le code. PILIQI ne fait qu'avertir à l'inscription qu'une activité est exonérée ;
   - les seuils de la franchise en base, qu'il ne suit pas. Seul le seuil de 1 100 000 € du trimestriel est suivi (TVA27) ;
   - un fournisseur « en franchise », qu'aucune fiche ne marque ;
   - la ligne d'une charge, qui part toujours à 20 % (`PageDesCharges.php:559`).
7. **Pas d'autoliquidation des achats de marchandises.** Ni acquisition intracommunautaire, ni importation. Seule une charge « Fournisseur établi hors de France » est autoliquidée. TVA27 le dit pour le seuil : PILIQI « ne voit ni les acquisitions taxables ni les ventes faites hors de PILIQI ».
8. **La détaxe n'est pas régularisée.** La TVA d'un ticket détaxé reste collectée après le visa. Le remboursement au voyageur est une opération de caisse de l'étape 3.
9. **Tout ce qui se passe hors de PILIQI manque** : ventes, achats, immobilisations, régularisations. La propre TVA d'un cabinet d'expertise comptable n'y est pas non plus, puisqu'il n'a ni magasin, ni ventes, ni charges dans PILIQI.
10. **Les limites du crédit (TVA27) :**
    - la chaîne part de la période d'arrivée, et le sort de cette période, commencée avant PILIQI, attend la décision de l'éditeur (`CreditsDeTva::premierePeriode()`, `CreditsDeTva.php:87-90`, TVA27) ;
    - seule la déclaration la plus récente sert de point de départ ;
    - **aucun remboursement n'est noté**, ni le retour d'une part refusée ;
    - le crédit transféré à une tête de groupe ne se présente pas : l'assujetti unique est refusé.
11. **Les arrondis.** Les sommes sont celles des documents, en centimes. Elles peuvent s'écarter de « TTC ÷ (1 + taux) » d'un demi-centime au plus par document et par taux (`CLAUDE.md:175`).
12. **Les mots.** « TVA à payer (réelle) » ne veut dire que « avec les charges validées », pas « déclarée ». La page devrait le dire autrement, par exemple « calculée avec les charges validées ».

---

## Annexe A : les données semées utiles pour la maquette (TVA27)

Ces cas viennent de `database/seeders/Semis/TvaDesSocietes.php` (TVA27, en-tête).

**Le crédit de départ.**
- **Maison Ferrand** (commerce, mensuel) : −1 250,00 € saisis par le propriétaire, « à vérifier » par son comptable. Son report enchaîne deux mois en crédit, puis un mois qui l'épuise en partie, puis un qui l'épuise tout à fait.
- **Épicerie Lemoine** (trimestriel) : −480,00 € confirmés par Emma Rousseau. Un trimestre en crédit est reporté sur le suivant.
- **Conseil Vasseur** (services, trimestriel) : −320,00 € corrigés à −350,00 € par Marc Petit, son expert-comptable déclaré, avec un motif, puis une déclaration plus récente née vérifiée.
- **Comptoir des Îles** (sans comptable) : −1 250,00 € « non vérifiés par un comptable ».
- **Studio Lumière** : « Non, aucun crédit ».
- **Groupe Nord Distribution** : pas encore de réponse.

**Les autres cas.**
- Une charge autoliquidée non déductible : la location d'une voiture de direction à un loueur établi en Belgique.
- Les cas A et B de l'éditeur se rejouent tels quels : une vente en France et une livraison vers un DOM, contre un achat à 20 %.

## Annexe B : les points ouverts pour la tâche 2 et pour l'éditeur

1. **Le remboursement quitte la chaîne à la demande**, à la période de la déclaration qui le porte (ligne 26), et non au virement. Le virement reçu ne fait que clore la demande. Une part refusée « en la forme » revient à la période qui suit la décision. Le refus sur le fond est à vérifier.
2. **Les états possibles d'un remboursement** : demandé (période, montant, date de dépôt) ; reçu (date, montant reçu) ; reçu en partie ; refusé (date, motif) ; sans réponse depuis plus de six mois, le délai étant à vérifier. Il faut aussi les seuils de 760 € et de 150 €, avec un avertissement sans refus. Il faut enfin dire qui note et qui vérifie : le propriétaire et le comptable, comme pour le crédit (`CreditsDeTva::peutDeclarer` et `peutVerifier`, TVA27) ; jamais un administrateur, un responsable, un vendeur ni un observateur.
3. **Une facture d'achat validée sans pièce** doit-elle sortir de la TVA « réelle » tant que sa pièce manque, comme une charge ? Aujourd'hui, elle compte. C'est une décision à prendre (D76, puis l'onglet « À faire vérifier par un professionnel »).
4. **L'exigibilité des ventes de services** : la page dit-elle seulement « comptée à la facture », ou reprend-elle déjà l'encaissement de l'e-reporting ?
5. **La place dans les menus** :
   - « TVA » dans l'espace de la société, près de « Charges » ;
   - un onglet « TVA » par client dans l'espace du cabinet ;
   - « TVA de la société » pour l'expert-comptable déclaré ;
   - « TVA du magasin » seulement pour qui voit les graphiques de ce magasin ;
   - aucune entrée pour un vendeur ni un observateur ;
   - aucun nombre dans le menu.
6. **Les lectures nouvelles à déclarer dans `CLAUDE.md:158`** : la liste des documents d'achat de plusieurs magasins dans l'espace de la société, du cabinet et de l'expert-comptable. Les remboursements seront des données de la société (`BelongsToSociete`), comme `credits_de_tva`.
7. **Les textes à corriger** au passage : la phrase de l'autoliquidation « sans effet sur la TVA à payer » (`PageDesCharges.php:837`), fausse pour une TVA non déductible ; la phrase `SANS_LE_REPORT` (« tous ses magasins ») dans le panneau d'un magasin ; la phrase grise `SANS_LES_REMBOURSEMENTS`, qui deviendra fausse dès que les remboursements se noteront.
