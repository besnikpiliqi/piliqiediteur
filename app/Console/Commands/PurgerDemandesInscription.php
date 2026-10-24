<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\DemandeInscription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Commande planifiée chaque heure (voir bootstrap/app.php) : efface les
 * demandes d'inscription 48 heures après leur expiration, confirmées ou
 * non (voir DemandeInscription) ; leurs données chiffrées ne se gardent
 * pas plus longtemps. Table technique, sans société ni magasin : la
 * lecture est globale, limitée à la date d'expiration.
 */
#[Signature('app:purger-demandes-inscription')]
#[Description('Efface les demandes d\'inscription expirées depuis plus de 48 heures')]
class PurgerDemandesInscription extends Command
{
    public function handle(): int
    {
        $effacees = DemandeInscription::query()
            ->where('expire_le', '<=', now()->subHours(DemandeInscription::EFFACEMENT_APRES_EXPIRATION_EN_HEURES))
            ->delete();

        $this->info("{$effacees} demande(s) d'inscription effacée(s).");

        return self::SUCCESS;
    }
}
