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
 * « Corriger », par le comptable de la société, le solde d'une déclaration
 * de crédit de TVA (lot TVA27, décision de l'éditeur du 15/11/2026) : le
 * nouveau solde, avec un motif obligatoire ; la correction vaut
 * vérification, avec son nom et la date, et le journal d'audit garde
 * l'ancienne et la nouvelle valeur. Un solde positif est refusé comme à la
 * déclaration ; un solde inchangé se confirme plutôt (voir
 * ConfirmerUnCreditDeTvaAction).
 */
class CorrigerUnCreditDeTvaAction
{
    public const REFUS_DROIT = 'Seul le comptable de la société corrige son crédit de TVA : le propriétaire le modifie.';

    public const REFUS_SANS_MOTIF = 'Dites pourquoi vous corrigez ce crédit de TVA.';

    public const REFUS_INCHANGE = 'Le montant n\'a pas changé : confirmez plutôt ce crédit de TVA.';

    public function __construct(private readonly GestesSurLeCreditDeTva $gestes) {}

    /**
     * @param  string  $credit  l'uuid de la déclaration
     * @param  int  $solde  le solde corrigé en centimes : négatif pour un crédit, 0 sans crédit
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $credit, int $solde, string $motif, ?string $jeton = null): CreditDeTva
    {
        $motif = trim($motif);

        return $this->gestes->executer($auteur, $societe, ActionsUniques::CORRIGER_UN_CREDIT_DE_TVA, $jeton, ['credit' => $credit, 'solde' => $solde, 'motif' => $motif], function (User $auteur, Societe $societe, ValideePar $comme) use ($credit, $solde, $motif): CreditDeTva {
            if ($comme === ValideePar::Proprietaire) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            if ($motif === '') {
                throw new LogicException(self::REFUS_SANS_MOTIF);
            }

            if ($solde > 0) {
                throw new LogicException(DeclarerUnCreditDeTvaAction::REFUS_POSITIF);
            }

            $relu = $this->gestes->creditVerrouille($societe, $credit);

            if ($relu->solde === $solde) {
                throw new LogicException(self::REFUS_INCHANGE);
            }

            $avant = GestesSurLeCreditDeTva::valeurs($relu);
            $relu->forceFill(['solde' => $solde, 'verifie_par' => $auteur->getKey(), 'verifie_comme' => $comme, 'verifie_le' => now(), 'motif' => $motif])->save();
            $this->gestes->auditer(ActionAuditee::CreditDeTvaCorrige, $auteur, $avant, $relu);

            return $relu;
        });
    }
}
