<?php

namespace App\Domain\CaisseSecurisee\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Les chaînes du registre fiscal (voir RegistreFiscal). Chaque magasin a,
 * pour chacune, sa propre séquence continue depuis 1, jamais remise à
 * zéro, et chaque enregistrement signe la signature du précédent de la
 * même chaîne : une chaîne ne se mélange ni à une autre ni à celle d'un
 * autre magasin.
 */
enum ChaineFiscale: string implements HasLabel
{
    /** Tickets, factures et avoirs, signés à leur émission. */
    case Vente = 'vente';

    /** Paiements reçus, signés à leur enregistrement. */
    case Encaissement = 'encaissement';

    /** Le journal des événements techniques (JET), voir CodeEvenement. */
    case Evenement = 'evenement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vente => 'Ventes',
            self::Encaissement => 'Encaissements',
            self::Evenement => 'Événements',
        };
    }
}
