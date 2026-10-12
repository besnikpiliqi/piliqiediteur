<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * La proposition qu'une société adresse au compte d'une personne qu'une
 * société a retirée, la même ou une autre (voir
 * ProposerUnRattachementAction) : le lien envoyé à l'adresse du compte
 * porte son jeton, dont seule l'empreinte SHA-256 se garde. Elle vaut sept
 * jours et ne sert qu'une fois (voir AccepterUnRattachementAction) ; le
 * rôle de société et les affectations qu'elle propose, déjà contrôlés sur
 * les droits de son auteur, le seront de nouveau à l'acceptation. Une
 * demande ne se corrige jamais : seule sa réponse, ou son annulation, se
 * pose, une fois ; elle ne s'efface pas.
 *
 * @property list<array{magasin_id: int, role: string, autorisations: list<string>}> $affectations
 */
class DemandeRattachement extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    public const DUREE_DE_VALIDITE_EN_JOURS = 7;

    protected $table = 'demandes_rattachement';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'role_societe' => RoleSociete::class,
        'affectations' => 'array',
        'expire_le' => 'datetime',
        'acceptee_le' => 'datetime',
        'refusee_le' => 'datetime',
        'annulee_le' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'demande_par' => 'demande_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DemandeRattachement $demande): void {
            $corrigees = array_diff(array_keys($demande->getDirty()), ['acceptee_le', 'refusee_le', 'annulee_le', 'updated_at']);

            if ($corrigees !== [] || $demande->getOriginal('acceptee_le') !== null || $demande->getOriginal('refusee_le') !== null || $demande->getOriginal('annulee_le') !== null) {
                throw new LogicException('Une demande de rattachement ne se corrige jamais : seule sa réponse, ou son annulation, se pose, une fois.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une demande de rattachement ne s\'efface jamais.');
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
        return $this->acceptee_le === null && $this->refusee_le === null && $this->annulee_le === null && $this->expire_le->isFuture();
    }

    /**
     * Les affectations proposées, par identifiant de magasin, au format des
     * actions de l'équipe (voir AffectationsDesMembres::lire()).
     *
     * @return array<int, array{role: RoleMagasin, autorisations: list<string>}>
     */
    public function affectationsProposees(): array
    {
        $proposees = [];

        foreach ($this->affectations as $affectation) {
            $proposees[(int) $affectation['magasin_id']] = ['role' => RoleMagasin::from($affectation['role']), 'autorisations' => $affectation['autorisations']];
        }

        return $proposees;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function compte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }
}
