# Lot EC : espace des cabinets d'expertise comptable — cahier des charges

Rédigé le 24/10/2026 sur la copie figée du commit 41fd0a5 (branche claude/saas-invoice-editor-architecture-q6eizh), pour l'agent qui implémente le lot EC, puis pour ses deux relecteurs. Mis d'accord le même jour avec les réponses de l'éditeur aux questions Q-EC1 à Q-EC7 et avec la décision des types d'activité (lot TA) : la section 8 donne chaque réponse et ce qu'elle change. Corrigé le 25/10/2026 après une deuxième série de deux relectures adversariales (section 9).

Le lot EC vient après les lots V2 (vérification par courrier), V2b (réponses de `reponses-v2.md`), FA1 à FA3 (concurrence des brouillons et des fiches) et TA (type d'activité choisi à l'inscription), avec leurs relectures. Il part de ce que ces lots ont livré et ne refait rien de ce qu'ils posent.

Ce qui fait foi, dans l'ordre :
1. `serie8/reponses-cabinets.md` (réponses de l'éditeur du 24/10/2026 à Q-EC1 à Q-EC7, parcours de l'invitation). Il complète la décision du 19/10/2026 et prime sur elle et sur ce cahier partout où ils diffèrent.
2. `serie8/reponses-types-activite.md`, puis `serie8/decision-types-activite.md` (lot TA) : le type d'activité choisi à l'inscription dans un menu, définitif, dont le cabinet d'expertise comptable. Sa section « Ce que le lot EC reprend » s'applique à ce cahier.
3. `serie8/reponses-v2.md` (lot V2b), qui complète `serie8/decision-verification-courrier.md`.
4. `serie8/decision-espace-cabinets.md` (décision de l'éditeur du 19/10/2026, règles 1 à 10 ; sa règle 10 est remplacée par le lot TA).
5. `serie8/decision-verification-courrier.md` (lot V2), que le lot V2 a appliquée avant EC : SIREN unique dans tout PILIQI, inscription par SIREN, adresse e-mail et mot de passe, validation par le seul code du courrier, au moyen d'une action de validation unique qui reçoit la preuve utilisée.
6. Ce cahier des charges.
7. CLAUDE.md du dépôt (protocole de rigueur, isolation, une migration de création par table, modifiée sur place, aucun code de compatibilité, tests sur les deux moteurs).

Les numéros de ligne cités viennent de la copie figée 41fd0a5. Les lots S8-L à T1, V2, V2b, FA1 à FA3 et TA ont pu les déplacer : relis toujours le code actuel avant de modifier. Partout où ce cahier nomme une classe que V2 crée ou renomme, suis les noms que V2 a réellement choisis. Cela concerne l'action de validation unique et son énumération de preuves, la règle « SIREN pris », la page du code du courrier, le formulaire d'inscription et la colonne du représentant légal. Ce cahier les appelle, faute de mieux : `ValiderLaSocieteAction` et `PreuveDeValidation`, `SirenPris`, la page du code de V2, `InscriptionSociete`, `societes.representant_legal`. Au HEAD `65819c6` (lot V2b), `ValiderLaSocieteAction`, `PreuveDeValidation` et `Societe::sirenPris()` existent sous ces noms ; le nom du représentant légal n'est gardé que dans le destinataire du courrier (`codes_confirmation.destinataire`). Pour le lot TA, suis de même les noms qu'il a choisis ; ce cahier les appelle comme `decision-types-activite.md` : `TypeDActivite` (`Commerce`, `Services`, `Cabinet`), `societes.type_activite`, `Societe::estUnCabinet()` et `aDesArticles()`, `PermissionRegistry::pourLeType()` et `articles()`, `GardesDesTypesDActivite`, `CloreLInscriptionAction::parLEditeur()` et `MotifCloture::TypeErrone`.

Consigne de l'éditeur sur les tests existants (24/10/2026) : « si tu trouves qu'ils ne sont pas bons, supprime-les ».
- Un test qui garde son objet est réécrit avec la même intention.
- Un test qui teste une fonction disparue, ou qui n'est plus bon, est supprimé. Le message de commit le nomme, avec sa raison.
- Jamais un test n'est supprimé, affaibli ou réécrit pour faire passer la suite (règle 4 du protocole).

---

## 0. Résumé et ordre de travail

### 0.1 Ce que fait le lot

1. **Le cabinet.** Un cabinet d'expertise comptable est une société PILIQI de type « Cabinet d'expertise comptable » (`TypeDActivite::Cabinet`, colonne `societes.type_activite`), choisi à son inscription dans le menu « Votre activité » et définitif. Le lot TA pose déjà le type, l'entrée du menu, le contrôle 69.20Z avant tout courrier et le refus de tout magasin et de toute démonstration : EC ne les refait pas.
   - Il s'inscrit et se valide comme toute société, par le parcours de V2 et du lot TA.
   - Son code d'activité Sirene doit être 69.20Z. TA le contrôle à l'inscription. EC garde le code lu à la confirmation dans la ligne `cabinets`, et fait contrôler par l'éditeur un code absent ou d'une autre nomenclature.
   - Son inscription au tableau de l'Ordre est vérifiée derrière l'interface `AnnuaireDeLOrdre`. Elle a deux pilotes : `faux` pour les tests, et `editeur` en production, faute d'API officielle documentée (voir 3.3). L'éditeur conclut par la procédure `editeur:cabinet:verifier-ordre`.
   - Un cabinet n'a aucun magasin, et la base le refuse (garde du lot TA).
2. **Son panneau.** Le cabinet a un panneau Filament à lui, `cabinet` (`/cabinet/{societe:uuid}`), distinct des panneaux `app` et `societe`. On y trouve :
   - le tableau de bord multi-clients ;
   - les clients liés, leur fiche et leurs magasins réels ;
   - les invitations et les demandes ;
   - les collaborateurs et les clients confiés à chacun ;
   - la vérification et l'identité du cabinet.
3. **Les collaborateurs.** Le rôle de société `collaborateur` remplace `expert_comptable`. Il n'existe que dans un cabinet.
   - Les comptes se créent, se suspendent, se retirent, se réintègrent et se voient redonner l'accès par le propriétaire du cabinet seul, selon les règles des comptes de PILIQI.
   - Chacun ne voit que les clients que le propriétaire lui confie (table `clients_confies`).
4. **Le lien entre un cabinet et un client** (tables `liens_cabinet` et `autorisations_cabinet`). Un client a au plus un lien actif. Du côté du cabinet, seul son propriétaire invite, demande, accepte, retire et met fin aux liens, et seul il voit les invitations et les demandes ; il confie ensuite chaque client lié à ses collaborateurs (Q-EC1). Le cabinet invite uniquement par le SIREN du client, avec l'adresse e-mail à laquelle part le lien ; l'e-mail nomme le cabinet qui invite, et la personne qui reçoit le lien accepte ou refuse.
   - **(a) Invitation d'une entreprise non inscrite** (table `invitations_cabinet`). Le lien envoyé par e-mail est valable 7 jours. À l'acceptation, PILIQI contrôle de nouveau au répertoire Sirene que l'entreprise existe et est active, sinon rien n'est créé. La personne choisit son mot de passe et le type d'activité de la société dans le menu du lot TA, sans « Cabinet d'expertise comptable », avec la case du choix définitif. La société naît validée sans courrier ni QR code, avec la preuve « invitation du cabinet X ». Son identité est remplie depuis le registre national (INPI) ; si le registre ne connaît pas l'entreprise, ou n'en donne pas de représentant légal, la société est quand même créée et validée, avec ce que donnent le registre et le répertoire Sirene, et le propriétaire complète le reste : l'entreprise active au répertoire suffit (règle 1 de `reponses-cabinets.md`). Seul un registre injoignable fait réessayer l'acceptation plus tard. La personne peut aussi refuser l'invitation.
   - **(b) Lien avec une société déjà inscrite.** Quand le SIREN invité est déjà pris, l'invitation devient une demande de rattachement : l'e-mail part à l'adresse saisie, et son lien mène, après la connexion du propriétaire de la société, à une page de rattachement qui montre le cabinet et trois choix : accepter avec les autorisations par défaut, accepter en les ajustant, ou refuser. Le propriétaire de la société peut aussi désigner lui-même un cabinet vérifié. Un rattachement ne valide jamais une société qui attend son code.
   - **Autorisations.** Par défaut, le cabinet reçoit les consultations. Le propriétaire du client règle ensuite ce qu'il accorde : il retire ce qu'il veut et ajoute seulement des autorisations d'achats et de stock, qui ne se proposent qu'à un client « Commerce ou atelier ». La base refuse toute autre autorisation, et toute vente au nom d'un compte de cabinet.
   - **Fin du lien.** Le client ou le cabinet y met fin. Elle est inscrite aux deux journaux d'audit, et l'accès se perd dès la requête suivante.
   - **Avant le lien actif**, le cabinet ne connaît d'une société inscrite que le SIREN qu'il a saisi.
5. **L'accès d'un compte de cabinet chez un client.** Un compte de cabinet ouvre les magasins réels d'un client lié qui lui est confié, « comme s'il y était connecté », avec exactement les autorisations du lien. Cela passe par :
   - `User` ;
   - `ControleDesDroits`, avec une liste blanche des gestes ;
   - `JournalDesDroits`, pour le JET 130 dans les magasins du client et les verrous de plusieurs sociétés ;
   - `RegistreFiscal`, pour le nom figé « Nom (cabinet X) » ;
   - `GardesDeLaVente` et `JournaliserFinDeSession`.
6. **La fin des experts-comptables de la société.** Le compte d'expert-comptable créé par le propriétaire dans sa société disparaît, avec son rôle, ses actions, ses écrans, la page `AucunMagasin` et ses tests sans objet. Les tests qui gardent leur intention sont réécrits avec un collaborateur d'un cabinet lié.
7. **CLAUDE.md, README et `.env.example`** sont réécrits (section 1).

### 0.2 Ordre de travail : quatre commits

Chaque commit est vert sur les deux moteurs, avec le code de sortie contrôlé juste après :

```
php artisan test --parallel ; echo $?
DB_CONNECTION=pgsql … php artisan test --parallel ; echo $?
```

Avant chaque commit : `vendor/bin/pint --dirty --format agent` et `php artisan migrate:fresh`. Après le dernier commit, la suite SQLite passe aussi sous faketime aux six instants du lot T1, horloge système en UTC, avec le code de sortie contrôlé après chacun :

```
for t in '2027-01-02 10:00:00' '2026-12-31 22:59:00' '2026-10-25 00:59:00' '2027-03-28 00:59:00' '2028-02-29 12:00:00' '2026-01-31 12:00:00'; do
  faketime -f "@$t" php artisan test --parallel ; echo "$? $t"
done
```

**Commit 1 — « Lot EC (1/4) : cabinet vérifié (69.20Z et Ordre), panneau du cabinet »**
- Ce qu'il fait :
  - la table `cabinets`, le modèle `Cabinet` ;
  - `GardesDesCabinets` (partie cabinets) ;
  - à l'inscription d'un cabinet, sur le formulaire et la confirmation du lot TA : la ligne `cabinets` avec le code lu à la confirmation, `ActiviteDuCabinetControlee`, la présélection de l'entrée « Cabinet d'expertise comptable » par `?type=cabinet` ;
  - la revérification mensuelle de l'activité d'un cabinet ;
  - l'interface `AnnuaireDeLOrdre` et ses pilotes `faux` et `editeur`, `VerifierLOrdreAction` ;
  - les procédures `editeur:cabinet:verifier-ordre` et `editeur:cabinet:refuser-ordre` (`editeur:cabinet:retirer-ordre`, qui met fin aux liens, vient au commit 3) ;
  - le panneau `cabinet` : `CabinetPanelProvider`, `TableauDeBordDuCabinet` avec la liste d'étapes du cabinet, qui remplace la liste réduite du lot TA, `VerificationDuCabinet`, `IdentiteDuCabinet` ;
  - dans `User` : `estDUnCabinet()`, `peutAdministrer()`, `canAccessPanel()`, `getTenants()`, `canAccessTenant()` ;
  - l'arrivée du propriétaire d'un cabinet dans l'espace du cabinet, après la confirmation de son inscription comme à chaque connexion.
- Ce qu'il ne fait pas, car le lot TA l'a fait : le type et sa garde, l'entrée du menu, la lecture du code d'activité (`UniteSirene`, `InseeSirene`, `FauxRepertoireSirene`, `ControleSirene`), le refus hors 69.20Z, le refus de tout magasin et de toute démonstration pour un cabinet.
- Tests : 6.1 (`CabinetInscriptionTest`, `VerificationOrdreTest`, `AnnuaireDeLOrdreTest`), 6.13 en partie, `AccesAuxPanneauxTest` réécrit pour trois panneaux, et les tests du lot TA que 6.19 marque « commit 1 » : dès ce commit, `peutAdministrer()` ferme l'espace de la société au cabinet, et ces tests y ouvraient des pages avec un cabinet.

**Commit 2 — « Lot EC (2/4) : collaborateurs du cabinet »**
- Ce qu'il fait :
  - `RoleSociete::Collaborateur`, ajouté à côté d'`ExpertComptable`, qui disparaît au commit 4 ;
  - les gardes rôle ↔ type de société ;
  - `CreerCollaborateurAction` ;
  - les cas du collaborateur dans `ChangerStatutCompteAction`, `RedonnerLAccesAction` (mode « présent » seul), `PorteeDesDroits` et `MembrePolicy` ;
  - `CollaborateurResource` ;
  - `PostesReconnus::ouvreLeCompte()`, faux pour un compte de cabinet ;
  - la fenêtre « Compte créé ».
- Tests : `CollaborateursTest`.

**Commit 3 — « Lot EC (3/4) : invitations, liens, autorisations accordées au cabinet, accès aux clients »**
- Ce qu'il fait :
  - les tables `invitations_cabinet`, `liens_cabinet`, `autorisations_cabinet` et `clients_confies`, avec leurs modèles et le reste des gardes ;
  - `PermissionRegistry::duCabinet()` et ses voisines ;
  - les accès : `AccesDesCabinets`, `User`, `ControleDesDroits`, `JournalDesDroits`, `RegistreFiscal`, `GardesDeLaVente`, le nom pour l'historique, `JournaliserFinDeSession` ;
  - toutes les actions de la section 3.5 ;
  - l'identité depuis le registre national (`RegistreNationalDesEntreprises::identite()`) ;
  - les notifications et les e-mails ;
  - les commandes d'expiration et de purge, et le job `RendreCaduquesLesInvitationsDuSiren` ;
  - la procédure `editeur:cabinet:retirer-ordre` (Q-EC5), qui met fin aux liens par `MettreFinAuLienAction::parLEditeur()` ;
  - les extensions de `ControleDesDroits` (lecture seule dans l'espace du cabinet, gestes d'accès d'un cabinet permis en lecture seule) ;
  - les écrans du cabinet, la page « Cabinet comptable » et la page de rattachement de la société, le contrôleur d'acceptation ou de refus d'une invitation et celui du lien d'une demande de rattachement ;
  - le tableau de bord multi-clients ;
  - les tests de concurrence PostgreSQL.
- Tests : 6.2 à 6.12, 6.14 à 6.18.

**Commit 4 — « Lot EC (4/4) : fin des experts-comptables de la société, CLAUDE.md »**
- Ce qu'il retire :
  - `RoleSociete::ExpertComptable` et toutes les API d'expert-comptable (liste en 3.10) ;
  - la page `AucunMagasin` et sa vue.
- Ce qu'il réécrit ou supprime :
  - les tests de la section 6.19 ;
  - les blocs de documentation (docblocks) qui parlent de l'expert-comptable ;
  - CLAUDE.md (section 1), README et `.env.example`.
- Le message de commit liste les tests supprimés et leur raison.

Après le commit 4 viennent la relecture adversariale par deux relecteurs, les corrections, les deux suites, la suite sous faketime, pint, le commit de relecture, le push et la CI verte.

### 0.3 Réponses de l'éditeur

Les questions Q-EC1 à Q-EC7 ont toutes reçu leur réponse le 24/10/2026 (`reponses-cabinets.md`). La section 8 donne chacune et ce qu'elle change. Ce cahier les applique partout : aucune porte partielle ne reste, et aucune partie du lot n'attend une réponse. Les choix de Claude listés en section 8 restent contestables par l'éditeur. Si l'implémenteur ou un relecteur trouve une règle métier que ni les décisions ni ce cahier ne tranchent, il la soumet à l'éditeur au lieu de choisir (règle 4 du protocole).

---

## 1. CLAUDE.md : texte exact

### 1.1 Nouvelle puce de « Décisions de l'éditeur »

Elle s'insère juste après la puce « Type d'activité » que le lot TA écrit après « Vérification de la société » (règle 1 des phrases de `decision-types-activite.md`). Elle applique les réponses de l'éditeur à Q-EC1 à Q-EC7 (section 8) : aucun passage n'attend plus de réponse.

> - Cabinets d'expertise comptable : un cabinet d'expertise comptable est une société PILIQI de type « Cabinet d'expertise comptable », choisi à son inscription dans le menu du type d'activité et définitif (voir « Type d'activité ») ; il s'inscrit et se valide comme toute société qui s'inscrit elle-même (SIREN unique, courrier recommandé), son code d'activité au répertoire Sirene doit être 69.20Z (activités comptables), contrôlé avant tout courrier, un code absent ou d'une autre nomenclature étant contrôlé par l'éditeur, et son inscription au tableau de l'Ordre des experts-comptables est vérifiée par l'annuaire de l'Ordre quand il offre un accès automatique utilisable (fournisseur externe, derrière une interface, avec une implémentation factice pour les tests et la démonstration, sans clé dans le dépôt), sinon une fois par l'éditeur (procédure de l'éditeur, inscrite au journal d'audit) ; tant que le cabinet n'est pas validé, contrôlé 69.20Z, vérifié à l'Ordre et actif, il n'invite, ne lie, ne voit ni n'ouvre aucun client, son propriétaire pouvant seulement mettre fin à ses liens ; sa révocation, ou sa radiation de l'Ordre constatée par l'éditeur, met fin à tous ses liens, clients et cabinet prévenus, et sa suspension ou sa résiliation suspend seulement l'accès. Un cabinet n'a ni magasin, ni magasin de démonstration, ni administrateur, ni membre : son propriétaire, qui voit tous ses clients, et ses collaborateurs, dont le propriétaire du cabinet seul crée, suspend, réactive, retire et réintègre les comptes et redonne l'accès, selon les règles des comptes de PILIQI (identifiant de 8 chiffres, premier mot de passe égal à l'identifiant ; faute de poste reconnu, le collaborateur choisit toujours son mot de passe en sa présence, sur l'écran du propriétaire du cabinet, à la création de son compte ou quand celui-ci lui redonne l'accès), et à qui il confie chaque client lié ; la propriété d'un cabinet se transmet comme celle d'une société, à un collaborateur actif, aux mêmes conditions qu'à un administrateur, l'ancien propriétaire devenant collaborateur, à qui tous les clients du cabinet sont confiés. Un compte d'un cabinet appartient au cabinet, seule exception à « un compte n'appartient qu'à une seule société » : il n'est jamais membre de la société d'un client, n'y a ni affectation, ni case, ni autorisation de société, et n'entre chez un client que par le lien de son cabinet ; une société n'a jamais de compte d'expert-comptable : un expert-comptable accède toujours à un client par le lien de son cabinet. Un cabinet ne voit que ses clients liés, et seulement ce que chacun autorise ; un collaborateur, seulement les clients qui lui sont confiés ; aucune donnée d'un client n'est jamais visible d'un autre client. L'espace du cabinet est un panneau à lui, distinct de l'espace d'administration d'une société et du panneau d'un magasin : la liste de ses clients liés, un tableau de bord qui les réunit dans la limite de ce que chacun autorise, et l'accès à chaque client et à chacun de ses magasins réels comme si le collaborateur y était connecté, selon les autorisations de ce client ; les calculs détaillés (écritures, export comptable, état de TVA, marges, rapports) s'y afficheront avec les étapes 5 et 6. Après son inscription, le propriétaire d'un cabinet arrive dans l'espace du cabinet, sur une liste d'étapes (saisir le code du courrier, faire vérifier l'inscription à l'Ordre, créer ses collaborateurs, inviter ses clients). Un client est une société PILIQI de type « Commerce ou atelier » ou « Services, sans vente d'articles », avec ou sans magasin ; il ne voit et ne lie qu'un cabinet vérifié et n'a qu'un cabinet lié à la fois : en lier un autre met fin au précédent ; un cabinet n'est jamais le client d'un autre cabinet. Du côté du cabinet, seul son propriétaire voit les invitations et les demandes, invite, demande, accepte, retire une invitation ou une demande et met fin à un lien ; il invite uniquement par le SIREN du client et l'adresse e-mail à laquelle part le lien, en redonnant son mot de passe ; l'e-mail nomme le cabinet qui invite, et la personne qui reçoit le lien accepte ou refuse ; une invitation ou une demande sans réponse expire après 7 jours, et le cabinet peut la retirer tant qu'elle n'est pas acceptée. (a) Une entreprise qui n'est pas encore inscrite : PILIQI contrôle au répertoire Sirene, à l'invitation puis de nouveau à l'acceptation, que l'entreprise existe et est active, sinon l'acceptation est refusée et rien n'est créé ; en acceptant, la personne choisit son mot de passe et le type d'activité de la société dans le menu de l'inscription, jamais « Cabinet d'expertise comptable », et devient propriétaire de la société, qui est validée sans courrier ni QR code, la preuve inscrite au journal d'audit étant l'invitation de ce cabinet, qui en répond ; les données légales de la société (dénomination, forme juridique, capital, immatriculation, adresse du siège, représentant légal, n° de TVA calculé depuis le SIREN) sont lues au registre national des entreprises (INPI) au moment de l'acceptation ou, quand il ne connaît pas l'entreprise, au répertoire Sirene, un registre injoignable faisant seulement réessayer plus tard, et le propriétaire complète ce qu'ils ne donnent pas ; n'ayant pas saisi le code du courrier, il signe les attestations à la main. (b) Une société déjà inscrite : quand le SIREN invité est déjà pris, l'invitation devient une demande de rattachement, dont l'e-mail part à l'adresse saisie et qui s'affiche aussi dans l'espace du propriétaire de la société ; le lien de l'e-mail mène, après la connexion de ce propriétaire, à une page de rattachement qui montre le cabinet et ses choix : accepter avec les autorisations par défaut, accepter en les ajustant, ou refuser ; tant que le lien n'est pas actif, le cabinet ne connaît de cette société que le SIREN qu'il a saisi ; le propriétaire d'une société inscrite peut aussi désigner lui-même un cabinet vérifié, cherché par SIREN ou par nom, et le lien naît quand le propriétaire du cabinet accepte, dans les 7 jours. Un rattachement ne valide jamais une société qui attend son code : le courrier recommandé ne sert qu'aux sociétés qui s'inscrivent elles-mêmes, et seule l'invitation d'un SIREN libre vaut validation ; le lien et les demandes d'une société non validée prennent fin quand son inscription est close sans validation ou quand une autre personne saisit le code du courrier et devient propriétaire, le cabinet en est prévenu, le nouveau propriétaire choisit lui-même son cabinet, et une société close ne gère plus de cabinet. Par défaut, le cabinet a, dans chacun des magasins réels du client, présents et futurs, toutes les autorisations de consultation (documents, paiements, achats, clôtures, fiches, registre et journal des événements, téléchargements ; jamais le journal d'audit ni les adresses IP et navigateurs) et, à l'étape 5, l'export comptable de la société, chaque export inscrit au journal d'audit ; le propriétaire du client, seul à désigner, accepter, régler et mettre fin au lien du côté du client, retire chacune de ces autorisations et, pour un client « Commerce ou atelier » seulement, en ajoute parmi celles des achats et du stock (factures d'achat, avoirs fournisseur, fournisseurs, inventaires, et la création des produits qu'ils font naître ; jamais celle d'un service) : jamais celles de la vente (ticket, facture, encaissement, vente sans stock, bon d'avoir), ni la gestion des comptes, ni les réglages de la société ou du magasin, ni la caisse et ses clôtures, ni les alertes de stock ; la base refuse toute autre autorisation pour un cabinet, toute autorisation des articles chez un client qui n'en a pas, et toute vente ou tout encaissement au nom d'un de ses comptes. Ces autorisations valent pour tout le cabinet, qui les répartit entre ses collaborateurs par les clients qu'il leur confie. Chaque action d'un compte du cabinet chez un client se fait en son nom, suivi du nom du cabinet, et suit les règles du client (lecture seule, validation, documents émis). Le propriétaire du client ou celui du cabinet met fin au lien quand il veut : l'accès s'arrête aussitôt, chaque session ouverte le perdant dès sa requête suivante, et la fin s'inscrit au journal d'audit des deux sociétés. Une société dont l'expert-comptable n'a pas encore d'espace l'invite par e-mail à en créer un. Au plus 50 invitations et demandes de lien par jour et par cabinet ; après le refus d'une invitation, ou après le refus, le retrait ou l'expiration d'une demande de lien, une partie ne renouvelle son invitation ou sa demande à la même autre partie qu'après 7 jours. L'abonnement d'un cabinet et la facturation des clients qu'il invite se décident à l'étape 12.

### 1.2 Phrases réécrites

**Protocole de rigueur, point 2.** Les unicités voulues restent celles que V2 laisse. Le lien actif unique par client et la demande unique par paire sont limités au client. Le cabinet qui invite un SIREN déjà inscrit apprend que ce SIREN est inscrit (son invitation devient une demande de rattachement), et la personne qui reçoit le lien de cette demande l'apprend aussi (l'e-mail et la page lui demandent la connexion du propriétaire de la société) : le point 2 le dit. V2 a déjà écrit l'exception de l'inscription ; EC n'y ajoute que l'invitation d'un cabinet. Remplacer « qu'aucun écran ni aucun message ne révèle à un tiers, hors le refus d'inscrire un SIREN déjà pris, qui ne dit rien d'autre. » par :
> qu'aucun écran ni aucun message ne révèle à un tiers, hors le refus d'inscrire un SIREN déjà pris, qui ne dit rien d'autre, et, à l'invitation d'un cabinet d'expertise comptable, le fait que le SIREN invité est déjà inscrit, appris par ce cabinet et par la personne qui reçoit le lien de sa demande de rattachement, sans rien d'autre de la société tant que son propriétaire n'a pas accepté.

**Produit, puce 1.** Le lot TA y a écrit « chacune avec le type d'activité choisi à son inscription ». Après « son créateur en est l'éditeur, il n'a ni société ni magasin. », ajouter :
> Un cabinet d'expertise comptable y est une société de type « Cabinet d'expertise comptable », sans magasin, qui suit ses clients (voir « Cabinets d'expertise comptable »).

**Produit, puce 2.**

1. Remplacer « Les données propres à une société (journal d'audit, vérifications, identifiants de connexion, transmissions de la propriété, et à l'étape 2 sa connexion et ses factures reçues SUPER PDP) utilisent `BelongsToSociete` + `SocieteScope`. » par :
   > Les données propres à une société (journal d'audit, vérifications, identifiants de connexion, transmissions de la propriété, du côté d'un client son lien avec un cabinet et les autorisations qu'il lui accorde, du côté d'un cabinet sa vérification à l'Ordre, ses invitations et les clients confiés à ses collaborateurs, et à l'étape 2 sa connexion et ses factures reçues SUPER PDP) utilisent `BelongsToSociete` + `SocieteScope` ; les comptes d'un cabinet appartiennent au cabinet et n'entrent chez un client que par le lien de leur cabinet.

2. Dans la liste des lectures permises, remplacer « à l'étape 5, les écritures et les documents de tous ses magasins réels, pour l'export comptable de la société produit par le propriétaire ou un expert-comptable ; » par :
   > à l'étape 5, les écritures et les documents de tous ses magasins réels, pour l'export comptable de la société produit par le propriétaire ou par le cabinet lié ; dans l'espace d'un cabinet d'expertise comptable vérifié et actif, pour son propriétaire et, chacun pour les clients qui lui sont confiés, ses collaborateurs : la liste de ses clients liés (dénomination, SIREN, ville du siège, date du lien) et de leurs magasins réels (nom, code, ville, statut), l'identité légale, le type d'activité et les réglages fiscaux communs de chacun, les autorisations qu'il accorde au cabinet et, au tableau de bord du cabinet, de chacun de ses magasins réels où il lui accorde de voir les factures de toute l'équipe, le nombre, le restant dû et le retard des factures à encaisser, sans aucun nom de client ni numéro de document ; pour le propriétaire d'un cabinet, les demandes de lien que des sociétés lui adressent (dénomination, SIREN, ville du siège), le seul SIREN de chaque demande qu'il a envoyée, et, même quand son cabinet n'est plus vérifié, la liste de ses liens actifs (dénomination, SIREN, date du lien), pour y mettre fin ; pour le propriétaire d'une société, la recherche des cabinets vérifiés (dénomination, SIREN, code postal et ville du siège, vingt au plus) et, du cabinet lié ou qui demande le lien, son identité, son état de vérification, son n° d'inscription à l'Ordre et le nom des personnes du cabinet qui ont accès à la société ; pour inscrire au journal des événements des magasins d'un client les droits des comptes de son cabinet lié : ces comptes, les magasins réels des clients de chacun, le lien avant d'en verrouiller les registres et, à la fin d'une session d'un de ces comptes, les magasins de clients qu'elle a ouverts ; pour adresser la demande de rattachement d'un cabinet qui invite un SIREN déjà inscrit, la société qui le tient et son type d'activité, jamais montrés ; pour les rendre caduques, les invitations ouvertes d'autres cabinets pour un SIREN désormais inscrit (identifiant et cabinet seulement) ; à la fin d'un lien, les clients confiés de ce lien, effacés depuis le contexte du client ; avant qu'aucune société ne soit ouverte, l'invitation d'un cabinet que désigne son lien, et la demande de rattachement que désigne son lien (le cabinet qui la fait, jamais la société à laquelle elle s'adresse, qui ne se montre qu'à son propriétaire connecté) ;

3. Remplacer « et, pour un même SIREN, l'existence d'une autre inscription (sans jamais son identité) » (texte de V2) par :
   > et, pour un même SIREN, l'existence d'une autre inscription (sans jamais son identité), y compris pour le cabinet qui invite ce SIREN et pour la personne qui reçoit le lien de sa demande de rattachement, la demande allant alors au propriétaire de la société inscrite, sans que le cabinet en apprenne rien d'autre tant que le lien n'est pas actif

**Comptes et équipe.**

| Phrase actuelle | Nouvelle phrase |
|---|---|
| « Comptes et équipe : un compte appartient pour toujours à une seule société et n'en change jamais : » | « Comptes et équipe : un compte appartient pour toujours à une seule société et n'en change jamais (les comptes d'un cabinet d'expertise comptable appartiennent au cabinet et n'entrent chez un client que par le lien de leur cabinet, voir « Cabinets d'expertise comptable ») : » |
| « tous les autres comptes (responsables, vendeurs, observateurs, administrateurs, experts-comptables) seulement avec leur identifiant et leur mot de passe. » | « tous les autres comptes (responsables, vendeurs, observateurs, administrateurs, collaborateurs d'un cabinet) seulement avec leur identifiant et leur mot de passe. » |
| « il ne touche jamais au propriétaire, à un autre administrateur ni à un expert-comptable. » | « il ne touche jamais au propriétaire ni à un autre administrateur. » |
| « jamais ceux de la société (identité et réglages fiscaux, création, fermeture, réouverture et cession de magasins, plateforme agréée, journal d'audit, administrateurs, experts-comptables et responsables) ; il ne crée, ne nomme ni ne retire jamais un responsable et ne touche jamais au compte d'un responsable, d'un administrateur, d'un expert-comptable ni du propriétaire. » | « jamais ceux de la société (identité et réglages fiscaux, création, fermeture, réouverture et cession de magasins, plateforme agréée, journal d'audit, administrateurs, responsables et cabinet lié) ; il ne crée, ne nomme ni ne retire jamais un responsable et ne touche jamais au compte d'un responsable, d'un administrateur ni du propriétaire. » |
| « un administrateur ou un expert-comptable qui devient membre l'est dans le magasin de son identifiant, » | « un administrateur qui devient membre l'est dans le magasin de son identifiant, » |
| « un responsable, un administrateur ou un expert-comptable choisit le sien à sa première connexion, » | « un responsable ou un administrateur choisit le sien à sa première connexion, » |
| « Tant que le mot de passe d'un compte est son identifiant, il n'ouvre ce compte que depuis un poste du magasin reconnu par le propriétaire ou un responsable ; ailleurs, la personne doit avoir choisi le sien. » | ajouter à la fin : « (un collaborateur d'un cabinet, qui n'a aucun poste reconnu, n'ouvre jamais son compte avec son identifiant : il choisit son mot de passe en sa présence, sur l'écran du propriétaire du cabinet qui crée son compte ou lui redonne l'accès) » |
| « Une société s'inscrit par un lien envoyé à l'adresse de son propriétaire, valable 24 heures ; » | « Une société s'inscrit par un lien envoyé à l'adresse de son propriétaire, valable 24 heures (une société invitée par un cabinet d'expertise comptable s'inscrit par le lien de son invitation, valable 7 jours, et naît validée, sans courrier, voir « Cabinets d'expertise comptable ») ; » |
| « sur une liste d'étapes (saisir le code du courrier recommandé, créer le premier magasin, ajouter l'équipe, essayer le magasin de démonstration ; un cabinet d'expertise comptable n'a que la première) » (texte du lot TA) | « sur une liste d'étapes (saisir le code du courrier recommandé, créer le premier magasin, ajouter l'équipe, essayer le magasin de démonstration ; le propriétaire d'un cabinet d'expertise comptable arrive dans l'espace du cabinet, sur la liste du cabinet, voir « Cabinets d'expertise comptable ») » |
| « à un administrateur actif, », dans la phrase de la transmission telle que V2b l'a écrite (« il la transmet, depuis l'espace de sa société, tant que son inscription n'est pas close, et en redonnant son mot de passe, à un administrateur actif, ») | « à un administrateur actif (dans un cabinet d'expertise comptable, à un collaborateur actif, aux mêmes conditions), » ; le reste de la phrase ne change pas |
| « l'ancien propriétaire devient administrateur, avec toutes les autorisations de magasin sauf celles de vente et toutes celles de la société, garde son identifiant et ne se connecte plus qu'avec lui ; » | « l'ancien propriétaire devient administrateur, avec toutes les autorisations de magasin sauf celles de vente et toutes celles de la société (dans un cabinet, collaborateur, à qui tous les clients du cabinet sont confiés), garde son identifiant et ne se connecte plus qu'avec lui ; » |
| « un propriétaire devenu propriétaire par transmission signe à la main. » (fin de la puce) | « un propriétaire devenu propriétaire par transmission, ou dont la société a été validée par l'invitation d'un cabinet d'expertise comptable, signe à la main. » |

Les phrases actuelles de cette table sont citées telles que le lot V2b les laisse (arbre de travail de sa relecture). Si V2b, FA1 à FA3, TA ou leurs relectures en ont écrit une autrement, EC garde leur texte et n'y ajoute que le cas du cabinet.

**Vérification de la société** (texte de V2, V2b et TA).
- Rien à changer à « une société s'inscrit avec son SIREN, son type d'activité et l'adresse e-mail et le mot de passe de son propriétaire, rien de plus » : le lot TA l'a écrit, et le cabinet y entre par son type.
- Dans la phrase « Avant la validation, aucune opération commerciale réelle (…) », le lot TA a écrit « un cabinet d'expertise comptable, qui n'a ni magasin ni démonstration, n'y configure que son identité ». « n'y configure que son identité » devient « y configure son identité et ses collaborateurs ».
- Remplacer la phrase « Saisir ce code valide la société : c'est la seule preuve (une autre voie ne s'y ajouterait que par décision de l'éditeur), sans prestataire de vérification d'identité, sans accord d'un dirigeant, sans pièces ni connexion à la plateforme agréée. » par :
  > Saisir ce code valide la société qui s'inscrit elle-même : c'est la seule preuve pour elle, sans prestataire de vérification d'identité, sans accord d'un dirigeant, sans pièces ni connexion à la plateforme agréée ; la seule autre voie, décidée par l'éditeur, est l'invitation acceptée d'un cabinet d'expertise comptable vérifié pour un SIREN libre (voir « Cabinets d'expertise comptable ») : le courrier recommandé ne sert qu'aux sociétés qui s'inscrivent elles-mêmes, et un rattachement à un cabinet ne valide jamais une société qui attend son code.
- Remplacer « Chaque fournisseur externe (INSEE, INPI, envoi recommandé, SUPER PDP) » par :
  > Chaque fournisseur externe (INSEE, INPI, envoi recommandé, annuaire de l'Ordre des experts-comptables, SUPER PDP)

**Type d'activité** (puce écrite par le lot TA, règle 1 des phrases de `decision-types-activite.md`).
- Dans la parenthèse de l'entrée du cabinet, remplacer « (sans magasin ni démonstration, code d'activité 69.20Z contrôlé avant tout courrier) » (texte du lot TA, qui n'écrit que ce qu'il rend vrai) par :
  > (sans magasin ni démonstration, code d'activité 69.20Z contrôlé avant tout courrier ; voir « Cabinets d'expertise comptable »)
- Avant « Le type ne se lit que », ajouter la phrase que seul EC rend vraie :
  > Un client invité par un cabinet choisit son type à l'acceptation, jamais « Cabinet d'expertise comptable ».
- Remplacer « Le type ne se lit que pour la société courante ou celle du magasin courant ; » par :
  > Le type ne se lit que pour la société courante ou celle du magasin courant, hors les lectures d'un cabinet d'expertise comptable listées dans « Produit » (type de chacun de ses clients liés, type de la société qui tient un SIREN qu'il invite, jamais montré) ;
- Si le lot TA ou sa relecture ont écrit ces passages autrement, EC garde leur texte et n'y fait que ces trois changements de sens.

**Société et établissements.** Le lot TA a déjà écrit la fin de la phrase (« le magasin de démonstration lui reste disponible dans les mêmes conditions, sauf à un cabinet d'expertise comptable, qui n'a aucun magasin »). EC remplace seulement « Une société sans magasin réel ne vend rien et n'est jamais facturée (étape 12) ; » par :
> Une société sans magasin réel ne vend rien et n'est jamais facturée (étape 12), hors un cabinet d'expertise comptable, dont l'abonnement se décide à l'étape 12 ;

**Prix et TVA des ventes.** « et qu'un expert-comptable n'a jamais ; » devient « et qu'un cabinet lié n'a jamais ; ».

**Ventes hors de France.** « et qu'un expert-comptable n'a jamais. » devient « et qu'un cabinet lié n'a jamais. ».

**Vendeurs.** « toutes celles du magasin pour le propriétaire, un responsable, un expert-comptable et un administrateur qui voit celles de toute l'équipe ; » devient :
> toutes celles du magasin pour le propriétaire, un responsable, un administrateur qui voit celles de toute l'équipe et un compte du cabinet lié à qui le client accorde de voir celles de toute l'équipe ;

**Achats et stock.** « pour les autres, cette autorisation n'est jamais précochée, et un expert-comptable ne l'a jamais) » devient « pour les autres, cette autorisation n'est jamais précochée, et un cabinet lié ne l'a jamais) ».

**Caisse et clôtures.** Dans la liste « seules s'effacent, à leur échéance : … les demandes d'inscription non confirmées, », ajouter après « les demandes d'inscription non confirmées, » (choix de Claude, section 8 : une invitation est une demande d'inscription faite par le cabinet, et suit leur délai, sauf une invitation refusée, gardée le temps du délai avant une nouvelle invitation) :
> les invitations d'un cabinet, acceptées ou non, 48 heures après leur fin, et 7 jours après pour une invitation refusée (l'acceptation et le refus restent prouvés au journal d'audit),

**E-mails.** « les alertes de stock bas ou négatif vont au propriétaire de la société, aux responsables du magasin et à toute autre personne qui a accès au magasin et y est autorisée à gérer le stock (administrateurs, vendeurs et observateurs). » devient (choix de Claude, section 8) :
> les alertes de stock bas ou négatif vont au propriétaire de la société, aux responsables du magasin et à toute autre personne qui a accès au magasin et y est autorisée à gérer le stock (administrateurs, vendeurs et observateurs), jamais à un compte d'un cabinet.

**Journal d'audit.** « les opérations importantes (factures, paiements, autorisations, procédures et réglages de la société) » devient « les opérations importantes (factures, paiements, autorisations, liens avec un cabinet, procédures et réglages de la société) ».

**Feuille de route.**

| Étape | Phrase actuelle | Nouvelle phrase |
|---|---|---|
| 1 | « experts-comptables de la société créés par le propriétaire, » | « espace des cabinets d'expertise comptable (cabinet vérifié à l'Ordre, collaborateurs et clients confiés, invitation d'un client par son SIREN, validé sans courrier, rattachement d'une société inscrite et autorisations accordées par le client), » |
| 1 bis | « des comptes d'entraînement dans le magasin de démonstration) » | « des comptes d'entraînement dans le magasin de démonstration ; un cabinet d'expertise comptable vérifié, avec son propriétaire et deux collaborateurs, lié à deux des sociétés, chaque collaborateur avec un client confié) » (ajout de Claude, contestable : la campagne doit couvrir l'accès d'un compte d'une autre société) |
| 5 | « export comptable de la société (FEC), produit et téléchargé par un expert-comptable ou le propriétaire, chaque export inscrit au journal d'audit, » | « export comptable de la société (FEC), produit et téléchargé par le propriétaire ou, si le client le lui accorde (accordé par défaut), par un compte du cabinet lié, chaque export inscrit au journal d'audit du client, écritures, export et état de TVA de chaque client affichés aussi dans l'espace du cabinet, » |
| 6 | « Tableau de bord et rapports : chiffre d'affaires, marges, TVA, meilleurs produits, stock, impayés. » | « Tableau de bord et rapports : chiffre d'affaires, marges, TVA, meilleurs produits, stock, impayés, affichés aussi dans l'espace du cabinet pour chacun de ses clients, dans la limite de ce qu'il autorise. » |
| 12 | « facturation des sociétés par l'éditeur ; » | « facturation des sociétés par l'éditeur ; abonnement d'un cabinet d'expertise comptable et facturation des clients qu'il invite ; » |
| 12 | « le magasin de démonstration, une société non validée et une société sans magasin réel ne sont jamais facturés ; » | « le magasin de démonstration, une société non validée et une société sans magasin réel, hors un cabinet d'expertise comptable, ne sont jamais facturés ; » |
| 13 | « libération d'un SIRET, » | « libération d'un SIRET, vérification et refus de l'inscription d'un cabinet à l'Ordre, constat de sa radiation, » (écrans des procédures `editeur:cabinet:*` du lot ; le lot TA a déjà réécrit le début de cette parenthèse, « clôture d'une inscription, pour refus ou type d'activité choisi par erreur, ») |

### 1.3 Phrases supprimées

1. Dans « Comptes et équipe », la phrase entière : « La société peut avoir plusieurs experts-comptables, chaque collaborateur du cabinet avec son propre compte dans chaque société qu'il suit, dont seul le propriétaire crée, suspend, réactive, retire et réintègre le compte et redonne l'accès : un expert-comptable n'a ni affectation ni case ; dans chacun des magasins réels de la société, présents et futurs, il a toutes les autorisations de consultation et aucune autre (documents, paiements, achats, clôtures, fiches, registre et journal des événements, téléchargements ; jamais le journal d'audit ni les adresses IP et navigateurs) ; il ne vend, ne modifie, ne supprime et n'envoie rien, ne gère aucun compte, signe ses propres consultations, et produit et télécharge lui-même l'export comptable de la société, chaque export étant inscrit au journal d'audit. » La puce de 1.1 la remplace.
2. Rien d'autre n'est supprimé.

Trois mentions restent telles quelles, car elles désignent la personne de l'expert-comptable de la société (dont le cabinet est ou non sur PILIQI), et non un compte :
- « cette règle est à faire confirmer par l'expert-comptable de la société le jour où le cas se présente » (Société et établissements) ;
- « une durée validée par l'expert-comptable de la société » (Caisse et clôtures) ;
- « confirmée par l'expert-comptable de la société » (Facture électronique).

Le relecteur vérifie qu'aucune autre mention d'un compte d'expert-comptable ne reste : `grep -n -i -E "expert-comptable|experts-comptables" CLAUDE.md` ne rend que cinq lignes : les trois puces de ces mentions, la puce de 1.1 et la puce « Vérification de la société » (l'annuaire de l'Ordre des experts-comptables parmi les fournisseurs externes). « Cabinet d'expertise comptable » ne désigne pas un compte, et ce motif ne l'attrape pas.

### 1.4 README et `.env.example`

- **README.** Le lot TA y dit déjà que l'inscription demande le type d'activité et qu'un cabinet s'inscrit en local avec `FauxRepertoireSirene::SIREN_CABINET`. Après ces phrases, ajouter :
  > L'espace d'un cabinet d'expertise comptable est `/cabinet/{uuid}` (clients liés, collaborateurs, invitations et demandes). L'inscription à l'Ordre se vérifie par la procédure `php artisan editeur:cabinet:verifier-ordre` tant qu'aucun accès automatique à l'annuaire de l'Ordre n'existe (pilote `ORDRE_PILOTE`). Le cabinet invite un client par son SIREN : un SIREN libre reçoit une invitation, acceptée sur `/invitation-cabinet/{jeton}` ; un SIREN déjà inscrit reçoit une demande de rattachement, ouverte par son propriétaire depuis `/rattachement-cabinet/{jeton}`.
- **`.env.example`.** Ajouter, avec son commentaire comme les autres pilotes :
  - `ORDRE_PILOTE=faux` ;
  - `CABINET_INVITATIONS_PAR_JOUR=50` (optionnel).
- **`phpunit.xml`.** Ajouter `<env name="ORDRE_PILOTE" value="faux"/>`.

---

## 2. Schéma

Chaque table changée l'est dans sa migration de création, modifiée sur place. Les tables nouvelles ont chacune une seule migration de création. Ensuite : `php artisan migrate:fresh`. `RetourArriereDesMigrationsTest` doit rester vert : chaque `down()` retire ses gardes avant sa table.

### 2.1 Tables changées

**`societes`** (`0001_01_00_000000_create_societes_table.php`)
- La colonne `type_activite` (string(20), non nulle, sans défaut, valeurs `commerce`, `services`, `cabinet`) et sa garde `societes_type_d_activite_connu_et_fixe`, qui refuse une valeur inconnue et toute modification, sont posées par le lot TA. EC ne les change pas.
- Index `index('type_activite')`, ajouté par EC, pour la recherche des cabinets (L4).
- `representant_legal` : V2 ne l'a pas créée (au HEAD `65819c6`, le nom du représentant n'est gardé que dans `codes_confirmation.destinataire`). EC ajoute une colonne string(255), nulle, avec ce commentaire : « nom et qualité du représentant légal tels que lus au registre national, à la validation par l'invitation d'un cabinet ; nulle quand le registre n'en donne pas », et la remplit à l'acceptation d'une invitation (3.5.6).
- Le docblock de la migration, que TA a mis à jour pour le type, ajoute qu'une société de type cabinet peut avoir sa ligne `cabinets` (voir `GardesDesCabinets`).
- Rien d'autre ne change. L'index d'unicité du SIREN est celui de V2.

**`users`** (`0001_01_01_000001_create_users_table.php`)
- `role_societe` : le commentaire devient « proprietaire, administrateur, membre ou collaborateur (voir RoleSociete) ; collaborateur seulement dans une société de type cabinet, administrateur et membre jamais (voir GardesDesDroits) ».
- Le docblock remplace « un expert-comptable » par « un collaborateur d'un cabinet », et `CreerExpertComptableAction` par `CreerCollaborateurAction`.
- Aucune colonne ne change. Les sessions restent filtrées par compte.

**`identifiants`, `affectations`, `autorisations_societe`.** Docblocks seulement : « expert-comptable » devient « collaborateur d'un cabinet ».

### 2.2 Tables nouvelles

Elles se placent après `2026_10_02_100020_create_transmissions_propriete_table.php`.

**`2026_10_02_100021_create_cabinets_table.php` → table `cabinets`.** Elle porte la vérification propre à un cabinet. Modèle `App\Domain\Cabinets\Models\Cabinet`, `BelongsToSociete`.

| Colonne | Type | Rôle |
|---|---|---|
| `id` | id | |
| `societe_id` | foreignId, `unique`, constrained `societes`, restrictOnDelete | |
| `code_activite` | string(6), nulle | code APE lu au répertoire Sirene à la confirmation de l'inscription (ex. `69.20Z`), le même que l'entrée d'audit `SocieteInscrite` du lot TA |
| `nomenclature_activite` | string(10), nulle | `NAFRev2`, telle que lue |
| `activite_controlee_le` | timestamp, nul | posé quand le répertoire a donné 69.20Z en NAFRev2, ou par la procédure de l'éditeur pour un code absent ou d'une autre nomenclature |
| `numero_ordre` | string(30), nulle | n° d'inscription au tableau, déclaré par le propriétaire ou constaté |
| `conseil_regional_ordre` | string(100), nulle | |
| `ordre_demande_le` | timestamp, nul | une vérification attend l'éditeur |
| `ordre_verifie_le` | timestamp, nul | |
| `ordre_voie` | string(20), nulle | `annuaire` ou `editeur` (`VoieDeVerificationOrdre`) |
| `ordre_retire_le` | timestamp, nul | radiation constatée par l'éditeur (Q-EC5) |
| `timestamps` | | |

- `DeclencheursDeProtection::sansSuppression('cabinets')`.
- La garde (2.3) refuse une ligne pour une société qui n'est pas de type cabinet. Le type d'une société ne change jamais (garde du lot TA) : une société qui a cette ligne reste un cabinet.
- La ligne naît à l'inscription d'un cabinet, dans la transaction qui crée la société (`CreerSocieteAction` du lot TA), et seulement là : aucune société ne devient un cabinet après sa création (Q-EC2, lot TA).

**`2026_10_02_100022_create_invitations_cabinet_table.php` → table `invitations_cabinet`.** Modèle `InvitationCabinet`, `BelongsToSociete` : sa société est le cabinet. Il utilise aussi `FigeLeNomDeSesAuteurs`.

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `uuid` | | |
| `societe_id` | foreignId constrained `societes` | le cabinet |
| `siren` | char(9) | |
| `email` | string | en minuscules, `User::normaliserEmail()` ; donnée personnelle, effacée avec l'invitation par la purge (3.6) |
| `jeton_hache` | char(64), unique | SHA-256 du jeton du lien, jamais le jeton |
| `invitee_par` | foreignId users | |
| `invitee_par_nom` | string | nom figé |
| `etat` | string(20) | `ouverte`, `acceptee`, `refusee`, `retiree`, `expiree`, `caduque` (`EtatInvitation`) |
| `expire_le` | timestamp | |
| `close_le` | timestamp, nul | posé à toute sortie de l'état `ouverte` (acceptée, refusée, retirée, expirée, caduque) |
| `retiree_par` | foreignId users, nul | |
| `timestamps` | | |

- L'invitation ne sert qu'à un SIREN libre au moment où le cabinet l'envoie. Un SIREN déjà pris reçoit une demande de rattachement, ligne de `liens_cabinet` (3.5.4), jamais une invitation.
- Contraintes :
  - FK composite `(societe_id, invitee_par)` → `users(societe_id, id)` ;
  - index unique partiel `invitations_cabinet_une_ouverte_par_siren` sur `(societe_id, siren) WHERE etat = 'ouverte'` ;
  - contrainte `CHECK ((etat = 'ouverte') = (close_le IS NULL))` : une invitation close a toujours sa date de fin, et la purge n'en oublie aucune ;
  - index `(etat, expire_le)` pour l'expiration, `(siren, etat)` pour rendre caduques les autres invitations, `(societe_id, siren, close_le)` pour le délai après un refus, `(close_le)` pour la purge.
- Pas de protection contre la suppression : la purge (3.6) efface toute invitation close 48 heures après `close_le`, acceptée ou non, comme une demande d'inscription, et une invitation refusée 7 jours après, le temps du délai avant une nouvelle invitation du même SIREN (choix de Claude, section 8). L'acceptation reste prouvée par le journal d'audit des deux sociétés (`SocieteValidee` avec la preuve et le cabinet, `InvitationAcceptee`) et par le lien, d'origine `invitation` ; le refus, par l'entrée `InvitationRefusee` du journal du cabinet. Aucune table ne référence donc une invitation.

**`2026_10_02_100023_create_liens_cabinet_table.php` → table `liens_cabinet`.** Modèle `LienCabinet`, `BelongsToSociete` : sa société est le client, celle qui accorde l'accès. Il utilise aussi `FigeLeNomDeSesAuteurs`.

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `uuid` | | |
| `societe_id` | foreignId `societes` | le client |
| `cabinet_id` | foreignId `societes` | |
| `origine` | string(30) | `invitation` (SIREN libre, invitation acceptée), `demande_du_cabinet` (SIREN déjà pris : la demande de rattachement née de l'invitation du cabinet), `designation_du_client` (`OrigineDuLien`) ; aucune colonne ne désigne l'invitation, que la purge efface |
| `etat` | string(30) | `demande_par_le_cabinet`, `demande_par_le_client`, `actif`, `refuse`, `retire`, `expire`, `termine` (`EtatDuLien`) |
| `demande_par` | foreignId users, nul | nul pour un lien né d'une invitation |
| `demande_par_nom` | string, nul | nom figé |
| `demande_le` | timestamp | |
| `expire_le` | timestamp, nul | pour une demande |
| `jeton_hache` | char(64), nul, unique | SHA-256 du jeton du lien de l'e-mail d'une demande de rattachement (`demande_par_le_cabinet`), jamais le jeton ; effacé dès que la demande n'est plus en cours |
| `repondu_par` | foreignId users, nul | |
| `repondu_par_nom` | string, nul | nom figé |
| `repondu_le` | timestamp, nul | |
| `actif_depuis` | timestamp, nul | |
| `termine_par` | foreignId users, nul | nul pour le système ou l'éditeur |
| `termine_par_nom` | string, nul | nom figé |
| `termine_le` | timestamp, nul | |
| `motif_fin` | string(30), nul | `par_le_client`, `par_le_cabinet`, `remplace`, `cabinet_plus_verifie` (Q-EC5), `proprietaire_remplace`, `inscription_close` (Q-EC7) (`MotifDeFinDuLien`) ; le lot A7 y ajoute `societe_revoquee` (3.5.14) |
| `timestamps` | | |

- Contraintes :
  - `CHECK (jeton_hache IS NULL OR etat = 'demande_par_le_cabinet')` : le lien de l'e-mail ne vaut plus rien dès que la demande est acceptée, refusée, retirée ou expirée, et chaque transition qui en sort efface le jeton dans la même écriture ;
  - `unique(['societe_id', 'id'])` et `unique(['cabinet_id', 'id'])`, cibles des FK composites ;
  - index unique partiel `liens_cabinet_un_actif_par_client` sur `(societe_id) WHERE etat = 'actif'` (règle 6 : un seul cabinet lié à la fois) ;
  - index unique partiel `liens_cabinet_une_demande_par_paire` sur `(societe_id, cabinet_id) WHERE etat IN ('demande_par_le_cabinet', 'demande_par_le_client')` ;
  - index `(cabinet_id, etat)` pour les listes du cabinet ;
  - index `(etat, expire_le)` pour l'expiration.
- `DeclencheursDeProtection::sansSuppression('liens_cabinet')` : l'historique des liens se garde.

**`2026_10_02_100024_create_autorisations_cabinet_table.php` → table `autorisations_cabinet`.** Les autorisations que le client accorde au cabinet pour ce lien.
- Pas de modèle, comme `autorisations_affectations`. Elles se lisent et s'écrivent par `LienCabinet::autorisations()` et `LienCabinet::accorder(list<string>)`.
- Colonnes : `societe_id` (le client), `lien_id`, `autorisation` string(60).
- Clé primaire `(lien_id, autorisation)`.
- FK composite `(societe_id, lien_id)` → `liens_cabinet(societe_id, id)`.
- FK `autorisation` → `referentiel_autorisations_magasin(cle)` restrictOnDelete.
- Les lignes s'effacent et se réécrivent quand le client règle les autorisations. L'historique est au JET 130 et au journal d'audit.

**`2026_10_02_100025_create_clients_confies_table.php` → table `clients_confies`.** Modèle `ClientConfie`, `BelongsToSociete` : sa société est le cabinet. Il utilise aussi `FigeLeNomDeSesAuteurs`.
- Colonnes : `id`, `societe_id` (le cabinet), `lien_id`, `user_id` (le collaborateur), `confie_par` (FK users), `confie_par_nom`, `created_at`.
- Clé primaire `id`, et `unique(['lien_id', 'user_id'])` : un client n'est confié qu'une fois au même collaborateur. Eloquent ne gère pas une clé primaire composite : l'insertion par le modèle lit l'`id` créé (`RETURNING "id"` sous PostgreSQL), et la suppression d'une instance filtre sur sa clé. Les tables du dépôt à clé composite (`autorisations_affectations`, `autorisations_societe`) n'ont d'ailleurs pas de modèle. `ClientConfie` en a un, pour `BelongsToSociete`, `FigeLeNomDeSesAuteurs`, le test d'isolation et le retrait d'un collaborateur par le modèle : il lui faut donc `id`.
- FK composite `(societe_id, user_id)` → `users(societe_id, id)`.
- FK composite `(societe_id, lien_id)` → `liens_cabinet(cabinet_id, id)`. Une ligne ne désigne donc jamais le lien d'un autre cabinet ni le compte d'une autre société.
- Les lignes d'un lien s'effacent à sa fin, et celles d'un collaborateur à son retrait. La fin d'un lien se traite dans le contexte du client, où `SocieteScope` filtrerait `clients_confies` sur le client et n'effacerait rien : l'effacement passe par `DB::table('clients_confies')->where('societe_id', $lien->cabinet_id)->where('lien_id', $lien->id)->delete()` (section 5, L12). Le retrait d'un collaborateur se fait dans le contexte du cabinet, par le modèle.
- Cette migration pose `GardesDesCabinets::poser()`, dont `down()` retire les gardes avant la table.

### 2.3 Gardes en base

Elles suivent le modèle de `GardesDesDroits` : PostgreSQL plpgsql et un déclencheur BEFORE par garde ; SQLite avec `RAISE(ABORT)` ; tout autre pilote fait échouer la migration.

**Nouvelle classe `App\Domain\Cabinets\Support\GardesDesCabinets`**, posée par la migration de `clients_confies`. Le lot TA pose déjà, dans `GardesDesTypesDActivite`, la garde du type (`societes_type_d_activite_connu_et_fixe` : valeur connue, aucune modification) et `magasins_jamais_dans_un_cabinet` (aucun magasin, démonstration comprise, dans un cabinet) : EC ne les repose pas. Ses gardes lisent `societes.type_activite`.

| Garde | Table, moment | Ce qu'elle refuse |
|---|---|---|
| `cabinets_de_type_cabinet` | `cabinets`, BEFORE INSERT OR UPDATE OF societe_id | une ligne pour une société dont `type_activite` n'est pas `cabinet` |
| `invitations_d_un_cabinet` | `invitations_cabinet`, BEFORE INSERT | une invitation d'une société dont `type_activite` n'est pas `cabinet` |
| `liens_entre_un_cabinet_et_un_client` | `liens_cabinet`, BEFORE INSERT OR UPDATE | voir le détail sous le tableau |
| `autorisations_cabinet_permises` | `autorisations_cabinet`, BEFORE INSERT OR UPDATE | voir le détail sous le tableau |
| `clients_confies_a_un_collaborateur` | `clients_confies`, BEFORE INSERT | un compte dont le rôle n'est pas `collaborateur` ; un lien qui n'est pas `actif` |

Détail de `liens_entre_un_cabinet_et_un_client` :
- refusé : un `cabinet_id` dont la société n'est pas de type `cabinet` ;
- refusé : un `societe_id` dont la société est de type `cabinet` (un client est « Commerce ou atelier » ou « Services, sans vente d'articles ») ;
- refusé : `cabinet_id = societe_id` ;
- refusé : une transition qui n'est pas permise.
- Transitions permises :
  - à l'insertion : `demande_par_le_cabinet`, `demande_par_le_client`, ou `actif` avec `origine = 'invitation'` ;
  - une demande devient `actif`, `refuse`, `retire` ou `expire` ;
  - un lien `actif` devient `termine` ;
  - tout autre état est final ;
  - `societe_id`, `cabinet_id` et `origine` ne changent jamais.

Détail de `autorisations_cabinet_permises` :
- refusé : une autorisation absente de `PermissionRegistry::duCabinet(TypeDActivite::Commerce)`, la liste complète, écrite en littéraux SQL par la garde (« la garde qui interdit à un compte de cabinet les autorisations de vente ») ;
- refusé : une autorisation de la famille `articles` (colonne `famille` du référentiel, posée par le lot TA) sur le lien d'un client dont `type_activite` n'est pas `commerce` ;
- refusé : une ligne sur un lien qui n'est pas `actif`.

**`GardesDesDroits` étendue** (`app/Domain/Tenancy/Support/GardesDesDroits.php`) :
- `$sansAffectation` vaut `[Proprietaire, Collaborateur]`, et `$horsDuMagasin` vaut `[Proprietaire, Administrateur, Collaborateur]`. `ExpertComptable` disparaît au commit 4.
- Nouveaux refus dans la garde des rôles (`users_role_coherent_avec_les_droits`) :
  - « `NEW.role_societe = 'collaborateur' AND COALESCE((SELECT type_activite FROM societes WHERE id = NEW.societe_id), '') <> 'cabinet'` » → « un collaborateur n'existe que dans un cabinet » ;
  - « `NEW.role_societe IN ('administrateur', 'membre') AND (SELECT type_activite FROM societes WHERE id = NEW.societe_id) = 'cabinet'` » → « un cabinet n'a ni administrateur ni membre ».
- Ces deux refus se lisent à l'insertion et à la modification de `role_societe` (colonnes déjà déclenchantes).
- Les messages d'expert-comptable sont réécrits.

**`GardesDeLaVente` étendue** (`app/Domain/Facturation/Support/GardesDeLaVente.php`) :
- `auteurProprietaire()` devient `auteurQuiNeVendJamais()`, avec la condition « `role_societe IN ('proprietaire', 'collaborateur')` ». Le propriétaire d'un cabinet est un propriétaire.
- `MESSAGE` : « Le propriétaire d'une société et les comptes d'un cabinet ne vendent jamais : aucune vente ni aucun encaissement ne s'inscrit à leur nom. »
- Les trois migrations qui posent la garde (`factures`, `paiements`, `enregistrements_fiscaux`) ne changent pas : la garde se repose par `migrate:fresh`.
- Le docblock dit pourquoi le rôle suffit : un compte de cabinet a toujours le rôle `proprietaire` ou `collaborateur`, car la garde des rôles l'impose.

### 2.4 Contraintes héritées de V2

- **SIREN unique.** L'index d'unicité de V2 arrête la seconde de deux acceptations simultanées du même SIREN. `AccepterLInvitationAction` intercepte la `UniqueConstraintViolationException` de cet index par son nom (comme `CreerSocieteAction` le fait pour `users_adresse_de_connexion`) et la traduit en refus clair.
- **Adresse de connexion d'un propriétaire.** L'index `users_adresse_de_connexion` vaut pour les propriétaires de cabinet comme pour les autres, tel que V2b l'a laissé : il ne porte que sur les adresses non nulles, et la clôture d'une inscription efface l'adresse de son propriétaire, qui peut alors servir à une invitation (`reponses-v2.md`, point 3). Deux acceptations simultanées de deux invitations à la même adresse, pour deux SIREN différents, passent toutes deux le contrôle de l'étape 4 de 3.5.5 : `AccepterLInvitationAction` intercepte aussi la violation de cet index par son nom, comme `CreerSocieteAction` (l.118-124), et rend le message de cette étape, rien n'étant créé.

### 2.5 Carte des morphismes et policies

- `AppServiceProvider::boot()`, carte des morphismes (l.181) : ajouter `'cabinet' => Cabinet::class`, `'lien_cabinet' => LienCabinet::class` et `'invitation_cabinet' => InvitationCabinet::class`, cibles d'entrées d'audit. Une invitation effacée par la purge laisse ses entrées d'audit, en ajout seul : leur `cible_id` ne désigne plus rien, et leurs valeurs portent le SIREN (`EntreeAudit` ne charge pas sa cible).
- `Gate::policy` (l.121-139) : ajouter `LienCabinet::class => LienCabinetPolicy::class`, `InvitationCabinet::class => InvitationCabinetPolicy::class` et `Cabinet::class => CabinetPolicy::class`.

---

## 3. Domaine

Un nouveau dossier de domaine `app/Domain/Cabinets` est créé : Actions, Enums, Mail, Models, Notifications, Services, Support. Ce n'est pas un nouveau dossier de base : `app/Domain` en a déjà neuf.

### 3.1 Énumérations

| Énumération | Fichier | Cas, libellé |
|---|---|---|
| `TypeDActivite` (lot TA) | `app/Domain/Tenancy/Enums/` | inchangée : `Commerce`, `Services`, `Cabinet`, posée par le lot TA ; EC n'y ajoute rien et ne crée aucune autre énumération de type |
| `RoleSociete` | modifiée | ajouter `Collaborateur = 'collaborateur'` (« Collaborateur du cabinet ») ; `ExpertComptable` supprimé au commit 4 ; docblock réécrit |
| `EtatDuLien`, `OrigineDuLien`, `MotifDeFinDuLien`, `EtatInvitation`, `VoieDeVerificationOrdre` | `app/Domain/Cabinets/Enums/` | valeurs de la section 2.2, libellés français (`EtatInvitation` compte `Refusee`) |
| `PreuveDeValidation` (V2) | celle de V2 | ajouter `InvitationDuCabinet = 'invitation_cabinet'` (« Invitation du cabinet ») |
| `ActionAuditee` | modifiée | voir la liste ci-dessous |

Nouveaux cas d'`ActionAuditee`, avec leur catégorie et leurs champs (`champsAudites()`) :

| Cas | Catégorie | Champs |
|---|---|---|
| `ActiviteDuCabinetControlee` | Verification | `['code_activite', 'nomenclature', 'resultat']` |
| `OrdreDemande` | Verification | `['numero_ordre', 'conseil_regional']` |
| `OrdreVerifie` | Verification | `['voie', 'numero_ordre', 'conseil_regional']` |
| `OrdreRefuse` | Verification | `['motif']` |
| `OrdreRetire` | Verification | `['motif']` (Q-EC5) |
| `CollaborateurCree` | Acces | `['droits']` |
| `ClientsConfiesModifies` | Acces | `['droits']` (les droits des comptes touchés dans les magasins des clients, voir `JournalDesDroits`) |
| `InvitationEnvoyee` | Acces | `['siren']` (jamais l'adresse e-mail : « sans donnée personnelle inutile ») |
| `InvitationRetiree` | Acces | `['siren']` |
| `InvitationAcceptee` | Acces | `['siren', 'client']` |
| `InvitationRefusee` | Acces | `['siren']` (journal du cabinet seul : aucune société n'existe) |
| `InvitationCaduque` | Acces | `['siren', 'raison']` |
| `LienDemande` | Acces | `['cabinet', 'client', 'origine']` |
| `LienRefuse` | Acces | `['cabinet', 'client']` |
| `DemandeDeLienRetiree` | Acces | `['cabinet', 'client']` |
| `DemandeDeLienExpiree` | Acces | `['cabinet', 'client']` |
| `LienActive` | Acces | `['cabinet', 'client', 'origine', 'droits']` |
| `LienTermine` | Acces | `['cabinet', 'client', 'motif', 'droits']` |
| `AutorisationsDuCabinetModifiees` | Acces | `['cabinet', 'droits']` |
| `EspaceCabinetSuggere` | Operations | `['envoye']` (l'adresse du cabinet invité ne s'écrit pas) |

- `SocieteInscrite` retient déjà `type_activite`, `code_activite` et `nomenclature_activite` (lot TA). EC y ajoute `'origine'` : `inscription` (la société s'inscrit elle-même) ou `invitation` (invitation acceptée d'un cabinet).
- `SocieteValidee` retient en plus `'cabinet'` (identifiant, SIREN et dénomination du cabinet qui a invité), vide pour une autre preuve.
- `LienDemande` sert aussi à la demande de rattachement née de l'invitation d'un SIREN déjà pris (`origine = demande_du_cabinet`), sans l'adresse saisie. `LienActive` retient en plus, pour une demande acceptée sur la page de rattachement, `'autorisations'` : `par_defaut` ou `ajustees`.
- `ExpertComptableCree` est supprimé au commit 4.
- Les valeurs `cabinet` et `client` sont `['id', 'siren', 'raison_sociale']`, sauf dans le journal d'un cabinet pour une demande qu'il a envoyée et qui n'est pas devenue un lien actif (`LienDemande`, `LienRefuse`, `DemandeDeLienRetiree`, `DemandeDeLienExpiree`) : `client` n'y porte que `['id', 'siren']`, car le cabinet ne connaît de cette société que le SIREN qu'il a saisi.

### 3.2 Modèles

**`Societe`** (`app/Domain/Tenancy/Models/Societe.php`) :
- le cast `'type_activite' => TypeDActivite::class`, `estUnCabinet()` et `aDesArticles()` existent déjà (lot TA) ; `type_activite` n'est jamais dans `$fillable` ;
- `cabinet(): HasOne<Cabinet>`, en `->withoutGlobalScope(SocieteScope::class)` : la relation filtre déjà par `societe_id`. Sans cela, `SocieteScope` (fermé par défaut) lèverait `TenantContextMissingException` hors contexte (Filament appelle `canAccessTenant()`, donc `peutOuvrir()`, avant que `BindTenantContextFromFilamentTenant` pose le contexte ; l'acceptation d'une invitation n'a pas de contexte), et filtrerait sur le client dans son contexte (`estUnCabinetVerifie()` rendrait toujours faux dans `modifierLeLien()`, dans un magasin du client et dans `DesignerUnCabinetAction`). Lecture L13 de la section 5 ;
- `estUnCabinetVerifie(): bool` : `estUnCabinet()`, `estValidee()`, `statut === StatutSociete::Active` (Q-EC5), `cabinet->activite_controlee_le !== null`, `cabinet->ordre_verifie_le !== null` et `ordre_retire_le === null` ;
- `lienActif(): HasOne<LienCabinet>` côté client, en `withoutGlobalScope(SocieteScope::class)` filtré par `societe_id` et `etat = actif`.

**`Cabinet`** : `BelongsToSociete`, `societe()`, `estVerifieALOrdre()`, `aSonActiviteControlee()`.

**`InvitationCabinet`** : `BelongsToSociete`, `FigeLeNomDeSesAuteurs` (`invitee_par → invitee_par_nom`), et :
- `estOuverte(): bool` : `etat === Ouverte && expire_le->isFuture()` ;
- `static parSonJeton(string $jeton): ?self` : la seule lecture hors société. Elle passe par `withoutGlobalScopes()`, cherche par `jeton_hache = hash('sha256', $jeton)` et ne sélectionne que les colonnes nécessaires.

**`LienCabinet`** : `BelongsToSociete` (client), `FigeLeNomDeSesAuteurs` (`demande_par`, `repondu_par`, `termine_par`). Ses relations sont `client()` et `cabinet()` (vers `Societe`). Aucune relation ne désigne une invitation, que la purge efface. Ses méthodes :
- `autorisations(): list<string>`, lu dans `autorisations_cabinet` filtré par `societe_id` et `lien_id`, trié ;
- `accorder(list<string>): void`, qui vérifie `PermissionRegistry::verifierDuCabinet($autorisations, $client->type_activite)` ;
- `static parSonJeton(string $jeton): ?self` : la lecture du lien d'une demande de rattachement, avant toute société ouverte (section 5, L15). Elle passe par `withoutGlobalScopes()`, cherche par `jeton_hache = hash('sha256', $jeton)` et `etat = demande_par_le_cabinet`, et ne sélectionne que `id`, `uuid`, `societe_id`, `cabinet_id`, `etat` et `expire_le` ;
- `comptesQuiOntAcces(): Collection<User>` ;
- `scopeDuCabinet(Builder, Societe|int)`, qui retire `SocieteScope` et filtre `cabinet_id` : la seule façon de lister les liens depuis le cabinet ;
- `estActif()`, `estUneDemande()`.

**`ClientConfie`** : `BelongsToSociete` (cabinet), `FigeLeNomDeSesAuteurs` (`confie_par`), clé primaire `id` (2.2).

**`User`** (`app/Models/User.php`) :

| Méthode | Changement |
|---|---|
| `estDUnCabinet(): bool` | nouvelle : `$this->societe?->estUnCabinet() === true`, relation chargée une fois |
| `estCollaborateur(): bool` | nouvelle |
| `estExpertComptable()` | supprimée au commit 4 |
| `peutAdministrer()` (l.708) | `estActif() && (estProprietaire() \|\| estAdministrateur()) && ! estDUnCabinet()` : le propriétaire d'un cabinet n'ouvre jamais le panneau `societe` |
| `doitChoisirSonMotDePasse()` (l.338) | administrateur ou responsable ; l'expert-comptable disparaît au commit 4, et le collaborateur n'y entre pas : il n'ouvre jamais son compte avec son identifiant (`PostesReconnus::ouvreLeCompte()` rend faux pour un compte de cabinet, voir 3.5.2) |
| `autorisationsDeSociete()` (l.403) | pour un compte de cabinet : `AutorisationsSociete::duProprietaireDUnCabinet()` (= `[PARAMETRES_GERER]`, l'identité du cabinet) pour son propriétaire, `[]` pour un collaborateur |
| `magasinsDeSaPortee()` (l.458) | inchangée : vide pour un compte de cabinet |
| `autorisationsDans(Magasin)` (l.505) | la condition de société devient `! $this->peutAgir() \|\| ((int) $magasin->societe_id !== (int) $this->societe_id && ! $this->estDUnCabinet())` → `[]` ; un compte de cabinet passe par `autorisationsEnregistreesDans()` |
| `autorisationsEnregistreesDans()` (l.649) | branche en tête, avant celle du propriétaire (voir sous le tableau) |
| `droitsDans(Magasin)` (l.569) | pour un compte de cabinet, la vérification de société est remplacée par la branche du cabinet (voir sous le tableau) |
| `peutOuvrir(Magasin)` (l.720) | pour un compte de cabinet : `peutAgir()` et `AccesDesCabinets::ouvre($this, $magasin)` (voir sous le tableau) ; la branche d'expert-comptable disparaît |
| `magasinsAccessibles()` (l.744) | pour un compte de cabinet : `AccesDesCabinets::magasinsOuverts($this)` (magasins réels des clients liés et confiés, actifs ou non, triés par dénomination du client puis nom du magasin, vide tant que le cabinet n'est pas vérifié) |
| `getTenants(Panel)` (l.791) | voir sous le tableau |
| `canAccessTenant(Model)` (l.800) | `Magasin` → `peutOuvrir()` ; `Societe` → même société et (`peutAdministrer()` ou (`estDUnCabinet()` et `estActif()`)) |
| `canAccessPanel(Panel)` (l.824) | `'app'` → `estActif()` ; `'societe'` → `peutAdministrer()` ; `'cabinet'` → `estActif() && estDUnCabinet()` ; sinon `false` ; docblock réécrit |
| `libelleDuRoleDeSociete()` (l.289) | propriétaire d'un cabinet : « Propriétaire du cabinet {dénomination} » ; collaborateur : « Collaborateur du cabinet {dénomination} » |
| `libelleDuRoleDans(Magasin)` (l.306) | compte de cabinet : `libelleDuRoleDeSociete()` suivi de « · » et du nom du magasin |
| `nomPourLHistorique(): string` | nouvelle : le nom ; pour un compte de cabinet, « {name} (cabinet {raison_sociale du cabinet, sinon SIREN du cabinet}) », borné à 255 caractères (la dénomination est raccourcie d'abord, puis le nom), car `name` (255) et `raison_sociale` (200) dépasseraient les colonnes `*_nom` et `auteur_libelle` (255), ce que PostgreSQL refuse |
| `static nomFigeDe(int $id): ?string` | nouvelle : relit le compte et sa société et rend `nomPourLHistorique()` |
| `suitLesFacturesAEncaisserDans()` (l.528) | inchangée : un compte de cabinet n'est pas membre, il lui faut donc `facture.view` et `facture.view_toutes` dans les autorisations du lien ; docblock réécrit |
| `tientUneCaisseDans()` | inchangée : un cabinet n'a jamais `facture.create` ni `paiement.creer` |

Détail de `autorisationsEnregistreesDans()` pour un compte de cabinet :
- elle rend `AccesDesCabinets::autorisationsEnregistrees($this, $magasin)` : les autorisations du lien actif entre le cabinet du compte et la société du magasin (`liensDuCompte()`, section 5, L2), intersectées avec `PermissionRegistry::duCabinet($magasin->societe->type_activite)`, donc avec `pourLeType()` du client (lot TA), triées ;
- seulement si le magasin est réel, que le compte n'est pas retiré, et qu'il est propriétaire du cabinet ou qu'une ligne de `clients_confies` lui confie ce lien ;
- `null` sinon.

Elle ne regarde pas l'état du cabinet (vérifié, actif) : c'est le contrôle dynamique de `peutOuvrir()` et de `ControleDesDroits` qui le fait, comme la lecture seule d'un magasin.

Détail de `droitsDans(Magasin)` pour un compte de cabinet : `['role_societe', 'role_magasin' => null, 'statut', 'permissions', 'cabinet' => ['id', 'siren', 'raison_sociale']]`, ou `null`.

Détail de `peutOuvrir(Magasin)` pour un compte de cabinet, `AccesDesCabinets::ouvre($this, $magasin)` exige :
- un magasin réel ;
- un lien actif entre le client et le cabinet ;
- un client confié, ou le propriétaire du cabinet ;
- `societe->estUnCabinetVerifie()`.

Détail de `getTenants(Panel)` :
- `'app'` pour un compte de cabinet : les magasins ouverts du seul client du magasin actif de Filament (`Filament::getTenant()`, quand c'en est un qu'il ouvre), sinon une collection vide. Le sélecteur de magasin ne montre ainsi que les magasins du client ouvert, jamais tous les clients du cabinet.
- `'societe'` : `peutAdministrer()` → `[société]`.
- `'cabinet'` : `estDUnCabinet() && estActif()` → `[société]`.

**`Affectation`** (l.64-65) : la `LogicException` du `saving` vaut pour le propriétaire et un collaborateur.

### 3.3 Le cabinet vérifié : 69.20Z et Ordre

**Répertoire Sirene : fait par le lot TA.** `UniteSirene` (`activitePrincipale`, `nomenclatureActivite`), leur lecture par `InseeSirene::unite()` dans la période en cours, `FauxRepertoireSirene` (`47.11B`/`NAFRev2` par défaut, `SIREN_CABINET` qui rend `69.20Z`/`NAFRev2`, réglage `exercer()`) et `ControleSirene` (`CODE_ACTIVITES_COMPTABLES`, `MOTIF_ACTIVITE_NON_COMPTABLE`, `exerceLesActivitesComptables(): ?bool`) existent. EC les utilise sans les changer. Un code absent ou d'une autre nomenclature (`exerceLesActivitesComptables()` rend `null`) ne refuse rien : le contrôle se fait alors par l'éditeur, en même temps que l'Ordre, dans la même procédure (champ `--activite-controlee`). C'est un choix de Claude (section 8), repris par le lot TA : une nouvelle nomenclature d'activités (NAF 2025) est annoncée, dont la date d'usage au répertoire et le code des activités comptables sont à vérifier ; tant que l'éditeur n'a pas fixé ce code, chaque cabinet classé dans une autre nomenclature passe par sa procédure.

**Inscription d'un cabinet**, sur le parcours de V2 et du lot TA :
1. Le menu « Votre activité » du formulaire d'inscription (`InscriptionSociete`, lot TA) a déjà l'entrée « Cabinet d'expertise comptable ». EC ajoute seulement sa présélection par le paramètre `?type=cabinet`, que seul le lien envoyé par une société à son expert-comptable utilise (3.5.12). La présélection ne remplace aucun contrôle : la page du lien d'inscription montre toujours le menu, encore modifiable, et la case du choix définitif.
2. Le refus d'un cabinet dont le code n'est pas 69.20Z en NAFRev2, au formulaire et à la confirmation, avant tout courrier, est fait par TA : EC n'y touche pas.
3. Si `exerceLesActivitesComptables()` rend `null`, ou que le répertoire était injoignable au formulaire, l'inscription suit le chemin de V2 et de TA (courrier, ou reprise du contrôle à la confirmation), et l'activité reste à contrôler par l'éditeur.
4. La création de la société (`CreerSocieteAction::handle(string $siren, TypeDActivite $type, string $email, string $motDePasse)`, lot TA) écrit déjà le type. Pour un cabinet, EC lui fait créer aussi la ligne `cabinets` dans la même transaction : `code_activite` et `nomenclature_activite` lus à la confirmation, et `activite_controlee_le = now()` si `exerceLesActivitesComptables()` rend `true`. Elle inscrit `ActiviteDuCabinetControlee` (`resultat` concluant ou à contrôler).
5. `SocieteInscrite` retient déjà le type et le code (lot TA) ; EC y ajoute `origine = inscription`.
6. Aucun magasin ni aucune démonstration pour un cabinet : fait par TA (`SocietePolicy`, `OuvrirLaDemonstrationAction`, `CreerEtablissementAction`, garde `magasins_jamais_dans_un_cabinet`). EC n'y touche pas.
7. La revérification mensuelle de V2 (`ReverificationSirene`) relit aussi l'activité d'un cabinet : c'est la seule relecture du code après l'inscription (règle 8 de `decision-types-activite.md`). Un code devenu autre que 69.20Z en NAFRev2 alerte le propriétaire et l'éditeur, sans rien bloquer, comme toute revérification négative après la validation.

**Interface de l'annuaire de l'Ordre** (`app/Integrations/Ordre`) :

```php
interface AnnuaireDeLOrdre
{
    /** L'annuaire offre-t-il un accès automatique ? Faux pour le pilote « editeur ». */
    public function estAutomatique(): bool;

    /**
     * L'inscription au tableau de l'Ordre de la société de ce SIREN, null si
     * l'annuaire ne la connaît pas.
     *
     * @throws AnnuaireDeLOrdreIndisponibleException
     * @throws LogicException pour un annuaire qui n'est pas automatique
     */
    public function inscription(string $siren): ?InscriptionALOrdre;
}
```

- `InscriptionALOrdre` est `final readonly` : `siren`, `denomination`, `numero`, `conseilRegional`, `inscrite` (bool : inscrite au tableau, ni radiée ni suspendue).
- Les pilotes, déclarés dans `VerificationServiceProvider::PILOTES` et `CONTRATS` sous la clé `'ordre'` :
  - `faux` → `FauxAnnuaireDeLOrdre`. `SIREN_CABINET` y est inscrit (n° `01-23456`, conseil « Île-de-France »), tout autre SIREN inconnu. Réglages : `inscrire(siren, numero, conseil)`, `radier(siren)`, `rendreInjoignable()`. Il est persistant entre requêtes comme les autres factices (voir `FauxRne`).
  - `editeur` → `AnnuaireTenuParLEditeur` : `estAutomatique() = false`. Le pilote de production tant qu'aucun accès automatique officiel n'existe.
  - `ordre`, une implémentation réelle, seulement si une documentation officielle d'API de l'Ordre (Conseil supérieur de l'Ordre des experts-comptables) est lisible. Le 24/10/2026, une recherche n'en a trouvé aucune : seul l'annuaire public en ligne existe, et le moissonner contreviendrait à ses conditions. Le lot ne crée donc pas ce pilote et dit dans « laisses » que l'éditeur doit obtenir de l'Ordre une convention d'accès ou une API documentée, avec ses identifiants.
- Configuration `config/services.php` : `'ordre' => ['pilote' => env('ORDRE_PILOTE', 'editeur')]`.
- `boot()` refuse `faux` en production, comme pour les autres pilotes.
- Aucune vraie requête n'est envoyée pendant le développement.

**`VerifierLOrdreAction`** (`app/Domain/Cabinets/Actions/`), appelée depuis `VerificationDuCabinet` : `handle(User $auteur, Societe $cabinet, array $donnees): Cabinet`. `$donnees` contient `numero_ordre` (requis, 30 caractères au plus) et `conseil_regional` (requis, 100 au plus).
1. `dansLaSociete()` (`AgitDansLaSociete`). Auteur exigé par `LecteurDesDroits::exigerDansLaSociete($auteur, 'verifierLOrdre', $cabinet)`, `CabinetPolicy` : propriétaire actif du cabinet, cabinet non révoqué, Ordre pas déjà vérifié.
2. Limite : 5 essais automatiques par heure et par cabinet (`RateLimiter`, clé `ordre:{cabinet}`).
3. Si `estAutomatique()` : l'annuaire est lu hors de toute transaction. Ensuite, dans une transaction qui verrouille la ligne `cabinets` (`lockForUpdate`) et relit l'auteur :
   - une inscription trouvée, `inscrite`, au même SIREN, pose `numero_ordre`, `conseil_regional_ordre`, `ordre_verifie_le = now()` et `ordre_voie = annuaire`, puis inscrit `OrdreVerifie` ;
   - sinon, l'action inscrit `OrdreRefuse` (`motif`) et lève `VerificationRefuseeException` avec le message « L'annuaire de l'Ordre ne trouve pas d'inscription active à ce SIREN. » ;
   - un annuaire injoignable lève un message de réessai et n'écrit rien.
4. Sinon (pilote `editeur`) : dans la même transaction, l'action pose `numero_ordre`, `conseil_regional_ordre` et `ordre_demande_le`, inscrit `OrdreDemande`, puis appelle `AlerteEditeur::alerter($cabinet, 'Inscription à l\'Ordre à vérifier', [n°, conseil, SIREN])` après le commit.
5. Notification au propriétaire : `AvisDuCabinet`, « Vérification demandée » ou « Inscription à l'Ordre vérifiée ».

**Procédures de l'éditeur** (`app/Console/Commands/Editeur/`, toutes sur `ProcedureDeLEditeur` : justification, pièce, SIREN retapé, opérateurs) :

| Commande | Effet |
|---|---|
| `editeur:cabinet:verifier-ordre {societe} --numero= --conseil= [--activite-controlee]` (`VerifierLInscriptionALOrdre`) | `ConstaterLInscriptionALOrdreAction` : refuse une société qui n'est pas un cabinet ; pose les champs de l'Ordre, `ordre_voie = editeur`, efface `ordre_demande_le` ; avec `--activite-controlee`, pose aussi `activite_controlee_le` (cas d'une nomenclature autre) ; inscrit `OrdreVerifie` (et `ActiviteDuCabinetControlee`) avec l'`InterventionDeLEditeur` ; prévient le propriétaire |
| `editeur:cabinet:refuser-ordre {societe} --motif=` (`RefuserLInscriptionALOrdre`) | `RefuserLInscriptionALOrdreAction` : efface `ordre_demande_le`, inscrit `OrdreRefuse`, prévient le propriétaire avec le motif |
| `editeur:cabinet:retirer-ordre {societe} --motif=` (`RetirerLInscriptionALOrdre`) (Q-EC5) | `RetirerLInscriptionALOrdreAction`, en deux temps pour garder l'ordre des verrous de 3.5 : (1) une transaction verrouille la ligne `cabinets` (`FOR UPDATE`), pose `ordre_retire_le`, inscrit `OrdreRetire` et valide, sans prendre d'autre verrou que les têtes du journal d'audit ; (2) ensuite, l'action relit la liste des liens actifs du cabinet et met fin à chacun par `MettreFinAuLienAction::parLEditeur()` (motif `cabinet_plus_verifie`), chacun dans sa propre transaction. Un lien accepté pendant le premier temps (l'acceptation tient la ligne `cabinets` en `FOR SHARE`, que le premier temps attend) est dans la liste relue ; un lien demandé ensuite est refusé, le cabinet n'étant plus vérifié. Elle prévient le propriétaire du cabinet et celui de chaque client |

**Effet de la vérification.** Tant que `estUnCabinetVerifie()` est faux, les actions suivantes refusent, avec le message `CabinetNonVerifieException::MESSAGE` (« Votre cabinet doit être validé, contrôlé 69.20Z et vérifié à l'Ordre avant d'inviter ou de lier un client. ») :
- `InviterUneEntrepriseAction`, pour un SIREN libre comme pour un SIREN déjà pris ;
- `AccepterLaDemandeDeLienAction`, des deux côtés : le cabinet est relu à l'acceptation, y compris sur la page de rattachement, qui dit alors « Ce cabinet ne peut plus être lié. » ;
- `DesignerUnCabinetAction` : le cabinet n'apparaît même pas dans la recherche ;
- `AccepterLInvitationAction` ;
- la lecture et l'ouverture d'un client (`liensOuverts()`, `peutOuvrir()`) : seul reste au propriétaire la liste de ses liens actifs, pour y mettre fin (L2 ter).

### 3.4 Les droits d'un compte de cabinet chez un client

**Registre des autorisations** (`app/Domain/Authorization/PermissionRegistry.php`). Après le lot TA, les services ont leurs autorisations (`service.view`, `service.create`, `service.update`), chaque autorisation est rangée dans la famille `commune` ou `articles`, et `pourLeType(TypeDActivite)` rend celles d'un type. Comme les méthodes du lot TA, les nouvelles reçoivent le type du client et ne rendent que des clés de ce type :
- `parDefautDuCabinet(TypeDActivite $typeDuClient): list<string>` = `consultations()` ∩ `pourLeType($typeDuClient)`. `service.view` est une consultation : le cabinet la reçoit par défaut. Chez un client « Services, sans vente d'articles », les consultations de la famille `articles` (produits, fournisseurs, achats, avoirs fournisseur, inventaires) n'en font pas partie.
- `ajoutablesAuCabinet(TypeDActivite $typeDuClient): list<string>` = `[FOURNISSEUR_CREATE, FOURNISSEUR_UPDATE, FOURNISSEUR_ARCHIVER, FACTURE_ACHAT_CREER, FACTURE_ACHAT_VALIDER, AVOIR_FOURNISSEUR_CREER, PRODUIT_CREATE]` ∩ `pourLeType($typeDuClient)`. La liste est écrite en toutes lettres ; elle n'a que des clés de la famille `articles`, donc rien pour un client de services. `PRODUIT_CREATE` y figure parce que valider une facture d'achat ou un inventaire d'ouverture exige aussi le droit de créer des produits ; depuis le lot TA, il ne vaut plus que pour les produits. `SERVICE_CREATE` et `SERVICE_UPDATE` n'y sont jamais.
- `duCabinet(TypeDActivite $typeDuClient): list<string>` = `parDefautDuCabinet()` ∪ `ajoutablesAuCabinet()` pour ce type, triée. `duCabinet(TypeDActivite::Commerce)` est la liste complète : la garde `autorisations_cabinet_permises` l'écrit en littéraux. Pour `TypeDActivite::Cabinet`, la liste est vide : un cabinet n'est jamais client.
- `verifierDuCabinet(array $autorisations, TypeDActivite $typeDuClient): list<string>` lève `InvalidArgumentException` pour une autorisation hors de `duCabinet($typeDuClient)`.
- `libellesDuCabinet(TypeDActivite $typeDuClient): array<string, string>` : les libellés de `libelles()` pour les clés de ce type, sauf `PRODUIT_CREATE` → « Produits : créer ceux d'une facture d'achat ou d'un inventaire d'ouverture ».
- `consultations()` : le docblock remplace l'expert-comptable par « les autorisations par défaut d'un cabinet lié, croisées avec le type du client ».
- Un test vérifie que `duCabinet(TypeDActivite::Commerce)` n'a aucune intersection avec :
  - `deVente()` ;
  - `CLIENT_CREATE`, `CLIENT_UPDATE`, `CLIENT_ARCHIVER` ;
  - `PRODUIT_UPDATE`, `PRODUIT_ARCHIVER`, `PRODUIT_AJUSTER_STOCK`, `PRIX_VENTE_REGLER`, `VENTE_REMISES_ET_PRIX` ;
  - `SERVICE_CREATE`, `SERVICE_UPDATE` ;
  - `DEVIS_CREATE`, `DEVIS_UPDATE`, `DEVIS_ENVOYER` ;
  - `FACTURE_ENVOYER`, `FACTURE_JUSTIFIER`, `RELANCES_REGLER`, `PARAMETRES_GERER` ;
  - `PAIEMENT_CONTRE_PASSER`, `AVOIR_CREER`, `AVOIR_ENVOYER` ;
  - `CLOTURE_CAISSE_GERER`.
- Le même test vérifie que `duCabinet(TypeDActivite::Services)` n'a aucune clé de `articles()`.

**`AutorisationsSociete`** reçoit `duProprietaireDUnCabinet(): list<string>` = `[PARAMETRES_GERER]`. Le docblock (l.11) dit que les collaborateurs n'en ont aucune et que le propriétaire d'un cabinet n'a que les paramètres (son identité).

**Service `App\Domain\Cabinets\Services\AccesDesCabinets`.** Sans état. Toutes ses requêtes retirent `SocieteScope` et filtrent explicitement. Ses méthodes :
- `lienActifEntre(int $cabinetId, int $clientId): ?LienCabinet`.
- `liensDuCompte(User $compte): Builder<LienCabinet>` : les liens actifs du cabinet du compte, filtrés par `clients_confies` pour un collaborateur, sans regarder l'état du cabinet. Elle sert aux droits enregistrés (`autorisationsEnregistrees()`, `magasinsTouches()`, droits implicites), comme la lecture seule d'un magasin ne change pas les droits enregistrés.
- `liensOuverts(User $compte): Builder<LienCabinet>` : `liensDuCompte()` quand `estUnCabinetVerifie()` est vrai, rien sinon (`whereRaw('1 = 0')`). Elle sert à tout ce que le compte voit ou ouvre : tableau de bord, liste et fiche des clients, `magasinsOuverts()`, `ouvre()`, policies.
- `liensActifsDuCabinet(Societe $cabinet): Collection<LienCabinet>` : pour le seul propriétaire d'un cabinet qui n'est plus vérifié, ses liens actifs, avec la dénomination et le SIREN de chaque client, pour y mettre fin (section 5, L2 ter).
- `ouvre(User $compte, Magasin $magasin): bool`.
- `magasinsOuverts(User $compte): Collection<Magasin>` ; `magasinsDuCompte(User $compte)`, la même lecture sur `liensDuCompte()`.
- `autorisationsEnregistrees(User $compte, Magasin $magasin): ?list<string>`.
- `comptesQuiOntAcces(LienCabinet $lien, bool $avecLesSuspendus = true): Collection<User>` : le propriétaire du cabinet et les collaborateurs confiés, jamais un compte retiré.
- `magasinsDuClient(int $clientId): Collection<Magasin>` : ses magasins réels, par identifiant.

Le texte de chaque requête est en section 5.

**`ControleDesDroits`** (`app/Domain/Authorization/ControleDesDroits.php`). Juste après `if (! $user->peutAgir()) return false;`, et avant toute autre branche :

```php
if ($user->estDUnCabinet()) {
    return $this->pourUnCompteDeCabinet($user, $ability, $arguments[0] ?? null, $contexte);
}
```

`pourUnCompteDeCabinet()` :
1. **Autorisation de société.** Seulement dans l'espace de son cabinet (contexte = sa société, sans magasin) : `$this->autorisationDeSociete($user, $ability, $contexte->societe())`, qui applique la lecture seule d'une société suspendue, résiliée ou révoquée, puis `autorisationsDeSociete()`. Ailleurs : `false`.
2. **Sans magasin dans le contexte.**
   - Contexte = son cabinet : `$this->dansLEspaceDeLaSociete($user, $ability, $cible, $contexte->societe())`, qui rend `null` hors lecture seule ; les policies du panneau `cabinet` répondent alors, en mode strict. Un cabinet suspendu, résilié ou révoqué ne garde ainsi que les consultations et les gestes d'accès (ci-dessous).
   - Contexte = une autre société : `false`. Un compte de cabinet n'agit jamais dans l'espace d'administration d'un client.
   - Aucun contexte : `null`, comme aujourd'hui (profil).
3. **Magasin dans le contexte.**
   - `! $user->peutOuvrir($magasin)` → `false`. Cela couvre le lien terminé, le client non confié, la démonstration, et le cabinet qui n'est plus vérifié ou plus actif.
   - Puis les règles du client, exactement comme pour tout compte : lecture seule (`estPermisEnLectureSeule`) et `peutVendre()`.
   - Si `$ability` est une autorisation de `PermissionRegistry` : `in_array($ability, $user->autorisationsDans($magasin), true)`. Le refus est `false`, et non `null`, pour qu'aucune policy ne l'accorde à un cabinet pour une autre raison.
   - Sinon (une question de policy) : `null` seulement si le couple (geste, modèle de la cible) est dans `GESTES_D_UN_CABINET`, `false` sinon.

**Gestes d'accès en lecture seule.** `porteSurLEquipe()` compte aussi un `LienCabinet`, et le geste `gererSonCabinet` sur une société : donner ou couper l'accès d'un cabinet reste permis à une société en lecture seule (« un accès se coupe toujours »), sauf révoquée, des deux côtés du lien. Sans cela, `dansLEspaceDeLaSociete()` refuserait à une société suspendue ou résiliée de mettre fin au lien (3.5.7 et 3.5.10), et à un cabinet suspendu d'y mettre fin. Un cabinet suspendu ne crée pourtant aucun lien : les actions exigent `estUnCabinetVerifie()`.

La constante `GESTES_D_UN_CABINET` est une liste blanche, écrite en toutes lettres :
- **Consultations.** `viewAny` et `view` sur `Client`, `Fournisseur`, `Produit`, `Service`, `Devis`, `Facture`, `Paiement`, `Avoir`, `ClotureCaisse`, `ClotureCaissePersonnelle`, `FactureAchat`, `AvoirFournisseur`, `InventaireOuverture`, `EnregistrementFiscal`, et sur tout autre modèle de document ou de fiche qu'une ressource du panneau `app` montre en consultation. L'implémenteur parcourt `Filament::getPanel('app')->getResources()` et les pages, et l'inventaire du test 6.12 fige la liste.
- **Écritures**, que la policy contrôle ensuite par l'autorisation du lien :
  - `create`, `update`, `valider`, `delete`, `deleteAny` sur `FactureAchat` ;
  - `create` sur `AvoirFournisseur` ;
  - `create`, `update`, `delete`, `deleteAny`, `restore` sur `Fournisseur` ;
  - `create`, `update`, `valider`, `delete`, `deleteAny` sur `InventaireOuverture` ;
  - `create` sur `Produit`, jamais sur `Service`.
- **Jamais**, quelle que soit la ligne en base : `User`, `Affectation`, `PosteReconnu`, `Magasin`, `Societe`, `fermer`, `fermerLaCaisseDe`, `cloturer`, `envoyer`, `justifier`, `encaisser`, `contrePasser`, `emettre`, `accepter`, `refuser`, `convertir`.

Le docblock de la classe remplace le paragraphe de l'expert-comptable par celui du cabinet. `estUneConsultationPermise()` disparaît.

**Lecture des droits par les actions.** `LecteurDesDroits` ne change pas : l'auteur relu répond par la Gate, donc par cette branche. Deux points changent.
- **`ExporterJournalDesEvenementsAction`** (`app/Domain/CaisseSecurisee/Actions/`) : le contrôle des droits passe sous le verrou du registre. Dans la transaction, l'action verrouille d'abord le registre (`RegistreFiscal::sousVerrou($magasin, …)`), puis relit l'auteur par `exigerSousVerrou`, puis journalise. Un export qui croise une fin de lien passe ainsi tout entier avant ou après elle (test 6.15).
- **`ValiderFactureAchatAction`, `EmettreAvoirFournisseurAction` et `ValiderInventaireOuvertureAction`** relisent déjà leur auteur sous le verrou du registre : rien à changer.

**Le nom pour l'historique** (règle 5 : « en son nom, avec le nom du cabinet »). `User::nomPourLHistorique()` remplace `->name` dans ces quatre endroits, pour que tout auteur soit nommé de la même façon :
- `RegistreFiscal::inscrire()` (l.565, `'operateur' => ['id', 'nom']`) ;
- `FigeLeNomDeSesAuteurs` (l.264 : `User::nomFigeDe($personne)`) ;
- `JournalAudit::inscrire()` (`auteur_libelle`) ;
- `JournalDesDroits` (`inscrireLeJet130()` : `'membre' => ['id', 'nom']`, et `resume()` : `'nom'`).

Le format signé ne change pas (`nom` reste une chaîne) : `ModuleFiscal::VERSION` ne change pas (point de relecture).

**`JournaliserFinDeSession`** (`app/Domain/CaisseSecurisee/Listeners/JournaliserFinDeSession.php`). Le filtre `where('societe_id', $utilisateur->societe_id)` devient : magasins de sa société, ou, pour un compte de cabinet, magasins dont `droitsDans()` n'est pas nul. Le JET 40 de fin de session s'inscrit dans un magasin d'un client, en `runAsSociete($magasin->societe, fn () => runAs($magasin, …))`. Un client dont le lien est terminé ne reçoit rien (« qui n'y en a plus n'a plus rien à y inscrire »).

**`JournaliserOuvertureDeSession`** : inchangé. Le JET 80 d'un collaborateur s'inscrit dans le magasin du client, à son nom avec celui du cabinet.

**`DestinatairesDesAlertes`** : inchangé, car il filtre par société. Le docblock remplace l'expert-comptable par « jamais un compte d'un cabinet, d'une autre société ».

**`FacturesAEncaisserApercu`**, **`BienvenueApercu`**, **`ValeurStockApercu`**, **`ClotureCaissePersonnelleResource`**, **`ViewClient`** : docblocks réécrits. Le comportement découle des autorisations du lien.

### 3.5 Actions

Règles communes à toutes les actions du lot :
- Chaque action relit son auteur sous les verrous qu'elle prend. Elle le contrôle sur ce compte relu par sa policy, via `LecteurDesDroits` ou `exigerDansLaSociete`, jamais sur l'instance reçue.
- **L'auteur se contrôle toujours dans sa propre société.** Une action qui travaille dans le contexte du client (`modifierLeLien()`, création d'une demande du cabinet) contrôle un auteur du cabinet par `TenantContext::runAsSociete($auteurRelu->societe, fn () => $lecteur->exigerDansLaSociete($auteurRelu, $geste, $auteurRelu->societe, $lienRelu))`, après les verrous. Dans le contexte du client, `ControleDesDroits` refuse tout à un compte de cabinet (« contexte = une autre société »), et `exigerDansLaSociete()` exige le contexte de la société qu'il reçoit : le contrôle échouerait toujours. Le contrôle reste entier : ne jamais le relâcher pour faire passer l'action. `runAsSociete()` s'imbrique et restaure le contexte d'avant.
- **Les lignes du client se touchent dans son contexte.** Une action du cabinet qui crée ou modifie une ligne du client (`liens_cabinet`, `autorisations_cabinet`) se place en `runAsSociete($client)` : `BelongsToSociete` écrit toujours la société du contexte dans `societe_id` à la création, et une demande créée dans le contexte du cabinet serait rattachée au cabinet, puis refusée par la garde. Les lignes du cabinet touchées depuis le contexte du client (`clients_confies` d'un lien) passent par `DB::table` filtré par `societe_id = cabinet_id` du lien et `lien_id` (section 5, L12).
- Elle écrit au nom de l'auteur relu (`OperateurCourant::pendant`).
- Les écritures aux journaux d'audit se font dans la transaction de l'action. Pour deux sociétés : celle du client d'abord, puis celle du cabinet, chacune en `runAsSociete()`, la tête de chaque chaîne en dernier.
- Les notifications et les e-mails partent après le commit (`afterCommit`).
- **Ordre des verrous** pour toute action qui touche un lien ou une invitation :
  1. les registres des magasins réels concernés, par identifiant croissant ;
  2. la ligne `societes` du cabinet : `FOR UPDATE` pour une invitation, qu'elle vise un SIREN libre ou devienne une demande de rattachement, et pour la commande d'expiration des invitations, `FOR SHARE` pour l'acceptation ou le refus d'une invitation ; ces actions ne prennent aucun registre ;
  3. les invitations concernées (`FOR UPDATE`), par identifiant croissant ;
  4. la ligne de la société cliente (`FOR UPDATE`), point de sérialisation des liens d'un client ;
  5. les lignes `liens_cabinet` concernées, par identifiant croissant ;
  6. la ligne `cabinets` (`FOR SHARE`) si l'état du cabinet est relu ; les seules transactions qui la prennent `FOR UPDATE` (`VerifierLOrdreAction` et les procédures de l'Ordre) ne prennent ensuite que les têtes du journal d'audit ;
  7. les comptes (auteur et comptes qui ont accès), par identifiant croissant ;
  8. les têtes du journal d'audit, en dernier.
- La société cliente née dans la transaction d'une acceptation est verrouillée après la ligne `cabinets` : elle est nouvelle, aucune autre transaction ne peut l'attendre, et l'ordre ne s'en trouve pas inversé.
- Cet ordre prolonge celui de `ValiderLaSocieteAction` et de la saisie du code (`SaisirCodeDeConfirmationAction::servir()` : registres des magasins réels, société, code, propriétaire). Les relecteurs le vérifient action par action.
- La clôture d'une inscription (`CloreLInscriptionAction`, telle que V2b, FA1 et TA la laissent) verrouille, au HEAD `65819c6`, la société, puis ses codes et son propriétaire, sans aucun registre. La fin du lien de 3.5.11 inscrit le JET 130 dans chaque magasin réel de la société, donc sous le verrou de leurs registres, qui précèdent la société dans l'ordre de 3.5 : EC fait prendre à la clôture les registres de ses magasins réels avant la société (`sousVerrouDesMagasins`, avec la reprise de `modifier()` si la liste des magasins change), comme la saisie du code, et `modifierLeLien()` s'exécute ensuite dans sa transaction sans reprendre ce qu'elle tient. Si FA1 ou TA ont déjà changé ces verrous, EC part de leur code et garde cet ordre. Les relecteurs le vérifient sur le code réel.
- Une action qui passe par `JournalDesDroits::modifier()` et doit aussi verrouiller des liens (`ConfierDesClientsAction`) les verrouille entre les registres et les comptes. `modifier()` reçoit pour cela un paramètre facultatif `?Closure $verrousAvantLesComptes`, appelé juste après les registres et avant `User::…->lockForUpdate()`. Sans lui, un client sans magasin réel, donc sans registre, exposerait à un interblocage : une fin de lien prend le lien puis les comptes, une modification des clients confiés prendrait les comptes puis le lien.
- Un client qui n'a encore aucun magasin réel (une société qui vient de naître d'une invitation, par exemple) n'a aucun registre à verrouiller : `sousVerrouDesMagasins()` reçoit une collection vide et ne verrouille rien. Le premier verrou est alors celui de la société cliente. Le test 6.4 le couvre, dans la transaction déjà ouverte de l'acceptation.
- Une action ne verrouille jamais une ligne d'une autre société que celles du lien qu'elle traite. Une invitation d'un autre cabinet ne se modifie donc jamais dans la transaction d'une acceptation (voir 3.5.5).

#### 3.5.1 `JournalDesDroits` étendu (`app/Domain/Tenancy/Services/JournalDesDroits.php`)

1. **`magasinsTouches()`** (l.200). Pour un compte de cabinet, les magasins touchés sont les magasins réels des clients de ses liens (`AccesDesCabinets::magasinsDuCompte()`, sans l'état du cabinet), ajoutés à ceux qu'il vise. La branche d'expert-comptable (l.204) disparaît.
2. **Verrouillage.** Quand un magasin touché n'est pas de la société du contexte (cas d'une action du cabinet), le verrou passe par la nouvelle méthode `RegistreFiscal::sousVerrouDesMagasinsDesClients(Societe $cabinet, Collection $magasins, Closure $operation)` :
   - elle refuse un magasin dont la société n'a aucune ligne `liens_cabinet` avec ce cabinet (`LogicException`) ;
   - elle verrouille par identifiant croissant, chaque registre en `runAsSociete($magasin->societe, fn () => runAs($magasin, fn () => $this->verrouiller($magasin)))` ;
   - elle passe par `enTeteDeTransaction()`, comme `sousVerrouDesMagasins()`.
   C'est la seule prise de registres de plusieurs sociétés : son docblock le dit. Sa vérification des liens est la lecture L14 de la section 5.
3. **`inscrireLeJet130()`** (l.309) : `runAsSociete($magasin->societe, …)` puis `runAs($magasin, …)` quand le magasin n'est pas de la société du contexte.
4. **`inscrireLesDroitsImplicites(Magasin)`** (l.174). Un nouveau magasin réel d'un client inscrit aussi les droits des comptes du cabinet lié qui y ont accès (`comptesQuiOntAcces($lien)`, sans les retirés). Les experts-comptables disparaissent de la requête.
5. **Nouvelle méthode `modifierLeLien(?User $auteur, ActionAuditee $action, Societe $client, array $liens, Closure $modification, array $valeursAuditees = [])`.** Toutes les actions de 3.5.7 à 3.5.12 qui changent l'accès d'un cabinet chez un client y passent :
   - Elle se place en `runAsSociete($client)`.
   - Elle verrouille les registres des magasins réels du client (`sousVerrouDesMagasins`), puis la société cliente, puis les liens (`$liens`, plus le lien actif du client s'il y en a un), puis les comptes : l'auteur, et les comptes qui ont accès par chacun de ces liens, avant et après.
   - Elle relit tout, puis contrôle l'auteur relu dans sa propre société (règles communes). Elle reprend, trois fois au plus, si la liste des magasins a changé (comme `modifier()`).
   - Elle relit l'état du cabinet par `Societe::cabinet()`, sans `SocieteScope` (3.2), et efface les `clients_confies` d'un lien par `DB::table` (L12).
   - Elle calcule les états avant, exécute la modification, puis calcule les états après. Pour un compte dont le lien vient de naître, la modification rend les comptes nouvellement touchés.
   - Elle inscrit le JET 130 de chaque compte dans chaque magasin réel du client où ses droits ont changé.
   - Elle inscrit l'entrée d'audit `$action` dans le journal du client, puis celle du cabinet de chaque lien touché, chacune en `runAsSociete()`. L'entrée du client porte `droits` de tous les comptes touchés et les `$valeursAuditees` (cabinet, client, origine, motif). L'entrée d'un cabinet ne porte que les droits des comptes dont `societe_id` est ce cabinet, et jamais l'identité d'un autre cabinet : au remplacement, l'ancien cabinet lit seulement que le lien a pris fin, motif `remplace`, et le nouveau, que le lien est actif.
   - Elle réutilise `etats()`, `inscrire()` et `inscrireLeJet130()`, rendus utilisables par les deux chemins. Rien n'est dupliqué.

#### 3.5.2 Collaborateurs

**`CreerCollaborateurAction`** (`app/Domain/Cabinets/Actions/`). Elle remplace `CreerExpertComptableAction`, réécrite et non copiée : `handle(User $auteur, Societe $cabinet, array $identite, ?string $jeton = null): User`.
- `dansLaSociete($cabinet, MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL)`.
- `exigerLeProprietaireDuCabinet()` : `PorteeDesDroits::gereSonEquipe($auteur)`, `estProprietaire()`, même société, société de type cabinet.
- `CreationDesComptes::valider()` et `empreinte(['role_societe' => 'collaborateur', 'name' => …])`.
- `JournalDesDroits::modifier($auteur, ActionAuditee::CollaborateurCree, [], [], fn …)` : aucun magasin visé, car un nouveau collaborateur n'a aucun client confié. À l'intérieur : `compteDejaCree()` (jeton d'écran), `verifierLaLimiteDuJour()` (20 par jour : un cabinet n'a aucun magasin ouvert), puis `CreationDesComptes::creer(…, RoleSociete::Collaborateur, null, …)`.
- Doublon du jeton : comme aujourd'hui.
- Message `MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL` : « Seul le propriétaire du cabinet crée, suspend, réactive, retire et réintègre un collaborateur et lui redonne l'accès. »
- Le docblock de `CreationDesComptes` (l.19-22) cite `CreerCollaborateurAction`.

**Statut des comptes.** `ChangerStatutCompteAction` :
- `suspendre()`, `reactiver()` et `retirer()` valent pour un collaborateur par les règles génériques de `PorteeDesDroits` : le propriétaire seul pour un compte qui n'est pas membre.
- `retirerLesDroits()` (l.224) efface aussi ses lignes `clients_confies`.
- Leurs magasins touchés (3.5.1) sont ceux des clients confiés.
- `reintegrerLExpertComptable()` (l.199-217) devient `reintegrerLeCollaborateur()` : le propriétaire du cabinet seul ; aucun client confié à la réintégration ; inscrit `CompteReintegre`.

**Accès redonné.** `RedonnerLAccesAction` : le propriétaire pour tout compte (règle générique). Pour un collaborateur, seul le mode « présent » est permis : en mode « absent », son mot de passe redeviendrait son identifiant, qui n'ouvre aucun compte de cabinet. L'action le refuse (« Un collaborateur choisit son mot de passe en votre présence : son identifiant n'ouvre pas son compte. »), et l'écran ne propose pas ce mode. Le docblock de `PorteeDesDroits::peutRedonnerLAcces()` (l.236-247) remplace l'expert-comptable par le collaborateur.

**`PorteeDesDroits`** (`app/Domain/Tenancy/Services/PorteeDesDroits.php`) :
- docblock (l.20-62) : « seul le propriétaire gère les administrateurs et, dans un cabinet, les collaborateurs » ;
- `peutChangerLeRoleDeSociete()` (l.330) : `false` dans un cabinet ;
- `comptesVisibles()` (l.413) : le propriétaire d'un cabinet voit ses collaborateurs ;
- nouvelle méthode `peutConfierDesClients(User $auteur, User $compte): bool` : propriétaire du cabinet, compte collaborateur non retiré du même cabinet ;
- la transmission dans un cabinet vise un collaborateur (Q-EC3).

**`MembrePolicy`** (`app/Policies/MembrePolicy.php`) :
- `creerLExpertComptable()` (l.162) est remplacée par `creerUnCollaborateur(User $user)` : propriétaire actif d'un cabinet non révoqué, dans le contexte de ce cabinet ;
- nouvelle méthode `confierDesClients(User $user, User $collaborateur)` → `PorteeDesDroits::peutConfierDesClients()` ;
- `creerUnAdministrateur()` et `nommerUnResponsable…` → `false` dans un cabinet ;
- `transmettreLaPropriete()` (l.151) : un collaborateur actif dans un cabinet (Q-EC3) ;
- docblock (l.21-23) réécrit.

**Mot de passe.**
- `doitChoisirSonMotDePasse()` ne reçoit pas le collaborateur (3.2). Aucun compte d'un cabinet n'arrive donc connecté avec son identifiant pour mot de passe (le propriétaire a choisi le sien à l'inscription, ou avant la transmission de la propriété du cabinet, Q-EC3) : le panneau `cabinet` ne déclare ni `ExigerSonPropreMotDePasse` ni la page `ChoisirSonMotDePasse` (3.8).
- `PostesReconnus::ouvreLeCompte()` (l.102-116) rend `false` pour un compte de cabinet, avant de lire `magasinsAccessibles()`, que le lot étend aux magasins des clients confiés. Sans cela, un collaborateur dont le mot de passe est encore son identifiant ouvrirait son compte depuis un poste reconnu d'un magasin d'un client, que ni lui ni son cabinet n'a reconnu.
- `GereLesComptes::fenetreDuCompteCree()` (l.212-256) : pour un collaborateur, le mode `present` est choisi d'office. Le texte dit qu'un cabinet n'a pas de poste reconnu : avec son seul identifiant, le collaborateur ne se connecte pas ; il choisit son mot de passe maintenant, sur cet écran, ou plus tard, quand le propriétaire lui redonne l'accès en sa présence.

**Transmission de la propriété d'un cabinet** (Q-EC3, option a, retenue par l'éditeur) :
- `DemanderLaTransmissionDeLaProprieteAction::exigerLesConditions()` (l.199) accepte, dans un cabinet, un collaborateur actif aux mêmes conditions qu'un administrateur dans une autre société : mot de passe choisi par lui, adresse e-mail renseignée par lui, différente de toute adresse de connexion d'un propriétaire, lien de 24 heures suivi en étant connecté à son compte, mot de passe redonné par le propriétaire.
- `AccepterLaTransmissionDeLaProprieteAction` : l'ancien propriétaire devient collaborateur. Dans la même modification, une ligne `clients_confies` est créée pour chaque lien actif du cabinet. Le JET 130 de chaque magasin des clients le dit, car `role_societe` change.
- La page `AccepterLaTransmission` est aussi enregistrée dans le panneau `cabinet` (route authentifiée).

#### 3.5.3 Clients confiés

**`ConfierDesClientsAction`** : `handle(User $auteur, User $collaborateur, array $liens): void`. `$liens` est la liste complète des identifiants des liens confiés après le geste, qui peut être vide.
- `dansLaSociete($cabinet)`.
- `JournalDesDroits::modifier($auteur, ActionAuditee::ClientsConfiesModifies, [$collaborateur], $magasinsVises, …, verrousAvantLesComptes: …)`.
  - `$magasinsVises` sont les magasins réels des clients des liens demandés et de ceux qu'on retire : verrous dans plusieurs sociétés (3.5.1).
  - `verrousAvantLesComptes` verrouille `FOR UPDATE`, par identifiant croissant, ces mêmes liens (`LienCabinet::duCabinet($cabinet)->whereKey(…)`), après les registres et avant les comptes (3.5).
- Dans la modification :
  - `PorteeDesDroits::peutConfierDesClients()` sur les comptes relus ;
  - chaque lien demandé, déjà verrouillé, doit être `actif` et de ce cabinet, sinon `LogicException` (« Ce client n'est plus lié à votre cabinet. ») ;
  - écriture de la différence dans `clients_confies` (ajouts avec `confie_par`, retraits).
- JET 130 dans chaque magasin réel touché. Audit dans le journal du cabinet.
- Notifications au collaborateur, une par client, avec `client_id` dans leurs données : « Client confié : {client} » ou « {client} ne vous est plus confié ». Au retrait d'un client, les avis du cabinet sur ce client (`data->client_id`) s'effacent d'abord de sa cloche (`User::effacerLesAvisDesClients(array $clients)`, sur le modèle de `effacerLesNotificationsDesMagasins()`), comme le code efface déjà les avis d'un magasin à la perte d'accès.

#### 3.5.4 Invitation d'un client par son SIREN (règle 4 ; Q-EC1)

Le cabinet invite uniquement par le SIREN du client, avec l'adresse e-mail à laquelle part le lien (Q-EC1). Aucune recherche par nom n'existe du côté du cabinet. Le même geste, « Inviter un client », donne une invitation si le SIREN est libre (règle 4a), et une demande de rattachement si le SIREN est déjà pris (règle 4b) ; dans les deux cas, l'e-mail part à l'adresse saisie et nomme le cabinet.

**`InviterUneEntrepriseAction`** : `handle(User $auteur, Societe $cabinet, string $siren, string $email, string $motDePasse): ResultatDInvitation`. `ResultatDInvitation` est une énumération : `InvitationEnvoyee`, `InvitationDejaEnvoyee`, `DemandeEnvoyee`, `DejaLie`, `DemandeDejaEnvoyee`.

1. Validation : `siren` par `SirenValide`, `email` requis, en minuscules (`User::normaliserEmail()`). Le SIREN du cabinet lui-même est refusé (« Votre cabinet ne peut pas être son propre client. »).
2. `MotDePasseRedemande::exiger($auteur, $motDePasse)` : l'invitation valide une société sans courrier, le cabinet en répond (choix de Claude, que l'éditeur peut contester : `reponses-cabinets.md`, règle 7).
3. Policy `InvitationCabinetPolicy::create` : propriétaire actif du cabinet (Q-EC1) et `estUnCabinetVerifie()`.
4. Débits, vérifiés avant d'écrire :
   - `DebitDesEmails::verifier($auteur)` ;
   - au plus `config('cabinets.invitations_par_jour', 50)` invitations et demandes de rattachement par jour et par cabinet (invitations du cabinet et demandes `demande_du_cabinet` créées ce jour), comptées sous le verrou de la société du cabinet, avec le message « Votre cabinet a déjà envoyé N invitations ou demandes aujourd'hui : réessayez demain. » (choix de Claude, que l'éditeur peut contester : `reponses-cabinets.md`, règle 7). Chaque invitation et chaque demande envoie un e-mail à l'adresse saisie : chacune compte dans `DebitDesEmails::compter($auteur)`.
5. Hors transaction : `ControleSirene::unite($siren)`. Une entreprise inconnue ou cessée est refusée avec le motif de `ControleSirene`. Un répertoire injoignable : « Le répertoire Sirene ne répond pas : réessayez dans quelques minutes. », rien n'est écrit.
6. Transaction :
   - verrou de la ligne `societes` du cabinet (`FOR UPDATE`, point 2 de l'ordre des verrous) : les invitations et les demandes d'un cabinet se suivent ;
   - relecture de l'auteur et de l'état du cabinet ;
   - les invitations ouvertes et périmées de ce cabinet pour ce SIREN passent à `expiree`, avec `close_le` ;
   - **si le SIREN est pris** (règle de V2, `Societe::sirenPris()` et la société qui le tient, section 5, L6) :
     - la société qui le tient est un cabinet → refus neutre « Ce SIREN ne peut pas être invité par votre cabinet. », qui ne dit pas pourquoi : le type d'une société inscrite n'est pas montré (choix de Claude, section 8 : un cabinet n'est jamais client) ;
     - la société qui le tient est close (inscription close sans validation, V2b) : le SIREN n'est plus pris, par la règle de V2 ; ce cas ne se présente donc pas ;
     - sinon, la suite se fait en `runAsSociete($client)` (règles communes : la demande appartient au client) : verrou de la société cliente (`FOR UPDATE`, point 4), puis relecture des liens de la paire, dans les deux sens :
       - un lien actif entre eux → `DejaLie`, sans e-mail ;
       - une demande du cabinet en cours → `DemandeDejaEnvoyee`, sans second e-mail, quelle que soit l'adresse saisie : son propriétaire la voit déjà dans son espace ;
       - une demande du client vers ce cabinet en cours → refus « Cette entreprise a déjà demandé à lier votre cabinet : répondez à sa demande dans « Invitations et demandes ». » (le client a choisi de se faire connaître du cabinet) ;
       - une demande de ce cabinet à ce client refusée, retirée ou expirée depuis moins de 7 jours → refus « Votre cabinet a déjà demandé ce lien il y a moins de 7 jours. » (choix de Claude, section 8 : la boucle demande, retrait, demande inonderait l'adresse saisie et l'espace du propriétaire du client) ;
       - sinon, création de la demande de rattachement : `LienCabinet` (`origine = demande_du_cabinet`, `etat = demande_par_le_cabinet`, `demande_par`, `expire_le = now() + 7 jours` (Q-EC6), `jeton_hache` = SHA-256 d'un jeton `Str::random(64)`), audit `LienDemande` dans les deux journaux (3.1 : SIREN seul côté cabinet), `DemandeDeRattachementMail` à l'adresse saisie, mise en file après le commit, `AvisDuCabinet` au propriétaire du client (base seulement, sans e-mail, avec un lien vers la page de rattachement de 4.2 bis), et `DebitDesEmails::compter($auteur)` → `DemandeEnvoyee` ;
       - une `UniqueConstraintViolationException` de `liens_cabinet_une_demande_par_paire`, que la relecture sous le verrou du client évite, est quand même traduite en `DemandeDejaEnvoyee` ;
     - PILIQI ne garde pas l'adresse saisie : elle ne sert qu'à l'envoi de l'e-mail, et n'est ni dans `liens_cabinet` ni au journal d'audit.
   - **sinon** (SIREN libre) :
     - une invitation ouverte de ce cabinet pour ce SIREN à la même adresse → `InvitationDejaEnvoyee`, sans second e-mail : une demande répétée ne crée rien ;
     - à une autre adresse → refus « Une invitation est déjà ouverte pour ce SIREN : retirez-la pour en envoyer une autre. » ;
     - une invitation de ce cabinet pour ce SIREN refusée depuis moins de 7 jours → refus « Cette entreprise a refusé votre invitation il y a moins de 7 jours. » (choix de Claude, section 8, le même délai qu'après le refus d'une demande) ;
     - sinon, création de l'invitation (jeton `Str::random(64)`, haché en SHA-256, `expire_le = now() + 7 jours`), audit `InvitationEnvoyee` (`siren`), `InvitationDuCabinetMail` mise en file après le commit, puis `DebitDesEmails::compter()` → `InvitationEnvoyee`.
7. L'écran dit au cabinet ce qui s'est passé :
   - « Invitation envoyée à … » ;
   - « Cette entreprise est déjà inscrite sur PILIQI : une demande de rattachement est envoyée à cette adresse, et son propriétaire la voit dans son espace. » Elle révèle seulement que le SIREN est inscrit, ce que le point 2 du protocole réécrit (1.2) permet. Jusqu'à l'activation du lien, le cabinet ne voit de cette demande que le SIREN qu'il a saisi, et les avis qu'il reçoit sur elle (refus, expiration) ne citent que ce SIREN.

**`InvitationDuCabinetMail`** (`app/Domain/Cabinets/Mail/`), pour un SIREN libre.
- Objet : « {cabinet} vous invite sur PILIQI ».
- Corps : la dénomination et la ville du cabinet qui invite ; le SIREN invité ; le fait qu'en acceptant, la personne choisit son mot de passe et le type d'activité de la société, définitif, que la société sera validée sans courrier ni QR code et que ses données légales seront lues au registre national ; les autorisations par défaut ; le lien `route('cabinets.invitation.afficher', $jeton)`, qui permet aussi de refuser ; l'expiration.
- Au moment de l'envoi (`shouldSend()` ou un job), l'invitation est relue : elle ne part que si elle est encore ouverte et que le cabinet est encore vérifié. Sinon elle ne part pas, et le propriétaire du cabinet en est prévenu (règle des e-mails).

**`DemandeDeRattachementMail`** (`app/Domain/Cabinets/Mail/`), pour un SIREN déjà pris.
- Objet : « {cabinet} demande à suivre votre entreprise sur PILIQI ».
- Corps : la dénomination et la ville du cabinet qui demande ; le SIREN invité, et rien d'autre de la société (ni sa dénomination enregistrée, ni son propriétaire) ; le fait que cette entreprise est déjà inscrite sur PILIQI et que seul son propriétaire, connecté à son compte, répond à la demande ; les trois choix de la page de rattachement ; les autorisations par défaut ; le lien `route('cabinets.rattachement.afficher', $jeton)` ; l'expiration.
- Au moment de l'envoi, la demande est relue : elle ne part que si elle est encore `demande_par_le_cabinet`, non expirée, et que le cabinet est encore vérifié. Sinon elle ne part pas, et le propriétaire du cabinet en est prévenu.

**`RetirerLInvitationAction`** : `handle(User $auteur, InvitationCabinet $invitation)`.
- Propriétaire du cabinet (Q-EC1).
- Verrou de la ligne de l'invitation. Seule une invitation `ouverte` passe à `retiree` (avec `close_le` et `retiree_par`). Une invitation déjà close renvoie « Cette invitation n'est plus ouverte. » sans erreur.
- Audit `InvitationRetiree`.
- Une demande de rattachement se retire par `RetirerLaDemandeDeLienAction` (3.5.8), qui efface son jeton.

#### 3.5.5 Acceptation ou refus d'une invitation (SIREN libre)

**`AccepterLInvitationAction`** : `handle(string $jeton, array $donnees): User`. `$donnees` contient `password`, `password_confirmation`, `type_activite` et `choix_definitif`, rien d'autre : en acceptant, la personne choisit son mot de passe et le type d'activité de la société (Q-EC1, Q-EC2 ; règle 11 de `decision-types-activite.md`). Le compte rendu est le nouveau propriétaire. Son nom est celui que V2 donne au propriétaire inscrit (`CreerSocieteAction` : `'name' => $destinataire->nom`) : le premier dirigeant qui a le pouvoir de représenter l'entreprise (`RepresentantRne::$pouvoirDeRepresentation`), nommé par `RepresentantRne::nomPourLeCourrier()` (une personne morale dirigeante, par sa dénomination). Ce dirigeant se lit par une méthode extraite de `DestinataireOfficiel::du()`, `representantLegal(string $siren): ?RepresentantRne`, qui n'exige pas d'adresse, rend `null` sans dirigeant qui a ce pouvoir et lève `RneIndisponibleException` ; `du()` l'appelle et garde ses messages : rien n'est copié. Sans représentant légal lisible, le nom est la dénomination lue au répertoire Sirene, ou « Propriétaire » quand le répertoire la masque (choix de Claude, section 8). Il le modifie dans son profil (`reponses-v2.md`, point 1).

1. `InvitationCabinet::parSonJeton($jeton)` : une invitation absente, close ou périmée lève `InvitationInvalideException`, avec un message unique : « Ce lien d'invitation n'est plus valable. Demandez une nouvelle invitation au cabinet. »
2. Validation :
   - `password`, par la règle du mot de passe du propriétaire, celle de l'inscription de V2 (8 caractères au moins, ni une suite ni un caractère répété) ;
   - `type_activite` requis, parmi `TypeDActivite::Commerce` et `TypeDActivite::Services` seulement : « Cabinet d'expertise comptable » est refusé (« Un client invité par un cabinet ne s'inscrit jamais comme cabinet d'expertise comptable. »), comme toute autre valeur ; aucune valeur par défaut ;
   - `choix_definitif` coché (« J'ai compris que ce type d'activité ne pourra plus changer. »), sinon rien n'est créé.
3. Hors transaction, tout ou rien, comme la confirmation d'une inscription par V2 et TA (règle 4a et K2 : les données légales viennent du registre au moment de l'acceptation) :
   - `ControleSirene::unite($siren)`, de nouveau à l'acceptation même si le cabinet l'a contrôlé à l'invitation (Q-EC1). Inconnue ou cessée → refus « L'entreprise n'est plus active au répertoire Sirene : l'invitation ne peut pas être acceptée. ». Injoignable → « Le répertoire Sirene ne répond pas : réessayez dans quelques minutes. ». Rien n'est écrit, et l'invitation reste ouverte. Le code d'activité lu ici est celui que `SocieteInscrite` retient ; il ne refuse rien, puisque le cabinet est exclu du menu.
   - `RegistreNationalDesEntreprises::identite($siren)` (3.5.6) et `DestinataireOfficiel::representantLegal($siren)`. `RneIndisponibleException` → « Le registre national des entreprises ne répond pas : réessayez dans quelques minutes. » ; rien n'est écrit et l'invitation reste ouverte.
   - Un SIREN inconnu du registre, ou sans représentant légal lisible, ne refuse rien : l'entreprise existe et est active au répertoire, et la règle 1 de `reponses-cabinets.md` veut alors la société créée et validée. Le courrier de V2 a besoin d'un destinataire ; l'invitation n'en a pas. L'identité est celle du registre quand il la donne, sinon celle du répertoire Sirene (`ControleSirene::identiteDepuisSirene()`, celle de l'inscription de V2) ; sans représentant, `representant_legal` reste nulle. Le propriétaire complète le reste dans `IdentiteSociete`.
4. Transaction, dans l'ordre des verrous de 3.5 :
   - verrou `FOR SHARE` de la ligne `societes` du cabinet (le `societe_id` lu à l'étape 1), puis verrou `FOR UPDATE` de l'invitation, en `withoutGlobalScopes()`, par son identifiant et son `societe_id`. Une invitation et la commande d'expiration prennent la société du cabinet puis les invitations : l'acceptation suit le même ordre et ne croise jamais leur passage à `expiree` en sens inverse ;
   - relecture : `estOuverte()`, sinon `InvitationInvalideException` ;
   - verrou `FOR SHARE` de la ligne `cabinets`, lue par `Societe::cabinet()` sans `SocieteScope` (aucun contexte n'est posé), puis `estUnCabinetVerifie()`, sinon refus « Le cabinet qui vous a invité ne peut plus inviter : inscrivez votre entreprise vous-même. » ;
   - l'adresse de l'invitation est déjà l'adresse de connexion d'un propriétaire (`FournisseurDesComptes::proprietaireDeLAdresse`, qui, depuis V2b, ne compte plus l'adresse d'une inscription close) → refus « Cette adresse est déjà l'adresse de connexion d'un compte PILIQI : demandez au cabinet une invitation à une autre adresse. » Seule la personne qui détient la boîte de cette adresse le lit. Une violation de `users_adresse_de_connexion` à la création du compte (deux acceptations simultanées à la même adresse, pour deux SIREN) est interceptée par son nom et rend le même message, rien n'étant créé (2.4) ;
   - création de la société : `type_activite` = le type choisi (`forceFill`, jamais une valeur par défaut ; la garde du lot TA refuse ensuite toute modification), SIREN, identité lue à l'étape 3 (3.5.6), `numero_tva = IdentifiantsInsee::numeroTvaPropose($siren)`, `representant_legal` (nulle sans représentant). Une `UniqueConstraintViolationException` de l'index de V2 → `SirenDejaInscritException`, traitée hors de la transaction (voir 5) ;
   - en `runAsSociete($client)` : le compte du propriétaire (`role_societe = proprietaire`, l'adresse de l'invitation, `email_verified_at = now()` puisque la personne a suivi le lien reçu à cette adresse, le nom fixé en tête de 3.5.5, mot de passe haché), son identifiant par `GenerateurDIdentifiants::attribuer($proprietaire, motDePasseHache: …)`, l'audit `SocieteInscrite` (`type_activite`, `code_activite` et `nomenclature_activite` lus à l'étape 3, `origine = invitation`), et l'inscription au registre des vérifications de V2 pour le contrôle Sirene ;
   - l'action de validation unique de V2 : `ValiderLaSocieteAction` avec `PreuveDeValidation::InvitationDuCabinet` et le cabinet. Elle inscrit `SocieteValidee` avec `preuve = invitation_cabinet` et `cabinet = {id, siren, raison_sociale}`. Elle n'envoie aucun courrier et aucune lettre au siège. Elle prend ses verrous dans la transaction ouverte : pas de magasin réel, donc pas de registre ; la ligne de la société ;
   - le lien : création d'un `LienCabinet` (`origine = invitation`, `etat = actif`, `actif_depuis = now()`, `demande_le = invitation.created_at`) et `accorder(PermissionRegistry::parDefautDuCabinet($type))`, par `JournalDesDroits::modifierLeLien()` : aucun magasin, donc aucun JET 130 ; audit `LienActive` dans les deux journaux. Une société « Services, sans vente d'articles » ne reçoit ainsi aucune autorisation de la famille `articles` ;
   - l'invitation passe à `acceptee`, avec `close_le`.
   - Les invitations des autres cabinets pour ce SIREN ne se touchent pas dans cette transaction. Une acceptation concurrente du même SIREN tient la sienne `FOR UPDATE` et attend l'index unique de cette transaction-ci : la modifier ici créerait un interblocage.
5. En cas de `SirenDejaInscritException`, rien n'est créé. Dans une transaction à part, l'invitation passe à `caduque`, avec `close_le` (audit `InvitationCaduque`, `raison = siren_pris`), et le propriétaire du cabinet est prévenu. La personne lit : « Cette entreprise vient d'être inscrite sur PILIQI : son propriétaire peut lier votre cabinet depuis son espace. »
6. Après le commit :
   - le propriétaire est connecté (`Auth::login`, session régénérée, `SuiviDesConnexions::noterLaConnexion`) et mené au tableau de bord de sa société (panneau `societe`) ;
   - `AvisDuCabinet` au propriétaire du cabinet : « {client} a accepté votre invitation » ;
   - le job `RendreCaduquesLesInvitationsDuSiren` (`afterCommit`, `app/Domain/Cabinets/Jobs/`) est envoyé. Il reçoit le SIREN et l'identifiant de l'invitation acceptée. Il lit les autres invitations ouvertes de ce SIREN (L8). Pour chacune, dans sa propre transaction, en `runAsSociete` de son cabinet, il la verrouille, la relit encore ouverte, la passe à `caduque`, avec `close_le` (audit `InvitationCaduque`, `raison = siren_pris`) et prévient le propriétaire de ce cabinet : « Votre invitation pour le SIREN … n'est plus valable : cette entreprise est désormais inscrite sur PILIQI. »
   - La commande horaire d'expiration (3.6) fait le même travail pour toute invitation ouverte dont le SIREN est désormais pris (un SIREN inscrit par V2 entre-temps, ou un job perdu).
7. Signature des attestations, règle 12 de V2 : un propriétaire validé par une invitation, et non par le code du courrier, signe le volet 2 à la main. Si V2 a codé le droit de signer dans l'application, il vérifie la preuve `CodeDuCourrier`.
8. Ensuite, la règle 10 de `decision-types-activite.md` vaut pour cette société comme pour toute société validée : personne ne change son type, par aucun écran ni aucune procédure de l'éditeur.

**`RefuserLInvitationAction`** : `handle(string $jeton): void`. La personne qui reçoit le lien refuse l'invitation (Q-EC1 : « accepte son invitation ou refuse »), sans compte ni mot de passe.
1. `InvitationCabinet::parSonJeton($jeton)` : une invitation absente, close ou périmée lève `InvitationInvalideException`, avec le message de l'acceptation.
2. Transaction, dans l'ordre de 3.5 : verrou `FOR SHARE` de la ligne `societes` du cabinet, puis `FOR UPDATE` de l'invitation ; relecture `estOuverte()` ; passage à `refusee`, avec `close_le` ; audit `InvitationRefusee` (`siren`) dans le journal du cabinet, au nom du système, en `runAsSociete($cabinet)`.
3. Après le commit : `AvisDuCabinet` au propriétaire du cabinet (base et e-mail) : « L'invitation pour le SIREN … a été refusée. » La page dit : « Vous avez refusé l'invitation. Aucune société n'a été créée. »
4. Un refus répété (deuxième POST) rend la page neutre de l'invitation invalide, sans rien écrire.
5. Le cabinet n'invite de nouveau ce SIREN qu'après 7 jours (3.5.4) ; la purge garde l'invitation refusée ce temps-là (3.6).

#### 3.5.6 Identité depuis le registre national (règle 4a, K2)

- **Contrat.** `RegistreNationalDesEntreprises` (`app/Integrations/Rne/Contracts/`) reçoit `identite(string $siren): ?IdentiteRne`. Elle rend `null` pour un SIREN inconnu (404) et lève `RneIndisponibleException`. L'acceptation ne refuse que dans ce second cas (3.5.5, étape 3).
- **`IdentiteRne`** (`app/Integrations/Rne/IdentiteRne.php`, `final readonly`) :
  - `denomination` ;
  - `categorieJuridique`, le code à 4 chiffres, qui alimente `FormeJuridique::depuisCategorieInsee()` ;
  - `capital` (?string décimal), `deviseCapital`, `capitalVariable` (?bool) ;
  - `immatriculation` (?string, ex. « RCS Lyon ») ;
  - `adresseDuSiege` (?AdressePostale).
  Le représentant légal n'y est pas : il se lit par `DestinataireOfficiel::representantLegal()` (3.5.5), le même dirigeant que V2 retient pour le courrier (le premier qui a `pouvoirDeRepresentation`).
- **`InpiRne::identite()`** lit la formalité déjà obtenue par `formalite()`. Les chemins JSON sont à confirmer dans le dictionnaire de données officiel du RNE (documentation technique des API formalités de l'INPI) avant tout code. Pistes :
  - `personneMorale.identite.entreprise.denomination` et `.formeJuridique` ;
  - `personneMorale.identite.description.montantCapital`, `.deviseCapital` et `.capitalVariable` ;
  - le greffe d'immatriculation, pour « RCS {ville du greffe} ».
  Un champ absent ou illisible donne `null` pour ce champ, sans rendre toute la réponse illisible, contrairement aux dirigeants. Le journal ne note jamais la réponse.
- **`FauxRne`** : une identité par défaut cohérente avec Jeanne DUPONT (SAS, capital 10 000 €, RCS Paris, siège déjà connu) et le réglage `identifier(string $siren, ?array $identite)` (`null` : SIREN inconnu du registre). Le réglage `dirigeants($siren, [])`, qui existe, donne une entreprise sans représentant.
- **`AccepterLInvitationAction`** convertit l'identité en colonnes de `societes` :
  - avec une `IdentiteRne`, par une méthode `ControleSirene::identiteDepuisLeRegistre(IdentiteRne, UniteSirene)` placée à côté d'`identiteDepuisSirene()` : `raison_sociale`, `categorie_juridique_insee`, `forme_juridique`, `capital_social`, `capital_variable`, `immatriculation`, `siege_*` ; un champ que le registre ne donne pas se prend dans `identiteDepuisSirene()` quand le répertoire le donne ;
  - sans `IdentiteRne` (SIREN inconnu du registre), par `identiteDepuisSirene(UniteSirene)` seule ;
  - `representant_legal` = « {nomPourLeCourrier()}, {rôle} » du dirigeant de `representantLegal()`, borné à 255 caractères, ou nulle ;
  - `numero_tva` calculé depuis le SIREN.
  Le propriétaire complète le reste dans `IdentiteSociete`, qui montre `Societe::mentionsLegalesManquantes()`.

#### 3.5.7 Désigner un cabinet (règle 4b, côté client)

**`DesignerUnCabinetAction`** : `handle(User $auteur, Societe $client, Societe $cabinet): LienCabinet`.
1. Policy `SocietePolicy::gererSonCabinet($auteur, $client)` : propriétaire actif, société qui n'est pas un cabinet (`! estUnCabinet()`), non révoquée, inscription non close (Q-EC7). Une société suspendue ou résiliée lie quand même un cabinet : couper ou donner un accès reste permis (3.4, gestes d'accès).
2. Le cabinet doit être `estUnCabinetVerifie()`, sinon « Ce cabinet ne peut pas encore être lié. ». Il ne doit pas être déjà lié : lien actif avec lui → « Ce cabinet est déjà votre cabinet. ».
3. `DebitDesEmails::verifier($auteur)` : la demande envoie un e-mail au propriétaire du cabinet.
4. Transaction : verrou de la société cliente (`FOR UPDATE`), puis relecture des liens de la paire, dans les deux sens, sous ce verrou, que prend aussi la demande d'un cabinet (3.5.4) :
   - une demande de ce cabinet à ce client en cours : désigner ce cabinet revient à accepter sa demande, avec les autorisations par défaut. L'action ne crée rien, valide sa transaction, puis délègue à `AccepterLaDemandeDeLienAction`, qui relit tout sous ses propres verrous (elle prend les registres avant la société cliente : l'appeler sous ce verrou inverserait l'ordre de 3.5). Si la demande a disparu entre-temps, l'acceptation répond « Cette demande n'est plus valable. » ;
   - une demande du client vers ce cabinet déjà en cours est rendue telle quelle : une demande répétée ne crée rien ;
   - une demande du client à ce cabinet refusée, retirée ou expirée depuis moins de 7 jours → refus « Vous avez déjà demandé ce lien il y a moins de 7 jours. » (choix de Claude, section 8) ;
   - sinon, création du `LienCabinet` (`designation_du_client`, `demande_par_le_client`, `expire_le = now() + 7 jours` (Q-EC6), sans jeton : le propriétaire du cabinet répond depuis son espace), puis audit `LienDemande` dans les deux journaux ;
   - une `UniqueConstraintViolationException` de `liens_cabinet_une_demande_par_paire`, que la relecture sous le verrou évite, est quand même traduite en refus « Une demande de lien est déjà en cours avec ce cabinet. ».
5. `AvisDuCabinet` au propriétaire du cabinet : base et e-mail, avec un lien vers « Invitations et demandes », puis `DebitDesEmails::compter($auteur)`.

**`RechercheDesCabinets::pour(string $terme): Collection`** (`app/Domain/Cabinets/Services/`) :
- 9 chiffres → recherche par SIREN exact ;
- sinon, au moins 3 caractères, par dénomination, sans casse ni accents (`RechercheSansAccents::appliquer`) ;
- seulement les cabinets vérifiés ;
- 20 résultats au plus ;
- les colonnes de la section 5, L4.

#### 3.5.8 Accepter, refuser, retirer une demande de lien

**`AccepterLaDemandeDeLienAction`** : `handle(User $auteur, LienCabinet $lien, ?array $autorisations = null): LienCabinet`.
- Auteur :
  - pour `demande_par_le_cabinet` (demande de rattachement), le propriétaire du client, dans le contexte du client, depuis la page de rattachement (4.2 bis) ou la page « Cabinet comptable » ;
  - pour `demande_par_le_client`, le propriétaire du cabinet (Q-EC1), contrôlé dans le contexte de son cabinet alors que `modifierLeLien()` travaille dans celui du client (règles communes de 3.5) ;
  - par `LienCabinetPolicy::accepter`.
- `$autorisations` (Q-EC1, « accepter en ajustant les autorisations ») :
  - `null` : les autorisations par défaut, `PermissionRegistry::parDefautDuCabinet($client->type_activite)` ;
  - une liste : seulement pour une demande de rattachement acceptée par le propriétaire du client ; elle est vérifiée par `PermissionRegistry::verifierDuCabinet($autorisations, $client->type_activite)`, qui refuse toute autorisation hors de la liste du cabinet pour ce type (erreur de champ à l'écran), et peut être vide ; le propriétaire du cabinet, qui accepte la demande d'un client, ne passe jamais de liste (`LogicException`).
- `JournalDesDroits::modifierLeLien($auteur, LienActive, $client, [$lien], …)`, sous les verrous de 3.5.1.5. La modification :
  1. relit le lien : toujours la même demande, non expirée (`expire_le` dans le futur), sinon `LogicException` (« Cette demande n'est plus valable. ») ;
  2. relit le cabinet par `Societe::cabinet()`, sans `SocieteScope` : vérifié, sinon refus (« Ce cabinet ne peut plus être lié. ») ;
  3. si le client a un lien actif avec un autre cabinet, ce lien passe à `termine` (`motif_fin = remplace`, `termine_par = auteur`), ses `clients_confies` sont effacés par `DB::table` (L12), et l'audit `LienTermine` va dans le journal de l'ancien cabinet (en plus de celui du client), avec les seuls droits de ses comptes et sans l'identité du nouveau cabinet (3.5.1.5) ;
  4. la demande passe à `actif` (`repondu_par`, `repondu_le`, `actif_depuis`, `jeton_hache = null`) ;
  5. `accorder()` des autorisations retenues, dans la même modification : un seul JET 130 par compte et par magasin, et une seule entrée `LienActive` par journal, avec `autorisations` = `par_defaut` ou `ajustees`.
- Accepter un rattachement ne valide jamais une société qui attend son code (Q-EC1) : aucune action de validation n'est appelée, et la page le dit (4.2 bis).
- JET 130 :
  - les comptes du nouveau cabinet qui ont accès, c'est-à-dire son propriétaire, car aucun collaborateur n'est encore confié, passent de null à leurs droits dans chaque magasin réel ;
  - ceux de l'ancien cabinet passent à null.
- Audits `LienActive` (client et cabinet).
- `AvisDuCabinet` à l'autre propriétaire, et, en cas de remplacement, au propriétaire de l'ancien cabinet et à ses collaborateurs confiés, dont les avis sur ce client s'effacent d'abord de la cloche (3.5.3).

**`RefuserLaDemandeDeLienAction`** : même auteur que pour accepter, contrôlé dans sa propre société ; pour une demande de rattachement, depuis la page de rattachement ou la page « Cabinet comptable ». En `runAsSociete($client)` : verrou de la société cliente puis du lien. `demande_*` → `refuse`, avec `jeton_hache = null`. Audit `LienRefuse` dans les deux journaux (3.1 : SIREN seul côté cabinet pour une demande du cabinet), notification à l'autre partie (pour une demande du cabinet, l'avis ne cite que le SIREN).

**`RetirerLaDemandeDeLienAction`** : l'auteur de la demande, c'est-à-dire le propriétaire de son côté, contrôlé dans sa propre société. En `runAsSociete($client)` : verrou de la société cliente puis du lien. `demande_*` → `retire`, avec `jeton_hache = null` : le lien de l'e-mail ne vaut plus rien. Audit `DemandeDeLienRetiree` dans les deux journaux.

**Expiration** (commande, 3.6) : une demande dont `expire_le` est passé passe à `expire`, avec `jeton_hache = null`. Audit `DemandeDeLienExpiree` dans les deux journaux, au nom du système.

#### 3.5.9 Régler les autorisations du cabinet (règle 5)

**`ReglerLesAutorisationsDuCabinetAction`** : `handle(User $auteur, LienCabinet $lien, array $autorisations): void`.
- Policy `LienCabinetPolicy::reglerLesAutorisations` : propriétaire du client, lien actif, client non révoqué.
- `PermissionRegistry::verifierDuCabinet($autorisations, $client->type_activite)` : une autorisation hors de la liste du cabinet pour le type du client lève `InvalidArgumentException`, traduite à l'écran en erreur de champ. Les autorisations des achats et du stock ne se proposent et ne s'accordent qu'à un client « Commerce ou atelier » (lot TA) : chez un client « Services, sans vente d'articles », elles sont refusées comme toute autre clé hors de la liste. La base les refuse aussi (garde `autorisations_cabinet_permises`).
- `JournalDesDroits::modifierLeLien($auteur, AutorisationsDuCabinetModifiees, $client, [$lien], …)` : relecture du lien actif, puis réécriture de `autorisations_cabinet`.
- JET 130 pour chaque compte du cabinet qui a accès. Audit dans les deux journaux.
- `AvisDuCabinet` au propriétaire du cabinet : « {client} a changé les autorisations de votre cabinet ».
- Une liste vide est permise : le cabinet reste lié et voit encore, dans son espace, l'identité, les réglages fiscaux communs et les magasins réels du client (L1, L2 bis), mais n'ouvre plus aucun document, aucune fiche ni aucun téléchargement de ses magasins. La page le dit avant d'enregistrer.

#### 3.5.10 Mettre fin au lien (règle 6)

**`MettreFinAuLienAction`** : `handle(User $auteur, LienCabinet $lien): void`.
- Auteur : propriétaire du client ou propriétaire du cabinet (Q-EC1), chacun contrôlé dans sa propre société (règles communes de 3.5). `LienCabinetPolicy::mettreFin`. Le propriétaire du cabinet y met fin même quand son cabinet n'est plus vérifié (L2 ter) ou est en lecture seule (3.4, gestes d'accès).
- `JournalDesDroits::modifierLeLien($auteur, LienTermine, $client, [$lien], …)` :
  - relecture du lien : un lien déjà terminé rend sans erreur, sans rien inscrire, pour une demande répétée ;
  - `etat = termine`, `termine_par`, `termine_le`, `motif_fin = par_le_client | par_le_cabinet` ;
  - effacement des `clients_confies` du lien, par `DB::table` filtré par `cabinet_id` et `lien_id` (L12) ;
  - suppression des `autorisations_cabinet` du lien, que la garde interdit sur un lien qui n'est plus actif.
- JET 130 : chaque compte qui avait accès passe à null dans chaque magasin réel du client. Audit `LienTermine` dans les deux journaux.
- Notifications : l'autre propriétaire (base et e-mail) et les collaborateurs confiés (base), dont les avis sur ce client s'effacent d'abord de la cloche (3.5.3).
- `parLEditeur(LienCabinet, MotifDeFinDuLien, InterventionDeLEditeur)`, sans auteur, pour la révocation d'un cabinet ou sa radiation constatée (Q-EC5) : même chemin, audit avec l'intervention de l'éditeur ; le propriétaire du client et celui du cabinet sont prévenus.
- `parLeSysteme(LienCabinet, MotifDeFinDuLien)`, sans auteur ni intervention, pour la fin du lien d'une société non validée (Q-EC7, 3.5.11) : même chemin, audit au nom du système. La fin y naît de la saisie du code ou de la clôture d'une inscription, pas d'une décision de l'éditeur.
- **Sessions** (Q-EC4, choix de Claude que l'éditeur a laissé tel quel et peut contester : `reponses-cabinets.md`, règle 7 ; il applique la règle 6 « l'accès s'arrête aussitôt, sessions comprises ») : aucune session n'est supprimée. Chaque requête suivante d'une session ouverte est refusée, car Filament relit `canAccessTenant()` et `ControleDesDroits` relit `peutOuvrir()`. Le compte garde son espace et ses autres clients. C'est prouvé par 6.10 et 6.15.

#### 3.5.11 Inscription close, ou propriétaire remplacé par la saisie du code (Q-EC7)

Réponse de l'éditeur (Q-EC7, option a) : le lien et les demandes en cours d'une société non validée prennent fin dans les deux cas, le cabinet est prévenu, le nouveau propriétaire choisit lui-même son cabinet, et une société close ne gère plus de cabinet. Ils prennent fin dans deux actions de V2 :
- la saisie du code par une autre personne que celle qui s'était inscrite (règle 8 de V2 : elle devient propriétaire, et les comptes antérieurs sont retirés ; `SaisirCodeDeConfirmationAction::reprendreLaSociete()` au HEAD `65819c6`) : `MettreFinAuLienAction::parLeSysteme($lien, MotifDeFinDuLien::ProprietaireRemplace)` ;
- la clôture d'une inscription, qui libère le SIREN et l'adresse de connexion de son propriétaire (`CloreLInscriptionAction`, telle que V2b et TA la laissent) : au bout de 30 jours sans code (`cloreSansCode()`, motif `DelaiExpire`), ou par la procédure de l'éditeur `editeur:societe:clore` (`parLEditeur()`, motifs `Refus` et `TypeErrone` du lot TA) : `parLeSysteme($lien, MotifDeFinDuLien::InscriptionClose)`. Sans elle, le cabinet resterait lié à une société close, et pourrait se lier ensuite à la nouvelle société du même SIREN.

Dans les deux cas :
- les demandes en cours, dans les deux sens, passent à `retire`, avec `jeton_hache = null` ;
- le tout dans la même transaction que l'action de V2, avec le JET 130 et les audits ; `modifierLeLien()` y prend ses verrous dans la transaction ouverte, qui tient déjà les registres et la société (voir 3.5 pour la clôture, qui prend désormais les registres avant la société), et l'ordre de 3.5 reste le même ;
- `SocietePolicy::gererSonCabinet()` refuse ensuite une société close (`close_le` de V2), dont le compte du propriétaire, gardé par V2b, ne s'ouvre plus qu'avec son identifiant.

Le cabinet est prévenu. Après un remplacement, la personne qui a saisi le code choisit ensuite son cabinet. Après une clôture pour type erroné, la nouvelle inscription du même SIREN est une autre société : aucun lien ni aucune demande n'y passe. La révocation d'une société par l'éditeur met aussi fin à ses liens (règle 4 de `reponses-cabinets.md` pour un cabinet, et la même règle que Q-EC7 pour un client). Aucune procédure de révocation n'existe au HEAD `65819c6` : le lot A7 la crée. EC fournit ce qu'elle appellera, `MettreFinAuLienAction::parLEditeur()`, déjà utilisée par le retrait de l'Ordre, et 3.5.14 donne au lot A7 la consigne et ses tests nommés.

#### 3.5.12 Inviter son expert-comptable à créer son espace (règle 7)

**`InviterSonCabinetACreerSonEspaceAction`** : `handle(User $auteur, Societe $client, string $email): void`. Son nom ne contient pas « ExpertComptable », pour que le contrôle de 7.1.3 ne rende rien.
- Propriétaire du client.
- `DebitDesEmails::verifier/compter($auteur)`, plus 5 par jour et par société (`RateLimiter`, clé `espace-cabinet:{societe}`).
- `InvitationACreerUnEspaceMail`, à l'adresse saisie : « {société} vous invite à créer l'espace de votre cabinet sur PILIQI ». Le lien mène à l'inscription avec `?type=cabinet`, qui présélectionne « Cabinet d'expertise comptable » dans le menu du lot TA (3.3), et le texte dit que ce type est définitif et que le cabinet sera vérifié (69.20Z, Ordre) avant tout lien.
- Audit `EspaceCabinetSuggere` (`envoye = true`), sans l'adresse.
- Rien d'autre : une fois le cabinet vérifié, l'une ou l'autre partie crée le lien (3.5.4 ou 3.5.7).

#### 3.5.13 Notifications et e-mails

- **`AvisDuCabinet`** (`app/Domain/Cabinets/Notifications/`), sur le modèle d'`AvisDeLEquipe` : base, et e-mail avec `$parCourriel`. Il porte la société du destinataire et ne dit que ce que le destinataire doit savoir : jamais un identifiant, jamais l'adresse d'un tiers. Quand il parle d'un client, il porte `client_id` dans ses données, pour s'effacer de la cloche d'un compte du cabinet qui perd l'accès à ce client (3.5.3, 3.5.8, 3.5.10). Tant que le lien n'est pas actif, un avis au cabinet sur une demande qu'il a envoyée ne cite que le SIREN.
- **Mails** : `InvitationDuCabinetMail`, `DemandeDeRattachementMail`, `InvitationACreerUnEspaceMail`. Les deux premiers partent à l'adresse saisie par le cabinet et nomment le cabinet qui invite (Q-EC1).
- Le propriétaire d'une société inscrite que vise une demande de rattachement reçoit aussi un `AvisDuCabinet` en base seulement (cloche), avec un lien vers la page de rattachement : l'e-mail est celui de la demande, à l'adresse saisie, et aucun second e-mail ne part.
- **Destinataires** : ceux décrits action par action. Leur lecture, le propriétaire d'une autre société, est déjà permise : « les destinataires des notifications ».

#### 3.5.14 Ce que le lot A7 reprend : la révocation d'un cabinet ou d'un client

La règle 4 de `reponses-cabinets.md` veut que la révocation d'un cabinet mette fin à tous ses liens, clients et cabinet prévenus ; Q-EC7 vaut de même pour un client révoqué. La procédure de révocation d'une société n'existe pas encore : le lot A7 la crée (puis l'étape 13 son écran). EC ne code pas la révocation ; il laisse au lot A7 cette consigne, à appliquer dans sa procédure :
- A7 ajoute la valeur `societe_revoquee` (« Société révoquée par l'éditeur ») à `MotifDeFinDuLien` et à la liste de la colonne `motif_fin` (2.2).
- Révocation d'un cabinet, en deux temps, comme `RetirerLInscriptionALOrdreAction` (3.3) : (1) une transaction verrouille la ligne `societes` du cabinet, puis sa ligne `cabinets` (`FOR UPDATE`), que toute acceptation de lien ou d'invitation tient en `FOR SHARE`, pose la révocation, inscrit l'audit (les têtes du journal en dernier, ordre de 3.5) et valide ; (2) ensuite, la procédure relit les liens actifs du cabinet et met fin à chacun par `MettreFinAuLienAction::parLEditeur($lien, MotifDeFinDuLien::SocieteRevoquee, $intervention)`, chacun dans sa propre transaction ; ses demandes en cours, dans les deux sens, passent à `retire` (jeton effacé) ; ses invitations ouvertes passent à `retiree`, sans auteur. Un lien accepté pendant le premier temps est dans la liste relue au second ; tout lien demandé ensuite est refusé, `estUnCabinetVerifie()` étant faux. Clients et cabinet sont prévenus.
- Révocation d'un client : dans la transaction de la révocation, qui prend les registres de ses magasins réels avant la société, comme la clôture d'une inscription (3.5), son lien actif prend fin par `parLEditeur()` avec le même motif, et ses demandes en cours passent à `retire` ; le cabinet est prévenu. `SocietePolicy::gererSonCabinet()` refuse déjà une société révoquée.
- Tests nommés, dans les tests du lot A7 : `test_la_revocation_d_un_cabinet_met_fin_a_tous_ses_liens_et_previent_clients_et_cabinet`, `test_la_revocation_d_un_cabinet_retire_ses_demandes_et_ses_invitations_ouvertes`, `test_la_revocation_d_un_client_met_fin_a_son_lien_et_previent_le_cabinet`, et sous PostgreSQL `test_une_acceptation_de_lien_pendant_la_revocation_du_cabinet_ne_laisse_aucun_lien_actif`.
- L'entrée A7 de `serie8-lots.js` doit citer cette sous-section : la chaîne ne donne pas ce cahier au lot A7 (signalé à l'orchestrateur, section 9).

### 3.6 Commandes planifiées

Déclarées dans `bootstrap/app.php`, `withSchedule`, avec leur commentaire :

| Commande | Fréquence | Ce qu'elle fait |
|---|---|---|
| `app:expirer-invitations-cabinets` (`app/Console/Commands/ExpirerInvitationsDesCabinets.php`) | toutes les heures | voir sous le tableau |
| `app:purger-invitations-cabinets` (`PurgerInvitationsDesCabinets.php`) | toutes les heures, comme `PurgerDemandesInscription` | efface toute invitation close (acceptée, retirée, expirée ou caduque) dont `close_le` a plus de 48 heures, et toute invitation refusée dont `close_le` a plus de 7 jours, cabinet par cabinet, filtrée par `societe_id` (choix de Claude, section 8 : le délai des demandes d'inscription, et celui avant une nouvelle invitation après un refus) |

Détail d'`app:expirer-invitations-cabinets`, société par société, au nom du système :
- pour chaque cabinet, dans une transaction : `runAsSociete($cabinet)`, verrou `FOR UPDATE` de la ligne `societes` du cabinet (ordre de 3.5), puis les invitations `ouverte` dont `expire_le <= now()` passent à `expiree`, avec `close_le` ; les invitations `ouverte` dont le SIREN est désormais pris (règle de V2, L6, simple booléen) passent à `caduque`, avec `close_le`, l'audit `InvitationCaduque` et l'avis au propriétaire, comme le job de 3.5.5 ;
- pour chaque client, dans une transaction : `runAsSociete($client)`, verrou de la société cliente puis des liens, et les demandes de lien `demande_*` périmées (Q-EC6 : 7 jours sans réponse) passent à `expire`, avec `jeton_hache = null` et l'audit dans les deux journaux (3.1 : SIREN seul côté cabinet pour une demande du cabinet) ;
- chaque requête est filtrée explicitement par `societe_id` ou `cabinet_id`.

### 3.7 Policies

| Policy | Gestes |
|---|---|
| `CabinetPolicy` (`Cabinet`) | `view` (comptes actifs du cabinet), `verifierLOrdre` (propriétaire, Ordre non vérifié, cabinet non révoqué) |
| `LienCabinetPolicy` | `viewAny`, `view` (côté cabinet : un lien de `liensOuverts()`, ou, pour le propriétaire du cabinet seul (Q-EC1), une demande de son cabinet ; côté client : propriétaire du client), `accepter` (côté client, pour une demande de rattachement, avec les autorisations par défaut ou ajustées : le propriétaire du client), `refuser`, `retirer`, `reglerLesAutorisations`, `mettreFin` (côté cabinet : son propriétaire, pour un lien actif de son cabinet, même non vérifié), `confier` ; chacun selon le contexte de la société de l'auteur (cabinet ou client), relu dans `TenantContext`, où l'action contrôle son auteur (règles communes de 3.5) |
| `InvitationCabinetPolicy` | `viewAny`, `view`, `create` et `retirer` : propriétaire du cabinet seul (Q-EC1). L'acceptation et le refus d'une invitation passent par le jeton du lien, sans compte (3.5.5). Un collaborateur ne voit ni les invitations ni les demandes : elles nomment des sociétés qui ne lui sont pas confiées (règle 3) |
| `SocietePolicy` (modifiée) | `gererSonCabinet()` (propriétaire, société qui n'est pas un cabinet, non révoquée, inscription non close (Q-EC7)) ; `voirLesParametres()` pour les comptes du cabinet dans l'espace du cabinet. `ouvrirLaDemonstration()`, `creerUnMagasin()` et `gererLesMagasins()` rendent déjà `false` pour un cabinet (lot TA) |
| `MembrePolicy` (modifiée) | voir 3.5.2 |

### 3.8 Panneau du cabinet (fournisseur)

**`app/Providers/Filament/CabinetPanelProvider.php`**, déclaré dans `bootstrap/providers.php` :

```php
$panel->id('cabinet')->path('cabinet')
    ->login(Connexion::class)
    ->profile(ModifierProfil::class)
    ->authenticatedRoutes(function (): void {
        AccepterLaTransmission::registerRoutes(); // transmission de la propriété du cabinet (Q-EC3)
    })
    ->tenant(Societe::class, slugAttribute: 'uuid')
    ->tenantMenu(false)
    ->tenantMiddleware([BindTenantContextFromFilamentTenant::class], isPersistent: true)
    ->colors(['primary' => Color::Sky])
    ->databaseNotifications()
    ->discoverResources(in: app_path('Filament/Cabinet/Resources'), for: 'App\\Filament\\Cabinet\\Resources')
    ->discoverPages(in: app_path('Filament/Cabinet/Pages'), for: 'App\\Filament\\Cabinet\\Pages')
    ->pages([TableauDeBordDuCabinet::class])
    ->middleware([...les mêmes que les deux autres panneaux...])
    ->authMiddleware([Authenticate::class], isPersistent: true)
    ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.verification-en-cours', ['magasin' => null, 'societe' => Filament::getTenant()]));
```

Son docblock dit :
- l'espace d'un cabinet, ouvert à son propriétaire et à ses collaborateurs actifs ;
- la seule lecture d'autres sociétés est celle des clients liés, explicite et filtrée (section 5) ;
- les magasins des clients s'ouvrent dans le panneau `app`.

Le bandeau `verification-en-cours` montre au cabinet le texte que le lot TA lui a donné (le seul titre « Cabinet en attente du code de son courrier recommandé. »), vrai aussi dans cet espace : EC n'y touche pas.

**`AppPanelProvider`** (`app/Providers/Filament/AppPanelProvider.php`) :
- `tenantMenu` (l.86) : visible pour un compte de cabinet dès que `getTenants()` rend plus d'un magasin, c'est-à-dire plusieurs magasins du client ouvert. Sinon la règle actuelle.
- `userMenuItems` : ajouter « Espace du cabinet » (icône `heroicon-o-briefcase`), qui mène à `TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: …)`, visible pour un compte de cabinet. « Administration de la société » reste visible si `peutAdministrer()`, ce qui exclut désormais le cabinet.
- `AucunMagasin::registerRoutes()` disparaît au commit 4.
- Le docblock (l.37-61) remplace les experts-comptables par « les comptes d'un cabinet lié, dans les magasins réels des clients qui leur sont confiés ».

**`SocietePanelProvider`** : docblock (l.37) réécrit. Le menu « Mes magasins » est inchangé.

**`MenerUnCompteSansMagasin`** (`app/Http/Middleware/MenerUnCompteSansMagasin.php`) :
- sur `filament.app.tenant`, un compte de cabinet est toujours mené à `TableauDeBordDuCabinet` de son cabinet ;
- sinon, la règle actuelle, sans la branche `aucun-magasin`, qui disparaît avec la page ;
- docblock réécrit.

**`BindTenantContextFromFilamentTenant`** : inchangé. Dans le panneau `cabinet`, il pose la société du cabinet.

**Arrivée du propriétaire d'un cabinet** (4.4) :
- `ConfirmerInscriptionController` (lot TA) mène le propriétaire d'un cabinet, après la confirmation de son inscription, à `TableauDeBordDuCabinet`, et non plus au tableau de bord de la société ;
- la connexion (`Connexion`) mène un compte de cabinet à son espace ;
- le panneau `societe` ne s'ouvre plus à un cabinet (`peutAdministrer()`, 3.2) : EC retire donc de `TableauDeBord` (espace de la société) et de `VerificationSociete` les branches provisoires du cabinet que le lot TA y a posées (liste d'étapes réduite au code et texte de l'état de la vérification dans `TableauDeBord`, texte du cabinet dans `VerificationSociete`), et les remplace par `TableauDeBordDuCabinet` et `VerificationDuCabinet` (4.1). Le rappel du type (règle 12 de `decision-types-activite.md`) n'est pas une branche du cabinet : `VerificationSociete` le garde pour toute société qui attend son code, et `VerificationDuCabinet` inclut la même vue partielle du lot TA (`resources/views/filament/rappel-du-type.blade.php`), sans copier son texte. Les autres textes du cabinet posés par TA (bandeau, avis de validation, avis et e-mail de clôture) restent tels quels. `IdentiteSociete` garde la ligne du type en lecture seule et le bloc d'assurance caché pour un cabinet (lot TA) : `IdentiteDuCabinet` en hérite.

### 3.9 Contrôleurs des liens envoyés par e-mail

**`app/Http/Controllers/AccepterLInvitationDuCabinetController.php`** (SIREN libre), avec trois méthodes :
- `afficher(string $jeton)` : n'écrit rien. Hors de toute transaction, elle relit l'invitation (L5) et lit le répertoire Sirene pour ce SIREN (`ControleSirene::unite()`), qui donne la dénomination et le code d'activité au moment où la personne choisit son type (règle 11 de `decision-types-activite.md`). Elle montre :
  - la dénomination et la ville du cabinet qui invite ;
  - le SIREN et la dénomination Sirene de l'entreprise invitée ;
  - les autorisations par défaut, en libellés ;
  - ce que fait l'acceptation : validation sans courrier ni QR code, identité lue au registre national (à défaut, au répertoire Sirene), nom du compte repris du représentant légal inscrit au registre (à défaut, la dénomination de l'entreprise), modifiable ensuite dans le profil ;
  - le formulaire : mot de passe et confirmation ; le menu « Votre activité » du lot TA sans l'entrée « Cabinet d'expertise comptable » (« Commerce ou atelier », « Services, sans vente d'articles »), sans valeur par défaut, avec les mêmes libellés, exemples et aides ; les avertissements du code d'activité lu, chacun avec les types qu'il concerne parmi les deux du menu, jamais bloquants, par `AvertissementsDuCodeDActivite::pourUneInvitation()` (méthode ajoutée par EC : la liste de `pour()`, où la ligne 69.20Z prend le texte propre à cette page, règle 8 de `decision-types-activite.md` : « Votre code d'activité est 69.20Z (activités comptables). Un cabinet d'expertise comptable ne s'inscrit pas par l'invitation d'un autre cabinet : si c'est votre cas, refusez cette invitation et inscrivez votre cabinet vous-même. ») ; jamais le texte de la page du lien, qui conseille une entrée absente de ce menu ; la phrase fixe du lot TA sous le menu ; la case obligatoire « J'ai compris que ce type d'activité ne pourra plus changer. » ;
  - un bouton « Refuser l'invitation », séparé du formulaire, avec une confirmation.
  Une invitation invalide donne une page neutre avec le message de 3.5.5. Une entreprise inconnue ou cessée au répertoire donne le refus de 3.5.5, sans formulaire ; un répertoire injoignable, « Le répertoire Sirene ne répond pas : réessayez dans quelques minutes. », sans formulaire.
- `accepter(Request, string $jeton)` : `AccepterLInvitationAction`, puis redirection vers le tableau de bord de la société. Une erreur de validation revient au formulaire, avec le type déjà choisi.
- `refuser(string $jeton)` : `RefuserLInvitationAction`, puis la page « Vous avez refusé l'invitation. Aucune société n'a été créée. »

**`app/Http/Controllers/RattachementAuCabinetController.php`** (SIREN déjà pris), avec une méthode :
- `ouvrir(string $jeton)` : n'écrit rien. Elle relit la demande par `LienCabinet::parSonJeton()` (L15). Une demande absente, plus en cours ou expirée donne une page neutre : « Ce lien n'est plus valable. Demandez au cabinet une nouvelle invitation. »
  - Personne n'est connecté : la page dit seulement que le cabinet {dénomination, ville} demande à suivre l'entreprise de ce SIREN, et que seul son propriétaire, connecté à son compte, y répond ; un bouton mène à la connexion du panneau `societe`, l'adresse du lien étant gardée comme adresse voulue (`redirect()->guest()`). La page ne montre rien d'autre de la société.
  - Le compte connecté est le propriétaire actif de la société visée (`societe_id` de la demande), société qui n'est ni close ni révoquée : redirection vers la page de rattachement de 4.2 bis, `RattachementDUnCabinet::getUrl(['lien' => $lien->uuid], panel: 'societe', tenant: $societe)`. L'uuid de la société n'apparaît ainsi jamais dans l'e-mail.
  - Tout autre compte connecté (autre compte de la même société, compte d'une autre société, compte d'un cabinet) : une page neutre, qui ne nomme pas la société : « Seul le propriétaire de l'entreprise invitée répond à cette demande. Connectez-vous avec son compte. »

Routes dans `routes/web.php`, au format de l'inscription :
- `Route::get('/invitation-cabinet/{jeton}', …)->where('jeton', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('cabinets.invitation.afficher')` (la page lit le répertoire Sirene) ;
- `Route::post('/invitation-cabinet/{jeton}', …)->middleware('throttle:10,1')->name('cabinets.invitation.accepter')` ;
- `Route::post('/invitation-cabinet/{jeton}/refus', …)->middleware('throttle:10,1')->name('cabinets.invitation.refuser')` ;
- `Route::get('/rattachement-cabinet/{jeton}', …)->where('jeton', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('cabinets.rattachement.afficher')`.

Vues : `resources/views/cabinets/invitation.blade.php`, `invitation-refusee.blade.php`, `invitation-invalide.blade.php`, `rattachement.blade.php` et `rattachement-invalide.blade.php`, en français, sans aucune donnée d'un autre cabinet ni, pour le rattachement, de la société avant la connexion de son propriétaire.

### 3.10 Ce qui disparaît au commit 4

**Code supprimé :**
- `RoleSociete::ExpertComptable` et son libellé ;
- `User::estExpertComptable()` et ses branches : l.341, l.655-664, l.730-731, l.752-753 ;
- `app/Domain/Tenancy/Actions/CreerExpertComptableAction.php` ;
- `ChangerLeRoleDeSocieteAction::versExpertComptable()` (l.121-132) et la mention dans son docblock (l.34-39), car un compte d'une société qui n'est pas un cabinet ne devient jamais collaborateur ;
- `ChangerStatutCompteAction::reintegrerLExpertComptable()`, renommée et réécrite pour le collaborateur au commit 2 ;
- `ActionAuditee::ExpertComptableCree` ;
- `MembrePolicy::creerLExpertComptable()` ;
- `ListMembres::creerUnExpertComptableAction()` (l.214-237) et le bouton d'en-tête (l.56) ;
- `FicheDuMembre` : l'option de rôle (l.140), `versExpertComptable` (l.188), la réintégration d'expert (l.291, l.300-301) et `peutReintegrer` (l.324-325) ;
- `MembreResource` : la section « Magasin » masquée pour lui (l.173) et `resumeDesMagasins()` (l.223-224) ;
- `ControleDesDroits::estUneConsultationPermise()` et la ligne 103 ;
- `GardesDesDroits` : les mentions ;
- `JournalDesDroits` : les branches (l.182, l.204) ;
- `app/Filament/App/Pages/AucunMagasin.php`, sa vue `resources/views/filament/app/pages/aucun-magasin.blade.php` et sa route ;
- `MenerUnCompteSansMagasin` : la branche ;
- l'aide de test `TestCase::expertComptable()`.

**Docblocks réécrits** (liste de la carte) :
- `RoleMagasin.php:20`, `UserFactory.php:28-29`, `CreationDesComptes`, `RegleDuMotDePasse:15`, `ChangerSonMotDePasseAction:16` ;
- `FermerMagasinAction:44,93`, `RouvrirMagasinAction:36`, `TenancyServiceProvider:36` ;
- `InventaireOuverturePolicy:22`, `EnregistrementFiscalPolicy:12`, `ClotureCaissePolicy:16`, `AffectationPolicy`, `ClotureCaissePersonnellePolicy`, `MagasinDeLaSocietePolicy` ;
- `ExigerSonPropreMotDePasse:13`, `ChoisirSonMotDePasse:21`, `EquipeDuMagasinResource:50`, `Societe/Pages/TableauDeBord.php:141` (texte de l'étape « équipe ») ;
- `ValeurStockApercu:34`, `ViewClient:14`, `ClotureCaissePersonnelleResource:27`, `BienvenueApercu:15`, `FacturesAEncaisserApercu:30` ;
- `PermissionRegistry` (l.22, 91, 99, 118, 158, 265-269), `AutorisationsSociete:11` ;
- `TestCase::casesDeLAncienComptable()` : « un cabinet lié, en consultation, le remplace » ;
- les migrations : users l.10, 20, 23, 38 ; identifiants l.17 ; affectations l.22 ; autorisations_societe l.13.

**Mentions conservées**, car elles désignent l'expert-comptable de la société comme personne : `TerritoireTva:21`, `ApercuEReporting:20`, `EReportingDesParticuliers:65,84`, `StockDuMagasinCede:31`, `ModifierMagasin:271`. Contrôle en deux temps :
- la commande de 7.1.3, sans `-i`, sur les noms de code, ne rend rien ;
- `grep -rn -i "expert" app database resources routes config` se relit à la main : chaque ligne restante est l'une de ces mentions, ou un texte du cabinet (« cabinet d'expertise comptable », « Ordre des experts-comptables », textes de l'invitation à créer un espace).

---

## 4. Écrans

Textes en français, au vouvoiement et au style des écrans existants. Chaque page déclare `canAccess()`, et chaque ressource hérite d'une base en mode strict.

### 4.1 Panneau du cabinet (`app/Filament/Cabinet`)

**`RessourceDuPanneauCabinet`** : `abstract`, `protected static bool $shouldCheckPolicyExistence = false;` (même raison que `RessourceDuPanneauSociete`).

**`Pages/TableauDeBordDuCabinet`** (page d'accueil) :
1. **« Bonjour {prénom nom}, {libelleDuRoleDeSociete()} ».**
2. **Liste d'étapes**, tant que le cabinet n'est pas vérifié, pour le propriétaire : chaque étape indique « fait » ou « à faire », avec son lien. Elle remplace la liste réduite au code que le lot TA a posée dans le tableau de bord de la société (3.8).
   - saisir le code du courrier : la page du code de V2 ;
   - activité 69.20Z : contrôlée, ou à contrôler par l'éditeur ;
   - inscription à l'Ordre : `VerificationDuCabinet` ;
   - créer vos collaborateurs : `CollaborateurResource` ;
   - inviter vos clients : `InvitationsEtDemandes`.
3. **Tableau « Vos clients »**, une ligne par client lié ouvert au compte, servi par `TableauDeBordDesClients::pour(User)` (section 5, L1). Colonnes :
   - client (dénomination) ; SIREN ;
   - statut (« Validée », « En attente du courrier », « Lecture seule ») ;
   - fin d'exercice (mois) ;
   - magasins réels (nombre) ;
   - « Factures à encaisser » : nombre, restant dû, dont en retard (en euros, depuis les centimes), ou « — » quand le client n'accorde pas `facture.view` et `facture.view_toutes` ;
   - lié depuis ;
   - bouton « Ouvrir », vers `FicheDuClient`.
   Aucun nom de client final, aucun numéro de document. Le tableau est vide tant que le cabinet n'est pas vérifié (`liensOuverts()`, L2).
4. **Tableau « Liens actifs »**, pour le seul propriétaire d'un cabinet qui n'est plus vérifié ou plus actif (Q-EC5) et garde des liens : dénomination, SIREN, lié depuis, et l'action « Mettre fin au lien » (`liensActifsDuCabinet()`, L2 ter). Rien d'autre du client : ni fiche, ni magasin, ni total. Un texte dit que l'accès aux clients reprendra quand le cabinet sera de nouveau vérifié.
5. Ce tableau de bord n'a aucune méthode publique Livewire qui reçoit l'identifiant d'un client ou d'un magasin : même protection que `LesCaisses`.
6. Note sous le tableau : « Les écritures, l'export comptable, l'état de TVA et les rapports de chaque client s'afficheront ici avec les prochaines étapes de PILIQI. »

**`Pages/VerificationDuCabinet`** (propriétaire ; les collaborateurs la voient en lecture seule) :
- **État du courrier** : un composant ou un lien vers la page du code de V2, avec, tant que le code n'est pas saisi, le rappel du type du lot TA (règle 12 de `decision-types-activite.md`), par sa vue partielle `resources/views/filament/rappel-du-type.blade.php`, incluse sans copier son texte. Ni cette page ni aucune autre du panneau ne parle de magasins ou de démonstration.
- **Activité.** Le code lu, la nomenclature et l'état : contrôlé, refusé, ou à contrôler par l'éditeur.
- **Ordre.**
  - Formulaire « N° d'inscription au tableau » et « Conseil régional », puis l'action « Vérifier » (`VerifierLOrdreAction`).
  - Avec le pilote `editeur`, le bouton s'appelle « Demander la vérification » et la page dit : « L'éditeur vérifie votre inscription au tableau de l'Ordre et vous prévient. »
  - État : vérifiée le … (par l'annuaire ou par l'éditeur), demandée le …, ou refusée (motif). Elle affiche aussi la radiation constatée (Q-EC5).

**`Pages/IdentiteDuCabinet`** : sous-classe d'`App\Filament\Societe\Pages\IdentiteSociete`, avec `canAccess` adapté (comptes du cabinet ; modification par le propriétaire, `SocietePolicy::modifierLesParametres`) et la même action `ModifierIdentiteSocieteAction`. Elle hérite du lot TA la ligne du type en lecture seule et l'absence du bloc d'assurance pour un cabinet. Les champs du capital et de l'immatriculation restent, car un cabinet est une société commerciale ou libérale. Si la sous-classe ne fonctionne pas proprement entre deux panneaux, crée une page propre qui réutilise le schéma du formulaire par une méthode statique partagée. Ne duplique pas le formulaire.

**`Pages/InvitationsEtDemandes`** (propriétaire du cabinet seul, Q-EC1 : `canAccess()` et `InvitationCabinetPolicy::viewAny`. Un collaborateur reçoit 403 : les invitations, même acceptées, et les demandes nomment des sociétés qui ne lui sont pas confiées, ce que la règle 3 interdit) :
- **En-tête : action « Inviter un client ».** Champs SIREN (le seul identifiant accepté : aucune recherche par nom), adresse e-mail à laquelle part le lien, et mot de passe redemandé. L'aide dit qu'un SIREN libre reçoit une invitation à créer son espace, et un SIREN déjà inscrit une demande de rattachement, que son propriétaire voit aussi dans son espace. Elle appelle `InviterUneEntrepriseAction`, puis une notification selon le `ResultatDInvitation`. Elle est désactivée, avec le texte de `CabinetNonVerifieException`, tant que le cabinet n'est pas vérifié.
- **Tableau « Invitations »** (SIREN libres) : SIREN, adresse, envoyée le, par, expire le, état (ouverte, acceptée, refusée, retirée, expirée, caduque). Action « Retirer ». Une invitation close disparaît 48 heures après sa fin, et une invitation refusée 7 jours après (purge, 3.6).
- **Tableau « Demandes de lien »** :
  - demandes de rattachement envoyées par le cabinet (SIREN déjà inscrits) : le seul SIREN saisi par le cabinet, envoyée le, expire le, état. Action « Retirer ». L'adresse saisie n'y figure pas : PILIQI ne la garde pas. Jamais la dénomination ni rien d'autre de la société tant que le lien n'est pas actif : la société n'a encore rien accepté, et sa dénomination enregistrée dans PILIQI a pu être saisie par son propriétaire (entreprise non diffusible) ;
  - reçues des clients : client (dénomination, SIREN, ville du siège), reçue le, expire le. Actions « Accepter » et « Refuser ». Le client a choisi de se faire connaître de ce cabinet en le désignant.
- Chaque tableau lit `LienCabinet::duCabinet($cabinet)` ou `InvitationCabinet` (`SocieteScope`), avec les colonnes de L11. Aucun paramètre de requête ne désigne un autre cabinet.

**`Resources/ClientDuCabinetResource`** (modèle `LienCabinet`, liens actifs ouverts au compte) :
- `getEloquentQuery()` = `AccesDesCabinets::liensOuverts(auth()->user())->with(...)` (section 5, L2), jamais `LienCabinet::query()`.
- `resolveRecordRouteBinding()` passe par cette même requête : un uuid de lien d'un autre cabinet, ou d'un client non confié, rend 404.
- **`ListClientsDuCabinet`** :
  - colonnes : dénomination, SIREN, ville du siège, lié depuis, nombre de magasins réels, et collaborateurs confiés (propriétaire seulement, noms) ;
  - recherche par dénomination ou SIREN ;
  - actions de ligne « Ouvrir », « Confier » (propriétaire : choix multiple des collaborateurs, `ConfierDesClientsAction`, appelée collaborateur par collaborateur) et « Mettre fin au lien » (propriétaire, confirmation qui dit que l'accès s'arrête aussitôt pour tout le cabinet).
- **`FicheDuClient`** :
  - identité légale, type d'activité et réglages fiscaux communs, en lecture ;
  - autorisations accordées (libellés de `libellesDuCabinet($client->type_activite)`) ;
  - magasins réels : nom, code, ville, statut, et bouton « Ouvrir le magasin », vers `/app/{magasin:uuid}` ;
  - collaborateurs confiés (propriétaire) ;
  - historique du lien : origine, depuis, par qui (noms figés).

**`Resources/CollaborateurResource`** (modèle `User`) :
- `getEloquentQuery()` = `PorteeDesDroits::comptesVisibles($auteur)` filtré par `role_societe = collaborateur`, vide pour un collaborateur.
- Il réutilise `GereLesComptes` : création avec `fenetreDuCompteCree` en mode présent d'office, suspendre, réactiver, retirer, réintégrer (`reintegrerLeCollaborateur`), redonner l'accès, afficher l'identifiant au propriétaire (`voitLIdentifiant`).
- **`ListCollaborateurs`** : nom, identifiant (propriétaire), statut, mot de passe encore l'identifiant (rappel), première connexion, nombre de clients confiés. Action d'en-tête « Créer un collaborateur » (`MembrePolicy::creerUnCollaborateur`).
- **`FicheDuCollaborateur`** : les actions ci-dessus, et la section « Clients confiés » (cases des liens actifs, `ConfierDesClientsAction`). Elle porte aussi l'action « Transmettre la propriété du cabinet » (Q-EC3).

### 4.2 Espace de la société cliente : page « Cabinet comptable »

**`app/Filament/Societe/Pages/CabinetComptable.php`**. Accès par `canAccess()` : `SocietePolicy::gererSonCabinet()`, donc le propriétaire d'une société qui n'est pas un cabinet. Navigation « Cabinet comptable », icône `heroicon-o-briefcase`. La page a trois états.

1. **Un cabinet est lié.**
   - Carte : dénomination, SIREN, ville, n° d'inscription à l'Ordre, lié depuis, et les personnes du cabinet qui ont accès (noms, section 5, L3).
   - Formulaire « Ce que votre cabinet peut faire » : une case par autorisation de `PermissionRegistry::duCabinet($societe->type_activite)`, en deux groupes, « Consulter » (précochées par défaut) et, pour une société « Commerce ou atelier » seulement, « Achats et stock ». Bouton « Enregistrer » → `ReglerLesAutorisationsDuCabinetAction`.
   - Texte fixe : « Jamais la vente, l'encaissement, la caisse, les comptes ni les réglages de la société. »
   - Action « Mettre fin au lien » : confirmation, puis `MettreFinAuLienAction`.
2. **Demandes en cours.**
   - Reçues des cabinets (demandes de rattachement) : dénomination, SIREN, ville, reçue le, expire le, et un bouton « Répondre », qui ouvre la page de rattachement de cette demande (4.2 bis), avec ses trois choix.
   - Envoyées : action « Retirer ».
3. **« Désigner votre cabinet ».**
   - Champ de recherche, par SIREN ou par nom, sur `RechercheDesCabinets`. Les résultats montrent dénomination, SIREN, code postal et ville.
   - Action « Demander le lien » → `DesignerUnCabinetAction`.
   - Sous la recherche : « Votre expert-comptable n'est pas encore sur PILIQI ? », action « L'inviter à créer son espace », avec une adresse e-mail → `InviterSonCabinetACreerSonEspaceAction`.

Une société qui attend son courrier voit, sous l'en-tête : « Lier un cabinet ne valide pas votre société : saisissez le code du courrier envoyé à votre siège. »

Le `TableauDeBord` de la société (`app/Filament/Societe/Pages/TableauDeBord.php`) ajoute, pour le propriétaire, une ligne « Cabinet comptable : {dénomination} » ou « Aucun cabinet lié », avec un lien vers la page, et, pour chaque demande de rattachement en cours, « {cabinet} demande à suivre votre société », avec un lien vers sa page de rattachement. Ce n'est pas une étape de la liste, qui reste celle de CLAUDE.md.

### 4.2 bis Espace de la société cliente : page de rattachement (Q-EC1)

**`app/Filament/Societe/Pages/RattachementDUnCabinet.php`**, page du panneau `societe`, au chemin `rattachement-cabinet/{lien}` (uuid du lien), sans entrée de navigation. Le lien de l'e-mail d'une demande y mène après la connexion du propriétaire (3.9), et le bouton « Répondre » de la page « Cabinet comptable » aussi.
- Accès par `canAccess()` : `SocietePolicy::gererSonCabinet()`. Le lien se résout dans la société courante par `LienCabinet::query()` (`SocieteScope`) : un uuid d'une autre société, ou un lien qui n'est pas une demande `demande_par_le_cabinet` en cours et non expirée, rend la page neutre « Cette demande n'est plus valable. », sans rien d'autre.
- Elle montre le cabinet qui demande : dénomination, SIREN, ville du siège, n° d'inscription à l'Ordre (L3), et dit que l'expert-comptable consultera vos magasins réels selon les autorisations choisies.
- Trois choix :
  1. « Accepter avec les autorisations par défaut » : liste en libellés de `PermissionRegistry::parDefautDuCabinet($societe->type_activite)`, confirmation, puis `AccepterLaDemandeDeLienAction($auteur, $lien)`.
  2. « Accepter en ajustant les autorisations » : les cases de `duCabinet($societe->type_activite)`, en deux groupes, « Consulter » (précochées) et, pour une société « Commerce ou atelier » seulement, « Achats et stock » (non cochées), avec le texte fixe de 4.2 ; confirmation, puis `AccepterLaDemandeDeLienAction($auteur, $lien, $autorisations)`. Une liste vide est permise, avec l'avertissement de 3.5.9.
  3. « Refuser » : confirmation, puis `RefuserLaDemandeDeLienAction`.
- Si la société a déjà un cabinet lié, la page le dit avant les choix : « Accepter remplace votre cabinet actuel, {dénomination}, dont l'accès s'arrête aussitôt. »
- Une société qui attend son courrier voit : « Accepter ce rattachement ne valide pas votre société : saisissez le code du courrier envoyé à votre siège. »
- Après un choix, la page mène à « Cabinet comptable », avec une notification.
- Aucune méthode publique Livewire ne reçoit l'identifiant d'une autre demande que celle de la page : même protection que `LesCaisses`.

### 4.3 Panneau d'un magasin, pour un compte de cabinet

- Il voit les ressources, les pages et les widgets que ses autorisations ouvrent. Les consultations sont les mêmes que celles d'un observateur qui a ces cases. La caisse, « Ma caisse » ou « Les caisses », l'équipe et les paramètres restent cachés, sauf `cloture_caisse.view_toutes` pour « Les caisses » en lecture.
- Le bandeau « magasin en lecture seule » et celui de la vérification en cours s'affichent comme pour tout compte.
- `BienvenueApercu` dit « Bonjour {nom}, Collaborateur du cabinet {X} · {magasin} ».
- Le menu utilisateur porte « Espace du cabinet ».
- Le sélecteur ne liste que les magasins réels du client ouvert.
- Chez un client « Services, sans vente d'articles », les écrans des articles (produits, achats, fournisseurs, inventaire, prix de vente, aperçus de stock) n'existent pas (lot TA), pour un compte de cabinet comme pour tout compte : leurs autorisations ne s'accordent pas au cabinet.

### 4.4 Inscription

- Le formulaire d'inscription, la page du lien d'inscription et le courrier sont ceux de V2 et du lot TA : l'entrée « Cabinet d'expertise comptable » du menu « Votre activité », son aide, le contrôle 69.20Z, la case du choix définitif et les rappels du type existent déjà. EC n'y ajoute que la présélection par `?type=cabinet` (3.3).
- Après la confirmation de son inscription, et à chaque connexion, le propriétaire d'un cabinet est mené à `TableauDeBordDuCabinet`, et non à l'espace de la société (3.8). EC remplace la liste d'étapes réduite que le lot TA a posée dans l'espace de la société par celle du cabinet (4.1), et retire les branches provisoires du cabinet de `TableauDeBord` et de `VerificationSociete` (3.8) ; `VerificationSociete` garde le rappel du type pour toute autre société qui attend son code.
- Un client invité par un cabinet choisit son type sur la page d'acceptation de l'invitation (3.9), jamais à l'inscription : il ne passe pas par le formulaire d'inscription.

---

## 5. Isolation et lectures permises

Toute lecture ci-dessous se fait hors du client courant, ou hors de la société du contexte. Chacune :
- passe par une méthode nommée, dans un service ou un modèle, dont le docblock cite CLAUDE.md ;
- retire explicitement le scope (`withoutGlobalScope(SocieteScope::class)` ou `withoutGlobalScope(MagasinScope::class)`) ;
- filtre par `cabinet_id`, `societe_id` ou `magasin_id` ;
- sélectionne les seules colonnes nommées ;
- est couverte par un test qui vérifie les colonnes lues (`DB::listen`), sur le modèle de `FacturesAEncaisserDeLaSocieteTest::141-162`.

Aucune autre requête du lot ne contourne un scope. Le relecteur cherche `withoutGlobalScope`, `DB::table` et `withoutGlobalScopes` dans le diff, et chaque occurrence doit être l'une de celles-ci.

**L1 — Tableau de bord du cabinet** (`TableauDeBordDesClients::pour(User $compte)`).

```php
$liens = AccesDesCabinets::liensOuverts($compte)          // voir L2
    ->get(['liens_cabinet.id', 'liens_cabinet.societe_id', 'liens_cabinet.actif_depuis']);
$clients = Societe::query()->whereKey($liens->pluck('societe_id'))
    ->get(['id', 'uuid', 'siren', 'raison_sociale', 'siege_ville', 'mois_fin_exercice', 'statut', 'validee_le']);
$magasins = Magasin::query()->whereIn('societe_id', $clients->modelKeys())
    ->where('est_demonstration', false)->orderBy('id')
    ->get(['id', 'societe_id', 'statut']);
// Magasins dont les factures à encaisser se montrent : ceux où le compte a
// facture.view et facture.view_toutes (User::suitLesFacturesAEncaisserDans()).
$suivis = $magasins->filter(fn (Magasin $m) => $compte->suitLesFacturesAEncaisserDans($m))->modelKeys();
$imputations = fn (HasMany $a) => $a->withoutGlobalScope(MagasinScope::class)
    ->whereIn($a->qualifyColumn('magasin_id'), $suivis)->select(['id', 'facture_id', 'montant_alloue']);
$factures = Facture::withoutGlobalScope(MagasinScope::class)
    ->whereIn('factures.magasin_id', $suivis)->aEncaisser()
    ->with(['allocationsPaiement' => $imputations, 'allocationsAvoir' => $imputations])
    ->get(['id', 'magasin_id', 'total_ttc', 'date_echeance']);
// Totaux en centimes par Facture::totauxAEncaisser(), sommés par client, jamais par la base.
```

Ce que la lecture rend, par client : identité réduite, nombre de magasins réels, totaux, ou `null` si aucun magasin n'est suivi.

**L2 — Liens d'un compte de cabinet** (`AccesDesCabinets::liensDuCompte(User $compte)` et `liensOuverts(User $compte)`).

```php
// liensDuCompte() : sans l'état du cabinet (droits enregistrés, JET 130)
LienCabinet::withoutGlobalScope(SocieteScope::class)
    ->where('liens_cabinet.cabinet_id', $compte->societe_id)
    ->where('liens_cabinet.etat', EtatDuLien::Actif->value)
    ->when(! $compte->estProprietaire(), fn (Builder $liens) => $liens->whereIn('liens_cabinet.id',
        DB::table('clients_confies')                            // jamais ClientConfie::query() : son SocieteScope
            ->where('societe_id', $compte->societe_id)          // lèverait une exception hors contexte et filtrerait
            ->where('user_id', $compte->getKey())               // sur le client dans un magasin du client
            ->select('lien_id')))

// liensOuverts() : ce que le compte voit et ouvre
$compte->societe->estUnCabinetVerifie() ? liensDuCompte($compte) : liensDuCompte($compte)->whereRaw('1 = 0')
```

Rien pour un compte retiré ou qui n'est pas d'un cabinet (`whereRaw('1 = 0')`). `estUnCabinetVerifie()` lit `Societe::cabinet()` sans `SocieteScope` (L13).

Autour de ces requêtes :
- `magasinsOuverts($compte)` = `Magasin::query()->whereIn('societe_id', liensOuverts($compte)->select('liens_cabinet.societe_id'))->where('est_demonstration', false)` ; `magasinsDuCompte($compte)`, la même sur `liensDuCompte()`.
- `ouvre($compte, $magasin)` = `! $magasin->est_demonstration && liensOuverts($compte)->where('liens_cabinet.societe_id', $magasin->societe_id)->exists()`.
- Ces lectures se font hors contexte (`canAccessTenant()`, connexion) comme dans le contexte d'un client : aucune ne passe par un `SocieteScope`.
- `autorisationsEnregistrees()` lit `DB::table('autorisations_cabinet')->where('societe_id', $magasin->societe_id)->where('lien_id', $lien->id)->orderBy('autorisation')->pluck('autorisation')`.

**L2 bis — Fiche du client** (`FicheDuClient`) :
- identité : `Societe::query()->whereKey($lien->societe_id)->first([...Societe::CHAMPS_DE_L_IDENTITE, 'id', 'siren', 'type_activite', 'regime_tva', 'option_debits_tva', 'mois_fin_exercice', 'statut', 'validee_le'])` ; le type sert aux libellés des autorisations (`libellesDuCabinet()`) ;
- magasins : `Magasin::query()->where('societe_id', $lien->societe_id)->where('est_demonstration', false)->get(['id', 'uuid', 'nom', 'code', 'ville', 'statut'])` ;
- autorisations : comme en L2.

Le lien est toujours celui que rend `liensOuverts()` : un cabinet qui n'est plus vérifié n'ouvre aucune fiche (404).

**L2 ter — Liens actifs d'un cabinet qui n'est plus vérifié** (`AccesDesCabinets::liensActifsDuCabinet(Societe $cabinet)`), pour son seul propriétaire, afin qu'il puisse y mettre fin :
- `LienCabinet::withoutGlobalScope(SocieteScope::class)->where('cabinet_id', $cabinet->id)->where('etat', 'actif')->orderBy('id')->get(['id', 'uuid', 'societe_id', 'actif_depuis'])` ;
- `Societe::query()->whereKey(...)->get(['id', 'siren', 'raison_sociale'])` ;
- rien d'autre du client (ni fiche, ni magasin, ni total).

**L3 — Côté client, le cabinet lié ou qui demande** (`CabinetComptable`, `RattachementDUnCabinet`) :
- cabinet : `Societe::query()->whereKey($lien->cabinet_id)->first(['id', 'siren', 'raison_sociale', 'siege_code_postal', 'siege_ville'])` ;
- Ordre : `Cabinet::withoutGlobalScope(SocieteScope::class)->where('societe_id', $lien->cabinet_id)->value('numero_ordre')` ;
- personnes qui ont accès, lien actif seulement : `User::query()->where('societe_id', $lien->cabinet_id)->where('statut', StatutCompte::Actif->value)->where(fn ($c) => $c->where('role_societe', 'proprietaire')->orWhereIn('id', DB::table('clients_confies')->where('societe_id', $lien->cabinet_id)->where('lien_id', $lien->id)->select('user_id')))->orderBy('name')->get(['id', 'name'])` ;
- liens du client : `LienCabinet::query()` (`SocieteScope` du client) ;
- jamais l'identifiant ni l'adresse d'un compte du cabinet.

**L4 — Recherche des cabinets vérifiés** (`RechercheDesCabinets::pour()`).

```php
Societe::query()
    ->where('type_activite', TypeDActivite::Cabinet->value)
    ->whereNotNull('validee_le')->where('statut', StatutSociete::Active->value)
    ->whereIn('id', Cabinet::withoutGlobalScope(SocieteScope::class)
        ->whereNotNull('activite_controlee_le')->whereNotNull('ordre_verifie_le')->whereNull('ordre_retire_le')
        ->select('societe_id'))
    ->when(preg_match('/^\d{9}$/', $terme), fn ($q) => $q->where('siren', $terme),
        fn ($q) => RechercheSansAccents::appliquer($q, $terme, ['raison_sociale']))
    ->orderBy('raison_sociale')->orderBy('id')->limit(20)
    ->get(['id', 'uuid', 'siren', 'raison_sociale', 'siege_code_postal', 'siege_ville']);
```

Un terme de moins de 3 caractères, qui n'est pas un SIREN, rend une collection vide.

**L5 — L'invitation désignée par son lien** (`InvitationCabinet::parSonJeton()`), avant qu'aucune société ne soit ouverte :
- `InvitationCabinet::withoutGlobalScopes()->where('jeton_hache', hash('sha256', $jeton))->first(['id', 'societe_id', 'siren', 'email', 'etat', 'expire_le', 'created_at'])` ;
- puis le cabinet : `Societe::query()->whereKey($invitation->societe_id)->first(['id', 'raison_sociale', 'siege_ville'])`.

**L6 — Le SIREN pris** (à l'invitation, et dans la commande d'expiration) : la règle de V2 (`Societe::sirenPris()`), qui ne sert qu'à décider (booléen), et, à l'invitation, l'identifiant et le type d'activité de la société qui le tient, pour lui adresser la demande de rattachement ou refuser un cabinet par un message neutre, jamais affichés. V2 n'expose qu'un booléen : EC ajoute `Societe::societeQuiTientLeSiren(string $siren): ?array`, même condition que l'index de V2 (une inscription close ne tient plus son SIREN), `first(['id', 'type_activite'])`. C'est une lecture du type d'une autre société, que la puce « Type d'activité » réécrite (1.2) permet.

**L7 — Les comptes du cabinet qui ont accès à un client** (JET 130, droits implicites, notifications) : `AccesDesCabinets::comptesQuiOntAcces($lien)`.

```php
User::query()->where('societe_id', $lien->cabinet_id)
    ->where('statut', '<>', StatutCompte::Retire->value)
    ->where(fn ($c) => $c->where('role_societe', 'proprietaire')
        ->orWhereIn('id', DB::table('clients_confies')->where('societe_id', $lien->cabinet_id)->where('lien_id', $lien->id)->select('user_id')))
    ->orderBy('id')->get();
```

**L8 — Les autres invitations ouvertes du même SIREN.** Elles sont lues par le job `RendreCaduquesLesInvitationsDuSiren`, après le commit de l'acceptation et hors de sa transaction, pour les rendre caduques chacune dans le contexte de son cabinet : `InvitationCabinet::withoutGlobalScopes()->where('siren', $siren)->where('etat', 'ouverte')->whereKeyNot($id)->orderBy('id')->get(['id', 'societe_id'])`. Rien d'autre de ces invitations n'est lu (ni l'adresse ni le jeton).

**L9 — Les magasins de fin de session d'un compte de cabinet** (`JournaliserFinDeSession`) : `Magasin::query()->whereKey(MagasinsOuvertsParLaSession::de($session))->orderBy('id')->get()`, filtrés par `droitsDans() !== null`.

**L10 — Les commandes** (3.6) : chacune itère société par société, avec un filtre explicite.

**L11 — Les invitations et les demandes du cabinet** (`InvitationsEtDemandes`, propriétaire du cabinet seul, Q-EC1) :
- invitations : `InvitationCabinet::query()` (`SocieteScope` du cabinet) ;
- demandes envoyées : `LienCabinet::duCabinet($cabinet)->whereIn('etat', [...])->where('origine', 'demande_du_cabinet')`, et du client le seul `Societe::query()->whereKey($lien->societe_id)->value('siren')`, le SIREN que le cabinet a saisi ;
- demandes reçues : `LienCabinet::duCabinet($cabinet)->where('etat', 'demande_par_le_client')`, et du client `Societe::query()->whereKey(...)->get(['id', 'siren', 'raison_sociale', 'siege_ville'])`, car le client s'est fait connaître en désignant ce cabinet.

**L12 — Les écritures d'une action du cabinet chez le client, et l'inverse** :
- la création d'une demande du cabinet (3.5.4) se fait en `runAsSociete($client)`, après le verrou de la société cliente : `BelongsToSociete` y écrit le client dans `societe_id` ;
- l'effacement des clients confiés d'un lien depuis le contexte du client (fin, remplacement, 3.5.8, 3.5.10, 3.5.11) : `DB::table('clients_confies')->where('societe_id', $lien->cabinet_id)->where('lien_id', $lien->id)->delete()`.

**L13 — La vérification d'un cabinet** (`Societe::cabinet()`, sans `SocieteScope`, filtrée par `societe_id`) : lue hors contexte (`canAccessTenant()`, acceptation d'une invitation) et dans le contexte d'un client (`modifierLeLien()`, `DesignerUnCabinetAction`, magasin du client). Colonnes : `societe_id`, `activite_controlee_le`, `ordre_verifie_le`, `ordre_retire_le`, et `numero_ordre` pour la page du client (L3).

**L14 — Le lien avant de verrouiller les registres d'un client** (`RegistreFiscal::sousVerrouDesMagasinsDesClients()`) : `LienCabinet::withoutGlobalScope(SocieteScope::class)->where('cabinet_id', $cabinet->id)->whereIn('societe_id', $magasins->pluck('societe_id')->unique())->distinct()->pluck('societe_id')` ; un magasin dont la société n'y figure pas lève une `LogicException`.

**L15 — La demande de rattachement désignée par son lien** (`LienCabinet::parSonJeton()`, `RattachementAuCabinetController`), avant qu'aucune société ne soit ouverte :
- `LienCabinet::withoutGlobalScopes()->where('jeton_hache', hash('sha256', $jeton))->where('etat', EtatDuLien::DemandeParLeCabinet->value)->first(['id', 'uuid', 'societe_id', 'cabinet_id', 'etat', 'expire_le'])` ;
- puis le cabinet : `Societe::query()->whereKey($lien->cabinet_id)->first(['id', 'raison_sociale', 'siege_ville'])` ;
- de la société visée, seulement son identifiant, comparé à la société du compte connecté, et, pour la redirection de son propriétaire, `Societe::query()->whereKey($lien->societe_id)->first(['id', 'uuid', 'close_le', 'statut'])` : rien ne s'affiche de cette société avant que son propriétaire soit connecté.

**Isolation, rappels au relecteur :**
- **Un cabinet ne voit que ses clients liés.** C'est L2, et toute vue du panneau `cabinet` passe par L2.
- **Un collaborateur ne voit que ses clients confiés.** C'est L2 pour un collaborateur. Il ne voit ni les invitations ni les demandes (L11).
- **Un cabinet qui n'est plus vérifié ne voit aucun client.** `liensOuverts()` ne rend rien ; son propriétaire ne garde que L2 ter, pour mettre fin à ses liens.
- **Avant le lien actif, le cabinet ne connaît d'une société que le SIREN qu'il a saisi** (L11, 3.1, 3.5.13).
- **La personne qui reçoit le lien d'une demande de rattachement n'apprend que l'existence de l'inscription.** Ni l'e-mail ni la page d'avant la connexion ne nomment la société (L15) ; seul son propriétaire connecté ouvre la page de rattachement, dans sa propre société (4.2 bis).
- **Le type d'une autre société ne se lit que dans les lectures listées** : celui de chaque client lié, dans l'espace du cabinet (L2 bis, et les autorisations croisées avec le type du client dans un magasin du client, qui est la société du magasin courant) ; celui de la société qui tient un SIREN invité, jamais montré (L6).
- **Un client ne voit jamais un autre client.** Aucune lecture du panneau `cabinet` ne mélange deux clients dans une même ligne. Le panneau `app` n'ouvre qu'un magasin, `MagasinScope` reste en place, et le sélecteur ne liste que le client ouvert.
- **Le cabinet ne lit jamais le journal d'audit d'un client, ni ses traces.** Pas d'écran d'audit dans `app`. L'export JET ne rend les traces qu'au propriétaire du magasin (`estProprietaireDuMagasin`, faux pour un compte de cabinet).
- **`IsolationDesSocietesTest::MODELES_DE_SOCIETE`** (l.77-101) reçoit `Cabinet`, `InvitationCabinet`, `LienCabinet` et `ClientConfie`. `test_chaque_modele_de_societe_est_liste_ici` l'exige.

---

## 6. Tests

Règles :
- Faker semé par test.
- Le temps figé (`travelTo`) pour toute expiration. La suite passe sous faketime.
- Les tests de concurrence sont marqués `exigerUnServeurDeBaseDeDonnees()` (PostgreSQL) et utilisent `demarrerUnProcessusConcurrent()` et `attendreQueLeProcessusAttendeUnVerrou()`.
- Les tests HTTP et Livewire passent par les aides `dansLeMagasin()`, `dansLEspaceDeLaSociete()`, et une nouvelle aide `dansLEspaceDuCabinet()`.
- Les fichiers nouveaux vont dans `tests/Feature/Cabinets/`, sauf mention contraire.

### 6.0 Aides de `TestCase` (`tests/TestCase.php`)

Nouvelles aides :
- `ouvrirUnCabinet(?string $siren = null, string $nomDuProprietaire = 'Claire Martin'): Societe`. Le cabinet est inscrit par le parcours de V2 et du lot TA (`inscrireUneSociete()` reçoit le type depuis TA), avec `TypeDActivite::Cabinet` et `FauxRepertoireSirene::SIREN_CABINET`, ou un SIREN réglé en `exercer(…, '69.20Z')`. Il est validé par le code de V2 et vérifié à l'Ordre par `VerifierLOrdreAction` avec le pilote factice. Aucun contexte actif ensuite.
- `proprietaireDuCabinet(Societe $cabinet): User`.
- `collaborateur(Societe $cabinet, array $clients = []): User` : `CreerCollaborateurAction`, puis `choisirSonMotDePasse()`, puis `ConfierDesClientsAction` pour ces sociétés clientes.
- `lierLeCabinet(Societe $cabinet, Magasin|Societe $client, ?array $autorisations = null): LienCabinet`. Le propriétaire du client désigne le cabinet et le propriétaire du cabinet accepte (actions réelles). Ensuite, `ReglerLesAutorisationsDuCabinetAction` si `$autorisations` est donné. Le client est une société « Commerce ou atelier » de `SocieteFactory` par défaut (lot TA), ou de services par son état `services()`.
- `inviterUneEntreprise(Societe $cabinet, string $siren, string $email): string` : rend le jeton, lu dans le dernier `InvitationDuCabinetMail` (`Mail::fake`).
- `accepterLInvitation(string $jeton, TypeDActivite $type = TypeDActivite::Commerce, string $motDePasse = …): User` : `AccepterLInvitationAction` avec le type et la case du choix définitif.
- `demanderLeRattachement(Societe $cabinet, Societe $client, string $email): string` : `InviterUneEntrepriseAction` pour le SIREN du client ; rend le jeton, lu dans le dernier `DemandeDeRattachementMail`.
- `dansLEspaceDuCabinet(Societe $cabinet, User $compte): void`.

Changements :
- `expertComptable()` est supprimée au commit 4. Ses 63 appels sont réécrits (6.19).
- Le docblock de `casesDeLAncienComptable()` est mis à jour.

### 6.1 Commit 1

**`CabinetInscriptionTest`** (les refus hors 69.20Z, l'absence de magasin et de démonstration et l'immuabilité du type sont testés par le lot TA, dans `TypeDActiviteTest` ; EC ne les duplique pas)
- `test_un_cabinet_inscrit_a_sa_ligne_cabinets_avec_le_code_lu_et_son_activite_controlee` : ligne `cabinets` créée dans la transaction de la société, avec `code_activite`, `nomenclature_activite` et `activite_controlee_le`, audit `ActiviteDuCabinetControlee`, `SocieteInscrite.origine = inscription`.
- `test_une_nomenclature_inconnue_laisse_l_activite_a_controler_par_l_editeur` : ligne `cabinets` sans `activite_controlee_le`, `editeur:cabinet:verifier-ordre --activite-controlee` la pose.
- `test_une_societe_qui_n_est_pas_un_cabinet_n_a_jamais_de_ligne_cabinets` : à l'inscription d'un commerce ou d'une société de services, aucune ligne ; la garde `cabinets_de_type_cabinet` refuse une insertion directe.
- `test_le_lien_d_une_societe_vers_l_inscription_preselectionne_le_cabinet` : `?type=cabinet` présélectionne l'entrée, la page du lien montre encore le menu et la case du choix définitif.
- `test_le_proprietaire_d_un_cabinet_arrive_dans_l_espace_du_cabinet_et_jamais_dans_l_espace_societe` : après la confirmation de l'inscription et à la connexion, `canAccessPanel`, `/societe/{uuid}` → 403, `/app` → redirection vers `/cabinet/{uuid}` ; sa liste d'étapes est celle du cabinet (4.1).
- `test_la_reverification_mensuelle_relit_l_activite_d_un_cabinet_et_alerte_sans_rien_bloquer` : code devenu autre que 69.20Z, alerte au propriétaire et à l'éditeur, aucun accès coupé.
- `test_la_liste_d_etapes_du_cabinet_ne_parle_ni_de_magasins_ni_de_demonstration` : réécrit depuis le test TA de la liste d'étapes réduite (6.19) ; `TableauDeBordDuCabinet` montre le code du courrier, l'activité, l'Ordre, les collaborateurs et les clients, et ni « magasin » ni « démonstration ».
- `test_la_verification_du_cabinet_rappelle_son_type_et_ne_parle_ni_de_magasins_ni_de_demonstration` : réécrit depuis les cas du cabinet des tests TA du rappel du type et des textes de la vérification (6.19) ; `VerificationDuCabinet` en attente du code (rappel par la vue partielle de TA) et après la clôture, et le bandeau dans le panneau `cabinet`.
- `test_l_identite_du_cabinet_montre_son_type_sans_bloc_d_assurance` : réécrit depuis la partie de la page de `AssuranceProfessionnelleTest::test_un_cabinet_n_a_pas_de_bloc_d_assurance` (6.19) ; `IdentiteDuCabinet` montre le type en lecture seule et pas le bloc d'assurance.

**`VerificationOrdreTest`**
- `test_avec_l_annuaire_automatique_l_ordre_se_verifie_au_meme_siren` : pilote factice automatique.
- `test_une_inscription_absente_ou_radiee_de_l_annuaire_est_refusee_sans_rien_verifier`.
- `test_sans_annuaire_automatique_la_demande_alerte_l_editeur_et_attend_sa_procedure` : pilote `editeur`, `AlerteEditeur`, `ordre_demande_le`.
- `test_seul_le_proprietaire_du_cabinet_demande_la_verification_de_l_ordre` : collaborateur refusé (écran, action forgée, action).
- `test_tant_que_le_cabinet_n_est_pas_verifie_il_n_invite_ni_ne_lie_aucun_client` : trois cas (courrier non saisi, activité non contrôlée, Ordre non vérifié), refus d'`InviterUneEntrepriseAction`, d'`AccepterLaDemandeDeLienAction` et de la recherche.
- `test_les_essais_automatiques_sont_limites_a_cinq_par_heure`.

**`tests/Feature/Editeur/ProceduresTest.php`** (ajouts)
- `test_editeur_cabinet_verifier_ordre_exige_justification_piece_siren_et_operateur_et_s_inscrit_a_l_audit`.
- `test_editeur_cabinet_verifier_ordre_refuse_une_societe_qui_n_est_pas_un_cabinet`.
- `test_editeur_cabinet_refuser_ordre_previent_le_proprietaire_avec_le_motif`.
- `test_editeur_cabinet_retirer_ordre_met_fin_a_tous_les_liens_du_cabinet` (Q-EC5) : clients et cabinet prévenus ; écrit au commit 3, avec la procédure.

**`tests/Feature/Integrations/AnnuaireDeLOrdreTest.php`**
- `test_le_faux_annuaire_connait_le_cabinet_de_test_et_se_regle`.
- `test_l_annuaire_tenu_par_l_editeur_n_est_pas_automatique_et_refuse_toute_lecture`.
- `test_le_pilote_faux_de_l_ordre_est_refuse_en_production`.

**`tests/Feature/Tenancy/AccesAuxPanneauxTest.php`**
- La l.39 est réécrite en `test_il_n_existe_que_les_panneaux_app_societe_et_cabinet`.

**`PanneauCabinetTest`** (première partie)
- `test_chaque_page_et_ressource_du_panneau_cabinet_declare_son_acces_et_a_une_policy` : inventaire, comme `PanneauSocieteTest:84`.
- `test_un_compte_d_une_societe_qui_n_est_pas_un_cabinet_n_ouvre_jamais_le_panneau_cabinet` : propriétaire, administrateur, membre, d'un commerce et d'une société de services → 403.

### 6.2 Commit 2 : `CollaborateursTest`

- `test_seul_le_proprietaire_du_cabinet_cree_suspend_reactive_retire_et_reintegre_un_collaborateur` : reprend l'intention d'`ExpertComptableTest::54`. Un autre collaborateur est refusé par l'écran, la requête forgée et l'action ; le propriétaire fait chaque geste.
- `test_un_collaborateur_recoit_un_identifiant_de_huit_chiffres_pour_premier_mot_de_passe_sans_adresse`.
- `test_la_fenetre_du_compte_cree_propose_d_office_au_collaborateur_de_choisir_son_mot_de_passe` : reprend l'intention d'`AdministrateursTest::94`.
- `test_un_collaborateur_ne_peut_pas_se_connecter_avec_son_identifiant_faute_de_poste_reconnu`.
- `test_un_collaborateur_dont_le_mot_de_passe_est_l_identifiant_est_refuse_sur_le_poste_reconnu_d_un_client` : poste reconnu d'un magasin d'un client confié, `PostesReconnus::ouvreLeCompte()` faux, même réponse qu'un mot de passe faux.
- `test_seul_le_proprietaire_du_cabinet_redonne_l_acces_a_un_collaborateur_et_seulement_en_sa_presence` : reprend l'intention de `RenouvellementDAccesTest::326` ; sessions fermées, audit, avis ; le mode « absent » est refusé par l'action et absent de l'écran.
- `test_la_base_refuse_un_collaborateur_hors_d_un_cabinet_et_un_administrateur_ou_un_membre_dans_un_cabinet` : jeu de données (commerce, services).
- `test_la_base_refuse_une_affectation_ou_une_autorisation_de_societe_a_un_collaborateur` : reprend l'intention de `GardesDesDroitsEnBaseTest::66`.
- `test_un_collaborateur_n_ouvre_jamais_l_espace_societe_et_ne_change_jamais_de_role` : `peutChangerLeRoleDeSociete` faux, aucun administrateur ni membre dans un cabinet.
- `test_la_creation_repetee_d_un_collaborateur_ne_cree_jamais_un_second_compte` : jeton d'écran.
- `test_retirer_un_collaborateur_garde_son_role_sans_aucun_acces_et_le_reintegre_sans_client_confie` : reprend l'intention d'`ExpertComptableTest::262`.
- `test_le_proprietaire_du_cabinet_est_prevenu_de_la_premiere_connexion_d_un_collaborateur`.

### 6.3 `InvitationsTest`

- `test_le_proprietaire_du_cabinet_invite_un_siren_libre_et_actif_en_redonnant_son_mot_de_passe` : mail envoyé à l'adresse saisie, jeton haché, `expire_le` dans 7 jours, audit `InvitationEnvoyee` sans adresse.
- `test_l_e_mail_d_invitation_nomme_le_cabinet_et_le_siren_invite` : dénomination et ville du cabinet, SIREN, choix du mot de passe et du type, validation sans courrier ni QR code, possibilité de refuser.
- `test_le_cabinet_n_invite_que_par_un_siren` : un SIREN invalide ou un nom est refusé par la validation, et l'écran n'a aucune recherche par nom.
- `test_un_mot_de_passe_faux_n_envoie_aucune_invitation`.
- `test_une_entreprise_cessee_ou_inconnue_n_est_pas_invitee`.
- `test_un_siren_pris_envoie_a_l_adresse_saisie_une_demande_de_rattachement_qui_nomme_le_cabinet` : `DemandeDeRattachementMail` à l'adresse saisie, qui nomme le cabinet et le SIREN et jamais la dénomination de la société, aucun `InvitationDuCabinetMail`, aucune invitation créée, demande `demande_par_le_cabinet` avec son jeton haché, `ResultatDInvitation::DemandeEnvoyee` ; l'adresse saisie n'est ni dans `liens_cabinet` ni au journal d'audit.
- `test_le_proprietaire_de_la_societe_inscrite_voit_la_demande_dans_son_espace_sans_second_e_mail` : avis en base avec le lien vers la page de rattachement, aucun e-mail à son adresse de connexion quand elle diffère de l'adresse saisie, ligne au tableau de bord et à « Cabinet comptable ».
- `test_un_siren_tenu_par_un_cabinet_est_refuse_par_un_message_neutre` : le message ne dit pas que la société est un cabinet, rien n'est créé.
- `test_avant_le_lien_actif_le_cabinet_ne_voit_que_le_siren_qu_il_a_saisi` : la liste des demandes envoyées, les avis de refus et d'expiration et le journal d'audit du cabinet ne contiennent ni la dénomination ni la ville de la société (`assertDontSee`).
- `test_une_demande_compte_dans_les_cinquante_par_jour_et_ne_se_renouvelle_pas_avant_sept_jours` : demande, retrait, nouvelle demande refusée ; `DebitDesEmails` compté ; temps figé pour les 7 jours.
- `test_une_invitation_repetee_a_la_meme_adresse_ne_cree_ni_ne_renvoie_rien`, et une demande de rattachement répétée, à la même adresse ou à une autre, non plus.
- `test_une_invitation_refusee_ne_se_renouvelle_pas_avant_sept_jours` : temps figé ; refus de la nouvelle invitation avant 7 jours, acceptée ensuite.
- `test_une_seconde_invitation_du_meme_siren_a_une_autre_adresse_est_refusee_tant_que_la_premiere_est_ouverte`.
- `test_le_cabinet_retire_une_invitation_ouverte_et_son_lien_ne_vaut_plus`.
- `test_une_invitation_expire_apres_sept_jours` : temps figé, commande d'expiration, refus à l'acceptation.
- `test_un_cabinet_n_envoie_pas_plus_de_cinquante_invitations_par_jour`.
- `test_l_invitation_ne_part_pas_si_le_cabinet_n_est_plus_verifie_au_moment_de_l_envoi`, ni la demande de rattachement retirée ou devenue sans objet avant l'envoi.
- `test_un_collaborateur_n_invite_pas` (Q-EC1).
- `test_deux_cabinets_peuvent_inviter_le_meme_siren_sans_rien_apprendre_l_un_de_l_autre`.

### 6.4 `AccepterLInvitationTest`

- `test_accepter_l_invitation_cree_la_societe_validee_sans_courrier_avec_la_preuve_de_l_invitation_du_cabinet` (jeu de données : « Commerce ou atelier », « Services, sans vente d'articles ») :
  - société du type choisi, propriétaire avec l'adresse de l'invitation ;
  - audit `SocieteInscrite` avec `type_activite`, le code d'activité lu à l'acceptation et `origine = invitation` ;
  - aucun envoi au recommandé factice, aucune lettre ;
  - audit `SocieteValidee` avec `preuve = invitation_cabinet` et `cabinet` ;
  - lien actif avec les autorisations par défaut du type (aucune autorisation de la famille `articles` pour une société de services) ;
  - invitation `acceptee`.
- `test_l_identite_de_la_societe_est_lue_au_registre_national_a_l_acceptation` : dénomination, forme, capital, immatriculation, siège, représentant légal, TVA depuis le SIREN (`FauxRne`) ; le nom du propriétaire est `nomPourLeCourrier()` du premier dirigeant qui a le pouvoir de représenter l'entreprise, le même que V2 donne au propriétaire inscrit (jeu de données : personne physique, personne morale dirigeante).
- `test_un_registre_national_injoignable_refuse_l_acceptation_sans_rien_creer_et_l_invitation_reste_ouverte`.
- `test_un_siren_inconnu_du_registre_ou_sans_representant_legal_s_accepte_avec_l_identite_du_repertoire_sirene` (jeu de données : SIREN inconnu du registre factice, SIREN connu sans dirigeant qui a le pouvoir de représenter) : société créée et validée, lien actif ; identité du répertoire Sirene, ou du registre quand il la donne ; `representant_legal` nulle ; nom du compte = dénomination lue au répertoire.
- `test_le_nouveau_proprietaire_est_connecte_et_mene_a_l_espace_de_sa_societe`.
- `test_le_lien_d_une_invitation_ne_sert_qu_une_fois`.
- `test_une_invitation_expiree_ou_retiree_ne_s_accepte_pas` : même message neutre.
- `test_une_adresse_deja_adresse_de_connexion_d_un_proprietaire_ne_s_accepte_pas_et_rien_n_est_cree`.
- `test_le_formulaire_d_acceptation_demande_le_mot_de_passe_et_le_type_d_activite_sans_cabinet_avec_la_case_du_choix_definitif` : le menu n'a que « Commerce ou atelier » et « Services, sans vente d'articles », sans valeur par défaut, avec leurs aides ; rien d'autre n'est demandé.
- `test_l_acceptation_sans_type_avec_le_type_cabinet_ou_sans_la_case_ne_cree_rien` : l'invitation reste ouverte.
- `test_la_page_d_acceptation_montre_les_avertissements_du_code_lu_au_repertoire` : jeu de données (services et 47.42Z, commerce et 10.71C, services et 69.20Z) ; jamais bloquants ; pour 69.20Z, le texte propre à cette page (« … refusez cette invitation et inscrivez votre cabinet vous-même. »), et jamais « choisissez « Cabinet d'expertise comptable » ».
- `test_le_repertoire_injoignable_a_l_ouverture_du_lien_ne_montre_pas_le_formulaire`.
- `test_la_personne_invitee_refuse_l_invitation_et_le_cabinet_est_prevenu` : invitation `refusee` avec `close_le`, audit `InvitationRefusee` dans le journal du cabinet, avis à son propriétaire, aucune société créée ; un second refus et une acceptation ensuite rendent la page neutre.
- `test_une_entreprise_cessee_entre_l_invitation_et_l_acceptation_ne_s_accepte_pas` : le répertoire est contrôlé de nouveau à l'acceptation (Q-EC1), rien n'est créé.
- `test_un_cabinet_qui_n_est_plus_verifie_ne_fait_plus_accepter_ses_invitations`.
- `test_un_siren_inscrit_entre_temps_rend_l_invitation_caduque_et_previent_le_cabinet`.
- `test_l_acceptation_rend_caduques_les_invitations_des_autres_cabinets_pour_ce_siren` : par le job envoyé après le commit, chaque invitation dans le journal de son cabinet ; la commande d'expiration rattrape une invitation dont le SIREN a été inscrit par V2.
- `test_l_acceptation_d_une_societe_sans_magasin_ne_verrouille_aucun_registre_et_active_le_lien`.
- `test_l_ouverture_du_lien_ne_change_rien` (GET).
- `test_le_proprietaire_valide_par_une_invitation_signe_les_attestations_a_la_main` : si V2 a codé le droit de signer.
- `test_la_societe_nee_d_une_invitation_ne_change_jamais_de_type` : la garde du lot TA refuse la modification, et aucun écran ne la propose.
- `test_la_page_d_acceptation_ne_montre_rien_d_un_autre_cabinet`.

### 6.5 `LiensAvecUneSocieteInscriteTest`

- `test_le_proprietaire_d_une_societe_trouve_un_cabinet_verifie_par_siren_ou_par_nom_sans_casse_ni_accents`.
- `test_la_recherche_ne_montre_ni_un_cabinet_non_verifie_ni_une_societe_qui_n_est_pas_un_cabinet_et_se_limite_a_vingt` : colonnes vérifiées par `DB::listen`.
- `test_designer_un_cabinet_envoie_la_demande_et_le_lien_nait_quand_le_cabinet_accepte`.
- `test_le_lien_de_la_demande_mene_apres_la_connexion_du_proprietaire_a_la_page_de_rattachement` : sans connexion, page qui ne nomme pas la société, puis connexion et redirection ; connecté comme propriétaire, redirection ; connecté comme administrateur de la société, comme compte d'une autre société ou d'un cabinet, page neutre sans identité ; jeton inconnu, demande retirée, refusée ou expirée, page neutre.
- `test_la_page_de_rattachement_accepte_avec_les_autorisations_par_defaut_ou_ajustees_ou_refuse` : trois choix ; avec des autorisations ajustées (consultations retirées, achats ajoutés), une seule modification, un JET 130 par compte et par magasin, `LienActive` avec `autorisations = ajustees` ; le jeton est effacé à chaque issue.
- `test_la_page_de_rattachement_ne_propose_les_achats_et_le_stock_qu_a_un_client_qui_a_des_articles` : une société de services n'a que le groupe « Consulter », et une autorisation d'articles forgée est refusée (action et garde).
- `test_la_page_de_rattachement_d_une_autre_societe_ou_d_une_demande_close_est_neutre` : uuid d'un lien d'une autre société, lien actif, demande du client.
- `test_designer_le_cabinet_qui_a_deja_demande_le_lien_l_accepte`.
- `test_une_demande_se_refuse_ou_se_retire_et_s_inscrit_aux_deux_journaux`.
- `test_une_demande_expire_apres_sept_jours` (Q-EC6), demande de rattachement comme désignation du client ; son jeton est effacé.
- `test_un_client_n_a_qu_un_cabinet_lie_et_en_lier_un_autre_met_fin_au_precedent` : JET 130 des comptes de l'ancien cabinet à null, audit dans les trois journaux, avis ; le journal de chaque cabinet ne porte que les droits de ses propres comptes et jamais l'identité de l'autre cabinet ; les avis de l'ancien cabinet sur ce client s'effacent de la cloche de ses collaborateurs.
- `test_le_proprietaire_du_cabinet_accepte_la_demande_d_un_client_controle_dans_son_cabinet` : l'acceptation, le refus et le retrait côté cabinet passent par `modifierLeLien()` dans le contexte du client, et un collaborateur forgeant le même geste est refusé.
- `test_un_rattachement_ne_valide_jamais_une_societe_qui_attend_son_code` : par la désignation d'un cabinet comme par l'acceptation sur la page de rattachement ; la société attend toujours son code, aucune action de validation n'est appelée, et la page le dit.
- `test_seul_le_proprietaire_du_client_designe_accepte_regle_et_met_fin_au_lien` : un administrateur autorisé à tout et un responsable sont refusés (écran, forgé, action).
- `test_seul_le_proprietaire_du_cabinet_accepte_ou_met_fin_au_lien_du_cote_du_cabinet` (Q-EC1).
- `test_une_societe_invite_son_cabinet_a_creer_son_espace_sans_garder_son_adresse` : le mail mène à l'inscription `?type=cabinet` ; l'audit ne contient pas l'adresse ; le débit est limité.
- `test_un_lien_prend_fin_quand_une_autre_personne_saisit_le_code_du_courrier` (Q-EC7) : lien et demandes, cabinet prévenu, le nouveau propriétaire désigne ensuite lui-même un cabinet.
- `test_un_lien_prend_fin_quand_l_inscription_est_close_sans_validation` (Q-EC7) : jeu de données (30 jours sans code, `editeur:societe:clore --motif=refus`, `editeur:societe:clore --motif=type_errone`) ; lien et demandes terminés dans la transaction de la clôture, JET 130 dans chaque magasin réel, cabinet prévenu ; `gererSonCabinet()` refuse ensuite la société close.

### 6.6 `AutorisationsDuCabinetTest`

- `test_par_defaut_le_cabinet_a_les_consultations_dans_chaque_magasin_reel_du_client` : reprend l'intention d'`ExpertComptableTest::98` ; `service.view` en fait partie.
- `test_le_cabinet_lit_un_magasin_reel_cree_apres_le_lien_et_jamais_la_demonstration`.
- `test_un_nouveau_magasin_du_client_inscrit_les_droits_implicites_du_cabinet` : reprend l'intention d'`ExpertComptableTest::244`.
- `test_le_proprietaire_du_client_retire_des_consultations_et_ajoute_des_autorisations_d_achat_et_de_stock`.
- `test_aucune_autorisation_de_vente_de_compte_de_reglage_ni_de_caisse_ne_s_accorde_au_cabinet` : par l'action (erreur de champ), par l'écran (cases absentes) et par la base (garde `autorisations_cabinet_permises`), pour chaque autorisation hors de `duCabinet(TypeDActivite::Commerce)`.
- `test_duCabinet_n_a_aucune_autorisation_de_vente_ni_de_gestion` : registre, et égalité de la liste SQL de la garde et de `duCabinet(TypeDActivite::Commerce)`.
- `test_un_client_de_services_ne_propose_ni_n_accorde_aucune_autorisation_d_articles` : défauts sans la famille `articles`, cases d'achats et de stock absentes de « Cabinet comptable » et de la page de rattachement, refus de l'action, refus de la base par la famille du référentiel.
- `test_le_collaborateur_a_exactement_les_autorisations_accordees_au_cabinet`.
- `test_service_create_ne_s_accorde_jamais_au_cabinet_et_produit_create_ne_cree_jamais_un_service` : `service.create` et `service.update` sont refusés par l'action et la garde ; avec `produit.create`, la liste blanche refuse `create` sur `Service`.
- `test_chaque_reglage_des_autorisations_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_deux_journaux`.
- `test_un_compte_de_cabinet_n_a_que_les_autorisations_du_lien_quelles_que_soient_les_lignes_en_base` : reprend l'intention de `ControleDesDroitsTest::455`. La garde est retirée (`retirerLeDeclencheur`), une autorisation de vente est insérée, et elle reste refusée.

### 6.7 `AccesDuCollaborateurChezLeClientTest`

- `test_un_collaborateur_ouvre_les_magasins_reels_des_clients_confies_comme_s_il_y_etait_connecte` : HTTP 200 sur la liste des factures, et la fiche.
- `test_un_collaborateur_ne_voit_pas_un_client_qui_ne_lui_est_pas_confie_et_le_proprietaire_du_cabinet_les_voit_tous`.
- `test_un_collaborateur_telecharge_les_pdf_et_exporte_le_journal_a_son_nom_avec_celui_du_cabinet` : reprend l'intention d'`ExpertComptableTest::161`. `operateur.nom` = « Nom (cabinet X) » dans le contenu signé.
- `test_deux_collaborateurs_du_meme_cabinet_signent_chacun_leurs_consultations` : reprend l'intention d'`ExpertComptableTest::205`.
- `test_un_collaborateur_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur_du_client` : reprend l'intention d'`ExpertComptableTest::140`.
- `test_un_collaborateur_n_ouvre_ni_l_espace_de_la_societe_du_client_ni_sa_demonstration_ni_sa_caisse` : reprend l'intention d'`ExpertComptableTest::116`.
- `test_un_collaborateur_n_envoie_aucun_e_mail_au_client_du_magasin` : reprend l'intention d'`ExpertComptableTest::184`.
- `test_avec_les_achats_accordes_un_collaborateur_cree_et_valide_une_facture_d_achat_a_son_nom_avec_celui_du_cabinet` : nom figé sur la facture d'achat, audit du client.
- `test_un_collaborateur_suit_la_lecture_seule_du_client` : un magasin fermé ou une société suspendue reste consultable, sans achat.
- `test_la_base_refuse_une_vente_ou_un_encaissement_au_nom_d_un_compte_de_cabinet` : `GardesDeLaVente`, par `DB::table`.
- `test_le_proprietaire_du_cabinet_n_a_aucun_droit_de_proprietaire_chez_un_client` : jeu de données (`TypeDActivite::Commerce`, `TypeDActivite::Services`), pour chaque autorisation de `duProprietaire($type)` hors de `duCabinet($type)`, refus dans un magasin d'un client de ce type ; aucun accès à l'espace de la société du client, ni à son équipe, ni à ses postes reconnus.
- `test_le_selecteur_d_un_collaborateur_ne_montre_que_les_magasins_reels_du_client_ouvert` : reprend l'intention de `SelecteurDeMagasinTest::64`.
- `test_un_compte_de_cabinet_qui_ouvre_app_va_a_l_espace_du_cabinet`.
- `test_un_cabinet_qui_n_est_plus_verifie_ou_plus_actif_n_ouvre_plus_aucun_client` (Q-EC5) : magasin, fiche du client (404) et tableau de bord (aucun client ni total) ; son propriétaire ne voit que la liste de L2 ter et y met fin à un lien.
- `test_peut_ouvrir_se_lit_sans_contexte_et_dans_le_contexte_d_un_client` : `peutOuvrir()` et `estUnCabinetVerifie()` sans contexte posé, et sous `runAsSociete($client)`, rendent la bonne réponse sans exception.
- `test_un_collaborateur_ouvre_un_magasin_du_client_par_son_url` : GET `/app/{uuid}` d'un magasin d'un client confié → 200, d'un client non confié → 404.
- `test_la_fin_de_session_d_un_collaborateur_s_inscrit_dans_les_magasins_du_client_qu_il_a_ouverts`.
- `test_la_cloche_d_un_collaborateur_ne_recoit_jamais_d_alerte_de_stock_du_client`.

### 6.8 `ClientsConfiesTest`

- `test_le_proprietaire_du_cabinet_confie_et_retire_un_client_et_l_acces_suit_aussitot`.
- `test_on_ne_confie_qu_un_client_lie_a_son_cabinet` : lien terminé ou d'un autre cabinet → refus ; la FK composite et la garde le refusent aussi.
- `test_confier_suspendre_reactiver_retirer_un_collaborateur_inscrit_le_jet_130_dans_chaque_magasin_reel_du_client` : reprend l'intention d'`ExpertComptableTest::222`, sur deux clients et leurs magasins.
- `test_un_collaborateur_ne_confie_rien`.

### 6.9 `TableauDeBordDuCabinetTest`

- `test_le_tableau_de_bord_reunit_les_clients_ouverts_au_compte_et_rien_d_autre`.
- `test_les_factures_a_encaisser_ne_se_montrent_que_pour_les_clients_qui_accordent_les_factures_de_toute_l_equipe` : « — » sinon.
- `test_les_totaux_se_calculent_en_centimes_sans_nom_de_client_ni_numero_de_document` : `assertDontSee` des noms et des numéros.
- `test_la_lecture_ne_rend_que_les_colonnes_permises` : `DB::listen`, liste exacte des colonnes de L1.
- `test_la_demonstration_d_un_client_n_entre_jamais_dans_les_totaux`.
- `test_une_requete_livewire_forgee_ne_montre_aucun_client_hors_des_liens_ouverts` : sur le modèle de `CaissesDeLaSocieteTest::160`.

### 6.10 `FinDuLienTest`

- `test_le_client_met_fin_au_lien_et_l_acces_du_cabinet_s_arrete_aussitot`.
- `test_le_cabinet_met_fin_au_lien`.
- `test_une_session_ouverte_perd_l_acces_au_client_des_sa_requete_suivante` : une session HTTP sur une page du client, puis la fin du lien, puis GET → 403 ou 404 ; une requête Livewire (`requeteLivewire`) sur un instantané pris avant → 403 ou 404.
- `test_la_fin_du_lien_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_journaux_des_deux_societes`.
- `test_la_fin_du_lien_efface_les_clients_confies_et_previent_l_autre_proprietaire_et_les_collaborateurs` : les lignes `clients_confies` du lien sont effacées depuis le contexte du client ; les anciens avis sur ce client disparaissent de la cloche des collaborateurs avant l'avis de fin.
- `test_un_cabinet_suspendu_met_fin_a_un_lien_mais_ne_modifie_plus_son_identite` : lecture seule de l'espace du cabinet (3.4).
- `test_mettre_fin_a_un_lien_deja_termine_ne_fait_rien`.
- `test_une_societe_suspendue_met_quand_meme_fin_au_lien_et_une_societe_revoquee_ne_gere_plus_son_cabinet`.

### 6.11 `IsolationDesCabinetsTest`

Scénario commun :
- cabinet C1, avec son propriétaire P1 et les collaborateurs A et B ;
- clients X (deux magasins réels et une démonstration) et Y, liés à C1 ; X confié à A, Y confié à B ;
- cabinet C2, avec P2 et le client Z.

Tests :
- `test_un_collaborateur_ne_voit_que_les_clients_qui_lui_sont_confies` : A voit X, jamais Y ; B voit Y, jamais X. Listes, fiches, URLs forgées (uuid de lien, uuid de magasin) → 404.
- `test_le_proprietaire_du_cabinet_voit_tous_ses_clients_et_jamais_ceux_d_un_autre_cabinet` : P1 voit X et Y, jamais Z ; P2 ne voit que Z.
- `test_aucune_donnee_d_un_client_n_est_visible_depuis_un_autre_client` : depuis un magasin de X, aucune facture, client, produit ni clôture de Y. Le panneau `app` n'ouvre que le magasin de l'URL.
- `test_un_client_ne_voit_que_son_cabinet_et_les_noms_de_ses_personnes_qui_ont_acces` : X voit P1 et A, jamais B ; jamais un identifiant ni une adresse.
- `test_les_lectures_hors_du_client_courant_filtrent_par_cabinet_et_ne_lisent_que_leurs_colonnes` : L2, L3, L4 sous `DB::listen`.
- `test_chaque_modele_d_un_cabinet_ou_d_un_lien_est_isole_par_sa_societe` : `LienCabinet::query()` dans le contexte de X ne rend que les liens de X ; `InvitationCabinet` et `ClientConfie` dans le contexte de C1 ne rendent que ceux de C1.
- `test_une_action_forgee_du_cabinet_sur_un_client_non_lie_est_refusee` : `ConfierDesClientsAction` avec le lien de Z, `ReglerLesAutorisationsDuCabinetAction` par P1 sur le lien de X, `MettreFinAuLienAction` par P2 sur le lien de X.
- `test_un_collaborateur_ne_voit_ni_les_invitations_ni_les_demandes_du_cabinet` (Q-EC1) : A et B reçoivent 403 sur `InvitationsEtDemandes`, et `InvitationCabinetPolicy::viewAny` et `view` les refusent.

Ajout à **`tests/Feature/Tenancy/IsolationDesSocietesTest.php`** : `MODELES_DE_SOCIETE` complété (5.).

### 6.12 `PanneauCabinetTest` (seconde partie) et inventaire du panneau `app`

- `test_un_collaborateur_voit_les_pages_du_cabinet_sans_les_actions_du_proprietaire` : sauf `InvitationsEtDemandes`, qu'il n'ouvre pas (403).
- `test_chaque_ressource_et_page_du_panneau_app_a_une_reponse_attendue_pour_un_compte_de_cabinet` : un tableau figé (ressource ou page → consultable, modifiable avec achats, ou jamais). Ajouter une ressource sans compléter le tableau fait échouer le test. C'est lui qui fige `GESTES_D_UN_CABINET`.

### 6.13 `GardesDesCabinetsEnBaseTest` (`tests/Feature/Authorization/`)

Un test par refus de 2.3. La garde du type (valeur inconnue, toute modification) et celle des magasins d'un cabinet sont testées par le lot TA (`TypeDActiviteTest`) : EC ne les duplique pas.
- `test_la_base_refuse_une_ligne_de_cabinet_pour_une_societe_qui_n_est_pas_un_cabinet` (jeu de données : commerce, services) ;
- `test_la_base_refuse_un_lien_dont_le_cabinet_n_est_pas_un_cabinet_ou_dont_le_client_est_un_cabinet_ou_vers_soi` ;
- `test_la_base_refuse_un_jeton_de_rattachement_sur_un_lien_qui_n_est_pas_une_demande_du_cabinet_en_cours` (contrainte `CHECK` de `liens_cabinet`) ;
- `test_la_base_refuse_une_transition_de_lien_non_permise_et_la_suppression_d_un_lien` ;
- `test_la_base_refuse_un_second_lien_actif_pour_un_client` ;
- `test_la_base_refuse_une_autorisation_hors_du_cabinet_ou_sur_un_lien_inactif` ;
- `test_la_base_refuse_une_autorisation_d_articles_pour_un_client_sans_articles` (client « Services, sans vente d'articles », chaque clé d'articles de `duCabinet(TypeDActivite::Commerce)`) ;
- `test_la_base_refuse_de_confier_un_client_a_un_compte_qui_n_est_pas_collaborateur_ou_un_lien_inactif` ;
- `test_les_transitions_permises_passent`.

Tous passent par `DB::table` ou une requête brute, sur les deux moteurs.

### 6.14 `tests/Feature/Integrations/InpiRneTest.php` (ajouts)

- `test_l_identite_d_une_societe_se_lit_dans_la_formalite` (`Http::fake` d'une formalité conforme au dictionnaire de données) ;
- `test_un_champ_d_identite_absent_reste_vide_sans_rendre_la_reponse_illisible` ;
- `test_un_siren_inconnu_du_registre_n_a_pas_d_identite`.

### 6.15 `CabinetsConcurrenceTest` (PostgreSQL)

- `test_deux_acceptations_simultanees_pour_le_meme_siren_ne_creent_qu_une_societe` : deux cabinets ont invité le même SIREN, et les deux invitations sont acceptées en même temps (processus concurrent). Les deux se terminent sans interblocage. On obtient exactement une société de ce SIREN, un propriétaire, un identifiant attribué et un lien actif. L'invitation perdante est `caduque`, sa personne lit le message de 3.5.5, et aucun compte ni identifiant orphelin ne reste.
- `test_la_meme_invitation_acceptee_deux_fois_en_meme_temps_ne_cree_qu_une_societe` : le verrou de l'invitation ; le second reçoit le message neutre.
- `test_une_fin_de_lien_pendant_un_export_du_journal_passe_tout_entiere_avant_ou_apres` : la fin de lien pendant une consultation.
  - Cas 1 : l'export tient le verrou du registre (`concurrencerDesQueLeRegistreEstVerrouille`), et la fin de lien concurrente attend. Le JET 110 précède le JET 130 de fin.
  - Cas 2 : la fin de lien tient les registres, et l'export concurrent attend puis est refusé (`AuthorizationException`). Aucun JET 110 n'est inscrit.
- `test_une_fin_de_lien_pendant_la_validation_d_un_achat_par_le_cabinet_passe_avant_ou_apres` : achats accordés, même schéma avec `ValiderFactureAchatAction`.
- `test_deux_invitations_simultanees_du_meme_siren_par_le_meme_cabinet_n_en_creent_qu_une` : une ligne, un e-mail.
- `test_deux_acceptations_simultanees_de_demandes_de_deux_cabinets_ne_laissent_qu_un_lien_actif` : verrou de la société cliente et index unique ; l'autre demande reste en attente, ou le lien accepté en second remplace le premier, de façon cohérente (un seul actif, l'autre `termine` avec `remplace`, JET 130 et audits complets).
- `test_confier_un_client_pendant_la_fin_du_lien_ne_laisse_aucun_acces` : aucune ligne `clients_confies` sur un lien terminé, et aucun interblocage pour un client sans magasin réel (le lien est verrouillé avant les comptes des deux côtés).
- `test_suspendre_un_collaborateur_pendant_qu_il_valide_un_achat_chez_un_client` : sérialisé sur la ligne du compte ; la validation est refusée si la suspension passe avant.
- `test_une_invitation_d_un_siren_pris_et_une_designation_simultanees_ne_creent_qu_une_demande` : le cabinet invite le SIREN pendant que le client le désigne ; sérialisé sur la société cliente, aucune erreur de contrainte, une seule demande, l'autre partie lit le message prévu.
- `test_une_acceptation_et_une_nouvelle_invitation_du_meme_cabinet_a_l_expiration_ne_s_interbloquent_pas` : temps figé à l'instant d'expiration ; les deux prennent la société du cabinet puis l'invitation.
- `test_deux_acceptations_simultanees_a_la_meme_adresse_pour_deux_siren_ne_creent_qu_une_societe` : la seconde lit le message de l'adresse déjà prise, rien d'autre n'est créé.
- `test_le_retrait_de_l_ordre_pendant_une_acceptation_de_demande_ne_s_interbloque_pas_et_n_oublie_aucun_lien` (Q-EC5) : le lien accepté pendant le premier temps prend fin au second.
- `test_une_acceptation_et_un_refus_simultanes_de_la_meme_invitation_ne_laissent_qu_une_issue` : le verrou de l'invitation ; soit la société naît et l'invitation est `acceptee`, soit rien n'est créé et elle est `refusee`, et l'autre geste lit le message neutre.
- `test_l_acceptation_d_un_rattachement_et_le_retrait_de_la_demande_simultanes_ne_laissent_qu_une_issue` : sérialisés sur la société cliente puis le lien ; un seul état final, jeton effacé, audits complets.
- `test_la_cloture_d_une_inscription_pendant_l_acceptation_d_un_rattachement_ne_s_interbloque_pas` : les deux prennent les registres des magasins réels, puis la société ; si l'acceptation passe avant, la clôture met fin au lien (motif `inscription_close`) ; sinon, la clôture a déjà retiré la demande, et l'acceptation lit « Cette demande n'est plus valable. ».

### 6.16 `CabinetComptableTest` (`tests/Feature/Societe/`, page de la société)

- `test_seul_le_proprietaire_ouvre_la_page_cabinet_comptable_et_la_page_de_rattachement` : administrateur autorisé à tout, membre, compte de cabinet → 403 ; propriétaire d'une société close → 403.
- `test_la_page_designe_repond_regle_et_met_fin_au_lien` : Livewire ; le bouton « Répondre » d'une demande reçue mène à sa page de rattachement.
- `test_la_page_ne_propose_que_les_autorisations_du_cabinet_en_deux_groupes` (société « Commerce ou atelier »).
- `test_une_requete_forgee_ne_designe_pas_une_societe_qui_n_est_pas_un_cabinet_ou_un_cabinet_non_verifie`.
- `test_la_page_cabinet_comptable_d_une_societe_de_services_n_a_que_le_groupe_consulter`.

### 6.17 `CommandesDesCabinetsTest`

- `test_l_expiration_ferme_les_invitations_et_demandes_perimees_societe_par_societe` (temps figé) : chaque invitation close reçoit `close_le`, et la base refuse une invitation close sans `close_le` ;
- `test_la_purge_efface_les_invitations_closes_depuis_quarante_huit_heures_quel_que_soit_leur_etat` : acceptée, retirée, expirée, caduque ; une invitation ouverte ou close depuis moins de 48 heures reste ; une invitation refusée reste 7 jours, puis s'efface ; le lien né d'une invitation et le journal d'audit restent.
- `test_l_expiration_d_une_demande_de_rattachement_efface_son_jeton` : le lien de l'e-mail rend ensuite la page neutre.

### 6.18 `NomPourLHistoriqueTest` (`tests/Feature/Tenancy/`)

- `test_le_nom_fige_d_un_compte_de_cabinet_porte_le_nom_du_cabinet_au_moment_de_l_operation` : renommer ensuite le cabinet ou la personne ne change rien.
- `test_le_journal_d_audit_du_client_nomme_le_collaborateur_avec_son_cabinet`.
- `test_le_nom_pour_l_historique_ne_depasse_jamais_255_caracteres` : nom de 255 caractères et dénomination de 200, une facture d'achat validée et une entrée d'audit passent sur PostgreSQL, et le nom figé garde le début du nom et du cabinet.

### 6.19 Tests existants : réécrits ou supprimés

« Réécrit » signifie que l'intention reste et que l'expert-comptable est remplacé par un collaborateur d'un cabinet lié, avec les autorisations par défaut sauf mention. « Supprimé » signifie que la fonction testée disparaît, et la raison est donnée.

| Fichier : test (ligne) | Sort | Raison ou nouvelle intention |
|---|---|---|
| `Societe/TypeDActiviteTest` (lot TA) : `test_la_liste_d_etapes_d_un_cabinet_n_a_que_le_code_du_courrier_et_ne_parle_pas_de_magasins` | Réécrit (commit 1) | Le cabinet n'ouvre plus l'espace de la société : 6.1 `test_la_liste_d_etapes_du_cabinet_ne_parle_ni_de_magasins_ni_de_demonstration` |
| `Societe/TypeDActiviteTest` (lot TA) : cas du cabinet de `test_la_verification_et_l_identite_rappellent_le_type_sans_permettre_de_le_changer`, et partie de la page de `test_les_textes_de_la_verification_ne_parlent_a_un_cabinet_ni_de_magasins_ni_de_demonstration` | Réécrit (commit 1) | Les cas du commerce et des services restent dans `TypeDActiviteTest`, comme les avis de validation et de clôture et l'e-mail de clôture du cabinet, que EC garde ; les pages du cabinet passent dans 6.1 (`test_la_verification_du_cabinet_rappelle_son_type_et_ne_parle_ni_de_magasins_ni_de_demonstration`, `test_l_identite_du_cabinet_montre_son_type_sans_bloc_d_assurance`) |
| `Facturation/AssuranceProfessionnelleTest` (lot TA) : `test_un_cabinet_n_a_pas_de_bloc_d_assurance` | Réécrit (commit 1) | La partie de l'action reste ; la partie de la page passe sur `IdentiteDuCabinet` (6.1) |
| Tout autre test du lot TA qui ouvre une page du panneau `societe` avec un cabinet | Réécrit (commit 1) | Même règle : intention gardée sur la page du cabinet ; le message de commit le nomme |
| `Societe/ExpertComptableTest.php` : tout le fichier | Supprimé | Le compte d'expert-comptable de la société disparaît (règle 7). Ses dix intentions sont reprises par les tests nommés en 6.2, 6.6, 6.7 et 6.8 |
| `Authorization/ActionsHorsEcranTest` : fournisseur `actions` (130), `test_l_expert_comptable_n_ecrit_rien_meme_par_l_action` (219), `geste` (235) | Réécrit | `test_un_compte_de_cabinet_n_ecrit_que_ce_que_le_client_autorise_meme_par_l_action` : avec les consultations, rien ; avec les achats, seulement les achats |
| `AffectationPolicyTest` (55, 83) | Réécrit | Un compte de cabinet ne voit aucune affectation du magasin du client |
| `AutorisationsDesRolesTest` : 110, `consultationDeLaCaisseSecurisee` (127), 139 | Réécrit | Ligne « cabinet lié » à la place de « expert-comptable ». `test_il_n_existe_plus_de_role_comptable_de_magasin` (86) est gardé tel quel |
| `AvoirPolicyTest` (43), `test_l_expert_comptable_voit_les_avoirs_de_toute_l_equipe_sans_les_envoyer` (57) | Réécrit | `test_un_compte_de_cabinet_voit_les_avoirs_de_toute_l_equipe_sans_les_envoyer` |
| `ClotureCaissePersonnellePolicyTest` (34), `ClotureCaissePolicyTest` (34), `PaiementPolicyTest` (42) | Réécrit | Rôle de la liste remplacé |
| `ControleDesDroitsTest` (428), 455 | Réécrit | 455 devient le test 6.6 `…quelles_que_soient_les_lignes_en_base`, qu'il remplace (supprimé ici pour ne pas le dupliquer) ; 428 : comparaison avec un collaborateur |
| `DroitsDuResponsableTest` (42, 63) | Réécrit | Les assertions sur l'expert-comptable sont retirées ; un responsable n'a aucun geste sur un compte du cabinet lié (autre société) |
| `GardesDesDroitsEnBaseTest` (66, 171, 197), `expertComptableCreePar` (235) | Réécrit | 66 → 6.2 (`…autorisation_de_societe_a_un_collaborateur`) et supprimé ici ; 171 : « propriétaire » gardé, « expert-comptable » remplacé par « collaborateur hors d'un cabinet » ; 197 : transitions sans expert-comptable ; aide `collaborateurCreePar(Societe $cabinet)` |
| `MagasinDeLaSocietePolicyTest` (31) | Réécrit | Acteur collaborateur : aucun geste |
| `MembrePolicyTest` (24, 63), `test_seul_le_proprietaire_voit_et_gere_l_expert_comptable` (103) | Réécrit | 24 et 63 sans expert-comptable ; 103 → `test_seul_le_proprietaire_du_cabinet_voit_et_gere_ses_collaborateurs` |
| `PorteeDesDroitsTest` : `matrice` (217), `acteur` (332), `cible` (347), `faire` (382), `cocher` (421), `secondExpertComptable` (442), `expertComptablePretAReprendre` (453) | Réécrit | Lignes d'expert-comptable retirées de la matrice d'une société qui n'est pas un cabinet. Nouvelle matrice de cabinet : propriétaire du cabinet, collaborateur, second collaborateur, sur suspendre, réactiver, retirer, réintégrer, redonner l'accès, voir l'identifiant, confier. Aides renommées `secondCollaborateur`, `collaborateurPretAReprendre` (Q-EC3) |
| `Facturation/BordereauDetaxeTest` (387, 409), `PreuvesOperationTest` (526, 545) | Réécrit | Le cabinet lié ne note jamais une preuve ni un bordereau |
| `Facturation/EReportingDesParticuliersTest` (305, 328, 467) | Gardé | Mentions de l'expert-comptable de la société comme personne (règle à confirmer) |
| `Facturation/FacturePolicyTest` (62, 103), `test_l_expert_comptable_n_envoie_aucune_facture` (117) | Réécrit | `test_un_compte_de_cabinet_n_envoie_aucune_facture` |
| `Facturation/RemisesEtPrixTest` (262) | Réécrit | « jamais un cabinet lié », y compris par la garde |
| `Filament/ActionsForgeesTest` : `actionsMasquees` (110), 128 | Réécrit | `creerUnExpertComptable` retiré ; `creerUnCollaborateur`, `confier` et `mettreFin` ajoutés (panneau `cabinet`), et `reglerLesAutorisations` (page de la société) |
| `Filament/BienvenueApercuTest` : `test_un_expert_comptable_lit_expert_comptable` (46) | Réécrit | `test_un_collaborateur_lit_collaborateur_du_cabinet_et_le_nom_du_magasin` |
| `Filament/ClientResourceTest` (1005, 1030), `rolesQuiVoientLesClientsSansLesModifier` (1050) | Réécrit | Rôle « cabinet lié » |
| `Filament/ClotureCaissePersonnelleResourceTest` (105, 143, 173, 206) | Réécrit | Rôle remplacé ; « Les caisses » en lecture avec `cloture_caisse.view_toutes` |
| `Filament/CoordonneesMagasinTest` (92), `test_ni_l_expert_comptable_ni_un_magasin_ferme_ne_reglent_les_coordonnees` (116) | Réécrit | `test_ni_un_compte_de_cabinet_ni_un_magasin_ferme_ne_reglent_les_coordonnees` |
| `Filament/EnregistrementFiscalResourceTest` (349, 377) | Réécrit | Export et traces : collaborateur sans traces |
| `Filament/EquipeDuMagasinTest` : `test_un_responsable_ne_cree_ni_ne_touche_l_expert_comptable` (132) | Réécrit | `test_l_equipe_du_magasin_ne_montre_ni_ne_touche_jamais_les_comptes_du_cabinet_lie` ; 371 et 418 : rôle remplacé |
| `Filament/FacturesAEncaisserTest` (185), `test_l_expert_comptable_voit_l_alerte_en_lecture` (208) | Réécrit | `test_un_compte_de_cabinet_voit_l_alerte_si_le_client_lui_accorde_les_factures_de_toute_l_equipe`, et ne la voit plus si le client retire `facture.view_toutes` |
| `Societe/AdministrateursTest` (62), 94, 362 | Réécrit | Sans expert-comptable ; la partie « expert-comptable » de 94 va dans 6.2 |
| `Societe/CaissesDeLaSocieteTest` : `test_un_membre_ou_un_expert_comptable_n_ouvre_pas_la_page` (141) | Réécrit | `test_un_membre_ou_un_compte_de_cabinet_n_ouvre_pas_la_page` |
| `Societe/ChangementDeRoleTest` (137, 172, 198, 228) | Réécrit | Cas `versExpertComptable` retirés |
| `Societe/ChangementDeRoleTest` : `test_un_compte_d_entrainement_ne_devient_ni_administrateur_ni_expert_comptable` (256) | Réécrit | `test_un_compte_d_entrainement_ne_devient_pas_administrateur` |
| `Societe/ConnexionParIdentifiantTest` (382), 424 | Réécrit | 424 : `…un_responsable_un_administrateur_ou_un_collaborateur_choisit_son_mot_de_passe…` (collaborateur d'un cabinet) |
| `Societe/FermerEtRouvrirMagasinTest` (377), 409 | Réécrit | 409 : `…ni_un_responsable_ni_un_compte_de_cabinet_ne_ferment…` |
| `Societe/IdentifiantsTest` (61, 113, 325, 347), `Societe/MembresTest` (229, 247), `Societe/MagasinDeDemonstrationTest` (563) | Réécrit | Expert-comptable remplacé par un collaborateur (identifiant, pas d'adresse, compte relu) |
| `Societe/PanneauSocieteTest` (84), `comptesSansEspaceSociete` (145), 161 (fournisseur), 260, 289 | Réécrit | Inventaire sans l'action d'expert-comptable ; `comptesSansEspaceSociete` : membre, propriétaire d'un cabinet, collaborateur |
| `Societe/RenouvellementDAccesTest` (143), 326 | Réécrit | 326 : la partie administrateur reste ; la partie expert-comptable va dans 6.2 |
| `Societe/ResponsablesTest` (188) | Réécrit | `…ne_nomme_jamais_le_proprietaire_ni_un_administrateur` |
| `Societe/TransfererProprieteTest` : `test_la_propriete_ne_va_jamais_a_l_expert_comptable` (775) | Supprimé | Le rôle n'existe plus, et un compte d'une autre société ne peut pas recevoir la propriété (clé étrangère composite de `transmissions_propriete`). Les tests de transmission d'un cabinet (Q-EC3) sont ajoutés à `CollaborateursTest` : `test_la_propriete_d_un_cabinet_se_transmet_a_un_collaborateur_qui_suit_le_lien` et `test_l_ancien_proprietaire_du_cabinet_devient_collaborateur_avec_tous_les_clients_confies` |
| `Stock/InventaireOuvertureTest` (286), `test_un_expert_comptable_consulte_un_inventaire_sans_pouvoir_le_saisir_ni_le_valider` (301) | Réécrit | `test_un_compte_de_cabinet_consulte_un_inventaire_et_ne_le_saisit_que_si_le_client_lui_accorde_achats_et_produits` |
| `Stock/StockLedgerServiceTest` (298) | Réécrit | `…ni_a_un_compte_du_cabinet_lie` |
| `Stock/VenteSansStockTest` (265), 287 | Réécrit | 287 : `…et_jamais_accordee_a_un_cabinet_lie` (action et garde) |
| `Tenancy/AccesAuxPanneauxTest` (39, 150) | Réécrit | Trois panneaux |
| `Tenancy/AccesAuxPanneauxTest` : `test_un_expert_comptable_sans_magasin_ouvre_app_sur_une_page_claire` (183) | Supprimé | La page `AucunMagasin` disparaît, plus aucun compte sans magasin hors de l'administration et du cabinet ; remplacé par 6.7 `test_un_compte_de_cabinet_qui_ouvre_app_va_a_l_espace_du_cabinet` |
| `Tenancy/AccesAuxPanneauxTest` : 224 | Réécrit | `test_un_collaborateur_ouvre_les_magasins_reels_des_clients_confies_et_jamais_l_espace_societe` |
| `Tenancy/IsolationDesSocietesTest` (467, 493) | Réécrit | « 404 même pour un compte de cabinet lié aux deux » ; `MODELES_DE_SOCIETE` complété |
| `Tenancy/SelecteurDeMagasinTest` : `test_l_expert_comptable_voit_tous_les_magasins_reels_et_pas_la_demonstration` (64) | Supprimé | Remplacé par 6.7 `test_le_selecteur_d_un_collaborateur_ne_montre_que_les_magasins_reels_du_client_ouvert` |

En plus de ce tableau : un test qui appelle `expertComptable()` sans être listé ici est réécrit avec `collaborateur()` et `lierLeCabinet()`, intention gardée. Après le commit 4, la commande de 7.1.3 ne rend rien dans `tests`, et `grep -rn -i "expert" tests` se relit à la main : seuls restent `EReportingDesParticuliersTest` et les textes du cabinet (« cabinet d'expertise comptable », « Ordre des experts-comptables », textes de l'invitation).

---

## 7. Critères de fin et points de relecture

### 7.1 Critères de fin

1. Sont appliquées : les règles 1 à 7 de `reponses-cabinets.md` (pour la révocation que cite la règle 4, EC fournit `MettreFinAuLienAction::parLEditeur()` et le lot A7 code le reste selon 3.5.14), les règles 1 à 9 de la décision du 19/10/2026 (sa règle 10 est remplacée par le lot TA), et ce que la section « Ce que le lot EC reprend » de `decision-types-activite.md` demande. Chaque règle a au moins un test nommé, et la matrice règle → tests se trouve dans le compte rendu.
2. CLAUDE.md est réécrit selon la section 1. `grep -n -i -E "expert-comptable|experts-comptables" CLAUDE.md` ne rend que les cinq lignes de 1.3 (les trois mentions gardées, la puce des cabinets, la puce « Vérification de la société »). README, `.env.example` et `phpunit.xml` sont à jour.
3. `RoleSociete::ExpertComptable`, `CreerExpertComptableAction`, `AucunMagasin` et toutes les API de 3.10 sont supprimés. `grep -rn -E "ExpertComptable|expertComptable|expert_comptable|EXPERT_COMPTABLE|AucunMagasin|aucun-magasin" app database tests resources routes config`, sans `-i`, ne rend rien : les textes « expert-comptable » et « cabinet d'expertise comptable » restent permis (3.10, 6.19).
3 bis. Rien ne change le type d'une société et EC ne crée aucun type : `git grep -n -E "TypeSociete|ChangerLeTypeDeLaSociete|TypeDeSocieteChange|societes_type_connu_et_fixe" app database tests` ne rend rien. Hors de l'énumération, des migrations et des gardes (`GardesDesTypesDActivite`, `GardesDesCabinets`, `GardesDesDroits`), une valeur de type ne s'écrit que par `TypeDActivite::Commerce`, `TypeDActivite::Services` ou `TypeDActivite::Cabinet` (ou leur `->value` dans une requête), fabriques et tests compris, comme le critère 3 du lot TA l'exige. Les chaînes `'services'` et `'cabinet'` désignent déjà autre chose (la table `services`, le champ `services` du formulaire d'ajout de services, le panneau `cabinet`) : le relecteur contrôle les lignes ajoutées par le lot, `git diff <base du lot> -- app tests database | grep -nE "^\+.*'(commerce|services|cabinet)'"`, dont aucune ne désigne un type hors de ces endroits.
4. Migrations :
   - chaque table changée l'est dans sa migration de création ;
   - les cinq tables nouvelles ont chacune la leur ;
   - `migrate:fresh` passe sur les deux moteurs ;
   - `RetourArriereDesMigrationsTest` est vert ;
   - les gardes de 2.3 se posent et se retirent.
5. Les deux suites complètes sont au code 0 (`echo $?` juste après chacune), à chacun des quatre commits. Au dernier commit, la suite SQLite passe aussi sous faketime aux six instants du lot T1 (0.2 : changement d'année, changements d'heure, 29 février, fin de mois), chacune au code 0.
6. `vendor/bin/pint --dirty --format agent` est propre.
7. Aucune vraie requête externe : le pilote `ordre` réel n'existe pas, et « laisses » dit ce que l'éditeur doit obtenir de l'Ordre.
8. Le compte rendu (« laisses ») liste :
   - les choix de Claude de la section 8 que l'éditeur peut encore contester (Q-EC4, limites, mot de passe redemandé, et les autres) ;
   - les chemins INPI non confirmés ;
   - le nom du nouveau propriétaire d'une société invitée, tiré du représentant légal lu au registre, comme V2, ou, sans représentant lisible, de la dénomination lue au répertoire Sirene ;
   - la consigne au lot A7 (3.5.14) et le fait que l'entrée A7 de `serie8-lots.js` doit la citer.

### 7.2 Points de relecture

1. **Le propriétaire d'un cabinet n'est jamais propriétaire chez un client.**
   - Chaque `estProprietaire()` du code (liste en 3.2 et dans la carte) est soit précédé d'un contrôle de même société, soit dans une branche que la branche du cabinet de `ControleDesDroits` court-circuite.
   - `peutAdministrer()` exclut le cabinet.
   - `estProprietaireDuMagasin()` compare la société.
   - Le test 6.7 couvre chaque autorisation du propriétaire.
2. **La liste blanche `GESTES_D_UN_CABINET`.** Aucun geste d'une policy ne passe pour un cabinet hors de la liste. Une autorisation du registre refusée rend `false`, et non `null`. Le test 6.12 fige l'inventaire.
3. **Jamais de vente.**
   - `duCabinet(TypeDActivite::Commerce)` n'a aucune intersection avec les ventes ni avec la gestion, ni avec `service.create` et `service.update`.
   - La garde `autorisations_cabinet_permises` est écrite en littéraux à partir de `duCabinet(TypeDActivite::Commerce)`, et un test vérifie que la liste SQL et la liste PHP sont égales ; elle refuse aussi toute autorisation de la famille `articles` chez un client qui n'est pas « Commerce ou atelier ».
   - `GardesDeLaVente` refuse les rôles propriétaire et collaborateur.
4. **Les verrous.**
   - L'ordre de 3.5 est respecté dans chaque action : registres, société du cabinet, invitations, société cliente, liens, cabinet, comptes, têtes d'audit. `ConfierDesClientsAction` verrouille ses liens par `verrousAvantLesComptes`. Une demande de rattachement du cabinet et une désignation du client se sérialisent sur la société cliente ; l'acceptation et le refus d'une invitation prennent la société du cabinet avant l'invitation ; le retrait de l'Ordre se fait en deux temps ; la clôture d'une inscription prend les registres de ses magasins réels avant la société (3.5).
   - L'auteur d'une action du cabinet se contrôle dans sa propre société, jamais dans le contexte du client, et ce contrôle n'est jamais relâché.
   - Aucune transaction d'acceptation ne modifie l'invitation d'un autre cabinet. Les invitations caduques passent par le job après le commit.
   - `sousVerrouDesMagasinsDesClients()` est la seule prise de registres de plusieurs sociétés, et elle refuse un magasin sans lien avec le cabinet.
   - L'export du journal relit son auteur sous le verrou du registre.
5. **Les lectures hors du client.** Chaque `withoutGlobalScope` et chaque `DB::table` du diff est l'une des lectures de la section 5, avec ses colonnes, et CLAUDE.md la liste.
6. **Rien ne révèle ce que la décision ne permet pas.**
   - Le cabinet n'apprend que « SIREN inscrit » (point 2 du protocole réécrit) ; avant le lien actif, il ne voit d'une société que le SIREN qu'il a saisi, à l'écran, dans ses avis et dans son journal d'audit ; un SIREN tenu par un cabinet reçoit un refus neutre.
   - La personne qui reçoit le lien d'une demande de rattachement n'apprend que l'existence de l'inscription : ni l'e-mail ni la page d'avant la connexion ne nomment la société, et seul son propriétaire connecté ouvre la page de rattachement.
   - L'adresse saisie pour une demande de rattachement n'est gardée nulle part : ni dans `liens_cabinet`, ni au journal d'audit.
   - Un collaborateur ne voit ni les invitations ni les demandes ; un cabinet qui n'est plus vérifié ne voit aucun client.
   - Au remplacement d'un cabinet, le journal de chacun ne porte que les droits de ses comptes.
   - Les adresses saisies ne sont pas dans le journal d'audit.
   - Les pages d'acceptation et de rattachement sont neutres pour un lien invalide, retiré, refusé ou expiré.
   - Aucun écran ne montre l'identifiant ni l'adresse d'un compte de l'autre société.
7. **Les noms dans l'historique.** Le format « Nom (cabinet X) » est le même au registre signé, dans les noms figés, à l'audit et au JET 130. `ModuleFiscal::VERSION` ne change pas, car le format signé est inchangé : à confirmer par le relecteur.
8. **Le JET 130.** Chaque changement de l'accès d'un compte de cabinet (lien activé, remplacé, terminé, autorisations, client confié, statut du collaborateur, nouveau magasin) s'inscrit dans chaque magasin réel du client concerné, avec `cabinet` dans les droits.
9. **Les deux journaux d'audit.** Les événements de lien sont dans le journal du client et dans celui du cabinet, chacun avec ses champs.
10. **V2.**
    - La validation par une invitation passe par l'action unique de V2, avec sa preuve.
    - Aucun courrier, aucune lettre.
    - L'index unique du SIREN arrête la concurrence.
    - Le contrôle 69.20Z précède le courrier de V2 : fait par le lot TA, EC n'y touche pas.
    - Un rattachement ne valide jamais une société qui attend son code : aucune action de validation n'est appelée hors de l'acceptation d'une invitation pour un SIREN libre.
11. **Les panneaux.**
    - `canAccessPanel`, `getTenants` et `canAccessTenant` pour les trois panneaux.
    - Le sélecteur de magasin d'un compte de cabinet est limité au client ouvert.
    - `MenerUnCompteSansMagasin` mène au cabinet.
    - Les requêtes Livewire forgées sont testées.
12. **Les tests.** Ceux de 6.19 sont réécrits avec leur intention, ou supprimés avec leur raison dans le message de commit. Aucun n'est affaibli. Aucun test du lot TA n'est dupliqué (type, refus hors 69.20Z, absence de magasin).
12 bis. **Le type d'activité.** Le client invité choisit son type à l'acceptation, jamais « Cabinet d'expertise comptable », avec la case du choix définitif, et la société naît avec ce type, jamais une valeur par défaut. Les autorisations du cabinet sont croisées avec le type du client partout où elles se proposent, s'accordent, se lisent ou se vérifient. EC ne lit le type d'une autre société que dans les lectures de la section 5 (L2 bis, L6).
13. **Les e-mails.** L'invitation et la demande de rattachement sont relues au moment de l'envoi, partent à l'adresse saisie et nomment le cabinet ; le propriétaire de la société inscrite ne reçoit qu'un avis en base. Les débits sont respectés : 60 par heure et par personne (invitations et demandes comprises), 50 invitations et demandes par jour et par cabinet, 7 jours avant de renouveler une invitation refusée ou une demande refusée, retirée ou expirée, 5 suggestions par jour et par société.
14. **Les commandes planifiées.** Elles filtrent société par société ; toute invitation close a son `close_le` (contrainte en base), et la purge l'efface 48 heures après, 7 jours pour une invitation refusée ; une demande expirée perd son jeton.
15. **Les notifications.** Un compte du cabinet qui perd l'accès à un client ne garde dans sa cloche aucun avis sur ce client.

---

## 8. Réponses de l'éditeur

Les questions Q-EC1 à Q-EC7 ont reçu leur réponse le 24/10/2026 (`reponses-cabinets.md`, texte d'origine et règles qui en découlent). Elles s'appliquent partout dans ce cahier, qui n'a plus aucun passage entre crochets.

**Q-EC1 — Qui, dans le cabinet, voit les invitations et les demandes, invite, demande, accepte, retire et met fin aux liens ? Comment se fait l'invitation ?**
- Réponse (texte libre, puis question complémentaire) : le cabinet invite ses clients par un lien envoyé par e-mail, qui nomme le cabinet ; le client accepte ou refuse ; un client déjà inscrit se rattache par ce lien, sur une page qui lui montre ses options ; l'invitation se fait uniquement par le SIREN du client ; à l'acceptation, PILIQI contrôle au répertoire de l'État que l'entreprise existe et est active, et le compte s'active sans courrier ni QR code ; le courrier ne sert qu'aux sociétés qui s'inscrivent elles-mêmes. Seul le propriétaire du cabinet invite.
- Appliqué :
  - seul le propriétaire du cabinet invite, demande, accepte, retire et met fin aux liens, et voit seul les invitations et les demandes ; il confie ensuite chaque client lié à ses collaborateurs (1.1, 3.5.4, 3.5.8, 3.5.10, 3.7, 4.1, L11, tests 6.3, 6.5, 6.11) ;
  - l'invitation se fait uniquement par le SIREN et l'adresse e-mail à laquelle part le lien ; l'e-mail nomme le cabinet (3.5.4, `InvitationDuCabinetMail`, `DemandeDeRattachementMail`) ;
  - entreprise pas encore inscrite : contrôle Sirene de nouveau à l'acceptation, société validée sans courrier ni QR code (preuve « invitation du cabinet X »), identité remplie depuis l'INPI ; la personne choisit son mot de passe et le type d'activité, jamais « Cabinet d'expertise comptable », avec la case du choix définitif ; elle peut aussi refuser (3.5.5, 3.9, `RefuserLInvitationAction`, état `refusee`) ;
  - entreprise déjà inscrite : l'invitation devient une demande de rattachement, dont l'e-mail part à l'adresse saisie ; son lien mène, après la connexion du propriétaire, à la page de rattachement, qui montre le cabinet et ses trois choix : accepter avec les autorisations par défaut, accepter en les ajustant, refuser (3.5.4, 3.5.8, 3.9, 4.2 bis, L15, colonne `liens_cabinet.jeton_hache`) ;
  - un rattachement ne valide jamais une société qui attend son code ; le courrier recommandé ne sert qu'aux sociétés qui s'inscrivent elles-mêmes (1.2, 3.5.8, 4.2 bis, test 6.5).

**Q-EC2 — Le type de société peut-il changer ?**
- Réponse : à l'inscription, un menu déroulant fait choisir le type d'activité ; le choix est définitif. Le contenu du menu et l'effet de chaque type sont fixés par `decision-types-activite.md` et `reponses-types-activite.md` (lot TA, qui précède EC) : trois entrées, « Commerce ou atelier », « Services, sans vente d'articles » et « Cabinet d'expertise comptable » ; le type ne change jamais, par aucun écran ni aucune procédure de l'éditeur.
- Appliqué :
  - `TypeSociete`, la colonne `societes.type` et sa valeur par défaut `entreprise`, le bouton radio de l'inscription, l'action `ChangerLeTypeDeLaSocieteAction`, son bouton, l'audit `TypeDeSocieteChange`, la garde `societes_type_connu_et_fixe` et le test du passage d'une entreprise au type cabinet disparaissent du cahier ;
  - EC part de ce que TA a livré : `TypeDActivite`, `societes.type_activite`, l'entrée « Cabinet d'expertise comptable », le contrôle 69.20Z avant tout courrier, le refus de tout magasin et de toute démonstration, la lecture du code d'activité ; il ne les refait pas (0.1, 0.2, 2.1, 2.3, 3.3, 4.4, 6.1, 6.13) ;
  - EC garde ce qui lui revient : la ligne `cabinets` avec le code lu à la confirmation, `ActiviteDuCabinetControlee`, le contrôle par l'éditeur d'un code absent ou d'une autre nomenclature, la revérification mensuelle, la présélection `?type=cabinet`, l'arrivée dans l'espace du cabinet ;
  - un client est une société « Commerce ou atelier » ou « Services, sans vente d'articles », avec ou sans magasin ; les autorisations des achats et du stock ne se proposent et ne s'accordent qu'à un client qui a des articles (3.4, 3.5.9, 4.2, 4.2 bis, garde `autorisations_cabinet_permises`) ; `service.view` est une consultation reçue par défaut, `produit.create` peut s'accorder, `service.create` jamais ;
  - le client invité choisit son type à l'acceptation, jamais « cabinet » (3.5.5, 3.9).

**Q-EC3 — La propriété d'un cabinet, qui n'a pas d'administrateur, se transmet-elle ?**
- Réponse : (a), oui, à un collaborateur.
- Appliqué : à un collaborateur actif, aux mêmes conditions que pour un administrateur (mot de passe choisi, adresse renseignée par lui, différente de toute adresse de connexion d'un propriétaire, lien de 24 heures) ; l'ancien propriétaire devient collaborateur, avec tous les clients du cabinet confiés (1.1, 1.2, 3.5.2, 3.8, 4.1, tests de `CollaborateursTest` et 6.19).

**Q-EC4 — Les sessions ouvertes à la fin d'un lien.**
- Réponse : reste un choix de Claude, que l'éditeur peut contester (`reponses-cabinets.md`, règle 7).
- Appliqué : aucune session n'est fermée à la fin d'un lien ; chaque session perd l'accès au client dès sa requête suivante, et le compte garde son espace et ses autres clients (3.5.10, tests 6.10 et 6.15).

**Q-EC5 — Un cabinet qui n'est plus vérifié garde-t-il ses liens ?**
- Réponse : (a), un cabinet qui n'est plus vérifié n'a plus aucun accès.
- Appliqué : aucun accès aux clients ni nouveau lien tant qu'il n'est pas actif et vérifié, son propriétaire pouvant seulement mettre fin à ses liens ; sa radiation de l'Ordre constatée par l'éditeur (`editeur:cabinet:retirer-ordre`) met fin à tous ses liens, clients et cabinet prévenus ; sa révocation aussi, par la procédure de révocation que crée le lot A7, qui appelle `MettreFinAuLienAction::parLEditeur()` selon la consigne de 3.5.14 (EC ne la code pas : elle n'existe pas encore) ; une suspension ou une résiliation suspend seulement l'accès (`Societe::estUnCabinetVerifie()`, `liensOuverts()`, L2 ter, `cabinets.ordre_retire_le`, `MotifDeFinDuLien::CabinetPlusVerifie`, 4.1, tests 6.1, 6.7 et 6.15).

**Q-EC6 — Une demande de lien expire-t-elle ?**
- Réponse : (a), après 7 jours.
- Appliqué : une demande de rattachement du cabinet comme une désignation du client expire après 7 jours sans réponse, comme l'invitation ; l'expiration efface le jeton du lien de l'e-mail (`liens_cabinet.expire_le`, 3.6, test 6.5).

**Q-EC7 — Le lien d'une société non validée prend-il fin à la clôture de son inscription ou au remplacement de son propriétaire par la saisie du code ?**
- Réponse : (a), oui, dans les deux cas.
- Appliqué : le lien et les demandes en cours prennent fin quand l'inscription est close sans validation (30 jours sans code, procédure de l'éditeur pour refus ou type d'activité choisi par erreur) ou quand une autre personne saisit le code du courrier et devient propriétaire ; le cabinet est prévenu ; le nouveau propriétaire choisit lui-même son cabinet ; une société close ne gère plus de cabinet (3.5.11, `MotifDeFinDuLien::ProprietaireRemplace` et `InscriptionClose`, `SocietePolicy::gererSonCabinet()`, ordre des verrous de la clôture en 3.5, tests 6.5 et 6.15). Le lot A7 applique la même règle à la révocation d'une société cliente (3.5.14).

Choix de Claude, sans question, que l'éditeur peut contester :
- 50 invitations et demandes de rattachement par jour et par cabinet, chaque invitation et chaque demande comptant aussi dans la limite d'e-mails de son auteur (anti-abus ; laissé par l'éditeur comme choix de Claude, `reponses-cabinets.md`, règle 7) ;
- mot de passe redemandé pour inviter (laissé de même comme choix de Claude) ;
- après le refus d'une invitation, ou après le refus, le retrait ou l'expiration d'une demande de lien, une partie ne renouvelle son invitation ou sa demande à la même autre partie qu'après 7 jours (sinon la boucle invitation ou demande, refus ou retrait, nouvelle invitation inonderait l'autre partie) ; une invitation refusée est donc gardée 7 jours avant la purge ;
- 5 suggestions « inviter son expert-comptable » par jour et par société ;
- pour une société déjà inscrite, l'e-mail de la demande de rattachement part à la seule adresse saisie par le cabinet (règle 1 de `reponses-cabinets.md`), et le propriétaire de la société en reçoit aussi un avis dans son espace, sans second e-mail : un cabinet qui se trompe d'adresse ne bloque rien, le propriétaire répondant depuis son espace ;
- l'adresse saisie pour une demande de rattachement n'est gardée nulle part : elle ne sert qu'à l'envoi ;
- le lien d'une demande de rattachement porte un jeton haché, comme les autres liens de PILIQI, effacé dès que la demande n'est plus en cours ; la page qu'il ouvre ne nomme pas la société avant la connexion de son propriétaire, et la personne qui reçoit le lien apprend seulement que le SIREN est inscrit (point 2 du protocole réécrit) ;
- « accepter en ajustant les autorisations » n'est proposé que sur une demande de rattachement du cabinet ; une société invitée (SIREN libre) reçoit les autorisations par défaut de son type et les ajuste ensuite dans « Cabinet comptable », comme une société qui désigne elle-même son cabinet ;
- la page d'acceptation d'une invitation lit le répertoire Sirene à son ouverture, pour montrer la dénomination et les avertissements du code d'activité avant le choix du type (règle 11 de `decision-types-activite.md`) ; sa route est limitée à 30 ouvertures par minute ;
- `produit.create` accordable au cabinet seulement chez un client « Commerce ou atelier », pour les produits d'un achat ou d'un inventaire ; `service.create` et `service.update` jamais ;
- « Produits : gérer le stock », qui ne sert qu'à recevoir les alertes de stock, n'est pas accordable au cabinet, et les alertes ne vont jamais à un compte d'un cabinet : la règle 5 de la décision énumère les achats et le stock ajoutables (factures d'achat, avoirs fournisseur, fournisseurs, inventaires), sans les alertes, qui restent à l'équipe du client ;
- page « Cabinet comptable » et page de rattachement réservées au propriétaire du client ;
- la page « Cabinet comptable » et la page de rattachement montrent au propriétaire du client le n° d'inscription du cabinet à l'Ordre (une donnée publique, qui lui permet de vérifier le cabinet) et, pour un cabinet lié, le nom des personnes du cabinet qui ont accès à sa société (leurs actions portent déjà ces noms au registre et au journal d'audit) ;
- un cabinet n'a que son propriétaire et ses collaborateurs, ni administrateur ni membre : la règle 3 de la décision ne décrit pas d'autre compte ; ses conséquences ont été tranchées par l'éditeur (Q-EC1, Q-EC3) ;
- un cabinet n'est jamais le client d'un autre cabinet : il n'a ni magasin ni document dans PILIQI, et un lien ne lui donnerait rien à consulter ; un SIREN tenu par un cabinet reçoit un refus neutre, qui ne révèle pas son type ;
- une activité classée dans une autre nomenclature que NAFRev2 (une nouvelle nomenclature, NAF 2025, est annoncée, date d'usage et code à vérifier) est contrôlée par l'éditeur dans la procédure de l'Ordre, jusqu'à ce qu'il fixe le code équivalent à 69.20Z ; le lot TA en fait le même choix ;
- l'acceptation d'une invitation est « tout ou rien » face aux pannes : un répertoire Sirene ou un registre national injoignable la fait réessayer plus tard, rien n'étant créé ; un SIREN inconnu du registre, ou sans représentant légal lisible, s'accepte quand même, avec l'identité du répertoire Sirene, comme le veut la règle 1 de `reponses-cabinets.md` (seule l'entreprise active au répertoire est exigée) ;
- le nom du propriétaire d'une société invitée est celui du représentant légal lu au registre, le même dirigeant que V2 retient pour le courrier, modifiable dans son profil (`reponses-v2.md`, point 1) ; sans représentant lisible, la dénomination lue au répertoire Sirene, ou « Propriétaire » quand le répertoire la masque ;
- une invitation est une demande d'inscription faite par le cabinet : elle s'efface 48 heures après sa fin, acceptée ou non, comme les demandes d'inscription, sauf une invitation refusée, gardée 7 jours ; l'acceptation et le refus restent prouvés au journal d'audit ;
- l'accès d'un collaborateur ne se redonne qu'en sa présence : en son absence, son mot de passe redeviendrait son identifiant, qui n'ouvre aucun compte de cabinet ;
- la clôture d'une inscription prend désormais les registres de ses magasins réels avant la société, pour que la fin du lien inscrive le JET 130 sans inverser l'ordre des verrous ;
- l'ajout du cabinet à la campagne de tests de l'étape 1 bis.

---

## 9. Constats de relecture écartés

Les deux relectures adversariales du 24/10/2026 se recoupent : relecture 1 n° 2 et relecture 2 n° 5 (invitations et demandes vues des collaborateurs) ; 1-8 et 2-8 (ce que le cabinet apprend avant le lien) ; 1-9 et 2-1, 2-9, 2-16 (contexte des écritures et lectures hors société) ; 1-15 et 2-14 (durée des invitations) ; 1-17 et 2-15 (inscription close) ; 1-20 et 2-13 (changement de type) ; 1-22 et 2-9 (demandes simultanées) ; 1-23 et 2-4 (cabinet suspendu). Chaque constat réel est corrigé dans le cahier. Sont écartés, en tout ou en partie :

1. **Relecture 1, n° 1, partie « nouvelle question à l'éditeur pour un SIREN inconnu du registre ».** Pas de question : la règle 4a (K2) veut l'identité lue au registre au moment de l'acceptation, et V2 refuse déjà l'inscription d'une entreprise que le registre ne connaît pas, faute de destinataire pour le courrier. Le cahier refuse l'acceptation dans ce cas, comme V2, sans créer de règle nouvelle ; le choix est quand même listé, contestable. Le reste du constat (registre injoignable : refus temporaire, rien n'est écrit, l'invitation reste ouverte ; test 6.4 remplacé) est corrigé. **Remplacé le 25/10/2026** (relecture 1 du 25/10, n° 2) : ce refus ajoutait une condition à la règle 1 de `reponses-cabinets.md`, qui prime ; un SIREN inconnu du registre, ou sans représentant légal lisible, s'accepte désormais avec l'identité du répertoire Sirene (3.5.5, 3.5.6). Le refus temporaire d'un registre injoignable reste.
2. **Relecture 1, n° 8, option « la dénomination que le cabinet a lue lui-même au répertoire Sirene ».** Écartée au profit du seul SIREN (relecture 2, n° 8) : la garder demanderait une colonne de plus pour une donnée que le cabinet relit au répertoire quand il le veut, et que le répertoire masque pour une entreprise non diffusible.
3. **Relecture 1, n° 9, alternative « `runAsSociete($cabinet)` » pour effacer les clients confiés d'un lien.** Écartée au profit de `DB::table` filtré par le cabinet et le lien (relecture 2, n° 1) : l'effacement a lieu dans la transaction de `modifierLeLien()`, déjà dans le contexte du client, et changer de contexte pour cette seule écriture n'apporte rien.
4. **Relecture 1, n° 12 (« un cabinet n'a pas d'administrateur », tranché sans l'éditeur).** Pas un écart : la règle 3 décrit les comptes d'un cabinet, son propriétaire et les collaborateurs qu'il crée, et aucun autre ; ajouter un administrateur créerait un rôle que la décision ne prévoit pas. Ses conséquences, qui gère les liens et à qui transmettre la propriété, ont été tranchées par l'éditeur (Q-EC1 : le propriétaire du cabinet seul ; Q-EC3 : la transmission à un collaborateur). Par transparence, cette lecture est rappelée parmi les choix de Claude.
5. **Relecture 1, n° 13, partie « l'inscription est refusée si le code n'est pas 69.20Z ».** C'est la règle 1 elle-même (« Son code d'activité lu au répertoire Sirene doit être 69.20Z »), et refuser avant le courrier évite à l'éditeur de payer un recommandé pour une inscription qui ne pourrait jamais lier un client. Seul le contrôle par l'éditeur d'une activité classée dans une autre nomenclature est un choix de Claude, désormais listé. Depuis la décision des types d'activité, ce refus est posé par le lot TA, au formulaire et à la confirmation de l'inscription.
6. **Relecture 1, n° 15, partie « effacer l'adresse e-mail à l'acceptation ».** Remplacée par l'effacement de l'invitation entière 48 heures après sa fin, acceptée ou non, comme une demande d'inscription, et 7 jours après pour une invitation refusée (le refus, ajouté par la réponse à Q-EC1, ouvre le délai avant une nouvelle invitation) : garder la ligne sans son adresse n'apporterait rien, l'acceptation étant prouvée par le journal d'audit des deux sociétés et par le lien d'origine `invitation`, et le refus par le journal du cabinet.
7. **Relecture 1, n° 19, partie « ajout du cabinet à la campagne de l'étape 1 bis ».** Déjà signalé comme ajout de Claude, contestable, dans le tableau de la feuille de route (1.2) et dans les choix de Claude : rien à changer. La partie « retrait de l'inscription à l'Ordre » de l'étape 13 est corrigée (« constat de sa radiation », Q-EC5).
8. **Relecture 2, n° 13, repli « à défaut, retenir l'option b ».** Sans objet depuis la réponse de l'éditeur à Q-EC2 (24/10/2026) et le lot TA : le type d'activité est choisi à l'inscription dans un menu et ne change jamais, dans aucun sens ; le passage d'une entreprise au type cabinet, que ce constat discutait, est retiré du cahier.
9. **Consigne de l'éditeur sur les tests (relecture 1, dernière section).** Le test 6.4 du repli sur Sirene est remplacé. Les tests 6.1 (activité dans une autre nomenclature) et 6.3 (SIREN tenu par un cabinet, devenu `test_un_siren_tenu_par_un_cabinet_est_refuse_par_un_message_neutre`) restent : leurs règles sont des choix de Claude listés en section 8, et ils changeront avec elles si l'éditeur les conteste. Le test prévu `test_un_collaborateur_choisit_son_mot_de_passe_a_sa_premiere_connexion` est retiré du cahier : il contredisait la règle qu'un collaborateur ne se connecte jamais avec son identifiant. La relecture 2 ne demandait aucune suppression.

### 9.1 Relectures adversariales du 25/10/2026

Les deux relectures de ce cahier et de `decision-types-activite.md` ont été vérifiées une à une, dans les deux fichiers, CLAUDE.md (arbre de travail de la relecture de V2b), `reponses-cabinets.md`, `reponses-types-activite.md`, `serie8-lots.js` et le code (lecture seule, HEAD `65819c6`). Tous les constats sont réels. Ceux qui touchent ce cahier sont corrigés : relecture 1, n° 1, 2, 3, 5, 6 et 8 ; relecture 2, n° 1 à 6, 9 et 11. Les autres touchent `decision-types-activite.md` et y sont corrigés, sauf le n° 10 de la relecture 2 (ci-dessous). Sont écartés, en tout ou en partie :

10. **Relecture 1, n° 1, option « ne pas montrer la ligne 69.20Z sur la page d'acceptation ».** Écartée au profit d'un texte propre à cette page, écrit par EC dans `AvertissementsDuCodeDActivite` (`pourUneInvitation()`, 3.9). Un cabinet invité par erreur comme client prendrait sinon un type qu'il ne pourra jamais changer : l'avertissement lui dit de refuser l'invitation et de s'inscrire lui-même.
11. **Relecture 1, n° 2, option « soumettre ce point à l'éditeur ».** Pas de question : l'éditeur a déjà répondu. Son texte (Q-EC1) dit « confirmer s'il existe et qu'il est actif et si oui alors son compte client il s'active », et la règle 1 de `reponses-cabinets.md`, qui prime sur ce cahier, « si oui, la société est créée et validée ». Le cahier applique cette règle : seul un registre injoignable fait réessayer. Le nom du compte sans représentant lisible (la dénomination lue au répertoire) est un choix de Claude, listé en section 8.
12. **Relecture 2, n° 1, option « supprimer le test de la liste d'étapes réduite au profit du test 6.1 ».** Écartée : la fonction ne disparaît pas, elle passe de l'espace de la société à celui du cabinet. Le test est réécrit sur `TableauDeBordDuCabinet`, intention gardée (6.1, 6.19).
13. **Relecture 2, n° 3, correction « EC 4.1 donne au bandeau une variante pour le cabinet ».** Sans objet : le texte du cabinet que le lot TA pose dans le bandeau (« Cabinet en attente du code de son courrier recommandé. ») reste vrai dans l'espace du cabinet (3.8).
14. **Relecture 2, n° 4, alternative « pas de modèle, `DB::table` filtré par `societe_id` ».** Écartée au profit d'une colonne `id` et de `unique(['lien_id', 'user_id'])` : le modèle garde `BelongsToSociete`, `FigeLeNomDeSesAuteurs`, sa place dans `MODELES_DE_SOCIETE` et le retrait d'un collaborateur par le modèle, et les clés étrangères composites restent.
15. **Relecture 2, n° 6, partie « écrire la consigne dans l'entrée A7 de `serie8-lots.js` ».** Pas faite ici : ce cahier ne modifie pas le script de la chaîne. La consigne et ses tests nommés sont en 3.5.14 ; l'orchestrateur doit faire citer 3.5.14 par l'entrée A7. EC ne code pas la révocation, qui n'existe pas encore : il fournit `MettreFinAuLienAction::parLEditeur()`.
16. **Relecture 2, n° 10.** Réel, mais dans `serie8-lots.js` (l.131 de l'entrée EC : « règles 1 à 10 », et `reponses-types-activite.md` absent de sa liste de priorité), que ce cahier ne modifie pas. L'ordre qui fait foi est celui de l'en-tête de ce cahier (`reponses-types-activite.md` avant `decision-types-activite.md`, règles 1 à 9 de la décision du 19/10/2026). Signalé à l'orchestrateur, avec la même correction pour l'entrée TA, qui dit que le lot TA « ne crée rien que le lot EC devrait défaire » sans l'exception des branches provisoires du cabinet.
