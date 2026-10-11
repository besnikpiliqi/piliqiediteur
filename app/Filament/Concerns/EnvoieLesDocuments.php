<?php

namespace App\Filament\Concerns;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les gestes d'envoi communs aux devis, factures et avoirs : télécharger
 * le PDF, l'envoyer par e-mail, relancer une facture impayée, et la
 * section « Envois » de leur fiche.
 *
 * Chaque geste se configure sur une action de tableau comme sur une action
 * de fiche (les deux sont des MountableAction) : par exemple
 * static::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email'))
 * dans la liste, FactureResource::envoyerParEmail(Actions\Action::make(...))
 * dans ViewFacture. La ressource qui l'utilise utilise aussi
 * GereLesExceptionsMetier, qui en affiche les refus.
 */
trait EnvoieLesDocuments
{
    /**
     * Dès que le document a son numéro : un brouillon n'a encore aucune
     * existence pour le client. Le PDF se produit avant le téléchargement :
     * un ticket, une facture ou un avoir qui ne peut pas être reproduit tel
     * qu'il a été émis (voir DocumentSansEnregistrementSigneException), ou
     * tout document d'une société pas encore validée (voir
     * Magasin::verifierQuIlPeutImprimer()), ne se télécharge pas, et
     * l'écran dit pourquoi.
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function telechargerLePdf(MountableAction $action): MountableAction
    {
        return $action
            ->label('PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Model&DocumentEnvoyable $record): bool => filled($record->numero))
            ->action(function (Facture|Devis|Avoir $record): ?StreamedResponse {
                $generateur = match (true) {
                    $record instanceof Facture => app(FacturePdfGenerator::class),
                    $record instanceof Devis => app(DevisPdfGenerator::class),
                    $record instanceof Avoir => app(AvoirPdfGenerator::class),
                };

                try {
                    $pdf = $generateur->generer($record)->output();
                } catch (DocumentSansEnregistrementSigneException|VenteNonAutoriseeException $refus) {
                    static::notifierLeRefus($refus);

                    return null;
                }

                return response()->streamDownload(fn () => print ($pdf), $generateur->nomFichier($record));
            });
    }

    /**
     * Destinataire prérempli avec l'adresse du client, modifiable ; un
     * message facultatif s'ajoute au texte de l'e-mail.
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function envoyerParEmail(MountableAction $action): MountableAction
    {
        return $action
            ->label('Envoyer par e-mail')
            ->icon('heroicon-o-envelope')
            ->color('success')
            ->modalHeading(fn (Model&DocumentEnvoyable $record): string => "Envoyer par e-mail : {$record->libelleDocument()} ".($record->numero ?? '(brouillon)'))
            ->modalDescription(fn (Model&DocumentEnvoyable $record): ?string => $record instanceof Devis && blank($record->numero)
                ? 'Ce devis reçoit son numéro en partant : il ne sera plus un brouillon.'
                : null)
            ->modalSubmitActionLabel('Envoyer')
            ->visible(fn (Model&DocumentEnvoyable $record): bool => $record->peutEtreEnvoye() && auth()->user()->can('envoyer', $record))
            ->fillForm(fn (Model&DocumentEnvoyable $record): array => ['destinataire' => $record->client->email])
            ->form([
                static::champDestinataire(),
                Textarea::make('message')
                    ->label('Message (facultatif)')
                    ->helperText('Ajouté au texte de l\'e-mail, avant votre signature.')
                    ->rows(4)
                    ->maxLength(2000)
                    ->validationMessages(['max' => 'Un message de 2 000 caractères au plus.']),
            ])
            ->action(fn (Model&DocumentEnvoyable $record, array $data) => static::executerSansCasser(function () use ($record, $data) {
                $envoi = app(EnvoyerDocumentAction::class)->handle($record, $data['destinataire'], $data['message'] ?? null, auth()->user());

                static::confirmerLEnvoi($envoi, $record);
            }));
    }

    /**
     * La relance qui suit la dernière, facture jointe, avec le rappel de
     * ce qu'il reste à payer (voir EnvoyerDocumentAction::relancer()).
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function relancer(MountableAction $action): MountableAction
    {
        return $action
            ->label('Relancer')
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->modalHeading(fn (Facture $record): string => 'Relance n°'.($record->niveauDeRelanceAtteint() + 1)." : {$record->libelleDocument()} {$record->numero}")
            ->modalDescription('Le client reçoit la facture en pièce jointe, avec le rappel de son montant, de son échéance et de ce qu\'il reste à payer.')
            ->modalSubmitActionLabel('Relancer')
            ->visible(fn (Facture $record): bool => $record->estEchueEtImpayee() && auth()->user()->can('envoyer', $record))
            ->fillForm(fn (Facture $record): array => ['destinataire' => $record->client->email])
            ->form([static::champDestinataire()])
            ->action(fn (Facture $record, array $data) => static::executerSansCasser(function () use ($record, $data) {
                $envoi = app(EnvoyerDocumentAction::class)->relancer($record, $data['destinataire'], auth()->user());

                static::confirmerLEnvoi($envoi, $record);
            }));
    }

    /**
     * Chaque envoi et chaque relance du document, du plus récent au plus
     * ancien, aboutis ou non.
     */
    public static function envoisSection(): Section
    {
        return Section::make('Envois')
            ->visible(fn (Model&DocumentEnvoyable $record): bool => $record->envois()->exists())
            ->schema([
                RepeatableEntry::make('envois')
                    ->label('')
                    ->state(fn (Model&DocumentEnvoyable $record) => $record->envois()->latest('id')->get())
                    ->schema([
                        TextEntry::make('created_at')->label('Date')->state(fn (EnvoiDocument $record) => $record->envoye_le ?? $record->created_at)->dateTime('d/m/Y à H:i'),
                        TextEntry::make('destinataire')->label('Destinataire')->state(fn (EnvoiDocument $record) => $record->destinataire),
                        TextEntry::make('nature')->label('Nature')->state(fn (EnvoiDocument $record) => $record->nature)->badge(),
                        TextEntry::make('niveau_relance')->label('Niveau')->state(fn (EnvoiDocument $record) => $record->niveau_relance)->prefix('n°')->placeholder('—'),
                        TextEntry::make('statut')->label('Statut')->state(fn (EnvoiDocument $record) => $record->statut)->badge(),
                        TextEntry::make('erreur')->label('Erreur')->state(fn (EnvoiDocument $record) => $record->erreur)->placeholder('—'),
                    ])
                    ->columns(6),
            ]);
    }

    /**
     * La même exigence que EnvoyerDocumentAction : une adresse sans
     * commentaire, guillemets ni domaine sans point, que le serveur d'envoi
     * accepte.
     */
    private static function champDestinataire(): TextInput
    {
        return TextInput::make('destinataire')
            ->label('Destinataire')
            ->type('email')
            ->rule('email:strict')
            ->required()
            ->maxLength(254)
            ->validationMessages([
                'required' => 'Indiquez l\'adresse e-mail du destinataire.',
                'email' => 'Cette adresse e-mail n\'est pas valide.',
                'max' => 'Cette adresse e-mail est trop longue.',
            ]);
    }

    /**
     * Le document de la fiche est relu : son statut (« Envoyée ») et sa
     * section « Envois » se mettent à jour sans recharger la page.
     */
    private static function confirmerLEnvoi(EnvoiDocument $envoi, Model $document): void
    {
        $document->refresh();

        Notification::make()
            ->title("Envoi à {$envoi->destinataire} programmé")
            ->body('Il apparaît dans la section « Envois » de la fiche, avec son résultat.')
            ->success()
            ->send();
    }
}
