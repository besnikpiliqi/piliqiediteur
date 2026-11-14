# État du projet (généré automatiquement)

Mis à jour le 14/11/2026 à 09:52 UTC par pilotage-sync.sh : « Lot GRAPH ajouté (bascule à faire après DEMO-V) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `fbf139f`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
c54c221 Relecture du lot EC2 de l’expert-comptable de la société
f159294 Donne à la société un expert-comptable déclaré par le SIREN de son cabinet, rattaché tout seul au cabinet vérifié (lot EC2)
b10d2e8 Relecture du lot DV du devis en ligne
496da9c Laisse le client répondre à son devis en ligne et transformer un devis accepté en facture (lot DV)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Services/DocumentsDeLaPeriode.php
 M app/Domain/Facturation/Services/FacturesAEncaisserDeLaSociete.php
 M app/Domain/Tenancy/Actions/FermerLaDemonstrationAction.php
 M app/Domain/Tenancy/Actions/ValiderLaSocieteAction.php
 M app/Domain/Tenancy/Models/TelechargementDeDocuments.php
 M app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/Concerns/EnvoieLesDocuments.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Models/User.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/DatabaseSeederTest.php
 M tests/Feature/Facturation/EnvoyerDocumentActionTest.php
 M tests/Feature/Filament/EnvoieLesDocumentsTest.php
 M tests/Feature/Societe/MagasinDeDemonstrationTest.php
 M tests/Feature/Societe/PanneauSocieteTest.php
 M tests/Unit/matrice-des-decisions.php
?? tests/Browser/Parcours/DemonstrationApresLaValidationTest.php
?? tests/Browser/Parcours/RetardEtRelanceDesTicketsTest.php
?? tests/Feature/Filament/FactureEnRetardTest.php
?? tests/Feature/Societe/DemonstrationApresLaValidationTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
SEED:correction
SEED:poussee
SEED:notes
ER-PRO:implementation
ER-PRO:implementation-2
ER-PRO:relecture1
ER-PRO:relecture2
ER-PRO:correction
ER-PRO:poussee
ER-PRO:notes
STAT:implementation
STAT:implementation-2
STAT:relecture1
STAT:relecture2
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
```
