<?php

namespace App\Filament\Cabinet\Resources;

use App\Domain\Tenancy\Models\EntreeAudit;
use App\Filament\Cabinet\Resources\JournalAuditDuCabinetResource\Pages;
use App\Filament\Cabinet\RessourceDuPanneauCabinet;
use App\Filament\Concerns\AfficheLeJournalDAudit;

/**
 * Le journal d'audit d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet, ouvert à son propriétaire seul (voir EntreeAuditPolicy), jamais
 * à un collaborateur : la liste de ses entrées et la fiche de chacune,
 * comme pour toute société (voir AfficheLeJournalDAudit), sans magasin.
 * Rien ne s'y écrit.
 */
class JournalAuditDuCabinetResource extends RessourceDuPanneauCabinet
{
    use AfficheLeJournalDAudit;

    protected static ?string $model = EntreeAudit::class;

    protected static ?string $tenantOwnershipRelationshipName = 'societe';

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Journal d\'audit';

    protected static ?string $modelLabel = 'entrée du journal d\'audit';

    protected static ?string $pluralModelLabel = 'journal d\'audit';

    protected static ?string $slug = 'journal-audit';

    protected static ?int $navigationSort = 90;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntreesDuJournal::route('/'),
            'view' => Pages\VoirEntreeDuJournal::route('/{record}'),
        ];
    }
}
