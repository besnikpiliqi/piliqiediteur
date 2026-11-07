# État du projet (généré automatiquement)

Mis à jour le 07/11/2026 à 14:37 UTC par pilotage-sync.sh : « 07/11 14 h 37 : course coupée par la limite d'usage, reprise de TR:correction ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `1d3278a`. Commits locaux pas encore poussés :

```
d57bdab Garde la TVA et le PU d'achat qu'écrit la facture du fournisseur
0afc14c Émet et encaisse d'un geste, ajoute le reçu de paiement et place les règlements à gauche des totaux
```

Derniers commits poussés :

```
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
8b15aec Outille le développement local et fixe le fuseau UTC de la base (lot L1)
01fe217 Relecture du lot EC de l’espace des cabinets
93ea590 Retire les experts-comptables de la société et réécrit CLAUDE.md (lot EC 4/4)
94ada86 Lie les cabinets à leurs clients : invitations, demandes, autorisations, accès (lot EC 3/4)
9671a29 Donne au cabinet ses collaborateurs et transmet sa propriété à l'un d'eux (lot EC 2/4)
976e827 Vérifie les cabinets d'expertise comptable et leur ouvre leur espace (lot EC 1/4)
9ce20c3 Relecture du lot TA des types d’activité
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Achats/Models/LigneAchat.php
 M app/Domain/Achats/Services/ChiffrageDUnAvoirFournisseur.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/Concerns/AjouteDesLignesDAchat.php
 M app/Filament/Concerns/SaisitUnReglementEnParts.php
 M tests/Browser/InventaireDesActionsTest.php
 M tests/Browser/Parcours/EmettreEtEncaisserTest.php
 M tests/Browser/Parcours/RecuDePaiementTest.php
 M tests/Browser/Parcours/TvaDesAchatsTest.php
 M tests/Browser/Support/ActionsDeLInterface.php
 M tests/Browser/Support/Navigation.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php
 M tests/Feature/Facturation/EmettreEtEncaisserTest.php
 M tests/Feature/Filament/TvaDesAchatsResourceTest.php
 M tests/Unit/CalculateurLigneTest.php
```

## Dernière course de la chaîne

Course `wf_f59a9064-d8d`, étapes commencées (de la plus ancienne à la plus récente) :

```
A7b:implementation
A7b:relecture1
A7b:relecture2
A7b:correction
A7b:poussee
A7b:fiche
A7b:notes
SL:implementation
SL:relecture1
SL:relecture2
SL:correction
SL:poussee
SL:fiche
SL:notes
NAV-1:implementation
NAV-1:relecture1
NAV-1:relecture2
NAV-1:correction
NAV-1:poussee
NAV-1:fiche
NAV-1:notes
TR:implementation
TR:relecture1
TR:relecture2
TR:correction
```
