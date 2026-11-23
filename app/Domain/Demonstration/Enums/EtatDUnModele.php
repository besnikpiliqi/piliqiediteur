<?php

namespace App\Domain\Demonstration\Enums;

/**
 * Les états d'une base modèle au registre du site de démonstration (lot
 * DEMO-COPIE, C.2) : un seul en service et un seul précédent par type.
 */
enum EtatDUnModele: string
{
    case EnConstruction = 'en_construction';
    case EnService = 'en_service';
    case Precedent = 'precedent';
    case EnEchec = 'en_echec';
    case Supprime = 'supprime';
}
