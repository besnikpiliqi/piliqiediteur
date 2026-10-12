<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\AccepterUnRattachementAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\DemandeRattachement;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Le lien qu'une personne reçoit à l'adresse de son compte quand une
 * société lui propose de rejoindre son équipe (voir
 * ProposerUnRattachementAction et AccepterUnRattachementAction) : l'ouvrir
 * (GET) n'affiche que la proposition et ne change rien ; « Accepter »
 * (POST) redemande l'adresse et le mot de passe du compte, rattache le
 * compte et connecte la personne ; « Refuser » (POST) refuse. La demande
 * se cherche par l'empreinte du jeton, jamais par le jeton, et un lien
 * expiré, déjà servi ou inconnu dit seulement « Ce lien n'est plus
 * valable. ». Les pages ne transmettent pas leur adresse aux liens qui en
 * partent (Referrer-Policy: no-referrer).
 */
class RattachementController
{
    public function afficher(string $jeton): Response
    {
        return $this->page($jeton, AccepterUnRattachementAction::demandeEnAttente($jeton), null);
    }

    public function accepter(string $jeton, Request $requete, AccepterUnRattachementAction $accepter): Response|RedirectResponse
    {
        $donnees = $requete->validate(['email' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);

        try {
            $compte = $accepter->handle($jeton, $donnees['email'], $donnees['password']);
        } catch (AuthorizationException $refus) {
            return $this->page($jeton, AccepterUnRattachementAction::demandeEnAttente($jeton), $refus->getMessage(), erreur: true);
        }

        Auth::login($compte);
        $requete->session()->regenerate();

        return redirect()->route('filament.app.tenant')->header('Referrer-Policy', 'no-referrer');
    }

    public function refuser(string $jeton, AccepterUnRattachementAction $accepter): Response
    {
        try {
            $accepter->refuser($jeton);
        } catch (AuthorizationException $refus) {
            return $this->page($jeton, null, $refus->getMessage(), erreur: true);
        }

        return $this->page($jeton, null, 'Votre refus est enregistré : la société en est prévenue.');
    }

    private function page(string $jeton, ?DemandeRattachement $demande, ?string $message, bool $erreur = false): Response
    {
        return response()
            ->view('rattachement.afficher', [
                'demande' => $demande,
                'role' => $demande === null ? null : self::role($demande),
                'message' => $message,
                'erreur' => $erreur,
                'jeton' => $jeton,
            ], $demande === null && $message === null ? 410 : 200)
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Le rôle proposé, tel que la page le dit : les magasins proposés se
     * lisent dans la société qui propose, par leurs identifiants.
     */
    private static function role(DemandeRattachement $demande): string
    {
        if ($demande->role_societe === RoleSociete::ExpertComptable) {
            return 'comme expert-comptable, avec la lecture de tous ses magasins';
        }

        $magasins = Magasin::query()->where('societe_id', $demande->societe_id)->whereKey(array_keys($demande->affectationsProposees()))->pluck('nom', 'id');

        return implode(', ', array_map(
            fn (int $magasinId, array $affectation): string => mb_strtolower($affectation['role']->getLabel()).' du magasin '.$magasins->get($magasinId),
            array_keys($demande->affectationsProposees()),
            $demande->affectationsProposees(),
        ));
    }
}
