<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;

/**
 * Passe à « Expiré » les devis envoyés d'un magasin dont la date de
 * validité est dépassée (voir Devis::validiteDepassee()) : leur client ne
 * peut plus les accepter. Un brouillon n'est pas encore parti, il reste
 * brouillon ; un devis accepté l'a été à temps, il reste convertible en
 * facture.
 *
 * Appelée pour chaque magasin dans son propre TenantContext (voir la
 * commande ExpirerDevis) : la requête, limitée à ce magasin par
 * MagasinScope, l'est aussi explicitement.
 */
class ExpirerLesDevisAction
{
    /**
     * @return int le nombre de devis expirés
     */
    public function handle(Magasin $magasin): int
    {
        return Devis::query()
            ->where('magasin_id', $magasin->getKey())
            ->where('statut', StatutDevis::Envoye)
            ->whereDate('date_validite', '<', today())
            ->update(['statut' => StatutDevis::Expire]);
    }
}
