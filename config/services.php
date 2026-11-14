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
     | FournisseursExternesServiceProvider) : son pilote (insee, ou faux pour le
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

    /*
     | L'annuaire de l'Ordre des experts-comptables, qui vérifie
     | l'inscription d'un cabinet d'expertise comptable au tableau (voir
     | AnnuaireDeLOrdre) : son pilote, editeur tant qu'aucun accès
     | automatique officiel et documenté n'existe (l'éditeur vérifie chaque
     | cabinet dans sa procédure), ou faux pour le développement et les
     | tests (jamais en production).
     */
    'ordre' => [
        'pilote' => env('ORDRE_PILOTE', 'editeur'),
    ],

    /*
     | La plateforme agréée de la facture électronique (voir
     | PdpGatewayInterface et FournisseursExternesServiceProvider) : son
     | pilote, faux seulement jusqu'au raccordement de SUPER PDP (étape 2 de
     | la feuille de route), jamais en production ; sans valeur par défaut.
     */
    'pdp' => [
        'pilote' => env('PDP_PILOTE'),
    ],

    /*
     | L'antivirus des fichiers reçus (voir Antivirus et
     | AntivirusServiceProvider) : son pilote (clamav, le démon clamd du
     | serveur, ou factice pour les tests, la démonstration et le
     | développement, jamais en production), la socket Unix de clamd ou, à
     | défaut, son hôte et son port, et le délai en secondes au-delà duquel
     | il est tenu pour injoignable. Aucun secret.
     */
    'antivirus' => [
        'pilote' => env('ANTIVIRUS_PILOTE', 'clamav'),
        'clamav' => [
            'socket' => env('CLAMAV_SOCKET'),
            'hote' => env('CLAMAV_HOTE', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'delai' => (int) env('CLAMAV_DELAI', 30),
        ],
    ],

    /*
     | La boîte de test de Mailtrap (« Email Sandbox »), où le transport
     | d'envoi « mailtrap » (MAIL_MAILER=mailtrap, voir config/mail.php et
     | TransportMailtrap) dépose chaque e-mail par l'API HTTPS : le jeton
     | d'API de l'éditeur (MAILTRAP_API_TOKEN, onglet « API Tokens » de son
     | compte Mailtrap) et le numéro de la boîte (MAILTRAP_INBOX_ID, dans
     | l'adresse de sa page). Des secrets de l'environnement, jamais du
     | dépôt, d'un journal ni d'un message d'erreur.
     */
    'mailtrap' => [
        'jeton' => env('MAILTRAP_API_TOKEN'),
        'boite' => env('MAILTRAP_INBOX_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
