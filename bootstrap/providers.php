<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\SuperAdminPanelProvider;
use App\Providers\PdpServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    PdpServiceProvider::class,
    AppPanelProvider::class,
    SuperAdminPanelProvider::class,
    TenancyServiceProvider::class,
];
