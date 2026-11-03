<?php

namespace Tests\Browser\Support;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;

/**
 * Une société validée de « Commerce ou atelier », telle que les tests en
 * navigateur la parcourent (voir TestCaseDuNavigateur::societeComplete()) :
 * deux magasins réels, chaque rôle de la société et du magasin, son
 * cabinet d'expertise comptable lié et le collaborateur à qui elle est
 * confiée. Chaque mot de passe est MOT_DE_PASSE_CHOISI.
 */
final readonly class SocieteDeTest
{
    public function __construct(
        public Societe $societe,
        public Magasin $magasin,
        public Magasin $autreMagasin,
        public User $proprietaire,
        public User $administrateur,
        public User $administrateurVendeur,
        public User $responsable,
        public User $vendeur,
        public User $observateur,
        public Societe $cabinet,
        public User $proprietaireDuCabinet,
        public User $collaborateur,
    ) {}
}
