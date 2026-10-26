# Réponses de l'éditeur (2026-10-10), verbatim — architecture société / établissements

Contexte : la relecture du lot 12 (« réglages communs fixés par le magasin principal du SIREN », commits 204db25 et 54c233a) a soulevé trois questions. L'éditeur les a remplacées par une nouvelle architecture.

## Q1 — Quand un magasin indique le SIREN d'une société qui a déjà des magasins dans PILIQI, que faut-il pour qu'il rejoigne cette société ?

Je souhaite un fonctionnement différent, plus logique et plus sécurisé.

Lorsqu'une société s'inscrit sur PILIQI, elle crée son compte principal avec son SIREN et son premier établissement avec son SIRET.

Ensuite, seul le propriétaire ou un administrateur de la société disposant des autorisations nécessaires peut créer des magasins supplémentaires directement depuis l'espace d'administration de la société.

Pour chaque nouveau magasin, il renseigne notamment son nom, son adresse et son SIRET. Le SIREN est automatiquement celui de la société, sans possibilité de le remplacer par celui d'une autre société.

Chaque magasin appartient ainsi automatiquement à la société qui l'a créé. Aucune demande de rattachement externe par saisie du SIREN ne doit être proposée dans le fonctionnement normal.

L'administrateur de la société peut ensuite inviter les responsables et vendeurs, leur attribuer des magasins et définir précisément leurs permissions.

Chaque magasin conserve sa caisse, ses stocks, ses ventes et ses opérations distinctes. Les informations juridiques et les paramètres fiscaux communs sont gérés au niveau de la société.

Un magasin ne doit jamais pouvoir s'attribuer lui-même l'appartenance à une société existante simplement en saisissant son SIREN.

Prévoir les vérifications d'identité et d'autorisation, l'unicité des établissements, les journaux d'audit et une procédure sécurisée pour les éventuels rattachements exceptionnels ou migrations de comptes existants.

Je veux que cette architecture soit appliquée de manière cohérente dans tout PILIQI : sociétés, établissements, utilisateurs, permissions, facturation, stocks, abonnements et intégration SUPER PDP.

## Q2 — Quel magasin est le magasin principal d'une société (celui qui fixe les réglages communs) ?

Il n'y a pas de magasin principal chargé de gérer les réglages communs.

Chaque société possède son propre compte principal PILIQI, identifié par son SIREN, avec un propriétaire et des administrateurs habilités.

C'est la société, depuis son espace d'administration centralisé, qui crée et gère ses différents magasins (établissements avec leurs SIRET).

Les informations juridiques, les paramètres fiscaux communs et les réglages concernant toute la société sont administrés au niveau de la société, et non par un magasin.

Chaque magasin conserve ses propres stocks, caisse, ventes, factures, employés et paramètres locaux.

Le propriétaire ou les administrateurs habilités de la société peuvent gérer les magasins et attribuer les permissions nécessaires.

Le premier magasin créé n'a aucun privilège particulier sur les autres. Il peut éventuellement être défini comme établissement principal à titre informatif, mais cela ne lui donne pas automatiquement le contrôle des réglages communs.

Adapte les anciennes règles du magasin principal à cette nouvelle architecture, sans modifier les règles métier déjà validées qui ne sont pas concernées.

## Q3 — Le SIREN d'un magasin peut-il encore changer après son premier document émis ?

Dans la nouvelle architecture PILIQI, le SIREN appartient à la société et non à chaque magasin.

Le SIREN est renseigné lors de la création du compte société et partagé automatiquement avec tous ses établissements.

Après l'émission du premier document fiscal, le SIREN ne peut plus être modifié librement, ni par un magasin ni par un administrateur.

En cas d'erreur de saisie, prévoir une procédure exceptionnelle de correction, sécurisée, justifiée et entièrement journalisée, avec vérification de l'identité juridique de la société et traitement approprié des documents déjà émis. Aucun document émis ne doit être modifié rétroactivement.

Si une activité passe à une autre société possédant un SIREN différent, il s'agit d'une autre entité juridique : elle doit disposer de son propre compte société PILIQI. Prévoir si nécessaire une procédure contrôlée de transfert d'établissement, sans déplacer ni modifier les anciennes factures, paiements ou archives de la société d'origine.

Chaque établissement conserve son propre SIRET. Les changements de SIRET doivent suivre une procédure distincte et préserver l'historique des documents.

Cette règle doit être appliquée à toute l'architecture multi-sociétés et multi-magasins.
