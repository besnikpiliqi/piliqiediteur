# Lot QA : outils de qualité (décision de l'éditeur du 01/11/2026, vers 23 h 40)

## 1. Le message de l'éditeur, mot pour mot

Après l'explication de la couverture du code : « fait ce qui est le meilleur donc s'il faut ajouter alors ajoute tout pour que le logiciel soit hyper performant avec zero de faille ou erreurs. je veux que le logiciel soit le meilleur de tout »

Réponse qui lui a été faite : aucun outil ne garantit zéro défaut ; ces outils en réduisent fortement le risque ; on ne dira jamais que PILIQI est sans erreur (règle des comptes rendus).

## 2. Ce que le lot QA met en place (après NAV-1, avant NAV-2)

Exception approuvée à la règle « aucune dépendance ajoutée » : les outils de développement nommés ici, jamais livrés en production.

1. **Couverture du code** : extension PCOV (pas un paquet du dépôt ; CI : `coverage: pcov` de setup-php ; machine de développement : paquet système, commande à donner dans « laisses » pour le script d'installation des sessions). Rapport de couverture des lignes et des branches, par fichier et par grande partie du logiciel (calculs, caisse sécurisée, droits et isolation, cabinets, facturation, achats et stock, société, pages publiques), produit par une tâche de la CI à chaque envoi et gardé comme artefact. Seuils par partie, enregistrés dans le dépôt, qui ne peuvent que monter (un lot qui fait baisser la couverture d'une partie échoue) ; objectif : tout nouveau code couvert ; les trous des parties sensibles (calculs, caisse sécurisée, droits et isolation) comblés par ce lot avec de vrais tests.
2. **Tests de mutation** : Infection (paquet de développement), sur les parties sensibles (calculs des ventes et des achats, caisse sécurisée, registre et clôtures, droits et isolation). Il modifie le code de petites façons (un « > » devient « >= », un « + » devient « - », une condition est inversée) et vérifie qu'un test échoue à chaque fois : il trouve les lignes exécutées mais jamais vraiment vérifiées. Score minimal (MSI) par partie, enregistré, qui ne peut que monter ; tâche de la CI sur les fichiers changés par l'envoi, et passage complet aux relances de la campagne de tests et à l'audit final.
3. **Analyse statique** : Larastan (PHPStan pour Laravel, paquet de développement), au niveau le plus élevé que le code atteint avec zéro erreur, sans fichier de référence qui masque des erreurs existantes ; chaque erreur trouvée se corrige ; le niveau atteint s'écrit dans le dépôt et ne peut que monter, jusqu'au niveau maximal. Tâche de la CI.
4. **Sécurité des dépendances** : `composer audit` et `npm audit` dans la CI, qui échouent sur une faille connue.
5. **Performances** : `Model::preventLazyLoading()` (et la détection des accès à des attributs absents) hors production, pour faire échouer les tests sur toute requête N+1 ; tests du nombre de requêtes des listes, tableaux de bord, caisse et PDF les plus chargés, avec un plafond qui ne dépend pas du nombre de lignes ; temps de réponse des pages principales mesuré dans les tests en navigateur, avec un plafond raisonnable ; tout dépassement se corrige (index, chargement anticipé, mise en cache bornée au locataire). La charge réelle et le volume restent à la campagne de tests (étape 1 bis).
6. **README et CLAUDE.md** : README, comment lancer chaque outil en local ; `CLAUDE.md` ne change pas (la puce « Outillage » interdit les paquets inutilisés, ceux-ci servent).
7. **Lots suivants** : chaque lot garde vertes toutes ces tâches de la CI, ne fait baisser aucun seuil et dit dans son compte rendu la couverture et le score de mutation des parties qu'il touche.
