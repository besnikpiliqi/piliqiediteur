<?php

namespace App\Filament\App\Resources\EReportingResource\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Filament\App\Resources\EReportingResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewEReporting extends ViewRecord
{
    protected static string $resource = EReportingResource::class;

    /**
     * « E-reporting du 05/10/2026 » : la journée de la clôture.
     */
    public function getTitle(): string|Htmlable
    {
        return 'E-reporting du '.Calendrier::afficherJour($this->getRecord()->getAttribute('date_cloture'), 'd/m/Y');
    }
}
