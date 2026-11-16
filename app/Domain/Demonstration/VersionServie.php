<?php

namespace App\Domain\Demonstration;

/**
 * Ce que le pied de chaque page des deux sites dit du logiciel servi : la
 * version déployée (PILIQI_VERSION, date et court numéro du commit, écrits
 * au déploiement) et le site (« site réel » ou « site de démonstration »),
 * pour qu'un essai mal lancé, un site de démonstration servi avec le .env
 * du réel par exemple, se voie tout de suite (lot DEMO-COPIE, A.8).
 */
final class VersionServie
{
    public const SANS_VERSION = 'version de développement';

    /**
     * « PILIQI · version 2026-11-16 7f9e16c · site réel ».
     */
    public static function texte(): string
    {
        $version = self::courante();

        return 'PILIQI · '.($version === self::SANS_VERSION ? $version : 'version '.$version).' · '.SiteDePiliqi::courant()->libelle();
    }

    /**
     * La version déployée (PILIQI_VERSION), ou « version de développement » ;
     * le registre du site de démonstration la note pour chaque modèle et
     * chaque copie.
     */
    public static function courante(): string
    {
        $version = config('piliqi.version');

        return is_string($version) && trim($version) !== '' ? trim($version) : self::SANS_VERSION;
    }
}
