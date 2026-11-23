<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Concerns\GereLesExceptionsMetier;
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
 * raison sociale, forme juridique, capital, immatriculation, n° de TVA,
 * siège et, dans les types qui ont un magasin, l'assurance professionnelle
 * facultative (voir ModifierIdentiteSocieteAction, qui la valide et
 * l'enregistre, et Emetteur::ligneDeLAssurance()). Le SIREN s'y lit sans
 * se saisir : c'est celui de l'inscription, que vérifie le courrier
 * recommandé, et il ne change pas ; le type d'activité aussi, choisi à
 * l'inscription et définitif (voir TypeDActivite). Un encadré dit les
 * mentions qui manquent pour facturer un professionnel, et la raison
 * sociale rappelle la dénomination que donne le répertoire Sirene quand
 * elle en diffère.
 *
 * Consultable par le propriétaire et un administrateur de tous les
 * magasins autorisé à gérer les paramètres de la société (voir
 * SocietePolicy::voirLesParametres()) ; le formulaire ne s'enregistre que
 * tant qu'elle n'est pas en lecture seule. Un cabinet d'expertise
 * comptable, qui ne facture rien dans le logiciel, n'y lit ni ce qui
 * manque pour facturer, ni l'assurance : sa page, dans l'espace du
 * cabinet, en hérite (voir IdentiteDuCabinet).
 *
 * @property Form $form le formulaire de la page (voir InteractsWithForms)
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

        return $societe instanceof Societe && (auth()->user()?->can('voirLesParametres', $societe) ?? false);
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
                        ->content(fn (): ?string => $this->societe()->avisDIdentiteIncomplete()),
                ])
                ->visible(fn (): bool => $this->societe()->avisDIdentiteIncomplete() !== null),
            Section::make('Identité légale')
                ->description(fn (): ?string => $this->societe()->type_activite->aDesMagasins() ? 'Imprimée dans le pied des devis, factures, tickets et avoirs de tous vos magasins.' : null)
                ->schema([
                    Placeholder::make('siren')
                        ->label('SIREN')
                        ->content(fn (): string => (string) $this->societe()->siren),
                    Placeholder::make('type_activite')
                        ->label('Type d\'activité')
                        ->helperText('Choisi à l\'inscription, il ne change jamais.')
                        ->content(fn (): string => $this->societe()->type_activite->getLabel()),
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
            Section::make('Assurance professionnelle')
                ->description('Facultative. Renseignée, elle s\'imprime dans le pied des devis, factures, tickets et avoirs de tous vos magasins. La loi impose cette mention, sur chaque devis et chaque facture, aux artisans dont l\'activité exige une assurance professionnelle, notamment la garantie décennale des travaux du bâtiment.')
                ->schema([
                    TextInput::make('assurance_assureur')
                        ->label('Assureur ou garant')
                        ->helperText('Obligatoire dès qu\'un autre champ de l\'assurance est renseigné.')
                        ->maxLength(200),
                    TextInput::make('assurance_contrat')
                        ->label('N° de contrat')
                        ->maxLength(100),
                    TextInput::make('assurance_adresse')
                        ->label('Adresse de l\'assureur ou du garant')
                        ->maxLength(300)
                        ->columnSpanFull(),
                    TextInput::make('assurance_couverture')
                        ->label('Couverture géographique')
                        ->placeholder('France métropolitaine')
                        ->maxLength(200)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->hidden(fn (): bool => ! $this->societe()->type_activite->aDesMagasins()),
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
                app(ModifierIdentiteSocieteAction::class)->handle(CompteConnecte::exiger(), $this->societe(), $donnees);
            } catch (ValidationException $refus) {
                throw ValidationException::withMessages(collect($refus->validator->errors()->messages())->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])->all());
            }

            $this->remplir();

            Notification::make()->title('Identité de la société enregistrée')->success()->send();
        });
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
        return ValeursLues::instance(Filament::getTenant(), Societe::class);
    }
}
