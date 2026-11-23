<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;

/**
 * L'image des initiales d'un compte, dans le menu de chaque panneau,
 * dessinée par PILIQI (décision de l'éditeur du 03/11/2026, Q-TIERS) : une
 * image SVG dans l'adresse même (data:), sans aucune requête, quand le
 * fournisseur par défaut de Filament la demandait à ui-avatars.com avec le
 * nom de la personne. Au plus deux initiales, en majuscules, en blanc sur
 * le gris le plus sombre du thème, comme celui-ci.
 */
final class InitialesDuCompte implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initiales = self::initiales(Filament::getNameForDefaultAvatar($record));
        $fond = 'rgb('.FilamentColor::getColors()['gray'][950].')';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.e($fond).'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#FFFFFF" font-family="Inter, sans-serif" font-size="26" font-weight="500">'.e($initiales).'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * La première lettre des deux premiers mots du nom (« Émilie de
     * Villiers » donne « ÉD »).
     */
    public static function initiales(string $nom): string
    {
        $mots = preg_split('/\s+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(static fn (string $mot): string => mb_substr($mot, 0, 1), array_slice($mots, 0, 2))));
    }
}
