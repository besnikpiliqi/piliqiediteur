# Directives de l'éditeur, dans ses propres mots

Ce fichier réunit tout ce que l'éditeur a demandé sur la façon de travailler, depuis son premier message du 2 octobre 2026 jusqu'au 26 octobre 2026. Il sert à une nouvelle session, sur ce compte ou sur un autre, pour travailler exactement comme il l'a demandé.

- Ordre de lecture : celui du § 0 de `README.md` (`CLAUDE.md` de la branche de travail, `README.md`, ce fichier, `ETAT.md` et `VERIFICATION.md` s'il existe, `HISTORIQUE.md`). `HISTORIQUE.md` raconte le projet ; `messages-editeur.md` donne tous ses messages à la session d'origine en entier (280 au 26/10/2026 à 12 h 45 UTC), régénéré à chaque mise à jour de la branche de reprise, et `messages-editeur-<8 premiers caractères>.md` ceux d'une session suivante (`README.md`, § 10).
- Ce fichier ne décide rien de nouveau. Pour une règle métier, `CLAUDE.md` et les fichiers de décision de `notes/` font foi, dans l'ordre de priorité du § 7 de `README.md`. Ici, chaque directive est citée mot pour mot, avec ses fautes, et son numéro de message renvoie à `historique/messages-editeur.md`.
- Les dates et les heures sont en UTC. Aucune valeur secrète n'est recopiée. Quand un message cite un lieu de travail personnel de l'éditeur, il est remplacé par « […] ».
- « Tient » veut dire que la directive s'applique aujourd'hui. « Remplacée » veut dire qu'un message plus récent l'a changée : la directive qui tient est nommée.

---

## 0. L'essentiel en quinze lignes

1. Il est l'éditeur du logiciel, jamais le propriétaire d'un magasin : ne jamais lui dire « vous, le propriétaire » (messages 16 à 18).
2. Tout se fait et s'écrit en français, même quand il écrit en albanais (message 210).
3. Autonomie totale sur la technique, jusqu'à un logiciel fini, testé et prêt pour la production (messages 8, 128, 129, 158, 174).
4. Une règle métier non tranchée (calcul, arrondi, obligation légale, droits) se pose à lui, expliquée en détail, avec des options et une recommandation ; on ne la devine jamais (message 1, § 42 ; message 101).
5. La voie la plus sûre, même si elle est plus longue : rien ne s'allège dans le protocole (messages 241 à 245, 253).
6. Un défaut se corrige partout, pas seulement sur l'écran où il l'a vu (messages 80, 97).
7. Les tests ne se remplacent jamais par une explication (messages 69, 70) ; les calculs se testent sur des milliers de cas (message 92).
8. Ne jamais prétendre avoir lancé un test qui ne l'a pas été ; ne jamais dire que PILIQI est sans erreur ; distinguer le vérifié du reste à valider (message 156).
9. Il n'a pas de comptable : chercher les règles sur les sites officiels (message 102).
10. Aucun mélange entre magasins ni entre sociétés, « zéro » ; chaque magasin doit avoir l'impression d'avoir son propre logiciel (messages 20, 107).
11. En développement, on repart de zéro et on réécrit le code quand il devient plus simple (messages 107, 125).
12. Les règles de `CLAUDE.md` ne changent qu'avec son accord (messages 126, 127).
13. Le logiciel est à lui seul : le dépôt reste privé (message 46).
14. Aucune action manuelle à retenir pour la production (message 275).
15. La branche de reprise est mise à jour à chaque changement, jamais seulement à la fin, et sa fiche est relue à froid après chaque lot (messages 276 à 278, 280).

---

## 1. Directives permanentes sur la façon de travailler

### 1.1 Qui il est, et ce qu'est le logiciel

**D1. Il est l'éditeur, jamais le propriétaire d'un magasin.**
- 03/10/2026, message 17 (répété aux messages 16 et 18) : « quand tu dis 'seulement vous, le propriétaire' alors ce n'est pas moi mais le proprietaire du magasin. »
- Ce que cela implique : dans les réponses, l'interface et les commentaires, « le propriétaire » est le propriétaire d'une société cliente. L'éditeur est le créateur de PILIQI. Lui parler de « vos magasins » ou de « vous, le propriétaire » est une erreur.
- Statut : tient.

**D2. PILIQI est un logiciel indépendant ; l'éditeur n'aura jamais de magasin.**
- 07/10/2026, message 107 : « et ce logiciel n'est pas pour ma boutique mais sera un logiciel indepandant, il n'y a aucun relation avec ou la je  travaille […], la ou je travaille donc sera pas le logiciel du magasin mais tout indepandant, donc moi le createur du logiciel j'aurais jamain un magasin et du coup je donne pas l'attestation à moi meme. »
- Ce que cela implique : aucune donnée, aucun réglage, aucun exemple lié à la boutique où il travaille. L'attestation de l'éditeur est donnée à chaque magasin client. Écrit dans `CLAUDE.md` (« Produit » : « son créateur en est l'éditeur, il n'a ni société ni magasin »).
- Statut : tient.

**D3. Le logiciel est à lui seul : le dépôt reste privé.**
- 04/10/2026, message 46 : « J’ai mis en public le github donc le nom du projet de mon logiciel. Est-ce que c’est pas grave ou pas bien car moi je veux pas que tout le monde voit et en plus comme ça tout le monde peut télécharger et utiliser comme son logiciel ce que normalement je veux surtout pas. Ce logiciel à moi et que à moi. »
- Ce que cela implique : ne jamais proposer de rendre le dépôt public ; licence propriétaire (décision H3 de `notes/decisions-editeur.md`, puce « Outillage » de `CLAUDE.md`) ; aucun secret dans le dépôt.
- Statut : tient.

### 1.2 Autonomie et mandat (dans l'ordre, avec les changements)

**D4. Au tout début : proposer l'architecture et attendre sa validation.**
- 02/10/2026, message 1, § 46 du prompt maître : « Commence par me proposer : […] Explique les décisions importantes. Ensuite, attends ma validation avant de commencer à générer le code. »
- Statut : **remplacée** par D5 et D6. Il ne veut plus valider chaque étape technique.

**D5. Faire mieux que ce qu'il a écrit.**
- 02/10/2026, message 8 : « Dernierment, tu fais le code comme tu penses qu’il faut etre. Donc tu te base pas uniquement se que je t’ai dit et envoyer. Donc tu fait beaucoup meilleurs ce que je t’ai dit, daccord. »
- 03/10/2026, message 11 : « Continue comme tu veux » ; message 12 : « Tu continues comme tu veux. L’essentiel que tu fasses parfaitement. »
- 03/10/2026, message 14 : « Oui tu continues ce que t’as à faire pour que ce logiciel soit hyper performant. »
- 03/10/2026, message 18 : « Et toi tu continue avec le projet jusqu a la fin comme tu as prevu, mais tu fait parfaitement »
- 03/10/2026, message 19 : « donc tu fais le meilleur car je peux me tromper pour ca. »
- Ce que cela implique : ses idées sont un point de départ. Quand il se trompe (droit, technique), le lui dire et proposer mieux. Il attend un travail « parfait », pas un travail minimal.
- Statut : tient.

**D6. Le mandat d'autonomie totale.**
- 08/10/2026, message 128 : « Claude, tu as commencé ce logiciel et c'est toi qui vas entièrement le finir. Je te confie 100 % du projet. Tu es le développeur principal et autonome de cette application. […] Travaille de manière totalement autonome enchaînant les tâches. Ne t'arrête que lorsque l'intégralité du logiciel est 100 % terminée, testée au vert et prête à être utilisée en production. »
- Répété : « continue tout seul jusqu'à la fin » (messages 129, 135, 138, 148), « Continue comme prévu » (messages 238, 246).
- Ce que cela implique : enchaîner les lots de la feuille de route de `CLAUDE.md` sans attendre son feu vert à chaque lot ; ne s'arrêter que pour une règle métier à trancher, un geste qu'il doit faire lui-même (compte, clé, réglage GitHub) ou un refus du classificateur.
- Statut : tient. Écrit au § 2 de `README.md`.

**D7. Les choix techniques sont délégués.**
- 10/10/2026, message 158 : « tu me parle pour "MySQL ou MariaDB" alors tu aplique celui qui est meilleur »
- 11/10/2026, message 173 : « Je privilégie la fiabilité et la sécurité plutôt que le développement d’un système personnalisé sans nécessité technique démontrée. »
- 11/10/2026, message 174 : « Tu le garde pas spatie parce que je t’ai dit mais c’est toi qui dois décider. Si c’est mieux pour logiciel d’avoir son propre système alors tu le fais »
- Ce que cela implique : choisir seul la technique (moteur de base, paquets, structure), justifier le choix par la fiabilité et la sécurité, puis lui expliquer simplement. Un paquet ajouté ou retiré suit quand même la règle de `CLAUDE.md` (« Do not change the application's dependencies without approval ») : il a donné son accord explicite pour `bacon/bacon-qr-code` (message 232 : « installe la bibliothèque bacon/bacon-qr-code ») ; la double authentification attend son accord pour un paquet (étape 15).
- Statut : tient.

**D8. Mais ne jamais deviner une règle métier.**
- 02/10/2026, message 1, § 42 : « Si une information manque, identifie clairement ce qui manque avant de générer une architecture incohérente. Ne devine pas une règle métier importante. »
- Ce que cela implique : calcul, arrondi, obligation légale ou droits non tranchés se posent à l'éditeur, avec des options et une recommandation (voir § 3.3). Écrit dans `CLAUDE.md`, protocole de rigueur, point 4.
- Statut : tient.

**D9. Quand il dit d'arrêter, on arrête.**
- 09/10/2026, message 153 : « Arrêt pour l’instant et je dis après quand commencer » ; message 154 : « commence maintenant ».
- Ce que cela implique : un ordre d'arrêt tient jusqu'à son ordre de reprise ; aucune course de chaîne ne se relance entre les deux.
- Statut : tient.

**D10. Le travail d'une autre session se supprime s'il n'est pas bon.**
- 24/10/2026, message 234 : « Fait tout toi comme prévu et supprime ce qui est fais par l’autre »
- Ce que cela implique : une seule session à la fois sur la branche de travail (§ 1 de `README.md`) ; le travail d'une autre session s'annule par `git revert`, jamais par une poussée forcée (c'est ce qui a été fait par `5cbe4e2`).
- Statut : tient.

### 1.3 Qualité, rigueur et voie la plus sûre

**D11. Du vrai code, jamais simplifié, jamais compliqué pour rien.**
- 02/10/2026, message 1, § 42 : « Ne me donne jamais un exemple volontairement simplifié si je te demande d’implémenter une vraie fonctionnalité. Je veux du code réellement exploitable dans le projet. »
- Message 1, § 40 : « Je ne veux PAS une architecture compliquée uniquement pour montrer qu’elle est complexe. Chaque abstraction doit avoir une justification. Laravel doit rester reconnaissable. »
- Message 1, § 30 : « Ne pas utiliser Repository Pattern partout par dogme. Utiliser les patterns uniquement lorsqu’ils apportent une vraie valeur. »
- Ce que cela implique : code complet (protocole de rigueur, point 1 de `CLAUDE.md`) ; fonctionnalités natives de Laravel et de Filament d'abord ; une abstraction seulement si elle apporte quelque chose de démontrable.
- Statut : tient.

**D12. Les treize questions avant de dire qu'une fonctionnalité est finie.**
- 02/10/2026, message 1, § 45 : « À chaque nouvelle fonctionnalité, pose-toi systématiquement ces questions : 1. Cette fonctionnalité appartient-elle à un tenant ? 2. Les données sont-elles correctement isolées ? 3. Un utilisateur d’un autre tenant pourrait-il y accéder ? 4. Les permissions sont-elles vérifiées ? 5. Les données sensibles sont-elles protégées ? 6. La transaction est-elle nécessaire ? 7. Y a-t-il un risque de race condition ? 8. Les indexes sont-ils suffisants ? 9. Faut-il une queue ? 10. Faut-il un audit log ? 11. Faut-il une API ? 12. Les tests couvrent-ils le scénario multi-tenant ? 13. Cette architecture pourra-t-elle évoluer ? Ne considère jamais une fonctionnalité comme terminée tant que ces points critiques n’ont pas été vérifiés. »
- Ce que cela implique : ces questions s'ajoutent au protocole de rigueur de `CLAUDE.md` (dont le point 2 ne couvre que l'isolation). Les relectures adversariales doivent les poser. Appliqué par la seconde optique de relecture de `serie8-lots.js` depuis le 26/10/2026 (et de la chaîne TVA quand elle sera mise d'accord, `README.md`, § 6).
- Statut : tient.

**D13. Faire propre, pour toujours, et partout.**
- 05/10/2026, message 80 : « le problem il faut regler et c'est pas grave qu est ce que tu fait, donc faire propre pour toujours car ca c'est tres serieux meme pour l'etat que le system envoie les factures par api. et bien sur que ce genre de calcule doit se faire pour tout les calcule et pas que pour la fiche produit, c'est normal que pour tout »
- 06/10/2026, message 97 : « et ce problem t'as regler pour partout ou que pour cette page ? si t'as pas fait alors il faire pour partout »
- 05/10/2026, message 86 : « mais tu t'en fous si c'est le client pro ou particulier, tu fait comme il faut pour tout »
- 17/10/2026, message 210, point 15 (partie en français) : « Je veux que tu corriges définitivement le problème des écarts de 1 centime dans PILIQI, et pas seulement dans l'interface de caisse. […] Ne corrige pas seulement les chiffres affichés. Identifie la source exacte des écarts, corrige le moteur de calcul et prouve par les tests que les mêmes opérations donnent les mêmes montants partout dans PILIQI. »
- Ce que cela implique : pour chaque défaut signalé, chercher la cause, corriger la source commune, chercher tous les autres endroits touchés, et le dire dans le compte rendu (« corrigé ici, ici et ici »). Une même règle vaut pour tous les clients, particuliers et professionnels.
- Statut : tient.

**D14. Le protocole de rigueur de `CLAUDE.md`.**
- 08/10/2026, message 126 : « et je veux que tu ajoute aussi ca dans le caude, mais tu me dis d'abord est ce que c'est bon ou d'ajoute ca, voila la demand: Mets à jour notre fichier `CLAUDE.md` pour y intégrer un protocole de rigueur absolu. […] » ; message 127 : « me convient tes deux points ».
- Ce que cela implique : le protocole en tête de `CLAUDE.md` est prioritaire sur tout (code complet, isolation, tests ciblés puis suites complètes SQLite et PostgreSQL au code 0, correction autonome, pint). Il a été depuis enrichi avec son accord (deux moteurs, `echo $?`, faketime).
- Statut : tient.

**D15. La voie la plus sûre, avec ultracode, sans rien alléger.**
- 10/10/2026, message 156 : « Je privilégie la qualité, la fiabilité et la profondeur des vérifications plutôt que le temps ou le coût. »
- 25/10/2026, message 241 : « On va pas changer rien, donc tu continues comme prévu. »
- 25/10/2026, message 242 : « Le mode « ultracode » activé sur la session ajoute des ateliers à plusieurs agents (propositions, juges, relectures) : plus sûr, mais plus long. Tu as dit plus sure mais plus long donc moi m’intéresse le plus sur »
- 25/10/2026, message 245 : « Tu le fait avec ulteacode »
- 25/10/2026, message 253 : « Non je veux pas changer rien du tout mais juste pourquoi ça doit prendre autant de temps »
- Ce que cela implique : chaque lot garde implémentation, deux relectures indépendantes, correction, suites sur les deux moteurs, faketime, pint, poussée, CI verte. Le travail substantiel passe par l'outil Workflow. Une question « pourquoi c'est long ? » n'est jamais une demande d'aller plus vite : on explique, on ne raccourcit rien.
- Statut : tient. Écrit au § 2 de `README.md`.

### 1.4 Les tests

**D16. Ne jamais sauter les tests.**
- 02/10/2026, message 1, § 41 : « Ne saute pas les tests. » ; § 29 : tests critiques (un locataire ne lit, ne modifie, ne supprime ni ne télécharge rien d'un autre ; deux utilisateurs simultanés n'obtiennent jamais le même numéro).
- Statut : tient (`CLAUDE.md`, « Test Enforcement »).

**D17. Une explication ne remplace jamais un test.**
- 05/10/2026, message 69 : il recopie une phrase de Claude qui disait ne pas avoir pu tester certains garde-fous, puis répond : « Il faut que tu le fasse. » ; message 70 : « les teste ».
- Ce que cela implique : chercher le chemin testable (test de concurrence sous PostgreSQL, appel direct de l'action, faux service). Ne jamais écrire « difficile à tester » à la place d'un test.
- Statut : tient. Écrit seulement dans `HISTORIQUE.md` (leçon 21).

**D18. Des milliers de cas pour les calculs.**
- 06/10/2026, message 92 : « quand tu fais les test "CalculateurLigneTest " alors tu fait pas just ce que je t'ai donné mais tu mets au moin 1000 exemple comme tu sais en sur »
- Ce que cela implique : tests de propriétés à graine fixe (jamais de hasard réel : règle T1 de `CLAUDE.md`), comparés à un calcul de référence exact, en plus des exemples qu'il donne.
- Statut : tient.

**D19. Les fournisseurs externes : tout préparer selon leur documentation, tester avec des faux.**
- 08/10/2026, message 137 : « mais toi tu creer quand meme et tu le prepare tout comme ils disent et tu fait les testes avec des faux donnes pour tester »
- 10/10/2026, message 156 : « donc tu as son api et tu utilse l'api comme tu connais mais pas envoyer de vrais requetés  à SUPER PDP mais juste tu utulise son api et tu fais tous les testes que lui peux envoyer, comme des refus et des accepte, donc tout. »
- Ce que cela implique : chaque fournisseur (INSEE, INPI, La Poste, SUPER PDP) derrière une interface avec une implémentation factice qui simule toutes les réponses (acceptations, refus, erreurs) ; aucune vraie requête (§ 3 de `README.md`).
- Statut : tient.

**D20. Les règles de la campagne de tests.**
- 10/10/2026, message 156 (extraits) : « Ne prétends jamais avoir exécuté un test si tu n'as pas réellement pu le lancer. » ; « N'annonce jamais que PILIQI est exempt d'erreurs simplement parce que tous les tests passent. Distingue clairement ce qui a été vérifié de ce qui reste à valider. » ; « Je veux que chaque fonctionnalité soit testée individuellement, mais aussi en interaction avec les autres fonctionnalités. » ; « Il ne suffit pas que les opérations retournent un statut de réussite. » ; pour chaque anomalie : « Identifie sa cause. Crée un test reproductible qui démontre le problème. Corrige le code. Relance le test concerné. Vérifie que la correction n'introduit pas d'autres régressions. » ; « Cette campagne doit être planifiée après la finalisation des  lots actuellement en cours, sans interrompre le travail existant. »
- Ce que cela implique : ces phrases valent pour tout compte rendu, pas seulement pour la campagne. Le rapport de campagne contient ce qu'il énumère (scénarios, volumes, concurrence, erreurs et corrections, temps de réponse, fonctionnalités non couvertes, limites, commandes pour reproduire). La stratégie est écrite dans `notes/campagne/strategie/finale.md` (10/10/2026, avant l'architecture société : à remettre d'accord, `README.md`, § 6) ; les choix T1 à T6 sont dans `notes/societe/reponses-serie4.md` et priment sur elle (T5 : Claude classe la gravité des anomalies, l'éditeur ne valide que le déclassement d'une anomalie bloquante) ; l'étape 1 bis de `CLAUDE.md` les résume.
- Statut : tient.

**D21. Dire ce qu'on fait des échecs.**
- 10/10/2026, message 159 : « tu fais quoi avec les echecs ? » ; 11/10/2026, message 175 : « Tu fait quoi avec ceux qui ont echué ».
- Ce que cela implique : chaque compte rendu dit, pour chaque échec (test, agent, CI) : la cause, la correction, et si c'est réglé ou encore ouvert.
- Statut : tient.

### 1.5 Développement : repartir de zéro et réécrire

**D22. On repart de zéro à chaque changement, on code comme pour la production.**
- 07/10/2026, message 107 : « EEETTTT une CHOSSEEE il faut que tu saches, quand tu creer de nouveau table ou regles tu jamais te bloquer avec ceux qui ont ete deja creer car pour l'instian on est en developement mais tu reagit tout comme on en production, donc on refait à chaque fois des debut poour ne pas encombre les base do donnes pour rien, donc a chaque modification il faut qu'on supprime tout et recommencer à zero pour ne pas creer des tables ou fonction pour rien et comme ca meme pour moi sera plus propre à comprendre la logique du code. »
- Ce que cela implique : une seule migration de création par table, modifiée sur place, puis `php artisan migrate:fresh` ; aucun code de reprise de données. Écrit dans `CLAUDE.md` (« Développement : toujours repartir de zéro »).
- Statut : tient jusqu'à la mise en production.

**D23. Réécrire le code dès qu'il devient plus simple et plus lisible.**
- 08/10/2026, message 125 : « donc tu mets dans la CLAUDE.md que à chaque modification ou rajoute du code et si le code peux se reduire pour qu'il soit plus lisible par moi alors tu refait tout pour que ce soit propr, donc tu creer pas des ou des foncstion ou des tables ou quoi que ce soit juste pour ne pas supprimer ce qui a été fait. »
- Ce que cela implique : écrit dans `CLAUDE.md`. Il lit le code lui-même (messages 18, 268 à 270) : le code doit rester lisible par lui.
- Statut : tient.

**D24. `CLAUDE.md` suit les décisions, avec son accord.**
- 08/10/2026, message 126 : « mais tu me dis d'abord est ce que c'est bon ou d'ajoute ca »
- 18/10/2026, message 213 : « est ce que t'as mis à jour claude.md ? »
- Ce que cela implique : il vérifie que ses décisions arrivent dans `CLAUDE.md`. Seul lui en change les règles : proposer le texte et attendre son accord, sauf les textes déjà approuvés dans un fichier de décision (§ 2 de `README.md`).
- Statut : tient.

### 1.6 Les règles légales et les sources

**D25. Pas de comptable : chercher les règles sur les sites officiels.**
- 06/10/2026, message 102 : « tu dis "Ma recommandation : oui. À ma connaissance, l'e-reporting doit déclarer les montants après remboursements. Confirme-le avec ton comptable, parce que c'est une règle fiscale.", j'ai pas de comptable, je creer pas le logiciel à l'aide d'un comptable. mais il faut savoir la realite et donc toi tu peux savoir en regandant les regles sur internet dans les site officiel de l'etat et aussi des comptable car eux mettent des info crucial sur leurs site »
- Ce que cela implique : ne jamais le renvoyer vers « votre comptable ». Chercher sur impots.gouv.fr, Légifrance, le BOFiP, les sites de cabinets (WebSearch, car le proxy bloque souvent WebFetch et curl), lire les documents qu'il envoie (messages 103, 162 à 165), citer la source. Seules restent « à faire confirmer par l'expert-comptable de la société » les règles que `CLAUDE.md` marque ainsi : c'est le comptable de chaque société cliente, pas le sien.
- Statut : tient. Écrit dans `HISTORIQUE.md` ; absent de `README.md`.

**D26. Justifier une affirmation sur un tiers.**
- 08/10/2026, message 136 : « comment tu le sais qu'il faut avoir un compte tous les magasin chez SUper pdp ? »
- Ce que cela implique : toute affirmation sur la loi ou sur un fournisseur cite sa source (page, section de la spécification). Une affirmation non vérifiée qui se révèle fausse se corrige aussitôt auprès de lui (`HISTORIQUE.md`, leçon 30).
- Statut : tient.

**D27. Ne pas coder les règles françaises en dur partout.**
- 02/10/2026, message 1, § 7 : « Ne pas hardcoder les règles françaises partout dans le code. Créer une architecture permettant éventuellement de supporter d’autres pays. »
- Ce que cela implique : les règles fiscales passent par le moteur de TVA centralisé (demande du 11/10, message 172, `notes/tva/demande-editeur.md`) ; le taux par défaut de 20 % reste réglable (décision B2).
- Statut : tient.

### 1.7 Sécurité, isolation, confidentialité

**D28. Isolation absolue entre sociétés et entre magasins.**
- 02/10/2026, message 1, § 5 : « JAMAIS retourner les données. […] Aucune fuite de données inter-tenant ne doit être possible. » ; § 4 : « Mais ne jamais sacrifier la sécurité pour réduire le code. »
- 07/10/2026, message 107 : « donc chaque magasin les meme s'il sont les memes. on melanges jamais de la vie avec un autre, donc je t'avais qu'on doit commter que chaque magasin utilise son logiciel donc pas de melanges zeeroooo. »
- Ce que cela implique : `BelongsToMagasin`, `BelongsToSociete`, filtres explicites, règles `unique` limitées (protocole, point 2 de `CLAUDE.md`). Même deux services identiques dans deux magasins restent deux fiches distinctes.
- Statut : tient.

**D29. Chaque magasin doit croire qu'il a son propre logiciel.**
- 04/10/2026, message 20 : « pour en salarie il n'y aura jamais ailleri comme t'as "Si son compte est aussi utilisé ailleurs, il reste intact.'" donc un proprietair ne doit jamais savoir si son salairie est ailleurs, donc aucun magasin ne pourras savoir et avoir une liaison, donc quand le system parle à un magasin alors l'impression doit etre comme si c'est son logiciel personelle et ne pas laisser comprendre que ici dans ce systeme on est melanger, donc on doit parler et gere comme un compte dedier à magasin. »
- 03/10/2026, message 19 : « donc il faut jamais laisser un tel message, il faut generaliser par exemple "La journée du {$dateCloture} a déjà été clôturée" sans jamais parle de magasin, donc tu fait ce genre de message partout »
- Ce que cela implique : deux règles, que l'architecture société (plusieurs magasins par société, une personne avec un compte dans deux magasins) a changées en partie : (1) un message affiché aux comptes d'un magasin (refus, alerte, notification) ne dit pas « ce magasin » ni « pour ce magasin » ; (2) aucun écran ni aucun message du panneau d'un magasin ne laisse deviner qu'il existe d'autres magasins ou d'autres sociétés (pas de « ce compte est utilisé ailleurs »). `CLAUDE.md` en garde l'esprit pour les unicités globales (« qu'aucun écran ni aucun message ne révèle à un tiers »), et elles sont désormais tranchées dans `notes/decisions/reponses-regles-ecran-26-10.md` (voir le statut). Le code poussé ne suit déjà plus la règle (1) partout : « … dans ce magasin. » dans `LecteurDesDroits::REFUS` et dans les `REFUS_INTROUVABLE` de `FactureAchat`, `Facture`, `Devis`, `InventaireOuverture`, `EnvoyerDocumentAction` et `ArchiverUneFicheAction`, motif « L'établissement de ce magasin (SIRET …) n'est pas encore confirmé… » de `Magasin.php`. L'espace d'administration de la société montre plusieurs magasins par nature : aucune des deux ne s'y applique telle quelle.
- Statut : tranché par l'éditeur le 26/10/2026 (`notes/decisions/reponses-regles-ecran-26-10.md`, qui fait foi) : la règle (1) est abandonnée (un message affiché aux comptes d'un magasin peut dire « ce magasin » ; les messages existants restent tels quels) ; la règle (2) tient (aucun écran ni aucun message du panneau d'un magasin ne laisse deviner d'autres magasins ou sociétés ; le lot 21 la teste et prépare son texte de `CLAUDE.md`).

**D30. Les principes de sécurité du prompt maître.**
- 02/10/2026, message 1 : § 23 « Le Super Admin SaaS ne doit pas automatiquement avoir accès à toutes les données métier sans mécanisme explicite et audité. » ; § 27 « Le cache doit toujours respecter le tenant. » ; § 28 « Un job appartenant à l’entreprise A ne doit jamais utiliser accidentellement le tenant B. » ; § 21 « Ne jamais permettre l’accès à un fichier simplement en connaissant son chemin. » ; § 33 « Ne jamais compter uniquement sur PHP pour empêcher les doublons. » ; § 34 « Ne pas compter uniquement sur Laravel pour garantir l’intégrité. » ; § 35 jamais de clés, secrets, mots de passe ou jetons dans le code ; § 36 ne jamais écrire dans les journaux mots de passe, jetons, secrets, données bancaires sensibles ni informations personnelles inutiles ; § 20 « Les factures et documents soumis à des obligations légales de conservation doivent être traités séparément des données pouvant être supprimées immédiatement. »
- Ce que cela implique : ces principes guident chaque lot et chaque relecture de sécurité. `CLAUDE.md` reprend l'accès explicite et audité de l'éditeur (étape 13), les secrets hors du dépôt, la conservation et le RGPD (étape 9), mais pas les règles du cache et des jobs.
- Statut : tient.

**D31. Les identifiants secrets ne passent jamais par la conversation.**
- 08/10/2026, message 140 : « je fait comment pour te donnes en vrais les identifiants » ; messages 141 à 147 : il montre des secrets du bac à sable en capture et ouvre lui-même l'accès réseau.
- Ce que cela implique : ne jamais lui demander de coller une clé ; seulement des variables d'environnement ; ne jamais répéter un secret qu'il aurait collé ; lui conseiller de renouveler les secrets exposés (§ 3 de `README.md`, à garder mot pour mot).
- Statut : tient.

### 1.8 Consignes générales sur les écrans

Ces consignes viennent de ses essais de l'application sur son PC (captures d'écran). Elles valent pour tout le logiciel, pas seulement pour l'écran d'origine.

**D32. Jamais d'erreur technique à l'écran : une alerte claire.**
- 04/10/2026, message 67 : « mais le gros probelem est qu'il faut pas affiche ce genre d erreur pour les magasin en production mais les message comme alerte, et ca partout dans le logiciel si il y a qqch qui doit pas etre fait »
- Ce que cela implique : toute règle métier refusée s'affiche en notification compréhensible (trait `GereLesExceptionsMetier` des pages Filament) ; jamais une trace d'exception.
- Statut : tient. Écrit seulement dans le code et `HISTORIQUE.md`.

**D33. Ne jamais changer ce que la personne tape ; calculer en direct.**
- 04/10/2026, message 58 : « donc tu mets tous les input live pour que le magasin voit en temps reel et non apres cliquer ailleur »
- 05/10/2026, message 87 : « il y a une grande anomalie ou probleme qui est ridicule. quand je tape sur les input alors il enleve ou change les valuer que je mets, donc je commenece par exmple 5 et je veux mettre 5.5 et me le met 5, donc ca c'est inaceptable. il doit jamais changer ce que je tappe mais juste calculer pour les autres ce que je tappe sans changer dans l'input ou j'ecris »
- Ce que cela implique : les champs voisins se recalculent pendant la frappe ; le champ en cours de frappe n'est jamais réécrit.
- Statut : tient. Écrit seulement dans le code et `HISTORIQUE.md`.

**D34. Après un enregistrement, retour à la page parente.**
- 05/10/2026, message 71 : « dans la page edit une facture en  brouillon quand je veux ajouter des produits et quand je sauvegarde alors la sauvegarde se fait mais reste toujour sur la meme page, donc il faute retourner dans le pages factures. »
- 08/10/2026, message 110 : « la quand le magasin sauvegarde alors il doit etre redirige vers l' equipe et pas rester sur la meme page comme si c'est rien passé, donc pour tous les sauvegarde qui se trouve sur le logiciel doivent se rediriger si la page a sa page parent. »
- Statut : tient. Écrit seulement dans le code.

**D35. Cases à cocher plutôt que menus déroulants quand c'est plus rapide.**
- 08/10/2026, message 110 : « il faut laisser que des case à cocher et pas menu deroulant car sera plus rapide comme ca. »
- Statut : tient (repris pour les services dans `notes/serie8/ecrans-de-vente.md`).

**D36. Des écrans compacts, des nombres propres, des libellés exacts.**
- 04/10/2026, message 57 : « En plus de ca tu reduit un peu les columns et text et size pour qu'il prend pas trop d'espce sur l'ecran »
- 06/10/2026, message 98 : « la quantite tu mets jamais comme ca 2.000 mais tu mets 2 » ; message 99 : « non pour quantite n'existe pas "uniforme du type « 2 », « 1,5 », « 20 % », « 5,5 %" il y a que des nombre fix »
- 08/10/2026, message 117 : « la t'as mal fait, le prix de vente est bien  12€ttc mais toi t'as mis en bas 0.56€ttc […] pourquoi tu fais des erreurs alors comment je peux avoir confience en toi »
- 17/10/2026, message 210, point 12 (traduit dans `notes/serie8/demande-editeur.md`) : pas de total HT sous chaque ligne pendant la saisie, le total est en bas.
- Ce que cela implique : quantités entières affichées sans décimales ; chaque libellé dit exactement ce qu'est le chiffre (un montant juste mal nommé passe pour une erreur, `HISTORIQUE.md`, leçon 24) ; prix unitaires affichés au centime (lot PU).
- Statut : tient.

**D37. Les dates proposées et les dates interdites.**
- 05/10/2026, message 72 : « "Échéance" doit mettre automatique la date de la journnes et si veux changer alors le magasin appuie pour la changer. Et aussi il faut interdire de choisir la date encien mais uniqument à partire de la journee. » ; message 75 : « meme ici il faut interdire la date encien, tu fait comme t'as tout a l heure. »
- 04/10/2026, message 39 : « je vois que tu as laisser l'option de choisir la de cloture mais la ne doit pas etre une option […] donc quand il cloture alors il cloture de journee elle meme »
- Statut : tient. La clôture sans choix de date est écrite dans `CLAUDE.md` ; l'échéance préremplie et l'interdiction des dates passées ne le sont que dans le code.

**D38. Tout document cité s'ouvre d'un clic ; l'important se voit en orange.**
- 04/10/2026, message 63 : « sera bien de pouvoir appuyer sur l'avoir "AV-2026-000001 " pour pouvoir voir la date et tout ce qui le concerne. […] c'est mieud de mettre la couleur orange pour qu'il soit visible et qu'il attire l'intention du vendeur. » ; 05/10/2026, message 75 : « j'ai dit que l'avoir doit etre cliquable pour te ramener dans l'avoir pour voir l'avoir quand et à qui est fait »
- 17/10/2026, message 210, points 19 et 25 : retours en orange sous les lignes d'achat ; numéros de tickets et d'avoirs cliquables dans les clôtures.
- Statut : tient (points de la série 8 dans `CLAUDE.md` et `notes/serie8/`).

**D39. Créer sur place ce qui manque, chercher vite.**
- 03/10/2026, message 15 : « si le client existe pas alors doit avoir un button quelque part pour créer un nouveau client, ce button doit afficher un modal ou une tell chose ou il affiche le formulaire. Donc ce fonctionnalité doit etre accessible pour tout le magasin. »
- 03/10/2026, message 18 : « quand il cherche le produit alors il doit pouvoir cherche par scanner le code barre du produit et le il se cherche automatiquement apres scanner le code barre. »
- 17/10/2026, message 210, points 13 et 22 : grands tableaux de sélection des produits et des clients, filtres, plusieurs choix à la fois.
- Limite : un fournisseur ne se crée jamais depuis une facture d'achat (message 110 : « il faut pas que le magasin puisse creer un fournisseur depuis la creation des factures d'achat, donc la creation de fournisseur faut faire que dans la page "Fournisseurs". »), et un produit ne naît que d'un achat ou d'un inventaire d'ouverture.
- Statut : tient.

**D40. Avertir avant un geste irréversible.**
- 03/10/2026, message 19 : « il faut alerte avec message que si tu cloture tu peux plus venir en arrier pour modifier et tu peux plus creer un nouveau pour la meme journée, donc il doit sure pour cloture. »
- Statut : tient (`CLAUDE.md`, « Caisse et clôtures » : « la fenêtre de confirmation prévient »).

### 1.9 Continuité et notes de reprise

**D41. Aucune action manuelle à retenir.**
- 26/10/2026, message 275 : « Tu as dis dans la fiche que « Ce qui reste […] À la mise en production : régler le fuseau de la base PostgreSQL en UTC. C'est une seule commande, écrite dans le README, et `php artisan caisse:diagnostiquer` vérifie qu'elle a été faite. » mais moi peut être je vais oublier ou si je passe sur l’autre compte alors lui se rappelle pas. Je fais quoi ? »
- Ce que cela implique : tout ce qui doit être fait au déploiement est automatisé (migration, commande vérifiée) ou fait échouer le déploiement de façon visible. Appliqué au fuseau UTC par le lot L1 (`notes/a-faire-apres-chaine.md`, point 4, suite ; sa phrase de `CLAUDE.md` a été approuvée par l'éditeur le 26/10/2026). La même idée vaut pour toute future « étape à ne pas oublier ».
- Statut : tient.

**D42. Toutes les notes, tout de suite, pour qu'un autre compte continue à l'identique.**
- 25/10/2026, message 263 : « Mais si se rappelle pas de notre souvenir et les reponses décision alors comment lui va continuer le travail. Peut être il va faire autrement »
- 26/10/2026, message 276 : « Donc tu peux me donner ou mettre qq part tout tes notes de travail afin que l’autre compte puisse continuer ton travail comme s’il était toi »
- 26/10/2026, message 277 : « Mais tu mets à jour toujours tout dans cette branche afin qu’il ait toujours le dernier note donc sans laisser pour à la fin de mettre à jour mais tu le mets à chaque fois »
- 26/10/2026, message 278 : « Il faut que tu mettes tout s’il te plaît. Met tout ce qu’il faut avoir pour que lui travaille comme si c’était toi. […] Donc tout les directives dont par où commencer et tout tout parce que l’autre compte comment sauras quoi faire »
- 26/10/2026, message 280 (après le message 279 : « Tu as dis que l’agent « Il a relevé 18 manques ou erreurs. » et toi tu les a corrigés mais pourquoi lui a trouvé et pas toi avant ») : « Ban alors pourquoi tu fais pas comme ça, donc tu mets à jour la fiche et les agents commencent par ce fichier et comme ça ils vont trouver des erreurs s’ils existent. Ou peut être c’est pas bon » : relecture à froid de la fiche après chaque lot (étape `<lot>:fiche`, `VERIFICATION.md`).
- Ce que cela implique : `pilotage-sync.sh` après chaque nouveau message de l'éditeur (messages et résumés régénérés automatiquement), chaque décision, chaque changement de fiche ou de script, chaque événement de course, chaque point de suivi et chaque résultat de CI (§ 10 de `README.md`) ; une directive durable d'un nouveau message s'ajoute ici, citée mot pour mot. Chaque nouveau fichier de notes s'ajoute d'abord à `pilotage-fichiers.txt`, sauf les messages et les résumés régénérés et `retraits-locaux.txt`, qui n'y entrent jamais (§ 10 de `README.md`). Après chaque lot, un agent neuf relit la fiche à froid (étape `<lot>:fiche`).
- Statut : tient.

### 1.10 Langue

**D43. Tout en français.**
- 17/10/2026, message 210 : il écrit ses retours en grande partie en albanais. `HISTORIQUE.md` rapporte sa consigne du même jour : « tu mets le texte en français, jamais albanais » (réponse donnée hors des messages tapés, par la fenêtre de questions).
- Ce que cela implique : réponses, code, commentaires, commits, documents, interface : tout en français. Ses textes en albanais se traduisent en français dans le fichier de demande (exemple : `notes/serie8/demande-editeur.md`).
- Statut : tient. Écrit au § 2 de `README.md`.

### 1.11 Outils et environnement qu'il a choisis

**D44. Filament.**
- 02/10/2026, message 2 : « Pour livewire je sais pas mais je crois que c’est mieux avec filament car lui il a compris tout. »
- Statut : tient (Filament 3.3).

**D45. SUPER PDP comme plateforme agréée.**
- 02/10/2026, message 3 : « Il faut pas oublier que la création des factures x doivent envoyer et récupérer à partir d’un api super pdp (normalement) » ; 08/10/2026, message 130 : « et pour l'api je vais utiliser SUPER PDP, voici le lien avec api reste: https://www.superpdp.tech/openapi/#superpdp »
- Statut : tient (étape 2 de `CLAUDE.md`, `notes/superpdp-plan.md`, `notes/superpdp/openapi.json`).

**D46. PostgreSQL à la place de MySQL.**
- 02/10/2026, message 1, § 2 : « MySQL 8+ » dans la pile de départ.
- 10/10/2026, message 157 : « c'est qui le meilleur entre "MariaDB, MySQL, PostgreSQL ou SQLite" , SUPER PDP utilise PostgreSQL » ; message 158 : « tu aplique celui qui est meilleur ».
- Statut : MySQL **remplacé**. Tient : PostgreSQL en production, SQLite en développement, suite complète sur les deux (`CLAUDE.md`, `notes/moteurs/choix-moteur-finale.md`). De la pile du prompt maître, Pest a été écarté (PHPUnit), Spatie retiré (message 174), Redis et l'analyse statique sont prévus à l'étape 15 et dans la campagne.

**D47. Le modèle Fable pour les écrans, si nécessaire.**
- 08/10/2026, message 128 : « Conçois toutes les interfaces graphiques (les écrans Blade, les formulaires, le tableau de bord) pour que le logiciel soit entièrement utilisable par les magasins, en utilisant Fable si nécessaire. » ; 12/10/2026, message 198 : « comence avec fable ».
- Ce que cela implique : « Fable » est un modèle Claude, que l'éditeur choisit pour la session, pas un outil : il n'y a aucun outil à chercher. Après le message 198, la ligne d'attribution des scripts de chaîne est passée à « Fable 5.1 », puis revenue à « Opus 5.5 » (`HISTORIQUE.md`, § 2, lundi 12/10). C'est une autorisation pour la conception des écrans, pas une obligation. La ligne d'attribution des commits reste celle que donne le harnais de la session (`README.md`, § 2), et aucun identifiant de modèle n'entre dans le code.
- Statut : tient comme autorisation.

**D48. L'ordre du prompt maître est remplacé par la feuille de route.**
- 02/10/2026, message 1, § 41 (format de livraison en 17 points) et § 44 (phases 1 à 8).
- Statut : **remplacés**. Tient : la feuille de route de `CLAUDE.md` (étapes 1 à 15) et la chaîne de lots ; chaque lot se termine par un compte rendu en français simple (§ 3.4 ci-dessous).

### 1.12 Changements d'avis sur la façon de travailler (résumé)

| Sujet | D'abord | Ensuite | Ce qui tient |
|---|---|---|---|
| Validation avant de coder | Attendre sa validation (msg 1, 02/10) | « tu fais beaucoup meilleurs » (msg 8), mandat total (msg 128, 08/10) | Autonomie ; seules les règles métier se posent |
| Moteur de base | MySQL (msg 1) | « tu aplique celui qui est meilleur » (msg 158, 10/10) | PostgreSQL + SQLite |
| Spatie | Garder un paquet éprouvé (msg 173, 11/10) | « c'est toi qui dois décider » (msg 174) | Autorisations propres à PILIQI, référentiel en base |
| Durée du travail | « ca a duré longtemps » (msg 155, 10/10) | « On va pas changer rien » (msg 241), « le plus sur » (msg 242) | Protocole complet, ultracode |

---

## 2. Décisions métier tranchées dans la conversation

Les 85 décisions de la page d'arbitrages (A1 à K10, 9 octobre) sont dans `notes/decisions-editeur.md` ; les séries suivantes ont leurs fichiers de réponses (index au § 7 de `README.md`). Ce tableau ne reprend que les décisions **tapées dans la conversation**, avec le fichier qui les porte. Les chemins `notes/…` sont ceux de la branche `claude/pilotage-reprise`.

### 2.1 Tableau

| Msg | Date | Décision de l'éditeur (résumé) | Fichier qui la porte | Statut |
|---|---|---|---|---|
| 3, 4 | 02/10 | Factures émises et reçues par l'API SUPER PDP ; achats et retours fournisseur récupérés automatiquement, stock mis à jour | `CLAUDE.md` (étape 2, « Facture électronique ») ; `notes/superpdp-plan.md` | Tient |
| 5 | 02/10 | Mandat accepté d'un clic sur le site | — | **Remplacée** par la délégation OAuth de chaque société (msgs 136, 137 ; `CLAUDE.md`, étape 2) |
| 8 | 02/10 | Envoi automatique de chaque facture à sa validation, pas groupé ; clôture automatique à minuit | `CLAUDE.md` (« Caisse et clôtures », étape 2) ; décision E14 | Tient |
| 8 | 02/10 | Les comptables ont déjà accès par la réforme : pas d'espace à eux | — | **Remplacée** par l'espace des cabinets (msg 230 ; `notes/serie8/decision-espace-cabinets.md`, `reponses-cabinets.md`) |
| 12 | 03/10 | Devis et lignes libres sans produit enregistré | — | **Remplacée** : une ligne vend un produit existant ou un service (msgs 106, 110) ; `CLAUDE.md` |
| 12 | 03/10 | « Une facture ne doit jamais se supprimer car c’est interdit par la loi. » | `CLAUDE.md` | Tient |
| 12 | 03/10 | Vendeurs créés par le propriétaire, sans confirmation d'e-mail | `CLAUDE.md` (comptes) ; `notes/serie8/reponses-serie8.md` (Q4, précisions) | Tient (affiné : identifiant de 8 chiffres, premier mot de passe égal à l'identifiant) |
| 14 | 03/10 | Facture brouillon sans numéro définitif ; numéro à la validation ; plus de suppression ensuite | Code ; `CLAUDE.md` ne l'écrit que pour les devis | Tient — **absente des fichiers de décision** (§ 2.2) |
| 15 | 03/10 | Aucune modification ni suppression d'une facture | `CLAUDE.md` (« une facture émise ne change plus jamais ») | Tient pour une facture émise ; précisée par msg 19 pour le brouillon |
| 15 | 03/10 | Clients visibles par toute l'équipe ; création d'un client depuis la facture, dans une fenêtre | Code ; `notes/serie8/ecrans-de-vente.md` (sélecteurs) | Tient |
| 15 | 03/10 | Valeur du stock et TVA du stock visibles du propriétaire et de qui il autorise | `notes/serie8/reponses-serie8.md` (Q9) ; autorisations de `CLAUDE.md` | Tient |
| 15 | 03/10 | Le propriétaire donne des autorisations par écran à ses salariés | `CLAUDE.md` ; décision G4 | Tient |
| 18 | 03/10 | Une facture peut venir d'un devis, jamais obligatoirement ; un devis se modifie | `CLAUDE.md` (devis) | Tient |
| 18 | 03/10 | Recherche d'un produit par lecture du code-barres | `notes/serie8/ecrans-de-vente.md`, `equipe-et-achats.md` | Tient |
| 19 | 03/10 | Avoir fournisseur sans toucher la facture d'achat, lié à sa facture | `CLAUDE.md` (« Achats et stock ») | Tient |
| 19 | 03/10 | Avoir de vente sur les seuls articles choisis | `CLAUDE.md` (retours partiels) | Tient |
| 19 | 03/10 | Brouillon modifiable, et supprimable en entier d'un geste | Code ; lots FA1 à FA3 (`notes/fa/`) | Tient — **absente comme règle écrite** (§ 2.2) |
| 19 | 03/10 | Clôtures de caisse visibles par le vendeur, le propriétaire et les autorisés | `CLAUDE.md` (« Ma caisse », « Les caisses ») | Tient |
| 19 | 03/10 | Messages sans le mot « magasin » | `HISTORIQUE.md` ; plus suivie partout dans le code | **À faire confirmer** (D29) — absente des fichiers de décision |
| 19 | 03/10 | E-reporting non quotidien ; données obligatoires à chercher | `CLAUDE.md` ; `notes/decisions/reponses-complements-14-20.md` (point 11) | Tient (SUPER PDP regroupe et transmet, msg 167) |
| 20 | 04/10 | Un vendeur ne clôture pas la journée sans autorisation ; il ferme sa caisse | `CLAUDE.md` | Tient |
| 20 | 04/10 | Devis figé une fois converti en facture | `CLAUDE.md` | Tient |
| 20 | 04/10 | Un avoir peut reprendre plusieurs factures ; plusieurs avoirs successifs possibles | `CLAUDE.md` (avoirs multi-factures, retours cumulés) | Tient |
| 20 | 04/10 | Facture d'achat modifiable si saisie à la main, pas si reçue par API ; avoirs non modifiables | Code seulement (`FactureAchat::estModifiable()`) : ni `CLAUDE.md` ni C2 (qui ne dit que l'autorisation de valider) ne l'écrivent (§ 2.2, point 10) | Tient |
| 39 | 04/10 | Clôture du jour sans choix de date | `CLAUDE.md` | Tient |
| 41 | 04/10 | « une vraie caisse et donc chaque vendeur sa caisse » | `CLAUDE.md` | Tient |
| 42, 43 | 04/10 | Ticket pour un particulier, facture pour un professionnel ; numérotations séparées | `CLAUDE.md` | Tient |
| 55 | 04/10 | Référence produit facultative, sinon générée comme un numéro de séquence | Code seulement (`CLAUDE.md` ne dit que l'unicité) | Tient — **absente des fichiers de décision** (§ 2.2) |
| 55 | 04/10 | Une facture d'achat porte deux numéros : celui du logiciel et celui du fournisseur | Analyses `notes/serie8/equipe-et-achats.md`, `notes/tva/ergonomie-donnees.md` (le n° du fournisseur y est noté facultatif) | **Absente comme règle** (§ 2.2) |
| 56 | 04/10 | Calcul en centimes entiers | `CLAUDE.md` | Tient |
| 57, 77 | 04-05/10 | Le prix de vente se saisit en TTC | `CLAUDE.md` (« Prix et TVA des ventes ») | Tient |
| 60 | 04/10 | Un avoir donne un crédit imputé sur les achats suivants ; type 381 pour l'avoir électronique | `CLAUDE.md` (imputation) ; `notes/superpdp-plan.md` | Tient |
| 61 | 04/10 | Alerter et attendre que toutes les caisses soient fermées avant la clôture | — | **Remplacée** par R5 : la clôture ferme d'office les caisses ouvertes (`notes/societe/reponses-serie4.md` ; `CLAUDE.md`) |
| 63 | 04/10 | Le PDF montre le paiement par crédit d'avoir | `CLAUDE.md` (PDF des ventes) | Tient |
| 64, 66 | 04/10 | Aucun paiement supérieur au restant dû, pas de trop-perçu en crédit | `CLAUDE.md` ; décision B3 | Tient |
| 76 | 05/10 | Une facture émise ne s'annule pas | `CLAUDE.md` (correction par avoir) | Tient |
| 77 | 05/10 | « le client comptoir ne doit jamais avoir un credit » | `CLAUDE.md` (bon d'avoir) | Tient |
| 78 à 97 | 05-06/10 | Calculer HT, TVA et TTC « séparément », article par article | — | **Remplacée** par la politique d'arrondi unique (msg 210, point 15 ; Q1-a « Oui, pour tous » ; R4 ; `CLAUDE.md`, « Prix et TVA des ventes ») |
| 98, 99 | 06/10 | Quantités en nombres entiers | `CLAUDE.md` (quantités décimales en saisie manuelle) ; `notes/serie8/decision-types-activite.md` | Tient |
| 106, 109 | 07/10 | Bouton « Ajouter un service », prix TTC saisi ; un produit est un bien, un service est un service | `CLAUDE.md` (services, prix de référence) ; `notes/serie8/ecrans-de-vente.md` | Tient |
| 107 | 07/10 | Logiciel indépendant ; aucun mélange ; repartir de zéro | `CLAUDE.md` | Tient |
| 110 | 08/10 | Une ligne de vente ne change ni la description ni le prix de la fiche | `CLAUDE.md` (prix dérogatoire seulement avec l'autorisation des remises) | Tient (affinée par R2 et Q1 de la série 5) |
| 110, 111 | 08/10 | Un produit naît d'une facture d'achat | `CLAUDE.md` ; `notes/serie8/demande-editeur.md` (point 18 : achat ou inventaire d'ouverture) | Tient |
| 111, 112 | 08/10 | Achats auprès de particuliers : « on laisse tomber pour l'instant […] on va revenir » | `CLAUDE.md` (étape 14) | Reporté (§ 4) |
| 112 | 08/10 | Coût moyen pondéré (CMUP) | `CLAUDE.md` | Tient |
| 112 à 115 | 08/10 | Barème de prix de vente réglable par le magasin, exemples réels proposés, désactivé par défaut mais visible | `CLAUDE.md` (barème parmi les paramètres locaux) ; valeurs par défaut dans le code | Tient |
| 116, 121 à 124 | 08/10 | Seul le prix de vente TTC s'arrondit, une fois, à l'enregistrement de l'achat ; ensuite rien ne se recalcule | `CLAUDE.md` | Tient |
| 136, 137 | 08/10 | Connexion SUPER PDP par OAuth, préparée selon la documentation et testée avec des faux | `CLAUDE.md` (étape 2) | Tient |
| 156 | 10/10 | Campagne de tests très approfondie | `CLAUDE.md` (étape 1 bis) ; `notes/societe/reponses-serie4.md` (T1 à T6) ; `notes/campagne/strategie/finale.md` | Tient |
| 157, 158 | 10/10 | PostgreSQL | `CLAUDE.md` ; `notes/moteurs/choix-moteur-finale.md` | Tient |
| 167 | 11/10 | SUPER PDP regroupe et transmet au PPF ; PILIQI ne tient pas de calendrier de déclaration | `notes/decisions/reponses-complements-14-20.md` (point 11) | Tient |
| 171 | 11/10 | Ticket transformé en facture : réglé en totalité par l'avoir d'annulation ; avoirs sur tickets par un vendeur autorisé | `notes/decisions/reponses-complements-14-20.md` (points 13, 14) ; `CLAUDE.md` (autorisations) | Tient |
| 172 | 11/10 | Moteur de TVA centralisé | `notes/tva/demande-editeur.md`, `reponses-serie7.md`, `plan-execution-decisions.md` | Tient (chaîne TVA à venir) |
| 173, 174 | 11/10 | Retrait de Spatie, autorisations propres | `CLAUDE.md` ; `notes/societe/reponses-serie4.md` (S12) | Tient |
| 176 | 11/10 | « chaque magasin a un seul responsable » ; l'administrateur ne crée pas de responsable | — | **Remplacée** le jour même : plusieurs responsables par magasin, administrateur autorisé à les nommer (`notes/societe/reponses-serie6.md`) |
| 177, 178 | 11/10 | « l'export comptable […] creer uniqument par le proptietaire », corrigé en « Je voulais dire expert comptable » | `notes/societe/reponses-serie6.md` (l'expert-comptable est créé et retiré par le propriétaire seul) | **Remplacée** ensuite par les cabinets (`notes/serie8/decision-espace-cabinets.md`) |
| 179 | 11/10 | Espace « charges du magasin » | `notes/tva/charges-demande-et-decisions.md`, `plan-execution-decisions.md` | Tient (lots de la chaîne TVA) |
| 181 | 11/10 | Inscription d'une société sans magasin | `notes/societe/inscription-sans-magasin.md` ; `CLAUDE.md` | Tient |
| 210 | 17/10 | Les 26 retours de la série 8 (mode « Avoir », retours par numéro, PDF, rôle après « Bonjour », administrateur sans vente, « Les caisses », pièce jointe d'achat, import automatique, mentions à 0 %, tableaux de sélection, journal, clôtures détaillées, factures à encaisser, équipe, produits nés d'un achat, avoirs fournisseur à la main, identifiant de connexion, e-reporting) | `notes/serie8/demande-editeur.md` (traduction), `reponses-serie8.md`, `plan-serie8.md` ; `CLAUDE.md` | Tient, sauf le point 9 (§ 4) |
| 210, point 6 | 17/10 | « Administratori nuk duhet leju me ba shitje » (l'administrateur ne doit pas vendre) | `notes/serie8/avenant-S8V-A4c.md`, `reponses-serie8.md` | Affinée : il ne vend que si le propriétaire lui coche la vente (`CLAUDE.md`) |
| 212 | 18/10 | Premier mot de passe égal à l'identifiant, changement facultatif avec une alerte | `notes/serie8/reponses-serie8.md` (précision 5) ; `CLAUDE.md` | Tient |
| 227 à 229 | 19/10 | SIREN unique ; vérification par le seul courrier recommandé, remis au dirigeant en personne ; réponses A1, B1, C1, D1, E2 | `notes/serie8/decision-verification-courrier.md`, `reponses-v2.md` ; `CLAUDE.md` | Tient |
| 230, 231 | 19/10 | Espace des cabinets d'expertise comptable ; réponses F1, G2, H1, I1, J1, K2 | `notes/serie8/decision-espace-cabinets.md`, `reponses-cabinets.md`, `avenant-cabinets.md` | Tient (lot EC à venir ; `CLAUDE.md` décrit encore l'ancien expert-comptable jusqu'à EC) |
| 232 | 19/10 | Accord pour `bacon/bacon-qr-code` | `notes/serie8/reponses-serie8.md` | Tient |
| 255 à 260 | 25/10 | Prix unitaires affichés au centime, calcul exact inchangé | `notes/serie8/reponses-affichage-pu.md` | Tient (lot PU) |
| 256 | 25/10 | « En fait les mentions je suis contre mais je sais pas si c’est mieux ou pas » | — | **Remplacée** : la note de calcul des PDF est gardée et complétée (`notes/serie8/reponses-affichage-pu.md`, dernier texte) |
| 275 | 26/10 | Fuseau UTC de la base fixé par les migrations, contrôlé par `caisse:diagnostiquer` | `notes/a-faire-apres-chaine.md` (point 4, suite) | Tient (lot L1) |

Les réponses données par la fenêtre de questions ou par une page d'arbitrages ne figurent pas toutes dans les messages tapés : elles sont citées dans leurs fichiers de réponses (séries 4 à 8, V2, cabinets, types d'activité, T2, T3, PU).

### 2.2 Décisions de la conversation absentes de tout fichier de décision et de `CLAUDE.md`

Elles sont appliquées dans le code depuis leur demande (sauf la règle (1) du point 1), mais aucun fichier de décision ni `CLAUDE.md` ne les écrit. Une nouvelle session qui réécrit le code pourrait les perdre : à garder, et à proposer à l'éditeur pour `CLAUDE.md` (§ 4, point 12). Le lot 21 met chacune dans sa matrice décision → test et propose dans « laisses » le texte à soumettre à l'éditeur, sans l'écrire dans `CLAUDE.md`.

1. Messages à l'utilisateur sans le mot « magasin », et aucun message ne laisse deviner d'autres magasins ou sociétés (messages 19, 20) : tranché le 26/10/2026 dans `notes/decisions/reponses-regles-ecran-26-10.md` (première règle abandonnée, seconde maintenue) ; voir D29.
2. Erreurs métier affichées en alertes claires, jamais en erreur technique, partout (message 67). Écrit seulement dans `HISTORIQUE.md`.
3. Le champ en cours de frappe n'est jamais modifié ; les autres champs se calculent en direct (messages 58, 87).
4. Après chaque enregistrement, retour à la page parente (messages 71, 110).
5. Échéance préremplie à la date du jour, et aucune date passée dans les champs de date concernés (messages 72, 75).
6. Facture brouillon sans numéro définitif, numérotée à la validation (message 14) ; brouillon modifiable et supprimable en entier d'un seul geste (message 19). `CLAUDE.md` ne l'écrit que pour les devis.
7. Référence produit facultative, générée automatiquement comme une séquence quand elle manque, y compris pour un produit reçu par API sans référence (message 55).
8. Deux numéros pour chaque facture d'achat : le numéro de séquence du logiciel et le numéro d'origine du fournisseur (message 55). Le numéro du fournisseur est aujourd'hui facultatif (`notes/tva/ergonomie-donnees.md`) ; l'e-reporting international l'exigera.
9. Quantités affichées sans décimales (« 2 », jamais « 2.000 ») (message 98). `CLAUDE.md` n'en parle qu'indirectement.
10. Une facture d'achat récupérée automatiquement (API, puis SUPER PDP) ne se modifie jamais. Un produit qui en vient garde ce que son fournisseur en dit (référence, code-barres, nom, taille, couleur), mais son prix de vente, sa TVA et son suivi du stock restent modifiables (messages 20, 110, 112 ; code : `FactureAchat::estModifiable()`, `Produit::vientDUneFactureAchatApi()` et `Produit::ATTRIBUTS_DU_FOURNISSEUR`).
11. Un produit se cherche aussi par son code-barres scanné (message 18).
12. Toute l'équipe qui en a le droit crée un client depuis la vente, dans une fenêtre (message 15).

---

## 3. Sa façon de communiquer et ce qu'il attend des réponses

### 3.1 Comment il écrit

- Il écrit vite, souvent depuis son téléphone, avec beaucoup de fautes et peu de ponctuation. Chercher ce qu'il veut dire, pas la lettre (exemple : « ultracode » écrit « ulteacode », message 245).
- Il écrit parfois en albanais (message 210). Toujours répondre en français et traduire ses textes en français dans le fichier de demande.
- Il envoie des captures d'écran de son essai local, souvent sans texte (messages 27, 33, 38…). Regarder chaque capture, dire ce qu'on y voit et ce qui sera corrigé.
- Il colle parfois de longs textes préparés ailleurs (prompt maître, message 1 ; campagne, message 156 ; moteur de TVA, message 172 ; méthode de calcul, message 81). Ce sont des demandes à prendre au sérieux, mais leurs conseils techniques restent à vérifier (message 81 : il propose lui-même une méthode, puis accepte la meilleure).
- Il change parfois d'avis, parfois plusieurs fois dans la même heure (prix unitaires le 25/10 ; édition des factures le 03/10). À chaque fois : mettre à jour le fichier de décision, le script de chaîne et la branche de reprise ; la dernière version du fichier fait foi.

### 3.2 Les mots qui ont un sens précis

- « Réessayer », « Réessayez », « Recommence », « J’ai atteint ma limite d’utilisation […] Veuillez continuer là où vous vous êtes arrêté » (messages 68, 119, 195 à 209, 233, 235, 239) : reprendre exactement là où le travail s'est arrêté, après avoir vérifié l'état (git, courses, agents).
- « c'est fait » (messages 145, 147, 150 à 152, 160, 186, 211) : il a rempli la page d'arbitrages ou fait le geste demandé (réglage, branche supprimée). Lire aussitôt ses réponses et les écrire dans un fichier de réponses.
- Des codes comme « A1,b1,c1,d1,e2 » (message 229) ou « f1, g2, h1, i1, j1,k2 » (message 231) : les options choisies dans une série de questions (lettre de la question, numéro de l'option). Une note libre ou un choix « autre » prime sur le texte de l'option.
- « continue tout seul jusqu'à la fin », « Continue comme prévu » : reprendre la chaîne dans l'ordre prévu, sans rien changer.
- « On va pas changer rien » : garder le protocole et le plan tels quels.

### 3.3 Comment lui poser une question

- Il veut comprendre ce qu'on lui demande : 06/10/2026, message 101 : « Tu m’expliques tout ça en détail pour bien comprendre qu’est-ce que tu demandes ».
- Il refuse les formulations ambiguës : 11/10/2026, message 173 : « Une dernière chose : « refus d'un compte jamais utilisé » est une formulation ambiguë. Il faudrait que Claude précise s'il parle d'un compte créé mais jamais activé, d'un compte qui ne s'est jamais connecté ou d'une autre règle. Ce sont trois situations différentes. »
- Quand il demande l'avis d'abord, donner l'avis avant de toucher au code : 04/10/2026, message 35 : « dit moi d'abord qu est ce tu pense avant que tu fasse des modifications. » ; message 181 : « dit moi c'est quoi meilleur ».
- Quand il propose une règle, vérifier la loi et le dire s'il se trompe : 03/10/2026, message 19 : « qu'est ce que tu pense? et par la loi c'est comment ?, donc tu fais le meilleur car je peux me tromper pour ca. »
- Forme qui marche : peu de questions à la fois ; chacune avec un identifiant, le contexte en une ou deux phrases simples, des options lettrées a, b, c, l'option recommandée marquée « (Recommandé) », un exemple chiffré, et ce que chaque option change pour un magasin. Une longue série passe par une page privée d'arbitrages (outil Artifact avec base de données) ; une courte, par la fenêtre de questions (si elle disparaît avant qu'il valide, la reposer : message 161, « J’ai pas pu valider tout, il s’est enlevé »).
- Ne poser que les règles métier. Le technique se décide seul (D7).

### 3.4 Ce qu'il attend des réponses

- Le vouvoyer (« vous », « votre »), même quand il tutoie, comme la session l'a toujours fait (`notes/serie8/reponse-editeur.md`, `notes/tva/resume-editeur.md`).
- Des explications simples, concrètes, avec des exemples chiffrés. Il donne lui-même ses exemples en chiffres (messages 88, 257 : « 65/1.2= calculer 54,1666666667 mais afficher 54.17 ») : répondre de la même façon.
- Aucun jargon sans explication. Il a demandé : « J’ai pas bien compris la partie que tu dis «  ce qui manque encore, relever en vérifiant « » (message 169), « C’est quoi fa1 2 3 » (message 267), « Qu’est ce que tu veux dire avec «  J’ai armé un guet. » » (message 271). Nommer un lot avec ce qu'il fait (« FA1 : protège les brouillons contre deux gestes faits en même temps »), et dire « je surveille la fin de l'étape » plutôt que « j'ai armé un guet ».
- L'exactitude avant tout. Une erreur visible lui fait perdre confiance : 08/10/2026, message 117 : « pourquoi tu fais des erreurs alors comment je peux avoir confience en toi » ; message 120 : « je comprends pas pourquoi tu fais encore des erreurs de ce genre. » ; 05/10/2026, message 73 : « ca c'est tres risque d'etre comme ca car le logiciel va se montre comme un logiciel mal fait. » Vérifier avant d'affirmer ; reconnaître une erreur tout de suite et dire comment elle est corrigée partout.
- L'honnêteté sur l'état du travail. Il croit parfois que tout est presque fini (message 247 : « Normalement tu a presque fini tout mes demandes, donc mon logiciel donc mon projet ») : dire précisément ce qui est fait, ce qui reste, et les ordres de grandeur (`README.md`, § 6).
- Après chaque lot poussé, dans un tour court, sans attendre la fin de la chaîne : ce qui change pour un magasin, avec des exemples chiffrés ; les défauts trouvés par les relectures ; ce que l'agent a laissé ; ce qui est vérifié et ce qui reste à valider (`README.md`, § 5, « Suivi »).
- Quand un commit change des migrations, lui rappeler pour son PC : `git pull`, `php artisan migrate:fresh`, `php artisan optimize:clear`, puis une nouvelle inscription (le lien d'inscription est dans `storage/logs/laravel.log`) (`HISTORIQUE.md`, § 5).
- Pour lui montrer un travail, une page privée (outil Artifact) avec des exemples marche bien : 26/10/2026, message 274 : « Tu as fini le T2! Montre moi de quoi il s’agit ».

### 3.5 Les questions qu'il pose souvent, et comment y répondre

| Question (exemples) | Comment répondre |
|---|---|
| « Pourquoi ça prend autant de temps ? » (messages 155, 240, 249, 253) | Expliquer les étapes d'un lot (implémentation, deux relectures indépendantes, correction, suite complète sur SQLite puis PostgreSQL, faketime, CI) et leur durée. Ne rien proposer d'alléger : il ne veut rien changer (message 253). |
| « Pourquoi un seul agent ? Pourquoi pas plusieurs lots en même temps ? » (messages 261, 266) | Les lots touchent les mêmes fichiers (`CLAUDE.md`, migrations, référentiel des autorisations) et les mêmes bases de test ; les relectures supposent un code stable. Deux lots en parallèle se détruiraient leur travail (`HISTORIQUE.md`, leçon 11). |
| « Avec ultracode, c'est ultra sécurisé, sans défaut ? » (messages 241, 244) | Plus sûr, oui ; jamais « zéro défaut ». Distinguer ce qui est vérifié de ce qui reste à valider (message 156), et rappeler l'audit de sécurité et la campagne finale avant la production. |
| « Les 4 300 tests, c'est pour l'agent ou pour tout le logiciel ? » (message 254) | Chaque agent lance la suite complète de tout le logiciel avant chaque commit ; les tests sont dans le dépôt, donc partagés par tous les lots suivants. |
| « Si la session s'interrompt, ça reprend ? » (message 250) | Expliquer : ce qui est commité et poussé est gardé ; un agent coupé se relance par un script de reprise ; un message envoyé pendant un tour coupe les courses en fond (`README.md`, § 5). |
| « Tu fais quoi avec les échecs ? » (messages 159, 175) | Pour chaque échec : cause, correction, état (D21). |
| « Dis-moi ce que tu as corrigé par rapport à ce que je t'ai donné » (message 226) | Un tableau : sa demande, ce qui a été fait, le lot et le commit, ce qui reste. |
| « T2 a implémenté quoi ? Dis-moi tout » (messages 272, 274) | Tout dire, simplement, avec des exemples ; proposer une page privée. |
| « Pourquoi tu as changé ce fichier ? Pourquoi `true` sans condition ? » (messages 268 à 270) | Il lit les diffs. Expliquer ligne par ligne, honnêtement, ce qui a été supprimé, ajouté et pourquoi ; si c'est une erreur, le dire et corriger. |
| « Pourquoi le 25 octobre et pas d'autres dates ? » (message 273) | Être précis sur les dates et les heures ; dire d'où vient chaque date. |
| « Comment tu le sais ? » (message 136) | Citer la source (texte officiel, section de la spécification). |
| Questions d'installation sur son PC (messages 21 à 32, 180 à 191, 214 à 217, 220) | Il travaille sous Windows avec XAMPP, PowerShell et VS Code ; ses pages d'erreur montrent PHP 8.5.11. Donner des commandes PowerShell une par une, dire ce que chacune fait, ne jamais lui faire lancer `composer update` (`notes/a-faire-apres-chaine.md`, points 1, 3 et 4). |
| Questions GitHub (messages 44 à 54, 219, 236, 237) | Il débute avec GitHub (PR, dépôt privé, authentification). Expliquer simplement ; un geste refusé par le classificateur (supprimer une branche) se fait par lui sur GitHub. |
| « Je peux utiliser un autre compte Claude ? » (messages 222, 262 à 265) | Oui, avec la branche de reprise et une seule session à la fois (`README.md`, § 1). |

### 3.6 Les interruptions

- Quand il envoie un message pendant un tour, les courses Workflow en fond s'arrêtent (`README.md`, § 5). Il en a été prévenu. Garder les tours courts pendant une chaîne ; avant un long travail, le prévenir ; après chaque interruption, vérifier les agents.
- Il surveille les agents lui-même : 09/10/2026, message 149 : « il y a qq agents qui sont arrete et pas fini leur travaile ». Vérifier chaque agent après une interruption et le relancer s'il est coupé.

---

## 4. Questions encore ouvertes, qu'il n'a pas tranchées

1. **Commande `composer run dev` propre à PILIQI** (sans Node, qui lance serve, queue:listen et schedule:work) : proposée, attend son accord (`notes/a-faire-apres-chaine.md`, point 2 ; `README.md`, § 9). Origine : message 180.
2. **Créer les produits automatiquement depuis une facture d'achat en PDF ou Excel** (série 8, point 9) : 17/10/2026, message 210 : « Por per (9) te jap rregullat pastaj kur te me kerkosh se si! » (« Mais pour le 9, je te donne les règles plus tard, quand tu me demanderas comment »). Questions prêtes au § 4.2 de `notes/serie8/plan-serie8.md`. À lui poser au moment voulu.
3. **Achats auprès de particuliers** (registre des objets achetés, TVA sur marge) : 08/10/2026, message 112 : « on laisse tomber pour l'instant les achat aux particulier on va revenir. » Prévu à l'étape 14 de `CLAUDE.md` ; ses règles restent à trancher.
4. **Charges récurrentes** (loyer, abonnements) : « Pas pour l'instant », à revoir après l'étape 2 (`notes/tva/plan-execution-decisions.md`, point 4).
5. **« Transformer en facture »** : les choix d'application proposés par Claude (aucun mouvement de stock, compensation non déclarée comme encaissement, même journée de caisse…) sont « à signaler à l'éditeur, modifiables » (`notes/decisions/reponses-complements-14-20.md`, point 14).
6. **Choix de Claude que l'éditeur peut contester** : assurance professionnelle facultative (`notes/serie8/reponses-types-activite.md`, règle 4) ; Q-EC4 et les limites des cabinets, 50 invitations par jour et mot de passe redemandé (`notes/serie8/reponses-cabinets.md`, règle 7) ; points 6.1 e1, e2, e3 et g du plan TVA appliqués à titre provisoire (`notes/tva/plan-execution-decisions.md`) ; les choix C2 à C23 de `notes/serie8/avenant-S8V-A4c.md` (§ 0.4) ; les « choix de Claude, sans question, que l'éditeur peut contester » de la section 8 de `notes/serie8/avenant-cabinets.md` ; les « Points à signaler dans le compte rendu » de `notes/serie8/notes-lot-V2.md`, s'ils ne lui ont pas déjà été dits. À présenter au plus tard dans le compte rendu après le lot 21 (`README.md`, § 6).
7. **Attestation de l'éditeur ou certificat d'un organisme accrédité** (art. 286, I, 3° bis du CGI, depuis la loi de finances pour 2025) : à faire vérifier en priorité ; l'étape 3 en dépend (`notes/serie8/decision-types-activite.md`, « Limites signalées » ; `README.md`, § 9).
8. **Règles marquées « à faire confirmer par l'expert-comptable de la société »** dans `CLAUDE.md` : déclaration des paiements de particuliers pour des services (décision E6), avoir de régularisation après correction d'un SIREN, durée d'un exercice modifié, points EC1 à EC20 du plan TVA. Elles restent en attente ; l'éditeur n'a pas de comptable (D25).
9. **Double authentification** des propriétaires et administrateurs : demandera un paquet, donc son accord (étape 15 ; `notes/societe/reponses-serie4.md`, V1.12).
10. **Fournisseurs étrangers** : réception par le réseau Peppol et e-reporting « in » à vérifier dans le bac à sable de SUPER PDP (`notes/decisions/reponses-complements-14-20.md`, point 12). Origine : message 168.
11. **Gestes qu'il doit faire lui-même** (`README.md`, § 9) : clé de l'API Sirene, accès à l'INPI, contrat La Poste avec remise en personne, compte SUPER PDP de production ; renouveler les deux secrets du bac à sable ; analyse d'impact (AIPD) de la vérification par courrier ; accès à l'annuaire de l'Ordre des experts-comptables ; audit de sécurité avant la production.
12. **Écriture dans `CLAUDE.md` des décisions de la conversation de la § 2.2** : aucune n'est écrite ; à lui proposer, puisque seul lui change les règles de `CLAUDE.md` (le lot 21 en prépare le texte dans « laisses »).
13. **Les deux anciennes règles de rédaction des messages 19 et 20** (D29) : tranchées le 26/10/2026 (`notes/decisions/reponses-regles-ecran-26-10.md`) ; plus une question ouverte.
14. **Limites signalées par `notes/serie8/decision-types-activite.md` (point 15)**, obligations légales que PILIQI ne couvre pas encore, notamment : date de la prestation ou de la livraison quand elle diffère de la date d'émission (art. 242 nonies A de l'annexe II au CGI) ; factures d'acompte ; facture obligatoire pour certaines ventes à un particulier (travaux immobiliers, référence légale à revérifier) ; autoliquidation de la sous-traitance du bâtiment (art. 283, 2 nonies du CGI) ; vente à distance à des particuliers d'autres pays de l'Union au-delà de 10 000 € ; attestation de l'éditeur ou certificat (art. 286 du CGI, point 7 ci-dessus). La liste complète est au point 15. Les lui exposer avec des options et une recommandation (D8), au plus tard dans le compte rendu après le lot 21.
15. **Phrase de l'étape 15 de `CLAUDE.md` du lot L1** (fuseau UTC de la base fixé par les migrations) : approuvée par l'éditeur le 26/10/2026 ; la mention « PHRASE DE CLAUDE.md APPROUVÉE PAR L'ÉDITEUR » est au point 4, suite, de `notes/a-faire-apres-chaine.md` ; plus une question ouverte.
