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
     * Le mode en abrégé, usuel en France, que montre la colonne « Encaissé »
     * des relevés de caisse (demande de l'éditeur du 15/11/2026 ; choix des
     * abréviations : décision de Claude, D76, que l'éditeur peut changer) :
     * CB, ESP, CHQ, VIR, PRLV. Le nom entier (getLabel()) reste partout
     * ailleurs, et en infobulle de l'abréviation.
     */
    public function abreviation(): string
    {
        return match ($this) {
            self::CarteBancaire => 'CB',
            self::Especes => 'ESP',
            self::Cheque => 'CHQ',
            self::Virement => 'VIR',
            self::Prelevement => 'PRLV',
        };
    }

    /**
     * L'ordre fixe des modes dans la colonne « Encaissé » d'un relevé de
     * caisse : espèces, carte bancaire, chèque, virement, prélèvement.
     *
     * @return list<self>
     */
    public static function dansLOrdreDesReleves(): array
    {
        return [self::Especes, self::CarteBancaire, self::Cheque, self::Virement, self::Prelevement];
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
