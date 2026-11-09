# État du projet (généré automatiquement)

Mis à jour le 09/11/2026 à 22:01 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `0d37396`. Commits locaux pas encore poussés :

```
09225fa Enregistre les scores de mutation des calculs et des droits et monte les seuils de couverture (lot QA 4/4)
aedb582 Tue les mutants survivants des calculs et des droits par des tests et un code simplifié (lot QA 3/4)
53beb37 Plafonne les requêtes des espaces de la société et du cabinet et limite la mutation aux calculs et aux droits (lot QA 2/3)
1b34f61 Ajoute les outils de qualité et met la CI sur PostgreSQL seulement (lot QA 1/2)
```

Derniers commits poussés :

```
0d37396 Met à jour six paquets Symfony qui portaient des failles connues
c7b1ec3 Relecture du lot EX de la série 8
938db39 Ajoute le téléchargement des documents par période (lot EX 2/2)
3c83ee0 Joint la pièce du fournisseur aux achats saisis à la main (lot EX 1/2)
074eaee Relecture du lot TR de la série 8
d57bdab Garde la TVA et le PU d'achat qu'écrit la facture du fournisseur
0afc14c Émet et encaisse d'un geste, ajoute le reçu de paiement et place les règlements à gauche des totaux
1d3278a Relecture du lot NAV-1 des tests en navigateur
523e74f Balaye chaque panneau rôle par rôle et ajoute les premiers parcours en navigateur (lot NAV-1 2/2)
9053757 Outille les tests en navigateur avec Pest 4 et Playwright (lot NAV-1 1/2)
92fd524 Relecture du lot SL de l’erreur 419 des fenêtres de recherche
b06c922 Corrige l'erreur 419 des fenêtres de recherche de clients et de produits (lot SL)
f9b669c Relecture du lot A7b des suites du lot A7
81de7e8 Complète les tests du lot A7b et indexe le journal d'audit par action
0d2b6a3 Régularise les factures après une correction de SIREN et corrige le SIREN d'un cabinet (lot A7b)
53ed088 Relecture du lot A7 de l'architecture société
137334e Ajoute l'examen et la correction d'un SIREN par l'éditeur (lot A7)
0772af0 Ouvre le journal d'audit au propriétaire et ajoute les procédures de l'éditeur (lot A7)
d232b4f Pose le fuseau UTC de la base avant le test du diagnostic qui le lit
5745e7a Relecture du lot L1 de l'architecture société
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M .github/workflows/tests.yml
 M README.md
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/Facturation/Services/ImputationDuCredit.php
 M app/Domain/Tenancy/Actions/AnnulerUneNominationAction.php
 M app/Domain/Tenancy/Actions/ChangerLeRoleDeSocieteAction.php
 M app/Domain/Tenancy/Actions/ModifierAdministrateurAction.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Services/AffectationsDesMembres.php
 M app/Http/Middleware/AjouterLesEnTetesDeSecurite.php
 M app/Models/User.php
 M psalm.xml
 M tests/Browser/Parcours/PiecesDuFournisseurTest.php
 M tests/Feature/Authorization/GardesDesDroitsEnBaseTest.php
 M tests/Feature/Authorization/LimitesDesDroitsTest.php
 M tests/Feature/Cabinets/AccepterLInvitationTest.php
 M tests/Feature/Cabinets/CollaborateursTest.php
 M tests/Feature/Cabinets/InvitationsTest.php
 M tests/Feature/Cabinets/IsolationDesCabinetsTest.php
 M tests/Feature/EnTetesDeSecuriteTest.php
 M tests/Feature/Proprietes/AchatsEtStockAuHasardTest.php
 M tests/Feature/Proprietes/DroitsEtIsolationAuHasardTest.php
 M tests/Feature/Societe/AdministrateursTest.php
 M tests/Feature/Societe/AutorisationsDesMembresTest.php
 M tests/Qualite/parties.php
 M tests/Qualite/qualite.php
 M tests/Qualite/seuils.json
?? psalm-temoin.xml
?? tests/Feature/Qualite/OutilDeQualiteTest.php
?? tests/Qualite/Psalm/
?? tests/Unit/RegleDuMotDePasseTest.php
```

## Dernière course de la chaîne

Course `wf_54289edd-a97`, étapes commencées (de la plus ancienne à la plus récente) :

```
QA:implementation
QA:relecture1
QA:relecture2
QA:correction
```
