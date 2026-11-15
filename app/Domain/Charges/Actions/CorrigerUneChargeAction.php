<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\EcritureDUneCharge;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * « Corriger » une charge validée (décision de l'éditeur du 14/11/2026,
 * lot CHARGES), seul geste qui l'écrit encore, par qui valide les charges
 * de la société (voir DroitsSurLesCharges), avec un motif obligatoire :
 * ses champs et ses lignes sont réécrits (voir EcritureDUneCharge), sa
 * pièce et sa validation restent, et la correction est comptée : la base
 * refuse toute autre écriture d'une charge validée (voir
 * GardesDesCharges). La correction s'inscrit au journal d'audit, avec le
 * motif, les anciennes et les nouvelles valeurs.
 */
class CorrigerUneChargeAction
{
    public const REFUS_DROIT = 'Seul un compte qui valide les charges corrige une charge validée.';

    public const REFUS_ETAT = 'Seule une charge validée se corrige ; avant sa validation, ses chiffres se modifient.';

    public const REFUS_SANS_MOTIF = 'Écrivez le motif de la correction.';

    public const REFUS_SANS_CHIFFRES = 'Une charge validée garde ses chiffres : écrivez au moins une ligne.';

    public function __construct(
        private readonly GestesSurLesCharges $gestes,
        private readonly EcritureDUneCharge $ecriture,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ConfirmationRequiseException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $charge, SaisieDeCharge $saisie, string $motif, ?string $jeton = null): Charge
    {
        $motif = Str::squish($motif);

        if ($motif === '') {
            throw new InvalidArgumentException(self::REFUS_SANS_MOTIF);
        }

        if (! $saisie->aDesChiffres()) {
            throw new InvalidArgumentException(self::REFUS_SANS_CHIFFRES);
        }

        $parametres = ['charge' => $charge, 'saisie' => $saisie->signature(), 'motif' => $motif];

        $resultat = $this->gestes->executer($auteur, $societe, ActionsUniques::CORRIGER_UNE_CHARGE, $jeton, $parametres, function (AccesAuxCharges $acces) use ($charge, $saisie, $motif): Charge {
            if (! $acces->valide()) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            $relue = $this->gestes->chargeVerrouillee($acces, $charge);

            if (! $relue->estValidee()) {
                throw new LogicException(self::REFUS_ETAT);
            }

            $avant = ChargesDeLaSociete::valeurs($relue);
            $chiffree = $this->ecriture->remplir($acces, $relue, $saisie);
            $relue->forceFill(['corrections' => $relue->corrections + 1, 'corrigee_le' => now()])->save();
            $this->ecriture->ecrireLesLignes($relue, $chiffree, $relue->corrections);
            $this->journalAudit->inscrire(ActionAuditee::ChargeCorrigee, $acces->compte, $relue->magasin, $avant, [...ChargesDeLaSociete::valeurs($relue), 'motif' => $motif], $relue);

            return $relue;
        });

        return $resultat ?? throw new LogicException(GestesSurLesCharges::CHARGE_INTROUVABLE);
    }
}
