# Série 9, demande 1 : « un produit = une ligne »

Analyse du 26/10/2026, faite sur la copie figée du code au commit `fa83824` (`scratchpad/code-fa83824`, lecture seule, aucun test lancé). Les chemins `app/…`, `tests/…`, `resources/…` sont relatifs à cette copie ; les numéros de ligne sont ceux de cette copie.

Règles suivies : le `CLAUDE.md` de la copie, lu en entier. Le § 7 de `pilotage-reprise/README.md` liste les décisions plus récentes qui priment : aucune ne porte sur les lignes des documents. Décisions de la série 8 relues : `notes/serie8/demande-editeur.md` (point 13), `notes/serie8/ecrans-de-vente.md` (§ 6.2), `notes/serie8/plan-serie8.md` (lot S8-L), `notes/serie8/reponses-serie8.md`.

La demande (traduction de `serie9/demande-editeur.md`, point 1) : « Quand on choisit les produits à vendre, il ne doit pas être possible d'ajouter deux fois le même produit : si on clique deux fois sur un produit, seule la quantité augmente, au lieu de faire apparaître deux lignes. »

Rien n'est tranché ici. Les règles s'écriront dans `serie9/reponses-serie9.md`, après les réponses de l'éditeur aux questions du § 4.2.

---

## 0. En bref

1. La fusion existe déjà en partie, depuis le lot S8-L (18/10/2026) : le grand tableau des produits et la douchette ajoutent la quantité à la ligne du même produit, **mais seulement** si cette ligne est au prix de la fiche et sans remise.
2. Deux lignes du même article apparaissent encore dans six cas, tous dans le trait `AjouteDesLignesDeVente` (caisse, facture, devis) :
   - la liste « Produit » de chaque ligne ne regarde jamais les autres lignes (c'est la « ligne à remplir » que le point 13 de la série 8 voulait remplacer par le grand tableau) ;
   - le produit est déjà sur une ligne remisée, ou à un autre prix que sa fiche ;
   - « Ajouter un service » crée toujours une nouvelle ligne, même pour un service déjà présent ;
   - la ligne existante est en cours de frappe (quantité ou prix illisible) ;
   - le prix de la fiche a changé depuis l'ajout de la ligne ;
   - peut-être un double clic sur « Ajouter le produit » du tableau (à vérifier par un test).
3. Dans le grand tableau, le deuxième clic sur un produit **le retire** de la sélection : c'est l'inverse de ce que décrit l'éditeur.
4. Les autres documents ont déjà leur règle : un avoir a une ligne par ligne d'origine ; un avoir fournisseur, une ligne par ligne d'achat ; un inventaire d'ouverture refuse un produit deux fois, mais seulement à l'enregistrement ; une facture d'achat accepte le même produit deux fois.
5. Fusionner ne change ni les totaux par taux, ni le contrôle du stock, ni le journal des événements. Cela change la répartition du HT entre les lignes (20,83 € + 20,84 € deviennent 41,67 €) et, sur une ligne remisée, peut changer le TTC d'un centime (exemple au § 2.4). D'où les questions du § 4.2.
6. Je ne sais pas sur quel écran l'éditeur a vu deux lignes. Précision à lui demander avec les questions : l'écran (caisse, facture ou devis) et le geste (liste « Produit » d'une ligne, grand tableau, douchette, « Ajouter un service »).

---

## 1. Où une ligne s'ajoute, et ce qui se passe aujourd'hui avec deux fois le même produit

### 1.1 Ventes : caisse, facture, devis

Les trois écrans partagent le même code : le trait `App\Filament\Concerns\AjouteDesLignesDeVente`, porté par `App\Filament\App\Resources\DevisResource` (l. 39 et 90) et appelé par `FactureResource` (l. 187, `DevisResource::lignesDeVente()`) et par la page `App\Filament\App\Pages\Caisse` (l. 407). Les pages `CreateFacture`, `EditFacture`, `CreateDevis`, `EditDevis` et `Caisse` reçoivent les produits choisis par le trait `App\Filament\Concerns\RecoitLesProduitsChoisis`.

Quatre gestes ajoutent une ligne :

| Geste | Code | Même produit déjà présent |
|---|---|---|
| Liste « Produit » d'une ligne (recherche par nom, référence ou code-barres) | `AjouteDesLignesDeVente::ligneSchema()`, `Select::make('produit_id')`, l. 274-319. Le répéteur s'ouvre avec une ligne vide (`defaultItems(1)`, l. 125) et n'a pas de bouton natif (`addable(false)`, l. 128). | **Deux lignes.** Le choix remplit la ligne au prix de la fiche (`ligneDuProduit()`, l. 697-713) sans regarder les autres lignes. On peut aussi remplacer le produit d'une ligne par celui d'une autre ligne. |
| « Ajouter un produit » : le grand tableau | `App\Filament\App\Livewire\SelecteurDeProduits` (événement `produits-choisis`) → `RecoitLesProduitsChoisis::recevoirLesProduitsChoisis()` → `AjouteDesLignesDeVente::ajouterLesProduitsChoisis()`, l. 775-811 | **Une ligne** si une ligne du produit est au prix de la fiche et sans remise (`ligneSansDerogationDuProduit()`, l. 857-882) : sa quantité augmente. **Deux lignes** sinon (ligne remisée, autre prix, ligne en cours de frappe). Aucun message ne dit que la quantité a augmenté. |
| Douchette « Scanner ou taper une référence » | `RecoitLesProduitsChoisis::scannerUneReference()` → `ajouterLeProduitScanne()`, l. 823-848 | Même règle que le tableau : c'est une sélection d'un article. |
| « Ajouter un service » | `actionAjouterUnService()` → `ajouterLesServices()`, l. 1024-1079 | **Toujours une nouvelle ligne**, même si le service est déjà sur une ligne au même prix. |

Dans le grand tableau (`SelecteurDeProduits`) :
- un clic sur une rangée appelle `basculer()` (l. 248-268) : le produit entre dans la sélection avec la quantité 1, ou **en sort s'il y était** (l. 252-256) ;
- la quantité se règle en bas de la fenêtre (`changerLaQuantite()`, l. 275-290) ; la croix retire le produit (`retirer()`, l. 292-295) ;
- une sélection ne porte jamais deux fois le même produit ; si une requête forgée le fait, `AjouteDesLignesDeVente::produitsChoisis()` cumule les quantités (l. 742) ;
- la sélection n'est pas vidée après l'envoi (`ajouterLaSelection()`, l. 316-327), et la page ajoute les produits à chaque événement reçu (`recevoirLesProduitsChoisis()`). Un double clic sur « Ajouter le produit » pourrait donc doubler la quantité de la ligne. **À vérifier par un test** au début du lot : je n'ai rien lancé.

Ce que prouvent les tests existants :
- `tests/Feature/Filament/SelecteurDeProduitsTest.php::test_un_produit_deja_present_sans_remise_voit_sa_quantite_augmenter` (l. 390) : 1 puis 2 câbles donnent une ligne de 3 ; après une remise de 10 % sur cette ligne, un câble de plus arrive sur une **nouvelle** ligne (`[[3, '10'], [1, '0']]`).
- `tests/Feature/Filament/CaisseTest.php::test_scanner_un_code_barres_ajoute_le_produit_sans_ouvrir_le_tableau` (l. 684) : deux scans donnent une ligne de 2.
- Aucun test ne couvre deux fois le même produit par la liste « Produit » d'une ligne, ni deux fois le même service.

Autres chemins qui portent des lignes de vente, sans ajout à la main :
- brouillon de facture ou devis rouvert : `lignesARouvrir()` (l. 236-259) puis `lignesAuxPrixPermis()` (l. 519-537), appelés par `EditFacture` (l. 52-54) et `EditDevis` (l. 61-63) ; les lignes reviennent telles qu'enregistrées, sans fusion ;
- panier de la caisse repris : `Caisse::mount()` et `remplirFormulaire()` (l. 173-187 et 304-313) reprennent les lignes du `PanierCaisse` telles quelles ; à chaque requête, `Caisse::rendering()` (l. 207-220) remet au prix du catalogue les lignes que le vendeur ne pourrait pas encaisser ;
- devis converti en facture : `CreerFactureDepuisDevisAction` recopie les lignes du devis telles quelles (l. 92-93).

### 1.2 Avoir (retours cherchés par numéro)

`App\Filament\App\Resources\AvoirResource` : on cherche un ticket ou une facture par son numéro ; l'écran propose ses lignes encore à rendre, une par ligne d'origine, sous leur `ligne_facture_id` (`lignesARendre()`, l. 258-271). Le répéteur n'a pas de bouton d'ajout (l. 139). Chercher de nouveau un document déjà dans l'avoir n'ajoute que ses lignes absentes (`lignesAvecLeDocument()`, l. 195-217). Le domaine refuse une même ligne deux fois (`CreerAvoirAction`, l. 80-87, testé dans `tests/Feature/Facturation/AvoirActionsTest.php`, l. 731).

Même produit : si le document a deux lignes du même produit (par exemple une remisée et une au prix de la fiche), l'avoir montre deux lignes, chacune liée à sa ligne d'origine. Aucun produit ne s'ajoute à la main.

### 1.3 Facture d'achat

`App\Filament\Concerns\AjouteDesLignesDAchat` (porté par `FactureAchatResource`) : « Ajouter un produit » ouvre un formulaire complet par ligne (`actionAjouterUnProduit()`, l. 166-182), pour un produit du catalogue ou un nouveau produit. Chaque formulaire validé ajoute **une nouvelle ligne**, sans regarder les autres.

Même produit :
- un produit du catalogue peut figurer sur plusieurs lignes, à des prix d'achat, remises ou quantités différents : `App\Domain\Achats\Services\ConstructeurLigneAchat::enregistrer()` (l. 174-220) l'accepte ;
- deux nouveaux produits de même référence sont refusés (`verifierLesNouveauxProduits()`, l. 268-302, testé dans `tests/Feature/Achats/FactureAchatActionsTest.php`, l. 348) ; deux nouveaux produits **sans référence et de même nom** sont acceptés, et la validation créerait deux produits (lecture du code, à confirmer par un test) ;
- deux lignes du même produit qui corrigent sa fiche différemment (deux prix de vente) : la seconde correction devrait être refusée comme « fiche modifiée depuis » (`FicheLue`, `ConstructeurLigneAchat::REFUS_FICHE_PERIMEE`) ; à confirmer par un test.

À l'étape 2, les factures reçues par SUPER PDP s'importeront en brouillon avec les lignes du fournisseur (`CLAUDE.md`, puce « Facture électronique »).

### 1.4 Avoir fournisseur

`FactureAchatResource::formulaireDeLAvoirFournisseur()` (l. 377-415), ouvert depuis le menu « Avoirs fournisseur » (`ListAvoirsFournisseur`) ou depuis la fiche d'une facture d'achat : les lignes sont celles de la facture d'achat encore retournables, une par `ligne_achat_id`, sans bouton d'ajout (l. 412). Le domaine refuse une même ligne d'achat deux fois (`CreerAvoirFournisseurAction`, l. 84-87). Deux lignes d'achat du même produit se partagent son stock (`regleStockDuProduitRetourne()`, l. 551 ; tests `FactureAchatResourceTest::test_l_avoir_fournisseur_partage_le_stock_entre_deux_lignes_du_meme_produit` et `AvoirFournisseurActionsTest::test_deux_lignes_du_meme_produit_ne_retournent_pas_ensemble_plus_que_le_stock`, défaut C56 de l'audit).

### 1.5 Inventaire d'ouverture

`App\Filament\App\Resources\InventaireOuvertureResource` (l. 98-160) : un répéteur avec le bouton natif « Ajouter un produit », une ligne vide au départ, et dans chaque ligne la liste « Produit déjà au catalogue, jamais reçu » (laissée vide pour un nouveau produit).

Même produit : l'écran laisse le choisir sur deux lignes ; **l'enregistrement le refuse** (« Le produit « Tasse » figure déjà sur une autre ligne de cet inventaire d'ouverture. », `App\Domain\Stock\Services\ConstructeurLigneInventaire::enregistrer()`, l. 91 ; test `tests/Feature/Stock/InventaireOuvertureTest.php`, l. 488). Deux nouveaux produits de même référence sont refusés (l. 99-106) ; de même nom sans référence, acceptés.

### 1.6 Sélecteur de clients

`App\Filament\App\Livewire\SelecteurDeClients` et `App\Filament\Concerns\RecoitLeClientChoisi` : un clic choisit un client, qui remplace le précédent dans le champ « Client ». Il n'ajoute aucune ligne ; choisir deux fois le même client ne change rien. Changer de client ne touche pas aux lignes de produits. Pour une ligne de service vendue sans TVA française, le prix affiché suit la nature de l'opération (`ChoisitLaNatureDeLOperation`) : une comparaison de prix pour réunir deux lignes de service devra donc se faire dans le contexte de la vente (`Service::prixDeReferenceDans($contexte)`, comme `ligneAuPrixDuCatalogue()`, l. 549-577).

### 1.7 Récapitulatif

| Document | Comment une ligne s'ajoute | Même produit deux fois, aujourd'hui |
|---|---|---|
| Caisse, facture, devis : produit | liste « Produit » d'une ligne | deux lignes |
| Caisse, facture, devis : produit | grand tableau, douchette | une ligne si la ligne existante est simple, sinon deux |
| Caisse, facture, devis : service | « Ajouter un service » | toujours deux lignes |
| Avoir | lignes du document cherché | une ligne par ligne d'origine, jamais deux fois la même |
| Facture d'achat | formulaire de ligne | deux lignes acceptées |
| Avoir fournisseur | lignes de la facture d'achat | une ligne par ligne d'achat, jamais deux fois la même |
| Inventaire d'ouverture | ligne du répéteur | refusé à l'enregistrement, pas à l'écran |

---

## 2. Les règles de CLAUDE.md que la fusion touche

### 2.1 Remise et prix dérogatoire (R2)

`CLAUDE.md`, « Prix et TVA des ventes » : « changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ».
- Le serveur le contrôle : `App\Domain\Facturation\Services\DerogationsDePrix::introduites()` compare chaque ligne aux lignes déjà enregistrées par une clé qui contient la quantité (`cle()`). Une ligne remisée dont la quantité change « introduit » une dérogation, refusée sans l'autorisation (`exiger()`).
- Conséquence : sans l'autorisation, on ne peut pas ajouter un article à une ligne remisée ou à un autre prix. C'est pourquoi le code crée aujourd'hui une nouvelle ligne.
- Piège à la caisse : `Caisse::rendering()` remet au prix du catalogue, **sans remise**, toute ligne que le vendeur ne pourrait pas encaisser (`lignesAuxPrixPermis()`, l. 532-534). Ajouter un article à une ligne remisée, pour un vendeur sans l'autorisation, effacerait donc la remise de toute la ligne, sans message.
- Avec l'autorisation, ajouter un article à une ligne remisée étend la remise à cet article : c'est une remise de plus, pas un simple ajout.

### 2.2 Prix de la fiche

« Sans elle, une ligne de produit prend le PU TTC de sa fiche, une ligne de service le prix de référence de sa fiche. »
- Un article ajouté arrive toujours au prix de la fiche (`ligneDuProduit()`). Sans l'autorisation, on ne peut donc le réunir qu'avec une ligne au prix de la fiche **d'aujourd'hui**.
- Rappel de la réponse Q1 de la série 8 (docblock du trait, l. 85-87, et champ `quantite`, l. 339-349) : une ligne dont la quantité change prend le prix du catalogue d'aujourd'hui.

### 2.3 Vente au-delà du stock (R1, avertissement renouvelé)

- Le contrôle se fait à l'émission, par produit, toutes lignes comprises : `App\Domain\Stock\Services\VerificateurDeStock` additionne les lignes du même produit (l. 90-92). Une ligne de 3 et trois lignes de 1 donnent le même contrôle.
- L'avertissement se renouvelle si le stock baisse encore : `ConfirmationVenteSansStock::couvre()` (l. 75-78) compare, par produit, le stock restant accepté. Ajouter un article après une confirmation baisse ce stock restant : l'avertissement revient, avec ou sans fusion.
- Le grand tableau refuse seulement un produit épuisé à qui n'a pas l'autorisation (`refusDuProduit()`, l. 681-686) ; il ne compare pas la quantité au stock. La fusion n'y change rien.

### 2.4 Calcul TTC et répartition par taux (R4, R6)

« Le TTC de chaque ligne est quantité × PU TTC, remise déduite, la remise arrondie une fois au centime » ; le HT de chaque taux se répartit entre ses lignes par cumul (`App\Domain\Facturation\Services\CalculateurLigne::chiffrerUneVente()`, l. 89-115).
- Les totaux par taux ne changent pas : le HT d'un taux se calcule sur la somme des TTC de ses lignes.
- La répartition change : deux lignes de 25,00 € reçoivent 20,83 € et 20,84 € HT ; une ligne de 2 × 25,00 € reçoit 41,67 €. La fusion fait disparaître cet écart d'un centime entre deux lignes identiques, qui étonne sur un PDF.
- **Sur une ligne remisée, la fusion peut changer le TTC.** La remise s'arrondit une fois par ligne (`montantNetCentimes()`, l. 750-758 ; demi-centime au centime supérieur, `diviserArrondi()`, l. 1010-1019). Exemple : article à 0,99 €, remise de 50 %. Trois lignes de 1 : remise 0,495 € → 0,50 €, soit 0,49 € par ligne, **1,47 €**. Une ligne de 3 : 2,97 € − 1,485 € → 1,49 €, soit **1,48 €**.
- Même effet pour un achat (HT de chaque ligne arrondi une fois) : trois lignes de 1 article à 0,99 € HT remisé de 50 % font 1,47 € HT ; une ligne de 3, 1,48 € HT.
- `CLAUDE.md` dit : « deux lignes identiques d'un document peuvent recevoir des HT qui diffèrent d'un centime (20,83 € et 20,84 €) ». Si deux lignes identiques deviennent impossibles (Q-L6), cette phrase devra dire « deux lignes de même prix ». Le test `tests/Feature/Facturation/PolitiqueDArrondiTest.php` (fournisseur `decoupagesDeCinquanteEuros`) crée un produit par ligne (`article()`, l. 61-64) : il resterait valable.

### 2.5 Jeton du panier (R3, demandes répétées)

« Chaque […] vente à la caisse […] porte un jeton unique, lié au contenu de la demande (celui de la caisse est celui de son panier). »
- L'empreinte se calcule sur les lignes envoyées : `EnregistrerVenteCaisseAction::handle()` (l. 98-106, `lignesCanoniques()`), puis `App\Domain\Tenancy\Services\ActionsUniques::empreinte()`.
- Une fusion faite à l'écran a lieu avant l'envoi : une demande répétée après une réponse perdue renvoie les mêmes lignes, donc la même empreinte. Rien ne change.
- Si le serveur refusait les lignes en double (Q-L6 a), le refus annulerait la transaction : le jeton ne serait pas consommé, et la vente corrigée passerait avec le même jeton. À prouver par un test.
- Si le serveur réunissait lui-même les lignes (Q-L6 c), l'empreinte devrait rester celle des lignes reçues, comme aujourd'hui.

### 2.6 Journal des événements (ligne retirée, baisse de prix)

« L'abandon d'une vente, chaque ligne retirée et chaque baisse du prix d'une ligne avant validation […] s'inscrivent au journal des événements […] ; les simples gestes d'écran (recherches, ajouts de lignes, […]) ne s'y inscrivent pas. »
- Augmenter la quantité d'une ligne est un ajout : rien ne s'inscrit.
- `App\Domain\Facturation\Services\LignesRetirees` compare les lignes par article, pas une à une (docblock, l. 16-22) : réunir deux lignes du même article au même prix en une seule ne retire rien et n'inscrit rien.
- Remplacer le produit d'une ligne reste un retrait de l'article remplacé (JET 323), comme aujourd'hui.
- Une remise ne compte pas comme une baisse de prix (même docblock) : étendre une remise à un article ajouté n'inscrit rien.

### 2.7 Retours liés à leur ligne d'origine

« Un retour rend, pour tous les articles d'une ligne rendus jusqu'ici, le même calcul sur cette quantité […] ; le retour de toute la ligne rend exactement ses montants. »
- Chaque ligne d'avoir garde sa `ligne_facture_id` (`CreerAvoirAction`, l. 133). Une ligne réunie se rend article par article (`CalculateurLigne::retourDeLigne()`, l. 136-160).
- Exemple : une ligne de 2 × 25,00 € à 20 % (41,67 € HT, 8,33 € de TVA). Premier retour d'un article : 20,83 € HT et 4,17 € de TVA. Second retour : le reste de la ligne, 20,84 € et 4,16 €. Ce sont les montants de deux lignes séparées.
- L'avoir garde une ligne par ligne d'origine : réunir ses lignes casserait ce lien.

### 2.8 Achats liés aux lignes d'achat

- Chaque ligne d'avoir fournisseur est liée à sa ligne d'achat (`ligne_achat_id` non nul depuis le lot S8-F) ; un retour sort au coût de la ligne d'achat retournée.
- La facture d'achat recopie celle du fournisseur. Réunir deux de ses lignes changerait le HT d'un centime (§ 2.4) et casserait la correspondance avec les lignes du fournisseur, dont aura besoin la réception SUPER PDP (étape 2).

### 2.9 Isolation des magasins

La fusion ne lit que les lignes du formulaire et des produits lus par `Produit::query()`, sous `MagasinScope`, comme aujourd'hui : aucune requête nouvelle hors du magasin. Un produit d'un autre magasin forgé dans une requête reste refusé par `produitsChoisis()` (`PRODUIT_INTROUVABLE`) et par `ConstructeurLigneDocument`.

---

## 3. Les cas limites

1. **Même produit déjà présent avec une remise ou un autre prix** : aujourd'hui, nouvelle ligne au prix de la fiche, sans message. Le choix est la question Q-L2.
2. **Personne sans l'autorisation des remises** : elle ne peut ni changer la quantité d'une ligne remisée (R2), ni retirer la remise (champ désactivé, l. 358). À la caisse, si elle change la quantité, la ligne revient au prix du catalogue, sans remise, dès la requête suivante (`Caisse::rendering()`), sans message. Sur une facture ou un devis, l'enregistrement est refusé (« Accorder une remise de 10 % sur « Câble » demande l'autorisation… »). Proposition : ne jamais réunir un ajout avec une ligne dérogatoire pour elle, et lui dire pourquoi.
3. **Autorisation retirée pendant la vente** : `lignesAuxPrixPermis()` remet une ligne remisée au prix de la fiche ; si une autre ligne du même produit est déjà au prix de la fiche, on obtient deux lignes identiques. Avec une garantie du serveur (Q-L6 a), la vente serait refusée : il faudra réunir ces lignes au même moment.
4. **Services** :
   - une ligne de service porte le nom du service, qui ne se corrige pas sur la ligne (docblock de `ligneSchema()`, l. 261-266) : deux lignes du même service ont toujours la même désignation ;
   - elles peuvent différer par le prix (service sans prix de référence, prix saisi à chaque ligne ; ou prix changé par une personne autorisée) et par le taux (le taux d'un service se saisit) ;
   - un service sans prix de référence n'est proposé qu'à une personne autorisée (`servicesVendables()`, l. 1014-1019).
   Le choix est la question Q-L5.
5. **Quantité tapée à la main** :
   - dans la ligne : rien à réunir, c'est la ligne elle-même ; sans l'autorisation, son prix revient à celui de la fiche (l. 339-349) ;
   - pendant la frappe (champ vide ou illisible), un scan ou un ajout par le tableau saute cette ligne (`ligneSansDerogationDuProduit()`, l. 866-874) et en crée une seconde. Proposition : refuser l'ajout avec un message (« Terminez la quantité de « Câble » »), sans seconde ligne ;
   - en bas du tableau : un nombre entier d'au moins 1 (`QuantiteEntiere`), sinon la quantité d'avant reste.
6. **Même produit par le scanner ou par une recherche** : la douchette réunit (comme le tableau) ; la recherche du tableau aussi ; la recherche de la liste « Produit » d'une ligne ne réunit rien (question Q-L3). Un code porté par deux produits n'ajoute rien (« Plusieurs produits ont le code… », l. 836-845).
7. **Deux personnes** :
   - le panier de la caisse est celui d'un vendeur (`PanierDeCaisse::enCours(auth()->user())`) : deux vendeurs ne partagent jamais un panier ; un même panier ouvert dans deux onglets est protégé par le verrou du panier ;
   - un brouillon de facture ou de devis modifié par deux personnes : chacune réunit dans son écran ; le dernier enregistrement remplace les lignes (lots FA1 à FA3), et ce qu'il perd s'inscrit en ligne retirée ; la fusion n'y change rien ;
   - deux ventes du même produit au même moment : le contrôle du stock les fait passer l'une après l'autre (verrou des produits dans `VerificateurDeStock`).
8. **Brouillon rouvert** : les lignes reviennent telles qu'enregistrées, y compris deux lignes du même produit à des conditions différentes. Proposition : ne jamais les réunir à la réouverture (ce serait changer une ligne dérogatoire acceptée par un responsable) ; seul un nouvel ajout se réunit.
9. **Prix de la fiche changé depuis l'ajout** (par exemple une facture d'achat validée avec un nouveau prix de vente) :
   - pour une personne autorisée, l'ancienne ligne compte comme « à un autre prix » : le nouvel article arrive sur une seconde ligne ;
   - pour une personne sans l'autorisation, à la caisse, l'ancienne ligne revient d'abord au nouveau prix, puis l'ajout s'y réunit ;
   - sur une facture ou un devis en création, rien n'appelle `lignesAuxPrixPermis()` avant l'enregistrement : seconde ligne, puis refus de l'ancienne ligne à l'enregistrement.
10. **Double clic sur « Ajouter le produit »** du tableau : à vérifier (§ 1.1).
11. **Produit archivé depuis** : la ligne reste affichée ; le produit n'est plus proposé (tableau, douchette) : on ne peut pas l'ajouter une seconde fois.
12. **Très grandes quantités** : `quantiteCumulee()` (l. 890-899) refuse une somme qui dépasse un entier, et le panier refuse plus d'articles qu'il ne peut en compter (`PanierDeCaisse::TROP_D_ARTICLES`).
13. **Facture d'achat** : même produit à deux prix d'achat (prix promotionnel, articles offerts à 0,00 €) ; deux nouveaux produits de même nom (§ 1.3).
14. **Inventaire d'ouverture** : le même produit choisi sur deux lignes n'est refusé qu'au clic sur « Créer » ou « Enregistrer » (§ 1.5).

---

## 4. Proposition de règle et questions à l'éditeur

### 4.1 Règle proposée (mes recommandations, à faire confirmer)

**Caisse, facture, devis : produits**
1. Un produit n'a qu'une ligne. Le choisir de nouveau, par le grand tableau, la douchette ou un nouveau clic dans le tableau, ajoute la quantité à sa ligne.
2. La ligne complétée garde sa place, et un message court le dit (« « Câble » : quantité portée à 3 »). Garder la place évite de changer la répartition du HT des autres lignes.
3. Seule exception : la ligne du produit porte une remise ou un autre prix que sa fiche. L'article ajouté arrive alors sur une nouvelle ligne, au prix de la fiche, avec un message qui dit pourquoi (selon Q-L2).
4. Dans le grand tableau, chaque clic sur une rangée ajoute 1 à la quantité choisie ; la croix retire le produit de la sélection (selon Q-L4).
5. La liste « Produit » de chaque ligne disparaît : une ligne montre sa référence et son nom ; on retire une ligne par sa corbeille ; plus de ligne vide à l'ouverture (selon Q-L3, comme le demandait le point 13 de la série 8 : « au lieu d'une ligne à remplir »).
6. Une ligne en cours de frappe ne reçoit pas de seconde ligne : l'ajout est refusé avec un message tant que sa quantité et son prix ne sont pas lisibles.
7. Le serveur refuse deux lignes du même article aux mêmes conditions (selon Q-L6).

**Caisse, facture, devis : services**
8. Cocher un service déjà présent, au même prix, au même taux et sans remise, ajoute 1 à sa quantité ; un service sans prix de référence, ou à d'autres conditions, arrive sur une nouvelle ligne (selon Q-L5).

**Avoir** : aucun changement : une ligne par ligne d'origine, jamais deux fois la même.

**Facture d'achat** : aucune fusion ; un message, sans refus, quand le produit est déjà sur une autre ligne, et quand deux nouveaux produits ont le même nom (selon Q-L1 et Q-L7).

**Avoir fournisseur** : aucun changement : une ligne par ligne d'achat.

**Inventaire d'ouverture** : la règle existe déjà (un produit une seule fois) ; l'écran prévient dès le choix du produit, au lieu d'attendre l'enregistrement.

**Texte proposé pour `CLAUDE.md`** (à soumettre à l'éditeur ; jamais écrit sans son accord), à la fin de la puce « Documents de vente » :

> Un produit n'occupe qu'une ligne d'un ticket, d'une facture ou d'un devis : le choisir de nouveau (tableau des produits, douchette, nouveau clic) augmente la quantité de sa ligne, qui garde sa place ; un article ajouté à un produit dont la ligne porte une remise ou un autre prix que sa fiche arrive sur une nouvelle ligne, au prix de sa fiche. Un service coché de nouveau, au même prix et au même taux, augmente de même la quantité de sa ligne. Un avoir garde une ligne par ligne du document qu'il corrige, une facture d'achat une ligne par ligne de la facture du fournisseur, et un inventaire d'ouverture une ligne par produit.

Et, si Q-L6 = (a), dans la puce « Prix et TVA des ventes » : « deux lignes identiques d'un document » devient « deux lignes de même prix d'un document ».

### 4.2 Questions métier

**Q-L1. Sur quels documents vaut « un produit = une ligne » ?**
- (a) **Recommandée** : les ventes seulement (caisse, facture, devis). L'avoir garde une ligne par ligne du document corrigé, la facture d'achat une ligne par ligne du fournisseur ; l'inventaire d'ouverture garde son refus, signalé dès le choix.
- (b) Les ventes et les factures d'achat : deux lignes d'achat du même produit, au même prix, à la même remise et au même taux, se réunissent.
- (c) Les ventes, les factures d'achat et les avoirs : un avoir regroupe les lignes d'un même produit.
- *Exemple* : un fournisseur facture 3 articles à 0,99 € HT remisés de 50 %, sur trois lignes. (a) 1,47 € HT, comme sa facture. (b) une ligne de 3 : 1,48 € HT, un centime de plus que sa facture. Avoir d'une facture « Câble 1 × 10,00 € remisé de 10 % » + « Câble 1 × 10,00 € » : (a) et (b) deux lignes à créditer, 9,00 € et 10,00 € ; (c) une ligne « Câble × 2 », qu'il faudrait de nouveau partager entre les deux lignes d'origine pour calculer le retour.

**Q-L2. Le produit est déjà sur une ligne remisée, ou à un autre prix que sa fiche, et on l'ajoute encore.**
- (a) **Recommandée** : nouvelle ligne au prix de la fiche, sans remise, avec un message (« « Câble » a une remise sur sa ligne : l'article ajouté est sur une nouvelle ligne, au prix de sa fiche ») ; la remise reste aux seuls articles pour lesquels elle a été accordée.
- (b) Pour une personne autorisée, la quantité de la ligne remisée augmente et la remise s'étend à l'article ajouté ; sans l'autorisation, nouvelle ligne, comme en (a).
- (c) Pour une personne autorisée, comme en (b) ; sans l'autorisation, l'ajout est refusé (« demandez à un responsable »).
- *Exemple* : câble à 10,00 € ; une ligne de 1 câble remisée de 10 % (9,00 €) ; on ajoute 1 câble. (a) deux lignes : 9,00 € + 10,00 € = 19,00 €. (b) et (c) avec l'autorisation : une ligne de 2 câbles remisée de 10 % = 18,00 €. (c) sans l'autorisation : la vente reste à 9,00 € tant qu'un responsable n'a pas changé la ligne.

**Q-L3. Que devient la liste « Produit » de chaque ligne ?**
- (a) **Recommandée** : elle disparaît. On ajoute par « Ajouter un produit » ou la douchette ; on retire une ligne par sa corbeille ; une ligne montre sa référence et son nom ; plus de ligne vide à l'ouverture.
- (b) Elle reste ; choisir un produit déjà présent sur une autre ligne réunit les deux lignes (si aucune n'a de remise ni d'autre prix), sinon le choix est refusé avec un message.
- (c) Elle reste, mais ne propose plus les produits déjà présents sur une autre ligne.
- *Exemple* : ligne 1 « Câble × 2 », ligne 2 « Chargeur × 1 » ; dans la ligne 2, on choisit « Câble ». Aujourd'hui : deux lignes Câble (2 et 1). (a) geste impossible : on retire le chargeur et on ajoute un câble, soit Câble × 3. (b) une ligne Câble × 3. (c) « Câble » n'apparaît pas dans la liste de la ligne 2. Dans les trois cas, à la caisse, le chargeur retiré s'inscrit en ligne retirée, comme aujourd'hui.
- *Coût* : (a) fait réécrire une quinzaine de tests qui choisissent le produit par cette liste (§ 5.3).

**Q-L4. Dans le grand tableau des produits, que fait le deuxième clic sur un même produit ?**
- (a) **Recommandée** : il ajoute 1 à la quantité choisie (1, puis 2, puis 3) ; la croix en bas de la fenêtre retire le produit.
- (b) Comme aujourd'hui : il retire le produit de la sélection.
- (c) Rien : la quantité ne se règle qu'en bas de la fenêtre.
- *Exemple* : trois clics sur « Câble » à 10,00 €. (a) Câble × 3, total 30,00 € TTC. (b) choisi, retiré, choisi : Câble × 1, 10,00 €. (c) Câble × 1, 10,00 €.
- *Risque de (a)* : un double clic involontaire donne × 2 ; la quantité reste visible sur la rangée (« × 2 ») et en bas, et se corrige avant « Ajouter ».

**Q-L5. Et les services ?**
- (a) **Recommandée** : même règle que les produits : cocher un service déjà présent, au même prix, au même taux et sans remise, ajoute 1 à sa quantité ; un service sans prix de référence, ou à d'autres conditions, arrive sur une nouvelle ligne.
- (b) Un service n'a toujours qu'une ligne : le cocher de nouveau ajoute 1 à sa quantité, même sans prix de référence (le prix saisi vaut alors pour toute la quantité).
- (c) Comme aujourd'hui : chaque service coché est une nouvelle ligne.
- *Exemple* : « Main d'œuvre » à 45,00 € TTC, cochée deux fois : (a) et (b) une ligne 2 × 45,00 € = 90,00 € (75,00 € HT) ; (c) deux lignes de 45,00 € (37,50 € HT chacune). « Réparation », sans prix de référence, saisie à 60,00 €, puis cochée de nouveau pour un second appareil réparé à 80,00 € : (a) et (c) une nouvelle ligne, saisie à 80,00 € ; (b) la même ligne, 2 × 60,00 € = 120,00 €, que la personne autorisée doit corriger à la main.

**Q-L6. Le serveur doit-il garantir la règle ?**
- (a) **Recommandée** : oui. Un ticket, une facture ou un devis qui porte deux lignes du même produit (ou du même service) aux mêmes conditions (même prix, même taux, même remise) est refusé, avec un message. Deux lignes du même article à des conditions différentes restent permises (selon Q-L2).
- (b) Non : seul l'écran réunit les lignes ; le serveur accepte ce qu'il reçoit.
- (c) Le serveur réunit lui-même, sans message, les lignes identiques avant de calculer.
- *Exemple* : une requête forgée (ou, plus tard, l'API de l'étape 10) envoie deux fois « Câble 1 × 10,00 € ». (a) refus : « « Câble » figure deux fois aux mêmes conditions : faites-en une seule ligne de quantité 2 ». (b) un ticket de deux lignes, à 8,33 € et 8,34 € HT. (c) une ligne de 2 × 10,00 €, à 16,67 € HT. Les totaux restent 16,67 € HT, 3,33 € de TVA et 20,00 € TTC.
- *Conséquence de (a)* : la phrase de `CLAUDE.md` sur « deux lignes identiques » change (§ 4.1).

**Q-L7. Facture d'achat : le même produit ajouté deux fois à la main.**
- (a) **Recommandée** : deux lignes permises, comme sur la facture du fournisseur ; l'écran prévient « « Câble » est déjà sur la ligne 1 », sans refuser ; deux nouveaux produits de même nom sont aussi signalés.
- (b) Comme aujourd'hui : deux lignes, sans message.
- (c) Refus : un produit une seule fois par facture d'achat.
- *Exemple* : le fournisseur facture 10 câbles à 4,00 € HT et 2 câbles offerts à 0,00 € HT. (a) deux lignes et un message. (b) deux lignes, sans message. (c) la facture ne se saisit pas telle quelle : il faudrait une seule ligne de 12 câbles à un prix moyen, différente de la facture du fournisseur.

---

## 5. Le futur lot : fichiers à modifier et tests

Selon les réponses recommandées. Aucune migration.

### 5.1 Fichiers à modifier

- `app/Filament/Concerns/AjouteDesLignesDeVente.php` :
  - `lignesDeVente()` : plus de ligne vide (`defaultItems(0)`, Q-L3 a) ;
  - `ligneSchema()` : la liste `produit_id` devient un champ caché, et la ligne montre sa référence et son nom (Q-L3 a) ; sinon réunion ou exclusion (Q-L3 b ou c) ;
  - `ajouterLesProduitsChoisis()` et `ligneSansDerogationDuProduit()` : message quand la quantité augmente ; refus d'un ajout à une ligne en cours de frappe ; règle de Q-L2 ;
  - `ajouterLesServices()` : réunion des services (Q-L5) ;
  - `lignesAuxPrixPermis()` : réunir les lignes devenues identiques (cas limite 3) ;
  - `actionAjouterUnProduit()` : texte de la fenêtre (« Cliquez sur les produits… ») ; docblocks du trait.
- `app/Filament/App/Livewire/SelecteurDeProduits.php` : `basculer()` remplacé par un clic qui ajoute 1 (Q-L4 a), sans doute renommé ; vider la sélection après l'envoi si le double envoi est confirmé ; docblock. La vue `resources/views/filament/app/livewire/selecteur-de-produits.blade.php` si un texte change.
- `app/Filament/Concerns/RecoitLesProduitsChoisis.php` : docblock ; garde contre un second envoi si le défaut est confirmé.
- `app/Domain/Facturation/Services/ConstructeurLigneDocument.php` : `construireToutes()` refuse deux lignes du même article aux mêmes conditions (Q-L6 a) ; `apercu()` ne refuse rien (le panier en cours de saisie s'enregistre par lui, via `PanierDeCaisse`).
- `app/Filament/App/Pages/Caisse.php` : docblock seulement (l. 62-68).
- `app/Filament/Concerns/AjouteDesLignesDAchat.php` : message quand le produit est déjà sur une autre ligne, ou quand un nouveau produit porte le nom d'un autre nouveau produit (Q-L7 a).
- `app/Filament/App/Resources/InventaireOuvertureResource.php` : message dès le choix d'un produit déjà sur une autre ligne (champ `produit_id`, l. 124-137). À vérifier dans `vendor/filament` : `distinct()` et `disableOptionsWhenSelectedInSiblingRepeaterItems()` de Filament 3.3 marchent-ils avec une recherche par `getSearchResultsUsing()` ?
- `CLAUDE.md`, après l'accord de l'éditeur (§ 4.1) ; `serie9/reponses-serie9.md` (ses réponses).

### 5.2 Tests à écrire

`tests/Feature/Filament/SelecteurDeProduitsTest.php` :
- `test_chaque_clic_sur_un_produit_ajoute_un_a_sa_quantite_choisie` (Q-L4 a)
- `test_la_croix_retire_un_produit_de_la_selection`
- `test_la_ligne_completee_garde_sa_place_et_un_message_le_dit`
- `test_un_produit_present_sur_une_ligne_remisee_arrive_sur_une_nouvelle_ligne_avec_un_message` (Q-L2 a ; sinon `test_une_personne_autorisee_ajoute_l_article_a_la_ligne_remisee` et `test_sans_l_autorisation_l_ajout_a_une_ligne_remisee_est_refuse`)
- `test_un_ajout_pendant_la_frappe_d_une_quantite_ne_cree_pas_de_seconde_ligne`
- `test_un_second_envoi_de_la_selection_n_ajoute_pas_les_produits_deux_fois` (si le défaut est confirmé)
- `test_un_identifiant_d_un_autre_magasin_ne_complete_jamais_une_ligne` (isolation)

`tests/Feature/Filament/CaisseTest.php` :
- `test_une_ligne_montre_son_produit_sans_liste_pour_le_changer` (Q-L3 a)
- `test_la_caisse_s_ouvre_sans_ligne_vide` (Q-L3 a)
- `test_un_produit_change_par_une_requete_forgee_dans_une_ligne_est_reverifie` (Q-L3 a : champ caché forgé ; produit épuisé refusé à l'émission, prix de la fiche exigé sans l'autorisation)
- `test_cocher_un_service_deja_present_ajoute_un_a_sa_quantite` (Q-L5 a)
- `test_un_service_sans_prix_de_reference_coche_de_nouveau_arrive_sur_une_nouvelle_ligne` (Q-L5 a)
- `test_un_service_a_un_autre_prix_ou_un_autre_taux_n_est_pas_complete` (Q-L5 a)
- `test_l_autorisation_retiree_pendant_la_vente_ne_laisse_jamais_deux_lignes_identiques`
- `test_completer_une_ligne_n_inscrit_rien_au_journal_des_evenements`
- `test_apres_un_changement_du_prix_de_la_fiche_l_article_ajoute_rejoint_la_ligne_remise_au_nouveau_prix`

`tests/Feature/Filament/FactureResourceTest.php` et `tests/Feature/Filament/DevisResourceTest.php` :
- `test_le_meme_produit_ajoute_deux_fois_a_une_facture_donne_une_seule_ligne`
- `test_le_meme_produit_ajoute_deux_fois_a_un_devis_donne_une_seule_ligne`
- `test_un_brouillon_rouvert_garde_ses_deux_lignes_du_meme_produit_a_des_conditions_differentes`

`tests/Feature/Facturation/LignesUniquesTest.php` (nouveau, Q-L6 a) :
- `test_une_facture_avec_deux_lignes_du_meme_produit_aux_memes_conditions_est_refusee`
- `test_un_devis_avec_deux_lignes_du_meme_service_aux_memes_conditions_est_refuse`
- `test_deux_lignes_du_meme_produit_a_des_conditions_differentes_restent_permises`
- `test_une_vente_a_la_caisse_refusee_pour_une_ligne_en_double_ne_consomme_pas_son_jeton`
- `test_le_retour_article_par_article_d_une_ligne_de_deux_rend_les_montants_de_deux_lignes` (20,83 € et 4,17 €, puis 20,84 € et 4,16 €)

`tests/Feature/Filament/FactureAchatResourceTest.php` (Q-L7 a) :
- `test_un_produit_deja_sur_la_facture_d_achat_est_signale_sans_refus`
- `test_deux_nouveaux_produits_de_meme_nom_sont_signales`

`tests/Feature/Achats/FactureAchatActionsTest.php` (pour confirmer la lecture du § 1.3) :
- `test_deux_lignes_qui_corrigent_differemment_la_fiche_du_meme_produit_sont_refusees`

`tests/Feature/Filament/InventaireOuvertureResourceTest.php` :
- `test_un_produit_deja_sur_une_autre_ligne_est_signale_des_son_choix`

### 5.3 Tests à réécrire ou à supprimer avec la décision

Chacun sera cité dans le message de commit, avec la décision qui le change (protocole de rigueur, point 4 : jamais pour faire passer un test).

- `SelecteurDeProduitsTest::test_un_produit_deja_present_sans_remise_voit_sa_quantite_augmenter` (l. 390) : sa seconde moitié attend `[[3, '10'], [1, '0']]`. Inchangé si Q-L2 = (a), réécrit sinon.
- Les appels à `basculer` (`SelecteurDeProduitsTest`, l. 179, 181, 200, 284, 351-352, 425, 427, 473, 514) : simple renommage si la méthode change de nom (Q-L4 a). Aucun test ne s'appuie aujourd'hui sur le deuxième clic qui retire.
- Si Q-L3 = (a) :
  - `SelecteurDeProduitsTest::test_la_liste_produit_d_une_ligne_refuse_un_produit_epuise_sans_l_autorisation` (l. 211) disparaît avec la liste ; le même refus reste couvert par `test_un_produit_epuise_ne_se_selectionne_pas_sans_l_autorisation_meme_par_requete_forgee` et par le nouveau test de la requête forgée (§ 5.2) ;
  - les tests qui choisissent le produit par `set("data.lignes.{$cle}.produit_id", …)` passeront par le tableau ou la douchette (`recevoirLesProduitsChoisis`) : `CaisseTest` l. 342, 383, 488, 518, 625, 650, 1151 ; `FactureResourceTest` l. 532, 965, 993 ; `ProduitResourceTest` l. 155 ; `SelecteurDeProduitsTest` l. 225 et 240 ;
  - les assertions sur ce champ : `CaisseTest` l. 775 (`assertFormFieldIsHidden`) et `FactureResourceTest` l. 250 (`assertFormComponentActionDoesNotExist`) ;
  - les tests qui remplissent `lignes` par `fillForm` avec un `produit_id` restent valables si `produit_id` reste un champ caché.
- Si Q-L6 = (a) : recenser au début du lot les tests qui enregistrent deux lignes du même produit aux mêmes conditions (chercher un même `produit_id` répété dans une liste de lignes) ; `PolitiqueDArrondiTest` n'en fait pas (un produit par ligne).

### 5.4 Points de relecture du lot

- Une ligne remisée dont la quantité change sans l'autorisation (R2).
- Une remise effacée en silence à la caisse.
- Deux lignes identiques laissées par `lignesAuxPrixPermis()`.
- Un service comparé hors du contexte de la vente (vente sans TVA française).
- Une fusion qui inscrit quelque chose au journal des événements, ou un retrait qui n'y est plus inscrit.
- Le jeton du panier consommé par une vente refusée.
- Un produit d'un autre magasin, par le tableau, la douchette ou un champ caché forgé.
- Un avoir, un avoir fournisseur ou une facture d'achat touché par erreur.
