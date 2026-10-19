<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une demande de transmission de la propriété d'une société à un
 * administrateur (voir DemanderLaTransmissionDeLaProprieteAction) : le lien
 * envoyé à l'adresse que l'administrateur a renseignée lui-même en porte le
 * jeton, dont seule l'empreinte SHA-256 est gardée ; il vaut 24 heures et
 * ne sert qu'une fois. Valable tant qu'elle n'est ni expirée, ni annulée,
 * ni acceptée.
 *
 * Seule lecture hors de la société active : la page du lien, qui lit la
 * demande que désigne l'empreinte de son jeton, et rien d'autre (voir
 * demandeDuLien()).
 *
 * @property int $demandee_par
 * @property int $destinataire_id
 * @property string $adresse
 * @property string $jeton_hache
 */
class TransmissionPropriete extends Model
{
    use BelongsToSociete;

    public const DUREE_DE_VALIDITE_EN_HEURES = 24;

    protected $table = 'transmissions_propriete';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'expire_le' => 'datetime',
        'annulee_le' => 'datetime',
        'acceptee_le' => 'datetime',
    ];

    /**
     * L'empreinte d'un jeton, telle qu'elle se garde et se cherche : le
     * jeton lui-même n'est jamais enregistré.
     */
    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * La demande que désigne ce jeton, hors de toute société (la page du
     * lien s'ouvre avant qu'aucune ne le soit), valable ou non ; null pour
     * un jeton inconnu.
     */
    public static function demandeDuLien(string $jeton): ?self
    {
        return self::withoutGlobalScopes()->where('jeton_hache', self::empreinte($jeton))->first();
    }

    public function estValable(): bool
    {
        return $this->annulee_le === null && $this->acceptee_le === null && $this->expire_le->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandee_par');
    }
}
