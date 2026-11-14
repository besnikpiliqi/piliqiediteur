<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\FacturesAEncaisserDeLaSociete;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\VerifierUnEcartSireneAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\EcartSirene;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le tableau de bord de la société, où arrive son propriétaire après le
 * lien de son inscription : en tête, « Bonjour » et le rôle de la personne
 * dans la société (voir User::libelleDuRoleDeSociete()) ; ce qui y
 * attend la personne (voir chosesAFaireDeLaSociete()) ; pour le
 * propriétaire seul, la liste de ses premières étapes, chacune avec son
 * état et l'écran qui la fait (voir etapes()), tant qu'il en reste une à
 * faire ; où en est sa vérification ; ce qui manque à son identité pour
 * facturer un professionnel ; ses magasins réels de la portée de la
 * personne connectée (tous pour le propriétaire et un administrateur de
 * tous les magasins, ceux de sa sélection pour un autre administrateur),
 * chacun avec son SIRET, son établissement (vérifié ou à vérifier), son
 * état et, quand la personne y voit toutes les factures, le nombre, le
 * restant dû et le retard de ses factures à encaisser (voir
 * facturesAEncaisser()), qui mènent à leur liste dans le magasin, et ce
 * qui y reste à faire, en nombres par groupe, qui mène à son tableau de
 * bord (voir chosesAFaire()) ; pour un
 * administrateur autorisé à nommer les responsables, où le faire (voir
 * peutNommerUnResponsable()) ; pour le propriétaire, son cabinet
 * comptable et les demandes de rattachement en cours (voir
 * cabinetComptable()) ; et son magasin de démonstration, ouvert ici
 * tant que la société est en cours de vérification (voir
 * OuvrirLaDemonstrationAction). Les magasins et les comptes se lisent dans
 * la société affichée : ceux d'aucune autre société.
 */
class TableauDeBord extends Dashboard
{
    use GereLesExceptionsMetier;

    public const ETAPE_CODE_DU_COURRIER = 'Saisir le code du courrier recommandé';

    public const ETAPE_PREMIER_MAGASIN = 'Créer votre premier magasin';

    public const ETAPE_AJOUTER_UN_MAGASIN = 'Ajouter un magasin';

    public const ETAPE_EQUIPE = 'Ajouter votre équipe';

    public const ETAPE_DEMONSTRATION = 'Essayer le magasin de démonstration';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static string $view = 'filament.societe.pages.tableau-de-bord';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->societe()->raison_sociale ?? 'Votre société';
    }

    public function societe(): Societe
    {
        return ValeursLues::instance(Filament::getTenant(), Societe::class);
    }

    /**
     * L'état de la vérification de la société, tel que l'écran le dit, les
     * achats seulement pour une société qui a des articles. Un cabinet
     * d'expertise comptable n'ouvre pas cet espace : il a le sien (voir
     * TableauDeBordDuCabinet).
     */
    public function etatDeLaVerification(): string
    {
        $societe = $this->societe();

        return match (true) {
            $societe->estClose() => 'L\'inscription de votre société est close.',
            $societe->estValidee() => $societe->phraseDeSaValidation(),
            default => 'Votre société attend le code de son courrier recommandé : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation. Vous pouvez déjà créer vos magasins et les configurer : '
                .($societe->aDesArticles() ? 'catalogue, équipe, paramètres et achats' : 'catalogue, équipe et paramètres')
                .', et vous essayer dans le magasin de démonstration.',
        };
    }

    /**
     * Les premières étapes de la société, celles de son propriétaire, dans
     * l'ordre : saisir le code du courrier recommandé, qui valide la
     * société ; créer son premier magasin, puis, dès le premier magasin
     * réel, quel que soit son statut, en ajouter un autre (l'étape reste
     * cochée, avec le nombre de magasins, sans ordinal, qui deviendrait
     * faux avec les magasins fermés ou cédés) ; ajouter son équipe ;
     * essayer le magasin de démonstration. Une société validée par
     * l'invitation de son cabinet d'expertise comptable n'a jamais reçu de
     * courrier ni eu de démonstration : ses étapes sont seulement celles
     * des magasins et de l'équipe (décision de l'éditeur du 01/11/2026).
     * Chacune dit si elle est faite, où elle en est, et mène à l'écran qui
     * la fait quand il peut l'ouvrir : l'étape des magasins à la création
     * d'un magasin quand le compte peut en créer un, sinon à leur liste ;
     * l'essai de la démonstration se fait par le bouton de l'étape (voir
     * ouvrirLaDemonstrationAction()). Une fois la société validée, la
     * démonstration disparaît de tous ses écrans (T115, lot DEMO-V) : son
     * étape n'est plus dans la liste. Aucune pour un
     * administrateur, qui ne voit jamais ici le nombre des magasins ni
     * celui des comptes de la société. Les magasins et les comptes se
     * comptent dans la portée du propriétaire (voir
     * User::magasinsDeSaPortee() et MembreResource::getEloquentQuery()) :
     * toute la société, sans son propre compte.
     *
     * @return list<array{titre: string, faite: bool, etat: string, url: string|null}>
     */
    public function etapes(): array
    {
        $compte = CompteConnecte::exiger();

        if (! $compte->estProprietaire()) {
            return [];
        }

        $societe = $this->societe();
        $parSonCabinet = $societe->aEteValideeParLInvitationDUnCabinet();
        $magasins = $compte->magasinsDeSaPortee()->where('est_demonstration', false)->count();
        $comptes = MembreResource::getEloquentQuery()->whereKeyNot($compte->getKey())->where('statut', '!=', StatutCompte::Retire->value)->where('role_societe', '<>', RoleSociete::ExpertComptable->value)->count();
        $magasinsEtEquipe = [
            [
                'titre' => $magasins === 0 ? self::ETAPE_PREMIER_MAGASIN : self::ETAPE_AJOUTER_UN_MAGASIN,
                'faite' => $magasins > 0,
                'etat' => $magasins > 0 ? trans_choice('{1} 1 magasin|[2,*] :count magasins', $magasins) : 'Choisissez son établissement dans la liste de ceux de votre SIREN.',
                'url' => match (true) {
                    MagasinResource::canCreate() => MagasinResource::getUrl('create'),
                    MagasinResource::canViewAny() => MagasinResource::getUrl('index'),
                    default => null,
                },
            ],
            [
                'titre' => self::ETAPE_EQUIPE,
                'faite' => $comptes > 0,
                'etat' => $comptes > 0 ? trans_choice('{1} 1 compte en plus du vôtre|[2,*] :count comptes en plus du vôtre', $comptes) : 'Créez les comptes de vos administrateurs, responsables, vendeurs et observateurs.',
                'url' => MembreResource::canViewAny() ? MembreResource::getUrl('index') : null,
            ],
        ];

        if ($parSonCabinet) {
            return $magasinsEtEquipe;
        }

        $code = [
            'titre' => self::ETAPE_CODE_DU_COURRIER,
            'faite' => $societe->estValidee(),
            'etat' => $this->prochaineEtapeDeLaVerification(),
            'url' => VerificationSociete::getUrl(),
        ];

        if ($societe->estValidee()) {
            return [$code, ...$magasinsEtEquipe];
        }

        $demonstration = $societe->demonstration()->first();

        return [
            $code,
            ...$magasinsEtEquipe,
            [
                'titre' => self::ETAPE_DEMONSTRATION,
                'faite' => $demonstration !== null,
                'etat' => match (true) {
                    $demonstration?->statut === StatutMagasin::Actif => 'Ouvert : vous vous y entraînez à vendre, encaisser et clôturer, et votre équipe aussi, avec des comptes d\'entraînement créés ici : ils ne servent qu\'ici et sont retirés à la validation de votre société.',
                    $demonstration !== null => 'Terminé.',
                    default => 'Un magasin d\'entraînement, sans valeur, à essayer avant la validation.',
                },
                'url' => $demonstration?->statut === StatutMagasin::Actif && $compte->peutOuvrir($demonstration) ? Dashboard::getUrl(panel: 'app', tenant: $demonstration) : null,
            ],
        ];
    }

    /**
     * Pour le propriétaire qui gère son cabinet comptable (voir
     * CabinetComptable) : le cabinet lié (« Cabinet comptable : … »), ou
     * l'expert-comptable déclaré qui attend l'espace de son cabinet, ou
     * null, et chaque demande de rattachement en cours d'un cabinet, avec
     * le lien de sa page de rattachement. Ce n'est pas une étape de la
     * liste.
     *
     * @return array{lie: string|null, demandes: list<array{cabinet: string, url: string}>}
     */
    public function cabinetComptable(): array
    {
        $enCours = LienCabinet::query()->enCours()->first();
        $demandes = LienCabinet::query()->where('etat', EtatDuLien::DemandeParLeCabinet->value)->where('expire_le', '>', now())->orderBy('demande_le')->get(['id', 'uuid', 'cabinet_id']);
        $denomination = fn (int $cabinet): string => ValeursLues::texteOuNul(Societe::query()->whereKey($cabinet)->value('raison_sociale')) ?? 'Un cabinet';
        $personnes = $enCours?->estDeclare() === true ? app(ExpertsComptablesDeclares::class)->comptesDe($enCours)->count() : 0;

        return [
            'lie' => match (true) {
                $enCours === null => null,
                $enCours->estDeclare() => 'Cabinet comptable : votre expert-comptable, '.$enCours->nomDuCabinetDeclare().', déclaré, en attente de son espace ('.$personnes.' '.($personnes > 1 ? 'personnes' : 'personne').')',
                default => 'Cabinet comptable : '.$denomination((int) $enCours->cabinet_id),
            },
            'demandes' => array_values($demandes->map(fn (LienCabinet $demande): array => [
                'cabinet' => $denomination((int) $demande->cabinet_id),
                'url' => RattachementDUnCabinet::getUrl(['lien' => $demande->uuid]),
            ])->all()),
        ];
    }

    /**
     * Reste-t-il une première étape à faire ? La liste ne se montre qu'au
     * propriétaire, tant qu'il en reste une.
     */
    public function resteDesEtapes(): bool
    {
        return collect($this->etapes())->contains(fn (array $etape): bool => ! $etape['faite']);
    }

    /**
     * Ses magasins réels de la portée de la personne connectée.
     *
     * @return Collection<int, Magasin>
     */
    public function magasins(): Collection
    {
        return CompteConnecte::exiger()->magasinsDeSaPortee()->where('est_demonstration', false)->orderBy('nom')->orderBy('id')->get();
    }

    /**
     * Le nombre, le restant dû et le retard des factures à encaisser de
     * chaque magasin réel de la portée où la personne voit toutes les
     * factures, par identifiant de magasin (voir
     * FacturesAEncaisserDeLaSociete) : un magasin qui n'y figure pas n'en
     * montre rien.
     *
     * @return array<int, array{nombre: int, restant_du: int, nombre_en_retard: int, restant_du_en_retard: int}>
     */
    public function facturesAEncaisser(): array
    {
        return app(FacturesAEncaisserDeLaSociete::class)->pour(CompteConnecte::exiger());
    }

    /**
     * Ce qui reste à faire dans chaque magasin réel de la portée, par
     * identifiant de magasin, en nombres par groupe, sans nom de client ni
     * numéro de document (voir ChosesAFaireDeLaSociete) : chaque magasin y
     * mène à son tableau de bord.
     *
     * @return array<int, list<array{groupe: string, nombre: int, montant: string|null}>>
     */
    public function chosesAFaire(): array
    {
        return app(ChosesAFaireDeLaSociete::class)->parMagasin(CompteConnecte::exiger());
    }

    /**
     * Ce qui attend dans la société elle-même, pour la personne connectée
     * (voir ChosesAFaireDeLaSociete::delaSociete()) : la section « À
     * faire », juste après la bienvenue.
     *
     * @return list<ChoseAFaire>
     */
    public function chosesAFaireDeLaSociete(): array
    {
        return app(ChosesAFaireDeLaSociete::class)->delaSociete(CompteConnecte::exiger(), $this->societe());
    }

    /**
     * « C'est vérifié », sur la ligne d'un écart relevé au répertoire
     * Sirene (voir VerifierUnEcartSireneAction) : l'écart sort de l'« À
     * faire ». Le droit se relit dans l'action, sous les verrous.
     */
    public function verifierLEcartAction(): Action
    {
        return Action::make('verifierLEcart')
            ->label('C\'est vérifié')
            ->icon('heroicon-o-check')
            ->color('success')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Écart vérifié')
            ->modalDescription('L\'écart sort de votre « À faire ». Rien d\'autre ne change ; un nouvel écart relevé ensuite se signalera de nouveau.')
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    $ecart = EcartSirene::query()->find(ValeursLues::entierOuNulDe($arguments, 'ecart'))
                        ?? throw new AuthorizationException(PorteeDesDroits::REFUS);
                    app(VerifierUnEcartSireneAction::class)->handle(CompteConnecte::exiger(), $ecart);
                    Notification::make()->title('Écart vérifié.')->success()->send();
                });
            });
    }

    /**
     * Un administrateur autorisé à nommer les responsables le fait ici,
     * dans « Comptes » (voir MembreResource), jamais dans l'équipe d'un
     * magasin, qui lui reste fermée : le tableau de bord le lui dit, tant
     * que la société lui permet de le faire (voir ControleDesDroits).
     */
    public function peutNommerUnResponsable(): bool
    {
        $compte = CompteConnecte::exiger();

        return $compte->estAdministrateur() && $compte->can(AutorisationsSociete::RESPONSABLES_NOMMER);
    }

    /**
     * Le magasin de démonstration, pour qui administre tous les magasins,
     * tant que la société n'est pas validée : une fois validée, sa section
     * disparaît, comme la démonstration de tous ses écrans (T115, lot
     * DEMO-V).
     */
    public function demonstration(): ?Magasin
    {
        $societe = $this->societe();

        return CompteConnecte::exiger()->administreTousLesMagasins() && ! $societe->estValidee() ? $societe->demonstration()->first() : null;
    }

    /**
     * Ouvre, ou rouvre, le magasin de démonstration, puis y mène : le
     * bouton de l'étape de l'essai tant qu'il n'a jamais été ouvert, puis
     * celui de la section de la démonstration quand il est fermé. Sa
     * fenêtre dit au propriétaire qu'il y vend, le seul magasin où il le
     * fait, et à un administrateur de tous les magasins, qui l'ouvre aussi
     * (voir SocietePolicy::ouvrirLaDemonstration()), que son équipe y vend
     * avec des comptes d'entraînement qu'il ne crée pas, et qu'il n'y vend
     * que si le propriétaire lui a coché la vente.
     */
    public function ouvrirLaDemonstrationAction(): Action
    {
        return Action::make('ouvrirLaDemonstration')
            ->label(fn (): string => $this->demonstration() === null ? 'Ouvrir le magasin de démonstration' : 'Rouvrir le magasin de démonstration')
            ->icon('heroicon-o-academic-cap')
            ->visible(fn (): bool => (auth()->user()?->can('ouvrirLaDemonstration', $this->societe()) ?? false) && $this->demonstration()?->statut !== StatutMagasin::Actif)
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'Un magasin d\'entraînement, avec un catalogue fictif : '.(CompteConnecte::exiger()->estProprietaire()
                ? 'vous y vendez, encaissez et clôturez comme pour de vrai, ce que vous ne faites jamais dans vos magasins réels ; pour y faire essayer la caisse à votre équipe, créez-y des comptes d\'entraînement : ils ne servent qu\'ici et sont retirés à la validation de votre société.'
                : 'votre équipe y vend, encaisse et clôture comme pour de vrai, avec les comptes d\'entraînement qu\'y créent le propriétaire et les responsables de ce magasin : ils ne servent qu\'ici et sont retirés à la validation de votre société ; vous n\'y vendez que si le propriétaire vous a coché la vente.')
                .' Ses documents sont marqués « Démonstration — sans valeur », il n\'écrit à aucun client et ne transmet rien. Il se ferme à la validation de votre société, et rien n\'en passe à vos magasins réels.')
            ->action(function () {
                $demonstration = null;
                static::executerSansCasser(function () use (&$demonstration): void {
                    $demonstration = app(OuvrirLaDemonstrationAction::class)->handle(CompteConnecte::exiger(), $this->societe());
                });

                return $demonstration === null ? null : redirect()->to(Dashboard::getUrl(panel: 'app', tenant: $demonstration));
            });
    }

    /**
     * Où en est la vérification, et ce qu'il reste à faire : saisir le
     * code du courrier, jusqu'à quand.
     */
    private function prochaineEtapeDeLaVerification(): string
    {
        $societe = $this->societe();
        $courriers = app(CourriersDeVerification::class);

        return match (true) {
            $societe->estValidee() => 'Validée le '.Calendrier::deParis()->afficher(ValeurRequise::de($societe->validee_le, 'la date de validation de la société validée'), 'd/m/Y').'.',
            $societe->estClose() => 'Inscription close.',
            default => 'Le courrier recommandé est parti au représentant légal de votre entreprise, à l\'adresse de son siège : saisissez son code avant le '.Calendrier::deParis()->afficher($courriers->cloturePrevueLe($societe), 'd/m/Y').'.',
        };
    }
}
