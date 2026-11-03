<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Support\IdentifiantDeConnexion;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Le fournisseur des comptes de l'authentification (config/auth.php) :
 * - à la connexion (clé « login »), une saisie avec « @ » est une adresse,
 *   normalisée (voir User::normaliserEmail()), cherchée parmi les
 *   adresses de connexion des propriétaires seulement : une adresse en
 *   attente de son lien (voir DemanderUneNouvelleAdresseAction) ou celle
 *   d'une inscription close (voir CloreLInscriptionAction) n'en est pas
 *   une, et reçoit la même réponse, au même délai, qu'une adresse
 *   inconnue ; toute autre saisie est un identifiant de
 *   connexion (voir IdentifiantDeConnexion), qui désigne un compte de tout
 *   rôle, propriétaire compris. L'adresse de contact d'un autre compte
 *   n'ouvre jamais ce compte ;
 * - « Mot de passe oublié » et la page de son lien (clé « email ») : les
 *   propriétaires seulement ;
 * - « Se souvenir de moi » : seulement un compte encore propriétaire (voir
 *   retrieveByToken()).
 * La seule lecture d'un identifiant hors de la société active : à la
 * connexion, le compte qu'il désigne, et rien d'autre.
 *
 * Même délai pour un compte introuvable : quand aucun compte ne répond à
 * la saisie d'un essai de connexion, le mot de passe tapé se vérifie contre
 * une empreinte factice au coût de hachage de l'application, écrite d'avance
 * et jamais calculée pendant la requête (voir empreinteFactice()), dans la
 * « timebox » de l'essai (voir config/auth.php, timebox_duration) : un
 * identifiant inconnu coûte une seule vérification, comme un compte existant
 * au mauvais mot de passe. Le compte se résout, dans les deux cas, par une
 * seule requête (voir compteDeLIdentifiant()).
 *
 * Un mot de passe exact n'ouvre le compte (voir validateCredentials()) que
 * pour un compte actif et, tant que son mot de passe est encore son
 * identifiant, que depuis un poste reconnu d'un magasin que ce compte ouvre
 * (voir PostesReconnus) : un compte suspendu ou retiré, ou un tel essai
 * ailleurs, reçoit la même réponse, au même délai, qu'un mot de passe faux.
 */
class FournisseurDesComptes extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $compte = $this->compteDeLaSaisie($credentials);

        if ($compte === null && is_string($credentials['password'] ?? null)) {
            $this->hasher->check($credentials['password'], self::empreinteFactice());
        }

        return $compte;
    }

    /**
     * Le compte que désigne la saisie, sans aucune vérification de mot de
     * passe : la connexion le résout avant l'essai, pour le témoin d'un
     * poste connu (voir PostesConnus).
     *
     * @param  array<string, mixed>  $credentials
     */
    public function compteDeLaSaisie(#[SensitiveParameter] array $credentials): ?User
    {
        if (is_string($credentials['login'] ?? null)) {
            $saisie = $credentials['login'];

            return str_contains($saisie, '@') ? self::proprietaireDeLAdresse($saisie) : self::compteDeLIdentifiant($saisie);
        }

        if (is_string($credentials['email'] ?? null)) {
            return self::proprietaireDeLAdresse($credentials['email']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        if (! parent::validateCredentials($user, $credentials) || ! $user instanceof User || ! $user->estActif()) {
            return false;
        }

        return ! $user->mot_de_passe_est_l_identifiant || app(PostesReconnus::class)->ouvreLeCompte($user, request());
    }

    /**
     * Le compte du cookie « Se souvenir de moi », seulement s'il est encore
     * propriétaire : celui d'un ancien propriétaire n'ouvre plus rien.
     *
     * @param  mixed  $identifier
     * @param  string  $token
     */
    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        $compte = parent::retrieveByToken($identifier, $token);

        return $compte instanceof User && $compte->estProprietaire() ? $compte : null;
    }

    /**
     * Le propriétaire dont c'est l'adresse de connexion active (un
     * propriétaire sans adresse de connexion a une adresse nulle, qui ne
     * répond à aucune saisie).
     */
    public static function proprietaireDeLAdresse(string $adresse): ?User
    {
        return User::query()
            ->where('role_societe', RoleSociete::Proprietaire->value)
            ->where('email', User::normaliserEmail($adresse))
            ->first();
    }

    /**
     * Le compte que désigne cet identifiant, écrit avec ou sans espaces,
     * tirets ou points, en une seule requête, qu'il existe ou non ; null
     * pour une saisie qui n'a pas la forme d'un identifiant ou qui n'en
     * désigne aucun.
     */
    public static function compteDeLIdentifiant(string $saisie): ?User
    {
        $identifiant = IdentifiantDeConnexion::normaliser($saisie);

        if (! IdentifiantDeConnexion::aLaForme($identifiant)) {
            return null;
        }

        return User::query()
            ->whereIn('id', Identifiant::withoutGlobalScopes()->where('identifiant', $identifiant)->select('user_id'))
            ->first();
    }

    /**
     * L'empreinte factice : une empreinte bcrypt bien formée, au coût de
     * hachage de l'application (hashing.bcrypt.rounds), dont le sel et le
     * résultat sont fixes. La vérifier coûte exactement une vérification
     * bcrypt de ce coût, sans jamais hacher pendant la requête, dans chaque
     * processus et à chaque requête ; aucun mot de passe n'y répond, et un
     * compte introuvable n'ouvre rien de toute façon.
     */
    private static function empreinteFactice(): string
    {
        return sprintf('$2y$%02d$%s', (int) config('hashing.bcrypt.rounds', 12), str_repeat('.', 53));
    }
}
