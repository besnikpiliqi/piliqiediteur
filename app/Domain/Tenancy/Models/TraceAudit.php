<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La trace technique d'une entrée du journal d'audit inscrite pendant une
 * requête HTTP : l'adresse IP et le navigateur du visiteur, rangés à part,
 * jamais dans l'empreinte de l'entrée (S13). Elle s'écrit dans la
 * transaction de l'entrée (voir noterPour()), ne se modifie jamais et
 * s'efface à expire_le, après la durée de config/conservation.php (voir
 * app:purger-traces) ; la base refuse tout le reste.
 *
 * @property int $entree_audit_id
 * @property string|null $ip
 * @property string|null $agent_utilisateur
 * @property CarbonImmutable $expire_le
 */
class TraceAudit extends Model
{
    use BelongsToSociete;

    protected $table = 'traces_audit';

    protected $primaryKey = 'entree_audit_id';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'expire_le' => Instant::class,
    ];

    /**
     * Note la trace de l'entrée que vient d'inscrire le journal d'audit,
     * si le visiteur d'une requête HTTP l'a fait inscrire dans la société
     * où il agit (voir TraceDuVisiteur).
     */
    public static function noterPour(EntreeAudit $entree): void
    {
        $colonnes = app(TraceDuVisiteur::class)->colonnes();

        if ($colonnes !== null) {
            (new self)->forceFill(['entree_audit_id' => $entree->getKey(), ...$colonnes])->save();
        }
    }
}
