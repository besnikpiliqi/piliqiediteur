# Série 8 : écrans de vente, caisse, PDF et tableau de bord

Points traités : 1, 2, 3, 4, 5, 12, 13, 16, 22, 23 et 25 de la demande du 17/10/2026, et le point 11 (mention légale d'une vente sans TVA, réponse de l'éditeur « Sur la facture, automatique »).

Code étudié : la copie de travail du banc (`/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc`, copie de HEAD 30844db). Les chemins ci-dessous partent de la racine de cette copie. Le dépôt a avancé depuis à 8a22d55 (relecture du lot A4) : j'ai vérifié par `git diff --name-only 30844db..HEAD` qu'aucun fichier cité ici n'a changé, sauf `app/Models/User.php`, dont les numéros de ligne glissent de quelques lignes (les méthodes sont citées par leur nom). Rien n'a été modifié dans le dépôt `/home/user/piliqiediteur`, et PostgreSQL n'a pas été utilisé.

Essais exécutés dans le banc, sous SQLite (tests jetables, à supprimer avec le banc) :
- `tests/Feature/Scratch/Serie8EcransDeVenteTest.php` (7 tests verts) : PDF d'une facture et d'un ticket, avoir du client comptoir, facture rouverte par une contre-passation, widget de bienvenue, saisie des lignes à la caisse, crédit disponible face à une facture rouverte ;
- `tests/Feature/Scratch/Serie8PrototypePdfTest.php` (2 tests verts) : maquette de la mise en page proposée, rendue par le dompdf du dépôt (1 ligne, puis 60 lignes sur 4 pages).

Commande : `php artisan test --compact tests/Feature/Scratch/<fichier>` dans le banc. Sorties : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/essais-ecrans/` (`sortie.txt`, `facture-pro.pdf`, `ticket.pdf`, `prototype-1-ligne.pdf`, `prototype-60-lignes.pdf`, `prototype-60-lignes-numerote.pdf`), mesurées avec `pdfinfo` et `pdftotext -layout` / `-bbox`.

Documents voisins de la série 8, dont ce livrable reprend les conclusions sans les refaire : `serie8/arrondi-et-calculs.md` (point 15, proposition d'un lot « R6 : politique d'arrondi unique ») et `serie8/caisse-clotures-jet-ereporting.md` (points 14, 21, 24, 25, 26, changements C1 à C6).

---

## 0. L'essentiel

| Point | Constat vérifié | Proposition | Lot |
|---|---|---|---|
| 1 | « Avoir » n'est pas un mode de paiement ; le bon d'avoir se saisit à part ; le crédit d'un client nommé est déduit sans être montré comme un choix. Sur une facture rouverte, un crédit disponible ne peut pas régler la facture seul (essai). | « Avoir » apparaît dans la liste des modes, mais s'enregistre comme une imputation de crédit, jamais comme un paiement ni un encaissement. | S8-R (règlements), après A6 |
| 23 | La caisse n'accepte qu'un mode par vente. Une facture peut déjà recevoir plusieurs encaissements, mais en plusieurs gestes. | Un règlement en plusieurs parts (mode, montant, référence), en un seul geste, à la caisse et sur une facture. Chaque part est un paiement signé. Le format signé ne change pas. | S8-R |
| 2 | L'écran d'avoir part du client. Avec le client comptoir, toutes les lignes de tous ses tickets sont proposées d'office (essai : 2 tickets, 2 lignes). La liste propose aussi des clients sans achat. | La recherche se fait par le seul numéro du ticket ou de la facture. Le client s'affiche d'après le document. On peut ajouter d'autres numéros du même client. | S8-A (retours), après A6, avant M4 |
| 3 | Le PDF n'a pas de référence. Il a huit colonnes et seul « Payé » (sans mode) ou « Crédité par avoir ». | Colonnes Référence (une ligne, jamais coupée), Désignation (qui passe à la ligne), Qté, PU HT, Remise s'il y en a, Taux, Total HT. Plus de PU TTC, de TVA ni de Total TTC par ligne. Un bloc « Règlements » par mode. | S8-P (PDF), après R6 |
| 4 | Le PDF est **déjà** une vraie page A4 (595,28 × 841,89 points). L'identité de la société est en tête. Les mentions suivent le contenu, vers le milieu de la page. | Un pied de page fixé en bas de chaque page : identité de la société, toutes les mentions, « Page x sur y ». Maquette rendue et mesurée. | S8-P |
| 11 | Les mentions d'exonération et de franchise s'impriment déjà d'office sur le PDF. Sur la fiche, seule la mention de la nature apparaît, pas celle de la franchise. | Aucun choix par ligne (réponse de l'éditeur). Le moteur décide et les mentions figées s'impriment. En plus, le motif de chaque taux à 0 % figure dans le tableau de TVA. | M2, M3, M4 ; S8-P pour le tableau |
| 12 | Sous chaque ligne : « Total TTC 50,00 € — 41,67 € HT + 8,33 € de TVA » (essai). | Retirer ce bloc. L'aide du taux inhabituel va sur le champ du taux. Les totaux du bas gagnent la ventilation par taux. | S8-L (lignes et sélecteurs), après A6 et R6 |
| 13 | « Ajouter un produit » ajoute une ligne vide avec une recherche de 50 résultats, triée par nom. | Un grand tableau des produits : colonnes au choix, 50 lignes puis « en voir plus », filtres, sélection multiple, totaux HT et TTC de la sélection calculés par le même aperçu que les lignes. | S8-L |
| 22 | Le client se cherche par le nom seul, trié par nom. | Une loupe ouvre le tableau des clients : recherche par nom, SIREN, SIRET, n° de TVA, e-mail, téléphone et ville, tri du plus récent au plus ancien. Le client comptoir reste choisi par défaut. | S8-L |
| 5 | Le tableau de bord affiche le widget de Filament : « Bonjour » puis le nom, sans rôle. | Un widget « Bonjour » qui dit le rôle dans le magasin ouvert (ou dans la société). | S8-T (tableau de bord), après A4b |
| 16 | Aucune alerte des factures à encaisser. Une facture rouverte par une contre-passation reste due, et rien ne la signale (essai). | Une alerte en tête du tableau de bord, selon la portée de chacun. Dans l'espace de la société, une ligne par magasin. Il faut ajouter une lecture hors du magasin à la liste de CLAUDE.md. | S8-T |
| 25 | Les numéros de la fiche de clôture ne sont pas des liens. | Le changement C3 de `caisse-clotures-jet-ereporting.md`, que je confirme. | « Série 8, clôtures », après A6 |

**Place dans la chaîne.** Aucun de ces points ne dépend du moteur de TVA. Ils peuvent donc tous passer avant le lot M4, qui ajoute ensuite son panneau aux écrans ainsi refaits (section 11). Seul le point 11 relève du moteur (M2, M3, M4).

---

## 1. Points 1 et 23 : « Avoir » comme mode de paiement, paiement en plusieurs modes

### 1.1 Ce qui existe

**Caisse** (`app/Filament/App/Pages/Caisse.php`) :
- Un seul champ « Mode de paiement » (`:257-262`). Ses choix viennent de `ModePaiement` : virement, chèque, espèces, carte bancaire, prélèvement (`app/Domain/Facturation/Enums/ModePaiement.php:9-13`).
- « Montant reçu » et « Monnaie à rendre » n'existent qu'en espèces (`:263-290`).
- Le bon d'avoir du client comptoir passe par une action à part, « Utiliser un bon d'avoir », qui demande son numéro (`:306-329`). Un seul bon par vente (`Hidden::make('bon_d_avoir')`, `:256`).
- Le crédit d'avoir d'un client nommé est déduit d'office du total à payer (`:560-569`), puis imputé à l'émission par `AppliquerAvoirsDisponiblesAction`.
- Essai : la caisse n'a qu'un `mode_paiement` (`sortie.txt`).

**Domaine** :
- `EnregistrerVenteCaisseAction::handle(User, Client, array $lignes, ModePaiement $modePaiement, ?string $reference, ?string $bonDAvoir)` (`app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php:75`) passe un `ReglementComptant` qui porte un mode, une référence et un bon (`app/Domain/Facturation/Services/ReglementComptant.php:22-26`).
- `EmettreFactureAction` impute le bon d'avoir, puis encaisse **tout** le reste en un seul paiement (`app/Domain/Facturation/Actions/EmettreFactureAction.php:201-209` et `:270-286`).

**Factures** :
- L'action « Encaisser » de la liste (`app/Filament/App/Resources/FactureResource.php:670-715`) prend un mode et un montant, au plus le restant dû.
- Plusieurs encaissements successifs sont déjà possibles. Essai : une facture professionnelle de 100,00 € réglée par 40,00 € en espèces puis 60,00 € par carte passe « payée », avec deux paiements (`sortie.txt`). Le paiement en plusieurs fois existe donc dans le domaine pour une facture, mais en deux gestes, et jamais à la caisse.

**Crédit sur une facture rouverte** :
- Essai : une facture de 10,00 € réglée par virement, puis rouverte par la contre-passation de ce virement, alors que le client a 10,00 € de crédit disponible.
- « Encaisser » ne peut pas régler la facture par ce crédit seul : `EnregistrerPaiementAction` refuse tout montant nul (« Le montant imputé sur une facture doit être strictement positif. », `app/Domain/Facturation/Actions/EnregistrerPaiementAction.php:98-99` et `:188`).
- Le complément 1 du plan TVA (lot C-a) corrigera ce cas en imputant le crédit dès la contre-passation (`tva/plan-execution.md`, 5.2).

**Contenu signé** : `ContenuFiscal` signe déjà, pour une vente, une **liste** `reglements` (un élément par imputation de paiement, avec son mode, sa référence et son montant) et une **liste** `credits_avoir` (`app/Domain/CaisseSecurisee/Services/ContenuFiscal.php:208-225`). Un ticket payé en deux modes se signe donc sans changer le format signé.

**Règles en vigueur** :
- Le montant d'un paiement égale le total qu'il impute (`EnregistrerPaiementAction.php:95-104`, CLAUDE.md « Paiements et avoirs »).
- Le crédit d'avoir s'impute automatiquement (CLAUDE.md, même puce).
- Le mode préféré du client est présélectionné (CLAUDE.md « Clients, produits, fournisseurs »).
- Le relevé « Fermer ma caisse » ventile déjà les encaissements par mode (`app/Domain/Facturation/Actions/FermerSaCaisseAction.php:191` et `:231-241`).

### 1.2 Écart

1. « Avoir » n'est pas un choix visible parmi les modes, ni à la caisse ni sur une facture.
2. Aucune vente ne se règle en plusieurs modes d'un seul geste.
3. Sur une facture, un crédit disponible ne règle pas seul ce qui reste dû (jusqu'au lot C-a).

### 1.3 Conception proposée

**« Avoir » est un choix d'écran, jamais un paiement.** `ModePaiement` reste tel quel. Le plan TVA le fige ainsi, sans carte cadeau ni bon d'achat (`tva/plan-execution.md`, 1.2). Le bon d'avoir n'est pas un bon d'achat. Choisir « Avoir » produit une imputation de crédit (`AvoirAllocation`), comme aujourd'hui. Il n'y a ni paiement, ni encaissement, ni ligne de moyen de paiement dans la clôture. La clôture le montre à part, comme « crédit d'avoir utilisé » (changement C2 de `caisse-clotures-jet-ereporting.md`). L'e-reporting ne le déclare jamais comme un encaissement.

**Caisse : bloc « Règlement »**
- **Total à payer** : comme aujourd'hui (`resteAPayer()`, `Caisse.php:538-547`).
- **Parts du règlement** : une liste de parts. Chaque part a un mode, un montant et une référence facultative (n° de transaction carte, n° de chèque).
  - La première part est préremplie avec le mode préféré du client (règle B8) et le montant du reste à payer.
  - « Ajouter un autre mode » ajoute une part préremplie avec ce qui reste.
  - Chaque mode n'apparaît qu'une fois, sauf « Avoir », qui prend une part par bon.
  - À la caisse, le choix « Prélèvement » n'est pas proposé.
- **Choix « Avoir »** :
  - Pour un client nommé, son crédit disponible s'affiche comme une part « Avoir (crédit du client) », remplie d'office et non retirable. La règle « imputé automatiquement » de CLAUDE.md reste donc vraie, et le choix est visible.
  - Pour un bon d'avoir (avoir du client comptoir, règle K6), la part « Avoir » demande le n° du bon. Son montant est le crédit restant du bon, dans la limite du reste à payer.
  - Plusieurs bons sont permis, imputés dans l'ordre saisi.
  - L'action « Utiliser un bon d'avoir » (`Caisse.php:306-339`) disparaît, remplacée par ce choix.
- **Espèces** : « Montant reçu » et « Monnaie à rendre » ne concernent que la part en espèces. La monnaie vaut le montant reçu moins cette part.
- **Validation à l'écran** : la somme des parts égale exactement le total à payer. Sinon, « Il reste X € à répartir » ou « Les parts dépassent le total de X € ».

**Domaine de la caisse**
- `ReglementComptant` devient une liste ordonnée de parts (mode, montant en centimes, référence) et une liste de bons d'avoir.
- `EnregistrerVenteCaisseAction` relit les bons sous le verrou du registre, comme aujourd'hui (`EnregistrerVenteCaisseAction.php:104-120`).
- `EmettreFactureAction` impute dans l'ordre : le crédit du client (écouteur actuel), puis les bons. Il relit ensuite le reste dû et vérifie que la somme des parts lui est égale. Sinon, il refuse sans rien enregistrer : « Le total à payer a changé (crédit d'avoir du client) : X € à répartir. » C'est le cas où le crédit du client est consommé par une autre caisse entre l'écran et la validation.
- Chaque part crée ensuite **un paiement** (`EnregistrerPaiementAction`), signé dans la chaîne des encaissements. Tout se fait dans la même transaction que l'émission : tout ou rien.
- Le ticket signé porte la liste `reglements` et la liste `credits_avoir`, déjà prévues (`ContenuFiscal.php:208-225`).
- **Demandes répétées (lot A6)** : le jeton de la caisse est celui du panier. Le contenu lié au jeton doit comprendre les parts et les bons. Le même jeton avec d'autres parts est donc refusé, comme le prévoit la règle R3 (« le même jeton avec une demande différente est refusé »). Les paiements intérieurs ne consomment aucun jeton (A6 : « seule l'action la plus externe consomme le jeton »).

**Factures : action « Encaisser »** (liste et fiche) :
- Même bloc de parts, sans « Monnaie à rendre ». La somme des parts est au plus égale au restant dû (un acompte reste permis), et la date de réception est commune.
- Une action extérieure, par exemple `EncaisserEnPlusieursModesAction`, consomme le jeton et appelle `EnregistrerPaiementAction` pour chaque part, dans une seule transaction.
- **Choix « Avoir »** : il affiche le crédit disponible du client et permet de l'imputer **seul**, sans part payée. Cela corrige le cas de l'essai. Après le lot C-a, ce cas ne se présentera presque plus, puisque la contre-passation imputera le crédit d'elle-même. Le choix reste alors l'endroit où le crédit se voit.
- **Bon d'avoir sur une facture créée depuis « Factures »** : la décision K6 dit « à une prochaine vente au comptoir ». L'étendre à une facture créée hors de la caisse ferait une imputation entre deux clients hors du comptoir. **Question Q3** (section 13). En attendant : à la caisse seulement.
- La page « Paiements > Créer » (un virement qui règle plusieurs factures) garde un seul mode : c'est un seul paiement reçu.

**Affichage** :
- La fiche d'une facture montre déjà chaque paiement avec son mode (`FactureResource.php:233-256`) et le crédit d'avoir avec le n° de l'avoir (`:258-277`). Rien à changer.
- Le message de fin de vente à la caisse (`Caisse.php:493-512`) énumère les parts : « 50,00 € en espèces, 30,00 € par carte bancaire, 20,00 € du bon d'avoir AV-… ».
- Le PDF : section 3.3.

### 1.4 Règles légales et de CLAUDE.md

- « Le montant d'un paiement est égal au total imputé » : tenu, puisque chaque part est un paiement qui impute exactement son montant.
- « Rien n'est jamais remboursé » : aucune part négative n'existe. La monnaie à rendre n'est pas un remboursement : elle vient d'un montant reçu supérieur à la part en espèces, comme aujourd'hui.
- Caisse sécurisée : chaque paiement est signé, et la vente signe la liste de ses règlements. La clôture compte chaque part dans son mode (C2 du document voisin, qui prévoit déjà le test `test_un_ticket_paye_en_deux_modes_compte_dans_chacun`).
- Texte proposé pour CLAUDE.md, puce « Paiements et avoirs », à placer après la première phrase : « Une vente se règle en un ou plusieurs modes (espèces, carte bancaire, chèque, virement, prélèvement), chaque part étant un paiement ; le crédit d'avoir du client et un bon d'avoir s'affichent comme le mode « Avoir », sans être ni un paiement ni un encaissement ; à la caisse, les parts et les crédits égalent exactement le total à payer, et seule la part en espèces rend la monnaie. »

### 1.5 Tests

`tests/Feature/Facturation/ReglementEnPlusieursModesTest.php` :
- `test_un_ticket_se_regle_moitie_en_especes_moitie_par_carte` : ticket de 80,00 €, parts de 40,00 € et 40,00 €. Résultat : deux paiements, ticket « payé », bloc signé `reglements` à deux éléments, vérificateur d'intégrité sans anomalie.
- `test_la_somme_des_parts_doit_egaler_le_reste_a_payer` : 79,99 € ou 80,01 € refusés, rien d'enregistré.
- `test_un_bon_d_avoir_et_deux_modes_reglent_un_meme_ticket` : bon de 20,00 €, puis 30,00 € en espèces et 30,00 € par carte.
- `test_le_credit_du_client_s_affiche_comme_avoir_et_s_impute_d_office`.
- `test_un_credit_consomme_entre_l_ecran_et_la_validation_refuse_la_vente_sans_rien_enregistrer`.
- `test_seule_la_part_en_especes_rend_la_monnaie`.
- `test_une_facture_s_encaisse_en_deux_modes_d_un_seul_geste`.
- `test_le_credit_disponible_regle_seul_une_facture_rouverte`.
- `test_un_vendeur_n_encaisse_en_plusieurs_modes_que_ses_factures` : règle « Vendeurs ».
- `test_la_meme_demande_rejouee_avec_le_meme_jeton_ne_cree_pas_de_second_paiement`.
- `test_le_meme_jeton_avec_d_autres_parts_est_refuse`.
- `test_un_paiement_par_avoir_n_est_jamais_compte_comme_encaissement`.

`tests/Feature/Filament/CaisseTest.php` (nouvelles méthodes) :
- `test_la_caisse_propose_le_mode_avoir_et_demande_le_numero_du_bon` ;
- `test_ajouter_un_autre_mode_preremplit_ce_qui_reste`.

Tests existants touchés : ceux qui appellent `EnregistrerVenteCaisseAction::handle()` avec un `ModePaiement`, dont la signature change, et ceux de l'action « Utiliser un bon d'avoir ». La liste est à dresser au début du lot par `grep -rn "EnregistrerVenteCaisseAction\|utiliserUnBonDAvoir" tests`. Ils sont réécrits en nommant la décision du point 23.

---

## 2. Point 2 : retour cherché par le numéro du ticket ou de la facture

### 2.1 Ce qui existe

`app/Filament/App/Resources/AvoirResource.php` (page « Avoirs > Créer ») :
- **Client** (`:84-101`) : la liste vient de `Client::scopeQuiPeuventEtreCredites()` (`app/Domain/Contacts/Models/Client.php:230-235`), c'est-à-dire **tous** les clients non archivés, plus les archivés qui ont des lignes à créditer. Essai : un « Client sans achat » est proposé.
- Choisir le client remplit d'office la série (tickets ou factures), quand il n'en a qu'une, et **toutes** les lignes encore créditables de **tous** ses documents (`:94-100`, `:222-263`).
- Essai : après deux tickets au comptoir, choisir « Client comptoir » choisit la série `ticket_caisse` et propose les deux lignes de TK-…-000001 et TK-…-000002 (`sortie.txt`). C'est ce que l'éditeur constate : on ne sait pas de quelle vente au comptoir il s'agit.
- L'autre chemin, l'action « Avoir » sur une ligne de la liste des factures (`FactureResource.php:719-764`), part déjà d'un document. Il n'existe ni sur la fiche d'une facture (`app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php:36-38`), ni à la caisse.
- Le domaine contrôle déjà que chaque ligne appartient au client donné (`app/Domain/Facturation/Actions/CreerAvoirAction.php:109-110`) et qu'un avoir ne mélange pas les types de client (`:119-122`).

### 2.2 Écart

La recherche part du client, propose des clients sans achat et préremplit au hasard des ventes du client comptoir. L'éditeur veut partir d'un numéro de document, seulement.

### 2.3 Conception proposée

**Écran « Nouvel avoir » (retour)**
- **Recherche** : un seul champ, « N° du ticket ou de la facture ». Il cherche sur le numéro, sans casse (« tk-lyon1-2026-12 » trouve TK-LYON1-2026-000012), parmi les documents émis que la personne voit (`Facture::scopeVisiblesPar()`, `app/Domain/Facturation/Models/Facture.php:246-253`) et qui ont encore une ligne à créditer (`LigneFacture::scopeCreditables()`, `app/Domain/Facturation/Models/LigneFacture.php:136-142`).
- **Document choisi** : l'écran affiche le client **d'après le document** (nom figé, `nomDuClient()`). Pour le comptoir : « Client comptoir — ticket TK-… du 17/10/2026 à 10 h 32, 10,00 € ». Il affiche aussi la date, le total et les lignes avec leur quantité encore créditable. Comme aujourd'hui, la personne retire des lignes ou réduit des quantités.
- **Plusieurs documents** : « Ajouter un autre ticket ou une autre facture » accepte un autre numéro. L'écran refuse un document d'un autre client (« Ce document est à un autre client : faites un autre avoir. ») et un mélange de tickets et de factures (message actuel). Au lot M4, il refusera aussi un document incompatible par la nature, la franchise ou le circuit (section 11).
- **Plus de champ « Client »** : le client envoyé à `EnregistrerAvoirAction` est celui du premier document, relu côté serveur. Le domaine garde son contrôle (`CreerAvoirAction.php:109-110`) : un identifiant forgé dans la requête est refusé.
- **Les autres chemins** : l'action « Avoir » de la liste reste. La même action s'ajoute sur la fiche d'une facture et d'un ticket, ainsi qu'un bouton « Retour d'un ticket » à la caisse, qui ouvre la même recherche par numéro. Le bouton de la caisse est une proposition, **question Q8**.
- **Droits** : inchangés (`avoir.creer`, puis `avoir.sur_ticket` pour un ticket au lot C-a). La recherche ne propose que ce que la personne voit. Un numéro d'un autre magasin est introuvable (`MagasinScope`). Un vendeur ne retrouve que ses ventes (règle B16).
- **Le crédit** : rien ne change (`AvoirResource::imputationDuCredit()`, `:152-157`). Pour le comptoir, l'avoir vaut bon d'avoir.

### 2.4 Tests

`tests/Feature/Filament/AvoirResourceTest.php` :
- `test_le_retour_se_cherche_par_le_numero_du_document_et_affiche_son_client` ;
- `test_avec_le_client_comptoir_seul_le_ticket_cherche_est_propose` (deux tickets au comptoir, un seul repris) ;
- `test_un_client_sans_achat_n_est_jamais_propose` ;
- `test_l_ecran_n_a_plus_de_champ_client` ;
- `test_ajouter_un_document_d_un_autre_client_est_refuse` ;
- `test_un_vendeur_ne_retrouve_que_ses_documents` ;
- `test_un_numero_d_un_autre_magasin_est_introuvable` (isolation) ;
- `test_un_document_entierement_credite_n_est_plus_propose` ;
- `test_un_client_forge_dans_la_requete_est_refuse`.

Tests existants touchés : ceux de `AvoirResourceTest` qui remplissent `client_id` sur la page de création, réécrits en nommant la décision du point 2. La liste est à dresser par `grep -n "client_id" tests/Feature/Filament/AvoirResourceTest.php`.

---

## 3. Points 3 et 4 : le PDF des ventes

### 3.1 Ce qui existe

**Format** :
- Le PDF est **déjà** une vraie page A4. Essai : `pdfinfo` donne « Page size: 595.28 x 841.89 pts (A4) », pour une facture d'une seule ligne comme pour un ticket, et le fichier porte `/MediaBox [0.000 0.000 595.280 841.890]`.
- `FacturePdfGenerator::generer()` appelle `Pdf::loadHTML()` sans format (`app/Domain/Facturation/Services/FacturePdfGenerator.php:28`). Le format par défaut du paquet est `a4` en portrait (`vendor/barryvdh/laravel-dompdf/config/dompdf.php:164` et `:173`).

**Place des éléments** :
- L'identité de la société est en tête, à gauche (`resources/views/documents/pdf/en-tete.blade.php:23-36`, lignes de `Emetteur::lignesDIdentite()`, `app/Domain/Facturation/Services/Emetteur.php:293-318`).
- Les mentions suivent le contenu, chacune avec 32 px de marge au-dessus (`resources/views/documents/pdf/styles.blade.php:22`, `resources/views/factures/pdf.blade.php:89-107`).
- Essai `pdftotext -bbox` : sur une facture d'une ligne, « Pénalités » est à y = 541 points et « Catégorie de l'opération » à y = 644, sur une page de 842. Le reste de la page est vide.
- Ce que l'éditeur voit comme une page « réduite » vient sans doute de là : tout le contenu tient dans le haut de la page. Son lecteur de PDF ou une impression « ajustée à la page » reste **à vérifier** avec lui.

**Colonnes** :
- Description, Qté, PU HT, PU TTC, Taux TVA, Remise (s'il y en a), Total HT, TVA, Total TTC (`resources/views/documents/pdf/lignes.blade.php:31-83`). Dans une vente sans TVA française, « Prix catalogue TTC » et « TVA retirée » remplacent le PU TTC.
- Aucune référence de produit, alors que chaque ligne en a une (`lignes_facture.reference`, reprise de la fiche par `AjouteDesLignesDeVente.php:217`).
- Essai : les colonnes de montants sont si étroites que « € » passe à la ligne (`pdftotext -layout facture-pro.pdf`, lignes 30-32 de la sortie).

**Règlements** :
- Seuls « Payé » (un montant, sans mode), « Crédité par avoir (AV-…) » et « Restant dû » s'impriment (`factures/pdf.blade.php:53-87`).
- Ces montants sont relus **au moment de l'impression** (`montantPaye()`, `montantCrediteParAvoir()`), et non dans le contenu signé.
- Essai : une facture réglée 40,00 € en espèces puis 60,00 € par carte n'imprime que « Payé 100,00 € ».

### 3.2 Écart

- Pas de référence.
- Trop de colonnes : PU TTC, TVA et Total TTC par ligne.
- Pas de mode de paiement.
- L'identité de la société et les mentions ne sont pas en bas de la page.
- Format A4 : déjà tenu, mais à verrouiller par un test.

### 3.3 Conception proposée

**Ce que la loi exige sur une facture, avant de retirer une colonne**

L'art. 242 nonies A de l'annexe II au CGI (la base des mentions, d'après `tva/reglementation.md`, ligne 44) demande, pour chaque bien ou service, la quantité, la dénomination précise, le prix unitaire hors taxe et le taux de TVA. Il demande ensuite, par taux, le total hors taxe et la taxe correspondante. Je cite ce contenu de mémoire : **le texte exact est à vérifier sur Légifrance**, inaccessible depuis cette session (`getaddrinfo ENOTFOUND www.legifrance.gouv.fr`, et refus du proxy pour `bofip.impots.gouv.fr`).

La norme de la facture électronique va dans le même sens. Le schematron européen de la série 8 (`serie8/sources/ubl-model.sch`) exige pour chaque ligne :
- une quantité (`BR-22`) ;
- un **montant net de ligne, c'est-à-dire le total HT** (`BR-24`, `LineExtensionAmount`) ;
- un nom (`BR-25`) ;
- un prix net (`BR-26`).

Il exige ensuite une base et une taxe par catégorie (`BR-45`, `BR-46`). La norme EN 16931 n'a pas de montant de TVA par ligne (**à vérifier** dans le texte de la norme ; le schematron n'en contient aucune règle).

Conclusion :
- **On garde** : désignation, quantité, PU HT, taux, total HT par ligne, et la TVA par taux en pied.
- **On retire** : le PU TTC, la TVA par ligne et le Total TTC par ligne. Ils sont déjà dans le tableau par taux et dans les totaux, comme le dit l'éditeur.
- Une facture Factur-X (étape 2) doit montrer les mêmes données que son XML : c'est une raison de plus de garder le total HT par ligne.

**Colonnes proposées**

| Document | Colonnes |
|---|---|
| Facture (professionnel, ou facture demandée par un particulier au lot C-b) et avoir sur facture | Référence · Désignation · Qté · PU HT · Remise (si une ligne en a) · Taux · Total HT ; un avoir garde « Facture d'origine » |
| Ticket de caisse et avoir sur ticket | Référence · Désignation · Qté · PU TTC · Remise (si une ligne en a) · Taux · Total TTC, puis le tableau de TVA par taux (base HT, TVA). Un ticket n'est pas une facture, et le client paie le prix affiché TTC. **Question Q1.** |
| Devis | Selon le type de client, comme la facture ou comme le ticket |
| Vente sans TVA française | Comme la facture, plus « Prix catalogue TTC » et « TVA retirée », qu'explique la note de calcul (`lignes.blade.php:11-17`) ; à revoir au lot M4 avec la note du moteur |

- **Référence** : colonne d'environ 38 mm, en `white-space: nowrap`. La désignation prend le reste de la largeur et passe à la ligne.
- **Montants** : `nowrap`, pour que « € » ne passe plus à la ligne.
- Essai `prototype-1-ligne.pdf` : la référence « CABLE-USBC-100W-2M-NOIR » tient sur une ligne et la longue désignation passe à la ligne.
- Dépendance au lot R6 de `arrondi-et-calculs.md` : selon la réponse de l'éditeur à Q15-a et Q15-b, le PU HT d'une ligne peut devenir indicatif et la note de calcul (`MentionsFiscales::calcul()`) changer. Les colonnes proposées restent les mêmes. Les montants imprimés restent ceux qui sont enregistrés (`prix_unitaire_ht`, `montant_ht`), jamais recalculés.

**Pied de page fixé en bas de chaque page**
- `@page { size: A4 portrait; margin: 14mm 14mm 48mm 14mm; }`, écrit dans la vue pour ne plus dépendre du réglage par défaut du paquet.
- Un bloc `#pied { position: fixed; bottom: -40mm; height: 36mm; }`, répété par dompdf sur chaque page. Il contient, dans l'ordre :
  1. l'identité de la société : dénomination (avec « EI » si besoin), forme, capital (« à capital variable » le cas échéant), siège (pas répété s'il est l'établissement, règle actuelle, `Emetteur.php:298` et `:308-312`), SIREN, immatriculation, n° de TVA ;
  2. toutes les mentions : catégorie de l'opération, option pour les débits, franchise ou exonération, et pour un professionnel les pénalités, l'indemnité de 40 € et l'escompte ;
  3. les conditions de paiement saisies ;
  4. « Page x sur y ».
- **En tête** reste l'établissement : nom du magasin, adresse, SIRET, téléphone, e-mail. C'est le magasin qui vend, et la règle « SIRET et adresse du magasin » de CLAUDE.md, puce « Documents de vente », le demande.
- `Emetteur::lignesDIdentite()` se scinde en `lignesDeLEtablissement()` et `lignesDeLaSociete()`. Le contenu signé (`Emetteur::enTableau()`) ne change pas : seule la présentation change.
- **Mesuré sur la maquette** (`Serie8PrototypePdfTest`) :
  - une ligne : une page A4, pied entre y = 719 et y = 763 points ;
  - 60 lignes : 4 pages A4, le même pied à la même hauteur sur chaque page, la dernière ligne de chaque page au plus bas à y = 674. Le pied ne recouvre donc jamais le contenu.
- **Numéro de page** : `counter(pages)` en CSS ne marche pas dans ce dompdf (essai : « Page 1 sur 0 »). Le dessin par le canevas après le rendu marche : `$dompdf->getCanvas()->page_text(470, 800, 'Page {PAGE_NUM} sur {PAGE_COUNT}', …)` donne « Page 1 sur 4 » à « Page 4 sur 4 » (`prototype-60-lignes-numerote.pdf`). Les trois générateurs (facture, avoir, devis) le font après `render()`.

**Bloc « Règlements »** (points 3 et 23)
- Une ligne par part, après les totaux : date, mode, référence et montant (« 17/10/2026 · Espèces · 40,00 € »). Puis une ligne par crédit d'avoir (« Avoir AV-… · 20,00 € »). Puis le restant dû et, s'il y en a, l'échéance.
- **Source.** CLAUDE.md (puce « Caisse et clôtures ») dit : « Le PDF reproduit le document tel qu'émis, une réimpression porte la mention « Duplicata », et « Payé / Restant dû » reste visible sur la fiche ». L'étape 3 prévoit des « justificatifs et duplicatas produits depuis le contenu signé ». Le PDF actuel relit pourtant les paiements à l'impression (`factures/pdf.blade.php:53-87`).
- **Proposition** :
  - le bloc lit les règlements **de l'émission** dans le contenu signé (`reglements`, `credits_avoir`, déjà signés, `ContenuFiscal.php:208-225`) : un ticket encaissé à la caisse imprime toutes ses parts ;
  - une facture payée plus tard imprime « À régler avant le … » ;
  - les paiements postérieurs restent sur la fiche, qui les montre déjà avec leur mode (`FactureResource.php:233-256`).
- **Question Q2** : imprimer aussi, sur une réimpression, une section datée « Situation des règlements au JJ/MM/AAAA », distincte du document émis. Les tests `FacturePdfGeneratorTest::test_le_credit_applique_par_un_avoir_apparait_sur_le_pdf` (`:383`), `test_un_avoir_impute_deux_fois_sur_la_facture_n_y_est_nomme_qu_une_fois` (`:434`), `test_l_avoir_dont_la_contre_passation_a_rendu_le_credit_n_est_plus_nomme` (`:457`) et `test_aucune_mention_de_reglement_sur_une_facture_non_payee` (`:478`) fixent la lecture à l'impression. Ils ne changent que sur la réponse de l'éditeur, en la nommant (protocole, règle 4).

**Ce qui ne change pas** : le filigrane et le bandeau de démonstration (`en-tete.blade.php:19-22`), la note de calcul sous les lignes, le tableau par taux, les totaux, la copie figée du client.

**Mise en production.** Aucune donnée réelle n'existe : changer la mise en page change aussi l'aspect d'une réimpression d'un document déjà émis, sans toucher à son contenu signé. Après la production, une nouvelle mise en page devra être versionnée (le document garde la mise en page de son émission). À noter pour l'étape 3.

### 3.4 Tests

`tests/Feature/Facturation/FacturePdfGeneratorTest.php` (nouvelles méthodes) :
- `test_le_pdf_est_une_page_a4_meme_avec_une_seule_ligne` : la sortie contient `/MediaBox [0.000 0.000 595.280 841.890]` et une seule page (`/Type /Page`). Aucun binaire externe : la suite de la CI n'a peut-être pas `pdftotext` (**à vérifier**).
- `test_la_reference_du_produit_est_imprimee_dans_sa_colonne_sans_retour_a_la_ligne` (le HTML porte la cellule de référence avec la classe `nowrap`).
- `test_une_facture_n_imprime_ni_pu_ttc_ni_tva_ni_total_ttc_par_ligne`.
- `test_un_ticket_imprime_le_pu_ttc_et_le_total_ttc_de_chaque_ligne` (selon Q1).
- `test_les_reglements_de_l_emission_s_impriment_par_mode` : ticket réglé par un bon de 20,00 €, 30,00 € en espèces et 30,00 € par carte, relus dans le contenu signé.
- `test_l_identite_de_la_societe_et_toutes_les_mentions_sont_dans_le_pied_de_page`.
- `test_le_pied_se_repete_sur_chaque_page_d_une_longue_facture` : 60 lignes, plusieurs pages, un seul bloc `#pied` fixé.
- `test_le_siege_n_est_pas_repete_quand_il_est_l_etablissement` (règle existante, déplacée dans le pied).

Mêmes ajouts pour `AvoirPdfGeneratorTest` et `DevisPdfGeneratorTest`. `EmetteurFigeTest` (`:502`, qui lit `lignesDIdentite()`) suit la scission des lignes, sans changer son assertion.

---

## 4. Point 11 : la mention légale d'une vente sans TVA

### 4.1 Ce qui existe

- Les mentions s'impriment déjà d'office : `MentionsFiscales::pour()` (`app/Domain/Facturation/Services/MentionsFiscales.php:59-78`) ajoute la mention de la nature (`NatureOperation::mention()`, `app/Domain/Facturation/Enums/NatureOperation.php:50-60`), sinon celle de la franchise (`RegimeTva::MENTION_FRANCHISE`, `app/Domain/Tenancy/Enums/RegimeTva.php:39` et `:44`). Le PDF les imprime (`factures/pdf.blade.php:105-107`), et elles sont signées.
- **Fiche (détail de la facture)** : la mention de la nature apparaît sous « Nature de l'opération » (`FactureResource.php:361-366`). La mention de franchise n'y apparaît pas : aucune ressource de vente ne l'affiche (`grep "293 B\|MENTION_FRANCHISE" app/Filament/App/Resources` ne trouve que `ProduitResource`).
- Le plan TVA livre déjà la suite :
  - M2 : table datée des mentions ;
  - M3 : mentions figées et signées, que le PDF relit ;
  - M4 : section « Régime de TVA » de la fiche, avec la « Mention imprimée » ; refus du taux 0 sur une fiche quand la société est redevable (TVA5).
  Voir `tva/plan-execution.md` 3.7, 3.15.4, 3.16.4, 3.16.5 et 5.4 à 5.7.

### 4.2 La réponse de l'éditeur et l'écart

« Sur la facture, automatique » : le motif vient de la nature décidée par le moteur, et sa mention s'imprime d'office. Il n'y a **aucun choix du type de TVA ou du texte de loi par ligne**. Cela s'accorde avec la règle « une seule nature par document » (TVA6). La TVA sur la marge, ligne par ligne, viendra à l'étape 14. Les ventes exonérées (santé, enseignement…) sont toujours refusées (complément 10 : art. 261 à 261 E « refusées ou signalées »).

Écart restant :
1. la mention de franchise n'est pas sur la fiche (M4 l'y met) ;
2. sur le PDF, le motif est loin du taux qu'il explique.

### 4.3 Recensement des ventes sans TVA

| Cas | Mention (code actuel ou plan) | Traitement |
|---|---|---|
| Société en franchise en base | « TVA non applicable, art. 293 B du CGI » | Fait (B10) ; toutes les lignes à 0 % |
| Export hors UE expédié par le magasin | « Exonération de TVA, article 262 I du CGI » | Fait ; conditions et preuves (MRN ou CN23) au lot M4 |
| Livraison vers un DOM | « Exonération de TVA en application de l'article 294 du Code général des impôts » | Fait ; catégorie à confirmer (EC3) |
| Livraison intracommunautaire | « Exonération de TVA, article 262 ter I du CGI » | Fait ; n° VIES valide du jour |
| Prestation à un professionnel de l'UE | « Autoliquidation » (art. 242 nonies A, I-13°) | Fait |
| Prestation à un professionnel hors UE | « TVA non applicable – article 259-1° du CGI » | Fait (formule d'usage, `tva/reglementation.md` ligne 319) |
| Régime de la marge | « Régime particulier – Biens d'occasion » (art. 242 nonies A, I-16°) | Étape 14, ligne par ligne (réponse de l'éditeur) |
| Exonérations des art. 261 à 261 E (santé, enseignement…) | — | Toujours refusées (réponse de l'éditeur, complément 10) |
| Détaxe d'un voyageur | — | Pas une vente à 0 % : le ticket reste à la TVA française, régularisé après le visa (règle I3) |
| Export emporté par le client hors UE | — | Non géré : reste à la TVA française, avec une information (`tva/plan-execution.md`, 3.15.4) |

Les textes des mentions sont ceux du code (`NatureOperation.php:54-58`) et du tableau de `tva/reglementation.md` (lignes 314-327). Les mentions du CIBS à partir de 2027 y sont « à vérifier » (décision O4 : le CGI reste imprimé tant que le CIBS n'est pas confirmé).

### 4.4 Proposition, à ajouter aux lots M3 et M4

- Le motif de chaque taux à 0 % s'imprime aussi dans le tableau de TVA, dans une colonne « Motif » qui n'apparaît que s'il y a un taux sans TVA. Exemple : « 0 % · base 100,00 € · TVA 0,00 € · Exonération de TVA, article 262 ter I du CGI ».
- Cela rapproche le motif du taux qu'il explique, comme la facture électronique, où le motif d'exonération accompagne chaque catégorie de TVA (schematron : `BR-E-10`, `BR-G-10`, `BR-IC-10` et `BR-AE-10` exigent `TaxExemptionReason` ou son code, `serie8/sources/ubl-model.sch:114`). Le rattachement de ces règles à la ventilation par catégorie (BG-23) est celui de la norme, **à vérifier** dans son texte.
- La mention reste aussi dans le pied, avec les autres, comme le demande l'éditeur. **Question Q1-bis**, si le doublon le gêne.
- Avant M3, le tableau lit `MentionsFiscales::pour()`. À partir de M3, il lit les mentions figées de la décision. Rien n'est recalculé.

### 4.5 Tests

- Lot S8-P : `FacturePdfGeneratorTest::test_un_taux_a_zero_imprime_son_motif_dans_le_tableau_de_tva` (livraison intracommunautaire et franchise).
- Lot M4 : `FactureResourceTest::test_la_fiche_affiche_la_mention_de_franchise_d_un_document_emis_en_franchise`, rattaché à la section « Régime de TVA » de 3.16.5.

Les autres tests sont déjà au plan : V05 à V14 et décision figée.

---

## 5. Point 12 : plus de total sous chaque ligne

### 5.1 Ce qui existe

- Chaque ligne de vente (caisse, facture, devis) se termine par `totalDeLigne()` (`app/Filament/Concerns/AjouteDesLignesDeVente.php:352-373`), un bloc sur toute la largeur.
- Il affiche « Total TTC » ou « Total HT » et, en aide, « x € HT + y € de TVA », ou « TVA calculée sur le total », ou « Sans TVA française ».
- Il porte aussi l'**aide du taux inhabituel** d'un service, règle X8 « signalé sans être refusé » (`:367-369`, `TerritoireTva::aideSiTauxInhabituel()`).
- Essai : sous une ligne à 50,00 €, la caisse affiche « Total TTC 50,00 € 41,67 € HT + 8,33 € de TVA » (`sortie.txt`).
- Les totaux du bas sont une seule ligne : « Total HT · TVA · Total TTC » (`app/Filament/Concerns/SaisitLesPrixDeLigne.php:30-46`).

### 5.2 Conception proposée

- Retirer `totalDeLigne()`. La ligne devient une rangée compacte : Référence et désignation (texte), Qté, PU (TTC pour un particulier, comme aujourd'hui), Remise (si autorisée, règle R2), Taux, et le bouton de suppression.
- L'aide du taux inhabituel passe sur le champ du taux (`helperText` de `taux_tva`, `AjouteDesLignesDeVente.php:298-302`). La règle X8 reste tenue.
- « Sans TVA française » passe aussi sur le taux, qui le dit déjà (`:300`).
- Les totaux du bas gardent leur ligne et gagnent, dès qu'il y a plusieurs taux, une rangée par taux (base, TVA, TTC), lue dans le même aperçu (`apercuDesLignes()`, `:111-119`). Il n'y a donc toujours qu'un calcul par requête.
- Le HT d'une ligne n'a plus à s'afficher. Avec la politique R6 proposée par `arrondi-et-calculs.md` (HT de chaque taux réparti par cumul), il dépendrait des autres lignes et changerait quand une ligne s'ajoute : le retirer évite cette confusion.
- **Achats** : la saisie d'une facture d'achat affiche aussi, sous chaque ligne, « Montant payé au fournisseur » et « Vente en magasin » (`app/Filament/Concerns/AjouteDesLignesDAchat.php:105-145`). Le point 12 vise les ventes. Pour les achats, à décider au lot M6, qui réécrit ces écrans : retirer le premier bloc et garder « Vente en magasin », qui montre le prix de vente et la marge au moment où on les fixe. **Question Q6.**

### 5.3 Tests

- Nouveaux : `CaisseTest::test_aucun_total_ne_s_affiche_sous_une_ligne` ; `FactureResourceTest::test_les_totaux_du_bas_se_ventilent_par_taux` ; `FactureResourceTest::test_un_taux_de_service_inhabituel_est_signale_sur_le_champ_du_taux`.
- Tests existants qui lisent le total de ligne, à réécrire en nommant la décision du point 12 (leur montant passe dans les totaux du bas ou dans l'aperçu) :
  - `CaisseTest::test_la_caisse_montre_le_ht_et_la_tva_de_chaque_ligne_et_les_totaux` (`:491`) ;
  - `CaisseTest::test_une_vente_a_un_professionnel_affiche_et_encaisse_le_total_calcule_depuis_le_ht_des_lignes` (`:517`) ;
  - `CaisseTest::test_une_vente_avec_un_service_enregistre_sa_ligne_depuis_le_ttc_saisi` (`:666`) ;
  - `CaisseTest::test_effacer_le_prix_ttc_d_un_service_remet_son_total_a_zero` (`:961`) ;
  - `FactureResourceTest::test_un_taux_de_tva_en_cours_de_frappe_ne_fait_pas_tomber_la_ligne_a_zero` (`:438`) ;
  - `DevisResourceTest::test_changer_le_taux_de_tva_d_un_service_recalcule_le_ht_sans_changer_le_ttc_affiche` (`:603`) ;
  - le test du taux inhabituel de `FactureResourceTest`, si son assertion lit le bloc de la ligne (à vérifier au début du lot).

---

## 6. Point 13 : le grand tableau des produits

### 6.1 Ce qui existe

- « Ajouter un produit » ajoute une ligne vide (`AjouteDesLignesDeVente.php:435-442`, `:586-603`).
- Sa liste « Produit » cherche par nom, référence ou code-barres, sans casse ni accents, trie par nom et rend 50 résultats (`ProduitResource::rechercher()`, `app/Filament/App/Resources/ProduitResource.php:107-116`). C'est elle qui reçoit aujourd'hui la douchette (« Recherchez par nom, référence, ou scannez le code-barres », `AjouteDesLignesDeVente.php:197`).
- La table `produits` a : référence, code-barres, nom, taille, couleur, `cmup_ht`, `prix_vente_ht`, `prix_vente_ttc`, `taux_tva`, `stock_quantite`, `seuil_alerte`, `suivi_stock`, fournisseur et dates (`app/Domain/Catalogue/Models/Produit.php:41-53` et `:105-113`).
- L'autorisation `produit.voir_valeur_stock` cache la valeur du stock aux vendeurs. Le propriétaire, les responsables et les experts-comptables l'ont (`app/Domain/Authorization/PermissionRegistry.php:78-83` et `:232`).
- Filament 3.3 n'a pas de champ « sélection dans un tableau », qui n'existe qu'en version 4. Je n'ai trouvé aucune classe `ModalTableSelect` dans `vendor/filament` (`grep`).
- Dans les tables de Filament 3.3, la sélection des cases reste dans le navigateur (`x-model="selectedRecords"`, `vendor/filament/tables/resources/views/index.blade.php:563`) et n'est envoyée au serveur qu'avec une action groupée (`HasBulkActions.php:29` et `:142`). Un total de la sélection, calculé côté serveur, ne peut donc pas s'appuyer sur ces cases.
- Le choix des colonnes existe (`->toggleable()`), gardé dans la session (`CanToggleColumns.php:81`).

### 6.2 Conception proposée

**L'ouverture.** « Ajouter un produit » ouvre une grande fenêtre, qui peut occuper tout l'écran (`MaxWidth::Screen`). Elle contient un composant Livewire avec une table Filament, `SelecteurDeProduits` (`HasTable`), reçu avec le client et la nature du document.

**Colonnes.** Les colonnes masquées par défaut sont marquées (m.). L'utilisateur choisit les colonnes affichées (`toggleable`) ; son choix est gardé pour la session.

| Colonne | Source | Qui la voit |
|---|---|---|
| Référence | `reference` | tous |
| Nom | `nom` | tous |
| Code-barres (m.), Taille (m.), Couleur (m.) | fiche | tous |
| Stock restant | `stock_quantite` ; « — » sans suivi ; en rouge si ≤ 0, « À régulariser » s'il est négatif (règle R1) | tous |
| Seuil d'alerte (m.) | `seuil_alerte` | tous |
| PV HT, PV TTC | `prix_vente_ht`, `prix_vente_ttc` de la fiche | tous |
| Taux de TVA (%) | `taux_tva` | tous |
| Coût moyen HT (m.) | `cmup_ht` (`cout_moyen` après le lot M7, décision O1) | `produit.voir_valeur_stock` |
| Bénéfice unitaire HT | PV HT − coût moyen HT | `produit.voir_valeur_stock` (**question Q4**) |
| Taux de marque (%) (m.) | bénéfice ÷ PV HT | `produit.voir_valeur_stock` |
| Fournisseur (m.) | dernier achat validé | tous |
| Entré le (m.) | `created_at` | tous |

Le bénéfice et le coût sont ceux de la fiche : une information de catalogue, jamais un montant de document. Le tableau de bord de l'étape 6 (marges) devra lire la même source.

**Affichage de 50 lignes, puis « Voir 50 de plus ».** La table n'est pas paginée (`->paginated(false)`), sa requête est limitée par une propriété `nombreAffiche` (50, puis +50 à chaque clic). Que Filament garde cette limite sans pagination est **à vérifier** au premier essai du lot. Sinon, une pagination de 50 lignes par page avec « Suivant » donne le même résultat.

**Recherche** : nom, référence et code-barres, sans casse ni accents (`RechercheSansAccents`, comme `ProduitResource::rechercher()`).

**Tri et filtres** :
- « Plus récents d'abord » (`created_at` décroissant) est un tri, avec « par nom » ;
- « En stock seulement » : suivi et stock > 0, plus les produits sans suivi ;
- « Hors stock seulement » : suivi et stock ≤ 0, proposé **seulement** à qui a l'autorisation « vendre sans stock » dans ce magasin (constante `STOCK_VENDRE_SANS_STOCK` du lot A6, règle R1 : le propriétaire et les responsables l'ont toujours). L'éditeur a écrit « si le propriétaire autorise la vente sans stock » : dans le modèle de CLAUDE.md, c'est cette autorisation par personne.

**Sans cette autorisation**, un produit épuisé reste visible, grisé, avec « Épuisé », pour que le vendeur puisse le dire au client. Il ne se sélectionne pas, ce qui est contrôlé aussi côté serveur. Le contrôle final reste `VerificateurDeStock` à l'émission (lot A6), avec son avertissement.

**Sélection multiple et totaux**
- Un clic sur une rangée ajoute le produit à la sélection ou l'en retire, avec une quantité modifiable (1 par défaut). La sélection est gardée côté serveur (`selection` : produit → quantité) et les rangées choisies sont surlignées (`recordClasses`). Pas de cases à cocher : elles ne remontent pas au serveur avant une action groupée.
- Bas de la fenêtre : « 3 produits sélectionnés · Total HT 124,17 € · Total TTC 149,00 € · [Ajouter les 3 produits] ».
- Les totaux viennent de `AjouteDesLignesDeVente::apercuDesLignes()` appelé sur les lignes à ajouter, avec le client et la nature du document. C'est le calcul qui chiffrera ces lignes une fois ajoutées : aucun second calcul à l'écran (exigence 5 de l'éditeur au point 15). Pour un professionnel ou une vente sans TVA française, ce total diffère donc d'un simple PV TTC × quantité, comme le document.
- **Ajout** : pour chaque produit, une nouvelle ligne au prix de sa fiche (règle R2). Si une ligne du même produit existe déjà, sans remise ni prix dérogatoire, sa quantité augmente : changer la quantité d'une ligne dérogatoire exigerait l'autorisation des remises.

**Douchette.** Retirer la liste « Produit » de chaque ligne casserait le passage au scanner. Je propose de garder au-dessus des lignes, à la caisse comme sur les factures, un champ « Scanner ou taper une référence ». Une correspondance exacte (code-barres ou référence) ajoute le produit, ou augmente sa quantité, sans ouvrir le tableau. C'est une proposition à faire valider avec l'écran.

**Isolation** : la requête passe par `MagasinScope`, et un produit archivé n'est pas proposé. Un produit d'un autre magasin, forgé dans la sélection, est refusé par le domaine (`ConstructeurLigneDocument`, comme aujourd'hui).

**Où** : caisse, factures et devis (le trait `AjouteDesLignesDeVente`, partagé par les trois). Les pages Livewire reçoivent l'événement `produits-choisis` par un trait commun, par exemple `RecoitLesProduitsChoisis`.

### 6.3 Tests

`tests/Feature/Filament/SelecteurDeProduitsTest.php` :
- `test_le_tableau_affiche_cinquante_produits_puis_cinquante_de_plus` ;
- `test_la_recherche_trouve_par_nom_reference_et_code_barres_sans_accents` ;
- `test_les_colonnes_se_choisissent_et_le_choix_reste_pour_la_session` ;
- `test_le_cout_et_le_benefice_ne_s_affichent_qu_avec_l_autorisation_de_voir_la_valeur_du_stock` ;
- `test_le_filtre_hors_stock_n_existe_que_pour_qui_peut_vendre_sans_stock` ;
- `test_un_produit_epuise_ne_se_selectionne_pas_sans_l_autorisation_meme_par_requete_forgee` ;
- `test_les_totaux_de_la_selection_egalent_ceux_du_document_apres_l_ajout` (particulier, professionnel, deux taux) ;
- `test_ajouter_la_selection_cree_une_ligne_par_produit_au_prix_de_sa_fiche` ;
- `test_un_produit_deja_present_sans_remise_voit_sa_quantite_augmenter` ;
- `test_un_produit_archive_ou_d_un_autre_magasin_n_est_jamais_propose` (isolation).

`CaisseTest::test_scanner_un_code_barres_ajoute_le_produit_sans_ouvrir_le_tableau`.

---

## 7. Point 22 : chercher un client à la caisse

### 7.1 Ce qui existe

- À la caisse, la liste « Client » cherche sur le **nom seul** (`RechercheSansAccents::appliquer(Client::query(), $search, ['nom'])`), triée par nom, avec 50 résultats (`Caisse.php:219-223`).
- Le client comptoir est choisi par défaut (`:182`).
- Sur une facture, la liste est préchargée et triée par nom (`FactureResource.php:127-141`).
- La table `clients` a : nom, type, SIREN, SIRET, n° de TVA, adresse, code postal, ville, pays, téléphone, e-mail et dates (`database/migrations/2026_10_02_200001_create_clients_table.php:14-43`).

### 7.2 Conception proposée

- Une loupe à côté du champ « Client » (une action d'appoint du champ) ouvre une grande fenêtre. Elle contient le composant `SelecteurDeClients` (table Filament).
- **Colonnes** : Nom, Type (particulier ou professionnel), SIREN ou SIRET, n° de TVA, e-mail, téléphone, ville, crédit d'avoir disponible (utile à l'encaissement), dernier achat (m.), créé le.
- **Recherche** : nom, SIREN, SIRET, n° de TVA, e-mail, téléphone, ville et code postal, sans casse ni accents (`RechercheSansAccents::appliquer()` accepte une liste de colonnes, `app/Domain/Recherche/RechercheSansAccents.php:88-106`).
- **Tri** : du plus récent au plus ancien par défaut (`created_at` décroissant), réversible, et par nom.
- **Filtres** : particuliers, professionnels, « avec un crédit d'avoir ».
- **Le client comptoir** reste choisi par défaut. Dans le tableau, il apparaît en premier, « Client comptoir (vente anonyme) », pour y revenir d'un clic.
- **Choix** : un clic sur une rangée pose le client dans le formulaire et ferme la fenêtre. Le mode préféré se présélectionne alors (`Caisse.php:236`, règle B8).
- **Création** : le bouton « Nouveau client » de la fenêtre (avec `client.create`) reprend `ClientResource::creationRapideSchema()`.
- **Liste du champ** : sa recherche rapide s'étend aux mêmes colonnes.
- **Réutilisation** : le même sélecteur sert au champ « Client » des factures et des devis.
- **Isolation** : seuls les clients du magasin (`MagasinScope`), archivés exclus. La recherche par e-mail ne révèle jamais un client d'un autre magasin.

### 7.3 Tests

`tests/Feature/Filament/SelecteurDeClientsTest.php` :
- `test_la_recherche_trouve_un_client_par_siren_email_ou_telephone_sans_accents` ;
- `test_les_clients_se_trient_du_plus_recent_au_plus_ancien` ;
- `test_choisir_un_client_le_pose_dans_la_caisse_avec_son_mode_prefere` ;
- `test_le_client_comptoir_reste_choisi_par_defaut` ;
- `test_un_client_archive_ou_d_un_autre_magasin_n_apparait_jamais` (isolation, même e-mail dans deux magasins).

---

## 8. Point 5 : le rôle à côté de « Bonjour »

### 8.1 Ce qui existe

- Le tableau de bord du magasin affiche `Filament\Widgets\AccountWidget` (`app/Providers/Filament/AppPanelProvider.php:102-104`).
- Sa vue écrit la traduction `welcome` (« Bonjour », `vendor/filament/filament/resources/lang/fr/widgets/account-widget.php:13`), puis le nom, puis « Déconnexion » (`vendor/filament/filament/resources/views/widgets/account-widget.blade.php`).
- Essai : pour un vendeur, le widget affiche « Bonjour Paul Vendeur Déconnexion », sans rôle (`sortie.txt` ; « Vendeur » y est le nom de famille de l'essai).
- L'espace de la société n'a pas ce widget (`app/Providers/Filament/SocietePanelProvider.php` n'en déclare aucun).
- Les libellés existent : `RoleSociete::getLabel()` (Propriétaire, Administrateur, Expert-comptable, Membre, `app/Domain/Tenancy/Enums/RoleSociete.php`) et `RoleMagasin::getLabel()` (Responsable, Vendeur, Observateur, `app/Domain/Tenancy/Enums/RoleMagasin.php`). Le rôle d'un membre dans un magasin se lit par `User::affectationQuiVautDans()`.

### 8.2 Conception proposée

- Un widget `BienvenueApercu` (dans `app/Filament/App/Widgets`) remplace `AccountWidget` dans `AppPanelProvider`. Il affiche « Bonjour {nom} » puis « {rôle} · {magasin} ». Exemples : « Responsable · Boutique Lyon 1 », « Vendeur · Boutique Lyon 1 », « Propriétaire de Ma Boutique SAS », « Administrateur », « Expert-comptable ».
- Le rôle se lit par une seule méthode, par exemple `User::libelleDuRoleDans(Magasin): string` : le rôle de société pour le propriétaire, un administrateur ou un expert-comptable, sinon le rôle de l'affectation dans le magasin ouvert.
- Un membre qui travaille dans deux magasins voit le rôle du magasin ouvert. Dans le magasin de démonstration, la même phrase, suivie de « (démonstration) ».
- L'espace de la société affiche la même phrase en tête de `TableauDeBord`, avec le rôle de société, après le lot A4b, qui réécrit ce tableau de bord en liste d'étapes (`societe/inscription-sans-magasin.md`, section 3).
- Point 20 (identifiants par magasin, autre mission) : si chaque compte d'un membre ne vaut plus que pour un magasin, la méthode reste la même et n'a plus qu'un rôle à lire.

### 8.3 Tests

`tests/Feature/Filament/BienvenueApercuTest.php` :
- `test_le_proprietaire_lit_proprietaire` ;
- `test_un_administrateur_lit_administrateur` ;
- `test_un_expert_comptable_lit_expert_comptable` ;
- `test_un_responsable_lit_responsable_et_son_magasin` ;
- `test_un_vendeur_lit_vendeur` ;
- `test_un_observateur_lit_observateur` ;
- `test_un_membre_de_deux_magasins_lit_le_role_du_magasin_ouvert`.

`PanneauSocieteTest::test_le_tableau_de_bord_de_la_societe_dit_bonjour_avec_le_role`.

---

## 9. Point 16 : les factures à encaisser au tableau de bord

### 9.1 Ce qui existe

- Le tableau de bord du magasin n'a que deux widgets : `AccountWidget` et `FacturesApercu` (essai : `Filament::getPanel('app')->getWidgets()`, `sortie.txt`). `FacturesApercu` ne montre que le chiffre d'affaires de la semaine, du mois et de l'année (`app/Filament/App/Widgets/FacturesApercu.php:35-64`).
- Le menu « Factures » a un badge, mais seulement pour les preuves d'exonération à fournir (`FactureResource.php:106-121`).
- Essai : une facture réglée par virement, puis rouverte par la contre-passation de ce virement, repasse « émise » avec 10,00 € dus. Rien ne la signale (`sortie.txt`). C'est le risque décrit par l'éditeur.
- Ce qui existe déjà et sert ici :
  - les statuts qui attendent un règlement : émise, envoyée, partiellement payée, en retard (`StatutFacture::quiAttendentUnReglement()`, `app/Domain/Facturation/Enums/StatutFacture.php:73-76`) ;
  - le statut suit chaque règlement et chaque contre-passation (`Facture::constaterUnReglement()`, `Facture.php:474-494`) ;
  - la portée de lecture d'une personne (`Facture::scopeVisiblesPar()`, `Facture.php:246-253`) ;
  - les magasins de la portée dans l'espace de la société (`TableauDeBord::magasins()`, `app/Filament/Societe/Pages/TableauDeBord.php:73-76`).

### 9.2 Conception proposée

**Dans le magasin : widget « Factures à encaisser »**, en tête du tableau de bord, en couleur d'alerte dès qu'il y en a une.

- **Contenu** :
  - nombre et restant dû total, dont « en retard » (nombre et montant, en rouge) ;
  - la liste des dix plus anciennes par échéance : numéro (lien vers la fiche), client (nom figé), restant dû, échéance, et un badge « Paiement contre-passé » quand la facture a reçu une contre-passation ;
  - le lien « Voir toutes », vers la liste des factures avec un nouveau filtre « À encaisser ».
- **Sélection** : `Facture::emises()->whereIn('statut', StatutFacture::quiAttendentUnReglement())->visiblesPar($compte)`. Les tickets restés dus en font partie : un ticket créé depuis « Factures », ou rouvert par une contre-passation.
- **Restants dus** : calculés côté PHP en centimes, jamais par une somme SQL sur une colonne décimale (règle 0.3.7 du plan TVA).
- **Qui voit quoi** (demande de l'éditeur, traduite dans le modèle de CLAUDE.md « Comptes et équipe ») :
  - le propriétaire : toutes les factures du magasin ;
  - un responsable du magasin : toutes, y compris celles des vendeurs qu'il gère (il a « voir celles de toute l'équipe » sans case) ;
  - un administrateur : toutes les factures des magasins de sa portée, s'il y a « voir celles de toute l'équipe » (le panneau du magasin ne s'ouvre que dans sa portée) ;
  - un vendeur, et un observateur s'il a la case : seulement s'il est autorisé à contre-passer (`paiement.contre_passer`, `PermissionRegistry.php:151`). Il voit alors ses factures, ou toutes avec « voir celles de toute l'équipe » (règle « Vendeurs ») ;
  - un expert-comptable : il a toutes les consultations (`PermissionRegistry::consultations()`, `:227-242`) ; l'éditeur ne l'a pas cité. **Question Q5.**
- **Magasin en lecture seule** : `paiement.contre_passer` fait partie des autorisations refusées quand le magasin ne peut pas vendre (`PermissionRegistry::reserveesAuxOperationsCommerciales()`, `:281-292`). La condition du vendeur doit donc lire la case cochée, et non le droit d'agir du moment. Le propriétaire, les responsables et les administrateurs voient l'alerte en lecture seule aussi : la facture reste due.

**Dans l'espace de la société** (le propriétaire voit toute la société, un administrateur sa portée) :
- Le tableau des magasins de `TableauDeBord` gagne trois colonnes : « À encaisser » (nombre), « Restant dû » et « dont en retard ». Chaque ligne mène à la liste filtrée du magasin.
- Aucune liste de factures ni aucun nom de client hors du magasin : seulement des sommes, magasin par magasin.
- Un administrateur ne voit la ligne d'un magasin que s'il y a « voir celles de toute l'équipe ». Le magasin de démonstration est exclu.

**Isolation (protocole, règle 2).** Cette lecture se fait hors du magasin courant. Elle doit tenir dans une seule classe, par exemple `app/Domain/Facturation/Services/FacturesAEncaisserDeLaSociete.php` : lecture explicite (`withoutGlobalScope(MagasinScope::class)`), filtrée par les magasins de la portée de la personne (`User::magasinsDeSaPortee()`), limitée au nombre et aux montants, et testée. Elle doit aussi figurer dans la liste de la puce 2 de « Produit » de CLAUDE.md. Texte proposé, à ajouter à cette liste : « le nombre et le restant dû des factures à encaisser de chaque magasin, au tableau de bord de l'espace d'administration, pour qui peut voir toutes les factures de ce magasin ». La demande de l'éditeur (point 16) vaut décision de principe. Le texte exact lui est soumis avec le lot.

**Texte proposé pour la puce « Vendeurs » de CLAUDE.md** : « Le tableau de bord signale les factures à encaisser de la portée de chacun : toutes celles du magasin pour le propriétaire, un responsable et un administrateur qui voit celles de toute l'équipe ; les siennes, ou toutes avec « voir celles de toute l'équipe », pour un vendeur autorisé à contre-passer. »

Cette alerte anticipe une partie de l'étape 6 (« impayés »). L'étape 6 la reprendra dans ses rapports, sans la refaire.

### 9.3 Tests

`tests/Feature/Filament/FacturesAEncaisserTest.php` :
- `test_une_facture_rouverte_par_une_contre_passation_apparait_avec_son_badge` ;
- `test_une_facture_payee_ou_un_brouillon_n_apparait_pas` ;
- `test_le_proprietaire_et_le_responsable_voient_toutes_les_factures_du_magasin` ;
- `test_un_vendeur_sans_l_autorisation_de_contre_passer_ne_voit_pas_l_alerte` ;
- `test_un_vendeur_autorise_a_contre_passer_ne_voit_que_ses_factures` ;
- `test_le_retard_se_compte_a_part` ;
- `test_le_restant_du_se_calcule_en_centimes_sans_somme_sql` ;
- `test_l_alerte_reste_visible_dans_un_magasin_en_lecture_seule`.

`tests/Feature/Societe/FacturesAEncaisserDeLaSocieteTest.php` :
- `test_le_proprietaire_voit_une_ligne_par_magasin_reel` ;
- `test_un_administrateur_d_une_selection_ne_voit_que_ses_magasins` ;
- `test_un_magasin_sans_l_autorisation_de_voir_toutes_les_factures_n_a_pas_de_ligne` ;
- `test_la_lecture_ne_rend_aucun_nom_de_client` ;
- `test_aucune_somme_d_une_autre_societe_n_y_entre` (isolation, nommé dans `IsolationDesSocietesTest`).

---

## 10. Point 25 : numéros cliquables dans les clôtures

Vérifié :
- Dans la fiche d'une clôture, les numéros des tickets et des avoirs sont de simples `TextEntry` (`app/Filament/App/Resources/ClotureCaisseResource.php:130-156`, lignes `:134` et `:149`).
- Le modèle d'un lien vers une fiche existe déjà dans la fiche d'une facture (`FactureResource.php:267-270` et `:322-326`).

Je reprends le changement **C3** de `caisse-clotures-jet-ereporting.md` :
- un lien vers la fiche, seulement si la personne peut voir le document (`FacturePolicy::view()`, `app/Policies/FacturePolicy.php:24-35` ; `AvoirPolicy::view()`, `app/Policies/AvoirPolicy.php:26-37`), sinon du texte ;
- ses deux tests ;
- sa question Q5 (un vendeur qui clôture voit aujourd'hui les tickets des autres).

Il se fait avec C1 et C2, dans le lot « série 8, clôtures », après A6. Le relevé « Fermer ma caisse » n'a pas de numéros de documents (`app/Filament/App/Resources/ClotureCaissePersonnelleResource.php:59-81`) : rien à y faire.

---

## 11. Rattachement aux lots

### 11.1 Ordre proposé

Chaîne actuelle : A4b (en cours) → A5 → A6 → A7 → lot 21 → plan TVA (M0, C-a, M1, M2, M3, C-b, M4, M8, puis les achats).

Le document voisin `arrondi-et-calculs.md` propose le lot **R6** (politique d'arrondi unique) après A4b et avant A5. `caisse-clotures-jet-ereporting.md` propose le lot **« série 8, clôtures »** (C1 à C3) après A6, puis C4 et C5.

| Lot proposé | Points | Fichiers principaux | Place | Pourquoi |
|---|---|---|---|---|
| **S8-T** Tableau de bord | 5, 16 | `AppPanelProvider`, nouveaux widgets, `TableauDeBord` de la société, `FacturesAEncaisserDeLaSociete`, `FactureResource` (filtre « À encaisser »), CLAUDE.md (puces 2 de « Produit » et « Vendeurs ») | Après A4b | A4b réécrit `TableauDeBord`. Rien dans A5 à A7 ne touche ces fichiers. |
| **S8-P** PDF des ventes | 3, 4, partie PDF de 11 et 23 | vues `factures/`, `avoirs/`, `devis/`, `documents/pdf/*`, `Emetteur` (présentation), les trois générateurs | Après R6, avant A7 | R6 décide du sens du PU HT et de la note de calcul. A7 (après Q6) ajoute la mention de l'avoir de régularisation dans `MentionsFiscales` : il la placera dans le nouveau pied. |
| **S8-R** Règlements | 1, 23 | `Caisse`, `ReglementComptant`, `EnregistrerVenteCaisseAction`, `EmettreFactureAction`, `FactureResource` (« Encaisser »), nouvelle action d'encaissement en plusieurs modes, CLAUDE.md (« Paiements et avoirs ») | Après A6, avec « série 8, clôtures » | A6 modifie ces mêmes fichiers (jetons R3, R1, R2). La clôture par mode (C2) compte les parts. |
| **S8-A** Retours | 2 | `AvoirResource` (création), `ViewFacture`, `Caisse` (bouton de retour, selon Q8) | Après A6, avant M4 | A6 met le jeton sur `EnregistrerAvoirAction`. M4 ajoute ensuite la compatibilité des documents à l'écran refait. |
| **S8-L** Lignes et sélecteurs | 12, 13, 22 | `AjouteDesLignesDeVente`, `SaisitLesPrixDeLigne`, `Caisse` (client, douchette), composants `SelecteurDeProduits` et `SelecteurDeClients` | Après A6 et R6, avant A7 | Le filtre « hors stock » lit l'autorisation de A6 (R1). Les lignes prennent le prix de la fiche (R2). R6 touche `AjouteDesLignesDeVente`. |

Proposition d'enchaînement : A4b → R6 → S8-T → S8-P → A5 → A6 → S8-R, S8-A et « série 8, clôtures » (C1 à C3) → S8-L → A7 → C4 et C5 → lot 21 → plan TVA.

Le lot 21 vérifie ensuite toutes ces décisions dans sa matrice décision → test (`societe/conception-v2-finale.md`, section 14). S8-R, S8-A et les clôtures touchent tous la caisse et les avoirs : un seul lot « série 8, caisse » est possible s'il reste relisible, sinon trois commits verts.

### 11.2 Le lot M4 : ce qui doit y entrer, ce qui peut se faire avant

**Peut se faire avant M4** : tous les lots S8. Aucun ne lit le moteur de TVA. Ils changent l'aspect des écrans, l'encaissement et le PDF, jamais une règle de TVA ni un montant.

**Doit entrer dans M4** (à écrire dans `tva/plan-execution.md`, 3.16 et 5.7) :
1. **Panneau « Régime de TVA de cette vente »** : il se place à côté des lignes refaites par S8-L. Le total « au lieu de » vient du moteur (P9). Les totaux de la sélection du tableau des produits passent la nature au même aperçu : ils montrent donc le prix sans TVA française quand une nature l'impose.
2. **Avoirs** (3.16.7) : « l'écran d'un avoir sur plusieurs factures regroupe les factures compatibles et grise les autres » devient, avec la recherche par numéro de S8-A, « ajouter le numéro d'un document incompatible (type de client, franchise, nature, circuit) est refusé avec le message de `CreerAvoirAction` ». Le test `AvoirResourceTest::test_l_ecran_d_avoir_grise_les_factures_incompatibles` (3.23.8) devient `test_l_ecran_d_avoir_refuse_d_ajouter_un_document_incompatible`.
3. **Caisse** (3.16.6) : l'information pour un client professionnel établi hors de France et l'avertissement pour des services à une entreprise étrangère se placent sous le sélecteur de clients de S8-L.
4. **Point 11** : la fiche d'un document émis montre la mention de franchise dans sa section « Régime de TVA » (déjà prévue en 3.16.5). Le tableau de TVA du PDF lit les mentions figées (M3) pour sa colonne « Motif ». Aucun choix de régime par ligne n'est construit (réponse de l'éditeur).
5. **Fiche produit** : le refus du taux 0 (TVA5), déjà au plan.

**Autres textes du plan à mettre à jour** :
- 3.11 (`clotures_caisse` « inchangée ») : déjà relevé par le document voisin (C2).
- 5.6 (lot C-b) : « Payé par l'avoir n° AV-… (annulation du ticket n° TK-…) » s'imprime dans le bloc « Règlements » de S8-P. « Remplace le ticket TK-… » et la date de la vente vont dans l'en-tête.
- 1.2 : « `ModePaiement` garde virement, chèque, espèces, carte bancaire, prélèvement » reste vrai. « Avoir » est un choix d'écran, pas un mode (section 1.3).

---

## 12. Remarques notées en passant (hors mission)

- **Échéance sur un ticket** : le PDF d'un ticket payé à la caisse imprime « Échéance le 16/11/2026 » (essai, `pdftotext -layout ticket.pdf`, ligne 7). Pour un ticket déjà réglé, cette échéance n'a pas de sens. À trancher avec S8-P : ne l'imprimer que tant qu'un montant reste dû.
- **Autoliquidation intérieure** : une vente en France autoliquidée entre professionnels, par exemple la sous-traitance du bâtiment (art. 283-2 nonies du CGI, **à vérifier**), n'a aucune nature dans le plan TVA (aucune occurrence de « 283-2 nonies » ni de « sous-traitance » dans `tva/plan-execution.md`). Pour un logiciel de magasins, c'est sans doute hors périmètre, mais il faudrait le dire à l'éditeur.

---

## 13. Questions pour l'éditeur

Chaque question a une réponse recommandée. Ce qui en dépend attend sa réponse ; le reste du lot avance.

- **Q1. Colonnes d'un ticket de caisse** (lot S8-P).
  - (a) **Recommandée** : un ticket montre le PU TTC et le total TTC de chaque ligne, avec le tableau de TVA par taux en bas. Une facture montre le PU HT et le total HT, comme vous le demandez au point 3.
  - (b) Même colonnes HT pour tous les documents.
- **Q1-bis. Motif d'un taux à 0 %** (lot S8-P). Le motif s'imprime-t-il aussi dans le tableau de TVA, à côté du taux qu'il explique, en plus du pied de page ? Recommandé : oui.
- **Q2. Règlements sur le PDF** (lot S8-P).
  - (a) **Recommandée** : le PDF imprime les règlements de l'émission, lus dans le contenu signé (un ticket encaissé montre chaque mode). Les paiements reçus plus tard restent sur la fiche, conformément à « Le PDF reproduit le document tel qu'émis ».
  - (b) Une réimpression imprime aussi une section datée « Situation des règlements au … », séparée du document émis.
- **Q3. Bon d'avoir sur une facture créée depuis « Factures »** (lot S8-R).
  - (a) **Recommandée** : non, le bon d'avoir reste réservé à la caisse (décision K6, « prochaine vente au comptoir »).
  - (b) Oui, sur toute facture du magasin.
- **Q4. Coût et bénéfice dans le tableau des produits** (lot S8-L). Recommandé : seulement pour qui voit la valeur du stock (le propriétaire, les responsables et l'expert-comptable toujours ; un vendeur si on le lui coche).
- **Q5. Alerte des factures à encaisser** (lot S8-T). Recommandé : l'expert-comptable la voit en lecture, dans chaque magasin réel. Un observateur, seulement s'il est autorisé à contre-passer, comme un vendeur.
- **Q6. Saisie des achats** (lot M6). Retirer aussi « Montant payé au fournisseur » sous chaque ligne d'achat, et garder « Vente en magasin » ? Recommandé : oui.
- **Q7. « Les plus récents »** dans le tableau des produits (lot S8-L). Recommandé : un tri par date d'entrée du produit, c'est-à-dire la validation de l'achat ou de l'inventaire d'ouverture qui l'a créé (point 18).
- **Q8. Retour depuis la caisse** (lot S8-A). Recommandé : un bouton « Retour d'un ticket » à la caisse, qui ouvre la même recherche par numéro.

---

## 14. Fichiers de cette mission

- Ce livrable : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/ecrans-de-vente.md`
- Essais : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc/tests/Feature/Scratch/Serie8EcransDeVenteTest.php` et `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc/tests/Feature/Scratch/Serie8PrototypePdfTest.php`
- Sorties : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/essais-ecrans/sortie.txt`, ainsi que `facture-pro.pdf`, `facture-pro.html`, `ticket.pdf`, `ticket.html`, `prototype-1-ligne.pdf`, `prototype-60-lignes.pdf`, `prototype-60-lignes-numerote.pdf` et `p60.html` dans le même dossier.
