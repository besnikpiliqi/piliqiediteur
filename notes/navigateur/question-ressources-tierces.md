# Question à l'éditeur : ressources de sites tiers chargées par Filament (constat 9 de la relecture du lot NAV-1, 03/11/2026)

Constat (commit 1d3278a) : en vérifiant qu'aucune page ne joint un autre site que l'application, la relecture du lot NAV-1 a trouvé deux ressources que Filament charge par défaut depuis des sites tiers, sur chaque page des trois panneaux :
- la police Inter, depuis fonts.bunny.net ;
- l'image des initiales du compte, depuis ui-avatars.com (l'adresse de l'image contient le nom de la personne connectée).
Chaque chargement envoie à ces sites l'adresse IP de la personne et, pour la seconde, ses initiales (donnée personnelle). Les tests les tolèrent pour l'instant, sans requête réelle.

Question posée le 03/11/2026 vers 00 h 10 UTC (Q-TIERS) : héberger la police chez PILIQI (fichiers dans public/, fournisseur de police local de Filament, sans outil Node) et dessiner les initiales sur le serveur (fournisseur d'avatar local), pour qu'aucune page n'envoie rien à un site tiers ? Oui (recommandé) / non, garder les sites tiers.

Réponse de l'éditeur (03/11/2026 vers 00 h 15 UTC) : « oui recommandé ». Décision : constat C4 de `navigateur/constats-editeur.md`, fait par le lot NAV-2 pour les trois panneaux et les pages publiques.
