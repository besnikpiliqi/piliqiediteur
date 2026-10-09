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
 * Le magasin crée lui-même le compte de ses salariés et leur communique
 * les identifiants directement (de la main à la main) : pas
 * d'auto-inscription, pas de lien de vérification par email. L'adresse
 * email n'a besoin d'être confirmée par personne -- c'est le magasin qui
 * se porte garant de la personne, pas une boîte mail.
 *
 * C'est toujours un NOUVEAU compte, qui n'appartiendra qu'à ce magasin :
 * une adresse déjà utilisée par un compte est refusée (voir
 * EmailLibrePourUnNouveauMembre).
 *
 * Le rôle n'est qu'un libellé et un modèle (voir
 * RoleMagasin::permissionsParDefaut()) : le propriétaire coche les
 * autorisations de chaque membre. Il donne au nouveau membre celles qu'il
 * coche, ou sans liste le modèle de son rôle. Un autre membre qui gère
 * l'équipe ne coche rien : le nouveau membre reçoit le modèle de son rôle,
 * limité aux autorisations de celui qui le crée, qui ne crée donc jamais
 * un compte plus autorisé que lui (voir autorisationsDuModele()).
 *
 * Les droits donnés au nouveau membre s'inscrivent au journal des
 * événements du magasin (JET 130, voir JournalDesDroits).
 */
class CreerMembreMagasinAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    /**
     * @param  array{name: string, email: string, password: string}  $donnees
     * @param  list<string>|null  $permissions  les autorisations que le propriétaire coche ; null : le modèle du rôle
     *
     * @throws ValidationException quand l'adresse e-mail est déjà utilisée par un compte
     * @throws InvalidArgumentException quand une autorisation est inconnue
     * @throws LogicException quand un autre membre que le propriétaire coche les autorisations
     */
    public function handle(User $auteur, Magasin $magasin, array $donnees, RoleMagasin $role, ?array $permissions = null): User
    {
        if ($role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le rôle propriétaire ne peut pas être attribué via la gestion d\'équipe.');
        }

        if ($permissions !== null) {
            if (! $auteur->estProprietaireDe($magasin)) {
                throw new LogicException('Seul le propriétaire coche les autorisations d\'un membre.');
            }

            $permissions = PermissionRegistry::verifierConnues($permissions);
        }

        Validator::make($donnees, ['email' => [new EmailLibrePourUnNouveauMembre($magasin)]])->validate();

        return DB::transaction(function () use ($auteur, $magasin, $donnees, $role, $permissions) {
            // Défensif : l'auteur agit normalement déjà depuis le panneau
            // de CE magasin (TenantContext déjà positionné dessus par
            // Filament), mais une Action ne doit jamais supposer le
            // contexte ambiant d'un appelant qu'elle ne contrôle pas : les
            // autorisations et le journal des événements sont ceux du
            // magasin actif.
            app(TenantContext::class)->set($magasin);

            // Le registre du magasin, verrouillé, sérialise les
            // modifications de ses droits : les autorisations de l'auteur
            // sont relues après lui.
            $avant = $this->journalDesDroits->avant($magasin, null);
            $permissions ??= $this->autorisationsDuModele($auteur, $magasin, $role);

            $utilisateur = User::create([
                'name' => $donnees['name'],
                'email' => $donnees['email'],
                'password' => $donnees['password'],
            ]);

            // email_verified_at n'est pas mass-assignable (hors de
            // $fillable, volontairement, pour qu'une requête utilisateur ne
            // puisse jamais s'auto-vérifier). Vérifié immédiatement ici :
            // c'est le magasin qui crée ce compte et transmet les
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

    /**
     * Les autorisations que reçoit un nouveau membre de ce rôle, créé par
     * cet auteur sans liste cochée : tout le modèle du rôle quand l'auteur
     * est le propriétaire ; sinon, seulement celles du modèle que l'auteur
     * a lui-même dans ce magasin. L'écran de création les affiche telles
     * quelles (voir MembreEquipeResource::champRole()).
     *
     * @return list<string>
     */
    public function autorisationsDuModele(User $auteur, Magasin $magasin, RoleMagasin $role): array
    {
        $modele = $role->permissionsParDefaut();

        if ($auteur->estProprietaireDe($magasin)) {
            return $modele;
        }

        return array_values(array_intersect($modele, $auteur->autorisationsCocheesDans($magasin)));
    }
}
