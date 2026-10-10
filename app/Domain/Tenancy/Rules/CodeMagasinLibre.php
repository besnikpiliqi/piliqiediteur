<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CodeMagasin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le code saisi dans l'identité d'un magasin : au format de CodeMagasin
 * (voir CodeMagasinAuFormat), et qu'aucun autre magasin du logiciel ne
 * porte, même supprimé. Le refus ne dit rien du magasin qui le porte.
 */
class CodeMagasinLibre implements ValidationRule
{
    public const MESSAGE_DEJA_UTILISE = 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.';

    /**
     * @param  Magasin  $magasin  le magasin dont on modifie le code, qui garde le sien
     */
    public function __construct(private readonly Magasin $magasin) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CodeMagasin::estAuFormat($value)) {
            $fail(CodeMagasinAuFormat::MESSAGE);

            return;
        }

        if (CodeMagasin::estPris(CodeMagasin::normaliser($value), $this->magasin)) {
            $fail(self::MESSAGE_DEJA_UTILISE);
        }
    }
}
