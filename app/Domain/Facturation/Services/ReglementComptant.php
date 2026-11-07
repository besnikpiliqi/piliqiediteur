<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use LogicException;

/**
 * Le règlement d'une vente, encaissé à son émission (voir
 * EmettreFactureAction), d'un seul geste et en un ou plusieurs modes
 * (points 1 et 23), chaque part dans un mode différent des autres :
 *
 * - à la caisse, les parts payées, jamais par prélèvement (voir
 *   ModePaiement::aLaCaisse()), et les numéros des bons d'avoir présentés,
 *   dans l'ordre où ils ont été saisis ; les parts doivent égaler
 *   exactement ce que le crédit du client et les bons laissent à payer ;
 * - d'une facture ou d'un ticket fait hors de la caisse, par « Émettre et
 *   encaisser » (R1, voir horsDeLaCaisse()), les parts payées, dans
 *   chacun des cinq modes, sans bon d'avoir (Q6) ; leur somme ne dépasse
 *   jamais ce que le crédit du client laisse dû, et un paiement partiel
 *   est permis : le reste reste dû, avec l'échéance de la facture.
 *
 * Le crédit d'avoir du client s'impute d'office, avant les parts (voir
 * AppliquerAvoirsDisponiblesAction) : il n'y figure pas. Le crédit et les
 * bons ne font entrer aucun argent : ils s'imputent sur la vente, jamais
 * comme un paiement d'un mode. Un règlement qui ne tombe pas juste n'est
 * pas enregistré (voir EmettreFactureAction). La monnaie rendue sur la part
 * en espèces ne s'enregistre pas : elle vient d'un montant reçu plus grand
 * que cette part, que seul l'écran de la caisse compte.
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
     * @param  list<string>  $bonsDAvoir  les numéros des bons d'avoir présentés (AV-...), à la caisse seulement
     * @param  bool  $aLaCaisse  une vente à la caisse, sinon « Émettre et encaisser » (voir horsDeLaCaisse())
     *
     * @throws LogicException
     */
    public function __construct(
        public array $parts,
        array $bonsDAvoir = [],
        public bool $aLaCaisse = true,
    ) {
        foreach ($parts as $part) {
            if ($aLaCaisse && ! in_array($part->mode, ModePaiement::aLaCaisse(), true)) {
                throw new LogicException('Le prélèvement ne règle pas une vente à la caisse.');
            }
        }

        if (! $aLaCaisse && $bonsDAvoir !== []) {
            throw new LogicException(Avoir::BON_D_AVOIR_HORS_DE_LA_CAISSE);
        }

        PartDeReglement::refuserUnModeEnDouble($parts);
        $this->bonsDAvoir = PartDeReglement::numerosDesBons($bonsDAvoir);
    }

    /**
     * Le règlement que « Émettre et encaisser » reçoit pour une facture ou
     * un ticket fait hors de la caisse (R1) : les parts payées, dans
     * chacun des cinq modes, sans bon d'avoir ; au plus ce qui reste dû.
     *
     * @param  list<PartDeReglement>  $parts
     *
     * @throws LogicException
     */
    public static function horsDeLaCaisse(array $parts): self
    {
        return new self($parts, [], false);
    }

    /**
     * Le montant de toutes les parts payées, en centimes.
     */
    public function totalDesParts(): int
    {
        return PartDeReglement::total($this->parts);
    }

    /**
     * Ce qui sépare les parts que l'écran de la caisse montre de ce qu'il
     * reste à payer une fois le crédit et les bons imputés : « Il reste
     * 10,00 € à répartir. » ou « Les parts dépassent le total de
     * 10,00 €. » ; null quand elles l'égalent.
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
     * ActionsUniques) : ses parts et ses bons, dans leur ordre, et s'il
     * règle une vente à la caisse.
     *
     * @return array{parts: list<array{mode: string, montant: int, reference: string|null}>, bons_d_avoir: list<string>, a_la_caisse: bool}
     */
    public function enTableau(): array
    {
        return [
            'parts' => array_map(fn (PartDeReglement $part): array => $part->enTableau(), $this->parts),
            'bons_d_avoir' => $this->bonsDAvoir,
            'a_la_caisse' => $this->aLaCaisse,
        ];
    }
}
