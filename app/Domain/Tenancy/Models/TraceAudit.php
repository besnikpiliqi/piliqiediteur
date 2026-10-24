<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
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
        'expire_le' => HorodatageUtc::class,
    ];

    /**
     * Note la trace de l'entrée que vient d'inscrire le journal d'audit,
     * si une requête HTTP routée l'a fait inscrire : une commande ou un job
     * n'a qu'une requête de façade, sans visiteur, et ne laisse aucune
     * trace.
     */
    public static function noterPour(EntreeAudit $entree): void
    {
        $requete = request();

        if ($requete->route() === null) {
            return;
        }

        $navigateur = $requete->userAgent();

        (new self)->forceFill([
            'entree_audit_id' => $entree->getKey(),
            'ip' => $requete->ip(),
            'agent_utilisateur' => $navigateur === null ? null : mb_substr($navigateur, 0, 255),
            'expire_le' => CarbonImmutable::now()->addMonthsNoOverflow(config('conservation.traces_en_mois')),
        ])->save();
    }
}
