# Série 8 : retours de l'éditeur après essai local (2026-10-17) — traduction française de référence

(Texte d'origine gardé à part, seulement pour vérifier une interprétation : demande-editeur-original.md. Règle de l'éditeur : tout texte écrit — logiciel, documents, réponses — est en français, jamais en albanais.)

1. Caisse : ajouter « Avoir » aux modes de paiement. Aujourd'hui, saisir un avoir suffit à payer, mais il est plus clair de choisir explicitement le mode de paiement « Avoir ». Même chose pour les factures.

2. Retour d'un ticket ou d'une facture : aujourd'hui, le client comptoir est choisi automatiquement, ce qui ne va pas, car on ne sait pas de quel client comptoir il s'agit. Le retour doit se chercher uniquement par le numéro du ticket (TK) ou de la facture (FAC), et le client s'affiche ensuite d'après ce document. Un client peut exister sans avoir d'achat : il faut donc supprimer la recherche par client et chercher par numéro de facture ou de ticket.

3. PDF de la facture : ajouter la référence du produit. Si la place manque, la désignation passe à la ligne, mais la référence reste entière, sur une seule ligne. Ajouter aussi le ou les modes de paiement : aujourd'hui, seul le paiement par avoir apparaît ; il faut aussi les paiements en espèces, carte bancaire, virement, y compris un paiement en plusieurs parties. Dans le tableau (DÉSIGNATION, QTÉ, PU HT, PU TTC, TAUX TVA, TOTAL HT, TVA, TOTAL TTC), certaines colonnes sont en trop : retirer PU TTC et TOTAL TTC (et peut-être TVA), qui figurent déjà dans les totaux en bas.

4. L'identité de la société (par exemple « SOCIÉTÉ FICTIVE 912442316 SARL à capital variable, au capital de 5 000,00 € ») doit être en bas du PDF. Les mentions (« Pénalités de retard » et toutes les autres) doivent être placées tout en bas de la page. La page doit être obligatoirement au format A4 réel, même avec une seule ligne de produit : elle ne se réduit jamais.

5. Tableau de bord : après « Bonjour (nom) », indiquer le rôle de la personne (vendeur, administrateur, etc.).

6. Un administrateur ne doit pas pouvoir faire de ventes.

7. Un administrateur doit voir les caisses de tous (« Les caisses ») et non « Ma caisse ».

8. Factures d'achat : permettre de joindre le document de la facture du fournisseur (le télécharger dans PILIQI) pour le garder comme justificatif.

9. Plus tard : créer automatiquement les produits depuis la facture d'achat (PDF ou Excel), en trouvant les produits qu'elle contient et en les entrant directement en stock. L'éditeur donnera ses règles quand Claude les lui demandera.

11. Ventes à 0 % de TVA : afficher le texte de loi qui justifie l'absence de TVA (vente sans TVA, TVA sur la marge…) sur la facture et dans le détail de la facture. Permettre de choisir le type de TVA ou le texte de loi au moment d'ajouter le produit aux lignes, avant la validation (Claude recense tous les cas de TVA à 0 % et leurs règles), puis l'imprimer sur le PDF.

12. Dans la saisie des lignes (facture, devis, ticket…), ne pas afficher le total HT sous chaque ligne : inutile, il prend de la place, le total est en bas.

13. Ajouter un produit : au lieu d'une ligne à remplir, un clic sur « Ajouter un produit » ouvre un grand tableau des produits (50 affichés, puis « en voir plus »), qui peut occuper toute la fenêtre : référence, nom, quantité restante en stock, prix HT et TTC, taux de TVA (%), bénéfice. L'utilisateur choisit les colonnes affichées (Claude ajoute les autres colonnes utiles). On peut sélectionner plusieurs produits et les ajouter tous d'un coup ; le total HT et TTC de la sélection s'affiche. Filtres : les plus récents, seulement en stock, seulement hors stock (ces derniers seulement si le propriétaire autorise la vente sans stock, selon ses réglages).

14. À l'abandon d'une vente avant validation, l'alerte dit : « Abandonner la vente ? Les lignes saisies sont effacées, et l'abandon de la vente est inscrit au journal des événements de la caisse. » Pourquoi l'inscrire au journal ? C'est peut-être bien ainsi, mais l'éditeur veut être convaincu par la raison.

15. Clôture de caisse : les modes de paiement n'apparaissent pas ; il faut afficher les montants en espèces, carte, etc. Et il reste un gros problème de calcul des prix en caisse : 50 / 1,2 = 41,666666667, l'arrondi doit donner 41,67, mais PILIQI affiche 41,66, et la TVA a un centime de trop : 5,84 au lieu de 5,83.
[La suite du point 15, écrite en français par l'éditeur, est reprise mot pour mot :]
Je veux que tu corriges définitivement le problème des écarts de 1 centime dans PILIQI, et pas seulement dans l'interface de caisse.
Le principe fondamental est le suivant : les montants monétaires doivent être calculés selon une politique d'arrondi unique, cohérente et documentée, puis enregistrés comme valeurs de référence des opérations validées.
Je ne veux pas que chaque écran recalcule indépendamment le HT à partir du TTC, car cela peut provoquer des différences entre la caisse, les tickets, les factures, les avoirs, les stocks, le tableau de bord et les clôtures Z.
Voici mes exigences :
1. Séparer strictement les prix d'achat et les prix de vente. Le coût d'achat HT du produit ne doit jamais être confondu avec son prix de vente HT.
2. Lors de l'enregistrement d'un produit, conserver les prix de référence nécessaires, mais ne pas utiliser ces prix historiques pour recalculer des ventes déjà effectuées.
3. Lors de la validation d'une vente, calculer et enregistrer les montants HT, TVA et TTC des lignes et du ticket, avec une méthode d'arrondi cohérente.
4. Utiliser des calculs décimaux exacts, jamais des flottants binaires pour les montants financiers.
5. Lors de l'affichage d'une vente déjà validée, d'une facture, d'un avoir, du tableau de bord ou d'une clôture Z, réutiliser les montants fiscaux enregistrés. Ne pas recalculer les montants depuis les prix affichés du catalogue. (peut-être tu fais déjà ça ?)
6. Garantir la cohérence des égalités : HT + TVA = TTC, selon les règles d'arrondi et de répartition choisies.
7. Pour les avoirs, reprendre les montants fiscaux de la vente d'origine lorsque c'est applicable, notamment pour les remboursements complets, afin d'éviter les différences d'arrondi.
8. Pour les clôtures Z, additionner les données fiscales des ventes et avoirs concernés, sans recalculer la TVA à partir du total TTC global.
9. Afficher les moyens de paiement dans la clôture : espèces, carte bancaire, virement, chèque et autres moyens configurés. Distinguer les ventes des encaissements, gérer les paiements mixtes et les remboursements.
10. Vérifier l'ensemble des calculs existants dans les modules Achats, Ventes, Caisse, Factures, Avoirs, Stocks, Tableau de bord et Clôtures.
Cas de test obligatoire : 50 € TTC à 20 % doit donner 41,67 € HT et 8,33 € de TVA avec l'arrondi classique au centime.
Je veux également des tests avec plusieurs lignes, plusieurs taux, quantités, remises, remboursements partiels et ventes concurrentes.
Ne corrige pas seulement les chiffres affichés. Identifie la source exacte des écarts, corrige le moteur de calcul et prouve par les tests que les mêmes opérations donnent les mêmes montants partout dans PILIQI.
Ne modifie pas rétroactivement les documents fiscaux déjà validés : prévois des corrections traçables lorsque nécessaire.

16. Tableau de bord : afficher les factures à encaisser (pas encore payées), visibles au premier coup d'œil comme une alerte, pour tout le monde. Car un paiement peut être enregistré puis contre-passé, et la facture rester ensuite impayée pour toujours. Portée : le propriétaire voit toutes celles de la société ; un administrateur toutes celles de sa portée ; un responsable de magasin toutes celles de son magasin, y compris celles des vendeurs qu'il gère ; un vendeur les voit s'il est autorisé à faire des contre-passations.

17. Administrateur : même avec la case « modifier les membres » cochée, il ne peut pas les modifier, et l'écran indique que seul le propriétaire le peut : sans doute une erreur. L'administrateur peut créer l'équipe avec des autorisations toutes prêtes, sans pouvoir les choisir ni les modifier. MAIS il ne doit jamais créer un autre administrateur : seul le propriétaire le fait. Le responsable de magasin doit pouvoir créer et modifier ses vendeurs (salariés). MAIS dans les rôles, l'option « Responsable » n'existe pas. Un responsable peut être créé par le propriétaire, et aussi par un administrateur s'il en a l'autorisation.

18. Ne plus permettre de créer un produit depuis « Produits » ; seulement depuis une facture d'achat, car un produit doit toujours être lié à une facture d'achat. Il reste modifiable dans « Produits », mais sans pouvoir changer le fournisseur ni les données de la facture.

19. Menu « Avoirs fournisseur » : on ne peut pas créer d'avoir ; seuls ceux qui arrivent par l'API apparaissent. Il faut pouvoir créer un avoir à la main (sans API), car jusqu'en septembre rien n'est obligatoire et une société doit pouvoir retourner des produits et les sortir du stock. Les avoirs reçus par l'API apparaîtront aussi dans ce menu. Un avoir ne se fait qu'à partir d'un produit qui figure sur une facture d'achat, et il agit sur cette facture : l'avoir est lié à la facture du produit, et une trace est gardée, comme pour un avoir déclaré par l'API. Claude regarde comment le faire au mieux. Dans « Afficher la facture d'achat », montrer sous chaque produit retourné par un avoir ce qui a été retourné, avec une alerte en orange directement sous la ligne (comme le montant d'avoir affiché sous un client).

20. Membres de la société : se connecter avec un identifiant, et non avec une adresse e-mail, car l'adresse e-mail ne peut servir qu'à un seul compte. L'identifiant est unique pour chaque membre créé, quel que soit le magasin ou la société, dans une table dédiée (unique dans toute la table, pas par magasin). Un membre se connecte avec son identifiant et son mot de passe. Le propriétaire ou le responsable lui donne ses identifiants. L'identifiant est lié au magasin (magasin_id) et non à la société, car il est créé pour un magasin : si la société crée la personne directement, elle la rattache au magasin où elle travaille. Si la même personne travaille dans plusieurs magasins de la même société, on lui crée de nouveaux identifiants pour chaque autre magasin : elle se connecte avec les identifiants du magasin où elle travaille, et dans un magasin elle ne voit que ce qu'elle y a fait, jamais le reste.

21. Dans « Caisse sécurisée », l'événement n'apparaît pas quand on fait une clôture de caisse. Faut-il l'inscrire ? Faut-il inscrire chaque clic ?

22. Caisse : une loupe permet de chercher le client ; un clic ouvre le tableau des clients, avec une recherche par nom, SIREN, e-mail, etc., et un tri du plus récent au plus ancien. Par défaut, le client comptoir reste choisi.

23. Paiement en plusieurs modes (par exemple moitié en espèces, moitié autrement) : la fonction n'existe pas. Elle doit exister pour les particuliers et pour les professionnels, y compris quand la facture est créée depuis « Factures ».

24. Clôtures de caisse : afficher peut-être aussi les modes de paiement et la somme des avoirs ; l'État le demande peut-être : à Claude de confirmer.

25. Clôtures de caisse : rendre cliquables les numéros des tickets et des avoirs, pour ouvrir le document complet.

26. « Soumission e-reporting » n'affiche rien. L'éditeur veut voir à quoi ressemble l'e-reporting et ce qu'il contient : « Tickets et avoirs détaillés → Clôture Z → Totaux journaliers ventilés par nature et taux de TVA → E-reporting via la plateforme agréée. » (Ce n'est donc ni « l'État reçoit tous les tickets », ni simplement « l'État reçoit un seul total TTC ». Il reçoit des totaux journaliers structurés fiscalement.) Il demande une explication.

## Réponses de l'éditeur aux questions de clarification (2026-10-17)
- Point 20, connexion par identifiant : **« Tous sauf le propriétaire »** — responsables, vendeurs et observateurs : un identifiant unique généré par PILIQI et un mot de passe, un compte par magasin (une personne qui travaille dans deux magasins a deux identifiants et ne voit dans chacun que ce qu'elle y a fait) ; e-mail facultatif ; un accès perdu est renouvelé par le propriétaire ou un responsable (mot de passe provisoire). Les administrateurs et les experts-comptables se connectent aussi avec un identifiant, rattaché à la société (ils ne dépendent pas d'un magasin). Seul le propriétaire garde son adresse e-mail (inscription par lien).
- Point 17 : **« Garder la série 6 »** — seuls le propriétaire et le responsable du magasin créent et modifient les vendeurs et les observateurs ; l'administrateur nomme des responsables s'il y est autorisé et ne crée jamais d'administrateur. (Les constats du point 17 venaient de la version locale antérieure au lot A4.)
- Point 18 : **« Achat ou inventaire d'ouverture »** — un produit naît seulement de la validation d'une facture d'achat ou d'un inventaire d'ouverture (stock existant au démarrage, avec son coût) ; ensuite il se modifie dans « Produits », sauf le fournisseur et les données d'achat.
- Point 11 : **« Sur la facture, automatique »** — le motif de l'absence de TVA vient de la nature de la vente décidée par le moteur de TVA et sa mention légale s'imprime automatiquement ; TVA sur la marge ligne par ligne à l'étape 14 ; ventes exonérées (santé, enseignement…) toujours refusées.
