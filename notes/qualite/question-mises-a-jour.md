# Question à l'éditeur : mise à jour des paquets (03/11/2026 vers 00 h 40 UTC)

Contexte : l'éditeur demande si les versions fixées par composer.lock restent toujours les mêmes (« donc jamais à jour »).

Proposition (Q-MAJ) :
1. Failles : `composer audit` et `npm audit` dans la CI (lot QA, `qualite/decision-qualite.md` point 4) échouent dès qu'une faille connue touche un paquet ; le paquet est alors mis à jour aussitôt, dans un petit lot, avec les deux suites et les tests en navigateur.
2. Mises à jour régulières : un lot « mise à jour des paquets » (composer update et npm update dans les plages permises, versions correctives et mineures) à chaque relance de la campagne de tests (fin des étapes 2, 3, 5, 9 et 13) et juste avant la mise en production, avec les deux suites, les tests en navigateur et la CI verte avant de pousser le nouveau composer.lock.
3. Versions majeures (Laravel, Filament, PHP, Pest…) : jamais d'office ; Claude présente chaque fois ce qu'elle change et l'effort, et l'éditeur décide.

Réponse de l'éditeur (03/11/2026 vers 00 h 50 UTC) : « oui ». Décision D53 (`historique/DIRECTIVES.md`) ; texte de CLAUDE.md et point 8 de `qualite/decision-qualite.md` (lot QA).
