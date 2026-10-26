# Réponses de l'éditeur aux questions A, B et C du lot T3 (25/10/2026)

Ce fichier fait foi et prime sur `decision-heure-locale.md` partout où ils diffèrent. Les options recommandées du § 13 du cahier des charges sont toutes retenues.

- A. Déménagement Guadeloupe ↔ Martinique : non. Tout changement de territoire passe par un nouveau magasin ; un changement de SIRET qui change le territoire est refusé (le magasin reste tel quel).
- B. Documents numérotés avant la confirmation de l'établissement : non. Pendant une panne de l'INSEE, un magasin dont l'établissement n'est pas encore confirmé ne valide ni facture d'achat, ni avoir fournisseur, ni inventaire d'ouverture : il attend la confirmation, qui fixe son territoire et donc l'année de ses numéros.
- C. Territoire mal lu au répertoire, puis corrigé : avant le premier document numéroté du magasin, l'éditeur corrige le territoire par une procédure (`editeur:corriger-territoire`), inscrite au journal d'audit et au journal des événements du magasin ; après, la société ouvre un nouveau magasin et l'éditeur libère le SIRET pour lui, sa procédure de libération étant étendue à ce cas.
