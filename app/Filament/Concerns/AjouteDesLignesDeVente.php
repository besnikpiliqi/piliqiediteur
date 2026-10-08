<?php

namespace App\Filament\Concerns;

use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Service;
use App\Filament\App\Resources\ServiceResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\MaxWidth;
use InvalidArgumentException;

/**
 * Les lignes d'une vente (caisse, facture, devis) : chacune vend un produit
 * du catalogue ou un service du magasin, jamais une ligne libre (voir
 * ConstructeurLigneDocument). Deux boutons sous les lignes les ajoutent.
 */
trait AjouteDesLignesDeVente
{
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
     * une ligne dont le vendeur saisit le prix TTC.
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
            ->modalDescription('Cochez les services réalisés : chacun devient une ligne dont vous saisissez le prix TTC.')
            ->modalSubmitActionLabel('Ajouter')
            ->modalWidth(MaxWidth::Large)
            ->form([
                CheckboxList::make('services')
                    ->label('Services du magasin')
                    ->options(fn (): array => Service::query()->actifs()->orderBy('nom')->pluck('nom', 'id')->all())
                    ->searchable()
                    ->noSearchResultsMessage('Aucun service ne correspond.')
                    ->visible(fn (): bool => Service::query()->actifs()->exists())
                    ->required(fn (Get $get): bool => blank($get('nouveau_service')))
                    // Pas d'astérisque : un nouveau service tapé plus bas
                    // dispense de cocher.
                    ->markAsRequired(false)
                    ->validationMessages(['required' => 'Cochez au moins un service, ou écrivez le nom d\'un nouveau service.']),
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
     * valeurs par défaut de ses champs (quantité 1, TVA 20 %...), puis
     * celles données ici.
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
