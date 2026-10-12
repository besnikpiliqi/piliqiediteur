<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le tableau de bord de la société : où en est sa vérification, ce qui
 * manque à son identité pour facturer un professionnel, ses magasins
 * réels de la portée de la personne connectée (tous pour le propriétaire
 * et un administrateur de tous les magasins, ceux de sa sélection pour un
 * autre administrateur), chacun avec son SIRET, son établissement (vérifié
 * ou à vérifier) et son état, et son magasin de démonstration, ouvert ici
 * tant que la société est en cours de vérification (voir
 * OuvrirLaDemonstrationAction). Les magasins se lisent par la portée, dans
 * la société affichée : ceux d'aucune autre société.
 */
class TableauDeBord extends Dashboard
{
    use GereLesExceptionsMetier;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static string $view = 'filament.societe.pages.tableau-de-bord';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->societe()->raison_sociale ?? 'Votre société';
    }

    public function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * L'état de la vérification de la société, tel que l'écran le dit.
     */
    public function etatDeLaVerification(): string
    {
        $societe = $this->societe();

        return match (true) {
            $societe->estRefusee() => 'L\'inscription de votre société a été refusée.',
            $societe->estValidee() => 'Votre société est validée depuis le '.$societe->validee_le->setTimezone(config('app.timezone'))->format('d/m/Y').'.',
            default => 'Votre société est en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation. Vous pouvez déjà configurer vos magasins : catalogue, équipe, paramètres et achats, et vous essayer dans le magasin de démonstration.',
        };
    }

    /**
     * Ses magasins réels de la portée de la personne connectée.
     *
     * @return Collection<int, Magasin>
     */
    public function magasins(): Collection
    {
        return auth()->user()->magasinsDeSaPortee()->where('est_demonstration', false)->orderBy('nom')->orderBy('id')->get();
    }

    /**
     * Le magasin de démonstration, pour qui administre tous les magasins.
     */
    public function demonstration(): ?Magasin
    {
        return auth()->user()->administreTousLesMagasins() ? $this->societe()->demonstration()->first() : null;
    }

    protected function getHeaderActions(): array
    {
        return [$this->ouvrirLaDemonstrationAction()];
    }

    /**
     * Ouvre, ou rouvre, le magasin de démonstration, puis y mène.
     */
    public function ouvrirLaDemonstrationAction(): Action
    {
        return Action::make('ouvrirLaDemonstration')
            ->label(fn (): string => $this->demonstration() === null ? 'Ouvrir le magasin de démonstration' : 'Rouvrir le magasin de démonstration')
            ->icon('heroicon-o-academic-cap')
            ->visible(fn (): bool => (auth()->user()?->can('ouvrirLaDemonstration', $this->societe()) ?? false) && $this->demonstration()?->statut !== StatutMagasin::Actif)
            ->requiresConfirmation()
            ->modalDescription('Un magasin d\'entraînement, avec un catalogue fictif : vous y vendez, encaissez et clôturez comme pour de vrai, mais ses documents sont marqués « Démonstration — sans valeur », il n\'écrit à aucun client et ne transmet rien. Il se ferme à la validation de votre société, et rien n\'en passe à vos magasins réels.')
            ->action(function () {
                $demonstration = null;
                static::executerSansCasser(function () use (&$demonstration): void {
                    $demonstration = app(OuvrirLaDemonstrationAction::class)->handle(auth()->user(), $this->societe());
                });

                return $demonstration === null ? null : redirect()->to(Dashboard::getUrl(panel: 'app', tenant: $demonstration));
            });
    }
}
