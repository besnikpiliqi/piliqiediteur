<?php

use App\Providers\AntivirusServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CourrielsServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\CabinetPanelProvider;
use App\Providers\Filament\SocietePanelProvider;
use App\Providers\FournisseursExternesServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    AppPanelProvider::class,
    SocietePanelProvider::class,
    CabinetPanelProvider::class,
    TenancyServiceProvider::class,
    FournisseursExternesServiceProvider::class,
    AntivirusServiceProvider::class,
    CourrielsServiceProvider::class,
];
