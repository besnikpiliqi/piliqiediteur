<?php

namespace App\Filament;

use App\Domain\Tenancy\Models\Societe;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\TableauDeBord;

/**
 * L'accueil de l'espace d'une société, selon son type : le tableau de bord
 * de l'espace d'un cabinet d'expertise comptable (/cabinet/{uuid}, voir
 * TableauDeBordDuCabinet), qui n'a jamais d'espace d'administration ; celui
 * de l'espace d'administration de toute autre société (/societe/{uuid},
 * voir TableauDeBord). La seule méthode qui le dit : chaque redirection et
 * chaque lien qui mènent à cet accueil la suivent, sans répéter le test du
 * type.
 */
class EspaceDeLaSociete
{
    public static function accueil(Societe $societe): string
    {
        return $societe->estUnCabinet()
            ? TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $societe)
            : TableauDeBord::getUrl(panel: 'societe', tenant: $societe);
    }
}
