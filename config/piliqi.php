<?php

return [

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

];
