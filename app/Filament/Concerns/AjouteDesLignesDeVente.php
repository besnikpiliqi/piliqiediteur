<?php

namespace App\Filament\Concerns;

use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ServiceResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use WeakMap;

/**
 * Les lignes d'une vente (caisse, facture, devis) : chacune vend un produit
 * du catalogue ou un service du magasin, jamais une ligne libre (voir
 * ConstructeurLigneDocument). Deux boutons sous les lignes les ajoutent.
 *
 * Les montants affichés pendant la saisie passent par le même
 * constructeur que l'enregistrement réel, avec le client choisi, la nature
 * de l'opération (voir ChoisitLaNatureDeLOperation) et le régime de TVA du
 * magasin (voir apercuDesLignes()) : l'écran ne peut jamais afficher un
 * centime différent de ce qui sera enregistré. Pour un client
 * professionnel, hors franchise en base, chaque ligne affiche son HT, la
 * TVA se calculant sur le total de son taux (voir totalDeLigne()). Une
 * vente sans TVA française affiche ses lignes sans TVA, et le prix d'un
 * service s'y saisit sans TVA.
 */
trait AjouteDesLignesDeVente
{
    use SaisitLesPrixDeLigne;

    /**
     * Les calculs déjà faits pendant la requête en cours, par page Livewire
     * (une nouvelle instance à chaque requête) puis par calcul : le
     * contexte de vente de chaque client choisi, l'aperçu de chaque jeu de
     * lignes pour un client (voir calculUneFoisParRequete()).
     *
     * @var WeakMap<LivewireComponent, array<string, mixed>>|null
     */
    private static ?WeakMap $calculsDeLaRequete = null;

    /**
     * @return array<Component>
     */
    public static function lignesDeVente(): array
    {
        return [
            Repeater::make('lignes')
                ->label('')
                ->schema(self::ligneSchema())
                ->columns(9)
                ->defaultItems(1)
                ->itemLabel(fn (array $state): string => filled($state['service_id'] ?? null) ? 'Service' : 'Produit')
                // Remplacé par les deux boutons ci-dessous.
                ->addable(false)
                ->required(),
            Actions::make([
                self::actionAjouterUnProduit(),
                self::actionAjouterUnService(),
            ])->alignCenter(),
        ];
    }

    /**
     * Les lignes saisies, calculées comme à leur enregistrement pour ce
     * client et cette nature d'opération, la vente en France sans nature
     * (la Caisse), (voir ConstructeurLigneDocument::apercu()), par clé de
     * ligne ; une ligne pas encore complète n'y est pas.
     *
     * Le total de chaque ligne, celui du document et ceux de la Caisse
     * (reste à payer, monnaie à rendre) lisent tous ce même aperçu : il ne
     * se calcule, client et catalogue lus, qu'une fois par requête de la
     * page pour les mêmes lignes, le même client et la même nature, quel
     * que soit le nombre de lignes.
     *
     * @param  array<array-key, mixed>  $lignes
     * @return array<array-key, array<string, mixed>>
     */
    public static function apercuDesLignes(array $lignes, mixed $clientId, mixed $nature = null): array
    {
        $nature = NatureOperation::depuisSaisie($nature);

        return self::calculUneFoisParRequete(
            'apercu:'.hash('xxh128', serialize([$lignes, $clientId, $nature->value])),
            fn (): array => app(ConstructeurLigneDocument::class)->apercu($lignes, self::contexteDeVente($clientId, $nature)),
        );
    }

    /**
     * Le résultat d'un calcul de la page, fait une seule fois par requête
     * pour la même clé ; hors d'une page Livewire, refait à chaque appel.
     *
     * @template T
     *
     * @param  Closure(): T  $calculer
     * @return T
     */
    private static function calculUneFoisParRequete(string $cle, Closure $calculer): mixed
    {
        $page = Livewire::current();

        if (! $page instanceof LivewireComponent) {
            return $calculer();
        }

        self::$calculsDeLaRequete ??= new WeakMap;

        if (! array_key_exists($cle, self::$calculsDeLaRequete[$page] ?? [])) {
            $resultat = $calculer();
            // Relu après le calcul, qui a pu en mémoriser un autre (le
            // contexte de vente d'un aperçu).
            self::$calculsDeLaRequete[$page] = [...(self::$calculsDeLaRequete[$page] ?? []), $cle => $resultat];
        }

        return self::$calculsDeLaRequete[$page][$cle];
    }

    /**
     * Les lignes d'un brouillon de facture ou d'un devis, telles que son
     * formulaire les rouvre. Le taux d'une ligne de produit se reprend de
     * sa fiche (voir champsPrixDeLigne()) ; celui d'une ligne de service,
     * tel qu'il a été saisi -- sauf sur un document enregistré en
     * franchise en base ou sans TVA française, où son 0 % n'a pas été
     * saisi mais imposé (voir ConstructeurLigneDocument) : la ligne propose
     * alors le taux du territoire du magasin, celui qu'elle prend si le
     * magasin est sorti de la franchise ou la vente revenue en France
     * depuis, son prix sans TVA converti à ce taux (voir
     * ChoisitLaNatureDeLOperation::natureARouvrir()). Sinon, le champ reste
     * caché et la ligne à 0 %.
     *
     * @return list<array<string, mixed>>
     */
    public static function lignesARouvrir(Facture|Devis $document): array
    {
        return $document->lignes->map(fn (LigneFacture|LigneDevis $ligne): array => [
            'produit_id' => $ligne->produit_id,
            'service_id' => $ligne->service_id,
            'description' => $ligne->description,
            'reference' => $ligne->reference,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
            'prix_unitaire_ttc' => $ligne->prix_unitaire_ttc,
            'taux_tva' => self::pourcentageEnTexte(($document->franchise_en_base || $document->nature_operation->exonere()) && $ligne->service_id !== null
                ? self::magasin()->tauxTvaParDefaut()
                : $ligne->taux_tva),
            'remise_pourcentage' => self::pourcentageEnTexte($ligne->remise_pourcentage),
        ])->all();
    }

    /**
     * Une ligne vend un produit du catalogue ou un service du magasin : sa
     * description vient du catalogue et ne se corrige que dans la fiche du
     * produit ou du service, et seul le PU TTC se saisit -- le PU HT en est
     * la conversion. Une ligne de service n'a ni produit à chercher ni PU
     * HT affiché.
     *
     * @return array<Component>
     */
    public static function ligneSchema(): array
    {
        return [
            Hidden::make('service_id'),
            Select::make('produit_id')
                ->label('Produit')
                ->helperText('Recherchez par nom, référence, ou scannez le code-barres.')
                ->searchable()
                // Un produit archivé n'est plus proposé ; celui d'une ligne
                // déjà composée reste affiché, et le document ne s'enregistre
                // plus tant qu'il n'est pas restauré ou retiré (voir
                // ConstructeurLigneDocument).
                ->getSearchResultsUsing(fn (string $search): array => ProduitResource::rechercher(Produit::query(), $search))
                ->getOptionLabelUsing(fn ($value) => Produit::withTrashed()->find($value)?->nom)
                ->required(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->live()
                ->columnSpan(2)
                ->visible(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->afterStateUpdated(function (?int $state, Get $get, Set $set) {
                    $produit = $state ? Produit::find($state) : null;

                    if ($produit === null) {
                        return;
                    }

                    $set('description', $produit->nom);
                    $set('reference', $produit->reference);
                    $set('prix_unitaire_ttc', self::prixEnTexte((float) $produit->prix_vente_ttc));
                    self::appliquerLeTauxDuProduit($produit, $get, $set);
                }),
            TextInput::make('description')
                ->label(fn (Get $get): string => self::estUneLigneDeService($get) ? 'Service' : 'Description')
                ->disabled()
                // Reprend la place du produit et du PU HT, absents d'une
                // ligne de service.
                ->columnSpan(fn (Get $get): int => self::estUneLigneDeService($get) ? 5 : 2),
            // live() et non live(onBlur: true) sur tous les champs de la
            // ligne : le total (et, dans la Caisse, la monnaie à rendre) se
            // met à jour pendant la saisie, pas seulement au clic ailleurs.
            TextInput::make('quantite')->integer()->minValue(1)->required()->default(1)->saisieEnDirect(),
            ...self::champsPrixDeLigne(),
            TextInput::make('remise_pourcentage')->label('Remise %')->numeric()->minValue(0)->maxValue(100)->default(0)->saisieEnDirect(),
            self::totalDeLigne(),
        ];
    }

    public static function estUneLigneDeService(Get $get): bool
    {
        return filled($get('service_id'));
    }

    /**
     * PU HT, PU TTC et TVA d'une ligne de vente : seul le PU TTC se saisit
     * (le prix affiché au client, repris tel quel de la fiche du produit),
     * le PU HT n'en est que la conversion au centime, affichée à côté.
     *
     * Le taux d'une ligne de produit est celui de sa fiche (voir
     * ConstructeurLigneDocument) : affiché, il ne se modifie pas et ne
     * part pas avec le formulaire. Celui d'une ligne de service se saisit,
     * le taux du territoire du magasin par défaut (voir
     * Magasin::tauxTvaParDefaut()). En franchise en base, toute ligne est à
     * 0 % : le taux d'un produit affiche 0, celui d'un service ne se saisit
     * pas. Sans TVA française, le taux d'un produit reste affiché, celui
     * qui est retiré de son prix TTC, et le prix d'un service se saisit
     * sans TVA, sans taux ; il se convertit quand la vente entre dans la
     * TVA française ou en sort (voir ChoisitLaNatureDeLOperation).
     *
     * @return array<Component>
     */
    private static function champsPrixDeLigne(): array
    {
        return [
            TextInput::make('prix_unitaire_ht')
                ->label('PU HT')
                ->numeric()
                // Une ligne de service ne montre que son TTC.
                ->visible(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->disabled(),
            TextInput::make('prix_unitaire_ttc')
                ->label(fn (Get $get): string => self::estUneLigneDeService($get) && self::venteSansTvaFrancaise($get) ? 'Prix (sans TVA)' : 'PU TTC')
                ->numeric()
                ->minValue(0)
                ->saisieEnDirect()
                ->required()
                // Le PU HT d'une ligne de produit suit le PU TTC saisi, au
                // taux affiché ; une ligne de service n'en montre pas, son
                // HT figure sous son total (voir totalDeLigne()).
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if (blank($state) || self::estUneLigneDeService($get)) {
                        return;
                    }

                    try {
                        $set('prix_unitaire_ht', self::prixEnTexte(app(CalculateurLigne::class)->prixUnitaireHtDepuisTtc((float) $state, (float) $get('taux_tva'))));
                    } catch (InvalidArgumentException) {
                        // Prix hors limites (voir sa validation) : rien à convertir.
                    }
                }),
            TextInput::make('taux_tva')
                ->label('TVA %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required(fn (Get $get): bool => self::estUneLigneDeService($get))
                ->default(fn (): string => self::pourcentageEnTexte(self::magasin()->tauxTvaParDefaut()))
                ->disabled(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->hidden(fn (Get $get): bool => self::estUneLigneDeService($get) && (self::magasin()->estEnFranchiseDeTva() || self::venteSansTvaFrancaise($get)))
                ->helperText(fn (Get $get): ?string => match (true) {
                    self::magasin()->estEnFranchiseDeTva() => 'TVA non applicable',
                    self::venteSansTvaFrancaise($get) => 'Retirée du prix : aucune TVA facturée',
                    default => null,
                })
                ->saisieEnDirect()
                // Une ligne de produit rouverte (brouillon, devis, panier de
                // la caisse) reprend le taux de sa fiche et le régime du
                // magasin d'aujourd'hui, ceux avec lesquels elle sera
                // enregistrée ; une ligne de service garde le taux saisi
                // (voir lignesARouvrir()).
                ->afterStateHydrated(function (Get $get, Set $set): void {
                    $produit = self::estUneLigneDeService($get) || blank($get('produit_id'))
                        ? null
                        : Produit::withTrashed()->find($get('produit_id'));

                    if ($produit !== null) {
                        self::appliquerLeTauxDuProduit($produit, $get, $set);
                    }
                }),
        ];
    }

    /**
     * Le taux de la fiche du produit, ou 0 en franchise, et le PU HT qui en
     * découle.
     */
    private static function appliquerLeTauxDuProduit(Produit $produit, Get $get, Set $set): void
    {
        $tauxTva = self::magasin()->estEnFranchiseDeTva() ? 0.0 : (float) $produit->taux_tva;

        $set('taux_tva', self::pourcentageEnTexte($tauxTva));

        if (blank($get('prix_unitaire_ttc'))) {
            return;
        }

        try {
            $set('prix_unitaire_ht', self::prixEnTexte(app(CalculateurLigne::class)->prixUnitaireHtDepuisTtc((float) $get('prix_unitaire_ttc'), $tauxTva)));
        } catch (InvalidArgumentException) {
            // Prix hors limites (voir sa validation) : rien à convertir.
        }
    }

    /**
     * Le HT et la TVA de la ligne à côté de son TTC, comme le ticket. Pour
     * un client professionnel, hors franchise en base (voir
     * calculeDepuisLeHtDeLigne()), la ligne affiche son HT, quantité x PU
     * TTC ÷ (1 + taux) arrondi une fois : sa TVA se calcule sur le total HT
     * de son taux (E1, voir ConstructeurLigneDocument), dans les totaux du
     * document. Une ligne vendue sans TVA française affiche son HT, sans
     * TVA. Un taux de service qui n'est pas en vigueur sur le territoire du
     * magasin est signalé, sans être refusé (X8).
     */
    private static function totalDeLigne(): Placeholder
    {
        return Placeholder::make('total_ligne')
            ->label(fn (Get $get): string => self::calculeDepuisLeHtDeLigne($get) || self::venteSansTvaFrancaise($get) ? 'Total HT' : 'Total TTC')
            ->columnSpanFull()
            ->content(fn (Get $get, Placeholder $component): string => self::montantEnTexte((float) (self::montantsDeLaLigne($get, $component)[self::calculeDepuisLeHtDeLigne($get) ? 'montant_ht' : 'montant_ttc'] ?? 0)))
            ->helperText(function (Get $get, Placeholder $component): string {
                if (self::venteSansTvaFrancaise($get)) {
                    return 'Sans TVA française';
                }

                $montants = self::montantsDeLaLigne($get, $component);
                $detail = self::calculeDepuisLeHtDeLigne($get)
                    ? 'TVA calculée sur le total'
                    : self::montantEnTexte((float) ($montants['montant_ht'] ?? 0)).' HT + '.self::montantEnTexte((float) ($montants['montant_tva'] ?? 0)).' de TVA';
                $aide = self::estUneLigneDeService($get) && ! self::magasin()->estEnFranchiseDeTva()
                    ? self::magasin()->territoireTva()?->aideSiTauxInhabituel($get('taux_tva'))
                    : null;

                return $aide === null ? $detail : "{$detail} · {$aide}";
            });
    }

    /**
     * La ligne du champ est vendue à un professionnel, hors franchise en
     * base (voir ContexteDeVente::calculeDepuisLeHtDeLigne()) : en
     * franchise, elle se calcule et s'affiche comme celle d'un particulier.
     */
    private static function calculeDepuisLeHtDeLigne(Get $get): bool
    {
        return self::contexteDeVente($get('../../client_id'), NatureOperation::depuisSaisie($get('../../nature_operation')))->calculeDepuisLeHtDeLigne();
    }

    /**
     * La ligne du champ est vendue sans TVA française, à son prix sans la
     * TVA française (voir ContexteDeVente::retireLaTvaDuPrix()) : jamais à
     * la Caisse, qui vend en France.
     */
    private static function venteSansTvaFrancaise(Get $get): bool
    {
        return self::contexteDeVente($get('../../client_id'), NatureOperation::depuisSaisie($get('../../nature_operation')))->retireLaTvaDuPrix();
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    protected static function montantsDesLignesSaisies(Get $get): iterable
    {
        return self::apercuDesLignes($get('lignes') ?? [], $get('client_id'), $get('nature_operation'));
    }

    /**
     * La ligne du champ, lue par sa clé dans le répéteur dans l'aperçu de
     * toutes les lignes du document (voir apercuDesLignes()) ; vide tant
     * qu'elle n'est pas complète.
     *
     * @return array<string, mixed>
     */
    private static function montantsDeLaLigne(Get $get, Placeholder $champ): array
    {
        return self::apercuDesLignes($get('../../lignes') ?? [], $get('../../client_id'), $get('../../nature_operation'))[$champ->getContainer()->getStatePath(isAbsolute: false)] ?? [];
    }

    /**
     * Le client choisi s'il est du magasin actif (MagasinScope), archivé
     * compris, et le magasin actif, dont le régime de TVA et le territoire
     * décident du calcul, avec la nature choisie ; lus une seule fois par
     * requête de la page pour le même client et la même nature.
     */
    private static function contexteDeVente(mixed $clientId, NatureOperation $nature = NatureOperation::VenteEnFrance): ContexteDeVente
    {
        return self::calculUneFoisParRequete('contexte:'.hash('xxh128', serialize([$clientId, $nature->value])), function () use ($clientId, $nature): ContexteDeVente {
            $identifiant = is_bool($clientId) ? false : filter_var($clientId, FILTER_VALIDATE_INT);

            return ContexteDeVente::pour($identifiant === false ? null : Client::withTrashed()->find($identifiant), self::magasin(), $nature);
        });
    }

    private static function magasin(): Magasin
    {
        return app(TenantContext::class)->magasin();
    }

    private static function actionAjouterUnProduit(): Action
    {
        return Action::make('ajouterUnProduit')
            ->label('Ajouter un produit')
            ->color('gray')
            ->size(ActionSize::Small)
            ->action(fn (HasForms $livewire) => self::ajouterUneLigne($livewire));
    }

    /**
     * Cases à cocher des services que le magasin a déjà créés ; un service
     * qui n'y est pas encore se crée ici et y sera proposé la prochaine
     * fois (voir TrouverOuCreerServiceAction). Chaque service coché devient
     * une ligne dont le vendeur saisit le prix, TTC en France, sans TVA
     * sinon (voir champsPrixDeLigne()). La recherche passe par
     * le serveur, sans casse ni accents (voir servicesProposes()) : celle
     * d'une liste de cases à cocher de Filament, faite dans le navigateur,
     * tiendrait compte des accents.
     *
     * Ouvert à quiconque peut vendre, création comprise : un service n'est
     * qu'un nom, sans prix ni stock, et le vendeur doit pouvoir facturer une
     * réparation qui n'est pas encore dans la liste.
     */
    private static function actionAjouterUnService(): Action
    {
        return Action::make('ajouterUnService')
            ->label('Ajouter un service')
            ->color('gray')
            ->size(ActionSize::Small)
            ->modalHeading('Ajouter un service')
            ->modalDescription('Cochez les services réalisés : chacun devient une ligne dont vous saisissez le prix.')
            ->modalSubmitActionLabel('Ajouter')
            ->modalWidth(MaxWidth::Large)
            ->form([
                TextInput::make('recherche')
                    ->label('Rechercher un service')
                    ->placeholder('Ex. : ecran')
                    ->live(debounce: 300)
                    ->dehydrated(false)
                    ->visible(fn (): bool => Service::query()->actifs()->exists()),
                CheckboxList::make('services')
                    ->label('Services du magasin')
                    ->options(fn (Get $get): array => self::servicesProposes($get('recherche'), $get('services')))
                    ->visible(fn (): bool => Service::query()->actifs()->exists())
                    ->required(fn (Get $get): bool => blank($get('nouveau_service')))
                    // Pas d'astérisque : un nouveau service tapé plus bas
                    // dispense de cocher.
                    ->markAsRequired(false)
                    ->validationMessages(['required' => 'Cochez au moins un service, ou écrivez le nom d\'un nouveau service.']),
                Placeholder::make('aucun_service_ne_correspond')
                    ->hiddenLabel()
                    ->content('Aucun service ne correspond.')
                    ->visible(fn (Get $get): bool => filled($get('recherche')) && self::servicesProposes($get('recherche'), $get('services')) === []),
                TextInput::make('nouveau_service')
                    ->label(fn (): string => Service::query()->actifs()->exists() ? 'Le service n\'est pas dans la liste ?' : 'Nom du service')
                    ->placeholder('Ex. : Remplacement écran')
                    ->helperText('Il sera ajouté à la liste : la prochaine fois, il suffira de le cocher.')
                    ->maxLength(Service::LONGUEUR_MAXIMALE_NOM)
                    ->rule(fn (): Closure => ServiceResource::regleNomRempli())
                    ->required(fn (): bool => ! Service::query()->actifs()->exists()),
            ])
            ->action(fn (array $data, HasForms $livewire) => self::ajouterLesServices($livewire, $data));
    }

    /**
     * Les services actifs du magasin (MagasinScope) dont le nom contient
     * le terme cherché, sans casse ni accents (« ecran » trouve
     * « Remplacement écran ») ; ceux déjà cochés restent proposés même
     * s'ils ne correspondent plus au terme, pour rester visibles et
     * décochables.
     *
     * @return array<int, string>
     */
    private static function servicesProposes(mixed $recherche, mixed $coches): array
    {
        $recherche = is_string($recherche) ? trim($recherche) : '';
        $coches = array_map('intval', is_array($coches) ? $coches : []);

        return Service::query()
            ->actifs()
            ->when($recherche !== '', fn (Builder $services): Builder => $services->where(
                fn (Builder $services): Builder => RechercheSansAccents::appliquer($services, $recherche, ['nom'])->orWhereKey($coches),
            ))
            ->orderBy('nom')
            ->orderBy('id')
            ->pluck('nom', 'id')
            ->all();
    }

    /**
     * @param  array{services?: list<int|string>|null, nouveau_service?: string|null}  $data
     */
    private static function ajouterLesServices(HasForms $livewire, array $data): void
    {
        $identifiants = array_map('intval', $data['services'] ?? []);
        $nouveauService = Service::nettoyerNom((string) ($data['nouveau_service'] ?? ''));

        if ($nouveauService !== '') {
            try {
                $nouveau = app(TrouverOuCreerServiceAction::class)->handle($nouveauService);
            } catch (InvalidArgumentException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();

                return;
            }

            if ($nouveau->wasRecentlyCreated) {
                Notification::make()
                    ->title("Service « {$nouveau->nom} » ajouté à la liste")
                    ->body('La prochaine fois, il suffira de le cocher.')
                    ->success()
                    ->send();
            } elseif ($nouveau->wasChanged('actif')) {
                Notification::make()
                    ->title("Service « {$nouveau->nom} » de nouveau proposé à la caisse")
                    ->success()
                    ->send();
            }

            $identifiants[] = $nouveau->id;
        }

        $services = Service::query()->whereKey($identifiants)->get()->keyBy('id');

        // Dans l'ordre où le vendeur les a cochés ; le nouveau service en
        // dernier, une seule fois même s'il était déjà coché.
        foreach (array_unique($identifiants) as $identifiant) {
            $service = $services->get($identifiant);

            if ($service !== null) {
                self::ajouterUneLigne($livewire, ['service_id' => $service->id, 'description' => $service->nom]);
            }
        }
    }

    /**
     * Comme le bouton natif du Repeater : la nouvelle ligne reçoit les
     * valeurs par défaut de ses champs (quantité 1, taux du territoire du
     * magasin...), puis celles données ici.
     *
     * @param  array<string, mixed>  $valeurs
     */
    private static function ajouterUneLigne(HasForms $livewire, array $valeurs = []): void
    {
        /** @var Repeater $lignes */
        $lignes = $livewire->getForm('form')->getComponent('data.lignes');

        $uuid = $lignes->generateUuid();

        $lignes->state([...($lignes->getState() ?? []), $uuid => []]);
        $lignes->getChildComponentContainer($uuid)->fill();

        if ($valeurs !== []) {
            $etat = $lignes->getState();
            $etat[$uuid] = [...$etat[$uuid], ...$valeurs];
            $lignes->state($etat);
        }

        $lignes->callAfterStateUpdated();
    }
}
