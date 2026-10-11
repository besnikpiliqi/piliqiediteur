<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Une entrée du journal d'audit d'une société (voir JournalAudit, son seul
 * écrivain) : qui a fait quoi, sur quoi, avec les anciennes et les
 * nouvelles valeurs que l'action retient (voir
 * ActionAuditee::champsAudites()), chaînée à la précédente de sa chaîne
 * par son empreinte. Elle ne se modifie jamais, et ne s'efface qu'une fois
 * sa durée de conservation passée, par le début de sa chaîne (voir
 * app:purger-journal-audit) ; la base refuse tout le reste.
 *
 * @property array<string, mixed>|null $anciennes_valeurs
 * @property array<string, mixed>|null $nouvelles_valeurs
 */
class EntreeAudit extends Model
{
    use BelongsToSociete;

    protected $table = 'journal_audit';

    /**
     * Son instant s'écrit par JournalAudit, en UTC à la seconde (voir
     * HorodatageUtc), et entre dans son empreinte : jamais par Eloquent.
     */
    public $timestamps = false;

    protected $fillable = [];

    protected $casts = [
        'categorie' => CategorieAudit::class,
        'action' => ActionAuditee::class,
        'sequence' => 'integer',
        'anciennes_valeurs' => 'array',
        'nouvelles_valeurs' => 'array',
        'operateur' => 'array',
        'expire_le' => HorodatageUtc::class,
        'created_at' => HorodatageUtc::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Une entrée du journal d\'audit ne se modifie jamais.');
        });
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    /**
     * @return HasOne<TraceAudit, $this>
     */
    public function trace(): HasOne
    {
        return $this->hasOne(TraceAudit::class, 'entree_audit_id');
    }
}
