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
| Le site de démonstration a sa propre clé, jamais celle du site réel (lot
| DEMO-COPIE, choix 12) : l'empreinte SHA-256 (hexadécimale) de chaque clé
| de démonstration, courante ou précédente, est épinglée, une par ligne,
| dans resources/journal-audit/cles-demonstration.txt (voir
| journal-audit:epingler-la-cle), jamais la clé. Le site de démonstration
| refuse de démarrer avec une clé dont l'empreinte n'y est pas, le site
| réel avec une clé dont l'empreinte y est (voir
| SiteDePiliqiServiceProvider) : l'identifiant JOURNAL_AUDIT_CLE_ID n'est
| qu'une étiquette et ne prouve rien.
|
*/

$fichierDesEmpreintesDeDemonstration = resource_path('journal-audit/cles-demonstration.txt');

return [

    'cle' => env('JOURNAL_AUDIT_CLE'),

    'cle_id' => env('JOURNAL_AUDIT_CLE_ID'),

    'cles_precedentes' => json_decode((string) env('JOURNAL_AUDIT_CLES_PRECEDENTES', '{}'), true) ?: [],

    'fichier_des_empreintes_de_demonstration' => $fichierDesEmpreintesDeDemonstration,

    'empreintes_de_demonstration' => is_file($fichierDesEmpreintesDeDemonstration)
        ? array_values(array_filter(array_map(trim(...), file($fichierDesEmpreintesDeDemonstration) ?: []), static fn (string $ligne): bool => preg_match('/^[0-9a-f]{64}$/', $ligne) === 1))
        : [],

];
