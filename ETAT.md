# État du projet (généré automatiquement)

Mis à jour le 18/11/2026 à 22:50 UTC par pilotage-sync.sh : « SUPER PDP e-reporting : ne jamais déclarer en double ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `7c5edc7a`. Commits locaux pas encore poussés :

```
2b75fa23 Devis : dépannage à domicile, rétractation et acceptation signée
a4ca0549 Réparation de la CI rouge de 7c5edc7a
```

Derniers commits poussés :

```
7c5edc7a Relecture du lot DEMO-COPIE-3
7d521432 Démonstration : bandeau, e-mails captés, tâches et mises à jour des copies
33c56754 Réparation de la CI rouge de 52dab0ff
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M README.md
 M app/Domain/Facturation/Actions/CreerDevisAction.php
 M app/Domain/Facturation/Actions/EmettreDevisAction.php
 M app/Domain/Facturation/Enums/NatureEnvoi.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Models/EnvoiDocument.php
 M app/Domain/Facturation/Services/MentionsFiscales.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Filament/App/Resources/ClientResource.php
 M app/Filament/App/Resources/DevisResource.php
 M app/Filament/App/Resources/DevisResource/Pages/EditDevis.php
 M app/Http/Controllers/ReponseAuDevisController.php
 M database/seeders/Semis/DocumentsDesCas.php
 M resources/views/devis/pdf.blade.php
 M resources/views/devis/reponse.blade.php
 M resources/views/documents/pdf/lignes.blade.php
 M tests/Browser/Parcours/DevisDeDepannageADomicileTest.php
 M tests/Browser/Parcours/ReponseAuDevisEnLigneTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Calendrier/IsolationDesCalendriersTest.php
 M tests/Feature/Demonstration/BasesModelesTest.php
 M tests/Feature/Facturation/DevisDeDepannageADomicileTest.php
 M tests/Feature/Facturation/ReponseAuDevisEnLigneTest.php
 M tests/Feature/SemisCompletTest.php
?? resources/views/devis/document.blade.php
```

## Dernière course de la chaîne

Course `wf_a558d16d-efa`, étapes commencées (de la plus ancienne à la plus récente) :

```
DEVIS-RET:implementation
DEVIS-RET:relecture1
DEVIS-RET:relecture2
DEVIS-RET:correction
```
