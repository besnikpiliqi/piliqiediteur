# Vérification de la fiche de reprise

Date : 26/10/2026, 21 h 35 UTC.

Relecture faite à la main, avant le lot TA. Tête poussée de la branche de travail au moment de la relecture : `89f092f` (relecture de PU). Les trois commits locaux du lot TA (`7832bb0`, `ad19e0b`, `e2d790a`) ne sont ni relus ni poussés : ils ne sont pas comptés comme faits.

Vérifié contre : `git log origin/claude/saas-invoice-editor-architecture-q6eizh` (lecture seule), la liste `LOTS` de `serie8-lots.js` (identique à la copie de `notes/`), `ETAT.md`, `notes/etat-chaines.md`, et l'existence de chaque fichier de `notes/` que cite la fiche (tous présents), ainsi que les sections citées (§ 0, 3.4, D12, D29 et § 4 de `DIRECTIVES.md` ; § 45 du prompt maître ; section 14 de `societe/conception-v2-finale.md` ; 3.5.14 de `serie8/avenant-cabinets.md` ; § 1.5 de `tva/plan-execution.md` ; 4.2 et 3.4.2 de `serie8/plan-serie8.md` ; point 10 de `serie9/reponses-serie9.md` ; grille faketime de 17 instants du README du dépôt ; numéros 128, 156, 275, 276 et 280 de `messages-editeur.md`). Les chemins des trois scripts adaptés au § 4.4 ne contiennent que le dossier de travail et la session d'origine, comme la fiche le dit.

## 1. Corrigé dans README.md

1. En-tête (`VERIFICATION.md`) : « absent tant qu'aucun lot n'est passé par cette étape » était faux depuis cette relecture à la main ; la phrase dit maintenant que la première version a été écrite à la main avant TA.
2. § 0, « Pour la nouvelle session », point 3 : « il n'existe qu'après la relecture à froid du premier lot que pousse la chaîne » contredisait l'en-tête ; remplacé de la même façon.
3. § 5, « Lots restants » : la boucle de contrôle listait `PU TA EC L1 A7 21`, sans S9-L ni S9-A (présents dans `LOTS` depuis le 26/10) et avec PU, déjà poussé. Elle liste maintenant `TA EC L1 A7 S9-L S9-A 21`.
4. § 6, « Fait » : PU complété (poussé vers 17 h 15 UTC, CI verte selon `etat-chaines.md`, étape `PU:fiche` jamais faite).
5. § 6, « Reste dans la chaîne » : le paragraphe décrivait la course `wf_fb31205c-c06` comme en cours avec « PU (commencé) ». En réalité cette course (tâche `wq32xpqqr`) a été arrêtée pendant `PU:fiche`, et la course en cours est `wf_a77da96f-9ee` (tâche `wmnbuda2x`, `ouverts` = TA, EC, L1, A7, S9-L, S9-A, 21), à l'étape `TA:implementation`, avec trois commits locaux non relus ni poussés.

## 2. Faux, ambigu ou manquant, non corrigé (propositions)

### 2.1 La boucle du § 5 ne voit pas les commits du lot TA (bloquant pour le calcul des lots restants)

Les commits de TA s'intitulent « (lot TA 1/3) », « (lot TA 2/3) », « (lot TA 3/3) ». `grep -F -e "(lot $l)"` ne les trouve pas : une fois TA poussé, la boucle afficherait TA comme non fait si sa relecture n'a trouvé aucun défaut (pas de commit « Relecture du lot TA »), et une nouvelle session remettrait TA dans `ouverts`, ce que le § 5 interdit. La chaîne elle-même n'en dépend pas (elle prend les commits que rend l'agent). Texte proposé pour la commande du § 5 :

> `for l in TA EC L1 A7 S9-L S9-A 21; do echo "== $l"; git -C /home/user/piliqiediteur log --oneline origin/claude/saas-invoice-editor-architecture-q6eizh | grep -F -e "(lot $l)" -e "(lot $l " -e "Relecture du lot $l "; done`
>
> (un lot peut numéroter ses commits, « (lot TA 1/3) » : le motif `(lot $l ` les attrape.)

Variante plus sûre, à décider par la session : dire aussi dans `REGLES` de `serie8-lots.js` que chaque commit d'un lot finit par « (lot X) » ou « (lot X n/m) ».

### 2.2 `VERIFICATION.md` n'est pas encore sur la branche distante

Ce fichier est écrit dans le dossier de travail `pilotage-reprise`, mais tant que `pilotage-sync.sh` n'a pas été lancé, il n'est ni commité ni poussé sur `claude/pilotage-reprise` : l'éditeur ne peut pas le voir sur GitHub. La session doit lancer `bash <scratchpad>/pilotage-sync.sh "Relecture à froid de la fiche (à la main, avant TA)"` (il fait `git add -A` dans ce dossier, donc il prend `VERIFICATION.md`). Texte proposé à ajouter au § 10 :

> `VERIFICATION.md` et `README.md` s'écrivent directement dans `<scratchpad>/pilotage-reprise/` : ils ne sont visibles sur GitHub qu'après `pilotage-sync.sh`, à lancer aussitôt après chaque relecture.

### 2.3 S9-A et les questions Q-AF10, Q-AF12, Q-AF13

Le § 6 dit « Avant ce lot, reposer à l'éditeur Q-AF10, Q-AF12 et Q-AF13 ». Or la course en cours enchaînera seule jusqu'à S9-A sans arrêt prévu. La règle 8 de `serie9/reponses-serie9.md` prévoit un comportement par défaut si les réponses manquent, et le complément de S9-A fait relire ce fichier au début du lot. Il manque donc l'échéance concrète. Texte proposé, à la suite de la phrase du § 6 (point 6) :

> La course en cours y arrivera sans s'arrêter : poser ces trois questions à l'éditeur dès que possible, et écrire ses réponses dans `serie9/reponses-serie9.md` avant le début de S9-A ; sans réponse, S9-A applique la règle 8 de ce fichier (comportement par défaut) et le dit dans « laisses ».

Ces trois questions manquent aussi dans « Questions encore ouvertes » (§ 6) et au § 9. Texte proposé pour la liste du § 6 :

> - Série 9 : Q-AF10 (délai d'urgence d'une preuve d'export), Q-AF12 (preuve d'export reçue après la fermeture du magasin), Q-AF13 (les trois choses sans fin), point 10 de `serie9/reponses-serie9.md`.

### 2.4 Série 9 absente des § 7 et § 8

Le tableau du § 7 n'a pas de ligne pour la série 9, alors que S9-L et S9-A sont dans la chaîne. Texte proposé :

> | Série 9 (un produit = une ligne ; « À faire » au tableau de bord) | `serie9/reponses-serie9.md`, puis `serie9/demande-editeur.md`, `serie9/critique.md`, `serie9/analyse-lignes.md`, `serie9/analyse-a-faire.md` |

Et dans la liste des priorités du § 7 :

> - `serie9/reponses-serie9.md` (26/10) sur `serie9/analyse-lignes.md`, `serie9/analyse-a-faire.md` et `serie9/critique.md`, et (règle 7) sur les « badges » du plan TVA.

Le § 8 ne résume pas la série 9. Texte proposé :

> - **Série 9** : un produit = une ligne partout où l'on ajoute des produits, achats compris (clic répété = quantité) ; ligne remisée selon le droit des remises ; même règle pour les services ; « À faire » et « À surveiller » au tableau de bord de chaque magasin, nombres par magasin dans l'espace de la société, plus aucun nombre dans les menus ni les onglets (règle 7) ; Q-AF10, 12 et 13 sans réponse.

### 2.5 Source officielle autorisée et dossier `notes/sources/`

Le § 4, point 6, dit que WebFetch et curl sont souvent bloqués. `etat-chaines.md` (26/10, 17 h 50 UTC) note que l'éditeur a autorisé entreprendre.service-public.gouv.fr, lisible par curl via le proxy (pas par WebFetch), et que la fiche F24271 est sauvée dans `notes/sources/service-public-F24271.txt` (listée dans `pilotage-fichiers.txt`). La fiche ne mentionne ni l'autorisation ni le dossier. Texte proposé, à la fin du § 4, point 6 :

> L'éditeur a autorisé entreprendre.service-public.gouv.fr le 26/10/2026 (lisible par curl via le proxy, pas par WebFetch) ; les pages officielles lues sont gardées dans `notes/sources/` (par exemple `service-public-F24271.txt`, prix HT = TTC ÷ (1 + taux), arrondi au centime).

### 2.6 Heure de poussée de T3

Le § 6 dit T3 « poussée le 26/10/2026 à 12 h 44 UTC » : c'est l'heure du commit `fa83824` (12 h 43 min 57 s) ; `etat-chaines.md` note la poussée à 12 h 57 UTC. Sans conséquence ; proposition : « commitée à 12 h 44 UTC et poussée vers 12 h 57 UTC ».

### 2.7 Description de A7 au § 6

Le § 6 cite pour A7 « section 14 de `societe/conception-v2-finale.md`, section 3.5.14 de `serie8/avenant-cabinets.md` », alors que le complément de A7 dans `LOTS` dit aussi « corrigé par la section 7 de l'avenant et ses compléments ». Proposition : ajouter « corrigée par la section 7 de l'avenant de l'architecture société et ses compléments (`societe/avenant-A4-serie6.md`, `societe/avenant-A4-complements.md`) », après vérification par la session de l'avenant exact visé.

### 2.8 Point 2 du § 0 « Pour la nouvelle session » et le lot TA en cours

Si la passation a lieu pendant TA, `ETAT.md` montrera des commits locaux du lot TA ; la fiche dit bien qu'ils sont perdus et que le lot se refait, mais ne dit pas qu'il faut alors remettre TA en tête de `ouverts`. Le § 5 le couvre implicitement (« ceux qui ne sont pas faits »). Proposition, au § 6 « Reste dans la chaîne » : « Si TA n'est pas poussé au moment de la passation, `ouverts` commence par TA. »

Aucune valeur secrète n'a été lue ni écrite dans cette relecture.
