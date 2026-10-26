# Lot T2 : horodatages sans ambiguïté aux changements d'heure

**Cahier des charges. Il prime sur le texte du lot T2 de `serie8-lots.js`. Les réponses de l'éditeur (`t2/reponses-t2.md`, reprises au § 12) priment sur lui.**

> **Le lot T2 vient après FA1, FA2 et FA3.** Le code aura changé entre-temps. Les numéros de ligne, les nombres et les listes de fichiers de ce document sont **indicatifs** : ils ont été relevés sur la copie figée de HEAD 6b5baba. La première tâche du lot est de refaire la mesure (§ 1.2) et les relevés (§ 4) sur son propre HEAD. Un constat qui a disparu est dit dans le compte rendu ; un site nouveau est traité selon les mêmes règles.

Sources de la conception (dossier de travail `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/`) : la carte des horodatages (`t2carte/`), l'approche A (`t2app1/`), l'approche B (`t2app2/`) et deux jugements croisés (A : 15/20 et 15/20 ; B : 14/20 et 12/20). Les sondes jetables y sont gardées.

Avant d'écrire du code, le lot lit : CLAUDE.md, `.ai/rules/index.md` et les règles qui couvrent les fichiers touchés, le skill `testing-best-practices`.

---

## 0. En bref

- **Décision : tout en UTC.** Le fuseau de l'application devient UTC. Chaque instant s'enregistre, se compare et se calcule en UTC. Un jour civil est une chaîne « AAAA-MM-JJ » de son calendrier. Une seule classe, `App\Domain\Calendrier\Calendrier`, connaît un fuseau. Un calendrier porte le sien ; dans T2, tous les magasins ont celui de Paris (`Calendrier::deParis()`).
- **Ce qui ne change pas :** le contenu signé (octet pour octet), `ModuleFiscal::VERSION`, les règles métier, les horaires du planificateur (heure de Paris), le schéma des colonnes (aucun changement de type). Seul ajout, décidé par l'éditeur : la mention de l'heure répétée dans les journaux (Q3, § 3.5).
- **Ce qui est corrigé :** les 10 tests qui échouent pendant l'heure répétée, les défauts de production D1 à D5, les mécanismes M1, M2 et M3, les valeurs par défaut `useCurrent()` et la colonne `verifications_societe.expire_le` sans cast.
- **Greffes reprises de l'approche B et des jugements :** cast `Instant` strict, plus aucune heure par défaut en base, test de garde du schéma, fuseau fixé aussi au niveau de la base, garde de l'horloge des tests, fichiers temporaires datés depuis l'horloge figée, test de référence du contenu signé, `cloturee_a` aligné sur l'instant fiscal, bornes de jour demi-ouvertes, règle statique complétée.
- **Chaque commit garde le stockage d'un seul fuseau :** un instant rendu par `Calendrier` ou par les aides des tests est au fuseau de l'application (Paris avant la bascule, UTC après) ; les heures écrites dans les tests portent leur fuseau dès le commit 1, pour qu'aucune ne se décale en silence à la bascule.
- **Réponses de l'éditeur (§ 12) :**
  - Q2 confirme le comportement actuel : un délai en jours garde la même heure n jours plus tard.
  - Q3 entre dans T2 : le journal des événements lisible et le journal d'audit ajoutent « (heure d'été) » ou « (heure d'hiver) » à une heure de 02:00 à 02:59 le dernier dimanche d'octobre. Rien d'autre ne change à l'affichage.
  - Q1 (heure locale de chaque magasin) se fait dans le lot T3, juste après T2. T2 garde l'heure de Paris pour tous les magasins. Sa classe `Calendrier` reçoit déjà son fuseau par son constructeur, lu à un seul endroit : T3 n'aura qu'à lui donner celui d'un magasin, sans la réécrire ni défaire T2 (§ 3.10).

---

## 1. Constat et reproduction

### 1.1 La cause

- `config/app.php` fixe le fuseau `Europe/Paris`.
- Les colonnes d'horodatage enregistrent l'heure de Paris **sans décalage** : `timestamp(0) without time zone` sous PostgreSQL, texte sous SQLite.
- Seuls les horodatages fiscaux passent par le cast `HorodatageUtc` (UTC) : factures, avoirs, avoirs fournisseur, `factures_achat.date_validation`, registre fiscal, journal d'audit, traces, consultations VIES, `codes_confirmation.conserver_jusqu_au`.
- Le dernier dimanche d'octobre, 02:00 à 03:00 se répète à Paris : de 00:00 à 01:00 UTC (premier passage, +02:00), puis de 01:00 à 02:00 UTC (second passage, +01:00). Le même texte « 2026-10-25 02:30:00 » désigne alors deux instants.

### 1.2 Reproduction (suite SQLite, `php artisan test --parallel`, horloge système en UTC)

| faketime (UTC) | Paris | Résultat sur 6b5baba |
|---|---|---|
| `@2026-10-25 00:30:00` | 02:30, premier passage | code 2 : 8 échecs et 1 erreur |
| idem avec `NO_FAKE_STAT=1` | idem | code 2 : 9 échecs et 1 erreur |
| `@2026-10-25 01:30:00` | 02:30, second passage | code 0 |
| `@2027-03-28 01:30:00` | 03:30, juste après le saut | code 0 |
| `@2026-10-25 00:58:30` avec `NO_FAKE_STAT=1` | traverse 01:00 UTC | code 0 (les tests concernés tournent après 01:00 UTC) |
| `@2027-03-28 00:58:30` avec `NO_FAKE_STAT=1` | traverse le saut | 1 échec sans lien avec le fuseau : `VerificationSocieteTest` (§ 6.3) |

Sur l'horloge réelle, le 25/10/2026 entre 00:00 et 01:00 UTC, 8 tests ont échoué. Les 2 autres n'ont simplement pas tourné dans l'heure.

`NO_FAKE_STAT=1` : par défaut, faketime décale aussi les dates des fichiers. `EffacerPiecesVerificationTest` cale l'horloge sur la date d'un fichier : faketime la sort de l'heure répétée et cache l'échec vu sur l'horloge réelle. Avec `NO_FAKE_STAT=1`, l'échec réapparaît.

**Mesure à refaire au début du lot**, sur son HEAD :

```sh
TZ=UTC NO_FAKE_STAT=1 faketime -f '@2026-10-25 00:30:00' php artisan test --parallel; echo $?
```

### 1.3 Les 10 tests en échec et leur cause

Cause M1 (relecture décalée), 7 tests. Ils appellent `CloreLInscriptionAction::cloreSansCode($societe, now())`. `Societe.created_at`, écrit au premier passage, se relit une heure plus tard. La comparaison `created_at->greaterThan($limite)` refuse la clôture. Le SIREN et l'adresse ne sont pas libérés.

- `InscriptionSocieteTest::test_une_inscription_close_ou_une_societe_revoquee_libere_le_siren` (jeu « inscription close sans code »)
- `InscriptionSocieteTest::test_l_adresse_d_une_inscription_close_recoit_le_lien_d_une_nouvelle_inscription`
- `ConnexionParIdentifiantTest::test_une_adresse_en_attente_ou_d_une_inscription_close_coute_comme_une_adresse_inconnue`
- `TransfererProprieteTest::test_la_propriete_d_une_inscription_close_ne_se_transmet_plus`
- `AdresseDeConnexionDuProprietaireTest::test_une_adresse_en_attente_ne_se_confirme_pas_dans_une_inscription_close`
- `AdresseDeConnexionDuProprietaireTest::test_une_nouvelle_inscription_avec_l_adresse_d_une_inscription_close_reussit`
- `AdresseDeConnexionDuProprietaireTest::test_le_proprietaire_d_une_inscription_close_ne_demande_plus_d_adresse_de_connexion`

Cause M3 (horloge figée refaite par Carbon), 3 tests :

- `InscriptionSocieteTest::test_le_quatrieme_essai_dans_l_heure_pour_un_meme_siren_et_une_meme_adresse_est_refuse`
- `LimitationDesConnexionsTest::test_trop_d_echecs_pour_un_identifiant_et_une_adresse_ip_font_attendre_de_plus_en_plus`
- `EffacerPiecesVerificationTest::test_les_envois_abandonnes_et_les_dossiers_d_examen_restes_s_effacent_apres_une_heure`

Les deux premiers partent de l'heure réelle par `travel()` et dépassent la fenêtre d'une heure. Le troisième fige l'horloge sur la date d'un fichier, et l'horloge saute d'une heure.

`TestCase::vendeurDeLaPremiereHeure()` a le même défaut (`travelTo($maintenant)` remet l'heure réelle), sans échec à ce jour.

### 1.4 Trois mécanismes (PHP 8.3.6, Carbon 3.14.2, Laravel 13.34)

- **M1, relecture décalée.** Une heure ambiguë se relit toujours au second passage (+01:00). Le cast `datetime` de Laravel lit par `Date::createFromFormat` (`HasAttributes::asDateTime()`). Tout ce qui est écrit au premier passage se relit une heure trop tard.
- **M2, la base compare des heures murales.** Pendant le second passage, l'heure murale recule. « 02:50 » (plus ancien) passe pour plus récent que « 02:10 ». L'erreur va jusqu'à une heure, dans les deux sens. Laravel met en forme une date liée à une requête dans le fuseau de l'objet (`Connection::prepareBindings`).
- **M3, défaut propre aux tests.** Carbon reconstruit l'instant figé depuis son heure murale (`Carbon\Traits\Test`, `MOCK_DATETIME_FORMAT`). Tout `travel()`, `travelTo()` ou `setTestNow()` au premier passage fait sauter `now()` d'une heure. `now('UTC')` reste juste. `now('Europe/Paris')` saute aussi.

### 1.5 Défauts réels en production

- **D1. Liens et codes à durée limitée : une heure de plus ou de moins.**
  - Un lien qui expire au premier passage reste accepté en base pendant le second passage.
  - Un lien qui expire au second passage est refusé pendant le premier.
  - Un lien écrit au premier passage et relu en PHP vit une heure de plus.
  - Touchés : lien d'inscription (24 h), transmission de la propriété (24 h), nouvelle adresse de connexion (24 h), codes du courrier (30 jours).
  - Cas le plus net : le lien « Mot de passe oublié » de 60 minutes, créé au premier passage, vaut 120 minutes (`JetonsDuLien::verifier`). Le délai de 60 s entre deux demandes passe à près d'une heure (`DatabaseTokenRepository`).
- **D2. Accès redonné non signalé** (`SuiviDesConnexions::noterLaConnexion`). Connexion au premier passage, accès redonné au second : le message n'apparaît pas. CLAUDE.md exige que tout renouvellement s'affiche à la personne.
- **D3. Devis non envoyé** (`EnvoyerDocumentJob::devisModifieDepuisLaDemande`). Un devis émis au premier passage, dont l'envoi est demandé au second passage, est cru modifié : l'e-mail ne part pas. L'inverse existe : une vieille demande peut envoyer la nouvelle version. (Si FA3 a remplacé cette comparaison de dates, le test de régression de D3 reste exigé.)
- **D4. Toute l'année : démonstrations fermées une ou deux heures trop tôt** (`FermerDemonstrationsInactives`). `max('horodatage')`, texte UTC, est lu comme l'heure de Paris.
- **D5. Toute l'année : mauvais jour pour « Dernier achat »** (`SelecteurDeClients`). Le maximum brut de `date_emission` (UTC) s'affiche à la veille pour une vente faite entre minuit et 1 h (hiver) ou 2 h (été) à Paris.
- **Mineurs, au plus une heure, en octobre :** clôture d'une inscription sans code, date d'un nouveau courrier et de la clôture prévue (`CourriersDeVerification`), purges (`PurgerDemandesInscription`, `PurgerActionsTraitees`), affichages et tris d'heures.
- **Latents :** dix valeurs par défaut `useCurrent()` (l'horloge de la base est en UTC, l'application écrit l'heure de Paris) ; `verifications_societe.expire_le` sans cast ; `periodes_activite` en heure locale (facturation de l'étape 12) ; une future tâche planifiée entre 02:00 et 03:00.
- **Heure sautée de mars :** aucun défaut de production trouvé.

### 1.6 Ce qui est déjà solide et doit le rester

- L'horodatage signé « AAAAMMJJHHMMSSZ » (`InstantFiscal`, `RegistreFiscal`) et le contrôle d'une horloge qui recule.
- Les dates ISO avec décalage du contenu signé (`ContenuFiscal`).
- Le vérificateur d'intégrité (comparaisons de textes UTC bruts).
- Le journal d'audit (empreinte sur le texte UTC).
- Les purges par déclencheur (`DeclencheursDeProtection`, heure UTC du serveur de base).
- Les limiteurs, sessions, file d'attente et jetons Livewire (horodatages Unix).

---

## 2. Approche retenue et pourquoi

### 2.1 Principe

1. **Un instant** est un `CarbonImmutable` en UTC. Il s'écrit en base en UTC (« AAAA-MM-JJ HH:MM:SS »), se compare en UTC (PHP et base) et se calcule en UTC.
2. **Un jour civil** est une chaîne « AAAA-MM-JJ », un jour du calendrier qui l'a donné : celui de Paris pour tous les magasins dans T2. Il ne se convertit jamais d'un fuseau à un autre.
3. **Seul `Calendrier` connaît un fuseau.** Un calendrier porte le sien (§ 3.3) ; dans T2, le seul est celui de Paris, `Calendrier::deParis()`. Il rend des instants, des jours, des années ou du texte. Un instant qu'il rend est au fuseau de l'application (`date_default_timezone_get()`), comme `now()` : l'UTC une fois la bascule faite (l'invariant l'exige), l'heure de Paris avant, celle des colonnes d'alors (§ 9). Après la bascule, il ne rend donc jamais un objet au fuseau de Paris.
4. **L'affichage** passe par `Calendrier` (PHP, Blade, PDF, e-mails). Filament est réglé une fois. Les journaux ajoutent la mention de l'heure répétée (Q3, § 3.5).
5. **Des garde-fous** rendent un oubli bruyant : invariant au démarrage, casts stricts, connexion qui lie en UTC, test de garde du schéma, règle statique, tests figés la nuit.

### 2.2 Pourquoi l'approche A plutôt que B

L'approche B gardait Paris dans l'application et l'UTC en base. Elle est écartée :

- elle remplace quatre méthodes internes d'Eloquent sur chacun des 51 modèles, plus la connexion, le courtier des mots de passe, les notifications et `Wormhole` : chaque montée de Laravel devient un risque ;
- la séparation Paris/UTC devient permanente : toute lecture brute d'un instant (`DB::table`, `value`, `pluck`, `max`) se trompe d'une ou deux heures **toute l'année**, en silence. Or CLAUDE.md impose des requêtes `DB::table` explicites pour les lectures hors magasin ou hors société ;
- les modèles des paquets (Sanctum à l'étape 10, abonnements à l'étape 12) écriraient l'heure de Paris pendant que la connexion convertit en UTC : stockage mixte ;
- le premier passage de l'heure répétée ne peut jamais être figé dans un test (M3) ;
- l'heure locale de chaque magasin (Q1, décidée par l'éditeur, lot T3) y serait très coûteuse.

L'approche A est retenue :

- **la cause disparaît** : une seule horloge, l'UTC, en PHP comme en base ; M1, M2 et M3 disparaissent au lieu d'être contournés ;
- **tout instant se fige dans un test**, premier passage compris : c'est la seule approche qui teste vraiment l'heure répétée, comme l'exige la puce Outillage ;
- **le comportement par défaut de Laravel et des paquets devient juste** pour les instants : courtier des mots de passe, notifications, `failed_jobs`, lectures brutes (D4), agrégats (D5), futurs paquets ;
- **un seul endroit connaît un fuseau** : T3 (Q1) n'aura qu'à donner à `Calendrier` le fuseau d'un magasin (§ 3.10) ;
- **`JourCivil` en chaîne sépare les types** : un jour ne peut plus être lié comme un instant par erreur.

Risque principal de A : une conversion oubliée se trompe **chaque nuit**, entre minuit et 1 h (hiver) ou 2 h (été) à Paris, sur la journée de caisse, la date imprimée ou l'année d'un numéro. Parades : deux types distincts, la règle statique (§ 3.9), les tests figés la nuit (§ 6.2), l'ordre des commits (§ 9) et les attentes figées du contenu signé posées avant la bascule.

### 2.3 Greffes (reprises de l'approche B et des jugements)

1. **Plus aucune heure par défaut en base.** Les dix `useCurrent()` sont retirés. Un oubli échoue sur NOT NULL au lieu de passer en silence.
2. **Cast `Instant` strict.** Il n'accepte qu'un `DateTimeInterface` (ramené en UTC) ou `null`. Une chaîne est refusée par une exception.
3. **Test de garde du schéma** (§ 6.2, `HorodatageEnUtcTest`).
4. **Fuseau fixé deux fois pour PostgreSQL** : `'timezone' => 'UTC'` sur la connexion, et en production `ALTER DATABASE … SET timezone TO 'UTC'` (README). Aucun code de l'application ne dépend du fuseau de la session (§ 11, règle 11). Le réglage de la base sert aux sessions ouvertes hors de l'application (psql, sauvegardes, scripts, répartiteur de connexions qui perd le `SET`). `caisse:diagnostiquer` vérifie ce fuseau par défaut de la base, lu dans `pg_settings.reset_val`, et jamais par `SHOW TIMEZONE` : Laravel exécute `set time zone 'UTC'` à chaque connexion (`PostgresConnector::configureTimezone`), donc `SHOW TIMEZONE` rend toujours UTC.
5. **Garde de l'horloge des tests** : `travel()`, `freezeTime()` et `freezeSecond()` sont refusés tant que rien n'est figé. `travelTo()` ne part jamais de `now()` (règle statique), sauf la seule exception de l'horloge du serveur de base (§ 3.8).
6. **Fichiers temporaires et horloge figée** : une aide de `TestCase` fige l'horloge sur la date que relisent les fichiers. `NO_FAKE_STAT=1` sert alors à toute date.
7. **Scénarios de régression de D1 à D3** entre les deux passages, figés directement au premier passage.
8. **`cloturee_a` aligné sur l'instant fiscal** de la clôture (`CloturerCaisseAction`), au lieu d'un `now()` à part.
9. **Test de référence du contenu signé exact**, posé avant la bascule, de jour et de nuit.
10. **Bornes de jour demi-ouvertes** pour toute recherche d'une période de jours : [minuit du calendrier, minuit du lendemain). Seule exception, voulue : la borne d'un relevé de caisse personnelle (`jusqu_au`, `emisJusquA`) reste un instant **inclus**, la dernière seconde de la journée (§ 3.3).
11. **Règle statique complétée** : `whereDate`/`whereYear`/`whereMonth`, règles de validation `today`/`tomorrow`, `minDate`/`maxDate`, `->timezone(`, `::parse(` sans fuseau, fermetures `formatStateUsing` (couvertes par `->format(`). Elle porte aussi sur `database/factories` et `database/seeders`, et, dans `tests/`, sur toute heure écrite en littéral, les voyages en jours et les voyages partis de `now()` (§ 3.9).
12. **Agrégats d'instants** lus par `withCasts([... => Instant::class])` ou `Instant::depuisLaBase()`.
13. **Une seule lecture de l'horloge** pour la date et l'année d'un même geste (ferme la fenêtre de minuit le 1er janvier).

### 2.4 Ce que le lot ne fait pas

- Pas de `timestampTz` : SQLite n'en a pas, le texte lu dépendrait de la session, le vérificateur compare des textes bruts.
- Pas d'entiers Unix en base.
- Pas d'heure locale par magasin : c'est le lot T3 (Q1). Tous les magasins restent à l'heure de Paris. T2 n'ajoute pour T3 ni colonne, ni paramètre, ni fonction inutilisée. Il donne seulement à `Calendrier` une forme que T3 n'aura pas à réécrire : un objet qui porte son fuseau, créé à un seul endroit (§ 3.3, § 3.10).
- Pas d'autre changement d'affichage que la mention de l'heure répétée dans les journaux (Q3, § 3.5).
- Aucune nouvelle dépendance. Aucun changement de la CI.

---

## 3. Règles

### 3.1 Stockage

- `config('app.timezone')` vaut `'UTC'`, écrit en clair (pas de variable d'environnement). `AppServiceProvider::boot()` appelle `Calendrier::exigerUnFuseauUtc()`, qui lève une exception si `config('app.timezone')` ou `date_default_timezone_get()` n'est pas `UTC`.
- Les colonnes `timestamp()` et `dateTime()` gardent leur type (`timestamp(0) without time zone` sous PostgreSQL, texte sous SQLite). Elles contiennent l'heure UTC, à la seconde.
- Chaque colonne d'instant a le cast `Instant`, sauf `created_at`, `updated_at` et `deleted_at` quand les horodatages d'Eloquent les gèrent (son `freshTimestamp()` est en UTC) : un tel modèle ne les caste pas. Un modèle sans horodatages d'Eloquent (`$timestamps = false`, comme `EntreeAudit`) caste son `created_at` par `Instant`.
- `Instant` :
  - écriture : `null` ou un `DateTimeInterface`, ramené en UTC quel que soit son fuseau ; une chaîne lève `InvalidArgumentException` ;
  - lecture : un `CarbonImmutable` en UTC, lu strictement au format « Y-m-d H:i:s » ;
  - `Instant::enBase(DateTimeInterface): string` est la seule définition du format en base ;
  - `Instant::depuisLaBase(string): CarbonImmutable` relit un texte brut (agrégat, `DB::table`).
- Chaque colonne `date` a le cast `JourCivil` :
  - écriture : `null` ou une chaîne « AAAA-MM-JJ » valide (`checkdate`) ; un objet date ou une autre chaîne lève `InvalidArgumentException` ;
  - lecture : la chaîne « AAAA-MM-JJ » telle quelle (SQLite stocke désormais « AAAA-MM-JJ », plus « AAAA-MM-JJ 00:00:00 »).
- Aucune colonne n'a de valeur par défaut d'heure. L'application écrit chaque instant.
- La session PostgreSQL est en UTC (`config/database.php`, connexion `pgsql`, `'timezone' => 'UTC'`).
- `ConnexionSqlite` et `ConnexionPostgres` (trait commun) remplacent `prepareBindings` : tout `DateTimeInterface` lié à une requête s'écrit par `Instant::enBase()`. Elles s'inscrivent par `Connection::resolverFor()` dans `AppServiceProvider::register()`.
- Un objet `Carbon` modifiable devient `CarbonImmutable` sur les attributs passés à `Instant` : le lot vérifie qu'aucun code ne comptait sur une modification en place.

### 3.2 Comparaison

- Un instant se compare à un instant : en PHP par les méthodes de Carbon, en base en liant un **objet** date (jamais une chaîne mise en forme).
- Un jour se compare à un jour, en chaîne « AAAA-MM-JJ » (l'ordre du texte est l'ordre du calendrier), en base par `where()`.
- `whereDate`, `whereYear`, `whereMonth`, `whereDay`, `whereTime` sont interdits.
- Une période de jours sur une colonne d'instant se cherche par l'intervalle demi-ouvert `[Calendrier::deParis()->debutDuJour($du), Calendrier::deParis()->debutDuJour(Calendrier::ajouterJours($au, 1)))` (`>=` puis `<`). `Calendrier::deParis()->bornesDuJour($jour)` rend les deux bornes d'un jour.
- Exception : « jusqu'à cet instant compris » (`emisJusquA`, borne `jusqu_au` d'un relevé de caisse personnelle) reste inclusif. La fin d'une journée y est sa dernière seconde (§ 3.3), jamais le minuit du lendemain. Les instants sont enregistrés à la seconde : « ≤ dernière seconde » vaut donc exactement « < minuit du lendemain ».
- Aucune requête n'extrait une date en SQL (`DATE(`, `strftime`, `date_trunc`, `EXTRACT`, `::date`, `AT TIME ZONE`), sauf `DeclencheursDeProtection`, qui compare déjà en UTC.
- Un agrégat sur une colonne d'instant (`max`, `min`, `withMax`, `withMin`) se lit par `withCasts([... => Instant::class])` ou `Instant::depuisLaBase()`.
- Une requête réécrite garde son filtre de magasin ou de société (CLAUDE.md, règle 2).

### 3.3 Calendrier : `App\Domain\Calendrier\Calendrier`

Classe finale et immuable. **Un objet `Calendrier` porte son fuseau, et rien d'autre.**

- Son constructeur est privé. Il reçoit le nom du fuseau et le range dans une seule propriété, `private readonly string $fuseau`.
- Toute méthode qui passe d'un instant à un jour, à une heure murale ou à un texte lit cette propriété, et elle seule. Hors le constructeur, aucune méthode ne reçoit un fuseau en paramètre.
- Dans T2, une seule fabrique crée un calendrier : `Calendrier::deParis()`. C'est la seule à lire la constante privée `FUSEAU_DE_PARIS` (`'Europe/Paris'`), seule écriture de ce nom dans l'application. Tout site obtient son calendrier par elle. Elle crée un objet à chaque appel : aucun état partagé.
- Les calculs qui ne dépendent d'aucun fuseau (jours en chaînes, horodatage signé en UTC, invariant) sont des méthodes statiques. Ils valent pour tout calendrier.
- Un calendrier n'appelle jamais `now()` avec un fuseau (M3) : il convertit un instant par `setTimezone()`.

Cette forme n'ajoute rien d'inutilisé : la propriété, le constructeur et la fabrique servent à chaque appel. Elle n'est pas plus longue que des méthodes statiques. C'est elle qui permettra à T3 de donner à `Calendrier` le fuseau d'un magasin sans le réécrire (§ 3.10).

**Notation.** Dans ce cahier, une méthode citée sans préfixe est celle de `Calendrier` : statique quand le tableau le dit (`ajouterJours()`, `jourValide()`…), sinon celle du calendrier du site (`jourDe()`, `aujourdhui()`, `debutDuJour()`…), `Calendrier::deParis()` dans T2.

Un instant que rend un calendrier est au fuseau de l'application : `->setTimezone(date_default_timezone_get())` sur le résultat. Cette règle est permanente. Après la bascule, ce fuseau est l'UTC (invariant), et un calendrier ne rend jamais un objet au fuseau de Paris. Avant la bascule (commits 2 et 3), c'est l'heure de Paris, celle des colonnes `datetime` d'alors. Laravel met une date liée en forme dans le fuseau de l'objet (`Connection::prepareBindings`), et le cast `datetime` écrit l'objet dans son propre fuseau (`HasAttributes::fromDateTime`). Ainsi un instant rendu par `Calendrier` se lie et s'écrit exactement comme le `now()->startOfDay()` qu'il remplace : aucune borne décalée, aucun texte UTC dans une colonne encore à l'heure de Paris (`users.created_at` de `CreationDesComptes`, `cloturee_a` de `CaissesDeLaSociete`, `paniers_caisse.updated_at` de `PanierDeCaisse`, `periode_debut` écrit par `FermerSaCaisseAction`). Les onze colonnes `HorodatageUtc` convertissent elles-mêmes, quel que soit le fuseau de l'objet. `CalendrierTest` compare des instants (`getTimestamp()`), jamais leur fuseau.

| Membre | Rôle |
|---|---|
| `deParis(): self` (statique) | le calendrier de Paris ; seule fabrique de T2 |
| `fuseau(): string` | le nom du fuseau du calendrier : pour `schedule_timezone`, le `configureUsing` de Filament et les tests |
| `aujourdhui(): string` | le jour de `now()` |
| `jourDe(DateTimeInterface): string` | le jour d'un instant |
| `anneeDe(DateTimeInterface): string` | l'année d'un instant (« AAAA ») |
| `debutDuJour(string): CarbonImmutable` | l'instant du minuit de ce jour |
| `bornesDuJour(string): array{0: CarbonImmutable, 1: CarbonImmutable}` | [minuit, minuit du lendemain) : 23, 24 ou 25 heures à Paris |
| `decaler(DateTimeInterface, CarbonInterval): CarbonImmutable` | ajoute une durée en jours, mois ou années à la même heure murale (Q2) |
| `decalerEnMoisSansDebordement(DateTimeInterface, int): CarbonImmutable` | idem en mois, sans débordement de fin de mois (traces) |
| `afficher(DateTimeInterface, string): string` | un instant mis en forme dans le fuseau du calendrier, sans aucune mention |
| `afficherDansUnJournal(DateTimeInterface, string): string` | comme `afficher()`, suivi de « (heure d'été) » ou « (heure d'hiver) » pour une heure murale répétée (Q3, § 3.5) ; ajoutée au commit 5 |
| `iso8601(DateTimeInterface): string` | la date ISO 8601 avec le décalage du fuseau (contenu signé) |
| `heureMurale(string): CarbonImmutable` | une heure murale du calendrier (« AAAA-MM-JJ HH:MM[:SS] ») en instant ; refuse une heure inexistante (à Paris : 28/03/2027 à 02:30) ou répétée (25/10/2026 à 02:30) |
| `ajouterJours(string, int): string` (statique) | un jour plus ou moins n jours (calcul en jours entiers) |
| `joursEntre(string, string): int` (statique) | le nombre de jours entre deux jours |
| `premierJourDeLaSemaine(string)`, `premierJourDuMois(string)`, `premierJourDeLAnnee(string)` (statiques) | des jours |
| `jourDuMoisSuivant(string, int): string` (statique) | le n-ième jour du mois suivant (délai des preuves d'exonération) |
| `afficherJour(string, string): string` (statique) | un jour mis en forme |
| `horodatageSigne(DateTimeInterface): string` (statique) | « AAAAMMJJHHMMSSZ » en UTC (repris d'`InstantFiscal::pourSignature`) |
| `jourValide(string): ?string` (statique) | la chaîne si c'est un jour « AAAA-MM-JJ » valide, sinon `null` |
| `exigerUnFuseauUtc(): void` (statique) | l'invariant du démarrage |

`heureMurale()` et `afficherDansUnJournal()` partagent une seule méthode privée qui dit si une heure murale est répétée. Elle se déduit du fuseau du calendrier (ses transitions), jamais d'une date ou d'une heure écrite. Elle compare des instants en secondes UTC, jamais par `modify()` sur une date au fuseau de Paris : PHP décale ce calcul à tort autour de l'heure sautée (sondé sur cette machine : « -3600 seconds » sur 2027-03-28T01:00:00Z donne la même heure murale et ferait croire à une heure répétée). Les jeux de mars de `CalendrierTest` le vérifient.

Le lot peut fusionner ou renommer ces membres si le code de son HEAD le rend plus simple. Il ne crée aucun membre inutilisé. Il garde la forme : un objet qui porte son fuseau, une seule fabrique, aucun fuseau en paramètre, aucune autre lecture de `FUSEAU_DE_PARIS`.

Règles de calendrier :

- **Journée de caisse et minuit :** `jourDe($instant)`. Exact à chaque seconde, heure répétée et heure sautée comprises. Le 25/10/2026 va de 2026-10-24T22:00Z à 2026-10-25T23:00Z (25 h) ; le 28/03/2027 dure 23 h.
- **Refus d'une vente après clôture :** `jourDe($instant) <= derniere journée clôturée` (deux chaînes).
- **Clôture automatique et rattrapage :** le jour visé est calculé (`ajouterJours(aujourdhui(), -1)`), jamais déduit de l'heure du passage. `JourneesDeCaisse` parcourt des chaînes de jours.
- **Caisses personnelles fermées d'office :** la borne `jusqu_au` garde son sens actuel, un instant **inclus**. Pour la fin d'une journée de Paris, c'est sa dernière seconde, `Calendrier::deParis()->bornesDuJour($jour)[1]->subSecond()` (le 25/10/2026 : 2026-10-25T22:59:59Z), comme l'`endOfDay()` d'aujourd'hui (`ClotureAutomatiqueCaisse`, `CloturerLesJourneesAction::fermerLesCaissesPersonnelles`, qui garde `min(now(), …)`). Ce n'est jamais le minuit du lendemain. Sinon `emisJusquA` compterait un ticket émis à 00:00:00, `vendeursAvecCaisseOuverte` et `paiementsDuReleve` prendraient la journée du lendemain, et le relevé afficherait « → 26/10 00:00 ». La journée d'une borne est `jourDe($borne)`, et `emisJusquA` reste inclusif. Un relevé sans fermeture précédente commence à `debutDuJour(jourDe($borne))`.
- **Année des numéros :** `anneeDe($instant)` pour les huit séries (tickets, factures, avoirs, devis, factures d'achat, avoirs fournisseur, inventaires d'ouverture, clôtures). Une seule lecture de l'horloge par geste. Pour une clôture, les quatre premiers caractères de sa journée.
- **Échéance :** `ajouterJours(jourDe($emission), $delai)`. **Retard :** `$echeance < aujourdhui()`. Jours de retard et jours depuis la dernière relance : `joursEntre()`.
- **Validité d'un devis :** `$date_validite < aujourdhui()`.
- **Date d'un règlement (`recu_le`) :** elle garde sa règle actuelle, qui est une règle métier.
  - Sur une facture (`EnregistrerPaiementAction`, `EncaisserEnPlusieursModesAction`, sélecteurs de `PaiementResource` et `FactureResource`), c'est le jour choisi par la personne, jamais dans le futur. Le jour reçu passe d'abord par `Calendrier::jourValide()`. Le refus compare ensuite deux jours, `$recuLe > Calendrier::deParis()->aujourdhui()`, au lieu de `Carbon::parse(…)->startOfDay()->gt(today())`. Les sélecteurs prennent `default` et `maxDate` sur `Calendrier::deParis()->aujourdhui()`.
  - `jourDe($instant)` de la transaction ne vaut que là où le code le fait déjà : le règlement d'une vente à la caisse ou à l'émission (`EmettreFactureAction`) et la contre-passation (`ContrePasserPaiementAction`).
- **Mois de fin d'exercice :** tout calcul futur de date se fait en jours, par `Calendrier`.
- **Magasins d'outre-mer :** heure de Paris dans T2, comme aujourd'hui. Leur heure locale vient avec T3 (Q1, § 3.10).

### 3.4 Durées

- **En secondes, minutes ou heures** (liens de 24 h, lien « Mot de passe oublié » de 60 min, fenêtres des limiteurs, 48 h avant effacement) : arithmétique sur l'instant UTC (`addHours`, `addMinutes`). Durée exacte.
- **En jours, semaines, mois ou années** (code du courrier de 30 jours, nouveau courrier après 15 jours, clôture sans code à 30 jours, inactivité des démonstrations, jetons gardés 30 jours, conservation des traces et du journal d'audit) : `Calendrier::deParis()->decaler()` ou `decalerEnMoisSansDebordement()`. C'est le comportement actuel, confirmé par l'éditeur (Q2, § 12) : la même heure murale n jours plus tard (719 ou 721 h si un changement d'heure tombe dans la période). Un code envoyé le 20/10/2026 à 10:00 est « valable jusqu'au 19/11/2026 à 10:00 ».
- Chaque site garde exactement son calcul d'aujourd'hui (même unité, même règle de fin de mois). Le test de chaque délai le prouve à sa borne.

### 3.5 Affichage

- PHP, Blade, PDF, e-mails, lettre recommandée : `Calendrier::deParis()->afficher($instant, $format)` ou `afficherJour($jour, $format)`. Les formats affichés ne changent pas (« d/m/Y », « d/m/Y à H:i », etc.).
- Filament : `TextColumn` et `TextEntry` reçoivent `->timezone(Calendrier::deParis()->fuseau())` par `configureUsing`, dans `AppServiceProvider::boot()`, à côté des réglages existants. Aucun autre `->timezone(` dans l'application.
- `DatePicker` ne reçoit jamais de fuseau (avec celui de Paris, la date choisie reculerait d'un jour). Son `->default()`, `->minDate()` et `->maxDate()` prennent `Calendrier::deParis()->aujourdhui()` ou un jour.
- Une colonne de jour (cast `JourCivil`) s'affiche par la macro `jour(string $format = 'd/m/Y')` de `TextColumn` et `TextEntry`, qui passe par `Calendrier::afficherJour()`, sans aucun fuseau. Jamais par `->date()` ni par `->dateTime()`. Pourquoi : `->date()` relit le jour comme minuit dans le fuseau de l'application, puis le convertit au fuseau de la colonne (`vendor/filament/tables/src/Columns/Concerns/CanFormatState.php:65-67`, même code dans `infolists`). Un jour se convertirait donc d'un fuseau à un autre, ce que le § 2.1 interdit. À Paris, en avance sur l'UTC, le texte reste juste ; dans un fuseau en retard (T3, Antilles), il donnerait la veille (sondé avec le vendor de la copie, sous `date.timezone=UTC` : « 2026-12-16 » s'affiche « 15/12/2026 » en Martinique). À Paris, le texte affiché ne change pas : aucun test ne change.
- Un instant affiché à son seul jour (« Créé le », « Dernier achat ») passe par `->dateTime('d/m/Y')` : la conversion au fuseau de la colonne y est voulue. Même texte qu'avec `->date('d/m/Y')` aujourd'hui.
- Les journaux techniques de Laravel passent en UTC (README).
- Les libellés qui disent déjà « heure de Paris » (colonne du journal des événements, courrier du code) ne changent pas dans T2.

**Heure répétée dans les journaux (Q3, décision de l'éditeur, commit 5) :**

- `afficherDansUnJournal($instant, $format)` rend le texte d'`afficher($instant, $format)`, suivi de « (heure d'été) » ou « (heure d'hiver) » quand l'heure murale de l'instant est répétée dans le fuseau du calendrier. La mention dit l'heure d'été si l'instant est à l'heure d'été de ce fuseau, l'heure d'hiver sinon. À Paris, c'est exactement de 02:00:00 à 02:59:59 le dernier dimanche d'octobre : « 25/10/2026 02:30:00 (heure d'été) » pour 2026-10-25T00:30:00Z, « 25/10/2026 02:30:00 (heure d'hiver) » pour 01:30:00Z. L'heure sautée de mars n'existe pas : rien n'est ajouté.
- Elle sert aux journaux, et à eux seuls :
  - le journal des événements lisible : la colonne et la fiche `horodatage` d'`EnregistrementFiscalResource` (au lieu de `->dateTime(…)`), et la colonne lisible de l'export CSV d'`ExporterJournalDesEvenementsAction` ;
  - le journal d'audit : tout écran ou export qui le lit. Sur 6b5baba, il n'y en a aucun (sa consultation par le propriétaire viendra à l'étape 4) ; T2 n'en crée pas. Si le HEAD du lot en a un, il passe par `afficherDansUnJournal()`, et le test du journal le couvre aussi. Sinon, la règle 5 du § 11 l'impose au lot qui le créera.
- Rien ne change ailleurs : écrans (dont le widget `ConformiteApercu`, qui n'est pas le journal), PDF, e-mails, courriers et messages de vérification passent par `afficher()`, qui n'ajoute jamais de mention.
- La mention n'est qu'un affichage : elle n'entre dans aucun contenu signé, aucune colonne, aucun tri. La colonne signée de l'export (horodatage UTC) ne change pas.

### 3.6 Contenu signé, caisse sécurisée, journal d'audit

- **Aucun octet signé ne change. `ModuleFiscal::VERSION` ne change pas** (valeur du HEAD de départ).
- L'horodatage signé reste « AAAAMMJJHHMMSSZ » en UTC (`Calendrier::horodatageSigne`).
- `ContenuFiscal` :
  - les dates ISO (`date_emission` des factures et des avoirs, `consultee_le` des consultations VIES) passent par `Calendrier::deParis()->iso8601()` : même forme « …T02:30:00+02:00 » ou « +01:00 » qu'aujourd'hui ;
  - la date d'émission réduite à son jour (facture d'origine d'un avoir) passe par `Calendrier::deParis()->jourDe()` ;
  - `recu_le`, `livraison_date`, `date_echeance` sont des chaînes `JourCivil`, signées telles quelles.
- `InstantFiscal` : `enHeureLocale()` est supprimée, remplacée par `jour()` et `annee()` qui délèguent à `Calendrier`. `pourSignature()` délègue à `Calendrier::horodatageSigne()`.
- `RegistreFiscal` : l'instant reste en UTC. Jusqu'à la bascule, c'est `CarbonImmutable::now('UTC')->startOfSecond()`, toléré (§ 3.9). La bascule l'écrit `CarbonImmutable::now()->startOfSecond()`, en UTC par l'invariant, comme les deux autres `now('UTC')` de l'application : `ValiderFactureAchatAction` (`date_validation`) et `EmettreAvoirFournisseurAction` (`date_emission`). Le contrôle d'une horloge qui recule ne change pas. Son journal technique (`Log::critical`, instants en `toIso8601String()` UTC) reste en UTC (§ 3.5) : c'est une exception listée de la règle statique.
- `VerificateurIntegrite` : l'année vérifiée passe par `Calendrier::deParis()->anneeDe()`, l'affichage par `Calendrier::deParis()->afficher()`. Les comparaisons de textes UTC bruts ne changent pas.
- `JournalAudit` : l'empreinte passe par `Instant::enBase()` (même texte) ; `valeur()` réduit un instant à son jour par `Calendrier::deParis()->jourDe()` (le défaut mineur de la carte disparaît) ; `expire_le` par `Calendrier::deParis()->decaler()`.
- `ExporterJournalDesEvenementsAction` garde ses deux colonnes : l'heure de Paris lisible et l'horodatage UTC signé. Au commit 5, la colonne lisible passe par `afficherDansUnJournal()` (Q3, § 3.5) ; la colonne signée ne change pas.
- Le test de référence (§ 6.2, `ContenuSigneDeReferenceTest`) fige le contenu signé attendu, écrit en clair, **avant** la bascule. Il ne change pas après, sauf l'ajout des jeux du premier et du second passage.

### 3.7 Planificateur

- `config/app.php` : `'schedule_timezone' => Calendrier::deParis()->fuseau()` (la valeur se met en cache avec la configuration). Laravel la lit (`Foundation\Console\Kernel::scheduleTimezone()`). Les horaires de `bootstrap/app.php` restent à l'heure de Paris sans modification ; leurs commentaires renvoient à `schedule_timezone`.
- Aucune tâche quotidienne entre 02:00 et 02:59 (elle tournerait deux fois en octobre et jamais en mars). Les tâches `hourly()` restent permises.

### 3.8 Tests et horloge

- Toute heure écrite dans un test porte son fuseau : `TestCase::aParis('AAAA-MM-JJ HH:MM[:SS]')` (par `Calendrier::deParis()->heureMurale()`) ou `TestCase::enUtc('AAAA-MM-JJ HH:MM[:SS]')`. Comme `Calendrier`, ces deux aides rendent l'instant au fuseau de l'application (§ 3.3). Au commit 1, elles existent sans `Calendrier` et donnent le même instant. Elles refusent déjà une heure de Paris inexistante ou répétée.
- **Quel que soit son usage.** Sans `aParis()`/`enUtc()`, Carbon lit une heure écrite dans le fuseau par défaut (`travelTo('…')` passe par `Carbon::setTestNow()`, puis `Factory::make()`). À la bascule, elle deviendrait de l'UTC : une à deux heures plus tôt à Paris, la veille pour une heure avant 2 h. Le test passerait souvent encore, mais testerait une autre heure. Sont donc concernés :
  - l'argument de `travelTo()` ou de `setTestNow()` : 599 `travelTo('…')` dans 82 fichiers sur 6b5baba, dont 45 avant 2 h et 4 après 22 h ;
  - le paramètre d'une aide : par exemple `consultation($client, '2026-10-09 14:32:10')`, `ouvrirLaCaisseDUnMagasin('2026-10-09 19:00:00')`. L'aide reçoit désormais un instant ;
  - un attribut donné à une fabrique ;
  - un instant attendu : 98 heures écrites hors de `travelTo()` sur 6b5baba.
- **Instant attendu.** Il se compare comme instant (`equalTo()`, `getTimestamp()` ou une aide `assertMemeInstant()`), jamais au texte de `->format()` d'un attribut, qui passe en UTC à la bascule.
- **Texte brut en base.** Il s'écrit et s'attend par `HorodatageUtc::enBase($this->enUtc('…'))`, puis `Instant::enBase()` à la bascule. Sur 6b5baba, les 14 textes bruts écrits ou attendus en base visent tous des colonnes déjà en UTC (`date_emission`, `horodatage`, `dernier_horodatage`, `consultee_le`) : leur valeur ne change pas. `suspendu_le` de `ClientResourceTest`, à l'heure de Paris, est donné au modèle et non à la base (§ 6.3).
- **Ce qui reste écrit en clair.** Une heure avec son décalage (`Z`, `+02:00` : réponses VIES, contenu signé attendu) et un texte affiché attendu (« 09/10/2026 à 14:32 ») restent en clair : ils ne dépendent d'aucun fuseau par défaut.
- **Commit 1.** Ces réécritures se font au commit 1, sur l'heure de Paris d'alors. Elles sont neutres : chaque test garde exactement son instant. La bascule ne décale ainsi aucune entrée de test, sans compter sur le filet des tests qui échouent (§ 6.4).
- **Garde de l'horloge.** `TestCase` remplace `travel()`, `freezeTime()` et `freezeSecond()`. Chacun lève une `LogicException` tant que rien n'est figé (`Carbon::hasTestNow()` faux) : `freezeTime()` fige l'heure réelle (`InteractsWithTime::freezeTime()` appelle `travelTo(Carbon::now())`). `travelTo()` ne reçoit jamais un instant tiré de `now()` (`travelTo(now()…)`, `travelTo(Carbon::now()…)`, `travelTo(CarbonImmutable::now()…)`), ce que la règle statique vérifie. Aucun voyage ne part donc de l'heure réelle.
- **Seule exception : l'horloge du serveur de base.** `PurgerTracesTest` voyage depuis `maintenantSelonLaBase()`, parce que le déclencheur de purge compare à l'horloge du serveur de base (`now() AT TIME ZONE 'UTC'`), que faketime ne décale pas sous PostgreSQL. L'exception est listée dans la règle statique avec cette raison. Le texte du CLAUDE.md la nomme (§ 8.1).
- **Délais en jours.** Un test qui franchit un délai en jours voyage vers un instant écrit (`travelTo($this->aParis('…'))`), jamais par `travel(n)->day(s)()`. Après la bascule, ce serait des jours de 24 h en UTC : 14 occurrences sur 6b5baba.
- **Calcul en jours, semaines, mois ou années.** Un tel calcul sur un instant (`->addDays(`, `->subDay(`, `->subYear(`…, 31 occurrences dans 16 fichiers sur 6b5baba, dont 24 enchaînées sur `now()`) s'écrit par un instant écrit ou par `Calendrier::deParis()->decaler()`. Sur un jour, c'est `Calendrier::ajouterJours()`. L'instant attendu ne change pas.
- `TestCase::figerALHeureDesFichiers()` écrit un fichier témoin, lit sa date de modification et fige l'horloge à cet instant (UTC). Elle sert aux tests qui comparent l'horloge à des dates de fichiers (Livewire, pièces de vérification).
- `vendeurDeLaPremiereHeure()` remet l'horloge telle qu'elle était : figée au même instant si elle l'était, libre sinon.
- `MagasinFactory` ouvre la première période à `Calendrier::deParis()->heureMurale(DEBUT_D_ACTIVITE)` (même instant qu'aujourd'hui).
- `demarrerUnProcessusConcurrent()` passe l'instant figé en ISO 8601 avec son décalage ; `tests/Fixtures/processus-concurrent.php` le relit tel quel.

### 3.9 Règle statique (`ReglesDuCalendrierTest`)

Portée : `app/`, `resources/views/`, `database/factories/` et `database/seeders/`, hors `app/Domain/Calendrier/`. Les fabriques et les seeders suivent la règle complète : après la bascule, `now()->toDateString()` (`ClotureCaisseFactory`) ou `now()->addDays(30)->toDateString()` (`DevisFactory`) y donneraient le jour UTC, la veille de Paris la nuit. Chaque exception est listée dans le test avec sa raison (fichier et motif).

Dans `app/Domain/Calendrier/`, le test vérifie seulement que `'Europe/Paris'` n'y est écrit qu'une fois, dans la constante privée `FUSEAU_DE_PARIS`, et que seule `deParis()` la lit (§ 3.3, § 3.10).

| Interdit | Remplacement |
|---|---|
| `config('app.timezone')`, `'Europe/Paris'`, `date_default_timezone_set` | `Calendrier` |
| `setTimezone(`, `->tz(`, `shiftTimezone(`, `->timezone(` (hors `configureUsing` de `AppServiceProvider`) | `Calendrier::deParis()->afficher()`, `jourDe()` |
| `today(`, `now('…')`, `::now('…')` | `Calendrier::deParis()->aujourdhui()`, `now()` (UTC par l'invariant). `now('UTC')` est toléré jusqu'à la bascule, qui remplace ses trois sites (`RegistreFiscal`, `ValiderFactureAchatAction`, `EmettreAvoirFournisseurAction`) par `now()`. |
| `->format(`, `->translatedFormat(`, `->isoFormat(`, `->toDateString(`, `->toDateTimeString(`, `->toIso8601String(` | `Calendrier::deParis()->afficher()`, `jourDe()`, `iso8601()` (heure de Paris). Exceptions listées, journal technique et contenu jeté, en UTC (§ 3.5) : `->toIso8601String()` du `Log::critical` de `RegistreFiscal` (horloge qui recule) et du contenu d'essai de `DiagnostiquerCaisseSecurisee`, signé puis jeté. |
| `->startOf…(`, `->endOf…(`, `->isToday(`, `->isSameDay(`, `->isYesterday(`, `->isTomorrow(`, `->diffInDays(`, `->year`, `->month`, `->day` | `Calendrier` |
| `(add\|sub)(Day\|Days\|Week\|Weeks\|Month\|Months\|MonthsNoOverflow\|Year\|Years)(` | `Calendrier::deParis()->decaler()`, `ajouterJours()` |
| `::parse(`, `::createFromFormat(`, `::create(` sur une date, `->setDate(`, `->setDay(` | `Calendrier::deParis()->heureMurale()`, `Calendrier::jourValide()`, `Instant::depuisLaBase()` |
| `whereDate(`, `whereYear(`, `whereMonth(`, `whereDay(`, `whereTime(` | `where()` sur un jour, bornes demi-ouvertes |
| SQL `DATE(`, `strftime`, `date_trunc`, `EXTRACT(`, `::date`, `AT TIME ZONE` (hors `DeclencheursDeProtection`) | bornes calculées en PHP |
| règles de validation `:today`, `:tomorrow`, `:yesterday`, `:now` | règle avec `Calendrier::deParis()->aujourdhui()` |
| `minDate(` / `maxDate(` sans `Calendrier::` sur la ligne | `Calendrier::deParis()->aujourdhui()` |
| `->date(` (colonne ou entrée Filament) | `->jour()` pour un jour, `->dateTime('d/m/Y')` pour un instant (§ 3.5) |
| casts `'datetime'`, `'immutable_datetime'`, `'date'`, `'immutable_date'`, `HorodatageUtc` | `Instant::class`, `JourCivil::class` |
| `useCurrent(` dans `database/migrations` | valeur écrite par l'application |

Règle pour `tests/` (§ 3.8) :

- **Dès le commit 1 :**
  - une heure écrite en littéral (`'AAAA-MM-JJ HH:MM'` avec ou sans secondes, sans décalage) n'apparaît qu'en argument de `aParis(` ou `enUtc(`, quel que soit son usage (`travelTo(`, `setTestNow(`, `::parse(`, paramètre d'aide, attribut, attente) ;
  - pas de `travel(…)->day(`/`days(`/`week(`/`weeks(`/`month(`/`months(`/`year(`/`years(` ;
  - pas de `travelTo(` dont l'argument part de `now(` ou de `::now(` ;
  - pas de calcul `->(add|sub)(Day|Days|Week|Weeks|Month|Months|MonthsNoOverflow|Year|Years)(` sur un instant.
- **Dès le commit 2 :** pas de `config('app.timezone')`, de `'Europe/Paris'` en clair (utiliser `Calendrier::deParis()->fuseau()`), de `now('…')` ni de `today(` (73 occurrences sur 6b5baba).
- **Exceptions listées avec leur raison :**
  - une heure que le test fait lire au code comme le ferait un service externe ou un formulaire (texte saisi face à la règle de validation `before:`, `AppServiceProviderTest`) ;
  - une heure d'un jeu de données (`#[DataProvider]`) que la méthode de test passe à `aParis()`/`enUtc()` ;
  - `maintenantSelonLaBase()` de `PurgerTracesTest`.

Une heure avec son décalage et un texte affiché ne sont pas visés.

Le motif `->format(` vise presque uniquement des dates dans `app/` et les vues (77 occurrences relevées) : la règle est précise. Une autre méthode `format` légitime entre dans la liste d'exceptions.

### 3.10 Heure locale des magasins : ce que T2 laisse à T3 (Q1)

L'éditeur a décidé que chaque magasin suit l'heure locale de son territoire : journée de caisse et clôture à minuit local, date imprimée sur ses documents, année de ses numéros, échéances et toute logique de calendrier du magasin (§ 12, Q1). C'est le lot T3, juste après T2 (cahier des charges `t3/decision-heure-locale.md`).

Dans T2 :

- Tous les magasins restent à l'heure de Paris. Aucun test de T2 ne crée un magasin à une autre heure.
- T2 n'ajoute pour T3 ni colonne de fuseau, ni territoire, ni paramètre, ni fabrique d'un magasin, ni fonction qu'il n'appelle pas.
- **Le fuseau n'est lu qu'à un seul endroit** : `Calendrier::deParis()`, qui lit la constante privée `FUSEAU_DE_PARIS` et la passe au constructeur. Tout le reste de l'application obtient son calendrier par cette fabrique (§ 3.3).
- Aucune méthode de `Calendrier` ne dépend de Paris autrement que par sa propriété `$fuseau` : aucune date, heure ou décalage écrit en dur. La mention de l'heure répétée et le refus d'une heure inexistante ou répétée se déduisent des transitions du fuseau du calendrier.
- Les commentaires, docblocks et messages que T2 écrit disent « le jour du calendrier » ou « minuit du calendrier », jamais « de Paris » pour un magasin. Seuls `deParis()`, le planificateur, les tests qui figent une heure de Paris et les textes du § 8 nomment Paris.
- La règle statique (§ 3.9) vérifie que `'Europe/Paris'` n'est écrit qu'une fois, dans `FUSEAU_DE_PARIS`, et que seule `deParis()` lit cette constante.

Comment T3 donnera à `Calendrier` le fuseau d'un magasin, sans le réécrire :

1. Il ajoute à `Calendrier` une seconde fabrique, qui crée le calendrier d'un magasin depuis ce que T3 choisit : un territoire stocké sur le magasin (T3 § 1.2 montre que `TerritoireTva` ne suffit pas, car il range la Guadeloupe et la Martinique avec La Réunion). La table des fuseaux des territoires est dans `Calendrier`, seul endroit qui connaît un fuseau. Le constructeur, la propriété et les méthodes existantes ne changent pas.
2. Aux sites qui calculent pour un magasin, il remplace `Calendrier::deParis()` par le calendrier de ce magasin. Le site garde sa méthode, ses arguments, ses bornes et son test. T3 dit quels sites ne relèvent d'aucun magasin et gardent `deParis()`.
3. Il règle l'affichage de Filament sur le fuseau du magasin : le seul `configureUsing` du § 3.5 reçoit le fuseau du calendrier du magasin, par la même méthode `fuseau()`.
4. S'il change la planification des tâches du calendrier des magasins (clôture automatique et caisses personnelles, passage en retard, expiration des devis, relances), seules les lignes attendues de ces tâches suivent dans `PlanificateurTest`, et elles quittent la liste des tâches quotidiennes à l'heure de Paris ; T3 peut y ajouter un test. La structure du test reste.
5. Il revoit les libellés qui disent déjà « heure de Paris » (colonne du journal, courrier du code). T2 ne les a pas créés.
6. Il complète, dans CLAUDE.md, la proposition que T2 écrit sur l'heure des magasins (§ 8.1 : la règle de Q1, « mise en place par le lot T3 ») ; dans le README, il remplace la phrase qui décrit l'état laissé par T2 (tous les magasins à l'heure de Paris).
7. Pour un magasin, il donne aux règles 4, 5, 8 et 12 du § 11 (« le calendrier du site ») le calendrier de ce magasin.

Ce que T3 garde tel quel : le stockage en UTC, les casts `Instant` et `JourCivil`, les connexions, les migrations et leurs commentaires, les membres de `Calendrier` et `CalendrierTest`, la mention de l'heure répétée, l'affichage d'un jour par `jour()` (§ 3.5), les tests de T2 (ceux de Paris restent vrais pour un magasin de métropole), la règle statique (T3 y ajoute ses fuseaux ; les noms de l'UTC restent permis, règle 13 du § 11), `deParis()` et `FUSEAU_DE_PARIS`. Donner à un site le calendrier de son magasin complète T2 : rien de T2 n'est retiré ni inversé.

---

## 4. Fichiers et classes à changer

Liste indicative relevée sur 6b5baba. Le lot la recalcule par les motifs du § 3.9 sur son HEAD.

### 4.1 Nouveaux

- `app/Domain/Calendrier/Calendrier.php` (objet qui porte son fuseau, § 3.3)
- `app/Domain/Calendrier/Casts/Instant.php` (remplace `HorodatageUtc`)
- `app/Domain/Calendrier/Casts/JourCivil.php`
- `app/Domain/Calendrier/Connexions/LieLesDatesEnUtc.php` (trait), `ConnexionSqlite.php`, `ConnexionPostgres.php`
- Les tests du § 6.2.

`app/Domain/Calendrier/` est un sous-dossier du dossier existant `app/Domain` : pas de nouveau dossier de base.

### 4.2 Supprimés

- `app/Domain/CaisseSecurisee/Casts/HorodatageUtc.php` (ses 11 casts deviennent `Instant`, ses appels `::enBase` deviennent `Instant::enBase`).
- `InstantFiscal::enHeureLocale()`.
- Le paramètre de fuseau de lecture (`HorodatageUtc::class.':'.config('app.timezone')`) dans `HorodateSonEmission`, `FactureAchat`, `AvoirFournisseur`, `ConsultationVies`.
- Le cast `created_at` de `VerificationSociete`.

### 4.3 Configuration et démarrage

- `config/app.php` : `timezone` à `'UTC'`, `schedule_timezone`, commentaire réécrit (le calendrier et son fuseau sont dans `Calendrier` ; le planificateur suit l'heure de Paris).
- `config/database.php` : `'timezone' => 'UTC'` sur `pgsql`.
- `app/Providers/AppServiceProvider.php` : `resolverFor` des deux connexions (`register`), invariant, `configureUsing` de `TextColumn`/`TextEntry` et macro `jour()` de ces deux classes, à côté des macros existantes (`boot`), message de validation `:date` (ligne 235) par `Calendrier::deParis()->afficher()`.
- `bootstrap/app.php` : commentaires des horaires seulement.

### 4.4 Modèles (casts)

`Instant` ou `JourCivil` sur : `User` (6 instants), `Societe`, `Magasin`, `SiretMagasin`, `PeriodeActivite`, `DemandeInscription`, `CodeConfirmation`, `TransmissionPropriete`, `PosteReconnu`, `PieceVerification`, `VerificationSociete` (`expire_le` sans cast aujourd'hui), `MandatPdp`, `TraceAudit`, `EntreeAudit`, `Facture` (`date_emission` par le trait ; jours `date_echeance`, `livraison_date`), `Avoir` (trait), `Devis` (`date_emission` ; jours `date_validite`, `livraison_date`), `Paiement` (jour `recu_le`), `PreuveOperation` (jour `date_document`), `ClotureCaisse` (`cloturee_a` ; jour `date_cloture`), `ClotureCaissePersonnelle`, `EnvoiDocument`, `FactureAchat` (`date_validation` ; jour `date_facture`), `AvoirFournisseur`, `MouvementStock`, `InventaireOuverture`, `ConsultationVies`, `EnregistrementFiscal`, `TraceEvenement`, `TeteRegistreFiscal` (`dernier_horodatage` ; jour `derniere_journee_cloturee`), `TransmissionPdp`, `EvenementTransmissionPdp`.

Méthodes de modèle à reprendre : `Facture` (`jourDeLaPremiereVente`, `estEchue`, `joursDepuisLaDerniereRelance`), `Devis::estExpire`, `ClotureCaisse` (`refusDUneVente`, `derniereJourneeCloturee`, `scopeDuJour` sans `whereDate`), `ConsultationVies` (`scopeDuJour` reçoit un jour ; référence affichée), `EnregistrementFiscal` (portée par période), `TransmissionPropriete::estEnAttente`, `CodeConfirmation::estActif`, `HorodateSonEmission` (`emisLe(string)` et une période de jours en bornes demi-ouvertes ; `emisJusquA(instant)` inclusif ; `emisEntre` gardé seulement s'il sert encore), `TraceAudit` et `TraceEvenement` (`expire_le`).

### 4.5 Caisse sécurisée

`InstantFiscal`, `RegistreFiscal` (`Instant::enBase`, `now()` à la bascule), `ContenuFiscal`, `VerificateurIntegrite`, `ExporterJournalDesEvenementsAction` (sortie inchangée jusqu'au commit 5, qui ajoute la mention de l'heure répétée à la colonne lisible ; validation du jour saisi par `jourValide`), `JournalAudit` (Tenancy), `VerifierIntegriteFiscale` (`now()->day` par `Calendrier`), `DiagnostiquerCaisseSecurisee`.

`DiagnostiquerCaisseSecurisee` ajoute deux contrôles :
- le fuseau de l'application, `config('app.timezone')` et `date_default_timezone_get()`, qui doivent valoir `UTC` ;
- sous PostgreSQL, le fuseau par défaut de la base, `SELECT reset_val FROM pg_settings WHERE name = 'TimeZone'`. C'est ce que reçoit toute session avant le `SET` de Laravel : `ALTER DATABASE`, `ALTER ROLE`, `postgresql.conf` ou options de connexion. Il doit être un nom de l'UTC (`UTC`, `Etc/UTC`). `SHOW TIMEZONE` ne prouve rien : il rend la valeur que Laravel a fixée à la connexion. Sondé sur ce serveur : après `ALTER DATABASE … SET timezone TO 'Europe/Paris'` puis `SET TIME ZONE 'UTC'`, `current_setting('TimeZone')` vaut `UTC` et `reset_val` vaut `Europe/Paris`.

### 4.6 Facturation

- Actions :
  - `EmettreFactureAction` : année, échéance, `recu_le` = `jourDe($instant)` ;
  - `EmettreAvoirAction` : année ;
  - `EmettreDevisAction` : une lecture de l'horloge ;
  - `CloturerCaisseAction` : signature en jour « AAAA-MM-JJ », `cloturee_a` = instant fiscal ;
  - `CloturerLesJourneesAction` et `FermerLesCaissesPersonnellesAction` : borne inclusive de fin de journée, § 3.3 ;
  - `FermerSaCaisseAction` : journée de la borne par `jourDe`, début par `debutDuJour` ;
  - `EnregistrerPaiementAction` : refus d'un `recu_le` postérieur à `Calendrier::deParis()->aujourdhui()`, comparaison de deux jours ; le jour saisi est gardé ;
  - `EncaisserEnPlusieursModesAction` : `recu_le` saisi, inchangé ;
  - `ContrePasserPaiementAction` : `recu_le` = `jourDe($instant)` ;
  - `AjouterPreuveOperationAction`, `ExpirerLesDevisAction`.
- Services : `JourneesDeCaisse`, `PanierDeCaisse`, `CaissesDeLaSociete`, `ControleNatureOperation` (reçoit un jour), `EReportingDesParticuliers`, `PreuvesRequises` (`jourDuMoisSuivant`), `RecapitulatifDeCloture`, `VerificateurCaissesPersonnelles` (reçoit un jour).
- Job : `EnvoyerDocumentJob` (D3 ; selon ce que FA3 a laissé).

### 4.7 Achats et stock

`ValiderFactureAchatAction` et `EmettreAvoirFournisseurAction` (une lecture de l'horloge pour l'année et la date), `ValiderInventaireOuvertureAction` (idem).

### 4.8 Sociétés, comptes, vérification

`CloreLInscriptionAction`, `CourriersDeVerification`, `EnvoyerCodeDeConfirmationAction` (30 jours par `decaler`), `SaisirCodeDeConfirmationAction`, `SuivreLesEnvoisAction`, `ConfirmerNouvelleAdresseAction`, `DemanderUneNouvelleAdresseAction`, `DemanderLaTransmissionDeLaProprieteAction`, `AnnulerLaTransmissionDeLaProprieteAction`, `ConfirmerInscriptionController`, `DemandeInscriptionMail`, `CourrierDeCode`, `CreationDesComptes` (jour par `bornesDuJour`), `JetonsDuLien` (`Instant::depuisLaBase`), `PeriodesDActivite`, `SuiviDesConnexions` (D2 : corrigé par la bascule ; affichage par `Calendrier`).

### 4.9 Commandes planifiées

À changer :
- `ClotureAutomatiqueCaisse` : jour visé calculé, borne inclusive de fin de journée, § 3.3 ;
- `MarquerFacturesEnRetard`, `RelancerFacturesImpayees`, `CloreInscriptionsSansCode`, `PurgerActionsTraitees`, `PurgerDemandesInscription`, `PurgerTraces`, `PurgerJournalAudit`, `EffacerPiecesVerification` ;
- `FermerDemonstrationsInactives` : D4, `Instant::depuisLaBase(max('horodatage'))`.

Les autres commandes planifiées n'ont pas de calcul de date dans leur propre code, ou il est traité ailleurs :
- `ExpirerDevis` : par `ExpirerLesDevisAction`, § 4.6 ;
- `VerifierIntegriteFiscale` : § 4.5 ;
- `SuivreEnvoisRecommandes` : par `SuivreLesEnvoisAction`, § 4.8 ;
- `ReverifierSirene` : par `ReverificationSirene`, qui n'écrit que `now()` ;
- `VerifierJournalAudit`.

Leur planification est vérifiée comme les autres par `PlanificateurTest` (§ 6.2), qui couvre chaque tâche de `bootstrap/app.php`.

### 4.10 Filament

`EnregistrementFiscalResource` (et sa liste, son widget `ConformiteApercu` ; au commit 5, la colonne et la fiche `horodatage` passent par `afficherDansUnJournal()`, le widget reste sur `afficher()`), `ClotureCaisseResource` (liste, widgets `VentesEnAttenteApercu`, `CaissesPersonnellesOuvertesApercu`), `ListClotureCaissePersonnelles`, `ClotureCaissePersonnelleResource`, `EReportingResource`, `FactureResource` et `ViewFacture` (`maxDate(today())`), `PaiementResource` (`maxDate(today())`), `AvoirResource`, `InventaireOuvertureResource`, `FactureAchatResource`, `PosteReconnuResource`, `ModifierProfil`, `FacturesApercu` (semaine, mois, année), `EnvoieLesDocuments`, `SelecteurDeClients` (D5 : `withCasts`), `SelecteurDeProduits`, `LesCaisses`, `TableauDeBord`, `MembreResource`.

Colonnes et entrées en `->date(` sur 6b5baba (§ 3.5) :
- 13 jours, qui passent à `->jour()` : `FactureResource.php:212`, `:271`, `:436`, `:673` ; `DevisResource.php:108`, `:137` ; `ClotureCaisseResource.php:115`, `:330` ; `EReportingResource.php:198`, `:217` ; `PaiementResource.php:300` ; `FactureAchatResource.php:135`, `:200` ;
- 4 instants, qui passent à `->dateTime('d/m/Y')` : `SelecteurDeClients.php:120` (dernier achat), `:126` ; `SelecteurDeProduits.php:215` ; `ServiceResource.php:151`.

### 4.11 Vues, PDF, e-mails

`resources/views/devis/pdf.blade.php`, `factures/pdf.blade.php`, `avoirs/pdf.blade.php`, `documents/pdf/reglements.blade.php`, `filament/societe/pages/les-caisses.blade.php`, `filament/societe/pages/verification-societe.blade.php`, `filament/app/clients/consultations-vies.blade.php`, `filament/app/widgets/factures-a-encaisser-apercu.blade.php`, et toute vue d'e-mail qui affiche une date.

### 4.12 Intégrations

`app/Integrations/Sirene/InseeSirene.php` (`now('Europe/Paris')->toDateString()` devient `Calendrier::deParis()->aujourdhui()`), et toute lecture de date d'une réponse INPI ou Sirene (jour par `jourValide`).

### 4.13 Tests, fabriques, fixtures

- `tests/TestCase.php` : aides du § 3.8, garde de `travel()`, `freezeTime()` et `freezeSecond()`, `vendeurDeLaPremiereHeure`, `demarrerUnProcessusConcurrent` ;
- `tests/Fixtures/processus-concurrent.php` ;
- fabriques : `database/factories/MagasinFactory.php`, `ClotureCaisseFactory.php` (`date_cloture` = `Calendrier::deParis()->aujourdhui()`) et `DevisFactory.php` (`date_validite` = `Calendrier::ajouterJours(Calendrier::deParis()->aujourdhui(), 30)`). Toute autre fabrique ou tout seeder que la règle statique signale (§ 3.9) ;
- les tests du § 6.3 et du § 6.4.

---

## 5. Migrations de création à modifier sur place

Aucune migration nouvelle, aucun changement de type. Puis `php artisan migrate:fresh` sur les deux moteurs.

Retirer `useCurrent()` (l'application écrit la valeur) :

- `0001_01_01_000003_create_jobs_table.php` (`failed_jobs.failed_at` ; Laravel l'écrit déjà)
- `2026_10_02_100000_create_demandes_inscription_table.php`
- `2026_10_02_100010_create_traces_audit_table.php`
- `2026_10_02_100011_create_verifications_societe_table.php`
- `2026_10_02_200004_create_mouvements_stock_table.php`
- `2026_10_03_100006_create_evenements_transmission_pdp_table.php` (`survenu_le`)
- `2026_10_03_100007_create_preuves_operation_table.php`
- `2026_10_03_100008_create_bordereaux_detaxe_table.php`
- `2026_10_14_100002_create_enregistrements_fiscaux_table.php`
- `2026_10_14_100004_create_traces_evenements_table.php`

Pour chacune, vérifier que tout chemin d'insertion écrit la colonne (horodatage d'Eloquent ou valeur explicite) ; sinon l'ajouter.

Réécrire les commentaires qui parlent de fuseau (« en UTC », « heure de Paris », `HorodatageUtc`) :

- `2026_10_02_100008_create_journal_audit_table.php`
- `2026_10_02_100013_create_codes_confirmation_table.php`
- `2026_10_02_200006_create_consultations_vies_table.php`
- `2026_10_03_100003_create_factures_table.php`
- `2026_10_05_100001_create_avoirs_table.php`
- `2026_10_07_100001_create_factures_achat_table.php`
- `2026_10_07_100003_create_avoirs_fournisseur_table.php`
- `2026_10_14_100001_create_tetes_registre_fiscal_table.php`
- `2026_10_14_100002_create_enregistrements_fiscaux_table.php`
- `2026_10_14_100004_create_traces_evenements_table.php`

Règle des commentaires : « Instant en UTC (cast Instant) » ou « Jour civil (cast JourCivil) ». Un commentaire ne dit pas « de Paris » pour un jour de magasin : T3 n'aura aucun commentaire de migration à réécrire (§ 3.10).

---

## 6. Tests

### 6.1 Le test qui reproduit l'ambiguïté

`tests/Feature/Calendrier/HorodatageEnUtcTest.php` :

**`test_un_instant_enregistre_au_premier_passage_de_l_heure_repetee_se_relit_au_meme_instant`**, avec trois jeux :

- « attribut casté » : `DemandeInscription.expire_le` écrit avec l'instant 2026-10-25T00:30:00Z donné au fuseau de Paris, enregistré, relu par `fresh()` ;
- « horodatage d'Eloquent » : une `Societe` créée sous l'horloge figée à 2026-10-25T00:30:00Z, `created_at` relu ;
- « jeton Mot de passe oublié » : un jeton créé par le courtier de Laravel sous la même horloge, sa ligne relue.

Attente : l'instant relu vaut exactement 2026-10-25T00:30:00Z (`getTimestamp()` égal).

Il **échoue avant la correction** (relu à 01:30Z par M1, ou décalé par M3) et passe après. Le lot le lance sur le code juste avant le commit de bascule et cite l'échec dans le message de ce commit. Il ne peut pas être commité avant la bascule (chaque commit est vert).

Deux autres tests du même fichier échouent aussi avant la bascule, vérifiés de la même façon :

- `test_l_horloge_figee_au_premier_passage_de_l_heure_repetee_garde_son_instant` (M3) ;
- `test_la_base_compare_les_instants_des_deux_passages_dans_leur_ordre` (M2 : deux lignes à 00:50Z et 01:10Z ; `where(..., '<', 01:00Z)` ne rend que la première).

### 6.2 Tests nommés à écrire

Tous sur les deux moteurs, sauf mention. Noms indicatifs ; l'intention est exigée.

**`tests/Unit/Calendrier/CalendrierTest.php`** (aucune base) :

- `test_le_jour_de_paris_change_a_minuit_de_paris_en_ete_et_en_hiver` : jeux 2026-10-24T21:59:59Z / 22:00:00Z ; 2026-10-25T22:59:59Z / 23:00:00Z ; 2027-03-27T22:59:59Z / 23:00:00Z ; 2027-03-28T00:59:59Z / 01:00:00Z.
- `test_l_annee_de_paris_change_a_minuit_de_paris_le_31_decembre` : 2026-12-31T22:59:59Z donne 2026, 23:00:00Z donne 2027.
- `test_les_bornes_d_un_jour_font_25_heures_le_25_octobre_et_23_heures_le_28_mars`
- `test_les_deux_passages_de_l_heure_repetee_ont_le_meme_jour_et_des_instants_distincts`
- `test_une_heure_de_paris_inexistante_ou_repetee_est_refusee`
- `test_un_decalage_en_jours_garde_l_heure_de_paris_a_travers_un_changement_d_heure` (Q2 : comportement actuel, confirmé par l'éditeur) : 20/10/2026 à 10:00 à Paris plus 30 jours donne 19/11/2026 à 10:00 (721 h).
- `test_un_decalage_en_mois_ne_deborde_pas_en_fin_de_mois`
- `test_l_affichage_et_l_iso8601_portent_l_heure_et_le_decalage_de_paris` : 00:30Z donne « 02:30 » et « +02:00 » ; 01:30Z donne « 02:30 » et « +01:00 ». `afficher()` n'ajoute aucune mention (Q3).
- `test_l_horodatage_signe_est_en_utc`
- `test_les_jours_s_ajoutent_et_se_comptent_en_jours_entiers`
- `test_un_jour_mal_forme_n_est_pas_un_jour_valide`
- `test_une_heure_repetee_porte_dans_un_journal_la_mention_de_l_heure_d_ete_ou_d_hiver` (Q3, commit 5). Format « d/m/Y H:i:s ». Jeux avec mention : 2026-10-25T00:00:00Z donne « 25/10/2026 02:00:00 (heure d'été) » ; 00:59:59Z, « 25/10/2026 02:59:59 (heure d'été) » ; 01:00:00Z, « 25/10/2026 02:00:00 (heure d'hiver) » ; 01:59:59Z, « 25/10/2026 02:59:59 (heure d'hiver) » ; 2027-10-31T00:30:00Z, « 31/10/2027 02:30:00 (heure d'été) » (aucune date écrite dans le code). Jeux sans mention : 2026-10-24T23:59:59Z (« 25/10/2026 01:59:59 »), 2026-10-25T02:00:00Z (« 25/10/2026 03:00:00 »), 2026-10-18T00:30:00Z (un autre dimanche), 2027-03-28T00:59:59Z et 01:00:00Z (autour de l'heure sautée). Pour chaque jeu, `afficher()` au même format rend le texte sans mention.

**`tests/Feature/Calendrier/HorodatageEnUtcTest.php`** (stockage, garde-fous) :

- les trois tests du § 6.1 ;
- `test_un_instant_s_ecrit_en_utc_quel_que_soit_le_fuseau_de_la_date_donnee` (date de Paris, date à +05:00) ;
- `test_une_chaine_est_refusee_pour_un_instant` ;
- `test_un_jour_civil_s_ecrit_et_se_relit_tel_quel_et_refuse_un_objet_date` ;
- `test_une_date_liee_a_une_requete_est_comparee_en_utc` (constructeur de requêtes, `DB::table`, insertion directe) ;
- `test_un_agregat_sur_un_instant_se_relit_en_utc` (`withCasts`) ;
- `test_la_session_postgresql_est_en_utc` (sauté sous SQLite) ;
- `test_l_application_refuse_un_fuseau_autre_que_utc` ;
- `test_les_colonnes_d_horodatage_ont_leur_cast_et_aucune_valeur_par_defaut` : pour chaque table d'un modèle de `app/`, par `Schema::getColumns()` sur les deux moteurs, chaque colonne `timestamp`/`datetime` a le cast `Instant` (hors `created_at`, `updated_at`, `deleted_at` gérés par les horodatages d'Eloquent, qui ne sont jamais castés), chaque colonne `date` a `JourCivil`, aucune colonne d'heure n'a de valeur par défaut. Les tables de Laravel (`jobs`, `failed_jobs`, `job_batches`, `cache`, `sessions`, `password_reset_tokens`, `notifications`) figurent dans une liste d'exceptions avec leur raison, sauf pour la règle « aucune valeur par défaut », qui vaut pour toutes.
- `test_les_colonnes_et_les_fiches_affichent_les_instants_a_l_heure_de_paris` (fuseau de `TextColumn`/`TextEntry`).

**`tests/Feature/Calendrier/HeureRepeteeTest.php`** (régressions, figées directement au premier et au second passage) :

- `test_un_lien_mot_de_passe_oublie_cree_au_premier_passage_expire_apres_exactement_60_minutes` (D1) : valable à 01:29:59Z, refusé à 01:30:00Z ; une seconde demande refusée avant 60 s et acceptée après.
- `test_un_lien_d_inscription_demande_au_premier_passage_expire_24_heures_plus_tard` (D1, en base et en PHP).
- `test_un_lien_expire_au_premier_passage_est_refuse_pendant_le_second` (D1, M2).
- `test_une_transmission_de_la_propriete_et_une_nouvelle_adresse_expirent_a_leur_instant` (D1).
- `test_un_code_de_courrier_expire_trente_jours_apres_son_envoi_a_la_meme_heure_de_paris` (D1, Q2).
- `test_un_acces_redonne_apres_une_connexion_du_premier_passage_est_signale` (D2) : connexion à 00:50Z, accès redonné à 01:10Z, le message apparaît à la connexion suivante.
- `test_un_devis_emis_au_premier_passage_part_quand_l_envoi_est_demande_au_second` (D3) : émis à 00:50Z, demandé à 01:10Z, l'e-mail part.
- `test_une_demande_d_envoi_anterieure_a_une_nouvelle_emission_n_envoie_pas_la_nouvelle_version` (D3, cas inverse).
- `test_une_inscription_creee_au_premier_passage_se_clot_sans_code_et_libere_son_siren` (M1).
- `test_les_essais_de_connexion_du_premier_passage_comptent_dans_la_fenetre_d_une_heure` (M3).
- `test_une_vente_au_premier_passage_et_une_au_second_signent_leur_decalage_et_le_verificateur_ne_signale_rien`.
- `test_les_releves_et_les_journaux_se_trient_dans_l_ordre_reel_des_deux_passages`.
- `test_le_journal_des_evenements_dit_l_heure_d_ete_ou_d_hiver_pendant_l_heure_repetee` (Q3, commit 5). Deux événements du journal, par exemple deux tickets, écrits sous l'horloge figée à 2026-10-25T00:30:00Z puis 01:30:00Z. La liste du journal montre, dans l'ordre réel, « 25/10/2026 02:30:00 (heure d'été) » et « 25/10/2026 02:30:00 (heure d'hiver) » ; la fiche, « 25/10/2026 à 02:30:00 (heure d'été) » ; la colonne lisible de l'export CSV, la même chose que la liste, et sa colonne signée « 20261025003000Z » et « 20261025013000Z », sans changement. Le widget `ConformiteApercu` affiche la dernière vente sans mention. Si le HEAD a un écran ou un export du journal d'audit, le test le vérifie de la même façon (§ 3.5).

**`tests/Feature/Calendrier/NuitDeParisTest.php`** (jeux figés à 2026-07-14T22:30:00Z, été, et 2026-11-15T23:30:00Z, hiver ; plus 2026-12-31T23:30:00Z pour l'année) :

- `test_une_vente_apres_minuit_a_paris_appartient_a_la_journee_de_paris`
- `test_la_caisse_refuse_une_vente_apres_la_cloture_du_jour_de_paris`
- `test_la_cloture_automatique_clot_la_journee_de_paris_qui_vient_de_finir`
- `test_une_caisse_personnelle_fermee_d_office_couvre_la_journee_de_paris` : fermeture d'office du 25/10/2026 (25 h). Elle compte un ticket émis à 23:59:59 à Paris et un paiement reçu le 25/10. Elle ne compte ni un ticket émis le 26/10 à 00:00:00 à Paris (2026-10-25T23:00:00Z), ni un paiement reçu le 26/10. Sa borne `jusqu_au` vaut 2026-10-25T22:59:59Z, et le relevé affiche « → 25/10/2026 23:59 ».
- `test_un_paiement_recu_le_jour_de_paris_est_accepte_la_nuit` : à 22:30Z en été (00:30 à Paris), un `recu_le` du jour de Paris est accepté. Le lendemain de Paris est refusé comme futur.
- `test_l_echeance_et_le_retard_se_comptent_en_jours_de_paris`
- `test_les_relances_comptent_les_jours_depuis_la_derniere_relance_en_jours_de_paris`
- `test_un_devis_expire_le_lendemain_de_sa_validite_a_paris`
- `test_les_huit_series_de_numeros_prennent_l_annee_de_paris` (au 31/12 à 23:30Z : 2027)
- `test_la_consultation_vies_compte_pour_son_jour_de_paris`
- `test_le_delai_des_preuves_d_exoneration_se_compte_en_jours_de_paris`
- `test_les_apercus_de_la_semaine_du_mois_et_de_l_annee_commencent_a_minuit_de_paris`
- `test_le_filtre_du_journal_des_evenements_compte_les_jours_de_paris`
- `test_la_limite_de_comptes_crees_par_jour_compte_les_jours_de_paris`
- `test_l_e_reporting_et_les_periodes_d_activite_comptent_les_jours_de_paris`
- `test_le_pdf_d_une_vente_a_0_h_30_a_paris_porte_la_date_de_paris`
- `test_les_choix_de_date_proposent_le_jour_de_paris` (`DatePicker` : `default` et `maxDate`)
- `test_le_dernier_achat_d_un_client_s_affiche_au_jour_de_paris` (D5)
- `test_un_magasin_de_demonstration_se_ferme_apres_trente_jours_d_inactivite_exactement` (D4)

**`tests/Feature/Calendrier/PlanificateurTest.php`** :

- `test_chaque_tache_planifiee_a_son_horaire_attendu`. Il lit `Schedule::events()` et compare la liste entière (commande, arguments, expression, fuseau) à la liste attendue, dans les deux sens. Une tâche ajoutée sans son horaire attendu fait donc échouer le test. Sur 6b5baba :
  - quotidiennes : 00:00 `ClotureAutomatiqueCaisse`, 00:15 `MarquerFacturesEnRetard`, 00:30 `ExpirerDevis`, 03:00 `VerifierIntegriteFiscale`, 03:30 `VerifierJournalAudit`, 04:00 `PurgerTraces`, 04:15 `PurgerJournalAudit`, 04:30 `PurgerActionsTraitees`, 04:45 `FermerDemonstrationsInactives`, 09:00 `RelancerFacturesImpayees` ;
  - mensuelle : le 1er à 05:00 `ReverifierSirene --toutes` ;
  - horaires : `EffacerPiecesVerification`, `PurgerDemandesInscription`, `CloreInscriptionsSansCode`, `ReverifierSirene`, `SuivreEnvoisRecommandes`.
- `test_les_taches_quotidiennes_et_mensuelles_partent_a_l_heure_de_paris_en_ete_en_hiver_et_les_jours_de_changement_d_heure`. Chaque horaire quotidien est dû au bon instant UTC, une seule fois le 25/10 et le 28/03. La tâche mensuelle est due le 1er à 05:00 à Paris, en hiver (2027-01-01T04:00:00Z) et en été (2026-07-01T03:00:00Z). Les tâches horaires sont dues à chaque heure ronde, les deux passages de l'heure répétée compris.
- `test_aucune_tache_quotidienne_n_est_planifiee_entre_2_et_3_heures`

**`tests/Feature/Calendrier/ContenuSigneDeReferenceTest.php`** :

- `test_le_contenu_signe_d_un_ticket_d_une_facture_d_un_avoir_et_d_une_consultation_vies_reste_identique` : jeux 2026-10-09T08:00:00Z (jour), 2026-07-14T22:30:00Z (nuit d'été), 2026-11-15T23:30:00Z (nuit d'hiver), 2026-12-31T23:30:00Z (nouvel an), écrits au commit 1 ; puis 2026-10-25T00:30:00Z et 01:30:00Z ajoutés au commit de bascule. Le JSON signé attendu est écrit en clair.

**`tests/Feature/Calendrier/ReglesDuCalendrierTest.php`** (règle statique) :

- `test_seul_le_calendrier_connait_un_fuseau_et_met_les_dates_en_forme` (`app/`, vues, fabriques et seeders ; dans `app/Domain/Calendrier/`, `'Europe/Paris'` écrit une seule fois et lu par `deParis()` seule)
- `test_aucune_requete_n_extrait_une_date_en_sql_ni_ne_compare_par_where_date`
- `test_les_colonnes_de_date_ont_les_casts_du_calendrier_et_aucune_heure_par_defaut` (casts et `useCurrent` interdits, côté code)
- `test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite` (littéraux date-heure hors `aParis()`/`enUtc()`, quel que soit leur usage)
- `test_aucun_test_ne_voyage_en_jours_ni_ne_part_de_l_heure_reelle` (`travel(…)->days()`, calculs en jours sur un instant, `travelTo(now()…)` ; exception `maintenantSelonLaBase()` listée)
- `test_un_voyage_dans_le_temps_exige_une_horloge_figee` (la garde de `TestCase::travel()`, `freezeTime()` et `freezeSecond()`, chacune vérifiée)

**`tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php`** :

- `test_diagnostiquer_echoue_quand_le_fuseau_par_defaut_de_la_base_n_est_pas_utc`. Sous PostgreSQL, le test ouvre une connexion de même configuration avec `server_options` `['TimeZone' => Calendrier::deParis()->fuseau()]` (option de démarrage `-c TimeZone=…`), qui fixe le fuseau par défaut de la session comme le ferait `ALTER DATABASE`, sans toucher la base des tests. Il la rend connexion par défaut le temps de la commande, puis la restaure. Laravel y exécute toujours `set time zone 'UTC'`, ce qui prouve que le contrôle ne lit pas `SHOW TIMEZONE`. Sauté sous SQLite.
- `test_diagnostiquer_echoue_quand_le_fuseau_de_l_application_n_est_pas_utc`

### 6.3 Tests existants à figer

- **Les 10 tests du § 1.3** figent l'horloge à un instant écrit avec son fuseau, avant tout voyage. Les sept tests de la clôture sans code passent une limite construite depuis l'instant figé. Même nom, même intention. Le premier passage est couvert par `HeureRepeteeTest`.
- **`EffacerPiecesVerificationTest::test_les_envois_abandonnes_et_les_dossiers_d_examen_restes_s_effacent_apres_une_heure`** : `figerALHeureDesFichiers()`.
- **`VerificationSocieteTest::test_un_depot_accepte_ou_refuse_ne_laisse_aucun_fichier_temporaire`** : `figerALHeureDesFichiers()` avant l'envoi par Livewire (Livewire efface un envoi qu'il croit vieux de plus de 24 h ; sous `NO_FAKE_STAT=1` à une date lointaine, il effaçait le fichier).
- **`TestCase::vendeurDeLaPremiereHeure()`** (§ 3.8).
- **Les fichiers que la garde révélera.**
  - Sur 6b5baba, 13 fichiers utilisent `travel(` : `RegistreFiscalTest`, `ProceduresTest`, `ModifierProfilTest`, `MotDePasseOublieTest`, `FermerEtRouvrirMagasinTest`, `IdentifiantsTest`, `InscriptionSocieteTest`, `LimitationDesConnexionsTest`, `MagasinDeDemonstrationTest`, `MagasinResourceTest`, `MembresTest`, `TransfererProprieteTest`, `VerificationSocieteTest`. Ceux qui voyagent sans horloge figée figent d'abord.
  - `freezeTime()` sans horloge figée : `InalterabiliteTest` (setUp, l. 55), `EmissionSigneeTest` (l. 539, 561, 752), `RegistreFiscalTest` (l. 152). Ils figent à un instant écrit (`travelTo($this->aParis('…'))`).
  - `travelTo(now()->addDay())` de `SelecteurDeClientsTest` (l. 115) part de l'heure réelle : le test fige d'abord, puis voyage vers un instant écrit.
- **Les heures écrites des tests** (§ 3.8), au commit 1 :
  - 599 `travelTo('…')` dans 82 fichiers et 98 autres heures écrites (aides, attributs, attentes) passent par `aParis()`/`enUtc()` ;
  - les attentes comparées par `->format('Y-m-d H:i:s')` deviennent des comparaisons d'instants (par exemple `FermerSaCaisseActionTest`, `FermerEtRouvrirMagasinTest`, `ModifierDevisActionTest`, `EmettreFactureActionTest`, `AdresseDeConnexionDuProprietaireTest`, `PageDuLienTest`, `ModifierProfilTest`, `VerificationSocieteTest`) ;
  - les 14 `travel(n)->day(s)()` et les calculs en jours sur un instant deviennent des instants écrits.
- **`ClientResourceTest`** : `suspendu_le` donné en chaîne devient une date (le cast strict la refuse).
- **Tests qui construisent un instant attendu par `now()->addDays(…)`** à travers un changement d'heure : attente écrite en clair ou par `Calendrier::deParis()->decaler()` ; l'instant attendu ne change pas.

### 6.4 Filet de la bascule

Sur 6b5baba, la bascule naïve (fuseau UTC, rien d'autre) fait échouer 37 tests sur 3908. Ils exigent l'heure de Paris : minuit, 9 h, affichage, contenu signé, planificateur. Après la bascule complète, **ils passent avec leurs valeurs attendues inchangées**. Seules leurs entrées d'heure deviennent explicites (`aParis()`, `enUtc()`, `Calendrier::deParis()->fuseau()`). Liste en annexe A.

Ce filet ne voit que les tests qui échouent. Les autres sont protégés autrement : une heure écrite sans fuseau garderait souvent un test vert, mais à une autre heure. Toutes les heures écrites des tests reçoivent donc leur fuseau **au commit 1**, sur l'heure de Paris d'alors. Cette réécriture est neutre : chaque test garde son instant. La règle `test_les_tests_donnent_le_fuseau_de_chaque_heure_ecrite` la fait respecter dès ce commit. À la bascule, aucune entrée de test ne change donc d'instant.

Le lot le prouve de deux façons, dites dans les messages de commit :
- le diff du commit 1 ne touche aucune valeur attendue, et sa suite est verte ;
- le diff des tests au commit de bascule ne touche aucune valeur attendue ni aucune heure écrite.

### 6.5 Règles de réécriture

- Aucun test supprimé ni affaibli.
- Un test réécrit garde son nom et son intention ; le message de commit le nomme.
- Une valeur attendue ne change que si le comportement change. Ce lot ne change qu'un affichage, au commit 5 : la mention de l'heure répétée dans les journaux (Q3). Sur 6b5baba, aucun test existant n'affiche une heure d'un journal pendant l'heure répétée : aucune valeur attendue existante ne change. Si le HEAD du lot en a un, sa valeur attendue gagne la mention, et le message du commit 5 le nomme.

---

## 7. Preuves exigées

Chaque code de sortie est contrôlé juste après (`echo $?`), comme le veut CLAUDE.md.

1. `php artisan migrate:fresh` sur SQLite et sur PostgreSQL.
2. Suite SQLite complète : `php artisan test --parallel`, code 0.
3. Suite PostgreSQL complète (base décrite dans le README), code 0.
4. Suite SQLite complète sous faketime, chacune au code 0, avec `TZ=UTC NO_FAKE_STAT=1 faketime -f '@<instant>' php artisan test --parallel` :

   | Instant (UTC) | Origine | Ce qu'il éprouve |
   |---|---|---|
   | `2026-10-25 00:30:00` | T2 | premier passage de l'heure répétée |
   | `2026-10-25 01:30:00` | T2 | second passage |
   | `2027-03-28 00:59:00` | T2 et T1 | traverse le saut de mars |
   | `2027-03-28 01:30:00` | T2 | juste après le saut |
   | `2027-01-02 10:00:00` | T1 | nouvelle année, en journée |
   | `2026-12-31 22:59:00` | T1 | dernière minute de l'année à Paris |
   | `2026-10-25 00:59:00` | T1 | traverse la fin du premier passage |
   | `2028-02-29 12:00:00` | T1 | jour bissextile |
   | `2026-01-31 12:00:00` | T1 | fin de mois |
   | `2026-12-31 23:30:00` | ce cahier | 1er janvier à Paris, encore 2026 en UTC |
   | `2026-07-14 22:30:00` | ce cahier | nuit d'été : jour de Paris différent du jour UTC |
   | `2026-10-25 22:59:30` | ce cahier | traverse le minuit de Paris qui finit la journée de 25 h |

5. Suite PostgreSQL complète sous faketime, code 0, à `2026-10-25 00:30:00` et à `2026-12-31 23:30:00` (même forme de commande).
6. Les trois tests du § 6.1 échouent sur le code juste avant la bascule : sortie citée dans le message du commit de bascule.
7. Les tests de régression de D1 à D5 échouent sans leur correction (mutation ou retour arrière vérifié, dit dans le compte rendu).
8. La règle statique échoue sur une violation plantée, vérifié et dit pour chacune :
   - `->format('d/m/Y')` ajouté dans une action ;
   - `now()->toDateString()` dans une fabrique ;
   - `travelTo('2026-10-09 10:00:00')`, `travel(1)->days()` et `travelTo(now()->addHour())` dans un test.

   La garde de l'horloge lève son exception pour `travel()`, `freezeTime()` et `freezeSecond()` appelés sans horloge figée.
9. `ContenuSigneDeReferenceTest` : son JSON attendu est le même du commit 1 à la fin du lot (le diff n'ajoute que les jeux des deux passages).
10. Le filet du § 6.4 passe sans changer une valeur attendue. Le commit 1 rend explicites toutes les heures écrites des tests sans changer une valeur attendue, et le commit de bascule ne change aucune heure écrite d'un test.
11. `php artisan caisse:diagnostiquer` et `php artisan caisse:verifier` sans anomalie sur les deux moteurs, base migrée à neuf. Le seeder ne sème aucune donnée (`DatabaseSeeder::run()` n'affiche qu'un message) : sur cette base, `caisse:verifier` prouve seulement qu'il tourne sur le schéma ; sur des données, la preuve vient des tests qui lancent le vérificateur après des ventes et des clôtures (sur 6b5baba, `VerifierIntegriteFiscaleTest` et `MagasinDeDemonstrationTest` lancent `caisse:verifier` ; T2 ajoute `HeureRepeteeTest::test_une_vente_au_premier_passage_et_une_au_second_signent_leur_decalage_et_le_verificateur_ne_signale_rien`), passés sur les deux moteurs. Sous PostgreSQL, le fuseau par défaut de la base doit être UTC (`reset_val` vaut `Etc/UTC` sur le serveur de cette machine, sondé), sinon `ALTER DATABASE … SET timezone TO 'UTC'`, comme en production.
12. `vendor/bin/pint --dirty --format agent` avant chaque commit.
13. `caisse:diagnostiquer` échoue sous PostgreSQL quand le fuseau par défaut de la base n'est pas UTC (test du § 6.2), alors que `SHOW TIMEZONE` rend UTC dans la même session.
14. Q3 : les deux tests nommés de la mention (§ 6.2) échouent si `afficherDansUnJournal()` rend le texte d'`afficher()` sans mention (mutation vérifiée et dite dans le compte rendu). Le diff des tests du commit 5 n'ajoute que ces deux tests et ne change aucune valeur attendue existante (§ 6.5).
15. Préparation de T3 : `'Europe/Paris'` n'est écrit qu'une fois dans l'application, dans `FUSEAU_DE_PARIS`, lu seulement par `Calendrier::deParis()` ; hors son constructeur privé, aucune méthode de `Calendrier` ne reçoit un fuseau en paramètre (règle statique, § 3.9).

Avant le commit de bascule, au moins les instants `2026-10-25 00:30:00`, `2026-07-14 22:30:00` et `2026-12-31 23:30:00` sur SQLite. Avant le dernier commit du lot, toute la grille du point 4 et le point 5.

---

## 8. Textes de CLAUDE.md et du README

### 8.1 CLAUDE.md, puce « Outillage »

Remplacer la dernière phrase de la puce (« Aucun test ne dépend du hasard … aux changements d'heure. ») par :

> Les instants (horodatages) s'enregistrent, se comparent et se calculent en UTC, fuseau de l'application et de la session PostgreSQL, sans aucune heure par défaut en base ; un jour (échéance, journée de caisse, validité, date d'un règlement, clôture) est une date « AAAA-MM-JJ » de son calendrier ; seule la classe Calendrier connaît un fuseau : chaque calendrier porte le sien et donne le jour, l'année, minuit, les décalages en jours (la même heure murale n jours plus tard) et l'affichage dans ce fuseau, et un jour s'affiche tel quel, sans fuseau ; chaque magasin suit l'heure locale de son territoire (journée de caisse et clôture à minuit local, date de ses documents, année de ses numéros, échéances), mise en place par le lot T3, et jusque-là tous les magasins suivent l'heure de Paris ; le journal des événements et le journal d'audit ajoutent « (heure d'été) » ou « (heure d'hiver) » à une heure répétée au passage à l'heure d'hiver (à Paris, de 02:00 à 02:59 le dernier dimanche d'octobre), et aucun autre affichage ne le fait ; le planificateur suit l'heure de Paris, sans tâche quotidienne entre 2 h et 3 h ; le contenu signé garde ses dates avec le décalage de leur calendrier et son horodatage en UTC. Aucun test ne dépend du hasard ni de la date ou de l'heure réelles : Faker est semé par test, toute heure écrite dans un test porte son fuseau, toute attente liée au calendrier fige le temps à un instant écrit avec son fuseau, aucun voyage dans le temps ne part de l'heure réelle (sauf pour suivre l'horloge du serveur de base que lit un déclencheur), et la suite passe aussi sous faketime au changement d'année, aux changements d'heure et la nuit, quand le jour de Paris n'est pas celui de l'UTC (README).

Aucune autre partie de CLAUDE.md ne change. Les réponses de l'éditeur y entrent ainsi : Q2 (la même heure murale n jours plus tard), Q3 (la mention dans les deux journaux) et Q1 (l'heure locale de chaque magasin) dans ce texte. T2 écrit la règle de Q1 telle que l'éditeur l'a décidée, avec le lot qui la met en place ; il n'écrit aucune règle « Paris pour tous » que T3 devrait retirer. T3 retire seulement « mise en place par le lot T3, et jusque-là tous les magasins suivent l'heure de Paris » et précise la règle (T3 § 8.1).

### 8.2 README, section « Technique »

Ajouter après le paragraphe sur PostgreSQL :

> **Heure.** L'application travaille en UTC : chaque horodatage s'enregistre en UTC (`config/app.php`), et la session PostgreSQL aussi (`config/database.php`). Les journaux techniques de Laravel sont donc en UTC. Le calendrier (journée de caisse, échéances, année des numéros, affichage) suit l'heure de Paris pour tous les magasins, par la classe `App\Domain\Calendrier\Calendrier`, seule à connaître un fuseau, et le planificateur par `schedule_timezone`. Aucun code ne dépend du fuseau de la session de base. En production, fixez aussi le fuseau par défaut de la base, pour les sessions ouvertes hors de l'application (psql, sauvegardes, scripts, répartiteur de connexions qui perd le réglage de la session) : `ALTER DATABASE <base> SET timezone TO 'UTC';`. `php artisan caisse:diagnostiquer` vérifie le fuseau de l'application et ce fuseau par défaut de la base (`pg_settings.reset_val`). `SHOW TIMEZONE` ne le montre pas : il rend l'UTC que l'application fixe à chaque connexion.

Ajouter après les commandes des deux suites :

> **Suite sous faketime.** Avant un commit qui touche aux dates, la suite passe aussi à des instants choisis (`sudo apt-get install faketime`). `TZ=UTC` fixe le fuseau de l'instant donné à faketime ; `NO_FAKE_STAT=1` laisse aux fichiers leur vraie date, comme sur l'horloge réelle.
>
> ```sh
> for t in '2026-10-25 00:30:00' '2026-10-25 01:30:00' '2027-03-28 00:59:00' '2027-03-28 01:30:00' \
>          '2027-01-02 10:00:00' '2026-12-31 22:59:00' '2026-10-25 00:59:00' '2028-02-29 12:00:00' \
>          '2026-01-31 12:00:00' '2026-12-31 23:30:00' '2026-07-14 22:30:00' '2026-10-25 22:59:30'; do
>   TZ=UTC NO_FAKE_STAT=1 faketime -f "@$t" php artisan test --parallel; echo "$t : $?"
> done
> ```
>
> Chaque ligne doit finir par 0. Sous PostgreSQL, ajoutez les variables de la commande ci-dessus ; l'horloge du serveur de base n'est pas décalée, et les tests qui comparent une expiration à cette horloge la lisent (`TestCase::maintenantSelonLaBase()`).

---

## 9. Découpage en commits

Chaque commit est vert sur les deux moteurs (suites complètes, code 0) et passé par pint. Chaque message nomme les tests ajoutés et réécrits.

1. **Tests de référence et horloge des tests.** Aucun code de l'application ne change.
   - Tests posés : `ContenuSigneDeReferenceTest` (jour, nuits, nouvel an), les tests de `NuitDeParisTest` qui passent déjà sur le code actuel, `PlanificateurTest`.
   - Horloge : la garde de `travel()`, `freezeTime()` et `freezeSecond()` ; `aParis()`/`enUtc()`, qui rendent l'instant au fuseau de l'application, donc à l'heure de Paris d'alors ; `figerALHeureDesFichiers()` ; `vendeurDeLaPremiereHeure`.
   - Tests figés : les 10 tests du § 1.3 et les fichiers révélés par la garde.
   - Réécriture neutre de toutes les heures écrites des tests, des voyages en jours et des voyages partis de `now()` (§ 3.8, § 6.3).
   - `ReglesDuCalendrierTest` avec sa partie « tests » du commit 1 (§ 3.9).

   Après ce commit, la suite figée passe à 00:30Z. Les défauts de production restent, et les tests du § 6.1 et de `HeureRepeteeTest` les montreront.
2. **`Calendrier`.** La classe, sous sa forme du § 3.3 (objet qui porte son fuseau, seule fabrique `deParis()`), sans `afficherDansUnJournal()`, et `CalendrierTest`.
   - Tous les usages implicites du fuseau passent par elle (§ 3.9, sauf les casts), dans `app/`, les vues, les fabriques et les seeders. `configureUsing` de Filament.
   - Dans les tests : `today(`, `now('…')`, `'Europe/Paris'` et `config('app.timezone')` remplacés. Un jour s'y écrit en clair (« AAAA-MM-JJ ») ou par `Calendrier::deParis()->aujourdhui()`/`ajouterJours()`.
   - `ReglesDuCalendrierTest` étendu à ces portées, sur les motifs déjà remplacés.

   L'application est encore à l'heure de Paris. Les instants que rend `Calendrier` y sont aussi (§ 3.3) : chaque borne liée et chaque instant écrit l'est dans le fuseau de sa colonne, comme le code qu'il remplace. Aucun comportement ne change, ce que la suite complète, sans aucune valeur attendue modifiée, prouve.
3. **`JourCivil`.** Le cast sur les colonnes `date`, les appels sur ces attributs réécrits en chaînes, `whereDate` retiré, la macro `jour()` et les colonnes et entrées en `->date(` réécrites (§ 3.5, § 4.10), la règle statique étendue.
4. **Bascule.** `timezone` à UTC, `schedule_timezone`, session PostgreSQL, `Instant` (remplace `HorodatageUtc` et les casts `datetime`), connexions, invariant, migrations du § 5 puis `migrate:fresh`, `InstantFiscal`, D4, D5, `cloturee_a`, une lecture de l'horloge par geste. Tests : `HorodatageEnUtcTest`, `HeureRepeteeTest` (sauf le test de Q3), le reste de `NuitDeParisTest`, les jeux des deux passages de `ContenuSigneDeReferenceTest`, la règle statique complète. Ces éléments partent ensemble : séparés, le stockage serait mixte.
5. **Heure répétée dans les journaux (Q3).** `Calendrier::afficherDansUnJournal()` et sa méthode privée partagée avec `heureMurale()` (§ 3.3) ; la colonne et la fiche `horodatage` d'`EnregistrementFiscalResource`, la colonne lisible de l'export du journal des événements, et tout écran ou export du journal d'audit que le HEAD aurait (§ 3.5). Tests : `CalendrierTest::test_une_heure_repetee_porte_dans_un_journal_la_mention_de_l_heure_d_ete_ou_d_hiver` et `HeureRepeteeTest::test_le_journal_des_evenements_dit_l_heure_d_ete_ou_d_hiver_pendant_l_heure_repetee`.
   - C'est le seul commit qui change un affichage. Seul, il garde la preuve que les commits 1 à 4 ne changent aucune valeur attendue.
   - Il vient après la bascule : son test fige l'horloge au premier passage de l'heure répétée, ce qui ne marche qu'une fois l'application en UTC (M3).
6. **Garde-fous et documentation.** `caisse:diagnostiquer` (fuseau de l'application, fuseau par défaut de la base par `reset_val`) et ses tests, README, CLAUDE.md, grille faketime complète (§ 7, points 4 et 5).

Le lot peut fusionner 2 et 3, ou couper 4 en deux si chaque moitié reste verte et cohérente. Il peut fusionner 5 et 6, jamais 5 avec un commit d'avant la bascule. Il ne pousse jamais un état de stockage mixte.

---

## 10. Critères de fin

- [ ] `config('app.timezone')` vaut `UTC` ; l'invariant le vérifie au démarrage.
- [ ] `schedule_timezone` vaut `Calendrier::deParis()->fuseau()` ; les horaires sont à l'heure de Paris.
- [ ] La connexion `pgsql` a `'timezone' => 'UTC'`.
- [ ] Chaque colonne d'instant a `Instant`, chaque colonne `date` a `JourCivil` ; `created_at`, `updated_at`, `deleted_at` gérés par Eloquent ne sont pas castés.
- [ ] Aucune migration n'a de `useCurrent()`.
- [ ] `HorodatageUtc` et `InstantFiscal::enHeureLocale()` n'existent plus.
- [ ] `ReglesDuCalendrierTest` passe, chaque exception est justifiée.
- [ ] Les trois tests du § 6.1 échouaient avant la bascule (preuve dans le message de commit) et passent.
- [ ] D1 à D5 ont chacun un test de régression qui échoue sans sa correction.
- [ ] Les 10 tests du § 1.3 figent l'horloge.
- [ ] Aucun test ne voyage depuis l'heure réelle (garde de `travel()`, `freezeTime()` et `freezeSecond()` ; `travelTo(now()…)` interdit), sauf l'exception listée de `maintenantSelonLaBase()`.
- [ ] Toute heure écrite d'un test porte son fuseau ; aucun test ne voyage en jours.
- [ ] La borne `jusqu_au` d'une caisse fermée d'office est la dernière seconde de la journée de Paris.
- [ ] `recu_le` d'un paiement sur facture reste le jour saisi, refusé s'il est après `Calendrier::deParis()->aujourdhui()`.
- [ ] `PlanificateurTest` couvre chaque tâche de `bootstrap/app.php`.
- [ ] Aucun `->date(` dans Filament : un jour s'affiche par `jour()`, un instant par `->dateTime()`.
- [ ] `caisse:diagnostiquer` lit le fuseau par défaut de la base dans `pg_settings.reset_val`.
- [ ] Le contenu signé de référence est identique ; `ModuleFiscal::VERSION` n'a pas changé.
- [ ] Le filet du § 6.4 passe sans valeur attendue modifiée.
- [ ] Toutes les preuves du § 7 sont au code 0 et citées dans le compte rendu.
- [ ] CLAUDE.md et le README portent les textes du § 8.
- [ ] Aucune dépendance ajoutée ; aucune règle métier changée hors les réponses de l'éditeur : Q2 garde le comportement actuel, Q3 est fait (mention dans les journaux, commit 5), Q1 est laissé à T3.
- [ ] La mention « (heure d'été) » ou « (heure d'hiver) » apparaît dans la liste, la fiche et l'export du journal des événements, et dans tout écran ou export du journal d'audit, pendant l'heure répétée seulement ; aucun autre affichage ne la porte.
- [ ] Tous les magasins sont à l'heure de Paris. `Calendrier` porte son fuseau ; `deParis()` est sa seule fabrique et la seule lectrice de `FUSEAU_DE_PARIS` ; hors le constructeur privé, aucune méthode ne reçoit un fuseau en paramètre ; aucun membre n'est inutilisé (§ 3.3, § 3.10).
- [ ] Aucune requête réécrite n'a perdu son filtre de magasin ou de société.
- [ ] Le compte rendu dit les constats disparus depuis 6b5baba et les sites nouveaux traités.

---

## 11. Ce que T3, TA, EC et les lots suivants devront respecter

Ces règles valent pour T3, TA, EC, L1, A7, le lot 21 et tous les lots ultérieurs. T3 y ajoute les siennes (`t3/decision-heure-locale.md`) sans retirer celles-ci. `ReglesDuCalendrierTest` et le test de garde du schéma les font respecter.

**Calendrier du site.** Les règles 4, 5, 8 et 12 disent « le calendrier du site » : celui pour lequel le site calcule ou affiche. Dans T2, c'est toujours `Calendrier::deParis()`. Après T3, c'est le calendrier du magasin pour un site d'un magasin (T3 § 11, règle 1), et `Calendrier::deParis()` pour la société, un cabinet, l'éditeur ou la technique.

1. **Nouvelle colonne d'instant** : `timestamp()` ou `dateTime()`, sans valeur par défaut, cast `Instant`. Jamais `'datetime'`.
2. **Nouvelle colonne de jour** : `date()`, cast `JourCivil`.
3. **Aucun fuseau, aucune mise en forme, aucun calcul de jour hors `Calendrier`** : pas de `config('app.timezone')`, `'Europe/Paris'`, `->format(`, `today()`, `now('…')`, `::parse(`, `whereDate`, `addDays`… dans `app/` ou les vues.
4. **Liens, invitations et délais** (invitations et liens des cabinets d'EC, par exemple) : durée en heures par l'instant UTC ; durée en jours par `decaler()` du calendrier du site (`Calendrier::deParis()` pour une société, un cabinet ou l'éditeur) ; comparaison en liant un objet date. Chaque délai a un test de sa borne exacte (une seconde avant, à la borne), figé à un instant écrit. Un délai qui peut commencer la nuit d'un changement d'heure a aussi un cas au premier passage de l'heure répétée.
5. **Affichage** par `Calendrier` ou par les colonnes Filament déjà réglées ; un jour par `jour()`, jamais par `->date()` (§ 3.5). Un e-mail ou un PDF qui affiche une date passe par `afficher()` du calendrier du site, ou par `afficherJour()`. Un journal (journal des événements, journal d'audit, dont la future consultation du journal d'audit par le propriétaire à l'étape 4, et leurs exports) affiche ses heures par `afficherDansUnJournal()`, avec un test au premier et au second passage de l'heure répétée ; rien d'autre ne l'utilise.
6. **Dates reçues d'une API** (INPI, Sirene, annuaire de l'Ordre, SUPER PDP) : un jour par `Calendrier::jourValide()` ; un instant avec heure se lit avec son décalage, par une fonction du `Calendrier` ajoutée à ce moment-là, jamais par `parse()` sans fuseau.
7. **Lectures brutes** (`DB::table`, agrégats, y compris les lectures hors magasin ou hors société permises par CLAUDE.md) : un instant se relit par `Instant::depuisLaBase()` ou `withCasts`.
8. **Contenu signé** : toute date ajoutée passe par `iso8601()` du calendrier du site ou est un jour ; si un contenu signé change, `ModuleFiscal::VERSION` change.
9. **Tâche planifiée** : jamais quotidienne entre 02:00 et 02:59 (`PlanificateurTest`).
10. **Tests** : toute heure écrite par `aParis()` ou `enUtc()`, quel que soit son usage ; instants attendus comparés comme instants ; horloge figée avant tout voyage, jamais de voyage en jours ni parti de `now()`. En plus du faketime de leur entrée (`2027-01-02 10:00:00`), la suite SQLite passe aussi, code 0, à `2026-10-25 00:30:00` et à `2026-12-31 23:30:00`, sous `TZ=UTC NO_FAKE_STAT=1`.
11. **Aucune requête SQL n'extrait une date.** Aucun code ne dépend du fuseau de la session de base. Le fuseau par défaut de la base, vérifié par `caisse:diagnostiquer`, ne sert qu'aux sessions ouvertes hors de l'application.
12. **Fabriques et seeders** : mêmes règles que `app/` (un jour par `aujourdhui()` du calendrier du site, ou par `ajouterJours()`).
13. **Un calendrier s'obtient par sa fabrique** : `Calendrier::deParis()` jusqu'à T3, puis aussi celle d'un magasin que T3 ajoutera. Hors le constructeur privé de `Calendrier`, aucune méthode ne reçoit un fuseau en paramètre. Hors de `Calendrier`, aucun code n'écrit le nom du fuseau d'un calendrier (l'UTC de la configuration n'en est pas un) : il le lit par `fuseau()`.

---

## 12. Réponses de l'éditeur

Réponses du 25/10/2026 (`t2/reponses-t2.md`). Elles font foi et priment sur ce cahier, et, pour l'heure locale, sur tout ce qui y dit « heure de Paris » pour un magasin. Ce sont des règles métier tranchées par l'éditeur (protocole, règle 4).

- **Q1. Magasins d'outre-mer : l'heure locale de chaque magasin.** Chaque magasin suit l'heure locale de son territoire (Guadeloupe, Martinique, Guyane, La Réunion, Mayotte, et tout autre territoire que PILIQI accepte) : journée de caisse et clôture à minuit local, date imprimée sur ses documents, année de ses numéros, échéances et toute logique de calendrier du magasin. **Ce n'est pas fait dans T2**, mais dans le lot T3, juste après T2 (cahier des charges `t3/decision-heure-locale.md`). T2 garde l'heure de Paris pour tous les magasins. Il ne crée rien que T3 devrait défaire : un calendrier reçoit son fuseau par son constructeur, et un seul endroit lit ce fuseau, `Calendrier::deParis()`. T3 n'aura qu'à donner à `Calendrier` le fuseau d'un magasin (§ 3.3, § 3.10).
- **Q2. Délais exprimés en jours : la même heure, n jours plus tard.** C'est le comportement actuel : « valable jusqu'au 19/11 à 10:00 » pour un code envoyé le 20/10 à 10:00 (719 ou 721 h quand un changement d'heure tombe dans la période). Aucun changement : `decaler()` et `decalerEnMoisSansDebordement()` le gardent (§ 3.4), et leurs tests le prouvent (§ 6.2).
- **Q3. Affichage pendant l'heure répétée : oui, dans les journaux seulement.** Le journal des événements lisible et le journal d'audit ajoutent « (heure d'été) » ou « (heure d'hiver) » à une heure comprise entre 02:00 et 02:59 le dernier dimanche d'octobre, pour que l'ordre reste lisible. Rien ne change ailleurs (écrans, PDF, e-mails). Fait dans T2, au commit 5, dans la mécanique d'affichage de `Calendrier` (`afficherDansUnJournal()`, § 3.3 et § 3.5), avec ses deux tests nommés (§ 6.2).

---

## 13. Constats écartés

La relecture adversariale a relevé neuf constats sur ce cahier. Chacun a été vérifié dans le code de 6b5baba : tous sont réels et sont corrigés plus haut (§ 2.1, 2.3, 3.2, 3.3, 3.6, 3.8, 3.9, 4, 6, 7, 8, 9, 10, 11). Seuls les éléments suivants sont écartés, en tout ou en partie.

- **Constat 1, remède implicite.** Le constat laisse penser qu'il faudrait repousser au commit 4 les remplacements qui produisent des instants. Ce remède est écarté. Le commit de bascule grossirait, et les aides des tests du commit 1 (`aParis()`, `enUtc()`) auraient le même défaut sans être couvertes. Retenu à la place : tout instant rendu par `Calendrier` ou par ces aides est au fuseau de l'application. Cette règle permanente rend chaque commit neutre et vaut UTC après la bascule (§ 3.3).
- **Constat 2, borne demi-ouverte pour `jusqu_au`.** Passer `jusqu_au` en borne exclusive (minuit du lendemain) est écarté. Le relevé afficherait « → 26/10 00:00 », et le contrat actuel, que vérifient des tests existants (« 2026-10-09 23:59:59 » dans `CaissesPersonnellesFermeesDOfficeTest`), changerait. Seule la recherche d'une période de jours est demi-ouverte. Les instants sont enregistrés à la seconde : la borne inclusive « dernière seconde » lui est donc équivalente.
- **Constat 4, « `now()->addDays(…)` : 26 occurrences ».** Ce nombre n'a pas été retrouvé. Sur 6b5baba, `now()->addDay(s)(` apparaît 3 fois dans `tests/`. Les calculs en jours, semaines, mois ou années sur un instant y sont 31, dans 16 fichiers, dont 24 enchaînés sur `now()`. Le fond est retenu (aucune règle ne les visait), avec ces nombres. Les autres nombres du constat sont confirmés : 599 `travelTo('…')` dans 82 fichiers, 45 avant 2 h et 4 après 22 h, 14 `travel(n)->day(s)()`.
- **Constat 5, `PurgerTracesTest`.** Son voyage depuis `maintenantSelonLaBase()` n'est pas réécrit : il est nécessaire. Le déclencheur de purge compare à l'horloge du serveur de base, que faketime ne décale pas sous PostgreSQL. C'est la règle qui le nomme comme seule exception, dans la règle statique et dans le texte du CLAUDE.md (§ 3.8, § 8.1).
- **Constat 6, « le test d'échec est sans objet en production ».** Écarté pour le contrôle lui-même. Le fuseau par défaut de la base sert les sessions ouvertes hors de l'application (psql, sauvegardes, scripts, répartiteur de connexions), et aucune règle du code ne les protège. Le contrôle est donc gardé, mais il lit `pg_settings.reset_val` au lieu de `SHOW TIMEZONE`. Son test simule le défaut par une option de démarrage de la connexion, que le `SET` de Laravel ne masque pas. Sondé sur ce serveur PostgreSQL : après `ALTER DATABASE … SET timezone TO 'Europe/Paris'` puis `SET TIME ZONE 'UTC'`, `current_setting` vaut `UTC` et `reset_val` vaut `Europe/Paris` ; même résultat par `PGOPTIONS='-c TimeZone=Europe/Paris'`.
- **Constat 8, numéro de ligne.** Le `now('UTC')` de `RegistreFiscal` est à la ligne 452, et non 449. Sans effet sur le constat. Pour `->toIso8601String(`, ajouter une fonction UTC à `Calendrier` est écarté : `Calendrier` ne sert qu'à l'heure de son calendrier (Paris dans T2), et seuls deux sites en ont besoin (journal technique, contenu d'essai jeté). Ils deviennent deux exceptions listées.
- **Constat 9, partie « § 4.9 ».** `VerifierJournalAudit`, `SuivreEnvoisRecommandes` et `ReverifierSirene` n'ont aucun calcul de date dans leur propre code, ou il est traité au § 4.5, 4.6 ou 4.8. Ils n'ont pas à figurer parmi les fichiers à changer. Le § 4.9 les nomme désormais pour le dire, et `PlanificateurTest` couvre leur planification. La partie du constat sur `PlanificateurTest` est retenue en entier.

**Relecture croisée de T2 et T3 (25/10/2026).** Deux relectures ont visé ensemble ce cahier et celui de T3. Chaque constat a été vérifié dans le code de 6b5baba. Ceux qui touchent T2 sont réels et corrigés ici ; aucun n'est écarté pour T2 :

- règles 4, 5, 8 et 12 du § 11, qui imposaient `Calendrier::deParis()` là où T3 donne le calendrier du magasin : elles disent désormais « le calendrier du site » (§ 11) ;
- CLAUDE.md : T2 n'écrit plus « tous les magasins suivent l'heure de Paris », mais la règle de Q1 avec le lot qui la met en place (§ 8.1) ;
- `->date()` sur une colonne de jour : il convertit un jour d'un fuseau à un autre, contre le § 2.1, et donnerait la veille aux Antilles ; T2 le remplace par la macro `jour()` (§ 3.5, § 4.10) ;
- promesse faite à T3 sur `PlanificateurTest` : elle couvre les quatre tâches du calendrier des magasins (§ 3.10, point 4) ;
- preuve 11 : le seeder ne sème rien ; la preuve sur des données vient des tests (§ 7).

Les autres constats de ces relectures ne touchent que T3 ; ils sont traités dans son cahier, avec ses constats écartés (T3 § 14).

---

## Annexe A. Filet de la bascule (37 tests sur 6b5baba)

- `AvoirFournisseurResourceTest::test_les_retours_d_une_ligne_restent_dans_l_ordre_de_leur_emission_quand_l_heure_de_paris_se_repete`
- `ClientResourceTest::test_un_membre_qui_voit_les_clients_sans_les_modifier_consulte_leurs_verifications_vies` (3 jeux)
- `ClientResourceTest::test_les_verifications_vies_restent_consultables_dans_un_magasin_en_lecture_seule` (2 jeux)
- `ClientResourceTest::test_les_verifications_vies_d_un_client_archive_restent_consultables`
- `ClotureAutomatiqueCaisseTest::test_a_minuit_heure_de_paris_cloture_la_journee_qui_vient_de_finir`
- `ClotureAutomatiqueCaisseTest::test_la_cloture_automatique_part_a_minuit_heure_de_paris`
- `CloturerCaisseActionTest::test_une_journee_de_caisse_va_de_minuit_a_minuit_a_paris`
- `ConsultationViesTest::test_une_consultation_compte_pour_son_jour_a_paris`
- `ConsultationViesTest::test_la_reference_cite_le_numero_de_consultation_quand_vies_en_a_donne_un`
- `EmettreFactureActionTest::test_une_vente_a_0_h_30_le_1er_janvier_a_paris_est_datee_et_numerotee_sur_la_nouvelle_annee`
- `EmissionSigneeTest::test_un_ticket_au_comptoir_signe_ses_lignes_sa_ventilation_son_emetteur_son_client_et_son_reglement`
- `EmissionSigneeTest::test_une_livraison_intracommunautaire_signe_sa_nature_sa_livraison_et_sa_verification_vies`
- `EmissionSigneeTest::test_la_date_d_emission_est_l_horodatage_signe`
- `EmissionSigneeTest::test_l_heure_qui_se_repete_au_passage_a_l_heure_d_hiver_garde_l_instant_de_chaque_emission`
- `EmissionSigneeTest::test_un_avoir_sur_un_ticket_signe_en_negatif_ses_lignes_d_origine_et_journalise_l_annulation`
- `EnregistrementFiscalResourceTest::test_les_horodatages_s_affichent_a_l_heure_de_paris`
- `EnregistrementFiscalResourceTest::test_le_filtre_par_periode_compte_les_jours_a_l_heure_de_paris`
- `EnregistrementFiscalResourceTest::test_l_export_csv_du_journal_contient_les_colonnes_attendues_et_s_inscrit_au_journal`
- `EnregistrementFiscalResourceTest::test_le_widget_conformite_montre_la_derniere_verification`
- `ExpirerDevisTest::test_l_expiration_des_devis_part_a_00_h_30_heure_de_paris`
- `FactureAchatActionsTest::test_le_dernier_achat_reste_le_plus_recemment_valide_quand_l_heure_de_paris_se_repete`
- `RegistreFiscalTest::test_le_contenu_signe_se_verifie_tel_quel_et_reprend_l_en_tete_de_l_enregistrement`
- `RegistreFiscalTest::test_les_ecritures_d_une_transaction_portent_toutes_l_instant_de_son_verrou`
- `RegistreFiscalTest::test_un_point_de_sauvegarde_annule_ne_laisse_ni_ses_enregistrements_ni_un_instant_oublie`
- `RegistreFiscalTest::test_une_horloge_qui_recule_est_refusee_et_signalee`
- `RegistreFiscalTest::test_un_verrou_sans_ecriture_fixe_aussi_l_heure_sous_laquelle_l_horloge_ne_recule_plus`
- `RelancerFacturesImpayeesTest::test_les_relances_partent_chaque_matin_a_9_heures_heure_de_paris`
- `VerificateurCaissesPersonnellesTest::test_une_vente_apres_minuit_a_paris_ouvre_la_caisse_du_lendemain`
- `VerificateurIntegriteTest::test_une_date_d_emission_modifiee_en_base_n_est_plus_l_instant_de_la_signature`
- `VerificateurIntegriteTest::test_un_horodatage_anterieur_a_l_enregistrement_precedent_est_detecte`
- `VerificateurIntegriteTest::test_une_ligne_de_tete_qui_ne_note_plus_la_suite_du_registre_est_detectee` (jeu « dernier verrou »)
- `VerificateurIntegriteTest::test_un_numero_hors_de_l_annee_d_emission_de_son_document_est_detecte`
- `VerifierIntegriteFiscaleTest::test_la_verification_part_chaque_nuit_a_3_heures_heure_de_paris`
- `VerifierNumeroTvaActionTest::test_un_numero_valide_conserve_la_consultation_et_son_numero_de_preuve`

## Annexe B. Correspondance des défauts et des preuves

| Défaut | Correction | Test |
|---|---|---|
| M1 | stockage UTC, cast `Instant` | § 6.1 ; `HeureRepeteeTest::test_une_inscription_creee_au_premier_passage_…` ; les 7 tests M1 sous faketime 00:30Z |
| M2 | comparaison d'instants UTC, connexion | `HorodatageEnUtcTest::test_la_base_compare_…` ; `test_un_lien_expire_au_premier_passage_…` |
| M3 | application en UTC ; garde de `travel()` | `HorodatageEnUtcTest::test_l_horloge_figee_…` ; les 3 tests M3 |
| D1 | instants UTC en PHP et en base | les quatre tests de liens de `HeureRepeteeTest` |
| D2 | instants UTC | `test_un_acces_redonne_…` |
| D3 | instants UTC (ou la correction de FA3) | les deux tests de devis de `HeureRepeteeTest` |
| D4 | `Instant::depuisLaBase()` | `NuitDeParisTest::test_un_magasin_de_demonstration_…` |
| D5 | `withCasts` et fuseau de Filament | `NuitDeParisTest::test_le_dernier_achat_…` |
| `useCurrent()` | retirés | garde du schéma ; règle statique |
| `verifications_societe.expire_le` | cast `Instant` | garde du schéma |
| Mineurs (clôture sans code, courriers, purges, tris) | stockage et comparaison UTC | `HeureRepeteeTest`, grille faketime |
| Fermeture d'office (borne du relevé) | `jusqu_au` = dernière seconde de la journée de Paris | `NuitDeParisTest::test_une_caisse_personnelle_fermee_d_office_…` |
| `recu_le` saisi la nuit | refus comparé à `Calendrier::deParis()->aujourdhui()` | `NuitDeParisTest::test_un_paiement_recu_le_jour_de_paris_…` |
| Heures écrites des tests | `aParis()`/`enUtc()` au commit 1, garde de l'horloge | `ReglesDuCalendrierTest` (tests), diff sans valeur attendue changée |
| Fuseau par défaut de la base | `reset_val` lu par `caisse:diagnostiquer` | `CommandesModuleFiscalTest::test_diagnostiquer_echoue_quand_le_fuseau_par_defaut_…` |
| Heure répétée illisible dans les journaux (Q3) | `afficherDansUnJournal()`, commit 5 | `CalendrierTest::test_une_heure_repetee_porte_dans_un_journal_…` ; `HeureRepeteeTest::test_le_journal_des_evenements_dit_l_heure_d_ete_…` |
| Heure locale des magasins (Q1, lot T3) | `Calendrier` porte son fuseau, lu par `deParis()` seule ; T3 ajoute la fabrique d'un magasin | `ReglesDuCalendrierTest::test_seul_le_calendrier_connait_un_fuseau_…` |
