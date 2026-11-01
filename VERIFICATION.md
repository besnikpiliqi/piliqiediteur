# Vérification de la fiche de reprise

Date : 01/11/2026, 08 h 59 UTC selon l'horloge du conteneur (voir 2.6). Lot : EC (étape `EC:fiche` de la course `wf_d67322b9-242`).

Vérifié contre : `git -C /home/user/piliqiediteur log` et `origin/claude/saas-invoice-editor-architecture-q6eizh`, en lecture seule (tête poussée `01fe217`, aucun commit local non poussé, arbre propre, branche de travail extraite) ; la boucle du § 5 (EC : 4 commits « (lot EC k/4) » et « Relecture du lot EC » sur `origin` ; L1, A7, S9-L, S9-A, 21 : aucun) ; le `journal.jsonl` de la course (`EC:implementation` → `976e827..93ea590` ; relectures relancées : 7 et 8 défauts ; `EC:correction` → `01fe217` ; `EC:poussee` → `pousse: true` ; `EC:fiche` commencée) ; `notes/reprises/args-course-wf_d67322b9-242.json` (`ouverts` = EC, L1, A7, S9-L, S9-A, 21) ; la liste `LOTS` de `serie8-lots.js` (identique à `notes/serie8-lots.js` ; ordre … TA, EC, L1, A7, S9-L, S9-A, 21, conforme au § 6), `LOTS_SENSIBLES = ['A7', '21']`, `effortDuLot`, `COMMITS_DU_LOT` ; les sauvegardes `serie8-lots.avant-*.js` citées (présentes) ; `<scratchpad>/../relectures-coupees/EC-0707/` (présent) ; la CI GitHub (courses 190 et 191 de `9ce20c3` : succès ; 192, push, et 193, PR, de `01fe217` : en cours).

## 1. Corrigé dans README.md

1. Titre de l'encadré : « TA corrigé et poussé, chaîne relancée sur EC » → « TA et EC corrigés et poussés, L1 suit ».
2. Encadré, 2e puce : « CI de `9ce20c3` en cours à 02 h 46 UTC » → « CI de `9ce20c3` verte (courses GitHub 190, push, et 191, PR) ».
3. Encadré : nouvelle puce « EC est fini » (relectures 7 + 8 constats, correction `01fe217`, constat 10 écarté et laissé à l'éditeur, poussée `9ce20c3..01fe217`, course à `EC:fiche`, puis `EC:notes` et `L1:implementation`, CI de `01fe217` en cours, `ouverts` commence par L1).
4. § 5, « Lots restants » : la boucle ne liste plus EC (`for l in L1 A7 S9-L S9-A 21`).
5. § 6, « Fait » : CI de `9ce20c3` verte ; EC ajouté (4 commits, correction `01fe217`, poussée par `wf_d67322b9-242`, premier lot sans grille faketime et avec `effortDuLot`).
6. § 6, « Reste dans la chaîne » : paragraphe d'état réécrit (tête `01fe217`, course `wf_d67322b9-242` à `EC:fiche`, puis `EC:notes` et `L1:implementation`, effort Extra, sans grille) ; EC retiré de la liste, renumérotée de 1 (L1) à 5 (21).

## 2. Faux, ambigu ou manquant, non corrigé (propositions ; la session décide)

### 2.1 § 7 : priorité de `decision-espace-cabinets.md` devenue caduque en partie

« `serie8/decision-espace-cabinets.md` (19/10) sur tout ce que `CLAUDE.md` et les plans disent des experts-comptables, que `CLAUDE.md` décrit encore jusqu'au lot EC » : EC a réécrit `CLAUDE.md` (commit `93ea590`, 43 lignes changées dans `CLAUDE.md`). Vérifié par `git show 01fe217:CLAUDE.md` : il ne parle plus d'expert-comptable de la société, mais de cabinets d'expertise comptable (21 occurrences de « cabinet »). Attention : une session ou un agent qui a chargé `CLAUDE.md` avant la poussée d'EC (c'était le cas du contexte de cette relecture) en garde l'ancienne version ; la relire depuis le dépôt. Texte proposé pour le § 7 :

> - `serie8/decision-espace-cabinets.md` (19/10) sur ce que les plans plus anciens disent des experts-comptables ; le lot EC (`93ea590`) l'a reporté dans `CLAUDE.md`, qui fait foi désormais pour ce sujet, sauf la section 3.5.14 de `avenant-cabinets.md`, reprise par A7.

La même mise à jour vaut pour la ligne « Chaîne TVA », point 2 du § 6 (« retirer « experts-comptables » de la règle des droits de son `REGLES` ») : toujours juste, rien à changer.

### 2.2 Compte rendu d'EC à l'éditeur (§ 5, « Suivi »)

- EC change des migrations (`create_societes_table`, `create_users_table`, `create_identifiants_table`, `create_affectations_table`, `create_autorisations_societe_table`, et cinq nouvelles tables `cabinets`, `invitations_cabinet`, `liens_cabinet`, `autorisations_cabinet`, `clients_confies`) : rappeler pour son PC `git pull`, `php artisan migrate:fresh`, `php artisan optimize:clear` et une nouvelle inscription.
- Lui soumettre le constat 10, écarté par la correction (« le refus neutre d'un SIREN tenu par un cabinet suit le cahier des charges … Question laissée à l'éditeur ») : à ajouter aux « Questions encore ouvertes » du § 6. Texte proposé :

> - Constat 10 du lot EC (message de `01fe217`) : le refus d'inviter un SIREN tenu par un cabinet est neutre mais se distingue d'une demande envoyée ; le rendre indistinguable exigerait une demande qui ne s'accepte jamais. Choix à confirmer par l'éditeur.

- Lui dire ce que l'agent d'implémentation a laissé (12 « laisses ») et la correction (5 « laisses »), à lire dans le `journal.jsonl` de la course ; la suppression de tests de l'expert-comptable de la société (4930 → 4916 tests, chacun remplacé par un test nommé selon le rapport) ; et que la grille faketime n'a pas été lancée (critère 7.1.5 de l'avenant non exécuté, D50).
- § 9 : « Accès à l'annuaire de l'Ordre des experts-comptables (lot EC) … l'éditeur fournit ce que le compte rendu du lot demande » : le compte rendu d'EC doit donc dire ce qui est demandé (`AnnuaireDeLOrdreTest` montre une implémentation factice).

### 2.3 CI de `01fe217` non encore conclue

Courses 192 et 193 « in_progress ». La session note leur conclusion dans `etat-chaines.md` et remplace « CI de `01fe217` en cours » par « CI verte » dans l'encadré et au § 6.

### 2.4 En-tête, ligne 3 : période de la session

« la session qui l'a écrite (2 au 26 octobre 2026) » : la même session écrit encore la fiche le 01/11/2026. Proposition : « (depuis le 2 octobre 2026) ».

### 2.5 Encadré : puces devenues historiques

Les puces sur `wf_22d43798-897` (plage `89f092f..e2d790a`, « la prochaine reprise écrit `89f092f..<dernier commit>` ») et le § 4, point 1 (commande `python3 … a['reprise']['TA']…`) ne servent plus. Les propositions 2.2 à 2.4 de la relecture précédente restent valables ; texte proposé, en tête du passage du § 4, point 1 : « Aujourd'hui aucun correctif n'est à appliquer (TA et EC sont sur `origin`) ; ce qui suit sert d'exemple pour un futur lot sauvé. » Et, dans l'encadré, une reprise de L1 aura pour premier hash `01fe217`.

### 2.6 Horloge du conteneur

`date -u` donnait 08 h 59 UTC alors que GitHub date la création des courses 192 et 193 à 09 h 59 UTC, après le commit `01fe217` (08 h 57 +0000) : une des deux horloges a une heure d'écart (la veille, la course 190 de `9ce20c3` était créée une minute après son commit). Les heures « vers 09 h UTC » écrites dans la fiche sont donc à une heure près. Proposition : que la session contrôle l'heure (`date -u` contre l'API GitHub) avant de dater les notes et les points de suivi.

### 2.7 Points des relectures précédentes toujours ouverts

Toujours non repris : visibilité de `VERIFICATION.md` seulement après `pilotage-sync.sh` ; Q-AF10, Q-AF12 et Q-AF13 à reposer avant S9-A, absentes des « Questions encore ouvertes » et du § 9 (proposition : les y ajouter, S9-A n'étant plus qu'à deux lots) ; complément de A7 qui cite aussi la section 7 de l'avenant ; T3 commité à 12 h 44, poussé vers 12 h 57 UTC. Textes proposés : versions précédentes de ce fichier dans l'historique de `claude/pilotage-reprise`.

### 2.8 `ETAT.md`

Généré à 07 h 12 UTC : il montre encore les quatre commits d'EC « pas encore poussés » et la tête `9ce20c3`. Normal : l'étape `EC:notes` le régénère. Rien à corriger à la main.

Aucune valeur secrète n'a été lue ni écrite dans cette relecture.
