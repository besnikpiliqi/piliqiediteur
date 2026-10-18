<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ModePaiement;
use LogicException;

/**
 * Le règlement d'une vente à la caisse, encaissé à son émission (voir
 * EmettreFactureAction), d'un seul geste et en un ou plusieurs modes
 * (points 1 et 23) : les parts payées, chacune dans un mode différent des
 * autres et jamais par prélèvement (voir ModePaiement::aLaCaisse()), et
 * les numéros des bons d'avoir présentés, dans l'ordre où ils ont été
 * saisis. Le crédit d'avoir du client s'impute d'office, avant eux (voir
 * AppliquerAvoirsDisponiblesAction) : il n'y figure pas.
 *
 * Le crédit et les bons ne font entrer aucun argent : ils s'imputent sur
 * la vente, jamais comme un paiement d'un mode. Les parts doivent égaler
 * exactement ce qu'ils laissent à payer (voir ecartAvec()) : sinon, rien
 * n'est enregistré. La monnaie rendue sur la part en espèces ne
 * s'enregistre pas : elle vient d'un montant reçu plus grand que cette
 * part, que seul l'écran de la caisse compte.
 */
final readonly class ReglementComptant
{
    public const RESTE_A_REPARTIR = 'Il reste %s à répartir.';

    public const PARTS_EN_TROP = 'Les parts dépassent le total de %s.';

    /**
     * @var list<string>
     */
    public array $bonsDAvoir;

    /**
     * @param  list<PartDeReglement>  $parts
     * @param  list<string>  $bonsDAvoir  les numéros des bons d'avoir présentés (AV-...)
     *
     * @throws LogicException
     */
    public function __construct(
        public array $parts,
        array $bonsDAvoir = [],
    ) {
        foreach ($parts as $part) {
            if (! in_array($part->mode, ModePaiement::aLaCaisse(), true)) {
                throw new LogicException('Le prélèvement ne règle pas une vente à la caisse.');
            }
        }

        PartDeReglement::refuserUnModeEnDouble($parts);
        $this->bonsDAvoir = PartDeReglement::numerosDesBons($bonsDAvoir);
    }

    /**
     * Le montant de toutes les parts payées, en centimes.
     */
    public function totalDesParts(): int
    {
        return PartDeReglement::total($this->parts);
    }

    /**
     * Ce qui sépare les parts de ce qu'il reste à payer une fois le crédit
     * et les bons imputés : « Il reste 10,00 € à répartir. » ou « Les parts
     * dépassent le total de 10,00 €. » ; null quand elles l'égalent.
     *
     * @param  int  $resteAPayer  en centimes
     */
    public function ecartAvec(int $resteAPayer): ?string
    {
        return self::ecartEntre($this->totalDesParts(), $resteAPayer);
    }

    /**
     * Le même écart, pour des parts que l'écran de la caisse montre avant
     * l'encaissement.
     *
     * @param  int  $totalDesParts  en centimes
     * @param  int  $resteAPayer  en centimes
     */
    public static function ecartEntre(int $totalDesParts, int $resteAPayer): ?string
    {
        $calculateur = app(CalculateurLigne::class);

        return match (true) {
            $totalDesParts < $resteAPayer => sprintf(self::RESTE_A_REPARTIR, $calculateur->enEuros($resteAPayer - $totalDesParts)),
            $totalDesParts > $resteAPayer => sprintf(self::PARTS_EN_TROP, $calculateur->enEuros($totalDesParts - $resteAPayer)),
            default => null,
        };
    }

    /**
     * Le règlement tel qu'il distingue une demande d'une autre (voir
     * ActionsUniques) : ses parts et ses bons, dans leur ordre.
     *
     * @return array{parts: list<array{mode: string, montant: int, reference: string|null}>, bons_d_avoir: list<string>}
     */
    public function enTableau(): array
    {
        return [
            'parts' => array_map(fn (PartDeReglement $part): array => $part->enTableau(), $this->parts),
            'bons_d_avoir' => $this->bonsDAvoir,
        ];
    }
}
