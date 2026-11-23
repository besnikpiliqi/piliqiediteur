<?php

namespace App\Integrations\Vies\Contracts;

use App\Integrations\Vies\ResultatVies;
use App\Integrations\Vies\ViesIndisponibleException;
use LogicException;

/**
 * VIES, le service de la Commission européenne qui dit si un n° de TVA de
 * l'Union est valide, derrière son pilote (VIES_PILOTE, voir
 * FournisseursExternesServiceProvider) : le vrai service (ClientVies), ou
 * le faux (FauxVies), qui ne joint jamais l'extérieur et sert au semis et
 * au site de démonstration.
 */
interface ServiceVies
{
    /**
     * @param  string  $numeroTva  le n° vérifié, d'un État membre de l'Union ou d'Irlande du Nord
     * @param  string|null  $numeroTvaDemandeur  le n° de TVA de la société qui demande, s'il en a un
     *
     * @throws ViesIndisponibleException
     * @throws LogicException quand VIES refuse le n° de la société qui demande
     */
    public function verifier(string $numeroTva, ?string $numeroTvaDemandeur): ResultatVies;
}
