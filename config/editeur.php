<?php

/*
|--------------------------------------------------------------------------
| L'éditeur
|--------------------------------------------------------------------------
|
| Les opérateurs qui lancent les procédures exceptionnelles de l'éditeur
| (php artisan list editeur, voir RegistreDesOperateurs) : chacun par son
| nom et l'empreinte Argon2id de son secret personnel, en JSON {nom:
| empreinte}, dans l'environnement du serveur, jamais dans le dépôt.
| L'adresse e-mail où l'éditeur reçoit ses alertes (voir AlerteEditeur) ;
| sans elle, l'alerte ne s'écrit qu'au journal de l'application.
|
*/

return [

    'operateurs' => json_decode((string) env('EDITEUR_OPERATEURS', '{}'), true) ?: [],

    'email' => env('PILIQI_EDITEUR_EMAIL'),

];
