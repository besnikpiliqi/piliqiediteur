<?php

use App\Domain\Tenancy\Support\ValeursLues;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Les pièces déposées pour l'examen de la qualité de représentant
        // d'une société (voir DeposerUnePieceAction) : chiffrées, jamais
        // servies, effacées dès la décision de l'éditeur.
        'verification' => [
            'driver' => 'local',
            'root' => storage_path('app/private/verification'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // Les pièces du fournisseur jointes aux factures d'achat et aux
        // avoirs fournisseur (voir PiecesDuFournisseur) : un dossier par
        // magasin, des noms tirés par l'application, jamais servies que
        // par leur adresse de téléchargement autorisée ; conservées comme
        // les documents.
        'pieces_fournisseur' => [
            'driver' => 'local',
            'root' => storage_path('app/private/pieces-fournisseur'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // Les fichiers préparés par le téléchargement des documents par
        // période (voir PreparerLeTelechargementDesDocumentsJob) : un ZIP
        // ou un CSV par demande, servi seulement au compte qui l'a
        // demandé, effacé sept jours après (voir
        // app:effacer-telechargements-de-documents).
        'telechargements' => [
            'driver' => 'local',
            'root' => storage_path('app/private/telechargements'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(ValeursLues::texte(env('APP_URL', 'http://localhost')), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
