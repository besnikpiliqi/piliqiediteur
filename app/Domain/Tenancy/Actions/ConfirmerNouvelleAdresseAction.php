<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Le propriétaire suit le lien envoyé à l'adresse de connexion qui attend
 * sur son compte (voir DemanderUneNouvelleAdresseAction et
 * ConfirmerNouvelleAdresseController) : sous le verrou de la ligne du
 * compte, le lien encore valable pose cette adresse comme adresse de
 * connexion, avec la date de sa confirmation, et ne sert qu'une fois. Le
 * changement s'inscrit au journal d'audit de la société, sans aucune
 * adresse. Un compte qui n'est plus propriétaire (après une transmission
 * de la propriété) ne confirme plus rien. La clôture d'une inscription
 * efface le lien en attente de son propriétaire (voir
 * CloreLInscriptionAction), et la confirmation relit la société sous un
 * verrou partagé avant le compte, dans le même ordre que la clôture : dans
 * une inscription close, elle est refusée et l'adresse en attente
 * s'efface, quelle que soit la façon dont elle s'est mise en attente.
 *
 * - Une nouvelle adresse demandée dans le profil remplace l'ancienne,
 *   qui en est prévenue ; un lien « Mot de passe oublié » déjà envoyé à
 *   l'ancienne ne sert plus. Le lien se suit sans être connecté.
 * - La première adresse d'un propriétaire qui n'en a encore aucune
 *   (celle qu'il a choisie en saisissant le code du courrier, voir
 *   SaisirCodeDeConfirmationAction) ne se confirme que connecté à ce
 *   compte, par son identifiant et son mot de passe (voir
 *   peutConfirmerDepuis()) : il n'y a aucune ancienne adresse à
 *   prévenir, et une faute de frappe vers l'adresse d'un tiers ne donne
 *   pas à ce tiers le moyen de prendre le compte par « Mot de passe
 *   oublié ». Ailleurs, le lien reste valable et rien ne change.
 *
 * Une adresse prise entre-temps par un autre propriétaire (une adresse en
 * attente ne bloque rien ; l'adresse de contact d'un autre compte n'est pas
 * unique) est refusée, sans dire à qui elle appartient, et le lien ne sert
 * plus : relue sous le verrou, puis, pour deux confirmations simultanées
 * de la même adresse, par l'index unique des adresses de connexion. Le
 * compte se cherche par l'empreinte du jeton, hors de toute société ; tout
 * s'écrit ensuite dans la sienne.
 */
class ConfirmerNouvelleAdresseAction
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable.';

    public const MESSAGE_ADRESSE_INUTILISABLE = 'Cette adresse e-mail ne peut pas être utilisée.';

    public const MESSAGE_CONNEXION_REQUISE = 'Ce lien se suit en étant connecté au compte auquel il est destiné : connectez-vous avec votre identifiant de connexion et votre mot de passe, puis suivez-le de nouveau.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly JetonsDuLien $jetonsDuLien,
    ) {}

    /**
     * L'empreinte d'un jeton, telle qu'elle se garde et se cherche : le
     * jeton lui-même n'est jamais enregistré.
     */
    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * Le compte dont ce jeton confirme l'adresse en attente ; null pour un
     * lien expiré, déjà servi, remplacé ou inconnu.
     */
    public static function compteEnAttente(string $jeton): ?User
    {
        $compte = User::query()->where('nouvelle_adresse_jeton_hache', self::empreinte($jeton))->first();

        return $compte !== null && self::estEnAttente($compte, $jeton) ? $compte : null;
    }

    /**
     * Ce compte peut-il confirmer l'adresse qui attend sur lui depuis la
     * session de cette personne connectée ? Oui pour un propriétaire qui a
     * déjà une adresse de connexion ; pour celui qui n'en a encore aucune,
     * seulement connecté à lui-même.
     */
    public static function peutConfirmerDepuis(User $compte, ?Authenticatable $connecte): bool
    {
        return $compte->aUneAdresseDeConnexion() || ($connecte instanceof User && (int) $connecte->getKey() === (int) $compte->getKey());
    }

    /**
     * @param  Authenticatable|null  $connecte  la personne connectée dans la session qui suit le lien
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(string $jeton, ?Authenticatable $connecte = null): User
    {
        $compte = self::compteEnAttente($jeton) ?? throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
        $societe = Societe::query()->findOrFail($compte->societe_id);

        try {
            $resultat = app(TenantContext::class)->runAsSociete($societe, fn (): array => DB::transaction(fn (): array => $this->confirmerSousVerrou($compte, $jeton, $connecte)));
        } catch (UniqueConstraintViolationException) {
            // Un autre propriétaire a pris l'adresse depuis le contrôle : la
            // même réponse, et le lien ne sert plus.
            app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(fn () => self::oublierLAdresseEnAttente($compte, $jeton)));
            $resultat = ['refus' => self::MESSAGE_ADRESSE_INUTILISABLE, 'ancienne' => null];
        }

        if ($resultat['refus'] !== null) {
            throw ValidationException::withMessages(['email' => $resultat['refus']]);
        }

        $compte->refresh();

        if ($resultat['ancienne'] !== null) {
            Mail::to($resultat['ancienne'])->send(new AdresseChangeeMail($compte->name, now()->setTimezone(config('app.timezone'))->format('d/m/Y à H:i')));
        }

        return $compte;
    }

    /**
     * La confirmation, dans la transaction de l'appelant, la ligne de la
     * société relue sous un verrou partagé, puis celle du compte sous son
     * verrou.
     *
     * @return array{refus: string|null, ancienne: string|null} le motif du refus de l'adresse, ou null quand elle est confirmée, et l'adresse de connexion qu'elle remplace
     *
     * @throws AuthorizationException
     */
    private function confirmerSousVerrou(User $compte, string $jeton, ?Authenticatable $connecte): array
    {
        $close = Societe::query()->whereKey($compte->societe_id)->sharedLock()->value('close_le') !== null;
        $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();

        if (! self::estEnAttente($relu, $jeton)) {
            throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
        }

        if (! self::peutConfirmerDepuis($relu, $connecte)) {
            throw new AuthorizationException(self::MESSAGE_CONNEXION_REQUISE);
        }

        $nouvelle = (string) $relu->nouvelle_adresse;
        $ancienne = $relu->email;
        $refus = match (true) {
            $close => DemanderUneNouvelleAdresseAction::MESSAGE_INSCRIPTION_CLOSE,
            User::query()->where('role_societe', RoleSociete::Proprietaire->value)->where('email', $nouvelle)->whereKeyNot($relu->getKey())->exists() => self::MESSAGE_ADRESSE_INUTILISABLE,
            default => null,
        };

        if ($refus !== null) {
            self::oublierLAdresseEnAttente($relu, $jeton);

            return ['refus' => $refus, 'ancienne' => null];
        }

        if ($ancienne !== null) {
            $this->jetonsDuLien->effacer($ancienne);
        }

        $relu->forceFill([
            'email' => $nouvelle,
            'email_verified_at' => now(),
            'nouvelle_adresse' => null,
            'nouvelle_adresse_jeton_hache' => null,
            'nouvelle_adresse_expire_le' => null,
        ])->save();
        $this->journalAudit->inscrire(ActionAuditee::AdresseDeConnexionChangee, $relu, null, null, [
            'compte' => ['id' => $relu->getKey(), 'nom' => $relu->name],
        ]);

        return ['refus' => null, 'ancienne' => $ancienne];
    }

    /**
     * Efface l'adresse en attente du compte et son lien, si ce lien est
     * encore le sien, dans la transaction de l'appelant et dans la société
     * du compte : une adresse refusée, ou dont le lien n'a pas pu partir
     * (voir SaisirCodeDeConfirmationAction).
     */
    public static function oublierLAdresseEnAttente(User $compte, string $jeton): void
    {
        $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();

        if ($relu->nouvelle_adresse_jeton_hache === self::empreinte($jeton)) {
            $relu->forceFill(['nouvelle_adresse' => null, 'nouvelle_adresse_jeton_hache' => null, 'nouvelle_adresse_expire_le' => null])->save();
        }
    }

    /**
     * Ce compte, encore propriétaire, attend-il l'adresse que confirme ce
     * jeton, avant la fin de sa validité ?
     */
    private static function estEnAttente(User $compte, string $jeton): bool
    {
        return $compte->estProprietaire()
            && $compte->nouvelle_adresse_jeton_hache === self::empreinte($jeton)
            && (bool) $compte->nouvelle_adresse_expire_le?->isFuture();
    }
}
