<?php

namespace App\Filament\Concerns;

use App\Domain\Facturation\Services\CalculateurLigne;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use InvalidArgumentException;

/**
 * Prix et totaux des lignes d'un document à l'écran, partagés par les
 * lignes de vente (voir AjouteDesLignesDeVente) et d'achat (voir
 * AjouteDesLignesDAchat) : chacune calcule ses montants comme à
 * l'enregistrement réel (voir montantsDesLignesSaisies()).
 */
trait SaisitLesPrixDeLigne
{
    /**
     * Les montants des lignes saisies du document, calculés comme à leur
     * enregistrement ; $get lit le formulaire au niveau du document.
     *
     * @return iterable<array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}>
     */
    abstract protected static function montantsDesLignesSaisies(Get $get): iterable;

    /**
     * Totaux du document en cours de saisie, sommés séparément comme à
     * l'enregistrement (voir CalculateurLigne::totaliser()).
     */
    public static function totauxDuDocument(): Placeholder
    {
        return Placeholder::make('totaux_document')
            ->label('Totaux')
            ->columnSpanFull()
            ->content(function (Get $get): string {
                try {
                    $totaux = app(CalculateurLigne::class)->totaliser(self::montantsDesLignesSaisies($get));
                } catch (InvalidArgumentException) {
                    return '—';
                }

                return 'Total HT '.self::montantEnTexte($totaux['total_ht'])
                    .' · TVA '.self::montantEnTexte($totaux['total_tva'])
                    .' · Total TTC '.self::montantEnTexte($totaux['total_ttc']);
            });
    }

    private static function montantEnTexte(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    /**
     * La ligne telle qu'elle est saisie, lue depuis l'un de ses champs.
     *
     * @return array<string, mixed>
     */
    private static function ligneSaisie(Get $get): array
    {
        return [
            'produit_id' => $get('produit_id'),
            'service_id' => $get('service_id'),
            'prix_unitaire_ht' => $get('prix_unitaire_ht'),
            'prix_unitaire_ttc' => $get('prix_unitaire_ttc'),
            'quantite' => $get('quantite'),
            'taux_tva' => $get('taux_tva'),
            'remise_pourcentage' => $get('remise_pourcentage'),
        ];
    }

    /**
     * Même présentation qu'un prix relu en base (cast decimal:2) : "24.00",
     * pas "24".
     */
    protected static function prixEnTexte(float $prix): string
    {
        return number_format($prix, 2, '.', '');
    }

    /**
     * Un taux de TVA ou une remise tel qu'on l'écrit dans le champ : "20",
     * "5.5", "0" -- jamais "20.00" comme le relit le cast decimal:2.
     */
    public static function pourcentageEnTexte(float|string|null $pourcentage): string
    {
        return (string) (float) $pourcentage;
    }
}
