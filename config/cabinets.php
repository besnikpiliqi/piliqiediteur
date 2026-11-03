<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitations des cabinets d'expertise comptable
    |--------------------------------------------------------------------------
    |
    | Le nombre d'invitations et de demandes de rattachement qu'un cabinet
    | d'expertise comptable envoie au plus par jour (jour de Paris), comptées
    | sous le verrou de sa ligne (voir InviterUneEntrepriseAction) : chacune
    | part par e-mail à une adresse que le cabinet saisit, et une invitation
    | acceptée valide une société sans courrier recommandé. 50 par défaut
    | (choix de Claude, que l'éditeur peut contester).
    |
    */

    'invitations_par_jour' => (int) env('CABINET_INVITATIONS_PAR_JOUR', 50),

];
