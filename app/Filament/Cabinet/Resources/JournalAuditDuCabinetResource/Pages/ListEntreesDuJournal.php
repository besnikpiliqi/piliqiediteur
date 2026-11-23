<?php

namespace App\Filament\Cabinet\Resources\JournalAuditDuCabinetResource\Pages;

use App\Filament\Cabinet\Resources\JournalAuditDuCabinetResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Les entrées du journal d'audit du cabinet, les plus récentes d'abord,
 * sans aucun geste d'écriture.
 */
class ListEntreesDuJournal extends ListRecords
{
    protected static string $resource = JournalAuditDuCabinetResource::class;
}
