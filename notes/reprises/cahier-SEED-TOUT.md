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

## 4. Liste des comptes
Le fichier comptes-de-demonstration.txt (jamais suivi par git) et l'affichage de fin de semis donnent, pour chaque compte : société, magasin, rôle, identifiant, mot de passe (PILIQI_DEMO_MOT_DE_PASSE ou identifiant), et une phrase « ce qu'il faut regarder avec ce compte » (ex. « Inès Garnier, vendeuse des Halles : sa caisse, ses tickets, son relevé du jour, la carte des notifications »).

## 5. Tests (au code 0, dans le même commit)
- Test du semis (groupe à part s'il est long, lancé par la CI) : après le semis, chaque table du schéma hors tables techniques a au moins une ligne ; chaque valeur de chaque énumération d'état utilisée en base (statuts des factures, devis, achats, charges, transmissions, invitations, liens, comptes, magasins, sociétés…) apparaît au moins une fois ; chaque rôle a au moins un compte qui a des données ; chaque compte qui vend a des ventes et un relevé de caisse ; l'isolation des sociétés et des magasins tient (aucune ligne d'un magasin référencée par un autre) ; la vérification d'intégrité de la caisse (`caisse:verifier`) et celle du stock passent sur les données semées ; un second `migrate:fresh --seed` donne les mêmes nombres (semis déterministe : Faker semé, horloge du semis).
- Le test existant du semis (refus en production, fournisseurs factices, base non vide, mot de passe) reste vert.
- Navigateur : pour quelques comptes (propriétaire, administrateur, responsable, vendeur, collaborateur du cabinet, expert-comptable déclaré), se connecter avec la liste des comptes et voir une liste non vide sur les pages principales (factures, tickets, charges, Les caisses, tableau de bord avec graphiques), sans erreur.

## 6. Ensuite, pour toujours
Chaque lot suivant (DEVIS-RET, MDP, IDENT, CESSION, NAF25, VIES-DEP, puis tous les autres) applique le point 6 de CLAUDE.md : ses tables, colonnes, états et cas nouveaux ont leurs données dans le semis et dans son test, dans le même commit.
