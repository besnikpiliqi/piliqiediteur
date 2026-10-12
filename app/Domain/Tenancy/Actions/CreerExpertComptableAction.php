<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Le propriétaire, et lui seul, crée le compte d'un expert-comptable de la
 * société, un par collaborateur du cabinet : son identité et son mot de
 * passe provisoire remis de la main à la main (voir CreationDesComptes),
 * sans affectation ni case. Il lit chaque magasin réel de la société,
 * présent ou futur, et n'écrit rien (voir ControleDesDroits) ; chacun signe
 * ses propres consultations.
 *
 * Contrôlé sur l'auteur relu sous les verrous des registres de tous les
 * magasins de la société et de sa ligne ; ses droits s'inscrivent au JET
 * 130 de chaque magasin réel (avant nul, après relu), puis au journal
 * d'audit (voir JournalDesDroits).
 */
class CreerExpertComptableAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire crée, suspend, réactive, retire et réintègre un expert-comptable.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
    ) {}

    /**
     * @param  array{name?: mixed, email?: mixed}  $identite
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $auteur, Societe $societe, array $identite, #[SensitiveParameter] string $motDePasseProvisoire): User
    {
        return $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $societe, $identite, $motDePasseProvisoire): User {
            self::exigerLeProprietaire($auteur, $societe);
            $identiteValide = $this->creation->valider($auteur, $societe, $identite, $motDePasseProvisoire);

            return $this->journalDesDroits->modifier($auteur, ActionAuditee::ExpertComptableCree, [], Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get(), function (User $auteurRelu) use ($societe, $identiteValide, $motDePasseProvisoire): User {
                self::exigerLeProprietaire($auteurRelu, $societe);

                return $this->creation->creer($auteurRelu, $societe, $identiteValide, $motDePasseProvisoire, RoleSociete::ExpertComptable);
            });
        });
    }

    /**
     * @throws AuthorizationException
     */
    private static function exigerLeProprietaire(User $auteur, Societe $societe): void
    {
        if (! $auteur->peutAgir() || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }
}
