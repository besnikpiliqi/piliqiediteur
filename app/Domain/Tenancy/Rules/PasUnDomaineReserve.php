<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Demonstration\SiteDePiliqi;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Une adresse e-mail sur un domaine réservé aux exemples (example.com,
 * example.net, example.org, et les noms en .example, .test, .invalid ou
 * .localhost, RFC 2606 et 6761) : aucun e-mail n'y arrive (lot DEMO-COPIE,
 * choix 26). En production, sur le site réel, une adresse de connexion,
 * une adresse professionnelle d'expert-comptable et une adresse
 * d'invitation sur un tel domaine sont refusées ; en développement et en
 * test, le semis s'en sert, et sur le site de démonstration ses sociétés
 * fictives aussi.
 */
final class PasUnDomaineReserve implements ValidationRule
{
    public const MESSAGE = 'Cette adresse est une adresse d\'exemple : aucun e-mail n\'y arrive. Saisissez une vraie adresse.';

    public const DOMAINES = ['example.com', 'example.net', 'example.org'];

    public const SUFFIXES = ['example', 'test', 'invalid', 'localhost'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::s_applique() && self::estReserve($value)) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * La règle ne vaut qu'en production, sur le site réel.
     */
    public static function s_applique(): bool
    {
        return app()->isProduction() && SiteDePiliqi::courant() === SiteDePiliqi::Reel;
    }

    public static function estReserve(string $adresse): bool
    {
        $domaine = mb_strtolower(rtrim(trim((string) substr(strrchr($adresse, '@') ?: '', 1)), '.'));

        if ($domaine === '') {
            return false;
        }

        foreach (self::DOMAINES as $reserve) {
            if ($domaine === $reserve || str_ends_with($domaine, '.'.$reserve)) {
                return true;
            }
        }

        $dernier = substr(strrchr('.'.$domaine, '.') ?: '', 1);

        return in_array($dernier, self::SUFFIXES, true);
    }
}
