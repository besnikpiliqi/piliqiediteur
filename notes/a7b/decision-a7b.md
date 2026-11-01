# Lot A7b : suites du lot A7 tranchées par l'éditeur le 01/11/2026

## 1. Les questions posées (message de la session du 01/11/2026, 16 h 25 UTC) et la réponse de l'éditeur, mot pour mot

Réponse de l'éditeur : « 1a 2a 3 oui »

Questions posées, telles qu'envoyées :

1. **Correction exceptionnelle du SIREN d'une société : qui émet la facture rectificative ?** La règle prévoit un avoir de régularisation sous l'ancien SIREN, puis une facture rectificative sous le nouveau. Mais le propriétaire ne crée ni n'émet jamais de facture, et la base refuse une vente à son nom.
   - **a) (retenue)** Le propriétaire fait l'avoir, comme tous les avoirs, et un compte autorisé à vendre dans ce magasin émet la facture rectificative. (Recommandé : aucune exception aux règles.)
   - b) Un opérateur de l'éditeur fait les deux par sa procédure, et la base accepte ce seul cas.
2. **Correction du SIREN d'un cabinet d'expertise comptable** (refusée pour l'instant par `CorrigerSirenAction::MESSAGE_CABINET`, car le code 69.20Z et l'inscription à l'Ordre ont été vérifiés sous l'ancien SIREN).
   - **a) (retenue)** La correction est permise. Ensuite, PILIQI recontrôle le code 69.20Z sous le nouveau SIREN et refait la vérification à l'Ordre. D'ici là, ses liens restent, mais il ne voit ni n'ouvre aucun client, comme tout cabinet non vérifié. (Recommandé.)
   - b) La correction reste refusée : le cabinet ferme son inscription et se réinscrit sous le bon SIREN, et ses clients refont le lien.
3. **Trois choix d'adaptation du lot A7, confirmés (« oui »)** :
   - une cession annulée laisse le magasin fermé, avec son motif d'avant la cession, ou fermé temporairement s'il était ouvert ; la société le rouvre elle-même après le contrôle Sirene, comme toute réouverture ;
   - lors d'une correction de SIREN, un magasin fermé pour cessation ou cédé garde son ancien SIRET ; s'il est fermé pour cessation et rouvert un jour, sa réouverture recontrôle son établissement ; un magasin cédé ne se rouvre jamais ;
   - les tests prévus pour la « réclamation » et la « preuve de qualité » sont adaptés à la décision du 19/10, qui a supprimé ces voies.

## 2. Ce que le lot A7b construit

### 2.1 Régularisation (Q6 de la série 5, réponse 1a)

Base : `societe/conception-v2-finale.md`, section 9.4, point 3 (régularisation), et la puce « Société et établissements » de `CLAUDE.md` (avoir de régularisation, facture rectificative, mention, confirmation par l'expert-comptable de la société le jour où le cas se présente). La réponse 1a remplace « propriétaire » de la conception pour l'émetteur :

- Seules les factures que désigne la dernière décision d'examen du SIREN (`ExaminerSirenAction`, décision `DecisionExamenSiren` inscrite à l'audit `SirenExamine`) se régularisent ; une facture déjà régularisée ne se régularise plus ; les tickets restent tels quels (BOI-TVA-DECLA-30-20-20-20).
- **L'avoir de régularisation** se fait comme tout avoir : par le propriétaire, ou par un compte autorisé à faire les avoirs dans ce magasin ; jamais par un compte d'un cabinet lié. Avoir de toute la facture, `regularisation = true`, référence de la facture d'origine, émetteur figé de la facture d'origine (l'ancien SIREN), mention « Avoir de régularisation émis après correction de l'identité du vendeur (SIREN correct : Y) » dans le pied du PDF (S8-P), sans retour en stock (`RestaurerStockApresEmissionAvoir` l'ignore).
- **La facture rectificative** est émise par un compte autorisé à créer et émettre une facture dans ce magasin à ce moment (responsable, vendeur, administrateur ou observateur à qui la vente est cochée) ; jamais par le propriétaire (la base refuse toujours une vente à son nom), ni par un compte d'un cabinet lié. Elle porte l'identité actuelle de la société (le SIREN correct), `facture_rectifiee_id`, les mêmes lignes que l'origine, non modifiables, la `livraison_date` de l'origine ou sa date d'émission, la mention « Facture rectificative de la facture n° X du JJ/MM/AAAA », sans sortie de stock (`DecrementerStockApresEmissionFacture` l'ignore).
- **Imputation** : le crédit de l'avoir de régularisation ne s'impute jamais ailleurs que sur le reste dû de la facture d'origine, puis sur sa rectificative, avant toute imputation automatique ; tant que la rectificative n'existe pas, ce qui reste du crédit lui est réservé (ni imputation automatique sur les autres factures du client, ni bon d'avoir). Quel que soit l'ordre des deux gestes, l'imputation sur la rectificative se fait dans la transaction signée de celui qui la rend possible. **Cette imputation n'est jamais un encaissement** : aucun statut « encaissée » ni paiement B2C déclaré, la rectificative reprend les encaissements déjà déclarés de l'origine, l'e-reporting l'ignore.
- Jetons des demandes répétées, verrous et ordre des verrous comme pour un avoir et une émission de facture ; audit (ajouter les actions nécessaires à `ActionAuditee`) et JET comme pour les avoirs et les factures.
- Écran : depuis la fiche de la facture d'origine, l'action « Faire l'avoir de régularisation » (visible seulement pour une facture désignée par l'examen et pour qui peut faire un avoir) et l'action « Émettre la facture rectificative » (visible seulement pour qui peut émettre une facture dans ce magasin, une fois la facture désignée) ; libellés et ordre exacts à choisir par le lot.
- Tests nommés : ceux de la section 9.4 (point 3) de la conception et de la section 14 pour la régularisation, adaptés à l'émetteur 1a, plus : refus pour le propriétaire de la rectificative (écran, action et base), refus pour un compte d'un cabinet lié, refus pour une facture non désignée ou déjà régularisée, crédit réservé tant que la rectificative manque (aucune imputation automatique ailleurs), les deux ordres des gestes, imputation non déclarée, demande répétée, deux régularisations simultanées de la même facture sous PostgreSQL.

### 2.2 Correction du SIREN d'un cabinet (réponse 2a)

- `CorrigerSirenAction` accepte un cabinet d'expertise comptable ; `MESSAGE_CABINET` et son refus disparaissent.
- Dans la même transaction que la correction : le contrôle du code 69.20Z et la vérification à l'Ordre du cabinet sont remis à faire pour le nouveau SIREN (la vérification à l'Ordre faite sous l'ancien SIREN ne vaut plus ; sa trace reste au journal d'audit). Ensuite, le code d'activité du nouveau SIREN se contrôle au répertoire Sirene comme à l'inscription (code absent ou d'une autre nomenclature : contrôlé par l'éditeur), et l'inscription à l'Ordre se vérifie par l'annuaire de l'Ordre (pilote) ou par la procédure de l'éditeur, comme pour un cabinet qui s'inscrit.
- D'ici là, le cabinet n'est plus « vérifié » : ses liens restent, mais lui et ses collaborateurs ne voient ni n'ouvrent aucun client, n'invitent ni ne lient personne, et ses invitations et demandes ouvertes ne peuvent pas être acceptées ; son propriétaire peut seulement mettre fin à ses liens (règle de `CLAUDE.md` pour tout cabinet non vérifié). Une session ouverte d'un compte du cabinet perd l'accès dès sa requête suivante.
- Le propriétaire du cabinet est prévenu ; choix de Claude, contestable : le propriétaire de chaque client lié est prévenu que l'accès de son cabinet est suspendu jusqu'à sa nouvelle vérification (sans autre détail), et l'est de nouveau quand l'accès revient.
- Si le nouveau SIREN n'est pas 69.20Z ou si l'Ordre ne confirme pas l'inscription, le cabinet reste non vérifié (les procédures de refus existantes s'appliquent).
- Tests nommés : correction acceptée pour un cabinet, vérification remise à faire, accès refusé aux clients et invitations refusées d'ici là, fin de lien encore possible, accès rétabli après le contrôle 69.20Z et la vérification à l'Ordre, avis envoyés, session ouverte qui perd l'accès, isolation (aucun autre cabinet ni client touché).

### 2.3 CLAUDE.md

- Puce « Société et établissements », phrase de la régularisation : préciser que l'avoir de régularisation se fait comme tout avoir et que la facture rectificative est émise par un compte autorisé à vendre dans le magasin, jamais par le propriétaire ; le crédit de cet avoir est réservé à la facture qu'il régularise puis à sa rectificative.
- Puce « Cabinets d'expertise comptable » : une correction de son SIREN par la procédure de l'éditeur remet à faire le contrôle 69.20Z et la vérification à l'Ordre ; d'ici là, ses liens restent, sans aucun accès.
- Rien d'autre ne change dans `CLAUDE.md`.

### 2.4 Hors de ce lot

- La règle de la régularisation reste « à faire confirmer par l'expert-comptable de la société le jour où le cas se présente » (`CLAUDE.md`).
- Nouvelle attestation par magasin après une correction de SIREN : étape 3. Connexion et adresses de réception SUPER PDP après une correction de SIREN : étape 2.
