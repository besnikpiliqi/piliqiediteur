<?php

namespace App\Domain\Charges\Support;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\FormatDeLEtat;
use App\Domain\Charges\Models\Charge;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Fichiers\NomDeFichier;

/**
 * Ce que montre l'état téléchargeable des charges (voir EtatDesCharges),
 * lu une seule fois et écrit tel quel en PDF comme en Excel : la société,
 * les filtres, qui l'édite et quand ; le tableau « type de charge × mois »
 * de la page, chaque case avec l'état le moins avancé de ses charges, leur
 * nombre et leur TTC (null quand aucune n'a ses chiffres), le total TTC de
 * chaque type, de chaque mois et de la période ; la liste des charges de
 * la vue ; la TVA de chaque mois telle que la page la compte, null en
 * franchise en base. Montants en centimes, additionnés dans le code.
 */
final readonly class ContenuDeLEtat
{
    /**
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  list<array{cle: string, libelle: string, cases: array<string, array{etat: EtatDeLaCharge, nombre: int, ttc: int|null}>, total: int, dernier_etat: EtatDeLaCharge|null}>  $lignes
     * @param  array<string, int>  $totauxDuMois
     * @param  list<Charge>  $charges  la liste de la vue, triée par mois de TVA puis par type
     * @param  array{montants: array<string, MontantsDeTva>, avec_les_ventes: bool, sans_celles_de_la_societe: bool, magasins_non_comptes: string|null}|null  $tva
     */
    public function __construct(
        public string $denomination,
        public string $siren,
        public FiltresDeLEtat $filtres,
        public string $edition,
        public array $mois,
        public array $lignes,
        public array $totauxDuMois,
        public int $total,
        public array $charges,
        public ?array $tva,
    ) {}

    /**
     * Le titre de l'état : « État des charges — année 2026 » ; sur le site
     * de démonstration, précédé de « Démonstration — sans valeur · » (lot
     * DEMO-COPIE, C.10), comme le nom du fichier.
     */
    public function titre(): string
    {
        return self::marqueDeLaDemonstration(' · ').'État des charges — '.TextesDesCharges::enMinuscule($this->filtres->periode);
    }

    /**
     * « Démonstration — sans valeur » suivi de ce séparateur, sur le site de
     * démonstration seulement ; rien au réel.
     */
    public static function marqueDeLaDemonstration(string $separateur): string
    {
        return SiteDePiliqi::estLaDemonstration() ? 'Démonstration — sans valeur'.$separateur : '';
    }

    /**
     * Le nom du fichier : « Charges 2026 — Dénomination.pdf » pour une
     * année civile, « Charges exercice de juillet 2025 à juin 2026 —
     * Dénomination.xlsx » pour un exercice, sans caractère interdit dans un
     * nom de fichier (voir NomDeFichier::dUnFichierProduit()).
     */
    public function nomDuFichier(FormatDeLEtat $format): string
    {
        $premier = $this->mois[0] ?? '';
        $dernier = $this->mois[count($this->mois) - 1] ?? '';
        $civile = str_ends_with($premier, '-01') && $dernier === substr($premier, 0, 4).'-12';
        $periode = $civile ? substr($premier, 0, 4) : TextesDesCharges::enMinuscule($this->filtres->periode);

        return NomDeFichier::dUnFichierProduit(self::marqueDeLaDemonstration(' — ')."Charges {$periode} — {$this->denomination}", $format->value);
    }

    /**
     * Les filtres en une ligne : « Toute la société et tous les magasins ·
     * Toutes les charges ».
     */
    public function lesFiltres(): string
    {
        return $this->filtres->libelleDuMagasin.' · '.$this->filtres->libelleDeLaVue();
    }
}
