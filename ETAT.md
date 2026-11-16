# État du projet (généré automatiquement)

Mis à jour le 16/11/2026 à 15:15 UTC par pilotage-sync.sh : « Point de suivi 16/11 15:20 UTC : DEMO-COPIE-1 poussé ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `7f9e16ca`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
743f9bc2 Relecture du lot GRAPH
b0d52373 Graphiques du tableau de bord (lot GRAPH)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M .env.example
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Domain/Cabinets/Actions/InviterSonCabinetACreerSonEspaceAction.php
 M app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php
 M app/Domain/CaisseSecurisee/Services/SignataireFiscal.php
 M app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php
 M app/Domain/Tenancy/Actions/CloreLInscriptionAction.php
 M app/Domain/Tenancy/Actions/CreerSocieteAction.php
 M app/Domain/Tenancy/Actions/DemanderUneNouvelleAdresseAction.php
 M app/Domain/Tenancy/Actions/RevoquerSocieteAction.php
 M app/Domain/Tenancy/Actions/SaisirCodeDeConfirmationAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Isolation/ReglesDIsolation.php
 M app/Domain/Tenancy/Services/CreationDesComptes.php
 M app/Filament/App/Pages/Auth/InscriptionSociete.php
 M app/Filament/Cabinet/Pages/TableauDeBordDuCabinet.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Http/Middleware/AjouterLesEnTetesDeSecurite.php
 M app/Integrations/Vies/ClientVies.php
 M app/Providers/AppServiceProvider.php
 M app/Providers/EventServiceProvider.php
 M app/Providers/FournisseursExternesServiceProvider.php
 M bootstrap/app.php
 M bootstrap/providers.php
 M config/caisse_securisee.php
 M config/database.php
 M config/journal_audit.php
 M config/piliqi.php
 M config/services.php
 M config/session.php
 M database/seeders/DatabaseSeeder.php
 M phpunit.xml
D  public/robots.txt
 M resources/views/cabinets/invitation-invalide.blade.php
 M resources/views/cabinets/invitation-refusee.blade.php
 M resources/views/cabinets/invitation.blade.php
 M resources/views/cabinets/lien-expert-comptable-invalide.blade.php
 M resources/views/cabinets/lien-expert-comptable.blade.php
 M resources/views/cabinets/rattachement-invalide.blade.php
 M resources/views/cabinets/rattachement.blade.php
 M resources/views/code/saisir.blade.php
 M resources/views/devis/reponse-enregistree.blade.php
 M resources/views/devis/reponse-invalide.blade.php
 M resources/views/devis/reponse.blade.php
 M resources/views/errors/mise-en-page.blade.php
 M resources/views/filament/cabinet/pages/tableau-de-bord-du-cabinet.blade.php
 M resources/views/filament/societe/pages/tableau-de-bord.blade.php
 M resources/views/inscription/confirmer.blade.php
 M resources/views/profil/adresse.blade.php
 M routes/web.php
 M tests/Feature/DatabaseSeederTest.php
 M tests/Feature/Integrations/AnnuaireDeLOrdreTest.php
 M tests/Feature/Providers/FournisseursExternesServiceProviderTest.php
 M tests/Feature/SemisCompletTest.php
 M tests/SemeSansTransaction.php
?? app/Console/Commands/GenererCleDesMessagesDeLaDemonstration.php
?? app/Console/Commands/MarquerLesFinsDesDemonstrations.php
?? app/Console/Commands/RelancerLesMessagesDeLaDemonstration.php
?? app/Domain/Demonstration/
?? app/Domain/Tenancy/Rules/PasUnDomaineReserve.php
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
```
