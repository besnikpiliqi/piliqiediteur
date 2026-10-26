# Quatrième série : réponses de l'éditeur (2026-10-10, verbatim)

Réponses lues dans la page d'arbitrages (collection reponses). Le choix « autre » ou une note de l'éditeur prime sur le texte de l'option.

## Société, établissements et membres

### S1 — Arrivée des membres
- Choix : b — Création directe comme aujourd'hui, avec un mot de passe à changer à la première connexion.
- Note de l'éditeur (verbatim) :

  > Mais ce membre ne peut pas être rattaché à un autre magasin si le magasin ne l’a pas encore supprimé de son membre

### S2 — Connexion SUPER PDP par société
- Choix : a (recommandé) — Une connexion par société, faite par le propriétaire ; une adresse de réception par magasin (SIREN_SIRET). Une facture reçue qui ne désigne aucun magasin va dans une liste « à attribuer » de la société ; le propriétaire et les administrateurs autorisés sont alertés et choisissent le magasin.
- Note de l'éditeur (verbatim) :

  > Je veux que PILIQI attribue automatiquement les factures fournisseurs au bon magasin en priorité grâce au SIRET destinataire ou à l’adresse électronique de facturation et au code de routage de l’établissement.
  > 
  > Si ces informations ne suffisent pas, PILIQI peut utiliser l’adresse physique et les références du magasin pour proposer une correspondance, sans attribution automatique en cas d’ambiguïté.
  > 
  > La liste « À attribuer » doit servir uniquement pour les factures dont le destinataire exact ne peut pas être déterminé avec certitude, ou dont les informations sont contradictoires.
  > 
  > Chaque magasin doit voir uniquement les factures qui lui sont attribuées. Toute attribution ou correction doit être journalisée, sans créer de doublon.
  > 
  > Cette logique doit être adaptée aux données réellement fournies par l’API SUPER PDP.

### S3 — Adresse du siège social sur les factures
- Choix : a (recommandé) — Adresse du siège enregistrée sur la société, exigée avant une facture à un professionnel, imprimée à côté de l'adresse du magasin.
- Note de l'éditeur (verbatim) :

  > Je confirme que l’adresse du siège social est enregistrée au niveau de la société et partagée avec tous ses magasins.
  > 
  > Chaque facture doit identifier correctement la société émettrice et l’établissement concerné, avec son SIRET et son adresse.
  > 
  > Si l’adresse du siège social est identique à celle du magasin, éviter de l’afficher inutilement deux fois.
  > 
  > Les adresses figurant sur une facture émise doivent être conservées telles qu’elles étaient au moment de l’émission, même si la société déménage ensuite.

### S4 — Capital social sur les factures
- Choix : a (recommandé) — Exigé pour les SARL, SAS, SA, SCA et SE avant une facture à un professionnel ; facultatif pour les autres formes.
- Note de l'éditeur (verbatim) :

  > Je confirme l’option A.
  > 
  > Le capital social est enregistré au niveau de la société, et non séparément pour chaque magasin.
  > 
  > PILIQI doit gérer les différentes formes juridiques, notamment SARL, EURL, SAS, SASU, SA, SCA, SE et entrepreneur individuel.
  > 
  > Le capital social doit être exigé et affiché sur les factures lorsque la réglementation applicable à la forme juridique l’impose.
  > 
  > Pour les entrepreneurs individuels et micro-entrepreneurs, aucun capital social n’est demandé.
  > 
  > Les modifications du capital social ne doivent jamais changer les factures déjà émises. Les factures conservent les informations juridiques en vigueur lors de leur émission.

### S5 — Entrepreneur individuel
- Choix : a (recommandé) — Forme « Entrepreneur individuel » gérée : mention « EI » contrôlée dans le nom, pas de capital, adresse de l'entreprise à la place du siège.
- Note de l'éditeur (verbatim) :

  > Je confirme l’option A.
  > 
  > PILIQI doit prendre en charge les entrepreneurs individuels, y compris les micro-entrepreneurs.
  > 
  > Lorsqu’une société cliente est enregistrée sous la forme « Entrepreneur individuel », le logiciel doit adapter automatiquement les informations juridiques et les mentions obligatoires.
  > 
  > Le capital social n’est pas demandé. L’identité de l’entrepreneur doit comporter la mention légale « Entrepreneur individuel » ou « EI » lorsque celle-ci est requise.
  > 
  > L’entrepreneur individuel peut gérer plusieurs établissements sous son même SIREN, avec un SIRET propre à chaque établissement.
  > 
  > L’adresse légale de l’entreprise et celle de chaque établissement doivent être correctement distinguées, et les factures émises doivent conserver leurs informations d’origine même si ces adresses changent ensuite.

### S6 — Vérification de l'identité de la société
- Choix : a (recommandé) — Contrôle au répertoire Sirene de l'INSEE à l'inscription et à chaque nouveau magasin : refus si l'entreprise est cessée, l'établissement fermé ou le numéro inconnu. Tant que vous n'avez pas validé la qualité de représentant (Kbis et pièce d'identité, puis connexion SUPER PDP au même SIREN dès l'étape 2), la société est « à vérifier » : tickets et ventes aux particuliers permis, mais aucune facture à un professionnel, aucun e-mail aux clients, aucune transmission. Si l'INSEE ne répond pas, elle reste « à vérifier ».
- Note de l'éditeur (verbatim) :

  > Je souhaite ajouter une possibilité de vérification de l'entreprise par courrier postal.
  > 
  > Lorsqu'une société s'inscrit sur PILIQI, son SIREN, son SIRET et son adresse officielle doivent être vérifiés auprès des sources officielles.
  > 
  > Pour renforcer la sécurité, PILIQI doit pouvoir envoyer un courrier postal contenant un code unique de confirmation à l'adresse officielle de la société. Le code possède une durée de validité limitée et ne peut être utilisé qu'une seule fois.
  > 
  > Cependant, je ne veux pas nécessairement imposer un courrier à toutes les sociétés si une vérification électronique fiable permet déjà de confirmer l'identité du représentant ou son autorisation.
  > 
  > La réception du courrier constitue une preuve de contrôle de l'adresse, mais ne suffit pas à elle seule à prouver la qualité de représentant légal.
  > 
  > Prévoir cette méthode comme vérification complémentaire, notamment lorsqu'un risque d'usurpation est détecté ou que les autres vérifications sont insuffisantes.
  > 
  > Avant la validation complète de la société, les opérations commerciales réelles restent désactivées. Le compte peut être configuré et utilisé en démonstration.
  > 
  > Propose la combinaison de vérifications la plus fiable, sécurisée, automatisable et adaptée à un SaaS accueillant de nombreuses sociétés.

### S7 — SIREN usurpé et SIRET saisi par erreur
- Choix : a (recommandé) — Procédure de l'éditeur, sur justificatif du vrai titulaire : la société usurpatrice passe en lecture seule (ses documents restent consultables et ne sont jamais modifiés), son SIREN et ses SIRET sont libérés ; un SIRET saisi par erreur peut aussi être libéré. Tout est journalisé. Cela déroge à « son SIRET ne sert jamais à un autre magasin ».
- Note de l'éditeur (verbatim) :

  > Je souhaite renforcer les décisions S6 et S7 avec une vérification possible par courrier recommandé avec accusé de réception et remise contre signature.
  > 
  > PILIQI doit vérifier le SIREN, le SIRET et l'adresse officielle de l'entreprise auprès de l'INSEE.
  > 
  > Lorsqu'une confirmation postale est nécessaire, un courrier recommandé contenant un code unique, temporaire et à usage unique est envoyé à l'adresse officielle de l'entreprise.
  > 
  > Le code doit être saisi dans le compte PILIQI pour confirmer la réception du courrier.
  > 
  > Cependant, la signature du recommandé ne remplace pas la vérification des droits du représentant légal ou de la personne mandatée par l'entreprise.
  > 
  > Aucune opération commerciale réelle ne sera autorisée tant que les contrôles obligatoires ne sont pas validés.
  > 
  > Cette procédure doit être intégrée à S6 pour prévenir les usurpations et à S7 pour sécuriser la récupération des comptes et la correction des SIREN ou SIRET usurpés.
  > 
  > Prévoir une solution de vérification électronique équivalente lorsqu'elle apporte des garanties suffisantes, afin de ne pas rendre systématiquement obligatoire l'envoi postal.

### S8 — Qui ferme et qui rouvre un magasin
- Choix : c — Fermeture et réouverture par la société elle-même.
- Note de l'éditeur (verbatim) :

  > mais la quand il ferme alors leurs abonnements doivent etre arrete et si ouvrir alors commencer

### S9 — Fin de la caisse d'un magasin fermé
- Choix : a (recommandé) — À la fermeture d'un magasin (et à la résiliation de la société) : clôtures journalière, mensuelle et de l'exercice à la date de fermeture, archive signée finale, puis plus aucun ticket Z pour ce magasin.

### S10 — Transfert d'un établissement à une autre société
- Choix : a (recommandé) — Rien ne suit : la société qui reprend saisit ou importe son catalogue. Le stock du magasin fermé sort au coût moyen, journalisé, et le montant hors taxe à déclarer est remis au propriétaire ; la nouvelle société fait un inventaire d'ouverture.

### S11 — SIREN corrigé après le premier document : factures déjà émises
- Choix : autre — Autre (voir la note)
- Note de l'éditeur (verbatim) :

  > Je veux que cette décision soit cohérente avec S6 et S7.
  > 
  > Le SIREN de la société doit être vérifié auprès de l'INSEE et l'identité ou les droits du représentant doivent être confirmés avant toute émission de document fiscal réel. Une confirmation par courrier recommandé avec code unique peut également être utilisée lorsque nécessaire.
  > 
  > Une fois ces vérifications réalisées et le premier document fiscal émis, le SIREN est verrouillé définitivement pour les modifications ordinaires.
  > 
  > Je ne veux donc pas de mécanisme de correction systématique du SIREN et de refacturation.
  > 
  > Cependant, si une erreur ou une usurpation est exceptionnellement découverte après validation, prévoir une procédure réservée à l'administration PILIQI, avec justificatifs, traçabilité et examen des documents concernés.
  > 
  > Aucun document déjà émis ne doit être modifié ou supprimé rétroactivement.
  > 
  > Les éventuels avoirs, factures rectificatives ou autres mesures de régularisation doivent être utilisés uniquement lorsque la situation juridique et fiscale l'exige, sans provoquer de mouvements de stock artificiels ni de doubles paiements.
  > 
  > L'objectif est de prévenir les erreurs avant l'activation du compte, plutôt que de devoir les corriger après l'émission des factures.

### S12 — Retirer le paquet spatie/laravel-permission
- Choix : a (recommandé) — Le retirer : les autorisations cochées d'un membre sont rangées avec son affectation au magasin et contrôlées contre la liste des autorisations ; même contrôle, sans le cache global.
- Note de l'éditeur (verbatim) :

  > Je confirme la suppression de spatie/laravel-permission et son remplacement par une architecture de permissions propre à PILIQI, si cette solution est techniquement meilleure.
  > 
  > Je rappelle une règle fondamentale de développement de PILIQI :
  > 
  > Le logiciel est encore en développement et n'a pas de véritables clients en production. Les données actuelles sont uniquement des données de développement et de test.
  > 
  > Tu ne dois donc pas conserver des tables, migrations, fonctions, classes, dépendances ou mécanismes de compatibilité inutiles simplement pour préserver les anciennes données fictives.
  > 
  > Lorsqu'une architecture doit être améliorée, tu peux la reconstruire proprement, modifier ou remplacer les migrations, supprimer les anciennes structures et réinitialiser les bases de développement et de test si nécessaire.
  > 
  > Je préfère une architecture finale simple, cohérente, optimisée, maintenable et sans dette technique inutile.
  > 
  > Cependant, tu dois toujours programmer PILIQI avec la rigueur d'un logiciel destiné à la production :
  > 
  > - Sécurité et isolation stricte entre sociétés et magasins.
  > - Permissions configurables et contrôlées côté serveur.
  > - Transactions et contraintes d'intégrité fiables.
  > - Code propre, sans doublons ni fonctionnalités obsolètes.
  > - Tests automatisés complets, y compris tests de concurrence.
  > - Installation reproductible depuis une base de données vide.
  > - Migrations propres permettant les futures évolutions sans perte de données lorsque PILIQI sera commercialisé.
  > 
  > Ne conserve pas d'ancienne architecture uniquement parce qu'elle existe déjà.
  > 
  > Les sauvegardes et Git doivent permettre de retrouver le code en cas de problème, mais ne justifient pas l'accumulation de code inutile.
  > 
  > Je veux que PILIQI soit construit correctement dès maintenant, comme un logiciel professionnel destiné à la production, sans pour autant imposer aujourd'hui la conservation des données fictives.
  > 
  > Après sa mise en production, les futures migrations devront évidemment préserver les données réelles des clients.

### S13 — Adresses IP et navigateurs du journal d'audit
- Choix : a (recommandé) — Les actions du journal d'audit se gardent 10 ans ; l'IP et le navigateur sont rangés à part et effacés après 1 an.
- Note de l'éditeur (verbatim) :

  > Je confirme la première option.
  > 
  > Je veux que PILIQI conserve un journal d'audit fiable des opérations importantes, notamment les factures, paiements, modifications de permissions et opérations administratives.
  > 
  > Les adresses IP et informations de navigateur doivent être conservées séparément, avec une durée adaptée aux besoins de sécurité et aux recommandations de la CNIL.
  > 
  > Les durées de conservation des actions doivent respecter les obligations légales applicables à chaque catégorie, sans conserver inutilement des données personnelles.
  > 
  > Les journaux doivent être protégés contre les modifications et accessibles uniquement aux personnes autorisées.

### S14 — Changement légal de la date de fin d'exercice
- Choix : a (recommandé) — Procédure de l'éditeur sur justificatif (procès-verbal), jamais rétroactive (effet au prochain exercice), journalisée dans chaque magasin.
- Note de l'éditeur (verbatim) :

  > La décision S14 doit respecter ma décision D4 déjà validée.
  > 
  > Je confirme que le mois de fin d'exercice est librement configurable par la société avant la première vente, avec décembre comme valeur par défaut.
  > 
  > Après la première vente, ce paramètre est verrouillé pour empêcher les changements ordinaires susceptibles de perturber les clôtures et les archives.
  > 
  > Une modification exceptionnelle reste possible lorsqu'une société change légalement sa date de clôture, suivant une procédure sécurisée et justifiée.
  > 
  > Cette modification ne doit jamais permettre de modifier les exercices déjà clôturés, supprimer des archives ou repousser artificiellement les clôtures obligatoires.
  > 
  > La date d'effet doit respecter les règles légales applicables, y compris lorsqu'un changement concerne un exercice encore ouvert.
  > 
  > Toutes les modifications sont journalisées.
  > 
  > Il ne faut donc pas remplacer D4 : S14 doit seulement compléter D4 en prévoyant cette exception légale, sans compromettre l'intégrité des données de caisse.

## Choix retenus ou déjà appliqués

### V1 — Interprétations de vos réponses sur l'architecture
Liste soumise :
1. Un compte appartient à une seule société (et non plus à un seul magasin) ; un membre peut être affecté à plusieurs magasins de cette société.
2. Un administrateur habilité agit dans toute la société, avec les mêmes autorisations dans tous ses magasins, présents et futurs.
3. Le « responsable » remplace l'ancien administrateur de magasin. Une alerte de stock va à toute personne autorisée dans le magasin ; les factures reçues relèvent de l'administrateur de société autorisé.
4. L'établissement principal n'est qu'une indication, sans aucun effet.
5. Une société suspendue ou résiliée se comporte comme un magasin suspendu ou fermé : lecture seule ; son équipe reste gérable.
6. Aucun écran ne change le statut d'une société avant l'administration de l'éditeur (étape 13).
7. Le journal d'audit est consultable par le propriétaire seul.
8. Le propriétaire peut transmettre lui-même la propriété à un administrateur actif ; un transfert forcé par l'éditeur viendra à l'étape 13, avec justificatif et délai d'opposition de 7 jours.
9. Une personne retirée d'une société peut rejoindre une autre société avec le même compte. Un magasin ne change jamais de société : une reprise passe par le transfert d'établissement.
10. L'e-mail et le téléphone du magasin sont des réglages du magasin.
11. Un changement de SIRET prend effet le jour même ; un déménagement qui change le territoire de TVA passe par un nouveau magasin.
12. La double authentification des propriétaires et administrateurs est reportée à la mise en production : elle demandera un paquet, donc votre accord.
- Choix : b — J'en conteste au moins une (numéro et précision dans la note).
- Note de l'éditeur (verbatim) :

  > 'ai deux modifications sur vos interprétations :
  > 
  > Pour le point 1 : L'idée est bonne, mais il faut s'assurer de la traçabilité. Un membre (surtout un vendeur) doit être strictement rattaché au magasin dans lequel il encaisse au moment de la vente. La caisse doit toujours savoir exactement dans quel magasin physique la vente est réalisée et par qui, pour que la facture soit émise sur le bon SIRET.
  > 
  > Pour le point 2 : Je ne veux pas que l'administrateur ait d'office accès à toute la société. C'est le propriétaire qui doit choisir ses droits à la carte au moment de l'invitation : le propriétaire doit pouvoir définir si cet administrateur a accès à tous les magasins, ou uniquement à une sélection de magasins précis.

### V2 — Règles déjà appliquées
Liste soumise :
1. PostgreSQL en production, SQLite pour le développement et les tests rapides ; la suite complète passe sur les deux, en local et en CI.
2. Une adresse e-mail s'enregistre et se compare en minuscules : A@x.fr et a@x.fr sont la même adresse.
3. Les recherches ignorent la casse et les accents : « creme » trouve « Crème ».
4. Une référence produit est unique dans le magasin sans tenir compte de la casse : « ABC » et « abc » sont la même référence.
5. Devis : un brouillon dont la validité est déjà dépassée ne s'envoie pas ; un devis envoyé échu est traité comme expiré dès l'échéance ; un devis repassé en brouillon perd sa date d'émission et son PDF l'indique.
- Choix : a (recommandé) — Je les confirme toutes.

## Campagne de tests

### T1 — Place de la campagne dans la feuille de route
- Choix : a (recommandé) — Campagne initiale juste après l'architecture société et les lots de décisions 14 à 21 ; une relance après chaque grande étape (SUPER PDP, caisse sécurisée, comptabilité, RGPD, administration) ; une campagne finale avant la mise en production.

### T2 — Volume des données fictives
- Choix : a (recommandé) — Campagne initiale sur cette machine au plus grand volume qu'elle tient, les résultats au-delà signalés comme extrapolés ; une machine dédiée avant la mise en production pour le volume complet.

### T3 — Configuration testée
- Choix : a (recommandé) — Les deux : d'abord la configuration actuelle, puis Redis.

### T4 — Composition des équipes fictives
- Choix : a (recommandé) — Par société : 1 propriétaire et au moins 1 administrateur ; par magasin : au moins 1 responsable et 2 vendeurs.

### T5 — Gravité des anomalies
- Choix : a (recommandé) — Je classe et je corrige ; le rapport montre chaque classement et vous pouvez le contester. Vous validez seulement le déclassement d'une anomalie bloquante.

### T6 — Publication des preuves
- Choix : a (recommandé) — Publiés après chaque palier dans une page privée, avec leur empreinte enregistrée dans le dépôt.

## Règles métier révélées par la préparation des tests

### R1 — Vente au-delà du stock
- Choix : c — Vente refusée, sauf pour un membre qui a l'autorisation « vendre sans stock ».
- Note de l'éditeur (verbatim) :

  > Lorsqu'un membre possède l'autorisation « vendre sans stock » et effectue une vente dépassant le stock disponible, PILIQI doit afficher un avertissement clair avant validation et générer une alerte de stock.
  > 
  > Si la vente est confirmée, la quantité négative reste visible jusqu'à sa régularisation par un achat, une réception de marchandises ou un inventaire.
  > 
  > Sans cette autorisation, la vente est refusée.
  > 
  > Les opérations doivent être journalisées et les ventes simultanées doivent respecter ces mêmes permissions et maintenir un stock cohérent.

### R2 — Remises et prix des lignes de service
- Choix : a (recommandé) — Nouvelle autorisation « accorder des remises et fixer le prix d'un service » : cochée par défaut pour le propriétaire, les administrateurs et les responsables, pas pour les vendeurs.

### R3 — Demande répétée après une réponse perdue
- Choix : a (recommandé) — Chaque action porte un jeton unique : la demande répétée renvoie le résultat déjà enregistré, sans doublon.

### R4 — Répartition de la TVA d'un taux sur ses lignes
- Choix : a (recommandé) — Garder cette répartition par cumul, dans l'ordre des lignes, et l'inscrire comme règle, testée au centime.

### R5 — Caisse personnelle restée ouverte
- Choix : a (recommandé) — La clôture du jour n'est jamais bloquée : elle ferme d'office les caisses personnelles encore ouvertes, avec la mention « fermée automatiquement » sur leur relevé. Un responsable peut aussi la fermer pour le vendeur.
- Note de l'éditeur (verbatim) :

  > deja doivent se fermer automatiquement à minuit s'il n'est pas fermer manuelement
