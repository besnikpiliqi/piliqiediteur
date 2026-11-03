<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Mail\InvitationACreerUnEspaceMail;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Le propriétaire d'une société invite son expert-comptable, dont le
 * cabinet n'a pas encore d'espace, à en créer un (voir
 * SocietePolicy::gererSonCabinet()) : un e-mail à l'adresse saisie, dont
 * le lien mène à l'inscription, « Cabinet d'expertise comptable »
 * présélectionné. L'adresse n'est gardée nulle part, ni au journal d'audit
 * ; au plus 5 par jour et par société, dans la limite d'e-mails de
 * l'auteur. Une fois le cabinet vérifié, l'une ou l'autre partie crée le
 * lien.
 */
class InviterSonCabinetACreerSonEspaceAction
{
    public const PAR_JOUR = 5;

    public const MESSAGE_TROP = 'Vous avez déjà envoyé 5 invitations à créer un espace de cabinet aujourd\'hui : réessayez demain.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $client, string $email): void
    {
        $email = User::normaliserEmail(Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->validate()['email']);
        $auteurRelu = $this->journalDesDroits->exigerDansSaSociete($auteur, 'gererSonCabinet', $client);
        $this->debit->verifier($auteurRelu);

        if (! RateLimiter::attempt("espace-cabinet:{$client->getKey()}", self::PAR_JOUR, static fn (): bool => true, 86400)) {
            throw new LogicException(self::MESSAGE_TROP);
        }

        Mail::to($email)->send(new InvitationACreerUnEspaceMail(
            route('filament.app.auth.register', ['type' => TypeDActivite::Cabinet->value]),
            $client->getFilamentName(),
        ));
        $this->debit->compter($auteurRelu);
        app(TenantContext::class)->runAsSociete($client, fn () => $this->journalAudit->inscrire(ActionAuditee::EspaceCabinetSuggere, $auteurRelu, null, null, ['envoye' => true], $client));
    }
}
