<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\TypePiece;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Une pièce déposée pour que l'éditeur traite à la main une inscription
 * dont les courriers recommandés ne sont pas parvenus (extrait du
 * registre, pièce d'identité du représentant légal, voir
 * DeposerUnePieceAction) : son fichier, chiffré sur
 * le disque privé « verification », s'efface dès la décision de
 * l'éditeur, au plus tard trente jours après le dépôt (voir
 * PiecesDeVerification::effacer()). La ligne reste : la trace qu'une pièce
 * de ce type a été examinée, avec l'empreinte du fichier déposé.
 *
 * Seul l'effacement se pose (chemin remis à null, effacee_le posée), une
 * fois ; la ligne ne s'efface pas.
 */
class PieceVerification extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'deposee_par' => 'deposee_par_nom',
        ];
    }

    public const CONSERVATION_EN_JOURS = 30;

    protected $table = 'pieces_verification';

    protected $fillable = [];

    protected $hidden = ['chemin'];

    protected $casts = [
        'type' => TypePiece::class,
        'deposee_le' => 'datetime',
        'effacee_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PieceVerification $piece): void {
            $corrigees = array_diff(array_keys($piece->getDirty()), ['chemin', 'effacee_le', 'updated_at']);

            if ($corrigees !== [] || $piece->getOriginal('effacee_le') !== null || $piece->chemin !== null) {
                throw new LogicException('Une pièce déposée ne se corrige jamais : seul son effacement se pose, une fois.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('La trace d\'une pièce déposée ne s\'efface jamais.');
        });
    }
}
