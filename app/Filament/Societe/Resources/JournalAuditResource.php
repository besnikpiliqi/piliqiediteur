<?php

namespace App\Filament\Societe\Resources;

use App\Domain\Tenancy\Models\EntreeAudit;
use App\Filament\Concerns\AfficheLeJournalDAudit;
use App\Filament\Societe\Resources\JournalAuditResource\Pages;
use App\Filament\Societe\RessourceDuPanneauSociete;

/**
 * Le journal d'audit de la société, dans son espace d'administration,
 * ouvert à son propriétaire seul (voir EntreeAuditPolicy), jamais à un
 * administrateur, quelles que soient ses autorisations : la liste de ses
 * entrées, avec leurs filtres, et la fiche de chacune, sa trace technique
 * comprise (voir AfficheLeJournalDAudit). Rien ne s'y écrit.
 */
class JournalAuditResource extends RessourceDuPanneauSociete
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
            'index' => Pages\ListEntreesAudit::route('/'),
            'view' => Pages\VoirEntreeAudit::route('/{record}'),
        ];
    }
}
