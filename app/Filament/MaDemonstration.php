<?php

namespace App\Filament;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\LienTire;
use App\Domain\Demonstration\Actions\RecommencerLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Mail\LienDeLaDemonstrationMail;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use App\Http\Middleware\AjouterLesEnTetesDeSecurite;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La carte « Ma démonstration » (lot DEMO-COPIE, B.2), sur le tableau de
 * bord de l'espace de la société et sur celui de l'espace du cabinet, pour
 * le seul propriétaire, sur le site réel : son état et ses phrases (voir
 * CarteDeLaDemonstration), et ses gestes, « Créer ma démonstration »,
 * « Ouvrir ma démonstration » (un formulaire qui part, dans un nouvel
 * onglet, vers la page du billet, voir OuvrirMaDemonstrationController),
 * « Renouveler le lien » et « Recommencer à zéro ».
 *
 * Le lien tiré à la création ou au renouvellement n'est jamais placé dans
 * une propriété du composant (son instantané reviendrait avec chaque
 * requête suivante), ni dans la session, ni dans un message flash : il
 * part dans la seule réponse du geste, comme un événement du navigateur
 * que la fenêtre du lien reçoit et oublie à sa fermeture, et cette réponse
 * porte Cache-Control: no-store. Une demande répétée après une réponse
 * perdue ne le rend jamais : la fenêtre dit qu'il est parti par e-mail.
 */
final class MaDemonstration extends Component implements HasActions, HasForms
{
    use GereLesExceptionsMetier, InteractsWithActions, InteractsWithForms, PorteUnJeton;

    public const NOM = 'ma-demonstration';

    /**
     * L'événement du navigateur qui porte le lien à la fenêtre qui
     * l'affiche une seule fois.
     */
    public const LIEN_TIRE = 'piliqi-lien-de-la-demonstration';

    public const PHRASE_DU_LIEN = 'Ce lien vous a aussi été envoyé par e-mail. Il ne s\'affichera plus : gardez-le.';

    public const PHRASE_LIEN_PARTI = 'Le lien vous a été envoyé par e-mail. S\'il est perdu, renouvelez-le.';

    public const TEXTE_DU_RENOUVELLEMENT = 'Le lien n\'est gardé nulle part : PILIQI en tire un nouveau, l\'affiche ici et vous l\'envoie par e-mail. L\'ancien cessera de marcher dès que la démonstration l\'aura reçu, en général en quelques secondes, sur tous les appareils, pour tous ceux à qui vous l\'aviez donné, et toutes les visites ouvertes se fermeront, la vôtre comprise : rouvrez-la par « Ouvrir ma démonstration ». Votre démonstration et ce que vous y avez fait restent.';

    public const TEXTE_DE_LA_REMISE_A_ZERO = 'Votre démonstration repart de la société fictive de départ ; le lien reste le même ; la date de fin ne bouge pas.';

    /**
     * La société du tableau de bord, que le navigateur ne change pas.
     */
    #[Locked]
    public Societe $societe;

    /**
     * L'adresse du profil, où se redemande le lien de confirmation de
     * l'adresse de connexion.
     */
    #[Locked]
    public string $profil = '';

    public function mount(Societe $societe): void
    {
        $this->societe = $societe;
        $this->profil = (string) Filament::getProfileUrl();
    }

    public function creerAction(): Action
    {
        return Action::make('creer')
            ->label('Créer ma démonstration')
            ->modalHeading('Créer ma démonstration')
            ->modalDescription(fn (): string => 'Votre démonstration est la copie d\'une société fictive de votre activité, que vous et votre équipe pouvez essayer pendant 30 jours ; elle est ensuite supprimée. '.CarteDeLaDemonstration::rienNEntre($this->laSociete()->type_activite).' '.LienDeLaDemonstrationMail::PHRASE_DU_SECRET)
            ->modalSubmitActionLabel('Créer ma démonstration')
            ->form([self::champDuJeton()])
            ->action(function (array $data): void {
                static::executerSansCasser(function () use ($data): void {
                    $this->montrerLeLien(app(DemanderUneDemonstrationAction::class)->handle(CompteConnecte::exiger(), ValeurSimple::enTexte($data['jeton']), request()->ip()));
                });
            });
    }

    public function renouvelerAction(): Action
    {
        return Action::make('renouveler')
            ->label('Renouveler le lien')
            ->color('gray')
            ->modalHeading('Renouveler le lien')
            ->modalDescription(self::TEXTE_DU_RENOUVELLEMENT)
            ->modalSubmitActionLabel('Renouveler le lien')
            ->form([
                self::champDuJeton(),
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->autocomplete('current-password')->required(),
            ])
            ->action(function (array $data): void {
                static::executerSansCasser(function () use ($data): void {
                    $this->montrerLeLien(app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle(CompteConnecte::exiger(), ValeurSimple::enTexte($data['mot_de_passe']), ValeurSimple::enTexte($data['jeton'])));
                });
            });
    }

    public function recommencerAction(): Action
    {
        return Action::make('recommencer')
            ->label('Recommencer à zéro')
            ->color('gray')
            ->modalHeading('Recommencer à zéro')
            ->modalDescription(self::TEXTE_DE_LA_REMISE_A_ZERO)
            ->modalSubmitActionLabel('Recommencer à zéro')
            ->form([self::champDuJeton()])
            ->action(function (array $data): void {
                static::executerSansCasser(function () use ($data): void {
                    app(RecommencerLaDemonstrationAction::class)->handle(CompteConnecte::exiger(), ValeurSimple::enTexte($data['jeton']));
                    Notification::make()->title('Votre démonstration repart de zéro : elle se prépare.')->success()->send();
                });
            });
    }

    public function render(): View
    {
        return view('filament.ma-demonstration', [
            'carte' => app(CarteDeLaDemonstration::class)->pour(auth()->user() instanceof User ? auth()->user() : null, $this->laSociete()),
            'phraseDuLien' => self::PHRASE_DU_LIEN,
            'phraseDuSecret' => LienDeLaDemonstrationMail::PHRASE_DU_SECRET,
        ]);
    }

    /**
     * Le lien tiré part dans la seule réponse de ce geste, sans cache ;
     * une demande répétée, qui ne le rend pas, le dit.
     */
    private function montrerLeLien(LienTire $resultat): void
    {
        request()->attributes->set(AjouterLesEnTetesDeSecurite::SANS_CACHE, true);

        if ($resultat->lien === null) {
            Notification::make()->title(self::PHRASE_LIEN_PARTI)->info()->send();

            return;
        }

        $this->dispatch(self::LIEN_TIRE, lien: DemanderUneDemonstrationAction::adresseDuLien($resultat->lien));
    }

    private function laSociete(): Societe
    {
        return $this->societe;
    }
}
