# État du projet (généré automatiquement)

Mis à jour le 17/11/2026 à 03:33 UTC par pilotage-sync.sh : « Point de suivi 03:34 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `52dab0ff`. Commits locaux pas encore poussés :

```
afb4ce96 Réparation de la CI rouge de 52dab0ff
```

Derniers commits poussés :

```
52dab0ff Relecture du lot DEMO-COPIE-2
b5ded45c Démonstration : site séparé, une base par copie, demande depuis le compte
7f9e16ca Relecture du lot DEMO-COPIE-1
4091e8b2 Démonstration : retrait du magasin de démonstration intérieur
3bdfe83b Réparation de la CI rouge de d15af36
d15af36b Relecture du lot DATEOP
ad18faca Date de la livraison ou de la prestation sur la facture (T49)
5418d777 Graphiques : en-tête des cartes
90f7ab33 Télécharger les documents : les charges et leurs pièces
a9bb1a78 Charges : état téléchargeable en PDF et en Excel
90a4426c Écrans : classes de style absentes de la feuille de Filament
9e14f02f Semis complet de toutes les tables et de tous les cas
93939942 Réparation de la CI rouge de b6015883
b6015883 Relecture du lot REGUL
be209079 Réparation de la CI rouge de 8ce8bd4 (cartes des notifications)
e405d27b Régularisation après une correction de SIREN et relances (lot REGUL)
220d40b4 Réparation de la CI rouge de 8ce8bd4
8ce8bd4a Relecture du lot CHARGES
68531b28 Charges de la société et TVA réelle (lot CHARGES)
fcbf1166 Réparation de la CI rouge de 743f9bc
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M .env.demonstration.example
 M .env.example
 M CLAUDE.md
 M README.md
 M app/Console/Commands/ReprendreLesCopiesDeLaDemonstration.php
 M app/Domain/Charges/Support/ContenuDeLEtat.php
 M app/Domain/Demonstration/Archives/ArchiverUneCopie.php
 M app/Domain/Demonstration/Archives/FinDesCopies.php
 M app/Domain/Demonstration/Bases/BrancherUneBase.php
 M app/Domain/Demonstration/Construction/CopiesDeLaDemonstration.php
 M app/Domain/Demonstration/Construction/NettoyageDeLaDemonstration.php
 M app/Domain/Demonstration/Etat/EtatDuSite.php
RM app/Domain/Demonstration/Jobs/RecommencerUneCopie.php -> app/Domain/Demonstration/Jobs/RefaireUneCopie.php
 M app/Domain/Demonstration/Messages/TraitementDesMessages.php
 M app/Domain/Demonstration/Registre/Models/Temoin.php
 M app/Domain/Demonstration/VersionServie.php
 M app/Domain/Demonstration/Visites/OuvertureDUneCopie.php
 M app/Domain/Demonstration/Visites/Visite.php
 M app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
 M app/Domain/Fichiers/ControleDesFichiers.php
 M app/Domain/Fichiers/Enums/RaisonDuRefus.php
 M app/Domain/TableauDeBord/Support/ReglagesDuTableauDeBord.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Isolation/ReglesDIsolation.php
 M app/Domain/Tenancy/Models/TelechargementDeDocuments.php
 M app/Domain/Tenancy/Services/CreationDesComptes.php
 M app/Domain/Tenancy/Services/TraceDuVisiteur.php
 M app/Http/Controllers/Demonstration/EntrerDansLaDemonstrationController.php
 M app/Http/Middleware/OuvrirLaCopie.php
 M app/Providers/AppServiceProvider.php
 M app/Providers/CourrielsServiceProvider.php
 M bootstrap/app.php
 M bootstrap/providers.php
 M config/demonstration.php
 M config/mail.php
 M config/queue.php
 M database/migrations-registre/2026_11_16_200002_create_copies_table.php
 M database/migrations-registre/2026_11_16_200004_create_temoins_table.php
 M database/seeders/DatabaseSeeder.php
 M database/seeders/Semis/HorlogeDuSemis.php
 M phpunit.xml
 M public/css/app/demonstration.css
 M resources/views/demonstration/attente.blade.php
 M resources/views/filament/bienvenue.blade.php
 M routes/demonstration.php
 M tests/Browser/Support/ContrasteDesPages.php
 M tests/Browser/TestCaseDuNavigateur.php
 M tests/Browser/inventaire-des-actions.php
 M tests/DemonstrationTestCase.php
 M tests/Feature/Demonstration/ArchiveAvantSuppressionTest.php
 M tests/Feature/Demonstration/BasesModelesTest.php
 M tests/Feature/Demonstration/ClesDeLaDemonstrationTest.php
 M tests/Feature/Demonstration/ConstructionDesModelesTest.php
 M tests/Feature/Demonstration/RolesPostgresDeLaDemonstrationTest.php
 M tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php
 M tests/Feature/Tenancy/IsolationDesSocietesTest.php
 M tests/Pest.php
 M tests/Unit/matrice-des-decisions.php
?? app/Console/Commands/MesurerLesCopiesDeLaDemonstration.php
?? app/Console/Commands/MigrerLesCopiesDeLaDemonstration.php
```

## Dernière course de la chaîne

Course `wf_b2591b35-919`, étapes commencées (de la plus ancienne à la plus récente) :

```
DEMO-COPIE-1:implementation
DEMO-COPIE-1:implementation-2
DEMO-COPIE-1:implementation-3
DEMO-COPIE-1:relecture1
DEMO-COPIE-1:relecture2
DEMO-COPIE-1:correction
DEMO-COPIE-1:poussee
DEMO-COPIE-1:notes
DEMO-COPIE-2:implementation
DEMO-COPIE-2:implementation-2
DEMO-COPIE-2:implementation-3
DEMO-COPIE-2:implementation-4
DEMO-COPIE-2:implementation-5
DEMO-COPIE-2:implementation-6
DEMO-COPIE-2:relecture1
DEMO-COPIE-2:relecture2
DEMO-COPIE-2:correction
DEMO-COPIE-2:poussee
DEMO-COPIE-2:notes
DEMO-COPIE-3:implementation
DEMO-COPIE-3:implementation-2
DEMO-COPIE-3:implementation-3
```
