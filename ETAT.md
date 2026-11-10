# État du projet (généré automatiquement)

Mis à jour le 10/11/2026 à 00:36 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `6c7b3d1`. Commits locaux pas encore poussés :

```
50d39d1 Dit vrai sur les ventes dans l'avis de l'identité incomplète (constat C7)
ccaa905 Retire SQLite : PostgreSQL seul moteur, du code à la CI (lot PG)
7f4d7ae Résout le verrou de Psalm pour PHP 8.3.16 (CI, analyse des flux)
```

Derniers commits poussés :

```
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
9053757 Outille les tests en navigateur avec Pest 4 et Playwright (lot NAV-1 1/2)
92fd524 Relecture du lot SL de l’erreur 419 des fenêtres de recherche
b06c922 Corrige l'erreur 419 des fenêtres de recherche de clients et de produits (lot SL)
f9b669c Relecture du lot A7b des suites du lot A7
81de7e8 Complète les tests du lot A7b et indexe le journal d'audit par action
0d2b6a3 Régularise les factures après une correction de SIREN et corrige le SIREN d'un cabinet (lot A7b)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M README.md
 M app/Console/Commands/MarquerFacturesEnRetard.php
 M app/Domain/Cabinets/Models/InvitationCabinet.php
 M app/Domain/Cabinets/Models/LienCabinet.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/Catalogue/Actions/TrouverOuCreerServiceAction.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Tenancy/Actions/CreerEtablissementAction.php
 M app/Domain/Tenancy/Actions/RouvrirMagasinAction.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Models/Societe.php
 M app/Domain/Tenancy/Services/GenerateurDIdentifiants.php
 M tests/Feature/Authorization/GardesDesCabinetsEnBaseTest.php
 M tests/Feature/Authorization/GardesDesDroitsEnBaseConcurrenceTest.php
 M tests/Feature/Authorization/GardesDesDroitsEnBaseTest.php
 M tests/Feature/Authorization/GardesDesTypesDActiviteTest.php
 M tests/Feature/CaisseSecurisee/RegistreFiscalTest.php
 M tests/Feature/Calendrier/ReglesDuCalendrierTest.php
 M tests/Feature/Catalogue/TrouverOuCreerServiceActionTest.php
 M tests/Feature/Editeur/LibererSiretTest.php
 M tests/Feature/Facturation/EmettreFactureActionTest.php
 M tests/Feature/RetourArriereDesMigrationsTest.php
 M tests/Feature/Societe/CreerEtablissementActionTest.php
 M tests/Feature/Societe/IdentifiantsTest.php
 M tests/Feature/Tenancy/AccesAuxPanneauxTest.php
?? tests/Feature/CaisseSecurisee/ProtectionDesTablesTest.php
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
```
