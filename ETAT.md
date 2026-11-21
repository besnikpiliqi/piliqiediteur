# État du projet (généré automatiquement)

Mis à jour le 21/11/2026 à 23:53 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `075b9f20`. Commits locaux pas encore poussés :

```
37fce91a Carte « Écart des prix d'achat » (lot 2) : catalogue, réglage, lecture des achats et série
8e9df41e Carte « Écart des prix d'achat » (lot 1) : calcul pur, textes, formats, dessin et fixtures figées
```

Derniers commits poussés :

```
075b9f20 Retour à Ubuntu 24.04 : l'essai d'« ubuntu-latest » échoue au semis
de1e2c95 Essai de la CI sur « ubuntu-latest » (Ubuntu 26.04) avec Playwright 1.65.1
c571221e Montée de Playwright de 1.59.1 à 1.65.1
fca01c39 Réparation de la CI rouge de a4ca0549
a4ca0549 Réparation de la CI rouge de 7c5edc7a
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/TableauDeBord/Support/DessinDeLEcartDesPrixDAchat.php
 M app/Filament/GrilleDesGraphiques.php
 M public/css/app/graphiques.css
 M resources/css/graphiques.css
 M resources/views/filament/graphiques-du-panneau.blade.php
 M resources/views/filament/grille-des-graphiques.blade.php
 M tests/Browser/Parcours/GraphiquesDuTableauDeBordTest.php
 M tests/Browser/Support/ActionsDeLInterface.php
 M tests/Browser/inventaire-des-actions.php
?? app/Domain/TableauDeBord/Support/SvgDeLEcartDesPrixDAchat.php
?? resources/views/filament/ecart-des-prix-d-achat.blade.php
?? tests/Browser/Balayage/ContrasteDeLaCarteEcartDesPrixDAchatTest.php
?? tests/Browser/Parcours/EcartDesPrixDAchatTest.php
?? tests/Browser/Support/CarteDeLEcartDesPrixDAchat.php
?? tests/Feature/TableauDeBord/EcartDesPrixDAchatGrilleTest.php
?? tests/Feature/TableauDeBord/EcartDesPrixDAchatVolumeDeLaPageTest.php
?? tests/Feature/TableauDeBord/SvgDeLEcartDesPrixDAchatTest.php
?? tests/Fixtures/EcartDesPrixDAchat/LectureDeLaPage.php
?? tests/Unit/EcartDesPrixDAchatVolumeTest.php
```

## Dernière course de la chaîne

Course `wf_bbdbefd3-771`, étapes commencées (de la plus ancienne à la plus récente) :

```
lot-1:implementation
lot-2:implementation
lot-3:implementation
```
