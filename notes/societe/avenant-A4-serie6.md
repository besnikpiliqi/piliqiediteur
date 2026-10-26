# Avenant au lot A4 : série 6 de l'éditeur (équipe, expert-comptable, retrait de Spatie, refus d'un compte, e-mails)

Rédigé le 2026-10-11. Version finale, après deux critiques de la version précédente (traitement en annexe B ; la version précédente est gardée dans `scratchpad/societe/avenant-A4-serie6.version-precedente.md`).

**Cet avenant prime sur `conception-v2-finale.md` et sur `scratchpad/spatie/recommandation-finale.md`** pour tout ce qu'il traite. Il modifie le lot A4 et, là où il le dit, les lots A5, A6 et A7, le lot de décisions 21 et l'étape 5. Pour tout ce qu'il ne cite pas, la conception reste la règle. La section 9 liste ce qui ne change pas. L'annexe A donne la correspondance avec les sections de la conception qu'il remplace ; l'annexe B, le traitement de chaque critique.

---

## 0. Lire cet avenant

### 0.1 Sources

- Réponses de l'éditeur, série 6, verbatim : `scratchpad/societe/reponses-serie6.md`, y compris la précision sur l'expert-comptable et ses trois réponses (lignes 34 à 44). Elles priment sur la conception et sur `CLAUDE.md`.
- Formulation du refus d'un compte retenue par l'éditeur : `scratchpad/spatie/reponse-editeur.md`, section 9.
- Analyses : `scratchpad/spatie/recommandation-finale.md` (garanties 5.1, tests 5.2, défauts 5.3, refus 6, reste à écrire 7.4), `scenarios-securite.md` (87 scénarios, sondes S-*), `code-actuel-et-a4.md` (HEAD et travail A4), `refus-de-compte.md` (écarts E1 à E12).
- Conception : `scratchpad/societe/conception-v2-finale.md` (sections 1 à 4, 8.6, 12, 14 « Lot A4 », annexe B).
- Travail non commité du lot A4, lu sans rien écrire : `git -C /home/user/piliqiediteur status` et `diff`, fichiers non suivis.

### 0.2 Références employées

| Code | Renvoie à |
|---|---|
| [É-msg] | message de l'éditeur (« le proprietaire de la societe peut faire tout… ») |
| [É-P] | réponse libre, « 1. Le Propriétaire » : bypass total, seul à créer les administrateurs et à nommer les responsables |
| [É-A] | réponse libre, « 2. L'Administrateur » : gestion globale ; pas de responsables sans l'autorisation du propriétaire |
| [É-R] | réponse libre, « 3. Le Responsable de magasin » (corrigée) : plusieurs par magasin, tous les droits dans son magasin, lui seul ou le propriétaire crée ses vendeurs et leur attribue des autorisations valables pour ce seul magasin |
| [É-V] | réponse libre, « 4. Le Vendeur » : droits strictement limités à son magasin |
| [É-nombre] | « Plusieurs par magasin » |
| [É-droits] | « Tout le magasin, pas la société » |
| [É-obs] | « Le responsable aussi » (ligne 25), restreint par la ligne 43 : le responsable crée les vendeurs et les observateurs de son magasin ; il n'existe plus de comptable de magasin |
| [É-nommer] | « Nommer et retirer » |
| [É-refus] | « Adresse jamais confirmée », avec la formulation de `reponse-editeur.md` section 9 |
| [É-e-mail] | « Non, on revérifie » |
| [É-EC-créé] | précision de l'éditeur (lignes 36 à 39) : l'expert-comptable de la société est créé uniquement par le propriétaire, et seul le propriétaire peut le retirer |
| [É-EC-accès] | « Tous, en lecture » (ligne 42) : tous les magasins de la société, présents et futurs, en lecture seule (factures, avoirs, paiements, achats, clôtures, journaux et téléchargements) ; il ne vend, ne modifie et ne supprime rien |
| [É-EC-rôle] | « Non, un seul expert-comptable » (ligne 43) : plus de rôle « comptable » de magasin, un seul rôle « expert-comptable » de société, créé et retiré par le propriétaire seul |
| [É-EC-export] | « Oui » (ligne 44) : l'expert-comptable produit et télécharge lui-même l'export comptable de la société ; chaque export s'inscrit au journal d'audit |
| [É-Spatie] | délégation de l'éditeur (« c'est toi qui dois décider ») et décision de Claude : retrait, référentiel des autorisations en base |
| [CL] | règle de `CLAUDE.md` gardée telle quelle |
| [CO x.y] | section de la conception gardée |
| [RF x.y] | section de `recommandation-finale.md` |
| [SC Xn] | scénario de `scenarios-securite.md` |
| [CA x.y] | section de `code-actuel-et-a4.md` |
| [E1]…[E12] | écarts de `refus-de-compte.md`, section 7 |
| [lu] | constaté dans le dépôt pour cet avenant, chemin et ligne cités |
| [I-n] | interprétation d'une réponse de l'éditeur, à lui signaler (section 8) |
| [Cn] | critique n de la version précédente (annexe B) |

### 0.3 Les décisions, en bref

1. **Propriétaire** : aucune autorisation ne lui est jamais demandée [É-P]. Les règles qui valent pour tous s'appliquent à lui [I-2].
2. **Administrateurs** : le propriétaire seul les crée et les gère, avec leur accès (tous les magasins, présents et futurs, ou une sélection) et leurs autorisations [É-P, CL].
   - Ils ne créent ni ne gèrent jamais les vendeurs et les observateurs, sauf pour nommer l'un d'eux responsable [É-R, I-4].
   - La nouvelle autorisation de société « nommer et retirer les responsables » (`societe.responsables.nommer`) leur permet de nommer, retirer et changer les magasins des responsables, dans les magasins de leur portée seulement [É-A, É-nommer]. Elle **remplace** `societe.membres.gerer`. Le propriétaire est prévenu de chaque responsable qu'un administrateur nomme [I-4].
3. **Responsables** : un magasin peut en avoir plusieurs, et une même personne peut être responsable de plusieurs magasins [É-nombre].
   - Un responsable a tous les droits dans son magasin, **sans aucune case** ; il n'a jamais ceux de la société [É-R, É-droits].
   - Avec le propriétaire, lui seul crée et gère les vendeurs et les observateurs de son magasin [É-R, É-obs].
4. **Vendeurs et observateurs** : leur rôle n'est qu'un modèle qui précoche des cases, valables dans un seul magasin [CL, É-V]. **Le rôle « comptable » de magasin disparaît** [É-EC-rôle].
5. **Expert-comptable** : un rôle de société, au plus un compte par société [I-21], que seul le propriétaire crée, suspend, réactive, retire et réintègre [É-EC-créé, É-EC-rôle]. Il lit tous les magasins réels de la société, présents et futurs, et n'écrit rien : ni vente, ni modification, ni suppression, ni envoi [É-EC-accès, I-22, I-23]. À l'étape 5, il produit et télécharge lui-même l'export comptable de la société, et chaque export s'inscrit au journal d'audit [É-EC-export].
6. **Refus d'un compte** : possible tant que l'adresse n'a jamais été confirmée par un lien [É-refus].
   - `users.premiere_connexion_le` disparaît ; `users.email_verified_at` est posé par tout lien suivi, et par rien d'autre.
   - Un refus rend le mot de passe inutilisable et ferme toutes les sessions du compte ; la personne choisit ensuite son mot de passe sur la page d'un lien envoyé à son adresse [I-28].
7. **Historique** : il garde le nom de chaque personne tel qu'il était au moment de chaque opération [É-refus].
8. **E-mails** : au moment de l'envoi, PILIQI revérifie que la personne qui l'a demandé a encore accès au magasin et le droit d'envoyer ; sinon rien ne part et le propriétaire est prévenu [É-e-mail].
9. **Spatie** : retiré définitivement. Son seul avantage propre est repris : la base refuse toute autorisation inconnue, grâce à deux tables de référence remplies par migration et à des clés étrangères [É-Spatie].
10. **Défauts** : les défauts prouvés à HEAD et dans le travail A4 sont corrigés, chacun avec son test de régression (section 5).

### 0.4 État du travail A4 non commité (lu)

- **Fait.** Le schéma : `affectations`, `autorisations_affectations`, `autorisations_societe`, `demandes_rattachement`, colonnes de `users`. Le retrait de Spatie dans `composer.json` et `composer.lock`. Le domaine des droits : `User`, `Affectation`, `ControleDesDroits`, `PorteeDesDroits`, `JournalDesDroits`, `LecteurDesDroits`, `AutorisationsSociete`, `DestinatairesDesAlertes`. Les policies `MembrePolicy`, `MagasinDeLaSocietePolicy` et `SocietePolicy`. La suppression de l'ancienne équipe du panneau magasin.
- **Non fait.** Aucune action d'équipe, aucun écran, aucun test réécrit.
- **Contraire à la série 6.** `RoleSociete` n'a que trois cas, `Proprietaire`, `Administrateur` et `Membre` (`app/Domain/Tenancy/Enums/RoleSociete.php`). `RoleMagasin` garde un modèle `Comptable` qui coche « enregistrer un paiement », « contre-passer un paiement », « envoyer les factures » et « envoyer les avoirs » (`app/Domain/Tenancy/Enums/RoleMagasin.php:73-88`) [lu].
- **Suite sur une copie** : 2 375 tests, dont 1 155 erreurs ; 1 147 viennent de l'erreur de type de `User::doitChangerSonMotDePasse()` [CA 2.4].

---

## 1. Textes de `CLAUDE.md`

À écrire au début du lot A4 révisé, avant tout code. Les puces que 1.1 à 1.6 ne citent pas ne changent pas ; pour celles qui parlent des responsables, des administrateurs, de l'expert-comptable ou des autorisations, 1.7 dit pourquoi.

### 1.0 Ce qui entre dans `CLAUDE.md`

**Un seul critère** [C9]. Les textes de `CLAUDE.md` portent les décisions de l'éditeur **et** toutes les interprétations retenues qui fixent qui peut faire quoi : I-2, I-3, I-4, I-5, I-6, I-7, I-18, I-19, I-21, I-22, I-23, I-24 et I-28 (section 8), ainsi que les règles G10, G11 et G14 qui en découlent.

**Pourquoi ce choix.**
- Le code applique ces interprétations, et les sessions suivantes ne relisent que `CLAUDE.md` : une règle qui n'y serait pas serait défaite par la première session qui alignerait le code sur `CLAUDE.md`.
- Un « bypass total » du propriétaire écrit sans sa limite (I-2) laisserait supprimer une facture émise ou vendre dans un magasin fermé.

La section 8 soumet ces interprétations à l'éditeur. S'il en décide autrement, `CLAUDE.md` est corrigé en même temps que le code. Les détails d'écran et de mise en œuvre restent dans l'avenant.

### 1.1 Puce « Comptes et équipe » : texte intégral, qui remplace l'actuelle

> - Comptes et équipe : un compte n'appartient qu'à une seule société ; une fois retiré par elle, la personne peut, avec son accord et avec le même compte, rejoindre une autre société ou créer la sienne, jamais avant. Une adresse e-mail déjà utilisée ne sert jamais à un nouveau compte. Une adresse e-mail s'enregistre et se compare en minuscules, sans espaces autour : A@x.fr et a@x.fr sont la même adresse. Chacun change lui-même son nom, son adresse de connexion et son mot de passe dans son profil, en donnant son mot de passe actuel ; une nouvelle adresse ne prend effet qu'après confirmation par un lien envoyé à cette adresse, et l'ancienne est prévenue ; personne ne change le nom ni l'adresse d'un autre compte. Une société s'inscrit par un lien envoyé à l'adresse de son propriétaire, valable 24 heures. Elle a un seul propriétaire, qui a tous les droits dans la société et dans chacun de ses magasins sans qu'aucune autorisation ne lui soit demandée ; les règles qui valent pour tous (lecture seule, validation de la société, documents émis, clôtures, une caisse à la fois) s'appliquent à lui comme aux autres. Seul le propriétaire crée et gère les administrateurs : il choisit l'accès de chacun à tous les magasins, présents et futurs, ou à une sélection de magasins, et coche ses autorisations. Un administrateur ne crée ni ne gère jamais les vendeurs et les observateurs, sauf pour nommer l'un d'eux responsable dans un magasin de sa portée, s'il y est autorisé ; il ne nomme, ne retire et ne change les magasins des responsables que si le propriétaire lui en a donné l'autorisation (« nommer et retirer les responsables »), et seulement dans les magasins auxquels il a accès ; le propriétaire est prévenu de chaque responsable qu'il nomme ; il ne suspend, ne réactive ni ne rétrograde jamais un responsable ; il peut seulement retirer de la société une personne qui n'est que responsable de magasins tous dans sa portée, et lui renouveler son accès par un lien ; il ne touche jamais au propriétaire, à un autre administrateur ni à l'expert-comptable. La société a au plus un expert-comptable, dont seul le propriétaire crée, suspend, réactive, retire et réintègre le compte et renouvelle l'accès : il n'a ni affectation ni case ; dans chacun des magasins réels de la société, présents et futurs, il a toutes les autorisations de consultation et aucune autre (documents, paiements, achats, clôtures, fiches, registre et journal des événements, téléchargements ; jamais le journal d'audit ni les adresses IP et navigateurs) ; il ne vend, ne modifie, ne supprime et n'envoie rien, ne gère aucun compte, et produit et télécharge lui-même l'export comptable de la société, chaque export étant inscrit au journal d'audit. Un magasin peut avoir plusieurs responsables, et une même personne peut être responsable de plusieurs magasins ; c'est le propriétaire qui les nomme, ou un administrateur ainsi autorisé. Un responsable a tous les droits dans son magasin, sans case (ventes, remises et prix, avoirs, achats, stock, caisse et clôtures, paramètres du magasin, équipe du magasin), jamais ceux de la société (identité et réglages fiscaux, création, fermeture, réouverture et cession de magasins, plateforme agréée, journal d'audit, administrateurs, expert-comptable et responsables) ; il ne crée, ne nomme ni ne retire jamais un responsable, ne touche jamais une affectation de responsable ni ce qui touche tout le compte d'un responsable, et ne touche jamais un administrateur, l'expert-comptable ni le propriétaire ; il gère dans son magasin l'affectation de vendeur ou d'observateur de toute personne, même responsable d'un autre magasin. Seuls le propriétaire et un responsable du magasin créent les vendeurs et les observateurs de ce magasin, leur cochent des autorisations valables pour ce magasin, jamais au-delà des siennes, les modifient et les retirent de ce magasin : pour eux, le rôle n'est qu'un modèle qui précoche des autorisations. Un responsable peut aussi ajouter à son magasin, par son adresse exacte, un compte actif de la société qui n'est ni propriétaire, ni administrateur, ni expert-comptable. Une même personne peut travailler dans plusieurs magasins de la société, avec des autorisations propres à chacun ; ce qui touche tout le compte d'un vendeur ou d'un observateur (le suspendre, le réactiver, le retirer de la société, renouveler son accès) revient au propriétaire, ou à une personne qui est responsable de tous les magasins de ce compte ; le responsable d'un seul de ses magasins ne gère que son affectation dans ce magasin. Ce qui touche tout le compte d'une personne responsable d'un magasin et vendeur ou observateur d'un autre revient au propriétaire seul. Retirer quelqu'un de son dernier magasin le retire de la société. Un compte suspendu ou retiré par le propriétaire n'est réactivé ou réintégré que par lui ; réintégrer un compte retiré par la société, ou proposer un rattachement, suit les règles de la création du rôle proposé ; un compte retiré par son propre refus ne revient que par un rattachement qu'il accepte. Personne ne modifie ses propres droits ni ceux du propriétaire. Un compte créé par la société reçoit un mot de passe provisoire remis en main propre, valable 72 heures, que la personne change à sa première connexion ; ensuite, un accès perdu se renouvelle par un lien envoyé à la personne, et personne d'autre ne connaît son mot de passe. Un nouveau mot de passe provisoire n'est donné qu'à un compte dont le mot de passe est encore un provisoire, et seulement par le propriétaire ou, pour un vendeur ou un observateur, par une personne responsable de tous ses magasins ; tant que son mot de passe est un provisoire, un compte ne reçoit un nouveau magasin, un rôle de responsable ou une réintégration que de la personne qui l'a créé. Un compte créé par la société, membre, administrateur ou expert-comptable, peut être refusé par la personne tant que son adresse e-mail n'a jamais été confirmée, c'est-à-dire tant que personne n'a suivi un lien envoyé à cette adresse (inscription, « Mot de passe oublié », rattachement, confirmation d'une nouvelle adresse). Ce refus est possible à la connexion avec le mot de passe provisoire, ou sur la page du lien « Mot de passe oublié », même si le mot de passe provisoire a expiré, si le compte est suspendu ou s'il n'a plus de magasin ; une connexion interrompue ne vaut ni acceptation ni refus. Refusé, le compte est retiré, son mot de passe ne sert plus et toutes ses sessions sont fermées, la société ne peut plus le réintégrer sans l'accord de la personne, son auteur (à défaut, le propriétaire) en est prévenu, et la personne peut créer sa propre société avec ce compte, ou accepter un rattachement, après avoir choisi son mot de passe sur la page d'un lien envoyé à son adresse. Le compte du propriétaire ne se refuse jamais. Changer un mot de passe provisoire ne confirme pas l'adresse. L'historique garde le nom de chaque personne tel qu'il était au moment de chaque opération. Chaque vente enregistre le magasin où elle est faite, avec son SIRET, et la personne qui l'a faite, affectée et autorisée dans ce magasin à ce moment. Une personne n'encaisse que dans un magasin à la fois : elle ferme sa caisse d'un magasin avant d'encaisser dans un autre. Un e-mail demandé par une personne ne part que si, au moment de l'envoi, elle a encore accès au magasin et le droit d'envoyer ; sinon il ne part pas et le propriétaire en est prévenu. Connecter la plateforme agréée, prouver la qualité de représentant de la société, céder un magasin, consulter le journal d'audit et transmettre la propriété à un administrateur dont la qualité de représentant est prouvée restent réservés au propriétaire ; signer les attestations aussi, une fois sa propre qualité prouvée.

**D'où vient chaque phrase.**

| Phrase | Source |
|---|---|
| Un compte, une société ; adresse jamais réutilisée, en minuscules ; profil ; lien d'inscription de 24 heures | [CL], mot pour mot |
| Le propriétaire, sans autorisation demandée, soumis aux règles de tous | [É-P], avec la limite [I-2] |
| Les administrateurs créés et gérés par le propriétaire, leur accès et leurs cases | [É-P], [CL] |
| Un administrateur ne crée ni ne gère les vendeurs et les observateurs, sauf pour en nommer un responsable | [É-R], [É-A], [I-4] ; reprend la matrice C (correction [C4]) |
| Nommer, retirer et changer les magasins des responsables ; propriétaire prévenu ; ni suspension, ni réactivation, ni rétrogradation ; retrait de la société et lien pour une personne qui n'est que responsable de sa portée ; jamais le propriétaire, un administrateur ni l'expert-comptable | [É-A], [É-nommer], [I-4], [I-6], [CL], [É-EC-créé] |
| L'expert-comptable : un par société, géré par le propriétaire seul, consultations de tous les magasins réels, aucune écriture, export comptable | [É-EC-créé], [É-EC-rôle], [É-EC-accès], [É-EC-export], [I-21], [I-22], [I-23] |
| Plusieurs responsables par magasin, une personne responsable de plusieurs magasins, nommés par le propriétaire ou un administrateur autorisé | [É-nombre], [É-P], [É-nommer] |
| Tous les droits du magasin, sans case ; jamais ceux de la société | [É-R], [É-droits], [I-3] |
| Ce que le responsable ne touche jamais ; l'affectation de vendeur ou d'observateur de toute personne | [I-5] (correction [C4]) |
| Vendeurs et observateurs créés, cochés, modifiés et retirés par le propriétaire et un responsable du magasin ; le rôle n'est qu'un modèle | [É-R], [É-obs], [CL] |
| Ajouter à son magasin un compte actif de la société | [I-18] |
| Plusieurs magasins ; gestes sur tout le compte | [É-R], [É-V], [I-6] (correction [C6]) |
| Dernier magasin | [I-7] |
| Réactivation et réintégration ; rattachement | [CO 4.8 n° 5], [I-19], [É-refus] |
| Personne ne modifie ses propres droits ni ceux du propriétaire | [CL] |
| Provisoire remis en main propre, valable 72 heures, puis lien | [CL], mot pour mot |
| Qui redonne un provisoire ; droits d'un compte encore au provisoire | [CL] (« personne d'autre ne connaît son mot de passe »), [E2], [I-4], [I-18] ; règles G11 et G14 (correction [C12]) |
| Le refus | formulation de `reponse-editeur.md` section 9 [É-refus], étendue à l'expert-comptable [I-24], au mot de passe et aux sessions [I-28] (correction [C11]) |
| Vente tracée ; une caisse à la fois | [CL], mot pour mot |
| E-mail revérifié au moment de l'envoi | [É-e-mail] |
| Gestes réservés au propriétaire | [CL], mot pour mot ; [I-27] |

Disparaissent de l'ancienne puce : « Le propriétaire, ou un administrateur autorisé à gérer les membres de ses magasins, crée directement le compte des responsables, vendeurs, comptables et observateurs », « Il les affecte à un ou plusieurs magasins et coche leurs autorisations magasin par magasin, jamais au-delà des siennes : le rôle n'est qu'un modèle » (remplacées par les phrases des administrateurs, des responsables, des vendeurs et observateurs) et « Un compte créé pour une personne qui ne s'en est jamais servie… » (remplacée par la formulation du refus).

### 1.2 Puce 2 de « Produit » (lectures hors du magasin) : texte intégral

> - Chaque société est strictement isolée des autres, et dans une société chaque magasin est strictement isolé (`BelongsToMagasin` + `MagasinScope`) : aucun client, produit, fournisseur, service, document, stock, caisse ni réglage local n'est jamais partagé ni mélangé entre magasins, ni copié d'une société à une autre. Les données propres à une société (journal d'audit, vérifications, et à l'étape 2 sa connexion et ses factures reçues SUPER PDP) utilisent `BelongsToSociete` + `SocieteScope`. L'identité légale et les réglages fiscaux communs n'existent qu'une fois, sur la société. Seules lectures hors du magasin ou de la société courants, explicites, filtrées, limitées aux champs nécessaires et testées : la liste et le tableau de bord des magasins et des membres de l'espace d'administration, limités à la portée de la personne ; les autres affectations d'un membre de l'équipe d'un magasin, lues seulement pour savoir qui peut gérer tout son compte, sans jamais dire lesquelles ; la recherche, par son adresse exacte, d'un compte actif de la société qui n'est ni propriétaire, ni administrateur, ni expert-comptable, pour l'ajouter à un magasin, sans rien révéler d'autre qu'un succès ou une réponse neutre ; les caisses encore ouvertes d'une personne dans les autres magasins de sa société (une caisse à la fois) ; l'existence d'une première vente de la société ; les destinataires des notifications ; les SIRET, adresses de réception SUPER PDP, codes de routage, adresses et références d'acheteur de ses magasins (pour attribuer les factures reçues) ; à l'étape 5, les écritures et les documents de tous ses magasins réels, pour l'export comptable de la société produit par le propriétaire ou l'expert-comptable ; l'examen exceptionnel d'un SIREN par l'éditeur ; et, pour un même SIREN, l'existence d'une autre société validée ou ayant prouvé sa qualité de représentant (sans jamais son identité) et les codes de magasins d'une société révoquée (pour ne jamais redonner un numéro déjà émis sous ce SIREN).

**Ce qui s'ajoute.**
- Les affectations d'un membre dans d'autres magasins, lues sans être nommées : l'écran de l'équipe ne dit que « a aussi accès à d'autres magasins » (règle G7 ; [I-9]).
- La recherche d'un compte par son adresse exacte pour l'ajouter à un magasin [I-18, C5]. Elle ne lit que l'identifiant, la société, le statut, le rôle de société, `mot_de_passe_a_changer` et `cree_par`, et répond exactement pareil pour toute adresse qui ne convient pas (4.5, `compteAAjouter`).
- Les caisses ouvertes de la personne dans ses autres magasins. Cette lecture était exigée par la réponse Q2, et `recommandation-finale.md` 5.1 point 5 demandait de la déclarer.
- À l'étape 5, la lecture de tous les magasins réels pour l'export comptable de la société [É-EC-export, C3]. Elle est déclarée dès maintenant, comme le sont déjà les lectures de l'étape 2.

**Ce qui n'en est pas une.** L'expert-comptable ouvre chaque magasin dans son propre panneau, sous `MagasinScope`, comme le propriétaire ou un administrateur de tous les magasins : il lit chaque magasin depuis ce magasin, pas depuis un autre. Son sélecteur ne liste que les magasins qu'il peut ouvrir.

### 1.3 Puce « Prix et TVA des ventes » : phrase de R2

**Remplacer** :
> Accorder une remise et fixer le prix d'un service (son prix de référence au catalogue comme celui d'une ligne) et le prix d'une ligne de produit différent de sa fiche exigent l'autorisation « accorder des remises et fixer le prix d'un service », cochée par défaut pour le propriétaire, les administrateurs et les responsables, pas pour les vendeurs ; changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ; sans elle, une ligne de produit prend le PU TTC de sa fiche, une ligne de service le prix de référence de sa fiche, et un service sans prix de référence ne se vend pas.

**par** :
> Accorder une remise et fixer le prix d'un service (son prix de référence au catalogue comme celui d'une ligne) et le prix d'une ligne de produit différent de sa fiche exigent l'autorisation « accorder des remises et fixer le prix d'un service », que le propriétaire et les responsables du magasin ont toujours, précochée pour les administrateurs, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et que l'expert-comptable n'a jamais ; changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ; sans elle, une ligne de produit prend le PU TTC de sa fiche, une ligne de service le prix de référence de sa fiche, et un service sans prix de référence ne se vend pas.

Sources : [É-droits] (« remises et prix »), [É-R] (« leur attribue des autorisations »), [É-EC-accès], réponse R2.

### 1.4 Puce « Achats et stock » : phrase de R1

**Remplacer** :
> Une vente au-delà du stock disponible est refusée, sauf pour un membre autorisé à « vendre sans stock » : il voit un avertissement avant de valider, qui se renouvelle si le stock a encore baissé, une alerte de stock part, et la quantité négative reste visible jusqu'à sa régularisation par un achat, une réception ou un inventaire ; ces ventes sont journalisées, et des ventes simultanées respectent ces règles sans jamais rendre le stock incohérent.

**par** :
> Une vente au-delà du stock disponible est refusée, sauf pour une personne autorisée à « vendre sans stock » dans ce magasin (le propriétaire et les responsables du magasin le sont toujours ; pour les autres, cette autorisation n'est jamais précochée, et l'expert-comptable ne l'a jamais) : elle voit un avertissement avant de valider, qui se renouvelle si le stock a encore baissé, une alerte de stock part, et la quantité négative reste visible jusqu'à sa régularisation par un achat, une réception ou un inventaire ; ces ventes sont journalisées, et des ventes simultanées respectent ces règles sans jamais rendre le stock incohérent.

Sources : [É-P], [É-R] (« il a tous les droits »), [É-EC-accès], [I-3].

### 1.5 Puce « E-mails » : texte intégral

**Remplacer** la puce par :
> - E-mails : au plus 60 e-mails par heure et par membre et 200 par heure et par magasin, une relance par jour et par facture ; les relances partent dans l'ordre, chacune pas avant son délai, en respectant l'écart entre deux relances ; les alertes de stock bas ou négatif vont au propriétaire de la société, aux responsables du magasin et à toute autre personne qui a accès au magasin et y est autorisée à gérer le stock (administrateurs, vendeurs et observateurs).

Source : [É-droits] (le responsable a le stock sans case ; sans ce texte, il ne recevrait plus l'alerte) ; [É-EC-rôle] (plus de comptable de magasin ; l'expert-comptable n'a pas la gestion du stock).

### 1.6 Feuille de route, étapes 1 et 5

**Étape 1, remplacer la ligne par** :
> 1. [ ] Corrections : ventes toujours au prix TTC et achats toujours au prix HT, retours partiels cumulés, défauts de l'audit complet, décisions de l'éditeur qui ne relèvent ni de SUPER PDP ni de la fin de la caisse sécurisée, dont l'architecture société → établissements → membres (inscription par lien, vérification de la société et magasin de démonstration, espace d'administration de la société, réglages communs de la société, administrateurs créés par le propriétaire, responsables nommés par lui ou par un administrateur autorisé, vendeurs et observateurs créés par le propriétaire ou le responsable de leur magasin, chacun affecté à un ou plusieurs magasins, expert-comptable de la société créé par le propriétaire, autorisations propres à PILIQI avec leur référentiel en base, journal d'audit de société, fermeture et réouverture des magasins, procédures de l'éditeur) et les règles R1 à R5 (vente sans stock, remises et prix, demandes répétées, répartition de la TVA, caisses personnelles fermées d'office).

**Étape 5, remplacer la ligne par** [É-EC-export, C3] :
> 5. [ ] Comptabilité : journaux (ventes, achats, caisse et banque), écritures générées depuis chaque document, plan de comptes de la société, journaux par magasin, export comptable de la société (FEC), produit et téléchargé par l'expert-comptable ou le propriétaire, chaque export inscrit au journal d'audit, état de TVA (collectée, déductible, à payer). Puis relance de la campagne de tests.

### 1.7 Puces relues, qui ne changent pas

| Puce | Pourquoi elle reste juste |
|---|---|
| Société ou magasin suspendu, fermé, résilié ou révoqué | fermer et rouvrir : « propriétaire ou administrateur autorisé » ; le responsable en est exclu [É-droits]. « Son équipe gérable » couvre aussi l'équipe gérée par un responsable, et l'expert-comptable géré par le propriétaire [I-11] |
| Société et établissements | création des magasins par le propriétaire ou un administrateur autorisé ayant accès à tous les magasins ; « chaque magasin garde … son équipe ». « L'expert-comptable de la société » y désigne la personne qui conseille la société, qu'elle ait ou non un compte |
| Vérification de la société | « la société se configure (identité, magasins, équipe, …) » |
| Vendeurs | inchangé |
| Caisse et clôtures | « un responsable peut aussi la fermer pour le vendeur » : toujours vrai, sans case. « Une durée validée par l'expert-comptable de la société » : la personne, qu'elle ait ou non un compte |
| Facture électronique (SUPER PDP) | « le propriétaire et les administrateurs autorisés » : ni le responsable [I-12], ni l'expert-comptable, qui lit les factures reçues attribuées sans les refuser ni les contester [I-22]. « L'expert-comptable de la société » : la personne |
| Journal d'audit | « consultable par le propriétaire seul » : ni un administrateur, ni l'expert-comptable [I-22]. L'export comptable que produit l'expert-comptable y est inscrit sans qu'il le lise |
| Protocole, règle 2 | inchangé |
| Feuille de route, étape 1 bis | « par société 1 propriétaire et au moins 1 administrateur, par magasin au moins 1 responsable et 2 vendeurs » reste vrai ; la composition est précisée en 7.4, avec un expert-comptable par société |

---

## 2. Matrice des droits de gestion

### 2.1 Notions

- **Affectation** : la ligne « tel compte dans tel magasin », ou dans tous les magasins pour un administrateur. Elle porte un **rôle de magasin** :
  - `responsable` : aucune case, tous les droits du magasin ;
  - `vendeur`, `observateur` : des cases ; le rôle n'est qu'un modèle ;
  - aucun rôle (`null`) : affectation d'un administrateur, qui délimite sa portée et porte les cases que le propriétaire lui a cochées.
- **Rôles de société** : propriétaire, administrateur, expert-comptable, membre. Un membre a des affectations avec rôle ; un administrateur, des affectations sans rôle ; le propriétaire et l'expert-comptable n'en ont aucune.
- **Expert-comptable** : au plus un par société. Il lit tous les magasins réels de la société, présents et futurs, par des autorisations de consultation implicites (`PermissionRegistry::consultations()`), et n'écrit rien.
- **Compte responsable** : un compte qui a au moins une affectation de responsable.
- **Compte mixte** : responsable d'un magasin, et vendeur ou observateur d'un autre.
- **Acteurs** :
  - **P** : le propriétaire ;
  - **A+** : un administrateur actif qui a `societe.responsables.nommer`, agissant dans sa portée ;
  - **A−** : un administrateur sans cette autorisation ;
  - **R(M)** : un responsable du magasin M ;
  - **R(N)** : un responsable d'autres magasins que M seulement ;
  - **V** : un vendeur ou un observateur, quelles que soient ses cases. Gérer l'équipe n'est pas une case [É-R : « seulement lui ou le propriétaire »] ;
  - **E** : l'expert-comptable.

### 2.2 Règles générales

| N° | Règle | Source |
|---|---|---|
| G1 | Aucun geste sur un compte d'une autre société. La base refuse une affectation croisée (clés composites) | [CL], [CO 2.4] |
| G2 | Personne ne modifie ses propres droits, son rôle, ses affectations ni son statut. Chacun change son profil, refuse son propre compte s'il est refusable, ferme sa caisse | [CL], [É-refus] |
| G3 | Personne ne touche au propriétaire. Il ne se suspend pas, ne se retire pas, ne refuse pas son compte ; il ne cesse de l'être que par un transfert de propriété | [CL], [É-refus] |
| G4 | Le propriétaire seul crée, nomme, modifie, rétrograde, suspend, réactive, retire et réintègre un administrateur, lui renouvelle son accès et lui transmet la propriété | [É-P], [CL] |
| G5 | Le propriétaire seul crée, suspend, réactive, retire et réintègre l'expert-comptable, lui renouvelle son accès et lui propose un rattachement ; jamais un administrateur, même autorisé, jamais un responsable. Au plus un expert-comptable par société. Il n'a ni affectation, ni case, ni autorisation de société, et ne fait aucun geste de gestion sur aucun compte | [É-EC-créé], [É-EC-rôle], [É-EC-accès], [I-21], [I-26] |
| G6 | Rôle de responsable dans M : donné ou retiré par P, ou par A+ si M est dans sa portée ; un responsable n'est rétrogradé que par P. Rôles de vendeur et d'observateur dans M : donnés, modifiés ou retirés par P ou par R(M), jamais sur une affectation de responsable. Un administrateur et l'expert-comptable n'ont jamais de rôle de magasin | [É-P], [É-A], [É-nommer], [É-R], [É-obs], [I-4], [I-5] |
| G7 | **Gestes qui touchent tout le compte** (suspendre, réactiver, retirer de la société, renouveler l'accès) : P, toujours. Compte dont toutes les affectations sont de vendeur ou d'observateur : une même personne responsable de **chacun** de ses magasins. Compte dont toutes les affectations sont de responsable, dans des magasins de la portée d'un A+ : cet A+, pour le retirer de la société ou lui renvoyer un lien seulement. Tout autre compte (mixte, responsable hors de la portée, administrateur, expert-comptable) : P seul | [É-R], [É-V], [É-nommer], [I-4], [I-6] |
| G8 | Retirer quelqu'un de son dernier magasin le retire de la société. C'est permis seulement à qui peut retirer ce compte de la société (G7) | [I-7] |
| G9 | Une case ne vaut que dans son magasin et ne se coche jamais au-delà des droits de qui coche. P et R(M) ont toutes les autorisations du magasin ; les cases d'un administrateur sont cochées par P seul ; l'expert-comptable n'a aucune case | [CL], [É-R], [É-EC-accès] |
| G10 | Un compte suspendu par le propriétaire ne se réactive que par lui. Un compte retiré par le propriétaire ne se réintègre que par lui. Un compte retiré par son propre refus ne revient que par un rattachement qu'il accepte | [CO 4.8 n° 5], [É-refus], [I-19] |
| G11 | Renouveler l'accès : un nouveau mot de passe provisoire seulement si le mot de passe actuel est encore un provisoire de la société (`mot_de_passe_a_changer` vrai), et seulement par P ou, pour un compte qui n'a que des affectations de vendeur ou d'observateur, par une même personne responsable de chacun de ses magasins ; sinon, et toujours pour un A+, un lien envoyé à la personne. Celui qui renouvelle par un lien ne connaît jamais le mot de passe | [CL], [E2], [I-4] |
| G12 | Dans un magasin fermé ou suspendu, ou dans une société suspendue ou résiliée, l'équipe reste gérable. Dans une société révoquée, on ne fait plus que consulter | [CL], [I-11] |
| G13 | Seule la personne refuse son propre compte, jamais quelqu'un à sa place | [É-refus] |
| G14 | Tant que le mot de passe d'un compte est un provisoire, seule la personne qui l'a créé (`cree_par`) lui ajoute un magasin, le nomme responsable ou le réintègre ; toute autre personne attend qu'il ait choisi son mot de passe. Ainsi, celui qui connaît le provisoire ne profite jamais, par ce compte, de droits qu'il ne pourrait pas donner lui-même | [CL] (« personne d'autre ne connaît son mot de passe »), [I-4], [I-18] |

### 2.3 Matrice par cible

« Oui » ou « Non », suivi de la règle ou de la réponse qui le justifie. « — » : sans objet. Le test de référence de la matrice (6.2, `PorteeDesDroitsTest`) a une donnée par case.

**A. Cible : un administrateur**

| Geste | P | A+ | A− | R(M) | V, E |
|---|---|---|---|---|---|
| Créer (accès, cases de société et de magasin, provisoire) | Oui [G4] | Non [G4] | Non [G4] | Non [G4] | Non [G4] |
| Nommer administrateur un membre existant (jamais l'expert-comptable) | Oui [G4] | Non [G4] | Non [G4] | Non [G4] | Non [G4, I-26] |
| Modifier sa portée ou ses cases | Oui [G4] | Non [G4, G2] | Non [G4, G2] | Non [G4] | Non [G4] |
| Le rétrograder en membre, avec ses nouvelles affectations | Oui [G4] | Non [G4] | Non [G4] | Non [G4] | Non [G4] |
| Suspendre, réactiver | Oui [G4] | Non [G4] | Non [G4] | Non [G4] | Non [G4] |
| Retirer de la société (il repasse membre) | Oui [G4] | Non [G4] | Non [G4] | Non [G4] | Non [G4] |
| Renvoyer un lien d'accès ; redonner un provisoire (le sien l'est encore) | Oui [G4, G11] | Non [G4] | Non [G4] | Non [G4] | Non [G4] |
| Lui transmettre la propriété (qualité prouvée) | Oui [CL] | Non [CL] | Non [CL] | Non [CL] | Non [CL] |

**B. Cible : un responsable du magasin M, qui n'est que responsable**

| Geste | P | A+, M dans sa portée | A+, M hors portée | A− | R(M) co-responsable | R(N) | V, E |
|---|---|---|---|---|---|---|---|
| Créer son compte et le nommer responsable de M | Oui [É-P] | Oui ; P est prévenu [É-A, É-nommer, I-4] | Non [É-nommer] | Non [É-A] | Non [G6] | Non [G6] | Non [G6] |
| Nommer responsable de M un membre existant | Oui [É-P, G14] | Oui, un membre déjà affecté à un magasin de sa portée, qui a choisi son mot de passe ou qu'il a créé ; P est prévenu [É-nommer, I-4, G14] | Non [É-nommer] | Non [É-A] | Non [G6] | Non [G6] | Non [G6] |
| Lui ajouter le magasin M2 comme responsable | Oui [É-P, G14] | Oui si M2 est dans sa portée [É-nommer, G14] | Non [É-nommer] | Non [É-A] | Non [G6] | Non [G6] | Non [G6] |
| Lui retirer M | Oui [É-P] | Oui [É-nommer] ; dernier magasin : [G8] | Non [É-nommer] | Non [É-A] | Non [G6] | Non [G6] | Non [G6] |
| Le rétrograder en vendeur ou observateur dans M | Oui [É-P, G6] | Non [I-4] | Non [I-4] | Non [É-A] | Non [G6] | Non [G6] | Non [G6] |
| Cocher ses cases | — [É-droits] | — [É-droits] | — [É-droits] | — [É-droits] | — [É-droits] | — [É-droits] | — [É-droits] |
| Suspendre, réactiver | Oui [G7] | Non [I-4] | Non [I-4] | Non [G7] | Non [G7] | Non [G7] | Non [G7] |
| Retirer de la société | Oui [G7] | Oui si toutes ses affectations sont des affectations de responsable dans sa portée [G7, I-4] | Non [G7] | Non [G7] | Non [G7] | Non [G7] | Non [G7] |
| Renvoyer un lien d'accès | Oui [G7, G11] | Oui, même condition [G7, G11, I-4] | Non [G7] | Non [G7] | Non [G7] | Non [G7] | Non [G7] |
| Redonner un provisoire (le sien l'est encore) | Oui [G11] | Non [G11, I-4] | Non [G11] | Non [G11] | Non [G11] | Non [G11] | Non [G11] |

**C. Cible : un vendeur ou un observateur du seul magasin M**

| Geste | P | A+ ou A− | R(M) | R(N) | V, E |
|---|---|---|---|---|---|
| Créer (identité, provisoire, rôle et cases dans M) | Oui [É-R] | Non [É-R, É-A] | Oui [É-R, É-obs] | Non [É-R : « ce magasin »] | Non [É-R] |
| Changer son modèle et ses cases dans M | Oui [É-R, G9] | Non [É-R] | Oui [É-R, G9] | Non [É-V] | Non [É-R] |
| L'ajouter au magasin N, avec ses cases dans N (il relève alors de la matrice D) | Oui [É-R, G14] | Non [É-R] | Non, sauf s'il est aussi responsable de N [É-V] | Oui s'il a choisi son mot de passe ou si R(N) l'a créé [I-18, G14] | Non [É-R] |
| Le nommer responsable de M | Oui [É-P, G14] | A+ si M est dans sa portée [É-nommer, G14] ; A− non [É-A] | Non [G6] | Non [G6] | Non [G6] |
| Le retirer de M (dernier magasin : il est retiré de la société) | Oui [É-R, G8] | Non [É-R] | Oui [É-R, G8] | Non [É-V] | Non [É-R] |
| Suspendre, réactiver | Oui [G7] | Non [G7] | Oui, sauf une suspension décidée par le propriétaire [G7, G10] | Non [G7] | Non [G7] |
| Retirer de la société | Oui [G7] | Non [G7] | Oui [G7] | Non [G7] | Non [G7] |
| Renvoyer un lien d'accès | Oui [G7, G11] | Non [G7] | Oui [G7, G11] | Non [G7] | Non [G7] |
| Redonner un provisoire (le sien l'est encore) | Oui [G11] | Non [G11] | Oui [G11] | Non [G11] | Non [G11] |

**D. Cible : un vendeur ou un observateur des magasins M et N**

| Geste | P | R(M) seulement | Une même personne R(M) et R(N) | A+ ou A− | V, E |
|---|---|---|---|---|---|
| Ses cases et son modèle dans M | Oui [É-R, G9] | Oui [É-R, G9] | Oui [É-R, G9] | Non [É-R] | Non [É-R] |
| Ses cases et son modèle dans N | Oui [É-R, G9] | Non [É-R, É-V] | Oui [É-R, G9] | Non [É-R] | Non [É-R] |
| Le retirer de M (il garde N) | Oui [É-R] | Oui [É-R] | Oui [É-R] | Non [É-R] | Non [É-R] |
| Suspendre, réactiver, retirer de la société, renvoyer un lien, redonner un provisoire | Oui [G7, G11] | Non [G7, I-6] | Oui [G7, G11] | Non [G7] | Non [G7] |
| Le nommer responsable de M (il devient un compte mixte : matrice E) | Oui [É-P, G14] | Non [G6] | Non [G6] | A+ si M est dans sa portée [É-nommer, G14, I-4] ; A− non [É-A] | Non [G6] |

**E. Cible : un compte mixte, responsable de N et vendeur ou observateur de M**

| Geste | P | R(M) | R(N) co-responsable | A+, N dans sa portée |
|---|---|---|---|---|
| Ses cases et son modèle de vendeur ou d'observateur dans M | Oui [É-R] | Oui [I-5] | Non [É-V] | Non [É-R] |
| Le retirer de M (N lui reste) | Oui [É-R] | Oui [I-5] | Non [É-V] | Non [É-R] |
| Lui retirer N (responsable) | Oui [É-P] | Non [G6] | Non [G6] | Oui [É-nommer] |
| Le rétrograder en vendeur ou observateur dans N | Oui [G6] | Non [G6] | Non [G6] | Non [I-4] |
| Gestes qui touchent tout le compte | Oui [G7] | Non [G7, I-6] | Non [G7, I-6] | Non [G7, I-6] |

**F. Cible : le propriétaire.** Personne, pour aucun geste [G3]. Lui-même : son profil seulement [CL].

**G. Cible : soi-même, quel que soit son rôle.**
- Droits, affectations, statut : Non [G2].
- Profil : Oui [CL].
- Refuser son compte : Oui s'il est refusable (section 3.5) ; jamais pour le propriétaire [É-refus].
- Fermer sa caisse : Oui [CL].

**H. Cible : un compte retiré de la société**

| Geste | P | A+ | R(M) |
|---|---|---|---|
| Réintégrer un compte retiré par la société, comme vendeur ou observateur | Oui [I-19, G14] | Non [É-R] | Oui, dans ses magasins [É-R, I-19, G14] |
| Réintégrer un compte retiré par la société, comme responsable | Oui [I-19, G14] | Oui, dans sa portée [É-nommer, I-19, G14] | Non [G6] |
| Réintégrer un compte retiré par la société, comme administrateur ou expert-comptable | Oui [G4, G5] | Non [G4, G5] | Non [G4, G5] |
| Réintégrer un compte retiré par le propriétaire | Oui [G10] | Non [G10] | Non [G10] |
| Compte retiré par son propre refus | Seulement par un rattachement qu'il accepte, proposé par qui peut créer ce rôle [G10, É-refus] | idem [G10, É-refus] | idem [G10, É-refus] |
| Proposer un rattachement à un compte retiré par une autre société | Oui, pour tout rôle [I-19] | Oui, comme responsable dans sa portée [É-nommer, I-19] | Oui, comme vendeur ou observateur de ses magasins [É-R, I-19] |
| Accepter le rattachement | la personne seule, en redonnant son mot de passe dans la requête qui accepte ; à l'acceptation, les droits de l'auteur sont relus [CO 3.5, CL, I-28] | idem | idem |

**I. Cible : l'expert-comptable**

| Geste | P | A+ | A− | R(M) | V | E lui-même |
|---|---|---|---|---|---|---|
| Créer son compte (identité, provisoire) ; un seul par société | Oui [É-EC-créé, G5, I-21] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | — |
| Suspendre, réactiver | Oui [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G2] |
| Retirer de la société (il repasse membre) | Oui [É-EC-créé, G5, I-26] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G2] |
| Réintégrer comme expert-comptable un compte retiré par la société | Oui [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | — |
| Renvoyer un lien d'accès ; redonner un provisoire (le sien l'est encore) | Oui [G5, G11] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | — |
| Proposer un rattachement comme expert-comptable | Oui [G5, I-19] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | — |
| Lui donner une affectation, une case ou une autorisation de société | Non : la base le refuse [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G2] |
| Changer son rôle sans le retirer (administrateur, membre) | Non [I-26] | Non [G5] | Non [G5] | Non [G5] | Non [G5] | Non [G2] |
| Lui transmettre la propriété | Non [CL : un administrateur seulement] | Non | Non | Non | Non | — |
| Refuser son compte | — | — | — | — | — | Oui s'il est refusable [É-refus, I-24, G13] |

**J. Ce que chacun voit** [I-9, I-22, É-A, É-R, CL : la liste des membres de l'espace d'administration est limitée à la portée de la personne]

| Écran | P | A+ | A− | R(M) | V | E |
|---|---|---|---|---|---|---|
| Espace société, liste des comptes (`MembreResource`) | Tous les comptes de la société, expert-comptable compris, leurs affectations et leurs cases | Les responsables de ses magasins (nom, adresse, magasins de sa portée). Pour en nommer un : les membres affectés à ses magasins qui ont choisi leur mot de passe ou qu'il a créés, sans leurs cases | Rien | Espace fermé | Espace fermé | Espace fermé |
| Équipe du magasin M, dans le panneau du magasin (`EquipeDuMagasinResource`) | Oui | Non | Non | Oui : les affectations de membre de M, leur rôle et leurs cases dans M, le statut, et la mention « a aussi accès à d'autres magasins », sans les nommer ; ni les administrateurs ni l'expert-comptable n'y figurent | Non | Non |
| Documents, fiches, clôtures, registre et journal des événements de M | Tout | Selon ses cases dans M | Selon ses cases dans M | Tout | Selon ses cases | En lecture, dans chaque magasin réel |
| Journal d'audit, adresses IP et navigateurs | Oui | Non | Non | Non | Non | Non |

---

## 3. Modèle de données, à partir du travail A4 non commité

Rappel de la règle : une seule migration de création par table, modifiée en place, puis `php artisan migrate:fresh`. Aucune colonne ni aucun code de compatibilité.

### 3.1 Ce qui se garde du travail A4

| Élément | Fichier | Gardé tel quel |
|---|---|---|
| Table `affectations` | `database/migrations/2026_10_02_100005_create_affectations_table.php` | `societe_id` ; `user_id` ; `magasin_id` nullable (nul : tous les magasins, présents et futurs, administrateur seulement) ; clés composites `(societe_id, user_id)` vers `users` et `(societe_id, magasin_id)` vers `magasins` ; `unique(user_id, magasin_id)` ; index partiel `affectations_une_globale`. Le reste change (3.2) |
| Table `autorisations_affectations` | `…_100006_…` | `affectation_id` en cascade, `autorisation` (`string(60)`), clé primaire, `index(autorisation)`. S'ajoutent la clé étrangère et une garde (3.2) |
| Table `autorisations_societe` | `…_100007_…` | `societe_id` et `user_id` (clé composite), `autorisation`, clé primaire `(user_id, autorisation)`. S'ajoutent la clé étrangère et une garde (3.2) |
| Table `demandes_rattachement` | `…_100012_…` | tout ; le contenu de `affectations` est précisé et deux colonnes s'ajoutent (3.2) |
| Colonnes de `users` | `0001_01_01_000001_create_users_table.php` | `statut_change_par`, `mot_de_passe_a_changer`, `mot_de_passe_provisoire_expire_le`, `cree_par`, `nouvelle_adresse`, `nouvelle_adresse_jeton_hache`, `nouvelle_adresse_expire_le`. `premiere_connexion_le` disparaît ; un index s'ajoute (3.2) |
| Commentaires des migrations `notifications` et `paniers_caisse` | migrations 2026_10_13_100001 et 2026_10_14_100003 | tels quels |
| `JournalDesDroits` | `app/Domain/Tenancy/Services/JournalDesDroits.php` | verrous des registres par identifiant croissant, comptes verrouillés, JET 130 par magasin avec avant et après relus en base, `inscrireLesDroitsImplicites()`. Change : l'auteur est relu (4.5), l'expert-comptable a des droits implicites (4.5) |
| `ControleDesDroits` | `app/Domain/Authorization/ControleDesDroits.php` | seul `Gate::before`, même structure. Change : section 4.6 |
| `AutorisationsSociete` | `app/Domain/Authorization/AutorisationsSociete.php` | même classe. Change : une clé (3.7) |
| `SocietePolicy`, `MagasinDeLaSocietePolicy` | `app/Policies/` | telles quelles, avec une méthode de plus (4.6) |
| `TenantContext` | `app/Domain/Tenancy/Services/TenantContext.php` | l'invalidation propre à Spatie est retirée, et le reste ne change pas |
| `CreerEtablissementAction`, `ModifierEtablissementAction`, `OuvrirLaDemonstrationAction`, `MiseEnActiviteDesMagasins` | `app/Domain/Tenancy/` | modifications A4 gardées ; l'auteur est relu (4.4) |
| `ClotureCaissePersonnellePolicy` | `app/Policies/ClotureCaissePersonnellePolicy.php` | version A4 gardée (`autorisationsDans()` du magasin actif ; « non » sans magasin) |
| Retrait de Spatie | `composer.json`, `composer.lock`, fichiers supprimés | `config/permission.php`, migration Spatie, `MagasinTeamResolver`, `SeedMagasinRolesAction`, `MagasinUser`, `StatutMembreMagasin` restent supprimés |

`RoleSociete` et `RoleMagasin` changent (3.7).

### 3.2 Tables et colonnes, migration par migration

| Migration | Changement |
|---|---|
| `2026_10_02_095000_create_referentiel_autorisations_magasin_table.php` (**nouvelle**) | Colonne `cle`, `string(60)`, clé primaire. `up()` insère `PermissionRegistry::toutes()`, une ligne par autorisation. L'application ne l'écrit jamais ; sa migration seule la remplit (3.3) |
| `2026_10_02_095001_create_referentiel_autorisations_societe_table.php` (**nouvelle**) | Colonne `cle`, `string(60)`, clé primaire. `up()` insère `AutorisationsSociete::toutes()` (3.3) |
| `0001_01_01_000001_create_users_table.php` | **Retirer** `premiere_connexion_le` et son commentaire. **Réécrire** le commentaire d'`email_verified_at` (définition de 3.5). **Réécrire** celui de `role_societe` : « `proprietaire`, `administrateur`, `expert_comptable` ou `membre` (voir RoleSociete) ». **Réécrire** celui de `cree_par` : « le compte qui a créé ce compte pour la personne (propriétaire, responsable ou administrateur autorisé), prévenu si elle le refuse, et seul à lui donner de nouveaux droits tant que son mot de passe est un provisoire ; nul pour le propriétaire inscrit par son lien ; il ne change plus ensuite ». **Ajouter** l'index unique partiel `users_un_expert_comptable_par_societe ON users (societe_id) WHERE role_societe = 'expert_comptable'` [I-21]. **Réécrire** le docblock de tête : les quatre rôles, le refus qui dépend de l'adresse confirmée et plus de la première connexion. Les gardes de `users` sont posées par la migration 100007 (3.4) |
| `2026_10_02_100005_create_affectations_table.php` | **Renommer** `modele` en **`role`**, `string(20)`, **nullable** : une valeur de `RoleMagasin` pour l'affectation d'un membre (`responsable`, `vendeur`, `observateur`) ; nul pour l'affectation d'un administrateur. **Ajouter** `index(['magasin_id', 'role'])`, pour lire les responsables d'un magasin. **Poser** les gardes de 3.4 (`GardesDesDroits::affectations()`). **Réécrire** le docblock (rôle et cases, responsable sans case, ni le propriétaire ni l'expert-comptable n'ont d'affectation) |
| `2026_10_02_100006_create_autorisations_affectations_table.php` | **Ajouter** une clé étrangère de `autorisation` vers `referentiel_autorisations_magasin.cle`, **`restrictOnDelete`** [C18]. **Poser** la garde « jamais de case sur une affectation de responsable » (3.4) |
| `2026_10_02_100007_create_autorisations_societe_table.php` | **Ajouter** une clé étrangère de `autorisation` vers `referentiel_autorisations_societe.cle`, **`restrictOnDelete`** [C18]. **Poser** la garde « administrateur seulement » et celle des rôles sur `users` (3.4). Elles sont créées ici, après les deux tables qu'elles lisent, et retirées par `down()` |
| `2026_10_02_100012_create_demandes_rattachement_table.php` | **Ajouter** `role_societe`, `string(20)` : `membre` ou `expert_comptable`, le rôle proposé [I-19, I-26]. **Préciser** le commentaire de `affectations` : `[{magasin_id, role, autorisations}]`, `autorisations` vide pour `responsable`, liste vide pour un expert-comptable. **Ajouter** `demande_par_nom` (3.6) |
| Tables d'historique | **Ajouter** les colonnes de nom figé de 3.6, chacune dans la migration de création de sa table |

### 3.3 Référentiel des autorisations

**Pourquoi.** Dans le travail A4, la colonne `autorisations_affectations.autorisation` n'a aucune clé étrangère. `Affectation::cocher()` ne contrôle rien et n'a aucun appelant (`Affectation.php:88-95`) [lu]. Une chaîne inconnue enregistrée aujourd'hui deviendrait un vrai droit le jour où le registre l'ajouterait [RF 3.1]. Le référentiel en base rend cela impossible [É-Spatie].

**Deux tables, une par portée** : `referentiel_autorisations_magasin` et `referentiel_autorisations_societe`, chacune avec une seule colonne `cle`. Une autorisation de société ne peut donc jamais être cochée sur une affectation, ni l'inverse.

**Source unique.** `PermissionRegistry` et `AutorisationsSociete` restent la source des constantes et des libellés. La migration lit ces registres. Ajouter ou retirer une constante (par exemple les deux autorisations du lot A6) se répercute au prochain `migrate:fresh`. Si la base n'a pas été recréée, la clé étrangère refuse la case : le système échoue fermé.

**Écritures.**
- `Affectation::cocher()` appelle `PermissionRegistry::verifierConnues()` avant d'effacer quoi que ce soit, et refuse une affectation de responsable.
- L'écriture des autorisations de société appelle `AutorisationsSociete::verifierConnues()`, puis la règle `exigentTousLesMagasins()`.
- La clé étrangère est la dernière garantie.

**Suppression** [C18]. Une clé du référentiel que porte au moins une case ne se supprime pas (`restrictOnDelete`) : aucun droit ne disparaît sans `JournalDesDroits`, donc sans JET 130 ni audit. Une autorisation retirée puis remise ne rouvre donc jamais d'anciennes cases. En développement, `migrate:fresh` reconstruit le référentiel depuis le registre. En production, retirer une autorisation passera par sa propre migration : `JournalDesDroits` efface d'abord ses cases, avec le JET 130 de chaque magasin touché et l'entrée d'audit, puis la clé est supprimée. Cette procédure s'écrira avec la première autorisation retirée en production (règle « Développement » de `CLAUDE.md`) ; rien n'est à préparer au lot A4.

### 3.4 Gardes en base

**But** : la base elle-même refuse les états de droits incohérents, même écrits par `DB::table` ou par une action défectueuse. Ces gardes ferment en base la trace « tous les magasins » d'un administrateur rétrogradé [RF 5.3, objection 10] ou ramené à une sélection [C17], et toute case de l'expert-comptable [C2]. Elles s'ajoutent à la lecture qui échoue fermée selon le rôle (4.6) et aux suppressions faites par les actions : trois défenses.

**Mise en œuvre** : une classe `App\Domain\Tenancy\Support\GardesDesDroits`, sur le modèle de `DeclencheursDeProtection`.
- **PostgreSQL** : une fonction `plpgsql` et un déclencheur `BEFORE INSERT OR UPDATE` par garde. Chaque fonction verrouille d'abord la ligne parente qu'elle lit :
  - `affectations_coherentes_avec_le_role` : `PERFORM 1 FROM users WHERE id = NEW.user_id FOR NO KEY UPDATE`. Ce verrou fait se suivre deux écritures d'affectations du même compte (une affectation à tous les magasins et une affectation par magasin, écrites en même temps, prendraient toutes deux un verrou `FOR SHARE` sans se gêner et passeraient ensemble) ; la seconde, après l'attente, voit la première. Il entre aussi en conflit avec un changement de rôle du compte. Il attend la fin d'une vente de cette personne, qui tient le même verrou (« une caisse à la fois ») ; aucun interblocage, puisque `JournalDesDroits` prend les registres puis tient déjà la ligne du compte (`FOR UPDATE`) dans la même transaction ;
  - `autorisations_affectations_sans_responsable` : `PERFORM 1 FROM affectations WHERE id = NEW.affectation_id FOR SHARE`, en conflit avec la promotion d'une affectation en responsable ;
  - `autorisations_societe_administrateur_seul` : `PERFORM 1 FROM users WHERE id = NEW.user_id FOR SHARE`, en conflit avec un changement de rôle.
  Ce n'est pas le cas du `FOR KEY SHARE` d'une clé étrangère : sans ces verrous, une garde et un changement simultanés pourraient passer tous les deux. Avec eux, ils se suivent toujours.
- **SQLite** : des déclencheurs `BEFORE INSERT` et `BEFORE UPDATE` avec `RAISE(ABORT, '<nom> : <raison>')`. C'est le schéma de `clotures_caisse_personnelles` (migration `2026_10_09_100001`). L'écriture est déjà sérialisée.
- Noms explicites de moins de 63 caractères. `GardesDesDroits::retirer()` dans chaque `down()`.

| Garde (nom) | Table | Refuse |
|---|---|---|
| `affectations_coherentes_avec_le_role` | `affectations` | un `role` qui n'est ni nul ni une valeur de `RoleMagasin` (`responsable`, `vendeur`, `observateur`) ; une affectation dont le compte est propriétaire ou expert-comptable ; `role` nul alors que le compte n'est pas administrateur ; `role` non nul alors que le compte est administrateur ; `magasin_id` nul avec un `role` non nul ; un `role` qui devient `responsable` alors que l'affectation a des cases ; une affectation par magasin d'un administrateur qui a une affectation à tous les magasins, ou une affectation à tous les magasins d'un administrateur qui a des affectations par magasin [C17] |
| `autorisations_affectations_sans_responsable` | `autorisations_affectations` | une case sur une affectation dont le `role` est `responsable` |
| `autorisations_societe_administrateur_seul` | `autorisations_societe` | une ligne pour un compte qui n'est pas administrateur (propriétaire, expert-comptable ou membre) |
| `users_role_coherent_avec_les_droits` | `users`, quand `role_societe` change | devenir propriétaire ou expert-comptable avec une affectation ou une autorisation de société ; devenir administrateur avec une affectation de membre (`role` non nul) ; devenir membre avec une affectation d'administrateur (`role` nul) ou une autorisation de société |
| `users_un_expert_comptable_par_societe` (index unique partiel de la migration `users`) | `users` | un second expert-comptable dans la société [I-21] |

**Ordre des transitions permises**, chacune dans la transaction de son action :
- **Nommer administrateur un membre** : supprimer ses affectations de membre ; puis `role_societe = administrateur` ; puis insérer ses affectations d'administrateur et ses autorisations de société.
- **Rétrograder un administrateur, le retirer, ou traiter son refus** : supprimer ses affectations d'administrateur et ses autorisations de société ; puis `role_societe = membre` ; puis, pour une rétrogradation seulement, insérer ses affectations de membre.
- **Créer l'expert-comptable** : insérer le compte avec `role_societe = expert_comptable`, sans aucune autre ligne.
- **Retirer l'expert-comptable, ou traiter son refus** : `statut = retire`, `role_societe = membre` ; il n'a aucune ligne à supprimer [I-26].
- **Réintégrer comme expert-comptable un compte retiré, ou accepter un rattachement comme expert-comptable** : le compte retiré n'a aucune ligne ; `role_societe = expert_comptable`, `statut = actif`.
- **Transférer la propriété** :
  1. supprimer les affectations et les autorisations de société du nouveau propriétaire ;
  2. l'ancien propriétaire devient administrateur ;
  3. le nouveau devient propriétaire (cet ordre, à cause de `users_un_proprietaire_par_societe`) ;
  4. l'ancien reçoit une affectation à tous les magasins, toutes les autorisations de magasin et toutes les autorisations de société.
- **Promouvoir un vendeur responsable dans M** : effacer les cases de cette affectation, puis `role = responsable`.

**Garde restée au modèle**, corrigée par A4-2 (section 5) et testée : un membre actif ou suspendu a au moins une affectation [I-7]. Elle reste au modèle parce qu'elle porte sur l'absence d'une ligne, qu'un déclencheur ne vérifierait qu'en fin de transaction ; sa violation n'ouvre aucun droit, puisqu'un compte sans affectation n'ouvre aucun magasin. `ModifierMembreAction` la tient : un compte qui perd son dernier magasin est retiré de la société (G8). La règle « tous les magasins ou une sélection, jamais les deux » est désormais en base [C17].

### 3.5 Adresse confirmée, mot de passe et sessions

**Définition**, écrite une seule fois dans le docblock de `User::aConfirmeSonAdresse()` : `users.email_verified_at` est la date à laquelle quelqu'un a suivi un lien envoyé à l'adresse actuelle du compte. On garde la colonne de Laravel, qui a déjà ce sens ; `User` n'implémente pas `MustVerifyEmail` et aucun panneau n'active la vérification d'e-mail de Filament [lu, `refus-de-compte.md` 4.2]. On n'ajoute aucune colonne.

**Qui la pose**, dans la requête même qui consomme le lien :
- `CreerSocieteAction`, pour l'inscription. C'est déjà le cas, `CreerSocieteAction.php:92` [lu].
- La page du lien « Mot de passe oublié » propre à PILIQI, dans la requête qui décide : accepter le compte en choisissant son mot de passe (compte actif), choisir son mot de passe (compte retiré), ou refuser le compte. Le lien a été suivi.
- `AccepterUnRattachementAction`.
- `ConfirmerNouvelleAdresseAction`, pour la nouvelle adresse.

Le courriel « Vous avez déjà un compte PILIQI » envoyé à l'adresse d'un compte retiré (« Créer votre propre société avec ce compte ») mène à cette même page du lien [E10] : il n'y a pas d'autre sorte de lien.

**Qui ne la pose jamais** : `CreerMembreAction`, `CreerAdministrateurAction`, `CreerExpertComptableAction`, `NommerAdministrateurAction`, `ModifierMembreAction`, `ChangerSonMotDePasseProvisoireAction`, `DonnerUnMotDePasseProvisoireAction`, et toute création par la société. HEAD la posait sans preuve dans `CreerMembreMagasinAction` [E6].

**Mot de passe et sessions** [C11, I-28].
- Toute écriture d'un mot de passe (provisoire changé, page du lien, profil), toute suspension, tout retrait et tout refus supprime les sessions du compte dans la table `sessions` (pilote `database`, `config/session.php:21` [lu]) et renouvelle `remember_token`. Seule est gardée la session courante de la personne qui change elle-même son mot de passe dans son profil ou sur la page du provisoire. `AuthenticateSession` ne protège que les deux panneaux (`AppPanelProvider.php:102`, `SocietePanelProvider.php:75` [lu]) : la suppression vaut aussi pour les routes hors panneau.
- Un refus remplace en plus le mot de passe par l'empreinte d'une valeur aléatoire que personne ne connaît. Ni l'auteur qui a fixé le provisoire, ni la personne qui utilisait le compte ne peuvent plus s'en servir.
- Un compte retiré, par la société ou par son propre refus, choisit son mot de passe sur la page du lien ; aucun panneau ne s'ouvre pour autant.
- `/societe/creer` (création de sa société par un compte retiré) et `/rattachement/{jeton}` redemandent l'adresse et le mot de passe du compte dans la requête qui agit ; aucune session antérieure ne suffit.

Conséquence : après un refus, ou après la confirmation de l'adresse d'un compte retiré, seule la personne qui reçoit les liens à cette adresse connaît un mot de passe utilisable. Elle seule peut créer sa société avec ce compte ou accepter un rattachement.

**Méthodes de `User`.**
- `peutAgir()` = compte actif et mot de passe définitif. `aAccepteSonCompte()` disparaît.
- `peutRefuserSonCompte()`, définition dans son docblock : le compte n'est pas le propriétaire ; `email_verified_at` est nul ; et le statut est `actif`, `suspendu`, ou `retire` par la société, c'est-à-dire avec un `statut_change_par` différent du compte lui-même [I-14]. Elle vaut pour un membre, un administrateur et l'expert-comptable [I-24]. Les actions la relisent en base, sous le verrou de la ligne du compte.
- `estExpertComptable()`.

### 3.6 Nom figé dans l'historique

**Règle** [É-refus, I-16]. Chaque ligne d'historique qui désigne une personne enregistre, au moment où elle la désigne, le nom de cette personne, dans une colonne sœur `<colonne>_nom`. Les écrans, les exports et les notifications lisent cette colonne, jamais le nom actuel du compte.

**Ce qui le fait déjà, inchangé** :
- l'en-tête signé du registre (`'operateur' => ['id', 'nom']`, `RegistreFiscal.php:486`), lu par `EnregistrementFiscal::libelleDeLOperateur()` ;
- le JET 130 (`'membre' => ['id', 'nom']`, `JournalDesDroits.php:207`) ;
- `journal_audit.auteur_libelle` (`JournalAudit.php:100`) ;
- le texte de consentement d'un mandat (`ApprouverMandatAction.php:78`).

**Colonnes à ajouter**, chacune dans la migration de création de sa table, `string(255)`. Elle est nullable si et seulement si la colonne qu'elle accompagne l'est.

| Table | Colonne d'auteur | Colonne du nom figé |
|---|---|---|
| `factures` | `cree_par` | `cree_par_nom` |
| `avoirs` | `cree_par` | `cree_par_nom` |
| `paiements` | `cree_par` | `cree_par_nom` |
| `clotures_caisse` | `cree_par` | `cree_par_nom` |
| `clotures_caisse_personnelles` | `vendeur_id`, `fermee_par` | `vendeur_nom`, `fermee_par_nom` |
| `factures_achat` | `cree_par` | `cree_par_nom` |
| `avoirs_fournisseur` | `cree_par` | `cree_par_nom` |
| `mouvements_stock` | `cree_par` | `cree_par_nom` |
| `envois_documents` | `envoye_par` | `envoye_par_nom` |
| `preuves_operation` | `note_par` | `note_par_nom` |
| `bordereaux_detaxe` | `note_par` | `note_par_nom` |
| `consultations_vies` | `consultee_par` | `consultee_par_nom` |
| `periodes_activite` | `ouverte_par`, `fermee_par` | `ouverte_par_nom`, `fermee_par_nom` |
| `verifications_societe` | `auteur_id`, `beneficiaire_id` | `auteur_nom`, `beneficiaire_nom` |
| `pieces_verification` | `deposee_par` | `deposee_par_nom` |
| `codes_confirmation` | `demande_par` | `demande_par_nom` |
| `demandes_accord_dirigeant` | `demande_par` | `demande_par_nom` |
| `demandes_rattachement` | `demande_par` | `demande_par_nom` |

**Non concernées**, avec la raison :
- `users.cree_par` et `users.statut_change_par` : liens internes, jamais affichés comme historique ;
- `paniers_caisse.user_id` : un panier en cours ;
- `affectations.user_id` : des droits actuels ;
- `journal_audit.auteur_id` : `auteur_libelle` existe ;
- `enregistrements_fiscaux.operateur_id` : le nom est signé ;
- `mandats_pdp.accorde_par` : la table est remplacée à l'étape 2 par `connexions_pdp`, qui portera son nom figé (note pour l'étape 2).

**Mécanisme** : un trait de modèle `FigeLeNomDeSesAuteurs`, sur le modèle de `FigeSonEmetteur`.
- Chaque modèle déclare ses couples, par exemple `['cree_par' => 'cree_par_nom']`.
- Un écouteur `saving` pose `<colonne>_nom` depuis le nom que la base donne pour ce compte, dans la même transaction, chaque fois que `<colonne>` est posée ou change. C'est donc le nom au moment de l'opération.
- Toute autre modification de `<colonne>_nom` est refusée (`LogicException`).
- Les tables en ajout seul refusent déjà toute modification par leurs déclencheurs.

**Qui le lit** (remplacements obligatoires) :
- `FactureResource.php:190` et `:631` (`creePar.name`) ;
- `AvoirResource.php:169` et `:312` ;
- `PaiementResource.php:355` ;
- `FactureAchatResource.php:114` ;
- `AvoirFournisseurResource.php:84` ;
- `ClotureCaisseResource.php:105` et `:172` ;
- `ClotureCaissePersonnelleResource.php:59` (`vendeur.name`) ;
- `ClotureCaissePersonnelle.php:103` (« Fermée par … ») ;
- `ListClotureCaisses.php:126` (caisses fermées d'office) ;
- `FactureResource.php:415` et `:464` (preuves et bordereau, « par … ») ;
- `resources/views/accord-dirigeant/afficher.blade.php:34` lit `demande_par_nom` ;
- les écrans d'équipe et `VerificationSociete`, s'ils affichent un auteur ;
- `JournalAuditResource` (lot A7) lit `auteur_libelle`.

Aucun PDF n'imprime aujourd'hui le nom d'une personne : le PDF imprime le magasin [lu, `resources/views/mail/document.blade.php:2-4`].

### 3.7 Énumérations, constantes, classes

| Élément | Après l'avenant |
|---|---|
| `RoleSociete` | Cas : `Proprietaire`, `Administrateur`, `ExpertComptable` (`expert_comptable`, libellé « Expert-comptable »), `Membre`. Docblock réécrit : qui crée chaque rôle, et lequel a des affectations [É-EC-rôle, C2] |
| `RoleMagasin` | Cas : `Responsable`, `Vendeur`, `Observateur`. `aDesCases(): bool`, faux pour `Responsable`. `permissionsParDefaut()` pour `Vendeur` et `Observateur` seulement (listes du travail A4) ; `Responsable` n'a aucun modèle, puisqu'il a toutes les autorisations sans case. `Comptable` et son modèle disparaissent [É-EC-rôle]. Docblock réécrit |
| `PermissionRegistry` | Liste inchangée. Ajouter `consultations(): list<string>` : les autorisations qui ne font que lire (`client.view`, `fournisseur.view`, `produit.view`, `produit.voir_valeur_stock`, `devis.view`, `facture.view`, `facture.view_toutes`, `paiement.view`, `avoir.view`, `cloture_caisse.view`, `cloture_caisse.view_toutes`, `facture_achat.view`, `avoir_fournisseur.view`, `caisse_securisee.consulter`) ; une autorisation de consultation nouvelle s'y ajoute ; oubliée, elle manque à l'expert-comptable, ce qui reste sûr. `permisesEnLectureSeule()` devient `consultations()` plus `cloture_caisse.gerer`. Ajouter `modeleDeLAdministrateur(): list<string>` : toutes les autorisations sauf `PARAMETRES_GERER`, et sauf `STOCK_VENDRE_SANS_STOCK` dès le lot A6 ; c'est l'ancienne liste du modèle `Responsable`, devenue le modèle précoché des cases de magasin d'un administrateur. Docblocks réécrits : celui de `PARAMETRES_GERER` dit « l'équipe du magasin se gère par son responsable et le propriétaire » ; ceux de `PRODUIT_VOIR_VALEUR_STOCK` et `PAIEMENT_CONTRE_PASSER` ne citent plus le comptable |
| `AutorisationsSociete` | `MEMBRES_GERER` disparaît. Ajouter `RESPONSABLES_NOMMER = 'societe.responsables.nommer'`, libellé « Responsables : nommer et retirer les responsables de ses magasins, et changer leurs magasins (un responsable a tous les droits de son magasin) » [I-4]. `toutes()` = `MAGASINS_GERER`, `RESPONSABLES_NOMMER`, `PARAMETRES_GERER` ; `FACTURES_RECUES_GERER` arrive à l'étape 2. `exigentTousLesMagasins()` = `PARAMETRES_GERER`. `permisesEnLectureSeule()` = `RESPONSABLES_NOMMER`, sauf dans une société révoquée. `modeleDeLAdministrateur()` = `MAGASINS_GERER` seulement [I-10]. Docblock réécrit |
| `StatutCompte` | inchangé depuis A4 |
| `ActionAuditee` | ajouter `CompteRefuse` [E12], `ResponsableNomme`, `ResponsableRetire`, `ExpertComptableCree`, tous dans la catégorie `acces` |
| `User` | Ajouter une valeur par défaut, identique à la migration, pour les colonnes que lisent les droits (`role_societe`, `statut`, `mot_de_passe_a_changer`) : c'est la correction A4-1. Méthodes : section 4.6. Ajouter `estExpertComptable()`, `estResponsableDe(Magasin)`, `aConfirmeSonAdresse()`, `peutRefuserSonCompte()`. Retirer `aAccepteSonCompte()`. `droitsDans()` rend `{role_societe, role_magasin, statut, permissions}` ; pour un responsable, toutes les autorisations ; pour l'expert-comptable, `consultations()` ; triées |
| `Affectation` | `role` casté en `RoleMagasin` nullable. `estDAdministrateur()` (rôle nul), `estDeResponsable()`. `cocher()` contrôle (3.3). Gardes de `booted()` corrigées (A4-2), avec des messages clairs, avant les gardes en base |
| `PorteeDesDroits` | réécrite pour la matrice (4.5) |
| `LecteurDesDroits` | réécrite (4.2) |
| `OperateurCourant` | **nouvelle** (4.2) |
| `JournalDesDroits` | `modifier()` reçoit et relit l'auteur (4.5) ; `inscrireLesDroitsImplicites()` compte l'expert-comptable |
| `DestinatairesDesAlertes` | ajoute les responsables du magasin (4.9) |
| `MembrePolicy` | réécrite ; nouvelle `AffectationPolicy` ; `MagasinDeLaSocietePolicy::nommerUnResponsable()` (4.6) |
| `FigeLeNomDeSesAuteurs`, `GardesDesDroits` | **nouvelles** (3.6, 3.4) |
| Notification `ResponsableNommeParUnAdministrateur` | **nouvelle** : au propriétaire, à chaque responsable nommé par un administrateur (nom de l'administrateur, de la personne et du magasin) [I-4] |
| `TestCase` | Voir ci-dessous [C16] |

**Aides de test** [C16]. Toutes passent par les vraies actions, puis par le changement du provisoire, comme le vrai parcours :
- `membre(Magasin $magasin, RoleMagasin $role, ?array $autorisations = null): User` garde sa signature : `CreerMembreAction` par le propriétaire, puis `ChangerSonMotDePasseProvisoireAction` au nom de la personne ; `email_verified_at` reste nul ; une liste de cases est refusée pour `Responsable` ;
- `responsable(Magasin ...$magasins): User`, `administrateur(Magasin $magasin, ?array $selection = null, array $autorisationsSociete = [], ?array $autorisationsMagasin = null): User` et `expertComptable(Magasin $magasin): User` suivent le même parcours (`CreerMembreAction`, `CreerAdministrateurAction`, `CreerExpertComptableAction`) ;
- `compteAuMotDePasseProvisoire(Magasin $magasin, RoleMagasin $role): array{0: User, 1: string}` : pour les tests qui ont besoin d'un compte qui n'a pas encore choisi son mot de passe ; rend le compte et son provisoire.

Sans cela, tout compte créé par une aide garderait son provisoire, et `ControleDesDroits` ne lui donnerait aucun droit (`ControleDesDroits.php:75` [lu]) : des centaines de tests tomberaient, comme les 1 147 erreurs d'A4-1.

### 3.8 Ce qui disparaît

- `users.premiere_connexion_le`, `User::aAccepteSonCompte()`, et la ligne `premiere_connexion_le` de `UserFactory` (`UserFactory.php:44`), qui masquait E3.
- `RoleMagasin::Comptable` et son modèle (`RoleMagasin.php:24`, `:32`, `:73-88`) [É-EC-rôle].
- `AutorisationsSociete::MEMBRES_GERER` (`'societe.membres.gerer'`).
- `affectations.modele`, remplacé par `role`.
- La règle de la conception « un membre sans affectation ne se gère que par le propriétaire ou un administrateur de tous les magasins », remplacée par G8.
- `CloturerLesJourneesAction::jusquAu()`, remplacée par `parUnePersonne()` et `parLeSysteme()` (4.4) [C13].
- Les six `cree_par ??= auth()->id()` des modèles : `Facture.php:120`, `Avoir.php:91`, `Paiement.php:51`, `ClotureCaisse.php:61`, `FactureAchat.php:63`, `AvoirFournisseur.php:56` [lu].
- Les lectures de `auth()` dans le domaine, remplacées par l'auteur reçu : `AjouterPreuveOperationAction.php:84`, `NoterBordereauDetaxeAction.php:73`, `VerifierNumeroTvaAction.php:64`, `EnregistrerVenteCaisseAction.php:79`, `ConstructeurLigneAchat.php:331`, `ExporterJournalDesEvenementsAction.php:99` [lu]. `RegistreFiscal.php:465` passe par `OperateurCourant`.
- `LecteurDesDroits::verifierSousVerrou(?User …)`.
- Les commentaires périmés qui citent Spatie : `app/Providers/Filament/AppPanelProvider.php:43-44`, `app/Policies/EnregistrementFiscalPolicy.php:15` [lu].
- Dans la conception, l'argument du cache global de Spatie (`conception-v2-finale.md:41` et `:565`) est retiré [RF 1.1]. La raison du retrait est [É-Spatie] : simplicité, aucune dépendance, aucun usage non documenté d'un paquet, référentiel en base.

---

## 4. Contrôles côté serveur

### 4.1 Principes

1. **Trois niveaux** [RF 5.1] :
   - l'écran : Filament revérifie à chaque requête Livewire ;
   - la Gate et les policies : `ControleDesDroits` est le seul `Gate::before` ;
   - **chaque action métier**, qui reçoit son auteur, le relit en base et contrôle ses droits sur ce compte relu, au moment où elle écrit.
2. **Auteur explicite.** Toute action qu'une personne déclenche reçoit `User $auteur`, non nullable. Les opérations du système passent par des entrées nommées, distinctes, que seuls les commandes planifiées, les jobs et les actions listés appellent, toujours sous `OperateurCourant::pendantLeSysteme()` :
   - `CloturerCaisseAction::parLeSysteme()` ;
   - `CloturerLesJourneesAction::parLeSysteme()` : clôture automatique de minuit ; au lot A5, fermeture d'un magasin (7.1) ;
   - `FermerSaCaisseAction::fermerDOffice()` ;
   - `FermerLesCaissesPersonnellesAction`, appelée seulement par les deux entrées de `CloturerLesJourneesAction` et par `ClotureAutomatiqueCaisse` ;
   - `EnvoyerDocumentAction::relanceAutomatique()` ;
   - `ExpirerLesDevisAction` ;
   - `GenererSoumissionEReportingAction`.

   Le bouton « Clôturer la journée » n'appelle que `CloturerLesJourneesAction::parUnePersonne()`, qui reçoit son auteur [C13].
3. **Relecture sous verrou.** Une vente, un paiement, un avoir, une contre-passation, une clôture manuelle, la fermeture de la caisse d'une autre personne, la validation d'un achat ou la modification d'un achat validé, l'émission d'un avoir fournisseur, ou un geste d'équipe verrouille la ligne de son auteur et relit ses droits, après les registres qu'il écrit et avant ses documents et ses produits (ordre de 4.3) [RF 7.4, C15]. Un retrait de droits, qui verrouille la même ligne après les mêmes registres, passe donc tout entier avant ou après.
4. **Échec fermé** (4.8).
5. **Attribution.** L'auteur relu est celui que le registre signe (`OperateurCourant`) et celui que la ligne enregistre (`cree_par` et son nom figé). Une entrée du système est signée « système » (opérateur nul), jamais au nom de la personne connectée [C14]. Le domaine ne lit plus `auth()`, sauf `OperateurCourant` pour les événements techniques hors action : ouverture et fin de session, abandon d'une vente, ligne retirée du panier.

### 4.2 `LecteurDesDroits` et `OperateurCourant`

```php
final class LecteurDesDroits
{
    /** Relit l'auteur en base et lui pose la question, dans ce magasin, qui doit être le magasin actif. */
    public function exiger(User $auteur, string $geste, Magasin $magasin, mixed $cible = null): User;

    /** Idem, en verrouillant d'abord la ligne de l'auteur (FOR NO KEY UPDATE) ; l'appelante a déjà pris les registres qu'elle écrit. */
    public function exigerSousVerrou(User $auteur, string $geste, Magasin $magasin, mixed $cible = null): User;

    /** Pour un geste de la société (panneau société, actions de la société), la société devant être celle du contexte. */
    public function exigerDansLaSociete(User $auteur, string $geste, Societe $societe, mixed $cible = null): User;
}
```

**Les trois méthodes** :
- refusent (`LogicException`) quand le magasin ou la société du contexte n'est pas celui ou celle passé en argument, ou qu'il n'y a pas de contexte ;
- relisent le compte par `User::query()->whereKey($auteur->getKey())`, sans la mémoire de ses droits ;
- posent la question à `Gate::forUser($compteRelu)` ;
- lèvent `AuthorizationException(LecteurDesDroits::REFUS)` en cas de refus ;
- rendent le compte relu, que l'action utilise ensuite pour le nom figé, la règle « une caisse à la fois » et la vente sans stock (R1).

**Le verrou.** `exigerSousVerrou()` verrouille la ligne du compte comme `FermerSaCaisseAction` (`lock('for no key update')`, `FermerSaCaisseAction.php:112` [lu]). Les ventes et les paiements qui vérifient leur clé étrangère vers cette ligne (`FOR KEY SHARE`) n'attendent donc pas. Sous SQLite, l'écriture est déjà sérialisée.

**`OperateurCourant`** [C14] est lié en `scoped()`, comme `TenantContext`. Il a trois états : une personne, le système, rien de posé.
- `pendant(User $operateur, Closure $operation): mixed` pose la personne pour la durée de l'opération ; `pendantLeSysteme(Closure $operation): mixed` pose le système. Chacun restaure l'état précédent à la fin, même après une exception.
- `actuel(): ?User` rend la personne posée ; `null` sous `pendantLeSysteme()`, sans jamais revenir à `auth()->user()` ; si rien n'est posé, `auth()->user()`, pour les seuls événements techniques de 4.1 point 5.
- `estPose(): bool`.
- `RegistreFiscal::inscrire()` (ventes, avoirs, encaissements, clôtures) refuse d'écrire si rien n'est posé (`LogicException`) et lit `actuel()` au lieu de `auth()->user()` (`RegistreFiscal.php:465`) ; `RegistreFiscal::journaliser()` lit `actuel()`, repli compris.
- Chaque action enveloppe ses écritures dans `pendant($compteRelu, …)` ; une entrée du système, dans `pendantLeSysteme(…)`.

Conséquence : l'opérateur signé est toujours l'auteur contrôlé, ou le système. Une opération du système appelée pendant une requête authentifiée (bouton, job synchrone, fermeture d'office qui suit une clôture) n'est jamais signée au nom de la personne connectée, et une action qui oublierait de poser son auteur échoue au lieu de signer au hasard.

### 4.3 Ordre des verrous

L'ordre de la conception 4.9 est gardé et précisé :
1. registres des magasins concernés, par identifiant croissant ;
2. ligne `societes` ;
3. lignes `users` : l'auteur et les comptes visés, par identifiant croissant, `FOR UPDATE` pour un geste d'équipe ; l'auteur seul (et le vendeur pour la fermeture d'une caisse), `FOR NO KEY UPDATE`, pour une vente, un paiement, une clôture, un achat ou un avoir fournisseur ;
4. lignes du document ;
5. produits suivis, par identifiant croissant ;
6. `sequences_documents` ;
7. têtes du journal d'audit.

**Pourquoi aucun interblocage.**
- `FermerSaCaisseAction` verrouille les lignes de l'auteur et du vendeur, par identifiant croissant, sans prendre de registre, puis n'en demande aucun.
- `ValiderFactureAchatAction`, `ModifierFactureAchatAction` et `EmettreAvoirFournisseurAction` ne prennent un registre que quand elles l'écrivent (changement de taux : `ConstructeurLigneAchat::verrouillerLeRegistreSiUnTauxChange()`, `ModifierFactureAchatAction.php:61` [lu]), puis la ligne de l'auteur, puis le document, puis les produits.
- Deux ventes de la même personne dans deux magasins se suivent sur la ligne de son compte.
- Une modification de ses droits prend d'abord les registres, puis son compte.
- Une garde en base qui verrouille la ligne d'un compte (3.4) le fait dans la transaction qui écrit, après les verrous que celle-ci tient déjà.
- Aucun cycle ne se forme.

**Ordre de `EmettreFactureAction`**, qui remplace la conception 8.6. Les numéros 4 et 5 sont désormais avant le verrou de la facture, pour respecter l'ordre comptes, puis documents :
1. `ActionsUniques::executer` (lot A6), avec l'auteur reçu.
2. Transaction : `RegistreFiscal::verrouiller($magasin)`.
3. `Magasin::verifierQuIlPeutVendre()`.
4. `LecteurDesDroits::exigerSousVerrou($auteur, 'emettre', $magasin, $facture)`. Avec un règlement comptant, aussi `encaisser`.
5. Une caisse à la fois : le compte relu n'a aucune caisse personnelle ouverte dans un autre magasin. C'est une lecture hors du magasin (1.2) ; refus : « Fermez d'abord votre caisse de {magasin} ».
6. Facture `lockForUpdate`, statut relu, lignes.
7. Puis les points 6 à 13 de la conception 8.6 : contexte de vente, identité légale, journée clôturée, produits verrouillés et `VerificateurDeStock` (sur le compte relu), VIES, numérotation, enregistrement. Toutes les écritures sont dans `OperateurCourant::pendant($compteRelu, …)`.

La même règle « une caisse à la fois » vaut pour `EnregistrerVenteCaisseAction`, `EnregistrerPaiementAction` et `ContrePasserPaiementAction`. Ce sont les opérations que compte une caisse personnelle.

### 4.4 Actions métier : auteur, contrôle, verrou

La colonne « Contrôle » donne la question posée à la Gate sur le compte relu (`LecteurDesDroits`). Les contrôles d'état propres à chaque action (statut du document, porte de validation, lecture seule) restent en plus.

| Action | Signature après l'avenant | Contrôle | Relecture |
|---|---|---|---|
| `CreerFactureAction` | `handle(User $auteur, Client $client, array $lignes, array $attributs = [])` | `create` sur `Facture` ; R2 au lot A6 | `exiger`, dans la transaction |
| `ModifierFactureAction` | `handle(User $auteur, Facture $facture, Client $client, array $lignes, array $attributs = [])` | `update` (brouillon) ; R2 | `exiger`, après le verrou de la facture |
| `EmettreFactureAction` | `handle(User $auteur, Facture $facture, ?ReglementComptant $reglement = null)` | `emettre` (+ `encaisser`) ; une caisse à la fois ; R1 au lot A6 | `exigerSousVerrou` (4.3) |
| `EnregistrerVenteCaisseAction` | `handle(User $auteur, Client $client, array $lignes, ModePaiement $mode, ?string $reference = null, ?string $bonDAvoir = null)` | les trois autorisations de la caisse (`facture.create`, `facture.emettre`, `paiement.creer`) ; une caisse à la fois. Les actions internes reçoivent le même auteur | `exigerSousVerrou` |
| `CreerDevisAction`, `ModifierDevisAction` | auteur en premier argument | `create`, `update` ; R2 | `exiger` |
| `EmettreDevisAction` | auteur | `envoyer` | `exigerSousVerrou` (numérotation) |
| `AccepterDevisAction`, `RefuserDevisAction`, `CreerFactureDepuisDevisAction` | auteur | `accepter`, `refuser`, `convertir` | `exiger`, après le verrou du devis |
| `CreerAvoirAction`, `EnregistrerAvoirAction`, `EmettreAvoirAction` | auteur | `avoir.creer`, et la facture visible par l'auteur | `exigerSousVerrou` |
| `EnregistrerPaiementAction` | `handle(User $auteur, Client $client, array $donnees, array $allocations)` | `paiement.creer` ; `encaisser` sur chaque facture imputée ; une caisse à la fois | `exigerSousVerrou` |
| `ContrePasserPaiementAction` | `handle(User $auteur, Paiement $paiement, string $motif)` | `contrePasser` ; une caisse à la fois | `exigerSousVerrou` |
| `ImputerAvoirSurSesFacturesAction`, `AppliquerAvoirsDisponiblesAction` | internes, appelées seulement sous une action qui a contrôlé son auteur | — | sous le verrou de l'appelante |
| `CloturerCaisseAction` | `handle(User $auteur, Magasin $magasin, $date)` ; `parLeSysteme(Magasin $magasin, $date)` | `cloturer` | `exigerSousVerrou`, après le verrou du registre |
| `CloturerLesJourneesAction` [C13] | `parUnePersonne(User $auteur, Magasin $magasin, CarbonInterface $jour)` : pour chaque journée en attente, dans l'ordre, `CloturerCaisseAction::handle($auteur, $magasin, $journee)`, chacune dans sa propre transaction (registre verrouillé, `exigerSousVerrou($auteur, 'cloturer', $magasin)`, clôture enregistrée avec cet auteur, `cree_par` et son nom figé, sous `OperateurCourant::pendant($compteRelu)`) ; puis, après la dernière transaction, `FermerLesCaissesPersonnellesAction` sous `pendantLeSysteme()`, chaque relevé portant « fermée automatiquement » ; un échec de ces fermetures est signalé (`report()`) et ne défait aucune clôture. `parLeSysteme(Magasin $magasin, CarbonInterface $jour)` : le même parcours par `CloturerCaisseAction::parLeSysteme()`, sous `pendantLeSysteme()`. `journeesEnAttente()` reste une lecture, utilisée par l'écran et ses compteurs | `cloturer` (personne), par `CloturerCaisseAction` | `exigerSousVerrou`, à chaque journée |
| `FermerSaCaisseAction` | `handle(User $auteur, User $vendeur)` ; `fermerDOffice()` pour le système | `fermer` (sa caisse) ou `fermerLaCaisseDe` | `exigerSousVerrou`, après le verrou des lignes de l'auteur et du vendeur, par identifiant croissant [C15] |
| `EnvoyerDocumentAction` | `handle(User $auteur, DocumentEnvoyable $document, string $destinataire, ?string $message)` ; `relanceAutomatique(Facture $facture)` pour le système | `envoyer` | `exiger`, après le verrou du document ; revérifié au départ (4.9) |
| `AjouterPreuveOperationAction`, `NoterBordereauDetaxeAction` | auteur | `justifier` | `exiger` |
| `CreerFactureAchatAction`, `ModifierFactureAchatAction` | auteur, transmis à `ConstructeurLigneAchat` (fin de l'échec ouvert sans utilisateur, section 5 n° 17) | `facture_achat.creer` ; `facture_achat.valider` sur une facture validée ; `create` ou `update` de `Produit` par ligne | `exiger` ; `exigerSousVerrou` pour une facture validée, avant le document et les produits [C15] |
| `ValiderFactureAchatAction` | auteur | `valider` | `exigerSousVerrou`, avant le verrou du document et ceux des produits [C15] |
| `CreerAvoirFournisseurAction` | auteur | `avoir_fournisseur.creer` | `exiger` |
| `EmettreAvoirFournisseurAction` | auteur | `avoir_fournisseur.creer` | `exigerSousVerrou`, avant le document et les produits : il fait sortir du stock et recalcule le coût moyen, comme une validation [C15] |
| `TrouverOuCreerServiceAction` | auteur | `create` sur `Service` ; R2 | `exiger` |
| `VerifierNumeroTvaAction` | auteur | `update` sur `Client` | `exiger` |
| `ExporterJournalDesEvenementsAction`, `VerifierIntegriteAction` | auteur | `caisse_securisee.consulter` | `exiger` ; l'événement qu'elles inscrivent (export de données, opération de contrôle) est signé de l'auteur relu, expert-comptable compris [I-22] |
| Actions de la société : `CreerEtablissementAction`, `ModifierEtablissementAction`, `ModifierIdentiteSocieteAction`, `ModifierParametresFiscauxSocieteAction`, `CorrigerSirenAvantValidationAction`, `OuvrirLaDemonstrationAction`, actions de la vérification ; au lot A5 `FermerMagasinAction`, `RouvrirMagasinAction`, `ChangerSiretAction` | gardent leur auteur | leurs questions actuelles | `exigerDansLaSociete`, dans leur transaction. Elles questionnent aujourd'hui l'instance reçue (`Gate::forUser($auteur)`), dont le statut et le rôle peuvent être périmés |

### 4.5 Actions de l'équipe

**`JournalDesDroits::modifier(User $auteur, array $comptes, iterable $magasinsVises, Closure $modification): mixed`.**
- Verrouille les registres des magasins touchés, puis les lignes de l'auteur et des comptes visés, par identifiant croissant.
- Revérifie la liste des magasins touchés. Pour un administrateur de tous les magasins et pour l'expert-comptable, ce sont tous les magasins réels de la société.
- Appelle la fermeture avec `(User $auteurRelu, list<User> $comptesRelus)`.
- La fermeture applique les règles de `PorteeDesDroits` **au compte relu**, puis écrit, dans `OperateurCourant::pendant($auteurRelu, …)`.
- Inscrit ensuite le JET 130 de chaque magasin touché, avec avant et après relus en base, et l'entrée du journal d'audit.

C'est la correction de l'objection 9 : `JournalDesDroits.php:76-77` ne verrouillait et ne relisait que les comptes visés [RF 5.3].

`inscrireLesDroitsImplicites()`, appelée à la création d'un magasin, inscrit aussi les droits de l'expert-comptable, comme ceux du propriétaire et des administrateurs de tous les magasins.

**`PorteeDesDroits`, réécrite.** Chaque méthode reçoit des comptes relus et lit les affectations en base, sans mémoire. Son docblock dit les règles de la matrice.

| Méthode | Règle |
|---|---|
| `gereLEquipeDe(User $auteur, Magasin $magasin): bool` | propriétaire de la société du magasin, ou responsable de ce magasin ; compte qui peut agir |
| `peutNommerUnResponsable(User $auteur, Magasin $magasin): bool` | propriétaire ; ou administrateur avec `RESPONSABLES_NOMMER` et le magasin dans sa portée |
| `peutDonnerLeRole(User $auteur, User $compte, Magasin $magasin, RoleMagasin $role, ?Affectation $actuelle): bool` | G1, G2, G3, G5, G6, G14 et [I-5] : jamais soi-même, le propriétaire, un administrateur ni l'expert-comptable comme cible. `responsable` (créer, promouvoir, retirer, changer de magasin) : `peutNommerUnResponsable`. Rétrograder un responsable : propriétaire seul. Rôles de vendeur et d'observateur : `gereLEquipeDe`, et jamais sur une affectation de responsable. Compte au mot de passe provisoire : seulement la personne qui l'a créé (G14) |
| `peutRetirerLAffectation(User $auteur, Affectation $affectation): bool` | comme `peutDonnerLeRole` pour son rôle actuel ; dernière affectation : `peutRetirerDeLaSociete` [G8] |
| `peutSuspendreOuReactiver`, `peutRetirerDeLaSociete`, `peutRenouvelerLAcces` (`User $auteur, User $compte): bool` | G5, G7 et G10 |
| `peutDonnerUnProvisoire(User $auteur, User $compte): bool` | G11 : `mot_de_passe_a_changer` vrai, et propriétaire, ou, pour un compte qui n'a que des affectations de vendeur ou d'observateur, une même personne responsable de chacun de ses magasins ; jamais un administrateur [C12] |
| `autorisationsCochables(User $auteur, Magasin $magasin): list<string>` | toutes les autorisations du magasin pour le propriétaire et un responsable de ce magasin ; aucune sinon (l'expert-comptable compris). Les cases d'un administrateur sont cochées par le propriétaire, dans les actions d'administrateur |
| `verifierCochables(User $auteur, Magasin $magasin, array $autorisations): void` | `PermissionRegistry::verifierConnues()`, puis inclusion. Refus : `LogicException` |
| `comptesVisibles(User $auteur): Builder` | espace société : tous les comptes pour le propriétaire, expert-comptable compris ; pour un A+, les comptes qui ont une affectation de responsable dans sa portée ; aucun sinon |
| `candidatsResponsables(User $auteur, Magasin $magasin): Builder` | membres actifs affectés à un magasin de la portée de l'auteur (A+), ou tous les membres actifs (propriétaire), qui ont choisi leur mot de passe ou que l'auteur a créés (G14) ; jamais un administrateur, le propriétaire ni l'expert-comptable |
| `compteAAjouter(User $auteur, Magasin $magasin, string $adresse): ?User` | [I-18, C5, C21] : l'adresse est normalisée (minuscules, sans espaces) et cherchée avec `societe_id` égal à la société du magasin, **avant toute écriture** ; rend le compte seulement s'il est actif, membre (ni propriétaire, ni administrateur, ni expert-comptable), au mot de passe définitif ou créé par l'auteur (G14), et pas déjà affecté à ce magasin ; sinon `null`, et l'écran donne la même réponse neutre ; ne lit que l'identifiant, la société, le statut, le rôle de société, `mot_de_passe_a_changer` et `cree_par`. Une adresse d'une autre société n'atteint donc jamais la clé composite `(societe_id, user_id)` des affectations |

**Actions de l'équipe.** Ce sont les noms de la conception, avec les règles de l'avenant. Toutes passent par `JournalDesDroits::modifier()`, avec l'auteur en premier argument.

| Action | Rôle |
|---|---|
| `CreerMembreAction` | crée un compte membre (identité, mot de passe provisoire d'au moins 12 caractères, `mot_de_passe_a_changer`, expiration à 72 heures, `cree_par`, `email_verified_at` nul) avec une ou plusieurs affectations ; chacune est autorisée par `peutDonnerLeRole`. Garde la limite de 20 comptes par jour et par société, et la réponse neutre pour une adresse déjà utilisée. Un responsable créé par un administrateur prévient le propriétaire (`ResponsableNommeParUnAdministrateur`) [I-4] |
| `ModifierMembreAction` | applique d'un seul coup des changements aux affectations d'un membre : magasin vers `{role, autorisations}`, ou nul pour retirer. Chacun est autorisé magasin par magasin ; l'ensemble est atomique (ajouter N et retirer M ne passe jamais par zéro magasin). S'il ne reste aucune affectation, le compte est retiré de la société [G8]. Couvre nommer, retirer et changer les magasins d'un responsable, promouvoir, rétrograder (propriétaire seul), les cases et modèles des vendeurs et observateurs, et l'ajout d'un compte trouvé par `compteAAjouter` [I-18]. Une nomination de responsable par un administrateur prévient le propriétaire [I-4] |
| `ChangerStatutCompteAction` | suspendre, réactiver, retirer de la société, réintégrer : G5, G7, G10, G14. Le retrait d'un administrateur ou de l'expert-comptable le repasse membre [CO 3.4, I-26]. Suspension et retrait : sessions supprimées et `remember_token` renouvelé (3.5). Pour l'expert-comptable, JET 130 dans tous les magasins réels |
| `DonnerUnMotDePasseProvisoireAction` | `peutDonnerUnProvisoire` (G11) [E2, C12] ; sessions supprimées |
| `EnvoyerUnLienDeReinitialisationAction` | `peutRenouvelerLAcces` (G7) ; le lien mène à la page du lien de PILIQI (4.7) |
| `CreerAdministrateurAction`, `NommerAdministrateurAction`, `ModifierAdministrateurAction` | propriétaire seul [G4] ; ordre des transitions de 3.4 ; rétrograder exige les nouvelles affectations dans la même action ; `NommerAdministrateurAction` refuse l'expert-comptable [I-26] |
| `CreerExpertComptableAction` (**nouvelle**) [C2, C10] | `handle(User $auteur, Societe $societe, array $identite, string $motDePasseProvisoire): User`. Propriétaire seul [G5]. Refuse s'il existe déjà un expert-comptable actif ou suspendu dans la société ; l'index unique est la dernière garantie [I-21]. Même mot de passe provisoire, même limite de 20 comptes et même réponse neutre que `CreerMembreAction`. Registres de tous les magasins réels verrouillés par identifiant croissant ; JET 130 dans chacun (« avant » nul, « après » relu) ; audit `ExpertComptableCree` |
| `ChangerSonMotDePasseProvisoireAction` | la personne ; n'écrit pas `email_verified_at` ; supprime ses autres sessions |
| `RefuserSonCompteAction` | la personne, désignée par sa session ou par le jeton de la page du lien, jamais par un identifiant de la requête [E12]. Condition relue sous verrou : `peutRefuserSonCompte()`. Effets comme un retrait : verrous, JET 130 par magasin (tous les magasins réels pour un administrateur de tous les magasins et pour l'expert-comptable), audit `CompteRefuse`, affectations et autorisations de société supprimées, un administrateur ou l'expert-comptable repasse membre, notifications effacées, `statut_change_par` = la personne. En plus [C11, I-28] : mot de passe remplacé par une empreinte inutilisable, sessions supprimées, `remember_token` renouvelé. L'auteur (`cree_par`) est prévenu s'il est encore actif dans la société, sinon le propriétaire [I-15] |
| `ProposerUnRattachementAction`, `AccepterUnRattachementAction` | proposé par qui peut créer le rôle et les affectations proposés [I-19] ; comme expert-comptable, par le propriétaire seul [G5]. La demande porte `role_societe` (`membre` ou `expert_comptable`). À l'acceptation : mot de passe du compte redemandé dans la requête [C11], auteur relu et droits revérifiés, magasins revérifiés, absence d'un autre expert-comptable revérifiée, `email_verified_at` posé |
| `CreerSaSocieteAvecUnCompteRetireAction` | compte retiré seulement ; adresse confirmée ; adresse et mot de passe du compte redemandés dans la requête qui crée la société [E10, C11] |
| `ConfirmerNouvelleAdresseAction` | pose la nouvelle adresse et `email_verified_at` |
| `TransfererProprieteAction` | propriétaire seul, mot de passe redemandé, vers un administrateur actif dont la qualité est prouvée ; jamais l'expert-comptable ; ordre de 3.4 |

### 4.6 `ControleDesDroits`, lectures de `User`, policies

**Lectures qui échouent fermées selon le rôle.** C'est la correction de l'objection 10 : `User.php:236-249`, `:364-370`, `:412`, `:434` ignorent le rôle [RF 5.3, lu] ; et celle du défaut 27 : `administreTousLesMagasins()` est vrai dès qu'une ligne globale existe, même à côté d'affectations par magasin (`User.php:263-284`) [lu, C17].

| Méthode de `User` | Après l'avenant |
|---|---|
| `autorisationsDans(Magasin)` | `[]` pour une autre société ou un compte qui ne peut pas agir. Propriétaire : toutes. Expert-comptable : `PermissionRegistry::consultations()` dans un magasin réel de sa société, `[]` dans le magasin de démonstration. Administrateur : `[]` s'il a à la fois une affectation à tous les magasins et des affectations par magasin ; sinon les cases de son affectation sans rôle à ce magasin, ou de son affectation à tous les magasins. Membre : son affectation à ce magasin, jamais une affectation globale ni sans rôle ; `responsable`, toutes les autorisations ; `vendeur` et `observateur`, leurs cases. Toute autre configuration : `[]` |
| `autorisationsDeSociete()` | propriétaire : toutes. Administrateur : ses lignes. Tout autre compte (membre, expert-comptable) : `[]`, même si des lignes subsistent |
| `affectationQuiVautDans(Magasin)`, `magasinsAccessibles()`, `peutOuvrir()`, `administreTousLesMagasins()`, `magasinsDeSaPortee()` | n'honorent une affectation globale ou sans rôle que pour un administrateur qui n'a pas à la fois les deux sortes d'affectations, et une affectation avec rôle que pour un membre. L'expert-comptable ouvre tous les magasins réels de sa société, présents et futurs, jamais la démonstration, et n'a aucune portée d'administration |
| Mémoire `autorisationsParMagasin` | gardée pour les écrans. Les actions questionnent toujours un compte relu (4.2) |

**`ControleDesDroits`.**
- Propriétaire : `autorisationsDans()` et `aLAutorisationDeSociete()` lui donnent tout. Le contrôle ne répond jamais `true` d'office avant les policies : les règles d'état s'appliquent à lui [I-2].
- **Expert-comptable** [G5, I-22, C2, C10] :
  - toute autorisation de société : refus ;
  - une autorisation de magasin : accordée si elle est dans `consultations()`, après les refus communs (autre société, magasin d'une autre société) ; refusée sinon ;
  - une question de policy : `viewAny` et `view` vont à la policy, qui demande les consultations ; toute autre question (`create`, `update`, `delete`, `emettre`, `encaisser`, `envoyer`, `justifier`, `cloturer`, `fermer`, `fermerLaCaisseDe`, `valider`, `contrePasser`, `accepter`, `refuser`, `convertir`, et toute question future) : refus ;
  - ce refus ne dépend d'aucune ligne en base : même une affectation ou une case glissée en base après la suppression d'un déclencheur ne lui donne rien.
- Magasin en lecture seule : un geste dont la cible est l'équipe (`Affectation` ou `User`) reste permis, sauf dans une société révoquée [G12].
- **Aucune autorisation de société ne suffit seule pour un geste qui porte sur un magasin.** `responsables.nommer` exige aussi le magasin dans la portée (`peutNommerUnResponsable`), et `magasins.gerer` aussi (`administreLeMagasin`). C'est la correction de la « garantie mal formulée » de l'objection 14 [RF 5.3].

**Policies.**

| Policy | Rôle |
|---|---|
| `MembrePolicy` (`User`, espace société) | `viewAny` : propriétaire ou A+. `view` : `comptesVisibles`. `creerUnAdministrateur`, `nommerAdministrateur`, `modifierUnAdministrateur`, `creerLExpertComptable` : propriétaire seul. `suspendreOuReactiver`, `retirerDeLaSociete`, `renouvelerLAcces`, `donnerUnProvisoire` : les méthodes de `PorteeDesDroits`, aussi depuis le panneau du magasin. `delete`, `forceDelete`, `restore` : jamais |
| `AffectationPolicy` (**nouvelle**, `Affectation`, panneau du magasin) | `viewAny` : `gereLEquipeDe` du magasin actif. `view` : `viewAny`, et une affectation de membre (rôle non nul) du magasin actif [C19] ; un vendeur, un administrateur ou l'expert-comptable n'en voit donc aucune. `create` : rôles de vendeur et d'observateur (propriétaire, responsable du magasin) ; `responsable` : propriétaire. `update` : `peutDonnerLeRole`. `delete` (retirer du magasin) : `peutRetirerLAffectation`. Tout le reste : jamais |
| `MagasinDeLaSocietePolicy` | ajouter `nommerUnResponsable(User, Magasin)` = `peutNommerUnResponsable` |
| `SocietePolicy`, `ClotureCaissePersonnellePolicy`, policies des documents | inchangées. Un responsable y passe par ses autorisations, qui sont toutes celles du magasin ; l'expert-comptable par ses consultations. `SocietePolicy::peutAdministrer()` ne l'admet pas : l'espace société lui reste fermé |

### 4.7 Écrans

- **Espace société, `MembreResource`** :
  - pour le propriétaire, tous les comptes, avec toutes les actions de la matrice, dont « Créer l'expert-comptable » (masquée quand il en existe un) ;
  - pour un A+, la vue « Responsables » de ses magasins : nommer (compte neuf ou candidat), retirer un magasin, changer les magasins, retirer de la société et renvoyer un lien selon G7 ;
  - masquée pour A− ; l'espace reste fermé aux membres et à l'expert-comptable.
- **Panneau du magasin, `EquipeDuMagasinResource`** (**nouvelle**) [I-8] :
  - Modèle `Affectation`, filtré explicitement par `magasin_id` = magasin actif et `role` non nul (affectations de membre), puisqu'il n'a pas de `MagasinScope` (règle 2 de `CLAUDE.md`, contournement documenté et testé). Fermé à la recherche globale.
  - Ouvert au propriétaire et aux responsables du magasin.
  - Actions : créer un vendeur ou un observateur ; ajouter un compte existant de la société par son adresse exacte [I-18] (`compteAAjouter`, même réponse neutre pour toute adresse qui ne convient pas) ; changer le modèle et les cases ; retirer du magasin, avec, pour un dernier magasin, la fenêtre « {nom} sera retiré de la société » [G8] ; suspendre, réactiver, retirer de la société, renvoyer un lien, redonner un provisoire, visibles seulement si l'auteur peut le faire (G7, G11). Pour le propriétaire, en plus : nommer un responsable.
  - Le menu « Équipe du magasin » est visible du propriétaire et des responsables seulement.
- **Panneau du magasin, page `ChangerMotDePasseProvisoire`** : « Accepter », en choisissant son mot de passe, et « Refuser ce compte » quand `peutRefuserSonCompte()`. Plus l'intergiciel `ExigerUnMotDePasseDefinitif` [CO 3.4].
- **Page du lien « Mot de passe oublié » propre à PILIQI**, qui remplace celle de Filament par `->passwordReset(requestAction: …, resetAction: …)` [E5, C11] :
  - le lien part vers tout compte, avec la même réponse neutre ;
  - compte actif, non suspendu : « Accepter et choisir mon mot de passe » (« Choisir mon mot de passe » si le compte n'est plus refusable) ; « Refuser ce compte » s'il est refusable ;
  - compte suspendu : « Refuser ce compte » s'il est refusable, et rien d'autre : ni nouveau mot de passe, ni levée de la suspension ;
  - compte retiré, par la société ou par son propre refus : « Choisir mon mot de passe », sans ouvrir aucun panneau ; ensuite, la création de sa société ou un rattachement. « Refuser ce compte » s'il est refusable ; la page prévient alors : « Choisir votre mot de passe confirme votre adresse : vous ne pourrez plus refuser ce compte, et la société qui l'a créé pourra vous réintégrer. » ;
  - le jeton est consommé atomiquement par la seule requête qui décide, laquelle pose `email_verified_at`, supprime les sessions du compte et renouvelle `remember_token` ;
  - la page répond pareil quel que soit l'état du compte.
- **`/societe/creer` et `/rattachement/{jeton}`** : adresse et mot de passe du compte redemandés dans la requête qui agit (3.5).
- **Caisse** : bandeau « Vous encaissez pour … » et « Vendeur : … » [CO 3.6], inchangé.

### 4.8 Refus par défaut

| Situation | Effet |
|---|---|
| Action d'une personne sans auteur | impossible : `User $auteur` est non nullable. L'ancien chemin « auteur nul, rien n'est relu » (`LecteurDesDroits.php:32-43`) disparaît |
| Contexte absent ou différent du magasin passé | `LogicException` dans `LecteurDesDroits` ; la Gate ne donne rien sans contexte (aucune policy de magasin n'accorde) |
| Compte suspendu, retiré, ou au mot de passe provisoire | `peutAgir()` faux : aucun droit, nulle part |
| Lecture seule, magasin qui ne peut pas vendre | `ControleDesDroits`, inchangé, sauf G12 pour l'équipe |
| Affectation ou autorisation qui contredit le rôle | lue comme inexistante (4.6) et refusée par la base (3.4) |
| Administrateur qui a à la fois tous les magasins et une sélection | aucun droit (4.6), et refusé par la base (3.4) |
| Geste d'écriture demandé par l'expert-comptable | refusé par `ControleDesDroits`, quelles que soient les lignes en base ; la base refuse toute affectation et toute autorisation de société pour lui |
| Autorisation inconnue | refusée avant écriture (`verifierConnues`) et par la clé étrangère (3.3) |
| Opération inscrite au registre sans opérateur posé | `LogicException` (4.2) |
| Entrée du système appelée depuis un écran | interdit ; vérifié par le critère de fin de 6.6 |
| Ancien mot de passe ou session antérieure, après un refus | sans effet (3.5) |
| API (étape 10) | le magasin et l'auteur viennent du jeton d'accès ; sinon refus. Exigence reportée à l'étape 10 [RF 5.1] |

### 4.9 Envoi des e-mails et destinataires

**Revérification au départ** [É-e-mail, I-17]. Dans `EnvoyerDocumentJob::handle()`, après le contrôle du magasin et avant la génération du PDF : si `envoye_par` n'est pas nul, le compte est relu.
- L'envoi ne part pas si le compte n'existe plus dans la société, si `peutOuvrir($magasin)` est faux, ou si `Gate::forUser($compte)->denies('envoyer', $document)` dans le contexte du magasin que le job restaure (`WithTenantContext`).
- Dans ce cas : statut `Echec`, motif « La personne qui a demandé cet envoi n'a plus accès à ce magasin ou n'a plus le droit d'envoyer ce document : l'e-mail n'est pas parti. », **le propriétaire est prévenu** (`EnvoiDocumentEchoue`), et le job s'arrête sans nouvelle tentative.
- Une relance automatique (`envoye_par` nul) n'est pas concernée.
- Les autres échecs gardent leur destinataire actuel : l'expéditeur s'il ouvre encore le magasin, sinon le propriétaire (`aPrevenir()`).
- L'expert-comptable n'envoie rien [I-23] : aucun envoi ne porte son nom.

**Alertes de stock.** `DestinatairesDesAlertes::autorisesDans($magasin, $autorisation)` rend :
- le propriétaire ;
- **les responsables du magasin**, qui n'ont aucune case et que la requête actuelle, fondée sur les seules cases (`DestinatairesDesAlertes.php:31-35`), oublierait ;
- toute autre personne dont l'affectation porte la case : à ce magasin pour un membre, à ce magasin ou à tous pour un administrateur.

Tous filtrés par `peutOuvrir()`, avec la lecture qui échoue fermée. L'expert-comptable n'a pas la gestion du stock : il ne reçoit jamais l'alerte.

---

## 5. Défauts prouvés à corriger

Chaque correction s'accompagne de son test de régression, qui doit échouer sur le code d'avant la correction.

| N° | Défaut | Preuve | Correction | Test de régression |
|---|---|---|---|---|
| 1 | **S-ACTION-1** : l'action crée un compte dans une autre société, rattaché au magasin de celle-ci | sonde HEAD [SC D1] | `CreerMembreAction` : société de l'auteur relu = société de chaque magasin ; `peutDonnerLeRole` ; clés composites | `Societe/MembresTest::test_un_magasin_d_une_autre_societe_ne_s_affecte_jamais` (appel direct de l'action) ; `Authorization/ActionsHorsEcranTest::test_chaque_action_refuse_un_auteur_d_une_autre_societe_sans_rien_ecrire` |
| 2 | **S-ACTION-2** : l'action suspend le membre d'une autre société | sonde HEAD [SC D2] | `ChangerStatutCompteAction` : G1, puis G7 sur les comptes relus | `Societe/AutorisationsDesMembresTest::test_les_actions_des_membres_refusent_un_auteur_ou_un_compte_d_une_autre_societe` |
| 3 | **S-ACTION-3** : un vendeur sans « Équipe : créer » crée des comptes en appelant l'action | sonde HEAD [SC D17] | gérer l'équipe n'est plus une case : propriétaire ou responsable du magasin seulement (`gereLEquipeDe`) | `Filament/EquipeDuMagasinTest::test_un_vendeur_qui_a_toutes_les_cases_ne_gere_aucun_compte` (écran et action) ; ligne `CreerMembreAction` de `ActionsHorsEcranTest` |
| 4 | **S-VERROU-1** : `EmettreFactureAction` émet sans « Factures : émettre » | sonde HEAD [SC J1] | auteur reçu, `exigerSousVerrou` | `ActionsHorsEcranTest::test_chaque_action_refuse_un_auteur_sans_le_droit_sans_rien_ecrire`, ligne `EmettreFactureAction` |
| 5 | **S-VERROU-2** : un observateur enregistre un paiement par l'action | sonde HEAD [SC J1] | idem pour `EnregistrerPaiementAction` | même test, ligne `EnregistrerPaiementAction` (observateur) |
| 6 | **S-VERROU-3** : un retrait de « Factures : émettre », validé entre le contrôle de l'écran et le verrou du registre, laisse passer l'émission | sonde HEAD [SC E4] | relecture après le verrou du registre (4.3) | `Authorization/LecteurDesDroitsTest::test_une_autorisation_retiree_entre_l_ecran_et_le_verrou_du_registre_refuse_l_emission`, version SQLite déterministe avec `TestCase::concurrencerDesQueLeRegistreEstVerrouille()` ; `Societe/DroitsConcurrenceTest::test_un_retrait_d_autorisation_et_une_emission_simultanes_dans_le_meme_magasin_ne_laissent_jamais_passer_une_vente_non_autorisee` (PostgreSQL) |
| 7 | **S-ESCALADE-2** : un « Administrateur » de magasin retire un autre « Administrateur » plus autorisé | sonde HEAD [SC D7] | plus d'administrateur de magasin ; un responsable ne touche jamais un autre responsable (G6) ; un administrateur ne touche jamais un administrateur (G4) | `EquipeDuMagasinTest::test_un_responsable_ne_cree_ni_ne_touche_un_autre_responsable_ni_un_administrateur_ni_le_proprietaire` ; `AutorisationsDesMembresTest::test_un_administrateur_ne_touche_ni_au_proprietaire_ni_a_un_administrateur_ni_a_lui_meme` |
| 8 | **A4-1** : erreur de type de `User::doitChangerSonMotDePasse()` sur une instance neuve, qui fait tomber 1 147 des 1 155 erreurs | [CA 2.5], `User.php:182-185` [lu] | valeurs par défaut de `role_societe`, `statut` et `mot_de_passe_a_changer` déclarées sur `User`, identiques à la migration | `Societe/MembresTest::test_un_compte_tout_juste_cree_repond_sans_erreur_et_comme_relu` ; la suite entière revenue au vert |
| 9 | **A4-2** : la garde d'`Affectation` compare une énumération à une chaîne : le propriétaire reçoit une affectation, un administrateur de tous les magasins est refusé | [CA 2.5], `Affectation.php:47-61` [lu] | comparaison d'énumérations ; gardes en base (3.4) | `Societe/AdministrateursTest::test_le_proprietaire_n_a_jamais_d_affectation` ; `test_seul_un_administrateur_est_affecte_a_tous_les_magasins` ; `Authorization/GardesDesDroitsEnBaseTest::test_la_base_refuse_une_affectation_au_proprietaire` |
| 10 | **`verifierSousVerrou()` jamais appelée**, et silencieuse quand l'auteur est nul | [CA 2.4], `LecteurDesDroits.php:32-43`, citée seulement en docblock [lu] | `LecteurDesDroits` réécrite (4.2), appelée par chaque action de 4.4 ; auteur non nullable | `LecteurDesDroitsTest::test_une_action_sans_auteur_est_refusee` ; `test_le_paiement_l_avoir_la_contre_passation_la_cloture_la_caisse_la_fermeture_de_la_caisse_d_un_autre_et_l_avoir_fournisseur_relisent_l_auteur_sous_le_verrou` (SQLite déterministe, une donnée par geste) |
| 11 | **`premiere_connexion_le` non posée à l'inscription** : le propriétaire inscrit n'a aucun droit ; la fabrique masque l'écart [E3] | [RF 6.6], `UserFactory.php:44` [lu] | colonne supprimée ; `peutAgir()` = actif et mot de passe définitif ; fabrique corrigée | `Societe/InscriptionSocieteTest::test_le_proprietaire_inscrit_par_son_lien_peut_agir_des_son_inscription` (par `CreerSocieteAction`, pas par la fabrique) |
| 12 | **Choix entre lien et mot de passe provisoire fondé sur « jamais connecté »** : après un « Mot de passe oublié », l'auteur peut écraser le mot de passe choisi par la personne [E2] | [RF 6.6] | G11 : provisoire seulement si `mot_de_passe_a_changer` est vrai, et par qui a tous les droits du compte | `Societe/MotDePasseProvisoireTest::test_l_auteur_ne_redonne_pas_de_mot_de_passe_provisoire_apres_un_mot_de_passe_oublie` |
| 13 | **Droits de l'auteur gardés en mémoire** : un administrateur coche pour un autre une autorisation qu'on vient de lui retirer, même sous les verrous (objection 9). Avec l'avenant, le même défaut touche un responsable retiré de son magasin et un A+ privé de son autorisation | sonde A4 [RF 5.3] ; `User.php:315`, `PorteeDesDroits.php:144`, `:161`, `JournalDesDroits.php:76-77` [lu] | auteur verrouillé et relu dans `JournalDesDroits::modifier()` (4.5) | `Authorization/PorteeDesDroitsTest::test_un_responsable_retire_du_magasin_pendant_que_son_formulaire_est_ouvert_ne_cree_ni_ne_coche_plus_rien` ; `test_un_administrateur_prive_de_nommer_les_responsables_pendant_que_son_formulaire_est_ouvert_n_en_nomme_plus` (SQLite déterministes) ; `DroitsConcurrenceTest::test_un_retrait_des_droits_d_un_responsable_et_sa_modification_d_un_vendeur_simultanes_ne_donnent_jamais_un_droit_retire` (PostgreSQL) |
| 14 | **Auteur suspendu pendant sa requête** : son instance répond encore « peut agir » | sonde A4 [RF 5.3] | même relecture | `PorteeDesDroitsTest::test_un_auteur_suspendu_pendant_sa_requete_ne_modifie_aucun_compte` |
| 15 | **Administrateur rétrogradé qui garde une trace « tous les magasins »** ou des autorisations de société : il garde des droits partout, même dans un magasin créé ensuite (objection 10) | sonde A4 [RF 5.3] | lectures qui échouent fermées (4.6), suppressions par les actions (3.4), gardes en base | `AdministrateursTest::test_une_affectation_globale_ou_une_autorisation_de_societe_restee_a_un_membre_ne_donne_rien` (lignes écrites après `retirerLeDeclencheur()`) ; `test_retrograder_un_administrateur_ne_lui_laisse_aucun_droit_dans_un_magasin_cree_ensuite` ; `GardesDesDroitsEnBaseTest::test_la_base_refuse_de_retrograder_un_administrateur_qui_garde_une_affectation_globale_ou_une_autorisation_de_societe` |
| 16 | **Autorisation inconnue enregistrable** : aucune clé, `cocher()` sans contrôle (objection 4) | [RF 3.1], `Affectation.php:88-95` [lu] | référentiel et clés étrangères (3.3) | `Authorization/ReferentielDesAutorisationsTest::test_une_autorisation_inconnue_ne_s_enregistre_jamais` |
| 17 | **Échec ouvert sans utilisateur** : `ConstructeurLigneAchat::verifierLeDroitDEnregistrer()` ne contrôle rien quand `auth()->user()` est nul | `ConstructeurLigneAchat.php:329-343` [lu] | auteur explicite, transmis par les actions d'achat | ligne `CreerFactureAchatAction` de `ActionsHorsEcranTest::test_chaque_action_refuse_un_auteur_sans_le_droit_sans_rien_ecrire`, avec une ligne qui crée un produit |
| 18 | **Une caisse à la fois** : le verrou prévu, propre à chaque magasin, ne sérialise pas deux ventes de la même personne dans deux magasins (objection 11) | [RF 5.3] | verrou de la ligne du compte (4.3) | `Tenancy/TracabiliteDesVentesConcurrenceTest::test_deux_premieres_ventes_simultanees_du_meme_vendeur_dans_a_et_b_n_en_laissent_passer_qu_une` (PostgreSQL), et sa version SQLite déterministe |
| 19 | **Refus d'un compte simultané à une vente** (objection 12) | [RF 5.3] | refus sous les verrous des registres du compte ; vente qui relit le compte sous le verrou de son registre | `Societe/RefusDUnCompteConcurrenceTest::test_un_refus_et_une_vente_simultanes_du_meme_compte_ne_laissent_jamais_passer_une_vente_apres_le_refus` (PostgreSQL), et sa version SQLite déterministe |
| 20 | **Refus impossible pour un compte suspendu, sans magasin ou retiré** : Filament refuse la connexion et la réinitialisation [E5] (objection 13) | [RF 6.3] | page du lien propre à PILIQI (4.7) | les tests de `Societe/PageDuLienTest` (6.2) |
| 21 | **`email_verified_at` posé sans preuve** à la création d'un membre [E6] | [RF 6.4] | 3.5 | `MembresTest::test_un_compte_cree_par_la_societe_n_a_pas_d_adresse_confirmee` |
| 22 | **Réintégration contre la volonté de la personne** [E7] ; **rattachement qui laisserait le droit de refus ouvert** [E8] ; **création de sa société sans preuve de l'adresse** [E10] ; **refus du propriétaire** [E11] ; **refus non journalisé** [E12] | [RF 6.6] | 3.5, 4.5 | `RefusDUnCompteTest::test_la_societe_ne_reintegre_pas_un_compte_refuse`, `test_un_rattachement_accepte_confirme_l_adresse_et_ferme_le_droit_de_refus`, `test_un_compte_refuse_ne_cree_sa_societe_qu_apres_avoir_choisi_son_mot_de_passe_par_un_lien`, `test_le_proprietaire_ne_refuse_jamais_son_compte_meme_apres_un_transfert`, `test_un_compte_refuse_est_retire_avec_un_jet_130_par_magasin_un_audit_et_son_auteur_prevenu` |
| 23 | **Échec fermé mal formulé sans contexte** : la Gate accorde une autorisation de société par la société du compte (objection 14) | sonde A4 [RF 5.3] | aucune autorisation de société seule pour un geste qui porte sur un magasin (4.6) ; aucune policy de magasin n'accorde sans contexte | `Authorization/ControleDesDroitsTest::test_sans_contexte_aucune_policy_de_magasin_n_accorde_rien` ; `Societe/ResponsablesTest::test_un_administrateur_autorise_d_une_selection_ne_nomme_aucun_responsable_hors_de_sa_selection` |
| 24 | **Les responsables ne reçoivent plus l'alerte de stock** : la requête des destinataires ne lit que les cases | `DestinatairesDesAlertes.php:31-35` [lu] | 4.9 | `Stock/StockLedgerServiceTest::test_l_alerte_de_stock_va_aux_responsables_du_magasin_sans_case` |
| 25 | **Commentaires périmés qui citent Spatie** ; **`TestCase::membre()` qui appelle une classe supprimée** | `AppPanelProvider.php:43-44`, `EnregistrementFiscalPolicy.php:15`, `tests/TestCase.php:227-233` [lu] | réécriture ; aides de test par les vraies actions, puis le changement du provisoire (3.7) [C16] | critères de fin (6.6) ; `MembresTest::test_le_membre_cree_par_l_aide_de_test_agit_et_n_a_pas_d_adresse_confirmee` ; la suite entière |
| 26 | **Clôture manuelle par des entrées du système** [C13] : le bouton « Clôturer la journée » appelle `CloturerLesJourneesAction::jusquAu()` puis `FermerLesCaissesPersonnellesAction::handle()`, sans auteur ; le seul contrôle est la visibilité du bouton, au rendu ; un retrait de `cloture_caisse.gerer` validé entre ce contrôle et le verrou du registre laisse passer la clôture ; une fois les `cree_par ??= auth()->id()` supprimés, l'auteur de la clôture serait perdu | `ListClotureCaisses.php:106`, `:109`, `:167` ; `CloturerLesJourneesAction.php:74-81` (`cloturerCaisse->handle($magasin, $journee)`) [lu] | `CloturerLesJourneesAction::parUnePersonne()` (4.4), seule entrée appelée par l'écran ; recherche de 6.6 étendue | `LecteurDesDroitsTest::test_une_autorisation_retiree_entre_l_ecran_et_le_verrou_refuse_la_cloture_manuelle` (SQLite déterministe) ; `test_la_cloture_manuelle_enregistre_et_signe_son_auteur` ; donnée « clôture manuelle » de `DroitsConcurrenceTest::test_un_retrait_d_autorisation_et_une_emission_simultanes_…` (PostgreSQL) |
| 27 | **Administrateur qui a à la fois une affectation à tous les magasins et des affectations par magasin** [C17] : `administreTousLesMagasins()` est vrai dès qu'une ligne globale existe ; sa portée reste tous les magasins, magasins futurs compris, et ses cases globales valent hors de sa sélection | `User.php:263-284` [lu] | garde en base (3.4) et lecture qui échoue fermée (4.6) | `GardesDesDroitsEnBaseTest::test_la_base_refuse_qu_un_administrateur_ait_a_la_fois_tous_les_magasins_et_une_selection` ; `AdministrateursTest::test_une_ligne_globale_restee_a_un_administrateur_d_une_selection_ne_donne_rien` |
| 28 | **Modèle « comptable » de magasin qui écrit**, contraire à la série 6 [C2] : il coche « enregistrer un paiement », « contre-passer un paiement », « envoyer les factures » et « envoyer les avoirs » | `RoleMagasin.php:73-88` [lu] | rôle supprimé, sans compatibilité ; expert-comptable de société en lecture seule (3.7, 4.6) | `AutorisationsDesRolesTest::test_il_n_existe_plus_de_role_comptable_de_magasin` ; `ActionsHorsEcranTest::test_l_expert_comptable_n_ecrit_rien_meme_par_l_action` |

---

## 6. Tests exigés

### 6.1 Règles

- **Noms** : selon la convention des tests existants, en PHPUnit (`php artisan make:test --phpunit`).
- **Concurrence** : groupe `concurrence`, sous PostgreSQL, sur le modèle d'`EnregistrerPaiementActionConcurrenceTest`. Chacun a sa version **SQLite déterministe** quand l'avenant le dit.
- **Gardes en base** : tests sous les deux moteurs.
- **Écrans** : vraies requêtes Livewire quand le test vise une page restée ouverte, comme S-SESSION-1. `Livewire::test` ne rejoue pas l'intergiciel persistant [SC 6].
- **Tests existants** : aucun n'est supprimé ni affaibli. Leur destination est donnée en 6.3 à 6.5, ou dans l'annexe B de la conception.

### 6.2 Tests par fichier

Légende : **N** nouveau ; **G** gardé du plan de la conception ou de `recommandation-finale.md` 5.2 ; **A** adapté par l'avenant (ancien nom, puis nouveau).

**`tests/Feature/Authorization/ReferentielDesAutorisationsTest.php`** (N)
- `test_le_referentiel_des_autorisations_est_exactement_celui_du_registre` : les deux tables, comparées à `PermissionRegistry::toutes()` et `AutorisationsSociete::toutes()`.
- `test_une_autorisation_inconnue_ne_s_enregistre_jamais` : par l'action, refus avant toute écriture, l'ancienne case restant ; par `DB::table`, la base refuse, sous les deux moteurs.
- `test_une_autorisation_de_societe_ne_se_coche_jamais_dans_un_magasin_et_inversement`.
- `test_une_cle_portee_par_des_cases_ne_se_supprime_pas` (deux moteurs) [C18].
- `test_aucune_table_ni_aucune_classe_de_spatie_ne_subsiste` : tables `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` absentes ; aucune classe `Spatie\Permission` chargeable ; aucune configuration `permission` ; `composer.json` et `composer.lock` sans le paquet [RF 5.2 D, option b].

**`tests/Feature/Authorization/GardesDesDroitsEnBaseTest.php`** (N, deux moteurs)
- `test_la_base_refuse_une_affectation_au_proprietaire`
- `test_la_base_refuse_un_role_de_magasin_inconnu` : `comptable` compris [C2]
- `test_la_base_refuse_une_affectation_ou_une_autorisation_de_societe_a_l_expert_comptable` [C2, C10]
- `test_la_base_refuse_un_second_expert_comptable_dans_la_societe` [I-21]
- `test_la_base_refuse_une_affectation_globale_ou_sans_role_a_un_membre`
- `test_la_base_refuse_une_affectation_avec_role_a_un_administrateur`
- `test_la_base_refuse_qu_un_administrateur_ait_a_la_fois_tous_les_magasins_et_une_selection` [C17]
- `test_la_base_refuse_une_case_sur_une_affectation_de_responsable`
- `test_la_base_refuse_une_autorisation_de_societe_a_qui_n_est_pas_administrateur`
- `test_la_base_refuse_de_retrograder_un_administrateur_qui_garde_une_affectation_globale_ou_une_autorisation_de_societe`
- `test_la_base_refuse_de_rendre_proprietaire_ou_expert_comptable_un_compte_qui_garde_des_droits`
- `test_les_transitions_permises_passent` (témoin : nommer, rétrograder, transférer, refuser, promouvoir ; créer, retirer et réintégrer l'expert-comptable)
- `test_une_retrogradation_et_une_affectation_globale_simultanees_ne_laissent_jamais_une_affectation_globale_a_un_membre` (concurrence)
- `test_deux_affectations_simultanees_globale_et_par_magasin_d_un_administrateur_n_en_retiennent_qu_une` (concurrence) [C17]

**`tests/Feature/Authorization/LecteurDesDroitsTest.php`** (N) [RF 5.2 A 12]
- `test_une_action_sans_auteur_est_refusee`
- `test_un_contexte_absent_ou_d_un_autre_magasin_est_refuse`
- `test_une_autorisation_retiree_entre_l_ecran_et_le_verrou_du_registre_refuse_l_emission` (SQLite déterministe)
- `test_une_autorisation_retiree_entre_l_ecran_et_le_verrou_refuse_la_cloture_manuelle` (SQLite déterministe) [C13]
- `test_une_autorisation_retiree_avant_le_verrou_refuse_la_validation_d_un_achat` (SQLite déterministe) [C15]
- `test_le_paiement_l_avoir_la_contre_passation_la_cloture_la_caisse_la_fermeture_de_la_caisse_d_un_autre_et_l_avoir_fournisseur_relisent_l_auteur_sous_le_verrou` (SQLite déterministe, une donnée par geste) [C15]
- `test_l_operateur_signe_et_l_auteur_enregistre_sont_l_auteur_passe_a_l_action` : connecté sous un autre compte, le contenu signé, `cree_par` et `cree_par_nom` désignent l'auteur reçu.
- `test_la_cloture_manuelle_enregistre_et_signe_son_auteur` : `cree_par`, `cree_par_nom` et l'opérateur signé de chaque journée clôturée ; les caisses fermées d'office ensuite portent « fermée automatiquement » et un opérateur nul [C13].
- `test_une_entree_du_systeme_appelee_pendant_une_requete_authentifiee_signe_un_operateur_nul` [C14]
- `test_une_operation_inscrite_au_registre_sans_operateur_pose_est_refusee` [C14]

**`tests/Feature/Authorization/ActionsHorsEcranTest.php`** (N) [RF 5.2 A 2]. Une donnée par action de 4.4 et 4.5 : actions de facturation, d'achats, du catalogue, des contacts, de la caisse sécurisée, de l'équipe (`CreerExpertComptableAction` comprise), de la société.
- `test_chaque_action_refuse_un_auteur_sans_le_droit_sans_rien_ecrire`
- `test_chaque_action_refuse_un_auteur_d_une_autre_societe_sans_rien_ecrire`
- `test_chaque_action_refuse_un_auteur_suspendu_retire_ou_au_mot_de_passe_provisoire_sans_rien_ecrire`
- `test_l_expert_comptable_n_ecrit_rien_meme_par_l_action` (une donnée par action d'écriture de 4.4 et 4.5) [C10]

**`tests/Feature/Authorization/ControleDesDroitsTest.php`** (G, complété)
- `test_sans_contexte_aucune_policy_de_magasin_n_accorde_rien` [RF 5.2 A 22]
- `test_le_proprietaire_n_a_besoin_d_aucune_case_mais_reste_soumis_aux_regles_de_tous` [I-2] : magasin fermé, facture émise non supprimable, journée clôturée, porte de validation, une caisse à la fois.
- `test_un_responsable_a_toutes_les_autorisations_de_son_magasin_et_aucune_ailleurs`
- `test_une_autorisation_de_societe_ne_vaut_jamais_seule_pour_un_magasin_hors_de_la_portee`
- `test_l_expert_comptable_n_a_que_les_consultations_quelles_que_soient_les_lignes_en_base` : affectation et cases glissées après `retirerLeDeclencheur()` ; aucune autorisation d'écriture, aucune autorisation de société [C2]

**`tests/Feature/Societe/ExpertComptableTest.php`** (N) [C1, C2, C3, C10]
- `test_seul_le_proprietaire_cree_suspend_reactive_retire_et_reintegre_l_expert_comptable` : un administrateur (même de tous les magasins, avec toutes les autorisations, A+ compris), un responsable et un vendeur sont refusés par l'écran, par une requête forgée et par l'action.
- `test_l_expert_comptable_lit_tous_les_magasins_reels_y_compris_un_magasin_cree_apres_lui`
- `test_l_expert_comptable_n_ouvre_ni_l_espace_societe_ni_la_demonstration_ni_la_caisse`
- `test_l_expert_comptable_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur` [I-22]
- `test_l_expert_comptable_telecharge_les_pdf_et_exporte_le_journal_des_evenements_a_son_nom` [I-22]
- `test_l_expert_comptable_n_envoie_aucun_e_mail` [I-23]
- `test_une_societe_n_a_qu_un_expert_comptable` [I-21]
- `test_creer_suspendre_ou_retirer_l_expert_comptable_inscrit_le_jet_130_dans_chaque_magasin_reel`
- `test_un_nouveau_magasin_inscrit_les_droits_implicites_de_l_expert_comptable`
- `test_retirer_l_expert_comptable_le_repasse_membre_sans_aucun_droit` [I-26]
- `test_l_expert_comptable_refuse_son_compte_tant_que_son_adresse_n_est_pas_confirmee` [I-24]
- `test_seul_le_proprietaire_propose_un_rattachement_comme_expert_comptable` [I-19]

**`tests/Feature/Authorization/DroitsDuResponsableTest.php`** (N)
- `test_un_responsable_a_tous_les_droits_de_son_magasin_sans_case` : remises et prix, paramètres du magasin, clôture, fermer la caisse d'un vendeur, achats, stock ; vendre sans stock dès le lot A6.
- `test_un_responsable_n_a_aucun_droit_de_societe` : espace société en 403 ; aucune action de la société (identité, fiscalité, créer, fermer, rouvrir, céder un magasin, changer un SIRET, plateforme, journal d'audit, administrateurs, expert-comptable, responsables) ne passe, même appelée directement.
- `test_un_responsable_n_a_aucun_droit_dans_un_autre_magasin`
- `test_deux_responsables_du_meme_magasin_ont_les_memes_droits`
- `test_une_personne_responsable_de_deux_magasins_a_tous_les_droits_dans_les_deux`

**`tests/Feature/Authorization/AutorisationsDesRolesTest.php`** (A, depuis `SeedMagasinRolesActionTest` et `CreerMagasinActionTest`)
- `test_aucun_role_n_existe_et_le_proprietaire_est_implicite` (G)
- `test_le_proprietaire_a_implicitement_toutes_les_autorisations` (G)
- `test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_la_vente_sans_stock` (A)
- `test_le_modele_de_l_administrateur_n_a_pas_les_parametres_du_magasin` (A)
- `test_il_n_existe_plus_de_role_comptable_de_magasin` (N) [C10] : `RoleMagasin` n'a que `Responsable`, `Vendeur`, `Observateur`, et `RoleMagasin::tryFrom('comptable')` est nul ; la base refuse ce rôle (`GardesDesDroitsEnBaseTest::test_la_base_refuse_un_role_de_magasin_inconnu`)
- les tests des modèles et de la clôture (G, pour vendeur et observateur)

**`tests/Feature/Authorization/MembrePolicyTest.php`** (A, depuis `MembreEquipePolicyTest`) et **`AffectationPolicyTest.php`** (N)
- `MembrePolicyTest::test_le_proprietaire_voit_et_gere_tous_les_comptes_sauf_le_sien`
- `MembrePolicyTest::test_un_administrateur_autorise_ne_voit_que_les_responsables_de_sa_portee`
- `MembrePolicyTest::test_seul_le_proprietaire_voit_et_gere_l_expert_comptable`
- `AffectationPolicyTest::test_seuls_le_proprietaire_et_un_responsable_du_magasin_actif_gerent_ses_affectations`
- `AffectationPolicyTest::test_une_affectation_d_un_autre_magasin_n_est_jamais_accessible`
- `AffectationPolicyTest::test_un_vendeur_ou_un_administrateur_ne_voit_aucune_affectation_du_magasin` (policy, et adresse de la fiche en 403 ou 404) [C19]

**`tests/Feature/Authorization/PorteeDesDroitsTest.php`** (N) [RF 5.2 A 17, défauts 13 et 14]
- `test_un_responsable_retire_du_magasin_pendant_que_son_formulaire_est_ouvert_ne_cree_ni_ne_coche_plus_rien` (SQLite déterministe)
- `test_un_administrateur_prive_de_nommer_les_responsables_pendant_que_son_formulaire_est_ouvert_n_en_nomme_plus` (SQLite déterministe)
- `test_un_auteur_suspendu_pendant_sa_requete_ne_modifie_aucun_compte`
- `test_un_compte_au_mot_de_passe_provisoire_ne_recoit_de_nouveaux_droits_que_de_son_auteur` [G14, C12]
- `test_les_regles_de_la_matrice_sont_respectees_geste_par_geste` : une donnée par case des matrices A à J de 2.3, « Oui » et « Non » ; c'est le test de référence de la matrice.

**`tests/Feature/Societe/MembresTest.php`** (G : les 14 de la conception)
- `test_le_proprietaire_cree_un_vendeur_affecte_a_deux_magasins_avec_un_mot_de_passe_provisoire`
- `test_le_membre_change_son_mot_de_passe_provisoire_a_sa_premiere_connexion_avant_toute_page`
- `test_une_requete_livewire_est_refusee_tant_que_le_mot_de_passe_est_provisoire`
- `test_un_mot_de_passe_provisoire_expire_apres_72_heures`
- `test_un_mot_de_passe_oublie_reussi_efface_le_mot_de_passe_provisoire` (désormais par la page du lien, qui pose `email_verified_at`)
- `test_le_mot_de_passe_provisoire_n_est_jamais_journalise_ni_envoye_par_e_mail`
- `test_une_adresse_deja_utilisee_recoit_une_reponse_neutre_et_aucun_compte_n_est_touche` (aussi pour un responsable et pour l'expert-comptable)
- `test_l_adresse_d_un_nouveau_membre_s_enregistre_en_minuscules_sans_espaces`
- `test_un_mot_de_passe_provisoire_trop_court_est_refuse`
- `test_la_creation_inscrit_les_droits_dans_chaque_magasin_en_un_evenement`
- `test_un_magasin_d_une_autre_societe_ne_s_affecte_jamais` (au niveau de l'action, défaut 1)
- `test_aucune_creation_ni_modification_ne_donne_la_propriete`
- `test_creer_un_compte_suspendu_de_la_societe_propose_de_le_reintegrer`
- `test_le_vingt_et_unieme_compte_du_jour_est_refuse` (les créations des responsables et celle de l'expert-comptable comptent)
- (N) `test_un_compte_tout_juste_cree_repond_sans_erreur_et_comme_relu` (défaut 8)
- (N) `test_un_compte_cree_par_la_societe_n_a_pas_d_adresse_confirmee` (défaut 21)
- (N) `test_le_membre_cree_par_l_aide_de_test_agit_et_n_a_pas_d_adresse_confirmee` [C16]

**`tests/Feature/Societe/MotDePasseProvisoireTest.php`** (G, A)
- (A) `test_le_mot_de_passe_provisoire_ne_se_donne_qu_a_un_compte_jamais_connecte_que_l_on_domine` devient `test_le_mot_de_passe_provisoire_ne_se_redonne_que_tant_que_le_mot_de_passe_est_provisoire_et_par_qui_a_tous_les_droits_du_compte`
- (A) `test_reinitialiser_un_membre_deja_connecte_lui_envoie_un_lien_et_l_auteur_ne_connait_pas_le_mot_de_passe` devient `test_un_compte_au_mot_de_passe_definitif_recoit_un_lien_et_l_auteur_ne_connait_pas_son_mot_de_passe`
- (G) `test_le_membre_est_prevenu_de_toute_reinitialisation`
- (G) `test_personne_ne_se_donne_un_mot_de_passe_provisoire_depuis_les_membres` (dans les deux panneaux)
- (G) `test_jamais_pour_le_proprietaire_ni_pour_un_administrateur_sauf_par_le_proprietaire` (et pour l'expert-comptable, par le propriétaire seul)
- (G) `test_donner_un_mot_de_passe_provisoire_coupe_le_se_souvenir_de_moi` (et supprime les sessions)
- (N) `test_l_auteur_ne_redonne_pas_de_mot_de_passe_provisoire_apres_un_mot_de_passe_oublie` (défaut 12)
- (N) `test_le_responsable_d_un_seul_des_magasins_d_un_vendeur_ne_renouvelle_pas_son_acces`
- (N) `test_un_administrateur_autorise_renouvelle_par_un_lien_l_acces_d_un_responsable_de_ses_seuls_magasins`
- (N) `test_un_administrateur_autorise_ne_redonne_jamais_de_mot_de_passe_provisoire_a_un_responsable` [C12]

**`tests/Feature/Societe/AutorisationsDesMembresTest.php`** (G, A)
- (G) `test_le_proprietaire_coche_les_autorisations_magasin_par_magasin`
- (A) `test_un_administrateur_ne_coche_que_ce_qu_il_a_dans_ce_magasin` devient `test_un_responsable_coche_les_autorisations_d_un_vendeur_dans_son_seul_magasin`
- (N) `test_un_administrateur_ne_coche_jamais_les_autorisations_d_un_vendeur_ou_d_un_observateur`
- (G) `test_un_simple_membre_ne_coche_rien`
- (G) `test_une_autorisation_inconnue_est_refusee_sans_rien_changer`
- (G) `test_un_administrateur_ne_touche_ni_au_proprietaire_ni_a_un_administrateur_ni_a_lui_meme` (défaut 7)
- (A) `test_un_administrateur_ne_modifie_pas_un_membre_plus_autorise_que_lui_mais_peut_couper_son_acces_dans_sa_portee` devient `test_un_administrateur_ne_modifie_ne_suspend_ni_ne_retire_aucun_vendeur_ou_observateur`
- (A) `test_un_membre_sans_affectation_ne_se_gere_que_par_le_proprietaire_ou_un_administrateur_global` devient `test_retirer_un_compte_de_son_dernier_magasin_le_retire_de_la_societe` et `test_aucun_membre_actif_ou_suspendu_n_est_jamais_sans_magasin` [I-7]
- (A) `test_un_administrateur_ne_reintegre_pas_un_compte_suspendu_par_le_proprietaire` devient `test_un_responsable_ne_reactive_pas_un_compte_suspendu_par_le_proprietaire`
- (G) `test_le_nom_et_l_adresse_d_un_autre_compte_ne_se_modifient_jamais`
- (G) `test_role_societe_statut_et_autorisations_ne_se_remplissent_jamais_par_masse`, étendu au `role` d'une affectation
- (G) `test_retirer_d_un_magasin_inscrit_les_droits_perdus_dans_ce_seul_magasin`
- (G) `test_suspendre_un_compte_inscrit_le_jet_130_dans_chacun_de_ses_magasins`
- (G) `test_retirer_de_la_societe_garde_le_compte_coupe_tout_acces_et_efface_ses_notifications`
- (G) `test_un_compte_retire_ne_libere_jamais_son_adresse`
- (G) `test_le_role_ne_fait_que_precocher` (vendeur, observateur)
- (G) `test_une_modification_s_inscrit_en_un_seul_evenement_avec_les_droits_avant_et_apres_relus_en_base`
- (G) `test_les_actions_des_membres_refusent_un_auteur_ou_un_compte_d_une_autre_societe` (défaut 2)
- (G) `test_suspendre_ou_retirer_renouvelle_le_se_souvenir_de_moi` (et supprime les sessions)

**`tests/Feature/Societe/AdministrateursTest.php`** (G, A)
- (G) `test_le_proprietaire_cree_un_administrateur_avec_sa_portee_et_ses_autorisations_en_une_fois`
- (G) `test_aucune_portee_n_est_preselectionnee`
- (G) `test_un_administrateur_de_tous_les_magasins_a_ses_autorisations_dans_un_magasin_cree_apres_lui`
- (G) `test_un_administrateur_d_une_selection_n_a_rien_hors_de_sa_selection`
- (G) `test_un_administrateur_d_une_selection_n_obtient_rien_dans_un_magasin_cree_apres_lui`
- (G) `test_un_administrateur_d_une_selection_ne_cree_pas_de_magasin_et_ne_coche_pas_les_autorisations_de_toute_la_societe`
- (A) `test_un_administrateur_d_une_selection_ne_voit_et_ne_gere_que_les_membres_de_ses_magasins` : remplacé par les tests de `ResponsablesTest` (6.3)
- (G) `test_nommer_administrateur_supprime_les_affectations_avec_un_jet_130_par_magasin`
- (G) `test_modifier_un_administrateur_global_inscrit_le_jet_130_dans_tous_les_magasins`
- (G) `test_retrograder_un_administrateur_exige_ses_affectations`
- (G) `test_retirer_un_administrateur_le_repasse_membre_sans_autorisation_de_societe`
- (G) `test_un_administrateur_suspendu_n_ouvre_plus_rien`
- (G) `test_un_administrateur_ne_nomme_ni_ne_cree_ni_ne_modifie_un_administrateur`
- (G) `test_le_proprietaire_n_a_jamais_d_affectation` (défaut 9)
- (G) `test_seul_un_administrateur_est_affecte_a_tous_les_magasins` (défaut 9)
- (G) `test_une_affectation_globale_ou_une_autorisation_de_societe_restee_a_un_membre_ne_donne_rien` (défaut 15)
- (G) `test_retrograder_un_administrateur_ne_lui_laisse_aucun_droit_dans_un_magasin_cree_ensuite` (défaut 15)
- (N) `test_un_administrateur_a_tous_les_magasins_ou_une_selection_jamais_les_deux`
- (N) `test_une_ligne_globale_restee_a_un_administrateur_d_une_selection_ne_donne_rien` (lignes écrites après `retirerLeDeclencheur()`) [C17, défaut 27]
- (N) `test_le_modele_d_un_nouvel_administrateur_precoche_gerer_les_magasins_et_pas_nommer_les_responsables` [I-10]

**`tests/Feature/Societe/ResponsablesTest.php`** (N)
- `test_le_proprietaire_nomme_plusieurs_responsables_dans_un_magasin_et_une_personne_responsable_de_deux_magasins`
- `test_un_administrateur_sans_l_autorisation_ne_nomme_aucun_responsable` : même avec tous les magasins et toutes les cases, par l'écran, par une requête forgée et par l'action.
- `test_un_administrateur_sans_l_autorisation_ne_voit_aucun_compte`
- `test_un_administrateur_autorise_nomme_retire_et_change_les_magasins_des_responsables_de_ses_seuls_magasins`
- `test_un_administrateur_autorise_d_une_selection_ne_nomme_aucun_responsable_hors_de_sa_selection` (défaut 23)
- `test_un_magasin_hors_de_la_portee_force_dans_la_nomination_d_un_responsable_est_refuse` (ancien `…_force_dans_le_formulaire_d_un_membre_est_refuse`, [RF 5.2 A 8])
- `test_un_administrateur_autorise_ne_voit_et_ne_nomme_que_les_responsables_de_ses_magasins`
- `test_un_administrateur_autorise_ne_nomme_jamais_le_proprietaire_ni_un_administrateur_ni_l_expert_comptable`
- `test_un_administrateur_autorise_ne_nomme_un_compte_existant_que_s_il_a_choisi_son_mot_de_passe_ou_s_il_l_a_cree` [C12, G14]
- `test_le_proprietaire_est_prevenu_de_chaque_responsable_nomme_par_un_administrateur` [C12, I-4]
- `test_un_administrateur_autorise_ne_retire_d_un_responsable_que_les_magasins_de_sa_portee`
- `test_un_administrateur_ne_suspend_ni_ne_reactive_ni_ne_retrograde_un_responsable` [I-4]
- `test_retirer_le_dernier_magasin_d_un_responsable_le_retire_de_la_societe` [G8]
- `test_seul_le_proprietaire_retrograde_un_responsable_en_vendeur` [I-4]
- `test_promouvoir_un_vendeur_responsable_efface_ses_cases_dans_ce_magasin`
- `test_nommer_un_responsable_s_inscrit_au_jet_130_et_au_journal_d_audit`
- `test_l_autorisation_de_nommer_reste_permise_dans_une_societe_suspendue_mais_pas_revoquee` [G12]

**`tests/Feature/Filament/EquipeDuMagasinTest.php`** (N)
- `test_un_responsable_cree_un_vendeur_et_un_observateur_de_son_magasin_avec_leurs_autorisations`
- `test_un_administrateur_ne_cree_jamais_un_vendeur_ou_un_observateur` : même avec tous les magasins et toutes les cases ; écran, requête forgée, action.
- `test_un_responsable_ne_cree_ni_ne_touche_un_autre_responsable_ni_un_administrateur_ni_le_proprietaire` (défaut 7) : aucun geste sur une affectation de responsable, ni sur tout le compte d'un responsable, d'un administrateur ou du propriétaire, ni par l'écran, ni par une requête forgée, ni par l'action.
- `test_un_responsable_ne_cree_ni_ne_touche_l_expert_comptable` [C10] : écran, requête forgée, action.
- `test_un_responsable_du_magasin_a_ne_gere_rien_dans_le_magasin_b` : adresse en 404, identifiant d'affectation forgé, appel direct de l'action.
- `test_deux_responsables_du_meme_magasin_ne_se_touchent_pas`
- `test_le_responsable_d_un_seul_des_magasins_d_un_vendeur_ne_gere_que_son_affectation` (cases et retrait dans son magasin ; ni suspension, ni retrait de la société, ni renouvellement)
- `test_le_responsable_de_tous_les_magasins_d_un_vendeur_suspend_reactive_retire_et_renouvelle_son_acces`
- `test_un_responsable_gere_l_affectation_de_vendeur_d_un_compte_responsable_ailleurs_sans_toucher_au_reste` [I-5]
- `test_un_responsable_ne_modifie_ni_son_affectation_ni_son_statut`
- `test_un_vendeur_qui_a_toutes_les_cases_ne_gere_aucun_compte` (défaut 3)
- `test_retirer_un_vendeur_de_son_dernier_magasin_le_retire_de_la_societe_apres_confirmation`
- `test_un_responsable_ajoute_a_son_magasin_un_compte_actif_de_la_societe_qui_a_choisi_son_mot_de_passe` [I-18, G14]
- `test_ajouter_par_adresse_repond_pareil_pour_une_adresse_inconnue_d_une_autre_societe_suspendue_retiree_refusee_au_provisoire_d_administrateur_d_expert_comptable_ou_du_proprietaire` : même réponse, aucune écriture, aucune erreur de clé [C21]
- `test_l_equipe_d_un_magasin_ne_dit_jamais_dans_quels_autres_magasins_travaille_un_membre` [I-9]
- `test_l_equipe_reste_geree_dans_un_magasin_ferme_ou_une_societe_suspendue_mais_pas_dans_une_societe_revoquee` [G12], qui remplace `MagasinEnLectureSeuleTest::test_l_equipe_reste_consultable_et_ouverte_aux_nouveaux_comptes` et `test_un_acces_se_coupe_toujours` (6.4)
- `test_l_ecran_de_l_equipe_ne_liste_que_les_affectations_de_membre_du_magasin_actif` (ni les administrateurs ni l'expert-comptable)

**`tests/Feature/Societe/RefusDUnCompteTest.php`** (N) [RF 6.6]
- `test_un_compte_cree_par_la_societe_se_refuse_a_la_connexion_avec_le_mot_de_passe_provisoire`
- `test_une_connexion_interrompue_ne_ferme_pas_le_droit_de_refus`
- `test_le_titulaire_de_l_adresse_refuse_par_mot_de_passe_oublie_meme_apres_l_expiration_du_provisoire`
- `test_le_titulaire_de_l_adresse_refuse_un_compte_deja_active_avec_le_mot_de_passe_provisoire`
- `test_changer_le_mot_de_passe_provisoire_ne_confirme_pas_l_adresse`
- `test_un_compte_dont_l_adresse_est_confirmee_ne_se_refuse_plus`
- `test_le_proprietaire_ne_refuse_jamais_son_compte_meme_apres_un_transfert`
- `test_un_compte_suspendu_ou_sans_magasin_jamais_confirme_se_refuse_par_le_lien`
- `test_un_compte_retire_par_la_societe_jamais_confirme_se_refuse_et_ne_se_reintegre_plus_sans_son_accord` [I-14]
- `test_un_compte_refuse_est_retire_avec_un_jet_130_par_magasin_un_audit_et_son_auteur_prevenu`
- `test_l_auteur_parti_de_la_societe_n_est_pas_prevenu_et_le_proprietaire_l_est` [I-15]
- `test_un_administrateur_qui_refuse_son_compte_perd_ses_autorisations_de_societe`
- `test_un_responsable_qui_refuse_son_compte_perd_ses_magasins`
- `test_la_societe_ne_reintegre_pas_un_compte_refuse`
- (A) `test_un_compte_refuse_sans_adresse_confirmee_ne_cree_sa_societe_qu_apres_un_lien` devient `test_un_compte_refuse_ne_cree_sa_societe_qu_apres_avoir_choisi_son_mot_de_passe_par_un_lien` [C11]
- `test_un_rattachement_accepte_confirme_l_adresse_et_ferme_le_droit_de_refus`
- `test_personne_ne_refuse_le_compte_d_un_autre_par_une_requete_forgee`
- (N) `test_apres_un_refus_l_ancien_mot_de_passe_et_les_sessions_ouvertes_n_ouvrent_plus_rien` [C11]
- (N) `test_seul_le_titulaire_du_lien_cree_sa_societe_avec_un_compte_refuse` : l'auteur, qui connaît l'ancien mot de passe ou garde une session ouverte, est refusé [C11]
- Les deux tests de [RF 6.6] qui portent sur d'autres sujets sont écrits une seule fois, ailleurs : `test_l_auteur_ne_redonne_pas_de_mot_de_passe_provisoire_apres_un_mot_de_passe_oublie` dans `MotDePasseProvisoireTest`, et `test_le_proprietaire_inscrit_par_son_lien_peut_agir_des_son_inscription` dans `InscriptionSocieteTest`.

**`tests/Feature/Societe/PageDuLienTest.php`** (N) [RF 5.2 A 21, défaut 20]
- (A) `test_la_page_de_lien_n_ouvre_aucune_session_et_ne_change_pas_le_mot_de_passe_d_un_compte_suspendu` devient `test_la_page_de_lien_n_ouvre_aucune_session_et_ne_laisse_pas_un_compte_suspendu_choisir_son_mot_de_passe` : un refus rend désormais le mot de passe inutilisable, ce que l'ancien nom interdisait [C11]
- `test_accepter_sur_la_page_de_lien_ne_leve_jamais_une_suspension`
- `test_la_page_de_lien_repond_pareil_quel_que_soit_l_etat_du_compte`
- `test_un_jeton_rejoue_ou_d_un_autre_compte_est_refuse_sur_la_page_de_lien`
- (N) `test_un_compte_retire_choisit_son_mot_de_passe_sur_la_page_de_lien_sans_ouvrir_aucun_panneau` [C11]
- (N) `test_toute_decision_sur_la_page_de_lien_supprime_les_sessions_du_compte` [C11]

**`tests/Feature/Societe/RefusDUnCompteConcurrenceTest.php`** (N, groupe `concurrence`)
- `test_un_refus_et_une_vente_simultanes_du_meme_compte_ne_laissent_jamais_passer_une_vente_apres_le_refus`, avec sa version SQLite déterministe (défaut 19)
- `test_un_refus_et_une_modification_simultanee_des_droits_se_suivent_sans_laisser_de_droits`

**`tests/Feature/Societe/DroitsConcurrenceTest.php`** (N, groupe `concurrence` ; reprend les tests de la conception 4.9)
- (A) `test_deux_administrateurs_modifiant_le_meme_membre_se_suivent_et_le_jet_130_reste_coherent` devient `test_deux_responsables_modifiant_le_meme_vendeur_se_suivent_et_le_jet_130_reste_coherent`
- (G) `test_un_retrait_d_autorisation_et_une_emission_simultanes_dans_le_meme_magasin_ne_laissent_jamais_passer_une_vente_non_autorisee`, étendu par une donnée au paiement, à l'avoir, à la contre-passation, à la clôture manuelle et à la caisse [RF 5.2 A 18, C13]
- (G) `test_deux_creations_simultanees_avec_la_meme_adresse_n_en_retiennent_qu_une`
- (G) `test_deux_rattachements_acceptes_en_meme_temps_n_en_retiennent_qu_un`
- (N) `test_un_retrait_des_droits_d_un_responsable_et_sa_modification_d_un_vendeur_simultanes_ne_donnent_jamais_un_droit_retire` (défaut 13)
- (N) `test_un_retrait_de_l_autorisation_de_nommer_et_une_nomination_simultanees_ne_nomment_jamais_apres_le_retrait`
- (N) `test_un_retrait_de_valider_et_une_validation_d_achat_simultanes_ne_valident_jamais_apres_le_retrait` [C15]
- (lot A6) `test_deux_ventes_sans_stock_dans_deux_magasins_ne_se_bloquent_pas_sur_la_societe`

**`tests/Feature/Societe/RattachementTest.php`** (G : les 11)
- (A) `test_l_acceptation_apres_la_retrogradation_de_l_auteur_est_refusee` devient `test_l_acceptation_apres_la_perte_de_ses_droits_par_l_auteur_est_refusee` : responsable retiré du magasin, A+ privé de son autorisation, administrateur rétrogradé.
- (N) `test_un_responsable_ne_propose_que_des_affectations_de_vendeur_ou_d_observateur_de_ses_magasins`
- (N) `test_un_compte_refuse_accepte_un_rattachement_apres_avoir_choisi_son_mot_de_passe_par_le_lien` [C11]
- (N) `test_l_acceptation_redemande_le_mot_de_passe_et_une_session_ancienne_ne_suffit_pas` [C11]

**`tests/Feature/Societe/TransfererProprieteTest.php`** (G : les 4)
- (G) `test_un_administrateur_ne_transmet_pas_la_propriete`
- (G) `test_la_propriete_ne_va_ni_a_un_compte_d_une_autre_societe_ni_a_un_compte_suspendu_ni_a_un_simple_membre`
- (N) `test_le_nouveau_proprietaire_perd_ses_affectations_et_l_ancien_devient_administrateur_de_tous_les_magasins`
- (N) `test_un_transfert_force_par_une_requete_livewire_d_un_administrateur_ne_change_rien` [SC K1, C22]
- (N) `test_la_propriete_ne_va_jamais_a_l_expert_comptable`

**`tests/Feature/Societe/PanneauSocieteTest.php`** (G : les 7 de la conception)
- (A) `test_un_membre_n_ouvre_pas_l_espace_societe` devient `test_ni_un_membre_ni_l_expert_comptable_n_ouvrent_l_espace_societe`, paramétré : responsable, vendeur, observateur, expert-comptable.

**`tests/Feature/Societe/InscriptionSocieteTest.php`** (G, complété)
- (N) `test_le_proprietaire_inscrit_par_son_lien_peut_agir_des_son_inscription`

**`tests/Feature/Filament/ModifierProfilTest.php`** (G)
- `test_une_nouvelle_adresse_ne_prend_effet_qu_apres_confirmation_et_l_ancienne_est_prevenue` ; `test_un_lien_de_confirmation_expire_ou_rejoue_est_refuse`
- (N) `test_confirmer_une_nouvelle_adresse_pose_l_adresse_confirmee`

**`tests/Feature/Filament/ActionsForgeesTest.php`** (N) [RF 5.2 A 3 et 4]
- `test_une_cle_d_un_autre_magasin_ou_d_un_collegue_dans_une_action_de_table_ou_de_masse_ne_monte_rien` (ajouter les actions de `EquipeDuMagasinResource`)
- `test_une_action_d_en_tete_masquee_appelee_directement_ne_fait_rien`, **paramétré par page** [SC C3, C22] : émettre une facture, envoyer un document, clôturer la journée, corriger le SIREN, ouvrir la démonstration, transférer la propriété, créer un vendeur (par un vendeur), nommer un responsable (par un A−), créer l'expert-comptable (par un administrateur). Fermer et rouvrir un magasin s'ajoutent au lot A5, avec leurs actions.

**`tests/Feature/Filament/PanneauxTest.php`** (N)
- `test_aucune_ressource_n_est_ouverte_a_la_recherche_globale` (les deux panneaux, `EquipeDuMagasinResource` comprise)

**`tests/Feature/Filament/EnregistrementFiscalResourceTest.php`** (G, complété)
- `test_l_export_du_journal_ne_contient_aucun_evenement_d_un_autre_magasin_de_la_societe` [RF 5.2 A 5]
- `test_seul_le_proprietaire_lit_l_adresse_ip_et_le_navigateur_d_un_evenement` (G), étendu à l'expert-comptable [I-22]

**`tests/Feature/Tenancy/IsolationDesSocietesTest.php`** (G, complété)
- `test_l_url_d_un_enregistrement_d_un_autre_magasin_de_la_societe_repond_404_meme_pour_un_compte_autorise_dans_les_deux`, avec trois comptes : le propriétaire, un responsable des deux magasins, et l'expert-comptable
- les 14, 2 et 5 tests de la conception 2.5 (G)
- (N) `test_l_ecran_de_l_equipe_contourne_explicitement_l_absence_de_scope_et_filtre_par_magasin`
- (N) `test_ajouter_un_compte_par_son_adresse_ne_lit_ni_n_affiche_rien_d_autre_que_le_succes_ou_la_reponse_neutre` : aucune autre donnée du compte ni de ses autres magasins n'est lue ni affichée ; une adresse d'une autre société reçoit la même réponse neutre [C5]

**`tests/Feature/Tenancy/TracabiliteDesVentesTest.php`** (G : les 6) et **`TracabiliteDesVentesConcurrenceTest.php`** (N)
- `test_deux_premieres_ventes_simultanees_du_meme_vendeur_dans_a_et_b_n_en_laissent_passer_qu_une`, avec sa version SQLite déterministe (défaut 18)
- (N) `test_un_responsable_de_deux_magasins_n_encaisse_que_dans_un_a_la_fois`

**`tests/Feature/Tenancy/SelecteurDeMagasinTest.php`** (G : les 3)
- (N) `test_un_responsable_de_deux_magasins_voit_les_deux`
- (N) `test_l_expert_comptable_voit_tous_les_magasins_reels_et_pas_la_demonstration`

**`tests/Feature/Tenancy/AccesAuxPanneauxTest.php`** (G, complété)
- (N) `test_un_responsable_ouvre_ses_magasins_et_pas_l_espace_societe`
- (N) `test_l_expert_comptable_ouvre_les_magasins_reels_et_pas_l_espace_societe`

**`tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php`** (N) [RF 5.2 A 23, I-16]
- `test_les_ecrans_de_l_historique_affichent_le_nom_tel_qu_il_etait_au_moment_de_l_operation` : vente, paiement, clôture personnelle « Fermée par », preuve, envoi, puis la personne change son nom dans son profil.
- `test_un_compte_refuse_ou_repris_ne_change_pas_le_nom_affiche_dans_l_historique_de_la_societe` : le contenu signé et le journal d'audit sont aussi inchangés.
- `test_chaque_colonne_qui_designe_une_personne_dans_l_historique_a_son_nom_fige` : méta-test sur la liste de 3.6.
- `test_le_nom_fige_ne_se_modifie_jamais_apres_coup`

**`tests/Feature/Facturation/EnvoyerDocumentJobTest.php`** (G, complété) [RF 5.2 E, I-17]
- `test_un_envoi_demande_par_un_membre_retire_ou_suspendu_entre_temps_ne_part_pas_et_le_proprietaire_est_prevenu`, avec une donnée par cas : retiré du magasin, suspendu, retiré de la société, refusé, privé de l'autorisation d'envoyer, facture devenue invisible pour lui.
- `test_une_relance_automatique_ne_depend_d_aucun_auteur`

**`tests/Feature/Stock/StockLedgerServiceTest.php`** (G, complété)
- `test_l_alerte_de_stock_va_au_proprietaire_et_aux_personnes_autorisees_du_magasin` (G)
- (N) `test_l_alerte_de_stock_va_aux_responsables_du_magasin_sans_case` (défaut 24)
- (N) `test_l_alerte_de_stock_ne_va_ni_a_un_compte_suspendu_ni_retire_ni_a_un_administrateur_hors_de_sa_selection_ni_a_l_expert_comptable` [SC I1]

**Autres tests de la conception, gardés** : `JournalEvenementsTest` (2), `test_l_echec_d_un_envoi_d_un_administrateur_le_previent_lui`, `test_toute_notification_porte_sa_societe`, `MagasinEnLectureSeuleTest` paramétré (6.4).

### 6.3 Tests du plan retirés ou remplacés

| Test du plan | Pourquoi | Remplacé par |
|---|---|---|
| `AdministrateursTest::test_un_administrateur_d_une_selection_ne_voit_et_ne_gere_que_les_membres_de_ses_magasins` | les administrateurs ne gèrent plus les membres [É-R] | `ResponsablesTest::test_un_administrateur_autorise_ne_voit_et_ne_nomme_que_les_responsables_de_ses_magasins` |
| `AdministrateursTest::test_un_administrateur_sans_gerer_les_membres_ne_voit_ni_ne_gere_aucun_membre` [RF 5.2 A 9] | `societe.membres.gerer` disparaît | `ResponsablesTest::test_un_administrateur_sans_l_autorisation_ne_nomme_aucun_responsable` et `ResponsablesTest::test_un_administrateur_sans_l_autorisation_ne_voit_aucun_compte` |
| `AdministrateursTest::test_un_magasin_de_la_societe_hors_de_la_selection_force_dans_le_formulaire_d_un_membre_est_refuse` [RF 5.2 A 8] | idem | `ResponsablesTest::test_un_magasin_hors_de_la_portee_force_dans_la_nomination_d_un_responsable_est_refuse` |
| `AutorisationsDesMembresTest::test_un_administrateur_ne_coche_que_ce_qu_il_a_dans_ce_magasin` | idem | `AutorisationsDesMembresTest::test_un_responsable_coche_les_autorisations_d_un_vendeur_dans_son_seul_magasin` et `AutorisationsDesMembresTest::test_un_administrateur_ne_coche_jamais_les_autorisations_d_un_vendeur_ou_d_un_observateur` |
| `AutorisationsDesMembresTest::test_un_administrateur_ne_modifie_pas_un_membre_plus_autorise_que_lui_mais_peut_couper_son_acces_dans_sa_portee` | idem | `AutorisationsDesMembresTest::test_un_administrateur_ne_modifie_ne_suspend_ni_ne_retire_aucun_vendeur_ou_observateur` |
| `AutorisationsDesMembresTest::test_un_membre_sans_affectation_ne_se_gere_que_par_le_proprietaire_ou_un_administrateur_global` | plus de membre actif sans magasin [I-7] | `test_retirer_un_compte_de_son_dernier_magasin_le_retire_de_la_societe`, `test_aucun_membre_actif_ou_suspendu_n_est_jamais_sans_magasin` |
| `AutorisationsDesMembresTest::test_un_administrateur_ne_reintegre_pas_un_compte_suspendu_par_le_proprietaire` | idem | `test_un_responsable_ne_reactive_pas_un_compte_suspendu_par_le_proprietaire` |
| `PorteeDesDroitsTest::test_un_administrateur_prive_d_une_autorisation_pendant_que_son_formulaire_est_ouvert_ne_la_coche_plus` [RF 5.2 A 17] | un administrateur ne coche plus de cases de membre | les deux tests de `PorteeDesDroitsTest` du défaut 13 |
| `test_deux_administrateurs_modifiant_le_meme_membre_se_suivent_et_le_jet_130_reste_coherent` | idem | `test_deux_responsables_modifiant_le_meme_vendeur_se_suivent_et_le_jet_130_reste_coherent` |
| `MotDePasseProvisoireTest::test_le_mot_de_passe_provisoire_ne_se_donne_qu_a_un_compte_jamais_connecte_que_l_on_domine` | le critère « jamais connecté » est faux [E2] | le nom adapté de 6.2 |
| `AutorisationsDesRolesTest::test_le_responsable_recoit_d_office_tout_sauf_les_parametres_et_la_vente_sans_stock` | le responsable n'a plus de modèle : il a tout [É-droits] | `DroitsDuResponsableTest::test_un_responsable_a_tous_les_droits_de_son_magasin_sans_case` et `AutorisationsDesRolesTest::test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_la_vente_sans_stock` |
| `AutorisationsDesRolesTest` : tests du modèle et de la clôture **pour le comptable** | le rôle disparaît [É-EC-rôle] | `AutorisationsDesRolesTest::test_il_n_existe_plus_de_role_comptable_de_magasin` et `ExpertComptableTest` ; la lecture reste couverte, les écritures du comptable deviennent des refus (plus strict) |
| `RefusDUnCompteTest::test_un_compte_refuse_sans_adresse_confirmee_ne_cree_sa_societe_qu_apres_un_lien` [RF 6.6] | un lien suivi ne suffit plus : il faut y choisir son mot de passe [C11, I-28] | `test_un_compte_refuse_ne_cree_sa_societe_qu_apres_avoir_choisi_son_mot_de_passe_par_un_lien` (plus strict) |
| `PageDuLienTest::test_la_page_de_lien_n_ouvre_aucune_session_et_ne_change_pas_le_mot_de_passe_d_un_compte_suspendu` [RF 5.2 A 21] | un refus rend désormais le mot de passe inutilisable [C11] | `test_la_page_de_lien_n_ouvre_aucune_session_et_ne_laisse_pas_un_compte_suspendu_choisir_son_mot_de_passe` (même garantie pour le choix du mot de passe et la session) |
| `PanneauSocieteTest::test_un_membre_n_ouvre_pas_l_espace_societe` | l'expert-comptable s'ajoute | `test_ni_un_membre_ni_l_expert_comptable_n_ouvrent_l_espace_societe` |
| Partie D, option a de `recommandation-finale.md` (Spatie gardé) | Spatie est retiré [É-Spatie] | option b, dans `ReferentielDesAutorisationsTest` |

### 6.4 Annexe B de la conception : corrections

- **`CreerMagasinActionTest`** : la destination de `test_le_proprietaire_a_les_parametres_du_magasin_mais_pas_ladministrateur` devient `AutorisationsDesRolesTest::test_le_modele_de_l_administrateur_n_a_pas_les_parametres_du_magasin`. La garantie est la même pour l'administrateur ; le responsable a désormais les paramètres [É-droits].
- **`SeedMagasinRolesActionTest`** : la destination de `test_l_administrateur_recoit_d_office_tout_sauf_les_parametres_du_magasin` devient `AutorisationsDesRolesTest::test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_la_vente_sans_stock`. Les données du comptable (lignes 59-61, 111, 149-151) suivent la règle des usages de `RoleMagasin::Comptable` ci-dessous.
- **`CreerMembreMagasinActionTest` et `MembreEquipeResourceTest`** :
  - Les destinations vers `test_un_administrateur_ne_coche_que_ce_qu_il_a_dans_ce_magasin` et `test_un_administrateur_ne_modifie_pas_un_membre_plus_autorise_que_lui_…` vont vers leurs remplaçants de 6.3, et vers `EquipeDuMagasinTest::test_un_vendeur_qui_a_toutes_les_cases_ne_gere_aucun_compte`.
  - `test_un_membre_qui_gere_l_equipe_n_ouvre_pas_sa_propre_ligne`, `test_le_proprietaire_napparait_pas_comme_modifiable_dans_la_liste` et `test_un_vendeur_sans_permission_ne_peut_pas_acceder_a_la_gestion_dequipe` vont vers `EquipeDuMagasinTest`, pour le responsable, et vers `MembreResource`, pour le propriétaire.
- **`MembreEquipePolicyTest`** vers `MembrePolicyTest` et `AffectationPolicyTest`, avec les règles du propriétaire et du responsable au lieu de `societe.membres.gerer`.
- **Les 42 usages de `RoleMagasin::Administrateur`** (modèle de magasin) dans 16 fichiers. La règle « devient `RoleMagasin::Responsable`, dont les cases sont identiques » n'est plus vraie : le responsable a désormais `parametres.gerer` et n'a plus de cases. Chaque usage devient `$this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur())`. C'est exactement l'ancienne liste de l'administrateur de magasin, hors `EQUIPE_*` : les assertions restent identiques. Seuls les tests qui portent sur le responsable lui-même passent à `RoleMagasin::Responsable`.
- **Les 41 usages de `RoleMagasin::Comptable`** dans 19 fichiers de tests [lu, recherche `git grep -n "RoleMagasin::Comptable" tests`] [C2] :
  1. un test qui vérifie une règle d'une policy pour un membre qui a certaines cases (`FacturePolicyTest`, `PaiementPolicyTest`, `AvoirPolicyTest`, `ClientPolicyTest`, `FournisseurPolicyTest`, `ProduitPolicyTest`, `ServicePolicyTest`, `DevisPolicyTest`, `ClotureCaissePolicyTest`, `ClotureCaissePersonnellePolicyTest`, `ClientResourceTest`, `ClotureCaisseResourceTest`, `PreuvesOperationTest`, `BordereauDetaxeTest`, `EnregistrementFiscalResourceTest`, `JournalEvenementsTest`) devient `$this->membre($magasin, RoleMagasin::Vendeur, [...])`, avec la liste des cases de l'ancien modèle du comptable écrite dans le test : les assertions restent identiques ;
  2. quand le test porte sur ce que voit un comptable (factures de toute l'équipe, registre, clôtures), il reçoit en plus une donnée pour l'expert-comptable (`expertComptable()`), avec les mêmes assertions de lecture, et un refus pour chaque écriture que le comptable avait ;
  3. les tests du modèle du comptable lui-même (`SeedMagasinRolesActionTest`, lignes 59-61, 111, 149-151 : « le comptable envoie les factures », « … les avoirs ») disparaissent avec le modèle, par décision de l'éditeur [É-EC-rôle] ; ils sont remplacés par `AutorisationsDesRolesTest::test_il_n_existe_plus_de_role_comptable_de_magasin` et par `ExpertComptableTest::test_l_expert_comptable_n_envoie_aucun_e_mail`, plus stricts ;
  4. les usages dans `CreerMembreMagasinActionTest` et `MembreEquipeResourceTest` suivent la destination de ces fichiers (ci-dessus), avec `RoleMagasin::Observateur` à la place du comptable quand le rôle n'est qu'un exemple de rôle de membre.
- **`MagasinEnLectureSeuleTest`** : `test_l_equipe_reste_consultable_et_ouverte_aux_nouveaux_comptes` et `test_un_acces_se_coupe_toujours` vont au niveau du magasin (`EquipeDuMagasinTest`, propriétaire et responsable) et de la société (propriétaire). La société révoquée est couverte par un test négatif [SC F3].
- **`StockLedgerServiceTest`** : les deux tests de l'alerte, plus les responsables (6.2).
- **`EnvoyerDocumentJobTest`** : plus le test de la revérification (6.2).
- **`AccesAuxPanneauxTest`**, **`SelecteurDeMagasinTest`** : plus le responsable et l'expert-comptable (6.2).

### 6.5 Tests qui portent sur des règles de HEAD contraires à celles de l'éditeur [RF 5.2 C]

Ils sont réécrits sans être affaiblis :
- `CreerMembreMagasinActionTest::test_reinitialiser_le_mot_de_passe_dun_membre` et `MembreEquipeResourceTest::test_le_proprietaire_donne_un_nouveau_mot_de_passe_a_un_vendeur` deviennent les deux tests de G11 de `MotDePasseProvisoireTest`.
- `CreerMembreMagasinActionTest::test_un_administrateur_donne_un_nouveau_mot_de_passe_a_un_membre_dont_il_a_toutes_les_autorisations` devient `MotDePasseProvisoireTest::test_le_responsable_d_un_seul_des_magasins_d_un_vendeur_ne_renouvelle_pas_son_acces` et `test_jamais_pour_le_proprietaire_ni_pour_un_administrateur_sauf_par_le_proprietaire`.
- `ModifierProfilTest::test_un_membre_change_son_nom_et_son_adresse_avec_son_mot_de_passe_actuel` : le nom change tout de suite, l'adresse après confirmation.
- `MembreEquipePolicyTest::test_avec_equipe_modifier_un_membre_modifie_et_retire_un_autre_membre` devient `AffectationPolicyTest::test_seuls_le_proprietaire_et_un_responsable_du_magasin_actif_gerent_ses_affectations`.
- Les tests du modèle du comptable (`SeedMagasinRolesActionTest`, lignes 149-151) encodent des envois que l'éditeur interdit désormais au seul compte qui lit la comptabilité [É-EC-accès] : remplacés comme dit en 6.4, point 3.

### 6.6 Critères de fin et relecture du lot A4 (remplacent ceux de la conception 14)

**Fin.**
- Ces recherches sont vides dans `app/`, `config/`, `database/`, `tests/`, `resources/`, `bootstrap/`, sauf `ReferentielDesAutorisationsTest.php`, qui nomme Spatie pour prouver son absence :
  - `git grep -n -E "EQUIPE_|MembreEquipe|MagasinUser|magasin_user|estProprietaireDe\(|RoleMagasin::(Proprietaire|Administrateur|Comptable)|case Comptable|checkPermissionTo|HasRoles|Spatie|spatie|permission\.php|SeedMagasinRolesAction|MagasinTeamResolver|MEMBRES_GERER|societe\.membres\.gerer|premiere_connexion_le|aAccepteSonCompte|->jusquAu\("`
- Vide dans `app/` et `resources/` :
  - `git grep -n -E "creePar\.name|creePar->name|notePar->name|fermeePar->name|vendeur\.name|vendeur->name|envoyePar->name"`
- Vide dans `app/Domain`, sauf `OperateurCourant`, `JournaliserFinDeSession` et les docblocks qui expliquent pourquoi un job ne lit pas `auth()` (`WithTenantContext`, `WithSocieteContext`) :
  - `git grep -n -E "cree_par \?\?= auth|auth\(\)->(id|user)\(\)"`
- Vide dans `app/Filament` [C13] :
  - `git grep -n -E "parLeSysteme\(|fermerDOffice\(|relanceAutomatique\(|FermerLesCaissesPersonnellesAction::class|ExpirerLesDevisAction::class|GenererSoumissionEReportingAction::class"`
- `composer.json` et `composer.lock` sans `spatie/laravel-permission`. Les paquets `spatie/color`, `spatie/invade` et `spatie/laravel-package-tools` du verrou sont des dépendances de Filament et restent ; la recherche ci-dessus ne lit pas `composer.lock`.
- `php artisan migrate:fresh --seed` passe sous les deux moteurs.
- Les deux suites complètes sortent avec le code 0, contrôlé par `echo $?` ; `vendor/bin/pint --dirty --format agent`.
- Le test de la matrice (`PorteeDesDroitsTest::test_les_regles_de_la_matrice_sont_respectees_geste_par_geste`) couvre chaque case des matrices A à J de 2.3.
- `CLAUDE.md` porte les textes de la section 1.

**Relecture adversariale, en plus de celle de la conception.**
- Un responsable crée un responsable, un administrateur ou l'expert-comptable par une requête forgée.
- Un A− nomme un responsable par Livewire ; un A+ nomme hors de sa portée.
- Un A+ crée, suspend ou retire l'expert-comptable, par l'écran, par une requête forgée ou par l'action.
- L'expert-comptable écrit par une action, une requête Livewire ou une ligne glissée en base ; il lit le journal d'audit ou les traces.
- Deux créations simultanées d'un expert-comptable dans la même société.
- Un A+ redonne un provisoire à un responsable ; un compte encore au provisoire reçoit un magasin d'une autre personne que son auteur.
- Un responsable de A agit dans B avec un identifiant d'affectation forgé.
- Deux co-responsables : l'un retire l'autre.
- Un responsable d'un seul des magasins suspend le compte.
- Retrait du dernier magasin.
- Ajout par adresse qui révèle une adresse d'une autre société.
- Revérification de l'e-mail contournée par une relance manuelle.
- Refus d'un compte retiré ou suspendu ; page du lien rejouée ; ancien mot de passe ou session ouverte utilisés après un refus pour créer une société ou accepter un rattachement.
- Nom figé contourné par un écran qui relit la relation.
- Autorisation inconnue écrite par `DB::table` ; clé du référentiel supprimée avec des cases.
- Gardes en base contournées sous l'un des moteurs ; administrateur à la fois global et d'une sélection.
- Opérateur signé différent de l'auteur contrôlé ; opération du système signée au nom de la personne connectée.
- Clôture manuelle passée par une entrée du système ; validation d'un achat après un retrait de « valider ».
- Mémoire de droits périmée dans une action.
- Ordre des verrous inversé (comptes avant registres, produits avant comptes).

---

## 7. Effets sur les lots suivants

### 7.1 Lot A5 : cycle de vie des magasins

- **Autorité inchangée** : `societe.magasins.gerer` dans la portée ; la cession reste au propriétaire.
  - `FermerMagasinAction`, `RouvrirMagasinAction`, `ChangerSiretAction` et `ModifierEtablissementAction` relisent leur auteur par `LecteurDesDroits::exigerDansLaSociete()` (4.4).
  - Leurs écritures au registre, JET 270 compris, se font dans `OperateurCourant::pendant($compteRelu, …)`.
  - `FermerMagasinAction` clôture les journées en attente par `CloturerLesJourneesAction::parLeSysteme()`, conséquence automatique de la fermeture, comme minuit, sans demander « clôturer » à son auteur ; les caisses personnelles sont ensuite fermées d'office (« fermée automatiquement »).
- **Le responsable n'agit jamais sur le cycle de vie** [É-droits] : il ne ferme, ne rouvre, ne cède ni ne change le SIRET de son magasin. L'expert-comptable non plus [É-EC-accès].
  - Test à ajouter : `FermerEtRouvrirMagasinTest::test_ni_un_responsable_ni_l_expert_comptable_ne_ferment_ne_rouvrent_ni_ne_cedent_un_magasin`.
  - Données à ajouter à `ActionsForgeesTest::test_une_action_d_en_tete_masquee_appelee_directement_ne_fait_rien` : fermer et rouvrir un magasin [C22].
  - Relecture à ajouter : « fermeture, réouverture ou changement de SIRET par un responsable ou l'expert-comptable, par écran forgé ou par l'action ».
- **Équipe d'un magasin fermé** : les affectations restent ; l'équipe reste gérable (G12). Aucune affectation n'est supprimée à la fermeture, à la cession ni à la réouverture. L'expert-comptable lit le magasin fermé ou cédé.
- **`CoordonneesMagasin`** (`PARAMETRES_GERER`) : le responsable y accède toujours.
  - `CoordonneesMagasinTest::test_le_responsable_avec_les_parametres_regle_l_e_mail_et_le_telephone_du_magasin` devient `test_le_responsable_regle_l_e_mail_et_le_telephone_du_magasin`.
  - On garde `test_un_vendeur_n_y_accede_pas`.
  - On ajoute `test_un_vendeur_a_qui_le_responsable_coche_les_parametres_y_accede`.
- **`MagasinDeLaSocietePolicy`** reçoit au lot A4 `nommerUnResponsable()`. Ses gestes du lot A5 restent ceux de la conception.

### 7.2 Lot A6 : R1, R2, R3

- **R1 (vente sans stock)** [I-3] :
  - `VerificateurDeStock` lit l'autorisation sur le compte relu par `exigerSousVerrou()` ;
  - le propriétaire et le responsable l'ont toujours ; l'expert-comptable jamais ;
  - elle n'est précochée dans aucun modèle de vendeur ou d'observateur, ni dans celui de l'administrateur (`PermissionRegistry::modeleDeLAdministrateur()` l'exclut) ;
  - avertissement, alerte et journalisation inchangés pour tous ;
  - tests à ajouter à `VenteSansStockTest` : `test_un_responsable_vend_sans_stock_apres_l_avertissement` ; `test_un_vendeur_a_qui_le_responsable_coche_vendre_sans_stock_vend_apres_l_avertissement` ;
  - `test_une_autorisation_retiree_entre_l_ecran_et_la_validation_refuse_la_vente` lit le compte relu.
- **R2 (remises et prix)** : texte de 1.3. `DerogationsDePrix` lit l'autorisation sur le compte relu.
  - `RemisesEtPrixTest::test_les_modeles_cochent_l_autorisation_pour_administrateurs_et_responsables_seulement` devient `test_le_proprietaire_et_le_responsable_ont_toujours_remises_et_prix_le_modele_de_l_administrateur_la_precoche_et_aucun_autre_modele`.
  - On ajoute `test_un_vendeur_a_qui_le_responsable_coche_remises_et_prix_accorde_une_remise`.
  - `test_un_responsable_accorde_une_remise` reste, désormais sans case.
- **Constantes `STOCK_VENDRE_SANS_STOCK` et `VENTE_REMISES_ET_PRIX`** : leur ajout à `PermissionRegistry` les met au référentiel au prochain `migrate:fresh`. `ReferentielDesAutorisationsTest` le vérifie ; la base refuse leurs cases tant qu'elle n'est pas recréée. Elles ne sont pas des consultations : l'expert-comptable ne les a jamais.
- **R3 (jetons)** : `ActionsUniques::executer` compare « le même compte » avec l'auteur reçu par l'action la plus externe, jamais avec `auth()`. Les actions internes reçoivent l'auteur et un jeton nul.
- **Ordre de `EmettreFactureAction`** : celui de 4.3 remplace la conception 8.6. Mêmes alignements dans `EmettreAvoirAction`, `EnregistrerPaiementAction`, `ContrePasserPaiementAction` et `EnregistrerVenteCaisseAction` : registre, puis compte relu, puis documents, puis produits. La validation des achats et des avoirs fournisseur suit le même ordre (4.3), sans registre sauf changement de taux.

### 7.3 Lot A7 : procédures, journal consultable, conservation

- **`JournalAuditResource`** : propriétaire seul. `JournalAuditResourceTest::test_seul_le_proprietaire_consulte_le_journal_et_les_traces` vérifie aussi le refus d'un responsable, d'un administrateur (A+ compris) et de l'expert-comptable [I-22]. L'auteur s'affiche par `auteur_libelle`, jamais par le nom actuel du compte.
- **Nouvelles entrées d'audit** du lot A4 : `CompteRefuse`, `ResponsableNomme`, `ResponsableRetire`, `ExpertComptableCree`, dans la catégorie `acces`. Elles prennent la durée de cette catégorie (5 ans, réponse Q4) ; les filtres de la ressource les proposent.
- **Procédures `editeur:*`** : aucune ne touche aux rôles de magasin. Les invariants « exactement un propriétaire actif » et « au plus un expert-comptable », et les gardes en base (3.4), restent verts après chacune ; `ProceduresTest` le vérifie.
- **Société révoquée** : son équipe n'est plus gérable, ni dans l'espace société ni dans les magasins (G12) ; l'expert-comptable garde la consultation, comme les autres comptes de la société.

### 7.4 Lot de décisions 21 et campagne

- **Matrice décision → test** : elle s'étend aux décisions de la série 6 (0.3), expert-comptable compris, chacune vers ses tests de 6.2.
- **Critère de fin du lot 21** : ajouter les recherches de 6.6, qui doivent rester vides. Dernière passe sur les commentaires qui parleraient encore d'administrateurs qui « gèrent les membres » ou d'un « comptable » de magasin.
- **Plan SUPER PDP** (`scratchpad/superpdp-plan.md`), révisé au lot 21 :
  - refuser ou contester une facture reçue reste au propriétaire et aux administrateurs autorisés, jamais au responsable [I-12] ni à l'expert-comptable [I-22] ;
  - un responsable voit et valide en brouillon les factures attribuées à son magasin, par ses autorisations de magasin ; l'expert-comptable les lit ;
  - `connexions_pdp` porte le nom figé de qui a connecté (3.6).
- **Campagne** (conception 13, T4 et scénarios) :
  - **Composition** :
    - par société, 1 propriétaire, au moins 1 administrateur de tous les magasins, et 1 expert-comptable ;
    - dans une société de plusieurs magasins, un administrateur d'une sélection autorisé à nommer les responsables, un sans cette autorisation, et un magasin créé après l'expert-comptable ;
    - par magasin, au moins 1 responsable et 2 vendeurs, dont au moins un magasin à 2 responsables, une personne responsable de deux magasins, un vendeur de deux magasins et un observateur.
  - **Scénarios à ajouter** :
    - nominations et retraits de responsables (administrateur autorisé, non autorisé, hors portée) ;
    - un responsable qui crée des vendeurs et coche leurs cases pendant des ventes ;
    - retrait du dernier magasin ;
    - refus de comptes jamais confirmés (connexion, lien, compte suspendu ou retiré), suivis d'une tentative de l'auteur avec l'ancien mot de passe ou une session ouverte ;
    - l'expert-comptable qui lit tous les magasins pendant des ventes, exporte le journal des événements et tente des écritures par l'écran, par Livewire et par les actions (toutes refusées) ; création et retrait de l'expert-comptable pendant des ventes ;
    - clôtures manuelles pendant un retrait de « clôturer » ; validations d'achats pendant un retrait de « valider » ;
    - envois en file après un retrait ou une suspension ;
    - noms figés après un changement de nom ou un refus ;
    - écriture d'une autorisation inconnue en base ;
    - retraits de droits simultanés à des ventes dans plusieurs magasins.

### 7.5 Étape 5 : comptabilité [É-EC-export, C3]

- **Feuille de route** : texte de 1.6.
- **Qui** : l'export comptable de la société (FEC) est produit et téléchargé par l'expert-comptable ou par le propriétaire. Un administrateur ne le produit que si l'éditeur le décide à l'étape 5 (question à poser alors).
- **Journal d'audit** : chaque export s'inscrit au journal d'audit de la société (action à créer, par exemple `ExportComptableProduit`, avec la période et le nombre d'écritures, jamais leur contenu) ; sa catégorie et sa durée de conservation suivront l'obligation légale qui le concerne, à vérifier à l'étape 5. L'expert-comptable ne lit pas ce journal [I-22].
- **Lecture hors du magasin** : l'export lit les écritures et les documents de tous les magasins réels de la société ; cette lecture est déclarée dans la puce 2 (1.2), filtrée par société et testée à l'étape 5.
- **Écran** : son emplacement est choisi à l'étape 5. S'il est dans l'espace société, cette seule page s'ouvre à l'expert-comptable, par une autorisation de consultation de société ajoutée à ses droits implicites ; le reste de l'espace lui reste fermé, et `ControleDesDroits` continue de lui refuser toute autre autorisation de société.
- **Journaux par magasin** (ventes, achats, caisse et banque) : leurs autorisations de consultation s'ajoutent à `PermissionRegistry::consultations()`, donc à l'expert-comptable.
- **Tests à prévoir à l'étape 5** : `test_l_expert_comptable_produit_et_telecharge_l_export_comptable_de_la_societe` ; `test_chaque_export_comptable_s_inscrit_au_journal_d_audit` ; `test_l_export_ne_lit_que_les_magasins_reels_de_la_societe` ; `test_ni_un_responsable_ni_un_vendeur_ne_produisent_l_export`.

---

## 8. Interprétations à signaler à l'éditeur

Aucune ne tranche une règle de calcul ni une obligation légale. Chacune est appliquée par l'avenant, et le sera dans le code, jusqu'à ce que l'éditeur la confirme ou la change. Celles qui fixent qui peut faire quoi (2, 3, 4, 5, 6, 7, 18, 19, 21, 22, 23, 24 et 28) sont écrites dans les textes de `CLAUDE.md` de la section 1 (critère de 1.0) ; si l'éditeur en décide autrement, ces textes sont corrigés en même temps que le code. Les autres restent dans l'avenant.

1. **Message initial et réponses suivantes.** Le message initial dit « chaque magasin a un seul responsable », et que l'administrateur n'est « pas pour créer des responsables ». La réponse libre, corrigée, puis la clarification disent « plusieurs par magasin » et « sauf si le propriétaire lui donne l'autorisation ». **Choix** : j'applique les réponses les plus récentes. **Raison** : l'éditeur écrit lui-même « je corrige ».

2. **« Bypass total » du propriétaire.** **Choix** : aucune autorisation ne lui est jamais demandée. Les règles qui valent pour tous s'appliquent à lui :
   - lecture seule, porte de validation, document émis qui ne change plus, journée clôturée, une caisse à la fois ;
   - il ne se suspend, ne se retire et ne refuse pas son compte ;
   - il signe les attestations seulement une fois sa qualité prouvée.

   Techniquement, `ControleDesDroits` ne répond pas « oui » d'office avant les policies. **Raison** : un « oui » à tout laisserait le propriétaire supprimer une facture émise ou vendre dans un magasin fermé. Cela heurterait l'article 286 du CGI et les autres décisions de l'éditeur ; le texte de 1.1 le dit.

3. **Droits du responsable.** **Choix** : il a toutes les autorisations du magasin, y compris « paramètres du magasin » et « vendre sans stock », toujours avec l'avertissement et l'alerte de R1. **Raison** : « il a tous les droits » dans son magasin [É-R, É-droits]. R1 ne précochait cette autorisation pour personne parce que c'était une case ; le responsable n'a plus de cases. Si l'éditeur veut réserver « vendre sans stock » au propriétaire, ce sera la seule case d'un responsable.

4. **Ce que permet « nommer et retirer les responsables ».** **Choix** :
   - **nommer**, c'est créer le compte d'un responsable, ou désigner comme responsable un membre déjà affecté à un magasin de sa portée, qui a choisi son mot de passe ou que l'administrateur a créé lui-même (G14) ;
   - **retirer**, c'est lui retirer un magasin de sa portée ;
   - **changer les magasins**, c'est ajouter ou retirer des magasins de sa portée ;
   - l'administrateur autorisé peut retirer de la société une personne qui n'est que responsable de magasins tous dans sa portée, et lui renouveler son accès **par un lien seulement** (G11) ;
   - il ne la suspend pas, ne la réactive pas et ne la rétrograde pas en vendeur ou observateur : ces gestes restent au propriétaire ;
   - le propriétaire est prévenu de chaque responsable nommé par un administrateur, et le libellé de l'autorisation dit qu'un responsable a tous les droits de son magasin.

   **Raison** : la réponse ne cite que nommer, retirer et changer les magasins. Rétrograder créerait un vendeur, ce qu'un administrateur ne fait jamais [É-R]. Un provisoire donné par l'administrateur lui permettrait d'ouvrir le compte du responsable avant la personne, et d'agir avec des droits qui dépassent les siens.

   **Conséquences à trancher** :
   - (a) Autoriser un administrateur à nommer les responsables lui permet de se donner tous les droits des magasins de sa portée : il peut créer le compte d'un responsable à une adresse qu'il contrôle, dont il connaît le mot de passe provisoire. Aucun contrôle technique ne l'empêche tant qu'il peut créer des comptes. Appliqué : le propriétaire est prévenu de chaque nomination, et le libellé de la case l'avertit. Autres choix possibles : un administrateur ne nomme que des comptes existants ; ou chaque nomination par un administrateur attend la validation du propriétaire.
   - (b) Un administrateur peut nommer mais pas rétrograder. Pour corriger une nomination faite par erreur, il ne peut que retirer le magasin ; si c'était le dernier, la personne est retirée de la société (G8), et le responsable du magasin peut ensuite la réintégrer comme vendeur (I-19), à moins que le propriétaire ne la rétrograde directement. Proposition, non appliquée : l'administrateur peut rendre, à un compte qu'il vient de promouvoir et que personne n'a modifié depuis, son rôle et ses cases d'avant, relus dans le JET 130 de la promotion.
   - (c) En nommant responsable de M un vendeur de M et de N, l'administrateur en fait un compte mixte : le responsable de N perd alors les gestes sur tout le compte, qui reviennent au propriétaire seul (G7). Proposition, non appliquée : prévenir les responsables des autres magasins du compte, sans nommer le magasin, comme en 18.

5. **« Un responsable ne touche jamais un autre responsable ».** **Choix** : la gestion se fait affectation par affectation. Un responsable gère, dans son magasin, l'affectation de tout vendeur ou observateur, même si ce compte est responsable d'un autre magasin. Il ne touche jamais une affectation de responsable, ni ce qui touche tout le compte d'un responsable, ni un administrateur, ni l'expert-comptable, ni le propriétaire. **Raison** : ses autorisations sont « valables uniquement pour ce magasin » [É-R] ; c'est le moyen de gérer ses vendeurs sans dépendre du rôle qu'ils ont ailleurs. L'autre lecture, où le responsable ne touche à rien d'un compte responsable ailleurs, laisserait au seul propriétaire les cases d'un tel vendeur.

6. **Ce qui touche tout le compte** (suspendre, réactiver, retirer de la société, renouveler l'accès). **Choix** :
   - le propriétaire, toujours ;
   - pour un vendeur ou un observateur : une même personne responsable de **chacun** de ses magasins ; le responsable d'un seul de ses magasins ne gère que son affectation dans ce magasin ;
   - pour une personne qui n'est que responsable, de magasins tous dans la portée d'un administrateur autorisé : cet administrateur, pour la retirer de la société et lui envoyer un lien ;
   - pour un compte mixte, un administrateur ou l'expert-comptable : le propriétaire seul.

   **Raison** : [É-R] et [É-V] limitent les droits au magasin ; suspendre ou retirer un compte toucherait les autres magasins. **Conséquence à trancher** : ces gestes changent de main quand le compte gagne un magasin ou un rôle par une autre personne (4 (c) et 18).

7. **Le dernier magasin.** **Choix** : retirer quelqu'un de son dernier magasin le retire de la société (statut « retiré »), après une fenêtre de confirmation. Un membre actif ou suspendu a donc toujours au moins un magasin. **Raison** : un compte actif sans magasin n'ouvre rien, n'est géré par aucun responsable, et empêche la personne de rejoindre une autre société, ce que la règle ne permet qu'après son retrait. Cela supprime aussi la « domination à vide » de la conception (4.3). Pour déplacer quelqu'un d'un magasin à l'autre, la même action ajoute le nouveau magasin et retire l'ancien.

8. **Où le responsable gère son équipe.** **Choix** : dans le panneau du magasin, par un nouvel écran « Équipe du magasin ». L'espace d'administration de la société reste réservé au propriétaire et aux administrateurs. **Raison** : le responsable est un membre de son magasin [É-R], et l'espace société porte des droits de société qui lui sont refusés [É-droits]. La conception (3.2 : « la gestion de l'équipe quitte l'écran du magasin ») est révisée sur ce point.

9. **Ce que voient les uns et les autres.** **Choix** :
   - un administrateur ne voit pas l'équipe des magasins ;
   - un administrateur autorisé voit les responsables de ses magasins et, pour en nommer un, les membres affectés à ses magasins qui ont choisi leur mot de passe ou qu'il a créés (nom, adresse, sans leurs cases) ;
   - un responsable voit les affectations de membre de son magasin, avec la seule mention qu'un membre « a aussi accès à d'autres magasins », sans les nommer ; ni les administrateurs ni l'expert-comptable n'y figurent ;
   - l'expert-comptable ne voit aucune équipe.

   **Raison** : un administrateur ne gère jamais les vendeurs [É-R] ; c'est aussi la minimisation des données, et chaque magasin garde son équipe [CL].

10. **Modèle d'un nouvel administrateur.** **Choix** : « nommer et retirer les responsables » n'est pas précochée ; « gérer les magasins » l'est, comme dans la conception. **Raison** : « sauf si le propriétaire lui donne l'autorisation » [É-A].

11. **Équipe d'un magasin en lecture seule.** **Choix** : dans un magasin fermé ou suspendu, ou une société suspendue ou résiliée, le propriétaire et le responsable gèrent encore l'équipe, notamment pour couper un accès ; le propriétaire gère encore l'expert-comptable. Dans une société révoquée, on ne fait plus que consulter. **Raison** : la règle « son équipe gérable » [CL], étendue au responsable, comme les anciennes autorisations d'équipe l'étaient dans un magasin en lecture seule.

12. **Factures reçues.** **Choix** : refuser ou contester une facture reçue reste au propriétaire et aux administrateurs autorisés, jamais au responsable, même pour une facture attribuée à son magasin. Il la voit et la valide en brouillon par ses autorisations de magasin. **Raison** : l'éditeur range la plateforme agréée parmi les droits de la société [É-droits].

13. **« Statistiques de tous les magasins, catalogue, configuration » de l'administrateur.** **Choix** : dans sa portée seulement, par les cases de magasin que le propriétaire lui coche. Aucune nouvelle autorisation de société n'est créée. **Raison** : l'éditeur a voulu que le propriétaire choisisse « tous les magasins ou une sélection » (V1-2) ; la réponse décrit un rôle, pas une portée.

14. **« S'il n'a plus de magasin », dans la règle du refus.** **Choix** : avec le point 7, seul un compte retiré n'a plus de magasin. Un compte retiré **par la société**, dont l'adresse n'a jamais été confirmée, reste refusable : son refus empêche sa réintégration sans l'accord de la personne. Un compte retiré par son propre refus ne se refuse pas une seconde fois. Sur la page du lien, un compte retiré encore refusable peut aussi choisir son mot de passe ; la page prévient que ce choix confirme l'adresse, ferme le refus et laisse la société le réintégrer. **Raison** : garder un sens à la phrase de l'éditeur, et empêcher une société de réactiver à son gré un compte qui porte l'adresse d'un tiers.

15. **« Son auteur (à défaut, le propriétaire) en est prévenu ».** **Choix** : l'auteur est le compte qui a créé le compte refusé (`cree_par`), s'il est encore actif dans la société ; sinon, le propriétaire. **Raison** : « à défaut » vise un auteur qui ne peut plus être joint dans la société. Le message ne contient que ce qu'il savait déjà : le nom et l'adresse du compte.

16. **Ce que garde « l'historique ».** **Choix** : toute table d'historique qui désigne une personne (documents, paiements, clôtures, mouvements de stock, envois, preuves, consultations VIES, périodes d'activité, vérifications, rattachements) enregistre son nom au moment où elle la désigne. Le registre, le JET 130 et le journal d'audit le font déjà. **Raison** : la règle vaut pour « chaque opération » [É-refus]. Une colonne figée, sur le modèle de l'émetteur figé, évite de reconstruire un nom d'après un historique daté à chaque affichage.

17. **« Le droit d'envoyer », au moment de l'envoi.** **Choix** :
    - c'est la même question que l'écran a posée à la demande : envoyer ce document-là, dans ce magasin, avec les règles de visibilité (un vendeur sans « voir celles de toute l'équipe » n'envoie que ses factures) ;
    - quand l'envoi ne part pas pour cette raison, seul le propriétaire est prévenu ; l'envoi passe en échec avec son motif, visible sur le document ;
    - une relance automatique, sans auteur, n'est pas concernée.

    **Raison** : texte de l'éditeur [É-e-mail].

18. **Ajouter à son magasin quelqu'un qui travaille déjà dans la société.** **Choix** : un responsable peut ajouter à son magasin, comme vendeur ou observateur, par son adresse exacte, un compte actif de la société qui n'est ni propriétaire, ni administrateur, ni expert-comptable, et qui a choisi son mot de passe (ou qu'il a créé lui-même, G14). Il ne peut pas y ajouter un compte suspendu. La recherche se fait par adresse et par société avant toute écriture ; toute adresse qui ne convient pas (inconnue, d'une autre société, compte suspendu, retiré, refusé, encore au provisoire, administrateur, expert-comptable, propriétaire) reçoit la même réponse neutre. C'est une lecture hors du magasin, déclarée en 1.2. **Raison** : il « recrute » ses vendeurs [É-R] ; un compte suspendu relève de qui l'a suspendu ; l'exigence du mot de passe choisi empêche celui qui a créé le compte, et connaît son provisoire, d'agir dans le nouveau magasin.

    **Conséquence à trancher** : un responsable de N peut ajouter à N, sans l'accord du responsable de M, un vendeur qui ne travaillait qu'à M. Ce vendeur devient un vendeur de deux magasins (matrice D) : le responsable de M perd la suspension, le retrait de la société et le renouvellement de l'accès de ce vendeur, qui reviennent au propriétaire ou à une personne responsable des deux magasins. Proposition, non appliquée : prévenir les responsables des magasins où le compte travaillait déjà qu'il a désormais accès à un autre magasin, sans le nommer (comme la mention de l'écran d'équipe) ; ou exiger leur accord.

19. **Réintégrer un compte retiré et proposer un rattachement.** **Choix** : c'est une création :
    - le propriétaire, pour tout rôle ;
    - le responsable des magasins visés, pour des affectations de vendeur ou d'observateur ;
    - l'administrateur autorisé, pour des affectations de responsable dans sa portée ;
    - comme administrateur ou expert-comptable : le propriétaire seul.

    Un compte retiré par le propriétaire ne se réintègre que par lui. Un compte retiré par son propre refus ne revient que par un rattachement qu'il accepte. Un compte encore au provisoire ne se réintègre que par son auteur (G14). **Raison** : mêmes règles que la création [É-R, É-nommer, É-EC-créé], avec la règle de la conception sur le propriétaire et celle du refus [É-refus].

20. **Compte refusé puis rattaché de nouveau à la même société, avec l'accord de la personne.** **Choix** : rien de particulier. Comme tout compte, il retrouve les ventes enregistrées sous lui dans « Mes ventes » de ce magasin, et le nom figé montre qui les a faites. **Raison** : l'éditeur garde « le même compte » ; une règle de visibilité datée ajouterait de la complexité pour un cas rare, qui exige l'accord de la personne et l'affectation par la société.

21. **« Un seul expert-comptable ».** **Choix** : au plus un compte d'expert-comptable par société (index unique partiel `users_un_expert_comptable_par_societe`) ; pour en changer, le propriétaire retire l'ancien puis crée le nouveau. **Raison** : les mots de l'éditeur, « un seul expert-comptable » et « l'expert-comptable de la société ». L'autre lecture (« un seul rôle » au lieu des comptables de magasin) autoriserait plusieurs comptes. **Conséquence** : les collaborateurs d'un cabinet ne peuvent pas avoir chacun leur compte dans la même société, et ne doivent pas partager le mot de passe de l'expert-comptable (« personne d'autre ne connaît son mot de passe ») ; si l'éditeur veut plusieurs comptes, il suffit de retirer l'index et le test qui le fixe.

22. **Ce que lit l'expert-comptable.** **Choix** : dans chaque magasin réel de la société, présent ou futur, toutes les autorisations de consultation (`PermissionRegistry::consultations()`) : fiches (clients, fournisseurs, produits et valeur du stock), devis, factures, avoirs, paiements, achats et avoirs fournisseur (factures reçues comprises à l'étape 2), clôtures, registre fiscal et journal des événements, avec leur export, le contrôle d'intégrité et les PDF ; aucune autre. Pas le magasin de démonstration. Les « journaux » ne comprennent ni le journal d'audit ni les adresses IP et navigateurs, que `CLAUDE.md` réserve au propriétaire. Ses consultations qui laissent une trace obligatoire (ouverture et fin de session, export du journal, contrôle d'intégrité) s'inscrivent à son nom ; ce ne sont pas des modifications. **Raison** : « Tous, en lecture » [É-EC-accès] ; les documents qu'il contrôle citent ces fiches ; une seule liste de consultations échoue fermée pour toute autorisation nouvelle ; le journal d'audit reste « consultable par le propriétaire seul » [CL]. L'éditeur peut retirer un élément (par exemple les devis), ou ouvrir le journal d'audit à l'expert-comptable.

23. **Aucun envoi d'e-mail par l'expert-comptable.** **Choix** : il n'envoie ni facture, ni avoir, ni devis, ni relance. **Raison** : un envoi écrit aux clients au nom du magasin et s'inscrit à l'historique ; « il ne vend, ne modifie et ne supprime rien » [É-EC-accès] décrit une lecture seule.

24. **Refus de son compte par l'expert-comptable.** **Choix** : comme tout compte créé par la société, il peut le refuser tant que son adresse n'a jamais été confirmée ; la formule du refus devient « Un compte créé par la société, membre, administrateur ou expert-comptable, … ». **Raison** : le but de la règle (rendre son adresse au titulaire) vaut pour lui ; la formule approuvée ne citait que les deux rôles créés par la société à ce moment-là.

25. **Un expert-comptable qui suit plusieurs sociétés.** **Choix** : rien de nouveau : un compte n'appartient qu'à une société [CL] ; un cabinet qui suit plusieurs sociétés clientes y a un compte, donc une adresse, par société. **Raison** : la règle « un compte, une société » est une décision de l'éditeur que la série 6 ne change pas. Un compte d'expert-comptable commun à plusieurs sociétés serait une nouvelle décision, à prendre avant l'étape 13 si l'éditeur le souhaite.

26. **Rôle de l'expert-comptable et changements de rôle.** **Choix** : l'expert-comptable est un rôle de société, sans affectation. Retiré, ou s'il refuse son compte, il repasse membre, comme un administrateur. Un compte actif ne passe jamais directement d'un autre rôle à expert-comptable, ni l'inverse : le propriétaire le retire, puis le réintègre ou lui propose un rattachement dans le nouveau rôle. **Raison** : « créé uniquement par le propriétaire » [É-EC-créé] ; une transition directe ajouterait une action et ses gardes pour un cas rare.

27. **« Restent au propriétaire ou à l'administrateur ».** **Choix** : cette liste de la réponse « Tout le magasin, pas la société » décrit ce qui reste hors du responsable ; elle ne change pas la répartition entre propriétaire et administrateur fixée par `CLAUDE.md` : céder un magasin, connecter la plateforme agréée et consulter le journal d'audit restent au propriétaire seul. **Raison** : la question portait sur les droits du responsable ; ouvrir ces trois gestes à l'administrateur contredirait des décisions antérieures sans que l'éditeur l'ait dit.

28. **Refus, mot de passe et sessions.** **Choix** : un refus remplace le mot de passe par une empreinte inutilisable et ferme toutes les sessions du compte. « Après avoir confirmé son adresse par un lien » se lit « après avoir choisi son mot de passe sur la page d'un lien envoyé à son adresse ». La création de sa société et l'acceptation d'un rattachement redemandent ce mot de passe. Sur la page du lien, un compte retiré peut choisir son mot de passe, sans ouvrir aucun panneau. **Raison** : sans cela, la personne qui connaissait le mot de passe (l'auteur qui a fixé le provisoire, ou le salarié qui utilisait un compte à l'adresse mal tapée) pourrait créer une société avec l'adresse du titulaire dès que celui-ci a refusé, et le titulaire, qui ne connaît pas ce mot de passe, n'aurait aucun moyen d'utiliser son compte.

---

## 9. Ce qui ne change pas

Sauf là où l'avenant le dit, la conception et le travail A4 restent la règle. Notamment :
- **Isolation** : les deux périmètres, `BelongsToSociete`, les lectures explicites de la conception 2.3 (plus les quatre de 1.2), et les règles d'unicité 2.4.
- **Panneau société** : ouvert au propriétaire et aux administrateurs actifs (3.2), fermé à l'expert-comptable ; mode d'autorisation strict ; méta-tests des policies.
- **Mot de passe provisoire** de 72 heures, intergiciel `ExigerUnMotDePasseDefinitif`, message d'expiration (3.4).
- **Changement d'adresse confirmé** par un lien, et l'ancienne adresse prévenue.
- **Rattachement et création de sa société par un compte retiré** (3.5), sauf qui propose (I-19), le rôle proposé (`role_societe`), l'adresse confirmée et le mot de passe redemandé (3.5 de l'avenant).
- **Transfert de propriété** (3.4), avec l'ordre de 3.4 de l'avenant ; jamais vers l'expert-comptable.
- **Traçabilité stricte d'une vente** (3.6), sélecteur de magasin, bandeau de la caisse, « une caisse à la fois » (réponse Q2), verrouillée par la ligne du compte.
- **Lecture seule** (3.8), JET 80 et 40 (3.9), notifications de société.
- **`ControleDesDroits`** seul `Gate::before` ; refus en lecture seule ; magasin qui ne peut pas vendre.
- **Autorisations de magasin** : leur liste (4.5), leurs libellés ; les modèles du vendeur et de l'observateur.
- **Autorisations de société** `magasins.gerer` (dans la portée ; créer avec tous les magasins) et `parametres.gerer` (avec tous les magasins).
- **Gestes réservés au propriétaire** (4.4), plus ceux de la matrice (administrateurs, expert-comptable, rétrogradation et suspension d'un responsable, comptes mixtes).
- **JET 130** dans chaque magasin touché, avant et après relus en base, sous les verrous des registres pris par identifiant croissant ; tous les magasins réels pour un administrateur de tous les magasins et pour l'expert-comptable.
- **Les décisions R1 à R5** et leurs tests, sauf les textes de 1.3 et 1.4 et les ajouts de 7.2.
- **La limite de 20 comptes créés par jour et par société**, les réponses neutres, les mails du lot A4.

---

## Annexe A : sections de la conception remplacées ou complétées

| Section de la conception | Effet de l'avenant |
|---|---|
| I (résumé) : paragraphes « Ce qui change pour les administrateurs », « pour les membres », « pour les permissions » | remplacés par 0.3 et 1.1 ; l'argument du cache de Spatie est retiré |
| II, points 4 et 6 | précisés par 0.3 (responsables, expert-comptable, référentiel en base) |
| 1.2, « Compte jamais connecté » | remplacé par « Adresse confirmée » et « Compte refusable » (3.5) |
| 1.3 `users`, `affectations`, `autorisations_affectations`, `autorisations_societe`, `demandes_rattachement` | 3.2 à 3.4 ; nouvelles tables de référence (3.3) ; index de l'expert-comptable ; colonnes de nom figé dans les tables d'historique (3.6) |
| 1.5, énumérations, modèles et services | 3.7 : `RoleSociete::ExpertComptable` ; `RoleMagasin` sans `Comptable` |
| 2.3, lectures hors du magasin | quatre lectures ajoutées (1.2) |
| 3.2, tableau des écrans (`MembreResource`) et « Quittent le panneau magasin » | `MembreResource` pour le propriétaire et A+ ; `EquipeDuMagasinResource` arrive dans le panneau du magasin [I-8] |
| 3.4, membres et administrateurs | remplacée par 2, 4.5 et 4.7 ; expert-comptable (`CreerExpertComptableAction`) |
| 3.5, compte retiré | qui propose (I-19) ; rôle proposé ; adresse confirmée, mot de passe redemandé (3.5) |
| 3.7, ce que voit un vendeur | inchangée ; ce que voient un responsable et l'expert-comptable : matrice 2.3 J |
| 3.9, destinataires | alerte de stock aux responsables (4.9) ; échec d'envoi faute de droits au propriétaire (4.9) |
| 4.1 à 4.3, 4.6, 4.8 | remplacées par 2, 3.7 et 4.6 ; le comptable disparaît des modèles (4.6 de la conception) |
| 4.4, gestes réservés au propriétaire | complétée par la matrice 2.3 (administrateurs, expert-comptable) |
| 4.9, concurrence | `LecteurDesDroits` (4.2), ordre des verrous (4.3), tests (6.2) |
| 8.6, ordre de `EmettreFactureAction` | remplacé par 4.3 |
| 11, ce qui disparaît | complétée par 3.8 |
| 12, textes de `CLAUDE.md` | « Comptes et équipe », puce 2 de « Produit », R2, R1, puce « E-mails », étapes 1 et 5 de la feuille de route : section 1 |
| 13, campagne | composition et scénarios complétés (7.4) |
| 14, lot A4 | objectif, migrations, créés, modifiés, supprimés, tests, fin et relecture : sections 3 à 6 de l'avenant |
| 14, lots A5, A6, A7, lot 21 | section 7 ; étape 5 : 7.5 |
| 15, Q2 et Q5 | tranchées (réponses Q2 = a de la série 5, et refus = « adresse jamais confirmée » de la série 6) |
| Annexe B | corrections en 6.4 |
| `recommandation-finale.md` 5.2 D, option a ; 7.2 | sans objet : Spatie est retiré [É-Spatie] |

---

## Annexe B : traitement des critiques de la version précédente

Chaque critique a été vérifiée dans `reponses-serie6.md`, dans la version précédente de l'avenant et dans le dépôt (lecture seule). Verdicts : **retenu** ; **retenu en partie** (ce qui est écarté est dit, avec sa raison). Aucune critique n'est écartée en entier. C10 et C20 répètent C1, C2 et C6 ; C21 complète C5.

Les règles sont citées dans la numérotation de cette version. Les critiques citaient celle de la version précédente : ses G5 à G12 sont ici G6 à G13 ; G5 (expert-comptable) et G14 (compte au provisoire) sont nouvelles. « Ancienne Gn » renvoie à la version précédente.

| N° | Critique (gravité) | Vérification | Verdict | Raison et correction |
|---|---|---|---|---|
| C1 | Les réponses sur l'expert-comptable ne sont pas reprises ; le rôle « comptable » de magasin, créé par le responsable, est gardé (bloquant) | `reponses-serie6.md` l. 36-44. La version précédente ne contenait pas une seule fois « expert » et donnait les comptables au responsable (0.2 [É-comptables], 0.3, 1.1, anciennes G5 et G6, matrices C, D, H, 1.3, 1.5, 1.6) [lu] | retenu | Le rôle « comptable » de magasin disparaît partout ; [É-obs] remplace [É-comptables] ; règle G5 et matrice I « Cible : l'expert-comptable » (P « Oui », tous les autres « Non ») ; 1.1, 1.3, 1.5 et 1.6 réécrits ; responsable limité aux vendeurs et observateurs |
| C2 | Le modèle de données, les gardes et les modèles de cases ne permettent pas l'expert-comptable et gardent un comptable qui écrit (bloquant) | `RoleSociete.php` : trois cas ; `RoleMagasin.php:73-88` : paiement, contre-passation, envois ; ancienne garde (rôle nul refusé hors administrateur) ; ancienne 4.6 (« toute autre configuration : `[]` ») ; tests de la version précédente sur le comptable [lu] | retenu | `RoleSociete::ExpertComptable`, sans affectation ni case (3.7) ; `PermissionRegistry::consultations()` ; refus dans `ControleDesDroits` quelles que soient les lignes en base (4.6) ; gardes en base et transitions (3.4) ; index « un par société » ; `autorisationsDans()`, `magasinsAccessibles()`, `peutOuvrir()` (4.6) ; `RoleMagasin::Comptable` supprimé sans compatibilité (3.8) ; défaut 28 ; tests de 6.2 et règle de réécriture des 41 usages de `RoleMagasin::Comptable` (6.4) |
| C3 | Conséquences sur l'étape 5, le journal d'audit, le refus et l'isolation ni reportées ni signalées (important) | `reponses-serie6.md` l. 44 ; ancienne 1.6 (étape 1 seule), section 7 sans étape 5, section 8 sans expert-comptable, ancienne 1.2 [lu] | retenu | Étape 5 réécrite (1.6) ; 7.5 ; lecture de l'export déclarée dans la puce 2 (1.2) ; interprétations 21 (un compte), 22 (journal d'audit exclu), 23 (aucun e-mail), 24 (refus, formule étendue) et 25 ; expert-comptable dans la composition de la campagne (7.4) |
| C4 | Le texte proposé pour `CLAUDE.md` interdit ce que la matrice et I-5 permettent (important) | ancienne 1.1 contre matrices C et E, I-5 et le test de référence [lu] | retenu | Phrases réécrites comme proposé, sans comptable : « il ne crée, ne nomme ni ne retire jamais un responsable, ne touche jamais une affectation de responsable ni ce qui touche tout le compte d'un responsable… ; il gère dans son magasin l'affectation de vendeur ou d'observateur de toute personne… » et « … sauf pour nommer l'un d'eux responsable dans un magasin de sa portée, s'il y est autorisé » (1.1) ; signalées comme reprenant I-5 et I-4 |
| C5 | La recherche par adresse d'I-18 est une lecture hors du magasin non déclarée (important) | ancienne 1.2 : deux lectures ajoutées seulement [lu] | retenu | Lecture déclarée dans la puce 2 (1.2), limitée aux champs nécessaires (`compteAAjouter`, 4.5) ; test d'isolation `IsolationDesSocietesTest::test_ajouter_un_compte_par_son_adresse_ne_lit_ni_n_affiche_rien_d_autre_que_le_succes_ou_la_reponse_neutre` |
| C6 | « au responsable de chacun de ses magasins » se lit à l'opposé de l'ancienne G6 (G7 ici) et de la matrice D (mineur) | ancienne 1.1 ; matrice D (« R(M) seulement : Non ») [lu] | retenu | « … revient au propriétaire, ou à une personne qui est responsable de tous les magasins de ce compte ; le responsable d'un seul de ses magasins ne gère que son affectation dans ce magasin » (1.1, G7) |
| C7 | Conséquences combinées d'I-4, I-6 et I-18 non signalées (mineur) | matrices C, D, E ; G7 ; G8 [lu] | retenu | Trois conséquences ajoutées à I-4 (b, c), I-6 et I-18, chacune avec une proposition non appliquée : prévenir les responsables des autres magasins sans nommer le magasin, ou exiger leur accord ; laisser l'administrateur annuler sa propre promotion, rôle et cases relus dans le JET 130 |
| C8 | La liste « restent au propriétaire ou à l'administrateur » ouvrirait la cession, la plateforme et le journal d'audit à l'administrateur, sans que l'avenant le signale (mineur) | `reponses-serie6.md` l. 24 [lu] | retenu | I-27 : la liste décrit ce qui reste hors du responsable ; la répartition de `CLAUDE.md` entre propriétaire et administrateur ne change pas |
| C9 | Critère d'entrée des interprétations dans `CLAUDE.md` inconstant (mineur) | anciennes lignes 79, 81, 83 et 1154 [lu] | retenu | Un seul critère (1.0) : toutes les interprétations qui fixent qui peut faire quoi entrent dans `CLAUDE.md`, G7 compris pour les responsables ; préambule de la section 8. Choix de cette option plutôt que l'autre : une règle absente de `CLAUDE.md` serait défaite par la session suivante, et le « bypass total » sans sa limite (I-2) serait dangereux |
| C10 | L'expert-comptable tranché en série 6 est absent (bloquant) | mêmes constats que C1 et C2 [lu] | retenu, avec C1 et C2 | Corrections de C1 et C2 ; les six tests proposés sont repris (6.2), dont `ExpertComptableTest`, `EquipeDuMagasinTest::test_un_responsable_ne_cree_ni_ne_touche_l_expert_comptable`, `GardesDesDroitsEnBaseTest::test_la_base_refuse_une_affectation_ou_une_autorisation_de_societe_a_l_expert_comptable`, `AutorisationsDesRolesTest::test_il_n_existe_plus_de_role_comptable_de_magasin`. « Déclarer en 1.2 la lecture de tous les magasins » : retenu pour l'export de la société ; la lecture magasin par magasin, faite depuis chaque magasin, n'est pas une lecture hors du magasin (1.2) |
| C11 | Après un refus, le mot de passe et les sessions restent : l'auteur ou le vrai utilisateur crée une société avec l'adresse du titulaire ; le titulaire ne peut pas choisir de mot de passe (bloquant) | ancienne 3.5 (la page du lien pose `email_verified_at`), ancienne 4.5 (`remember_token` seul), ancienne 4.7 (refus seul pour un compte retiré) ; `config/session.php:21` (pilote `database`) ; `AuthenticateSession` seulement dans `AppPanelProvider.php:102` et `SocietePanelProvider.php:75` ; conception l. 513-514 [lu] | retenu | Refus : mot de passe inutilisable, sessions supprimées, `remember_token` renouvelé ; toute écriture de mot de passe, suspension et retrait supprime les sessions ; la page du lien propose à un compte retiré de choisir son mot de passe ; `/societe/creer` et le rattachement redemandent le mot de passe dans la requête qui agit (3.5, 4.5, 4.7) ; I-28 ; tests. La forme proposée (création et rattachement dans la requête même qui consomme un lien) est remplacée par une garantie équivalente et plus simple : après un refus ou la confirmation de l'adresse d'un compte retiré, seul le titulaire du lien connaît un mot de passe utilisable |
| C12 | Un administrateur autorisé obtient tous les droits d'un magasin, et la gestion de son équipe, par un compte de responsable dont il connaît le mot de passe (important) | ancienne matrice B (A+ crée et redonne un provisoire) ; ancienne G8 ; `modeleDeLAdministrateur()` [lu] | retenu en partie | **Retenu** : un administrateur ne redonne jamais de provisoire, il renouvelle par un lien (G11) ; un compte encore au provisoire ne reçoit de droits que de son auteur (G14), donc un administrateur ne nomme un compte existant que s'il a choisi son mot de passe ; le propriétaire est prévenu de chaque nomination ; le libellé de l'autorisation dit qu'un responsable a tous les droits de son magasin ; signalé en I-4 (a). **Écarté** : « ne nommer que des comptes existants » contredit la réponse de l'éditeur (« créer des responsables ») et reste proposé en I-4 ; « un lien d'activation au lieu du provisoire » ne ferme pas le compte créé à une adresse que l'administrateur contrôle, et changerait la règle générale du provisoire remis en main propre. Le test « ne connaît jamais le mot de passe d'un responsable qu'il nomme » devient `test_un_administrateur_autorise_ne_nomme_un_compte_existant_que_s_il_a_choisi_son_mot_de_passe_ou_s_il_l_a_cree` |
| C13 | La clôture manuelle de la journée passe par des entrées réservées au système (important) | `ListClotureCaisses.php:106` (visibilité), `:109` (`jusquAu`), `:167` (`FermerLesCaissesPersonnellesAction`) ; `CloturerLesJourneesAction.php:74-81` (`cloturerCaisse->handle($magasin, $journee)`, sans auteur) [lu] | retenu | `CloturerLesJourneesAction::parUnePersonne()` et `parLeSysteme()` (4.4) ; l'écran n'appelle que la première ; recherche de 6.6 étendue ; défaut 26 ; tests SQLite et PostgreSQL |
| C14 | `OperateurCourant` ne distingue pas « système » de « rien de posé » (important) | ancienne 4.2 (`actuel()` revient à `auth()->user()`) ; `RegistreFiscal.php:465`, `:486` [lu] | retenu | Trois états, `pendantLeSysteme()`, aucun repli sous le système ; en plus, `RegistreFiscal::inscrire()` refuse d'écrire sans état posé (4.2) ; tests |
| C15 | La validation d'un achat et la fermeture de la caisse d'un vendeur relisent l'auteur sans verrou (important) | `recommandation-finale.md` l. 587 ; anciennes 4.1 (sans les achats) et 4.4 (« sous les verrous des produits ») ; `FermerSaCaisseAction.php:82` et `:112` [lu] | retenu | `exigerSousVerrou` (ligne de l'auteur, avant documents et produits) pour la validation et la modification d'un achat validé, et pour la fermeture de la caisse d'une autre personne (lignes de l'auteur et du vendeur) ; étendu à l'émission d'un avoir fournisseur, qui fait aussi sortir du stock (4.1, 4.3, 4.4) ; tests |
| C16 | `TestCase::membre()` par `CreerMembreAction` crée un compte au provisoire, donc sans droit (important) | ancienne 3.7 ; `ControleDesDroits.php:75` [lu] | retenu | Les aides passent par le changement du provisoire ; aide distincte pour un compte au provisoire (3.7) ; test `MembresTest::test_le_membre_cree_par_l_aide_de_test_agit_et_n_a_pas_d_adresse_confirmee` |
| C17 | « Tous les magasins ou une sélection, jamais les deux » laissé au modèle, avec une justification fausse (mineur) | `User.php:263-284` : `administreTousLesMagasins()` vrai dès qu'une ligne globale existe, `magasinsDeSaPortee()` rend alors tous les magasins [lu] | retenu | Garde en base, avec `FOR NO KEY UPDATE` pour que deux écritures simultanées se suivent (un verrou `FOR SHARE` ne les sérialiserait pas) ; lecture qui échoue fermée (3.4, 4.6) ; défaut 27 ; tests, dont un test de concurrence |
| C18 | La cascade depuis le référentiel efface des droits sans JET 130 ni audit (mineur) | ancienne 3.3 ; garde-fou 10 de la conception (l. 640) [lu] | retenu en partie | **Retenu** : `restrictOnDelete` et `test_une_cle_portee_par_des_cases_ne_se_supprime_pas`. **Reporté** : la procédure journalisée qui retire une autorisation en production (cases effacées par `JournalDesDroits` avec JET 130, puis la clé) s'écrira avec la première autorisation retirée en production, selon la règle « Développement » de `CLAUDE.md` ; son test n'est donc pas exigé au lot A4 |
| C19 | `AffectationPolicy::view` accorde toute affectation du magasin actif à n'importe quel compte (mineur) | ancienne 4.6 ; `MembrePolicy::view` = `viewAny` et portée, aux lignes 38-40 de `MembrePolicy.php` (et non 239-242) [lu] | retenu | `view` = `viewAny` et affectation de membre du magasin actif (4.6) ; test `AffectationPolicyTest::test_un_vendeur_ou_un_administrateur_ne_voit_aucune_affectation_du_magasin` |
| C20 | « au responsable de chacun de ses magasins » se lit contre l'ancienne G6 (G7 ici) (mineur) | même constat que C6 | retenu, avec C6 | Correction de C6 |
| C21 | Réponse neutre promise mais non testée pour une adresse d'une autre société (mineur) | ancienne 6.2 ; clé composite `2026_10_02_100005_create_affectations_table.php:39` [lu] | retenu | Recherche par adresse et par société avant toute écriture, même réponse pour tous les cas refusés (`compteAAjouter`, 4.5) ; test `EquipeDuMagasinTest::test_ajouter_par_adresse_repond_pareil_…` |
| C22 | Deux scénarios « MANQUANT » (K1, C3) pas repris en entier (mineur) | `scenarios-securite.md`, K1 et section 6, A 4 [lu] | retenu | `TransfererProprieteTest::test_un_transfert_force_par_une_requete_livewire_d_un_administrateur_ne_change_rien` ; `ActionsForgeesTest::test_une_action_d_en_tete_masquee_appelee_directement_ne_fait_rien` paramétré par page ; fermer et rouvrir un magasin ajoutés au lot A5, avec leurs actions (7.1) |

**Corrections ajoutées en relisant, au-delà des critiques.**
- G14 (un compte encore au provisoire ne reçoit de droits que de son auteur) s'applique aussi à l'ajout d'un compte à un autre magasin (I-18) et à la réintégration : sans elle, celui qui a créé le compte, et connaît son provisoire, agirait dans un magasin où il n'a pas de droits.
- `EquipeDuMagasinResource` ne liste que les affectations de membre (rôle non nul) : les cases d'un administrateur d'une sélection n'apparaissent pas au responsable (I-9).
- L'émission d'un avoir fournisseur relit son auteur sous verrou, comme la validation d'un achat (C15).
- `RegistreFiscal::inscrire()` refuse d'écrire sans opérateur posé (C14).
- La page du lien prévient un compte retiré encore refusable que choisir son mot de passe ferme le refus (I-14).
- `FermerMagasinAction` (lot A5) clôture les journées en attente par l'entrée du système (7.1), conséquence automatique de la fermeture.
- `ControleDesDroits` refuse à l'expert-comptable toute question de policy autre que `viewAny` et `view`, y compris une question future : la lecture seule échoue fermée.
