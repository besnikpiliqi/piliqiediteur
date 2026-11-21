<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * « Recommencer à zéro » (lot DEMO-COPIE, B.7) : la démonstration repart
 * de la société fictive de départ ; le lien reste le même, la date de fin
 * ne bouge pas (choix 11). Le message « recommencer » part au site de
 * démonstration, qui archive la caisse de la copie avant de la refaire.
 * Au plus 3 fois par jour et par société ; ni dans une société suspendue
 * ou résiliée (choix 12). La carte dit ensuite « Remise à zéro le … ».
 */
final class RecommencerLaDemonstrationAction
{
    use AgitDansLaSociete;

    public const ACTION = 'demonstration.recommencer';

    public const PAR_JOUR = 3;

    public const MESSAGE_TROP_DE_REMISES_A_ZERO = 'Vous avez déjà remis votre démonstration à zéro 3 fois aujourd\'hui. Réessayez demain.';

    public function __construct(
        private readonly ConditionsDeLaDemonstration $conditions,
        private readonly ActionsUniques $actionsUniques,
        private readonly BoiteDEnvoi $boite,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, ?string $jeton): Demonstration
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        return $this->dansLaSociete($societe, ConditionsDeLaDemonstration::REFUS, function () use ($auteur, $societe, $jeton): Demonstration {
            $this->conditions->exiger($auteur, $societe, envoieLeLien: false, creeOuRecommence: true);

            $resultat = $this->actionsUniques->executerDansLaSociete(
                $jeton,
                self::ACTION,
                Demonstration::class,
                $auteur,
                ['societe' => $societe->uuid],
                function () use ($auteur, $societe): void {
                    Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
                    User::query()->whereKey($auteur->getKey())->lock('for no key update')->first();
                },
                function () use ($auteur, $societe): Demonstration {
                    $auteurRelu = User::query()->findOrFail(CleDuModele::de($auteur));
                    $this->conditions->exiger($auteurRelu, Societe::query()->findOrFail(CleDuModele::de($societe)), envoieLeLien: false, creeOuRecommence: true);
                    $demonstration = Demonstration::query()->where('etat', EtatDeLaDemonstration::Prete->value)->lockForUpdate()->first()
                        ?? throw new LogicException(RenouvelerLeLienDeLaDemonstrationAction::MESSAGE_AUCUNE_DEMONSTRATION);
                    $cle = 'demonstration-remises-a-zero:'.CleDuModele::de($societe);

                    if (RateLimiter::tooManyAttempts($cle, self::PAR_JOUR)) {
                        throw new LogicException(self::MESSAGE_TROP_DE_REMISES_A_ZERO);
                    }

                    RateLimiter::hit($cle, 86400);
                    $demonstration->forceFill(['recommencee_le' => CarbonImmutable::now()])->save();
                    $this->boite->recommencer($demonstration);
                    $this->journalAudit->inscrire(ActionAuditee::DemonstrationRecommencee, $auteurRelu, null, null, ['repere' => $demonstration->repere], $demonstration);

                    return $demonstration;
                },
            );

            return $resultat instanceof Demonstration ? $resultat : throw new LogicException(RenouvelerLeLienDeLaDemonstrationAction::MESSAGE_AUCUNE_DEMONSTRATION);
        });
    }
}
