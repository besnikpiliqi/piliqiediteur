const GROUPES = [
  { id: "S", titre: "Société, établissements et membres", texte: "Votre architecture est conçue et relue (sécurité, droit, cohérence avec vos décisions). Une société, identifiée par son SIREN, porte l'identité légale et les réglages fiscaux communs. Ses magasins (SIRET, code, caisse, stock, ventes, paramètres locaux) sont créés depuis son espace d'administration. Un compte appartient à une seule société : un propriétaire, des administrateurs habilités, et des responsables et vendeurs affectés magasin par magasin. L'inscription se fait en deux temps avec un lien par e-mail. Il n'y a plus de magasin principal, et un SIREN ne se rattache jamais par simple saisie. Les procédures exceptionnelles sont réservées à l'éditeur, justifiées et journalisées. Restent ces quatorze points, que vos réponses ne tranchent pas." },
  { id: "V", titre: "Choix retenus ou déjà appliqués", texte: "Pour avancer, j'ai interprété certaines de vos réponses et appliqué quelques règles. Confirmez-les ou contestez-les : je change ce que vous contestez." },
  { id: "T", titre: "Campagne de tests", texte: "La stratégie complète est prête : environnement isolé, sociétés fictives, concurrence réelle sur PostgreSQL, simulateur SUPER PDP sans aucune vraie requête, environ 130 contrôles automatiques après chaque palier, montée progressive, rapport vérifiable. Ces points dépendent de vous (ressources, organisation)." },
  { id: "R", titre: "Règles métier révélées par la préparation des tests", texte: "La reconnaissance du code a fait apparaître des cas que vos décisions ne règlent pas encore. Ce sont des règles métier : je n'en choisis aucune à votre place." }
];

const DECISIONS = [
  { id: "S1", g: "S", titre: "Arrivée des membres",
    contexte: "Aujourd'hui, le propriétaire crée le compte et donne le mot de passe de la main à la main, sans vérification de l'adresse e-mail.",
    source: "Architecture Q1",
    options: [
      { k: "a", t: "Invitation par e-mail : lien valable 24 h, à usage unique ; la personne choisit elle-même son mot de passe. Chaque membre doit avoir une adresse e-mail accessible." },
      { k: "b", t: "Création directe comme aujourd'hui, avec un mot de passe à changer à la première connexion." },
      { k: "c", t: "Les deux, au choix de celui qui ajoute le membre." }
    ], reco: "a" },
  { id: "S2", g: "S", prioritaire: true, titre: "Connexion SUPER PDP par société",
    contexte: "Votre règle actuelle dit « chaque magasin a sa connexion » et « une facture reçue sans magasin précis va au magasin principal ». Or, d'après la spécification de SUPER PDP, un compte correspond à un SIREN et sa connexion voit toutes les factures de l'entreprise : une connexion par magasin n'isolerait rien, et le magasin principal n'existe plus.",
    source: "Architecture Q2",
    options: [
      { k: "a", t: "Une connexion par société, faite par le propriétaire ; une adresse de réception par magasin (SIREN_SIRET). Une facture reçue qui ne désigne aucun magasin va dans une liste « à attribuer » de la société ; le propriétaire et les administrateurs autorisés sont alertés et choisissent le magasin." },
      { k: "b", t: "Une connexion par société ; les factures sans magasin vont à l'établissement principal indiqué par la société." },
      { k: "c", t: "Une connexion par magasin (déconseillé : aucune isolation réelle)." }
    ], reco: "a" },
  { id: "S3", g: "S", titre: "Adresse du siège social sur les factures",
    contexte: "Le Code de commerce (art. R123-237) exige l'adresse du siège sur les factures ; PILIQI n'a aucun champ pour elle.",
    source: "Architecture Q3 (i)",
    options: [
      { k: "a", t: "Adresse du siège enregistrée sur la société, exigée avant une facture à un professionnel, imprimée à côté de l'adresse du magasin." },
      { k: "b", t: "Facultative." }
    ], reco: "a" },
  { id: "S4", g: "S", titre: "Capital social sur les factures",
    contexte: "Votre règle l'imprime seulement s'il est renseigné. Le Code de commerce (art. R123-238) l'imposerait pour les SARL, SAS et SA ; le texte officiel n'a pas pu être relu en entier, seulement des extraits.",
    source: "Architecture Q3 (ii)",
    options: [
      { k: "a", t: "Exigé pour les SARL, SAS, SA, SCA et SE avant une facture à un professionnel ; facultatif pour les autres formes." },
      { k: "b", t: "Garder la règle actuelle." }
    ], reco: "a" },
  { id: "S5", g: "S", titre: "Entrepreneur individuel",
    contexte: "Un entrepreneur individuel n'a pas de capital ; son nom porte la mention « EI » et l'adresse de l'entreprise est souvent son domicile (art. R526-27).",
    source: "Architecture Q3 (iii)",
    options: [
      { k: "a", t: "Forme « Entrepreneur individuel » gérée : mention « EI » contrôlée dans le nom, pas de capital, adresse de l'entreprise à la place du siège." },
      { k: "b", t: "Rien de particulier." }
    ], reco: "a" },
  { id: "S6", g: "S", prioritaire: true, titre: "Vérification de l'identité de la société",
    contexte: "Le SIREN et le SIRET sont publics : n'importe qui peut s'inscrire avec ceux d'une vraie entreprise, puis émettre et envoyer des factures à son nom. Le contrôle automatique demande une clé gratuite de l'INSEE (portail-api.insee.fr), que vous créerez et que je lirai dans une variable d'environnement.",
    source: "Architecture Q4",
    options: [
      { k: "a", t: "Contrôle au répertoire Sirene de l'INSEE à l'inscription et à chaque nouveau magasin : refus si l'entreprise est cessée, l'établissement fermé ou le numéro inconnu. Tant que vous n'avez pas validé la qualité de représentant (Kbis et pièce d'identité, puis connexion SUPER PDP au même SIREN dès l'étape 2), la société est « à vérifier » : tickets et ventes aux particuliers permis, mais aucune facture à un professionnel, aucun e-mail aux clients, aucune transmission. Si l'INSEE ne répond pas, elle reste « à vérifier »." },
      { k: "b", t: "Contrôle Sirene seulement, sans restriction (risque de fausses factures au nom d'un tiers)." },
      { k: "c", t: "Vérification manuelle par vous avant toute vente." }
    ], reco: "a" },
  { id: "S7", g: "S", titre: "SIREN usurpé et SIRET saisi par erreur",
    contexte: "Le SIREN est unique, une société ne s'efface jamais, et un SIRET porté une fois est réservé pour toujours (votre règle). Un usurpateur qui a émis un seul ticket bloquerait donc pour toujours le SIREN du vrai titulaire ; un magasin qui a tapé le SIRET d'un voisin le lui confisquerait.",
    source: "Architecture Q5",
    options: [
      { k: "a", t: "Procédure de l'éditeur, sur justificatif du vrai titulaire : la société usurpatrice passe en lecture seule (ses documents restent consultables et ne sont jamais modifiés), son SIREN et ses SIRET sont libérés ; un SIRET saisi par erreur peut aussi être libéré. Tout est journalisé. Cela déroge à « son SIRET ne sert jamais à un autre magasin »." },
      { k: "b", t: "Rien : le vrai titulaire doit s'arranger avec l'usurpateur." },
      { k: "c", t: "Libérer le SIREN seulement, jamais un SIRET." }
    ], reco: "a" },
  { id: "S8", g: "S", titre: "Qui ferme et qui rouvre un magasin",
    contexte: "Vous avez écrit que le propriétaire et les administrateurs habilités « peuvent gérer les magasins ».",
    source: "Architecture Q6 (i, iii)",
    options: [
      { k: "a", t: "Fermeture par le propriétaire ou un administrateur autorisé à gérer les magasins, mot de passe redemandé ; réouverture seulement par une procédure de l'éditeur." },
      { k: "b", t: "Fermeture par le propriétaire seul ; réouverture par l'éditeur." },
      { k: "c", t: "Fermeture et réouverture par la société elle-même." }
    ], reco: "a" },
  { id: "S9", g: "S", titre: "Fin de la caisse d'un magasin fermé",
    contexte: "Votre règle produit un ticket Z chaque jour du calendrier à partir de la première vente, sans fin prévue.",
    source: "Architecture Q6 (ii)",
    options: [
      { k: "a", t: "À la fermeture d'un magasin (et à la résiliation de la société) : clôtures journalière, mensuelle et de l'exercice à la date de fermeture, archive signée finale, puis plus aucun ticket Z pour ce magasin." },
      { k: "b", t: "Les tickets Z continuent chaque jour sans fin." }
    ], reco: "a" },
  { id: "S10", g: "S", titre: "Transfert d'un établissement à une autre société",
    contexte: "Une activité reprise par une autre société devient un nouveau compte (votre réponse) ; l'historique reste à la société d'origine. Reste à dire ce qui suit le magasin.",
    source: "Architecture Q7",
    options: [
      { k: "a", t: "Rien ne suit : la société qui reprend saisit ou importe son catalogue. Le stock du magasin fermé sort au coût moyen, journalisé, et le montant hors taxe à déclarer est remis au propriétaire ; la nouvelle société fait un inventaire d'ouverture." },
      { k: "b", t: "Le catalogue (produits, services, fournisseurs) suit, sur demande écrite des deux sociétés ; le stock comme en (a)." },
      { k: "c", t: "Le catalogue et les clients suivent (clients informés sous un mois, opposants retirés) ; le stock comme en (a)." }
    ], reco: "a" },
  { id: "S11", g: "S", titre: "SIREN corrigé après le premier document : factures déjà émises",
    contexte: "Votre règle dit déjà qu'une erreur dans un document se corrige par un avoir et une nouvelle facture. Aujourd'hui, un avoir remet les articles en stock et une facture ne peut citer ni une facture remplacée ni une date de livraison.",
    source: "Architecture Q8",
    options: [
      { k: "a", t: "Pour chaque facture à un professionnel : un avoir de correction sans mouvement de stock, puis une facture de remplacement qui cite la facture initiale et garde sa date de livraison. Le propriétaire les émet depuis la liste fournie par la procédure ; les tickets restent tels quels." },
      { k: "b", t: "La procédure émet elle-même l'avoir et la facture de remplacement." },
      { k: "c", t: "Aucune refacturation." }
    ], reco: "a" },
  { id: "S12", g: "S", titre: "Retirer le paquet spatie/laravel-permission",
    contexte: "Dans la nouvelle architecture, ce paquet ne range plus que les cases cochées des membres. Il impose un cache global dont le coût grandit avec le nombre de magasins : 79 secondes et 1,9 Go mesurés à 22 540 magasins. Changer une dépendance demande votre accord.",
    source: "Architecture Q9",
    options: [
      { k: "a", t: "Le retirer : les autorisations cochées d'un membre sont rangées avec son affectation au magasin et contrôlées contre la liste des autorisations ; même contrôle, sans le cache global." },
      { k: "b", t: "Le garder." }
    ], reco: "a" },
  { id: "S13", g: "S", titre: "Adresses IP et navigateurs du journal d'audit",
    contexte: "Votre règle « tout se conserve 10 ans » vise les documents et la caisse. La CNIL recommande de garder les journaux de connexion de 6 mois à 1 an (délibération 2021-122).",
    source: "Architecture Q10",
    options: [
      { k: "a", t: "Les actions du journal d'audit se gardent 10 ans ; l'IP et le navigateur sont rangés à part et effacés après 1 an." },
      { k: "b", t: "IP et navigateur effacés après 6 mois." },
      { k: "c", t: "Tout gardé 10 ans." }
    ], reco: "a" },
  { id: "S14", g: "S", titre: "Changement légal de la date de fin d'exercice",
    contexte: "Votre règle verrouille le mois de fin d'exercice après la première vente ; une société peut pourtant changer légalement la date de clôture de son exercice (décision d'assemblée).",
    source: "Architecture Q11",
    options: [
      { k: "a", t: "Procédure de l'éditeur sur justificatif (procès-verbal), jamais rétroactive (effet au prochain exercice), journalisée dans chaque magasin." },
      { k: "b", t: "Verrou définitif." }
    ], reco: "a" },

  { id: "V1", g: "V", titre: "Interprétations de vos réponses sur l'architecture",
    contexte: "Je les applique sauf si vous en contestez une ; donnez son numéro dans la note.",
    liste: [
      "Un compte appartient à une seule société (et non plus à un seul magasin) ; un membre peut être affecté à plusieurs magasins de cette société.",
      "Un administrateur habilité agit dans toute la société, avec les mêmes autorisations dans tous ses magasins, présents et futurs.",
      "Le « responsable » remplace l'ancien administrateur de magasin. Une alerte de stock va à toute personne autorisée dans le magasin ; les factures reçues relèvent de l'administrateur de société autorisé.",
      "L'établissement principal n'est qu'une indication, sans aucun effet.",
      "Une société suspendue ou résiliée se comporte comme un magasin suspendu ou fermé : lecture seule ; son équipe reste gérable.",
      "Aucun écran ne change le statut d'une société avant l'administration de l'éditeur (étape 13).",
      "Le journal d'audit est consultable par le propriétaire seul.",
      "Le propriétaire peut transmettre lui-même la propriété à un administrateur actif ; un transfert forcé par l'éditeur viendra à l'étape 13, avec justificatif et délai d'opposition de 7 jours.",
      "Une personne retirée d'une société peut rejoindre une autre société avec le même compte. Un magasin ne change jamais de société : une reprise passe par le transfert d'établissement.",
      "L'e-mail et le téléphone du magasin sont des réglages du magasin.",
      "Un changement de SIRET prend effet le jour même ; un déménagement qui change le territoire de TVA passe par un nouveau magasin.",
      "La double authentification des propriétaires et administrateurs est reportée à la mise en production : elle demandera un paquet, donc votre accord."
    ],
    source: "Architecture, interprétations",
    options: [
      { k: "a", t: "Je les confirme toutes." },
      { k: "b", t: "J'en conteste au moins une (numéro et précision dans la note)." }
    ], reco: "a" },
  { id: "V2", g: "V", titre: "Règles déjà appliquées",
    contexte: "Elles sont dans le code, testées et inscrites dans CLAUDE.md.",
    liste: [
      "PostgreSQL en production, SQLite pour le développement et les tests rapides ; la suite complète passe sur les deux, en local et en CI.",
      "Une adresse e-mail s'enregistre et se compare en minuscules : A@x.fr et a@x.fr sont la même adresse.",
      "Les recherches ignorent la casse et les accents : « creme » trouve « Crème ».",
      "Une référence produit est unique dans le magasin sans tenir compte de la casse : « ABC » et « abc » sont la même référence.",
      "Devis : un brouillon dont la validité est déjà dépassée ne s'envoie pas ; un devis envoyé échu est traité comme expiré dès l'échéance ; un devis repassé en brouillon perd sa date d'émission et son PDF l'indique."
    ],
    source: "Lot PostgreSQL · Lot 13",
    options: [
      { k: "a", t: "Je les confirme toutes." },
      { k: "b", t: "J'en conteste au moins une (numéro et précision dans la note)." }
    ], reco: "a" },

  { id: "T1", g: "T", titre: "Place de la campagne dans la feuille de route",
    contexte: "Vous avez demandé qu'elle vienne après les lots en cours. Les corrections qu'elle révélera retarderont l'intégration SUPER PDP d'une durée impossible à fixer d'avance.",
    source: "Stratégie, décision 1",
    options: [
      { k: "a", t: "Campagne initiale juste après l'architecture société et les lots de décisions 14 à 21 ; une relance après chaque grande étape (SUPER PDP, caisse sécurisée, comptabilité, RGPD, administration) ; une campagne finale avant la mise en production." },
      { k: "b", t: "Une seule campagne, juste avant la mise en production." }
    ], reco: "a" },
  { id: "T2", g: "T", prioritaire: true, titre: "Volume des données fictives",
    contexte: "Cette machine (4 processeurs, 15 Go de mémoire, environ 15 Go de disque libre) tient quelques centaines à quelques milliers de sociétés, selon la taille mesurée sous PostgreSQL. 10 000 sociétés complètes et des milliers d'utilisateurs simultanés exigent une machine dédiée, fournie par vous.",
    source: "Stratégie, décision 9",
    options: [
      { k: "a", t: "Campagne initiale sur cette machine au plus grand volume qu'elle tient, les résultats au-delà signalés comme extrapolés ; une machine dédiée avant la mise en production pour le volume complet." },
      { k: "b", t: "Cette machine seulement (le volume complet ne sera jamais vérifié)." },
      { k: "c", t: "Une machine dédiée dès la campagne initiale." }
    ], reco: "a" },
  { id: "T3", g: "T", titre: "Configuration testée",
    contexte: "Aujourd'hui, le cache, les sessions et les files d'attente sont rangés dans la base. Redis est prévu pour la production (étape 15).",
    source: "Stratégie, décision 6",
    options: [
      { k: "a", t: "Les deux : d'abord la configuration actuelle, puis Redis." },
      { k: "b", t: "La configuration actuelle seulement." }
    ], reco: "a" },
  { id: "T4", g: "T", titre: "Composition des équipes fictives",
    contexte: "Chaque société et chaque magasin fictifs reçoivent des comptes de chaque rôle.",
    source: "Stratégie, décision 7",
    options: [
      { k: "a", t: "Par société : 1 propriétaire et au moins 1 administrateur ; par magasin : au moins 1 responsable et 2 vendeurs." },
      { k: "b", t: "Au moins 2 comptes de chaque rôle partout." }
    ], reco: "a" },
  { id: "T5", g: "T", titre: "Gravité des anomalies",
    contexte: "Chaque anomalie trouvée est classée bloquante, majeure ou mineure, puis corrigée avec un test qui la reproduit.",
    source: "Stratégie, décision 13",
    options: [
      { k: "a", t: "Je classe et je corrige ; le rapport montre chaque classement et vous pouvez le contester. Vous validez seulement le déclassement d'une anomalie bloquante." },
      { k: "b", t: "Vous validez chaque classement bloquant ou majeur avant sa correction." }
    ], reco: "a" },
  { id: "T6", g: "T", titre: "Publication des preuves",
    contexte: "Journaux d'exécution, résultats des contrôles, temps de réponse et anomalies de chaque palier.",
    source: "Stratégie, décision 19",
    options: [
      { k: "a", t: "Publiés après chaque palier dans une page privée, avec leur empreinte enregistrée dans le dépôt." },
      { k: "b", t: "Seulement le rapport final." }
    ], reco: "a" },

  { id: "R1", g: "R", titre: "Vente au-delà du stock",
    contexte: "Aujourd'hui, une vente peut faire passer la quantité en stock sous zéro ; seule la valeur est bornée à 0 € (votre décision).",
    source: "Stratégie, règle 17 (a)",
    options: [
      { k: "a", t: "Vente permise avec un avertissement à l'écran et une alerte de stock ; la quantité négative reste visible jusqu'à un achat ou un inventaire qui la corrige." },
      { k: "b", t: "Vente refusée au-delà du stock." },
      { k: "c", t: "Vente refusée, sauf pour un membre qui a l'autorisation « vendre sans stock »." }
    ], reco: "a" },
  { id: "R2", g: "R", titre: "Remises et prix des lignes de service",
    contexte: "Le prix d'une ligne de produit vient de sa fiche et ne se modifie pas. En revanche, la remise (de 0 à 100 %) et le prix et le taux d'une ligne de service sont libres pour quiconque peut créer une facture.",
    source: "Stratégie, règle 17 (b)",
    options: [
      { k: "a", t: "Nouvelle autorisation « accorder des remises et fixer le prix d'un service » : cochée par défaut pour le propriétaire, les administrateurs et les responsables, pas pour les vendeurs." },
      { k: "b", t: "Remise plafonnée par membre : un pourcentage maximal coché par le propriétaire." },
      { k: "c", t: "Libre, comme aujourd'hui." }
    ], reco: "a" },
  { id: "R3", g: "R", titre: "Demande répétée après une réponse perdue",
    contexte: "Une émission, un paiement, un avoir ou un envoi est bien enregistré, mais la réponse se perd (coupure réseau) et l'utilisateur recommence.",
    source: "Stratégie, règle 17 (f)",
    options: [
      { k: "a", t: "Chaque action porte un jeton unique : la demande répétée renvoie le résultat déjà enregistré, sans doublon." },
      { k: "b", t: "L'utilisateur vérifie d'abord ce qui a été enregistré avant de recommencer (risque de doublon s'il ne le fait pas)." }
    ], reco: "a" },
  { id: "R4", g: "R", titre: "Répartition de la TVA d'un taux sur ses lignes",
    contexte: "Votre règle pour une vente à un professionnel : la TVA d'un taux est calculée sur le total HT du taux, arrondie une fois, puis répartie sur ses lignes. Aujourd'hui, chaque ligne reçoit, dans l'ordre des lignes, la TVA du cumul arrondi moins celle des lignes précédentes.",
    source: "Stratégie, règle 17 (g)",
    options: [
      { k: "a", t: "Garder cette répartition par cumul, dans l'ordre des lignes, et l'inscrire comme règle, testée au centime." },
      { k: "b", t: "Répartir au prorata du HT de chaque ligne, l'écart d'arrondi sur la ligne la plus élevée." }
    ], reco: "a" },
  { id: "R5", g: "R", titre: "Caisse personnelle restée ouverte",
    contexte: "Un vendeur absent ou retiré de l'équipe a laissé sa caisse personnelle ouverte (« Fermer ma caisse »).",
    source: "Stratégie, règle 17 (i)",
    options: [
      { k: "a", t: "La clôture du jour n'est jamais bloquée : elle ferme d'office les caisses personnelles encore ouvertes, avec la mention « fermée automatiquement » sur leur relevé. Un responsable peut aussi la fermer pour le vendeur." },
      { k: "b", t: "La clôture du jour reste bloquée tant qu'un responsable ne l'a pas fermée." }
    ], reco: "a" }
];
