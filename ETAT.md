# État du projet (généré automatiquement)

Mis à jour le 01/11/2026 à 22:47 UTC par pilotage-sync.sh : « Directive : tests en navigateur de toutes les actions (questions à l'éditeur) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `53ed088`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
53ed088 Relecture du lot A7 de l'architecture société
137334e Ajoute l'examen et la correction d'un SIREN par l'éditeur (lot A7)
0772af0 Ouvre le journal d'audit au propriétaire et ajoute les procédures de l'éditeur (lot A7)
d232b4f Pose le fuseau UTC de la base avant le test du diagnostic qui le lit
5745e7a Relecture du lot L1 de l'architecture société
8b15aec Outille le développement local et fixe le fuseau UTC de la base (lot L1)
01fe217 Relecture du lot EC de l’espace des cabinets
93ea590 Retire les experts-comptables de la société et réécrit CLAUDE.md (lot EC 4/4)
94ada86 Lie les cabinets à leurs clients : invitations, demandes, autorisations, accès (lot EC 3/4)
9671a29 Donne au cabinet ses collaborateurs et transmet sa propriété à l'un d'eux (lot EC 2/4)
976e827 Vérifie les cabinets d'expertise comptable et leur ouvre leur espace (lot EC 1/4)
9ce20c3 Relecture du lot TA des types d’activité
e2d790a Imprime l'assurance professionnelle facultative de la société (lot TA 3/3)
ad19e0b Range les autorisations par famille et retire les articles aux sociétés de services (lot TA 2/3)
7832bb0 Fait choisir à l'inscription un type d'activité définitif (lot TA 1/3)
89f092f Relecture du lot PU de l’affichage des prix unitaires
e70f18e Affiche les prix unitaires au centime et complète la note de calcul (lot PU)
fa83824 Relecture du lot T3 de l’heure locale des magasins
5f86951 Passe toutes les heures les tâches du calendrier des magasins (lot T3)
60962cc Met chaque magasin à l'heure locale de son territoire (lot T3)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Console/Commands/Editeur/CorrigerLeSiren.php
 M app/Domain/Cabinets/Actions/ConstaterLInscriptionALOrdreAction.php
 M app/Domain/Cabinets/Actions/VerifierLOrdreAction.php
 M app/Domain/Cabinets/Models/Cabinet.php
 M app/Domain/Cabinets/Models/LienCabinet.php
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/CaisseSecurisee/Services/ContenuFiscal.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/AppliquerAvoirsDisponiblesAction.php
 M app/Domain/Facturation/Actions/CreerAvoirAction.php
 M app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/ImputerAvoirSurSesFacturesAction.php
 M app/Domain/Facturation/Listeners/DecrementerStockApresEmissionFacture.php
 M app/Domain/Facturation/Listeners/RestaurerStockApresEmissionAvoir.php
 M app/Domain/Facturation/Models/Avoir.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Services/ImputationDuCredit.php
 M app/Domain/Facturation/Services/MentionsFiscales.php
 M app/Domain/Facturation/Services/PiedDePage.php
 M app/Domain/Tenancy/Actions/CorrigerSirenAction.php
 M app/Domain/Tenancy/Actions/CreerSocieteAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Services/ActionsUniques.php
 M app/Filament/App/Resources/AvoirResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php
 M app/Filament/Cabinet/Pages/TableauDeBordDuCabinet.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource.php
 M app/Filament/Societe/Pages/AccepterLaTransmission.php
 M app/Http/Controllers/AccepterLInvitationDuCabinetController.php
 M app/Http/Controllers/CodeDuCourrierController.php
 M app/Http/Controllers/ConfirmerInscriptionController.php
 M app/Http/Middleware/MenerUnCompteSansMagasin.php
 M app/Providers/Filament/AppPanelProvider.php
 M app/Providers/Filament/SocietePanelProvider.php
 M database/migrations/2026_10_03_100003_create_factures_table.php
 M database/migrations/2026_10_05_100001_create_avoirs_table.php
 M resources/views/filament/cabinet/pages/verification-du-cabinet.blade.php
 M tests/Feature/Cabinets/CabinetInscriptionTest.php
 M tests/Feature/Cabinets/CollaborateursTest.php
 M tests/Feature/Cabinets/IsolationDesCabinetsTest.php
 M tests/Feature/Cabinets/PanneauCabinetTest.php
 M tests/Feature/CaisseSecurisee/EmissionSigneeTest.php
 M tests/Feature/Editeur/SirenErroneTest.php
 M tests/Feature/Societe/PanneauSocieteTest.php
 M tests/Feature/Societe/TypeDActiviteTest.php
 M tests/Fixtures/contenu-signe/jour.json
 M tests/Fixtures/contenu-signe/nouvel-an.json
 M tests/Fixtures/contenu-signe/nuit-d-ete.json
 M tests/Fixtures/contenu-signe/nuit-d-hiver.json
 M tests/Fixtures/contenu-signe/outre-mer.json
 M tests/Fixtures/contenu-signe/premier-passage.json
 M tests/Fixtures/contenu-signe/second-passage.json
 M tests/TestCase.php
?? app/Domain/Cabinets/Services/AvisAuxClientsDuCabinet.php
?? app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php
?? app/Domain/Facturation/Actions/FaireLAvoirDeRegularisationAction.php
?? app/Domain/Facturation/Services/RegularisationDUneFacture.php
```

## Dernière course de la chaîne

Course `wf_1ce3e4cc-2f2`, étapes commencées (de la plus ancienne à la plus récente) :

```
A7b:implementation
```
