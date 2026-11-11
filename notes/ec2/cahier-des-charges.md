# Lot EC2 — L'expert-comptable de la société, déclaré par le SIREN de son cabinet : cahier des charges

Rédigé le 01/11/2026 sur la copie figée `ec2/code-01fe217` (état du commit 01fe217 ; son `CLAUDE.md` est identique à celui de ce commit), puis revu le même jour après deux relectures adversariales et le complément de la décision (§ 5, point 3 : message de l'éditeur de 17 h 18 sur l'étape des magasins). Chaque fichier, méthode et numéro de ligne cité a été vérifié dans cette copie ; ce qui vient d'un lot plus récent est cité avec son commit (lot A7 : 0772af0 et 137334e, lus par `git show`). Le lot vient après EC, L1, A7, A7b et ML, avant S9-L, S9-A et le lot 21 (ordre de `LOTS` dans `serie8-lots.js` ; ligne 423 de `historique/DIRECTIVES.md`) : la section 11 lui dit de tout revérifier sur son code de départ.

Statut : cahier définitif. L'éditeur a répondu le 01/11/2026, vers 18 h 55 UTC, « tout comme recommandé » aux treize points de la section 2 : P1 a, P2 b, P3 b, P4 a, P5 a, P6 a, P7 a, P8 a, P9 a, P10 a, P11 a, P12 a, P13 a. Les règles de la section 1, les sections 4 à 9 et les textes de la section 8 sont écrits avec ces options ; les variantes de la section 8.10 sont sans objet. Les choix de Claude de la section 3 (C1 à C40) n'ont pas été contestés.

## Ce qui fait foi, dans l'ordre

1. `ec2/decision-expert-comptable-societe.md`, décision de l'éditeur du 01/11/2026 : son message mot pour mot, ses quatre choix, son § 3 (non contesté), le constat 10 (§ 4, réponse « 1a » du message 322) et les corrections d'écran (§ 5 : messages 323 et 325, puis message de 17 h 18 sur l'étape des magasins). Elle prime sur `CLAUDE.md`, sur `serie8/decision-espace-cabinets.md` (dont la règle 7 tombe) et sur ce que le lot EC a écrit.
2. Les réponses de l'éditeur aux points P1 à P13 de la section 2 : « tout comme recommandé » (01/11/2026), reportées sous chaque point.
3. Ce cahier.
4. Les notes de pilotage : `serie8/reponses-cabinets.md` (réponses Q-EC1 à Q-EC7) ; `serie8/decision-espace-cabinets.md` hors sa règle 7 ; `serie8/avenant-cabinets.md` (ordre des verrous de 3.5, consigne 3.5.14 de la révocation) ; `serie9/reponses-serie9.md`, règle 9 de l'« À faire » (l'expert-comptable de la société n'en voit rien) ; `a7b/decision-a7b.md`, § 2.2 (réponse 2a : le SIREN d'un cabinet se corrige, son contrôle 69.20Z et sa vérification à l'Ordre sont refaits) ; `courriels/constat-queue-listen.md` (`queue:listen` coupé au bout de 60 secondes chez l'éditeur) ; `historique/DIRECTIVES.md`, D50 (la grille faketime ne se lance plus pendant les lots).
5. `CLAUDE.md` : protocole de rigueur, isolation, une seule migration de création par table, modifiée sur place puis `php artisan migrate:fresh`, aucun code de compatibilité, suites SQLite puis PostgreSQL au code 0 contrôlé par `echo $?`.

## Conception retenue

Deux conceptions indépendantes ont été comparées : « lien en attente » et « déclaration séparée ». Ce cahier retient la première et lui ajoute le meilleur de la seconde.

- **La déclaration est un lien en attente.** C'est une ligne de `liens_cabinet`, dans la société qui déclare, d'origine `declaration_du_client` et d'état `declare`, **sans cabinet** (`cabinet_id` nul), avec le SIREN déclaré (`siren_cabinet`) et la dénomination que le répertoire Sirene lui donne (`denomination_cabinet`).
- **Ses autorisations sont des lignes de `autorisations_cabinet`** de cette déclaration, réglées par l'action qui règle celles d'un cabinet lié (`ReglerLesAutorisationsDuCabinetAction`).
- **Ses comptes sont des comptes de la société**, au rôle `expert_comptable`, attachés à la déclaration par la table `comptes_expert_comptable`, qui porte aussi le lien de leur mot de passe.
- **Au rattachement, la même ligne reçoit son cabinet et devient le lien actif**, avec ses lignes d'autorisations telles quelles, sans copie ; dans la même transaction, ses comptes sont retirés. Un job par déclaration fait chaque rattachement, et les avis et e-mails qui le suivent partent chacun dans sa propre tâche en file.
- « Un seul cabinet à la fois, lié ou déclaré » devient un seul index unique partiel ; la fin d'une déclaration, son remplacement et son rattachement passent par `JournalDesDroits::modifierLeLien()` et les actions du lot EC, dont le « lien actif » devient le « lien en cours ».

Pourquoi : c'est la plus simple (un seul modèle de la relation entre un client et son expert-comptable, une seule table d'autorisations, les actions du lot EC réutilisées, aucune copie, aucune suppression de ligne), la plus sûre face aux gestes simultanés (l'unicité tient par un index, atomique, et non par deux gardes croisées sur deux tables ; un seul ordre des verrous), et la plus fidèle à la décision (« ce sont ces autorisations qui passent au lien »). L'isolation se vaut dans les deux : toutes les lectures du cabinet filtrent par `cabinet_id`, et une déclaration, sans cabinet, n'y entre jamais.

Repris de la conception « déclaration séparée » : chaque entrée d'audit ne porte que les droits des comptes d'une seule société ; l'accès redonné en présence de l'expert-comptable, en plus du lien ; un seul message de refus pour un code autre que 69.20Z ou absent, qui propose d'inviter le cabinet à créer son espace ; la limite des contrôles Sirene par société ; l'effacement du lien quand l'e-mail ne peut pas partir ; la page « La société » en lecture seule (P10) ; le non-recontrôle du SIREN posé en question (P9) ; le test d'équivalence entre la portée SQL et `estUnCabinetVerifie()` ; la liste des autorisations dans l'e-mail ; l'essentiel des tests nommés. Écarté de la seconde : les tables séparées et la copie des autorisations, la suppression de la déclaration à sa fin, les gardes croisées sur deux tables, les crochets Eloquent comme déclencheurs.

## Vocabulaire

- **Déclaration** : la ligne de `liens_cabinet` d'état `declare` d'une société ; « terminée » quand elle a pris fin sans rattachement.
- **Lien en cours** d'une société : son lien actif ou sa déclaration ; elle en a au plus un.
- **Expert-comptable de la société** : un compte de la société au rôle `RoleSociete::ExpertComptable` (`expert_comptable`), créé par le propriétaire pour une personne du cabinet déclaré ; **personne déclarée** : la personne de ce compte.
- **Lien du mot de passe** : le lien de 7 jours envoyé à l'adresse d'un expert-comptable de la société, où il choisit son mot de passe : le premier, celui d'un accès redonné ou celui qui suit une adresse corrigée ; son jeton n'est gardé que par son empreinte.
- **Cabinet vérifié** : `Societe::estUnCabinetVerifie()` (`app/Domain/Tenancy/Models/Societe.php` l.329-338) : type cabinet, validé, actif, code d'activité contrôlé, inscrit à l'Ordre sans radiation constatée.
- **Rattachement** : le passage d'une déclaration de `declare` à `actif`, quand le cabinet de son SIREN est vérifié.

## Personnages et dates des exemples

Sociétés et personnes fictives ; les SIREN sont ceux du répertoire Sirene factice ou des numéros fictifs à clé valide ; les heures sont celles de Paris.

- **Boulangerie Bernard SAS**, SIREN 000 000 018, « Commerce ou atelier », validée le 15/10/2026. Propriétaire : Julie Bernard. Administrateur : Thomas Roux, de tous les magasins, avec toutes les autorisations de société. Magasins réels « Lyon 1 » (LYON1) et « Lyon 2 » (LYON2), puis « Villeurbanne » créé le 10/12/2026.
- **Cabinet Petit et Associés**, SIREN 000 000 943 (`FauxRepertoireSirene::SIREN_CABINET`, 69.20Z en NAF rév. 2 ; les tests lui donnent la dénomination « CABINET PETIT ET ASSOCIES » par `nommer()`), absent de PILIQI le 03/11/2026. Ses personnes : Marc Petit (marc.petit@petit-associes.test) et Sophie Lenoir (sophie.lenoir@petit-associes.test). Marc l'inscrira dans PILIQI le 20/11/2026.
- **Compta Rhône**, SIREN 000 000 935, cabinet inscrit et vérifié dans PILIQI depuis le 01/10/2026 ; propriétaire Paul Garnier.
- **Audit Conseil Lyon**, SIREN 000 000 927, 69.20Z, absent de PILIQI ; personne : Luc Moreau (luc.moreau@audit-conseil.test).
- **Épicerie du Parc**, SIREN 000 000 026, code 47.11B.
- **Atelier Morel**, « Services, sans vente d'articles », SIREN 000 000 034, validé le 30/10/2026.
- **Garage Petitjean**, « Commerce ou atelier », SIREN 000 000 042, inscrit le 03/11/2026, en attente du code de son courrier, qu'il saisit le 08/11/2026.
- SIREN 000 000 919 : une entreprise sans code d'activité en NAF rév. 2. SIREN 000 000 968 : diffusion partielle, dénomination masquée (`SIREN_DIFFUSION_PARTIELLE`). SIREN 000 000 992, 000 000 984 et 000 000 976 : entreprise cessée, inconnue, répertoire injoignable (constantes du répertoire factice).

---

## 1. Règles du lot

### R1 — Ce qu'est l'expert-comptable de la société (décision § 1 et § 3 ; choix 4)

- Une société « Commerce ou atelier » ou « Services, sans vente d'articles », validée (P12), déclare son expert-comptable par le SIREN de son cabinet. Pour chaque personne de ce cabinet que le propriétaire nomme, PILIQI crée un compte **de la société**, au rôle de société « expert-comptable de la société ».
- Ce compte n'est ni administrateur ni membre : ni affectation, ni case, ni autorisation de société, ni magasin de rattachement. Tant que sa déclaration est en attente, il ouvre chaque magasin réel de sa société, présent et futur, avec les autorisations de la déclaration (R7) ; jamais le magasin de démonstration, jamais l'espace d'administration de la société, jamais l'espace d'un cabinet.
- Un cabinet d'expertise comptable ne déclare jamais d'expert-comptable (il n'est jamais client) : la base refuse la déclaration et le rôle dans un cabinet.
- Exemple : le 03/11/2026, Julie Bernard déclare 000 000 943 avec Marc Petit. Le compte de Marc est un compte de Boulangerie Bernard : il ouvre « Lyon 1 » et « Lyon 2 », puis « Villeurbanne » dès sa création le 10/12/2026, jamais la démonstration de la société.

### R2 — Seul le propriétaire déclare, dans une société validée (§ 3 ; P12)

- Seul le propriétaire actif déclare, en redonnant son mot de passe, depuis la page « Cabinet comptable » de l'espace d'administration, sous `SocietePolicy::gererSonCabinet()` (`app/Policies/SocietePolicy.php` l.167-174), et seulement dans une société validée : jamais dans un cabinet, ni dans une société close, révoquée ou qui attend le code de son courrier.
- Une société qui attend le code de son courrier ne déclare pas (P12). À la place du formulaire, la page dit : « Vous pourrez déclarer votre expert-comptable dès que votre société sera validée par le code de son courrier. D'ici là, vous pouvez demander le lien à un cabinet déjà présent sur PILIQI ou inviter votre expert-comptable à y créer son espace. » Une requête forgée est refusée par l'action, avec le même texte, et la base refuse la ligne (4.6). Désigner un cabinet vérifié et inviter son expert-comptable à créer son espace restent permis avant la validation, comme au lot EC.
- Une société suspendue ou résiliée déclare encore : un accès se donne et se coupe toujours, comme pour un lien (docblock de `SocietePolicy::gererSonCabinet()`, l.158-166).
- Un administrateur (même de tous les magasins, avec toutes les autorisations de société), un responsable, un vendeur, un observateur, un compte d'un cabinet et un expert-comptable de la société ne déclarent jamais et ne voient pas la page ; une requête Livewire forgée en leur nom est refusée : « Seul le propriétaire déclare l'expert-comptable de la société et gère les comptes de ses personnes. »
- Exemples : Thomas Roux reçoit 403 sur « Cabinet comptable ». Julie déclare après avoir retapé son mot de passe ; un mot de passe faux ne crée rien (« Votre mot de passe est incorrect. », `MotDePasseRedemande::MESSAGE`). Le 03/11/2026, le propriétaire du Garage Petitjean lit le texte d'attente au lieu du formulaire ; le 08/11/2026, il saisit le code de son courrier et déclare son expert-comptable.

### R3 — Le SIREN déclaré est contrôlé (choix 2)

Dans cet ordre, rien ne s'écrit au premier refus :
1. 9 chiffres à clé valide (règle `SirenValide`), différent du SIREN de la société : « Votre société ne peut pas se déclarer elle-même comme expert-comptable. » ;
2. hors de toute transaction, le lien en cours de la société (une donnée à elle, relue ensuite sous verrou) : le SIREN déjà déclaré, ou celui du cabinet déjà lié, est refusé par les messages de R4 ;
3. pas celui d'un cabinet vérifié dans PILIQI (R5) ;
4. au plus 10 contrôles du répertoire par heure et par société : « Trop de vérifications de SIREN pour votre société : réessayez dans une heure. » ;
5. hors de toute transaction, `ControleSirene::unite()` (`app/Domain/Tenancy/Services/ControleSirene.php` l.91-104) doit être concluant : une entreprise inconnue est refusée par `MOTIF_ENTREPRISE_INCONNUE`, une entreprise cessée par `MOTIF_ENTREPRISE_CESSEE` ; un répertoire injoignable fait réessayer (« Le répertoire Sirene ne répond pas : réessayez dans quelques minutes. ») ;
6. `ControleSirene::exerceLesActivitesComptables()` (l.115-122) doit rendre vrai ; faux, ou nul pour un code absent ou d'une autre nomenclature (P6), donne un seul message : « Le répertoire Sirene ne donne pas à cette entreprise le code d'activité 69.20Z (activités comptables) : elle ne peut pas être déclarée comme votre expert-comptable. Si c'est bien votre cabinet, invitez-le à créer son espace sur PILIQI : son activité y sera contrôlée. »

La dénomination lue (`UniteSirene::$denomination`) se garde sur la déclaration, jamais saisie ; nulle en diffusion partielle, les écrans et le nom figé montrent alors le SIREN. Le SIREN déclaré n'est jamais recontrôlé ensuite (P9).

Exemples : 000 000 943 (actif, 69.20Z) passe, avec la dénomination « CABINET PETIT ET ASSOCIES » ; 000 000 992 (cessée) et 000 000 984 (inconnue) sont refusés par le motif de l'INSEE ; 000 000 026 (47.11B) et 000 000 919 (sans code) sont refusés par le message du 69.20Z ; 000 000 968 passe, et les écrans disent « cabinet SIREN 000000968 » ; avec 000 000 976, rien n'est créé et le formulaire garde sa saisie ; au onzième essai de Julie dans la même heure, le répertoire n'est plus interrogé.

### R4 — Un seul cabinet, plusieurs personnes (choix 4 ; P11)

- Une société a au plus un lien en cours : son cabinet lié (lien actif) ou son expert-comptable déclaré (déclaration en attente). La base le garde par un index unique partiel.
- Une déclaration naît avec sa première personne (son nom et son adresse e-mail professionnelle, tapée deux fois) et prend fin quand le propriétaire retire sa dernière personne non retirée (P11, R15). Le propriétaire ajoute ensuite d'autres personnes du même cabinet, en redonnant son mot de passe, chacune avec son compte, son identifiant de connexion et son lien. Toutes ont les autorisations de la déclaration, réglées une seule fois.
- Déclarer de nouveau le SIREN déjà déclaré est refusé : « Ce cabinet est déjà votre expert-comptable déclaré : ajoutez-y une personne. » ; déclarer le SIREN du cabinet déjà lié aussi : « Ce cabinet est déjà votre cabinet lié. » Ces deux contrôles passent avant celui de R5 (R3, étape 2) : un cabinet lié et vérifié reçoit bien ce message, et non celui de R5.
- Ajouter une personne est refusé quand le cabinet du SIREN déclaré a désormais son espace et est vérifié (même recherche qu'en R5) : « Le cabinet de ce SIREN a désormais son espace sur PILIQI : il sera relié à votre société dès qu'une personne déclarée y aura un compte actif. Réactivez-en une, ou retirez votre expert-comptable. »
- Les créations comptent dans la limite du jour du propriétaire (`CreationDesComptes::limiteDuJour()`, `app/Domain/Tenancy/Services/CreationDesComptes.php` l.132-141 : 20 comptes, plus 10 par magasin réel ouvert).
- Exemple : le 03/11/2026 à 10:00, Julie déclare 000 000 943 avec Marc Petit (identifiant 48215937) ; le 05/11/2026 à 14:30, elle ajoute Sophie Lenoir (identifiant 60231189). Le réglage du 06/11 vaut pour les deux comptes. Avec deux magasins ouverts, Julie crée au plus 40 comptes par jour. Si Julie retirait Sophie puis Marc, le retrait de Marc, dernière personne non retirée, mettrait fin à la déclaration.

### R5 — Le SIREN d'un cabinet déjà vérifié ne se déclare pas (P1)

- Si la recherche des cabinets vérifiés par ce SIREN (`RechercheDesCabinets::pour()`, `app/Domain/Cabinets/Services/RechercheDesCabinets.php` l.31-54) trouve un cabinet, la déclaration est refusée avant tout contrôle du répertoire : « Ce cabinet a déjà son espace sur PILIQI : demandez-lui le lien dans « Désigner votre cabinet » ; il entrera par son espace dès qu'il l'aura accepté. » Le formulaire propose « Lui demander le lien », qui fait la désignation existante (`DesignerUnCabinetAction`). Aucun compte n'est créé ; le lien naîtra avec les autorisations par défaut, que le propriétaire réglera.
- Un cabinet inscrit mais pas encore vérifié ne se révèle jamais : la déclaration se fait comme pour un SIREN inconnu de PILIQI, même écran, même message, même résultat.
- Une déclaration qui croise la vérification du cabinet de son SIREN se résout par le rattachement (R13, R14).
- Désigner le cabinet du SIREN déclaré, une fois vérifié, ou accepter la demande de rattachement qu'il envoie, le rattache aussitôt, au nom du propriétaire, sans demande ni attente (R13).
- Exemple : le 03/11/2026, Julie essaie de déclarer 000 000 935 (Compta Rhône) : refus et bouton ; elle clique, Paul Garnier reçoit la demande et l'accepte le 05/11. Si Cabinet Petit s'était inscrit le 01/11 sans avoir encore saisi son code, Julie déclarerait 000 000 943 comme si le cabinet n'existait pas.

### R6 — Remplacer le cabinet lié ou l'expert-comptable déclaré (P2, P3)

- Déclarer alors qu'un autre cabinet est lié, ou qu'un autre SIREN est déclaré, exige une case de confirmation qui nomme ce qui est remplacé ; sans elle : « Votre société a déjà un expert-comptable : {dénomination}. Cochez la case pour le remplacer. » Avec elle, dans la même transaction, le lien en cours prend fin (motif « remplacé ») : un lien actif efface ses clients confiés et ses autorisations, une déclaration retire ses comptes ; puis la nouvelle déclaration naît.
- Déclarer retire aussi, dans la même transaction et au nom du propriétaire, chaque demande de lien que la société a envoyée et qui attend encore sa réponse (désignation d'un cabinet vérifié) ; ce cabinet en est prévenu (« le client a choisi un autre expert-comptable ») et la case de confirmation nomme cette demande (choix de Claude C32, montré à l'éditeur dans P2). Une demande de rattachement reçue d'un cabinet reste : l'accepter remplace la déclaration (puce suivante), sauf celle du cabinet du SIREN déclaré, qui la rattache (R5).
- Lier un autre cabinet (demande de rattachement acceptée par le propriétaire, désignation acceptée par le cabinet) met fin à une déclaration en attente comme à un lien actif, ses comptes retirés. La page de rattachement et la fenêtre de désignation le disent avant.
- Un cabinet lié remplacé est prévenu sans apprendre par qui ; les personnes d'une déclaration remplacée ne reçoivent pas d'e-mail.
- Exemples : Boulangerie Bernard est liée à Compta Rhône depuis le 01/09/2026 ; le 03/11/2026, Julie déclare 000 000 943 en cochant « Je remplace COMPTA RHONE : son accès prend fin aussitôt » ; Compta Rhône perd l'accès (motif « remplacé ») et lit seulement que le lien a pris fin. Le 10/11/2026, Julie remplace Cabinet Petit par Audit Conseil Lyon (000 000 927) avec Luc Moreau, en cochant « Je remplace CABINET PETIT ET ASSOCIES : les comptes de Marc Petit et Sophie Lenoir seront fermés » ; les deux comptes sont retirés et Luc reçoit son lien.

### R7 — Les autorisations de la déclaration (§ 3)

- Ce sont celles qu'un client accorde à un cabinet lié, la même liste et la même table :
  - précochées, `PermissionRegistry::parDefautDuCabinet($type)` (`app/Domain/Authorization/PermissionRegistry.php` l.471-477) : les quinze consultations pour un commerce, les dix qui ne portent pas sur des articles pour une société de services ;
  - le propriétaire décoche ce qu'il veut ;
  - pour une société « Commerce ou atelier » seulement, il ajoute parmi `ajoutablesAuCabinet($type)` (l.490-501).
- Jamais la vente, la gestion des comptes, les réglages, la caisse ni le journal d'audit : `PermissionRegistry::verifierDuCabinet()` (l.530-544) et la base refusent toute autre autorisation, et toute autorisation des articles chez une société de services.
- Une seule liste par déclaration, commune à toutes ses personnes ; une liste vide est permise (les comptes ouvrent les magasins sans aucun document).
- Le propriétaire la règle à tout moment sur « Cabinet comptable », par la même action que pour un cabinet lié : chaque compte ouvert voit ses droits changer, au JET 130 de chaque magasin réel et au journal d'audit, et en est prévenu dans sa cloche.
- Exemple : le 03/11/2026, Julie part des quinze consultations, décoche « Clients : voir » et « Devis : voir », ajoute « Factures d'achat : créer » : quatorze autorisations. Marc ouvre « Lyon 1 » : ni le menu Clients ni le menu Devis, et l'adresse d'une fiche client tapée à la main répond 403 ; il saisit une facture d'achat en brouillon, mais ne la valide pas sans « Factures d'achat : valider ». Pour Atelier Morel, le groupe « Achats et stock » n'apparaît pas, et une requête forgée qui y coche « Factures d'achat : créer » est refusée.

### R8 — Le lien du mot de passe, valable 7 jours (choix 1)

- **L'adresse.** Le propriétaire saisit deux fois l'adresse e-mail professionnelle de la personne (règle `confirmed`), et la fenêtre de confirmation la répète en gras avant l'envoi. Elle s'enregistre en minuscules, sans espaces autour (`User::normaliserEmail()`), comme adresse de contact du compte (`users.email`) : exception voulue à « jamais renseignée par qui crée le compte ». Pour ce rôle, elle est obligatoire tant que le compte n'est pas retiré (la base refuse une adresse nulle) ; elle ne sert jamais à se connecter et peut être celle de plusieurs comptes. Elle n'entre ni au journal d'audit ni dans un journal technique ; elle entre dans la demande dont l'empreinte de création est le hachage SHA-256, jamais en clair (R18, 5.10).
- **Le compte.** Il naît avec un mot de passe tiré au hasard que personne ne connaît, jamais son identifiant (`mot_de_passe_est_l_identifiant` faux ; la base refuse le contraire pour ce rôle) : exception voulue à « le premier mot de passe est l'identifiant ». L'identifiant se tire comme pour tout compte (`GenerateurDIdentifiants::attribuer()`), sans magasin de rattachement.
- **L'e-mail.** Après la transaction, un e-mail part à cette adresse avec un lien valable 7 jours, jusqu'à la même heure de Paris sept jours plus tard (`Calendrier::deParis()->decaler(now(), CarbonInterval::days(7))`, comme l'invitation d'un cabinet).
  - Le jeton ne vit qu'en mémoire jusqu'à l'envoi, marqué `#[SensitiveParameter]` partout où il passe ; seule son empreinte SHA-256 s'enregistre. L'e-mail ne contient jamais l'identifiant ni un mot de passe.
  - Il est relu au moment de l'envoi : il ne part que si le compte est actif, la déclaration en attente, le lien encore celui-ci et non expiré, la société ni close ni révoquée et son auteur encore propriétaire ; sinon il ne part pas, et le propriétaire en est prévenu (« E-mail non envoyé »).
  - Une messagerie en panne efface le lien du compte et prévient le propriétaire, qui en envoie un autre depuis la fiche.
  - Chaque lien compte dans les 60 e-mails par heure du propriétaire (`DebitDesEmails`).
- **La page du lien.** L'ouvrir (GET) n'écrit rien et ne montre ni l'identifiant, ni l'adresse, ni le nom du propriétaire : la dénomination et le SIREN de la société, le nom de la personne, le cabinet déclaré, les libellés des autorisations de la déclaration, puis le formulaire. Choisir son mot de passe (POST) applique la règle du mot de passe avec l'identifiant (`RegleDuMotDePasse::exiger()`), consomme le lien (il ne sert qu'une fois), date le premier mot de passe choisi (`mot_de_passe_choisi_le`, qui ferme la correction de l'adresse, R12), ferme les autres sessions du compte, ferme d'abord la session d'un autre compte ouverte dans ce navigateur (sa fin s'inscrit, JET 40), connecte la personne et lui montre son identifiant.
- **La page neutre.** Un lien expiré, déjà servi, remplacé ou inconnu, ou celui d'un compte suspendu (son lien est effacé à la suspension, R12) ou retiré, d'une déclaration qui n'est plus en attente ou d'une société close ou révoquée, rend la même page neutre (code 410).
- Exemple : le 03/11/2026 à 10:00, PILIQI crée le compte de Marc (identifiant 48215937, que Julie voit dans la fenêtre de confirmation) ; le lien vaut jusqu'au 10/11/2026 à 10:00. Le 04/11/2026 à 09:12, Marc suit le lien, choisit son mot de passe, est connecté et lit « Votre identifiant de connexion est 48215937 : notez-le. » Le 05/11, le même lien rend la page neutre ; un lien jamais servi, suivi le 10/11 à 10:00:01, la rend aussi. Un lien créé le 27/03/2027 à 10:00 vaut jusqu'au 03/04/2027 à 10:00, malgré le passage à l'heure d'été du 28/03/2027.

### R9 — Connexion et profil (P13)

- Il se connecte avec son identifiant et son mot de passe, jamais avec son adresse ; « Se souvenir de moi » ne vaut pas pour lui ; aucun poste reconnu ne lui sert (`PostesReconnus::ouvreLeCompte()` rend faux pour lui comme pour un compte de cabinet).
- Les essais manqués font attendre comme pour tout compte ; à chaque connexion, il voit la date de la précédente ; sa première connexion s'inscrit au journal d'audit (`PremiereConnexion`) et prévient le propriétaire (`SuiviDesConnexions::noterLaConnexion()`).
- « Mot de passe oublié » ne lui sert jamais, même quand son adresse est aussi l'adresse de connexion d'un propriétaire (celle de son propre cabinet, une fois inscrit) : le lien ne part qu'au propriétaire et ne touche que son compte ; l'accès de l'expert-comptable se redonne par le propriétaire de la société (R12).
- Dans son profil, il change son nom et son mot de passe en donnant son mot de passe actuel, et y lit son identifiant. Son adresse reste obligatoire ; une nouvelle adresse ne prend effet qu'après un clic sur le lien envoyé à cette adresse, valable 24 heures, et l'ancienne est prévenue (P13) ; d'ici là, les liens que le propriétaire envoie partent à l'ancienne. L'aide du champ le dit.
- Exemples : Marc tape marc.petit@petit-associes.test dans le champ de l'identifiant : la connexion échoue avec la réponse commune ; avec 48215937 et son mot de passe, il entre ; Julie reçoit « Marc Petit s'est connecté à son compte pour la première fois. » Le 02/12/2026, Marc remplace son adresse par m.petit@petit-associes.test : elle vaut quand il clique le lien reçu, et marc.petit@petit-associes.test reçoit l'avis du changement.

### R10 — Ce que voit et fait l'expert-comptable (§ 3)

- Dans chaque magasin réel de sa société : exactement les autorisations de la déclaration, croisées avec `PermissionRegistry::duCabinet($type)` ; une autorisation qu'il n'a pas est un refus net ; une question de policy n'est permise que pour un geste de `ControleDesDroits::GESTES_D_UN_CABINET` (`app/Domain/Authorization/ControleDesDroits.php` l.123-132), comme pour un compte de cabinet.
- Jamais :
  - vendre, encaisser, utiliser un bon d'avoir, ouvrir la caisse ou avoir une caisse personnelle (la base refuse une vente ou un encaissement à son nom) ;
  - clôturer une journée, faire un avoir ou un devis, envoyer un e-mail à un client ;
  - gérer un compte, une affectation, un poste reconnu, le magasin ou la société ;
  - lire le journal d'audit ou les adresses IP et navigateurs (l'export du journal des événements ne les rend qu'au propriétaire) ;
  - recevoir une alerte de stock (`DestinatairesDesAlertes::autorisesDans()` ne prend que le propriétaire et les comptes affectés) ;
  - voir quoi que ce soit de l'« À faire » (règle 9 de `serie9/reponses-serie9.md`, appliquée par le lot S9-A).
- Il suit les règles de la société : lecture seule d'un magasin fermé, cédé ou suspendu, ou d'une société suspendue ou résiliée ; documents émis immuables.
- L'alerte des factures à encaisser de son tableau de bord le suit comme un compte de cabinet : seulement avec « Factures : voir » et « Factures : voir celles de toute l'équipe » (`User::suitLesFacturesAEncaisserDans()`, `app/Models/User.php` l.647-652), jusqu'au lot S9-A.
- Il voit en lecture seule, dans le panneau des magasins, une page « La société » : identité légale, type d'activité et réglages fiscaux communs (P10).
- Sa société n'a aucun magasin réel : il arrive sur la page « Aucun magasin pour le moment », jamais sur une erreur 404.
- Son tableau de bord dit « Bonjour Marc Petit » et « Expert-comptable déclaré par la société · cabinet CABINET PETIT ET ASSOCIES · Lyon 1 » (C7).
- Exemple : Marc ne crée aucune facture (403 ; la base la refuserait) ; il exporte le journal des événements de « Lyon 1 », signé à son nom avec son cabinet, sans aucune adresse IP ; dans « Lyon 1 », l'adresse d'une facture de « Lyon 2 » lui répond 404.

### R11 — Son nom dans l'historique

- Chaque action se fait en son nom, suivi de ce qui le rattache au cabinet déclaré, sans laisser croire que ce cabinet l'a reconnu : « Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES) », ou « … cabinet SIREN 000000968) » quand le répertoire masque la dénomination (C6). Le tout tient en 255 caractères, par la règle de raccourcissement de `User::nomPourLHistorique()` (`app/Models/User.php` l.399-413), partagée avec les comptes de cabinet, et se fige dans les colonnes `*_nom` (`FigeLeNomDeSesAuteurs`) et dans le registre fiscal.
- La dénomination et le SIREN se lisent sur la déclaration du compte par `ExpertsComptablesDeclares::declarationDe()`, sans scope, filtrée par la société du compte et par son attachement, dans tout contexte (registre, journaux, `User::nomFigeDe()`), y compris après la fin ou le rattachement de la déclaration (L4).

### R12 — La gestion des comptes de l'expert-comptable

- **Le propriétaire seul :**
  - il suspend, réactive, retire et réintègre un compte, tant que sa déclaration est en attente ; suspendre efface aussi le lien du mot de passe en cours, et à la réactivation aucun lien ne revit (il en envoie un nouveau ou redonne l'accès en présence) ; retirer la dernière personne non retirée met fin à la déclaration (P11, R15) ;
  - il voit les identifiants, comme ceux de tout compte (`PorteeDesDroits::voitLIdentifiant()`, l.300-307) ;
  - il redonne l'accès, soit en présence de la personne, qui tape elle-même son nouveau mot de passe sur son écran (le lien en cours s'efface), soit par un nouveau lien de 7 jours (P4) : le mot de passe d'avant ne sert plus dès l'envoi, les sessions se ferment, le lien d'avant ne vaut plus ; jamais en remettant l'identifiant pour mot de passe ;
  - il corrige l'adresse d'une personne qui n'a pas encore choisi son mot de passe (ni par un lien, ni en sa présence), en redonnant son mot de passe et en tapant deux fois la nouvelle adresse (P5) : le lien d'avant ne vaut plus, un nouveau part ; ensuite, seule la personne change son adresse, dans son profil (« Cette personne a déjà choisi son mot de passe : elle seule change son adresse, dans son profil. »).
- Aucun administrateur, responsable ni autre compte ne voit ni ne touche ces comptes (`PorteeDesDroits::comptesVisibles()`, l.438-466, ne les montre qu'au propriétaire).
- Un compte d'expert-comptable ne change jamais de rôle (la base le refuse ; une requête forgée reçoit 403, jamais 500), aucun compte ne le devient, et il ne reçoit jamais la propriété (`DemanderLaTransmissionDeLaProprieteAction::exigerLesConditions()`, l.202, n'accepte qu'un administrateur).
- Un compte retiré garde son rôle, son identifiant et son historique, sans aucun droit ; il ne se rouvre plus une fois sa déclaration terminée ou rattachée. Réintégré, il garde son mot de passe, comme tout compte.
- Une demande répétée après une réponse perdue ne crée jamais un second compte et n'envoie jamais un second lien ; au plus 5 liens par jour et par compte (« Vous avez déjà envoyé 5 liens à cette personne aujourd'hui : réessayez demain. »).
- Exemples : le 15/12/2026 à 16:05, Marc a oublié son mot de passe ; Julie choisit « Lui envoyer un nouveau lien » : son mot de passe cesse de valoir, ses sessions se ferment, un lien part, valable jusqu'au 22/12/2026 à 16:05 ; à sa connexion suivante, Marc lit « Votre accès a été redonné le 15/12/2026 à 16:05 par Julie Bernard, par un lien envoyé à votre adresse. » Le 05/11, Julie avait tapé deux fois sophie.lenoit@petit-associes.test ; le 06/11, Sophie n'ayant pas encore choisi son mot de passe, elle corrige l'adresse : le premier lien ne vaut plus, un nouveau part à sophie.lenoir@petit-associes.test.

### R13 — Le rattachement : le compte se ferme, l'accès passe par le cabinet (choix 3 ; P11)

- Quand un cabinet dont le SIREN est celui d'une déclaration en attente devient vérifié, la déclaration de chaque société qui a déclaré ce SIREN devient son lien actif, chacune dans sa propre transaction, dès qu'au moins une de ses personnes a un compte actif (P11 ; sinon elle attend, et la réactivation ou la réintégration d'une personne relance le rattachement, R14) :
  - la même ligne reçoit `cabinet_id`, passe à `actif`, et `actif_depuis` vaut maintenant ; ses lignes d'autorisations restent telles quelles (aucune copie) ;
  - tous ses comptes d'expert-comptable sont retirés : droits supprimés, avis effacés, sessions fermées, lien du mot de passe effacé ; leur historique reste ;
  - les demandes en cours entre cette société et ce cabinet, dans les deux sens, sont retirées, leur jeton effacé (elles n'ont plus d'objet) ;
  - le JET 130 de chaque magasin réel inscrit les comptes retirés (droits → nul) et les comptes du cabinet qui entrent (son propriétaire : nul → les autorisations) ; les journaux d'audit du client et du cabinet reçoivent leurs entrées (section 7) ;
  - après la transaction, les propriétaires des deux sociétés sont prévenus (sauf l'auteur du geste, s'il y en a un), et chaque personne dont le compte se ferme reçoit un e-mail, sans identifiant, qui lui dit d'entrer par l'espace de son cabinet dès que son propriétaire lui confiera ce client, et quoi faire si ce cabinet n'est pas le sien ; chaque avis et chaque e-mail part dans sa propre tâche en file, rejouée seule (C3).
- Le propriétaire de la société qui désigne ce cabinet ou accepte sa demande de rattachement fait ce même rattachement, à son nom : les comptes sont retirés par lui, et le lien prend les autorisations qu'il valide, préremplies avec celles de la déclaration (C31).
- Aucun accord n'est demandé au cabinet ni à la société ; l'un ou l'autre propriétaire met fin au lien quand il veut, selon les règles du lot EC. Les collaborateurs du cabinet n'entrent que quand son propriétaire leur confie ce client.
- Plusieurs sociétés peuvent avoir déclaré le même SIREN : chacune est rattachée par son propre job, dans sa propre transaction, et le cabinet découvre chaque client comme un lien distinct, prévenu une fois par client (C34).
- Le rattachement est rejouable sans effet et ne laisse aucune trace de visiteur quand le système le fait ; une déclaration n'existant que dans une société validée (P12), il ne touche jamais à la validation.
- Exemple :
  - le 20/11/2026, Marc Petit inscrit Cabinet Petit (000 000 943, 69.20Z contrôlé) ; Boulangerie Bernard ne voit rien changer et le cabinet n'apprend rien d'elle ;
  - le 26/11/2026, l'annuaire de l'Ordre confirme son inscription ; le cabinet n'a pas encore saisi son code : rien ;
  - le 27/11/2026 à 11:20, Marc saisit le code de son courrier ; après le commit, le job de la déclaration de Boulangerie Bernard en fait son lien actif avec Cabinet Petit, avec ses quatorze autorisations ; les comptes 48215937 (Marc) et 60231189 (Sophie) sont retirés ; le JET 130 de « Lyon 1 » et de « Lyon 2 » l'inscrit ; Julie et Marc, propriétaire du cabinet, sont prévenus ; Marc et Sophie reçoivent l'e-mail de fermeture ; Marc crée le compte de collaboratrice de Sophie dans son cabinet et lui confie Boulangerie Bernard.

### R14 — Ce qui déclenche le rattachement, et son rattrapage

- Le rattachement part après le commit de chaque geste qui peut rendre un cabinet vérifié ou une déclaration rattachable :
  - la validation du cabinet par le code de son courrier (écouteur de `SocieteValidee`) ;
  - la vérification de l'Ordre par l'annuaire (`VerifierLOrdreAction`) ;
  - le constat de l'éditeur (`ConstaterLInscriptionALOrdreAction`), qui lève aussi une radiation et peut contrôler l'activité ;
  - la correction du SIREN d'un cabinet par l'éditeur (lot A7b) et chaque geste qui le revérifie ensuite sous son nouveau SIREN (nouveau contrôle du code 69.20Z, vérification à l'Ordre par l'annuaire, constat de l'éditeur) : le job part pour le SIREN en vigueur ; les liens nés d'une déclaration de l'ancien SIREN restent, la garde ne comparant les SIREN qu'au passage de `declare` à `actif` ;
  - chaque déclaration, remplacement compris (elle peut croiser la vérification du cabinet de son SIREN) ;
  - la réactivation et la réintégration d'un compte d'expert-comptable (P11) ;
  - plus tard, la levée d'une suspension et la réactivation d'un cabinet (étapes 12 et 13).
- La désignation du cabinet déclaré et l'acceptation de sa demande rattachent directement, dans la requête du propriétaire (R5, R13).
- Une tâche horaire rattrape tout rattachement manqué (job perdu, chemin futur oublié) : au plus une heure entre un cabinet vérifié et le rattachement d'une déclaration rattachable de son SIREN ; une déclaration dont aucune personne n'est active est relue sans rien changer.
- La saisie du code verrouille la ligne `societes` du cabinet et la vérification de l'Ordre sa ligne `cabinets` : deux transactions simultanées peuvent ne pas voir l'écriture l'une de l'autre. Chacune relance le rattachement après son commit, qui relit tout : la dernière voit les deux.

### R15 — La fin de la déclaration

- Elle prend fin :
  - quand le propriétaire la retire (« Retirer votre expert-comptable », motif « par le client ») ;
  - quand le propriétaire retire sa dernière personne non retirée (P11, motif « par le client ») ;
  - quand un autre cabinet est lié ou déclaré (R6, motif « remplacé ») ;
  - à la révocation de la société (motif `societe_revoquee` du lot A7) ;
  - à son rattachement, où elle devient le lien actif (R13).
- Une déclaration n'existe que dans une société validée (P12) : la clôture d'une inscription et la reprise d'une société par la saisie de son code n'en rencontrent jamais.
- Hors rattachement, la ligne passe à « terminé » (auteur, date, motif), ses autorisations s'effacent, ses comptes sont retirés et leurs liens ne valent plus ; JET 130 dans chaque magasin réel ; entrée `LienTermine` au seul journal de la société, aucun cabinet n'existant.
- Les comptes sont retirés par le propriétaire qui retire ou remplace la déclaration ou retire sa dernière personne ; sans auteur à la révocation (procédure de l'éditeur) et quand c'est le propriétaire d'un cabinet qui accepte la désignation qui la remplace (un compte d'un cabinet n'écrit jamais le statut d'un compte du client).
- Exemple : si, le 18/11/2026, Julie retirait sa déclaration, la ligne passerait à « terminé » (par le client), ses quatorze lignes d'autorisations s'effaceraient, les comptes de Marc et de Sophie seraient retirés par Julie, et le lien encore valable de Sophie rendrait la page neutre.

### R16 — Après le rattachement (P7)

- Le lien né d'une déclaration est un lien comme un autre : le propriétaire du cabinet confie le client, le propriétaire du client règle les autorisations et y met fin, la radiation ou la révocation du cabinet y met fin.
- Les comptes retirés au rattachement ne se rouvrent jamais (la base le refuse). Si le lien prend fin, rien ne revit, même après la révocation d'un cabinet qui avait pris le SIREN d'un autre (`Societe::sirenPris()` libère alors ce SIREN pour le vrai cabinet) ; la société déclare de nouveau un cabinet qui n'est pas vérifié, ou désigne un cabinet vérifié, avec de nouveaux comptes et de nouveaux identifiants.
- Exemple : le 15/01/2027, l'éditeur constate la radiation de Cabinet Petit ; le lien prend fin (motif « cabinet plus vérifié ») ; le compte 48215937 reste retiré ; le 20/01/2027, Julie déclare de nouveau 000 000 943 avec Marc, qui reçoit un nouveau compte et un nouvel identifiant.

### R17 — La vie de la société

- Transmission de la propriété : les comptes d'expert-comptable restent, le nouveau propriétaire les gère ; aucun ne pouvait recevoir la propriété.
- Suspension ou résiliation de la société : lecture seule ; l'expert-comptable garde ses consultations ; déclarer, régler et gérer ses comptes restent permis ; le jour où le cabinet déclaré est vérifié, la déclaration se rattache quand même, et le cabinet n'a que la lecture seule, comme tout cabinet lié d'une société suspendue (C33).
- Révocation de la société : sa déclaration prend fin à la révocation et ses comptes sont retirés ; une société révoquée ne gère plus rien.
- Avant la validation, aucune déclaration (P12) : le code saisi par une autre personne et la clôture de l'inscription ne trouvent ni déclaration ni compte d'expert-comptable.
- Exemples : le 05/12/2026, Julie transmet la propriété à Thomas Roux : Thomas voit et gère les comptes de Marc et de Sophie. Si Boulangerie Bernard était suspendue le 20/11/2026, le rattachement du 27/11 aurait lieu quand même, et Cabinet Petit ne ferait que consulter.

### R18 — Ce que chacun apprend (isolation)

- La société qui déclare n'apprend qu'un cabinet de ce SIREN existe que s'il est vérifié (R5), ce que la recherche des cabinets montre déjà ; jamais l'existence d'un cabinet inscrit qui ne l'est pas.
- Le cabinet n'apprend rien des sociétés qui ont déclaré son SIREN avant que le lien soit actif : ni leur nombre, ni un avis, ni une entrée d'audit, ni une ligne dans ses listes. Au rattachement, il apprend le client comme tout client lié (identité, magasins réels, autorisations). Les comptes fermés ne lui sont jamais nommés dans un avis ni dans son journal d'audit (P8) ; il ne les lit que dans le journal des événements des magasins que le client lui laisse consulter (« Caisse sécurisée : consulter », JET 130 du rattachement compris : `JournalDesDroits::inscrireLeJet130()`, l.551-573, y inscrit `membre` {id, nom figé}), comme il y lit déjà toute l'équipe du client.
- Aucune société n'apprend qu'une autre a déclaré le même SIREN ; aucune règle d'unicité ne porte sur le SIREN déclaré hors de la société qui le déclare.
- Un compte d'expert-comptable n'ouvre que les magasins réels de sa société.
- L'adresse et l'identifiant d'une personne ne s'écrivent jamais au journal d'audit, ni en clair dans l'empreinte de création ; l'identifiant ne paraît jamais dans un e-mail.
- Exemple : Boulangerie Bernard et Atelier Morel déclarent toutes deux 000 000 943 ; aucune ne le sait de l'autre ; Cabinet Petit, inscrit le 20/11 mais pas encore vérifié, ne voit rien ; le 27/11, chacune est rattachée par son propre job.

### R19 — Le refus d'inviter le SIREN d'un cabinet (constat 10, décision § 4, réponse « 1a »)

- Le message actuel reste : `InviterUneEntrepriseAction::MESSAGE_SIREN_NEUTRE` (`app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php` l.69, « Ce SIREN ne peut pas être invité par votre cabinet. »), levé par `demanderLeRattachement()` (l.201-205) quand la société qui tient le SIREN est un cabinet ; le code ne change pas.
- L'exception s'écrit dans `CLAUDE.md` (protocole, point 2 ; « Produit » ; « Type d'activité », section 8) : le cabinet qui invite apprend que ce SIREN est celui d'un cabinet inscrit, et rien d'autre.
- Exemple : Compta Rhône invite 000 000 943 le 25/11/2026 : « Ce SIREN ne peut pas être invité par votre cabinet. », sans autre information ; c'est vrai aussi pendant que Cabinet Petit attend encore son code (`Societe::societeQuiTientLeSiren()`, l.385-394, compte une inscription qui attend son code).

### R20 — La phrase des activités exonérées (décision § 5.1)

- `TypeDActivite::rappelDuChoixDefinitif()` (`app/Domain/Tenancy/Enums/TypeDActivite.php` l.78-83) devient, avec `{application}` lu dans `config('app.name')` comme aujourd'hui :
  « Ce choix est définitif : il décide des menus de {application} (articles, achats et stock) et ne changera pas, même si votre activité change ensuite. Dans le doute, choisissez « Commerce ou atelier », le plus complet. Les micro-entrepreneurs en franchise de TVA (art. 293 B du CGI) sont pris en charge. En revanche, {application} ne permet pas de facturer une activité exonérée de TVA par la loi (soins médicaux, enseignement, assurance, location de logements…). »
- Elle s'affiche partout où la méthode est appelée : `InscriptionSociete` (l.251), `resources/views/inscription/confirmer.blade.php` (l.70) et `resources/views/cabinets/invitation.blade.php` (l.96).
- Le refus des activités exonérées (art. 261 à 261 E du CGI) reste, comme les avertissements du code d'activité ; la phrase de `CLAUDE.md` (« activité exonérée de TVA que PILIQI ne facture pas ») ne change pas.

### R21 — L'astérisque des mots de passe obligatoires (décision § 5.2)

- Un champ de mot de passe choisi obligatoire montre l'astérisque rouge des champs obligatoires, comme sa confirmation : `InscriptionSociete::getPasswordFormComponent()` (l.275-285), `PageDuLien::form()` (champ `password`, l.67-73) et `ChoisirSonMotDePasse::form()` (champ `password`, l.62-68) reçoivent `->required()`, en plus de leurs règles.
- Dans les pages Blade où un mot de passe choisi est obligatoire (`resources/views/cabinets/invitation.blade.php`, `resources/views/code/saisir.blade.php` et la nouvelle page du lien de l'expert-comptable), chaque champ obligatoire, mot de passe et confirmation compris, porte le même astérisque rouge, sauf la confirmation de la page du code, facultative (« si vous le choisissez maintenant », `resources/views/code/saisir.blade.php` l.70-71), qui n'en porte pas.
- Vérifiés, rien à changer : le profil (`ModifierProfil`, nouveau mot de passe facultatif ; « Mot de passe actuel » déjà `->required()`, l.179) ; la transmission de la propriété (`AccepterLaTransmission`, l.73-79, déjà `->required()`) ; la fenêtre « en sa présence » (`GereLesComptes::champsDuMotDePasseChoisi()`, l.362-381, déjà `->required($visible)`).

### R22 — L'étape des magasins dans « Vos premières étapes » (décision § 5.3 ; choix de Claude que l'éditeur peut contester)

- Dans `TableauDeBord::etapes()` (`app/Filament/Societe/Pages/TableauDeBord.php` l.119-166), tant que la portée du compte ne compte aucun magasin réel, l'étape s'intitule « Créer votre premier magasin » (`ETAPE_PREMIER_MAGASIN`, l.53) et mène à la création d'un magasin.
- Dès le premier magasin réel, quel que soit son statut, elle s'intitule « Ajouter un magasin » (nouvelle constante `ETAPE_AJOUTER_UN_MAGASIN`), reste cochée avec dessous « 1 magasin » ou « 3 magasins », sans ordinal, et mène à la création d'un magasin si `MagasinResource::canCreate()`, sinon à la liste (aujourd'hui, l.143-147 ne mène à la création que tant qu'il n'y a aucun magasin).
- L'étape « Ajouter votre équipe » ne compte pas les comptes d'expert-comptable de la société (l.129 : `where('role_societe', '<>', 'expert_comptable')`) : ce n'est pas l'équipe des magasins.
- Exemple : la société du Garage Petitjean n'a aucun magasin : « Créer votre premier magasin », non cochée ; après « Garage 1 » : « Ajouter un magasin », cochée, « 1 magasin » ; après trois magasins : « 3 magasins ». Une déclaration seule laisse « Ajouter votre équipe » non cochée.


### R23 — « Voir les siennes » n'existe pas pour un cabinet ni pour l'expert-comptable déclaré (décision § 5.4 ; message de l'éditeur du 01/11/2026, 21 h 29)

- Constat de l'éditeur (capture de la fenêtre « Accepter en ajustant les autorisations » et de l'e-mail de la demande de rattachement) : on y coche séparément « Factures : voir (les siennes) » et « Factures : voir celles de toute l'équipe », de même pour « Clôture de caisse » ; or un compte d'un cabinet, comme un compte d'expert-comptable déclaré, ne fait jamais ni facture ni clôture : « les siennes » ne désigne rien, et « voir les siennes » seul ne lui montre rien. « Elle voit normalement toutes les factures. »
- Règle : pour un cabinet lié et pour l'expert-comptable déclaré, chaque paire devient une seule case : « Factures : voir toutes celles du magasin » accorde ensemble `facture.view` et `facture.view_toutes` ; « Clôtures de caisse : voir toutes celles du magasin » accorde ensemble `cloture_caisse.view` et `cloture_caisse.view_toutes`. Les deux permissions de chaque paire sont toujours accordées ou retirées ensemble : `PermissionRegistry` (consultations du cabinet, `parDefautDuCabinet`, libellés), l'action qui règle les autorisations d'un cabinet (et celle de la déclaration, R7), les écrans (désignation, page de rattachement, « Accepter en ajustant », page « Cabinet comptable », autorisations de l'expert-comptable déclaré), et le texte de l'e-mail de la demande de rattachement, qui ne cite plus « (les siennes) ». Garde en base, sur SQLite et PostgreSQL : pour une ligne d'autorisations d'un cabinet ou d'une déclaration, `facture.view_toutes` est présente si et seulement si `facture.view` l'est, de même pour `cloture_caisse` ; toute autre combinaison est refusée.
- Rien ne change pour les comptes de la société (vendeurs, observateurs, administrateurs), pour qui « les siennes » garde son sens.
- Le tableau de bord du cabinet et l'alerte des factures à encaisser lisent toujours « voir les factures de toute l'équipe » (`CLAUDE.md`), désormais accordé par la seule case des factures.
- Tests : la fenêtre et l'e-mail montrent une seule case ou un seul libellé par paire ; cocher la case accorde les deux permissions et la décocher retire les deux ; la garde refuse en base une paire incomplète (insertion directe), pour un cabinet comme pour une déclaration ; un compte d'un cabinet à qui la case des factures est accordée voit toutes les factures du magasin, et sans elle aucune.
- **Complété le 11/11/2026 par la décision, § 5 ter** (réponse de l'éditeur à T44) : en plus de cette case unique, le propriétaire choisit les magasins que voit le cabinet et règle les cases menu par menu, dans les magasins et pour la société ; ce paragraphe prime.

### R24 — Une société validée par l'invitation de son cabinet ne voit ni l'étape du courrier ni celle de la démonstration (décision § 5.5 ; message de l'éditeur du 01/11/2026, 22 h 00)

- Constat de l'éditeur (capture du tableau de bord d'une société validée par l'invitation de son cabinet) : « Vos premières étapes » montre « Saisir le code du courrier recommandé » (« Validée le 01/11/2026. ») et « Essayer le magasin de démonstration » (« Plus disponible : votre société est validée. »). Or cette société n'a jamais reçu de courrier ni eu de démonstration : « ces étapes sont pour eux qui créent eux-mêmes leur compte chez PILIQI ».
- Règle : dans `TableauDeBord::etapes()` (`app/Filament/Societe/Pages/TableauDeBord.php`, la même méthode que R22), pour une société validée par l'invitation acceptée d'un cabinet (`CLAUDE.md`, « Cabinets d'expertise comptable », (a)), la liste ne montre que l'étape des magasins (R22 : « Créer votre premier magasin » puis « Ajouter un magasin ») et « Ajouter votre équipe ». La section « Vérification de la société » du tableau de bord et la page « Vérification » de l'espace de la société ne parlent ni de courrier, ni de code, ni de démonstration : « Votre société est validée depuis le JJ/MM/AAAA, par l'invitation de votre cabinet d'expertise comptable. » (sans le nom du cabinet, qui a pu changer depuis). Le lien « Saisir le code » et l'action d'ouverture de la démonstration n'y apparaissent pas.
- L'indicateur : la preuve de validation que l'acceptation de l'invitation enregistre déjà (preuve « invitation du cabinet » du registre des vérifications et du journal d'audit, celle qui fait signer les attestations à la main ; `AccepterLInvitationAction`) ; ne créer une colonne, dans la migration de création de la table concernée, que si aucune donnée enregistrée ne le dit avec certitude, et le dire dans « faits ».
- Rien ne change pour une société qui s'inscrit elle-même, avant comme après sa validation.
- Tests : société validée par une invitation (deux étapes seulement, textes de la vérification sans courrier ni démonstration, ni lien ni action correspondants) ; société inscrite elle-même avant sa validation (quatre étapes inchangées) et après (inchangée) ; la page « Vérification » des deux.

---

## 2. Points à trancher par l'éditeur

Chaque point est une règle métier que la décision du 01/11/2026 ne règle pas. Il est posé en français courant : un rappel du contexte, la question, deux ou trois options avec ce que chacune change pour la société, un exemple chiffré et une recommandation. Les exemples reprennent les mêmes personnes : la Boulangerie Bernard (propriétaire : Julie Bernard ; deux magasins à Lyon) et son expert-comptable, le Cabinet Petit (Marc Petit et Sophie Lenoir), qui n'a pas encore d'espace sur PILIQI. La section 1 applique l'option recommandée ; la section 8.10 donne le texte de `CLAUDE.md` et ce qui change dans le lot pour chaque autre option.

Rappel de la décision : une société peut déclarer son expert-comptable par le SIREN de son cabinet quand ce cabinet n'a pas d'espace sur PILIQI ; elle crée un compte pour chaque personne du cabinet, qui reçoit par e-mail un lien valable 7 jours pour choisir son mot de passe ; elle choisit ce que ces personnes peuvent voir ; le jour où le cabinet s'inscrit sur PILIQI et y est vérifié, il est relié tout seul à la société, avec les mêmes autorisations, et les comptes créés par la société se ferment.

### P1 — Le cabinet de l'expert-comptable est déjà sur PILIQI

- **Contexte.** La déclaration sert quand le cabinet n'a pas d'espace sur PILIQI ; mais une société peut taper le SIREN d'un cabinet qui y est déjà inscrit et vérifié.
- **Question.** Que se passe-t-il quand la société déclare le SIREN d'un cabinet déjà présent et vérifié sur PILIQI ?
- **Options.**
  - (a) PILIQI refuse la déclaration et propose un bouton « Lui demander le lien » : le cabinet reçoit la demande et l'accepte ou la refuse dans les 7 jours. Pour la société : aucun compte n'est créé ; son expert-comptable entre par l'espace de son cabinet dès qu'il a accepté, et elle règle ensuite ce qu'il voit.
  - (b) Le cabinet est relié tout de suite à la société, sans avoir à accepter, avec les autorisations cochées. Pour la société : accès immédiat ; mais le cabinet reçoit un client qu'il n'a pas choisi.
  - (c) PILIQI crée quand même les comptes des personnes et envoie une demande au cabinet ; s'il accepte, les comptes se ferment ; s'il refuse, ils restent. Pour la société : son expert-comptable entre tout de suite, mais par deux chemins à la fois pendant quelques jours.
- **Exemple.** Le 03/11/2026, la Boulangerie Bernard déclare le SIREN 000 000 935 de Compta Rhône, inscrit et vérifié sur PILIQI depuis le 01/10/2026. Avec (a), Compta Rhône accepte la demande le 05/11 et entre ce jour-là ; avec (b), il entre le 03/11 sans avoir rien demandé.
- **Recommandation : (a).** Un cabinet présent sur PILIQI garde le droit d'accepter ou non un client, comme aujourd'hui, et il n'y a qu'un seul chemin.
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P2 — Remplacer le cabinet relié par un autre expert-comptable

- **Contexte.** Une société n'a qu'un expert-comptable à la fois ; elle peut avoir un cabinet déjà relié par son espace PILIQI et vouloir déclarer à la place un autre cabinet, qui n'y est pas.
- **Question.** La société qui a déjà un cabinet relié peut-elle déclarer directement un autre expert-comptable ?
- **Options.**
  - (a) Non : elle met d'abord fin au lien avec son cabinet, puis déclare le nouveau. Pour la société : deux gestes, et un moment sans expert-comptable.
  - (b) Oui, en un seul geste, après avoir coché une case qui nomme le cabinet remplacé : son accès s'arrête aussitôt et il en est prévenu. Une demande de lien que la société avait envoyée à un autre cabinet et qui attend encore sa réponse est retirée en même temps, et la case le dit. Pour la société : un seul geste, et jamais deux expert-comptables à la fois.
- **Exemple.** La Boulangerie Bernard est reliée à Compta Rhône depuis le 01/09/2026. Le 03/11/2026, elle déclare le Cabinet Petit (SIREN 000 000 943) : avec (b), Julie coche « Je remplace COMPTA RHONE : son accès prend fin aussitôt », et Compta Rhône perd l'accès le jour même ; avec (a), elle met d'abord fin au lien, puis déclare.
- **Recommandation : (b).** C'est la règle d'aujourd'hui pour les cabinets reliés (« en lier un autre met fin au précédent »), étendue à la déclaration, et la décision dit « un seul cabinet à la fois ».
- **Décision de l'éditeur (01/11/2026) : (b)**, « tout comme recommandé ».

### P3 — Changer de cabinet déclaré

- **Contexte.** Une société a déclaré un cabinet absent de PILIQI, avec un compte pour chacune de ses personnes, et veut en déclarer un autre : elle change d'expert-comptable, ou elle a tapé un mauvais SIREN.
- **Question.** Comment change-t-elle de cabinet déclaré ?
- **Options.**
  - (a) Elle retire d'abord sa déclaration (les comptes des personnes se ferment), puis déclare le nouveau cabinet. Pour la société : deux gestes, et un moment sans expert-comptable.
  - (b) En un seul geste, après une case qui nomme les personnes dont le compte va se fermer. Pour la société : un seul geste ; les anciens comptes se ferment, les nouvelles personnes reçoivent leur lien.
  - (c) Comme (b), mais tant qu'aucune personne n'a choisi son mot de passe, le propriétaire peut simplement corriger le SIREN, sans fermer les comptes. Pour la société : une faute de frappe se répare sans recréer les comptes ; mais un SIREN déclaré peut alors changer après coup.
- **Exemple.** Le 10/11/2026, la Boulangerie Bernard remplace le Cabinet Petit (Marc Petit et Sophie Lenoir) par Audit Conseil Lyon (SIREN 000 000 927), avec Luc Moreau : avec (b), Julie coche « Je remplace CABINET PETIT ET ASSOCIES : les comptes de Marc Petit et Sophie Lenoir seront fermés », et Luc reçoit son lien le jour même.
- **Recommandation : (b).** Même règle qu'en P2 ; un SIREN mal tapé doit de toute façon être celui d'un cabinet actif pour passer.
- **Décision de l'éditeur (01/11/2026) : (b)**, « tout comme recommandé ».

### P4 — L'expert-comptable a oublié son mot de passe

- **Contexte.** Le compte que la société crée à une personne du cabinet ne s'ouvre jamais avec son identifiant comme mot de passe ; s'il oublie son mot de passe, seul le propriétaire de la société peut lui redonner l'accès, et l'expert-comptable travaille souvent loin des magasins.
- **Question.** Comment le propriétaire redonne-t-il l'accès à un expert-comptable qui n'est pas sur place ?
- **Options.**
  - (a) Le propriétaire lui envoie un nouveau lien, valable 7 jours, à son adresse e-mail ; l'ancien mot de passe ne sert plus dès l'envoi et ses connexions ouvertes se ferment. Si la personne est là, il peut aussi la laisser taper son nouveau mot de passe sur son écran. Pour la société : l'accès se redonne à distance, et un mot de passe peut-être volé cesse aussitôt de servir.
  - (b) Même lien, mais l'ancien mot de passe reste valable jusqu'à ce que le lien serve. Pour la société : aucune coupure si l'e-mail tarde ; mais un mot de passe volé reste utilisable jusqu'à 7 jours.
  - (c) Seulement en sa présence, sur l'écran du propriétaire. Pour la société : impossible à distance.
- **Exemple.** Le 15/12/2026 à 16:05, Marc Petit, à Villeurbanne, a oublié son mot de passe. Avec (a), Julie Bernard lui envoie un lien depuis Lyon, valable jusqu'au 22/12/2026 à 16:05, et Marc le lit le lendemain matin ; avec (c), Marc doit venir à Lyon.
- **Recommandation : (a).**
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P5 — Le propriétaire s'est trompé d'adresse e-mail

- **Contexte.** Le propriétaire tape lui-même l'adresse e-mail de chaque personne du cabinet (deux fois, pour éviter les fautes), et le lien du mot de passe part à cette adresse : une faute de frappe peut l'envoyer à quelqu'un d'autre, qui entrerait dans les magasins.
- **Question.** Le propriétaire peut-il corriger l'adresse qu'il a tapée ?
- **Options.**
  - (a) Oui, en redonnant son mot de passe, tant que la personne n'a pas encore choisi son mot de passe : l'ancien lien cesse aussitôt de valoir et un nouveau part à la bonne adresse ; ensuite, seule la personne change son adresse. Pour la société : une faute se répare en un geste, sans recréer le compte.
  - (b) Non : le propriétaire retire le compte et en crée un autre. Pour la société : un geste de plus, et un identifiant perdu.
  - (c) Oui, à tout moment. Pour la société : plus simple, mais le propriétaire pourrait détourner vers lui les liens d'une personne qui utilise déjà son compte.
- **Exemple.** Le 05/11/2026, Julie tape sophie.lenoit@petit-associes.test au lieu de sophie.lenoir@petit-associes.test ; le 06/11, Sophie n'ayant rien reçu, Julie corrige l'adresse : le lien du 05/11 ne vaut plus, un nouveau part, valable jusqu'au 13/11.
- **Recommandation : (a).**
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P6 — Le répertoire Sirene ne donne pas le code d'activité du cabinet

- **Contexte.** PILIQI n'accepte comme expert-comptable déclaré qu'une entreprise active dont le répertoire Sirene donne le code 69.20Z (activités comptables). Pour quelques entreprises, il ne donne aucun code, ou un code d'une ancienne nomenclature.
- **Question.** Que faire quand le répertoire ne donne aucun code utilisable ?
- **Options.**
  - (a) Refuser, avec un message qui propose d'inviter le cabinet à créer son espace sur PILIQI, où l'éditeur contrôle lui-même son activité. Pour la société : elle ne peut pas déclarer ce cabinet, mais peut l'inviter en un clic.
  - (b) Accepter la déclaration après un contrôle de l'éditeur. Pour la société : une attente de quelques jours, et un travail de plus pour l'éditeur.
  - (c) Accepter sans contrôle. Pour la société : immédiat ; mais n'importe quelle entreprise pourrait alors être déclarée comme expert-comptable.
- **Exemple.** Le SIREN 000 000 919 n'a aucun code au répertoire : avec (a), la Boulangerie Bernard lit le refus et invite le cabinet ; avec (b), elle attend la réponse de l'éditeur.
- **Recommandation : (a).** C'est la lecture littérale de « sinon PILIQI refuse ».
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P7 — Le lien avec le cabinet prend fin après le rattachement

- **Contexte.** Le jour où le cabinet déclaré s'inscrit et est vérifié sur PILIQI, il est relié tout seul à la société et les comptes créés par la société se ferment. Ce lien peut ensuite prendre fin sans que la société le veuille : le cabinet est radié de l'Ordre, ou l'éditeur le révoque, par exemple parce que quelqu'un s'était inscrit sous le SIREN d'un vrai cabinet.
- **Question.** Que devient alors l'expert-comptable de la société ?
- **Options.**
  - (a) Rien ne revit : la société n'a plus d'expert-comptable ; elle en déclare un de nouveau (nouveaux comptes, nouveaux identifiants) ou demande le lien à un cabinet présent sur PILIQI. Pour la société : quelques gestes à refaire, dans un cas rare ; elle est prévenue de la fin du lien.
  - (b) La déclaration revit d'elle-même, sans compte, chaque fois que le lien prend fin sans geste de la société ni du cabinet : le cabinet de ce SIREN qui s'inscrira de nouveau sera relié tout seul. Pour la société : pas de déclaration à refaire, mais personne n'entre en attendant.
  - (c) Comme (b), mais seulement quand l'éditeur révoque le cabinet parce qu'il avait pris le SIREN d'un autre : le vrai cabinet, quand il s'inscrira, sera relié tout seul.
- **Exemple.** Le 15/01/2027, l'éditeur révoque « Cabinet Petit », inscrit le 20/11/2026 par quelqu'un qui n'était pas Marc Petit ; le lien avec la Boulangerie Bernard prend fin. Avec (a), le 20/01/2027, Julie déclare de nouveau le SIREN 000 000 943 avec Marc, qui reçoit un nouveau compte et un nouvel identifiant ; avec (c), le vrai Marc inscrit son cabinet le 01/02/2027 et il est relié tout seul.
- **Recommandation : (a).** Le cas est rare, la société est prévenue, et la règle reste simple.
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P8 — Ce que le cabinet apprend des comptes fermés

- **Contexte.** Au rattachement, les comptes que la société avait créés pour les personnes du cabinet se ferment, et le propriétaire du cabinet doit ensuite confier la société à ses collaborateurs. Aujourd'hui, un cabinet relié ne voit le nom des comptes d'une société que dans le journal des événements de ses magasins, si la société lui en laisse la consultation.
- **Question.** L'avis « Nouveau client relié » envoyé au propriétaire du cabinet nomme-t-il les personnes dont le compte s'est fermé ?
- **Options.**
  - (a) Non : l'avis ne nomme personne ; chaque personne dont le compte se ferme reçoit un e-mail qui lui dit de demander l'accès au propriétaire de son cabinet. Pour la société : rien de plus n'est montré au cabinet.
  - (b) Oui : l'avis donne le nom de ces personnes. Pour la société : le cabinet apprend qui y travaillait déjà, ce qui l'aide à confier la société aux bonnes personnes.
- **Exemple.** Le 27/11/2026, le Cabinet Petit est relié à la Boulangerie Bernard ; Marc Petit et Sophie Lenoir y avaient un compte. Avec (b), l'avis dit « Comptes fermés : Marc Petit, Sophie Lenoir » ; avec (a), Sophie demande elle-même à Marc de lui confier la Boulangerie Bernard.
- **Recommandation : (a).** Rien de plus n'est lu par le cabinet, et l'e-mail de fermeture suffit à chaque personne.
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P9 — Le cabinet déclaré cesse son activité

- **Contexte.** PILIQI contrôle au répertoire Sirene, le jour de la déclaration, que le cabinet déclaré existe et est actif ; il peut fermer ensuite sans jamais s'inscrire sur PILIQI.
- **Question.** PILIQI revérifie-t-il ensuite le SIREN déclaré ?
- **Options.**
  - (a) Non : la déclaration et les comptes restent jusqu'à ce que le propriétaire les retire. Pour la société : elle reste seule maîtresse de l'accès.
  - (b) Oui, chaque mois : un cabinet fermé fait alerter le propriétaire, sans rien couper. Pour la société : elle est prévenue et décide.
  - (c) Oui, chaque mois : les comptes d'un cabinet fermé se ferment. Pour la société : l'accès se coupe sans elle.
- **Exemple.** Le Cabinet Petit, jamais inscrit sur PILIQI, est radié du répertoire le 01/02/2027. Avec (b), Julie reçoit une alerte au début de mars ; avec (c), les comptes de Marc et de Sophie se ferment au début de mars ; avec (a), rien ne change.
- **Recommandation : (a).** Rien de nouveau à planifier, et la société garde la main.
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P10 — L'expert-comptable de la société voit-il l'identité et les réglages fiscaux de la société ?

- **Contexte.** Un cabinet relié voit déjà l'identité légale, le type d'activité et les réglages fiscaux (régime de TVA, fin d'exercice) de ses clients. L'expert-comptable déclaré n'a pas d'espace de cabinet : il ne voit que ce que ses autorisations ouvrent dans les magasins.
- **Question.** Lui montre-t-on ces informations ?
- **Options.**
  - (a) Oui, sur une page « La société » en lecture seule, dans les magasins. Pour la société : son expert-comptable a les informations utiles à son travail, sans rien pouvoir changer.
  - (b) Non. Pour la société : il doit les demander.
- **Exemple.** Marc Petit veut connaître le régime de TVA et le mois de fin d'exercice (décembre) de la Boulangerie Bernard : avec (a), il les lit sur la page « La société ».
- **Recommandation : (a).**
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P11 — Toutes les personnes déclarées sont suspendues ou retirées

- **Contexte.** Le propriétaire peut suspendre ou retirer le compte de chaque personne déclarée sans retirer sa déclaration. Or, le jour où le cabinet s'inscrit et est vérifié sur PILIQI, il est relié tout seul à la société, et son propriétaire voit aussitôt les magasins.
- **Question.** Que devient une déclaration dont plus aucune personne n'a de compte actif ?
- **Options.**
  - (a) Elle attend : le cabinet n'est pas relié tant qu'aucune personne déclarée n'a de compte actif, et retirer la dernière personne met fin à la déclaration. Pour la société : suspendre tout le monde gèle aussi le rattachement ; dès qu'elle réactive une personne, le cabinet est relié s'il est vérifié.
  - (b) Le cabinet est relié quand même, dès qu'il est vérifié, et la page « Cabinet comptable » prévient que suspendre les personnes ne l'empêche pas. Pour la société : elle doit retirer sa déclaration pour empêcher le lien.
  - (c) Le cabinet n'est relié qu'après une confirmation du propriétaire de la société. Pour la société : un geste de plus, chaque fois.
- **Exemple.** Le 25/11/2026, après un désaccord, Julie suspend Marc et Sophie sans retirer sa déclaration ; le 27/11, le Cabinet Petit est vérifié sur PILIQI. Avec (a), rien ne se passe tant que Julie ne réactive pas l'un d'eux ; avec (b), Marc, propriétaire du cabinet, voit dès le 27/11 les factures et les clients des deux magasins.
- **Recommandation : (a).** Une déclaration vit avec ses personnes : elle naît avec la première et finit avec la dernière.
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P12 — Une société qui attend encore le code de son courrier

- **Contexte.** Une société qui s'inscrit elle-même n'est prouvée qu'en saisissant le code du courrier recommandé envoyé à son représentant légal ; avant, rien ne prouve que la personne inscrite la représente. Déclarer son expert-comptable fait partir un e-mail au nom de la société et peut relier tout seul un vrai cabinet à cette inscription.
- **Question.** Une société qui attend encore son code peut-elle déclarer son expert-comptable ?
- **Options.**
  - (a) Non : seule une société validée déclare ; avant, elle peut demander le lien à un cabinet déjà présent sur PILIQI (qui accepte ou non) ou inviter son expert-comptable à y créer son espace. Pour la société : elle déclare son expert-comptable le jour de sa validation ; avant, elle ne vend rien et n'a donc encore aucune facture à lui montrer.
  - (b) Oui, mais l'e-mail du lien et le rattachement attendent la validation. Pour la société : elle prépare la déclaration, mais rien ne part avant.
  - (c) Oui, comme une société validée. Pour la société : son expert-comptable entre tout de suite ; mais une personne qui se serait inscrite à la place du vrai dirigeant pourrait faire écrire PILIQI à n'importe quelle adresse et recevoir un vrai cabinet.
- **Exemple.** Le Garage Petitjean s'inscrit le 03/11/2026 et reçoit son courrier le 08/11 : avec (a), il déclare son expert-comptable le 08/11, juste après avoir saisi le code ; avec (c), il le déclare dès le 03/11.
- **Recommandation : (a).**
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

### P13 — L'expert-comptable change lui-même son adresse e-mail

- **Contexte.** Avec la réponse (a) ou (b) à P4, l'adresse e-mail de l'expert-comptable sert à lui renvoyer un lien quand il a oublié son mot de passe. Or, aujourd'hui, l'adresse d'un compte autre que le propriétaire se change dans le profil sans aucune vérification. La question ne se pose pas avec la réponse (c) à P4.
- **Question.** Quand l'expert-comptable change son adresse dans son profil, faut-il la vérifier ?
- **Options.**
  - (a) Oui, comme l'adresse de connexion du propriétaire : la nouvelle adresse ne vaut qu'après un clic sur un lien envoyé à cette adresse, valable 24 heures, et l'ancienne adresse est prévenue. Pour la société : ni une faute de frappe ni une personne qui aurait volé le mot de passe ne détournent en silence les liens de l'accès.
  - (b) Non, elle change tout de suite, mais l'ancienne adresse est prévenue. Pour la société : la personne est alertée, mais une faute de frappe fait perdre les liens suivants.
  - (c) Non, elle change tout de suite, sans prévenir personne, comme aujourd'hui pour tout compte autre que le propriétaire.
- **Exemple.** Le 02/12/2026 à 09:00, Marc remplace marc.petit@petit-associes.test par m.petit@petit-associes.test. Avec (a), la nouvelle adresse vaut quand il clique le lien reçu, avant le 03/12/2026 à 09:00, et l'ancienne reçoit « Votre adresse a été changée » ; jusque-là, un lien envoyé par Julie part encore à l'ancienne.
- **Recommandation : (a).**
- **Décision de l'éditeur (01/11/2026) : (a)**, « tout comme recommandé ».

---

## 3. Choix techniques de Claude, contestables par l'éditeur

- **C1 — L'angle.** La déclaration est une ligne de `liens_cabinet` en attente ; la même ligne devient le lien actif au rattachement ; ses autorisations ne se copient pas. Écartées : des tables séparées avec copie des autorisations au rattachement (deux modèles, deux lectures des droits, gardes croisées sur deux tables, suppression de la déclaration à sa fin) ; une déclaration terminée suivie d'un nouveau lien (copie, et deux lignes pour un seul accès).
- **C2 — `comptes_expert_comptable`.** Les comptes s'attachent à leur déclaration par une table de la société, qui porte aussi le lien du mot de passe et la date du premier mot de passe choisi : une clé étrangère de `users` vers `liens_cabinet` obligerait à modifier `users` dans une autre migration que la sienne. L'attachement garde pour toujours le cabinet déclaré de chaque compte (nom pour l'historique, réintégration).
- **C3 — Rattachement après le commit, un job par déclaration.** `RattacherLesDeclarationsDuSiren` ne fait que lire les déclarations en attente du SIREN et envoyer un job `RattacherUneDeclaration` par déclaration : unique par déclaration, 5 essais espacés, 45 secondes au plus, chacun dans sa propre transaction, rejouable sans effet, sans trace de visiteur ; la tâche horaire fait de même. Les avis et l'e-mail de fermeture, qui ne portent aucun jeton, partent après le commit chacun dans sa propre tâche en file, rejouée seule. Raisons : `RegistreFiscal::enTeteDeTransaction()` (`app/Domain/CaisseSecurisee/Services/RegistreFiscal.php` l.268-284) refuse de prendre les registres d'un client dans une transaction déjà ouverte ; l'ordre des verrous interdit de prendre un registre ou la ligne d'un client sous `cabinets FOR UPDATE` ; la relecture après chaque commit couvre l'écriture concurrente de la saisie du code et de la vérification de l'Ordre ; et un job unique pour tout un SIREN, qui enverrait lui-même ses e-mails, dépasserait les 60 secondes de `queue:listen` (`courriels/constat-queue-listen.md` : l'éditeur lance la file avec `--tries=1`, et le listener tombe à la coupure) : un rattachement déjà fait perdrait alors ses avis et ses e-mails, qu'un nouvel essai, sans effet, ne renverrait plus.
- **C4 — Déclencheurs explicites** (écouteur de `SocieteValidee`, actions de l'Ordre, correction du SIREN d'un cabinet et sa revérification, déclaration, réactivation et réintégration d'un compte d'expert-comptable), testés chacun, plutôt que des crochets Eloquent sur `Cabinet` et `Societe` : un effet de bord caché dans un modèle, que contourne une écriture par `DB::table` ; la tâche horaire couvre un chemin futur oublié.
- **C5 — Mot de passe du propriétaire redemandé** (`MotDePasseRedemande::exiger()`) pour déclarer, ajouter une personne et corriger une adresse, gestes qui ouvrent un accès ; pas pour retirer la déclaration, régler les autorisations, ni redonner l'accès.
- **C6 — Nom pour l'historique** : « Nom (expert-comptable déclaré par la société, cabinet Dénomination) », le SIREN à défaut (R11). « Expert-comptable du cabinet X » laisserait croire que le cabinet a reconnu cette personne, que PILIQI ne vérifie pas (C29) ; un compte d'un cabinet garde « Nom (cabinet X) ».
- **C7 — Libellés** : rôle « Expert-comptable de la société » ; après « Bonjour » : « Expert-comptable déclaré par la société · cabinet CABINET PETIT ET ASSOCIES · Lyon 1 » ; dans la liste de l'équipe : « Expert-comptable déclaré · CABINET PETIT ET ASSOCIES ».
- **C8 — E-mail de fermeture au rattachement seulement** ; aux autres fins, aucun e-mail, comme pour tout compte retiré.
- **C9 — Limites** : celle des créations de `CreationDesComptes` ; le débit des e-mails du propriétaire ; 10 contrôles Sirene de déclaration par heure et par société (`RateLimiter`, clé `declaration-expert-comptable:{societe}`) ; 5 liens par jour et par compte (`RateLimiter`, clé `lien-expert-comptable:{compte}`).
- **C10 — Page `AucunMagasin` rétablie** d'après `git show 9ce20c3:app/Filament/App/Pages/AucunMagasin.php`, route comprise (`AucunMagasin::registerRoutes()` dans `authenticatedRoutes()`, retirée par le lot EC), pour un expert-comptable d'une société sans magasin réel.
- **C11 — Aucune purge des liens expirés** : l'empreinte d'un jeton tiré au hasard n'est pas une donnée personnelle, et l'adresse vit sur le compte ; le jeton s'efface quand il sert, quand un autre le remplace, quand l'e-mail ne peut pas partir et quand le compte est suspendu ou retiré ; un lien expiré se lit « Lien expiré, effacé ou non parti : à renvoyer ».
- **C12 — États de la société** (P12) : déclaration permise dans une société validée, même suspendue ou résiliée ; refusée dans une société qui attend son code, close ou révoquée ; la base refuse une déclaration dans une société non validée.
- **C13 — Adresse obligatoire pour un compte non retiré de ce rôle** (garde en base) : elle reçoit les liens que le propriétaire envoie ; un compte retiré n'en a plus besoin, ce qui laisse son effacement possible à l'étape 9.
- **C14 — `acces_redonne_en_presence` (booléen) devient `acces_redonne_mode`** (`presence`, `absence`, `lien`, `ModeDAccesRedonne`) ; l'entrée `AccesRedonne` retient `mode` à la place d'`en_presence`. `RedonnerLAccesAction::handle()` garde son paramètre `bool $enPresence` (aucun de ses 53 appels ne change) et écrit `presence` ou `absence` ; une nouvelle `RedonnerLAccesAction::parUnNouveauLien()`, réservée à l'expert-comptable de la société, écrit `lien`.
- **C15 — Dénomination lue une fois**, à la déclaration ; jamais saisie ni relue ensuite (P9).
- **C16 — Une seule condition du cabinet vérifié** : `Societe::scopeCabinetsVerifies()`, dont se servent `RechercheDesCabinets::pour()`, le job et la tâche horaire ; la garde du rattachement (4.6) l'écrit en SQL ; un test prouve que la portée, la garde et `estUnCabinetVerifie()` disent la même chose.
- **C17 — Demandes de la paire retirées au rattachement**, par une méthode statique extraite de `RepondreAUneDemandeDeLien::cloreLaDemande()` (rien n'est dupliqué).
- **C18 — Auteur des retraits** : le propriétaire qui retire ou remplace la déclaration, retire sa dernière personne, désigne le cabinet déclaré ou accepte sa demande ; le système au rattachement par un job ; aucun auteur à la révocation (procédure de l'éditeur) ni quand un compte d'un cabinet accepte la désignation qui remplace la déclaration. Avec P12 (b) ou (c), la reprise d'une société par une autre personne retire les comptes au nom du nouveau propriétaire (`SaisirCodeDeConfirmationAction::reprendreLaSociete()`, l.444-449), avant que la fin de la déclaration ne trouve plus rien à retirer.
- **C19 — `AutorisationsDuCabinetModifiees` retient la liste des autorisations** : une déclaration peut n'avoir aucun compte ouvert, donc aucun droit à montrer.
- **C20 — Verrou partagé de la déclaration dans la garde des comptes**, sous PostgreSQL, sur le modèle de `GardesDesDroits::verrou()` (`app/Domain/Tenancy/Support/GardesDesDroits.php` l.215-223) : toute action qui rouvre un compte d'expert-comptable tient déjà sa déclaration ; une écriture fautive attend, puis voit l'état d'après, au pire est refusée par un interblocage détecté, jamais incohérente.
- **C21 — Prédicat commun** `User::entreParLesAutorisationsDUnCabinet()` (compte d'un cabinet ou expert-comptable de la société), lu par `ControleDesDroits` et, plus tard, par le lot S9-A.
- **C22 — Accès redonné à un expert-comptable** : en sa présence ou par un lien, jamais en son absence (l'identifiant pour mot de passe) ; le mode « lien » ne sert qu'à lui.
- **C23 — Origine visible** : la fiche d'un client dans l'espace du cabinet montre déjà l'origine du lien (`FicheDuClient`, docblock l.22) ; le libellé « Expert-comptable déclaré par le client » s'y lit.
- **C24 — Une entrée d'audit porte les droits des comptes d'une seule société** : le troisième élément d'une entrée de `modifierLeLien()` reste un identifiant de société, la société du client pour ses comptes d'expert-comptable, et devient nul pour une entrée sans droits ; aucune liste de sociétés.
- **C25 — La page du lien connecte la personne** après le choix de son mot de passe et lui montre son identifiant dans un avis persistant, comme la page du code du courrier (`CodeDuCourrierController`, l.82-96) ; une session d'un autre compte ouverte dans le même navigateur se ferme d'abord, sa fin inscrite (JET 40).
- **C26 — L'e-mail du lien part sans file d'attente**, le jeton en mémoire seulement, comme `EnvoiDesLiensDesCabinets` (`Mail::to()->send()`, `#[SensitiveParameter]`) : jamais écrit dans une file ni en base ; une messagerie muette échoue dans le délai SMTP réglé par le lot ML (`MAIL_TIMEOUT`), qui efface le lien et prévient le propriétaire.
- **C27 — Lecture des autorisations partagée** : `LienCabinet::autorisationsRetenues()` (autorisations du lien croisées avec `duCabinet()` du type du client), utilisée pour les comptes d'un cabinet et pour l'expert-comptable de la société.
- **C28 — Création seulement depuis « Cabinet comptable »** ; la liste de l'équipe montre ces comptes et mène à leur fiche.
- **C29 — Risques connus, acceptés par la décision.** (1) Choix 1 : le propriétaire saisit l'adresse ; il pourrait y mettre la sienne et agir sous le nom de la personne. Le journal d'audit garde la création par le propriétaire, et le propriétaire reçoit l'avis de la première connexion ; l'espace d'un cabinet n'a pas ce défaut. (2) Choix 3, risque inverse : une inscription de cabinet faite sous le SIREN d'un autre, validée par un courrier détourné, reçoit d'office les sociétés qui ont déclaré ce SIREN. Parades : l'avis au propriétaire de la société et l'e-mail de fermeture disent quoi faire si ce n'est pas le bon cabinet (6.7, 6.8) ; la société met fin au lien à tout moment ; l'éditeur révoque la société qui a pris le SIREN d'un autre (lot A7) ; la base ne rattache qu'à un cabinet entièrement vérifié (C35).
- **C30 — Adresse saisie deux fois** (règle `confirmed`) dans « Déclarer », « Ajouter une personne » et « Corriger l'adresse », et répétée en gras dans la fenêtre de confirmation : une faute de frappe donnerait à un tiers la lecture de tous les magasins.
- **C31 — Désigner le cabinet déclaré ou accepter sa demande de rattachement, c'est rattacher.** La même ligne devient le lien actif, au nom du propriétaire, avec les autorisations qu'il valide sur la page, préremplies avec celles de la déclaration (« par défaut » y veut dire « celles de votre déclaration ») ; les comptes se ferment et leurs e-mails partent. Ce n'est ni un remplacement (motif faux, autorisations perdues, personnes non prévenues) ni une question : la décision dit « avec les mêmes autorisations », et le propriétaire garde la main sur la page.
- **C32 — Déclarer retire les demandes de lien que la société a envoyées et qui attendent leur réponse** : « un seul cabinet à la fois » (choix 4), et le dernier choix du propriétaire l'emporte ; cela ne donne d'accès à personne, la case de confirmation le dit, et l'option (b) de P2 le montre à l'éditeur.
- **C33 — La déclaration d'une société suspendue ou résiliée se rattache**, en lecture seule, comme un lien que cette société garde : « un accès se donne et se coupe toujours » (docblock de `SocietePolicy::gererSonCabinet()`).
- **C34 — Un avis par client au propriétaire du cabinet**, comme chaque lien qui naît au lot EC ; aucun récapitulatif.
- **C35 — La base ne rattache qu'à un cabinet vérifié** (garde de `liens_cabinet`, 4.6) : c'est le seul geste qui donne les données d'un client sans un nouvel accord ; une écriture fautive (job, commande, `DB::table`) ne doit jamais livrer ces données à une inscription qui attend encore son code.
- **C36 — Date du premier mot de passe choisi** (`comptes_expert_comptable.mot_de_passe_choisi_le`), écrite dans la transaction même qui consomme le lien ou redonne l'accès en présence, et jamais effacée : la correction de l'adresse par le propriétaire se ferme dès ce moment, sans attendre la connexion, dont la date s'écrit dans une autre transaction (`SuiviDesConnexions::noterLaConnexion()`, l.37-43).
- **C37 — Suspendre efface le lien en cours** : une réactivation ne rouvre jamais un lien parti, peut-être, à une mauvaise adresse.
- **C38 — Libellés de la page publique tirés des clés enregistrées**, sans lire le type de la société, qu'aucune lecture hors de la société courante ne permet (« Type d'activité » de `CLAUDE.md`) : la garde des autorisations (4.6) garantit déjà que ces clés valent pour ce type.
- **C39 — Ajouter une personne est refusé quand le cabinet du SIREN déclaré est vérifié** (R4) : le compte se fermerait aussitôt, avec un lien inutile.
- **C40 — L'étape des magasins** : « Ajouter un magasin » dès le premier, sans ordinal (décision § 5.3, R22).

---

## 4. Modèle de données

Chaque table changée l'est dans sa seule migration de création, modifiée sur place ; la table nouvelle a une seule migration de création ; puis `php artisan migrate:fresh`. Jamais de migration `add_x_to_y`. `RetourArriereDesMigrationsTest` reste vert : chaque `down()` retire ses gardes avant sa table.

### 4.1 `liens_cabinet` (`database/migrations/2026_10_02_100023_create_liens_cabinet_table.php`)

| Élément | Changement |
|---|---|
| `cabinet_id` (l.31) | devient `->nullable()`, toujours `constrained('societes')->restrictOnDelete()`, commentaire : « Le cabinet ; nul pour une déclaration du client tant qu'aucun cabinet de son SIREN ne s'y est rattaché, et pour toujours si elle prend fin sans rattachement. » |
| `siren_cabinet` | nouvelle, `char(9)`, nulle, après `cabinet_id` : « Le SIREN que le client déclare (origine declaration_du_client seulement), contrôlé au répertoire Sirene ; il ne change jamais. » |
| `denomination_cabinet` | nouvelle, `string`, nulle : « La dénomination de ce SIREN lue au répertoire Sirene le jour de la déclaration, nulle quand il la masque : pour les écrans et le nom figé des comptes de l'expert-comptable de la société. » |
| `origine`, `etat` (l.32-35) | commentaires : `declaration_du_client` et `declare` ajoutés |
| `demande_par`, `demande_par_nom`, `demande_le` (l.36-41) | commentaire : pour une déclaration, le propriétaire qui l'a faite, son nom figé et sa date |
| `actif_depuis` (l.49) | commentaire : pour une déclaration, la date de son rattachement |
| `liens_cabinet_un_actif_par_client` (l.66) | remplacé par `CREATE UNIQUE INDEX liens_cabinet_un_cabinet_par_client ON liens_cabinet (societe_id) WHERE etat IN ('actif', 'declare')` |
| nouvel index | `CREATE INDEX liens_cabinet_declarations_par_siren ON liens_cabinet (siren_cabinet) WHERE etat = 'declare'` |
| inchangés | `liens_cabinet_une_demande_par_paire` (l.67), `unique(['societe_id', 'id'])`, `unique(['cabinet_id', 'id'])` : une valeur nulle n'entre en conflit avec aucune autre, sur les deux moteurs ; la clé étrangère de `clients_confies` vers `(cabinet_id, id)` (`database/migrations/2026_10_02_100025_create_clients_confies_table.php` l.38) ne peut jamais désigner une déclaration |
| docblock (l.10-21) | la table porte aussi la déclaration de l'expert-comptable d'une société validée, un lien en attente sans cabinet, qui devient le lien actif à son rattachement à un cabinet vérifié de son SIREN |

### 4.2 `autorisations_cabinet` (`database/migrations/2026_10_02_100024_create_autorisations_cabinet_table.php`)

Schéma inchangé. Docblock (l.8-19) : les autorisations qu'un client accorde à son cabinet lié ou, tant qu'elle attend son cabinet, à la déclaration de son expert-comptable, les mêmes lignes au rattachement ; la base refuse une ligne sur un lien ni actif ni déclaré.

### 4.3 `users` (`database/migrations/0001_01_01_000001_create_users_table.php`)

- Docblock (l.8-35) : rôles « propriétaire, administrateur, membre, expert-comptable de la société ou, dans un cabinet, collaborateur » ; « L'adresse de tout autre compte est facultative, renseignée par la personne seule dans son profil, sauf celle d'un expert-comptable de la société, que le propriétaire saisit en créant son compte et qui reçoit les liens de son mot de passe (voir DeclarerSonExpertComptableAction) » ; « un expert-comptable de la société n'a jamais son identifiant pour mot de passe : il choisit le sien par un lien de 7 jours (voir ChoisirLeMotDePasseDeLExpertComptableAction) ».
- `role_societe` (commentaire l.44-47) : « proprietaire, administrateur, membre, expert_comptable ou collaborateur (voir RoleSociete) ; collaborateur seulement dans une société de type cabinet, administrateur, membre et expert_comptable jamais (voir GardesDesDroits) ».
- `email` (commentaire l.63-72) : « … pour tout autre compte, une adresse de contact facultative, que plusieurs comptes peuvent partager, jamais écrite par qui crée le compte, sauf l'adresse professionnelle d'un expert-comptable de la société, obligatoire tant que son compte n'est pas retiré (voir GardesDesCabinets), que le propriétaire saisit en créant le compte et corrige tant que la personne n'a pas choisi son mot de passe, puis que la personne change par un lien de confirmation (voir nouvelle_adresse) ».
- `mot_de_passe_est_l_identifiant` (commentaire l.84-89) : « … et la base refuse un propriétaire ou un expert-comptable de la société dont il est vrai ».
- `acces_redonne_en_presence` (l.114) remplacée par `$table->string('acces_redonne_mode', 10)->nullable();` ; commentaire (l.109-111) : « quand, par qui, et comment (ModeDAccesRedonne) : en sa présence, en son absence, ou par un lien envoyé à l'adresse d'un expert-comptable de la société ».
- `jeton_acces_redonne` (commentaire l.119-121) : « Le jeton d'écran du dernier accès redonné en présence de la personne, ou par un lien envoyé à un expert-comptable de la société : une demande répétée avec le même jeton est sans effet, et n'envoie pas de second lien. »
- `nouvelle_adresse`, `nouvelle_adresse_jeton_hache`, `nouvelle_adresse_expire_le` (commentaire l.123-128) : servent aussi à la nouvelle adresse d'un expert-comptable de la société, demandée dans son profil (P13).

### 4.4 `identifiants` (`database/migrations/2026_10_02_100002_create_identifiants_table.php`)

Schéma inchangé. Docblock : le magasin de rattachement est « nul pour un compte né propriétaire, administrateur, collaborateur ou expert-comptable de la société » (l.15-17) ; l'empreinte de création est celle de la demande « (rôle, magasin, nom, autorisations et, pour un expert-comptable de la société, adresse et SIREN déclaré), hachée, sans aucun secret » (l.24-28).

### 4.5 Nouvelle table `comptes_expert_comptable`

Migration `database/migrations/2026_10_02_100026_create_comptes_expert_comptable_table.php` (numéro à vérifier libre après A7, A7b et ML : 100016 est pris par A7, 100019 réservé au plan TVA). Modèle `App\Domain\Cabinets\Models\CompteExpertComptable`, `BelongsToSociete`.

| Colonne | Type | Rôle |
|---|---|---|
| `id` | `id()` | |
| `societe_id` | `foreignId` `constrained('societes')->restrictOnDelete()` | la société qui déclare |
| `lien_id` | `foreignId` | sa déclaration, pour toujours |
| `user_id` | `foreignId`, `unique` | le compte d'expert-comptable de la société, attaché pour toujours |
| `jeton_hache` | `char(64)`, nulle, `unique` | SHA-256 du jeton du lien du mot de passe en cours, jamais le jeton ; effacée quand il sert, quand un autre le remplace, quand l'e-mail ne peut pas partir et quand le compte est suspendu ou retiré |
| `jeton_expire_le` | `timestamp`, nulle | son échéance, 7 jours plus tard à la même heure de Paris |
| `mot_de_passe_choisi_le` | `timestamp`, nulle | le premier mot de passe choisi par la personne (par un lien ou en présence) ; écrite une fois, jamais effacée : elle ferme la correction de l'adresse par le propriétaire (C36) |
| `created_at`, `updated_at` | `timestamps()` | |

- Clés étrangères composites : `(societe_id, lien_id)` vers `liens_cabinet (societe_id, id)` et `(societe_id, user_id)` vers `users (societe_id, id)`, `restrictOnDelete()`.
- Index `(societe_id, lien_id)`.
- `DeclencheursDeProtection::sansSuppression('comptes_expert_comptable')`.
- `up()` pose les gardes `COMPTES_EXPERT_COMPTABLE`, `EXPERT_COMPTABLE_DE_SA_DECLARATION` (sur `users`) et `DECLARATION_SANS_COMPTE_OUVERT` (sur `liens_cabinet`) : elles lisent cette table, la dernière créée (convention des gardes, docblock de `GardesDesDroits` l.71-75). `down()` les retire, puis la table, puis `DeclencheursDeProtection::retirer()`.
- Docblock : ce que la table garde, ses gardes, et la lecture par l'empreinte du jeton avant qu'aucune société ne soit ouverte.

### 4.6 Gardes en base

Conditions en SQL commun aux deux moteurs (PostgreSQL : une fonction plpgsql et un déclencheur BEFORE par garde ; SQLite : `RAISE(ABORT)` ; tout autre pilote fait échouer la migration). Une condition nulle ne refuse rien : chaque comparaison avec une colonne nulle est écrite avec `IS NULL` ou `COALESCE`. `{typeDe(...)}`, `{demandes}` et `{etatDuLien}` sont ceux de `GardesDesCabinets::refus()` (`app/Domain/Cabinets/Support/GardesDesCabinets.php` l.150-155). Le docblock de la classe (l.15-43) reçoit les nouvelles gardes et leurs tables, et dit que celles de l'expert-comptable de la société se posent avec `comptes_expert_comptable`, la dernière table qu'elles lisent.

**`GardesDesCabinets::LIENS`** (l.165-173), colonnes déclenchantes (l.84) : `societe_id, cabinet_id, origine, etat, jeton_hache, siren_cabinet`.

| Moment | Condition qui refuse | Message |
|---|---|---|
| toujours | `NEW.cabinet_id IS NOT NULL AND {typeDe('cabinet_id')} <> 'cabinet'` (remplace l.166, qui refusait un cabinet nul) | un lien ne se fait qu'avec un cabinet d'expertise comptable |
| toujours | l.167, l.168 et l.169, inchangées | (inchangés) |
| toujours | `(NEW.origine = 'declaration_du_client') <> (NEW.siren_cabinet IS NOT NULL)` | seule une déclaration du client porte le SIREN de son cabinet |
| toujours | `NEW.cabinet_id IS NULL AND NOT (NEW.origine = 'declaration_du_client' AND NEW.etat IN ('declare', 'termine'))` | seule une déclaration du client, en attente ou terminée sans rattachement, n'a pas de cabinet |
| toujours | `NEW.etat = 'declare' AND NEW.cabinet_id IS NOT NULL` | une déclaration en attente n'a pas encore de cabinet |
| insertion | `NEW.siren_cabinet IS NOT NULL AND NEW.siren_cabinet = COALESCE((SELECT siren FROM societes WHERE id = NEW.societe_id), '')` | une société ne déclare pas son propre SIREN |
| insertion | `NEW.origine = 'declaration_du_client' AND (SELECT validee_le FROM societes WHERE id = NEW.societe_id) IS NULL` (P12) | seule une société validée déclare son expert-comptable |
| insertion | `NEW.etat NOT IN ({demandes}) AND NOT (NEW.etat = 'actif' AND NEW.origine = 'invitation') AND NEW.etat <> 'declare'` (remplace l.170) | un lien naît d'une demande, d'une déclaration du client, ou actif par une invitation acceptée |
| insertion | `NEW.origine = 'declaration_du_client' AND NEW.etat <> 'declare'` | une déclaration du client naît en attente de son cabinet |
| modification | `NEW.societe_id <> OLD.societe_id OR NEW.origine <> OLD.origine OR (NEW.siren_cabinet IS NULL) <> (OLD.siren_cabinet IS NULL) OR NEW.siren_cabinet <> OLD.siren_cabinet` (remplace l.171) | le client, l'origine et le SIREN déclaré d'un lien ne changent jamais |
| modification | `(OLD.cabinet_id IS NOT NULL AND (NEW.cabinet_id IS NULL OR NEW.cabinet_id <> OLD.cabinet_id)) OR (OLD.cabinet_id IS NULL AND NEW.cabinet_id IS NOT NULL AND NOT (OLD.etat = 'declare' AND NEW.etat = 'actif'))` | le cabinet d'un lien ne change jamais ; celui d'une déclaration ne se pose qu'à son rattachement |
| modification | `OLD.etat = 'declare' AND NEW.etat = 'actif' AND COALESCE((SELECT siren FROM societes WHERE id = NEW.cabinet_id), '') <> NEW.siren_cabinet` | une déclaration ne se rattache qu'au cabinet de son SIREN |
| modification | `OLD.etat = 'declare' AND NEW.etat = 'actif' AND NOT EXISTS (SELECT 1 FROM societes INNER JOIN cabinets ON cabinets.societe_id = societes.id WHERE societes.id = NEW.cabinet_id AND societes.validee_le IS NOT NULL AND societes.statut = 'active' AND cabinets.activite_controlee_le IS NOT NULL AND cabinets.ordre_verifie_le IS NOT NULL AND cabinets.ordre_retire_le IS NULL)` (C35 ; mêmes conditions que `Societe::estUnCabinetVerifie()`, le type étant déjà contrôlé) | une déclaration ne se rattache qu'à un cabinet vérifié |
| modification | l.172, dont la liste des transitions permises reçoit `OR (OLD.etat = 'declare' AND NEW.etat IN ('actif', 'termine'))` | transition du lien non permise |

Les comparaisons au SIREN de la société et du cabinet ne valent qu'à l'insertion et au passage de `declare` à `actif` : une correction exceptionnelle du SIREN du client ou d'un cabinet (lots A7 et A7b) ne bloque jamais la fin d'une déclaration ni celle d'un lien.

**`GardesDesCabinets::AUTORISATIONS`** (l.174-178) : la troisième condition (l.177) devient `{etatDuLien} NOT IN ('actif', 'declare')`, message « une autorisation ne s'accorde que sur un lien actif ou une déclaration en attente ». Les deux premières (liste du cabinet en toutes lettres, articles refusés chez une société sans articles) valent pour la déclaration sans changement.

**Nouvelle garde `GardesDesCabinets::COMPTES_EXPERT_COMPTABLE`** = `comptes_expert_comptable_coherents`, table `comptes_expert_comptable`, colonnes déclenchantes `lien_id, user_id, jeton_hache, jeton_expire_le, mot_de_passe_choisi_le` :

| Moment | Condition qui refuse | Message |
|---|---|---|
| toujours | `COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), '') <> 'expert_comptable'` | seul un compte d'expert-comptable de la société s'attache à une déclaration |
| insertion | `COALESCE((SELECT etat FROM liens_cabinet WHERE id = NEW.lien_id), '') <> 'declare'` | un compte d'expert-comptable ne s'attache qu'à une déclaration en attente |
| modification | `NEW.lien_id <> OLD.lien_id OR NEW.user_id <> OLD.user_id` | un compte d'expert-comptable ne change jamais de déclaration |
| toujours | `(NEW.jeton_hache IS NULL) <> (NEW.jeton_expire_le IS NULL)` | un lien du mot de passe a toujours son échéance |
| toujours | `NEW.jeton_hache IS NOT NULL AND COALESCE((SELECT etat FROM liens_cabinet WHERE id = NEW.lien_id), '') <> 'declare'` | le lien du mot de passe ne vaut que tant que la déclaration est en attente |
| modification | `OLD.mot_de_passe_choisi_le IS NOT NULL AND (NEW.mot_de_passe_choisi_le IS NULL OR NEW.mot_de_passe_choisi_le <> OLD.mot_de_passe_choisi_le)` | la date du premier mot de passe choisi ne change plus |

**Nouvelle garde `GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION`** = `users_expert_comptable_de_sa_declaration`, table `users`, colonnes déclenchantes `role_societe, statut, email` :

| Moment | Condition qui refuse | Message |
|---|---|---|
| toujours | `NEW.role_societe = 'expert_comptable' AND NEW.statut <> 'retire' AND COALESCE((SELECT liens_cabinet.etat FROM comptes_expert_comptable INNER JOIN liens_cabinet ON liens_cabinet.id = comptes_expert_comptable.lien_id WHERE comptes_expert_comptable.user_id = NEW.id), 'declare') <> 'declare'` | un compte d'expert-comptable de la société n'est ouvert que tant que sa déclaration est en attente |
| toujours | `NEW.role_societe = 'expert_comptable' AND NEW.statut <> 'retire' AND NEW.email IS NULL` (C13) | un expert-comptable de la société a une adresse tant que son compte n'est pas retiré, où partent les liens de son mot de passe |
| modification | `(OLD.role_societe = 'expert_comptable') <> (NEW.role_societe = 'expert_comptable')` | le rôle d'expert-comptable de la société ne se prend ni ne se quitte |

À l'insertion, aucune ligne d'attachement n'existe encore : la première condition passe ; un compte d'expert-comptable sans attachement n'a de toute façon aucun droit (lectures fermées, 5.4) ; l'adresse s'écrit dès l'insertion (5.4, `CreationDesComptes::creer()`). Sous PostgreSQL, quand `NEW.role_societe = 'expert_comptable' AND NEW.statut <> 'retire'`, la fonction prend d'abord la déclaration du compte `FOR SHARE` (C20) : `GardesDesCabinets` reçoit pour cela une méthode `verrou()` par garde, sur le modèle de `GardesDesDroits::verrou()`.

**Nouvelle garde `GardesDesCabinets::DECLARATION_SANS_COMPTE_OUVERT`** = `liens_cabinet_declaration_sans_compte_ouvert`, table `liens_cabinet`, colonne déclenchante `etat` :

| Moment | Condition qui refuse | Message |
|---|---|---|
| toujours | `NEW.etat <> 'declare' AND EXISTS (SELECT 1 FROM comptes_expert_comptable INNER JOIN users ON users.id = comptes_expert_comptable.user_id WHERE comptes_expert_comptable.lien_id = NEW.id AND users.statut <> 'retire')` | les comptes de l'expert-comptable de la société se retirent avant que sa déclaration prenne fin ou se rattache |

« Toujours » plutôt que « modification » : sous SQLite, le déclencheur d'insertion d'une garde sans refus d'insertion aurait un corps vide, refusé par le moteur ; à l'insertion, aucun compte ne désigne encore la ligne. Sans verrou : la seule écriture qui fait cette transition, `modifierLeLien()` ou la modification qui retire la dernière personne, tient déjà ces comptes.

**`GardesDesDroits`** (`app/Domain/Tenancy/Support/GardesDesDroits.php` l.160-208) :
- `$sansAffectation` (l.164) vaut `[Proprietaire, Collaborateur, ExpertComptable]` : la garde des affectations (l.181) et celle des rôles (l.198) refusent une affectation ou une autorisation de société à un expert-comptable de la société ; messages : « le propriétaire, un collaborateur d'un cabinet et un expert-comptable de la société n'ont aucune affectation » et « … n'ont ni affectation ni autorisation de société » ;
- le refus de la l.200 reçoit `expert_comptable` : « un cabinet d'expertise comptable n'a ni administrateur, ni membre, ni expert-comptable de société » ;
- nouveau refus de la garde des rôles : `NEW.role_societe = 'expert_comptable' AND NEW.mot_de_passe_est_l_identifiant = TRUE` → « un expert-comptable de la société n'a jamais pour mot de passe son identifiant » ;
- `$horsDuMagasin` (l.171) ne change pas : un compte d'entraînement est un membre, que la garde de la déclaration empêche de prendre ce rôle, et un compte qui n'est pas membre ne se rattache jamais au magasin de démonstration (`app/Domain/Tenancy/Support/GardesDesIdentifiants.php` l.92 et l.95) ;
- docblock (l.11-76) mis à jour.

**`GardesDeLaVente::auteurQuiNeVendJamais()`** (`app/Domain/Facturation/Support/GardesDeLaVente.php` l.147-150) : `role_societe IN ('proprietaire', 'collaborateur', 'expert_comptable')` ; `MESSAGE` (l.53) : « Le propriétaire d'une société, l'expert-comptable de la société et les comptes d'un cabinet ne vendent jamais : aucune vente ni aucun encaissement ne s'inscrit à leur nom. » ; docblock (l.10-50). Les migrations des factures, des paiements et du registre ne changent pas : la garde se repose au `migrate:fresh`.

### 4.7 Carte des modèles polymorphes et journal d'audit consultable

- `AppServiceProvider::nommerLesModelesPolymorphes()` (`app/Providers/AppServiceProvider.php` l.201-223) reçoit `'compte_expert_comptable' => CompteExpertComptable::class`, cible de l'entrée d'audit d'une adresse changée.
- `AfficheLeJournalDAudit::OBJETS` (lot A7, `app/Filament/Concerns/AfficheLeJournalDAudit.php`, l.74-97 au commit 137334e) reçoit `CompteExpertComptable::class => 'Compte d\'expert-comptable de la société'`, et `LienCabinet::class` devient « Lien avec un cabinet ou déclaration de l'expert-comptable » ; sans eux, l'écran afficherait « Fiche (identifiant n) » (l.271).

---

## 5. Classes et actions

### 5.1 Ordre des verrous

Il prolonge celui du lot EC (`serie8/avenant-cabinets.md` § 3.5, et le code réel, qui prend la ligne du client « for no key update ») sans rien inverser :
1. registres des magasins réels du client, par identifiant croissant ;
2. ligne `societes` d'un cabinet, pour ses invitations seulement ;
3. invitations ;
4. ligne de la société cliente (« for no key update » ; en partage pour le changement d'adresse du profil, comme aujourd'hui) ;
5. lignes `liens_cabinet`, par identifiant croissant (la déclaration en est une) ;
6. lignes `cabinets` (`FOR SHARE`) ;
7. comptes, par identifiant croissant ;
7 bis. lignes `comptes_expert_comptable` des comptes déjà tenus ;
8. têtes du journal d'audit, celle du client avant celle d'un cabinet.

La ligne `cabinets FOR UPDATE` n'est prise que par `VerifierLOrdreAction`, les procédures de l'Ordre et la révocation d'un cabinet (lot A7), qui ne prennent ensuite aucun registre, aucune ligne de client ni aucun lien ; un rattachement ne part jamais dans leur transaction. Un client sans magasin réel n'a aucun registre : sa ligne est alors le premier verrou de chaque geste, et la déclaration, toujours prise avant les comptes, empêche tout cycle.

| Geste | Verrous, dans l'ordre |
|---|---|
| Déclarer (`DeclarerSonExpertComptableAction`) | hors transaction : lien en cours relu (R4), recherche du cabinet vérifié, répertoire Sirene ; puis 1, 4, 5 (lien en cours remplacé, demandes envoyées par la société et retirées), 6 (cabinets de ces lignes), 7 (auteur, propriétaires de ces cabinets, comptes qui ont accès par le lien en cours), 7 bis (comptes d'une déclaration remplacée), 8 |
| Ajouter une personne | hors transaction : recherche du cabinet vérifié du SIREN déclaré ; puis 1, 4, 5 (déclaration), 7 (auteur, comptes de la déclaration), 8 |
| Régler les autorisations, retirer la déclaration | ceux de `modifierLeLien()` aujourd'hui, la déclaration à la place du lien actif, plus 7 bis au retrait |
| Suspendre, réactiver, retirer, réintégrer un compte | 1 (magasins réels de la société) ; 4 puis 5 (la déclaration du compte), par `verrousAvantLesComptes` ; 7 (auteur, compte, et au retrait les autres comptes de la déclaration) ; 7 bis ; 8. Le retrait de la dernière personne non retirée termine la déclaration dans la même modification, sans autre verrou |
| Redonner l'accès (présence ou lien), corriger l'adresse | 7 (auteur, compte), par `JournalDesDroits::sousVerrouDesComptes()` (l.175-186) ; 7 bis ; 8 |
| Changer son adresse dans son profil, la confirmer (P13) | 4 en partage, puis le compte, dans l'ordre de `DemanderUneNouvelleAdresseAction` et de `ConfirmerNouvelleAdresseAction` |
| Choisir son mot de passe (page du lien) | 7 (compte) ; 7 bis |
| Rattacher (`RattacherUneDeclarationAction`) | 1, 4, 5 (déclaration et demandes en cours de la paire), 6 (cabinet du SIREN, `cabinetsQuiEntrent`), 7 (comptes de la déclaration, propriétaire du cabinet, auteur s'il y en a un), 7 bis, 8 (client, puis cabinet) |
| Désigner le cabinet déclaré, accepter sa demande | lecture préalable de la déclaration (une donnée de la société) ; puis le rattachement, avec l'auteur |
| Accepter la demande d'un autre cabinet (déclaration remplacée) | ceux de `modifierLeLien()` : 1, 4, 5 (demande et lien en cours), 6, 7 (comptes de la déclaration, propriétaire du cabinet qui entre, auteur), 7 bis, 8 |
| Révocation de la société (lot A7) | la transaction de la révocation (1, ligne de la société, comptes), puis la fin de la déclaration dans cette transaction (5, 7, 7 bis, 8), sans reprendre de registre |

### 5.2 Énumérations

- `app/Domain/Tenancy/Enums/RoleSociete.php` : `case ExpertComptable = 'expert_comptable';` (16 caractères, dans le `string(20)` de `role_societe`), libellé « Expert-comptable de la société » ; docblock réécrit.
- `app/Domain/Cabinets/Enums/EtatDuLien.php` : `case Declare = 'declare';`, libellé « Déclaré, en attente de son cabinet » ; `enCours(): list<self>` (`Actif`, `Declare`) et `estEnCours(): bool` ; docblock.
- `app/Domain/Cabinets/Enums/OrigineDuLien.php` : `case DeclarationDuClient = 'declaration_du_client';`, libellé « Expert-comptable déclaré par le client ».
- `app/Domain/Cabinets/Enums/MotifDeFinDuLien.php` : libellé de `Remplace` : « Le client a choisi un autre expert-comptable » ; docblock (une déclaration prend fin par ces motifs, `societe_revoquee` d'A7 compris).
- Nouvelle `app/Domain/Tenancy/Enums/ModeDAccesRedonne.php` : `Presence` (`presence`), `Absence` (`absence`), `Lien` (`lien`) ; `phraseAuCompte()` (« en votre présence. », « en votre absence. », « par un lien envoyé à votre adresse. ») et `phraseAuProprietaire()` (« en sa présence », « en son absence », « par un lien envoyé à son adresse »).
- `app/Domain/Tenancy/Enums/ActionAuditee.php` (cas, `categorie()` l.164-180, `champsAudites()` l.189-306), quatre cas de catégorie `Acces` :
  - `ExpertComptableDeclare` (`expert_comptable_declare`, « Expert-comptable déclaré par le SIREN de son cabinet »), champs `['cabinet', 'autorisations', 'droits']` ;
  - `ExpertComptableCree` (`expert_comptable_cree`, « Compte d'expert-comptable de la société créé »), champs `['cabinet', 'droits']` ;
  - `ExpertComptableRattache` (`expert_comptable_rattache`, « Expert-comptable rattaché à son cabinet : comptes de la société fermés »), champs `['cabinet', 'droits']` ;
  - `AdresseDExpertComptableChangee` (`adresse_d_expert_comptable_changee`, « Adresse d'un expert-comptable de la société changée »), champs `['compte']` : la correction par le propriétaire (P5) comme le changement confirmé par la personne (P13), sans aucune adresse, comme `AdresseDeConnexionChangee` (l.282).
  - En plus : `AutorisationsDuCabinetModifiees` (l.274) retient `['cabinet', 'autorisations', 'droits']` ; `AccesRedonne` (l.283) retient `['compte', 'mode']`. Aucune adresse, aucun identifiant, aucun jeton (`CHAMPS_INTERDITS`, l.89).

### 5.3 Modèles

- **`LienCabinet`** (`app/Domain/Cabinets/Models/LienCabinet.php`) : `@property int|null $cabinet_id`, `$siren_cabinet`, `$denomination_cabinet` ; `estDeclare()`, `estEnCours()` ; `scopeEnCours()` ; `scopeDeclaresDuSiren(string $siren)` (lecture hors société : `withoutGlobalScope(SocieteScope::class)`, `siren_cabinet = ?`, `etat = 'declare'`, colonnes `id` et `societe_id`) ; `autorisationsRetenues(): list<string>` (C27 ; `autorisations()` l.192-200 croisées avec `duCabinet()` du type du client) ; `accorder()` (l.212-222) inchangée ; docblock.
- **`CompteExpertComptable`** (nouveau, `BelongsToSociete`) : `DUREE_DU_LIEN_EN_JOURS = 7` ; `empreinte(#[SensitiveParameter] string $jeton): string` (SHA-256) ; `parSonJeton(#[SensitiveParameter] string $jeton): ?self` (lecture hors société : `withoutGlobalScopes()`, `jeton_hache = ?`, colonnes `id, societe_id, lien_id, user_id, jeton_expire_le`) ; `lienValable(): bool` ; `nouveauLien(): string` (écrit l'empreinte et l'échéance d'un jeton `Str::random(64)`, rend le jeton, que l'appelant ne passe jamais à un journal ni à un job) ; `oublierLeLien(): void` ; `aChoisiSonMotDePasse(): bool` ; `$hidden = ['jeton_hache']` ; casts `Instant` pour `jeton_expire_le` et `mot_de_passe_choisi_le`.
- **`Societe`** (`app/Domain/Tenancy/Models/Societe.php`) : `scopeCabinetsVerifies(Builder)` (C16 : type cabinet, `validee_le` non nul, statut actif, ligne `cabinets` avec activité contrôlée, Ordre vérifié, sans radiation) ; `estUnCabinetVerifie()` (l.329-338) inchangée.
- **`User`** (`app/Models/User.php`) :
  - `estExpertComptable()` ; `entreParLesAutorisationsDUnCabinet()` = `estDUnCabinet() || estExpertComptable()` (C21) ;
  - `libelleDuRoleDeSociete()` (l.355-365) et `libelleDuRoleDans()` (l.378-387) : C7 ;
  - `nomPourLHistorique()` (l.399-413) : R11, la dénomination et le SIREN lus par `ExpertsComptablesDeclares::declarationDe($this)` ; la règle de raccourcissement factorisée pour les deux formats ; `nomFigeDe()` (l.419-422) inchangée ;
  - `droitsDans()` (l.692-714) : pour ce rôle, `role_magasin` nul et une clé `cabinet_declare` (`siren`, `raison_sociale`) ;
  - `autorisationsEnregistreesDans()` (l.779-798) : une branche `ExpertsComptablesDeclares::autorisationsEnregistrees()` ;
  - `peutOuvrir()` (l.857-872) : actif, magasin réel de sa société, déclaration en attente ;
  - `magasinsAccessibles()` (l.884-903) : les magasins réels de sa société, par nom, tant que sa déclaration est en attente ; aucun sinon ;
  - `peutAdministrer()` (l.843-846), `ouvreLEspaceDuCabinet()` (l.992) et `canAccessPanel()` (l.1014-1022) inchangées : faux pour lui, le panneau « app » seulement ;
  - cast `acces_redonne_mode` → `ModeDAccesRedonne`, à la place d'`acces_redonne_en_presence` (l.145) ; docblock (l.37-83).

### 5.4 Services

- **Nouveau `app/Domain/Cabinets/Services/ExpertsComptablesDeclares.php`**, sans état ; chaque lecture filtre explicitement par la société du compte ou de la déclaration :
  - `declarationDe(User $compte): ?LienCabinet` : la déclaration à laquelle le compte est attaché, sans scope, filtrée par `societe_id` du compte et `comptes_expert_comptable.user_id`, lisible hors contexte (connexion, `canAccessTenant()`, nom figé), quel que soit son état ;
  - `comptesDe(LienCabinet $declaration, bool $avecLesSuspendus = true): Collection` : ses comptes non retirés, par identifiant croissant ;
  - `aUnCompteActif(LienCabinet $declaration): bool` (P11) ;
  - `autorisationsEnregistrees(User $compte, Magasin $magasin): ?array` : nul pour la démonstration, un magasin d'une autre société, un compte retiré ou une déclaration qui n'est pas en attente ; sinon `autorisationsRetenues()` de la déclaration ;
  - `verrouillerLaDeclarationDe(User $compte): void` : la ligne de sa société « for no key update », puis sa déclaration `FOR UPDATE` (pour `verrousAvantLesComptes`) ;
  - `retirerLesComptes(LienCabinet $declaration, ?User $auteur): Collection` : dans la modification en cours, pour chaque compte non retiré tenu, `ChangerStatutCompteAction::retirerLesDroits()`, `nouveauStatut(Retire, $auteur)`, `effacerToutesSesNotifications()`, `SessionsDesComptes::fermer()` ; rend les comptes retirés ;
  - `cabinetVerifieDuSiren(string $siren): ?Societe` : `Societe::query()->cabinetsVerifies()->where('siren', $siren)->first(['id', 'siren', 'type_activite', 'validee_le', 'statut'])` ;
  - `sirensAvecUnCabinetVerifie(): Collection` : les SIREN distincts des déclarations en attente dont le cabinet est vérifié, SIREN seulement.
- **`AccesDesCabinets`** (`app/Domain/Cabinets/Services/AccesDesCabinets.php`) : `comptesQuiOntAcces()` (l.166-181) rend, pour une ligne sans cabinet, `ExpertsComptablesDeclares::comptesDe()` ; `effacerLesClientsConfies()` (l.188-191) ne fait rien pour elle ; `autorisationsEnregistrees()` (l.135-157) se sert de `autorisationsRetenues()` ; `liensDuCompte()` (l.44-62), `liensOuverts()`, `liensActifsDuCabinet()` et `ouvre()` restent filtrées par `cabinet_id` : une déclaration n'y entre jamais.
- **`JournalDesDroits`** (`app/Domain/Tenancy/Services/JournalDesDroits.php`) :
  - `modifierLeLien()` (l.226-289) : le lien verrouillé avec ceux donnés est le lien en cours (l.241 : `EtatDuLien::enCours()`) ; les cabinets (l.244) ignorent les `cabinet_id` nuls ; `liensApres` (l.260-263) relit les liens en cours ; les comptes avant et après comprennent ceux d'une déclaration (par `comptesQuiOntAcces()`) ;
  - `inscrireAuxJournauxDuLien()` (l.480-517) : les journaux (l.482-483) ignorent les `cabinet_id` nuls ; le troisième élément d'une entrée devient `?int` (C24 : nul, aucun droit) ;
  - `magasinsTouches()` (l.351-377) : un expert-comptable de la société touche les magasins réels de sa société, jamais la démonstration ;
  - `inscrireLesDroitsImplicites()` (l.320-339) : le lien lu (l.333) est le lien en cours ; ses comptes, du cabinet ou de l'expert-comptable de la société, reçoivent leurs droits dans un nouveau magasin réel ;
  - docblock (l.30-70) : la phrase « Une seule modification se fait sans auteur … » (l.62-64), déjà inexacte, devient la liste des modifications du système : retrait des comptes d'entraînement, reprise de la société à la saisie du code, fin d'un lien ou d'une déclaration par le système ou l'éditeur, rattachement d'une déclaration par un job.
- **`ControleDesDroits`** (`app/Domain/Authorization/ControleDesDroits.php`) : l.156 envoie tout compte qui `entreParLesAutorisationsDUnCabinet()` dans `pourUnCompteDeCabinet()` (l.205-248), renommée `pourUnCompteAuxAutorisationsDUnCabinet()` ; pour un expert-comptable de la société, toute autorisation de société et toute question dans l'espace d'une société rendent faux ; la branche du magasin est celle des comptes de cabinet (refus net hors des autorisations, liste blanche) ; docblock.
- **`PorteeDesDroits`** (`app/Domain/Tenancy/Services/PorteeDesDroits.php`) : `peutChangerLeRoleDeSociete()` (l.352-359) rend faux pour un expert-comptable ; nouvelles `peutReintegrerLExpertComptable()` (propriétaire, compte retiré de ce rôle, déclaration en attente) et `peutCorrigerLAdresseDeLExpertComptable()` (propriétaire, compte actif de ce rôle, `mot_de_passe_choisi_le` nul sur sa ligne, relue sous le verrou du compte puis de sa ligne) ; `peutSuspendreOuReactiver()` (l.202-213), `peutRetirerDeLaSociete()` (l.224-236), `peutRedonnerLAcces()` (l.250-272) et `voitLIdentifiant()` (l.300-307) réservent déjà ces comptes au propriétaire (aucune affectation de membre) ; docblock.
- **`CreationDesComptes`** (`app/Domain/Tenancy/Services/CreationDesComptes.php`) : nouvelle `validerLAdresse(mixed $email, mixed $confirmation): string` (requise, confirmée, au format e-mail, 255 caractères au plus, normalisée) ; `creer()` (l.157-170) reçoit `?string $adresseDuLien = null` : donnée, l'adresse s'écrit dans le `forceFill` de l'insertion (l.159-164), le compte garde le mot de passe tiré au hasard (l.159), et la ligne l.167 ne s'exécute pas (`mot_de_passe_est_l_identifiant` reste faux) ; refusée pour tout autre rôle (`LogicException`) ; `valider()` (l.64-69) inchangée ; docblocks de la classe (l.20-45, avec l'exception du choix 1) et d'`empreinte()` (l.71-76 : rôle, magasin, nom, autorisations et, pour l'expert-comptable de la société, adresse et SIREN déclaré, hachés ensemble).
- **Nouveau `app/Domain/Cabinets/Services/EnvoiDesLiensDesExpertsComptables.php`** :
  - `envoyer(int $compteId, #[SensitiveParameter] string $jeton, int $auteurId, bool $accesRedonne): void`, appelé après le commit, sans file (C26) ; relit le compte (actif, ce rôle), sa ligne d'attachement (`jeton_hache` égal à l'empreinte, échéance future), la déclaration (en attente), la société (ni close ni révoquée) et l'auteur (encore propriétaire) ; sinon pas d'e-mail et `AvisDeLEquipe` au propriétaire actuel ; une exception de la messagerie efface le jeton (transaction courte sous le verrou de la ligne) et prévient le propriétaire ;
  - `envoyerLaFermeture(int $compteId, string $client, string $cabinet): void`, après un rattachement : met en file `CompteDExpertComptableFermeMail` (5.7) à l'adresse du compte lue après le commit.
- **`PermissionRegistry`** (`app/Domain/Authorization/PermissionRegistry.php`) : nouvelle `libellesDe(array $cles): array`, les libellés de ces clés dans l'ordre du registre, avec celui de « Produits : créer » de `libellesDuCabinet()` (l.558-560), pour la page du lien et l'e-mail (C38) ; `libellesDuCabinet()` (l.554-563) s'en sert.
- **`RechercheDesCabinets::pour()`** (l.40-48) : se sert de `Societe::scopeCabinetsVerifies()`.
- **`PostesReconnus::ouvreLeCompte()`** (`app/Domain/Tenancy/Services/PostesReconnus.php` l.105-123) : faux aussi pour un expert-comptable de la société (l.107-109) ; docblock (l.96-104).
- **`SuiviDesConnexions::accesRedonne()`** (`app/Domain/Tenancy/Services/SuiviDesConnexions.php` l.85-90) : la phrase du mode (`phraseAuCompte()`).
- **`IdentiteDansLeJournal`** (`app/Domain/Cabinets/Support/IdentiteDansLeJournal.php`) : `declaree(LienCabinet $declaration): array{id: null, siren: string, raison_sociale: string|null}`.
- **`DestinatairesDesAlertes`** (`app/Domain/Tenancy/Services/DestinatairesDesAlertes.php`) : docblock (l.13-26), « ni un expert-comptable de la société » ; le code ne change pas.

### 5.5 Actions nouvelles (`app/Domain/Cabinets/Actions/`)

**`DeclarerSonExpertComptableAction::handle(User $auteur, Societe $societe, array $donnees, #[SensitiveParameter] string $motDePasse, ?string $jeton = null): User`** (`$donnees` : `siren`, `name`, `email`, `email_confirmation`, `autorisations`, `remplacer`)
1. Validation : SIREN (`SirenValide`), différent de celui de la société ; nom par `CreationDesComptes::valider()`, adresse par `validerLAdresse()` ; autorisations par `PermissionRegistry::verifierDuCabinet()` du type (erreur de champ).
2. `MotDePasseRedemande::exiger($auteur, $motDePasse)` ; `JournalDesDroits::exigerDansSaSociete($auteur, 'gererSonCabinet', $societe)` ; société validée, sinon le texte de R2 (P12) ; `DebitDesEmails::verifier($auteur)`.
3. Hors transaction, le lien en cours relu (R4) : même SIREN déclaré, ou SIREN du cabinet lié → refus R4.
4. R5 : `RechercheDesCabinets::pour($siren)` ; un cabinet vérifié → refus.
5. Hors transaction : la limite des contrôles, puis R3 (répertoire, 69.20Z) ; dénomination lue.
6. `JournalDesDroits::modifierLeLien($auteur, $societe, [...demandes envoyées en cours], modification, entrées)`. Dans la modification :
   - contrôle de l'auteur relu et de la société validée ; empreinte de création `['role_societe' => 'expert_comptable', 'name' => …, 'email' => adresse normalisée, 'siren_cabinet' => …, 'autorisations' => …, 'remplacer' => …]`, hachée par `CreationDesComptes::empreinte()` (l.77-82), jamais en clair ; `CreationDesComptes::compteDejaCree()` (l.92-111) : une demande répétée rend le compte déjà créé, sans rien écrire ni envoyer ; le même jeton avec une autre adresse est refusé (`MESSAGE_DEMANDE_DEJA_SERVIE`) ;
   - lien en cours relu : même SIREN déclaré ou même cabinet lié → refus R4 ; un autre → refus sans `remplacer` (R6), sinon `MettreFinAuLienAction::terminerSousVerrou($enCours, $auteurRelu, MotifDeFinDuLien::Remplace)` ;
   - chaque demande envoyée par la société et encore en cours, lue avant la transaction et relue sous les verrous avec le lien en cours, est retirée (`RepondreAUneDemandeDeLien::cloreSousVerrou($demande, EtatDuLien::Retire, $auteurRelu)`, C32) ; une demande envoyée entre cette lecture et les verrous fait recommencer le geste (« Les liens de votre société ont changé : recommencez. »), sans rien écrire ;
   - insertion de la déclaration (`origine`, `etat`, `siren_cabinet`, `denomination_cabinet`, `demande_par`, `demande_le`) ; `accorder($autorisations)` ;
   - `verifierLaLimiteDuJour()` ; `creer(…, RoleSociete::ExpertComptable, null, $jeton, $empreinte, $email)` ; ligne `comptes_expert_comptable`, puis `nouveauLien()`.
7. Entrées : au journal du client, `LienTermine` du lien remplacé s'il y en a (identité du cabinet ou identité déclarée, motif `remplace` ; troisième élément : le cabinet remplacé, ou nul pour une déclaration remplacée), `DemandeDeLienRetiree` par demande retirée, puis `ExpertComptableDeclare` (identité déclarée, liste des autorisations ; troisième élément : la société, ce qui porte les droits du compte créé et des comptes retirés) ; au journal de chaque cabinet concerné, `LienTermine` ou `DemandeDeLienRetiree`, sans rien de la déclaration.
8. Après le commit : `EnvoiDesLiensDesExpertsComptables::envoyer()` ; `DebitDesEmails::compter($auteur)` ; avis au cabinet remplacé (`MettreFinAuLienAction::prevenir()`, raison « votre client a choisi un autre expert-comptable ») et à chaque cabinet dont la demande est retirée (même raison) ; `RattacherLesDeclarationsDuSiren::dispatch($siren)`.
9. Doublon du jeton de création : rend le compte déjà créé, comme `CreerCollaborateurAction` (`CreationDesComptes::estUnJetonDejaServi()`).

**`AjouterUnExpertComptableAction::handle(User $auteur, LienCabinet $declaration, array $personne, #[SensitiveParameter] string $motDePasse, ?string $jeton = null): User`** : mot de passe redemandé ; policy `LienCabinetPolicy::ajouterUnExpertComptable()` ; hors transaction, `RechercheDesCabinets::pour($declaration->siren_cabinet)` : un cabinet vérifié → refus de R4 ; `modifierLeLien($auteur, $client, [$declaration], …)` : déclaration relue en attente, sinon « Votre expert-comptable n'est plus déclaré : son cabinet est peut-être désormais lié à votre société. » ; empreinte `['role_societe' => 'expert_comptable', 'name' => …, 'email' => …, 'lien' => uuid]`, hachée ; `compteDejaCree()` (le même jeton avec une autre adresse est refusé) ; limite du jour ; création (adresse à l'insertion), attachement, `nouveauLien()` ; entrée `ExpertComptableCree` au journal du client ; après le commit, l'envoi et le débit.

**`CorrigerLAdresseDeLExpertComptableAction::handle(User $auteur, User $compte, string $adresse, string $confirmation, #[SensitiveParameter] string $motDePasse): void`** (P5) : adresse validée (deux fois) ; mot de passe redemandé ; `sousVerrouDesComptes($auteur, [$compte], …)`, puis sa ligne `comptes_expert_comptable` `FOR UPDATE` ; `peutCorrigerLAdresseDeLExpertComptable()` sur les lignes relues, sinon le message de R12 ; même adresse : rien ; sinon l'adresse s'écrit et `nouveauLien()` remplace le lien ; entrée `AdresseDExpertComptableChangee` (`compte` : identifiant technique et nom) ; après le commit, l'envoi et le débit.

**`ChoisirLeMotDePasseDeLExpertComptableAction::handle(#[SensitiveParameter] string $jeton, #[SensitiveParameter] string $motDePasse, #[SensitiveParameter] string $confirmation): User`** : `CompteExpertComptable::parSonJeton()` absent ou expiré → `LienDeLExpertComptableInvalideException` (nouvelle, `app/Domain/Cabinets/Exceptions/`, message unique « Ce lien n'est plus valable. Demandez au propriétaire de la société qui vous a créé ce compte de vous en envoyer un nouveau. ») ; validation (confirmation, `RegleDuMotDePasse::exiger($motDePasse, identifiant)`) ; dans le contexte de la société, une transaction : compte `FOR UPDATE`, puis sa ligne `FOR UPDATE` ; relecture (même empreinte, échéance future, compte actif, déclaration en attente, société ni close ni révoquée), sinon l'exception ; mot de passe écrit, `mot_de_passe_est_l_identifiant` faux, jeton et échéance effacés, `mot_de_passe_choisi_le` écrit s'il était nul ; `SessionsDesComptes::fermer()`. Aucune entrée d'audit, comme `ChoisirSonMotDePasseParLeLienAction` ; la première connexion s'inscrit ensuite.

**`RattacherUneDeclarationAction::handle(int $declarationId, int $cabinetId, ?User $auteur = null, ?array $autorisations = null): bool`** :
1. Lit la déclaration (`withoutGlobalScope(SocieteScope::class)`, colonnes `id, societe_id, etat, siren_cabinet`) ; rien si elle n'est plus en attente.
2. Lit les demandes en cours de la paire (société, cabinet), filtrées par `societe_id`, `cabinet_id` et état.
3. `modifierLeLien($auteur, $client, [$declaration, …$demandes], modification, entrées, [$cabinetId])`, sous `TraceDuVisiteur::horsDeSaSociete()` (`app/Domain/Tenancy/Services/TraceDuVisiteur.php` l.46-55) quand il n'y a pas d'auteur ; avec un auteur (désignation, acceptation), contrôlé dans sa société (`exigerDansSaSociete($auteur, 'gererSonCabinet', $client)`), ses entrées portent sa trace. Dans la modification :
   - déclaration relue en attente et de ce SIREN ; cabinet relu `estUnCabinetVerifie()` (sa ligne `cabinets` est déjà tenue en partage) ; au moins un compte de la déclaration actif (P11, `aUnCompteActif()`) ; sinon rien ;
   - autorisations données : vérifiées par `verifierDuCabinet()` du type du client et écrites sur la déclaration à la place des siennes ;
   - `retirerLesComptes($declaration, $auteurRelu)` ;
   - chaque demande encore en cours, fermée par `RepondreAUneDemandeDeLien::cloreSousVerrou($demande, EtatDuLien::Retire, $auteurRelu)` ;
   - la déclaration reçoit `cabinet_id`, `etat` actif et `actif_depuis`.
4. Entrées : au journal du client, `DemandeDeLienRetiree` par demande (sans droits), `ExpertComptableRattache` (cabinet ; droits des comptes retirés, troisième élément : la société) et `LienActive` (cabinet, client, origine `declaration_du_client`, autorisations `declarees` ou `ajustees` ; droits des comptes du cabinet) ; au journal du cabinet, les mêmes `DemandeDeLienRetiree` et `LienActive`, avec les droits de ses seuls comptes.
5. Après le commit : les avis de 6.8 (en file, sauf à l'auteur) et l'e-mail de fermeture de 6.7 (en file, un par compte retiré) ; rend vrai si le rattachement a eu lieu.

### 5.6 Actions existantes modifiées

- **`MettreFinAuLienAction`** (`app/Domain/Cabinets/Actions/MettreFinAuLienAction.php`) :
  - `terminer()` (l.176-204) termine un lien en cours (l.187 : `estEnCours()`) ; pour une déclaration, l'entrée `LienTermine` porte l'identité déclarée et les droits des comptes de la société (troisième élément : la société) ;
  - `terminerSousVerrou()` (l.115-125) retire d'abord les comptes d'une déclaration (`retirerLesComptes()`, avec l'auteur seulement s'il est de la société du client, C18) ;
  - `pourUnClientRevoque()` (lot A7 ; l.88-101 au commit 137334e) lit aussi la déclaration en attente (`whereIn('etat', [...EtatDuLien::enCours(), ...EtatDuLien::demandes()])`) et aiguille par `$lien->estEnCours()` à la place d'`estActif()` (l.97) : un lien actif ou une déclaration passe par `parLEditeur()`, donc par `terminer()`, qui retire d'abord les comptes de la déclaration ; seule une demande passe par `RetirerLaDemandeDeLienAction::parLeSysteme()` (l.42-52), dont `cloreLaDemande()` lirait le cabinet nul d'une déclaration (`RepondreAUneDemandeDeLien.php` l.59) et que la garde refuserait (transition `declare` → `retire`) ;
  - `pourUneSocieteQuiChangeDeMains()` (l.96-107, l.124-135 au commit 137334e) ne change pas avec P12 (a) : aucune déclaration n'existe dans une société non validée (garde de 4.6) ; avec P12 (b) ou (c), elle reçoit le même changement que `pourUnClientRevoque()` (la l.105 envoie sinon la déclaration au retrait des demandes, et la clôture comme la reprise échouent) ;
  - `prevenir()` (l.135-171) ne fait rien pour une déclaration (aucun cabinet ; personnes non prévenues, C8) ;
  - `handle()` (l.58-65) : le motif « par le client » d'une déclaration vient déjà de la comparaison avec `cabinet_id`, nul.
- **`AccepterLaDemandeDeLienAction`** (`app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php`) : avant tout, la déclaration en attente de la société est lue (une donnée à elle) ; si la demande vient du cabinet dont le SIREN est celui de la déclaration, l'action délègue à `RattacherUneDeclarationAction::handle($declarationId, $cabinetId, $auteurRelu, $autorisations ?? les autorisations de la déclaration)` (C31) ; sinon, `$remplace` (l.85) est le lien en cours, et ses entrées (l.100-120) et `prevenir()` (l.135-166) traitent une déclaration remplacée (identité déclarée, droits des comptes de la société au seul journal du client ; l'avis au propriétaire du client dit que son expert-comptable déclaré est remplacé et ses comptes fermés).
- **`DesignerUnCabinetAction`** (`app/Domain/Cabinets/Actions/DesignerUnCabinetAction.php` l.69-147) : sous le verrou du client (l.81), la déclaration en attente est verrouillée avec les liens de la paire (l.82) ; si son SIREN est celui du cabinet désigné, la transaction valide sans rien créer et l'action appelle `RattacherUneDeclarationAction::handle(…, $auteurRelu)`, comme elle délègue l'acceptation (l.128-130) ; ce contrôle passe avant celui d'une demande du cabinet.
- **`ReglerLesAutorisationsDuCabinetAction`** (l.44-73) : un lien en cours (l.52 : `estEnCours()`) ; entrée avec la liste (l.57-59, C19) ; avis au cabinet (l.61-72) pour un lien actif seulement ; pour une déclaration, avis dans la cloche de chaque compte ouvert (`AvisDeLEquipe`, base seulement).
- **`RepondreAUneDemandeDeLien`** (`app/Domain/Cabinets/Actions/RepondreAUneDemandeDeLien.php`) : nouvelle méthode statique `cloreSousVerrou(LienCabinet $relu, EtatDuLien $etat, ?User $auteur): void` (état, `jeton_hache` nul, `repondu_par`, `repondu_le`), dont `cloreLaDemande()` (l.56-89) se sert désormais.
- **`VerifierLOrdreAction::handle()`** (`app/Domain/Cabinets/Actions/VerifierLOrdreAction.php` l.75-115) : après `ecrire()` réussie avec une inscription trouvée (l.100-111), `RattacherLesDeclarationsDuSiren::dispatch((string) $cabinet->siren)`.
- **`ConstaterLInscriptionALOrdreAction::handle()`** (`app/Domain/Cabinets/Actions/ConstaterLInscriptionALOrdreAction.php` l.46-95) : après la transaction (l.56-85), le même envoi.
- **Lot A7b** : la correction du SIREN d'un cabinet (`CorrigerSirenAction`) et chaque geste qui le revérifie ensuite sous son nouveau SIREN (noms réels sur le code de départ, section 11) envoient le même job pour le SIREN en vigueur, après leur commit.
- **`ChangerStatutCompteAction`** (`app/Domain/Tenancy/Actions/ChangerStatutCompteAction.php`) : `changer()` (l.239-248) passe `verrousAvantLesComptes: fn () => $experts->verrouillerLaDeclarationDe($compte)` pour un expert-comptable ; `suspendre()` (l.70-85) efface aussi le lien de sa ligne (`oublierLeLien()`, 7 bis) ; `retirer()` (l.108-120), quand le compte retiré était la dernière personne non retirée de sa déclaration, termine celle-ci dans la même modification (`MettreFinAuLienAction::terminerSousVerrou($declaration, $auteurRelu, MotifDeFinDuLien::ParLeClient)` et entrée `LienTermine` au journal du client) ; `reactiver()` (l.90-103) et la nouvelle `reintegrerLExpertComptable(User $auteur, User $compte)`, sur le modèle de `reintegrerLeCollaborateur()` (l.201-219), avec ce verrou et `peutReintegrerLExpertComptable()`, envoient après le commit `RattacherUneDeclaration` pour la déclaration du compte (P11) ; `retirerLesDroits()` (l.227-232) efface aussi le jeton de la ligne `comptes_expert_comptable` du compte.
- **`RedonnerLAccesAction`** (`app/Domain/Tenancy/Actions/RedonnerLAccesAction.php`) :
  - `handle()` (l.79) garde `bool $enPresence` : aucun appel ne change ; il écrit `acces_redonne_mode` (`presence` ou `absence`) à la place d'`acces_redonne_en_presence` (l.117) ; le mode « absence » est refusé pour un collaborateur (l.104-106, inchangé) et pour un expert-comptable de la société (« Un expert-comptable de la société reçoit un nouveau lien à son adresse, ou choisit son mot de passe en votre présence : son identifiant n'ouvre jamais son compte. ») ; en présence, pour un expert-comptable, le lien en cours s'efface et `mot_de_passe_choisi_le` s'écrit s'il était nul (7 bis) ; l'entrée `AccesRedonne` (l.123-126) retient `mode` ;
  - nouvelle `parUnNouveauLien(User $auteur, User $compte, string $jeton): bool`, pour un expert-comptable de la société seulement : sous `sousVerrouDesComptes()` puis sa ligne, `peutRedonnerLAcces()` ; même jeton d'écran (`jeton_acces_redonne`) : sans effet, rend faux, aucun second e-mail ; limite C9 ; mot de passe remplacé par un aléatoire inconnu (P4), `acces_redonne_le`, `acces_redonne_par`, `acces_redonne_mode = 'lien'`, `jeton_acces_redonne`, `nouveauLien()`, sessions fermées ; entrée `AccesRedonne` (`mode` : `lien`) ; après le commit, l'envoi (`accesRedonne: true`) et le débit ;
  - l'avis au propriétaire (l.138-147) prend `phraseAuProprietaire()` ; docblock (l.20-50).
- **`MembreResource::accesRedonne()`** (`app/Filament/Societe/Resources/MembreResource.php` l.202-211) : la phrase du mode.
- **`FicheDuMembre::changerLeRoleAction()`** (`app/Filament/Societe/Resources/MembreResource/Pages/FicheDuMembre.php` l.185-189) : le bras qui refuse devient `RoleSociete::Proprietaire, RoleSociete::Collaborateur, RoleSociete::ExpertComptable => throw new AuthorizationException(PorteeDesDroits::REFUS)` (sans lui, une requête forgée finirait en `UnhandledMatchError`, erreur 500).
- **`Affectation`** (`app/Domain/Tenancy/Models/Affectation.php` l.64-66) : la liste refusée reprend celle de `$sansAffectation` (propriétaire, collaborateur, expert-comptable de la société), message comme la garde.
- **Changement d'adresse du profil (P13)** : `DemanderUneNouvelleAdresseAction` (`app/Domain/Tenancy/Actions/DemanderUneNouvelleAdresseAction.php` : refus l.65 et l.91) et `ConfirmerNouvelleAdresseAction` (`app/Domain/Tenancy/Actions/ConfirmerNouvelleAdresseAction.php` : `estEnAttente()` l.206-211) servent aussi l'expert-comptable de la société. Pour lui : aucune vérification d'unicité parmi les propriétaires (l.159), aucune date de confirmation (`email_verified_at`), aucun jeton « Mot de passe oublié » à effacer (l.169-171) ; le lien se suit sans être connecté, comme celui d'un propriétaire qui a déjà une adresse ; l'ancienne adresse reçoit `AdresseChangeeMail` ; entrée `AdresseDExpertComptableChangee` à la confirmation ; un compte qui n'est plus actif ou n'est plus de ce rôle ne confirme rien ; docblocks (l.17-46 et l.21-55).
- **`ChangerLeRoleDeSocieteAction`**, **`SaisirCodeDeConfirmationAction::reprendreLaSociete()`** (l.413-457) et **`CloreLInscriptionAction::cloreSousVerrou()`** (l.227-243) : aucun changement de code ; la policy et les gardes refusent le changement de rôle ; avec P12 (a), la reprise et la clôture ne rencontrent jamais de déclaration.
- **Révocation d'une société (lot A7)** : voir `pourUnClientRevoque()` ci-dessus ; la révocation d'un cabinet (`RevoquerSocieteAction::revoquerUnCabinet()`, l.235-266 au commit 137334e) met fin aux liens nés d'une déclaration comme aux autres, dans son second temps.

### 5.7 Jobs, notifications en file, écouteur, commande

- **`app/Domain/Cabinets/Jobs/RattacherLesDeclarationsDuSiren.php`** (`ShouldQueue`, `afterCommit()` dans le constructeur, sur le modèle de `RendreCaduquesLesInvitationsDuSiren`, l.32-41) : `handle()` lit `cabinetVerifieDuSiren()` ; rien s'il n'y en a pas ; sinon, pour chaque déclaration de `LienCabinet::declaresDuSiren()`, par identifiant croissant, envoie `RattacherUneDeclaration` ; il n'écrit rien lui-même et finit en quelques millisecondes.
- **`app/Domain/Cabinets/Jobs/RattacherUneDeclaration.php`** (nouveau ; `ShouldQueue`, `ShouldBeUnique` avec `uniqueId()` = l'identifiant de la déclaration, `afterCommit()`, `$tries = 5`, `$backoff = [60, 300, 900]`, `$timeout = 45`, sur le modèle d'`EnvoyerDocumentJob`, `app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php` l.90-110) : `handle()` appelle `RattacherUneDeclarationAction::handle($declarationId, $cabinetId)` au nom du système ; l'échec d'une société n'arrête aucune autre ; 45 secondes restent sous les 60 de `queue:listen` et sous le `retry_after` de 90 secondes de la connexion `database` (`config/queue.php` l.43).
- **Avis et e-mails du rattachement, chacun rejoué seul** : `app/Domain/Cabinets/Notifications/AvisDuCabinetEnFile.php` (nouvelle, `extends AvisDuCabinet implements ShouldQueue`, `afterCommit()`, `$tries = 5`) : Laravel met en file un job par destinataire et par canal (cloche, e-mail), si bien qu'une messagerie en panne ne double jamais l'avis de la cloche ; `AvisDuCabinet` (`app/Domain/Cabinets/Notifications/AvisDuCabinet.php` l.24, l.48-51) reste synchrone pour tout le reste. `CompteDExpertComptableFermeMail` implémente `ShouldQueue` (`afterCommit()`, `$tries = 5`) et part par `Mail::to()->queue()`.
- **`app/Domain/Cabinets/Listeners/RattacherLesDeclarationsDuCabinetValide.php`** : écoute `SocieteValidee` (`ShouldDispatchAfterCommit`, `app/Domain/Tenancy/Events/SocieteValidee.php` l.16-21), enregistré dans `EventServiceProvider::boot()` (`app/Providers/EventServiceProvider.php` l.35-48) ; pour un cabinet, il envoie `RattacherLesDeclarationsDuSiren`.
- **`app/Console/Commands/RattacherLesExpertsComptables.php`** (`app:rattacher-experts-comptables`), `hourlyAt(40)` dans `bootstrap/app.php`, après les tâches des cabinets (l.128-129, l.132-133 au commit 137334e), avec son commentaire : pour chaque SIREN de `sirensAvecUnCabinetVerifie()`, `RattacherLesDeclarationsDuSiren::dispatch()`.

### 5.8 Contrôleur, routes, pages, vues, e-mails

- **`app/Http/Controllers/LienDeLExpertComptableController.php`** : `afficher(#[SensitiveParameter] string $jeton)` (GET, n'écrit rien ; page neutre 410 pour un lien invalide) ; `choisir(Request $requete, #[SensitiveParameter] string $jeton)` (POST) appelle l'action ; si une session est ouverte pour un autre compte, il la ferme d'abord par `Auth::logout()` (fin de session inscrite, JET 40, par `JournaliserFinDeSession`, `app/Providers/EventServiceProvider.php` l.46-47 : `Auth::login()` ne déclenche pas `Logout` pour le compte d'avant) et invalide la session ; puis `Auth::login()`, régénère la session, `SuiviDesConnexions::noterLaConnexion()`, envoie l'avis persistant « Votre identifiant de connexion est {identifiant} : notez-le. Vous vous connecterez avec lui et votre mot de passe ; votre adresse ne sert pas à vous connecter. » et redirige vers l'accueil du panneau « app » ; chaque réponse porte `Referrer-Policy: no-referrer`.
- **`routes/web.php`**, à côté de l.64-87 : `GET /expert-comptable/{jeton}` (`experts-comptables.lien.afficher`, `throttle:30,1`) et `POST /expert-comptable/{jeton}` (`experts-comptables.lien.choisir`, `throttle:10,1`), `where('jeton', '[A-Za-z0-9]{64}')`.
- Vues : `resources/views/cabinets/lien-expert-comptable.blade.php` et `lien-expert-comptable-invalide.blade.php`.
- E-mails (section 6.7) : `app/Domain/Cabinets/Mail/LienDuMotDePasseDeLExpertComptableMail.php` et `CompteDExpertComptableFermeMail.php`, vues `resources/views/mail/lien-du-mot-de-passe-de-l-expert-comptable.blade.php` et `compte-d-expert-comptable-ferme.blade.php`.
- **`app/Filament/App/Pages/AucunMagasin.php`** et sa vue `resources/views/filament/app/pages/aucun-magasin.blade.php`, rétablies d'après 9ce20c3, message « Votre société n'a encore aucun magasin réel : vous consulterez chacun d'eux dès que son propriétaire l'aura créé. » ; **`app/Providers/Filament/AppPanelProvider.php`** : `AucunMagasin::registerRoutes()` dans `authenticatedRoutes()` (l.84-86), comme à 9ce20c3 (la page, une `SimplePage`, ne s'enregistre que par cette route), et docblock du panneau (l.37-65) ; **`MenerUnCompteSansMagasin::handle()`** (`app/Http/Middleware/MenerUnCompteSansMagasin.php` l.28-47) envoie un expert-comptable de la société actif et sans magasin accessible vers `route('filament.app.aucun-magasin')`, avant l'`abort_unless()` de la l.44 ; docblock (l.13-25).
- **`app/Filament/App/Pages/LaSociete.php`** et sa vue (P10) : `canAccess()` pour un expert-comptable de la société qui ouvre le magasin courant ; lecture seule de `Societe::CHAMPS_DE_L_IDENTITE` (l.90), du type d'activité et des réglages fiscaux communs.
- **README**, paragraphe des cabinets (l.74) : en local, une société déclare son expert-comptable avec `FauxRepertoireSirene::SIREN_CABINET` (000000943) ; tout autre SIREN fictif est en 47.11B (`app/Integrations/Sirene/FauxRepertoireSirene.php` l.99) et sera refusé.

### 5.9 Policies

- **`LienCabinetPolicy`** (`app/Policies/LienCabinetPolicy.php`) : `reglerLesAutorisations()` (l.68-71) et `mettreFin()` (l.78-81) permettent au propriétaire du client une déclaration en attente (`estEnCours()`, et l'état `declare` dans la liste de `mettreFin()`) ; nouvelle `ajouterUnExpertComptable()` (propriétaire du client, déclaration en attente).
- **`MembrePolicy`** (`app/Policies/MembrePolicy.php`) : nouvelles `reintegrerLExpertComptable()` et `corrigerLAdresse()`, par `PorteeDesDroits` ; les autres gestes y passent déjà.
- **`SocietePolicy::gererSonCabinet()`** : inchangée ; docblock (déclarer, dans une société validée seulement).

### 5.10 Jetons des demandes répétées

| Geste | Protection |
|---|---|
| Déclarer, ajouter une personne | jeton d'écran (`identifiants.jeton_de_creation`) et empreinte de la demande, adresse comprise, hachée ; une demande répétée rend le compte déjà créé, sans rien écrire ni envoyer ; le même jeton avec une autre demande (une autre adresse, par exemple) est refusé (« Cette demande a déjà servi. ») ; l'index unique du jeton arrête une seconde demande passée quand même |
| Redonner l'accès (présence ou lien) | jeton d'écran (`users.jeton_acces_redonne`) ; même jeton : sans effet, aucun second e-mail |
| Corriger l'adresse | état (même adresse : rien ; mot de passe déjà choisi : refus) |
| Changer son adresse dans son profil (P13) | état (une nouvelle demande remplace la précédente, dont le lien ne sert plus, comme pour le propriétaire) |
| Choisir son mot de passe | jeton à usage unique, consommé sous le verrou du compte et de sa ligne |
| Régler les autorisations, retirer la déclaration, statut d'un compte | état (rien ne change, ou `MESSAGE_DEJA_DANS_CE_STATUT`) |
| Rattacher | état (une déclaration rattachée ne change plus) et unicité du job par déclaration |

### 5.11 Lectures hors de la société courante

Chacune passe par une méthode nommée, retire explicitement le scope, filtre, ne lit que les colonnes citées et a son test `DB::listen` ; toutes figurent dans « Produit » (section 8.3).

| | Lecture | Colonnes |
|---|---|---|
| L1 | À la déclaration et à l'ajout d'une personne, `RechercheDesCabinets::pour($siren)` (déjà permise au propriétaire) ; à la déclaration, le cabinet du lien en cours, déjà permis (son identité) | `id, uuid, siren, raison_sociale, siege_code_postal, siege_ville` |
| L2 | Quand un cabinet devient vérifié, et chaque heure : `cabinetVerifieDuSiren()`, `LienCabinet::declaresDuSiren()`, `sirensAvecUnCabinetVerifie()`, puis, dans `RattacherUneDeclarationAction`, la déclaration et les demandes de la paire avant d'ouvrir le contexte du client | cabinet : `id, siren, type_activite, validee_le, statut` ; déclarations : `id, societe_id` ; demandes : `id, societe_id, cabinet_id, etat` ; SIREN seuls |
| L3 | Avant qu'aucune société ne soit ouverte, la page du lien : `CompteExpertComptable::parSonJeton()`, puis le compte, sa société, sa déclaration et ses autorisations ; après le choix seulement, l'identifiant ; jamais le type de la société (C38) ni le nom de qui a déclaré | ligne : `id, societe_id, lien_id, user_id, jeton_expire_le` ; compte : `id, societe_id, name, role_societe, statut` ; société : `id, raison_sociale, siren, close_le, statut` ; déclaration : `id, etat, siren_cabinet, denomination_cabinet` ; autorisations : leurs clés |
| L4 | À la connexion, à l'ouverture d'un magasin et pour le nom figé du compte, hors contexte : `ExpertsComptablesDeclares::declarationDe()` et ses autorisations, dans la société du compte | attachement : `lien_id` ; déclaration : `id, societe_id, etat, siren_cabinet, denomination_cabinet` ; autorisations |

---

## 6. Écrans, e-mails et avis

### 6.1 Espace d'administration — page « Cabinet comptable » (`app/Filament/Societe/Pages/CabinetComptable.php`)

- `lienActif()` (l.123-126) devient `lienEnCours()` ; `remplir()` (l.94-103), le formulaire (l.128-148), `enregistrerLesAutorisationsAction()` (l.150-164) et `mettreFinAction()` (l.166-183) le lisent et servent la déclaration comme le lien actif : deux groupes, « Consulter » et, pour un commerce, « Achats et stock », et le texte `TEXTE_JAMAIS` (l.61).
- Vue `resources/views/filament/societe/pages/cabinet-comptable.blade.php` : la section « Votre cabinet » (l.12-29) ne s'affiche que pour un lien actif (`$lien->estActif()`) ; pour une déclaration (`$lien->estDeclare()`), la section « Votre expert-comptable (sans espace PILIQI) » la remplace. `cabinetDu()` (l.240-246) et `personnesQuiOntAcces()` (l.254-264) ne reçoivent jamais une ligne sans cabinet (`LogicException` sinon) : leur `firstOrFail()` sur un `cabinet_id` nul ferait répondre la page entière 404.
- `mettreFinAction()`, pour une déclaration : libellé « Retirer votre expert-comptable », confirmation « Les comptes de {noms} sont retirés aussitôt ; leur historique reste. »
- Section **« Votre expert-comptable (sans espace PILIQI) »**, quand une déclaration attend :
  - en-tête « {dénomination ou SIREN} — SIREN {siren} — déclaré le {date} par {nom figé} — en attente de l'espace de son cabinet » et le texte « Dès que ce cabinet sera inscrit et vérifié sur PILIQI, il sera relié à votre société avec ces autorisations, et ces comptes seront fermés, tant qu'au moins une de ces personnes a un compte actif. » ;
  - tableau des personnes : nom, identifiant, adresse, statut, état du lien (« Lien valable jusqu'au {date} à {heure} » ; « Lien expiré, effacé ou non parti : à renvoyer » ; « Mot de passe choisi le {date} »), lien « Fiche » ;
  - le formulaire des autorisations, « Enregistrer » ;
  - actions « Ajouter une personne de ce cabinet » (nom, adresse professionnelle et sa confirmation, votre mot de passe, jeton d'écran) et « Retirer votre expert-comptable ».
- Formulaire **« Déclarer votre expert-comptable »**, dans une société validée, quand rien n'est déclaré, ou pour remplacer ; dans une société qui attend son code, le texte de R2 à la place (P12) :
  - texte d'entrée : « Votre expert-comptable n'a pas encore d'espace PILIQI ? Déclarez-le par le SIREN de son cabinet : PILIQI crée un compte pour chaque personne que vous nommez, qui choisit son mot de passe par un lien valable 7 jours envoyé à son adresse professionnelle. Le jour où son cabinet ouvre son espace et est vérifié, il est relié à votre société avec les mêmes autorisations, et ces comptes se ferment. » ;
  - champs « SIREN de son cabinet », « Nom de la personne », « Son adresse e-mail professionnelle » et « Confirmez cette adresse » (aide : « Le lien part à cette adresse. La personne qui le suit entre dans vos magasins avec les autorisations ci-dessous. »), les deux groupes précochés, « Votre mot de passe », et, s'il y a un lien en cours ou une demande envoyée, la case « Je remplace {dénomination} : son accès prend fin aussitôt » ou « … : les comptes de {noms} seront fermés », complétée de « et je retire ma demande à {cabinet} » ; jeton d'écran tiré à l'ouverture ; la fenêtre de confirmation répète l'adresse en gras ;
  - refus R5 avec le bouton « Lui demander le lien » ;
  - succès : « {nom} est déclaré : un lien valable jusqu'au {date} est parti à {adresse du compte}. Son identifiant : {identifiant} ; la personne le découvrira en choisissant son mot de passe. »
- `demanderLeLienAction()` (l.200-214) : la fenêtre dit qu'une acceptation remplacera l'expert-comptable déclaré ; `inviterSonExpertAction()` (l.216-231) ne change pas.
- Docblock (l.34-56).

### 6.2 Tableau de bord de la société (`app/Filament/Societe/Pages/TableauDeBord.php`)

- `cabinetComptable()` (l.177-190) lit le lien en cours : « Votre expert-comptable : {dénomination}, déclaré, en attente de son espace (2 personnes) », ou le cabinet lié comme aujourd'hui.
- `etapes()` (l.119-166) : R22 (titre et lien de l'étape des magasins, l.139-148 ; comptage de l'équipe sans les comptes d'expert-comptable, l.129) ; docblock (l.102-118).

### 6.3 Page de rattachement (`app/Filament/Societe/Pages/RattachementDUnCabinet.php`)

- `cabinetActuel()` (l.100-105) lit le lien en cours. Pour la demande d'un autre cabinet alors qu'une déclaration attend : « Accepter remplace votre expert-comptable déclaré {dénomination} : les comptes de {noms} seront fermés. »
- Pour la demande du cabinet du SIREN déclaré (C31) : « Ce cabinet est votre expert-comptable déclaré : accepter le relie à votre société avec les autorisations de votre déclaration, que vous pouvez ajuster ci-dessous, et ferme les comptes de {noms}. » ; « par défaut » y veut dire les autorisations de la déclaration, et le formulaire d'ajustement (l.138-142) est prérempli avec elles.

### 6.4 Liste de l'équipe et fiche (`MembreResource`, `FicheDuMembre`)

- La liste (`MembreResource::table()`, l.98-132) montre les comptes d'expert-comptable au propriétaire seul : rôle « Expert-comptable déclaré · {dénomination} » ; magasins (`resumeDesMagasins()`, l.218-237) « Tous les magasins réels » ; signaux (`signaux()`, l.189-195) « Lien valable jusqu'au {date} », « Lien expiré, effacé ou non parti », « Mot de passe pas encore choisi ».
- La fiche (`infolist()`, l.134-181) : « Adresse e-mail professionnelle (reçoit les liens de son mot de passe) » ; « Mot de passe : pas encore choisi » tant que `mot_de_passe_choisi_le` est nul ; accès redonné selon le mode ; section « Autorisations de l'expert-comptable » (libellés de la déclaration, lien vers « Cabinet comptable ») ; actions suspendre (« Son lien en cours ne vaudra plus »), réactiver, retirer (pour la dernière personne non retirée : « C'est la dernière personne de votre expert-comptable déclaré : la retirer met fin à la déclaration. »), réintégrer, redonner l'accès (`GereLesComptes::actionRedonnerLAcces()`, l.122-165 : pour lui, « En sa présence » ou « Lui envoyer un nouveau lien, valable 7 jours, à {adresse} : son mot de passe actuel ne vaudra plus »), corriger l'adresse (tant que son mot de passe n'est pas choisi ; adresse et confirmation) ; jamais « Changer le rôle » ni « Transmettre la propriété ».
- `GereLesComptes::fenetreDuCompteCree()` : docblock (l.193-218) : elle n'est plus « la seule à montrer son identifiant à qui l'a créé » ; la fenêtre de succès de la déclaration et de l'ajout d'une personne le montre aussi au propriétaire.

### 6.5 Profil et panneau des magasins

- `ModifierProfil` (`app/Filament/App/Pages/Auth/ModifierProfil.php`) : pour un expert-comptable de la société (`getEmailFormComponent()`, l.104-113), l'adresse est obligatoire, sans contrôle d'unicité, et une nouvelle adresse passe par `DemanderUneNouvelleAdresseAction` comme celle du propriétaire (`save()`, l.204-211 : la branche de la l.209-211 ne s'applique plus à lui), avec l'aide « Le propriétaire de la société vous envoie à cette adresse un nouveau lien quand il vous redonne l'accès ; elle ne sert pas à vous connecter. Une nouvelle adresse vaut une fois confirmée par le lien qui lui est envoyé. » et l'état de l'adresse en attente ; l'identifiant en lecture seule ; docblock (l.23-53 : « c'est le seul endroit où elle s'écrit » devient « hors l'adresse d'un expert-comptable de la société, saisie par le propriétaire à la création et confirmée par un lien quand la personne la change »).
- Panneau « app » : les magasins réels de sa société seulement ; « Bonjour, Expert-comptable déclaré par la société · cabinet … · Lyon 1 » ; menus selon ses autorisations, aucune caisse ; pages « La société » (P10) et « Aucun magasin pour le moment ».

### 6.6 Page publique du lien

- GET : « Bienvenue Marc Petit. BOULANGERIE BERNARD (SIREN 000 000 018) vous ouvre un accès d'expert-comptable sur PILIQI, comme personne du cabinet CABINET PETIT ET ASSOCIES (SIREN 000 000 943). Ce que vous pourrez consulter ou faire : {libellés}. Choisissez votre mot de passe. » ; deux champs avec l'astérisque (R21), l'aide « Au moins 8 caractères ; ni votre identifiant, ni un seul caractère répété, ni une suite de chiffres. », le bouton « Choisir mon mot de passe ». Ni le nom du propriétaire, ni l'adresse, ni l'identifiant.
- POST réussi : connexion, avis persistant de l'identifiant (5.8).
- Page neutre : le message de `LienDeLExpertComptableInvalideException`.

### 6.7 E-mails

- **`LienDuMotDePasseDeLExpertComptableMail`** (sans file, C26) : objet « {société} vous ouvre l'accès à ses magasins sur PILIQI », ou « Nouveau lien pour votre accès à {société} sur PILIQI » ; corps : « Bonjour {nom}, {société} (SIREN {siren}) vous a déclaré comme son expert-comptable, du cabinet {dénomination ou SIREN déclaré}. Choisissez votre mot de passe avec ce lien, valable jusqu'au {date} à {heure} (heure de Paris). Votre identifiant de connexion vous sera montré sur la page, une fois votre mot de passe choisi : il n'est jamais envoyé par e-mail. Ce que vous pourrez consulter ou faire dans ses magasins : {libellés}. Le jour où votre cabinet s'inscrit et est vérifié sur PILIQI, vous accéderez à {société} par l'espace du cabinet, et ce compte sera fermé. Si vous n'êtes pas la personne visée, ignorez cet e-mail. » ; pour un accès redonné, en plus : « Votre accès a été redonné par {propriétaire} : votre mot de passe d'avant ne sert plus. » Jamais d'identifiant ni de mot de passe.
- **`CompteDExpertComptableFermeMail`** (en file, au rattachement, à chaque compte retiré, à l'adresse lue après le commit) : « Bonjour {nom}, votre cabinet {dénomination} est désormais inscrit et vérifié sur PILIQI, et relié à {société} : le compte que {société} vous avait créé est fermé. Vous accéderez à {société} depuis l'espace de votre cabinet, dès que son propriétaire vous confiera ce client. Si votre cabinet n'a pas ouvert d'espace sur PILIQI, prévenez aussitôt {société} et l'éditeur de PILIQI{, à {adresse de config('editeur.email')}, quand elle est réglée} : quelqu'un s'est peut-être inscrit à sa place. » Jamais d'identifiant.
- Le lien de confirmation d'une nouvelle adresse (P13) et l'avis à l'ancienne adresse sont ceux du propriétaire (`ConfirmationNouvelleAdresseMail`, `AdresseChangeeMail`), leurs textes rendus valables pour l'expert-comptable de la société (aucune mention d'« adresse de connexion » pour lui).
- Aucun e-mail ne part à un client de la société.

### 6.8 Avis

- Au rattachement, au propriétaire du client (`AvisDuCabinetEnFile`, cloche et e-mail, sauf s'il est l'auteur du geste) : « Votre expert-comptable a rejoint PILIQI » — « Le cabinet {dénomination} (SIREN {siren}) que vous aviez déclaré est inscrit et vérifié sur PILIQI : il suit désormais votre société depuis son espace, avec les autorisations que vous lui aviez accordées. Les comptes de {noms} sont fermés. Si ce n'est pas votre expert-comptable, mettez fin au lien dans « Cabinet comptable » et prévenez l'éditeur de PILIQI. »
- Au rattachement, au propriétaire du cabinet (`AvisDuCabinetEnFile`, cloche et e-mail, `clientId`), un par client (C34) : « Nouveau client relié » — « {client} (SIREN {siren}) avait déclaré votre cabinet comme son expert-comptable : elle est désormais votre cliente liée, avec les autorisations qu'elle vous accorde. Confiez-la à vos collaborateurs. » Rien des comptes fermés (P8).
- Lien qui ne part pas (`AvisDeLEquipe` au propriétaire) : « Le lien de {nom} n'est pas parti : son compte ou votre déclaration n'est plus ouvert. » ; messagerie en panne : « Le compte de {nom} est créé, mais l'e-mail n'a pas pu partir : envoyez-lui un nouveau lien depuis sa fiche. »
- Autorisations réglées (`AvisDeLEquipe`, cloche seulement, à chaque compte ouvert) : « Vos autorisations chez {société} ont changé. »
- Première connexion, accès redonné : avis existants ; cabinet lié remplacé ou dont la demande est retirée par une déclaration : `MettreFinAuLienAction::prevenir()` ou l'avis du retrait (« votre client a choisi un autre expert-comptable »).

### 6.9 Espace du cabinet au rattachement

Le client paraît dans « Clients » (`ClientDuCabinetResource`, « Lié depuis » = date du rattachement) et au tableau de bord, dans la limite de ses autorisations, comme tout client lié ; sa fiche montre l'origine « Expert-comptable déclaré par le client » (C23). Rien de la déclaration avant le rattachement.

---

## 7. Journaux

### 7.1 Journal d'audit de la société (catégorie Accès, gardée 5 ans)

| Entrée | Quand | Nouvelles valeurs |
|---|---|---|
| `ExpertComptableDeclare` | déclaration, avec sa première personne | `cabinet` (identité déclarée : SIREN, dénomination lue), `autorisations` (liste), `droits` (compte créé, comptes d'une déclaration remplacée) |
| `ExpertComptableCree` | personne ajoutée | `cabinet`, `droits` |
| `AutorisationsDuCabinetModifiees` | réglage | `cabinet` (lié ou déclaré), `autorisations`, `droits` des comptes touchés |
| `CompteSuspendu`, `CompteReactive`, `CompteRetire`, `CompteReintegre` | gestes du propriétaire | `droits` |
| `AccesRedonne` | accès redonné en présence ou par un lien | `compte` (identifiant technique, nom), `mode` |
| `AdresseDExpertComptableChangee` | correction par le propriétaire (P5) ; changement confirmé par la personne (P13) | `compte` |
| `LienTermine` | fin d'une déclaration (retrait, dernière personne retirée, remplacement, révocation) | `cabinet` (identité déclarée ou du cabinet), `client`, `motif`, `droits` des comptes |
| `DemandeDeLienRetiree` | rattachement ; demande envoyée par la société et retirée par une déclaration | `cabinet`, `client` |
| `ExpertComptableRattache` | rattachement | `cabinet`, `droits` des comptes retirés |
| `LienActive` | rattachement | `cabinet`, `client`, `origine` = `declaration_du_client`, `autorisations` = `declarees` ou `ajustees`, `droits` des comptes du cabinet |
| `PremiereConnexion` | première connexion | `compte` |

Jamais l'adresse, l'identifiant, le mot de passe ni un jeton. L'auteur est le propriétaire, ou personne : le système (rattachement par un job) ou l'éditeur (révocation, avec son intervention). Le journal reste consultable par le propriétaire seul (lot A7) ; les nouvelles entrées entrent dans ses filtres. Exemple de `droits` : `{"57": {"nom": "Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)", "role_societe": "expert_comptable", "statut": "actif", "autorisations_societe": [], "magasins": {"3": {"role_societe": "expert_comptable", "role_magasin": null, "statut": "actif", "permissions": ["avoir.view", "…"], "cabinet_declare": {"siren": "000000943", "raison_sociale": "CABINET PETIT ET ASSOCIES"}}}}}`.

### 7.2 Journal d'audit du cabinet

Au rattachement seulement : `DemandeDeLienRetiree` (s'il y en a) et `LienActive`, avec les droits de ses seuls comptes, jamais le nom d'un compte de la société. Au remplacement d'un lien actif par une déclaration : `LienTermine` (motif `remplace`) ; au retrait de sa demande par une déclaration : `DemandeDeLienRetiree` ; sans rien de la déclaration. Rien d'autre sur les sociétés qui ont déclaré son SIREN.

### 7.3 JET 130 de chaque magasin réel

À la création d'un compte (nul → droits), à chaque réglage des autorisations, à chaque statut, à la fin de la déclaration (droits → nul), au rattachement (comptes de la société → nul ; propriétaire du cabinet nul → droits, avec `cabinet`), à la création d'un magasin réel (droits implicites). Contenu : `membre` {`id`, `nom` figé}, `avant`, `apres` (`role_societe` `expert_comptable`, `role_magasin` nul, `statut`, `permissions`, `cabinet_declare` {`siren`, `raison_sociale`}). Jamais dans le magasin de démonstration, jamais au choix du mot de passe ni au changement d'adresse (aucun droit ne change). Aucune trace de visiteur pour un geste du système. Un cabinet lié à qui le client laisse « Caisse sécurisée : consulter » lit ces entrées, comme toutes celles de l'équipe du client (R18).

---

## 8. CLAUDE.md : textes exacts

Chaque ancien texte est copié de `code-01fe217/CLAUDE.md`, où il figure une seule fois à la ligne citée (vérifié). Le lot recopie chaque ancien texte depuis le fichier réel de son code de départ : les lots A7 et A7b ont déjà ajouté des lectures à « Produit » (la révocation d'un cabinet) et des phrases à « Cabinets d'expertise comptable » (la correction du SIREN d'un cabinet), et ont pu réécrire d'autres passages ; un écart se signale dans le compte rendu. Les textes suivent les options recommandées ; 8.10 donne les variantes. Ces textes s'écrivent dans le commit 2 (section 10).

### 8.1 Protocole de rigueur, point 2 (l.4)

Remplacer :
```text
et, à l'invitation d'un cabinet d'expertise comptable, le fait que le SIREN invité est déjà inscrit, appris par ce cabinet et par la personne qui reçoit le lien de sa demande de rattachement, sans rien d'autre de la société tant que son propriétaire n'a pas accepté.
```
par :
```text
et, à l'invitation d'un cabinet d'expertise comptable, le fait que le SIREN invité est déjà inscrit, appris par ce cabinet et par la personne qui reçoit le lien de sa demande de rattachement, sans rien d'autre de la société tant que son propriétaire n'a pas accepté, ou, quand ce SIREN est celui d'une société inscrite comme cabinet d'expertise comptable, le refus de l'inviter (« Ce SIREN ne peut pas être invité par votre cabinet. »), qui apprend au cabinet qui invite que ce SIREN est celui d'un cabinet inscrit, et rien d'autre (le code d'activité 69.20Z de toute entreprise est public au répertoire Sirene) ; et, à la déclaration de l'expert-comptable d'une société, le seul fait que le SIREN déclaré est celui d'un cabinet vérifié, que la recherche des cabinets montre déjà, jamais celui d'un cabinet inscrit qui ne l'est pas. Aucune règle d'unicité ne porte, hors de la société qui le déclare, sur le SIREN qu'une société déclare pour son expert-comptable.
```

### 8.2 « Produit », données propres à une société (l.155)

Remplacer :
```text
du côté d'un client son lien avec un cabinet et les autorisations qu'il lui accorde,
```
par :
```text
du côté d'un client son lien avec un cabinet ou la déclaration de son expert-comptable, les autorisations qu'il leur accorde et les comptes de cet expert-comptable attachés à sa déclaration, avec le lien de leur mot de passe,
```

Remplacer :
```text
les comptes d'un cabinet appartiennent au cabinet et n'entrent chez un client que par le lien de leur cabinet.
```
par :
```text
les comptes d'un cabinet appartiennent au cabinet et n'entrent chez un client que par le lien de leur cabinet ; les comptes de l'expert-comptable d'une société, déclaré par le SIREN de son cabinet, appartiennent à cette société.
```

### 8.3 « Produit », lectures hors du magasin ou de la société courants (l.155)

1. Remplacer :
```text
pour l'export comptable de la société produit par le propriétaire ou par le cabinet lié
```
par :
```text
pour l'export comptable de la société produit par le propriétaire, par le cabinet lié ou par l'expert-comptable de la société
```

2. Après :
```text
son n° d'inscription à l'Ordre et le nom des personnes du cabinet qui ont accès à la société ;
```
insérer (une espace avant) :
```text
pour le propriétaire d'une société qui déclare son expert-comptable ou ajoute une personne à sa déclaration, la même recherche par le SIREN déclaré, qui refuse de déclarer un cabinet vérifié et invite à le désigner, sans jamais révéler qu'un cabinet de ce SIREN est inscrit sans être vérifié ; quand un cabinet devient vérifié et actif, et chaque heure pour rattraper un rattachement manqué, les déclarations encore en attente de son SIREN dans les autres sociétés (l'identifiant de chacune et de sa société) et les demandes en cours entre chacune de ces sociétés et ce cabinet, pour rattacher chaque déclaration depuis le contexte de sa société, sans que le cabinet apprenne rien d'une société avant que son lien soit actif ni qu'une société apprenne qu'une autre a déclaré le même SIREN ; à la connexion, à l'ouverture d'un magasin et pour son nom figé dans l'historique, avant qu'aucune société ne soit ouverte, la déclaration à laquelle est attaché un compte d'expert-comptable d'une société (le SIREN et la dénomination de son cabinet), et ses autorisations ;
```

3. Remplacer :
```text
pour adresser la demande de rattachement d'un cabinet qui invite un SIREN déjà inscrit, la société qui le tient et son type d'activité, jamais montrés ;
```
par :
```text
pour adresser la demande de rattachement d'un cabinet qui invite un SIREN déjà inscrit, la société qui le tient et son type d'activité, jamais montrés, hors le refus d'inviter le SIREN d'une société inscrite comme cabinet, qui n'apprend au cabinet qui invite que ce fait ;
```

4. Remplacer :
```text
avant qu'aucune société ne soit ouverte, l'invitation d'un cabinet que désigne son lien, et la demande de rattachement que désigne son lien (le cabinet qui la fait, jamais la société à laquelle elle s'adresse, qui ne se montre qu'à son propriétaire connecté) ;
```
par :
```text
avant qu'aucune société ne soit ouverte, l'invitation d'un cabinet que désigne son lien, la demande de rattachement que désigne son lien (le cabinet qui la fait, jamais la société à laquelle elle s'adresse, qui ne se montre qu'à son propriétaire connecté) et le compte d'expert-comptable d'une société que désigne le lien de son mot de passe (le premier, celui d'un accès redonné ou celui qui suit une adresse corrigée), comparé à son empreinte (son nom, la dénomination et le SIREN de sa société, le SIREN et la dénomination du cabinet déclaré et les autorisations de la déclaration, puis, une fois son mot de passe choisi, son identifiant) ;
```

5. Remplacer :
```text
sans que le cabinet en apprenne rien d'autre tant que le lien n'est pas actif et les codes de magasins d'une société révoquée
```
par :
```text
sans que le cabinet en apprenne rien d'autre tant que le lien n'est pas actif, ou, quand ce SIREN est celui d'une société inscrite comme cabinet, le seul fait qu'il l'est, appris par le cabinet qui l'invite (« Ce SIREN ne peut pas être invité par votre cabinet. »), et les codes de magasins d'une société révoquée
```

### 8.4 « Comptes et équipe » (l.161)

1. Remplacer :
```text
(les comptes d'un cabinet d'expertise comptable appartiennent au cabinet et n'entrent chez un client que par le lien de leur cabinet, voir « Cabinets d'expertise comptable »)
```
par :
```text
(les comptes d'un cabinet d'expertise comptable appartiennent au cabinet et n'entrent chez un client que par le lien de leur cabinet ; les comptes d'expert-comptable qu'une société crée pour les personnes du cabinet qu'elle déclare appartiennent à cette société ; voir « Cabinets d'expertise comptable »)
```

2. Remplacer :
```text
(la création d'un compte qu'il fait, un accès qu'il redonne, ou sa demande de le voir) ; jamais dans un document, un e-mail, le journal d'audit ni un journal technique.
```
par :
```text
(la création d'un compte qu'il fait, un accès qu'il redonne, ou sa demande de le voir) ; un expert-comptable de la société le découvre sur la page du lien de son mot de passe, une fois ce mot de passe choisi ; jamais dans un document, un e-mail, le journal d'audit ni un journal technique.
```

3. Remplacer :
```text
tous les autres comptes (responsables, vendeurs, observateurs, administrateurs, collaborateurs d'un cabinet) seulement avec leur identifiant et leur mot de passe.
```
par :
```text
tous les autres comptes (responsables, vendeurs, observateurs, administrateurs, experts-comptables de la société, collaborateurs d'un cabinet) seulement avec leur identifiant et leur mot de passe.
```

4. Remplacer :
```text
celle de tout autre compte est facultative, n'est renseignée que par la personne elle-même dans son profil, jamais par qui crée son compte, n'est qu'un contact, peut être celle de plusieurs comptes et ne sert jamais à se connecter ni à retrouver un accès.
```
par :
```text
celle de tout autre compte est facultative, n'est renseignée que par la personne elle-même dans son profil, jamais par qui crée son compte, n'est qu'un contact, peut être celle de plusieurs comptes et ne sert jamais à se connecter ni à retrouver un accès, sauf, exception voulue, celle d'un expert-comptable de la société : son adresse professionnelle, obligatoire tant que son compte n'est pas retiré, que le propriétaire tape deux fois en créant son compte et corrige tant que la personne n'a pas choisi son mot de passe, puis que la personne change elle-même dans son profil, reçoit les liens de son mot de passe que le propriétaire lui envoie, ne sert jamais à se connecter et peut être celle de plusieurs comptes.
```

5. Remplacer :
```text
personne ne change le nom ni l'adresse d'un autre compte ;
```
par :
```text
personne ne change le nom ni l'adresse d'un autre compte, hors l'adresse d'un expert-comptable de la société qui n'a pas encore choisi son mot de passe ;
```

6. Après :
```text
il ne touche jamais au propriétaire ni à un autre administrateur.
```
insérer (une espace avant) :
```text
Seul le propriétaire déclare l'expert-comptable de la société et crée, suspend, réactive, retire, réintègre et redonne l'accès aux comptes de ses personnes (voir « Cabinets d'expertise comptable ») ; aucun administrateur ni responsable ne les voit ni ne les touche.
```

7. Remplacer :
```text
administrateurs, responsables et cabinet lié)
```
par :
```text
administrateurs, responsables, cabinet lié et expert-comptable de la société)
```

8. Remplacer :
```text
un compte d'entraînement ne change jamais de rôle de société)
```
par :
```text
un compte d'entraînement et un compte d'expert-comptable de la société ne changent jamais de rôle de société, et aucun compte ne devient expert-comptable de la société)
```

9. Remplacer :
```text
Un compte créé par la société a pour premier mot de passe son identifiant, remis en main propre par qui l'a créé, jamais envoyé par e-mail ;
```
par :
```text
Un compte créé par la société a pour premier mot de passe son identifiant, remis en main propre par qui l'a créé, jamais envoyé par e-mail, sauf, exception voulue, un compte d'expert-comptable de la société, qui n'a jamais son identifiant pour mot de passe : la personne choisit le sien par un lien valable 7 jours envoyé à son adresse professionnelle ;
```

10. Remplacer :
```text
absente, son mot de passe redevient son identifiant ;
```
par :
```text
absente, son mot de passe redevient son identifiant, sauf pour un expert-comptable de la société, à qui le propriétaire envoie à la place un nouveau lien de 7 jours à son adresse, son mot de passe d'avant ne servant plus dès l'envoi ;
```

11. Remplacer :
```text
avec le nom de la personne, jamais le propriétaire ; la base refuse une vente ou un encaissement à son nom.
```
par :
```text
avec le nom de la personne, jamais le propriétaire ni un expert-comptable de la société ; la base refuse une vente ou un encaissement à leur nom.
```

12. Remplacer (P13) :
```text
la nouvelle adresse de connexion du propriétaire ne prend effet qu'après confirmation par un lien envoyé à cette adresse, et l'ancienne est prévenue ;
```
par :
```text
la nouvelle adresse de connexion du propriétaire, comme la nouvelle adresse d'un expert-comptable de la société, ne prend effet qu'après confirmation par un lien envoyé à cette adresse, et l'ancienne est prévenue ;
```

### 8.5 « Type d'activité » (l.165)

Remplacer :
```text
(type de chacun de ses clients liés, type de la société qui tient un SIREN qu'il invite, jamais montré)
```
par :
```text
(type de chacun de ses clients liés, type de la société qui tient un SIREN qu'il invite, jamais montré, hors le refus d'inviter le SIREN d'un cabinet inscrit)
```

### 8.6 « Cabinets d'expertise comptable » (l.166)

1. Remplacer :
```text
; une société n'a jamais de compte d'expert-comptable : un expert-comptable accède toujours à un client par le lien de son cabinet.
```
par :
```text
; un expert-comptable accède à un client par le lien de son cabinet ou, tant que son cabinet n'est pas vérifié dans PILIQI, par un compte d'expert-comptable que ce client lui crée (voir plus bas), compte de la société et non du cabinet.
```

2. Remplacer :
```text
il ne voit et ne lie qu'un cabinet vérifié et n'a qu'un cabinet lié à la fois : en lier un autre met fin au précédent ;
```
par :
```text
il ne voit et ne lie qu'un cabinet vérifié et n'a qu'un cabinet à la fois, lié ou déclaré : en lier ou en déclarer un autre met fin au précédent ;
```

3. Remplacer :
```text
le propriétaire du client, seul à désigner, accepter, régler et mettre fin au lien du côté du client,
```
par :
```text
le propriétaire du client, seul à désigner, déclarer, accepter, régler et mettre fin au lien du côté du client,
```

4. Remplacer :
```text
la base refuse toute autre autorisation pour un cabinet, toute autorisation des articles chez un client qui n'en a pas, et toute vente ou tout encaissement au nom d'un de ses comptes.
```
par :
```text
la base refuse toute autre autorisation pour un cabinet ou pour l'expert-comptable déclaré d'une société, toute autorisation des articles chez un client qui n'en a pas, et toute vente ou tout encaissement au nom d'un compte d'un cabinet ou d'un expert-comptable de la société.
```

5. Remplacer :
```text
Chaque action d'un compte du cabinet chez un client se fait en son nom, suivi du nom du cabinet,
```
par :
```text
Chaque action d'un compte du cabinet ou d'un expert-comptable de la société chez un client se fait en son nom, suivi du nom du cabinet (pour l'expert-comptable de la société, « expert-comptable déclaré par la société » et la dénomination que le répertoire Sirene donnait au SIREN déclaré le jour de la déclaration, ou ce SIREN),
```

6. Remplacer :
```text
Une société dont l'expert-comptable n'a pas encore d'espace l'invite par e-mail à en créer un.
```
par :
```text
Une société dont l'expert-comptable n'a pas encore d'espace l'invite par e-mail à en créer un ou, une fois validée, le déclare, par le SIREN de son cabinet et le nom de chaque personne de ce cabinet qui doit entrer. Seul son propriétaire déclare, en redonnant son mot de passe, dans une société validée qui n'est ni un cabinet, ni close, ni révoquée ; une société suspendue ou résiliée le peut encore, et la base refuse une déclaration dans une société qui attend son code. Le SIREN déclaré doit exister, être actif au répertoire Sirene et avoir le code d'activité 69.20Z (activités comptables), sinon PILIQI refuse, un code absent ou d'une autre nomenclature comme un autre code ; la dénomination du cabinet est lue au répertoire le jour de la déclaration, jamais saisie, et le SIREN n'est plus recontrôlé ensuite. Le SIREN d'un cabinet déjà vérifié dans PILIQI ne se déclare pas : la société le désigne. Déclarer remplace, après une confirmation qui le nomme, le cabinet lié ou l'expert-comptable déclaré, et retire la demande de lien que la société a envoyée et qui attend sa réponse ; lier un autre cabinet met fin à la déclaration. Pour chaque personne, le propriétaire crée, par son nom et son adresse e-mail professionnelle, un compte de la société au rôle d'expert-comptable de la société : ni administrateur ni membre, sans affectation, ni case, ni autorisation de société, ce compte n'a jamais son identifiant pour mot de passe, ne change jamais de rôle, ne reçoit jamais la propriété, ne vend jamais, n'envoie aucun e-mail aux clients, ne reçoit aucune alerte de stock, n'ouvre jamais l'espace d'administration de la société ni le magasin de démonstration, ne voit jamais le journal d'audit ni les adresses IP et navigateurs, voit en lecture seule l'identité légale, le type d'activité et les réglages fiscaux communs de la société, et ouvre chaque magasin réel de la société, présent et futur, avec les autorisations de la déclaration, les mêmes pour toutes ses personnes : celles qu'un client accorde à un cabinet lié, toutes les consultations cochées au départ, que le propriétaire retire à son gré et auxquelles, pour une société « Commerce ou atelier », il ajoute celles des achats et du stock. PILIQI envoie à cette adresse un lien valable 7 jours, où la personne choisit son mot de passe et découvre son identifiant. Tant que la déclaration attend son cabinet, le propriétaire seul suspend (ce qui efface le lien en cours), réactive, retire et réintègre ces comptes, corrige l'adresse d'une personne qui n'a pas encore choisi son mot de passe et redonne l'accès, en sa présence ou par un nouveau lien de 7 jours, jamais en remettant son identifiant pour mot de passe ; la déclaration naît avec sa première personne et prend fin quand le propriétaire retire sa dernière personne non retirée. Le jour où un cabinet de ce SIREN est inscrit et vérifié dans PILIQI (validé, contrôlé 69.20Z, vérifié à l'Ordre et actif), et dès qu'au moins une personne déclarée a un compte actif, la déclaration devient d'elle-même son lien avec la société, sans demande ni réponse, avec les mêmes autorisations, et les comptes de l'expert-comptable de la société sont retirés, leur historique restant : l'expert-comptable entre désormais par l'espace de son cabinet, dont le propriétaire lui confie ce client ; les propriétaires des deux sociétés en sont prévenus et chaque personne dont le compte se ferme reçoit un e-mail ; si ce lien prend fin ensuite, rien ne revit. Le propriétaire de la société qui désigne ce cabinet ou accepte sa demande de rattachement fait ce même rattachement, avec les autorisations qu'il valide. Jusque-là, le cabinet n'apprend rien des sociétés qui ont déclaré son SIREN, et aucune société n'apprend qu'une autre l'a déclaré. La déclaration prend fin aussi, ses comptes retirés, quand le propriétaire la retire et à la révocation de la société.
```

7. Remplacer :
```text
L'abonnement d'un cabinet et la facturation des clients qu'il invite se décident à l'étape 12.
```
par :
```text
L'abonnement d'un cabinet et la facturation des clients qu'il invite ou qui le rejoignent par la déclaration de leur expert-comptable se décident à l'étape 12.
```

### 8.7 Autres puces de « Décisions de l'éditeur »

1. « Prix et TVA des ventes » (l.169) — remplacer `et qu'un cabinet lié n'a jamais ; changer le produit` par `et qu'un cabinet lié et l'expert-comptable de la société n'ont jamais ; changer le produit`.
2. « Ventes hors de France » (l.170) — remplacer `et qu'un cabinet lié n'a jamais. Détaxe` par `et qu'un cabinet lié et l'expert-comptable de la société n'ont jamais. Détaxe`.
3. « Vendeurs » (l.173) — remplacer `jamais par le propriétaire.` par `jamais par le propriétaire ni par un expert-comptable de la société.` ; remplacer `et un compte du cabinet lié à qui le client accorde de voir celles de toute l'équipe ;` par `et un compte du cabinet lié ou un expert-comptable de la société à qui le client accorde de voir celles de toute l'équipe ;`.
4. « Achats et stock » (l.174) — remplacer `et un cabinet lié ne l'a jamais)` par `et ni un cabinet lié ni l'expert-comptable de la société ne l'ont jamais)`.
5. « Caisse et clôtures » (l.175) — après `les jetons des demandes répétées (après 30 jours),` insérer (une espace avant) `les jetons du lien du mot de passe d'un expert-comptable de la société (effacés dès qu'il sert, qu'un autre le remplace, que l'e-mail ne peut pas partir ou que le compte est suspendu ou retiré ; un lien expiré, sans donnée personnelle, reste jusque-là),`.
6. « E-mails » (l.177) — remplacer `jamais à un compte d'un cabinet.` par `jamais à un compte d'un cabinet ni à un expert-comptable de la société ; chaque lien du mot de passe d'un expert-comptable de la société (le premier, celui d'un accès redonné ou celui qui suit une adresse corrigée) compte dans les e-mails du propriétaire qui l'envoie, et le lien qui confirme sa nouvelle adresse dans les siens.`.
7. « Journal d'audit » (l.178) — remplacer `(factures, paiements, autorisations, liens avec un cabinet, procédures et réglages de la société)` par `(factures, paiements, autorisations, liens avec un cabinet et déclaration de l'expert-comptable de la société, procédures et réglages de la société)`.

### 8.8 Feuille de route

1. Étape 1 (l.194) — remplacer `rattachement d'une société inscrite et autorisations accordées par le client)` par `rattachement d'une société inscrite et autorisations accordées par le client, expert-comptable de la société déclaré par le SIREN de son cabinet et rattaché à ce cabinet dès qu'il est vérifié)`.
2. Étape 1 bis (l.195) — remplacer `lié à deux des sociétés, chaque collaborateur avec un client confié)` par `lié à deux des sociétés, chaque collaborateur avec un client confié ; une troisième société qui déclare l'expert-comptable d'un cabinet pas encore inscrit, avec deux comptes d'expert-comptable, puis le rattachement à ce cabinet quand il s'inscrit et est vérifié)`.
3. Étape 5 (l.199) — remplacer `par un compte du cabinet lié, chaque export inscrit au journal d'audit du client` par `par un compte du cabinet lié ou par un expert-comptable de la société, chaque export inscrit au journal d'audit du client`.
4. Étape 12 (l.206) — remplacer `abonnement d'un cabinet d'expertise comptable et facturation des clients qu'il invite ;` par `abonnement d'un cabinet d'expertise comptable et facturation des clients qu'il invite et de ceux qui le rejoignent par la déclaration de leur expert-comptable (à trancher alors : comptent-ils comme des clients invités ?) ;`.

### 8.9 Ce qui ne change pas

« Un compte d'un cabinet appartient au cabinet, seule exception à « un compte n'appartient qu'à une seule société » » (l.166) reste vrai : le compte d'expert-comptable de la société appartient à la société. La phrase « activité exonérée de TVA que PILIQI ne facture pas » de « Type d'activité » ne change pas (R20). « Tant que le mot de passe d'un compte est son identifiant … » (l.161) ne change pas : le mot de passe d'un expert-comptable de la société n'est jamais son identifiant. « consultable par le propriétaire seul » (l.178) couvre déjà l'expert-comptable de la société. Les phrases qui font confirmer une règle par « l'expert-comptable de la société » (puces « Société et établissements », « Caisse et clôtures », « Facture électronique », et les textes du lot S9-A sur les preuves d'export) désignent la personne, quel que soit son accès à PILIQI (cabinet lié, compte de la société ou aucun) : elles ne changent pas.

### 8.10 Textes et code si l'éditeur choisit une autre option

Sans objet : l'éditeur a retenu toutes les options recommandées (« tout comme recommandé », 01/11/2026). Les textes des sections 8.1 à 8.9 sont les textes définitifs.

## 9. Tests nommés

Règles : Faker semé par test ; toute heure écrite avec son fuseau (`self::aParis('2026-11-03 10:00:00')`) ; temps figé pour toute échéance ; tests HTTP et Livewire par `dansLeMagasin()`, `dansLEspaceDeLaSociete()`, `dansLEspaceDuCabinet()` ; tests de concurrence marqués `exigerUnServeurDeBaseDeDonnees()` et menés par `demarrerUnProcessusConcurrent()`, `concurrencerDesQueLaLigneEstVerrouillee()` et `attendreQueLeProcessusAttendeUnVerrou()` (`tests/TestCase.php` l.1512-1636). Chaque test nommé échoue sans le code qu'il couvre. Aucun test n'est supprimé pour faire passer la suite ; le message de commit nomme chaque test réécrit et sa raison. Les tests marqués d'un point (Pn) suivent l'option retenue ; une autre option les réécrit (8.10).

### 9.0 Aides de `TestCase` (`tests/TestCase.php`)

- `declarerSonExpertComptable(Magasin|Societe $client, string $siren = FauxRepertoireSirene::SIREN_CABINET, string $nom = 'Marc Petit', string $email = 'marc.petit@petit-associes.test', ?array $autorisations = null, bool $remplacer = false): User` : sur une société validée, le répertoire factice règle le SIREN en 69.20Z (`exercer()`, l.257), e-mails retenus, action réelle ; rend le compte créé ; aucun contexte ensuite.
- `ajouterUnExpertComptable(Societe $client, string $nom, string $email): User`.
- `jetonDuDernierLienDExpertComptable(): string` : lu dans le dernier `LienDuMotDePasseDeLExpertComptableMail`, comme `jetonDuDernierLienDeTransmission()` (l.971).
- `expertComptable(Magasin|Societe $client, string $nom = 'Marc Petit', ?array $autorisations = null): User` : déclaré, puis mot de passe choisi par le lien (`MOT_DE_PASSE_CHOISI`).
- `inscrireUnCabinetSansLeVerifier(?string $siren = null): Societe` (inscrit, sans code ni Ordre) et `verifierLOrdreDuCabinet(Societe $cabinet): void`, pour les tests d'ordre des gestes ; `ouvrirUnCabinet()` (l.478-493) inchangée : appelée après une déclaration de son SIREN, elle rattache par le vrai parcours (file `sync`, `phpunit.xml` l.31).

### 9.1 `tests/Feature/Cabinets/ExpertComptableDeclareTest.php`

- `test_seul_le_proprietaire_declare_son_expert_comptable_en_redonnant_son_mot_de_passe`
- `test_une_societe_close_revoquee_qui_attend_son_code_ou_un_cabinet_ne_declare_pas_mais_une_societe_suspendue_ou_resiliee_le_peut` (P12)
- `test_une_societe_qui_attend_son_code_lit_pourquoi_elle_ne_declare_pas_et_peut_designer_ou_inviter` (P12)
- `test_le_siren_declare_doit_exister_etre_actif_et_avoir_le_code_69_20z`
- `test_un_code_d_activite_absent_ou_d_une_autre_nomenclature_est_refuse_comme_un_autre_code` (P6)
- `test_un_repertoire_injoignable_ne_cree_rien_et_fait_reessayer`
- `test_les_controles_sirene_des_declarations_sont_limites_a_dix_par_heure_et_par_societe`
- `test_la_societe_ne_se_declare_pas_elle_meme_comme_expert_comptable`
- `test_la_denomination_du_cabinet_est_lue_au_repertoire_et_jamais_saisie`
- `test_en_diffusion_partielle_l_ecran_et_le_nom_fige_portent_le_siren_du_cabinet`
- `test_le_siren_d_un_cabinet_deja_verifie_ne_se_declare_pas_et_mene_a_la_designation` (P1)
- `test_un_cabinet_inscrit_mais_pas_encore_verifie_se_declare_comme_un_siren_inconnu_de_piliqi`
- `test_la_declaration_cree_un_compte_de_la_societe_dont_le_mot_de_passe_n_est_jamais_l_identifiant`
- `test_l_adresse_saisie_par_le_proprietaire_est_le_contact_du_compte_et_jamais_une_adresse_de_connexion`
- `test_l_adresse_se_saisit_deux_fois_et_deux_adresses_differentes_sont_refusees`
- `test_ni_l_adresse_ni_l_identifiant_n_entrent_au_journal_d_audit`
- `test_le_meme_jeton_avec_une_autre_adresse_est_refuse`
- `test_plusieurs_personnes_du_cabinet_declare_ont_chacune_leur_compte_et_leur_identifiant_avec_les_memes_autorisations`
- `test_ajouter_une_personne_exige_le_mot_de_passe_du_proprietaire_et_une_declaration_en_attente`
- `test_ajouter_une_personne_est_refuse_quand_le_cabinet_du_siren_declare_est_verifie`
- `test_declarer_de_nouveau_le_meme_siren_ou_le_cabinet_lie_est_refuse` (avec un cabinet lié vérifié : le message de R4, jamais celui de R5)
- `test_les_consultations_sont_precochees_et_le_proprietaire_en_decoche_et_ajoute_les_achats_et_le_stock`
- `test_une_societe_de_services_ne_propose_ni_n_accorde_aucune_autorisation_des_articles_a_son_expert_comptable`
- `test_aucune_autorisation_de_vente_de_compte_de_reglage_ni_de_caisse_ne_s_accorde_a_l_expert_comptable`
- `test_regler_les_autorisations_change_celles_de_tous_les_comptes_ouverts_et_les_previent`
- `test_une_creation_repetee_avec_le_meme_jeton_ne_cree_ni_un_second_compte_ni_un_second_lien`
- `test_la_limite_du_jour_des_creations_vaut_pour_les_experts_comptables`
- `test_declarer_alors_qu_un_cabinet_est_lie_le_remplace_apres_confirmation` (P2 ; sans la case : refus)
- `test_declarer_retire_la_demande_de_lien_envoyee_a_un_autre_cabinet_et_le_previent` (C32)
- `test_declarer_un_autre_siren_remplace_la_declaration_et_retire_ses_comptes_apres_confirmation` (P3 ; `Mail::assertNotSent(CompteDExpertComptableFermeMail::class)`)
- `test_lier_un_autre_cabinet_met_fin_a_la_declaration_et_retire_ses_comptes` (quand le cabinet accepte la désignation : `statut_change_par` nul)
- `test_retirer_la_declaration_retire_tous_ses_comptes_et_annule_leurs_liens`
- `test_retirer_la_derniere_personne_met_fin_a_la_declaration` (P11)
- `test_seul_le_proprietaire_suspend_reactive_retire_reintegre_et_redonne_l_acces_a_un_expert_comptable`
- `test_suspendre_un_expert_comptable_efface_son_lien_et_la_reactivation_n_en_rouvre_aucun`
- `test_un_compte_d_expert_comptable_retire_se_reintegre_tant_que_sa_declaration_est_en_attente_et_jamais_ensuite`
- `test_un_expert_comptable_ne_change_jamais_de_role_et_aucun_compte_ne_le_devient`
- `test_une_requete_forgee_ne_rend_personne_expert_comptable_par_le_changement_de_role` (403, jamais 500)
- `test_la_propriete_ne_se_transmet_jamais_a_un_expert_comptable_de_la_societe`
- `test_un_administrateur_ne_voit_ni_ne_touche_aucun_compte_d_expert_comptable`
- `test_une_requete_livewire_forgee_d_un_administrateur_ne_declare_ni_n_ajoute_rien`
- `test_la_transmission_de_la_propriete_garde_les_comptes_d_expert_comptable_au_nouveau_proprietaire`
- `test_une_societe_suspendue_regle_les_autorisations_et_gere_les_comptes_de_son_expert_comptable`
- `test_la_revocation_de_la_societe_met_fin_a_la_declaration_et_retire_ses_comptes`
- `test_declarer_ajouter_regler_suspendre_retirer_et_reintegrer_s_inscrivent_au_jet_130_de_chaque_magasin_reel_et_a_l_audit`

Avec P12 (b) ou (c) s'ajoutent : `test_la_declaration_ne_valide_jamais_une_societe_qui_attend_son_code`, `test_la_cloture_de_l_inscription_met_fin_a_la_declaration_et_retire_ses_comptes`, `test_une_autre_personne_qui_saisit_le_code_met_fin_a_la_declaration`.

### 9.2 `tests/Feature/Cabinets/LienDeLExpertComptableTest.php`

- `test_le_lien_part_apres_la_transaction_a_l_adresse_saisie_sans_identifiant_ni_mot_de_passe`
- `test_le_lien_vaut_sept_jours_a_la_meme_heure_de_paris_meme_au_changement_d_heure` (créé le 27/03/2027 à 10:00, heure de Paris : valable jusqu'au 03/04/2027 à 10:00)
- `test_ouvrir_le_lien_n_ecrit_rien_et_ne_montre_ni_l_identifiant_ni_l_adresse_ni_le_proprietaire`
- `test_choisir_son_mot_de_passe_consomme_le_lien_connecte_la_personne_et_montre_son_identifiant`
- `test_suivre_le_lien_depuis_la_session_d_un_autre_compte_ferme_d_abord_cette_session` (JET 40 de cette session)
- `test_le_mot_de_passe_choisi_suit_la_regle_et_ne_contient_pas_l_identifiant`
- `test_un_lien_expire_deja_servi_remplace_ou_inconnu_rend_la_meme_page_neutre`
- `test_le_lien_d_un_compte_suspendu_ou_retire_d_une_declaration_terminee_ou_d_une_societe_revoquee_ne_sert_plus`
- `test_la_page_du_lien_ferme_les_autres_sessions_du_compte`
- `test_la_page_du_lien_ne_transmet_pas_son_adresse` (`Referrer-Policy: no-referrer`)
- `test_l_expert_comptable_ne_se_connecte_jamais_avec_son_adresse_ni_avec_son_identifiant_pour_mot_de_passe`
- `test_mot_de_passe_oublie_n_ouvre_jamais_un_compte_d_expert_comptable_meme_quand_son_adresse_est_l_adresse_de_connexion_d_un_proprietaire`
- `test_un_nouveau_lien_coupe_l_ancien_mot_de_passe_l_ancien_lien_et_les_sessions` (P4)
- `test_l_acces_redonne_en_presence_marche_et_en_absence_est_refuse_pour_un_expert_comptable`
- `test_l_acces_redonne_par_un_lien_s_affiche_a_la_personne_et_sur_sa_fiche_avec_son_mode`
- `test_un_renvoi_repete_avec_le_meme_jeton_d_ecran_n_envoie_qu_un_lien`
- `test_au_plus_cinq_liens_par_jour_et_par_compte`
- `test_le_proprietaire_corrige_l_adresse_tant_que_la_personne_n_a_pas_choisi_son_mot_de_passe` (P5)
- `test_l_adresse_ne_se_corrige_plus_une_fois_le_mot_de_passe_choisi_meme_sans_connexion` (par le lien et en présence)
- `test_l_e_mail_qui_ne_peut_plus_partir_au_moment_de_l_envoi_previent_le_proprietaire`
- `test_une_panne_de_la_messagerie_efface_le_lien_et_previent_le_proprietaire`
- `test_chaque_lien_compte_dans_les_e_mails_du_proprietaire`
- `test_la_premiere_connexion_de_l_expert_comptable_previent_le_proprietaire`
- `test_le_champ_du_mot_de_passe_de_la_page_du_lien_porte_l_asterisque_des_champs_obligatoires`
- `test_le_profil_garde_l_adresse_obligatoire_d_un_expert_comptable`
- `test_la_nouvelle_adresse_d_un_expert_comptable_attend_son_lien_et_l_ancienne_est_prevenue` (P13 ; d'ici là, un lien du propriétaire part à l'ancienne)

### 9.3 `tests/Feature/Cabinets/AccesDeLExpertComptableTest.php`

- `test_l_expert_comptable_ouvre_les_magasins_reels_presents_et_futurs_avec_les_autorisations_declarees`
- `test_l_expert_comptable_n_ouvre_ni_l_espace_de_la_societe_ni_la_demonstration_ni_la_caisse_ni_l_espace_d_un_cabinet`
- `test_l_expert_comptable_d_une_societe_sans_magasin_reel_arrive_sur_une_page_qui_le_lui_dit` (route `filament.app.aucun-magasin`, jamais 404)
- `test_l_expert_comptable_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur`
- `test_l_expert_comptable_telecharge_les_pdf_et_exporte_le_journal_des_evenements_a_son_nom_avec_son_cabinet`
- `test_l_expert_comptable_n_envoie_aucun_e_mail_a_un_client_et_ne_recoit_aucune_alerte_de_stock`
- `test_avec_les_achats_declares_l_expert_comptable_cree_et_valide_une_facture_d_achat_a_son_nom`
- `test_l_expert_comptable_suit_la_lecture_seule_de_la_societe_et_de_ses_magasins`
- `test_la_base_refuse_une_vente_ou_un_encaissement_au_nom_d_un_expert_comptable_de_la_societe`
- `test_un_expert_comptable_n_a_que_les_autorisations_declarees_quelles_que_soient_les_lignes_en_base`
- `test_l_expert_comptable_a_exactement_les_droits_d_un_compte_de_cabinet_aux_memes_autorisations`
- `test_chaque_ressource_et_page_du_panneau_app_a_une_reponse_attendue_pour_un_expert_comptable`
- `test_deux_experts_comptables_de_la_meme_societe_signent_chacun_leurs_consultations`
- `test_un_nouveau_magasin_inscrit_les_droits_implicites_des_experts_comptables_ouverts`
- `test_un_compte_d_expert_comptable_retire_garde_son_role_et_son_identifiant_sans_aucun_droit`
- `test_l_alerte_des_factures_a_encaisser_suit_l_autorisation_de_voir_celles_de_toute_l_equipe`
- `test_l_expert_comptable_voit_l_identite_et_les_reglages_fiscaux_de_la_societe_en_lecture_seule` (P10)
- `test_le_tableau_de_bord_dit_expert_comptable_declare_par_la_societe_et_le_nom_du_magasin`
- `test_entrer_par_les_autorisations_d_un_cabinet_vaut_pour_un_compte_de_cabinet_et_pour_un_expert_comptable`
- `test_l_expert_comptable_n_ouvre_aucun_magasin_d_une_autre_societe`
- `test_l_url_d_un_document_d_un_autre_magasin_de_la_societe_repond_404_a_l_expert_comptable`

### 9.4 `tests/Feature/Cabinets/RattachementDesExpertsComptablesTest.php`

- `test_quand_le_cabinet_du_siren_devient_verifie_la_declaration_devient_le_lien_avec_les_memes_autorisations_sans_copie` (même identifiant de ligne, mêmes lignes d'autorisations)
- `test_au_rattachement_les_comptes_d_expert_comptable_sont_retires_avec_leur_historique_et_leurs_liens_effaces`
- `test_le_rattachement_s_inscrit_au_jet_130_de_chaque_magasin_reel_et_aux_journaux_des_deux_societes`
- `test_le_rattachement_part_de_la_saisie_du_code_de_la_verification_de_l_ordre_et_du_constat_de_l_editeur` (fournisseur de données : chacun des trois gestes en dernier)
- `test_le_job_de_la_premiere_validation_ne_rattache_rien_et_celui_de_la_seconde_rattache` (SQLite, `Queue::fake()` : saisie du code puis son job, rien ; vérification de l'Ordre puis son job, rattachée ; puis dans l'autre ordre)
- `test_un_cabinet_radie_puis_constate_par_l_editeur_rattache_les_declarations_en_attente`
- `test_un_cabinet_qui_n_est_pas_entierement_verifie_ne_rattache_rien_et_n_apprend_rien`
- `test_un_cabinet_suspendu_ne_rattache_rien_et_la_tache_horaire_rattache_des_sa_reactivation` (statut écrit en base, faute de geste avant l'étape 13)
- `test_une_declaration_dont_toutes_les_personnes_sont_suspendues_ne_se_rattache_pas` (P11)
- `test_reactiver_ou_reintegrer_une_personne_relance_le_rattachement` (P11)
- `test_la_declaration_d_une_societe_suspendue_se_rattache_et_reste_en_lecture_seule` (C33)
- `test_le_rattachement_retire_les_demandes_en_cours_entre_la_societe_et_ce_cabinet`
- `test_le_rattachement_previent_les_deux_proprietaires_et_les_personnes_dont_le_compte_est_ferme`
- `test_l_avis_et_l_e_mail_du_rattachement_disent_quoi_faire_si_le_cabinet_n_est_pas_le_bon`
- `test_une_messagerie_en_panne_apres_le_rattachement_ne_perd_ni_l_avis_ni_l_e_mail_de_fermeture` (le rattachement reste fait ; chaque tâche en file est rejouée seule et n'envoie qu'une fois)
- `test_au_rattachement_aucun_avis_ni_journal_d_audit_du_cabinet_ne_nomme_les_comptes_fermes` (P8)
- `test_apres_le_rattachement_seul_le_proprietaire_du_cabinet_voit_le_client_jusqu_a_ce_qu_il_le_confie`
- `test_au_rattachement_le_client_parait_dans_l_espace_du_cabinet_avec_l_origine_de_son_lien`
- `test_la_fin_du_lien_ne_d_un_rattachement_ne_rouvre_ni_la_declaration_ni_les_comptes` (P7 ; radiation et révocation du cabinet)
- `test_un_rattachement_rejoue_ne_change_rien`
- `test_la_tache_horaire_rattrape_un_rattachement_manque` (`Queue::fake()` pour le job, puis la commande)
- `test_le_rattachement_d_un_siren_envoie_un_job_par_declaration_et_l_echec_d_une_societe_n_arrete_pas_les_autres`
- `test_le_job_d_une_declaration_rattache_si_le_cabinet_est_devenu_verifie_entre_temps`
- `test_le_rattachement_ne_laisse_aucune_trace_du_visiteur_dans_les_journaux_des_deux_societes`
- `test_designer_le_cabinet_declare_le_rattache_aussitot_au_nom_du_proprietaire`
- `test_accepter_la_demande_du_cabinet_du_siren_declare_rattache_la_declaration_avec_ses_autorisations` (et avec des autorisations ajustées sur la page)
- `test_plusieurs_societes_qui_declarent_le_meme_siren_sont_rattachees_chacune_dans_sa_transaction`
- `test_seules_les_declarations_du_siren_du_cabinet_se_rattachent`
- `test_la_portee_des_cabinets_verifies_la_garde_du_rattachement_et_est_un_cabinet_verifie_disent_la_meme_chose`
- `test_chaque_geste_qui_peut_rendre_un_cabinet_verifie_envoie_le_rattachement`
- `test_chaque_declaration_et_chaque_remplacement_envoient_le_rattachement_du_siren_declare` (`Queue::fake()`, `assertPushed`)
- `test_un_cabinet_dont_le_siren_est_corrige_rattache_les_declarations_de_son_nouveau_siren_une_fois_reverifie_et_garde_ses_liens` (lot A7b)

### 9.5 `tests/Feature/Cabinets/ExpertsComptablesConcurrenceTest.php` (PostgreSQL)

Les deux premiers opposent des gestes sans verrou commun : ils prouvent l'absence d'interblocage et l'issue unique, pas l'ordre des commits, que le test déterministe de 9.4 couvre.

- `test_la_saisie_du_code_et_la_verification_de_l_ordre_simultanees_rattachent_la_declaration_une_seule_fois`
- `test_une_declaration_pendant_la_verification_de_l_ordre_du_cabinet_de_ce_siren_finit_rattachee_ou_refusee_jamais_en_attente`
- `test_la_verification_d_un_cabinet_pendant_que_le_proprietaire_change_de_siren_declare_ne_rattache_que_le_siren_en_vigueur`
- `test_deux_rattachements_simultanes_de_la_meme_declaration_n_en_font_qu_un`
- `test_un_rattachement_et_le_retrait_de_la_declaration_simultanes_ne_laissent_qu_une_issue` (fournisseur de données : avec et sans magasin réel)
- `test_un_rattachement_et_la_reactivation_d_un_compte_d_expert_comptable_simultanes_laissent_le_compte_retire`
- `test_un_rattachement_et_le_reglage_des_autorisations_simultanes_ne_perdent_aucun_reglage`
- `test_l_ajout_d_une_personne_pendant_le_rattachement_ne_laisse_aucun_compte_d_expert_comptable_ouvert`
- `test_le_lien_du_mot_de_passe_suivi_pendant_le_rattachement_ne_rouvre_pas_le_compte`
- `test_le_meme_lien_suivi_deux_fois_en_meme_temps_ne_sert_qu_une_fois`
- `test_le_renvoi_du_lien_pendant_que_la_personne_suit_l_ancien_ne_laisse_qu_un_mot_de_passe`
- `test_la_correction_de_l_adresse_pendant_que_la_personne_choisit_son_mot_de_passe_passe_avant_ou_apres`
- `test_deux_retraits_simultanes_des_deux_dernieres_personnes_mettent_fin_a_la_declaration` (P11)
- `test_une_declaration_et_l_acceptation_de_la_demande_d_un_autre_cabinet_simultanees_ne_laissent_qu_un_lien_en_cours`
- `test_la_revocation_du_client_pendant_le_rattachement_ne_laisse_aucun_lien_actif_ni_compte_ouvert` (avec et sans magasin réel)
- `test_la_revocation_du_cabinet_pendant_un_rattachement_ne_laisse_aucun_lien_actif` (le rattachement tient `cabinets FOR SHARE` ; la révocation attend, puis met fin au lien né du rattachement dans son second temps, sans interblocage ; sur le modèle de `CabinetsConcurrenceTest::test_une_acceptation_de_lien_pendant_la_revocation_du_cabinet_ne_laisse_aucun_lien_actif`, l.366 au commit 137334e)
- `test_le_retrait_de_l_ordre_pendant_un_rattachement_ne_s_interbloque_pas_et_ne_laisse_aucun_lien_actif`
- `test_deux_ajouts_de_la_meme_personne_avec_le_meme_jeton_ne_creent_qu_un_compte`
- `test_suspendre_un_expert_comptable_pendant_qu_il_valide_un_achat_passe_avant_ou_apres`
- `test_une_facture_d_achat_validee_par_l_expert_comptable_pendant_le_retrait_de_cette_autorisation_passe_avant_ou_apres`
- `test_un_rattachement_pendant_l_export_du_journal_par_l_expert_comptable_passe_tout_entier_avant_ou_apres`

Avec P12 (b) ou (c) s'ajoutent : `test_la_cloture_d_une_inscription_pendant_un_rattachement_ne_s_interbloque_pas` et `test_la_saisie_du_code_par_une_autre_personne_pendant_un_rattachement_ne_s_interbloque_pas`.

### 9.6 Gardes en base (ajouts)

`tests/Feature/Authorization/GardesDesCabinetsEnBaseTest.php` :
- `test_la_base_refuse_une_declaration_avec_un_cabinet_sans_siren_ou_vers_son_propre_siren`
- `test_la_base_refuse_une_declaration_dans_un_cabinet`
- `test_la_base_refuse_une_declaration_dans_une_societe_qui_attend_son_code` (P12)
- `test_la_base_refuse_une_declaration_a_cote_d_un_lien_actif_ou_d_une_autre_declaration`
- `test_la_base_refuse_de_rattacher_une_declaration_a_un_cabinet_d_un_autre_siren`
- `test_la_base_refuse_de_rattacher_une_declaration_a_un_cabinet_non_verifie` (fournisseur de données : inscription qui attend son code, Ordre non vérifié, cabinet radié, cabinet suspendu)
- `test_une_correction_du_siren_du_client_ne_bloque_pas_la_fin_de_sa_declaration` (la comparaison au SIREN de la société ne vaut qu'à l'insertion)
- `test_la_base_refuse_de_changer_le_siren_declare_l_origine_ou_le_cabinet_d_un_lien`
- `test_la_base_refuse_qu_une_declaration_prenne_fin_ou_se_rattache_avec_un_compte_d_expert_comptable_ouvert`
- `test_la_base_refuse_un_compte_attache_qui_n_est_pas_expert_comptable_ou_a_une_declaration_terminee`
- `test_la_base_refuse_de_rouvrir_un_compte_d_expert_comptable_dont_la_declaration_n_est_plus_en_attente`
- `test_la_base_refuse_qu_un_compte_prenne_ou_quitte_le_role_d_expert_comptable`
- `test_la_base_refuse_un_expert_comptable_ouvert_sans_adresse_mais_pas_un_compte_retire`
- `test_la_base_refuse_un_lien_du_mot_de_passe_sans_echeance_ou_hors_d_une_declaration_en_attente`
- `test_la_base_refuse_d_effacer_ou_de_changer_la_date_du_premier_mot_de_passe_choisi`
- `test_la_base_refuse_la_suppression_d_un_compte_attache_a_une_declaration`

`tests/Feature/Authorization/GardesDesDroitsEnBaseTest.php` :
- `test_la_base_refuse_un_expert_comptable_dans_un_cabinet_avec_une_affectation_une_autorisation_de_societe_ou_son_identifiant_pour_mot_de_passe`

### 9.7 Isolation des sociétés et des magasins

- `tests/Feature/Tenancy/IsolationDesSocietesTest.php` : `MODELES_DE_SOCIETE` (l.90-102) reçoit `CompteExpertComptable` ; `test_chaque_modele_de_societe_est_liste_ici` (l.104) l'exige et `test_societe_scope_echoue_ferme_sans_contexte` (l.133) le couvre.
- `tests/Feature/Cabinets/IsolationDesCabinetsTest.php` : `test_chaque_modele_d_un_cabinet_ou_d_un_lien_est_isole_par_sa_societe` (l.169) reçoit `CompteExpertComptable` ; nouveaux :
  - `test_les_lectures_des_declarations_d_un_siren_ne_lisent_que_leur_identifiant_et_leur_societe`
  - `test_le_cabinet_n_apprend_rien_des_societes_qui_ont_declare_son_siren_avant_le_rattachement`
  - `test_une_societe_n_apprend_pas_qu_une_autre_a_declare_le_meme_siren`
  - `test_la_page_du_lien_ne_lit_que_le_compte_sa_societe_et_sa_declaration` (`DB::listen` : exactement les colonnes de L3, jamais `type_activite` ni `demande_par_nom`)
  - `test_les_listes_et_le_tableau_de_bord_du_cabinet_ignorent_les_declarations`
  - `test_la_connexion_et_le_nom_fige_d_un_expert_comptable_ne_lisent_que_la_declaration_de_sa_societe`

### 9.8 Autres fichiers existants (ajouts)

- `tests/Feature/Authorization/ControleDesDroitsTest.php` : `test_un_expert_comptable_n_a_aucune_autorisation_de_societe_ni_rien_dans_l_espace_de_la_societe`.
- `tests/Feature/Societe/CabinetComptableTest.php` : `test_la_page_declare_un_expert_comptable_avec_ses_autorisations_en_deux_groupes`, `test_la_page_montre_l_expert_comptable_declare_ses_personnes_et_l_etat_de_leurs_liens` (page chargée en HTTP, `assertOk()`, avec une déclaration en attente), `test_une_requete_forgee_ne_declare_ni_n_ajoute_pour_une_autre_societe`.
- `tests/Feature/Societe/MembresTest.php` : `test_la_liste_de_l_equipe_montre_l_expert_comptable_et_l_etat_de_son_lien_au_proprietaire_seul`, `test_la_fiche_d_un_expert_comptable_redonne_l_acces_en_sa_presence_ou_par_un_lien_seulement`.
- `tests/Feature/Cabinets/CommandesDesCabinetsTest.php` : `test_la_commande_horaire_rattache_chaque_siren_dont_le_cabinet_est_verifie_et_rien_d_autre`.
- `tests/Feature/Tenancy/NomPourLHistoriqueTest.php` : `test_le_nom_fige_d_un_expert_comptable_de_la_societe_porte_le_cabinet_declare_et_se_lit_sans_contexte` ; `test_le_nom_pour_l_historique_ne_depasse_jamais_255_caracteres` (l.59) couvre aussi ce format.
- `tests/Feature/Tenancy/AccesAuxPanneauxTest.php` : `test_un_expert_comptable_de_la_societe_ouvre_ses_magasins_reels_et_jamais_l_espace_societe_ni_le_cabinet`, sur le modèle de l.219.
- `tests/Feature/Cabinets/InvitationsTest.php` : `test_un_siren_tenu_par_un_cabinet_est_refuse_par_un_message_neutre` (l.170) reste le test nommé de R19, inchangé ; ajout `test_un_siren_tenu_par_un_cabinet_qui_attend_son_code_est_refuse_par_le_meme_message`.
- `tests/Feature/Societe/JournalAuditResourceTest.php` (lot A7, commit 0772af0) : `test_l_expert_comptable_de_la_societe_n_ouvre_jamais_le_journal_d_audit` et `test_les_entrees_de_l_expert_comptable_de_la_societe_nomment_leur_objet` (`AfficheLeJournalDAudit::OBJETS`).
- `tests/Feature/Calendrier/PlanificateurTest.php` : `TACHES` (l.28-48) reçoit `['app:rattacher-experts-comptables', '40 * * * *']`.

### 9.9 Corrections d'écran (R20, R21, R22)

- `tests/Feature/Societe/TypeDActiviteTest.php` : `test_le_rappel_du_choix_definitif_prend_en_charge_la_franchise_en_base_et_garde_le_refus_des_activites_exonerees` (texte mot pour mot sur l'inscription, la page du lien d'inscription et la page d'une invitation ; l'ancienne phrase absente).
- `tests/Feature/Societe/InscriptionSocieteTest.php` : `test_le_mot_de_passe_de_l_inscription_porte_l_asterisque_des_champs_obligatoires` ; `test_apres_le_lien_le_proprietaire_arrive_sur_la_liste_de_ses_premieres_etapes` (l.183-195) reste valable tel quel (aucun magasin : « Créer votre premier magasin »).
- `tests/Feature/Societe/PageDuLienTest.php` : `test_le_mot_de_passe_de_la_page_du_lien_porte_l_asterisque_des_champs_obligatoires`.
- `tests/Feature/Societe/ConnexionParIdentifiantTest.php` : `test_le_premier_mot_de_passe_choisi_a_la_connexion_porte_l_asterisque_des_champs_obligatoires`.
- `tests/Feature/Cabinets/AccepterLInvitationTest.php` : `test_la_page_de_l_invitation_marque_d_un_asterisque_chaque_champ_obligatoire`.
- `tests/Feature/Societe/VerificationSocieteTest.php` : `test_la_page_du_code_marque_d_un_asterisque_chaque_champ_obligatoire` (et pas la confirmation facultative).
- `tests/Feature/Filament/ModifierProfilTest.php` : `test_le_profil_d_un_vendeur_ne_marque_obligatoire_que_le_mot_de_passe_actuel_et_la_confirmation_d_un_nouveau_mot_de_passe`.
- `tests/Feature/Societe/PanneauSocieteTest.php` : `test_l_etape_des_magasins_dit_creer_votre_premier_magasin_puis_ajouter_un_magasin` (aucun magasin, puis un, puis trois ; titre, coche, « 1 magasin », « 3 magasins », lien de création) et `test_une_declaration_seule_ne_coche_pas_l_etape_de_l_equipe`.

### 9.10 Invariants et matrice

- `tests/Feature/Societe/TransfererProprieteTest.php` : `test_chaque_societe_a_exactement_un_proprietaire_actif_apres_chaque_action_et_procedure` (l.545) passe aussi par la déclaration, l'ajout, le remplacement, le rattachement et la fin d'une déclaration.
- `tests/Feature/Authorization/PorteeDesDroitsTest.php` : `matrice()` (l.229) reçoit les lignes de l'expert-comptable (propriétaire : suspendre, réactiver, retirer, réintégrer, redonner l'accès, voir l'identifiant, corriger l'adresse ; administrateur et responsable : aucun ; changer le rôle : personne) ; `test_les_regles_de_la_matrice_sont_respectees_geste_par_geste` (l.321) les vérifie.

### 9.11 Tests existants réécrits avec la même intention

| Test | Nouvelle forme |
|---|---|
| `GardesDesCabinetsEnBaseTest::test_la_base_refuse_un_second_lien_actif_pour_un_client` (l.76) | `test_la_base_refuse_un_second_lien_en_cours_actif_ou_declare_pour_un_client` |
| `GardesDesCabinetsEnBaseTest::test_la_base_refuse_une_autorisation_hors_du_cabinet_ou_sur_un_lien_inactif` (l.85) | `test_la_base_refuse_une_autorisation_hors_du_cabinet_ou_sur_un_lien_ni_actif_ni_declare` |
| `GardesDesCabinetsEnBaseTest::test_la_base_refuse_un_lien_dont_le_cabinet_n_est_pas_un_cabinet_ou_dont_le_client_est_un_cabinet_ou_vers_soi` (l.42) | même nom, plus un cabinet nul hors d'une déclaration |
| `GardesDesCabinetsEnBaseTest::test_la_base_refuse_une_transition_de_lien_non_permise_et_la_suppression_d_un_lien` (l.62) | même nom, plus `declare` → `refuse`, `retire`, `expire`, et `actif` → `declare` |
| `GardesDesCabinetsEnBaseTest::test_les_transitions_permises_passent` (l.138) | plus `declare` → `actif` (vers un cabinet vérifié de ce SIREN) et `declare` → `termine` |
| `GardesDesDroitsEnBaseTest::test_la_base_refuse_de_rendre_proprietaire_ou_collaborateur_hors_d_un_cabinet_un_compte_qui_garde_des_droits` (l.163) | plus l'expert-comptable de la société parmi les rôles sans affectation |
| `GardesDesDroitsEnBaseTest::test_les_transitions_permises_passent` (l.190) | plus la création, la suspension et le retrait d'un expert-comptable |
| `RenouvellementDAccesTest::test_le_responsable_redonne_l_acces_d_un_vendeur_de_son_magasin` (l.61), `test_en_presence_la_personne_choisit_son_mot_de_passe_et_il_suit_la_regle` (l.360), `test_en_absence_le_mot_de_passe_redevient_l_identifiant` (l.414), `test_redonner_l_acces_ferme_les_sessions_coupe_le_rappel_et_s_inscrit_au_journal_d_audit` (l.456) | `acces_redonne_mode` et `mode` à la place d'`acces_redonne_en_presence` (assertions l.76, l.387, l.424) et d'`en_presence` (l.469) ; les appels à `RedonnerLAccesAction::handle()` ne changent pas (C14) |
| `EquipeDuMagasinTest::test_le_responsable_de_son_magasin_suspend_reactive_redonne_l_acces_et_retire_un_vendeur` (l.207) | idem (assertions l.224 et l.229) |
| `CollaborateursTest::test_seul_le_proprietaire_du_cabinet_redonne_l_acces_a_un_collaborateur_et_seulement_en_sa_presence` (l.190) | `mode` = `presence` (assertion l.215) |
| `AutorisationsDesRolesTest::test_la_caisse_securisee_se_consulte_selon_le_role` (l.141, fournisseur `consultationDeLaCaisseSecurisee()` l.129) | plus la ligne « expert-comptable de la société » |
| `AutorisationsDuCabinetTest::test_chaque_reglage_des_autorisations_s_inscrit_au_jet_130_de_chaque_magasin_et_aux_deux_journaux` (l.191) | l'entrée porte aussi la liste (C19) |
| `LiensAvecUneSocieteInscriteTest::test_un_client_n_a_qu_un_cabinet_lie_et_en_lier_un_autre_met_fin_au_precedent` (l.334) | plus un expert-comptable déclaré remplacé |
| `LiensAvecUneSocieteInscriteTest::test_la_recherche_ne_montre_ni_un_cabinet_non_verifie_ni_une_societe_qui_n_est_pas_un_cabinet_et_se_limite_a_vingt` (l.72) | inchangé : il prouve que `scopeCabinetsVerifies()` garde la recherche |
| `CabinetComptableTest::test_seul_le_proprietaire_ouvre_la_page_cabinet_comptable_et_la_page_de_rattachement` (l.30) | refuse aussi l'expert-comptable de la société |
| `CabinetComptableTest::test_la_page_ne_propose_que_les_autorisations_du_cabinet_en_deux_groupes` (l.84) | les deux groupes servent aussi la déclaration |
| `ChangementDeRoleTest::test_seul_le_proprietaire_change_le_role_de_societe_d_un_compte` (l.172) | plus : jamais pour un expert-comptable |
| `ControleDesDroitsTest::test_sans_contexte_aucune_policy_de_magasin_n_accorde_rien` (l.344) | couvre aussi l'expert-comptable |
| `tests/Feature/Filament/ActionsForgeesTest.php`, `actionsMasquees()` (l.121) | plus les actions de déclaration, d'ajout, de correction de l'adresse et de renvoi d'un lien |
| `PanneauSocieteTest::test_le_tableau_de_bord_coche_chaque_premiere_etape_faite` (l.407 ; assertions l.418-434, docblock l.400-405) | après le premier magasin, l'étape s'intitule `ETAPE_AJOUTER_UN_MAGASIN` et mène à la création d'un magasin (l.429-431), R22 |

Les intentions des dix tests de l'ancien `tests/Feature/Societe/ExpertComptableTest.php` (`git show 9ce20c3:tests/Feature/Societe/ExpertComptableTest.php`) sont reprises dans 9.1 et 9.3 ; le message de commit donne la correspondance.

---

## 10. Critères de fin

1. **Cinq commits**, chacun vert sur les deux moteurs, après `php artisan migrate:fresh` et `vendor/bin/pint --dirty --format agent`, code de sortie contrôlé juste après chaque suite (`php artisan test --parallel ; echo $?`, puis la même commande avec `DB_CONNECTION=pgsql …` ; `echo $?`) :
   1. « Corrige la phrase des activités exonérées, marque d'un astérisque les mots de passe obligatoires et renomme l'étape des magasins (lot EC2 1/5) » : R20, R21, R22, 9.9 ;
   2. « Donne à la société un expert-comptable déclaré par le SIREN de son cabinet, qui choisit son mot de passe par un lien (lot EC2 2/5) » : textes de la section 8 dans `CLAUDE.md` ; README (5.8) ; rôle, énumérations, migrations, gardes, modèles, droits, déclaration avec remplacement, lien de 7 jours, connexion, pages `AucunMagasin` (route comprise) et « La société », section de « Cabinet comptable » ; tests de R1 à R11, R18, des gardes et de l'isolation ; tests existants que ces changements touchent ;
   3. « Gère les comptes de l'expert-comptable de la société et la fin de sa déclaration (lot EC2 3/5) » : ajout, réglage, statut (lien effacé à la suspension, dernière personne), réintégration, accès redonné (`acces_redonne_mode`, `parUnNouveauLien()`), correction de l'adresse, adresse changée dans le profil (P13), fin de la déclaration et vie de la société (R12, R15, R17), liste de l'équipe, fiche, profil ; tests et tests réécrits correspondants ;
   4. « Rattache tout seul l'expert-comptable déclaré au cabinet de son SIREN dès qu'il est vérifié (lot EC2 4/5) » : R13, R14, R16 ; action, jobs, notifications en file, écouteur, commande, déclencheurs (A7b compris), désignation, acceptation ; tests et concurrence ;
   5. « Relit l'expert-comptable de la société dans les commentaires et dresse sa matrice décision → test (lot EC2 5/5) » : docblocks et commentaires cités aux sections 4 à 6, contrôles 3 et 4 ci-dessous.
2. Les règles R1 à R22 sont appliquées, avec les réponses de l'éditeur à P1 à P13 ; chaque règle a au moins un test nommé, qui échoue sans elle. Le compte rendu donne la matrice : choix 1 → R8, R9, R12, 9.2 ; choix 2 → R3, `test_le_siren_declare_doit_exister_etre_actif_et_avoir_le_code_69_20z` ; choix 3 → R13, R14, R16, 9.4, 9.5 ; choix 4 → R4, R7, `test_plusieurs_personnes_du_cabinet_declare_ont_chacune_leur_compte_et_leur_identifiant_avec_les_memes_autorisations` ; § 3 → R2, R7, R10 et leurs tests ; § 4 → R19, `InvitationsTest` l.170 ; § 5 → R20, R21, R22, 9.9 ; P1 à P13 → les tests de l'option retenue ; C1 à C40 → leurs tests, quand ils en ont.
3. Les textes de la section 8 sont dans `CLAUDE.md` mot pour mot, selon les options retenues (8.10). `grep -rn "acces_redonne_en_presence\|'en_presence'" app tests database` ne rend rien ; `grep -rn "n'a jamais de compte d'expert-comptable\|jamais écrite par qui crée le compte" app database CLAUDE.md` ne rend que des phrases qui citent l'exception de l'expert-comptable de la société.
4. Chaque lecture hors société nouvelle (5.11) retire le scope explicitement, filtre, ne lit que ses colonnes, a son test `DB::listen` et figure dans « Produit » ; le relecteur liste chaque `withoutGlobalScope`, `withoutGlobalScopes` et `DB::table` du diff, et chacun est l'une d'elles ou une écriture filtrée de 5.4 à 5.6.
5. Aucun interblocage dans la suite PostgreSQL ; l'ordre des verrous de 5.1 est vérifié action par action par les relecteurs, sur un client avec et sans magasin réel.
6. `php artisan migrate:fresh` passe sur les deux moteurs ; `RetourArriereDesMigrationsTest` est vert ; chaque table changée l'est dans sa seule migration de création.
7. La grille faketime ne se lance pas pendant ce lot (D50, décision de l'éditeur du 01/11/2026) ; chaque test qui dépend d'une échéance ou d'un calendrier fige le temps à un instant écrit avec son fuseau, et aucun ne dépend de l'heure réelle.
8. Aucun code de compatibilité avec l'état d'avant (aucune lecture d'`acces_redonne_en_presence`, aucune branche pour un lien actif sans cabinet).
9. Le groupe d'e-mails réels du lot ML (« courriels-reels ») compte les deux nouveaux e-mails, la confirmation d'une nouvelle adresse d'expert-comptable et les avis en file du rattachement, et la liste dressée depuis `app/Domain/Cabinets/Mail` et `app/Domain/Cabinets/Notifications` les contient.
10. Le compte rendu signale dans « laisses » que les contrôleurs existants qui connectent une personne (`CodeDuCourrierController::saisir()` l.82-83, `AccepterLInvitationDuCabinetController::accepter()` l.79-81, `ConfirmerInscriptionController` l.128-129) appellent `Auth::login()` sans fermer la session d'un autre compte ouverte dans le même navigateur, dont la fin ne s'inscrit donc pas (JET 40) : le lot ne les change pas, l'éditeur décidera.
11. La chaîne fait ensuite la relecture adversariale par deux relecteurs, les corrections, les deux suites, pint et le commit de relecture ; un agent dédié pousse, la CI est verte ; l'étape 1 de la feuille de route n'est pas cochée.

---

## 11. Consigne au lot : tout revérifier sur son code de départ

Ce cahier cite `code-01fe217`, et les commits 0772af0 et 137334e du lot A7. Les lots L1, A7, A7b et ML passent avant EC2 et déplacent des lignes : au début du lot, et avant chaque modification, le lot relit sur son propre code de départ chaque fichier, méthode et numéro de ligne cité ici, et suit les noms réels. En particulier :

- **Révocation (lot A7, noms confirmés au commit 137334e).** `RevoquerSocieteAction` (`revoquerUnCabinet()`, l.235-266, en deux temps), commande `editeur:societe:revoquer`, `MotifDeFinDuLien::SocieteRevoquee`, `MettreFinAuLienAction::pourUnClientRevoque()` (l.88-101, aiguillage l.97) et `parLEditeur($lien, $motif, $editeur, $prevenirLeClient)` (l.74-77). La fin des liens d'un client révoqué prend aussi la déclaration en attente et retire ses comptes (5.6) ; la révocation d'un cabinet met fin aux liens nés d'une déclaration comme aux autres ; les tests de concurrence de 9.5 suivent son ordre de verrous réel.
- **Journal d'audit consultable (lot A7, noms confirmés au commit 0772af0)** : `JournalAuditResource`, `JournalAuditDuCabinetResource`, `EntreeAuditPolicy`, `AfficheLeJournalDAudit` (`OBJETS`, l.74-97 au commit 137334e) et `tests/Feature/Societe/JournalAuditResourceTest.php` : les nouvelles actions entrent dans les filtres et dans `OBJETS` (4.7), et le test de refus à l'expert-comptable de la société s'écrit dans les tests de la page.
- **Lot A7b.** `CorrigerSirenAction` accepte un cabinet et remet à faire son contrôle 69.20Z et sa vérification à l'Ordre (`a7b/decision-a7b.md` § 2.2) : chaque geste qui le revérifie sous son nouveau SIREN (noms réels sur le code de départ) envoie `RattacherLesDeclarationsDuSiren` pour le SIREN en vigueur, après son commit (R14, test de 9.4). Le refus de l'avoir de régularisation et de la facture rectificative à un compte d'un cabinet lié (§ 2.1) vaut aussi pour l'expert-comptable de la société, qui n'a de toute façon ni avoir ni vente ; la phrase qu'A7b ajoute à « Cabinets d'expertise comptable » se recopie telle quelle avant les « Remplacer » de 8.6.
- **Lectures déjà ajoutées à « Produit » par A7 et A7b** : les recopier telles qu'elles sont, et chaque « Remplacer » de la section 8 depuis le fichier réel ; un écart se signale dans le compte rendu.
- **Lot ML** : le délai SMTP `MAIL_TIMEOUT` (C26), la marche à suivre de `queue:listen` dans le README (aucune autre mention à ajouter par EC2 : ses jobs restent sous 45 secondes), et la liste des e-mails de son groupe réel, à compléter (critère 9).
- **Numéros de migration** : 100016 est pris par A7 (`changements_fin_exercice`) ; 100026 pour `comptes_expert_comptable` s'il est encore libre après A7b et ML, jamais 100019.
- **`PlanificateurTest::TACHES`** et `bootstrap/app.php` : les tâches qu'A7 a ajoutées (`PurgerVerifications` à 4 h 20) et celles d'A7b ou de ML ; une minute libre pour la tâche horaire du rattachement (40 par défaut).
- **Ordre des verrous** de `JournalDesDroits::modifierLeLien()`, de `CloreLInscriptionAction`, de `SaisirCodeDeConfirmationAction` et de la révocation, tels qu'A7 et A7b les laissent : l'ordre de 5.1 se recale sur eux sans rien inverser.

Toute règle métier que ni la décision, ni les réponses de l'éditeur, ni ce cahier ne tranchent se soumet à l'éditeur au lieu d'être choisie (règle 4 du protocole) ; tout autre changement de `CLAUDE.md` que la section 8 se propose dans « laisses ».

---

## Annexe — Suite donnée aux relectures du 01/11/2026

Retenu et appliqué ci-dessus : l'étape des magasins (R22) ; la page « Cabinet comptable » qui planterait sur une déclaration (6.1) ; l'aiguillage de `pourUnClientRevoque()` et de `pourUneSocieteQuiChangeDeMains()` (5.6) ; le lot A7b (R14, section 11) ; `RedonnerLAccesAction::handle()` gardée et `parUnNouveauLien()` (C14) ; l'acceptation de la demande du cabinet déclaré et sa désignation, qui rattachent (C31) ; les demandes envoyées retirées par une déclaration (C32) ; un job par déclaration et les avis et e-mails en file (C3) ; l'adresse dans l'empreinte hachée (5.10) ; la date du premier mot de passe choisi (C36) ; les données de la page du lien (L3, sans le nom du propriétaire ni le type de la société) ; les tests de concurrence de la révocation (9.5) ; les tests manquants (9.1 à 9.4) ; la route d'`AucunMagasin` ; le test déterministe de l'ordre des validations ; le refus de R4 avant celui de R5 ; l'auteur des retraits (C18) ; les comparaisons de SIREN à l'insertion seulement ; l'ajout inutile à `$horsDuMagasin` retiré ; l'adresse écrite à l'insertion ; la lecture de la déclaration pour le nom figé (R11, L4) ; la session d'un autre compte fermée avant la connexion ; les astérisques ; les commentaires et docblocks ; le README ; l'étape de l'équipe sans les experts-comptables ; l'auteur du rattachement par désignation ; la garde du rattachement à un cabinet vérifié (C35) ; P8 et R18 corrigés (le cabinet lit les comptes dans le journal des événements) ; le lien effacé à la suspension (C37) ; `#[SensitiveParameter]` ; « le lien de son mot de passe » partout ; les deux contrôles de rôles en PHP ; l'adresse saisie deux fois (C30) ; le libellé de l'historique (C6) ; les références à A7 et le renvoi de 5.5 ; les avertissements contre un cabinet usurpé (C29, 6.7, 6.8) ; la grille faketime retirée des critères (D50), relevée en vérifiant les textes.

Posé à l'éditeur : P11 (personnes toutes suspendues ou retirées), P12 (société qui attend son code), P13 (adresse changée dans le profil) ; la révocation d'un cabinet usurpateur entre dans P7.

Écarté, avec sa raison : rattachement différé ou confirmé par la société (la décision dit « tout de suite », choix 3) ; adresses au journal d'audit (l'adresse corrigée n'a jamais servi, puisque la correction se ferme dès le mot de passe choisi ; celle d'un compte entré reste sur le compte ; et la convention du projet n'en écrit aucune, docblock de `ConfirmerNouvelleAdresseAction`) ; comptes de Sophie gardés jusqu'à ce qu'on lui confie le client (la décision ferme les comptes au rattachement, choix 3) ; société suspendue, désigner puis déclarer, avis groupé au cabinet, demande du cabinet déclaré (choix de Claude C31 à C34, qui suivent la décision et les règles du lot EC, contestables) ; facturation des clients rattachés par leur déclaration (étape 12, notée en 8.8) ; `withoutOverlapping()` sur la tâche horaire (elle n'envoie que des jobs uniques ; aucune tâche ne s'en sert) ; une mention du `--timeout` de la file au README (lot ML) ; le type d'activité dans la lecture de la page du lien (C38).
