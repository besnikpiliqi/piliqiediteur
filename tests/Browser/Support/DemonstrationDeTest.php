<?php

namespace Tests\Browser\Support;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;

/**
 * Le magasin de démonstration d'une société inscrite qui attend le code de
 * son courrier, tel que les tests en navigateur le parcourent (voir
 * TestCaseDuNavigateur::demonstration()) : son propriétaire, qui y vend
 * comme un responsable (le seul magasin où il vend), et les comptes
 * d'entraînement qu'il y crée, un responsable et un vendeur. Chaque mot de
 * passe est MOT_DE_PASSE_CHOISI.
 */
final readonly class DemonstrationDeTest
{
    public function __construct(
        public Societe $societe,
        public Magasin $magasin,
        public User $proprietaire,
        public User $responsable,
        public User $vendeur,
    ) {}
}
