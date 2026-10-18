<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ModePaiement;
use LogicException;

/**
 * Une part d'un règlement en plusieurs modes, à la caisse comme sur une
 * facture (points 1 et 23) : un mode, un montant en centimes, strictement
 * positif, et une référence facultative (n° de transaction de la carte, de
 * chèque...). Chaque part devient un paiement, signé dans la chaîne des
 * encaissements (voir EnregistrerPaiementAction).
 */
final readonly class PartDeReglement
{
    public const MONTANT_NON_POSITIF = 'Chaque part du règlement doit être strictement positive.';

    public const LONGUEUR_DE_LA_REFERENCE = 100;

    public const BON_EN_DOUBLE = 'Un même bon d\'avoir ne se présente qu\'une fois.';

    public ?string $reference;

    /**
     * @param  int  $montant  en centimes
     *
     * @throws LogicException
     */
    public function __construct(
        public ModePaiement $mode,
        public int $montant,
        ?string $reference = null,
    ) {
        if ($montant <= 0) {
            throw new LogicException(self::MONTANT_NON_POSITIF);
        }

        $reference = $reference === null ? null : trim($reference);

        if ($reference !== null && mb_strlen($reference) > self::LONGUEUR_DE_LA_REFERENCE) {
            throw new LogicException('La référence d\'une part ne dépasse pas '.self::LONGUEUR_DE_LA_REFERENCE.' caractères.');
        }

        $this->reference = $reference === '' ? null : $reference;
    }

    /**
     * La part telle qu'elle distingue une demande d'une autre (voir
     * ActionsUniques).
     *
     * @return array{mode: string, montant: int, reference: string|null}
     */
    public function enTableau(): array
    {
        return ['mode' => $this->mode->value, 'montant' => $this->montant, 'reference' => $this->reference];
    }

    /**
     * Le montant de toutes ces parts, en centimes.
     *
     * @param  list<self>  $parts
     */
    public static function total(array $parts): int
    {
        return array_sum(array_map(fn (self $part): int => $part->montant, $parts));
    }

    /**
     * Ces parts n'ont chacune qu'un mode, différent de celui des autres.
     *
     * @param  list<self>  $parts
     *
     * @throws LogicException
     */
    public static function refuserUnModeEnDouble(array $parts): void
    {
        $modes = array_map(fn (self $part): string => $part->mode->value, $parts);

        if (count($modes) !== count(array_unique($modes))) {
            throw new LogicException('Chaque mode ne règle qu\'une part du règlement.');
        }
    }

    /**
     * Les numéros des bons d'avoir présentés, tels que la recherche d'un
     * bon les lit (voir Avoir::scopeBonDAvoir()), chacun une seule fois.
     *
     * @param  list<string>  $numeros
     * @return list<string>
     *
     * @throws LogicException
     */
    public static function numerosDesBons(array $numeros): array
    {
        $normalises = array_map(fn (string $numero): string => mb_strtoupper(trim($numero)), array_values($numeros));

        if (in_array('', $normalises, true)) {
            throw new LogicException('Saisissez le n° de chaque bon d\'avoir présenté.');
        }

        if (count($normalises) !== count(array_unique($normalises))) {
            throw new LogicException(self::BON_EN_DOUBLE);
        }

        return $normalises;
    }
}
