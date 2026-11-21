<?php

namespace App\Integrations\Vies;

use App\Domain\Facturation\Services\NumeroTva;
use App\Domain\Facturation\Services\TerritoireFiscal;
use App\Integrations\Vies\Contracts\ServiceVies;
use InvalidArgumentException;

/**
 * Le faux VIES (VIES_PILOTE=faux), du semis et du site de démonstration :
 * il ne joint jamais le service de la Commission européenne et dit valide
 * chaque n° de TVA de l'Union, sans nom ni adresse publiés, avec un n° de
 * consultation fictif, la réponse que le semis simulait jusque-là. Comme
 * le vrai, il refuse un n° hors de l'Union.
 */
final class FauxVies implements ServiceVies
{
    public const IDENTIFIANT_DE_CONSULTATION = 'WAPIAAAAW0SEMIS';

    /**
     * @throws InvalidArgumentException un n° hors de l'Union européenne et de l'Irlande du Nord
     */
    public function verifier(string $numeroTva, ?string $numeroTvaDemandeur): ResultatVies
    {
        if (TerritoireFiscal::prefixeTvaUe(NumeroTva::normaliser($numeroTva)) === null) {
            throw new InvalidArgumentException('VIES ne vérifie que les n° de TVA de l\'Union européenne et d\'Irlande du Nord.');
        }

        return new ResultatVies(
            valide: true,
            nom: null,
            adresse: null,
            identifiantConsultation: self::IDENTIFIANT_DE_CONSULTATION,
            reponse: (string) json_encode(['valid' => true, 'name' => '---', 'address' => '---', 'requestIdentifier' => self::IDENTIFIANT_DE_CONSULTATION]),
        );
    }
}
