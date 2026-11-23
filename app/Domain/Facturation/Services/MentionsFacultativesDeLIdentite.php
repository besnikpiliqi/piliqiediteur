<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\ValeursLues;
use UnexpectedValueException;

/**
 * Les mentions facultatives de l'identité de la société, que ses documents
 * de vente impriment quand elles s'appliquent (décision P14.7, art. R123-237
 * du Code de commerce et art. 242 nonies A de l'annexe II au CGI) : la
 * société en liquidation (4°), sa qualité de locataire-gérant ou de
 * gérant-mandataire (6°), l'entrepreneur individuel à responsabilité
 * limitée avec l'objet de son activité (8°), et l'appartenance à un
 * assujetti unique (groupe TVA, I-5° bis), réglage fiscal de la société
 * (voir Societe::$membre_assujetti_unique).
 *
 * Elles font partie de l'émetteur (voir Emetteur) : un ticket, une facture
 * ou un avoir les signe à son émission et les imprime ensuite telles qu'il
 * les a signées, un devis les fige à chaque envoi. Le contenu signé ne
 * porte le bloc « mentions_facultatives » que lorsqu'au moins l'une
 * s'applique : celui d'une société sans aucune garde sa forme.
 *
 * La liquidation s'imprime à côté de la dénomination (voir
 * Emetteur::lignesDeLaSociete()) ; les autres mentions ont chacune leur
 * ligne dans le pied (voir lignes()), l'EIRL jointe à la dénomination
 * (voir Emetteur::denominationImprimee()).
 */
final readonly class MentionsFacultativesDeLIdentite
{
    public const CLE_DU_CONTENU_SIGNE = 'mentions_facultatives';

    public function __construct(
        public bool $enLiquidation,
        public ?ExploitationEnGerance $exploitationEnGerance,
        public bool $eirl,
        public ?string $objetDeLEirl,
        public bool $membreAssujettiUnique,
    ) {}

    /**
     * Aucune mention : ce que porte la société qui n'en déclare aucune.
     */
    public static function aucune(): self
    {
        return new self(false, null, false, null, false);
    }

    /**
     * Celles que la société déclare aujourd'hui. L'objet de l'activité ne
     * s'imprime que pour un EIRL.
     */
    public static function deLaSociete(Societe $societe): self
    {
        return new self(
            enLiquidation: (bool) $societe->en_liquidation,
            exploitationEnGerance: $societe->exploitation_en_gerance,
            eirl: (bool) $societe->eirl,
            objetDeLEirl: $societe->eirl ? $societe->eirl_objet : null,
            membreAssujettiUnique: (bool) $societe->membre_assujetti_unique,
        );
    }

    /**
     * Aucune mention ne s'applique ?
     */
    public function estVide(): bool
    {
        return ! $this->enLiquidation && $this->exploitationEnGerance === null && ! $this->eirl && $this->objetDeLEirl === null && ! $this->membreAssujettiUnique;
    }

    /**
     * Les lignes que le pied imprime pour ces mentions, sous l'identité de
     * la société et dans cet ordre : la qualité d'exploitant, l'objet de
     * l'activité d'un EIRL, l'appartenance à un assujetti unique.
     *
     * @return list<string>
     */
    public function lignes(): array
    {
        return array_values(array_filter([
            $this->exploitationEnGerance?->getLabel(),
            filled($this->objetDeLEirl) ? "Objet de l'activité à laquelle le patrimoine est affecté : {$this->objetDeLEirl}" : null,
            $this->membreAssujettiUnique ? 'Membre d\'un assujetti unique' : null,
        ], filled(...)));
    }

    /**
     * Le bloc du contenu signé : toutes les mentions, ou rien quand aucune
     * ne s'applique (voir Emetteur::enTableau()).
     *
     * @return array<string, mixed>
     */
    public function enTableau(): array
    {
        return [
            'en_liquidation' => $this->enLiquidation,
            'exploitation_en_gerance' => $this->exploitationEnGerance?->value,
            'eirl' => $this->eirl,
            'objet_de_l_eirl' => $this->objetDeLEirl,
            'membre_assujetti_unique' => $this->membreAssujettiUnique,
        ];
    }

    /**
     * Les mentions que le contenu signé d'un émetteur a retenues, aucune
     * quand il n'a pas ce bloc (voir enTableau()) ; chaque champ du bloc
     * est lu pour ce qu'enTableau() y écrit.
     *
     * @param  array<array-key, mixed>  $emetteur  le bloc « emetteur » du contenu signé
     *
     * @throws UnexpectedValueException un champ absent ou d'un autre type
     */
    public static function duContenuSigne(array $emetteur): self
    {
        if (! array_key_exists(self::CLE_DU_CONTENU_SIGNE, $emetteur)) {
            return self::aucune();
        }

        $mentions = ValeursLues::tableauDe($emetteur, self::CLE_DU_CONTENU_SIGNE);
        $gerance = ValeursLues::texteOuNulDe($mentions, 'exploitation_en_gerance');

        return new self(
            enLiquidation: ValeursLues::booleenDe($mentions, 'en_liquidation'),
            exploitationEnGerance: $gerance === null ? null : ExploitationEnGerance::from($gerance),
            eirl: ValeursLues::booleenDe($mentions, 'eirl'),
            objetDeLEirl: ValeursLues::texteOuNulDe($mentions, 'objet_de_l_eirl'),
            membreAssujettiUnique: ValeursLues::booleenDe($mentions, 'membre_assujetti_unique'),
        );
    }
}
