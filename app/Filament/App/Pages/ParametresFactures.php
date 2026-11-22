<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Filament\Concerns\EnregistreLesParametresDuMagasin;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;

/**
 * L'échéance des factures du magasin et les mentions qu'elles impriment
 * entre professionnels : le délai de paiement par défaut, qui s'applique
 * au client sans délai propre (B7, voir Magasin::delaiDePaiementPour()),
 * et les textes des pénalités de retard, de l'indemnité forfaitaire pour
 * frais de recouvrement et de l'escompte (B9, art. L441-9 du Code de
 * commerce), que chaque magasin reçoit à sa création et règle ici ; et,
 * pour ses devis, le dépannage, la réparation ou l'entretien à domicile
 * (arrêté du 24 janvier 2017, décoché au départ) et le prix d'un devis
 * payant, vide pour un devis gratuit (voir DepannageADomicile).
 *
 * Comme ses coordonnées (voir CoordonneesMagasin), ces réglages engagent
 * le magasin envers ses clients : ils se règlent avec « Paramètres du
 * magasin »
 * (PermissionRegistry::PARAMETRES_GERER). Seul le magasin actif est lu et
 * modifié, et seulement ce que la personne y a corrigé (voir
 * EnregistreLesParametresDuMagasin).
 *
 * @property Form $form le formulaire de la page (voir InteractsWithForms)
 */
class ParametresFactures extends Page implements HasForms
{
    use EnregistreLesParametresDuMagasin, InteractsWithForms;

    /**
     * Les champs de la page, tous enregistrés tels quels sur le magasin.
     */
    private const CHAMPS = ['delai_paiement_jours', 'mention_penalites_retard', 'mention_indemnite_recouvrement', 'mention_escompte', 'depannage_a_domicile', 'prix_du_devis_de_depannage'];

    private const AIDE_DES_MENTIONS = 'Imprimées sur vos factures aux professionnels (art. L441-9 du Code de commerce).';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Factures et devis';

    protected static ?string $title = 'Factures et devis : échéance et mentions';

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
            Section::make('Devis de dépannage à domicile')
                ->description('Pour les métiers du bâtiment et de l\'équipement de la maison (arrêté du 24 janvier 2017).')
                ->schema([
                    Toggle::make('depannage_a_domicile')
                        ->label('Dépannage, réparation ou entretien à domicile')
                        ->helperText('Coché, chaque devis à un particulier indique le lieu de l\'intervention, si le devis est gratuit ou payant et que le client peut conserver les pièces remplacées ; un devis signé chez le client imprime aussi le droit de rétractation de quatorze jours et son formulaire. Ajoutez les frais de déplacement sur une ligne à part.')
                        ->live(),
                    TextInput::make('prix_du_devis_de_depannage')
                        ->label('Prix du devis')
                        ->helperText('Laissez vide pour un devis gratuit : le devis imprime « Devis gratuit », sinon « Devis payant : » et ce prix.')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(99999.99)
                        ->rule('decimal:0,2')
                        ->suffix('€ TTC')
                        ->visible(fn (Get $get): bool => (bool) $get('depannage_a_domicile'))
                        ->validationMessages([
                            'numeric' => 'Un montant en euros, par exemple 35 ou 35,50.',
                            'min' => 'Le prix d\'un devis payant est d\'au moins 0,01 €.',
                            'max' => 'Au plus 99 999,99 €.',
                            'decimal' => 'Deux décimales au plus.',
                        ]),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $saisie = $this->form->getState();
        $saisie['prix_du_devis_de_depannage'] = blank($saisie['prix_du_devis_de_depannage'] ?? null) ? null : $saisie['prix_du_devis_de_depannage'];
        $magasin = $this->enregistrerLesParametres($saisie, 'Paramètres des factures et des devis enregistrés');

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
