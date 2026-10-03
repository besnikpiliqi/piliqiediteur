<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le propriétaire crée lui-même le compte de ses salariés et leur
 * communique les identifiants directement (de la main à la main) : pas
 * d'auto-inscription, pas de lien de vérification par email. L'adresse
 * email n'a besoin d'être confirmée par personne -- c'est le magasin qui
 * se porte garant de la personne, pas une boîte mail.
 */
class CreerMembreMagasinAction
{
    /**
     * @param  array{name: string, email: string, password: string}  $donnees
     */
    public function handle(Magasin $magasin, array $donnees, RoleMagasin $role): User
    {
        if ($role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne peut pas être attribué via la gestion d\'équipe.');
        }

        return DB::transaction(function () use ($magasin, $donnees, $role) {
            $utilisateur = User::firstWhere('email', $donnees['email']);

            if ($utilisateur === null) {
                $utilisateur = User::create([
                    'name' => $donnees['name'],
                    'email' => $donnees['email'],
                    'password' => $donnees['password'],
                ]);

                // email_verified_at n'est pas mass-assignable (hors de
                // $fillable, volontairement, pour qu'une requête
                // utilisateur ne puisse jamais s'auto-vérifier). Vérifié
                // immédiatement ici : c'est le propriétaire qui crée ce
                // compte et transmet les identifiants lui-même, il n'y a
                // rien à confirmer par email.
                $utilisateur->forceFill(['email_verified_at' => now()])->save();
            } elseif ($utilisateur->appartientA($magasin)) {
                throw new LogicException('Cette personne fait déjà partie de ce magasin.');
            }
            // Si l'utilisateur existe déjà (ex: comptable qui intervient
            // déjà sur un autre magasin), on ne touche PAS à son mot de
            // passe existant -- le nouveau mot de passe du formulaire
            // n'est utilisé que pour la création d'un compte inédit.

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
