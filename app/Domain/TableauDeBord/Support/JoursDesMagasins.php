<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Les jours « AAAA-MM-JJ » du $du au $au compris, dans le calendrier de
 * chacun de ces magasins (CLAUDE.md, « Outillage » : une opération
 * appartient au jour, au mois et à l'année de son magasin) : une vente à
 * 23 h 30 en Martinique le 31 janvier est du 31 janvier, même s'il est
 * déjà le 1er février à Paris. Une lecture en base prend les instants qui
 * couvrent ces jours dans tous ces calendriers (voir bornes()), puis chaque
 * document se range dans son jour, celui de son magasin (voir jourDe()).
 */
final class JoursDesMagasins
{
    /** @var array<int, Calendrier> */
    private array $calendriers = [];

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    public function __construct(Collection $magasins, public readonly string $du, public readonly string $au)
    {
        foreach ($magasins as $magasin) {
            $this->calendriers[CleDuModele::de($magasin)] = $magasin->calendrier();
        }
    }

    /**
     * Le premier minuit et le dernier minuit (exclu) de ces jours parmi
     * tous les calendriers des magasins.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bornes(): array
    {
        $debuts = [];
        $fins = [];

        foreach ($this->calendriers as $calendrier) {
            $debuts[] = $calendrier->debutDuJour($this->du);
            $fins[] = $calendrier->bornesDuJour($this->au)[1];
        }

        if ($debuts === []) {
            throw new LogicException('Les bornes des jours se lisent dans le calendrier d\'un magasin au moins.');
        }

        return [min($debuts), max($fins)];
    }

    /**
     * Le jour de cet instant (tel que la base l'écrit, ou déjà lu) dans le
     * calendrier de ce magasin, ou null quand il tombe hors de ces jours.
     */
    public function jourDe(int $magasin, string|DateTimeInterface $instant): ?string
    {
        $jour = $this->calendriers[$magasin]->jourDe(is_string($instant) ? Instant::depuisLaBase($instant) : $instant);

        return $this->contient($jour) ? $jour : null;
    }

    /**
     * Ce jour « AAAA-MM-JJ » d'un document daté sans heure (une facture
     * d'achat, un paiement) est-il de la fenêtre ?
     */
    public function contient(string $jour): bool
    {
        return $jour >= $this->du && $jour <= $this->au;
    }

    public function calendrierDe(int $magasin): Calendrier
    {
        return $this->calendriers[$magasin];
    }
}
