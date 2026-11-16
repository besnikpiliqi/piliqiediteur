# État du projet (généré automatiquement)

Mis à jour le 16/11/2026 à 11:13 UTC par pilotage-sync.sh : « Point de suivi 16/11 11:12 UTC : DEMO-COPIE-1 partie 2 ».

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
 M .env.example
 M CLAUDE.md
 M README.md
 M app/Console/Commands/Editeur/CloreLInscription.php
 M app/Console/Commands/Editeur/ExaminerLeSiren.php
D  app/Console/Commands/FermerDemonstrationsInactives.php
 M app/Console/Commands/MarquerFacturesEnRetard.php
 M app/Console/Commands/RelancerFacturesImpayees.php
 M app/Console/Commands/ReverifierSirene.php
 M app/Console/Commands/VerifierLIsolation.php
 M app/Console/SemisEnCours.php
 M app/Domain/Authorization/AutorisationsSociete.php
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Actions/ConfierDesClientsAction.php
 M app/Domain/Cabinets/Actions/DeclarerSonExpertComptableAction.php
 M app/Domain/Cabinets/Actions/MettreFinAuLienAction.php
 M app/Domain/Cabinets/Actions/RattacherUneDeclarationAction.php
 M app/Domain/Cabinets/Actions/ReglerLesAutorisationsDuCabinetAction.php
 M app/Domain/Cabinets/Models/LienCabinet.php
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/Cabinets/Services/ExpertsComptablesDeclares.php
 M app/Domain/Cabinets/Services/TableauDeBordDesClients.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/Cabinets/Support/IdentiteDansLeJournal.php
 M app/Domain/CaisseSecurisee/Enums/CodeEvenement.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/Charges/Models/Charge.php
 M app/Domain/Charges/Services/ChargesAFaire.php
 M app/Domain/Charges/Services/ChargesDeLaSociete.php
 M app/Domain/Charges/Services/DroitsSurLesCharges.php
 M app/Domain/Charges/Services/EcritureDUneCharge.php
 M app/Domain/Charges/Services/TvaDeLaPeriode.php
 M app/Domain/Charges/Support/AccesAuxCharges.php
 M app/Domain/Charges/Support/GardesDesCharges.php
 M app/Domain/Facturation/Actions/CloturerCaisseAction.php
 M app/Domain/Facturation/Actions/CloturerLesJourneesAction.php
 M app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Actions/RegulariserUneExonerationNonProuveeAction.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Jobs/SubmitFactureToPdpJob.php
 M app/Domain/Facturation/Listeners/DeclencherTransmissionPdpApresEmissionFacture.php
 M app/Domain/Facturation/Models/ClotureCaissePersonnelle.php
 M app/Domain/Facturation/Models/LienDuDevis.php
 M app/Domain/Facturation/Services/ApercuEReporting.php
 M app/Domain/Facturation/Services/CaissesDeLaSociete.php
 M app/Domain/Facturation/Services/ClotureCaissePdfGenerator.php
 M app/Domain/Facturation/Services/DocumentsDeLaPeriode.php
 M app/Domain/Facturation/Services/EReportingDesParticuliers.php
 M app/Domain/Facturation/Services/Emetteur.php
 M app/Domain/Facturation/Services/FacturesAEncaisserDeLaSociete.php
 M app/Domain/Facturation/Services/RecuPaiementPdfGenerator.php
 M app/Domain/Facturation/Services/RegularisationDUneFacture.php
 M app/Domain/Facturation/Support/GardesDeLaVente.php
 M app/Domain/TableauDeBord/Services/PerimetreDesGraphiques.php
 M app/Domain/TableauDeBord/Support/Perimetre.php
 M app/Domain/Tenancy/Actions/AccepterLaTransmissionDeLaProprieteAction.php
```

## Dernière course de la chaîne

Course `wf_b2591b35-919`, étapes commencées (de la plus ancienne à la plus récente) :

```
DEMO-COPIE-1:implementation
DEMO-COPIE-1:implementation-2
```
