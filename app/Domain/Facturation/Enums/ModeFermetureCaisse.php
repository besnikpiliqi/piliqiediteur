<?php

namespace App\Domain\Facturation\Enums;

/**
 * Qui a fermé une caisse personnelle (voir ClotureCaissePersonnelle et
 * FermerSaCaisseAction) :
 * - Vendeur : le vendeur lui-même (« Fermer ma caisse ») ;
 * - Responsable : un membre autorisé à clôturer la journée, pour lui
 *   (« Fermer la caisse d'un vendeur ») ;
 * - Automatique : d'office, après la clôture de la journée ou à minuit
 *   (voir FermerLesCaissesPersonnellesAction), sans auteur.
 */
enum ModeFermetureCaisse: string
{
    case Vendeur = 'vendeur';
    case Responsable = 'responsable';
    case Automatique = 'automatique';
}
