# État du projet (généré automatiquement)

Mis à jour le 13/11/2026 à 08:50 UTC par pilotage-sync.sh : « 13/11 08:50 UTC : lot SEED décidé (données de démonstration), affichage des 295 décisions en cours ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `9b258e5`. Commits locaux pas encore poussés :

```
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
 M CLAUDE.md
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Cabinets/Services/TableauDeBordDesClients.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/AjouterPreuveOperationAction.php
 M app/Domain/Facturation/Actions/NoterBordereauDetaxeAction.php
 M app/Domain/Facturation/Models/BordereauDetaxe.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Models/LigneFacture.php
 M app/Domain/Facturation/Services/FacturesAEncaisserDeLaSociete.php
 M app/Domain/Facturation/Services/PreuvesRequises.php
 M app/Domain/Tenancy/Concerns/BelongsToMagasin.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Services/PorteeDesDroits.php
 M app/Domain/Tenancy/Services/ReverificationSirene.php
 M app/Filament/App/Livewire/SelecteurDeClients.php
 M app/Filament/App/Pages/Caisse.php
 M app/Filament/App/Resources/AvoirFournisseurResource.php
 M app/Filament/App/Resources/ClientResource.php
 M app/Filament/App/Resources/DevisResource.php
 M app/Filament/App/Resources/EquipeDuMagasinResource.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php
 M app/Filament/App/Resources/InventaireOuvertureResource.php
 M app/Filament/App/Resources/ProduitResource.php
D  app/Filament/App/Widgets/FacturesAEncaisserApercu.php
D  app/Filament/App/Widgets/PiecesDuFournisseurManquantesApercu.php
D  app/Filament/App/Widgets/StockNegatifApercu.php
 M app/Filament/Concerns/RecoitLeClientChoisi.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Filament/Societe/Resources/MembreResource.php
 M app/Filament/Societe/Resources/MembreResource/Pages/FicheDuMembre.php
 M app/Models/User.php
 M app/Policies/ClientPolicy.php
 M database/migrations/2026_10_03_100008_create_bordereaux_detaxe_table.php
 M resources/views/filament/app/livewire/fenetre-du-selecteur-de-clients.blade.php
D  resources/views/filament/app/widgets/factures-a-encaisser-apercu.blade.php
 M resources/views/filament/societe/pages/tableau-de-bord.blade.php
 M tests/Browser/Parcours/PiecesDuFournisseurTest.php
 M tests/Browser/Support/Balayeur.php
 M tests/Browser/Support/CouvertureDuBalayage.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Authorization/ClientPolicyTest.php
 M tests/Feature/Authorization/ControleDesDroitsTest.php
 M tests/Feature/Cabinets/AccesDeLExpertComptableTest.php
 M tests/Feature/CaisseSecurisee/ProtectionDesTablesTest.php
 M tests/Feature/Facturation/BordereauDetaxeTest.php
 M tests/Feature/Facturation/ContrePasserPaiementActionTest.php
 M tests/Feature/Facturation/PreuvesOperationTest.php
 M tests/Feature/Facturation/RegularisationDUneFactureTest.php
 M tests/Feature/Filament/ClientResourceTest.php
 M tests/Feature/Filament/EcransSelonLeTypeTest.php
 M tests/Feature/Filament/FacturesAEncaisserTest.php
 M tests/Feature/Filament/PiecesDuFournisseurResourceTest.php
 M tests/Feature/Filament/SelecteurDeClientsTest.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
DV:implementation-2
DV:relecture1
DV:relecture2
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
```
