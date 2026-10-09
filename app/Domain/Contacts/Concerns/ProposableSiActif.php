<?php

namespace App\Domain\Contacts\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Exists;

/**
 * Une fiche désactivée (« Actif » décoché) ou archivée n'est plus proposée
 * pour un nouveau document, sans disparaître pour autant : les documents
 * qui la portent déjà la gardent, et celle déjà rattachée au document
 * modifié reste affichée et conservée.
 *
 * À utiliser avec BelongsToMagasin et SoftDeletes.
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

    /**
     * La règle « exists » d'une fiche choisie pour un nouveau document :
     * une fiche active du magasin, non archivée (voir
     * BelongsToMagasin::regleExisteDansLeMagasin()). Elle refuse une fiche
     * désactivée qu'une requête forgée enverrait malgré les options.
     */
    public static function regleProposableDansLeMagasin(): Exists
    {
        return static::regleExisteDansLeMagasin()->where('actif', true);
    }
}
