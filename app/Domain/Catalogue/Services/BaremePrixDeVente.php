<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Le barème avec lequel un magasin fixe ses prix de vente (Paramètres >
 * Prix de vente) : un bénéfice en euros HT selon le prix d'achat HT de
 * l'article, ajouté à ce prix d'achat avant la TVA, puis, si le magasin le
 * veut, un arrondi commercial au X9. Appliqué au prix de vente des
 * produits d'une facture d'achat (voir AjouteDesLignesDAchat et
 * ConstructeurLigneAchat).
 *
 * Le magasin en décide entièrement : autant de paliers qu'il veut, et un
 * bénéfice au-delà du dernier palier facultatif -- sans lui, le prix de
 * vente d'un article plus cher se saisit à la main.
 */
final readonly class BaremePrixDeVente implements Castable
{
    /**
     * @param  list<array{jusqu_a: float, benefice: float}>  $paliers  un palier s'applique à un prix d'achat HT strictement inférieur à son « jusqu_a »
     */
    public function __construct(
        public array $paliers,
        public ?float $beneficeAuDela,
        public bool $arrondiX9,
    ) {
        $seuils = array_column($paliers, 'jusqu_a');

        if ($paliers === [] && $beneficeAuDela === null) {
            throw new InvalidArgumentException('Le barème a au moins un palier, ou un bénéfice au-delà.');
        }

        if (min([...$seuils, 1]) <= 0 || count(array_unique($seuils)) !== count($seuils)) {
            throw new InvalidArgumentException('Chaque palier du barème a son propre prix d\'achat, supérieur à 0.');
        }

        if (min([...array_column($paliers, 'benefice'), $beneficeAuDela ?? 0]) < 0) {
            throw new InvalidArgumentException('Un bénéfice du barème ne peut pas être négatif.');
        }
    }

    /**
     * Un exemple, proposé au magasin qui active le calcul automatique : il
     * le modifie, le complète ou le vide comme il l'entend.
     */
    public static function parDefaut(): self
    {
        return new self([
            ['jusqu_a' => 2.0, 'benefice' => 10.0],
            ['jusqu_a' => 5.0, 'benefice' => 15.0],
            ['jusqu_a' => 10.0, 'benefice' => 30.0],
            ['jusqu_a' => 15.0, 'benefice' => 35.0],
            ['jusqu_a' => 30.0, 'benefice' => 40.0],
            ['jusqu_a' => 70.0, 'benefice' => 45.0],
            ['jusqu_a' => 99.0, 'benefice' => 50.0],
        ], 60.0, false);
    }

    /**
     * @param  array{paliers: list<array{jusqu_a: float|int|string, benefice: float|int|string}>, benefice_au_dela: float|int|string|null, arrondi_x9: bool}  $bareme
     */
    public static function depuisTableau(array $bareme): self
    {
        $paliers = array_map(
            fn (array $palier): array => ['jusqu_a' => (float) $palier['jusqu_a'], 'benefice' => (float) $palier['benefice']],
            $bareme['paliers'],
        );

        usort($paliers, fn (array $a, array $b): int => $a['jusqu_a'] <=> $b['jusqu_a']);

        return new self($paliers, filled($bareme['benefice_au_dela']) ? (float) $bareme['benefice_au_dela'] : null, (bool) $bareme['arrondi_x9']);
    }

    /**
     * @return array{paliers: list<array{jusqu_a: float, benefice: float}>, benefice_au_dela: float|null, arrondi_x9: bool}
     */
    public function versTableau(): array
    {
        return [
            'paliers' => $this->paliers,
            'benefice_au_dela' => $this->beneficeAuDela,
            'arrondi_x9' => $this->arrondiX9,
        ];
    }

    /**
     * Null au-delà du dernier palier quand le magasin n'y a prévu aucun
     * bénéfice.
     */
    public function beneficePour(float $prixAchatHt): ?float
    {
        foreach ($this->paliers as $palier) {
            if ($prixAchatHt < $palier['jusqu_a']) {
                return $palier['benefice'];
            }
        }

        return $this->beneficeAuDela;
    }

    /**
     * (prix d'achat HT + bénéfice) x (1 + TVA), au centime : le prix cible ;
     * arrondi au X9 si le magasin le veut. Null quand le barème ne prévoit
     * aucun bénéfice pour ce prix d'achat.
     *
     * @return array{prix_vente_ttc: float, prix_cible_ttc: float, benefice: float}|null
     */
    public function prixDeVente(float $prixAchatHt, float $tauxTva): ?array
    {
        $calculateur = app(CalculateurLigne::class);
        $benefice = $this->beneficePour($prixAchatHt);

        if ($benefice === null) {
            return null;
        }

        $prixCibleTtc = $calculateur->prixUnitaireTtcDepuisHt($calculateur->sommer([$prixAchatHt, $benefice]), $tauxTva);

        return [
            'prix_vente_ttc' => $this->arrondiX9 ? $this->arrondirAuX9($prixCibleTtc) : $prixCibleTtc,
            'prix_cible_ttc' => $prixCibleTtc,
            'benefice' => $benefice,
        ];
    }

    /**
     * Le prix finissant par 9 le plus proche : moins de la moitié de la
     * dizaine, on descend, sinon on monte (77,60 € -> 79 € ; 74,00 € ->
     * 69 € ; 134,40 € -> 129 €). Un prix sous 5 € n'a pas de X9 au-dessus
     * de 0 : il reste tel quel.
     */
    private function arrondirAuX9(float $prixTtc): float
    {
        $dizaines = intdiv((int) round($prixTtc * 100) + 500, 1000);

        return $dizaines >= 1 ? $dizaines * 10 - 1 : $prixTtc;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return CastsAttributes<self, self>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?BaremePrixDeVente
            {
                return $value === null ? null : BaremePrixDeVente::depuisTableau(json_decode($value, true, flags: JSON_THROW_ON_ERROR));
            }

            /**
             * @return array<string, string|null>
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                return [$key => $value === null ? null : json_encode($value->versTableau(), JSON_THROW_ON_ERROR)];
            }
        };
    }
}
