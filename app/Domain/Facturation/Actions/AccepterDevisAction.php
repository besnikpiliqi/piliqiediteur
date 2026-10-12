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
 * Constate l'acceptation, verbale ou écrite, d'un devis par son client :
 * seule cette acceptation explicite, et non un simple envoi, permet de le
 * convertir en facture (voir CreerFactureDepuisDevisAction). Jamais une
 * fois sa date de validité dépassée, même avant que le devis soit passé à
 * « Expiré » dans la nuit (voir ExpirerLesDevisAction).
 */
class AccepterDevisAction
{
    public function __construct(private readonly LecteurDesDroits $lecteur) {}

    /**
     * Son auteur, relu en base après le verrou du devis, doit pouvoir
     * constater l'acceptation (voir DevisPolicy::accepter()).
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): Devis
    {
        return DB::transaction(function () use ($auteur, $devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Accepte)) {
                throw new LogicException("Impossible d'accepter un devis au statut [{$devis->statut->value}].");
            }

            if ($devis->validiteDepassee()) {
                throw new LogicException("La date de validité de ce devis est dépassée ({$devis->date_validite->format('d/m/Y')}) : il ne peut plus être accepté.");
            }

            $this->lecteur->exiger($auteur, 'accepter', $devis->magasin, $devis);

            $devis->forceFill(['statut' => StatutDevis::Accepte])->save();

            return $devis;
        });
    }
}
