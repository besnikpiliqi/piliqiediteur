<?php

namespace App\Http\Controllers;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Exceptions\LienDeLExpertComptableInvalideException;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurSimple;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * La page du lien de 7 jours où un expert-comptable de la société choisit
 * son mot de passe (voir ChoisirLeMotDePasseDeLExpertComptableAction),
 * avant qu'aucune société ne soit ouverte. L'ouvrir n'écrit rien et ne
 * montre ni l'identifiant, ni l'adresse, ni le nom du propriétaire : la
 * dénomination et le SIREN de la société, le nom de la personne, le cabinet
 * déclaré et les libellés des autorisations de la déclaration, tirés des
 * clés enregistrées sans lire le type de la société. Choisir son mot de
 * passe consomme le lien, ferme d'abord la session d'un autre compte
 * ouverte dans ce navigateur (sa fin s'inscrit au journal des événements),
 * connecte la personne et lui montre son identifiant. Un lien qui ne vaut
 * plus rend une page neutre (410). Chaque réponse porte
 * Referrer-Policy: no-referrer : l'adresse du lien ne part vers aucun
 * site.
 */
class LienDeLExpertComptableController
{
    public function __construct(
        private readonly ChoisirLeMotDePasseDeLExpertComptableAction $choisir,
        private readonly ExpertsComptablesDeclares $experts,
    ) {}

    public function afficher(#[SensitiveParameter] string $jeton): Response
    {
        return $this->page($jeton, []);
    }

    public function choisir(Request $requete, #[SensitiveParameter] string $jeton): Response|RedirectResponse
    {
        try {
            $compte = $this->choisir->handle($jeton, ValeurSimple::enTexte($requete->input('password')), ValeurSimple::enTexte($requete->input('password_confirmation')));
        } catch (LienDeLExpertComptableInvalideException) {
            return self::pageNeutre();
        } catch (ValidationException $refus) {
            return $this->page($jeton, array_values(array_merge(...array_values($refus->errors()))));
        }

        if (Auth::check() && Auth::id() !== $compte->getKey()) {
            Auth::logout();
            $requete->session()->invalidate();
        }

        Auth::login($compte);
        $requete->session()->regenerate();
        app(SuiviDesConnexions::class)->noterLaConnexion($compte);

        Notification::make()
            ->title('Votre mot de passe est choisi')
            ->body("Votre identifiant de connexion est {$compte->identifiantDeConnexion()} : notez-le. Vous vous connecterez avec lui et votre mot de passe ; votre adresse ne sert pas à vous connecter.")
            ->success()
            ->persistent()
            ->send();

        return redirect()->to(Filament::getPanel('app')->getUrl())->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @param  list<string>  $erreurs
     */
    private function page(#[SensitiveParameter] string $jeton, array $erreurs): Response
    {
        try {
            [, $compte, $societe] = $this->choisir->lienValable($jeton);
        } catch (LienDeLExpertComptableInvalideException) {
            return self::pageNeutre();
        }

        $declaration = app(TenantContext::class)->runAsSociete($societe, fn () => $this->experts->declarationDe($compte));

        return response()->view('cabinets.lien-expert-comptable', [
            'nom' => $compte->name,
            'societe' => $societe->raison_sociale ?? 'La société',
            'siren' => (string) $societe->siren,
            'cabinet' => $declaration?->nomDuCabinetDeclare() ?? '',
            'sirenDuCabinet' => (string) $declaration?->siren_cabinet,
            'autorisations' => PermissionRegistry::libellesDe(app(TenantContext::class)->runAsSociete($societe, fn (): array => $declaration?->autorisations() ?? [])),
            'erreurs' => $erreurs,
            'action' => route('experts-comptables.lien.choisir', ['jeton' => $jeton]),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    private static function pageNeutre(): Response
    {
        return response()->view('cabinets.lien-expert-comptable-invalide', ['message' => LienDeLExpertComptableInvalideException::MESSAGE], 410)->header('Referrer-Policy', 'no-referrer');
    }
}
