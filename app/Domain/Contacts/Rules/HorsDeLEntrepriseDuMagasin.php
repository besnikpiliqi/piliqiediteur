<?php

namespace App\Domain\Contacts\Rules;

use App\Domain\Facturation\Services\NumeroTva;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le SIREN, le SIRET ou le n° de TVA d'un client ou d'un fournisseur n'est
 * jamais celui de la société du magasin : une opération entre magasins
 * d'une même société n'est ni une vente ni un achat (art. 256 I du CGI),
 * et aucune marchandise ne passe d'un magasin à l'autre. L'identifiant
 * saisi désigne cette société quand c'est :
 * - un SIREN ou un SIRET qui commence par son SIREN ;
 * - un n° de TVA qui, sans ses séparateurs (voir NumeroTva::normaliser()),
 *   est le sien, ou le n° français que forme son SIREN (FR, clé à deux
 *   caractères, SIREN), qu'une société membre d'un groupe TVA n'utilise
 *   plus mais qui reste le sien.
 *
 * Seuls le SIREN et le n° de TVA de la société du magasin actif sont lus.
 */
class HorsDeLEntrepriseDuMagasin implements ValidationRule
{
    public function __construct(private readonly string $message) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->designeLEntrepriseDuMagasin($value)) {
            $fail($this->message);
        }
    }

    private function designeLEntrepriseDuMagasin(string $identifiant): bool
    {
        $societe = app(TenantContext::class)->magasin()->societe;
        $numeroTva = NumeroTva::normaliser($identifiant);

        if (str_starts_with($identifiant, $societe->siren) || preg_match('/^FR[0-9A-Z]{2}'.preg_quote($societe->siren, '/').'$/', $numeroTva) === 1) {
            return true;
        }

        return filled($societe->numero_tva) && $numeroTva === $societe->numero_tva;
    }
}
