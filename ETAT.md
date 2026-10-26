# État du projet (généré automatiquement)

Mis à jour le 26/10/2026 à 16:09 UTC par pilotage-sync.sh : « Série 9 : aucun nombre dans les menus, tout au tableau de bord ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `fa83824`. Commits locaux pas encore poussés :

```
e70f18e Affiche les prix unitaires au centime et complète la note de calcul (lot PU)
```

Derniers commits poussés :

```
fa83824 Relecture du lot T3 de l’heure locale des magasins
5f86951 Passe toutes les heures les tâches du calendrier des magasins (lot T3)
60962cc Met chaque magasin à l'heure locale de son territoire (lot T3)
c4c684a Fixe le territoire de chaque magasin au répertoire Sirene (lot T3)
3a02fbc Relecture du lot T2 des horodatages
c723e1f Dit l'heure d'été ou d'hiver dans les journaux et contrôle les fuseaux (lot T2)
7ed0395 Enregistre, compare et calcule chaque instant en UTC (lot T2)
b926cc9 Fait passer jours, années, minuits et affichages par le calendrier (lot T2)
c0acd28 Donne son fuseau à chaque heure écrite des tests et fige l'horloge avant tout voyage (lot T2)
c8af80f Relecture du lot FA3 de la concurrence des brouillons et des fiches
a188773 Relit sous verrou ce que décident l'envoi d'un e-mail et le passage en retard (lot FA3)
5f71b60 Relecture du lot FA2 de la concurrence des brouillons et des fiches
c2b2512 Protège les fiches et les achats contre les gestes simultanés (lot FA2)
08854d5 Relecture du lot FA1 de la concurrence des brouillons et des fiches
fc8a711 Protège les brouillons et les fiches contre les gestes simultanés (lot FA1)
6b5baba Relecture du lot V2b des compléments de la vérification
65819c6 Confirme l'adresse choisie au code et libère celle d'une inscription close (lot V2b)
79216b1 Relecture du lot V2 de la vérification par courrier
7ee6d18 Vérifie les sociétés par le seul courrier recommandé (lot V2)
5cbe4e2 Annule les lots M0 et M1 du moteur de TVA faits dans une autre session
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/DerogationsDePrix.php
 M app/Filament/App/Pages/ParametresPrixDeVente.php
 M app/Filament/App/Resources/InventaireOuvertureResource.php
 M app/Filament/App/Resources/ProduitResource.php
 M app/Filament/App/Resources/ServiceResource.php
 M app/Filament/Concerns/AjouteDesLignesDAchat.php
 M app/Filament/Concerns/AjouteDesLignesDeVente.php
 M app/Filament/Concerns/SaisitLesPrixDeLigne.php
 M database/factories/PanierCaisseFactory.php
 M database/migrations/2026_10_03_100002_create_lignes_devis_table.php
 M database/migrations/2026_10_03_100004_create_lignes_facture_table.php
 M database/migrations/2026_10_05_100002_create_lignes_avoir_table.php
 M tests/Feature/Filament/CaisseTest.php
 M tests/Feature/Filament/FactureResourceTest.php
 M tests/Feature/Filament/InventaireOuvertureResourceTest.php
 M tests/Feature/Filament/ProduitResourceTest.php
 M tests/Feature/Filament/ServiceResourceTest.php
 M tests/Unit/CalculateurLigneTest.php
```

## Dernière course de la chaîne

Course `wf_fb31205c-c06`, étapes commencées (de la plus ancienne à la plus récente) :

```
T3:correction-reprise
T3:poussee
T3:notes
PU:implementation
PU:relecture1
PU:relecture2
PU:correction
```
