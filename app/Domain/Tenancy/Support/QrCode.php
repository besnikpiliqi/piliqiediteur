<?php

namespace App\Domain\Tenancy\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Un QR code en SVG (bibliothèque bacon/bacon-qr-code, sans extension
 * graphique), tel que le courrier recommandé à code l'imprime (voir
 * CourrierDeCode) : il ne porte jamais que l'adresse de la page où saisir
 * le code, jamais le code lui-même. La correction d'erreur moyenne (M)
 * le garde lisible sur un papier froissé ou mal imprimé.
 */
final class QrCode
{
    /**
     * Le côté de l'image, en pixels SVG : le courrier la met à l'échelle.
     */
    public const COTE = 200;

    public static function svg(string $contenu): string
    {
        $renderer = new ImageRenderer(new RendererStyle(self::COTE, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($contenu, ecLevel: ErrorCorrectionLevel::M());
    }

    /**
     * Le QR code en URI data:, tel qu'une image de la page du courrier le
     * reçoit.
     */
    public static function uriDeDonnees(string $contenu): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($contenu));
    }
}
