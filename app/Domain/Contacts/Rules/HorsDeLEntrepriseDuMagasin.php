<?php

namespace App\Domain\Contacts\Rules;

use App\Domain\Facturation\Services\NumeroTva;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le SIREN, le SIRET ou le n° de TVA d'un client ou d'un fournisseur n'est
 * jamais celui de l'entreprise du magasin : une opération entre magasins
 * d'une même société n'est ni une vente ni un achat (art. 256 I du CGI),
 * et aucune marchandise ne passe d'un magasin à l'autre. L'identifiant
 * saisi désigne cette entreprise quand c'est :
 * - un SIREN ou un SIRET qui commence par le SIREN du magasin ;
 * - un n° de TVA qui, sans ses séparateurs (voir NumeroTva::normaliser()),
 *   est celui du magasin, ou le n° français que forme son SIREN (FR, clé
 *   à deux caractères, SIREN), qu'un magasin membre d'un groupe TVA
 *   n'utilise plus mais qui reste le sien.
 *
 * Seuls le SIREN et le n° de TVA du magasin actif sont lus ; un magasin qui
 * ne les a pas encore n'écarte rien de ce qu'ils désigneraient.
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
        $magasin = app(TenantContext::class)->magasin();
        $numeroTva = NumeroTva::normaliser($identifiant);

        if (filled($magasin->siren)
            && (str_starts_with($identifiant, $magasin->siren) || preg_match('/^FR[0-9A-Z]{2}'.preg_quote($magasin->siren, '/').'$/', $numeroTva) === 1)) {
            return true;
        }

        return filled($magasin->numero_tva) && $numeroTva === $magasin->numero_tva;
    }
}
