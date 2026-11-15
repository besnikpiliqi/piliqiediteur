# État du projet (généré automatiquement)

Mis à jour le 15/11/2026 à 01:32 UTC par pilotage-sync.sh : « CI 743f9bc : test des cartes confié à CHARGES ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `743f9bc2`. Commits locaux pas encore poussés :

```
7ea99bc4 Réparation de la CI rouge de 743f9bc
```

Derniers commits poussés :

```
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
175df0fd Applique les décisions de Claude sans attendre un professionnel (lot ER-PRO)
58ad4deb Relecture du lot SEED
8847d246 Sème des données de démonstration pour le poste de l'éditeur (lot SEED)
3b695ece Relecture du lot 21 des décisions
8f9817ac Vérifie toutes les décisions par une matrice décision → test (lot 21)
7f983c16 Relecture du lot S9-A de la série 9
8ec160b1 Ajoute l'« À faire » au tableau de bord du magasin et de la société
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Console/Commands/PurgerActionsTraitees.php
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/TableauDeBord/Services/ChiffresDuTableauDeBord.php
 M app/Domain/TableauDeBord/Services/PerimetreDesGraphiques.php
 M app/Domain/TableauDeBord/Services/SeriesDesGraphiques.php
 M app/Domain/TableauDeBord/Support/CalculDesSeries.php
 M app/Domain/TableauDeBord/Support/Perimetre.php
 M app/Domain/TableauDeBord/Support/SerieDuGraphique.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Services/ActionsUniques.php
 M app/Domain/Tenancy/Services/AffectationsDesMembres.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Domain/Tenancy/Support/NomsPolymorphes.php
 M app/Filament/Concerns/CocheLesAutorisationsDeMagasin.php
 M app/Filament/GrilleDesGraphiques.php
 M config/filesystems.php
 M database/migrations/0001_01_00_000000_create_societes_table.php
 M resources/views/filament/graphiques-du-panneau.blade.php
 M resources/views/filament/grille-des-graphiques.blade.php
 M tests/Feature/TableauDeBord/QuiVoitLesGraphiquesTest.php
 M tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php
 M tests/Feature/Tenancy/IsolationDesSocietesTest.php
 M tests/Unit/matrice-des-decisions.php
?? app/Domain/Charges/
?? app/Domain/TableauDeBord/Services/ChargesDuTableauDeBord.php
?? app/Domain/Tenancy/Models/ActionTraiteeDeLaSociete.php
?? database/migrations/2026_10_14_100010_create_actions_traitees_de_la_societe_table.php
?? database/migrations/2026_10_15_100001_create_types_de_charge_de_la_societe_table.php
?? database/migrations/2026_10_15_100002_create_charges_table.php
?? database/migrations/2026_10_15_100003_create_lignes_de_charge_table.php
?? database/migrations/2026_10_15_100004_create_pieces_de_charge_table.php
?? tests/Feature/Charges/
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
MAT-PRO:notes
DEMO-V:implementation
DEMO-V:implementation-2
DEMO-V:implementation-3
DEMO-V:relecture1
DEMO-V:relecture2
DEMO-V:correction
DEMO-V:poussee
DEMO-V:notes
REGLE-B:implementation
REGLE-B:relecture1
REGLE-B:relecture2
REGLE-B:correction
REGLE-B:poussee
REGLE-B:notes
GRAPH:implementation
GRAPH:implementation-2
GRAPH:implementation-3
GRAPH:implementation-4
GRAPH:relecture1
GRAPH:relecture2
GRAPH:correction
GRAPH:poussee
GRAPH:notes
CHARGES:implementation
```
