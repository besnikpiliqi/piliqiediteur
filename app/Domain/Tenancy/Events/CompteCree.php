<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un compte vient de naître (voir CreationDesComptes::creer()) : un membre
 * d'un magasin, un administrateur, un collaborateur d'un cabinet ou un
 * expert-comptable de la société. Sur le site de démonstration, il entre
 * au bandeau « Vous êtes : » de la copie (voir AjouterLeCompteAuBandeau).
 */
final class CompteCree
{
    use Dispatchable;

    public function __construct(
        public readonly User $compte,
        public readonly ?Magasin $magasin,
    ) {}
}
