<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Models\User;
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
 * RefuserLaDemandeDeLienAction). Pour la demande du cabinet que la société
 * a déclaré comme son expert-comptable, « par défaut » veut dire « les
 * autorisations et les magasins de votre déclaration », que la fenêtre
 * d'ajustement reprend (voir declarationRattachee()). Accepter remplace le
 * cabinet déjà lié, et ne valide jamais une société qui attend son code.
 * Aucune méthode publique ne reçoit l'identifiant d'une autre demande.
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
        return ValeursLues::instance(Filament::getTenant(), Societe::class);
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
            'numero_ordre' => ValeursLues::texteOuNul(Cabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $demande->cabinet_id)->value('numero_ordre')),
        ];
    }

    /**
     * Le cabinet qui demande est-il suspendu ? Sa demande ne s'accepte pas
     * tant qu'il l'est ; elle se refuse (décision de l'éditeur du
     * 11/11/2026).
     */
    public function cabinetSuspendu(LienCabinet $demande): bool
    {
        return Societe::query()->whereKey($demande->cabinet_id)->firstOrFail(['id', 'type_activite', 'statut'])->estUnCabinetSuspendu();
    }

    /**
     * La dénomination du cabinet déjà lié, que l'acceptation remplace ;
     * null sans cabinet lié (un expert-comptable déclaré se dit à part,
     * voir texteDeLaDeclaration()).
     */
    public function cabinetActuel(): ?string
    {
        $actif = LienCabinet::query()->where('etat', EtatDuLien::Actif->value)->first(['id', 'cabinet_id']);

        return $actif === null ? null : (ValeursLues::texteOuNul(Societe::query()->whereKey($actif->cabinet_id)->value('raison_sociale')) ?? 'votre cabinet actuel');
    }

    /**
     * La déclaration en attente de la société que l'acceptation de cette
     * demande rattache : celle du SIREN du cabinet qui demande, tant qu'une
     * de ses personnes a un compte actif (voir
     * AccepterLaDemandeDeLienAction::rattacherLaDeclarationDuMemeCabinet()) ;
     * null sinon, la demande s'acceptant alors comme toute autre. Le lien
     * qui en naît garde les autorisations et les magasins de la
     * déclaration, ou ceux que le propriétaire ajuste : « par défaut » y
     * veut dire « ceux de votre déclaration ».
     */
    public function declarationRattachee(LienCabinet $demande): ?LienCabinet
    {
        $declaration = LienCabinet::query()->where('etat', EtatDuLien::Declare->value)->first();

        if ($declaration === null || Societe::query()->whereKey($demande->cabinet_id)->value('siren') !== $declaration->siren_cabinet) {
            return null;
        }

        return app(ExpertsComptablesDeclares::class)->aUnCompteActif($declaration) ? $declaration : null;
    }

    /**
     * Ce que l'acceptation fait de l'expert-comptable que la société a
     * déclaré, s'il y en a un : la demande de son propre cabinet relie la
     * déclaration avec ses autorisations et ses magasins, celle d'un autre
     * cabinet, ou du sien quand plus aucune personne déclarée n'a de compte
     * actif, la remplace ; null sans déclaration en attente.
     */
    public function texteDeLaDeclaration(LienCabinet $demande): ?string
    {
        $declaration = LienCabinet::query()->where('etat', EtatDuLien::Declare->value)->first();

        if ($declaration === null) {
            return null;
        }

        $noms = implode(', ', app(ExpertsComptablesDeclares::class)->comptesDe($declaration)->map(fn (User $compte): string => $compte->name)->all());

        return $this->declarationRattachee($demande) !== null
            ? "Ce cabinet est votre expert-comptable déclaré : accepter le relie à votre société avec les autorisations et les magasins de votre déclaration, que vous pouvez ajuster, et ferme les comptes de {$noms}."
            : 'Accepter remplace votre expert-comptable déclaré, '.$declaration->nomDuCabinetDeclare()." : les comptes de {$noms} seront fermés.";
    }

    /**
     * Ce que « Accepter » accorde, dit dans sa fenêtre : pour le cabinet
     * déclaré, les magasins et les autorisations de la déclaration ; pour
     * un autre cabinet, tous les magasins réels, présents et futurs, et les
     * autorisations par défaut.
     */
    public function texteDeLAcceptation(LienCabinet $demande): string
    {
        $declaration = $this->declarationRattachee($demande);

        if ($declaration === null) {
            return 'Ce cabinet consultera tous vos magasins réels, présents et futurs : '.implode(', ', PermissionRegistry::libellesDe(PermissionRegistry::parDefautDuCabinet($this->societe()->type_activite))).'.';
        }

        $libelles = PermissionRegistry::libellesDe($declaration->autorisations());

        return 'Ce cabinet consultera '.CabinetComptable::magasinsOuvertsEnToutesLettres($declaration, $this->societe()).', avec les autorisations de votre déclaration : '.($libelles === [] ? 'aucune' : implode(', ', $libelles)).'.';
    }

    public function accepterParDefautAction(): Action
    {
        return Action::make('accepterParDefaut')
            ->label(fn (): string => ($demande = $this->demande()) !== null && $this->declarationRattachee($demande) !== null ? 'Accepter avec les autorisations de votre déclaration' : 'Accepter avec les autorisations par défaut')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => ($demande = $this->demande()) === null ? AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE : $this->texteDeLAcceptation($demande))
            ->visible(fn (): bool => $this->demandeAcceptable())
            ->action(fn () => $this->accepter(null));
    }

    /**
     * « Accepter en ajustant les autorisations » : la fenêtre part des
     * autorisations et des magasins par défaut, ou de ceux de la
     * déclaration pour la demande du cabinet que la société a déclaré, si
     * bien que la valider sans rien changer accorde exactement ce que dit
     * « Accepter ».
     */
    public function accepterEnAjustantAction(): Action
    {
        $type = $this->societe()->type_activite;
        $libelles = PermissionRegistry::libellesDuCabinet($type);

        return Action::make('accepterEnAjustant')
            ->label('Accepter en ajustant les autorisations')
            ->visible(fn (): bool => $this->demandeAcceptable())
            ->fillForm(function () use ($type): array {
                $demande = $this->demande();
                $declaration = $demande === null ? null : $this->declarationRattachee($demande);

                return $declaration === null ? CabinetComptable::etatParDefaut($type) : CabinetComptable::etatDuLien($declaration, $type);
            })
            ->form([
                ...CabinetComptable::champsDesMagasins($this->societe(), fn (): bool => true),
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
            ->action(fn (array $data) => $this->accepter(CabinetComptable::autorisationsCocheesDans($data), CabinetComptable::magasinsChoisisDans($data)));
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
     * Y a-t-il une demande en cours, d'un cabinet qui n'est pas suspendu ?
     */
    private function demandeAcceptable(): bool
    {
        $demande = $this->demande();

        return $demande !== null && ! $this->cabinetSuspendu($demande);
    }

    /**
     * @param  list<string>|null  $autorisations
     * @param  list<int>|null  $magasins
     */
    private function accepter(?array $autorisations, ?array $magasins = null): void
    {
        static::executerSansCasser(function () use ($autorisations, $magasins): void {
            app(AccepterLaDemandeDeLienAction::class)->handle(CompteConnecte::exiger(), $this->demande() ?? throw new LogicException(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE), $autorisations, $magasins);
            Notification::make()->title('Ce cabinet est désormais votre cabinet.')->success()->send();
            $this->redirect(CabinetComptable::getUrl());
        });
    }
}
