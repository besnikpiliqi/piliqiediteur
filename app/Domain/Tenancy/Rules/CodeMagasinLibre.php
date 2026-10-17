<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodeMagasin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le code saisi pour un magasin d'une société : lu en majuscules
 * (« lyon1 » est LYON1), au format de CodeMagasin, autre que DEMO, réservé
 * au magasin de démonstration, et qu'aucun autre magasin de la société ne
 * porte (voir CodeMagasin::estPris()). Le refus ne dit rien du magasin qui
 * le porte.
 */
class CodeMagasinLibre implements ValidationRule
{
    public const MESSAGE = 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.';

    public const MESSAGE_RESERVE = 'Le code DEMO est réservé au magasin de démonstration : choisissez-en un autre.';

    public const MESSAGE_DEJA_UTILISE = 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.';

    /**
     * @param  Magasin|null  $magasin  le magasin dont on modifie le code, qui garde le sien ; null pour un nouveau magasin
     */
    public function __construct(
        private readonly Societe $societe,
        private readonly ?Magasin $magasin = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CodeMagasin::estAuFormat($value)) {
            $fail(self::MESSAGE);

            return;
        }

        if (CodeMagasin::estReserve($value)) {
            $fail(self::MESSAGE_RESERVE);

            return;
        }

        if (CodeMagasin::estPris($this->societe, CodeMagasin::normaliser($value), $this->magasin)) {
            $fail(self::MESSAGE_DEJA_UTILISE);
        }
    }
}
