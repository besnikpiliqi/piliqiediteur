<?php

namespace App\Filament\App\Resources\EReportingResource\Pages;

use App\Filament\App\Resources\EReportingResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListEReporting extends ListRecords
{
    protected static string $resource = EReportingResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'E-reporting';
    }
}
