<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Actions\DemanderAccordDUnDirigeantAction;
use App\Domain\Tenancy\Actions\DemarrerVerificationIdentiteAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\RecevoirResultatIdentiteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete as LigneDeVerification;
use App\Domain\Tenancy\Services\AdresseOfficielle;
use App\Domain\Tenancy\Services\EtatDeVerification;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Http\Controllers\RetourVerificationIdentiteController;
use App\Integrations\Rne\RneIndisponibleException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * La vérification de la société (S6, S7), pour son propriétaire : où en
 * sont l'entreprise et ses établissements au répertoire Sirene (A), la
 * preuve de sa qualité de représentant (B), le contrôle de l'adresse
 * officielle (C) et les signaux de risque, et les gestes qui la font
 * avancer : vérifier son identité, demander l'accord d'un dirigeant,
 * déposer des pièces pour l'éditeur, recevoir un code par courrier
 * recommandé ou par lettre recommandée électronique, le saisir. Chaque
 * geste passe par son action, qui contrôle elle-même le droit de le faire
 * (voir SocietePolicy::verifierLaSociete()).
 *
 * Avant la vérification d'identité, l'écran informe (art. 13 du RGPD) et
 * recueille le consentement explicite au traitement biométrique du
 * prestataire (art. 9.2.a), en présentant les autres voies (pièces avec
 * courrier, plateforme agréée à l'étape 2) comme des alternatives d'égale
 * valeur. Le conflit avec une autre société du même SIREN ne se révèle
 * qu'après une preuve de qualité concluante.
 */
class VerificationSociete extends Page
{
    use GereLesExceptionsMetier;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Vérification';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.societe.pages.verification-societe';

    protected ?EtatDeVerification $etatEvalue = null;

    public const INFORMATION_IDENTITE = 'Un prestataire certifié par l\'ANSSI vérifie votre pièce d\'identité et votre visage, puis nous transmet seulement vos nom, prénoms, mois et année de naissance et le niveau de garantie, que nous rapprochons des dirigeants inscrits au registre national des entreprises. Nous ne gardons ni l\'image ni le numéro de votre pièce. Ces données servent à prouver que vous pouvez agir pour votre entreprise et se gardent au plus cinq ans après la fin de notre relation. Vous pouvez y accéder, les faire rectifier ou effacer en écrivant à l\'éditeur. Vous n\'y êtes pas obligé : déposer des pièces pour l\'éditeur, avec un courrier recommandé, ou connecter la plateforme agréée de facturation électronique (bientôt) prouvent tout autant votre qualité.';

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
     * L'état de la vérification tel que la société le voit (voir
     * EtatDeVerification::vuParLaSociete()), évalué une fois par requête.
     */
    public function etat(): EtatDeVerification
    {
        return $this->etatEvalue ??= app(EvaluateurDeVerification::class)->evaluer($this->societe()->refresh())->vuParLaSociete();
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

    /**
     * La dernière identité vérifiée du propriétaire, telle que le
     * prestataire l'a donnée, pour qu'il la voie.
     *
     * @return array<string, mixed>|null
     */
    public function identiteVerifiee(): ?array
    {
        return LigneDeVerification::query()
            ->where('type', TypeVerification::Identite->value)
            ->where('resultat', ResultatVerification::Concluante->value)
            ->where('beneficiaire_id', auth()->id())
            ->orderByDesc('id')
            ->value('details');
    }

    public function codeEnCours(): ?CodeConfirmation
    {
        return CodeConfirmation::query()->whereNull('utilise_le')->whereNull('annule_le')->where('expire_le', '>', now())->orderByDesc('id')->first();
    }

    /**
     * Les signaux encore actifs que la société voit, tels qu'elle les lit
     * (voir SignalDeRisque::libellePourLaSociete()).
     *
     * @return list<string>
     */
    public function signaux(): array
    {
        return array_values(array_unique(array_map(fn (SignalDeRisque $signal): string => $signal->libellePourLaSociete(), $this->etat()->signauxActifs)));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->reverifierAction(),
            $this->verifierMonIdentiteAction(),
            $this->rapprocherDeNouveauAction(),
            $this->demanderLAccordDUnDirigeantAction(),
            $this->deposerUnePieceAction(),
            $this->recevoirUnCourrierAction(),
            $this->recevoirUneLettreElectroniqueAction(),
            $this->saisirLeCodeAction(),
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

    public function verifierMonIdentiteAction(): Action
    {
        return Action::make('verifierMonIdentite')
            ->label('Vérifier mon identité')
            ->icon('heroicon-o-identification')
            ->visible(fn (): bool => $this->peutVerifier() && $this->etat()->preuveDeQualite === null)
            ->modalHeading('Vérifier votre identité en ligne')
            ->modalSubmitActionLabel('Commencer la vérification')
            ->form([
                Placeholder::make('information')->hiddenLabel()->content(self::INFORMATION_IDENTITE),
                Checkbox::make('consentement')
                    ->label('J\'accepte que le prestataire traite l\'image de mon visage et de ma pièce d\'identité pour vérifier mon identité.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'La vérification d\'identité demande votre consentement explicite.']),
            ])
            ->action(function (array $data) {
                try {
                    $demarree = app(DemarrerVerificationIdentiteAction::class)->handle(auth()->user(), $this->societe(), (bool) $data['consentement'], rtrim((string) config('app.url'), '/').route('verification.identite.retour', absolute: false));
                } catch (VerificationRefuseeException $refus) {
                    static::notifierLeRefus($refus);

                    return null;
                }

                session()->put(RetourVerificationIdentiteController::CLE_DE_SESSION, [
                    'reference' => $demarree['session']->reference,
                    'state' => $demarree['state'],
                    'societe_id' => $this->societe()->getKey(),
                    'user_id' => auth()->id(),
                ]);

                return redirect()->away($demarree['session']->url);
            });
    }

    public function rapprocherDeNouveauAction(): Action
    {
        return Action::make('rapprocherDeNouveau')
            ->label('Rapprocher de nouveau des dirigeants')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => $this->peutVerifier() && $this->rapprochementEnAttente())
            ->action(fn () => static::executerSansCasser(function (): void {
                app(RecevoirResultatIdentiteAction::class)->rapprocherDeNouveau(auth()->user(), $this->societe());
                Notification::make()->title('Rapprochement repris.')->success()->send();
            }));
    }

    public function demanderLAccordDUnDirigeantAction(): Action
    {
        return Action::make('demanderLAccordDUnDirigeant')
            ->label('Je ne suis pas dirigeant : demander l\'accord d\'un dirigeant')
            ->icon('heroicon-o-user-group')
            ->color('gray')
            ->visible(fn (): bool => $this->peutVerifier() && $this->etat()->identiteVerifiee && $this->etat()->preuveDeQualite === null)
            ->modalDescription('Le dirigeant reçoit un lien valable sept jours : il vérifie son identité, puis approuve votre mandat.')
            ->form([
                Select::make('rang')->label('Dirigeant')->options(fn (): array => $this->dirigeants())->required(),
                TextInput::make('email')->label('Adresse e-mail du dirigeant')->email()->required()->maxLength(255),
            ])
            ->action(fn (array $data) => static::executerSansCasser(function () use ($data): void {
                try {
                    app(DemanderAccordDUnDirigeantAction::class)->handle(auth()->user(), $this->societe(), (int) $data['rang'], (string) $data['email']);
                } catch (ValidationException $refus) {
                    Notification::make()->title(collect($refus->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Demande envoyée au dirigeant.')->success()->send();
            }));
    }

    public function deposerUnePieceAction(): Action
    {
        return Action::make('deposerUnePiece')
            ->label('Déposer des pièces pour l\'éditeur')
            ->icon('heroicon-o-document-arrow-up')
            ->color('gray')
            ->visible(fn (): bool => $this->peutVerifier() && $this->etat()->preuveDeQualite === null)
            ->modalDescription('Extrait Kbis ou extrait du registre national des entreprises de moins de trois mois, pièce d\'identité, mandat. Vos pièces sont chiffrées et effacées dès la décision de l\'éditeur, au plus tard 30 jours après leur dépôt. Un courrier recommandé suivra à l\'adresse officielle de votre entreprise.')
            ->form([
                Select::make('type')->label('Pièce')->options(TypePiece::class)->required(),
                FileUpload::make('fichier')->label('Fichier')->storeFiles(false)->maxSize(DeposerUnePieceAction::TAILLE_MAXIMALE_EN_OCTETS / 1024)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->required(),
            ])
            ->action(fn (array $data) => static::executerSansCasser(function () use ($data): void {
                /** @var TemporaryUploadedFile $fichier */
                $fichier = $data['fichier'];
                app(DeposerUnePieceAction::class)->handle(auth()->user(), $this->societe(), TypePiece::from($data['type']), (string) $fichier->get());
                $fichier->delete();
                Notification::make()->title('Pièce déposée : l\'éditeur est prévenu.')->success()->send();
            }));
    }

    public function recevoirUnCourrierAction(): Action
    {
        return Action::make('recevoirUnCourrier')
            ->label('Recevoir un code par courrier recommandé')
            ->icon('heroicon-o-envelope')
            ->visible(fn (): bool => $this->peutVerifier() && $this->etat()->identiteVerifiee && $this->etat()->attendUnCourrier() && $this->codeEnCours() === null)
            ->requiresConfirmation()
            ->modalDescription(function (): string {
                try {
                    return 'Un courrier recommandé avec accusé de réception, contenant un code à saisir ici, partira à l\'adresse officielle de votre entreprise : '.implode(', ', app(AdresseOfficielle::class)->du($this->societe())->lignes()).'.';
                } catch (VerificationRefuseeException $refus) {
                    return $refus->getMessage();
                }
            })
            ->action(fn () => static::executerSansCasser(function (): void {
                app(EnvoyerCodeDeConfirmationAction::class)->handle($this->societe(), MotifCode::Verification, CanalEnvoi::RecommandeAr, auth()->user());
                Notification::make()->title('Le courrier est parti.')->success()->send();
            }));
    }

    public function recevoirUneLettreElectroniqueAction(): Action
    {
        return Action::make('recevoirUneLettreElectronique')
            ->label('Recevoir un code par lettre recommandée électronique')
            ->icon('heroicon-o-at-symbol')
            ->color('gray')
            ->visible(fn (): bool => $this->peutVerifier() && $this->etat()->identiteVerifiee && $this->etat()->attendUnCourrier() && $this->codeEnCours() === null)
            ->modalDescription('La lettre est remise au dirigeant choisi, que le prestataire qualifié identifie à son acceptation : le code ne se saisit qu\'ensuite.')
            ->form([
                Select::make('rang')->label('Dirigeant')->options(fn (): array => $this->dirigeants())->required(),
                TextInput::make('email')->label('Adresse e-mail du dirigeant')->email()->required()->maxLength(255),
            ])
            ->action(fn (array $data) => static::executerSansCasser(function () use ($data): void {
                app(EnvoyerCodeDeConfirmationAction::class)->handle($this->societe(), MotifCode::Verification, CanalEnvoi::LreQualifiee, auth()->user(), lre: ['rang' => (int) $data['rang'], 'email' => (string) $data['email']]);
                Notification::make()->title('La lettre recommandée électronique est partie.')->success()->send();
            }));
    }

    public function saisirLeCodeAction(): Action
    {
        return Action::make('saisirLeCode')
            ->label('Saisir le code reçu')
            ->icon('heroicon-o-key')
            ->visible(fn (): bool => $this->peutVerifier() && $this->codeEnCours() !== null)
            ->form([
                TextInput::make('code')->label('Code (XXXX-XXXX-XXXX)')->required()->maxLength(20),
            ])
            ->action(fn (array $data) => static::executerSansCasser(function () use ($data): void {
                $juste = app(SaisirCodeDeConfirmationAction::class)->handle(auth()->user(), $this->societe(), (string) $data['code']);

                $juste
                    ? Notification::make()->title('Code accepté.')->success()->send()
                    : Notification::make()->title(SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX)->danger()->send();
            }));
    }

    private function peutVerifier(): bool
    {
        return auth()->user()?->can('verifierLaSociete', $this->societe()) ?? false;
    }

    /**
     * Les dirigeants que le registre national donne de l'entreprise, par
     * rang ; aucun quand il ne répond pas.
     *
     * @return array<int, string>
     */
    private function dirigeants(): array
    {
        try {
            return collect(app(RapprochementDesDirigeants::class)->candidats((string) $this->societe()->siren))
                ->map(fn (array $candidat): string => implode(' — ', $candidat['chaine']))
                ->all();
        } catch (RneIndisponibleException) {
            return [];
        }
    }

    /**
     * Un contrôle de l'entreprise ou d'un établissement reste-t-il
     * impossible, l'INSEE n'ayant pas répondu ?
     */
    private function controleSireneEnAttente(): bool
    {
        $dernierControleDeLEntreprise = LigneDeVerification::query()->where('type', TypeVerification::SireneUnite->value)->orderByDesc('id')->value('resultat');

        return $dernierControleDeLEntreprise === ResultatVerification::Indisponible || $this->etablissementsAVerifier()->isNotEmpty();
    }

    private function rapprochementEnAttente(): bool
    {
        return LigneDeVerification::query()
            ->where('type', TypeVerification::RneRepresentants->value)
            ->where('beneficiaire_id', auth()->id())
            ->orderByDesc('id')
            ->value('resultat') === ResultatVerification::Indisponible;
    }
}
