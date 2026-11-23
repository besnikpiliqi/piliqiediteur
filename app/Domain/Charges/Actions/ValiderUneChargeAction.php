<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * « Valider » une charge à valider (décision de l'éditeur du 14/11/2026,
 * lot CHARGES), par qui valide les charges de la société : son cabinet
 * lié ou son expert-comptable déclaré, ou son propriétaire quand elle n'a
 * aucun comptable (voir DroitsSurLesCharges) ; la validation dit qui l'a
 * faite. Une charge sans sa pièce ne se valide pas : une TVA ne se déduit
 * pas sans pièce (la base le refuse aussi, voir GardesDesCharges) ; une
 * pièce sans chiffres non plus. Deux validations simultanées, ou une
 * validation et une suppression, passent l'une après l'autre sous le
 * verrou de la charge, et la seconde trouve la charge déjà validée ou
 * supprimée. La validation s'inscrit au journal d'audit.
 */
class ValiderUneChargeAction
{
    public const REFUS_DROIT = 'Seul votre comptable valide les charges, ou le propriétaire d\'une société qui n\'a aucun comptable.';

    public const REFUS_ETAT = 'Seule une charge à valider, avec ses chiffres, se valide.';

    public const REFUS_DEJA_VALIDEE = 'Cette charge est déjà validée.';

    public const REFUS_SANS_PIECE = 'Cette charge n\'a pas de pièce : une TVA ne se déduit pas sans pièce.';

    public function __construct(
        private readonly GestesSurLesCharges $gestes,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $charge, ?string $jeton = null): Charge
    {
        $resultat = $this->gestes->executer($auteur, $societe, ActionsUniques::VALIDER_UNE_CHARGE, $jeton, ['charge' => $charge], function (AccesAuxCharges $acces) use ($charge): Charge {
            if (! $acces->valide()) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            $relue = $this->gestes->chargeVerrouillee($acces, $charge);

            if ($relue->estValidee()) {
                throw new LogicException(self::REFUS_DEJA_VALIDEE);
            }

            if ($relue->etat !== EtatDeLaCharge::AValider) {
                throw new LogicException(self::REFUS_ETAT);
            }

            if (PieceDeCharge::query()->where('charge_id', $relue->getKey())->doesntExist()) {
                throw new LogicException(self::REFUS_SANS_PIECE);
            }

            $avant = ChargesDeLaSociete::valeurs($relue);
            $relue->forceFill([
                'etat' => EtatDeLaCharge::Validee,
                'validee_par' => $acces->valideComme,
                'validee_par_compte' => $acces->compte->getKey(),
                'validee_le' => now(),
            ])->save();
            $this->journalAudit->inscrire(ActionAuditee::ChargeValidee, $acces->compte, $relue->magasin, ['etat' => $avant['etat']], ['charge' => $avant['charge'], 'etat' => $relue->etat->value, 'validee_par' => $relue->validee_par?->value, 'total_tva_deductible' => $relue->total_tva_deductible], $relue);

            return $relue;
        });

        return $resultat ?? throw new LogicException(GestesSurLesCharges::CHARGE_INTROUVABLE);
    }
}
