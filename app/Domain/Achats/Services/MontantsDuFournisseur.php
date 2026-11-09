<?php

declare(strict_types=1);

namespace App\Domain\Achats\Services;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Facturation\Services\SaisieDecimale;
use InvalidArgumentException;

/**
 * Ce que la facture d'achat ou la note d'avoir d'un fournisseur écrit au
 * niveau du document, recopié par la personne qui la saisit ou par l'API
 * (décision de l'éditeur du 02/11/2026, T1) : la TVA de chaque taux, quand
 * elle n'est pas celle que PILIQI propose, la base de chaque taux (l'API
 * seulement : la somme des HT de ses lignes, contrôlée), le total TTC saisi
 * pour contrôle, et la confirmation des écarts au-delà de la tolérance
 * (voir TvaDuFournisseur). Le HT écrit de chaque ligne voyage avec sa
 * ligne. Montants en centimes, taux en centièmes de point.
 */
final readonly class MontantsDuFournisseur
{
    public const REFUS_BASE = 'La base à %s écrite sur la facture (%s) n\'est pas la somme des HT de ses lignes (%s) : saisissez le HT de chaque ligne tel que la facture l\'écrit.';

    public const REFUS_TOTAL_TTC = 'Le total TTC saisi (%s) ne vaut pas le total HT plus la TVA de la facture (%s + %s = %s) : vérifiez les montants saisis.';

    /**
     * @param  array<int, int>  $tvaParTaux  la TVA écrite, par taux
     * @param  array<int, int>  $baseParTaux  la base écrite, par taux
     * @param  int|null  $totalTtc  le total TTC écrit, saisi pour contrôle
     * @param  bool  $confirmation  les écarts au-delà de la tolérance sont confirmés
     */
    public function __construct(
        public array $tvaParTaux = [],
        public array $baseParTaux = [],
        public ?int $totalTtc = null,
        public bool $confirmation = false,
    ) {}

    /**
     * Rien d'écrit : les propositions de PILIQI, comme avant cette
     * décision.
     */
    public static function aucun(): self
    {
        return new self;
    }

    /**
     * La saisie d'un écran ou d'un appel de l'API : « taux », une liste de
     * taux avec leur TVA (« montant_tva ») et, pour l'API, leur base
     * (« base_ht ») ; « total_ttc » ; « confirmation ». Une TVA, une base ou
     * un total laissés vides ne disent rien, comme un taux qui n'est pas un
     * tableau. Un montant s'écrit au centime (voir
     * SaisieDecimale::montantAuCentime()).
     *
     * @param  array{taux?: iterable<mixed>|null, total_ttc?: mixed, confirmation?: mixed}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public static function depuisLaSaisie(array $saisie): self
    {
        $tvaParTaux = [];
        $baseParTaux = [];

        foreach ($saisie['taux'] ?? [] as $taux) {
            if (! is_array($taux) || blank($taux['taux_tva'] ?? null)) {
                continue;
            }

            $cle = SaisieDecimale::enCentiemes($taux['taux_tva']);

            if (filled($taux['montant_tva'] ?? null)) {
                $tvaParTaux[$cle] = SaisieDecimale::montantAuCentime($taux['montant_tva']);
            }

            if (filled($taux['base_ht'] ?? null)) {
                $baseParTaux[$cle] = SaisieDecimale::montantAuCentime($taux['base_ht']);
            }
        }

        return new self(
            $tvaParTaux,
            $baseParTaux,
            filled($saisie['total_ttc'] ?? null) ? SaisieDecimale::montantAuCentime($saisie['total_ttc']) : null,
            (bool) ($saisie['confirmation'] ?? false),
        );
    }

    /**
     * La même saisie, la confirmation en plus ou en moins.
     */
    public function avecLaConfirmation(bool $confirmation): self
    {
        return new self($this->tvaParTaux, $this->baseParTaux, $this->totalTtc, $confirmation);
    }

    /**
     * Refuse une base écrite qui n'est pas la somme des HT des lignes de son
     * taux, ou un total TTC saisi qui ne vaut pas le total HT plus la TVA du
     * document : une erreur de saisie, qu'aucune facture valable ne porte.
     *
     * @throws InvalidArgumentException
     */
    public function verifier(DocumentChiffre $document): void
    {
        $calculateur = app(CalculateurLigne::class);

        foreach ($this->baseParTaux as $taux => $base) {
            $calculee = $calculateur->enCentiemes($document->parTaux[$calculateur->enDecimal($taux)]['montant_ht'] ?? 0);

            if ($base !== $calculee) {
                throw new InvalidArgumentException(sprintf(self::REFUS_BASE, CalculateurLigne::tauxEnTexte($taux), $calculateur->enEuros($base), $calculateur->enEuros($calculee)));
            }
        }

        if ($this->totalTtc === null) {
            return;
        }

        $ht = $calculateur->enCentiemes($document->totaux['total_ht']);
        $tva = $calculateur->enCentiemes($document->totaux['total_tva']);

        if ($this->totalTtc !== $ht + $tva) {
            throw new InvalidArgumentException(sprintf(self::REFUS_TOTAL_TTC, $calculateur->enEuros($this->totalTtc), $calculateur->enEuros($ht), $calculateur->enEuros($tva), $calculateur->enEuros($ht + $tva)));
        }
    }

    /**
     * La saisie telle qu'elle distingue une demande d'une autre (voir
     * ActionsUniques).
     *
     * @return array{tva_par_taux: array<int, int>, base_par_taux: array<int, int>, total_ttc: int|null, confirmation: bool}
     */
    public function enTableau(): array
    {
        return [
            'tva_par_taux' => $this->tvaParTaux,
            'base_par_taux' => $this->baseParTaux,
            'total_ttc' => $this->totalTtc,
            'confirmation' => $this->confirmation,
        ];
    }
}
