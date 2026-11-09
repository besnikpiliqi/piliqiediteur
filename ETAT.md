# État du projet (généré automatiquement)

Mis à jour le 09/11/2026 à 23:01 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `6c7b3d1`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
6c7b3d1 Relecture du lot QA des outils de qualité
09225fa Enregistre les scores de mutation des calculs et des droits et monte les seuils de couverture (lot QA 4/4)
aedb582 Tue les mutants survivants des calculs et des droits par des tests et un code simplifié (lot QA 3/4)
53beb37 Plafonne les requêtes des espaces de la société et du cabinet et limite la mutation aux calculs et aux droits (lot QA 2/3)
1b34f61 Ajoute les outils de qualité et met la CI sur PostgreSQL seulement (lot QA 1/2)
0d37396 Met à jour six paquets Symfony qui portaient des failles connues
c7b1ec3 Relecture du lot EX de la série 8
938db39 Ajoute le téléchargement des documents par période (lot EX 2/2)
3c83ee0 Joint la pièce du fournisseur aux achats saisis à la main (lot EX 1/2)
074eaee Relecture du lot TR de la série 8
d57bdab Garde la TVA et le PU d'achat qu'écrit la facture du fournisseur
0afc14c Émet et encaisse d'un geste, ajoute le reçu de paiement et place les règlements à gauche des totaux
1d3278a Relecture du lot NAV-1 des tests en navigateur
523e74f Balaye chaque panneau rôle par rôle et ajoute les premiers parcours en navigateur (lot NAV-1 2/2)
9053757 Outille les tests en navigateur avec Pest 4 et Playwright (lot NAV-1 1/2)
92fd524 Relecture du lot SL de l’erreur 419 des fenêtres de recherche
b06c922 Corrige l'erreur 419 des fenêtres de recherche de clients et de produits (lot SL)
f9b669c Relecture du lot A7b des suites du lot A7
81de7e8 Complète les tests du lot A7b et indexe le journal d'audit par action
0d2b6a3 Régularise les factures après une correction de SIREN et corrige le SIREN d'un cabinet (lot A7b)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M README.md
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Domain/Achats/Models/TauxAvoirFournisseur.php
 M app/Domain/Achats/Models/TauxFactureAchat.php
 M app/Domain/Achats/Support/GardesDesPiecesDuFournisseur.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Calendrier/Connexions/ConnexionPostgres.php
D  app/Domain/Calendrier/Connexions/ConnexionSqlite.php
 M app/Domain/Calendrier/Connexions/FuseauParDefautDeLaBase.php
D  app/Domain/Calendrier/Connexions/LieLesDatesEnUtc.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Catalogue/Models/Service.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/AjouterPreuveOperationAction.php
 M app/Domain/Facturation/Services/DocumentNumberingService.php
 M app/Domain/Facturation/Services/PiedDePage.php
 M app/Domain/Facturation/Support/GardesDeLaVente.php
D  app/Domain/Recherche/ConnecteurSqlite.php
 M app/Domain/Recherche/RechercheSansAccents.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Domain/Tenancy/Support/GardesDesIdentifiants.php
 M app/Domain/Tenancy/Support/GardesDesTypesDActivite.php
 M app/Filament/App/Livewire/SelecteurDeClients.php
 M app/Providers/AppServiceProvider.php
 M config/database.php
 M config/queue.php
 M database/migrations/0000_00_00_000000_fixer_le_fuseau_par_defaut_de_la_base.php
 M database/migrations/2026_10_02_095000_create_referentiel_autorisations_magasin_table.php
 M database/migrations/2026_10_02_100001_create_fonction_sans_casse_ni_accents.php
 M database/migrations/2026_10_02_100005_create_affectations_table.php
 M database/migrations/2026_10_02_200001_create_clients_table.php
 M database/migrations/2026_10_02_200003_create_produits_table.php
 M database/migrations/2026_10_02_200005_create_services_table.php
 M database/migrations/2026_10_09_100001_create_clotures_caisse_personnelles_table.php
 M database/migrations/2026_10_14_100002_create_enregistrements_fiscaux_table.php
 M tests/Feature/Achats/FactureAchatArchivageConcurrenceTest.php
 M tests/Feature/Achats/FactureAchatConcurrenceTest.php
 M tests/Feature/Achats/FicheProduitPendantUneFactureAchatConcurrenceTest.php
 M tests/Feature/Achats/PiecesDuFournisseurConcurrenceTest.php
 M tests/Feature/Achats/TvaDuFournisseurConcurrenceTest.php
 M tests/Feature/Authorization/GardesDesDroitsEnBaseConcurrenceTest.php
 M tests/Feature/Authorization/ProprietaireSansVenteConcurrenceTest.php
 M tests/Feature/Cabinets/CabinetsConcurrenceTest.php
 M tests/Feature/CaisseSecurisee/DeclencheursDeProtectionTest.php
 M tests/Feature/CaisseSecurisee/VerificateurIntegriteConcurrenceTest.php
 M tests/Feature/Calendrier/FuseauParDefautDeLaBaseTest.php
 M tests/Feature/Catalogue/FicheProduitConcurrenceTest.php
 M tests/Feature/Catalogue/ProduitConcurrenceTest.php
 M tests/Feature/Contacts/DoublonDUneFicheConcurrenceTest.php
 M tests/Feature/Facturation/ActionsUniquesConcurrenceTest.php
 M tests/Feature/Facturation/BrouillonDeFactureConcurrenceTest.php
 M tests/Feature/Facturation/CaissesPersonnellesFermeesDOfficeConcurrenceTest.php
 M tests/Feature/Facturation/EmettreEtEncaisserConcurrenceTest.php
 M tests/Feature/Facturation/EnregistrerPaiementActionConcurrenceTest.php
 M tests/Feature/Facturation/EnregistrerVenteCaisseActionConcurrenceTest.php
 M tests/Feature/Facturation/EnvoyerDocumentJobConcurrenceTest.php
 M tests/Feature/Facturation/FermerSaCaisseActionConcurrenceTest.php
 M tests/Feature/Facturation/ImputerAvoirSurSesFacturesActionConcurrenceTest.php
```

## Dernière course de la chaîne

Course `wf_54289edd-a97`, étapes commencées (de la plus ancienne à la plus récente) :

```
QA:implementation
QA:relecture1
QA:relecture2
QA:correction
QA:poussee
QA:fiche
QA:notes
PG:implementation
```
