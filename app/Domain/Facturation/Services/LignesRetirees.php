<?php

namespace App\Domain\Facturation\Services;

use InvalidArgumentException;

/**
 * Ce qu'une vente perd avant sa validation, d'un état de ses lignes au
 * suivant : chaque article (produit ou service) qui n'y est plus, que la
 * ligne ait été retirée, que son produit ou son service ait été remplacé
 * par un autre, ou que sa quantité ait baissé. Le journal des événements
 * inscrit chacun d'eux en ligne retirée (JET 323) : au panier de la caisse
 * (voir PanierDeCaisse) comme dans un brouillon de facture ou de ticket
 * (voir ModifierFactureAction).
 *
 * Les lignes se comparent article par article, pas une à une : déplacer
 * une ligne, la scinder en deux ou changer le prix saisi d'un service ne
 * retire rien. Chaque article d'avant cherche d'abord son pareil au même
 * prix, puis au même article quel que soit le prix ; ce qui ne trouve
 * rien est retiré, au prix qu'il avait. Une remise, elle, ne retire aucun
 * article : elle figure sur la ligne du document signé.
 */
class LignesRetirees
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Une ligne réduite à ce qui se compare : son article, sa description,
     * sa quantité et son PU TTC en centimes (null s'il n'est pas lisible).
     *
     * @return array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}
     */
    public function comparable(int|string|null $produitId, int|string|null $serviceId, ?string $description, int $quantite, mixed $prixUnitaireTtc): array
    {
        $produitId = $produitId === null ? null : (int) $produitId;

        return [
            'produit_id' => $produitId,
            'service_id' => $produitId === null && $serviceId !== null ? (int) $serviceId : null,
            'description' => $description,
            'quantite' => $quantite,
            'prix_unitaire_ttc' => $this->enCentimes($prixUnitaireTtc),
        ];
    }

    /**
     * Les articles des lignes $avant qui ne sont plus dans les lignes
     * $apres : chacun avec la quantité retirée.
     *
     * @param  list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}>  $avant
     * @param  list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}>  $apres
     * @return list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}>
     */
    public function entre(array $avant, array $apres): array
    {
        $aRetirer = array_column($avant, 'quantite');
        $disponibles = array_column($apres, 'quantite');

        foreach ([true, false] as $auMemePrix) {
            foreach ($avant as $i => $ligne) {
                foreach ($apres as $j => $nouvelle) {
                    if ($aRetirer[$i] === 0) {
                        break;
                    }

                    if ($disponibles[$j] === 0
                        || self::article($ligne) !== self::article($nouvelle)
                        || ($auMemePrix && $ligne['prix_unitaire_ttc'] !== $nouvelle['prix_unitaire_ttc'])) {
                        continue;
                    }

                    $retrouves = min($aRetirer[$i], $disponibles[$j]);
                    $aRetirer[$i] -= $retrouves;
                    $disponibles[$j] -= $retrouves;
                }
            }
        }

        $retraits = [];

        foreach ($avant as $i => $ligne) {
            if ($aRetirer[$i] > 0) {
                $retraits[] = [...$ligne, 'quantite' => $aRetirer[$i]];
            }
        }

        return $retraits;
    }

    /**
     * @param  array{produit_id: int|null, service_id: int|null}  $ligne
     */
    private static function article(array $ligne): string
    {
        return $ligne['produit_id'] !== null ? "produit:{$ligne['produit_id']}" : "service:{$ligne['service_id']}";
    }

    private function enCentimes(mixed $prix): ?int
    {
        if (! is_numeric($prix)) {
            return null;
        }

        try {
            return $this->calculateur->enCentiemes(number_format((float) $prix, 2, '.', ''));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
