<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Filament\Concerns\EnregistreLesParametresDuMagasin;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * L'échéance des factures du magasin et les mentions qu'elles impriment
 * entre professionnels : le délai de paiement par défaut, qui s'applique
 * au client sans délai propre (B7, voir Magasin::delaiDePaiementPour()),
 * et les textes des pénalités de retard, de l'indemnité forfaitaire pour
 * frais de recouvrement et de l'escompte (B9, art. L441-9 du Code de
 * commerce), que chaque magasin reçoit à sa création et règle ici.
 *
 * Comme ses coordonnées (voir CoordonneesMagasin), ces réglages engagent
 * le magasin envers ses clients : ils se règlent avec « Paramètres du
 * magasin »
 * (PermissionRegistry::PARAMETRES_GERER). Seul le magasin actif est lu et
 * modifié, et seulement ce que la personne y a corrigé (voir
 * EnregistreLesParametresDuMagasin).
 */
class ParametresFactures extends Page implements HasForms
{
    use EnregistreLesParametresDuMagasin, InteractsWithForms;

    /**
     * Les champs de la page, tous enregistrés tels quels sur le magasin.
     */
    private const CHAMPS = ['delai_paiement_jours', 'mention_penalites_retard', 'mention_indemnite_recouvrement', 'mention_escompte'];

    private const AIDE_DES_MENTIONS = 'Imprimées sur vos factures aux professionnels (art. L441-9 du Code de commerce).';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Factures';

    protected static ?string $title = 'Factures : échéance et mentions';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    protected static function autorisationDeLaPage(): string
    {
        return PermissionRegistry::PARAMETRES_GERER;
    }

    public function mount(): void
    {
        $this->form->fill($this->lireLesParametres()->only(self::CHAMPS));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Échéance')->schema([
                TextInput::make('delai_paiement_jours')
                    ->label('Délai de paiement par défaut')
                    ->helperText('L\'échéance d\'une facture = date d\'émission + délai du client, sinon ce délai.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(365)
                    ->suffix('jours')
                    ->required()
                    ->validationMessages([
                        'required' => 'Indiquez le délai de paiement.',
                        'numeric' => 'Un nombre entier de jours.',
                        'integer' => 'Un nombre entier de jours.',
                        'min' => 'Le délai ne peut pas être négatif.',
                        'max' => 'Au plus 365 jours.',
                    ]),
            ]),
            Section::make('Mentions entre professionnels')->schema([
                self::mention('mention_penalites_retard', 'Pénalités de retard'),
                self::mention('mention_indemnite_recouvrement', 'Indemnité forfaitaire pour frais de recouvrement'),
                self::mention('mention_escompte', 'Escompte pour paiement anticipé'),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $magasin = $this->enregistrerLesParametres($this->form->getState(), 'Paramètres des factures enregistrés');

        if ($magasin !== null) {
            $this->form->fill($magasin->only(self::CHAMPS));
        }
    }

    private static function mention(string $champ, string $libelle): Textarea
    {
        return Textarea::make($champ)
            ->label($libelle)
            ->helperText(self::AIDE_DES_MENTIONS)
            ->required()
            ->maxLength(500)
            ->rows(2)
            ->validationMessages([
                'required' => 'Cette mention est obligatoire sur une facture à un professionnel.',
                'max' => 'Au plus 500 caractères.',
            ]);
    }
}
