# Plan d'implémentation de l'intégration SUPER PDP

Les chemins sont relatifs à la racine `/home/user/piliqiediteur`. Je n'ai modifié aucun fichier du dépôt.

Sources utilisées :
- CLAUDE.md, qui fixe déjà la route de retour `/superpdp/retour`, les variables `SUPERPDP_URL`, `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET` et `SUPERPDP_REDIRECT_URI`, le format préféré Factur-X et une application OAuth confidentielle ;
- la spécification v1.36.0.beta, versionnée dans `tests/Fixtures/SuperPdp/openapi.json` ;
- les quatre analyses ;
- la lecture du code au commit 74288f3.

## 0. Cadre

- **Ordre de la feuille de route.** SUPER PDP correspond à l'étape 11. On la fait maintenant parce que l'utilisateur l'a demandé, mais on ne coche pas l'étape 11 : elle couvre aussi le paiement en ligne et les outils comptables.
- **Dépendance avec l'étape 1 (calcul des prix, tâche #94 en cours).** La conversion lit uniquement les montants enregistrés et ne recalcule jamais rien. Seules les valeurs attendues des fixtures dépendent des règles de calcul. Il faut donc les poser après la fusion de l'étape 1.
- **Obligation légale déjà en vigueur.** Depuis le 1er septembre 2026, toute entreprise doit pouvoir recevoir des factures électroniques. Si l'éditeur veut traiter la réception en premier, la phase 7 peut passer juste après la phase 4.
- **Règles appliquées à chaque phase :**
  - une seule migration de création par table, modifiée sur place, puis `php artisan migrate:fresh` ;
  - `BelongsToMagasin` sur tout modèle ;
  - tests ciblés pendant le travail, suite complète, `vendor/bin/pint --dirty --format agent`, relecture adversariale, commit, push, CI ;
  - lire le skill `testing-best-practices` avant d'écrire les tests.
- **Montants.** Ils passent tous par des centimes entiers, jamais par un flottant intermédiaire. On formate en chaîne `"12.34"`, jamais avec un `number_format` appliqué directement à un flottant calculé.

### Architecture cible

```
Domaine (Facture, Avoir, ClotureCaisse, Paiement, FactureRecue)
   │  événements → listeners → jobs (WithTenantContext)
   ▼
App\Integrations\Pdp\Contracts\PdpGatewayInterface   ← contrat réécrit
   ▼
App\Integrations\Pdp\SuperPdp\SuperPdpGateway        ← orchestration + conversion (Mapping\*)
   ▼
App\Integrations\Pdp\SuperPdp\SuperPdpClient         ← HTTP, jetons, renouvellement, erreurs typées, journal
   ▼
https://api.superpdp.tech (Http::fake dans les tests, Http::preventStrayRequests partout)
```

Un seul point de synchronisation, puisqu'il n'existe aucun webhook dans la spécification : la commande `superpdp:synchroniser`, lancée toutes les 15 minutes, dispatche un `SynchroniserSuperPdpJob` par magasin connecté.

---

## Phase 1 — Configuration

**Fichiers**

- **`config/services.php`** : ajouter le bloc suivant.
  ```php
  'superpdp' => [
      'url' => env('SUPERPDP_URL', 'https://api.superpdp.tech'),
      'client_id' => env('SUPERPDP_CLIENT_ID'),
      'client_secret' => env('SUPERPDP_CLIENT_SECRET'),
      'redirect_uri' => env('SUPERPDP_REDIRECT_URI'),
      'environnement' => env('SUPERPDP_ENVIRONNEMENT', 'production'), // sandbox | production
      'delai_reponse' => 30,
      'delai_connexion' => 10,
  ],
  ```
  Bac à sable et production partagent le même hôte : l'environnement dépend de l'entreprise (`company.env`). `SUPERPDP_ENVIRONNEMENT` a deux usages :
  - choisir le schéma de préremplissage (`sandbox` ou `fr_siren`) ;
  - refuser une entreprise du mauvais environnement, pour ne jamais envoyer de vraie facture vers le bac à sable ni de fausse facture en production.
- **`.env.example`** : `SUPERPDP_URL=https://api.superpdp.tech`, `SUPERPDP_CLIENT_ID=`, `SUPERPDP_CLIENT_SECRET=`, `SUPERPDP_REDIRECT_URI=http://localhost:8000/superpdp/retour`, `SUPERPDP_ENVIRONNEMENT=sandbox`.
  - Commentaire à mettre : l'hôte de `SUPERPDP_REDIRECT_URI` doit être celui du navigateur (`localhost` ou `127.0.0.1`), sinon le cookie de session et donc le `state` sont perdus.
- **`phpunit.xml`** : variables de test fixes (`client-test`, `secret-test`, `http://localhost/superpdp/retour`, `sandbox`). Aucun secret réel dans le code ni dans le dépôt.
- **`config/logging.php`** : canal `superpdp` (daily, 30 jours).
- **`app/Integrations/Pdp/SuperPdp/ConfigurationSuperPdp.php`** (final readonly, singleton) :
  - `estComplete(): bool` ;
  - `exigerComplete(): void`, qui lève `ConfigurationPdpIncompleteException` ;
  - `schemaNumeroAttendu(): string` : `sandbox` ou `fr_siren` ;
  - `environnementAttendu(): string`.
- **`app/Providers/PdpServiceProvider.php`** : lie `PdpGatewayInterface` à `SuperPdpGateway` et enregistre `ConfigurationSuperPdp` en singleton.
- **`tests/TestCase.php`** : `setUp()` appelle `Http::preventStrayRequests()`, pour qu'aucun appel réseau ne parte pendant les tests.

**Rotation de clé.** Les jetons sont chiffrés avec `APP_KEY`. Une rotation passe par `APP_PREVIOUS_KEYS` (à documenter dans `.env.example`).

**Tests.** `tests/Feature/SuperPdp/ConfigurationSuperPdpTest.php` : une configuration incomplète désactive la connexion.

---

## Phase 2 — Connexion d'un magasin (OAuth 2.1 authorization code, propriétaire seulement)

### Pourquoi une table plutôt que des colonnes sur `magasins`

- Le magasin est chargé à chaque requête Filament (tenant) et sérialisé dans les jobs. Garder les secrets hors de cette ligne limite leur exposition.
- La connexion a son propre cycle de vie : vérification, reconnexion, déconnexion.
- Elle porte les curseurs de synchronisation.
- Elle remplace `mandats_pdp` ligne pour ligne.
- `BelongsToMagasin` s'applique : on ne fait aucune exception au protocole.

### Migration

Supprimer `2026_10_02_100006_create_mandats_pdp_table.php` et créer à la même place `2026_10_02_100006_create_connexions_pdp_table.php` :

```
connexions_pdp
 id
 magasin_id                 FK magasins, unique, cascadeOnDelete
 statut                     string(20) default 'en_verification'
 verification_entreprise    string(20) nullable   verified|needs_review|failed
 verification_identite      string(20) nullable   verified|needs_review|failed|not_verified
 entreprise_externe_id      unsignedBigInteger nullable UNIQUE (global, voir ci-dessous)
 environnement              string(10) nullable   sandbox|production
 numero_entreprise          string(20) nullable
 schema_numero              string(30) nullable
 raison_sociale             string(200) nullable  (formal_name, instantané)
 adresse_reception          string(100) nullable  ex. 0225:315143296_110452
 access_token               text nullable         (cast encrypted)
 refresh_token              text nullable         (cast encrypted)
 access_token_expire_le     timestamp nullable
 regime_tva_declare_le      timestamp nullable    (null = PATCH /companies à refaire)
 curseur_evenements         unsignedBigInteger default 0
 curseur_factures_recues    unsignedBigInteger default 0
 motif                      string(255) nullable  (raison d'un refus, message pour le propriétaire)
 connecte_par               FK users nullable nullOnDelete
 connecte_le                timestamp nullable
 connexion_ip               string(45) nullable
 connexion_user_agent       string(255) nullable
 verifiee_le, active_le, deconnecte_le  timestamps nullable
 timestamps
 index(statut)
```

**Index unique global sur `entreprise_externe_id`.** Une entreprise SUPER PDP (un SIREN) ne peut être reliée qu'à un seul magasin. Sinon, les factures reçues par GET /invoices seraient visibles dans deux magasins, ce qui casse l'isolation stricte. À la déconnexion, on remet la colonne à null. Voir la décision D12.

### Modèle, enum, factory

- **`app/Integrations/Pdp/Models/ConnexionPdp.php`**
  - `BelongsToMagasin`, `HasFactory`.
  - `$hidden = ['access_token','refresh_token']`. Ni `magasin_id` ni les jetons ne figurent dans `$fillable`.
  - Méthodes :
    - `enregistrerJetons(JetonsPdp $jetons): void`
    - `effacerJetons(): void`
    - `autoriseLesEchanges(): bool` : `statut === Active && access_token !== null`
    - `static courante(): ?self` : la connexion du magasin courant
    - `adresseReception(): array{scheme: string, value: string}`
- **`Magasin`** : `connexionPdp(): HasOne`.
- **`app/Integrations/Pdp/Enums/StatutConnexionPdp.php`** : `EnVerification='en_verification'`, `Active='active'`, `Refusee='refusee'`, `AReconnecter='a_reconnecter'`, `Deconnectee='deconnectee'`. Implémente HasLabel et HasColor, avec `autoriseLesEchanges(): bool`.
- **`database/factories/ConnexionPdpFactory.php`** : états `active()` (Burger Queen bac à sable : 000000002, entreprise 110452, adresse 0225:315143296_110452), `enVerification()`, `aReconnecter()`, `deconnectee()`. Jetons fictifs `jeton-acces-…`.

### Ce que devient le mandat PDP

Suppression complète :
- `app/Domain/Tenancy/Models/MandatPdp.php`
- `Enums/StatutMandatPdp.php`
- `Actions/CreerMandatPdpAction.php`
- `Actions/RevokerMandatPdpAction.php`
- `database/factories/MandatPdpFactory.php`
- `app/Integrations/Pdp/Contracts/ResultatMandatPdp.php`
- `tests/Feature/Tenancy/MandatPdpActionsTest.php`

`PermissionRegistry::MANDAT_PDP_GERER` devient `CONNEXION_PDP_GERER = 'connexion_pdp.gerer'`, avec le libellé « Plateforme agréée : connecter et gérer ». Il reste exclu pour l'administrateur dans `SeedMagasinRolesAction`. Il faut mettre à jour ParametresFiscaux::canAccess, CreerMagasinActionTest et CreerMembreMagasinActionTest.

Les `company_mandates` de SUPER PDP (mandats de facturation et autofacturation) ne sont **pas** un équivalent du mandat local : la spécification le dit explicitement, et ils sont hors périmètre.

### Routes et contrôleur

`routes/web.php` :
```php
Route::middleware(['auth', 'throttle:10,1'])->group(function (): void {
    Route::get('/superpdp/connexion/{magasin:uuid}', [ConnexionSuperPdpController::class, 'rediriger'])->name('superpdp.connexion');
    Route::get('/superpdp/retour', [ConnexionSuperPdpController::class, 'retour'])->name('superpdp.retour');
});
```

`bootstrap/app.php` : remplacer le `//` de `withMiddleware` par `$middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));`. Un retour OAuth après expiration de session renvoie alors vers la connexion, puis vers l'URL demandée. La session est conservée à la connexion, le `state` aussi.

`app/Http/Controllers/ConnexionSuperPdpController.php` :
```php
public function rediriger(Request $request, Magasin $magasin, DemarrerConnexionPdpAction $action): RedirectResponse
public function retour(Request $request, FinaliserConnexionPdpAction $action): RedirectResponse
```

Fonctionnement :
- **`rediriger`** : appelle `$request->user()->canAccessTenant($magasin)`, sinon 404. Ensuite `TenantContext::runAs($magasin, …)` puis `can(CONNEXION_PDP_GERER)`, sinon 403. Enfin `redirect()->away($url)`.
- **`retour`** : redirige vers `PlateformeAgreee::getUrl(tenant: $magasin)` avec une `Notification` Filament flashée, en succès comme en erreur.

### Actions (`app/Integrations/Pdp/Actions/`)

**`DemarrerConnexionPdpAction::handle(Magasin $magasin, User $utilisateur): string`**

1. Préconditions, chacune avec un message clair :
   - `ConfigurationSuperPdp::exigerComplete()` ;
   - magasin actif ;
   - `siren` sur 9 chiffres ;
   - `raison_sociale` renseignée ;
   - `regime_tva` renseigné, parce que le PATCH /companies suit immédiatement.
2. `state = Str::random(64)`, `codeVerifier = Str::random(64)`, `codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')`.
3. `session()->put("superpdp.autorisations.$state", ['magasin_id', 'user_id', 'code_verifier', 'expire_le' => now()+10 min])`. On purge au passage les entrées expirées. Plusieurs onglets ou magasins peuvent être en cours en même temps.
4. Retourne `$gateway->urlAutorisation(new DemandeAutorisationPdp(state, codeChallenge, numero: $magasin->siren, email: $utilisateur->email))`.

L'URL construite est la suivante :
```
{url}/oauth2/authorize?response_type=code&client_id=…&redirect_uri=…&state=…
  &code_challenge=…&code_challenge_method=S256&login_hint={email}
  &superpdp_company_number={siren}&superpdp_company_number_scheme={sandbox|fr_siren}
```
On l'encode avec `http_build_query(..., PHP_QUERY_RFC3986)`. On envoie PKCE même si la spécification n'en parle pas : OAuth 2.1 l'impose, et un serveur ignore les paramètres qu'il ne connaît pas (RFC 6749 §3.1).

**`FinaliserConnexionPdpAction::handle(User $utilisateur, string $state, ?string $code, ?string $erreur, string $ip, string $userAgent): ConnexionPdp`**

1. `session()->pull("superpdp.autorisations.$state")` : usage unique. Puis trois contrôles :
   - entrée absente ou expirée : `ConnexionPdpRefuseeException`, sans aucun appel HTTP ;
   - `hash_equals` sur le state ;
   - `user_id` égal à l'utilisateur connecté.
2. Charger le magasin. Puis, dans `runAs` : vérifier de nouveau la permission ; si `$erreur` vaut par exemple `access_denied`, lever une exception qui produit le message « Autorisation refusée sur SUPER PDP ».
3. `$jetons = $gateway->echangerCodeAutorisation($code, $verifier)`.
4. Upsert de la connexion du magasin. Le `magasin_id` est imposé par `BelongsToMagasin` via le contexte. On écrit :
   - `statut = EnVerification` ;
   - les preuves : `connecte_par`, `connecte_le`, IP, user agent ;
   - `motif = null`, `deconnecte_le = null` ;
   - les jetons via `enregistrerJetons`.
5. Appelle `VerifierConnexionPdpAction` puis retourne la connexion.

**`VerifierConnexionPdpAction::handle(ConnexionPdp $connexion): ConnexionPdp`**

1. `$session = $gateway->lireSession($connexion)` (GET /v1.beta/oauth2_sessions/me). Si `clientId` diffère de la configuration : jetons effacés, statut `Refusee`, motif « jeton émis pour une autre application ».
2. On enregistre `verification_entreprise`, `verification_identite` et `verifiee_le`.
3. Selon la vérification d'entreprise :
   - `needs_review` : statut `EnVerification`, puis retour ;
   - `failed` : statut `Refusee`, jetons effacés, motif ;
   - `verified` : on passe à l'étape 4.
4. `$entreprise = $gateway->lireEntreprise($connexion)` (GET /companies/me). Contrôles :
   - `environnement` = `environnementAttendu()` ;
   - `schemaNumero` = `schemaNumeroAttendu()` ;
   - `numero` = `magasin->siren` ;
   - entreprise non reliée à un autre magasin. C'est une requête explicite `ConnexionPdp::withoutGlobalScope(MagasinScope::class)->where('entreprise_externe_id', …)->where('magasin_id', '!=', …)->exists()`, qui ne lit qu'une existence. Elle est justifiée par l'isolation, et l'index unique sert de garde-fou.
   Si un contrôle échoue : jetons effacés, `Refusee`, motif précis. Exemple : « L'entreprise autorisée (000000001) n'est pas celle du magasin (000000002) ».
5. Si `entreprise_externe_id` change, les deux curseurs repartent à 0.
6. `adresse_reception` = première adresse active, ni `is_replyto` ni réservée à la réponse, tirée de `$gateway->adressesDeReception()` (GET /v1.beta/directory_entries).
7. Statut `Active`, `active_le`, `regime_tva_declare_le = null`, puis dispatch de `DeclarerRegimeTvaAuPdpJob`.
8. `AccesNonVerifiePdpException` sur /companies/me : on reste en `EnVerification`.

**`DeconnecterPdpAction::handle(ConnexionPdp $connexion): void`**

Efface les jetons, met `entreprise_externe_id` à null, passe en `Deconnectee` et horodate `deconnecte_le`. La spécification ne prévoit aucune révocation distante : l'écran indique au propriétaire comment retirer l'accès depuis SUPER PDP. Les transmissions en attente le restent.

### Écrans Filament

- **`app/Filament/App/Pages/PlateformeAgreee.php`** et `resources/views/filament/app/pages/plateforme-agreee.blade.php` : groupe « Paramètres », accès `canAccess` = `CONNEXION_PDP_GERER`.
  - Affichage : statut, raison sociale, numéro, environnement, adresse de réception, les deux vérifications, connecté par et le, motif, état de la déclaration du régime de TVA.
  - Actions d'en-tête :
    - « Connecter SUPER PDP » : `->url(route('superpdp.connexion', $magasin))`, visible sans connexion ou si elle est `Refusee`, `AReconnecter` ou `Deconnectee`. Désactivée avec explication si la configuration est incomplète, ou s'il manque le SIREN, la raison sociale ou le régime ;
    - « Vérifier maintenant » ;
    - « Déconnecter » (confirmation demandée).
- **`ParametresFiscaux`** reçoit une section « Identité de l'entreprise ». Aucun écran ne la saisit aujourd'hui.
  - Champs : `raison_sociale` (obligatoire), `siren` (`/^\d{9}$/`), `siret` (`/^\d{14}$/`, doit commencer par le SIREN, `unique` en ignorant le magasin courant), `numero_tva`, `adresse`, `complement_adresse`, `code_postal`, `ville`, `pays`, `email`.
  - Le SIREN n'est plus modifiable tant qu'une connexion est active (message : déconnecter d'abord).
  - Au `save()`, si le régime ou l'option pour les débits change et que la connexion est active : `regime_tva_declare_le = null`, puis dispatch du job.
  - Le texte d'aide du régime ne parle plus de cadence calculée en local.

### Tests (`tests/Feature/SuperPdp/ConnexionSuperPdpTest.php`)

**Redirection**
- URL d'autorisation : tous les paramètres, et `code_challenge` = S256 du verifier stocké en session.
- Vendeur : 403. Utilisateur d'un autre magasin : 404.
- SIREN, raison sociale ou régime manquant : refus. Configuration incomplète : refus.

**Retour**
- State inconnu, expiré ou d'un autre utilisateur : `Http::assertNothingSent()` et aucune connexion créée.
- `error=access_denied` : aucune connexion.
- Cas nominal : POST /oauth2/token en `x-www-form-urlencoded` (`grant_type=authorization_code`, `code`, `redirect_uri`, `code_verifier`), en-tête `Basic base64(client:secret)`.
  - Statut `Active`, `entreprise_externe_id` et adresse 0225:315143296_110452 enregistrés.
  - En base, la valeur brute lue par `DB::table` est différente du jeton en clair.
  - PATCH /companies envoyé avec `{"vat_regime":"monthly","has_vat_on_debits":false}`.
- `needs_review` donne `EnVerification`. `failed` donne `Refusee` et des jetons à null.
- Rejet par motif :
  - mauvais SIREN (Tricatel 000000001 pour le magasin 000000002) ;
  - environnement production pour une entreprise du bac à sable ;
  - entreprise déjà reliée à un autre magasin ;
  - `client_id` étranger.

**Après connexion**
- La déconnexion efface les jetons.
- Une reconnexion avec une autre entreprise remet les curseurs à 0.

**Filament**
- Accès à PlateformeAgreee réservé au propriétaire, actions visibles selon le statut.
- ParametresFiscaux : validation de l'identité, SIREN verrouillé une fois connecté, job dispatché au changement de régime.

---

## Phase 3 — Client HTTP, contrat réécrit, gateway, synchronisation (socle)

### Contrat réécrit : `app/Integrations/Pdp/Contracts/PdpGatewayInterface.php`

L'ancien contrat suppose un mandat créé en un appel synchrone, ce qui ne correspond pas à SUPER PDP. Il est réécrit ainsi :

```php
interface PdpGatewayInterface
{
    // Connexion
    public function urlAutorisation(DemandeAutorisationPdp $demande): string;                         // /oauth2/authorize
    public function echangerCodeAutorisation(string $code, string $codeVerifier): JetonsPdp;         // POST /oauth2/token
    public function lireSession(ConnexionPdp $connexion): SessionPdp;                                // GET /v1.beta/oauth2_sessions/me
    public function lireEntreprise(ConnexionPdp $connexion): EntreprisePdp;                          // GET /v1.beta/companies/me
    /** @return list<string> */
    public function adressesDeReception(ConnexionPdp $connexion): array;                             // GET /v1.beta/directory_entries
    public function declarerRegimeTva(ConnexionPdp $connexion, RegimeTva $regime, bool $optionDebits): void; // PATCH /v1.beta/companies
    /** @return list<EntreeAnnuairePdp> */
    public function rechercherDansLAnnuaire(string $siren): array;                                   // GET /v1.beta/french_directory/entries (public)

    // Émission
    public function preparerDocument(ConnexionPdp $connexion, Facture|Avoir $document): DocumentPdp;  // /invoices/convert + /validation_reports (publics)
    public function deposerDocument(ConnexionPdp $connexion, DocumentPdp $document, string $referenceExterne): DepotPdp; // POST /v1.beta/invoices
    public function retrouverDepot(ConnexionPdp $connexion, string $referenceExterne, CarbonImmutable $tenteLe): ?DepotPdp; // GET /v1.beta/invoices?direction=out
    public function envoyerStatut(ConnexionPdp $connexion, int $factureExterneId, StatutEnvoyePdp $statut): EvenementPdp; // POST /v1.beta/invoice_events
    /** @return list<EvenementPdp> */
    public function evenementsDeLaFacture(ConnexionPdp $connexion, int $factureExterneId): array;    // GET /v1.beta/invoice_events?invoice_id=
    public function evenementsApres(ConnexionPdp $connexion, int $curseur): PagePdp;                 // GET /v1.beta/invoice_events?starting_after_id=&limit=1000

    // E-reporting
    public function declarerTransactionB2c(ConnexionPdp $connexion, DeclarationEReporting $declaration): int;   // POST /v1.beta/b2c_transactions
    public function retrouverTransactionB2c(ConnexionPdp $connexion, DeclarationEReporting $declaration): ?int; // GET /v1.beta/b2c_transactions?order=desc
    /** @return array<int, int|null> identifiant de transaction => ppf_ereporting_id */
    public function rattachementsEReporting(ConnexionPdp $connexion, int $apresTransaction): array;  // GET /v1.beta/b2c_transactions?starting_after_id=
    public function statutEReporting(ConnexionPdp $connexion, int $ereportingId): ?string;           // GET /v1.beta/ereportings/{id}

    // Réception
    public function facturesRecuesApres(ConnexionPdp $connexion, int $curseur): PagePdp;             // GET /v1.beta/invoices?direction=in&order=asc
    public function lireFactureRecue(ConnexionPdp $connexion, int $factureExterneId): FactureRecuePdp; // GET /v1.beta/invoices/{id}
    public function telechargerOriginal(ConnexionPdp $connexion, int $factureExterneId): FichierPdp;  // GET /v1.beta/invoices/{id}?format=original
}
```

**DTO** (final readonly, même dossier) :
- `DemandeAutorisationPdp(state, codeChallenge, numeroEntreprise, email)`
- `JetonsPdp(accessToken, ?refreshToken, ?CarbonImmutable expireLe)`
- `SessionPdp(clientId, verificationEntreprise, ?verificationIdentite)`
- `EntreprisePdp(int id, environnement, numero, schemaNumero, raisonSociale)`
- `EntreeAnnuairePdp(identifiant, bool actif, nom)`
- `DocumentPdp(contenu, typeMime, format)`
- `DepotPdp(int identifiantExterne, ?processingRule, list<EvenementPdp> evenements)`
- `EvenementPdp(int identifiantExterne, int factureExterneId, code, libelle, array details, CarbonImmutable survenuLe)`
- `PagePdp(list elements, bool aSuivant, int dernierIdentifiant)`
- `FactureRecuePdp(int identifiantExterne, int entrepriseExterneId, CarbonImmutable recueLe, array enInvoice, list<EvenementPdp> evenements)`
- `FichierPdp(contenu, typeMime, extension)`
- `StatutEnvoyePdp(string code, ?string motif, ?string commentaire)`

**Supprimés** : `ResultatSoumissionPdp.php`, `PeriodeEReporting.php`, `FakePdpGateway.php`, ainsi que la configuration `services.pdp.nom`. La colonne `prestataire_pdp` disparaît aussi (phase 4), puisqu'il n'y a qu'un prestataire. Les tests passent par `Http::fake` sur la vraie passerelle.

### Exceptions (`app/Integrations/Pdp/Exceptions/`)

Base abstraite `ErreurPdpException` (`?int $codeSuperPdp`, `?int $statutHttp`, message sans aucune donnée sensible). Dérivées :

| Exception | Déclencheur | Effet |
|---|---|---|
| `ConfigurationPdpIncompleteException` | client_id, secret ou redirect absents | connexion impossible |
| `ConnexionPdpRefuseeException` | state invalide, refus OAuth, erreur sur /oauth2/token | message sur l'écran |
| `DonneesManquantesPdpException(list<string> $manques)` | conversion impossible (SIREN, adresse électronique…) | définitive, sans nouvel essai |
| `DocumentInvalidePdpException(list<string> $messages)` | `/validation_reports` → `is_valid=false` | définitive |
| `RequeteRefuseePdpException` | 400 et autres 4xx hors 401, 403 et 429 (`http_ko.code`) | définitive |
| `AccesNonVerifiePdpException` | 403 | connexion repassée en `EnVerification` |
| `ConnexionPerduePdpException` | 401 après renouvellement, ou `invalid_grant` | connexion passée en `AReconnecter`, jetons effacés |
| `ServicePdpIndisponibleException` | `ConnectionException`, timeout, 429, 5xx | transitoire, nouvel essai du job |
| `FactureRecueNonImportableException(list<string> $motifs)` | phase 7 | facture reçue à traiter à la main |

### `app/Integrations/Pdp/SuperPdp/SuperPdpClient.php`

```php
final class SuperPdpClient
{
    public function __construct(private ConfigurationSuperPdp $configuration, private TenantContext $tenantContext) {}

    public function echangerCode(string $code, string $codeVerifier): JetonsPdp;
    public function get(ConnexionPdp $connexion, string $chemin, array $query = []): Response;          // rejouable
    public function postJson(ConnexionPdp $connexion, string $chemin, array $donnees, array $query = []): Response; // non rejouable
    public function postFichier(ConnexionPdp $connexion, string $chemin, string $contenu, string $typeMime, array $query = []): Response;
    public function patchJson(ConnexionPdp $connexion, string $chemin, array $donnees): Response;
    public function publicGet(string $chemin, array $query = []): Response;                             // rejouable
    /** @param list<array{nom: string, contenu: string, nomFichier: ?string, typeMime: string}> $parties */
    public function publicMultipart(string $chemin, array $parties, array $query = []): Response;      // rejouable (convert, validation_reports : sans effet de bord)

    private function garantirLeMagasin(ConnexionPdp $connexion): void;     // LogicException si magasin_id ≠ TenantContext::id()
    private function jetonValide(ConnexionPdp $connexion): string;          // renouvelle si expire dans < 60 s
    private function renouveler(ConnexionPdp $connexion): void;             // Cache::lock("superpdp-jeton-{magasin_id}", 15)->block(10), puis relit la ligne en base
    private function executer(ConnexionPdp|null $connexion, Closure $appel, bool $rejouable): Response; // traduit les erreurs, un seul rejeu après un 401
    private function journaliser(?ConnexionPdp $connexion, string $methode, string $chemin, ?Response $reponse, float $debut): void;
}
```

**Détails de fonctionnement**

- **Requête de base** : `Http::baseUrl(url)->acceptJson()->timeout(30)->connectTimeout(10)->withToken(...)->withUserAgent('piliqiediteur')`.
- **Nouveaux essais au niveau HTTP**, réservés aux appels rejouables : 3 tentatives (200 ms, 1 s, 3 s), seulement sur `ConnectionException` ou sur 429, 502, 503 et 504. Les POST qui créent quelque chose (/invoices, /b2c_transactions, /invoice_events) ne sont jamais rejoués à ce niveau. Ils le sont par le job, avec une recherche préalable de l'envoi précédent.
- **Jetons** :
  - échange et renouvellement en `asForm()` avec `withBasicAuth(client_id, client_secret)` (application confidentielle) ;
  - renouvellement par `grant_type=refresh_token` ;
  - remplacement atomique des deux jetons si un nouveau `refresh_token` est renvoyé ;
  - sans `refresh_token` et jeton expiré : `AReconnecter`, sans aucun appel ;
  - `expires_in` absent : `access_token_expire_le = null`, et on s'en remet au 401.
- **Journal** (canal `superpdp`) : `magasin_id`, méthode, chemin **sans** la chaîne de requête, statut HTTP, durée, `http_ko.code`. On n'y écrit jamais :
  - les en-têtes ;
  - les jetons, le code ou le verifier ;
  - le client_secret ;
  - les corps de requête ou de réponse (données de facture).
- **Exceptions** : elles ne reprennent jamais le message de `RequestException`, qui contient le corps de la réponse. Elles finissent dans `failed_jobs`.

### `app/Integrations/Pdp/SuperPdp/SuperPdpGateway.php`

Il implémente le contrat, convertit les réponses en DTO et porte la correspondance du régime de TVA :

| RegimeTva | vat_regime |
|---|---|
| ReelNormalMensuel | monthly |
| ReelNormalTrimestriel | quarterly |
| ReelSimplifie | simplified |
| FranchiseEnBase | vat_exemption |

`has_vat_on_debits` est toujours envoyé.

### Synchronisation (socle)

- **Commande `app/Console/Commands/SynchroniserSuperPdp.php`** (`superpdp:synchroniser`) : pour chaque magasin actif, `TenantContext::runAs` puis `ConnexionPdp::courante()`. Si le statut vaut `EnVerification` ou `Active`, dispatch de `SynchroniserSuperPdpJob`. Aucun contournement du scope.
- **`bootstrap/app.php`** : `$schedule->command(SynchroniserSuperPdp::class)->everyFifteenMinutes()->withoutOverlapping();`.
- **`app/Integrations/Pdp/Jobs/SynchroniserSuperPdpJob.php`** (ShouldQueue, ShouldBeUnique avec `uniqueId = magasinId`, `uniqueFor = 900`, WithTenantContext, `tries = 1`). Ordre d'exécution, complété au fil des phases :
  1. `EnVerification` : `VerifierConnexionPdpAction`.
  2. Si `!autoriseLesEchanges()` : arrêt.
  3. `regime_tva_declare_le === null` : `DeclarerRegimeTvaAction`.
  4. Relance des transmissions et déclarations en attente (phases 5 et 6).
  5. Import des factures reçues (phase 7).
  6. Événements (phase 4).
  7. Statuts PPF de l'e-reporting (phase 6).
- **`app/Integrations/Pdp/Jobs/DeclarerRegimeTvaAuPdpJob.php`** et **`Actions/DeclarerRegimeTvaAction.php`** : PATCH, puis `regime_tva_declare_le = now()`.

### Tests

**`tests/Feature/SuperPdp/SuperPdpClientTest.php`**
- Bearer = jeton déchiffré.
- Connexion du magasin A utilisée sous le contexte du magasin B : `LogicException`, aucune requête.
- Jeton expiré : renouvellement puis appel, nouveaux jetons enregistrés.
- 401 : un seul renouvellement puis rejeu ; second 401 : `AReconnecter` et `ConnexionPerduePdpException`.
- `invalid_grant` : `AReconnecter`.
- 403 : `EnVerification` et `AccesNonVerifiePdpException`.
- 400 `http_ko{code:1234}` : `RequeteRefuseePdpException`, code conservé.
- 503 sur GET : 3 tentatives puis `ServicePdpIndisponibleException`. 503 sur POST /invoices : une seule tentative.
- Journal : on écoute `MessageLogged` et on vérifie qu'aucun jeton, secret, code ou corps n'apparaît.

**`tests/Feature/SuperPdp/RegimeTvaSuperPdpTest.php`**
- Corps du PATCH exact pour les 4 régimes et les deux valeurs de l'option pour les débits.
- Si le PATCH échoue, le drapeau reste à null et la synchronisation suivante renvoie.

---

## Phase 4 — Suivi : transmissions et événements (socle commun émission et réception)

### Migrations (réécrites sur place)

`2026_10_03_100005_create_transmissions_pdp_table.php` :
```
transmissions_pdp
 id
 magasin_id               FK cascade
 morphs('transmissible')  Facture | Avoir | FactureRecue
 direction                string(3)   out | in (valeurs de l'API)
 reference_externe        string(36) nullable   external_id envoyé (sortantes)
 identifiant_externe      unsignedBigInteger nullable   invoice.id SUPER PDP
 processing_rule          string(20) nullable
 format                   string(10) nullable   facturx | cii | ubl
 statut                   string(30)
 chemin_fichier_archive   string nullable
 code_erreur              string(60) nullable
 message_erreur           text nullable
 depot_tente_le           timestamp nullable   (POST /invoices peut-être parti : retrouverDepot avant de renvoyer)
 deposee_le               timestamp nullable
 statut_maj_le            timestamp nullable
 timestamps
 unique(transmissible_type, transmissible_id, direction)   un document = une transmission par sens
 unique(magasin_id, identifiant_externe)
 unique(magasin_id, reference_externe)
 index(magasin_id, statut)
```

`2026_10_03_100006_create_evenements_transmission_pdp_table.php` :
```
evenements_transmission_pdp
 id
 magasin_id            FK cascade        (corrige l'écart avec la règle 2)
 transmission_pdp_id   FK cascade
 identifiant_externe   unsignedBigInteger   event.id SUPER PDP
 code                  string(40)           fr:205, api:uploaded, ppf:payment-received-ack-error…
 libelle               string(255)          status_text
 details               json nullable        data, details, noms des pièces jointes
 survenu_le            timestamp            event.created_at
 unique(magasin_id, identifiant_externe)
 index(transmission_pdp_id, survenu_le)
```
La table n'a pas d'`updated_at` : on ne fait qu'y ajouter des lignes.

### Modèles et enums

- **`TransmissionPdp`** (réécrit) : casts `direction` (`DirectionTransmissionPdp`) et `statut` (`StatutTransmissionPdp`). Relations `evenements()` et `transmissible()`. `enregistrerEvenement(EvenementPdp $evenement): void` fait un `firstOrCreate` sur `identifiant_externe`. Si le code a une correspondance et que l'événement est plus récent que `statut_maj_le`, le statut et `statut_maj_le` sont mis à jour.
- **`EvenementTransmissionPdp`** : ajout de `BelongsToMagasin`.
- **`Facture` et `Avoir`** : `transmissionSortante(): MorphOne`, à la place de `transmissionsPdp()` qui était un MorphMany.
- **`DirectionTransmissionPdp`** : `Sortante='out'`, `Entrante='in'`.
- **`StatutTransmissionPdp`** (réécrit). La spécification précise que ce n'est pas une machine à états : le statut affiché est celui du dernier événement qui a une correspondance.

| Statut affiché | Codes |
|---|---|
| EnAttente | statut local |
| Invalide | statut local : contrôle préalable, 400, données manquantes |
| EchecTechnique | statut local : essais épuisés |
| Deposee | api:uploaded |
| Emise | api:validated, api:sent, fr:200, fr:201 |
| MiseADisposition | fr:202, fr:203, api:received |
| PriseEnCharge | fr:204, api:acknowledged |
| Approuvee | fr:205, api:accepted |
| ApprouveePartiellement | fr:206 |
| EnLitige | fr:207 |
| Suspendue | fr:208 |
| Completee | fr:209 |
| Refusee | fr:210 |
| PaiementTransmis | fr:211 |
| Encaissee | fr:212 |
| Rejetee | fr:213, fr:501, api:rejected, api:invalid |

`ppf:*` et `fr:220` sont conservés comme événements mais ne changent pas le statut affiché. Méthodes : `static depuisCode(string $code): ?self`, HasLabel, HasColor.

### Action de synchronisation

`app/Integrations/Pdp/Actions/SynchroniserEvenementsPdpAction::handle(ConnexionPdp $connexion): void`

Boucle sur `evenementsApres(curseur_evenements)`, 50 pages au plus par passage. Chaque page est traitée dans `DB::transaction` :
- on cherche `TransmissionPdp::where('identifiant_externe', $e->factureExterneId)`, avec le scope du magasin ;
- si on la trouve : `enregistrerEvenement` ;
- sinon on ignore l'événement (journal sans données) ;
- en fin de page, `curseur_evenements = dernierIdentifiant`.

Un événement est toujours rattaché par la connexion du magasin qui a fait l'appel, jamais par un identifiant de magasin fourni par l'API. L'action est branchée à l'étape 6 de `SynchroniserSuperPdpJob`.

### Tests (`tests/Feature/SuperPdp/SynchronisationEvenementsTest.php`)

- Pagination : `has_after=true` puis `false`, curseur enregistré.
- Statut affiché égal au dernier code ayant une correspondance ; `ppf:*` stocké sans changer le statut.
- Identifiant de facture inconnu : ignoré, le curseur avance quand même.
- Une seconde exécution ne crée aucun doublon.
- **Isolation** : deux magasins et deux connexions. `Http::fake` répond selon le jeton Bearer, et chaque magasin ne reçoit que ses propres événements.

---

## Phase 5 — Émission des factures et avoirs B2B

### Données

- **`2026_10_02_200001_create_clients_table.php`** : ajout de `siren` string(9) nullable, de `adresse_electronique` string(100) nullable et de `index(magasin_id, siren)`.
  - Modèle `Client` : le SIREN se déduit des 9 premiers chiffres du SIRET quand il est vide.
- **`ClientResource`**, pour un client professionnel :
  - champs SIREN, SIRET et « Adresse de facturation électronique » ;
  - à côté de l'adresse, une action « Chercher dans l'annuaire » qui appelle `rechercherDansLAnnuaire` (route publique, sans jeton) et propose les adresses actives.
  - Validation : SIREN sur 9 chiffres ; SIRET sur 14 chiffres commençant par le SIREN ; SIREN obligatoire pour un client professionnel français (décision D16).
- **`app/Domain/Facturation/Enums/CircuitReglementaire.php`** : `FactureElectronique`, `EReportingB2c`, `EReportingInternational`.
  - `Facture::circuitReglementaire()` et `Avoir::circuitReglementaire()` remplacent `relevantDeLeInvoicing()`. Particulier : B2c. Professionnel en France : FactureElectronique. Professionnel à l'étranger : International (voir phase 9).

### Conversion (`app/Integrations/Pdp/SuperPdp/Mapping/`)

- **`Decimal.php`** : `montant(string|int|float $valeur): string` passe par des centimes entiers ; `taux(...)`, `centimes(...)`, `depuisCentimes(int): string`.
- **`ConstructeurEnInvoice.php`** :
  ```php
  public function depuisFacture(Facture $facture, ConnexionPdp $connexion): array;  // en_invoice
  public function depuisAvoir(Avoir $avoir, ConnexionPdp $connexion): array;
  ```
  Avant toute construction, on lève une seule `DonneesManquantesPdpException` qui liste **tous** les manques :
  - magasin : raison sociale, SIREN, n° TVA (BR-S-02), adresse, pays, adresse de réception de la connexion ;
  - client : SIREN, adresse électronique (résolue automatiquement si l'annuaire renvoie une seule adresse active), adresse, pays ;
  - toute ligne à 0 % (décision D10).

**Correspondance avec en_invoice.** Tout vient des montants enregistrés ; on ne recalcule rien.

| en_invoice | Source |
|---|---|
| number (BT-1) | `numero` |
| issue_date (BT-2) | `date_emission->toDateString()` (heure de Paris) |
| type_code (BT-3) | 380 pour une facture, 381 pour un avoir (entier) |
| currency_code (BT-5) | `devise` |
| process_control.specification_identifier (BT-24) | `urn:cen.eu:en16931:2017` (T4) |
| process_control.business_process_type (BT-23) | B1, S1 ou M1 selon `CategorieOperation::depuisNatures` ; B2, S2 ou M2 si le reste dû vaut 0 à l'envoi (D15) |
| payment_due_date (BT-9) | `date_echeance` |
| payment_terms (BT-20) | `conditions_paiement`, sinon « Paiement à réception » (BR-CO-25). Pour un avoir : « Montant imputé sur les factures … » |
| notes (BG-1) | `notes` ; mention « Option pour le paiement de la taxe d'après les débits » si `option_debits_tva` ; notes PMT, PMD et AAB selon D14 |
| seller.name / trading_name | `raison_sociale` / `nom` s'il est différent |
| seller.legal_registration_identifier (BT-30) | `{scheme:"0002", value: siren}` |
| seller.identifiers (BT-29) | `[{scheme:"0009", value: siret}]` si le SIRET est renseigné |
| seller.vat_identifier (BT-31) | `numero_tva` |
| seller.electronic_address (BT-34) | `connexion.adresse_reception`, découpée en `{scheme:"0225", value:"315143296_110452"}` |
| seller.postal_address | adresse, complément, code postal, ville, pays |
| buyer.* (BT-44 à 49) | `client` : nom, SIREN 0002, SIRET 0009, n° TVA, adresse électronique, adresse, email en `contact.email_address` |
| preceding_invoice_references (BG-3, avoir) | `facturesConcernees()` : `{reference: numero, issue_date, preceding_invoice_type_code: 380}`, une entrée par facture (avoir multi-factures) |
| lines[].identifier (BT-126) | rang 1, 2, 3… de la ligne triée par `ordre` (même règle à l'envoi de la facture et dans l'avoir) |
| lines[].invoiced_quantity / _code | `quantite` (chaîne) / `C62` (D21) |
| lines[].net_amount (BT-131) | `montant_ht` |
| lines[].price_details | `item_net_price = prix_unitaire_ht`, `item_price_base_quantity = "1"`, `quantity_unit_code = "C62"` |
| lines[].allowances (BG-27) | si `remise_pourcentage > 0` : `{amount: quantite×PU HT − montant_ht, base_amount: quantite×PU HT, percent, reason: "Remise", reason_code: "95"}` en centimes |
| lines[].vat_information | `{invoiced_item_vat_category_code:"S", invoiced_item_vat_rate: taux}` |
| lines[].line_vat_amount / line_with_vat_net_amount | `montant_tva` / `montant_ttc` (extensions FR 181 et 184) |
| lines[].item_information | `{name: description, seller_identifier: reference}` |
| lines[].additional_reference_previous_invoice_line (avoir) | numéro et date de la facture d'origine, rang de `ligne_facture`, type 380 |
| vat_break_down (BG-23) | par taux : `taxable_amount = Σ montant_ht`, `tax_amount = Σ montant_tva` (montants enregistrés, voir D1), catégorie S |
| totals | `sum_invoice_lines_amount = total_without_vat = total_ht` ; `total_vat_amount = {value: total_tva, currency_code}` ; `total_with_vat = total_ttc` ; `paid_amount = montantPaye()` s'il est positif ; `amount_due_for_payment = total_ttc − paid` |

**Référence externe** (≤ 36 caractères) : `F{magasin_id}-{facture_id}` ou `A{magasin_id}-{avoir_id}`.

### Format retenu : Factur-X

C'est la préférence de l'éditeur dans CLAUDE.md. Le fichier transmis, le PDF que voit le client et l'archive sont un seul et même document : on archive exactement ce qui a été envoyé. Pour le produire, on passe par POST /invoices/convert en multipart, avec le JSON en_invoice et notre PDF DomPDF existant. La réponse Factur-X de /convert n'est pas typée dans la spécification : c'est à vérifier en bac à sable (T5). Si elle n'est pas exploitable, on bascule sur `to=cii` (D18).

`SuperPdpGateway::preparerDocument(ConnexionPdp $connexion, Facture|Avoir $document): DocumentPdp`
1. `ConstructeurEnInvoice`.
2. Le PDF vient de `FacturePdfGenerator::generer($facture)->output()` ou de `AvoirPdfGenerator`.
3. `publicMultipart('/v1.beta/invoices/convert', [invoice (application/json), pdf (application/pdf)], ['from'=>'en16931','to'=>'factur-x'])`. On contrôle `Content-Type: application/pdf` et le préfixe `%PDF`, sinon `ErreurPdpException`.
4. `publicMultipart('/v1.beta/validation_reports', [file_name: facture.pdf])`. Si `data[0].is_valid === false` : `DocumentInvalidePdpException`, avec au plus 20 messages tirés de `failures` et `messages` (`message` et `location`).

`deposerDocument(...)` : `postFichier($connexion, '/v1.beta/invoices', $pdf, 'application/pdf', ['external_id' => $ref, 'processing_rule' => 'B2B'])`, sans `disable_pre_check`. On en tire un `DepotPdp` (id, processing_rule, events).

`retrouverDepot(...)` : GET `/v1.beta/invoices?direction=out&order=desc&limit=100`. On parcourt les pages tant que `created_at ≥ tenteLe − 1 h`, et on compare `external_id`.

### Déclenchement et job

- **Listeners** (`app/Integrations/Pdp/Listeners/`) : `TransmettreAuPdpApresEmissionFacture` (FactureEmise) et `TransmettreAuPdpApresEmissionAvoir` (AvoirEmis). Ils remplacent `DeclencherTransmissionPdpApresEmissionFacture`, qui est supprimé. Mettre à jour `EventServiceProvider`.
  - Si le circuit est `FactureElectronique` et que `ConnexionPdp::courante()?->autoriseLesEchanges()`, on appelle `PreparerTransmissionAction::handle(Facture|Avoir): TransmissionPdp`.
  - Cette action crée la transmission dans la transaction d'émission (`out`, `EnAttente`, `reference_externe`), puis `TransmettreDocumentAuPdpJob::dispatch(...)->afterCommit()`.
- **`app/Integrations/Pdp/Jobs/TransmettreDocumentAuPdpJob.php`** : remplace `app/Domain/Facturation/Jobs/SubmitFactureToPdpJob.php`, supprimé, et corrige le défaut constaté (une erreur réseau n'était jamais retentée).
  ```php
  public function __construct(public readonly int $magasinId, public readonly int $transmissionId) {}
  public int $tries = 5; public array $backoff = [60, 300, 900, 3600];
  public function uniqueId(): string;           // transmissionId
  public function handle(PdpGatewayInterface $gateway): void;
  public function failed(Throwable $exception): void;   // EchecTechnique, message générique
  ```
  Déroulé de `handle` :
  1. `demarrerContexteTenant` ; transmission chargée avec le scope du magasin. Si `identifiant_externe` est renseigné ou si le statut vaut `Invalide` : retour.
  2. Connexion `!autoriseLesEchanges()` : retour. On reste en `EnAttente` ; la synchronisation relancera.
  3. `$document = preparerDocument(...)`.
     - `DonneesManquantes`, `DocumentInvalide` ou `RequeteRefusee` : statut `Invalide` avec code et message, sans nouvel essai.
     - `ServicePdpIndisponible` : on relance l'exception.
  4. Si `depot_tente_le !== null` : `retrouverDepot`. S'il trouve le dépôt, on l'enregistre et on s'arrête.
  5. `depot_tente_le = now()`, puis `deposerDocument`.
     - `RequeteRefusee` : `Invalide`.
     - `AccesNonVerifie` ou `ConnexionPerdue` : `depot_tente_le = null`, rien n'est parti ; retour.
     - `ServicePdpIndisponible` : on relance l'exception (nouvel essai, avec recherche à l'étape 4).
  6. Enregistrement :
     - archive dans `Storage::disk('local')->put("pdp/{magasin.uuid}/emises/{facture|avoir}-{numero}.pdf")` ;
     - `identifiant_externe`, `processing_rule`, `format='facturx'`, `deposee_le`, statut `Deposee` ;
     - événements du dépôt (`api:uploaded`).
- **Relance** : à l'étape 4 de `SynchroniserSuperPdpJob`, toute transmission sortante `EnAttente` sans identifiant, dont `updated_at` date de plus de 15 minutes, est redispatchée.
- **`AnnulerFactureAction`** refuse l'annulation dès qu'une transmission sortante existe et n'est pas `Invalide`. Une facture déposée se corrige par un avoir, comme le prévoit déjà l'étape 2.

### Écrans

Dans `FactureResource` (infolist l.194-209) et `AvoirResource`, section « Facture électronique » :
- statut, identifiant SUPER PDP, date de dépôt, erreur ;
- `RepeatableEntry` des événements (date, libellé, code) ;
- actions :
  - « Télécharger le Factur-X transmis » ;
  - « Transmettre » (document sans transmission, D13) ;
  - « Transmettre de nouveau » (`EchecTechnique`).
  Ces actions sont réservées à `CONNEXION_PDP_GERER`.

### Tests

**`tests/Feature/SuperPdp/ConstructeurEnInvoiceTest.php`**
- Fixture de référence `tests/Fixtures/SuperPdp/en_invoice_facture.json`. On adapte l'exemple validé `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/exemples/facture.json` : vendeur Burger Queen 000000002, acheteur Tricatel 000000001 à l'adresse 0225:315143296_110451, lignes 2 × 50,00 HT à 20 % et 3 × 4,00 HT à 5,5 %. On vérifie avec `assertSame`.
- Ligne au prix TTC 2 × 40,00 à 20 % : `net_amount "66.66"`, `line_vat_amount "13.34"`, ventilation `"13.34"`.
- Remise de 10 % : `allowance` au centime.
- Tous les montants sont des chaînes à 2 décimales.
- Avoir sur deux factures : deux `preceding_invoice_references` et les références de ligne.
- Données manquantes : exception qui liste tous les champs.
- Fixture `tests/Fixtures/SuperPdp/en_invoice_avoir.json`.

**Contrôle de schéma.** Un petit vérificateur dans les tests (required, type, enum, `$ref`, oneOf) relit `tests/Fixtures/SuperPdp/openapi.json` et contrôle chaque charge construite. On n'ajoute aucune dépendance (voir D19).

**`tests/Feature/SuperPdp/EmissionFactureTest.php`**
- Déclenchement :
  - facture pro : transmission `EnAttente` et job dispatché ;
  - particulier ou client étranger : rien ;
  - sans connexion active : rien.
- Cas nominal, trois requêtes dans l'ordre :
  - multipart avec les parties `invoice` et `pdf` ;
  - `validation_reports` ;
  - POST /invoices avec `external_id=F{m}-{f}`, `processing_rule=B2B`, `application/pdf`, Bearer du magasin.
  Puis identifiant 4242, archive présente (`Storage::fake('local')`), événement `api:uploaded`.
- Rapport invalide : `Invalide`, aucun POST /invoices.
- 400 au dépôt : `Invalide`, aucun nouvel essai.
- 503 : exception, `depot_tente_le` renseigné. Nouvelle exécution avec GET /invoices qui contient l'`external_id` : identifiant repris sans second POST (`Http::assertSentCount`). Si la recherche ne trouve rien, le POST repart : c'est la régression du défaut constaté.
- `failed()` : `EchecTechnique`.
- Connexion `AReconnecter` : aucun appel.
- Annulation refusée après le dépôt.

---

## Phase 6 — E-reporting B2C par clôture, et régime de TVA

### Ce que devient la SoumissionEReporting actuelle

SUPER PDP agrège lui-même les données et les envoie au PPF selon le `vat_regime` déclaré. Toute l'agrégation locale par période fait donc double emploi et disparaît (décision D17 à confirmer, mais elle découle de la spécification) :
- `SoumissionEReporting` (modèle, factory) ;
- la migration `2026_10_03_000001_create_soumissions_ereporting_table.php` ;
- `GenererSoumissionEReportingAction`, `SubmitSoumissionEReportingToPdpJob`, `DeclencherTransmissionPdpApresSoumissionEReporting`, `SoumissionEReportingGeneree` ;
- `SoumettreEReportingPeriodique` et sa ligne dans `bootstrap/app.php` ;
- `CalculateurPeriodeEReporting` ;
- `SoumissionEReportingResource` (et ses pages), `SoumissionEReportingPolicy`, la ligne `Gate::policy` ;
- `TypeDocument::SoumissionEReporting` et le préfixe `ER` dans `DocumentNumberingService` ;
- la relation `Magasin::soumissionsEReporting` ;
- la colonne `clotures_caisse.soumission_ereporting_id`, avec la relation et le commentaire, ainsi que les commentaires de `magasins.regime_tva` et `option_debits_tva` ;
- les tests `GenererSoumissionEReportingActionTest`, `SoumettreEReportingPeriodiqueTest`, `SoumissionEReportingResourceTest` et `tests/Unit/CalculateurPeriodeEReportingTest`.

Ces tests disparaissent avec la fonctionnalité qu'ils couvrent : on ne les affaiblit pas.

### Nouvelle table

`2026_10_03_000003_create_declarations_ereporting_table.php` (après `clotures_caisse`) :
```
declarations_ereporting
 id
 magasin_id               FK cascade
 cloture_caisse_id        FK clotures_caisse nullable restrictOnDelete
 type                     string(12)   transaction | paiement
 categorie                string(4)    TLB1 | TPS1 | TNT1
 date_operation           date
 total_ht                 decimal(12,2) nullable
 total_tva                decimal(12,2) nullable
 sous_totaux              json         (exactement ce qui est envoyé)
 statut                   string(20) default 'en_attente'  en_attente | declaree | invalide | echec_technique
 identifiant_externe      unsignedBigInteger nullable      b2c_transaction.id
 declaration_tentee_le    timestamp nullable
 declaree_le              timestamp nullable
 ereporting_externe_id    unsignedBigInteger nullable      ppf_ereporting_id
 statut_ppf               string(40) nullable
 statut_ppf_maj_le        timestamp nullable
 code_erreur              string(60) nullable
 message_erreur           text nullable
 timestamps
 unique(magasin_id, type, categorie, date_operation)
 unique(magasin_id, identifiant_externe)
 index(magasin_id, statut)
```

- Modèle `app/Integrations/Pdp/Models/DeclarationEReporting.php` (BelongsToMagasin, HasFactory).
- `ClotureCaisse::declarationsEReporting(): HasMany`.
- Enums : `StatutDeclarationEReporting` et `CategorieTransactionB2c` (`Biens='TLB1'`, `Services='TPS1'`, `NonTaxable='TNT1'`).

### Flux

- **Événement** `app/Domain/Facturation/Events/CaisseCloturee(ClotureCaisse $cloture)`, dispatché à la fin de `CloturerCaisseAction`. Il couvre la clôture manuelle comme la clôture automatique.
- **Listener** `app/Integrations/Pdp/Listeners/DeclarerClotureAuPdpApresCloture` : si la connexion autorise les échanges, `PreparerDeclarationsClotureAction::handle(ClotureCaisse)`.
  - Pour chaque nature de `ventilation_par_nature`, on crée une `DeclarationEReporting` : Bien = TLB1, Service = TPS1 (0 % voir D10). `sous_totaux` = `[{tax_percent, taxable_amount, tax_total}]` et totaux en centimes.
  - Un groupe entièrement nul est ignoré.
  - Ensuite `DeclarerEReportingAuPdpJob::dispatch(...)->afterCommit()`.
- **Conversion** `Mapping/ConstructeurTransactionB2c::depuisDeclaration(DeclarationEReporting $declaration, Magasin $magasin): array` :
  ```json
  {"date":"2026-10-07","currency":"EUR","category_code":"TLB1","role_code":"SE",
   "tax_exclusive_amount":"…","tax_total":"…","tax_subtotals":[{"tax_percent":"20.00","taxable_amount":"…","tax_total":"…"}]}
  ```
  On n'envoie jamais `id`, qui est en lecture seule. `tax_due_date_type_code` est omis (D9). Les montants négatifs, quand les avoirs du jour dépassent les ventes, sont envoyés tels quels (T7).
- **Job** `app/Integrations/Pdp/Jobs/DeclarerEReportingAuPdpJob(magasinId, declarationId)` : même logique que la phase 5. On fait un POST par déclaration (`{"data":[…]}`), parce que l'atomicité d'un envoi groupé n'est pas documentée.
  - Si `declaration_tentee_le` est renseigné, `retrouverTransactionB2c` : GET `order=desc`, même date, même catégorie, mêmes montants, et identifiant pas encore utilisé en local.
  - 400 : `Invalide`. 5xx : nouvel essai. `failed()` : `EchecTechnique`.
- **Statuts PPF** : `SynchroniserStatutsEReportingAction::handle(ConnexionPdp)`, à l'étape 7 de la synchronisation.
  - Déclarations `Declaree` sans `ereporting_externe_id` : `rattachementsEReporting(min − 1)`.
  - Puis, par `ppf_ereporting` dont le statut n'est pas final et dont la déclaration a moins de 90 jours : `statutEReporting`, qui renvoie le dernier `ppf:ereporting*`.
  - Statuts finaux : `-ack-error`, `-rejected`, `-response-ok` (T10).
- **Relance** : déclarations `EnAttente` depuis plus de 15 minutes. Les clôtures antérieures à la connexion n'ont pas de déclaration (D13).

### Écrans

- `app/Filament/App/Resources/DeclarationEReportingResource.php` (pages List et View, `DeclarationEReportingPolicy`). Elle remplace la Resource supprimée :
  - lecture seule : date, catégorie, HT, TVA, statut, statut PPF, erreur ;
  - filtres ;
  - action « Déclarer de nouveau » (`EchecTechnique`, `CONNEXION_PDP_GERER`) ;
  - consultation avec `CLOTURE_CAISSE_VIEW_TOUTES`.
- `ClotureCaisseResource` : la section « E-reporting » (l.77-86) liste les déclarations de la clôture.

### Tests (`tests/Feature/SuperPdp/DeclarationEReportingTest.php`)

- **Journée fictive de Burger Queen** : 10 × 3,20 TTC à 5,5 %, 2 × 40,00 et 3 × 9,99 TTC à 20 %, deux services à 20 % et 10 %, un avoir.
  - On obtient TLB1 et TPS1. `sous_totaux` est égal aux sommes calculées à la main depuis les lignes enregistrées, au centime.
  - Le corps du POST est exact.
- Une seconde exécution ne crée aucun doublon. Un 503 suivi d'une recherche fructueuse reprend l'identifiant.
- Journée négative envoyée en négatif. Services seuls : uniquement TPS1. Journée vide : aucune déclaration.
- Pas de connexion : aucune ligne.
- Statut PPF : `ppf_ereporting_id` 77, puis `ppf:ereporting-ack`.
- Isolation entre deux magasins.

---

## Phase 7 — Réception des factures d'achat

### Migrations

- **`2026_10_02_200002_create_fournisseurs_table.php`** : ajout de `siren` string(9) nullable et de `index(magasin_id, siren)`.
- **Nouvelle `2026_10_07_100005_create_factures_recues_table.php`** :
  ```
  factures_recues
   id
   magasin_id                     FK cascade
   fournisseur_id                 FK fournisseurs nullable restrictOnDelete
   facture_achat_id               FK factures_achat nullable unique nullOnDelete
   type_document                  unsignedSmallInteger  (BT-3 : 380, 381, 384…)
   numero                         string(60)
   date_emission                  date
   date_echeance                  date nullable
   devise                         string(3)
   vendeur_nom                    string(200)
   vendeur_siren                  string(9) nullable
   vendeur_siret                  string(14) nullable
   vendeur_numero_tva             string(30) nullable
   vendeur_adresse_electronique   string(100) nullable
   total_ht, total_tva, total_ttc, montant_a_payer   decimal(12,2)   (tels que reçus)
   contenu                        json      (en_invoice complet, tel que reçu)
   statut                         string(20) default 'a_traiter'  a_traiter | importee | refusee | traitee
   motif_traitement_manuel        text nullable
   recue_le                       timestamp
   timestamps
   index(magasin_id, statut), index(magasin_id, fournisseur_id)
  ```
  **Pourquoi une table intermédiaire.** La facture reçue est la pièce légale telle qu'émise. On ne la recalcule pas et elle peut contenir ce que `FactureAchat` ne sait pas représenter. Elle porte aussi le cycle de vie côté acheteur. Une `FactureAchat` source Api, elle, n'est jamais modifiable : elle ne peut naître que d'une correspondance exacte.

### Modèle et enums

- `app/Domain/Achats/Models/FactureRecue.php` : `BelongsToMagasin`, HasFactory, relations `fournisseur`, `factureAchat`, `transmission` (morphOne). La transmission entrante porte l'identifiant SUPER PDP et l'archive.
- `app/Domain/Achats/Enums/StatutFactureRecue.php`.
- `app/Integrations/Pdp/Enums/StatutAcheteurPdp.php` : `Approuvee='fr:205'`, `EnLitige='fr:207'`, `Refusee='fr:210'`.
- `MotifStatutPdp.php` : codes MDT-113 de la norme XP Z12-012 (D6).

### Actions

**`app/Integrations/Pdp/Actions/ImporterFacturesRecuesAction::handle(ConnexionPdp $connexion): void`**

On boucle sur `facturesRecuesApres(curseur_factures_recues)` (liste des identifiants). Pour chaque identifiant :
1. Si une transmission entrante existe déjà avec cet identifiant : on avance le curseur.
2. `lireFactureRecue` (GET /invoices/{id}, en_invoice complet). Si `entrepriseExterneId ≠ connexion.entreprise_externe_id` : on n'enregistre rien, on journalise et on avance.
3. `telechargerOriginal`, puis `Storage put "pdp/{uuid}/recues/{id}.{ext}"`.
4. Dans `DB::transaction` :
   - création de `FactureRecue` via `LecteurEnInvoice` ;
   - création de la transmission (`in`, identifiant, format, archive) ;
   - enregistrement des `invoice.events` ;
   - `TrouverOuCreerFournisseurDepuisFactureRecueAction` ;
   - `curseur = id`.
5. Hors transaction : tentative d'`ImporterFactureRecueEnAchatAction`. Si `FactureRecueNonImportableException` : statut `a_traiter` et motifs enregistrés.

**`app/Integrations/Pdp/SuperPdp/Mapping/LecteurEnInvoice::lire(array $enInvoice): FactureRecueLue`**

DTO `FactureRecueLue` avec ses lignes `LigneRecueLue`. Seules les lignes `subtype` DETAIL (ou sans subtype) sont reprises. Champs lus :
- identifiants du vendeur : BT-30 schéma 0002, BT-29 schéma 0009, BT-31, BT-34 ;
- totaux BT-109, 110, 112, 115 ;
- remises et frais de pied (BG-20/21) ;
- références antérieures (BG-3) ;
- par ligne : quantité, unité, `item_net_price` / `item_price_base_quantity`, BT-131, catégorie et taux, BT-153, 155, 156, BT-157 schéma 0160, remises de ligne.

**`app/Domain/Achats/Actions/TrouverOuCreerFournisseurDepuisFactureRecueAction::handle(FactureRecueLue $facture): Fournisseur`**

Ordre de recherche, en incluant les fournisseurs archivés (`withTrashed`) :
1. SIRET ;
2. SIREN, si un seul fournisseur correspond ;
3. n° TVA.

Un fournisseur archivé trouvé est restauré. On complète uniquement les champs vides, sans jamais écraser une saisie manuelle. Si rien ne correspond, on crée le fournisseur avec la source `AutoPdp`. `Fournisseur::upsertDepuisFactureEntrante()`, jamais appelée et défectueuse, est supprimée.

**`app/Domain/Achats/Actions/ImporterFactureRecueEnAchatAction::handle(FactureRecue $factureRecue): FactureAchat`**

L'import automatique n'a lieu que si **toutes** les conditions suivantes sont réunies. Sinon, la facture passe en `FactureRecueNonImportableException` avec la liste des motifs :
- type 380 et devise du magasin ;
- aucune remise ni frais de pied ;
- chaque ligne DETAIL :
  - quantité entière et positive ;
  - PU HT (net / quantité de base) avec 2 décimales au plus ;
  - catégorie S à un taux français (20, 10, 5,5 ou 2,1) ;
  - remise de ligne exprimée en pourcentage ;
- le recalcul selon la règle du dépôt (`CalculateurLigne::calculer(PrixReference::Ht, …)` puis `totaliser`) redonne **au centime** BT-131 pour chaque ligne, ainsi que BT-109 et BT-110.

Grâce à cette dernière condition, la facture d'achat ne s'écarte jamais de la pièce légale (D2).

Rapprochement des produits :
1. `produits.reference` = BT-156 ;
2. sinon `code_barres` = BT-157 ;
3. sinon `reference` = BT-155 et `fournisseur_id` du fournisseur ;
4. sinon nouveau produit `['produit' => ['reference' => BT-155 ou "PDP-{fournisseur_id}-{rang}", 'code_barres', 'nom' => BT-153, 'taux_tva']]` (D5).

Enfin, `CreerFactureAchatAction::handle($fournisseur, $lignes, ['source' => Api, 'numero_fournisseur', 'date_facture', 'devise', 'notes'])`, avec `prix_reference='ht'`, puis le lien vers la facture d'achat et le statut `importee`. La validation (stock et CMUP) reste manuelle (D4).

**`app/Integrations/Pdp/Actions/EnvoyerStatutAcheteurAction::handle(FactureRecue $facture, StatutAcheteurPdp $statut, ?MotifStatutPdp $motif, ?string $commentaire): void`**

- Corps envoyé : `{"invoice_id":…,"status_code":"fr:210","details":[{"reason":"…","notes":[{"contents":[{"content":"…"}]}]}]}`. `details` est omis s'il est vide.
- Un motif est obligatoire pour un refus ou un litige.
- L'événement renvoyé est enregistré ; un refus passe la facture reçue au statut `refusee`.

### Écrans

- **`app/Filament/App/Resources/FactureRecueResource.php`** (pages List et View, groupe « Achats »).
  - Liste : date de réception, fournisseur, numéro, TTC, statut, statut PDP.
  - Fiche : lignes et ventilation lues depuis `contenu`, montants tels que reçus, événements.
  - Actions :
    - « Télécharger l'original » ;
    - « Importer en facture d'achat », qui affiche les motifs en cas d'échec ;
    - « Rattacher à une facture d'achat » : une facture d'achat du même fournisseur pas encore liée, puis statut `traitee` ;
    - « Approuver », « Mettre en litige », « Refuser » (fenêtre avec motif et commentaire).
- **`FactureRecuePolicy`** :
  - `FACTURE_RECUE_VIEW` : propriétaire, administrateur, comptable ;
  - `FACTURE_RECUE_TRAITER` : propriétaire, administrateur ;
  - à ajouter dans `PermissionRegistry` et `SeedMagasinRolesAction`.
- **`FactureAchatResource`** : lien vers la facture reçue et vers l'original.

### Tests (`tests/Feature/SuperPdp/ReceptionFacturesTest.php`)

Toutes les factures reçues viennent de Tricatel et sont adressées à Burger Queen.

- **Import d'une page** :
  - champs de la facture reçue identiques à ceux reçus ;
  - archive enregistrée, transmission `in`, événements, curseur ;
  - une seconde exécution ne crée aucun doublon.
- **Fournisseur** :
  - retrouvé par SIRET, ou par SIREN ;
  - fournisseur archivé restauré ;
  - créé en `AutoPdp` si rien ne correspond ;
  - une saisie manuelle n'est jamais écrasée.
- **Import automatique** :
  - conditions réunies : facture d'achat Api en brouillon dont HT et TVA sont identiques aux montants reçus ;
  - produits retrouvés par code-barres, ou créés.
- **Non importable** : quantité « 1.5 », PU « 1.1834 », remise de pied, recalcul de TVA 1,47 contre 1,46 reçu, type 381. Résultat : `a_traiter` avec les motifs, aucune facture d'achat.
- `company_id` étranger : facture ignorée.
- **Isolation** : chaque magasin n'importe que ce qui arrive avec son propre jeton.
- **Statut de refus** : le corps du fr:210 est exact, l'événement est enregistré, le motif est obligatoire, un vendeur reçoit 403.

---

## Phase 8 — Encaissement fr:212, puis b2c_payments

- **Événement** `app/Domain/Facturation/Events/PaiementEnregistre(Paiement $paiement)`, dispatché par `EnregistrerPaiementAction`. Il couvre aussi la caisse, qui passe par cette action.
- **Listener** `app/Integrations/Pdp/Listeners/DeclarerEncaissementAuPdpApresPaiement`. Pour chaque facture allouée, il dispatche `EnvoyerEncaissementAuPdpJob(magasinId, transmissionId)` en `afterCommit` si toutes ces conditions sont réunies :
  - transmission sortante déposée ;
  - `statut === Payee` ;
  - **uniquement des lignes de service** ;
  - `option_debits_tva === false` ;
  - aucun fr:212 déjà enregistré.
  Ce périmètre est le seul cas sans ambiguïté. Les autres attendent D7.
- **Job** :
  - si un essai précédent a peut-être abouti, on cherche d'abord un fr:212 avec `evenementsDeLaFacture` ;
  - sinon, `envoyerStatut(fr:212)` **sans `details`** : selon la spécification, le montant pris est alors le total de la facture ;
  - l'événement renvoyé est enregistré.
- **`b2c_payments`** (services payés par des particuliers sans option pour les débits) : à implémenter seulement après D8. Ce sont des lignes `type='paiement'` dans `declarations_ereporting`, préparées une fois par jour d'encaissement. Le schéma de la spécification est incohérent (`company_id` et `category_code` sont exigés sans être définis, T8).

**Tests** (`tests/Feature/SuperPdp/EncaissementTest.php`) :
- facture de services entièrement payée : un seul POST `{"invoice_id":4242,"status_code":"fr:212"}` ;
- biens seuls, option pour les débits ou paiement partiel : aucun envoi ;
- second paiement ou fr:212 retrouvé : aucun doublon.

## Phase 9 — B2B international (après D11)

- Le circuit `EReportingInternational` concerne les clients professionnels hors de France ; ils ne sont plus envoyés en facture électronique B2B.
- La déclaration passe par POST /v1.beta/b2bint_invoices (role SE, direction out), avec /b2bint_payments si besoin. Les achats à des fournisseurs étrangers vont en direction in (BY).
- Il faut d'abord modéliser la catégorie de TVA et le motif d'exonération par ligne (K, G, AE, E, O, Z), ce que le dépôt ne fait pas aujourd'hui.
- En attendant, l'écran de la facture indique « Déclaration internationale non prise en charge ».

## Phase 10 — Recette dans le bac à sable (manuelle, par l'éditeur, avec ses identifiants)

1. Dans `.env` : variables `SUPERPDP_*` et `SUPERPDP_ENVIRONNEMENT=sandbox`.
2. Magasin « Burger Queen » : SIREN 000000002, réel normal mensuel. Le connecter, puis vérifier : `Active`, adresse 0225:315143296_110452, PATCH `monthly` accepté.
3. Client professionnel Tricatel (000000001, adresse 0225:315143296_110451). Émettre une facture et un avoir, puis vérifier : `Deposee`, puis fr:200… après synchronisation, Factur-X archivé.
4. Second magasin Tricatel connecté à 000000001 :
   - réception dans « Factures reçues » ;
   - Approuver ou Refuser ;
   - Burger Queen voit fr:205 ou fr:210.
5. Clôture de caisse fictive : `b2c_transactions`, puis contrôle par GET /ereportings/preview.
6. Relever les vérifications T1 à T12 et corriger le code si nécessaire.

## Découpage des commits

Un commit par phase (1, 2, 3, 4, 5, 6, 7, 8), chacun suivi de la suite complète, de pint, de la relecture adversariale et de la CI.

À ajouter à `TenantIsolationTest` (fournisseur de données), avec les factories correspondantes : `ConnexionPdp`, `TransmissionPdp`, `EvenementTransmissionPdp`, `DeclarationEReporting`, `FactureRecue`.

Fixtures dans `tests/Fixtures/SuperPdp/` :
- `token.json`, `oauth2_session_verifiee.json`, `oauth2_session_en_revue.json`
- `company_burger_queen.json`, `company_tricatel.json`, `directory_entries_burger_queen.json`
- `validation_report_valide.json`, `validation_report_invalide.json`
- `invoice_deposee.json`, `invoices_out_page.json`, `invoice_events_page_1.json`, `invoice_events_page_2.json`
- `invoices_in_page.json`, `invoice_recue_tricatel.json`
- `b2c_transaction_creee.json`, `b2c_transactions_page.json`, `ereporting_77.json`
- `http_ko_400.json`
- `en_invoice_facture.json`, `en_invoice_avoir.json`

---

## Décisions à soumettre à l'éditeur

Pour chaque point : la question, la proposition retenue par défaut dans ce plan, et l'impact.

1. **D1 — TVA par taux dans une facture électronique (BT-117).**
   - Problème : la règle du logiciel additionne les TVA enregistrées ligne par ligne. EN 16931 (BR-CO-17 / BR-S-09) attend base × taux, arrondi une fois, avec une tolérance probable de ±1 €. Exemple : 1000 × 1,00 € TTC à 20 % donne 170,00 € dans le logiciel contre 166,00 € attendus, ce qui sera rejeté.
   - Par défaut : montants enregistrés, conformément à CLAUDE.md.
   - Options : accepter le risque, ou changer la règle de calcul des ventes B2B.
2. **D2 — Factures d'achat reçues.**
   - Question : faut-il les enregistrer telles quelles, ou les recalculer selon la règle du dépôt ?
   - Par défaut : import automatique seulement si le recalcul est identique au centime ; sinon traitement manuel.
3. **D3 — Cas non pris en charge à l'import.**
   - Question : que faire des quantités non entières, des unités KGM ou LTR, des PU à plus de 2 décimales, des remises et frais de pied, des remises en montant, et des avoirs reçus (381) sans retour de marchandise ?
   - Par défaut : traitement manuel.
4. **D4 — Validation d'une facture d'achat importée.**
   - Question : l'entrée en stock et le CMUP doivent-ils être automatiques, ou attendre la réception physique ?
   - Par défaut : validation manuelle.
   - Question liée : une facture refusée (fr:210) mais déjà importée doit-elle avoir un statut dédié ?
5. **D5 — Produits inconnus à l'import.**
   - Par défaut : création automatique, avec référence BT-155 ou générée.
   - Alternative : rapprochement manuel.
6. **D6 — Statuts côté acheteur.**
   - Liste exacte des motifs MDT-113 (XP Z12-012) pour le refus et le litige, à fournir.
   - Statuts proposés : 205, 207 et 210. Par défaut, pas de 204 automatique.
   - Qui peut refuser : propriétaire et administrateur par défaut.
7. **D7 — Encaissement fr:212.**
   - Périmètre : services seuls, ou factures mixtes ?
   - Paiements partiels : faut-il `reported_data` « MEN » ? Le montant est-il HT ou TTC, ventilé par taux ?
   - Facture déjà payée à l'émission : envoie-t-on quand même ?
   - Par défaut : services seuls, entièrement payés, sans option pour les débits, sans `details`.
8. **D8 — `b2c_payments`.**
   - Faut-il les déclarer pour les services payés par des particuliers ?
   - Montant TTC ou HT ?
   - Comment répartir un paiement partiel ou une facture mixte ?
   - Le crédit d'un avoir de service doit-il apparaître ?
   - Par défaut : rien n'est envoyé avant décision.
9. **D9 — Codes de date d'exigibilité.**
   - `tax_due_date_type_code` (UNTDID 2005 ou 2475) pour TLB1 et TPS1, et `vat_point_date_code` (BT-8) sur une facture avec option pour les débits : quelles valeurs ?
   - Par défaut : omis.
10. **D10 — Ventes à 0 % et franchise en base.**
    - Quelle catégorie (E, Z, O, K, G) et quel motif VATEX ? En B2C, TNT1, ou TLB1/TPS1 à 0 % ?
    - Par défaut : refus de transmission avec un message clair.
    - À vérifier aussi : un magasin en franchise facture-t-il bien sans TVA, avec la mention de l'article 293 B ?
11. **D11 — Clients professionnels étrangers et achats à l'étranger.**
    - Question : déclaration B2BInt (/b2bint_invoices), ou envoi Peppol avec `processing_rule=B2BInt` ?
    - Prérequis : modéliser la catégorie de TVA des lignes.
12. **D12 — Plusieurs magasins avec le même SIREN.**
    - Par défaut : refusé, une entreprise SUPER PDP pour un seul magasin.
    - Alternative : adresses `SIREN_SIRET` ou `SIREN_SUFFIXE` via `superpdp_directory_entry_identifier`, pour répartir les factures reçues.
13. **D13 — Rattrapage.**
    - Question : factures et avoirs B2B émis, ou clôtures, avant la connexion ou pendant une coupure.
    - Par défaut : action manuelle « Transmettre » pour les documents ; pas de rattrapage des clôtures antérieures à la connexion.
14. **D14 — Mentions légales B2B (notes PMT, PMD, AAB).**
    - Elles sont probablement exigées (BR-FR-05) et absentes du PDF actuel.
    - Question : quels textes, et sont-ils réglables par magasin ?
15. **D15 — Cadre de facturation (BT-23) et `specification_identifier`.**
    - Par défaut : B1, S1 ou M1 ; B2, S2 ou M2 si la facture est soldée à l'envoi ; `urn:cen.eu:en16931:2017`.
16. **D16 — SIREN des clients professionnels.**
    - Par défaut : obligatoire pour un client professionnel français (mention obligatoire en facturation électronique).
17. **D17 — Fin de l'agrégation par période.**
    - Par défaut : suppression de SoumissionEReporting et de son calendrier, remplacés par une déclaration par clôture et le suivi des statuts PPF.
18. **D18 — Format transmis.**
    - Par défaut : Factur-X, préférence de CLAUDE.md, via /convert avec notre PDF.
    - Repli : CII si /convert ne renvoie pas de PDF/A-3 valide.
19. **D19 — Validation des charges contre la spécification.**
    - Question : faut-il ajouter la dépendance de développement `opis/json-schema` ? L'ajout d'une dépendance demande l'accord de l'éditeur.
    - Par défaut : petit vérificateur interne aux tests.
20. **D20 — Identité fiscale du magasin.**
    - Par défaut : saisie dans ParametresFiscaux et contrôlée à la connexion par /companies/me.
    - Question liée : faut-il ajouter le contrôle Luhn du SIREN en production ?
21. **D21 — Unité de mesure.**
    - Par défaut : `C62` partout, puisque le dépôt ne gère que des quantités entières.
22. **D22 — Adresse de réception dans l'annuaire.**
    - Par défaut : créée par le parcours SUPER PDP, avec `superpdp_send_and_receive=any` implicite, et seulement affichée chez nous.
    - Alternative : bouton POST /directory_entries.
23. **D23 — Conservation des archives Factur-X et des originaux reçus.**
    - Par défaut : 10 ans dans `storage/app/private/pdp/{uuid}`, en lien avec l'étape 9.
24. **D24 — Ordre de la feuille de route.**
    - Confirmer que SUPER PDP passe avant les étapes 1 à 10, sans cocher l'étape 11.
    - Faut-il avancer la réception (obligatoire depuis le 1er septembre 2026) avant l'émission ?

## Vérifications techniques à faire dans le bac à sable (à constater, pas à décider)

- **T1** — Réponse de /oauth2/token : `expires_in`, présence d'un `refresh_token` et rotation.
- **T2** — PKCE : exigé ou seulement toléré ? Le client s'authentifie-t-il en `client_secret_basic` (choix par défaut) ou en `client_secret_post` ?
- **T3** — Les routes /companies/me et PATCH répondent-elles 403 tant que la connexion est `needs_review` ? Une reconnexion relance-t-elle la vérification de l'entreprise (KYB) ?
- **T4** — Valeurs exactes attendues : `specification_identifier`, schéma 0002 pour les numéros fictifs du bac à sable. À relever avec GET /invoices/generate_test_invoice?format=en16931 sur Tricatel.
- **T5** — /convert vers factur-x avec notre PDF : type de contenu renvoyé, conformité PDF/A-3. /validation_reports accepte-t-il le Factur-X ?
- **T6** — Longueur réelle de `external_id` (36 ou 64) ; présence d'`external_id` dans la liste GET /invoices, nécessaire à `retrouverDepot`.
- **T7** — `b2c_transactions` : accepte-t-elle des montants négatifs ? Comment est traitée une date déjà envoyée au PPF ? Un envoi groupé est-il atomique ?
- **T8** — `b2c_payment_subtotal.category_code` et `b2c_payment.company_id` sont-ils réellement attendus ?
- **T9** — Tolérance réelle de BR-CO-17 en pratique (permet de chiffrer le risque de D1).
- **T10** — Événement final d'un e-reporting (`-ack` ou `-response-ok`).
- **T11** — Sens de `fr:220` ; présence de `events` dans GET /invoices/{id} ; limites de débit.
- **T12** — Les suffixes 110451 et 110452 des adresses Peppol sont-ils les `company.id` ?

Références hors dépôt :
- spécification : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/superpdp/openapi.json`
- exemples validés : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/exemples/facture.json`, `avoir.json` et `fr212.json`
- validateur Python : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/tools/valider.py`
- essais d'e-reporting : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/essais_ereporting/essai.py`