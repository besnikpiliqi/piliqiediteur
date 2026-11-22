<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * Les compteurs atomiques des limitations d'essais (voir
 * LimitationDesConnexions et LimitationDesLiens), sur le cache de
 * l'application, partagé entre les processus. Chaque compteur s'ajoute s'il
 * manque, puis s'incrémente par l'opération atomique du cache (voir
 * compter()) : des essais simultanés sont tous comptés. Le RateLimiter de
 * Laravel n'est pas utilisé : après un ajout manqué, il remet à 1 un
 * compteur qu'il vient d'incrémenter à 1, ce qui, sous des essais
 * simultanés, efface ceux qu'un autre processus a comptés entre-temps.
 * Chaque clé est un HMAC-SHA256 avec une clé secrète dérivée d'APP_KEY,
 * jamais la valeur en clair ni une empreinte sans clé, qui se retrouverait
 * en quelques secondes parmi 90 millions d'identifiants. La classe qui
 * l'utilise porte un `$caches` (Illuminate\Contracts\Cache\Factory) et dit
 * le début de ses clés (voir prefixeDesCles()). Jamais de blocage définitif :
 * seulement des attentes, qui finissent avec la fenêtre de leur compteur.
 */
trait CompteursAtomiques
{
    /**
     * Le début des clés de la classe, qui la distingue des autres limitations.
     */
    abstract protected function prefixeDesCles(): string;

    /**
     * La clé d'un compteur : son nom, puis le HMAC de chaque valeur.
     */
    public function cle(string $compteur, string ...$valeurs): string
    {
        return $this->prefixeDesCles().$compteur.':'.implode(':', array_map($this->hmac(...), $valeurs));
    }

    protected function cleDAttente(string $compteur, string ...$valeurs): string
    {
        return $this->cle('attente-'.$compteur, ...$valeurs);
    }

    /**
     * Le nombre d'échecs que compte, en ce moment, le compteur de cette clé.
     */
    public function compteDe(string $cle): int
    {
        return ValeurSimple::enEntier($this->cache()->get($cle, 0));
    }

    /**
     * Ajoute un à ce compteur, créé à zéro s'il manque, pour cette fenêtre
     * en secondes, à partir de son premier échec ; rend le nouveau compte.
     * L'ajout ne remplace jamais un compteur qui existe, et l'incrément est
     * atomique : des échecs simultanés sont tous comptés. Un compteur
     * expiré entre l'ajout et l'incrément se recrée, une fois.
     *
     * @throws RuntimeException quand le compteur ne s'incrémente pas
     */
    protected function compter(string $cle, int $fenetre): int
    {
        foreach ([1, 2] as $essai) {
            $this->cache()->add($cle.':fin', now()->addSeconds($fenetre)->getTimestamp(), $fenetre);
            $this->cache()->add($cle, 0, $fenetre);
            $compte = $this->cache()->increment($cle);

            if ($compte !== false) {
                return (int) $compte;
            }
        }

        throw new RuntimeException('Le compteur des essais de connexion ne s\'incrémente pas.');
    }

    /**
     * Les secondes qui restent avant la fin de la fenêtre de ce compteur,
     * s'il compte au moins un échec ; 0 sinon.
     */
    protected function secondesRestantes(string $cle): int
    {
        return $this->compteDe($cle) > 0 ? max(0, ValeurSimple::enEntier($this->cache()->get($cle.':fin', 0)) - now()->getTimestamp()) : 0;
    }

    /**
     * Fait vivre ce compteur encore ce nombre de secondes, à partir de
     * maintenant, sans toucher à son compte.
     */
    protected function prolonger(string $cle, int $secondes): void
    {
        $this->cache()->touch($cle, $secondes);
        $this->cache()->put($cle.':fin', now()->addSeconds($secondes)->getTimestamp(), $secondes);
    }

    protected function effacer(string $cle): void
    {
        $this->cache()->forget($cle);
        $this->cache()->forget($cle.':fin');
    }

    /**
     * Fait attendre cette clé ce nombre de secondes, à partir de maintenant.
     */
    protected function attendre(string $cle, int $secondes): void
    {
        $this->effacer($cle);
        $this->compter($cle, $secondes);
    }

    /**
     * Le cache des compteurs : celui des limitations de l'application
     * (cache.limiter), sinon le cache par défaut, partagé entre les
     * processus.
     */
    protected function cache(): Repository
    {
        return $this->caches->store(Reglage::texteOuNul('cache.limiter'));
    }

    /**
     * Le HMAC-SHA256 d'une valeur, avec une clé dérivée d'APP_KEY propre à
     * les limitations d'essais.
     */
    protected function hmac(#[SensitiveParameter] string $valeur): string
    {
        return hash_hmac('sha256', $valeur, self::cleSecrete());
    }

    private static function cleSecrete(): string
    {
        $cle = Reglage::texteOuNul('app.key');

        if ($cle === null || $cle === '') {
            throw new RuntimeException('APP_KEY manque : les compteurs des essais ne peuvent pas être protégés.');
        }

        $brute = Str::startsWith($cle, 'base64:') ? base64_decode(substr($cle, 7), true) : $cle;

        return hash_hkdf('sha256', (string) $brute, 32, 'limitation-des-connexions');
    }
}
