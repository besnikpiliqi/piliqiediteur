# État du projet (généré automatiquement)

Mis à jour le 12/11/2026 à 02:14 UTC par pilotage-sync.sh : « D71 : seul le propriétaire peut tout faire en démonstration ; chaîne reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `3dd8705`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
3dd8705 Relecture du lot FI de la série 8
5c67a15 Contrôle les fichiers reçus et sert les fichiers en sécurité (lot FI)
cba222d Relecture du lot CALC de la série 8
b21124f Calcule les montants sans virgule flottante et imprime le PU HT tel qu'il est enregistré (lot CALC)
71a217a Passe à la méthode rapide : la suite une fois par lot, l'analyse statique et la mutation à la fin
5dc11a1 Relecture du lot TY2 de l’analyse statique
b9dd057 Vérifie les types que donnaient les commentaires en ligne, et refuse ces commentaires au contrôle des seuils (lot TY2, types en ligne)
c2455dd Passe Larastan au niveau 10 sans erreur : les valeurs lues sans type sont vérifiées (lot TY2, niveaux 9 et 10)
9f46860 Relecture du lot TY1 de l’analyse statique
8546296 Couvre les lignes retouchées par le typage et retire une fonction sans appelant (lot TY1 12/n)
5ff0152 Type les valeurs requises des écrans, et passe Larastan au niveau 8 (lot TY1 11/n)
96b3f8a Type les valeurs requises des ventes, des achats, de la caisse et du stock (Larastan niveau 8, lot TY1 10/n)
283c34d Type les valeurs requises des droits, de la société et des cabinets (Larastan niveau 8, lot TY1 9/n)
97c16bc Type les écrans, les commandes et la configuration, et passe Larastan au niveau 7 (lot TY1 8/n)
430539c Type les ventes, les achats, la caisse et le stock (Larastan niveau 7, lot TY1 7/n)
0e5cf71 Type les lectures des droits, de la société et des cabinets (Larastan niveau 7, lot TY1 6/n)
8b3b731 Étend l'analyse statique à tout le code de l'application hors des tests (lot TY1 5/n)
6fcefd0 Lit comme absent un témoin de poste envoyé en tableau, au lieu d'une erreur 500 (lot TY1 4/n)
d8d2ee3 Contrôle les seuils de l'analyse statique par ses paramètres effectifs (lot TY1 3/n)
5946e69 Arrête les mutants survivants connus des calculs et des droits (lot TY1 2/n)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M README.md
 M app/Domain/Achats/Actions/CreerFactureAchatAction.php
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Support/GardesDesPiecesDuFournisseur.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/CaisseSecurisee/Concerns/ProtegeLesDonneesValidees.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Contacts/Models/Fournisseur.php
 M app/Domain/Facturation/Actions/CloturerCaisseAction.php
 M app/Domain/Facturation/Actions/CloturerLesJourneesAction.php
 M app/Domain/Facturation/Actions/CreerAvoirAction.php
 M app/Domain/Facturation/Actions/CreerDevisAction.php
 M app/Domain/Facturation/Actions/CreerFactureAction.php
 M app/Domain/Facturation/Actions/EnregistrerAvoirAction.php
 M app/Domain/Facturation/Actions/EnregistrerPaiementAction.php
 M app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Facturation/Actions/ModifierDevisAction.php
 M app/Domain/Facturation/Actions/ModifierFactureAction.php
 M app/Domain/Facturation/Models/ClotureCaisse.php
 M app/Domain/Facturation/Services/EReportingDesParticuliers.php
 M app/Domain/Facturation/Services/RecapitulatifDeCloture.php
 M app/Domain/Facturation/Services/RecusDePaiement.php
 M app/Domain/Facturation/Support/GardesDeLaVente.php
 M app/Domain/Stock/Enums/TypeMouvementStock.php
 M app/Domain/Stock/Models/MouvementStock.php
 M app/Domain/Stock/Services/StockLedgerService.php
 M app/Domain/Tenancy/Concerns/BelongsToMagasin.php
 M app/Domain/Tenancy/Support/GardesDesDroits.php
 M app/Domain/Tenancy/Support/GardesDesIdentifiants.php
 M app/Domain/Tenancy/Support/GardesDesTypesDActivite.php
 M app/Filament/App/Pages/Caisse.php
 M database/factories/ClotureCaisseFactory.php
 M database/migrations/0001_01_01_000000_create_magasins_table.php
 M database/migrations/2026_10_02_100013_create_codes_confirmation_table.php
 M database/migrations/2026_10_02_100014_create_pieces_verification_table.php
 M database/migrations/2026_10_02_100018_create_mandats_pdp_table.php
 M database/migrations/2026_10_02_200001_create_clients_table.php
 M database/migrations/2026_10_02_200002_create_fournisseurs_table.php
 M database/migrations/2026_10_02_200003_create_produits_table.php
 M database/migrations/2026_10_02_200004_create_mouvements_stock_table.php
 M database/migrations/2026_10_02_200005_create_services_table.php
 M database/migrations/2026_10_02_200006_create_consultations_vies_table.php
 M database/migrations/2026_10_03_000002_create_clotures_caisse_table.php
 M database/migrations/2026_10_03_100001_create_devis_table.php
 M database/migrations/2026_10_03_100002_create_lignes_devis_table.php
 M database/migrations/2026_10_03_100003_create_factures_table.php
 M database/migrations/2026_10_03_100004_create_lignes_facture_table.php
 M database/migrations/2026_10_03_100007_create_preuves_operation_table.php
 M database/migrations/2026_10_03_100008_create_bordereaux_detaxe_table.php
 M database/migrations/2026_10_04_100000_create_recus_paiement_table.php
 M database/migrations/2026_10_04_100001_create_paiements_table.php
 M database/migrations/2026_10_04_100002_create_paiement_allocations_table.php
 M database/migrations/2026_10_04_100003_create_lignes_recu_paiement_table.php
 M database/migrations/2026_10_05_100001_create_avoirs_table.php
 M database/migrations/2026_10_05_100002_create_lignes_avoir_table.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
CALC:implementation
CALC:implementation-2
CALC:relecture1
CALC:relecture2
CALC:correction
CALC:poussee
CALC:notes
FI:implementation
FI:implementation-2
FI:relecture1
FI:relecture2
FI:correction
FI:poussee
FI:notes
AUD:implementation
AUD:implementation-2
```
