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
    | Mots de passe provisoires
    |--------------------------------------------------------------------------
    |
    | Le mot de passe provisoire d'un compte que la société crée, ou dont
    | elle redonne l'accès en l'absence de la personne (voir
    | MotsDePasseProvisoires) : tiré au hasard, il ne sert qu'une fois, à la
    | première connexion, et ne vaut que cette durée en heures (décision de
    | Claude D76, T36.1 à T36.4).
    |
    */

    'mots_de_passe' => [
        'provisoire_duree_en_heures' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertes de l'éditeur sur les essais de connexion manqués
    |--------------------------------------------------------------------------
    |
    | Les seuils, par heure et sur toute la plateforme, au-delà desquels
    | l'éditeur est prévenu (voir LimitationDesConnexions et
    | AlerterDeLaMonteeDesEchecsJob), une seule fois par heure et par motif
    | (décision de Claude D76) : un nombre absolu d'échecs ; le multiple de
    | la moyenne horaire des 7 jours d'avant, avec son plancher ; le nombre
    | d'identifiants différents qui échouent depuis une même adresse IP.
    |
    */

    'connexions' => [
        'alerte_echecs_par_heure' => 500,
        'alerte_multiple_de_la_moyenne' => 3,
        'alerte_plancher_par_heure' => 100,
        'alerte_identifiants_par_adresse' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mot de passe du semis
    |--------------------------------------------------------------------------
    |
    | Facultatif, en développement seulement : le mot de passe que reçoivent
    | tous les comptes semés par php artisan migrate:fresh --seed (voir
    | DatabaseSeeder), stable d'un semis à l'autre, dans
    | PILIQI_SEMIS_MOT_DE_PASSE. Il suit la règle des mots de passe choisis
    | (voir RegleDuMotDePasse) : au moins 12 caractères, ou au moins 8 de
    | trois sortes parmi les majuscules, les minuscules, les chiffres et les
    | signes ; jamais un mot de passe courant (liste de
    | resources/mots-de-passe-courants), un seul caractère répété, une suite
    | de chiffres ni l'identifiant d'un compte. Vide : chaque compte reçoit
    | un mot de passe tiré au hasard à chaque semis, que la liste des
    | comptes affiche.
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
