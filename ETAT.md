# État du projet (généré automatiquement)

Mis à jour le 11/11/2026 à 03:00 UTC par pilotage-sync.sh : « Mise à jour des notes de reprise ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `9f46860`. Commits locaux pas encore poussés :

```
f0a961d Type les valeurs lues du socle : commandes, requêtes, réglages, intégrations (Larastan niveaux 9 et 10, lot TY2 1/n)
```

Derniers commits poussés :

```
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
50d39d1 Dit vrai sur les ventes dans l'avis de l'identité incomplète (constat C7)
ccaa905 Retire SQLite : PostgreSQL seul moteur, du code à la CI (lot PG)
7f4d7ae Résout le verrou de Psalm pour PHP 8.3.16 (CI, analyse des flux)
6c7b3d1 Relecture du lot QA des outils de qualité
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Console/Commands/EffacerPiecesVerification.php
 M app/Domain/Achats/Actions/CreerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/EmettreAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/EnregistrerAvoirFournisseurAction.php
 M app/Domain/Achats/Actions/ModifierFactureAchatAction.php
 M app/Domain/Achats/Actions/ValiderFactureAchatAction.php
 M app/Domain/Achats/Models/AvoirFournisseur.php
 M app/Domain/Achats/Models/LigneAchat.php
 M app/Domain/Achats/Services/ChiffrageDUnAvoirFournisseur.php
 M app/Domain/Achats/Services/ConstructeurLigneAchat.php
 M app/Domain/Achats/Services/MontantsDuFournisseur.php
 M app/Domain/Achats/Services/TvaDuFournisseur.php
 M app/Domain/Cabinets/Actions/AccepterLInvitationAction.php
 M app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Actions/ConfierDesClientsAction.php
 M app/Domain/Cabinets/Actions/ConstaterLInscriptionALOrdreAction.php
 M app/Domain/Cabinets/Actions/DesignerUnCabinetAction.php
 M app/Domain/Cabinets/Actions/InviterSonCabinetACreerSonEspaceAction.php
 M app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php
 M app/Domain/Cabinets/Actions/MettreFinAuLienAction.php
 M app/Domain/Cabinets/Actions/ReglerLesAutorisationsDuCabinetAction.php
 M app/Domain/Cabinets/Actions/RepondreAUneDemandeDeLien.php
 M app/Domain/Cabinets/Actions/VerifierLOrdreAction.php
 M app/Domain/Cabinets/Enums/ResultatDInvitation.php
 M app/Domain/Cabinets/Jobs/RendreCaduquesLesInvitationsDuSiren.php
 M app/Domain/Cabinets/Mail/DemandeDeRattachementMail.php
 M app/Domain/Cabinets/Mail/InvitationACreerUnEspaceMail.php
 M app/Domain/Cabinets/Mail/InvitationDuCabinetMail.php
 M app/Domain/Cabinets/Notifications/AvisDuCabinet.php
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/Cabinets/Services/EnvoiDesLiensDesCabinets.php
 M app/Domain/Cabinets/Support/IdentiteDansLeJournal.php
 M app/Domain/CaisseSecurisee/Actions/ExporterJournalDesEvenementsAction.php
 M app/Domain/CaisseSecurisee/Actions/VerifierIntegriteAction.php
 M app/Domain/CaisseSecurisee/Concerns/JournaliseSesParametresDeConformite.php
 M app/Domain/CaisseSecurisee/Models/EnregistrementFiscal.php
 M app/Domain/CaisseSecurisee/Services/ContenuFiscal.php
 M app/Domain/CaisseSecurisee/Services/RegistreFiscal.php
 M app/Domain/CaisseSecurisee/Services/SignataireFiscal.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/CaisseSecurisee/Support/MagasinsOuvertsParLaSession.php
 M app/Domain/Calendrier/Calendrier.php
 M app/Domain/Calendrier/Casts/Instant.php
 M app/Domain/Calendrier/Casts/JourCivil.php
 M app/Domain/Calendrier/Connexions/FuseauParDefautDeLaBase.php
 M app/Domain/Catalogue/Models/Produit.php
 M app/Domain/Catalogue/Services/BaremePrixDeVente.php
 M app/Domain/Contacts/Models/Client.php
 M app/Domain/Facturation/Actions/AjouterPreuveOperationAction.php
 M app/Domain/Facturation/Actions/CloturerCaisseAction.php
 M app/Domain/Facturation/Actions/EmettreAvoirAction.php
 M app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/EncaisserEnPlusieursModesAction.php
 M app/Domain/Facturation/Actions/EnregistrerPaiementAction.php
 M app/Domain/Facturation/Actions/FermerSaCaisseAction.php
 M app/Domain/Facturation/Actions/ModifierFactureAction.php
 M app/Domain/Facturation/Casts/NumeroTvaNormalise.php
 M app/Domain/Facturation/Concerns/FigeSonEmetteur.php
 M app/Domain/Facturation/Concerns/NoteSaNatureDOperation.php
 M app/Domain/Facturation/Enums/CategorieOperation.php
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
TY1:relecture1
TY1:relecture2
TY1:correction
TY1:poussee
TY1:fiche
TY1:notes
TY2:implementation
```
