<?php

namespace App\Http\Middleware;

use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Le contrôle du jeton CSRF de Laravel, sans le témoin XSRF-TOKEN sur le
 * site de démonstration (lot DEMO-COPIE, A.10 et choix 32) : ses seuls
 * témoins sont celui de la copie et celui de la session. Le jeton se lit
 * toujours dans la page (balise meta et champ _token des formulaires).
 */
final class VerifierLeJetonCsrf extends PreventRequestForgery
{
    public function shouldAddXsrfTokenCookie(): bool
    {
        return ! SiteDePiliqi::estLaDemonstration() && parent::shouldAddXsrfTokenCookie();
    }
}
