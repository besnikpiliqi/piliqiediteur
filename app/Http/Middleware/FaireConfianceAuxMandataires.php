<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Les mandataires dont l'application croit les en-têtes X-Forwarded-* :
 * seulement les adresses ou plages exactes du répartiteur de charge, lues
 * de la configuration à chaque requête (voir config/piliqi.php), jamais
 * « * », « ** » ni « REMOTE_ADDR », qui feraient croire l'en-tête de tout
 * client. Sans elles, aucun en-tête n'est cru et l'adresse IP d'une requête
 * est celle de la connexion, même sur Laravel Cloud, Forge ou Vapor, où le
 * TrustProxies de Laravel, sans liste, croirait tout mandataire : les
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
     * Les valeurs qui, pour Laravel, désignent tout mandataire.
     */
    private const TOUT_MANDATAIRE = ['*', '**', 'REMOTE_ADDR'];

    /**
     * La liste déclarée, et elle seule : vide, aucun mandataire n'est cru
     * (handle() a déjà effacé toute confiance), sans le repli de Laravel
     * sur config('trustedproxy.proxies') ni sur « * ».
     */
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $adresses = $this->proxies();

        if ($adresses !== []) {
            $request->setTrustedProxies($adresses, $this->getTrustedHeaderNames());
        }
    }

    /**
     * @return list<string>
     */
    protected function proxies(): array
    {
        return array_values(array_filter(
            (array) config('piliqi.proxies_de_confiance', []),
            static fn (mixed $adresse): bool => is_string($adresse) && $adresse !== '' && ! in_array($adresse, self::TOUT_MANDATAIRE, true),
        ));
    }
}
