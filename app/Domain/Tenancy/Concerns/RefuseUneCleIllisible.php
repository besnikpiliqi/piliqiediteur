<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Un modèle que l'adresse d'une page désigne par sa clé : le magasin ou la
 * société d'un panneau (/app/{uuid}, /societe/{uuid}), et la fiche d'une
 * ressource de chaque panneau (/…/factures/{id}, /societe/{uuid}/membres/{id},
 * voir Resource::resolveRecordRouteBinding()). Une valeur que la colonne de
 * la clé ne peut pas lire -- un texte qui n'est pas un uuid pour la colonne
 * uuid, ou, pour l'identifiant, autre chose qu'un entier positif d'au plus
 * dix-huit chiffres, que la colonne bigint lit toujours -- ne désigne
 * aucune ligne (404), sans interroger la base, qui la refuserait par une
 * erreur (500).
 *
 * @mixin Model
 */
trait RefuseUneCleIllisible
{
    /**
     * @param  Model|BuilderContract  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return BuilderContract
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $lisible = match ($field ?? $this->getRouteKeyName()) {
            'uuid' => is_string($value) && Str::isUuid($value),
            $this->getKeyName() => is_int($value) ? $value > 0 : is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1,
            default => true,
        };

        if (! $lisible) {
            throw (new ModelNotFoundException)->setModel(static::class, [ValeurSimple::enTexte($value)]);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }
}
