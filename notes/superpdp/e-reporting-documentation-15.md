# SUPER PDP : documentation « E-reporting » (page /documentation/15), texte collé par l'éditeur le 18/11/2026

Source : https://www.superpdp.tech/documentation/15 (documentation publique ; l'outil de lecture de Claude ne récupère que le titre de la page, qui se charge dans le navigateur). Références voisines du même site : Calendrier (/documentation/13), Synchronisation (/documentation/14), Mandat de facturation (/documentation/19), FAQ (/documentation/10), API AFNOR (/documentation/9), Référence OpenAPI (https://www.superpdp.tech/openapi/#superpdp/tag/ereporting). À relire à l'étape 2 : Calendrier et la balise « ereporting » de l'OpenAPI.

## Règles de la page (résumé fidèle)
- Deux obligations : e-invoicing et e-reporting (envoyer les données de transaction et de paiement à l'Administration). Base légale : articles 289 E à 290 A de l'annexe II du CGI.
- « Le calendrier de déclaration de l'e-reporting dépend du régime de TVA de l'entreprise. Pour faire fonctionner l'e-reporting, il faut paramétrer le régime de TVA au niveau de son entreprise. »
- Facture B2B envoyée par SUPER PDP : acheminée au destinataire ET copie à l'Administration (« Flux 1 ») : rien à faire de plus.
- Facture B2C (facultative) : acceptée par l'API des factures, non acheminée ; SUPER PDP extrait la transaction (de la facture) et le paiement (du message de cycle de vie « Encaissée (212) »). « Il faut veiller à ne pas envoyer les données d'e-reporting en double, et donc ne pas déclarer les données de transaction et de paiement par ailleurs. » Détection : note BAR de valeur B2C. Les factures mixtes (biens et services) ne sont pas gérées. Le message 212 est à envoyer à l'encaissement, ou juste après la création d'une facture déjà encaissée à l'émission ; sans effet pour une vente de marchandises.
- B2Bi (internationales) : achat : API `b2bint_invoices` (données d'e-reporting) ou API AFNOR ; pas d'envoi direct de factures d'achat, lignes facultatives. Vente : API `invoices` (facture) ou `b2bint_invoices` (données) ou API AFNOR (facture ou données). (Correction : l'API s'écrit `b2bint_invoices`.)
- Ventes B2C sans facture (caisse) : obligation de déclarer transactions (le ticket) et paiements (les encaissements). Pas de paiements à déclarer pour des ventes de biens (TVA exigible à la livraison) ni sous l'option pour les débits (article 290 A de l'annexe II du CGI) : restaurant = transactions seules ; salon de coiffure = transactions et paiements, sauf option pour les débits.
- SUPER PDP stocke, accumule et déclenche seule l'envoi à l'Administration (« Flux 10.3 » et « Flux 10.4 ») selon le calendrier du régime de TVA.
- Granularité minimale : la journée. Autorisé (restaurant à 2 caisses) : chaque soir les données agrégées des 2 caisses ; chaque soir en 2 fois (une par caisse) ; 2 fois par jour pour chaque caisse (4 envois) ; tous les 3 jours les données agrégées avec granularité jour par jour (1 envoi). Interdit : le 10 du mois en une fois, agrégé, avec la date du 10 ; en fin de mois les données du mois avec granularité par jour quand le régime impose de déclarer tous les 10 jours (calendrier non respecté, envoi trop tardif).

## Exemple officiel : transactions (POST https://api.superpdp.tech/v1.beta/b2c_transactions, Bearer)
```
{ "data": [ {
  "category_code": "TLB1", "currency": "EUR", "date": "2026-06-05", "role_code": "SE",
  "tax_exclusive_amount": "15.00", "tax_total": "1.55",
  "tax_subtotals": [
    { "tax_percent": "5.50", "tax_total": "0.55", "taxable_amount": "10.00" },
    { "tax_percent": "20.0", "tax_total": "1.00", "taxable_amount": "5.00" } ] } ] }
```
## Exemple officiel : paiements (POST https://api.superpdp.tech/v1.beta/b2c_payments, Bearer)
```
{ "data": [ { "company_id": 1, "date": "2026-06-05",
  "subtotals": [ { "amount": "35.00", "currency_code": "EUR", "tax_percent": "20.00" } ] } ] }
```

## Ce que ces exemples apprennent à PILIQI (étape 2, à confirmer au bac à sable)
1. Aucun champ ne désigne un magasin ou un SIRET ; seul le paiement porte `company_id` (l'entreprise SUPER PDP de la société, obtenue à la connexion OAuth). SUPER PDP accumule par entreprise (par société, donc par SIREN). Plusieurs envois le même jour sont expressément autorisés (« un par caisse ») : PILIQI peut garder une déclaration par magasin et par journée clôturée, envoyée sous la connexion de la société ; le magasin n'apparaît pas dans l'envoi.
2. Une ligne de transaction par catégorie (`category_code` : TLB1 livraison de biens ; TPS1 prestation de services d'après le CLAUDE.md) avec les sous-totaux par taux (`taxable_amount`, `tax_total`), montants et taux écrits comme des chaînes à décimales (« 5.50 », « 20.0 » dans la transaction, « 20.00 » dans le paiement : la forme attendue est à lire dans l'OpenAPI). `role_code` « SE » (vendeur).
3. Les exemples ne montrent aucune clé d'idempotence : il faut lire l'OpenAPI pour savoir comment éviter un envoi en double (règle de la page), comment retrouver l'accusé de réception d'un envoi, et comment corriger une journée déjà envoyée ou renvoyer après une panne ; le calendrier (page 13) dit jusqu'à quand un envoi est « trop tardif » pour chaque régime.
4. Une vente à un particulier ne se déclare QU'UNE fois : par les données de la clôture du jour, jamais aussi par l'envoi de la facture (qui déclencherait l'extraction automatique de SUPER PDP).
