# État du projet (généré automatiquement)

Mis à jour le 13/11/2026 à 11:41 UTC par pilotage-sync.sh : « 13/11 : estimation du temps restant (fin du code ~10/02/2027) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `9b258e5`. Commits locaux pas encore poussés :

```
8ec160b Ajoute l'« À faire » au tableau de bord du magasin et de la société
9ef56b6 Répare la CI : une seule aide pour choisir le client en navigateur
```

Derniers commits poussés :

```
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
5c67a15 Contrôle les fichiers reçus et sert les fichiers en sécurité (lot FI)
cba222d Relecture du lot CALC de la série 8
b21124f Calcule les montants sans virgule flottante et imprime le PU HT tel qu'il est enregistré (lot CALC)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Facturation/Actions/NoterLeVisaDuBordereauAction.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Services/PreuvesRequises.php
 M app/Domain/Tenancy/Actions/VerifierUnEcartSireneAction.php
 M app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php
 M app/Domain/Tenancy/Services/ReverificationSirene.php
 M app/Filament/App/Resources/DevisResource.php
 M app/Filament/App/Resources/DevisResource/Pages/ViewDevis.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/ViewFactureAchat.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php
 M app/Filament/App/Resources/InventaireOuvertureResource/Pages/ViewInventaireOuverture.php
 M app/Providers/AppServiceProvider.php
 M app/Providers/Filament/AppPanelProvider.php
 M database/migrations/2026_10_02_100015_create_ecarts_sirene_table.php
 M tests/Browser/Parcours/AFaireTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Facturation/VisaDuBordereauTest.php
 M tests/Feature/Filament/AFaireDuMagasinTest.php
 M tests/Feature/Filament/AvoirResourceTest.php
 M tests/Feature/Filament/FactureResourceTest.php
 M tests/Feature/Filament/PaiementResourceTest.php
 M tests/Feature/Qualite/PlafondsDeRequetesTest.php
 M tests/Feature/Societe/AFaireDeLEspaceDeLaSocieteTest.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
DV:correction
DV:poussee
DV:notes
EC2:implementation
EC2:implementation-2
EC2:implementation-3
EC2:implementation-4
EC2:implementation-5
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
```
