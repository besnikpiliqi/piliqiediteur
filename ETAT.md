# État du projet (généré automatiquement)

Mis à jour le 12/11/2026 à 12:27 UTC par pilotage-sync.sh : « Point de suivi 12:27 UTC : UI poussé, CI 8feeb83, sauvegarde ML ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `8feeb83`. Commits locaux pas encore poussés :

```
```

Derniers commits poussés :

```
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
9f46860 Relecture du lot TY1 de l’analyse statique
8546296 Couvre les lignes retouchées par le typage et retire une fonction sans appelant (lot TY1 12/n)
5ff0152 Type les valeurs requises des écrans, et passe Larastan au niveau 8 (lot TY1 11/n)
96b3f8a Type les valeurs requises des ventes, des achats, de la caisse et du stock (Larastan niveau 8, lot TY1 10/n)
283c34d Type les valeurs requises des droits, de la société et des cabinets (Larastan niveau 8, lot TY1 9/n)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M .env.example
 M app/Domain/Cabinets/Actions/InviterSonCabinetACreerSonEspaceAction.php
 M app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php
 M app/Domain/Cabinets/Services/EnvoiDesLiensDesCabinets.php
 M app/Domain/Facturation/Actions/EnvoyerDocumentAction.php
 M app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php
 M app/Domain/Facturation/Models/EnvoiDocument.php
 M app/Domain/Tenancy/Actions/ConfirmerNouvelleAdresseAction.php
 M app/Domain/Tenancy/Actions/DemanderLaTransmissionDeLaProprieteAction.php
 M app/Domain/Tenancy/Actions/DemanderUneNouvelleAdresseAction.php
 M app/Domain/Tenancy/Services/AlerteEditeur.php
 M app/Filament/App/Pages/Auth/DemandeDeLien.php
 M app/Filament/App/Pages/Auth/InscriptionSociete.php
 M app/Filament/App/Resources/FactureResource.php
 M app/Filament/Concerns/EnvoieLesDocuments.php
 M bootstrap/app.php
 M bootstrap/providers.php
 M config/dompdf.php
 M config/mail.php
 M config/services.php
 M database/migrations/2026_10_13_100002_create_envois_documents_table.php
 M phpunit.xml
 M tests/Browser/Parcours/RecuDePaiementTest.php
 M tests/Feature/Calendrier/PlanificateurTest.php
 M tests/Feature/Facturation/EnvoyerDocumentJobTest.php
 M tests/Feature/Facturation/FacturePdfGeneratorTest.php
 M tests/Feature/Facturation/RecusDePaiementTest.php
 M tests/Feature/Filament/EnvoieLesDocumentsTest.php
 M tests/Feature/Qualite/TestsSautesTest.php
?? app/Console/Commands/EssayerUnCourriel.php
?? app/Console/Commands/SurveillerLaFileDAttente.php
?? app/Integrations/Courriel/
?? app/Providers/CourrielsServiceProvider.php
?? resources/views/mail/essai.blade.php
?? tests/Feature/Courriels/
?? tests/Feature/Integrations/TransportMailtrapTest.php
?? tests/Fixtures/Courriels/
?? tests/Unit/CauseDEchecDEnvoiTest.php
```

## Dernière course de la chaîne

Course `wf_1d467e9e-93e`, étapes commencées (de la plus ancienne à la plus récente) :

```
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
```
