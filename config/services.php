<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     | VIES, le service de la Commission européenne qui vérifie les n° de
     | TVA de l'Union (voir ClientVies) : son API REST, et le délai en
     | secondes au-delà duquel il est tenu pour indisponible.
     */
    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
        'delai' => 10,
    ],

    /*
     | Le répertoire Sirene de l'INSEE, qui contrôle l'existence d'une
     | société et de ses établissements (voir InseeSirene et
     | VerificationServiceProvider) : son pilote (insee, ou faux pour le
     | développement et les tests, jamais en production), l'adresse de son
     | API, la clé d'intégration de l'éditeur (portail-api.insee.fr), et le
     | délai en secondes au-delà duquel il est tenu pour indisponible.
     */
    'sirene' => [
        'pilote' => env('SIRENE_PILOTE', 'insee'),
        'url' => env('INSEE_SIRENE_URL', 'https://api.insee.fr/api-sirene/3.11'),
        'cle' => env('INSEE_SIRENE_CLE'),
        'delai' => 10,
    ],

    /*
     | Le registre national des entreprises de l'INPI, qui donne le
     | représentant légal d'une entreprise et l'adresse de son siège, où
     | part le courrier recommandé de la vérification (voir InpiRne)
     | : son pilote (inpi, ou faux pour le développement et les tests,
     | jamais en production), l'adresse de son API, les identifiants de
     | l'éditeur, et le délai en secondes au-delà duquel il est tenu pour
     | indisponible.
     */
    'rne' => [
        'pilote' => env('RNE_PILOTE', 'inpi'),
        'url' => env('INPI_RNE_URL', 'https://registre-national-entreprises.inpi.fr/api'),
        'identifiant' => env('INPI_RNE_IDENTIFIANT'),
        'mot_de_passe' => env('INPI_RNE_MOT_DE_PASSE'),
        'delai' => 10,
    ],

    /*
     | L'envoi recommandé, par l'API de La Poste (voir
     | ServiceEnvoiRecommande) : son pilote, faux seulement tant que le
     | contrat de l'éditeur avec La Poste n'est pas branché (jamais en
     | production), l'adresse et la clé de son API, et le secret qui signe
     | ses rappels (voir RappelsDesPrestatairesController).
     */
    'recommande' => [
        'pilote' => env('RECOMMANDE_PILOTE'),
        'url' => env('RECOMMANDE_URL'),
        'cle' => env('RECOMMANDE_CLE'),
        'secret_rappel' => env('RECOMMANDE_SECRET_RAPPEL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
