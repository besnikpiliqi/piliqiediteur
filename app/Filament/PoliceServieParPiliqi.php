<?php

namespace App\Filament;

use Filament\FontProviders\Contracts\FontProvider;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * La police Inter des trois panneaux, servie par PILIQI depuis
 * public/fonts/inter (décision de l'éditeur du 03/11/2026, Q-TIERS), quand
 * le fournisseur par défaut de Filament la demandait à fonts.bunny.net.
 * L'adresse de la feuille de style se calcule à chaque page, depuis
 * l'adresse de la requête : elle reste celle du site qui sert la page.
 */
final class PoliceServieParPiliqi implements FontProvider
{
    public const FEUILLE_DE_STYLE = 'fonts/inter/inter.css';

    public function getHtml(string $family, ?string $url = null): Htmlable
    {
        return new HtmlString('<link href="'.e(asset(self::FEUILLE_DE_STYLE)).'" rel="stylesheet" />');
    }
}
