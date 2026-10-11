<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeVerification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Une ligne du registre des vérifications d'une société : chaque contrôle
 * d'un fournisseur (le répertoire Sirene de l'INSEE...), concluant,
 * négatif ou impossible, avec les seuls détails que retient son type (voir
 * TypeVerification::detailsRetenus()). Une ligne ne se modifie jamais et
 * ne s'efface qu'une fois sa durée de conservation fixée et passée ; la
 * base refuse tout le reste (voir
 * DeclencheursDeProtection::effacementApresExpiration()).
 */
class VerificationSociete extends Model
{
    use BelongsToSociete;

    protected $table = 'verifications_societe';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'type' => TypeVerification::class,
        'resultat' => ResultatVerification::class,
        'details' => 'array',
        'operateur' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (VerificationSociete $verification): void {
            $inconnus = array_diff(array_keys($verification->details ?? []), $verification->type->detailsRetenus());

            if ($inconnus !== []) {
                throw new LogicException('Une vérification ne retient que les détails de la liste de son type : '.implode(', ', $inconnus).'.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('Une vérification ne se modifie jamais.');
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
