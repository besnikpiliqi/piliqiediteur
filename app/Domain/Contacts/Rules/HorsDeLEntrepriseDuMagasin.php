<?php

namespace App\Domain\Contacts\Rules;

use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le SIREN ou le SIRET d'un client ou d'un fournisseur n'est jamais celui
 * de l'entreprise du magasin : une opération entre magasins d'une même
 * société n'est ni une vente ni un achat (art. 256 I du CGI), et aucune
 * marchandise ne passe d'un magasin à l'autre. Un SIRET y appartient quand
 * ses 9 premiers chiffres sont le SIREN du magasin. Seul le SIREN du
 * magasin actif est lu ; un magasin qui n'a pas encore le sien n'écarte
 * rien.
 */
class HorsDeLEntrepriseDuMagasin implements ValidationRule
{
    public function __construct(private readonly string $message) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $siren = app(TenantContext::class)->magasin()->siren;

        if (filled($siren) && is_string($value) && str_starts_with($value, $siren)) {
            $fail($this->message);
        }
    }
}
