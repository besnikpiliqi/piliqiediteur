<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\CorrigerSirenAvantValidationAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * L'identité légale de la société, celle que tous ses magasins impriment :
 * raison sociale, forme juridique, capital, immatriculation, n° de TVA et
 * siège (voir ModifierIdentiteSocieteAction, qui la valide et
 * l'enregistre). Le SIREN s'y lit sans se saisir ; le propriétaire le
 * corrige, tant que la société n'est pas validée, par « Corriger le SIREN »
 * (voir CorrigerSirenAvantValidationAction). Un encadré dit les mentions
 * qui manquent pour facturer un professionnel, et la raison sociale
 * rappelle la dénomination que donne le répertoire Sirene quand elle en
 * diffère.
 *
 * Consultable par qui voit la société (voir SocietePolicy::view()) ; le
 * formulaire ne s'enregistre que pour qui en modifie les paramètres, tant
 * qu'elle n'est pas en lecture seule.
 */
class IdentiteSociete extends Page implements HasForms
{
    use GereLesExceptionsMetier, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Identité';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Identité de la société';
    }

    public function mount(): void
    {
        $this->remplir();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identité incomplète pour facturer un professionnel')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->schema([
                    Placeholder::make('mentions_manquantes')
                        ->hiddenLabel()
                        ->content(fn (): string => 'Vos tickets de caisse restent possibles, mais aucune facture à un professionnel ne peut être émise, dans aucun de vos magasins, tant que ces mentions manquent : '.implode(', ', $this->societe()->mentionsLegalesManquantes()).'.'),
                ])
                ->visible(fn (): bool => $this->societe()->mentionsLegalesManquantes() !== []),
            Section::make('Identité légale')
                ->description('Imprimée en tête des devis, factures et avoirs de tous vos magasins.')
                ->schema([
                    Placeholder::make('siren')
                        ->label('SIREN')
                        ->content(fn (): string => (string) $this->societe()->siren),
                    TextInput::make('raison_sociale')
                        ->label('Raison sociale')
                        ->helperText(fn (): ?string => $this->ecartAvecSirene())
                        ->maxLength(200),
                    Select::make('forme_juridique')
                        ->label('Forme juridique')
                        ->options(FormeJuridique::class)
                        ->live(),
                    TextInput::make('forme_juridique_autre')
                        ->label('Libellé exact de la forme')
                        ->helperText('Pour une variante (SELARL, SA coopérative...) ou une autre forme : le libellé imprimé sur vos documents.')
                        ->maxLength(100)
                        ->hidden(fn (Get $get): bool => $get('forme_juridique') === FormeJuridique::EntrepreneurIndividuel->value),
                    Radio::make('societe_commerciale')
                        ->label('Est-ce une société commerciale ?')
                        ->boolean()
                        ->visible(fn (Get $get): bool => $get('forme_juridique') === FormeJuridique::Autre->value),
                    TextInput::make('capital_social')
                        ->label('Capital social')
                        ->helperText('Obligatoire pour toute société commerciale ; imprimé sur vos documents quand il est renseigné.')
                        ->numeric()
                        ->suffix('€')
                        ->hidden(fn (Get $get): bool => $get('forme_juridique') === FormeJuridique::EntrepreneurIndividuel->value),
                    Toggle::make('capital_variable')
                        ->label('Capital variable')
                        ->hidden(fn (Get $get): bool => $get('forme_juridique') === FormeJuridique::EntrepreneurIndividuel->value),
                    TextInput::make('immatriculation')
                        ->label('Immatriculation')
                        ->placeholder('RCS Lyon')
                        ->helperText('RCS suivi de la ville du greffe (ex. RCS Lyon), RM suivi du département pour un artisan (ex. RM 69), ou la mention de la dispense qui s\'applique.')
                        ->maxLength(100),
                    // Saisi avec ses séparateurs s'il le faut : il s'enregistre
                    // sans eux (voir NumeroTvaNormalise), comme celui d'un
                    // client ou d'un fournisseur.
                    TextInput::make('numero_tva')
                        ->label('N° TVA intracommunautaire')
                        ->maxLength(20),
                ])
                ->columns(2),
            Section::make(fn (Get $get): string => $get('forme_juridique') === FormeJuridique::EntrepreneurIndividuel->value ? 'Adresse de l\'entreprise' : 'Siège social')
                ->schema([
                    TextInput::make('siege_adresse')->label('Adresse')->maxLength(255)->columnSpanFull(),
                    TextInput::make('siege_complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull(),
                    TextInput::make('siege_code_postal')->label('Code postal')->maxLength(10),
                    TextInput::make('siege_ville')->label('Ville')->maxLength(100),
                ])
                ->columns(2),
        ])
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->peutModifier());
    }

    /**
     * @throws ValidationException
     */
    public function save(): void
    {
        $donnees = $this->form->getState();

        static::executerSansCasser(function () use ($donnees): void {
            try {
                app(ModifierIdentiteSocieteAction::class)->handle(auth()->user(), $this->societe(), $donnees);
            } catch (ValidationException $refus) {
                throw ValidationException::withMessages(collect($refus->errors())->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])->all());
            }

            $this->remplir();

            Notification::make()->title('Identité de la société enregistrée')->success()->send();
        });
    }

    /**
     * « Corriger le SIREN », pour le propriétaire tant que la société n'est
     * pas validée : le nouveau SIREN, et le NIC de chaque magasin, dont le
     * SIRET change avec lui.
     */
    public function corrigerLeSirenAction(): Action
    {
        return Action::make('corrigerLeSiren')
            ->label('Corriger le SIREN')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => auth()->user()?->can('corrigerLeSiren', $this->societe()) ?? false)
            ->modalDescription('Le SIREN se corrige tant que votre société n\'est pas validée. Le SIRET de chaque magasin commence par le SIREN : ressaisissez le NIC de chacun.')
            ->form(fn (): array => [
                TextInput::make('siren')->label('SIREN corrigé')->required()->maxLength(9),
                ...$this->societe()->magasins()->orderBy('id')->get()->map(fn (Magasin $magasin): TextInput => TextInput::make("nics.{$magasin->getKey()}")
                    ->label("NIC de {$magasin->nom}")
                    ->required()
                    ->maxLength(5))->all(),
            ])
            ->action(function (array $data): void {
                static::executerSansCasser(function () use ($data): void {
                    try {
                        app(CorrigerSirenAvantValidationAction::class)->handle(auth()->user(), $this->societe(), (string) $data['siren'], $data['nics'] ?? []);
                    } catch (ValidationException $refus) {
                        Notification::make()->title(collect($refus->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $this->remplir();

                    Notification::make()->title('SIREN corrigé')->success()->send();
                });
            });
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->corrigerLeSirenAction()];
    }

    private function peutModifier(): bool
    {
        return auth()->user()?->can('modifierLesParametres', $this->societe()) ?? false;
    }

    /**
     * La dénomination que donne le répertoire Sirene (voir
     * Societe::denominationSirene()), quand elle diffère de la raison
     * sociale enregistrée.
     */
    private function ecartAvecSirene(): ?string
    {
        $societe = $this->societe();
        $denomination = $societe->denominationSirene();

        return filled($denomination) && $denomination !== $societe->raison_sociale
            ? "Le répertoire Sirene de l'INSEE indique « {$denomination} »."
            : null;
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
            ...$societe->only(ModifierIdentiteSocieteAction::CHAMPS),
            'forme_juridique' => $societe->forme_juridique?->value,
        ]);
    }

    private function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }
}
