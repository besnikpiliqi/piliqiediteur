<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Illuminate\Support\Number;

/**
 * Le tableau des lignes d'un PDF de vente (points 3 et 11), chacune avec
 * la référence de son produit, entière sur une seule ligne, et sa
 * désignation, qui passe à la ligne si la place manque :
 *
 * - une facture, un avoir sur facture ou un devis à un professionnel
 *   montrent la quantité, le PU HT, la remise s'il y en a, le taux et le
 *   total HT : les mentions qu'une facture doit porter pour chaque ligne ;
 * - un ticket, un avoir sur ticket ou un devis à un particulier, qui paie
 *   le prix affiché TTC, montrent le PU TTC et le total TTC ;
 * - une vente sans TVA française, qui se paie hors taxe (voir
 *   ContexteDeVente::documentRetireLaTvaDuPrix()), montre toujours le PU
 *   HT et le total HT, même sur un ticket : le PU TTC de ses lignes est
 *   celui du catalogue, TVA française comprise, que le client ne paie pas.
 *
 * La TVA et le TTC de chaque taux sont dans le tableau qui suit (voir
 * documents/pdf/totaux). La remise n'apparaît que si une ligne en a une.
 * Le PU HT, enregistré au millionième d'euro (voir
 * CalculateurLigne::prixUnitaireHtExact()), s'imprime arrondi au centime,
 * comme le PU TTC (voir CalculateurLigne::prixUnitaireAffiche()) : 54,17 €
 * pour 54,166667 €. La quantité x le prix imprimé peut donc s'écarter du
 * total de la ligne, calculé sans arrondi intermédiaire, ce qu'explique la
 * note de calcul imprimée sous les lignes (voir MentionsFiscales::calcul()).
 * Un avoir indique la facture de chaque ligne, son numéro entier sur une
 * seule ligne. Rien ne se recalcule : chaque montant est celui de la ligne
 * enregistrée.
 *
 * Le tableau tient toujours dans la largeur utile de la page (voir
 * MiseEnPageA4::LARGEUR_UTILE), pour qu'aucune colonne obligatoire ne
 * sorte de la page. Ses textes sont mesurés avec la police du PDF :
 * chaque colonne qui ne passe jamais à la ligne (le n° de la facture
 * d'origine, la quantité, les prix, la remise, le taux et le total) a la
 * largeur de son plus long texte ; la désignation garde au moins
 * LARGEUR_MINIMALE_DE_LA_DESIGNATION ; la référence a la largeur de la plus
 * longue quand elle tient dans ce qui reste. Sinon, chaque référence trop
 * longue pour sa colonne s'imprime, entière sur une ligne, dans un corps
 * réduit juste assez pour y tenir ; les autres gardent le corps du
 * tableau.
 */
final readonly class TableauDesLignes
{
    /**
     * Le corps du texte des lignes, en points (les 10 px du corps de la
     * page, voir documents/pdf/styles).
     */
    public const CORPS = 7.5;

    /**
     * Le corps des titres des colonnes, en points, en capitales grasses.
     */
    public const CORPS_DES_TITRES = 6.0;

    /**
     * La marge intérieure de chaque case, à gauche comme à droite, en
     * points.
     */
    public const MARGE_DE_CASE = 4.5;

    /**
     * La place que la désignation garde au moins, en points : une vingtaine
     * de caractères par ligne, avant que la référence ne soit réduite.
     */
    private const LARGEUR_MINIMALE_DE_LA_DESIGNATION = 100.0;

    /**
     * Ce que chaque colonne mesurée garde en plus de son plus long texte,
     * en points, contre l'arrondi des positions.
     */
    private const JEU = 1.0;

    /**
     * @param  list<array{titre: string, montant: bool, largeur: float}>  $colonnes  dans l'ordre, chacune avec sa largeur en points, marges comprises (leur somme est la largeur utile de la page)
     * @param  list<array{reference: string, designation: string, factureDOrigine: string|null, montants: list<string>, corpsDeLaReference: float|null}>  $lignes  le texte de chaque case (montants : la quantité, le prix, la remise s'il y en a, le taux et le total) et, pour une référence réduite, son corps en points, arrondi au dixième inférieur comme dompdf l'écrit
     */
    public function __construct(
        public array $colonnes,
        public array $lignes,
    ) {}

    /**
     * Le tableau du document, mesuré avec les polices de dompdf.
     */
    public static function pour(Facture|Avoir|Devis $document): self
    {
        $calculateur = app(CalculateurLigne::class);
        $enTtc = ! $document->estPourUnProfessionnel() && ! ContexteDeVente::documentRetireLaTvaDuPrix($document);
        $avecFactureDOrigine = $document instanceof Avoir;
        $avecRemise = $document->lignes->contains(fn (LigneFacture|LigneAvoir|LigneDevis $ligne): bool => $calculateur->enCentiemes($ligne->remise_pourcentage) !== 0);
        $pourcentage = fn (mixed $valeur): string => Number::format((float) $valeur, maxPrecision: 2, locale: 'fr').' %';

        $titres = [
            ['titre' => 'Référence', 'montant' => false],
            ['titre' => 'Désignation', 'montant' => false],
            ...($avecFactureDOrigine ? [['titre' => 'Facture d\'origine', 'montant' => false]] : []),
            ['titre' => 'Qté', 'montant' => true],
            ['titre' => $enTtc ? 'PU TTC' : 'PU HT', 'montant' => true],
            ...($avecRemise ? [['titre' => 'Remise', 'montant' => true]] : []),
            ['titre' => 'Taux', 'montant' => true],
            ['titre' => $enTtc ? 'Total TTC' : 'Total HT', 'montant' => true],
        ];

        $lignes = $document->lignes->map(fn (LigneFacture|LigneAvoir|LigneDevis $ligne): array => [
            'reference' => $ligne->reference ?? '',
            'designation' => $ligne->description,
            'factureDOrigine' => $ligne instanceof LigneAvoir ? ($ligne->ligneFacture?->facture?->numero ?? '—') : null,
            'montants' => array_values(array_filter([
                Number::format($ligne->quantite, locale: 'fr'),
                $calculateur->prixUnitaireAffiche($calculateur->enMillioniemes($enTtc ? $ligne->prix_unitaire_ttc : $ligne->prix_unitaire_ht)),
                $avecRemise ? $pourcentage($ligne->remise_pourcentage) : null,
                $pourcentage($ligne->taux_tva),
                $calculateur->enEuros($calculateur->enCentiemes($enTtc ? $ligne->montant_ttc : $ligne->montant_ht)),
            ], fn (?string $texte): bool => $texte !== null)),
        ])->values()->all();

        return self::mesure($titres, $lignes, app(Dompdf::class)->getFontMetrics());
    }

    /**
     * La largeur de chaque colonne en pourcentage de la largeur utile, que
     * la vue écrit (dompdf ne lit qu'une largeur en pourcentage dans un
     * tableau à disposition fixe).
     *
     * @return list<float>
     */
    public function pourcentages(): array
    {
        return array_map(fn (array $colonne): float => round($colonne['largeur'] / MiseEnPageA4::LARGEUR_UTILE * 100, 4), $this->colonnes);
    }

    /**
     * Les largeurs des colonnes et le corps de chaque référence : chaque
     * colonne qui ne passe jamais à la ligne prend la largeur de son plus
     * long texte, titre compris ; la référence prend la sienne tant que la
     * désignation garde sa place minimale (ou, faute de place, la moitié
     * de ce qui reste), la désignation tout le reste.
     *
     * @param  list<array{titre: string, montant: bool}>  $titres
     * @param  list<array{reference: string, designation: string, factureDOrigine: string|null, montants: list<string>}>  $lignes
     */
    private static function mesure(array $titres, array $lignes, FontMetrics $polices): self
    {
        $normale = $polices->getFont('DejaVu Sans');
        $grasse = $polices->getFont('DejaVu Sans', 'bold');
        $largeurDuTexte = fn (string $texte): float => $polices->getTextWidth($texte, $normale, self::CORPS);
        $largeurDeColonne = fn (int $rang, array $textes): float => max([
            $polices->getTextWidth(mb_convert_case($titres[$rang]['titre'], MB_CASE_UPPER, 'UTF-8'), $grasse, self::CORPS_DES_TITRES),
            ...array_map($largeurDuTexte, $textes),
        ]) + 2 * self::MARGE_DE_CASE + self::JEU;

        $largeurs = [];
        foreach (array_slice(array_keys($titres), 2) as $rang) {
            $largeurs[$rang] = $largeurDeColonne($rang, array_map(fn (array $ligne): string => self::casesQuiNePassentPasALaLigne($ligne)[$rang - 2], $lignes));
        }

        $espace = MiseEnPageA4::LARGEUR_UTILE - array_sum($largeurs);
        $largeurs[0] = min(
            $largeurDeColonne(0, array_column($lignes, 'reference')),
            max($espace - self::LARGEUR_MINIMALE_DE_LA_DESIGNATION, $espace / 2),
        );
        $largeurs[1] = $espace - $largeurs[0];
        ksort($largeurs);
        $placeDUneReference = $largeurs[0] - 2 * self::MARGE_DE_CASE - self::JEU;

        return new self(
            array_map(fn (array $titre, float $largeur): array => [...$titre, 'largeur' => $largeur], $titres, $largeurs),
            array_map(function (array $ligne) use ($largeurDuTexte, $placeDUneReference): array {
                $largeurDeLaReference = $largeurDuTexte($ligne['reference']);

                return [...$ligne, 'corpsDeLaReference' => $largeurDeLaReference > $placeDUneReference
                    ? floor(self::CORPS * $placeDUneReference / $largeurDeLaReference * 10) / 10
                    : null];
            }, $lignes),
        );
    }

    /**
     * Les cases d'une ligne qui ne passent jamais à la ligne, dans l'ordre
     * des colonnes à partir de la troisième : le n° de la facture
     * d'origine d'un avoir, puis les montants.
     *
     * @param  array{factureDOrigine: string|null, montants: list<string>}  $ligne
     * @return list<string>
     */
    private static function casesQuiNePassentPasALaLigne(array $ligne): array
    {
        return [...($ligne['factureDOrigine'] === null ? [] : [$ligne['factureDOrigine']]), ...$ligne['montants']];
    }
}
