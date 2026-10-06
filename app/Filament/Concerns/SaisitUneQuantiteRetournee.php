<?php

namespace App\Filament\Concerns;

use App\Domain\Facturation\Services\QuantiteEntiere;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use InvalidArgumentException;

/**
 * Quantité d'un retour (avoir client ou avoir fournisseur) : un nombre
 * entier d'articles -- ou le reliquat exact d'une ancienne ligne à virgule,
 * qui solde alors la ligne (voir QuantiteEntiere::depuisSaisieOuReliquat()).
 * Même règle que l'action du domaine, pour que le formulaire accepte
 * exactement ce qu'elle accepte : sans quoi le dernier demi-article d'une
 * ancienne ligne de 1,5 ne pourrait jamais être crédité.
 */
trait SaisitUneQuantiteRetournee
{
    /**
     * @param  Closure(Get): (int|float)  $quantiteRestante
     */
    protected static function champQuantiteRetournee(string $libelle, Closure $quantiteRestante): TextInput
    {
        return TextInput::make('quantite')
            ->label($libelle)
            ->numeric()
            // Le reliquat d'une ancienne ligne peut être 0,5 : pas de pas
            // de 1 imposé par le navigateur, la règle ci-dessous suffit.
            ->step('any')
            ->required()
            ->maxValue(fn (Get $get): int|float => $quantiteRestante($get))
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $libelle, $quantiteRestante): void {
                try {
                    QuantiteEntiere::depuisSaisieOuReliquat($value, $quantiteRestante($get), 'La '.mb_strtolower($libelle));
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            });
    }
}
