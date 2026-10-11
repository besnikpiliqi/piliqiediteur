<?php

use App\Console\Commands\ClotureAutomatiqueCaisse;
use App\Console\Commands\ExpirerDevis;
use App\Console\Commands\MarquerFacturesEnRetard;
use App\Console\Commands\PurgerDemandesInscription;
use App\Console\Commands\PurgerJournalAudit;
use App\Console\Commands\PurgerTraces;
use App\Console\Commands\RelancerFacturesImpayees;
use App\Console\Commands\ReverifierSirene;
use App\Console\Commands\SoumettreEReportingPeriodique;
use App\Console\Commands\VerifierIntegriteFiscale;
use App\Console\Commands\VerifierJournalAudit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hors du développement et des tests, une requête n'est servie que
        // pour l'hôte d'APP_URL (et ses sous-domaines) : une adresse
        // absolue construite depuis la requête, comme celle d'un e-mail
        // « Mot de passe oublié », ne peut pas mener ailleurs qu'au
        // logiciel par un en-tête Host forgé.
        $middleware->trustHosts();
    })
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
        // À 3 h 30 : la vérification des chaînes du journal d'audit de
        // chaque société (voir VerifierJournalAudit).
        $schedule->command(VerifierJournalAudit::class)->dailyAt('03:30');
        // À 4 h : les traces techniques (adresse IP et navigateur) dont la
        // durée de conservation est passée s'effacent (voir PurgerTraces),
        // puis les entrées expirées du journal d'audit, par le début de
        // chaque chaîne (voir PurgerJournalAudit).
        $schedule->command(PurgerTraces::class)->dailyAt('04:00');
        $schedule->command(PurgerJournalAudit::class)->dailyAt('04:15');
        // Chaque heure : les demandes d'inscription expirées depuis 48
        // heures s'effacent (voir PurgerDemandesInscription), et les
        // contrôles Sirene restés impossibles reprennent (voir
        // ReverifierSirene) ; le premier du mois, à 5 h, les sociétés
        // validées et leurs magasins sont revérifiés.
        $schedule->command(PurgerDemandesInscription::class)->hourly();
        $schedule->command(ReverifierSirene::class)->hourly();
        $schedule->command(ReverifierSirene::class, ['--toutes'])->monthlyOn(1, '05:00');
        // Les relances écrivent aux clients : en matinée plutôt qu'en pleine
        // nuit, et bien après le passage en retard des factures de la veille.
        $schedule->command(RelancerFacturesImpayees::class)->dailyAt('09:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
