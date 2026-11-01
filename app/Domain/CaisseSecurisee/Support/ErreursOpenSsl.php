<?php

namespace App\Domain\CaisseSecurisee\Support;

/**
 * Ce qu'OpenSSL dit d'une génération, d'une lecture ou d'un export de clé
 * de la caisse sécurisée qui a échoué, pour le message de l'exception
 * (voir SignataireFiscal) et caisse:diagnostiquer.
 *
 * PHP garde les erreurs d'OpenSSL dans une file, que openssl_error_string()
 * vide une à une : oublier() la vide avant l'opération, pour que detail()
 * ne rende que les erreurs de celle-ci. Ces messages ne contiennent jamais
 * une clé ni une phrase secrète : seulement le code et le libellé de
 * chaque erreur.
 *
 * Sous Windows, PHP ne trouve pas le fichier openssl.cnf tant que la
 * variable d'environnement OPENSSL_CONF ne le désigne pas, et OpenSSL ne
 * génère alors aucune clé : detail() y ajoute la marche à suivre.
 */
final class ErreursOpenSsl
{
    /**
     * La marche à suivre sous Windows (XAMPP compris), où PHP ne trouve pas
     * openssl.cnf sans la variable OPENSSL_CONF.
     */
    public const MARCHE_A_SUIVRE_SOUS_WINDOWS = 'Sous Windows, PHP ne trouve pas le fichier openssl.cnf tant que la variable d\'environnement OPENSSL_CONF ne le désigne pas : '
        .'réglez-la sur le fichier openssl.cnf de PHP (par exemple, dans une invite de commandes : setx OPENSSL_CONF "C:\\xampp\\php\\extras\\ssl\\openssl.cnf"), '
        .'ouvrez un nouveau terminal, redémarrez Apache s\'il sert l\'application, puis relancez php artisan caisse:diagnostiquer.';

    /**
     * Vide la file des erreurs d'OpenSSL, juste avant une opération.
     */
    public static function oublier(): void
    {
        do {
            $erreur = openssl_error_string();
        } while ($erreur !== false);
    }

    /**
     * Les erreurs qu'OpenSSL a laissées depuis oublier(), chacune une seule
     * fois et dans leur ordre, suivies, sous Windows, de la marche à suivre.
     * La file en sort vide.
     *
     * @param  string  $familleDuSysteme  la famille du système qui fait tourner PHP (PHP_OS_FAMILY)
     */
    public static function detail(string $familleDuSysteme = PHP_OS_FAMILY): string
    {
        $erreurs = [];

        while (($erreur = openssl_error_string()) !== false) {
            $erreurs[] = $erreur;
        }

        $detail = $erreurs === []
            ? 'OpenSSL n\'a laissé aucun message d\'erreur.'
            : 'Erreurs d\'OpenSSL : '.implode(' ; ', array_values(array_unique($erreurs))).'.';

        return $familleDuSysteme === 'Windows' ? $detail.' '.self::MARCHE_A_SUIVRE_SOUS_WINDOWS : $detail;
    }
}
