# Lot T3 : chaque magasin à l'heure locale de son territoire

**Cahier des charges. Il prime sur le texte du lot T3 de `serie8-lots.js`. La réponse Q1 de l'éditeur, citée ci-dessous, prime sur lui.**

> **Réponse de l'éditeur du 25/10/2026 (Q1 de `t2/reponses-t2.md`, qui fait foi) :**
> « Q1, magasins d'outre-mer : chaque magasin suit l'heure locale de son territoire (Guadeloupe, Martinique, Guyane, La Réunion, Mayotte, et tout autre territoire que PILIQI accepte) : journée de caisse et clôture à minuit local, date imprimée sur ses documents, année de ses numéros, échéances et toute logique de calendrier du magasin. Ce n'est pas fait dans T2 mais dans un lot à part, T3, juste après T2, dont le cahier des charges est `t3/decision-heure-locale.md`. T2 garde l'heure de Paris pour tous les magasins, mais ne crée rien que T3 devrait défaire : un seul endroit connaît le fuseau (la classe Calendrier), pour que T3 n'ait qu'à lui donner le fuseau du magasin. »

> **Place du lot.** T3 vient juste après T2 et sa relecture. Il vient avant TA (types d'activité) et EC (espace des cabinets). Ordre de la série : FA1, FA2, FA3, T2, T3, TA, EC, L1, A7, 21.
>
> **Le code aura changé.** Ce cahier a été écrit sur la copie figée de HEAD 6b5baba, avant FA3 et avant T2. FA3 verrouille les envois et les tâches de fond (dont `MarquerFacturesEnRetard` et `RelancerFacturesImpayees`). T2 passe tout en UTC et crée la classe `Calendrier`. Les numéros de ligne, les nombres et les listes de fichiers sont donc **indicatifs**. La première tâche du lot est de refaire les relevés (§ 1.4) sur son propre HEAD. Un site nouveau suit les mêmes règles. Un constat disparu se dit dans le compte rendu.

Sources (dossier de travail `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/`) :

- la carte du lot T3 (atelier du 25/10/2026 : sept sondes jetables sur 6b5baba, relevé des sites), résumée au § 1.3 et en annexe A ;
- `t2/decision-horodatage.md`, surtout son § 3.3 (la classe `Calendrier`), son § 3.10 (ce que T2 laisse à T3) et son § 11 (règles des lots suivants) ;
- `t2/reponses-t2.md`.

Avant d'écrire du code, le lot lit : CLAUDE.md, `t2/reponses-t2.md`, `t2/decision-horodatage.md` en entier, le `git log` de T2 (ce que T2 a réellement livré), `.ai/rules/index.md` et les règles qui couvrent les fichiers touchés, le skill `testing-best-practices`.

---

## 0. En bref

- **Un territoire par magasin.** Une énumération `Territoire` : métropole, Corse, Guadeloupe, Martinique, Guyane, La Réunion, Mayotte. Elle est rangée dans une colonne `magasins.territoire`. Elle donne à la fois le territoire de TVA du magasin, son département d'outre-mer et son calendrier.
- **Source :** la commune de l'établissement au répertoire Sirene (code officiel géographique). À défaut, son code postal. Sans l'un ni l'autre, l'établissement est refusé.
- **Fixé une fois, jamais changé.** Le territoire se fixe au premier contrôle Sirene concluant. Ensuite il ne change plus ; la base le garantit. Un déménagement vers un autre territoire passe par un nouveau magasin. Deux points attendent l'éditeur (§ 13) : le déménagement entre la Guadeloupe et la Martinique, que le code accepte aujourd'hui (question A), et la correction d'un territoire mal lu au répertoire (question C).
- **Sans territoire,** un magasin ne vend pas (déjà le cas) et, si l'éditeur retient l'option recommandée de la question B, ne numérote aucun document (nouveau : facture d'achat, avoir fournisseur, inventaire d'ouverture).
- **Le calendrier d'un magasin :** `Calendrier::duTerritoire()`, seconde fabrique de `Calendrier`, et `Magasin::calendrier()`. Chaque site qui calcule pour un magasin prend ce calendrier au lieu de `Calendrier::deParis()`. Il garde sa méthode, ses bornes et son test.
- **Reste à l'heure de Paris :** la société, ses courriers et ses e-mails, le journal d'audit, les procédures de l'éditeur, les tâches techniques. **Reste en UTC :** le stockage et l'horodatage signé.
- **Affichage :** le panneau d'un magasin affiche son heure, sans mention. Une page qui mêle plusieurs calendriers prend Paris pour référence et nomme toute autre heure : « 16/11/2026 20:00 (heure de la Martinique) ». Un jour s'affiche tel quel, sans fuseau, par la macro `jour()` que T2 pose (jamais `->date()`, qui donnerait la veille aux Antilles).
- **Planificateur :** la clôture automatique et les caisses personnelles, le passage en retard, l'expiration des devis et les relances passent toutes les heures. Chaque passage ne traite, pour chaque magasin, que ce que son calendrier rend dû. Les relances partent entre 09:00 et 11:59, heure locale.
- **Exercice :** la date de fin d'exercice est un jour civil commun à tous les magasins de la société. Chacun le clôt à son minuit local.
- **Contenu signé :** octets inchangés en métropole et en Corse, sauf la version du module. Outre-mer, les dates portent −04:00 ou +04:00 et les jours sont locaux. `ModuleFiscal::VERSION` passe à la version mineure suivante.
- **Corrigé au passage :** l'établissement en diffusion partielle classé en métropole (20 % au lieu de 8,5 %), le passage silencieux de la Guadeloupe à La Réunion, l'établissement à l'étranger classé en métropole, le département du vendeur lu à son code postal pour une « Livraison vers un DOM », le NIC corrigé pendant une panne qui contournait le territoire fixé.
- **Trois questions à l'éditeur** (§ 13, texte exact et option recommandée), à trancher avant le commit 1 : A, déménagement entre la Guadeloupe et la Martinique ; B, documents numérotés avant la confirmation de l'établissement ; C, correction d'un territoire mal lu au répertoire. Les choix contestables sont au § 12. Les constats écartés des relectures sont au § 14.

---

## 1. Constat

### 1.1 Ce que T2 aura posé

Résumé de `t2/decision-horodatage.md`. T3 ne refait rien de T2 et n'en retire rien. La frontière exacte est au § 1.1 bis.

- Les instants s'enregistrent, se comparent et se calculent en UTC (cast `Instant`). Un jour est une chaîne « AAAA-MM-JJ » (cast `JourCivil`).
- `App\Domain\Calendrier\Calendrier` est la seule classe qui connaît un fuseau. Un objet porte son fuseau dans `private readonly string $fuseau`. Son constructeur est privé.
- Seule fabrique de T2 : `Calendrier::deParis()`. Elle seule lit la constante privée `FUSEAU_DE_PARIS`.
- Membres : `fuseau()`, `aujourdhui()`, `jourDe()`, `anneeDe()`, `debutDuJour()`, `bornesDuJour()`, `decaler()`, `decalerEnMoisSansDebordement()`, `afficher()`, `afficherDansUnJournal()`, `iso8601()`, `heureMurale()`, et les statiques sur les jours.
- Filament : un seul `configureUsing` règle `TextColumn` et `TextEntry` sur `Calendrier::deParis()->fuseau()`. Un jour s'affiche par la macro `jour()` (`Calendrier::afficherJour()`), sans fuseau ; un instant réduit à son jour, par `->dateTime('d/m/Y')`. Aucun `->date(` (règle statique de T2).
- Planificateur : `schedule_timezone` vaut le fuseau de Paris.
- Tests : `aParis()` et `enUtc()`, garde de l'horloge, règle statique (`ReglesDuCalendrierTest`), `PlanificateurTest`, `ContenuSigneDeReferenceTest`, `NuitDeParisTest`.
- T2 § 3.10 promet : une seconde fabrique suffira ; la table des fuseaux sera dans `Calendrier` ; chaque site remplace `deParis()` par le calendrier de son magasin ; le seul `configureUsing` reçoit le fuseau du magasin ; seules les lignes des tâches du calendrier des magasins changent dans `PlanificateurTest` ; T3 complète la proposition de CLAUDE.md sur l'heure des magasins ; les règles 4, 5, 8 et 12 du § 11 de T2 disent « le calendrier du site ».

### 1.1 bis Frontière avec T2

Ce que T3 change à ce que T2 a posé, et pourquoi. Rien d'autre de T2 ne change.

| Élément de T2 | Ce que T3 en fait | Pourquoi |
|---|---|---|
| `Calendrier` | ajoute `duTerritoire()`, `libelleDeLHeure()`, `afficherHorsDuMagasin()`, `heureDe()` ; rien d'autre | seconde fabrique promise (T2 § 3.10, point 1) |
| Sites qui appellent `Calendrier::deParis()` pour un magasin | prennent `$magasin->calendrier()` | Q1 ; T2 § 3.10, point 2 |
| `configureUsing` de Filament | une fermeture qui lit le calendrier d'affichage | T2 § 3.10, point 3 |
| `PlanificateurTest` | quatre lignes attendues changent, ces quatre tâches quittent la liste des tâches quotidiennes à Paris, un test s'ajoute | tâches horaires (§ 3.9) ; T2 § 3.10, point 4 |
| Libellés « (heure de Paris) » du journal des événements | `libelleDeLHeure()` ; inchangés pour la métropole et la Corse | T2 § 3.10, point 5 |
| Proposition de CLAUDE.md sur l'heure des magasins | retire « mise en place par le lot T3, et jusque-là… », précise la règle ; README réécrit | T2 § 3.10, point 6 ; § 8 |
| Règles 4, 5, 8 et 12 du § 11 de T2 (« le calendrier du site ») | pour un magasin, son calendrier | T2 § 3.10, point 7 ; § 11, règle 1 |
| Règle statique | ajoute des noms de fuseaux, `deParis()` interdit dans le code d'un magasin, `calendrierDAffichage()`, `aLHeureDe()` ; n'en retire rien ; les noms de l'UTC restent permis | § 3.15 ; T2 § 11, règle 13 |
| `ModuleFiscal::VERSION` (inchangé par T2) | version mineure suivante | le contenu signé d'outre-mer change (§ 3.12) |
| Affichage d'un jour par `jour()` | rien ; T3 ajoute le test en Martinique | T2 § 3.5 |

### 1.2 Pourquoi le territoire de TVA ne suffit pas

Une première version de T2 § 3.10 suggérait de bâtir la fabrique sur `Magasin::territoireTva()` ; T2 renvoie désormais ici. La carte montre que ce serait faux :

- `TerritoireTva::AntillesReunion` réunit la Guadeloupe et la Martinique (UTC−4) avec La Réunion (UTC+4) ;
- `TerritoireTva` se recalcule à chaque appel depuis le pays et le code postal (`Magasin.php:533-536`, `TerritoireTva.php:39-50`) ;
- les contrôles de `ModifierEtablissementAction` (l. 148-150) et de `ChangerSiretAction` (l. 125-127) comparent des `TerritoireTva`, jamais des fuseaux.

### 1.3 Sondes de la carte (SQLite, sur 6b5baba)

| Sonde | Constat | Code en cause |
|---|---|---|
| 1 | Adresse 97110 → 97400 acceptée par `ModifierEtablissementAction` : le magasin passe de la Guadeloupe à La Réunion. | `ModifierEtablissementAction.php:148` |
| 2 | Changement de SIRET vers un établissement en 97400 depuis un magasin en 97110 : accepté. | `ChangerSiretAction.php:125` |
| 3 | Magasin créé pendant une panne de l'INSEE : `[code postal null, metropole, 20 %, non vérifié]`. Après la reprise : `[97200, antilles_reunion, 8,5 %, vérifié]`. Entre-temps, il peut valider des achats et un inventaire d'ouverture, numérotés par année. | `CreerEtablissementAction.php:127-135`, `ReverificationSirene.php:145-150`, `RouvrirMagasinAction.php:172-177`, aucune garde dans `app/Domain/Achats` ni `app/Domain/Stock/Actions` |
| 4 | Magasin de démonstration : pays FR, sans code postal, métropole, 20 %, même pour une société d'outre-mer. | `OuvrirLaDemonstrationAction.php:80-87`, `Emetteur.php:126-145` |
| 6 | Établissement en diffusion partielle : sans code postal, classé en métropole, vérifié, il vend à 20 %. Saisir 97200 est refusé (`MESSAGE_TERRITOIRE_CHANGE`). C'est déjà un défaut de TVA. | `FauxRepertoireSirene.php:113`, `ControleSirene.php:270-276` |
| 7 | Fuseau changé après la signature : le vérificateur signale `date_emission` sur chaque facture, et « numéroté dans l'année 2027 mais émis le 31/12/2026 à 21:30:00 ». | `VerificateurIntegrite.php:710-722`, `:1316-1333`, `ContenuFiscal.php:169` |

Défaut latent, relevé en écrivant ce cahier : un établissement à l'étranger d'un SIREN français n'a ni code postal ni commune française. `ControleSirene::estDansUnTerritoireGere()` le classe en métropole (« un code postal non diffusé compte en métropole »).

Seuls quatre fuseaux sont atteignables aujourd'hui : Paris, Guadeloupe, Martinique, La Réunion. La Guyane, Mayotte et les collectivités d'outre-mer sont refusées (`MOTIF_TERRITOIRE_NON_GERE`).

Fuseaux vérifiés sous PHP 8.3.6 (tzdata du système) : America/Guadeloupe, America/Martinique, Indian/Reunion, America/Cayenne et Indian/Mayotte n'ont aucune transition d'heure entre 2020 et 2035. Leurs décalages sont des heures entières.

### 1.4 Relevés à refaire au début du lot

Sur le HEAD du lot, avant toute modification :

1. Lister chaque appel de `Calendrier::deParis()` dans `app/`, `resources/views/`, `database/factories/` et `database/seeders/`. Classer chacun : il calcule pour un magasin (→ calendrier du magasin) ou pour la société, l'éditeur ou la technique (→ reste `deParis()`). L'annexe A donne le classement sur 6b5baba. Le compte rendu donne le nombre de chaque classe.
2. Lister chaque usage de `territoireTva()`, `TerritoireTva::depuisAdresse()` et `ControleSirene::estDansUnTerritoireGere()`, et toute lecture du pays ou du code postal du magasin, ou de son émetteur, qui en déduit un territoire ou un département (sur 6b5baba : `TerritoireFiscal::departementDom($emetteur->pays, $emetteur->codePostal)`, `ControleNatureOperation.php:185`).
3. Écrire les tests du § 6.1 (les sondes devenues tests de régression). Les lancer sur le HEAD avant la correction : ils doivent échouer. La sortie est citée dans le message du commit qui les corrige.
4. Relire `MarquerFacturesEnRetard`, `RelancerFacturesImpayees`, `EnvoyerDocumentJob` et `ExpirerLesDevisAction` tels que FA3 les a laissés. Leur ordre des verrous ne change pas.

---

## 2. Approche retenue et pourquoi

### 2.1 Principe

1. **Un territoire stocké** sur le magasin, source unique de son taux de TVA par défaut et de son calendrier.
2. **Lu au répertoire Sirene**, par la commune de l'établissement, jamais saisi à la main.
3. **Fixé une fois.** Il ne change plus. La base refuse toute modification.
4. **Un calendrier par magasin**, obtenu par `Calendrier::duTerritoire($territoire)`, que `Magasin::calendrier()` appelle. Seul `Calendrier` écrit des noms de fuseaux.
5. **Chaque site garde sa méthode.** Seul change l'objet `Calendrier` sur lequel il l'appelle.
6. **La société et l'éditeur restent à l'heure de Paris.** Une page qui mêle plusieurs calendriers nomme toute heure qui n'est pas celle de Paris.
7. **Des tâches horaires** atteignent le minuit de chaque territoire.

### 2.2 Pourquoi un territoire stocké

- **Plutôt qu'un fuseau déduit de l'adresse à chaque lecture :** une adresse se modifie, et un code postal peut manquer (diffusion partielle, panne). Le fuseau d'un document signé ne doit jamais changer (sonde 7).
- **Plutôt qu'une colonne de fuseau IANA :** T2 § 11, règle 13, interdit d'écrire un nom de fuseau hors de `Calendrier`. Une colonne de fuseau séparée du territoire de TVA pourrait aussi les contredire : un magasin à l'heure de la Martinique mais à 20 %.
- **Plutôt que `TerritoireTva` comme clé :** il mêle des fuseaux différents (§ 1.2).
- **Une seule source pour la TVA, le département et l'heure :** `Magasin::territoireTva()` devient `$this->territoire?->tva()`, et le département du vendeur d'une « Livraison vers un DOM » devient `$magasin->territoire?->departement()`. Cela corrige la sonde 6 sans règle de plus : sans cela, un magasin de la Martinique en diffusion partielle, sans code postal, accepterait sans TVA une livraison dans son propre département.
- **Par la commune plutôt que le code postal :** le code commune dit le département sans ambiguïté. 977 est Saint-Barthélemy et 978 Saint-Martin, alors que leurs codes postaux commencent par 971. Il est aussi diffusé en diffusion partielle, d'après la documentation de l'INSEE (l'adresse est masquée à l'intérieur de la commune) ; c'est à confirmer sur l'API réelle (§ 3.2).

### 2.3 Pourquoi des tâches horaires

- Tous les décalages des territoires sont des heures entières. Une tâche horaire à la minute 0 passe donc à chaque minuit local.
- Un passage manqué est rattrapé au suivant : le jour visé se calcule, il ne se déduit pas de l'heure du passage (T2 § 3.3).
- Une tâche par fuseau (`->timezone()` sur chaque tâche) écrirait des noms de fuseaux dans `bootstrap/app.php`. Elle demanderait une tâche de plus à chaque territoire accepté. Elle est écartée.
- Garder une tâche quotidienne à minuit à Paris est écarté. Avec le jour local, la journée des Antilles se clôturerait 19 à 20 heures en retard. Avec le jour de Paris, elle se clôturerait à 18 h ou 19 h, heure locale, et la caisse refuserait ensuite toute vente.

### 2.4 Ce que le lot ne fait pas

- Il n'accepte ni la Guyane, ni Mayotte, ni les collectivités d'outre-mer. Leurs règles de TVA et de documents ne sont pas prêtes. Leurs fuseaux sont seulement dans la table (§ 3.5).
- Il ne touche ni au stockage en UTC, ni aux casts, ni aux connexions de T2.
- Il ne met aucun fuseau ni aucun territoire dans l'en-tête signé.
- Il ne laisse personne choisir le territoire à la main.
- Il ne crée pas les clôtures mensuelles et de l'exercice (étape 3). Il en écrit seulement la règle (§ 3.10).
- Il ne change pas l'heure de la société, de ses courriers ni de l'éditeur.
- Aucune nouvelle dépendance. Aucun changement de la CI.

---

## 3. Règles

### 3.1 Le territoire d'un magasin

- Énumération `App\Domain\Tenancy\Enums\Territoire`, à valeurs texte : `metropole`, `corse`, `guadeloupe`, `martinique`, `guyane`, `reunion`, `mayotte`.
- Membres :
  - `tva(): TerritoireTva` : Métropole → `Metropole` ; Corse → `Corse` ; Guadeloupe, Martinique, La Réunion → `AntillesReunion` ; Guyane, Mayotte → `GuyaneMayotte` ;
  - `estPrisEnCharge(): bool` : faux pour la Guyane et Mayotte (repris de `TerritoireTva::estPrisEnCharge()`) ;
  - `lieu(): string` : « en métropole », « en Corse », « en Guadeloupe », « en Martinique », « en Guyane », « à La Réunion », « à Mayotte » ;
  - `departement(): ?string` : « 971 », « 972 », « 973 », « 974 », « 976 », ou null pour la métropole et la Corse ;
  - `depuisLeRepertoire(?string $codeCommune, ?string $codePostal): ?self` et `depuisCodePostal(?string $pays, ?string $codePostal): ?self` (§ 3.2).
- Colonne `magasins.territoire`, texte, nulle seulement pour un magasin réel dont l'établissement n'a jamais été confirmé (§ 3.4). Cast vers l'énumération.
- Les collectivités d'outre-mer (Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon, Pacifique) n'ont pas de cas : elles sont hors du territoire de TVA français et restent refusées.
- `Magasin::territoireTva()` rend `$this->territoire?->tva()`. `Magasin::tauxTvaParDefaut()` garde son repli sur la métropole.
- Le département du magasin, pour le refus « la livraison reste dans le département d'outre-mer du magasin » (`ControleNatureOperation.php:185`), est `$magasin->territoire?->departement()`, jamais `TerritoireFiscal::departementDom()` sur le code postal du magasin ou de son émetteur. Le département de la destination reste lu à l'adresse de livraison.
- `TerritoireTva` garde ses taux (`tauxNormal()`, `tauxEnVigueur()`, `aideSiTauxInhabituel()`, `lieu()`). `TerritoireTva::depuisAdresse()` et `TerritoireTva::estPrisEnCharge()` disparaissent, reprises par `Territoire`.
- La fiche d'un magasin dans l'espace de la société affiche son territoire (« Martinique »), ou « à fixer : établissement pas encore confirmé ».

### 3.2 Comment il se détermine

- `EtablissementSirene` reçoit `public ?string $codeCommune`, juste après `codePostal`.
- `InseeSirene::lireEtablissement()` lit `adresseEtablissement.codeCommuneEtablissement` par `texte()` (« [ND] » devient null).
- `FauxRepertoireSirene` modélise la commune : par défaut, un établissement est à Paris (75001, commune 75056) ; `deplacer(string $siret, string $codePostal, string $ville, string $codeCommune)` le situe ailleurs ; en diffusion partielle, le code postal est absent et la commune présente ; `situerHorsDeFrance(string $siret)` lui retire commune et code postal, comme un établissement à l'étranger. Aujourd'hui, `deplacer()` n'accepte pas de code postal nul (`FauxRepertoireSirene.php:175`) et un établissement hors diffusion partielle a toujours un code postal (repli « 75001 », l. 113) : sans cette méthode, le test d'un établissement qu'aucune commune ne situe ne pourrait pas s'écrire.
- `Territoire::depuisLeRepertoire($codeCommune, $codePostal)` :
  1. avec un code commune : `2A…` ou `2B…` → Corse ; `971…` → Guadeloupe ; `972…` → Martinique ; `973…` → Guyane ; `974…` → La Réunion ; `976…` → Mayotte ; `975…`, `977…`, `978…` et `98…` → aucun (collectivité d'outre-mer) ; tout autre code → métropole ;
  2. sans code commune : `depuisCodePostal('FR', $codePostal)`, qui reprend exactement les règles de `TerritoireTva::depuisAdresse()` (codes 97133, 97150 et 975 → aucun ; 971, 972, 973, 974, 976 → le département ; 20 → Corse ; sinon métropole) ;
  3. sans l'un ni l'autre : inconnu.
- Le code commune prime sur le code postal quand ils se contredisent.
- `ControleSirene::territoireDe(EtablissementSirene)` remplace `estDansUnTerritoireGere()`. Il rend le territoire, ou le motif du refus :
  - `MOTIF_TERRITOIRE_NON_GERE` (texte inchangé) : Guyane, Mayotte, collectivité d'outre-mer ;
  - `MOTIF_TERRITOIRE_INCONNU` (nouveau) : « Le répertoire Sirene de l'INSEE ne situe cet établissement dans aucune commune française : son taux de TVA et son heure ne peuvent pas être fixés. »
- Ce refus vaut partout où le refus d'un territoire non géré vaut aujourd'hui : création (`CreerEtablissementAction`), changement de SIRET (`ChangerSiretAction`), réouverture (`RouvrirMagasinAction`), NIC corrigé (`ModifierEtablissementAction`), reprise (`ReverificationSirene`) et liste proposée à la création (`CreerMagasin`).
- **À confirmer sur l'API réelle :** que `codeCommuneEtablissement` est diffusé pour un établissement en diffusion partielle. Le lot écrit un test `Http::fake` avec une réponse de ce format (§ 6.2). Il ne fait aucune vraie requête. Le compte rendu le dit dans « laisses ». Si l'INSEE masque aussi la commune, ces établissements reçoivent `MOTIF_TERRITOIRE_INCONNU`, et l'éditeur en décide.

### 3.3 Quand il se fixe, et pourquoi il ne change plus

**Fixation.** Le territoire se fixe une seule fois, au premier contrôle Sirene concluant qui donne l'établissement du magasin :

- à la création (`CreerEtablissementAction`), avec l'adresse du répertoire ;
- à la reprise d'un contrôle resté impossible (`ReverificationSirene`) ;
- à la réouverture d'un magasin jamais confirmé (`RouvrirMagasinAction`) ;
- au NIC corrigé avant la validation (`ModifierEtablissementAction`) ;
- au changement de SIRET d'un magasin jamais confirmé (`ChangerSiretAction`) ;
- à l'ouverture du magasin de démonstration (§ 3.11).

**Immuabilité.** Une fois fixé, il ne change plus jamais :

- Un nouvel établissement d'un autre territoire (NIC corrigé, changement de SIRET) est refusé. Le contrôle se fait sous le verrou du registre, sur la ligne du magasin relue. Message `MESSAGE_TERRITOIRE_CHANGE`, réécrit dans les deux actions : « Cet établissement est dans un autre territoire que le magasin : son taux de TVA, son heure et ses numéros en dépendent. Un déménagement vers un autre territoire passe par un nouveau magasin. »
- **Entre la Guadeloupe et la Martinique : selon la question A (§ 13).** Le code de 6b5baba accepte ce déménagement : il compare des `TerritoireTva` (`ChangerSiretAction.php:125`), et les deux îles sont dans `AntillesReunion`. CLAUDE.md dit seulement : « un déménagement qui change le territoire de TVA passe par un nouveau magasin ». T3 ne tranche pas. Option A (recommandée) : refusé comme les autres. Option B : permis par un changement de SIRET contrôlé au répertoire ; seul ce changement-là passe le modèle et le déclencheur (§ 5), et il s'inscrit au JET 270 ; une adresse saisie ne change jamais le territoire. Les deux fuseaux sont à −04:00 sans aucune transition de 1981 à 2040 (vérifié sous PHP 8.3.6) : aucune date signée ne changerait.
- Une adresse saisie est refusée quand son code postal donne un autre territoire, ou aucun (collectivité d'outre-mer, pays étranger), y compris, avec l'option B de la question A, entre la Guadeloupe et la Martinique. Une adresse sans code postal passe. Tant que le territoire n'est pas fixé, une adresse saisie ne le fixe pas. Message `ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE`, réécrit : « Cette adresse n'est pas dans le territoire du magasin : son taux de TVA, son heure et ses numéros en dépendent. Elle ne peut pas être enregistrée. »
- **Reprise d'un SIRET jamais confirmé.** Un NIC corrigé pendant une panne de l'INSEE est accepté en attente : `ModifierEtablissementAction` (l. 101-105) ne refuse qu'un contrôle négatif et remet `etablissement_verifie_le` à null (l. 139-145). À la reprise, `ReverificationSirene` (l. 145-150) confirme l'établissement et reprend l'adresse du répertoire, sans rien comparer. Désormais, quand le magasin a déjà un territoire, la reprise compare le territoire de l'établissement au territoire fixé. S'il diffère, le contrôle s'inscrit comme négatif, avec le motif `ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE` ; le magasin reste « à vérifier », donc sans vente ; son adresse ne change pas ; la page de la vérification le montre, et la société corrige le NIC (c'est toujours avant la validation, seule période où un NIC se corrige). La même règle vaut pour toute reprise d'un SIRET jamais confirmé sous un territoire fixé.
- **Revérification d'un SIRET déjà confirmé.** Une revérification qui lit un autre territoire pour le même SIRET (correction du répertoire) alerte le propriétaire et l'éditeur, comme un écart d'adresse. Elle ne change rien. Une réouverture fait de même. La suite de l'alerte dépend de la question C (§ 13).
- Le modèle refuse de changer un territoire déjà renseigné (`Magasin::booted()`, événement `updating`, `LogicException`).
- La base le refuse aussi : déclencheur `DeclencheursDeProtection::colonneFigee('magasins', 'territoire')`, sous PostgreSQL et SQLite (§ 5). Il laisse passer une valeur posée sur un territoire nul. Il refuse de changer ou d'effacer un territoire renseigné, même par `DB::table()`.

**Pourquoi figer dès la fixation, sans attendre le premier document :**

- le vérificateur recalcule les dates signées avec le calendrier du moment (sonde 7) : un territoire qui change d'heure rend faux tout ce qui a été signé (ce n'est pas le cas entre la Guadeloupe et la Martinique, d'où la question A) ;
- une règle unique est plus simple à garder qu'une règle « tant qu'aucun document » ;
- aujourd'hui déjà, une adresse ou un établissement qui change le territoire de TVA est refusé, même avant le premier document ; T3 ne retire aucune autre liberté. Entre la Guadeloupe et la Martinique, que le code accepte aujourd'hui, l'éditeur décide (question A).

**Journal.** `territoire` s'ajoute à `Magasin::parametresDeConformite()` :

- la fixation sur un magasin qui existe déjà (de nul à une valeur) s'inscrit au journal des événements (JET 270), avec son ancienne et sa nouvelle valeur ;
- elle se fait donc sous le verrou du registre du magasin, premier verrou de sa transaction, puis la ligne du magasin relue en `FOR UPDATE` (règle de `JournaliseSesParametresDeConformite`) ; `ReverificationSirene` prend désormais ce verrou quand il peut fixer un territoire ;
- la création ne journalise rien (insertion), comme aujourd'hui pour le SIRET ;
- le journal d'audit reçoit `territoire` dans les valeurs de `MagasinCree`, `MagasinModifie`, `SiretChange` et `MagasinRouvert` (la réouverture d'un magasin jamais confirmé le fixe). `ActionAuditee::champsAudites()` doit donc le retenir pour ces quatre actions : aujourd'hui, il ne le retient pas (`ActionAuditee.php:161-162`, `:171`), et `JournalAudit` refuse tout champ non retenu (`JournalAudit.php:254`, `InvalidArgumentException`). La reprise par `ReverificationSirene` n'écrit rien au journal d'audit aujourd'hui ; sa fixation s'inscrit au JET 270 seulement.

**Cohérence.** Un magasin réel dont l'établissement est vérifié a toujours un territoire. Le magasin de démonstration aussi. Le modèle le vérifie à l'enregistrement, et un test le prouve (§ 6.2).

### 3.4 Un magasin sans territoire

- C'est un magasin réel dont l'établissement n'a jamais été confirmé (INSEE injoignable à la création ou à la réouverture).
- Il ne vend pas : règle actuelle (`Magasin::refusDeVente()`).
- **Il ne numérote aucun document — selon la question B (§ 13), option recommandée.** C'est une règle nouvelle : CLAUDE.md n'exige le contrôle Sirene qu'« avant toute vente », et un magasin non confirmé valide aujourd'hui achats et inventaire d'ouverture. Avec l'option B, ces documents se numérotent à l'heure de Paris en attendant ; la garde, son message et la partie « ne numérote rien » du test de la sonde 3 tombent. Avec l'option A, `DocumentNumberingService::suivant()` refuse toute série annuelle (`$periodKey` non vide) d'un magasin sans territoire. Le refus vaut pour les huit séries ; en pratique il touche les factures d'achat, les avoirs fournisseur et les inventaires d'ouverture, que la vente ne bloquait pas. Les références des produits (série sans année) ne sont pas concernées.
- Message (option A) `Magasin::REFUS_TERRITOIRE_INCONNU` : « L'établissement de ce magasin n'est pas encore confirmé par le répertoire Sirene de l'INSEE : PILIQI ne connaît pas encore son territoire, donc ni son heure ni l'année de ses numéros. Aucun document numéroté avant sa confirmation. » Les boutons de validation l'affichent.
- Ses écrans et ses brouillons prennent le calendrier de la métropole, par `Magasin::calendrier()` (§ 3.5). Rien de ce qu'il date ainsi n'est numéroté, signé avec une date locale ni déclaré.
- `ReverifierSirene` le reprend chaque heure, comme aujourd'hui.

### 3.5 Le calendrier d'un magasin

**Dans `Calendrier` (ajouts seulement ; le constructeur, la propriété et les membres de T2 ne changent pas) :**

| Membre | Rôle |
|---|---|
| `duTerritoire(Territoire): self` (statique) | seconde fabrique ; table privée : Métropole et Corse → `self::deParis()` ; Guadeloupe → `America/Guadeloupe` ; Martinique → `America/Martinique` ; Guyane → `America/Cayenne` ; La Réunion → `Indian/Reunion` ; Mayotte → `Indian/Mayotte` |
| `libelleDeLHeure(): string` | « heure de Paris », « heure de la Guadeloupe », « heure de la Martinique », « heure de la Guyane », « heure de La Réunion », « heure de Mayotte » ; table privée par fuseau |
| `afficherHorsDuMagasin(DateTimeInterface, string): string` | `afficher()`, suivi de « (heure de la Martinique) » quand le fuseau n'est pas celui de Paris ; identique à `afficher()` à Paris |
| `heureDe(DateTimeInterface): int` | l'heure murale d'un instant, de 0 à 23 (fenêtre des relances, § 3.9) |

- `FUSEAU_DE_PARIS` reste lu par `deParis()` seule. La table de `duTerritoire()` renvoie à `self::deParis()` pour la métropole et la Corse.
- Les autres noms de fuseaux ne sont écrits que dans cette table.
- La Guyane et Mayotte ont leur entrée. Elles ne sont pas encore acceptées, mais la table est exhaustive (un `match` sur l'énumération) et `CalendrierTest` la vérifie. Le jour où l'éditeur les accepte, leur calendrier est prêt.
- `afficherHorsDuMagasin()` compare son fuseau à `self::deParis()->fuseau()`, jamais à la constante.

**Ailleurs :**

- `Magasin::calendrier(): Calendrier` rend `Calendrier::duTerritoire($this->territoire ?? Territoire::Metropole)`. Le repli ne sert qu'aux écrans et aux brouillons d'un magasin sans territoire (§ 3.4).
- `TenantContext::calendrierDAffichage(): Calendrier` rend le calendrier du magasin actif, sinon celui de Paris. Seul le `configureUsing` de Filament l'appelle (règle statique).
- `RegistreFiscal::verrouiller($magasin)` donne à `InstantFiscal` le calendrier du magasin : `jour()` et `annee()` le lisent.
- Le code du domaine prend le calendrier **explicitement** : celui du magasin pour lequel il calcule, ou, pour un document, celui de son propre magasin (`magasin_id`). Jamais celui du contexte par défaut.
- **Seule exception : les fabriques** `ClotureCaisseFactory` et `DevisFactory`. Elles n'ont pas de `magasin_id` (`ClotureCaisseFactory.php:19`, `DevisFactory.php`) : `BelongsToMagasin` le pose à la création, depuis `TenantContext` (`BelongsToMagasin.php:39-40`). Elles prennent donc le calendrier du magasin actif de `TenantContext`, celui qui recevra la fiche. La règle statique liste cette exception avec sa raison.
- Une liste ne relit pas le magasin à chaque ligne. Elle prend le calendrier du magasin courant (panneau d'un magasin) ou charge une fois le territoire de ses magasins (lecture de la société).

### 3.6 Ce qui suit l'heure locale du magasin

Chaque site garde sa méthode, ses arguments et ses bornes. Il prend `$magasin->calendrier()` au lieu de `Calendrier::deParis()`. Liste complète des sites en annexe A.1.

- **Journée de caisse et minuit :** `jourDe($instant)`. Refus d'une vente après la clôture du jour : jusqu'au minuit local.
- **Clôture automatique et rattrapage :** jour visé `ajouterJours($calendrier->aujourdhui(), -1)`, calculé pour chaque magasin (§ 3.9).
- **Caisses personnelles fermées d'office :** `jusqu_au` est la dernière seconde de la journée locale, `$calendrier->bornesDuJour($jour)[1]->subSecond()`. En Martinique, le 16/11/2026 finit à 2026-11-17T03:59:59Z.
- **Ticket Z :** sa journée (chaîne signée) et l'année de son numéro.
- **Date d'émission, date imprimée, année des numéros :** les huit séries (tickets, factures, avoirs, devis, factures d'achat, avoirs fournisseur, inventaires d'ouverture, clôtures). Une seule lecture de l'horloge par geste (T2).
- **Échéance, retard, jours depuis la dernière relance, « déjà relancée aujourd'hui » :** jours du magasin.
- **Validité d'un devis et expiration.**
- **Date d'un règlement :** le jour saisi est refusé s'il est après `aujourdhui()` du magasin ; à la caisse et à la contre-passation, `jourDe()` de la transaction. Les `DatePicker` proposent le jour du magasin.
- **VIES « le jour même » et délai des preuves d'exonération** (`jourDuMoisSuivant()`).
- **E-reporting par journée :** la journée de la clôture.
- **Aperçus** (semaine, mois, année), dernier achat d'un client, relevés de caisse.
- **Périodes d'activité :** `PeriodesDActivite::jours(Magasin $magasin)` reçoit déjà le magasin (l. 66), et ses deux appelants le passent (`JourneesDeCaisse.php:51`, `:73`). Elle prend `$magasin->calendrier()`, sans paramètre de plus.
- **Journal des événements :** affichage, filtre par jour, export (§ 3.8).
- **PDF et écrans du magasin.**
- **Vérificateur d'intégrité :** l'année vérifiée et les affichages prennent le calendrier du magasin vérifié.
- **Méthodes de modèle sans magasin courant** (`Facture::estEchue()`, `Facture::joursDepuisLaDerniereRelance()`, `Facture::totauxAEncaisser()`, `Devis::estExpire()`, `ClotureCaisse::refusDUneVente()`) : le calendrier du magasin du document. Elles servent aussi dans les tâches, les envois en file d'attente et les lectures de la société.
- **Fabriques :** `ClotureCaisseFactory` et `DevisFactory` prennent le jour du calendrier du magasin actif de `TenantContext`, qui recevra la fiche (exception du § 3.5).

### 3.7 Ce qui reste à l'heure de Paris ou en UTC

**En UTC (T2, inchangé) :** le stockage ; l'horodatage signé « …Z » ; les comparaisons du vérificateur sur les textes UTC ; les limiteurs, les sessions et les limites d'e-mails par heure ; les journaux techniques ; le message de l'horloge qui recule.

**À l'heure de Paris : la société.** Elle n'a pas de fuseau propre (choix C6).

- Inscription, courrier du code et son libellé « (heure de Paris) », pages de la vérification, tableau de bord de la société (validée le, clôture prévue).
- Délais des courriers et de l'inscription, transmission de la propriété, nouvelle adresse de connexion, « Mot de passe oublié ».
- Limite anti-abus de comptes créés par jour (`CreationDesComptes`).
- Heures propres à un compte de la société (propriétaire, administrateur) : dernière connexion, accès redonné.
- Journal d'audit de la société : heure de Paris, avec la mention de l'heure répétée (T2, Q3).

**À l'heure de Paris : l'éditeur et la technique.**

- Commandes `editeur:*` et futurs écrans de l'éditeur.
- `VerifierIntegriteFiscale` à 03:00 : la vérification est incrémentale ; une journée des Antilles encore ouverte sera vue la nuit suivante.
- Purges, effacements, `FermerDemonstrationsInactives` (30 jours, à quelques heures près), date de recherche Sirene.

**Pourquoi.** Ces délais relèvent de la société ou de l'éditeur, pas d'un magasin. Une durée en heures ne dépend d'aucun fuseau. Une durée en jours ne diffère qu'au passage d'un changement d'heure, que les départements d'outre-mer n'ont pas.

### 3.8 Affichage

**Panneau d'un magasin.** Tout s'affiche dans son calendrier, sans mention.

- Le seul `configureUsing` de T2 devient `->timezone(fn (): string => app(TenantContext::class)->calendrierDAffichage()->fuseau())`. Filament v3 accepte une fermeture (`vendor/filament/tables/src/Columns/Concerns/CanFormatState.php:226` sur 6b5baba, et son équivalent dans `infolists`).
- Les vues et les PDF d'un magasin passent par `$magasin->calendrier()->afficher()`.
- Un jour (cast `JourCivil` : échéance, validité, date de règlement, journée de clôture, date d'une facture d'achat, date d'un justificatif) s'affiche par la macro `jour()` de T2, sans fuseau. `->date()` le relirait comme minuit UTC puis le convertirait au fuseau de la colonne (`vendor/filament/tables/src/Columns/Concerns/CanFormatState.php:65-67`, `vendor/filament/infolists/src/Components/Concerns/CanFormatState.php:66-67`) : sondé avec le vendor de la copie, « 2026-12-16 » s'affiche « 15/12/2026 » en Martinique, « 16/12/2026 » à Paris et à La Réunion. T2 a déjà remplacé les 13 sites (T2 § 3.5, § 4.10) et interdit `->date(` ; T3 ajoute le test en Martinique (§ 6.2).

**Pages qui mêlent plusieurs calendriers.** Ce sont l'espace de la société, le futur espace du cabinet (EC), les rapports du vérificateur, les sorties des commandes de l'éditeur, les e-mails et notifications à la société. Règle :

- **Paris est la référence.** Une heure sans mention y est à l'heure de Paris.
- Une heure propre à la société ou à un compte de la société s'affiche à l'heure de Paris, sans mention.
- Une heure qui se rapporte à un magasin (une opération du magasin, ou un compte de membre de ce magasin) s'affiche dans le calendrier de ce magasin, par `afficherHorsDuMagasin()`. Hors de Paris, elle porte donc le nom de son heure : « 16/11/2026 20:00 (heure de la Martinique) ».
- Ces colonnes passent par `formatStateUsing()` ou par la vue. Aucun `->timezone(` de plus (T2 § 3.5).
- Un jour s'affiche tel quel, sans mention : un jour n'a pas de fuseau.

**Pages précises de la société :**

- **« Les caisses »** (`LesCaisses`, `CaissesDeLaSociete`, `les-caisses.blade.php`) : par défaut, chaque magasin montre **sa propre journée en cours**. Un jour choisi s'applique à chaque magasin dans son calendrier (`bornesDuJour()` du magasin). Les caisses encore ouvertes sont celles du jour du magasin. Les heures des relevés passent par `afficherHorsDuMagasin()`. L'en-tête de chaque magasin dit sa journée.
- **Factures à encaisser** (`FacturesAEncaisserDeLaSociete`) : le retard se compte au jour de chaque magasin. Le territoire s'ajoute aux seuls champs lus de ses magasins.
- **Équipe** (`MembreResource`) : la dernière connexion et l'accès redonné d'un membre d'un magasin, dans le calendrier de ce magasin ; ceux d'un compte de la société, à Paris.

**Journal des événements** (par magasin) :

- Affiché et exporté dans le calendrier du magasin.
- Ses libellés nomment l'heure du magasin : la colonne et la fiche d'`EnregistrementFiscalResource` disent « (heure de la Martinique) », l'en-tête de l'export CSV « Horodatage (heure de la Martinique) », par `libelleDeLHeure()`. Pour la métropole et la Corse, le texte reste « (heure de Paris) » : aucun test de T2 ne change.
- La mention de l'heure répétée (T2, Q3) se déduit des transitions du fuseau. Elle n'apparaît donc jamais outre-mer.

**Connexion précédente** (`SuiviDesConnexions`) : celle d'un membre s'affiche dans le calendrier de son magasin ; celle d'un compte de la société, à Paris. Sans mention : la personne lit sa propre heure.

**E-mails et PDF.**

- Un PDF de magasin et un e-mail à un client prennent le calendrier du magasin, sans mention.
- Un e-mail ou une notification à la société qui cite l'heure d'un magasin passe par `afficherHorsDuMagasin()`.
- Les e-mails de la société restent à Paris.

### 3.9 Tâches planifiées

| Tâche | T2 | T3 | Règle de T3 |
|---|---|---|---|
| `ClotureAutomatiqueCaisse` | chaque jour à 00:00 | chaque heure, minute 0 | pour chaque magasin : jour visé = la veille de son jour ; borne `jusqu_au` = dernière seconde de ce jour local ; journées manquées rattrapées dans l'ordre |
| `MarquerFacturesEnRetard` | 00:15 | chaque heure, minute 15 | échéance < jour du magasin |
| `ExpirerDevis` | 00:30 | chaque heure, minute 30 | validité < jour du magasin |
| `RelancerFacturesImpayees` | 09:00 | chaque heure, minute 0 | un magasin n'est traité que si son heure locale est de 09:00 à 11:59 ; « une relance par jour et par facture » compte son jour |

Les autres tâches ne changent pas. Elles restent quotidiennes à l'heure de Paris, ou horaires.

Précisions :

- **Clôture :** la commande ne calcule plus « hier » une seule fois pour tous les magasins (`ClotureAutomatiqueCaisse.php:52-53`). Chaque magasin a son jour visé et sa borne.
- **Passage à vide : déjà sans verrou, à garder.** Sur 6b5baba, un passage où rien n'est dû ne verrouille aucun registre : `CloturerLesJourneesAction::journeesEnAttente()` (l. 84-91) lit `ClotureCaisse::derniereJourneeCloturee()`, « lue sans verrou » (`ClotureCaisse.php:114-125`) ; le verrou n'est pris que pour chaque journée due, dans `CloturerCaisseAction::parLeSysteme()` (l. 138) ; `vendeursAvecCaisseOuverte()` ne verrouille rien, et `fermerDOffice()` ne verrouille que le compte et ses factures (`FermerSaCaisseAction.php:133-135`), jamais le registre, et seulement pour une caisse ouverte. T3 garde ces lectures et leur source. Il n'ajoute aucune lecture de `tetes_registre_fiscal.derniere_journee_cloturee`, qui ferait une seconde source de la même donnée. Une vente n'attend donc pas chaque heure le verrou de la clôture. Le test du § 6.2 est une garde : il passe avant T3, et une mutation (un verrou du registre ajouté dans `journeesEnAttente()`) prouve qu'il échoue.
- **Message de sortie :** « N clôture(s) automatique(s) effectuée(s). » Il ne cite plus un jour unique.
- **Relances :** le premier passage d'un magasin de métropole reste à 09:00, heure de Paris. Aux Antilles, 09:00 locales sont 13:00 UTC ; à La Réunion, 05:00 UTC. Si le passage de 09:00 manque, ceux de 10:00 et 11:00 rattrapent. Après 11:59, plus rien ce jour-là. Les relances restent « en matinée plutôt qu'en pleine nuit » (commentaire de `bootstrap/app.php`).
- **FA3 :** ses verrous et ses relectures dans `MarquerFacturesEnRetard` et `RelancerFacturesImpayees` ne changent pas.
- Aucune tâche quotidienne entre 02:00 et 02:59 (T2). Les tâches horaires restent permises.
- Le planificateur suit toujours l'heure de Paris (`schedule_timezone`). Une tâche horaire passe aussi aux deux passages de l'heure répétée et après le saut de mars ; chaque tâche est sans effet quand rien n'est dû.
- **Coût :** quatre passages par heure sur tous les magasins. Le passage à vide ne verrouille rien (garde ci-dessus). La campagne de tests 1 bis mesure le coût en volume.

### 3.10 Mois de fin d'exercice et clôtures d'une société à plusieurs fuseaux

- Le mois de fin d'exercice reste un seul réglage de la société, verrouillé à la première vente de l'un de ses magasins. Ce verrou ne dépend d'aucun fuseau.
- **La date de fin d'exercice est un jour civil commun** à tous ses magasins (par exemple le 31/12/2026).
- **Chaque magasin le clôt à son minuit local.** Une opération appartient à la journée, au mois et à l'exercice de son jour local.
- Exemples :
  - une vente du 31/12/2026 à 21:00 en Guadeloupe (2027-01-01T01:00:00Z, déjà 02:00 le 1er janvier à Paris) appartient à l'exercice 2026, porte un numéro de 2026 et entre dans le ticket Z du 31/12/2026 ;
  - une vente du 01/01/2027 à 00:30 à La Réunion (2026-12-31T20:30:00Z, encore 21:30 le 31 décembre à Paris) appartient à l'exercice 2027.
- À un même instant, deux magasins d'une même société peuvent être dans deux journées, deux mois ou deux exercices différents. Entre La Réunion et les Antilles, l'écart est de 8 heures.
- **Aucune requête ne prend une plage de dates commune à des magasins de calendriers différents.** Les totaux d'une société (export comptable et état de TVA à l'étape 5, rapports à l'étape 6) se font par magasin, chacun dans son calendrier, puis s'additionnent.
- Procédure de l'éditeur (changement légal de la date de clôture) : la date fixée par le procès-verbal est un jour civil, que chaque magasin applique dans son calendrier. Une clôture d'exercice « dont la date est déjà passée » s'apprécie dans le calendrier de chaque magasin.
- T3 écrit la règle (CLAUDE.md, § 8) et donne leur calendrier à `JourneesDeCaisse` et à `PeriodesDActivite::jours()`. Les clôtures mensuelles et de l'exercice viennent avec l'étape 3.

### 3.11 Magasin de démonstration

- Son territoire est celui du siège de la société, lu sur `societes.siege_code_postal` par `Territoire::depuisCodePostal('FR', …)`, s'il est pris en charge. Sinon, la métropole : siège inconnu, en Guyane, à Mayotte ou dans une collectivité d'outre-mer.
- Il se fixe à la première ouverture et se garde à chaque réouverture.
- Il donne à la démonstration son taux par défaut et son heure. Le catalogue de démonstration de TA prend « le taux par défaut du territoire du magasin » : c'est celui-ci.
- L'émetteur fictif ne change pas : aucune adresse n'est imprimée.

### 3.12 Contenu signé et version du module fiscal

- **Métropole et Corse :** octets identiques, sauf `version_module`.
- **Outre-mer :**
  - les dates ISO portent le décalage du territoire, par `$magasin->calendrier()->iso8601()` : « 2026-11-16T20:00:00-04:00 » (Martinique), « 2027-01-01T00:30:00+04:00 » (La Réunion) ;
  - `consultee_le` d'une consultation VIES aussi ;
  - les jours sont locaux : journée d'une clôture, `recu_le` d'un règlement à la caisse, jour de la facture d'origine d'un avoir.
- Aucun fuseau ni territoire dans l'en-tête signé (`RegistreFiscal.php:554-559`) : cela changerait les octets de tous les magasins. La fixation du territoire s'inscrit au JET 270 (§ 3.3).
- Le vérificateur recalcule avec le calendrier du magasin vérifié. Le territoire étant figé (base et modèle), ce recalcul ne varie plus.
- **`ModuleFiscal::VERSION` passe à la version mineure suivante** (de 1.2 à 1.3 si le HEAD est encore à 1.2). Pourquoi :
  - le module change : la journée, l'année des numéros et les dates signées d'un magasin d'outre-mer ;
  - mineure, car rien ne change dans l'inaltérabilité, la sécurisation, la conservation ni l'archivage (choix C8).
- `ContenuSigneDeReferenceTest` : dans les jeux existants, seul `version_module` change. Le lot ajoute les jeux d'outre-mer (§ 6.2).

### 3.13 Isolation

- Le territoire est une colonne du magasin. Il ne se lit que pour le magasin courant, le magasin d'un document, ou les magasins d'une lecture déjà permise par CLAUDE.md (« Les caisses » de la société, factures à encaisser, liste et tableau de bord des magasins). Il s'ajoute aux seuls champs que lisent ces lectures.
- Aucune nouvelle lecture hors du magasin ou de la société courants.
- Les tâches horaires traitent chaque magasin dans son propre contexte (`runAs`), avec des requêtes filtrées par magasin, comme aujourd'hui.
- Une requête réécrite garde son filtre de magasin ou de société (CLAUDE.md, règle 2).

### 3.14 Tests et horloge

- `TestCase::aLHeureDe(Territoire $territoire, 'AAAA-MM-JJ HH:MM[:SS]')` rend l'instant par `Calendrier::duTerritoire($territoire)->heureMurale()`, au fuseau de l'application, comme `aParis()`.
- Une heure écrite en littéral n'apparaît qu'en argument d'`aParis()`, `enUtc()` ou `aLHeureDe()`.
- `MagasinFactory` :
  - par défaut, `territoire` vaut la métropole (code postal 75###, inchangé) ;
  - état `dansLeTerritoire(Territoire)`, avec une adresse cohérente : Corse 20000 Ajaccio, Guadeloupe 97110 Pointe-à-Pitre, Martinique 97200 Fort-de-France, La Réunion 97400 Saint-Denis.
- `FauxRepertoireSirene::deplacer($siret, $codePostal, $ville, $codeCommune)` et `FauxRepertoireSirene::situerHorsDeFrance($siret)` (§ 3.2).
- Un test qui dépend du jour d'un magasin d'outre-mer fige l'horloge par `aLHeureDe()` ou `enUtc()`.

### 3.15 Règle statique (`ReglesDuCalendrierTest`)

T3 complète la règle de T2 sans rien en retirer. Les noms de l'UTC (`UTC`, `Etc/UTC`) ne sont pas des calendriers : T2 les écrit pour la configuration, la session PostgreSQL et le contrôle de `DiagnostiquerCaisseSecurisee` (T2 § 4.5, § 11, règle 13). Ils restent permis partout.

| Interdit | Portée | Remplacement |
|---|---|---|
| un nom de fuseau en clair (`Europe/…`, `America/…`, `Indian/…`, `Pacific/…`, `Atlantic/…`, `Africa/…`, `Asia/…`, `Australia/…`, `Antarctica/…`, `Etc/…`), sauf `Etc/UTC` | `app/`, vues, fabriques, seeders, `tests/`, hors `app/Domain/Calendrier/` | `Calendrier` ; `fuseau()` |
| dans `app/Domain/Calendrier/` : `Europe/Paris` ailleurs que dans `FUSEAU_DE_PARIS`, lu par `deParis()` seule ; un autre nom ailleurs que dans la table de `duTerritoire()` | `app/Domain/Calendrier/` | — |
| `Calendrier::deParis()` | code d'un magasin : `app/Domain/Facturation/`, `app/Domain/Achats/`, `app/Domain/Stock/`, `app/Domain/Catalogue/`, `app/Domain/CaisseSecurisee/`, `app/Domain/Contacts/` (consultations VIES, « le jour même »), `app/Domain/Tenancy/Services/PeriodesDActivite.php`, `app/Filament/App/`, vues `factures/`, `avoirs/`, `devis/`, `documents/`, `filament/app/`, et les commandes `ClotureAutomatiqueCaisse`, `MarquerFacturesEnRetard`, `ExpirerDevis`, `RelancerFacturesImpayees` | `$magasin->calendrier()` |
| `calendrierDAffichage(` | partout sauf `AppServiceProvider` | `$magasin->calendrier()` |
| une heure écrite en littéral hors d'`aParis(`, `enUtc(`, `aLHeureDe(` | `tests/` | ces trois aides |

Chaque exception est listée avec sa raison (fichier et motif). Exceptions relevées sur 6b5baba :

| Fichier | Ce qui est permis | Raison |
|---|---|---|
| `tests/Unit/Calendrier/CalendrierTest.php` | les noms des fuseaux de la table, dans `test_chaque_territoire_a_son_fuseau` | il vérifie la table de `duTerritoire()` |
| `app/Filament/App/Pages/Auth/ModifierProfil.php` (l. 142-148) | `Calendrier::deParis()` | échéance du lien de l'adresse de connexion du propriétaire : heure propre à un compte de la société (§ 3.7, C10) ; page commune aux deux panneaux |
| `app/Domain/CaisseSecurisee/Models/TraceEvenement.php` (l. 70) | `Calendrier::deParis()->decalerEnMoisSansDebordement()` | `expire_le` des traces techniques (1 an) : délai technique (§ 3.7 ; T2 § 3.4) |
| `database/factories/ClotureCaisseFactory.php`, `DevisFactory.php` | le calendrier du magasin actif de `TenantContext` | la fiche n'a pas encore de `magasin_id` (§ 3.5) |

Le lot ajoute toute autre exception trouvée sur son HEAD, avec sa raison. `VerifierIntegriteFiscale` (jour du mois qui étale la charge, à Paris) n'est pas dans la portée : ce n'est pas une exception.

---

## 4. Fichiers et classes à changer

Liste indicative relevée sur 6b5baba. Le lot la recalcule sur son HEAD (§ 1.4).

### 4.1 Nouveaux

- `app/Domain/Tenancy/Enums/Territoire.php` (§ 3.1). `app/Domain/Tenancy/Enums/` existe déjà : pas de nouveau dossier de base.
- Les tests du § 6.2.

### 4.2 Supprimés

- `TerritoireTva::depuisAdresse()` et `TerritoireTva::estPrisEnCharge()` (reprises par `Territoire`).
- `ControleSirene::estDansUnTerritoireGere()` (remplacée par `territoireDe()`).
- Les jeux de `TerritoireTvaTest` qui testaient ces deux méthodes passent dans `TerritoireTest` avec la même intention (§ 6.3).

### 4.3 Calendrier

`app/Domain/Calendrier/Calendrier.php` : `duTerritoire()`, sa table, `libelleDeLHeure()`, `afficherHorsDuMagasin()`, `heureDe()`. Rien d'autre ne change.

### 4.4 Sociétés et magasins

- `Magasin` : cast de `territoire` ; `calendrier()` ; `territoireTva()` ; `parametresDeConformite()` avec `territoire` ; garde de `booted()` (territoire figé, cohérence avec l'établissement vérifié) ; `REFUS_TERRITOIRE_INCONNU`.
- `ActionAuditee::champsAudites()` : `territoire` pour `MagasinCree`, `MagasinModifie`, `SiretChange` et `MagasinRouvert` (§ 3.3).
- `TenantContext` : `calendrierDAffichage()`.
- `ControleSirene` : `territoireDe()`, `MOTIF_TERRITOIRE_INCONNU`, écart de territoire à la revérification.
- `CreerEtablissementAction`, `ModifierEtablissementAction`, `ChangerSiretAction`, `RouvrirMagasinAction` : fixation, refus, messages, valeurs du journal d'audit.
- `ReverificationSirene` : fixation sous le verrou du registre ; à la reprise d'un SIRET jamais confirmé, territoire différent du territoire fixé → contrôle négatif, adresse inchangée (§ 3.3) ; écart signalé pour un SIRET déjà confirmé.
- `OuvrirLaDemonstrationAction` : territoire du siège.
- `SuiviDesConnexions` : calendrier du compte.
- `PeriodesDActivite::jours(Magasin $magasin)` : `$magasin->calendrier()`, sans paramètre de plus.

### 4.5 Sirene

`app/Integrations/Sirene/EtablissementSirene.php`, `InseeSirene.php`, `FauxRepertoireSirene.php` (`deplacer()` avec la commune, `situerHorsDeFrance()`, § 3.2).

### 4.6 Caisse sécurisée

- `ModuleFiscal::VERSION`.
- `InstantFiscal`, `RegistreFiscal::verrouiller()` : calendrier du magasin.
- `ContenuFiscal` : `iso8601()` et `jourDe()` du calendrier du magasin du document.
- `VerificateurIntegrite` : année et affichages du magasin vérifié (`afficherHorsDuMagasin()` dans ses messages).
- `ExporterJournalDesEvenementsAction` : calendrier du magasin, en-tête par `libelleDeLHeure()`.
- `DeclencheursDeProtection` : `colonneFigee()` (§ 5).

### 4.7 Facturation

- Actions : `EmettreFactureAction`, `EmettreAvoirAction`, `EmettreDevisAction`, `AccepterDevisAction`, `ExpirerLesDevisAction`, `CloturerCaisseAction`, `CloturerLesJourneesAction`, `FermerLesCaissesPersonnellesAction`, `FermerSaCaisseAction`, `EnregistrerPaiementAction`, `EncaisserEnPlusieursModesAction`, `ContrePasserPaiementAction`, `AjouterPreuveOperationAction`, `EnvoyerDocumentAction`.
- Services : `DocumentNumberingService` (garde du § 3.4, selon la question B), `JourneesDeCaisse`, `PanierDeCaisse`, `RecapitulatifDeCloture`, `VerificateurCaissesPersonnelles`, `ControleNatureOperation` (calendrier du magasin ; département du vendeur par `Territoire::departement()`, l. 185, § 3.1), `PreuvesRequises`, `EReportingDesParticuliers`, `CaissesDeLaSociete`, `FacturesAEncaisserDeLaSociete`.
- Modèles : `Facture`, `Devis`, `ClotureCaisse`, `EnregistrementFiscal` (filtre par jour).
- Hors de la facturation : le modèle `app/Domain/Contacts/Models/ConsultationVies.php` (`scopeDuJour()` et `reference()`, l. 75 et 90) ; `app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php` n'écrit qu'un instant (`consultee_le` = `now()`, l. 73) : rien à y changer.
- Job : `EnvoyerDocumentJob`.

### 4.8 Achats et stock

`ValiderFactureAchatAction`, `EmettreAvoirFournisseurAction`, `ValiderInventaireOuvertureAction` : année et date du calendrier du magasin ; refus sans territoire (par la numérotation).

### 4.9 Commandes planifiées et démarrage

- `bootstrap/app.php` : quatre horaires et leurs commentaires (§ 3.9).
- `ClotureAutomatiqueCaisse`, `MarquerFacturesEnRetard`, `ExpirerDevis`, `RelancerFacturesImpayees` : jour de chaque magasin, passage à vide gardé sans verrou (§ 3.9), fenêtre des relances.
- `app/Providers/AppServiceProvider.php` : fermeture du `configureUsing`.

### 4.10 Filament

- Panneau du magasin : `EnregistrementFiscalResource` (libellés), `ListEnregistrementsFiscaux`, `ConformiteApercu`, `ClotureCaisseResource`, `ListClotureCaisses`, `VentesEnAttenteApercu`, `ClotureCaissePersonnelleResource`, `ListClotureCaissePersonnelles`, `CaissesPersonnellesOuvertesApercu`, `EReportingResource`, `ViewEReporting`, `FactureResource`, `ViewFacture`, `PaiementResource`, `AvoirResource`, `DevisResource`, `FactureAchatResource`, `AvoirFournisseurResource`, `InventaireOuvertureResource`, `ClientResource`, `ServiceResource`, `PosteReconnuResource`, `FacturesApercu`, `EnvoieLesDocuments`, `SelecteurDeClients`, `SelecteurDeProduits`, `Caisse`.
- Espace de la société : `LesCaisses`, `MagasinResource` et `ModifierMagasin` (territoire affiché), `CreerMagasin` (liste proposée), `MembreResource`, `TableauDeBord` (heures de magasins, s'il en affiche).

### 4.11 Vues

`resources/views/factures/pdf.blade.php`, `avoirs/pdf.blade.php`, `devis/pdf.blade.php`, `documents/pdf/reglements.blade.php`, `filament/app/clients/consultations-vies.blade.php`, `filament/app/widgets/factures-a-encaisser-apercu.blade.php`, `filament/societe/pages/les-caisses.blade.php`.

### 4.12 Fabriques et aides des tests (le seeder ne sème rien : rien à y changer)

- `database/factories/MagasinFactory.php` (§ 3.14), `ClotureCaisseFactory.php`, `DevisFactory.php`.
- `tests/TestCase.php` : `aLHeureDe()`.

---

## 5. Migrations de création à modifier sur place

Aucune migration nouvelle. Puis `php artisan migrate:fresh` sur les deux moteurs.

**`database/migrations/0001_01_01_000000_create_magasins_table.php` :**

- colonne `territoire`, `string(20)`, nullable, après `pays`, avec ce commentaire :
  > Le territoire du magasin (voir Territoire) : métropole, Corse ou un département d'outre-mer. Il donne son taux de TVA par défaut et son calendrier (journée de caisse, date et année de ses documents, échéances). Fixé au premier contrôle Sirene concluant de son établissement, d'après sa commune, il ne change plus : la base refuse de le modifier (voir DeclencheursDeProtection::colonneFigee()). Nul seulement pour un magasin réel dont l'établissement n'a jamais été confirmé, qui ne vend pas et ne numérote rien.
- après la création de la table : `DeclencheursDeProtection::colonneFigee('magasins', 'territoire');` ;
- dans `down()` : le retrait de ce déclencheur, par `DeclencheursDeProtection::retirer('magasins')` s'il le couvre, sinon par la méthode qui le retire.

**`DeclencheursDeProtection::colonneFigee(string $table, string $colonne)`** (nouvelle méthode, sur le modèle des déclencheurs existants) :

- PostgreSQL : une fonction plpgsql et un déclencheur `BEFORE UPDATE OF <colonne>`. Il lève une exception quand `OLD.<colonne> IS NOT NULL AND NEW.<colonne> IS DISTINCT FROM OLD.<colonne>`.
- SQLite : un déclencheur `BEFORE UPDATE OF <colonne>` avec `WHEN OLD.<colonne> IS NOT NULL AND (NEW.<colonne> IS NULL OR NEW.<colonne> <> OLD.<colonne>)`, qui fait `RAISE(ABORT, …)`.
- Un message constant, en français.
- Tout autre pilote fait échouer la migration, comme les autres gardes.
- `RetourArriereDesMigrationsTest` passe toujours.
- Avec l'option B de la question A seulement, `colonneFigee()` reçoit en troisième argument les échanges permis (`[['guadeloupe', 'martinique'], ['martinique', 'guadeloupe']]`), que le déclencheur laisse passer ; avec l'option A, aucun. La garde du modèle suit la même liste, et le commentaire de la colonne le dit.

Aucune autre table ne change. `sequences_documents`, `tetes_registre_fiscal`, `periodes_activite` et `clotures_caisse` gardent leur schéma : leurs jours sont déjà des jours de leur calendrier.

---

## 6. Tests

Tous sur les deux moteurs, sauf mention. Noms indicatifs ; l'intention est exigée.

### 6.1 Sondes de la carte devenues tests de régression

Chaque test échoue sur le HEAD avant sa correction. Le lot le vérifie et cite la sortie dans le message du commit.

| Sonde | Test |
|---|---|
| 1 | `TerritoireDuMagasinTest::test_une_adresse_d_un_autre_territoire_est_refusee_meme_au_meme_taux` |
| 2 | `TerritoireDuMagasinTest::test_un_nouvel_etablissement_d_un_autre_territoire_est_refuse_meme_au_meme_taux` |
| 2 bis (relecture) | `TerritoireDuMagasinTest::test_un_nic_corrige_pendant_une_panne_vers_un_autre_territoire_ne_se_confirme_pas_a_la_reprise` |
| 6 bis (relecture) | `TerritoireDuMagasinTest::test_une_livraison_dans_le_departement_d_un_magasin_sans_code_postal_reste_taxee` |
| 3 | `TerritoireDuMagasinTest::test_un_magasin_cree_quand_l_insee_ne_repond_pas_ne_numerote_rien` |
| 4 | `MagasinDeDemonstrationTest::test_la_demonstration_prend_le_territoire_du_siege_et_le_garde` |
| 6 | `TerritoireDuMagasinTest::test_un_etablissement_en_diffusion_partielle_prend_le_territoire_de_sa_commune` |
| 7 | `TerritoireDuMagasinTest::test_la_base_et_le_modele_refusent_de_changer_un_territoire_fixe` |
| étranger | `TerritoireDuMagasinTest::test_un_etablissement_qu_aucune_commune_francaise_ne_situe_est_refuse` |

### 6.2 Tests nommés à écrire

**`tests/Unit/TerritoireTest.php`** (aucune base) :

- `test_le_territoire_se_lit_au_code_commune` : 75056 → métropole ; 2A004 et 2B033 → Corse ; 97105 → Guadeloupe ; 97209 → Martinique ; 97302 → Guyane ; 97411 → La Réunion ; 97611 → Mayotte ; 97701 (avec le code postal 97133), 97801 et 97502 → aucun ; 97209 sans code postal → Martinique.
- `test_le_territoire_se_deduit_du_pays_et_du_code_postal` (même nom que dans `TerritoireTvaTest`, sur `Territoire::depuisCodePostal()`, et le repli de `depuisLeRepertoire()` sans code commune) : jeux repris (Paris, Ajaccio, Fort-de-France, Pointe-à-Pitre, « 974 00 », Cayenne, Mamoudzou, 97133, 97150, 97500, un pays étranger).
- `test_sans_code_commune_ni_code_postal_le_territoire_est_inconnu`.
- `test_chaque_territoire_donne_son_territoire_de_tva_et_son_taux`.
- `test_seuls_la_guyane_et_mayotte_ne_sont_pas_encore_pris_en_charge` (même nom que dans `TerritoireTvaTest`).
- `test_le_departement_d_outre_mer_se_deduit_du_territoire` : 971, 972, 973, 974, 976 ; null pour la métropole et la Corse.

**`tests/Unit/Calendrier/CalendrierTest.php`** (ajouts ; les tests de T2 ne changent pas) :

- `test_chaque_territoire_a_son_fuseau` : les sept territoires et leur `fuseau()`.
- `test_le_jour_et_l_annee_des_antilles_changent_a_minuit_des_antilles` : Martinique, 2026-11-17T03:59:59Z → 2026-11-16, 04:00:00Z → 2026-11-17 ; Guadeloupe, 2027-01-01T03:59:59Z → 2026, 04:00:00Z → 2027.
- `test_le_jour_et_l_annee_de_la_reunion_changent_a_minuit_de_la_reunion` : 2026-12-31T19:59:59Z → 2026-12-31 et 2026 ; 20:00:00Z → 2027-01-01 et 2027.
- `test_un_jour_outre_mer_dure_toujours_24_heures` : bornes des 25/10/2026 et 28/03/2027 en Martinique et à La Réunion.
- `test_aucune_heure_n_est_repetee_ni_sautee_outre_mer` : `heureMurale('2026-10-25 02:30')` et `heureMurale('2027-03-28 02:30')` acceptées en Martinique ; `afficherDansUnJournal()` de 2026-10-25T06:30:00Z (02:30 en Martinique) sans mention.
- `test_l_iso8601_porte_le_decalage_du_territoire` : Martinique, 2026-11-17T00:00:00Z → « 2026-11-16T20:00:00-04:00 » ; La Réunion, 2026-12-31T20:30:00Z → « 2027-01-01T00:30:00+04:00 ».
- `test_le_libelle_de_l_heure_nomme_le_territoire`.
- `test_l_affichage_hors_du_magasin_nomme_l_heure_sauf_a_paris` : 2026-11-17T00:00:00Z au format « d/m/Y H:i » : « 17/11/2026 01:00 » à Paris ; « 16/11/2026 20:00 (heure de la Martinique) » en Martinique.
- `test_un_decalage_en_jours_outre_mer_garde_l_heure_locale` : Martinique, 20/10/2026 10:00 + 30 jours → 19/11/2026 10:00, exactement 720 heures.
- `test_l_heure_murale_d_un_instant_est_celle_du_calendrier` : 2026-11-17T13:00:00Z → 9 en Martinique, 14 à Paris, 17 à La Réunion.

**`tests/Feature/Societe/TerritoireDuMagasinTest.php`** :

- `test_la_creation_fixe_le_territoire_lu_a_la_commune_de_l_etablissement` : commune 97209 → Martinique, territoire de TVA `AntillesReunion`, 8,5 % proposé ; la fiche du magasin dans l'espace de la société affiche « Martinique ».
- `test_un_etablissement_en_diffusion_partielle_prend_le_territoire_de_sa_commune` (sonde 6) : sans code postal, commune 97209 → Martinique, 8,5 % ; saisir 97200 est accepté ; saisir 75001 est refusé.
- `test_un_etablissement_d_une_collectivite_d_outre_mer_est_refuse_par_sa_commune` : commune 97701 avec le code postal 97133 → `MOTIF_TERRITOIRE_NON_GERE`.
- `test_un_etablissement_qu_aucune_commune_francaise_ne_situe_est_refuse` : établissement placé par `situerHorsDeFrance()`, ni commune ni code postal → `MOTIF_TERRITOIRE_INCONNU`, à la création, au changement de SIRET et à la réouverture.
- `test_un_magasin_cree_quand_l_insee_ne_repond_pas_ne_numerote_rien` (sonde 3) : territoire nul ; la validation d'une facture d'achat et celle d'un inventaire d'ouverture sont refusées avec `REFUS_TERRITOIRE_INCONNU` ; aucun numéro n'est consommé ; la fiche affiche « à fixer ».
- `test_la_reprise_du_controle_fixe_le_territoire_et_l_inscrit_au_journal_des_evenements` (commit 1) : suite du précédent ; `ReverifierSirene` → Martinique ; JET 270 « territoire », de nul à « martinique » ; sous l'horloge figée à `enUtc('2026-12-16 15:00:00')` (même jour et même année à Paris et en Martinique), la facture d'achat se valide et reçoit un numéro de 2026. L'année au bord du 31 décembre vient au commit 2 (`HeureLocaleTest`).
- `test_une_reouverture_fixe_le_territoire_d_un_magasin_jamais_confirme`.
- `test_un_nouvel_etablissement_d_un_autre_territoire_est_refuse_meme_au_meme_taux` (sonde 2) : depuis la Guadeloupe, vers La Réunion, par un changement de SIRET et par un NIC corrigé avant la validation. Vers la Martinique : refusé avec l'option A de la question A ; avec l'option B, accepté par un changement de SIRET (JET 270 de « guadeloupe » à « martinique ») et refusé par un NIC corrigé, et le test change de nom (`…_sauf_entre_la_guadeloupe_et_la_martinique`).
- `test_un_nic_corrige_pendant_une_panne_vers_un_autre_territoire_ne_se_confirme_pas_a_la_reprise` : magasin confirmé en Guadeloupe, société non validée ; NIC corrigé pendant une panne vers un établissement de La Réunion : accepté en attente ; à la reprise, contrôle négatif avec `ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE`, `etablissement_verifie_le` reste nul, l'adresse et le territoire ne changent pas, la vente est refusée.
- `test_une_livraison_dans_le_departement_d_un_magasin_sans_code_postal_reste_taxee` : magasin de la Martinique en diffusion partielle, sans code postal ; une facture « Livraison vers un DOM » livrée en 97200 est refusée (« la livraison reste dans le département d'outre-mer du magasin ») ; livrée en 97110, elle est acceptée.
- `test_une_adresse_d_un_autre_territoire_est_refusee_meme_au_meme_taux` (sonde 1) : magasin en Guadeloupe ; 97400 et 97200 refusés (quelle que soit la réponse à la question A) ; 97110 accepté ; une adresse sans code postal acceptée.
- `test_la_base_et_le_modele_refusent_de_changer_un_territoire_fixe` (sonde 7) : `DB::table('magasins')->update()` qui change ou efface un territoire renseigné échoue sur les deux moteurs ; le modèle refuse aussi ; poser un territoire sur un territoire nul passe.
- `test_une_reverification_qui_lit_un_autre_territoire_alerte_sans_rien_changer` (SIRET déjà confirmé ; la suite dépend de la question C ; avec son option A, un test de plus : `test_l_editeur_corrige_un_territoire_mal_lu_avant_le_premier_document_numerote_et_jamais_apres`).
- `test_un_magasin_verifie_et_la_demonstration_ont_toujours_un_territoire`.

**`tests/Feature/Integrations/InseeSireneTest.php`** (tests existants étendus, aucun test nouveau) :

- `test_l_entrepreneur_individuel_et_la_diffusion_partielle_se_lisent` couvre déjà la diffusion partielle : sa réponse `Http::fake` porte le code commune 97209 avec le code postal « [ND] » ; attendu : `codeCommune` « 97209 », `codePostal` nul.
- `test_l_unite_et_l_etablissement_se_lisent_avec_la_cle_de_l_editeur` : le code commune d'un établissement ordinaire est lu ; « [ND] » donne null.

**`tests/Feature/Societe/MagasinDeDemonstrationTest.php`** (ajout) :

- `test_la_demonstration_prend_le_territoire_du_siege_et_le_garde` (sonde 4) : siège en 97200 → Martinique, 8,5 % proposé, un ticket vendu à `aLHeureDe(Territoire::Martinique, '2026-11-16 20:00')` est daté du 16/11/2026 ; fermée puis rouverte, elle reste en Martinique ; siège inconnu → métropole ; siège en 97133 → métropole.

**`tests/Feature/Calendrier/HeureLocaleTest.php`** :

- `test_un_magasin_de_la_martinique_vend_a_20_heures_au_jour_local_et_cloture_a_minuit_local` (l'horloge ne fait qu'avancer) :
  - à `aLHeureDe(Territoire::Martinique, '2026-11-16 18:30')` (2026-11-16T22:30:00Z), un premier ticket ;
  - à 2026-11-16T23:00:00Z (minuit à Paris, 19:00 en Martinique), la clôture automatique ne clôt rien en Martinique ; avec le calendrier de Paris, elle aurait clos le 16/11 avec ce ticket ;
  - à `aLHeureDe(Territoire::Martinique, '2026-11-16 20:00')` (2026-11-17T00:00:00Z, 01:00 le 17 à Paris), un deuxième ticket ; attendus : jour 2026-11-16 ; numéro de 2026 ; le PDF porte « Émise le 16/11/2026 » (il n'imprime pas l'heure, `factures/pdf.blade.php:39`) ; la fiche du ticket affiche « Émise le » 16/11/2026 ; la référence de ce ticket dans un avoir dit « du 16/11/2026 à 20 h 00 » (`AvoirResource.php:334`) ;
  - à 23:59:30, heure locale (2026-11-17T03:59:30Z), un troisième ticket du même jour ;
  - à 2026-11-17T04:00:00Z, la clôture automatique clôt le 16/11/2026 avec les trois tickets ; le ticket Z est de 2026 ; la caisse personnelle est fermée d'office avec `jusqu_au` = 2026-11-17T03:59:59Z, et la liste des relevés du magasin affiche « 16/11/2026 23:59 » dans la colonne « Jusqu'à » (`ClotureCaissePersonnelleResource.php:98`) ;
  - un ticket à 04:00:01Z est du 17/11/2026 ; `caisse:verifier` ne signale rien.
- `test_la_caisse_de_la_martinique_refuse_toute_vente_apres_sa_cloture_jusqu_a_minuit_local` : clôture manuelle à 21:00, heure locale ; vente refusée à 23:30, heure locale ; acceptée à 00:00:01, heure locale, et datée du lendemain.
- `test_un_magasin_de_la_reunion_numerote_sur_la_nouvelle_annee_des_minuit_local` (l'horloge ne fait qu'avancer : le registre refuse un instant antérieur à son dernier verrou, `RegistreFiscal.php:455-463`) :
  - ticket à 2026-12-31T19:59:30Z → 2026, daté du 31/12/2026 ;
  - le passage de 2026-12-31T20:00:00Z (minuit à La Réunion) clôt le 31/12/2026, ticket Z de 2026 ;
  - facture à 2026-12-31T20:30:00Z (00:30 à La Réunion, 21:30 à Paris) → 2027, datée du 01/01/2027, échéance au 31/01/2027 ;
  - au même instant, un magasin de Paris de la même société numérote encore en 2026 ;
  - `caisse:verifier` ne signale rien.
- `test_une_vente_du_31_decembre_a_21_heures_en_guadeloupe_reste_dans_l_annee_qui_finit` : à 2027-01-01T01:00:00Z, numéro de 2026, date du 31/12/2026 ; une facture d'achat validée au même instant reçoit aussi un numéro de 2026 ; à 2027-01-01T03:30:00Z (23:30 le 31 en Guadeloupe), une autre facture d'achat reçoit encore un numéro de 2026 ; le vérificateur ne signale rien.
- `test_une_societe_avec_un_magasin_a_paris_et_un_en_guadeloupe_suit_deux_calendriers` (l'horloge ne fait qu'avancer) :
  - à 2026-07-14T21:30:00Z, une vente dans chaque magasin : 23:30 le 14/07 à Paris, 17:30 le 14/07 en Guadeloupe ;
  - le passage de 2026-07-14T22:00:00Z (minuit à Paris) clôt le 14/07 à Paris seulement ;
  - à 2026-07-14T23:30:00Z, une vente dans chaque magasin : le ticket de Paris est du 15/07 (01:30), celui de la Guadeloupe du 14/07 (19:30) ;
  - le passage de 2026-07-15T04:00:00Z clôt le 14/07 en Guadeloupe, avec ses ventes de 17:30 et de 19:30 ;
  - chaque magasin numérote avec son code ; l'e-reporting de chaque magasin compte ses propres journées.
- `test_l_echeance_le_retard_et_les_relances_suivent_le_jour_local` :
  - facture en Martinique émise le 16/11/2026, délai de 30 jours → échéance au 16/12/2026 ;
  - à 2026-12-17T00:15:00Z (20:15 le 16, heure locale), `MarquerFacturesEnRetard` ne la touche pas ; à 04:15:00Z, elle passe en retard ;
  - relances (premier délai d'un jour) : rien à 2026-12-17T09:00:00Z (05:00 locales) ; une relance à 13:00:00Z (09:00 locales) ; rien de plus à 14:00:00Z ;
  - rattrapage : un autre magasin de la Martinique, dont les relances s'activent après le passage de 13:00:00Z, est relancé à 14:00:00Z (10:00 locales) ;
  - fin de la fenêtre : un troisième, dont les relances s'activent après 15:00:00Z, n'est pas relancé à 16:00:00Z (12:00 locales).
- `test_un_devis_expire_le_lendemain_de_sa_validite_au_jour_local` : validité au 31/12/2026 ; à 2026-12-31T20:30:00Z, `ExpirerDevis` expire celui de La Réunion et pas celui de la Guadeloupe ; à 2027-01-01T04:30:00Z, celui de la Guadeloupe expire.
- `test_un_reglement_recu_le_jour_local_est_accepte_et_le_lendemain_local_refuse` : à 2026-07-14T21:00:00Z, `recu_le` = 2026-07-15 est accepté à La Réunion (01:00 le 15) et refusé en Martinique (17:00 le 14) ; le `DatePicker` propose le jour du magasin et le borne à ce jour.
- `test_la_consultation_vies_et_le_delai_des_preuves_comptent_le_jour_local`.
- `test_le_journal_des_evenements_et_son_export_nomment_l_heure_du_territoire` : en Martinique, la colonne et l'en-tête de l'export disent « (heure de la Martinique) », la colonne lisible est à l'heure locale, la colonne signée reste en UTC ; à 2026-10-25T06:30:00Z, aucune mention de l'heure répétée ; pour un magasin de Paris, les libellés restent « (heure de Paris) ».
- `test_les_apercus_le_dernier_achat_et_les_releves_comptent_le_jour_local`.
- `test_la_connexion_precedente_s_affiche_a_l_heure_du_compte` : un vendeur de la Martinique la lit à l'heure locale ; le propriétaire, à Paris.
- `test_les_listes_d_un_magasin_ne_relisent_pas_son_territoire_a_chaque_ligne` : la liste des factures fait le même nombre de requêtes pour 1 et pour 20 factures.

**`tests/Feature/Calendrier/HeuresDeLaSocieteTest.php`** :

- `test_les_caisses_de_la_societe_montrent_chaque_magasin_a_son_jour_et_a_son_heure` : magasins à Paris et en Guadeloupe ; à 2026-07-15T02:00:00Z (04:00 le 15 à Paris, 22:00 le 14 en Guadeloupe), la page montre par défaut les relevés du 15/07 à Paris et du 14/07 en Guadeloupe ; les heures de la Guadeloupe portent « (heure de la Guadeloupe) », celles de Paris aucune mention ; le jour choisi 2026-07-14 montre le 14/07 de chaque magasin.
- `test_les_factures_a_encaisser_de_la_societe_comptent_le_retard_au_jour_de_chaque_magasin` : même échéance au 14/07/2026 dans les deux magasins ; à 2026-07-15T02:00:00Z, celle de Paris est en retard, pas celle de la Guadeloupe.
- `test_les_heures_de_la_societe_restent_a_l_heure_de_paris_et_celles_d_un_magasin_d_outre_mer_sont_nommees` : date de validation, dernière connexion d'un administrateur, courrier du code « (heure de Paris) » ; dernière connexion d'un vendeur de la Guadeloupe avec « (heure de la Guadeloupe) ».
- `test_le_verificateur_nomme_l_heure_d_un_magasin_d_outre_mer_dans_son_rapport` : une année de numéro faussée en base ; le message dit « émis le 31/12/2026 à 21:30:00 (heure de la Guadeloupe) ».

**`tests/Feature/Calendrier/IsolationDesCalendriersTest.php`** :

- `test_un_passage_horaire_ne_clot_que_les_magasins_dont_le_minuit_est_passe` : société A (Paris) et société B (Martinique) ; le passage de 2026-11-16T23:00:00Z clôt A seulement ; celui de 2026-11-17T04:00:00Z clôt B seulement ; registres, séries et clôtures restent séparés.
- `test_les_caisses_de_la_societe_ne_lisent_que_les_magasins_de_la_portee_quel_que_soit_leur_fuseau` : un administrateur limité au magasin de Paris ne voit ni le magasin de la Guadeloupe ni ceux d'une autre société.
- `test_le_calendrier_d_un_document_est_celui_de_son_magasin_et_jamais_celui_du_contexte` : `FacturesAEncaisserDeLaSociete` dans le contexte de la société ; `EnvoyerDocumentJob` d'un devis de la Martinique ; `estExpire()` lu dans le contexte d'un autre magasin de la même société.
- `test_un_passage_horaire_sans_journee_due_ne_verrouille_aucun_registre` (PostgreSQL seulement, sauté sous SQLite) : `DB::listen` ne voit aucun `FOR UPDATE` sur `tetes_registre_fiscal` pendant un passage où rien n'est dû. C'est une garde : il passe déjà sur le HEAD (§ 3.9) ; la mutation « verrou du registre ajouté dans `journeesEnAttente()` » le fait échouer.
- `test_le_verificateur_ne_signale_rien_pour_trois_magasins_de_trois_fuseaux` : une société avec un magasin à Paris, un en Martinique et un à La Réunion ; des ventes de part et d'autre du minuit de chacun et du nouvel an (du 2026-12-31T19:30:00Z au 2027-01-01T04:30:00Z, l'horloge ne faisant qu'avancer), les passages horaires de la clôture ; puis `caisse:verifier` rend 0 et ne signale aucune anomalie. Il tient lieu de la base « alimentée » de la preuve 10 (§ 7).

**`tests/Feature/Calendrier/PlanificateurTest.php`** (T2, mis à jour) :

- `test_chaque_tache_planifiee_a_son_horaire_attendu` : seules quatre lignes attendues changent : `ClotureAutomatiqueCaisse` `0 * * * *`, `MarquerFacturesEnRetard` `15 * * * *`, `ExpirerDevis` `30 * * * *`, `RelancerFacturesImpayees` `0 * * * *`.
- `test_les_taches_quotidiennes_et_mensuelles_partent_a_l_heure_de_paris_…` : sa liste perd ces quatre tâches ; le reste ne change pas.
- `test_chaque_minuit_local_tombe_sur_un_passage_horaire` (nouveau) : pour chaque territoire et pour les jours 2026-07-14, 2026-10-25, 2026-12-31 et 2027-03-28, `debutDuJour()` tombe à la minute 0 et à la seconde 0 en UTC, et la clôture automatique est due à cet instant. Un territoire futur au décalage non entier fera échouer ce test.

**`tests/Feature/Calendrier/ContenuSigneDeReferenceTest.php`** (T2, mis à jour) :

- les jeux existants : seul `version_module` change dans le JSON attendu ;
- `test_le_contenu_signe_d_un_magasin_d_outre_mer_porte_le_decalage_et_le_jour_de_son_territoire` (nouveau) : ticket en Martinique à 2026-11-17T00:00:00Z (« 2026-11-16T20:00:00-04:00 », `recu_le` « 2026-11-16 ») ; facture à La Réunion à 2026-12-31T20:30:00Z (« 2027-01-01T00:30:00+04:00 », numéro de 2027) ; avoir sur cette facture (jour de la facture d'origine « 2027-01-01 ») ; clôture de la Martinique (journée « 2026-11-16 »). JSON attendu écrit en clair.

**`tests/Feature/Calendrier/ReglesDuCalendrierTest.php`** (T2, mis à jour) :

- `test_seul_le_calendrier_connait_un_fuseau_et_met_les_dates_en_forme` : étendu à tout nom de fuseau en clair (§ 3.15).
- `test_le_code_d_un_magasin_prend_le_calendrier_de_son_magasin` (nouveau).
- `test_le_calendrier_d_affichage_ne_sert_qu_a_filament` (nouveau).
- `test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite` : étendu à `aLHeureDe()`.

**`tests/Feature/Calendrier/HorodatageEnUtcTest.php`** (ajout) :

- `test_le_panneau_d_un_magasin_d_outre_mer_affiche_ses_instants_a_son_heure` : dans le panneau d'un magasin de la Martinique, `TextColumn` et `TextEntry` sont au fuseau de la Martinique ; dans l'espace de la société, à celui de Paris.
- `test_un_jour_s_affiche_tel_quel_dans_le_panneau_d_un_magasin_de_la_martinique` : une facture à échéance au 16/12/2026 affiche « 16/12/2026 » dans la colonne « Échéance » de la liste et sur sa fiche ; même chose pour la journée d'une clôture et la date d'un règlement. Avec `->date()`, ce serait « 15/12/2026 ».

### 6.3 Tests existants à réécrire

Chaque test réécrit garde son nom et son intention, même quand il change de classe parce que sa méthode disparaît. Le message de commit le nomme.

- `TerritoireTvaTest` (`tests/Unit/TerritoireTvaTest.php:34`, `:58`) : `test_le_territoire_se_deduit_du_pays_et_du_code_postal` et `test_seuls_la_guyane_et_mayotte_ne_sont_pas_encore_pris_en_charge` passent dans `TerritoireTest`, sous le même nom, avec leurs jeux, sur `Territoire` (leurs méthodes disparaissent). Les tests des taux restent.
- Fabriques avec `['code_postal' => '97200']` : `CreerFactureActionTest` (l. 199), `NatureOperationTest` (l. 343) → `dansLeTerritoire(Territoire::Martinique)`. `NatureOperationTest` fige 10:15 à Paris : il reste juste à 04:15 ou 05:15 en Martinique, ce que le lot vérifie.
- Appels de `deplacer()` (6 sur 6b5baba : `ParametresFiscauxSocieteTest.php:198`, `ReverifierSireneTest.php:93`, `CreerEtablissementActionTest.php:442`, `MagasinResourceTest.php:206`, `:709`, `:778`) : avec le code commune.
- `InseeSireneTest` construit `EtablissementSirene` par position (l. 65, 96, 132, 133) : chaque construction reçoit le code commune à sa place, après le code postal.
- `MagasinResourceTest::test_un_changement_d_adresse_qui_change_le_territoire_de_tva_est_refuse` : même intention, messages réécrits (comparés par leurs constantes).
- `RelancerFacturesImpayeesTest::test_les_relances_partent_chaque_matin_a_9_heures_heure_de_paris` : il affirme que la tâche n'est pas due à 09:00 UTC. La tâche devient horaire. Même intention, pour un magasin de métropole : une relance part au passage de 09:00 à Paris ; aucune autre au passage suivant ; aucune pour un magasin non relancé après 11:59.
- `RelancerFacturesImpayeesTest` : les autres tests figent 09:00 à Paris (dans la fenêtre) ; un test qui relancerait hors de la fenêtre est figé dans la fenêtre, même intention.
- `ClotureAutomatiqueCaisseTest` : si un test lit le message de sortie avec un jour, il suit le nouveau message.
- Tout test qui validait un achat ou un inventaire d'ouverture dans un magasin sans territoire : il reçoit désormais le refus du § 3.4, ou se place dans un magasin confirmé, selon son intention.

### 6.4 Règles de réécriture

- Aucun test supprimé ni affaibli. Un test ne disparaît qu'avec la fonction qu'il testait, et son intention passe dans un autre test (§ 6.3).
- Une valeur attendue ne change que si le comportement change. Pour un magasin de métropole, ce lot ne change que `version_module` et le passage horaire des quatre tâches. Les tests de T2 (`NuitDeParisTest`, `HeureRepeteeTest`, `CalendrierTest`) ne changent pas.

---

## 7. Preuves exigées

Chaque code de sortie est contrôlé juste après (`echo $?`), comme le veut CLAUDE.md.

1. `php artisan migrate:fresh` sur SQLite et sur PostgreSQL.
2. Suite SQLite complète : `php artisan test --parallel`, code 0.
3. Suite PostgreSQL complète (base décrite dans le README), code 0.
4. Suite SQLite complète sous faketime, chacune au code 0, avec `TZ=UTC NO_FAKE_STAT=1 faketime -f '@<instant>' php artisan test --parallel` :

   | Instant (UTC) | Origine | Ce qu'il éprouve |
   |---|---|---|
   | `2026-10-25 00:30:00` | T2 | premier passage de l'heure répétée à Paris |
   | `2026-10-25 01:30:00` | T2 | second passage |
   | `2027-03-28 00:59:00` | T1, T2 | traverse le saut de mars |
   | `2027-03-28 01:30:00` | T2 | juste après le saut |
   | `2027-01-02 10:00:00` | T1 | nouvelle année, en journée |
   | `2026-12-31 22:59:00` | T1 | dernière minute de l'année à Paris |
   | `2026-10-25 00:59:00` | T1 | traverse la fin du premier passage |
   | `2028-02-29 12:00:00` | T1 | jour bissextile |
   | `2026-01-31 12:00:00` | T1 | fin de mois |
   | `2026-12-31 23:30:00` | T2 | 1er janvier à Paris, encore 2026 en UTC |
   | `2026-07-14 22:30:00` | T2 | nuit d'été à Paris |
   | `2026-10-25 22:59:30` | T2 | traverse le minuit de Paris de la journée de 25 h |
   | `2026-12-31 20:30:00` | T3 | 1er janvier à 00:30 à La Réunion, 31 décembre à Paris |
   | `2027-01-01 03:30:00` | T3 | 31 décembre à 23:30 aux Antilles, 1er janvier à Paris |
   | `2026-07-14 03:30:00` | T3 | 13 juillet à 23:30 aux Antilles, 14 juillet à Paris |
   | `2026-11-17 03:59:30` | T3 | traverse le minuit de la Martinique |
   | `2026-12-31 19:59:30` | T3 | traverse le minuit du nouvel an à La Réunion |

5. Suite PostgreSQL complète sous faketime, code 0, à `2026-10-25 00:30:00`, `2026-12-31 23:30:00` et `2027-01-01 03:30:00`.
6. Les tests du § 6.1 échouent sur le HEAD avant leur correction : sortie citée dans le message du commit.
7. Mutations, chacune vérifiée et dite dans le compte rendu. Chacune doit faire échouer au moins un test nommé :
   - `Calendrier::deParis()` à la place du calendrier du magasin dans la journée de `CloturerCaisseAction` ;
   - idem dans l'année des numéros d'`EmettreFactureAction` ;
   - idem dans l'année vérifiée de `VerificateurIntegrite` ;
   - le déclencheur `colonneFigee` retiré de la migration ;
   - le `configureUsing` remis sur Paris ;
   - `ClotureAutomatiqueCaisse` qui calcule « hier » une seule fois pour tous les magasins ;
   - `Territoire::depuisLeRepertoire()` qui lit le code postal avant la commune ;
   - les relances sans fenêtre locale ;
   - la garde de `DocumentNumberingService` retirée (option A de la question B) ;
   - le département du vendeur relu au code postal du magasin dans `ControleNatureOperation` ;
   - la reprise d'un SIRET jamais confirmé qui ne compare plus le territoire ;
   - un verrou du registre ajouté dans `CloturerLesJourneesAction::journeesEnAttente()` (garde du passage à vide) ;
   - `->date('d/m/Y')` remis sur la colonne « Échéance » de `FactureResource`.
8. La règle statique échoue sur chaque violation plantée, vérifiée et dite : `'America/Martinique'` dans une action ; `Calendrier::deParis()` dans `EmettreFactureAction` et dans `ConsultationVies` ; `calendrierDAffichage()` dans une action ; `travelTo('2026-11-16 20:00')` dans un test. Elle ne signale pas `'Etc/UTC'` dans `DiagnostiquerCaisseSecurisee`.
9. `ContenuSigneDeReferenceTest` : dans les jeux existants, le diff ne change que `version_module`.
10. `php artisan caisse:diagnostiquer` et `php artisan caisse:verifier` sans anomalie sur les deux moteurs, base migrée à neuf. Le seeder ne sème rien (`DatabaseSeeder::run()` n'affiche qu'un message ; `DatabaseSeederTest::test_db_seed_s_execute_sans_erreur_et_ne_seme_aucune_donnee`) : ces deux commandes prouvent seulement qu'elles tournent sur le schéma. Sur des données d'outre-mer, la preuve est `IsolationDesCalendriersTest::test_le_verificateur_ne_signale_rien_pour_trois_magasins_de_trois_fuseaux`, avec les tests de `HeureLocaleTest` qui lancent le vérificateur, passés sur les deux moteurs (points 2 et 3).
11. `vendor/bin/pint --dirty --format agent` avant chaque commit.

Avant le commit 2, au moins les instants T3 sur SQLite. Avant le dernier commit, toute la grille du point 4 et le point 5.

---

## 8. Textes de CLAUDE.md et du README

### 8.1 CLAUDE.md

**Puce « Outillage »** (texte posé par T2) :

- remplacer « chaque magasin suit l'heure locale de son territoire (journée de caisse et clôture à minuit local, date de ses documents, année de ses numéros, échéances), mise en place par le lot T3, et jusque-là tous les magasins suivent l'heure de Paris » (texte posé par T2, § 8.1, qui reprend Q1) par :
  > chaque magasin suit l'heure locale de son territoire (journée de caisse et clôture à minuit local, date de ses documents, année de ses numéros, échéances) : la métropole et la Corse suivent l'heure de Paris, et chaque territoire d'outre-mer accepté (aujourd'hui la Guadeloupe, la Martinique et La Réunion) la sienne ; la société, ses courriers et ses e-mails, le journal d'audit, l'éditeur et les tâches techniques suivent l'heure de Paris ; une page qui montre des heures de plusieurs calendriers prend Paris pour référence et nomme toute autre heure (« 20:00 (heure de la Martinique) »)
- remplacer « le planificateur suit l'heure de Paris, sans tâche quotidienne entre 2 h et 3 h » par :
  > le planificateur suit l'heure de Paris, sans tâche quotidienne entre 2 h et 3 h, et les tâches du calendrier des magasins (clôture du jour et caisses personnelles, passage en retard, expiration des devis, relances) passent toutes les heures et ne traitent que ce que le calendrier de chaque magasin rend dû
- remplacer « et la nuit, quand le jour de Paris n'est pas celui de l'UTC (README) » par :
  > et la nuit, quand le jour de Paris ou d'un territoire d'outre-mer n'est pas celui de l'UTC (README)

**Puce « Caisse et clôtures » :**

- après « Caisse et clôtures : une seule caisse par magasin. », insérer :
  > Le calendrier d'un magasin est celui de son territoire : sa journée de caisse et ses clôtures, la date de ses documents, l'année de ses numéros, ses échéances, ses relances et ses délais en jours suivent son heure locale, et minuit est son minuit (une vente faite à 20 h en Martinique est du jour même et se clôt au minuit de la Martinique).
- « et minuit ferme toute caisse personnelle qui ne l'a pas été » devient « et son minuit ferme toute caisse personnelle qui ne l'a pas été » ;
- « et la caisse refuse toute vente jusqu'à minuit » devient « et la caisse refuse toute vente jusqu'à son minuit » ;
- « Sans clôture manuelle, la clôture automatique de minuit le fait » devient « Sans clôture manuelle, la clôture automatique le fait, passé son minuit » ;
- après « Le mois de fin d'exercice, réglage de la société (décembre par défaut), est verrouillé dès la première vente de l'un de ses magasins ; », insérer :
  > la date de fin d'exercice est un même jour civil pour tous ses magasins, que chacun clôt à son minuit local, et une opération appartient à la journée, au mois et à l'exercice de son magasin ;

**Puce « Société et établissements »** (selon la réponse à la question A) :

- avec l'option A, « un déménagement qui change le territoire de TVA passe par un nouveau magasin » devient :
  > un déménagement qui change le territoire du magasin (métropole, Corse ou un département d'outre-mer), donc son taux de TVA, son heure ou son département, passe par un nouveau magasin
- avec l'option B, elle devient :
  > un déménagement qui change le territoire du magasin (métropole, Corse ou un département d'outre-mer) passe par un nouveau magasin, sauf de la Guadeloupe à la Martinique ou l'inverse, qui se fait par un changement de SIRET

**Puce « Vérification de la société » :**

- après « Chaque nouveau magasin, changement de SIRET ou réouverture est contrôlé au répertoire Sirene avant toute vente ; », insérer :
  > le premier contrôle concluant fixe le territoire du magasin, lu à la commune de son établissement (à défaut à son code postal), qui ne change plus[, et aucun document du magasin n'est numéroté avant] ;

  Le passage entre crochets n'entre que si l'éditeur retient l'option A de la question B.
- avec l'option A de la question C, insérer à la suite :
  > une revérification qui lit un autre territoire pour le même établissement alerte le propriétaire et l'éditeur ; tant qu'aucun document du magasin n'est numéroté, l'éditeur corrige le territoire par sa procédure, inscrite au journal des événements du magasin et au journal d'audit ; ensuite, la société ouvre un nouveau magasin et ferme l'ancien pour cessation ;

  et, dans la puce « Société ou magasin suspendu… », « sauf libération par la procédure de l'éditeur d'un SIRET saisi par erreur ou usurpé » devient « sauf libération par la procédure de l'éditeur d'un SIRET saisi par erreur ou usurpé, ou d'un magasin dont le territoire a été mal lu au répertoire ».
- dans la description du magasin de démonstration, après « identité fictive, », insérer :
  > territoire de son siège (à défaut la métropole), fixé à son ouverture, qui donne son taux par défaut et son heure,

**Puce « Prix et TVA des ventes » :**

- « 20 % par défaut (8,5 % pour un magasin en Guadeloupe, en Martinique ou à La Réunion) » devient « 20 % par défaut (8,5 % pour un magasin dont le territoire est la Guadeloupe, la Martinique ou La Réunion) ».

Aucune autre partie de CLAUDE.md ne change. Si le HEAD a d'autres phrases qui disent qu'un magasin suit l'heure de Paris, le lot les réécrit selon ces règles et le dit.

### 8.2 README

Dans le paragraphe « Heure » de T2, remplacer la phrase « Le calendrier (journée de caisse, échéances, année des numéros, affichage) suit l'heure de Paris pour tous les magasins, par la classe `App\Domain\Calendrier\Calendrier`, seule à connaître un fuseau, et le planificateur par `schedule_timezone`. » par :

> Le calendrier de chaque magasin (journée de caisse, échéances, année des numéros, affichage) suit l'heure de son territoire : Paris pour la métropole et la Corse, la sienne pour chaque territoire d'outre-mer accepté (aujourd'hui la Guadeloupe, la Martinique et La Réunion). Ce territoire est fixé au premier contrôle Sirene de l'établissement et ne change plus. La société, ses courriers et l'éditeur suivent l'heure de Paris. Seule la classe `App\Domain\Calendrier\Calendrier` connaît un fuseau. Le planificateur suit l'heure de Paris (`schedule_timezone`) ; les tâches du calendrier des magasins passent toutes les heures.

Dans la boucle « Suite sous faketime », ajouter les cinq instants de T3 :

```sh
for t in '2026-10-25 00:30:00' '2026-10-25 01:30:00' '2027-03-28 00:59:00' '2027-03-28 01:30:00' \
         '2027-01-02 10:00:00' '2026-12-31 22:59:00' '2026-10-25 00:59:00' '2028-02-29 12:00:00' \
         '2026-01-31 12:00:00' '2026-12-31 23:30:00' '2026-07-14 22:30:00' '2026-10-25 22:59:30' \
         '2026-12-31 20:30:00' '2027-01-01 03:30:00' '2026-07-14 03:30:00' '2026-11-17 03:59:30' \
         '2026-12-31 19:59:30'; do
  TZ=UTC NO_FAKE_STAT=1 faketime -f "@$t" php artisan test --parallel; echo "$t : $?"
done
```

---

## 9. Découpage en commits

Chaque commit est vert sur les deux moteurs (suites complètes, code 0) et passé par pint. Chaque message nomme les tests ajoutés et réécrits.

Le commit 1 attend les réponses aux questions A, B et C (§ 13) : elles décident du refus entre la Guadeloupe et la Martinique, de la garde de la numérotation et de la suite d'un territoire mal lu. Les commits 2 à 4 n'en dépendent pas.

1. **Territoire du magasin.** Aucun calendrier ne change encore : tous les magasins restent sur `Calendrier::deParis()`.
   - `Territoire`, code commune de Sirene (contrat, INSEE, factice), `ControleSirene::territoireDe()`.
   - Colonne `magasins.territoire`, `colonneFigee()`, garde du modèle, `migrate:fresh`.
   - Fixation aux six sites, refus, messages, JET 270, journal d'audit (`ActionAuditee::champsAudites()`).
   - Reprise d'un SIRET jamais confirmé : territoire comparé, contrôle négatif s'il diffère.
   - `Magasin::territoireTva()` et `Territoire::departement()` depuis le territoire ; `ControleNatureOperation` ; suppressions du § 4.2.
   - Garde de `DocumentNumberingService` (option A de la question B).
   - Procédure de l'éditeur de la question C, si l'éditeur retient son option A.
   - Territoire de la démonstration.
   - `MagasinFactory` (état `dansLeTerritoire()`), `FauxRepertoireSirene` (`deplacer()` avec la commune, `situerHorsDeFrance()`).
   - Tests : `TerritoireTest`, `TerritoireDuMagasinTest` (sa reprise figée à un instant où Paris et la Martinique ont le même jour et la même année), les tests étendus d'`InseeSireneTest`, la partie « territoire et taux » du test de la démonstration, les réécritures du § 6.3 qui en dépendent. Aucun test du commit 1 n'appelle `aLHeureDe()` ni n'attend une année locale : ils viennent au commit 2.
2. **Calendrier des magasins.**
   - `Calendrier::duTerritoire()`, `libelleDeLHeure()`, `afficherHorsDuMagasin()`, `heureDe()` ; `Magasin::calendrier()` ; `TenantContext::calendrierDAffichage()` ; `aLHeureDe()`.
   - Chaque site du § 3.6 et de l'annexe A.1 ; `InstantFiscal`, `ContenuFiscal`, vérificateur ; pages de la société ; libellés du journal des événements ; `configureUsing`.
   - Les quatre commandes calculent le jour de chaque magasin, avec leurs horaires de T2 encore quotidiens. Les relances n'ont pas encore de fenêtre : avec un passage quotidien à 09:00 à Paris, elle priverait les Antilles de relances.
   - `ModuleFiscal::VERSION`.
   - Tests : ajouts à `CalendrierTest`, `HeureLocaleTest` (sauf la partie « relances » de `test_l_echeance_le_retard_et_les_relances_suivent_le_jour_local`, qui vient au commit 3), `HeuresDeLaSocieteTest`, `IsolationDesCalendriersTest` (sauf la garde du verrou), `ContenuSigneDeReferenceTest`, `ReglesDuCalendrierTest`, `HorodatageEnUtcTest` (dont le jour affiché en Martinique), partie « heure » du test de la démonstration.
3. **Tâches horaires.** Les quatre horaires de `bootstrap/app.php`, la fenêtre des relances ; le passage à vide reste sans verrou. Tests : `PlanificateurTest`, la partie « relances » du test du commit 2, la garde `test_un_passage_horaire_sans_journee_due_ne_verrouille_aucun_registre` (avec sa mutation), la réécriture de `RelancerFacturesImpayeesTest`.
4. **Documentation et preuves.** CLAUDE.md et README (§ 8), grille faketime complète (§ 7, points 4 et 5).

Le lot peut fusionner 3 et 4. Il ne fusionne jamais 1 et 2 : le commit 1 garde la preuve que le territoire seul ne change aucune heure.

---

## 10. Critères de fin

- [ ] `magasins.territoire` existe ; la base et le modèle refusent de changer un territoire renseigné.
- [ ] Le territoire se lit à la commune de l'établissement, à défaut au code postal ; un établissement sans l'un ni l'autre est refusé.
- [ ] Le territoire se fixe aux six sites du § 3.3 et nulle part ailleurs ; sa fixation sur un magasin existant s'inscrit au JET 270.
- [ ] Un nouvel établissement ou une adresse d'un autre territoire est refusé, même au même taux ; entre la Guadeloupe et la Martinique, selon la réponse à la question A.
- [ ] À la reprise d'un SIRET jamais confirmé, un établissement d'un autre territoire que le territoire fixé n'est pas confirmé et l'adresse ne change pas.
- [ ] Le département du vendeur d'une « Livraison vers un DOM » est celui de son territoire, jamais celui de son code postal.
- [ ] Selon la réponse à la question B, un magasin sans territoire ne numérote aucun document.
- [ ] Selon la réponse à la question C, la suite d'un territoire mal lu est en place, ou l'alerte seule.
- [ ] `Magasin::territoireTva()` se déduit du territoire ; un établissement en diffusion partielle d'outre-mer propose son taux.
- [ ] La démonstration a le territoire du siège, à défaut la métropole, gardé à chaque réouverture.
- [ ] `Calendrier::duTerritoire()` existe ; `FUSEAU_DE_PARIS` est lu par `deParis()` seule ; les autres noms de fuseaux ne sont que dans la table de `duTerritoire()`.
- [ ] Aucun site d'un magasin n'appelle `Calendrier::deParis()` (règle statique, exceptions justifiées).
- [ ] Le panneau d'un magasin affiche son heure ; un jour s'y affiche tel quel, même aux Antilles ; les pages qui mêlent plusieurs calendriers nomment toute heure hors de Paris.
- [ ] Les libellés du journal des événements nomment l'heure du magasin ; ceux de Paris n'ont pas changé.
- [ ] Les quatre tâches sont horaires ; les relances partent de 09:00 à 11:59, heure locale ; un passage à vide ne verrouille toujours aucun registre (garde et mutation).
- [ ] `ModuleFiscal::VERSION` est à la version mineure suivante ; dans les jeux existants du contenu signé de référence, seul `version_module` a changé.
- [ ] Les tests nommés du § 6.2 passent ; les sondes du § 6.1 échouaient avant leur correction.
- [ ] Toutes les preuves du § 7 sont au code 0 et citées dans le compte rendu, mutations comprises.
- [ ] CLAUDE.md et le README portent les textes du § 8.
- [ ] Aucune requête réécrite n'a perdu son filtre de magasin ou de société ; aucune plage de dates n'est commune à des magasins de calendriers différents.
- [ ] Aucune dépendance ajoutée ; aucune règle métier changée hors la réponse Q1 et les réponses aux questions A, B et C ; les choix du § 12 ne touchent aucune règle écrite dans CLAUDE.md.
- [ ] Le compte rendu dit les constats disparus depuis 6b5baba, les sites nouveaux traités, et la confirmation à obtenir sur la diffusion partielle (« laisses »).

---

## 11. Ce que TA, EC et les lots suivants devront respecter

Ces règles s'ajoutent à celles de T2 (`t2/decision-horodatage.md`, § 11). Elles n'en retirent aucune. Elles en précisent quatre : les règles 4, 5, 8 et 12 de T2 disent « le calendrier du site » ; pour un site d'un magasin, c'est le calendrier de ce magasin (règle 1 ci-dessous) ; pour la société, un cabinet, l'éditeur ou la technique, `Calendrier::deParis()`. La règle statique et les tests les font respecter.

1. **Calendrier d'un magasin.** Tout calcul de jour, d'année ou de minuit pour un magasin prend `$magasin->calendrier()`, ou celui du magasin du document : dates des e-mails et des PDF du magasin, dates de son contenu signé, jours de ses fabriques. Jamais `Calendrier::deParis()`, jamais le contexte par défaut, sauf pour une fabrique dont la fiche n'a pas encore de `magasin_id` (§ 3.5).
2. **Territoire.** Tout nouveau magasin, quel que soit le type d'activité de sa société, reçoit son territoire selon le § 3. Aucun écran ne le change. Seuls le changement de SIRET entre la Guadeloupe et la Martinique (si l'éditeur retient l'option B de la question A) et la procédure de l'éditeur de la question C (si l'éditeur retient son option A, avant le premier document numéroté) peuvent le changer, inscrits au JET 270. Le département du magasin se lit à son territoire, jamais à son code postal.
3. **Pages qui mêlent plusieurs calendriers.** Paris est la référence ; toute heure d'un magasin passe par `afficherHorsDuMagasin()`. Un jour s'affiche sans mention, par `jour()` ou `afficherJour()`, jamais par `->date()`.
4. **Aucune plage de dates commune** à des magasins de calendriers différents. Un total de société se fait par magasin, puis s'additionne.
5. **TA :**
   - la garde « la base refuse toute modification » du type d'activité réutilise `DeclencheursDeProtection::colonneFigee()` ;
   - le catalogue de démonstration de chaque profil prend le taux par défaut du territoire de la démonstration (§ 3.11) ;
   - une société de services suit les mêmes calendriers que les autres : caisse, clôtures, échéances ;
   - l'avis et l'e-mail de clôture d'une inscription, et les textes du cabinet, restent à l'heure de Paris.
6. **EC :**
   - le cabinet n'a pas de magasin : son espace a Paris pour référence ; ses délais en jours (invitation et demande de 7 jours) passent par `Calendrier::deParis()->decaler()` ; sa limite de 50 invitations par jour compte le jour de Paris ;
   - son tableau de bord multi-clients affiche l'heure de chaque magasin par `afficherHorsDuMagasin()` ;
   - un collaborateur dans le panneau d'un magasin client le voit à l'heure de ce magasin, comme tout compte ;
   - la lecture du territoire des magasins des clients liés s'ajoute aux champs de la lecture permise du tableau de bord ; son test d'isolation comprend un client avec un magasin d'outre-mer.
7. **A7 (journal d'audit consultable) :** heure de Paris, en-tête de colonne « heure de Paris », mention de l'heure répétée (T2) ; une entrée qui concerne un magasin d'outre-mer garde l'heure de Paris, journal de la société oblige.
8. **Étape 2 (SUPER PDP) :** la date d'une facture (Factur-X), d'un avoir et d'un encaissement, et la journée d'une déclaration d'e-reporting, sont le jour local du magasin. Aucune déclaration ne mêle deux calendriers.
9. **Étape 3 :** clôtures mensuelles et de l'exercice, et archives, dans le calendrier de chaque magasin (§ 3.10). La notice et l'attestation disent que la journée d'un magasin est celle de son territoire.
10. **Étapes 5 et 6 :** écritures, export comptable, état de TVA et rapports datés au jour local de chaque magasin.
11. **Étape 12 :** la facturation des périodes d'activité nomme son calendrier ; `PeriodesDActivite::jours(Magasin $magasin)` prend celui du magasin qu'elle reçoit.
12. **Étape 13 :** les écrans de l'éditeur ont Paris pour référence et nomment l'heure d'un magasin.
13. **Nouveau territoire accepté** (Guyane, Mayotte, ou une collectivité sur décision de l'éditeur) : son entrée dans la table de `duTerritoire()` existe ou s'ajoute ; `test_chaque_minuit_local_tombe_sur_un_passage_horaire` le couvre ; Saint-Pierre-et-Miquelon a une heure d'été, que la mécanique de T2 gère déjà.
14. **Tests :** une heure d'outre-mer s'écrit par `aLHeureDe()` ; la suite SQLite passe aussi sous faketime aux instants de T3 (§ 7), en plus de ceux que T2 exige.

---

## 12. Choix de Claude contestables

Chaque choix est appliqué par le lot. L'éditeur peut le contester ; l'autre option est dite.

- **C1. Un territoire stocké, source de la TVA et de l'heure.** Autre option : une colonne de fuseau à part, le territoire de TVA restant lu à l'adresse. Écartée : deux sources pourraient se contredire, et la diffusion partielle resterait à 20 %.
- **C2. La commune d'abord, le code postal en secours, sinon refus.** Autre option : laisser la personne choisir le territoire quand le répertoire ne le dit pas. Écartée : un territoire déclaratif fixerait l'heure de documents signés sans contrôle.
- **C3. Le territoire est figé dès sa fixation, sans attendre le premier document** (question 7 de la carte, en partie). Autre option : figer seulement au premier document. Écartée : règle plus complexe, et le vérificateur recalcule les dates signées avec le calendrier du moment. Le déménagement entre la Guadeloupe et la Martinique n'est plus un choix de Claude : il touche une règle écrite de l'éditeur et passe à la question A (§ 13).
- **C4. Aucun document numéroté sans territoire** (question 6 de la carte) : c'est une règle nouvelle, passée à la question B (§ 13), dont c'est l'option recommandée.
- **C5. La démonstration prend le territoire du siège, à défaut la métropole** (question 1 de la carte). Autres options : toujours Paris ; ou un territoire choisi à l'ouverture. Écartées : Paris entraîne une société d'outre-mer à un taux et une heure faux ; un choix à l'ouverture demande un écran de plus.
- **C6. La société, ses courriers, ses e-mails et le journal d'audit restent à l'heure de Paris** (questions 2 et 3 de la carte). Autre option : l'heure du siège. Écartée : le siège peut être inconnu à l'inscription et peut changer ; Paris est déjà la règle et l'éditeur y travaille. Une heure de magasin hors de Paris est toujours nommée.
- **C7. Tâches horaires ; relances de 09:00 à 11:59, heure locale** (question 4 de la carte). Autres options : une tâche par fuseau ; ou des passages quotidiens à Paris. Écartées au § 2.3. Le passage en retard et l'expiration des devis suivent aussi le minuit local, avec au plus 15 ou 30 minutes de délai.
- **C8. Version mineure du module fiscal** (question 5 de la carte). Autre option : version majeure. Écartée : rien ne change dans l'inaltérabilité, la sécurisation, la conservation ni l'archivage, et aucune attestation n'a encore été émise (étape 3). À revoir avec l'attestation si l'éditeur en juge autrement.
- **C9. « Les caisses » de la société montrent par défaut la journée en cours de chaque magasin.** Autre option : un seul jour, celui de Paris. Écartée : à 01:00 à Paris, une société des Antilles verrait une page vide pour un jour qui n'a pas commencé chez elle.
- **C10. La connexion précédente suit le compte :** un membre, l'heure de son magasin ; un compte de la société, l'heure de Paris.
- **C11. Un magasin sans territoire affiche ses écrans à l'heure de la métropole.** Autre option : lever une erreur. Écartée : ses écrans doivent rester utilisables pendant une panne, et rien de ce qu'il date n'est numéroté.
- **C12. Un écart de territoire relevé par une revérification d'un SIRET déjà confirmé alerte sans rien changer.** C'est la règle actuelle d'une revérification négative après la validation. La suite de l'alerte touche une procédure de l'éditeur : question C (§ 13).
- **C13. À la reprise d'un SIRET jamais confirmé, un autre territoire donne un contrôle négatif.** Autre option : refuser un NIC corrigé tant que l'INSEE ne répond pas, quand le territoire est fixé. Écartée : elle retire une liberté (corriger un NIC pendant une panne) et laisse le même trou aux autres reprises ; la comparaison à la reprise couvre tous les chemins.
- **C14. Le département du vendeur se lit à son territoire.** Autre option : exiger un code postal pour tout magasin d'outre-mer. Écartée : la diffusion partielle masque le code postal, et le territoire connaît déjà le département.

---

## 13. Questions à l'éditeur

La réponse Q1 tranche le principe. Trois points touchent une règle écrite de l'éditeur, ou en ajoutent une : ils ne sont pas tranchés par Claude (CLAUDE.md, protocole, règle 4 ; T2 § 10). Le commit 1 attend leurs réponses ; les commits 2 à 4 n'en dépendent pas.

**Question A. Déménagement entre la Guadeloupe et la Martinique.**

> Un magasin de la Guadeloupe peut-il déménager en Martinique (ou l'inverse) en restant le même magasin ?
>
> Aujourd'hui, CLAUDE.md dit : « un déménagement qui change le territoire de TVA passe par un nouveau magasin ». Le code compare les groupes de taux (Guadeloupe, Martinique et La Réunion ensemble) : il accepte donc ce déménagement, mais refuse la Guadeloupe vers La Réunion. Les deux îles ont le même taux (8,5 %) et la même heure (−04:00 toute l'année). Mais chacune est un département distinct pour les livraisons entre départements d'outre-mer : le code exonère déjà, comme « Livraison vers un DOM », une livraison d'un magasin de la Guadeloupe vers la Martinique.
>
> - **Option A (recommandée) : non.** Tout déménagement vers un autre territoire (métropole, Corse ou un département d'outre-mer) passe par un nouveau magasin. Le territoire d'un magasin ne change jamais. La règle de CLAUDE.md devient : « un déménagement qui change le territoire du magasin (métropole, Corse ou un département d'outre-mer), donc son taux de TVA, son heure ou son département, passe par un nouveau magasin ».
> - **Option B : oui.** Le magasin passe d'une île à l'autre par un changement de SIRET, contrôlé au répertoire Sirene et inscrit au journal des événements. À partir de ce jour, une livraison vers son ancienne île devient une « Livraison vers un DOM », et une livraison dans sa nouvelle île ne l'est plus. Tout autre changement de territoire reste refusé.

Pourquoi A : une règle unique (un territoire, un magasin), un département qui ne change jamais pour un même magasin, et un cas presque inexistant (déménager une boutique d'une île à l'autre).

**Question B. Documents numérotés avant la confirmation de l'établissement.**

> Quand l'INSEE ne répond pas à la création d'un magasin, le magasin est créé « à vérifier » ; il ne vend rien jusqu'à la confirmation, reprise automatiquement chaque heure (règle actuelle). En attendant, peut-il valider ses factures d'achat, ses avoirs fournisseur et son inventaire d'ouverture, qui reçoivent un numéro avec l'année ?
>
> - **Option A (recommandée) : non.** Aucun document du magasin n'est numéroté avant la confirmation de son établissement.
> - **Option B : oui.** Ils se numérotent à l'heure de Paris en attendant. Un document validé le 31 décembre au soir ou le 1er janvier peut alors porter une autre année que son jour local (par exemple, à La Réunion, un achat validé le 1er janvier à 00:30 recevrait un numéro de l'année qui finit).

Pourquoi A : l'année d'un numéro dépend de l'heure locale du magasin, que PILIQI ne connaît qu'à la confirmation ; l'attente dure en général moins d'une heure ; le magasin ne peut pas vendre pendant ce temps.

**Question C. Territoire mal lu au répertoire Sirene.**

> Le territoire d'un magasin (donc son heure et son taux par défaut) est lu au répertoire Sirene à la confirmation de son établissement, puis ne change plus. Si le répertoire se trompait sur la commune de l'établissement, puis se corrigeait, que fait-on ?
>
> - **Option A (recommandée).** La revérification qui lit l'autre territoire alerte le propriétaire et l'éditeur. Tant qu'aucun document du magasin n'est numéroté, l'éditeur corrige le territoire par une procédure (commande `editeur:corriger-territoire`), sur ce seul constat, inscrite au journal des événements du magasin et au journal d'audit. Après le premier document numéroté, le territoire ne change plus : la société ouvre un nouveau magasin et ferme l'ancien pour cessation ; l'éditeur libère le SIRET pour le nouveau magasin par sa procédure de libération, étendue à ce cas.
> - **Option B.** L'alerte seule ; l'éditeur décide au cas par cas, sans procédure prévue.

Pourquoi A : avant le premier document numéroté, aucune date ni aucune année signée ne dépend du territoire, et la correction ne fausse rien ; après, changer l'heure rendrait faux ce qui a été signé (sonde 7), et seul un nouveau magasin est juste.

**Les sept questions de la carte :**

| Question de la carte | Tranchée par |
|---|---|
| 1. Heure de la démonstration | C5 |
| 2. Heure du journal d'audit, de « Les caisses » de la société, de la connexion précédente | C6, C9, C10 |
| 3. Heure du courrier du code et des e-mails de la société | C6 |
| 4. Tâches horaires, retard, expiration, relances à l'heure locale | C7 |
| 5. Nouvelle version du module fiscal | C8 |
| 6. Territoire exigé avant le premier document | question B |
| 7. Déménagement Guadeloupe ↔ Martinique | question A |

Une confirmation reste à obtenir, sans bloquer le lot : que l'INSEE diffuse la commune d'un établissement en diffusion partielle (§ 3.2). Elle se constate sur l'API réelle, avec la clé de l'éditeur.

---

## 14. Constats écartés

Deux relectures croisées de ce cahier et de celui de T2 (25/10/2026) ont relevé 21 constats (6 et 15). Chacun a été vérifié dans le code de 6b5baba et sous PHP 8.3.6. Tous sont réels et corrigés plus haut, ou dans le cahier de T2 pour ce qui le touche (T2 § 13). Seuls les éléments suivants sont écartés, en tout ou en partie.

- **Relecture 1, constat 1, remède « garder ce déménagement permis ».** Claude ne le choisit pas plus que le refus : le texte de l'éditeur (« territoire de TVA ») se lit des deux façons, et le code traite déjà chaque département d'outre-mer à part pour les livraisons. Le point passe à la question A, avec une option recommandée ; aucune des deux options n'est appliquée sans réponse.
- **Relecture 1, constat 3, remède « un nouveau magasin » seul.** Écarté comme remède unique : un nouveau magasin demande le même SIRET, qu'aucune procédure actuelle ne libère pour ce motif, et il serait disproportionné avant le premier document. La suite proposée combine les deux remèdes du constat ; elle crée une procédure de l'éditeur, donc passe à la question C.
- **Relecture 2, constat 3, seconde option (refuser un NIC corrigé pendant une panne).** Écartée (choix C13) : la comparaison du territoire à la reprise ferme le trou pour tout SIRET jamais confirmé, sans retirer la correction d'un NIC pendant une panne.
- **Relecture 2, constat 6, seconde option (vérifier des décalages plutôt que des noms dans `CalendrierTest`).** Écartée : des décalages identiques ne distinguent pas deux fuseaux (la Guadeloupe et la Martinique ont les mêmes) ; seul le nom prouve la table. `CalendrierTest` est donc une exception listée de la règle statique. Pour `DiagnostiquerCaisseSecurisee`, ce n'est pas une exception qui est retenue, mais le retrait des noms de l'UTC de la liste interdite (relecture 1, constat 4).
- **Relecture 2, constat 8, remède « test jetable dans `tests/Feature/Scratch/` ».** Remplacé par un test nommé et permanent (`IsolationDesCalendriersTest::test_le_verificateur_ne_signale_rien_pour_trois_magasins_de_trois_fuseaux`) : même contenu (Paris, Martinique, La Réunion, ventes et clôtures, `caisse:verifier`), passé à chaque suite sur les deux moteurs, et il reste comme garde. Le retrait des deux points sur le seeder est retenu en entier.
- **Relecture 2, constat 11, seconde option (ajouter une colonne de date d'émission à la liste des factures).** Écartée : ce serait un changement d'écran que rien ne demande. Le test affirme ce qui existe.
- **Relecture 2, constat 1, emplacement de la correction.** Le remède (macro `jour()`, `->date(` interdit) est retenu, mais il est posé par T2 (T2 § 3.5, § 3.9, § 4.10), non par T3 : `->date()` convertit un jour d'un fuseau à un autre, ce que T2 interdit déjà (T2 § 2.1), et Q1 demande que T2 ne crée rien que T3 doive défaire. T3 garde le test en Martinique. La règle statique n'est pas limitée aux colonnes `JourCivil` : `->date(` est interdit partout, et les 4 instants affichés à leur jour passent à `->dateTime('d/m/Y')`, même texte ; la règle est plus simple à vérifier.
- **Relecture 2, constat 13, part « T3 modifie deux règles de T2 sans les nommer ».** Après correction, T3 ne modifie plus ces deux règles : T2 pose `jour()` lui-même, et les noms de l'UTC restent permis dans T3. La liste des promesses de T2 que T3 étend est au § 1.1 bis.

## Annexe A. Sites relevés sur 6b5baba (carte T3)

Numéros de ligne de 6b5baba, avant T2. Le lot refait le relevé (§ 1.4).

### A.1 Passent au calendrier du magasin

- **Journée de caisse et minuit :** `ClotureCaisse::refusDUneVente` (l. 139-146), appelé par `Caisse.php:507-510` ; `ClotureCaisse::derniereJourneeCloturee` (l. 121-126) et `scopeDuJour` (l. 109-111) ; `ContrePasserPaiementAction.php:111`, `:144`, `:211-212` ; `CloturerCaisseAction.php:117-124`, `:140`, `:151`, `:218-240` ; `CloturerLesJourneesAction.php:85-91`, `:117` (jour du jeton), `:183-189` ; `JourneesDeCaisse.php:40-60` ; `Facture::jourDeLaPremiereVente` (l. 316-320) ; `PeriodesDActivite::jours` (l. 66-79) ; `RecapitulatifDeCloture.php:92` ; `PanierDeCaisse.php:234`.
- **Écrans des clôtures :** `ListClotureCaisses.php:77`, `:100-101`, `:146`, `:157`, `:180` ; `ClotureCaisseResource.php:81-84` ; `VentesEnAttenteApercu.php:33`.
- **Clôture automatique et caisses fermées à minuit :** `ClotureAutomatiqueCaisse.php:52-53`, `:64`, `:73`, `:83` ; `FermerLesCaissesPersonnellesAction.php:59` ; `FermerSaCaisseAction.php:104`, `:125`, `:176`, `:196`, `:220` ; `VerificateurCaissesPersonnelles.php:52-56`.
- **Ticket Z :** `CloturerCaisseAction.php:174` (année), `:196` (journée signée).
- **Date d'émission et année des numéros :** `EmettreFactureAction.php:242` ; `EmettreAvoirAction.php:129` ; `EmettreDevisAction.php:85`, `:87` ; `ValiderFactureAchatAction.php:126` ; `EmettreAvoirFournisseurAction.php:93` ; `ValiderInventaireOuvertureAction.php:140` ; `CloturerCaisseAction.php:174` ; `InstantFiscal::enHeureLocale` (l. 33-35), remplacée par T2.
- **PDF :** `factures/pdf.blade.php:39-40` ; `avoirs/pdf.blade.php:21`, `:33` ; `devis/pdf.blade.php:31-32` ; `documents/pdf/reglements.blade.php:23`.
- **Écrans des documents :** `AvoirResource.php:334`, `:414`, `:509` ; `FactureResource.php:211`, `:440`, `:489`, `:494` ; `FactureAchatResource.php:136`, `:303` ; `AvoirFournisseurResource.php:61`, `:89` ; `InventaireOuvertureResource.php:210`, `:243` ; `DevisResource.php:107`, `:138` ; `ClientResource.php:405` ; `ServiceResource.php:151` ; `EnvoieLesDocuments.php:166` ; `PosteReconnuResource.php:58`, `:61` ; `consultations-vies.blade.php:32`.
- **Échéances, retard, relances :** `EmettreFactureAction.php:250` ; `Facture::estEchue` (l. 617-619), `totauxAEncaisser` (l. 267-278), `joursDepuisLaDerniereRelance` (l. 662-667), message de relance (l. 680) ; `EnvoyerDocumentAction.php:175-176` ; `MarquerFacturesEnRetard.php:45-47` ; `RelancerFacturesImpayees.php:71-81` ; `factures-a-encaisser-apercu.blade.php:39`.
- **Devis :** `Devis::estExpire` (l. 211) ; `AccepterDevisAction.php:41` ; `EmettreDevisAction.php:78` ; `ExpirerLesDevisAction.php:32`.
- **Département du vendeur (territoire, pas calendrier) :** `ControleNatureOperation.php:185`, qui lit aujourd'hui le code postal de l'émetteur (`Emetteur.php:103`).
- **Règlement, VIES, preuves :** `EnregistrerPaiementAction.php:101` ; `PaiementResource.php:131-132` ; `FactureResource.php:804-805`, `:882` ; `ViewFacture.php:104` ; `EmettreFactureAction.php:241`, `:260` ; `ContrePasserPaiementAction.php:144` ; `ConsultationVies.php:75`, `:90-92` ; `ControleNatureOperation.php:243` ; `AjouterPreuveOperationAction.php:131-140` ; `PreuvesRequises.php:144-147`.
- **E-reporting :** `EReportingDesParticuliers.php:96`, `:287-290`, `:448-450` ; `EReportingResource.php:198`, `:217`, `:328` ; `ViewEReporting.php:18`.
- **Tableaux de bord et relevés :** `FacturesApercu.php:38-40` ; `SelecteurDeClients.php:120-126` ; `SelecteurDeProduits.php:215` ; `ClotureCaissePersonnelleResource.php:97-98` ; `ListClotureCaissePersonnelles.php:120` ; `CaissesPersonnellesOuvertesApercu.php:32`.
- **Pages de la société (calendrier de chaque magasin, heures nommées) :** `LesCaisses.php:60`, `:94`, `:96` ; `CaissesDeLaSociete.php:85-99` ; `les-caisses.blade.php:31`, `:50` ; `FacturesAEncaisserDeLaSociete.php:62-69` ; `MembreResource.php:155`, `:209` pour un membre d'un magasin.
- **Journal des événements :** `EnregistrementFiscalResource.php:67-68`, `:127-128`, `:148-149` ; `ListEnregistrementsFiscaux.php:89-90` ; `EnregistrementFiscal.php:103-110` ; `ExporterJournalDesEvenementsAction.php:58`, `:137-142`, `:161` ; `ConformiteApercu.php:41`, `:82`.
- **Vérificateur :** `VerificateurIntegrite.php:391-392`, `:626-627`, `:1316-1333`, `:1578-1582`.
- **Contenu signé :** `ContenuFiscal.php:115`, `:169`, `:179`, `:186`, `:210`, `:242`, `:253`.
- **Connexion précédente :** `SuiviDesConnexions.php:94-96` pour un membre.
- **Fabriques :** `MagasinFactory.php:33`, `:48`, `:76` ; `ClotureCaisseFactory.php:19` ; `DevisFactory.php:22` (ces deux dernières par le magasin actif de `TenantContext`, § 3.5).
- **Jours affichés par Filament :** les 13 colonnes et entrées en `->date(` sur un jour (liste au § 4.10 de T2), déjà passées à `jour()` par T2.

### A.2 Restent à l'heure de Paris ou en UTC

- **UTC :** `RegistreFiscal.php:452-459`, `:564` ; `InstantFiscal.php:24` ; `VerificateurIntegrite.php:734` ; `HorlogeRegressiveException.php:21-23` ; limiteurs, sessions.
- **Société :** `DemandeInscriptionMail.php:34` ; `CourrierDeCode.php:45-46` ; `courriers/code.blade.php:9`, `:62` ; `verification-societe.blade.php:11-37` ; `VerificationSociete.php:159` ; `TableauDeBord.php:87`, `:246`, `:248` ; `EnvoyerCodeDeConfirmationAction.php:159`, `:208-209` ; `CourriersDeVerification.php:63`, `:81` ; `SuivreLesEnvoisAction.php:107` ; `CloreInscriptionsSansCode.php:33` ; `DemanderLaTransmissionDeLaProprieteAction.php:277` ; `DemanderUneNouvelleAdresseAction.php:114` ; `ConfirmerNouvelleAdresseAction.php:126`, `:209` ; `ModifierProfil.php:142-148` (exception listée de la règle statique, § 3.15) ; `JetonsDuLien.php:66-68` ; `CreationDesComptes.php:122` ; `MembreResource.php:155`, `:209` pour un compte de la société ; `JournalAudit.php:84`, `:206` ; `SuiviDesConnexions.php:94-96` pour un compte de la société.
- **Éditeur et technique :** commandes `editeur:*` ; `VerifierIntegriteFiscale.php:58` ; `PurgerActionsTraitees.php:34` ; `EffacerPiecesVerification.php:51` ; `TraceAudit.php:61` ; `TraceEvenement.php:70` (exception listée, § 3.15) ; `PurgerTraces` ; `PurgerJournalAudit` ; `FermerDemonstrationsInactives.php:39`, `:79` ; `InseeSirene.php:90`.
- **E-mails sans heure :** `DocumentMail.php:119-121` n'affiche que des jours. Les alertes de stock ne portent aucune date.

---

## Annexe B. Correspondance des constats et des preuves

| Constat | Correction | Test |
|---|---|---|
| Fuseau confondu avec le territoire de TVA | `Territoire` stocké, `duTerritoire()` | `CalendrierTest::test_chaque_territoire_a_son_fuseau` |
| Sonde 1 : adresse vers un autre territoire | refus par territoire, pas par taux | `TerritoireDuMagasinTest::test_une_adresse_d_un_autre_territoire_…` |
| Sonde 2 : SIRET vers un autre territoire | idem, sous le verrou | `TerritoireDuMagasinTest::test_un_nouvel_etablissement_d_un_autre_territoire_…` |
| Sonde 3 : panne de l'INSEE | territoire nul, garde de la numérotation, fixation à la reprise (JET 270) | `test_un_magasin_cree_quand_l_insee_ne_repond_pas_…`, `test_la_reprise_du_controle_fixe_…` |
| Sonde 4 : démonstration à Paris | territoire du siège | `MagasinDeDemonstrationTest::test_la_demonstration_prend_le_territoire_du_siege_…` |
| Sonde 6 : diffusion partielle à 20 % | lecture de la commune | `test_un_etablissement_en_diffusion_partielle_…`, `InseeSireneTest::test_le_code_commune_…` |
| Sonde 7 : fuseau changé après signature | territoire figé (modèle et base) | `test_la_base_et_le_modele_refusent_de_changer_un_territoire_fixe` |
| Établissement à l'étranger classé en métropole | `MOTIF_TERRITOIRE_INCONNU` | `test_un_etablissement_qu_aucune_commune_francaise_ne_situe_est_refuse` |
| Clôture à minuit de Paris pour tous | tâche horaire, jour de chaque magasin | `HeureLocaleTest::test_un_magasin_de_la_martinique_…`, `IsolationDesCalendriersTest::test_un_passage_horaire_…`, `PlanificateurTest::test_chaque_minuit_local_…` |
| Année des numéros à Paris | `anneeDe()` du magasin | `test_un_magasin_de_la_reunion_numerote_…`, `test_une_vente_du_31_decembre_…_en_guadeloupe_…` |
| Échéance, retard, relances à Paris | jour du magasin, fenêtre locale | `test_l_echeance_le_retard_et_les_relances_suivent_le_jour_local` |
| Pages de la société à un seul jour de Paris | calendrier de chaque magasin, heures nommées | `HeuresDeLaSocieteTest` |
| Libellés « heure de Paris » du journal | `libelleDeLHeure()` | `test_le_journal_des_evenements_et_son_export_nomment_l_heure_du_territoire` |
| Contenu signé d'outre-mer | `iso8601()` et jours du magasin, version mineure | `ContenuSigneDeReferenceTest::test_le_contenu_signe_d_un_magasin_d_outre_mer_…` |
| Vérificateur à l'heure de Paris | calendrier du magasin vérifié | `test_une_vente_du_31_decembre_…`, `HeuresDeLaSocieteTest::test_le_verificateur_nomme_…` |
| Méthodes de modèle sans magasin courant | calendrier du magasin du document | `IsolationDesCalendriersTest::test_le_calendrier_d_un_document_…` |
| Passage horaire à vide : déjà sans verrou sur 6b5baba (relecture) | garder la source actuelle, aucune lecture nouvelle | garde `test_un_passage_horaire_sans_journee_due_ne_verrouille_aucun_registre` et sa mutation |
| Jour affiché la veille aux Antilles par `->date()` (relecture) | macro `jour()` posée par T2 | `HorodatageEnUtcTest::test_un_jour_s_affiche_tel_quel_dans_le_panneau_d_un_magasin_de_la_martinique` |
| Département du vendeur lu au code postal (relecture) | `Territoire::departement()` | `TerritoireDuMagasinTest::test_une_livraison_dans_le_departement_d_un_magasin_sans_code_postal_reste_taxee` |
| NIC corrigé pendant une panne vers un autre territoire (relecture) | territoire comparé à la reprise | `TerritoireDuMagasinTest::test_un_nic_corrige_pendant_une_panne_vers_un_autre_territoire_ne_se_confirme_pas_a_la_reprise` |
| `territoire` refusé par le journal d'audit (relecture) | `ActionAuditee::champsAudites()` | les tests de création, de modification, de changement de SIRET et de réouverture de `TerritoireDuMagasinTest` |
| Vérificateur sur des données d'outre-mer (relecture : le seeder ne sème rien) | test nommé sur trois fuseaux | `IsolationDesCalendriersTest::test_le_verificateur_ne_signale_rien_pour_trois_magasins_de_trois_fuseaux` |
