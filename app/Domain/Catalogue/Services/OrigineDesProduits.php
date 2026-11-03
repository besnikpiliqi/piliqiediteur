<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Enums\OrigineProduit;
use Closure;
use LogicException;

/**
 * Un produit naît seulement de la validation d'une facture d'achat ou d'un
 * inventaire d'ouverture : le modèle lui-même refuse toute autre création
 * (voir Produit::booted()), quel que soit l'écran, la requête, le job ou
 * la commande qui la tente. Seules ces deux validations ouvrent, le temps
 * de créer leurs produits, le contexte d'origine que l'écouteur exige
 * (voir pendant()) ; la fabrique de test l'ouvre aussi pour les siens, qui
 * tiennent lieu de produits déjà reçus par un achat.
 *
 * Lié en scoped() (voir AppServiceProvider), comme OperateurCourant : une
 * requête ou un job repart toujours sans contexte ouvert, et chaque
 * contexte se referme à la fin de sa création, même après une exception :
 * il ne reste jamais ouvert pour ce qui suit.
 */
final class OrigineDesProduits
{
    public const REFUS = 'Un produit naît seulement de la validation d\'une facture d\'achat ou d\'un inventaire d\'ouverture.';

    private ?OrigineProduit $origine = null;

    /**
     * Exécute $creation avec ce contexte d'origine ouvert, puis remet celui
     * d'avant.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $creation
     * @return TResultat
     */
    public function pendant(OrigineProduit $origine, Closure $creation): mixed
    {
        $avant = $this->origine;
        $this->origine = $origine;

        try {
            return $creation();
        } finally {
            $this->origine = $avant;
        }
    }

    /**
     * L'origine ouverte, null hors de toute validation qui crée des
     * produits.
     */
    public function ouverte(): ?OrigineProduit
    {
        return $this->origine;
    }

    /**
     * Refuse la création d'un produit hors d'un contexte d'origine ouvert.
     *
     * @throws LogicException
     */
    public function exigerUneOrigine(): void
    {
        if ($this->origine === null) {
            throw new LogicException(self::REFUS);
        }
    }
}
