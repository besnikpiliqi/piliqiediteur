<?php

namespace App\Domain\Contacts\Enums;

enum SourceFournisseur: string
{
    case Manuel = 'manual';
    case AutoPdp = 'pdp_auto';
}
