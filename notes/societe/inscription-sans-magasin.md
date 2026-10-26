# Lot A4b : inscription d'une société sans magasin

Décision de l'éditeur (2026-10-11, verbatim dans reponses-serie6.md, section « Inscription sans magasin ») : une société s'inscrit SANS magasin. Ce document est le cahier des charges du lot ; il prime sur la conception v2 finale et sur CLAUDE.md, qu'il modifie. Relis le code actuel (inscription du lot A2, vérification du lot A3, travail du lot A4) avant de modifier quoi que ce soit.

## 1. Règles (texte à inscrire dans CLAUDE.md)
- Puce « Société et établissements » : remplacer « chaque société (un SIREN, un seul compte PILIQI) s'inscrit avec son propriétaire et son premier magasin (son SIRET) ; ses autres magasins ne se créent que dans l'espace d'administration de la société » par « chaque société (un SIREN, un seul compte PILIQI) s'inscrit avec son seul propriétaire, sans magasin ; tous ses magasins se créent dans l'espace d'administration de la société », le reste de la phrase inchangé (propriétaire ou administrateur autorisé ayant accès à tous les magasins, NIC seul saisi, SIRET à clé valide, actif et rattaché au SIREN au répertoire Sirene, jamais celui d'un autre magasin, établissement fermé ou inconnu refusé).
- Ajouter : à la création d'un magasin, PILIQI propose la liste des établissements actifs du SIREN lue au répertoire Sirene ; choisir un établissement préremplit son NIC, son nom et son adresse et propose un code ; la saisie du NIC reste possible.
- Puce « Comptes et équipe » : « Une société s'inscrit par un lien envoyé à l'adresse de son propriétaire, valable 24 heures » reste ; après ce lien, le propriétaire arrive dans l'espace de sa société, sur une liste d'étapes (vérifier la société, créer le premier magasin, ajouter l'équipe, essayer le magasin de démonstration).
- Une société sans magasin réel ne vend rien et n'est jamais facturée (étape 12) ; le magasin de démonstration reste disponible comme aujourd'hui.
- Vérification de la société : sa validation ne suppose aucun magasin (SIREN contrôlé au répertoire, adresse officielle du siège, qualité de représentant, courrier selon les règles existantes) ; chaque magasin reste contrôlé au répertoire Sirene à sa création, avant toute vente (règle existante). Relis la puce « Vérification de la société » et ajuste seulement ce qui supposait un premier magasin.

## 2. Inscription
- Formulaire : SIREN, nom du propriétaire, adresse e-mail (et ce que l'inscription actuelle demande en plus sans rapport avec un magasin, par exemple l'acceptation des conditions : garder). Plus aucun champ de magasin (nom, code, NIC).
- Contrôle du SIREN au répertoire Sirene comme aujourd'hui (clé de contrôle, entreprise active ; refus si cessée ou inconnue ; comportement actuel si le répertoire ne répond pas). Identité de la société préremplie depuis Sirene comme aujourd'hui.
- Aucun magasin, aucun SIRET réservé, aucun registre, aucune série créés à l'inscription. Règles inchangées : aucun écran ne révèle qu'un SIREN est déjà client ; une adresse e-mail ne sert qu'à un compte ; lien valable 24 h ; demandes non confirmées effacées.
- Le lien suivi confirme l'adresse du propriétaire (cohérent avec la série 6 : adresse confirmée).

## 3. Après la confirmation
- Le propriétaire arrive sur le tableau de bord de l'espace société, avec une liste d'étapes qui montre l'état de chacune : « Vérifier votre société » (statut de la vérification et prochaine action), « Créer votre premier magasin », « Ajouter votre équipe », « Essayer le magasin de démonstration ». Chaque étape mène à l'écran concerné ; une étape faite est cochée.
- Un compte sans aucun magasin accessible ne tombe jamais sur une erreur dans le panneau des magasins (/app) : il est redirigé vers l'espace de sa société (propriétaire, administrateur) ou vers une page claire (membre sans magasin). Test pour chaque cas.

## 4. Création d'un magasin dans l'espace société
- Le fournisseur Sirene (interface existante, implémentation réelle et factice) gagne la liste des établissements actifs d'un SIREN : SIRET, NIC, enseigne ou dénomination usuelle, adresse, indicateur de siège. Implémentation réelle : API Sirene de l'INSEE (requête par SIREN filtrée sur les établissements actifs ; vérifier la syntaxe exacte de la version utilisée par le code existant), clé lue comme aujourd'hui ; implémentation factice déterministe pour les tests et le développement ; aucune vraie requête dans les tests.
- Écran : la liste des établissements actifs ; ceux déjà pris par un magasin de la société sont marqués « déjà un magasin de votre société » ; pour les autres cas de refus (SIRET réservé ailleurs), garder exactement les messages et le comportement actuels de CreerEtablissementAction, sans jamais révéler une autre société. Choisir un établissement préremplit NIC, nom (enseigne, sinon « Magasin » + commune) et adresse ; un code est proposé, dérivé de la commune (majuscules sans accents ni espaces, longueur et caractères selon la règle actuelle du code de magasin, suffixe numérique pour l'unicité dans la société), modifiable avant le premier document comme aujourd'hui. Saisie manuelle du NIC possible (liste indisponible, établissement non listé) : mêmes contrôles qu'aujourd'hui.
- La création reste faite par CreerEtablissementAction (un seul chemin pour tous les magasins), avec ses contrôles actuels (droits : propriétaire ou administrateur autorisé ayant accès à tous les magasins ; Sirene ; SIRET jamais celui d'un autre magasin ; réservation ; client comptoir ; audit ; journal des événements du magasin).

## 5. Ce qui disparaît
- Le chemin qui créait le premier magasin pendant l'inscription (champs, validation, création, tests) : supprimé, pas désactivé. Les tests qui l'affirmaient sont réécrits pour la nouvelle règle (garder leur intention : contrôle Sirene, unicité, réservation), nommés dans le message de commit.
- Tout code, aide de test ou fabrique qui supposait qu'une société a toujours un premier magasin (par exemple dans la validation de la société ou le magasin de démonstration) est réécrit proprement.

## 6. Tests exigés (au minimum)
- L'inscription ne demande ni nom, ni code, ni NIC de magasin et ne crée aucun magasin, aucun SIRET réservé, aucun registre.
- Un SIREN cessé ou inconnu est refusé à l'inscription ; un SIREN à clé invalide aussi.
- Après le lien, le propriétaire arrive dans l'espace de sa société avec la liste d'étapes ; son adresse est confirmée.
- Un propriétaire, un administrateur et un membre sans magasin ouvrent /app sans erreur (redirection ou page claire).
- La liste des établissements actifs vient du fournisseur Sirene factice ; choisir un établissement préremplit NIC, nom, adresse et code ; le code proposé est unique dans la société.
- Un établissement déjà magasin de la société est marqué et refusé ; un établissement fermé ou d'un autre SIREN est refusé ; la saisie manuelle du NIC suit les mêmes contrôles.
- Aucune information sur une autre société n'apparaît dans la liste ni dans les messages.
- La société peut être validée sans magasin ; aucune vente n'est possible sans magasin réel ; le magasin de démonstration fonctionne sans magasin réel.
- Seuls le propriétaire et un administrateur autorisé ayant accès à tous les magasins créent un magasin (y compris par appel direct de l'action).
