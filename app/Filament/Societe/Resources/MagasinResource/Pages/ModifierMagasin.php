<?php

namespace App\Filament\Societe\Resources\MagasinResource\Pages;

use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\ChoisirEtablissementPrincipalAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Societe\Resources\MagasinResource;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Un magasin réel de la société, pour qui gère les magasins de sa portée
 * (voir MagasinDeLaSocietePolicy::update()) : son nom, son code tant qu'il
 * n'a numéroté aucun document, son adresse sans changer de territoire de
 * TVA et, avant la validation de la société, son NIC (voir
 * ModifierEtablissementAction) ; puis les gestes de son cycle de vie, chacun
 * offert selon la policy et l'état du magasin :
 * - changer de SIRET, une fois la société validée (voir ChangerSiretAction) ;
 * - l'indiquer comme établissement principal, ou retirer cette indication
 *   (voir ChoisirEtablissementPrincipalAction) ;
 * - le fermer, temporairement ou pour cessation d'activité (voir
 *   FermerMagasinAction), ou, pour le propriétaire seul, une fois la
 *   société validée, le céder en retapant son nom, qu'il soit ouvert ou
 *   déjà fermé ;
 * - le rouvrir, avant comme après la validation de la société, sauf après
 *   une cession (voir RouvrirMagasinAction).
 * Les gestes graves (SIRET, fermeture, cession, réouverture) redemandent
 * le mot de passe.
 *
 * Ce que montre la page n'est qu'une proposition : chaque action du
 * domaine revérifie l'auteur, l'état du magasin et ses données sous ses
 * verrous, avec ses propres messages, qui s'affichent tels quels. Un
 * magasin cédé ne se modifie plus : la page le montre sans rien
 * enregistrer.
 */
class ModifierMagasin extends EditRecord
{
    use GereLesExceptionsMetier;

    /**
     * Les champs que la page envoie à ModifierEtablissementAction ; un
     * champ désactivé ou masqué (le code devenu définitif, le NIC d'une
     * société validée) n'y figure pas.
     */
    private const CHAMPS = ['nom', 'code', 'nic', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays'];

    protected static string $resource = MagasinResource::class;

    /**
     * Le magasin a-t-il été cédé ? Lu une fois par requête (la forme et
     * chacun de ses champs le demandent), et de nouveau après un geste.
     */
    private ?bool $cede = null;

    public function getTitle(): string|Htmlable
    {
        return $this->magasin()->nom;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return MagasinResource::etat($this->magasin());
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Établissement')
                ->schema([
                    Placeholder::make('siret')
                        ->label('SIRET')
                        ->content(fn (): string => (string) $this->magasin()->siret),
                    Placeholder::make('etablissement')
                        ->label('Établissement au répertoire Sirene')
                        ->content(fn (): string => $this->magasin()->etablissement_verifie_le === null ? 'à vérifier : aucune vente avant sa confirmation' : 'vérifié'),
                    Placeholder::make('territoire')
                        ->label('Territoire')
                        ->helperText('Lu au répertoire Sirene, à la commune de l\'établissement, il fixe le taux de TVA proposé et l\'heure du magasin, et ne change plus.')
                        ->content(fn (): string => MagasinResource::territoire($this->magasin())),
                    TextInput::make('nic')
                        ->label('NIC de l\'établissement')
                        ->helperText('Les 5 derniers chiffres du SIRET, après le SIREN : il se corrige tant que votre société n\'est pas validée, contrôlé au répertoire Sirene.')
                        ->required()
                        ->maxLength(5)
                        ->visible(fn (): bool => ! $this->magasin()->societe->estValidee()),
                    TextInput::make('adresse')->label('Adresse')->maxLength(255)->columnSpanFull(),
                    TextInput::make('complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull(),
                    TextInput::make('code_postal')->label('Code postal')->maxLength(10),
                    TextInput::make('ville')->label('Ville')->maxLength(100),
                    TextInput::make('pays')->label('Pays')->required()->length(2),
                ])
                ->columns(2),
            Section::make('Magasin')
                ->schema([
                    TextInput::make('nom')
                        ->label('Nom du magasin')
                        ->required()
                        ->maxLength(150),
                    TextInput::make('code')
                        ->label('Code du magasin')
                        ->helperText(fn (): string => $this->magasin()->aNumeroteUnDocument()
                            ? 'Il figure déjà dans les numéros des documents du magasin : il ne change plus.'
                            : 'Il figurera dans les numéros de tous ses documents (ex. FAC-LYON1-2026-000001) et ne changera plus après son premier document.')
                        ->required()
                        ->maxLength(CodeMagasin::LONGUEUR_MAXIMALE)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->disabled(fn (): bool => $this->magasin()->aNumeroteUnDocument()),
                ])
                ->columns(2),
        ])
            ->disabled(fn (): bool => $this->estCede());
    }

    /**
     * Le formulaire ne reçoit que ses champs, le NIC tiré du SIRET : rien
     * d'autre du magasin (barème, mentions...) ne passe par la page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...Arr::only($data, self::CHAMPS), 'nic' => substr((string) $this->magasin()->siret, 9)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::executerOuInterrompre(function () use ($record, $data): Magasin {
            try {
                return app(ModifierEtablissementAction::class)->handle($this->auteur(), $record, Arr::only($data, self::CHAMPS));
            } catch (ValidationException $refus) {
                throw ValidationException::withMessages(collect($refus->errors())->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])->all());
            }
        });
    }

    /**
     * Le formulaire se remplit de nouveau avec ce que l'action a
     * enregistré : un code devenu définitif pendant la saisie y reprend sa
     * valeur.
     */
    protected function afterSave(): void
    {
        $this->rafraichir();
    }

    /**
     * Un magasin cédé ne s'enregistre plus.
     */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->visible(fn (): bool => ! $this->estCede());
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->changerLeSiretAction(),
            $this->choisirCommePrincipalAction(),
            $this->retirerCommePrincipalAction(),
            $this->fermerAction(),
            $this->cederAction(),
            $this->rouvrirAction(),
        ];
    }

    public function changerLeSiretAction(): Action
    {
        return Action::make('changerLeSiret')
            ->label('Changer de SIRET')
            ->icon('heroicon-o-truck')
            ->visible(fn (): bool => $this->peut('changerLeSiret') && $this->magasin()->societe->estValidee() && ! $this->estCede())
            ->modalDescription('Le magasin déménage : son nouvel établissement, du même SIREN, est contrôlé au répertoire Sirene et prend effet aujourd\'hui. Le magasin garde ses documents, son stock, son équipe et son historique ; l\'ancien SIRET reste réservé et figure toujours sur les documents déjà émis. Un déménagement dans un autre territoire de TVA passe par un nouveau magasin.')
            ->form([
                TextInput::make('nic')->label('NIC du nouvel établissement')->required()->maxLength(5),
                self::champMotDePasse(),
            ])
            ->action(fn (array $data) => $this->agir(
                fn () => app(ChangerSiretAction::class)->handle($this->auteur(), $this->magasin(), $data['nic'], (string) $data['mot_de_passe']),
                fn (): string => "Le SIRET du magasin est désormais {$this->magasin()->siret}.",
            ));
    }

    public function choisirCommePrincipalAction(): Action
    {
        return Action::make('choisirCommePrincipal')
            ->label('Indiquer comme établissement principal')
            ->icon('heroicon-o-star')
            ->visible(fn (): bool => $this->peut('choisirCommePrincipal') && ! $this->magasin()->est_etablissement_principal && ! $this->estCede())
            ->requiresConfirmation()
            ->modalDescription('Une simple information : l\'établissement principal n\'a aucun privilège sur vos autres magasins, ni effet sur vos réglages ou vos documents. Le magasin qui l\'était ne l\'est plus.')
            ->action(fn () => $this->agir(
                fn () => app(ChoisirEtablissementPrincipalAction::class)->handle($this->auteur(), $this->magasin(), true),
                fn (): string => 'Ce magasin est indiqué comme votre établissement principal.',
            ));
    }

    public function retirerCommePrincipalAction(): Action
    {
        return Action::make('retirerCommePrincipal')
            ->label('Ne plus l\'indiquer comme établissement principal')
            ->icon('heroicon-o-star')
            ->color('gray')
            ->visible(fn (): bool => $this->peut('choisirCommePrincipal') && (bool) $this->magasin()->est_etablissement_principal)
            ->requiresConfirmation()
            ->action(fn () => $this->agir(
                fn () => app(ChoisirEtablissementPrincipalAction::class)->handle($this->auteur(), $this->magasin(), false),
                fn (): string => 'Ce magasin n\'est plus indiqué comme votre établissement principal.',
            ));
    }

    public function fermerAction(): Action
    {
        return Action::make('fermer')
            ->label('Fermer le magasin')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (): bool => $this->peut('fermerLeMagasin') && ! $this->magasin()->estFerme())
            ->modalDescription('Ses journées de caisse en attente sont clôturées tout de suite, les caisses personnelles encore ouvertes sont fermées automatiquement, et le magasin passe en lecture seule : plus aucune vente, aucun e-mail aux clients ni ticket Z tant qu\'il est fermé. Ses documents, son stock et son équipe restent. Vous pourrez le rouvrir.')
            ->form([
                Radio::make('motif')
                    ->label('Motif')
                    ->options([
                        MotifFermeture::Temporaire->value => MotifFermeture::Temporaire->getLabel(),
                        MotifFermeture::Cessation->value => MotifFermeture::Cessation->getLabel(),
                    ])
                    ->descriptions([
                        MotifFermeture::Temporaire->value => 'Travaux, congés, saison : seules ses journées sont clôturées.',
                        MotifFermeture::Cessation->value => 'L\'activité de ce magasin s\'arrête.',
                    ])
                    ->required(),
                self::champMotDePasse(),
            ])
            ->action(fn (array $data) => $this->agir(
                fn () => app(FermerMagasinAction::class)->handle($this->auteur(), $this->magasin(), MotifFermeture::from((string) $data['motif']), (string) $data['mot_de_passe']),
                fn (): string => "Le magasin {$this->magasin()->nom} est fermé.",
            ));
    }

    public function cederAction(): Action
    {
        return Action::make('ceder')
            ->label('Céder le magasin')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('danger')
            ->visible(fn (): bool => $this->peut('cederLeMagasin') && $this->magasin()->societe->estValidee() && ! $this->estCede())
            ->modalDescription(fn (): string => ($this->magasin()->estFerme()
                ? 'La cession est définitive : le magasin, déjà fermé, ne se rouvrira plus, et il n\'est ni rouvert ni recontrôlé au répertoire Sirene pour être cédé.'
                : 'La cession est définitive : le magasin est fermé et ne se rouvrira pas. Ses journées en attente sont clôturées.')
                .' Tout son stock sort au coût moyen ; sa valeur hors taxe vous est remise, à rapprocher du prix de cession prévu à l\'acte avec votre expert-comptable. Ses factures, paiements et archives restent dans votre société. L\'activité reprise s\'ouvre dans le compte de la société qui la reprend.')
            ->form([
                TextInput::make('confirmation')
                    ->label(fn (): string => "Retapez le nom du magasin : {$this->magasin()->nom}")
                    ->required(),
                self::champMotDePasse(),
            ])
            ->action(fn (array $data) => $this->agir(
                fn () => app(FermerMagasinAction::class)->handle($this->auteur(), $this->magasin(), MotifFermeture::Cession, (string) $data['mot_de_passe'], (string) $data['confirmation']),
                fn (): string => "Le magasin {$this->magasin()->nom} est cédé : la valeur de son stock sorti vous est remise dans vos notifications.",
            ));
    }

    public function rouvrirAction(): Action
    {
        return Action::make('rouvrir')
            ->label('Rouvrir le magasin')
            ->icon('heroicon-o-lock-open')
            ->visible(fn (): bool => $this->peut('rouvrirLeMagasin') && $this->magasin()->estFerme() && ! $this->estCede())
            ->modalDescription(fn (): string => $this->magasin()->societe->estValidee()
                ? 'Son établissement est contrôlé au répertoire Sirene. Sa caisse reprend au premier jour non clôturé, avec le même registre, les mêmes séries et les mêmes grands totaux ; aucun ticket Z n\'est produit pour ses jours de fermeture. Une suspension décidée par l\'éditeur reste en place.'
                : 'Son établissement est contrôlé au répertoire Sirene. Votre société n\'étant pas encore validée, le magasin rouvert ne vend qu\'après sa validation, qui le met en activité avec vos autres magasins ouverts.')
            ->form([self::champMotDePasse()])
            ->action(fn (array $data) => $this->agir(
                fn () => app(RouvrirMagasinAction::class)->handle($this->auteur(), $this->magasin(), (string) $data['mot_de_passe']),
                fn (): string => match (true) {
                    $this->magasin()->etablissement_verifie_le === null => "Le magasin {$this->magasin()->nom} est rouvert : le répertoire Sirene n'ayant pas répondu, son établissement sera vérifié avant toute vente.",
                    ! $this->magasin()->societe->estValidee() => "Le magasin {$this->magasin()->nom} est rouvert : il vendra une fois votre société validée.",
                    default => "Le magasin {$this->magasin()->nom} est rouvert.",
                },
            ));
    }

    /**
     * Exécute le geste ; un refus de l'action (geste, droit, donnée)
     * s'affiche tel quel, sans rien changer à la page. Réussi, le magasin
     * se relit, le formulaire se remplit de nouveau et le message de
     * réussite s'affiche.
     *
     * @param  Closure(): mixed  $geste
     * @param  Closure(): string  $reussite
     */
    private function agir(Closure $geste, Closure $reussite): void
    {
        $fait = false;

        static::executerSansCasser(function () use ($geste, &$fait): void {
            try {
                $geste();
                $fait = true;
            } catch (ValidationException $refus) {
                Notification::make()->title(implode(' ', array_merge(...array_values($refus->errors()))))->danger()->send();
            }
        });

        if ($fait) {
            $this->rafraichir();
            Notification::make()->title($reussite())->success()->send();
        }
    }

    private function rafraichir(): void
    {
        $this->magasin()->refresh();
        $this->cede = null;
        $this->fillForm();
    }

    private function estCede(): bool
    {
        return $this->cede ??= $this->magasin()->aEteCede();
    }

    private static function champMotDePasse(): TextInput
    {
        return TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->autocomplete('current-password')->required();
    }

    private function peut(string $geste): bool
    {
        return auth()->user()?->can($geste, $this->magasin()) ?? false;
    }

    private function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }

    private function magasin(): Magasin
    {
        /** @var Magasin */
        return $this->getRecord();
    }
}
