<?php

namespace App\Domain\Facturation\Services;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * Le tableau des règlements d'un PDF (constat C3) : une rangée par
 * paiement, en colonnes Date, Mode, Référence et Montant, puis une par
 * crédit d'avoir (le mode « Avoir », le numéro de l'avoir pour référence).
 * Sur un ticket ou une facture, il se place à gauche du tableau des
 * totaux, sur la même bande (voir surLaBande() et documents/pdf/totaux),
 * une seule rangée de tableau que dompdf ne coupe jamais entre deux pages ;
 * sur un reçu de paiement, il prend toute la largeur de la page (voir
 * surLaPage() et recus/pdf).
 *
 * Une référence passe à la ligne entre deux mots, jamais au milieu d'un
 * mot : dompdf coupe aussi un texte après un trait d'union (« CB- » puis
 * « TRX-4521 »), si bien que chaque mot qui tient sur une ligne de sa
 * colonne, mesuré avec la police du PDF, s'imprime d'un seul tenant
 * (insécable, voir documents/pdf/tableau-des-reglements). Seul un mot plus long que
 * toute la colonne se coupe, là où la place manque. Les blancs entre deux
 * mots, où la référence passe à la ligne, se réduisent à une espace.
 *
 * Les dimensions sont en points, celles que la feuille de style écrit
 * (voir documents/pdf/styles) : le corps et les marges des cases, la
 * largeur du tableau des totaux et l'écart qui l'en sépare, et la part de
 * chaque colonne dans la largeur du tableau, en pourcentage (dompdf ne lit
 * qu'une largeur en pourcentage dans un tableau à disposition fixe).
 */
final readonly class TableauDesReglements
{
    /**
     * Le corps du texte du tableau, en points (9 px).
     */
    public const CORPS = 6.75;

    /**
     * La marge intérieure de chaque case, à gauche comme à droite, en
     * points (6 px).
     */
    public const MARGE_DE_CASE = 4.5;

    /**
     * La largeur du tableau des totaux, à droite de la bande, en points
     * (260 px) : celle qu'il a toujours eue, si bien que les totaux d'un
     * avoir, d'un devis ou d'un document sans règlement restent à leur
     * place.
     */
    public const LARGEUR_DES_TOTAUX = 195.0;

    /**
     * L'écart entre le tableau des règlements et celui des totaux, en
     * points (14 px).
     */
    public const ECART_AVEC_LES_TOTAUX = 10.5;

    /**
     * La part de chaque colonne dans la largeur du tableau, en pourcentage.
     */
    public const COLONNES = ['date' => 15, 'mode' => 20, 'reference' => 48, 'montant' => 17];

    /**
     * Ce que chaque mesure garde en plus de la largeur du texte, en points,
     * contre l'arrondi des positions.
     */
    private const JEU = 1.0;

    /**
     * @param  list<array{date: string, mode: string, mots: list<array{texte: string, insecable: bool}>, montant: string}>  $rangees  chaque règlement tel qu'il s'imprime : sa date, son mode, les mots de sa référence et son montant écrit en euros
     */
    private function __construct(public array $rangees) {}

    /**
     * Le tableau des règlements d'un ticket ou d'une facture, à gauche des
     * totaux : la référence a la part de sa colonne dans ce qui reste de la
     * largeur utile de la page à côté du tableau des totaux.
     *
     * @param  list<array{date: string, mode: string, reference: string|null, montant: int}>  $reglements  montants en centimes
     */
    public static function surLaBande(array $reglements): self
    {
        return self::mesurer($reglements, MiseEnPageA4::LARGEUR_UTILE - self::LARGEUR_DES_TOTAUX - self::ECART_AVEC_LES_TOTAUX);
    }

    /**
     * Le tableau des règlements d'un reçu de paiement, de toute la largeur
     * utile de la page.
     *
     * @param  list<array{date: string, mode: string, reference: string|null, montant: int}>  $reglements  montants en centimes
     */
    public static function surLaPage(array $reglements): self
    {
        return self::mesurer($reglements, MiseEnPageA4::LARGEUR_UTILE);
    }

    /**
     * Chaque règlement avec les mots de sa référence, mesurés avec une même
     * police, et son montant écrit en euros.
     *
     * @param  list<array{date: string, mode: string, reference: string|null, montant: int}>  $reglements
     * @param  float  $largeurDuTableau  en points
     */
    private static function mesurer(array $reglements, float $largeurDuTableau): self
    {
        $polices = app(Dompdf::class)->getFontMetrics();
        $calculateur = app(CalculateurLigne::class);
        $place = $largeurDuTableau * self::COLONNES['reference'] / 100 - 2 * self::MARGE_DE_CASE;

        return new self(array_map(fn (array $reglement): array => [
            'date' => $reglement['date'],
            'mode' => $reglement['mode'],
            'mots' => self::mots($reglement['reference'], $place, $polices),
            'montant' => $calculateur->enEuros($reglement['montant']),
        ], $reglements));
    }

    /**
     * Les mots d'une référence, dans l'ordre, chacun avec ce que la vue en
     * fait : un mot qui tient dans cette place s'imprime d'un seul tenant
     * (insécable) ; un mot plus long qu'elle peut seul se couper. Aucune
     * référence, aucun mot.
     *
     * @return list<array{texte: string, insecable: bool}>
     */
    private static function mots(?string $reference, float $place, FontMetrics $polices): array
    {
        $mots = preg_split('/\s+/u', trim((string) $reference), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $police = $polices->getFont('DejaVu Sans');

        return array_map(fn (string $mot): array => [
            'texte' => $mot,
            'insecable' => $polices->getTextWidth($mot, $police, self::CORPS) + self::JEU <= $place,
        ], $mots);
    }
}
