# État du projet (généré automatiquement)

Mis à jour le 16/11/2026 à 00:36 UTC par pilotage-sync.sh : « DEMO-COPIE : rien sur les autres types ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b6015883`. Commits locaux pas encore poussés :

```
a9bb1a78 Charges : état téléchargeable en PDF et en Excel
90a4426c Écrans : classes de style absentes de la feuille de Filament
9e14f02f Semis complet de toutes les tables et de tous les cas
93939942 Réparation de la CI rouge de b6015883
```

Derniers commits poussés :

```
b6015883 Relecture du lot REGUL
be209079 Réparation de la CI rouge de 8ce8bd4 (cartes des notifications)
e405d27b Régularisation après une correction de SIREN et relances (lot REGUL)
220d40b4 Réparation de la CI rouge de 8ce8bd4
8ce8bd4a Relecture du lot CHARGES
68531b28 Charges de la société et TVA réelle (lot CHARGES)
fcbf1166 Réparation de la CI rouge de 743f9bc
743f9bc2 Relecture du lot GRAPH
b0d52373 Graphiques du tableau de bord (lot GRAPH)
d0bf1bb0 Réparation de la CI rouge de 036841d
036841d6 Relecture du lot REGLE-B
f9e4ea17 Ventes calculées ligne par ligne (règle (b)) et PDF aux modèles M6-b et P1
31a5a13c Relecture du lot DEMO-V
0b89116f Démonstration masquée après la validation, PU HT d'un article, tableaux au modèle D et cartes des notifications (lot DEMO-V)
fbf139f6 Relecture du lot MAT-PRO
beddf30f Relie les 295 décisions de Claude de l'onglet à leurs tests (lot MAT-PRO)
a7df29ee Répare la CI : un test sans base de test n'écrit plus en base
6bf1d949 Relecture du lot STAT
565f1ba8 Passe Larastan et l'analyse des flux de Psalm à chaque envoi (lot STAT)
eba4bc85 Relecture du lot ER-PRO
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Domain/Facturation/Enums/TypeDeDocumentTelechargeable.php
 M app/Domain/Facturation/Services/ArchiveDesDocuments.php
 M app/Domain/Facturation/Services/DocumentTrouve.php
 M app/Domain/Facturation/Services/DocumentsDeLaPeriode.php
 M app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
 M app/Domain/Tenancy/Actions/DemanderLeTelechargementDesDocumentsAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Jobs/PreparerLeTelechargementDesDocumentsJob.php
 M app/Domain/Tenancy/Models/TelechargementDeDocuments.php
 M app/Filament/App/Pages/TelechargerLesDocuments.php
 M app/Filament/Concerns/TelechargeLesDocumentsParPeriode.php
 M app/Http/Controllers/TelechargerLesDocumentsController.php
 M app/Providers/AppServiceProvider.php
 M database/migrations/2026_10_14_100006_create_telechargements_documents_table.php
 M database/seeders/Semis/DerniersGestes.php
 M resources/views/filament/telecharger-les-documents.blade.php
 M tests/Browser/Parcours/TelechargerLesDocumentsTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php
 M tests/Feature/Facturation/TelechargementDesDocumentsConcurrenceTest.php
 M tests/Feature/Facturation/TelechargementDesDocumentsTest.php
 M tests/Feature/Filament/TelechargerLesDocumentsTest.php
 M tests/Feature/SemisCompletTest.php
 M tests/Feature/Tenancy/NotificationsDeLaSocieteTest.php
?? app/Domain/Facturation/Services/ChoixDesCharges.php
?? app/Filament/VosTelechargements.php
?? resources/views/filament/vos-telechargements.blade.php
?? tests/Feature/Facturation/TelechargementDesChargesTest.php
```

## Dernière course de la chaîne

Course `wf_1a997172-d15`, étapes commencées (de la plus ancienne à la plus récente) :

```
releve:regles
releve:code
releve:tests
releve:notes
releve:archi
redaction
```
