<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La réponse du client à un devis par son lien secret (voir
 * RepondreAuDevisEnLigneAction), une seule par lien : l'acceptation, avec
 * le nom qu'il a tapé en cochant « Bon pour accord » (signature
 * électronique simple), ou le refus, avec son motif facultatif ; l'instant
 * (UTC, affiché à l'heure du magasin) et l'empreinte de la version du
 * devis à laquelle il a répondu (voir Devis::empreinteDeSaVersion()). En
 * ajout seul : la base refuse de la modifier ou de l'effacer. L'adresse
 * IP et le navigateur du client sont ailleurs, dans les traces techniques
 * du journal d'audit et du journal des événements, effacées après un an.
 *
 * @property int $devis_id
 * @property int $lien_du_devis_id
 * @property ReponseAuDevis $reponse
 * @property string|null $signataire
 * @property string|null $motif
 * @property string $empreinte_du_devis
 * @property CarbonImmutable $created_at
 */
class ReponseDuDevis extends Model
{
    use BelongsToMagasin;

    /**
     * L'instant de la réponse s'écrit par son action, avec son fuseau
     * (voir Instant) : jamais par l'horodatage automatique, qui l'écrirait
     * en chaîne.
     */
    public $timestamps = false;

    protected $table = 'reponses_des_devis';

    protected $fillable = [];

    protected $casts = [
        'reponse' => ReponseAuDevis::class,
        'created_at' => Instant::class,
    ];

    /**
     * @return BelongsTo<Devis, $this>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * @return BelongsTo<LienDuDevis, $this>
     */
    public function lien(): BelongsTo
    {
        return $this->belongsTo(LienDuDevis::class, 'lien_du_devis_id');
    }
}
