<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Catalogue\Services\EssaiDuBareme;
use App\Domain\Catalogue\Services\ExemplesDArrondi;
use App\Domain\Facturation\Rules\MontantAuCentime;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\Concerns\EnregistreLesParametresDuMagasin;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\View;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * Le barème avec lequel le magasin fixe ses prix de vente (voir
 * BaremePrixDeVente) : un bénéfice selon le prix d'achat de l'article,
 * appliqué aux produits de ses factures d'achat, puis, au choix, une
 * formule d'arrondi du prix de vente (voir ArrondiDuPrixDeVente). Seul ce
 * que la personne y a corrigé s'enregistre (voir
 * EnregistreLesParametresDuMagasin).
 *
 * @property Form $form le formulaire de la page (voir InteractsWithForms)
 */
class ParametresPrixDeVente extends Page implements HasForms
{
    use EnregistreLesParametresDuMagasin, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Prix de vente';

    protected static ?string $title = 'Prix de vente';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    protected static function autorisationDeLaPage(): string
    {
        return PermissionRegistry::PRIX_VENTE_REGLER;
    }

    public function mount(): void
    {
        $this->remplir($this->lireLesParametres());
    }

    /**
     * Le barème tel qu'il est enregistré, relu en base.
     */
    private function remplir(Magasin $magasin): void
    {
        $this->form->fill([
            'actif' => $magasin->prix_vente_automatique,
            ...self::etatDuBareme(ValeurRequise::de($magasin->bareme_prix_vente, 'le barème des prix de vente du magasin')),
            'essai_taux_tva' => self::tauxDansLeChamp($magasin->tauxTvaParDefaut()),
        ]);
    }

    /**
     * Le barème tel que le formulaire le tient : ses paliers, son bénéfice
     * au-delà, la case « Arrondir le prix de vente » et la formule choisie
     * derrière elle (« Un prix en 9 » d'office tant que la case est
     * décochée, le magasin n'en gardant aucune sans arrondi).
     *
     * @return array{paliers: list<array{jusqu_a: string, benefice: string}>, benefice_au_dela: string|null, arrondi: bool, formule: string}
     */
    private static function etatDuBareme(BaremePrixDeVente $bareme): array
    {
        $tableau = $bareme->versTableau();

        return [
            'paliers' => $tableau['paliers'],
            'benefice_au_dela' => $tableau['benefice_au_dela'],
            'arrondi' => $bareme->arrondi !== ArrondiDuPrixDeVente::Aucun,
            'formule' => ($bareme->arrondi === ArrondiDuPrixDeVente::Aucun ? ArrondiDuPrixDeVente::UnPrixEn9 : $bareme->arrondi)->value,
        ];
    }

    /**
     * Un taux écrit comme sa colonne (« 20.00 ») tel que l'écrit le champ
     * où il se saisit : « 20 » (voir CalculateurLigne::centiemesDansUnChamp()).
     */
    private static function tauxDansLeChamp(string $taux): string
    {
        return CalculateurLigne::centiemesDansUnChamp(app(CalculateurLigne::class)->enCentiemes($taux));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Toggle::make('actif')
                ->label('Calculer automatiquement le prix de vente')
                ->helperText('Dans une facture d\'achat, le prix de vente TTC d\'un produit se remplit d\'après ce barème dès que vous saisissez son prix d\'achat. Vous pouvez toujours le corriger. Désactivé, votre barème est gardé pour le jour où vous le réactivez.')
                ->live()
                // Désactiver remet le barème que la page a lu : une saisie
                // laissée en cours ne se retrouve ni grisée à l'écran comme
                // si elle valait, avec ses erreurs, ni bloquante à
                // l'enregistrement.
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    foreach (self::etatDuBareme(ValeurRequise::de($this->parametresTelsQueLus()->bareme_prix_vente, 'le barème des prix de vente du magasin')) as $champ => $valeur) {
                        $set($champ, $valeur);
                    }

                    $this->resetErrorBag();
                }),
            // Visible même désactivé, pour que le magasin voie d'emblée de
            // quoi il s'agit ; il ne se modifie qu'une fois activé.
            Section::make('Bénéfice selon le prix d\'achat')
                ->description(fn (Get $get): string => 'Prix de vente TTC = (prix d\'achat HT + bénéfice) + TVA. Le prix d\'achat est celui de la ligne, remise du fournisseur déduite. '
                    .($get('actif')
                        ? 'Le logiciel vous propose ces paliers au départ : modifiez-les, supprimez-les ou ajoutez-en autant que vous voulez.'
                        : 'Voici un exemple de barème : activez le calcul pour l\'utiliser et le régler comme vous voulez.'))
                ->schema([
                    Repeater::make('paliers')
                        ->label('')
                        ->schema([
                            // Les montants du barème s'écrivent au centime,
                            // jamais arrondis en silence (voir
                            // BaremePrixDeVente::depuisTableau()).
                            TextInput::make('jusqu_a')
                                ->label('Prix d\'achat HT inférieur à')
                                ->numeric()
                                ->minValue('0.01')
                                ->rule(new MontantAuCentime)
                                ->suffix('€')
                                ->required()
                                ->distinct(),
                            TextInput::make('benefice')
                                ->label('Bénéfice ajouté')
                                ->numeric()
                                ->minValue(0)
                                ->rule(new MontantAuCentime)
                                ->suffix('€ HT')
                                ->required(),
                        ])
                        ->columns(2)
                        ->reorderable(false)
                        ->addActionLabel('Ajouter un palier')
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                    TextInput::make('benefice_au_dela')
                        ->label('Bénéfice au-delà du dernier palier')
                        ->helperText('Laissez vide pour saisir à la main le prix de vente des articles plus chers.')
                        ->numeric()
                        ->minValue(0)
                        ->rule(new MontantAuCentime)
                        ->suffix('€ HT')
                        ->required(fn (Get $get): bool => $get('actif') && blank($get('paliers')))
                        ->validationMessages(['required' => 'Sans palier, indiquez au moins le bénéfice à appliquer.'])
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                    Toggle::make('arrondi')
                        ->label('Arrondir le prix de vente')
                        ->helperText('Cochez pour ramener le prix de vente calculé à un prix rond, puis choisissez la formule.')
                        ->live()
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                    // Un champ masqué n'est ni validé ni enregistré, et son
                    // état reste en mémoire : décocher puis recocher retrouve
                    // la formule choisie.
                    Radio::make('formule')
                        ->label('Formule d\'arrondi')
                        ->options(ExemplesDArrondi::libelles())
                        ->descriptions(ExemplesDArrondi::phrases())
                        ->in(array_map(fn (ArrondiDuPrixDeVente $formule): string => $formule->value, ArrondiDuPrixDeVente::formules()))
                        ->required(fn (Get $get): bool => (bool) $get('arrondi'))
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('arrondi'))
                        ->disabled(fn (Get $get): bool => ! $get('actif'))
                        ->extraAttributes(['class' => 'piliqi-arrondi__formules']),
                ]),
            Section::make('Essayer le barème')
                ->schema([
                    TextInput::make('essai_prix_achat_ht')
                        ->label('Prix d\'achat HT')
                        ->numeric()
                        ->suffix('€')
                        ->live(debounce: 400)
                        ->dehydrated(false),
                    TextInput::make('essai_taux_tva')
                        ->label('TVA')
                        ->numeric()
                        ->suffix('%')
                        ->live(debounce: 400)
                        ->dehydrated(false),
                    View::make('filament.app.pages.essai-du-bareme')
                        ->viewData(fn (Get $get): array => self::lectureDeLEssai($get))
                        ->columnSpanFull(),
                ])->columns(2),
        ])->statePath('data');
    }

    /**
     * Le barème tel qu'il est en train d'être saisi, appliqué au prix
     * d'achat d'essai -- comme le fera une facture d'achat, qui ne propose
     * rien tant que la TVA est vide : ce que montre la vue de l'essai (voir
     * EssaiDuBareme), avec la formule dont elle écrit le tableau d'exemples,
     * ou null case décochée.
     *
     * @return array{formule: ArrondiDuPrixDeVente|null, lecture: array{avant: string, apres: string, lignes: list<array{texte: string, grise: bool}>}}
     */
    private static function lectureDeLEssai(Get $get): array
    {
        $tauxTva = ProduitResource::tauxDeTvaSaisi($get('essai_taux_tva'));

        try {
            $formule = (bool) $get('arrondi') ? ArrondiDuPrixDeVente::choisie(true, $get('formule')) : null;
            // Le prix d'achat d'essai se lit comme un coût, au dix-millième
            // d'euro (voir CalculateurLigne::coutUnitaireNet()).
            $lecture = blank($get('essai_prix_achat_ht')) || $tauxTva === null
                ? EssaiDuBareme::illisible()
                : EssaiDuBareme::lire(self::baremeSaisi($get), SaisieDecimale::enDixMilliemes(ValeurSimple::simple($get('essai_prix_achat_ht'))), $tauxTva);
        } catch (InvalidArgumentException) {
            return ['formule' => null, 'lecture' => EssaiDuBareme::illisible()];
        }

        return ['formule' => $formule, 'lecture' => $lecture];
    }

    private static function baremeSaisi(Get $get): BaremePrixDeVente
    {
        return BaremePrixDeVente::depuisTableau([
            'paliers' => array_values(array_filter(
                ValeursLues::lignes($get('paliers') ?? []),
                fn (array $palier): bool => filled($palier['jusqu_a'] ?? null) && filled($palier['benefice'] ?? null),
            )),
            'benefice_au_dela' => $get('benefice_au_dela'),
            'arrondi' => ArrondiDuPrixDeVente::choisie((bool) $get('arrondi'), $get('formule'))->value,
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Désactivé, le barème ne se modifie pas : celui enregistré reste
        // tel quel.
        $magasin = $data['actif']
            ? $this->enregistrerLeBareme($data)
            : $this->enregistrerLesParametres(['prix_vente_automatique' => false], 'Prix de vente automatique désactivé');

        if ($magasin !== null) {
            $this->remplir($magasin);
        }
    }

    /**
     * Le calcul activé avec le barème saisi : le magasin relu, ou null
     * quand le barème ou l'enregistrement est refusé.
     *
     * @param  array<string, mixed>  $data
     */
    private function enregistrerLeBareme(array $data): ?Magasin
    {
        try {
            $bareme = BaremePrixDeVente::depuisTableau([
                'paliers' => array_values(ValeursLues::tableau($data['paliers'] ?? [])),
                'benefice_au_dela' => $data['benefice_au_dela'],
                'arrondi' => ArrondiDuPrixDeVente::choisie($data['arrondi'] === true, $data['formule'] ?? null)->value,
            ]);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return null;
        }

        return $this->enregistrerLesParametres(['prix_vente_automatique' => true, 'bareme_prix_vente' => $bareme], 'Barème de prix de vente enregistré');
    }
}
