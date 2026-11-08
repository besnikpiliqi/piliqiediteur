<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les en-têtes de sécurité de chaque réponse de PILIQI (décision de
 * l'éditeur du 07/11/2026, D54) : pages des trois panneaux et pages
 * publiques, fichiers téléchargés, réponses JSON de Livewire, erreurs
 * comprises (middleware global, voir bootstrap/app.php).
 *
 * La politique de contenu (Content-Security-Policy) ne permet que PILIQI
 * lui-même : aucune source tierce (Q-TIERS : la police et l'image des
 * initiales viennent de PILIQI), aucun objet embarqué, aucun formulaire
 * vers un autre site, aucune page qui encadre PILIQI. Ses seules
 * ouvertures sont celles que Filament 3, Livewire 3 et Alpine imposent :
 * des scripts et des styles écrits dans la page, sans jeton à usage unique
 * (« unsafe-inline »), et l'évaluation des expressions d'Alpine
 * (« unsafe-eval ») ; les images en data: (initiales, icônes) et blob:
 * (aperçu d'un fichier choisi). Un en-tête déjà posé par la réponse
 * (Referrer-Policy: no-referrer des pages ouvertes par un lien secret) est
 * gardé.
 */
final class AjouterLesEnTetesDeSecurite
{
    public const POLITIQUE_DE_CONTENU = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self'; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'";

    public const POLITIQUE_DES_FONCTIONS = 'accelerometer=(), autoplay=(), camera=(), display-capture=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=()';

    /**
     * Un an, sous-domaines compris : en production, PILIQI ne se sert
     * qu'en HTTPS.
     */
    public const TRANSPORT_STRICT = 'max-age=31536000; includeSubDomains';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);
        $enTetes = [
            'Content-Security-Policy' => self::POLITIQUE_DE_CONTENU,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => self::POLITIQUE_DES_FONCTIONS,
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if (app()->isProduction()) {
            $enTetes['Strict-Transport-Security'] = self::TRANSPORT_STRICT;
        }

        foreach ($enTetes as $nom => $valeur) {
            if (! $reponse->headers->has($nom)) {
                $reponse->headers->set($nom, $valeur);
            }
        }

        return $reponse;
    }
}
