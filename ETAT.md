# État du projet (généré automatiquement)

Mis à jour le 16/11/2026 à 08:59 UTC par pilotage-sync.sh : « Q2 suspendue ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `d15af36b`. Commits locaux pas encore poussés :

```
3bdfe83b Réparation de la CI rouge de d15af36
```

Derniers commits poussés :

```
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
743f9bc2 Relecture du lot GRAPH
b0d52373 Graphiques du tableau de bord (lot GRAPH)
d0bf1bb0 Réparation de la CI rouge de 036841d
036841d6 Relecture du lot REGLE-B
f9e4ea17 Ventes calculées ligne par ligne (règle (b)) et PDF aux modèles M6-b et P1
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Domain/Facturation/Actions/CreerDevisAction.php
 M app/Domain/Facturation/Actions/EmettreDevisAction.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Actions/ModifierDevisAction.php
 M app/Domain/Facturation/Actions/RepondreAuDevisEnLigneAction.php
 M app/Domain/Facturation/Enums/TypeDocument.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Mail/DocumentMail.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Models/LienDuDevis.php
 M app/Domain/Facturation/Models/ReponseDuDevis.php
 M app/Domain/Facturation/Notifications/ReponseDuClientAuDevis.php
 M app/Domain/Facturation/Services/DevisPdfGenerator.php
 M app/Domain/Tenancy/Actions/ModifierLesParametresDuMagasinAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Filament/App/Pages/ParametresFactures.php
 M app/Filament/App/Resources/DevisResource.php
 M app/Filament/App/Resources/DevisResource/Pages/CreateDevis.php
 M app/Filament/App/Resources/DevisResource/Pages/EditDevis.php
 M app/Http/Controllers/ReponseAuDevisController.php
 M database/migrations/0001_01_01_000000_create_magasins_table.php
 M database/migrations/2026_10_03_100001_create_devis_table.php
 M database/migrations/2026_10_14_100008_create_reponses_des_devis_table.php
 M database/seeders/Semis/DocumentsDesCas.php
 M database/seeders/Semis/SemisDeDemonstration.php
 M resources/views/devis/pdf.blade.php
 M resources/views/devis/reponse-enregistree.blade.php
 M resources/views/devis/reponse.blade.php
 M resources/views/documents/pdf/styles.blade.php
 M resources/views/mail/document.blade.php
 M tests/Browser/Parcours/ReponseAuDevisEnLigneTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/CaisseSecurisee/ProtectionDesTablesTest.php
 M tests/Feature/Calendrier/IsolationDesCalendriersTest.php
 M tests/Feature/Facturation/EnvoyerDocumentJobConcurrenceTest.php
 M tests/Feature/Facturation/EnvoyerDocumentJobTest.php
 M tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php
 M tests/Feature/Filament/ParametresFacturesTest.php
 M tests/Feature/SemisCompletTest.php
 M tests/Feature/Tenancy/TenantIsolationTest.php
 M tests/Unit/matrice-des-decisions.php
?? app/Domain/Facturation/Services/DepannageADomicile.php
?? app/Domain/Facturation/Services/DroitDeRetractation.php
?? tests/Browser/Parcours/DevisDeDepannageADomicileTest.php
?? tests/Feature/Facturation/DevisDeDepannageADomicileTest.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
DATEOP:relecture1
DATEOP:relecture2
DATEOP:correction
DATEOP:poussee
DATEOP:notes
DEVIS-RET:implementation
DEVIS-RET:implementation-2
```
