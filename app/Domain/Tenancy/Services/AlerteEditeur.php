<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\Societe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Prévient l'éditeur de ce qu'il doit examiner : un e-mail à son adresse
 * d'alerte (PILIQI_EDITEUR_EMAIL, voir config/editeur.php), et toujours
 * une ligne au journal de l'application. La société n'y est désignée que
 * par son identifiant et son SIREN.
 */
class AlerteEditeur
{
    /**
     * @param  list<string>  $lignes
     */
    public function alerter(Societe $societe, string $objet, array $lignes): void
    {
        Log::warning("Alerte de l'éditeur : {$objet}", ['societe_id' => $societe->getKey(), 'siren' => $societe->siren]);
        $adresse = config('editeur.email');

        if (! is_string($adresse) || $adresse === '') {
            return;
        }

        Mail::to($adresse)->send(new AlerteEditeurMail($objet, ["Société n° {$societe->getKey()}, SIREN {$societe->siren}.", ...$lignes]));
    }
}
