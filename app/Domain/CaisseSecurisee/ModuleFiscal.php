<?php

namespace App\Domain\CaisseSecurisee;

/**
 * Le module fiscal de la caisse sécurisée (art. 286 I-3° bis du CGI) :
 * signature, chaînage, journal des événements, clôtures et archives des
 * données de caisse.
 *
 * Sa version, « majeure.mineure », est une constante du code : aucun
 * réglage, aucun magasin ne peut la changer. Toute modification du module
 * fiscal en change la version : la mineure pour une modification sans
 * incidence sur les conditions d'inaltérabilité, de sécurisation, de
 * conservation et d'archivage, la majeure sinon. Une version majeure ne se
 * note jamais comme une mineure.
 */
final class ModuleFiscal
{
    public const VERSION = '1.0';
}
