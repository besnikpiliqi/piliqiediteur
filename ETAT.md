# État du projet (généré automatiquement)

Mis à jour le 11/11/2026 à 21:48 UTC par pilotage-sync.sh : « Contrôle des pages d'arbitrages contre l'onglet 3 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `cba222d`. Commits locaux pas encore poussés :

```
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
 M .env.example
 M .github/workflows/tests.yml
 M CLAUDE.md
 M README.md
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Console/Commands/Editeur/ConsulterLesPieces.php
 M app/Domain/Achats/Actions/CreerFactureAchatAction.php
 M app/Domain/Achats/Actions/EnregistrerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/JoindreDesPiecesAction.php
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Actions/RetirerUnePieceAction.php
 M app/Domain/Achats/Models/PieceDuFournisseur.php
 M app/Domain/Achats/Services/PiecesDuFournisseur.php
 M app/Domain/Achats/Support/GardesDesPiecesDuFournisseur.php
 M app/Domain/Facturation/Services/ArchiveDesDocuments.php
RM app/Domain/Achats/Services/PieceDeposee.php -> app/Domain/Fichiers/PieceDeposee.php
 M app/Domain/Tenancy/Actions/DeposerUnePieceAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Jobs/PreparerLeTelechargementDesDocumentsJob.php
 M app/Domain/Tenancy/Models/PieceVerification.php
 M app/Domain/Tenancy/Services/ActionsUniques.php
 M app/Domain/Tenancy/Services/PiecesDeVerification.php
 M app/Filament/App/Resources/EnregistrementFiscalResource/Pages/ListEnregistrementsFiscaux.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/CreateFactureAchat.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/EditFactureAchat.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/Concerns/EnvoieLesDocuments.php
 M app/Filament/Concerns/JointLesPiecesDuFournisseur.php
 M app/Filament/Societe/Pages/VerificationSociete.php
 M app/Http/Controllers/TelechargerLesDocumentsController.php
 M app/Http/Controllers/TelechargerUnePieceDuFournisseurController.php
 M bootstrap/providers.php
 M composer.json
 M composer.lock
 M config/filesystems.php
 M config/piliqi.php
 M config/services.php
 M database/migrations/2026_10_02_100014_create_pieces_verification_table.php
 M database/migrations/2026_10_07_100009_create_pieces_fournisseur_table.php
 M phpunit.xml
 M resources/views/telechargements/indisponible.blade.php
 M tests/Browser/AidesCommunesTest.php
 M tests/Browser/Parcours/PiecesDuFournisseurTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Achats/PiecesDuFournisseurConcurrenceTest.php
 M tests/Feature/Achats/PiecesDuFournisseurTest.php
 M tests/Feature/Authorization/ActionsHorsEcranTest.php
 M tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php
 M tests/Feature/Editeur/ProceduresTest.php
 M tests/Feature/Facturation/RecusDePaiementTest.php
 M tests/Feature/Facturation/TelechargementDesDocumentsTest.php
 M tests/Feature/Filament/EnregistrementFiscalResourceTest.php
 M tests/Feature/Filament/EnvoieLesDocumentsTest.php
 M tests/Feature/Filament/PiecesDuFournisseurResourceTest.php
 M tests/Feature/Filament/TelechargerLesDocumentsTest.php
 M tests/Feature/Societe/EffacerPiecesVerificationTest.php
 M tests/Feature/Societe/VerificationSocieteTest.php
 M tests/Feature/Tenancy/TenantIsolationTest.php
?? app/Domain/Fichiers/AnalyseurDePdf.php
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
```
