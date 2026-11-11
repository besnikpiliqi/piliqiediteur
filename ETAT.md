# État du projet (généré automatiquement)

Mis à jour le 11/11/2026 à 11:47 UTC par pilotage-sync.sh : « Réponse B (revue fiscale avant la production) rangée ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `71a217a`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
2c55bad Ajoute le test d'empreinte de la caisse et des PDF (lot TY1 1/n)
b93aeff Relecture du lot PG de la série 8
d8deaf9 Mute en entier chaque fichier sensible changé par l'envoi (CI, lot PG)
4e6d832 Prouve chaque protection de la base par un test qui échoue sans elle (lot PG)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Casts/PrixDAchat.php
 M app/Domain/Achats/Models/FactureAchat.php
 M app/Domain/Achats/Models/LigneAchat.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Catalogue/Models/Service.php
 M app/Domain/Catalogue/Services/BaremePrixDeVente.php
 M app/Domain/Catalogue/Services/MargeDuCatalogue.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/CreerAvoirAction.php
 M app/Domain/Facturation/Actions/EmettreAvoirAction.php
 M app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php
 M app/Domain/Facturation/Actions/EnregistrerPaiementAction.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php
 M app/Domain/Facturation/Enums/TerritoireTva.php
 M app/Domain/Facturation/Mail/DocumentMail.php
 M app/Domain/Facturation/Models/Avoir.php
 M app/Domain/Facturation/Models/ClotureCaissePersonnelle.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/ChiffreDAffaires.php
 M app/Domain/Facturation/Services/ContexteDeVente.php
 M app/Domain/Facturation/Services/DerogationsDePrix.php
 M app/Domain/Facturation/Services/Emetteur.php
 M app/Domain/Facturation/Services/LignesRetirees.php
 M app/Domain/Facturation/Services/MentionsFiscales.php
 M app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
 M app/Domain/Facturation/Services/RegularisationDUneFacture.php
 M app/Domain/Facturation/Services/SaisieDecimale.php
 M app/Domain/Facturation/Services/TableauDesLignes.php
 M app/Domain/Stock/Actions/ValiderInventaireOuvertureAction.php
 M app/Domain/Stock/Models/LigneInventaireOuverture.php
 M app/Domain/Stock/Services/ConstructeurLigneInventaire.php
 M app/Domain/Stock/Services/StockLedgerService.php
 M app/Domain/Tenancy/Actions/AnnulerCessionAction.php
 M app/Domain/Tenancy/Actions/FermerMagasinAction.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Notifications/StockDuMagasinCede.php
 M app/Domain/Tenancy/Services/CatalogueDeDemonstration.php
 M app/Domain/Tenancy/Support/ValeurSimple.php
 M app/Domain/Tenancy/Support/ValeursLues.php
 M app/Filament/App/Livewire/SelecteurDeProduits.php
 M app/Filament/App/Pages/Caisse.php
 M app/Filament/App/Pages/ParametresPrixDeVente.php
 M app/Filament/App/Resources/AvoirResource.php
 M app/Filament/App/Resources/ClientResource.php
 M app/Filament/App/Resources/ClotureCaissePersonnelleResource.php
 M app/Filament/App/Resources/ClotureCaissePersonnelleResource/Pages/ListClotureCaissePersonnelles.php
 M app/Filament/App/Resources/ClotureCaisseResource.php
 M app/Filament/App/Resources/ClotureCaisseResource/Pages/ListClotureCaisses.php
 M app/Filament/App/Resources/ClotureCaisseResource/Widgets/VentesEnAttenteApercu.php
 M app/Filament/App/Resources/EnregistrementFiscalResource/Widgets/ConformiteApercu.php
 M app/Filament/App/Resources/FactureAchatResource.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/App/Resources/InventaireOuvertureResource.php
 M app/Filament/App/Resources/PaiementResource.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
CALC:implementation
CALC:implementation-2
```
