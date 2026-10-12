<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Le lien envoyé à la nouvelle adresse de connexion demandée dans le
 * profil (voir DemanderUneNouvelleAdresseAction). L'ouvrir (GET) n'affiche
 * qu'un bouton et ne change rien : un outil qui suit les liens d'un e-mail
 * ne confirme rien. Le bouton (POST) confirme (voir
 * ConfirmerNouvelleAdresseAction) ; un lien expiré, déjà servi, remplacé
 * ou inconnu dit seulement « Ce lien n'est plus valable. ». Le compte se
 * cherche par l'empreinte du jeton, jamais par le jeton ; les pages ne
 * transmettent pas leur adresse aux liens qui en partent
 * (Referrer-Policy: no-referrer). Aucune session n'est ouverte.
 */
class ConfirmerNouvelleAdresseController
{
    public function afficher(string $jeton): Response
    {
        $compte = ConfirmerNouvelleAdresseAction::compteEnAttente($jeton);

        return $this->page($jeton, $compte?->nouvelle_adresse, null);
    }

    public function confirmer(string $jeton, ConfirmerNouvelleAdresseAction $confirmer): Response
    {
        try {
            $compte = $confirmer->handle($jeton);
        } catch (AuthorizationException|ValidationException $refus) {
            return $this->page($jeton, null, $refus instanceof ValidationException ? collect($refus->errors())->flatten()->first() : $refus->getMessage(), erreur: true);
        }

        return $this->page($jeton, null, "Votre adresse de connexion est désormais {$compte->email} : connectez-vous avec elle.");
    }

    private function page(string $jeton, ?string $nouvelleAdresse, ?string $message, bool $erreur = false): Response
    {
        return response()
            ->view('profil.adresse', [
                'nouvelleAdresse' => $nouvelleAdresse,
                'message' => $message,
                'erreur' => $erreur,
                'action' => route('profil.adresse.confirmer', ['jeton' => $jeton]),
            ], $nouvelleAdresse === null && $message === null ? 410 : 200)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
