# Espace « Charges du magasin » : demande et décisions de l'éditeur (2026-10-11)

## Demande (verbatim)

> qu est ce que tu pense pour creer une espace pour la societe, donc l'espace "les charges du magasin":  Par exemple, une société possède trois magasins. Chaque magasin enregistre ses propres charges :
> Catégorie	Exemples
> Loyer	Loyer du local commercial
> Électricité et eau	Factures d'énergie
> Salaires	Salaires et cotisations patronales
> Assurances	Assurance professionnelle
> Téléphone et Internet	Abonnements
> Logiciels	Abonnements professionnels
> Entretien	Nettoyage, réparations
> Frais bancaires	Commissions, frais de compte
> Transport	Livraison, déplacements
> Autres charges	Dépenses diverses. donc pour chaque charge il faut telecharger les Pièces justificatives et mettre le prix total et les charge si existe et la tva pour calcula la tva à recuperer et calculer tous les charge du magasin

## Avis de Claude donné à l'éditeur (résumé)
- Une charge = une facture fournisseur dont les lignes n'entrent pas en stock, avec une catégorie ; même moteur de TVA que les achats (aucun second calcul).
- TVA récupérable décidée par le moteur et expliquée : pas de TVA sur salaires, assurances, la plupart des frais bancaires ; loyer taxé seulement sur option du bailleur ; exclusions du droit à déduction (véhicules de tourisme, logement du personnel, cadeaux au-delà de 73 € par bénéficiaire et par an…) ; TVA d'un service déductible au paiement sauf option du fournisseur pour les débits ; rien de récupérable en franchise. Règles à vérifier sur les textes avant de les coder.
- Pièces justificatives : PDF ou photo, rangées par magasin (jamais visibles d'un autre), conservées 10 ans, jamais supprimées après validation, chaque ajout tracé.
- SUPER PDP (étape 2) : factures de charges reçues électroniquement, attribuées au bon magasin, deviennent des brouillons avec la facture comme pièce.
- Charges récurrentes (loyer, abonnements) : modèle qui crée un brouillon chaque mois.
- Droits : le responsable saisit et valide les charges de son magasin ; un vendeur n'y a pas accès sauf autorisation ; l'expert-comptable consulte et télécharge ; une charge validée ne se modifie que par une correction tracée.

## Décisions de l'éditeur (réponses aux questions)
- Charges communes à plusieurs magasins : **« Saisie société, répartie »** — le propriétaire ou un administrateur autorisé saisit la charge au niveau de la société et la répartit entre les magasins (parts égales ou pourcentages) ; chaque magasin voit sa part dans ses totaux ; la pièce reste au niveau société.
- Salaires : **« Totaux du mois »** — un total par magasin et par mois (salaires bruts + cotisations patronales), avec le récapitulatif du logiciel de paie comme pièce ; visible du propriétaire, des administrateurs autorisés et de l'expert-comptable seulement, pas des responsables ni des vendeurs ; aucun bulletin individuel.
- Calendrier : **« Avec les achats du moteur TVA »** — dans les lots achats du moteur de TVA (M5 à M7), avec les pièces justificatives avancées de l'étape 8 ; un seul modèle pour toutes les factures fournisseurs ; réception automatique par SUPER PDP à l'étape 2.

## Conséquences à porter
- CLAUDE.md, « Produit » : la répartition d'une charge de société entre ses magasins est une nouvelle écriture explicite entre magasins (société → parts par magasin), à ajouter à la liste des exceptions, filtrée et testée.
- CLAUDE.md : salaires = exception au « responsable a tous les droits du magasin » (les totaux de salaires ne lui sont pas visibles).
- Conception du moteur de TVA (scratchpad/tva/conception-finale.md) : ajouter les charges aux lots M5 à M7 et avancer les pièces justificatives de l'étape 8 ; feuille de route (étapes 6, 7 et 8) à ajuster.
