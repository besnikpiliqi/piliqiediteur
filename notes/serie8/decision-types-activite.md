# Décision du 24/10/2026 : le type d'activité choisi à l'inscription

Ce fichier fait foi. Il applique `reponses-types-activite.md` (réponses de l'éditeur du 24/10/2026 à Q-TA1 et Q-TA2), qui prime sur lui partout où ils différeraient.

Le 24/10/2026, l'éditeur a décidé qu'un menu déroulant fait choisir, à l'inscription, le type d'activité de la société, et que ce choix est définitif (règle 2 de `reponses-cabinets.md`). Le même jour, il a fixé les familles du menu et dit que le type ne change jamais (`reponses-types-activite.md`). Il a laissé à Claude les textes du menu et l'effet de chaque type sur le logiciel : ce fichier les fixe.

Il remplace, pour le type d'une société :
- dans CLAUDE.md, la phrase « une société s'inscrit avec son SIREN et l'adresse e-mail et le mot de passe de son propriétaire, rien de plus » ;
- dans `avenant-cabinets.md` : l'énumération `TypeSociete` (« entreprise » ou « cabinet »), la colonne `societes.type` et sa valeur par défaut, le bouton radio de l'inscription, le passage d'une entreprise au type cabinet (Q-EC2, option a), la garde `societes_type_connu_et_fixe` et la lecture du code d'activité par le lot EC ;
- dans `decision-espace-cabinets.md`, la règle 10 (« L'éditeur définit encore les types d'entreprises que PILIQI prend en charge ») : ces types sont fixés ici ;
- les propositions et les jugements qui ont préparé ce fichier.

Il s'applique après les lots V2 (vérification par courrier), V2b (réponses de `reponses-v2.md`, dont la clôture d'une inscription qui libère l'adresse de son propriétaire, point 3) et FA1 à FA3 (concurrence des brouillons et des fiches), et avant le lot EC, qui en reprend ce que dit la section « Lot TA ».

## Demande et réponses de l'éditeur (texte d'origine)

Demande (Q-EC2) : « Il faut lors de l'inscription afficher un menu déroulant pour choisir quel genre d'entreprise il a besoin. Donc par exemple : expert-comptable etc. ; donc il faut penser pour ceux qui font que le service au client et donc pas besoin de stock, et pour ceux qui ont besoin de stock comme les magasins de réparateur téléphonique ou d'autres. Donc c'est toi qui dois trouver quoi mettre dans le menu. Donc ils choisissent dans le menu et donc ne peuvent plus changer leur statut. »

Règle qui en découle (`reponses-cabinets.md`, règle 2) : le choix est définitif ; personne ne le change ensuite dans l'application ; une inscription faite avec un mauvais type se clôt faute de code ou par la procédure de l'éditeur, ce qui libère le SIREN ; le menu distingue au moins les cabinets d'expertise comptable, les activités de services sans stock et les activités avec stock.

Réponses du 24/10/2026 (`reponses-types-activite.md`) :
- sur le contenu du menu : « Pour l'instant on va garder que ceux qui ont besoin de stock et ceux qui font le service donc ceux qui le service n'ont pas besoin de stock. » ;
- sur le changement de type d'une société de services : « Non, jamais. »

Règles qui en découlent (`reponses-types-activite.md`, règles 1 à 3) : le menu ne garde, pour l'instant, que les activités avec stock, les activités de services sans stock et le cabinet d'expertise comptable ; les métiers du bâtiment s'inscrivent dans les services, sans règle propre ; un produit naît seulement d'une facture d'achat validée ou d'un inventaire d'ouverture ; le type d'une société ne change jamais, par aucun écran ni aucune procédure de l'éditeur. Sa règle 4 (assurance professionnelle facultative) est un choix de Claude, que l'éditeur peut contester.

## Règles retenues

1. **Principe.**
   - Le type ne fixe que ce qui change les menus et les contrôles du logiciel : vendre ou non des articles, être ou non un cabinet d'expertise comptable.
   - Le menu ne garde, pour l'instant, que trois entrées (règle 1 des réponses). Une autre entrée ne viendra que par une décision de l'éditeur.
   - Le type est définitif dès la création de la société. Il ne change jamais, par aucun écran ni aucune procédure de l'éditeur, d'aucun type vers aucun autre (règle 3 des réponses).
   - Ce qui peut commencer ou s'arrêter sans changer les menus reste un réglage, jamais un type : franchise en base, option pour les débits, ventes hors de France, achats d'occasion de l'étape 14.
   - Chaque entrée du menu change quelque chose de concret. Deux métiers qui mènent au même comportement partagent une entrée et figurent dans ses exemples. Le menu ne cite jamais la nomenclature d'activités (NAF).

2. **Le menu.** Le champ s'appelle « Votre activité ». Il n'a aucune valeur par défaut : « Choisissez votre activité ». Il a trois entrées, dans cet ordre. Chaque entrée montre son libellé et, en dessous, une ligne d'exemples. L'aide de l'entrée choisie s'affiche sous le menu.

   | Libellé | Exemples (ligne sous le libellé) | Aide de l'entrée | Profil (valeur) |
   |---|---|---|---|
   | Commerce ou atelier | Boutique (vêtements, cadeaux, bijoux), épicerie, cave, fleuriste, librairie, opticien, grossiste ; réparation de téléphones, d'ordinateurs, d'électroménager ou de vélos, garage, cordonnerie, horlogerie ; salon de coiffure ou institut qui vend des produits. | Vous vendez des articles achetés à vos fournisseurs et suivis en stock, avec ou sans votre main-d'œuvre et vos services. C'est le choix le plus complet : prenez-le si vous vendez ne serait-ce que quelques articles. Seules les marchandises que vous revendez s'enregistrent en facture d'achat ; vos matières premières, fournitures et frais, pas encore. La vente de ce que vous fabriquez ou préparez vous-même n'est pas prise en charge. | commerce (règle 3) |
   | Services, sans vente d'articles | Consultant, développeur, agence de communication, graphiste, photographe, architecte, géomètre, agent immobilier, formateur dont les formations sont soumises à la TVA ou en franchise, coach, nettoyage, taxi ou VTC, déménagement, location de matériel, auto-école, coiffure ou esthétique sans vente de produits ; plombier, chauffagiste, électricien, maçon, peintre, plaquiste, carreleur, menuisier poseur, couvreur, climaticien, entreprise de rénovation. | Vous facturez votre travail, votre temps ou une prestation, sans vendre d'articles : services, devis, factures, encaissements et caisse de comptoir ; ni articles, ni achats de marchandises, ni stock, ni inventaire. Les métiers du bâtiment s'inscrivent ici : vos travaux, fournitures comprises, se facturent en lignes de service (fourniture et pose). Si la loi exige une assurance professionnelle pour votre activité (la garantie décennale des travaux du bâtiment, par exemple), renseignez-la dans l'identité de votre société : PILIQI l'imprime sur vos devis et vos factures. Ce choix ne permet pas de vendre des articles. | services (règle 4) |
   | Cabinet d'expertise comptable | Société d'expertise comptable, expert-comptable exerçant seul, association de gestion et de comptabilité, inscrits au tableau de l'Ordre. | Réservé aux cabinets inscrits au tableau de l'Ordre des experts-comptables, dont le code d'activité est 69.20Z : un espace pour suivre vos clients, sans magasin ni vente. L'inscription à l'Ordre est vérifiée. | cabinet (règle 5) |

   Sous le menu, toujours : « Ce choix est définitif : il décide des menus de PILIQI (articles, achats et stock) et ne changera pas, même si votre activité change ensuite. Dans le doute, choisissez « Commerce ou atelier », le plus complet. PILIQI ne permet pas de facturer une activité exonérée de TVA (soins, enseignement, assurance, location de logements…). »

   La phrase de l'aide « Services » sur l'assurance professionnelle cite le bloc de la règle 7 : elle s'écrit avec lui (commit 3 du lot).

3. **Profil « commerce » (Commerce ou atelier).** C'est le logiciel d'aujourd'hui.
   - Articles achetés : produits nés d'une facture d'achat validée ou d'un inventaire d'ouverture, stock et grand livre, coût moyen, barème des prix de vente, avoirs fournisseur, fournisseurs, vente sans stock (R1), alertes de stock.
   - Un produit naît seulement d'une facture d'achat validée ou d'un inventaire d'ouverture, comme aujourd'hui (règle 2 des réponses). Ce que la société fabrique ou prépare elle-même ne se vend pas comme un article. L'aide de l'entrée et les avertissements des divisions 10 à 32 et 56 (règle 8) le disent.
   - Seules les marchandises revendues s'enregistrent en facture d'achat. Une facture d'achat crée des produits vendables, suivis en stock : des matières premières, des fournitures ou des frais y deviendraient des articles à vendre, au prix du barème, dont le stock ne baisserait jamais. Ils attendent les factures reçues de l'étape 2 et la comptabilité de l'étape 5. L'aide de l'entrée le dit.
   - Un même article acheté, vendu sur place et à emporter à deux taux, n'est pas pris en charge : une ligne de produit prend le taux de sa fiche, et le vendre comme un service laisserait son stock sans baisse. L'avertissement de la division 56 (règle 8) le dit.
   - Services au catalogue, caisse de comptoir et client comptoir, toutes les natures d'opération, toutes les autorisations du référentiel.
   - Pièces et main-d'œuvre sur un même document : une ligne vend un produit, une autre un service, comme aujourd'hui.
   - Ventes à un particulier : des tickets, comme aujourd'hui.

4. **Profil « services » (Services, sans vente d'articles) : une société sans articles.**
   - Elle n'a ni produits, ni stock, ni achats de marchandises, ni avoirs fournisseur, ni inventaire d'ouverture, ni barème des prix de vente, ni vente sans stock, ni alertes de stock, ni écran des fournisseurs.
   - Les autorisations de la famille « articles » (règle 6) n'existent pas dans ses magasins : ni proposées, ni précochées, ni lues ; la base refuse de les cocher.
   - La base refuse un produit, une facture d'achat, un avoir fournisseur ou un inventaire d'ouverture dans l'un de ses magasins.
   - Les fournisseurs n'ont ni écran ni autorisation, mais aucune garde en base ne les refuse : à l'étape 2, le fournisseur de toute facture reçue, frais compris, est reconnu par son SIRET, et l'étape 5 en aura besoin pour tous les types. Ces étapes décideront de leur place dans une société de services (famille de leurs autorisations, écran).
   - Écrans absents, et leur adresse répond 403 : le menu Achats (Factures d'achat, Avoirs fournisseur, Fournisseurs, Inventaire d'ouverture), « Produits », « Prix de vente », les aperçus de stock négatif et de valeur du stock.
   - Services au catalogue, avec leur prix de référence et l'autorisation « accorder des remises et fixer le prix d'un service ».
   - La caisse de comptoir et le client comptoir restent (coiffure, taxi, photographe, cours, dépannage).
   - La caisse, les devis et les factures n'ont que « Ajouter un service » : ni sélecteur de produits, ni douchette. La section des lignes de la caisse s'intitule « Services » au lieu de « Produits et services ». Le formulaire d'un service n'a pas la case « frais accessoires », qui suit des biens.
   - Natures d'opération : seulement celles qui ne portent pas sur des biens (vente en France, prestation à un professionnel de l'UE en autoliquidation, prestation à un professionnel hors UE), à l'écran comme à l'enregistrement d'un brouillon.
   - Devis, factures, avoirs, paiements, clôtures, caisse sécurisée et e-reporting : inchangés. La TVA des services reste exigible à l'encaissement, sauf option pour les débits.
   - Ventes à un particulier : des tickets, comme aujourd'hui.
   - Les métiers du bâtiment s'y inscrivent, sans règle propre : leurs travaux, fournitures comprises, se facturent en lignes de service, avec la caisse de comptoir, les natures de cette règle et l'assurance facultative de la règle 7, comme toute autre activité de services.
   - Ses achats et ses frais ne se saisissent pas dans PILIQI avant les étapes 2 et 5. À l'étape 2, une facture reçue n'y crée jamais de produit.
   - Le type ne change jamais (règle 1) : une société de services qui se met à vendre des articles ne les vend pas dans PILIQI.

5. **Profil « cabinet » (Cabinet d'expertise comptable).**
   - L'espace des cabinets du lot EC (`decision-espace-cabinets.md`, `reponses-cabinets.md`) : aucun magasin ni démonstration, panneau du cabinet, collaborateurs, liens avec les clients.
   - Code d'activité 69.20Z exigé avant tout courrier (règle 8) ; inscription à l'Ordre vérifiée ensuite (lot EC).
   - Le type cabinet ne s'obtient qu'à l'inscription. Une société ne devient jamais un cabinet, ni l'inverse : l'option a de Q-EC2 de l'avenant disparaît.
   - Le lot TA pose le type, l'entrée du menu, le contrôle 69.20Z et le refus de tout magasin. Le lot EC fait le reste. Jusqu'au lot EC, le propriétaire d'un cabinet arrive dans l'espace de la société. La création d'un magasin et la démonstration lui sont refusées, sa liste d'étapes n'a que « Saisir le code du courrier recommandé », et aucun texte de la vérification ne lui parle de magasins, d'achats ou de démonstration : état de la vérification au tableau de bord, page « Vérification », bandeau des panneaux, avis de validation, avis et e-mail de clôture.
   - Ces branches du cabinet dans l'espace de la société sont provisoires : le lot EC ferme cet espace au cabinet et les remplace par l'espace du cabinet (voir « Place dans la chaîne »).

6. **Autorisations par famille.**
   - Les services ont leurs propres autorisations : « Services : voir », « Services : créer », « Services : modifier » (`service.view`, `service.create`, `service.update`). `produit.view`, `produit.create` et `produit.update` ne valent plus que pour les produits.
   - Chaque autorisation de magasin est rangée, en toutes lettres, dans une seule famille. Le référentiel en base porte sa famille.
   - Famille « articles », 18 autorisations : `fournisseur.view`, `fournisseur.create`, `fournisseur.update`, `fournisseur.archiver`, `produit.view`, `produit.create`, `produit.update`, `produit.archiver`, `produit.ajuster_stock`, `produit.voir_valeur_stock`, `prix_vente.regler`, `stock.vendre_sans_stock`, `facture_achat.view`, `facture_achat.creer`, `facture_achat.valider`, `avoir_fournisseur.view`, `avoir_fournisseur.creer`, `facture.justifier`. Cette dernière y entre parce que les preuves d'export et de livraison et le bordereau de détaxe ne portent que sur des biens.
   - Famille « commune », 29 autorisations : toutes les autres, les trois des services comprises (47 en tout ; le référentiel en a 44 au HEAD `79216b1`).
   - Dans un magasin, les autorisations de tout compte sont croisées avec celles du type de la société de ce magasin : propriétaire, responsable, administrateur, vendeur, observateur, modèles des rôles, modèle de l'administrateur, cases proposées. Le croisement se fait partout où des autorisations s'écrivent, se lisent ou se précochent : création et modification d'un membre, changement de rôle, réintégration, création d'un administrateur, transmission de la propriété, cases cochables, écrans de l'équipe.
   - Le classement « vente » ou « hors vente » ne change pas : les trois autorisations des services sont hors vente, et `service.view` est une consultation. Les modèles des rôles reçoivent `service.*` partout où ils avaient `produit.*`.

7. **Assurance professionnelle** (règle 4 des réponses, choix de Claude que l'éditeur peut contester).
   - L'identité de la société reçoit un bloc facultatif, pour tous les types qui ont un magasin (commerce et services) : assureur ou garant (nom), adresse de l'assureur ou du garant, n° de contrat, couverture géographique.
   - Rien n'y est exigé, dans aucun type : ni pour numéroter un devis, ni pour émettre une vente. Seule exigence : un champ renseigné sans le nom de l'assureur ou du garant est refusé à l'enregistrement (« Indiquez l'assureur ou le garant. »), pour qu'aucune ligne incomplète ne s'imprime.
   - Le bloc n'apparaît pas pour un cabinet d'expertise comptable, qui n'a pas de magasin et n'émet aucun document de vente ; l'action qui modifie l'identité n'écrit pas ces champs pour lui.
   - Renseigné, il s'imprime dans le pied des devis et des factures, sous la ligne de l'identité de la société : « Assurance professionnelle : contrat n° {contrat}, {assureur}, {adresse}, couverture : {couverture} », sans les champs vides. Ce pied est commun à tous les PDF de vente : la ligne s'imprime donc aussi sur les tickets et les avoirs.
   - Il se fige dans l'émetteur à l'émission. Le contenu signé de l'émetteur des factures et des avoirs le reçoit : `ModuleFiscal::VERSION` passe à la version mineure suivante (1.2 → 1.3 au HEAD `79216b1`), puisque des champs s'ajoutent sans toucher à l'inaltérabilité, et le vérificateur d'intégrité suit. Un devis le fige avec le reste de son émetteur. L'émetteur du magasin de démonstration, fictif, n'en porte pas.
   - Il se modifie comme le reste de l'identité, par qui peut la modifier, et chaque changement s'inscrit au journal d'audit avec elle.
   - L'aide du bloc rappelle que la loi impose cette mention, sur chaque devis et chaque facture, aux artisans dont l'activité exige une assurance (bâtiment notamment). L'aide de l'entrée « Services » (règle 2) et l'avertissement des divisions 41 à 43 (règle 8) le rappellent aussi.
   - Références : loi n° 96-603 du 5 juillet 1996, art. 22-2 (l'assurance souscrite, les coordonnées de l'assureur ou du garant et la couverture géographique, sur chaque devis et chaque facture) ; code des assurances, art. L243-2 pour l'attestation de la décennale ; à revérifier au moment du lot.

8. **Code d'activité.**
   - Le code d'activité principale et sa nomenclature se lisent au répertoire Sirene (`activitePrincipaleUniteLegale` et `nomenclatureActivitePrincipaleUniteLegale`, dans la période en cours), là où l'inscription le consulte déjà : à l'envoi du formulaire et à la confirmation. Aucune lecture pendant la saisie.
   - Le code lu au formulaire est gardé dans la demande d'inscription, et s'efface avec elle. La page du lien l'y relit, sans appeler l'INSEE.
   - Le code lu à la confirmation s'inscrit, avec sa nomenclature et le type, dans l'entrée d'audit « Société inscrite ». Il n'est enregistré nulle part ailleurs sur la société ; le lot EC garde celui d'un cabinet dans sa propre table.
   - Bloquant pour le cabinet seul. Un code autre que 69.20Z en NAFRev2 refuse l'inscription comme cabinet, au formulaire si le répertoire répond, et toujours à la confirmation, avec ce motif : « Le code d'activité de cette entreprise au répertoire Sirene n'est pas 69.20Z (activités comptables) : elle ne peut pas s'inscrire comme cabinet d'expertise comptable. » Aucun courrier ne part et le SIREN reste libre. Un code absent ou d'une autre nomenclature ne refuse rien : l'éditeur contrôle alors l'activité avec l'Ordre (lot EC).
   - Pour tous les types, des avertissements jamais bloquants, en NAFRev2 seulement. Ils s'affichent sur la page du lien (et, au lot EC, sur la page d'acceptation d'une invitation, avec le texte propre à cette page pour la ligne 69.20Z, ci-dessous), chacun avec les types qu'il concerne :

     | Codes | Types concernés | Avertissement |
     |---|---|---|
     | Divisions 10 à 33, 45, 46, 47 et 56 ; groupes 95.1 et 95.2 | Services | « Votre code d'activité au répertoire Sirene est {code} : ces entreprises vendent en général des articles. Avec « {type} », vous ne pourrez pas en vendre. » |
     | Divisions 10 à 32 | Commerce ou atelier | « Votre code d'activité est {code}, une activité de fabrication : PILIQI ne prend pas en charge la vente de ce que vous fabriquez ou préparez vous-même. » |
     | Divisions 41 à 43 | Commerce ou atelier ; Services | « Votre code d'activité est {code}, une activité du bâtiment. Si la loi exige une assurance professionnelle pour votre activité (la garantie décennale, par exemple), renseignez-la dans l'identité de votre société : PILIQI l'imprime sur vos devis et vos factures. PILIQI ne prend pas encore en charge la sous-traitance où la TVA est due par le donneur d'ordre (autoliquidation), les factures d'acompte et de situation, ni la retenue de garantie. » |
     | 69.20Z | Tout type sauf cabinet | « Votre code d'activité est 69.20Z (activités comptables) : si vous êtes inscrit à l'Ordre des experts-comptables, choisissez « Cabinet d'expertise comptable ». » |
     | Divisions 64 à 66 et 85 à 88 | Tout type sauf cabinet | « Si votre activité est exonérée de TVA (soins, enseignement, assurance, services financiers…), PILIQI ne permet pas de la facturer. » |
     | 68.20A | Tout type sauf cabinet | « La location de logements est en général exonérée de TVA (art. 261 D du CGI) : PILIQI ne permet pas de la facturer. » |
     | 47.91A, 47.91B | Tout type sauf cabinet | « Au-delà de 10 000 € par an de ventes à distance à des particuliers d'autres pays de l'Union européenne, la TVA due est celle du pays de l'acheteur (art. 258 A du CGI) : PILIQI ne prend pas ces ventes en charge. » |
     | 47.21Z, 47.22Z, 47.23Z | Tout type sauf cabinet | « PILIQI ne vend pas encore au poids : les quantités sont des nombres entiers. » |
     | 47.26Z, 47.62Z | Tout type sauf cabinet | « Les ventes pour le compte de tiers (tabac, presse, jeux) ne sont pas prises en charge. » |
     | Division 55 | Tout type sauf cabinet | « La taxe de séjour n'est pas gérée. » |
     | Division 56 | Tout type sauf cabinet | « PILIQI ne prend pas en charge la vente de ce que vous préparez vous-même, ni celle d'un même article acheté à deux taux (sur place et à emporter), ni le titre-restaurant. » |

   - Sur la page d'acceptation d'une invitation (lot EC), le menu n'a pas « Cabinet d'expertise comptable » (règle 11). La ligne 69.20Z y a donc un autre texte : « Votre code d'activité est 69.20Z (activités comptables). Un cabinet d'expertise comptable ne s'inscrit pas par l'invitation d'un autre cabinet : si c'est votre cas, refusez cette invitation et inscrivez votre cabinet vous-même. » Les autres lignes ne changent pas. Le lot EC écrit ce texte dans `AvertissementsDuCodeDActivite`, avec la page qui le montre : TA ne l'écrit pas.
   - La phrase de l'avertissement des divisions 41 à 43 sur l'assurance cite le bloc de la règle 7 : elle s'écrit avec lui (commit 3 du lot).
   - Les références légales des lignes 68.20A et 47.91A, 47.91B sont à revérifier au moment du lot.
   - Aucun avertissement pour un code absent, inconnu ou d'une autre nomenclature : la NAF 2025 annoncée n'aura sa table qu'une fois ses codes fixés par l'éditeur.
   - Après l'inscription, le code ne joue plus aucun rôle pour le type. Seule la revérification mensuelle d'un cabinet le relit (lot EC).

9. **Choix définitif et page du lien.**
   - Le formulaire d'inscription exige le type parmi les trois entrées, contrôle le cabinet (règle 8) et range le type dans la demande.
   - La page du lien d'inscription devient un petit formulaire. Elle rappelle le SIREN et le type choisi. Elle montre le menu, encore modifiable sans nouvel e-mail, les avertissements du code (règle 8) et une case obligatoire : « J'ai compris que ce type d'activité ne pourra plus changer. » L'ouverture du lien ne consomme toujours rien.
   - Le bouton « Confirmer l'inscription » envoie le type final, validé contre les trois entrées ; un cabinet est recontrôlé 69.20Z. Il crée la société avec ce type et fait partir le courrier.
   - Le type est définitif dès la création de la société. Aucun écran ni aucune procédure ne le modifie, aucun formulaire ne remplit la colonne, et la base refuse un type inconnu et toute modification.

10. **Erreur de type.**
    - Avant la création de la société : la page du lien (règle 9).
    - Société créée, code pas encore saisi : personne ne change le type. Deux issues, selon la règle 2 de `reponses-cabinets.md` :
      - la clôture faute de code, au bout de 30 jours ;
      - tout de suite, la procédure de l'éditeur `editeur:societe:clore {societe} --motif=type_errone`, avec le nouveau motif « Type d'activité choisi par erreur, à la demande du propriétaire ».
    - La commande `editeur:societe:refuser` devient `editeur:societe:clore --motif=refus|type_errone`. Les deux motifs ont la suite de la clôture telle que les lots V2b et FA1 la laissent : pièces effacées, codes annulés, démonstration fermée, propriétaire prévenu, adresse de connexion du propriétaire retirée. Le compte du propriétaire reste dans l'inscription close et ne s'ouvre plus qu'avec son identifiant ; l'inscription close et ses données restent en lecture seule (`reponses-v2.md`, point 3). TA ne touche ni aux comptes, ni à l'index des adresses de connexion, ni à `FournisseurDesComptes`.
    - Pour un type erroné, l'avis au propriétaire dit qu'il peut inscrire de nouveau son entreprise avec le bon type, avec la même adresse et le même SIREN, tous deux libérés par la clôture. La nouvelle inscription crée une autre société et un autre compte, avec un autre identifiant ; rien ne passe de l'inscription close à la nouvelle. Un nouveau courrier partira, payé par l'éditeur.
    - Société validée : personne ne change le type, par aucun écran ni aucune procédure de l'éditeur (règle 1). Aucun code ne prévoit de changement de type.

11. **Client invité par un cabinet (lot EC).** Il choisit son type sur la page d'acceptation de l'invitation : le même menu sans « Cabinet d'expertise comptable » (donc « Commerce ou atelier » ou « Services, sans vente d'articles »), les mêmes aides, les avertissements du code lu au répertoire au moment de l'acceptation (la ligne 69.20Z avec son texte propre à cette page, règle 8), la même case. La société naît validée : cette page est sa seule barrière. Ensuite, la règle 10 vaut pour elle comme pour toute société validée.

12. **Rappels du type.**
    - E-mail du lien d'inscription : « Type d'activité choisi : {type}. Vous pourrez encore le changer en confirmant votre inscription ; il sera ensuite définitif. »
    - Courrier recommandé de l'inscription, une ligne : « Type d'activité choisi à l'inscription : {type}. Il ne pourra plus changer. S'il ne convient pas, ne saisissez pas ce code et contactez l'éditeur : l'inscription sera close et votre entreprise pourra s'inscrire de nouveau avec le bon type. » La page publique du code ne montre toujours rien avant la saisie.
    - Page « Vérification » de la société, tant que le code n'est pas saisi : « Votre société est inscrite comme « {type} ». Si ce type est faux, ne saisissez pas le code : contactez l'éditeur, qui clôt l'inscription et libère votre SIREN. »
    - Page « Identité » de la société : le type, en lecture seule.
    - Le mot « magasin » reste celui de tous les écrans, quel que soit le type, jusqu'à la finition des écrans de l'étape 15.

13. **Magasin de démonstration de chaque profil.** Tous les prix sont TTC, au taux par défaut du territoire du magasin. Le reste de la démonstration ne change pas : client comptoir, registre et séries à lui, filigrane, JET 100 et 105, aucun e-mail ni transmission, comptes d'entraînement.
    - Commerce : le catalogue d'aujourd'hui (Grossiste de démonstration, facture d'achat validée qui crée Carnet A5, Stylo bille bleu et Tasse en céramique avec leur stock, services « Emballage cadeau » et « Livraison à domicile », une cliente particulière et une entreprise fictives). S'y ajoute un service « Main-d'œuvre de réparation (30 min) » à 25,00 €.
    - Services : aucun fournisseur, aucun achat, aucun produit. Services à prix de référence : « Heure de conseil » 72,00 €, « Forfait déplacement » 30,00 €, « Atelier d'une demi-journée » 450,00 €. Une cliente particulière et une entreprise fictives.
    - Cabinet : aucune démonstration.

14. **Isolation.**
    - Le type se lit seulement pour la société courante de l'espace d'administration, ou pour la société du magasin courant (magasin, puis sa société). Les gardes en base joignent le magasin à sa société.
    - Le code d'activité ne lit que les données publiques du SIREN de la demande ou de l'invitation.
    - Aucune lecture nouvelle hors du magasin ou de la société courants : la liste de la section « Produit » de CLAUDE.md ne change pas.

15. **Ce qui ne change pas**, pour tous les types qui ont un magasin :
    - le calcul des ventes depuis les prix TTC et des achats depuis les prix HT ;
    - les factures et leurs mentions légales, le PDF, les documents émis qui ne changent plus ;
    - les ventes à un particulier, qui restent des tickets ;
    - la caisse de comptoir et le client comptoir ;
    - la caisse sécurisée, les clôtures du jour (un ticket Z chaque jour dès la première vente), du mois et de l'exercice, l'e-reporting, la facture électronique ;
    - l'archivage et les durées de conservation ;
    - les jetons des demandes répétées ;
    - les comptes, les rôles et le propriétaire qui ne vend jamais ;
    - le SIREN unique et le courrier recommandé ;
    - les services, leur prix de référence et l'autorisation « accorder des remises et fixer le prix d'un service » ;
    - l'exigibilité de la TVA des services à l'encaissement, sauf option pour les débits ;
    - le refus des ventes exonérées par nature (santé, enseignement) ;
    - les procédures de l'éditeur existantes, sauf le renommage de la règle 10.

## Phrases de CLAUDE.md à réécrire

Liste non limitative : tout le fichier doit rester cohérent. Chaque phrase porte le commit du lot qui l'écrit.

1. **Nouvelle puce de « Décisions de l'éditeur »**, juste après « Vérification de la société » (la puce « Cabinets d'expertise comptable » du lot EC viendra après elle). Le commit 1 l'écrit sans la phrase « Les autorisations de magasin se rangent … inventaires d'ouverture. », que le commit 2 ajoute. Elle ne dit rien de l'espace des cabinets ni de l'invitation d'un client par un cabinet, que seul le lot EC rend vrais : le lot EC y ajoute le renvoi vers sa puce « Cabinets d'expertise comptable » et la phrase du client invité (section 1.2 de `avenant-cabinets.md`).

   > - Type d'activité : à l'inscription, un menu sans valeur par défaut fait choisir le type d'activité de la société, dans cet ordre : « Commerce ou atelier » (articles achetés et suivis en stock, services, caisse de comptoir : tout PILIQI, le choix conseillé en cas de doute) ; « Services, sans vente d'articles » (services seulement, avec la caisse de comptoir, sans articles, achats, stock ni inventaire ; les métiers du bâtiment s'y inscrivent, sans règle propre) ; « Cabinet d'expertise comptable » (sans magasin ni démonstration, code d'activité 69.20Z contrôlé avant tout courrier). Le choix se confirme sur la page du lien d'inscription, où il peut encore changer et où une case dit qu'il sera définitif ; il le devient à la création de la société : aucun écran ni aucune procédure de l'éditeur ne le change, d'aucun type vers aucun autre, et la base refuse toute modification. Une inscription faite avec un mauvais type se clôt faute de code ou par la procédure de l'éditeur ; comme toute clôture d'une inscription, cela libère son SIREN et l'adresse de connexion de son propriétaire, dont le compte reste dans l'inscription close : la même personne s'inscrit de nouveau avec le bon type, sous un autre compte. Le code d'activité lu au répertoire Sirene ne bloque que le cabinet ; pour les autres types, il n'affiche que des avertissements (type peu probable pour cette activité, activité exonérée de TVA que PILIQI ne facture pas, limites connues) et ne joue plus aucun rôle après l'inscription. Les autorisations de magasin se rangent en deux familles, « articles » et « commune », portées par le référentiel en base, et les services ont les leurs ; une société de services n'a ni produits, ni stock, ni achats de marchandises, ni avoirs fournisseur, ni inventaire d'ouverture, ni barème, ni vente sans stock, ni écran des fournisseurs, ni natures d'opération qui portent sur des biens : les autorisations de la famille « articles » n'existent pas dans ses magasins, et la base refuse ses produits, factures d'achat, avoirs fournisseur et inventaires d'ouverture. Factures et mentions, caisse sécurisée, clôtures, e-reporting et archivage valent pour tous les types qui ont un magasin. Le type ne se lit que pour la société courante ou celle du magasin courant ; le mot « magasin » reste celui de tous les écrans.

2. **Vérification de la société.**
   - Commit 1 : « une société s'inscrit avec son SIREN et l'adresse e-mail et le mot de passe de son propriétaire, rien de plus » devient « une société s'inscrit avec son SIREN, son type d'activité et l'adresse e-mail et le mot de passe de son propriétaire, rien de plus ».
   - Commit 1 : dans la phrase de la clôture que le lot V2b écrit (« sans code au bout de 30 jours, ou au refus de l'éditeur, l'inscription est close, le SIREN libéré, et l'adresse de son propriétaire cesse d'être une adresse de connexion … »), « ou au refus de l'éditeur, » devient « ou par la procédure de l'éditeur, pour un refus ou pour un type d'activité choisi par erreur, à la demande de son propriétaire, ». Si V2b ou sa relecture l'ont écrite autrement, TA garde leur phrase et y ajoute seulement ce motif.
   - Commit 1 : dans la phrase « Avant la validation, aucune opération commerciale réelle (…) », « il se ferme à la validation et rien n'en passe aux magasins réels. » devient « il se ferme à la validation et rien n'en passe aux magasins réels ; un cabinet d'expertise comptable, qui n'a ni magasin ni démonstration, n'y configure que son identité. » Le lot EC y ajoute ses collaborateurs.
   - Commit 2 : « la société se configure (identité, magasins, équipe, catalogue, achats) » devient « la société se configure (identité, magasins, équipe, catalogue et, si elle a des articles, achats) ».

3. **Comptes et équipe** (commit 1). « sur une liste d'étapes (saisir le code du courrier recommandé, créer le premier magasin, ajouter l'équipe, essayer le magasin de démonstration) » devient « sur une liste d'étapes (saisir le code du courrier recommandé, créer le premier magasin, ajouter l'équipe, essayer le magasin de démonstration ; un cabinet d'expertise comptable n'a que la première) ». Le lot EC complète la liste du cabinet.

4. **Documents de vente** (commit 3).
   - Après « pas d'escompte. », ajouter : « L'assurance professionnelle de la société (assureur ou garant avec son adresse, n° de contrat, couverture géographique) est facultative dans tous les types qui ont un magasin ; quand elle est renseignée, elle s'imprime dans le pied des devis et des factures, tickets et avoirs compris, et se fige à l'émission. »
   - « l'identité de la société et toutes les mentions (catégorie de l'opération, … conditions de paiement) » devient « l'identité de la société, son assurance professionnelle quand elle est renseignée et toutes les mentions (catégorie de l'opération, … conditions de paiement) ».

5. **Ventes hors de France** (commit 2). En tête de la puce, ajouter : « Dans une société de services, qui n'a pas d'articles, seules les natures qui ne portent pas sur des biens sont proposées et acceptées, et ni preuve d'export ou de livraison ni bordereau de détaxe n'y existe. »

6. **Achats et stock** (commit 2). « un produit naît seulement de la validation d'une facture d'achat ou d'un inventaire d'ouverture (stock existant au démarrage d'un magasin, avec son coût unitaire HT), qui exige aussi le droit de créer des produits ; aucun écran ni aucun autre code ne crée un produit autrement. » devient « seule une société « Commerce ou atelier » a des produits ; un produit y naît seulement de la validation d'une facture d'achat ou d'un inventaire d'ouverture (stock existant au démarrage d'un magasin, avec son coût unitaire HT), qui exige aussi le droit de créer des produits ; aucun écran ni aucun autre code ne crée un produit autrement. Seules les marchandises revendues s'enregistrent en facture d'achat ; matières premières, fournitures et frais attendent les factures reçues de l'étape 2 et la comptabilité de l'étape 5. »

7. **Facture électronique (SUPER PDP)** (commit 2). « Les factures reçues s'importent en brouillon, l'entrée en stock reste une validation manuelle, un produit inconnu est créé, » devient « Seule une facture reçue de marchandises, dans une société qui a des articles, s'importe en brouillon de facture d'achat : l'entrée en stock reste une validation manuelle, un produit inconnu est créé, » ; à la fin de la même phrase, ajouter : « ; une facture reçue de frais (loyer, énergie, honoraires…), et toute facture reçue par une société de services, ne crée jamais de produit ni d'entrée en stock : elle reste une facture reçue, à consulter, refuser ou contester, puis à comptabiliser à l'étape 5 ».

8. **Produit** (commit 1). « pour leurs magasins (établissements, un SIRET chacun) : » devient « pour leurs magasins (établissements, un SIRET chacun), chacune avec le type d'activité choisi à son inscription : ».

9. **Feuille de route.**
   - Étape 1 (commit 1) : dans la parenthèse de l'architecture, après « inscription par lien, », ajouter « type d'activité choisi à l'inscription, définitif, ».
   - Étape 2 (commit 2) : après « attribuées automatiquement au bon magasin, d'abord, », ajouter « (une facture de frais, ou reçue par une société de services, ne devient jamais un achat de marchandises ; l'étape décide de la place des fournisseurs, reconnus par leur SIRET, dans une société de services) ».
   - Étape 7 (commit 2) : « Stock : » devient « Stock, pour les sociétés qui ont des articles : ».
   - Étape 13 (commit 1) : « (refus d'une société, » devient « (clôture d'une inscription, pour refus ou type d'activité choisi par erreur, ».
   - Étape 14 (commit 2) : « Achats auprès de particuliers » devient « Achats auprès de particuliers, pour les sociétés qui ont des articles ».

10. **Société et établissements** (commit 1). « le magasin de démonstration lui reste disponible dans les mêmes conditions » devient « le magasin de démonstration lui reste disponible dans les mêmes conditions, sauf à un cabinet d'expertise comptable, qui n'a aucun magasin ».

## Lot TA

### Place dans la chaîne

- Après le lot V2b (réponses de `reponses-v2.md`, dont la clôture d'une inscription qui garde le compte de son propriétaire et libère son adresse) et les lots FA1 à FA3, avec leurs relectures, qui touchent la clôture, la connexion, les brouillons, l'inventaire d'ouverture, les achats et les fiches. Relis le code tel qu'ils l'ont laissé avant de modifier, en particulier `CloreLInscriptionAction` et la commande de refus, que FA1 peut avoir verrouillées autrement (magasins réels après la société) : le renommage du commit 1 garde leur ordre des verrous.
- Avant le lot EC, qui reprend ce que dit « Ce que le lot EC reprend ». Puis L1 (indépendant), A7, le lot 21.
- Le lot ne crée rien que le lot EC ou les étapes 2 et 5 devraient défaire, sauf une exception voulue : les branches provisoires du cabinet dans l'espace de la société (règle 5). Ce sont la liste d'étapes réduite au code et le texte de l'état de la vérification dans `TableauDeBord`, et le texte du cabinet dans la page `VerificationSociete`. Sans elles, le propriétaire d'un cabinet lirait jusqu'au lot EC des étapes et des textes qu'il ne peut pas suivre. Le lot EC ferme l'espace de la société au cabinet et retire ces branches, que l'espace du cabinet remplace. Les autres textes du cabinet de la règle 5 (bandeau des panneaux, avis de validation, avis et e-mail de clôture) restent : le lot EC les garde.
- Pas de nouvelle dépendance ; jamais `composer update`.
- Chaque table changée l'est dans sa migration de création, puis `php artisan migrate:fresh`. `RetourArriereDesMigrationsTest` reste vert : chaque `down()` retire ses gardes avant sa table.
- Les gardes en base suivent le modèle de `GardesDesDroits` : une fonction plpgsql et un déclencheur BEFORE par garde sous PostgreSQL, `RAISE(ABORT)` sous SQLite, échec de la migration pour tout autre pilote. Elles vivent dans une nouvelle classe `App\Domain\Tenancy\Support\GardesDesTypesDActivite`, posées par la migration de création de chaque table concernée.
- Trois commits, chacun vert sur les deux moteurs. Chacun réécrit les phrases de CLAUDE.md qu'il rend vraies (section précédente).

### Commit 1 — « Lot TA (1/3) : type d'activité choisi à l'inscription, définitif »

Schéma :
- `societes` (`0001_01_00_000000`) : `type_activite` string(20), non nulle, sans défaut. Le commentaire renvoie à `TypeDActivite` et à la garde.
- Gardes :

  | Garde | Table, moment | Ce qu'elle refuse |
  |---|---|---|
  | `societes_type_d_activite_connu_et_fixe` | `societes`, BEFORE INSERT OR UPDATE OF type_activite | une valeur hors de `commerce`, `services`, `cabinet` ; toute modification |
  | `magasins_jamais_dans_un_cabinet` | `magasins`, BEFORE INSERT OR UPDATE OF societe_id | un magasin, démonstration comprise, dans une société de type cabinet |

Domaine :
- `app/Domain/Tenancy/Enums/TypeDActivite.php`, nouveau, `HasLabel` : `Commerce = 'commerce'`, `Services = 'services'`, `Cabinet = 'cabinet'`.
  - Textes : `getLabel()`, `aide()`, `exemples()` (règle 2 ; la phrase de l'assurance de l'aide « Services » vient au commit 3).
  - Capacités : `aDesMagasins()` (commit 1), `aDesArticles()` (commit 2). Aucune n'est écrite avant son premier usage.
- `app/Domain/Tenancy/Support/AvertissementsDuCodeDActivite.php`, nouveau : le seul endroit des tables de codes et des textes de la règle 8 (la phrase de l'assurance de l'avertissement des divisions 41 à 43 vient au commit 3). `pour(?string $code, ?string $nomenclature): list<array{texte: string, types: list<TypeDActivite>}>`.
- `Societe` : cast `type_activite`, `estUnCabinet()`. `type_activite` n'est jamais dans `$fillable` : seule la création l'écrit (`forceFill`).
- Répertoire Sirene, ce que le lot EC prévoyait à son commit 1, fait une seule fois ici :
  - `UniteSirene` reçoit `?string $activitePrincipale` et `?string $nomenclatureActivite` ;
  - `InseeSirene::unite()` les lit dans la période en cours ; un champ absent donne `null` sans rendre la réponse illisible ;
  - `FauxRepertoireSirene` donne `47.11B`/`NAFRev2` par défaut, ajoute la constante `SIREN_CABINET` (SIREN fictif à clé valide, non utilisé ailleurs, qui rend `69.20Z`/`NAFRev2`) et le réglage `exercer(string $siren, ?string $code, ?string $nomenclature = 'NAFRev2')` ;
  - `ControleSirene` ajoute `CODE_ACTIVITES_COMPTABLES = '69.20Z'`, `MOTIF_ACTIVITE_NON_COMPTABLE` (texte de la règle 8) et `exerceLesActivitesComptables(UniteSirene): ?bool` (`true` pour 69.20Z en NAFRev2, `false` pour un autre code en NAFRev2, `null` sinon).
- `InscriptionSociete` (`app/Filament/App/Pages/Auth`) :
  - un `Select` `type_activite`, obligatoire, sans défaut, placé entre le SIREN et l'adresse, options en HTML (libellé et ligne d'exemples), aide de l'entrée choisie sous le champ ;
  - après la lecture Sirene, un cabinet dont `exerceLesActivitesComptables()` rend `false` est refusé sur le champ du type ;
  - `donnees` reçoit `type_activite`, `code_activite` et `nomenclature_activite` (nuls si le répertoire n'a pas répondu) ;
  - `refuserCeQueSireneRefuse()`, aujourd'hui sans retour, rend l'unité lue.
- `ConfirmerInscriptionController` et `resources/views/inscription/confirmer.blade.php` : rappel du SIREN et du type, menu, avertissements de chaque type lus dans `donnees` et montrés avec les types qu'ils concernent (pas de script nécessaire), case obligatoire. Le POST valide le type contre l'énumération et la case.
- `CreerSocieteAction::handle(string $siren, TypeDActivite $type, string $email, string $motDePasse)` (aujourd'hui sans le type) :
  - un cabinet dont la lecture Sirene de la confirmation donne `false` est refusé avec le motif ; rien n'est créé et le lien reste valable ;
  - la société est créée avec son type ;
  - `SocieteInscrite` retient en plus `type_activite`, `code_activite` et `nomenclature_activite` (champs de `ActionAuditee::SocieteInscrite`).
- `DemandeInscriptionMail` (`resources/views/mail/inscription-demande.blade.php`) et `resources/views/courriers/code.blade.php` (`CourrierDeCode`, dans le paragraphe de l'inscription seulement) : rappels de la règle 12.
- `VerificationSociete` (page de l'espace de la société) : rappel de la règle 12 tant que le code n'est pas saisi, pour toute société, quel que soit son type. Le texte du rappel s'écrit une seule fois, dans une vue partielle (`resources/views/filament/rappel-du-type.blade.php`, paramètre `$societe`), que la page inclut ; le lot EC l'inclut aussi dans la page de vérification du cabinet, sans le copier. `IdentiteSociete` : ligne du type, en lecture seule.
- Cabinet sans magasin :
  - `SocietePolicy::creerUnMagasin()`, `gererLesMagasins()` et `ouvrirLaDemonstration()` rendent faux pour un cabinet ; `OuvrirLaDemonstrationAction` et `CreerEtablissementAction` refusent avec « Un cabinet d'expertise comptable n'a pas de magasin. » ;
  - `TableauDeBord` (espace de la société), branche provisoire (voir « Place dans la chaîne ») : pour un cabinet, `etapes()` n'a que l'étape du code, donc `resteDesEtapes()` devient faux une fois le code saisi ; `etatDeLaVerification()` ne lui parle ni de magasins, ni de catalogue, ni d'achats, ni de démonstration (« Votre cabinet attend le code de son courrier recommandé. L'espace des cabinets s'ouvrira avec la validation. ») ;
  - les autres textes de la vérification, au HEAD `65819c6` et tels que V2b les laisse, parlent de magasins ou de démonstration ; pour un cabinet, chacun ne dit que ce qui vaut pour lui, sans magasins ni démonstration :
    - le bandeau `resources/views/filament/verification-en-cours.blade.php` (titre « Société en attente du code de son courrier recommandé : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés. », puis « Essayez … dans le magasin de démonstration, depuis le tableau de bord de l'administration de la société ») : pour un cabinet, le seul titre « Cabinet en attente du code de son courrier recommandé. », sans autre ligne. Ce texte reste vrai dans l'espace du cabinet, où le lot EC montre le même bandeau ;
    - la page `VerificationSociete` (vue `verification-societe.blade.php`), branche provisoire : inscription close (« vos magasins restent consultables ») et attente du code (« Configurez-la et essayez … dans le magasin de démonstration ») ; pour un cabinet, « L'inscription de votre cabinet est close ({motif}) : son espace reste consultable, en lecture seule. » et « Votre cabinet attend le code de son courrier recommandé. » ;
    - l'avis de `ValiderLaSocieteAction::apresLaValidation()` (« La vente, les e-mails aux clients, les PDF et les transmissions sont ouverts dans vos magasins. ») : pour un cabinet, « Votre cabinet est validé. » ;
    - l'avis de `CloreLInscriptionAction::apresLaCloture()` (« Vos magasins restent consultables, en lecture seule. ») et l'e-mail `InscriptionCloseMail` de V2b (« Ses magasins restent consultables, en lecture seule. ») : pour un cabinet, « Son espace reste consultable, en lecture seule. ».
- Clôture d'une inscription, sur le code laissé par V2b et FA1 :
  - `MotifCloture::TypeErrone = 'type_errone'` (« Type d'activité choisi par erreur, à la demande du propriétaire ») ;
  - `CloreLInscriptionAction::refuser()` devient `parLEditeur(Societe, InterventionDeLEditeur, MotifCloture)` ; le reste de la clôture (codes, pièces, démonstration, adresse de connexion retirée, compte gardé, verrous) ne change pas ;
  - `Editeur/RefuserLaSociete` devient `Editeur/CloreLInscription`, signature `editeur:societe:clore {societe} --motif=refus|type_errone`, plus les options communes de `ProcedureDeLEditeur` ;
  - les textes qui citent l'ancienne commande la remplacent : le message à l'éditeur de `DeposerUnePieceAction`, les commentaires de `ExaminerLesPiecesAction`, `ConsulterLesPieces` et `CloreLInscriptionAction` ;
  - l'avis de `apresLaCloture()` suit le motif : pour un type erroné, il dit que l'entreprise peut s'inscrire de nouveau avec le bon type, la même adresse et le même SIREN.
- `SocieteFactory` : `type_activite = TypeDActivite::Commerce` par défaut, ce qui garde leur sens aux tests existants ; états `services()` (`TypeDActivite::Services`) et `cabinet()` (`TypeDActivite::Cabinet`), jamais une chaîne (critère de fin 3). `TestCase::ouvrirUneSociete()` et `inscrireUneSociete()` acceptent un type, passé à la création, jamais ensuite.
- README : l'inscription depuis `/app/register` demande aussi le type d'activité ; un cabinet s'inscrit en local avec `FauxRepertoireSirene::SIREN_CABINET`, tout autre SIREN fictif rendant `47.11B`.

Tests :
- `tests/Feature/Societe/TypeDActiviteTest.php`, nouveau :
  - `test_le_menu_propose_les_trois_types_dans_l_ordre_sans_valeur_par_defaut`
  - `test_la_demande_sans_type_ou_avec_un_type_inconnu_est_refusee`
  - `test_le_type_choisi_entre_dans_la_demande_avec_le_code_d_activite_et_se_rappelle_dans_l_e_mail`
  - `test_la_page_du_lien_rappelle_le_type_et_permet_de_le_changer_sans_nouvel_e_mail`
  - `test_l_ouverture_du_lien_n_interroge_pas_le_repertoire_et_ne_consomme_rien`
  - `test_la_confirmation_sans_la_case_du_choix_definitif_ne_cree_rien`
  - `test_la_societe_nait_avec_le_type_confirme_et_societe_inscrite_retient_le_type_et_le_code`
  - `test_la_base_refuse_un_type_inconnu_et_toute_modification_du_type` (par `DB::table`, sur les deux moteurs ; jeu de données : chaque passage d'un type à un autre)
  - `test_un_cabinet_hors_69_20z_est_refuse_au_formulaire_et_a_la_confirmation_sans_courrier_et_le_siren_reste_libre`
  - `test_un_cabinet_sans_code_ou_d_une_autre_nomenclature_n_est_pas_refuse`
  - `test_un_cabinet_n_a_jamais_de_magasin_ni_de_demonstration` (action, policy, garde en base)
  - `test_la_liste_d_etapes_d_un_cabinet_n_a_que_le_code_du_courrier_et_ne_parle_pas_de_magasins` (branche provisoire : le lot EC le réécrit sur le tableau de bord du cabinet)
  - `test_les_textes_de_la_verification_ne_parlent_a_un_cabinet_ni_de_magasins_ni_de_demonstration` (bandeau, page `VerificationSociete` en attente du code et après la clôture, avis de validation, avis et e-mail de clôture : aucun ne contient « magasin » ni « démonstration » ; le lot EC en réécrit la partie de la page sur la page de vérification du cabinet)
  - `test_les_avertissements_du_code_d_activite_ne_bloquent_jamais` (jeu de données : services et 47.42Z, services et 95.12Z, commerce et 10.71C, commerce et 43.22A, services et 43.22A, services et 69.20Z, commerce et 86.90D, services et 68.20A, commerce et 47.91B, commerce et 56.10A, commerce et 47.22Z)
  - `test_aucun_avertissement_pour_un_code_absent_ou_d_une_autre_nomenclature`
  - `test_le_courrier_recommande_rappelle_le_type_choisi`
  - `test_la_verification_et_l_identite_rappellent_le_type_sans_permettre_de_le_changer` (jeu de données : les trois types ; le rappel vient de la vue partielle ; le lot EC réécrit le cas du cabinet sur les pages du cabinet)
  - `test_apres_une_cloture_pour_type_errone_la_meme_personne_se_reinscrit_avec_le_meme_siren_la_meme_adresse_et_un_autre_type` (le compte de l'inscription close reste, et ne s'ouvre plus qu'avec son identifiant, comme V2b le fait)
- `tests/Feature/Editeur/ProceduresTest.php`, ajouts :
  - `test_editeur_societe_clore_au_motif_type_errone_clot_l_inscription_et_previent_le_proprietaire`
  - `test_editeur_societe_clore_au_motif_type_errone_refuse_une_societe_validee`
- `tests/Feature/Integrations/InseeSireneTest.php`, ajout : `test_l_activite_principale_et_sa_nomenclature_se_lisent_dans_la_periode_en_cours` (`Http::fake`).
- Réécrits avec la même intention :
  - `InscriptionSocieteTest` : chaque envoi du formulaire remplit le type ; `test_la_demande_ne_demande_que_le_siren_l_adresse_et_le_mot_de_passe` devient `test_la_demande_ne_demande_que_le_siren_le_type_d_activite_l_adresse_et_le_mot_de_passe` ;
  - `InscriptionConcurrenceTest`, `IdentifiantsTest`, `VerificationSocieteTest` et tout appelant de `CreerSocieteAction::handle()` : nouvelle signature ;
  - `ProceduresTest` et `VerificationSocieteTest` (l.977 au HEAD `65819c6`, qui attend « Inscription refusée et close. ») : `editeur:societe:refuser` devient `editeur:societe:clore --motif=refus` (dont `test_une_procedure_refusee_n_ecrit_rien`) ; le message attendu suit celui de la nouvelle commande ;
  - chaque appel de `CloreLInscriptionAction::refuser()` devient `parLEditeur(…, MotifCloture::Refus)`, intention gardée. Au HEAD `65819c6` : `AdresseDeConnexionDuProprietaireTest` (l.286), `FermerEtRouvrirMagasinTest` (l.692), `InscriptionSocieteTest` (l.375), `MagasinDeDemonstrationTest` (l.393), `MagasinResourceTest` (l.391), `VerificationSocieteTest` (l.1231) et `IsolationDesSocietesTest` (l.492 et l.511). Les lots V2b à FA3 peuvent en ajouter : le critère de fin 2 les attrape tous ;
  - `IdentiteSocieteTest` : ligne du type.

### Commit 2 — « Lot TA (2/3) : autorisations par famille, sociétés de services sans articles, démonstration par type »

Schéma :
- `referentiel_autorisations_magasin` (`2026_10_02_095000`) : colonne `famille` string(20), non nulle, limitée par CHECK à `commune` et `articles`, remplie depuis `PermissionRegistry::articles()`. Les trois clés `service.*` y entrent.
- Gardes :

  | Garde | Table, moment | Ce qu'elle refuse |
  |---|---|---|
  | `autorisations_d_articles_seulement_avec_des_articles` | `autorisations_affectations` (`2026_10_02_100006`), BEFORE INSERT OR UPDATE | une clé de la famille `articles` sur une affectation d'une société qui n'est pas de type `commerce` (la société se lit par l'affectation, qu'elle vise un magasin ou tous) |
  | `produits_seulement_avec_des_articles`, et de même pour `factures_achat`, `avoirs_fournisseur`, `inventaires_ouverture` | chaque table (`2026_10_02_200003`, `2026_10_07_100001`, `2026_10_07_100003`, `2026_10_07_100005`), BEFORE INSERT OR UPDATE OF magasin_id | une ligne dans un magasin dont la société n'est pas de type `commerce` |

  Aucune garde sur `fournisseurs` (règle 4).

Domaine :
- `TypeDActivite::aDesArticles()` et `Societe::aDesArticles()` : vrai pour le commerce seul.
- `PermissionRegistry` :
  - `SERVICE_VIEW`, `SERVICE_CREATE`, `SERVICE_UPDATE`, ajoutées à `toutes()`, `horsVente()` et `libelles()` ; `SERVICE_VIEW` ajoutée à `consultations()` ;
  - libellés : « Produits : voir », « Produits : créer », « Produits : modifier » (aujourd'hui « Produits et services : … »), « Services : voir », « Services : créer », « Services : modifier » ;
  - `articles()` : les 18 clés de la règle 6, en toutes lettres ;
  - `pourLeType(TypeDActivite): list<string>` : toutes pour commerce, toutes moins `articles()` pour services, aucune pour cabinet ;
  - `duProprietaire(TypeDActivite)`, `modeleDeLAdministrateur(TypeDActivite)` et `verifierConnues(array, TypeDActivite)` reçoivent le type et rendent des clés du type seulement.
- `ServicePolicy`, `TrouverOuCreerServiceAction`, `AjouteDesLignesDeVente::actionAjouterUnService()` et `ServiceResource` passent à `SERVICE_*`.
- Modèles et cases croisés avec le type de la société du magasin, partout où ils s'écrivent ou se précochent :
  - `RoleMagasin::permissionsParDefaut(TypeDActivite)` : `service.*` partout où il y avait `produit.*`, puis croisement avec `pourLeType()`. Appelants : `AffectationsDesMembres::cases()`, donc `CreerMembreAction`, `ModifierMembreAction`, `ChangerLeRoleDeSocieteAction::versMembre()` et `ChangerStatutCompteAction::reintegrer()` ; écrans `MembreResource` (précochage du rôle) et `EquipeDuMagasinResource` (précochage du rôle) ;
  - `PermissionRegistry::modeleDeLAdministrateur(TypeDActivite)` : son seul appelant, `MembreResource::modeleDeLAdministrateur()` (l.406 au HEAD `65819c6`), donc `ListMembres` et `FicheDuMembre` (précochage à la création et à la modification d'un administrateur) ;
  - `PorteeDesAdministrateurs::lire()`, par où passent toutes les cases d'un administrateur (`PermissionRegistry::verifierConnues()`, l.66), reçoit le type de la société et le passe à `verifierConnues()` : une clé hors du type est refusée. Ses appelants sont `CreerAdministrateurAction` (l.60), `ModifierAdministrateurAction` (l.47), `ChangerLeRoleDeSocieteAction::versAdministrateur()` (l.81) et `ChangerStatutCompteAction::reintegrerLAdministrateur()` (l.174) ;
  - `PermissionRegistry::duProprietaire(TypeDActivite)` : `User::autorisationsDans()` et les cases écrites à l'ancien propriétaire par `AccepterLaTransmissionDeLaProprieteAction` ;
  - `PorteeDesDroits::autorisationsCochables()` rend `pourLeType()` de la société du magasin au lieu de `toutes()`.
- `User::autorisationsDans()` et `autorisationsEnregistreesDans()` croisent chaque résultat avec `pourLeType()` de la société du magasin : propriétaire, responsable, cases. Le JET 130 (`JournalDesDroits`) ne liste donc que les autorisations du type.
- `Affectation::cocher()` refuse une clé hors du type. `CocheLesAutorisationsDeMagasin` ne propose que les clés du type.
- Écrans qui disparaissent d'eux-mêmes par leurs autorisations, à vérifier un par un : `ProduitResource`, `FournisseurResource`, `FactureAchatResource`, `AvoirFournisseurResource`, `InventaireOuvertureResource`, `ParametresPrixDeVente`, `StockNegatifApercu`, `ValeurStockApercu`.
- `AjouteDesLignesDeVente` : `actionAjouterUnProduit()` et `champDeLaDouchette()` visibles seulement si la société du magasin a des articles ; `SelecteurDeProduits::mount()` refuse de même. La section des lignes de `Caisse` s'intitule « Produits et services » avec des articles et « Services » sans articles. Le commentaire de `SelecteurDeProduits` cite le nouveau libellé « Produits : voir ».
- `NatureOperation::proposees()` (aujourd'hui `proposees(bool $professionnel, bool $franchiseEnBase)`) reçoit aussi `TypeDActivite` et retire, sans articles, les natures `porteSurDesBiens()` ; `ContexteDeVente::pourEnregistrer()` refuse une nature hors de celles du type ; `ChoisitLaNatureDeLOperation` passe le type et cache le choix quand une seule nature reste.
- `ServiceResource` : la case `frais_accessoires` est cachée sans articles, et enregistrée fausse, quel que soit l'écran qui crée le service (`TrouverOuCreerServiceAction` compris).
- `TableauDeBord::etatDeLaVerification()` : « achats » n'est cité que pour une société qui a des articles.
- `CatalogueDeDemonstration::remplir()` suit le type (règle 13).

Tests :
- `tests/Feature/Authorization/AutorisationsSelonLeTypeTest.php`, nouveau :
  - `test_chaque_autorisation_est_dans_une_seule_famille_et_le_referentiel_en_base_porte_la_sienne`
  - `test_sans_articles_aucun_role_n_a_d_autorisation_d_articles` (propriétaire, responsable, administrateur, vendeur, observateur, dans une société de services)
  - `test_les_cases_proposees_cochables_et_precochees_sont_celles_du_type`
  - `test_dans_une_societe_de_services_le_changement_de_role_la_reintegration_et_la_transmission_n_ecrivent_que_les_autorisations_du_type` (`versMembre()`, `reintegrer()`, transmission de la propriété)
  - `test_dans_une_societe_de_services_les_cases_d_un_administrateur_ne_prennent_que_les_autorisations_du_type` (jeu de données : `CreerAdministrateurAction`, `ModifierAdministrateurAction`, `ChangerLeRoleDeSocieteAction::versAdministrateur()`, `ChangerStatutCompteAction::reintegrerLAdministrateur()` ; une clé d'articles est refusée, le modèle précoché n'en a aucune, la base n'en garde aucune)
  - `test_la_base_refuse_une_case_d_articles_dans_une_societe_sans_articles`
  - `test_les_services_se_voient_se_creent_et_se_modifient_par_leurs_propres_autorisations`
  - `test_le_jet_130_ne_liste_que_les_autorisations_du_type`
  - `test_le_commerce_garde_toutes_les_autorisations_d_aujourd_hui`
- `tests/Feature/Filament/EcransSelonLeTypeTest.php`, nouveau :
  - `test_sans_articles_la_navigation_n_a_ni_produits_ni_achats_ni_fournisseurs_ni_inventaire_ni_prix_de_vente` (inventaire de la navigation par type, pour le propriétaire, un responsable et un vendeur)
  - `test_sans_articles_les_adresses_des_ecrans_d_articles_repondent_403`
  - `test_sans_articles_les_apercus_de_stock_n_apparaissent_pas`
  - `test_sans_articles_la_caisse_les_devis_et_les_factures_n_ajoutent_que_des_services` (un service vendu, encaissé et clôturé ; section « Services » de la caisse)
  - `test_sans_articles_seules_les_natures_sans_biens_sont_proposees_et_acceptees_au_brouillon`
  - `test_sans_articles_le_service_n_a_pas_la_case_frais_accessoires`
  - `test_sans_articles_l_etat_de_la_verification_ne_parle_pas_d_achats`
- `tests/Feature/Authorization/GardesDesTypesDActiviteTest.php`, nouveau : `test_la_base_refuse_produit_facture_d_achat_avoir_fournisseur_et_inventaire_sans_articles` (par `DB::table`, sur les deux moteurs ; jeu de données : une ligne par table, dans un magasin d'une société de services).
- `MagasinDeDemonstrationTest`, ajout : `test_le_catalogue_de_demonstration_suit_le_type_d_activite`.
- `IsolationDesSocietesTest`, ajout : `test_le_type_d_une_societe_ne_change_rien_dans_une_autre` (autorisations, navigation, gardes).
- Réécrits avec la même intention : `ReferentielDesAutorisationsTest` (familles), `AutorisationsDesRolesTest`, `NatureOperationTest`, et chaque test qui donnait `produit.*` pour un service (au moins `ServiceResourceTest`, `CaisseTest`, `RemisesEtPrixTest`, `EquipeDuMagasinTest`, `AutorisationsDesMembresTest`, `AdministrateursTest`, `ResponsablesTest`, `DroitsConcurrenceTest`, `ControleDesDroitsTest`, `JournalEvenementsTest`, `InventaireOuvertureTest`, `FactureAchatResourceTest`, `FactureAchatActionsTest`, `PorteDeValidationTest`) ; `MagasinDeDemonstrationTest`, pour le service de main-d'œuvre ajouté au catalogue du commerce.

### Commit 3 — « Lot TA (3/3) : assurance professionnelle facultative »

Schéma :
- `societes` (`0001_01_00_000000`) : `assurance_assureur` string(200), `assurance_adresse` string(300), `assurance_contrat` string(100), `assurance_couverture` string(200), nulles.

Domaine et écrans :
- `IdentiteSociete` et `ModifierIdentiteSocieteAction` : bloc « Assurance professionnelle » (assureur ou garant, son adresse, n° de contrat, couverture géographique), avec l'aide de la règle 7 ; un champ renseigné sans assureur ni garant est refusé ; le bloc est caché pour un cabinet, et l'action n'écrit pas ces champs pour lui. Les quatre champs entrent dans `Societe::CHAMPS_DE_L_IDENTITE`, donc dans l'audit `IdentiteModifiee`.
- `Emetteur` : les quatre champs dans `duMagasin()`, `enTableau()` et `duContenuSigne()` ; une méthode `ligneDeLAssurance(): ?string` rend la ligne de la règle 7, ou `null` sans assureur. `deDemonstration()` n'en porte pas. Le type d'activité n'entre pas dans l'émetteur.
- `PiedDePage::pour()` ajoute la ligne de l'assurance sous celle de l'identité de la société, pour les devis, les factures, les tickets et les avoirs ; `hauteurEnMillimetres()` la compte.
- `ModuleFiscal::VERSION` passe de `1.2` à `1.3` (version mineure : des champs s'ajoutent au contenu signé de l'émetteur, sans toucher à l'inaltérabilité) ; le vérificateur d'intégrité suit.
- `TypeDActivite::aide()` (entrée « Services ») et `AvertissementsDuCodeDActivite` (divisions 41 à 43) reçoivent leur phrase sur l'assurance (règles 2 et 8).

Tests :
- `tests/Feature/Facturation/AssuranceProfessionnelleTest.php`, nouveau :
  - `test_sans_assurance_un_devis_se_numerote_et_une_vente_s_emet_dans_le_commerce_et_les_services` (jeu de données : type × devis, facture à un professionnel, ticket)
  - `test_un_champ_d_assurance_sans_assureur_ni_garant_est_refuse`
  - `test_l_assurance_renseignee_s_imprime_dans_le_pied_des_devis_des_factures_des_tickets_et_des_avoirs`
  - `test_l_assurance_se_fige_a_l_emission_et_son_changement_s_inscrit_au_journal_d_audit`
  - `test_un_cabinet_n_a_pas_de_bloc_d_assurance` (page `IdentiteSociete` et action ; le lot EC réécrit la partie de la page sur la page d'identité du cabinet)
- `EmetteurFigeTest` et `VerificateurIntegriteTest` : l'émetteur signé porte l'assurance, à la version 1.3.
- Réécrits avec la même intention : `EmetteurFigeTest` (tableau complet de l'émetteur imprimé), `EnregistrementFiscalResourceTest` (version `'1.2'` écrite en dur dans la ligne exportée), `IdentiteSocieteTest` (bloc de l'assurance), `TypeDActiviteTest` (aide de l'entrée « Services », avertissement des divisions 41 à 43).

### Critères de fin

1. Chaque commit est vert sur SQLite puis sur PostgreSQL, code de sortie contrôlé juste après (`echo $?`). Après le dernier commit, la suite SQLite passe aussi sous `faketime '@2027-01-02 10:00:00'`, code 0. `vendor/bin/pint --dirty --format agent` et `php artisan migrate:fresh` avant chaque commit.
2. Ces recherches ne rendent rien :
   - `git grep -n "editeur:societe:refuser" app tests`
   - `git grep -n "CloreLInscriptionAction::class)->refuser(" app tests`
   - `git grep -n "TypeSociete" app tests`
   - `git grep -nE "PRODUIT_(VIEW|CREATE|UPDATE)" app/Policies/ServicePolicy.php`
   - `git grep -n "Produits et services" app/Domain/Authorization/PermissionRegistry.php app/Filament/App/Livewire/SelecteurDeProduits.php`
3. Les textes des avertissements ne vivent que dans `AvertissementsDuCodeDActivite`. Hors de l'énumération, des migrations et des gardes en base (`GardesDesTypesDActivite`), une valeur de type ne s'écrit que par `TypeDActivite::Commerce`, `TypeDActivite::Services` ou `TypeDActivite::Cabinet` (ou leur `->value` dans une requête), fabriques et tests compris. Les chaînes `'services'` et `'cabinet'` désignent déjà autre chose dans le code (la table `services`, le champ `services` du formulaire d'ajout de services, le libellé pluriel de `ServiceResource`) : le relecteur contrôle donc les seules lignes ajoutées par le lot, `git diff <base du lot> -- app tests database | grep -nE "^\+.*'(commerce|services|cabinet)'"`, dont aucune ne désigne un type hors de ces trois endroits. Aucun code ne change le type d'une société existante.
4. CLAUDE.md porte les phrases de la section précédente et reste cohérent. Le README dit que l'inscription demande le type, et qu'un cabinet s'inscrit en local avec `FauxRepertoireSirene::SIREN_CABINET`.
5. Le test d'isolation passe ; aucune lecture nouvelle hors du magasin ou de la société courants.
6. Les messages de commit nomment chaque test réécrit ou supprimé, avec sa raison.
7. Le compte rendu signale les références légales à revérifier (règles 7 et 8) et les limites du choix 15.

### Ce que le lot EC reprend

`avenant-cabinets.md` est mis d'accord avant le commit 1 du lot EC :
- 0.1, 2.1, 3.1, 3.2 : `TypeSociete` et `societes.type` deviennent `TypeDActivite` et `type_activite`, posés par TA, sans valeur par défaut. `Societe::estUnCabinet()` existe déjà. Les gardes d'EC lisent `type_activite` : un collaborateur seulement dans un cabinet ; un client de type autre que cabinet dans `liens_cabinet`.
- 2.3 : la garde `societes_type_connu_et_fixe` disparaît, remplacée par celle de TA. `magasins_jamais_dans_un_cabinet` est déjà posée par TA. `GardesDesCabinets` garde les autres.
- 3.3 :
  - `UniteSirene`, `InseeSirene`, `FauxRepertoireSirene` et `ControleSirene` sont faits par TA ; EC les utilise ;
  - point 1 : le bouton radio devient l'entrée du menu de TA ; EC ajoute la présélection par `?type=cabinet`, que seul le lien de 3.5.12 (une société invite son expert-comptable à créer son espace) utilise ;
  - point 2 et point 6 : faits par TA ;
  - points 3, 4 et 7 : à EC (activité à contrôler quand le code est absent ou d'une autre nomenclature, ligne `cabinets` avec le code lu à la confirmation, `ActiviteDuCabinetControlee`, revérification mensuelle) ;
  - point 5 : `SocieteInscrite` retient déjà le type et le code.
- 3.5.5 et 3.9 : `AccepterLInvitationAction` reçoit le type choisi dans le menu sans cabinet (« Commerce ou atelier » ou « Services, sans vente d'articles ») et la case du choix définitif ; la page montre les avertissements du code lu au répertoire à l'acceptation ; la société est créée avec ce type, jamais une valeur par défaut ; `SocieteInscrite` retient type et code. EC écrit dans `AvertissementsDuCodeDActivite` le texte de la ligne 69.20Z propre à la page d'acceptation (règle 8), par une méthode `pourUneInvitation(?string $code, ?string $nomenclature)` qui rend la liste de `pour()` avec ce texte à la place de celui de la page du lien.
- 3.5.9 et règle 5 de `decision-espace-cabinets.md` (autorisations du cabinet chez un client) : les autorisations d'achats et de stock ne se proposent qu'à un client qui a des articles (`duCabinet()` croisé avec `pourLeType()`). « La création des produits qu'ils font naître, jamais celle d'un service » devient : `produit.create` permis, `service.create` jamais. `service.view` est une consultation : le cabinet la reçoit par défaut.
- 1.1 (puce de CLAUDE.md) : « de type « cabinet », choisi à son inscription dans le menu du type d'activité, définitif », sans le passage [Q-EC2]. « l'espace des cabinets ne dépend pas de la typologie des entreprises : un client est une société PILIQI, avec ou sans magasin » devient « un client est une société PILIQI de type « Commerce ou atelier » ou « Services, sans vente d'articles », avec ou sans magasin ; les autorisations des achats et du stock ne se proposent qu'à un client qui a des articles ». Dans la puce « Type d'activité », EC ajoute ce que TA n'écrit pas, faute de le rendre vrai : le renvoi de la parenthèse du cabinet vers la puce « Cabinets d'expertise comptable » et la phrase « Un client invité par un cabinet choisit son type à l'acceptation, jamais « Cabinet d'expertise comptable ». ». Dans la puce « Vérification de la société », « n'y configure que son identité » devient « y configure son identité et ses collaborateurs ».
- 3.8, 4.1 et 4.4 : l'entrée du menu existe ; EC mène le propriétaire d'un cabinet à l'espace du cabinet. Il retire les branches provisoires du cabinet de `TableauDeBord` (liste d'étapes réduite, texte de l'état de la vérification) et de la page `VerificationSociete` (texte du cabinet), que l'espace du cabinet remplace. `VerificationSociete` garde le rappel du type (règle 12) pour toute autre société qui attend son code, et la page de vérification du cabinet inclut la même vue partielle, sans copier le texte. Le bandeau des panneaux, les avis de validation et de clôture et l'e-mail de clôture gardent leur texte du cabinet.
- 6.1 et 6.19 : le test « une entreprise passe au type cabinet » est supprimé. Les tests du refus hors 69.20Z, de l'absence de magasin (action, policy, garde) et de l'immuabilité du type restent tels quels dans `TypeDActiviteTest`. Les tests de TA qui ouvrent l'espace de la société avec un cabinet sont réécrits par EC, intention gardée, sur les pages du cabinet : `TypeDActiviteTest::test_la_liste_d_etapes_d_un_cabinet_n_a_que_le_code_du_courrier_et_ne_parle_pas_de_magasins` (tableau de bord du cabinet), le cas du cabinet de `test_la_verification_et_l_identite_rappellent_le_type_sans_permettre_de_le_changer`, la partie de la page de `test_les_textes_de_la_verification_ne_parlent_a_un_cabinet_ni_de_magasins_ni_de_demonstration`, et la partie de la page de `AssuranceProfessionnelleTest::test_un_cabinet_n_a_pas_de_bloc_d_assurance` (page d'identité du cabinet). La table 6.19 de l'avenant les nomme. EC ajoute ceux de la ligne `cabinets` et de l'arrivée dans l'espace du cabinet.
- 8 : Q-EC2 sort des questions, puisqu'elle a reçu sa réponse.

### Ce que les lots A7 et 21 reprennent

- A7 : `editeur:societe:clore` (motifs refus et type erroné) est une procédure de l'éditeur.
- Lot 21 : la matrice décision → test couvre les règles 1 à 15 de ce fichier.
- Étape 13 : l'écran de cette procédure.

## Choix de Claude que l'éditeur peut contester

1. **Les textes du menu.** L'éditeur a fixé ses trois familles (règle 1 des réponses) ; leurs libellés, exemples et aides sont de Claude. « Commerce ou atelier » réunit tout ce qui vend des articles achetés, réparateurs et salons qui vendent des produits compris. « Services, sans vente d'articles » réunit le reste, métiers du bâtiment compris, sans règle propre.
2. **Aucune entrée pour les activités exonérées de TVA** (santé, enseignement, location de logements). L'éditeur refuse ces ventes. Un avertissement le dit. Servir la santé exigerait aussi un hébergement certifié pour les données de santé (art. L1111-8 du code de la santé publique).
3. **Pas de mode « sur place » ou « à emporter » dans ce lot.** Un article acheté vendu aux deux taux n'est pas pris en charge, et l'avertissement de la division 56 le dit. Un taux « sur place » sur la fiche du produit, ou un mode signé sur chaque document, inscrit au journal des événements à chaque changement et réparti dans les clôtures, pourra venir plus tard, sur décision de l'éditeur.
4. **Assurance facultative pour tous les types qui ont un magasin** (règle 4 des réponses), imprimée quand elle est renseignée, jamais exigée. La loi impose cette mention aux artisans dont l'activité exige une assurance, qui s'inscrivent dans les services : l'aide de l'entrée « Services », celle du bloc et l'avertissement des divisions 41 à 43 la rappellent. Elle s'imprime aussi sur les tickets et les avoirs, parce que le pied des PDF de vente est commun. Un champ sans assureur est refusé, pour qu'aucune ligne incomplète ne s'imprime.
5. **Deux avertissements de plus, jamais bloquants.** Les divisions 41 à 43 rappellent l'assurance et les limites des travaux du bâtiment, dans le commerce comme dans les services. Les divisions 10 à 32 disent au commerce que la vente de ce qu'il fabrique n'est pas prise en charge.
6. **Code d'activité.** Il ne bloque que le cabinet. Il n'est inscrit qu'au journal d'audit de l'inscription. Il n'est jamais lu pendant la saisie (le répertoire limite les requêtes, et une présélection pousserait vers un choix définitif sans réflexion). Il n'est jamais revérifié, sauf pour un cabinet.
7. **Erreur de type : nouvelle inscription, sous un autre compte.** Après la clôture, telle que V2b la fait (compte gardé, qui ne s'ouvre plus qu'avec son identifiant, adresse et SIREN libérés), la même personne se réinscrit avec la même adresse et le même SIREN. Rien ne passe de l'inscription close à la nouvelle, ni catalogue, ni équipe : un compte appartient pour toujours à une seule société.
8. **Le type se change sur la page du lien**, sans nouvel e-mail et avec une case obligatoire, plutôt que par un lien « Recommencer l'inscription », qui aurait laissé plusieurs demandes ouvertes.
9. **« Magasin » reste le mot de tous les écrans** jusqu'à l'étape 15, plutôt qu'« établissement » dans certains écrans de certains types.
10. **Les autorisations des services sont séparées dès ce lot.** Elles donnent des familles nettes et répondent au besoin du lot EC (« la création des produits, jamais celle d'un service »), au prix de tests réécrits.
11. **L'entrée « Cabinet » est au menu dès ce lot**, avec le contrôle 69.20Z, le refus de tout magasin, une liste d'étapes réduite au code et des textes de la vérification sans magasins ni démonstration ; l'espace du cabinet vient avec le lot EC. La liste réduite et les textes du cabinet dans le tableau de bord et la page « Vérification » de l'espace de la société sont provisoires : le lot EC les remplace par l'espace du cabinet. C'est la seule chose que ce lot pose et que le lot EC retire ; sans elle, le propriétaire d'un cabinet lirait des étapes qu'il ne peut pas suivre.
12. **`editeur:societe:refuser` devient `editeur:societe:clore --motif=`**, une seule procédure pour deux motifs.
13. **Le catalogue de démonstration du commerce est gardé**, avec un service de main-d'œuvre en plus, plutôt que remplacé.
14. **Pas de garde en base sur les fournisseurs d'une société de services** : écrans et autorisations absents suffisent, et les étapes 2 et 5 auront besoin des fournisseurs pour tous les types.
15. **Limites signalées, hors de ce lot :**
    - la date de la prestation ou de la livraison, quand elle diffère de la date d'émission (art. 242 nonies A de l'annexe II au CGI), n'existe pour aucun type ;
    - les factures d'acompte n'existent pas, et un acompte reçu avant la facture ne s'enregistre pas, puisque le montant d'un paiement est égal au total imputé sur les factures ;
    - les quantités sont entières : la vente au poids et le travail à l'heure fractionnée attendent l'étape 7 ;
    - une vente à un particulier ne s'intitule jamais « Facture » : la caisse et « Factures » n'émettent pour lui que des tickets. Une facture est pourtant obligatoire pour certaines ventes à un particulier, dont les travaux immobiliers (référence légale à revérifier), et un client peut en demander une. Cet intitulé pourra venir plus tard, dans un lot à part, si l'éditeur le veut ;
    - travaux du bâtiment : sous-traitance où la TVA est due par le donneur d'ordre (autoliquidation, art. 283, 2 nonies du CGI), dont la facture ne doit pas porter de TVA, sous peine d'en devenir redevable (art. 283, 3 du CGI) ; justificatif des taux réduits des travaux dans un logement (10 % et 5,5 %), dont la forme est à vérifier depuis la loi de finances pour 2025 ; retenue de garantie ; situations de travaux ; attestation de la décennale jointe aux devis et aux factures (art. L243-2 du code des assurances), qui attend les pièces jointes de l'étape 8 ; présentation du devis à un particulier, à vérifier contre l'arrêté du 24 janvier 2017 ;
    - commerce : vente de ce que la société fabrique ou prépare elle-même ; matières premières, fournitures et frais, qui ne s'enregistrent pas avant les étapes 2 et 5 ; vente à distance à des particuliers d'autres pays de l'Union au-delà du seuil de 10 000 € ;
    - à vérifier en priorité : depuis la loi de finances pour 2025, l'art. 286, I, 3° bis du CGI exigerait le certificat d'un organisme accrédité au lieu de l'attestation individuelle de l'éditeur ; l'étape 3 et le texte de CLAUDE.md sur l'attestation en dépendent ;
    - un mode de paiement « titre-restaurant » n'existe pas, et l'éditeur a limité les modes aux cinq actuels (Q14 c).

## Questions ouvertes

Aucune. Les réponses de l'éditeur du 24/10/2026 (`reponses-types-activite.md`) sont appliquées, et ce fichier ne déroge à aucune règle qu'il a fixée.

## Constats écartés

Les relectures du 24/10/2026 ont été vérifiées une à une, dans ce fichier, CLAUDE.md, les décisions et le code (lecture seule, HEAD `79216b1` et travail en cours du lot V2b). À l'intérieur de constats retenus, ces éléments sont écartés :

1. **Relecture 1, constat 13, la référence à l'art. 259 B du CGI** pour la vente à distance de biens. Cet article vise des prestations de services. L'avertissement cite l'art. 258 A et le seuil de 10 000 €, à revérifier au moment du lot.
2. **Relecture 1, constats 8, 9 et 14, les corrections non retenues parmi celles qu'ils proposaient au choix.** Ce ne sont pas des constats écartés, mais des options laissées de côté, avec leur raison :
   - constat 8 : pas de question à l'éditeur sur un taux « sur place » ; l'avertissement de la division 56 le dit (choix 3) ;
   - constat 9 : pas d'état « matière non vendue » ; la règle 3 et l'aide disent que matières et frais attendent les étapes 2 et 5 ;
   - constat 14 : la garde sur les fournisseurs n'est pas posée, plutôt que posée puis levée aux étapes 2 et 5 (choix 14).

### Relectures adversariales du 25/10/2026

Les deux relectures de ce fichier et de `avenant-cabinets.md` ont été vérifiées une à une, dans les deux fichiers, CLAUDE.md (arbre de travail du lot V2b), `reponses-cabinets.md`, `reponses-types-activite.md`, `serie8-lots.js` et le code (lecture seule, HEAD `65819c6` et travail en cours de la relecture de V2b). Tous les constats qui touchent ce fichier sont réels et corrigés : relecture 1, n° 1, 4, 5, 7, 8 et 9 ; relecture 2, n° 1, 2, 3, 7, 8 et 9. Les constats 2, 3 et 6 de la relecture 1 et 4, 5, 6, 10 et 11 de la relecture 2 touchent l'avenant et y sont traités. Sont écartés, en partie seulement :

3. **Relecture 1, n° 1, option « ne pas montrer la ligne 69.20Z sur la page d'acceptation ».** Écartée au profit d'un texte propre à cette page (règle 8). Un cabinet invité par erreur comme client prendrait sinon un type qu'il ne pourra jamais changer : l'avertissement lui dit de refuser l'invitation et de s'inscrire lui-même.
4. **Relecture 1, n° 5, texte proposé « (un cabinet d'expertise comptable : son identité et, au lot EC, ses collaborateurs, sans magasin ni démonstration) ».** Écarté dans sa forme : TA n'écrit dans CLAUDE.md que ce qu'il rend vrai (constat 8 de la même relecture). Le commit 1 écrit « un cabinet d'expertise comptable, qui n'a ni magasin ni démonstration, n'y configure que son identité », et le lot EC y ajoute ses collaborateurs.
5. **Relecture 2, n° 1, option « supprimer le test de la liste d'étapes réduite au profit du test 6.1 ».** Écartée : la fonction ne disparaît pas, elle passe de l'espace de la société à celui du cabinet. Le test est réécrit sur le tableau de bord du cabinet, intention gardée (aucun test supprimé pour faire passer la suite, règle 4 du protocole).
6. **Relecture 2, n° 3, correction « le lot EC donne au bandeau une variante pour le cabinet ».** Sans objet : le texte du cabinet que TA pose dans le bandeau (« Cabinet en attente du code de son courrier recommandé. ») reste vrai dans l'espace du cabinet, où EC montre le même bandeau. EC n'a rien à défaire.
7. **Relecture 2, n° 7, liste des tests.** Pas un écart : la liste du constat, prise au HEAD `65819c6`, oubliait `MagasinResourceTest` (l.391), `VerificationSocieteTest` (l.1231) et `IsolationDesSocietesTest` (l.492 et l.511). Elle est complétée, et le critère de fin 2 attrape aussi ceux que les lots V2b à FA3 ajouteraient.
