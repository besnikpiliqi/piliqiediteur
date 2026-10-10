<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Services\NumeroTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Filament\Concerns\GereLesExceptionsMetier;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'identité légale du magasin, imprimée en tête de ses devis, factures et
 * avoirs (voir documents/pdf/en-tete.blade.php), et ses coordonnées, qui
 * signent les e-mails de ses documents (voir DocumentMail). Elle se règle
 * avec « Paramètres du magasin » (PermissionRegistry::PARAMETRES_GERER),
 * comme les paramètres fiscaux : même responsabilité de configuration
 * légale du magasin, que le propriétaire détient et délègue à qui il veut.
 *
 * Le code du magasin, qui figure dans les numéros de tous ses documents, se
 * modifie ici jusqu'au premier document ; le champ se désactive ensuite
 * (voir Magasin::codeEstDefinitif()).
 */
class IdentiteMagasin extends Page implements HasForms
{
    use GereLesExceptionsMetier, InteractsWithForms;

    /**
     * Les champs de la page, tous enregistrés tels quels sur le magasin.
     */
    private const CHAMPS = [
        'nom', 'code', 'raison_sociale', 'forme_juridique', 'siren', 'siret', 'numero_tva',
        'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays',
        'email', 'telephone',
    ];

    private const MESSAGE_SIRET_DEJA_UTILISE = 'Ce SIRET est déjà celui d\'un autre magasin.';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Identité du magasin';

    protected static ?string $title = 'Identité du magasin';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Lu une fois par requête (voir codeEstDefinitif()).
     */
    private ?bool $codeDefinitif = null;

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
                    TextInput::make('code')
                        ->label('Code du magasin')
                        ->helperText(fn (): string => $this->codeEstDefinitif()
                            ? 'Il figure dans les numéros de vos documents : il ne change plus.'
                            : 'Il figurera dans les numéros de tous vos documents (ex. FAC-LYON1-2026-000001) et ne changera plus après votre premier document.')
                        ->required()
                        ->maxLength(CodeMagasin::LONGUEUR_MAXIMALE)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        // Comme pour le SIRET, seule l'existence du code chez
                        // un autre magasin est lue, magasins supprimés compris
                        // (voir CodeMagasin::estPris()).
                        ->rule(fn (): CodeMagasinLibre => new CodeMagasinLibre($this->magasin()))
                        ->dehydrateStateUsing(fn (string $state): string => CodeMagasin::normaliser($state))
                        ->disabled(fn (): bool => $this->codeEstDefinitif()),
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
                        // Lecture de toute la table magasins limitée, comme pour
                        // le code, à l'existence de ce SIRET : un établissement
                        // n'appartient qu'à un seul magasin du logiciel, et le
                        // SIRET d'un magasin supprimé, qui reste en base, ne
                        // sert jamais à un autre (voir l'index unique de
                        // magasins.siret et le déclencheur qui refuse
                        // d'effacer un magasin).
                        ->unique(table: Magasin::class, ignorable: fn (): Magasin => $this->magasin())
                        ->validationMessages([
                            'regex' => 'Le SIRET compte 14 chiffres, sans espace.',
                            'unique' => self::MESSAGE_SIRET_DEJA_UTILISE,
                        ]),
                    TextInput::make('numero_tva')
                        ->label('N° TVA intracommunautaire')
                        ->maxLength(20)
                        ->regex(NumeroTva::FORMAT)
                        ->validationMessages(['regex' => NumeroTva::MESSAGE_FORMAT]),
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

    /**
     * Le magasin se modifie dans une transaction : un changement de code y
     * verrouille la ligne du magasin jusqu'à son enregistrement (voir
     * Magasin::codeEstDefinitif()). Un code devenu définitif depuis
     * l'ouverture de la page n'est plus enregistré, son champ étant
     * désactivé, et le formulaire montre ensuite ce qui est enregistré ;
     * devenu définitif pendant l'enregistrement même, il est refusé, et
     * l'écran dit pourquoi.
     *
     * @throws ValidationException
     */
    public function save(): void
    {
        $donnees = $this->form->getState();

        static::executerSansCasser(function () use ($donnees): void {
            try {
                DB::transaction(fn () => $this->magasin()->update($donnees));
            } catch (UniqueConstraintViolationException $exception) {
                throw $this->refusDuDoublon($exception);
            }

            $this->form->fill($this->magasin()->only(self::CHAMPS));

            Notification::make()
                ->title('Identité du magasin enregistrée')
                ->success()
                ->send();
        });
    }

    /**
     * Le code ou le SIRET, libres à la validation, peuvent être pris par un
     * autre magasin avant l'enregistrement : l'index unique de la colonne
     * refuse alors la modification, et l'écran le dit comme la validation
     * l'aurait dit. Le message du pilote de la base, sans la requête, nomme
     * l'index refusé (magasins.siret, magasins_siret_unique...) ; un code
     * s'écrit en majuscules et un SIRET en chiffres : « siret » en
     * minuscules n'y désigne que l'index du SIRET.
     */
    private function refusDuDoublon(UniqueConstraintViolationException $exception): ValidationException
    {
        return str_contains((string) ($exception->errorInfo[2] ?? ''), 'siret')
            ? ValidationException::withMessages(['data.siret' => self::MESSAGE_SIRET_DEJA_UTILISE])
            : ValidationException::withMessages(['data.code' => CodeMagasinLibre::MESSAGE_DEJA_UTILISE]);
    }

    private function codeEstDefinitif(): bool
    {
        return $this->codeDefinitif ??= $this->magasin()->codeEstDefinitif();
    }

    private function magasin(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }
}
