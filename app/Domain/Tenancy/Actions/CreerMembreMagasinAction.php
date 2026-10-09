<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Rules\EmailLibrePourUnNouveauMembre;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Le propriétaire crée lui-même le compte de ses salariés et leur
 * communique les identifiants directement (de la main à la main) : pas
 * d'auto-inscription, pas de lien de vérification par email. L'adresse
 * email n'a besoin d'être confirmée par personne -- c'est le magasin qui
 * se porte garant de la personne, pas une boîte mail.
 *
 * C'est toujours un NOUVEAU compte, qui n'appartiendra qu'à ce magasin :
 * une adresse déjà utilisée par un compte est refusée (voir
 * EmailLibrePourUnNouveauMembre).
 *
 * Le rôle n'est qu'un libellé et un modèle : le membre reçoit, directement
 * dans ce magasin, les autorisations cochées pour lui, ou sans liste
 * celles du modèle de son rôle (voir RoleMagasin::permissionsParDefaut()).
 * Une autorisation inconnue ou réservée au propriétaire n'est jamais
 * donnée (voir PermissionRegistry::cochables()).
 *
 * Les droits donnés au nouveau membre s'inscrivent au journal des
 * événements du magasin (JET 130, voir JournalDesDroits).
 */
class CreerMembreMagasinAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    /**
     * @param  array{name: string, email: string, password: string}  $donnees
     * @param  list<string>|null  $permissions  les autorisations cochées ; null : le modèle du rôle
     *
     * @throws ValidationException quand l'adresse e-mail est déjà utilisée par un compte
     * @throws InvalidArgumentException quand une autorisation est inconnue ou réservée au propriétaire
     */
    public function handle(Magasin $magasin, array $donnees, RoleMagasin $role, ?array $permissions = null): User
    {
        if ($role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne peut pas être attribué via la gestion d\'équipe.');
        }

        $permissions = PermissionRegistry::verifierCochables($permissions ?? $role->permissionsParDefaut());

        Validator::make($donnees, ['email' => [new EmailLibrePourUnNouveauMembre($magasin)]])->validate();

        return DB::transaction(function () use ($magasin, $donnees, $role, $permissions) {
            // Défensif : le propriétaire agit normalement déjà depuis le
            // panneau de CE magasin (TenantContext déjà positionné dessus
            // par Filament), mais une Action ne doit jamais supposer le
            // contexte ambiant d'un appelant qu'elle ne contrôle pas : les
            // autorisations et le journal des événements sont ceux du
            // magasin actif.
            app(TenantContext::class)->set($magasin);
            $avant = $this->journalDesDroits->avant($magasin, null);

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

            $utilisateur->syncPermissions($permissions);
            $this->journalDesDroits->apres($magasin, $utilisateur, $avant);

            return $utilisateur;
        });
    }
}
