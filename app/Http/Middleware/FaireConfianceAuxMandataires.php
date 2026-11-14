<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\EntetesDeConfiance;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Les mandataires dont l'application croit les en-têtes X-Forwarded-* :
 * seulement les adresses ou plages exactes du répartiteur de charge, lues
 * de la configuration à chaque requête (voir config/piliqi.php). Une liste
 * blanche (constat C39 des audits du 07/11/2026) : n'est gardée qu'une
 * adresse IP valide, ou une plage CIDR dont l'adresse est valide et le
 * préfixe un entier de 1 à 32 (IPv4) ou de 1 à 128 (IPv6) ; tout le reste
 * est écarté : « * », « ** », « REMOTE_ADDR », les mots-clés de Symfony
 * (« PRIVATE_SUBNETS », « private_ranges »), une plage /0, et une plage
 * IPv6 qui recouvre un préfixe portant des adresses IPv4 (adresses IPv4
 * portées en IPv6, 6to4, Teredo, NAT64), par laquelle une connexion IPv4
 * quelconque passerait pour le mandataire. Sans adresse gardée, aucun
 * en-tête n'est cru et l'adresse IP d'une requête est celle de la
 * connexion, même sur Laravel Cloud, Forge ou Vapor, où le TrustProxies de
 * Laravel, sans liste, croirait tout mandataire : les compteurs d'essais
 * de connexion par adresse IP (voir LimitationDesConnexions), les limites
 * d'inscription, de courriers recommandés et de saisie du code et les
 * traces techniques ne suivent jamais un en-tête qu'un client aurait
 * forgé.
 *
 * Elle remplace le TrustProxies de Laravel dans la pile globale (voir
 * bootstrap/app.php), dont le réglage statique se pose avant que la
 * configuration ne soit chargée.
 */
class FaireConfianceAuxMandataires extends TrustProxies
{
    /**
     * Les plages IPv6 qui portent des adresses IPv4 : une plage déclarée
     * qui en recouvre une est écartée.
     */
    private const PLAGES_IPV6_QUI_PORTENT_DES_ADRESSES_IPV4 = [
        ['::ffff:0:0', 96],
        ['::', 96],
        ['2002::', 16],
        ['2001::', 32],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
    ];

    /**
     * La liste déclarée, et elle seule : vide, aucun mandataire n'est cru
     * (handle() a déjà effacé toute confiance), sans le repli de Laravel
     * sur config('trustedproxy.proxies') ni sur « * ».
     */
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $adresses = $this->proxies();

        if ($adresses !== []) {
            $request->setTrustedProxies($adresses, EntetesDeConfiance::masque($this->getTrustedHeaderNames()));
        }
    }

    /**
     * @return list<string>
     */
    protected function proxies(): array
    {
        return ValeursLues::textes(array_filter((array) config('piliqi.proxies_de_confiance', []), self::estUneAdresseOuUnePlageEtroite(...)));
    }

    private static function estUneAdresseOuUnePlageEtroite(mixed $declaree): bool
    {
        if (! is_string($declaree)) {
            return false;
        }

        $morceaux = explode('/', $declaree, 2);
        $adresse = $morceaux[0];
        $prefixe = $morceaux[1] ?? null;

        if (filter_var($adresse, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ipv6 = filter_var($adresse, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ($prefixe === null) {
            return true;
        }

        if (preg_match('/^[1-9][0-9]{0,2}$/', $prefixe) !== 1 || (int) $prefixe > ($ipv6 ? 128 : 32)) {
            return false;
        }

        foreach ($ipv6 ? self::PLAGES_IPV6_QUI_PORTENT_DES_ADRESSES_IPV4 : [] as [$plage, $longueur]) {
            if (self::seRecouvrent($adresse, (int) $prefixe, $plage, $longueur)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deux plages se recouvrent quand leurs premiers bits, sur la longueur
     * du plus court de leurs deux préfixes, sont les mêmes.
     */
    private static function seRecouvrent(string $adresse, int $longueur, string $autreAdresse, int $autreLongueur): bool
    {
        $octets = (string) inet_pton($adresse);
        $autresOctets = (string) inet_pton($autreAdresse);
        $bits = min($longueur, $autreLongueur);
        $pleins = intdiv($bits, 8);

        if (substr($octets, 0, $pleins) !== substr($autresOctets, 0, $pleins)) {
            return false;
        }

        $reste = $bits % 8;
        $masque = (0xFF << (8 - $reste)) & 0xFF;

        return $reste === 0 || (ord($octets[$pleins]) & $masque) === (ord($autresOctets[$pleins]) & $masque);
    }
}
