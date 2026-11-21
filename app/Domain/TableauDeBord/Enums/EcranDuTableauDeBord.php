<?php

namespace App\Domain\TableauDeBord\Enums;

/**
 * Les écrans où s'affichent les chiffres clés et les graphiques (décision
 * de l'éditeur du 14/11/2026), chacun avec ses préférences retenues par
 * compte (voir PreferencesDesGraphiques) :
 * - l'espace de la société, pour le propriétaire et un administrateur à
 *   qui il coche la case, sur les magasins de sa portée ;
 * - le panneau d'un magasin, sur ce seul magasin, un réglage valant pour
 *   tous les magasins du compte ;
 * - l'espace du cabinet, sur un client lié à la fois, un réglage par
 *   compte valant pour tous ses clients ;
 * - la page de l'expert-comptable déclaré par la société, sur les magasins
 *   de sa déclaration.
 */
enum EcranDuTableauDeBord: string
{
    case Societe = 'societe';
    case Magasin = 'magasin';
    case Cabinet = 'cabinet';
    case ExpertComptable = 'expert_comptable';

    /**
     * L'écran montre-t-il plusieurs magasins, avec le filtre « Magasin » ?
     * Jamais le panneau d'un magasin, qui ne laisse deviner aucun autre
     * magasin de la société.
     */
    public function montrePlusieursMagasins(): bool
    {
        return $this !== self::Magasin;
    }
}
