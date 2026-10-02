<?php

namespace App\Domain\Contacts\Enums;

/**
 * Détermine le circuit de facturation électronique applicable :
 * un client professionnel relève de l'e-invoicing (transmission PDP
 * individuelle), un particulier relève de l'e-reporting (agrégé via la
 * clôture de caisse journalière).
 */
enum TypeClient: string
{
    case Particulier = 'particulier';
    case Professionnel = 'professionnel';
}
