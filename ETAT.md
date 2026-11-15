# État du projet (généré automatiquement)

Mis à jour le 15/11/2026 à 11:59 UTC par pilotage-sync.sh : « Point de suivi 11:59 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `8ce8bd4a`. Commits locaux pas encore poussés :

```
e405d27b Régularisation après une correction de SIREN et relances (lot REGUL)
220d40b4 Réparation de la CI rouge de 8ce8bd4
```

Derniers commits poussés :

```
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
175df0fd Applique les décisions de Claude sans attendre un professionnel (lot ER-PRO)
58ad4deb Relecture du lot SEED
8847d246 Sème des données de démonstration pour le poste de l'éditeur (lot SEED)
3b695ece Relecture du lot 21 des décisions
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Facturation/Services/RegularisationDUneFacture.php
 M app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php
 M app/Filament/App/Resources/AvoirResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/PaiementResource.php
 M app/Filament/Concerns/EnvoieLesDocuments.php
 M resources/views/filament/carte-de-notification.blade.php
 M tests/Browser/Parcours/CartesDesNotificationsTest.php
 M tests/Browser/Parcours/RegularisationEtRelancesTest.php
 M tests/Browser/Support/Navigation.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Facturation/RegularisationDUneFactureTest.php
?? resources/views/vendor/filament-notifications/
?? resources/views/vendor/filament-panels/
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
```
