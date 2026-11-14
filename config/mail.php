<?php

/*
 | Le délai, en secondes, au-delà duquel un serveur d'envoi qui ne répond
 | pas fait échouer l'envoi (MAIL_TIMEOUT, 10 par défaut) : nettement sous
 | les 60 secondes au bout desquelles `php artisan queue:listen` coupe une
 | tâche, et sous la durée maximale de la tâche d'envoi d'un document (voir
 | EnvoyerDocumentJob::$timeout), pour que l'erreur du serveur s'écrive au
 | lieu de faire tomber le travailleur. Toujours strictement positif : une
 | variable vide, « null », nulle ou négative donne 10 secondes, un délai
 | de 0 faisant échouer tout envoi, même vers un serveur qui répond.
 */
$delaiDEnvoi = (int) env('MAIL_TIMEOUT', 10);
$delaiDEnvoi = $delaiDEnvoi > 0 ? $delaiDEnvoi : 10;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin", et "mailtrap" (voir plus bas)
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => $delaiDEnvoi,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        /*
         | La boîte de test (« Email Sandbox ») de Mailtrap, par son API HTTPS
         | (voir TransportMailtrap), choisie seulement par
         | MAIL_MAILER=mailtrap : pour vérifier les e-mails de PILIQI depuis
         | une machine qui ne joint aucun serveur SMTP, jamais en
         | production. Son jeton d'API et sa boîte se lisent dans
         | config/services.php (MAILTRAP_API_TOKEN et MAILTRAP_INBOX_ID).
         */
        'mailtrap' => [
            'transport' => 'mailtrap',
            'timeout' => $delaiDEnvoi,
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
