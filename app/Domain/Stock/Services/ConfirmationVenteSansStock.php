<?php

namespace App\Domain\Stock\Services;

use InvalidArgumentException;

/**
 * Ce qu'une personne autorisée à vendre sans stock a confirmé après
 * l'avertissement (R1, voir VerificateurDeStock) : pour chaque produit
 * qui passerait sous zéro, le stock qu'elle a accepté de voir après la
 * vente. La vente passe tant que le stock réel n'est pas plus bas que ce
 * qu'elle a accepté ; sinon l'avertissement se renouvelle.
 */
final readonly class ConfirmationVenteSansStock
{
    /**
     * @param  array<int, int>  $stocksAcceptes  le stock accepté après la vente, par identifiant de produit
     */
    public function __construct(public array $stocksAcceptes) {}

    /**
     * La confirmation de l'avertissement qui a nommé ces manques.
     *
     * @param  list<ManqueDeStock>  $manques
     */
    public static function de(array $manques): self
    {
        $stocksAcceptes = [];

        foreach ($manques as $manque) {
            $stocksAcceptes[$manque->produitId] = $manque->stockApres();
        }

        return new self($stocksAcceptes);
    }

    /**
     * La confirmation telle qu'un écran la renvoie (voir enTableau()) :
     * des stocks entiers, par identifiant de produit.
     *
     * @param  array<array-key, mixed>  $stocksAcceptes
     *
     * @throws InvalidArgumentException
     */
    public static function depuisTableau(array $stocksAcceptes): self
    {
        $confirmes = [];

        foreach ($stocksAcceptes as $produitId => $stock) {
            $produit = filter_var($produitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $stockAccepte = is_bool($stock) ? false : filter_var($stock, FILTER_VALIDATE_INT);

            if ($produit === false || $stockAccepte === false) {
                throw new InvalidArgumentException('La confirmation de la vente sans stock est illisible : validez de nouveau la vente.');
            }

            $confirmes[$produit] = $stockAccepte;
        }

        return new self($confirmes);
    }

    /**
     * @return array<int, int>
     */
    public function enTableau(): array
    {
        return $this->stocksAcceptes;
    }

    /**
     * Ce manque a-t-il été accepté ? Oui si le stock qu'il laisserait n'est
     * pas plus bas que celui que la personne a accepté pour ce produit.
     */
    public function couvre(ManqueDeStock $manque): bool
    {
        return isset($this->stocksAcceptes[$manque->produitId]) && $manque->stockApres() >= $this->stocksAcceptes[$manque->produitId];
    }
}
