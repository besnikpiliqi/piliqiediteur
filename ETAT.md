# État du projet (généré automatiquement)

Mis à jour le 08/11/2026 à 19:55 UTC par pilotage-sync.sh : « Lecture des archives d'audits finie : rapport, cahier du lot AUD, questions ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `0d37396`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
0d37396 Met à jour six paquets Symfony qui portaient des failles connues
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M .github/workflows/tests.yml
 M .gitignore
 M CLAUDE.md
 M README.md
 M app/Console/Commands/ClotureAutomatiqueCaisse.php
 M app/Console/Commands/Editeur/ProcedureDeLEditeur.php
 M app/Console/Commands/EffacerPiecesVerification.php
 M app/Console/Commands/ExpirerDevis.php
 M app/Console/Commands/MarquerFacturesEnRetard.php
 M app/Console/Commands/PurgerActionsTraitees.php
 M app/Console/Commands/PurgerTraces.php
 M app/Console/Commands/RelancerFacturesImpayees.php
 M app/Console/Commands/VerifierIntegriteFiscale.php
 M app/Domain/Achats/Actions/CreerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/CreerFactureAchatAction.php
 M app/Domain/Achats/Actions/EnregistrerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Concerns/EstUnTauxDuFournisseur.php
 M app/Domain/Achats/Listeners/DecrementerStockApresEmissionAvoirFournisseur.php
 M app/Domain/Achats/Listeners/IncrementerStockApresValidationFactureAchat.php
 M app/Domain/Achats/Models/AvoirFournisseur.php
 M app/Domain/Achats/Models/FactureAchat.php
 M app/Domain/Achats/Models/LigneAchat.php
 M app/Domain/Achats/Models/LigneAvoirFournisseur.php
 M app/Domain/Achats/Models/TauxAvoirFournisseur.php
 M app/Domain/Achats/Models/TauxFactureAchat.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Achats/Services/MontantsDuFournisseur.php
 M app/Domain/Authorization/OperateurCourant.php
 M app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Actions/ConfierDesClientsAction.php
 M app/Domain/Cabinets/Actions/MettreFinAuLienAction.php
 M app/Domain/Cabinets/Actions/RepondreAUneDemandeDeLien.php
 M app/Domain/Cabinets/Notifications/AvisDuCabinet.php
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/CaisseSecurisee/Concerns/HorodateSonEmission.php
 M app/Domain/CaisseSecurisee/Concerns/ProtegeLesDonneesValidees.php
 M app/Domain/CaisseSecurisee/Listeners/JournaliserFinDeSession.php
 M app/Domain/CaisseSecurisee/Models/EnregistrementFiscal.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/Calendrier/Casts/Instant.php
 M app/Domain/Calendrier/Casts/JourCivil.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/CloturerLesJourneesAction.php
 M app/Domain/Facturation/Actions/CreerAvoirAction.php
 M app/Domain/Facturation/Actions/CreerDevisAction.php
 M app/Domain/Facturation/Actions/CreerFactureAction.php
 M app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/EncaisserEnPlusieursModesAction.php
 M app/Domain/Facturation/Actions/EnregistrerAvoirAction.php
 M app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Facturation/Actions/ModifierDevisAction.php
 M app/Domain/Facturation/Actions/ModifierFactureAction.php
 M app/Domain/Facturation/Concerns/EstEnvoyeParEmail.php
 M app/Domain/Facturation/Concerns/FigeLeClient.php
```

## Dernière course de la chaîne

Course `wf_14a12639-401`, étapes commencées (de la plus ancienne à la plus récente) :

```
QA:implementation
```
