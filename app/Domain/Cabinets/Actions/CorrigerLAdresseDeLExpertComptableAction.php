<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesExpertsComptables;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * Le propriétaire corrige l'adresse d'un expert-comptable de la société
 * qui n'a pas encore choisi son mot de passe, ni par un lien, ni en sa
 * présence (décision de l'éditeur du 01/11/2026, P5) : il redonne son mot
 * de passe et tape deux fois la nouvelle adresse ; le lien d'avant ne vaut
 * plus et un nouveau lien de 7 jours part à la nouvelle adresse, dans la
 * limite de 5 liens par jour et par compte. La même adresse ne change rien.
 * Une fois son mot de passe choisi, seule la personne change son adresse,
 * dans son profil. L'entrée d'audit ne retient que le compte, jamais
 * l'adresse. Les verrous suivent l'ordre de l'espace des cabinets : la
 * ligne de la société puis la déclaration du compte, avant les comptes
 * (voir ExpertsComptablesDeclares::verrouillerLaDeclarationDe()) ; la garde
 * de l'adresse prend ensuite la déclaration en partage, déjà tenue, si bien
 * que la correction attend un rattachement ou un réglage en cours au lieu
 * de s'interbloquer avec lui.
 */
class CorrigerLAdresseDeLExpertComptableAction
{
    use AgitDansLaSociete;

    public const MESSAGE_MOT_DE_PASSE_DEJA_CHOISI = 'Cette personne a déjà choisi son mot de passe : elle seule change son adresse, dans son profil.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
        private readonly EnvoiDesLiensDesExpertsComptables $envoi,
        private readonly ExpertsComptablesDeclares $experts,
    ) {}

    /**
     * @return bool faux quand l'adresse était déjà celle-ci (rien n'a changé)
     *
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $compte, mixed $adresse, mixed $confirmation, #[SensitiveParameter] string $motDePasse): bool
    {
        $nouvelle = $this->creation->validerLAdresse($adresse, $confirmation);
        MotDePasseRedemande::exiger($auteur, $motDePasse);
        $cle = 'lien-expert-comptable:'.CleDuModele::de($compte);
        $societe = $compte->societe()->firstOrFail();

        $lien = $this->dansLaSociete($societe, PorteeDesDroits::REFUS, fn (): ?array => $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus) use ($nouvelle, $cle): ?array {
            [$compteRelu] = $relus;
            $attachement = CompteExpertComptable::query()->where('societe_id', $compteRelu->societe_id)->where('user_id', $compteRelu->getKey())->lockForUpdate()->first();

            if ($attachement?->aChoisiSonMotDePasse() === true && $compteRelu->estExpertComptable() && $this->portee->gereSonEquipe($auteurRelu) && $auteurRelu->estProprietaire()) {
                throw new LogicException(self::MESSAGE_MOT_DE_PASSE_DEJA_CHOISI);
            }

            if ($attachement === null || ! $this->portee->peutCorrigerLAdresseDeLExpertComptable($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            if ($compteRelu->email === $nouvelle) {
                return null;
            }

            if (RateLimiter::tooManyAttempts($cle, RedonnerLAccesAction::LIENS_PAR_JOUR)) {
                throw new LogicException(RedonnerLAccesAction::MESSAGE_TROP_DE_LIENS);
            }

            $this->debit->verifier($auteurRelu);
            $compteRelu->forceFill(['email' => $nouvelle])->save();
            $jetonDuLien = $attachement->nouveauLien();
            $this->journalAudit->inscrire(ActionAuditee::AdresseDExpertComptableChangee, $auteurRelu, null, null, [
                'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
            ]);

            return ['jeton' => $jetonDuLien, 'auteur' => CleDuModele::de($auteurRelu)];
        }, fn () => $this->experts->verrouillerLaDeclarationDe($compte)));

        if ($lien === null) {
            return false;
        }

        RateLimiter::hit($cle, 86400);
        $this->debit->compter($auteur);
        $compte->refresh();
        $this->envoi->envoyer(CleDuModele::de($compte), $lien['jeton'], $lien['auteur'], accesRedonne: false);

        return true;
    }
}
