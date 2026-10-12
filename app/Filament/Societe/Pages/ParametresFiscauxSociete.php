<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Seul endroit où se règlent le régime de TVA de la société, l'option pour
 * les débits et le mois de fin de son exercice, communs à tous ses
 * magasins (voir ModifierParametresFiscauxSocieteAction, qui les
 * enregistre sous le verrou des registres de tous les magasins et
 * inscrit chaque changement au journal des événements de chacun). Le mois
 * de fin d'exercice ne se modifie plus dès la première vente de l'un des
 * magasins.
 *
 * Consultable par le propriétaire et un administrateur de tous les
 * magasins autorisé à gérer les paramètres de la société (voir
 * SocietePolicy::voirLesParametres()) ; le formulaire ne s'enregistre que
 * tant qu'elle n'est pas en lecture seule.
 */
class ParametresFiscauxSociete extends Page implements HasForms
{
    use GereLesExceptionsMetier, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Paramètres fiscaux';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('voirLesParametres', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Paramètres fiscaux de la société';
    }

    public function mount(): void
    {
        $this->remplir();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('regime_tva')
                ->label('Régime de TVA')
                ->options(RegimeTva::class)
                ->helperText('Détermine la cadence de transmission de l\'e-reporting de tous vos magasins : ce n\'est pas une obligation journalière, la fréquence dépend de ce régime. En franchise, vos ventes sont à 0 % avec la mention « '.RegimeTva::MENTION_FRANCHISE.' » ; le prix TTC de vos produits ne change pas.')
                ->required(),
            Toggle::make('option_debits_tva')
                ->label('Option pour les débits')
                ->helperText('Ne concerne que les prestations de services normalement soumises à la TVA sur les encaissements. Activée, la mention « Option pour le paiement de la taxe d\'après les débits » est imprimée sur vos factures et avoirs. Laissez désactivé si vous ne savez pas, ou si votre activité est principalement de la vente de marchandises.'),
            Select::make('mois_fin_exercice')
                ->label('Mois de fin d\'exercice')
                ->options(collect(range(1, 12))->mapWithKeys(fn (int $mois): array => [$mois => ucfirst(Carbon::create(2000, $mois)->translatedFormat('F'))])->all())
                ->helperText(fn (): string => $this->societe()->aVenduUneFois()
                    ? 'Verrouillé depuis la première vente de l\'un de vos magasins.'
                    : 'Décembre par défaut. Il se verrouille dès la première vente de l\'un de vos magasins.')
                ->required()
                ->disabled(fn (): bool => $this->societe()->aVenduUneFois()),
        ])
            ->statePath('data')
            ->disabled(fn (): bool => ! (auth()->user()?->can('modifierLesParametres', $this->societe()) ?? false));
    }

    /**
     * @throws ValidationException
     */
    public function save(): void
    {
        $donnees = $this->form->getState();

        static::executerSansCasser(function () use ($donnees): void {
            try {
                app(ModifierParametresFiscauxSocieteAction::class)->handle(auth()->user(), $this->societe(), $donnees);
            } catch (ValidationException $refus) {
                throw ValidationException::withMessages(collect($refus->errors())->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])->all());
            }

            $this->remplir();

            Notification::make()->title('Paramètres fiscaux enregistrés')->success()->send();
        });
    }

    /**
     * Le formulaire rempli avec la société telle qu'elle est enregistrée :
     * le tenant du panneau se relit d'abord, pour que l'écran rendu après
     * un enregistrement (encadré, rappels) dise ce qui vient de changer.
     */
    private function remplir(): void
    {
        $societe = $this->societe()->refresh();

        $this->form->fill([
            'regime_tva' => $societe->regime_tva?->value,
            'option_debits_tva' => $societe->option_debits_tva,
            'mois_fin_exercice' => $societe->mois_fin_exercice,
        ]);
    }

    private function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }
}
