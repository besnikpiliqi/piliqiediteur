<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Le lien envoyé à une adresse de connexion en attente (voir
 * DemanderUneNouvelleAdresseAction). L'ouvrir (GET) n'affiche qu'un bouton
 * et ne change rien : un outil qui suit les liens d'un e-mail ne confirme
 * rien. Le bouton (POST) confirme (voir ConfirmerNouvelleAdresseAction) ;
 * un lien expiré, déjà servi, remplacé ou inconnu dit seulement « Ce lien
 * n'est plus valable. ». La première adresse d'un propriétaire qui n'en a
 * encore aucune ne se confirme que connecté à ce compte : ailleurs, la page
 * le dit, sans bouton, et mène à la connexion, qui ramène ici (voir
 * ReponseDeConnexion). Le compte se cherche par l'empreinte du jeton,
 * jamais par le jeton ; les pages ne transmettent pas leur adresse aux
 * liens qui en partent (Referrer-Policy: no-referrer). Aucune session
 * n'est ouverte.
 */
class ConfirmerNouvelleAdresseController
{
    public function afficher(Request $requete, string $jeton): Response
    {
        $compte = ConfirmerNouvelleAdresseAction::compteEnAttente($jeton);

        if ($compte !== null && ! ConfirmerNouvelleAdresseAction::peutConfirmerDepuis($compte, Auth::user())) {
            return $this->pageDeConnexion($requete, $jeton, erreur: false);
        }

        return $this->page($jeton, $compte?->nouvelle_adresse, null, premiereAdresse: $compte !== null && ! $compte->aUneAdresseDeConnexion());
    }

    public function confirmer(Request $requete, string $jeton, ConfirmerNouvelleAdresseAction $confirmer): Response
    {
        try {
            $compte = $confirmer->handle($jeton, Auth::user());
        } catch (AuthorizationException|ValidationException $refus) {
            $message = $refus instanceof ValidationException ? collect($refus->errors())->flatten()->first() : $refus->getMessage();

            return $message === ConfirmerNouvelleAdresseAction::MESSAGE_CONNEXION_REQUISE
                ? $this->pageDeConnexion($requete, $jeton, erreur: true)
                : $this->page($jeton, null, $message, erreur: true);
        }

        return $this->page($jeton, null, "Votre adresse de connexion est désormais {$compte->email} : connectez-vous avec elle.");
    }

    /**
     * La page d'un lien qui se suit connecté au compte : elle le dit, sans
     * bouton, et la connexion ramène à ce lien.
     */
    private function pageDeConnexion(Request $requete, string $jeton, bool $erreur): Response
    {
        $requete->session()->put('url.intended', route('profil.adresse.afficher', ['jeton' => $jeton]));

        return $this->page($jeton, null, ConfirmerNouvelleAdresseAction::MESSAGE_CONNEXION_REQUISE, erreur: $erreur, connexion: true);
    }

    private function page(string $jeton, ?string $nouvelleAdresse, ?string $message, bool $erreur = false, bool $connexion = false, bool $premiereAdresse = false): Response
    {
        return response()
            ->view('profil.adresse', [
                'nouvelleAdresse' => $nouvelleAdresse,
                'premiereAdresse' => $premiereAdresse,
                'message' => $message,
                'erreur' => $erreur,
                'connexion' => $connexion ? Filament::getPanel('app')->getLoginUrl() : null,
                'action' => route('profil.adresse.confirmer', ['jeton' => $jeton]),
            ], $nouvelleAdresse === null && $message === null ? 410 : 200)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
