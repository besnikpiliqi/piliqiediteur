<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Une page expirée (HTTP 419) : un formulaire envoyé avec un jeton CSRF
 * que la session ne connaît pas (session expirée, autre compte connecté
 * dans ce navigateur, déconnexion dans un autre onglet), ou une requête
 * Livewire refusée par Livewire lui-même (nouvelle version du logiciel,
 * ou composant qu'il ne retrouve pas par son nom). Le refus ne change pas.
 *
 * Laravel n'inscrit jamais une erreur HTTP au journal : un 419 d'une
 * requête Livewire s'y inscrit donc ici, au niveau warning, avec la classe
 * de l'exception, celle de sa cause, le nombre de composants de la requête
 * et le nom des premiers, sans jeton, session ni donnée personnelle (voir
 * journaliser()).
 * C'est ainsi qu'un composant que Livewire ne retrouvait pas, qui
 * répondait 419 à chaque clic, aurait laissé une trace.
 *
 * Une requête qui attend du JSON reçoit celui de Laravel ; toute autre
 * reçoit la page « Page expirée » en français (errors/419), avec un lien
 * qui recharge la page du formulaire (voir adresseDeRetour()). Dans les
 * panneaux, c'est l'avis en français posé par chaque panneau qui s'affiche
 * à la place (voir la vue filament/requete-refusee).
 */
final class ReponseAUnePageExpiree
{
    public const MESSAGE_DU_JOURNAL = 'Requête Livewire refusée : page expirée (419).';

    /**
     * Le nombre de composants d'une requête Livewire dont le nom s'inscrit
     * au journal (voir journaliser()).
     */
    public const NOMBRE_DE_COMPOSANTS_LUS = 10;

    public static function pour(HttpExceptionInterface $exception, Request $request): ?Response
    {
        if ($exception->getStatusCode() !== 419) {
            return null;
        }

        if ($request->hasHeader('X-Livewire')) {
            self::journaliser($exception, $request);
        }

        if ($request->expectsJson()) {
            return null;
        }

        return response()->view('errors.419', ['retour' => self::adresseDeRetour($request)], 419);
    }

    /**
     * Le 419 d'une requête Livewire, au journal : la classe de l'exception
     * (Livewire lève LivewireReleaseTokenMismatchException ; Laravel change
     * le refus du jeton CSRF en HttpException), celle de sa cause, le
     * nombre de composants de la requête et les noms distincts de ses
     * NOMBRE_DE_COMPOSANTS_LUS premiers composants, lus dans leur
     * instantané, un nom absent ou mal formé devenant « ? ».
     *
     * Le refus du jeton CSRF a lieu avant toute connexion et avant les
     * limites de Livewire : la ligne reste donc bornée, quelle que soit la
     * requête (une page n'envoie que quelques composants à la fois).
     */
    private static function journaliser(HttpExceptionInterface $exception, Request $request): void
    {
        $composants = $request->input('components');
        $composants = is_array($composants) ? array_values($composants) : [];
        $cause = $exception->getPrevious();

        Log::warning(self::MESSAGE_DU_JOURNAL, [
            'classe' => $exception::class,
            'cause' => $cause === null ? null : $cause::class,
            'nombre_de_composants' => count($composants),
            'composants' => array_values(array_unique(array_map(
                self::nomDuComposant(...),
                array_slice($composants, 0, self::NOMBRE_DE_COMPOSANTS_LUS),
            ))),
        ]);
    }

    /**
     * Le nom d'un composant d'une requête Livewire, lu dans son instantané
     * (une chaîne JSON), ou « ? » quand la requête ne le donne pas ou le
     * donne mal formé.
     */
    private static function nomDuComposant(mixed $composant): string
    {
        $instantane = data_get($composant, 'snapshot');
        $nom = is_string($instantane) ? data_get(json_decode($instantane, true), 'memo.name') : null;

        return is_string($nom) && preg_match('/\A[A-Za-z0-9._\-]{1,255}\z/', $nom) === 1 ? $nom : '?';
    }

    /**
     * L'adresse qui recharge la page du formulaire : en remontant le
     * chemin de l'envoi, la première adresse qui affiche une page (celle
     * de l'envoi quand le formulaire s'envoie à l'adresse qui l'affiche,
     * celle de l'invitation pour son refus, envoyé sous elle), sinon
     * l'accueil, qui mène chacun à son espace ou à la connexion.
     */
    private static function adresseDeRetour(Request $request): string
    {
        $segments = explode('/', $request->path());

        for ($nombre = count($segments); $nombre > 0; $nombre--) {
            $adresse = url(implode('/', array_slice($segments, 0, $nombre)));

            if (self::afficheUnePage($adresse)) {
                return $adresse;
            }
        }

        return url('/');
    }

    /**
     * Une route GET mène à cette adresse.
     */
    private static function afficheUnePage(string $adresse): bool
    {
        $page = Request::create($adresse);

        foreach (Route::getRoutes()->get('GET') as $route) {
            if ($route->matches($page)) {
                return true;
            }
        }

        return false;
    }
}
