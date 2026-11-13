# État du projet (généré automatiquement)

Mis à jour le 13/11/2026 à 14:41 UTC par pilotage-sync.sh : « 13/11 14:41 UTC : CI S9-A verte, lot 21 en correction ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `7f983c1`. Commits locaux pas encore poussés :

```
8f9817a Vérifie toutes les décisions par une matrice décision → test (lot 21)
```

Derniers commits poussés :

```
7f983c1 Relecture du lot S9-A de la série 9
8ec160b Ajoute l'« À faire » au tableau de bord du magasin et de la société
9ef56b6 Répare la CI : une seule aide pour choisir le client en navigateur
9b258e5 Relecture du lot S9-L de la série 9
4e2b320 Un produit n'a qu'une ligne d'un document
c54c221 Relecture du lot EC2 de l’expert-comptable de la société
f159294 Donne à la société un expert-comptable déclaré par le SIREN de son cabinet, rattaché tout seul au cabinet vérifié (lot EC2)
b10d2e8 Relecture du lot DV du devis en ligne
496da9c Laisse le client répondre à son devis en ligne et transformer un devis accepté en facture (lot DV)
0b7e734 Écrit chaque jour de la relance en navigateur sans calcul sur un instant (CI)
d4a8247 Relecture du lot ML des e-mails vérifiés
0ad0710 Vérifie les e-mails sur Mailtrap et explique chaque échec d'envoi (lot ML)
8feeb83 Relecture du lot UI des écrans
238f430 Corrige les constats de l'éditeur sur les écrans de vente (lot UI)
e101d9f Lit l'écart d'horloge du diagnostic à un dixième de seconde près (CI)
b12a835 Relecture du lot DEMO de la démonstration
d23eb96 Laisse le propriétaire tout faire dans le magasin de démonstration (lot DEMO)
bb135db Relecture du lot AUD de la série 8
484ddcd Corrige les constats des audits du 07/11/2026 : isolation, base, clôtures, stock, plateforme agréée et outillage (lot AUD)
3dd8705 Relecture du lot FI de la série 8
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Tenancy/Actions/ModifierMembreAction.php
 M app/Domain/Tenancy/Exceptions/TenantContextMissingException.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Scopes/MagasinScope.php
 M app/Domain/Tenancy/Scopes/SocieteScope.php
 M app/Domain/Tenancy/Services/CodeMagasin.php
 M app/Domain/Tenancy/Services/TenantContext.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Filament/App/Resources/InventaireOuvertureResource/Pages/CreateInventaireOuverture.php
 M app/Filament/App/Resources/InventaireOuvertureResource/Pages/EditInventaireOuverture.php
 M app/Filament/Societe/Resources/MagasinResource/Pages/ModifierMagasin.php
 M tests/Browser/Parcours/UnProduitUneLigneTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Filament/InventaireOuvertureResourceTest.php
 M tests/Feature/OutillageTest.php
 M tests/Feature/Societe/MagasinResourceTest.php
 M tests/Feature/Tenancy/IsolationDesSocietesTest.php
 M tests/Feature/Tenancy/TenantIsolationTest.php
 M tests/Unit/MatriceDesDecisionsTest.php
 M tests/Unit/RetourALaListeTest.php
 M tests/Unit/matrice-des-decisions.php
?? tests/Browser/Parcours/RetourALaListeTest.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
EC2:relecture1
EC2:relecture2
EC2:correction
EC2:poussee
EC2:notes
S9-L:implementation
S9-L:relecture1
S9-L:relecture2
S9-L:correction
S9-L:poussee
S9-L:notes
S9-A:implementation
S9-A:implementation-2
S9-A:implementation-3
S9-A:relecture1
S9-A:relecture2
S9-A:correction
S9-A:poussee
S9-A:notes
21:implementation
21:implementation-2
21:implementation-3
21:relecture1
21:relecture2
21:correction
```
