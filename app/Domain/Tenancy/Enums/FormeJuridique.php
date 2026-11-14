<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La forme juridique d'une société (S4, S5) : les dix sociétés
 * commerciales que le logiciel connaît, l'entrepreneur individuel
 * (micro-entrepreneur compris) et toute autre forme, dont la société dit
 * si elle est commerciale (Societe::$societe_commerciale). Une variante
 * (SELARL, SA coopérative...) prend la forme de sa famille, qui fixe ses
 * règles, et garde son libellé exact (Societe::$forme_juridique_autre).
 *
 * Toute société commerciale indique son capital sur ses factures (art.
 * R123-238 du Code de commerce, voir exigeUnCapital()) ; un entrepreneur
 * individuel n'a pas de capital, et sa dénomination porte la mention « EI »
 * (art. R526-27, voir Emetteur::denominationImprimee()).
 */
enum FormeJuridique: string implements HasLabel
{
    case Sarl = 'sarl';
    case Eurl = 'eurl';
    case Sas = 'sas';
    case Sasu = 'sasu';
    case Sa = 'sa';
    case Sca = 'sca';
    case Se = 'se';
    case Snc = 'snc';
    case Scs = 'scs';
    case EntrepreneurIndividuel = 'entrepreneur_individuel';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sarl => 'SARL',
            self::Eurl => 'EURL',
            self::Sas => 'SAS',
            self::Sasu => 'SASU',
            self::Sa => 'SA',
            self::Sca => 'SCA',
            self::Se => 'SE',
            self::Snc => 'SNC',
            self::Scs => 'SCS',
            self::EntrepreneurIndividuel => 'Entrepreneur individuel (micro-entrepreneur compris)',
            self::Autre => 'Autre forme',
        };
    }

    /**
     * Une société commerciale ? Toujours pour les dix sociétés, jamais pour
     * l'entrepreneur individuel ; pour une autre forme, ce que la société
     * a répondu (null tant qu'elle ne l'a pas dit).
     */
    public function estUneSocieteCommerciale(?bool $reponsePourUneAutreForme): ?bool
    {
        return match ($this) {
            self::EntrepreneurIndividuel => false,
            self::Autre => $reponsePourUneAutreForme,
            default => true,
        };
    }

    /**
     * Le capital est-il exigé ? Pour toute société commerciale ; pour une
     * autre forme qui n'a pas dit si elle l'est, la réponse manque (null).
     */
    public function exigeUnCapital(?bool $reponsePourUneAutreForme): ?bool
    {
        return $this->estUneSocieteCommerciale($reponsePourUneAutreForme);
    }

    public function estEntrepreneurIndividuel(): bool
    {
        return $this === self::EntrepreneurIndividuel;
    }

    /**
     * La forme que donne une catégorie juridique du répertoire Sirene, par
     * familles de la nomenclature de l'INSEE et non par codes exacts : 1xxx
     * entrepreneur individuel, 52xx SNC, 5306 et 5307 SCS, 5308 et 5309
     * SCA, 5498 EURL, les autres 54xx SARL (SELARL, SARL coopératives...),
     * 55xx et 56xx SA (SELAFA, SA coopératives...), 5720 SASU, les autres
     * 57xx SAS (SELAS...), 5800 SE ; tout autre code, une autre forme.
     */
    public static function depuisCategorieInsee(string $categorie): self
    {
        return match (true) {
            str_starts_with($categorie, '1') => self::EntrepreneurIndividuel,
            str_starts_with($categorie, '52') => self::Snc,
            in_array($categorie, ['5306', '5307'], true) => self::Scs,
            in_array($categorie, ['5308', '5309'], true) => self::Sca,
            $categorie === '5498' => self::Eurl,
            str_starts_with($categorie, '54') => self::Sarl,
            str_starts_with($categorie, '55'), str_starts_with($categorie, '56') => self::Sa,
            $categorie === '5720' => self::Sasu,
            str_starts_with($categorie, '57') => self::Sas,
            $categorie === '5800' => self::Se,
            default => self::Autre,
        };
    }
}
