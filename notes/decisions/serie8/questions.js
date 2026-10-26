const GROUPES = [
  { id: "C", titre: "Calculs et PDF des ventes", texte: "Q1-b sert dès la correction de l'écart d'un centime, qui commence maintenant ; les deux autres, à l'étape 5 (les PDF)." },
  { id: "A", titre: "L'administrateur ne vend pas", texte: "Nécessaires à l'étape 2. La règle nouvelle « un administrateur ne vend jamais » sera tenue partout : écrans, demandes fabriquées, base de données, et l'ancien propriétaire après une transmission de la propriété." },
  { id: "I", titre: "La connexion par identifiant", texte: "Nécessaires à l'étape 3. Votre choix « tous sauf le propriétaire » est appliqué ; ces questions règlent la façon de le faire sans ouvrir de faille. Conséquence sans question : un collaborateur d'un cabinet d'expertise comptable qui suit dix sociétés aura dix identifiants." },
  { id: "P", titre: "Paiements, caisse et clôtures", texte: "Étapes 6 et 7 : paiements en plusieurs modes, mode « Avoir », clôtures détaillées." },
  { id: "S", titre: "Produits, stock et achats", texte: "Étapes 10 à 12, et la pièce jointe des factures d'achat." },
  { id: "M", titre: "Fermer et céder un magasin", texte: "Deux cas que la relecture de la fermeture, de la réouverture et de la cession des magasins (terminées aujourd'hui) a laissés sans règle. Ils seront appliqués juste après la correction de l'arrondi." }
];

const DECISIONS = [
  { id: "Q1-b", g: "C", prioritaire: true, titre: "Le prix unitaire hors taxe imprimé sur une facture",
    contexte: "La loi exige le prix unitaire hors taxe sur chaque ligne d'une facture. Arrondi au centime, il ne retombe pas sur le total : 1 000 vis à 1,00 € TTC impriment « 1 000 × 0,83 € » pour un total de 833,33 € HT, soit 3,33 € d'écart que le lecteur ne comprend pas.",
    source: "Point 3 · étapes 1 et 5",
    options: [
      { k: "a", t: "Le prix exact, avec ses décimales utiles (0,833333 €, mais 10,00 € quand il tombe juste) : quantité × prix retombe sur le total à deux centimes près au plus, ce que demandent aussi les règles de la facture électronique." },
      { k: "b", t: "Le prix au centime, comme aujourd'hui, avec une note sous les lignes qui explique l'écart." }
    ], reco: "a" },
  { id: "Q5", g: "C", titre: "Les paiements reçus après l'émission, sur le PDF",
    contexte: "Votre règle dit que le PDF reproduit le document tel qu'émis. Aujourd'hui pourtant, il relit les paiements au moment de l'impression.",
    source: "Point 3 · étape 5",
    options: [
      { k: "a", t: "Le PDF imprime les règlements reçus à l'émission ; les paiements reçus ensuite restent sur la fiche, avec leur mode." },
      { k: "b", t: "Une réimpression ajoute, à part du document, une section datée « Situation des règlements au … »." }
    ], reco: "a" },
  { id: "P3", g: "C", titre: "Les colonnes d'un ticket",
    contexte: "Sur une facture, les colonnes deviennent : Référence · Désignation · Qté · PU HT · Remise (s'il y en a une) · Taux · Total HT, comme vous le demandez ; la loi impose le prix unitaire hors taxe sur une facture. Sur un ticket, le client paie le prix affiché TTC. Une vente sans TVA française imprime toujours les colonnes hors taxe.",
    source: "Point 3 · étape 5",
    options: [
      { k: "a", t: "Ticket en TTC : Référence · Désignation · Qté · PU TTC · Remise · Taux · Total TTC, puis le tableau de TVA." },
      { k: "b", t: "Ticket avec les mêmes colonnes hors taxe qu'une facture." }
    ], reco: "a" },

  { id: "Q3-a", g: "A", prioritaire: true, titre: "Les documents qu'un administrateur ne crée jamais",
    contexte: "Aujourd'hui, un administrateur reçoit par défaut les autorisations de vente : j'ai pu ouvrir la caisse avec un compte d'administrateur et émettre un ticket.",
    source: "Point 6 · étape 2",
    options: [
      { k: "1", t: "Tickets, factures et encaissements." },
      { k: "2", t: "En plus, les avoirs et les devis, y compris numéroter et envoyer un devis : un administrateur supervise et ne fait aucune opération commerciale avec un client." }
    ], reco: "2" },
  { id: "Q3-b", g: "A", titre: "Les gestes de gestion qu'il garde, s'il y est autorisé",
    contexte: "Contre-passer un paiement saisi par erreur ; envoyer ou relancer une facture ou un avoir déjà émis. Ce ne sont pas des ventes.",
    source: "Point 6 · étape 2",
    options: [
      { k: "a", t: "Il garde les deux." },
      { k: "b", t: "Il ne garde aucun des deux." }
    ], reco: "a" },
  { id: "Q3-c", g: "A", titre: "Le prix de référence d'une prestation au catalogue",
    contexte: "Un administrateur autorisé peut-il fixer le prix de référence d'une prestation au catalogue, sans rien vendre ?",
    source: "Point 6 · étape 2",
    options: [
      { k: "a", t: "Oui." },
      { k: "b", t: "Non." }
    ], reco: "a" },
  { id: "P7", g: "A", titre: "Une page des caisses de tous les magasins",
    contexte: "Le menu devient « Les caisses » pour qui voit les relevés de toute l'équipe (propriétaire, responsable, administrateur autorisé, expert-comptable), dans le magasin ouvert, avec les caisses encore ouvertes. L'administrateur n'a plus de caisse à lui.",
    source: "Point 7 · étape 2",
    options: [
      { k: "a", t: "Pas pour l'instant : la page de chaque magasin suffit." },
      { k: "b", t: "Oui : dans l'espace de la société, une page réunit les caisses de tous les magasins de la portée de la personne." }
    ], reco: "a" },

  { id: "Q4-a", g: "I", titre: "Le format de l'identifiant",
    contexte: "L'identifiant est donné par PILIQI, tiré au hasard, unique dans tout le logiciel et jamais réutilisé. À la création, une fiche d'accès (identifiant et mot de passe provisoire valable 72 heures) s'affiche une seule fois, à remettre en main propre.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "12 chiffres dont un chiffre de contrôle, affichés en trois groupes (« 4821 0937 5512 ») : faciles à taper et à dicter." },
      { k: "b", t: "10 lettres et chiffres." }
    ], reco: "a" },
  { id: "Q4-b", g: "I", titre: "L'adresse e-mail facultative d'un compte autre que le propriétaire",
    contexte: "Seul le propriétaire se connecte avec son adresse. Dans tous les cas, une transmission de la propriété enverra un lien à l'adresse du futur propriétaire et ne prendra effet que quand il le suivra : c'est la seule façon de s'assurer que l'adresse qui deviendra sa connexion est bien la sienne.",
    source: "Point 20 · étape 3",
    options: [
      { k: "1", t: "Un simple contact." },
      { k: "2", t: "Recevoir des notifications, une fois l'adresse confirmée par un lien." },
      { k: "3", t: "En plus, « Mot de passe oublié » par un lien quand l'adresse est confirmée : la personne retrouve son accès sans que son responsable connaisse son mot de passe." }
    ], reco: "3" },
  { id: "Q4-c", g: "I", titre: "Le refus d'un compte et le rattachement à une autre société",
    contexte: "Avec un compte par société, une personne qui change de société reçoit simplement un nouveau compte. Le refus d'un compte, le rattachement d'un compte retiré à une autre société et la création d'une société avec un compte retiré n'ont plus d'objet.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "Les supprimer." },
      { k: "b", t: "Les garder." }
    ], reco: "a" },
  { id: "Q4-d", g: "I", titre: "Le renouvellement de l'accès",
    contexte: "Celui qui renouvelle l'accès d'un vendeur connaît son mot de passe provisoire : il pourrait se connecter avant lui et vendre en son nom. Deux protections proposées pour tout compte : la personne présente tape elle-même son nouveau mot de passe, sans provisoire, et absente, elle reçoit un provisoire de 72 heures ; le propriétaire est prévenu de chaque renouvellement qu'il n'a pas fait lui-même, puis du premier mot de passe choisi ensuite.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "L'accès d'un responsable se renouvelle par le propriétaire seul (sinon un administrateur pourrait se connecter comme responsable et vendre), avec les deux protections pour tout compte." },
      { k: "b", t: "Aussi par un administrateur autorisé à nommer les responsables, avec les deux protections." }
    ], reco: "a" },
  { id: "Q4-e", g: "I", titre: "« Une caisse à la fois »",
    contexte: "Avec un compte par magasin, seul le propriétaire vend dans plusieurs magasins sous un même compte. Une même personne pourra avoir deux caisses ouvertes sous ses deux comptes, dans deux magasins.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "D'accord : la règle ne vaut plus que pour le compte du propriétaire." },
      { k: "b", t: "Garder la règle pour la personne, quel que soit le compte." }
    ], reco: "a" },
  { id: "Q4-f", g: "I", titre: "Les comptes d'un magasin cédé ou fermé définitivement",
    contexte: "Une fermeture temporaire garde les comptes du magasin dans tous les cas.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "Les retirer d'office." },
      { k: "b", t: "Les garder." }
    ], reco: "a" },
  { id: "Q4-g", g: "I", titre: "Des comptes d'entraînement dans le magasin de démonstration",
    contexte: "Ils seraient créés par le propriétaire ou un responsable, et retirés à la fermeture du magasin de démonstration.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "Oui." },
      { k: "b", t: "Non." }
    ], reco: "a" },
  { id: "Q4-h", g: "I", titre: "« Se souvenir de moi »",
    contexte: "Le propriétaire vend lui aussi à la caisse ; son compte, qui a tous les droits partout, resterait ouvert sur un poste partagé.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "Le retirer pour tous, propriétaire compris." },
      { k: "b", t: "Le retirer seulement pour les comptes de magasin." },
      { k: "c", t: "Le garder." }
    ], reco: "a" },
  { id: "Q4-i", g: "I", titre: "Les mots de passe",
    contexte: "Le contrôle par un service extérieur envoie à ce service une petite partie de l'empreinte du mot de passe, jamais le mot de passe lui-même : je ne l'ajoute qu'avec votre accord.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "Au moins 12 caractères, sans l'identifiant ni le nom." },
      { k: "b", t: "Au moins 12 caractères, sans l'identifiant ni le nom, et en plus vérifier auprès d'un service extérieur qu'un mot de passe n'a pas déjà été divulgué." },
      { k: "c", t: "Au moins 8 caractères." }
    ], reco: "a" },
  { id: "Q4-j", g: "I", prioritaire: true, titre: "Un compte de responsable créé par un administrateur",
    contexte: "Aujourd'hui, un administrateur autorisé qui crée un compte de responsable choisit son mot de passe provisoire : il peut s'y connecter et vendre. Je l'ai essayé : un administrateur sans aucune autorisation de vente a émis un ticket sous le compte qu'il venait de créer.",
    source: "Points 6, 17 et 20 · étape 3",
    options: [
      { k: "a", t: "L'administrateur crée le compte, mais seul le propriétaire en produit et remet la fiche d'accès ; le compte ne s'ouvre pas avant. L'administrateur garde le droit de créer des responsables, sans jamais connaître leur mot de passe." },
      { k: "b", t: "L'administrateur ne crée plus de compte de responsable : il nomme seulement responsable un vendeur ou un observateur du magasin qui a déjà son propre mot de passe." },
      { k: "c", t: "Accepter le risque, le propriétaire étant prévenu de chaque compte créé et de sa première connexion." }
    ], reco: "a" },
  { id: "Q4-k", g: "I", prioritaire: true, titre: "Les changements de rôle",
    contexte: "Un compte peut aujourd'hui changer de rôle (un vendeur devenu administrateur, un administrateur redevenu vendeur dans deux magasins), ce qui ne colle pas avec un identifiant rattaché pour toujours à un magasin ou à la société. Dans tous les cas, passer de vendeur à responsable dans le même magasin garde le même compte.",
    source: "Point 20 · étape 3",
    options: [
      { k: "a", t: "Un compte garde pour toujours son rôle (membre d'un magasin, administrateur ou expert-comptable) ; une personne qui change de rôle reçoit un nouveau compte et l'ancien est retiré ; seule exception, la transmission de la propriété." },
      { k: "b", t: "Le compte reste le même et reçoit un nouvel identifiant à chaque changement." }
    ], reco: "a" },

  { id: "Q6", g: "P", titre: "Un bon d'avoir sur la facture d'un client nommé",
    contexte: "Un bon d'avoir (avoir fait au client comptoir) se présente aujourd'hui à la caisse, pour la vente de tout client.",
    source: "Point 1 · étape 6",
    options: [
      { k: "a", t: "À la caisse seulement, comme aujourd'hui, et sur un ticket du client comptoir resté impayé, fidèle à votre règle « à une prochaine vente au comptoir » ; jamais sur une facture faite depuis « Factures »." },
      { k: "b", t: "Aussi sur une facture faite depuis « Factures » pour un client nommé." }
    ], reco: "a" },
  { id: "Q7", g: "P", titre: "Les remboursements",
    contexte: "Votre exigence 9 parle de remboursements ; votre règle dit « rien n'est jamais remboursé : un retour donne un crédit ».",
    source: "Points 15 et 24 · étapes 6 et 7",
    options: [
      { k: "a", t: "Garder la règle : la clôture montre les avoirs et le crédit utilisé, sans ligne de remboursement." },
      { k: "b", t: "Permettre de rembourser : une fonction nouvelle, dont vous me donnerez d'abord les règles (qui, jusqu'à quel montant, en quel mode, avec quelle trace)." }
    ], reco: "a" },
  { id: "Q8", g: "P", titre: "La baisse du prix d'une ligne avant validation",
    contexte: "Retirer une ligne avant de valider s'inscrit au journal des événements ; baisser le prix d'une prestation avant de valider, pour qui en a le droit, ne s'inscrit pas.",
    source: "Points 14 et 21 · étape 7",
    options: [
      { k: "a", t: "L'inscrire, comme une ligne retirée, avec le prix d'avant et d'après : elle fait disparaître une partie de la vente avant son enregistrement." },
      { k: "b", t: "Ne pas l'inscrire : seule l'autorisation de fixer les prix la limite." }
    ], reco: "a" },
  { id: "Q13", g: "P", titre: "Une prestation payée avec un crédit d'avoir compte-t-elle comme encaissée ?",
    contexte: "Pour une prestation de service, la TVA est due à l'encaissement (sauf option pour les débits). Payer avec un avoir ne fait entrer aucun argent, mais éteint la dette du client. Rien n'est envoyé à l'administration avant le raccordement à SUPER PDP.",
    source: "Point 1 · étape 2 de la feuille de route",
    options: [
      { k: "a", t: "La compter comme encaissée, à la date où le crédit est utilisé." },
      { k: "b", t: "Ne jamais la compter : seuls les paiements en espèces, carte, chèque, etc. comptent." },
      { k: "c", t: "Le faire trancher par votre expert-comptable avant le raccordement à SUPER PDP ; d'ici là, PILIQI montre ces montants à part et n'envoie rien." }
    ], reco: "c" },
  { id: "Q14", g: "P", titre: "D'autres moyens de paiement",
    contexte: "Vous demandez « espèces, carte bancaire, virement, chèque et autres moyens configurés ». PILIQI n'a aujourd'hui que cinq modes fixes. Les cartes et bons cadeaux restent exclus, comme vous l'avez décidé.",
    source: "Points 15, 23 et 24 · étapes 6 et 7",
    options: [
      { k: "a", t: "En plus des cinq modes, chaque magasin peut ajouter ses propres moyens (par exemple « Titre-restaurant », « Chèque-vacances »), qui ne rendent jamais la monnaie et comptent chacun à part dans la clôture ; PILIQI ne contrôle pas leurs règles propres." },
      { k: "b", t: "Une liste fixe un peu plus longue : titre-restaurant et chèque-vacances." },
      { k: "c", t: "Les cinq modes seulement." }
    ], reco: "a" },

  { id: "Q9", g: "S", titre: "Le coût et le bénéfice dans le tableau des produits",
    contexte: "Le grand tableau qui s'ouvre sur « Ajouter un produit » peut montrer le coût moyen et le bénéfice de chaque produit.",
    source: "Point 13 · étape 10",
    options: [
      { k: "a", t: "Seulement pour qui voit la valeur du stock (propriétaire, responsables, expert-comptable, et un vendeur si on le lui coche) : à la caisse, le client voit souvent l'écran." },
      { k: "b", t: "Pour toute personne qui ajoute des produits à une vente." }
    ], reco: "a" },
  { id: "Q10-a", g: "S", titre: "Retourner au fournisseur un produit déjà vendu",
    contexte: "Aujourd'hui, on ne retourne au fournisseur que ce qui est encore en stock.",
    source: "Point 19 · étape 11",
    options: [
      { k: "a", t: "Garder cette limite." },
      { k: "b", t: "Permettre le retour d'un produit déjà vendu, le stock pouvant passer en négatif jusqu'à sa régularisation." }
    ], reco: "a" },
  { id: "Q10-b", g: "S", titre: "Un avoir fournisseur sans retour de marchandise",
    contexte: "Par exemple une remise accordée après coup, ou une erreur de prix du fournisseur.",
    source: "Point 19 · étape 11",
    options: [
      { k: "a", t: "Pas pour l'instant." },
      { k: "b", t: "Oui, sa règle sur le coût des produits étant à faire confirmer par votre expert-comptable." }
    ], reco: "a" },
  { id: "Q11-a", g: "S", titre: "Jusqu'à quand saisir l'inventaire d'ouverture",
    contexte: "Un produit naît seulement d'une facture d'achat validée ou de l'inventaire d'ouverture : le stock déjà là au démarrage du magasin, saisi avec son coût, en brouillon puis validé.",
    source: "Point 18 · étape 12",
    options: [
      { k: "a", t: "Jusqu'à la première vente du magasin ; un produit oublié passe ensuite par une facture d'achat." },
      { k: "b", t: "À tout moment, pour un produit jamais reçu." },
      { k: "c", t: "À tout moment." }
    ], reco: "a" },
  { id: "Q11-b", g: "S", titre: "Qui saisit l'inventaire d'ouverture",
    contexte: "La validation crée les produits, fait entrer leur stock et s'inscrit au journal d'audit.",
    source: "Point 18 · étape 12",
    options: [
      { k: "a", t: "Une personne autorisée à valider les achats et à créer des produits." },
      { k: "b", t: "Une autorisation dédiée « saisir les inventaires d'ouverture »." }
    ], reco: "a" },
  { id: "Q12", g: "S", titre: "Une pièce jointe est-elle exigée pour valider un achat de marchandises ?",
    contexte: "La pièce jointe est une copie pour consultation ; l'original se garde 10 ans.",
    source: "Point 8",
    options: [
      { k: "a", t: "Non, mais un rappel « aucune pièce jointe » à la validation." },
      { k: "b", t: "Oui : aucune facture d'achat ne se valide sans sa pièce." }
    ], reco: "a" },
  { id: "P8", g: "S", titre: "Quand arrive la pièce jointe des factures d'achat",
    contexte: "Elle est déjà conçue en entier avec les « Charges du magasin » : PDF, Factur-X, photo ou XML, 10 Mo et 20 pièces au plus par facture, rangée dans son magasin, jamais supprimée. Je propose d'y ajouter les avoirs fournisseur.",
    source: "Point 8",
    options: [
      { k: "a", t: "Avec le moteur de TVA, comme vous l'avez déjà décidé pour les « Charges du magasin »." },
      { k: "b", t: "Plus tôt, juste après les avoirs fournisseur à la main (étape 11)." }
    ], reco: "a" },

  { id: "M1", g: "M", titre: "Céder un magasin déjà fermé",
    contexte: "Aujourd'hui, seul un magasin ouvert se cède. Exemple qui bloque : le propriétaire ferme un magasin, vend ensuite son fonds à une autre société, puis l'INSEE ferme l'établissement. Le magasin ne peut plus être rouvert (le répertoire le refuse) ni cédé : son stock ne sort jamais, et la valeur à rapprocher du prix de cession n'est jamais calculée.",
    source: "Fermeture et cession des magasins",
    options: [
      { k: "a", t: "Un magasin fermé, temporairement ou pour cessation, se cède sans être rouvert ni recontrôlé au répertoire Sirene, par le propriétaire seul (nom du magasin retapé, mot de passe redemandé) : le stock sort au coût moyen et sa valeur hors taxe vous est remise, comme pour un magasin ouvert." },
      { k: "b", t: "Seulement un magasin fermé temporairement ; un magasin fermé pour cessation ne se cède pas." },
      { k: "c", t: "Non : seul un magasin ouvert se cède ; un magasin fermé doit d'abord être rouvert." }
    ], reco: "a" },
  { id: "M2", g: "M", titre: "Rouvrir ou céder un magasin avant la validation de la société",
    contexte: "Avant la validation, un magasin se configure mais ne vend rien. Aujourd'hui, un magasin fermé avant la validation ne se rouvre qu'après elle, et un magasin ne se cède qu'une fois la société validée : avant, n'ayant jamais vendu, il se ferme pour cessation. Un magasin fermé par erreur reste donc bloqué jusqu'à la validation, sans pouvoir être recréé avec le même SIRET.",
    source: "Fermeture et cession des magasins",
    options: [
      { k: "a", t: "Avant la validation, un magasin fermé se rouvre, après le contrôle au répertoire Sirene ; il ne se cède toujours pas (il se ferme pour cessation)." },
      { k: "b", t: "Garder la règle actuelle : ni réouverture ni cession avant la validation." }
    ], reco: "a" }
];
