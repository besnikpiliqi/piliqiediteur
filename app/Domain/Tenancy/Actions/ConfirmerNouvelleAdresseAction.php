<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Le propriétaire suit le lien envoyé à la nouvelle adresse de connexion
 * qu'il a demandée dans son profil (voir DemanderUneNouvelleAdresseAction
 * et ConfirmerNouvelleAdresseController) : sous le verrou de la ligne du
 * compte, le lien encore valable pose la nouvelle adresse et la date de sa
 * confirmation, et ne sert qu'une fois. Un lien « Mot de passe oublié »
 * déjà envoyé à l'ancienne adresse ne sert plus. Le changement s'inscrit au
 * journal d'audit de la société, sans aucune adresse, et l'ancienne
 * adresse en est prévenue. Un compte qui n'est plus propriétaire (après une
 * transmission de la propriété) ne confirme plus rien.
 *
 * Une adresse prise entre-temps par un autre propriétaire (une adresse en
 * attente ne bloque rien ; l'adresse de contact d'un autre compte n'est pas
 * unique) est refusée, sans dire à qui elle appartient, et le lien ne sert
 * plus. Le compte se cherche par l'empreinte du jeton, hors de toute
 * société ; tout s'écrit ensuite dans la sienne.
 */
class ConfirmerNouvelleAdresseAction
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable.';

    public const MESSAGE_ADRESSE_INUTILISABLE = 'Cette adresse e-mail ne peut pas être utilisée.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
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
     * Le compte dont ce jeton confirme la nouvelle adresse ; null pour un
     * lien expiré, déjà servi, remplacé ou inconnu.
     */
    public static function compteEnAttente(string $jeton): ?User
    {
        $compte = User::query()->where('nouvelle_adresse_jeton_hache', self::empreinte($jeton))->first();

        return $compte !== null && $compte->estProprietaire() && $compte->nouvelle_adresse_expire_le?->isFuture() ? $compte : null;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(string $jeton): User
    {
        $compte = self::compteEnAttente($jeton) ?? throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
        $societe = Societe::query()->findOrFail($compte->societe_id);

        try {
            $ancienne = app(TenantContext::class)->runAsSociete($societe, fn (): ?string => DB::transaction(function () use ($compte, $jeton): ?string {
                $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();

                if (! $relu->estProprietaire() || $relu->nouvelle_adresse_jeton_hache !== self::empreinte($jeton) || ! $relu->nouvelle_adresse_expire_le?->isFuture()) {
                    throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
                }

                $nouvelle = (string) $relu->nouvelle_adresse;
                $ancienne = $relu->email;
                $relu->forceFill(['nouvelle_adresse' => null, 'nouvelle_adresse_jeton_hache' => null, 'nouvelle_adresse_expire_le' => null]);

                if (User::query()->where('role_societe', RoleSociete::Proprietaire->value)->where('email', $nouvelle)->whereKeyNot($relu->getKey())->exists()) {
                    $relu->save();

                    return null;
                }

                DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))->where('email', $ancienne)->delete();
                $relu->forceFill(['email' => $nouvelle, 'email_verified_at' => now()])->save();
                $this->journalAudit->inscrire(ActionAuditee::AdresseDeConnexionChangee, $relu, null, null, [
                    'compte' => ['id' => $relu->getKey(), 'nom' => $relu->name],
                ]);

                return $ancienne;
            }));
        } catch (UniqueConstraintViolationException) {
            // Un autre propriétaire a pris l'adresse depuis le contrôle : la
            // même réponse, et rien n'est changé.
            $ancienne = null;
        }

        if ($ancienne === null) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
        }

        $compte->refresh();
        Mail::to($ancienne)->send(new AdresseChangeeMail($compte->name, now()->setTimezone(config('app.timezone'))->format('d/m/Y à H:i')));

        return $compte;
    }
}
