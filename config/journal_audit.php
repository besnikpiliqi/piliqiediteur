<?php

/*
|--------------------------------------------------------------------------
| Clé du journal d'audit
|--------------------------------------------------------------------------
|
| La clé HMAC qui chaîne les entrées du journal d'audit des sociétés (voir
| JournalAudit) : au moins 32 octets aléatoires encodés en base64, avec
| son identifiant, inscrit dans chaque entrée. Dédiée au journal, distincte
| de APP_KEY et de la clé de signature fiscale ; elle n'existe que dans
| l'environnement du serveur, jamais dans le dépôt ni en base. Sans elle,
| aucune entrée ne s'inscrit, et l'action qui devait l'inscrire est
| refusée.
|
| Une clé remplacée reste dans les clés précédentes (JSON {identifiant:
| clé}) tant que des entrées qu'elle a chaînées sont conservées : la
| vérification du journal en a besoin (voir app:verifier-journal-audit).
|
*/

return [

    'cle' => env('JOURNAL_AUDIT_CLE'),

    'cle_id' => env('JOURNAL_AUDIT_CLE_ID'),

    'cles_precedentes' => json_decode((string) env('JOURNAL_AUDIT_CLES_PRECEDENTES', '{}'), true) ?: [],

];
