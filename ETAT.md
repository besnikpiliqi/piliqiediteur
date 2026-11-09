# État du projet (généré automatiquement)

Mis à jour le 09/11/2026 à 12:35 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `0d37396`. Commits locaux pas encore poussés :

```
53beb37 Plafonne les requêtes des espaces de la société et du cabinet et limite la mutation aux calculs et aux droits (lot QA 2/3)
1b34f61 Ajoute les outils de qualité et met la CI sur PostgreSQL seulement (lot QA 1/2)
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
 M README.md
 M app/Domain/Achats/Services/ChiffrageDUnAvoirFournisseur.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Achats/Services/EcartsDUnTaux.php
 M app/Domain/Achats/Services/MontantsDuFournisseur.php
 M app/Domain/Achats/Services/TvaDuFournisseur.php
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Authorization/LecteurDesDroits.php
 M app/Domain/Cabinets/Models/LienCabinet.php
 M app/Domain/Catalogue/Services/BaremePrixDeVente.php
 M app/Domain/Catalogue/Services/MargeDuCatalogue.php
 M app/Domain/Facturation/Models/Avoir.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/ConstructeurLigneDocument.php
 M app/Domain/Facturation/Services/DerogationsDePrix.php
 M app/Domain/Facturation/Services/DocumentChiffre.php
 M app/Domain/Facturation/Services/ImputationDuCredit.php
 M app/Domain/Facturation/Services/PartDeReglement.php
 M app/Domain/Facturation/Services/QuantiteEntiere.php
 M app/Domain/Facturation/Services/ReglementComptant.php
 M app/Domain/Facturation/Services/SaisieDecimale.php
 M app/Domain/Facturation/Services/TerritoireFiscal.php
 M app/Domain/Stock/Services/ConstructeurLigneInventaire.php
 M app/Domain/Tenancy/Concerns/BelongsToMagasin.php
 M app/Domain/Tenancy/Concerns/BelongsToSociete.php
 M app/Domain/Tenancy/Models/Affectation.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Services/PorteeDesAdministrateurs.php
 M app/Domain/Tenancy/Services/PorteeDesDroits.php
 M app/Domain/Tenancy/Services/TenantContext.php
 M app/Http/Middleware/ExigerSonPropreMotDePasse.php
 M app/Http/Middleware/MenerUnCabinetASonEspace.php
 M app/Http/Middleware/MenerUnCompteSansMagasin.php
 M app/Models/User.php
 M app/Policies/AffectationPolicy.php
 M app/Policies/CabinetPolicy.php
 M app/Policies/ClotureCaissePersonnellePolicy.php
 M app/Policies/EntreeAuditPolicy.php
 M app/Policies/InvitationCabinetPolicy.php
 M app/Policies/LienCabinetPolicy.php
 M app/Policies/MagasinPolicy.php
 M app/Policies/MembrePolicy.php
 M app/Policies/PosteReconnuPolicy.php
 M app/Policies/SocietePolicy.php
 M tests/Feature/Achats/FactureAchatActionsTest.php
 M tests/Feature/Achats/TvaDesAvoirsFournisseurTest.php
 M tests/Feature/Achats/TvaDuFournisseurTest.php
 M tests/Feature/Authorization/ControleDesDroitsTest.php
 M tests/Feature/Authorization/LimitesDesDroitsTest.php
 M tests/Feature/Cabinets/AutorisationsDuCabinetTest.php
 M tests/Feature/Facturation/ConstructeurLigneDocumentTest.php
 M tests/Feature/Facturation/RegularisationDUneFactureTest.php
 M tests/Feature/Stock/InventaireOuvertureTest.php
 M tests/Feature/Tenancy/AccesAuxPanneauxTest.php
 M tests/Unit/CalculateurLigneLimitesTest.php
 M tests/Unit/CalculateurLigneTest.php
 M tests/Unit/EcartsDUnTauxTest.php
?? tests/Feature/Facturation/DerogationsDePrixTest.php
?? tests/Unit/CalculateurLigneAuHasardTest.php
```

## Dernière course de la chaîne

Course `wf_54289edd-a97`, étapes commencées (de la plus ancienne à la plus récente) :

```
QA:implementation
QA:relecture1
QA:relecture2
```
