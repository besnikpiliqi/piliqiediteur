# État du projet (généré automatiquement)

Mis à jour le 14/11/2026 à 20:18 UTC par pilotage-sync.sh : « Même échec sur la course PR ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `036841d`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
036841d Relecture du lot REGLE-B
f9e4ea1 Ventes calculées ligne par ligne (règle (b)) et PDF aux modèles M6-b et P1
31a5a13 Relecture du lot DEMO-V
0b89116 Démonstration masquée après la validation, PU HT d'un article, tableaux au modèle D et cartes des notifications (lot DEMO-V)
fbf139f Relecture du lot MAT-PRO
beddf30 Relie les 295 décisions de Claude de l'onglet à leurs tests (lot MAT-PRO)
a7df29e Répare la CI : un test sans base de test n'écrit plus en base
6bf1d94 Relecture du lot STAT
565f1ba Passe Larastan et l'analyse des flux de Psalm à chaque envoi (lot STAT)
eba4bc8 Relecture du lot ER-PRO
175df0f Applique les décisions de Claude sans attendre un professionnel (lot ER-PRO)
58ad4de Relecture du lot SEED
8847d24 Sème des données de démonstration pour le poste de l'éditeur (lot SEED)
3b695ec Relecture du lot 21 des décisions
8f9817a Vérifie toutes les décisions par une matrice décision → test (lot 21)
7f983c1 Relecture du lot S9-A de la série 9
8ec160b Ajoute l'« À faire » au tableau de bord du magasin et de la société
9ef56b6 Répare la CI : une seule aide pour choisir le client en navigateur
9b258e5 Relecture du lot S9-L de la série 9
4e2b320 Un produit n'a qu'une ligne d'un document
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Stock/Services/StockLedgerService.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Services/AffectationsDesMembres.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/FicheDuClient.php
 M app/Filament/Concerns/CocheLesAutorisationsDeMagasin.php
 M app/Filament/Societe/Pages/CabinetComptable.php
 M app/Filament/Societe/Pages/RattachementDUnCabinet.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M app/Providers/Filament/AppPanelProvider.php
 M app/Providers/Filament/CabinetPanelProvider.php
 M app/Providers/Filament/SocietePanelProvider.php
 M resources/views/filament/societe/pages/tableau-de-bord.blade.php
?? app/Domain/TableauDeBord/
?? app/Filament/App/Pages/TableauDeBordDeLaSociete.php
?? app/Filament/App/Widgets/GraphiquesDuMagasin.php
?? app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/GraphiquesDuClient.php
?? app/Filament/GrilleDesGraphiques.php
?? database/migrations/2026_10_14_100009_create_preferences_tableau_de_bord_table.php
?? public/css/app/graphiques.css
?? resources/css/graphiques.css
?? resources/views/filament/app/pages/tableau-de-bord-de-la-societe.blade.php
?? resources/views/filament/cabinet/resources/graphiques-du-client.blade.php
?? resources/views/filament/graphiques-du-panneau.blade.php
?? resources/views/filament/grille-des-graphiques.blade.php
?? tests/Browser/Parcours/GraphiquesDuTableauDeBordTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
STAT:correction
STAT:poussee
STAT:notes
MAT-PRO:implementation
MAT-PRO:implementation-2
MAT-PRO:relecture1
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
```
