<?php

namespace App\Domain\Stock\Services;

/**
 * Un produit suivi qu'une vente ferait passer sous zéro (R1, voir
 * VerificateurDeStock) : son stock au moment où la vente se vérifie, la
 * quantité qu'elle en vend, toutes ses lignes comprises, et le stock qui
 * resterait après elle, négatif.
 */
final readonly class ManqueDeStock
{
    public function __construct(
        public int $produitId,
        public string $nom,
        public string $reference,
        public int $stockActuel,
        public int $quantiteVendue,
    ) {}

    /**
     * Le stock qui resterait après la vente, négatif.
     */
    public function stockApres(): int
    {
        return $this->stockActuel - $this->quantiteVendue;
    }

    /**
     * « Câble USB, reste 1, demandé 3 » : ce que dit le refus d'une vente
     * au-delà du stock (voir StockInsuffisantException).
     */
    public function refus(): string
    {
        return "{$this->nom}, reste ".self::nombre($this->stockActuel).", demandé {$this->quantiteVendue}";
    }

    /**
     * « Le stock de Câble USB passera à −2 » : ce que dit l'avertissement
     * avant une vente sans stock (voir
     * ConfirmationVenteSansStockRequiseException).
     */
    public function avertissement(): string
    {
        return "Le stock de {$this->nom} passera à ".self::nombre($this->stockApres());
    }

    /**
     * Un nombre d'articles, avec le signe moins typographique quand il est
     * négatif.
     */
    private static function nombre(int $nombre): string
    {
        return $nombre < 0 ? '−'.abs($nombre) : (string) $nombre;
    }
}
