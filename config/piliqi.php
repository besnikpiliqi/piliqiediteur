<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Le site que sert ce serveur
    |--------------------------------------------------------------------------
    |
    | PILIQI_SITE : reel (par défaut), le site des vraies sociétés, ou
    | demonstration, le site qui sert à chaque société la copie d'une
    | société modèle fictive de son type (voir SiteDePiliqi et
    | SiteDePiliqiServiceProvider). Lu une seule fois au démarrage ; une
    | autre valeur empêche de démarrer. Jamais déduit d'APP_ENV.
    |
    */

    'site' => env('PILIQI_SITE', 'reel'),

    /*
    |--------------------------------------------------------------------------
    | Version servie
    |--------------------------------------------------------------------------
    |
    | La date et le court numéro du commit déployé (PILIQI_VERSION, par
    | exemple « 2026-11-16 7f9e16c »), écrits au déploiement : le pied de
    | chaque page des deux sites les affiche avec le site servi, pour qu'un
    | essai mal lancé se voie tout de suite. Vide sur un poste de
    | développement : le pied dit alors « version de développement ».
    |
    */

    'version' => env('PILIQI_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Hôtes de confiance
    |--------------------------------------------------------------------------
    |
    | Les noms d'hôte exacts que sert ce serveur, séparés par des virgules
    | dans HOTES_DE_CONFIANCE : hors du développement et des tests, une
    | requête pour un autre hôte, sous-domaine compris, répond 400 (voir
    | HotesDeConfiance). Vide : le seul hôte d'APP_URL. Jamais un motif ni
    | un domaine ouvert à ses sous-domaines : le site de démonstration a
    | son propre domaine, jamais un sous-domaine du site réel.
    |
    */

    'hotes_de_confiance' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('HOTES_DE_CONFIANCE', ''))), static fn (string $hote): bool => $hote !== '')),

    /*
    |--------------------------------------------------------------------------
    | Mandataires de confiance (répartiteur de charge)
    |--------------------------------------------------------------------------
    |
    | Les adresses ou plages exactes du répartiteur de charge placé devant
    | l'application, séparées par des virgules dans PROXIES_DE_CONFIANCE :
    | leurs en-têtes X-Forwarded-* donnent l'adresse IP réelle du client
    | (voir FaireConfianceAuxMandataires), sur laquelle se comptent les
    | essais de connexion (voir LimitationDesConnexions) et se notent les
    | traces techniques. Vide par défaut : aucun en-tête n'est cru. « * »,
    | « ** » et « REMOTE_ADDR », qui laisseraient un attaquant changer
    | d'adresse à chaque essai, sont ignorés.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Analyse des PDF reçus
    |--------------------------------------------------------------------------
    |
    | Le programme qpdf (licence Apache 2.0) qui lit en entier chaque PDF
    | reçu (voir AnalyseurDePdf) : son nom s'il est dans le PATH du serveur
    | (« qpdf », par défaut), ou son chemin complet dans QPDF_BINAIRE
    | (C:\qpdf\bin\qpdf.exe sous Windows).
    |
    */

    'qpdf' => env('QPDF_BINAIRE', 'qpdf'),

    /*
    |--------------------------------------------------------------------------
    | Mandataires de confiance
    |--------------------------------------------------------------------------
    |
    | Les adresses IP exactes, ou les plages CIDR étroites, du répartiteur de
    | charge (PROXIES_DE_CONFIANCE, séparées par des virgules), dont
    | l'application croit les en-têtes X-Forwarded-* (voir
    | FaireConfianceAuxMandataires) : un mot-clé, une plage /0 ou une plage
    | IPv6 qui porte des adresses IPv4 est ignoré.
    |
    */

    'proxies_de_confiance' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('PROXIES_DE_CONFIANCE', ''))), static fn (string $adresse): bool => $adresse !== '')),

    /*
    |--------------------------------------------------------------------------
    | Mot de passe du semis
    |--------------------------------------------------------------------------
    |
    | Facultatif, en développement seulement : le mot de passe que reçoivent
    | tous les comptes semés par php artisan migrate:fresh --seed (voir
    | DatabaseSeeder), stable d'un semis à l'autre, dans
    | PILIQI_SEMIS_MOT_DE_PASSE. Il suit la règle des mots de passe choisis :
    | au moins 8 caractères, ni un seul caractère répété, ni une suite de
    | chiffres, ni l'identifiant d'un compte. Vide : chaque compte reçoit un
    | mot de passe tiré au hasard à chaque semis, que la liste des comptes
    | affiche.
    |
    */

    'semis_mot_de_passe' => env('PILIQI_SEMIS_MOT_DE_PASSE'),

    /*
    |--------------------------------------------------------------------------
    | Rythme de la cloche et des cartes des notifications
    |--------------------------------------------------------------------------
    |
    | Toutes les combien de secondes une page ouverte de PILIQI regarde s'il
    | y a du nouveau dans la cloche et montre en carte chaque notification
    | arrivée depuis (voir CartesDesNotifications), sans service extérieur
    | ni WebSocket : 30 secondes par défaut, dans
    | PILIQI_INTERVALLE_DES_NOTIFICATIONS. Un nombre plus petit fait
    | apparaître une notification plus tôt, au prix d'une requête de plus
    | par page ouverte à chaque intervalle.
    |
    */

    'intervalle_des_notifications_en_secondes' => (int) env('PILIQI_INTERVALLE_DES_NOTIFICATIONS', 30),

];
