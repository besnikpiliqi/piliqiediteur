<?php

use App\Console\Commands\CloreInscriptionsSansCode;
use App\Console\Commands\ClotureAutomatiqueCaisse;
use App\Console\Commands\EffacerPiecesVerification;
use App\Console\Commands\ExpirerDevis;
use App\Console\Commands\FermerDemonstrationsInactives;
use App\Console\Commands\MarquerFacturesEnRetard;
use App\Console\Commands\PurgerActionsTraitees;
use App\Console\Commands\PurgerDemandesInscription;
use App\Console\Commands\PurgerJournalAudit;
use App\Console\Commands\PurgerTraces;
use App\Console\Commands\RelancerFacturesImpayees;
use App\Console\Commands\ReverifierSirene;
use App\Console\Commands\SuivreEnvoisRecommandes;
use App\Console\Commands\VerifierIntegriteFiscale;
use App\Console\Commands\VerifierJournalAudit;
use App\Http\Middleware\FaireConfianceAuxMandataires;
use App\Http\Responses\ReponseAUnDocumentDisparu;
use App\Http\Responses\ReponseDansUnMagasinEnLectureSeule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Seules les adresses exactes du répartiteur de charge, lues de la
        // configuration (PROXIES_DE_CONFIANCE, jamais « * »), donnent
        // l'adresse IP du client par leurs en-têtes : à la place du réglage
        // trustProxies() de Laravel, qui se poserait ici avant le chargement
        // de la configuration (voir FaireConfianceAuxMandataires).
        $middleware->replace(TrustProxies::class, FaireConfianceAuxMandataires::class);
        // Les rappels des prestataires arrivent sans session : ils se
        // prouvent par leur signature (voir RappelsDesPrestatairesController).
        $middleware->validateCsrfTokens(except: ['rappels/*']);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Les tâches du calendrier des magasins passent toutes les heures,
        // heure de Paris comme tous les horaires ci-dessous
        // (schedule_timezone, voir config/app.php), et ne traitent que ce
        // que le calendrier de chaque magasin rend dû (voir
        // Magasin::calendrier()) : le minuit de chaque territoire tombe sur
        // une heure ronde. À la minute 0, la clôture, dans l'ordre, des
        // journées de caisse encore ouvertes jusqu'à la veille de chaque
        // magasin, celle qui vient de finir à son minuit et toute journée
        // manquée, pour tout magasin qui ne l'a pas fait lui-même (voir
        // ClotureAutomatiqueCaisse) ; un passage où rien n'est dû ne
        // verrouille aucun registre. À la minute 15, une fois ces clôtures
        // terminées, le passage en retard des factures échues la veille de
        // leur magasin.
        $schedule->command(ClotureAutomatiqueCaisse::class)->hourly();
        $schedule->command(MarquerFacturesEnRetard::class)->hourlyAt(15);
        // À la minute 30 : les devis envoyés dont la date de validité était
        // la veille de leur magasin expirent ; leur client ne peut plus les
        // accepter (voir ExpirerDevis).
        $schedule->command(ExpirerDevis::class)->hourlyAt(30);
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
        // À 4 h 30 : les jetons des demandes traitées depuis plus de 30
        // jours s'effacent (voir PurgerActionsTraitees).
        $schedule->command(PurgerActionsTraitees::class)->dailyAt('04:30');
        // À 4 h 45, les magasins de démonstration inactifs depuis trente
        // jours, ou d'une société validée ou close, se ferment (voir
        // FermerDemonstrationsInactives).
        $schedule->command(FermerDemonstrationsInactives::class)->dailyAt('04:45');
        // Chaque heure : les pièces de vérification qui atteindraient leurs
        // trente jours avant le passage suivant sans décision de l'éditeur,
        // et les copies restées d'un envoi abandonné ou d'un examen
        // interrompu, s'effacent (voir EffacerPiecesVerification).
        $schedule->command(EffacerPiecesVerification::class)->hourly();
        // Chaque heure : les demandes d'inscription expirées depuis 48
        // heures s'effacent (voir PurgerDemandesInscription), les
        // inscriptions sans code saisi trente jours après leur dernier
        // courrier se closent et libèrent leur SIREN (voir
        // CloreInscriptionsSansCode), et les contrôles Sirene restés
        // impossibles reprennent (voir ReverifierSirene) ; le premier du
        // mois, à 5 h, les sociétés validées et leurs magasins sont
        // revérifiés, sans rien bloquer.
        $schedule->command(PurgerDemandesInscription::class)->hourly();
        $schedule->command(CloreInscriptionsSansCode::class)->hourly();
        $schedule->command(ReverifierSirene::class)->hourly();
        // Chaque heure aussi : le suivi des plis recommandés à code encore
        // en route, au cas où un rappel du prestataire se perdrait (voir
        // SuivreEnvoisRecommandes).
        $schedule->command(SuivreEnvoisRecommandes::class)->hourly();
        $schedule->command(ReverifierSirene::class, ['--toutes'])->monthlyOn(1, '05:00');
        // Les relances écrivent aux clients : toutes les heures, à la minute
        // 0, pour les seuls magasins qu'il est de 9 h à 11 h 59 à leur heure
        // locale, en matinée plutôt qu'en pleine nuit, et bien après le
        // passage en retard des factures de leur veille (voir
        // RelancerFacturesImpayees).
        $schedule->command(RelancerFacturesImpayees::class)->hourly();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Une page d'un document du magasin restée ouverte pendant qu'une
        // autre requête supprimait ce document : au lieu d'une page
        // introuvable, retour à la liste, qui dit pourquoi (voir
        // ReponseAUnDocumentDisparu). Laravel a déjà changé l'absence du
        // modèle en NotFoundHttpException, qui la garde comme précédente.
        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => ReponseAUnDocumentDisparu::pour($exception, $request));
        // Une page d'écriture du magasin restée ouverte pendant qu'il
        // passait en lecture seule : au lieu d'une page interdite, retour
        // au tableau de bord, qui dit pourquoi (voir
        // ReponseDansUnMagasinEnLectureSeule). Laravel a déjà changé un
        // refus d'autorisation en HttpException.
        $exceptions->render(fn (HttpException $exception, Request $request) => ReponseDansUnMagasinEnLectureSeule::pour($exception, $request));
    })->create();
