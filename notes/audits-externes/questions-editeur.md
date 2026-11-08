# Questions à l'éditeur — audits remis le 7 novembre 2026 (lot AUD)

Cinq questions de règle métier. L'option recommandée est toujours la (a). Sans réponse au début du lot AUD, le lot fait tout le reste et laisse seulement le point concerné. Le détail est dans `rapport-lecture-2026-11-07.md` et `decision-aud.md` (§ 4).

**Q-AUD1 — Écart dans le stock trouvé par la vérification de chaque nuit** (un stock qui ne correspond plus à ses entrées et sorties, ou un achat validé sans trace au journal d'audit : cela ne peut venir que d'une écriture faite en dehors de PILIQI)
- (a) recommandé : l'éditeur et le propriétaire de la société sont prévenus ; rien n'est bloqué, le magasin continue de vendre en attendant l'examen.
- (b) l'éditeur seul est prévenu.
- (c) comme (a), et la vente du produit concerné est suspendue jusqu'à l'examen.

**Q-AUD2 — Faux ticket Z** (un ticket Z écrit directement dans la base, sans la signature de la clôture ; la clôture du magasin s'arrête et l'éditeur est alerté)
- (a) recommandé : la procédure de l'éditeur le retire, sur pièces, en l'inscrivant au journal d'audit et au journal des événements du magasin, puis PILIQI produit le vrai ticket Z de cette journée.
- (b) il est gardé, marqué « non signé – sans valeur », et le vrai ticket Z de la journée est produit sous un autre numéro.

**Q-AUD3 — Envoi d'une facture à la plateforme agréée qui échoue après toutes les tentatives automatiques** (panne du réseau ou de la plateforme ; PILIQI continue de toute façon de la relancer seul chaque heure)
- (a) recommandé : le propriétaire et les administrateurs à qui il a donné l'autorisation de la plateforme agréée (comme pour refuser ou contester une facture reçue) sont prévenus et peuvent la relancer à la main.
- (b) le propriétaire seul.
- (c) comme (a), et aussi la personne qui a émis la facture.

**Q-AUD4 — Ajustements du stock à la main (casse, perte, comptage), prévus à l'étape 7** (d'ici là, aucun écran n'en fait et la base refuse tout ajustement sans document)
- (a) recommandé : toujours par un document numéroté (fiche de comptage ou de casse) avec son motif, validé par le propriétaire, un responsable du magasin ou un compte à qui une nouvelle autorisation « ajuster le stock » est cochée (l'autorisation actuelle « gérer le stock » ne règle que les alertes) ; un compte du cabinet lié seulement pour un inventaire de comptage, si le client lui accorde les inventaires.
- (b) un simple motif suffit, sans document, par les mêmes personnes.
- (c) seulement le propriétaire et les responsables du magasin, jamais un autre compte ni le cabinet.

**Q-AUD5 — Heure signée des ventes quand PILIQI tournera sur plusieurs serveurs**
- (a) recommandé : l'horloge de chaque serveur, tenue exacte automatiquement et contrôlée par PILIQI ; une vente horodatée avant la précédente du même magasin reste refusée et l'éditeur est prévenu, comme aujourd'hui.
- (b) l'horloge du serveur de la base de données, la même pour tous.
- (c) une tolérance de quelques secondes : la vente prend l'heure de la précédente, et le fait est inscrit au journal des événements.

Et si l'horloge d'un serveur est partie en avance puis a été corrigée :
- (a) recommandé : on attend que l'heure réelle rattrape (les ventes du magasin touché sont refusées pendant ce temps), sans aucune procédure qui permettrait d'antidater ;
- (b) une procédure de l'éditeur.
