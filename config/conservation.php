<?php

/*
|--------------------------------------------------------------------------
| Durées de conservation
|--------------------------------------------------------------------------
|
| Les documents, les registres et les journaux se conservent 10 ans, sans
| aucune purge (voir CLAUDE.md, « Caisse et clôtures ») ; seules les
| données ci-dessous s'effacent à leur échéance. Les durées sont des
| décisions de l'éditeur (S13, Q4), réglables ici.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Traces techniques
    |--------------------------------------------------------------------------
    |
    | L'adresse IP et le navigateur d'un événement technique de la caisse
    | sécurisée, rangés à part de son contenu signé (voir TraceEvenement) :
    | 1 an par défaut, que recommande la CNIL (délibération 2021-122 : de 6
    | mois à 1 an). Effacés chaque nuit une fois cette durée passée (voir
    | app:purger-traces).
    |
    */

    'traces_en_mois' => (int) env('CONSERVATION_TRACES_EN_MOIS', 12),

];
