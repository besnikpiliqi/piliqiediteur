<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * « Ouvrir ma démonstration » (lot DEMO-COPIE, B.4) : le site réel signe
 * un billet d'entrée (voir MessagesSignes::signerUnBillet()), valable 60
 * secondes, pour une seule entrée, qui porte la référence de la copie ; la
 * page du billet l'envoie au site de démonstration par un formulaire qui
 * part seul, dans un nouvel onglet (voir OuvrirMaDemonstrationController).
 * Le lien n'est pas nécessaire : le propriétaire entre en un clic, même
 * après un renouvellement.
 *
 * Le propriétaire seul, d'une démonstration vivante (une copie encore en
 * préparation ouvre la page d'attente du site de démonstration) ; permis
 * à une société suspendue ou résiliée. Au plus 30 ouvertures par heure et
 * par société ; chaque ouverture s'inscrit au journal d'audit, sans la
 * référence.
 */
final class OuvrirLaDemonstrationAction
{
    use AgitDansLaSociete;

    public const PAR_HEURE = 30;

    public const MESSAGE_TROP_D_OUVERTURES = 'Vous avez déjà ouvert votre démonstration 30 fois dans l\'heure. Réessayez dans une heure.';

    public const MESSAGE_AUCUNE_DEMONSTRATION = 'Votre société n\'a pas de démonstration à ouvrir : créez-la depuis votre tableau de bord.';

    public function __construct(
        private readonly ConditionsDeLaDemonstration $conditions,
        private readonly MessagesSignes $messages,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @return array{message: string, signature: string} le billet signé
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur): array
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        return $this->dansLaSociete($societe, ConditionsDeLaDemonstration::REFUS, function () use ($auteur, $societe): array {
            $this->conditions->exiger($auteur, $societe, envoieLeLien: false, creeOuRecommence: false);

            return DB::transaction(function () use ($auteur, $societe): array {
                Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
                $demonstration = Demonstration::query()
                    ->whereIn('etat', [...EtatDeLaDemonstration::valeursEnCours(), EtatDeLaDemonstration::EnEchec->value])
                    ->first() ?? throw new LogicException(self::MESSAGE_AUCUNE_DEMONSTRATION);
                $cle = 'demonstration-ouvertures:'.CleDuModele::de($societe);

                if (RateLimiter::tooManyAttempts($cle, self::PAR_HEURE)) {
                    throw new LogicException(self::MESSAGE_TROP_D_OUVERTURES);
                }

                RateLimiter::hit($cle, 3600);
                $this->journalAudit->inscrire(ActionAuditee::DemonstrationOuverteParLeBouton, $auteur, null, null, ['repere' => $demonstration->repere], $demonstration);

                return $this->messages->signerUnBillet($demonstration->reference);
            });
        });
    }
}
