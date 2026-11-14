<?php

namespace App\Domain\Cabinets\Models;

use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * L'invitation qu'un cabinet d'expertise comptable vérifié envoie à une
 * entreprise pas encore inscrite, par son SIREN, à l'adresse e-mail que le
 * cabinet a saisie (voir InviterUneEntrepriseAction) ; sa société est le
 * cabinet. Le lien de l'e-mail porte un jeton dont seule l'empreinte
 * SHA-256 est gardée ; il vaut 7 jours et ne sert qu'une fois (voir
 * AccepterLInvitationAction et RefuserLInvitationAction).
 *
 * Seule lecture hors de la société active : celle de l'invitation que
 * désigne le lien de l'e-mail, avant qu'aucune société ne soit ouverte
 * (voir parSonJeton()).
 *
 * Son échéance (voir Instant) n'est jamais nulle : sa colonne ne l'est pas
 * en base.
 *
 * @property string $siren
 * @property string $email
 * @property EtatInvitation $etat
 * @property CarbonImmutable $expire_le
 */
class InvitationCabinet extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    public const DUREE_EN_JOURS = 7;

    protected $table = 'invitations_cabinet';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'etat' => EtatInvitation::class,
        'expire_le' => Instant::class,
        'close_le' => Instant::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (InvitationCabinet $invitation): void {
            $invitation->uuid ??= (string) Str::uuid();
        });
    }

    protected static function colonnesAuNomFige(): array
    {
        return ['invitee_par' => 'invitee_par_nom', 'retiree_par' => 'retiree_par_nom'];
    }

    /**
     * Celle de cet uuid, venu d'une adresse ou d'un geste de l'écran : un
     * texte qui n'est pas un uuid ne désigne rien, sans interroger la
     * colonne, de type uuid.
     *
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public function scopeParUuid(Builder $requete, string $uuid): Builder
    {
        return Str::isUuid($uuid) ? $requete->where('invitations_cabinet.uuid', $uuid) : $requete->whereRaw('1 = 0');
    }

    /**
     * L'empreinte d'un jeton, telle qu'elle se garde et se cherche.
     */
    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * L'invitation que désigne ce jeton, hors de toute société (la page du
     * lien s'ouvre avant qu'aucune ne le soit), ouverte ou non ; null pour
     * un jeton inconnu. Seulement les colonnes que la page et l'acceptation
     * lisent.
     */
    public static function parSonJeton(string $jeton): ?self
    {
        return self::withoutGlobalScopes()
            ->where('jeton_hache', self::empreinte($jeton))
            ->first(['id', 'societe_id', 'siren', 'email', 'etat', 'expire_le', 'created_at']);
    }

    /**
     * Ouverte et pas encore périmée.
     */
    public function estOuverte(): bool
    {
        return $this->etat === EtatInvitation::Ouverte && $this->expire_le->isFuture();
    }
}
