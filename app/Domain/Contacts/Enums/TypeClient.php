<?php

namespace App\Domain\Contacts\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Particulier ou professionnel : le type se lit sur la fiche du client,
 * puis s'écrit sur chaque document de vente, où il se fige à l'émission
 * (voir FigeLeClient). Celui du document choisit sa série, ticket de caisse
 * ou facture (voir Facture::serie()), et, pour l'instant, son circuit :
 * clôture de caisse et e-reporting pour un ticket, facture électronique
 * pour une facture (voir Facture::relevantDeLeInvoicing()).
 */
enum TypeClient: string implements HasLabel
{
    case Particulier = 'particulier';
    case Professionnel = 'professionnel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Particulier => 'Particulier',
            self::Professionnel => 'Professionnel',
        };
    }
}
