<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete as LigneDeVerification;
use App\Domain\Tenancy\Services\CourrierDeCode;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Integrations\EnvoiRecommande\DestinataireDuCourrier;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * La vérification de la société : où en est son courrier recommandé (le
 * destinataire, l'adresse, le jour d'envoi, le jour et l'heure jusqu'où
 * son code vaut, ou son retour, et la page où le saisir, celle que le QR
 * code du courrier ouvre), et les gestes qui la font avancer, pour son
 * propriétaire seul :
 * demander un nouveau courrier, une seule fois, au plus tôt quinze jours
 * après le premier ; ensuite, déposer des pièces pour que l'éditeur traite
 * son cas à la main. Chaque geste passe par son action, qui contrôle
 * elle-même le droit de le faire (voir SocietePolicy::verifierLaSociete()).
 * Le code se saisit sur sa page publique (voir CodeDuCourrierController),
 * depuis le téléphone qui scanne le QR code comme depuis cette page.
 *
 * Elle montre aussi les établissements que le répertoire Sirene doit
 * encore confirmer, avec le bouton qui reprend leur contrôle.
 */
class VerificationSociete extends Page
{
    use GereLesExceptionsMetier;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Vérification';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.societe.pages.verification-societe';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Vérification de la société';
    }

    public function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * Le dernier courrier de la vérification.
     */
    public function dernierCourrier(): ?CodeConfirmation
    {
        return app(CourriersDeVerification::class)->dernier($this->societe()->refresh());
    }

    /**
     * Le destinataire du dernier courrier, tel qu'il l'imprime.
     */
    public function destinataire(): ?DestinataireDuCourrier
    {
        $destinataire = $this->dernierCourrier()?->destinataire;

        return is_array($destinataire) ? DestinataireDuCourrier::depuisTableau($destinataire) : null;
    }

    /**
     * Le jour à partir duquel un nouveau courrier peut être demandé ; null
     * quand il ne le peut plus.
     */
    public function nouvelEnvoiPossibleLe(): ?CarbonImmutable
    {
        return app(CourriersDeVerification::class)->nouvelEnvoiPossibleLe($this->societe()->refresh());
    }

    public function piecesPossibles(): bool
    {
        return app(CourriersDeVerification::class)->piecesPossibles($this->societe()->refresh());
    }

    public function cloturePrevueLe(): CarbonImmutable
    {
        return app(CourriersDeVerification::class)->cloturePrevueLe($this->societe());
    }

    /**
     * L'adresse de la page du code, avec le SIREN de la société déjà rempli.
     */
    public function adresseDeLaPageDuCode(): string
    {
        return CourrierDeCode::adresseDeLaPage((string) $this->societe()->siren);
    }

    /**
     * Les magasins réels actifs dont l'établissement attend la
     * confirmation du répertoire Sirene.
     *
     * @return Collection<int, Magasin>
     */
    public function etablissementsAVerifier(): Collection
    {
        return $this->societe()->magasinsReels()->where('statut', 'actif')->whereNull('etablissement_verifie_le')->orderBy('nom')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->reverifierAction(),
            $this->demanderUnNouveauCourrierAction(),
            $this->deposerUnePieceAction(),
        ];
    }

    public function reverifierAction(): Action
    {
        return Action::make('reverifier')
            ->label('Revérifier auprès de l\'INSEE')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => $this->peutVerifier() && $this->controleSireneEnAttente())
            ->action(function (): void {
                app(ReverificationSirene::class)->reprendre($this->societe(), false);
                Notification::make()->title('Contrôles du répertoire Sirene repris.')->success()->send();
            });
    }

    public function demanderUnNouveauCourrierAction(): Action
    {
        return Action::make('demanderUnNouveauCourrier')
            ->label('Demander un nouveau courrier')
            ->icon('heroicon-o-envelope')
            ->visible(fn (): bool => $this->peutVerifier() && $this->nouvelEnvoiPossibleLe()?->isFuture() === false)
            ->requiresConfirmation()
            ->modalDescription('Un nouveau courrier recommandé, avec un nouveau code, partira au représentant légal de votre entreprise, à l\'adresse officielle de son siège ; le code du premier courrier ne vaudra plus. Un seul nouveau courrier peut être demandé.')
            ->action(fn () => static::executerSansCasser(function (): void {
                app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi(auth()->user(), $this->societe());
                Notification::make()->title('Le nouveau courrier est parti.')->success()->send();
            }));
    }

    public function deposerUnePieceAction(): Action
    {
        return Action::make('deposerUnePiece')
            ->label('Déposer des pièces pour l\'éditeur')
            ->icon('heroicon-o-document-arrow-up')
            ->color('gray')
            ->visible(fn (): bool => $this->peutVerifier() && $this->piecesPossibles())
            ->modalDescription('Extrait Kbis ou extrait du registre national des entreprises de moins de trois mois, pièce d\'identité du représentant légal. Vos pièces sont chiffrées et effacées dès la décision de l\'éditeur, au plus tard 30 jours après leur dépôt. L\'éditeur traite votre cas et vous envoie, s\'il y a lieu, un nouveau courrier recommandé : seul son code valide la société.')
            ->form([
                Select::make('type')->label('Pièce')->options(TypePiece::class)->required(),
                FileUpload::make('fichier')->label('Fichier')->storeFiles(false)->maxSize(DeposerUnePieceAction::TAILLE_MAXIMALE_EN_OCTETS / 1024)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->required(),
            ])
            ->action(function (array $data): void {
                /** @var TemporaryUploadedFile $fichier */
                $fichier = $data['fichier'];

                try {
                    static::executerSansCasser(function () use ($data, $fichier): void {
                        app(DeposerUnePieceAction::class)->handle(auth()->user(), $this->societe(), TypePiece::from($data['type']), (string) $fichier->get());
                        Notification::make()->title('Pièce déposée : l\'éditeur est prévenu.')->success()->send();
                    });
                } finally {
                    $fichier->delete();
                }
            });
    }

    private function peutVerifier(): bool
    {
        return auth()->user()?->can('verifierLaSociete', $this->societe()) ?? false;
    }

    /**
     * Un contrôle d'établissement reste-t-il à faire, l'INSEE n'ayant pas
     * répondu ?
     */
    private function controleSireneEnAttente(): bool
    {
        return $this->etablissementsAVerifier()->contains(fn (Magasin $magasin): bool => LigneDeVerification::query()
            ->where('type', TypeVerification::SireneEtablissement->value)
            ->where('magasin_id', $magasin->getKey())
            ->orderByDesc('id')
            ->value('resultat') === ResultatVerification::Indisponible);
    }
}
