<?php

namespace App\Domain\CaisseSecurisee\Enums;

/**
 * Ce qui a lancé une vérification de l'intégrité du registre fiscal (voir
 * VerifierIntegriteAction), tel que l'opération de contrôle (JET 280) le
 * retient : un utilisateur depuis l'écran de la caisse sécurisée, ou la
 * commande caisse:verifier, lancée à la main ou chaque nuit par le
 * planificateur.
 */
enum DeclenchementVerification: string
{
    case Ecran = 'ecran';
    case Commande = 'commande';
}
