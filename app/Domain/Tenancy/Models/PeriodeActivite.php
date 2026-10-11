<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\MotifFermeture;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Une période d'activité d'un magasin (voir PeriodesDActivite, son seul
 * écrivain) : de sa validation, de sa création dans une société validée ou
 * de sa réouverture, à sa fermeture. Les tickets Z ne se produisent que
 * pour ses jours (voir CloturerLesJourneesAction). Une période ne se
 * corrige jamais : seule sa fin (fin, motif_fin, fermee_par) se pose, une
 * fois ; elle ne s'efface pas.
 *
 * Sans scope global : toute requête filtre explicitement par magasin.
 */
class PeriodeActivite extends Model
{
    protected $table = 'periodes_activite';

    protected $fillable = [];

    protected $casts = [
        'debut' => 'datetime',
        'fin' => 'datetime',
        'motif_fin' => MotifFermeture::class,
    ];

    /**
     * Les colonnes qui passent de null à une valeur, et elles seules.
     */
    private const COLONNES_QUI_SE_POSENT = ['fin', 'motif_fin', 'fermee_par'];

    protected static function booted(): void
    {
        static::updating(function (PeriodeActivite $periode): void {
            foreach (array_keys($periode->getDirty()) as $colonne) {
                if ($colonne === 'updated_at') {
                    continue;
                }

                if (! in_array($colonne, self::COLONNES_QUI_SE_POSENT, true) || $periode->getOriginal($colonne) !== null) {
                    throw new LogicException('Une période d\'activité ne se corrige jamais : seule sa fin se pose, une fois.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une période d\'activité ne s\'efface jamais.');
        });
    }
}
