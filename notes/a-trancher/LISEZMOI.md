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
- `generer.py registre.json <dossier>` réécrit les six sections en Markdown (s2 à s7) ; il sert à refaire une section,
  pas à la réécrire par-dessus des réponses de l'éditeur : relire le document d'abord.
- Tenue à jour : chaque nouveau point dit à l'éditeur s'ajoute le jour même (numéro suivant : T104) ;
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
- Onglet « Vos décisions » (fichier `a79e1f22-bf92`, corps `173dd30d-9caf`, bloc en attente `mb4cajnjtng.39`) : toutes ses décisions de lui-même
  depuis le 02/10/2026 (date, ses mots, où chacune en est).
- Onglet « Mes questions et vos réponses » (fichier `d0abfb7f-42fd`, corps `78905e66-067c`, bloc en attente `mb4cajnjtng.30`) : chaque question
  de Claude, avec sa réponse, sa date et la décision.
- Les deux derniers se remplissent depuis le workflow `wf_b897639c-407` (tâche `w8h3wdo7c`, lancé à 18 h 22 UTC ; relevés et résultat final
  dans `scratchpad/a-trancher/decisions/`, résultat final `final.json` : `decisions` V1…, `questions` Q1…).
