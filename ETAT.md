# État du projet (généré automatiquement)

Mis à jour le 13/11/2026 à 02:27 UTC par pilotage-sync.sh : « point de suivi 02:26 UTC : sauvegarde S9-L ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `c54c221`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
71a217a Passe à la méthode rapide : la suite une fois par lot, l'analyse statique et la mutation à la fin
5dc11a1 Relecture du lot TY2 de l’analyse statique
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/Achats/Actions/CreerAvoirFournisseurAction.php
 M app/Domain/Achats/Models/AvoirFournisseur.php
 M app/Domain/Achats/Models/LigneAchat.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/ConstructeurLigneDocument.php
 M app/Filament/App/Livewire/SelecteurDeProduits.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/InventaireOuvertureResource.php
 M app/Filament/Concerns/AjouteDesLignesDAchat.php
 M app/Filament/Concerns/AjouteDesLignesDeVente.php
 M app/Filament/Concerns/RecoitLesProduitsChoisis.php
 M resources/views/filament/app/livewire/selecteur-de-produits.blade.php
 M tests/Feature/Achats/AvoirFournisseurActionsTest.php
 M tests/Feature/Achats/FactureAchatActionsTest.php
 M tests/Feature/Facturation/ActionsUniquesTest.php
 M tests/Feature/Facturation/AvoirPdfGeneratorTest.php
 M tests/Feature/Facturation/ConstructeurLigneDocumentTest.php
 M tests/Feature/Facturation/EnregistrerVenteCaisseActionConcurrenceTest.php
 M tests/Feature/Facturation/ExempleDeQuinzeLignesTest.php
 M tests/Feature/Facturation/PolitiqueDArrondiTest.php
 M tests/Feature/Facturation/TransformerUnDevisEnFactureTest.php
 M tests/Feature/Filament/AvoirFournisseurResourceTest.php
 M tests/Feature/Filament/AvoirResourceTest.php
 M tests/Feature/Filament/CaisseTest.php
 M tests/Feature/Filament/ClientResourceTest.php
 M tests/Feature/Filament/ClotureCaissePersonnelleResourceTest.php
 M tests/Feature/Filament/ClotureCaisseResourceTest.php
 M tests/Feature/Filament/DevisResourceTest.php
 M tests/Feature/Filament/FactureAchatResourceTest.php
 M tests/Feature/Filament/FactureResourceTest.php
 M tests/Feature/Filament/FacturesApercuWidgetTest.php
 M tests/Feature/Filament/FenetresDesSelecteursTest.php
 M tests/Feature/Filament/InventaireOuvertureResourceTest.php
 M tests/Feature/Filament/ProduitResourceTest.php
 M tests/Feature/Filament/SelecteurDeProduitsTest.php
 M tests/Feature/Filament/VentesEnAttenteApercuTest.php
 M tests/Feature/Proprietes/AchatsEtStockAuHasardTest.php
 M tests/Feature/Proprietes/VentesAuHasardTest.php
 M tests/Feature/Stock/StockLedgerServiceTest.php
 M tests/Feature/Stock/VenteSansStockTest.php
?? tests/Feature/Facturation/LignesUniquesTest.php
?? tests/Feature/Filament/UnProduitUneLigneTest.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
UI:notes
ML:implementation
ML:relecture1
ML:relecture2
ML:correction
ML:poussee
ML:notes
DV:implementation
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
```
