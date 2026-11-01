# Vérification de la fiche de reprise

Date : 01/11/2026, 11 h 21 UTC (`date -u` du conteneur ; les courses GitHub 194 et 195 de `5745e7a` sont créées à 11 h 20 UTC, une minute après le commit de 11 h 19 : horloges d'accord cette fois). Lot : L1 (étape `L1:fiche` de la course `wf_d6032f41-e79`).

Vérifié contre : `git -C /home/user/piliqiediteur log` et `origin/claude/saas-invoice-editor-architecture-q6eizh`, en lecture seule (tête poussée `5745e7a`, égale à `HEAD`, arbre propre, branche de travail extraite) ; la boucle du § 5 (L1 : `8b15aec` « (lot L1) » et `5745e7a` « Relecture du lot L1 … » sur `origin` ; A7, EC2, S9-L, S9-A, 21 : aucun) ; le `journal.jsonl` de la course (`L1:correction` → `5745e7a`, 7 constats traités, aucun écarté ; `L1:poussee` → `pousse: true`, `01fe217..5745e7a` ; `L1:fiche` commencée) ; `notes/reprises/args-course-wf_d6032f41-e79.json` (`ouverts` = L1, A7, EC2, S9-L, S9-A, 21, `reprise` de L1 avec `8b15aec` et 7 défauts) ; `notes/reprises/L1-defauts-des-relectures.json` (présent) ; la liste `LOTS` de `serie8-lots.js` (identique à `notes/serie8-lots.js` ; ordre … TA, EC, L1, A7, EC2, S9-L, S9-A, 21), `LOTS_SENSIBLES = ['A7', '21']` ; `serie8-lots.avant-EC2.js` (présent) ; `git show 8b15aec -- CLAUDE.md` (phrase de l'étape 15 écrite) ; `notes/historique/messages-editeur.md` de la branche de reprise (jusqu'au message 325) ; la CI GitHub (courses 194, push, et 195, PR, de `5745e7a` : en cours).

## 1. Corrigé dans README.md

1. Titre de l'encadré : « TA et EC corrigés et poussés, L1 suit » → « TA, EC et L1 corrigés et poussés, A7 suit ».
2. Encadré : nouvelle puce « L1 est fini » (coupure de `wf_d67322b9-242` à 10 h 52, reprise par `wf_d6032f41-e79` à `L1:correction`, commit `5745e7a`, poussée `01fe217..5745e7a` vers 11 h 20 UTC, CI 194/195 en cours, puis `L1:notes` et `A7:implementation` ; `ouverts` commence par A7 ; premier hash d'une reprise de A7 : `5745e7a`).
3. § 5, « Lots restants » : la boucle était `for l in L1 A7 S9-L S9-A 21` ; elle oubliait EC2, qui est dans `LOTS` entre A7 et S9-L. Devenue `for l in A7 EC2 S9-L S9-A 21`.
4. § 6, « Fait » : L1 ajouté (`8b15aec`, correction `5745e7a`, poussés par `wf_d6032f41-e79`, phrase de l'étape 15 écrite dans `CLAUDE.md`, CI en cours).
5. § 6, « Reste dans la chaîne » : paragraphe d'état réécrit (tête `5745e7a`, course à `L1:fiche`, puis `L1:notes` et `A7:implementation`) ; L1 retiré de la liste, renumérotée de 1 (A7) à 5 (21).
6. § 6, S9-A : la réponse du msg 322 est « 1a 2a 3a 4a » (le 1 étant le constat 10 d'EC), pas « 2a 3a 4a ».
7. § 8, « Série 9 » : « Q-AF10, Q-AF12 et Q-AF13 à reposer avant S9-A » était faux (tranchées par le msg 322) ; corrigé.
8. § 8, « Production » : ajouté que le fuseau et la phrase de `CLAUDE.md` sont faits par L1.

## 2. Faux, ambigu ou manquant, non corrigé (propositions ; la session décide)

### 2.1 CI de `5745e7a` non conclue

Courses 194 et 195 « in_progress » à 11 h 21 UTC. Remplacer « CI de `5745e7a` en cours à 11 h 21 UTC » par « CI de `5745e7a` verte (courses GitHub 194, push, et 195, PR) » dans l'encadré et au § 6 quand elles finissent, et le noter dans `etat-chaines.md`.

### 2.2 Messages 323 à 325 de l'éditeur et nouvelles décisions, absents de la fiche

- Msg 323 (11 h 04) : question sur l'avertissement « ne permet pas de facturer une activité exonérée de TVA » (micro-entreprises en franchise). Une réponse de la session est attendue ; si elle conduit à changer le texte de l'avertissement du type d'activité, c'est un lot ou un ajout de cahier des charges.
- Msg 324 (11 h 14) et 325 (11 h 20) : TVA sur la marge (case sur le produit, mention sur le PDF et la facture électronique) et réponses « a) d'accord pour cette phrase, et le refus reste », « 1 : b », « 2 : a ». Fichiers créés à 11 h 21 : `tva/decision-tva-sur-marge.md` (déjà dans `pilotage-fichiers.txt`) et la nouvelle version de `ec2/decision-expert-comptable-societe.md` (copie d'avant : `decision-expert-comptable-societe.avant-msg325.md`).
- Proposition : au § 7, ajouter à la liste des priorités « `tva/decision-tva-sur-marge.md` (01/11) sur l'étape 14 de `CLAUDE.md` et sur le plan TVA pour le régime de la marge » et « `ec2/decision-expert-comptable-societe.md` (01/11) sur `CLAUDE.md` et `serie8/decision-espace-cabinets.md` pour le compte d'expert-comptable de la société, jusqu'au lot EC2 » ; au tableau, lignes « Expert-comptable de la société (EC2) : `ec2/decision-expert-comptable-societe.md`, `ec2/cahier-des-charges.md` » et ajouter `tva/decision-tva-sur-marge.md` à « Moteur de TVA » ; au § 8, une puce « EC2 » et une puce « TVA sur la marge » ; au § 6, « Ensuite », point 2 de la chaîne TVA, ajouter « TVA sur la marge (`tva/decision-tva-sur-marge.md`) ».
- Le § 8 « Cabinets » et le § 7 disent encore que `CLAUDE.md` fait foi pour les experts-comptables depuis EC ; la décision du 01/11 (msg 321) rétablit un compte d'expert-comptable de la société, contraire à la phrase actuelle de `CLAUDE.md` (« une société n'a jamais de compte d'expert-comptable »). Proposition pour le § 7, après la puce `decision-espace-cabinets.md` : « sauf le compte d'expert-comptable de la société, rétabli par `ec2/decision-expert-comptable-societe.md` (01/11), qui prime sur `CLAUDE.md` jusqu'au lot EC2 ».

### 2.3 Cahier des charges d'EC2 encore « BROUILLON »

`ec2/cahier-des-charges.md` (159 octets) ne contient que la mention « BROUILLON … ». La fiche dit que la session l'écrit pendant A7 et que la chaîne s'arrête sinon : c'est juste, mais aucune ligne ne dit à une nouvelle session reprenant après une passation quoi faire si le brouillon n'est pas fini. Texte proposé au § 6, point 2 (EC2) : « Si la chaîne s'est arrêtée sur EC2 faute de cahier des charges, l'écrire depuis la décision et `ec2/code-01fe217/` (copie figée du code, conteneur d'origine seulement ; sur un conteneur neuf, `git archive 01fe217`), le faire relire à froid par des agents neufs, retirer la mention, puis relancer avec `ouverts` commençant par EC2. »

### 2.4 Encadré et § 4, point 1 : passages devenus historiques

Les puces sur `wf_22d43798-897` et la commande `python3 … a['reprise']['TA']…` du § 4, point 1 ne servent plus (TA, EC, L1 sont sur `origin`). Texte proposé, en tête du passage du § 4, point 1 : « Aujourd'hui aucun correctif n'est à appliquer ; ce qui suit sert d'exemple pour un futur lot sauvé (remplacer `TA` par le lot et le fichier d'arguments par celui de sa course). » L'encadré devient long (huit puces) : proposer de déplacer les puces TA et EC dans `notes/historique/HISTORIQUE.md` et de ne garder que l'état courant.

### 2.5 Titre du commit de correction de L1

`5745e7a` s'appelle « Relecture du lot L1 de l'architecture société » (titre tiré de la phase du lot dans `LOTS`), alors que L1 porte sur l'outillage local. La boucle du § 5 le trouve quand même (« Relecture du lot L1 »). Rien à corriger dans le dépôt (pas de réécriture d'historique) ; signaler seulement, si la session le juge utile, que la phase de A7 et EC2 (« A7 et lot 21 ») donnera le même genre de titre.

### 2.6 Lot de suivi du § 6 « Après la poussée du lot 21 », point 2

Toujours ouvert ; L1 ne l'a pas touché (vérifié par `git show --stat 8b15aec 5745e7a` : aucun fichier de `SaisirCodeDeConfirmationAction`). Rien à changer.

### 2.7 Points des relectures précédentes toujours ouverts

Visibilité de `VERIFICATION.md` seulement après `pilotage-sync.sh` ; complément de A7 qui cite aussi la section 7 de l'avenant ; constat 10 d'EC désormais tranché (msg 322, « 1a » : lot EC2), donc plus à ajouter aux questions ouvertes. Le compte rendu de L1 à l'éditeur (§ 5, « Suivi ») doit lui rappeler, puisque L1 ajoute une migration (`0000_00_00_000000_fixer_le_fuseau_par_defaut_de_la_base`), `git pull`, `php artisan migrate:fresh`, `php artisan optimize:clear` et une nouvelle inscription, ainsi que les nouvelles consignes du README (OPENSSL_CONF par `setx`, terminal et XAMPP à rouvrir).

### 2.8 `ETAT.md`

Généré à 10 h 58 UTC : il montre encore la tête `01fe217`, `8b15aec` « pas encore poussé » et du travail non commité. Normal : l'étape `L1:notes` le régénère. Rien à corriger à la main.

Aucune valeur secrète n'a été lue ni écrite dans cette relecture.
