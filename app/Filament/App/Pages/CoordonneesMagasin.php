<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\Concerns\EnregistreLesParametresDuMagasin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Les coordonnées du magasin et ses références d'acheteur : son adresse
 * e-mail et son téléphone, que ses devis, factures et e-mails impriment,
 * et les références que ses fournisseurs lui donnent (n° de client, code
 * d'acheteur...), qui aideront à reconnaître le magasin destinataire d'une
 * facture reçue (étape 2). Son nom, son adresse et son SIRET s'y lisent
 * sans se modifier : la société les gère, dans son administration (voir
 * ModifierMagasin), comme son identité légale.
 *
 * Elles engagent le magasin envers ses clients : elles se règlent avec
 * « Paramètres du magasin » (PermissionRegistry::PARAMETRES_GERER), que le
 * propriétaire et les responsables du magasin ont toujours et qu'ils
 * cochent pour qui ils veulent ; en lecture seule, la page se ferme. Seul
 * le magasin actif est lu et modifié, et rien de la société, et seulement
 * ce que la personne y a corrigé (voir EnregistreLesParametresDuMagasin).
 */
class CoordonneesMagasin extends Page implements HasForms
{
    use EnregistreLesParametresDuMagasin, InteractsWithForms;

    /**
     * Les champs de la page, tous enregistrés tels quels sur le magasin.
     */
    private const CHAMPS = ['email', 'telephone', 'references_acheteur'];

    /**
     * Combien de références d'acheteur un magasin garde, au plus.
     */
    public const REFERENCES_MAXIMUM = 20;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Coordonnées';

    protected static ?int $navigationSort = -1;

    protected static ?string $title = 'Coordonnées du magasin';

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
        $this->remplir($this->lireLesParametres());
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Établissement')
                ->description('Le nom, l\'adresse et le SIRET du magasin se gèrent dans l\'administration de la société.')
                ->schema([
                    Placeholder::make('nom')->label('Nom')->content(fn (): string => $this->magasin()->nom),
                    Placeholder::make('siret')->label('SIRET')->content(fn (): string => (string) $this->magasin()->siret),
                    Placeholder::make('adresse')
                        ->label('Adresse')
                        ->content(fn (): string => implode(', ', array_filter([$this->magasin()->adresse, $this->magasin()->complement_adresse, trim("{$this->magasin()->code_postal} {$this->magasin()->ville}")], filled(...))) ?: '—'),
                ])
                ->columns(3),
            Section::make('Coordonnées')
                ->description('Imprimées sur les devis, factures et avoirs du magasin, et dans ses e-mails aux clients.')
                ->schema([
                    TextInput::make('email')
                        ->label('Adresse e-mail')
                        ->email()
                        ->maxLength(150),
                    TextInput::make('telephone')
                        ->label('Téléphone')
                        ->tel()
                        ->maxLength(30),
                ])
                ->columns(2),
            Section::make('Références d\'acheteur')
                ->schema([
                    TagsInput::make('references_acheteur')
                        ->label('Références que vos fournisseurs vous donnent')
                        ->helperText('N° de client, code d\'acheteur... : elles aideront à reconnaître ce magasin comme destinataire d\'une facture reçue. Validez chacune par Entrée.')
                        ->nestedRecursiveRules(['string', 'max:100'])
                        ->rules(['array', 'max:'.self::REFERENCES_MAXIMUM])
                        ->validationMessages([
                            'max' => 'Au plus '.self::REFERENCES_MAXIMUM.' références, de 100 caractères chacune.',
                        ]),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $donnees = $this->form->getState();
        $saisie = [
            ...$donnees,
            'references_acheteur' => array_values(array_unique(array_filter(array_map(trim(...), $donnees['references_acheteur'] ?? []), filled(...)))) ?: null,
        ];

        $magasin = $this->enregistrerLesParametres($saisie, 'Coordonnées du magasin enregistrées');

        if ($magasin !== null) {
            $this->remplir($magasin);
        }
    }

    private function remplir(Magasin $magasin): void
    {
        $this->form->fill([...$magasin->only(self::CHAMPS), 'references_acheteur' => $magasin->references_acheteur ?? []]);
    }

    private function magasin(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }
}
