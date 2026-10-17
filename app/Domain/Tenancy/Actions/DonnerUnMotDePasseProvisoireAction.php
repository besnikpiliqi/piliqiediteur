<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Notifications\MotDePasseProvisoireRenouvele;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Redonne un mot de passe provisoire, valable 72 heures, à un compte dont
 * le mot de passe est encore un provisoire de la société (la personne ne
 * l'a jamais changé) : par le propriétaire, ou par un responsable de tous
 * les magasins d'un vendeur ou d'un observateur, jamais par un
 * administrateur (voir PorteeDesDroits::peutDonnerUnProvisoire()). Un
 * compte qui a choisi son mot de passe ne reçoit qu'un lien (voir
 * EnvoyerUnLienDeReinitialisationAction) : personne d'autre que lui ne le
 * connaît jamais.
 *
 * Contrôlé sur l'auteur et le compte relus sous le verrou de leurs lignes.
 * L'auteur, qui connaît désormais ce provisoire, est noté sur le compte
 * (users.provisoire_donne_par) : tant que la personne n'a pas choisi son
 * mot de passe, le compte ne reçoit plus de nouveaux droits que de
 * l'auteur du compte, s'il est aussi celui qui lui a donné ce provisoire
 * (voir User::recoitDeNouveauxDroitsDe()). Les sessions du compte se
 * ferment, la personne est prévenue (sans le mot de passe, remis de la
 * main à la main) et le geste s'inscrit au journal d'audit, jamais avec
 * le mot de passe.
 */
class DonnerUnMotDePasseProvisoireAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly SessionsDesComptes $sessions,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $auteur, User $compte, #[SensitiveParameter] string $motDePasseProvisoire): void
    {
        Validator::make(['password' => $motDePasseProvisoire], [
            'password' => ['required', 'string', 'min:'.CreationDesComptes::LONGUEUR_MINIMALE_DU_PROVISOIRE],
        ], ['password.min' => CreationDesComptes::MESSAGE_PROVISOIRE_TROP_COURT])->validate();

        $auteurRelu = $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, fn (): User => $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus) use ($motDePasseProvisoire): User {
            [$compteRelu] = $relus;

            if (! $this->portee->peutDonnerUnProvisoire($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $compteRelu->forceFill([
                'password' => $motDePasseProvisoire,
                'mot_de_passe_a_changer' => true,
                'mot_de_passe_provisoire_expire_le' => now()->addHours(CreationDesComptes::HEURES_DU_PROVISOIRE),
                'provisoire_donne_par' => $auteurRelu->getKey(),
            ])->save();
            $this->sessions->fermer($compteRelu);
            $this->journalAudit->inscrire(ActionAuditee::MotDePasseProvisoireDonne, $auteurRelu, null, null, [
                'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
            ], $compteRelu);

            return $auteurRelu;
        }));

        $compte->refresh()->notify(new MotDePasseProvisoireRenouvele($auteurRelu->name));
    }
}
