# État du projet (généré automatiquement)

Mis à jour le 13/11/2026 à 18:53 UTC par pilotage-sync.sh : « CI SEED verte ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `58ad4de`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
0b7e734 Écrit chaque jour de la relance en navigateur sans calcul sur un instant (CI)
d4a8247 Relecture du lot ML des e-mails vérifiés
0ad0710 Vérifie les e-mails sur Mailtrap et explique chaque échec d'envoi (lot ML)
8feeb83 Relecture du lot UI des écrans
238f430 Corrige les constats de l'éditeur sur les écrans de vente (lot UI)
e101d9f Lit l'écart d'horloge du diagnostic à un dixième de seconde près (CI)
b12a835 Relecture du lot DEMO de la démonstration
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Console/Commands/Editeur/ChangerLaFinDExercice.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/Contacts/Actions/ArchiverUneFicheAction.php
 M app/Domain/Contacts/Actions/CreerUneFicheAction.php
 M app/Domain/Contacts/Actions/ModifierUneFicheAction.php
 M app/Domain/Contacts/Models/Fournisseur.php
 M app/Domain/Facturation/Actions/CreerAvoirAction.php
 M app/Domain/Facturation/Actions/EnregistrerAvoirAction.php
 M app/Domain/Facturation/Enums/CategorieEReporting.php
 M app/Domain/Facturation/Services/ApercuEReporting.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/EReportingDesParticuliers.php
 M app/Domain/Facturation/Services/MentionsFiscales.php
 M app/Domain/Facturation/Services/PreuvesRequises.php
 M app/Domain/Facturation/Services/RegularisationDUneFacture.php
 M app/Domain/Fichiers/AnalyseurDePdf.php
 M app/Domain/Tenancy/Actions/ChangerFinExerciceAction.php
 M app/Domain/Tenancy/Actions/ModifierParametresFiscauxSocieteAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Models/ChangementFinExercice.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Models/Societe.php
 M app/Filament/App/Pages/Auth/InscriptionSociete.php
 M app/Filament/App/Resources/AvoirFournisseurResource.php
 M app/Filament/App/Resources/AvoirFournisseurResource/Pages/ListAvoirsFournisseur.php
 M app/Filament/App/Resources/DevisResource.php
 M app/Filament/App/Resources/EReportingResource.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/Societe/Pages/ParametresFiscauxSociete.php
 M app/Integrations/Rne/InpiRne.php
 M database/migrations/0001_01_00_000000_create_societes_table.php
 M database/migrations/2026_10_02_100016_create_changements_fin_exercice_table.php
 M database/migrations/2026_10_02_200002_create_fournisseurs_table.php
 M resources/views/filament/app/pages/auth/inscription-societe.blade.php
 M tests/Feature/Cabinets/MagasinsOuvertsAuCabinetTest.php
 M tests/Feature/CaisseSecurisee/CommandesModuleFiscalTest.php
 M tests/Feature/CaisseSecurisee/ProtectionDesTablesTest.php
 M tests/Feature/Editeur/ChangerFinExerciceTest.php
 M tests/Feature/Facturation/CloturerCaisseActionTest.php
 M tests/Feature/Facturation/EReportingDesParticuliersTest.php
 M tests/Feature/Facturation/FacturePdfGeneratorTest.php
 M tests/Feature/Facturation/MentionsFiscalesTest.php
 M tests/Feature/Filament/AvoirFournisseurResourceTest.php
 M tests/Feature/Filament/DevisResourceTest.php
 M tests/Feature/Filament/EReportingResourceTest.php
 M tests/Feature/Filament/TelechargerLesDocumentsTest.php
 M tests/Feature/Integrations/InpiRneTest.php
 M tests/Feature/OutillageTest.php
 M tests/Feature/Societe/MagasinResourceTest.php
 M tests/Feature/Tenancy/HistoriqueAuNomFigeTest.php
 M tests/Fixtures/empreinte-de-la-caisse/apercu-e-reporting.txt
 M tests/Unit/CalculateurLigneTest.php
 M tests/Unit/matrice-des-decisions.php
?? app/Domain/Facturation/Services/EncaissementsDesPrestations.php
?? app/Domain/Tenancy/Enums/OrganeDeLaDecision.php
?? tests/Feature/Societe/AssujettiUniqueTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
SEED:implementation
SEED:implementation-2
SEED:relecture1
SEED:relecture2
SEED:correction
SEED:poussee
SEED:notes
ER-PRO:implementation
```
