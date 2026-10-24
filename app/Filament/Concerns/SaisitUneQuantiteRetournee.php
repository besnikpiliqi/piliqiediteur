<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

/**
 * Quantité d'un retour (avoir client ou avoir fournisseur) : un nombre
 * entier d'articles, au plus ce qui reste à retourner sur la ligne -- la
 * même règle que l'action du domaine (voir CreerAvoirAction et
 * CreerAvoirFournisseurAction).
 */
trait SaisitUneQuantiteRetournee
{
    /**
     * @param  Closure(Get): ?int  $quantiteRestante  null pour une ligne que l'utilisateur ne voit pas : le champ n'en révèle aucun plafond, et l'autorisation ou l'action refuse la demande
     */
    protected static function champQuantiteRetournee(string $libelle, Closure $quantiteRestante): TextInput
    {
        return TextInput::make('quantite')
            ->label($libelle)
            ->integer()
            ->minValue(1)
            ->maxValue(fn (Get $get): ?int => $quantiteRestante($get))
            ->required();
    }
}
