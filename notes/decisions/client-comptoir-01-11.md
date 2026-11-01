# Client comptoir et ticket transformé en facture (message de l'éditeur du 01/11/2026, 22 h 35)

## 1. Le message, mot pour mot (avec deux captures : la fiche « Modifier Client » du client comptoir, et la liste « Clients » où il apparaît avec « Voir », « Modifier » et « Solde créditeur 0,00 € »)

« le client comptoir n'as pas besoin de ce genre d'info et meme chez le client comptoir ne doit pas pouvoir aller voir ou editer car c'est un client comptoir inconnu et c'est normal que c'est un client particulier sauf si ce client veux apres transformer son ticket de vente en facture alors la on choisi les info du client. ETTT commen je t'ai deja dis si un jour un client viens avec son ticket et veut transformer son ticket en facture alors ca doit se faire uniquement latransformation en facture en un clique sans pouvoir creer d'abort l'avoir et apres la facture. donc le client donne son ticket avec son numer de ticket et le magasin transform en facture juste avec un clique car un client comptoir ne doit pas avoir une avoir car c'est inconnu et donc comme dans la 2eme photo marque le "Solde créditeur" 0.00€ alors ce client ne doit jamais avoir un avoir car c'est inconnu. donc juste une transformation en facture en un clique avec son num de ticket »

## 2. Ce qui est déjà décidé

- `decisions/reponses-complements-14-20.md`, points 14 et 15 (11/10/2026, ligne 171 de `historique/DIRECTIVES.md`) : bouton « Transformer en facture » sur un ticket anonyme déjà émis, retrouvé par son numéro ; au clic, saisie des seules coordonnées du client ; en arrière-plan, de façon atomique et signée : avoir d'annulation total lié au ticket, dont le crédit n'est jamais remis au client ni proposé comme bon d'avoir ; nouvelle facture aux mêmes lignes ; facture payée en totalité par ce crédit (« Payé par l'avoir n° AV-… (annulation du ticket n° TK-…) ») ; seul un ticket entièrement payé se transforme. Pas encore construit : prévu au lot C-b de la chaîne TVA (`tva/plan-execution.md`), avec la case « Le client demande une facture » (point 13).
- Point 16 (11/10/2026) : un retour sur un ticket donne toujours un avoir, qui sert de bon d'avoir pour le client comptoir (`CLAUDE.md`, « Paiements et avoirs »).

## 3. Questions posées à l'éditeur le 01/11/2026 vers 22 h 40 (dans le texte du message)

1. L'avoir d'annulation créé automatiquement dans le même clic, jamais à la main, entièrement consommé par la facture (le solde du client comptoir reste à 0,00 €) : (a) le garder, comme décidé le 11/10 (recommandé) ; (b) aucun avoir du tout (facture établie à partir du ticket, ticket marqué « facturé »), voie à vérifier d'abord au regard de la caisse sécurisée et de l'e-reporting.
2. Retours au comptoir d'un client anonyme : (a) garder le bon d'avoir (point 16), sans jamais l'afficher comme un « solde créditeur » du client comptoir (recommandé) ; (b) aucun avoir au client comptoir : un retour exige d'abord de transformer le ticket en facture au nom du client.
3. Choix de Claude, contestable : le client comptoir n'apparaît plus dans la liste « Clients » (rien à y voir ni à modifier), son adresse est refusée, il reste « Client comptoir » sur ses tickets, et la colonne « Solde créditeur » ne le concerne pas.

## 4. Réponses de l'éditeur (01/11/2026, vers 22 h 50)

« 1a 2a( mais la il faut afficher qq part (peut etre dans le tableau de bord ) les avoir des client comptoires et professionel s'il reste des avoir sans regler donc qui ne sont pas encore facture pour que le proprietaire ou le magasin sache ce qu'il reste comme avoirs sans regler ) 3 oui »

- 1a : l'avoir d'annulation automatique de la transformation reste (lot C-b de la chaîne TVA).
- 2a : les bons d'avoir des retours au comptoir restent, jamais affichés comme un solde du client comptoir ; en plus, les avoirs non utilisés (bons d'avoir du comptoir et crédits des clients) s'affichent au tableau de bord du magasin et, par magasin, dans l'espace d'administration.
- 3 oui : le client comptoir sort de la liste des clients et de leurs sélecteurs, sa fiche ne se consulte ni ne se modifie.
- Application et textes de `CLAUDE.md` : `serie9/reponses-serie9.md`, dernière section (lot S9-A).
