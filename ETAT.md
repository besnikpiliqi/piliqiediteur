# État du projet (généré automatiquement)

Mis à jour le 26/10/2026 à 20:45 UTC par pilotage-sync.sh : « Notes : CI de PU verte, fiche F24271 vérifiée, messages de l'éditeur du 26/10 ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `89f092f`. Commits locaux pas encore poussés :

```
ad19e0b Range les autorisations par famille et retire les articles aux sociétés de services (lot TA 2/3)
7832bb0 Fait choisir à l'inscription un type d'activité définitif (lot TA 1/3)
```

Derniers commits poussés :

```
89f092f Relecture du lot PU de l’affichage des prix unitaires
e70f18e Affiche les prix unitaires au centime et complète la note de calcul (lot PU)
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/CaisseSecurisee/ModuleFiscal.php
 M app/Domain/Facturation/Services/Emetteur.php
 M app/Domain/Facturation/Services/PiedDePage.php
 M app/Domain/Tenancy/Actions/ModifierIdentiteSocieteAction.php
 M app/Domain/Tenancy/Enums/TypeDActivite.php
 M app/Domain/Tenancy/Models/Societe.php
 M app/Domain/Tenancy/Support/AvertissementsDuCodeDActivite.php
 M app/Filament/Societe/Pages/IdentiteSociete.php
 M database/migrations/0001_01_00_000000_create_societes_table.php
 M resources/views/documents/pdf/pied.blade.php
 M tests/Feature/CaisseSecurisee/EmissionSigneeTest.php
 M tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php
 M tests/Feature/Facturation/EmetteurFigeTest.php
 M tests/Feature/Filament/EnregistrementFiscalResourceTest.php
 M tests/Feature/Societe/IdentiteSocieteTest.php
 M tests/Feature/Societe/MagasinDeDemonstrationTest.php
 M tests/Feature/Societe/TypeDActiviteTest.php
 M tests/Fixtures/contenu-signe/jour.json
 M tests/Fixtures/contenu-signe/nouvel-an.json
 M tests/Fixtures/contenu-signe/nuit-d-ete.json
 M tests/Fixtures/contenu-signe/nuit-d-hiver.json
 M tests/Fixtures/contenu-signe/outre-mer.json
 M tests/Fixtures/contenu-signe/premier-passage.json
 M tests/Fixtures/contenu-signe/second-passage.json
?? tests/Feature/Facturation/AssuranceProfessionnelleTest.php
```

## Dernière course de la chaîne

Course `wf_a77da96f-9ee`, étapes commencées (de la plus ancienne à la plus récente) :

```
TA:implementation
```
