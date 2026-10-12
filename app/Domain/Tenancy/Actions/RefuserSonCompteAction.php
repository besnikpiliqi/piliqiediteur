<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * La personne refuse le compte que la société a créé à son adresse, tant
 * que cette adresse n'a jamais été confirmée (voir
 * User::peutRefuserSonCompte()) : à sa connexion avec le mot de passe
 * provisoire (voir ChangerMotDePasseProvisoire), ou sur la page d'un lien
 * envoyé à son adresse (voir PageDuLien), même si le provisoire a expiré,
 * si le compte est suspendu ou s'il a été retiré par la société. Le compte
 * est désigné par la session de la personne ou par le jeton du lien,
 * jamais par un identifiant de la requête ; personne ne refuse le compte
 * d'un autre. Le compte du propriétaire ne se refuse jamais.
 *
 * Sous les verrous des registres des magasins du compte et de sa ligne, la
 * condition relue, le refus le retire comme un retrait (voir
 * JournalDesDroits : JET 130 de chaque magasin touché, journal d'audit) :
 * ses affectations et ses autorisations de société disparaissent, un
 * administrateur ou un expert-comptable repasse membre, ses notifications
 * s'effacent ; il est retiré par lui-même, si bien que la société ne peut
 * plus le réintégrer sans son accord (voir PorteeDesDroits). Son mot de
 * passe devient inutilisable (l'empreinte d'une valeur aléatoire que
 * personne ne connaît) et toutes ses sessions se ferment : ni l'auteur qui
 * avait fixé le provisoire, ni personne d'autre ne peut plus s'en servir.
 * La personne choisira son mot de passe sur la page d'un lien envoyé à son
 * adresse, avant de créer sa société ou d'accepter un rattachement.
 *
 * Qui avait créé le compte en est prévenu s'il est encore actif dans la
 * société, à défaut le propriétaire, avec seulement ce qu'il savait déjà :
 * le nom et l'adresse du compte.
 */
class RefuserSonCompteAction
{
    use AgitDansLaSociete;

    public const MESSAGE_NON_REFUSABLE = 'Ce compte ne peut plus être refusé.';

    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable : demandez-en un nouveau.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @param  (Closure(User): bool)|null  $consommerLeLien  depuis la page d'un lien : consomme son jeton dans la transaction du refus, et dit s'il était valable ; le lien suivi confirme alors l'adresse
     *
     * @throws AuthorizationException quand le compte ne peut pas, ou plus, être refusé, ou que le lien n'est plus valable
     */
    public function handle(User $compte, ?Closure $consommerLeLien = null): void
    {
        $societe = $compte->societe()->firstOrFail();

        $this->dansLaSociete($societe, self::MESSAGE_NON_REFUSABLE, function () use ($compte, $societe, $consommerLeLien): void {
            $this->journalDesDroits->modifier($compte, ActionAuditee::CompteRefuse, [$compte], [], function (User $compteRelu) use ($consommerLeLien): void {
                if ($consommerLeLien !== null && ! $consommerLeLien($compteRelu)) {
                    throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
                }

                if (! $compteRelu->peutRefuserSonCompte()) {
                    throw new AuthorizationException(self::MESSAGE_NON_REFUSABLE);
                }

                ChangerStatutCompteAction::retirerLesDroits($compteRelu);
                $compteRelu->forceFill([
                    'statut' => StatutCompte::Retire,
                    'statut_change_par' => $compteRelu->getKey(),
                    'password' => Str::random(64),
                    ...($consommerLeLien === null ? [] : ['email_verified_at' => now()]),
                ])->save();
                $compteRelu->effacerToutesSesNotifications();
                $this->sessions->fermer($compteRelu);
            });

            $compte->refresh();
            $this->prevenir($compte, $societe->proprietaire()->first());
        });
    }

    /**
     * Prévient qui avait créé le compte, s'il est encore actif dans la
     * société, sinon le propriétaire.
     */
    private function prevenir(User $compte, ?User $proprietaire): void
    {
        $createur = $compte->createur()->first();
        $destinataire = $createur !== null && $createur->estActif() && (int) $createur->societe_id === (int) $compte->societe_id ? $createur : $proprietaire;

        $destinataire?->notify(new AvisDeLEquipe(
            $compte->societe()->firstOrFail(),
            'Compte refusé',
            "{$compte->name} ({$compte->email}) a refusé le compte créé à son adresse : il est retiré de la société, et ne pourra la rejoindre de nouveau qu'avec son accord.",
            nature: AvisDeLEquipe::ATTENTION,
        ));
    }
}
