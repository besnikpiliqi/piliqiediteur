# État du projet (généré automatiquement)

Mis à jour le 10/11/2026 à 18:25 UTC par pilotage-sync.sh : « D58 : note D57 en trois onglets ; workflow des décisions lancé ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b93aeff`. Commits locaux pas encore poussés :

```
0e5cf71 Type les lectures des droits, de la société et des cabinets (Larastan niveau 7, lot TY1 6/n)
8b3b731 Étend l'analyse statique à tout le code de l'application hors des tests (lot TY1 5/n)
6fcefd0 Lit comme absent un témoin de poste envoyé en tableau, au lieu d'une erreur 500 (lot TY1 4/n)
d8d2ee3 Contrôle les seuils de l'analyse statique par ses paramètres effectifs (lot TY1 3/n)
5946e69 Arrête les mutants survivants connus des calculs et des droits (lot TY1 2/n)
2c55bad Ajoute le test d'empreinte de la caisse et des PDF (lot TY1 1/n)
```

Derniers commits poussés :

```
b93aeff Relecture du lot PG de la série 8
d8deaf9 Mute en entier chaque fichier sensible changé par l'envoi (CI, lot PG)
4e6d832 Prouve chaque protection de la base par un test qui échoue sans elle (lot PG)
50d39d1 Dit vrai sur les ventes dans l'avis de l'identité incomplète (constat C7)
ccaa905 Retire SQLite : PostgreSQL seul moteur, du code à la CI (lot PG)
7f4d7ae Résout le verrou de Psalm pour PHP 8.3.16 (CI, analyse des flux)
6c7b3d1 Relecture du lot QA des outils de qualité
09225fa Enregistre les scores de mutation des calculs et des droits et monte les seuils de couverture (lot QA 4/4)
aedb582 Tue les mutants survivants des calculs et des droits par des tests et un code simplifié (lot QA 3/4)
53beb37 Plafonne les requêtes des espaces de la société et du cabinet et limite la mutation aux calculs et aux droits (lot QA 2/3)
1b34f61 Ajoute les outils de qualité et met la CI sur PostgreSQL seulement (lot QA 1/2)
0d37396 Met à jour six paquets Symfony qui portaient des failles connues
c7b1ec3 Relecture du lot EX de la série 8
938db39 Ajoute le téléchargement des documents par période (lot EX 2/2)
3c83ee0 Joint la pièce du fournisseur aux achats saisis à la main (lot EX 1/2)
074eaee Relecture du lot TR de la série 8
d57bdab Garde la TVA et le PU d'achat qu'écrit la facture du fournisseur
0afc14c Émet et encaisse d'un geste, ajoute le reçu de paiement et place les règlements à gauche des totaux
1d3278a Relecture du lot NAV-1 des tests en navigateur
523e74f Balaye chaque panneau rôle par rôle et ajoute les premiers parcours en navigateur (lot NAV-1 2/2)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Achats/Actions/EnregistrerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Actions/ValiderFactureAchatAction.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Achats/Services/TvaDuFournisseur.php
 M app/Domain/Achats/Support/GardesDesPiecesDuFournisseur.php
 M app/Domain/CaisseSecurisee/Services/ContenuFiscal.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/CaisseSecurisee/Services/SignataireFiscal.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Calendrier/Calendrier.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Contacts/Actions/CreerUneFicheAction.php
 M app/Domain/Contacts/Actions/ModifierUneFicheAction.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/CloturerLesJourneesAction.php
 M app/Domain/Facturation/Actions/ContrePasserPaiementAction.php
 M app/Domain/Facturation/Actions/EmettreAvoirAction.php
 M app/Domain/Facturation/Actions/EmettreDevisAction.php
 M app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php
 M app/Domain/Facturation/Actions/EncaisserEnPlusieursModesAction.php
 M app/Domain/Facturation/Actions/EnregistrerAvoirAction.php
 M app/Domain/Facturation/Actions/EnregistrerPaiementAction.php
 M app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Actions/FaireLAvoirDeRegularisationAction.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Facturation/Actions/ModifierFactureAction.php
 M app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php
 M app/Domain/Facturation/Enums/TypeDeDocumentTelechargeable.php
 M app/Domain/Facturation/Mail/DocumentMail.php
 M app/Domain/Facturation/Models/ClotureCaisse.php
 M app/Domain/Facturation/Models/Facture.php
 M app/Domain/Facturation/Services/ArchiveDesDocuments.php
 M app/Domain/Facturation/Services/CaissesDeLaSociete.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/ConstructeurLigneDocument.php
 M app/Domain/Facturation/Services/DocumentChiffre.php
 M app/Domain/Facturation/Services/DocumentsDeLaPeriode.php
 M app/Domain/Facturation/Services/EReportingDesParticuliers.php
 M app/Domain/Facturation/Services/RecapitulatifDeCloture.php
 M app/Domain/Facturation/Services/RecapitulatifDesDocuments.php
 M app/Domain/Facturation/Services/RegularisationDUneFacture.php
 M app/Domain/Facturation/Services/TableauDesLignes.php
 M app/Domain/Facturation/Support/GardesDeLaVente.php
 M app/Domain/Stock/Actions/ValiderInventaireOuvertureAction.php
 M app/Domain/Stock/Listeners/NotifierLeStockNegatif.php
 M app/Domain/Stock/Listeners/NotifierLeStockSousLeSeuilDAlerte.php
 M app/Domain/Stock/Services/ConstructeurLigneInventaire.php
 M app/Domain/Stock/Services/VerificateurDeStock.php
 M app/Domain/Tenancy/Services/ActionsUniques.php
?? tests/Unit/DocumentChiffreTest.php
```

## Dernière course de la chaîne

Course `wf_54289edd-a97`, étapes commencées (de la plus ancienne à la plus récente) :

```
QA:implementation
QA:relecture1
QA:relecture2
QA:correction
QA:poussee
QA:fiche
QA:notes
PG:implementation
PG:relecture1
PG:relecture2
PG:correction
PG:poussee
PG:fiche
PG:notes
TY1:implementation
```
