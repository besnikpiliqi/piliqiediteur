<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * À appliquer sur tout modèle de données propre à une société, qui
 * n'appartient à aucun magasin en particulier : le journal d'audit, les
 * vérifications de la société...
 *
 * Deux garanties, comme BelongsToMagasin :
 *  1. Lecture : SocieteScope filtre toute requête par la société active.
 *  2. Écriture : societe_id est TOUJOURS celui de la société active à la
 *     création, quelle que soit la valeur reçue : il ne figure jamais dans
 *     $fillable, et un identifiant forcé dans une requête ne rattache
 *     jamais rien à une autre société.
 *
 * Sa société n'est jamais nulle : societe_id ne l'est jamais dans les
 * tables de ces modèles, sa clé étrangère vise une société, qu'aucune
 * société ne s'efface (voir DeclencheursDeProtection::sansSuppression())
 * et que Societe n'a aucun scope global.
 *
 * @property-read Societe $societe
 */
trait BelongsToSociete
{
    public static function bootBelongsToSociete(): void
    {
        static::addGlobalScope(new SocieteScope);

        static::creating(function (Model $model): void {
            $model->setAttribute('societe_id', CleDuModele::de(app(TenantContext::class)->societe()));
        });
    }

    /**
     * La société d'une donnée se lit toujours en entier, même avant son
     * enregistrement : les droits la comparent strictement à celle du
     * compte (voir EntreeAuditPolicy).
     */
    public function initializeBelongsToSociete(): void
    {
        $this->mergeCasts(['societe_id' => 'integer']);
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }
}
