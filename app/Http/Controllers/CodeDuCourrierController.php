<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Filament\Societe\Pages\TableauDeBord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * La page du code du courrier recommandé de la vérification, ouverte à
 * tous, sans être connecté : le QR code du courrier l'ouvre avec le SIREN
 * déjà rempli (/code/{siren}), et son adresse courte se tape (/code). On y
 * saisit le SIREN, le code, une adresse e-mail et un mot de passe (voir
 * SaisirCodeDeConfirmationAction, qui limite les saisies et valide la
 * société) : la personne qui a inscrit la société redonne les siens ; une
 * autre personne choisit les siens et devient propriétaire. Le bon code
 * connecte le propriétaire et le mène au tableau de bord de sa société ;
 * une saisie refusée revient sur la page avec son motif, le SIREN et
 * l'adresse gardés, jamais le code ni le mot de passe. La page ne
 * transmet pas son adresse aux liens qui en partent (Referrer-Policy:
 * no-referrer).
 */
class CodeDuCourrierController
{
    public function afficher(?string $siren = null): Response
    {
        return response()
            ->view('code.saisir', ['siren' => old('siren', $siren)])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function saisir(Request $requete, SaisirCodeDeConfirmationAction $saisir): RedirectResponse
    {
        try {
            $donnees = Validator::make($requete->all(), [
                'siren' => ['required', 'string', 'max:20'],
                'code' => ['required', 'string', 'max:30'],
                'email' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'max:255'],
                'password_confirmation' => ['nullable', 'string', 'max:255'],
            ], attributes: ['siren' => 'SIREN', 'code' => 'code', 'email' => 'adresse e-mail', 'password' => 'mot de passe'])->validate();

            $proprietaire = $saisir->handle(
                (string) preg_replace('/\s+/', '', $donnees['siren']),
                $donnees['code'],
                $donnees['email'],
                $donnees['password'],
                (string) ($donnees['password_confirmation'] ?? ''),
                (string) $requete->ip(),
            );
        } catch (ValidationException $refus) {
            return redirect()->route('code.afficher')
                ->withErrors($refus->errors())
                ->withInput($requete->only('siren', 'email'))
                ->header('Referrer-Policy', 'no-referrer');
        }

        Auth::login($proprietaire);
        $requete->session()->regenerate();

        return redirect()->to(TableauDeBord::getUrl(panel: 'societe', tenant: $proprietaire->societe))
            ->header('Referrer-Policy', 'no-referrer');
    }
}
