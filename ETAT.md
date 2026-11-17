# État du projet (généré automatiquement)

Mis à jour le 17/11/2026 à 05:34 UTC par pilotage-sync.sh : « Point de suivi 05:35 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `52dab0ff`. Commits locaux pas encore poussés :

```
7d521432 Démonstration : bandeau, e-mails captés, tâches et mises à jour des copies
33c56754 Réparation de la CI rouge de 52dab0ff
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
 M README.md
 M app/Console/Commands/RenvoyerLesDemandesDeLaDemonstration.php
 M app/Console/Commands/SemerLesEtatsDeLaDemonstration.php
 M app/Console/Commands/SurveillerLaFileDAttente.php
 M app/Domain/Cabinets/Services/EnvoiDesLiensDesCabinets.php
 M app/Domain/Cabinets/Services/EnvoiDesLiensDesExpertsComptables.php
 M app/Domain/Demonstration/Archives/FinDesCopies.php
 M app/Domain/Demonstration/Construction/ConstruireUnModele.php
 M app/Domain/Demonstration/Construction/MigrationDesCopies.php
 M app/Domain/Demonstration/Etat/EtatDuSite.php
 M app/Domain/Demonstration/Jobs/RattraperLesTachesDUneCopie.php
 M app/Domain/Demonstration/Jobs/RefaireUneCopie.php
 M app/Domain/Demonstration/Listeners/AjouterLeCompteAuBandeau.php
 M app/Domain/Demonstration/Models/CompteDuBandeau.php
 M app/Domain/Demonstration/Services/BoiteDEnvoi.php
 M app/Domain/Demonstration/Taches/TachesDesCopies.php
 M app/Domain/Demonstration/Taches/TachesDesSites.php
 M app/Domain/Demonstration/Taches/TravauxDesCopies.php
 M app/Domain/Demonstration/Visites/BandeauDeLaDemonstration.php
 M app/Domain/Demonstration/Visites/OuvertureDUneCopie.php
 M app/Http/Controllers/AccepterLInvitationDuCabinetController.php
 M app/Http/Middleware/OuvrirLaCopie.php
 M bootstrap/app.php
 M config/queue.php
 M database/migrations-demonstration/2026_11_16_300001_create_comptes_du_bandeau_table.php
 M database/seeders/Semis/ComptesDuBandeauDuModele.php
 M database/seeders/Semis/DerniersGestes.php
 M resources/views/demonstration/choisir.blade.php
 M tests/DemonstrationTestCase.php
 M tests/Feature/Demonstration/BandeauVousEtesTest.php
 M tests/Feature/Demonstration/BasesModelesTest.php
 M tests/Feature/Demonstration/ConstructionDesModelesTest.php
 M tests/Feature/Demonstration/CourrielsQuiSeraientPartisTest.php
 M tests/Feature/Demonstration/EtatPublieTest.php
 M tests/Feature/Demonstration/FilesDesCopiesTest.php
 M tests/Feature/Demonstration/MisesAJourDesCopiesTest.php
 M tests/Feature/Demonstration/PlaceDesCopiesTest.php
 M tests/Feature/Demonstration/RenvoiDesDemandesTest.php
 M tests/Feature/Demonstration/RolesPostgresDeLaDemonstrationTest.php
 M tests/Feature/Demonstration/SemisSurLeSiteDeDemonstrationTest.php
 M tests/Feature/Demonstration/UnSeulTypeTest.php
?? app/Domain/Demonstration/Visites/RoleAuBandeau.php
?? resources/views/demonstration/invitation-du-cabinet.blade.php
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
DEMO-COPIE-3:relecture1
DEMO-COPIE-3:relecture2
DEMO-COPIE-3:correction
```
