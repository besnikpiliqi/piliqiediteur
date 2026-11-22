<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Services\LimitationDesLiens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse, avant toute lecture du jeton, les pages d'un lien (voir
 * LimitationDesLiens) depuis une adresse IP qui a essayé trop de jetons
 * inconnus ou expirés dans l'heure : la réponse dit seulement d'attendre,
 * sans rien dire d'aucun lien. Chaque contrôleur compte lui-même ses jetons
 * inconnus ou expirés (voir LimitationDesLiens::jetonInvalide()). Jamais un
 * blocage pour de bon : l'attente finit avec l'heure.
 */
class LimiterLesEssaisDeLien
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $attente = app(LimitationDesLiens::class)->attenteDeLAdresse((string) $request->ip());

        if ($attente === 0) {
            return $next($request);
        }

        return response()->view('liens.trop-de-tentatives', ['message' => LimitationDesLiens::message($attente)], 429)
            ->header('Retry-After', (string) $attente)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
