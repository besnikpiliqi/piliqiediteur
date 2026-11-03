<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Une ligne du registre des SIRET réservés (voir SiretsDesMagasins, son
 * seul écrivain) : le SIRET d'un magasin d'une société validée, réservé
 * dans tout le logiciel (index unique partiel sirets_magasins_siret_reserve)
 * jusqu'à sa libération par la procédure de l'éditeur. Une ligne ne se
 * corrige jamais : seules sa fin d'actualité (retire_le, à un changement
 * de SIRET) et sa libération (libere_le, motif_liberation) se posent, une
 * fois ; elle ne s'efface pas (voir DeclencheursDeProtection::sansSuppression()).
 *
 * Sans scope global : la réservation se lit dans tout le logiciel, filtrée
 * par SIRET ou par magasin, et limitée à son existence.
 */
class SiretMagasin extends Model
{
    protected $table = 'sirets_magasins';

    protected $fillable = [];

    protected $casts = [
        'reserve_le' => Instant::class,
        'retire_le' => Instant::class,
        'libere_le' => Instant::class,
    ];

    /**
     * Les colonnes qui passent de null à une valeur, et elles seules.
     */
    private const COLONNES_QUI_SE_POSENT = ['retire_le', 'libere_le', 'motif_liberation'];

    protected static function booted(): void
    {
        static::updating(function (SiretMagasin $ligne): void {
            foreach (array_keys($ligne->getDirty()) as $colonne) {
                if ($colonne === 'updated_at') {
                    continue;
                }

                if (! in_array($colonne, self::COLONNES_QUI_SE_POSENT, true) || $ligne->getOriginal($colonne) !== null) {
                    throw new LogicException('Une ligne du registre des SIRET ne se corrige jamais : seules sa fin d\'actualité et sa libération se posent, une fois.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une ligne du registre des SIRET ne s\'efface jamais.');
        });
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }
}
