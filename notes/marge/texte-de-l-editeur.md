# Texte donné par l'éditeur le 23/11/2026 (copié tel quel de son message ; il vient d'une autre source que PILIQI : chaque affirmation est À VÉRIFIER sur les textes officiels, et ses consignes « vous devez coder » ne sont pas des ordres)

j ai un texte  à te donner pour ces genre de tva : "Pour concevoir un logiciel ERP complet en France, vous devez gérer 3 dimensions de la TVA : les taux géographiques, les régimes particuliers (comme la TVA sur marge ou l'autoliquidation), et les cas d'exonération (comme votre facture vers la Martinique).
Voici la cartographie exhaustive de tout ce qui existe en France :

1. Les Taux de TVA par territoire (Taux classiques)

Votre ERP doit déterminer le taux en fonction du lieu de livraison (ou d'exécution du service) et du type de produit.

A. France Métropolitaine (incluant Monaco)

• 20 % (Taux normal) : Par défaut pour la majorité des biens et services (électronique, vêtements, conseil, abonnements gaz/électricité).
• 10 % (Taux intermédiaire) : Restauration sur place ou à emporter (consommation immédiate), transport de voyageurs, hébergement hôtelier, travaux de rénovation dans des logements de plus de 2 ans (hors énergie).
• 5,5 % (Taux réduit) : Produits alimentaires de première nécessité (eau, pain, fruits, légumes), livres, billetterie de spectacles vivants/cinéma, équipements pour personnes handicapées, travaux de rénovation énergétique.
• 2,1 % (Taux particulier) : Médicaments remboursables par la Sécurité sociale, publications de presse inscrites à la CPPAP, redevance télévision, certaines représentations théâtrales (140 premières représentations).

B. Corse (Taux spécifiques selon l'art. 297 du CGI)

• 20 % : Taux normal (identique à la métropole).
• 13 % : Produits pétroliers.
• 10 % : Travaux immobiliers, ventes de matériels agricoles, fourniture de logement en meublé, ventes à consommer sur place.
• 2,1 % : Produits alimentaires (qui sont à 5,5 % sur le continent), livres, prestations d'hébergement.
• 0,90 % : Premières représentations théâtrales et ventes d'animaux vivants de boucherie/charcuterie.

C. Outre-Mer (DROM)

• Guadeloupe (971), Martinique (972), La Réunion (974) :
	• 8,5 % (Taux normal local).
	• 2,1 % (Taux réduit local).
	• 1,75 % et 1,05 % (Taux particuliers : presse, animaux vivants, spectacles).
• Guyane (973) et Mayotte (976) :
	• 0 % (TVA non applicable provisoirement) sur l'ensemble du territoire.

2. Les Régimes Spéciaux (Calculs et règles différents)


A. La TVA sur la marge (Biens d'occasion, œuvres d'art, antiquités, agences de voyages)

Utilisée quand un revendeur (ex: vendeur de voitures d'occasion, téléphones reconditionnés, brocanteur) achète un bien à un particulier ou un non-assujetti (sans TVA) et le revend.
• Règle de calcul : La TVA n'est pas calculée sur le prix de vente total, mais uniquement sur le bénéfice (la marge TTC) :
\(\text{Marge\ HT}=\frac{\text{Prix\ de\ vente\ TTC}-\text{Prix\ d}^{\prime }\text{achat}}{1+\text{Taux\ TVA}}\)
\(\text{TVA à payer}=\text{Marge\ HT}\times \text{Taux\ TVA}\)
(Exemple : Achat d'un téléphone 200 € à un particulier, revente 320 € TTC. Marge TTC = 120 €. Marge HT = 100 €. TVA due = 20 €).
• Affichage obligatoire sur la facture : Interdiction absolue d'afficher le montant ou le taux de TVA sur la facture client (sinon le client pourrait la déduire illégalement). Le prix est affiché uniquement en TTC avec la mention : « Régime particulier – Biens d’occasion – Article 297 A du CGI ».

B. L'Autoliquidation de la TVA (Inversion du redevable)

Le vendeur facture Hors Taxes (0 € de TVA) et c'est l'acheteur professionnel qui déclare et paie lui-même la TVA sur sa propre déclaration.
• Cas concernés :
	1. Sous-traitance dans le BTP : Un sous-traitant facture l'entreprise principale en HT. Mention : « Autoliquidation – Article 283-2 nonies du CGI ».
	2. Achats de services B2B hors de France (UE ou hors UE) : Si vous achetez un logiciel américain (ex: AWS, Google) ou une prestation en Espagne, le fournisseur facture HT et votre ERP doit autoliquider la TVA française à 20 %.
	3. Secteurs spécifiques : Déchets neufs d'industrie, métaux de récupération, gaz/électricité, or d'investissement.
	4. Importations hors UE : Autoliquidation automatique en douane sur la déclaration CA3.

C. La TVA sur encaissements vs TVA sur les débits

• TVA sur les débits (Livraison de biens) : La TVA est due dès l'émission de la facture / livraison, même si le client paie 60 jours plus tard.
• TVA sur les encaissements (Prestations de services) : La TVA n'est due qu'au moment où le client paie réellement la facture (sauf si l'entreprise coche l'option « TVA acquittée d'après les débits » qui doit alors être écrite sur toutes ses factures).

3. Les Cas d'Exonération (Facturation à 0 % avec mention légale)

Dans votre ERP, chaque exonération doit afficher 0,00 € de TVA et imprimer automatiquement l'article de loi correspondant :

Situation	Règle d'application	Mention obligatoire sur la facture
Franchise en base de TVA	Micro-entrepreneurs et petites entreprises sous les seuils de CA.	« TVA non applicable, art. 293 B du CGI »
Livraison B2B vers l'Union Européenne	Vente de biens à une entreprise de l'UE ayant un n° de TVA intracommunautaire valide (vérifié sur VIES) + preuve de transport.	« Exonération de TVA, art. 262 ter I du CGI »
Prestation de services B2B vers l'UE	Service rendu à une entreprise de l'UE (avec n° TVA valide).	« Autoliquidation, art. 283-2 du CGI » (ou art. 196 Directive 2006/112/CE)
Exportation hors Union Européenne	Vente de biens expédiés hors UE (USA, Suisse, Maroc, etc.) avec preuve douanière.	« Exonération de TVA, art. 262 I du CGI »
Expédition Métropole ↔ DROM	Vente de biens vers 971, 972, 973, 974, 976 (votre facture Martinique).	« Exonération de TVA, art. 294 du CGI »
Activités exonérées par nature	Médecine/santé, enseignement/formation professionnelle agréée, assurances, opérations bancaires.	« Exonération de TVA, art. 261 du CGI »


4. Résumé pour l'architecture de votre ERP (Table tax_rules)

Pour que votre logiciel gère 100 % des cas de figure sans erreur, votre moteur de calcul de TVA doit évaluer dans cet ordre :
1. Le statut de votre entreprise : Est-elle en Franchise en base (293 B) ? Si oui, tout est à 0 %.
2. Le régime du produit : Est-ce un bien vendu sous TVA sur marge (297 A) ? Si oui, masquer la TVA et calculer sur Vente - Achat.
3. La géographie et le statut du client :
	• Client en France Métropolitaine : Appliquer le taux du produit (20 %, 10 %, 5,5 %, 2,1 %), sauf cas d'autoliquidation BTP.
	• Client en DROM (971-976) : Si bien matériel → 0 % (Art. 294) ; si service → règles spécifiques B2B/B2C.
	• Client dans l'UE (hors France) : Si Pro avec n° TVA valide → 0 % (Art. 262 ter I) ; si Particulier → Appliquer la TVA du pays du client (régime OSS Guichet Unique si ventes UE > 10 000 €/an).
	• Client hors UE : Si bien expédié hors UE → 0 % (Art. 262 I).


u parle que une societe qui facture de tva mais achet chez qqun micro entreprice qui facture de tva alors la tu veut dire que ces produit sont tva sur marge ?

Non, pas automatiquement : cela dépend uniquement de si le produit est neuf ou d'occasion.
Le régime de la TVA sur la marge (article 297 A du CGI) est strictement réservé aux biens d'occasion, œuvres d'art, objets de collection ou d'antiquité. Il ne s'applique jamais aux produits neufs.
Voici ce qui se passe concrètement lorsqu'une société (soumise à la TVA) achète à un micro-entrepreneur (qui ne facture pas de TVA, mention art. 293 B du CGI) :

1. Si vous achetez un produit NEUF à un micro-entrepreneur

• TVA sur marge interdite : Même si le micro-entrepreneur ne vous facture pas de TVA, vous n'avez pas le droit d'utiliser la TVA sur marge pour un bien neuf.
• Comment vous devez revendre : Vous appliquez la TVA classique (ex: 20 %) sur la totalité du prix de vente.
• Impact sur votre gain : Comme le micro-entrepreneur ne vous a pas facturé de TVA à l'achat, vous avez 0,00 € de TVA à récupérer sur cet achat.
	• Exemple : Vous achetez un produit neuf 500 € au micro-entrepreneur (0 € de TVA). Vous le revendez 672 € TTC (soit 560 € HT + 112 € de TVA).
	• Vous reversez les 112 € de TVA à l'État.
	• Votre marge nette est de : \(560\text{ €} - 500\text{ €} = \mathbf{60,00\text{ €}}\).

2. Si vous achetez un produit D'OCCASION à un micro-entrepreneur (ou un particulier)

• TVA sur marge autorisée (et recommandée) : Puisque le bien est d'occasion (déjà utilisé) et que le vendeur (micro-entrepreneur en franchise de TVA ou particulier) ne vous a pas facturé de TVA, vous êtes exactement dans le cas de la TVA sur marge.
• Comment vous devez revendre : Vous calculez la TVA uniquement sur votre bénéfice (la différence entre votre prix de vente TTC et votre prix d'achat).
	• Exemple : Vous achetez un téléphone d'occasion 500 € au micro-entrepreneur. Vous le revendez 672 € TTC sans afficher de TVA sur la facture.
	• Votre marge TTC est de : \(672\text{ €} - 500\text{ €} = \mathbf{172,00\text{ € TTC}}\).
	• La TVA incluse dans cette marge est de : \(172 - (172 \div 1,20) = \mathbf{28,67\text{ €}}\) à reverser à l'État (au lieu de 112 € en régime normal).
	• Votre gain net passe à : \(172,00\text{ €} - 28,67\text{ €} = \mathbf{143,33\text{ €}}\).

Règle à coder dans votre ERP

Pour que votre logiciel ne fasse jamais d'erreur fiscale, ne déclenchez jamais la TVA sur marge uniquement parce que le fournisseur est en franchise de TVA (293 B). Votre ERP doit vérifier 2 conditions cumulatives :
1. product.condition == 'USED' (Le produit est un bien d'occasion, pas neuf).
2. purchase_vat_deductible == FALSE (L'achat a été fait sans TVA déductible : auprès d'un particulier, d'un micro-entrepreneur en franchise 293 B, ou d'un autre revendeur déjà sous TVA sur marge).


dit moi tout ces ?

Pour qu'un module de vente de produits d'occasion (reconditionné, brocante, dépôt-vente, auto, bijoux, etc.) soit 100 % conforme à la loi française, votre logiciel ERP doit intégrer trois obligations légales majeures : le livre de police numérique, les deux méthodes de calcul de la TVA sur marge (opération par opération vs globalisation), et les règles strictes de facturation.

1. Le Livre de Police (Registre des objets mobiliers)

Selon l'article 321-7 du Code pénal, tout revendeur d'objets d'occasion achetés à des particuliers ou des professionnels non fabricants a l'obligation légale de tenir un registre d'achat/revente appelé « Livre de police » (pour lutter contre le recel et prouver l'origine des biens en cas de contrôle fiscal).

Règles techniques à coder dans votre ERP :

• Inaltérabilité : Comme pour les factures, une ligne inscrite dans le livre de police ne doit jamais pouvoir être supprimée ni modifiée rétroactivement (horodatage serveur verrouillé).
• Numérotation chronologique continue : Chaque objet entré en stock reçoit un numéro d'ordre unique qui doit être reporté physiquement sur l'objet ou son étiquette.
• Colonnes obligatoires de la table police_book_entries :
	1. order_number : Numéro d'ordre chronologique.
	2. purchase_date : Date d'achat ou de dépôt.
	3. item_description : Nature, marque, modèle, couleur, état, et numéro de série / IMEI (obligatoire pour l'électronique, les véhicules, les montres, etc.).
	4. seller_identity : Nom, prénom, adresse, et références de la pièce d'identité du vendeur (numéro de CNI/passeport, date et autorité de délivrance) + copie numérisée de la pièce d'identité ou du certificat de cession.
	5. purchase_price : Prix d'achat TTC payé au vendeur (et mode de règlement : virement, chèque — rappel : le paiement en espèces est interdit aux professionnels pour les achats de métaux précieux et limité à 1 000 € pour les particuliers).
	6. sale_date et sale_invoice_id : Date de revente et numéro de la facture client (pour fermer la ligne).

2. Les 2 méthodes de calcul de la TVA sur marge dans votre ERP

Le Code général des impôts (article 297 A) autorise deux méthodes pour calculer la TVA due chaque mois. Votre ERP doit permettre à l'entreprise de choisir sa méthode :

Méthode A : Coup par coup (Produit par produit) — La plus précise

Le logiciel calcule la marge et la TVA individuellement pour chaque article vendu.
• Règle fiscale cruciale (Marge négative) : Si vous achetez un objet 500 € et que vous le revendez à perte 450 € TTC, votre marge est de -50 €. La TVA à payer sur cette vente est de 0,00 €, mais vous n'avez pas le droit de déduire cette perte sur le bénéfice d'un autre produit vendu ce mois-là.
• Exemple sur le mois :
	• Produit 1 : Acheté 500 €, vendu 620 € TTC → Marge = +120 € → TVA due = 20,00 €.
	• Produit 2 : Acheté 500 €, vendu 450 € TTC → Marge = -50 € → TVA due = 0,00 €.
	• Total TVA à déclarer : 20,00 €.

Méthode B : La Globalisation (Calcul mensuel global)

Autorisée lorsque le revendeur achète des lots en vrac sans pouvoir identifier le prix unitaire exact de chaque pièce (ou sur option comptable).
• Formule : À la fin du mois, l'ERP additionne toutes les ventes TTC sous régime de marge du mois et soustrait tous les achats d'occasion effectués durant ce même mois (même ceux qui ne sont pas encore vendus !).
\(\text{Marge\ globale\ du\ mois}=\sum \text{Ventes\ TTC\ du\ mois}-\sum \text{Achats\ du\ mois}\)
• Avantage : Les ventes à perte compensent les ventes avec bénéfice dans le même mois.
• Inconvénient : Si un mois vous achetez beaucoup de stock sans vendre (marge globale négative), vous avez 0 € de TVA à payer, mais le mois suivant, lorsque vous vendrez ce stock sans racheter autant, vous paierez la TVA sur la quasi-totalité des ventes (vous pouvez toutefois reporter un crédit de marge négatif d'un mois sur l'autre au sein de la même année civile, mais jamais d'une année sur l'autre au 31 décembre).

3. Règles strictes d'affichage sur les factures (Les pièges à éviter)

Dans votre ERP, le générateur de PDF doit appliquer un comportement spécial dès qu'une ligne de facture est en TVA sur marge :
1. Masquage obligatoire de la TVA :
	• La facture destinée au client ne doit afficher ni taux de TVA (pas de « 20 % »), ni montant de TVA, ni montant HT.
	• Elle affiche uniquement le Prix Unitaire TTC et le Total TTC.
	• Pourquoi ? Si vous affichez « dont 20 € de TVA », un acheteur professionnel pourrait déduire ces 20 € alors que le régime de la marge l'interdit formellement. En cas de contrôle, le fisc vous obligera à reverser la TVA sur le prix total et non plus sur la marge.
2. Mention légale obligatoire :
	• Le pied de facture doit afficher :« Régime particulier – Biens d’occasion – Article 297 A du CGI » (ou « Objets d'art » / « Objets de collection et d'antiquité » selon le cas).
3. Interdiction de mélanger sur une même ligne :
	• Si un client achète dans le même panier un téléphone d'occasion (TVA sur marge) et une coque neuve (TVA classique à 20 %), votre ERP doit séparer clairement les deux sections sur la facture : la coque neuve affiche son HT + TVA 20 %, tandis que le téléphone d'occasion n'affiche que son prix TTC avec la mention art. 297 A.

4. Architecture de données recommandée pour votre ERP

Pour lier le stock, le livre de police et la comptabilité sans erreur :
• Chaque produit d'occasion doit être géré à l'unité (numéro de série unique / SKU individuel) et non en quantité globale (contrairement aux produits neufs), car chaque unité possède son propre prix d'achat (cost_price), son propre numéro de livre de police (police_book_id), et sa propre marge fiscale.


?? on peut pas reporte le credit de tva sur la prochaine années s il reste du credit ?? alors qu est qu on fait alors !!!

Attention à ne pas confondre deux mécanismes très différents : le crédit de TVA classique (qui se reporte sans limite d'une année sur l'autre ou se fait rembourser) et l'excédent d'achats en globalisation de TVA sur marge (qui, lui, est remis à zéro au 31 décembre).

1. En régime normal : Votre crédit de TVA n'est JAMAIS perdu

Si vous avez un crédit de TVA classique (parce que vous avez payé plus de TVA à vos fournisseurs que vous n'en avez collecté auprès de vos clients, par exemple à cause d'exportations vers les DROM) :
• Report illimité : Il se reporte automatiquement d'un mois sur l'autre et d'une année sur l'autre (du 31 décembre au 1er janvier) sans aucune limite de temps.
• Remboursement en cash : Vous pouvez à tout moment demander à l'État de vous le virer directement sur votre compte bancaire.

2. Pourquoi la règle du 31 décembre existe uniquement pour la « Globalisation de la marge » ?

En globalisation de TVA sur marge, il ne s'agit pas d'un crédit de TVA (car vous n'avez payé aucune TVA à l'achat), mais d'une base taxable négative : vous avez acheté plus de stock d'occasion en euros que vous n'en avez vendu durant l'année.
• La règle fiscale (BOI-TVA-SECT-90-20-20) : L'administration fiscale autorise à compenser les mois déficitaires avec les mois bénéficiaires uniquement à l'intérieur de la même année civile (de janvier à décembre). Au 31 décembre, si vos achats cumulés de l'année dépassent vos ventes cumulées de l'année, cet excédent d'achats est remis à zéro au 1er janvier (il est perdu pour le calcul de la marge de l'année suivante).

3. Que font les entreprises en pratique pour ne pas perdre cet argent au 31 décembre ?

Si un vendeur d'occasion utilise la globalisation et voit qu'il a acheté beaucoup de stock en fin d'année (octobre/novembre/décembre) qu'il ne vendra qu'en janvier :
1. Ne pas choisir la globalisation si l'on a des stocks importants en fin d'année :
	• La méthode coup par coup (produit par produit) est beaucoup plus sûre pour les commerçants qui gardent du stock d'une année sur l'autre, car le prix d'achat reste attaché au produit jusqu'au jour de sa vente, même 2 ans plus tard.
2. Faire une régularisation de stock au 31 décembre (le mécanisme prévu par la loi) :
	• Pour éviter que les entreprises perdent leurs achats non vendus au 31 décembre, le fisc prévoit une règle de variation de stock : au 31 décembre, l'entreprise fait l'inventaire de son stock d'occasion invendu. La valeur de ce stock invendu au 31 décembre est soustraite des achats de l'année qui se termine et ajoutée comme premier achat au 1er janvier de l'année suivante.
	• Résultat : Rien n'est perdu si l'entreprise tient un inventaire précis de son stock au 31 décembre !

Ce que vous devez coder dans votre ERP

• Par défaut pour l'occasion : Configurez votre ERP sur le calcul coup par coup (article par article) grâce à votre table de stock unitaire / livre de police. C'est la méthode la plus avantageuse et la plus simple, car le prix d'achat suit l'article indéfiniment.
• Si l'utilisateur active l'option « Globalisation » : Affichez une alerte dans votre module de clôture annuelle au 31 décembre pour intégrer automatiquement la valeur du stock d'occasion restant au 1er janvier de l'année N+1.
Souhaitez-vous que je vous résume le fonctionnement complet de la clôture comptable annuelle à intégrer dans votre ERP (reports à nouveau, numérotation des factures, inventaire) ? oui:


fonctionnement complet de la clôture comptable annuelle

En France, la clôture comptable annuelle est un processus strictement encadré par le Code de commerce et le Code général des impôts (norme NF525 / loi anti-fraude à la TVA). Une fois qu'un exercice comptable est clôturé dans votre ERP, aucune écriture, facture ou paiement de cette année-là ne peut plus être ajouté, modifié ou supprimé.
Voici les 6 étapes obligatoires à coder dans le module de clôture annuelle de votre logiciel ERP :

1. Vérifications préalables (Contrôles de cohérence avant verrouillage)

Avant d'autoriser l'utilisateur à cliquer sur « Clôturer l'exercice 2026 », votre ERP doit exécuter un audit automatique :
• Aucune facture en brouillon : Toutes les factures de vente de l'exercice doivent être soit validées, soit supprimées (si restées à l'état de brouillon sans numéro).
• Continuité de la numérotation : Vérifier qu'il n'y a aucun « trou » dans la séquence des numéros de factures (FAC-2026-0001 à FAC-2026-XXXX).
• Rapprochement bancaire et lettrage : Vérifier que les encaissements et paiements sont bien rattachés aux factures correspondantes.
• Inventaire des stocks (au 31/12) : Figer la quantité et la valeur d'achat (HT) de tous les produits en stock au dernier jour de l'exercice. C'est ce chiffre qui détermine le bénéfice réel et évite de perdre les achats non vendus (notamment en TVA sur marge).

2. Calcul du résultat de l'exercice (Bénéfice ou Perte)

Votre ERP additionne tous les comptes de gestion de l'année (du 1er janvier au 31 décembre) :
• Total des Produits (Classe 7) : Chiffre d'affaires HT vendu durant l'année.
• Total des Charges (Classe 6) : Achats de marchandises HT (corrigés de la variation de stock), loyers, logiciels, salaires, impôts, amortissements du matériel.
• Résultat net = Produits − Charges :
	• Si positif : Bénéfice (compte 120000).
	• Si négatif : Déficit / Perte (compte 129000).

3. Verrouillage définitif de l'exercice (Inaltérabilité)

C'est l'étape technique la plus importante pour la conformité fiscale :
• Statut de l'exercice : Passe de OPEN à CLOSED (fiscal_years.status = 'CLOSED').
• Blocage en base de données : Toute tentative d'insertion, de modification (UPDATE) ou de suppression (DELETE) sur les tables invoices, payments, accounting_entries et police_book_entries dont la date appartient à l'exercice clôturé doit être rejetée par le système.
• Signature numérique (Chaînage) : Générer une empreinte cryptographique (hash SHA-256) scellant le total de l'exercice et les écritures de clôture.

4. Génération de l'Archive Fiscale (Fichier FEC)

Dès la clôture validée, votre ERP doit générer automatiquement le FEC (Fichier des Écritures Comptables), obligatoire en France en cas de contrôle fiscal (article L.47 A-I du Livre des procédures fiscales) :
• Format : Fichier .txt ou .csv (séparateur tabulation ou |) encodé en UTF-8.
• Structure imposée par la loi : Doit contenir exactement 18 colonnes normalisées dans un ordre précis (JournalCode, JournalLib, EcritureNum, EcritureDate, CompteNum, CompteLib, CompAuxNum, CompAuxLib, PieceRef, PieceDate, EcritureLib, Debit, Credit, EcritureLet, DateLet, ValidDate, Montantdevise, Idevise).
• Nom du fichier normalisé : [SIREN]FEC[AAAAMMJJ].txt (ex: 123456789FEC20261231.txt).

5. Ouverture du nouvel exercice et « À-Nouveaux » (Reports)

Une fois 2026 clôturé, votre ERP initialise automatiquement l'exercice 2027 en effectuant la bascule des comptes :

Type de compte	Ce qui se passe au 1er janvier 2027
Comptes de Charges et Produits (Ventes, Achats, Frais - Classes 6 et 7)	Remis à 0,00 € pour démarrer le calcul de la nouvelle année.
Comptes de Bilan (Banque, Caisse, Dettes fournisseurs, Créances clients, Crédit de TVA, Capital, Stock - Classes 1 à 5)	Reportés à l'identique dans un journal spécial appelé « Journal des À-Nouveaux (AN) » daté du 01/01/2027.


6. Gestion des compteurs de numérotation au 1er janvier

Selon le paramétrage choisi par l'entreprise dans votre ERP :
• Option 1 (Chronologie annuelle — la plus courante) : Le compteur de factures repart à 0001 avec le préfixe de la nouvelle année (FAC-2027-0001).
• Option 2 (Chronologie continue sur toute la vie de l'entreprise) : Le compteur continue sans interruption (FAC-2026-0842 → FAC-2027-0843).
• Note importante : L'entreprise peut commencer à facturer sur janvier 2027 même si la clôture officielle de 2026 n'est pas encore terminée (en pratique, les comptables clôturent l'année N entre février et avril de l'année N+1). Votre ERP doit donc permettre d'avoir deux exercices ouverts simultanément (l'exercice N en cours de révision et l'exercice N+1 en cours d'exploitation).


En globalisation, la TVA se calcule chaque mois uniquement sur la différence entre l'argent entré (ventes) et l'argent sorti (achats) durant ce même mois, peu importe quel produit a été vendu.
Voici un exemple chiffré très simple sur 2 mois pour visualiser exactement ce qui se passe sur votre compte bancaire :

Exemple concret : Vous achetez 10 téléphones en Novembre et les vendez en Décembre

• En Novembre (Le mois où vous achetez le stock) :
	• Vous achetez 10 téléphones d'occasion à 100 € pièce = 1 000 € d'achats.
	• Vous ne vendez rien en novembre = 0 € de ventes.
	• Calcul de marge du mois : 0 € - 1 000 € = -1 000 € (marge négative).
	• TVA à payer en novembre : 0,00 €. (Votre ERP garde en mémoire que vous avez un « crédit de marge négative » de -1 000 € à utiliser en décembre).
• En Décembre (Le mois où vous vendez le stock) :
	• Vous vendez ces 10 téléphones à 160 € pièce = 1 600 € de ventes.
	• Vous n'achetez rien en décembre = 0 € d'achats.
	• Comment le calcul se fait :
		• Avec le report du mois précédent (au sein de la même année) : Votre ERP reprend les -1 000 € de novembre. Votre marge réelle est donc 1 600 € - 1 000 € = 600 €. Vous payez la TVA uniquement sur ces 600 € (soit 100 € de TVA).
		• Le piège si vous passez de Décembre à Janvier (sans report d'une année sur l'autre) : Si les achats de 1 000 € avaient eu lieu en Décembre et les ventes de 1 600 € en Janvier, les -1 000 € seraient effacés au 31 décembre (sans inventaire de stock). En Janvier, le logiciel calculerait 1 600 € de ventes - 0 € d'achats = 1 600 € de marge, et vous paieriez 266,67 € de TVA sur la totalité des 1 600 € au lieu de 100 € !

Ce qu'il faut retenir en une phrase

• En méthode coup par coup (produit par produit) : chaque téléphone garde son prix d'achat de 100 € collé à lui pour toujours, même si vous le vendez 6 mois plus tard.
• En méthode globalisation : les prix d'achat ne sont pas collés aux produits, ils sont mis dans un « pot commun » mensuel qui remet les compteurs à zéro chaque 31 décembre.

"
