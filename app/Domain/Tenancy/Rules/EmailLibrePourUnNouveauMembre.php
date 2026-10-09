<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un compte User est global : il ouvre tous les magasins auxquels il est
 * rattaché. Un magasin ne crée donc un membre qu'avec une adresse e-mail
 * qu'aucun compte n'utilise encore, même supprimé (l'adresse reste unique
 * en base) : sinon il suffirait de connaître l'adresse d'un compte pour se
 * l'approprier avec les magasins qu'il ouvre.
 *
 * Le refus ne dit rien des autres magasins. Seule une personne déjà
 * rattachée à CE magasin, quel que soit son statut, est désignée comme
 * telle : le magasin la voit déjà dans sa liste d'équipe.
 */
class EmailLibrePourUnNouveauMembre implements ValidationRule
{
    public function __construct(private readonly Magasin $magasin) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $compte = User::withTrashed()->where('email', $value)->first();

        if ($compte === null) {
            return;
        }

        $fail($this->magasin->membres()->where('user_id', $compte->getKey())->exists()
            ? 'Cette personne fait déjà partie de votre équipe : modifiez son statut.'
            : 'Cette adresse e-mail ne peut pas être utilisée pour un nouveau membre.');
    }
}
