<?php

/*
|--------------------------------------------------------------------------
| Dossiers des clés publiques épinglées
|--------------------------------------------------------------------------
|
| Chaque fichier .pem du dossier du site servi est une clé publique de
| l'éditeur (voir SignataireFiscal) : resources/caisse-securisee/cles pour
| le site réel, resources/caisse-securisee/cles-demonstration pour le site
| de démonstration, jamais les deux (lot DEMO-COPIE, choix 12). Seules ces
| clés font foi pour vérifier une signature, et la clé privée n'est
| utilisée que si sa clé publique s'y trouve : un document signé par la
| démonstration ne se vérifie jamais comme un document réel. Une clé
| retirée du service y reste pendant toute la durée de conservation des
| données qu'elle a signées (six ans pour une archive d'une copie).
|
| Ce fichier se charge avant config/piliqi.php : il lit PILIQI_SITE dans
| l'environnement, avec la même liste fermée de valeurs que SiteDePiliqi ;
| pour une autre valeur, il n'épingle aucune clé, et le serveur refuse de
| démarrer (voir SiteDePiliqiServiceProvider).
|
*/

$dossiers = [
    'reel' => resource_path('caisse-securisee/cles'),
    'demonstration' => resource_path('caisse-securisee/cles-demonstration'),
];

$clesDuDossier = static fn (?string $dossier): array => $dossier === null ? [] : collect(glob($dossier.'/*.pem') ?: [])
    ->mapWithKeys(fn (string $fichier): array => [basename($fichier) => (string) file_get_contents($fichier)])
    ->all();

$dossierClesPubliques = $dossiers[(string) env('PILIQI_SITE', 'reel')] ?? null;

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
    | rien n'est signé : SignataireFiscal lève
    | ModuleFiscalNonConfigureException au lieu de signer.
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
    | Le contenu de chaque fichier .pem du dossier du site servi, par nom de
    | fichier ; et, sur chaque site, celui des clés de démonstration, que
    | le site réel refuse pour signer (voir SiteDePiliqiServiceProvider).
    |
    */

    'cles_publiques' => $clesDuDossier($dossierClesPubliques),

    'cles_publiques_de_demonstration' => $clesDuDossier($dossiers['demonstration']),

];
