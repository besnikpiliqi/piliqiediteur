<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Filament\Concerns\EnregistreLesParametresDuMagasin;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Les relances automatiques des factures impayées (voir
 * RelancerFacturesImpayees) : désactivées au départ, avec des délais
 * d'exemple que le magasin modifie, complète ou réduit comme le barème de
 * prix de vente (voir ParametresPrixDeVente). Seul ce que la personne y a
 * corrigé s'enregistre (voir EnregistreLesParametresDuMagasin).
 */
class ParametresRelances extends Page implements HasForms
{
    use EnregistreLesParametresDuMagasin, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Relances';

    protected static ?string $title = 'Relances des factures impayées';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionRegistry::RELANCES_REGLER) ?? false;
    }

    public function mount(): void
    {
        $this->remplir();
    }

    /**
     * Les relances telles qu'elles sont enregistrées, relues en base.
     */
    private function remplir(): void
    {
        $magasin = $this->lireLesParametres();

        $this->form->fill(['actif' => $magasin->relances_automatiques, 'delais' => $magasin->delais_relance]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Toggle::make('actif')
                ->label('Relancer automatiquement les factures impayées')
                ->helperText('Chaque matin, le client d\'une facture échue qui n\'est pas entièrement réglée reçoit par e-mail, avec la facture, un rappel de ce qu\'il reste à payer : une relance à chacun des délais ci-dessous, dans l\'ordre, jamais deux fois la même. Une facture déjà très en retard reçoit d\'abord la relance n°1, puis les suivantes en respectant l\'écart entre vos délais. Un client sans adresse e-mail n\'est pas relancé. Désactivées, vos relances gardent leurs délais pour le jour où vous les réactivez.')
                ->live()
                // Désactiver remet les délais que la page a lus : une saisie
                // laissée en cours ne reste pas affichée comme si elle
                // valait.
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if (! $state) {
                        $set('delais', self::elementsDuRepeteur($this->parametresTelsQueLus()->delais_relance));
                    }
                }),
            Section::make('Délais de relance')
                ->description(fn (Get $get): string => 'En jours après la date d\'échéance de la facture : la relance n°1 part au premier délai, la n°2 au suivant... '
                    .($get('actif')
                        ? 'Modifiez-les, supprimez-les ou ajoutez-en autant que vous voulez.'
                        : 'Voici un exemple : activez les relances pour l\'utiliser et le régler comme vous voulez.'))
                ->schema([
                    Repeater::make('delais')
                        ->label('')
                        ->simple(
                            TextInput::make('jours')
                                ->label('Jours après l\'échéance')
                                ->integer()
                                ->minValue(1)
                                ->maxValue(365)
                                ->suffix('jours après l\'échéance')
                                ->required()
                                ->distinct()
                                ->validationMessages([
                                    'required' => 'Indiquez le nombre de jours.',
                                    'integer' => 'Un nombre entier de jours.',
                                    'min' => 'Au moins un jour après l\'échéance.',
                                    'max' => 'Au plus 365 jours après l\'échéance.',
                                    'distinct' => 'Ce délai est déjà prévu.',
                                ]),
                        )
                        ->minItems(fn (Get $get): int => $get('actif') ? 1 : 0)
                        ->validationMessages(['min' => 'Indiquez au moins un délai de relance.'])
                        ->reorderable(false)
                        ->addActionLabel('Ajouter une relance')
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Désactivées, les relances gardent leurs délais enregistrés.
        $enregistre = $data['actif']
            ? $this->enregistrerLesParametres(PermissionRegistry::RELANCES_REGLER, ['relances_automatiques' => true, 'delais_relance' => $data['delais']], 'Relances automatiques enregistrées')
            : $this->enregistrerLesParametres(PermissionRegistry::RELANCES_REGLER, ['relances_automatiques' => false], 'Relances automatiques désactivées');

        if ($enregistre) {
            $this->remplir();
        }
    }

    /**
     * Un $set() ne passe pas par le remplissage du formulaire : les délais
     * y sont donnés comme le répéteur les tient, un élément par délai.
     *
     * @param  list<int>  $delais
     * @return array<string, array{jours: int}>
     */
    private static function elementsDuRepeteur(array $delais): array
    {
        return collect($delais)
            ->mapWithKeys(fn (int $delai): array => [(string) Str::uuid() => ['jours' => $delai]])
            ->all();
    }
}
