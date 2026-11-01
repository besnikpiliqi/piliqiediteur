# État du projet (généré automatiquement)

Mis à jour le 01/11/2026 à 18:26 UTC par pilotage-sync.sh : « Point de suivi de 18:26 : A7 en correction ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `d232b4f`. Commits locaux pas encore poussés :

```
137334e Ajoute l'examen et la correction d'un SIREN par l'éditeur (lot A7)
0772af0 Ouvre le journal d'audit au propriétaire et ajoute les procédures de l'éditeur (lot A7)
```

Derniers commits poussés :

```
d232b4f Pose le fuseau UTC de la base avant le test du diagnostic qui le lit
5745e7a Relecture du lot L1 de l'architecture société
8b15aec Outille le développement local et fixe le fuseau UTC de la base (lot L1)
01fe217 Relecture du lot EC de l’espace des cabinets
93ea590 Retire les experts-comptables de la société et réécrit CLAUDE.md (lot EC 4/4)
94ada86 Lie les cabinets à leurs clients : invitations, demandes, autorisations, accès (lot EC 3/4)
9671a29 Donne au cabinet ses collaborateurs et transmet sa propriété à l'un d'eux (lot EC 2/4)
976e827 Vérifie les cabinets d'expertise comptable et leur ouvre leur espace (lot EC 1/4)
9ce20c3 Relecture du lot TA des types d’activité
e2d790a Imprime l'assurance professionnelle facultative de la société (lot TA 3/3)
ad19e0b Range les autorisations par famille et retire les articles aux sociétés de services (lot TA 2/3)
7832bb0 Fait choisir à l'inscription un type d'activité définitif (lot TA 1/3)
89f092f Relecture du lot PU de l’affichage des prix unitaires
e70f18e Affiche les prix unitaires au centime et complète la note de calcul (lot PU)
fa83824 Relecture du lot T3 de l’heure locale des magasins
5f86951 Passe toutes les heures les tâches du calendrier des magasins (lot T3)
60962cc Met chaque magasin à l'heure locale de son territoire (lot T3)
c4c684a Fixe le territoire de chaque magasin au répertoire Sirene (lot T3)
3a02fbc Relecture du lot T2 des horodatages
c723e1f Dit l'heure d'été ou d'hiver dans les journaux et contrôle les fuseaux (lot T2)
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M CLAUDE.md
 M README.md
 M app/Console/Commands/Editeur/CorrigerLeSiren.php
 M app/Console/Commands/Editeur/EnvoyerUnCourrier.php
 M app/Console/Commands/Editeur/ExaminerLeSiren.php
 M app/Console/Commands/Editeur/LibererUnSiretSaisiParErreur.php
 M app/Console/Commands/Editeur/ProcedureDeLEditeur.php
 M app/Console/Commands/Editeur/RevoquerLaSociete.php
 M app/Domain/Cabinets/Actions/DesignerUnCabinetAction.php
 M app/Domain/Cabinets/Actions/InviterUneEntrepriseAction.php
 M app/Domain/Cabinets/Actions/MettreFinAuLienAction.php
 M app/Domain/Cabinets/Actions/RepondreAUneDemandeDeLien.php
 M app/Domain/Cabinets/Actions/RetirerLInscriptionALOrdreAction.php
 M app/Domain/Cabinets/Actions/RetirerLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Services/AccesDesCabinets.php
 M app/Domain/Cabinets/Support/IdentiteDansLeJournal.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Facturation/Services/DocumentNumberingService.php
 M app/Domain/Tenancy/Actions/AnnulerCessionAction.php
 M app/Domain/Tenancy/Actions/ChangerFinExerciceAction.php
 M app/Domain/Tenancy/Actions/CorrigerSirenAction.php
 M app/Domain/Tenancy/Actions/EnvoyerCodeDeConfirmationAction.php
 M app/Domain/Tenancy/Actions/ExaminerSirenAction.php
 M app/Domain/Tenancy/Actions/LibererUnSiretSaisiParErreurAction.php
 M app/Domain/Tenancy/Actions/RevoquerSocieteAction.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Models/CodeConfirmation.php
 M app/Domain/Tenancy/Models/Magasin.php
 M app/Domain/Tenancy/Rules/CodeMagasinLibre.php
 M app/Domain/Tenancy/Services/CodeMagasin.php
 M app/Domain/Tenancy/Services/ExercicesDeLaSociete.php
 M app/Domain/Tenancy/Services/JournalAudit.php
 M app/Domain/Tenancy/Services/JournalDesDroits.php
 M app/Filament/Concerns/AfficheLeJournalDAudit.php
 M database/migrations/0001_01_00_000000_create_societes_table.php
 M database/migrations/0001_01_01_000000_create_magasins_table.php
 M database/migrations/2026_10_02_100008_create_journal_audit_table.php
 M database/migrations/2026_10_02_100013_create_codes_confirmation_table.php
 M tests/Feature/Cabinets/LiensAvecUneSocieteInscriteTest.php
 M tests/Feature/Editeur/AnnulerCessionTest.php
 M tests/Feature/Editeur/ChangerFinExerciceTest.php
 M tests/Feature/Editeur/LibererSiretTest.php
 M tests/Feature/Editeur/RevoquerSocieteTest.php
 M tests/Feature/Editeur/SirenErroneTest.php
 M tests/Feature/Societe/JournalAuditResourceTest.php
 M tests/TestCase.php
```

## Dernière course de la chaîne

Course `wf_b5c2413b-8c6`, étapes commencées (de la plus ancienne à la plus récente) :

```
A7:relecture1
A7:relecture2
A7:correction
```
