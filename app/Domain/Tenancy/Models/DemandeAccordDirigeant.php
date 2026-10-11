<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Integrations\Rne\RepresentantRne;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * La demande d'accord qu'un propriétaire qui n'est pas dirigeant adresse à
 * un dirigeant inscrit au registre national des entreprises (voir
 * DemanderAccordDUnDirigeantAction et ApprouverMandatAction) : le lien
 * envoyé au dirigeant porte son jeton, dont seule l'empreinte SHA-256 se
 * garde. Elle vaut sept jours ; approuvée ou refusée, une fois, elle ne
 * change plus, et ne s'efface pas.
 *
 * @property array<string, mixed> $representant_rne
 */
class DemandeAccordDirigeant extends Model
{
    use BelongsToSociete;

    public const DUREE_DE_VALIDITE_EN_JOURS = 7;

    protected $table = 'demandes_accord_dirigeant';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'representant_rne' => 'array',
        'expire_le' => 'datetime',
        'approuvee_le' => 'datetime',
        'refusee_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (DemandeAccordDirigeant $demande): void {
            $corrigees = array_diff(array_keys($demande->getDirty()), ['approuvee_le', 'refusee_le', 'verification_identite_id', 'updated_at']);

            if ($corrigees !== [] || $demande->getOriginal('approuvee_le') !== null || $demande->getOriginal('refusee_le') !== null) {
                throw new LogicException('Une demande d\'accord ne se corrige jamais : seule sa réponse se pose, une fois.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une demande d\'accord ne s\'efface jamais.');
        });
    }

    /**
     * L'empreinte d'un jeton, telle qu'elle se garde et se cherche : le
     * jeton lui-même n'est jamais enregistré.
     */
    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function estEnAttente(): bool
    {
        return $this->approuvee_le === null && $this->refusee_le === null && $this->expire_le->isFuture();
    }

    /**
     * Le dirigeant visé, tel que le registre le donnait à la demande.
     */
    public function representant(): RepresentantRne
    {
        return RepresentantRne::depuisTableau($this->representant_rne['representant']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }
}
