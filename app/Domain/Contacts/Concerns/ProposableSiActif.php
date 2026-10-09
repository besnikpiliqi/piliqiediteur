<?php

namespace App\Domain\Contacts\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Une fiche désactivée (« Actif » décoché) ou archivée n'est plus proposée
 * pour un nouveau document, sans disparaître pour autant : les documents
 * qui la portent déjà la gardent, et celle déjà rattachée au document
 * modifié reste affichée et conservée.
 */
trait ProposableSiActif
{
    /**
     * Les fiches actives et non archivées, plus celle déjà rattachée au
     * document modifié ($dejaRattachee), même désactivée ou archivée depuis
     * -- pourvu que la requête n'exclue pas déjà les fiches archivées.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProposables(Builder $query, int|string|null $dejaRattachee = null): Builder
    {
        return $query->where(fn (Builder $fiches): Builder => $fiches
            ->where(fn (Builder $proposables): Builder => $proposables
                ->where($this->qualifyColumn('actif'), true)
                ->whereNull($this->getQualifiedDeletedAtColumn()))
            ->when(filled($dejaRattachee), fn (Builder $fiches): Builder => $fiches->orWhere($this->getQualifiedKeyName(), $dejaRattachee)));
    }
}
