<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * La personne choisit elle-même son mot de passe : dans son profil (voir
 * ModifierProfil), ou à sa première connexion quand elle est responsable,
 * administrateur ou expert-comptable (voir ChoisirSonMotDePasse). Il suit
 * la règle du mot de passe choisi (voir RegleDuMotDePasse) ; sous le verrou
 * de la ligne du compte relue, il remplace l'ancien, le mot de passe n'est
 * plus l'identifiant, ses autres sessions se ferment et son « se souvenir
 * de moi » se renouvelle ; seule la session courante reste. Après un accès
 * redonné par un autre que le propriétaire, le propriétaire est prévenu de
 * ce premier mot de passe choisi (voir RedonnerLAccesAction). Personne
 * d'autre que la personne ne le connaît.
 */
class ChangerSonMotDePasseAction
{
    public function __construct(private readonly SessionsDesComptes $sessions) {}

    /**
     * @throws ValidationException quand le mot de passe ne suit pas la règle
     */
    public function handle(User $compte, #[SensitiveParameter] string $nouveauMotDePasse, ?string $sessionCourante = null, string $champ = 'password'): void
    {
        RegleDuMotDePasse::exiger($nouveauMotDePasse, $compte->identifiantDeConnexion(), $champ);

        $aSignaler = DB::transaction(function () use ($compte, $nouveauMotDePasse, $sessionCourante): bool {
            $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();
            $aSignaler = $relu->signaler_le_prochain_mot_de_passe;

            $relu->forceFill([
                'password' => $nouveauMotDePasse,
                'mot_de_passe_est_l_identifiant' => false,
                'signaler_le_prochain_mot_de_passe' => false,
            ])->save();
            $this->sessions->fermer($relu, $sessionCourante);

            return $aSignaler;
        });

        $compte->refresh();

        if ($aSignaler) {
            $societe = $compte->societe()->firstOrFail();
            $societe->proprietaire?->notify(new AvisDeLEquipe($societe, 'Mot de passe choisi', "{$compte->name} a choisi son propre mot de passe après l'accès qui lui a été redonné."));
        }
    }
}
