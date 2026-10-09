<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CodeMagasin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le code saisi pour un magasin, à l'inscription ou dans son identité :
 * lu en majuscules (« lyon1 » est LYON1), au format de CodeMagasin, et
 * qu'aucun autre magasin du logiciel ne porte, même supprimé. Le refus ne
 * dit rien du magasin qui le porte.
 */
class CodeMagasinLibre implements ValidationRule
{
    public const MESSAGE_FORMAT = 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.';

    public const MESSAGE_DEJA_UTILISE = 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.';

    /**
     * @param  Magasin|null  $magasin  le magasin dont on modifie le code, qui garde le sien ; null pour un magasin qui reste à créer
     */
    public function __construct(private readonly ?Magasin $magasin = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = is_string($value) ? CodeMagasin::normaliser($value) : '';

        if (preg_match(CodeMagasin::FORMAT, $code) !== 1) {
            $fail(self::MESSAGE_FORMAT);

            return;
        }

        if (CodeMagasin::estPris($code, $this->magasin)) {
            $fail(self::MESSAGE_DEJA_UTILISE);
        }
    }
}
