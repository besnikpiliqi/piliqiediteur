<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use Closure;
use LogicException;

/**
 * Source unique de vérité pour « quelle société et quel magasin sont
 * actifs », quel que soit le point d'entrée : panneau Filament (le magasin
 * ou la société de l'URL, voir BindTenantContextFromFilamentTenant), job
 * de queue (contexte restauré depuis le payload sérialisé, voir
 * WithTenantContext), commande Artisan (positionné explicitement, magasin
 * par magasin ou société par société).
 *
 * Deux périmètres, un invariant : le magasin actif est toujours un magasin
 * de la société active.
 * - set() pose un magasin et sa société ; poserSociete() pose une société
 *   seule (l'espace d'administration de la société, où aucun magasin
 *   n'est actif : tout modèle de magasin y échoue fermé, voir MagasinScope).
 * - Une société posée explicitement (poserSociete(), runAsSociete()) ne
 *   laisse jamais poser le magasin d'une autre société : LogicException,
 *   rien n'est écrit dans un autre périmètre. Sans société posée
 *   explicitement, poser un magasin remplace le magasin et la société
 *   actifs.
 * - runAs() et runAsSociete() restaurent ensuite, séparément, le magasin et
 *   la société posés avant eux.
 *
 * Lié en scoped() et non en singleton (voir TenancyServiceProvider) : une
 * instance par requête ou par job, oubliée entre deux jobs d'un même
 * worker -- jamais le magasin d'une exécution précédente.
 */
class TenantContext
{
    private ?Magasin $magasin = null;

    private ?Societe $societe = null;

    /**
     * La société a-t-elle été posée pour elle-même, et non comme celle du
     * magasin actif ?
     */
    private bool $societeExplicite = false;

    /**
     * @throws LogicException quand une société est posée explicitement et que le magasin n'en est pas
     */
    public function set(Magasin $magasin): void
    {
        if ($this->societeExplicite && (int) $magasin->societe_id !== $this->societe->getKey()) {
            throw new LogicException('Le magasin d\'une autre société ne s\'ouvre pas dans le contexte de cette société.');
        }

        $this->magasin = $magasin;

        if (! $this->societeExplicite) {
            $this->societe = $magasin->societe;
        }
    }

    /**
     * Pose une société seule, sans magasin actif : celle de l'espace
     * d'administration.
     *
     * @throws LogicException quand le magasin actif est celui d'une autre société
     */
    public function poserSociete(Societe $societe): void
    {
        if ($this->magasin !== null && (int) $this->magasin->societe_id !== $societe->getKey()) {
            throw new LogicException('Une société ne se pose pas tant que le magasin d\'une autre société est actif.');
        }

        $this->societe = $societe;
        $this->societeExplicite = true;
    }

    public function clear(): void
    {
        $this->magasin = null;
        $this->societe = null;
        $this->societeExplicite = false;
    }

    public function has(): bool
    {
        return $this->magasin !== null;
    }

    public function magasin(): Magasin
    {
        if ($this->magasin === null) {
            throw TenantContextMissingException::forModel(Magasin::class);
        }

        return $this->magasin;
    }

    public function id(): int
    {
        return $this->magasin()->getKey();
    }

    /**
     * Le calendrier dans lequel les colonnes et les fiches de Filament
     * affichent leurs instants : celui du magasin actif (son panneau, voir
     * Magasin::calendrier()), sinon celui de Paris, référence de l'espace
     * de la société. Seul le réglage de ces colonnes l'appelle (voir
     * AppServiceProvider) : le code du domaine prend toujours le
     * calendrier du magasin pour lequel il calcule.
     */
    public function calendrierDAffichage(): Calendrier
    {
        return $this->magasin?->calendrier() ?? Calendrier::deParis();
    }

    public function aUneSociete(): bool
    {
        return $this->societe !== null;
    }

    public function societe(): Societe
    {
        if ($this->societe === null) {
            throw TenantContextMissingException::forModel(Societe::class);
        }

        return $this->societe;
    }

    /**
     * Exécute un callback avec ce magasin pour magasin actif, puis restaure
     * le magasin et la société posés avant lui. Utilisé par les jobs de
     * queue et les commandes qui agissent tour à tour « en tant que »
     * chaque magasin, et par les écritures d'une société dans le journal de
     * chacun de ses magasins.
     *
     * @throws LogicException quand une société est posée explicitement et que le magasin n'en est pas
     */
    public function runAs(Magasin $magasin, Closure $callback): mixed
    {
        $avant = [$this->magasin, $this->societe, $this->societeExplicite];

        $this->set($magasin);

        try {
            return $callback();
        } finally {
            $this->restaurer(...$avant);
        }
    }

    /**
     * Exécute un callback avec cette société pour seule société active,
     * sans magasin actif, puis restaure le magasin et la société posés
     * avant elle : la création d'une société, ou une écriture dans son
     * journal hors de tout panneau.
     */
    public function runAsSociete(Societe $societe, Closure $callback): mixed
    {
        $avant = [$this->magasin, $this->societe, $this->societeExplicite];

        $this->magasin = null;
        $this->poserSociete($societe);

        try {
            return $callback();
        } finally {
            $this->restaurer(...$avant);
        }
    }

    private function restaurer(?Magasin $magasin, ?Societe $societe, bool $societeExplicite): void
    {
        $this->magasin = $magasin;
        $this->societe = $societe;
        $this->societeExplicite = $societeExplicite;
    }
}
