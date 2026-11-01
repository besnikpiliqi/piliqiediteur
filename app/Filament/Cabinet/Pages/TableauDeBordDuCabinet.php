<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\TableauDeBordDesClients;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Cabinet\Resources\CollaborateurResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le tableau de bord d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet (voir CabinetPanelProvider), où arrive son propriétaire après la
 * confirmation de son inscription et à chaque connexion : en tête,
 * « Bonjour » et le rôle de la personne dans le cabinet (voir
 * User::libelleDuRoleDeSociete()) ; pour le propriétaire seul, tant que le
 * cabinet n'est pas vérifié, la liste de ses étapes, chacune avec son état
 * et l'écran qui la fait (voir etapes()) ; où en est sa vérification ; ses
 * clients liés que le cabinet ouvre au compte, chacun avec le bouton qui
 * ouvre sa fiche ; pour le propriétaire d'un cabinet qui n'est plus
 * vérifié, ses seuls liens actifs, pour y mettre fin (voir
 * getViewData()). Aucune méthode publique ne reçoit l'identifiant d'un
 * client ou d'un magasin : un geste relit son lien parmi ceux du cabinet. Ni magasin ni démonstration : un cabinet n'en a aucun.
 */
class TableauDeBordDuCabinet extends Dashboard
{
    use GereLesExceptionsMetier;

    public const ETAPE_CODE_DU_COURRIER = 'Saisir le code du courrier recommandé';

    public const ETAPE_ACTIVITE = 'Faire contrôler votre code d\'activité 69.20Z';

    public const ETAPE_ORDRE = 'Faire vérifier votre inscription au tableau de l\'Ordre';

    public const ETAPE_COLLABORATEURS = 'Créer vos collaborateurs';

    public const ETAPE_CLIENTS = 'Inviter vos clients';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static string $view = 'filament.cabinet.pages.tableau-de-bord-du-cabinet';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && $societe->estUnCabinet() && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->cabinet()->raison_sociale ?? 'Votre cabinet';
    }

    public function cabinet(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * L'état de la vérification du cabinet, tel que l'écran le dit.
     */
    public function etatDeLaVerification(): string
    {
        $cabinet = $this->cabinet();

        return match (true) {
            $cabinet->estClose() => 'L\'inscription de votre cabinet est close.',
            $cabinet->estUnCabinetVerifie() => 'Votre cabinet est validé, contrôlé 69.20Z et vérifié à l\'Ordre : il peut inviter et lier ses clients.',
            $cabinet->estValidee() => 'Votre cabinet est validé depuis le '.Calendrier::deParis()->afficher($cabinet->validee_le, 'd/m/Y').' : il invitera et liera ses clients une fois son code d\'activité contrôlé et son inscription à l\'Ordre vérifiée.',
            default => 'Votre cabinet attend le code de son courrier recommandé.',
        };
    }

    /**
     * Les étapes du propriétaire du cabinet, dans l'ordre : saisir le code
     * du courrier recommandé, qui valide le cabinet, faire contrôler son
     * code d'activité, faire vérifier son inscription au tableau de
     * l'Ordre, créer ses collaborateurs, inviter ses clients. Chacune dit si elle est faite, où
     * elle en est, et mène à l'écran qui la fait. Aucune pour un
     * collaborateur.
     *
     * @return list<array{titre: string, faite: bool, etat: string, url: string|null}>
     */
    public function etapes(): array
    {
        if (! auth()->user()->estProprietaire()) {
            return [];
        }

        $cabinet = $this->cabinet();
        $verification = $cabinet->cabinet()->first();
        $page = VerificationDuCabinet::getUrl();

        return [
            [
                'titre' => self::ETAPE_CODE_DU_COURRIER,
                'faite' => $cabinet->estValidee(),
                'etat' => $this->prochaineEtapeDuCourrier(),
                'url' => $page,
            ],
            [
                'titre' => self::ETAPE_ACTIVITE,
                'faite' => $verification?->aSonActiviteControlee() ?? false,
                'etat' => ($verification?->aSonActiviteControlee() ?? false)
                    ? 'Contrôlé : '.($verification->code_activite ?? 'code donné à l\'éditeur').'.'
                    : 'Le répertoire Sirene ne donne pas votre code dans la nomenclature attendue : l\'éditeur le contrôle avec votre inscription à l\'Ordre.',
                'url' => $page,
            ],
            [
                'titre' => self::ETAPE_ORDRE,
                'faite' => $verification?->estVerifieALOrdre() ?? false,
                'etat' => match (true) {
                    $verification === null => 'À demander.',
                    $verification->estVerifieALOrdre() => 'Vérifiée le '.Calendrier::deParis()->afficher($verification->ordre_verifie_le, 'd/m/Y').'.',
                    $verification->ordre_retire_le !== null => 'Radiation constatée par l\'éditeur le '.Calendrier::deParis()->afficher($verification->ordre_retire_le, 'd/m/Y').'.',
                    $verification->attendLEditeur() => 'Demandée le '.Calendrier::deParis()->afficher($verification->ordre_demande_le, 'd/m/Y').' : l\'éditeur la vérifie.',
                    $verification->ordre_refuse_le !== null => "Refusée : {$verification->ordre_motif_refus}",
                    default => 'Donnez votre n° d\'inscription au tableau et votre conseil régional.',
                },
                'url' => $page,
            ],
            [
                'titre' => self::ETAPE_COLLABORATEURS,
                'faite' => ($collaborateurs = CollaborateurResource::getEloquentQuery()->where('users.statut', '<>', StatutCompte::Retire->value)->count()) > 0,
                'etat' => $collaborateurs > 0 ? trans_choice('{1} 1 collaborateur|[2,*] :count collaborateurs', $collaborateurs) : 'Un compte par collaborateur, qui ne verra que les clients que vous lui confierez.',
                'url' => CollaborateurResource::getUrl('index'),
            ],
            [
                'titre' => self::ETAPE_CLIENTS,
                'faite' => ($clients = app(AccesDesCabinets::class)->liensDuCompte(auth()->user())->count()) > 0,
                'etat' => $clients > 0 ? trans_choice('{1} 1 client lié|[2,*] :count clients liés', $clients) : 'Par leur SIREN, une fois votre cabinet vérifié : chacun accepte et règle ce que votre cabinet peut faire.',
                'url' => InvitationsEtDemandes::getUrl(),
            ],
        ];
    }

    /**
     * Ce que la vue montre, hors de toute méthode publique : le navigateur,
     * qui peut appeler toute méthode publique d'une page Livewire avec les
     * paramètres qu'il veut, n'obtient jamais un client qu'il désignerait
     * lui-même. Les clients liés que le cabinet ouvre au compte connecté,
     * chacun avec son identité réduite, ses magasins réels, ses factures à
     * encaisser quand il les lui ouvre et l'adresse de sa fiche (voir
     * TableauDeBordDesClients), aucun tant que le cabinet n'est pas
     * vérifié ; pour le seul propriétaire d'un cabinet qui n'est plus
     * vérifié ou plus actif, ses liens actifs, pour y mettre fin (voir
     * AccesDesCabinets::liensActifsDuCabinet()).
     *
     * @return array{clients: list<array{lien: string, url: string, client: Societe, actif_depuis: mixed, magasins: int, factures: array{nombre: int, restant_du: int, nombre_en_retard: int, restant_du_en_retard: int}|null}>, liensActifs: Collection<int, LienCabinet>}
     */
    protected function getViewData(): array
    {
        $cabinet = $this->cabinet();
        $compte = auth()->user();

        return [
            'clients' => array_map(
                fn (array $ligne): array => [...$ligne, 'url' => ClientDuCabinetResource::getUrl('view', ['record' => $ligne['lien']])],
                app(TableauDeBordDesClients::class)->pour($compte),
            ),
            'liensActifs' => $compte->estProprietaire() && ! $cabinet->estUnCabinetVerifie()
                ? app(AccesDesCabinets::class)->liensActifsDuCabinet($cabinet)
                : new Collection,
        ];
    }

    /**
     * Mettre fin à un lien actif d'un cabinet qui n'est plus vérifié : le
     * lien se relit parmi ceux du cabinet par son uuid, et l'action le
     * contrôle de nouveau (voir MettreFinAuLienAction).
     */
    public function mettreFinAuLienAction(): Action
    {
        return Action::make('mettreFinAuLien')
            ->label('Mettre fin au lien')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Ce client et votre cabinet ne sont plus liés : il pourra en lier un autre.')
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    $lien = LienCabinet::query()->duCabinet($this->cabinet())->parUuid((string) ($arguments['uuid'] ?? ''))->firstOrFail();
                    app(MettreFinAuLienAction::class)->handle(auth()->user(), $lien);
                    Notification::make()->title('Le lien a pris fin.')->success()->send();
                });
            });
    }

    /**
     * Reste-t-il une étape à faire ? La liste ne se montre qu'au
     * propriétaire, tant qu'il en reste une.
     */
    public function resteDesEtapes(): bool
    {
        return collect($this->etapes())->contains(fn (array $etape): bool => ! $etape['faite']);
    }

    /**
     * Où en est le courrier, et ce qu'il reste à faire : saisir son code,
     * jusqu'à quand.
     */
    private function prochaineEtapeDuCourrier(): string
    {
        $cabinet = $this->cabinet();

        return match (true) {
            $cabinet->estValidee() => 'Validé le '.Calendrier::deParis()->afficher($cabinet->validee_le, 'd/m/Y').'.',
            $cabinet->estClose() => 'Inscription close.',
            default => 'Le courrier recommandé est parti au représentant légal de votre cabinet, à l\'adresse de son siège : saisissez son code avant le '.Calendrier::deParis()->afficher(app(CourriersDeVerification::class)->cloturePrevueLe($cabinet), 'd/m/Y').'.',
        };
    }
}
