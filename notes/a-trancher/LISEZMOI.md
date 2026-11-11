# Registre de ce qui reste à trancher (D57)

- Document de l'éditeur : « PILIQI — Ce qui reste à trancher », https://claude.ai/artifact/9M7jL75DYpwBcTrLC1TpGS
  (document `439821cf-cd41-493d-8b4d-ce35494fcc4b`, corps de l'onglet `1d1e8094-dda6`).
- Rempli le 10/11/2026 vers 17 h 40 UTC (révision 8) depuis le relevé du workflow `wf_437b8241-219`
  (8 lecteurs : CLAUDE.md et README, notes de décisions, notes fiscales, notes société, conversation ;
  497 points bruts, fusionnés puis contrôlés deux fois).
- `registre.json` : le relevé fusionné, tel qu'il a servi à remplir le document :
  `points` (T1 à T103 : 29 expert-comptable, 11 juridique et administration, 46 produit, 17 technique et comptes ;
  `etat` = `applique-a-confirmer` ou `ouvert` ; `sources` et `citation` pour retrouver l'origine),
  `urgent` (11 ; T43 ajouté à la main dans le document, soit 12), `tranche` (61), `ecartes` (106, avec la raison).
  Au 11/11 vers 02 h 10 UTC : T41 à T44 tranchés (états `tranche`, avec `reponse`), T104 ajouté (question de suite de T44),
  `urgent` = T104, T45, T58, T59, T61, T62, T63, T1, T2 (au 11/11 vers 02 h 45 UTC, T104 tranché, `urgent` = T45, T58, T59, T61, T62, T63, T1, T2) ; la même mise à jour est faite dans `scratchpad/a-trancher/resultat/final.json`.
- `generer.py registre.json <dossier>` réécrit les six sections en Markdown (s2 à s7) ; il sert à refaire une section,
  pas à la réécrire par-dessus des réponses de l'éditeur : relire le document d'abord.
- Tenue à jour : chaque nouveau point dit à l'éditeur s'ajoute le jour même (numéro suivant : T105) ;
  chaque réponse (conversation, colonne « Votre réponse », commentaire) passe dans « Déjà tranché », dans
  `historique/DIRECTIVES.md` et dans ce registre. Les commentaires ne réveillent pas la session : chaque point de
  suivi relit le document depuis la dernière révision connue (`read` avec `sinceRev`) et ses commentaires
  (`query` sous le corps de l'onglet).
- Commentaire posé le 10/11 : fil `d2a7d77b-78dc`, sur le titre « À voir avec votre expert-comptable »
  (expert-comptable dès maintenant, ou une seule revue fiscale avant la mise en production, T38).

## Trois onglets (D58, 10/11/2026 à 18 h 16 UTC)

- Onglet « Ce qui reste à trancher » (fichier `0705661d-beeb`, corps `1d1e8094-dda6`, révision 10) : Mode d'emploi (avec les liens vers les deux autres onglets) ;
  Le plus urgent ; À voir avec votre expert-comptable (T1 à T29, puis « Vos autres points qui passent aussi par lui » : T38, T40, T50, T51, T58,
  T60, T66, T71, T75, T81, T91) ; API et services extérieurs (SUPER PDP : T87 à T92 ; registres et courrier : T98 à T101 ; autres services :
  T97, T72, T93, T78) ; Juridique et administration (T30 à T40) ; Vos décisions de produit (T41 à T86 sans T72 ni T78) ; Technique et mise en
  production (T94, T95, T96, T102, T103) ; « Déjà tranché » (61 lignes, à remplacer par un renvoi quand les deux onglets suivants sont remplis).
  `generer-onglet1.py registre.json <dossier> generer.py` refait les sections réorganisées.
- Onglet « Vos décisions » (fichier `a79e1f22-bf92`, corps `173dd30d-9caf`) : texte d'entrée (lien vers l'onglet 3, légende,
  compte), « Novembre 2026 » puis « Octobre 2026 », une semaine (du lundi au dimanche, coupée au mois) par sous-titre de niveau 3,
  la plus récente en haut ; tableau Date (heure de Paris) | Décision (titre en gras, texte, code D… de DIRECTIVES.md) | Vos mots
  (citation exacte, […] pour une coupe, / pour un retour à la ligne) | Où elle en est (Appliquée, À appliquer, Remplacée, Abandonnée,
  À vérifier). 235 décisions, V1 à V235 dans `onglets23/releve-voix.json` (« decisions »).
- Onglet « Mes questions et vos réponses » (fichier `d0abfb7f-42fd`, corps `78905e66-067c`) : texte d'entrée (lien vers l'onglet 2),
  « Novembre 2026 » puis « Octobre 2026 », une série de questions par sous-titre de niveau 3, la plus récente en haut, avec la date
  des réponses sous le titre (ou après chaque réponse quand la série couvre plusieurs jours) ; dans une série, l'ordre des questions ;
  tableau N° (code de la question, ou rang dans la série) | Ma question | Votre réponse (citation exacte, ou « Choix coché, sans mots
  écrits. ») | Décision | Où elle en est. 386 réponses, Q1 à Q386 dans `onglets23/releve-voix.json` (« questions »), 61 séries.
- Fabrication, le 10/11 au soir : relevé `wf_b897639c-407` (`decisions/final.json` : 9 lecteurs, 2 fusions, contrôles d'exhaustivité
  et de justesse, finalisation) ; réécriture à « vous » (l'éditeur) et à « je » (Claude) par `wf_94605ee2-3e2` (`voix/*.json`, seuls
  les champs changés) ; contrôles `wf_2fc3263f-8ab` (`verif/couverture-61.json` : les 61 lignes « Déjà tranché » de l'onglet 1 sont
  toutes dans les onglets 2 et 3, 4 complétées ; vie privée : aucune donnée de tiers ni secret, V93 coupé car il parlait du lieu de
  travail de l'éditeur) ; relecture de la réécriture `wf_65ab60d2-565` (`verif/corrections-*.json`) ; corrections et compléments dans
  `surcharges.json` ; heures UTC passées en heure de Paris par la table `HEURES_DE_PARIS` de `generer-onglets23.py`.
- `python3 -I generer-onglets23.py decisions/final.json voix <dossier>` refait `onglets23/` (un fichier Markdown par bloc, `plan.json`,
  `releve-voix.json`) ; `groupes-figes.json` garde le partage des séries entre les blocs de l'onglet 3. Le remplissage s'est fait par
  `remplir-onglets23.js` (un agent par bloc en attente, workflow `wf_5b76763a-43a`), puis `comparer-export.py <export> <fichiers.md>`
  compare l'export Markdown de chaque onglet avec les fichiers envoyés (cellule par cellule, échappements retirés).
- Plafond du document : 32 768 octets de texte par écriture (refus « row_too_large », rien n'est écrit). Les six blocs plus gros
  (o3-01, o3-04, o3-05, o3-06, o2-…-10-05, o2-…-09-28) ont été coupés en deux par `decouper-blocs.py <dossier> <clés>`
  (`<clé>-p1.md`, `<clé>-p2.md` : la semaine par jours entiers, l'onglet 3 entre deux séries, la série « Arbitrages du 09/10 »
  entre deux lignes sous des titres de niveau 4 « Questions A1 à H1 » et « Questions H2 à K10 ») ; leurs emplacements ont été
  posés à la place des blocs refusés, puis remplis par `remplir-onglets23.js` avec `remplir-args-2.json` (`wf_6f40fd54-734`).
  Premier remplissage : `remplir-args.json` (`wf_5b76763a-43a`, arrêté après les refus ; 8 blocs écrits).
- Rempli et vérifié le 10/11 vers 22 h 30 UTC : onglet « Vos décisions » au rev 13 (10 blocs), onglet « Mes questions et vos réponses »
  au rev 14 (11 blocs) ; l'export Markdown de chaque onglet (`mcp__Claude_Docs__export`, gardé dans `scratchpad/a-trancher/exports/`)
  a été comparé par `comparer-export.py` aux fichiers envoyés : 255 éléments (235 décisions) et 572 éléments (386 réponses), 0 écart.
  L'onglet 1 (rev 11) n'a plus de tableau « Déjà tranché » : son titre (`mb4cajnjtng.81049`) est suivi d'un paragraphe de renvoi
  (`mb4cajnjtng.81062`) avec les liens vers les deux onglets ; la liste `tranche` de `registre.json` est remplacée par
  `onglets23/releve-voix.json`.
- Tenue à jour : une décision de lui-même → une ligne en haut du premier tableau de « Vos décisions » (nouveau sous-titre pour une
  nouvelle semaine, nouveau titre de niveau 2 pour un nouveau mois), avec son numéro suivant (V236…) dans `onglets23/releve-voix.json` ;
  une réponse à un point T… de l'onglet 1 ou à une question → « Mes questions et vos réponses » (nouvelle série en haut du mois, ou
  ligne ajoutée à la série du jour), avec son numéro suivant (Q387…) ; la ligne du point T… quitte l'onglet 1 ; la décision va aussi
  dans `historique/DIRECTIVES.md` et, pour un point T…, dans `registre.json`. Une décision qui en change une autre : la nouvelle en haut,
  l'ancienne passe à « Remplacée », avec la date et ce qui la remplace.

## Quatrième onglet : « À faire vérifier par un professionnel » (D59, 10/11/2026 à 23 h 08 UTC)

- Fichier `d4c6de97-7d8b`, corps `9abd6603-2887`, placé en deuxième position (ordre `a05`) ; bloc en attente `mb4cajnjtng.38`
  (h `6126120d`, rev 1) jusqu'au remplissage. Lien depuis l'onglet 1 : paragraphe `mb4cajnjtng.81484`, juste après les liens vers
  les onglets 2 et 3.
- Contenu voulu par l'éditeur : un tableau de TOUTES les questions et demandes que Claude lui a faites (ou lui fera) pour qu'il demande
  l'avis d'un professionnel (expert-comptable, juriste ou avocat, administration, prestataire), y compris celles qu'il a tranchées
  autrement malgré l'avertissement ou sans attendre l'avis ; chaque nouvelle question de ce genre s'y ajoute le jour même, même
  s'il répond aussitôt autrement (elle reste aussi dans l'onglet où elle se range).
- 11/11 vers 12 h 00 UTC : D66 appliquée (une question par ligne). Onglet 1 réécrit au rev 611 : 362 lignes de tableau, points coupés en T58.1, T58.2… ; « Le plus urgent » est désormais le tableau `mb4cajnjtng.103653` ; le titre « À voir avec votre expert-comptable » (`mb4cajnjtng.3294`) est gardé pour son fil de commentaire d2a7d77b-78dc (question T38 posée par Claude). Onglet « À faire vérifier par un professionnel » réécrit au rev 23, puis rev 56 (33 « Voir aussi » retirés, D66) : 288 questions (156 ouvertes, 76 règles à confirmer, 51 comme conseillé, 5 autrement), chacune sous le numéro T de l'onglet 1 qu'elle vérifie, ou son numéro P. Sources : `scratchpad/t-vers-p/` (`decoupage.json`, `rendre.py`, `couper.py`, `blocs/`), export comparé au mot près (écarts voulus seulement : titres « (suite) » et ligne de date).
- 11/11 vers 11 h 50 UTC : réponse « B » (une seule revue fiscale avant la mise en production) rangée : onglet 1 au rev 614 (T1.5, T38.1, T38.3 retirés ; renvoi « T38.2 et T38.4 » ; texte d'entrée de « À voir avec votre expert-comptable » réécrit), onglet « À faire vérifier » au rev 57 (T38.1 et T38.3 « Tranchée comme conseillé »), onglet 3 au rev 55 (Q396 = T1.5, Q397 = T38.1, Q398 = T38.3 ; 398 réponses : 228 appliquées, 132 à appliquer, 38 remplacées), onglet « À appliquer à la fin » au rev 8 (ligne de la revue : décidée) ; réponse sous le fil d2a7d77b-78dc (n° 05b2146e-f5b5).
- 11/11 vers 12 h 07 UTC : onglet 1 au rev 615 : T58.1 à T58.4 (« Le plus urgent » et « Chaîne TVA, campagne de tests et étape 2 ») disaient « Déjà appliqué au lot C-b » ; « Transformer en facture » n'existe pas encore dans le code (vérifié dans app/ à 71a217a) : corrigé en « Oui. Prévu au lot C-b de la chaîne TVA, pas encore construit ».
- 11/11 12 h 07 à 12 h 08 UTC : l'éditeur a commenté la ligne de renvoi « T58.1 à T58.4 » (tableau « Vos autres points qui passent aussi par lui ») : « tu peux les separer les points » (fil 1d500be2-540c) ; une session lancée par ce commentaire l'a coupée en 4 lignes T58.1 à T58.4 et a répondu (9839a866-7ee1) : onglet 1 au rev 617. Rien d'autre à faire.
- Relevé lancé le 10/11 à 23 h 30 UTC : workflow `wf_dfbbb6a6-31a` (tâche `wx0e27xzy`, script `releve-avis.js`) : 8 lecteurs
  (registre de l'onglet 1, relevé des décisions, CLAUDE.md et DIRECTIVES, conversation en deux moitiés, historique d'octobre, notes
  fiscales, notes société et cabinets), fusion, contrôles d'exhaustivité et de justesse, version finale numérotée P1… dans
  `scratchpad/a-trancher/avis/final.json`.
- La décision D59 est la ligne V236 de l'onglet « Vos décisions » (ajoutée le 11/11 à 00 h 08, heure de Paris ; le sous-titre de la
  semaine est devenu « Du 9 au 11 novembre », le texte d'entrée dit 236 décisions, dont 50 à appliquer).

## Où ajouter une nouvelle question (D60, 10/11/2026 à 23 h 56 et 23 h 59 UTC)

- « Le plus urgent », en haut de l'onglet 1, est un tableau (`mb4cajnjtng.103653` depuis la réécriture D66 du 11/11 ; avant, `mb4cajnjtng.85849`) : N° | Ma question | Ma proposition | Votre réponse (vide) | Pourquoi maintenant. Toute nouvelle question posée à l'éditeur s'y ajoute EN HAUT, avec le numéro suivant (T105…), ma vraie proposition et la case « Votre réponse » vide ; elle peut aussi figurer dans le tableau de sa section, et va aussi dans l'onglet « À faire vérifier par un professionnel » si un professionnel doit la vérifier (D59).
- Une réponse écrite dans ce tableau, dans le tableau d'une section, dans une ligne qu'il ajoute lui-même ou dans la conversation vaut réponse : la ligne quitte les tableaux de l'onglet 1, passe dans la série du jour de « Mes questions et vos réponses », et la consigne va au lot qui l'applique.

## Révisions connues du document (pour les points de suivi)

- 11/11 vers 02 h 10 UTC : onglet 1 au rev 469 (T43 et T44 retirés des deux tableaux, T104 en haut de « Le plus urgent » et du tableau
  « Avant les prochains lots de la chaîne (NAV-2, EC2) », exemple « T45 : oui », 390 réponses) ; onglet « À faire vérifier » au rev 1 ;
  onglet 2 au rev 22 ; onglet 3 au rev 35 (T43 = Q389 et T44 = Q390 dans la série du 11/11 ; 390 réponses, dont 127 à appliquer).
- Commentaires : dernier n° connu 526 (fils de T43 et T44, résolus par l'éditeur le 11/11 à 00 h 06 et 00 h 28 UTC).
- 11/11 vers 02 h 45 UTC : T104 tranché par l'éditeur dans la conversation (« Choix A ») : onglet 1 au rev 471 (T104 retiré des deux tableaux, « Le plus urgent » à 8 points : T45, T58, T59, T61, T62, T63, T1, T2 ; 391 réponses), onglet 3 au rev 39 (T104 = Q391 dans la série du 11/11 ; 391 réponses, dont 128 à appliquer). Prochain numéro : T105.
- 11/11 vers 03 h 00 UTC : onglet « À faire vérifier par un professionnel » rempli (rev 10) depuis `avis/final.json` (relevé `wf_dfbbb6a6-31a` : 8 lecteurs, fusion, deux contrôles, version finale P1 à P76) par `generer-onglet4.py avis/final.json onglet4` (texte d'entrée `onglet4/intro.md`, blocs `onglet4/b01.md` à `b08.md`, `plan.json`) et `remplir-onglets23.js` (8 agents) ; export comparé par `comparer-export.py` : 120 éléments, 98 lignes de tableau, 0 écart. Sections : expert-comptable (P1 à P50), juriste ou avocat (P51 à P57), administration (P58 à P64), prestataires (P65 à P76), un tableau par sujet ; colonnes N° (date) | Question à poser (« À qui : … ») | Ce que je vous ai dit | Votre réponse (état en gras) | Ce que fait PILIQI | Réponse du professionnel (vide). États : 41 ouvertes, 15 règles à confirmer, 16 comme conseillé, 4 autrement (P8, P17, P32, P51). Onglet 2 au rev 24 : V236 « Appliquée », 163 appliquées et 49 à appliquer.
- Ajouter une question à faire vérifier (D59) : une ligne de plus, numéro suivant (P77…), dans le tableau du sujet de la section du professionnel (nouveau titre de niveau 3 si le sujet n'existe pas encore), avec les mêmes colonnes ; mettre à jour les comptes du texte d'entrée ; l'ajouter aussi à `avis/final.json` (et sa copie dans les notes).
- 11/11 vers 03 h 10 UTC : D61 ajoutée en haut de « Vos décisions » (V238 ; onglet 2 au rev 27 : 238 décisions, 164 appliquées, 49 à appliquer) ; onglet 1 au rev 472 (renvoi : 238 décisions, 391 réponses).
- 11/11 vers 03 h 25 UTC : corrections de la vérification adversariale `wf_b0f3d11f-6d5` (18 constats confirmés) : décision T44 (onglet 3 rev 41, registre, final, Q390), § 5 ter de la décision EC2 récrit (choix de Claude signalés, export FEC et TVA réservés à « tous les magasins », pas de case pour le tableau de bord ni pour les analyses, journaux, stockage et gardes) et renvois dans le cahier EC2 ; titre de l'onglet 1 « Avant le prochain lot de la chaîne (EC2) » (rev 473) ; C5 étendu aux lignes cochées des listes ; cahier de l'analyse statique (commits à part, « À chaque commit », contrôle des lignes de test, ordre couverture puis suite, relecteurs sans suite complète) ; tâche « TY2 2b » donnée à l'agent de TY2 (réunir f0a961d au commit des niveaux 9 et 10).
- 11/11 vers 03 h 35 UTC : onglet 3 au rev 43 (série « Effort de la chaîne (11/11) », Q392 ; 392 réponses, 129 à appliquer), onglet 1 au rev 474 (392 réponses).
- 11/11 vers 03 h 50 UTC : réponse « 1 et 2 » de l'éditeur (D62) rangée : onglet 3 au rev 45 (ligne « 2 » = Q393 dans la série « Effort de la chaîne (11/11) » ; 393 réponses, 130 à appliquer), onglet 1 au rev 475 (393 réponses).
- 11/11 vers 10 h 05 UTC : D63 rangée : onglet 2 au rev 28 (V239 ; 239 décisions, 165 appliquées, 49 à appliquer), onglet 3 au rev 48 (Q394 = n° 3 de « Effort de la chaîne (11/11) » ; Q392 remplacée, Q393 appliquée ; 394 réponses : 228 appliquées, 128 à appliquer, 38 remplacées), onglet 1 au rev 477 (239 décisions, 394 réponses).
- 11/11 vers 11 h 15 UTC : onglet « À appliquer à la fin du logiciel » (D64 ; fichier b085ef4c-a6c3, corps 64daf516-c927, rev 7) rempli : 53 points F1 à F53 (analyse statique F1–F6, tests F7–F17, CI F18–F19, production F20–F41, autres F42–F53), relevé `wf_b4438f10-8b2` (8 agents), sources dans a-trancher/a-la-fin/ (releve.json, intro.md, s1–s5.md). Tout nouveau report pour la fin s'y ajoute (ligne F54…, colonne État « À faire »). Onglet 3 au rev 52 (Q395 = T45 ; 395 réponses, 129 à appliquer).
- 11/11 : T105 ajouté en tête de « Le plus urgent » (rev 675), question de la relecture CALC (constats 2 et 5), proposition (a).
