<?php

namespace App\Domain\Charges\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Support\CleDeComparaison;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un type de charge qu'une société ajoute à la liste de PILIQI (décision
 * de l'éditeur du 14/11/2026, lot CHARGES ; voir TypesDeCharge) : un nom
 * libre, unique dans la société sans tenir compte de la casse ni des
 * accents (voir cleDuNom()), et la nature de ce qu'il paie. Un type
 * utilisé ne s'efface pas : il s'archive, n'est plus proposé, reste
 * lisible sur ses charges, et se restaure.
 *
 * @property string $uuid
 * @property string $nom
 * @property NatureDeCharge $nature
 */
class TypeDeChargeDeLaSociete extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    protected $table = 'types_de_charge_de_la_societe';

    protected $fillable = [];

    protected $casts = [
        'nature' => NatureDeCharge::class,
        'archive_le' => Instant::class,
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TypeDeChargeDeLaSociete $type): void {
            $type->uuid ??= (string) Str::uuid();
        });

        static::saving(function (TypeDeChargeDeLaSociete $type): void {
            $type->nom_cle = self::cleDuNom((string) $type->nom);
        });
    }

    /**
     * Le nom comparé (voir CleDeComparaison) : « Ménage  bureau » et
     * « menage bureau » sont le même type.
     */
    public static function cleDuNom(string $nom): string
    {
        return CleDeComparaison::de($nom);
    }

    public function estArchive(): bool
    {
        return $this->archive_le !== null;
    }
}
