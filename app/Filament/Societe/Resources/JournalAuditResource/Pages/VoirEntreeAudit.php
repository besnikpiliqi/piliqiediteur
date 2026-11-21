<?php

namespace App\Filament\Societe\Resources\JournalAuditResource\Pages;

use App\Filament\Societe\Resources\JournalAuditResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'une entrée du journal d'audit de la société, sa trace
 * technique comprise.
 */
class VoirEntreeAudit extends ViewRecord
{
    protected static string $resource = JournalAuditResource::class;
}
