<?php

namespace App\Domain\Achats\Casts;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Le PU HT d'une ligne d'achat ou d'avoir fournisseur, et le coût unitaire
 * d'une ligne d'inventaire d'ouverture (décision de l'éditeur du
 * 02/11/2026, T2) : tel que le fournisseur l'écrit, six décimales au plus,
 * jamais arrondi, en colonne decimal(18,6). Il s'écrit depuis une saisie
 * (voir SaisieDecimale::enMillioniemes(), qui refuse une saisie plus
 * précise) et se relit tel qu'il est enregistré, sans zéro inutile au-delà
 * du centime : « 0.1234 », « 12.50 », « 8.333333 », « 2.10 » (voir
 * CalculateurLigne::prixUnitaireExactEnDecimal()).
 *
 * @implements CastsAttributes<string|null, mixed>
 */
final class PrixDAchat implements CastsAttributes
{
    /**
     * @throws InvalidArgumentException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(CalculateurLigne::class)->prixUnitaireExactEnDecimal(SaisieDecimale::enMillioniemes($value));
    }

    /**
     * Écrit comme sa colonne, au millionième : « 0.123400 ».
     *
     * @throws InvalidArgumentException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(CalculateurLigne::class)->enDecimalAuMillionieme(SaisieDecimale::enMillioniemes($value));
    }
}
