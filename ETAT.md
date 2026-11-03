# État du projet (généré automatiquement)

Mis à jour le 03/11/2026 à 09:47 UTC par pilotage-sync.sh : « C6 : PostgreSQL sous Windows ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `1d3278a`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
1d3278a Relecture du lot NAV-1 des tests en navigateur
523e74f Balaye chaque panneau rôle par rôle et ajoute les premiers parcours en navigateur (lot NAV-1 2/2)
9053757 Outille les tests en navigateur avec Pest 4 et Playwright (lot NAV-1 1/2)
92fd524 Relecture du lot SL de l’erreur 419 des fenêtres de recherche
b06c922 Corrige l'erreur 419 des fenêtres de recherche de clients et de produits (lot SL)
f9b669c Relecture du lot A7b des suites du lot A7
81de7e8 Complète les tests du lot A7b et indexe le journal d'audit par action
0d2b6a3 Régularise les factures après une correction de SIREN et corrige le SIREN d'un cabinet (lot A7b)
53ed088 Relecture du lot A7 de l'architecture société
137334e Ajoute l'examen et la correction d'un SIREN par l'éditeur (lot A7)
0772af0 Ouvre le journal d'audit au propriétaire et ajoute les procédures de l'éditeur (lot A7)
d232b4f Pose le fuseau UTC de la base avant le test du diagnostic qui le lit
5745e7a Relecture du lot L1 de l'architecture société
8b15aec Outille le développement local et fixe le fuseau UTC de la base (lot L1)
01fe217 Relecture du lot EC de l’espace des cabinets
93ea590 Retire les experts-comptables de la société et réécrit CLAUDE.md (lot EC 4/4)
94ada86 Lie les cabinets à leurs clients : invitations, demandes, autorisations, accès (lot EC 3/4)
9671a29 Donne au cabinet ses collaborateurs et transmet sa propriété à l'un d'eux (lot EC 2/4)
976e827 Vérifie les cabinets d'expertise comptable et leur ouvre leur espace (lot EC 1/4)
9ce20c3 Relecture du lot TA des types d’activité
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Facturation/Actions/ContrePasserPaiementAction.php
MM app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/EncaisserEnPlusieursModesAction.php
 M app/Domain/Facturation/Actions/EnregistrerPaiementAction.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Contracts/DocumentEnvoyable.php
 M app/Domain/Facturation/Enums/TypeDocument.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Mail/DocumentMail.php
 M app/Domain/Facturation/Models/Paiement.php
 M app/Domain/Facturation/Notifications/EnvoiDocumentEchoue.php
M  app/Domain/Facturation/Services/ApercuDuReglement.php
 M app/Domain/Facturation/Services/PiedDePage.php
M  app/Domain/Facturation/Services/ReglementComptant.php
AM app/Domain/Facturation/Services/TableauDesReglements.php
MM app/Filament/App/Resources/FactureResource.php
M  app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php
M  app/Filament/Concerns/SaisitUnReglementEnParts.php
M  app/Policies/FacturePolicy.php
 M app/Providers/AppServiceProvider.php
 M database/migrations/2026_10_04_100001_create_paiements_table.php
 M database/migrations/2026_10_13_100002_create_envois_documents_table.php
MM resources/views/documents/pdf/reglements.blade.php
MM resources/views/documents/pdf/styles.blade.php
A  resources/views/documents/pdf/tableau-des-reglements.blade.php
M  resources/views/documents/pdf/totaux.blade.php
M  resources/views/factures/pdf.blade.php
A  tests/Browser/Parcours/EmettreEtEncaisserTest.php
 M tests/Browser/Support/Balayeur.php
M  tests/Browser/Support/Navigation.php
M  tests/Browser/Support/surveillance.js
MM tests/Browser/inventaire-des-actions.php
 M tests/Feature/Facturation/ContrePasserPaiementActionTest.php
A  tests/Feature/Facturation/EmettreEtEncaisserConcurrenceTest.php
A  tests/Feature/Facturation/EmettreEtEncaisserTest.php
M  tests/Feature/Facturation/FacturePdfGeneratorTest.php
M  tests/Feature/Facturation/FacturePolicyTest.php
M  tests/Feature/Tenancy/MagasinEnLectureSeuleTest.php
 M tests/Feature/Tenancy/TenantIsolationTest.php
?? app/Domain/Facturation/Enums/NatureRecuPaiement.php
?? app/Domain/Facturation/Models/LigneRecuPaiement.php
?? app/Domain/Facturation/Models/RecuPaiement.php
?? app/Domain/Facturation/Services/RecuPaiementPdfGenerator.php
?? app/Domain/Facturation/Services/RecusDePaiement.php
?? app/Policies/RecuPaiementPolicy.php
?? database/migrations/2026_10_04_100000_create_recus_paiement_table.php
?? database/migrations/2026_10_04_100003_create_lignes_recu_paiement_table.php
?? resources/views/recus/
?? tests/Browser/Parcours/RecuDePaiementTest.php
?? tests/Feature/Facturation/RecusDePaiementTest.php
```

## Dernière course de la chaîne

Course `wf_f59a9064-d8d`, étapes commencées (de la plus ancienne à la plus récente) :

```
A7b:implementation
A7b:relecture1
A7b:relecture2
A7b:correction
A7b:poussee
A7b:fiche
A7b:notes
SL:implementation
SL:relecture1
SL:relecture2
SL:correction
SL:poussee
SL:fiche
SL:notes
NAV-1:implementation
NAV-1:relecture1
NAV-1:relecture2
NAV-1:correction
NAV-1:poussee
NAV-1:fiche
NAV-1:notes
TR:implementation
```
