<?php

namespace App\Filament\App\Livewire;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\MargeDuCatalogue;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\DevisResource;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Le grand tableau des produits (point 13), qu'ouvre « Ajouter un
 * produit » à la caisse, sur une facture ou un devis (voir
 * AjouteDesLignesDeVente::actionAjouterUnProduit()) : les produits du
 * magasin actif (MagasinScope), jamais un produit archivé, 50 à la fois,
 * puis 50 de plus à chaque « Voir 50 de plus » ; une recherche par nom,
 * référence ou code-barres, sans casse ni accents (voir
 * RechercheSansAccents) ; un tri par nom ou des plus récents aux plus
 * anciens (par date d'entrée du produit) ; les filtres « En stock
 * seulement » et, pour qui est autorisé à vendre sans stock dans ce
 * magasin (R1), « Hors stock seulement » ; des colonnes au choix, gardées
 * pour la session.
 *
 * Le coût moyen, le bénéfice unitaire et le taux de marque ne se
 * montrent qu'à qui voit la valeur du stock (réponse de l'éditeur à Q9) :
 * à la caisse, le client voit souvent l'écran. Ce sont ceux de la fiche
 * (voir MargeDuCatalogue), jamais les montants d'un document. Le stock
 * restant, le seuil d'alerte, le fournisseur et la date d'entrée, lus sur
 * la fiche du produit, ne se montrent qu'à qui peut voir les fiches des
 * produits (« Produits et services : voir ») : vendre ne demande que la
 * référence, le nom, le prix et le taux.
 *
 * Un clic sur une rangée ajoute le produit à la sélection, gardée ici,
 * côté serveur, avec sa quantité, ou l'en retire ; un produit épuisé reste
 * visible, grisé, « Épuisé » sous son nom, pour qui ne peut pas le vendre,
 * même sans voir son stock, mais ne se sélectionne pas sans l'autorisation
 * de vendre sans stock (voir AjouteDesLignesDeVente::refusDuProduit()). Le
 * bas de la fenêtre donne le total HT et TTC de la sélection, calculé
 * comme les lignes qu'elle ajoutera, pour le client et la nature de
 * l'opération du document (voir AjouteDesLignesDeVente::apercuDesLignes()) :
 * aucun montant ne se calcule ici. « Ajouter » envoie la sélection à la
 * page (voir RecoitLesProduitsChoisis), qui la revérifie avant d'ajouter
 * ses lignes ; à l'émission, VerificateurDeStock reste le contrôle final
 * du stock.
 */
class SelecteurDeProduits extends Component implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    /**
     * L'événement qui porte la sélection à la page du document.
     */
    public const EVENEMENT = 'produits-choisis';

    public const PAR_PAGE = 50;

    public const TRI_PAR_NOM = 'nom';

    public const TRI_PLUS_RECENTS = 'plus_recents';

    /**
     * Le client du document, dont le calcul des totaux dépend (voir
     * ContexteDeVente).
     */
    #[Locked]
    public ?int $clientId = null;

    /**
     * La nature de l'opération du document ; aucune à la caisse, qui vend
     * en France.
     */
    #[Locked]
    public ?string $nature = null;

    /**
     * Les produits choisis, dans l'ordre des clics, chacun avec sa
     * quantité.
     *
     * @var list<array{produit_id: int, quantite: int}>
     */
    #[Locked]
    public array $selection = [];

    #[Locked]
    public int $nombreAffiche = self::PAR_PAGE;

    public string $tri = self::TRI_PAR_NOM;

    /**
     * La marge de chaque produit affiché, calculée une fois par requête
     * (voir marge()).
     *
     * @var array<int, MargeDuCatalogue>
     */
    private array $marges = [];

    /**
     * L'autorisation de vendre sans stock de la personne connectée, lue une
     * fois par requête (voir vendSansStock()).
     */
    private ?bool $vendSansStock = null;

    /**
     * Ouvert seulement à qui saisit une vente (caisse, facture, devis), à
     * chaque requête : une personne qui perd ce droit, la fenêtre ouverte,
     * n'y fait plus rien.
     */
    public function boot(): void
    {
        abort_unless(DevisResource::peutSaisirUneVente(), 403);
    }

    public function mount(mixed $clientId = null, mixed $nature = null): void
    {
        $this->clientId = is_int($clientId) || (is_string($clientId) && ctype_digit($clientId)) ? (int) $clientId : null;
        $this->nature = is_string($nature) && $nature !== '' ? $nature : null;
    }

    public function table(Table $table): Table
    {
        $voitLesFiches = auth()->user()?->can(PermissionRegistry::PRODUIT_VIEW) ?? false;
        $voitLaValeurDuStock = auth()->user()?->can(PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK) ?? false;
        $vendSansStock = $this->vendSansStock();
        $calculateur = app(CalculateurLigne::class);

        return $table
            ->query(fn (): Builder => Produit::query())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->limit($this->nombreAffiche))
            ->defaultSort(fn (Builder $query): Builder => $this->tri === self::TRI_PLUS_RECENTS
                ? $query->orderByDesc('created_at')->orderByDesc('id')
                : $query->orderBy('nom')->orderBy('id'))
            ->paginated(false)
            ->recordAction('basculer')
            ->recordClasses(fn (Produit $produit): array => [
                'opacity-50' => DevisResource::refusDuProduit($produit, $vendSansStock) !== null,
                'bg-gray-100 dark:bg-white/5 font-semibold' => $this->quantiteChoisie($produit->getKey()) !== null,
            ])
            ->searchPlaceholder('Nom, référence ou code-barres')
            ->emptyStateHeading('Aucun produit ne correspond.')
            ->columns([
                TextColumn::make('choisi')
                    ->label('')
                    ->state(fn (Produit $produit): ?string => ($quantite = $this->quantiteChoisie($produit->getKey())) === null ? null : '× '.$quantite)
                    ->icon('heroicon-m-check-circle')
                    ->color('primary'),
                TextColumn::make('reference')->label('Référence'),
                // Sous le nom, qu'on voie ou non le stock : la rangée grisée
                // dit pourquoi le produit ne se choisit pas, pour que le
                // vendeur le dise au client.
                TextColumn::make('nom')
                    ->label('Nom')
                    ->description(fn (Produit $produit): ?string => DevisResource::refusDuProduit($produit, $vendSansStock) === null ? null : 'Épuisé')
                    ->searchable(query: RechercheSansAccents::dans('nom', 'reference', 'code_barres')),
                TextColumn::make('code_barres')->label('Code-barres')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('taille')->label('Taille')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('couleur')->label('Couleur')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                // Une quantité négative, laissée par une vente sans stock
                // (R1), reste en rouge, « À régulariser », jusqu'à un
                // achat, une réception ou un inventaire.
                TextColumn::make('stock_quantite')
                    ->label('Stock restant')
                    ->formatStateUsing(fn (Produit $produit): string => $produit->suivi_stock ? Number::format($produit->stock_quantite, locale: config('app.locale')) : '—')
                    ->color(fn (Produit $produit): ?string => $produit->estEpuise() ? 'danger' : null)
                    ->description(fn (Produit $produit): ?string => $produit->estEnStockNegatif() ? 'À régulariser' : null)
                    ->visible($voitLesFiches),
                TextColumn::make('seuil_alerte')
                    ->label('Seuil d\'alerte')
                    ->formatStateUsing(fn (Produit $produit): string => $produit->suivi_stock ? (string) $produit->seuil_alerte : '—')
                    ->visible($voitLesFiches)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('prix_vente_ht')
                    ->label('PV HT')
                    ->state(fn (Produit $produit): string => $calculateur->enEuros($this->marge($produit)->prixVenteHt)),
                TextColumn::make('prix_vente_ttc')
                    ->label('PV TTC')
                    ->formatStateUsing(fn (Produit $produit): string => $calculateur->prixUnitaireAffiche($calculateur->enMillioniemes($produit->prix_vente_ttc))),
                TextColumn::make('taux_tva')
                    ->label('TVA')
                    ->formatStateUsing(fn (Produit $produit): string => $this->pourcentage($this->marge($produit)->tauxTva)),
                // Enregistré au dix-millième d'euro, affiché au centime
                // comme tout prix unitaire : 1,0035 € s'affiche 1,00 €.
                TextColumn::make('cout_moyen')
                    ->label('Coût moyen HT')
                    ->state(fn (Produit $produit): string => $calculateur->prixUnitaireAffiche($calculateur->enMillioniemes($produit->cmup_ht)))
                    ->visible($voitLaValeurDuStock)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('benefice')
                    ->label('Bénéfice HT')
                    ->state(fn (Produit $produit): string => $calculateur->enEuros($this->marge($produit)->beneficeUnitaireHt))
                    ->visible($voitLaValeurDuStock)
                    ->toggleable(),
                TextColumn::make('taux_de_marque')
                    ->label('Taux de marque')
                    ->state(fn (Produit $produit): string => ($taux = $this->marge($produit)->tauxDeMarque) === null ? '—' : $this->pourcentage($taux))
                    ->visible($voitLaValeurDuStock)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fournisseur.nom')->label('Fournisseur')->placeholder('—')->visible($voitLesFiches)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Entré le')->dateTime('d/m/Y')->visible($voitLesFiches)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('en_stock')
                    ->label('En stock seulement')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->enStock()),
                Filter::make('hors_stock')
                    ->label('Hors stock seulement')
                    ->toggle()
                    ->visible($vendSansStock)
                    ->query(fn (Builder $query): Builder => $query->epuises()),
            ]);
    }

    /**
     * Le tri choisi, l'un des deux proposés ; tout autre, venu d'une
     * requête forgée, revient au tri par nom.
     */
    public function updatedTri(): void
    {
        if (! in_array($this->tri, [self::TRI_PAR_NOM, self::TRI_PLUS_RECENTS], true)) {
            $this->tri = self::TRI_PAR_NOM;
        }
    }

    /**
     * Un clic sur la rangée : le produit entre dans la sélection, avec une
     * quantité de 1, ou en sort s'il y était. Un produit qui n'est pas au
     * catalogue du magasin (archivé, ou d'un autre magasin) ou que la
     * personne ne peut pas vendre (épuisé, sans l'autorisation de vendre
     * sans stock) n'y entre pas, même par une requête forgée.
     */
    public function basculer(int|string $cle): void
    {
        $identifiant = (int) $cle;

        if ($this->quantiteChoisie($identifiant) !== null) {
            $this->retirer($identifiant);

            return;
        }

        $produit = Produit::query()->find($identifiant);
        $refus = $produit === null ? DevisResource::PRODUIT_INTROUVABLE : DevisResource::refusDuProduit($produit, $this->vendSansStock());

        if ($refus !== null) {
            Notification::make()->title($refus)->danger()->send();

            return;
        }

        $this->selection[] = ['produit_id' => $produit->getKey(), 'quantite' => 1];
    }

    /**
     * La quantité d'un produit choisi, un nombre entier d'articles, au
     * moins 1 ; une saisie qui n'en est pas un laisse la quantité
     * d'avant.
     */
    public function changerLaQuantite(int|string $produitId, mixed $quantite): void
    {
        try {
            $quantite = QuantiteEntiere::depuisSaisie($quantite);
        } catch (InvalidArgumentException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return;
        }

        foreach ($this->selection as $position => $choix) {
            if ($choix['produit_id'] === (int) $produitId) {
                $this->selection[$position]['quantite'] = $quantite;
            }
        }
    }

    public function retirer(int|string $produitId): void
    {
        $this->selection = array_values(array_filter($this->selection, fn (array $choix): bool => $choix['produit_id'] !== (int) $produitId));
    }

    public function voirPlus(): void
    {
        $this->nombreAffiche += self::PAR_PAGE;
    }

    /**
     * D'autres produits correspondent au-delà de ceux affichés : « Voir 50
     * de plus » les montre.
     */
    public function resteDAutresProduits(): bool
    {
        return $this->getFilteredTableQuery()->limit(null)->count() > $this->nombreAffiche;
    }

    /**
     * « Ajouter » : la sélection part à la page du document, qui la
     * revérifie (voir RecoitLesProduitsChoisis) ; un produit devenu
     * épuisé ou archivé depuis son choix l'arrête ici, avec sa raison.
     */
    public function ajouterLaSelection(): void
    {
        try {
            DevisResource::produitsChoisis($this->selection);
        } catch (InvalidArgumentException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return;
        }

        $this->dispatch(self::EVENEMENT, selection: $this->selection);
    }

    /**
     * Les produits choisis encore au catalogue du magasin, dans l'ordre
     * des clics, avec leur quantité.
     *
     * @return list<array{produit: Produit, quantite: int}>
     */
    public function produitsSelectionnes(): array
    {
        $produits = Produit::query()->whereKey(array_column($this->selection, 'produit_id'))->get()->keyBy('id');

        return array_values(array_filter(array_map(
            fn (array $choix): ?array => $produits->has($choix['produit_id']) ? ['produit' => $produits->get($choix['produit_id']), 'quantite' => $choix['quantite']] : null,
            $this->selection,
        )));
    }

    /**
     * Le bas de la fenêtre : « 3 produits sélectionnés · Total HT 124,17 €
     * · Total TTC 149,00 € », le total des produits choisis calculé comme
     * les lignes qu'ils ajouteront au document, au prix de leur fiche, pour
     * son client et la nature de son opération (voir
     * AjouteDesLignesDeVente::apercuDesLignes()).
     *
     * @param  list<array{produit: Produit, quantite: int}>  $choisis  voir produitsSelectionnes()
     */
    public function resumeDeLaSelection(array $choisis): string
    {
        $calculateur = app(CalculateurLigne::class);
        $totaux = DevisResource::apercuDesLignes(
            array_map(fn (array $choisi): array => DevisResource::ligneDuProduit($choisi['produit'], $choisi['quantite']), $choisis),
            $this->clientId,
            $this->nature,
        )->totaux;

        return match (count($choisis)) {
            0 => 'Aucun produit sélectionné',
            1 => '1 produit sélectionné',
            default => count($choisis).' produits sélectionnés',
        }.' · Total HT '.$calculateur->enEuros($calculateur->enCentiemes($totaux['total_ht']))
            .' · Total TTC '.$calculateur->enEuros($calculateur->enCentiemes($totaux['total_ttc']));
    }

    public function render(): View
    {
        return view('filament.app.livewire.selecteur-de-produits');
    }

    private function quantiteChoisie(int $produitId): ?int
    {
        foreach ($this->selection as $choix) {
            if ($choix['produit_id'] === $produitId) {
                return $choix['quantite'];
            }
        }

        return null;
    }

    private function vendSansStock(): bool
    {
        return $this->vendSansStock ??= DevisResource::peutVendreSansStock();
    }

    private function marge(Produit $produit): MargeDuCatalogue
    {
        return $this->marges[$produit->getKey()] ??= MargeDuCatalogue::de($produit, app(TenantContext::class)->magasin()->societe->estEnFranchiseDeTva());
    }

    /**
     * Un taux en centièmes de point tel qu'on le lit, avec ses décimales
     * utiles : « 20 % », « 5,5 % », « 60,01 % », « -4,8 % ».
     */
    private function pourcentage(int $centiemes): string
    {
        $decimales = rtrim(sprintf('%02d', abs($centiemes) % 100), '0');

        return ($centiemes < 0 ? '-' : '').intdiv(abs($centiemes), 100).($decimales === '' ? '' : ','.$decimales).' %';
    }
}
