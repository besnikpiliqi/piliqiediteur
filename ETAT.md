# État du projet (généré automatiquement)

Mis à jour le 07/11/2026 à 17:22 UTC par pilotage-sync.sh : « 07/11 17 h 20 : TR poussé, CI verte ; EX en cours, travail sauvé ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `074eaee`. Commits locaux pas encore poussés :

```
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
 M app/Console/Commands/EffacerPiecesVerification.php
 M app/Domain/Achats/Actions/CreerFactureAchatAction.php
 M app/Domain/Achats/Actions/EmettreAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/EnregistrerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Actions/SupprimerFactureAchatAction.php
 M app/Domain/Achats/Models/AvoirFournisseur.php
 M app/Domain/Achats/Models/FactureAchat.php
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Services/CatalogueDeDemonstration.php
 M app/Filament/App/Resources/AvoirFournisseurResource.php
 M app/Filament/App/Resources/AvoirFournisseurResource/Pages/ViewAvoirFournisseur.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/CreateFactureAchat.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/EditFactureAchat.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/ViewFactureAchat.php
 M app/Policies/AvoirFournisseurPolicy.php
 M app/Policies/FactureAchatPolicy.php
 M app/Providers/Filament/AppPanelProvider.php
 M config/filesystems.php
 M database/migrations/2026_10_07_100001_create_factures_achat_table.php
 M database/migrations/2026_10_07_100003_create_avoirs_fournisseur_table.php
 M tests/Browser/Parcours/TvaDesAchatsTest.php
 M tests/Browser/TestCaseDuNavigateur.php
 M tests/Feature/Achats/AvoirFournisseurActionsTest.php
 M tests/Feature/Achats/FactureAchatActionsTest.php
 M tests/Feature/Achats/FactureAchatArchivageConcurrenceTest.php
 M tests/Feature/Achats/FactureAchatConcurrenceTest.php
 M tests/Feature/Achats/FicheProduitPendantUneFactureAchatConcurrenceTest.php
 M tests/Feature/Achats/TvaDesAchatsParTauxTest.php
 M tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php
 M tests/Feature/Achats/TvaDuFournisseurConcurrenceTest.php
 M tests/Feature/Achats/TvaDuFournisseurTest.php
 M tests/Feature/Authorization/ActionsHorsEcranTest.php
 M tests/Feature/Authorization/FactureAchatPolicyTest.php
 M tests/Feature/Authorization/LecteurDesDroitsTest.php
 M tests/Feature/Cabinets/AccesDuCollaborateurChezLeClientTest.php
 M tests/Feature/Cabinets/CabinetsConcurrenceTest.php
 M tests/Feature/Calendrier/HeureLocaleTest.php
 M tests/Feature/Calendrier/NuitDeParisTest.php
 M tests/Feature/Catalogue/FicheProduitConcurrenceTest.php
 M tests/Feature/Catalogue/OrigineDesProduitsTest.php
 M tests/Feature/Catalogue/ProduitTest.php
 M tests/Feature/Facturation/ActionsUniquesTest.php
 M tests/Feature/Facturation/EnvoyerDocumentJobConcurrenceTest.php
 M tests/Feature/Facturation/MarquerFacturesEnRetardConcurrenceTest.php
 M tests/Feature/Filament/AvoirFournisseurResourceTest.php
 M tests/Feature/Filament/DocumentDisparuDeLEcranTest.php
 M tests/Feature/Filament/EcranOuvertAvantLaFermetureTest.php
 M tests/Feature/Filament/FactureAchatResourceTest.php
 M tests/Feature/Filament/FournisseurProduitResourceTest.php
 M tests/Feature/Filament/InventaireOuvertureResourceTest.php
 M tests/Feature/Filament/ProduitResourceTest.php
 M tests/Feature/Filament/TvaDesAchatsResourceTest.php
 M tests/Feature/Societe/DroitsConcurrenceTest.php
 M tests/Feature/Societe/FermerEtRouvrirMagasinConcurrenceTest.php
 M tests/Feature/Societe/GestesPendantLaClotureOuLaValidationConcurrenceTest.php
 M tests/Feature/Societe/GestesPendantLaFermetureConcurrenceTest.php
 M tests/Feature/Societe/PorteDeValidationTest.php
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
