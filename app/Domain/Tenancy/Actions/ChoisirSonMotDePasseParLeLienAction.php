<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Le propriétaire choisit son mot de passe sur la page du lien « Mot de
 * passe oublié » envoyé à son adresse de connexion (voir PageDuLien). Dans
 * une seule transaction, sous le verrou de la ligne du compte relue : le
 * jeton du lien se consomme (il ne sert qu'une fois), le mot de passe
 * devient le sien, suivant la règle du mot de passe choisi (voir
 * RegleDuMotDePasse), le lien suivi confirme son adresse, toutes ses
 * sessions se ferment et son « se souvenir de moi » se renouvelle.
 * Personne d'autre que lui ne connaît ce mot de passe. Un compte qui n'est
 * plus propriétaire (après une transmission de la propriété) ne se sert
 * plus d'aucun lien.
 */
class ChoisirSonMotDePasseParLeLienAction
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable : demandez-en un nouveau.';

    public function __construct(
        private readonly JetonsDuLien $jetons,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @throws AuthorizationException quand le lien n'est plus valable
     * @throws ValidationException
     */
    public function handle(User $compte, #[SensitiveParameter] string $jeton, #[SensitiveParameter] string $nouveauMotDePasse): void
    {
        RegleDuMotDePasse::exiger($nouveauMotDePasse, $compte->identifiantDeConnexion());

        DB::transaction(function () use ($compte, $jeton, $nouveauMotDePasse): void {
            $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->jetons->consommer($relu, $jeton)) {
                throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
            }

            $relu->forceFill([
                'password' => $nouveauMotDePasse,
                'mot_de_passe_est_l_identifiant' => false,
                'email_verified_at' => now(),
            ])->save();
            $this->sessions->fermer($relu);
        });

        $compte->refresh();
    }
}
