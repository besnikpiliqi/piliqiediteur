# À vérifier après l'analyse wf_369666ad-020 (question de l'éditeur, 02/11/2026 vers 11 h 35 UTC)

Question : « ce que je veux, c'est être rassuré de ne pas avoir de problème avec l'État, ou c'est important que l'État regarde ce que tu as récolté et ce que tu as payé comme TVA ».

1. TVA collectée : la TVA déclarée doit être exactement la somme des TVA imprimées sur les tickets, factures et avoirs (art. 283-3 du CGI : la TVA facturée est due). Vérifier dans le code des clôtures, de l'e-reporting et du futur état de TVA (étape 5) que rien ne recalcule une TVA sur un total.
2. TVA déductible : elle est celle qui figure sur la facture du fournisseur (art. 271 du CGI). Vérifier si PILIQI recalcule la TVA d'une facture d'achat depuis ses lignes (quantité × PU HT, TVA par taux) et ce qui se passe quand la facture du fournisseur porte une TVA différente d'un centime (arrondi par ligne chez lui) : faut-il pouvoir saisir la TVA du fournisseur telle qu'écrite, ou la prendre de la facture reçue par SUPER PDP (étape 2) ?
3. Déclaration CA3 : vérifier dans la notice officielle (formulaire 3310-CA3, impots.gouv.fr) que les montants se déclarent en euros arrondis, sans centimes, et le citer.
