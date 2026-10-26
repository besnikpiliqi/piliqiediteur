# PILIQI : architecture société → établissements → membres — conception v2 finale

**Base de travail**
- Dépôt lu en lecture seule au commit `b2bce46` (« Relecture du lot 19 des décisions », 2026-10-11). Le lot de décisions 20 (preuves d'exonération) est en cours dans l'arbre de travail : fichiers non suivis `app/Domain/Facturation/Enums/TypePreuve.php`, `Models/PreuveOperation.php`, `Models/BordereauDetaxe.php`, `Services/PreuvesRequises.php`, migrations `2026_10_03_100007_create_preuves_operation_table.php` et `2026_10_03_100008_create_bordereaux_detaxe_table.php`.
- Positions marquées **[b2bce46]** : relues à ce commit. `Magasin.php`, `User.php`, `RegistreFiscal.php`, `Emetteur.php`, `ControleDesDroits.php`, `TenantContext.php` n'ont pas changé depuis `12c2f77` (vérifié par `git diff --name-only 12c2f77 HEAD`) : leurs numéros de ligne de la v2 restent justes. Ont changé depuis : `EmettreFactureAction` (verrou du registre ligne 116, numérotation ligne 142, `FactureEmise` ligne 155), `AjouteDesLignesDeVente` (remise ligne 231, PU TTC lignes 268-295), `ConstructeurLigneDocument` (PU TTC lignes 189-191), `ContenuFiscal`, `MentionsFiscales`, migrations `services`, `factures`, `avoirs`. Tout fichier se relit avant modification (le lot 20 en déplacera encore).
- Versions (`vendor/composer/installed.json`) : Laravel 13.34.0, Filament 3.3.55, spatie/laravel-permission 6.25.0 (retiré par cette conception [S12]). Spécification SUPER PDP 1.36.0.beta (`tests/Fixtures/SuperPdp/openapi.json`).
- Réponses de l'éditeur suivies à la lettre : `scratchpad/societe/reponses-editeur.md` (Q1 à Q3) et `scratchpad/societe/reponses-serie4.md` (S1 à S14, V1, V2, T1 à T6, R1 à R5) ; une note ou un choix « autre » prime sur le texte de l'option. Texte complet des options : `scratchpad/decisions/serie4-donnees.js`. Stratégie de campagne : `scratchpad/campagne/strategie/finale.md`.
- Cette version remplace la v2 (`conception-v2.md`) et intègre ses deux relectures adversariales ; l'annexe C dit, constat par constat, ce qui est intégré et ce qui est écarté, avec la raison. Chaque section se lit seule.

**Légende**
- **Vérifié** : lu dans le code, dans `vendor` ou dans la spécification versionnée.
- **Source web** : extrait de recherche ; texte officiel non relu en entier (les sites `*.gouv.fr`, `legifrance.gouv.fr` et `cnil.fr` ne répondent pas depuis cette machine).
- **Supposé** : déduction à confirmer à l'implémentation ou par l'expert-comptable.
- **[S6]**, **[V1-2]**, **[R1]** : réponse de l'éditeur appliquée. **[Q2 éditeur]** : point soumis à l'éditeur en section 15.

---

## I. Résumé pour l'éditeur (en français simple)

**Ce que devient PILIQI.** Le client de PILIQI est désormais l'entreprise (« société » dans le logiciel : une société ou un entrepreneur individuel, avec un SIREN). Elle a un propriétaire, des administrateurs, et autant de magasins qu'elle a d'établissements (un SIRET chacun). L'identité légale (raison sociale, forme, capital, siège, n° de TVA) et les réglages fiscaux (régime de TVA, option pour les débits, mois de fin d'exercice) n'existent qu'une fois, sur la société. Il n'y a plus de « magasin principal ». Chaque magasin garde sa caisse, ses stocks, ses ventes, ses clients, son catalogue et ses réglages locaux, sans rien partager avec les autres.

**Comment une société est vérifiée, et pourquoi cette combinaison.** Un SIREN et un SIRET sont publics : n'importe qui peut taper ceux d'une vraie entreprise. Aucune vente réelle n'est donc possible avant la validation de la société, qui se fait en trois couches.
1. **L'entreprise existe** (automatique, gratuit, immédiat) : PILIQI interroge le répertoire Sirene de l'INSEE à l'inscription et à chaque nouveau magasin. Entreprise cessée, établissement fermé ou numéro inconnu : refus.
2. **Le propriétaire a le droit d'agir pour elle** (une seule de ces preuves suffit) :
   - il vérifie son identité en ligne chez un prestataire certifié par l'ANSSI (pièce d'identité et visage, quelques minutes), et PILIQI la compare aux dirigeants inscrits au registre national des entreprises (INPI) ; si le président est une autre société (une holding), PILIQI remonte à son représentant ;
   - s'il n'est pas dirigeant, un dirigeant ainsi vérifié approuve en ligne son mandat ;
   - dès l'intégration SUPER PDP (étape 2), une connexion SUPER PDP vérifiée au même SIREN suffit : la plateforme agréée est elle-même tenue de vérifier la qualité de ses utilisateurs ; c'est la voie la moins chère, et chaque société devra s'y connecter de toute façon ;
   - à défaut, vous examinez des pièces (Kbis ou extrait RNE, pièce d'identité, mandat), déposées dans l'application et effacées dès votre décision ; mais comme ces pièces peuvent être volées ou se procurer facilement, cette voie manuelle est **toujours** complétée par un courrier recommandé.
3. **Le courrier recommandé à code**, envoyé à l'adresse officielle lue dans Sirene (ou une lettre recommandée électronique qualifiée remise à un dirigeant identifié), seulement quand il le faut : voie manuelle, risque détecté (autre inscription du même SIREN, ancienne usurpation, dirigeant tout juste nommé, essais ratés...). Il prouve que l'on reçoit le courrier à cette adresse, jamais à lui seul la qualité de représentant.

En plus, à chaque validation, une **lettre simple d'information** part au siège : « votre entreprise vient d'être validée sur PILIQI par {nom} ; si ce n'est pas vous, contactez l'éditeur ». Pour quelques centimes, le vrai dirigeant est prévenu de toute usurpation.

Pourquoi ainsi : c'est automatique pour la grande majorité des sociétés ; le coût n'est payé que dans les cas douteux ; un usurpateur ne peut ni bloquer le vrai titulaire (le SIREN n'est réservé qu'à la société validée), ni apprendre qu'une entreprise est cliente de PILIQI, ni réussir avec de simples documents publics.

**Le mode démonstration.** Avant la validation, la société se configure (identité, magasins, équipe, catalogue, achats) et peut ouvrir un magasin « Démonstration » : on y vend, encaisse, clôture et imprime comme pour de vrai, mais avec une identité fictive (aucun SIREN ni SIRET), des documents marqués « DÉMONSTRATION — SANS VALEUR », ses propres numéros et son propre registre, sans aucun e-mail ni aucune transmission, jamais facturé. Rien n'en sort vers les vrais magasins. Il se ferme à la validation et reste consultable. La loi traite les ventes d'entraînement comme les autres : elles sont signées, conservées et contrôlées, et la notice et l'attestation de la caisse le diront. Avant la validation, aucun PDF d'un vrai magasin ne se télécharge : un usurpateur ne peut pas fabriquer un faux devis au nom de la victime.

**Ce qui change pour les administrateurs.** Le propriétaire crée un administrateur directement, dans un seul formulaire : il choisit son accès (tous les magasins, présents et futurs, ou une sélection) et coche ses autorisations. Un administrateur d'une sélection ne voit que ses magasins et leurs membres, ne crée pas de magasin et ne touche pas aux réglages de la société. Un administrateur ne touche jamais au propriétaire ni à un autre administrateur, et ne donne jamais plus que ce qu'il a lui-même.

**Ce qui change pour les membres.** Le propriétaire (ou un administrateur autorisé) crée directement le compte d'un responsable, d'un vendeur, d'un comptable ou d'un observateur, avec un mot de passe provisoire remis en main propre, que la personne change à sa première connexion (72 heures pour s'en servir). Ensuite, un mot de passe oublié se renouvelle par un lien envoyé à la personne : personne d'autre ne connaît son mot de passe, donc personne ne peut vendre en son nom. Un membre peut être affecté à plusieurs magasins de sa société, avec des cases différentes dans chacun. Chaque vente enregistre le magasin où elle est faite (son SIRET) et la personne qui l'a faite, dont l'autorisation est relue au moment même de la vente. Un compte n'appartient qu'à une société ; une fois retiré par elle, la personne peut, avec son accord, rejoindre une autre société ou créer la sienne avec le même compte.

**Ce qui change pour les permissions.** Le paquet Spatie disparaît : les autorisations sont rangées dans des tables propres à PILIQI, relues en base à chaque requête, sans cache global (le cache actuel coûterait 79 s et 1,9 Go à 22 540 magasins). Le rôle ne fait que précocher des cases. Deux autorisations arrivent : « vendre sans stock » (après un avertissement) et « accorder des remises et fixer le prix d'un service ». La gestion de l'équipe quitte l'écran du magasin pour l'espace d'administration de la société.

**Ce que la société fait elle-même.** Fermer et rouvrir un magasin (mot de passe redemandé ; la fermeture clôture la caisse et arrête les tickets Z, l'abonnement suit), changer le SIRET d'un magasin qui déménage, céder un établissement (le stock sort au coût moyen), transmettre la propriété. Ce qui reste à l'éditeur, par des commandes journalisées : la validation manuelle, la révocation d'un usurpateur, la libération d'un SIRET saisi par erreur, la correction exceptionnelle d'un SIREN, le changement légal de fin d'exercice.

**Sept questions vous sont posées** (section 15) : le prix d'une ligne de produit, le vendeur de plusieurs magasins, le moment du verrou du SIREN, les durées de conservation du journal d'audit, l'adresse e-mail prise par une autre société, l'émetteur d'un avoir de régularisation, les clôtures d'une fermeture temporaire. Chacune a une réponse recommandée, et rien de ce qui en dépend n'est construit avant votre réponse.

---

## II. Résumé des choix

1. Une table `societes` porte le SIREN, l'identité légale (forme juridique parmi SARL, EURL, SAS, SASU, SA, SCA, SE, SNC, SCS, entrepreneur individuel ou autre), le capital, l'immatriculation, le siège ou l'adresse de l'entreprise, le n° de TVA, les réglages fiscaux communs, le mois de fin d'exercice, le statut et l'état de vérification [S3, S4, S5].
2. Aucune opération commerciale réelle (ticket, facture, avoir, paiement, devis numéroté, e-mail à un client, transmission, PDF d'un document) avant la validation : Sirene, puis qualité de représentant (identité vérifiée rapprochée du RNE, mandat approuvé, SUPER PDP vérifié, ou pièces examinées par l'éditeur **toujours complétées** par le courrier), puis chaque signal de risque levé par la vérification qu'il exige [S6, S7, S11].
3. Un SIREN et un SIRET ne sont réservés qu'à la validation ; une inscription concurrente n'est jamais refusée par une autre validation ; le vrai titulaire évincé par un usurpateur dispose d'une réclamation qui aboutit (section 9.3).
4. Membres créés directement, mot de passe provisoire (72 h) changé à la première connexion ; renouvellement ensuite par lien envoyé à la personne ; administrateurs créés en une fois avec leur portée [S1, V1-2].
5. Multi-affectation dans la société ; traçabilité stricte du magasin et du vendeur, autorisation relue sous le verrou du registre [V1-1] ; l'exclusivité d'une prise de poste est soumise à l'éditeur [Q2 éditeur].
6. spatie/laravel-permission retiré ; tables propres, contrôles côté serveur, contraintes de base, tests de concurrence [S12].
7. Fermeture et réouverture par la société, suspension de l'éditeur rangée à part et jamais levée par une réouverture ; périodes d'activité pour les tickets Z et les abonnements [S8, S9] ; cession [S10].
8. Pas de correction systématique du SIREN ; procédure exceptionnelle de l'éditeur ; régularisation seulement si la loi l'exige, sans mouvement de stock ni encaissement déclaré deux fois [S11].
9. SUPER PDP : une connexion par société ; attribution automatique des factures reçues ; « À attribuer » seulement si incertain ou contradictoire [S2].
10. Journal d'audit en ajout seul, chaîné par magasin et par société, protégé, consultable par le propriétaire seul ; adresse IP et navigateur rangés à part, **y compris pour le journal des événements de la caisse**, et effacés après 1 an [S13].
11. Mois de fin d'exercice sur la société, verrouillé à la première vente ; changement légal par procédure de l'éditeur avec la date de fin tirée du procès-verbal [S14].
12. R1 à R5 : vente sans stock sur autorisation avec avertissement, remises et prix sur autorisation, jeton unique par action lié au contenu de la demande, TVA répartie par cumul, caisses personnelles fermées d'office [R1 à R5].
13. Ordre des lots refait pour que chaque lot soit vert seul : préalables indépendants (R4, R5, traces techniques) → socle société → vérification et démonstration → comptes et autorisations → cycle de vie des magasins → R1 à R3 → procédures de l'éditeur → lot 21 étendu → campagne [T1].
14. Sept questions restent (section 15).

---

## 0. Que fais-je des échecs ?

- **Dans cette mission** : lecture seule ; ni test, ni migration, ni fichier du dépôt modifié ; aucun échec de test à traiter. Recherches web limitées aux extraits ; les codes de catégorie juridique INSEE, l'API RNE de l'INPI et les articles cités restent marqués « source web » ou « supposé ».
- **Pendant les lots** (règles 3 et 4 de CLAUDE.md) : tests ciblés pendant le travail ; avant chaque commit `php artisan migrate:fresh`, `php artisan test --parallel ; echo $?`, la même suite sur PostgreSQL (commande de la règle 3) `; echo $?`, les deux codes valant 0 ; `vendor/bin/pint --dirty --format agent` ; un test qui échoue se corrige à sa cause, jamais en supprimant, affaiblissant ou réécrivant le test ; une règle métier ou une obligation légale révélée est soumise à l'éditeur.
- **Tests qui encodent une règle remplacée par l'éditeur** (un compte = un magasin, magasin principal, rattachement par SIREN, seul le propriétaire coche, clôture bloquée par une caisse personnelle, adresse IP dans le contenu signé) : chacun a une destination dans l'annexe B (conservé, réécrit avec une garantie au moins aussi forte, ou retiré en nommant la réponse qui abolit la règle) ; leurs garanties de sécurité sont reprises.
- **Échecs à l'exécution** : fermé par défaut.

| Échec | Effet |
|---|---|
| Un registre d'un des magasins refuse d'écrire (clé absente, horloge qui recule) | Rien n'est enregistré (réglage, droits, fermeture, procédure) ; message à l'écran |
| L'écriture du journal d'audit ou d'une trace échoue | L'action est annulée (même transaction) |
| L'INSEE ne répond pas à l'inscription | Demande acceptée ; contrôle repris par `app:reverifier-sirene` ; aucune vente |
| L'INSEE répond « fermé » ou « inconnu » pour un nouveau magasin, une réouverture, un changement de SIRET | Refus, rien n'est créé ni changé |
| L'INSEE ne répond pas pour un nouveau magasin ou une réouverture | Magasin créé ou rouvert « établissement à vérifier » : aucune vente avant confirmation. Pour un changement de SIRET : refus, à réessayer |
| Revérification Sirene négative d'une société validée | Alerte au propriétaire et à l'éditeur ; aucun blocage automatique des ventes ni des paiements |
| L'INPI, le prestataire d'identité ou l'envoi recommandé ne répond pas | Étape « en attente », réessayable ; jamais validée par défaut |
| Rappel de prestataire mal signé ou rejoué | 401, rien n'est enregistré, journal de l'application |
| Code de courrier faux, expiré, utilisé ; cinquième essai manqué | Refus ; au cinquième essai le code est annulé et le signal `essais_repetes` levé |
| Deux validations simultanées d'un même SIREN | L'index unique partiel n'en retient qu'une ; l'autre devient une réclamation possible, sans rien révéler |
| Vente au-delà du stock sans autorisation, ou sans confirmation à jour | Refus, aucun numéro consommé, rien de signé [R1] |
| Même jeton, même demande | Résultat déjà enregistré renvoyé, rien n'est refait [R3] |
| Même jeton, demande différente | Refus « demande différente déjà enregistrée sous ce jeton » [R3] |
| Fermeture d'office d'une caisse personnelle qui échoue après une clôture du jour | La clôture reste faite ; reprise à minuit [R5] |
| Procédure de l'éditeur interrompue | Transaction annulée, rien de partiel |
| Lien d'inscription, de rattachement ou de réinitialisation expiré, rejoué, annulé | Refus, à redemander |
| `runAs` d'un magasin d'une autre société dans un contexte de société | `LogicException`, rien n'est écrit |

---

## 1. Modèle de données

### 1.1 Vue d'ensemble

```
societes (SIREN, identité légale, siège, réglages fiscaux, statut, validée / refusée, réclamation)
 ├─ magasins (societe_id, SIRET, code, adresse, coordonnées, paramètres locaux, statut actif/fermé,
 │    │       suspendu_le, démonstration, établissement vérifié)
 │    ├─ sirets_magasins (SIRET réservés, uniques dans tout le logiciel)
 │    ├─ periodes_activite (ouverture, fermeture, motif)
 │    ├─ actions_traitees (jetons [R3], 30 jours)
 │    └─ toutes les données BelongsToMagasin (inchangées)
 ├─ users (un compte = une société ; rôle de société, statut, mot de passe provisoire, qualité vérifiée)
 │    ├─ affectations (magasin ou « tous » ; modèle) ── autorisations_affectations
 │    └─ autorisations_societe (administrateurs)
 ├─ journal_audit (ajout seul, chaînes par magasin et par société, expiration par catégorie)
 │    └─ tetes_journal_audit ; traces_audit (IP, navigateur, 1 an)
 ├─ verifications_societe ; codes_confirmation ; pieces_verification ; demandes_accord_dirigeant
 ├─ demandes_rattachement
 └─ changements_fin_exercice
enregistrements_fiscaux ── traces_evenements (IP, navigateur des JET, 1 an)
demandes_inscription (avant toute société ; purgées 48 h après expiration)
```

### 1.2 Définitions uniques (écrites une fois, dans le docblock de la méthode)

- **Société validée** : `societes.validee_le` non nul ; écrit par la seule `ValiderLaSocieteAction`.
- **Premier document numéroté d'un magasin** : une série `sequences_documents` du magasin hors références produit (`Magasin::codeEstDefinitif()` [b2bce46, `Magasin.php:219`], renommé `aNumeroteUnDocument()`). Il verrouille le code du magasin.
- **Première vente de la société** : un `EnregistrementFiscal` de la chaîne `vente` dans l'un de ses magasins réels (`Societe::aVenduUneFois()`). Il verrouille le mois de fin d'exercice.
- **Premier document fiscal de la société** : `Societe::aEmisUnDocumentFiscal()` (même lecture que la première vente, chaînes `vente` et `encaissement`) ; sert au verrou du SIREN si l'éditeur choisit l'option (b) de la question Q3.
- **Magasin qui peut vendre** : `Magasin::peutVendre()` (section 7.8).
- **Compte jamais connecté** : `users.premiere_connexion_le` nul.

### 1.3 Tables

Une migration de création par table (convention du dépôt, `database/migrations/`), modifiée en place puis `migrate:fresh`. Les index uniques partiels, que le Blueprint de Laravel 13 n'écrit pas, se créent par `DB::statement('CREATE UNIQUE INDEX … WHERE …')` (syntaxe commune à SQLite et PostgreSQL), nom explicite de moins de 63 caractères. Les invariants qui demanderaient une contrainte CHECK sont gardés par le modèle et prouvés par un test nommé.

**Protection** (`App\Support\DeclencheursDeProtection` [b2bce46]) : `sansSuppression`, `ajoutSeul` existent ; une méthode s'ajoute, `effacementApresExpiration(string $table)` : déclencheur qui refuse tout UPDATE et n'autorise un DELETE que si `OLD.expire_le` est non nul et passé (SQLite : `BEFORE DELETE … WHEN OLD.expire_le IS NULL OR OLD.expire_le > CURRENT_TIMESTAMP SELECT RAISE(ABORT, …)` ; PostgreSQL : fonction `plpgsql` équivalente). Tests : `test_une_trace_ne_se_modifie_jamais`, `test_une_trace_ne_s_efface_pas_avant_son_expiration`, `test_une_trace_expiree_s_efface`, sous les deux moteurs.

#### `societes` — `0001_01_00_000000_create_societes_table.php` (créée)

| Colonne | Type et rôle |
|---|---|
| `id`, `uuid` | `uuid` unique, slug du panneau société |
| `siren` | `string(9)` non nul ; `index(siren)` ; `societes_siren_valide_unique ON societes (siren) WHERE validee_le IS NOT NULL AND statut <> 'revoquee'` |
| `raison_sociale` | `string(200)` nullable, préremplie depuis Sirene (en diffusion partielle : saisie) |
| `forme_juridique` | `string(30)` nullable, cast `FormeJuridique` |
| `forme_juridique_autre` | `string(100)` nullable (seulement `Autre`) |
| `societe_commerciale` | booléen nullable : réponse obligatoire pour `Autre` (« société commerciale : oui ou non »), nulle sinon |
| `categorie_juridique_insee` | `string(4)` nullable : dernier code lu dans Sirene |
| `capital_social` | `decimal(14,2)` nullable |
| `capital_variable` | booléen, défaut false : imprime « à capital variable » (supposé : L231-2 C. com., à vérifier) |
| `immatriculation` | `string(100)` nullable (RCS, RM ou mention de dispense) |
| `numero_tva` | `string(20)` nullable, cast `NumeroTvaNormalise` ; proposé depuis le SIREN (clé `(12 + 3 × (SIREN mod 97)) mod 97`, python-stdnum `siren.py:77-83`) |
| `siege_adresse`, `siege_complement_adresse`, `siege_code_postal` (`string(10)`), `siege_ville` (`string(100)`) | nullables ; siège social, ou adresse de l'entreprise pour un entrepreneur individuel [S3, S5] |
| `regime_tva` | `string(30)` nullable, cast `RegimeTva` |
| `option_debits_tva` | booléen, défaut false |
| `mois_fin_exercice` | `unsignedTinyInteger`, défaut 12 |
| `statut` | `string(20)`, défaut `active` (`StatutSociete`) |
| `validee_le` | `timestamp` nullable |
| `refusee_le` | `timestamp` nullable : refus définitif, seulement par l'éditeur ou par une revérification qui trouve l'unité cessée avant la validation |
| `reclamation_ouverte_le` | `timestamp` nullable : réclamation d'un SIREN validé par une autre société, ouverte par l'éditeur (9.3) |
| timestamps ; `index(statut)` | |

- Gardes du modèle : jamais `validee_le` et `refusee_le` tous deux non nuls (`test_une_societe_n_est_jamais_a_la_fois_validee_et_refusee`) ; `siren` refusé à la modification selon la réponse Q3 (option (a) : dès `validee_le` ; option (b) : dès `aEmisUnDocumentFiscal()`), sauf procédures de l'éditeur ; `mois_fin_exercice` refusé dès `aVenduUneFois()`, sauf la procédure 9.5.
- `sansSuppression('societes')`.

#### `magasins` — `0001_01_01_000000_create_magasins_table.php` (modifiée)

- **Ajoutées** : `societe_id` (`foreignId` → `societes`, `restrictOnDelete`, non nul) ; `est_demonstration` (booléen, défaut false) ; `etablissement_verifie_le` (`timestamp` nullable : posé une fois, au premier contrôle Sirene concluant, remis à null seulement à une réouverture) ; `est_etablissement_principal` (booléen nullable, `true` ou `null`, informatif [V1-4]) ; `suspendu_le` (`timestamp` nullable : suspension par l'éditeur, étapes 12-13, indépendante de la fermeture) ; `references_acheteur` (`json` nullable : références que le magasin donne à ses fournisseurs, pour l'attribution des factures reçues, étape 2).
- **SIRET** : `siret` `string(14)` nullable ; garde : nul si et seulement si `est_demonstration` ; `adresse`, `code_postal`, `ville` nuls seulement pour la démonstration.
- **Statut** : `actif`, `ferme` (`StatutMagasin`, aujourd'hui `'active'/'suspended'/'closed'` [b2bce46, `StatutMagasin.php:16-18`]) ; la suspension passe dans `suspendu_le`.
- **Retirées** : `raison_sociale`, `forme_juridique`, `capital_social`, `siren`, `designe_principal`, `immatriculation`, `numero_tva`, `regime_tva`, `option_debits_tva` [migration `:31-45`, `:60`, `:64`], `deleted_at` (`:89`), index `unique(siret)` (`:95`), `unique(code)` (`:100`), `unique(siren, designe_principal)` (`:104`).
- **Index** : `unique(societe_id, code)` ; `unique(societe_id, siret)` ; `unique(societe_id, id)` (cible des clés composites) ; `magasins_un_principal_par_societe ON magasins (societe_id) WHERE est_etablissement_principal` ; `magasins_une_demonstration_par_societe ON magasins (societe_id) WHERE est_demonstration` ; `index(statut)`.
- Code `DEMO` réservé à la démonstration (`CodeMagasinAuFormat`). `sansSuppression('magasins')` gardé [`:112`].

#### `sirets_magasins` — `2026_10_02_100003_create_sirets_magasins_table.php` (créée)

- `id`, `societe_id`, `magasin_id` (clé composite `(societe_id, magasin_id)` → `magasins(societe_id, id)`, `restrictOnDelete`), `siret` (`string(14)`), `reserve_le`, `retire_le` (nullable : n'est plus le SIRET actuel), `libere_le` (nullable : libéré par 9.3), `motif_liberation` (`string(200)` nullable), timestamps.
- `sirets_magasins_siret_reserve ON sirets_magasins (siret) WHERE libere_le IS NULL` ; `sirets_magasins_un_actuel ON sirets_magasins (magasin_id) WHERE retire_le IS NULL AND libere_le IS NULL`.
- Ligne créée à la validation (magasins réels **actifs**), à la création d'un magasin dans une société validée, à un changement de SIRET ; jamais corrigée sur place. Seul écrivain : `App\Domain\Tenancy\Services\SiretsDesMagasins` (`reserver`, `remplacer`, `liberer`), qui écrit aussi `magasins.siret` ; garde `updating` de `Magasin` sur `siret` ; garde de `SiretMagasin` (seuls `retire_le`, `libere_le`, `motif_liberation` passent de null à une valeur) ; `sansSuppression`.
- Méta-test : `test_chaque_magasin_reel_actif_d_une_societe_validee_a_son_siret_actuel_au_registre_et_un_seul`.

#### `periodes_activite` — `2026_10_02_100004_create_periodes_activite_table.php` (créée)

| Colonne | Type et rôle |
|---|---|
| `id`, `magasin_id` | `foreignId` → `magasins`, `restrictOnDelete` |
| `debut` | `timestamp` : validation (magasins réels actifs), création dans une société validée, réouverture, ouverture de la démonstration |
| `fin`, `motif_fin` | nullables ; `MotifFermeture` : `temporaire`, `cessation`, `cession`, `fin_demonstration`, `resiliation_societe`, `revocation_societe` |
| `ouverte_par`, `fermee_par` | `foreignId` nullables → `users` (null : système) |
| timestamps | |

- `periodes_activite_une_ouverte ON periodes_activite (magasin_id) WHERE fin IS NULL` ; garde (seuls `fin`, `motif_fin`, `fermee_par` passent de null à une valeur) ; `sansSuppression` ; pas de scope global, toute requête filtre par `magasin_id` (règle 2).

#### `users` — `0001_01_01_000001_create_users_table.php` (modifiée)

| Colonne | Type et rôle |
|---|---|
| `societe_id` | `foreignId` → `societes`, `restrictOnDelete`, non nul |
| `role_societe` | `string(20)`, défaut `membre` (`RoleSociete`) |
| `statut` | `string(20)`, défaut `actif` (`StatutCompte` : `actif`, `suspendu`, `retire`) |
| `mot_de_passe_a_changer` | booléen, défaut false |
| `mot_de_passe_provisoire_expire_le` | `timestamp` nullable : 72 heures après sa remise |
| `premiere_connexion_le` | `timestamp` nullable |
| `qualite_verifiee_le` | `timestamp` nullable : qualité de représentant ou de mandataire prouvée **pour cette personne** (B1, B2, B3 à son nom, ou B4 avec C) ; la vérification qui l'a prouvée porte cette personne en `verifications_societe.beneficiaire_id` |

- Conservées : `id`, `uuid`, `name`, `email` (unique, minuscules sans espaces [b2bce46, `User.php:56-67`]), `email_verified_at`, `password`, `remember_token`, timestamps. Retirée : `deleted_at` [`:143`].
- Index : `unique(societe_id, id)` ; `index(societe_id, role_societe)` ; `users_un_proprietaire_par_societe ON users (societe_id) WHERE role_societe = 'proprietaire'`.
- Invariant « exactement un propriétaire actif par société » (société révoquée comprise : son propriétaire reste actif en consultation, 9.3) : test `test_chaque_societe_a_exactement_un_proprietaire_actif_apres_chaque_action_et_procedure`.
- `#[Fillable(['name', 'email', 'password'])]` inchangé [`User.php:26`].

#### `affectations` — `2026_10_02_100005_create_affectations_table.php` (remplace `magasin_user`)

| Colonne | Type et rôle |
|---|---|
| `id`, `societe_id`, `user_id` | clé composite `(societe_id, user_id)` → `users(societe_id, id)`, `restrictOnDelete` |
| `magasin_id` | nullable ; clé composite → `magasins(societe_id, id)` ; **null = tous les magasins, présents et futurs**, administrateur seulement |
| `modele` | `string(20)`, `RoleMagasin` |
| timestamps | |

- `unique(user_id, magasin_id)` ; `affectations_une_globale ON affectations (user_id) WHERE magasin_id IS NULL`.
- Gardes testées : un membre n'a jamais d'affectation globale ; un administrateur a une affectation globale ou des affectations par magasin, jamais les deux ; le propriétaire n'en a aucune.

#### `autorisations_affectations` — `2026_10_02_100006_create_autorisations_affectations_table.php`

- `affectation_id` (`cascadeOnDelete`), `autorisation` (`string(60)`) ; clé primaire `(affectation_id, autorisation)` ; `index(autorisation)`. Contrôle `PermissionRegistry::toutes()` par l'action qui écrit.

#### `autorisations_societe` — `2026_10_02_100007_create_autorisations_societe_table.php`

- `societe_id`, `user_id` (clé composite → `users(societe_id, id)`), `autorisation` (`string(60)`) ; clé primaire `(user_id, autorisation)` ; administrateur seulement ; contrôle `AutorisationsSociete::toutes()` et portée (4.3).

#### `demandes_inscription` — `2026_10_02_100000_create_demandes_inscription_table.php`

- `email` (normalisé), `jeton_hache` (`char(64)`, unique, SHA-256), `donnees` (`text`, `encrypted:array` : SIREN, NIC, nom et code du premier magasin, nom, mot de passe déjà haché, résultat Sirene), `expire_le` (24 h), `consommee_le`, `created_at`. Purge `app:purger-demandes-inscription` (quotidienne), 48 h après expiration ; sans protection.

#### `demandes_rattachement` — `2026_10_02_100012_create_demandes_rattachement_table.php`

- `id`, `societe_id`, `user_id`, `demande_par`, `affectations` (`json`), `jeton_hache` (`char(64)` unique), `expire_le` (7 jours), `acceptee_le`, `refusee_le`, `annulee_le`, timestamps ; `demandes_rattachement_une_en_attente ON demandes_rattachement (societe_id, user_id) WHERE acceptee_le IS NULL AND refusee_le IS NULL AND annulee_le IS NULL` ; `BelongsToSociete` ; la route `/rattachement/{jeton}` la cherche par empreinte (contournement documenté et testé).

#### `journal_audit` — `2026_10_02_100008_create_journal_audit_table.php` [S13]

| Colonne | Type et rôle |
|---|---|
| `id`, `societe_id` | non nul |
| `magasin_id` | nullable ; clé composite ; **désigne la chaîne** : chaîne du magasin si non nul, chaîne de la société sinon |
| `categorie` | `string(30)`, `CategorieAudit` : `operations` (opérations commerciales, fiscales, réglages et procédures), `acces` (comptes, droits, mots de passe, rattachements, transferts), `verification` |
| `sequence` | `unsignedBigInteger` |
| `origine` | `membre`, `editeur`, `systeme` |
| `auteur_id`, `auteur_libelle` | `foreignId` nullable → `users` ; libellé figé |
| `action` | `string(60)`, `ActionAuditee` |
| `cible_type`, `cible_id` | nullables |
| `anciennes_valeurs`, `nouvelles_valeurs` | `json` nullable, liste blanche de l'action |
| `justification`, `operateur` | obligatoires pour l'origine `editeur` |
| `cle_id` | `string(40)` : clé HMAC utilisée |
| `empreinte` | `char(64)` : HMAC-SHA256(clé, empreinte précédente de la chaîne ‖ contenu canonique) |
| `expire_le` | `timestamp` : `created_at` + durée de la catégorie (section 9.8, selon Q4) |
| `created_at` | |

- Chaîne = (portée, catégorie), où portée = magasin ou société : une expiration n'efface que le début d'une chaîne, jamais un maillon au milieu. Index uniques partiels : `journal_audit_sequence_magasin ON journal_audit (magasin_id, categorie, sequence) WHERE magasin_id IS NOT NULL` ; `journal_audit_sequence_societe ON journal_audit (societe_id, categorie, sequence) WHERE magasin_id IS NULL`. Index `(societe_id, created_at)`, `(cible_type, cible_id)`.
- Protection : `effacementApresExpiration('journal_audit')` et garde du modèle.
- Clé : `JOURNAL_AUDIT_CLE` et `JOURNAL_AUDIT_CLE_ID` (dédiées, distinctes de `APP_KEY` et de la clé fiscale) ; anciennes clés dans `JOURNAL_AUDIT_CLES_PRECEDENTES` (JSON `{id: clé}`) pour la vérification.
- Liste blanche (`ActionAuditee::champsAudites()`) ; aucun champ commençant par `password`, `remember_token`, `jeton`, `token`, `secret`, `code` (exception si une liste blanche en contient un ; test).

#### `tetes_journal_audit` — `2026_10_02_100009_create_tetes_journal_audit_table.php`

- `id`, `societe_id`, `magasin_id` (nullable), `categorie`, `sequence`, `empreinte`, `reprise_sequence`, `reprise_empreinte` (point de reprise après effacement du début de la chaîne), timestamps ; index uniques partiels par chaîne. Verrouillée **en dernier** (4.9). La vérification compare la dernière entrée à la tête ; à l'étape 3, l'archive signée de chaque magasin embarque la tête des chaînes de ce magasin et de sa société (ancrage hors base : un effacement des dernières entrées par le propriétaire de la base est détecté). Seuls écrivains : `JournalAudit::inscrire()` et `app:purger-journal-audit`.

#### `traces_audit` et `traces_evenements` — `2026_10_02_100010_create_traces_audit_table.php`, `2026_10_14_100004_create_traces_evenements_table.php`

- `traces_audit` : `entree_audit_id` (clé primaire, → `journal_audit`, `restrictOnDelete`), `ip` (`string(45)`), `agent_utilisateur` (`string(255)`), `expire_le` (+1 an), `created_at`.
- `traces_evenements` : `enregistrement_fiscal_id` (clé primaire, → `enregistrements_fiscaux`, `restrictOnDelete`), mêmes colonnes. **L'adresse IP et le navigateur sortent du contenu signé des JET** : `RegistreFiscal::evenement()` [b2bce46, `RegistreFiscal.php:371-385`] ne les met plus dans `donnees` ; ils vont dans `traces_evenements` dans la même transaction. Rien dans les règles de la caisse sécurisée n'exige l'adresse IP ; l'opérateur reste dans l'en-tête signé (`:415`).
- Les deux : `effacementApresExpiration`, lisibles par le propriétaire seul [V1-7], effacées par `app:purger-traces` (quotidienne, en lots, nombre effacé au journal de l'application).

#### `verifications_societe` — `2026_10_02_100011_create_verifications_societe_table.php` [S6, S7]

| Colonne | Type et rôle |
|---|---|
| `id`, `societe_id`, `magasin_id` (nullable, clé composite) | |
| `type` | `TypeVerification` : `sirene_unite`, `sirene_etablissement` (lot A2) ; `rne_representants`, `identite`, `mandat`, `courrier`, `lre`, `pdp_kyb`, `manuelle`, `signal`, `lettre_information` (lot A3) |
| `resultat` | `ResultatVerification` : `concluante`, `negative`, `indisponible`, `en_attente`, `a_examiner` |
| `fournisseur`, `reference` | `insee`, `inpi`, prestataire, `superpdp`, `editeur` ; référence de transaction ou de pli |
| `signal` | `string(40)` nullable : pour les lignes `signal` (levée ou constat) et `manuelle` (signal levé) |
| `details` | `json` nullable, liste blanche par type (état administratif, catégorie juridique, dénomination, adresse normalisée et sa source `sirene` ou `rne`, chaîne de rapprochement des dirigeants, nom et prénoms vérifiés, niveau de garantie) ; jamais l'image ni le numéro d'une pièce |
| `auteur_id`, `operateur` | |
| `beneficiaire_id` | `foreignId` nullable → `users` : la personne dont la ligne prouve l'identité ou la qualité |
| `expire_le` | nullable : posé à la fin de la relation selon Q4 (section 9.8) |
| `created_at` | |

- Protection : `effacementApresExpiration` (aucune ligne ne s'efface tant que `expire_le` est nul).

#### `codes_confirmation` — `2026_10_02_100013_create_codes_confirmation_table.php`

| Colonne | Type et rôle |
|---|---|
| `id`, `societe_id`, `siren` | `siren` copié : limites par SIREN, toutes sociétés confondues |
| `motif` | `MotifCode` : `verification`, `recuperation`, `liberation_siret`, `revocation`, `examen_siren` |
| `canal` | `CanalEnvoi` : `recommande_ar`, `lre_qualifiee` |
| `destinataire` | `json` : dénomination et adresse officielle avec sa source (Sirene ou RNE), ou dirigeant et adresse électronique (LRE) |
| `code_hache` | `string(255)` : `Hash::make(code)` (salé, sans clé à faire tourner) ; comparaison `Hash::check` ; jamais le code en clair |
| `reference_envoi`, `envoye_le`, `remis_le`, `retourne_le`, `expire_le` | |
| `tentatives` | `unsignedTinyInteger` ; au cinquième échec, `annule_le` |
| `utilise_le`, `annule_le`, `demande_par` | |
| timestamps | |

- `codes_confirmation_un_actif ON codes_confirmation (siren, motif) WHERE utilise_le IS NULL AND annule_le IS NULL` : un code actif par SIREN et par motif dans tout le logiciel ; au plus deux plis par SIREN et par 30 jours sans l'éditeur (contrôle de l'action) ; `sansSuppression`, `expire_le` de conservation comme `verifications_societe`.

#### `pieces_verification` — `2026_10_02_100014_create_pieces_verification_table.php` (voie B4)

- `id`, `societe_id`, `type` (`kbis`, `extrait_rne`, `identite`, `mandat`, `autre`), `chemin` (nullable : disque privé `verification`, contenu chiffré par `Crypt::encryptString`), `empreinte` (`char(64)`, SHA-256 du fichier déposé), `deposee_par`, `deposee_le`, `effacee_le`.
- Fichier effacé (et `chemin` remis à null) dès la décision de l'éditeur, au plus 30 jours après le dépôt (`app:effacer-pieces-verification`, quotidienne) ; la ligne reste (trace « pièce de type Y vérifiée le … »). Lecture par `editeur:societe:pieces {societe}` seulement (déchiffrement dans un dossier temporaire effacé à la fin de la commande, consultation journalisée). Une copie de pièce d'identité n'est jamais conservée au-delà de la décision.

#### `demandes_accord_dirigeant` — `2026_10_02_100015_create_demandes_accord_dirigeant_table.php` (B2)

- `id`, `societe_id`, `demande_par`, `email_dirigeant`, `representant_rne` (`json` : nom et rôle visés), `jeton_hache`, `expire_le` (7 jours), `approuvee_le`, `refusee_le`, `verification_identite_id`, timestamps. L'e-mail au dirigeant contient l'information prévue par l'art. 14 RGPD (qui traite ses données, pourquoi, combien de temps).

#### `actions_traitees` — `2026_10_14_100005_create_actions_traitees_table.php` [R3]

- `id`, `magasin_id` (`BelongsToMagasin`), `jeton` (`uuid`), `action` (`string(40)`), `user_id`, `empreinte_demande` (`char(64)` : SHA-256 des paramètres canoniques), `resultat_type`, `resultat_id`, `created_at` ; `unique(magasin_id, jeton)`. Effacée après 30 jours par `app:purger-actions-traitees` (filtre explicite par magasin, en lots) : table technique sans valeur fiscale.

#### `changements_fin_exercice` — `2026_10_02_100016_create_changements_fin_exercice_table.php` [S14]

- `id`, `societe_id`, `ancien_mois`, `nouveau_mois`, `fin_exercice_en_cours_avant` (`date`), `fin_exercice_en_cours_apres` (`date`, tirée du procès-verbal), `decision_du` (`date`), `piece_reference`, `duree_validee_par` (`string(150)` : expert-comptable qui a validé la durée), `justification`, `operateur` (`json`), `created_at`. `ajoutSeul`.

#### Tables existantes modifiées

- **`paniers_caisse`** [R3] (`2026_10_14_100003` [b2bce46], panier persistant de la caisse, `PanierDeCaisse`) : `jeton` (`uuid`, non nul, régénéré à chaque nouveau panier) : identifiant de la vente en cours, porté par l'encaissement.
- **`clotures_caisse_personnelles`** [R5] (`2026_10_09_100001`) : `mode_fermeture` (`ModeFermetureCaisse` : `vendeur`, `responsable`, `automatique`), `fermee_par` (nullable ; null pour l'automatique), `jusqu_au` (`timestamp` : borne des opérations comptées). Commentaire « Toujours un vendeur réel, jamais le système » (`:14-16`) réécrit.
- **`services`** [R2] (`2026_10_02_200005`) : `prix_ttc` (`decimal(12,2)` nullable), `taux_tva` (`decimal(5,2)` nullable), prix de référence ; le docblock (« sans prix : le vendeur saisit le TTC à chaque vente », `:11`) est réécrit.
- **`factures`** et **`avoirs`** [S11] : `avoirs.regularisation` (booléen), `factures.facture_rectifiee_id` (`foreignId` nullable → `factures`, même magasin) ; seulement après la réponse Q6.
- **`mouvements_stock`** : type `sortie_cession` (et `entree_annulation_cession`, 9.7).
- Supprimées avec Spatie : `2026_10_02_223524_create_permission_tables.php`, `config/permission.php`.
- `mandats_pdp` : inchangée jusqu'à l'étape 2 (remplacée par `connexions_pdp`).
- `notifications.data` porte `societe_id`, plus `magasin_id` pour une notification de magasin.

### 1.4 Ordre des migrations

`societes` (0001_01_00_000000) → `magasins` → `users` → `cache`, `jobs` → `demandes_inscription` (100000) → `fonction_sans_casse_ni_accents` (100001, existante) → `magasin_user` (100002, existante jusqu'au lot A4 puis supprimée) → `sirets_magasins` (100003) → `periodes_activite` (100004) → `affectations` (100005), `autorisations_affectations` (100006), `autorisations_societe` (100007) (lot A4) → `journal_audit` (100008) → `tetes_journal_audit` (100009) → `traces_audit` (100010) → `verifications_societe` (100011) → `demandes_rattachement` (100012) → `codes_confirmation` (100013) → `pieces_verification` (100014) → `demandes_accord_dirigeant` (100015) → `changements_fin_exercice` (100016) → `sequences_documents` (existante, renumérotée 100017) → `mandats_pdp` (existante, 100018) → le reste ; `traces_evenements` (2026_10_14_100004) après `enregistrements_fiscaux` ; `actions_traitees` (2026_10_14_100005). Renuméroter des migrations est permis (règle « Développement » : `migrate:fresh`).

### 1.5 Énumérations, modèles, services

**Énumérations**
- `RoleSociete` : `Proprietaire`, `Administrateur`, `Membre`.
- `StatutSociete` : `Active`, `Suspendue`, `Resiliee`, `Revoquee` ; `estEnLectureSeule()`.
- `StatutCompte` : `Actif`, `Suspendu`, `Retire`.
- `StatutMagasin` : `Actif = 'actif'`, `Ferme = 'ferme'`.
- `RoleMagasin` (modèles de cases) : `Responsable`, `Comptable`, `Vendeur`, `Observateur` ; `Proprietaire` et `Administrateur` [b2bce46, `RoleMagasin.php:22-23`] disparaissent.
- `FormeJuridique` [S4, S5] : `Sarl`, `Eurl`, `Sas`, `Sasu`, `Sa`, `Sca`, `Se`, `Snc`, `Scs`, `EntrepreneurIndividuel` (micro-entrepreneur compris), `Autre`. `libelle()` ; `estUneSocieteCommerciale()` : vrai pour les dix sociétés, faux pour l'entrepreneur individuel, `societes.societe_commerciale` pour `Autre` ; `exigeUnCapital()` = `estUneSocieteCommerciale()` (toute société commerciale : R123-238 C. com., notes `scratchpad/societe/web/juridique/notes.md` §2, source web) ; `estEntrepreneurIndividuel()` ; `depuisCategorieInsee(string $code): self` par **familles de niveau II** et non par codes exacts (source web, nomenclature https://www.insee.fr/fr/information/2028129, à confirmer sur le fichier téléchargeable) : `1xxx` → `EntrepreneurIndividuel` ; `52xx` → `Snc` ; `5306`, `5307` → `Scs` ; `5308`, `5309` → `Sca` ; `5498` → `Eurl` ; autres `54xx` (SARL, SELARL, SARL coopératives) → `Sarl` ; `55xx`, `56xx` (SA, SELAFA, SA coopératives) → `Sa` ; `5720` → `Sasu` ; autres `57xx` (SAS, SELAS) → `Sas` ; `5800` → `Se` ; tout autre code → `Autre` (la question « société commerciale ? » est alors posée). Une variante (SELARL, SA coopérative) garde son libellé exact dans `forme_juridique_autre` si le propriétaire le souhaite, la famille fixant l'exigence du capital.
- `CategorieAudit`, `MotifFermeture`, `ModeFermetureCaisse`, `TypeVerification`, `ResultatVerification`, `SignalDeRisque` (7.4), `MotifCode`, `CanalEnvoi`.
- `CodeEvenement` [b2bce46, `CodeEvenement.php:47-60`] : ajout de `EntreeEnModeDemonstration = 100` et `SortieDuModeDemonstration = 105` (lot A3, section 5.8).
- `ActionAuditee` : `SocieteInscrite`, `IdentiteModifiee`, `ParametresFiscauxModifies`, `SirenCorrigeAvantValidation`, `VerificationEnregistree`, `PieceDeposee`, `PieceEffacee`, `CodeEnvoye`, `CodeSaisi`, `SocieteValidee`, `SocieteRefusee`, `ReclamationOuverte`, `MagasinCree`, `MagasinModifie`, `SiretChange`, `MagasinFerme`, `MagasinRouvert`, `MagasinSuspendu`, `EtablissementPrincipalChoisi`, `DemonstrationOuverte`, `DemonstrationFermee`, `MembreCree`, `AdministrateurCree`, `MembreModifie`, `MotDePasseProvisoireDonne`, `LienDeReinitialisationEnvoye`, `AdministrateurModifie`, `CompteSuspendu`, `CompteRetire`, `CompteReintegre`, `RattachementPropose`, `RattachementAccepte`, `SocieteCreeeParUnCompteRetire`, `AdresseDeConnexionChangee`, `ProprieteTransferee`, `VenteSansStock` [R1], `SocieteRevoquee`, `SiretLibere`, `SirenExamine`, `SirenCorrige`, `CessionAnnulee`, `FactureRegularisee`, `FinExerciceChangee` ; `champsAudites()`, `categorie(): CategorieAudit`. L'étape 4 ajoute les autres actions importantes ; l'étape 2, `FactureRecueAttribuee` et `FactureRecueReattribuee`.

**Modèles créés** : `Societe` (`magasins()`, `magasinsReels()`, `demonstration()`, `membres()`, `proprietaire()`, `estValidee()`, `estEnLectureSeule()`, `aVenduUneFois()`, `aEmisUnDocumentFiscal()`, `estEnFranchiseDeTva()`, `mentionsLegalesManquantes()`, `siegeImprime()`, `parametresDeConformite()` = `['regime_tva', 'option_debits_tva', 'mois_fin_exercice', 'siren', 'numero_tva']`), `Affectation`, `SiretMagasin`, `PeriodeActivite`, `EntreeAudit`, `TeteJournalAudit`, `TraceAudit`, `TraceEvenement`, `VerificationSociete`, `CodeConfirmation`, `PieceVerification`, `DemandeAccordDirigeant`, `DemandeInscription`, `DemandeRattachement`, `ActionTraitee`, `ChangementFinExercice`. Les modèles propres à une société utilisent `BelongsToSociete`.

**Modèles modifiés**
- `Magasin` : `societe()` ; `estEnLectureSeule()` (fermé, suspendu, ou société en lecture seule ou refusée, relue en base) ; `peutVendre()`, `verifierQuIlPeutVendre()` (7.8) ; `verifierQuIlEstOuvert()` [`Magasin.php:290-297`] s'appuie sur `estEnLectureSeule()` ; `territoireTva()`, `tauxTvaParDefaut()` restent ; `estEnFranchiseDeTva()` [`:327`] et `parametresDeConformite()` [`:406`] passent à la société ; `mentionsLegalesManquantes()` [`:344`] combine société et établissement ; `periodes()`, `periodeOuverte()`. Retirés : hooks `saving` et `siren` [`:196-204`], `oublierLaDesignationDeLEditeur()` [`:257-261`], `utilisateurs()`, `membresActifs()`, `proprietaires()` [`:500-520`], `membres()` [`:555-558`], `SoftDeletes` [`:74`] ; docblocks [`:36-70`], [`:142-148`] réécrits.
- `User` : sans `HasRoles` ni `SoftDeletes` [`User.php:31`] ; `societe()`, `affectations()`, `estProprietaire()`, `estAdministrateur()`, `estActif()`, `doitChangerSonMotDePasse()`, `aLAutorisationDeSociete()`, `autorisationsDans(Magasin)`, `droitsDans(Magasin)`, `magasinsAccessibles()`, `peutOuvrir(Magasin)`, `peutAdministrer()`, `porteeDAdministration()`, `aSaQualiteVerifiee()`, `effacerToutesSesNotifications()`. Retirés : `magasins()` [`:76-82`], `appartientA()` [`:84-90`], `estProprietaireDe()` [`:98-101`], `autorisationsCocheesDans()` [`:111-117`], `effacerLesNotificationsDu()` [`:125-128`].

**Services, règles, intégrations créés** : `AutorisationsSociete`, `LecteurDesDroits` ; `JournalAudit`, `SiretsDesMagasins`, `PeriodesDActivite` ; règles `SirenValide` (9 chiffres, Luhn, refus de `000000000`), `SiretValide` (14 chiffres, Luhn, exception La Poste `356000000…` hors `35600000000048` : somme des chiffres multiple de 5 ; préfixe = SIREN de la société), `NicValide` (sources : python-stdnum `siret.py:60-75`, `siren.py:59-69` ; INSEE définitions c2047, c1981, c1841, source web) — elles ne s'appliquent pas aux fiches clients et fournisseurs ; `EvaluateurDeVerification`, `RapprochementDesDirigeants`, `SignauxDeRisque`, `CodesDeConfirmation` ; intégrations `Sirene`, `Rne`, `Identite`, `EnvoiRecommande` (7.9) ; `ActionsUniques` [R3], `VerificateurDeStock` [R1], `DerogationsDePrix` [R2] ; `RegistreDesOperateurs` (9.1).

---

## 2. Cloisonnement

### 2.1 Contexte : deux périmètres, un invariant

`TenantContext` [b2bce46, `TenantContext.php:19-107`] :
- champs `?Magasin $magasin`, `?Societe $societe` ;
- `set(Magasin $m)` : si une société est posée et diffère de celle de `$m` → `LogicException` ; sinon pose le magasin et sa société ;
- `poserSociete(Societe)` : refuse si un magasin d'une autre société est posé ;
- `societe()` lève `TenantContextMissingException` sans société ;
- `runAs(Magasin, Closure)` et `runAsSociete(Societe, Closure)` restaurent séparément magasin et société (la restauration par `clear()` [`:100-104`] efface tout aujourd'hui) ;
- `invaliderPermissionsUtilisateurCourant()` [`:52-64`] disparaît avec Spatie (lot A4).

`BindTenantContextFromFilamentTenant` traite les deux tenants. `MagasinScope` ne change pas : dans le panneau société, toute lecture d'un modèle de magasin échoue fermée, sauf sous `runAs`.

**`BelongsToSociete` + `SocieteScope`** (sur le modèle de `BelongsToMagasin`) : scope fermé par défaut ; `societe_id` forcé depuis le contexte, jamais lu dans la requête ; `regleExisteDansLaSociete()`. `Magasin` et `User` n'ont pas de scope global : dans l'espace société, toute requête passe par `$societe->magasins()` / `$societe->membres()` ou par le cloisonnement Filament.

**Jobs** : `WithSocieteContext` (`societeId` sérialisé à la mise en file, `runAsSociete` à l'exécution), à côté de `WithTenantContext`.

**Routes hors panneau** (inscription, rattachement, création de société par un compte retiré, rappels des prestataires, accord d'un dirigeant) : recherche explicite par empreinte de jeton ou référence signée, puis `runAsSociete` ; chaque contournement documenté et testé (règle 2).

### 2.2 Ce qui reste strictement isolé par magasin

Tous les modèles `BelongsToMagasin`, liste tenue par `test_chaque_modele_de_magasin_est_liste_ici` (`ActionTraitee` s'y ajoute). J5 et J6 inchangées. La démonstration est un magasin comme un autre : rien n'en sort, rien n'y entre.

### 2.3 Lectures hors du magasin ou de la société courants (liste exhaustive)

Chacune filtrée explicitement, limitée aux champs nécessaires et testée :
1. Liste des magasins et des membres dans l'espace société, **limitée à la portée de l'auteur** (relations de `Societe`, cloisonnement Filament).
2. Tableau de bord de la société : statut, vérification et compteurs des magasins **de la portée de l'auteur**.
3. `Societe::aVenduUneFois()` et `aEmisUnDocumentFiscal()` : `EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->whereIn('magasin_id', $this->magasinsReels()->select('id'))->…->exists()`.
4. Destinataires des notifications de société et des alertes de magasin (affectations de la société, 3.9) et `User::peutOuvrir()`.
5. Évaluation de la vérification : existence d'une autre société **du même SIREN** validée, ou ayant une preuve de qualité concluante (booléens seulement, jamais leur identité) ; codes de magasins des sociétés révoquées **du même SIREN** (pour ne jamais redonner un numéro déjà émis sous ce SIREN, 5.6).
6. Examen exceptionnel d'un SIREN par l'éditeur (9.4) : documents et état des paiements de tous les magasins de la société examinée, par commande de l'éditeur, journalisé.
7. Étape 2 [S2] : SIRET (actuels et anciens), adresses de réception, codes de routage, adresses et références d'acheteur des magasins de la société, pour attribuer les factures reçues.
8. Écritures (pas des lectures) : JET 270 et JET 130 dans chaque magasin concerné, sous `runAs`.

Les exceptions « filtrées par SIREN » de `CLAUDE.md:155` disparaissent. Aucune copie de données entre sociétés [S10].

### 2.4 Règles d'unicité et d'existence

| Donnée | Périmètre | Comment |
|---|---|---|
| SIREN | sociétés validées non révoquées | index unique partiel ; jamais révélé par un écran ni un e-mail avant une preuve de qualité (3.1, 7.7) |
| SIRET | réservé dans tout le logiciel à la validation | `sirets_magasins` ; `unique(societe_id, siret)` ; message « Ce SIRET ne peut pas être utilisé. » |
| E-mail | tout le logiciel (A1) | `users.email` unique ; messages neutres (3.1, 3.4) |
| Code de magasin | société, plus sociétés révoquées du même SIREN | `CodeMagasin::estPris(Societe, string)` et `proposer(Societe, string)` ; index `unique(societe_id, code)` |
| Magasin choisi | société et portée de l'auteur | `Rule::exists('magasins','id')->where('societe_id', …)` puis portée (4.3) |
| Compte choisi | société et portée | `Rule::exists('users','id')->where('societe_id', …)` |
| Affectation hors société | base | clés composites |
| Données de magasin | magasin | inchangé (`regleExisteDansLeMagasin()`, rappel global des `Select` [b2bce46, `AppServiceProvider.php:212`]) |

**Options de formulaire du panneau société** (Filament ne cloisonne pas `Select`, `CheckboxList`, `SelectFilter` : `vendor/filament/filament/docs/11-tenancy.md:768`) : les rappels `configureUsing` sont statiques et globaux ; chacun commence donc par `if (Filament::getCurrentPanel()?->getId() !== 'societe') { return; }`, puis lève une `LogicException` au rendu si sa relation ou ses options portent sur `Magasin`, `User` ou un modèle `BelongsToSociete` sans requête limitée à la société et à la portée de l'auteur. Test `test_les_options_du_panneau_magasin_ne_sont_pas_touchees_par_les_gardes_du_panneau_societe`.

### 2.5 Tests d'isolation

`tests/Feature/Tenancy/TenantIsolationTest.php` (adapté) et `tests/Feature/Tenancy/IsolationDesSocietesTest.php` (créé).

Deux sociétés : `test_chaque_modele_de_societe_est_liste_ici` ; `test_societe_scope_echoue_ferme_sans_contexte` ; `test_une_societe_ne_lit_ni_le_journal_d_audit_ni_les_verifications_ni_les_demandes_d_une_autre` ; `test_l_espace_d_une_autre_societe_repond_404` ; `test_le_proprietaire_d_une_societe_n_ouvre_aucun_magasin_d_une_autre` ; `test_la_base_refuse_d_affecter_un_compte_au_magasin_d_une_autre_societe` ; `test_un_identifiant_de_magasin_d_une_autre_societe_force_dans_un_formulaire_est_refuse` ; `test_les_autorisations_de_societe_ne_valent_jamais_hors_de_sa_societe` ; `test_run_as_d_un_magasin_d_une_autre_societe_est_refuse_dans_un_contexte_de_societe` ; `test_run_as_restaure_le_magasin_et_la_societe_poses_avant` ; `test_la_lecture_de_la_premiere_vente_ne_compte_que_les_magasins_reels_de_la_societe` ; `test_aucun_reglage_d_une_autre_societe_n_est_lu_ni_revele` ; `test_deux_inscriptions_en_attente_du_meme_siren_ne_voient_rien_l_une_de_l_autre` ; `test_l_evaluation_ne_lit_d_une_autre_societe_du_meme_siren_que_des_booleens`.

Deux magasins d'une société : tests existants d'avoir et d'encaissement réécrits avec `ouvrirUnAutreMagasin()` (au lieu de `MagasinFactory::deLaSocieteDe`, 15 usages dans 5 fichiers) ; `test_les_clients_produits_documents_et_stock_d_un_magasin_restent_invisibles_dans_l_autre` ; `test_rien_ne_passe_du_magasin_de_demonstration_aux_magasins_reels`.

Portée : `test_un_vendeur_affecte_au_seul_magasin_a_ne_voit_que_lui_et_l_url_de_b_repond_404` ; `test_les_autorisations_cochees_dans_a_n_existent_pas_dans_b` ; `test_lire_les_droits_d_un_membre_dans_a_puis_dans_b_ne_donne_jamais_ceux_de_a` ; `test_un_administrateur_d_une_selection_n_ouvre_aucun_magasin_hors_de_sa_selection` ; `test_le_tableau_de_bord_d_un_administrateur_d_une_selection_ne_montre_que_ses_magasins`.

---

## 3. Parcours et écrans

### 3.1 Inscription en deux temps

Panneau `app`, `/app/register` : `app/Filament/App/Pages/Auth/InscriptionSociete.php` remplace `RegisterMagasin.php`.

**Étape 1 — demande**
- SIREN (`SirenValide`) ; premier magasin : nom, code proposé depuis le nom (repris), NIC (`NicValide` ; SIRET = SIREN + NIC passe `SiretValide`) ; propriétaire : nom, e-mail (macro `adresseDeConnexion()` [`AppServiceProvider.php:276`]), mot de passe.
- Contrôle Sirene (répertoire public) : unité active, SIRET actif et rattaché ; cessée, fermé ou inconnu → refus motivé. INSEE injoignable → demande acceptée, contrôle repris après confirmation.
- Aucune existence dans PILIQI n'est vérifiée. Réponse toujours : « Si ces informations permettent l'inscription, un e-mail vient d'être envoyé à cette adresse. Le lien est valable 24 heures. »
- Adresse déjà utilisée : aucune demande ; e-mail « Vous avez déjà un compte PILIQI » (lien de connexion, « Mot de passe oublié » ; si le compte est retiré : « Créer votre propre société avec ce compte », 3.5).
- Sinon : `DemandeInscription` (jeton de 64 caractères, haché), `DemandeInscriptionMail`, lien construit depuis `APP_URL`.
- Limites : `rateLimit(2)` de Filament (par minute et par IP) ; 20 par heure et par IP ; 3 par heure et par couple (SIREN, adresse) ; au-delà, même message neutre. **Aucune limite par SIREN seul** : un tiers ne peut pas saturer le SIREN d'une entreprise. Aucun champ validé en `live()`.

**Étape 2 — confirmation** (`GET /inscription/{jeton}` affiche un bouton sans rien consommer ; `POST` consomme)
- `Referrer-Policy: no-referrer` ; jeton jamais journalisé ; consommation atomique (`update … where jeton_hache = ? and consommee_le is null and expire_le > now`) ; zéro ligne → « Ce lien n'est plus valable. »
- **Même parcours dans tous les cas** : que le SIREN soit libre, en attente ailleurs ou déjà validé par une autre société, la société est créée « à vérifier » et rien n'est révélé. Le cas « déjà validé » n'apparaît qu'après une preuve de qualité concluante (7.7, 9.3).
- `CreerSocieteAction` en une transaction. Succès : propriétaire connecté, `email_verified_at` et `premiere_connexion_le` posés, redirection vers l'espace société, page « Vérification de la société ».

**`CreerSocieteAction::handle(array $societe, array $premierMagasin, User|array $proprietaire): Societe`** : crée la société, préremplie depuis Sirene (raison sociale, forme par `depuisCategorieInsee()`, siège, n° de TVA proposé) ; crée le compte propriétaire (`role_societe = proprietaire`) ou, pour un compte retiré (3.5), le rattache ; appelle `CreerEtablissementAction` ; inscrit les contrôles Sirene au registre des vérifications ; audit `SocieteInscrite`. Aide de test `ouvrirUneSociete()` : renvoie le premier magasin.

Interdit : `registration()` et `tenantRegistration()` dans le panneau société (test).

### 3.2 Espace d'administration de la société

**Panneau `societe`** : `SocietePanelProvider`, `/societe`, `->tenant(Societe::class, slugAttribute: 'uuid')`, `->tenantMenu(false)`, `->login()`, `->profile(ModifierProfil::class)`, `->databaseNotifications()`, `AuthenticateSession`. Lot A2 : propriétaire seul ; lot A4 : ouvert aux administrateurs actifs.

**Mode d'autorisation strict** : `vendor/filament/filament/src/helpers.php:16-41` autorise quand la policy manque, et `Resource::$shouldCheckPolicyExistence` est une propriété statique déclarée sur la seule classe de base (`Resource.php:116`, modifiée par `:230`) : appeler `checkPolicyExistence(false)` depuis une ressource changerait toutes les ressources. On crée donc `App\Filament\Societe\RessourceDuPanneauSociete` (abstraite) qui **redéclare** `protected static bool $shouldCheckPolicyExistence = false;` ; toutes les ressources du panneau société en héritent. `Gate::policy` explicite pour `Magasin` (`MagasinDeLaSocietePolicy`), `User` (`MembrePolicy`), `EntreeAudit` (`EntreeAuditPolicy`), `Societe` (`SocietePolicy`). Méta-tests `test_chaque_ressource_et_page_du_panneau_societe_a_une_policy_complete` et `test_les_ressources_du_panneau_magasin_gardent_le_controle_d_existence_des_policies`.

| Écran | Accès | Contenu |
|---|---|---|
| Tableau de bord | propriétaire, administrateurs | magasins de la portée et statut ; vérification ; identité incomplète ; démonstration ; (étape 2) factures à attribuer |
| `Pages/VerificationSociete` | propriétaire (gestes), administrateurs (lecture) | 7.6 |
| `Pages/IdentiteSociete` | `societe.parametres.gerer` | raison sociale, forme (liste), « société commerciale ? » pour Autre, capital (exigé selon la forme), capital variable, immatriculation, n° de TVA, siège ou adresse de l'entreprise ; SIREN en lecture seule, « Corriger le SIREN » pour le propriétaire tant que le verrou (Q3) ne s'applique pas ; écart avec Sirene signalé ; encadré des mentions manquantes |
| `Pages/ParametresFiscauxSociete` | `societe.parametres.gerer` | régime, option pour les débits, mois de fin d'exercice (désactivé dès la première vente) ; enregistrement sous les verrous de tous les registres, JET 270 dans chaque magasin (5.2) |
| `Resources/MagasinResource` | `societe.magasins.gerer` dans la portée | 3.3 |
| `Resources/MembreResource` | `societe.membres.gerer` dans la portée ; administrateurs : propriétaire seul | 3.4, 3.5 |
| `Resources/JournalAuditResource` | propriétaire seul [V1-7] | lecture seule ; filtres ; avant et après ; IP et navigateur tant que la trace existe ; vérification des chaînes |

**Quittent le panneau magasin** : `IdentiteMagasin`, `ParametresFiscaux` (lot A2), `MembreEquipeResource` et ses 3 pages (lot A4). **Arrivent dans le panneau magasin** : `Pages/CoordonneesMagasin` (`PARAMETRES_GERER` : e-mail, téléphone, références d'acheteur ; nom, adresse et SIRET en lecture seule) ; `Pages/Auth/ChangerMotDePasseProvisoire`.

### 3.3 Créer, modifier, fermer, rouvrir, céder un magasin

**`CreerEtablissementAction::handle(User $auteur, Societe $societe, array $donnees): Magasin`**
- Contrôles : `societe.magasins.gerer` avec accès à tous les magasins (propriétaire toujours) ; société non en lecture seule ; société de l'auteur et société posée ; NIC → SIRET valide, libre dans la société et non réservé ; territoire pris en charge ; code libre (`CodeMagasin::estPris`).
- **Contrôle Sirene synchrone, avant toute écriture** [S6] : établissement fermé, inconnu ou rattaché à un autre SIREN → refus, rien n'est créé. INSEE injoignable → création « établissement à vérifier » (`etablissement_verifie_le` nul), reprise horaire et bouton « Vérifier maintenant ». Concluant → `etablissement_verifie_le` posé, adresse reprise de Sirene.
- Transaction : `Magasin::forceCreate([... 'societe_id' => $societe->id])` ; sous `runAs` : client comptoir (repris de `CreerMagasinAction`) ; audit `MagasinCree` ; si la société est validée : `SiretsDesMagasins::reserver()`, période d'activité ouverte, et **un JET 130 par personne qui y a des droits implicites** (propriétaire, administrateurs de tous les magasins ; « avant » nul, « après » relu en base).
- Aucune affectation automatique.

**`ModifierEtablissementAction`** : nom ; adresse sans changement de territoire ; établissement principal ; code tant que `aNumeroteUnDocument()` est faux ; NIC tant que la société n'est pas validée (nouveau contrôle Sirene synchrone).

**`ChangerSiretAction`** [V1-11] (`societe.magasins.gerer`, mot de passe redemandé) : nouveau NIC ; Sirene obligatoire (actif et rattaché au même SIREN ; injoignable → refus) ; date d'effet le jour même ; refus si le territoire de TVA change ; sous le verrou du registre : `SiretsDesMagasins::remplacer()`, adresse reprise de Sirene, JET 270 `siret`, audit `SiretChange`, (étape 2) nouvelle adresse de réception. Le magasin garde tout ; l'ancien SIRET reste réservé et lisible sur les documents émis.

**`FermerMagasinAction::handle(User $auteur, Magasin $magasin, MotifFermeture $motif, string $motDePasse, ?string $confirmation = null): void`** [S8, S9, S10]
- Contrôles : `societe.magasins.gerer` dans la portée ; mot de passe redemandé ; magasin réel `actif` (suspendu ou non : une entreprise qui cesse doit pouvoir fermer un magasin suspendu, la suspension reste) ; motif `temporaire`, `cessation` ou `cession` ; **`cession` réservée au propriétaire**, avec le nom du magasin retapé (`$confirmation`).
- Transaction, registre verrouillé en tête : `CloturerLesJourneesAction` jusqu'au jour même ; statut `ferme` ; période close ; JET 270 `statut_magasin` ; audit `MagasinFerme`. Motif `cession` [S10] : chaque produit suivi en stock positif sort au coût moyen (`SortieCession`), valeur totale journalisée et notifiée au propriétaire : « Valeur du stock au coût moyen : X € HT. À rapprocher du prix de cession prévu à l'acte et à faire vérifier par votre expert-comptable (transmission d'universalité, art. 257 bis CGI, ou vente du stock à facturer) » ; un produit en stock négatif est listé, sans mouvement.
- Après : caisses personnelles fermées d'office [R5] ; événement `MagasinFerme` ; (étape 2) fin de l'adresse de réception ; (étape 3) clôtures finales selon la réponse Q7.
- Fenêtre : lecture seule, plus aucun ticket Z tant qu'il est fermé, réouverture possible sauf après une cession.

**`RouvrirMagasinAction::handle(User $auteur, Magasin $magasin, string $motDePasse): void`** [S8]
- Contrôles : même autorité ; magasin `ferme` pour `temporaire` ou `cessation` ; société validée et active. La suspension de l'éditeur (`suspendu_le`) n'est jamais levée par une réouverture : un magasin suspendu rouvert reste en lecture seule.
- Contrôle Sirene synchrone : SIRET fermé → refus ; injoignable → réouverture « établissement à vérifier » ; concluant → vendable.
- Transaction : statut `actif` ; nouvelle période ; JET 270 ; audit `MagasinRouvert` ; événement ; (étape 2) adresse recréée. Reprise de la caisse : 5.7.

### 3.4 Membres et administrateurs [S1, V1-2]

**`CreerMembreAction::handle(User $auteur, Societe $societe, array $identite, string $motDePasseProvisoire, array $affectations): User`**
- Contrôles : `societe.membres.gerer` ; magasins dans la portée ; chaque autorisation connue et incluse dans celles de l'auteur dans ce magasin ; adresse libre (`EmailLibrePourUnNouveauCompte`, « Cette adresse ne peut pas servir à un nouveau compte. ») ; mot de passe provisoire d'au moins 12 caractères ; au plus 20 comptes créés par jour et par société (anti-abus).
- Transaction : registres des magasins concernés verrouillés par id ; compte (`membre`, `actif`, `mot_de_passe_a_changer`, expiration à 72 h) ; affectations et autorisations ; JET 130 dans chaque magasin ; audit `MembreCree` (jamais le mot de passe).
- Écran : nom, e-mail, mot de passe provisoire (« Générer » : 16 caractères, affichés une seule fois), modèle, magasins (limités à la portée), cases par magasin (limitées aux siennes). Remis de la main à la main, jamais par e-mail (CNIL 2022-100 : pas de mot de passe en clair par e-mail, source web).

**`CreerAdministrateurAction`** (propriétaire seul) [V1-2] : un seul formulaire, sans portée présélectionnée : identité, mot de passe provisoire, portée (« tous les magasins, présents et futurs » ou « sélection »), autorisations de société, autorisations de magasin (une liste pour la sélection, modifiable par magasin) ; un seul JET 130 par magasin touché (tous les magasins réels pour une portée globale). `NommerAdministrateurAction` (promouvoir un membre existant) et `ModifierAdministrateurAction` suivent les mêmes règles.

**Première connexion** : middleware `ExigerUnMotDePasseDefinitif` sur les deux panneaux (toute page et toute requête Livewire mènent à `ChangerMotDePasseProvisoire`, sauf la déconnexion) ; nouveau mot de passe différent ; enregistré, il efface le drapeau et l'expiration et renouvelle `remember_token` ; `premiere_connexion_le` posé. `ControleDesDroits` refuse tout geste à un compte qui doit changer son mot de passe. Provisoire expiré : « Votre mot de passe provisoire a expiré : demandez-en un nouveau à la personne qui a créé votre compte » (après des identifiants exacts seulement). Un « Mot de passe oublié » réussi (événement `PasswordReset`) efface aussi le drapeau et l'expiration.

**Renouveler l'accès d'un membre** :
- compte **jamais connecté** : `DonnerUnMotDePasseProvisoireAction` (nouveau provisoire, 72 h) ;
- compte **déjà connecté** : `EnvoyerUnLienDeReinitialisationAction` : lien de réinitialisation du courtier de mots de passe de Laravel envoyé à l'adresse du membre (« à la demande de {auteur} »), délai `auth.passwords.users.expire` (60 minutes) ; l'auteur ne connaît jamais le mot de passe : personne ne peut se connecter et vendre au nom d'un vendeur ;
- dans les deux cas : seulement pour un membre que l'auteur domine (4.8) ; jamais pour soi-même, le propriétaire ou un administrateur (un administrateur, par le propriétaire seul) ; notification au membre ; audit.

**Suspendre, retirer, réintégrer** (`ChangerStatutCompteAction`) : registres de **tous** les magasins du compte verrouillés (tous les magasins réels pour un administrateur global) et JET 130 dans chacun ; suspendre coupe tout accès dès la requête suivante (`canAccessPanel`, `canAccessTenant`, `ControleDesDroits` relisent le statut) et renouvelle `remember_token` ; retirer supprime en plus affectations, autorisations de société et efface les notifications ; **retirer un administrateur le repasse `membre`** dans la même transaction ; réintégrer obéit à la règle d'inclusion ; un compte suspendu ou retiré par le propriétaire ne se réintègre que par lui. Un compte retiré garde son adresse (A1).

**Adresse de connexion changée dans le profil** (H1 complétée) : `ModifierProfil` exige toujours le mot de passe actuel ; la nouvelle adresse ne prend effet qu'après un clic sur un lien envoyé **à cette nouvelle adresse** (24 h) ; l'ancienne adresse est prévenue. Une personne ne peut donc plus bloquer l'adresse d'une autre en la tapant dans son profil.

**Transférer la propriété** (`TransfererProprieteAction`, propriétaire seul, mot de passe redemandé) [V1-8] : vers un administrateur actif **dont la qualité de représentant est prouvée à son nom** (`qualite_verifiee_le`, sinon refus : « Cette personne doit d'abord prouver sa qualité de représentant ») ; l'ancien propriétaire devient administrateur de tous les magasins avec toutes les autorisations, puis le nouveau devient propriétaire (cet ordre, pour l'index partiel) ; JET 130 dans chaque magasin ; audit ; notification aux deux.

### 3.5 Compte retiré : rejoindre une autre société, ou créer la sienne [S1 note, V1-9]

- **Proposer un rattachement** (`ProposerUnRattachementAction`, `societe.membres.gerer`) : réponse toujours « Si cette adresse correspond à un compte qui peut rejoindre votre société, la personne a reçu une proposition par e-mail ». Compte retiré : demande (jeton, 7 jours) et e-mail. Compte encore actif ou suspendu ailleurs : aucune demande ; e-mail à la personne disant qu'elle doit d'abord être retirée par sa société actuelle (lecture de la note S1, voir Q2). Compte de la même société : réintégration. Rien n'est révélé à l'auteur.
- **Accepter** (`/rattachement/{jeton}`, connexion par son propre mot de passe, GET sans effet, POST atomique) : compte relu (toujours retiré, `membre`), auteur revérifié (actif, autorisé, inclusion), magasins revérifiés ; `societe_id` changé, `statut = actif` ; affectations ; notifications effacées ; `remember_token` renouvelé ; autres demandes annulées ; JET 130 dans chaque magasin ; audit dans les deux sociétés.
- **Créer sa propre société** (`/societe/creer`, authentifié, compte `retire` seulement) : même formulaire que l'inscription (SIREN, premier magasin) sans e-mail ni mot de passe ; `CreerSocieteAction` avec le compte existant (`societe_id` changé, `role_societe = proprietaire`, `statut = actif`, `qualite_verifiee_le` remis à null) ; audit `SocieteCreeeParUnCompteRetire` dans les deux sociétés.

### 3.6 Choix du magasin et traçabilité de chaque vente [V1-1]

| Méthode de `User` | Panneau `app` | Panneau `societe` |
|---|---|---|
| `getTenants` | `magasinsAccessibles()` triés par nom (démonstration à part) | `[$this->societe]` si `peutAdministrer()` |
| `canAccessTenant` | `peutOuvrir($m)` : même société, compte actif, mot de passe définitif, et propriétaire, affectation globale ou affectation à ce magasin | même société et `peutAdministrer()` |
| `canAccessPanel` | compte actif et au moins un magasin accessible | `peutAdministrer()` |

**Traçabilité stricte** :
- Le magasin d'une opération est celui de l'adresse du panneau (`/app/{uuid}`), posé à chaque requête, Livewire comprise (intergiciel persistant [`AppPanelProvider.php:64-67`]) ; jamais un « magasin courant » en session.
- Chaque document et paiement est `BelongsToMagasin` ; l'émetteur figé porte le SIRET du magasin ; l'en-tête signé porte le magasin (uuid, SIREN, SIRET) et l'opérateur (id, nom) [`RegistreFiscal.php:412-415`] ; `cree_par` désigne la personne.
- L'autorisation est relue **sous le verrou du registre** au moment de l'émission ou du paiement (4.9).
- La caisse affiche en permanence « Vous encaissez pour : {nom} — SIRET {siret} — {adresse} » et « Vendeur : {nom} ».
- Caisse personnelle et panier par magasin et par vendeur (`clotures_caisse_personnelles.magasin_id`, `paniers_caisse` `unique(magasin_id, user_id)`).
- **Selon Q2** : prise de poste exclusive (un vendeur n'encaisse dans B que si sa caisse personnelle de A est fermée), contrôlée sous verrou.
- Tests (`tests/Feature/Tenancy/TracabiliteDesVentesTest.php`) : `test_une_vente_enregistre_le_magasin_de_l_adresse_et_son_siret_jamais_un_autre_magasin_du_vendeur` ; `test_le_contenu_signe_porte_le_siret_du_magasin_et_l_operateur` ; `test_un_vendeur_de_deux_magasins_n_encaisse_dans_b_que_s_il_y_est_affecte_au_moment_de_la_vente` ; `test_retirer_le_vendeur_de_b_pendant_qu_un_onglet_est_ouvert_refuse_la_vente_suivante_dans_b` ; `test_deux_onglets_de_deux_magasins_n_echangent_jamais_leur_magasin` ; selon Q2 (a) : `test_un_vendeur_n_encaisse_dans_b_qu_apres_avoir_ferme_sa_caisse_de_a`.

### 3.7 Ce que voit un vendeur

Les magasins où il est affecté, avec ses cases dans chacun ; ses factures (sans `facture.view_toutes`), « Mes ventes », la caisse, « Fermer ma caisse ». Ni espace société, ni équipe, ni paramètres sauf case cochée. L'adresse d'un autre magasin ou d'une autre société répond 404.

### 3.8 Statuts et lecture seule

| Niveau | États | Qui | Effet |
|---|---|---|---|
| Société | `active`, `suspendue`, `resiliee` | l'éditeur (étapes 12-13) | tous ses magasins en lecture seule ; espace consultable, équipe gérable ; aucun magasin créé, aucun réglage modifié |
| Société | `revoquee` | procédure 9.3 | lecture seule totale : documents et espace consultables par ses comptes [S7 option a], aucune création, aucune gestion d'équipe, aucun réglage |
| Vérification | à vérifier, validée, refusée | section 7 | à vérifier : configuration et démonstration ; refusée : lecture seule définitive |
| Magasin | `actif`, `ferme` ; `suspendu_le` | fermeture : la société [S8] ; suspension : l'éditeur | lecture seule ; SIRET réservé |
| Établissement | vérifié, à vérifier | Sirene | à vérifier : aucune vente |

Tous les endroits qui lisent la lecture seule passent par `Magasin::estEnLectureSeule()` : `verifierQuIlEstOuvert()` [`Magasin.php:290-297`] donc `RegistreFiscal::ecrire()` [`RegistreFiscal.php:254-262`] ; `ControleDesDroits` [`:62-67`] ; `EnvoyerDocumentJob::handle()` [`:102-105`] ; `RelancerFacturesImpayees` [`:41`] (magasins actifs, non suspendus, qui peuvent vendre, d'une société active) ; le bandeau `magasin-en-lecture-seule.blade.php`, qui dit lequel est en cause. Critère de fin : `git grep -n -E "statut->estEnLectureSeule\(|StatutMagasin::Actif"` ne renvoie rien hors de `Magasin::estEnLectureSeule()`, des fabriques et des requêtes planifiées listées ; les 21 tests de `MagasinEnLectureSeuleTest` paramétrés aussi par `Suspendue`, `Resiliee`, `Revoquee`, `refusée` et `suspendu_le`.

### 3.9 Sessions, JET 80/40, notifications

- `MagasinOuvertParLaSession` devient `MagasinsOuvertsParLaSession` (lot A2, car le propriétaire ouvre plusieurs magasins dès ce lot) : JET 80 la première fois qu'une session ouvre un magasin ; JET 40 dans chaque magasin ouvert à la fin de session.
- **Destinataires** [V1-3] : échec d'un envoi : l'expéditeur s'il peut encore ouvrir le magasin, sinon le propriétaire ; factures en retard : le propriétaire ; **alerte de stock** (seuil et négatif [R1]) : le propriétaire et toute personne qui peut ouvrir le magasin et y a `produit.ajuster_stock` (affectation du magasin ou globale portant l'autorisation, `index(autorisation)`) ; notifications de société : propriétaire et administrateurs habilités dans leur portée ; factures à attribuer (étape 2) : propriétaire et administrateurs de tous les magasins ayant `societe.factures_recues.gerer`.
- Toute notification porte `societe_id` ; au retrait ou au rattachement, `effacerToutesSesNotifications()` ; à la suppression d'une affectation, effacement des notifications de ce magasin.

---

## 4. Permissions [S12, V1-2]

### 4.1 Principes

- **Propriétaire** : un par société ; tous les droits, sans case ni affectation ; jamais suspendu, retiré, rétrogradé ni rattaché ailleurs.
- **Administrateur** : portée et autorisations choisies par le propriétaire à la création [V1-2].
- **Membre** (responsable, vendeur, comptable, observateur) : affecté et coché magasin par magasin.
- Le rôle n'est qu'un modèle ; contrôle côté serveur dans chaque action, en plus des policies et de l'écran ; toute requête Livewire forgée est refusée (tests).
- Sans paquet ni cache global : droits lus en base (une requête par magasin, mémorisée sur l'instance).

### 4.2 Lecture des droits

- `User::autorisationsDans(Magasin $m): list<string>` : `[]` si autre société, compte non actif ou mot de passe à changer ; propriétaire → `PermissionRegistry::toutes()` ; affectation à `$m` → ses autorisations ; sinon affectation globale → ses autorisations ; sinon `[]`. Mémorisée par magasin ; toute écriture de droits appelle `oublierLesDroits()`.
- `User::aLAutorisationDeSociete(string)` : propriétaire vrai ; administrateur → `autorisations_societe` ; membre faux.
- `User::droitsDans(Magasin)` (JET 130 avant et après) relit toujours la base, statut compris (`JournalDesDroits` [b2bce46, `app/Domain/Tenancy/Services/JournalDesDroits.php`]).

### 4.3 Autorisations de société et portée

| Clé | Libellé | Portée exigée | Société en lecture seule |
|---|---|---|---|
| `societe.magasins.gerer` | Magasins : créer (tous les magasins seulement), modifier, changer le SIRET, fermer, rouvrir | dans la portée | refusée |
| `societe.membres.gerer` | Membres : créer, affecter, cocher (jamais au-delà des siennes), mot de passe provisoire ou lien, suspendre, retirer, réintégrer, rattachement ; jamais un administrateur ni le propriétaire | membres dont toutes les affectations sont dans la portée | permise (sauf société révoquée) |
| `societe.parametres.gerer` | Paramètres de la société : identité légale (hors SIREN), siège, TVA, mois de fin d'exercice | tous les magasins | refusée |
| `societe.factures_recues.gerer` (étape 2) | Factures reçues : attribuer, corriger, refuser, contester | tous les magasins | permise |

- Une autorisation de toute la société ne se coche que pour un administrateur de tous les magasins (case désactivée, action qui refuse ; test).
- Un administrateur d'une sélection ne voit que les membres affectés à au moins un de ses magasins, et seulement leurs affectations dans sa sélection ; il ne modifie qu'un membre dont toutes les affectations sont dans sa sélection, sinon il ne retire que l'affectation de ses magasins. **Un membre sans aucune affectation ne se gère que par le propriétaire ou un administrateur de tous les magasins** (pas de domination « à vide »).
- La cession d'un magasin reste au propriétaire (3.3).

### 4.4 Gestes réservés au propriétaire (`SocietePolicy`)

Créer, nommer, modifier, suspendre, retirer, réintégrer un administrateur ; corriger le SIREN tant qu'il n'est pas verrouillé ; prouver la qualité de représentant (identité, accord d'un dirigeant, dépôt de pièces, demande et saisie du code) ; consulter le journal d'audit et les traces ; transmettre la propriété ; céder un magasin ; ouvrir la démonstration (aussi un administrateur de tous les magasins ayant `societe.magasins.gerer`) ; (étape 2) connecter SUPER PDP ; (étape 3) signer le volet 2 des attestations, **à condition que sa qualité soit prouvée à son nom** (`aSaQualiteVerifiee()`).

### 4.5 Autorisations de magasin

`PermissionRegistry` (44 constantes [b2bce46, `PermissionRegistry.php:174-190`]) : `EQUIPE_VIEW`, `EQUIPE_CREER`, `EQUIPE_MODIFIER` disparaissent (lot A4) ; s'ajoutent (lot A6) `STOCK_VENDRE_SANS_STOCK = 'stock.vendre_sans_stock'` (« Stock : vendre au-delà du stock disponible, après avertissement ») et `VENTE_REMISES_ET_PRIX = 'vente.remises_et_prix'` (« Ventes : accorder des remises et fixer le prix d'un service ») : 43. `PARAMETRES_GERER` : « Paramètres du magasin : coordonnées, références d'acheteur, échéance et mentions des factures, barème, relances ». `permisesEnLectureSeule()` perd `EQUIPE_*`. Nouvelle liste `reserveesAuxOperationsCommerciales()` : `facture.emettre`, `facture.envoyer`, `devis.envoyer`, `avoir.creer`, `avoir.envoyer`, `paiement.creer`, `paiement.contre_passer` (refusées tant que le magasin ne peut pas vendre — jamais à cause d'une revérification Sirene après validation, 7.10).

### 4.6 Modèles des rôles

| Rôle | Modèle |
|---|---|
| Administrateur | Société : `magasins.gerer`, `membres.gerer` (cochées par défaut, décochables). Magasins : modèle Responsable |
| Responsable | toutes les autorisations de magasin sauf `parametres.gerer` et `stock.vendre_sans_stock` ; `vente.remises_et_prix` comprise [R2] |
| Comptable, Vendeur, Observateur | listes actuelles [`RoleMagasin.php:87-129`], sans `vente.remises_et_prix` ni `stock.vendre_sans_stock` |

`stock.vendre_sans_stock` n'est précochée pour personne (R1 : le refus est la règle).

### 4.7 `ControleDesDroits` (seul `Gate::before` [`TenancyServiceProvider.php:33`])

```text
__invoke(User $u, string $ability, array $args): ?bool
  si ! $u->estActif() ou $u->doitChangerSonMotDePasse()                → false
  1. ability ∈ AutorisationsSociete::toutes() :
       société = contexte->societe() si posée, sinon $u->societe
       si société.id ≠ $u->societe_id                                   → false
       si société en lecture seule et ability ∉ permisesEnLectureSeule  → false
       retourner $u->aLAutorisationDeSociete($ability) ?: null
  2. société posée sans magasin (panneau société) :
       si société en lecture seule et geste ni consultatif ni sur l'équipe → false
       (révoquée : seulement les gestes consultatifs)
       → null (policies, mode strict)
  3. magasin actif :
       si magasin.societe_id ≠ $u->societe_id                           → false
       si magasin->estEnLectureSeule() et geste non permis              → false
       si ! magasin->peutVendre() et ability ∈ reserveesAuxOperationsCommerciales → false
       si ability ∈ PermissionRegistry::toutes()
          → in_array($ability, $u->autorisationsDans($magasin), true) ?: null
  4. sinon → null
```

Appels directs à corriger : `ControleDesDroits.php:59`, `ClotureCaissePersonnellePolicy.php:74-75` (`checkPermissionTo`). L'exception « porte sur MagasinUser » [`:73`] disparaît.

### 4.8 Garde-fous contre l'escalade (tous testés)

1. Personne ne modifie ses propres droits (rôle, statut, affectations, cases, portée).
2. Un administrateur ne touche jamais au propriétaire ni à un autre administrateur et ne nomme personne administrateur.
3. Ce qu'un administrateur coche pour un membre dans un magasin reste inclus dans ses propres autorisations dans ce magasin (`verifierConnues` puis inclusion) ; les cases qui dépassent ne sont pas proposées.
4. Il ne modifie un membre que si toutes les autorisations de ce membre, dans chaque magasin, sont incluses dans les siennes et dans sa portée ; un membre sans affectation reste au propriétaire et aux administrateurs globaux ; il peut toujours couper un accès dans sa portée.
5. Réintégrer obéit à la même règle ; un compte suspendu ou retiré par le propriétaire ne se réintègre que par lui.
6. Le nom et l'adresse d'un autre compte ne se modifient jamais (H1) ; aucun champ dans `MembreResource` ; requête forgée testée.
7. Mot de passe provisoire (compte jamais connecté) ou lien (compte déjà connecté) seulement pour un membre que l'on domine ; jamais pour soi, le propriétaire ni un administrateur (sauf le propriétaire pour un administrateur).
8. Nommer administrateur supprime les affectations de membre ; rétrograder exige les nouvelles affectations dans la même action ; retirer un administrateur le repasse membre.
9. Une demande de rattachement acceptée revérifie son auteur et les magasins.
10. Chaque modification de droits (création, cases, affectations, portée, suspension, réintégration, retrait, rattachement, transfert, création d'un magasin qui donne des droits implicites) s'inscrit au JET 130 de chaque magasin touché — tous les magasins réels pour un administrateur global —, avant et après relus en base, sous les verrous de ces registres pris par id croissant, et au journal d'audit.
11. Une adresse déjà utilisée reçoit la même réponse neutre ; aucun compte n'est touché par une création refusée.

### 4.9 Concurrence, transactions, installation reproductible [S12]

- **Ordre des verrous** (docblock de `RegistreFiscal` réécrit, lot A2) : registres des magasins concernés par id croissant → ligne `societes` (réglages, validation) → ligne du compte modifié (`users`) → lignes du document → produits suivis, par id croissant → `sequences_documents` → têtes du journal d'audit. Une vente ne verrouille jamais la ligne de la société ; l'audit d'une vente (`VenteSansStock`) s'inscrit dans la chaîne **du magasin**, dont la tête se verrouille en dernier : deux magasins d'une société ne se bloquent jamais sur l'audit.
- **Réordonnancement nécessaire** (lot A6) : aujourd'hui la numérotation (`EmettreFactureAction.php:142`) précède le verrou des produits pris par l'écouteur (`StockLedgerService.php:405`, via `FactureEmise` `:155`) ; `EmettreFactureAction` verrouille désormais les produits suivis de la facture par id croissant avant la numérotation, et l'écouteur les retrouve verrouillés. Mêmes alignements dans `EmettreAvoirAction` (retour en stock), la validation d'une facture d'achat et d'un avoir fournisseur (relus avant).
- **Lecture des droits sous verrou** : `LecteurDesDroits::verifierSousVerrou(User, string $autorisation, Magasin)` relit en base, après `RegistreFiscal::verrouiller()`, l'autorisation de l'auteur d'une émission, d'un avoir, d'un paiement, d'une contre-passation, d'une clôture, et celle de vendre sans stock [R1].
- **Contraintes** : clés composites, index uniques partiels nommés, déclencheurs, gardes testées.
- **Tests de concurrence** (groupe `concurrence`, PostgreSQL, comme `EnregistrerPaiementActionConcurrenceTest`) : `test_deux_administrateurs_modifiant_le_meme_membre_se_suivent_et_le_jet_130_reste_coherent` ; `test_un_retrait_d_autorisation_et_une_emission_simultanes_dans_le_meme_magasin_ne_laissent_jamais_passer_une_vente_non_autorisee` ; `test_deux_creations_simultanees_avec_la_meme_adresse_n_en_retiennent_qu_une` ; `test_deux_rattachements_acceptes_en_meme_temps_n_en_retiennent_qu_un` ; `test_deux_ventes_sans_stock_dans_deux_magasins_ne_se_bloquent_pas_sur_la_societe`.
- **Installation reproductible** : `php artisan migrate:fresh --seed` sous les deux moteurs en CI ; `RetourArriereDesMigrationsTest` ; `DatabaseSeeder` et `DatabaseSeederTest` adaptés ; aucune table de Spatie ne subsiste (test).

---

## 5. Caisse sécurisée et fiscalité

### 5.1 Registre par magasin

Inchangé : chaînes, séquences et grands totaux par magasin. En-tête signé : `'magasin' => ['uuid' => $magasin->uuid, 'siren' => $magasin->est_demonstration ? null : $magasin->societe->siren, 'siret' => $magasin->siret, ...($magasin->est_demonstration ? ['demonstration' => true] : [])]` [`RegistreFiscal.php:412`]. Le vérificateur ne compare que l'uuid. Les données d'un JET ne contiennent plus ni IP ni navigateur (1.3, `traces_evenements`) ; `EnregistrementFiscalResource` [`:73-78`] et `ExporterJournalDesEvenementsAction` [`:140-141`] les lisent dans la trace tant qu'elle existe (colonnes vides ensuite).

### 5.2 Réglage commun modifié : JET 270 dans chaque magasin

- Paramètres journalisés : `regime_tva`, `option_debits_tva`, `mois_fin_exercice`, `siren`, `numero_tva` ; `siret` dans le seul magasin concerné ; `statut_magasin` (fermeture, réouverture, suspension) ; `statut_societe` (révocation). Raison sociale, forme, capital, immatriculation, siège : journal d'audit seul.
- `JournaliseSesParametresDeConformite` [`:41-58`] réécrit autour de `magasinsDuJournal()` : un produit renvoie son magasin ; la société, tous ses magasins réels triés par id, fermés compris. La démonstration ne reçoit pas les réglages de la société.
- `RegistreFiscal::sousVerrouDesMagasins(Collection $magasins, Closure $operation)` (généralise `sousVerrou` [`:141`]) : transaction ; verrou de chaque registre par `runAs` dans l'ordre des id ; société relue sous verrou ; enregistrement ; journal dans chaque magasin. Fermé par défaut.

### 5.3 Mois de fin d'exercice (D4 complété par S14)

- `societes.mois_fin_exercice` (décembre par défaut), réglable tant qu'aucun magasin réel n'a vendu ; verrouillé dès la première vente, contrôle sous les verrous de 5.2.
- Exception légale [S14] : procédure de l'éditeur (9.5), date de fin tirée du procès-verbal, jamais d'exercice clos modifié, d'archive supprimée ni de clôture repoussée ; JET 270 dans chaque magasin, audit, `changements_fin_exercice`. D4 n'est pas remplacée.

### 5.4 Émetteur figé [S3, S4, S5]

État [b2bce46] : `Emetteur` porte nom, raison sociale, forme (texte libre), capital, SIREN, SIRET, immatriculation, n° de TVA, adresse du magasin, coordonnées, régime, option, mentions (`Emetteur.php:28-47`) ; `duMagasin()` (`:54-76`), `duContenuSigne()` (`:85-107`), `lignesDIdentite()` (`:149-168`) ; `FigeSonEmetteur::emetteur()` relit le contenu signé dès qu'un document est numéroté (`FigeSonEmetteur.php:54-61`). Le siège n'existe nulle part.

Changements (lot A2) :
- `Emetteur::duMagasin(Magasin)` lit la société et l'établissement ; `Emetteur::deDemonstration(Magasin)` (lot A3).
- Nouveaux champs : `formeJuridique` (libellé imprimé), `estEntrepreneurIndividuel`, `capitalVariable`, `siegeAdresse`, `siegeComplementAdresse`, `siegeCodePostal`, `siegeVille`, `estDemonstration` ; écrits et relus par `enTableau()` et `duContenuSigne()`.
- `denominationImprimee()` [S5] : pour un entrepreneur individuel, si la raison sociale ne contient pas déjà la mention en mot entier (`/(^|\s)(EI|Entrepreneur individuel)(\s|$)/iu`), PILIQI **ajoute « EI » juste après le nom** ; le propriétaire n'a rien à taper (R526-27 C. com., source web : nom précédé ou suivi immédiatement de la mention). La garde n'interdit qu'une saisie contradictoire (mention « EI » pour une autre forme).
- `lignesDIdentite()` : dénomination, forme (« à capital variable » le cas échéant), capital quand il est renseigné ; adresse du magasin, SIRET ; puis « Siège social : … » (« Adresse de l'entreprise : … » pour un entrepreneur individuel), **sauf** s'il est identique à l'adresse du magasin (comparaison normalisée : casse, accents, espaces, ponctuation, sur adresse, complément, code postal, ville), auquel cas l'adresse dit « Siège social et établissement » ; SIREN, immatriculation, n° de TVA, coordonnées.
- Tout est figé à l'émission (lot 1, `FigeSonEmetteur`).
- `MentionsFiscales` et `DocumentMail` [`:134-141`] lisent ces champs.
- Tests (`EmetteurFigeTest`) : `test_le_pdf_d_une_facture_emise_garde_le_siege_la_forme_et_le_capital_du_jour_de_son_emission` ; `test_le_siege_identique_a_l_adresse_du_magasin_n_est_imprime_qu_une_fois` ; `test_un_entrepreneur_individuel_imprime_la_mention_ei_son_adresse_d_entreprise_et_aucun_capital` ; `test_la_mention_ei_est_ajoutee_quand_elle_manque_et_jamais_doublee` ; `test_une_sous_chaine_ei_dans_un_mot_ne_vaut_pas_mention` ; `test_un_document_de_demonstration_n_imprime_aucune_identite_reelle`.

### 5.5 Mentions obligatoires

- `Societe::mentionsLegalesManquantes()` (facture à un professionnel et son avoir) : raison sociale ; forme ; SIREN ; immatriculation ; siège (ou adresse de l'entreprise) ; capital si `exigeUnCapital()` (toute société commerciale ; pour `Autre`, selon `societe_commerciale`, qui doit être renseignée) ; n° de TVA hors franchise. `Magasin::mentionsLegalesManquantes()` ajoute SIRET, adresse, code postal, ville.
- `IdentiteDuMagasinIncompleteException` devient `IdentiteDeLaSocieteIncompleteException` (« Administration de la société > Identité »).
- Le capital reste imprimé quand il est renseigné pour les autres formes (H2).
- Sources (source web) : R123-237, R123-238 C. com. ; art. 242 nonies A ann. II CGI ; R526-27 C. com. (liens en fin de document).

### 5.6 Numérotation

Une série par magasin, `FAC-CODE-ANNÉE-NNNNNN`. L'unicité attendue par la plateforme porte sur le SIREN du vendeur, le numéro et l'année : un code de magasin d'une société révoquée du même SIREN n'est jamais redonné (2.4). Changement de SIRET, fermeture puis réouverture, correction du SIREN : le magasin garde ses séries. Démonstration : `FAC-DEMO-…`, `TIC-DEMO-…`.

### 5.7 Fermeture et réouverture d'un magasin [S8, S9]

**À la fermeture** : clôtures journalières en attente jusqu'au jour même, dans la transaction ; JET 270 ; plus aucun ticket Z tant qu'il est fermé. Clôtures mensuelle et de l'exercice et archive finale (étape 3) : selon la réponse Q7 — recommandé : pour une fermeture **définitive** (cessation, cession, résiliation de la société) à la date de fermeture, comme S9 le prévoit ; pour une fermeture **temporaire**, aucune clôture d'exercice ni archive anticipée, les clôtures mensuelle et de l'exercice venant à leurs dates normales (un exercice appartient à la société, pas à l'établissement).

**Production des Z** : `CloturerLesJourneesAction::journeesEnAttente()` [`:48-72`] ne parcourt que les jours, depuis la première vente, qui tombent dans une période d'activité du magasin, dimanches et jours de fermeture habituelle compris, jusqu'au jour demandé. `ClotureAutomatiqueCaisse` [`:45`] traite tous les magasins et ne trouve aucune journée pour un magasin fermé (docblock réécrit, la mention de `deleted_at` disparaît).

**À la réouverture** :
1. Même registre, mêmes chaînes, séquences et grands totaux (aucune remise à zéro, aucun JET 260) ; JET 270 `ferme` → `actif`.
2. Séries continues.
3. Aucun Z pour les jours de fermeture. Le premier Z est celui du **premier jour non encore clôturé** : le jour même si la fermeture date d'un jour antérieur ; le lendemain si le magasin a été fermé et rouvert le même jour (la journée de la fermeture est déjà clôturée, et K4 refuse toute vente jusqu'à minuit).
4. Étape 3 : la prochaine archive part de la réouverture ; pour les clôtures mensuelle et de l'exercice, selon Q7.
5. Caisses personnelles fermées d'office à la fermeture (R5) : elles repartent de zéro.
6. Le stock laissé en place redevient disponible ; une cession est définitive.
7. Ventes dès que Sirene confirme le SIRET.

Tests (`tests/Feature/Societe/FermerEtRouvrirMagasinTest.php`) : `test_la_fermeture_exige_l_autorisation_dans_la_portee_et_le_mot_de_passe` ; `test_la_fermeture_cloture_les_journees_en_attente_et_s_inscrit_au_jet_270` ; `test_un_magasin_ferme_passe_en_lecture_seule_et_ne_produit_plus_de_ticket_z` ; `test_la_reouverture_reprend_le_meme_registre_les_memes_series_et_les_memes_grands_totaux` ; `test_aucun_ticket_z_n_est_produit_pour_les_jours_de_fermeture` ; `test_le_premier_ticket_z_apres_la_reouverture_est_celui_du_premier_jour_non_cloture` ; `test_fermer_et_rouvrir_le_meme_jour_ne_produit_qu_un_z_et_reprend_les_ventes_le_lendemain` ; `test_une_fermeture_pour_cession_sort_le_stock_au_cout_moyen_et_remet_la_valeur_hors_taxe` ; `test_seul_le_proprietaire_cede_un_magasin_en_retapant_son_nom` ; `test_un_magasin_cede_ne_se_rouvre_pas` ; `test_la_reouverture_d_un_siret_ferme_a_l_insee_est_refusee` ; `test_la_reouverture_attend_la_confirmation_sirene_si_l_insee_ne_repond_pas` ; `test_une_reouverture_ne_leve_jamais_la_suspension_de_l_editeur` ; `test_un_magasin_suspendu_peut_etre_ferme_et_reste_suspendu` ; `test_les_evenements_de_fermeture_et_de_reouverture_sont_emis_pour_les_abonnements` ; `test_une_vente_simultanee_a_la_fermeture_est_faite_avant_ou_refusee_apres` (concurrence).

### 5.8 Le magasin de démonstration et la caisse sécurisée

- Un magasin à part entière : son registre (chaînes, séquences, grands totaux), ses séries, ses clôtures ; le registre et les séries des magasins réels ne reçoivent rien.
- **C'est un mode école au sens de la caisse sécurisée** (BOI-TVA-DECLA-30-10-30 : les transactions simulées entrent dans le périmètre sécurisé, source web). La décision ECO-01 de `scratchpad/caisse-securisee-conception.json` (« aucun mode école n'est proposé », codes 100 et 105 « sans objet », attestation « absent : mode école ») est donc révisée au lot A3 : JET 100 (entrée en mode démonstration) à chaque ouverture d'une période de la démonstration, JET 105 (sortie) à chaque fermeture, dans son registre ; documents marqués ; chaîne et séquences séparées (magasin distinct) ; exclue des totaux réels, de l'e-reporting et des transmissions. À l'étape 3, la notice (`resources/views/caisse-securisee/notice.blade.php`) et le périmètre de l'attestation disent : « mode démonstration : magasin distinct, registre et séries propres, documents marqués, exclu des totaux réels, de l'e-reporting et des transmissions ».
- Chaque document porte « DÉMONSTRATION — SANS VALEUR » ; chaque ticket « TICKET DE DÉMONSTRATION — NE PAS REMETTRE À UN CLIENT » ; aucun champ libre du magasin n'est imprimé ; contenu signé `emetteur.demonstration = true`, en-tête `siren` et `siret` nuls.
- Le vérificateur d'intégrité la contrôle comme les autres.

### 5.9 E-reporting et facture électronique

L'assujetti est le SIREN (`openapi.json:831`) ; `SoumettreEReportingPeriodique` [`:42-46`] et `CalculateurPeriodeEReporting` lisent le régime de la société et ne retiennent que les magasins réels des sociétés validées.

### 5.10 Attestation

Une attestation par magasin réel, nommant la société, son SIREN et le SIRET du magasin. Le volet 2 (BOI-LETTRE-000242, source web) est signé par le représentant légal ou son mandataire : à l'étape 3, dans l'application par le propriétaire **dont la qualité est prouvée à son nom** (nom, prénom, qualité et vérification utilisée enregistrés), sinon à la main. Une correction exceptionnelle du SIREN exige une nouvelle attestation par magasin. Version à citer : BOI-TVA-DECLA-30-10-30 en vigueur au 25/03/2026 (rétablit l'attestation individuelle de l'éditeur, source web), à relire avant l'étape 3.

---

## 6. SUPER PDP [S2]

Aucun code SUPER PDP ne change dans ces lots ; cette section fixe l'étape 2, et le plan `scratchpad/superpdp-plan.md` est révisé au lot 21 étendu.

### 6.1 Une connexion par société

- Vérifié dans la spécification : une company est une structure juridique d'un SIREN ; le jeton désigne une company (`GET /v1.beta/companies/me`) ; `GET /v1.beta/invoices` liste toutes ses factures : un jeton par magasin n'isolerait rien.
- Connexion par le propriétaire (OAuth 2.1, code d'autorisation, `state` et PKCE liés à la session, à la société et au propriétaire). Au retour : `companies/me.number` = `societes.siren` avec `number_scheme = fr_siren` ; sinon refus journalisé, aucun jeton gardé.
- **Bac à sable** : les entreprises fictives (Tricatel 000000001, Burger Queen 000000002) ne passent pas la clé de Luhn exigée par `SirenValide` (vérifié par calcul). Hors production seulement, et seulement si `SUPERPDP_URL` désigne le bac à sable, une correspondance configurée (`services.superpdp.correspondances_bac_a_sable` : uuid de société de test → numéro de company `sandbox`) remplace l'égalité des SIREN. Test `test_en_bac_a_sable_une_societe_de_test_se_relie_a_tricatel` ; `test_aucune_correspondance_de_bac_a_sable_n_est_lue_en_production`.
- Table `connexions_pdp` (`BelongsToSociete`), qui remplace `mandats_pdp`.

### 6.2 Adresses de réception

- Une adresse par magasin réel : `POST /v1.beta/directory_entries` `{directory: ppf, identifier: "SIREN_SIRET", effective_date}` (bac à sable : `peppol`). La ligne `SIREN` reste active.
- `adresses_reception_pdp` (`BelongsToSociete`, clé composite vers `magasins(societe_id, id)`, identifiant unique, code de routage, statut d'annuaire, dates d'effet et de fin) : nouveau magasin → ligne ; changement de SIRET → nouvelle ligne, ancienne close ; fermeture → close ; réouverture → recréée.
- `superpdp_directory_entry_identifier` et `superpdp_send_and_receive`, cités dans l'étape 2 de CLAUDE.md, sont absents de la spécification 1.36.0.beta (qui ne liste que `login_hint` et `superpdp_company_number(_scheme)`) : le texte de l'étape 2 est amendé (section 12) et on ne les envoie pas tant que la documentation officielle ne les confirme pas.

### 6.3 Attribution automatique des factures reçues

Données de l'API (`GET /v1.beta/invoices?direction=in&expand[]=en_invoice&expand[]=en_invoice.buyer&expand[]=en_invoice.addressed_to&expand[]=en_invoice.delivery_information&expand[]=en_invoice.deliver_to_address`) :

| Indice | Champ | Force |
|---|---|---|
| E1 adresse électronique de facturation | `buyer.electronic_address` (BT-49), formes `SIREN`, `SIREN_SIRET`, `SIREN_SIRET_CODE`, `SIREN_SUFFIXE` | forte (sauf `SIREN` seul) |
| E2 SIRET destinataire | schéma 0009 dans `buyer.identifiers` (BT-46), `buyer.legal_registration_identifier` (BT-47), `addressed_to.identifiers`, `addressed_to.siren_identifier`, `delivery_information.delivery_identifier` (BT-71) | forte |
| E3 code de routage | schéma 0224 (XP Z12-012, source web) dans `buyer.identifiers` ou `addressed_to.identifiers`, ou suffixe de l'adresse | forte |
| F1 adresse de livraison | `deliver_to_address` (BG-15) | faible |
| F2 adresse de l'acheteur | `buyer.postal_address` (BG-8), si ce n'est pas le siège | faible |
| F3 références | `buyer_reference` (BT-10), `purchase_order_reference` (BT-13), comparées au code et aux `references_acheteur` du magasin | faible |

Algorithme (`RoutageDesFacturesRecues`, déterministe, testé cas par cas) :
1. S = magasins réels désignés par les indices forts (SIRET actuels et anciens, adresses de réception, codes de routage).
2. |S| = 1 sans indice contraire → **attribution automatique**.
3. |S| ≥ 2, ou indice faible désignant précisément un autre magasin → **« À attribuer »**, motif « informations contradictoires », candidats listés, aucune présélection.
4. S vide : société d'un seul magasin réel → attribution automatique ; un seul magasin désigné par les indices faibles → « À attribuer » avec proposition présélectionnée ; plusieurs → candidats sans présélection ; aucun → motif « destinataire non précisé ».
5. Un magasin fermé ou suspendu reste destinataire ; import en brouillon seulement une fois rouvert.

Ce que l'API remplit réellement est à constater sur des factures du bac à sable avant de coder (phase 10 du plan SUPER PDP).

### 6.4 Liste « À attribuer », corrections, journal

- `factures_recues` (`BelongsToSociete`) : `magasin_id` nullable, `mode_attribution` (`automatique`, `proposee_confirmee`, `manuelle`), `preuves_attribution` (`json`), `attribuee_le`, `attribuee_par` ; `unique(connexion_pdp_id, facture_externe_id)` ; `factures_achat.facture_recue_id` unique.
- Attribution atomique (`update … where magasin_id is null`), import sous `runAs`, jamais dans deux magasins ; audit `FactureRecueAttribuee`.
- Correction (`ReattribuerFactureRecueAction`) tant que le brouillon n'est pas validé ; après validation, avoir fournisseur.
- Chaque magasin ne voit que ses factures ; alerte et rappel quotidien aux destinataires de 3.9.

### 6.5 E-reporting, émission, historique

Chaque magasin réel pousse ses `b2c_transactions` et le statut fr:212 avec le jeton de la société ; seul `ParametresFiscauxSociete` envoie `PATCH /v1.beta/companies` ; chaque transmission retient `connexion_pdp_id` ; « transmettre les factures émises avant la connexion » exclut une facture dont le contenu signé porte un autre SIREN ; la démonstration ne transmet rien ; l'imputation d'un avoir de régularisation n'est jamais un encaissement (9.4).

### 6.6 La vérification de la plateforme, brique de la vérification de la société

`GET /v1.beta/oauth2_sessions/me` donne `company_verification_status` (« `verified` : the user is successfully linked to the company and has legal authority to manage its resources ») et `user_identity_verification_status` de l'utilisateur qui a accordé le jeton (`openapi.json:3444`). Une plateforme agréée doit vérifier identité et qualité (art. 242 nonies E et F ann. II CGI, source web). Les deux à `verified`, au même SIREN, prouvent la qualité **de la personne qui s'est connectée** : la ligne `pdp_kyb` porte son identité ; `qualite_verifiee_le` n'est posé que sur le compte PILIQI qui a fait la connexion (le propriétaire). Depuis la version 1.23 de la spécification, un cabinet comptable peut inscrire ses utilisateurs sans leur présence : la voie B3 ne vaut que si `user_identity_verification_status = verified`.

---

## 7. Vérification de la société [S6, S7, S11]

### 7.1 Objectifs

1. N'ouvrir les opérations réelles qu'à une société dont l'existence, l'établissement et l'adresse officielle sont confirmés par l'INSEE, et dont le propriétaire est le représentant légal ou son mandataire.
2. Automatiser pour un grand nombre de sociétés ; l'humain seulement pour les cas douteux.
3. Ne jamais laisser un usurpateur bloquer, évincer définitivement ou démasquer le vrai titulaire.
4. Graduer : le courrier quand la voie choisie ne suffit pas seule (voie manuelle) ou sur signal ; il ne prouve jamais seul la qualité.
5. Tout journaliser, conserver les preuves, minimiser les données personnelles (pièces effacées dès la décision).
6. Chaque fournisseur derrière une interface, avec une implémentation factice ; clés en variables d'environnement.
7. Prévenir avant le premier document fiscal [S11].

### 7.2 Briques évaluées

| Brique | Prouve | Ne prouve pas | Automatisable | Coût, délai | Source |
|---|---|---|---|---|---|
| Sirene (INSEE) | existence, état (A/C, A/F), rattachement, adresses, catégorie juridique | rien sur la personne ; en diffusion partielle (statut P), identité et adresse masquées, commune seule publique | oui | gratuit (clé), 30 req./min | https://static.insee.fr/api-sirene/Insee_API_publique_modalites_connexion.pdf ; https://www.data.gouv.fr/dataservices/api-sirene-dont-unites-a-diffusion-partielle (source web) |
| RNE (INPI) | dirigeants (personnes physiques ou morales), rôle, mois et année de naissance, forme, capital, siège | que l'utilisateur est cette personne | oui | gratuit | documentation API formalités v4.0 INPI (source web, à confirmer) |
| Identité à distance PVID (ANSSI), niveau substantiel | identité de la personne ; rapprochée du RNE : qualité de dirigeant | l'adresse ; un mandat | oui | payant à l'acte | ANSSI portées de certification (source web) |
| SUPER PDP vérifié (étape 2) | qualité vérifiée par une plateforme agréée ; identité de l'utilisateur | — | oui | nul | 6.6 |
| Courrier recommandé AR à code | réception à l'adresse officielle ; alerte le vrai titulaire | la qualité | oui (API) | quelques euros, quelques jours | [S6, S7] |
| LRE qualifiée (eIDAS art. 44) à un dirigeant inscrit | réception par ce dirigeant identifié ; équivalente au recommandé (CPCE L100) | — | oui | payant | https://cyber.gouv.fr/lenvoi-recommande-electronique (source web) |
| Lettre simple d'information | rien ; alerte le vrai titulaire | — | oui | centimes | — |
| Pièces examinées par l'éditeur | sur pièces, mais pièces publiques ou falsifiables | identité sans contrôle de présence | non | temps humain | — |
| FranceConnect | — | — | écarté : ouverture au privé limitée aux services où la loi exige l'identité (source web) | — | — |

### 7.3 Combinaison retenue

**A — entreprise et établissement** (automatique) : Sirene à l'inscription, pour chaque nouveau magasin, changement de SIRET et réouverture.

**B — qualité de représentant ou de mandataire du propriétaire** :
- **B1** : identité du propriétaire vérifiée (PVID) et rapprochée d'un dirigeant RNE ayant pouvoir de représentation (nom de naissance ou d'usage, premier prénom, mois et année de naissance, sans accents ni casse ; rôles : gérant, président, directeur général, directeur général délégué, président du directoire, directeur général unique, entrepreneur ; codes INPI à confirmer). **Dirigeant personne morale** : `RapprochementDesDirigeants` lit au RNE les représentants de cette société et accepte un rapprochement avec l'un d'eux (profondeur 2 au plus ; chaîne inscrite dans `details`).
- **B2** : identité du propriétaire vérifiée (PVID) et mandat approuvé par un dirigeant RNE lui-même vérifié et rapproché (`DemandeAccordDirigeant`, texte « J'autorise {nom} à gérer le compte PILIQI de {société} »).
- **B3** (étape 2) : connexion SUPER PDP `verified` au même SIREN, identité de l'utilisateur `verified`.
- **B4** : pièces examinées par l'éditeur (Kbis ou extrait RNE de moins de trois mois, pièce d'identité, mandat), **toujours avec C**.

**Liaison de la vérification d'identité au compte** (contre le relais à la victime) : `VerificationIdentite::demarrer(User $proprietaire, Societe $societe, string $urlDeRetour)` ouvre une session de prestataire à usage unique, liée à la session PILIQI par un `state` vérifié au retour ; l'écran de consentement du prestataire affiche « Vous confirmez être {nom du compte} et prendre le contrôle du compte PILIQI de {société}, créé avec l'adresse {e-mail masqué} » ; le nom et les prénoms vérifiés sont montrés au propriétaire et inscrits dans `details` ; la lettre d'information de la validation nomme la personne vérifiée.

**C — contrôle de l'adresse** : code par courrier recommandé AR à l'adresse officielle (Sirene ; en diffusion partielle, adresse du RNE lue par l'API), ou LRE qualifiée remise à un dirigeant RNE identifié et rapproché.

**Règle de validation** (`EvaluateurDeVerification::evaluer(Societe): EtatDeVerification`, fonction pure des lignes de `verifications_societe` et de deux booléens lus sur le même SIREN) :

```text
validable ⇔ A
          ∧ (B1 ∨ B2 ∨ B3 ∨ (B4 ∧ C))
          ∧ ∀ signal actif s : leve(s)          (table 7.4, chaque signal par ce qu'il exige)
          ∧ aucune autre société du SIREN validée (non révoquée)
A ⇔ unité active ∧ chaque magasin réel ACTIF : établissement actif et rattaché
reclamation_possible ⇔ (B1 ∨ B2 ∨ B3 ∨ (B4 ∧ C)) ∧ une autre société du SIREN est validée
refus définitif ⇔ unité cessée avant validation ∨ refus de l'éditeur
```

`ValiderLaSocieteAction` (transaction, ligne `societes` verrouillée) : réévalue ; pose `validee_le` (l'index refuse un second SIREN validé : la course perdue devient `reclamation_possible`) ; réserve les SIRET des magasins réels actifs (un SIRET déjà réservé par une autre société → refus avec le message de la réclamation, 9.3) ; un magasin réel fermé sans document n'est ni réservé ni ouvert ; ouvre une période d'activité pour chaque magasin réel actif ; pose `qualite_verifiee_le` du propriétaire (vérification B utilisée) ; audit `SocieteValidee` ; **ne refuse aucune autre inscription** : les autres sociétés en attente du même SIREN restent en attente ; celles qui ont déjà une preuve de qualité sont prévenues sans rien révéler de l'autre (« L'inscription de ce SIREN demande un examen par l'éditeur ») et l'éditeur est alerté (signal `inscriptions_concurrentes` chez elles). Après la transaction : ferme la démonstration (7.8), envoie la lettre simple d'information au siège (adresse Sirene, ou RNE en diffusion partielle), émet `SocieteValidee`, prévient le propriétaire. Se déclenche à chaque vérification concluante, et par la commande de l'éditeur pour B4.

**Recommandation** : B3 en voie principale dès l'étape 2 ; B1 (B2 pour un mandataire) avant ; B4 avec C en repli. Avant l'étape 2 et un contrat PVID, toute validation passe par B4 et C : chaque validation coûte alors un recommandé, ce qui est le prix d'une protection réelle avec de simples pièces.

### 7.4 Signaux de risque (`SignalDeRisque`, `leveePar()` écrit dans l'enum et testé signal par signal)

| Code | Condition | Levé par |
|---|---|---|
| `diffusion_partielle` | Sirene en diffusion partielle | `lre` concluante, ou `courrier` à l'adresse lue au RNE par l'API ; à défaut `manuelle` avec courrier de l'éditeur à l'adresse d'un justificatif **qu'il obtient lui-même** ; jamais un courrier vers une adresse tirée d'une pièce déposée |
| `inscriptions_concurrentes` | une autre société du même SIREN a une preuve de qualité concluante | `courrier` (adresse officielle) **et** `manuelle` |
| `antecedent_usurpation` | une société de ce SIREN a été révoquée | `courrier` **et** `manuelle` |
| `dirigeant_recent` | le dirigeant rapproché est en fonction depuis moins de 30 jours (RNE) | `courrier` ou `lre` |
| `forme_contradictoire` | forme saisie contraire à la catégorie Sirene | correction de la forme (signal recalculé) ou `manuelle` |
| `adresse_contradictoire` | adresse d'un magasin contraire à Sirene, ou pli revenu | correction (recalculé) ou `manuelle` |
| `identite_non_concluante` | prestataire `a_examiner`, ou identité vérifiée sans dirigeant rapproché ni mandat | `courrier` **et** `manuelle` |
| `essais_repetes` | cinq codes faux, ou trois vérifications d'identité échouées | `manuelle` seulement ; voies automatiques bloquées en attendant |
| `decision_editeur` | l'éditeur lève un doute | `manuelle` |

B4 ne lève aucun signal. Une ligne `manuelle` cite le signal levé et sa justification.

### 7.5 Le courrier recommandé et son équivalent électronique

- **Code** : 12 caractères tirés par générateur cryptographique dans l'alphabet de Crockford sans I, L, O, U, groupés « XXXX-XXXX-XXXX » (60 bits) ; seul `Hash::make(code)` est conservé ; 5 essais ; usage unique ; 30 jours après le dépôt ; un code actif par SIREN et par motif dans tout le logiciel ; au plus deux plis par SIREN et par 30 jours sans l'éditeur.
- **Qui peut demander un courrier** : un propriétaire dont l'identité a été vérifiée (PVID concluante, même si non rapprochée), ou l'éditeur. Une inscription factice, sans identité vérifiée, ne fait partir aucun pli et ne lève aucun signal chez le vrai titulaire.
- **Contenu** : dénomination, SIREN, objet, code, expiration, où le saisir, et « Si vous n'êtes pas à l'origine de cette demande, signalez-le à l'éditeur : {adresse} ».
- **Envoi** (`EnvoyerCodeDeConfirmationAction`) : recommandé AR avec remise contre signature, à l'adresse officielle lue le jour de l'envoi (Sirene ; RNE par l'API en diffusion partielle), jamais une adresse saisie ou déposée ; statuts reçus par rappel signé ou interrogés chaque heure ; pli revenu → code annulé, `adresse_contradictoire`.
- **LRE qualifiée** à un dirigeant RNE (adresse fournie par le propriétaire) : le code n'est saisi qu'après acceptation du pli ; l'identité établie par le prestataire qualifié doit être rapprochée du dirigeant visé ; alors elle remplace le courrier.
- **Saisie** (`SaisirCodeDeConfirmationAction`, propriétaire) : limiteur de 5 essais par heure en plus du compteur ; réussite → ligne `courrier` ou `lre` concluante, réévaluation.
- **Même mécanisme**, motif distinct, pour la récupération de compte (étape 13), la libération d'un SIRET, la réclamation d'un SIREN (9.3) et l'examen d'un SIREN (9.4) [S7].

### 7.6 Parcours à l'écran (`Pages/VerificationSociete`)

1. « Entreprise et établissements vérifiés auprès de l'INSEE » : « Revérifier » si l'INSEE était injoignable ; motif d'un refus.
2. « Votre qualité de représentant » : « Vérifier mon identité » ; « Je ne suis pas dirigeant : demander l'accord d'un dirigeant » ; (étape 2) « Connecter SUPER PDP » ; « Déposer des pièces pour l'éditeur » (B4 : formulaire de dépôt chiffré, avec la mention « Vos pièces sont effacées dès la décision, au plus tard 30 jours après leur dépôt » ; un courrier suivra).
3. Si un signal ou B4 l'exige : « Recevoir un code par courrier recommandé à {adresse officielle} » ou LRE ; saisie.
4. « Société validée le … ». Ou, si une autre société du SIREN est validée : « Ce SIREN est déjà utilisé par un autre compte PILIQI. L'éditeur a été prévenu et va examiner votre demande. » (révélé seulement après une preuve de qualité concluante).

Bandeau dans les deux panneaux tant que la société n'est pas validée : « Société en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés. Essayez PILIQI dans le magasin de démonstration. »

Avant la vérification d'identité, l'écran informe (art. 13 RGPD) et recueille le consentement explicite au traitement biométrique du prestataire (art. 9.2.a), en présentant les autres voies (pièces avec courrier, SUPER PDP dès l'étape 2) comme alternatives d'égale valeur.

### 7.7 SIREN et SIRET réservés à la validation

- Avant la validation, rien n'est réservé : plusieurs sociétés en attente du même SIREN coexistent, isolées ; la première validée l'emporte ; les autres restent en attente, et celles qui prouvent ensuite leur qualité deviennent des réclamations (9.3) — aucune n'est refusée pour toujours par la validation d'une autre.
- Le SIREN se corrige par le propriétaire tant que le verrou ne s'applique pas (Q3) : `CorrigerSirenAvantValidationAction` (contrôle Sirene, NIC des magasins ressaisis, vérifications B et C refaites ; selon Q3 (b), une société validée sans document fiscal repasse « à vérifier », SIRET libérés et périodes closes, démonstration non rouverte) ; audit.

### 7.8 Mode démonstration

**Ce que c'est** : un magasin marqué `est_demonstration`, au plus un par société, nom « Démonstration », code `DEMO`, ouvert par le propriétaire (ou un administrateur de tous les magasins ayant `societe.magasins.gerer`) tant que la société n'est ni validée ni refusée (`OuvrirLaDemonstrationAction`), avec un catalogue fictif (produits, services avec prix de référence, client comptoir, deux clients fictifs). Jamais créé d'office.

| | Magasins réels avant validation | Démonstration |
|---|---|---|
| Identité, magasins, équipe, paramètres | oui | — |
| Catalogue, clients, fournisseurs | oui | oui (à elle) |
| Achats saisis et validés | oui | oui |
| Brouillons de devis et de factures | oui, **sans aucun PDF** | oui, PDF marqués |
| Devis numéroté, ticket, facture, avoir, paiement, clôture | non (`peutVendre()`) | oui, séries et registre à elle |
| E-mail à un client, relance | non | non |
| Transmission, e-reporting | non | non |
| Identité imprimée | — | fictive, sans SIREN ni SIRET, filigrane |
| Facturation de l'abonnement | à partir de la validation | jamais |
| Membres pour s'entraîner | — | oui |

**Ne pollue jamais** : registre, chaînes, séquences, grands totaux et séries à elle ; aucun JET ni écriture dans un magasin réel ; aucune copie entre elle et les magasins réels ; totaux de société (étape 6) l'excluent.

**Fin** : à la validation, `FermerLaDemonstrationAction` (motif `fin_demonstration`) après la transaction de validation : clôture des journées en attente, caisses personnelles fermées d'office (R5), JET 105, JET 270, période close, audit ; consultable sous « Démonstration (terminée) », jamais rouverte. Si cette fermeture échoue, `peutVendre()` refuse quand même toute opération (la société est validée) et la commande quotidienne la reprend. Sans aucune opération pendant 30 jours avant la validation : même fermeture par `app:fermer-demonstrations-inactives` ; réouverture possible tant que la société n'est ni validée ni refusée (nouvelle période, JET 100).

**Garde unique** : `Magasin::peutVendre()` = pas en lecture seule ∧ (démonstration et société ni validée ni refusée ∨ réel, société validée et établissement vérifié) ; `verifierQuIlPeutVendre()` lève `VenteNonAutoriseeException` motivée. Appelée par `RegistreFiscal::ecrire()` pour les chaînes des ventes et des encaissements (à côté de `verifierQuIlEstOuvert()` [`:260-262`]), juste après le verrou dans `EmettreFactureAction` (avant toute numérotation), par `EmettreDevisAction`, `EnvoyerDocumentAction`, `EnvoyerDocumentJob`, les générateurs de PDF des magasins réels, les jobs de transmission, `RelancerFacturesImpayees`, `SoumettreEReportingPeriodique`. La démonstration refuse en plus e-mail et transmission.

Une limite de durée ou de volume de la démonstration a été examinée et écartée : ses données sont signées, conservées et contrôlées comme les autres, sans identité réelle ; elle ne permet ni d'effacer ni de déguiser une vente.

Tests (`tests/Feature/Societe/MagasinDeDemonstrationTest.php`) : `test_le_proprietaire_ouvre_une_seule_demonstration_avant_la_validation` ; `test_l_ouverture_et_la_fin_de_la_demonstration_s_inscrivent_en_jet_100_et_105` ; `test_la_demonstration_vend_encaisse_et_cloture_dans_son_propre_registre` ; `test_ses_numeros_portent_le_code_demo_et_ne_touchent_aucune_serie_reelle` ; `test_aucun_e_mail_ni_aucune_transmission_ne_part_de_la_demonstration` ; `test_ses_documents_portent_une_identite_fictive_et_le_filigrane` ; `test_l_en_tete_signe_de_la_demonstration_n_a_ni_siren_ni_siret` ; `test_rien_ne_passe_du_magasin_de_demonstration_aux_magasins_reels` ; `test_la_validation_ferme_la_demonstration_qui_reste_consultable` ; `test_la_demonstration_ne_vend_plus_apres_la_validation_meme_si_sa_fermeture_a_echoue` ; `test_une_societe_refusee_ne_rouvre_pas_sa_demonstration` ; `test_une_demonstration_inactive_30_jours_se_ferme_et_se_rouvre_avant_la_validation` ; `test_le_verificateur_d_integrite_controle_aussi_la_demonstration` ; `test_aucun_pdf_d_un_magasin_reel_ne_se_telecharge_avant_la_validation`.

### 7.9 Interfaces, implémentations factices, configuration

| Contrat | Méthodes | Réelle | Factice |
|---|---|---|---|
| `Sirene\Contracts\RepertoireSirene` | `unite(string $siren)`, `etablissement(string $siret)`, `siege(string $siren)` ; `SireneIndisponibleException` | `InseeSirene` : `https://api.insee.fr/api-sirene/3.11`, en-tête `X-INSEE-Api-Key-Integration`, limiteur 30/min, lecture tolérante (modèle `ClientVies`) | `FauxRepertoireSirene` : SIREN fictifs (plage `000`, Luhn) actifs, cessés, fermés, inconnus, diffusion partielle, injoignable |
| `Rne\Contracts\RegistreNationalDesEntreprises` | `representants(string $siren): list<RepresentantRne>` (personne physique ou morale), `adresseDuSiege(string $siren)` ; `RneIndisponibleException` | `InpiRne` | `FauxRne` |
| `Identite\Contracts\VerificationIdentite` | `demarrer(User, Societe, string $urlDeRetour): SessionDIdentite` ; `resultat(string $reference, string $state): ResultatDIdentite` | une classe par prestataire PVID | `FausseVerificationIdentite` |
| `EnvoiRecommande\Contracts\ServiceEnvoiRecommande` | `envoyerCourrier`, `envoyerLre`, `envoyerLettreSimple`, `suivre` | une classe par prestataire | `FauxServiceEnvoiRecommande` |
| `PdpGatewayInterface::lireSession()` (étape 2) | statuts de vérification | `SuperPdpGateway` | `FakePdpGateway` |

- Câblage dans `VerificationServiceProvider` selon `config/services.php` (`services.sirene.pilote`, `services.rne.pilote`, `services.identite.pilote`, `services.recommande.pilote`) ; un pilote `faux` en production fait échouer le démarrage (`test_aucun_pilote_factice_n_est_accepte_en_production`).
- Variables (noms seuls dans `.env.example`) : `SIRENE_PILOTE`, `INSEE_SIRENE_CLE` ; `RNE_PILOTE`, `INPI_RNE_IDENTIFIANT`, `INPI_RNE_MOT_DE_PASSE` ; `IDENTITE_PILOTE`, `IDENTITE_URL`, `IDENTITE_CLE`, `IDENTITE_SECRET_RAPPEL` ; `RECOMMANDE_PILOTE`, `RECOMMANDE_URL`, `RECOMMANDE_CLE`, `RECOMMANDE_SECRET_RAPPEL` ; `JOURNAL_AUDIT_CLE`, `JOURNAL_AUDIT_CLE_ID`, `JOURNAL_AUDIT_CLES_PRECEDENTES` ; `EDITEUR_OPERATEURS` ; `PILIQI_EDITEUR_EMAIL` (alertes à l'éditeur).
- Rappels : `POST /rappels/identite`, `POST /rappels/recommande`, HMAC du secret, horodatage borné, idempotents par référence ; sinon interrogation horaire.
- `Http::preventStrayRequests()` dans la classe de base des tests de vérification ; chaque implémentation réelle testée par `Http::fake`.

### 7.10 Après la validation : revérifications

`app:reverifier-sirene` : chaque heure pour les contrôles en échec ; chaque mois pour les sociétés validées et leurs magasins. Un écart (unité cessée, établissement fermé, adresse ou catégorie différente) est inscrit et signalé au propriétaire et à l'éditeur ; **aucun blocage automatique** : ni les ventes ni les paiements ne s'arrêtent, `etablissement_verifie_le` n'est pas touché ; l'éditeur décide (suspension, étape 13). Le blocage automatique reste réservé à l'établissement jamais vérifié (création, réouverture).

Tests (`tests/Feature/Societe/VerificationSocieteTest.php`) : `test_une_societe_a_verifier_n_emet_ni_ticket_ni_facture_ni_avoir_ni_devis_numerote` ; `test_une_societe_a_verifier_n_enregistre_aucun_paiement_et_n_ecrit_a_aucun_client` ; `test_une_societe_a_verifier_se_configure_catalogue_equipe_parametres_et_achats` ; `test_le_registre_refuse_toute_vente_d_un_magasin_dont_la_societe_n_est_pas_validee` ; `test_un_magasin_dont_l_etablissement_n_est_pas_verifie_ne_vend_pas` ; `test_l_identite_verifiee_rapprochee_d_un_dirigeant_du_rne_valide_la_societe` ; `test_le_president_de_la_holding_presidente_valide_la_filiale` ; `test_un_mandat_approuve_par_un_dirigeant_verifie_valide_la_societe` ; `test_une_identite_sans_dirigeant_correspondant_ne_valide_rien` ; `test_une_identite_verifiee_au_nom_d_un_autre_que_le_titulaire_du_compte_est_montree_et_nommee_dans_la_lettre` ; `test_une_session_d_identite_ne_sert_qu_une_fois_et_qu_a_la_session_qui_l_a_ouverte` ; `test_une_validation_manuelle_sans_code_de_courrier_ne_valide_rien` ; `test_la_validation_manuelle_ne_leve_pas_un_antecedent_d_usurpation` ; `test_chaque_signal_ne_se_leve_que_par_ce_qu_il_exige` (paramétré) ; `test_le_code_du_courrier_seul_ne_valide_jamais_la_societe` ; `test_le_code_est_hache_unique_temporaire_et_a_usage_unique` ; `test_le_cinquieme_code_faux_annule_le_code_et_bloque_les_voies_automatiques` ; `test_le_courrier_part_a_l_adresse_officielle_sirene_jamais_a_une_adresse_saisie` ; `test_en_diffusion_partielle_aucun_courrier_ne_part_a_une_adresse_fournie` ; `test_une_inscription_factice_n_impose_pas_de_courrier_au_vrai_titulaire` ; `test_le_nombre_de_courriers_est_limite_par_siren` ; `test_une_lre_qualifiee_remise_a_un_dirigeant_identifie_remplace_le_courrier` ; `test_la_validation_reserve_le_siren_et_les_siret_des_magasins_actifs_et_ouvre_leurs_periodes` ; `test_un_magasin_ferme_avant_la_validation_ne_bloque_pas_la_validation` ; `test_la_validation_ne_refuse_aucune_autre_inscription_du_meme_siren` ; `test_la_validation_envoie_la_lettre_d_information_au_siege` ; `test_deux_validations_simultanees_du_meme_siren_n_en_retiennent_qu_une_et_l_autre_devient_une_reclamation` (concurrence) ; `test_un_rappel_de_prestataire_mal_signe_ou_rejoue_est_refuse` ; `test_l_insee_ou_l_inpi_injoignable_laisse_l_etape_en_attente` ; `test_aucune_donnee_d_identite_hors_liste_blanche_n_est_conservee` ; `test_les_pieces_deposees_sont_chiffrees_et_effacees_a_la_decision` ; `test_une_revérification_negative_n_arrete_ni_les_ventes_ni_les_paiements` ; (étape 2) `test_une_connexion_superpdp_verifiee_au_meme_siren_prouve_la_qualite_de_celui_qui_s_est_connecte`.

---

## 8. Règles R1 à R5

### 8.1 R1 — Vente au-delà du stock [R1 = c + note]

Constat [b2bce46] : sortie de stock par l'écouteur synchrone `DecrementerStockApresEmissionFacture` (`:20-35`) via `StockLedgerService::enregistrer()` (`:189-203`), sans contrôle ; `produits.stock_quantite` entier signé (migration `produits` `:46`) ; seule la valeur est bornée à 0 € (K1).

Règle :
- Sans `stock.vendre_sans_stock` dans le magasin, une vente qui ferait passer sous zéro un produit suivi est **refusée** (`StockInsuffisantException`, « Stock insuffisant : Câble USB, reste 1, demandé 3 »), sans numéro ni signature.
- Avec : **avertissement avant validation** (« Le stock de Câble USB passera à −2 : confirmer la vente ? ») ; le serveur exige `confirmationVenteSansStock`, liste des manques confirmés (`produit_id`, stock prévu après la vente) ; si le stock réel est plus défavorable que ce qui a été confirmé, `ConfirmationVenteSansStockRequiseException` et nouvelle fenêtre ; confirmée, la vente passe ; **alerte** `StockNegatif` (destinataires 3.9) ; la **quantité négative reste visible** jusqu'à sa régularisation par un achat validé, une réception ou un inventaire (étape 7) : colonne « Stock » en rouge « À régulariser », filtre « Stock négatif », compteur au tableau de bord.
- **Journalisé** : audit `VenteSansStock` (chaîne du magasin) et note du mouvement.
- **Ventes simultanées** : contrôle dans `EmettreFactureAction`, après le verrou du registre, sur les produits verrouillés par id avant la numérotation (4.9), autorisation relue sous ce verrou. Seul point d'entrée : `EmettreFactureAction` (caisse, facture, conversion de devis).
- Non concernés : produit sans suivi de stock ; retours ; facture rectificative d'une régularisation (`facture_rectifiee_id`, sans mouvement de stock).
- Fichiers : `VerificateurDeStock` (`manques(Facture): list<ManqueDeStock>`), deux exceptions, notification `StockNegatif`, `EmettreFactureAction`, `EnregistrerVenteCaisseAction`, `Caisse`, `ViewFacture`/`FactureResource`, `ProduitResource`.
- Tests (`tests/Feature/Stock/VenteSansStockTest.php`) : `test_sans_l_autorisation_une_vente_au_dela_du_stock_est_refusee_sans_numero_ni_signature` ; `test_avec_l_autorisation_la_vente_exige_la_confirmation_de_l_avertissement` ; `test_une_confirmation_devenue_insuffisante_redemande_l_avertissement` ; `test_la_vente_confirmee_rend_le_stock_negatif_alerte_et_se_journalise` ; `test_le_stock_negatif_reste_visible_jusqu_a_un_achat_qui_le_regularise` ; `test_un_produit_sans_suivi_du_stock_n_est_jamais_bloque` ; `test_une_autorisation_retiree_entre_l_ecran_et_la_validation_refuse_la_vente` ; `test_deux_ventes_simultanees_de_la_derniere_unite_sans_autorisation_n_en_laissent_passer_qu_une` (concurrence) ; `test_deux_ventes_simultanees_avec_autorisation_passent_toutes_deux_et_le_stock_reste_coherent` (concurrence) ; `test_une_requete_forgee_sans_confirmation_est_refusee`.

### 8.2 R2 — Remises et prix [R2 = a]

Constat [b2bce46] : remise libre (`AjouteDesLignesDeVente.php:231`) ; service sans prix au catalogue (migration `services` `:11`), PU TTC et taux saisis à chaque vente ; PU TTC d'une ligne de produit modifiable à l'écran (`:268-295`) et accepté par le domaine (`ConstructeurLigneDocument.php:189-191`) ; test `FactureResourceTest::test_saisir_le_prix_ttc_dune_ligne_totalise_exactement_a_quantite_2`.

Règle :
- Autorisation `vente.remises_et_prix`, cochée par défaut pour le propriétaire (de droit), les administrateurs et les responsables (modèles), pas pour les vendeurs, comptables, observateurs.
- **Prix de référence d'un service** : `services.prix_ttc` et `taux_tva`, fixés dans `ServiceResource` (et à la création depuis la caisse) par qui a `produit.update` et `vente.remises_et_prix`.
- Sans l'autorisation : remise 0 ; une ligne de service prend prix et taux de référence (sans prix de référence : « Le prix de ce service n'est pas fixé : demandez à un responsable ») ; prix d'une ligne de produit selon Q1.
- **Contrôle serveur** (`DerogationsDePrix::introduites(array $lignesAvant, array $lignesApres)`) : une dérogation (remise non nulle, prix ou taux de service différent de la référence, et selon Q1 prix de produit différent de la fiche) exige l'autorisation de celui qui l'introduit ; **toute modification du produit, du service, de la quantité, du PU, du taux ou de la remise d'une ligne qui porte une dérogation compte comme une dérogation introduite** ; une ligne reprise strictement telle quelle (conversion d'un devis, réouverture d'un brouillon) garde l'accord donné. Appelé par `CreerFactureAction`, `ModifierFactureAction`, `CreerDevisAction`, `ModifierDevisAction`, `EnregistrerVenteCaisseAction`, `TrouverOuCreerServiceAction`.
- Écrans : champs désactivés avec une aide.
- Tests (`tests/Feature/Facturation/RemisesEtPrixTest.php`) : `test_un_vendeur_n_accorde_aucune_remise_meme_par_requete_forgee` ; `test_un_responsable_accorde_une_remise` ; `test_un_vendeur_vend_un_service_a_son_prix_de_reference_sans_pouvoir_le_changer` ; `test_un_service_sans_prix_de_reference_ne_se_vend_pas_par_un_vendeur` ; `test_seul_un_membre_autorise_fixe_le_prix_de_reference_d_un_service` ; `test_convertir_un_devis_remise_par_un_responsable_reste_permis_au_vendeur` ; `test_modifier_une_autre_ligne_d_un_brouillon_garde_la_remise_accordee` ; `test_changer_le_produit_ou_la_quantite_d_une_ligne_remisee_exige_l_autorisation` ; `test_les_modeles_cochent_l_autorisation_pour_administrateurs_et_responsables_seulement`.

### 8.3 R3 — Jeton unique par action [R3 = a]

Constat [b2bce46] : aucune idempotence des actions de l'utilisateur ; seuls des jobs le sont. Le panier de la caisse est déjà persistant (`paniers_caisse`, `PanierDeCaisse` : « une vente commencée survit au rechargement de la page »).

Conception :
- **Caisse** : le jeton de la vente est `paniers_caisse.jeton`, créé avec le panier. Une réponse perdue puis un rechargement retrouvent le même panier (vente non faite : même jeton, réessai idempotent) ou un panier vide (vente faite, panier effacé dans la même transaction) avec « Dernière vente enregistrée : ticket N° X » (dernière `actions_traitees` du vendeur dans ce magasin).
- **Autres formulaires et fenêtres** : jeton `Str::uuid()` généré à l'ouverture (propriété Livewire, champ caché de l'action), renouvelé après chaque succès ; pour les documents existants, l'état du document s'y ajoute (une facture déjà émise renvoie son résultat).
- `ActionsUniques::executer(string $jeton, string $action, array $parametresCanoniques, Closure $operation): ResultatDAction` : dans la transaction, après le verrou du registre (ou de la ligne du document pour un envoi), cherche `(magasin_id, jeton)` ; trouvé avec la même action, le même compte et la même `empreinte_demande` → renvoie le résultat (« déjà enregistré ») ; trouvé avec une autre action, un autre compte ou une autre empreinte → refus « demande différente déjà enregistrée sous ce jeton » ; sinon exécute et inscrit (l'index unique arrête une course : annulation, relecture, résultat renvoyé). Une action qui échoue n'inscrit rien. **Seule l'action la plus externe consomme le jeton** : les actions appelées à l'intérieur (émission et paiement d'une vente à la caisse) reçoivent un jeton nul.
- **Couvertes par le jeton** : émission d'une facture ou d'un ticket, vente à la caisse, émission d'un devis, paiement, contre-passation, avoir (`EnregistrerAvoirAction`, `EmettreAvoirAction`), envoi et relance manuelle, fermeture de sa caisse, clôture manuelle du jour, validation d'une facture d'achat et d'un avoir fournisseur. **Idempotentes par leur état ou une contrainte**, testées : fermeture et réouverture d'un magasin (déjà fermé : « déjà fait »), création d'un magasin (`unique(societe_id, siret)`), création d'un membre (`users.email`), clôture automatique (`unique(magasin_id, date_cloture)`). L'API de l'étape 10 branchera `Idempotency-Key` sur le même service.
- Jetons effacés après 30 jours (`app:purger-actions-traitees`).
- Tests (`tests/Feature/Facturation/ActionsUniquesTest.php`) : `test_une_emission_repetee_avec_le_meme_jeton_renvoie_la_meme_facture_sans_second_numero` ; `test_un_paiement_repete_n_est_enregistre_qu_une_fois` ; `test_un_avoir_repete_n_est_emis_qu_une_fois` ; `test_un_envoi_repete_ne_part_qu_une_fois` ; `test_une_vente_a_la_caisse_repetee_ne_cree_qu_un_ticket_et_un_paiement` ; `test_recharger_la_caisse_apres_une_reponse_perdue_ne_cree_pas_un_second_ticket` ; `test_le_meme_jeton_avec_un_autre_montant_est_refuse` ; `test_une_vente_a_la_caisse_et_son_emission_n_utilisent_qu_un_jeton` ; `test_deux_demandes_simultanees_avec_le_meme_jeton_ne_font_qu_un_effet` (concurrence) ; `test_un_jeton_reutilise_pour_une_autre_action_ou_par_un_autre_compte_est_refuse` ; `test_une_action_echouee_n_inscrit_pas_son_jeton` ; `test_les_jetons_sont_isoles_par_magasin` ; `test_les_jetons_de_plus_de_30_jours_sont_effaces` ; `test_une_cloture_manuelle_repetee_ne_cree_qu_une_cloture` ; `test_valider_deux_fois_une_facture_d_achat_n_entre_le_stock_qu_une_fois`.

### 8.4 R4 — Répartition de la TVA par cumul [R4 = a]

Constat : `CalculateurLigne::repartirLaTva()` [b2bce46, `CalculateurLigne.php:144`] répartit déjà par cumul ; test `CalculateurLigneTest::test_la_tva_d_un_taux_se_calcule_sur_son_total_ht_et_se_repartit_entre_ses_lignes` (`:181`).

À faire : règle dans CLAUDE.md ; docblock qui la cite ; tests au centime : `CalculateurLigneTest::test_la_tva_d_un_taux_se_repartit_par_cumul_dans_l_ordre_des_lignes` ; `tests/Feature/Facturation/RepartitionTvaParCumulTest.php` : `test_une_facture_professionnelle_repartit_la_tva_de_chaque_taux_par_cumul_au_centime` (trois lignes, deux taux, montants en dur) ; `test_le_contenu_signe_et_le_pdf_portent_les_parts_de_tva_ainsi_reparties` ; `test_le_retour_d_une_ligne_ne_depasse_jamais_sa_part_de_tva`.

### 8.5 R5 — Caisse personnelle restée ouverte [R5 = a + note]

Constat [b2bce46] : bouton de clôture désactivé tant qu'un vendeur n'a pas fermé sa caisse (`ListClotureCaisses.php:100`, `:143`) ; la clôture de minuit ne ferme pas les caisses personnelles (`ClotureAutomatiqueCaisse.php:24-27`) ; fermeture toujours par le vendeur (migration `:14-16`).

Conception :
- La clôture du jour n'est **jamais bloquée** ; la fenêtre nomme les caisses qui seront fermées d'office.
- Après la clôture (manuelle ou automatique), transactions séparées : `FermerLesCaissesPersonnellesAction::handle(Magasin, CarbonImmutable $jusquAu, ModeFermetureCaisse::Automatique)` ferme chaque caisse personnelle qui compte des ventes ou paiements jusqu'à la borne ; relevé « Fermée automatiquement ». Un échec n'annule jamais la clôture ; reprise à minuit.
- **À minuit** [note R5] : `ClotureAutomatiqueCaisse` ferme aussi toute caisse personnelle restée ouverte pour les journées écoulées, même après une clôture manuelle.
- **Un responsable ferme pour le vendeur** : « Fermer la caisse de {vendeur} » (`cloture_caisse.gerer`), mode `responsable`, `fermee_par`, relevé « Fermée par {nom} ».
- `FermerSaCaisseAction` reçoit mode, auteur, borne ; verrou de la fiche du vendeur (`FOR NO KEY UPDATE` [`FermerSaCaisseAction.php:33-47`]) : rien n'est compté deux fois.
- `VerificateurCaissesPersonnelles` ne bloque plus rien (docblock réécrit).
- Tests (`tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeTest.php`) : `test_la_cloture_du_jour_n_est_jamais_bloquee_et_ferme_d_office_les_caisses_personnelles_ouvertes` ; `test_le_releve_ferme_d_office_porte_la_mention_fermee_automatiquement` ; `test_minuit_ferme_les_caisses_personnelles_restees_ouvertes_meme_apres_une_cloture_manuelle` ; `test_un_responsable_ferme_la_caisse_d_un_vendeur` ; `test_un_vendeur_ne_ferme_pas_la_caisse_d_un_autre` ; `test_une_operation_posterieure_a_la_borne_n_est_pas_comptee` ; `test_une_fermeture_du_vendeur_et_une_fermeture_d_office_simultanees_ne_comptent_rien_deux_fois` (concurrence) ; `test_un_echec_de_fermeture_d_office_laisse_la_cloture_du_jour_faite`.

### 8.6 Ordre complet des contrôles de `EmettreFactureAction` (lots A2, A6)

1. `ActionsUniques::executer` (jeton, si l'action est la plus externe).
2. Transaction : `RegistreFiscal::verrouiller(magasin)`.
3. `Magasin::verifierQuIlPeutVendre()` (porte de validation, lecture seule).
4. Facture `lockForUpdate` ; statut ; lignes.
5. `LecteurDesDroits::verifierSousVerrou($auteur, 'facture.emettre', $magasin)`.
6. `ContexteDeVente::refuserSiLeTypeDeClientAChange()`, `refuserSiLeRegimeAChange()` (régime lu sur la société) ; le contrôle d'alignement `verifierQuIlEstAligne()` [`EmettreFactureAction.php:129`] disparaît.
7. Identité légale complète (facture à un professionnel).
8. Journée clôturée (ticket, K4).
9. Selon Q2 (a) : exclusivité du poste du vendeur.
10. Produits suivis verrouillés par id ; `VerificateurDeStock` (autorisation relue, confirmation) [R1].
11. `ControleNatureOperation::verifier()` (VIES) [lot 19].
12. Numérotation.
13. Figer client et émetteur ; enregistrer ; `FactureEmise` (sortie de stock sur les produits déjà verrouillés) ; bon d'avoir ; encaissement ; contenu signé ; `enregistrerVente` ; audit `VenteSansStock` (tête de la chaîne du magasin verrouillée en dernier) ; inscription du jeton.

---

## 9. Procédures exceptionnelles de l'éditeur

### 9.1 Cadre commun

- Une action par procédure (`app/Domain/Tenancy/Actions/`) ; une commande `editeur:*` (`php artisan list editeur`) ; l'étape 13 appellera les mêmes actions.
- Chaque commande exige `--justification="…"` (au moins 20 caractères), `--piece="<référence>"` ; affiche un résumé ; fait retaper la valeur attendue (`--confirmer=<valeur>` sans terminal ; `--no-interaction` sans `--confirmer` → refus) ; une transaction, verrous des registres en tête ; audit (`origine = editeur`, justification, opérateur(s), utilisateur système, hôte, version du module fiscal) et JET quand elle touche la caisse ; ne réécrit jamais un document émis.
- **Opérateurs authentifiés** (`RegistreDesOperateurs`) : `EDITEUR_OPERATEURS` (JSON `{nom: empreinte Argon2id du secret personnel}`, hors dépôt) ; chaque opérateur saisit son nom et son secret dans un champ masqué (jamais en option de ligne de commande, pour ne pas le laisser dans l'historique). Procédures graves (révocation, libération d'un SIRET, correction d'un SIREN, annulation d'une cession) : deux opérateurs distincts, chacun authentifié, et seulement en terminal interactif. Limite assumée jusqu'à l'étape 13 : un secret partagé entre opérateurs ne se détecte pas.
- Test commun (`tests/Feature/Editeur/ProceduresTest.php`) : refus sans justification ; refus sans confirmation, `--no-interaction` compris ; refus d'un secret faux ou d'un opérateur deux fois ; aucune écriture partielle ; audit complet ; invariant du propriétaire ; magasin inconnu ou texte qui n'est ni un identifiant ni un uuid refusé (garanties de `DesignerMagasinPrincipalTest`).

| Procédure | Maintenant | Étape 13 |
|---|---|---|
| Validation et refus manuels (B4 avec C) | `editeur:societe:valider`, `editeur:societe:refuser`, `editeur:societe:pieces` | écran |
| Levée motivée d'un signal | `editeur:societe:lever-signal` | écran |
| Envoi d'un code de courrier | `editeur:courrier:envoyer` | écran |
| Réclamation et révocation | `editeur:reclamation:ouvrir`, `editeur:societe:revoquer` | écran |
| Libération d'un SIRET | `editeur:siret:liberer` | écran |
| Examen et correction d'un SIREN | `editeur:societe:examiner-siren`, `editeur:societe:corriger-siren` | écran |
| Régularisation d'une facture listée par l'examen | action du propriétaire (après Q6) | même |
| Changement légal de fin d'exercice | `editeur:societe:changer-fin-exercice` | écran |
| Annulation d'une cession faite par erreur | `editeur:magasin:annuler-cession` | écran |
| Récupération de compte, transfert forcé, statuts de société, suspension d'un magasin | — | écran |

`app:designer-magasin-principal` (`DesignerMagasinPrincipal.php`) est supprimée.

### 9.2 Validation et refus manuels

`editeur:societe:valider {societe}` : l'éditeur lit les pièces (`editeur:societe:pieces`), inscrit une ligne `manuelle` concluante pour B4 (types et références des pièces, jamais leur contenu), envoie s'il ne l'est pas déjà le code de courrier (C) ; la validation se fait d'elle-même à la saisie du code, si l'évaluation le permet ; les pièces sont effacées dès la décision. `editeur:societe:refuser {societe}` : `refusee_le`, lecture seule définitive, audit, notification, pièces effacées.

### 9.3 Réclamation, révocation d'une société usurpatrice, libération d'un SIRET [S7]

- **Réclamation** : le vrai titulaire s'inscrit normalement (3.1). Dès qu'il a une preuve de qualité concluante alors que le SIREN est validé ailleurs, l'écran le lui dit (7.6) et l'éditeur est alerté (`PILIQI_EDITEUR_EMAIL`). `editeur:reclamation:ouvrir {societe}` pose `reclamation_ouverte_le` et envoie un code de motif `revocation` à l'adresse officielle (ou LRE) ; le réclamant le saisit.
- **`editeur:societe:revoquer {usurpatrice} --au-profit-de={reclamante}`** (deux opérateurs ; exige une réclamation ouverte, le code saisi et la preuve de qualité du réclamant) : usurpatrice en `revoquee` (lecture seule totale, documents consultables par ses comptes, jamais modifiés [S7 option a]) ; SIREN libéré (l'index exclut les révoquées) ; SIRET libérés (`SiretsDesMagasins::liberer()`) ; périodes closes (`revocation_societe`) ; JET 270 `statut_societe` dans chaque magasin ; audit dans les deux sociétés ; puis réévaluation de la société réclamante (codes de magasins à changer d'abord s'ils reprennent un code de l'usurpatrice, 5.6). Déroge, par cette seule procédure, à « son SIRET ne sert jamais à un autre magasin ».
- **`editeur:siret:liberer {siret}`** (deux opérateurs) : SIRET saisi par erreur par un magasin d'une société validée et appartenant à un tiers (avis de situation ou Kbis du titulaire, code `liberation_siret` reçu à l'adresse officielle du titulaire) ; ligne libérée ; le magasin fautif reçoit son bon SIRET (`ChangerSiretAction`) ou est fermé ; ses documents gardent l'ancien SIRET.
- Tests (`RevoquerSocieteTest`, `LibererSiretTest`) : `test_le_reclamant_d_un_siren_valide_s_inscrit_et_n_apprend_le_conflit_qu_apres_sa_preuve_de_qualite` ; `test_la_revocation_exige_une_reclamation_ouverte_le_code_et_la_qualite_du_reclamant` ; `test_la_revocation_libere_le_siren_et_les_siret_sans_toucher_aux_documents` ; `test_le_vrai_titulaire_est_valide_apres_la_revocation` ; `test_les_comptes_de_la_societe_revoquee_consultent_mais_ne_modifient_plus_rien` ; `test_apres_revocation_le_reclamant_ne_reprend_pas_un_code_de_l_usurpateur` ; `test_un_siret_libere_sert_au_magasin_du_titulaire` ; `test_deux_operateurs_authentifies_sont_exiges`.

### 9.4 SIREN erroné ou usurpé découvert après le verrou [S11]

Principes : pas de correction systématique ni de refacturation ; aucun document émis modifié ni supprimé ; régularisation seulement si la loi l'exige, sans mouvement de stock artificiel ni double paiement.

1. **`editeur:societe:examiner-siren {societe}`** : justificatifs de l'entité correcte, preuve de qualité, code `examen_siren` reçu à son adresse officielle ; liste, magasin par magasin, des documents concernés (type, client, montants, paiements, SIREN signé) ; décision motivée : usurpation → 9.3 ; erreur → 2 ; rien à régulariser → clôture.
2. **`editeur:societe:corriger-siren {societe}`** (deux opérateurs, après un examen concluant à une erreur) : nouveau SIREN actif et non validé ailleurs ; nouveau SIRET de chaque magasin, actif et rattaché ; `SiretsDesMagasins::remplacer()` ; JET 270 `siren` (et `numero_tva` ; si un n° FR ne correspond plus, le nouveau est exigé, sauf confirmation « assujetti unique ») et `siret` dans chaque magasin ; audit `SirenCorrige` ; nouvelle attestation par magasin (étape 3) ; (étape 2) connexion et adresses de réception révoquées, à refaire. Les documents émis gardent l'ancien SIREN signé.
3. **Régularisation** (`RegulariserUneFactureAction`, propriétaire, factures désignées par l'examen ; **construite après la réponse Q6**) : avoir de régularisation de toute la facture (`regularisation = true`, référence de l'origine, sans retour en stock : `RestaurerStockApresEmissionAvoir` l'ignore) puis facture rectificative (`facture_rectifiee_id`, mêmes lignes, `livraison_date` de l'origine ou sa date d'émission, mention « Facture rectificative de la facture n° X du JJ/MM/AAAA », sans sortie de stock : `DecrementerStockApresEmissionFacture` l'ignore) ; le crédit de l'avoir s'impute d'abord sur le reste dû de l'origine, puis sur la rectificative, avant toute imputation automatique. **Cette imputation n'est jamais un encaissement** : aucun statut fr:212 ni paiement B2C, la rectificative reprend les encaissements déjà déclarés de l'origine, l'e-reporting l'ignore. Émetteur de l'avoir : selon Q6. Les tickets restent tels quels (source web : BOI-TVA-DECLA-30-20-20-20).
- Tests (`SirenErroneTest`) : `test_l_examen_liste_les_documents_concernes_sans_en_modifier_aucun` ; `test_la_correction_exige_un_examen_concluant_a_une_erreur_et_deux_operateurs` ; `test_la_correction_journalise_dans_chaque_magasin_et_garde_les_anciens_siret_reserves` ; `test_aucun_document_emis_ne_change` ; `test_un_siren_deja_valide_par_une_autre_societe_est_refuse` ; après Q6 : `test_la_regularisation_ne_fait_aucun_mouvement_de_stock` ; `test_la_regularisation_ne_fait_jamais_payer_deux_fois` ; `test_une_regularisation_ne_declare_aucun_encaissement_supplementaire` ; `test_seules_les_factures_listees_par_l_examen_se_regularisent` ; `test_l_avoir_de_regularisation_porte_l_emetteur_choisi_par_l_editeur`.

### 9.5 Changement légal de fin d'exercice [S14]

`editeur:societe:changer-fin-exercice {societe} --mois= --fin-exercice-en-cours= --decision-du= --piece= --duree-validee-par=` :
- `--fin-exercice-en-cours` est la date de fin de l'exercice en cours **tirée du procès-verbal** : inchangée (effet au prochain exercice, l'exercice suivant étant transitoire) ou modifiée (changement qui concerne l'exercice encore ouvert, permis par la note S14 quand la loi le permet).
- Refus si : nouveau mois égal à l'ancien ; décision postérieure à aujourd'hui ; décision postérieure à la fin **d'origine** de l'exercice en cours (supposé : un exercice ne se modifie qu'avant sa clôture, à confirmer) ; fin tirée du procès-verbal antérieure à la dernière clôture d'exercice produite ou à une archive (jamais un exercice clos modifié ni une archive supprimée) ; date de fin incompatible avec le nouveau mois (la fin d'un exercice transitoire peut être une fin de mois quelconque, mais l'exercice suivant finit au nouveau mois).
- Une fin déjà passée mais postérieure à la dernière clôture d'exercice est **rattrapée** à l'étape 3 (clôture d'exercice produite à cette date, dans l'ordre, sans rien modifier d'existant), comme les journées manquées.
- Durée de l'exercice transitoire : PILIQI ne tranche pas la règle légale (art. 37 CGI pour l'IS, durée usuelle, supposé) ; il affiche la durée obtenue et exige `--duree-validee-par` (expert-comptable de la société), inscrit dans `changements_fin_exercice`.
- Écritures : `societes.mois_fin_exercice`, ligne `changements_fin_exercice`, JET 270 dans chaque magasin réel, audit `FinExerciceChangee`.
- Tests (`ChangerFinExerciceTest`) : `test_le_changement_legal_s_inscrit_dans_chaque_magasin_avec_sa_piece` ; `test_l_effet_au_prochain_exercice_garde_la_fin_de_l_exercice_en_cours` ; `test_un_changement_de_l_exercice_en_cours_prend_la_date_du_proces_verbal` ; `test_une_fin_avant_la_derniere_cloture_d_exercice_est_refusee` ; `test_une_decision_posterieure_a_la_fin_d_origine_est_refusee` ; `test_la_duree_doit_etre_validee_par_l_expert_comptable` ; `test_hors_procedure_le_mois_reste_verrouille_apres_la_premiere_vente`.

### 9.6 Récupération de compte et transfert forcé (étape 13)

Justificatif, code `recuperation` reçu à l'adresse officielle, preuve de qualité du demandeur (B1, B2, B3 ou B4 avec C), notification immédiate de l'ancien propriétaire et des administrateurs, délai d'opposition de 7 jours.

### 9.7 Annulation d'une cession faite par erreur

`editeur:magasin:annuler-cession {magasin}` (deux opérateurs ; seulement si aucun document, paiement ni mouvement n'a suivi la cession) : mouvements `EntreeAnnulationCession` au même coût que la sortie, magasin rouvert (règles de 3.3), JET 270, audit `CessionAnnulee`. Test `test_une_cession_annulee_rentre_le_stock_au_meme_cout_et_seulement_sans_operation_posterieure`.

### 9.8 Journal d'audit, traces et conservation [S13]

- En ajout seul, chaîné par magasin et par société et par catégorie, protégé (déclencheur ; à l'étape 15, rôle de l'application privé de UPDATE et DELETE hors expiration) ; consultable par le propriétaire seul ; vérifié par `app:verifier-journal-audit {societe?}` (chaînes, têtes, clés précédentes) ; têtes ancrées dans les archives signées (étape 3).
- **Durées** (`CategorieAudit::dureeDeConservation()`), selon la réponse Q4 ; recommandé :

| Donnée | Durée | Base |
|---|---|---|
| Journal d'audit, catégorie `operations` (factures, paiements, réglages, procédures, caisse) | 10 ans | règle de l'éditeur « Tout se conserve 10 ans » ; L123-22 C. com. (pièces comptables, source web) |
| Journal d'audit, catégorie `acces` (comptes, droits, mots de passe, rattachements, transferts) | 5 ans | prescription de droit commun, art. 2224 C. civ. (supposé) ; les droits restent de toute façon 10 ans au JET 130 de la caisse |
| `verifications_societe`, `codes_confirmation`, journal d'audit `verification` | 5 ans après la fin de la relation (résiliation ou révocation) | supposé ; `expire_le` posé à la fin de la relation |
| `traces_audit`, `traces_evenements` (IP, navigateur) | 1 an | [S13] ; CNIL délibération 2021-122 : 6 mois à 1 an (source web) |
| Copies de pièces (B4) | dès la décision, au plus 30 jours | minimisation |
| `actions_traitees` | 30 jours | technique |
| `demandes_inscription` | 48 h après expiration | technique |

- Expiration : `app:purger-journal-audit` efface, chaîne par chaîne et dans l'ordre des séquences, les entrées dont `expire_le` est passé, et enregistre le point de reprise dans la tête (la vérification repart de là) ; le déclencheur refuse tout autre effacement. Sociétés jamais validées et leurs données de démonstration : traitées à l'étape 9 (RGPD), après décision sur le statut fiscal des données de démonstration.
- Tests (`JournalAuditTest`, `JournalAuditResourceTest`) : `test_la_base_refuse_de_modifier_ou_d_effacer_une_entree_non_expiree` ; `test_les_entrees_sont_chainees_par_magasin_par_societe_et_par_categorie` ; `test_aucun_secret_ni_code_n_entre_jamais_au_journal` ; `test_un_echec_d_ecriture_du_journal_annule_l_action` ; `test_l_ip_et_le_navigateur_vont_dans_la_trace_separee` ; `test_aucun_contenu_signe_ne_contient_d_adresse_ip_ni_de_navigateur` ; `test_l_effacement_des_entrees_expirees_garde_la_chaine_verifiable` ; `test_la_suppression_des_dernieres_entrees_est_detectee` ; `test_la_rotation_de_la_cle_garde_les_anciennes_entrees_verifiables` ; `test_seul_le_proprietaire_consulte_le_journal_et_les_traces` ; `test_les_filtres_ne_proposent_que_la_societe` ; `test_les_traces_de_plus_d_un_an_sont_effacees_sans_toucher_aux_entrees`.

### 9.9 Ce que la société fait elle-même

Fermer et rouvrir un magasin [S8], changer un SIRET, céder un établissement [S10], rattacher un compte retiré ou créer sa société avec lui [S1], transmettre la propriété [V1-8], corriger le SIREN tant que le verrou ne s'applique pas [S11]. Les commandes de la v1 `editeur:magasin:rouvrir`, `editeur:magasin:changer-siret`, `editeur:etablissement:transferer`, `editeur:compte:migrer` n'existent pas.

---

## 10. Abonnements (étape 12)

- Souscrits par la société pour ses magasins réels ; facture de l'éditeur adressée à la société.
- Fermeture et réouverture [S8 note] : l'abonnement d'un magasin s'arrête à sa fermeture, reprend à sa réouverture. Préparé sans construire la facturation : `periodes_activite` donne les périodes facturables ; événements `SocieteValidee`, `MagasinOuvert`, `MagasinFerme`, `MagasinRouvert`, émis après les transactions, sans écouteur avant l'étape 12 (tests `Event::fake`).
- Démonstration et société non validée : jamais facturées. Une société résiliée garde son SIREN réservé ; son retour passe par la réactivation de la même société (étape 12 ou 13), jamais par une nouvelle inscription.
- Limites d'un plan : `CreerEtablissementAction`, `RouvrirMagasinAction`, `CreerMembreAction`, `CreerAdministrateurAction`.

---

## 11. Ce qui disparaît

| Élément | Où [b2bce46] | Remplacé par |
|---|---|---|
| Réglages communs par magasin principal | `ReglagesCommunsDeLaSociete.php`, `AligneLesReglagesCommuns.php`, `ReglagesCommunsDifferentsException.php`, `DesignerMagasinPrincipal.php`, `README.md:60` | colonnes de `societes` |
| Refus d'émettre d'un magasin non aligné | `EmettreFactureAction.php:129` ; `Caisse.php:397` (`reglagesNonAlignes()`) et sa vue | `peutVendre()` |
| `designe_principal`, colonnes de société sur `magasins`, hooks `saving` et `siren` | migration `magasins`, `Magasin.php` | `societes`, `est_etablissement_principal` |
| `deleted_at` de magasins et users ; `Magasin::withTrashed()`, `User::withTrashed()` | migrations ; `Magasin.php:74, :240, :259` ; `User.php:31` (9 usages) | statuts `ferme`, `retire` |
| Pivot `magasin_user`, son index et son statut | migration `2026_10_02_100002` | `affectations` |
| Spatie | `composer.json`, `config/permission.php`, migration de Spatie, `MagasinTeamResolver.php`, `SeedMagasinRolesAction.php`, `TenantContext.php:52-64`, `HasRoles` | tables propres, `ControleDesDroits` |
| `RoleMagasin::Proprietaire`, `Administrateur` | `RoleMagasin.php:22-23` | propriétaire implicite ; `Responsable` |
| Inscription d'un magasin seul | `CreerMagasinAction.php`, `RegisterMagasin.php` | `CreerSocieteAction`, `CreerEtablissementAction`, `InscriptionSociete` |
| Identité et fiscalité du magasin | `IdentiteMagasin.php`, `ParametresFiscaux.php` | `IdentiteSociete`, `ParametresFiscauxSociete`, `MagasinResource`, `CoordonneesMagasin` |
| Équipe du magasin | `MembreEquipeResource` (+3 pages), `CreerMembreMagasinAction`, `MettreAJourMembreMagasinAction`, `RetirerMembreMagasinAction`, `ReinitialiserMotDePasseMembreAction`, `EmailLibrePourUnNouveauMembre`, `MembreEquipePolicy`, `EQUIPE_*`, `StatutMembreMagasin`, `MagasinUser` | `MembreResource`, actions de 3.4, `EmailLibrePourUnNouveauCompte`, `MembrePolicy`, `Affectation` |
| `estProprietaireDe()`, `Magasin::proprietaires()` | `User.php:98-101`, `Magasin.php:517-520` | `User::estProprietaire()`, `Societe::proprietaire()` |
| `checkPermissionTo` | `ControleDesDroits.php:59`, `ClotureCaissePersonnellePolicy.php:74-75` | `autorisationsDans()` |
| Un seul magasin par session | `MagasinOuvertParLaSession.php` | `MagasinsOuvertsParLaSession` |
| Clôture bloquée par une caisse personnelle | `ListClotureCaisses.php:100` | fermeture d'office [R5] |
| IP et navigateur dans le contenu signé des JET | `RegistreFiscal.php:381-382` | `traces_evenements` |
| `IdentiteDuMagasinIncompleteException` | `Facturation/Exceptions/` | `IdentiteDeLaSocieteIncompleteException` |
| Fabrique « même société » | `MagasinFactory::deLaSocieteDe` (15 usages, 5 fichiers) | `SocieteFactory`, `MagasinFactory::for(Societe)` |
| Identifiants invalides ou réels des tests | `12345678900012` (36), `732829320` (100) | valeurs fictives valides des fabriques |
| Commentaires faux | `User.php:69-75`, `Magasin.php:36-70`, `TenantContext.php:85-89`, `AppPanelProvider.php:29-43`, `PermissionRegistry.php:13-19`, `RoleMagasin.php:8-19`, `ClotureAutomatiqueCaisse.php:24-30`, `DatabaseSeeder.php:7-11`, migrations `paniers_caisse` (« voir RetirerMembreMagasinAction »), `services`, `clotures_caisse_personnelles` | réécrits |

Non touchés : `mandats_pdp`, `MandatPdp`, `CreerMandatPdpAction`, `RevokerMandatPdpAction`, `PdpGatewayInterface` (étape 2).

---

## 12. Textes exacts de CLAUDE.md et du README

Écrits au lot A0, sauf les passages marqués **[après Qn]**, écrits quand l'éditeur a répondu (texte de chaque option en section 15). Les puces non citées ne changent pas ; les règles confirmées [V2] sont déjà inscrites, seule la précision des devis s'ajoute.

**Protocole, règle 2** — remplacer par :
> 2. À chaque modification, vérifier l'isolation des sociétés et des magasins : tout modèle de données d'un magasin utilise `BelongsToMagasin`, tout modèle de données propre à une société `BelongsToSociete` ; toute requête qui contourne `MagasinScope` ou `SocieteScope` (`withoutGlobalScopes`, `DB::table`, requête brute, job, commande planifiée, route hors panneau) filtre explicitement par magasin ou par société ; toute règle `unique`/`exists` est limitée au magasin ou à la société, sauf les unicités voulues dans tout le logiciel (SIREN d'une société validée, SIRET réservé, adresse e-mail), qu'aucun écran ni aucun message ne révèle à un tiers.

**Produit, puce 1** — remplacer par :
> - Logiciel indépendant (SaaS) vendu à des entreprises — sociétés ou entrepreneurs individuels, un SIREN chacune, appelées « sociétés » dans le logiciel — pour leurs magasins (établissements, un SIRET chacun) : son créateur en est l'éditeur, il n'a ni société ni magasin.

**Produit, puce 2** — remplacer par :
> - Chaque société est strictement isolée des autres, et dans une société chaque magasin est strictement isolé (`BelongsToMagasin` + `MagasinScope`) : aucun client, produit, fournisseur, service, document, stock, caisse ni réglage local n'est jamais partagé ni mélangé entre magasins, ni copié d'une société à une autre. Les données propres à une société (journal d'audit, vérifications, et à l'étape 2 sa connexion et ses factures reçues SUPER PDP) utilisent `BelongsToSociete` + `SocieteScope`. L'identité légale et les réglages fiscaux communs n'existent qu'une fois, sur la société. Seules lectures hors du magasin ou de la société courants, explicites, filtrées, limitées aux champs nécessaires et testées : la liste et le tableau de bord des magasins et des membres de l'espace d'administration, limités à la portée de la personne ; l'existence d'une première vente de la société ; les destinataires des notifications ; les SIRET, adresses de réception SUPER PDP, codes de routage, adresses et références d'acheteur de ses magasins (pour attribuer les factures reçues) ; l'examen exceptionnel d'un SIREN par l'éditeur ; et, pour un même SIREN, l'existence d'une autre société validée ou ayant prouvé sa qualité de représentant (sans jamais son identité) et les codes de magasins d'une société révoquée (pour ne jamais redonner un numéro déjà émis sous ce SIREN).

**Comptes et équipe** — remplacer la puce par :
> - Comptes et équipe : un compte n'appartient qu'à une seule société ; une fois retiré par elle, la personne peut, avec son accord et avec le même compte, rejoindre une autre société ou créer la sienne, jamais avant. Une adresse e-mail déjà utilisée ne sert jamais à un nouveau compte. Une adresse e-mail s'enregistre et se compare en minuscules, sans espaces autour : A@x.fr et a@x.fr sont la même adresse. Chacun change lui-même son nom, son adresse de connexion et son mot de passe dans son profil, en donnant son mot de passe actuel ; une nouvelle adresse ne prend effet qu'après confirmation par un lien envoyé à cette adresse, et l'ancienne est prévenue ; personne ne change le nom ni l'adresse d'un autre compte. Une société s'inscrit par un lien envoyé à l'adresse de son propriétaire, valable 24 heures. Elle a un seul propriétaire, qui a tous les droits dans la société et dans chacun de ses magasins, et des administrateurs : le propriétaire crée chacun directement en choisissant son accès à tous les magasins, présents et futurs, ou à une sélection de magasins, et en cochant ses autorisations. Le propriétaire, ou un administrateur autorisé à gérer les membres de ses magasins, crée directement le compte des responsables, vendeurs, comptables et observateurs avec un mot de passe provisoire remis en main propre, valable 72 heures, que la personne change à sa première connexion ; ensuite, un accès perdu se renouvelle par un lien envoyé à la personne, et personne d'autre ne connaît son mot de passe. Il les affecte à un ou plusieurs magasins et coche leurs autorisations magasin par magasin, jamais au-delà des siennes : le rôle n'est qu'un modèle. Chaque vente enregistre le magasin où elle est faite, avec son SIRET, et la personne qui l'a faite, affectée et autorisée dans ce magasin à ce moment. Seul le propriétaire gère les administrateurs ; personne ne modifie ses propres droits ni ceux du propriétaire. Connecter la plateforme agréée, prouver la qualité de représentant de la société, céder un magasin, consulter le journal d'audit et transmettre la propriété à un administrateur dont la qualité de représentant est prouvée restent réservés au propriétaire ; signer les attestations aussi, une fois sa propre qualité prouvée.

**[après Q2]** : la phrase sur la prise de poste (section 15).

**Magasin suspendu ou fermé** — remplacer la puce par :
> - Société ou magasin suspendu, fermé, résilié ou révoqué : lecture seule (consultation et téléchargements), plus aucune vente ni aucun e-mail aux clients ; clôtures, statuts et déclarations continuent jusqu'au bout. Une société suspendue ou résiliée met tous ses magasins en lecture seule ; son espace d'administration reste consultable et son équipe gérable. La société ferme elle-même un magasin et le rouvre elle-même (propriétaire ou administrateur autorisé, mot de passe redemandé) ; seul le propriétaire cède un magasin, qui ne se rouvre pas ; une réouverture ne lève jamais une suspension décidée par l'éditeur. Une société et un magasin ne se suppriment jamais ; un SIRET réservé par un magasin d'une société validée ne sert jamais à un autre magasin, sauf libération par la procédure de l'éditeur d'un SIRET saisi par erreur ou usurpé.

**Société à plusieurs magasins (même SIREN)** — remplacer la puce par :
> - Société et établissements : chaque société (un SIREN, un seul compte PILIQI) s'inscrit avec son propriétaire et son premier magasin (son SIRET) ; ses autres magasins ne se créent que dans l'espace d'administration de la société, par le propriétaire ou un administrateur autorisé ayant accès à tous les magasins, avec le SIREN de la société, que personne ne remplace : on ne saisit que le NIC, et le SIRET, à clé de contrôle valide, actif et rattaché à ce SIREN au répertoire Sirene, n'est jamais celui d'un autre magasin ; un établissement fermé ou inconnu au répertoire est refusé. Aucun magasin ne rejoint une société par la saisie d'un SIREN. La société porte l'identité légale (raison sociale, forme juridique — SARL, EURL, SAS, SASU, SA, SCA, SE, SNC, SCS, entrepreneur individuel, micro-entrepreneur compris, ou autre —, capital social, SIREN, immatriculation, adresse du siège ou, pour un entrepreneur individuel, de l'entreprise, n° de TVA) et les réglages fiscaux communs (régime de TVA, option pour les débits, mois de fin d'exercice) ; chaque changement d'un réglage qui touche la conformité s'inscrit au journal des événements de chacun de ses magasins. Chaque magasin garde sa caisse, ses stocks, ses ventes, ses factures, ses clients, ses produits, ses fournisseurs, son équipe et ses paramètres locaux (coordonnées, références d'acheteur, barème, relances, échéance et mentions des factures) ; aucun n'a de privilège sur les autres, un établissement principal peut seulement être indiqué. Chaque magasin a un code court, unique dans la société, définitif dès son premier document, inséré dans tous ses numéros (FAC-LYON1-2026-000001). Un retour ou un paiement se fait dans le magasin qui a vendu ; aucun transfert de marchandises entre magasins. Ensuite, une erreur ou une usurpation de SIREN ne se traite que par la procédure exceptionnelle de l'éditeur (justificatifs, traçabilité, examen des documents concernés), qui ne modifie ni ne supprime aucun document émis : un avoir ou une facture rectificative n'est émis que si la situation juridique et fiscale l'exige, sans mouvement de stock artificiel ni encaissement ou paiement compté deux fois. Un changement de SIRET (déménagement) se fait par la société, contrôlé au répertoire Sirene, prend effet le jour même, garde le même magasin et son historique, et l'ancien SIRET reste réservé ; un déménagement qui change le territoire de TVA passe par un nouveau magasin. Une activité reprise par une autre société s'ouvre dans le compte de celle-ci, qui saisit ou importe son catalogue et fait un inventaire d'ouverture ; le magasin cédé est fermé pour cession, son stock sort au coût moyen, journalisé, et cette valeur hors taxe est remise au propriétaire pour la rapprocher du prix de cession ; rien d'autre ne suit, et ni les factures, ni les paiements, ni les archives de la société d'origine ne bougent.

**[après Q3]** : la phrase sur le verrou du SIREN (section 15).

**Nouvelle puce, après « Société et établissements »** :
> - Vérification de la société : avant toute opération commerciale réelle (ticket, facture, avoir, paiement, devis numéroté, PDF d'un document, e-mail à un client, transmission), la société est validée : SIREN, SIRET et adresse officielle contrôlés au répertoire Sirene de l'INSEE (refus si l'entreprise est cessée, l'établissement fermé ou le numéro inconnu), puis qualité de représentant légal ou de mandataire du propriétaire prouvée électroniquement (identité vérifiée à distance par un prestataire certifié, liée à son compte et rapprochée des dirigeants du registre national des entreprises, y compris du représentant d'un dirigeant personne morale ; accord électronique d'un dirigeant ainsi vérifié ; ou, dès l'étape 2, connexion SUPER PDP vérifiée au même SIREN), ou, à défaut, par l'éditeur sur pièces (Kbis ou extrait RNE, pièce d'identité, mandat) déposées dans l'application, effacées dès sa décision et toujours complétées par le courrier recommandé. Un courrier recommandé avec accusé de réception à l'adresse officielle lue au répertoire (ou au registre national quand le répertoire la masque), ou une lettre recommandée électronique qualifiée remise à un dirigeant inscrit et identifié, porte un code unique, temporaire et à usage unique, à saisir dans le compte ; il est exigé pour la voie manuelle et quand un risque est détecté, chaque risque n'étant levé que par la vérification qu'il exige ; il prouve le contrôle de l'adresse, jamais seul la qualité de représentant ; il ne part qu'à la demande d'un propriétaire dont l'identité est vérifiée, ou de l'éditeur. À chaque validation, une lettre simple informe le siège. Chaque nouveau magasin, changement de SIRET ou réouverture est contrôlé au répertoire Sirene avant toute vente ; une revérification négative après la validation alerte le propriétaire et l'éditeur sans rien bloquer. Un SIREN et ses SIRET ne sont réservés qu'à la société qui les fait valider ; aucune autre inscription n'est refusée pour autant, et aucun écran ne révèle qu'un SIREN est déjà client avant une preuve de qualité ; le vrai titulaire évincé par un usurpateur obtient, par la procédure de l'éditeur, la révocation de l'usurpateur. Tant qu'elle n'est pas validée, la société se configure (identité, magasins, équipe, catalogue, achats) et s'essaie dans un magasin de démonstration : identité fictive, documents marqués « Démonstration — sans valeur », numéros et registre à lui, entrée et sortie inscrites au journal des événements, aucun e-mail ni aucune transmission, jamais facturé ; il se ferme à la validation et rien n'en passe aux magasins réels. La même procédure postale sert à récupérer un compte et à libérer un SIREN ou un SIRET usurpé. Chaque fournisseur externe (INSEE, INPI, vérification d'identité, envoi recommandé, SUPER PDP) est derrière une interface, avec une implémentation factice pour les tests et la démonstration ; ses clés ne sont jamais dans le dépôt.

**Documents de vente** — trois remplacements :
- « Une facture à un professionnel exige l'identité légale complète du magasin, immatriculation (RCS, RM ou mention de dispense) comprise ; les tickets restent possibles. » devient :
  > Une facture à un professionnel exige l'identité légale complète de la société (raison sociale, forme juridique, SIREN, immatriculation — RCS, RM ou mention de dispense —, adresse du siège ou, pour un entrepreneur individuel, de l'entreprise, n° de TVA hors franchise, et capital social pour toute société commerciale : SARL, EURL, SAS, SASU, SA, SCA, SE, SNC, SCS et leurs variantes), ainsi que le SIRET et l'adresse du magasin ; les tickets restent possibles.
- « Le capital social est imprimé quand il est renseigné. » devient :
  > Le capital social est imprimé quand il est renseigné, avec la mention « à capital variable » le cas échéant ; la dénomination d'un entrepreneur individuel, sans capital, porte la mention « EI », ajoutée automatiquement quand elle manque ; l'adresse du siège n'est pas répétée quand elle est celle du magasin ; identité et adresses sont figées à l'émission.
- « un devis envoyé dont la validité est dépassée expire automatiquement ; » devient [V2] :
  > un devis brouillon dont la validité est déjà dépassée ne s'envoie pas ; un devis envoyé dont la validité est dépassée est traité comme expiré dès son échéance et expire automatiquement ; un devis repassé en brouillon perd sa date d'émission, et son PDF l'indique ;

**Prix et TVA des ventes** — deux changements :
- « TVA de chaque taux = total HT du taux × taux, arrondie une fois et répartie sur ses lignes. » devient [R4] :
  > TVA de chaque taux = total HT du taux × taux, arrondie une fois et répartie sur ses lignes par cumul, dans leur ordre : chaque ligne reçoit la TVA arrondie du cumul des HT jusqu'à elle, moins celle des lignes précédentes.
- Ajouter après « une ligne de produit prend le taux de sa fiche. » [R2] :
  > Accorder une remise et fixer le prix d'un service (son prix de référence au catalogue comme celui d'une ligne) exigent l'autorisation « accorder des remises et fixer le prix d'un service », cochée par défaut pour le propriétaire, les administrateurs et les responsables, pas pour les vendeurs ; changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ; sans elle, une ligne de service prend le prix de référence de sa fiche, et un service sans prix de référence ne se vend pas.
  **[après Q1]** : la phrase sur le prix d'une ligne de produit.

**Paiements et avoirs** — ajouter à la fin [S11] :
> Seule exception à cet ordre d'imputation : le crédit d'un avoir de régularisation (procédure exceptionnelle de l'éditeur) s'impute d'abord sur la facture qu'il régularise, puis sur sa facture rectificative ; cette imputation n'est jamais déclarée comme un encaissement.

**Nouvelle puce, après « Paiements et avoirs »** [R3] :
> - Demandes répétées : chaque émission, vente à la caisse, émission de devis, paiement, contre-passation, avoir, envoi, fermeture de caisse, clôture du jour et validation d'achat porte un jeton unique, lié au contenu de la demande (celui de la caisse est celui de son panier) ; une demande répétée après une réponse perdue renvoie le résultat déjà enregistré, sans doublon ; le même jeton avec une demande différente est refusé ; les autres gestes sont protégés par leur état ou une contrainte d'unicité.

**Achats et stock** — ajouter à la fin [R1] :
> Une vente au-delà du stock disponible est refusée, sauf pour un membre autorisé à « vendre sans stock » : il voit un avertissement avant de valider, qui se renouvelle si le stock a encore baissé, une alerte de stock part, et la quantité négative reste visible jusqu'à sa régularisation par un achat, une réception ou un inventaire ; ces ventes sont journalisées, et des ventes simultanées respectent ces règles sans jamais rendre le stock incohérent.

**Caisse et clôtures** — six changements :
- « Le magasin clôture sa journée par un bouton, selon les autorisations ; » devient [R5] :
  > Le magasin clôture sa journée par un bouton, selon les autorisations, sans jamais être bloqué par une caisse personnelle restée ouverte : la clôture du jour la ferme d'office, avec la mention « fermée automatiquement » sur son relevé, et minuit ferme toute caisse personnelle qui ne l'a pas été ; un responsable peut aussi la fermer pour le vendeur ;
- « Un ticket Z est produit chaque jour du calendrier à partir de la première vente, même à zéro, » devient [S9] :
  > Un ticket Z est produit chaque jour du calendrier, dimanches et jours de fermeture habituelle compris, à partir de la première vente du magasin, même à zéro, tant que le magasin n'est pas fermé,
- « Le mois de fin d'exercice est réglable (décembre par défaut) et verrouillé après la première vente. » devient [S14] :
  > Le mois de fin d'exercice, réglage de la société (décembre par défaut), est verrouillé dès la première vente de l'un de ses magasins ; seul un changement légal de la date de clôture, sur procès-verbal, le modifie ensuite, par la procédure de l'éditeur, journalisée dans chaque magasin, avec la date de fin de l'exercice en cours fixée par le procès-verbal et une durée validée par l'expert-comptable de la société, sans jamais toucher un exercice clos ni une archive, ni repousser une clôture obligatoire ; une clôture d'exercice dont la date est déjà passée est rattrapée.
- « À la résiliation, clôture et archive signées finales, automatiques. » devient [S8, S9] :
  > À la fermeture d'un magasin, ses journées en attente sont clôturées et plus aucun ticket Z n'est produit tant qu'il est fermé ; à sa réouverture, sa caisse reprend au premier jour non clôturé, avec le même registre, les mêmes séries et les mêmes grands totaux. À la résiliation de la société, clôtures journalière, mensuelle et de l'exercice et archive signées finales, automatiques.
  **[après Q7]** : la phrase sur les clôtures mensuelle et de l'exercice et l'archive à la fermeture d'un magasin.
- « Tout se conserve 10 ans, sans aucune purge, même après la résiliation. » devient [S13] :
  > Les documents, registres, journaux des événements et le journal d'audit des opérations se conservent 10 ans, sans aucune purge, même après la résiliation ; seules s'effacent, à leur échéance : les traces techniques (adresse IP et navigateur, après 1 an), les copies de pièces de la vérification (dès la décision), les jetons des demandes répétées (après 30 jours) et les demandes d'inscription non confirmées.
  **[après Q4]** : la durée des entrées d'audit de gestion des accès et de vérification.
- « Une attestation de l'éditeur par magasin, même pour plusieurs magasins d'un même SIREN ; le magasin signe le volet 2 dans l'application ou à la main. » devient :
  > Une attestation de l'éditeur par magasin, même pour plusieurs magasins d'une même société ; le volet 2 de chacune est signé par le représentant légal de la société ou son mandataire, dans l'application par le propriétaire dont la qualité a été prouvée à son nom, ou à la main. Le magasin de démonstration est un mode d'entraînement distinct (registre et séries propres, documents marqués, exclu des totaux réels, entrée et sortie inscrites au journal des événements), décrit dans la notice et l'attestation.

**Facture électronique (SUPER PDP)** — remplacer « la connexion est réservée au propriétaire ; refuser ou contester une facture reçue, au propriétaire et à l'administrateur. » par [S2] :
> chaque société a une seule connexion, faite par son propriétaire, à l'entreprise SUPER PDP de son SIREN, et chaque magasin son adresse de réception (SIREN_SIRET) ; une facture reçue est attribuée automatiquement au magasin que désignent avec certitude son SIRET destinataire, son adresse électronique de facturation ou le code de routage de l'établissement ; l'adresse physique et les références du magasin ne servent qu'à proposer une correspondance ; seule une facture dont le destinataire exact est incertain ou dont les informations se contredisent entre dans la liste « À attribuer » de la société, dont le propriétaire et les administrateurs autorisés sont alertés ; chaque magasin ne voit que les factures qui lui sont attribuées, une facture n'est jamais importée dans deux magasins, et chaque attribution ou correction est journalisée ; refuser ou contester une facture reçue : au propriétaire et aux administrateurs autorisés.

et remplacer « l'expert-comptable du magasin » par « l'expert-comptable de la société ».

**E-mails** — remplacer « les alertes de stock bas vont au propriétaire, à l'administrateur et à tout membre autorisé à gérer le stock » par [V1-3, R1] :
> les alertes de stock bas ou négatif vont au propriétaire de la société et à toute personne qui a accès au magasin et y est autorisée à gérer le stock (administrateurs et membres)

**Nouvelle puce, avant « Outillage »** [S13] :
> - Journal d'audit : les opérations importantes (factures, paiements, autorisations, procédures et réglages de la société) s'inscrivent au journal d'audit de la société, en ajout seul, chaîné par magasin et par société et protégé contre toute modification, consultable par le propriétaire seul ; chaque catégorie se conserve selon l'obligation légale qui la concerne, sans donnée personnelle inutile ; l'adresse IP et le navigateur, du journal d'audit comme du journal des événements de la caisse, sont rangés à part, jamais dans un contenu signé, et effacés après 1 an.

**Feuille de route**
- Étape 1 — remplacer par :
  > 1. [ ] Corrections : ventes toujours au prix TTC et achats toujours au prix HT, retours partiels cumulés, défauts de l'audit complet, décisions de l'éditeur qui ne relèvent ni de SUPER PDP ni de la fin de la caisse sécurisée, dont l'architecture société → établissements → membres (inscription par lien, vérification de la société et magasin de démonstration, espace d'administration de la société, réglages communs de la société, membres et administrateurs créés directement et affectés à plusieurs magasins, autorisations propres à PILIQI, journal d'audit de société, fermeture et réouverture des magasins, procédures de l'éditeur) et les règles R1 à R5 (vente sans stock, remises et prix, demandes répétées, répartition de la TVA, caisses personnelles fermées d'office).
- Ajouter après l'étape 1 [T1 à T6] :
  > 1 bis. [ ] Campagne de tests initiale, après l'architecture société et les lots de décisions 14 à 21 : environnement isolé, sociétés fictives (par société 1 propriétaire et au moins 1 administrateur, par magasin au moins 1 responsable et 2 vendeurs), concurrence réelle sur PostgreSQL, sur cette machine au plus grand volume qu'elle tient (au-delà, résultats signalés comme extrapolés), d'abord avec la configuration actuelle puis avec Redis ; chaque anomalie classée et corrigée par Claude avec un test qui la reproduit, le rapport montrant chaque classement, que l'éditeur peut contester, l'éditeur ne validant que le déclassement d'une anomalie bloquante ; preuves publiées après chaque palier dans une page privée, avec leur empreinte enregistrée dans le dépôt. La campagne est relancée à la fin des étapes 2, 3, 5, 9 et 13 ; une campagne finale, sur une machine dédiée au volume complet, précède la mise en production (étape 15).
- Étape 2 : remplacer « connexion de chaque magasin et réception des factures d'achat d'abord » par « connexion de chaque société par son propriétaire, adresse de réception de chaque magasin (SIREN_SIRET) et réception des factures d'achat, attribuées automatiquement au bon magasin, d'abord » ; « chaque magasin délègue l'accès à son compte SUPER PDP » par « chaque société délègue l'accès à son compte SUPER PDP (l'entreprise de son SIREN) » ; « tunnel d'inscription si le magasin n'a pas encore de compte » par « tunnel d'inscription si la société n'a pas encore de compte » ; « préremplissage possible : login_hint (e-mail), superpdp_company_number + superpdp_company_number_scheme (sandbox, fr_siren, be_numero_entreprise), superpdp_directory_entry_identifier (adresse de réception d'une entreprise fr_siren), superpdp_send_and_receive (any par défaut, send, receive) » par « préremplissage : login_hint (e-mail), superpdp_company_number + superpdp_company_number_scheme (sandbox, fr_siren, be_numero_entreprise) ; superpdp_directory_entry_identifier et superpdp_send_and_receive, absents de la spécification 1.36.0.beta, ne s'envoient qu'une fois confirmés par la documentation officielle » ; ajouter à la fin : « En bac à sable seulement, une société de test se relie à une entreprise fictive par une correspondance configurée, leurs numéros n'ayant pas de clé valide. La connexion vérifiée au même SIREN prouve la qualité de représentant de la personne qui s'est connectée. Puis relance de la campagne de tests. »
- Étape 3 : ajouter à la fin : « Le mode démonstration (JET 100 et 105) figure dans la notice et le périmètre de l'attestation ; les archives signées embarquent la tête des chaînes du journal d'audit ; clôtures mensuelle et de l'exercice et archive finale à la fermeture d'un magasin selon la règle retenue ; reprise de la caisse à sa réouverture ; rattrapage d'une clôture d'exercice avancée par un changement légal. Puis relance de la campagne de tests. »
- Étape 4 — remplacer par :
  > 4. [ ] Journal d'audit des actions importantes (utilisateur, société, magasin, action, document, anciennes et nouvelles valeurs, IP, navigateur), consultable par le propriétaire : la table chaînée, ses traces séparées (IP et navigateur effacés après 1 an), ses durées par catégorie et les événements de société existent depuis l'étape 1 ; il reste à y inscrire les autres actions importantes (factures, paiements…).
- Étape 5 : remplacer « plan de comptes du magasin, export FEC » par « plan de comptes de la société, journaux par magasin, export FEC de la société » (supposé : FEC par entreprise, art. L47 A LPF, à vérifier avant l'étape 5) ; ajouter « Puis relance de la campagne de tests. »
- Étape 7 — remplacer par :
  > 7. [ ] Stock : inventaire (ouverture et comptage), ajustements, catégories, unités, import d'un catalogue (reprise d'un établissement par une autre société).
- Étape 9 — remplacer par :
  > 9. [ ] RGPD : export et anonymisation des clients, durées de conservation (documents légaux conservés à part), sort des sociétés jamais validées et de leurs données de démonstration, analyse d'impact de la vérification d'identité tenue à jour. Puis relance de la campagne de tests.
- Étape 12 — remplacer par :
  > 12. [ ] Abonnements SaaS souscrits par chaque société pour ses magasins : plans, limites, fonctionnalités par plan, facturation des sociétés par l'éditeur ; l'abonnement d'un magasin s'arrête à sa fermeture et reprend à sa réouverture (périodes d'activité tenues depuis l'étape 1) ; le magasin de démonstration et une société non validée ne sont jamais facturés ; suspension, résiliation et réactivation au niveau de la société.
- Étape 13 — remplacer par :
  > 13. [ ] Administration de l'éditeur (comptes distincts de ceux des sociétés, opérateurs authentifiés) : sociétés, magasins, utilisateurs, abonnements, suspension (société ou magasin), écrans des procédures exceptionnelles reprises des commandes `editeur:*` (validation et refus d'une société, pièces, levée des signaux, courriers de confirmation, réclamation et révocation d'une société usurpatrice, libération d'un SIRET, examen et correction exceptionnelle d'un SIREN, changement légal de fin d'exercice, annulation d'une cession), récupération de compte et transfert de propriété forcé (justificatif, code reçu par courrier recommandé à l'adresse officielle, délai d'opposition de 7 jours), statistiques, journaux système ; accès aux données d'un magasin seulement explicite et audité. Puis relance de la campagne de tests.
- Étape 15 : ajouter avant le point final « , double authentification des propriétaires et administrateurs (paquet soumis à l'accord de l'éditeur) ; campagne de tests finale sur une machine dédiée au volume complet » [V1-12, T1, T2].

**README.md** — remplacer la ligne 58 et le paragraphe de la ligne 60 par :
> Une société s'inscrit depuis `/app/register` avec son SIREN, son premier magasin (SIRET) et son propriétaire, par un lien envoyé à l'adresse du propriétaire ; elle ne vend qu'une fois validée (Sirene, qualité de représentant), et s'essaie avant dans un magasin de démonstration. Ses autres magasins se créent dans l'administration de la société (`/societe/{uuid}`). Les procédures exceptionnelles de l'éditeur : `php artisan list editeur` (opérateurs déclarés dans `EDITEUR_OPERATEURS`). Les fournisseurs externes (INSEE, INPI, vérification d'identité, envoi recommandé) se choisissent par les variables `*_PILOTE` de `.env.example` ; `faux` sert au développement et aux tests, jamais en production.

et la phrase du planificateur (ligne 50) par :
> En développement, trois commandes tournent côte à côte : le serveur du panneau, la file d'attente (e-mails, courriers et transmissions à la plateforme) et le planificateur (clôture automatique de minuit et caisses personnelles, factures en retard, e-reporting, vérification de la caisse sécurisée, relances, revérifications Sirene, démonstrations inactives, effacement des traces, des pièces, des jetons et des demandes d'inscription expirés, journal d'audit expiré).

---

## 13. Campagne de tests (T1 à T6)

- **Place** [T1] : après les lots A0 à A7 et les lots de décisions 20 et 21 : recalage R0, préparation C0, campagne initiale C1 (paliers P0 à P3, P4 extrapolé) ; relances C2 (étape 2), C3 (étape 3), C4 (étape 5), C5 (étape 9), C6 (étape 13) ; finale CF (étape 15).
- **Volume** [T2] : C1 au plus grand volume que tient cette machine (P3 si R0 confirme une base de 4 Go au plus), au-delà extrapolé et signalé ; machine dédiée avant la mise en production (10 000 sociétés).
- **Configuration** [T3] : base de données, puis Redis.
- **Équipes** [T4] : par société 1 propriétaire et au moins 1 administrateur (dont un de tous les magasins et, dans une société de plusieurs magasins, un d'une sélection) ; par magasin au moins 1 responsable et 2 vendeurs (décompte de `finale.md` 5.4 recalculé : 2 par société, 3 par magasin).
- **Gravité** [T5] : Claude classe et corrige ; le rapport montre chaque classement, que l'éditeur peut contester ; il valide seulement le déclassement d'une anomalie bloquante (point 9 de la section 10 de `finale.md` remplacé).
- **Preuves** [T6] : archive publiée après chaque palier dans une page privée ; empreinte de son `SHA256SUMS` versionnée (chemin fixé en C0).
- **Scénarios** (lot 21 étendu) : retirer magasin principal et rattachement par SIREN ; ajouter inscriptions concurrentes et réclamation, validation simultanée, inscriptions factices sans courrier imposé, démonstration (aucune fuite, JET 100/105), administrateurs à portée limitée, vendeur de plusieurs magasins, fermeture et réouverture (Z, périodes, même jour), cession, suspension non levée, vente sans stock simultanée, jetons répétés et rechargement de caisse, caisses personnelles fermées d'office, effacement des traces, et à C2 l'attribution des factures reçues.

---

## 14. Plan de lots

**Règles communes**
- Lire `.ai/rules/index.md` et les règles qui couvrent les fichiers touchés ; `grep -rin` des mots-clés.
- Protocole CLAUDE.md (section 0) : `migrate:fresh`, deux suites à code de sortie 0, `pint --dirty`, relecture adversariale, commit, push, CI verte.
- Tout fichier relu avant modification.
- Les tests de l'annexe B font partie du lot qui touche leur fichier d'origine ; la correspondance est recopiée dans le message de commit ; aucun test retiré sans destination.
- Un lot peut se commiter en plusieurs commits, chacun vert sur les deux moteurs.
- Le lot 1 de l'architecture (documents émis figés sur leur contenu signé : `Emetteur`, `FigeSonEmetteur`, `f360d9b`, `96c04dd`) est fait.

**Ordre** : lot de décisions 20 (en cours) → A0 → A1 → A2 → A3 → A4 → A5 → A6 → A7 → lot de décisions 21 étendu → campagne (étape 1 bis). Correspondance avec la v2 : A0 = A0 ; A1 = R4, R5 et traces (sortis de A5) ; A2 = A1 ; A3 = A4 ; A4 = A3 ; A5 = A2 ; A6 = A5 sans R4/R5 ; A7 = A6.

### Lot A0 : règles

- Objectif : textes de la section 12 non marqués **[après Qn]**.
- Fichiers : `CLAUDE.md`, `README.md`.
- Fin : le diff ne touche que les puces listées ; la phrase des e-mails en minuscules est présente mot pour mot ; aucun texte ne dépend d'une question sans réponse.
- Relecture : chaque changement découle d'une réponse ; aucune règle non concernée ne bouge.

### Lot A1 : préalables indépendants (R4, R5, traces techniques)

- Objectif : sections 8.4, 8.5 ; sortie de l'IP et du navigateur du contenu signé des JET.
- Migrations : `clotures_caisse_personnelles` (mode, auteur, borne) ; `traces_evenements` (créée).
- Créés : `ModeFermetureCaisse`, `FermerLesCaissesPersonnellesAction`, `TraceEvenement`, `DeclencheursDeProtection::effacementApresExpiration`, commande `app:purger-traces` (traces des événements ; étendue aux traces d'audit en A2).
- Modifiés : `FermerSaCaisseAction`, `CloturerCaisseAction`, `ClotureAutomatiqueCaisse`, `VerificateurCaissesPersonnelles`, `ListClotureCaisses`, `ClotureCaissePersonnelleResource` (« Fermer la caisse de … »), `CalculateurLigne` (docblock), `RegistreFiscal::evenement()` et `inscrire()` (trace dans la transaction), `EnregistrementFiscalResource`, `ExporterJournalDesEvenementsAction`, planification (`bootstrap/app.php`).
- Tests : `CaissesPersonnellesFermeesDOfficeTest`, `RepartitionTvaParCumulTest`, `CalculateurLigneTest::test_la_tva_d_un_taux_se_repartit_par_cumul_dans_l_ordre_des_lignes`, `test_aucun_contenu_signe_ne_contient_d_adresse_ip_ni_de_navigateur`, `test_journaliser_range_l_adresse_ip_et_le_navigateur_dans_la_trace` (réécriture de `RegistreFiscalTest::test_journaliser_note_l_adresse_ip_et_le_navigateur_de_la_requete_http`), tests du déclencheur (1.3), `test_les_traces_de_plus_d_un_an_sont_effacees`, réécritures de `ClotureCaisseResourceTest` et `EnregistrementFiscalResourceTest` (annexe B).
- Fin : le bouton de clôture n'a plus de condition de caisse personnelle ; `git grep -n "'ip' =>" app/Domain/CaisseSecurisee` vide hors du service de traces.
- Relecture : fermeture d'office et du vendeur simultanées ; borne franchie à minuit ; trace écrite hors transaction ; déclencheur contournable sous l'un des moteurs.

### Lot A2 : socle société

- Objectif : sociétés, magasins rattachés, propriétaire implicite, identité et fiscalité communes, émetteur étendu, JET 270 dans chaque magasin, mois de fin d'exercice, registre des SIRET, périodes d'activité, journal d'audit (écriture, chaînes, traces, expiration), table des vérifications (contrôles Sirene), inscription en deux temps, panneau société pour le propriétaire seul, porte de validation, fin du magasin principal.
- Migrations : `societes`, `demandes_inscription`, `sirets_magasins`, `periodes_activite`, `journal_audit`, `tetes_journal_audit`, `traces_audit`, `verifications_societe` (créées) ; `magasins` (`societe_id`, `etablissement_verifie_le`, statut, colonnes retirées), `users` (`societe_id`, `role_societe`, `statut`, `deleted_at` retirée) (modifiées) ; `magasin_user` gardée jusqu'à A4 pour les seuls membres.
- Créés : `Societe`, `SiretMagasin`, `PeriodeActivite`, `EntreeAudit`, `TeteJournalAudit`, `TraceAudit`, `VerificationSociete`, `DemandeInscription`, `BelongsToSociete`, `SocieteScope`, `WithSocieteContext`, `RoleSociete`, `StatutSociete`, `StatutCompte`, `FormeJuridique`, `MotifFermeture`, `CategorieAudit`, `ActionAuditee`, `TypeVerification` et `ResultatVerification` (cas Sirene), `JournalAudit`, `SiretsDesMagasins`, `PeriodesDActivite`, `SirenValide`, `SiretValide`, `NicValide`, `RepertoireSirene` + `InseeSirene` + `FauxRepertoireSirene` + `SireneIndisponibleException`, `VerificationServiceProvider`, `CreerSocieteAction`, `CreerEtablissementAction`, `ModifierIdentiteSocieteAction`, `ModifierParametresFiscauxSocieteAction`, `CorrigerSirenAvantValidationAction` (verrou selon Q3 ; en attendant la réponse, verrou à la validation, l'option la plus stricte), `VenteNonAutoriseeException`, `IdentiteDeLaSocieteIncompleteException`, commandes `app:purger-demandes-inscription`, `app:reverifier-sirene`, `app:purger-journal-audit`, `app:verifier-journal-audit`, page `InscriptionSociete`, `DemandeInscriptionMail`, `CompteExistantMail`, routes `GET|POST /inscription/{jeton}`, `SocietePanelProvider` (propriétaire seul), `RessourceDuPanneauSociete`, pages `TableauDeBord`, `IdentiteSociete`, `ParametresFiscauxSociete`, `SocietePolicy`, `SocieteFactory`, `MagasinsOuvertsParLaSession`.
- Modifiés : `Magasin`, `User` (colonnes ; propriétaire par `role_societe`), `TenantContext`, `BindTenantContextFromFilamentTenant`, `ControleDesDroits` (propriétaire implicite, société en lecture seule, compte inactif, porte de validation), `JournaliseSesParametresDeConformite`, `RegistreFiscal` (`sousVerrouDesMagasins`, en-tête, porte dans `ecrire()`, docblock des verrous), `Emetteur`, `ContenuFiscal`, `MentionsFiscales`, `DocumentMail`, vues PDF, générateurs de PDF (refus avant validation), `EmettreFactureAction` (porte après le verrou ; alignement retiré), `EmettreAvoirAction`, `EmettreDevisAction`, `EnvoyerDocumentAction`, `EnvoyerDocumentJob`, `RelancerFacturesImpayees`, `SoumettreEReportingPeriodique`, `CalculateurPeriodeEReporting`, `Caisse` et sa vue, `HorsDeLEntrepriseDuMagasin` et `VerifierNumeroTvaAction` (SIREN et n° de TVA de la société), **`ContexteDeVente`, `ControleNatureOperation`, `ChoisitLaNatureDeLOperation`, `ProduitResource`, `AjouteDesLignesDeVente`** (régime et franchise lus sur la société), `CodeMagasin`, `CodeMagasinLibre`, `DocumentNumberingService`, `CloturerLesJourneesAction` (périodes), `ClotureAutomatiqueCaisse` (docblock), `AppPanelProvider`, `StatutMagasin`, lectures de la lecture seule (3.8), `MagasinFactory`, `UserFactory`, `DatabaseSeeder`.
- **Transition jusqu'à A4, liste fermée** : le propriétaire n'est plus dans `magasin_user` ; ses lectures sont réécrites sur `role_societe` dans ce lot : `User::getTenants`, `canAccessTenant`, `canAccessPanel`, `appartientA` [`:84-90`], `estProprietaireDe` [`:98-101`], `User.php:136-161`, `Magasin::proprietaires()` [`:512-519`], `JournaliserFinDeSession` [`:48`], `NotifierLeStockSousLeSeuilDAlerte` [`:42`], `EnvoyerDocumentJob::aPrevenir()` [`:227-231`], `MarquerFacturesEnRetard` [`:62`], `MembreEquipeResource` [`:144`], `MembreEquipePolicy` [`:55`], `TestCase::proprietaireDe()` [`tests/TestCase.php:74-77`]. Les membres gardent `magasin_user` et leurs cases Spatie : `SeedMagasinRolesAction` est réduite à la création des lignes de permissions et des rôles de membres (seul `Permission::insertOrIgnore` du dépôt [`:41`]), appelée par `CreerEtablissementAction` ; `CreerMembreMagasinAction` crée le compte avec le `societe_id` du magasin et `role_societe = membre`. `IdentiteMagasin` et `ParametresFiscaux` sont supprimées (les pages du panneau société les remplacent ; pas de formulaire de transition).
- Supprimés : service, trait, exception et commande du magasin principal ; `CreerMagasinAction` ; `RegisterMagasin` ; `IdentiteMagasin`, `ParametresFiscaux` et leurs vues ; `MagasinOuvertParLaSession`.
- `TestCase` : `ouvrirUneSociete(array $options = []): Magasin` (validée, établissement vérifié par défaut, par l'état de fabrique `validee()` ; `['a_verifier' => true]`) ; `ouvrirUnAutreMagasin(Magasin, array $donnees = []): Magasin` ; `proprietaireDe(Magasin): User` ; `completerIdentiteLegale(Magasin)` ; les 508 appels de `CreerMagasinAction` dans 68 fichiers passent par ces aides, sans changer leurs assertions.
- Tests :
  - `InscriptionSocieteTest` : `test_la_demande_envoie_un_lien_et_ne_cree_rien_avant_sa_confirmation` ; `test_la_confirmation_cree_la_societe_a_verifier_son_premier_magasin_et_son_proprietaire_en_une_fois` ; `test_l_identite_et_les_adresses_sont_preremplies_depuis_sirene` ; `test_une_entreprise_cessee_un_etablissement_ferme_ou_un_numero_inconnu_est_refuse` ; `test_l_insee_injoignable_n_empeche_pas_l_inscription_et_le_controle_reprend` ; `test_l_ecran_repond_pareil_que_le_siren_l_adresse_ou_le_siret_soient_libres_ou_non` ; `test_la_confirmation_d_un_siren_deja_valide_ne_revele_rien_et_cree_une_societe_a_verifier` ; `test_une_adresse_deja_utilisee_recoit_un_e_mail_et_aucune_demande` ; `test_deux_inscriptions_en_attente_du_meme_siren_coexistent` ; `test_le_lien_expire_apres_24_heures` ; `test_le_lien_ne_sert_qu_une_fois_et_l_ouverture_seule_ne_le_consomme_pas` ; `test_le_jeton_n_est_jamais_stocke_en_clair` ; `test_le_quatrieme_essai_dans_l_heure_pour_un_meme_siren_et_une_meme_adresse_est_refuse` ; `test_un_tiers_ne_sature_pas_le_siren_d_une_entreprise` ; `test_un_siren_a_cle_invalide_ou_fait_de_zeros_est_refuse` ; `test_un_siret_de_la_poste_se_controle_par_la_somme_de_ses_chiffres` ; `test_le_code_propose_suit_le_nom_sans_ecraser_un_code_saisi` (repris) ; `test_ni_la_proposition_ni_la_validation_du_code_ne_revelent_les_codes_d_une_autre_societe` (repris) ; `test_le_panneau_societe_n_a_aucune_inscription` ; `test_les_demandes_expirees_sont_purgees`.
  - `PorteDeValidationTest` : les cinq premiers tests de 7.10 ; `test_le_siren_se_corrige_avant_le_verrou_et_plus_apres` ; `test_une_societe_n_est_jamais_a_la_fois_validee_et_refusee` ; `test_aucun_pdf_d_un_magasin_reel_ne_se_telecharge_avant_la_validation`.
  - `CreerEtablissementActionTest` : `test_le_proprietaire_cree_un_magasin_avec_le_siren_de_la_societe` ; `test_sans_autorisation_aucun_magasin_ne_se_cree` ; `test_un_societe_id_force_dans_la_requete_est_ignore` ; `test_un_siret_reserve_ne_sert_jamais_a_un_autre_magasin` ; `test_un_etablissement_ferme_ou_inconnu_a_l_insee_est_refuse_sans_rien_creer` ; `test_l_insee_injoignable_cree_un_etablissement_a_verifier` ; `test_le_code_est_unique_dans_la_societe_mais_libre_dans_une_autre` ; `test_un_code_propose_deja_pris_recoit_le_premier_suffixe_libre` (repris) ; `test_le_client_comptoir_est_cree_avec_le_magasin` (repris) ; `test_la_creation_s_inscrit_au_journal_d_audit` ; `test_un_nouveau_magasin_journalise_les_droits_implicites` ; `test_une_societe_suspendue_ne_cree_plus_de_magasin` ; `test_hors_des_territoires_geres_le_magasin_est_refuse` ; `test_un_magasin_ne_se_supprime_jamais_et_la_base_refuse_de_l_effacer` (repris) ; méta-test des SIRET ; `test_une_ligne_du_registre_des_siret_ne_se_modifie_ni_ne_s_efface`.
  - `ParametresFiscauxSocieteTest` : `test_le_regime_de_la_societe_s_applique_a_tous_ses_magasins` ; `test_un_changement_de_regime_s_inscrit_au_journal_de_chaque_magasin_avec_l_ancienne_et_la_nouvelle_valeur` (magasin fermé compris) ; `test_un_parametre_inchange_n_est_pas_journalise` (repris) ; `test_sans_cle_de_signature_rien_n_est_enregistre_dans_aucun_magasin` ; `test_le_mois_de_fin_d_exercice_se_verrouille_des_la_premiere_vente_d_un_magasin` ; `test_une_emission_lit_le_regime_de_la_societe_apres_son_verrou` ; `test_un_magasin_de_la_martinique_propose_8_5_pour_cent_sur_un_service` (repris) ; `test_la_nature_d_operation_lit_la_franchise_de_la_societe`.
  - `IdentiteSocieteTest` : `test_le_proprietaire_enregistre_l_identite_legale_de_la_societe` ; `test_le_siren_ne_se_saisit_dans_aucun_ecran_de_magasin` ; `test_une_identite_incomplete_bloque_les_factures_professionnelles_de_tous_les_magasins_mais_pas_les_tickets` ; `test_le_capital_est_exige_pour_toute_societe_commerciale` (paramétré : SARL, EURL, SAS, SASU, SA, SCA, SE, SNC, SCS) ; `test_une_selarl_et_une_sa_cooperative_exigent_leur_capital` ; `test_une_forme_autre_exige_de_dire_si_la_societe_est_commerciale` ; `test_un_entrepreneur_individuel_n_a_pas_de_capital` ; `test_la_forme_juridique_est_preremplie_depuis_la_famille_de_la_categorie_insee` ; `test_le_numero_de_tva_s_enregistre_sans_separateurs` (repris) ; `test_le_capital_social_s_enregistre_puis_se_vide` (repris) ; `test_aucun_magasin_n_a_de_privilege_sur_les_reglages` ; reprises de l'annexe B.
  - `EmetteurFigeTest` : les tests de 5.4 sauf démonstration (A3).
  - `JournalAuditTest` : 9.8 sauf la ressource.
  - `ControleDesDroitsTest` (complété) : propriétaire implicite ; aucun droit ailleurs ; société suspendue ; compte suspendu ; autorisations commerciales refusées tant que le magasin ne peut pas vendre.
  - Isolation : 2.5, parties « deux sociétés » et « deux magasins ».
  - `MagasinEnLectureSeuleTest` paramétré ; `test_un_envoi_en_file_ne_part_pas_si_la_societe_a_ete_suspendue_entre_temps` ; `test_aucune_relance_automatique_pour_une_societe_suspendue_ou_non_validee`.
  - `CloturerLesJourneesActionTest` : `test_les_journees_a_cloturer_ne_sont_que_celles_des_periodes_d_activite`.
  - `PanneauSocieteTest` (propriétaire seul) : `test_chaque_ressource_et_page_du_panneau_societe_a_une_policy_complete` ; `test_les_ressources_du_panneau_magasin_gardent_le_controle_d_existence_des_policies` ; `test_les_options_du_panneau_magasin_ne_sont_pas_touchees_par_les_gardes_du_panneau_societe`.
- Fin : `git grep -n -E "designe_principal|ReglagesCommuns|AligneLesReglagesCommuns|designer-magasin-principal|reglagesNonAlignes|deLaSocieteDe|CreerMagasinAction|Magasin::withTrashed|magasin\(\)->withTrashed|User::withTrashed|IdentiteMagasin|ParametresFiscaux::"` vide dans `app/`, `database/`, `tests/`, `resources/` (les `withTrashed()` des fiches archivées restent) ; aucun `->siren`, `->regime_tva`, `->raison_sociale` lu sur un `Magasin` ; critère de 3.8 ; aucun `12345678900012` ni `732829320` dans les tests.
- Relecture : identifiants forgés ; réservation des SIRET concurrente ; vente et changement de régime simultanés ; JET 270 dans un magasin fermé ; instance de société périmée à l'émission ; commandes planifiées sans contexte ; propriétaire implicite qui déborderait ; messages qui révéleraient un SIREN ; course sur le lien ; porte contournée par un job, une commande ou un PDF ; chaînes d'audit sous les deux moteurs ; liste de transition complète (`git grep` des lectures de `magasin_user` par le propriétaire) ; aucune assertion perdue.

### Lot A3 : vérification de la société et magasin de démonstration

- Objectif : sections 7 et 5.8 ; à la fin de ce lot, une société peut être validée.
- Migrations : `codes_confirmation`, `pieces_verification`, `demandes_accord_dirigeant` (créées) ; `magasins` (`est_demonstration`) ; `users` (`qualite_verifiee_le`) ; `verifications_societe` (`beneficiaire_id`, cas restants).
- Créés : cas restants de `TypeVerification`, `SignalDeRisque`, `MotifCode`, `CanalEnvoi`, `CodeConfirmation`, `PieceVerification`, `DemandeAccordDirigeant`, `EvaluateurDeVerification`, `RapprochementDesDirigeants` (personnes morales comprises), `SignauxDeRisque`, `CodesDeConfirmation`, `ValiderLaSocieteAction`, contrats et implémentations RNE, identité, envoi recommandé (réelles quand l'éditeur aura choisi ses prestataires ; factices tout de suite), actions `DemarrerVerificationIdentiteAction`, `RecevoirResultatIdentiteAction`, `DemanderAccordDUnDirigeantAction`, `ApprouverMandatAction`, `DeposerUnePieceAction`, `EnvoyerCodeDeConfirmationAction`, `SaisirCodeDeConfirmationAction`, `OuvrirLaDemonstrationAction`, `FermerLaDemonstrationAction`, `Emetteur::deDemonstration()`, `CodeEvenement` 100 et 105, page `VerificationSociete`, routes des rappels et de l'accord d'un dirigeant, commandes `editeur:societe:valider`, `editeur:societe:refuser`, `editeur:societe:pieces`, `editeur:societe:lever-signal`, `editeur:courrier:envoyer`, `editeur:reclamation:ouvrir`, `app:fermer-demonstrations-inactives`, `app:effacer-pieces-verification`, `RegistreDesOperateurs`, catalogue d'exemple, `LettreDInformationValidation`.
- Modifiés : `app:reverifier-sirene` (mensuelle, sans blocage), `Magasin::peutVendre()` (démonstration), `EnvoyerDocumentAction` et `EnvoyerDocumentJob` (refus en démonstration), `RegistreFiscal` (en-tête de la démonstration), vues PDF (filigrane), `CodeMagasinAuFormat` (`DEMO`), planification, `.env.example`, `config/services.php`, `scratchpad/caisse-securisee-conception.json` (ECO-01 et codes 100/105 révisés, hors dépôt).
- Tests : `VerificationSocieteTest` (7.10), `MagasinDeDemonstrationTest` (7.8), tests `Http::fake` de `InseeSirene`, `InpiRne` et des prestataires retenus, `test_aucun_pilote_factice_n_est_accepte_en_production`, part « opérateurs » de `ProceduresTest`, `EmetteurFigeTest::test_un_document_de_demonstration_n_imprime_aucune_identite_reelle`.
- Fin : aucune validation sans A, une preuve B (B4 avec C) et chaque signal levé ; aucun pilote factice en production ; aucune requête sortante dans la suite ; aucune copie de pièce au-delà de 30 jours.
- Relecture : code deviné ; rappel forgé ou rejoué ; courrier à une adresse saisie ou déposée ; relais de la vérification d'identité ; inscriptions factices qui imposent un courrier ; énumération des clients ; données d'identité hors liste blanche ; démonstration qui écrit dans un registre réel ; validation concurrente ; signal levé sans ce qu'il exige ; B4 sans C.

### Lot A4 : comptes, autorisations, membres, administrateurs

- Objectif : retrait de Spatie [S12] ; affectations multiples et autorisations propres ; administrateurs créés en une fois avec leur portée [V1-2] ; membres créés directement, mot de passe provisoire, lien de réinitialisation [S1] ; changement d'adresse confirmé ; rattachement et création de société par un compte retiré ; transfert de propriété (qualité prouvée) ; traçabilité stricte [V1-1] ; sélecteur de magasin ; destinataires [V1-3] ; panneau société ouvert aux administrateurs ; fin de l'équipe du panneau magasin et de `EQUIPE_*`.
- Migrations : `affectations`, `autorisations_affectations`, `autorisations_societe` (remplacent `magasin_user`) ; `users` (`mot_de_passe_a_changer`, `mot_de_passe_provisoire_expire_le`, `premiere_connexion_le`) ; `demandes_rattachement` ; suppression de la migration de Spatie ; `composer remove spatie/laravel-permission` (accord donné par S12).
- Créés : `Affectation`, `DemandeRattachement`, `AutorisationsSociete`, `LecteurDesDroits`, `CreerMembreAction`, `CreerAdministrateurAction`, `ModifierMembreAction`, `ChangerStatutCompteAction`, `DonnerUnMotDePasseProvisoireAction`, `EnvoyerUnLienDeReinitialisationAction`, `ChangerSonMotDePasseProvisoireAction`, `NommerAdministrateurAction`, `ModifierAdministrateurAction`, `ProposerUnRattachementAction`, `AccepterUnRattachementAction`, `CreerSaSocieteAvecUnCompteRetireAction`, `TransfererProprieteAction`, `ConfirmerNouvelleAdresseAction`, `EmailLibrePourUnNouveauCompte`, `MembrePolicy`, `MembreResource` (+ pages), `MagasinDeLaSocietePolicy` (lecture), page `ChangerMotDePasseProvisoire`, middleware `ExigerUnMotDePasseDefinitif`, routes et vues `/rattachement/{jeton}`, `/societe/creer`, `/profil/adresse/{jeton}`, mails `PropositionDeRattachementMail`, `RattachementImpossibleMail`, `ConfirmationNouvelleAdresseMail`, `AdresseChangeeMail`, `LienDeReinitialisationEnvoyeNotification`.
- Modifiés : `PermissionRegistry`, `RoleMagasin`, `ControleDesDroits`, `User`, `TenantContext`, `JournalDesDroits` (plusieurs magasins, statut, droits relus en base), `JournaliserOuvertureDeSession`, `JournaliserFinDeSession`, `ClotureCaissePersonnellePolicy`, `NotifierLeStockSousLeSeuilDAlerte`, `EnvoyerDocumentJob::aPrevenir()`, `MarquerFacturesEnRetard`, `ModifierProfil` (adresse confirmée), écouteur `PasswordReset`, panneaux (middleware, menu « Administration de la société »), `Caisse` (bandeau), `EmettreFactureAction` et actions de paiement, d'avoir et de clôture (autorisation relue sous verrou), `TestCase::membre()` (affectation et cases au lieu de Spatie), `paniers_caisse` (commentaire).
- Supprimés : bloc « équipe » de la section 11, `MagasinTeamResolver`, `config/permission.php`, `MagasinUser`, `StatutMembreMagasin`, `SeedMagasinRolesAction`, migration `magasin_user`.
- Tests :
  - `MembresTest` : `test_le_proprietaire_cree_un_vendeur_affecte_a_deux_magasins_avec_un_mot_de_passe_provisoire` ; `test_le_membre_change_son_mot_de_passe_provisoire_a_sa_premiere_connexion_avant_toute_page` ; `test_une_requete_livewire_est_refusee_tant_que_le_mot_de_passe_est_provisoire` ; `test_un_mot_de_passe_provisoire_expire_apres_72_heures` ; `test_un_mot_de_passe_oublie_reussi_efface_le_mot_de_passe_provisoire` ; `test_le_mot_de_passe_provisoire_n_est_jamais_journalise_ni_envoye_par_e_mail` ; `test_une_adresse_deja_utilisee_recoit_une_reponse_neutre_et_aucun_compte_n_est_touche` ; `test_l_adresse_d_un_nouveau_membre_s_enregistre_en_minuscules_sans_espaces` (repris) ; `test_un_mot_de_passe_provisoire_trop_court_est_refuse` (repris) ; `test_la_creation_inscrit_les_droits_dans_chaque_magasin_en_un_evenement` ; `test_un_magasin_d_une_autre_societe_ne_s_affecte_jamais` ; `test_aucune_creation_ni_modification_ne_donne_la_propriete` ; `test_creer_un_compte_suspendu_de_la_societe_propose_de_le_reintegrer` ; `test_le_vingt_et_unieme_compte_du_jour_est_refuse`.
  - `MotDePasseProvisoireTest` : `test_le_mot_de_passe_provisoire_ne_se_donne_qu_a_un_compte_jamais_connecte_que_l_on_domine` ; `test_reinitialiser_un_membre_deja_connecte_lui_envoie_un_lien_et_l_auteur_ne_connait_pas_le_mot_de_passe` ; `test_le_membre_est_prevenu_de_toute_reinitialisation` ; `test_personne_ne_se_donne_un_mot_de_passe_provisoire_depuis_les_membres` ; `test_jamais_pour_le_proprietaire_ni_pour_un_administrateur_sauf_par_le_proprietaire` ; `test_donner_un_mot_de_passe_provisoire_coupe_le_se_souvenir_de_moi`.
  - `AutorisationsDesMembresTest` : `test_le_proprietaire_coche_les_autorisations_magasin_par_magasin` ; `test_un_administrateur_ne_coche_que_ce_qu_il_a_dans_ce_magasin` ; `test_un_simple_membre_ne_coche_rien` ; `test_une_autorisation_inconnue_est_refusee_sans_rien_changer` (repris) ; `test_un_administrateur_ne_touche_ni_au_proprietaire_ni_a_un_administrateur_ni_a_lui_meme` ; `test_un_administrateur_ne_modifie_pas_un_membre_plus_autorise_que_lui_mais_peut_couper_son_acces_dans_sa_portee` ; `test_un_membre_sans_affectation_ne_se_gere_que_par_le_proprietaire_ou_un_administrateur_global` ; `test_un_administrateur_ne_reintegre_pas_un_compte_suspendu_par_le_proprietaire` ; `test_le_nom_et_l_adresse_d_un_autre_compte_ne_se_modifient_jamais` ; `test_role_societe_statut_et_autorisations_ne_se_remplissent_jamais_par_masse` ; `test_retirer_d_un_magasin_inscrit_les_droits_perdus_dans_ce_seul_magasin` ; `test_suspendre_un_compte_inscrit_le_jet_130_dans_chacun_de_ses_magasins` ; `test_retirer_de_la_societe_garde_le_compte_coupe_tout_acces_et_efface_ses_notifications` ; `test_un_compte_retire_ne_libere_jamais_son_adresse` ; `test_le_role_ne_fait_que_precocher` ; `test_une_modification_s_inscrit_en_un_seul_evenement_avec_les_droits_avant_et_apres_relus_en_base`.
  - `AdministrateursTest` : `test_le_proprietaire_cree_un_administrateur_avec_sa_portee_et_ses_autorisations_en_une_fois` ; `test_aucune_portee_n_est_preselectionnee` ; `test_un_administrateur_de_tous_les_magasins_a_ses_autorisations_dans_un_magasin_cree_apres_lui` ; `test_un_administrateur_d_une_selection_n_a_rien_hors_de_sa_selection` ; `test_un_administrateur_d_une_selection_ne_cree_pas_de_magasin_et_ne_coche_pas_les_autorisations_de_toute_la_societe` ; `test_un_administrateur_d_une_selection_ne_voit_et_ne_gere_que_les_membres_de_ses_magasins` ; `test_nommer_administrateur_supprime_les_affectations_avec_un_jet_130_par_magasin` ; `test_modifier_un_administrateur_global_inscrit_le_jet_130_dans_tous_les_magasins` ; `test_retrograder_un_administrateur_exige_ses_affectations` ; `test_retirer_un_administrateur_le_repasse_membre_sans_autorisation_de_societe` ; `test_un_administrateur_suspendu_n_ouvre_plus_rien`.
  - `RattachementTest` : `test_un_compte_retire_rejoint_une_autre_societe_avec_son_accord_et_son_mot_de_passe` ; `test_un_compte_encore_rattache_ailleurs_ne_peut_pas_etre_rattache` ; `test_la_reponse_a_l_auteur_ne_revele_jamais_l_etat_du_compte` ; `test_l_acceptation_reverifie_l_auteur_et_les_magasins` ; `test_le_lien_expire_et_ne_sert_qu_une_fois` ; `test_accepter_annule_les_autres_demandes` ; `test_l_audit_s_inscrit_dans_les_deux_societes` ; `test_ni_le_proprietaire_ni_un_administrateur_ne_se_rattache_ailleurs` ; `test_la_route_de_rattachement_contourne_explicitement_le_scope_de_societe` ; `test_un_compte_retire_cree_sa_propre_societe_avec_le_meme_compte` ; `test_un_compte_actif_ne_cree_pas_de_seconde_societe`.
  - `ModifierProfilTest` (complété) : `test_une_nouvelle_adresse_ne_prend_effet_qu_apres_confirmation_et_l_ancienne_est_prevenue` ; `test_un_lien_de_confirmation_expire_ou_rejoue_est_refuse`.
  - `TransfererProprieteTest` : `test_le_proprietaire_transmet_la_propriete_a_un_administrateur_dont_la_qualite_est_prouvee` ; `test_le_transfert_a_un_administrateur_sans_qualite_prouvee_est_refuse` ; `test_le_mot_de_passe_est_redemande` ; `test_chaque_societe_a_exactement_un_proprietaire_actif_apres_chaque_action_et_procedure`.
  - `TracabiliteDesVentesTest` (3.6) ; `JournalEvenementsTest` (complété) : `test_ouvrir_a_puis_b_puis_a_inscrit_une_ouverture_par_magasin` ; `test_la_deconnexion_inscrit_la_fin_de_session_dans_chaque_magasin_ouvert` ; `SelecteurDeMagasinTest` : `test_un_membre_de_deux_magasins_voit_les_deux` ; `test_un_vendeur_d_un_seul_magasin_ne_voit_que_lui` ; `test_le_proprietaire_voit_tous_les_magasins_de_sa_societe` ; notifications : `test_l_alerte_de_stock_va_au_proprietaire_et_aux_personnes_autorisees_du_magasin` ; `test_l_echec_d_un_envoi_d_un_administrateur_le_previent_lui` ; `test_toute_notification_porte_sa_societe` ; concurrence (4.9) ; `test_aucune_table_ni_aucune_classe_de_spatie_ne_subsiste` ; `PanneauSocieteTest` (complété) : `test_un_membre_n_ouvre_pas_l_espace_societe` ; `test_un_administrateur_avec_parametres_ouvre_l_identite` ; `test_un_administrateur_sans_parametres_n_ouvre_ni_l_identite_ni_la_fiscalite` ; `test_les_options_des_formulaires_et_filtres_se_limitent_a_la_societe_et_a_la_portee` ; `test_une_societe_suspendue_garde_son_espace_consultable_mais_ne_cree_rien` ; `test_une_societe_revoquee_ne_gere_plus_son_equipe` ; `test_le_tableau_de_bord_d_un_administrateur_d_une_selection_ne_montre_que_ses_magasins`.
- Fin : `git grep -n -E "EQUIPE_|MembreEquipe|MagasinUser|magasin_user|estProprietaireDe\(|RoleMagasin::(Proprietaire|Administrateur)|checkPermissionTo|HasRoles|Spatie|permission\.|SeedMagasinRolesAction"` vide dans `app/`, `config/`, `database/`, `tests/`, `resources/` ; `composer.json` sans le paquet ; installation depuis une base vide verte sous les deux moteurs.
- Relecture : compte secondaire créé pour dépasser ses droits ; case ajoutée par requête forgée ; administrateur hors de sa sélection ; domination à vide ; rejeu d'un jeton de rattachement ou de confirmation d'adresse ; acceptation après rétrogradation de l'auteur ; énumération d'adresses ; JET 130 manquant ; mémoire de droits périmée ; retrait d'autorisation pendant une émission ; mot de passe provisoire contourné par Livewire ; transfert vers un administrateur non vérifié.

### Lot A5 : cycle de vie des magasins

- Objectif : `MagasinResource` (créer, modifier, changer de SIRET, fermer, rouvrir, céder, établissement principal) ; suspension rangée à part ; `CoordonneesMagasin` (e-mail, téléphone, références d'acheteur) ; événements pour l'étape 12.
- Migrations : `magasins` (`suspendu_le`, `est_etablissement_principal`, `references_acheteur`) ; `mouvements_stock` (types de cession).
- Créés : `Filament/Societe/Resources/MagasinResource` (+ pages), `ModifierEtablissementAction`, `ChangerSiretAction`, `FermerMagasinAction`, `RouvrirMagasinAction`, `ChoisirEtablissementPrincipalAction`, événements `MagasinOuvert`, `MagasinFerme`, `MagasinRouvert`, `SocieteValidee` (émis par `ValiderLaSocieteAction`), `Filament/App/Pages/CoordonneesMagasin`, `TypeMouvementStock::SortieCession`.
- Modifiés : `MagasinDeLaSocietePolicy` (gestes), `PermissionRegistry` (libellé de `PARAMETRES_GERER`), `StockLedgerService` (sortie de cession au coût moyen), `Magasin::estEnLectureSeule()` (suspension), `ValiderLaSocieteAction` (événement).
- Tests : `MagasinResourceTest` : `test_la_creation_se_fait_par_le_nic_apres_le_siren_fixe` ; `test_le_code_se_verrouille_au_premier_document_numerote` ; `test_un_premier_produit_ne_verrouille_pas_le_code` (repris) ; `test_un_code_devenu_definitif_pendant_la_saisie_n_est_pas_enregistre` (repris) ; `test_une_valeur_prise_pendant_l_enregistrement_est_refusee_comme_par_la_validation` (repris) ; `test_changer_le_siret_exige_sirene_garde_le_magasin_et_reserve_l_ancien` ; `test_un_changement_de_siret_s_inscrit_au_jet_270_et_les_documents_emis_gardent_l_ancien` ; `test_un_changement_d_adresse_qui_change_le_territoire_de_tva_est_refuse` ; `test_l_etablissement_principal_n_a_aucun_effet_sur_les_reglages` ; `test_le_magasin_d_une_autre_societe_repond_404` ; reprises de l'annexe B ; `FermerEtRouvrirMagasinTest` (5.7) ; `CoordonneesMagasinTest` : `test_le_responsable_avec_les_parametres_regle_l_e_mail_et_le_telephone_du_magasin` ; `test_un_vendeur_n_y_accede_pas` ; `test_les_coordonnees_restent_celles_du_magasin_actif`.
- Fin : aucune page du panneau `app` n'écrit une donnée de la société ; méta-test des policies vert ; une réouverture ne lève jamais `suspendu_le`.
- Relecture : requête Livewire forgée ; méthode de policy manquante ; société suspendue ; fermeture concurrente d'une vente ; réouverture d'un magasin cédé ou le même jour ; Z produits pendant une fermeture ; cession par un administrateur.

### Lot A6 : R1, R2, R3

- Objectif : sections 8.1 à 8.3 et ordre de 8.6. R2 sauf le point qui attend Q1.
- Migrations : `actions_traitees` (créée) ; `services` (prix de référence) ; `paniers_caisse` (`jeton`).
- Créés : `VerificateurDeStock`, `StockInsuffisantException`, `ConfirmationVenteSansStockRequiseException`, notification `StockNegatif`, `DerogationsDePrix`, `ActionsUniques`, `ActionTraitee`, commande `app:purger-actions-traitees` ; constantes `STOCK_VENDRE_SANS_STOCK`, `VENTE_REMISES_ET_PRIX`.
- Modifiés : `PermissionRegistry`, `RoleMagasin`, `EmettreFactureAction` (ordre de 8.6, produits verrouillés avant la numérotation), `EmettreAvoirAction`, validation des achats et des avoirs fournisseur (ordre des verrous), `EnregistrerVenteCaisseAction`, `PanierDeCaisse`, `EnregistrerPaiementAction`, `ContrePasserPaiementAction`, `EnregistrerAvoirAction`, `EmettreDevisAction`, `EnvoyerDocumentAction`, `FermerSaCaisseAction`, `CloturerCaisseAction`, `CreerFactureAction`, `ModifierFactureAction`, `CreerDevisAction`, `ModifierDevisAction`, `TrouverOuCreerServiceAction`, `ServiceResource`, `AjouteDesLignesDeVente`, `Caisse`, `FactureResource`, `ProduitResource`.
- Tests : `VenteSansStockTest`, `RemisesEtPrixTest`, `ActionsUniquesTest` (section 8).
- Fin : toute émission passe par le contrôle de stock ; aucune remise ni prix de service introduit ou modifié sans l'autorisation (requêtes forgées) ; chaque action couverte refuse un doublon et un jeton de contenu différent ; aucune action interne ne consomme de jeton.
- Relecture : deux ventes de la dernière unité ; stock qui baisse pendant la fenêtre d'avertissement ; autorisation retirée pendant la fenêtre ; jeton rejoué par un autre compte ou avec un autre montant ; rechargement de la caisse après une réponse perdue ; ligne remisée dont on change la quantité ; inversion des verrous produits et séquences.

### Lot A7 : procédures de l'éditeur, journal consultable, conservation

- Objectif : section 9 ; `JournalAuditResource` ; vérification et expiration ; traces d'audit lisibles.
- Migrations : `changements_fin_exercice` ; après Q6 : `factures` (`facture_rectifiee_id`), `avoirs` (`regularisation`).
- Créés : `RevoquerSocieteAction`, `LibererSiretAction`, `ExaminerSirenAction`, `CorrigerSirenAction`, `ChangerFinExerciceAction`, `AnnulerCessionAction`, `ChangementFinExercice` ; commandes `editeur:societe:revoquer`, `editeur:siret:liberer`, `editeur:societe:examiner-siren`, `editeur:societe:corriger-siren`, `editeur:societe:changer-fin-exercice`, `editeur:magasin:annuler-cession` ; `JournalAuditResource`, `EntreeAuditPolicy` ; après Q6 : `RegulariserUneFactureAction` et les modifications de `RestaurerStockApresEmissionAvoir`, `DecrementerStockApresEmissionFacture`, `ImputerAvoirSurSesFacturesAction`, `MentionsFiscales`, `ContenuFiscal`, e-reporting (imputation non déclarée).
- Tests : `ProceduresTest`, `RevoquerSocieteTest`, `LibererSiretTest`, `SirenErroneTest`, `ChangerFinExerciceTest`, `AnnulerCessionTest`, `JournalAuditResourceTest` (section 9).
- Fin : chaque commande couverte jusqu'au refus sans confirmation ni opérateur authentifié ; invariant du propriétaire vert après chaque procédure ; aucun document émis modifié (empreintes des contenus signés comparées avant et après).
- Relecture : contournement de la confirmation ou des secrets ; transaction partielle ; justification vide ; JET dans le mauvais magasin ; régularisation qui bouge le stock, déclare un encaissement ou fait payer deux fois ; changement de fin d'exercice rétroactif ou repoussant une clôture ; expiration qui casse une chaîne.

### Lot de décisions 21, étendu : fin de l'étape 1

- Objectif : vérification transverse de toutes les décisions de l'étape 1 et de la quatrième série (matrice décision → test) ; révision du plan SUPER PDP selon S2, section 6 et la voie B3 ; scénarios de campagne (13) ; dernière passe sur les commentaires ; textes **[après Qn]** écrits si les réponses sont arrivées.
- Fin : `git grep -n -i -E "un seul magasin|magasin principal|écran de sélection|Super Admin|seul le propriétaire coche"` vide dans `app/`, `database/`, `resources/` ; étape 1 cochée.

### Étape 1 bis : campagne de tests initiale (section 13)

---

## 15. Questions à l'éditeur

Chaque question a une réponse recommandée ; ce qui en dépend n'est construit qu'après la réponse ; le reste du lot avance.

**Q1. Qui peut changer le prix d'une ligne de produit ?** (lot A6)
Le contexte de R2 disait « le prix d'une ligne de produit vient de sa fiche et ne se modifie pas » ; or le code le permet, à l'écran (`AjouteDesLignesDeVente.php:268-295`) et dans le domaine (`ConstructeurLigneDocument.php:189-191`), et des tests l'encodent (`FactureResourceTest::test_saisir_le_prix_ttc_dune_ligne_totalise_exactement_a_quantite_2`, `CaisseTest::test_saisir_le_prix_ttc_dune_ligne_calcule_automatiquement_le_prix_ht`). Sans règle, un vendeur contournerait R2 en baissant le prix d'un produit.
- **(a) Recommandée** : seulement un membre qui a « accorder des remises et fixer le prix d'un service » ; pour les autres, le PU TTC est celui de la fiche. Texte : insérer « et le prix d'une ligne de produit différent de sa fiche » après « le prix d'un service » dans la phrase de R2. Les tests cités, en propriétaire, restent valables.
- (b) Personne : le PU TTC d'une ligne de produit est toujours celui de sa fiche ; une baisse passe par une remise. Texte : « Le PU TTC d'une ligne de produit est toujours celui de sa fiche ; une baisse passe par une remise. » Les deux tests sont réécrits en nommant cette réponse.

**Q2. Un vendeur affecté à plusieurs magasins peut-il encaisser dans l'un tant que sa caisse est ouverte dans l'autre ?** (lot A4)
Votre note V1-1 veut qu'un membre soit « strictement rattaché au magasin dans lequel il encaisse au moment de la vente ». Ce qui est déjà prévu dans tous les cas : le magasin de chaque vente est celui de l'écran, son SIRET est sur la facture, le vendeur doit y être affecté et autorisé au moment même de la vente, et la caisse affiche en permanence le magasin et le vendeur. Reste à savoir si un vendeur peut avoir deux caisses ouvertes à la fois. Lecture retenue de votre note S1 (« ce membre ne peut pas être rattaché à un autre magasin si le magasin ne l'a pas encore supprimé ») : elle vise un compte d'une **autre société**, puisque V1-1 accepte la multi-affectation dans la société (« l'idée est bonne ») ; dites-le si vous la lisiez autrement.
- **(a) Recommandée** : prise de poste exclusive : une personne (vendeur, responsable, administrateur ou propriétaire) n'encaisse dans un magasin que si sa caisse personnelle est fermée dans tous les autres ; la caisse de B affiche « Fermez d'abord votre caisse de A » ; contrôle relu sous verrou. Texte (puce « Comptes et équipe ») : « Une personne n'encaisse que dans un magasin à la fois : elle ferme sa caisse d'un magasin avant d'encaisser dans un autre. »
- (b) Pas d'exclusivité : un vendeur de A et B peut encaisser dans les deux le même jour sans fermer sa caisse ; chaque vente reste tracée sur son magasin.

**Q3. Quand le SIREN se verrouille-t-il ?** (lot A2)
Votre note S11 dit : « Une fois ces vérifications réalisées et le premier document fiscal émis, le SIREN est verrouillé définitivement. »
- **(a) Recommandée** : à la validation de la société. La validation porte sur ce SIREN (identité du dirigeant, courrier, SIRET réservés) ; le changer revient à tout revalider. Comme aucun document fiscal ne peut exister avant la validation, le verrou tombe toujours avant le premier document. Texte : « Le SIREN se corrige librement avant la validation de la société et se verrouille à sa validation. »
- (b) Au premier document fiscal : entre la validation et le premier document, le propriétaire peut encore corriger le SIREN, ce qui remet la société « à vérifier » (SIRET libérés, périodes closes, vérifications à refaire). Texte : « Le SIREN se corrige librement jusqu'au premier document fiscal ; une correction après la validation remet la société en vérification. »
En attendant, le lot A2 applique (a).

**Q4. Combien de temps garder les entrées du journal d'audit qui ne concernent pas les opérations ?** (lot A2 ; les durées sont des paramètres)
Votre note S13 demande des durées « selon les obligations légales applicables à chaque catégorie, sans conserver inutilement des données personnelles ».
- **(a) Recommandée** : opérations commerciales et fiscales, réglages et procédures : 10 ans ; gestion des accès (comptes, droits, mots de passe donnés, suspensions, rattachements, transferts) : 5 ans (prescription de droit commun, supposé ; les droits restent 10 ans au journal des événements de la caisse) ; vérifications de la société et codes de courrier : 5 ans après la fin de la relation ; IP et navigateur : 1 an. Texte : ajouter « les entrées d'audit de gestion des accès, après 5 ans, et celles de la vérification d'une société, 5 ans après la fin de la relation » à la liste des effacements.
- (b) Tout 10 ans, sauf IP et navigateur (1 an). Texte : aucun ajout.
Faites confirmer ces durées par votre conseil si possible : elles ne sont pas sourcées sur un texte officiel relu.

**Q5. Une adresse e-mail prise par une autre société, à l'insu de la personne** (lot A4)
Avec la création directe des comptes, une société peut créer un compte avec l'adresse de quelqu'un qui n'a rien demandé ; comme une adresse ne sert jamais deux fois, cette personne ne pourra plus inscrire sa propre société avec son adresse, et si elle fait « Mot de passe oublié » elle se retrouve membre d'une société inconnue, dont elle ne peut pas sortir seule (note S1). Déjà prévu dans tous les cas : 20 comptes créés au plus par jour et par société ; un compte retiré peut créer sa propre société ; une adresse changée dans un profil n'est prise qu'après confirmation.
- **(a) Recommandée** : un compte créé par une société et **jamais utilisé** peut être refusé par la personne : à sa première connexion (y compris par « Mot de passe oublié »), elle voit « Ce compte a été créé pour vous par {société}. L'accepter ou le refuser » ; refusé, il est retiré (l'auteur est prévenu) et la personne peut créer sa propre société avec ce compte. Texte : « Un compte créé pour une personne qui ne s'en est jamais servie peut être refusé par elle à sa première connexion ; il est alors retiré. »
- (b) Rien de plus : la personne doit demander à la société de la retirer, ou passer par l'éditeur.

**Q6. Qui est l'émetteur de l'avoir de régularisation après une correction exceptionnelle du SIREN ?** (lot A7 ; cas rare)
La facture d'origine porte l'ancien SIREN, figé. L'avoir qui l'annule est émis après la correction.
- **(a) Recommandée** : l'avoir porte l'émetteur figé de la facture d'origine (ancien SIREN), avec la mention « Avoir de régularisation émis après correction de l'identité du vendeur (SIREN correct : Y) » ; la facture rectificative porte le nouveau SIREN. L'avoir et la facture d'origine forment ainsi une paire cohérente pour la plateforme. À faire confirmer par l'expert-comptable de la société concernée le jour où le cas se présente.
- (b) L'avoir porte le nouveau SIREN, avec la référence de la facture d'origine.
En attendant, l'examen et la correction du SIREN sont construits ; l'outil de régularisation ne l'est pas.

**Q7. Une fermeture temporaire produit-elle des clôtures d'exercice et une archive finales ?** (étape 3)
Votre choix S9 prévoit, à la fermeture, « clôtures journalière, mensuelle et de l'exercice à la date de fermeture, archive signée finale », et S8 permet de rouvrir. Un exercice appartient à la société : le clôturer pour un magasin fermé quelques semaines puis rouvert couperait son exercice en deux.
- **(a) Recommandée** : clôtures et archive finales seulement pour une fermeture définitive (cessation, cession, résiliation de la société) ; pour une fermeture temporaire, seules les journées sont clôturées et les Z s'arrêtent, les clôtures mensuelle et de l'exercice venant à leurs dates normales. Une réouverture après cessation garde les clôtures finales déjà faites. Texte : « À la fermeture définitive d'un magasin (cessation, cession), clôtures mensuelle et de l'exercice et archive signées finales ; une fermeture temporaire ne clôture que ses journées. »
- (b) Comme S9 à la lettre, pour toute fermeture : à la réouverture, l'exercice reprend avec une seconde clôture d'exercice. Texte : « À toute fermeture d'un magasin, clôtures mensuelle et de l'exercice et archive signées finales. »
Aucun lot de l'étape 1 n'en dépend (les clôtures mensuelles et d'exercice arrivent à l'étape 3).

---

## 16. Actions de l'éditeur (pas des questions)

- Créer la clé de l'API Sirene (portail-api.insee.fr) : `INSEE_SIRENE_CLE`.
- Ouvrir un accès à l'API du registre national des entreprises (INPI) : `INPI_RNE_IDENTIFIANT`, `INPI_RNE_MOT_DE_PASSE`.
- Choisir un prestataire de vérification d'identité certifié PVID (niveau substantiel) et un prestataire d'envoi recommandé (courrier AR par API, lettre simple, LRE qualifiée), signer les contrats **et leurs clauses de sous-traitance (art. 28 RGPD)**, fournir clés et secrets de rappel ; jusque-là, implémentations factices en développement, et en production validation manuelle (pièces et courrier).
- Faire rédiger l'**analyse d'impact** (AIPD) de la vérification d'identité (traitement biométrique) avant la mise en service des voies B1 et B2, et valider les mentions d'information (propriétaire, dirigeant sollicité).
- Déclarer les opérateurs (`EDITEUR_OPERATEURS`) et l'adresse d'alerte (`PILIQI_EDITEUR_EMAIL`).
- Générer la clé du journal d'audit (`JOURNAL_AUDIT_CLE`, `JOURNAL_AUDIT_CLE_ID`), conservée comme la clé fiscale.
- Pour la campagne : la machine dédiée avant l'étape 15 [T2].

---

## Annexe A : traçabilité des réponses

| Réponse | Sections | Lots |
|---|---|---|
| S1 (création directe ; note) | 1.3 `users`, `demandes_rattachement` ; 3.4 ; 3.5 ; 4.8 ; 12 ; Q2, Q5 | A4 |
| S2 | 6 ; 12 | lot 21, étape 2 |
| S3 | 1.3 `societes` ; 5.4 ; 5.5 ; 12 | A2 |
| S4 | 1.5 `FormeJuridique` ; 5.5 ; 12 | A2 |
| S5 | 1.3 ; 5.4 (mention ajoutée automatiquement) ; 5.5 ; 12 | A2 |
| S6 | 7 ; 3.1 ; 3.3 (Sirene synchrone) ; 12 | A2, A3 |
| S7 | 7.5 ; 9.3 (réclamation, lecture seule) ; 9.6 ; 12 | A3, A7, étape 13 |
| S8 | 3.3 ; 5.7 ; 10 ; 12 | A5 |
| S9 | 5.7 ; 12 ; Q7 | A1-A5, étape 3 |
| S10 | 3.3 ; 9.7 ; 12 (étape 7) | A5, A7 |
| S11 | 7.7 ; 9.4 ; 12 ; Q3, Q6 | A2, A7 |
| S12 | 4 ; 11 | A4 |
| S13 | 1.3 (journal, traces, JET) ; 9.8 ; 12 ; Q4 | A1, A2, A7 |
| S14 | 5.3 ; 9.5 ; 12 | A7 |
| V1-1 | 3.6 ; 8.6 ; Q2 | A4 |
| V1-2 | 1.3 `affectations` ; 3.4 (création en une fois) ; 4.3 | A4 |
| V1-3 à V1-12 | 3.8, 3.9, 4.4, 9.6, 12 | A2 à A5 |
| V2 | 12 (devis) | A0 |
| T1 à T6 | 12 ; 13 | A0, lot 21, campagne |
| R1 | 8.1 ; 12 | A6 |
| R2 | 8.2 ; 12 ; Q1 | A6 |
| R3 | 8.3 ; 12 | A6 |
| R4 | 8.4 ; 12 | A0, A1 |
| R5 | 8.5 ; 12 | A1 |

## Annexe B : correspondance des tests touchés

Légende : **C** conservé (chemin adapté, mêmes assertions) ; **R** réécrit, garantie au moins aussi forte ; **A** retiré, règle abolie par l'éditeur (nommée). Noms relus à `b2bce46`.

`tests/Feature/Filament/IdentiteMagasinTest.php` → `Societe/IdentiteSocieteTest`, `Societe/MagasinResourceTest`, `Filament/CoordonneesMagasinTest` (A2, A5)
- R `test_le_proprietaire_enregistre_l_identite_legale_et_les_coordonnees_du_magasin` → `IdentiteSocieteTest::test_le_proprietaire_enregistre_l_identite_legale_de_la_societe` + `CoordonneesMagasinTest::test_le_responsable_avec_les_parametres_regle_l_e_mail_et_le_telephone_du_magasin`.
- R `test_un_membre_sans_les_parametres_du_magasin_n_accede_pas_a_l_identite` → `PanneauSocieteTest::test_un_membre_n_ouvre_pas_l_espace_societe` (A4).
- R `test_un_administrateur_a_qui_le_proprietaire_coche_les_parametres_accede_a_l_identite` → `PanneauSocieteTest::test_un_administrateur_avec_parametres_ouvre_l_identite` et `…sans_parametres_n_ouvre_ni_l_identite_ni_la_fiscalite` (A4).
- C `test_le_proprietaire_ouvre_la_page_de_l_identite`, `test_une_saisie_invalide_est_refusee_sans_rien_enregistrer`, `test_le_capital_social_s_enregistre_puis_se_vide`, `test_l_encadre_liste_les_mentions_qui_manquent_pour_facturer_un_professionnel`, `test_un_champ_vide_est_enregistre_sans_valeur`, `test_le_numero_de_tva_s_enregistre_sans_separateurs` → `IdentiteSocieteTest`.
- C `test_un_magasin_de_la_martinique_propose_8_5_pour_cent_sur_un_service` → `ParametresFiscauxSocieteTest`.
- R `test_le_siret_d_un_autre_magasin_meme_supprime_est_refuse` → `CreerEtablissementActionTest::test_un_siret_reserve_ne_sert_jamais_a_un_autre_magasin`.
- C `test_le_magasin_reenregistre_son_propre_siret`, `test_le_code_saisi_en_minuscules_s_enregistre_en_majuscules`, `test_le_pays_saisi_en_minuscules_s_enregistre_en_majuscules`, `test_un_code_refuse_n_est_pas_enregistre`, `test_le_code_ne_change_plus_apres_un_premier_devis_numerote`, `test_un_premier_produit_ne_rend_pas_le_code_definitif`, `test_un_code_devenu_definitif_pendant_la_saisie_n_est_pas_enregistre`, `test_un_magasin_sans_vente_garde_son_code_modifiable_apres_les_commandes_de_la_nuit`, `test_le_premier_document_d_un_autre_magasin_ne_rend_pas_le_code_definitif`, `test_une_valeur_prise_pendant_l_enregistrement_est_refusee_comme_par_la_validation`, `test_un_autre_doublon_n_est_pas_attribue_au_code` → `MagasinResourceTest` (A5 ; entre A2 et A5, ces tests ciblent `ModifierEtablissementAction` directement).
- R `test_l_identite_reste_celle_du_magasin_actif` → `CoordonneesMagasinTest::test_les_coordonnees_restent_celles_du_magasin_actif` et `IsolationDesSocietesTest::test_l_espace_d_une_autre_societe_repond_404`.
- A (rattachement par SIREN et magasin principal abolis, Q1 et Q2 de `reponses-editeur.md`) : `test_un_siren_deja_porte_ne_s_enregistre_qu_avec_les_reglages_du_principal_de_la_societe_sans_les_reveler` → garanties reprises par `InscriptionSocieteTest::test_l_ecran_repond_pareil_que_le_siren_l_adresse_ou_le_siret_soient_libres_ou_non` et `IsolationDesSocietesTest::test_aucun_reglage_d_une_autre_societe_n_est_lu_ni_revele` ; `test_le_onzieme_essai_dans_l_heure_de_prendre_le_siren_d_une_societe_est_refuse` → `InscriptionSocieteTest::test_le_quatrieme_essai_dans_l_heure_pour_un_meme_siren_et_une_meme_adresse_est_refuse` ; `test_un_magasin_rejoint_une_societe_dont_le_principal_n_a_pas_encore_choisi_son_regime`, `test_un_siren_deja_porte_s_enregistre_avec_les_reglages_du_principal_de_la_societe`, `test_un_reglage_commun_que_le_principal_n_a_pas_se_laisse_vide`, `test_l_encadre_dit_les_reglages_a_aligner_sur_le_principal`, `test_un_magasin_laisse_derriere_enregistre_son_identite_avec_les_valeurs_du_principal`, `test_un_magasin_laisse_derriere_n_efface_pas_son_siren` → `IdentiteSocieteTest::test_le_siren_ne_se_saisit_dans_aucun_ecran_de_magasin` et `test_aucun_magasin_n_a_de_privilege_sur_les_reglages`.

`tests/Feature/Filament/ParametresFiscauxTest.php` → `Societe/ParametresFiscauxSocieteTest` : C les tests du régime, du paramètre inchangé et de la clé absente (dans aucun magasin) ; R le journal de chaque paramètre (dans chaque magasin) ; R les tests d'accès → `PanneauSocieteTest` ; A (magasin principal, Q2) `test_un_autre_magasin_de_la_societe_n_enregistre_que_les_parametres_du_principal`, `test_un_autre_magasin_choisit_son_regime_tant_que_le_principal_n_a_pas_choisi_le_sien`, `test_le_principal_change_le_regime_et_l_autre_magasin_voit_ce_qu_il_doit_aligner` → `test_le_regime_de_la_societe_s_applique_a_tous_ses_magasins`.

`tests/Feature/Tenancy/CreerMagasinActionTest.php` → `Societe/CreerSocieteActionTest`, `Societe/CreerEtablissementActionTest` : R `test_creer_un_magasin_rattache_le_proprietaire_avec_tous_les_droits` → `test_le_proprietaire_a_tous_les_droits_dans_chaque_magasin_sans_affectation` ; C les tests du code, de la suppression impossible et du client comptoir ; R les deux tests des rôles Spatie → `AutorisationsDesRolesTest::test_aucun_role_n_existe_et_le_proprietaire_est_implicite` et `IsolationDesSocietesTest::test_les_autorisations_de_societe_ne_valent_jamais_hors_de_sa_societe` (A4) ; R `test_le_proprietaire_a_les_parametres_du_magasin_mais_pas_ladministrateur` → `AutorisationsDesRolesTest::test_le_modele_responsable_n_a_pas_les_parametres`.

`tests/Feature/Tenancy/RegisterMagasinPageTest.php` → `Societe/InscriptionSocieteTest` : R `test_linscription_cree_le_compte_et_son_magasin_en_une_fois` → `test_la_confirmation_cree_la_societe_a_verifier_son_premier_magasin_et_son_proprietaire_en_une_fois` ; C les trois tests du code proposé ; R `test_un_code_refuse_n_ouvre_ni_compte_ni_magasin` (demande et confirmation) ; R `test_une_adresse_deja_utilisee_sous_une_autre_casse_est_refusee` → `test_une_adresse_deja_utilisee_recoit_un_e_mail_et_aucune_demande` ; R `test_un_code_pris_par_une_inscription_simultanee_est_refuse_sans_laisser_de_compte` → même cas à la confirmation.

`tests/Feature/Tenancy/SeedMagasinRolesActionTest.php` → `Authorization/AutorisationsDesRolesTest` (A4) : R `test_seul_le_role_du_proprietaire_existe_et_il_porte_toutes_les_autorisations` → `test_le_proprietaire_a_implicitement_toutes_les_autorisations` ; A `test_des_autorisations_creees_entre_temps_par_une_autre_inscription_sont_reprises` (S12 retire la table des permissions : la course n'existe plus) ; C les quatre tests des modèles et de la clôture ; R `test_l_administrateur_recoit_d_office_tout_sauf_les_parametres_du_magasin` → `test_le_responsable_recoit_d_office_tout_sauf_les_parametres_et_la_vente_sans_stock`. Entre A2 et A4, la classe de test reste, sans les tests du rôle propriétaire (R vers `test_le_proprietaire_a_tous_les_droits_dans_chaque_magasin_sans_affectation`).

`tests/Feature/Tenancy/AccesAuxPanneauxTest.php` : R `test_le_proprietaire_d_un_magasin_n_ouvre_aucun_autre_panneau` → `test_le_proprietaire_ouvre_les_panneaux_app_et_societe_et_aucun_autre` ; C les trois tests du magasin suspendu, de l'absence de magasin et de l'adresse sans uuid ; R `test_un_membre_suspendu_n_ouvre_pas_ce_magasin` → `test_un_compte_suspendu_n_ouvre_aucun_magasin`.

`tests/Feature/Tenancy/CreerMembreMagasinActionTest.php` et `tests/Feature/Filament/MembreEquipeResourceTest.php` → `Societe/MembresTest`, `MotDePasseProvisoireTest`, `AutorisationsDesMembresTest`, `AdministrateursTest`, `RattachementTest` (A4)
- R `test_le_proprietaire_cree_un_vendeur_avec_lemail_deja_verifie`, `test_le_proprietaire_cree_un_vendeur_depuis_le_panneau` → `MembresTest::test_le_proprietaire_cree_un_vendeur_affecte_a_deux_magasins_avec_un_mot_de_passe_provisoire`.
- R `test_impossible_dattribuer_le_role_proprietaire_via_la_gestion_dequipe`, `test_attribuer_le_role_proprietaire_via_creation_membre_affiche_une_notification`, `test_modifier_le_role_dun_membre_en_proprietaire_affiche_une_notification` → `MembresTest::test_aucune_creation_ni_modification_ne_donne_la_propriete`.
- C `test_sans_liste_un_vendeur_recoit_exactement_le_modele_de_son_role`, `test_avec_une_liste_le_membre_n_a_que_les_autorisations_cochees`, `test_une_autorisation_inconnue_est_refusee`, `test_modifier_un_membre_avec_une_autorisation_inconnue_est_refuse_sans_rien_changer`, `test_le_proprietaire_coche_et_decoche_les_autorisations_d_un_nouveau_vendeur`, `test_changer_de_role_coche_le_modele_du_nouveau_role`, `test_le_proprietaire_peut_cocher_chaque_autorisation_sous_son_libelle`, `test_le_proprietaire_coche_une_autorisation_de_plus_pour_un_vendeur`, `test_changer_le_statut_sans_changer_le_role_garde_les_autorisations_cochees` (statut du compte), `test_une_modification_s_inscrit_en_un_seul_evenement_avec_les_droits_avant_et_apres`, `test_un_mot_de_passe_trop_court_est_refuse_a_la_creation`, `test_l_adresse_d_un_nouveau_membre_s_enregistre_en_minuscules_sans_espaces`.
- R `test_un_membre_qui_gere_l_equipe_cree_un_compte_sans_les_autorisations_qu_il_n_a_pas`, `test_un_membre_qui_gere_l_equipe_cree_un_compte_qui_n_a_pas_plus_d_autorisations_que_lui`, `test_un_administrateur_cree_un_membre_avec_le_seul_modele_de_son_role` → `AutorisationsDesMembresTest::test_un_administrateur_ne_coche_que_ce_qu_il_a_dans_ce_magasin`.
- R `test_seul_le_proprietaire_coche_les_autorisations_d_un_nouveau_membre`, `test_un_autre_membre_que_le_proprietaire_ne_change_ni_le_role_ni_les_autorisations`, `test_un_administrateur_ne_change_pas_le_role_d_un_membre`, `test_un_administrateur_ne_change_pas_les_autorisations_d_un_membre` → `test_un_simple_membre_ne_coche_rien` et `test_un_administrateur_ne_modifie_pas_un_membre_plus_autorise_que_lui_mais_peut_couper_son_acces_dans_sa_portee` (« seul le propriétaire coche » remplacé par l'éditeur, Q1 de `reponses-editeur.md`).
- C `test_les_autorisations_donnees_dans_un_magasin_n_existent_pas_dans_un_autre` → `IsolationDesSocietesTest::test_les_autorisations_cochees_dans_a_n_existent_pas_dans_b`.
- R `test_un_compte_ne_se_rattache_jamais_a_un_second_magasin` → `IsolationDesSocietesTest::test_la_base_refuse_d_affecter_un_compte_au_magasin_d_une_autre_societe` et `RattachementTest::test_un_compte_encore_rattache_ailleurs_ne_peut_pas_etre_rattache` (A1 remplacée par V1-1 ; garantie entre sociétés, note S1).
- R `test_ladresse_du_compte_dun_autre_magasin_est_refusee_sans_toucher_a_ce_compte`, `test_ladresse_du_compte_dun_autre_magasin_est_refusee_sur_le_champ_email`, `test_une_adresse_deja_utilisee_sous_une_autre_casse_est_refusee` → `MembresTest::test_une_adresse_deja_utilisee_recoit_une_reponse_neutre_et_aucun_compte_n_est_touche`.
- R `test_recreer_un_membre_suspendu_demande_de_modifier_son_statut` → `MembresTest::test_creer_un_compte_suspendu_de_la_societe_propose_de_le_reintegrer`.
- R `test_changer_le_role_dun_membre_resynchronise_ses_permissions_spatie` → `AutorisationsDesMembresTest::test_le_role_ne_fait_que_precocher` (S12).
- R `test_reinitialiser_le_mot_de_passe_dun_membre`, `test_le_proprietaire_donne_un_nouveau_mot_de_passe_a_un_vendeur`, `test_un_nouveau_mot_de_passe_trop_court_est_refuse` → `MotDePasseProvisoireTest::test_le_mot_de_passe_provisoire_ne_se_donne_qu_a_un_compte_jamais_connecte_que_l_on_domine` et `test_reinitialiser_un_membre_deja_connecte_lui_envoie_un_lien_et_l_auteur_ne_connait_pas_le_mot_de_passe` ; `test_reinitialiser_son_propre_mot_de_passe_depuis_lequipe_est_refuse`, `test_un_administrateur_ne_change_pas_son_propre_mot_de_passe_depuis_lequipe` → `test_personne_ne_se_donne_un_mot_de_passe_provisoire_depuis_les_membres` ; `test_un_autre_membre_que_le_proprietaire_ne_donne_pas_de_mot_de_passe_a_un_membre_plus_autorise_que_lui`, `test_un_membre_qui_gere_l_equipe_ne_donne_pas_de_mot_de_passe_a_un_membre_plus_autorise_que_lui`, `test_un_administrateur_donne_un_nouveau_mot_de_passe_a_un_membre_dont_il_a_toutes_les_autorisations`, `test_reinitialiser_le_mot_de_passe_du_proprietaire_est_refuse`, `test_un_membre_qui_voit_seulement_lequipe_ne_change_pas_le_mot_de_passe_dun_administrateur_ni_ne_le_retire` → `test_jamais_pour_le_proprietaire_ni_pour_un_administrateur_sauf_par_le_proprietaire` et `test_le_mot_de_passe_provisoire_ne_se_donne_qu_a_un_compte_jamais_connecte_que_l_on_domine`.
- C `test_retirer_un_membre_ne_supprime_pas_son_compte_mais_retire_lacces`, `test_retirer_un_membre_retire_ses_autorisations`, `test_retirer_un_membre_efface_ses_autorisations`, `test_retirer_un_membre_efface_ses_notifications`, `test_suspendre_un_membre_efface_ses_notifications`, `test_impossible_de_retirer_le_proprietaire` → `AutorisationsDesMembresTest`.
- C `test_un_membre_qui_gere_l_equipe_n_ouvre_pas_sa_propre_ligne`, `test_le_proprietaire_napparait_pas_comme_modifiable_dans_la_liste`, `test_un_vendeur_sans_permission_ne_peut_pas_acceder_a_la_gestion_dequipe` → `MembreResource` (panneau société).

`tests/Feature/Authorization/MembreEquipePolicyTest.php` → `Authorization/MembrePolicyTest` : C les deux tests (avec `societe.membres.gerer` et la portée).

**Tests de politiques et usages de `RoleMagasin::Administrateur` (lot A4)** — `AvoirPolicyTest`, `ClientPolicyTest`, `DevisPolicyTest`, `FournisseurPolicyTest`, `PaiementPolicyTest`, `ProduitPolicyTest`, `ServicePolicyTest`, `FacturePolicyTest`, `StockLedgerServiceTest`, `EnregistrementFiscalResourceTest` (42 usages dans 16 fichiers, dont les 6 fichiers d'équipe ci-dessus) : C tous ; chaque `RoleMagasin::Administrateur` (modèle de magasin) devient `RoleMagasin::Responsable`, dont les cases sont identiques à l'ancien administrateur de magasin [V1-3] ; les autres tests de politiques (`ClotureCaissePersonnellePolicyTest`, `ClotureCaissePolicyTest`, `EnregistrementFiscalPolicyTest`, `FactureAchatPolicyTest`) : C. `TestCase::membre(Magasin, RoleMagasin, ?array)` [`tests/TestCase.php:61-69`] garde sa signature et crée une affectation et ses cases au lieu des rôles Spatie : aucun appelant ne change.

**Lectures de `regime_tva` sur un magasin dans les tests (lot A2)** — `EmissionSigneeTest`, `JournalEvenementsTest`, `RegistreFiscalTest`, `CreerFactureActionTest`, `EmetteurFigeTest`, `EmettreFactureActionTest`, `FacturePdfGeneratorTest`, `FranchiseEnBaseTest`, `NatureOperationTest`, `SoumettreEReportingPeriodiqueTest`, `CaisseTest`, `DevisResourceTest`, `EnregistrementFiscalResourceTest`, `FactureResourceTest`, `ProduitResourceTest`, `MagasinEnLectureSeuleTest` (91 lectures dans 20 fichiers, dont les 4 fichiers du principal traités ci-dessus) : C ; chaque réglage s'écrit sur `$magasin->societe` (aide `reglerLaSociete(Magasin, array)` de `TestCase`), assertions inchangées.

`tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php` (21) : C tous, paramétrés aussi par les statuts de société, `refusee_le` et `suspendu_le` ; R `test_l_equipe_reste_consultable_et_ouverte_aux_nouveaux_comptes` et `test_un_acces_se_coupe_toujours` au niveau de la société (A4).

`tests/Feature/Tenancy/ReglagesCommunsDeLaSocieteTest.php` (13) : A (magasin principal, alignement, rattachement par SIREN abolis, Q1 et Q2) : `test_la_lecture_des_autres_magasins_se_limite_aux_reglages_communs_de_leur_siren` → `IsolationDesSocietesTest::test_aucun_reglage_d_une_autre_societe_n_est_lu_ni_revele` ; `test_un_magasin_d_un_autre_siren_ne_compte_pas` → `test_la_lecture_de_la_premiere_vente_ne_compte_que_les_magasins_reels_de_la_societe` ; `test_un_magasin_qui_prend_le_siren_d_une_societe_doit_avoir_tous_les_reglages_de_son_principal_sans_les_apprendre` → `InscriptionSocieteTest::test_la_confirmation_d_un_siren_deja_valide_ne_revele_rien_et_cree_une_societe_a_verifier` ; le test de comparaison sans casse ni séparateurs → sans objet (plus de comparaison entre magasins ; normalisation gardée par `test_le_numero_de_tva_s_enregistre_sans_separateurs`) ; `test_un_magasin_qui_a_les_reglages_du_principal_prend_le_siren_de_la_societe`, `test_seul_le_magasin_principal_change_un_reglage_commun`, `test_un_magasin_que_le_principal_a_laisse_derriere_lui_enregistre_ses_autres_parametres_et_s_aligne`, `test_le_principal_est_le_plus_ancien_magasin_du_siren_meme_s_il_a_saisi_le_siren_apres_les_autres`, `test_un_magasin_que_le_principal_a_laisse_derriere_lui_ne_quitte_pas_sa_societe`, `test_un_magasin_aligne_sur_son_principal_quitte_sa_societe`, `test_le_magasin_suivant_devient_principal_quand_le_principal_est_supprime`, `test_un_principal_ferme_reste_le_principal`, `test_un_reglage_different_du_principal_est_refuse` → `IdentiteSocieteTest::test_aucun_magasin_n_a_de_privilege_sur_les_reglages`.

`tests/Feature/Tenancy/DesignerMagasinPrincipalTest.php` (7) : A (désignation abolie, Q2) `test_le_magasin_designe_fixe_seul_les_reglages_communs_de_sa_societe`, `test_la_commande_dit_les_reglages_a_aligner_et_ne_designe_rien_sans_confirmation`, `test_un_changement_de_siren_efface_la_designation`, `test_un_magasin_designe_rejoint_une_societe_qui_a_deja_son_magasin_designe`, `test_un_magasin_sans_siren_n_est_pas_designe` → `test_aucun_magasin_n_a_de_privilege_sur_les_reglages` ; R `test_un_magasin_inconnu_est_refuse`, `test_un_texte_qui_n_est_ni_un_identifiant_ni_un_uuid_est_refuse` → `Editeur/ProceduresTest`.

`tests/Feature/Facturation/EmettreFactureActionTest.php` : A `test_un_magasin_non_aligne_sur_le_principal_de_sa_societe_n_emet_rien`, `test_un_magasin_aligne_de_nouveau_sur_le_principal_emet` → `ParametresFiscauxSocieteTest::test_une_emission_lit_le_regime_de_la_societe_apres_son_verrou`.

`tests/Feature/Filament/CaisseTest.php` : A `test_la_caisse_d_un_magasin_non_aligne_sur_le_principal_de_sa_societe_n_encaisse_pas` → même garantie ; C `test_une_vente_a_un_professionnel_est_refusee_tant_que_l_identite_du_magasin_est_incomplete`, renommé `…de_la_societe…` ; C `test_saisir_le_prix_ttc_dune_ligne_calcule_automatiquement_le_prix_ht` (propriétaire ; si Q1 (b) : R en nommant la réponse).

`tests/Feature/Filament/FactureResourceTest.php` : C `test_saisir_le_prix_ttc_dune_ligne_totalise_exactement_a_quantite_2` (si Q1 (b) : R).

`tests/Feature/Filament/ClotureCaisseResourceTest.php` (A1) : R `test_le_bouton_est_desactive_et_nomme_le_vendeur_tant_qu_il_n_a_pas_ferme_sa_caisse` → `test_le_bouton_reste_actif_et_la_fenetre_nomme_les_caisses_qui_seront_fermees_automatiquement` (R5) ; R `test_le_comptable_qui_encaisse_ferme_sa_caisse_et_debloque_la_cloture` → `test_la_cloture_ferme_d_office_la_caisse_du_comptable_qui_encaisse`.

`tests/Feature/Facturation/VerificateurCaissesPersonnellesTest.php` (11) : C tous.

`tests/Feature/CaisseSecurisee/RegistreFiscalTest.php` (A1) : R `test_journaliser_note_l_adresse_ip_et_le_navigateur_de_la_requete_http` → `test_journaliser_range_l_adresse_ip_et_le_navigateur_dans_la_trace` et `test_aucun_contenu_signe_ne_contient_d_adresse_ip_ni_de_navigateur` (S13). `JournalEvenementsTest`, `EmissionSigneeTest` : C, les assertions sur `donnees.ip` lues dans la trace.

`tests/Feature/Filament/EnregistrementFiscalResourceTest.php` (A1) : C `test_l_export_csv_ne_laisse_aucune_formule_venue_de_l_utilisateur` (l'agent utilisateur piégé vient de la trace ; même neutralisation de formule).

`tests/Feature/Filament/ModifierProfilTest.php` (A4) : C les dix tests ; R `test_un_membre_change_son_nom_et_son_adresse_avec_son_mot_de_passe_actuel` : le nom change tout de suite, l'adresse après confirmation (`test_une_nouvelle_adresse_ne_prend_effet_qu_apres_confirmation_et_l_ancienne_est_prevenue`).

`tests/Feature/Tenancy/TenantIsolationTest.php` : R les tests d'avoir et d'encaissement dans un autre magasin, avec `ouvrirUnAutreMagasin()`.

`tests/Feature/Facturation/EnvoyerDocumentJobTest.php` : C `test_un_envoi_ne_part_pas_si_le_magasin_a_ete_suspendu_entre_temps` + cas de la société ; R l'échec d'une relance automatique (propriétaire de la société) et l'expéditeur qui a quitté le magasin (`peutOuvrir`).

`tests/Feature/Facturation/MarquerFacturesEnRetardTest.php` : R l'alerte au propriétaire de la société.

`tests/Feature/Stock/StockLedgerServiceTest.php` : R les deux tests de l'alerte (propriétaire et personnes autorisées dans le magasin).

`tests/Feature/Tenancy/MandatPdpActionsTest.php` : C jusqu'à l'étape 2.

`tests/Feature/DatabaseSeederTest.php` : R message « créez une société depuis la page d'inscription ».

Les 508 appels de `CreerMagasinAction` dans 68 fichiers passent par `ouvrirUneSociete()` et `ouvrirUnAutreMagasin()` (A2), sans changer leurs assertions.

## Annexe C : constats des deux relectures de la v2

**Intégrés** (section) :
- Lot A1 de la v2 non exécutable (Spatie, propriétaire hors du pivot) → ordre refait, liste fermée de transition (14, A2).
- Table des vérifications nécessaire dès le socle → créée en A2 (1.3, 14).
- Cycle de vie et tests d'administrateurs avant les autorisations → A4 avant A5 (14).
- R5 nécessaire à la fermeture et à la démonstration → lot A1 (14).
- IP et navigateur signés dans les JET → `traces_evenements` (1.3, 5.1).
- `traces_audit` sans protection → `effacementApresExpiration` (1.3).
- Capital limité à sept formes → toute société commerciale, familles INSEE (1.5, 5.5, 12).
- Comptes suspendus à la révocation → lecture seule consultable (3.8, 9.3).
- Fin d'exercice calculée → tirée du procès-verbal, deux cas testés, rattrapage (9.5).
- Formule de validation et signaux → levée par signal, B4 ne lève rien (7.3, 7.4).
- Nouveau magasin créé malgré un Sirene négatif → contrôle synchrone (3.3).
- Suspension levée par fermeture et réouverture → `suspendu_le` à part (1.3, 3.3).
- Liste « exhaustive » incomplète → complétée (2.3, 12).
- Critère `withTrashed()` → restreint à `Magasin` et `User` (14).
- Mention EI à taper → ajoutée automatiquement, contrôle en mot entier (5.4).
- Administrateur créé en deux temps → `CreerAdministrateurAction` (3.4).
- « 10 ans sans purge » contredit → texte réécrit (12).
- Durées par catégorie non justifiées → table, catégories, expiration, Q4 (9.8, 15).
- Confirmation qui révèle un SIREN client → parcours identique (3.1).
- Texte des Z ambigu → réécrit (12).
- Réouverture le même jour → premier jour non clôturé (5.7).
- Paramètres SUPER PDP non spécifiés → étape 2 amendée (6.2, 12).
- README lignes 50 et 58 → réécrites (12).
- Annexe B incomplète → politiques, `RoleMagasin::Administrateur`, `regime_tva`, `TestCase::membre()`, tests nommés (B).
- Ordre d'imputation de la régularisation → exception écrite (12).
- Retrait d'un administrateur → repassé membre (3.4).
- Mot de passe oublié et drapeau ; compte retiré qui crée sa société → 3.4, 3.5.
- SIREN réel dans l'en-tête de la démonstration → nul (5.1).
- Société résiliée qui revient → réactivation (10).
- Base périmée, fichiers omis, ordre des contrôles → `b2bce46`, liste complétée, 8.6.
- Import du catalogue et inventaire d'ouverture (S10) → étape 7 (12).
- Une migration par table → 1.3, 1.4.
- Couverture de R3 → étendue, le reste justifié par l'état et testé (8.3).
- Dirigeant personne morale → remontée (7.3).
- Administrateur global et verrous → tous les magasins (4.8).
- T5 « que l'éditeur peut contester » → 12.
- B4 suffisant seul → toujours avec C (7.3).
- Réclamation inexécutable → aucune inscription refusée par une validation concurrente, réclamation (7.3, 9.3).
- Courrier vers une adresse fournie en diffusion partielle → RNE par l'API, LRE ou éditeur (7.4, 7.5).
- Relais de la vérification d'identité → session liée, consentement nominatif, lettre d'information (7.3).
- Abus des inscriptions factices → courrier réservé aux identités vérifiées, limites par SIREN, signal limité aux candidats ayant prouvé leur qualité, limite d'inscription par couple (3.1, 7.4, 7.5).
- PDF brouillons à l'identité réelle → aucun PDF avant validation (7.8).
- Usurpation par mot de passe provisoire → lien pour les comptes déjà connectés, 72 h (3.4).
- Qualité perdue au transfert → `qualite_verifiee_le` exigée (3.4, 4.4, 5.10).
- Démonstration contraire à ECO-01 → JET 100/105, notice et attestation révisées (5.8).
- Exercice coupé par une fermeture temporaire → Q7 (5.7, 15).
- Double paiement déclaré à la régularisation → imputation non déclarée ; émetteur en Q6 (9.4).
- Jeton perdu au rechargement → jeton du panier persistant (8.3).
- Jeton non lié à la demande, actions imbriquées → empreinte, action externe seule (8.3).
- RGPD de la vérification → pièces chiffrées effacées, consentement, AIPD, art. 28 et 14 (1.3, 7.6, 16).
- Chaînage d'audit sur la ligne société → chaînes par magasin, tête verrouillée en dernier (1.3, 4.9).
- Contradiction sur la suspension automatique par Sirene → aucun blocage après validation (7.10).
- Confirmation de stock périmée, rectificative, ordre des verrous → 8.1, 4.9.
- Définition d'un changement de dérogation → 8.2.
- `actions_traitees` jamais purgée → 30 jours (1.3).
- Codes de magasin après révocation → réservés par SIREN (2.4, 5.6).
- Domination à vide, portée du tableau de bord, JET 130 à la suspension → 4.3, 2.3, 3.4.
- Droits implicites d'un nouveau magasin → JET 130 (3.3).
- Clés HMAC et tête de chaîne → clé dédiée avec `cle_id`, tête ancrée ; codes par `Hash::make` (1.3).
- Propriété statique de Filament et `configureUsing` globaux → classe de base, garde du panneau (2.4, 3.2).
- Double contrôle des opérateurs → opérateurs authentifiés par secret (9.1).
- Bac à sable SUPER PDP → correspondance configurée hors production (6.1).
- Cession par un administrateur, libellé du montant → propriétaire seul, libellé prudent, annulation (3.3, 9.7).
- Validation bloquée par un magasin fermé → seuls les magasins actifs (7.3).
- Version du BOFiP → 2026-03-25 citée (5.10).

**Écartés ou transformés** :
- « Placer A3 juste après A1 sans liste de transition » : retenu sous la forme d'un ordre refait **et** d'une liste fermée ; garder le propriétaire dans le pivot jusqu'à A4 aurait doublé la source de vérité.
- « Prise de poste exclusive imposée » (V1-1) : c'est une règle métier nouvelle ; soumise (Q2) au lieu d'être tranchée.
- « Verrou du SIREN au premier document » (S11, Q3) : interprétation soumise (Q3), (a) appliquée en attendant comme option la plus stricte.
- « Limiter la démonstration à 60 jours et 50 documents par jour » : écarté ; les données de démonstration sont signées, conservées, contrôlées et sans identité réelle ; la fermeture après 30 jours d'inactivité suffit à ne pas produire des Z sans fin.
- « Purger les sociétés jamais validées après 12 mois » : renvoyé à l'étape 9 (RGPD), car le statut fiscal des données de démonstration doit d'abord être décidé.
- « Squat d'adresses e-mail » : mesures sans changement de règle intégrées (limite de créations, confirmation d'adresse, compte retiré qui crée sa société) ; le refus d'un compte jamais utilisé touche A1 et la note S1 : soumis (Q5).
- « Ne créer des membres que dans une société validée » : écarté, la démonstration doit permettre l'entraînement des équipes avant la validation [S6 note].
- « Paniers de caisse non persistants » (relecture 2, R3) : constat inexact (`paniers_caisse` existe, `2026_10_14_100003`) ; la conclusion est retenue en s'appuyant sur ce panier.
- « Contrôle légal de durée de l'exercice transitoire codé en dur » : écarté (règle légale non relue) ; validation obligatoire par l'expert-comptable, inscrite.
- « Clé HMAC dédiée pour les codes de courrier » : remplacé par `Hash::make`, plus simple et sans rotation.

---

*Sources web citées (extraits, textes officiels non relus en entier)* : https://www.insee.fr/fr/information/2028129 ; https://static.insee.fr/api-sirene/Insee_API_publique_modalites_connexion.pdf ; https://www.insee.fr/fr/information/9019311 ; https://www.data.gouv.fr/dataservices/api-sirene-dont-unites-a-diffusion-partielle ; https://www.insee.fr/fr/information/1896455 ; https://www.sirene.fr/static-resources/htm/v_sommaire_311.htm ; https://www.inpi.fr/sites/default/files/2025-06/documentation%20technique%20API%20formalit%C3%A9s_v4.0.pdf ; https://www.topograph.co/guides/business-registers-in-france/extrait-rne-in-france ; https://cyber.gouv.fr/sites/default/files/2022-08/cert-serv-portees_portees-de-certification-des-services_v1.0%5B1%5D.pdf ; https://cyber.gouv.fr/lenvoi-recommande-electronique ; https://www.marche-public.fr/Marches-publics/Textes/Codes/CPCE/cpce-article-L100.htm ; https://www.lemondeinformatique.fr/actualites/lire-franceconnect-s-ouvre-timidement-au-secteur-prive-79204.html ; Légifrance R123-237 (LEGIARTI000045710304), R123-238 (LEGIARTI000006259054), art. 242 nonies A ann. II CGI (LEGIARTI000050811276), R526-27 (LEGIARTI000045697814) ; BOFiP BOI-TVA-DECLA-30-10-30 (version du 25/03/2026), BOI-LETTRE-000242, BOI-TVA-DECLA-30-20-20-20 ; CNIL délibérations 2021-122 et 2022-100 (via `scratchpad/societe/web/juridique/notes.md`).
