<?php

namespace App\Integrations\Pdp\Enums;

enum StatutTransmissionPdp: string
{
    case Soumise = 'soumise';
    case Acceptee = 'acceptee';
    case Livree = 'livree';
    case Rejetee = 'rejetee';
    case Refusee = 'refusee';
    case Archivee = 'archivee';
}
