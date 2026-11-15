<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Support\ValeursLues;
use InvalidArgumentException;

/**
 * Les montants des lignes d'une charge, une par taux (décision de
 * l'éditeur du 14/11/2026, lot CHARGES, modèle C2), en centimes entiers :
 * - la TVA de chaque ligne est son HT × son taux, arrondie une seule fois
 *   au centime le plus proche (un demi-centime au centime supérieur) ; son
 *   TTC, le HT plus cette TVA ; pour un fournisseur établi hors de France,
 *   la ligne se saisit HT et la TVA, autoliquidée, ne se paie pas au
 *   fournisseur : son TTC est son HT ;
 * - la personne recopie la TVA et le TTC de la pièce : un écart d'un
 *   centime au plus avec ce calcul s'accepte sans question ; au-delà, une
 *   confirmation est demandée, et la ligne confirmée est marquée « TVA à
 *   vérifier » (voir ConfirmationRequiseException) ;
 * - la TVA déductible de chaque ligne est celle que propose PILIQI selon
 *   le type (voir PropositionsDeTva), ou, pour le comptable seul, celle
 *   qu'il écrit, jamais au-delà de la TVA de la ligne.
 * Les totaux de la charge sont la somme de ses lignes, faite ici.
 *
 * @phpstan-type LigneChiffree array{rang: int, taux: TauxDeCharge, ht: int, tva: int, ttc: int, tva_deductible: int, tva_a_verifier: bool}
 * @phpstan-type ChargeChiffree array{lignes: list<LigneChiffree>, total_ht: int, total_tva: int, total_ttc: int, total_tva_deductible: int, tva_a_verifier: bool}
 */
final class ChiffrageDUneCharge
{
    /** L'écart, en centimes, accepté sans question sur la TVA et le TTC d'une ligne. */
    public const TOLERANCE = 1;

    public const REFUS_DEDUCTIBLE = 'La TVA déductible d\'une ligne ne dépasse jamais sa TVA.';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * La TVA que PILIQI calcule pour ce HT à ce taux.
     */
    public static function tvaCalculee(int $ht, TauxDeCharge $taux): int
    {
        return intdiv($ht * $taux->value * 2 + 10000, 20000);
    }

    /**
     * Le TTC d'une ligne : son HT et sa TVA, ou son seul HT quand le
     * fournisseur est établi hors de France (la TVA, autoliquidée, ne se
     * paie pas au fournisseur).
     */
    public static function ttcCalcule(int $ht, int $tva, bool $horsDeFrance): int
    {
        return $horsDeFrance ? $ht : $ht + $tva;
    }

    /**
     * @param  list<array{taux: TauxDeCharge, ht: int, tva: int|null, ttc: int|null, tva_deductible: int|null}>  $lignes
     * @param  bool  $deductibleSaisie  vrai pour le comptable, qui écrit la TVA déductible ; faux pour le client, à qui PILIQI la propose
     * @return ChargeChiffree
     *
     * @throws ConfirmationRequiseException quand une TVA ou un TTC s'écarte de plus d'un centime, sans confirmation
     * @throws InvalidArgumentException
     */
    public function chiffrer(array $lignes, ?TypeDeCharge $type, bool $vehiculeUtilitaire, bool $horsDeFrance, bool $deductibleSaisie, bool $ecartsConfirmes): array
    {
        $chiffrees = [];
        $ecarts = [];

        foreach ($lignes as $rang => $ligne) {
            $calculee = self::tvaCalculee($ligne['ht'], $ligne['taux']);
            $tva = $ligne['tva'] ?? $calculee;
            $ttcCalcule = self::ttcCalcule($ligne['ht'], $tva, $horsDeFrance);
            $ttc = $ligne['ttc'] ?? $ttcCalcule;
            $aVerifier = abs($tva - $calculee) > self::TOLERANCE || abs($ttc - $ttcCalcule) > self::TOLERANCE;

            if ($aVerifier) {
                $ecarts[] = $this->ecart($ligne['taux'], $tva, $calculee, $ttc, $ttcCalcule);
            }

            $deductible = $deductibleSaisie && $ligne['tva_deductible'] !== null
                ? $ligne['tva_deductible']
                : PropositionsDeTva::tvaDeductible($type, $vehiculeUtilitaire, $tva);

            if ($deductible > $tva) {
                throw new InvalidArgumentException(self::REFUS_DEDUCTIBLE);
            }

            $chiffrees[] = ['rang' => $rang + 1, 'taux' => $ligne['taux'], 'ht' => $ligne['ht'], 'tva' => $tva, 'ttc' => $ttc, 'tva_deductible' => $deductible, 'tva_a_verifier' => $aVerifier];
        }

        if ($ecarts !== [] && ! $ecartsConfirmes) {
            throw new ConfirmationRequiseException(ConfirmationRequiseException::ECARTS, implode(' ', [...$ecarts, ConfirmationRequiseException::CONSIGNE_ECARTS]));
        }

        $total = static function (string $champ) use ($chiffrees): int {
            $somme = 0;

            foreach (ValeursLues::entiers(array_column($chiffrees, $champ)) as $montant) {
                $somme += $montant;
            }

            return $somme;
        };

        return [
            'lignes' => $chiffrees,
            'total_ht' => $total('ht'),
            'total_tva' => $total('tva'),
            'total_ttc' => $total('ttc'),
            'total_tva_deductible' => $total('tva_deductible'),
            'tva_a_verifier' => in_array(true, array_column($chiffrees, 'tva_a_verifier'), true),
        ];
    }

    /**
     * L'écart d'une ligne, dit en clair : « Ligne à 20 % : la TVA saisie,
     * 24,50 €, s'écarte de 0,50 € de celle que calcule PILIQI, 24,00 €. »
     */
    private function ecart(TauxDeCharge $taux, int $tva, int $calculee, int $ttc, int $ttcCalcule): string
    {
        $phrases = [];

        if (abs($tva - $calculee) > self::TOLERANCE) {
            $phrases[] = 'la TVA saisie, '.$this->calculateur->enEuros($tva).', s\'écarte de '.$this->calculateur->enEuros(abs($tva - $calculee)).' de celle que calcule PILIQI, '.$this->calculateur->enEuros($calculee);
        }

        if (abs($ttc - $ttcCalcule) > self::TOLERANCE) {
            $phrases[] = 'le TTC saisi, '.$this->calculateur->enEuros($ttc).', s\'écarte de '.$this->calculateur->enEuros(abs($ttc - $ttcCalcule)).' de '.$this->calculateur->enEuros($ttcCalcule);
        }

        return 'Ligne à '.$taux->getLabel().' : '.implode(' ; ', $phrases).'.';
    }
}
