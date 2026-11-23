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
 * « Confirmer », par le comptable de la société (le cabinet lié ou
 * l'expert-comptable déclaré, qui a « Charges : voir, ajouter, saisir et
 * valider »), le crédit de TVA qu'a indiqué le propriétaire (lot TVA27,
 * décision de l'éditeur du 15/11/2026) : il devient « Vérifié par le
 * comptable », avec son nom et la date. Un crédit déjà vérifié ne se
 * confirme pas deux fois.
 */
class ConfirmerUnCreditDeTvaAction
{
    public const REFUS_DROIT = 'Seul le comptable de la société vérifie son crédit de TVA.';

    public const REFUS_DEJA_VERIFIE = 'Ce crédit de TVA est déjà vérifié.';

    public function __construct(private readonly GestesSurLeCreditDeTva $gestes) {}

    /**
     * @param  string  $credit  l'uuid de la déclaration
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $credit, ?string $jeton = null): CreditDeTva
    {
        return $this->gestes->executer($auteur, $societe, ActionsUniques::CONFIRMER_UN_CREDIT_DE_TVA, $jeton, ['credit' => $credit], function (User $auteur, Societe $societe, ValideePar $comme) use ($credit): CreditDeTva {
            if ($comme === ValideePar::Proprietaire) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            $relu = $this->gestes->creditVerrouille($societe, $credit);

            if ($relu->estVerifie()) {
                throw new LogicException(self::REFUS_DEJA_VERIFIE);
            }

            $avant = GestesSurLeCreditDeTva::valeurs($relu);
            $relu->forceFill(['verifie_par' => $auteur->getKey(), 'verifie_comme' => $comme, 'verifie_le' => now()])->save();
            $this->gestes->auditer(ActionAuditee::CreditDeTvaConfirme, $auteur, $avant, $relu);

            return $relu;
        });
    }
}
