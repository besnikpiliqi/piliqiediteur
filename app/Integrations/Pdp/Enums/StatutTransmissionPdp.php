<?php

namespace App\Integrations\Pdp\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * L'état d'une transmission à la plateforme agréée. Une panne (réseau,
 * plateforme injoignable) n'est jamais un rejet : la transmission reste
 * « soumise » pendant les tentatives automatiques, puis passe « non
 * transmise : incident technique » après la dernière (voir
 * SubmitFactureToPdpJob), et se reprend sous la même référence de dépôt ;
 * « rejetée » ne vient que d'une réponse de rejet de la plateforme, avec
 * son motif.
 */
enum StatutTransmissionPdp: string implements HasColor, HasLabel
{
    case Soumise = 'soumise';
    case Acceptee = 'acceptee';
    case Livree = 'livree';
    case Rejetee = 'rejetee';
    case Refusee = 'refusee';
    case Archivee = 'archivee';
    case EchecTechnique = 'echec_technique';

    public function getLabel(): string
    {
        return match ($this) {
            self::Soumise => 'Soumise au PDP',
            self::Acceptee => 'Acceptée par le PDP',
            self::Livree => 'Livrée au destinataire',
            self::Rejetee => 'Rejetée par la plateforme',
            self::Refusee => 'Refusée par le destinataire',
            self::Archivee => 'Archivée',
            self::EchecTechnique => 'Non transmise : incident technique',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Soumise => 'gray',
            self::Acceptee, self::Livree, self::Archivee => 'success',
            self::Rejetee, self::Refusee => 'danger',
            self::EchecTechnique => 'warning',
        };
    }

    /**
     * L'état est-il final : la plateforme a répondu (acceptée, livrée,
     * archivée, rejetée, refusée), et plus rien ne la reprend d'office ?
     * Une transmission soumise ou en échec technique se reprend.
     */
    public function estFinal(): bool
    {
        return ! in_array($this, [self::Soumise, self::EchecTechnique], true);
    }
}
