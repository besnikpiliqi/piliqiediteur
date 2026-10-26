# Historique du projet PILIQI (2 au 26 octobre 2026)

Ce fichier raconte tout le travail fait sur PILIQI depuis le premier message de l'éditeur, le 2 octobre 2026, jusqu'au 26 octobre 2026 à 12 h 45 UTC : ce qui a été demandé, conçu, livré et décidé, ce qui a mal tourné, et les règles qui en sont sorties.

À lire après `README.md` (la fiche de reprise). Ce fichier raconte ; il ne décide rien. Pour toute règle, `CLAUDE.md` de la branche de travail et les fichiers de décision de `notes/` font foi (ordre de priorité au § 7 du README).

Sources : les 18 résumés successifs de la session, les messages de l'éditeur (datés), les notes `etat-chaines.md` et le journal git de la branche `claude/saas-invoice-editor-architecture-q6eizh` (255 commits au 26/10 à 12 h 45 ; tête poussée `fa83824`). Les heures sont en UTC. Aucune valeur secrète n'est recopiée ici.

---

## 1. Vue d'ensemble

| Période | Ce qui s'est passé | Repères git |
|---|---|---|
| Ven 2 – dim 4/10 | Premier logiciel construit à partir du « prompt maître » : un magasin = un locataire. L'éditeur l'essaie sur son PC et envoie des captures. | `6b49387` … `79fcb05` |
| Lun 5 – jeu 8/10 | Chasse aux écarts de centimes, méthode de calcul de l'éditeur, services, CMUP, barème de prix, schéma propre. `CLAUDE.md` reçoit les règles du projet, le protocole de rigueur et la feuille de route. SUPER PDP est retenue. Mandat d'autonomie totale. | `7146e00` … `74288f3` |
| Ven 9 – dim 11/10 | Passage aux chaînes d'agents (implémentation, deux relectures, correction). Refonte des prix, étape 4, audit en 7 lots, cœur de la caisse sécurisée, 85 décisions recueillies, lots de décisions 1 à 20, passage à PostgreSQL, architecture société A0 à A3. | `9eacfd9` … `e2f8d17` |
| Lun 12/10 | Lot A4 (Spatie retiré), puis limites d'usage. | `5533743` … `30844db` |
| Mar 13 – ven 16/10 | Arrêt complet : limite hebdomadaire et plafond mensuel de dépense. | aucun commit |
| Sam 17 – dim 18/10 | A4 relu, A4b, A5, A6, série 8 (R6, S8-T à S8-V), A5c, T1. Panne de CI (quota), redémarrages du conteneur. | `8a22d55` … `01b7483` |
| Lun 19/10 | A4c. Décisions : vérification par courrier recommandé (V2) et espace des cabinets (EC). | `bd0d6ef`, `41fd0a5` |
| Mar 20 – ven 23/10 | Arrêt (limite hebdomadaire). Une autre session, sur un autre compte, pousse M1 et M0. | `67f43e9`, `3fede71` |
| Sam 24 – dim 25/10 | Annulation de M0 et M1, V2, V2b, FA1 à FA3, T2. Décisions TA, EC, T2, T3, PU. | `5cbe4e2` … `c723e1f` |
| Lun 26/10 | T2 relu et poussé ; T3 commité, relu, corrigé et poussé ; branche de reprise créée ; PU commence. | `3a02fbc` … `fa83824` |

---

## 2. Chronologie détaillée

### Semaine 1 : vendredi 2 au dimanche 4 octobre

#### Vendredi 2/10 : le prompt maître et le socle

- 21 h 06 : l'éditeur colle un « PROMPT MAÎTRE » de 46 sections (SaaS de facturation multi-entreprises sous Laravel). Il précise ensuite : il est l'éditeur ; les magasins s'inscrivent chez lui et sont les locataires ; ils ont des clients particuliers et professionnels ; leurs factures d'achat doivent arriver par API ; les retours aux fournisseurs baissent le stock ; le magasin donne un mandat à la plateforme ; Filament plutôt que Livewire seul. Il donne carte blanche : « tu fais beaucoup mieux que ce que je t'ai dit ».
- Construit et poussé le soir même : Laravel 13 et le socle multi-locataire (`MagasinScope` qui refuse de répondre sans magasin courant, `TenantContext`, `BelongsToMagasin`), Laravel Boost, inscription qui crée le magasin, devis, factures, numérotation, stock, passerelle PDP factice, ressources Filament.
- Correctif critique `44d431d` : sans `FilamentUser::canAccessPanel`, tout utilisateur aurait reçu une erreur 403 hors de l'environnement local. Trouvé grâce à un vrai navigateur (Playwright).
- Choix de départ abandonnés depuis : Spatie laravel-permission (retiré le 12/10), MySQL pour la production (PostgreSQL depuis le 10/10), compte d'employé partagé entre magasins (un compte par magasin depuis le lot 2 des décisions, le 09/10).
- Outillage : Pest était incompatible, d'où PHPUnit ; Larastan ne s'installait pas derrière le proxy.

#### Samedi 3/10 : paiements, avoirs, clôtures

- Règles de l'éditeur : une facture ne se supprime jamais, « interdit par la loi » (`f93331d`) ; un vendeur est créé par le propriétaire, sans confirmer d'e-mail (`c24e8ba`) ; un devis ou une facture peut avoir des lignes libres sans produit (`d75e25f`, supprimées le 08/10).
- Livré : paiements et imputations, passage automatique en retard, avoirs, conversion d'un devis en facture, distinction ticket (particulier) et facture (professionnel) avec un client comptoir (`6963123`), clôture de caisse journalière et clôture automatique à minuit, confidentialité des ventes par vendeur.
- L'éditeur interdit d'abord toute modification de facture (`3db5daf`), puis se reprend le soir : un brouillon se modifie et se supprime en entier (`2d3a32a`).
- Consignes de rédaction : ne jamais écrire « magasin » dans un message à l'utilisateur (« La journée du … a déjà été clôturée », `cd012d1`) ; avertir que la clôture est irréversible ; ne pas lui dire « vous, le propriétaire », car il n'est pas propriétaire d'un magasin.
- Recherche sur l'e-reporting : il n'est pas quotidien. La clôture du jour est découplée de la transmission (`6fb0ac2`). Avoir partiel par lignes (`dcedb42`). Fondations des achats et des avoirs fournisseur (`dd2f72f`).

#### Dimanche 4/10 : la vraie caisse et le premier essai en local

- Nuit : aucun magasin ne doit pouvoir deviner qu'un compte d'employé sert ailleurs (`d9144d3`) ; la fermeture de caisse d'un vendeur est séparée de la clôture du jour (`5cc6813`) ; un devis reste modifiable jusqu'à sa conversion ; un avoir peut regrouper plusieurs factures ; une facture d'achat est saisie à la main ou reçue par API.
- Matin : l'éditeur installe le projet sur son PC Windows (XAMPP, VS Code, un nouveau dossier de projet sous `htdocs`). Node trop ancien, `vendor` abîmé, erreurs 500 : tout est résolu. Il essaie l'application toute la journée et envoie des captures.
- Livré : création rapide d'un produit depuis une ligne (supprimée le 08/10), champs légaux d'un client professionnel, clôture du jour sans choix de date, vraie caisse de comptoir (`3063101`), numérotation séparée des tickets et des factures (`d399159`), référence produit facultative et générée.
- Incident CI : la CI échouait en silence depuis le début. Un avertissement PHPUnit donne le code de sortie 1 alors que le résumé affiche « passed ». Corrigé par `b49e97c`. L'éditeur reçoit les courriels d'échec de la PR #1 et demande ce qu'est une PR.
- Incident dépôt public : l'éditeur avait rendu le dépôt public. Il ne le veut pas (« Ce logiciel à moi et que à moi ») et le remet en privé. Vérifié : aucun secret n'avait été commité. Aide pour l'authentification Git sous Windows.
- Soir : premier écart d'un centime (« monnaie à rendre 5,01 € ») : calcul en centimes entiers (`055688a`). Champs calculés en direct. Crédit d'avoir imputé automatiquement (`a69e2f1`). Clôture bloquée tant qu'une caisse personnelle est ouverte (`51b1689`, remplacé le 11/10 par la fermeture d'office). Paiement supérieur au restant dû refusé : l'éditeur rejette l'idée d'un trop-perçu en crédit (« non, je veux pas mettre plus que la somme due », `79fcb05`).

### Semaine 2 : lundi 5 au dimanche 11 octobre

#### Lundi 5/10 : jamais d'erreur brute, et la chasse au centime

- « Il ne faut pas afficher ce genre d'erreur aux magasins, mais des alertes, partout » : toute erreur métier devient une notification (trait `GereLesExceptionsMetier`, `7146e00`).
- Après une explication sur des cas « difficiles à tester », l'éditeur exige : « Il faut que tu le fasses. Les tests. » Trois cas réels sont trouvés et testés (`25feb0a`).
- Retour à la liste après modification, échéance préremplie à aujourd'hui, colonne « Payé » qui compte le crédit d'avoir.
- L'éditeur constate qu'une facture émise peut être « annulée », contrairement à ce qu'il voulait. Corrigé le 09/10 : correction par avoir seulement (`dfef273`).
- Écarts de centimes répétés : PU HT à 4 décimales (`d737e89`), prix de vente saisi en TTC, client comptoir sans crédit d'avoir. Puis « il ne doit jamais changer ce que je tape » : bug reproduit avec Playwright (« 12.5 » devenait « 15 »), corrigé par `d1a728a`.

#### Mardi 6/10 : la méthode de calcul de l'éditeur

- L'éditeur impose son calcul « article par article » (HT, TVA et TTC séparés ; sa facture devait faire 84,99 € HT et non 85,00 €) et « au moins 1000 exemples » : `40b640b`, puis `f88518b` (tests de propriétés contre brick/math, graine fixe, des dizaines de milliers de cas). Il demande si c'est « partout » : HT, TVA et TTC sont affichés partout (`138032b`). Les quantités sont toujours entières (`4a64469`).
- Deux tâches lancées depuis l'interface (« Effectuez cette tâche ici ») : retour arrière d'une migration sous SQLite, caisse personnelle qui sautait une vente.
- Recherches juridiques (e-reporting, avoirs, caisse, coût d'achat). L'éditeur : « je n'ai pas de comptable… cherche sur les sites officiels ». WebFetch et curl sont bloqués par le proxy (impots.gouv.fr) : recherche par WebSearch.
- Réparations : la pièce et la main-d'œuvre sont sur une seule facture, payée le même jour.

#### Mercredi 7/10 : services, schéma propre, premières règles du projet

- Bouton « Ajouter un service » à la caisse (`9e82c16`, `0226bb1`).
- Message clé de l'éditeur : PILIQI est un logiciel indépendant, sans lien avec la boutique où il travaille ; lui n'aura jamais de magasin et ne se donne pas d'attestation ; aucun mélange entre magasins (« zéro ») ; en développement, on repart de zéro à chaque changement. D'où `5a1d62d` (une seule migration de création par table) et `f727b12`, et la première section « Règles du projet » de `CLAUDE.md`.
- Le soir, les « 8 recommandations » mises de côté le matin lui sont présentées. Réponse : « oui pour tout » (un produit est un bien, un service est un service). Livré : dates à l'heure de Paris (`ebfde8b`), avoir imputé d'abord sur les factures qu'il corrige, clôture nette des avoirs, coût d'achat après remise et escompte, mention de l'option pour les débits, nature bien ou service de chaque ligne (`6da7189`). La 8e, la caisse sécurisée, reste à faire.
- Incidents : poussées GitHub en erreur 500 (réussies en réessayant) ; limite de session ; `pkill -f` a tué le shell de la session.

#### Jeudi 8/10 : achats, CMUP, barème, `CLAUDE.md` complet, SUPER PDP

- Une ligne de vente vend un produit existant ou un service, sans modifier sa description ni son PU HT (`38e8544`). Une facture d'achat se saisit ligne par ligne dans un formulaire produit complet, sans créer de fournisseur (`a86783c`). Un produit venu d'une facture d'achat API est verrouillé. Stock au CMUP (`7e8ab17`). Barème de prix de vente tiré de la calculatrice HTML de l'éditeur, désactivé par défaut mais visible (`36d638c`).
- Un libellé trompeur (« 0,56 € TTC » pour un achat) irrite l'éditeur : « comment je peux avoir confiance en toi ». Puis la TVA d'une ligne au prix HT est fausse (10 × 1,18 €) : `a31b0c4`. Il fixe la règle : seul le prix de vente TTC s'arrondit, une fois, quand l'achat enregistre le produit ; ensuite rien ne se recalcule.
- `CLAUDE.md` : règle de réécriture du code, protocole de rigueur en tête (`49c7f8a`, proposé puis accepté : « me convient tes deux points »), feuille de route en 15 étapes, SUPER PDP plateforme agréée, spécification OpenAPI 1.36.0.beta (`41afe34`), accès par délégation OAuth.
- 22 h 52, le mandat : « Claude, tu as commencé ce logiciel et c'est toi qui vas entièrement le finir… Ne t'arrête que lorsque l'intégralité du logiciel est 100 % terminée, testée au vert et prête à être utilisée en production. » Puis, plusieurs fois : « continue tout seul jusqu'à la fin ». Il autorise le modèle Fable (un modèle Claude qu'il choisit pour la session, pas un outil) pour concevoir les écrans « si nécessaire ».
- Épisode des identifiants : l'éditeur montre en capture les secrets du bac à sable SUPER PDP et veut « donner les vrais identifiants ». Il ouvre lui-même l'accès réseau à superpdp.tech dans l'environnement. Les variables, mises d'abord dans le script de configuration, passent dans « Variables d'environnement » (visibles seulement dans une nouvelle session). L'essai réel avec les identifiants en ligne est refusé par le classificateur (« Credential Leakage ») : non contourné. Conseil : renouveler les deux secrets après les tests.
- Méthode : travail parallèle dans un worktree (`wt-prix`). `php artisan test` n'y marchait pas (autoload) ; la méthode a été abandonnée ensuite au profit des copies figées.

#### Vendredi 9/10 : chaînes d'agents, audit, caisse sécurisée, 85 décisions

- Vers 0 h 20 : chaque message de l'éditeur redémarre la session et tue les courses en fond (« il y a quelques agents qui sont arrêtés »). Reprises par `resumeFromRunId`.
- Poussé : la refonte des prix (`9eacfd9` : ventes au TTC, achats au HT, retours cumulés), l'étape 4 (`265674d` : e-mails, relances, notifications), l'audit complet en 7 lots, chacun suivi d'un commit « Relecture du lot : … » (sécurité des comptes, isolation des formulaires, policies, catalogue, achats et CMUP rejoué depuis le grand livre, ventes et paiements, code mort), puis le cœur de la caisse sécurisée en 6 lots (fin de l'annulation des documents émis, socle cryptographique ES256, registre fiscal et JET, ventes, avoirs et encaissements signés, journal des événements, vérificateur d'intégrité).
- Les 85 décisions métier sont recueillies sur une page privée d'arbitrages, en trois tours (« c'est fait » à 12 h 32, 14 h 12 et 17 h 51), puis écrites dans `CLAUDE.md` (`b48e1cf`, feuille de route réordonnée : SUPER PDP passe en étape 2).
- 18 h 01 « Arrêt pour l'instant » ; 18 h 18 « commence maintenant » : lots de décisions 1 à 4 (retrait de Shield, Sanctum, Vite et Tailwind, licence propriétaire ; un compte limité à un magasin ; lecture seule des magasins suspendus ; code du magasin dans les numéros).
- Incident CI : un avertissement PHPUnit (fournisseur de données) donne le code 1 sur `5d5a1ed`, corrigé par `16f79db`. Le contrôle du code de sortie entre dans le protocole.
- Le classificateur refuse `git stash drop` : les stashs restent.

#### Samedi 10/10 : vitesse, campagne de tests, PostgreSQL

- 0 h 10 : « J'ai l'impression que ça a duré longtemps ». L'éditeur accepte les tests en parallèle (paratest, `a55b91d`) : un lot passe de 70 à 35-40 minutes.
- Lots de décisions 5 à 18 : clôture du jour et rattrapage, archivage des fiches, SIREN des clients professionnels, client figé à l'émission, taux et franchise, ventes aux professionnels depuis le HT (remplacé par R6 le 17/10), identité légale, réglages communs par le « magasin principal » (remplacé le lendemain par la société), devis figés, contre-passation signée avec verrou avant lecture, crédit et bon d'avoir, ventes cloisonnées par vendeur, produits créés à la validation, VIES.
- 8 h 39 : demande d'une campagne de tests très approfondie (des milliers de sociétés fictives, concurrence réelle, SUPER PDP simulée, rapport honnête), « planifiée après la finalisation des lots en cours », avec deux règles : « Ne prétends jamais avoir exécuté un test si tu n'as pas réellement pu le lancer » et « N'annonce jamais que PILIQI est exempt d'erreurs ». La stratégie est écrite (`campagne/strategie/finale.md`, 19 décisions). Sa reconnaissance sur MariaDB a montré : deux paiements simultanés acceptés, un interblocage sur le premier numéro d'une série, des codes magasin en double, un cache des autorisations qui explose (79 s et 1,9 Go à 22 540 magasins).
- 9 h 01 à 9 h 09 : choix du moteur délégué (« tu appliques celui qui est meilleur ») : PostgreSQL en production, SQLite en développement, suite sur les deux (`ce8458f`, `df3d70c`). `CLAUDE.md` exige désormais les deux suites avant chaque commit.
- 23 h 22 : la série 4 (architecture société, plus les décisions T1 à T6 de la campagne) est remplie sur la page.

#### Dimanche 11/10 : architecture société, TVA, équipe

- Lots 19 et 20 (natures d'opération, preuves d'exonération et bordereau de détaxe), puis A0 (règles de l'architecture société dans `CLAUDE.md`, étape 1 bis de la campagne dans la feuille de route), A1, A2 (socle des sociétés, `BelongsToSociete`), A3 (vérification des sociétés et magasin de démonstration).
- 1 h 45 : « J'ai pas pu valider tout, il s'est enlevé » : la fenêtre de questions a disparu avant la validation. Questions reposées.
- Matin : explications sur l'e-reporting (documents fournis par l'éditeur, article 290, chaîne PILIQI → SUPER PDP → PPF), la facture demandée par un particulier, « Transformer en facture » (le ticket est payé en totalité par l'avoir d'annulation), les avoirs sur tickets soumis à une autorisation, et jamais de remboursement en argent.
- 13 h 23 : demande d'un moteur de TVA centralisé (achats, ventes, stock, déclarations). Conception d'abord, présentée avant tout code.
- 13 h 59 à 14 h 16 : l'éditeur demande pourquoi retirer Spatie, puis délègue (« c'est toi qui dois décider »). Décision : un système d'autorisations propre à PILIQI, avec son référentiel en base.
- 19 h 20 : série 6, organisation de l'équipe (propriétaire, administrateurs, responsables, vendeurs, experts-comptables). 20 h 41 : « charges du magasin ». 21 h 16 : inscription sans magasin.
- Soir, poste Windows de l'éditeur : `composer run dev` absent, `pdo_pgsql`, OpenSSL et `OPENSSL_CONF`, `caisse:generer-cle` (l'éditeur colle une clé privée de développement : conseil de la régénérer et de ne jamais coller de clé), `JournalAuditNonConfigureException` faute de `JOURNAL_AUDIT_CLE`. La série 7 (moteur de TVA) est remplie vers 22 h 30. 23 h 51 : limite d'usage.

### Semaine 3 : lundi 12 au dimanche 18 octobre

#### Lundi 12/10 : A4, puis les limites

- Nuit : lot A4 (Spatie retiré, équipe de la société, contrôle de l'auteur de chaque action, comptes retirés, équipe d'une société révoquée), de `5533743` à `30844db`.
- Journée : une douzaine de « Réessayer », « comence avec fable » (8 h 35, choix du modèle Fable), « Recommence » : limites de session, puis limite hebdomadaire et plafond mensuel de dépense de l'organisation.
- Entre le 12 et le 17/10 (date exacte non notée), la ligne d'attribution des scripts de chaîne a été changée (« Fable 5.1 »), puis remise à l'identique (« Opus 5.5 ») : un texte de `REGLES` modifié casse le cache d'une course reprise.

#### Mardi 13 au vendredi 16/10 : arrêt complet

Aucun commit, aucun message.

#### Samedi 17/10 : reprise et série 8

- 9 h 02 : reprise (fin de la limite hebdomadaire). Relecture de A4 (`8a22d55`), A4b (inscription sans magasin). L'implémentation de A5 et la finalisation du plan de la série 8 échouent sur le plafond mensuel de dépense de l'organisation ; relancées après 14 h, A5 (fermer, rouvrir et céder un magasin) est poussé le jour même.
- 9 h 14 : la série 8, 26 retours de l'éditeur après essai, écrits en albanais et en français. Il précise : « tu mets le texte en français, jamais albanais ». Analyse et plan : `serie8/plan-serie8.md`.
- Q15-a « Oui, pour tous » et Q15-b « HT au centime supérieur » : politique d'arrondi unique pour tous les clients.
- Incident d'ordre : la chaîne société avait déjà fait A6 avant R6. Elle est arrêtée avant A7 ; R6 réécrit les montants de A6.
- Poussé : A6 (R1 à R3), R6, S8-T, S8-A. 23 h 36 : la page de la série 8 est remplie.

#### Dimanche 18/10 : série 8 en chaîne, pannes en série

- Précisions 1 à 5 (le propriétaire ne vend jamais ; un compte par magasin ; premier mot de passe égal à l'identifiant, changement facultatif avec une alerte ; un administrateur peut aussi redonner l'accès d'un vendeur). Avenant S8-V/A4c finalisé (26 défauts corrigés). Points ouverts : Q-V1 a, Q-V2 b, Q-A d.
- 0 h 53 : l'éditeur lance la suite sur son PC : 3 642 erreurs, car OpenSSL ne génère pas la clé ECDSA (`OPENSSL_CONF`).
- 2 h : la CI n'a plus de machine dès `243eceb` (runner_id 0, échec en 2 secondes) : quota de minutes ou plafond de dépense de GitHub Actions. Un seul commentaire est posté sur la PR. 8 h 14 : l'éditeur règle GitHub ; CI relancée.
- 8 h 20 : redémarrage du conteneur pendant S8-L ; le travail reste dans l'arbre ; note de reprise ajoutée au lot.
- Échecs de CI aléatoires : `gzuncompress(): data error` (la lecture des PDF dans les tests perdait un dernier octet `\r`, corrigé par `aa6f7f8`) ; `ClientResourceTest` (un e-mail Faker contenait « helene », corrigé par `fc23528`). D'où le lot T1, tests déterministes (Faker semé par test, temps figé). faketime est installé.
- 16 h 45 : limite d'usage, puis redémarrage du conteneur.
- Poussé dans la journée : A5c, S8-P, S8-R, S8-C, S8-E, S8-L, S8-F, S8-O, S8-V, T1.
- Soir : l'éditeur demande s'il peut utiliser la même branche avec un autre compte Claude ; il installe l'application GitHub de Claude ; il pose une question sur l'effort et ultracode.
- Une autre session (`session_01FDqxHJe8vV4eCZi1gkY8XL`) crée la branche `claude/amazing-ride-5nni6d` avec le commit `176d29d`.

### Semaine 4 : lundi 19 au dimanche 25 octobre

#### Lundi 19/10 : A4c, courrier recommandé, cabinets

- Redémarrages du conteneur vers 0 h et 2 h. A4c (connexion par identifiant, transmission de la propriété par un lien) est poussé (`bd0d6ef`, `41fd0a5`) ; son agent de correction meurt avec le conteneur, après son commit.
- 1 h 20 : l'éditeur voit qu'on peut ouvrir deux sociétés avec le même SIREN. Décision V2 : SIREN unique, vérification par le seul courrier recommandé au représentant légal (réponses A1, B1, C1, D1, E2 ; « contre signature » veut dire remis au dirigeant en personne). Accord pour `bacon/bacon-qr-code`.
- 2 h 03 : espace des cabinets d'expertise comptable (réponses F1, G2, H1, I1, J1, K2).
- Ensuite, limite hebdomadaire jusqu'au 24/10 à 9 h.

#### Mardi 20 au vendredi 23/10 : arrêt, et l'autre session

- 20/10 à 17 h 46 : une autre session (`session_01Bq8quawoZfBDgiwxoXPiYL`, sur un autre compte) pousse M1 (`67f43e9`) puis M0 (`3fede71`) sur la branche de travail, sans connaître les décisions.

#### Samedi 24/10 : nettoyage et V2

- 16 h 13 : reprise. Verdict sur l'autre session : M0 contredit TVA9 = c (jamais de carte cadeau) et omet des textes du plan ; M1 n'est qu'un déplacement de fichiers. L'éditeur : « Mais si tu trouves qu'ils sont pas bons, alors supprime-les ». Annulation commitée à 16 h 32 et poussée à 16 h 40 (`5cbe4e2`, arbre identique à `41fd0a5`). 16 h 34 : « Fait tout toi comme prévu et supprime ce qui est fait par l'autre ».
- 16 h 50 : la reprise de la course `wf_f8075c92-25b` relance la relecture de S8-L (cache manqué). Arrêtée aussitôt. Nouvelle règle : relancer sans reprise, avec les seuls lots restants.
- Branche `amazing-ride` : sa suppression est refusée par le classificateur (« Git Destructive »). L'éditeur la supprime sur GitHub (22 h 30), refait la manœuvre après vérification (22 h 47). Le défaut que visait `176d29d` est refait par FA1.
- V2 est commité. Limite de session de 19 h 33 à 21 h 10, puis redémarrage pendant sa correction (13 défauts) : script de reprise dédié (`v2-correction-reprise.js`). V2 et sa relecture sont poussés vers 22 h. Réponses de l'éditeur sur V2 : lot V2b. Réponses sur les cabinets (Q-EC1 à Q-EC7) et sur les types d'activité (Q-TA1, Q-TA2).

#### Dimanche 25/10 : V2b, FA, T2, prix unitaires, changement de compte

- 8 h 15 : V2b poussé. Sa relecture trouve que des horodatages locaux sans décalage font échouer 8 tests pendant l'heure répétée du passage à l'heure d'hiver : lot T2 ajouté.
- FA1 est coupé par la limite de session avant tout travail, puis relancé. FA1, FA2 et FA3 sont poussés ; la CI de `5f71b60` est coupée par l'arrêt d'une machine GitHub, relancée une fois, verte.
- L'éditeur demande plusieurs fois pourquoi c'est si long. Réponse de sa part : « On va pas changer rien, donc tu continues comme prévu » ; il veut la voie la plus sûre, avec ultracode.
- Décisions T2 (heure locale de chaque magasin, d'où le lot T3 ; un délai en jours = la même heure n jours plus tard ; « (heure d'été) / (heure d'hiver) » dans les journaux) et T3 (A : pas de déménagement entre territoires ; B : attendre la confirmation pendant une panne de l'INSEE ; C : procédure de l'éditeur pour un territoire mal lu).
- Prix unitaires : trois revirements (18 h : affichage au centime ; 18 h 30 : retour à l'existant avec la note ; 19 h, version finale : 54,17 €, calcul exact inchangé, note de calcul des PDF gardée et complétée). Lot PU.
- 19 h 56 : l'éditeur envisage un autre compte (forfait Max 20x) pour éviter les limites. Proposition : une fiche de reprise sur une branche à part, une seule session à la fois.
- T2 est commité (4 commits). 23 h 40 : un message de l'éditeur pendant un tour coupe `T2:correction` pendant ses suites faketime.

### Lundi 26 octobre

- 2 h 10 : la consigne de la correction de T2 est sauvée ; course de reprise `wf_8eaaa549-68e`. 3 h 45 : la relecture de T2 est poussée (`3a02fbc`).
- T3 : trois commits (`c4c684a`, `60962cc`, `5f86951`, jusqu'à 9 h 07), relus ; sa correction commence.
- 8 h 24 : « Tu as fini le T2 ! Montre-moi » : une page privée lui présente T2.
- 10 h 30 : l'éditeur ne veut pas d'action manuelle à retenir pour le fuseau UTC de la base en production : ajouté au lot L1 (fixé par les migrations).
- 10 h 42 à 10 h 46 : il demande toutes les notes de travail pour un autre compte, mises à jour « à chaque fois ». Branche `claude/pilotage-reprise` créée (seule autre branche autorisée), script `pilotage-sync.sh`, étape `:notes` ajoutée à la chaîne.
- 10 h 46 : ce message coupe `T3:correction` pendant sa grille faketime. Consigne sauvée ; course de reprise `wf_fb31205c-c06`, qui enchaîne ensuite PU, TA, EC, L1, A7 et 21.
- 11 h 34 : « Il faut que tu mettes tout… pour que lui travaille comme si c'était toi ». Le dossier de reprise est complété (ce fichier en fait partie).
- 11 h 38 et 12 h 04 (messages 279 et 280) : l'éditeur demande pourquoi l'agent de relecture a trouvé des erreurs dans la fiche que la session n'avait pas vues, puis que la fiche soit relue ainsi à chaque fois. 12 h 05 : étape `<lot>:fiche` ajoutée à la chaîne (relecture à froid de la fiche après chaque lot, `VERIFICATION.md`).
- 12 h 44 : la relecture de T3 est poussée (`fa83824`), avec la correction des 6 défauts de ses deux relectures ; la sous-course de `wf_fb31205c-c06` commence PU.

---

## 3. Tableau des incidents

| Date | Incident | Effet | Réponse |
|---|---|---|---|
| 04/10 | La CI échouait en silence depuis le début (avertissement PHPUnit, code 1, résumé « passed ») | Aucune CI fiable pendant deux jours | `b49e97c` ; plus tard, contrôle du code de sortie dans le protocole |
| 04/10 | Dépôt rendu public par l'éditeur | Code visible de tous | Remis en privé ; vérifié : aucun secret commité |
| 06/10 | WebFetch et curl bloqués par le proxy (impots.gouv.fr, cabinets) | Pas de lecture directe des sources | WebSearch ; PDF fournis par l'éditeur ; il peut ouvrir un domaine dans l'environnement |
| 07/10 | Poussées GitHub en erreur 500, alors que les lectures marchaient | Poussée retardée | Réessai en fond, réussi |
| 07/10 | `pkill -f` a tué le shell (il visait le texte de sa propre commande) | Commande perdue | Tuer un processus par son PID |
| 08/10 | Essai réel avec identifiants en ligne refusé (« Credential Leakage ») | Pas d'essai réel SUPER PDP | Non contourné ; uniquement des implémentations factices |
| 08/10 | Un appel d'outil refusé avait quand même écrit des fichiers | Tests en double | Doublons supprimés ; vérifier les fichiers après un refus |
| 09/10 | Chaque message de l'éditeur redémarrait la session | Courses en fond tuées | Reprises par `resumeFromRunId` |
| 09/10 | Avertissement PHPUnit sur `5d5a1ed` | CI rouge (PHP 8.3, 8.4) | `16f79db` |
| 09/10 | `git stash drop` refusé par le classificateur | Stashs laissés | Ne pas forcer |
| 10/10 | `${PIPESTATUS[0]}` interpolé par le gabarit JS d'un script de chaîne | Course qui ne démarre pas | Échapper `\${…}` |
| 11/10 | Fenêtre de questions disparue avant la validation | Réponses perdues | Questions reposées |
| 11/10 | Clé privée de développement collée par l'éditeur | Clé exposée dans la conversation | Conseil de la régénérer ; ne jamais la répéter |
| 11/10 | Affirmation fausse (MariaDB « préinstallé ») | Information inexacte | Corrigée : un agent l'avait installé |
| 12 au 17/10 | Limites de session, limite hebdomadaire, plafond mensuel de dépense de l'organisation | Cinq jours d'arrêt ; A5 et le plan de la série 8 en échec | Reprise le 17/10 à 9 h ; A5 et le plan relancés après 14 h |
| 17/10 | Chaîne lancée trop loin : A6 fait avant R6 | Montants de A6 à refaire | Chaîne arrêtée avant A7 ; R6 a réécrit A6 |
| 18/10 | CI sans machine (runner_id 0, échec en 2 s) | Aucune CI | Un commentaire sur la PR ; l'éditeur règle GitHub Actions |
| 18/10 | Échecs aléatoires de CI (dernier octet d'un flux PDF ; e-mail Faker) | CI rouge par hasard | `aa6f7f8`, `fc23528`, lot T1 |
| 18/10 | `composer dump-autoload` dans une copie à `vendor` en liens physiques a réécrit le `vendor` du dépôt | Risque sur le dépôt | Copier `vendor/composer` pour de vrai avant tout dump |
| 18/10 | Disque presque plein | Courses en danger | Copies de banc supprimées (12 Go libérés) |
| 18, 19, 24/10 | Redémarrages du conteneur | PostgreSQL arrêté ; agents coupés, travail resté dans l'arbre | `service postgresql start` ; notes de reprise ; lots déjà poussés retirés des ouverts |
| 18 et 20/10 | Autres sessions sur la branche (autre compte) | Commits concurrents hors décisions | `5cbe4e2` (revert) ; branche supprimée par l'éditeur |
| 19 au 24/10 | Limite hebdomadaire | Cinq jours d'arrêt | Reprise le 24/10 |
| 24/10 | Reprise d'une course qui manque le cache | Relectures de S8-L refaites pour rien | Arrêt ; relance sans reprise |
| 24/10 | `git push origin --delete` refusé (« Git Destructive ») | Branche étrangère restée | Supprimée par l'éditeur sur GitHub |
| 24 et 25/10 | Limites de session pendant V2 et FA1 | Correction coupée | Script de reprise ; relance |
| 25/10 | Arrêt d'une machine GitHub (CI de `5f71b60`) | CI interrompue | Relancée une seule fois |
| 25/10 | Heure d'hiver : 8 tests échouent pendant l'heure répétée | Défaut réel des horodatages | Lots T2 puis T3 |
| 25/10 | Erreur dans une réponse (code du courrier « 8 caractères ») | Information inexacte | Corrigée : 12 caractères |
| 25/10 23 h 40 et 26/10 10 h 46 | Message de l'éditeur pendant un tour | `T2:correction` puis `T3:correction` coupés | Consignes sauvées, courses de reprise |

---

## 4. Leçons apprises (le piège, puis la règle)

### La chaîne et les agents

1. **Une reprise peut manquer le cache** (24/10 : relectures de S8-L refaites). Règle : relancer une chaîne sans `resumeFromRunId`, avec les seuls lots restants ; ne jamais remettre dans `ouverts` un lot déjà poussé.
2. **Une course lancée ne voit pas les modifications du script.** Règle : pour insérer un lot ou changer un cahier des charges, arrêter la course entre deux lots (guet sur `journal.jsonl`, après une poussée) et la relancer ; garder une copie `serie8-lots.avant-<raison>.js` et vérifier la syntaxe avant.
3. **Changer le texte d'un agent ou de `REGLES` invalide le cache de tout ce qui suit.** Règle : pendant une reprise, ne toucher qu'un lot pas encore appelé.
4. **Une chaîne lancée trop loin fait un lot avant une décision qui le change** (A6 avant R6). Règle : guetter le démarrage du lot qui dépend d'une décision nouvelle et arrêter la course avant lui.
5. **Le crochet d'arrêt pousse à committer le travail en cours.** Règle : ne jamais committer ni pousser le travail d'un agent ; répondre que l'agent commitera après des suites vertes, et qu'un commit pas encore relu n'est pas poussé avant sa relecture.
6. **Un agent coupé laisse du travail non commité.** Règle : dans le même conteneur, note de reprise ou script de reprise (consigne d'origine sauvée sans l'en-tête du harnais ni l'indentation) ; sur un autre conteneur, refaire le lot depuis la tête poussée.
7. **`${…}` dans un gabarit JS est interprété par le script.** Règle : écrire `\${…}` pour du shell.
8. **Les agents oublient des tests jetables** (`tests/Feature/Scratch*`). Règle : exclusion locale dans `.git/info/exclude`, et vérifier qu'ils ont disparu.
9. **« étape commencée » ne veut pas dire « étape finie ».** Règle : une étape est finie quand son `journal.jsonl` a une ligne `"type":"result"` de même `key`.
10. **Un atelier de conception qui lit le dépôt pendant qu'un lot l'écrit voit un état instable.** Règle : travailler sur une copie figée (`git archive`, `vendor` en liens physiques, `vendor/composer` copié pour de vrai avant tout `dump-autoload`).
11. **Plusieurs lots en même temps se marcheraient dessus** (mêmes fichiers comme `CLAUDE.md`, les migrations et le référentiel des autorisations, mêmes bases de test, relectures qui supposent un arbre stable). Règle : un lot à la fois, dans l'ordre de `LOTS`. C'est la réponse à donner quand l'éditeur demande « pourquoi un seul agent ».
12. **Lire la sortie d'une course** : `json.load(...)['result']`, jamais une recherche du premier `[`.

### Sessions, limites et interruptions

13. **Un message de l'éditeur pendant un tour tue les courses en fond** (09/10 à chaque message, 25/10, 26/10). Règle : tours courts quand une chaîne tourne ; prévenir l'éditeur avant un long travail ; après chaque interruption, vérifier la dernière ligne de chaque `agent-*.jsonl`.
14. **Les limites tombent sans prévenir** (session, hebdomadaire, plafond de dépense de l'organisation ; arrêts de cinq jours). Règle : pousser dès qu'un lot est vert et relu ; tenir `etat-chaines.md` et la branche de reprise à jour ; points de suivi programmés.
15. **Un redémarrage du conteneur arrête PostgreSQL et coupe les agents.** Règle : `service postgresql start`, puis `git status` et `git log origin/<branche>..HEAD`, puis décider. Lot poussé : le retirer des ouverts. Lot commité mais pas poussé, ou travail resté dans l'arbre : script de reprise dans le même conteneur, qui repart de l'étape coupée (relectures, correction ou poussée), sans jamais retirer le lot des ouverts : sinon la poussée du lot suivant l'enverrait sans relecture.
16. **Deux sessions sur la même branche se détruisent leur travail** (18/10 et 20/10). Règle : une seule session à la fois ; le travail d'une autre session s'annule par `git revert`, jamais par une poussée forcée.

### Tests

17. **« passed » peut cacher un code de sortie 1** (avertissement PHPUnit). Règle : toujours `echo $?` juste après la suite ; dans un script, pas de tube qui masque le code (`if git push …; then`, `PIPESTATUS`).
18. **Les tests dépendaient du hasard et de la date réelle** (e-mail Faker, « -2026- » attendu en dur). Règle (T1) : Faker semé par test, temps figé pour toute attente liée au calendrier, suite rejouée sous faketime aux instants de la grille du README (`FAKETIME_DONT_RESET=1` sous PostgreSQL).
19. **Des heures locales sans décalage cassent à l'heure d'hiver.** Règle (T2, T3) : instants en UTC, jours « AAAA-MM-JJ », la classe `Calendrier` seule connaît un fuseau, chaque magasin à l'heure de son territoire.
20. **SQLite masque la concurrence** (`lockForUpdate` sans effet). Règle : verrou avant lecture, et vrais tests de concurrence sous PostgreSQL.
21. **L'éditeur refuse une explication à la place d'un test** (« Il faut que tu le fasses. Les tests. »). Règle : chercher le chemin testable ; tests de propriétés à graine fixe ; mutation volontaire pour prouver qu'un test attrape le défaut ; ne jamais affaiblir un test.
22. **La lecture des PDF dans les tests perdait un octet une fois sur 256.** Règle : lire un flux sur sa longueur `/Length` (test de régression dans `OutillageTest`).

### Montants

23. **Les flottants font des écarts d'un centime** (5,01 €, 79,99 €, 84,99 € contre 85,00 €). Règle : tout en centimes entiers, un seul arrondi, aucune somme faite par la base sur une colonne décimale ; politique d'arrondi unique (R6) avec le test 50,00 € TTC → 41,67 € HT + 8,33 € de TVA.
24. **Un montant juste mal nommé passe pour une erreur** (« 0,56 € TTC »). Règle : chaque libellé dit exactement ce qu'est le chiffre.

### Git, GitHub et environnement

25. **Le classificateur refuse certains gestes** (identifiants en ligne, `git stash drop`, `git push origin --delete`). Règle : ne jamais contourner ; demander à l'éditeur de faire le geste lui-même.
26. **CI sans machine** (runner_id 0, échec en 2 s) = quota ou plafond GitHub Actions. Règle : un seul commentaire sur la PR, demander à l'éditeur ; relancer une seule fois après l'arrêt d'une machine ; une CI rouge pour une cause réelle se corrige.
27. **Le proxy bloque des sites** (WebFetch, curl ; github.com en curl). Règle : WebSearch, documents fournis par l'éditeur ; un domaine s'ouvre dans les réglages réseau de l'environnement, des variables dans « Variables d'environnement », visibles seulement dans une nouvelle session.
28. **`pkill -f` peut tuer son propre shell.** Règle : tuer par PID.
29. **Le disque est petit (environ 15 Go libres).** Règle : supprimer les copies figées et les bancs dès qu'ils ne servent plus.
30. **Une affirmation non vérifiée finit par être fausse** (MariaDB, « 8 caractères »). Règle : vérifier avant d'affirmer, et corriger aussitôt auprès de l'éditeur.

### Pièges Filament 3.3 et Livewire 3 rencontrés

- `createOptionForm` sur un `Select` sans `relationship()` : le formulaire perd son modèle ; le refixer dans une fermeture.
- `$get()` sur un `Select` d'énumération renvoie l'énumération une fois le champ touché : normaliser avant de comparer.
- `RepeatableEntry` qui ne correspond pas à une vraie relation : donner un `state()` explicite à chaque entrée.
- `DeleteBulkAction` ne revérifie pas la policy ligne par ligne.
- Une action cachée ne peut pas être montée (`isDisabled()` inclut `isHidden()`).
- Un champ désactivé n'est pas enregistré mais il est validé.
- `callFormComponentAction(..., data: [...])` pose les champs dans l'ordre du schéma et déclenche leurs `afterStateUpdated`.
- `assertTableColumnStateSet` : passer la clé de l'enregistrement, pas le modèle.
- `minDate(today())`, jamais `now()`, pour un champ de date.
- Un message de validation ne contient pas de deux-points (Livewire les lit comme paramètres).
- `money()` met une espace insécable avant « € ».
- PHPUnit 12 : attribut `#[DataProvider]`, et un fournisseur ne donne jamais plus d'arguments que la méthode n'en prend.

### Communication avec l'éditeur

- Il est l'éditeur, jamais propriétaire d'un magasin : ne pas lui dire « vous, le propriétaire ».
- Le vouvoyer (« vous », « votre »), même s'il tutoie : la session l'a toujours fait.
- Tout en français, même s'il écrit en partie en albanais.
- Il veut des explications simples avec des exemples chiffrés, et demande souvent pourquoi c'est long : expliquer les relectures, les deux bases et faketime, sans promettre « zéro défaut ».
- Il change parfois d'avis (prix unitaires, édition des factures) : mettre à jour le fichier de décision, le script et les points de suivi à chaque fois ; la dernière version du fichier fait foi.
- Il n'a pas de comptable : chercher les sources officielles ; signaler seulement ce que `CLAUDE.md` demande de faire confirmer par l'expert-comptable de la société.
- « Réessayer », « Réessayez » ou « J'ai atteint ma limite… continuer » veulent dire : reprendre là où le travail s'est arrêté.

---

## 5. Conventions établies

### Fichiers de décision

- Tout se range dans le dossier de travail (`<scratchpad>`) et se copie dans `notes/` de la branche `claude/pilotage-reprise` par `pilotage-sync.sh`.
- Un dossier par sujet : `societe/`, `serie8/`, `tva/`, `t2/`, `t3/`, `fa/`, `decisions/`, `moteurs/`, `superpdp/`, `campagne/`.
- Dans chaque dossier :
  - `demande-editeur.md` : la demande d'origine, citée (traduite en français si besoin) ;
  - `analyse-*.md`, `conception*.md` : études ;
  - `reponses-*.md` : les réponses de l'éditeur, texte d'origine cité puis règles ; elles font foi ;
  - `decision-*.md` : le cahier des charges tranché d'un lot ; `avenant-*.md` : son complément ; `plan-*.md` : le plan des lots ;
  - `consigne-correction-*.md` : la consigne exacte d'un agent coupé, pour le relancer.
- Priorité : un fichier de réponses prime sur le cahier des charges qu'il complète ; une décision plus récente prime sur `CLAUDE.md` pour son sujet, jusqu'au lot qui réécrit `CLAUDE.md`.
- `etat-chaines.md` garde des notes horodatées de chaque lancement, arrêt, reprise et résultat de CI.

### Poser les questions à l'éditeur

- Ne poser que les règles métier non tranchées (calcul, arrondi, obligation légale, droits). Le technique se décide seul : il l'a délégué (« c'est toi qui dois décider »).
- Chaque question porte un identifiant de série (A1 à K8 pour les 85 décisions ; S1 à S14, V1, V2, T1 à T6, R1 à R5 pour la série 4 ; TVA1 à TVA16 et O1 à O8 pour la série 7 ; Q1-a à Q15-b, P3 à P8, M1, M2 pour la série 8 ; Q-V1, Q-V2, Q-A ; A1 à E2 pour V2 ; F1 à K2 pour les cabinets ; Q-EC1 à Q-EC7 ; Q-TA1, Q-TA2 ; Q1 à Q3 pour T2 ; A à C pour T3).
- Des options lettrées a, b, c…, une recommandation marquée « (Recommandé) », un exemple chiffré. L'éditeur répond souvent par des codes (« A1,b1,c1,d1,e2 ») ou par « c'est fait » quand il a rempli la page. Une note ou un choix « autre » prime sur le texte de l'option.
- Peu de questions : fenêtre de questions (si elle disparaît, la reposer). Une longue série : page privée d'arbitrages (Artifact avec base de données ; réponses lues dans la collection `reponses`, puis écrites dans un fichier de réponses).
- `CLAUDE.md` : seul l'éditeur change ses règles. Proposer le texte et attendre son accord, sauf les textes déjà approuvés dans un fichier de décision.

### Lots et commits

- Noms des lots : lots de décisions 1 à 21 ; audit (7 lots) ; caisse sécurisée (6 lots) ; société A0 à A7 (plus A4b, A4c, A5c ; A4q retiré) ; série 8 : R6, S8-T, S8-A, S8-P, S8-R, S8-C, S8-E, S8-L, S8-F, S8-O, S8-V ; T1 à T3 ; V2, V2b ; FA1 à FA3 ; PU, TA, EC, L1 ; chaîne TVA : M0 à FIN (avec C-a, C-b, M5a à M5e, CH1 à CH6).
- Chaque lot : implémentation (un ou plusieurs commits), deux relectures adversariales (fidélité et métier ; sécurité, isolation et qualité, avec les questions du § 45 du prompt maître), correction, poussée, relecture à froid de la fiche de reprise, notes.
- Message de commit : en français, un verbe au présent qui dit l'effet (« Protège les brouillons et les fiches contre les gestes simultanés »), suivi de « (lot X) ». Le commit de correction s'intitule « Relecture du lot X de <thème> ». Lignes de fin : `Co-Authored-By` et `Claude-Session`. Aucun identifiant de modèle dans le code.
- Jamais de `--amend` sur un commit poussé (une seule fois, le 04/10, sur un commit pas encore poussé) ; jamais de poussée forcée ; une erreur poussée s'annule par `git revert`.
- Avant chaque commit : suites SQLite puis PostgreSQL au code 0, faketime si le lot touche aux dates, puis pint.

### Rendre compte

- Après chaque lot poussé : vérifier la CI de la tête, lancer `pilotage-sync.sh`, puis dire à l'éditeur, en français simple, ce qui change pour un magasin, avec des exemples chiffrés, les défauts trouvés par les relectures et les « laisses » de l'agent (ce qu'il n'a pas fait ou soumet à approbation).
- Distinguer ce qui a été vérifié de ce qui reste à valider. Ne jamais prétendre avoir lancé un test qui ne l'a pas été. Ne jamais dire que PILIQI est sans erreur.
- Quand un commit change des migrations, rappeler à l'éditeur, pour son PC : `git pull`, `php artisan migrate:fresh`, `php artisan optimize:clear`, puis une nouvelle inscription (le lien d'inscription est dans `storage/logs/laravel.log`).
- Points de suivi programmés toutes les 3 à 4 heures ; guets en fond de 2 heures au plus.

### Pages privées

- Une page (outil Artifact) est privée par défaut. Elle a servi à recueillir les décisions (page « Arbitrages PILIQI » du compte d'origine) et à présenter T2. La campagne de tests y publiera ses preuves, avec leur empreinte enregistrée dans le dépôt.
- Le dépôt GitHub reste privé : ne jamais proposer de le rendre public.

---

## 6. Ce qui reste à faire (au 26/10/2026)

L'ordre et le détail font foi dans `README.md` (§ 6) et `ETAT.md`.

1. **Chaîne en cours** (course `wf_fb31205c-c06` dans l'ancien conteneur) : T3 poussé le 26/10 à 12 h 44 (`fa83824`) ; sa sous-course fait PU (commencé), TA, EC, L1, A7 et 21. Une nouvelle session relance seulement les lots qui ne sont pas sur `origin` (`README.md`, § 5, « Lots restants »).
2. **Après chaque lot poussé** : CI de la tête et compte rendu à l'éditeur (§ 5, « Rendre compte »). **Après le lot 21** : CI de la tête, `pilotage-sync.sh`, compte rendu récapitulatif à l'éditeur (laisses, matrice décision → test, choix contestables et limites légales non couvertes), puis lui redemander le point 2 de `a-faire-apres-chaine.md` (commande `composer run dev` propre à PILIQI).
3. **Chaîne TVA** (`tva-lots-M0-FIN.js`, écrite le 17/10) : la mettre d'accord avec `serie8-lots.js` (mécanique et décisions postérieures au 17/10 : V2, V2b, EC, TA, T2, T3, PU, FA), retirer le complément `reprise` du lot FIN, refaire M0 et M1 selon `tva/plan-execution.md`, la faire relire sur une copie figée, puis la lancer. Elle porte aussi les compléments 14 à 20 et les charges des magasins. L'étape 1 de la feuille de route se coche à son lot FIN.
4. **Point 9 de la série 8** (créer les produits depuis une facture d'achat en PDF ou Excel) : attend les règles de l'éditeur ; questions prêtes au § 4.2 de `serie8/plan-serie8.md`.
5. **Étape 1 bis** : campagne de tests initiale, selon l'étape 1 bis de `CLAUDE.md` et les réponses T1 à T6 (`societe/reponses-serie4.md`), qui priment sur `campagne/strategie/finale.md` (stratégie du 10/10, à remettre d'accord avec l'architecture actuelle et à présenter de nouveau à l'éditeur avant le premier palier ; `README.md`, § 6).
6. **Étapes 2 à 15** de la feuille de route de `CLAUDE.md` (SUPER PDP, fin de la caisse sécurisée, journal d'audit, comptabilité, etc.), avec une relance de la campagne après les étapes 2, 3, 5, 9 et 13.
7. **Rappels à l'éditeur** (§ 9 du README) : clé de l'API Sirene, accès à l'INPI, contrat La Poste avec remise en personne, compte SUPER PDP de production ; renouveler les deux secrets du bac à sable ; analyse d'impact de la vérification par courrier ; question de l'attestation contre le certificat (art. 286 du CGI) ; annuaire de l'Ordre des experts-comptables ; règles à faire confirmer par l'expert-comptable ; audit de sécurité avant la production.
8. **Ordres de grandeur donnés à l'éditeur** : la chaîne jusqu'au lot 21 en 4 à 6 jours ; l'étape 1 complète en environ un mois ; les étapes 2 à 15 en plusieurs mois.
