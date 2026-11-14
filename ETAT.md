# État du projet (généré automatiquement)

Mis à jour le 14/11/2026 à 23:51 UTC par pilotage-sync.sh : « Point de suivi 23:55 : GRAPH en correction ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `036841d6`. Commits locaux pas encore poussés :

```
b0d52373 Graphiques du tableau de bord (lot GRAPH)
d0bf1bb0 Réparation de la CI rouge de 036841d
```

Derniers commits poussés :

```
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
9ef56b62 Répare la CI : une seule aide pour choisir le client en navigateur
9b258e52 Relecture du lot S9-L de la série 9
4e2b320a Un produit n'a qu'une ligne d'un document
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/TableauDeBord/Services/PerimetreDesGraphiques.php
 M app/Domain/TableauDeBord/Services/SeriesDesGraphiques.php
 M app/Domain/TableauDeBord/Services/StockDuTableauDeBord.php
 M app/Domain/TableauDeBord/Services/VentesDuTableauDeBord.php
 M app/Domain/TableauDeBord/Support/ReglagesDuTableauDeBord.php
 M app/Domain/Tenancy/Actions/AnnulerUneNominationAction.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/FicheDuClient.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/GraphiquesDuClient.php
 M app/Filament/GrilleDesGraphiques.php
 M resources/views/filament/app/pages/tableau-de-bord-de-la-societe.blade.php
 M resources/views/filament/cabinet/resources/graphiques-du-client.blade.php
 M resources/views/filament/societe/pages/tableau-de-bord.blade.php
 M tests/Browser/Parcours/GraphiquesDuTableauDeBordTest.php
 M tests/Browser/Support/ActionsDeLInterface.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Societe/ResponsablesTest.php
 M tests/Feature/TableauDeBord/PreferencesDesGraphiquesTest.php
 M tests/Feature/TableauDeBord/QuiVoitLesGraphiquesTest.php
 M tests/Feature/TableauDeBord/SeriesDesGraphiquesTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
MAT-PRO:relecture2
MAT-PRO:correction
MAT-PRO:poussee
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
```
