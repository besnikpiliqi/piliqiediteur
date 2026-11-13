# État du projet (généré automatiquement)

Mis à jour le 13/11/2026 à 21:48 UTC par pilotage-sync.sh : « Modèles : tous les tableaux ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `eba4bc8`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
eba4bc8 Relecture du lot ER-PRO
175df0f Applique les décisions de Claude sans attendre un professionnel (lot ER-PRO)
58ad4de Relecture du lot SEED
8847d24 Sème des données de démonstration pour le poste de l'éditeur (lot SEED)
3b695ec Relecture du lot 21 des décisions
8f9817a Vérifie toutes les décisions par une matrice décision → test (lot 21)
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Console/Commands/DiagnostiquerCaisseSecurisee.php
 M app/Console/Commands/RelancerTransmissionsPdp.php
 M app/Domain/Achats/Models/FactureAchat.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Achats/Services/PiecesDuFournisseur.php
 M app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Actions/ChoisirLeMotDePasseDeLExpertComptableAction.php
 M app/Domain/Cabinets/Actions/DeclarerSonExpertComptableAction.php
 M app/Domain/Cabinets/Actions/DesignerUnCabinetAction.php
 M app/Domain/Cabinets/Listeners/RattacherLesDeclarationsDuCabinetValide.php
 M app/Domain/Cabinets/Notifications/AvisDuCabinetEnFile.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/CaisseSecurisee/Actions/VerifierIntegriteAction.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Catalogue/Services/BaremePrixDeVente.php
 M app/Domain/Facturation/Actions/NoterLeVisaDuBordereauAction.php
 M app/Domain/Facturation/Actions/RepondreAuDevisEnLigneAction.php
 M app/Domain/Facturation/Actions/RetirerUnTicketZNonSigneAction.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Models/Devis.php
 M app/Domain/Facturation/Services/CalculateurLigne.php
 M app/Domain/Facturation/Services/DocumentChiffre.php
 M app/Domain/Facturation/Services/EReportingDesParticuliers.php
 M app/Domain/Facturation/Support/GardesDesDocumentsEmis.php
 M app/Domain/Stock/Enums/TypeMouvementStock.php
 M app/Domain/Stock/Services/VerificateurDuStock.php
 M app/Domain/Stock/Support/GardesDuStock.php
 M app/Domain/Tenancy/Services/ChosesAFaireDeLaSociete.php
 M app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php
 M app/Domain/Tenancy/Services/PiecesDeVerification.php
 M app/Http/Controllers/TelechargerLesDocumentsController.php
 M app/Providers/AppServiceProvider.php
?? app/Domain/Tenancy/Support/NomsPolymorphes.php
```

## Dernière course de la chaîne

Course `wf_ee9b6e6e-908`, étapes commencées (de la plus ancienne à la plus récente) :

```
SEED:implementation
SEED:implementation-2
SEED:relecture1
SEED:relecture2
SEED:correction
SEED:poussee
SEED:notes
ER-PRO:implementation
ER-PRO:implementation-2
ER-PRO:relecture1
ER-PRO:relecture2
ER-PRO:correction
ER-PRO:poussee
ER-PRO:notes
STAT:implementation
STAT:implementation-2
```
