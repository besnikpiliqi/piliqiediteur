<?php

namespace App\Domain\Demonstration\Visites;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Les sessions d'une copie, dans sa base (lot DEMO-COPIE, C.6), sans
 * adresse IP ni navigateur : le site de démonstration n'en garde aucun
 * (C.17), et leurs colonnes restent vides.
 */
final class SessionsSansTrace extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return $this
     */
    protected function addRequestInformation(&$payload)
    {
        return $this;
    }
}
