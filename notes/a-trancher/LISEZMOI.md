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
