<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Rules\EmailLibrePourUnNouveauMembre;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Le propriétaire crée lui-même le compte de ses salariés et leur
 * communique les identifiants directement (de la main à la main) : pas
 * d'auto-inscription, pas de lien de vérification par email. L'adresse
 * email n'a besoin d'être confirmée par personne -- c'est le magasin qui
 * se porte garant de la personne, pas une boîte mail.
 *
 * C'est toujours un NOUVEAU compte : une adresse déjà utilisée par un
 * compte est refusée (voir EmailLibrePourUnNouveauMembre), et le nom ou le
 * mot de passe d'un compte existant ne sont jamais réécrits ici.
 */
class CreerMembreMagasinAction
{
    /**
     * @param  array{name: string, email: string, password: string}  $donnees
     *
     * @throws ValidationException quand l'adresse e-mail est déjà utilisée par un compte
     */
    public function handle(Magasin $magasin, array $donnees, RoleMagasin $role): User
    {
        if ($role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne peut pas être attribué via la gestion d\'équipe.');
        }

        Validator::make($donnees, ['email' => [new EmailLibrePourUnNouveauMembre($magasin)]])->validate();

        return DB::transaction(function () use ($magasin, $donnees, $role) {
            $utilisateur = User::create([
                'name' => $donnees['name'],
                'email' => $donnees['email'],
                'password' => $donnees['password'],
            ]);

            // email_verified_at n'est pas mass-assignable (hors de
            // $fillable, volontairement, pour qu'une requête utilisateur ne
            // puisse jamais s'auto-vérifier). Vérifié immédiatement ici :
            // c'est le propriétaire qui crée ce compte et transmet les
            // identifiants lui-même, il n'y a rien à confirmer par email.
            $utilisateur->forceFill(['email_verified_at' => now()])->save();

            $magasin->utilisateurs()->attach($utilisateur->getKey(), [
                'role' => $role->value,
                'statut' => StatutMembreMagasin::Actif->value,
                'joined_at' => now(),
            ]);

            // Défensif : le propriétaire agit normalement déjà depuis le
            // panneau de CE magasin (TenantContext déjà positionné dessus
            // par Filament), mais une Action ne doit jamais supposer le
            // contexte ambiant d'un appelant qu'elle ne contrôle pas.
            app(TenantContext::class)->set($magasin);
            $utilisateur->assignRole($role->value);

            return $utilisateur;
        });
    }
}
