<?php

namespace App\Filament\Concerns;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Models\ChangementFinExercice;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\SensDuTri;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\FontFamily;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * Le journal d'audit de la société affichée, en lecture seule, tel que le
 * consulte son propriétaire, seul à le faire (voir EntreeAuditPolicy),
 * dans l'espace d'administration de sa société (voir
 * JournalAuditResource) ou dans l'espace de son cabinet d'expertise
 * comptable (voir JournalAuditDuCabinetResource) : chaque entrée avec sa
 * date à l'heure de Paris, celle de la société et du journal, suivie de
 * « (heure d'été) » ou « (heure d'hiver) » pendant l'heure qui se répète
 * (voir Calendrier::afficherDansUnJournal()), sa catégorie, son action, le
 * magasin qu'elle concerne ou la société, son auteur tel que le journal
 * a figé son nom au moment de l'opération (jamais le nom actuel de son
 * compte), l'éditeur pour une de ses procédures, le système, ou « l'autre
 * société » pour le geste d'une personne d'une autre société sur une
 * demande de lien avec un cabinet (voir
 * JournalAudit::inscrireLeGesteDeLAutreSociete()) ; et, sur sa fiche, les
 * anciennes et les nouvelles valeurs que son action retient, la trace
 * technique de la requête qui l'a inscrite (adresse IP et navigateur,
 * hors de son empreinte, effacée après sa durée de conservation, voir
 * TraceAudit), sa place dans sa chaîne et la fin de sa conservation. D'une
 * procédure de l'éditeur, la fiche dit seulement qu'il l'a faite : sa
 * justification, sa pièce, ses opérateurs, l'utilisateur et la machine
 * d'où elle a été lancée restent chez l'éditeur, comme dans les avis qu'il
 * envoie, et ne se montrent jamais à une société.
 *
 * Les filtres ne proposent que la société affichée et ses magasins :
 * toute requête passe par SocieteScope et le tenant du panneau, et une
 * valeur forcée d'un magasin d'une autre société ne rend rien. Aucune
 * entrée ne se crée, ne se modifie ni ne se supprime ici.
 */
trait AfficheLeJournalDAudit
{
    /**
     * Ce que l'écran nomme pour l'objet d'une entrée, par sa classe : jamais
     * un nom de classe à l'écran.
     */
    private const OBJETS = [
        Avoir::class => 'Avoir',
        AvoirFournisseur::class => 'Avoir fournisseur',
        Cabinet::class => 'Vérification du cabinet',
        ChangementFinExercice::class => 'Changement de la date de clôture de l\'exercice',
        ClotureCaisse::class => 'Clôture de caisse',
        ClotureCaissePersonnelle::class => 'Relevé de caisse',
        CodeConfirmation::class => 'Courrier recommandé',
        Devis::class => 'Devis',
        EnvoiDocument::class => 'Envoi d\'un document',
        Facture::class => 'Facture ou ticket',
        FactureAchat::class => 'Facture d\'achat',
        InventaireOuverture::class => 'Inventaire d\'ouverture',
        InvitationCabinet::class => 'Invitation d\'un client',
        LienCabinet::class => 'Lien avec un cabinet',
        LigneAvoir::class => 'Ligne d\'avoir',
        Magasin::class => 'Magasin',
        Paiement::class => 'Paiement',
        PieceVerification::class => 'Pièce déposée',
        Produit::class => 'Produit',
        Societe::class => 'Société',
        TransmissionPropriete::class => 'Transmission de la propriété',
        User::class => 'Compte',
        VerificationSociete::class => 'Vérification de la société',
    ];

    private const ORIGINES = [
        'membre' => 'Une personne',
        'editeur' => 'L\'éditeur',
        'systeme' => 'Le système',
        'autre_societe' => 'L\'autre société',
    ];

    /**
     * Les entrées de la société affichée, avec leur magasin et leur trace.
     *
     * @return Builder<EntreeAudit>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['magasin:id,societe_id,nom,code', 'trace']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date (heure de Paris)')
                    ->formatStateUsing(fn (EntreeAudit $record): string => Calendrier::deParis()->afficherDansUnJournal($record->created_at, 'd/m/Y H:i:s'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('id', SensDuTri::de($direction))),
                Tables\Columns\TextColumn::make('categorie')->label('Catégorie')->badge(),
                Tables\Columns\TextColumn::make('action')->label('Action')->wrap(),
                Tables\Columns\TextColumn::make('portee')
                    ->label('Magasin')
                    ->state(fn (EntreeAudit $record): string => self::portee($record))
                    ->visible(fn (): bool => self::societeAffichee()->type_activite->aDesMagasins()),
                Tables\Columns\TextColumn::make('auteur')
                    ->label('Auteur')
                    ->state(fn (EntreeAudit $record): string => self::auteur($record))
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categorie')->label('Catégorie')->options(CategorieAudit::class),
                Tables\Filters\SelectFilter::make('action')
                    ->label('Action')
                    ->options(collect(ActionAuditee::cases())->mapWithKeys(fn (ActionAuditee $action): array => [$action->value => $action->getLabel()])->sort()->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('portee')
                    ->label('Magasin')
                    ->options(fn (): array => self::portees())
                    ->query(fn (Builder $query, array $data): Builder => match (true) {
                        blank($data['value'] ?? null) => $query,
                        $data['value'] === 'societe' => $query->whereNull('magasin_id'),
                        ctype_digit(ValeurSimple::enTexte($data['value'])) => $query->where('magasin_id', ValeurSimple::enEntier($data['value'])),
                        default => $query->whereRaw('1 = 0'),
                    })
                    ->visible(fn (): bool => self::societeAffichee()->type_activite->aDesMagasins()),
                Tables\Filters\SelectFilter::make('origine')->label('Origine')->options(self::ORIGINES),
                Tables\Filters\Filter::make('periode')
                    ->form([
                        DatePicker::make('du')->label('Du'),
                        DatePicker::make('au')->label('Au'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['du'] ?? null), fn (Builder $entrees): Builder => $entrees->where('created_at', '>=', Calendrier::deParis()->debutDuJour(ValeurSimple::enTexte($data['du']))))
                        ->when(filled($data['au'] ?? null), fn (Builder $entrees): Builder => $entrees->where('created_at', '<', Calendrier::deParis()->bornesDuJour(ValeurSimple::enTexte($data['au']))[1])))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['du'] ?? null) ? 'Du '.Calendrier::afficherJour(ValeurSimple::enTexte($data['du']), 'd/m/Y') : null,
                        filled($data['au'] ?? null) ? 'Au '.Calendrier::afficherJour(ValeurSimple::enTexte($data['au']), 'd/m/Y') : null,
                    ]))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Aucune entrée');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Entrée')->schema([
                TextEntry::make('created_at')->label('Date (heure de Paris)')
                    ->formatStateUsing(fn (EntreeAudit $record): string => Calendrier::deParis()->afficherDansUnJournal($record->created_at, 'd/m/Y à H:i:s')),
                TextEntry::make('categorie')->label('Catégorie')->badge(),
                TextEntry::make('action')->label('Action'),
                TextEntry::make('portee')->label('Magasin')
                    ->state(fn (EntreeAudit $record): string => self::portee($record))
                    ->visible(fn (): bool => self::societeAffichee()->type_activite->aDesMagasins()),
                TextEntry::make('auteur')->label('Auteur')->state(fn (EntreeAudit $record): string => self::auteur($record)),
                TextEntry::make('origine')->label('Origine')->state(fn (EntreeAudit $record): string => self::ORIGINES[$record->origine] ?? $record->origine),
                TextEntry::make('objet')->label('Objet')
                    ->state(fn (EntreeAudit $record): ?string => self::objet($record))
                    ->placeholder('Aucun'),
            ])->columns(3),

            Section::make('Valeurs')
                ->description('Les seuls champs que l\'action retient, avant et après elle, tels qu\'ils sont inscrits.')
                ->visible(fn (EntreeAudit $record): bool => $record->anciennes_valeurs !== null || $record->nouvelles_valeurs !== null)
                ->schema([
                    ViewEntry::make('anciennes_valeurs_lisibles')->label('Avant')
                        ->view('filament.app.infolists.contenu-signe')
                        ->state(fn (EntreeAudit $record): string => self::json($record->anciennes_valeurs))
                        ->visible(fn (EntreeAudit $record): bool => $record->anciennes_valeurs !== null),
                    ViewEntry::make('nouvelles_valeurs_lisibles')->label('Après')
                        ->view('filament.app.infolists.contenu-signe')
                        ->state(fn (EntreeAudit $record): string => self::json($record->nouvelles_valeurs))
                        ->visible(fn (EntreeAudit $record): bool => $record->nouvelles_valeurs !== null),
                ]),

            Section::make('Intervention de l\'éditeur')
                ->visible(fn (EntreeAudit $record): bool => $record->origine === 'editeur')
                ->schema([
                    TextEntry::make('procedure')->hiddenLabel()
                        ->state('Une procédure exceptionnelle de l\'éditeur, qui en garde la justification et les pièces : pour toute question, contactez-le.'),
                ]),

            Section::make('Trace technique')
                ->description(fn (): string => 'Hors de l\'empreinte de l\'entrée, effacée '.Reglage::entier('conservation.traces_en_mois').' mois après elle.')
                ->schema([
                    TextEntry::make('trace.ip')->label('Adresse IP')
                        ->placeholder('Aucune (traitement automatique, procédure de l\'éditeur, ou trace effacée)'),
                    TextEntry::make('trace.agent_utilisateur')->label('Navigateur')
                        ->placeholder('Aucun (traitement automatique, procédure de l\'éditeur, ou trace effacée)'),
                ])->columns(2),

            Section::make('Chaîne et conservation')->schema([
                TextEntry::make('sequence')->label('Place dans sa chaîne'),
                TextEntry::make('expire_le')->label('Conservée jusqu\'au')
                    ->state(fn (EntreeAudit $record): string => self::finDeConservation($record)),
                TextEntry::make('empreinte')->label('Empreinte')
                    ->fontFamily(FontFamily::Mono)->copyable()->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    /**
     * Le magasin d'une entrée, par son nom et son code, ou la société.
     */
    private static function portee(EntreeAudit $entree): string
    {
        return $entree->magasin === null ? 'Société' : "{$entree->magasin->nom} ({$entree->magasin->code})";
    }

    /**
     * L'auteur d'une entrée : le nom de la personne figé au moment de
     * l'opération, l'éditeur, le système, ou l'autre société, sans rien
     * d'elle.
     */
    private static function auteur(EntreeAudit $entree): string
    {
        return $entree->origine === 'membre' ? (string) $entree->auteur_libelle : (self::ORIGINES[$entree->origine] ?? '');
    }

    /**
     * La fin de la conservation d'une entrée : sa date d'expiration ; pour
     * une entrée de la vérification de la société, la fin que la fin de la
     * relation a fixée à la société, ou, tant qu'elle ne l'est pas, la durée
     * réglée après cette fin (voir config/conservation.php).
     */
    private static function finDeConservation(EntreeAudit $entree): string
    {
        $fin = $entree->expire_le ?? self::societeAffichee()->verification_conservee_jusqu_au;

        if ($fin !== null) {
            return Calendrier::deParis()->afficher($fin, 'd/m/Y');
        }

        $annees = Reglage::entier('conservation.verification_apres_la_fin_de_la_relation_en_annees');

        return "{$annees} an".($annees > 1 ? 's' : '').' après la fin de la relation avec la société';
    }

    /**
     * L'objet d'une entrée, par la nature de sa fiche et son identifiant ;
     * null pour une entrée sans objet.
     */
    private static function objet(EntreeAudit $entree): ?string
    {
        if ($entree->cible_type === null) {
            return null;
        }

        $classe = Relation::getMorphedModel($entree->cible_type) ?? $entree->cible_type;

        return (self::OBJETS[$classe] ?? 'Fiche')." (identifiant {$entree->cible_id})";
    }

    /**
     * Les choix du filtre des magasins : la société elle-même, puis ses
     * magasins par leur nom, ceux de la société affichée seulement.
     *
     * @return array<int|string, string>
     */
    private static function portees(): array
    {
        return ['societe' => 'La société (hors magasin)'] + self::societeAffichee()->magasins()
            ->orderBy('nom')
            ->orderBy('id')
            ->get(['id', 'nom', 'code'])
            ->mapWithKeys(fn (Magasin $magasin): array => [CleDuModele::de($magasin) => "{$magasin->nom} ({$magasin->code})"])
            ->all();
    }

    private static function societeAffichee(): Societe
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe ? $societe : throw new LogicException('Le journal d\'audit se consulte dans l\'espace d\'une société.');
    }

    /**
     * @param  array<string, mixed>|null  $valeurs
     */
    private static function json(?array $valeurs): string
    {
        return json_encode($valeurs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
