# Série 9 : critique de complétude des deux analyses

Critique du 26/10/2026 de `serie9/analyse-lignes.md` (demande 1, « un produit = une ligne ») et de `serie9/analyse-a-faire.md` (demande 2, « À faire » au tableau de bord), vérifiées dans la copie figée `scratchpad/code-fa83824` (commit `fa83824`, lecture seule, aucun test lancé). Les chemins `app/…`, `tests/…`, `resources/…`, `database/…` sont relatifs à cette copie.

Rien n'est tranché ici. Chaque constat dit ce qui manque, est faux ou mal posé, puis la correction exacte à apporter à l'analyse.

## Sources lues

- Demande : `serie9/demande-editeur.md` (texte d'origine et traduction, que j'ai relue sur le texte albanais : elle est fidèle).
- Règles : `code-fa83824/CLAUDE.md`, en entier ; `pilotage-reprise/README.md` (§ 5 à 9) et `pilotage-reprise/ETAT.md` ; dans `pilotage-reprise/notes/` : `serie8/demande-editeur.md` (points 13 et 16), `serie8/ecrans-de-vente.md` (§ 6.2), `serie8/reponses-serie8.md`, `serie8/reponses-types-activite.md`, `serie8/decision-types-activite.md` (règles 3 et 4, textes pour `CLAUDE.md`), `serie8/decision-espace-cabinets.md` (règles 2, 7, 8), `serie8/avenant-cabinets.md` (texte « Cabinets d'expertise comptable », tableau de bord L1), `decisions/reponses-regles-ecran-26-10.md`, `decisions-editeur.md` (B16, I4), `historique/DIRECTIVES.md` (§ 3.3, 3.4), `historique/messages-editeur.md` (messages 257 à 284), `tva/conception-finale.md` (§ 5.4), `tva/plan-execution.md` (l. 1329 et 1848).
- Code de la copie : `App\Filament\Concerns\AjouteDesLignesDeVente`, `RecoitLesProduitsChoisis`, `App\Filament\App\Livewire\SelecteurDeProduits` et sa vue `selecteur-de-produits.blade.php`, `fenetre-du-selecteur-de-produits.blade.php`, `App\Filament\App\Pages\Caisse`, `CreateFacture`, `EditFacture`, `EditDevis`, `DevisResource`, `App\Domain\Facturation\Services\DerogationsDePrix`, `CalculateurLigne` (`chiffrerUnAchat()`, `montantNetCentimes()`, `diviserArrondi()`), `LignesRetirees`, `ConstructeurLigneDocument` et ses appelants, `CreerFactureDepuisDevisAction`, `EnregistrerVenteCaisseAction`, `ConstructeurLigneAchat`, `ConstructeurLigneInventaire`, `FactureResource` (badge, filtres, `urlDesFacturesAEncaisser()`), `Facture` (scopes), `PreuvesRequises`, `NatureOperation`, `PermissionRegistry` (`deVente()`, `reserveesAuxOperationsCommerciales()`), `RoleMagasin::permissionsParDefaut()`, `User::suitLesFacturesAEncaisserDans()`, widgets `FacturesAEncaisserApercu` et `StockNegatifApercu`, `App\Filament\Societe\Pages\TableauDeBord`, `Magasin::mentionsLegalesManquantes()`, `InventaireOuverturePolicy`, `DevisPolicy`, `PosteReconnuPolicy`, `PostesReconnus`, `DeclencherTransmissionPdpApresEmissionFacture`, `MarquerFacturesEnRetard`, `FicheDuMembre::annulerLaNominationAction()`, les énumérations de statut, les migrations `create_devis_table` et `create_bordereaux_detaxe_table`.
- Tests de la copie : `CaisseTest` (l. 338-343, 663-674, 760-778), `SelecteurDeProduitsTest` (l. 390-407), `PolitiqueDArrondiTest` (l. 162-215, 250-268, 288-304), `FactureResourceTest` (l. 244-250, 525-536).
- Dans le dépôt, en lecture seule (`git show`, rien de modifié) : le commit local `e70f18e` du lot PU (message et `tests/Feature/Facturation/PolitiqueDArrondiTest.php`).

---

## 0. En bref

1. **Faux (lignes)** : `PolitiqueDArrondiTest` met déjà deux fois le même produit dans un document (l. 256 et 293), et le lot PU y ajoute un test de quatre lignes du même écran. La garantie du serveur proposée (Q-L6 a) contredit une phrase de `CLAUDE.md` approuvée par l'éditeur et ces tests (§ 2, L2).
2. **Faux (lignes)** : la caisse s'ouvre déjà sans ligne vide ; seules une nouvelle facture et un nouveau devis en ont une (§ 2, L1). L'inventaire des tests touchés par la disparition de la liste « Produit » est très incomplet.
3. **Questions qui n'en sont pas** : Q-L1, Q-L3, Q-L4, Q-L6, Q-L7, Q-AF3, Q-AF8 et la moitié de Q-AF9 sont déjà tranchées par la demande, par `CLAUDE.md`, par le point 13 de la série 8, ou sont techniques (D7 : « Le technique se décide seul ») (§ 1, C3).
4. **Recommandation contraire à la demande** : Q-L2 (a) garde deux lignes du même produit, alors que l'éditeur demande que ce ne soit « jamais possible ». La question doit le dire (§ 2, L4).
5. **Lots suivants oubliés** : la série 9 se fera après TA, EC, L1, A7 et 21. Les sociétés de services (TA) n'ont ni produits, ni stock, ni preuves d'export ; l'expert-comptable de la société disparaît au profit du cabinet lié (EC). Aucune des deux analyses n'en tient compte (§ 1, C1 et C2).
6. **Contradiction avec `CLAUDE.md` (À faire)** : la ligne « factures à encaisser » proposée oublie « celles dont un paiement a été contre-passé », que `CLAUDE.md` exige au tableau de bord ; Q-AF2 (c) et Q-AF4 (c) contredisent `CLAUDE.md` et les mots de l'éditeur au point 16 de la série 8 (§ 3, A3 et A4).
7. **Éléments en attente oubliés** : un magasin sans poste reconnu alors que des comptes ont encore leur identifiant pour mot de passe (ils ne peuvent pas se connecter) ; un ticket détaxé qui attend le visa ; un catalogue vide avant la première vente ; une preuve d'export impossible à noter dans un magasin fermé ; des éléments sans fin (nomination, écart Sirene) (§ 3, A7).
8. **Le lot devrait deviner** : aucune phrase exacte par élément, aucune date de tri, des cases « si coché » sans ce qui se passe sinon, un ordre « par groupe » et « par urgence » à la fois, des filtres de liste qui n'existent pas, aucun texte de `CLAUDE.md` proposé pour la demande 2 (§ 3, A6, A8 et A9).
9. **Options sans exemple chiffré** : Q-AF1, Q-AF2, Q-AF3, Q-AF4, Q-AF7, Q-AF9 et Q-AF10 (DIRECTIVES § 3.3 exige un exemple chiffré et ce que chaque option change pour un magasin) (§ 3, A11).
10. Le § 4 donne la liste corrigée des questions à poser, avec leur texte ; le § 5, ce qu'un lot doit avoir en main avant de commencer.

---

## 1. Constats communs aux deux analyses

### C1. Le code de référence et la place du lot dans la chaîne ne sont pas dits

**Constat.**
- `pilotage-reprise/ETAT.md` et le dépôt montrent le lot PU commité en local (`e70f18e`), avec sa relecture en cours ; TA, EC, L1, A7 et 21 suivent (README § 6, « Reste dans la chaîne »). La série 9 n'est dans aucune liste.
- Le lot PU touche déjà `AjouteDesLignesDeVente` (docblock de `champsPrixDeLigne()`, l. 369 et suivantes : +3 lignes) et `SelecteurDeProduits` (colonnes `prix_vente_ttc` et `cout_moyen`, `prixUnitaireAffiche()`). Les numéros de ligne des deux analyses seront faux dès le début du lot.
- TA et EC changent des règles que les analyses utilisent (C2).

**Correction.** Ajouter en tête de chaque analyse un paragraphe « Place du lot » :
> « Le lot de la série 9 se fait après le lot 21 (ou : se place entre … et …, selon la décision de la session). Il part du code poussé à ce moment-là, pas de `fa83824` : au début du lot, l'agent revérifie chaque fichier, méthode et numéro de ligne cités ici, et applique les règles des lots TA (sociétés de services) et EC (cabinets liés), décrites au § … . »

Et dans la fiche `pilotage-reprise/README.md` (§ 6), ajouter la série 9 à « Reste dans la chaîne » ou à « Ensuite », avec sa place.

### C2. Le § 7 du README est mal lu

**Constat.**
- `analyse-lignes.md`, l. 5 : « aucune [décision plus récente] ne porte sur les lignes des documents ». C'est faux :
  - `serie8/reponses-types-activite.md` (règle 1) et `serie8/decision-types-activite.md` (règle 4, et la liste de fichiers du commit 2) : dans une société de services, « La caisse, les devis et les factures n'ont que « Ajouter un service » : ni sélecteur de produits, ni douchette » ; les services ont leurs propres autorisations `service.view`, `service.create`, `service.update`.
  - `serie8/reponses-affichage-pu.md` (lot PU) : tout prix unitaire affiché en lecture, sélecteur des produits compris, l'est au centime ; un champ de saisie montre le prix enregistré.
- `analyse-a-faire.md`, l. 3, cite `reponses-regles-ecran-26-10.md` et `decision-espace-cabinets.md`, mais pas `serie8/reponses-cabinets.md` (qui prime sur `decision-espace-cabinets.md`), ni `serie8/avenant-cabinets.md`, ni `serie8/reponses-types-activite.md`. Or ces fichiers décident :
  - qu'une société de services n'a « ni produits, ni stock, ni achats de marchandises, ni avoirs fournisseur, ni inventaire d'ouverture, ni barème, ni vente sans stock » ni « natures d'opération qui portent sur des biens » (texte pour `CLAUDE.md` de `decision-types-activite.md`, et sa puce 5 : « ni preuve d'export ou de livraison ni bordereau de détaxe n'y existe ») ;
  - que « le compte d'expert-comptable créé par le propriétaire dans sa société disparaît » (`decision-espace-cabinets.md`, règle 7) ; le cabinet lié a, par défaut, toutes les autorisations de consultation, et le propriétaire du client peut lui ajouter celles des achats et du stock (client « Commerce ou atelier » seulement), « jamais celles de la vente […], ni la caisse et ses clôtures, ni les alertes de stock » (`avenant-cabinets.md`, texte « Cabinets d'expertise comptable »).

**Correction.**
- Dans `analyse-lignes.md`, remplacer la phrase de la l. 5 par :
  > « Deux décisions plus récentes touchent les lignes : TA (une société de services n'a ni sélecteur de produits ni douchette : seule la règle des services, Q-L5, s'y applique ; les autorisations des services sont `service.*`) et PU (les prix unitaires montrés en lecture, au sélecteur comme sur les fiches, sont arrondis au centime ; les champs de saisie gardent le prix enregistré). »
- Dans `analyse-a-faire.md`, compléter la l. 3 avec ces trois fichiers, et ajouter un § 2 bis « Selon le type d'activité et le cabinet lié » qui dit :
  > « Société de services : A5, A6, D1 à D6, E2 et K1 n'existent pas ; E1 se lit avec `service.update` et `vente.remises_et_prix`. Collaborateur du cabinet lié : il voit, dans la limite des autorisations que le client accorde, ce qui se consulte (factures à encaisser, preuves à fournir, brouillons) en « à surveiller » ; les achats et l'inventaire en « à faire » seulement si le propriétaire du client lui a ajouté ces autorisations ; jamais la caisse, les clôtures, la vente ni les alertes de stock. »

### C3. Trop de questions, dont plusieurs sont déjà tranchées ou techniques

**Constat.** Dix-sept questions, plus une demande de précision. `historique/DIRECTIVES.md` § 3.3 : « peu de questions à la fois » et « Ne poser que les règles métier. Le technique se décide seul (D7). » Or :
- **Q-L1** est tranchée par la demande elle-même (« Quand on choisit les produits à vendre ») et par `CLAUDE.md` : un retour rend « pour tous les articles d'une ligne » (lignes d'avoir liées à leur ligne d'origine) ; un avoir fournisseur « ne porte que des lignes d'une facture d'achat validée du magasin, chacune liée à sa ligne d'achat » ; un achat se calcule ligne par ligne depuis la facture du fournisseur. Les options (b) et (c) contrediraient ces règles.
- **Q-L3** se déduit du point 13 de la série 8, mot de l'éditeur : « au lieu d'une ligne à remplir, un clic sur « Ajouter un produit » ouvre un grand tableau » (`serie8/demande-editeur.md`). La liste « Produit » de chaque ligne est justement cette « ligne à remplir ».
- **Q-L4** est tranchée par la demande : « si on clique deux fois sur un produit, seule la quantité augmente ». L'option (b) (le second clic retire) et l'option (c) (rien) la contredisent.
- **Q-L6** est technique : l'écran réunit déjà les lignes ; la question est seulement « le domaine refuse-t-il ce que l'écran ne doit jamais envoyer ? ». Le projet répond toujours oui (docblock de `AjouteDesLignesDeVente` : « le serveur refuse toute valeur changée (voir DerogationsDePrix) » ; `CLAUDE.md` : « la base refuse une vente ou un encaissement à son nom »). Seul le texte de `CLAUDE.md` qui en découle doit être soumis (L2).
- **Q-L7** porte sur les achats, hors de la demande ; un simple message à l'écran est un choix d'écran (D7).
- **Q-AF3** (ordre, couleurs) et **Q-AF8** (masquer un élément) : la demande tranche Q-AF8 (« tout ce qui est en attente doit y figurer ») ; Q-AF3 est une présentation, que la session décide et montre dans la page d'arbitrages.
- **Q-AF9** : la fin d'un e-mail en échec (« tant qu'aucun envoi plus récent du même document n'a réussi ») est technique ; compléter plus tard le n° d'un avoir fournisseur est une fonction nouvelle, hors de la demande.

**Correction.** Garder seulement les questions du § 4 de cette critique (trois pour les lignes, dix pour l'« À faire »), et, pour chaque question retirée, écrire dans l'analyse une ligne « Tranché par : … » avec la citation exacte (demande, `CLAUDE.md`, point 13 de la série 8, D7). Comme la série reste longue, la poser par une page privée d'arbitrages (DIRECTIVES § 3.3 : « Une longue série passe par une page privée d'arbitrages »), en vouvoyant l'éditeur.

### C4. La chaîne TVA prévoit déjà d'autres nombres dans les menus

**Constat.** `tva/conception-finale.md` (§ 5.4) et `tva/plan-execution.md` (l. 1329 et 1848) prévoient des « listes de suivi », chacune « avec un badge et une notification » : « Dédouanement à compléter » (lot M7), « TVA étrangère à régulariser » (lot M6), « Charges à valider » (lot CH2). `analyse-a-faire.md` (§ 2 J) attribue les trois à `conception-finale.md` § 5.4 : « Charges à valider » n'est que dans `plan-execution.md`. Surtout, la décision sur le nombre jaune (Q-AF7) doit valoir aussi pour ces badges futurs, sinon le lot M7 remettra ce que la série 9 aura retiré.

**Correction.**
- Corriger la citation : « « Charges à valider » : `tva/plan-execution.md`, l. 1329 et 1848 (lot CH2). »
- Ajouter à Q-AF7 la phrase : « La même règle vaudra pour les listes que prévoit la chaîne TVA (« Dédouanement à compléter », « TVA étrangère à régulariser », « Charges à valider ») : elles entreront dans l'« À faire », et leur badge suivra votre réponse. »
- Après la réponse, mettre d'accord `notes/tva-lots-M0-FIN.js` et la liste « Chaîne TVA » du README (§ 6, « Ensuite », point 2 « Contenu »).

---

## 2. `analyse-lignes.md`

### L1. Faux : « Le répéteur s'ouvre avec une ligne vide »

**Constat.** `AjouteDesLignesDeVente::lignesDeVente()` a bien `defaultItems(1)`, mais seule une page qui remplit son formulaire sans valeur reçoit cette ligne : `CreateFacture` et `CreateDevis`. La caisse remplit le sien avec `'lignes' => array_values($panier->lignes ?? [])` (`Caisse::remplirFormulaire()`, l. 304-313) : elle s'ouvre **sans** ligne. Le test `CaisseTest::test_ajouter_un_produit_ouvre_le_tableau_des_produits_sans_ajouter_de_ligne` (l. 663-674) vérifie `[]`, et les tests de la caisse qui choisissent un produit par la liste créent eux-mêmes une ligne vide (`set('data.lignes', [[...]])`, l. 338, 379, 484, 514, 621, 646). `EditFacture` et `EditDevis` rouvrent les lignes enregistrées (`mutateFormDataBeforeFill()`).

Conséquences :
- À la caisse, la liste « Produit » ne sert qu'à **remplacer** le produit d'une ligne déjà ajoutée. Sur une facture ou un devis, elle sert aussi à remplir la ligne vide du début.
- Le test proposé `test_la_caisse_s_ouvre_sans_ligne_vide` (§ 5.2) passe déjà : il ne prouve rien.
- L'inventaire des tests touchés (§ 5.3) est très incomplet : `FactureResourceTest` lit 17 fois `array_key_first($component->get('data.lignes'))`, et plusieurs de ces lectures, faites après un `fillForm()` sans lignes, prennent la ligne vide par défaut (l. 244, 530, 626, 659, 718, 943, 962, 989) ; `DevisResourceTest` le fait 5 fois. Avec `defaultItems(0)`, ces tests changent aussi, pas seulement ceux qui posent `produit_id`.

**Correction.**
- § 1.1, tableau, première ligne : remplacer « Le répéteur s'ouvre avec une ligne vide (`defaultItems(1)`, l. 125) » par :
  > « Une nouvelle facture et un nouveau devis s'ouvrent avec une ligne vide (`defaultItems(1)`, l. 125, appliqué par `CreateRecord`) ; la caisse s'ouvre sans ligne (`Caisse::remplirFormulaire()`, l. 304-313 ; test `CaisseTest`, l. 663-674) ; un brouillon rouvert montre ses lignes. À la caisse, la liste ne sert donc qu'à remplacer le produit d'une ligne. »
- § 5.2 : remplacer `test_la_caisse_s_ouvre_sans_ligne_vide` par `test_une_nouvelle_facture_et_un_nouveau_devis_s_ouvrent_sans_ligne_vide`.
- § 5.3 : remplacer la liste des tests par la consigne :
  > « Au début du lot, recenser tous les tests qui lisent `array_key_first(…->get('data.lignes'))` ou posent `data.lignes.{…}.produit_id` (aujourd'hui : `CaisseTest` 20, `FactureResourceTest` 17, `DevisResourceTest` 5, `SelecteurDeProduitsTest` 3, `ProduitResourceTest` 2 occurrences ; `InventaireOuvertureResourceTest` et `FactureAchatResourceTest` ne sont pas touchés), et les citer un par un dans le message de commit avec la décision qui les change. »

### L2. Faux : « `PolitiqueDArrondiTest` n'en fait pas (un produit par ligne) »

**Constat.**
- Le fournisseur `decoupagesDeCinquanteEuros` crée bien un produit par ligne (l. 172-215). Mais deux autres tests de la copie mettent **le même produit sur deux lignes** :
  - `test_le_meme_panier_donne_les_memes_montants_a_un_particulier_et_a_un_professionnel` : `[[$tshirt, 1], [$tshirt, 1], [$chaussettes, 1]]` (l. 256), qui attend 20,83 € puis 20,84 € HT ;
  - `test_un_retour_complet_reprend_exactement_les_montants_de_la_vente` : `[[$tshirt, 1], [$tshirt, 1], [$livre, 2]]` (l. 293).
- Le lot PU (`e70f18e`) ajoute `test_quatre_articles_a_65_euros_ttc_font_216_67_ht_et_43_33_de_tva_en_une_ou_quatre_lignes`, dont un jeu met quatre lignes du même `$ecran`.
- Ces tests passent par `CreerFactureAction` et `EnregistrerVenteCaisseAction`, donc par `ConstructeurLigneDocument::construireToutes()`, où l'analyse place le refus (§ 5.1).
- Ils prouvent la phrase de `CLAUDE.md` (« Prix et TVA des ventes ») : « deux lignes identiques d'un document peuvent recevoir des HT qui diffèrent d'un centime (20,83 € et 20,84 €) ». Cette phrase vient de la politique d'arrondi de la série 8, approuvée par l'éditeur.

**Correction.**
- § 2.4, dernier point, et § 5.3, dernier point : remplacer « `PolitiqueDArrondiTest` n'en fait pas (un produit par ligne) » et « il resterait valable » par :
  > « Trois tests mettent le même produit sur plusieurs lignes aux mêmes conditions et changeraient avec un refus du serveur : `PolitiqueDArrondiTest::test_le_meme_panier_donne_les_memes_montants_a_un_particulier_et_a_un_professionnel` (l. 256), `test_un_retour_complet_reprend_exactement_les_montants_de_la_vente` (l. 293), et, depuis le lot PU, le jeu « quatre lignes de 1 » de `test_quatre_articles_a_65_euros_ttc_font_216_67_ht_et_43_33_de_tva_en_une_ou_quatre_lignes`. Ils seraient réécrits avec deux produits différents au même prix (même intention : la répartition par cumul), et cités dans le commit avec la décision. »
- Le texte de `CLAUDE.md` à soumettre (§ 4.1) doit remplacer explicitement « deux lignes identiques d'un document peuvent recevoir des HT qui diffèrent d'un centime (20,83 € et 20,84 €) » par « deux lignes de même prix d'un document, par exemple deux articles différents à 25,00 €, peuvent recevoir des HT qui diffèrent d'un centime (20,83 € et 20,84 €) ». Ce changement ne dépend plus d'une réponse à Q-L6 (retirée, C3) : il découle du refus par le domaine, décidé comme choix technique.

### L3. Questions déjà tranchées (Q-L1, Q-L3, Q-L4, Q-L6, Q-L7)

**Constat.** Voir C3.

**Correction.** Dans le § 4.2, remplacer ces cinq questions par :
> « Tranché, sans question :
> - Ventes seulement (Q-L1) : la demande parle des « produits à vendre » ; un avoir garde une ligne par ligne d'origine et un avoir fournisseur une ligne par ligne d'achat (`CLAUDE.md`, « Prix et TVA des ventes » et « Achats et stock ») ; une facture d'achat recopie celle du fournisseur.
> - Plus de liste « Produit » dans les lignes (Q-L3) : point 13 de la série 8, « au lieu d'une ligne à remplir ». Une ligne montre sa référence et son nom, se retire par sa corbeille ; plus de ligne vide sur une nouvelle facture ou un nouveau devis.
> - Chaque clic sur une rangée du tableau ajoute 1 (Q-L4) : la demande, « si on clique deux fois sur un produit, seule la quantité augmente ». La croix en bas de la fenêtre retire le produit.
> - Le domaine refuse deux lignes du même article aux mêmes conditions (Q-L6) : choix technique (D7), comme pour toute règle de vente ; seul le texte de `CLAUDE.md` qui en découle est soumis à l'éditeur.
> - Facture d'achat (Q-L7) : hors de la demande ; aucun changement, sinon un message d'écran sans refus si la session le juge utile, dit dans les « laisses » du lot. »

Garder le paragraphe « *Coût* » de Q-L3 (réécriture des tests) en le déplaçant au § 5.3.

### L4. Q-L2 : la recommandation contredit la demande sans le dire

**Constat.** La demande : « il ne doit pas être possible d'ajouter deux fois le même produit ». L'option recommandée (a) garde deux lignes de « Câble » dès que la première porte une remise ou un autre prix. Seule l'option (c) respecte la demande à la lettre pour tout le monde ; (b) la respecte pour une personne autorisée. L'analyse ne le dit pas. Elle ne dit pas non plus pourquoi la demande à la lettre se heurte à R2 : `CLAUDE.md` exige l'autorisation pour « changer […] la quantité […] d'une ligne qui porte une remise ou un prix dérogatoire ».

**Correction.** Réécrire le contexte de Q-L2 ainsi :
> « Votre demande dit qu'un produit ne doit jamais être ajouté deux fois. Une seule situation s'y oppose : le produit est déjà sur une ligne remisée, ou à un autre prix que sa fiche. Changer la quantité de cette ligne demande l'autorisation « accorder des remises et fixer le prix d'un service » (règle déjà en place). L'option (c) suit votre demande à la lettre ; l'option (a) garde deux lignes dans ce seul cas ; l'option (b) ne les garde que pour une personne sans l'autorisation. »

Garder l'exemple chiffré existant (9,00 € + 10,00 € contre 2 × 9,00 €). Si la recommandation reste (a), ajouter sa raison en une phrase (la remise reste aux seuls articles pour lesquels elle a été accordée, et un vendeur n'est jamais bloqué).

### L5. La demande de précision est incomplète

**Constat.** Sur le code de la copie, le tableau et la douchette réunissent déjà (`ligneSansDerogationDuProduit()`) ; une ligne vide n'existe qu'au début d'une facture ou d'un devis (L1) ; au tableau, un second clic **retire** le produit (`basculer()`). Deux lignes du même produit ne viennent donc que de quatre gestes : remplacer le produit d'une ligne par la liste, ajouter un produit déjà remisé ou à un autre prix, ajouter pendant la frappe d'une quantité, cocher deux fois un service. Le lot S8-L (tableau et douchette) date du 18/10/2026 : une copie plus ancienne chez l'éditeur montrerait l'ancien comportement (une ligne vide par ajout). L'analyse demande l'écran et le geste, pas la date de la copie.

**Correction.** Remplacer le point 6 du § 0 par :
> « Avant toute question, demander à l'éditeur : l'écran (caisse, facture ou devis), le geste (tableau « Ajouter un produit », douchette, liste « Produit » d'une ligne, « Ajouter un service »), et la date de sa dernière mise à jour du logiciel (dernier `git pull`), ou une capture d'écran. Si sa copie date d'avant le 18/10/2026 (lot S8-L), le cas qu'il décrit est déjà en partie corrigé ; le lot ne traite alors que les quatre gestes qui donnent encore deux lignes. »

### L6. Cas limites manquants

**Constat.**
1. **Une ligne modifiée à la main devient identique à une autre** : un responsable efface la remise de « Câble × 1, 10 % », ou ramène son prix à celui de la fiche, alors qu'une autre ligne « Câble × 1 » existe. Le § 3 ne traite que l'autorisation retirée (cas 3). Avec le refus du domaine, l'écran doit savoir quoi faire.
2. **« Mêmes conditions » n'est pas défini colonne par colonne.** Pour un produit : `produit_id`, `prix_unitaire_ttc`, `remise_pourcentage` (le taux vient de la fiche). Pour un service : `service_id`, prix comparé dans le contexte de la vente (`Service::prixDeReferenceDans()`), `taux_tva_du_prix`, `remise_pourcentage`. `DerogationsDePrix::cle()` compare déjà ces colonnes, plus la quantité.
3. **Conversion d'un devis en facture** : `CreerFactureDepuisDevisAction` recopie les lignes sans `construireToutes()` (l. 92-93). Le § 5.1 ne dit pas que ce chemin repose sur le contrôle déjà fait au devis.
4. **Société de services** (TA) : seule Q-L5 s'y applique (C2).

**Correction.** Ajouter au § 3 :
> « 15. Une ligne modifiée à la main devient identique à une autre ligne du même article (remise effacée, prix ramené à celui de la fiche ou du prix de référence) : l'écran les réunit aussitôt, la ligne la plus haute garde sa place et reçoit la somme des quantités, avec le message « « Câble » : les deux lignes sont réunies (quantité 2) » ; rien ne s'inscrit au journal des événements (`LignesRetirees` compare par article) ; le domaine refuse ce qui arrive quand même. (Choix d'écran, D7.)
> 16. « Mêmes conditions » : pour un produit, même `produit_id`, même PU TTC, même remise ; pour un service, même `service_id`, même prix dans le contexte de la vente, même taux du prix, même remise (les colonnes de `DerogationsDePrix::cle()`, sans la quantité).
> 17. Devis converti : `CreerFactureDepuisDevisAction` recopie ses lignes telles quelles ; il n'a pas besoin du contrôle, déjà fait par `CreerDevisAction` et `ModifierDevisAction`.
> 18. Société de services : ni tableau des produits ni douchette (lot TA) ; seule la règle des services s'applique. »

Et au § 5.2 :
- `test_une_ligne_devenue_identique_a_une_autre_est_reunie_a_l_ecran`
- `test_dans_une_societe_de_services_cocher_un_service_deja_present_ajoute_un_a_sa_quantite` (selon Q-L5)

### L7. Le double envoi ne doit pas dépendre d'un test à venir

**Constat.** § 1.1 et § 5.1 : « vider la sélection après l'envoi si le double envoi est confirmé ». Le lot devrait donc deviner selon un résultat. Lecture du code : `RecoitLesProduitsChoisis::recevoirLesProduitsChoisis()` ferme la fenêtre après un ajout réussi (`unmountFormComponentAction()`), et la vue appelle `<x-filament::button wire:click="ajouterLaSelection">`. Dans Filament 3, ce bouton se désactive sans doute pendant sa requête (à vérifier dans `vendor/filament/support/resources/views/components/button/index.blade.php`, absent de la copie). Le risque est faible, mais vider la sélection ne coûte rien.

**Correction.** Remplacer, au § 1.1 et au § 5.1, « si le double envoi est confirmé » par :
> « `ajouterLaSelection()` vide la sélection juste après l'envoi de l'événement (`$this->selection = []`), dans tous les cas : un second envoi n'ajoute rien. Le test `test_un_second_envoi_de_la_selection_n_ajoute_pas_les_produits_deux_fois` le prouve, sans condition. »

### L8. Le texte proposé pour `CLAUDE.md` est à compléter

**Constat.** Le texte du § 4.1 ne parle ni des services sans prix de référence, ni de la fusion d'une ligne devenue identique, ni de la phrase « deux lignes identiques » à remplacer sans condition (L2).

**Correction.** Remplacer le texte proposé par (variante selon Q-L2 et Q-L5, entre crochets) :
> « Un produit n'occupe qu'une ligne d'un ticket, d'une facture ou d'un devis : le choisir de nouveau (tableau des produits, douchette, nouveau clic sur sa rangée) augmente la quantité de sa ligne, qui garde sa place ; [Q-L2 a : un article ajouté à un produit dont la ligne porte une remise ou un autre prix que sa fiche arrive sur une nouvelle ligne, au prix de sa fiche]. Un service coché de nouveau, au même prix, au même taux et sans remise, augmente de même la quantité de sa ligne ; [Q-L5 a : un service sans prix de référence arrive toujours sur une nouvelle ligne]. Deux lignes qui deviennent identiques se réunissent, et aucun document ne s'enregistre avec deux lignes du même article aux mêmes conditions. Un avoir garde une ligne par ligne du document qu'il corrige, une facture d'achat une ligne par ligne de la facture du fournisseur, et un inventaire d'ouverture une ligne par produit. »

Et, dans « Prix et TVA des ventes », le remplacement de L2.

### L9. Points mineurs

- § 1.3, exemple d'achat (1,47 € contre 1,48 € HT) : juste. `CalculateurLigne::chiffrerUnAchat()` passe par `montantNetCentimes()`, qui arrondit la remise (0,495 € → 0,50 €), pas le net. Aucune correction.
- § 1.1 : `RecoitLesProduitsChoisis` n'a que 43 lignes ; « l. 823-848 » est dans `AjouteDesLignesDeVente` (`ajouterLeProduitScanne()`). Écrire « `AjouteDesLignesDeVente::ajouterLeProduitScanne()`, l. 823-848, appelé par `RecoitLesProduitsChoisis::scannerUneReference()` ».
- Q-L4 (a) : chaque clic peut dépasser le stock restant, que le tableau ne compare pas (§ 2.3). Ajouter : « la quantité choisie n'est pas comparée au stock dans le tableau ; seul l'enregistrement (`VerificateurDeStock`) la refuse ou demande la confirmation, comme aujourd'hui avec le champ de quantité. »

---

## 3. `analyse-a-faire.md`

### A1. L'expert-comptable de la société n'existera plus

**Constat.** Le tableau du § 4.3 a une colonne « Expert-comptable », Q-AF2 (a) lui donne « À surveiller », et le § 6.3 a `test_l_expert_comptable_n_a_rien_a_faire`. Après le lot EC, ce compte disparaît (`decision-espace-cabinets.md`, règle 7) ; un collaborateur du cabinet lié entre dans les magasins réels du client « comme s'il y était connecté », avec les autorisations que le client accorde (C2). Le § 2 J renvoie l'« À faire » du cabinet au lot EC, alors que le lot EC passera avant la série 9.

**Correction.**
- § 4.3 : renommer la colonne « Collaborateur du cabinet lié » et la remplir : A1 à A3, A5, A6, A7, B1 à B4 « À surveiller, s'il a la consultation de ces documents » ; D1, D2 « À faire si le propriétaire du client lui a ajouté la validation des achats et la création des produits (client « Commerce ou atelier »), sinon À surveiller » ; C1 à C3, D3, D4, G1, G2 « Non » (ni caisse, ni clôtures, ni alertes de stock) ; F2, F3 « Non ».
- § 2 J, dernier point : remplacer « l'« À faire » du cabinet est à prévoir avec ce lot » par une question (Q-AF11 du § 4).
- § 6.3 : remplacer `test_l_expert_comptable_n_a_rien_a_faire` par `test_le_collaborateur_du_cabinet_lie_ne_voit_que_ce_que_le_client_lui_accorde_et_jamais_la_caisse_ni_le_stock`.

### A2. Les sociétés de services (TA) ne sont pas traitées

**Constat.** Voir C2. Le § 4.2 propose les groupes « Stock » et « Justifier les ventes hors de France » pour tout magasin. Les tests du § 6.3 ne couvrent pas une société de services.

**Correction.** Ajouter au § 4.2 : « Dans une société de services, les groupes « Justifier les ventes hors de France » et « Stock », ainsi que les achats et l'inventaire, n'existent pas. » Ajouter au § 6.3 : `test_dans_une_societe_de_services_aucune_ligne_de_stock_d_achat_ni_de_preuve`. Dans E1, remplacer « `produit.update` » par « `service.update` (lot TA) » là où il s'agit d'un service.

### A3. La ligne « factures à encaisser » contredit `CLAUDE.md`

**Constat.**
- `CLAUDE.md` (« Vendeurs ») : le tableau de bord « signale en alerte les factures à encaisser de sa portée (nombre, restant dû, dont en retard, **et celles dont un paiement a été contre-passé**) ». L'exemple du § 4.2 (« 3 factures à encaisser · 1 240,00 € restant dû, dont 1 en retard (450,00 €) ») n'a pas les contre-passées. Le point 16 de la série 8 en est la raison même : « un paiement peut être enregistré puis contre-passé, et la facture rester ensuite impayée pour toujours ».
- Le § 4.2 et le § 6.2 disent « fusion dans l'« À faire » ou maintien dessous, selon Q-AF1 ». Q-AF1 ne pose pas ce choix.

**Correction.**
- § 4.2, exemple : « 3 factures à encaisser · 1 240,00 € restant dû, dont 1 en retard (450,00 €) et 1 dont un paiement a été contre-passé ».
- § 4.2, « Place » : décider (présentation, D7) et l'écrire : « L'encart `FacturesAEncaisserApercu` devient le groupe « Encaisser » de l'« À faire » : sa ligne de totaux, puis ses dix factures les plus anciennes par échéance (numéro cliquable, client, restant dû, échéance, badge « Paiement contre-passé »), puis le lien « Voir toutes les factures à encaisser ». » Retirer « selon Q-AF1 » du § 4.2 et du § 6.2.
- § 6.3 : `test_la_ligne_a_encaisser_dit_le_nombre_le_restant_du_le_retard_et_les_contre_passees`.

### A4. Deux options contredisent `CLAUDE.md` et les mots de l'éditeur

**Constat.**
- Q-AF2 (c) : « le propriétaire ne verrait plus les factures à encaisser ». `CLAUDE.md` les montre au propriétaire.
- Q-AF4 (c) : « Les vendeurs ne voient jamais les factures à encaisser ». Le point 16 de la série 8, mot de l'éditeur : « un vendeur les voit s'il est autorisé à faire des contre-passations ».
- Q-AF4 dit « règle de la série 8 » sans citer ces mots. L'option (a) les renverse : l'éditeur doit le savoir.

**Correction.**
- Q-AF2 (c) : ajouter « (contraire à `CLAUDE.md` : il faudrait réécrire la puce « Vendeurs ») », ou retirer l'option.
- Q-AF4 : retirer (c) ; écrire le contexte avec la citation (point 16 de `serie8/demande-editeur.md`, traduction de référence de vos retours du 17/10/2026) : « Le 17/10, vous avez demandé : « un vendeur les voit s'il est autorisé à faire des contre-passations ». Par défaut, un vendeur ne l'est pas : il ne voit donc pas au tableau de bord une facture qu'il a laissée partiellement payée. L'option (a) change votre règle de la série 8. »

### A5. Q-AF1 et Q-AF8 : la demande répond déjà en partie

**Constat.** La demande : « tout ce qui est en attente doit y figurer » ; « s'il y a quelque chose à faire ou resté inachevé, cela doit apparaître ». Q-AF1 recommande une sélection (a) sans dire qu'elle laisse de côté des choses en attente. Q-AF8 (masquer) est tranchée par la même phrase.

**Correction.**
- Q-AF1 : ne demander que les cas limites (texte au § 4, Q-AF1).
- Q-AF8 : retirer ; écrire « Tranché par la demande (« tout ce qui est en attente doit y figurer ») : rien ne se masque ; seule la couleur change avec l'urgence. »

### A6. Incohérences internes

**Constat.**
1. **E-mail en échec (A8)** : Q-AF9 (a) le met dans l'« À faire » ; le § 4.7 le laisse « pour une seconde passe » ; le § 4.2 le classe en rouge.
2. **Ordre** : le § 4.2 donne des « Groupes, dans cet ordre » et une « Urgence […] dans chaque couleur, le plus ancien d'abord ». On ne sait pas si la liste se trie par groupe ou par couleur. A9 (vente en cours) est dans le groupe « Terminer » et en gris.
3. **« Le plus ancien d'abord »** : la date n'est pas dite pour chaque élément (échéance ? émission ? création ?).
4. **Cases incomplètes du § 4.3** : « Si `facture.envoyer` », « Si coché », « Selon ses cases » ne disent pas ce que voit la personne sinon (« À surveiller » ou rien), alors que Q-AF2 (a) donne « À surveiller » à qui voit toute l'équipe.
5. **Observateur** (§ 1.2 et § 1.4 point 7) : par défaut, il a `facture.view` mais ne crée aucune facture (`RoleMagasin::permissionsParDefaut()`) ; `Facture::scopeVisiblesPar()` ne lui montre que les siennes, donc aucune : il ne voit **pas** le nombre jaune. Il ne le voit qu'avec « voir celles de toute l'équipe ».
6. **Comptes** : § 0 « une quarantaine », Q-AF1 « une cinquantaine » (le § 2 en liste 51).
7. **§ 1.3** : la livraison vers un DOM impose aussi le transport par le magasin (`NatureOperation::transportImpose()`) et demande aussi le MRN ou le CN23. « C'est exactement la nature Export hors UE » est trop sûr.
8. **§ 2 J** : « pas dans le code de fa83824 » est inexact pour la transmission : `SubmitFactureToPdpJob`, `StatutTransmissionPdp::Rejetee` et `DeclencherTransmissionPdpApresEmissionFacture` existent ; aucun écran ne crée de `MandatPdp`, donc rien n'est transmis en pratique.

**Correction.**
1. Choisir une seule position : A8 dans l'« À faire » dès le premier lot, fin « tant qu'aucun envoi plus récent du même document n'a réussi ni n'est en cours » (choix technique, D7), urgence orange. Corriger le § 4.2, le § 4.7 et Q-AF9 en ce sens.
2. Écrire au § 4.2 : « La liste se trie d'abord par urgence (rouge, orange, gris), puis, dans chaque couleur, par date (colonne « Date de tri » du tableau de A8 ci-dessous) ; le groupe ne s'affiche que comme une étiquette à gauche de chaque ligne. »
3. Voir A8 (tableau complet).
4. Réécrire chaque case du § 4.3 avec l'un de ces trois mots seulement : « À faire », « À surveiller », « Non », suivi de sa condition exacte (autorisation et portée). Exemple pour A4, administrateur : « À faire avec `facture.envoyer` ; sinon À surveiller s'il a `facture.view_toutes` ; sinon Non. »
5. § 1.2 et § 1.4 point 7 : « un observateur à qui l'on a coché « voir celles de toute l'équipe » (sans « noter les preuves ») ou un expert-comptable ».
6. Écrire « une cinquantaine » partout.
7. § 1.3 : « C'est la nature « Export hors UE expédié par le magasin » (ou « Livraison vers un DOM », qui impose aussi le transport par le magasin et demande aussi le MRN ou le CN23). »
8. § 2 J : « La transmission existe dans le code (passerelle factice), mais aucun écran ne crée de mandat (`MandatPdp`) : rien n'est transmis tant que l'étape 2 n'est pas faite. »

### A7. Éléments en attente oubliés

**Constat.**
1. **Aucun poste reconnu** (G5) : `PostesReconnus` (docblock) : « Tant que le mot de passe d'un compte est encore son identifiant, il n'ouvre ce compte que depuis un poste reconnu ». Un magasin sans poste reconnu, dont un vendeur ou un observateur a encore son identifiant pour mot de passe, a des comptes qui ne peuvent pas se connecter. Seuls le propriétaire et un responsable reconnaissent un poste (`PosteReconnuPolicy`, `PorteeDesDroits::gereLEquipeDe()`).
2. **Détaxe en attente du visa** : K1 dit que rien n'est en attente parce que PILIQI ne sait pas quel ticket sera détaxé. Mais une fois le n° du bordereau noté (`bordereaux_detaxe`), le ticket attend son visa, et `CLAUDE.md` dit « la TVA n'est régularisée qu'après le visa ». La table n'a pas de colonne pour le visa (migration `create_bordereaux_detaxe_table`) : le suivre serait une donnée nouvelle.
3. **Catalogue vide** (E4) : dans une société « Commerce ou atelier », un produit ne naît que d'une facture d'achat validée ou d'un inventaire d'ouverture, et l'inventaire ne se saisit que jusqu'à la première vente (`InventaireOuverturePolicy::create()`). Un magasin sans produit ni service ne peut rien vendre.
4. **Magasin fermé ou cédé** : `PermissionRegistry::reserveesAuxOperationsCommerciales()` contient `facture.justifier`. Dans un magasin en lecture seule, une preuve d'export arrivée après la fermeture ne se note plus, et la ligne A5 resterait pour toujours, sans geste possible. `CLAUDE.md` dit pourtant que « clôtures, statuts et déclarations continuent jusqu'au bout ». C'est une règle métier à trancher.
5. **Éléments sans fin** : H8 (nomination par un administrateur : « pas de délai », aucune façon de la « garder ») et H9 (écart Sirene : « aucun état « traité » ») resteraient pour toujours, ce que Q-AF8 (a) interdit de masquer.
6. **« Jamais connectés »** (G2) : `EquipeDuMagasinResource` a le filtre `mot_de_passe_identifiant` mais aucun filtre « jamais connecté » : la « liste filtrée » promise n'existe pas.

**Correction.** Ajouter au § 2 :
- G5 : « Aucun poste reconnu alors qu'un compte de vendeur ou d'observateur du magasin a encore son identifiant pour mot de passe : ces comptes ne peuvent pas se connecter. Qui : le propriétaire et les responsables du magasin. Fin : un poste reconnu, ou plus aucun compte dans ce cas. Clic : `PosteReconnuResource`. »
- K1 : remplacer par « Avant le bordereau, rien n'est en attente. Après le bordereau noté, le ticket attend son visa ; PILIQI n'enregistre pas le visa : voir Q-AF13. »
- E4 : « Magasin « Commerce ou atelier » sans aucun produit ni service actif : rien ne peut se vendre. Qui : qui peut valider les achats et créer des produits. Clic : inventaire d'ouverture (avant la première vente) ou facture d'achat. »
- Le cas du magasin fermé et les éléments sans fin deviennent Q-AF12 et Q-AF13 (§ 4).
- G2 : « Clic : liste de l'équipe filtrée « Mot de passe : l'identifiant » ; pour « jamais connecté », un filtre à créer dans `EquipeDuMagasinResource` et `MembreResource`, qui dit la même chose que le badge. »

### A8. Le lot devrait inventer les phrases, les liens et les filtres

**Constat.** Six exemples de phrases seulement (§ 4.2) pour une vingtaine d'éléments retenus. Plusieurs liens visent des filtres qui n'existent pas : devis « à facturer » et « expire bientôt » (`DevisResource` n'a que le filtre `statut`), « Stock bas » (`ProduitResource`, filtre `statut_stock` : `en_stock`, `epuise`, `negatif`), inventaires (`InventaireOuvertureResource` n'a aucun filtre), « jamais connecté » (A7). Le principe 3 (« la liste filtrée montre exactement ce nombre de lignes ») exige que chaque filtre dise la même chose que le compte.

**Correction.** Ajouter un § 4.8 « Fiche de chaque élément retenu », un tableau à remplir entièrement avant le lot. Colonnes : clé ; phrase au singulier ; phrase au pluriel ; bouton ; lien s'il y en a un seul ; lien s'il y en a plusieurs (méthode de la ressource à créer, du type `FactureResource::urlDesFacturesAEncaisser()`) ; filtre (existant, ou à créer avec le scope qu'il partage avec le compte) ; date de tri ; urgence ; fin. Exemples de lignes à écrire telles quelles :

| Clé | Singulier | Pluriel | Bouton | Un seul | Plusieurs | Filtre | Date de tri | Fin |
|---|---|---|---|---|---|---|---|---|
| A5 | « 1 vente hors de France attend sa preuve d'export : le n° MRN de la déclaration en douane, ou le n° du CN23 de La Poste. » | « 3 ventes hors de France attendent leur preuve d'export ou de livraison. » | « Ajouter la preuve » | fiche de la facture, section « Preuves de l'exonération » | `FactureResource::urlDesPreuvesAFournir()` | `preuves_a_fournir` (existe) | date d'émission | plus rien ne manque, ou vente entièrement corrigée par avoir |
| A7 | « 1 brouillon de facture pas encore émis, du 21/10. » | « 2 brouillons de facture pas encore émis, le plus ancien du 21/10. » | « Émettre » | page de modification | `FactureResource::urlDesBrouillons()` | `statut` = Brouillon (existe) | date de création | émis ou supprimé |
| B4 | « 1 devis accepté n'est pas encore facturé. » | « 2 devis acceptés ne sont pas encore facturés. » | « Convertir en facture » | fiche du devis | `DevisResource::urlDesDevisAFacturer()` | à créer : statut Accepté et aucune facture (`Devis::factures()`) | date d'acceptation | converti |
| D4 | « 1 produit est au seuil d'alerte ou dessous. » | « 4 produits sont au seuil d'alerte ou dessous. » | « Voir » | fiche du produit | `ProduitResource` filtré | à créer : option `bas` de `statut_stock`, scope `Produit::scopeEnAlerteStock()` qui dit la même chose que `enAlerteStock()` | quantité la plus basse d'abord | quantité au-dessus du seuil |
| G5 | « Aucun poste n'est reconnu : 2 comptes qui ont encore leur identifiant pour mot de passe ne peuvent pas se connecter. » | (même phrase) | « Reconnaître ce poste » | `PosteReconnuResource` | — | — | — | un poste reconnu |

Et une ligne par élément retenu (A1 à A8, B1 à B4, C1 à C3, D1 à D4, E4, F2, F3, G1, G2, G5, et ceux de la société). Les nombres s'écrivent avec `trans_choice`, comme `TableauDeBord::etapes()`.

### A9. L'espace de la société : qui voit quoi, et le texte de `CLAUDE.md`

**Constat.**
- Le § 4.4 liste H1 à H10 sans dire qui voit chaque ligne. Or `CLAUDE.md` réserve au propriétaire l'information « mot de passe encore égal à l'identifiant » (« la liste de l'équipe l'indique au propriétaire et, pour un vendeur ou un observateur, au responsable de son magasin ») ; H5 et H6 suivent des autorisations de société (`societe.magasins.gerer`, `societe.parametres.gerer`).
- Q-AF6 (a) dit « un administrateur qui voit ces documents » sans dire, pour chaque groupe, quelle autorisation compte dans quel magasin. `FacturesAEncaisserDeLaSociete` a une règle précise (« voit toutes les factures de ce magasin ») ; il en faut une par groupe.
- Le § 6.2 annonce un texte de `CLAUDE.md` « à soumettre », sans le donner. `analyse-lignes.md`, elle, le donne.

**Correction.**
- § 4.4 : un tableau H1 à H10 × (propriétaire, administrateur) avec « À faire », « À surveiller » ou « Non » et la condition. Exemples : H2, H3, H4 « propriétaire seul » ; H5 « propriétaire ; administrateur avec `societe.magasins.gerer` pour corriger le NIC d'un magasin de sa portée » ; H6 « propriétaire ; administrateur de tous les magasins avec `societe.parametres.gerer` » ; comptes au mot de passe égal à l'identifiant « propriétaire seul ».
- Q-AF6 (a) : ajouter la règle par groupe : « preuves, brouillons et factures : seulement dans un magasin où il voit toutes les factures (`facture.view_toutes`) ; achats et inventaires : `facture_achat.view` ; stock : `produit.view` ; caisses et journées : jamais (déjà sur la page « Les caisses ») ».
- § 6.2 : donner les deux textes à soumettre, à compléter selon les réponses :
  > Puce « Vendeurs » : « Le tableau de bord dit le rôle de la personne après « Bonjour », puis une partie « À faire » : tout ce qui attend un geste dans sa portée, chaque ligne avec son nombre, ce qui manque et pourquoi, et un lien vers la fiche, ou vers la liste filtrée qui montre exactement ces documents ; ce qu'elle voit sans pouvoir le faire y figure « à surveiller », sans bouton d'action. Les factures à encaisser y gardent leur nombre, leur restant dû, celles en retard et celles dont un paiement a été contre-passé, pour [portée selon Q-AF4]. »
  > Section « Produit », lectures permises (si Q-AF6 = a) : « au tableau de bord de l'espace d'administration, pour le propriétaire et, dans sa portée, un administrateur, le nombre des choses à faire de chaque magasin réel, par sorte (preuves d'exonération à fournir, brouillons, achats et inventaires à valider, stock négatif ou bas), chacune seulement dans un magasin où il voit les documents qu'elle compte, sans aucun nom de client ni numéro de document ; ».

### A10. La tuile du stock négatif change de public sans le dire

**Constat.** `StockNegatifApercu::canView()` la montre à qui a `produit.view`. Le § 4.3 met D3 « si `produit.ajuster_stock` » pour les administrateurs, vendeurs et observateurs. Le § 4.7 retire la tuile. Un vendeur qui a `produit.view` (précoché) mais pas `produit.ajuster_stock` ne verrait plus le stock négatif au tableau de bord. `CLAUDE.md` (« la quantité négative reste visible jusqu'à sa régularisation ») reste respecté par la liste des produits, mais le changement doit être dit.

**Correction.** Au § 4.3, D3 : « À faire avec `produit.ajuster_stock` ou la validation des achats ; sinon À surveiller avec `produit.view` (comme la tuile d'aujourd'hui) ; sinon Non. »

### A11. Options sans exemple chiffré

**Constat.** DIRECTIVES § 3.3 : « un exemple chiffré, et ce que chaque option change pour un magasin ». Q-AF1, Q-AF2, Q-AF3, Q-AF4, Q-AF7, Q-AF9 et Q-AF10 n'en ont pas ; Q-AF5 et Q-AF8 donnent une situation sans dire ce que chaque option en fait.

**Correction.** Utiliser les textes du § 4, qui ont chacun leur exemple.

### A12. Hors périmètre, à signaler au lot 21

**Constat.** G4 : la cloche du panneau d'un magasin n'est pas filtrée par magasin (`AppPanelProvider::panel()`, `databaseNotifications()` ; `NotificationDuMagasin` met le nom du magasin dans le titre). Un compte de plusieurs magasins y lit les noms de ses autres magasins. Cela peut heurter la règle 2 de `decisions/reponses-regles-ecran-26-10.md` (« Aucun écran ni aucun message du panneau d'un magasin ne laisse deviner qu'il existe d'autres magasins de la même société »).

**Correction.** Ajouter au § 3.5 : « 10. La cloche du panneau d'un magasin montre les avis des autres magasins du même compte : à vérifier par le lot 21 contre la règle 2 de `reponses-regles-ecran-26-10.md`. L'« À faire » ne lit jamais les notifications et ne l'aggrave donc pas. »

---

## 4. Liste corrigée des questions à poser

À poser en une page privée d'arbitrages, en vouvoyant l'éditeur. Chaque question : un identifiant, le contexte en deux phrases, des options a, b, c, l'option recommandée marquée « (Recommandé) », un exemple chiffré.

### Demande 1 (lignes)

**Q-L0 (précision, avant tout).** « Sur quel écran avez-vous vu deux lignes du même produit (caisse, facture ou devis), par quel geste (tableau « Ajouter un produit », douchette, liste « Produit » d'une ligne, « Ajouter un service »), et de quand date votre copie du logiciel (dernier `git pull`) ? Une capture d'écran suffit. »

**Q-L2.** Texte de L4, options (a), (b), (c) de l'analyse, exemple existant (câble à 10,00 €).

**Q-L5.** Inchangée (elle a son exemple). Ajouter au contexte : « Dans une société de services, qui ne vend que des services, c'est la seule règle qui s'applique. »

**Texte de `CLAUDE.md`** : celui de L8 et le remplacement de L2, soumis pour accord.

### Demande 2 (À faire)

**Q-AF1. Les cas limites de « tout ce qui est en attente ».**
Contexte : vous demandez que tout ce qui est en attente figure dans l'« À faire ». Certaines choses se font toutes seules, ou ne sont que des informations.
- a) Tout ce qui demande un geste, plus, en gris, ce qui se fera tout seul (votre caisse ouverte, la journée du jour pas encore clôturée, une vente commencée à la caisse) ; sans les informations (services sans prix, produits à 0,00 €, relances automatiques désactivées). (Recommandé)
- b) Comme (a), sans ce qui se fera tout seul.
- c) Comme (a), avec aussi les informations.
- Exemple : à 18 h, Léa a sa caisse ouverte, la journée n'est pas clôturée, et le service « Réparation » n'a pas de prix. (a) deux lignes grises (« Votre caisse est ouverte », « La journée du 26/10 sera clôturée à minuit si personne ne le fait avant ») ; (b) rien ; (c) les deux lignes grises et « 1 service sans prix : vos vendeurs ne peuvent pas le vendre ».

**Q-AF2. « À faire » et « À surveiller ».** Options de l'analyse. Exemple : une facture de 120,00 € faite par le vendeur Marc, échue depuis trois jours. (a) Marc la voit en « À faire » (bouton « Encaisser ») ; le propriétaire et le responsable en « À surveiller » (bouton « Voir ») ; (b) tous la voient dans une seule liste, Marc avec le bouton ; (c) seul Marc la voit (le propriétaire ne la verrait plus : contraire à `CLAUDE.md`, qu'il faudrait réécrire).

**Q-AF4.** Texte de A4, options (a) et (b). Exemple : Marc encaisse 50,00 € sur une facture de 120,00 €. (a) il voit « 1 facture à encaisser · 70,00 € restant dû » ; (b) il ne voit rien tant qu'on ne lui a pas coché la contre-passation.

**Q-AF5. Quand un brouillon ou un devis devient « à faire ».** Options de l'analyse. Exemple : un vendeur prépare une facture le 26/10 à 10 h ; un devis envoyé le 01/10 est valable jusqu'au 31/10 inclus (jours du magasin). (a) la facture apparaît à 10 h ; le devis apparaît le 24/10 ; (b) la facture apparaît le 27/10 à 0 h ; le devis le 28/10 ; (c) aucun des deux.

**Q-AF6.** Options de l'analyse, avec la règle par groupe et le texte de `CLAUDE.md` de A9. Exemple : Lyon a 2 preuves à fournir et 1 achat à valider ; Paris, 3 produits en stock négatif. (a) le tableau « Vos magasins » montre « Lyon : 2 preuves · 1 achat » et « Paris : 3 stocks négatifs », sans aucun nom de client ni numéro ; (b) rien de plus qu'aujourd'hui ; (c) « Lyon : 3 » et « Paris : 3 ».

**Q-AF7.** Options de l'analyse, avec la phrase de C4. Exemple : 1 facture attend son MRN, 3 sont à encaisser, 2 brouillons. (a) aucun nombre au menu ; la liste des factures a les onglets « À encaisser 3 », « Preuves à fournir 1 », « Brouillons 2 » ; (b) le menu affiche 6 ; (c) le menu affiche 1, et la liste s'ouvre sur « Preuves à fournir » ; (d) un nombre sur chaque menu concerné.

**Q-AF10. Le délai des preuves d'export (MRN ou CN23).** Options de l'analyse. Exemple : facture d'export émise le 02/10. (a) orange jusqu'au 01/11, rouge à partir du 02/11 ; (b) orange tant que la preuve manque ; (c) rouge dès le 03/10. Rappeler que ce délai est un rappel interne, à faire confirmer par l'expert-comptable de la société.

**Q-AF11 (nouvelle). Le cabinet lié.**
Contexte : après le lot des cabinets, un collaborateur du cabinet entre dans vos magasins avec les autorisations que vous lui accordez. Son tableau de bord réunit déjà, pour chaque client, les factures à encaisser de chaque magasin.
- a) Dans un magasin, il voit l'« À faire » selon ses autorisations (en « À surveiller » pour ce qu'il ne peut pas faire) ; son tableau de bord du cabinet ne montre que les factures à encaisser, comme prévu. (Recommandé)
- b) Comme (a), et son tableau de bord du cabinet montre aussi le nombre des choses à faire de chaque magasin de chaque client (une lecture de plus, à écrire dans `CLAUDE.md`).
- c) Il ne voit aucun « À faire ».
- Exemple : votre cabinet a la consultation des factures ; Lyon a 2 preuves à fournir. (a) dans Lyon, il voit « 2 ventes hors de France attendent leur preuve » en « À surveiller » ; (b) en plus, son tableau de bord montre « Lyon : 2 preuves » ; (c) rien.

**Q-AF12 (nouvelle). Une preuve d'export reçue après la fermeture du magasin.**
Contexte : aujourd'hui, un magasin fermé, cédé ou suspendu ne peut plus noter de preuve. La vente faite avant la fermeture reste pourtant exonérée sur sa facture, et sans preuve elle devrait être corrigée à la TVA française.
- a) Noter une preuve reste possible dans un magasin en lecture seule, comme les clôtures et les déclarations qui continuent jusqu'au bout. (Recommandé, à faire confirmer par l'expert-comptable de la société)
- b) Non : la ligne reste dans l'« À faire », sans bouton.
- c) Non, et la ligne disparaît à la fermeture.
- Exemple : export de 500,00 € HT le 20/10 ; magasin fermé le 25/10 ; MRN reçu le 28/10. (a) le responsable le note le 28/10, la ligne disparaît ; (b) la ligne reste pour toujours ; (c) plus rien ne rappelle la preuve.

**Q-AF13 (nouvelle). Ce qui n'a pas de fin aujourd'hui.**
Contexte : trois choses n'ont aujourd'hui aucun moyen d'être « faites » : la nomination d'un responsable par un administrateur (vous pouvez l'annuler, mais pas la « garder »), un écart relevé au répertoire Sirene après la validation, et un ticket détaxé qui attend le visa de son bordereau (PILIQI n'enregistre pas le visa).
- a) Un bouton « Garder » pour la nomination, « C'est vérifié » pour l'écart Sirene, et une date de visa à noter sur le ticket détaxé : chaque ligne disparaît une fois ce geste fait, inscrit au journal d'audit. (Recommandé)
- b) Ces trois choses restent seulement dans la cloche des notifications, pas dans l'« À faire ».
- c) Elles restent dans l'« À faire » pendant 30 jours, puis disparaissent.
- Exemple : un administrateur nomme Paul responsable de Lyon le 26/10. (a) « Paul a été nommé responsable de Lyon par Julie » jusqu'à ce que vous cliquiez « Garder » ou « Annuler la nomination » ; (b) seulement l'avis du 26/10 dans la cloche ; (c) la ligne disparaît le 25/11.

---

## 5. Ce qu'un lot doit avoir en main avant de commencer

1. La place du lot et le code de départ (C1) ; les règles de TA et EC appliquées (C2, A1, A2).
2. Les réponses de l'éditeur, écrites dans `serie9/reponses-serie9.md`, et les textes de `CLAUDE.md` approuvés (L8, A9).
3. Pour les lignes : la définition de « mêmes conditions » (L6), le cas de la ligne devenue identique (L6), la sélection vidée après l'envoi (L7), l'inventaire complet des tests à réécrire (L1, L2).
4. Pour l'« À faire » : le tableau complet du § 4.3 avec trois mots seulement (A6), la fiche de chaque élément (A8), les filtres à créer avec leur scope partagé, l'ordre de tri (A6), le sort de l'encart des factures à encaisser (A3), le tableau de la société (A9).
5. Les tests à ajouter en plus de ceux des analyses : société de services (L6, A2), collaborateur du cabinet lié (A1), ligne « à encaisser » avec les contre-passées (A3), poste reconnu (A7), magasin fermé selon Q-AF12, chaque élément de Q-AF13 selon la réponse.
