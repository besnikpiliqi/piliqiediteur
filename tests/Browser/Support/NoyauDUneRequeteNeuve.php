<?php

namespace Tests\Browser\Support;

use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le noyau HTTP des tests en navigateur : le serveur du module browser de
 * Pest traite chaque requête du navigateur dans le processus du test, avec
 * la même application (voir Pest\Browser\Drivers\LaravelHttpServer), alors
 * qu'en production chaque requête part d'un processus neuf. Avant chaque
 * requête, ce noyau rend donc à l'application l'état d'un processus neuf :
 * - les instances scoped() (TenantContext, l'opérateur courant, la trace
 *   du visiteur, le verrou du registre fiscal, mais aussi le panneau et le
 *   magasin courants de Filament...), comme le fait TestCase::call() pour
 *   le magasin actif, et les instances que gardent les façades, qui sinon
 *   désigneraient encore celles de la requête précédente ;
 * - les gardes d'authentification, qui sinon garderaient le compte d'une
 *   requête précédente, même pour une autre session ;
 * - la session, relue de la base à chaque requête au lieu de garder en
 *   mémoire les clés d'une session précédente, celle aussi que lit la garde
 *   d'authentification (session.store, que le conteneur garde sinon depuis
 *   la première requête du test : un second navigateur y trouverait le
 *   compte connecté dans le premier) ;
 * - les témoins mis en file, qui sinon repartiraient avec chaque réponse ;
 * - les rappels du conteneur à la résolution d'un service, tels qu'au
 *   démarrage de l'application : chaque panneau de Filament qui démarre
 *   ajoute les siens (ses crochets de rendu, ses couleurs, ses fichiers),
 *   que chaque requête suivante rejouerait, si bien qu'une page de l'espace
 *   d'une société recevrait les crochets du panneau des magasins.
 * Il fait aussi ce que le serveur du module ne fait pas :
 * - les témoins de la requête se lisent dans son en-tête Cookie comme PHP
 *   les lit : le serveur du module n'en transmet aucun dès qu'un nom
 *   contient un crochet (« postes_reconnus[0] »), quand PHP range ce
 *   témoin en tableau sous « postes_reconnus » ;
 * - le corps d'un formulaire multipart/form-data (un fichier envoyé, comme
 *   Livewire le fait pour un champ de fichier) se lit en champs et en
 *   fichiers, comme PHP le ferait : le serveur du module ne lit que les
 *   formulaires urlencoded et ne transmet aucun fichier ;
 * - une réponse envoyée en flux (un fichier téléchargé : une pièce du
 *   fournisseur, l'archive ou le récapitulatif d'un téléchargement par
 *   période) se lit en entier, octet pour octet : le serveur du module la
 *   rogne de ses espaces et de ses octets nuls de fin, ce qui abîme un
 *   fichier binaire (une archive ZIP peut finir par des octets nuls) ;
 * - chaque témoin que la réponse pose pour une durée (la session, un poste
 *   reconnu, « se souvenir de moi ») dure le temps du navigateur : son
 *   échéance se compte depuis l'instant figé du test, alors que le
 *   navigateur suit l'heure réelle, et un témoin déjà échu à l'heure
 *   réelle serait jeté dès son arrivée ; un témoin effacé (échu à
 *   l'instant du test) reste effacé.
 * Le magasin actif de la préparation du test lui est rendu après la
 * réponse, pour ses vérifications ; les fichiers envoyés s'effacent.
 */
final class NoyauDUneRequeteNeuve implements Kernel
{
    private ?TenantContext $contexteDuTest = null;

    /**
     * Les rappels du conteneur à la résolution d'un service au démarrage
     * de l'application.
     *
     * @var array{0: array<string, list<Closure>>, 1: array<string, list<Closure>>}
     */
    private readonly array $rappelsDuDemarrage;

    /**
     * Les fichiers temporaires des fichiers envoyés de la requête en cours.
     *
     * @var list<string>
     */
    private array $fichiersEnvoyes = [];

    /**
     * @param  Closure(): array<string, mixed>  $temoinsDuTest  les témoins que le test pose lui-même (withCookie), qui l'emportent sur ceux du navigateur, comme sur le serveur du module
     */
    public function __construct(private readonly Kernel $noyau, private readonly Closure $temoinsDuTest)
    {
        $this->rappelsDuDemarrage = (fn (): array => [$this->resolvingCallbacks, $this->afterResolvingCallbacks])->call($noyau->getApplication());
    }

    public function bootstrap(): void
    {
        $this->noyau->bootstrap();
    }

    /**
     * @param  Request  $request
     */
    public function handle($request): Response
    {
        $application = $this->getApplication();
        $this->contexteDuTest = $application->make(TenantContext::class);
        $rappels = $this->rappelsDuDemarrage;
        (function () use ($rappels): void {
            [$this->resolvingCallbacks, $this->afterResolvingCallbacks] = $rappels;
        })->call($application);
        $application->forgetScopedInstances();
        Facade::clearResolvedInstances();
        $application->make('auth')->forgetGuards();
        $application->make('session')->forgetDrivers();
        $application->forgetInstance('session.store');
        $application->make('cookie')->flushQueuedCookies();
        $this->lireLesTemoins($request);
        $this->lireLeFormulaireMultipart($request);

        $reponse = $this->enEntier($this->noyau->handle($request));

        foreach ($reponse->headers->getCookies() as $temoin) {
            if ($temoin->getExpiresTime() > Date::now()->getTimestamp()) {
                $reponse->headers->setCookie($temoin->withExpires(0));
            }
        }

        return $reponse;
    }

    /**
     * La réponse, son contenu lu en entier quand elle l'envoie en flux,
     * avec son statut et ses en-têtes.
     */
    private function enEntier(Response $reponse): Response
    {
        if ($reponse->getContent() !== false) {
            return $reponse;
        }

        ob_start();

        try {
            $reponse->sendContent();
        } finally {
            $contenu = (string) ob_get_clean();
        }

        return new Response($contenu, $reponse->getStatusCode(), $reponse->headers->all());
    }

    /**
     * @param  Request  $request
     * @param  Response  $response
     */
    public function terminate($request, $response): void
    {
        $this->noyau->terminate($request, $response);

        foreach ($this->fichiersEnvoyes as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }

        $this->fichiersEnvoyes = [];

        if ($this->contexteDuTest !== null) {
            $this->getApplication()->instance(TenantContext::class, $this->contexteDuTest);
            $this->contexteDuTest = null;
        }
    }

    public function getApplication(): Application
    {
        return $this->noyau->getApplication();
    }

    /**
     * Les témoins de l'en-tête Cookie, rangés dans la requête comme PHP les
     * range ($_COOKIE) : séparés par « ; », chaque nom sans les espaces qui
     * le précèdent et sans décodage, chaque valeur décodée sans faire d'un
     * « + » une espace (rawurldecode), un nom en tableau rangé par PHP
     * lui-même (parse_str, qui suit ses règles des noms de variables), et,
     * pour un nom ordinaire déjà lu, le premier gardé ; puis les témoins que
     * le test pose lui-même. Une requête sans en-tête Cookie garde ceux
     * qu'elle porte.
     */
    private function lireLesTemoins(Request $requete): void
    {
        $entetes = $requete->headers->all('cookie');
        $paires = [];
        $lus = [];

        if ($entetes === []) {
            return;
        }

        foreach (explode(';', implode(';', $entetes)) as $temoin) {
            [$nom, $valeur] = array_pad(explode('=', ltrim($temoin, " \t\n\r\v\f"), 2), 2, '');
            $nom = rawurlencode($nom);
            parse_str($nom.'=', $seul);
            $base = array_key_first($seul);

            if ($base === null || (isset($lus[$base]) && ! is_array($seul[$base]))) {
                continue;
            }

            $lus[$base] = true;
            $paires[] = $nom.'='.rawurlencode(rawurldecode($valeur));
        }

        parse_str(implode('&', $paires), $temoins);
        $requete->cookies->replace(array_replace($temoins, ($this->temoinsDuTest)()));
    }

    /**
     * Les champs et les fichiers d'un corps multipart/form-data, rangés dans
     * la requête comme PHP les range ($_POST et $_FILES), noms en tableau
     * compris (files[], data[nom]) ; chaque fichier s'écrit dans un fichier
     * temporaire.
     */
    private function lireLeFormulaireMultipart(Request $requete): void
    {
        if (preg_match('/^multipart\/form-data;.*\bboundary="?([^";]+)"?/i', (string) $requete->headers->get('Content-Type'), $frontiere) !== 1) {
            return;
        }

        $champs = [];
        $fichiers = [];

        foreach (array_slice(explode('--'.$frontiere[1], $requete->getContent()), 1) as $partie) {
            if (str_starts_with($partie, '--')) {
                break;
            }

            [$entetes, $contenu] = array_pad(explode("\r\n\r\n", substr($partie, 2), 2), 2, '');
            $contenu = substr($contenu, 0, -2);
            preg_match('/\bname="([^"]*)"/i', $entetes, $nom);

            if (preg_match('/\bfilename="([^"]*)"/i', $entetes, $nomDuFichier) !== 1) {
                $champs[] = rawurlencode($nom[1]).'='.rawurlencode($contenu);

                continue;
            }

            preg_match('/^Content-Type:\s*(\S+)/im', $entetes, $type);
            $chemin = (string) tempnam(sys_get_temp_dir(), 'piliqi-envoi-');
            file_put_contents($chemin, $contenu);
            $this->fichiersEnvoyes[] = $chemin;
            $fichiers[] = [$nom[1], new UploadedFile($chemin, $nomDuFichier[1], $type[1] ?? null, UPLOAD_ERR_OK, true)];
        }

        parse_str(implode('&', $champs), $valeurs);
        parse_str(implode('&', array_map(fn (array $fichier, int $indice): string => rawurlencode($fichier[0]).'='.$indice, $fichiers, array_keys($fichiers))), $places);
        array_walk_recursive($places, function (mixed &$place) use ($fichiers): void {
            $place = $fichiers[(int) $place][1];
        });
        $requete->request->replace($valeurs);
        $requete->files->replace($places);
    }
}
