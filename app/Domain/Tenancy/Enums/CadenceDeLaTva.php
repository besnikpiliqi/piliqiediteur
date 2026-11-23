<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Calendrier\Calendrier;
use App\Domain\TableauDeBord\Support\Mois;
use InvalidArgumentException;

/**
 * Le rythme des déclarations de TVA d'une société au réel normal (voir
 * RegimeTva::cadence()) : chaque mois ou chaque trimestre civil. Une
 * période s'écrit par son premier mois, « AAAA-MM » : « 2026-11 » pour
 * novembre 2026 au mensuel, « 2026-10 » pour le 4e trimestre 2026 au
 * trimestriel, dont les trois mois s'additionnent avant le report du
 * crédit (voir ReportDuCreditDeTva).
 */
enum CadenceDeLaTva: string
{
    case Mensuelle = 'mensuelle';
    case Trimestrielle = 'trimestrielle';

    private const ORDINAUX_DES_TRIMESTRES = [1 => '1er', 2 => '2e', 3 => '3e', 4 => '4e'];

    /**
     * Le nombre de mois d'une période.
     */
    public function duree(): int
    {
        return $this === self::Mensuelle ? 1 : 3;
    }

    /**
     * La période qui contient ce mois « AAAA-MM ».
     *
     * @throws InvalidArgumentException un mois illisible
     */
    public function periodeDu(string $mois): string
    {
        $numero = Mois::numero($mois);

        return $this === self::Mensuelle ? $mois : sprintf('%04d-%02d', Mois::annee($mois), intdiv($numero - 1, 3) * 3 + 1);
    }

    /**
     * Ce mois est-il le premier d'une période, la façon dont une période
     * s'écrit ?
     */
    public function estUnePeriode(string $periode): bool
    {
        try {
            return $this->periodeDu($periode) === $periode;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * La période qui suit celle-ci, ou la précède avec un décalage négatif.
     *
     * @throws InvalidArgumentException une période illisible
     */
    public function decaler(string $periode, int $decalage): string
    {
        return Mois::decaler($this->exiger($periode), $decalage * $this->duree());
    }

    /**
     * Les mois de cette période, dans l'ordre.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException une période illisible
     */
    public function mois(string $periode): array
    {
        return Mois::du($this->exiger($periode), $this->dernierMois($periode));
    }

    /**
     * Le dernier mois de cette période.
     *
     * @throws InvalidArgumentException une période illisible
     */
    public function dernierMois(string $periode): string
    {
        return Mois::decaler($this->exiger($periode), $this->duree() - 1);
    }

    /**
     * Le premier jour qui suit cette période, « AAAA-MM-JJ » : le jour où
     * sa déclaration peut se déposer, et donc se dire à PILIQI.
     *
     * @throws InvalidArgumentException une période illisible
     */
    public function lendemainDe(string $periode): string
    {
        return $this->decaler($periode, 1).'-01';
    }

    /**
     * « novembre 2026 » au mensuel, « 4e trimestre 2026 » au trimestriel.
     *
     * @throws InvalidArgumentException une période illisible
     */
    public function libelle(string $periode): string
    {
        $periode = $this->exiger($periode);

        return $this === self::Mensuelle
            ? mb_strtolower(Calendrier::nomDuMois(Mois::numero($periode))).' '.Mois::annee($periode)
            : self::ORDINAUX_DES_TRIMESTRES[intdiv(Mois::numero($periode) - 1, 3) + 1].' trimestre '.Mois::annee($periode);
    }

    /**
     * « mois » ou « trimestre », pour les phrases qui parlent d'une
     * période.
     */
    public function nomDeLaPeriode(): string
    {
        return $this === self::Mensuelle ? 'mois' : 'trimestre';
    }

    /**
     * @throws InvalidArgumentException une période illisible, ou un mois qui n'en commence pas une
     */
    private function exiger(string $periode): string
    {
        if ($this->periodeDu($periode) !== $periode) {
            throw new InvalidArgumentException("« {$periode} » ne commence pas une période de la déclaration {$this->value} de la TVA.");
        }

        return $periode;
    }
}
