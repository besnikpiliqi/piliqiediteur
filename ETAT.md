# État du projet (généré automatiquement)

Mis à jour le 01/11/2026 à 12:01 UTC par pilotage-sync.sh : « CI de L1 : test qui dépendait de l'ordre, corrigé (d232b4f) ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `d232b4f`. Commits locaux pas encore poussés :

```
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
 M .env.example
 M README.md
 M app/Console/Commands/Editeur/ProcedureDeLEditeur.php
 M app/Console/Commands/PurgerJournalAudit.php
 M app/Domain/Cabinets/Actions/MettreFinAuLienAction.php
 M app/Domain/Cabinets/Actions/RetirerLInvitationAction.php
 M app/Domain/Cabinets/Actions/RetirerLaDemandeDeLienAction.php
 M app/Domain/Cabinets/Enums/MotifDeFinDuLien.php
 M app/Domain/CaisseSecurisee/Support/DeclencheursDeProtection.php
 M app/Domain/Tenancy/Enums/ActionAuditee.php
 M app/Domain/Tenancy/Events/MagasinFerme.php
 M app/Domain/Tenancy/Models/CodeConfirmation.php
 M app/Domain/Tenancy/Models/Societe.php
 M app/Domain/Tenancy/Services/AnnulationDesCodes.php
 M app/Domain/Tenancy/Services/SiretsDesMagasins.php
 M app/Providers/AppServiceProvider.php
 M bootstrap/app.php
 M config/conservation.php
 M database/migrations/0001_01_00_000000_create_societes_table.php
 M database/migrations/2026_10_02_100008_create_journal_audit_table.php
 M database/migrations/2026_10_02_100011_create_verifications_societe_table.php
 M database/migrations/2026_10_02_100023_create_liens_cabinet_table.php
 M tests/Feature/Cabinets/CabinetsConcurrenceTest.php
 M tests/Feature/Calendrier/PlanificateurTest.php
?? app/Console/Commands/Editeur/RevoquerLaSociete.php
?? app/Console/Commands/PurgerVerifications.php
?? app/Domain/Tenancy/Actions/RevoquerSocieteAction.php
?? app/Domain/Tenancy/Services/FinDeLaRelation.php
?? app/Filament/Cabinet/Resources/JournalAuditDuCabinetResource.php
?? app/Filament/Cabinet/Resources/JournalAuditDuCabinetResource/
?? app/Filament/Concerns/AfficheLeJournalDAudit.php
?? app/Filament/Societe/Resources/JournalAuditResource.php
?? app/Filament/Societe/Resources/JournalAuditResource/
?? app/Policies/EntreeAuditPolicy.php
?? tests/Feature/Editeur/RevoquerSocieteTest.php
?? tests/Feature/Societe/JournalAuditResourceTest.php
```

## Dernière course de la chaîne

Course `wf_d6032f41-e79`, étapes commencées (de la plus ancienne à la plus récente) :

```
L1:correction
L1:poussee
L1:fiche
L1:notes
A7:implementation
```
