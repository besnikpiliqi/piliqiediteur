<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Ce que partage la création d'un compte par la société (voir
 * CreerMembreAction, CreerAdministrateurAction et
 * CreerExpertComptableAction) :
 * - son identité (un nom, une adresse e-mail enregistrée en minuscules,
 *   sans espaces autour) et son mot de passe provisoire, d'au moins 12
 *   caractères, remis de la main à la main, jamais journalisé ni envoyé
 *   par e-mail ;
 * - une adresse qui ne sert jamais à un nouveau compte si un compte
 *   l'utilise déjà : la réponse est la même quel que soit ce compte, sauf
 *   quand l'auteur le voit déjà dans sa société, à qui l'on propose de le
 *   réactiver ou de le réintégrer ;
 * - au plus 20 comptes créés par jour et par société, contre les abus ;
 * - le compte, actif, au mot de passe provisoire valable 72 heures, créé
 *   par l'auteur (cree_par), son adresse jamais confirmée : la personne
 *   peut le refuser (voir User::peutRefuserSonCompte()).
 */
final class CreationDesComptes
{
    public const LONGUEUR_MINIMALE_DU_PROVISOIRE = 12;

    public const COMPTES_PAR_JOUR = 20;

    public const HEURES_DU_PROVISOIRE = 72;

    public const MESSAGE_ADRESSE_INUTILISABLE = 'Cette adresse ne peut pas servir à un nouveau compte.';

    public const MESSAGE_DEJA_DANS_LA_SOCIETE = 'Cette personne a déjà un compte dans votre société : réactivez-le ou réintégrez-le au lieu d\'en créer un autre.';

    public const MESSAGE_LIMITE_DU_JOUR = 'Votre société a déjà créé 20 comptes aujourd\'hui : réessayez demain.';

    public const MESSAGE_PROVISOIRE_TROP_COURT = 'Le mot de passe provisoire doit compter au moins 12 caractères.';

    public function __construct(private readonly PorteeDesDroits $portee) {}

    /**
     * L'identité validée, l'adresse normalisée.
     *
     * @param  array{name?: mixed, email?: mixed}  $identite
     * @return array{name: string, email: string}
     *
     * @throws ValidationException
     */
    public function valider(User $auteur, Societe $societe, array $identite, #[SensitiveParameter] string $motDePasseProvisoire): array
    {
        $email = $identite['email'] ?? null;
        $valide = Validator::make([...$identite, 'email' => is_string($email) ? User::normaliserEmail($email) : $email, 'password' => $motDePasseProvisoire], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:'.self::LONGUEUR_MINIMALE_DU_PROVISOIRE],
        ], [
            'password.min' => self::MESSAGE_PROVISOIRE_TROP_COURT,
        ])->validate();

        $email = $valide['email'];
        $existant = User::query()->where('email', $email)->first();

        if ($existant !== null) {
            throw ValidationException::withMessages(['email' => $this->leVoitDejaDansSaSociete($auteur, $societe, $existant)
                ? self::MESSAGE_DEJA_DANS_LA_SOCIETE
                : self::MESSAGE_ADRESSE_INUTILISABLE]);
        }

        if (User::query()->where('societe_id', $societe->getKey())->whereNotNull('cree_par')->where('created_at', '>=', now()->startOfDay())->count() >= self::COMPTES_PAR_JOUR) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_LIMITE_DU_JOUR]);
        }

        return ['name' => $valide['name'], 'email' => $email];
    }

    /**
     * Crée le compte, dans la transaction de l'appelant : actif, au mot de
     * passe provisoire, créé par l'auteur, son adresse jamais confirmée.
     * Une autre création qui a pris l'adresse entre-temps reçoit la même
     * réponse, et rien n'est créé.
     *
     * @param  array{name: string, email: string}  $identite
     *
     * @throws ValidationException
     */
    public function creer(User $auteur, Societe $societe, array $identite, #[SensitiveParameter] string $motDePasseProvisoire, RoleSociete $role): User
    {
        try {
            return tap((new User([
                'name' => $identite['name'],
                'email' => $identite['email'],
                'password' => $motDePasseProvisoire,
            ]))->forceFill([
                'societe_id' => $societe->getKey(),
                'role_societe' => $role,
                'statut' => StatutCompte::Actif,
                'mot_de_passe_a_changer' => true,
                'mot_de_passe_provisoire_expire_le' => now()->addHours(self::HEURES_DU_PROVISOIRE),
                'cree_par' => $auteur->getKey(),
                'provisoire_donne_par' => $auteur->getKey(),
                'email_verified_at' => null,
            ]))->save();
        } catch (UniqueConstraintViolationException $doublon) {
            if (str_contains($doublon->getMessage(), 'users.email') || str_contains($doublon->getMessage(), 'users_email_unique')) {
                throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
            }

            throw $doublon;
        }
    }

    /**
     * Le compte existant est-il un compte de sa société qu'il voit déjà ?
     * Le propriétaire voit tous les comptes de sa société ; un responsable,
     * les vendeurs et les observateurs qu'il gère ; un administrateur
     * autorisé, les responsables de sa portée. Personne d'autre n'apprend
     * rien d'un compte existant.
     */
    private function leVoitDejaDansSaSociete(User $auteur, Societe $societe, User $existant): bool
    {
        if ((int) $existant->societe_id !== $societe->getKey() || (int) $auteur->societe_id !== $societe->getKey()) {
            return false;
        }

        return $auteur->estProprietaire()
            || $this->portee->gereLeCompte($auteur, $existant)
            || $this->portee->voit($auteur, $existant);
    }
}
