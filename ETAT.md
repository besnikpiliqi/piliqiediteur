# État du projet (généré automatiquement)

Mis à jour le 12/11/2026 à 06:09 UTC par pilotage-sync.sh : « Point de suivi 06:10 UTC : AUD commité en local, correction en cours ; sauvegarde ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `3dd8705`. Commits locaux pas encore poussés :

```
484ddcd Corrige les constats des audits du 07/11/2026 : isolation, base, clôtures, stock, plateforme agréée et outillage (lot AUD)
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
 M app/Console/Commands/Editeur/ProcedureDeLEditeur.php
 M app/Domain/Achats/Actions/ValiderFactureAchatAction.php
 M app/Domain/Achats/Models/FactureAchat.php
 M app/Domain/CaisseSecurisee/ModuleFiscal.php
 M app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Facturation/Support/GardesDesClotures.php
 M app/Domain/Stock/Services/StockLedgerService.php
 M app/Domain/Stock/Services/VerificateurDuStock.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M database/factories/ClotureCaisseFactory.php
 M database/migrations/2026_10_03_000002_create_clotures_caisse_table.php
 M database/migrations/2026_10_03_100003_create_factures_table.php
 M database/migrations/2026_10_05_100001_create_avoirs_table.php
 M database/migrations/2026_10_07_100001_create_factures_achat_table.php
 M tests/Browser/Parcours/TransmissionALaPlateformeTest.php
 M tests/Browser/inventaire-des-actions.php
 M tests/Feature/Authorization/ProprietaireSansVenteConcurrenceTest.php
 M tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php
 M tests/Feature/Editeur/AnnulerCessionTest.php
 M tests/Feature/Filament/EnregistrementFiscalResourceTest.php
 M tests/Fixtures/contenu-signe/jour.json
 M tests/Fixtures/contenu-signe/nouvel-an.json
 M tests/Fixtures/contenu-signe/nuit-d-ete.json
 M tests/Fixtures/contenu-signe/nuit-d-hiver.json
 M tests/Fixtures/contenu-signe/outre-mer.json
 M tests/Fixtures/contenu-signe/premier-passage.json
 M tests/Fixtures/contenu-signe/second-passage.json
 M tests/Fixtures/empreinte-de-la-caisse/journal-des-evenements.txt
 M tests/Fixtures/empreinte-de-la-caisse/registre.txt
 M tests/TestCase.php
?? app/Console/Commands/Editeur/RetirerUnTicketZNonSigne.php
?? app/Domain/CaisseSecurisee/Services/VerificateurDesReferences.php
?? app/Domain/Facturation/Actions/RetirerUnTicketZNonSigneAction.php
?? tests/Feature/Editeur/RetirerUnTicketZNonSigneTest.php
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
```
