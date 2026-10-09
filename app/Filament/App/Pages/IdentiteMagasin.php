<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Models\Magasin;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * L'identité légale du magasin, imprimée en tête de ses devis, factures et
 * avoirs (voir documents/pdf/en-tete.blade.php), et ses coordonnées, qui
 * signent les e-mails de ses documents (voir DocumentMail). Elle se règle
 * avec « Paramètres du magasin » (PermissionRegistry::PARAMETRES_GERER),
 * comme les paramètres fiscaux : même responsabilité de configuration
 * légale du magasin, que le propriétaire détient et délègue à qui il veut.
 */
class IdentiteMagasin extends Page implements HasForms
{
    use InteractsWithForms;

    /**
     * Les champs de la page, tous enregistrés tels quels sur le magasin.
     */
    private const CHAMPS = [
        'nom', 'raison_sociale', 'forme_juridique', 'siren', 'siret', 'numero_tva',
        'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays',
        'email', 'telephone',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Identité du magasin';

    protected static ?string $title = 'Identité du magasin';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionRegistry::PARAMETRES_GERER) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->magasin()->only(self::CHAMPS));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identité légale')
                ->description('Imprimée en tête de vos devis, factures et avoirs.')
                ->schema([
                    TextInput::make('nom')
                        ->label('Nom commercial')
                        ->required()
                        ->maxLength(150),
                    TextInput::make('raison_sociale')
                        ->label('Raison sociale')
                        ->maxLength(200),
                    TextInput::make('forme_juridique')
                        ->label('Forme juridique')
                        ->placeholder('SAS, SARL, entreprise individuelle...')
                        ->maxLength(100),
                    TextInput::make('siren')
                        ->label('SIREN')
                        ->maxLength(9)
                        ->regex('/^\d{9}$/')
                        ->requiredWith('siret')
                        ->validationMessages([
                            'regex' => 'Le SIREN compte 9 chiffres, sans espace.',
                            'required_with' => 'Indiquez le SIREN, par lequel commence le SIRET.',
                        ]),
                    TextInput::make('siret')
                        ->label('SIRET')
                        ->maxLength(14)
                        ->regex('/^\d{14}$/')
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $siret, Closure $fail) use ($get): void {
                            if (filled($get('siren')) && ! str_starts_with((string) $siret, (string) $get('siren'))) {
                                $fail('Le SIRET commence par les 9 chiffres du SIREN.');
                            }
                        })
                        // Seule lecture volontaire de toute la table magasins,
                        // limitée à l'existence de ce SIRET : un établissement
                        // n'appartient qu'à un seul magasin du logiciel, et le
                        // SIRET d'un magasin supprimé, qui reste en base, ne
                        // sert jamais à un autre (voir l'index unique de
                        // magasins.siret et le déclencheur qui refuse
                        // d'effacer un magasin).
                        ->unique(table: Magasin::class, ignorable: fn (): Magasin => $this->magasin())
                        ->validationMessages([
                            'regex' => 'Le SIRET compte 14 chiffres, sans espace.',
                            'unique' => 'Ce SIRET est déjà celui d\'un autre magasin.',
                        ]),
                    TextInput::make('numero_tva')
                        ->label('N° TVA intracommunautaire')
                        ->maxLength(20)
                        ->regex('/^[A-Z]{2}[0-9A-Z]{2,13}$/')
                        ->validationMessages(['regex' => 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de chiffres ou de lettres, sans espace.']),
                ])
                ->columns(2),
            Section::make('Adresse')
                ->schema([
                    TextInput::make('adresse')->maxLength(255)->columnSpanFull(),
                    TextInput::make('complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull(),
                    TextInput::make('code_postal')->label('Code postal')->maxLength(10),
                    TextInput::make('ville')->maxLength(100),
                    TextInput::make('pays')
                        ->label('Pays')
                        ->helperText('Code du pays à deux lettres : FR, BE, CH...')
                        ->required()
                        ->maxLength(2)
                        ->regex('/^[A-Z]{2}$/')
                        ->validationMessages(['regex' => 'Le code du pays compte deux lettres majuscules : FR, BE, CH...']),
                ])
                ->columns(2),
            Section::make('Coordonnées')
                ->description('Imprimées sur vos documents ; les réponses aux e-mails de vos documents arrivent à cette adresse.')
                ->schema([
                    TextInput::make('email')
                        ->label('E-mail')
                        ->type('email')
                        ->rule('email:strict')
                        ->maxLength(150)
                        ->validationMessages(['email' => 'Cette adresse e-mail n\'est pas valide.']),
                    TextInput::make('telephone')->label('Téléphone')->tel()->maxLength(30),
                ])
                ->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $this->magasin()->update($this->form->getState());

        Notification::make()
            ->title('Identité du magasin enregistrée')
            ->success()
            ->send();
    }

    private function magasin(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }
}
