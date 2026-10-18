<?php

namespace App\Domain\Facturation\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le PDF d'un ticket, d'une facture, d'un avoir ou d'un devis (point 4) :
 * une vraie page A4 en portrait, jamais réduite, même pour une seule
 * ligne, le format étant fixé ici comme dans la vue (règle @page de
 * documents/pdf/styles) ; le pied de page de la vue se répète en bas de
 * chaque page (voir PiedDePage), et chaque page porte, sous lui,
 * « Page x sur y », dessiné une fois le document mis en pages, quand le
 * nombre de pages est connu (un compteur CSS de pages n'en donne aucun
 * dans dompdf).
 */
final class MiseEnPageA4
{
    /**
     * Le point d'une page PDF vaut 1/72 de pouce : un millimètre en vaut
     * 72 ÷ 25,4.
     */
    private const POINTS_PAR_MILLIMETRE = 72 / 25.4;

    /**
     * La marge de droite de la page, en millimètres, comme celle de la vue.
     */
    private const MARGE_DROITE = 14;

    /**
     * Le haut du numéro de page, en millimètres au-dessus du bas de la
     * page, sous le pied (voir documents/pdf/styles).
     */
    private const NUMERO_AU_DESSUS_DU_BAS = 10;

    private const CORPS_DU_NUMERO = 7;

    /**
     * Le document mis en pages, numéros de page compris : sa sortie
     * (output()) est le fichier PDF.
     */
    public function pdf(string $html): PdfDocument
    {
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canevas = $dompdf->getCanvas();
        $police = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $largeur = $dompdf->getFontMetrics()->getTextWidth('Page 999 sur 999', $police, self::CORPS_DU_NUMERO);

        $canevas->page_text(
            $canevas->get_width() - self::MARGE_DROITE * self::POINTS_PAR_MILLIMETRE - $largeur,
            $canevas->get_height() - self::NUMERO_AU_DESSUS_DU_BAS * self::POINTS_PAR_MILLIMETRE,
            'Page {PAGE_NUM} sur {PAGE_COUNT}',
            $police,
            self::CORPS_DU_NUMERO,
            [0.32, 0.38, 0.43],
        );

        return $pdf;
    }
}
