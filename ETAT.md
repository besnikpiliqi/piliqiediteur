# État du projet (généré automatiquement)

Mis à jour le 16/11/2026 à 04:24 UTC par pilotage-sync.sh : « Point 04:25 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b6015883`. Commits locaux pas encore poussés :

```
5418d777 Graphiques : en-tête des cartes
90f7ab33 Télécharger les documents : les charges et leurs pièces
a9bb1a78 Charges : état téléchargeable en PDF et en Excel
90a4426c Écrans : classes de style absentes de la feuille de Filament
9e14f02f Semis complet de toutes les tables et de tous les cas
93939942 Réparation de la CI rouge de b6015883
```

Derniers commits poussés :

```
b6015883 Relecture du lot REGUL
be209079 Réparation de la CI rouge de 8ce8bd4 (cartes des notifications)
e405d27b Régularisation après une correction de SIREN et relances (lot REGUL)
220d40b4 Réparation de la CI rouge de 8ce8bd4
8ce8bd4a Relecture du lot CHARGES
68531b28 Charges de la société et TVA réelle (lot CHARGES)
fcbf1166 Réparation de la CI rouge de 743f9bc
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Domain/CaisseSecurisee/Services/ContenuFiscal.php
 M app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php
 M app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php
 M app/Domain/Facturation/Actions/RegulariserUneExonerationNonProuveeAction.php
 M app/Domain/Facturation/Concerns/NoteSaNatureDOperation.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Services/ControleNatureOperation.php
 M app/Domain/Facturation/Services/MentionsFiscales.php
 M app/Domain/Facturation/Services/PreuvesRequises.php
 M app/Filament/App/Resources/DevisResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/Concerns/ChoisitLaNatureDeLOperation.php
 M database/migrations/2026_10_03_100001_create_devis_table.php
 M database/migrations/2026_10_03_100003_create_factures_table.php
 M database/seeders/Semis/DocumentsDesCas.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/CaisseSecurisee/EmissionSigneeTest.php
 M tests/Feature/CaisseSecurisee/EmpreinteDeLaCaisseEtDesPdfTest.php
 M tests/Feature/Calendrier/ContenuSigneDeReferenceTest.php
 M tests/Feature/Calendrier/HeureLocaleTest.php
 M tests/Feature/Calendrier/NuitDeParisTest.php
 M tests/Feature/Facturation/FacturePdfGeneratorTest.php
 M tests/Feature/Facturation/NatureOperationTest.php
 M tests/Feature/Facturation/PreuvesOperationTest.php
 M tests/Feature/Facturation/RegularisationDUneFactureTest.php
 M tests/Feature/Filament/DevisResourceTest.php
 M tests/Feature/Filament/FactureResourceTest.php
 M tests/Feature/SemisCompletTest.php
 M tests/Fixtures/contenu-signe/jour.json
 M tests/Fixtures/contenu-signe/nouvel-an.json
 M tests/Fixtures/contenu-signe/nuit-d-ete.json
 M tests/Fixtures/contenu-signe/nuit-d-hiver.json
 M tests/Fixtures/contenu-signe/outre-mer.json
 M tests/Fixtures/contenu-signe/premier-passage.json
 M tests/Fixtures/contenu-signe/second-passage.json
 M tests/Fixtures/empreinte-de-la-caisse/pdf/devis.txt
 M tests/Fixtures/empreinte-de-la-caisse/pdf/facture.txt
 M tests/Fixtures/empreinte-de-la-caisse/registre.txt
 M tests/Unit/matrice-des-decisions.php
?? tests/Browser/Parcours/DateDeLOperationTest.php
?? tests/Feature/Facturation/DateDeLOperationTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
CHARGES:implementation-2
CHARGES:implementation-3
CHARGES:relecture1
CHARGES:relecture2
CHARGES:correction
CHARGES:poussee
CHARGES:notes
REGUL:implementation
REGUL:implementation-2
REGUL:relecture1
REGUL:relecture2
REGUL:correction
REGUL:poussee
REGUL:notes
DATEOP:implementation
DATEOP:implementation-2
DATEOP:implementation-3
DATEOP:implementation-4
DATEOP:implementation-5
DATEOP:implementation-6
DATEOP:implementation-7
DATEOP:implementation-8
DATEOP:implementation-9
DATEOP:implementation-10
DATEOP:implementation-11
```
