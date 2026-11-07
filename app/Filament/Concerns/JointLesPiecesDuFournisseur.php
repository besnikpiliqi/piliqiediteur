<?php

namespace App\Filament\Concerns;

use App\Domain\Achats\Actions\JoindreDesPiecesAction;
use App\Domain\Achats\Actions\RetirerUnePieceAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Tenancy\Services\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Les pièces du fournisseur à l'écran (décision de l'éditeur du 03/11/2026,
 * lot EX ; voir PiecesDuFournisseur) : le champ de dépôt du formulaire
 * d'une facture d'achat et de la fenêtre d'un avoir fournisseur, la marque
 * « pièce du fournisseur manquante » (fiche, liste, filtre), la liste des
 * pièces d'une fiche avec leur téléchargement, et les gestes de la fiche :
 * « Ajouter des pièces » (en brouillon comme après la validation),
 * « Retirer une pièce » et « Remplacer une pièce » (d'un brouillon
 * seulement). Chaque geste passe par son action du domaine, qui contrôle
 * tout ; l'écran n'est qu'un confort. Après chaque geste, la fiche relit
 * son document, dont la marque a pu changer. La classe qui l'utilise
 * utilise aussi GereLesExceptionsMetier.
 */
trait JointLesPiecesDuFournisseur
{
    /**
     * La marque d'un document saisi à la main sans sa pièce du fournisseur.
     */
    public const PIECE_MANQUANTE = 'Pièce du fournisseur manquante';

    /**
     * Le champ de dépôt des pièces : des PDF ou des photos, dix au plus, de
     * 10 Mo au plus chacun ; les fichiers restent dans le dossier temporaire
     * des envois jusqu'à leur dépôt (voir PieceDeposee).
     */
    public static function champDesPieces(string $libelle, string $aide): FileUpload
    {
        return FileUpload::make('pieces')
            ->label($libelle)
            ->helperText($aide)
            ->multiple()
            ->maxFiles(PiecesDuFournisseur::NOMBRE_MAXIMAL_PAR_DOCUMENT)
            ->maxSize(PiecesDuFournisseur::TAILLE_MAXIMALE_EN_OCTETS / 1024)
            ->acceptedFileTypes(array_keys(PiecesDuFournisseur::TYPES))
            ->storeFiles(false)
            ->columnSpanFull();
    }

    /**
     * La marque sur la fiche d'une facture d'achat ou d'un avoir
     * fournisseur.
     */
    public static function marquePieceManquante(): TextEntry
    {
        return TextEntry::make('piece_manquante')
            ->label('Pièce du fournisseur')
            ->state(fn (FactureAchat|AvoirFournisseur $record): ?string => $record->piece_manquante ? self::PIECE_MANQUANTE : null)
            ->badge()
            ->color('warning')
            ->helperText('Saisi à la main sans la pièce du fournisseur : joignez-la par « Ajouter des pièces ».')
            ->visible(fn (FactureAchat|AvoirFournisseur $record): bool => (bool) $record->piece_manquante);
    }

    /**
     * La colonne « Pièce » d'une liste : le nombre de pièces jointes, ou la
     * marque quand il en manque une ; la liste les compte avec ses lignes
     * (pieces_count, voir getEloquentQuery()).
     */
    public static function colonnePiece(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('pieces_count')
            ->label('Pièce')
            ->state(fn (FactureAchat|AvoirFournisseur $record): ?string => match (true) {
                (bool) $record->piece_manquante => 'Manquante',
                (int) $record->pieces_count === 0 => null,
                default => $record->pieces_count.' '.((int) $record->pieces_count > 1 ? 'pièces' : 'pièce'),
            })
            ->badge()
            ->color(fn (FactureAchat|AvoirFournisseur $record): string => $record->piece_manquante ? 'warning' : 'gray')
            ->placeholder('—');
    }

    /**
     * Le filtre des documents marqués « pièce du fournisseur manquante ».
     */
    public static function filtrePieceManquante(): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make('piece_manquante')
            ->label(self::PIECE_MANQUANTE)
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->where('piece_manquante', true));
    }

    /**
     * La section des pièces d'une fiche : chacune avec son nom, qui la
     * télécharge (voir TelechargerUnePieceDuFournisseurController), sa
     * taille, et qui l'a jointe, quand.
     */
    public static function sectionDesPieces(): InfolistSection
    {
        return InfolistSection::make('Pièces du fournisseur')
            ->schema([
                self::marquePieceManquante(),
                RepeatableEntry::make('pieces_jointes')
                    ->hiddenLabel()
                    ->state(fn (FactureAchat|AvoirFournisseur $record) => $record->pieces()->get())
                    ->schema([
                        // Un lien de téléchargement (attribut download) : le
                        // navigateur enregistre le fichier sous son nom
                        // d'origine sans quitter la fiche.
                        TextEntry::make('nom_origine')
                            ->label('Fichier')
                            ->state(fn (PieceDuFournisseur $record): HtmlString => new HtmlString('<a href="'.e(self::adresseDeLaPiece($record)).'" download="'.e($record->nom_origine).'" class="text-primary-600 underline dark:text-primary-400">'.e($record->nom_origine).'</a>'))
                            ->html(),
                        TextEntry::make('taille')
                            ->label('Taille')
                            ->state(fn (PieceDuFournisseur $record): string => $record->tailleLisible()),
                        TextEntry::make('ajoutee_par_nom')
                            ->label('Jointe par')
                            ->state(fn (PieceDuFournisseur $record): ?string => $record->ajoutee_par_nom)
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Le')
                            ->state(fn (PieceDuFournisseur $record): mixed => $record->created_at)
                            ->dateTime('d/m/Y à H:i'),
                    ])
                    ->columns(4)
                    ->visible(fn (FactureAchat|AvoirFournisseur $record): bool => $record->pieces()->exists()),
                TextEntry::make('aucune_piece')
                    ->hiddenLabel()
                    ->state('Aucune pièce jointe.')
                    ->visible(fn (FactureAchat|AvoirFournisseur $record): bool => $record->pieces()->doesntExist() && ! $record->piece_manquante),
            ]);
    }

    /**
     * L'adresse de téléchargement d'une pièce, dans son magasin.
     */
    public static function adresseDeLaPiece(PieceDuFournisseur $piece): string
    {
        return route('filament.app.pieces-du-fournisseur.telecharger', ['tenant' => app(TenantContext::class)->magasin(), 'piece' => $piece]);
    }

    /**
     * « Ajouter des pièces » sur la fiche d'un document, à qui peut y
     * joindre une pièce (voir JoindreDesPiecesAction).
     */
    public static function ajouterDesPieces(): Action
    {
        return Action::make('ajouter_des_pieces')
            ->label('Ajouter des pièces')
            ->icon('heroicon-o-paper-clip')
            ->modalHeading('Ajouter des pièces du fournisseur')
            ->form([self::champDesPieces('Pièces du fournisseur', 'La vraie facture ou la vraie note d\'avoir du fournisseur, en PDF ou en photo (JPEG, PNG), 10 Mo au plus par fichier.')->required()])
            ->modalSubmitActionLabel('Joindre')
            ->visible(fn (FactureAchat|AvoirFournisseur $record): bool => auth()->user()->can('joindreUnePiece', $record))
            ->action(function (FactureAchat|AvoirFournisseur $record, array $data): void {
                try {
                    static::executerSansCasser(function () use ($record, $data): void {
                        $jointes = app(JoindreDesPiecesAction::class)->handle(auth()->user(), $record, PieceDeposee::lire($data['pieces'] ?? []));
                        $record->refresh();
                        Notification::make()->title(count($jointes) > 1 ? count($jointes).' pièces jointes.' : 'Pièce jointe.')->success()->send();
                    });
                } finally {
                    PieceDeposee::effacerLesEnvois($data['pieces'] ?? []);
                }
            });
    }

    /**
     * « Retirer une pièce » d'une facture d'achat en brouillon, avec une
     * confirmation (voir RetirerUnePieceAction).
     */
    public static function retirerUnePiece(): Action
    {
        return Action::make('retirer_une_piece')
            ->label('Retirer une pièce')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading('Retirer une pièce du fournisseur')
            ->modalDescription('La pièce se retire de ce brouillon et son fichier s\'efface. Une fois la facture validée, aucune pièce ne se retire plus.')
            ->form(fn (FactureAchat $record): array => [self::choixDeLaPiece($record)])
            ->modalSubmitActionLabel('Retirer')
            ->visible(fn (FactureAchat $record): bool => self::aDesPiecesARetirer($record))
            ->action(fn (FactureAchat $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                app(RetirerUnePieceAction::class)->handle(auth()->user(), self::pieceChoisie($record, $data['piece']));
                $record->refresh();
                Notification::make()->title('Pièce retirée.')->success()->send();
            }));
    }

    /**
     * « Remplacer une pièce » d'une facture d'achat en brouillon : la pièce
     * choisie se retire et le fichier déposé la remplace, d'un seul geste
     * (voir RetirerUnePieceAction).
     */
    public static function remplacerUnePiece(): Action
    {
        return Action::make('remplacer_une_piece')
            ->label('Remplacer une pièce')
            ->icon('heroicon-o-arrow-path')
            ->modalHeading('Remplacer une pièce du fournisseur')
            ->form(fn (FactureAchat $record): array => [
                self::choixDeLaPiece($record),
                self::champDesPieces('Nouvelle pièce', 'Le bon fichier, en PDF ou en photo (JPEG, PNG), 10 Mo au plus.')->multiple(false)->required(),
            ])
            ->modalSubmitActionLabel('Remplacer')
            ->visible(fn (FactureAchat $record): bool => self::aDesPiecesARetirer($record))
            ->action(function (FactureAchat $record, array $data): void {
                try {
                    static::executerSansCasser(function () use ($record, $data): void {
                        [$remplacante] = PieceDeposee::lire($data['pieces'] ?? []) + [null];
                        app(RetirerUnePieceAction::class)->handle(auth()->user(), self::pieceChoisie($record, $data['piece']), $remplacante);
                        $record->refresh();
                        Notification::make()->title('Pièce remplacée.')->success()->send();
                    });
                } finally {
                    PieceDeposee::effacerLesEnvois($data['pieces'] ?? []);
                }
            });
    }

    private static function aDesPiecesARetirer(FactureAchat $record): bool
    {
        return $record->statut === StatutFactureAchat::Brouillon && auth()->user()->can('retirerUnePiece', $record) && $record->pieces()->exists();
    }

    private static function choixDeLaPiece(FactureAchat $record): Select
    {
        return Select::make('piece')
            ->label('Pièce')
            ->options(fn (): array => $record->pieces()->get()->mapWithKeys(fn (PieceDuFournisseur $piece): array => [$piece->uuid => $piece->nom_origine.' ('.$piece->tailleLisible().')'])->all())
            ->required();
    }

    /**
     * La pièce de ce document que désigne le choix ; aucune autre, même
     * d'une autre facture du magasin.
     */
    private static function pieceChoisie(FactureAchat $record, mixed $uuid): PieceDuFournisseur
    {
        return PieceDuFournisseur::query()->where('facture_achat_id', $record->getKey())->parUuid(is_string($uuid) ? $uuid : '')->firstOrFail();
    }
}
