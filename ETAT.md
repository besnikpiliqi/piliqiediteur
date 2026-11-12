# État du projet (généré automatiquement)

Mis à jour le 12/11/2026 à 19:35 UTC par pilotage-sync.sh : « DV envoyé, CI verte ; EC2 en cours ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `b10d2e8`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
b9dd057 Vérifie les types que donnaient les commentaires en ligne, et refuse ces commentaires au contrôle des seuils (lot TY2, types en ligne)
c2455dd Passe Larastan au niveau 10 sans erreur : les valeurs lues sans type sont vérifiées (lot TY2, niveaux 9 et 10)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Cabinets/Actions/AccepterLInvitationAction.php
 M app/Domain/Cabinets/Actions/AccepterLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Mail/DemandeDeRattachementMail.php
 M app/Domain/Cabinets/Mail/InvitationDuCabinetMail.php
 M app/Domain/Cabinets/Support/GardesDesCabinets.php
 M app/Domain/Tenancy/Enums/TypeDActivite.php
 M app/Domain/Tenancy/Models/Societe.php
 M app/Filament/App/Pages/Auth/ChoisirSonMotDePasse.php
 M app/Filament/App/Pages/Auth/InscriptionSociete.php
 M app/Filament/App/Pages/Auth/PageDuLien.php
 M app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/FicheDuClient.php
 M app/Filament/Societe/Pages/CabinetComptable.php
 M app/Filament/Societe/Pages/RattachementDUnCabinet.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Http/Controllers/AccepterLInvitationDuCabinetController.php
 M database/migrations/2026_10_02_100024_create_autorisations_cabinet_table.php
 M resources/views/cabinets/invitation.blade.php
 M resources/views/code/saisir.blade.php
 M resources/views/filament/societe/pages/rattachement-d-un-cabinet.blade.php
 M resources/views/filament/societe/pages/verification-societe.blade.php
 M tests/Feature/Authorization/GardesDesCabinetsEnBaseTest.php
 M tests/Feature/Cabinets/AccepterLInvitationTest.php
 M tests/Feature/Cabinets/AutorisationsDuCabinetTest.php
 M tests/Feature/Cabinets/InvitationsTest.php
 M tests/Feature/Cabinets/TableauDeBordDuCabinetTest.php
 M tests/Feature/Filament/FacturesAEncaisserTest.php
 M tests/Feature/Filament/ModifierProfilTest.php
 M tests/Feature/Filament/TelechargerLesDocumentsTest.php
 M tests/Feature/Societe/CabinetComptableTest.php
 M tests/Feature/Societe/ConnexionParIdentifiantTest.php
 M tests/Feature/Societe/InscriptionSocieteTest.php
 M tests/Feature/Societe/PageDuLienTest.php
 M tests/Feature/Societe/PanneauSocieteTest.php
 M tests/Feature/Societe/TypeDActiviteTest.php
 M tests/Feature/Societe/VerificationSocieteTest.php
 M tests/TestCase.php
?? resources/views/components/
?? tests/Feature/Cabinets/CabinetSuspenduTest.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
DEMO:relecture1
DEMO:relecture2
DEMO:correction
DEMO:poussee
DEMO:notes
UI:implementation
UI:relecture1
UI:relecture2
UI:correction
UI:poussee
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
```
