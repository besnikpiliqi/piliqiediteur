# État du projet (généré automatiquement)

Mis à jour le 10/11/2026 à 14:37 UTC par pilotage-sync.sh : « CI du lot PG verte (b93aeff) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b93aeff`. Commits locaux pas encore poussés :

```
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
 M README.md
 M tests/Feature/Authorization/ControleDesDroitsTest.php
 M tests/Unit/CalculateurLigneLimitesTest.php
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
