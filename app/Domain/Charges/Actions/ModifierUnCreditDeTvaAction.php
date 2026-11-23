<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Services\GestesSurLeCreditDeTva;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * « Modifier », par le propriétaire de la société, le solde d'une
 * déclaration de crédit de TVA déjà indiquée (lot TVA27) : sa période ne
 * change pas, et une modification d'un crédit vérifié le repasse à
 * vérifier (décision de l'éditeur du 15/11/2026) ; le motif d'une
 * correction du comptable s'efface avec elle. Le comptable, lui, corrige
 * avec un motif (voir CorrigerUnCreditDeTvaAction). Un solde positif est
 * refusé comme à la déclaration, et un solde inchangé ne change rien.
 */
class ModifierUnCreditDeTvaAction
{
    public const REFUS_DROIT = 'Seul le propriétaire modifie le crédit de TVA indiqué : votre comptable le corrige avec un motif.';

    public const REFUS_INCHANGE = 'Ce crédit de TVA est déjà de ce montant.';

    public function __construct(private readonly GestesSurLeCreditDeTva $gestes) {}

    /**
     * @param  string  $credit  l'uuid de la déclaration
     * @param  int  $solde  le nouveau solde en centimes : négatif pour un crédit, 0 sans crédit
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $credit, int $solde, ?string $jeton = null): CreditDeTva
    {
        return $this->gestes->executer($auteur, $societe, ActionsUniques::MODIFIER_UN_CREDIT_DE_TVA, $jeton, ['credit' => $credit, 'solde' => $solde], function (User $auteur, Societe $societe, ValideePar $comme) use ($credit, $solde): CreditDeTva {
            if ($comme !== ValideePar::Proprietaire) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            if ($solde > 0) {
                throw new LogicException(DeclarerUnCreditDeTvaAction::REFUS_POSITIF);
            }

            $relu = $this->gestes->creditVerrouille($societe, $credit);

            if ($relu->solde === $solde) {
                throw new LogicException(self::REFUS_INCHANGE);
            }

            $avant = GestesSurLeCreditDeTva::valeurs($relu);
            $relu->forceFill([
                'solde' => $solde,
                'saisi_par' => $auteur->getKey(),
                'saisi_comme' => ValideePar::Proprietaire,
                'saisi_le' => now(),
                'verifie_par' => null,
                'verifie_comme' => null,
                'verifie_le' => null,
                'motif' => null,
            ])->save();
            $this->gestes->auditer(ActionAuditee::CreditDeTvaModifie, $auteur, $avant, $relu);

            return $relu;
        });
    }
}
