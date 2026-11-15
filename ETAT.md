# État du projet (généré automatiquement)

Mis à jour le 15/11/2026 à 22:21 UTC par pilotage-sync.sh : « Point de suivi 22:20 UTC : DATEOP partie 8 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b6015883`. Commits locaux pas encore poussés :

```
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
 M app/Domain/CaisseSecurisee/Actions/ExporterJournalDesEvenementsAction.php
 M app/Domain/Charges/Enums/EtatDeLaCharge.php
 M app/Domain/Charges/Models/Charge.php
 M app/Domain/Charges/Services/ChargesDeLaSociete.php
 M app/Domain/Charges/Services/TvaDeLaPeriode.php
 M app/Domain/Facturation/Services/MiseEnPageA4.php
 M app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
 M app/Domain/Fichiers/EnTetesDesFichiers.php
 M app/Domain/Fichiers/NomDeFichier.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Filament/PageDesCharges.php
 M composer.json
 M composer.lock
 M database/seeders/Semis/DerniersGestes.php
 M resources/views/filament/page-des-charges.blade.php
 M tests/Browser/Parcours/ChargesTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Fichiers/ControleDesFichiersTest.php
 M tests/Feature/SemisCompletTest.php
?? app/Domain/Charges/Enums/FormatDeLEtat.php
?? app/Domain/Charges/Services/EtatDesCharges.php
?? app/Domain/Charges/Services/EtatDesChargesEnExcel.php
?? app/Domain/Charges/Services/EtatDesChargesEnPdf.php
?? app/Domain/Charges/Support/ContenuDeLEtat.php
?? app/Domain/Charges/Support/FiltresDeLEtat.php
?? app/Domain/Charges/Support/TextesDesCharges.php
?? app/Domain/Fichiers/CelluleDeTableur.php
?? resources/views/charges/
?? tests/Feature/Charges/EtatDesChargesTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
GRAPH:poussee
GRAPH:notes
CHARGES:implementation
CHARGES:implementation-2
CHARGES:implementation-3
CHARGES:relecture1
CHARGES:relecture2
CHARGES:correction
CHARGES:poussee
CHARGES:notes
REGUL:implementation
REGUL:implementation-2
REGUL:relecture1
REGUL:relecture2
REGUL:correction
REGUL:poussee
REGUL:notes
DATEOP:implementation
DATEOP:implementation-2
DATEOP:implementation-3
DATEOP:implementation-4
DATEOP:implementation-5
DATEOP:implementation-6
DATEOP:implementation-7
DATEOP:implementation-8
```
