# État du projet (généré automatiquement)

Mis à jour le 12/11/2026 à 07:28 UTC par pilotage-sync.sh : « Q-AUD6 : fichiers copiés sur main ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `bb135db`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M app/Domain/Authorization/ControleDesDroits.php
 M app/Domain/Authorization/PermissionRegistry.php
 M app/Domain/Facturation/Actions/EmettreFactureAction.php
 M app/Domain/Facturation/Actions/EmettreLaFactureRectificativeAction.php
 M app/Domain/Facturation/Models/ClotureCaissePersonnelle.php
 M app/Domain/Facturation/Support/GardesDeLaVente.php
 M app/Domain/Tenancy/Actions/AccepterLaTransmissionDeLaProprieteAction.php
 M app/Domain/Tenancy/Actions/OuvrirLaDemonstrationAction.php
 M app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php
 M app/Filament/Societe/Pages/TableauDeBord.php
 M app/Models/User.php
 M app/Policies/ClotureCaissePersonnellePolicy.php
 M app/Policies/FacturePolicy.php
 M database/migrations/0001_01_01_000000_create_magasins_table.php
 M database/migrations/2026_10_03_100003_create_factures_table.php
 M database/migrations/2026_10_04_100001_create_paiements_table.php
 M database/migrations/2026_10_14_100002_create_enregistrements_fiscaux_table.php
 M tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php
 M tests/Feature/Authorization/ProprietaireSansVenteTest.php
 M tests/Feature/CaisseSecurisee/FonctionsDeGardeTest.php
 M tests/Feature/Societe/MagasinDeDemonstrationTest.php
 M tests/Feature/Societe/PanneauSocieteTest.php
 M tests/Feature/Tenancy/IsolationDesSocietesTest.php
 M tests/TestCase.php
?? tests/Browser/Parcours/ProprietaireEnDemonstrationTest.php
?? tests/Feature/Authorization/ProprietaireEnDemonstrationTest.php
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
AUD:implementation-3
AUD:relecture1
AUD:relecture2
AUD:correction
AUD:poussee
AUD:notes
DEMO:implementation
```
