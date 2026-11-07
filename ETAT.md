# État du projet (généré automatiquement)

Mis à jour le 07/11/2026 à 22:40 UTC par pilotage-sync.sh : « Décision D54 de l'éditeur : paquets utiles installés sans demander ; § 9 du cahier du lot QA ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `c7b1ec3`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
c7b1ec3 Relecture du lot EX de la série 8
938db39 Ajoute le téléchargement des documents par période (lot EX 2/2)
3c83ee0 Joint la pièce du fournisseur aux achats saisis à la main (lot EX 1/2)
074eaee Relecture du lot TR de la série 8
d57bdab Garde la TVA et le PU d'achat qu'écrit la facture du fournisseur
0afc14c Émet et encaisse d'un geste, ajoute le reçu de paiement et place les règlements à gauche des totaux
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Achats/Models/AvoirFournisseur.php
 M app/Domain/Achats/Models/FactureAchat.php
 M app/Domain/Achats/Models/LigneAchat.php
 M app/Domain/Achats/Models/LigneAvoirFournisseur.php
 M app/Domain/Achats/Models/TauxAvoirFournisseur.php
 M app/Domain/Achats/Models/TauxFactureAchat.php
 M app/Domain/CaisseSecurisee/Concerns/HorodateSonEmission.php
 M app/Domain/CaisseSecurisee/Concerns/ProtegeLesDonneesValidees.php
 M app/Domain/CaisseSecurisee/Models/EnregistrementFiscal.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Concerns/EstEnvoyeParEmail.php
 M app/Domain/Facturation/Concerns/FigeLeClient.php
 M app/Domain/Facturation/Concerns/FigeSonEmetteur.php
 M app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php
 M app/Domain/Facturation/Mail/DocumentMail.php
 M app/Domain/Facturation/Models/Avoir.php
 M app/Domain/Facturation/Models/AvoirAllocation.php
 M app/Domain/Facturation/Models/BordereauDetaxe.php
 M app/Domain/Facturation/Models/ClotureCaisse.php
 M app/Domain/Facturation/Models/ClotureCaissePersonnelle.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Models/EnvoiDocument.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Models/LigneAvoir.php
 M app/Domain/Facturation/Models/LigneDevis.php
 M app/Domain/Facturation/Models/LigneFacture.php
 M app/Domain/Facturation/Models/LigneRecuPaiement.php
 M app/Domain/Facturation/Models/Paiement.php
 M app/Domain/Facturation/Models/PaiementAllocation.php
 M app/Domain/Facturation/Models/PreuveOperation.php
 M app/Domain/Facturation/Models/RecuPaiement.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
 M app/Domain/Stock/Models/InventaireOuverture.php
 M app/Domain/Stock/Models/LigneInventaireOuverture.php
 M app/Domain/Stock/Models/MouvementStock.php
 M app/Domain/Tenancy/Concerns/BelongsToMagasin.php
 M app/Domain/Tenancy/Concerns/BelongsToSociete.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Models/MandatPdp.php
 M app/Filament/App/Livewire/SelecteurDeClients.php
 M app/Filament/App/Livewire/SelecteurDeProduits.php
 M app/Filament/App/Pages/Auth/Connexion.php
 M app/Filament/App/Pages/Caisse.php
 M app/Filament/App/Pages/CoordonneesMagasin.php
 M app/Filament/App/Pages/ParametresFactures.php
 M app/Filament/App/Pages/ParametresPrixDeVente.php
 M app/Filament/App/Pages/ParametresRelances.php
 M app/Filament/App/Resources/AvoirResource.php
 M app/Filament/App/Resources/ClientResource.php
 M app/Filament/App/Resources/ClientResource/Pages/ViewClient.php
 M app/Filament/App/Resources/ClotureCaisseResource.php
 M app/Filament/App/Resources/DevisResource/Pages/EditDevis.php
 M app/Filament/App/Resources/EnregistrementFiscalResource.php
 M app/Filament/App/Resources/EquipeDuMagasinResource.php
 M app/Filament/App/Resources/FactureAchatResource/Pages/EditFactureAchat.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/InventaireOuvertureResource/Pages/EditInventaireOuverture.php
```

## Dernière course de la chaîne

Course `wf_f59a9064-d8d`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
TR:poussee
TR:fiche
TR:notes
EX:implementation
EX:relecture1
EX:relecture2
EX:correction
EX:poussee
EX:fiche
EX:notes
QA:implementation
```
