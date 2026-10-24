<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\SocietePanelProvider;
use App\Providers\PdpServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\VerificationServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    PdpServiceProvider::class,
    AppPanelProvider::class,
    SocietePanelProvider::class,
    TenancyServiceProvider::class,
    VerificationServiceProvider::class,
];
