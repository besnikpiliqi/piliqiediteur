<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Supprimer une charge qui n'est pas validée (décision de l'éditeur du
 * 14/11/2026, lot CHARGES), par qui l'a déposée ou par qui valide les
 * charges de la société (voir DroitsSurLesCharges) : ses lignes, sa pièce
 * et son fichier s'effacent avec elle. Une charge validée ne s'efface
 * jamais, et sa pièce non plus (la base le refuse aussi, voir
 * GardesDesCharges). La suppression s'inscrit au journal d'audit, avec
 * toutes ses valeurs.
 */
class SupprimerUneChargeAction
{
    public const REFUS_DROIT = 'Seuls la personne qui a déposé cette charge et votre comptable la suppriment.';

    public const REFUS_VALIDEE = 'Une charge validée ne s\'efface jamais : votre comptable la corrige.';

    public function __construct(
        private readonly GestesSurLesCharges $gestes,
        private readonly PiecesDesCharges $pieces,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $charge, ?string $jeton = null): void
    {
        $this->gestes->executer($auteur, $societe, ActionsUniques::SUPPRIMER_UNE_CHARGE, $jeton, ['charge' => $charge], function (AccesAuxCharges $acces) use ($charge): null {
            $relue = $this->gestes->chargeVerrouillee($acces, $charge);

            if ($relue->estValidee()) {
                throw new LogicException(self::REFUS_VALIDEE);
            }

            if (! $acces->valide() && $relue->deposee_par !== $acces->compte->getKey()) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            $avant = ChargesDeLaSociete::valeurs($relue);
            $magasin = $relue->magasin;
            LigneDeCharge::query()->where('charge_id', $relue->getKey())->delete();
            $this->pieces->effacer($relue);
            $relue->delete();
            $this->journalAudit->inscrire(ActionAuditee::ChargeSupprimee, $acces->compte, $magasin, $avant, null, $relue);

            return null;
        });
    }
}
