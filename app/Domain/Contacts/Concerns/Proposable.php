<?php

namespace App\Domain\Contacts\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Une fiche archivée (voir les SoftDeletes du modèle) n'est plus proposée
 * pour un nouveau document, sans disparaître pour autant : les documents
 * qui la portent déjà la gardent, et celle déjà rattachée au document
 * modifié reste affichée et conservée. Une fiche choisie dans un
 * formulaire se valide avec BelongsToMagasin::regleExisteDansLeMagasin(),
 * qui refuse déjà une fiche archivée.
 *
 * À utiliser avec BelongsToMagasin et SoftDeletes.
 */
trait Proposable
{
    /**
     * Les fiches non archivées, plus celle déjà rattachée au document
     * modifié ($dejaRattachee), même archivée depuis -- pourvu que la
     * requête n'exclue pas déjà les fiches archivées (une relation
     * withTrashed(), comme Facture::client()).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProposables(Builder $query, int|string|null $dejaRattachee = null): Builder
    {
        return $query->where(fn (Builder $fiches): Builder => $fiches
            ->whereNull($this->getQualifiedDeletedAtColumn())
            ->when(filled($dejaRattachee), fn (Builder $fiches): Builder => $fiches->orWhere($this->getQualifiedKeyName(), $dejaRattachee)));
    }
}
