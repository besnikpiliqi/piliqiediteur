<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
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
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne constate plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits), puis le devis sous le sien : une
 * fermeture du magasin, qui verrouille cette ligne (voir
 * FermerMagasinAction), passe tout entière avant ou après le constat.
 */
class AccepterDevisAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * L'auteur relu doit pouvoir constater l'acceptation du devis relu
     * (voir DevisPolicy::accepter()) ; le constat se fait à son nom (voir
     * OperateurCourant).
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): Devis
    {
        return DB::transaction(function () use ($auteur, $devis) {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::DEVIS_ENVOYER, $magasin);

            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Devis::REFUS_INTROUVABLE);

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Accepte)) {
                throw new LogicException("Impossible d'accepter un devis au statut [{$devis->statut->value}].");
            }

            if ($devis->validiteDepassee()) {
                throw new LogicException('La date de validité de ce devis est dépassée ('.Calendrier::afficherJour($devis->date_validite, 'd/m/Y').') : il ne peut plus être accepté.');
            }

            $this->lecteur->exiger($compte, 'accepter', $magasin, $devis);

            return $this->operateur->pendant($compte, fn (): Devis => tap($devis->forceFill(['statut' => StatutDevis::Accepte]))->save());
        });
    }
}
