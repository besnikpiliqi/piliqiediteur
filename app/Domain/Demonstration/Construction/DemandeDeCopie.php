<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * La demande d'une copie telle que le message « creer » l'a apportée, déjà
 * contrôlée par la réception des messages (ReceptionDesMessages), relue en
 * valeurs typées pour le registre.
 */
final class DemandeDeCopie
{
    /**
     * @param  array{reference: string, repere: string, type: string, validee: bool, empreinte_du_lien: string, generation: int, fin: string}  $demande
     * @return array{reference: string, repere: string, type: TypeDActivite, validee: bool, empreinte_du_lien: string, generation: int, fin: CarbonImmutable}
     */
    public static function lire(array $demande): array
    {
        return [
            'reference' => $demande['reference'],
            'repere' => $demande['repere'],
            'type' => TypeDActivite::from($demande['type']),
            'validee' => $demande['validee'],
            'empreinte_du_lien' => $demande['empreinte_du_lien'],
            'generation' => $demande['generation'],
            'fin' => Calendrier::lireUnInstantUtc($demande['fin']) ?? throw new InvalidArgumentException('La fin de la copie est illisible.'),
        ];
    }
}
