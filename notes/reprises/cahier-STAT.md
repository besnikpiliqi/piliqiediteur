# Lot STAT — Larastan et l'analyse des flux de Psalm à chaque envoi dans la CI

Décision de l'éditeur du 13/11/2026, vers 20 h 50 UTC : « oui pour larastan et psalm sur github ».
Elle répond à la proposition de Claude : les tâches « Analyse statique et seuils » (Larastan, `qualite.php seuils`) et « Analyse des flux de données » (Psalm `--taint-analysis` et son témoin) de `.github/workflows/tests.yml` tournent à chaque envoi et à chaque demande de fusion, et plus seulement à la main. La couverture, les tests de mutation et Psalm au niveau 1 (types : lots PS1 et PS2) restent à l'étape 15.

Ce cahier fait foi pour le lot.

## 1. Mesure de départ

- Larastan sur tout le code, réglé exactement par `phpstan.neon` (niveau 10 et ses options) : `env -u AI_AGENT -u CLAUDECODE -u CLAUDE_CODE vendor/bin/phpstan analyse --memory-limit=3G`. La sortie va dans un fichier ; tu lis le nombre d'erreurs et chaque erreur en entier.
  - Une mesure faite par la session sur 175df0f se trouve dans `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/ci-stat/larastan-175df0f.json (236 erreurs dans 65 fichiers ; surtout argument.type 87, return.type 28, method.nonObject 25, nullsafe.neverNull 18, cast.int 13)`, si elle est finie. Elle sert seulement d'indication : refais la mesure sur la tête.
- `php tests/Qualite/qualite.php seuils <(git show origin/<branche>:tests/Qualite/seuils.json)`.
- Psalm, analyse des flux de données puis témoin, avec les commandes du README et de la tâche `flux` de la CI. Installe d'abord `tools/psalm` (`composer install --working-dir=tools/psalm`) et prends la version de PHP que le README demande. Ensuite, `php tests/Qualite/qualite.php flux …` et `flux-temoin …`.
- Note les nombres de départ dans la note de passage, puis dans « faits ».

## 2. Tout amener à 0

- Larastan : 0 erreur au niveau et aux options de `phpstan.neon`, sans rien relâcher.
  - Les interdits sont ceux des lots TY1 et TY2, que `qualite.php seuils` contrôle déjà : ni `ignoreErrors` nouveau, ni fichier de référence (baseline), ni `@phpstan-ignore`, ni `@psalm-suppress`, ni `assert()`, ni `@var` en ligne, ni conversion forcée qui cache un type faux, ni type élargi pour faire taire une erreur.
  - Aucun niveau, aucune règle, aucune option, aucun chemin exclu et aucun stub de l'analyse ne baisse.
  - Chaque erreur se corrige par une vraie preuve de type : contrôle à l'exécution qui refuse proprement, type précis, docblock exact ou code réécrit.
- Quand une erreur cache un vrai défaut (valeur nulle possible, clé absente, type faux reçu d'un écran ou d'une requête) :
  - corrige-le, avec un test de régression qui échoue sans la correction ;
  - liste-le à part dans « faits » sous la forme « défaut réel : … ».
- `qualite.php seuils` rend 0 face au `seuils.json` de la tête poussée. Le fichier `seuils.json` ne baisse jamais. S'il doit changer (niveau, nombre d'erreurs), ce n'est que vers plus strict.
- Psalm flux : `qualite.php flux` et `flux-temoin` rendent 0. Un chemin de donnée signalé se corrige à sa source : validation, échappement, liste permise, requête paramétrée. On ne le fait jamais taire.
  - Si Psalm ne tourne pas dans ce conteneur (mémoire, version de PHP), dis-le dans « laisses » avec le message exact. La tâche de la CI tranchera après l'envoi, et le lot suivant la réparera si elle est rouge.

## 3. La CI à chaque envoi

- Dans `.github/workflows/tests.yml`, retire `if: github.event_name == 'workflow_dispatch'` des tâches `analyse` et `flux`.
- Réécris leur commentaire : décision de l'éditeur du 13/11/2026, Larastan et l'analyse des flux de Psalm à chaque envoi et à chaque demande de fusion.
- La tâche `couverture` reste à la main : n'y touche pas.
- Vérifie que la tâche `analyse` lit bien la base de comparaison des seuils pour un envoi (`github.event.before`) comme pour une demande de fusion. Elle doit fonctionner aussi quand `before` vaut des zéros, c'est-à-dire pour une nouvelle branche.
- Si un test du dépôt vérifie la forme de la CI (par exemple `OutilDeQualiteTest`), mets-le à jour pour la nouvelle règle, et fais-lui refuser le retour de la condition `workflow_dispatch` sur ces deux tâches. Ne l'affaiblis pas.

## 4. Textes

- CLAUDE.md, protocole de rigueur, point 3 : remplace la dernière phrase par ce texte, tel quel :
  « Après chaque envoi, la CI repasse toute la suite, Larastan (réglages de `phpstan.neon`) et l'analyse des flux de données de Psalm avec son témoin (décision de l'éditeur du 13/11/2026), et le lot suivant répare d'abord une CI rouge (décision de l'éditeur du 11/11/2026). Avant son commit, chaque lot lance aussi Larastan sur tout le code (`vendor/bin/phpstan analyse --memory-limit=3G`) et `php tests/Qualite/qualite.php seuils` : les deux rendent 0. Psalm au niveau 1 (types), la couverture et les tests de mutation ne se lancent pas pendant les lots : ils passent une seule fois sur tout le code à l'étape 15. »
- Garde intacte la phrase sur la correction d'une relecture, vérifiée par ses tests ciblés.
- CLAUDE.md, étape 15 de la feuille de route : n'y laisse que ce qui n'est pas encore fait. Larastan au niveau 10 et l'analyse des flux tournent déjà à chaque envoi.
  - Restent Psalm au niveau 1 (PS1, PS2), la couverture, la mutation, et les règles ou options de Larastan qui ne sont pas encore actives (TY3), si c'est le cas.
  - Vérifie ce dernier point dans `phpstan.neon` et `composer.json` avant d'écrire.
- README : mets à jour la partie sur la CI et les outils de qualité (ce qui tourne à chaque envoi, ce qui reste à la main) et la façon de lancer Larastan avant un commit.

## 5. Vérifications avant le commit

- Larastan : 0. `qualite.php seuils` : 0. Psalm flux et témoin : 0, ou bien la raison exacte dans « laisses ».
- `vendor/bin/pint --dirty --format agent`.
- Suite PostgreSQL complète, une seule fois, juste avant le commit, sur l'arbre exact du commit (protocole, point 3), code de sortie 0 contrôlé.
- Un seul commit pour le lot.
- Aucun changement de comportement visible n'est attendu, hors les défauts réels corrigés. Tout défaut réel corrigé a son test.
- Isolation des sociétés et des magasins vérifiée sur chaque requête touchée.

## 6. Décision de Claude pour les lots suivants (D74/D76, montrée à l'éditeur)

- Chaque lot lance Larastan et `qualite.php seuils` avant son commit. Avec le cache de Larastan, c'est rapide.
- Psalm flux tourne dans la CI seulement, parce qu'il est lent en local.
- Une CI rouge, analyse comprise, est réparée en premier par le lot suivant.

## 7. Petit ajout : matrice des décisions (D76)

- Dans `tests/Unit/matrice-des-decisions.php`, les deux décisions marquées « a_trancher » (série 4, décision 5, et D37 : le champ « Reçu le » d'un paiement) ne sont plus en attente.
  - Selon D76, la décision de Claude s'applique : une date passée est permise, une date future est refusée. L'éditeur l'a lue le 13/11/2026 sans la contester.
  - Remplace « a_trancher » par la règle qui la fait vivre, avec ses tests existants. Si aucun test ne refuse une date future ou n'accepte une date passée, ajoute-le.
  - Garde « remplacee » pour la part de l'échéance.
- Mets `MatriceDesDecisionsTest` à jour si une liste de clés y change.

## 8. CI rouge sur eba4bc8 : à réparer dans ce lot (ajout du 13/11, 21 h 25 UTC)

- La tâche « Audit des dépendances » est rouge. `composer audit` signale l'avis PKSA-yxbx-3c9s-cjt9 (CVE-2026-128083, GHSA-63hm-g96r-2m2q, gravité faible) sur `laravel/framework` : versions >=13.0.0 et <13.40.0. Il a été publié le 13/11 à 18:54 UTC. La version installée est la v13.34.0.
- Réparation (CLAUDE.md, « Outillage » : mise à jour aussitôt qu'un audit signale une faille) : `composer update laravel/framework --with-dependencies`, dans les plages permises de composer.json, sans version majeure. Ensuite `composer audit --locked` et `composer audit --locked --working-dir=tools/psalm` rendent 0.
- Avant le commit, la suite PostgreSQL complète tourne avec ce nouveau composer.lock.
- Ajoute une ligne au README (paquets mis à jour et pourquoi).
- Dans « laisses » : « À faire sur le PC de l'éditeur : composer install ».

**Précision du 13/11 à 22 h 42 UTC sur le § 8.** Le « processus extérieur » que signale la partie 1 était une copie de l'agent STAT, lancée par erreur par la session. Elle a fait la mise à jour de laravel/framework demandée au § 8. La partie 1 l'a annulée en remettant composer.lock et vendor à leur état commité. Cette mise à jour reste voulue et fait partie du lot : refais-la toi-même, comme le § 8 le dit, puis lance la suite PostgreSQL complète avec le nouveau composer.lock. Plus aucun autre agent ne travaille dans le dépôt.
