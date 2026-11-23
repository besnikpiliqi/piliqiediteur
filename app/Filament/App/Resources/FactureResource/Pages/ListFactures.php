<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\FacturerLeStockAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VenteDuStockSeul;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SignaleUneLigneDisparue;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * La liste des factures et des tickets du magasin. Un magasin que sa
 * société a fermé sans le céder, en lecture seule, n'y crée plus rien :
 * « Facturer le stock » y est le seul geste de vente qui reste, pour qui
 * est autorisé à créer et à émettre une facture dans ce magasin (voir
 * FacturePolicy::facturerLeStock() et VenteDuStockSeul).
 */
class ListFactures extends ListRecords
{
    use GereLesExceptionsMetier, SignaleUneLigneDisparue;

    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            $this->facturerLeStock(),
        ];
    }

    /**
     * « Facturer le stock », tant qu'il reste un produit en stock à
     * facturer : prépare le brouillon de la facture du stock
     * vendu seul du magasin fermé (voir FacturerLeStockAction), à un client
     * professionnel, pour les produits en stock choisis (tous au départ),
     * chacun pour tout ce qu'il en reste ; une remise sur toutes les lignes
     * pour qui est autorisé à en accorder. Le brouillon s'ouvre ensuite : il
     * s'y émet et s'y encaisse comme toute facture, ou se supprime.
     */
    private function facturerLeStock(): Actions\Action
    {
        return Actions\Action::make('facturerLeStock')
            ->label('Facturer le stock')
            ->icon('heroicon-o-building-storefront')
            ->visible(fn (): bool => CompteConnecte::exiger()->can('facturerLeStock', Facture::class) && self::produitsEnStock() !== [])
            ->modalHeading('Facturer le stock du magasin')
            ->modalDescription('Ce magasin est fermé : cette facture du stock vendu seul, avec TVA, est la seule vente qu\'il fasse encore. Elle reprend tout ce qui reste en stock des produits choisis, à leur prix de vente et au taux de TVA de leur fiche. Le stock que vous ne facturez pas sortira à la cession du magasin, sans facture.')
            ->modalSubmitActionLabel('Préparer la facture')
            ->form([
                Select::make('client_id')
                    ->label('Client professionnel')
                    ->helperText('Un client professionnel du magasin : jamais un particulier ni le client comptoir.')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => RechercheSansAccents::appliquer(self::clientsProfessionnels(), $search, ClientResource::colonnesDeRecherche())->orderBy('nom')->orderBy('id')->limit(50)->pluck('nom', 'id')->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ValeursLues::texteOuNul(self::clientsProfessionnels()->whereKey(ValeursLues::cle($value))->value('nom'))),
                CheckboxList::make('produits')
                    ->label('Produits à facturer')
                    ->helperText('Les produits qui suivent leur stock et en ont : chacun est facturé pour tout ce qu\'il en reste.')
                    ->options(fn (): array => self::produitsEnStock())
                    ->default(fn (): array => array_keys(self::produitsEnStock()))
                    ->required()
                    ->minItems(1)
                    ->searchable()
                    ->bulkToggleable(),
                TextInput::make('remise_pourcentage')
                    ->label('Remise sur chaque ligne (%)')
                    ->helperText('Facultative : le même pourcentage sur chaque produit.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step('0.01')
                    ->visible(fn (): bool => self::peutAccorderUneRemise()),
            ])
            ->action(fn (array $data) => static::executerLeGeste(function () use ($data): void {
                $facture = app(FacturerLeStockAction::class)->handle(
                    CompteConnecte::exiger(),
                    Client::query()->whereKey(ValeursLues::cle($data['client_id']))->firstOrFail(),
                    array_values(array_map(ValeurSimple::enTexte(...), ValeursLues::tableau($data['produits']))),
                    ValeurSimple::enTexteOuNul($data['remise_pourcentage'] ?? null),
                );

                Notification::make()->title('La facture du stock est prête : relisez-la, puis émettez-la.')->success()->send();
                $this->redirect(FactureResource::getUrl('view', ['record' => $facture]));
            }));
    }

    /**
     * Les clients professionnels que la vente propose, jamais le client
     * comptoir.
     *
     * @return Builder<Client>
     */
    private static function clientsProfessionnels(): Builder
    {
        return Client::query()->proposables()->where('type_client', TypeClient::Professionnel);
    }

    /**
     * Les produits en stock du magasin, non archivés, avec ce qu'il en
     * reste, par référence.
     *
     * @return array<int, string>
     */
    private static function produitsEnStock(): array
    {
        return Produit::query()
            ->where('suivi_stock', true)
            ->where('stock_quantite', '>', 0)
            ->orderBy('reference')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Produit $produit): array => [CleDuModele::de($produit) => "{$produit->reference} — {$produit->nom} ({$produit->stock_quantite} en stock)"])
            ->all();
    }

    /**
     * Le compte peut-il accorder une remise dans ce magasin, tenu pour
     * ouvert le temps de la question (voir VenteDuStockSeul) ?
     */
    private static function peutAccorderUneRemise(): bool
    {
        $magasin = ValeursLues::instance(Filament::getTenant(), Magasin::class);

        return app(VenteDuStockSeul::class)->commeSiOuvert($magasin, fn (): bool => CompteConnecte::exiger()->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
    }
}
