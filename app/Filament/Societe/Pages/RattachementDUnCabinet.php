<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * La page de rattachement d'une demande d'un cabinet d'expertise
 * comptable, dans l'espace de la société visée, pour son propriétaire (voir
 * SocietePolicy::gererSonCabinet()) : le lien de l'e-mail y mène après sa
 * connexion (voir RattachementAuCabinetController), le bouton « Répondre »
 * de la page « Cabinet comptable » aussi. La demande se lit dans la
 * société courante par son uuid : une demande d'une autre société, ou qui
 * n'est plus en cours, ou expirée, donne seulement « Cette demande n'est
 * plus valable. ». La page montre le cabinet qui demande et trois choix :
 * accepter avec les autorisations par défaut, accepter en les ajustant
 * (jamais la vente, l'encaissement, la caisse, les comptes ni les
 * réglages), ou refuser (voir AccepterLaDemandeDeLienAction et
 * RefuserLaDemandeDeLienAction). Accepter remplace le cabinet déjà lié, et
 * ne valide jamais une société qui attend son code. Aucune méthode
 * publique ne reçoit l'identifiant d'une autre demande.
 */
class RattachementDUnCabinet extends Page
{
    use GereLesExceptionsMetier;

    protected static ?string $slug = 'rattachement-cabinet/{lien}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Demande de rattachement';

    protected static string $view = 'filament.societe.pages.rattachement-d-un-cabinet';

    #[Locked]
    public string $lien = '';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('gererSonCabinet', $societe) ?? false);
    }

    public function mount(string $lien): void
    {
        $this->lien = $lien;
    }

    public function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * La demande de la page, encore en cours et non expirée, dans la
     * société courante ; null sinon.
     */
    public function demande(): ?LienCabinet
    {
        $demande = LienCabinet::query()->parUuid($this->lien)->where('etat', EtatDuLien::DemandeParLeCabinet->value)->first();

        return $demande?->estUneDemandeValable() ? $demande : null;
    }

    /**
     * Le cabinet qui demande : sa dénomination, son SIREN, la ville de son
     * siège et son n° d'inscription à l'Ordre.
     *
     * @return array{societe: Societe, numero_ordre: string|null}
     */
    public function cabinet(LienCabinet $demande): array
    {
        return [
            'societe' => Societe::query()->whereKey($demande->cabinet_id)->firstOrFail(['id', 'siren', 'raison_sociale', 'siege_code_postal', 'siege_ville']),
            'numero_ordre' => Cabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $demande->cabinet_id)->value('numero_ordre'),
        ];
    }

    /**
     * La dénomination du cabinet déjà lié, que l'acceptation remplace.
     */
    public function cabinetActuel(): ?string
    {
        $actif = LienCabinet::query()->where('etat', EtatDuLien::Actif->value)->first(['id', 'cabinet_id']);

        return $actif === null ? null : (Societe::query()->whereKey($actif->cabinet_id)->value('raison_sociale') ?? 'votre cabinet actuel');
    }

    /**
     * Les libellés des autorisations par défaut.
     *
     * @return list<string>
     */
    public function autorisationsParDefaut(): array
    {
        $type = $this->societe()->type_activite;

        return array_values(array_intersect_key(PermissionRegistry::libellesDuCabinet($type), array_flip(PermissionRegistry::parDefautDuCabinet($type))));
    }

    public function accepterParDefautAction(): Action
    {
        return Action::make('accepterParDefaut')
            ->label('Accepter avec les autorisations par défaut')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'Ce cabinet consultera vos magasins réels : '.implode(', ', $this->autorisationsParDefaut()).'.')
            ->visible(fn (): bool => $this->demande() !== null)
            ->action(fn () => $this->accepter(null));
    }

    public function accepterEnAjustantAction(): Action
    {
        $type = $this->societe()->type_activite;
        $libelles = PermissionRegistry::libellesDuCabinet($type);

        return Action::make('accepterEnAjustant')
            ->label('Accepter en ajustant les autorisations')
            ->visible(fn (): bool => $this->demande() !== null)
            ->fillForm(['consultations' => PermissionRegistry::parDefautDuCabinet($type), 'achats' => [], 'telechargements' => []])
            ->form([
                CheckboxList::make('consultations')
                    ->label('Consulter')
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::parDefautDuCabinet($type))))
                    ->columns(2),
                CheckboxList::make('achats')
                    ->label('Achats et stock')
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::ajoutablesAuCabinet($type))))
                    ->columns(2)
                    ->hidden(PermissionRegistry::ajoutablesAuCabinet($type) === []),
                CheckboxList::make('telechargements')
                    ->label('Téléchargement des documents par période')
                    ->helperText(CabinetComptable::AIDE_TELECHARGEMENTS)
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::cocheesParLeProprietaire($type)))),
                Placeholder::make('jamais')->hiddenLabel()->content(CabinetComptable::TEXTE_JAMAIS),
                Placeholder::make('vide')->hiddenLabel()->content('Sans aucune autorisation, votre cabinet reste lié et voit votre identité, vos réglages fiscaux et vos magasins, mais n\'ouvre aucun document, aucune fiche ni aucun téléchargement.'),
            ])
            ->action(fn (array $data) => $this->accepter(array_values(array_unique([...($data['consultations'] ?? []), ...($data['achats'] ?? []), ...($data['telechargements'] ?? [])]))));
    }

    public function refuserAction(): Action
    {
        return Action::make('refuser')
            ->label('Refuser')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Ce cabinet est prévenu que vous avez refusé.')
            ->visible(fn (): bool => $this->demande() !== null)
            ->action(function (): void {
                static::executerSansCasser(function (): void {
                    app(RefuserLaDemandeDeLienAction::class)->handle(CompteConnecte::exiger(), $this->demande() ?? throw new LogicException(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE));
                    Notification::make()->title('Vous avez refusé cette demande.')->success()->send();
                    $this->redirect(CabinetComptable::getUrl());
                });
            });
    }

    /**
     * @param  list<string>|null  $autorisations
     */
    private function accepter(?array $autorisations): void
    {
        static::executerSansCasser(function () use ($autorisations): void {
            app(AccepterLaDemandeDeLienAction::class)->handle(CompteConnecte::exiger(), $this->demande() ?? throw new LogicException(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE), $autorisations);
            Notification::make()->title('Ce cabinet est désormais votre cabinet.')->success()->send();
            $this->redirect(CabinetComptable::getUrl());
        });
    }
}
