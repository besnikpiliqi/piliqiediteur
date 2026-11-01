# État du projet (généré automatiquement)

Mis à jour le 01/11/2026 à 20:28 UTC par pilotage-sync.sh : « Point de suivi de 20:28 : ML en correction ».

## Branche de travail (claude/saas-invoice-editor-architecture-q6eizh)

Tête poussée : `53ed088`. Commits locaux pas encore poussés :

```
335620b Vérifie les e-mails sur la boîte de test de Mailtrap et borne le délai SMTP (lot ML)
```

Derniers commits poussés :

```
53ed088 Relecture du lot A7 de l'architecture société
137334e Ajoute l'examen et la correction d'un SIREN par l'éditeur (lot A7)
0772af0 Ouvre le journal d'audit au propriétaire et ajoute les procédures de l'éditeur (lot A7)
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
```

## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)

```
 M .env.example
 M README.md
 M app/Console/Commands/EssayerLesCourriels.php
 M app/Integrations/Mailtrap/TransportMailtrap.php
 M resources/views/mail/essai.blade.php
 M tests/Feature/Console/EssayerLesCourrielsTest.php
 M tests/Feature/Integrations/TransportMailtrapTest.php
```

## Dernière course de la chaîne

Course `wf_b5c2413b-8c6`, étapes commencées (de la plus ancienne à la plus récente) :

```
A7:relecture1
A7:relecture2
A7:correction
A7:poussee
A7:fiche
A7:notes
ML:implementation
ML:relecture1
ML:relecture2
ML:correction
```
