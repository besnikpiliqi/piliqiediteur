# Demande de l'éditeur (2026-10-11), verbatim : moteur de TVA centralisé

Je souhaite améliorer le logiciel pour que la gestion de la TVA soit largement automatisée dès la création des factures d'achat et de vente.
Pour les achats :

* Prévoir un champ clair pour indiquer d'où viennent réellement les marchandises : France, autre pays de l'UE ou hors UE.
* Distinguer les marchandises des prestations de services.
* Utiliser les informations du fournisseur, le pays d'expédition, le pays de livraison, les numéros de TVA et les documents disponibles pour déterminer le régime fiscal.
* Calculer automatiquement la TVA facturée, l'autoliquidation éventuelle, la TVA déductible, le montant à payer et le coût du stock.

Pour les ventes aux professionnels :

* Prévoir le pays de destination réelle des marchandises ou le lieu pertinent pour les services.
* Distinguer les clients particuliers et professionnels.
* Vérifier les conditions d'application de la TVA française, des livraisons intracommunautaires, des exportations, des ventes à distance et du régime OSS lorsque nécessaire.
* Calculer automatiquement la TVA et afficher les mentions légales appropriées.

Fonctionnement souhaité :

* Préremplir les informations connues pour éviter des saisies répétitives.
* Demander uniquement les informations manquantes ou importantes.
* Afficher clairement le régime de TVA appliqué et la justification du calcul.
* Bloquer ou signaler les opérations lorsque des informations indispensables manquent.
* Ne jamais considérer que le pays du fournisseur ou du client suffit à lui seul pour déterminer la TVA.
* Conserver une traçabilité des décisions fiscales et créer des tests automatisés pour les différents scénarios.

Je veux un moteur de TVA centralisé, partagé entre Achats, Ventes, Stocks et les futures déclarations fiscales, afin d'éviter des calculs contradictoires entre les modules.
Analyse l'architecture existante avant de développer et propose la solution la plus fiable, ergonomique et évolutive, adaptée à la réglementation française et européenne.

## Contexte déjà décidé ou noté le même jour (scratchpad/decisions/reponses-complements-14-20.md, points 7 à 16)
- Achats à l'étranger : autoliquidation des acquisitions intracommunautaires et des achats à un fournisseur non établi, importations hors UE (TVA d'importation autoliquidée, droits de douane dans le coût du stock, référence du document de douane), e-reporting B2BInt sens « in ».
- Ventes : circuit de transmission selon les trois conditions officielles de la facture électronique (assujettis établis en France, champ de la TVA française, règles de facturation françaises) ; champs « assujetti à la TVA » et « entité publique » sur le client ; e-reporting B2C agrégé et B2BInt facture par facture ; article 290 du CGI (version du 21/02/2026) et FAQ impots.gouv.fr « clients à la fois entreprises et particuliers ».
- Facture demandée par un particulier et « Transformer en facture » (avoir d'annulation, paiement total par l'avoir).
