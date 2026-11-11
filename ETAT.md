# État du projet (généré automatiquement)

Mis à jour le 11/11/2026 à 05:11 UTC par pilotage-sync.sh : « CI de 9f46860 verte ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `9f46860`. Commits locaux pas encore poussés :

```
c2455dd Passe Larastan au niveau 10 sans erreur : les valeurs lues sans type sont vérifiées (lot TY2, niveaux 9 et 10)
```

Derniers commits poussés :

```
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
d8deaf9 Mute en entier chaque fichier sensible changé par l'envoi (CI, lot PG)
4e6d832 Prouve chaque protection de la base par un test qui échoue sans elle (lot PG)
50d39d1 Dit vrai sur les ventes dans l'avis de l'identité incomplète (constat C7)
ccaa905 Retire SQLite : PostgreSQL seul moteur, du code à la CI (lot PG)
7f4d7ae Résout le verrou de Psalm pour PHP 8.3.16 (CI, analyse des flux)
6c7b3d1 Relecture du lot QA des outils de qualité
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M README.md
 M app/Domain/Facturation/Actions/AppliquerAvoirsDisponiblesAction.php
 M app/Domain/Facturation/Actions/ContrePasserPaiementAction.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Services/Emetteur.php
 M app/Domain/Facturation/Services/ImputationDuCredit.php
 M app/Domain/Stock/Services/VerificateurDeStock.php
 M app/Domain/Tenancy/Actions/CreerMembreAction.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Filament/App/Livewire/SelecteurDeClients.php
 M app/Filament/App/Livewire/SelecteurDeProduits.php
 M app/Filament/App/Pages/Caisse.php
 M app/Filament/App/Pages/CoordonneesMagasin.php
 M app/Filament/App/Pages/TelechargerLesDocuments.php
 M app/Filament/App/Resources/AvoirResource.php
 M app/Filament/App/Resources/ClientResource/Pages/ViewClient.php
 M app/Filament/App/Resources/EnregistrementFiscalResource.php
 M app/Filament/App/Resources/EquipeDuMagasinResource/Pages/EditMembreDuMagasin.php
 M app/Filament/App/Resources/EquipeDuMagasinResource/Pages/ListEquipeDuMagasin.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/PosteReconnuResource/Pages/ListPostesReconnus.php
 M app/Filament/App/Resources/ProduitResource.php
 M app/Filament/App/Widgets/BienvenueApercu.php
 M app/Filament/Cabinet/Pages/InvitationsEtDemandes.php
 M app/Filament/Cabinet/Pages/TableauDeBordDuCabinet.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/FicheDuClient.php
 M app/Filament/Cabinet/Resources/CollaborateurResource/Pages/FicheDuCollaborateur.php
 M app/Filament/Concerns/AjouteDesLignesDAchat.php
 M app/Filament/Concerns/AjouteDesLignesDeVente.php
 M app/Filament/Concerns/EnregistreLesParametresDuMagasin.php
 M app/Filament/Concerns/RecoitLeClientChoisi.php
 M app/Filament/Societe/Pages/CabinetComptable.php
 M app/Filament/Societe/Pages/IdentiteSociete.php
 M app/Filament/Societe/Pages/LesCaisses.php
 M app/Filament/Societe/Pages/ParametresFiscauxSociete.php
 M app/Filament/Societe/Pages/RattachementDUnCabinet.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Filament/Societe/Pages/VerificationSociete.php
 M app/Filament/Societe/Resources/MagasinResource/Pages/CreerMagasin.php
 M app/Filament/Societe/Resources/MagasinResource/Pages/ModifierMagasin.php
 M app/Filament/Societe/Resources/MembreResource.php
 M app/Filament/Societe/Resources/MembreResource/Pages/FicheDuMembre.php
 M tests/Feature/Facturation/EnvoyerDocumentJobTest.php
 M tests/Feature/Qualite/OutilDeQualiteTest.php
 M tests/Qualite/qualite.php
?? app/Domain/Tenancy/Support/RequeteDuModele.php
?? tests/Feature/Tenancy/RequeteDuModeleTest.php
```

## Dernière course de la chaîne

Course `wf_54289edd-a97`, étapes commencées (de la plus ancienne à la plus récente) :

```
QA:implementation
QA:relecture1
QA:relecture2
QA:correction
QA:poussee
QA:fiche
QA:notes
PG:implementation
PG:relecture1
PG:relecture2
PG:correction
PG:poussee
PG:fiche
PG:notes
TY1:implementation
TY1:relecture1
TY1:relecture2
TY1:correction
TY1:poussee
TY1:fiche
TY1:notes
TY2:implementation
```
