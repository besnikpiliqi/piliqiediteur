<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Filament\Societe\Pages\TableauDeBord;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Les pages du code du courrier recommandé, ouvertes à tous, sans être
 * connecté : le QR code du courrier ouvre celle de son motif avec le SIREN
 * déjà rempli, et son adresse courte se tape (voir CourrierDeCode).
 *
 * - Le courrier de la vérification d'une société (/code, /code/{siren}) :
 *   on y saisit le SIREN, le code, une adresse e-mail et un mot de passe
 *   (voir SaisirCodeDeConfirmationAction::handle(), qui limite les saisies
 *   et valide la société) ; la personne qui a inscrit la société redonne
 *   les siens ; une autre personne choisit les siens et devient
 *   propriétaire. Le bon code connecte le propriétaire et le mène au
 *   tableau de bord de sa société ; une autre personne y lit que le lien
 *   de l'adresse qu'elle a choisie vient de partir, et qu'elle se connecte
 *   d'ici là avec son identifiant, qui lui est montré.
 * - Le courrier d'une procédure de l'éditeur (/code/{objet},
 *   /code/{objet}/{siren}) : on y saisit le SIREN et le code (voir
 *   SaisirCodeDeConfirmationAction::saisirUnCodeDeProcedure()) ; le bon
 *   code revient sur la page, qui dit que l'éditeur poursuit la procédure,
 *   et ne connecte personne.
 * Une saisie refusée revient sur la page avec son motif, le SIREN et
 * l'adresse gardés, jamais le code ni le mot de passe. Les pages ne
 * transmettent pas leur adresse aux liens qui en partent (Referrer-Policy:
 * no-referrer).
 */
class CodeDuCourrierController
{
    private const NOMS_DES_CHAMPS = ['siren' => 'SIREN', 'code' => 'code', 'email' => 'adresse e-mail', 'password' => 'mot de passe'];

    public function afficher(?string $siren = null): Response
    {
        return $this->page(MotifCode::Verification, $siren);
    }

    public function afficherProcedure(string $objet, ?string $siren = null): Response
    {
        return $this->page(MotifCode::from($objet), $siren);
    }

    public function saisir(Request $requete, SaisirCodeDeConfirmationAction $saisir): RedirectResponse
    {
        try {
            $donnees = Validator::make($requete->all(), [
                ...self::reglesDuCode(),
                'email' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'max:255'],
                'password_confirmation' => ['nullable', 'string', 'max:255'],
            ], attributes: self::NOMS_DES_CHAMPS)->validate();

            $proprietaire = $saisir->handle(
                self::siren($donnees['siren']),
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

        if (! $proprietaire->aUneAdresseDeConnexion()) {
            Notification::make()
                ->title('Confirmez votre adresse de connexion')
                ->body("Un lien valable 24 heures vient d'être envoyé à {$proprietaire->nouvelle_adresse} : suivez-le en étant connecté à ce compte pour en faire votre adresse de connexion. D'ici là, connectez-vous avec votre identifiant de connexion, {$proprietaire->identifiantDeConnexion()}, que montre aussi votre profil, et votre mot de passe.")
                ->warning()
                ->persistent()
                ->send();
        }

        return redirect()->to(TableauDeBord::getUrl(panel: 'societe', tenant: $proprietaire->societe))
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function saisirProcedure(Request $requete, string $objet, SaisirCodeDeConfirmationAction $saisir): RedirectResponse
    {
        $motif = MotifCode::from($objet);

        try {
            $donnees = Validator::make($requete->all(), self::reglesDuCode(), attributes: self::NOMS_DES_CHAMPS)->validate();
            $saisir->saisirUnCodeDeProcedure(self::siren($donnees['siren']), $motif, $donnees['code'], (string) $requete->ip());
        } catch (ValidationException $refus) {
            return redirect()->route('code.procedure.afficher', ['objet' => $motif->value])
                ->withErrors($refus->errors())
                ->withInput($requete->only('siren'))
                ->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->route('code.procedure.afficher', ['objet' => $motif->value])
            ->with('code_reconnu', true)
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function reglesDuCode(): array
    {
        return [
            'siren' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:30'],
        ];
    }

    private static function siren(string $saisie): string
    {
        return (string) preg_replace('/\s+/', '', $saisie);
    }

    private function page(MotifCode $motif, ?string $siren): Response
    {
        return response()
            ->view('code.saisir', ['motif' => $motif, 'siren' => old('siren', $siren)])
            ->header('Referrer-Policy', 'no-referrer');
    }
}
