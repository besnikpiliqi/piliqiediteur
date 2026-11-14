<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Les modes d'encaissement (Q14) : chaque part d'un règlement est un
 * paiement dans l'un d'eux. Le crédit d'avoir n'en est pas un : il
 * s'affiche comme le mode « Avoir » mais s'enregistre comme une
 * imputation, jamais comme un paiement (voir ReglementComptant).
 */
enum ModePaiement: string implements HasLabel
{
    case Virement = 'virement';
    case Cheque = 'cheque';
    case Especes = 'especes';
    case CarteBancaire = 'carte_bancaire';
    case Prelevement = 'prelevement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Virement => 'Virement',
            self::Cheque => 'Chèque',
            self::Especes => 'Espèces',
            self::CarteBancaire => 'Carte bancaire',
            self::Prelevement => 'Prélèvement',
        };
    }

    /**
     * Le mode tel qu'une phrase le dit après un montant : « 40,00 € en
     * espèces », « 30,00 € par carte bancaire ».
     */
    public function dansUnePhrase(): string
    {
        return match ($this) {
            self::Especes => 'en espèces',
            default => 'par '.mb_strtolower($this->getLabel()),
        };
    }

    /**
     * Les modes qui règlent une vente à la caisse : tous, sauf le
     * prélèvement, qui suppose un mandat du client et n'est jamais reçu au
     * comptoir.
     *
     * @return list<self>
     */
    public static function aLaCaisse(): array
    {
        return [self::Especes, self::CarteBancaire, self::Cheque, self::Virement];
    }
}
