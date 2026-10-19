<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Les mandataires dont l'application croit les en-têtes X-Forwarded-* :
 * seulement les adresses exactes du répartiteur de charge, lues de la
 * configuration à chaque requête (voir config/piliqi.php), jamais « * ».
 * Sans elles, l'adresse IP d'une requête est celle de la connexion : les
 * compteurs d'essais de connexion par adresse IP (voir
 * LimitationDesConnexions) ne suivent jamais un en-tête qu'un client
 * aurait forgé.
 *
 * Elle remplace le TrustProxies de Laravel dans la pile globale (voir
 * bootstrap/app.php), dont le réglage statique se pose avant que la
 * configuration ne soit chargée.
 */
class FaireConfianceAuxMandataires extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $adresses = array_values(array_filter((array) config('piliqi.proxies_de_confiance', []), static fn (mixed $adresse): bool => is_string($adresse) && $adresse !== '' && $adresse !== '*'));

        return $adresses === [] ? null : $adresses;
    }
}
