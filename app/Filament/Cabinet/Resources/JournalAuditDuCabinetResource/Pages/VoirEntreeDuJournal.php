<?php

namespace App\Filament\Cabinet\Resources\JournalAuditDuCabinetResource\Pages;

use App\Filament\Cabinet\Resources\JournalAuditDuCabinetResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'une entrée du journal d'audit du cabinet, sa trace technique
 * comprise.
 */
class VoirEntreeDuJournal extends ViewRecord
{
    protected static string $resource = JournalAuditDuCabinetResource::class;
}
