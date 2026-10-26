# Fiche de reprise du projet PILIQI

Cette branche (`claude/pilotage-reprise`) ne contient que les notes de pilotage du projet. Elle ne touche jamais le code et ne se fusionne jamais. Elle permet à une autre session Claude, sur ce compte ou sur un autre, de continuer le travail exactement comme la session qui l'a écrite (2 au 26 octobre 2026).

- `README.md` : cette fiche, écrite à la main. Elle dit par où commencer, comment travailler et où tout se trouve.
- `ETAT.md` : l'état du dépôt et de la chaîne, régénéré automatiquement à chaque mise à jour.
- `VERIFICATION.md` : la dernière relecture à froid de cette fiche (après chaque lot, à partir de PU).
- `notes/` : les fichiers de décision de l'éditeur, les cahiers des charges, les plans, les scripts de la chaîne, l'installation (`notes/installation/`), l'historique complet (`notes/historique/`) et le script de mise à jour de cette branche.

Le code est sur la branche de travail `claude/saas-invoice-editor-architecture-q6eizh` (PR #1 de besnikpiliqi/piliqiediteur, dépôt privé). `CLAUDE.md` de cette branche fait foi pour les règles du projet, sauf sur les sujets où une décision plus récente de l'éditeur l'emporte en attendant le lot qui la reporte dans `CLAUDE.md` (liste au § 7).

## 0. Par où commencer

### Pour l'éditeur, sur l'autre compte

1. Arrêter l'ancienne session, de préférence juste après la poussée d'un lot (§ 1).
2. Créer l'environnement cloud de l'autre compte : relier le compte GitHub qui a accès au dépôt privé besnikpiliqi/piliqiediteur ; accès réseau qui permet apt, Packagist et GitHub ; dans le champ « Setup script » (menu de l'environnement dans la barre de titre de la session, puis Modifier), le script d'installation décrit dans `notes/installation/ENVIRONNEMENT.md` (§ 4). Aucun secret n'est nécessaire pour continuer : les tests utilisent des implémentations factices. Les identifiants SUPER PDP ne se mettront qu'à l'étape 2, par l'éditeur lui-même, dans les variables de l'environnement, jamais dans la conversation.
3. Ouvrir une session sur le dépôt et lui écrire ce message :

> Tu reprends le développement de PILIQI. Lis d'abord la branche claude/pilotage-reprise du dépôt besnikpiliqi/piliqiediteur, en commençant par README.md, puis fais exactement ce que dit son § 0 « Pour la nouvelle session ». Travaille en français. ultracode

Le mot « ultracode » autorise la session à lancer les chaînes d'agents (outil Workflow), dont toute la méthode dépend.

### Pour la nouvelle session

1. Ne rien commencer tant que l'éditeur n'a pas confirmé que l'ancienne session est arrêtée (§ 1).
2. Récupérer cette branche et ses notes (§ 4, points 1 à 4), puis lancer `bash notes/installation/installer-session.sh --verifier` depuis le dossier de la branche, et l'installation complète si un point manque (§ 4).
3. Lire, dans cet ordre : `CLAUDE.md` de la branche de travail (en entier) ; cette fiche ; `notes/historique/DIRECTIVES.md` (au moins son § 0, « L'essentiel en quinze lignes », puis le reste) ; `ETAT.md` et `VERIFICATION.md` ; `notes/historique/HISTORIQUE.md`. `notes/historique/messages-editeur.md` (les 278 messages de l'éditeur) et `notes/historique/resumes-de-session.md` (les 18 résumés de la session) servent à retrouver un détail.
4. Vérifier la CI de la dernière tête poussée, puis calculer les lots restants (§ 5) et relancer la chaîne (§ 5 et § 6).
5. Dire à l'éditeur, en quelques lignes simples, où en est le projet et ce qui va se passer, puis tenir cette branche à jour à chaque changement (§ 10).

## 1. Règle d'or : une seule session à la fois

Deux sessions qui travaillent en même temps sur la branche de travail se détruisent leur travail. Avant de commencer :

1. L'éditeur arrête l'ancienne session (ou confirme qu'elle est arrêtée). Ne jamais démarrer tant qu'une chaîne y tourne. Avant de s'arrêter, l'ancienne session lance une dernière fois `pilotage-sync.sh "Passation"`, pour que `ETAT.md` montre l'état exact.
2. Le meilleur moment pour changer de session est entre deux lots, juste après une poussée. Dans `ETAT.md`, regarder « Commits locaux pas encore poussés » et « Travail non commité » : tout ce qui n'est pas poussé reste dans le conteneur de l'ancienne session et n'arrive pas dans la nouvelle. Un lot qui n'est pas entièrement poussé se refait en entier depuis la tête poussée (§ 6).
3. Lire les documents dans l'ordre du § 0.

## 2. Le mandat et la façon de travailler

`notes/historique/DIRECTIVES.md` donne chaque directive de l'éditeur mot pour mot, avec sa date ; ce qui suit en est l'essentiel.

- L'utilisateur est l'éditeur de PILIQI (SaaS français de facturation et de caisse ; Laravel 13, Filament 3.3, PHP 8.3, PHPUnit 12). Il n'a ni société ni magasin : ne jamais lui dire « vous, le propriétaire » ni « vos magasins ». Il délègue toutes les décisions techniques et demande de finir le logiciel en autonomie, jusqu'à un logiciel prêt pour la production, selon le protocole de rigueur de `CLAUDE.md` et sa feuille de route. Son premier message (le prompt maître du 02/10, `notes/prompt_maitre.txt`) garde des principes qui valent toujours : jamais d'exemple simplifié, jamais de règle métier devinée, pas de complexité inutile (Laravel reconnaissable), les questions à se poser avant de dire qu'une fonctionnalité est finie, une clé de cache qui porte toujours le locataire, un job qui porte son contexte, rien d'inutile ni de secret dans les journaux.
- Tout se fait et s'écrit en français (réponses, code, commits, documents), même quand il écrit en albanais. Jamais en albanais.
- Il veut la voie la plus sûre, même si elle est plus longue : chaque lot passe par une implémentation, deux relectures adversariales indépendantes, une correction, les suites complètes SQLite puis PostgreSQL au code 0, faketime aux instants de la grille du README, pint, puis la poussée et la CI verte. Il a refusé tout allègement du protocole (« On va pas changer rien »). Ultracode est activé chez lui : le travail substantiel s'orchestre avec l'outil Workflow. Les lots se font un par un, parce qu'ils touchent les mêmes fichiers et les mêmes bases de test, et que leurs relectures doivent porter sur un arbre stable ; il a demandé deux fois pourquoi, c'est la réponse.
- Un défaut se corrige partout où il existe, pas seulement sur l'écran où il l'a vu. Une explication ne remplace jamais un test ; les calculs se testent sur des milliers de cas.
- Comptes rendus : ne jamais prétendre avoir lancé un test qui ne l'a pas été ; ne jamais dire que PILIQI est sans erreur ; toujours distinguer ce qui a été vérifié de ce qui reste à valider ; ne dire « c'est fini » qu'après la relecture (une première version se présente comme telle).
- Une règle métier non tranchée (calcul, arrondi, obligation légale, droits) se soumet à l'éditeur, expliquée en détail, avec des options et une recommandation, au lieu de choisir. Il n'a pas de comptable : chercher les règles sur les sites officiels, ne jamais le renvoyer vers un comptable. Ses réponses s'écrivent aussitôt dans un fichier de décision de `notes/` (texte d'origine cité, puis règles), la chaîne lit ce fichier, et cette branche est mise à jour dans la minute. S'il change d'avis, la dernière version du fichier fait foi.
- Communication : explications simples, concrètes, sans jargon, avec des exemples chiffrés ; donner son avis avant de modifier quand il le demande ; quand il demande pourquoi c'est long, expliquer les relectures, les suites sur deux bases et faketime. « Réessayer » ou « Réessayez » veut dire reprendre après une limite d'usage ; « c'est fait » se vérifie (par exemple par l'API GitHub). Une page privée (outil Artifact) a servi à lui montrer un lot (T2) et à recueillir ses décisions ; un autre compte ne peut pas ouvrir ces pages : en refaire une au besoin (sources dans `notes/decisions/`, `notes/gen-decisions.js`).
- Son poste local : Windows, XAMPP, PowerShell, PHP 8.5. Après un commit qui change des migrations, lui rappeler `git pull`, `php artisan migrate:fresh`, `php artisan optimize:clear` et une nouvelle inscription (le lien d'inscription est dans `storage/logs/laravel.log` avec `MAIL_MAILER=log`) ; voir aussi `a-faire-apres-chaine.md` (OPENSSL_CONF, clé du journal d'audit).
- Règles d'écran qu'il a exigées et que le code applique déjà (à garder dans tout nouvel écran) : jamais de page d'erreur technique, une alerte claire en français (trait `GereLesExceptionsMetier`) ; retour à la liste après chaque enregistrement (`RevientALaListe`) ; calcul en direct sans jamais réécrire le champ que la personne tape ; boutons radio et cases à cocher plutôt que menus déroulants quand c'est possible ; quantités affichées « 2 » et non « 2.000 » ; messages de validation sans deux-points. L'outil Fable est autorisé pour concevoir les écrans « si nécessaire » (finition, étape 15).
- Deux anciennes règles de l'éditeur (03 et 04/10) à faire confirmer avant d'y toucher, parce que l'architecture société les a changées en partie : ne pas écrire « magasin » dans un message destiné à un client, et ne jamais laisser deviner à un compte de magasin l'existence d'autres magasins ou sociétés. Tant qu'il ne les a pas revues, les appliquer à tout écran de magasin.
- Le dépôt reste privé : ne jamais proposer de le rendre public (« Ce logiciel à moi et que à moi »).
- Seul l'éditeur change les règles de `CLAUDE.md`. Les textes que prévoit un fichier de décision de `notes/` sont approuvés (réponses de l'éditeur, décision qu'il a tranchée, section 2 du plan de la série 8) : les lots les écrivent sans redemander, notamment T3, PU (règle 4), TA, EC, et L1 pour la phrase de l'étape 15 sur le fuseau UTC de la base, demandée par l'éditeur le 26/10/2026. Tout autre changement de `CLAUDE.md` se propose à l'éditeur et attend son accord (exemple : la puce « Outillage », approuvée le 25/10/2026).
- Ne jamais committer ni pousser le travail en cours d'un agent de la chaîne. Le crochet d'arrêt le demande à chaque tour : répondre que c'est le travail de l'agent, qui commitera après des suites vertes, et qu'un commit non relu n'est pas poussé avant sa relecture.
- Pas de PR sans demande. Jamais de poussée forcée ni de réécriture de l'historique de la branche de travail. Une seule autre branche a été autorisée : celle-ci.
- Lignes de fin de chaque commit : celles que donne le harnais de la session (dans l'ancienne : `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` et `Claude-Session: https://claude.ai/code/session_01K3593fBCSfG8EcbWrZryb6`). Une nouvelle session met les siennes dans `REGLES` des scripts de chaîne et dans `pilotage-sync.sh` (§ 4). Aucun identifiant de modèle dans le code, les commits ou la PR.
- Un commentaire GitHub se termine par une ligne `---` puis `_Generated by [Claude Code](https://claude.ai/code)_`.

## 3. Consignes de sécurité (à garder mot pour mot)

- Ne jamais demander à l'utilisateur de coller jetons, clés ou mots de passe dans la conversation ; ne jamais écrire d'identifiants dans les fichiers du dépôt.
- Identifiants seulement par variables d'environnement : SUPERPDP_URL, SUPERPDP_CLIENT_ID, SUPERPDP_CLIENT_SECRET, SUPERPDP_TRICATEL_CLIENT_ID, SUPERPDP_TRICATEL_CLIENT_SECRET, SUPERPDP_REDIRECT_URI ; INSEE_SIRENE_CLE, INPI_RNE_IDENTIFIANT, INPI_RNE_MOT_DE_PASSE, JOURNAL_AUDIT_CLE, JOURNAL_AUDIT_CLE_ID, EDITEUR_OPERATEURS, PILIQI_EDITEUR_EMAIL, CAISSE_SECURISEE_CLE_PRIVEE, CAISSE_SECURISEE_PHRASE_SECRETE.
- Le classificateur a refusé un essai réel avec identifiants en ligne (« Credential Leakage ») ; ne pas le contourner.
- Conseiller à l'éditeur de renouveler les deux secrets du bac à sable SUPER PDP après les tests.
- Ne jamais envoyer de vraie requête à SUPER PDP, La Poste, l'INPI, l'INSEE ni à l'annuaire de l'Ordre des experts-comptables : seulement des implémentations factices ou des environnements de test autorisés ; aucune donnée fictive en masse en production.
- `git stash drop` et `git push origin --delete` ont été refusés par le classificateur (Git Destructive) : ne pas les forcer ; demander à l'éditeur de supprimer lui-même une branche sur GitHub.
- L'éditeur a un jour collé une clé privée de développement dans la conversation : ne jamais la répéter. Les identifiants qu'il a envoyés en captures d'écran ne figurent dans aucune note.
- `notes/pilotage-sync.sh` refuse de pousser cette branche s'il trouve un en-tête de clé privée, un jeton de la forme `ghp_`, `github_pat_`, `sk-` ou `AKIA`, une adresse de messagerie personnelle (gmail, outlook, hotmail, yahoo…), ou la valeur (6 caractères au moins) d'une des variables ci-dessus définie dans l'environnement ou dans `~/.profile`, sauf une valeur publique écrite dans la CI du dépôt. Il ne voit pas un secret collé dans la conversation et jamais mis en variable : relire soi-même chaque nouveau fichier de notes avant de le synchroniser. Les notes ne contiennent aucune donnée personnelle de l'éditeur (le nom de son employeur, ses navigateurs et les identifiants de son poste ont été retirés de l'historique).

## 4. Mettre en place une nouvelle session

Tout le détail (versions exactes de l'ancien conteneur, commandes, vérifications, 15 pièges connus) est dans `notes/installation/ENVIRONNEMENT.md`. `notes/installation/installer-session.sh` refait toute l'installation et ne détruit jamais une base ou un cluster qui contient des données ; `--verifier` contrôle tout sans rien modifier (code de sortie non nul s'il manque quelque chose).

1. Le dépôt est cloné par l'environnement dans `/home/user/piliqiediteur`. Se placer sur la branche de travail :
   `git -C /home/user/piliqiediteur fetch origin && git -C /home/user/piliqiediteur checkout claude/saas-invoice-editor-architecture-q6eizh && git -C /home/user/piliqiediteur pull --ff-only`.
   Vérifier que `git rev-parse --short HEAD` donne la « Tête poussée » de `ETAT.md` et que `git status --short` est vide. Ne jamais travailler sur `main` ni sur une autre branche que l'environnement propose : s'il en impose une autre, demander à l'éditeur avant tout commit. Si le dépôt manque ou si une poussée est refusée : `mcp__claude-code-remote__add_repo` (owner `besnikpiliqi`, repo `piliqiediteur`, access `push`), puis `mcp__claude-code-remote__read_documentation` (topic `github.access`), et demander à l'éditeur de relier son compte GitHub. Ne rien contourner.
2. Récupérer cette branche à côté, sans toucher l'arbre de travail :
   `git -C /home/user/piliqiediteur fetch origin claude/pilotage-reprise && git -C /home/user/piliqiediteur worktree add <scratchpad>/pilotage-reprise claude/pilotage-reprise`,
   puis copier `notes/*` dans `<scratchpad>` en gardant les sous-dossiers.
3. Installer ou vérifier l'environnement : `bash <scratchpad>/installation/installer-session.sh --verifier`, puis, s'il manque quelque chose, `bash <scratchpad>/installation/installer-session.sh` (paquets avec `apt-get update` et `LANG=C.UTF-8`, PostgreSQL en UTF8 et C.UTF-8, rôle `piliqi` avec CREATEDB sans être superutilisateur, base `piliqi_test`, mot de passe local tiré au hasard dans `~/.profile`, faketime, exclusion des tests jetables, `composer install`, `.env` et clé si absents). Ne jamais lancer `composer update`, ni `php artisan caisse:generer-cle` dans l'arbre de travail (il y écrit une clé non suivie : l'arbre devient sale et la chaîne s'arrête). `composer install` est lent ici : le proxy refuse les archives GitHub et Composer se rabat sur des clones git (vendor de 4,6 Go).
4. Adapter les chemins et le lien de session. N est le nouveau `<scratchpad>`, U l'identifiant de la nouvelle session (nom du dossier parent de `<scratchpad>`, à vérifier par `ls ~/.claude/projects/-home-user-piliqiediteur/`) :
   ```
   N=<scratchpad> ; U=$(basename "$(dirname "$N")")
   sed -i -e "s#/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad#$N#g" \
     -e "s#/root/.claude/projects/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb#/root/.claude/projects/-home-user-piliqiediteur/$U#g" \
     -e "s#session_01K3593fBCSfG8EcbWrZryb6#<identifiant de la nouvelle session>#g" \
     "$N/serie8-lots.js" "$N/tva-lots-M0-FIN.js" "$N/pilotage-sync.sh"
   grep -n "00d7e3b2\|01K3593f" "$N"/serie8-lots.js "$N"/tva-lots-M0-FIN.js "$N"/pilotage-sync.sh   # ne doit rien rendre
   ```
   Si le harnais de la nouvelle session donne une autre ligne `Co-Authored-By`, la remplacer de la même façon. Les journaux des courses sont sous `~/.claude/projects/-home-user-piliqiediteur/<U>/subagents/workflows/<course>/journal.jsonl`, les scripts des courses sous `.../<U>/workflows/scripts/`. Avant le premier `pilotage-sync.sh` : `git -C "$N"/pilotage-reprise pull --ff-only`.
5. Au début de chaque reprise (un redémarrage du conteneur ne se voit pas toujours ; il y en a eu au moins cinq) : `service postgresql status`, et `service postgresql start` s'il est arrêté ; `df -h` avant une longue course (le disque plein a déjà fait échouer une suite le 18/10 ; supprimer les copies de travail devenues inutiles).
6. Outils : GitHub seulement par les outils `mcp__github__*` (à charger par ToolSearch), pas de `gh`. WebFetch et curl sont souvent bloqués par le proxy (impots.gouv.fr, github.com) alors que WebSearch fonctionne. La chaîne ne tourne que par l'outil Workflow : s'il n'apparaît pas, ni directement ni par ToolSearch, ne pas faire les lots à la main hors de la chaîne ; demander à l'éditeur de l'activer (ultracode) sur ce compte.
7. Ne pas refaire les restes de l'ancien conteneur : MariaDB (`CLAUDE.md` interdit tout autre moteur), cluster d'essai sur le port 55432, rôles `relecteur*`, bases `relecture_*`, `instables_pg*`, `piliqi_banc*`.

## 5. La chaîne de lots

### Fonctionnement

- `notes/serie8-lots.js` contient `REGLES` (règles communes à tous les lots, dont la vérification sur les deux moteurs et, depuis le 26/10/2026, la grille faketime avant chaque commit qui touche aux dates et avant le dernier commit du lot), la liste `LOTS` (chaque lot : `id`, `phase`, `titre`, `complement` = cahier des charges et fichiers à lire) et la mécanique. Pour chaque lot demandé dans `args.ouverts`, dans l'ordre de `LOTS` :
  1. `<lot>:implementation` (commits du lot, suites vertes) ;
  2. `<lot>:relecture1` et `<lot>:relecture2` en parallèle (optique fidélité et métier ; optique sécurité, isolation et qualité) : défauts confirmés seulement ;
  3. `<lot>:correction` s'il y a des défauts (un commit « Relecture du lot … ») ;
  4. `<lot>:poussee` (arbre propre, `git push` sans forcer) ;
  5. `<lot>:fiche` : un agent neuf relit cette fiche à froid, comme le ferait une autre session, la vérifie contre le dépôt et les notes, met à jour le § 6 et les références factuelles fausses, et écrit le reste de ce qu'il trouve dans `VERIFICATION.md` (règles et méthode : il propose, la session décide) ;
  6. `<lot>:notes` (lance `pilotage-sync.sh`, qui met cette branche à jour).
  La chaîne s'arrête si un lot n'a pas de commit ou si la poussée échoue. Les agents des lots ne lisent pas cette fiche : elle s'adresse à la session qui orchestre, et ses consignes (arrêter, relancer) ne doivent pas être prises pour les leurs.
- Lancer : outil Workflow avec `scriptPath` = `<scratchpad>/serie8-lots.js` et `args` = `{"ouverts": [ ... les lots restants, dans l'ordre ... ]}` (de vrais tableaux JSON, pas une chaîne), sans `resumeFromRunId`.
- Lots restants : un lot est fait quand ses commits « (lot X) » sont sur `origin` et, si ses relectures ont trouvé des défauts, son commit « Relecture du lot X … » aussi. `ouverts` reçoit, dans l'ordre de `LOTS`, ceux qui ne sont pas faits. Ne jamais remettre dans `ouverts` un lot déjà poussé.
- Règle apprise : relancer SANS reprise (`resumeFromRunId`). La reprise peut manquer le cache et refaire des relectures déjà faites.
- Une course déjà lancée ne voit pas les modifications du script, sauf une sous-course pas encore appelée (le script est lu au moment de l'appel). Pour insérer un lot ou changer un cahier des charges, arrêter la course entre deux lots (après une poussée) et la relancer. Avant toute modification du script, en garder une copie (`serie8-lots.avant-<raison>.js`) et vérifier la syntaxe (`node --check` sur une copie où `export const meta` devient `const meta` et le corps est enveloppé dans une fonction `async`). Dans un script de course, `${…}` s'échappe en `\${…}` quand il doit arriver tel quel à l'agent (incident `PIPESTATUS`).
- Lot interrompu, dans le même conteneur, avec du travail resté dans l'arbre : écrire un script de reprise comme ceux de `notes/reprises/` (consigne exacte de l'agent coupé sauvée dans un fichier, note de reprise, poussée, fiche, mise à jour des notes, puis la chaîne en sous-course avec les lots restants). En sauvant la consigne (premier message utilisateur de son `agent-*.jsonl`), retirer l'en-tête ajouté par le harnais (« [Workflow harness — computed task] … ») et l'indentation de deux espaces de chaque ligne. Sur un autre conteneur, ne jamais lancer un script de `reprises/` : il suppose le travail non commité et les journaux de l'ancien conteneur.

### Dangers connus : ce qui coupe les agents

- Un message de l'éditeur envoyé pendant qu'un tour est en cours interrompt ce tour et arrête aussi toutes les courses Workflow en arrière-plan. C'est arrivé le 09/10 (chaque message redémarrait alors la session), le 25/10 à 23 h 40 UTC (T2) et le 26/10 à 10 h 46 UTC (T3). Garder les tours courts quand une chaîne tourne ; avant un long travail, prévenir l'éditeur (« ne répondez pas avant que j'écrive C'est fini ») ; il sait qu'il peut écrire sans risque quand la session ne travaille pas.
- Les redémarrages du conteneur (18, 19 et 24/10) et les limites d'usage (de session, hebdomadaire) coupent aussi les courses (S8-L, A4c, V2, FA1).
- Après chaque arrêt : vérifier la date du dernier `agent-*.jsonl` de la course et sa dernière ligne (« [Request interrupted by user] ») ; si l'agent est coupé, le relancer comme ci-dessus, et redémarrer PostgreSQL s'il le faut.

### Suivi

- Points de suivi par `mcp__claude-code-remote__send_later` (toutes les 3 à 4 heures) et guets en fond (`until grep … journal.jsonl`, 2 heures au plus). Lire le résultat d'une course par `json.load(...)['result']` ou dans son `journal.jsonl`.
- Dans `ETAT.md`, « étapes commencées » ne dit pas qu'une étape est finie : une étape est finie quand le `journal.jsonl` de sa course a une ligne `"type":"result"` avec la même `key` que sa ligne `"type":"started"`.
- Durées : suite SQLite environ 4 min 30, PostgreSQL environ 11 min (4 processus), grille faketime 12 × environ 5 min. Le résumé de PHPUnit sort en une ligne JSON : seul le code de sortie fait foi (`echo $?` juste après, jamais de tube après `git push` ou une suite).
- CI : vérifier la course GitHub de chaque tête poussée (`mcp__github__actions_list`, `list_workflow_runs` sur la branche de travail). Relancer une seule fois si une machine GitHub s'arrête ; une CI rouge pour une cause réelle se corrige. Si les courses échouent en 2 secondes sans machine (`runner_id` 0, comme le 18/10), c'est le quota de minutes ou le plafond de dépense de GitHub Actions : un seul commentaire sur la PR, et l'éditeur règle la facturation. Les erreurs 500 intermittentes de GitHub se réessaient.
- Pour les ateliers de conception (cahiers des charges), travailler sur une copie figée du code : `git archive` d'un commit, plus `vendor` en liens physiques et `vendor/composer` copié (sinon un `composer dump-autoload` dans la copie réécrit le vendor du dépôt). Tuer un processus par son PID, jamais `pkill -f` (il a tué le shell). Un appel d'outil refusé peut quand même avoir écrit des fichiers : vérifier.

## 6. Où en est le projet

L'état précis (commits, travail en cours, dernière course) est dans `ETAT.md`. Historique détaillé : `notes/historique/HISTORIQUE.md` et `notes/etat-chaines.md`.

### Fait (étape 1 en cours)

- Lots de décisions 1 à 20 ; architecture société A0 à A6 (+ A4b, A5c) ; série 8 : R6, S8-T, S8-A, S8-P, S8-R, S8-C, S8-E, S8-L, S8-F, S8-O, S8-V, A4c ; T1 (tests déterministes) ; V2 (vérification par courrier) et V2b ; FA1, FA2, FA3 (brouillons et fiches face aux validations simultanées) ; T2 (horodatages en UTC, classe Calendrier).
- M0 et M1 poussés par une autre session le 20/10 ont été annulés (5cbe4e2) : la chaîne TVA les refera selon son plan.

### Reste dans la chaîne, dans cet ordre (celui de `LOTS`)

1. **T3** : heure locale de chaque magasin (`t2/reponses-t2.md` Q1, `t3/reponses-t3.md`, `t3/decision-heure-locale.md`). Au 26/10/2026, ses trois commits sont faits et relus, et la correction de relecture tourne dans l'ancien conteneur (course `wf_fb31205c-c06`, script `reprises/t3-correction-reprise-puis-chaine.js`), qui enchaîne ensuite seule PU, TA, EC, L1, A7 et 21 dans une sous-course : l'arrêter avant toute passation (§ 1). Si `origin` n'a pas les commits « (lot T3) » et « Relecture du lot T3 … », une nouvelle session refait T3 en entier : `{"ouverts": ["T3", "PU", "TA", "EC", "L1", "A7", "21"]}`. Les 6 défauts trouvés par la relecture de la première version sont décrits dans `t3/consigne-correction-t3.md`, pour suivre la nouvelle relecture (ne pas les donner tels quels à un agent).
2. **PU** : prix unitaires affichés au centime, calcul inchangé, note de calcul des PDF gardée et complétée (`serie8/reponses-affichage-pu.md`).
3. **TA** : types d'activité choisis à l'inscription (`serie8/reponses-types-activite.md`, puis `serie8/decision-types-activite.md`).
4. **EC** : espace des cabinets d'expertise comptable : `serie8/reponses-cabinets.md`, `serie8/reponses-types-activite.md`, `serie8/decision-types-activite.md`, règles 1 à 9 de `serie8/decision-espace-cabinets.md` (sa règle 10 est remplacée par TA), appliquées selon `serie8/avenant-cabinets.md` (dont la section 3.5.14 est reprise par A7).
5. **L1** : outillage local (OpenSSL, diagnostic, README) et fuseau UTC de la base fixé par les migrations (points 1, 3 et 4, avec sa suite, de `a-faire-apres-chaine.md`, énumérés dans le complément de L1 depuis le 26/10/2026 ; le point 2 attend l'accord de l'éditeur).
6. **A7** : procédures de l'éditeur, journal d'audit consultable, conservation (section 14 de `societe/conception-v2-finale.md`, section 3.5.14 de `serie8/avenant-cabinets.md`).
7. **21** : lot de décisions 21 étendu, vérification transverse de toutes les décisions (matrice décision → test).

### Après la poussée du lot 21

1. Vérifier la CI de la tête poussée et lancer `pilotage-sync.sh`.
2. Rendre compte à l'éditeur : ce que chaque lot a laissé (« laisses »), la matrice décision → test du lot 21, les choix contestables des fichiers de décision.
3. Lui redemander le point 2 de `a-faire-apres-chaine.md`, et lui reposer les questions ouvertes (ci-dessous).
4. Préparer la chaîne TVA. Ne pas cocher l'étape 1 : elle se coche au lot FIN de la chaîne TVA.

### Ensuite

- **Chaîne TVA** (`notes/tva-lots-M0-FIN.js`, écrite le 17/10). Avant de la lancer, en garder une copie, puis la mettre d'accord avec `serie8-lots.js` :
  1. Mécanique : filtre `args.ouverts` ; arrêt (`break`) si la poussée échoue ; étapes `<lot>:fiche` et `<lot>:notes` ; contrôle de la CI au début de chaque lot ; règle faketime de `REGLES`.
  2. Contenu : la section 3.4.2 de `serie8/plan-serie8.md`, l'avenant `serie8/avenant-S8V-A4c.md`, les réponses Q12 (b) et P8 (a), puis les décisions postérieures au 17/10 : courrier recommandé (V2, V2b) ; cabinets à la place des experts-comptables de la société (EC), donc retirer « experts-comptables » de la règle des droits de son `REGLES` ; types d'activité (TA), ni achats ni stock pour les services ; calendrier de chaque magasin (T2, T3) ; prix unitaires affichés au centime (PU) ; verrous des brouillons (FA).
  3. Retirer le complément `reprise` du lot FIN, déjà fait par L1.
  4. Refaire M0 et M1 selon `tva/plan-execution.md`, puisqu'ils ont été annulés par 5cbe4e2.
  5. Faire relire la chaîne corrigée sur une copie figée du code, comme les autres ateliers de conception, avant de la lancer.
- CH3 et CH6 sont tranchés dans `tva/plan-execution-decisions.md` (6.1 f : pas de charges récurrentes, donc pas de lot CH3 ; 6.1 b : avoir lié, donc le lot CH6 existe) ; `tva-lots-M0-FIN.js` les applique déjà.
- Les compléments 1 à 16 des décisions 14 à 20 (`decisions/reponses-complements-14-20.md`) ne forment pas une chaîne à part : `tva/plan-execution.md` (§ 1.5) les répartit dans la chaîne TVA (C-a pour 2, 4, 6 et 16 ; C-b pour 13 à 15 ; textes de M0 ; M2, M3, M5a à M7). Les compléments 1 et 3 sont déjà faits par S8-R et S8-O (§ 3.4.2 du plan de la série 8).
- Puis l'étape 1 bis (campagne de tests initiale) : sa stratégie, ses 19 décisions, ses outils et ses critères sont dans `notes/campagne/strategie/finale.md` ; les réponses T1 à T6 de l'éditeur sur la campagne sont dans `societe/reponses-serie4.md`. Puis les étapes 2 à 15 de la feuille de route de `CLAUDE.md`.
- Ordres de grandeur donnés à l'éditeur : la chaîne jusqu'à 21 en 4 à 6 jours ; l'étape 1 complète en environ un mois ; les étapes 2 à 15 en plusieurs mois.

### Questions encore ouvertes (détail dans `notes/historique/DIRECTIVES.md`, § 4)

- Point 9 de la série 8 : créer les produits depuis une facture d'achat en PDF ou Excel ; les questions sont prêtes au § 4.2 de `serie8/plan-serie8.md` et attendent les règles de l'éditeur.
- Charges récurrentes, à revoir après l'étape 2 (`tva/plan-execution-decisions.md`) ; choix de « Transformer en facture » à lui signaler (point 14 de `decisions/reponses-complements-14-20.md`) ; réception Peppol des fournisseurs étrangers à vérifier (point 12 du même fichier).
- Les deux anciennes règles d'écran à faire confirmer (§ 2) et le point 2 de `a-faire-apres-chaine.md`.

## 7. Index des fichiers de décision et priorités

Un fichier de réponses de l'éditeur prime toujours sur le cahier des charges qu'il complète. Une décision plus récente de l'éditeur prime sur `CLAUDE.md` et sur les plans plus anciens pour son sujet, jusqu'à ce que le lot qui l'applique réécrive `CLAUDE.md` :
- `serie8/decision-verification-courrier.md` (19/10, lot V2, fait) sur la conception société et les avenants ;
- `serie8/decision-espace-cabinets.md` (19/10) sur tout ce que `CLAUDE.md` et les plans disent des experts-comptables, que `CLAUDE.md` décrit encore jusqu'au lot EC ;
- `serie8/reponses-cabinets.md` (24/10) sur `decision-espace-cabinets.md` et `avenant-cabinets.md` ;
- `serie8/reponses-types-activite.md` (24/10) sur `decision-types-activite.md` et sur la règle 10 de `decision-espace-cabinets.md` ;
- `t2/reponses-t2.md` (Q1) sur tout « heure de Paris » dit d'un magasin ;
- `serie8/reponses-affichage-pu.md` (son dernier texte, du 25/10) sur toute version antérieure.
Pour le reste, `CLAUDE.md` fait foi.

| Sujet | Fichiers (par ordre de priorité) |
|---|---|
| Façon de travailler, mots de l'éditeur | `historique/DIRECTIVES.md`, `prompt_maitre.txt`, `historique/messages-editeur.md` |
| Histoire du projet et leçons apprises | `historique/HISTORIQUE.md`, `historique/resumes-de-session.md`, `etat-chaines.md` |
| Installation de l'environnement | `installation/ENVIRONNEMENT.md`, `installation/installer-session.sh` |
| Série 8 (retours de l'éditeur après essai) | `serie8/reponses-serie8.md`, `serie8/plan-serie8.md`, `serie8/demande-editeur.md`, `serie8/reponse-editeur.md`, analyses `serie8/arrondi-et-calculs.md`, `caisse-clotures-jet-ereporting.md`, `ecrans-de-vente.md`, `equipe-et-achats.md` |
| Administrateurs sans vente, connexion par identifiant | `serie8/avenant-S8V-A4c.md` |
| Vérification par courrier recommandé | `serie8/reponses-v2.md`, `serie8/decision-verification-courrier.md`, `serie8/notes-lot-V2.md` |
| Cabinets d'expertise comptable | `serie8/reponses-cabinets.md`, `serie8/decision-espace-cabinets.md`, `serie8/avenant-cabinets.md` |
| Types d'activité | `serie8/reponses-types-activite.md`, `serie8/decision-types-activite.md` |
| Prix unitaires affichés | `serie8/reponses-affichage-pu.md` |
| Horodatages (T2) | `t2/reponses-t2.md` (dont le point 4, CLAUDE.md approuvé), `t2/decision-horodatage.md` |
| Heure locale des magasins (T3) | `t2/reponses-t2.md` (Q1), `t3/reponses-t3.md`, `t3/decision-heure-locale.md` |
| Brouillons et fiches (FA) | `fa/critique-completude.md`, `fa/balayage-5cbe4e2.json` |
| Architecture société | `societe/conception-v2-finale.md`, `societe/avenant-A4-serie6.md`, `societe/avenant-A4-complements.md`, `societe/reponses-serie4.md`, `reponses-serie5.md`, `reponses-serie6.md`, `reponses-editeur.md`, `inscription-sans-magasin.md` |
| Moteur de TVA | `tva/reponses-serie7.md`, `tva/plan-execution-decisions.md`, `tva/plan-execution.md`, `tva/conception-finale.md`, `tva/charges-demande-et-decisions.md`, `tva/reglementation.md`, `regles-tva-international.md` |
| Décisions 1 à 20 | `decisions-editeur.md`, `decisions/reponses-complements-14-20.md`, `audit-liste.md` |
| Campagne de tests (étape 1 bis) | `campagne/strategie/finale.md`, `societe/reponses-serie4.md` (T1 à T6) |
| Pages de questions à l'éditeur (sources) | `gen-decisions.js`, `decisions/serie4-donnees.js`, `decisions/serie8/questions.js` |
| SUPER PDP | `superpdp-plan.md`, `superpdp/openapi.json`, étape 2 de la feuille de route dans `CLAUDE.md` |
| Points à faire après la chaîne | `a-faire-apres-chaine.md` |
| Choix des moteurs de base | `moteurs/choix-moteur-finale.md` |

## 8. Décisions récentes de l'éditeur (résumé ; le fichier fait foi)

- **Cabinets** : l'invitation d'un client se fait par son SIREN, par le propriétaire du cabinet seulement ; contrôle Sirene à l'acceptation, sans courrier ; page de rattachement à trois choix pour une société déjà inscrite ; Q-EC3, 5, 6, 7 = a.
- **Types d'activité** : deux familles seulement (avec stock ; services sans stock), plus le cabinet ; aucun article fabriqué ; le type ne change jamais ; assurance professionnelle facultative (choix de Claude, contestable).
- **V2** : nom du représentant lu à l'INPI ; adresse choisie à la saisie du code confirmée par un lien ; adresse d'une inscription close libérée ; un code n'est jamais annulé après des essais faux ; signature à la main après une transmission de la propriété.
- **T2** : délais en jours = même heure n jours plus tard ; « (heure d'été) / (heure d'hiver) » dans les journaux seulement ; puce « Outillage » de CLAUDE.md approuvée et écrite.
- **T3** : chaque magasin à l'heure de son territoire ; pas de déménagement entre territoires (nouveau magasin) ; pendant une panne de l'INSEE, attendre la confirmation ; territoire mal lu corrigé par `editeur:corriger-territoire` avant le premier document numéroté.
- **PU** : prix unitaires affichés au centime (54,17 €), calcul exact inchangé, note de calcul des PDF gardée avec une phrase sur l'arrondi des prix unitaires. L'éditeur a hésité plusieurs fois : la version du fichier est la finale.
- **Production** : le fuseau UTC de la base ne doit pas être une action à retenir : fait par les migrations dans le lot L1 (demande de l'éditeur du 26/10/2026).
- **Branche de reprise** : toujours à jour, à chaque changement, jamais seulement à la fin ; relue à froid après chaque lot (demandes de l'éditeur du 26/10/2026).

## 9. Rappels à faire à l'éditeur

- Clé de l'API Sirene de l'INSEE, accès au registre national des entreprises (INPI), contrat La Poste avec remise en personne, compte SUPER PDP de production.
- Renouveler les deux secrets du bac à sable SUPER PDP après les tests.
- Analyse d'impact (AIPD) de la vérification des sociétés par courrier.
- Faire vérifier en priorité, comme le signale `serie8/decision-types-activite.md` (« Limites signalées »), si l'art. 286, I, 3° bis du CGI exige, depuis la loi de finances pour 2025, le certificat d'un organisme accrédité au lieu de l'attestation individuelle de l'éditeur. L'étape 3 et le texte de `CLAUDE.md` sur l'attestation en dépendent.
- Accès à l'annuaire de l'Ordre des experts-comptables (lot EC) : sans documentation officielle lisible, la procédure de l'éditeur fait foi, et l'éditeur fournit ce que le compte rendu du lot demande.
- Faire confirmer par l'expert-comptable les règles signalées comme telles dans `CLAUDE.md`, et prévoir un audit de sécurité avant la production.
- Le point 2 de `a-faire-apres-chaine.md` (commande `composer run dev`) et les questions ouvertes du § 6 attendent sa réponse.

## 10. Mettre à jour cette branche, à chaque fois

`bash <scratchpad>/pilotage-sync.sh "message"` copie les fichiers listés dans `pilotage-fichiers.txt` et les scripts de reprise dans `notes/`, régénère `ETAT.md`, vérifie l'absence de secret, commite et pousse `claude/pilotage-reprise`.

L'éditeur veut que cette branche ait toujours les dernières notes, jamais seulement à la fin. La session le lance donc aussitôt après chaque changement : chaque décision ou réponse de l'éditeur écrite dans `notes/` ; chaque modification de cette fiche, d'un script de la chaîne ou d'un plan ; chaque lancement, arrêt, interruption ou reprise d'une course ; chaque point de suivi ; chaque résultat de CI. La chaîne le lance en plus après chaque lot poussé (étape `:notes`). Un nouveau fichier de notes (décision, consigne sauvée, script de reprise placé dans `<scratchpad>/reprises/`) s'ajoute à `pilotage-fichiers.txt` avant ce lancement, sinon il n'est pas copié. Cette fiche se modifie à la main dans `<scratchpad>/pilotage-reprise/README.md`, puis le script la commite avec le reste. Si la poussée échoue (code 4) : `git -C <scratchpad>/pilotage-reprise pull --rebase`, puis relancer le script.
