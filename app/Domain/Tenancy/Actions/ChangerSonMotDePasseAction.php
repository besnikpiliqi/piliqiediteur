<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * La personne choisit elle-même son mot de passe : dans son profil (voir
 * ModifierProfil), ou à sa première connexion avec son mot de passe
 * provisoire (voir ChoisirSonMotDePasse). Il suit la règle du mot de passe
 * choisi (voir RegleDuMotDePasse) ; sous le verrou de la ligne du compte
 * relue, il remplace l'ancien, qu'il soit provisoire ou choisi, la date du
 * choix s'écrit, ses autres sessions se ferment et son « se souvenir de
 * moi » se renouvelle ; seule la session courante reste. Après un accès
 * redonné par un autre que le propriétaire, le propriétaire est prévenu de
 * ce premier mot de passe choisi (voir RedonnerLAccesAction). Personne
 * d'autre que la personne ne le connaît.
 */
class ChangerSonMotDePasseAction
{
    public function __construct(private readonly SessionsDesComptes $sessions) {}

    /**
     * @throws ValidationException quand le mot de passe ne suit pas la règle, ou est le mot de passe provisoire remis
     */
    public function handle(User $compte, #[SensitiveParameter] string $nouveauMotDePasse, ?string $sessionCourante = null, string $champ = 'password'): void
    {
        RegleDuMotDePasse::exiger($nouveauMotDePasse, $compte->identifiantDeConnexion(), $champ);

        $aSignaler = DB::transaction(function () use ($compte, $nouveauMotDePasse, $sessionCourante, $champ): bool {
            $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();
            MotsDePasseProvisoires::exigerUnAutreQueCeluiQuiAEteRemis($relu, $nouveauMotDePasse, $champ);
            $aSignaler = $relu->signaler_le_prochain_mot_de_passe;

            $relu->forceFill([
                ...MotsDePasseProvisoires::colonnesDuMotDePasseChoisi($nouveauMotDePasse),
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
