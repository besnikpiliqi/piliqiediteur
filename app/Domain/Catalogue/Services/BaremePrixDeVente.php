<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Services;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnexpectedValueException;

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
 *
 * Ses montants sont des euros au centime, en centimes entiers, saisis
 * comme tout montant (voir SaisieDecimale::enCentiemes()) ; le prix
 * d'achat auquel il s'applique est un coût au dix-millième d'euro (voir
 * CalculateurLigne::coutUnitaireNet()).
 */
final readonly class BaremePrixDeVente implements Castable
{
    /**
     * @param  list<array{jusqu_a: int, benefice: int}>  $paliers  en centimes ; un palier s'applique à un prix d'achat HT strictement inférieur à son « jusqu_a »
     * @param  int|null  $beneficeAuDela  en centimes
     */
    public function __construct(
        public array $paliers,
        public ?int $beneficeAuDela,
        public bool $arrondiX9,
    ) {
        $seuils = array_column($paliers, 'jusqu_a');

        foreach ([...$seuils, ...array_column($paliers, 'benefice')] as $montant) {
            if (! is_int($montant)) {
                throw new InvalidArgumentException('Les montants du barème sont des centimes entiers, jamais un nombre à virgule.');
            }
        }

        if ($paliers === [] && $beneficeAuDela === null) {
            throw new InvalidArgumentException('Le barème a au moins un palier, ou un bénéfice au-delà.');
        }

        if (($seuils !== [] && min($seuils) <= 0) || count(array_unique($seuils)) !== count($seuils)) {
            throw new InvalidArgumentException('Chaque palier du barème a son propre prix d\'achat, supérieur à 0.');
        }

        $benefices = array_column($paliers, 'benefice');

        if ($beneficeAuDela !== null) {
            $benefices[] = $beneficeAuDela;
        }

        if (min($benefices) < 0) {
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
            ['jusqu_a' => 200, 'benefice' => 1000],
            ['jusqu_a' => 500, 'benefice' => 1500],
            ['jusqu_a' => 1000, 'benefice' => 3000],
            ['jusqu_a' => 1500, 'benefice' => 3500],
            ['jusqu_a' => 3000, 'benefice' => 4000],
            ['jusqu_a' => 7000, 'benefice' => 4500],
            ['jusqu_a' => 9900, 'benefice' => 5000],
        ], 6000, false);
    }

    /**
     * Le barème d'un tableau : celui qu'écrit versTableau(), relu en base,
     * ou celui que saisit la page des paramètres du prix de vente (voir
     * ParametresPrixDeVente), chaque montant lu au centime comme toute
     * saisie (voir SaisieDecimale::enCentiemes()).
     *
     * @param  array<array-key, mixed>  $bareme
     *
     * @throws UnexpectedValueException un champ absent, un palier qui n'est pas un tableau, ou une valeur qui n'est pas une valeur simple
     * @throws InvalidArgumentException un montant qui n'est pas un nombre
     */
    public static function depuisTableau(array $bareme): self
    {
        $paliers = array_map(
            fn (mixed $palier): array => [
                'jusqu_a' => SaisieDecimale::enCentiemes(ValeurSimple::simple(ValeursLues::champ(ValeursLues::tableau($palier), 'jusqu_a'))),
                'benefice' => SaisieDecimale::enCentiemes(ValeurSimple::simple(ValeursLues::champ(ValeursLues::tableau($palier), 'benefice'))),
            ],
            ValeursLues::tableauDe($bareme, 'paliers'),
        );

        usort($paliers, fn (array $a, array $b): int => $a['jusqu_a'] <=> $b['jusqu_a']);
        $beneficeAuDela = ValeurSimple::simple(ValeursLues::champ($bareme, 'benefice_au_dela'));

        return new self($paliers, filled($beneficeAuDela) ? SaisieDecimale::enCentiemes($beneficeAuDela) : null, (bool) ValeurSimple::simple(ValeursLues::champ($bareme, 'arrondi_x9')));
    }

    /**
     * Le barème tel que la page des paramètres le montre et que sa colonne
     * le garde : chaque montant écrit comme un champ l'écrit, sans zéro
     * inutile (« 2 », « 10 », « 2.5 », voir
     * CalculateurLigne::centiemesDansUnChamp()).
     *
     * @return array{paliers: list<array{jusqu_a: string, benefice: string}>, benefice_au_dela: string|null, arrondi_x9: bool}
     */
    public function versTableau(): array
    {
        return [
            'paliers' => array_map(fn (array $palier): array => [
                'jusqu_a' => CalculateurLigne::centiemesDansUnChamp($palier['jusqu_a']),
                'benefice' => CalculateurLigne::centiemesDansUnChamp($palier['benefice']),
            ], $this->paliers),
            'benefice_au_dela' => $this->beneficeAuDela === null ? null : CalculateurLigne::centiemesDansUnChamp($this->beneficeAuDela),
            'arrondi_x9' => $this->arrondiX9,
        ];
    }

    /**
     * Le bénéfice que le barème ajoute à ce prix d'achat HT, en centimes ;
     * null au-delà du dernier palier quand le magasin n'y a prévu aucun
     * bénéfice.
     *
     * @param  int  $prixAchatHt  en dix-millièmes d'euro
     */
    public function beneficePour(int $prixAchatHt): ?int
    {
        foreach ($this->paliers as $palier) {
            if ($prixAchatHt < $palier['jusqu_a'] * 100) {
                return $palier['benefice'];
            }
        }

        return $this->beneficeAuDela;
    }

    /**
     * (prix d'achat HT + bénéfice) x (1 + TVA), depuis le prix d'achat
     * exact (au dix-millième, remise déduite) et arrondi une seule fois :
     * au centime, le prix cible ; ou, si le magasin le veut, au prix
     * finissant par 9 le plus proche du prix cible EXACT -- moins de la
     * moitié de la dizaine, on descend, sinon on monte (77,60 € -> 79 € ;
     * 74,00 € -> 69 € ; 54,996 € -> 49 €, là où 55,00 € arrondi donnerait
     * 59 €). Sous 5 €, pas de X9 au-dessus de 0 : le prix cible reste tel
     * quel. Null quand le barème ne prévoit aucun bénéfice pour ce prix
     * d'achat.
     *
     * @param  int  $prixAchatHt  en dix-millièmes d'euro
     * @param  int  $tauxTva  en centièmes de point
     * @return array{prix_vente_ttc: int, prix_cible_ttc: int, benefice: int}|null en centimes
     *
     * @throws InvalidArgumentException
     */
    public function prixDeVente(int $prixAchatHt, int $tauxTva): ?array
    {
        $calculateur = app(CalculateurLigne::class);
        $benefice = $this->beneficePour($prixAchatHt);

        if ($benefice === null) {
            return null;
        }

        $prixCibleTtc = $calculateur->prixTtcArrondi($prixAchatHt + $benefice * 100, $tauxTva);
        $dizaineLaPlusProche = $calculateur->prixTtcArrondi($prixAchatHt + $benefice * 100, $tauxTva, pasCentimes: 1000);

        return [
            'prix_vente_ttc' => $this->arrondiX9 && $dizaineLaPlusProche >= 1000 ? $dizaineLaPlusProche - 100 : $prixCibleTtc,
            'prix_cible_ttc' => $prixCibleTtc,
            'benefice' => $benefice,
        ];
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
                return $value === null ? null : BaremePrixDeVente::depuisTableau(ValeursLues::tableau(json_decode(ValeursLues::texte($value), true, flags: JSON_THROW_ON_ERROR)));
            }

            /**
             * @return array<string, string|null>
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                return [$key => $value === null ? null : json_encode(ValeursLues::instance($value, BaremePrixDeVente::class)->versTableau(), JSON_THROW_ON_ERROR)];
            }
        };
    }
}
