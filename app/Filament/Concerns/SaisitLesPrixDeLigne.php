<?php

namespace App\Filament\Concerns;

use App\Domain\Facturation\Services\DocumentChiffre;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

/**
 * Prix et totaux des lignes d'un document à l'écran, partagés par les
 * lignes de vente (voir AjouteDesLignesDeVente) et d'achat (voir
 * AjouteDesLignesDAchat) : le document se calcule comme à
 * l'enregistrement réel (voir documentDesLignesSaisies()), et l'écran ne
 * fait que lire ses montants.
 */
trait SaisitLesPrixDeLigne
{
    /**
     * Les lignes saisies du document, calculées ensemble comme à leur
     * enregistrement ; $get lit le formulaire au niveau du document.
     */
    abstract protected static function documentDesLignesSaisies(Get $get): DocumentChiffre;

    /**
     * Totaux du document en cours de saisie, tels que le calcul les donne
     * (voir DocumentChiffre). Une vente (caisse, facture, devis) les fait
     * précéder d'une rangée par taux dès qu'il y en a plusieurs ($parTaux) :
     * HT, TVA et TTC de chaque taux, sommes exactes de ses lignes ; une
     * facture d'achat n'en montre que le total.
     */
    public static function totauxDuDocument(bool $parTaux = false): Placeholder
    {
        return Placeholder::make('totaux_document')
            ->label('Totaux')
            ->columnSpanFull()
            ->content(function (Get $get) use ($parTaux): HtmlString {
                $document = self::documentDesLignesSaisies($get);
                $rangees = $parTaux && count($document->parTaux) > 1
                    ? array_map(
                        fn (string $taux, array $montants): string => 'À '.str_replace('.', ',', self::pourcentageEnTexte($taux)).' % : '.self::montantEnTexte($montants['montant_ht']).' HT + '.self::montantEnTexte($montants['montant_tva']).' de TVA = '.self::montantEnTexte($montants['montant_ttc']).' TTC',
                        array_keys($document->parTaux),
                        $document->parTaux,
                    )
                    : [];

                $rangees[] = 'Total HT '.self::montantEnTexte($document->totaux['total_ht'])
                    .' · TVA '.self::montantEnTexte($document->totaux['total_tva'])
                    .' · Total TTC '.self::montantEnTexte($document->totaux['total_ttc']);

                return new HtmlString(implode('<br>', array_map(e(...), $rangees)));
            });
    }

    /**
     * Un montant tel qu'on le lit : « 1 234,50 € ».
     */
    private static function montantEnTexte(float|string $montant): string
    {
        return number_format((float) $montant, 2, ',', ' ').' €';
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
