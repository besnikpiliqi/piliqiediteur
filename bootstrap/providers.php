<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\SuperAdminPanelProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    AppPanelProvider::class,
    SuperAdminPanelProvider::class,
    TenancyServiceProvider::class,
];
