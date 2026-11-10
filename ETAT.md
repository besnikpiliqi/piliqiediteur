# État du projet (généré automatiquement)

Mis à jour le 10/11/2026 à 17:43 UTC par pilotage-sync.sh : « Point de suivi de 17 h 35 UTC ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b93aeff`. Commits locaux pas encore poussés :

```
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
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Cabinets/Actions/AccepterLInvitationAction.php
 M app/Domain/Cabinets/Actions/ConfierDesClientsAction.php
 M app/Domain/Cabinets/Models/LienCabinet.php
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/Cabinets/Services/TableauDeBordDesClients.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/Recherche/RechercheSansAccents.php
 M app/Domain/Tenancy/Actions/AnnulerCessionAction.php
 M app/Domain/Tenancy/Actions/CorrigerLeTerritoireAction.php
 M app/Domain/Tenancy/Actions/CreerEtablissementAction.php
 M app/Domain/Tenancy/Actions/FermerLaDemonstrationAction.php
 M app/Domain/Tenancy/Actions/FermerMagasinAction.php
 M app/Domain/Tenancy/Actions/RevoquerSocieteAction.php
 M app/Domain/Tenancy/Actions/SaisirCodeDeConfirmationAction.php
 M app/Domain/Tenancy/Jobs/PreparerLeTelechargementDesDocumentsJob.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Models/TelechargementDeDocuments.php
 M app/Domain/Tenancy/Notifications/AvisDuTelechargementDesDocuments.php
 M app/Domain/Tenancy/Services/JetonsDuLien.php
 M app/Domain/Tenancy/Services/JournalDesDroits.php
 M app/Domain/Tenancy/Services/PeriodesDActivite.php
 M app/Domain/Tenancy/Services/PiecesDeVerification.php
 M app/Domain/Tenancy/Services/SiretsDesMagasins.php
 M app/Domain/Tenancy/Support/FicheLue.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Domain/Tenancy/Support/GardesDesIdentifiants.php
 M app/Domain/Tenancy/Support/GardesDesTypesDActivite.php
 M app/Http/Middleware/FaireConfianceAuxMandataires.php
 M app/Integrations/EnvoiRecommande/AdressePostale.php
 M app/Integrations/EnvoiRecommande/DestinataireDuCourrier.php
 M app/Models/User.php
?? app/Domain/Tenancy/Support/EntetesDeConfiance.php
?? app/Domain/Tenancy/Support/ExpressionDUneColonne.php
?? app/Domain/Tenancy/Support/LitteralSql.php
?? app/Domain/Tenancy/Support/ResultatDePhp.php
?? app/Domain/Tenancy/Support/SensDuTri.php
?? app/Domain/Tenancy/Support/ValeursLues.php
?? tests/Feature/Tenancy/ExpressionDUneColonneTest.php
?? tests/Feature/Tenancy/LitteralSqlTest.php
?? tests/Unit/EntetesDeConfianceTest.php
?? tests/Unit/ResultatDePhpTest.php
?? tests/Unit/SensDuTriTest.php
?? tests/Unit/ValeursLuesTest.php
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
