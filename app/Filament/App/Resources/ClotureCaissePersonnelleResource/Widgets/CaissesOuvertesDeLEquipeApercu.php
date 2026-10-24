<?php

namespace App\Filament\App\Resources\ClotureCaissePersonnelleResource\Widgets;

use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\CaissesPersonnellesOuvertesApercu;

/**
 * Les caisses encore ouvertes en tête de la page des relevés de caisse,
 * attaché explicitement via ListClotureCaissePersonnelles::getHeaderWidgets() :
 * le même encart que sur la liste des clôtures (voir
 * CaissesPersonnellesOuvertesApercu), montré quand la page s'appelle « Les
 * caisses » (qui voit les relevés de toute l'équipe, ou ferme la caisse
 * d'un vendeur sans tenir de caisse), jamais quand elle s'appelle « Ma
 * caisse » (voir ClotureCaissePersonnelleResource::neVoitQueSaCaisse()) :
 * un vendeur autorisé à clôturer la journée sans voir les relevés de toute
 * l'équipe n'y voit pas les caisses des autres.
 */
class CaissesOuvertesDeLEquipeApercu extends CaissesPersonnellesOuvertesApercu
{
    public static function canView(): bool
    {
        return ! ClotureCaissePersonnelleResource::neVoitQueSaCaisse();
    }
}
