# État du projet (généré automatiquement)

Mis à jour le 26/10/2026 à 11:09 UTC par pilotage-sync.sh : « Complète la fiche de reprise après sa relecture à froid ; faketime et fuseau UTC dans la chaîne ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `3a02fbc`. Commits locaux pas encore poussés :

```
5f86951 Passe toutes les heures les tâches du calendrier des magasins (lot T3)
60962cc Met chaque magasin à l'heure locale de son territoire (lot T3)
c4c684a Fixe le territoire de chaque magasin au répertoire Sirene (lot T3)
```

Derniers commits poussés :

```
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
3fede71 Inscrit les décisions du moteur de TVA dans les règles du projet (lot M0)
67f43e9 Réunit la calculatrice et la vérification VIES dans le moteur de TVA (lot M1)
41fd0a5 Relecture du lot A4c de la série 8
bd0d6ef Connecte chaque compte par son identifiant et transmet la propriété par un lien (lot A4c)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Console/Commands/Editeur/CorrigerLeTerritoire.php
 M app/Console/Commands/Editeur/ProcedureDeLEditeur.php
 M app/Domain/Achats/Actions/EmettreAvoirFournisseurAction.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/Facturation/Actions/CloturerLesJourneesAction.php
 M app/Domain/Facturation/Actions/FermerLesCaissesPersonnellesAction.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Services/PanierDeCaisse.php
 M app/Domain/Facturation/Services/RecapitulatifDeCloture.php
 M app/Domain/Tenancy/Actions/ChangerSiretAction.php
 M app/Domain/Tenancy/Actions/CorrigerLeTerritoireAction.php
 M app/Domain/Tenancy/Actions/CreerEtablissementAction.php
 M app/Domain/Tenancy/Actions/ModifierEtablissementAction.php
 M app/Domain/Tenancy/Actions/RouvrirMagasinAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Services/PeriodesDActivite.php
 M app/Domain/Tenancy/Services/ReverificationSirene.php
 M app/Domain/Tenancy/Services/SiretsDesMagasins.php
 M app/Domain/Tenancy/Services/SuiviDesConnexions.php
 M app/Filament/Societe/Resources/MagasinResource.php
 M app/Filament/Societe/Resources/MagasinResource/Pages/CreerMagasin.php
 M app/Filament/Societe/Resources/MagasinResource/Pages/ModifierMagasin.php
 M app/Filament/Societe/Resources/MembreResource.php
 M database/factories/MagasinFactory.php
 M database/migrations/0001_01_01_000000_create_magasins_table.php
 M tests/Feature/Calendrier/IsolationDesCalendriersTest.php
 M tests/Feature/Societe/TerritoireDuMagasinTest.php
?? app/Console/Commands/Editeur/LibererLeSiret.php
?? app/Domain/Tenancy/Actions/LibererLeSiretAction.php
```

## Dernière course de la chaîne

Course `wf_fb31205c-c06`, étapes commencées (de la plus ancienne à la plus récente) :

```
T3:correction-reprise
```
