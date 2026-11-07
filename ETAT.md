# État du projet (généré automatiquement)

Mis à jour le 07/11/2026 à 19:58 UTC par pilotage-sync.sh : « Point de suivi du 07/11 19 h 58 UTC : sauvegarde du lot EX (1 commit local et travail en cours) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `074eaee`. Commits locaux pas encore poussés :

```
3c83ee0 Joint la pièce du fournisseur aux achats saisis à la main (lot EX 1/2)
```

Derniers commits poussés :

```
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
53ed088 Relecture du lot A7 de l'architecture société
137334e Ajoute l'examen et la correction d'un SIREN par l'éditeur (lot A7)
0772af0 Ouvre le journal d'audit au propriétaire et ajoute les procédures de l'éditeur (lot A7)
d232b4f Pose le fuseau UTC de la base avant le test du diagnostic qui le lit
5745e7a Relecture du lot L1 de l'architecture société
8b15aec Outille le développement local et fixe le fuseau UTC de la base (lot L1)
01fe217 Relecture du lot EC de l’espace des cabinets
93ea590 Retire les experts-comptables de la société et réécrit CLAUDE.md (lot EC 4/4)
94ada86 Lie les cabinets à leurs clients : invitations, demandes, autorisations, accès (lot EC 3/4)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Facturation/Models/ClotureCaisse.php
 M app/Domain/Tenancy/Actions/ModifierMembreAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Enums/RoleMagasin.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Services/AffectationsDesMembres.php
 M app/Domain/Tenancy/Services/PorteeDesDroits.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Filament/App/Resources/ClotureCaisseResource.php
 M app/Filament/App/Resources/EReportingResource.php
 M app/Filament/Concerns/CocheLesAutorisationsDeMagasin.php
 M app/Filament/Societe/Pages/CabinetComptable.php
 M app/Filament/Societe/Pages/RattachementDUnCabinet.php
 M app/Filament/Societe/Resources/MembreResource.php
 M app/Models/User.php
 M app/Providers/Filament/AppPanelProvider.php
 M app/Providers/Filament/CabinetPanelProvider.php
 M app/Providers/Filament/SocietePanelProvider.php
 M bootstrap/app.php
 M config/filesystems.php
 M tests/Browser/Support/NoyauDUneRequeteNeuve.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Authorization/AutorisationsDesRolesTest.php
 M tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php
 M tests/Feature/Authorization/ControleDesDroitsTest.php
 M tests/Feature/Authorization/DroitsDuResponsableTest.php
 M tests/Feature/Cabinets/PanneauCabinetTest.php
 M tests/Feature/Calendrier/PlanificateurTest.php
 M tests/Feature/Societe/AdministrateursTest.php
 M tests/Feature/Societe/AutorisationsDesMembresTest.php
 M tests/Feature/Societe/PanneauSocieteTest.php
 M tests/Feature/Societe/ResponsablesTest.php
 M tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php
 M tests/Feature/Tenancy/IsolationDesSocietesTest.php
 M tests/Feature/Tenancy/NotificationsDeLaSocieteTest.php
?? app/Console/Commands/EffacerTelechargementsDeDocuments.php
?? app/Domain/Achats/Services/FicheDeSaisiePdfGenerator.php
?? app/Domain/Facturation/Enums/FormatDuTelechargement.php
?? app/Domain/Facturation/Enums/OrigineDesAchats.php
?? app/Domain/Facturation/Enums/TypeDeDocumentTelechargeable.php
?? app/Domain/Facturation/Services/ArchiveDesDocuments.php
?? app/Domain/Facturation/Services/ClotureCaissePdfGenerator.php
?? app/Domain/Facturation/Services/DocumentTrouve.php
?? app/Domain/Facturation/Services/DocumentsDeLaPeriode.php
?? app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
?? app/Domain/Facturation/Services/ReleveDesEncaissementsPdfGenerator.php
?? app/Domain/Tenancy/Actions/DemanderLeTelechargementDesDocumentsAction.php
?? app/Domain/Tenancy/Enums/StatutDuTelechargement.php
?? app/Domain/Tenancy/Jobs/PreparerLeTelechargementDesDocumentsJob.php
?? app/Domain/Tenancy/Models/TelechargementDeDocuments.php
?? app/Domain/Tenancy/Notifications/AvisDuTelechargementDesDocuments.php
?? app/Filament/App/Pages/TelechargerLesDocuments.php
?? app/Filament/Cabinet/Pages/TelechargerLesDocuments.php
?? app/Filament/Concerns/TelechargeLesDocumentsParPeriode.php
?? app/Filament/Societe/Pages/TelechargerLesDocuments.php
?? app/Http/Controllers/TelechargerLesDocumentsController.php
?? database/migrations/2026_10_14_100006_create_telechargements_documents_table.php
```

## Dernière course de la chaîne

Course `wf_f59a9064-d8d`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
TR:relecture1
TR:relecture2
TR:correction
TR:poussee
TR:fiche
TR:notes
EX:implementation
```
