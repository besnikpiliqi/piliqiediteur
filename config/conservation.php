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
    | sécurisée et d'une entrée du journal d'audit, rangés à part de leur
    | contenu signé ou chaîné (voir TraceEvenement et TraceAudit) :
    | 1 an par défaut, que recommande la CNIL (délibération 2021-122 : de 6
    | mois à 1 an). Effacés chaque nuit une fois cette durée passée (voir
    | app:purger-traces).
    |
    */

    'traces_en_mois' => (int) env('CONSERVATION_TRACES_EN_MOIS', 12),

    /*
    |--------------------------------------------------------------------------
    | Journal d'audit des sociétés
    |--------------------------------------------------------------------------
    |
    | Les entrées des opérations commerciales et fiscales, des réglages et
    | des procédures se gardent 10 ans ; celles de la gestion des accès
    | (comptes, droits, mots de passe donnés, suspensions, rattachements,
    | transferts), 5 ans (Q4 de l'éditeur, voir CategorieAudit). Celles de
    | la vérification d'une société se gardent jusqu'à 5 ans après la fin
    | de la relation. Effacées chaque nuit une fois cette durée passée, par
    | le début de leur chaîne (voir app:purger-journal-audit).
    |
    */

    'journal_audit_operations_en_annees' => (int) env('CONSERVATION_AUDIT_OPERATIONS_EN_ANNEES', 10),

    'journal_audit_acces_en_annees' => (int) env('CONSERVATION_AUDIT_ACCES_EN_ANNEES', 5),

    /*
    |--------------------------------------------------------------------------
    | Jetons des demandes répétées
    |--------------------------------------------------------------------------
    |
    | Le jeton unique de chaque demande traitée (R3, voir ActionsUniques),
    | avec l'empreinte de son contenu et son résultat : table technique sans
    | valeur fiscale, gardée 30 jours, le temps qu'une demande répétée après
    | une réponse perdue retrouve son résultat. Effacée chaque nuit une fois
    | cette durée passée (voir app:purger-actions-traitees).
    |
    */

    'actions_traitees_en_jours' => (int) env('CONSERVATION_ACTIONS_TRAITEES_EN_JOURS', 30),

];
