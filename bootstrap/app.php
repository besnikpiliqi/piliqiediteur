<?php

use App\Console\Commands\ClotureAutomatiqueCaisse;
use App\Console\Commands\ExpirerDevis;
use App\Console\Commands\MarquerFacturesEnRetard;
use App\Console\Commands\PurgerTraces;
use App\Console\Commands\RelancerFacturesImpayees;
use App\Console\Commands\SoumettreEReportingPeriodique;
use App\Console\Commands\VerifierIntegriteFiscale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware()
    ->withSchedule(function (Schedule $schedule): void {
        // À minuit pile, heure de Paris comme tous les horaires ci-dessous
        // (voir config/app.php) : clôture, dans l'ordre, les journées de
        // caisse encore ouvertes jusqu'à la veille, celle qui vient de finir
        // et toute journée manquée, pour tout magasin qui ne l'a pas fait
        // lui-même (voir ClotureAutomatiqueCaisse). Puis, 15 minutes plus
        // tard, une fois ces clôtures terminées, on statue sur les factures
        // en retard.
        $schedule->command(ClotureAutomatiqueCaisse::class)->dailyAt('00:00');
        $schedule->command(MarquerFacturesEnRetard::class)->dailyAt('00:15');
        // Les devis envoyés dont la date de validité était la veille
        // expirent : leur client ne peut plus les accepter (voir
        // ExpirerDevis).
        $schedule->command(ExpirerDevis::class)->dailyAt('00:30');
        // L'e-reporting n'est transmis que par période entière déjà
        // terminée (décade, mois ou bimestre selon le régime de TVA, voir
        // CalculateurPeriodeEReporting), jamais quotidiennement. Il agrège
        // les clôtures de la période : il passe donc après la clôture
        // automatique de minuit, qui a clôturé sa dernière journée.
        $schedule->command(SoumettreEReportingPeriodique::class)->dailyAt('01:00');
        // À 3 h, une fois ces traitements de la nuit terminés : la
        // vérification de l'intégrité de la caisse sécurisée de chaque
        // magasin, incrémentale, complète une fois par mois à un jour
        // propre à chaque magasin (voir VerifierIntegriteFiscale).
        $schedule->command(VerifierIntegriteFiscale::class)->dailyAt('03:00');
        // À 4 h : les traces techniques (adresse IP et navigateur) dont la
        // durée de conservation est passée s'effacent (voir PurgerTraces).
        $schedule->command(PurgerTraces::class)->dailyAt('04:00');
        // Les relances écrivent aux clients : en matinée plutôt qu'en pleine
        // nuit, et bien après le passage en retard des factures de la veille.
        $schedule->command(RelancerFacturesImpayees::class)->dailyAt('09:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
