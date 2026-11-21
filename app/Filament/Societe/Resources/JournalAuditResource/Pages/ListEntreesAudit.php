<?php

namespace App\Filament\Societe\Resources\JournalAuditResource\Pages;

use App\Filament\Societe\Resources\JournalAuditResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Les entrées du journal d'audit de la société, les plus récentes
 * d'abord, sans aucun geste d'écriture.
 */
class ListEntreesAudit extends ListRecords
{
    protected static string $resource = JournalAuditResource::class;
}
