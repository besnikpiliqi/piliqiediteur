<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Facturation\Services\FacturesAEncaisserDeLaSociete;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le tableau de bord de la société, où arrive son propriétaire après le
 * lien de son inscription : en tête, « Bonjour » et le rôle de la personne
 * dans la société (voir User::libelleDuRoleDeSociete()) ; pour le
 * propriétaire seul, la liste de ses premières étapes, chacune avec son
 * état et l'écran qui la fait (voir etapes()), tant qu'il en reste une à
 * faire ; où en est sa vérification ; ce qui manque à son identité pour
 * facturer un professionnel ; ses magasins réels de la portée de la
 * personne connectée (tous pour le propriétaire et un administrateur de
 * tous les magasins, ceux de sa sélection pour un autre administrateur),
 * chacun avec son SIRET, son établissement (vérifié ou à vérifier), son
 * état et, quand la personne y voit toutes les factures, le nombre, le
 * restant dû et le retard de ses factures à encaisser (voir
 * facturesAEncaisser()), qui mènent à leur liste dans le magasin ; pour un
 * administrateur autorisé à nommer les responsables, où le faire (voir
 * peutNommerUnResponsable()) ; et son magasin de démonstration, ouvert ici
 * tant que la société est en cours de vérification (voir
 * OuvrirLaDemonstrationAction). Les magasins et les comptes se lisent dans
 * la société affichée : ceux d'aucune autre société.
 */
class TableauDeBord extends Dashboard
{
    use GereLesExceptionsMetier;

    public const ETAPE_VERIFIER = 'Vérifier votre société';

    public const ETAPE_PREMIER_MAGASIN = 'Créer votre premier magasin';

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
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * L'état de la vérification de la société, tel que l'écran le dit.
     */
    public function etatDeLaVerification(): string
    {
        $societe = $this->societe();

        return match (true) {
            $societe->estRefusee() => 'L\'inscription de votre société a été refusée.',
            $societe->estValidee() => 'Votre société est validée depuis le '.$societe->validee_le->setTimezone(config('app.timezone'))->format('d/m/Y').'.',
            default => 'Votre société est en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation. Vous pouvez déjà créer vos magasins et les configurer : catalogue, équipe, paramètres et achats, et vous essayer dans le magasin de démonstration.',
        };
    }

    /**
     * Les premières étapes de la société, celles de son propriétaire, dans
     * l'ordre : vérifier la société, créer son premier magasin, ajouter son
     * équipe, essayer le magasin de démonstration. Chacune dit si elle est
     * faite, où elle en est, et mène à l'écran qui la fait quand il peut
     * l'ouvrir ; l'essai de la démonstration se fait par le bouton de
     * l'étape (voir ouvrirLaDemonstrationAction()). L'essai ne se fait plus
     * une fois la société validée : l'étape compte alors comme faite.
     * Aucune pour un administrateur, qui ne voit jamais ici le nombre des
     * magasins ni celui des comptes de la société. Les magasins et les
     * comptes se comptent dans la portée du propriétaire (voir
     * User::magasinsDeSaPortee() et MembreResource::getEloquentQuery()) :
     * toute la société, sans son propre compte.
     *
     * @return list<array{titre: string, faite: bool, etat: string, url: string|null}>
     */
    public function etapes(): array
    {
        $compte = auth()->user();

        if (! $compte->estProprietaire()) {
            return [];
        }

        $societe = $this->societe();
        $magasins = $compte->magasinsDeSaPortee()->where('est_demonstration', false)->count();
        $comptes = MembreResource::getEloquentQuery()->whereKeyNot($compte->getKey())->where('statut', '!=', StatutCompte::Retire->value)->count();
        $demonstration = $societe->demonstration()->first();

        return [
            [
                'titre' => self::ETAPE_VERIFIER,
                'faite' => $societe->estValidee(),
                'etat' => $this->prochaineEtapeDeLaVerification(),
                'url' => VerificationSociete::getUrl(),
            ],
            [
                'titre' => self::ETAPE_PREMIER_MAGASIN,
                'faite' => $magasins > 0,
                'etat' => $magasins > 0 ? trans_choice('{1} 1 magasin|[2,*] :count magasins', $magasins) : 'Choisissez son établissement dans la liste de ceux de votre SIREN.',
                'url' => match (true) {
                    MagasinResource::canCreate() && $magasins === 0 => MagasinResource::getUrl('create'),
                    MagasinResource::canViewAny() => MagasinResource::getUrl('index'),
                    default => null,
                },
            ],
            [
                'titre' => self::ETAPE_EQUIPE,
                'faite' => $comptes > 0,
                'etat' => $comptes > 0 ? trans_choice('{1} 1 compte en plus du vôtre|[2,*] :count comptes en plus du vôtre', $comptes) : 'Créez les comptes de vos administrateurs, responsables, vendeurs et experts-comptables.',
                'url' => MembreResource::canViewAny() ? MembreResource::getUrl('index') : null,
            ],
            [
                'titre' => self::ETAPE_DEMONSTRATION,
                'faite' => $demonstration !== null || $societe->estValidee(),
                'etat' => match (true) {
                    $demonstration?->statut === StatutMagasin::Actif => 'Ouvert : votre équipe s\'y entraîne à vendre, encaisser et clôturer ; pour essayer la caisse, créez-y des comptes d\'entraînement : ils ne servent qu\'ici et sont retirés à la validation de votre société.',
                    $demonstration !== null => 'Terminé.',
                    $societe->estValidee() => 'Plus disponible : votre société est validée.',
                    default => 'Un magasin d\'entraînement, sans valeur, à essayer avant la validation.',
                },
                'url' => $demonstration?->statut === StatutMagasin::Actif && $compte->peutOuvrir($demonstration) ? Dashboard::getUrl(panel: 'app', tenant: $demonstration) : null,
            ],
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
        return auth()->user()->magasinsDeSaPortee()->where('est_demonstration', false)->orderBy('nom')->orderBy('id')->get();
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
        return app(FacturesAEncaisserDeLaSociete::class)->pour(auth()->user());
    }

    /**
     * Un administrateur autorisé à nommer les responsables le fait ici,
     * dans « Comptes » (voir MembreResource), jamais dans l'équipe d'un
     * magasin, qui lui reste fermée : le tableau de bord le lui dit, tant
     * que la société lui permet de le faire (voir ControleDesDroits).
     */
    public function peutNommerUnResponsable(): bool
    {
        $compte = auth()->user();

        return $compte->estAdministrateur() && $compte->can(AutorisationsSociete::RESPONSABLES_NOMMER);
    }

    /**
     * Le magasin de démonstration, pour qui administre tous les magasins.
     */
    public function demonstration(): ?Magasin
    {
        return auth()->user()->administreTousLesMagasins() ? $this->societe()->demonstration()->first() : null;
    }

    /**
     * Ouvre, ou rouvre, le magasin de démonstration, puis y mène : le
     * bouton de l'étape de l'essai tant qu'il n'a jamais été ouvert, puis
     * celui de la section de la démonstration quand il est fermé.
     */
    public function ouvrirLaDemonstrationAction(): Action
    {
        return Action::make('ouvrirLaDemonstration')
            ->label(fn (): string => $this->demonstration() === null ? 'Ouvrir le magasin de démonstration' : 'Rouvrir le magasin de démonstration')
            ->icon('heroicon-o-academic-cap')
            ->visible(fn (): bool => (auth()->user()?->can('ouvrirLaDemonstration', $this->societe()) ?? false) && $this->demonstration()?->statut !== StatutMagasin::Actif)
            ->requiresConfirmation()
            ->modalDescription('Un magasin d\'entraînement, avec un catalogue fictif : votre équipe y vend, encaisse et clôture comme pour de vrai ; vous-même ne vendez pas : pour essayer la caisse, créez-y des comptes d\'entraînement : ils ne servent qu\'ici et sont retirés à la validation de votre société. Ses documents sont marqués « Démonstration — sans valeur », il n\'écrit à aucun client et ne transmet rien. Il se ferme à la validation de votre société, et rien n\'en passe à vos magasins réels.')
            ->action(function () {
                $demonstration = null;
                static::executerSansCasser(function () use (&$demonstration): void {
                    $demonstration = app(OuvrirLaDemonstrationAction::class)->handle(auth()->user(), $this->societe());
                });

                return $demonstration === null ? null : redirect()->to(Dashboard::getUrl(panel: 'app', tenant: $demonstration));
            });
    }

    /**
     * Où en est la vérification, et ce qu'il reste à faire, tel que la
     * société le voit (voir EtatDeVerification::vuParLaSociete()).
     */
    private function prochaineEtapeDeLaVerification(): string
    {
        $societe = $this->societe();

        if ($societe->estValidee()) {
            return 'Validée le '.$societe->validee_le->setTimezone(config('app.timezone'))->format('d/m/Y').'.';
        }

        $etat = app(EvaluateurDeVerification::class)->evaluer($societe)->vuParLaSociete();

        return match (true) {
            $etat->refusee => 'Refusée.',
            $etat->reclamationPossible() => 'En cours d\'examen par l\'éditeur.',
            ! $etat->entrepriseEtEtablissements => 'En cours : le répertoire Sirene de l\'INSEE doit encore confirmer votre entreprise ou l\'établissement d\'un magasin.',
            $etat->attendUnCourrier() => 'Prochaine étape : recevoir le code du courrier recommandé et le saisir.',
            $etat->preuveDeQualite === null => 'Prochaine étape : prouver votre qualité de représentant de l\'entreprise.',
            $etat->signauxActifs !== [] => 'Prochaine étape : lever les points signalés.',
            default => 'En cours.',
        };
    }
}
