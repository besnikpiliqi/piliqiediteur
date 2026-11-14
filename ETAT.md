# État du projet (généré automatiquement)

Mis à jour le 14/11/2026 à 16:50 UTC par pilotage-sync.sh : « 14/11 16:50 UTC : CI de DEMO-V verte ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `31a5a13`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
c54c221 Relecture du lot EC2 de l’expert-comptable de la société
f159294 Donne à la société un expert-comptable déclaré par le SIREN de son cabinet, rattaché tout seul au cabinet vérifié (lot EC2)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/Catalogue/Services/MargeDuCatalogue.php
 M app/Domain/Facturation/Actions/CreerAvoirAction.php
 M app/Domain/Facturation/Actions/EmettreAvoirAction.php
 M app/Domain/Facturation/Models/LigneFacture.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/ConstructeurLigneDocument.php
 M app/Domain/Facturation/Services/ContexteDeVente.php
 M app/Domain/Facturation/Services/MentionsFiscales.php
 M app/Domain/Facturation/Services/RegularisationDUneExoneration.php
 M app/Domain/Facturation/Services/RegularisationDUneFacture.php
 M app/Domain/Facturation/Services/TableauDesLignes.php
 M app/Filament/Concerns/AjouteDesLignesDeVente.php
 M app/Filament/Concerns/ChoisitLaNatureDeLOperation.php
 M resources/views/avoirs/pdf.blade.php
 M resources/views/devis/pdf.blade.php
 M resources/views/documents/pdf/en-tete.blade.php
 M resources/views/documents/pdf/lignes.blade.php
 M resources/views/documents/pdf/styles.blade.php
 M resources/views/documents/pdf/totaux.blade.php
 M resources/views/factures/pdf.blade.php
 M tests/Feature/Facturation/AvoirActionsTest.php
 M tests/Feature/Facturation/AvoirPdfGeneratorTest.php
 M tests/Feature/Facturation/ConstructeurLigneDocumentTest.php
 M tests/Feature/Facturation/CreerFactureActionTest.php
 M tests/Feature/Facturation/DevisPdfGeneratorTest.php
 M tests/Feature/Facturation/EmetteurFigeTest.php
 M tests/Feature/Facturation/EnregistrerVenteCaisseActionConcurrenceTest.php
 M tests/Feature/Facturation/ExempleDeQuinzeLignesTest.php
 M tests/Feature/Facturation/FacturePdfGeneratorTest.php
 M tests/Feature/Facturation/MentionsFiscalesTest.php
 M tests/Feature/Facturation/NatureOperationTest.php
 M tests/Feature/Facturation/PolitiqueDArrondiTest.php
 M tests/Feature/Facturation/RegularisationDUneFactureTest.php
 M tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php
RM tests/Feature/Facturation/RepartitionTvaParCumulTest.php -> tests/Feature/Facturation/TvaLigneParLigneTest.php
 M tests/Feature/Filament/FactureResourceTest.php
 M tests/Feature/Filament/TableauxDesFichesTest.php
 M tests/Feature/Proprietes/CalculsAuHasardTest.php
 M tests/Fixtures/empreinte-de-la-caisse/pdf/avoir.txt
 M tests/Fixtures/empreinte-de-la-caisse/pdf/bon-d-avoir.txt
 M tests/Fixtures/empreinte-de-la-caisse/pdf/devis.txt
 M tests/Fixtures/empreinte-de-la-caisse/pdf/facture.txt
 M tests/Fixtures/empreinte-de-la-caisse/pdf/ticket.txt
 M tests/Unit/CalculateurLigneAuHasardTest.php
 M tests/Unit/CalculateurLigneTest.php
 M tests/Unit/matrice-des-decisions.php
?? resources/views/documents/pdf/blocs.blade.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
DEMO-V:implementation-2
DEMO-V:implementation-3
DEMO-V:relecture1
DEMO-V:relecture2
DEMO-V:correction
DEMO-V:poussee
DEMO-V:notes
REGLE-B:implementation
```
