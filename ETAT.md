# État du projet (généré automatiquement)

Mis à jour le 16/11/2026 à 19:22 UTC par pilotage-sync.sh : « Point de suivi 16/11 19:23 UTC : DEMO-COPIE-2 partie 5 ».

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
 M .github/workflows/tests.yml
 M CLAUDE.md
 M README.md
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Domain/Cabinets/Actions/InviterSonCabinetACreerSonEspaceAction.php
 M app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php
 M app/Domain/CaisseSecurisee/Actions/ExporterJournalDesEvenementsAction.php
 M app/Domain/CaisseSecurisee/ModuleFiscal.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/CaisseSecurisee/Services/SignataireFiscal.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/Calendrier/Calendrier.php
 M app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php
 M app/Domain/Facturation/Services/Emetteur.php
 M app/Domain/Tenancy/Actions/CloreLInscriptionAction.php
 M app/Domain/Tenancy/Actions/CreerSocieteAction.php
 M app/Domain/Tenancy/Actions/DemanderUneNouvelleAdresseAction.php
 M app/Domain/Tenancy/Actions/RevoquerSocieteAction.php
 M app/Domain/Tenancy/Actions/SaisirCodeDeConfirmationAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Isolation/ControlesDeLApplication.php
 M app/Domain/Tenancy/Isolation/ControlesDeLaBase.php
 M app/Domain/Tenancy/Isolation/ReglesDIsolation.php
 M app/Domain/Tenancy/Services/CreationDesComptes.php
 M app/Domain/Tenancy/Support/NomsPolymorphes.php
 M app/Filament/App/Pages/Auth/InscriptionSociete.php
 M app/Filament/Cabinet/Pages/TableauDeBordDuCabinet.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Http/Middleware/AjouterLesEnTetesDeSecurite.php
 M app/Integrations/Sirene/FauxRepertoireSirene.php
 M app/Integrations/Vies/ClientVies.php
 M app/Providers/AppServiceProvider.php
 M app/Providers/EventServiceProvider.php
 M app/Providers/FournisseursExternesServiceProvider.php
 M bootstrap/app.php
 M bootstrap/providers.php
 M composer.json
 M composer.lock
 M config/cache.php
 M config/caisse_securisee.php
 M config/database.php
 M config/journal_audit.php
 M config/mail.php
 M config/piliqi.php
 M config/queue.php
 M config/services.php
 M config/session.php
 M database/migrations/0000_00_00_000000_fixer_le_fuseau_par_defaut_de_la_base.php
 M database/seeders/DatabaseSeeder.php
 M database/seeders/Semis/ActiviteDesMagasins.php
 M database/seeders/Semis/ChargesDesSocietes.php
 M database/seeders/Semis/CommandesDesProfessionnels.php
 M database/seeders/Semis/ComptesDesCas.php
 M database/seeders/Semis/DerniersGestes.php
 M database/seeders/Semis/DocumentsDesCas.php
 M database/seeders/Semis/HorlogeDuSemis.php
 M database/seeders/Semis/MotsDePasseDuSemis.php
 M database/seeders/Semis/SemisComplet.php
 M database/seeders/Semis/SocietesDesCas.php
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
```
