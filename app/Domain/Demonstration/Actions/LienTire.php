<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Demonstration\Models\Demonstration;
use SensitiveParameter;

/**
 * Ce que rend un geste qui tire un lien (créer, renouveler) : la
 * démonstration, et le lien en clair, seulement dans la réponse de la
 * demande qui l'a tiré. Une demande répétée après une réponse perdue rend
 * la même démonstration sans lien : il est parti par e-mail, et n'est
 * gardé nulle part.
 */
final readonly class LienTire
{
    public function __construct(
        public Demonstration $demonstration,
        #[SensitiveParameter] public ?string $lien,
    ) {}
}
