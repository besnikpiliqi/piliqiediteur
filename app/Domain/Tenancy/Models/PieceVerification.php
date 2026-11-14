<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
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
 * Seuls se posent l'effacement (chemin remis à null, effacee_le posée),
 * une fois, et, tant que le fichier reste, sa dernière analyse par
 * l'antivirus, qui avance, et son blocage, une fois, avec sa menace (voir
 * PiecesDeVerification::dechiffrerPourLExamen()) ; la ligne ne s'efface
 * pas.
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
        'deposee_le' => Instant::class,
        'effacee_le' => Instant::class,
        'analysee_le' => Instant::class,
        'bloquee_le' => Instant::class,
        'taille' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (PieceVerification $piece): void {
            $modifiees = array_keys($piece->getDirty());
            $effacement = array_diff($modifiees, ['chemin', 'effacee_le', 'updated_at']) === [] && $piece->chemin === null;
            $analyse = array_diff($modifiees, ['analysee_le', 'bloquee_le', 'menace', 'updated_at']) === [] && $piece->getOriginal('bloquee_le') === null;

            if ($piece->getOriginal('effacee_le') !== null || (! $effacement && ! $analyse)) {
                throw new LogicException('Une pièce déposée ne se corrige jamais : seuls son effacement, une fois, et sa dernière analyse par l\'antivirus se posent.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('La trace d\'une pièce déposée ne s\'efface jamais.');
        });
    }
}
