# Vérification de la fiche de reprise

Date : 01/11/2026, 18 h 30 UTC (`date -u` du conteneur). Lot : A7 (étape `A7:fiche` de la course `wf_b5c2413b-8c6`).

Vérifié contre : `git -C /home/user/piliqiediteur log` et `origin/claude/saas-invoice-editor-architecture-q6eizh`, en lecture seule (tête poussée `53ed088`, égale à `HEAD`, `origin/…..HEAD` vide, arbre propre) ; les commits du lot (`0772af0`, `137334e` « (lot A7) », `53ed088` « Relecture du lot A7 de l'architecture société », commité à 18 h 27 UTC) ; le `journal.jsonl` de la course (relectures : 12 et 14 constats, un bloquant ; `A7:correction` → `53ed088`, 26 constats traités, 7 paires de doublons, aucun écarté ; `A7:poussee` → `pousse: true`, `d232b4f..53ed088` ; `A7:fiche` commencée) ; `notes/reprises/args-course-wf_b5c2413b-8c6.json` (`ouverts` = A7, ML, EC2, S9-L, S9-A, 21 ; `reprise` de A7 `0772af0..137334e` sans `defauts`) ; la liste `LOTS` de `serie8-lots.js` (identique à `notes/serie8-lots.js` ; ordre … L1, A7, A7b, ML, EC2, S9-L, S9-A, 21 ; `LOTS_SENSIBLES = ['A7', 'A7b', '21']`) ; `notes/ec2/cahier-des-charges.md` (commence encore par « BROUILLON ») ; `notes/a7b/decision-a7b.md`, `notes/courriels/constat-queue-listen.md` (présents) ; la CI GitHub (courses 196/197 de `d232b4f` vertes ; 194/195 de `5745e7a` en échec ; 198, push, et 199, PR, de `53ed088` en cours à 18 h 29 UTC).

## 1. Corrigé dans README.md

1. Titre de l'encadré : « TA, EC et L1 corrigés et poussés, A7 suit » → « TA, EC, L1 et A7 corrigés et poussés, ML suit ».
2. Encadré, puce L1 : « CI de `5745e7a` en cours » était périmé : elle a échoué (194, 195) et `d232b4f` l'a corrigée (CI verte, 196, 197). Retiré « une reprise de A7 aura pour premier hash `5745e7a` », faux (la tête poussée était devenue `d232b4f`).
3. Encadré, puce A7 : « A7 est implémenté, non poussé » n'est plus vrai ; les trois points laissés à l'éditeur sont dits tranchés (lot A7b). Nouvelle puce « A7 est fini » (`53ed088`, 26 constats, poussée `d232b4f..53ed088` vers 18 h 29 UTC, CI 198/199 en cours ; la course passe à `A7:fiche`, `A7:notes`, puis ML ; elle ne connaît pas A7b et s'arrêtera à EC2 ; premier hash d'une reprise suivante : `53ed088`).
4. § 2 : « Max pour A7 et 21 » → « Max pour A7, A7b et 21 » (`LOTS_SENSIBLES` du script).
5. § 5, « Lots restants » : la boucle `for l in A7 EC2 S9-L S9-A 21` oubliait A7b et ML, présents dans `LOTS` ; devenue `for l in A7b ML EC2 S9-L S9-A 21`.
6. § 6, « Fait » : L1 corrigé (CI rouge puis `d232b4f`) ; A7 ajouté.
7. § 6, « Reste dans la chaîne » : paragraphe d'état réécrit (tête `53ed088`) ; A7 retiré, liste renumérotée de 1 (A7b) à 6 (21).

## 2. Faux, ambigu ou manquant, non corrigé (propositions ; la session décide)

### 2.1 CI de `53ed088` non conclue
Les courses 198 et 199 tournaient à 18 h 29 UTC. Après conclusion, remplacer « CI de `53ed088` en cours à 18 h 29 UTC » (encadré et § 6, « Fait ») par « CI de `53ed088` verte (courses 198 et 199) », ou, si rouge, décrire le correctif comme pour `d232b4f`.

### 2.2 `ETAT.md` périmé jusqu'à `A7:notes`
`ETAT.md` (18 h 26 UTC) dit encore tête poussée `d232b4f`, deux commits A7 non poussés et 46 fichiers modifiés : état d'avant le commit `53ed088`. L'étape `A7:notes` le régénère ; aucune action si elle aboutit. Sinon : `bash <scratchpad>/pilotage-sync.sh "Après le lot A7"` (§ 5, « Coupure après la poussée »).

### 2.3 Ordre réel A7b / ML différent de l'ordre de `LOTS`
Le § 6 dit « dans cet ordre (celui de `LOTS`) » et met A7b avant ML, mais la course en cours fera ML avant A7b (elle ne connaît pas A7b). Texte proposé, en tête de la liste du § 6 : « Ordre réel dans le conteneur d'origine : ML (course `wf_b5c2413b-8c6`), arrêt à EC2 (cahier en brouillon), puis A7b, EC2, S9-L, S9-A, 21 dans une nouvelle course. Une nouvelle session, elle, suit l'ordre de `LOTS` pour ce qui n'est pas sur `origin`. » Vérifier aussi que le complément de ML ne suppose rien du lot A7b (le constat 403 du cabinet, `a7b/decision-a7b.md` § 2.5, reste à A7b).

### 2.4 EC2 : cahier toujours en brouillon, 13 questions à l'éditeur
`ec2/cahier-des-charges.md` commence par « BROUILLON » ; `ec2/cahier-des-charges.proposition.md` existe (« proposition … en relecture ») et la branche de reprise note « 13 questions posées à l'éditeur » (commit `9cee076`), absentes de la fiche. Texte proposé pour le point EC2 du § 6 : « La proposition (`ec2/cahier-des-charges.proposition.md`) attend les réponses de l'éditeur à 13 questions ; une fois tranchées et écrites dans `ec2/`, elle remplace `cahier-des-charges.md`, sans la mention BROUILLON, avant de relancer la chaîne. » Ajouter ces 13 questions à « Questions encore ouvertes ».

### 2.5 « Laisses » de A7 qui ne sont rattachées à aucun lot
La correction laisse, hors de ses constats : (a) `codes_confirmation.conserver_jusqu_au` encore posable par une requête brute avant effacement (comportement voulu par V2, documenté dans `VerificationSocieteTest`) ; (b) la boucle de la radiation de l'Ordre ni reprenable ni protégée contre un échec autre que la messagerie ; (c) des choix de Claude contestables (preuve d'un code de procédure valable 30 jours, SIRET d'un magasin cédé libérable, justification et opérateurs cachés à toute société, refus d'une correction vers un SIREN où une société révoquée a numéroté le même code de magasin). Texte proposé au § 6, « Après la poussée du lot 21 », point 2 bis : « Suivis laissés par A7 (journal de `wf_b5c2413b-8c6`, `A7:correction`, champ `laisses`) : (a) et (b) ci-dessus, à vérifier dans le rapport du lot 21 ; (c) à présenter à l'éditeur dans le compte rendu de A7. »

### 2.6 Compte rendu de A7 à l'éditeur
La fiche ne dit pas qu'il reste à faire (§ 5, « Suivi ») : CI, puis compte rendu simple avec exemple, 26 constats dont le bloquant (un numéro de document déjà émis sous un SIREN corrigé aurait pu être réémis par le vrai titulaire), et les choix contestables (2.5 c). L'ajouter à l'encadré une fois fait, comme pour EC.

### 2.7 Message de l'éditeur sur `queue:listen` relayé de nouveau
La requête qui a déclenché cette course est encore la sortie de `php artisan queue:listen` coupée à 60 s sur le PC de l'éditeur. Elle est couverte par `courriels/constat-queue-listen.md` et le lot ML (délai SMTP `MAIL_TIMEOUT`, commande d'e-mail d'essai, README), que la course fait juste après A7 ; A7 n'a traité que la robustesse de la révocation quand l'envoi échoue. Proposition : dans le compte rendu, rappeler à l'éditeur le diagnostic de ce fichier (`Test-NetConnection sandbox.smtp.mailtrap.io -Port 2525` puis 587, `php artisan queue:work --once`, dernière erreur de `storage\logs\laravel.log`) en attendant ML.

### 2.8 Exemple du § 4, point 1, figé sur TA
La commande `python3 … a['reprise']['TA'] … args-course-wf_22d43798-897.json` ne vaut plus que comme modèle (TA est poussé). Texte proposé : remplacer `'TA'` par `sys.argv[3]` et le fichier par « le fichier `args-course-<course>.json` nommé dans l'encadré », avec le lot en troisième argument.

### 2.9 Encadré de plus en plus long
L'encadré garde l'histoire de TA, EC et L1, déjà résumée au § 6. Proposition : n'y laisser que la course en cours, la tête poussée et l'étape atteinte, et renvoyer le reste à `notes/etat-chaines.md`.
