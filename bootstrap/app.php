<?php

use App\Console\Commands\ClotureAutomatiqueCaisse;
use App\Console\Commands\MarquerFacturesEnRetard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // À minuit pile : clôture automatiquement la veille pour tout
        // magasin qui ne l'a pas fait lui-même (e-reporting B2C, voir
        // ClotureAutomatiqueCaisse). Puis, 15 minutes plus tard, une fois
        // cette clôture terminée, on statue sur les factures en retard.
        $schedule->command(ClotureAutomatiqueCaisse::class)->dailyAt('00:00');
        $schedule->command(MarquerFacturesEnRetard::class)->dailyAt('00:15');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
