<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
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
 * veut, un arrondi commercial selon la formule qu'il choisit (voir
 * ArrondiDuPrixDeVente). Appliqué au prix de vente des
 * produits d'une facture d'achat (voir AjouteDesLignesDAchat et
 * ConstructeurLigneAchat).
 *
 * Le magasin en décide entièrement : autant de paliers qu'il veut, et un
 * bénéfice au-delà du dernier palier facultatif -- sans lui, le prix de
 * vente d'un article plus cher se saisit à la main.
 *
 * Ses montants sont des euros au centime, en centimes entiers, saisis au
 * centime et jamais arrondis : une saisie plus précise est refusée (voir
 * SaisieDecimale::montantAuCentime()) ; le prix d'achat auquel il
 * s'applique est un coût au dix-millième d'euro (voir
 * CalculateurLigne::coutUnitaireNet()).
 */
final readonly class BaremePrixDeVente implements Castable
{
    public const REFUS_MONTANT_A_VIRGULE = 'Les montants du barème sont des centimes entiers, jamais un nombre à virgule.';

    /**
     * En centimes ; un palier s'applique à un prix d'achat HT strictement
     * inférieur à son « jusqu_a ».
     *
     * @var list<array{jusqu_a: int, benefice: int}>
     */
    public array $paliers;

    /**
     * Le bénéfice au-delà du dernier palier, en centimes ; null quand le
     * magasin n'en prévoit aucun.
     */
    public ?int $beneficeAuDela;

    /**
     * Chaque montant est un nombre entier de centimes, le bénéfice au-delà
     * du dernier palier compris : un nombre à virgule, que l'on prendrait
     * pour des euros (60.0 pour 60,00 €), ou un texte, est refusé
     * (REFUS_MONTANT_A_VIRGULE) au lieu de valoir 60 centimes, même passé
     * depuis un fichier sans strict_types, où PHP le convertirait en entier
     * sans rien dire.
     *
     * @param  list<array{jusqu_a: mixed, benefice: mixed}>  $paliers  en centimes ; un palier s'applique à un prix d'achat HT strictement inférieur à son « jusqu_a »
     * @param  mixed  $beneficeAuDela  en centimes, ou null sans bénéfice au-delà du dernier palier
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        array $paliers,
        mixed $beneficeAuDela,
        public ArrondiDuPrixDeVente $arrondi,
    ) {
        $this->paliers = array_map(static fn (array $palier): array => ['jusqu_a' => self::centimes($palier['jusqu_a']), 'benefice' => self::centimes($palier['benefice'])], $paliers);
        $this->beneficeAuDela = $beneficeAuDela === null ? null : self::centimes($beneficeAuDela);
        $seuils = array_column($this->paliers, 'jusqu_a');

        if ($this->paliers === [] && $this->beneficeAuDela === null) {
            throw new InvalidArgumentException('Le barème a au moins un palier, ou un bénéfice au-delà.');
        }

        if (($seuils !== [] && min($seuils) <= 0) || count(array_unique($seuils)) !== count($seuils)) {
            throw new InvalidArgumentException('Chaque palier du barème a son propre prix d\'achat, supérieur à 0.');
        }

        $benefices = array_column($this->paliers, 'benefice');

        if ($this->beneficeAuDela !== null) {
            $benefices[] = $this->beneficeAuDela;
        }

        if ($benefices !== [] && min($benefices) < 0) {
            throw new InvalidArgumentException('Un bénéfice du barème ne peut pas être négatif.');
        }
    }

    /**
     * Un montant du barème, en centimes entiers.
     *
     * @throws InvalidArgumentException un nombre à virgule, un texte ou toute autre valeur
     */
    private static function centimes(mixed $montant): int
    {
        return is_int($montant) ? $montant : throw new InvalidArgumentException(self::REFUS_MONTANT_A_VIRGULE);
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
        ], 6000, ArrondiDuPrixDeVente::Aucun);
    }

    /**
     * Le barème d'un tableau : celui qu'écrit versTableau(), relu en base,
     * ou celui que saisit la page des paramètres du prix de vente (voir
     * ParametresPrixDeVente), chaque montant lu au centime et jamais
     * arrondi en silence -- « 0.125 » est refusé
     * (SaisieDecimale::REFUS_MONTANT_TROP_PRECIS), « 2.50 » et « 2.500 »
     * valent 250 (voir SaisieDecimale::montantAuCentime()).
     *
     * @param  array<array-key, mixed>  $bareme
     *
     * @throws UnexpectedValueException un champ absent, un palier qui n'est pas un tableau, ou une valeur qui n'est pas une valeur simple
     * @throws InvalidArgumentException un montant qui n'est pas un nombre, ou plus précis que le centime, ou une formule d'arrondi inconnue
     */
    public static function depuisTableau(array $bareme): self
    {
        $paliers = array_map(
            fn (mixed $palier): array => [
                'jusqu_a' => SaisieDecimale::montantAuCentime(ValeurSimple::simple(ValeursLues::champ(ValeursLues::tableau($palier), 'jusqu_a'))),
                'benefice' => SaisieDecimale::montantAuCentime(ValeurSimple::simple(ValeursLues::champ(ValeursLues::tableau($palier), 'benefice'))),
            ],
            ValeursLues::tableauDe($bareme, 'paliers'),
        );

        usort($paliers, fn (array $a, array $b): int => $a['jusqu_a'] <=> $b['jusqu_a']);
        $beneficeAuDela = ValeurSimple::simple(ValeursLues::champ($bareme, 'benefice_au_dela'));

        return new self($paliers, filled($beneficeAuDela) ? SaisieDecimale::montantAuCentime($beneficeAuDela) : null, ArrondiDuPrixDeVente::tryFrom(ValeursLues::texteDe($bareme, 'arrondi')) ?? throw new InvalidArgumentException(ArrondiDuPrixDeVente::REFUS_FORMULE_INCONNUE));
    }

    /**
     * Le barème tel que la page des paramètres le montre et que sa colonne
     * le garde : chaque montant écrit comme un champ l'écrit, sans zéro
     * inutile (« 2 », « 10 », « 2.5 », voir
     * CalculateurLigne::centiemesDansUnChamp()).
     *
     * @return array{paliers: list<array{jusqu_a: string, benefice: string}>, benefice_au_dela: string|null, arrondi: string}
     */
    public function versTableau(): array
    {
        return [
            'paliers' => array_map(fn (array $palier): array => [
                'jusqu_a' => CalculateurLigne::centiemesDansUnChamp($palier['jusqu_a']),
                'benefice' => CalculateurLigne::centiemesDansUnChamp($palier['benefice']),
            ], $this->paliers),
            'benefice_au_dela' => $this->beneficeAuDela === null ? null : CalculateurLigne::centiemesDansUnChamp($this->beneficeAuDela),
            'arrondi' => $this->arrondi->value,
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
        return $this->palierDe($prixAchatHt)['benefice'] ?? null;
    }

    /**
     * Le « jusqu_a » (en centimes) du palier qui s'applique à ce prix
     * d'achat HT ; null au-delà du dernier palier (que le magasin y ait
     * prévu un bénéfice ou non).
     *
     * @param  int  $prixAchatHt  en dix-millièmes d'euro
     */
    public function palierPour(int $prixAchatHt): ?int
    {
        return $this->palierDe($prixAchatHt)['jusqu_a'] ?? null;
    }

    /**
     * Le palier qui s'applique à ce prix d'achat HT, ou le bénéfice
     * au-delà du dernier palier (« jusqu_a » null) ; null quand le barème
     * n'en prévoit aucun.
     *
     * @param  int  $prixAchatHt  en dix-millièmes d'euro
     * @return array{jusqu_a: int|null, benefice: int}|null
     */
    private function palierDe(int $prixAchatHt): ?array
    {
        foreach ($this->paliers as $palier) {
            if ($prixAchatHt < $palier['jusqu_a'] * 100) {
                return $palier;
            }
        }

        return $this->beneficeAuDela === null ? null : ['jusqu_a' => null, 'benefice' => $this->beneficeAuDela];
    }

    /**
     * (prix d'achat HT + bénéfice) x (1 + TVA), depuis le prix d'achat
     * exact (au dix-millième, remise déduite) et arrondi une seule fois :
     * au centime, le prix cible ; ou, si le magasin le veut, selon sa
     * formule, au multiple de son pas le plus proche du prix cible EXACT,
     * un prix pile au milieu montant (54,996 € -> 49 € en prix en 9, là où
     * 55,00 € arrondi donnerait 59 € ; 10,496 € -> 10 € à l'euro près, là où
     * 10,50 € arrondi donnerait 11 €). Un prix que la formule ne peut pas
     * arrondir (sous 5 € pour un prix en 9, un arrondi qui donnerait 0 €
     * aux autres) reste le prix cible, ce que dit « laisse_tel_quel ».
     * Null quand le barème ne prévoit aucun bénéfice pour ce prix d'achat.
     *
     * @param  int  $prixAchatHt  en dix-millièmes d'euro
     * @param  int  $tauxTva  en centièmes de point
     * @return array{prix_vente_ttc: int, prix_cible_ttc: int, benefice: int, laisse_tel_quel: bool}|null en centimes ; « laisse_tel_quel » : la formule choisie n'arrondit pas ce prix
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

        $prixHt = $prixAchatHt + $benefice * 100;
        $prixCibleTtc = $calculateur->prixTtcArrondi($prixHt, $tauxTva);
        $pas = $this->arrondi->pasEnCentimes();
        $arrondi = $pas === null ? null : $this->arrondi->arrondir($calculateur->prixTtcArrondi($prixHt, $tauxTva, pasCentimes: $pas));

        return [
            'prix_vente_ttc' => $arrondi ?? $prixCibleTtc,
            'prix_cible_ttc' => $prixCibleTtc,
            'benefice' => $benefice,
            'laisse_tel_quel' => $pas !== null && $arrondi === null,
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
