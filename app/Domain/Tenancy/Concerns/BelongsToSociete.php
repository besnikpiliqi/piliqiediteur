<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
 * Un identifiant choisi dans un formulaire se valide avec
 * regleExisteDansLaSociete(), jamais avec une règle « exists » nue.
 */
trait BelongsToSociete
{
    public static function bootBelongsToSociete(): void
    {
        static::addGlobalScope(new SocieteScope);

        static::creating(function ($model): void {
            $model->societe_id = app(TenantContext::class)->societe()->getKey();
        });
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }

    /**
     * La règle « exists » d'un identifiant de ce modèle choisi dans un
     * formulaire : une ligne de la société active. La validation interroge
     * la table sans passer par SocieteScope : une règle « exists » nue
     * accepterait l'identifiant d'une autre société, et révélerait
     * lesquels existent.
     */
    public static function regleExisteDansLaSociete(): Exists
    {
        $modele = new static;

        return Rule::exists($modele->getTable(), $modele->getKeyName())
            ->where('societe_id', app(TenantContext::class)->societe()->getKey());
    }
}
