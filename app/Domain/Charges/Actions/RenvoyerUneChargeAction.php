<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
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
 * « Renvoyer au client » une charge à valider ou une pièce sans chiffres
 * (décision de l'éditeur du 14/11/2026, lot CHARGES), par le comptable de
 * la société (son cabinet lié ou son expert-comptable déclaré), avec un
 * mot obligatoire (« pièce illisible ») : la charge revient au client,
 * qui la corrige ou en remplace la pièce, et elle repasse « à valider »
 * (voir SaisirUneChargeAction) ; elle entre dans l'« À faire » du
 * propriétaire et de qui l'a déposée. Le renvoi s'inscrit au journal
 * d'audit, avec le mot.
 */
class RenvoyerUneChargeAction
{
    public const REFUS_DROIT = 'Seul votre comptable renvoie une charge au client.';

    public const REFUS_ETAT = 'Seule une charge à valider, ou une pièce sans chiffres, se renvoie au client.';

    public const REFUS_SANS_MOT = 'Écrivez au client pourquoi vous lui renvoyez cette charge.';

    public function __construct(
        private readonly GestesSurLesCharges $gestes,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $charge, string $mot, ?string $jeton = null): Charge
    {
        $mot = Str::squish($mot);

        if ($mot === '') {
            throw new InvalidArgumentException(self::REFUS_SANS_MOT);
        }

        $resultat = $this->gestes->executer($auteur, $societe, ActionsUniques::RENVOYER_UNE_CHARGE, $jeton, ['charge' => $charge, 'mot' => $mot], function (AccesAuxCharges $acces) use ($charge, $mot): Charge {
            if (! $acces->estComptable()) {
                throw new AuthorizationException(self::REFUS_DROIT);
            }

            $relue = $this->gestes->chargeVerrouillee($acces, $charge);

            if (! in_array($relue->etat, [EtatDeLaCharge::AValider, EtatDeLaCharge::PieceSansChiffres], true)) {
                throw new LogicException($relue->estValidee() ? ValiderUneChargeAction::REFUS_DEJA_VALIDEE : self::REFUS_ETAT);
            }

            $avant = ChargesDeLaSociete::valeurs($relue);
            $relue->forceFill([
                'etat' => EtatDeLaCharge::Renvoyee,
                'motif_renvoi' => $mot,
                'renvoyee_par' => $acces->compte->getKey(),
                'renvoyee_le' => now(),
            ])->save();
            $this->journalAudit->inscrire(ActionAuditee::ChargeRenvoyee, $acces->compte, $relue->magasin, ['etat' => $avant['etat']], ['charge' => $avant['charge'], 'etat' => $relue->etat->value, 'motif' => $mot], $relue);

            return $relue;
        });

        return $resultat ?? throw new LogicException(GestesSurLesCharges::CHARGE_INTROUVABLE);
    }
}
