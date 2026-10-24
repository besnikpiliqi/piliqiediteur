<?php

namespace App\Domain\Facturation\Services;

use InvalidArgumentException;

/**
 * Ce qu'une vente perd avant sa validation : chaque article (produit ou
 * service) qui n'y est plus, que la ligne ait été retirée, que son produit
 * ou son service ait été remplacé par un autre, ou que sa quantité ait
 * baissé ; et chaque article resté dont le prix unitaire TTC a baissé
 * (Q8), avec son prix d'avant et d'après. Le journal des événements
 * inscrit chacun d'eux en ligne retirée (JET 323).
 *
 * Les lignes se comparent par article, pas une à une : déplacer une
 * ligne, la scinder en deux, ajouter un article ou en monter le prix ne
 * retire rien. Chaque article d'avant cherche d'abord son pareil au même
 * prix, puis le même article à un prix qui n'a pas baissé (ou pas encore
 * lisible), puis le même article à un prix plus bas : ce dernier est une
 * baisse de prix ; ce qui ne trouve rien est retiré. Une remise, elle, ne
 * retire aucun article et ne baisse pas le prix unitaire : elle figure sur
 * la ligne du document signé, à côté de ce prix.
 *
 * Un brouillon de facture ou de ticket s'enregistre d'un geste, une fois
 * saisi : ses deux états se comparent tels quels (voir entre() et
 * ModifierFactureAction). Le panier de la caisse, lui, s'enregistre à
 * chaque requête de la page, frappe après frappe (voir dansLePanier() et
 * PanierDeCaisse) : un article retiré s'y inscrit aussitôt, mais un prix
 * ne se compare qu'une fois sa saisie terminée, à celui que l'article
 * avait avant elle.
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
            'prix_unitaire_ttc' => $this->prixEnCentimes($prixUnitaireTtc),
        ];
    }

    /**
     * Un PU TTC saisi, en centimes ; null s'il n'est pas lisible (vide, pas
     * un nombre, hors limites) : sa saisie n'est pas terminée.
     */
    public function prixEnCentimes(mixed $prix): ?int
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

    /**
     * Ce que les lignes $apres ont perdu par rapport aux lignes $avant :
     * chaque article retiré, avec la quantité retirée et son prix, puis
     * chaque article dont le prix a baissé, avec la quantité concernée, son
     * prix d'avant (prix_unitaire_ttc) et son prix d'après
     * (prix_unitaire_ttc_apres), en centimes.
     *
     * @param  list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}>  $avant
     * @param  list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}>  $apres
     * @return list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null, prix_unitaire_ttc_apres?: int}>
     */
    public function entre(array $avant, array $apres): array
    {
        ['paires' => $paires, 'restes' => $restes] = self::apparier($avant, $apres);
        $retraits = [];
        $baisses = [];

        foreach ($avant as $i => $ligne) {
            if ($restes[$i] > 0) {
                $retraits[] = [...$ligne, 'quantite' => $restes[$i]];
            }
        }

        foreach ($paires as [$i, $j, $quantite]) {
            $prix = $avant[$i]['prix_unitaire_ttc'];
            $nouveauPrix = $apres[$j]['prix_unitaire_ttc'];

            if ($prix !== null && $nouveauPrix !== null && $nouveauPrix < $prix) {
                $baisses[] = [...$avant[$i], 'quantite' => $quantite, 'prix_unitaire_ttc_apres' => $nouveauPrix];
            }
        }

        return [...$retraits, ...$baisses];
    }

    /**
     * Le panier de la caisse d'un enregistrement au suivant (voir
     * PanierDeCaisse). Chaque article du panier garde son prix arrêté : le
     * prix que sa saisie a laissé la dernière fois qu'elle était terminée,
     * c'est-à-dire inchangé d'un enregistrement au suivant (null tant que
     * l'article n'a jamais eu de prix lisible). Un prix tapé chiffre par
     * chiffre change à chaque enregistrement : il ne s'arrête qu'à
     * l'enregistrement suivant qui le laisse tel quel (le vendeur est passé
     * à un autre champ, à un autre geste), ou quand la vente se fige
     * ($arreter : encaissement, abandon, clôture de sa journée). Un prix
     * en saisie (illisible) ne s'arrête jamais.
     *
     * Rendu : ce que le panier perd, chaque article retiré aussitôt, au
     * prix arrêté qu'il avait (ou, jamais arrêté, au prix qu'il avait),
     * puis chaque baisse d'un prix qui s'arrête plus bas que le prix arrêté
     * d'avant, avec ces deux prix ; et le prix arrêté des articles de
     * chaque ligne d'après, un nouvel article prenant le prix qu'il a. Un
     * prix qui s'arrête plus haut, ou qui revient à son prix arrêté, ne
     * perd rien.
     *
     * @param  list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null, prix_arretes: list<array{prix_unitaire_ttc: int|null, quantite: int}>}>  $avant  les lignes enregistrées, avec le prix arrêté de leurs articles
     * @param  list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null, en_saisie: bool}>  $apres  les lignes vues, chacune avec son prix en saisie ou non
     * @param  bool  $arreter  la vente se fige : chaque prix s'arrête
     * @return array{pertes: list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null, prix_unitaire_ttc_apres?: int}>, prix_arretes: list<list<array{prix_unitaire_ttc: int|null, quantite: int}>>}
     */
    public function dansLePanier(array $avant, array $apres, bool $arreter): array
    {
        $articles = [];

        foreach ($avant as $ligne) {
            foreach ($ligne['prix_arretes'] as $arrete) {
                $articles[] = [
                    'produit_id' => $ligne['produit_id'],
                    'service_id' => $ligne['service_id'],
                    'description' => $ligne['description'],
                    'quantite' => $arrete['quantite'],
                    'prix_unitaire_ttc' => $ligne['prix_unitaire_ttc'],
                    'prix_arrete' => $arrete['prix_unitaire_ttc'],
                ];
            }
        }

        ['paires' => $paires, 'restes' => $restes, 'disponibles' => $disponibles] = self::apparier($articles, $apres);
        $retraits = [];
        $baisses = [];
        $prixArretes = array_fill(0, count($apres), []);

        foreach ($articles as $i => $article) {
            if ($restes[$i] > 0) {
                $retraits[] = [...self::perte($article, $restes[$i]), 'prix_unitaire_ttc' => $article['prix_arrete'] ?? $article['prix_unitaire_ttc']];
            }
        }

        foreach ($paires as [$i, $j, $quantite]) {
            $arrete = $articles[$i]['prix_arrete'];
            $prix = $apres[$j]['prix_unitaire_ttc'];

            if (! $arreter && ($apres[$j]['en_saisie'] || $prix !== $articles[$i]['prix_unitaire_ttc'])) {
                $prixArretes[$j][] = ['prix_unitaire_ttc' => $arrete, 'quantite' => $quantite];

                continue;
            }

            if ($arrete !== null && $prix !== null && $prix < $arrete) {
                $baisses[] = [...self::perte($articles[$i], $quantite), 'prix_unitaire_ttc' => $arrete, 'prix_unitaire_ttc_apres' => $prix];
            }

            $prixArretes[$j][] = ['prix_unitaire_ttc' => $prix ?? $arrete, 'quantite' => $quantite];
        }

        foreach ($apres as $j => $ligne) {
            if ($disponibles[$j] > 0) {
                $prixArretes[$j][] = ['prix_unitaire_ttc' => $ligne['prix_unitaire_ttc'], 'quantite' => $disponibles[$j]];
            }
        }

        return ['pertes' => [...$retraits, ...$baisses], 'prix_arretes' => array_map(self::regrouper(...), $prixArretes)];
    }

    /**
     * Apparie les articles d'avant à ceux d'après, par article, en trois
     * passes : au même prix, puis à un prix qui n'a pas baissé (ou pas
     * lisible), puis à un prix plus bas.
     *
     * @param  list<array{produit_id: int|null, service_id: int|null, quantite: int, prix_unitaire_ttc: int|null}>  $avant
     * @param  list<array{produit_id: int|null, service_id: int|null, quantite: int, prix_unitaire_ttc: int|null}>  $apres
     * @return array{paires: list<array{0: int, 1: int, 2: int}>, restes: array<int, int>, disponibles: array<int, int>} chaque paire (article d'avant, article d'après, quantité), ce qui reste de chaque article d'avant et de chaque article d'après
     */
    private static function apparier(array $avant, array $apres): array
    {
        $restes = array_column($avant, 'quantite');
        $disponibles = array_column($apres, 'quantite');
        $paires = [];
        $passes = [
            static fn (?int $prix, ?int $nouveauPrix): bool => $prix === $nouveauPrix,
            static fn (?int $prix, ?int $nouveauPrix): bool => $prix === null || $nouveauPrix === null || $nouveauPrix >= $prix,
            static fn (?int $prix, ?int $nouveauPrix): bool => $prix !== null && $nouveauPrix !== null && $nouveauPrix < $prix,
        ];

        foreach ($passes as $convient) {
            foreach ($avant as $i => $ligne) {
                foreach ($apres as $j => $nouvelle) {
                    if ($restes[$i] === 0) {
                        break;
                    }

                    if ($disponibles[$j] === 0
                        || self::article($ligne) !== self::article($nouvelle)
                        || ! $convient($ligne['prix_unitaire_ttc'], $nouvelle['prix_unitaire_ttc'])) {
                        continue;
                    }

                    $retrouves = min($restes[$i], $disponibles[$j]);
                    $restes[$i] -= $retrouves;
                    $disponibles[$j] -= $retrouves;
                    $paires[] = [$i, $j, $retrouves];
                }
            }
        }

        return ['paires' => $paires, 'restes' => $restes, 'disponibles' => $disponibles];
    }

    /**
     * @param  array{produit_id: int|null, service_id: int|null}  $ligne
     */
    private static function article(array $ligne): string
    {
        return $ligne['produit_id'] !== null ? "produit:{$ligne['produit_id']}" : "service:{$ligne['service_id']}";
    }

    /**
     * L'article perdu, tel que le journal l'inscrit : son produit ou son
     * service, sa description et la quantité perdue (son prix suit).
     *
     * @param  array{produit_id: int|null, service_id: int|null, description: string|null}  $article
     * @return array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int}
     */
    private static function perte(array $article, int $quantite): array
    {
        return [
            'produit_id' => $article['produit_id'],
            'service_id' => $article['service_id'],
            'description' => $article['description'],
            'quantite' => $quantite,
        ];
    }

    /**
     * Les prix arrêtés d'une ligne, un par prix, du plus haut au plus bas,
     * le prix pas encore lisible en dernier.
     *
     * @param  list<array{prix_unitaire_ttc: int|null, quantite: int}>  $prixArretes
     * @return list<array{prix_unitaire_ttc: int|null, quantite: int}>
     */
    private static function regrouper(array $prixArretes): array
    {
        $parPrix = [];

        foreach ($prixArretes as $arrete) {
            $cle = $arrete['prix_unitaire_ttc'] ?? 'illisible';
            $parPrix[$cle] = ['prix_unitaire_ttc' => $arrete['prix_unitaire_ttc'], 'quantite' => ($parPrix[$cle]['quantite'] ?? 0) + $arrete['quantite']];
        }

        usort($parPrix, static fn (array $a, array $b): int => [$a['prix_unitaire_ttc'] === null, -($a['prix_unitaire_ttc'] ?? 0)] <=> [$b['prix_unitaire_ttc'] === null, -($b['prix_unitaire_ttc'] ?? 0)]);

        return $parPrix;
    }
}
