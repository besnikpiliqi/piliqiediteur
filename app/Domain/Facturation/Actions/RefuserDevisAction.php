<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Constate le refus d'un devis par son client : refusé, il reste figé
 * (voir Devis::estModifiable()). Son auteur, relu en base après le verrou
 * du devis, doit pouvoir le constater (voir DevisPolicy::refuser()).
 */
class RefuserDevisAction
{
    public function __construct(private readonly LecteurDesDroits $lecteur) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): Devis
    {
        return DB::transaction(function () use ($auteur, $devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Refuse)) {
                throw new LogicException("Impossible de refuser un devis au statut [{$devis->statut->value}].");
            }

            $this->lecteur->exiger($auteur, 'refuser', $devis->magasin, $devis);

            $devis->forceFill(['statut' => StatutDevis::Refuse])->save();

            return $devis;
        });
    }
}
