<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\RetirerLeLienDuDevisAction;
use App\Domain\Facturation\Actions\SupprimerDevisAction;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\RequeteDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\FicheDUnDocument;
use App\Filament\App\Resources\DevisResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDeVente;
use App\Filament\Concerns\ChoisitLaNatureDeLOperation;
use App\Filament\Concerns\EnvoieLesDocuments;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Filament\Actions\DeleteAction;
use Filament\Actions\MountableAction;
use Filament\Actions\StaticAction;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DevisResource extends Resource
{
    use AjouteDesLignesDeVente;
    use ChoisitLaNatureDeLOperation;
    use EnvoieLesDocuments;
    use GereLesExceptionsMetier;
    use SupprimeEnMasseLigneParLigne;

    /**
     * Ce que dit la fenêtre de « Transformer en facture » à qui peut le
     * faire.
     */
    private const TRANSFORMATION = 'Une facture brouillon est créée avec exactement les lignes, les prix, les remises et les taux de ce devis accepté ; vous l\'émettez ensuite, et l\'émission contrôle le stock. Le devis reste, transformé et figé.';

    protected static ?string $model = Devis::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'devis';

    protected static ?string $pluralModelLabel = 'devis';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Devis')->schema([
                Select::make('client_id')
                    ->label('Client')
                    // Un client archivé ne reçoit plus de nouveau document,
                    // même si ceux qu'il a déjà le gardent (voir
                    // Devis::client() et AppServiceProvider::limiterLesChoixAuMagasin()).
                    ->relationProposable('client', 'nom', ClientResource::colonnesDeRecherche())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::ajusterLaNatureAuClient($get, $set))
                    ->helperText(fn (Get $get) => ClientResource::soldeCrediteurHelperText($get('client_id')))
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(CompteConnecte::exiger()->can(PermissionRegistry::CLIENT_CREATE)))
                    ->createOptionUsing(fn (array $data): int => ClientResource::creerUnClient(ValeursLues::champs($data)))
                    ->suffixAction(ClientResource::actionChercherUnClient()),
                DatePicker::make('date_validite')->label('Valide jusqu\'au'),
                self::champNatureDeLOperation(),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->maxLength(PiedDePage::CARACTERES_DES_CONDITIONS_DE_PAIEMENT)->helperText(FactureResource::INFORMATION_ACOMPTE)->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            self::sectionLivraison(),

            Section::make('Lignes')->schema([
                // La page de modification n'est ouverte qu'à un devis
                // modifiable (voir Devis::estModifiable() et
                // DevisPolicy::update()), lignes comprises.
                ...self::lignesDeVente(),
                self::totauxDuDocument(parTaux: true),
            ]),
        ]);
    }

    /**
     * Un devis refusé, expiré ou transformé en facture n'est plus
     * modifiable : sans cette fiche, ses lignes et ses totaux ne seraient
     * plus visibles nulle part. Mêmes colonnes que la facture (voir
     * FactureResource::lignesEntry()).
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Devis')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore envoyé)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('client.nom')->label('Client'),
                // Repassé en brouillon par une modification, un devis garde
                // la date de son dernier envoi (voir ModifierDevisAction).
                TextEntry::make('date_emission')->label('Dernier envoi le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('date_validite')->label('Valide jusqu\'au')->jour()->placeholder('—'),
                FactureResource::natureEntry(),
                TextEntry::make('accepte_le')
                    ->label('Accepté le')
                    ->state(fn (Devis $record): ?string => $record->accepte_le === null ? null : $record->magasin->calendrier()->afficher($record->accepte_le, 'd/m/Y à H:i'))
                    ->visible(fn (Devis $record): bool => $record->accepte_le !== null),
                // La facture d'un devis transformé, et un lien vers sa fiche
                // pour qui peut la voir (voir FicheDUnDocument).
                TextEntry::make('facture_transformee')
                    ->label('Transformé en facture')
                    ->state(fn (Devis $record): ?string => $record->factures->first()?->numero ?? ($record->factures->isNotEmpty() ? 'Facture brouillon' : null))
                    ->url(fn (Devis $record): ?string => FicheDUnDocument::adresse($record->factures->first()))
                    ->color(fn (Devis $record): ?string => FicheDUnDocument::adresse($record->factures->first()) === null ? null : 'primary')
                    ->visible(fn (Devis $record): bool => $record->factures->isNotEmpty()),
                // Ce qu'un compte qui ne peut pas créer de facture lit sous le
                // bouton désactivé « Transformer en facture ».
                TextEntry::make('transformation_impossible')
                    ->label('Transformation en facture')
                    ->state(CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT)
                    ->visible(fn (Devis $record): bool => $record->statut === StatutDevis::Accepte && CompteConnecte::exiger()->cannot('convertir', $record)),
            ])->columns(3),

            self::reponseEnLigneSection(),

            FactureResource::livraisonSection(),

            InfolistSection::make('Lignes')->schema([
                FactureResource::lignesEntry($infolist->getRecord()),
            ]),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
            ])->columns(3),

            self::envoisSection(),
        ]);
    }

    /**
     * Les devis de la liste, avec leur magasin chargé une fois pour toute
     * la liste : la validité de chacun se compte au jour de son calendrier
     * (voir Devis::validiteDepassee()), sans relire le magasin à chaque
     * ligne.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('magasin')->withExists('factures');
    }

    /**
     * La liste des devis du magasin actif, filtrée sur les brouillons : où
     * mène l'« À faire » du tableau de bord (voir ChosesAFaireDuMagasin).
     */
    public static function urlDesBrouillons(): string
    {
        return static::getUrl('index', ['tableFilters' => ['statut' => ['value' => StatutDevis::Brouillon->value]]]);
    }

    /**
     * La liste des devis envoyés qui expirent dans les sept jours (voir
     * Devis::scopeExpirentBientot()).
     */
    public static function urlDesDevisQuiExpirent(): string
    {
        return static::getUrl('index', ['tableFilters' => ['expirent_bientot' => ['isActive' => true]]]);
    }

    /**
     * La liste des devis acceptés pas encore transformés en facture (voir
     * Devis::scopeATransformer()).
     */
    public static function urlDesDevisATransformer(): string
    {
        return static::getUrl('index', ['tableFilters' => ['a_transformer' => ['isActive' => true]]]);
    }

    /**
     * Ce qui attend encore sur ce devis, dit sur sa ligne (voir
     * ChosesAFaireDuMagasin) : « À envoyer » pour un brouillon, « À
     * transformer en facture » pour un devis accepté sans facture, « Expire
     * le … » pour un devis envoyé dont la validité finit dans les sept
     * jours. Les factures d'un devis de la liste se lisent avec elle
     * (voir getEloquentQuery()).
     *
     * @return list<string>
     */
    public static function raisonsAFaire(Devis $devis): array
    {
        $aujourdhui = $devis->magasin->calendrier()->aujourdhui();

        return array_values(array_filter([
            $devis->statut === StatutDevis::Brouillon ? 'À envoyer' : null,
            $devis->statut === StatutDevis::Accepte && ! (array_key_exists('factures_exists', $devis->getAttributes()) ? (bool) $devis->getAttribute('factures_exists') : $devis->factures()->exists()) ? 'À transformer en facture' : null,
            $devis->statut === StatutDevis::Envoye && $devis->date_validite !== null && $devis->date_validite >= $aujourdhui && $devis->date_validite <= Calendrier::ajouterJours($aujourdhui, Devis::JOURS_DU_RAPPEL_AVANT_LA_FIN)
                ? 'Expire le '.Calendrier::afficherJour($devis->date_validite, 'd/m/Y')
                : null,
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('date_validite')->label('Validité')->jour(),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('a_faire')
                    ->label('À faire')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Devis $record): array => self::raisonsAFaire($record))
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutDevis::class),
                // Les filtres où mène l'« À faire » du tableau de bord, qui
                // comptent ce que la liste montre (voir ChosesAFaireDuMagasin).
                Tables\Filters\Filter::make('expirent_bientot')
                    ->label('Expirent dans les 7 jours')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => RequeteDuModele::de($query, Devis::class)->expirentBientot(app(TenantContext::class)->magasin()->calendrier()->aujourdhui())),
                Tables\Filters\Filter::make('a_transformer')
                    ->label('À transformer en facture')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => RequeteDuModele::de($query, Devis::class)->aTransformer()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Affichée et autorisée par DevisPolicy::update() (voir
                // ListRecords::configureEditAction()) : un brouillon, ou un
                // devis envoyé ou accepté pas encore transformé en facture.
                Tables\Actions\EditAction::make(),
                self::telechargerLePdf(Tables\Actions\Action::make('telecharger_pdf')),
                self::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email')),
                self::envoyerSansEmail(Tables\Actions\Action::make('envoyer')),
                // Masquée une fois la date de validité dépassée (voir
                // DevisPolicy::accepter()).
                Tables\Actions\Action::make('accepter')
                    ->label('Accepter')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && CompteConnecte::exiger()->can('accepter', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => self::avisDeLAcceptation(app(AccepterDevisAction::class)->handle(CompteConnecte::exiger(), $record)))),
                Tables\Actions\Action::make('refuser')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && CompteConnecte::exiger()->can('refuser', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(RefuserDevisAction::class)->handle(CompteConnecte::exiger(), $record))),
                self::actionTransformerDansLaListe(Tables\Actions\Action::make('convertir')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(Devis::class, fn (Devis $devis) => app(SupprimerDevisAction::class)->handle(CompteConnecte::exiger(), $devis)),
                ]),
            ]);
    }

    /**
     * Remis en main propre, par exemple : le devis en brouillon part sans
     * e-mail, avec le numéro qu'il reçoit, ou celui qu'il a gardé s'il a
     * été modifié depuis son envoi (voir EmettreDevisAction) ; sur la ligne
     * de la liste comme sur la fiche, où mène l'« À faire » d'un brouillon
     * seul (voir ChosesAFaireDuMagasin), y compris dans le magasin de
     * démonstration, qui n'écrit à aucun client. La fiche relit ensuite le
     * devis, numéroté et envoyé.
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function envoyerSansEmail(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (Devis $record): string => $record->numero === null ? 'Numéroter sans e-mail' : 'Marquer envoyé sans e-mail')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Devis $record): string => $record->numero === null
                ? 'Le devis reçoit son numéro et passe au statut « Envoyé », sans e-mail : pour un devis remis en main propre, par exemple.'
                : "Le devis garde son numéro {$record->numero} et repasse au statut « Envoyé », sans e-mail : pour un devis remis en main propre, par exemple.")
            ->form([self::champDuJeton()])
            ->visible(fn (Devis $record): bool => $record->statut === StatutDevis::Brouillon && CompteConnecte::exiger()->can('envoyer', $record))
            ->action(fn (Devis $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                app(EmettreDevisAction::class)->handle(CompteConnecte::exiger(), $record, ValeurSimple::enTexteOuNul($data['jeton']));
                $record->refresh();
            }));
    }

    /**
     * « Transformer en facture » (décision de l'éditeur du 02/11/2026),
     * sur la fiche d'un devis accepté : une facture brouillon qui reprend
     * exactement ses lignes, ses prix, ses remises et ses taux (voir
     * CreerFactureDepuisDevisAction), puis la fiche de cette facture, à
     * émettre. Pour qui ne peut pas créer de facture dans ce magasin (le
     * propriétaire hors du magasin de démonstration, un administrateur ou
     * un vendeur sans la vente, un observateur, un compte du cabinet lié),
     * le bouton reste visible, désactivé, au lieu de disparaître ; la fiche
     * en écrit l'explication juste au-dessous (voir infolist()).
     */
    public static function actionTransformer(MountableAction $action): MountableAction
    {
        return $action
            ->label('Transformer en facture')
            ->icon('heroicon-o-document-currency-euro')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(fn (Devis $record): string => "Transformer le devis {$record->numero} en facture")
            ->modalDescription(self::TRANSFORMATION)
            ->modalSubmitActionLabel('Transformer en facture')
            ->visible(fn (Devis $record): bool => $record->statut === StatutDevis::Accepte)
            ->disabled(fn (Devis $record): bool => ! self::peutTransformer($record))
            ->tooltip(fn (Devis $record): ?string => self::peutTransformer($record) ? null : CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT)
            ->action(function (Devis $record, MountableAction $action): void {
                $facture = static::executerOuInterrompre(fn (): Facture => app(CreerFactureDepuisDevisAction::class)->handle(CompteConnecte::exiger(), $record));

                Notification::make()->title("Le devis {$record->numero} est transformé en facture brouillon : vérifiez-la, puis émettez-la.")->success()->send();
                $action->redirect(FactureResource::getUrl('view', ['record' => $facture]));
            });
    }

    /**
     * « Transformer en facture » dans la liste des devis. Un lien désactivé
     * d'une liste ne réagit ni au survol ni au clic, et la liste n'a pas de
     * place pour une ligne d'explication : pour qui ne peut pas créer de
     * facture, le bouton s'y montre grisé, avec un cadenas, et son clic
     * n'ouvre qu'une fenêtre qui l'explique, sans rien transformer (le
     * domaine le refuserait de toute façon, voir
     * CreerFactureDepuisDevisAction).
     */
    public static function actionTransformerDansLaListe(Tables\Actions\Action $action): Tables\Actions\Action
    {
        self::actionTransformer($action);

        return $action
            ->disabled(false)
            ->icon(fn (Devis $record): string => self::peutTransformer($record) ? 'heroicon-o-document-currency-euro' : 'heroicon-o-lock-closed')
            ->color(fn (Devis $record): string => self::peutTransformer($record) ? 'primary' : 'gray')
            ->modalIcon(fn (Devis $record): string => self::peutTransformer($record) ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-lock-closed')
            ->modalDescription(fn (Devis $record): string => self::peutTransformer($record)
                ? self::TRANSFORMATION
                : CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT)
            ->modalSubmitAction(fn (StaticAction $action, Devis $record): StaticAction|false => self::peutTransformer($record) ? $action : false)
            ->modalCancelActionLabel(fn (Devis $record): string => self::peutTransformer($record) ? 'Annuler' : 'Fermer');
    }

    /**
     * La personne connectée peut-elle créer, dans ce magasin, la facture de
     * ce devis (voir DevisPolicy::convertir()) ?
     */
    private static function peutTransformer(Devis $devis): bool
    {
        return CompteConnecte::exiger()->can('convertir', $devis);
    }

    /**
     * « Retirer le lien de réponse » : le client ne peut plus répondre en
     * ligne au devis (voir RetirerLeLienDuDevisAction), pour qui peut
     * l'envoyer, tant qu'un lien vaut encore.
     */
    public static function actionRetirerLeLien(MountableAction $action): MountableAction
    {
        return $action
            ->label('Retirer le lien de réponse')
            ->icon('heroicon-o-link-slash')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Le client ne pourra plus accepter ni refuser ce devis en ligne. Un nouvel envoi par e-mail lui donnera un nouveau lien.')
            ->visible(fn (Devis $record): bool => CompteConnecte::exiger()->can('envoyer', $record) && $record->lienValable() !== null)
            ->action(fn (Devis $record) => static::executerSansCasser(function () use ($record): void {
                app(RetirerLeLienDuDevisAction::class)->handle(CompteConnecte::exiger(), $record);
                Notification::make()->title('Le lien de réponse est retiré : le client ne peut plus répondre en ligne.')->success()->send();
            }));
    }

    /**
     * L'avis de l'acceptation constatée par le magasin : combien de
     * produits du devis n'ont plus assez de stock, et lesquels (décision de
     * l'éditeur du 02/11/2026, option « a »). Les noms des produits
     * s'échappent : Filament interprète en HTML le corps d'un avis.
     */
    private static function avisDeLAcceptation(Devis $devis): void
    {
        $manques = $devis->stocksInsuffisants();

        if ($manques === []) {
            Notification::make()->title("Le devis {$devis->numero} est accepté.")->success()->send();

            return;
        }

        Notification::make()
            ->title("Le devis {$devis->numero} est accepté, mais ".(count($manques) === 1 ? '1 produit n\'a' : count($manques).' produits n\'ont').' plus assez de stock.')
            ->body(e(implode(' ', array_map(fn (array $manque): string => "« {$manque['produit']} » : {$manque['demande']} demandé".($manque['demande'] > 1 ? 's' : '').", {$manque['disponible']} en stock.", $manques))))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * La réponse du client par son lien secret (voir ReponseDuDevis), ou le
     * lien qui attend encore sa réponse : à qui il est parti, jusqu'à quand
     * il vaut.
     */
    private static function reponseEnLigneSection(): InfolistSection
    {
        return InfolistSection::make('Réponse en ligne du client')
            ->schema([
                TextEntry::make('reponse_en_ligne')
                    ->hiddenLabel()
                    ->state(function (Devis $record): string {
                        $reponse = $record->reponseALaVersionEnvoyee();
                        $calendrier = $record->magasin->calendrier();

                        if ($reponse !== null) {
                            $quand = $calendrier->afficher($reponse->created_at, 'd/m/Y à H:i');

                            return $reponse->reponse === ReponseAuDevis::Acceptation
                                ? "Accepté en ligne le {$quand} par « {$reponse->signataire} », avec la mention « Bon pour accord ». Empreinte du devis accepté : {$reponse->empreinte_du_devis}."
                                : "Refusé en ligne le {$quand}".($reponse->motif === null ? '.' : ", motif : « {$reponse->motif} ».");
                        }

                        $lien = $record->lienValable();

                        return 'Le client peut répondre en ligne par le lien envoyé à '.ValeurRequise::de($lien, 'le lien du devis')->envoi->destinataire
                            .($record->date_validite === null ? '.' : ', jusqu\'au '.Calendrier::afficherJour($record->date_validite, 'd/m/Y').'.');
                    }),
            ])
            ->visible(fn (Devis $record): bool => $record->reponseALaVersionEnvoyee() !== null || $record->lienValable() !== null);
    }

    /**
     * « Supprimer » un brouillon jamais numéroté, depuis la page de
     * modification : par l'action du domaine, qui relit sous verrou le
     * magasin, l'auteur et le devis (voir SupprimerDevisAction) ; numéroté
     * par une autre requête depuis l'ouverture de la page, ou dans un
     * magasin fermé entre-temps, il reste, et la page dit pourquoi. Un
     * devis supprimé par une autre requête depuis l'ouverture de la page
     * n'y est plus : la page revient à la liste, qui le dit (voir
     * RevientALaListeSiLeDocumentEstSupprime).
     */
    public static function actionSupprimer(DeleteAction $action): DeleteAction
    {
        return $action->using(fn (Devis $record): bool => static::executerOuInterrompre(function () use ($record): bool {
            app(SupprimerDevisAction::class)->handle(CompteConnecte::exiger(), $record);

            return true;
        }));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevis::route('/'),
            'create' => Pages\CreateDevis::route('/create'),
            'view' => Pages\ViewDevis::route('/{record}'),
            'edit' => Pages\EditDevis::route('/{record}/edit'),
        ];
    }
}
