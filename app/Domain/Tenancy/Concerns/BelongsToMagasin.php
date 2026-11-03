<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * À appliquer sur tout modèle appartenant à un magasin (Produit, Client,
 * Fournisseur, Facture, ...).
 *
 * Deux garanties, jamais une seule :
 *  1. Lecture : MagasinScope filtre automatiquement toute requête.
 *  2. Écriture : magasin_id est TOUJOURS écrasé par le TenantContext actif
 *     à la création, quelle que soit la valeur envoyée par le payload. On
 *     ne fait jamais confiance à un magasin_id fourni par le client : sans
 *     ça, un payload malveillant pourrait rattacher un enregistrement à un
 *     autre magasin via mass assignment.
 *
 * magasin_id ne doit JAMAIS figurer dans $fillable des modèles qui
 * utilisent ce trait.
 *
 * Un identifiant choisi dans un formulaire se valide avec
 * regleExisteDansLeMagasin(), jamais avec une règle « exists » nue.
 */
trait BelongsToMagasin
{
    public static function bootBelongsToMagasin(): void
    {
        static::addGlobalScope(new MagasinScope);

        static::creating(function ($model) {
            $model->magasin_id = app(TenantContext::class)->id();
        });
    }

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    /**
     * La règle « exists » d'un identifiant de ce modèle choisi dans un
     * formulaire : une fiche du magasin actif, et pas une fiche archivée --
     * ou, avec $parmi, l'une des fiches de cette requête (archivées
     * comprises si elle les garde, voir Client::scopeQuiPeuventRegler()).
     * La validation interroge la table sans passer par MagasinScope : une
     * règle « exists » nue accepterait l'identifiant d'une fiche d'un autre
     * magasin, et révélerait lesquels existent. Elle reste donc limitée au
     * magasin actif, même quand $parmi l'est déjà par MagasinScope.
     *
     * @param  Builder<static>|null  $parmi
     */
    public static function regleExisteDansLeMagasin(?Builder $parmi = null): Exists
    {
        $modele = new static;
        $regle = Rule::exists($modele->getTable(), $modele->getKeyName())
            ->where('magasin_id', app(TenantContext::class)->id());

        if ($parmi !== null) {
            return $regle->where(fn (QueryBuilder $fiches): QueryBuilder => $fiches->whereIn($modele->getKeyName(), $parmi->select($modele->getQualifiedKeyName())));
        }

        return in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            ? $regle->whereNull($modele->getDeletedAtColumn())
            : $regle;
    }
}
