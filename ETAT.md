# État du projet (généré automatiquement)

Mis à jour le 15/11/2026 à 06:00 UTC par pilotage-sync.sh : « Point de suivi 15/11 06:00 UTC ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `743f9bc2`. Commits locaux pas encore poussés :

```
68531b28 Charges de la société et TVA réelle (lot CHARGES)
fcbf1166 Réparation de la CI rouge de 743f9bc
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
 M app/Domain/Charges/Actions/CorrigerUneChargeAction.php
 M app/Domain/Charges/Actions/DeposerUneChargeAction.php
 M app/Domain/Charges/Actions/GererLesTypesDeChargeAction.php
 M app/Domain/Charges/Actions/SaisirUneChargeAction.php
 M app/Domain/Charges/Enums/EtatDeLaCharge.php
 M app/Domain/Charges/Services/ChargesAFaire.php
 M app/Domain/Charges/Services/ChargesDeLaSociete.php
 M app/Domain/Charges/Services/DroitsSurLesCharges.php
 M app/Domain/Charges/Services/EcritureDUneCharge.php
 M app/Domain/Charges/Services/TvaDeLaPeriode.php
 M app/Domain/Charges/Services/TypesDeCharge.php
 M app/Domain/Charges/Support/AccesAuxCharges.php
 M app/Domain/Charges/Support/GardesDesCharges.php
 M app/Domain/TableauDeBord/Services/ChargesDuTableauDeBord.php
 M app/Domain/TableauDeBord/Services/ChiffresDuTableauDeBord.php
 M app/Domain/TableauDeBord/Services/SeriesDesGraphiques.php
 M app/Domain/TableauDeBord/Support/Perimetre.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Filament/PageDesCharges.php
 M resources/views/filament/grille-des-graphiques.blade.php
 M resources/views/filament/page-des-charges.blade.php
 M tests/Browser/Parcours/ChargesTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Charges/AFaireDesChargesTest.php
 M tests/Feature/Charges/DroitsSurLesChargesTest.php
 M tests/Feature/Charges/GardesDesChargesEnBaseTest.php
 M tests/Feature/Charges/GestesSurLesChargesTest.php
 M tests/Feature/Charges/PagesDesChargesTest.php
 M tests/Feature/Charges/TvaDeLaPeriodeTest.php
 M tests/Feature/TableauDeBord/SeriesDesGraphiquesTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
CHARGES:implementation-2
CHARGES:implementation-3
CHARGES:relecture1
CHARGES:relecture2
CHARGES:correction
```
