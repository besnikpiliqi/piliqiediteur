# Série 8 : équipe, connexion par identifiant et achats (points 6, 7, 8, 9, 17, 18, 19 et 20)

Livrable d'analyse et de conception, écrit le 17/10/2026. Aucun fichier du dépôt `/home/user/piliqiediteur` n'a été modifié ; aucune base PostgreSQL n'a été utilisée.

## 0. Sources, méthode, conventions

- **Code lu et essayé** : la copie de travail `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc` (copie de HEAD 30844db, lot A4 poussé, SQLite). Tous les chemins de code cités ci-dessous (`app/...`, `database/...`, `tests/...`, `vendor/...`) sont relatifs à cette copie, avec leurs numéros de ligne.
- **Deux tests jetables écrits et lancés** dans le banc (`php artisan test --compact <fichier>`, tous deux verts) ; leurs sorties sont reprises aux sections 1, 2 et 6 :
  - `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc/tests/Feature/Scratch/Serie8EquipeConstatsTest.php` (points 6, 7, 17) ;
  - `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc/tests/Feature/Scratch/Serie8AchatsConstatsTest.php` (points 18, 19).
- **Documents lus** : `demande-editeur.md` (en entier) et, pour vérifier une interprétation, `demande-editeur-original.md` ; `/home/user/piliqiediteur/CLAUDE.md` (sa puce « Comptes et équipe » contient déjà, dans le dépôt, deux ajouts de la relecture A4 absents du banc : « sa décision tenant même après une transmission de la propriété » et « et seulement si c'est elle qui lui a donné ce provisoire ») ; `scratchpad/tva/plan-execution.md` (sections 2.11, 3.17.6, 4.6, 5.0, 5.13, 5.14, 5.15) et `scratchpad/tva/plan-execution-decisions.md` ; `scratchpad/societe/inscription-sans-magasin.md` (lot A4b) ; `scratchpad/societe/conception-v2-finale.md` section 14 (lots A0 à A7 et 21) ; `scratchpad/superpdp-plan.md` (l. 15, 993) ; `scratchpad/tva/reglementation.md` (l. 47).
- « À vérifier » signale ce que je n'ai ni lu ni essayé.

## Résumé

| Point | État dans le banc (vérifié) | Ce qui manque |
|---|---|---|
| 17 | **Conforme à la série 6.** Le rôle « Responsable » existe ; l'administrateur ne crée ni vendeur, ni observateur, ni administrateur ; il nomme un responsable seulement s'il y est autorisé ; le responsable crée et modifie ses vendeurs. Les constats de l'éditeur venaient d'une version antérieure au lot A4 (il le dit lui-même). | Rien de fonctionnel. Une remarque d'ergonomie (2.3). |
| 6 | **Non conforme.** Le modèle de l'administrateur coche `facture.create`, `facture.emettre`, `paiement.creer` et `avoir.creer` ; un administrateur ouvre la caisse et a émis le ticket `TK-MABOUT-2026-000001` dans l'essai. | Règle nouvelle « un administrateur ne vend jamais », en quatre couches (registre, action, lecture des droits, garde en base) ; une question sur les gestes limites (devis, avoirs, contre-passation, relances). |
| 7 | **Non conforme.** Le menu s'appelle « Ma caisse » pour tout le monde (libellé fixe) ; l'administrateur y voit « Fermer ma caisse ». | Libellé « Les caisses » pour qui voit les relevés de toute l'équipe ; plus de caisse personnelle pour l'administrateur (conséquence du point 6) ; caisses ouvertes affichées. |
| 20 | Connexion par adresse e-mail pour tous ; un membre peut avoir plusieurs magasins. | Grand changement, conçu en section 3 : table des identifiants, un compte par magasin pour les membres, propriétaire seul avec e-mail, renouvellement par le propriétaire ou un responsable. Supprime une partie du lot A4 (refus du compte, rattachement, affectation à plusieurs magasins) : **à faire confirmer** (Q20-c). |
| 8 | Aucune pièce jointe aujourd'hui. | Déjà conçu en entier au **lot CH1** du plan TVA (pièces chiffrées, jamais supprimées en base) ; à avancer si l'éditeur le veut tôt ; deux compléments proposés. |
| 18 | « Nouveau produit » existe dans « Produits » (vérifié : un produit naît sans aucun achat). Le fournisseur et le coût ne se modifient déjà pas dans la fiche. | Retirer la création ; **l'inventaire d'ouverture n'existe pas avant l'étape 7** : sans lui, une société qui démarre avec un stock ne peut plus créer ses produits (Q18-a). |
| 19 | **Un avoir fournisseur manuel existe déjà**, lié à la facture d'achat et à sa ligne, sortie de stock au coût de la ligne (vérifié), mais il ne se crée que par une action de ligne de la **liste** des factures d'achat ; le menu « Avoirs fournisseur » n'a aucun bouton, la fiche d'achat non plus. Aucune API n'existe (aucune route). | Bouton « Créer un avoir fournisseur » dans le menu et sur la fiche d'achat ; retours affichés en orange sous chaque ligne. Ni le lot M5e ni le lot M6 ne le décrivent. |
| 9 | Rien. | Seulement des questions à l'éditeur, et le lien avec l'étape 2 (factures reçues, déjà en brouillon) et l'étape 7 (import d'un catalogue). |

---

## 1. Points 6 et 7 : l'administrateur ne vend pas et voit « Les caisses »

### 1.1 Constats (code lu, essai exécuté)

Sortie du test `Serie8EquipeConstatsTest::test_point_6_et_7_un_administrateur_au_modele_par_defaut_vend_et_a_ma_caisse` (administrateur de tous les magasins créé avec le modèle par défaut) :

| Question | Réponse constatée |
|---|---|
| Cases du modèle de l'administrateur | toutes sauf `parametres.gerer` (dont `facture.create`, `facture.emettre`, `paiement.creer`, `paiement.contre_passer`, `avoir.creer`, `devis.*`) |
| `Caisse::canAccess()` | `true` |
| `can('create', Facture::class)` | `true` |
| Vente en caisse par l'action du domaine | acceptée : ticket `TK-MABOUT-2026-000001`, `cree_par` = l'administrateur |
| `can('fermer', ClotureCaissePersonnelle::class)` | `true` (il « tient une caisse ») |
| Actions visibles sur la page des relevés | « Fermer ma caisse » et « Fermer la caisse d'un vendeur » |
| Libellé du menu pour l'administrateur / pour un vendeur | « Ma caisse » / « Ma caisse » |

Où cela se décide :
- `app/Domain/Authorization/PermissionRegistry.php:259-270` : `modeleDeLAdministrateur()` = toutes les autorisations moins `PARAMETRES_GERER`.
- `app/Domain/Tenancy/Services/PorteeDesAdministrateurs.php:46-65` : les cases d'un administrateur ne sont contrôlées que contre la liste des autorisations connues (l. 65) ; le propriétaire peut donc lui cocher n'importe quelle case de vente.
- `app/Models/User.php:510-537` : `autorisationsEnregistreesDans()` rend les cases de l'affectation de l'administrateur, sans filtre.
- `app/Filament/App/Pages/Caisse.php:113-120` : la caisse s'ouvre à qui a `facture.create`, `facture.emettre` et `paiement.creer`.
- `app/Policies/ClotureCaissePersonnellePolicy.php:99-108` : « tenir une caisse » = avoir `facture.create` ou `paiement.creer` dans le magasin.
- `app/Filament/App/Resources/ClotureCaissePersonnelleResource.php:31` : `$navigationLabel = 'Ma caisse'`, fixe ; l. 43-52 : sans `cloture_caisse.view_toutes`, on ne voit que ses relevés, sinon tous.
- `app/Filament/App/Resources/ClotureCaissePersonnelleResource/Pages/ListClotureCaissePersonnelles.php:134-141` (« Fermer ma caisse », visible par la policy `fermer`) et l. 142-176 (« Fermer la caisse d'un vendeur », visible avec `cloture_caisse.gerer`).
- `app/Filament/App/Resources/ClotureCaisseResource/Widgets/CaissesPersonnellesOuvertesApercu.php:22-38` : le nombre et les noms des caisses encore ouvertes existent, mais seulement sur la liste des clôtures de la journée.

### 1.2 Règle proposée (texte pour `CLAUDE.md`, puce « Comptes et équipe »)

> Un administrateur ne vend jamais : il ne crée ni n'émet aucun ticket, aucune facture ni aucun avoir, n'enregistre aucun encaissement et n'ouvre pas la caisse ; aucune de ces autorisations ne se coche pour lui. Il n'a pas de caisse personnelle ; s'il y est autorisé, il voit « Les caisses » de chaque magasin de sa portée (les relevés de toute l'équipe et les caisses encore ouvertes), clôture la journée et ferme la caisse d'un vendeur.

La liste exacte des gestes interdits dépend de la question **Q6-a** (ci-dessous). Conséquence à reporter dans la puce « Prix et TVA des ventes » : l'autorisation « accorder des remises et fixer le prix d'un service » y est « précochée pour les administrateurs » ; elle couvre à la fois une ligne de vente (sans objet pour qui ne vend pas) et le prix de référence d'un service au catalogue (qui n'est pas une vente) : **Q6-b**.

### 1.3 Conception (fail-closed, comme le reste du lot A4)

1. **Registre** : `PermissionRegistry::interditesAuxAdministrateurs(): array` (au minimum `FACTURE_CREATE`, `FACTURE_EMETTRE`, `PAIEMENT_CREER`, `AVOIR_CREER` ; plus, selon Q6-a, `DEVIS_CREATE`, `DEVIS_UPDATE`, `DEVIS_ENVOYER`, `PAIEMENT_CONTRE_PASSER`, `FACTURE_ENVOYER`, `AVOIR_ENVOYER` ; et, au lot A6, `STOCK_VENDRE_SANS_STOCK` et la part « ligne de vente » de `VENTE_REMISES_ET_PRIX`). `modeleDeLAdministrateur()` les retire.
2. **Action** : `PorteeDesAdministrateurs::lire()` refuse toute case interdite (requête forgée comprise), avec un message clair (« Un administrateur ne vend jamais : cette autorisation ne se coche pas pour lui. »).
3. **Lecture des droits** : `User::autorisationsEnregistreesDans()` retire ces autorisations pour un administrateur, même si une ligne reste en base : `Caisse::canAccess()`, `tientUneCaisse()`, `LecteurDesDroits` sous verrou (émission, paiement, avoir) refusent alors d'eux-mêmes.
4. **Base** : une colonne `interdite_aux_administrateurs` (booléen) dans `referentiel_autorisations_magasin` (`database/migrations/2026_10_02_095000_create_referentiel_autorisations_magasin_table.php:24-31`, remplie depuis le registre par la migration, comme aujourd'hui) ; la garde `GardesDesDroits::CASES` (`app/Domain/Tenancy/Support/GardesDesDroits.php`, doc l. 10-56) refuse une telle case sur une affectation sans rôle (celle d'un administrateur) ; la garde `ROLES` refuse déjà de nommer administrateur un compte qui garde une affectation de membre (doc de `GardesDesDroits.php:31-34`), et la nomination supprime ces affectations (test existant `tests/Feature/Societe/AdministrateursTest.php:161`) : un vendeur nommé administrateur ne peut donc garder aucune case de vente par ce chemin.
5. **Écran** : `MembreResource::champsDeLAdministrateur()` (`app/Filament/Societe/Resources/MembreResource.php:282-307`) ne propose plus ces cases.
6. **Menu et page** : `ClotureCaissePersonnelleResource::getNavigationLabel()` dynamique : « Les caisses » pour qui a `cloture_caisse.view_toutes` dans le magasin (propriétaire, responsable, administrateur autorisé, expert-comptable), « Ma caisse » sinon ; titre de la page assorti ; « Fermer ma caisse » reste réservé à qui tient une caisse (l'administrateur n'en tient plus) ; le widget des caisses ouvertes s'affiche aussi en tête de cette page.
7. **Portée** : dans le panneau d'un magasin, l'administrateur ne voit que ce magasin. Une vue de toutes les caisses de tous les magasins de sa portée, dans l'espace de la société, serait une nouvelle lecture hors du magasin, à déclarer dans `CLAUDE.md` et à tester : **Q7**.

### 1.4 Tests

- Nouveau fichier `tests/Feature/Societe/AdministrateurSansVenteTest.php` : `test_le_modele_de_l_administrateur_ne_coche_aucune_autorisation_de_vente` ; `test_le_proprietaire_ne_coche_jamais_une_autorisation_de_vente_pour_un_administrateur` (action et requête Livewire forgée) ; `test_la_base_refuse_une_autorisation_de_vente_sur_l_affectation_d_un_administrateur` (SQLite et PostgreSQL) ; `test_une_case_de_vente_restee_en_base_ne_donne_rien_a_un_administrateur` (garde retirée le temps du test, comme `retirerLaGardeDesDroits()`) ; `test_un_administrateur_n_ouvre_pas_la_caisse_et_n_emet_ni_ticket_ni_facture_ni_avoir_ni_encaissement` (écrans et actions directes) ; `test_nommer_administrateur_un_vendeur_ne_lui_laisse_aucune_autorisation_de_vente`.
- `tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php` (complété) : `test_le_menu_s_appelle_les_caisses_pour_qui_voit_toute_l_equipe_et_ma_caisse_pour_un_vendeur` ; `test_un_administrateur_n_a_pas_fermer_ma_caisse_mais_ferme_celle_d_un_vendeur_s_il_peut_cloturer` ; `test_les_caisses_ouvertes_s_affichent_sur_la_page_des_caisses`.
- **Tests existants touchés** (vérifié par recherche) : les jeux de données « vendeur aux cases de l'administrateur » de `tests/Feature/Facturation/FacturePolicyTest.php:91,257`, `tests/Feature/Authorization/PaiementPolicyTest.php:60`, `DevisPolicyTest.php:40,63`, `AvoirPolicyTest.php:35`, `FournisseurPolicyTest.php:27`, `tests/Feature/Filament/EnregistrementFiscalResourceTest.php:108`, `tests/Feature/Stock/StockLedgerServiceTest.php:241` : ils donnent `modeleDeLAdministrateur()` à un vendeur ; leur intention (les cases d'un administrateur, portées par un vendeur) change, ils sont à réécrire un par un sans affaiblir l'assertion. Les 9 appels `administrateur(..., PermissionRegistry::toutes())` dans 7 fichiers (`EquipeDuMagasinTest`, `AutorisationsDesMembresTest`, `AdministrateursTest`, `ExpertComptableTest`, `ResponsablesTest`, `AffectationPolicyTest`, `PorteeDesDroitsTest`) seront refusés par l'action : ils passent à « toutes sauf les interdites ».

### 1.5 Questions à l'éditeur

- **Q6-a** : « faire une vente », pour l'administrateur, c'est : (a) les tickets, factures et encaissements seulement ; (b) **plus les avoirs (retours) et les devis** — recommandé : l'administrateur supervise et gère, il ne fait aucune opération commerciale avec un client ; (c) autre. Et peut-il encore, s'il y est autorisé, contre-passer un paiement saisi par erreur, et envoyer ou relancer une facture déjà émise (ce ne sont pas des ventes) ? Recommandé : oui pour les deux, cochables.
- **Q6-b** : l'administrateur peut-il fixer le prix de référence d'un service au catalogue (sans vendre) ? Recommandé : oui, s'il est autorisé ; la règle R2 du lot A6 sépare alors ce geste du prix d'une ligne de vente.
- **Q7** : faut-il, dans l'espace de la société, une page « Les caisses » qui réunit tous les magasins de la portée de l'administrateur ? Recommandé : non pour l'instant (chaque magasin a sa page) ; si oui, c'est une lecture hors du magasin à déclarer.

---

## 2. Point 17 : déjà conforme à la série 6

### 2.1 Constats (essai exécuté)

Sortie de `Serie8EquipeConstatsTest::test_point_17_ce_qui_est_deja_conforme` :

| Vérification | Résultat |
|---|---|
| Administrateur (toutes les cases, sans « nommer les responsables ») : créer dans « Équipe du magasin » | refusé (`AffectationPolicy::create` = faux) |
| Administrateur autorisé à nommer : créer un **vendeur** par l'action | refusé : « Vous ne pouvez pas faire cela pour ce compte. » |
| Administrateur : créer un **administrateur** | refusé : « Seul le propriétaire crée, nomme et modifie les administrateurs. » |
| Responsable : créer un vendeur (écran et action) | accepté ; il peut ensuite le modifier (`update` = vrai) |
| Espace société, administrateur autorisé : boutons visibles | « Nommer un responsable » oui ; « Créer un membre » non ; « Créer un administrateur » non |
| Espace société, administrateur non autorisé | page « Comptes » interdite (403) |
| Propriétaire : boutons visibles | les trois |
| Rôles proposés dans « Équipe du magasin » | propriétaire : responsable, vendeur, observateur ; responsable : vendeur, observateur |

### 2.2 Où cela se décide

- `app/Domain/Tenancy/Enums/RoleMagasin.php:24-37` : le rôle « Responsable » existe.
- `app/Domain/Tenancy/Services/PorteeDesDroits.php:92-97` (`gereLEquipeDe` : propriétaire ou responsable du magasin), `:104-112` (`peutNommerUnResponsable` : propriétaire, ou administrateur autorisé dans sa portée), `:155-191` (`peutDonnerLeRole`).
- `app/Policies/MembrePolicy.php:85-88` (`creerUnAdministrateur` : propriétaire seul) ; `app/Policies/AffectationPolicy.php:44-47`.
- `app/Filament/Societe/Resources/MembreResource/Pages/ListMembres.php:55-97` (« Nommer un responsable ») et `:104` (« Créer un membre » : propriétaire seul).
- `app/Filament/App/Resources/EquipeDuMagasinResource.php:109-122` (le rôle « Responsable » n'est proposé qu'au propriétaire).
- La case « modifier les membres » que cite l'éditeur n'existe plus : aucune constante d'équipe dans `app/Domain/Authorization/PermissionRegistry.php` (le lot A4 a supprimé `EQUIPE_*`).
- Tests existants qui le prouvent déjà : `tests/Feature/Societe/ResponsablesTest.php` (22 tests), `tests/Feature/Societe/AutorisationsDesMembresTest.php:91` et `:163`, `tests/Feature/Societe/AdministrateursTest.php:247`, `tests/Feature/Authorization/AffectationPolicyTest.php:28` et `:83`.

### 2.3 Remarque d'ergonomie (sans règle nouvelle)

Un administrateur autorisé nomme un responsable seulement depuis l'espace de la société (« Comptes »), jamais depuis « Équipe du magasin », qui lui reste fermée : s'il cherche dans le magasin, il ne trouve rien. Proposition : une phrase dans le tableau de bord de l'espace société (« Nommer un responsable : Comptes ») ; à décider avec le point 5 (rôle affiché après « Bonjour »). Aucun test nouveau n'est exigé pour un libellé.

---

## 3. Point 20 : connexion par identifiant

### 3.1 Ce que l'éditeur a décidé (réponse « Tous sauf le propriétaire »)

- Responsables, vendeurs, observateurs : un identifiant unique généré par PILIQI et un mot de passe ; **un compte par magasin** ; une personne qui travaille dans deux magasins a deux identifiants et ne voit dans chacun que ce qu'elle y a fait ; e-mail facultatif ; un accès perdu est renouvelé par le propriétaire ou un responsable (mot de passe provisoire).
- Administrateurs et experts-comptables : un identifiant **rattaché à la société**.
- Seul le propriétaire garde son adresse e-mail (inscription par lien).
- Les identifiants sont dans une table dédiée, uniques dans toute la table (pas par magasin ni par société).

### 3.2 Modèle de données

**Table `identifiants`** (une seule migration de création ; le numéro `2026_10_02_100002` est libre dans le banc et la place après `users`, `magasins` et `societes`, dont elle dépend, et avant la pose des gardes des droits avec la migration des autorisations de société, `100007` ; le `100016` est réservé au lot A7 pour `changements_fin_exercice`) :

| Colonne | Contenu |
|---|---|
| `id` | |
| `identifiant` | `char(12)`, les 12 chiffres sans séparateur ; **index unique** sur toute la table |
| `user_id` | clé vers `users`, **unique** (un identifiant par compte), `restrictOnDelete` |
| `societe_id` | clé vers `societes` ; clé composite (`societe_id`, `user_id`) vers `users (societe_id, id)` (la cible existe : `database/migrations/0001_01_01_000001_create_users_table.php:84`) |
| `magasin_id` | nul pour un administrateur ou un expert-comptable ; non nul pour un membre ; clé composite (`societe_id`, `magasin_id`) vers `magasins (societe_id, id)` |
| `attribue_le` | date de l'attribution |

Gardes (en base, sur les deux moteurs, à la manière de `GardesDesDroits`) :
- aucune ligne ne se modifie ni ne s'efface (`DeclencheursDeProtection::sansSuppression()` et refus de tout UPDATE de `identifiant`, `user_id`, `societe_id`, `magasin_id`) : un identifiant n'est jamais réattribué, même après le retrait du compte ;
- `magasin_id` non nul si et seulement si le compte est un membre ; jamais de ligne pour le propriétaire tant qu'il l'est (voir 3.6, transfert) ;
- un membre a **au plus une** affectation avec rôle (index unique partiel `affectations (user_id) WHERE role IS NOT NULL`), et elle est dans le magasin de son identifiant (garde `AFFECTATIONS` étendue).

Ce que je recommande de garder : la table `affectations` reste le siège du rôle et des cases (tout le code du lot A4, `JournalDesDroits`, `PorteeDesDroits`, les gardes et leurs tests s'appuient sur elle) ; l'administrateur garde ses affectations de portée. Option écartée : déplacer le rôle et les cases d'un membre sur `users` ; plus simple en apparence, mais elle réécrit toutes les gardes et les lectures des droits pour un gain faible.

**Table `users`** (migration de création modifiée) :
- `email` devient **nullable** ; l'index unique global (`...create_users_table.php:47`) devient un **index unique partiel** `WHERE role_societe = 'proprietaire'` (l'adresse de connexion d'un propriétaire ne sert qu'à un compte ; celle d'un autre compte peut être celle de plusieurs comptes de la même personne).
- `password_reset_tokens` (l. 91-95), indexée par adresse, ne sert plus qu'aux propriétaires.
- Selon Q20-c : `email_verified_at` garde son sens (adresse confirmée par un lien) seulement si l'e-mail facultatif sert à quelque chose (Q20-b).

**Pourquoi une table dédiée plutôt qu'une colonne `users.identifiant`** : c'est la demande explicite de l'éditeur ; elle porte aussi le rattachement définitif du compte (le magasin d'un membre, que l'on garde après son retrait pour savoir où il peut être réintégré), alors que l'affectation disparaît au retrait.

### 3.3 Format et génération de l'identifiant

Proposition (**Q20-a**) : **12 chiffres**, affichés en trois groupes (`4821 0937 5512`), soit 11 chiffres tirés au hasard (le premier de 1 à 9) et 1 chiffre de contrôle (formule de Luhn).

- Lisible et facile à taper sur le pavé numérique d'un poste de caisse ou à dicter ; le texte d'origine de l'éditeur parle d'ailleurs d'un « numéro » identifiant.
- 12 chiffres : jamais confondu avec un SIREN (9), un SIRET (14) ni un numéro de téléphone français (10, commençant par 0).
- Non devinable : tiré par un générateur cryptographique (`random_int`), jamais séquentiel (une suite révélerait le nombre de comptes de PILIQI et faciliterait l'énumération), sans rien qui dise la société, le magasin, le nom ni le rôle. Espace : 9 × 10^10 valeurs (environ 36 bits) ; avec un million de comptes, il faut en moyenne 90 000 essais pour tomber sur un identifiant existant, soit environ 300 heures à 5 essais par minute depuis une adresse IP (limite de 3.5).
- Le chiffre de contrôle détecte toute faute de frappe sur un chiffre et la plupart des inversions de deux chiffres voisins : la page de connexion peut dire « vérifiez votre identifiant » sans rien révéler de son existence.
- Génération : dans la transaction de création du compte, insertion dans un point de sauvegarde (transaction imbriquée de Laravel) ; en cas de doublon (violation de l'index unique), nouveau tirage, au plus 5 fois, puis erreur. Le point de sauvegarde est nécessaire sous PostgreSQL, où une violation d'unicité interrompt toute la transaction : à couvrir par un test sous PostgreSQL (`test_un_doublon_d_identifiant_est_retire_sans_interrompre_la_creation`).
- L'identifiant n'est pas un secret, mais c'est la moitié d'un accès : il s'affiche à la personne (profil) et à qui gère son compte, pas aux autres membres de l'équipe.

Alternative possible si l'éditeur préfère : 10 caractères alphanumériques sans caractères ambigus (base 32 de Crockford, environ 50 bits, par exemple `7KQ4-M2XR-9T`). Moins pratique sur un pavé numérique.

### 3.4 Création, mot de passe provisoire, renouvellement

**Création** (`CreerMembreAction`, `CreerAdministrateurAction`, `CreerExpertComptableAction`, `CreationDesComptes`) :
- formulaire : nom, e-mail facultatif, rôle et cases ; plus de champ d'adresse obligatoire ;
- un membre est créé pour **un seul magasin** (la saisie de plusieurs magasins disparaît) ;
- PILIQI génère l'identifiant **et** le mot de passe provisoire (recommandé : toujours généré, plus jamais tapé par le gestionnaire, pour éviter un provisoire faible ou le même pour toute l'équipe ; le bouton « Générer » existe déjà, `app/Filament/Concerns/GereLesComptes.php:47-52`, `Str::password(16, symbols: false)`, mais la saisie libre est permise, l. 39-46) ;
- une **fiche d'accès** s'affiche une seule fois, imprimable : identifiant, mot de passe provisoire, date et heure d'expiration (72 heures), adresse de connexion ; le provisoire ne se réaffiche jamais, ne s'envoie jamais par e-mail et n'est jamais journalisé ;
- la limite de 20 comptes créés par jour et par société reste (`app/Domain/Tenancy/Services/CreationDesComptes.php:77-79`) ;
- le contrôle « adresse déjà utilisée » (`CreationDesComptes.php:69-75`) disparaît pour les comptes autres que le propriétaire.

**Renouvellement d'un accès perdu** (remplace la règle « un accès perdu se renouvelle par un lien ; un nouveau provisoire n'est donné qu'à un compte dont le mot de passe est encore un provisoire », `app/Domain/Tenancy/Actions/DonnerUnMotDePasseProvisoireAction.php:19-33` et `PorteeDesDroits.php:277-284`) :

| Compte | Qui renouvelle (nouveau provisoire de 72 heures) |
|---|---|
| Vendeur, observateur | le propriétaire ; un responsable de son magasin |
| Responsable | le propriétaire seul (recommandé, **Q20-d**) |
| Administrateur, expert-comptable | le propriétaire seul |
| Propriétaire | lui-même, par « Mot de passe oublié » (lien à son adresse) ; à défaut, procédure de récupération de compte de l'éditeur (étape 13) |

Chaque renouvellement : relu sous verrous (comme aujourd'hui), ferme toutes les sessions du compte et coupe « se souvenir de moi » (`SessionsDesComptes`, déjà appelé l. 67), s'inscrit au journal d'audit (`ActionAuditee::MotDePasseProvisoireDonne`, existant, catégorie gestion des accès, 5 ans), et la personne le voit à sa connexion suivante (« Votre mot de passe a été renouvelé le … par … ») avec la date de sa dernière connexion. Le courriel `MotDePasseProvisoireRenouvele` (`app/Domain/Tenancy/Notifications/MotDePasseProvisoireRenouvele.php:21-32`) ne part plus vers une adresse non confirmée (il invite aujourd'hui à refuser le compte, ce qui n'aurait plus d'objet).

**Règle « provisoire » à garder, adaptée** : tant que son mot de passe est un provisoire, un compte ne reçoit un rôle de responsable ou une réintégration que de la personne qui lui a donné ce provisoire (texte actuel du dépôt, après la relecture A4). « Un nouveau magasin » disparaît de la phrase : un compte de membre n'en change jamais.

### 3.5 Page de connexion, fournisseur des comptes, limitation des tentatives

- **Un seul champ** « Identifiant ou adresse e-mail », puis le mot de passe. Si la saisie contient « @ », c'est une adresse : on ne cherche que parmi les **propriétaires** (adresse en minuscules, sans espaces). Sinon, on retire espaces, tirets et points, on exige 12 chiffres à clé valide, puis on cherche dans `identifiants` un compte qui n'est pas propriétaire.
- L'adresse facultative d'un membre n'ouvre **jamais** son compte, ni par la connexion, ni par « Mot de passe oublié » : sinon, qui saisit une adresse sur un compte pourrait s'en emparer.
- Fichiers : `app/Domain/Tenancy/Services/FournisseurDesComptes.php:23-30` (réécrit : deux chemins) ; `app/Filament/App/Pages/Auth/Connexion.php:22-37` (champ, libellé, message d'un provisoire expiré inchangé, toujours après des identifiants exacts seulement) ; `app/Filament/App/Pages/Auth/DemandeDeLien.php` et `PageDuLien.php` (propriétaire seul, selon Q20-b) ; `app/Providers/Filament/AppPanelProvider.php:72`.
- Réponse : la même pour un identifiant inconnu, un mot de passe faux, un compte suspendu ou retiré (comme aujourd'hui, `Connexion.php:12-21`) ; seule une faute de format (clé de contrôle fausse) peut être signalée, car elle ne révèle rien.
- **Limitation des tentatives** : aujourd'hui seulement 5 essais par minute et par adresse IP (`vendor/filament/filament/src/Pages/Auth/Login.php:56`, clé = classe + méthode + adresse IP, `vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27`). À ajouter : un compteur par identifiant (ou adresse) et par adresse IP (par exemple 5 échecs en 15 minutes), et un compteur par identifiant seul, plus haut (par exemple 30 échecs par heure), qui fait attendre quelques minutes depuis toute adresse IP ; les compteurs s'appliquent aussi aux identifiants inexistants (sinon le blocage révélerait l'existence). Le blocage ne doit pas devenir un moyen d'empêcher un vendeur de travailler : délai progressif plutôt que blocage long, et alerte au propriétaire quand un compte dépasse le seuil haut. Le cache par défaut est la base de données (`config/cache.php:18`), partagé entre processus : à revérifier avec Redis à l'étape 15.
- **Mots de passe définitifs** : aujourd'hui `Password::default()` (`app/Domain/Tenancy/Actions/ChangerSonMotDePasseProvisoireAction.php:40`) sans `Password::defaults()` défini dans l'application (recherche vide), donc **8 caractères minimum** (`vendor/laravel/framework/src/Illuminate/Validation/Rules/Password.php:166-173`). Avec des identifiants numériques, je recommande 12 caractères minimum, et le refus d'un mot de passe qui contient l'identifiant ou le nom ; la vérification « déjà divulgué » (`uncompromised()`) interroge un service externe : à soumettre à l'éditeur si on la veut (Q20-j).

### 3.6 Conséquences sur les règles du lot A4 et de `CLAUDE.md`

| Règle actuelle (puce « Comptes et équipe », sauf mention) | Devient |
|---|---|
| « un compte n'appartient qu'à une seule société ; une fois retiré par elle, la personne peut, avec son accord et avec le même compte, rejoindre une autre société ou créer la sienne, jamais avant » | « Un compte n'appartient qu'à une seule société. » La suite disparaît : un compte retiré ne rejoint plus une autre société ; la personne y reçoit un nouveau compte (Q20-c). |
| « Une adresse e-mail déjà utilisée ne sert jamais à un nouveau compte. » | « L'adresse e-mail d'un propriétaire ne sert de connexion qu'à un seul compte ; celle de tout autre compte est facultative, ne sert jamais à se connecter et peut être celle de plusieurs comptes de la même personne. » La phrase sur les minuscules reste. |
| Changement d'adresse confirmé par un lien, l'ancienne prévenue | Garde son sens pour le propriétaire ; pour un autre compte, selon Q20-b (si l'adresse ne sert à rien, aucune confirmation) ; l'identifiant ne change jamais. |
| Administrateur : « il ne nomme, ne retire et ne change les magasins des responsables » ; « lui renouveler son accès par un lien » | « change les magasins » disparaît (un compte de responsable n'a qu'un magasin) ; le renouvellement par un administrateur disparaît (Q20-d). |
| « une même personne peut être responsable de plusieurs magasins » | « …avec un compte et un identifiant dans chacun ». |
| « Seul le propriétaire affecte une même personne à plusieurs magasins, avec des autorisations propres à chacun. » | **Supprimée.** Le compte du second magasin se crée dans ce magasin, par qui peut y créer ce rôle. |
| « Un responsable ne gère … que les vendeurs et les observateurs dont tous les magasins sont des magasins dont il est responsable ; … il voit un membre qui travaille aussi ailleurs, sans pouvoir le modifier ni savoir dans quels autres magasins » | « Un responsable gère les comptes de vendeur et d'observateur de son magasin, et ne voit ni ne touche jamais les comptes d'un autre magasin. » |
| « Retirer quelqu'un de son dernier magasin le retire de la société. » | « Retirer un compte de son magasin le retire de la société. » |
| Réintégration, rattachement, « un compte retiré par son propre refus ne revient que par un rattachement qu'il accepte » | « Réintégrer un compte retiré, dans son magasin ou dans la société, suit les règles de la création de son rôle. » Rattachement supprimé (Q20-c). |
| Mot de passe provisoire, lien, « personne d'autre ne connaît son mot de passe » | Texte de 3.4. « Personne d'autre ne connaît son mot de passe » n'est plus garanti : le gestionnaire qui renouvelle connaît le provisoire (risque R1 de 3.9). |
| Refus d'un compte tant que l'adresse n'a jamais été confirmée (paragraphe entier) ; « Changer un mot de passe provisoire ne confirme pas l'adresse. » | **Supprimés** si Q20-c est confirmée : le refus servait à libérer une adresse e-mail prise sans l'accord de la personne et à lui permettre de créer sa société avec ce compte ; sans adresse de connexion, il n'a plus d'objet. |
| « L'historique garde le nom de chaque personne tel qu'il était au moment de chaque opération. » | Inchangée (le nom est figé par compte ; le contenu signé garde l'identifiant technique du compte et son nom, `app/Domain/CaisseSecurisee/Services/RegistreFiscal.php:498`, sans changement de format). |
| « Une personne n'encaisse que dans un magasin à la fois » | « Un compte n'encaisse que dans un magasin à la fois. » Elle ne concerne plus que le **propriétaire** (seul compte vendeur ouvert sur plusieurs magasins, l'administrateur ne vendant plus) ; une même personne peut avoir deux caisses ouvertes sous deux comptes (Q20-e). Le contrôle `VerificateurCaissesPersonnelles::refuserSiSaCaisseEstOuverteAilleurs()` (`app/Domain/Facturation/Services/VerificateurCaissesPersonnelles.php:83`, appelé par `EmettreFactureAction.php:147`, `EnregistrerPaiementAction.php:111`, `ContrePasserPaiementAction.php:99`) reste, inchangé. |
| Transfert de la propriété à un administrateur dont la qualité est prouvée | Ajouter : « et qui a une adresse e-mail confirmée, qu'aucun autre propriétaire n'utilise ; elle devient sa connexion, son identifiant ne l'ouvre plus ; l'ancien propriétaire reçoit un identifiant, affiché à la fin du transfert ». |
| Protocole de rigueur, règle 2 : unicités voulues « (SIREN d'une société validée, SIRET réservé, adresse e-mail) » | « (…, adresse e-mail de connexion d'un propriétaire, identifiant de connexion) ». Règle de l'éditeur : à lui faire valider. |
| Puce « Produit », lectures hors du magasin : « le fait qu'un membre de l'équipe d'un magasin a aussi accès à d'autres magasins… » et « les caisses encore ouvertes d'une personne dans les autres magasins de sa société » | La première disparaît (`PorteeDesDroits::aAussiDAutresMagasins()`, `PorteeDesDroits.php:524-532`) ; la seconde devient « du propriétaire ». |
| Journal d'audit | L'entrée de création d'un compte porte son identifiant (pseudonyme, utile pour tracer) ; disparaissent `CompteRefuse`, `LienDAccesEnvoye`, `RattachementPropose`, `RattachementAccepte`, `SocieteCreeeParUnCompteRetire` (`app/Domain/Tenancy/Enums/ActionAuditee.php:49` et `:51-54` ; `MotDePasseProvisoireDonne`, `:50`, reste) si Q20-c est confirmée. |

Ce que la règle « un compte par magasin » apporte en plus : l'isolation devient plus simple (un compte de membre n'ouvre qu'un magasin, plus de lecture « aussi dans d'autres magasins », plus de sélecteur de magasin pour un membre), et le motif de l'éditeur est réglé (une adresse ne bloque plus personne).

### 3.7 Migration du code du lot A4

**Supprimés** (si Q20-c est confirmée) : `app/Domain/Tenancy/Actions/RefuserSonCompteAction.php`, `ProposerUnRattachementAction.php`, `AccepterUnRattachementAction.php`, `CreerSaSocieteAvecUnCompteRetireAction.php`, `EnvoyerUnLienDeReinitialisationAction.php` (selon Q20-b) ; `app/Domain/Tenancy/Models/DemandeRattachement.php` et la migration `2026_10_02_100012_create_demandes_rattachement_table.php` ; `app/Http/Controllers/RattachementController.php`, `CreerSaSocieteController.php` et leurs routes (`routes/web.php:57-79`) ; `app/Domain/Tenancy/Mail/PropositionDeRattachementMail.php`, `RattachementImpossibleMail.php` ; `app/Domain/Tenancy/Notifications/LienPourChoisirSonMotDePasse.php` (selon Q20-b) ; `User::peutRefuserSonCompte()` (`app/Models/User.php:263-273`) ; `PorteeDesDroits::peutAffecterAPlusieursMagasins()` (`:119-122`), `aAussiDAutresMagasins()` (`:524-532`), `peutRecevoirUnRattachement()` (`:506-516`), `peutProposerUnRattachement()` (`:471-496`), constante `REFUS_PLUSIEURS_MAGASINS` (`:69`) ; le bouton « Proposer à un compte existant » (`ListMembres.php:174-211`) ; les vues correspondantes.

**Modifiés** : migration de `users` (3.2) ; `User` (`email` nullable, `normaliserEmail` pour les seules adresses, relation `identifiant`, `magasinsAccessibles()` d'un membre = le magasin de son identifiant) ; `FournisseurDesComptes`, `Connexion`, `DemandeDeLien`, `PageDuLien` ; `CreationDesComptes` (identifiant, provisoire généré, plus de contrôle d'adresse pour les non-propriétaires) ; `CreerMembreAction`, `ModifierMembreAction` (un seul magasin ; « déplacer un responsable » disparaît) ; `AffectationsDesMembres` (un seul magasin, celui de l'identifiant) ; `PorteeDesDroits` (`gereLeCompte` : responsable du magasin du compte ; `candidatsResponsables` : comptes de ce magasin seulement ; `peutDonnerUnProvisoire` : tableau de 3.4 ; `peutRetirerDeLaSociete`, `nEstResponsableQueDansSaPortee` simplifiés) ; `DonnerUnMotDePasseProvisoireAction` ; `ChangerSonMotDePasseProvisoireAction` (règle de mot de passe) ; `TransfererProprieteAction` (adresse confirmée, identifiant de l'ancien propriétaire) ; `AutorisationsSociete::libelles()` (« changer leur magasin » disparaît, `app/Domain/Authorization/AutorisationsSociete.php:121`) ; `MembreResource`, `ListMembres`, `FicheDuMembre`, `EquipeDuMagasinResource` et ses pages (formulaires sans adresse obligatoire, fiche d'accès, colonne identifiant pour qui gère le compte, colonne « Ailleurs » supprimée, `EquipeDuMagasinResource.php:154-157`) ; `ModifierProfil` (identifiant affiché en lecture seule) ; `CompteExistantMail` et `InscriptionSociete` (une adresse déjà prise = seulement celle d'un propriétaire) ; `GardesDesDroits` (3.2) ; `TestCase` (`membre()`, `responsable()`, `membreDeCesMagasins()`, `administrateur()`, `expertComptable()`, `compteAuMotDePasseProvisoire()`).

**Créés** : migration `identifiants`, modèle `Identifiant`, service `GenerateurDIdentifiants` (tirage, clé de Luhn, point de sauvegarde, 5 essais), valeur `IdentifiantDeConnexion` (normalisation et contrôle de la clé), `LimitationDesConnexions` (compteurs de 3.5), page ou fenêtre « fiche d'accès », vue imprimable.

**Interactions avec la chaîne** :
- **Lot A4b** (en cours) : son test « un membre sans magasin ouvre /app sans erreur » devient « un compte retiré ne se connecte pas » (un membre a toujours le magasin de son identifiant) ; l'étape « Ajouter votre équipe » produit des fiches d'accès. Le reste de A4b (inscription sans magasin, liste Sirene) n'est pas touché : le propriétaire garde son adresse. D'après la liste des tâches de la chaîne, A4b retouche aussi le chemin « compte retiré » de l'inscription : si Q20-c est confirmée, ce chemin disparaît ; à coordonner (non vérifié dans le code, A4b n'est pas dans le banc).
- **Lot A5** (cycle de vie des magasins) : que deviennent les comptes d'un magasin cédé ou fermé définitivement ? Ils ne servent plus à rien (Q20-g).
- **Lot A6** (R1 à R3) : il se construit sur les nouveaux comptes ; il touche aussi `PermissionRegistry` (Q6-b).
- **Lot A7** : la récupération de compte de l'éditeur ne concerne plus que les propriétaires.
- **Magasin de démonstration** : un membre qui veut s'y entraîner y a besoin d'un compte ; à sa fermeture, ces comptes sont retirés (Q20-h).
- **Place recommandée** : un lot « A4c » juste après A4b et la règle d'arrondi R6 proposée par l'autre livrable, **avant A5, A6 et A7**, qui s'appuient tous sur les comptes ; les points 6 et 7, petits et sans question bloquante hors Q6-a, peuvent passer avant, dans un lot à part.

### 3.8 Tests

**Fichiers supprimés, avec leur destination** (aucun test retiré sans destination) : `tests/Feature/Societe/RattachementTest.php` (16 tests), `RefusDUnCompteTest.php` (20), `RefusDUnCompteConcurrenceTest.php` (2) : supprimés avec leur fonctionnalité si Q20-c est confirmée ; les assertions qui portent sur autre chose (sessions fermées, audit, isolation des sociétés) passent dans les nouveaux fichiers ci-dessous. À recenser test par test au début du lot.

**Réécrits** : `MembresTest` (17 : création sans adresse, un seul magasin, fiche d'accès) ; `MotDePasseProvisoireTest` (10 : tableau de 3.4) ; `PageDuLienTest` (6 : propriétaire seul) ; `FournisseurDesComptesTest` (3 : deux chemins) ; `ModifierProfilTest` (14) ; `TransfererProprieteTest` (9) ; `SelecteurDeMagasinTest` (5 : un membre n'a pas de sélecteur) ; `TracabiliteDesVentesTest` et `TracabiliteDesVentesConcurrenceTest` (« une caisse à la fois » éprouvée sur le propriétaire) ; `AccesAuxPanneauxTest` (8) ; et les 26 appels de `membreDeCesMagasins()` et `responsable($a, $b)` dans 13 fichiers (hors `TestCase` et tests jetables) qui affectent une même personne à deux magasins (`EnvoyerDocumentJobTest.php:451`, `EquipeDuMagasinTest.php:201,232,233,258,343,394`, `RefusDUnCompteTest.php:276`, `AutorisationsDesMembresTest.php:51,184,288,307`, `AdministrateursTest.php:165`, `MotDePasseProvisoireTest.php:54,209,230`, `ResponsablesTest.php:344,404`, `SelecteurDeMagasinTest.php:27,58`, `TracabiliteDesVentesTest.php:192`, `IsolationDesSocietesTest.php:423`, `TracabiliteDesVentesConcurrenceTest.php:43`, `DroitsDuResponsableTest.php:129`, `PorteeDesDroitsTest.php:329,331`) : chacun devient deux comptes, en gardant son intention. Les 287 occurrences de `'email' =>` dans 65 fichiers de test (adresses de clients comprises) sont une borne haute des lignes à relire.

**Nouveaux** :
- `tests/Feature/Societe/IdentifiantsTest.php` : `test_un_compte_cree_par_la_societe_recoit_un_identifiant_de_douze_chiffres_a_cle_valide` ; `test_deux_comptes_n_ont_jamais_le_meme_identifiant_meme_dans_deux_societes` ; `test_un_doublon_d_identifiant_est_retire_sans_interrompre_la_creation` (PostgreSQL et SQLite) ; `test_un_identifiant_ne_se_modifie_ni_ne_s_efface_en_base` ; `test_un_identifiant_n_est_jamais_reattribue_apres_le_retrait_du_compte` ; `test_l_identifiant_d_un_membre_est_lie_a_son_magasin_et_celui_d_un_administrateur_a_la_societe` ; `test_la_base_refuse_un_membre_affecte_hors_du_magasin_de_son_identifiant_ou_a_deux_magasins` ; `test_le_proprietaire_n_a_pas_d_identifiant` ; `test_le_mot_de_passe_provisoire_genere_ne_s_affiche_qu_une_fois_et_n_est_jamais_journalise`.
- `tests/Feature/Societe/ConnexionParIdentifiantTest.php` : `test_un_membre_se_connecte_avec_son_identifiant_avec_ou_sans_espaces` ; `test_le_proprietaire_se_connecte_avec_son_adresse_et_jamais_avec_un_identifiant` ; `test_l_adresse_facultative_d_un_membre_n_ouvre_jamais_son_compte` ; `test_un_identifiant_inconnu_recoit_la_meme_reponse_qu_un_mot_de_passe_faux` ; `test_une_cle_de_controle_fausse_est_signalee_sans_rien_reveler` ; `test_trop_d_echecs_pour_un_identifiant_le_font_attendre_depuis_toute_adresse_ip` ; `test_les_compteurs_s_appliquent_aussi_a_un_identifiant_inexistant` ; `test_un_compte_de_membre_n_ouvre_que_son_magasin` ; `test_mot_de_passe_oublie_ne_sert_qu_au_proprietaire`.
- `tests/Feature/Societe/RenouvellementDAccesTest.php` : `test_le_responsable_renouvelle_l_acces_d_un_vendeur_de_son_magasin` ; `test_un_responsable_ne_renouvelle_ni_un_autre_responsable_ni_un_compte_d_un_autre_magasin` ; `test_un_administrateur_ne_renouvelle_jamais_l_acces_d_un_responsable` (selon Q20-d) ; `test_seul_le_proprietaire_renouvelle_un_administrateur_ou_un_expert_comptable` ; `test_le_renouvellement_ferme_les_sessions_coupe_se_souvenir_de_moi_et_s_inscrit_au_journal_d_audit` ; `test_la_personne_voit_le_renouvellement_a_sa_connexion_suivante`.
- `tests/Feature/Tenancy/IsolationDesSocietesTest.php` (complété) : `test_une_personne_de_deux_magasins_a_deux_comptes_et_ne_voit_dans_chacun_que_ce_qu_il_y_a_fait` ; `test_aucun_ecran_ne_montre_l_identifiant_d_un_compte_que_l_on_ne_gere_pas`.
- `TransfererProprieteTest` (complété) : `test_le_transfert_exige_une_adresse_confirmee_que_n_utilise_aucun_autre_proprietaire` ; `test_l_ancien_proprietaire_recoit_un_identifiant_et_le_nouveau_se_connecte_par_son_adresse`.
- Critères de fin : `git grep -n -E "Rattachement|RefuserSonCompte|peutAffecterAPlusieursMagasins|aAussiDAutresMagasins|CreerSaSocieteAvecUnCompteRetire"` vide dans `app/`, `database/`, `resources/`, `routes/`, `tests/` (si Q20-c) ; aucune connexion par adresse pour un compte qui n'est pas propriétaire.

### 3.9 Risques de sécurité et parades

| # | Risque | Parade |
|---|---|---|
| R1 | **Usurpation par le gestionnaire** : celui qui renouvelle connaît le provisoire ; il peut se connecter, choisir lui-même le mot de passe et agir au nom de la personne (ventes, contre-passations attribuées à elle). Avec un e-mail obligatoire, ce n'était pas possible après la première connexion. | Renouvellement réservé à qui a déjà tous les droits sur ce compte (tableau de 3.4) ; sessions fermées ; audit ; message à la personne à sa connexion suivante et date de dernière connexion ; option de renouvellement par lien pour un compte à l'adresse confirmée (Q20-b). |
| R2 | **Élévation de droits d'un administrateur** : s'il pouvait renouveler l'accès d'un responsable (permis aujourd'hui par un lien), il se connecterait comme lui et vendrait, contre la règle du point 6. | Seul le propriétaire renouvelle un responsable (Q20-d). |
| R3 | **Pulvérisation de mots de passe** (un mot de passe courant essayé sur beaucoup d'identifiants) : les identifiants sont des nombres. | Identifiants aléatoires et clairsemés (3.3) ; limites par adresse IP et par identifiant (3.5) ; mots de passe de 12 caractères au moins ; surveillance des échecs à l'étape 15. |
| R4 | **Blocage volontaire** d'un vendeur par quelqu'un qui connaît son identifiant. | Attente progressive et courte, pas de blocage long ; compteur par paire identifiant et adresse IP avant le compteur global ; alerte au propriétaire. |
| R5 | **Énumération** des identifiants ou des comptes. | Même réponse partout ; compteurs appliqués aux identifiants inexistants ; identifiant jamais séquentiel ni porteur d'information. |
| R6 | **Prise de contrôle par l'adresse facultative** (quelqu'un y met son adresse puis demande « Mot de passe oublié »). | Une adresse de membre n'ouvre jamais le compte ; si Q20-b retient le lien, seulement pour une adresse confirmée par un lien, et avec l'identifiant. |
| R7 | **Postes de caisse partagés** : « se souvenir de moi », ou une session laissée ouverte, fait vendre l'un sous le compte de l'autre ; l'attribution nominative des ventes en souffre. | Retirer « se souvenir de moi » pour les comptes de magasin (Q20-i) ; plus tard, un « changer de vendeur » rapide à la caisse. |
| R8 | **Fiche d'accès** perdue ou photographiée. | Provisoire de 72 heures, changé à la première connexion ; fiche affichée une seule fois. |
| R9 | **Erreur de transfert de propriété** : un identifiant qui ouvrirait encore le compte du nouveau propriétaire, ou deux propriétaires avec la même adresse. | Connexion par identifiant refusée pour un propriétaire ; index unique partiel des adresses de propriétaires ; tests de 3.8. |

### 3.10 Questions à l'éditeur (point 20)

- **Q20-a** : format de l'identifiant : 12 chiffres avec un chiffre de contrôle (recommandé) ou 10 caractères lettres et chiffres ?
- **Q20-b** : à quoi sert l'e-mail facultatif d'un compte autre que le propriétaire ? (1) seulement un contact affiché à qui gère le compte ; (2) recevoir des notifications, après confirmation par un lien ; (3) en plus, « Mot de passe oublié » par un lien quand l'adresse est confirmée (recommandé : il réduit le risque R1). Si (1), aucune confirmation n'est utile.
- **Q20-c** : le refus d'un compte, le rattachement d'un compte retiré à une autre société et la création d'une société avec un compte retiré deviennent sans objet (une personne reçoit un nouveau compte dans chaque société). Je propose de les supprimer : d'accord ?
- **Q20-d** : seul le propriétaire renouvelle l'accès d'un responsable (recommandé, risque R2), ou aussi un administrateur autorisé à nommer les responsables ?
- **Q20-e** : « une caisse à la fois » ne vaut plus que pour le propriétaire ; une même personne pourra avoir deux caisses ouvertes sous deux comptes, dans deux magasins : d'accord ?
- **Q20-f** : un collaborateur d'un cabinet d'expertise comptable qui suit dix sociétés aura dix identifiants : d'accord ?
- **Q20-g** : à la cession ou à la fermeture définitive d'un magasin, ses comptes sont-ils retirés automatiquement ?
- **Q20-h** : comptes du magasin de démonstration : le propriétaire et un responsable peuvent-ils y créer des comptes d'entraînement, retirés à sa fermeture ?
- **Q20-i** : retirer « se souvenir de moi » pour les comptes de magasin (postes de caisse partagés) ?
- **Q20-j** : imposer 12 caractères minimum aux mots de passe définitifs ; vérifier aussi qu'un mot de passe n'a pas déjà été divulgué (service externe, derrière une interface) ?

---

## 4. Point 8 : pièce jointe d'une facture d'achat

### 4.1 Ce qui est déjà prévu (vérifié dans le plan)

Le **lot CH1** du plan TVA couvre la demande en entier (`scratchpad/tva/plan-execution.md`, section 4.6, l. 1866-1900, et lot 5.15, l. 2302-2313) : table `pieces_justificatives` (`BelongsToMagasin`, jamais supprimée en base), fichiers chiffrés sur un disque privé `justificatifs`, types lus sur le contenu (PDF, Factur-X, JPEG, PNG, XML UBL ou CII sans DOCTYPE ni ressource externe), 10 Mo et 20 pièces au plus, retrait sur un brouillon, remplacement d'une pièce déposée par erreur avec un motif, téléchargement contrôlé par la policy, journal d'audit sans le nom du fichier, texte « Copie de consultation : conservez l'original… pendant 10 ans ». 23 tests sont nommés.

Dans le banc : aucune pièce jointe, aucun disque `justificatifs`, aucune classe `PieceJustificative` ni `CodeDeManquement` (recherche vide) ; le disque privé `verification` (`config/filesystems.php:44-46`) et le service `PiecesDeVerification` du lot A3 servent de modèle, comme le plan le prévoit.

### 4.2 Écart et compléments proposés

- **Calendrier** : CH1 est placé tard, après le moteur des achats et le lot M6 (`plan-execution.md`, 5.0, l. 2010-2040), parce que les charges exigent une pièce. Le point 8 seul (joindre une pièce à une facture d'achat de marchandises) ne dépend d'aucun lot du moteur : seuls `FactureAchatResource` (réécrit au lot M6) et les codes `piece.*` (dans `CodeDeManquement`, qui n'existe pas encore) le touchent ; les messages peuvent d'abord être ceux de l'action. CH1 peut donc être avancé si l'éditeur veut la fonction tôt ; la décision revient à l'orchestrateur.
- **Avoirs fournisseur** : CH1 ne prévoit de pièces que pour les factures d'achat (et les salaires au lot CH4). L'avoir papier ou PDF du fournisseur est une pièce de même nature : proposer d'étendre la colonne de rattachement (`avoir_fournisseur_id`, « exactement un document » garanti comme au lot CH4).
- **Pièce exigée ou non** : le plan exige une pièce pour valider une charge (`plan-execution.md`, l. 1786), pas pour une facture de marchandises. Question **Q8**.

### 4.3 Question à l'éditeur

- **Q8** : la validation d'une facture d'achat de marchandises exige-t-elle une pièce jointe (recommandé : non, mais un rappel « aucune pièce jointe ») ? Une pièce se joint-elle aussi à un avoir fournisseur (recommandé : oui) ?

---

## 5. Point 18 : un produit naît d'un achat ou d'un inventaire d'ouverture

### 5.1 Constats (code lu, essai exécuté)

- La création existe : bouton « Nouveau » (`app/Filament/App/Resources/ProduitResource/Pages/ListProduits.php:14-19`), page `create` (`app/Filament/App/Resources/ProduitResource.php:318-325`), avec un « Prix d'achat HT » saisi à la création (`:243-252`). Essai : la page s'ouvre pour le propriétaire et crée « Produit sans achat » sans fournisseur, coût 3,0000, stock 0.
- Déjà conforme pour la modification : le fournisseur n'est jamais saisi (`ProduitResource.php:232-235`, celui du dernier achat validé) ; le coût moyen ne se modifie pas (`disabledOn('edit')`, `:252`) ; un produit reçu par une facture automatique garde sa référence et son nom (`:216-228`) ; les lignes d'achat gardent leur propre copie de la désignation et de la référence (`database/migrations/2026_10_07_100002_create_lignes_achat_table.php:28-29`), si bien que modifier la fiche ne change pas la facture. Tests existants : `tests/Feature/Filament/ProduitResourceTest.php:182` (coût) et `:371` (fournisseur forcé ignoré).
- La création par un achat existe : « Nouveau produit » dans une ligne d'achat, visible avec `produit.create` (`app/Filament/Concerns/AjouteDesLignesDAchat.php:229`), créé seulement à la validation (`app/Domain/Achats/Services/ConstructeurLigneAchat.php:183,226`), qui exige le droit de créer un produit (`:345`). Le catalogue de démonstration crée déjà ses produits par une facture d'achat validée (`app/Domain/Tenancy/Services/CatalogueDeDemonstration.php:18,46-48`).
- `produit.create` sert aussi aux services (`app/Policies/ServicePolicy.php:29`) : il reste, avec le sens « créer un produit par un achat ou un inventaire d'ouverture, et créer un service ».
- **L'inventaire d'ouverture n'existe pas** : il est prévu à l'étape 7 (feuille de route ; `conception-v2-finale.md`, l. 1574). Retirer la création dans « Produits » sans lui empêcherait une société qui démarre avec un stock existant d'enregistrer ses produits, sauf par une fausse facture d'achat.

### 5.2 Règle proposée (puce « Achats et stock »)

> Un produit naît seulement de la validation d'une facture d'achat ou d'un inventaire d'ouverture (stock existant au démarrage, avec son coût) ; ensuite il se modifie dans « Produits », sauf son fournisseur et ses données d'achat (coût, quantités reçues), que seuls les achats, les retours et les inventaires font évoluer.

### 5.3 Conception

1. Retirer la page `create` et le bouton (`ProduitResource::getPages()`, `ListProduits::getHeaderActions()`), le champ « Prix d'achat HT » et les branches `$operation === 'create'` du formulaire ; `ProduitPolicy::create` reste (droit de créer un produit par un document).
2. **Inventaire d'ouverture minimal**, avancé avec ce retrait (**Q18-a**) : un document du magasin (`inventaires_ouverture` et `lignes_inventaire_ouverture`, `BelongsToMagasin`, `FigeLeNomDeSesAuteurs`), brouillon puis validé, numéroté dans la série du magasin ; chaque ligne : produit nouveau (fiche : référence, nom, prix de vente TTC, taux de TVA, suivi du stock) ou produit existant jamais reçu, quantité, coût unitaire HT ; la validation crée les produits, l'entrée en stock (nouveau type de mouvement `InventaireOuverture`, au coût saisi, coût moyen initial) et une entrée au journal d'audit ; aucune TVA, aucun fournisseur. Le comptage, les ajustements et l'import restent à l'étape 7.
3. **Garantie de l'origine**, deux options :
   - (a) contrôle applicatif seulement : seuls `ConstructeurLigneAchat` et l'action de validation de l'inventaire créent un produit ; critère de fin `git grep -n -E "new Produit|Produit::create|produits\(\)->create" app` limité à ces deux classes ;
   - (b) en base : colonnes `origine_type` et `origine_id` non nulles sur `produits` (la facture d'achat ou l'inventaire qui l'a créé), écrites une fois. Plus sûre, mais la fabrique `Produit::factory()` (658 appels dans 106 fichiers de test, vérifié par recherche) doit alors créer une origine : coût à évaluer au début du lot (« à vérifier »).
   Recommandé : (b) si le coût de la fabrique reste raisonnable, sinon (a).
4. Tests : `test_la_liste_des_produits_n_a_plus_de_bouton_nouveau` ; `test_la_page_de_creation_d_un_produit_n_existe_plus` ; `test_un_produit_nait_de_la_validation_d_une_facture_d_achat` (existant à relier) ; `test_un_produit_nait_de_la_validation_d_un_inventaire_d_ouverture_avec_son_cout` ; `test_l_inventaire_d_ouverture_entre_le_stock_au_cout_saisi_et_s_inscrit_au_journal_d_audit` ; `test_le_fournisseur_et_le_cout_ne_se_modifient_pas_dans_produits` (réécriture de `ProduitResourceTest.php:182`) ; isolation de l'inventaire entre magasins. Les 14 appels de `CreateProduit::class` dans `ProduitResourceTest` et `FournisseurProduitResourceTest` sont à réécrire en passant par une facture d'achat ou la page de modification, sans perdre leur assertion (prix TTC enregistré tel quel, référence déjà prise, taux vide, etc.).

### 5.4 Questions à l'éditeur

- **Q18-a** : avancer un inventaire d'ouverture minimal (saisie à la main, sans import ni comptage) dans le même lot que le retrait de « Nouveau produit » ? Recommandé : oui, sinon une société qui a déjà du stock ne peut pas démarrer.
- **Q18-b** : quand l'inventaire d'ouverture est-il permis : seulement avant la première vente du magasin, ou à tout moment pour un produit jamais reçu (stock retrouvé, reprise) ? Et par qui (recommandé : qui peut valider un achat) ?

---

## 6. Point 19 : avoir fournisseur créé à la main depuis une ligne d'achat

### 6.1 Constats (code lu, essai exécuté)

Sortie de `Serie8AchatsConstatsTest::test_points_18_et_19` (deux achats du même produit : 10 à 10 € puis 10 à 20 € ; retour de 4 sur la première facture par l'action existante) :

| Vérification | Résultat |
|---|---|
| Après les deux achats | stock 20, coût moyen 15,0000 |
| Actions d'en-tête de la fiche d'une facture d'achat | aucune |
| Actions d'en-tête du menu « Avoirs fournisseur » ; page de création | aucune ; pas de page `create` |
| Avoir créé par l'action « Avoir fournisseur » d'une ligne de la **liste** des factures d'achat | `AF-MABOUT-2026-000001`, source `manuelle`, lié à la facture d'achat et à sa ligne |
| Sortie de stock | au coût de la ligne retournée, 10,0000 ; stock 16 ; coût moyen recalculé 16,2500 (soit (300 − 40) / 16) |
| L'avoir manuel figure dans le menu « Avoirs fournisseur » | oui |
| La fiche d'achat montre la section « Avoirs fournisseur émis » / le retour sous la ligne | oui / non |

Donc l'essentiel de la demande existe déjà :
- action « Avoir fournisseur » dans les actions de ligne de la liste des factures d'achat (`app/Filament/App/Resources/FactureAchatResource.php:196-229`), qui appelle `CreerAvoirFournisseurAction` puis `EmettreAvoirFournisseurAction` ;
- un avoir ne porte que des lignes de la facture créditée (`app/Domain/Achats/Actions/CreerAvoirFournisseurAction.php:78-82`), plafonnées à ce qui reste retournable et en stock (`:84-89`, `LigneAchat::quantiteRetournableRestante()`), sans jamais modifier la facture d'achat (docblock `:18-22`) ; une facture d'achat validée qui a un avoir ne se modifie plus (`app/Domain/Achats/Models/FactureAchat.php:149-160`) ;
- sortie de stock au coût de la ligne et coût moyen recalculé (`app/Domain/Achats/Listeners/DecrementerStockApresEmissionAvoirFournisseur.php:21-36`, `app/Domain/Stock/Services/StockLedgerService.php:145-164`), conforme à `CLAUDE.md` ;
- test existant : `tests/Feature/Filament/AvoirFournisseurResourceTest.php:47-74`.

Ce que l'éditeur n'a pas trouvé, et pourquoi :
- le menu « Avoirs fournisseur » est en lecture seule (docblock `app/Filament/App/Resources/AvoirFournisseurResource.php:16-21` : « un avoir fournisseur se crée uniquement depuis l'action rapide "Avoir fournisseur" de FactureAchatResource ») ; ni bouton ni page de création (`:98-104`, `Pages/ListAvoirsFournisseur.php`) ;
- la fiche d'une facture d'achat n'a aucune action (`Pages/ViewFactureAchat.php`) ;
- **aucune API n'existe** : `routes/` ne contient que `web.php`, `bootstrap/app.php:24-27` n'enregistre aucune route d'API, `php artisan route:list --path=api` ne trouve rien ; la source `Api` (`app/Domain/Achats/Enums/SourceAvoirFournisseur.php:7-16`) n'anticipe qu'une synchronisation future. Aucun avoir « n'arrive par l'API » aujourd'hui.

Correction d'un autre livrable de la série : `serie8/arrondi-et-calculs.md` (section 4, ligne du point 19) dit que « la saisie à la main d'un avoir lié à une ligne d'achat … [n'est décrite] dans aucun lot : à ajouter » ; c'est exact pour les plans, mais la saisie à la main existe déjà dans le code, seulement mal placée.

### 6.2 Ce qui manque et conception

1. **Créer depuis le menu** : action d'en-tête « Créer un avoir fournisseur » dans `ListAvoirsFournisseur` : choisir une facture d'achat validée du magasin qui a encore des lignes retournables (règle `exists` limitée au magasin), puis les lignes et quantités, le motif ; même formulaire et même chemin du domaine que l'action actuelle (à factoriser dans `FactureAchatResource`, sans dupliquer `lignesRetournables()` ni `regleStockDuProduitRetourne()`, `FactureAchatResource.php:245-295`).
2. **Créer depuis la fiche d'achat** : la même action en en-tête de `ViewFactureAchat`, et, sur chaque ligne retournable, « Retourner ce produit » qui préremplit cette seule ligne.
3. **Retours sous chaque ligne de la fiche d'achat, en orange** : dans `FactureResource::lignesEntry()` (`app/Filament/App/Resources/FactureResource.php:507-543`), pour une `LigneAchat`, le `helperText` de la désignation (`:519-520`, qui affiche déjà « Nouveau produit, créé à la validation ») dit « Retourné : 4 (avoir AF-MABOUT-2026-000001 du 17/10/2026) », une mention par avoir émis, avec la couleur et le style de `ClientResource::enEvidence()` (`app/Filament/App/Resources/ClientResource.php:460-462`, `#c2410c`, gras), celle du crédit d'avoir affiché sous un client, comme le demande l'éditeur ; lien vers la fiche de l'avoir. Ajouter sous les totaux « Total après avoirs fournisseur ».
4. **Lien obligatoire** : `lignes_avoir_fournisseur.ligne_achat_id` est aujourd'hui nullable avec `nullOnDelete` (`database/migrations/2026_10_07_100004_create_lignes_avoir_fournisseur_table.php:19`) ; la règle de l'éditeur (« un avoir ne se fait qu'à partir d'un produit qui figure sur une facture d'achat ») demande une colonne non nulle, `restrictOnDelete`.
5. **N° de l'avoir du fournisseur** : ajouter `numero_fournisseur` (comme sur la facture d'achat, `FactureAchatResource.php:80-83`) pour le rapprochement avec le document du fournisseur, et la pièce jointe (4.2).
6. **Trace** : ajouter au journal d'audit `AvoirFournisseurEmis` (catégorie opérations), qui manque comme pour toutes les opérations commerciales (étape 4 de la feuille de route ; `ActionAuditee.php` n'a aucune entrée d'achat aujourd'hui).
7. **Avec la réception SUPER PDP (étape 2)** : un avoir reçu (type 381) arrive en brouillon ; chaque ligne doit être rattachée à une ligne de la facture d'achat qu'il cite (proposée par la référence du fournisseur ou le code-barres, confirmée à la main) avant d'être émis ; s'il correspond à un avoir déjà saisi à la main pour le même retour, on les rapproche au lieu de sortir deux fois le stock. Le cas « avoir reçu sans retour de marchandise » est déjà une question ouverte du plan SUPER PDP (`scratchpad/superpdp-plan.md`, l. 993).
8. **Recoupement avec le plan TVA** : le lot M5e recalculera les montants d'un retour (`CalculDesRetours`, TVA rendue sans dépasser la part de la ligne, `plan-execution.md` 3.17.6 et 5.13) et le lot M6 réécrira les écrans d'achat ; ni l'un ni l'autre ne décrit le bouton du menu, l'action sur la fiche ni l'alerte orange. Les points 1 à 3 ci-dessus ne touchent pas au calcul : ils peuvent se faire tout de suite (petit lot d'écrans) ou s'ajouter au lot M6 ; les points 4 et 5 (schéma) se font dans la migration de création, avant M5e.
9. Tests : `test_un_avoir_fournisseur_se_cree_depuis_le_menu_avoirs_fournisseur` ; `test_un_avoir_fournisseur_se_cree_depuis_la_fiche_de_la_facture_d_achat` ; `test_le_choix_d_une_facture_d_achat_se_limite_au_magasin_et_aux_factures_validees_retournables` (requête forgée avec une facture d'un autre magasin) ; `test_chaque_retour_s_affiche_en_orange_sous_sa_ligne_avec_le_numero_de_l_avoir` ; `test_une_ligne_d_avoir_fournisseur_sans_ligne_d_achat_est_refusee_par_la_base` ; `test_l_emission_d_un_avoir_fournisseur_s_inscrit_au_journal_d_audit` ; `test_un_vendeur_sans_avoir_fournisseur_creer_ne_voit_pas_le_bouton`.

### 6.3 Questions à l'éditeur

- **Q19-a** : un produit déjà vendu ne peut pas être retourné au fournisseur (règle actuelle : la quantité retournable est plafonnée au stock disponible) ; on la garde ? (Recommandé : oui ; à revoir avec la vente sans stock du lot A6, où le stock peut être négatif.)
- **Q19-b** : un avoir « financier » sans retour de marchandise (remise accordée après coup, ristourne, erreur de prix du fournisseur) se saisit-il aussi ici, sans sortie de stock ? Il touche le coût des produits : règle à faire confirmer par l'expert-comptable de la société.

---

## 7. Point 9 : création automatique des produits depuis une facture d'achat (PDF ou Excel)

L'éditeur donnera ses règles quand on les lui demandera. Ce qu'il faut lui demander :

1. **Sources** : facture électronique structurée (Factur-X, UBL, CII), fichier Excel ou CSV (avec quelles colonnes, un modèle imposé ou libre ?), PDF « image » ou PDF de texte sans données structurées ? Un PDF sans données structurées demande une lecture par un service externe (reconnaissance de caractères ou intelligence artificielle) : accepte-t-il un tel prestataire, son coût, ses erreurs et l'envoi des factures à un tiers ? Comme tout fournisseur externe, il serait derrière une interface, avec une implémentation factice.
2. **Reconnaissance d'un produit existant** : par la référence du fournisseur (qui diffère souvent de celle du magasin : faut-il une table de correspondance par fournisseur ?), par le code-barres, par le nom ?
3. **Produit inconnu** : créé d'office ; avec quel prix de vente (le barème de prix de vente du magasin existe, `PRIX_VENTE_REGLER`) et quel taux de TVA de vente ?
4. **« Directement en stock »** : `CLAUDE.md` (puce SUPER PDP) dit déjà que les factures reçues s'importent en brouillon et que l'entrée en stock reste une validation manuelle. L'éditeur veut-il changer cette règle pour les imports, ou un brouillon validé d'un clic lui suffit-il ? Recommandé : brouillon et validation humaine (le coût, la TVA déductible et le stock en dépendent).
5. **Cas difficiles** : quantités décimales, unités de poids ou de volume, lots (« carton de 12 »), articles gratuits, remises et frais de pied de facture, plusieurs taux, devises, avoirs dans le même fichier, facture importée deux fois (même fournisseur et même numéro).
6. **Droits** : qui importe (recommandé : `facture_achat.creer` et `produit.create`) ; le fichier importé devient-il d'office la pièce justificative (lot CH1) ?

Liens avec la feuille de route :
- **Étape 2 (SUPER PDP)** : les factures reçues sont déjà prévues en brouillon, produit inconnu créé, fournisseur reconnu par son SIRET, cas non gérés en saisie manuelle (`CLAUDE.md`, puce « Facture électronique ») ; les mêmes cas difficiles y sont une question ouverte (`scratchpad/superpdp-plan.md`, l. 993). La réception est obligatoire pour toutes les entreprises depuis le 1/9/2026 ; l'émission l'est pour les PME et micro-entreprises au 1/9/2027 (`scratchpad/tva/reglementation.md`, l. 47, recherche antérieure, non revérifiée ici) : d'ici là, beaucoup de fournisseurs enverront encore des PDF. Recommandé : un seul service d'import de facture d'achat structurée, alimenté d'abord par SUPER PDP (étape 2), puis par un fichier Factur-X, UBL ou CII déposé à la main (facile, mêmes données), puis par un modèle Excel ou CSV, et en dernier par la lecture d'un PDF libre.
- **Étape 7 (stock)** : l'import d'un catalogue (reprise d'un établissement) et l'inventaire d'ouverture (point 18) partagent la reconnaissance des produits et la lecture d'un tableur : à concevoir ensemble.
- **Point 18** : un produit créé par un import naît bien d'une facture d'achat validée : la règle reste cohérente.

---

## 8. Récapitulatif des questions à poser à l'éditeur

| Code | Question | Recommandation |
|---|---|---|
| Q6-a | Gestes de « vente » interdits à l'administrateur | Tickets, factures, encaissements, avoirs et devis ; contre-passation et relances restent cochables |
| Q6-b | Prix de référence d'un service au catalogue par un administrateur | Oui, s'il est autorisé |
| Q7 | « Les caisses » de tous ses magasins dans l'espace société | Non pour l'instant |
| Q20-a | Format de l'identifiant | 12 chiffres avec clé de contrôle |
| Q20-b | Usage de l'e-mail facultatif | Contact, notifications et « Mot de passe oublié » une fois confirmé |
| Q20-c | Suppression du refus, du rattachement et de la société créée par un compte retiré | Oui |
| Q20-d | Renouvellement d'un responsable par un administrateur | Non, propriétaire seul |
| Q20-e | « Une caisse à la fois » limitée au propriétaire | Oui |
| Q20-f | Un identifiant par société pour un expert-comptable | Oui |
| Q20-g | Comptes d'un magasin cédé ou fermé définitivement | Retirés automatiquement |
| Q20-h | Comptes d'entraînement du magasin de démonstration | Oui, retirés à sa fermeture |
| Q20-i | « Se souvenir de moi » sur les comptes de magasin | Retiré |
| Q20-j | Longueur minimale et contrôle des mots de passe divulgués | 12 caractères ; service externe seulement avec son accord |
| Q8 | Pièce exigée pour valider un achat ; pièce sur un avoir fournisseur | Non ; oui |
| Q18-a | Inventaire d'ouverture minimal avancé | Oui, avec le retrait de « Nouveau produit » |
| Q18-b | Quand et par qui l'inventaire d'ouverture | Avant la première vente ou pour un produit jamais reçu ; qui valide les achats |
| Q19-a | Pas de retour d'un produit déjà vendu | Garder |
| Q19-b | Avoir financier sans retour | À confirmer par l'expert-comptable |
| Point 9 | Les six questions de la section 7 | Brouillon et validation humaine |

## 9. Place dans la chaîne (proposition à l'orchestrateur)

1. Lot A4b (en cours), puis la règle d'arrondi R6 si l'orchestrateur suit l'autre livrable.
2. **Points 6 et 7** : petit lot (registre, garde en base, menu « Les caisses »), dès la réponse à Q6-a ; il précède A6, qui ajoute deux autorisations de vente.
3. **Point 20** : lot « A4c », après les réponses Q20-a à Q20-d, avant A5, A6 et A7.
4. **Point 19, écrans** (bouton du menu, action sur la fiche, retours en orange) : petit lot indépendant du calcul, possible tout de suite ; schéma (ligne d'achat obligatoire, n° du fournisseur) dans la migration de création, avant M5e.
5. **Point 18** : avec l'inventaire d'ouverture minimal (Q18-a), sinon à l'étape 7.
6. **Point 8** : lot CH1 tel que conçu, avancé si l'éditeur le souhaite.
7. **Point 9** : après les réponses de l'éditeur, avec l'étape 2 et l'étape 7.
