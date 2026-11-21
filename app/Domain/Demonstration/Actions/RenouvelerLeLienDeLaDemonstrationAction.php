<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Jobs\EnvoyerLeLienDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use SensitiveParameter;

/**
 * « Renouveler le lien » (lot DEMO-COPIE, B.6) : le propriétaire, en
 * redonnant son mot de passe, tire un nouveau lien (génération suivante),
 * l'affiche une seule fois et l'envoie par e-mail, comme à la création.
 * Le message « renouveler_le_lien » part au site de démonstration, qui
 * retire l'ancienne empreinte et tous les témoins de la copie, ceux du
 * bouton compris (choix 10) : toutes les visites ouvertes se ferment, et
 * l'ancien propriétaire, un poste prêté ou un lien perdu sont coupés d'un
 * seul geste. La date de fin ne change pas.
 *
 * Permis aussi à une société suspendue ou résiliée (son propriétaire doit
 * pouvoir couper un lien perdu). Au plus 5 renouvellements par jour et
 * par société. Une demande répétée après une réponse perdue rend la même
 * démonstration, sans le lien.
 */
final class RenouvelerLeLienDeLaDemonstrationAction
{
    use AgitDansLaSociete;

    public const ACTION = 'demonstration.renouveler-le-lien';

    public const PAR_JOUR = 5;

    public const MESSAGE_TROP_DE_RENOUVELLEMENTS = 'Vous avez déjà renouvelé ce lien 5 fois aujourd\'hui. Réessayez demain.';

    public const MESSAGE_AUCUNE_DEMONSTRATION = 'Votre société n\'a pas de démonstration prête : il n\'y a aucun lien à renouveler.';

    public function __construct(
        private readonly ConditionsDeLaDemonstration $conditions,
        private readonly ActionsUniques $actionsUniques,
        private readonly BoiteDEnvoi $boite,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, #[SensitiveParameter] string $motDePasse, ?string $jeton): LienTire
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);
        $lien = null;

        $demonstration = $this->dansLaSociete($societe, ConditionsDeLaDemonstration::REFUS, function () use ($auteur, $societe, $motDePasse, $jeton, &$lien): Demonstration {
            $this->conditions->exiger($auteur, $societe, envoieLeLien: true, creeOuRecommence: false);
            MotDePasseRedemande::exiger($auteur, $motDePasse);
            $this->debit->verifier($auteur);

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
                function () use ($auteur, $societe, &$lien): Demonstration {
                    $auteurRelu = User::query()->findOrFail(CleDuModele::de($auteur));
                    $this->conditions->exiger($auteurRelu, Societe::query()->findOrFail(CleDuModele::de($societe)), envoieLeLien: true, creeOuRecommence: false);
                    $demonstration = Demonstration::query()->where('etat', EtatDeLaDemonstration::Prete->value)->lockForUpdate()->first()
                        ?? throw new LogicException(self::MESSAGE_AUCUNE_DEMONSTRATION);
                    $cle = 'demonstration-renouvellements:'.CleDuModele::de($societe);

                    if (RateLimiter::tooManyAttempts($cle, self::PAR_JOUR)) {
                        throw new LogicException(self::MESSAGE_TROP_DE_RENOUVELLEMENTS);
                    }

                    RateLimiter::hit($cle, 86400);
                    $lien = DemanderUneDemonstrationAction::tirerUnLien();
                    $demonstration->forceFill([
                        'empreinte_du_lien' => Demonstration::empreinteDuLien($lien),
                        'generation_du_lien' => $demonstration->generation_du_lien + 1,
                        'lien_tire_le' => CarbonImmutable::now(),
                        'lien_tire_par' => $auteurRelu->getKey(),
                    ])->save();
                    $this->boite->renouvelerLeLien($demonstration);
                    $this->journalAudit->inscrire(ActionAuditee::LienDeDemonstrationRenouvele, $auteurRelu, null, null, [
                        'repere' => $demonstration->repere,
                        'generation' => $demonstration->generation_du_lien,
                    ], $demonstration);

                    return $demonstration;
                },
            );

            return $resultat instanceof Demonstration ? $resultat : throw new LogicException(self::MESSAGE_AUCUNE_DEMONSTRATION);
        });

        if ($lien !== null) {
            EnvoyerLeLienDeLaDemonstration::envoyer($auteur, $demonstration, $lien);
            $this->debit->compter($auteur);
        }

        return new LienTire($demonstration, $lien);
    }
}
