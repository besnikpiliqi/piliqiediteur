# État du projet (généré automatiquement)

Mis à jour le 11/11/2026 à 23:45 UTC par pilotage-sync.sh : « Reprise après redémarrage du conteneur (FI:correction) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `cba222d`. Commits locaux pas encore poussés :

```
5c67a15 Contrôle les fichiers reçus et sert les fichiers en sécurité (lot FI)
```

Derniers commits poussés :

```
cba222d Relecture du lot CALC de la série 8
b21124f Calcule les montants sans virgule flottante et imprime le PU HT tel qu'il est enregistré (lot CALC)
71a217a Passe à la méthode rapide : la suite une fois par lot, l'analyse statique et la mutation à la fin
5dc11a1 Relecture du lot TY2 de l’analyse statique
b9dd057 Vérifie les types que donnaient les commentaires en ligne, et refuse ces commentaires au contrôle des seuils (lot TY2, types en ligne)
c2455dd Passe Larastan au niveau 10 sans erreur : les valeurs lues sans type sont vérifiées (lot TY2, niveaux 9 et 10)
9f46860 Relecture du lot TY1 de l’analyse statique
8546296 Couvre les lignes retouchées par le typage et retire une fonction sans appelant (lot TY1 12/n)
5ff0152 Type les valeurs requises des écrans, et passe Larastan au niveau 8 (lot TY1 11/n)
96b3f8a Type les valeurs requises des ventes, des achats, de la caisse et du stock (Larastan niveau 8, lot TY1 10/n)
283c34d Type les valeurs requises des droits, de la société et des cabinets (Larastan niveau 8, lot TY1 9/n)
97c16bc Type les écrans, les commandes et la configuration, et passe Larastan au niveau 7 (lot TY1 8/n)
430539c Type les ventes, les achats, la caisse et le stock (Larastan niveau 7, lot TY1 7/n)
0e5cf71 Type les lectures des droits, de la société et des cabinets (Larastan niveau 7, lot TY1 6/n)
8b3b731 Étend l'analyse statique à tout le code de l'application hors des tests (lot TY1 5/n)
6fcefd0 Lit comme absent un témoin de poste envoyé en tableau, au lieu d'une erreur 500 (lot TY1 4/n)
d8d2ee3 Contrôle les seuils de l'analyse statique par ses paramètres effectifs (lot TY1 3/n)
5946e69 Arrête les mutants survivants connus des calculs et des droits (lot TY1 2/n)
2c55bad Ajoute le test d'empreinte de la caisse et des PDF (lot TY1 1/n)
b93aeff Relecture du lot PG de la série 8
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M README.md
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Domain/Facturation/Services/ArchiveDesDocuments.php
 M app/Domain/Fichiers/AnalyseurDePdf.php
 M app/Domain/Fichiers/NomDeFichier.php
 M app/Domain/Fichiers/ReenregistrementDesImages.php
 M app/Domain/Tenancy/Jobs/PreparerLeTelechargementDesDocumentsJob.php
 M app/Domain/Tenancy/Models/TelechargementDeDocuments.php
 M app/Filament/App/Resources/AvoirFournisseurResource/Pages/ViewAvoirFournisseur.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/ViewFactureAchat.php
 M app/Filament/Concerns/JointLesPiecesDuFournisseur.php
 M app/Http/Controllers/TelechargerLesDocumentsController.php
 M app/Http/Controllers/TelechargerUnePieceDuFournisseurController.php
 M database/migrations/2026_10_14_100006_create_telechargements_documents_table.php
 M tests/Browser/Parcours/PiecesDuFournisseurTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php
 M tests/Feature/Facturation/TelechargementDesDocumentsTest.php
 M tests/Feature/Fichiers/ControleDesFichiersTest.php
 M tests/Feature/Fichiers/PdfDePiliqiTest.php
 M tests/Feature/Fichiers/PiecesRecuesTest.php
 M tests/Fixtures/FichiersDeTest.php
?? app/Domain/Fichiers/MemoireDePhp.php
?? app/Filament/Concerns/TelechargeLesPiecesDuFournisseur.php
?? tests/Fixtures/controler-un-fichier.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
CALC:implementation
CALC:implementation-2
CALC:relecture1
CALC:relecture2
CALC:correction
CALC:poussee
CALC:notes
FI:implementation
FI:implementation-2
FI:relecture1
FI:relecture2
FI:correction
```
