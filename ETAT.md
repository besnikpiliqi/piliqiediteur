# État du projet (généré automatiquement)

Mis à jour le 10/11/2026 à 19:46 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b93aeff`. Commits locaux pas encore poussés :

```
97c16bc Type les écrans, les commandes et la configuration, et passe Larastan au niveau 7 (lot TY1 8/n)
430539c Type les ventes, les achats, la caisse et le stock (Larastan niveau 7, lot TY1 7/n)
0e5cf71 Type les lectures des droits, de la société et des cabinets (Larastan niveau 7, lot TY1 6/n)
8b3b731 Étend l'analyse statique à tout le code de l'application hors des tests (lot TY1 5/n)
6fcefd0 Lit comme absent un témoin de poste envoyé en tableau, au lieu d'une erreur 500 (lot TY1 4/n)
d8d2ee3 Contrôle les seuils de l'analyse statique par ses paramètres effectifs (lot TY1 3/n)
5946e69 Arrête les mutants survivants connus des calculs et des droits (lot TY1 2/n)
2c55bad Ajoute le test d'empreinte de la caisse et des PDF (lot TY1 1/n)
```

Derniers commits poussés :

```
b93aeff Relecture du lot PG de la série 8
d8deaf9 Mute en entier chaque fichier sensible changé par l'envoi (CI, lot PG)
4e6d832 Prouve chaque protection de la base par un test qui échoue sans elle (lot PG)
50d39d1 Dit vrai sur les ventes dans l'avis de l'identité incomplète (constat C7)
ccaa905 Retire SQLite : PostgreSQL seul moteur, du code à la CI (lot PG)
7f4d7ae Résout le verrou de Psalm pour PHP 8.3.16 (CI, analyse des flux)
6c7b3d1 Relecture du lot QA des outils de qualité
09225fa Enregistre les scores de mutation des calculs et des droits et monte les seuils de couverture (lot QA 4/4)
aedb582 Tue les mutants survivants des calculs et des droits par des tests et un code simplifié (lot QA 3/4)
53beb37 Plafonne les requêtes des espaces de la société et du cabinet et limite la mutation aux calculs et aux droits (lot QA 2/3)
1b34f61 Ajoute les outils de qualité et met la CI sur PostgreSQL seulement (lot QA 1/2)
0d37396 Met à jour six paquets Symfony qui portaient des failles connues
c7b1ec3 Relecture du lot EX de la série 8
938db39 Ajoute le téléchargement des documents par période (lot EX 2/2)
3c83ee0 Joint la pièce du fournisseur aux achats saisis à la main (lot EX 1/2)
074eaee Relecture du lot TR de la série 8
d57bdab Garde la TVA et le PU d'achat qu'écrit la facture du fournisseur
0afc14c Émet et encaisse d'un geste, ajoute le reçu de paiement et place les règlements à gauche des totaux
1d3278a Relecture du lot NAV-1 des tests en navigateur
523e74f Balaye chaque panneau rôle par rôle et ajoute les premiers parcours en navigateur (lot NAV-1 2/2)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
M  app/Console/Commands/RelancerFacturesImpayees.php
M  app/Domain/Cabinets/Actions/AccepterLInvitationAction.php
M  app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php
M  app/Domain/Cabinets/Actions/ConfierDesClientsAction.php
M  app/Domain/Cabinets/Actions/ConstaterLInscriptionALOrdreAction.php
M  app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php
M  app/Domain/Cabinets/Actions/MettreFinAuLienAction.php
M  app/Domain/Cabinets/Actions/RefuserLaDemandeDeLienAction.php
M  app/Domain/Cabinets/Actions/ReglerLesAutorisationsDuCabinetAction.php
M  app/Domain/Cabinets/Actions/RetirerLaDemandeDeLienAction.php
M  app/Domain/Cabinets/Actions/VerifierLOrdreAction.php
M  app/Domain/Cabinets/Models/InvitationCabinet.php
M  app/Domain/Cabinets/Models/LienCabinet.php
M  app/Domain/Cabinets/Services/AccesDesCabinets.php
M  app/Domain/Cabinets/Services/EnvoiDesLiensDesCabinets.php
M  app/Domain/Cabinets/Services/TableauDeBordDesClients.php
M  app/Domain/Tenancy/Actions/AnnulerCessionAction.php
M  app/Domain/Tenancy/Actions/CloreLInscriptionAction.php
M  app/Domain/Tenancy/Actions/CorrigerSirenAction.php
M  app/Domain/Tenancy/Actions/DemanderUneNouvelleAdresseAction.php
M  app/Domain/Tenancy/Actions/ExaminerSirenAction.php
M  app/Domain/Tenancy/Actions/FermerMagasinAction.php
M  app/Domain/Tenancy/Actions/LibererLeSiretAction.php
M  app/Domain/Tenancy/Actions/RevoquerSocieteAction.php
M  app/Domain/Tenancy/Actions/SaisirCodeDeConfirmationAction.php
M  app/Domain/Tenancy/Concerns/BelongsToMagasin.php
M  app/Domain/Tenancy/Concerns/BelongsToSociete.php
M  app/Domain/Tenancy/Jobs/PreparerLeTelechargementDesDocumentsJob.php
M  app/Domain/Tenancy/Models/Affectation.php
M  app/Domain/Tenancy/Models/ChangementFinExercice.php
M  app/Domain/Tenancy/Models/CodeConfirmation.php
M  app/Domain/Tenancy/Models/DemandeInscription.php
M  app/Domain/Tenancy/Models/EntreeAudit.php
M  app/Domain/Tenancy/Models/Magasin.php
M  app/Domain/Tenancy/Models/PeriodeActivite.php
M  app/Domain/Tenancy/Models/TelechargementDeDocuments.php
M  app/Domain/Tenancy/Models/TransmissionPropriete.php
M  app/Domain/Tenancy/Services/AffectationsDesMembres.php
M  app/Domain/Tenancy/Services/CodeMagasin.php
M  app/Domain/Tenancy/Services/CourriersDeVerification.php
M  app/Domain/Tenancy/Services/ExercicesDeLaSociete.php
M  app/Domain/Tenancy/Services/JournalDesDroits.php
M  app/Domain/Tenancy/Services/PiecesDeVerification.php
M  app/Domain/Tenancy/Services/PorteeDesDroits.php
M  app/Domain/Tenancy/Services/ReverificationSirene.php
M  app/Domain/Tenancy/Services/SuiviDesConnexions.php
M  app/Domain/Tenancy/Services/TenantContext.php
A  app/Domain/Tenancy/Support/CompteConnecte.php
A  app/Domain/Tenancy/Support/ModeleRelu.php
A  app/Domain/Tenancy/Support/ValeurRequise.php
M  app/Http/Controllers/ConfirmerInscriptionController.php
M  app/Http/Controllers/TelechargerLesDocumentsController.php
M  app/Http/Middleware/ExigerSonPropreMotDePasse.php
M  app/Integrations/Vies/ClientVies.php
M  app/Models/User.php
M  app/Providers/Filament/AppPanelProvider.php
A  tests/Feature/Tenancy/CompteConnecteTest.php
A  tests/Feature/Tenancy/ModeleReluTest.php
M  tests/Qualite/qualite.php
A  tests/Unit/ValeurRequiseTest.php
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
```
