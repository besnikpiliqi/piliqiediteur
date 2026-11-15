# Cahier du semis complet (SEED-TOUT) — demande de l'éditeur du 15/11/2026, vers 12 h à Paris

Ce cahier fait foi. Il se fait dans le lot DATEOP, EN PREMIER (après la seule réparation d'une CI rouge), dans son propre commit « Semis complet de toutes les tables et de tous les cas », avant le travail de la date de l'opération.

## 1. Les mots de l'éditeur

« pour cette parti tu creer un demo avec seed car je veux voir tout les donnes remplis deja avec seed. mais tu oublie pas ! il faut que tu fasse le seed pour tout tout et pour tout les compte separament afin que je puisse voir les donnes reel comment resemble car t as vu la aujourdhui je viens de voir qq petit defaut grace qu donnes deja existant. donc tu fais pour tous les tables (dans la base de donnees) qui existent des seed en plusieurs meme bcp de donnes en tout cas, donc il faut avoir des donnnes pour tous les cas du logiciel. met ce que je te dis en priorite dans mes regle strict pour toujours de toujours. » (capture : page « Charges » de l'espace de la société, vide).

## 2. Règle permanente à écrire dans CLAUDE.md (même commit)

Dans « Protocole de rigueur (prioritaire sur toutes les autres règles) », un nouveau point 6, mot pour mot ou plus précis, jamais plus faible :

« 6. Données de démonstration complètes (décision de l'éditeur du 15/11/2026, prioritaire et permanente) : `php artisan migrate:fresh --seed` remplit, par les vraies actions et les fournisseurs factices, toutes les tables de la base (hors tables techniques du cadre : migrations, jobs, job_batches, failed_jobs, cache, cache_locks, sessions, password_reset_tokens), avec beaucoup de données, sur au moins treize mois, et tous les cas du logiciel : chaque état de chaque document et de chaque fiche, chaque type d'activité, chaque territoire, chaque rôle et chaque compte avec ses propres données (ses ventes, sa caisse, ses relevés, ses notifications, son « À faire », ses préférences), afin que chaque écran, vu par chaque compte, montre des données réelles. Chaque lot qui crée ou change une table, une colonne, un état, un écran ou un cas met le semis à jour dans le même commit, et le test du semis le prouve ; un lot qui ne le fait pas n'est pas terminé. »

## 3. Ce que le semis doit contenir (tout, par les vraies actions)

Partir du semis actuel (database/seeders, SemisDeDemonstration, HorlogeDuSemis, ComptesSemes, liste des comptes dans storage/app/private/comptes-de-demonstration.txt, PILIQI_DEMO_MOT_DE_PASSE) et l'étendre, en gardant toutes ses protections (refus hors local/test, fournisseurs factices exigés, base vide exigée, aucun e-mail, planificateur suspendu).

### 3.1 Sociétés (au moins)
- Deux sociétés « Commerce ou atelier » validées, plusieurs magasins chacune, dont un magasin en Martinique ou en Guadeloupe (territoire DOM, taux 8,5 %, heure locale), un magasin fermé temporairement, un magasin fermé pour cessation et rouvert, un magasin cédé, un magasin suspendu par l'éditeur.
- Une société « Services, sans vente d'articles » validée (services seulement, caisse, factures, devis), dont un client professionnel public (Chorus Pro).
- Une société en franchise en base (TVA non applicable, art. 293 B).
- Une société qui a opté pour les débits.
- Une société membre d'un assujetti unique (ventes refusées, démonstration seule).
- Une société inscrite qui attend son code du courrier (avec son magasin de démonstration ouvert et utilisé), une inscription close faute de code, une demande d'inscription non confirmée.
- Une société suspendue, une société résiliée (lecture seule, clôtures finales).
- Le cabinet d'expertise comptable vérifié (propriétaire, deux collaborateurs ou plus, clients confiés), lié à au moins deux sociétés ; une invitation en attente, une acceptée, une refusée, une expirée ; une demande de rattachement en attente et une refusée ; un cabinet inscrit non vérifié à l'Ordre ; un cabinet suspendu.
- Une société qui a déclaré son expert-comptable (cabinet non inscrit) avec deux comptes d'expert-comptable, dont un qui a choisi son mot de passe et un qui ne l'a pas encore fait.
- Une transmission de la propriété en attente et une faite.

### 3.2 Comptes (chacun avec ses propres données, rien de partagé)
Propriétaire, administrateur sans vente, administrateur vendeur dans deux magasins, administrateur coché aux graphiques et aux charges, responsables (dont un coché aux graphiques et aux charges), vendeurs (dont une même personne avec un compte dans deux magasins, et un vendeur « voir celles de toute l'équipe »), observateurs, comptes d'entraînement de la démonstration, collaborateurs du cabinet, expert-comptable déclaré, comptes suspendus, retirés, réintégrés, un compte promu (vendeur → responsable) et un administrateur devenu membre ; postes reconnus ; un compte qui garde son identifiant pour mot de passe. Chaque compte qui vend a ses ventes sur plusieurs jours, sa caisse ouverte certains jours, ses relevés de caisse (fermés par lui, par un responsable, automatiquement à la clôture, à minuit) ; chacun a des notifications lues et non lues dans sa cloche, son « À faire » non vide quand son rôle le permet, ses préférences de graphiques.

### 3.3 Documents et données (sur au moins treize mois, pour « Comparer avec l'année précédente » et les graphiques)
- Factures dans tous les états : brouillon, émise, partiellement payée, payée, en retard, soldée par un avoir, réglée par un crédit d'avoir, régularisée (avoir de régularisation et rectificative après correction de SIREN), exonérée non prouvée régularisée à 150 jours ; ventes en France, export hors UE (avec et sans preuve MRN/CN23, en orange et en rouge dans « À faire »), livraison vers un DOM, livraison intracommunautaire (consultation VIES conservée), prestation autoliquidée UE, prestation hors UE ; clients particuliers, professionnels, publics, non assujettis ; client comptoir.
- Tickets de caisse en grand nombre (plusieurs par jour ouvré et par vendeur), paiements en un et plusieurs modes (espèces, CB, chèque, virement, prélèvement sur facture), monnaie rendue, bons d'avoir utilisés, crédit d'avoir imputé, contre-passations suivies du bon paiement, reçus de paiement (série RP), remises et prix dérogatoires, ventes sans stock (stock négatif), remboursement de TVA de détaxe après visa.
- Détaxe : bordereaux sans visa et avec visa.
- Avoirs : sur facture (partiel, total, successifs d'une même ligne), sur ticket, bons d'avoir non utilisés et utilisés.
- Devis dans tous les états : brouillon, envoyé, envoyé bientôt expiré (dans 7 jours), accepté (par le lien, « Bon pour accord »), refusé, expiré, converti en facture ; liens des devis et réponses.
- Achats : factures d'achat brouillon, validées, « TVA à vérifier », « pièce du fournisseur manquante », avec pièces jointes (PDF et images factices propres), achat d'un fournisseur de Monaco ; avoirs fournisseur ; inventaires d'ouverture ; fournisseurs actifs et archivés ; produits nés d'un achat et d'un inventaire, actifs et archivés, avec code-barres, stock bas et négatif ; barème de prix ; CMUP qui évolue.
- Charges (page « Charges ») : pour chaque société qui en a, chaque mois de l'année en cours et de la précédente, plusieurs types (loyer, électricité, téléphone, honoraires, carburant, véhicule, cadeaux, fournitures, types propres à la société, un type archivé), dans tous les états : validée, à valider, pièce sans chiffres, renvoyée au client avec son mot, corrigée avec motif, validée par le propriétaire (sans comptable) et par le cabinet ou l'expert-comptable ; avec et sans pièce ; plusieurs taux (20, 10, 8,5, 5,5, 2,1, 0 %, sans TVA) ; TVA déductible proposée à 0 % (véhicule de tourisme, cadeau au-delà de 73 €), à 80 % (carburant) ; fournisseur hors de France autoliquidé ; « TVA à vérifier » ; charges de « Toute la société » et de magasins ; doublon probable confirmé ; types habituels cochés.
- Caisse sécurisée : clôtures du jour pour chaque jour depuis la première vente (dimanches compris, à zéro), clôtures personnelles, registre et journal des événements signés, tickets Z, grands totaux ; e-reporting des journées ; transmissions à la plateforme agréée factice dans tous les statuts (déposée, acceptée, rejetée, non transmise : incident technique) avec leurs événements ; mandat PDP.
- Journal d'audit de chaque société (toutes catégories), traces, vérifications, codes de confirmation, écarts Sirene (dont un « C'est vérifié »), changement de fin d'exercice (procédure de l'éditeur), périodes d'activité, SIRET des magasins (dont un déménagement), envois de documents (réussis, en échec, relances), téléchargements des documents par période (prêts et expirés), notifications.
- Tout autre table du schéma (voir la liste obtenue par les migrations) : au moins une ligne par cas qu'elle porte.

### 3.4 Volume et durée
- Beaucoup de données : de l'ordre de plusieurs milliers de tickets et de centaines de factures, devis, achats et charges au total, réparties entre les comptes, assez pour que les listes aient plusieurs pages et que les graphiques aient des courbes réelles ; les nombres exacts sont écrits dans le README.
- `php artisan migrate:fresh --seed` doit finir en moins de 15 minutes sur la machine de l'éditeur (mesurer ici et écrire la durée dans le README ; si c'est plus long, une option `--seed` légère n'est PAS une réponse : optimiser).

## 3 bis. Vérification de l'isolation à la fin du semis (demande de l'éditeur du 15/11/2026, vers 12 h 10 à Paris)

Les mots de l'éditeur : « quand je lance php artisan migrate:fresh --seed, alors la normalement on doit pouvoir voir si exite des probleme de l'isolation des sociétés et des magasins si est respectée, donc quand les donnees se mettent alors la si qq donees ne va pas dans son isolation donc dans sa societe et magasin donc normalement la si une donnes va ailleur doit afficher le problem car tout les donnees appartion comme on a dis isolation par societe, magasin, responsable, vendeur, comptable etc etc etc donc tout tout ».

1. Une commande 
   ERROR  There are no commands defined in the "piliqi" namespace.   (nom libre en français, cohérent avec les commandes existantes), lançable à tout moment en local et en production (lecture seule, aucune écriture, aucune donnée personnelle affichée : seulement table, colonne, identifiants internes et motif), qui parcourt TOUTE la base et vérifie, ligne par ligne :
   - **Sociétés** : chaque ligne d'une table d'une société (BelongsToSociete) et chaque ligne d'un magasin a une société existante ; toute référence d'une ligne vers une autre ligne porte la même société (client, fournisseur, produit, document, paiement, allocation, avoir, charge, pièce, type de charge, journal d'audit, notification, préférence, téléchargement…), sauf les liens permis par CLAUDE.md (cabinet ↔ client lié, déclaration d'expert-comptable), vérifiés selon leurs propres règles.
   - **Magasins** : chaque ligne d'une table d'un magasin (BelongsToMagasin) n'a de références que vers des lignes du même magasin (clés composées), et ce magasin appartient à la société de la ligne ; aucun mouvement de stock, paiement, allocation, ligne, clôture, enregistrement fiscal ou transmission ne pointe vers un autre magasin.
   - **Comptes** : chaque compte appartient à une seule société ; chaque affectation d'un responsable, vendeur, observateur ou compte d'entraînement vise un magasin de sa société, un seul ; chaque document, paiement, relevé de caisse, clôture personnelle, envoi, téléchargement est au nom d'un compte de la même société qui avait accès à ce magasin (et, pour une vente ou un encaissement, le droit de vendre dans ce magasin ; jamais le propriétaire hors de la démonstration, jamais un compte d'un cabinet ni un expert-comptable de la société) ; un compte d'entraînement n'a rien dans un magasin réel ; une caisse personnelle ouverte à la fois par compte et par magasin.
   - **Cabinet et comptables** : un compte d'un cabinet n'a d'affectation ni de droit dans aucune société cliente ; ses gestes chez un client (charges validées, exports, consultations) ne visent que des clients liés par un lien actif à ce moment et des magasins que le client lui ouvre ; chaque client confié l'est par un lien actif ; un compte d'expert-comptable de la société n'agit que dans sa société et ses magasins déclarés.
   - **Magasin de démonstration** : rien n'en sort vers un magasin réel (aucun document, stock, client, produit partagé), ses séries et son registre sont à part.
   - **Registre et journaux** : chaque chaîne (registre fiscal, journal des événements, journal d'audit) ne contient que des maillons de son magasin ou de sa société.
1 bis. **Deux contrôles, par Laravel ET par SQL** (précision de l'éditeur du 15/11/2026, vers 12 h 20 à Paris : « car toi t as fait deja les regle strict qui controle donc par laravel et par sql donc tout les deux doivent controler les regles ») : la commande vérifie les règles par les deux couches qui les portent, et chaque problème dit laquelle l'a trouvé :
   - **Par SQL (la base)** : (a) requêtes SQL directes, sans modèle ni scope, sur chaque table, qui comparent la société et le magasin de chaque ligne à ceux des lignes qu'elle référence ; (b) la base porte bien ses protections : pour chaque table d'un magasin, la clé composée (magasin_id, …) vers l'unicité (magasin_id, id) de la table visée ; pour chaque table protégée, ses déclencheurs et ses gardes présents et actifs (pg_trigger non désactivé, fonction avec son search_path fixé), lus dans le catalogue de PostgreSQL et comparés à la liste attendue (tables en ajout seul, documents émis, stock, registre, journaux, charges validées, affectations, autorisations d'un cabinet et d'un expert-comptable, ventes au nom d'un compte autorisé) ; une protection absente, désactivée ou différente est un problème.
   - **Par Laravel (l'application)** : (c) chaque modèle d'une table d'un magasin utilise BelongsToMagasin et MagasinScope, chaque modèle d'une table d'une société BelongsToSociete et SocieteScope (lu par réflexion sur tous les modèles d'app/) ; (d) pour CHAQUE compte semé, la commande ouvre son contexte comme à sa connexion (société, magasin, cabinet, expert-comptable déclaré) et lit par les modèles et les règles d'accès de PILIQI (scopes, politiques, ControleDesDroits, AccesDesCabinets) ce qu'il voit : clients, produits, factures, tickets, devis, avoirs, paiements, achats, charges, clôtures, relevés de caisse, notifications, téléchargements ; chaque ligne qu'il voit doit être de sa société et d'un magasin qu'il a le droit de voir, et un vendeur sans « voir celles de toute l'équipe » ne voit que ses factures ; un compte d'un cabinet ne voit que ses clients liés et leurs magasins ouverts ; puis la commande compare à ce que SQL dit qu'il devrait voir : une ligne vue de trop (fuite) ou une ligne due qui manque est un problème.
   - Les deux couches doivent être d'accord : une donnée que l'une accepte et l'autre refuse est signalée comme « désaccord entre Laravel et la base ».
   - Tests : en plus des fautes de données (point 4), un test par protection de la base retirée ou désactivée dans une base de test (signalée par le contrôle SQL), un test par modèle privé de son scope (signalé par le contrôle Laravel) et un test d'un compte qui verrait une ligne d'un autre magasin (fuite signalée).
2. La commande affiche, en français, un tableau des contrôles (contrôle, nombre de lignes vérifiées, nombre de problèmes) puis chaque problème trouvé (table, identifiant, colonne, valeur attendue, valeur trouvée, règle de CLAUDE.md qu'il viole) ; elle sort avec le code 0 si tout est juste, 1 sinon.
3. **Le semis la lance tout seul à la fin** : 

   Illuminate\Database\QueryException 

  SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "piliqi"
connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "piliqi" (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: piliqi, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:864
    860▕             $exceptionType = ($isUniqueConstraintError = $this->isUniqueConstraintError($e))
    861▕                 ? UniqueConstraintViolationException::class
    862▕                 : QueryException::class;
    863▕ 
  ➜ 864▕             $exception = new $exceptionType(
    865▕                 $this->getNameWithReadWriteType(),
    866▕                 $query,
    867▕                 $this->prepareBindings($bindings),
    868▕                 $e,

      [2m+50 vendor frames [22m

  51  artisan:16
      Illuminate\Foundation\Application::handleCommand() se termine par ce tableau ; s'il y a un problème, il l'affiche en rouge et la commande sort avec le code 1 (le semis n'est pas accepté). La liste des comptes ne s'affiche qu'après un contrôle réussi.
4. **Elle doit vraiment trouver les problèmes** : un test par contrôle introduit volontairement une ligne fautive (par une écriture SQL de test qui contourne les gardes quand elles existent, dans une base de test) et vérifie que la commande la signale avec le bon motif et le code 1 ; un test vérifie le code 0 et zéro problème sur les données semées ; un test vérifie que la commande n'écrit rien et ne montre aucune donnée personnelle (ni nom, ni adresse, ni montant). Les contrôles se lisent depuis le schéma réel (tables qui ont societe_id ou magasin_id, clés étrangères) pour qu'une table nouvelle soit vérifiée sans qu'on pense à l'ajouter ; une table nouvelle sans règle connue fait échouer le test jusqu'à ce que sa règle soit écrite.
5. CLAUDE.md, le point 6 du protocole de rigueur ajoute : « … et le semis se termine par la vérification de l'isolation de toute la base (sociétés, magasins, comptes, cabinet, comptables, démonstration), qui doit trouver zéro problème. »
6. README : la commande, ce qu'elle vérifie, comment lire son tableau.

## 4. Liste des comptes
Le fichier comptes-de-demonstration.txt (jamais suivi par git) et l'affichage de fin de semis donnent, pour chaque compte : société, magasin, rôle, identifiant, mot de passe (PILIQI_DEMO_MOT_DE_PASSE ou identifiant), et une phrase « ce qu'il faut regarder avec ce compte » (ex. « Inès Garnier, vendeuse des Halles : sa caisse, ses tickets, son relevé du jour, la carte des notifications »).

## 5. Tests (au code 0, dans le même commit)
- Test du semis (groupe à part s'il est long, lancé par la CI) : après le semis, chaque table du schéma hors tables techniques a au moins une ligne ; chaque valeur de chaque énumération d'état utilisée en base (statuts des factures, devis, achats, charges, transmissions, invitations, liens, comptes, magasins, sociétés…) apparaît au moins une fois ; chaque rôle a au moins un compte qui a des données ; chaque compte qui vend a des ventes et un relevé de caisse ; l'isolation des sociétés et des magasins tient (aucune ligne d'un magasin référencée par un autre) ; la vérification d'intégrité de la caisse (`caisse:verifier`) et celle du stock passent sur les données semées ; un second `migrate:fresh --seed` donne les mêmes nombres (semis déterministe : Faker semé, horloge du semis).
- Le test existant du semis (refus en production, fournisseurs factices, base non vide, mot de passe) reste vert.
- Navigateur : pour quelques comptes (propriétaire, administrateur, responsable, vendeur, collaborateur du cabinet, expert-comptable déclaré), se connecter avec la liste des comptes et voir une liste non vide sur les pages principales (factures, tickets, charges, Les caisses, tableau de bord avec graphiques), sans erreur.

## 6. Ensuite, pour toujours
Chaque lot suivant (DEVIS-RET, MDP, IDENT, CESSION, NAF25, VIES-DEP, puis tous les autres) applique le point 6 de CLAUDE.md : ses tables, colonnes, états et cas nouveaux ont leurs données dans le semis et dans son test, dans le même commit.
