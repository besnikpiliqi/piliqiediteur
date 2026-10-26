# Décisions de l'éditeur (page d'arbitrages, réponses du 9 octobre 2026)

## A. Comptes et équipe

- **A1 – Créer un membre avec l'adresse e-mail d'un compte qui existe déjà** (Audit C0, C1, C70, C129)
  - Choix : Refuser l'adresse : chaque compte n'appartient qu'à un seul magasin. (recommandation)
- **A2 – Magasin suspendu ou fermé** (Audit C132 · Caisse Q4)
  - Choix : Lecture seule : consultation et téléchargements possibles, plus aucune vente ; clôtures et déclarations continuent jusqu'au bout. (recommandation)

## B. Ventes, factures et paiements

- **B1 – Taux de TVA d'une ligne de produit à la vente** (Audit C15, C40)
  - Choix : Autre choix
  - Note de l'éditeur : « ca depend ou il vend car des fois il peux vendre à l'etranger ou par exemple hors de la france, et je crois que dans ces peut changer la tva, je te laisse confimer toi qu'est ce que c'est ces regles sur les sites francais »
- **B2 – Taux de TVA autorisés** (Audit X8)
  - Choix : Autre choix
  - Note de l'éditeur : « Garder la saisie libre mais par defaut 20% car le logiciel va etre utiliser en france, et si le logiciel sera utiliser ailleurs alors selon les regles du pay »
- **B3 – Paiement supérieur aux factures qu'il règle** (Audit C17)
  - Choix : Refuser : le montant reçu doit être égal au total imputé sur les factures. (recommandation)
- **B4 – Paiement saisi par erreur** (Audit X1 · Caisse Q7)
  - Choix : Contre-passation : un paiement d'annulation, lié à l'original et tracé, annule ses imputations ; on saisit ensuite le bon paiement. (recommandation)
- **B5 – Remboursement d'un retour** (Caisse Q7)
  - Choix : Seulement un crédit à valoir, comme aujourd'hui.
- **B6 – Crédit d'avoir d'un client qui a déjà une facture impayée** (Audit C23)
  - Choix : Appliquer automatiquement le crédit à ses factures impayées, de la plus ancienne à la plus récente. (recommandation)
- **B7 – Date d'échéance des factures** (Audit C20, C21, C87, C116)
  - Choix : Échéance = date d'émission + délai du client, sinon 30 jours (réglable dans les paramètres), recalculée au moment de l'émission. (recommandation)
- **B8 – Mode de paiement préféré du client** (Audit C87, C116)
  - Choix : Le présélectionner à l'encaissement (choisi dans la liste des modes de paiement). (recommandation)
- **B9 – Mentions obligatoires entre professionnels** (Audit C18 · SUPER PDP D14)
  - Choix : Pénalités : 3 fois le taux d'intérêt légal ; indemnité forfaitaire de 40 € ; « Pas d'escompte pour paiement anticipé ». Textes réglables par magasin. (recommandation)
- **B10 – Magasin en franchise en base de TVA** (Audit C19 · SUPER PDP D10)
  - Choix : Ventes à 0 % avec la mention « TVA non applicable, art. 293 B du CGI » ; le prix TTC des produits ne change pas, le client paie le même prix. (recommandation)
- **B11 – Identité légale du magasin incomplète** (Audit C12, C82, C102)
  - Choix : Bloquer l'émission d'une facture à un professionnel tant que ces mentions manquent ; les tickets restent possibles. (recommandation)
- **B12 – SIREN des clients professionnels** (Audit C84 · SUPER PDP D16)
  - Choix : Obligatoire pour un professionnel français, format contrôlé (9 ou 14 chiffres) ; n° de TVA ou autre identifiant pour un client étranger. (recommandation)
- **B13 – Client imprimé sur une facture déjà émise** (Audit C13, C30, C73)
  - Choix : Figer à l'émission le nom, l'adresse, les identifiants et le type du client : une facture émise ne change plus jamais. (recommandation)
- **B14 – Suppression d'un devis** (Audit C14)
  - Choix : Seul un devis brouillon se supprime ; un devis numéroté reste (refusé ou expiré). (recommandation)
- **B15 – Devis dont la date de validité est dépassée** (Audit C25, C125)
  - Choix : Passage automatique à « Expiré », plus d'acceptation possible ; modifier un devis envoyé le repasse en brouillon. (recommandation)
- **B16 – Confidentialité entre vendeurs** (Audit C8, C16, C36)
  - Choix : Un vendeur ne voit et n'encaisse que ses factures ; son tableau de bord montre « Mes ventes ». La permission « voir toutes les factures » donne accès au reste. (recommandation)
- **B17 – Chiffre d'affaires du tableau de bord** (Audit C24, C44)
  - Choix : Net des avoirs, en HT, avec le TTC à côté. (recommandation)
- **B18 – Relevé « Fermer ma caisse » du vendeur** (Audit C39)
  - Choix : Afficher séparément le TTC vendu, les encaissements par mode de paiement, le crédit d'avoir utilisé et le reste dû. (recommandation)
- **B19 – Supprimer un client ou un produit déjà utilisé** (Audit C61, C72, C92)
  - Choix : Il ne se supprime pas : il s'archive, n'est plus proposé, et reste lisible sur les anciens documents. Le client comptoir ne se supprime jamais. (recommandation)

## C. Achats et stock

- **C1 – Retour de marchandise au fournisseur** (Audit C60)
  - Choix : Sortir le retour au prix de la ligne d'achat retournée et recalculer le coût moyen. (recommandation)
- **C2 – Modifier une facture d'achat déjà validée (stock déjà entré)** (Audit C65)
  - Choix : Exige la permission « valider les factures d'achat ». (recommandation)
- **C3 – Achat manuel d'un produit reçu par facture électronique** (Audit C66)
  - Choix : Autre choix
  - Note de l'éditeur : « Il devient celui du dernier achat, même saisi à la main. la reconnaissace du fourniseur se fait par son siret, s'il se trouve deja le fournisseur dans la fiche fourniseur »
- **C4 – Brouillon de facture d'achat supprimé** (Audit C67)
  - Choix : Les nouveaux produits ne sont créés qu'à la validation de la facture. (recommandation)

## D. Caisse sécurisée (art. 286 du CGI)

- **D1 – Factures aux professionnels dans les clôtures de caisse** (Caisse Q2)
  - Choix : Oui : toutes les ventes et leurs règlements entrent dans les clôtures et les grands totaux ; l'e-reporting ne déclare que la part des particuliers. (recommandation)
- **D2 – Journée sans aucune vente** (Caisse Q3)
  - Choix : Autre choix
  - Note de l'éditeur : « il faut avoir une trace de tickte Z, donc meme zero, pour montrer que cette jour il n'avais de vente, comme meme pour la gestion dans le magasin pour regader les jounees avec ces ventes, comme ca il marque zero. mais pour envoyer à l'etat un ticket zero, ca il faut voir si il faut ou pas, donc meme s'il faut pas alors le ticket sert pour le magasin quand meme. »
- **D3 – Journée de caisse et clôture** (Audit C29, C31 · Caisse Q11)
  - Choix : Autre choix
  - Note de l'éditeur : « Créer un bouton pour clôture manuelle par le magasin en fin de journée. Si la clôture manuelle n'est pas faite, déclencher une clôture automatique à minuit. et la cloture doit etre par rapport aux permissions selon le rôle des employés »
- **D4 – Exercice comptable du magasin** (Caisse Q5)
  - Choix : Mois de fin d'exercice réglable (décembre par défaut), verrouillé après la première vente. (recommandation)
- **D5 – Durée de conservation des données de caisse et des factures électroniques** (Caisse Q6 · SUPER PDP D23)
  - Choix : 10 ans pour tout, sans aucune purge, même après la résiliation du magasin. (recommandation)
- **D6 – Résiliation d'un magasin** (Caisse Q4)
  - Choix : Clôture et archive signée finales, automatiques, à la résiliation. (recommandation)
- **D7 – Numéro de la clôture du 31 décembre** (Audit C43)
  - Choix : Numéroter selon l'année de la journée clôturée. (recommandation)
- **D8 – PDF d'une facture ou d'un ticket** (Caisse Q12)
  - Choix : Le PDF reproduit le document exactement comme émis (une réimpression porte la mention « Duplicata ») ; « Payé / Restant dû » reste visible sur la fiche. (recommandation)
- **D9 – Ventes abandonnées en caisse** (Caisse Q13)
  - Choix : Inscrire au journal des événements l'abandon d'une vente et chaque ligne retirée avant validation. (recommandation)
- **D10 – Postes de caisse** (Caisse Q16)
  - Choix : Une seule caisse par magasin pour l'instant. (recommandation)
- **D11 – Attestation de conformité de l'éditeur** (Caisse Q9)
  - Choix : Une attestation par magasin. (recommandation)
  - Note de l'éditeur : « mais si la meme entreprise utilise plusieurs magasin alors comment faire? faut il enregister chaque magasin mais alors sera toujours le meme siren? »
- **D12 – Signature du volet 2 par le magasin** (Caisse Q10)
  - Choix : Signature électronique dans l'application, ou à la main sur le PDF. (recommandation)
- **D13 – Extensions PHP de signature et d'archivage** (Caisse Q18)
  - Choix : Les déclarer dans composer.json. (recommandation)

## E. Facture électronique avec SUPER PDP

- **E1 – TVA des factures électroniques entre professionnels** (SUPER PDP D1)
  - Choix : Factures aux professionnels : HT de la ligne = quantité × prix TTC ÷ (1 + taux), arrondi une fois ; TVA = total HT × taux. Exemple : 833,33 € HT + 166,67 € = 1 000,00 €. Le total reste celui du prix affiché, à quelques centimes près sur une facture de nombreuses lignes. (recommandation)
- **E2 – Ordre de la feuille de route** (SUPER PDP D24)
  - Choix : Après les corrections : SUPER PDP (connexion et réception des factures d'abord), puis la caisse sécurisée et la suite de la feuille de route. (recommandation)
- **E3 – Factures d'achat reçues par la plateforme** (SUPER PDP D2 à D5)
  - Choix : Importées en brouillon ; l'entrée en stock reste une validation manuelle ; un produit inconnu est créé automatiquement ; les cas non gérés (quantités décimales, kg, litres, remises de pied de facture) passent en saisie manuelle. (recommandation)
- **E4 – Qui peut refuser ou contester une facture reçue** (SUPER PDP D6)
  - Choix : Le propriétaire et l'administrateur. (recommandation)
- **E5 – Statut « encaissée » des factures de services** (SUPER PDP D7)
  - Choix : Envoyé automatiquement à chaque paiement d'une facture de services, avec le montant et la date, sauf pour un magasin qui a opté pour les débits. (recommandation)
- **E6 – Paiements des particuliers pour des services** (SUPER PDP D8)
  - Choix : Les déclarer une fois la règle confirmée avec votre expert-comptable ; rien n'est envoyé d'ici là. (recommandation)
- **E7 – Clients publics (mairies…) et professionnels non assujettis** (SUPER PDP D25)
  - Choix : Ces factures restent hors plateforme, avec un message sur la facture. (recommandation)
- **E8 – Facture rejetée par la plateforme** (SUPER PDP D26)
  - Choix : Redéposer la facture corrigée sous le même numéro. (recommandation)
- **E9 – Crédit d'avoir dans le « montant déjà payé » de la facture électronique** (SUPER PDP D27)
  - Choix : Compté comme déjà payé, avec la note « dont X € imputés de l'avoir N ». (recommandation)
- **E10 – Connecter ou déconnecter le compte SUPER PDP du magasin** (SUPER PDP D28)
  - Choix : Le propriétaire du magasin seulement. (recommandation)
- **E11 – Plusieurs magasins de la même société (même SIREN)** (SUPER PDP D12)
  - Choix : Autorisé, avec une adresse de réception par établissement (SIRET).
- **E12 – Documents émis avant la connexion à SUPER PDP** (SUPER PDP D13)
  - Choix : Bouton « Transmettre » pour les factures émises avant la connexion ; pas de rattrapage des clôtures antérieures. (recommandation)
- **E13 – Ventes à 0 % de TVA (export, autoliquidation…)** (SUPER PDP D10)
  - Choix : Transmission refusée, avec un message clair, tant que le motif d'exonération n'est pas géré. (recommandation)
- **E14 – Déclaration des ventes aux particuliers (e-reporting)** (Audit C28, X5 · SUPER PDP D17)
  - Choix : Une déclaration par clôture journalière, suivie jusqu'à l'accusé de réception ; les journées manquées sont rattrapées. (recommandation)
- **E15 – Vérification des envois contre la spécification SUPER PDP dans les tests** (SUPER PDP D19)
  - Choix : Un petit vérificateur intégré aux tests, sans nouvelle dépendance. (recommandation)

## F. Outillage

- **F1 – Paquets installés mais jamais utilisés** (Audit X9, C94, C139)
  - Choix : Les retirer de composer.json. (recommandation)

## K. Troisième série : détails d'application

- **K1 – Retour au fournisseur qui ferait passer la valeur du stock sous zéro** (C1 · lot Achats)
  - Choix : Le valoriser à 0 € : un stock ne vaut jamais moins que rien. (recommandation)
- **K2 – Retour partiel sur une facture à un professionnel** (E1 · lot Ventes aux professionnels)
  - Choix : Chaque retour se recalcule comme la facture, le dernier soldant la ligne au centime : 1er article 33,33 € HT + 6,67 € = 40,00 €, 2e article 33,34 € + 6,66 € = 40,00 €. (recommandation)
- **K3 – Prix payé pour une vente exonérée (export, DOM, livraison intracommunautaire)** (I1 · lot Natures d'opération)
  - Choix : Le client paie le prix hors taxe : prix TTC du catalogue ÷ (1 + taux). Exemple : 1 000 articles à 1,00 € TTC à 20 % font 833,33 € pour un professionnel. (recommandation)
- **K4 – Vente après la clôture manuelle de la journée** (D3 · lot Journées de caisse)
  - Choix : La caisse refuse tout nouveau ticket jusqu'à minuit, avec le message « La journée du 09/10/2026 est clôturée : les ventes reprennent demain. » Chaque clôture couvre exactement sa journée. (recommandation)
  - Note de l'éditeur : « mais comme le message alert dit que si vous fermez alors vourer plus faires des ventes »
- **K5 – Qui change un réglage commun à plusieurs magasins d'une même société** (J2 · lot Réglages communs)
  - Choix : Le magasin principal le change ; les autres ne peuvent plus émettre de facture tant qu'ils ne se sont pas alignés.
- **K6 – Avoir sur un ticket anonyme (client comptoir)** (B5, B6 · lot Crédits d'avoir)
  - Choix : Le n° de l'avoir sert de bon d'avoir : à une prochaine vente au comptoir, la caisse propose « Utiliser un bon d'avoir » et impute son crédit restant. (recommandation)
- **K7 – Mention d'immatriculation (RCS, RM) sur les factures** (B11, H2 · lot Identité légale)
  - Choix : L'exiger, comme les autres mentions, avant toute facture à un professionnel.
- **K8 – Devis déjà accepté par le client** (B15 · lot Devis)
  - Choix : Modifiable comme un devis envoyé : il repasse en brouillon et perd son acceptation.
- **K9 – Crédit d'avoir disponible après une contre-passation** (B4, B6 · lot Paiements)
  - Choix : La contre-passation annule exactement l'erreur ; quand le bon paiement est saisi (ex. 10,00 € en espèces), le crédit solde le reste de la facture (90,00 €) et il reste 10,00 € de crédit. (recommandation)
- **K10 – Facture rejetée par la plateforme, redéposée sous le même numéro** (E8 · SUPER PDP)
  - Choix : Le redépôt ne corrige que les données de transmission ajoutées hors du document signé (adresse électronique du client, cadre de facturation) ; une erreur dans le document lui-même (montants, client, lignes) se corrige par un avoir et une nouvelle facture. (recommandation)

## I. Deuxième série : ventes hors de France

- **I1 – Natures d'opération proposées sur une facture** (Recherche TVA · B1)
  - Choix : D'abord : Vente en France, Export hors UE expédié par le magasin, Livraison vers un DOM, Livraison intracommunautaire à un professionnel, Prestation à un professionnel de l'UE (autoliquidation), Prestation à un professionnel hors UE. Ensuite : la vente à distance UE au-delà du seuil (OSS) et l'export enlevé par le client. (recommandation)
- **I2 – Vérification du n° de TVA d'un client professionnel de l'UE** (Recherche TVA)
  - Choix : Vérification automatique sur VIES (service de la Commission européenne), consultation datée conservée ; sans n° valide, la vente reste à la TVA française. (recommandation)
- **I3 – Détaxe des voyageurs résidant hors UE** (Recherche TVA)
  - Choix : Le ticket reste à la TVA française ; on y note le n° du bordereau de détaxe ; la TVA est régularisée seulement après le visa. (recommandation)
- **I4 – Preuves d'export et de livraison intracommunautaire** (Recherche TVA)
  - Choix : Enregistrer les références des preuves sur la facture et rappeler celles qui manquent. (recommandation)

## J. Deuxième série : plusieurs magasins d'une même société

- **J1 – Numérotation des factures dans une société à plusieurs magasins** (Recherche multi-établissements)
  - Choix : Chaque magasin a un code court, unique dans la société et définitif dès son premier document (ex. LYON1), inséré dans tous ses numéros : FAC-LYON1-2026-000001. (recommandation)
- **J2 – Réglages fiscaux communs à la société** (Recherche multi-établissements)
  - Choix : Contrôle bloquant : un magasin ne peut pas enregistrer un réglage différent de celui des autres magasins du même SIREN (le logiciel lit seulement ces réglages chez eux). (recommandation)
- **J3 – Connexion SUPER PDP de plusieurs magasins d'une même société** (Recherche multi-établissements · E11)
  - Choix : Une connexion par magasin, avec sa propre adresse de réception (SIRET). Le logiciel lit seulement les adresses de réception des autres magasins du même SIREN pour ranger chaque facture reçue au bon endroit, et une même facture ne peut jamais être importée dans deux magasins. (recommandation)
- **J4 – Facture reçue adressée à la société sans préciser le magasin** (Recherche multi-établissements)
  - Choix : Elle va au « magasin principal » que la société désigne (un seul par SIREN), qui est alerté. (recommandation)
- **J5 – Transfert de marchandises entre magasins de la même société** (Recherche multi-établissements)
  - Choix : Pas pour l'instant : chaque magasin reste séparé. (recommandation)
- **J6 – Retour ou paiement dans un autre magasin de la même société** (Recherche multi-établissements)
  - Choix : Refusé : un retour ou un paiement se fait dans le magasin qui a vendu. (recommandation)
- **J7 – SIRET d'un magasin supprimé** (Recherche multi-établissements)
  - Choix : Jamais réutilisable par un autre magasin. (recommandation)
- **J8 – Clôture Z à zéro : quels jours** (D2)
  - Choix : Chaque jour du calendrier à partir de la première vente, dimanches et jours de fermeture compris. (recommandation)

## H. Points soulevés par les corrections de l'audit

- **H1 – Nom et adresse e-mail de connexion d'un membre** (Correction du lot 1)
  - Choix : Le membre les change lui-même dans son profil, en donnant son mot de passe actuel. (recommandation)
- **H2 – Capital social sur les factures** (Correction du lot 6 · Audit C12)
  - Choix : Ajouter le capital social à l'identité du magasin et l'imprimer quand il est renseigné. (recommandation)
- **H3 – Licence du logiciel déclarée dans composer.json** (Correction du lot 7)
  - Choix : Licence propriétaire (« proprietary ») : le code reste à vous. (recommandation)
- **H4 – Outils de développement d'interface inutilisés (Vite, Tailwind CSS…)** (Correction du lot 7)
  - Choix : Les retirer maintenant ; les réinstaller seulement si un thème d'écran personnalisé est créé (étape 15). (recommandation)

## G. Envois par e-mail et relances (déjà livrés, à valider)

- **G1 – Facture en retard qui reçoit un paiement partiel** (Étape 4 · Audit X7)
  - Choix : Elle reste « En retard » tant qu'il reste un montant dû après l'échéance (appliqué). (recommandation)
- **G2 – Plafonds d'envoi d'e-mails** (Étape 4)
  - Choix : 60 e-mails par heure et par membre, 200 par heure et par magasin, une relance par jour et par facture (appliqué). (recommandation)
- **G3 – Rythme des relances automatiques** (Étape 4)
  - Choix : Toujours dans l'ordre, la n°1 d'abord, chacune pas avant son délai ; après un démarrage tardif, l'écart entre deux relances est respecté (appliqué). (recommandation)
- **G4 – Qui peut envoyer les documents par e-mail** (Étape 4)
  - Choix : Une autre répartition (précisez dans la note).
  - Note de l'éditeur : « apliquer quand le magasin creer son equipe et la il leur donnes des autorisation ce qu'il veut. donc tu ajoute tous les options des roles »
- **G5 – Destinataires des alertes de stock bas** (Étape 4)
  - Choix : Le propriétaire et l'administrateur, et tout membre à qui vous donnez la permission de gérer le stock (appliqué). (recommandation)

