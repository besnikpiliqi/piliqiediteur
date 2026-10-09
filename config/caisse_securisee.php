<?php

/*
|--------------------------------------------------------------------------
| Dossier des clés publiques épinglées
|--------------------------------------------------------------------------
|
| Chaque fichier .pem de ce dossier, versionné dans le dépôt, est une clé
| publique de l'éditeur (voir SignataireFiscal). Seules ces clés font foi
| pour vérifier une signature, et la clé privée n'est utilisée que si sa
| clé publique s'y trouve. Une clé retirée du service y reste pendant
| toute la durée de conservation des données qu'elle a signées.
|
*/

$dossierClesPubliques = resource_path('caisse-securisee/cles');

return [

    /*
    |--------------------------------------------------------------------------
    | Clé privée de signature de l'éditeur
    |--------------------------------------------------------------------------
    |
    | Clé ECDSA P-256 au format PEM, encodée en base64 sur une seule ligne
    | (voir php artisan caisse:generer-cle), avec sa phrase secrète si elle
    | est chiffrée. Elle n'existe que dans l'environnement du serveur :
    | jamais dans le dépôt, jamais en base, jamais affichée. Sans elle,
    | aucune donnée fiscale n'est signée et la vente est refusée.
    |
    */

    'cle_privee' => env('CAISSE_SECURISEE_CLE_PRIVEE'),

    'phrase_secrete' => env('CAISSE_SECURISEE_PHRASE_SECRETE'),

    'dossier_cles_publiques' => $dossierClesPubliques,

    /*
    |--------------------------------------------------------------------------
    | Clés publiques épinglées
    |--------------------------------------------------------------------------
    |
    | Le contenu de chaque fichier .pem du dossier, par nom de fichier.
    |
    */

    'cles_publiques' => collect(glob($dossierClesPubliques.'/*.pem') ?: [])
        ->mapWithKeys(fn (string $fichier): array => [basename($fichier) => (string) file_get_contents($fichier)])
        ->all(),

];
