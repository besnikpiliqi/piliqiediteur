<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodeMagasin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le code saisi pour un magasin d'une société : au format de CodeMagasin
 * (voir CodeMagasinAuFormat), et qu'aucun autre magasin de la société ne
 * porte (voir CodeMagasin::estPris()). Le refus ne dit rien du magasin qui
 * le porte.
 */
class CodeMagasinLibre implements ValidationRule
{
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
            $fail(CodeMagasinAuFormat::MESSAGE);

            return;
        }

        if (CodeMagasin::estPris($this->societe, CodeMagasin::normaliser($value), $this->magasin)) {
            $fail(self::MESSAGE_DEJA_UTILISE);
        }
    }
}
