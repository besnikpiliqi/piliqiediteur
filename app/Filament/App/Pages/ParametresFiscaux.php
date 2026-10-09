<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Seul endroit où le régime de TVA du magasin se configure : en dépend
 * directement la cadence de transmission de l'e-reporting (voir
 * CalculateurPeriodeEReporting et la commande planifiée
 * app:soumettre-ereporting-periodique). Réservé à qui a la permission
 * mandat_pdp.gerer (le propriétaire par défaut, voir
 * SeedMagasinRolesAction) : la configuration fiscale du magasin.
 *
 * Le régime de TVA et l'option pour les débits ont un impact sur la
 * conformité : chaque changement s'inscrit au journal des événements du
 * magasin, avec l'ancienne et la nouvelle valeur (voir
 * JournaliseSesParametresDeConformite). Quand le registre fiscal ne peut
 * pas l'inscrire, rien n'est enregistré et l'écran dit pourquoi.
 */
class ParametresFiscaux extends Page implements HasForms
{
    use GereLesExceptionsMetier, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Paramètres fiscaux';

    protected static ?string $title = 'Paramètres fiscaux';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionRegistry::MANDAT_PDP_GERER) ?? false;
    }

    public function mount(): void
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        $this->form->fill([
            'regime_tva' => $magasin->regime_tva?->value,
            'option_debits_tva' => $magasin->option_debits_tva,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('regime_tva')
                ->label('Régime de TVA')
                ->options(RegimeTva::class)
                ->helperText('Détermine la cadence de transmission de l\'e-reporting au PDP (voir la page Soumissions e-reporting) : ce n\'est pas une obligation journalière, la fréquence dépend de ce régime.')
                ->required(),
            Toggle::make('option_debits_tva')
                ->label('Option pour les débits')
                ->helperText('Ne concerne que les prestations de services normalement soumises à la TVA sur les encaissements. Activée, la mention « Option pour le paiement de la taxe d\'après les débits » est imprimée sur vos factures et avoirs. Laissez désactivé si vous ne savez pas, ou si votre activité est principalement de la vente de marchandises.'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        static::executerSansCasser(function () use ($magasin, $data): void {
            $magasin->update([
                'regime_tva' => $data['regime_tva'],
                'option_debits_tva' => $data['option_debits_tva'],
            ]);

            Notification::make()
                ->title('Paramètres fiscaux enregistrés')
                ->success()
                ->send();
        });
    }
}
