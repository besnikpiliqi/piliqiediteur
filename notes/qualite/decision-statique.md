# Analyse statique au plus strict : lots TY1, TY2, TY3 (Larastan) et PS1, PS2 (Psalm au niveau 1)

> Décision de l'éditeur du 09/11/2026 vers 21 h 05 UTC (D56, `historique/DIRECTIVES.md`) : ce cahier fait foi pour les lots TY1, TY2, TY3, PS1 et PS2. Il complète `qualite/decision-qualite.md` (lot QA) : pour l'analyse statique, il remplace le point 3 de son § 2, et il applique la réponse à son § 11. Version 3, du 09/11/2026 vers 22 h 40 UTC. Elle intègre trois relectures indépendantes : les 33 constats sur la version 1 (`scratchpad/revue-script/relecteur1.md` et `relecteur2.md`) et les 13 constats sur la version 2 (`scratchpad/revue-script/relecteur-v2.md`).

## 1. Les messages de l'éditeur, mot pour mot

- 09/11/2026 vers 20 h 34 UTC : « pourquoi le niveau est 6 et s'il peut monter au plus strict, 10 pour Larastan et 1 pour Psalm »
- Après les mesures et la proposition de la session (§ 11 de `decision-qualite.md`) : « oui + Psalm 1 ». Un premier envoi, que l'éditeur a interrompu puis remplacé par celui-ci, ajoutait « mais tu faise ce que tu vois la meilleurs option ».
- Rappel : le 07/11/2026 vers 22 h 40 UTC, l'éditeur a demandé « et avec phpstan tu doit alors controle tout le logiciel ? ». La session avait répondu : tout le code de l'application, à chaque envoi dans la CI, niveau monté de 0 au maximum, chaque erreur corrigée. Le lot QA s'est arrêté au niveau 6 ; ces lots tiennent cette réponse.

## 2. Ce qui est décidé, et le découpage

1. **Larastan au plus strict** : niveau 10, règles « bleeding edge », règles strictes et règles de dépréciation de PHPStan, options de contrôle de PHPStan et de Larastan (§ 4). Il porte sur tout le code de l'application hors des tests.
2. **Psalm au niveau 1** pour le contrôle des types, avec ses options de contrôle (§ 5). Son analyse des flux de données (D54) s'y ajoute et ne change pas.
3. **Cinq lots dans la chaîne**, après PG et avant CALC. Chacun est implémenté, relu par deux relecteurs, corrigé, poussé et contrôlé par la CI à part, pour que chaque diff reste lisible :
   - **TY1** : mesure de départ ; test d'empreinte de la caisse et des PDF ; survivants connus de la mutation ; contrôle des seuils de l'analyse statique ; durée de la CI ; périmètre élargi ; niveaux 7 et 8 (§ 4.2) ;
   - **TY2** : niveaux 9 et 10, et les `@var` en ligne déjà présents (§ 4.2) ;
   - **TY3** : `bleedingEdge`, règles strictes et de dépréciation, options de PHPStan et de Larastan, `#[\Override]` (§ 4.2) ;
   - **PS1** : réglages de Psalm, une seule passe « types et flux » dans la CI, options, classes `final`, constantes typées ; Psalm au niveau 2 (§ 5.5) ;
   - **PS2** : Psalm au niveau 1, options mesurées, verrou final contre toute suppression (§ 5.5).
4. **Après PS2**, chaque lot garde les deux outils à zéro erreur, sans exception nouvelle (§ 9). Les niveaux, les ensembles de règles, les options, le périmètre et les stubs ne peuvent que monter. Ils sont enregistrés dans `tests/Qualite/seuils.json` et contrôlés par `php tests/Qualite/qualite.php seuils <(git show origin/<branche>:tests/Qualite/seuils.json)`, comme dans la CI.
5. La session dit à l'éditeur, après la poussée de TY3, combien de signalements de Psalm restent (mesure du § 3.4).

## 3. Mesures de départ

Mesures du 09/11/2026 entre 20 h 40 et 22 h 10 UTC, faites par la session puis par les relecteurs, sur l'arbre du lot QA (avant PG). Chaque lot remesure au début, sur l'arbre qu'il reçoit, avec la configuration du dépôt, et donne ses chiffres dans son compte rendu.

### 3.1 Larastan sur `app` (743 fichiers)

| Configuration | Erreurs | Fichiers distincts (un trait compté une fois) |
|---|---|---|
| niveau 7 | 275 | 130 |
| niveau 8 | 1 067 | 280 |
| niveau 9 | 1 946 | 372 |
| niveau 10 | 2 524 | 390 |
| niveau 10 + `bleedingEdge` + règles strictes (sans `dynamicCallOnStaticMethod`) + dépréciations | 2 881 | 415 |
| + les options a à h du § 4.1, point 4 | 3 423 | 420 |
| + les options i à l du § 4.1, point 4, et `reportUnsafeArrayStringKeyCasting` | 4 163 | — |

**Nature des erreurs** :
- niveau 7 : `find()` typé « un modèle ou une liste de modèles » quand l'identifiant n'est pas un entier ;
- niveau 8 : relations et dates qui peuvent être nulles ;
- niveaux 9 et 10 : valeurs de type inconnu (saisies des formulaires de Filament, `config()`, JSON) converties ou utilisées sans contrôle ;
- options : cases de tableau lues sans vérifier qu'elles existent (`offsetAccess.notFound` 464), et 555 méthodes sans `#[\Override]`.

**La règle `dynamicCallOnStaticMethod`** donne à elle seule 1 636 erreurs, dont aucune n'est un défaut du code :
- 1 626 portent sur le constructeur de requêtes d'Eloquent et `Guard::user` : `Guard::user` 290, `orderBy` 246, `lockForUpdate` 148, `whereNull` 95, `exists` 95, `whereIn` 86, `sharedLock` 51… ;
- 10 portent sur `Session\Store`, `Application::isProduction`, `Request::setTrustedProxies`, `Model::getActualClassNameForMorph` et `Client::restore`.

**Options de Larastan** (§ 4.1, point 5) : les erreurs passent de 3 423 à 3 547 avec ses règles. Le détail :
- `larastan.uniqueJobUniqueFor` 3 : `EnvoyerDocumentJob`, `SubmitFactureToPdpJob` et `PreparerLeTelechargementDesDocumentsJob` sont uniques sans `uniqueFor`. Leur verrou d'unicité ne s'efface donc jamais quand le travail quitte la file sans finir (file vidée, travail effacé à la main) ;
- `larastan.noPublicModelScopeMethod` 40, `larastan.unusedViews` 28, `larastan.missingTranslations` 6 ;
- 47 de plus avec `checkModelProperties` ;
- `checkConfigTypes` seul retire 44 erreurs.

**Hors de `app`**, dans le périmètre du § 4.1, point 7 : 90 erreurs (`tests/Qualite/qualite.php` 71, `database` 13, `config` 5, `bootstrap/app.php` 1).

### 3.2 Psalm (contrôle des types, extension Laravel démarrée)

**Au niveau 1** :
- 4 699 signalements sur `app`, `routes` et `bootstrap`. Il en reste 4 512 une fois `tests/Qualite/macros.stub` déclaré à Psalm : les 65 `UndefinedMagicMethod` sont les macros que `AppServiceProvider` ajoute à Filament. Psalm infère les types de 98,5 % du code.
- Principaux types :

  | Type | Nombre |
  |---|---|
  | `MissingOverrideAttribute` | 555 |
  | `MissingClassConstType` | 524 |
  | `ClassMustBeFinal` | 523 |
  | `MixedArgument` | 366 |
  | `PossiblyNullArgument` | 343 |
  | `RedundantCast` | 270 |
  | `MixedArgumentTypeCoercion` | 231 |
  | `PossiblyNullReference` | 162 |
  | `MixedAssignment` | 132 |
  | `PossiblyNullPropertyFetch` | 130 |
  | `MixedMethodCall` | 124 |

  Tous les autres types comptent moins de 100 signalements, dont `RedundantCastGivenDocblockType` 65, `RedundantCondition` 52 et `TypeDoesNotContainType` 48.
- L'extension Laravel lit les colonnes des modèles dans les migrations (réglage par défaut `columnFallback="migrations"`) et type le compte connecté. Il ne reste donc que :
  - 6 `UndefinedMagicPropertyFetch` : 5 dans `FicheDeSaisiePdfGenerator.php`, lignes 62 à 75, où Psalm ne retient pas le type à travers `$estUneFacture`, et `RecuPaiement::$statut` dans `EnvoyerDocumentAction.php`, ligne 110 ;
  - 2 `UndefinedInterfaceMethod` : `Arrayable::offsetExists`, dans `AjouteDesLignesDeVente.php`, ligne 1081 ;
  - 2 `PropertyNotSetInConstructor` : `VerificateurIntegrite.php`, lignes 132 et 134.

**Au niveau 8 d'aujourd'hui**, Psalm rend déjà 591 signalements de sévérité « error ». La CI ne les voit pas, parce qu'elle ne lit que les chemins de sécurité :
- 555 `MissingOverrideAttribute`, que TY3 retire ;
- 36 que PS1 examine en premier, parce que certains peuvent être de vrais défauts : `TooFewArguments` 15 (`FicheDuMembre.php`, lignes 187, 209 et 298), `DuplicateArrayKey` 11 (dont `VerificateurIntegrite.php`, lignes 363 à 365), `ParadoxicalCondition` 5, `NoValue` 2, `UndefinedClass` 1 (`AppServiceProvider.php`, ligne 378), `UndefinedConstant` 1 (`ConnecteurSqlite.php`, ligne 32, que PG retire peut-être), `MissingTemplateParam` 1.

Le rapport contient aussi des signalements de sévérité « info » (2 919 au niveau 8). Un niveau moins strict y range ce qu'il ne compte pas.

**Effet des fichiers et des options** :
- ajouter `config` et `database` aux fichiers analysés : +67 ;
- `ensureArrayStringOffsetsExist` : +377 ;
- `ensureArrayIntOffsetsExist` : +35 ;
- `strictNullableComparison` : +6 ;
- `restrictReturnTypes` : +1 015 (§ 5.1, point 7) ;
- `findUnusedCode` : 1 023, dont 90 `UnusedClass`, parmi lesquelles 25 politiques que Laravel trouve lui-même.

Aucun chemin de sécurité : l'analyse des flux tourne aussi sur `config` et `database`.

Une première mesure de la session donnait 10 580 signalements. Elle était fausse : sa configuration, rangée hors du dépôt, empêchait l'extension Laravel de démarrer, sans aucun message (§ 3.4).

### 3.3 Durées et ressources

Même machine : 4 processeurs, 16 Go de mémoire, environ 8 Go de disque libres.

- Larastan au plus strict : environ 35 s, un processus. Psalm au niveau 1 : 70 à 80 s, environ 1,5 Go, un fil.
- Suite PostgreSQL complète : 22 à 30 min. Couverture comme la CI : 16 à 21 min, environ 1,6 Go écrits.
- Mutation : 2,8 à 5 s par mutant sur 4 fils. La passe complète des calculs et des droits prend 3 h 02 pour 3 885 mutants, plus 59 à 69 min pour `User.php`, `AccesDesCabinets` et `AffectationsDesMembres`.
- Dans la CI, un envoi qui change tous ces fichiers ferait durer la tâche « Couverture et mutation » environ 4 h 15 à 4 h 45. La limite de GitHub Actions est de 6 h par tâche.
- Mesure dans la CI sur l'envoi `6c7b3d1` (lot QA, 09/11) : tâche « Couverture et mutation » 1 h 22 min (tests avec couverture 29 min 20 s ; mutation des parties sensibles changées 52 min). Suites PHP 8.3, 8.4 et 8.5 : 29, 26 et 26 min ; Larastan 16 s.

### 3.4 Comment mesurer sans se tromper

- **PHPStan** reconnaît qu'un agent le lance (`AI_AGENT`, `CLAUDECODE`, `CLAUDE_CODE` et d'autres, voir `AgentDetector` dans son phar). Il rend alors un résumé tronqué. Pour la liste complète : `env -u AI_AGENT -u CLAUDECODE -u CLAUDE_CODE vendor/bin/phpstan analyse --memory-limit=3G --error-format=json --no-progress`.
- **Psalm** se lance et se mesure toujours depuis la racine du dépôt, avec `psalm.xml`, et jamais avec une configuration rangée ailleurs. Pour mesurer un autre niveau, on ajoute `--error-level=N`.
  - Pourquoi : sinon, Psalm se place dans le dossier de cette configuration. L'extension Laravel ne trouve plus `bootstrap/app.php` et se désactive sans rien dire.
  - Psalm demande PHP 8.3.16 ou plus. Sur cette machine (8.3.6), on le lance avec `php8.4 -d memory_limit=6G tools/psalm/vendor/bin/psalm`.
- **Recherches** : un motif de `grep` qui contient `$` s'écrit entre guillemets simples. Entre guillemets doubles, bash transforme `\$…` et casse le motif, si bien que la recherche ne trouve rien.

## 4. Larastan au plus strict (TY1, TY2, TY3)

### 4.1 Configuration finale (`phpstan.neon`, atteinte à la fin de TY3)

1. `level: 10`.
2. **Inclusions**, en plus de l'extension de Larastan :
   - `phar://phpstan.phar/conf/bleedingEdge.neon` ;
   - le `rules.neon` de `phpstan/phpstan-strict-rules` ;
   - le `rules.neon` de `phpstan/phpstan-deprecation-rules`.

   TY3 ajoute ces deux paquets par `composer require --dev`. Au 09/11/2026, ce sont les versions 2.1.1 et 2.0.5, compatibles avec PHPStan 2.3.5 et Larastan 3.14.0, sous licence MIT. `phpstan/extension-installer` n'est pas installé : les `rules.neon` s'incluent à la main. TY3 donne leur justification dans le README et le compte rendu (D54).
3. **Une seule règle désactivée** : `strictRules: dynamicCallOnStaticMethod: false`, avec un commentaire dans `phpstan.neon` qui dit pourquoi (§ 3.1).
   - Aucun autre paramètre n'est désactivé ni relâché.
   - Aucun paramètre actif par défaut ne s'écrit. Par exemple `exceptions.check.tooWideThrowType`, qui refuse un `@throws` faux, `treatPhpDocTypesAsCertain` ou `reportUnmatchedIgnoredErrors`.
4. **Options de PHPStan activées** (liste fermée) :
   - a. `checkUninitializedProperties: true` ;
   - b. `checkBenevolentUnionTypes: true` ;
   - c. `checkTooWideReturnTypesInProtectedAndPublicMethods: true` ;
   - d. `reportPossiblyNonexistentGeneralArrayOffset: true` ;
   - e. `reportPossiblyNonexistentConstantArrayOffset: true` ;
   - f. `reportAnyTypeWideningInVarTag: true` ;
   - g. `reportAlwaysTrueInLastCondition: true` ;
   - h. `checkMissingCallableSignature: true` ;
   - i. `checkMissingOverrideMethodAttribute: true` : 555 erreurs. `#[\Override]` se pose une seule fois, dans TY3, et retire aussi les 555 `MissingOverrideAttribute` de Psalm ;
   - j. `checkTooWideThrowTypesInProtectedAndPublicMethods: true` ;
   - k. `checkTooWideParameterOutInProtectedAndPublicMethods: true` ;
   - l. `exceptions.check.throwTypeCovariance: true` et `exceptions.check.tooWideImplicitThrowType: true` ;
   - m. `reportUnsafeArrayStringKeyCasting: detect`, qui suit ce que PHP fait d'une clé en chaîne numérique (`"123"` devient l'entier 123). La valeur `prevent` est mesurée aussi. Elle ne remplace `detect` que si chacune de ses erreurs se corrige par un type vrai. Le compte rendu donne les deux chiffres ;
   - n. `reportIgnoresWithoutComments: true` ;
   - o. `rememberPossiblyImpureFunctionValues: false` est mesurée, puis activée ou écartée, avec sa raison et ses chiffres.

   **Déjà actives, à ne pas réécrire** :
   - par les règles strictes : `polluteScopeWithLoopInitialAssignments`, `polluteScopeWithAlwaysIterableForeach` et `polluteScopeWithBlock` à `false`, `checkDynamicProperties`, `checkExplicitMixedMissingReturn`, `checkFunctionNameCase`, `checkInternalClassCaseSensitivity`, `reportMaybesInMethodSignatures`, `reportStaticMethodSignatures`, `reportMaybesInPropertyPhpDocTypes`, `reportWrongPhpDocTypeInVarTag`, `checkStrictPrintfPlaceholderTypes` ;
   - par `bleedingEdge` : `reportNonIntStringArrayKey` ;
   - par les niveaux : `checkExplicitMixed` (niveau 9) et `checkImplicitMixed` (niveau 10).

   **Sans objet** : `checkMissingOverridePropertyAttribute`. L'attribut sur une propriété demande PHP 8.5, et le logiciel vise 8.3.

   **Reste éteinte** : `exceptions.check.missingCheckedExceptionInThrows`, le modèle des exceptions vérifiées. Elle ferait déclarer par `@throws` chaque exception jusqu'au point d'entrée. Or Laravel transforme lui-même ses exceptions (validation, autorisation, fiche introuvable) en réponses : c'est une règle d'écriture, pas un contrôle de justesse.
5. **Options de Larastan** (voir `vendor/larastan/larastan/extension.neon`, `docs/custom-config-parameters.md` et `docs/rules.md`) :
   - **activées d'office** : `checkConfigTypes`, `checkUniqueJobUniqueFor`, `checkUniqueJobUniqueId`, `noBatchedUniqueJob`, `checkJobSerializesModels`, `checkBatchedJobIsBatchable`, `checkBatchableJobChecksCancellation`, `checkDispatchInTransactionAfterCommit` ;
   - **les trois tâches uniques du § 3.1** reçoivent chacune un `uniqueFor` au moins égal à la plus longue vie normale du travail : l'attente admise dans la file, plus, pour chaque essai, sa durée maximale (son `timeout`, à défaut le `retry_after` de sa file), plus les délais entre les essais. Une valeur plus courte laisserait partir un second envoi du même document, ou une seconde soumission de la même facture, ce que `ShouldBeUnique` empêche aujourd'hui (§ 6.2). La valeur s'écrit avec son calcul en commentaire. Un test prouve qu'un second envoi est refusé pendant toute cette durée, essais compris, et que le verrou s'efface après. Si aucune durée ne peut être fixée sans risque de double envoi, la question va dans « laisses » ;
   - **mesurées, puis activées ou écartées** avec leur raison et leurs chiffres : `checkModelProperties` (encore en bêta), `checkModelMethodVisibility`, `checkUnusedViews` (à vérifier contre les vues appelées par leur nom, par Filament ou par une variable), `checkMissingTranslations`, `checkAuthCallsWhenInRequestScope`, `noImplicitQueryBuilderCall`, `noUnnecessaryEnumerableToArrayCalls` ;
   - **sans objet** : `checkOctaneCompatibility`, puisque PILIQI n'utilise pas Octane.
6. **Propriétés non initialisées** : `checkUninitializedProperties` ne signale que `VerificateurIntegrite::$rapport` et `::$magasin`, posées par `verifier()`.
   - On les réécrit en variables locales ou en objet de résultat, en gardant exactement le rapport du vérificateur.
   - `additionalConstructors` n'est pas utilisé, et jamais pour `mount()` d'un composant Livewire. Livewire n'appelle `mount()` qu'à la première requête et ne garde que les propriétés publiques : une propriété protégée ou privée posée dans `mount()` est vide aux requêtes suivantes.
   - Les composants Livewire et les pages de Filament donnent déjà une valeur par défaut à leurs propriétés, et Larastan ne prévoit rien pour Livewire.
7. **Périmètre** (`paths`) : `app`, `routes`, `bootstrap` (sauf `bootstrap/cache`), `config`, `database` (migrations, factories, seeders), et les outils de la CI de `tests/Qualite` (`qualite.php`, `parties.php`…).

   **Exclusions permises, et seulement celles-là** :
   - `bootstrap/cache` ;
   - `tests/Qualite/Psalm`, l'extension et le stub chargés par Psalm seul, avec son propre chargeur ; son témoin contient exprès des chemins dangereux ;
   - `tests/Qualite/Temoins`, les fichiers témoins de PHPStan du § 4.3, qui violent exprès des règles.

   Les tests (`tests/Feature`, `tests/Unit`, `tests/Browser`) restent hors du périmètre : ils ne partent pas en production, et leurs erreurs se voient quand ils tournent. Le README dit pourquoi.

### 4.2 Les trois lots de Larastan

**TY1**, dans cet ordre :
1. **Mesure de départ** sur l'arbre reçu (après PG). Les chiffres vont dans le compte rendu.
2. **Test d'empreinte de la caisse et des PDF** (§ 6.4), dans le premier commit. Il passe avant de toucher un fichier de la partie `caisse_securisee` de `tests/Qualite/parties.php` ou un générateur de PDF.
3. **Survivants connus de la mutation** (§ 6.5, point 1). Ils sont traités dans un commit à part, avant de toucher un fichier des calculs ou des droits.
4. **Contrôle des seuils de l'analyse statique** (§ 4.3), avec ses tests. Les tests des ensembles de règles ne sont pas de ce lot : TY3 les fait, avec ses deux paquets.
5. **Durée de la CI** :
   - l'agent relève, avec les outils GitHub, la durée de la tâche « Couverture et mutation » sur le dernier envoi dont la tâche est finie. Celle de PG peut encore tourner : il la relève avant le dernier commit du lot ;
   - si elle dépasse 4 h 30, ou si l'estimation pour un envoi qui change tous les fichiers des calculs et des droits (§ 3.3) dépasse 5 h, il partage la mutation en tâches parallèles : une matrice par partie, chacune avec sa couverture ;
   - ce partage ne change rien à ce qui est muté ni aux seuils. Une tâche annulée pour dépassement de durée ne se règle jamais en réduisant ce qu'elle contrôle.
6. **Périmètre élargi** (§ 4.1, point 7), puis niveau 7, puis niveau 8. Chaque niveau donne un ou plusieurs commits, par domaine (Facturation, Tenancy, Filament…), chacun vert.

**TY2** :
1. Niveau 9, puis niveau 10.
2. **Les `@var` en ligne déjà présents dans le périmètre.** Au 09/11/2026, il y en a 54 : 27 devant une affectation (comme `AjouteDesLignesDeVente.php`, ligne 798) et 27 devant un `return` (comme `TableauDeBordDuCabinet.php`, ligne 71 : `/** @var Societe */ return Filament::getTenant();`). Chacun est remplacé par une vérification réelle : `instanceof` et exception explicite.
3. **Le refus des `@var` en ligne**, ajouté à `qualite.php seuils` avec son test dans `OutilDeQualiteTest`. Il refuse tout commentaire, lu par `token_get_all` (`T_COMMENT` ou `T_DOC_COMMENT`), qui contient `@var`, `@phpstan-var` ou `@psalm-var` sans précéder une déclaration de propriété ou de constante (attributs sautés), dans les fichiers du périmètre du § 4.1, point 7.

**TY3** :
1. Les deux paquets, `bleedingEdge`, les règles strictes (sans `dynamicCallOnStaticMethod`) et les règles de dépréciation.
2. Les options du § 4.1, points 4 et 5, et `#[\Override]`.
3. `seuils.json`, `qualite.php` et `OutilDeQualiteTest`, complétés pour ce qui n'a de sens qu'avec les deux paquets (§ 4.3) : ensembles de règles, `featureToggles`, `strictRules` et témoins.
4. Le README et `CLAUDE.md` (§ 4.4).

**À chaque niveau ou palier** :
- `phpstan.neon` et `seuils.json` changent ensemble : le niveau enregistré monte avec chaque niveau atteint ;
- Larastan rend zéro erreur sur tout le périmètre ;
- `php artisan migrate:fresh` puis la suite PostgreSQL complète, tests en navigateur compris, rendent 0 ;
- pint est passé.

TY1, TY2 et TY3 ne corrigent aucun signalement de types de Psalm, sauf TY3, qui retire les `MissingOverrideAttribute` en posant `#[\Override]`.

### 4.3 `seuils.json`, `qualite.php` et la CI

1. **Ce que `seuils.json` enregistre pour Larastan** : les paramètres effectifs attendus.
   - Le niveau.
   - Les chemins (`paths`).
   - Les exclusions (`excludePaths`), seulement celles du § 4.1, point 7.
   - Les stubs (`stubFiles`) : `tests/Qualite/macros.stub` seulement.
   - `featureToggles` en entier.
   - Les options, à leur valeur stricte : `true` pour les options booléennes, `false` pour `rememberPossiblyImpureFunctionValues` si elle est activée, et la valeur choisie pour `reportUnsafeArrayStringKeyCasting`.
   - Les règles désactivées : la seule `dynamicCallOnStaticMethod`.
   - Les identifiants des ensembles de règles et des règles propres au dépôt (§ 4.3, point 3). La liste des règles propres au dépôt ne peut que grandir.
2. **Comment `qualite.php seuils <ancien seuils.json>` lit les paramètres** : par `vendor/bin/phpstan dump-parameters --json`, lancé sans `AI_AGENT`, `CLAUDECODE` ni `CLAUDE_CODE`. Il ne lit pas le texte de `phpstan.neon` : aucun lecteur NEON n'est disponible hors du phar. Il échoue si l'une de ces conditions est vraie :
   - le niveau baisse ;
   - un chemin, une option ou une règle du dépôt disparaît ;
   - une option change de valeur ;
   - une règle désactivée, une exclusion, un stub, une classe de `universalObjectCratesClasses` ou une entrée de `ignoreErrors` apparaît ;
   - `treatPhpDocTypesAsCertain` ou `reportUnmatchedIgnoredErrors` passe à `false` ;
   - les paramètres effectifs diffèrent de `seuils.json` ;
   - le témoin du point 3 ne produit plus l'erreur attendue ;
   - un commentaire d'un fichier du périmètre contient `@phpstan-ignore`. La recherche se fait par `token_get_all`, sur `T_COMMENT` et `T_DOC_COMMENT` seulement, pour que `qualite.php` ne se trouve pas lui-même.
3. **Ensembles de règles et règles propres au dépôt** : ils se prouvent par leur effet, et non par un paramètre. Deux raisons :
   - `dump-parameters` ne rend pas la section `rules:` de `phpstan.neon`, qui est une extension du conteneur ;
   - `strictRulesInstalled`, `deprecationRulesInstalled` et `featureToggles.bleedingEdge` sont des paramètres qu'un `phpstan.neon` pourrait mettre à `true` sans inclure les `rules.neon`.

   Chaque règle propre au dépôt a donc un fichier témoin qui la viole exprès, dans `tests/Qualite/Temoins/PHPStan`, exclu de l'analyse ordinaire. Un témoin produit aussi une erreur d'une règle stricte, une erreur de dépréciation et une erreur que seul `bleedingEdge` produit. `qualite.php` analyse les témoins avec la configuration du dépôt, sans l'exclusion de leur dossier. Il exige, dans le rapport JSON, l'identifiant de chaque erreur attendue que `seuils.json` enregistre.
4. **Sans argument**, la commande écrit son usage et sort avec le code 2. Une clé absente de l'ancien `seuils.json` compte comme « rien d'enregistré » : le premier envoi de TY1, puis ceux de TY3 et de PS1, ne rendent pas la CI rouge parce que le `seuils.json` du commit de base a l'ancienne forme.
5. **`tests/Feature/Qualite/OutilDeQualiteTest.php`** prouve chaque refus, sur un dépôt temporaire qui contient aussi `phpstan.neon` et `psalm.xml`, en appelant le PHPStan du dépôt par son chemin absolu. Aujourd'hui, il n'a aucun test de `seuils`.
6. **La tâche Larastan de la CI** garde son principe (`--memory-limit=3G`). Le compte rendu dit sa durée.

### 4.4 README et CLAUDE.md (TY3)

- **README**, section des outils de qualité :
  - niveau, ensembles de règles, options ;
  - la règle désactivée et sa raison ;
  - le périmètre, ses exclusions et leurs raisons ;
  - la commande locale avec sa mémoire ;
  - les pièges de mesure du § 3.4.
- **CLAUDE.md** : à la fin de la puce « Outillage », cette phrase, et aucune autre :

  > « l'analyse statique contrôle tout le code de l'application hors des tests (app, routes, bootstrap, config, database et les outils de tests/Qualite) au plus strict : Larastan au niveau 10 avec bleedingEdge, ses règles strictes (sauf dynamicCallOnStaticMethod, contraire à l'écriture des requêtes de Laravel), ses règles de dépréciation et ses options de contrôle, sans aucune erreur cachée ; ces niveaux ne redescendent jamais (décision de l'éditeur du 09/11/2026) »

  Rien ne se coche dans la feuille de route.

## 5. Psalm au niveau 1 (PS1, PS2)

### 5.1 Configuration finale (`psalm.xml`, atteinte à la fin de PS2)

1. `errorLevel="1"`.
2. **Fichiers analysés** : `app`, `routes`, `bootstrap` (sauf `bootstrap/cache`), `config`, `database`.
3. **Extension Laravel** réglée pour échouer au lieu de se désactiver en silence : `<failOnInternalError value="true"/>`, selon sa documentation (`tools/psalm/vendor/psalm/plugin-laravel/docs/config.md`). Ce réglage va dans `psalm.xml` et dans `psalm-temoin.xml`.
4. **Stubs** : celui d'aujourd'hui (`tests/Qualite/Psalm/filament.stub`), plus `tests/Qualite/macros.stub`.
   - `macros.stub` est déjà comparé aux vraies macros par `MacrosDeclareesTest`.
   - `filament.stub` reçoit lui aussi un test qui le compare au code réel de Filament (§ 6.1).
5. **Défauts de Psalm 7 qui restent actifs** : `psalm.xml` ne les écrit jamais à `false`, et `qualite.php` refuse qu'il le fasse, quel que soit l'ancien `seuils.json` :
   - `sealAllMethods`, `sealAllProperties`, `strictBinaryOperands`, `ensureOverrideAttribute` ;
   - `findUnusedBaselineEntry` (le `false` d'aujourd'hui disparaît) ;
   - `findUnusedIssueHandlerSuppression`, `disableSuppressAll`, `reportMixedIssues`, `runTaintAnalysis` ;
   - `ignoreInternalFunctionFalseReturn` et `ignoreInternalFunctionNullReturn`, qui restent à `false`.

   Ne s'écrivent jamais : `errorBaseline`, `usePhpDocPropertiesWithoutMagicCall="true"`, et `<issueHandlers>` (sauf les exceptions du § 5.3).
6. **Options activées** :
   - `ensureArrayStringOffsetsExist` et `ensureArrayIntOffsetsExist` ;
   - `strictNullableComparison` ;
   - `disableVarParsing` : Psalm ne lit plus aucun `@var` ailleurs que sur les propriétés ;
   - `findUnusedPsalmSuppress`.
7. **Mesurées, puis activées ou écartées**, avec leur raison et leurs chiffres :
   - `restrictReturnTypes` (1 015 `LessSpecificReturnType` au 09/11/2026). Elle n'est activée que si chaque signalement se corrige par un type exact et vrai sur toute machine. Une méthode redéfinie garde la signature de son parent, et aucun type littéral ne dépend de l'environnement. Exemple impossible : `DiagnostiquerCaisseSecurisee::controlerOpenssl`, dont Psalm déduit le type avec la version d'OpenSSL de la machine ;
   - `findUnusedCode` (1 023 signalements). Elle n'est activée que si ses faux signalements viennent seulement de points d'entrée appelés par Laravel, Filament ou Livewire, et peuvent se déclarer un par un (§ 5.2, `@psalm-api`) sans cacher du vrai code mort. Le vrai code mort trouvé est supprimé (CLAUDE.md) ;
   - `rememberPropertyAssignmentsAfterCall="false"`, `disallowLiteralKeysOnUnshapedArrays` et `findUnusedVariablesAndParams`.
8. **Écartées** : `checkForThrowsDocblock` et `checkForThrowsInGlobalScope`. Elles relèvent du modèle des exceptions vérifiées, écarté pour la même raison qu'au § 4.1, point 4.
9. **À la fin de PS2**, s'il n'existe aucune exception du § 5.3 : `<unsuppressibleIssues>` sur tous les problèmes (`CodeIssue`). Psalm refuse alors lui-même tout `@psalm-suppress` et toute référence.
10. **CI** : depuis Psalm 7, l'analyse des flux tourne par défaut dans la même passe que le contrôle des types.
    - La tâche « Analyse des flux de données » devient « Psalm (types et flux) ». Une seule passe, avec `psalm.xml`, écrit le rapport JSON et garde la sortie au format de GitHub.
    - `qualite.php flux` vérifie que ce rapport ne contient aucun chemin de sécurité.
    - Puis une nouvelle commande, `qualite.php types`, vérifie qu'il ne contient aucun signalement de sévérité « error ». Les « info » d'un niveau moins strict ne comptent pas ; au niveau 1, il n'y en a plus.
    - La tâche du témoin (`psalm-temoin.xml`) garde son principe.
    - Le commentaire en tête de `psalm.xml` et le README cessent de dire que Psalm ne sert qu'à l'analyse des flux.

### 5.2 Corrections propres à Psalm

- **Attributs des modèles** : l'extension les lit déjà. Aucune annotation `@property` n'est ajoutée pour Psalm : elle primerait sur les migrations, et une annotation fausse cacherait la vérité.
  - Les 6 `UndefinedMagicPropertyFetch` restants se corrigent dans le code : une vérification `instanceof` au lieu de `$estUneFacture`, et le cas de `RecuPaiement` dans `EnvoyerDocumentAction`.
  - De même pour les 2 `UndefinedInterfaceMethod`.
- **`auth()->user()`** : l'extension le type ; rien n'est réécrit pour Psalm.
- **`PropertyNotSetInConstructor`** : seul `VerificateurIntegrite` est concerné, et TY3 l'a déjà corrigé (§ 4.1, point 6).
- **Les 36 signalements « error » du niveau 8** (§ 3.2) sont examinés en premier. Un vrai défaut se corrige selon le § 6.3.
- **`ClassMustBeFinal`** : la classe devient `final`, sauf si une autre classe en hérite, tests compris. Psalm n'analyse pas `tests/` : il ne voit ni leurs classes anonymes ni leurs simulations.
  - **Exception** : une classe de `app` qu'un test étend ou simule, pour injecter une panne, n'est pas rendue finale (Mockery refuse une classe `final`). Ce test ne change pas.
  - **Les neuf classes concernées au 09/11/2026**, vérifiées par `token_get_all` sur `tests/` :
    - six étendues par une classe des tests : `MettreFinAuLienAction`, `RegistreFiscal`, `CloturerCaisseAction`, `ExpirerLesDevisAction`, le modèle `VerificationSociete`, `FauxRepertoireSirene` ;
    - trois simulées par `$this->mock` : `FermerLesCaissesPersonnellesAction`, `FermerSaCaisseAction`, `FermerLaDemonstrationAction`.
  - **Marque** : chacune porte `@psalm-api`, suivi dans le même bloc d'une ligne « Utilisée hors de l'analyse : » qui nomme le ou les fichiers de test.
  - **Contrôle** : `qualite.php` refuse un `@psalm-api` sans cette ligne, ou dont aucun fichier de test nommé n'étend ni ne simule cette classe. La recherche se fait par `token_get_all` : `extends`, `mock`, `partialMock`, `spy`, `Mockery::mock`, `createMock`. Il refuse aussi une classe de `app` que `tests/` étend ou simule sans porter `@psalm-api`.
  - **Interdit** : aucune interface n'est créée pour le seul besoin d'un test.
  - **Toutes les autres classes** deviennent `final` : modèles, ressources et pages de Filament, composants Livewire, commandes, e-mails, fabriques.
- **`MissingOverrideAttribute`** : déjà fait par TY3 (`#[\Override]`).
- **`MissingClassConstType`** : constantes typées (PHP 8.3).
- **`RedundantCast`, `RedundantCastGivenDocblockType`, `RedundantCondition`** : voir le § 6.2, c. Une conversion ou une condition ne se retire que si son type est prouvé par PHP lui-même. Jamais par un PHPDoc, un attribut sans cast, un agrégat SQL ou une colonne `numeric`, qui arrive en chaîne sur PostgreSQL.
- **`Mixed…`, `PossiblyNull…`** : mêmes corrections qu'aux niveaux 8 à 10 de Larastan (§ 6).

### 5.3 Seules exceptions de configuration permises

Un type de signalement ou une option ne se désactive que si les quatre conditions sont réunies :
- (a) chaque signalement visé est un faux signalement : Psalm ou son extension Laravel ne comprend pas Laravel, Filament ou Livewire, et notre code n'y est pour rien ;
- (b) aucune déclaration précise ne peut dire la vérité : ni une annotation vraie, ni un stub vérifié par un test, ni un réglage de l'extension ;
- (c) l'exception est la plus étroite possible : `referencedClass`, `referencedMethod` ou `referencedProperty` d'une classe de `vendor`, jamais un fichier ni un dossier de notre code ;
- (d) elle est écrite dans `psalm.xml` (en commentaire), dans le README et dans le compte rendu, avec son nombre de signalements et un exemple. La session la dit à l'éditeur.

Rien d'autre ne se désactive : aucun `@psalm-suppress`, `@psalm-ignore-nullable-return` ni `@psalm-ignore-falsable-return`, et aucun fichier de référence.

### 5.4 `seuils.json`, `qualite.php` et la CI pour Psalm

1. **Ce que `seuils.json` enregistre pour Psalm** : le niveau, les fichiers analysés et exclus, les stubs, les extensions, chaque attribut de `psalm.xml` avec sa valeur, les exceptions du § 5.3 et `<unsuppressibleIssues>`.
2. **`qualite.php seuils` échoue si l'une de ces conditions est vraie** :
   - le niveau monte vers 8, c'est-à-dire devient moins strict ;
   - une option, un fichier analysé, un stub ou une extension disparaît ;
   - un attribut ou un élément que l'ancien `seuils.json` n'énumère pas apparaît ;
   - l'une des valeurs interdites du § 5.1, point 5, apparaît, quel que soit l'ancien `seuils.json` ;
   - `psalm.xml` ne dit pas la même chose que le nouveau `seuils.json` ;
   - un commentaire du code contient `@psalm-suppress`, `@psalm-ignore-nullable-return` ou `@psalm-ignore-falsable-return` (recherche par `token_get_all`, comme au § 4.3) ;
   - un `@psalm-api` ne respecte pas le § 5.2.

   `OutilDeQualiteTest` prouve chacun de ces refus.
3. **README** : niveau, options, exceptions et leurs raisons, commande locale, piège du § 3.4.
4. **CLAUDE.md** (PS2) : compléter la phrase de TY3, juste après « ses options de contrôle », par cette phrase, et aucune autre :

   > « , et Psalm au niveau 1 (sur app, routes, bootstrap, config et database) avec ses options de contrôle, en plus de son analyse des flux »

### 5.5 Les deux lots de Psalm

**PS1** :
1. Mesure de départ, après TY3.
2. Les 36 signalements « error » du niveau 8 (§ 3.2).
3. Les réglages du § 5.1, points 2 à 6 et 10, macros comprises.
4. `ClassMustBeFinal` et `MissingClassConstType`.
5. Les corrections des niveaux 8 à 2.
6. `errorLevel="2"` : tout est signalé comme « error », sauf ce que le niveau 2 range en « info », surtout les `Mixed…`.
7. `seuils.json` et `qualite.php` pour Psalm (§ 5.4).

Chaque palier donne un ou plusieurs commits, chacun vert.

**PS2** :
1. Niveau 1.
2. Les options du § 5.1, point 7, mesurées puis décidées.
3. `<unsuppressibleIssues>` (§ 5.1, point 9).
4. Le README et `CLAUDE.md` (§ 5.4).

Larastan reste au plus strict, à zéro erreur, pendant PS1 et PS2.

## 6. Règles communes aux cinq lots

### 6.1 Une vraie correction, jamais une erreur cachée

Chaque erreur se corrige par un vrai changement du code. Les corrections permises sont :
- un type précis, quand il est vrai : PHPDoc générique, forme d'un tableau, type de retour ;
- une vérification réelle, qui refuse la valeur fausse ou lève une exception explicite avec un message en français ;
- un objet typé à la frontière : formulaire, requête, JSON, configuration, file d'attente ;
- une relation ou une valeur chargée explicitement.

Sont interdits :
- un fichier de référence (baseline), `ignoreErrors`, `@phpstan-ignore…`, `@psalm-suppress`, `@psalm-ignore-nullable-return`, `@psalm-ignore-falsable-return` ;
- `assert()`, désactivé en production ;
- un `@var` ou un `@psalm-var` en ligne (§ 4.2, TY2) ;
- une conversion forcée (`(int)`, `(string)`, `(float)`, `(bool)`, `intval()`, `strval()`, `settype()`…) d'une valeur dont la nature n'est pas vérifiée juste avant ;
- un type élargi (`mixed`, nullable) pour faire passer l'erreur ;
- un `@phpstan-assert` ou un `@psalm-assert` sur une fonction qui ne vérifie pas vraiment ;
- un PHPDoc de forme de tableau, ou d'un type plus précis que ce que PHP garantit, sur une valeur venue du navigateur ou de l'extérieur. Cela vise : une propriété publique d'un composant Livewire ou d'une page de Filament (dont `$data`) ; le paramètre `$data`, `$state` ou `$get` d'une fonction d'écran ; ce que rendent `config()` et `json_decode()`. Ces valeurs se lisent par une vérification réelle. C'est une exception à la consigne générale de CLAUDE.md sur les formes de tableau, qui vaut pour les valeurs que produit notre code ;
- une entrée de stub qui donne à du code de `vendor` un type plus précis que celui qu'il rend vraiment. Chaque stub est comparé au réel par un test, comme `MacrosDeclareesTest`.

Hors de cette liste, il n'y a que deux exceptions : la règle désactivée du § 4.1, point 3, et les exceptions du § 5.3.

### 6.2 Le comportement ne change pas

- Aucune règle métier ne change.
- Aucune valeur attendue d'un test existant ne change. Un commit de typage ne retire ni ne change aucune ligne de test ; il peut en ajouter.
- Une vérification ajoutée sur un chemin qu'une saisie peut atteindre doit être déjà garantie par la validation existante. Sinon, c'est un défaut réel (§ 6.3).
- Une correction qui toucherait une règle métier non tranchée s'arrête là et se décrit dans « laisses ».

Corrections interdites, parce qu'elles changent le comportement sans qu'un test le voie :
- (a) **Remplacer une erreur par une valeur par défaut** (`??`, `?:`, `?->`, ou un défaut après `array_key_exists`) là où la case, la relation ou la valeur ne peut pas manquer. On lève alors une exception explicite. Une valeur par défaut n'est permise que là où le code actuel rend déjà ce résultat pour ce cas, prouvé par un test.
- (b) **Réécrire une condition sur une valeur qui peut valoir `'0'`, `0`, `''` ou `[]`** (`if ($x)`, `! $x`, `?:`, `empty()`, `array_filter()` sans fonction) autrement que par une forme équivalente pour toutes les valeurs de son type réel. Un test couvre `'0'` quand le type l'admet.
- (c) **Retirer une conversion, un `??`, un `instanceof` ou une condition jugés inutiles** quand leur type vient d'un PHPDoc, d'un attribut de modèle sans cast, d'un agrégat SQL ou d'une colonne `numeric` (une chaîne sur PostgreSQL). On corrige d'abord le PHPDoc, ou on garde la garantie à l'exécution.
- (d) **Rendre stricte une comparaison**, ou `in_array()`/`array_search()`, entre un identifiant venu d'une route, de Livewire ou d'un formulaire et un entier, sans avoir d'abord ramené les deux au même type.
- (e) **Exiger un `int` ou un `bool` à la frontière d'un écran.** La lecture doit accepter tout ce que le navigateur et Livewire envoient vraiment (`'12'`, `12`, `''`, `null`, `'0'`, `'1'`, `true`) et rendre, pour chacun, le résultat d'avant. Exemple : `ListMembres.php`, ligne 221, `(int) $get('magasin_id')`, où un Select envoie `'12'`, ou `null` avant tout choix. Un test unitaire couvre chaque forme.
- (f) **Laisser une exception ajoutée dans une tâche planifiée ou un travail en file arrêter le traitement des autres magasins.** Cela vise la clôture automatique, les caisses personnelles, les relances, l'expiration des devis et les téléchargements.

Le corps de chaque message de commit liste ses changements de ces six sortes (fichier, avant, après), pour les relecteurs.

### 6.3 Défauts réels

Un défaut réel trouvé se corrige dans son propre commit, avec un test de régression qui échoue sans la correction. Exemples : un appel sur une valeur qui peut vraiment être nulle et donnerait une erreur 500 ; une case de tableau qui peut vraiment manquer ; une tâche unique sans `uniqueFor`. Le compte rendu le liste : fichier, scénario, test.

### 6.4 Caisse sécurisée et PDF : le test d'empreinte

Le premier commit de TY1 ajoute un test d'empreinte, avant tout changement d'un fichier de la partie `caisse_securisee` ou d'un générateur de PDF.

**Ce qu'il fait** :
1. Il fige le temps à un instant écrit avec son fuseau. Il utilise une clé de signature de test fixe et des identifiants figés (`Str::createUuidsUsingSequence()`…).
2. Il émet un ticket, une facture, un avoir, un paiement et sa contre-passation, une clôture du jour et un relevé de caisse.
3. Il compare au caractère près, à des valeurs relevées sur le code d'avant le lot et écrites dans le test :
   - le contenu signé de chaque enregistrement du registre ;
   - le journal des événements ;
   - le texte de chaque PDF.

**Les valeurs qui changent à chaque exécution** :
- La signature et `signature_precedente` sont exclues de la comparaison : ECDSA tire un aléa à chaque signature. À leur place, le test vérifie que chaque `signature_precedente` est la signature de l'enregistrement précédent de la même chaîne (`null` pour le premier).
- Les identifiants auto-incrémentés du contenu (`operateur.id`, `document.id`) s'écrivent dans les valeurs attendues par une marque. Le test remplace cette marque par l'identifiant de l'objet qu'il a créé : sur PostgreSQL, une séquence ne recule pas quand une transaction de test est annulée.
- On ne supprime jamais une clé.

**Ensuite** :
- Ce test ne change plus dans les cinq lots.
- Le vérificateur d'intégrité (`php artisan caisse:verifier`) reste sans anomalie.

### 6.5 CI verte : couverture du code touché et mutation

**Ce que la CI contrôle** :
- Elle refuse toute ligne exécutable de `app`, ajoutée ou modifiée, qu'aucun test n'exécute (étape « Nouveau code couvert »).
- Elle calcule, par partie, le score de tous les mutants des fichiers sensibles que l'envoi ajoute ou modifie, fichiers entiers. Elle compare ce score au seuil de la partie : 99,5 % pour les calculs, 99,9 % pour les droits, arrondis au dixième inférieur.

Une vérification ajoutée, ou une ligne retouchée, a donc un test qui l'exécute. Dans un fichier des calculs ou des droits, ce test la déclenche et vérifie son effet. Une garde jugée impossible s'écrit autant que possible dans une aide de lecture commune et typée, testée une fois pour chaque refus, plutôt que copiée dans chaque fichier.

1. **Les survivants connus d'abord.** Avant de toucher un fichier des calculs ou des droits, TY1 fait disparaître, dans un commit à part, les survivants connus de ces deux parties : voir le README, « Mutants équivalents connus » ; au 09/11, `CalculateurLigne::repartirLEcart()` et les deux de `ControleDesDroits::dansLEspaceDeLaSociete()`.
   - Il le fait par un test qui appelle directement ce code, ou par une simplification qui ne change pas le comportement.
   - Il le prouve par Infection sur ces seuls fichiers.
   - Pourquoi : sinon, un envoi qui change `ControleDesDroits` et peu d'autres fichiers des droits passerait sous 99,9 % sans aucune régression.
2. **Avant le dernier commit de chaque lot**, et avant celui de sa correction si elle change des lignes des calculs ou des droits, l'agent lance dans le dépôt lui-même, l'une après l'autre :
   - la couverture comme la CI : `env -u AI_AGENT -u CLAUDECODE -u CLAUDE_CODE LARAVEL_PARALLEL_TESTING=1 vendor/bin/paratest --processes=4 --testsuite=Unit,Feature --cache-directory=storage/framework/cache/phpunit --coverage-clover=storage/qualite/clover.xml --coverage-xml=storage/qualite/infection/coverage-xml --log-junit=storage/qualite/infection/junit.xml` ;
   - `qualite.php couverture`, puis `qualite.php nouveau-code storage/qualite/clover.xml origin/<branche>` ;
   - sur le même arbre et avec cette couverture, Infection sur les seules lignes changées : `php tests/Qualite/qualite.php configuration-sensibles storage/qualite/infection-sensibles.json`, puis `env -u AI_AGENT -u CLAUDECODE -u CLAUDE_CODE LARAVEL_PARALLEL_TESTING=1 vendor/bin/infection --configuration=storage/qualite/infection-sensibles.json --threads=4 --only-covering-test-cases --git-diff-lines --git-diff-base=<base> --coverage="$PWD/storage/qualite/infection" --skip-initial-tests --ignore-msi-with-no-mutations`. La base est `origin/<branche>` pour le lot, et le dernier commit du lot pour sa correction. Ensuite : `qualite.php mutation storage/qualite/infection.json`.

   La liste « mutants qu'aucun test n'arrête » doit être vide. Les mutants non conclus sont nommés dans le compte rendu. La durée suit le nombre de mutants des lignes changées : quelques minutes pour TY3 et PS1 (surtout des attributs, `final` et des constantes), environ 1 h à 1 h 30 au plus pour TY1, TY2 et PS2, plus 16 à 21 min de couverture.
3. **La passe sur les fichiers entiers est celle de la CI.** Le lot suivant lit son résultat avant son dernier commit (règles impératives). Si la tâche « Couverture et mutation » est rouge ou annulée, il corrige d'abord, dans un commit à part. Puis il refait en local la passe sur les fichiers entiers de l'envoi en cause, avec `--git-diff-filter=AM`.

**Toujours** :
- Aucun seuil de couverture ni de score de mutation ne baisse.
- Aucune requête N+1 nouvelle : `preventLazyLoading` reste actif dans les tests, et `PlafondsDeRequetesTest` reste vert.
- L'isolation des sociétés et des magasins ne change pas : une requête réécrite (par exemple `find()` remplacé par `whereKey()->first()`) garde exactement ses portées et ses verrous.

### 6.6 Ressources de la machine

- Une seule commande lourde à la fois : suite, couverture, Infection, Larastan ou Psalm.
- Avant une couverture ou une mutation, `df -h /` doit montrer au moins 3 Go libres. Ensuite, effacer `storage/qualite/infection` et toute copie de travail.
- Larastan se lance avec `--memory-limit=3G`, et Psalm avec `php8.4 -d memory_limit=6G`, jamais pendant la suite.

### 6.7 Montants et nombres à virgule flottante

Les montants en nombres à virgule flottante (`calculs/sans-virgule-flottante.md`, § 2) sont retirés par le lot CALC, qui suit PS2. Ces cinq lots :
- n'en changent pas la représentation ;
- n'ajoutent ni `float` ni conversion `(float)` ;
- là où une erreur ne se corrige qu'en vérifiant une valeur décimale, la vérifient sans la convertir autrement qu'aujourd'hui, et listent ces endroits dans le message de commit, pour CALC.

### 6.8 Compte rendu de chaque lot

Il donne :
- le nombre d'erreurs corrigées par niveau ou palier ;
- les défauts réels trouvés ;
- les règles, options et exceptions, avec leurs raisons et leurs chiffres ;
- les fichiers touchés ;
- les suites (nombre de tests, codes de sortie) ;
- la couverture et la mutation des parties touchées ;
- la durée de chaque outil, et de chaque tâche de la CI sur l'envoi précédent ;
- « À faire sur le PC de l'éditeur » : au moins `composer install` quand un paquet de développement s'ajoute, et `composer install --working-dir=tools/psalm` si `tools/psalm` change.

## 7. Méthode et points des relecteurs

On note `B=origin/claude/saas-invoice-editor-architecture-q6eizh` et `P='app routes bootstrap config database tests/Qualite'`. Un motif de `grep` qui contient `$` s'écrit entre guillemets simples ; un motif qui contient une apostrophe s'écrit entre guillemets doubles, et sans `$` (§ 3.4).

1. **Les tests existants ne changent pas.**
   - Lister les commits : `git log --format='%h %s' $B..HEAD`.
   - Un commit de typage ne retire ni ne change aucune ligne de test : `git show --format= <commit> -- tests | grep -E '^-[^-]'` ne rend rien.
   - Il peut ajouter des fichiers ou des méthodes : `git show --name-status --format= <commit> -- tests` les montre.
   - Seuls le commit d'un défaut réel et celui de `qualite.php` y échappent.
2. **Interdits ajoutés** : `git diff $B..HEAD -U0 -- $P | grep -E '^\+[^+]' | grep -nE '@(phpstan|psalm)-(ignore|suppress|assert|var)|@psalm-ignore-(nullable|falsable)-return|@var\b|ignoreErrors|baseline|\bassert\(|\((int|string|float|bool|array)\)|\b(intval|strval|floatval|boolval|settype)\(|\bmixed\b'`. Chaque résultat suit une vérification juste au-dessus, ou relève d'une exception écrite.
3. **Valeurs par défaut ajoutées** : même commande sur `app`, avec le motif `"\?\?\s*(0|0\.0|null|false|true|''|\"\"|\[\])\s*[;,)]|\?:|\?->"`.
4. **Conditions réécrites** : même commande, avec le motif `"!==?\s*(''|\"\"|0|\[\])|array_filter\(|in_array\(|array_search\(|empty\("`.
5. **Gardes retirées** : `git diff $B..HEAD -U0 -- app | grep -E '^-[^-]' | grep -nE 'lockForUpdate|sharedLock|withoutGlobalScope|whereKey|find(OrFail)?\(|authorize|Gate::|->can\(|abort\(|throw new|magasin_id|societe_id'`. Chaque ligne retirée a son équivalent dans le même fichier.
6. **Contrôles sur une saisie** : chercher le motif `'throw new|is_(int|string|bool|array|numeric|float)\('` dans `app/Filament` et `app/Http`. Comparer à ce que le navigateur envoie vraiment (§ 6.2, e).
7. **Caisse** : `git diff --stat` sur les fichiers de la partie `caisse_securisee` de `tests/Qualite/parties.php`. Puis le test d'empreinte, inchangé depuis TY1, et `php artisan caisse:verifier`.
8. **Outils relancés par le relecteur** :
   - Larastan ;
   - Psalm (types et flux, témoin) ;
   - `php tests/Qualite/qualite.php seuils <(git show $B:tests/Qualite/seuils.json)` ;
   - `tests/Browser` en entier.

   Les actions ne se rejouent à la main que si un défaut réel corrigé change leur résultat.
9. **Configurations** : `phpstan dump-parameters --json`, les témoins du § 4.3 et `psalm.xml` disent exactement ce que ce cahier demande pour ce lot. `seuils.json` refuse toute baisse.

## 8. Critères de fin

**Pour chaque lot** :
- Larastan rend 0 au niveau et avec les règles du lot. `dump-parameters --json` et les témoins du § 4.3 montrent ce que le § 4.1 demande à ce stade. À la fin de TY3 : `level` 10, les ensembles de règles prouvés par les témoins, chaque `strictRules` à `true` sauf `dynamicCallOnStaticMethod`, les options à leur valeur stricte, `ignoreErrors` vide.
- L'analyse des flux et le témoin de Psalm rendent 0 (`qualite.php flux` et `flux-temoin`).
- À partir de PS1, la passe « types et flux » ne contient aucun signalement de sévérité « error » au niveau du lot (`qualite.php types`) : `errorLevel` 2 dans PS1, 1 dans PS2.
- `php tests/Qualite/qualite.php seuils <(git show origin/<branche>:tests/Qualite/seuils.json)` rend 0, et `OutilDeQualiteTest` est vert.
- `php artisan migrate:fresh`, puis la suite PostgreSQL complète, tests en navigateur compris, rendent 0.
- La couverture, `nouveau-code` et la mutation des lignes changées, comme au § 6.5, rendent 0.
- `composer audit` (dépôt et `tools/psalm`) et `npm audit` rendent 0, et pint est passé.
- Le test d'empreinte de la caisse et des PDF n'a pas changé depuis TY1.

**En plus, à la fin de TY2** : `qualite.php seuils` refuse tout `@var` en ligne, et n'en trouve aucun.

**En plus, à la fin de PS2** :
- `errorLevel="1"` ;
- `<unsuppressibleIssues>` est posé, s'il n'existe aucune exception du § 5.3 ;
- la phrase de `CLAUDE.md` est complète.

## 9. Après ces lots

- **Les lots suivants** (CALC, FI, AUD, NAV-2 à NAV-5, ML, DV, EC2, S9-L, S9-A, 21) écrivent du code qui passe les deux outils au plus strict. Avant chaque commit, Larastan, Psalm (types et flux, témoin) et `php tests/Qualite/qualite.php seuils <(git show origin/<branche>:tests/Qualite/seuils.json)` rendent 0. Sans `@phpstan-ignore`, `@psalm-suppress`, `assert()`, `@var` en ligne, conversion forcée, type élargi ni exception de configuration nouvelle. Le complément de chaque lot dans `serie8-lots.js` le rappelle.
- **Le lot CALC** déclare sa règle qui interdit les nombres à virgule flottante dans la section `rules` de `phpstan.neon`, avec son fichier témoin, et enregistre son identifiant dans `seuils.json` (§ 4.3, point 3). Là où TY a vérifié une valeur décimale (voir les messages de commit de TY1 à TY3), il remplace cette vérification par la lecture en entier ou en `BigDecimal`. Il refuse les mêmes valeurs fausses avec le même message.
- **Une mise à jour** de PHPStan, de Larastan ou de Psalm qui ajoute des contrôles se prend avec ses erreurs corrigées (D53).
