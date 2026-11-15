# État du projet (généré automatiquement)

Mis à jour le 15/11/2026 à 03:59 UTC par pilotage-sync.sh : « Point de suivi 15/11 03:59 UTC ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `743f9bc2`. Commits locaux pas encore poussés :

```
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
 M app/Console/Commands/PurgerActionsTraitees.php
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Cabinets/Services/TableauDeBordDesClients.php
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
 M app/Domain/Tenancy/Services/ChosesAFaireDeLaSociete.php
 M app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Domain/Tenancy/Support/NomsPolymorphes.php
 M app/Filament/App/Pages/TableauDeBordDeLaSociete.php
 M app/Filament/Cabinet/Pages/TableauDeBordDuCabinet.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/FicheDuClient.php
 M app/Filament/Concerns/CocheLesAutorisationsDeMagasin.php
 M app/Filament/GrilleDesGraphiques.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M config/filesystems.php
 M database/migrations/0001_01_00_000000_create_societes_table.php
 M resources/views/filament/app/pages/tableau-de-bord-de-la-societe.blade.php
 M resources/views/filament/cabinet/pages/tableau-de-bord-du-cabinet.blade.php
 M resources/views/filament/graphiques-du-panneau.blade.php
 M resources/views/filament/grille-des-graphiques.blade.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Authorization/AffectationPolicyTest.php
 M tests/Feature/Authorization/AutorisationsDesRolesTest.php
 M tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php
 M tests/Feature/Authorization/ControleDesDroitsTest.php
 M tests/Feature/Authorization/DroitsDuResponsableTest.php
 M tests/Feature/Authorization/GardesDesDroitsEnBaseTest.php
 M tests/Feature/Authorization/PorteeDesDroitsTest.php
 M tests/Feature/Authorization/ProprietaireSansVenteConcurrenceTest.php
 M tests/Feature/Authorization/ProprietaireSansVenteTest.php
 M tests/Feature/Cabinets/AccesDeLExpertComptableTest.php
 M tests/Feature/Cabinets/PanneauCabinetTest.php
 M tests/Feature/Filament/EquipeDuMagasinTest.php
 M tests/Feature/Qualite/PlafondsDeRequetesTest.php
 M tests/Feature/Societe/AdministrateursTest.php
 M tests/Feature/Societe/AutorisationsDesMembresTest.php
 M tests/Feature/Societe/ChangementDeRoleTest.php
 M tests/Feature/Societe/ConnexionParIdentifiantTest.php
 M tests/Feature/Societe/DroitsConcurrenceTest.php
 M tests/Feature/Societe/MagasinDeDemonstrationTest.php
 M tests/Feature/Societe/PanneauSocieteTest.php
 M tests/Feature/Societe/RenouvellementDAccesTest.php
 M tests/Feature/Societe/ResponsablesTest.php
 M tests/Feature/Societe/TransfererProprieteTest.php
 M tests/Feature/TableauDeBord/QuiVoitLesGraphiquesTest.php
 M tests/Feature/TableauDeBord/SeriesDesGraphiquesTest.php
 M tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php
 M tests/Feature/Tenancy/IsolationDesSocietesTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
CHARGES:implementation-2
CHARGES:implementation-3
```
