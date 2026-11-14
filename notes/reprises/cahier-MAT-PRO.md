# Lot MAT-PRO — les 295 décisions de Claude de l'onglet « À faire vérifier par un professionnel » dans la matrice décision → test

Décision de Claude (D74/D76), prise le 13/11/2026 après la question de l'éditeur : « tu parles pour ceux que je t'ai dit décide toi, ce que t'as mis dans la fiche ? ».

La matrice `tests/Unit/matrice-des-decisions.php` recopie les décisions de l'éditeur (530) et les relie aux règles et aux tests. Les 295 décisions de Claude de l'onglet « À faire vérifier par un professionnel », que PILIQI applique sans attendre (D76), n'y sont pas une par une. Il n'y en a qu'une trentaine, venues par la conversation. Ce lot les y met toutes.

## 1. Source

- Les décisions résumées et vérifiées de l'onglet : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/decisions-affichage/sortie/section-*.md`.
  - Ces fichiers font foi, avec la correction du 13/11 de T2.7 et T2.8 en catégorie O.
  - Ils comptent 70 décisions en A (appliquées au lot ER-PRO), 130 en B (à leur étape), 7 en C, 47 en D (déjà faites) et 41 en E.
  - Une même décision peut être notée deux fois, avec une « question au comptable » et une « question au prestataire ». Garde les deux, sous des clés distinctes, par exemple `T2.1-comptable` et `T2.1-prestataire`.
- Ce que le lot ER-PRO a fait de la section A : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/pro-d76/cahier-ER-PRO.md`, avec le commit du lot ER-PRO et sa relecture.

## 2. Ce que fait le lot

- Ajoute une source à `decisions` : « Décisions de Claude de l'onglet « À faire vérifier par un professionnel » (D76), appliquées sans attendre ; une réponse du professionnel prime ». Elle porte une entrée par décision de l'onglet, avec son numéro T et sa phrase résumée.
- Pour chaque décision :
  - Si le code l'applique, les « regles » qui la font vivre. Une règle de CLAUDE.md qui la porte existe déjà ou s'ajoute, avec des tests qui existent et qui la prouvent vraiment.
  - S'il manque un test pour une décision appliquée, écris-le : c'est le cœur du lot. Un test doit échouer si l'on casse la décision.
  - Sinon, « a_venir » avec l'étape de la feuille de route qui l'appliquera (étape 2 SUPER PDP, 3, 5, 9, 12, 13, 14, 15).
  - Ou « hors_logiciel » pour ce qui n'est pas du code (démarche, dossier de la revue fiscale…).
  - Ou « remplacee » quand une décision plus récente la remplace.
  - Ou « explication ».
  - Jamais « a_trancher » : D76 interdit d'attendre.
- Une décision de l'onglet que le code contredit est un défaut :
  - Corrige le code avec un test si la correction est petite et sûre.
  - Sinon, décris-la dans « laisses » avec le lot qui la corrigera, et note-la « a_venir » avec ce lot nommé.
  - Ne change jamais la décision pour qu'elle colle au code.
- `MatriceDesDecisionsTest` reconnaît cette source. Les règles de la nouvelle source sont les mêmes que pour les autres : relié à une règle testée, ou une raison écrite.
- Commentaire en tête de la matrice : ajoute la source, et une phrase pour dire que, si la colonne « Réponse du professionnel » de l'onglet est remplie, la réponse prime (D76). La décision change alors dans la matrice et dans le code, au lot qui l'applique.

## 3. Vérifications

- `MatriceDesDecisionsTest`, puis les tests ajoutés.
- Larastan et `qualite.php seuils` à 0 (lot STAT, fait juste avant).
- Suite PostgreSQL complète une seule fois avant le commit, code 0. Pint. Un seul commit.
- Dans « faits » : combien de décisions de l'onglet sont reliées à des tests, combien sont « a_venir » (par étape), combien sont « hors_logiciel », combien de tests ont été ajoutés, et chaque défaut trouvé.

## Ajout du 14/11/2026 à 00 h 50 UTC : CI rouge de 6bf1d94

**CI rouge de 6bf1d94 (lot STAT), à réparer EN TOUT PREMIER (ajout du 14/11/2026 à 00 h 50 UTC).** Course 43531775339 : la tâche « PHP 8.4, PostgreSQL 17 » échoue (6 966 tests, 1 erreur), les cinq autres tâches de tests sont annulées par elle ; « Analyse statique et seuils », « Analyse des flux de données », Pint et l’audit sont verts.
- **Test en échec :** `Tests\Feature\Tenancy\RequeteDuModeleTest::test_la_sous_requete_d_une_relation_vers_soi_meme_est_rendue_elle_meme_inchangee` → `relation "societes" does not exist` à l’insertion de la société.
- **Cause :** le lot STAT (565f1ba) a ajouté à cette classe un test qui appelle `ouvrirUneSociete()` et écrit en base, alors que la classe n’utilise pas `RefreshDatabase`. En parallèle, quand ce test est le premier de son processus à toucher la base, celle-ci n’est pas encore migrée ; ailleurs, ses lignes restent en base hors de toute transaction et peuvent gêner d’autres tests. L’échec dépend de l’ordre : la suite locale a pu passer.
- **Réparation :** `use RefreshDatabase;` dans la classe (comme `BindTenantContextFromFilamentTenantTest`), puis chercher dans `tests/` toute autre classe sans `RefreshDatabase` ni `DatabaseMigrations` qui écrit en base (`ouvrirUneSociete`, `::create(`, fabriques) et la corriger de même. Si possible, ajouter un contrôle (test d’outillage) qui refuse une classe de test qui écrit en base sans l’un de ces traits.
- Vérifier : le test seul (`php artisan test --compact tests/Feature/Tenancy/RequeteDuModeleTest.php`) sur une base vide fraîchement créée, puis la suite PostgreSQL complète. Commit à part, avant le reste du lot.

Si ta suite PostgreSQL complète tombe sur ce test, répare-le dans ton lot (protocole, point 4) et dis-le dans « faits » ; sinon, le lot DEMO-V le répare en premier.
