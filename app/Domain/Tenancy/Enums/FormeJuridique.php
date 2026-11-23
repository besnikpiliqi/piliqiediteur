<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La forme juridique d'une société (S4, S5) : les neuf sociétés
 * commerciales que le logiciel connaît, l'entrepreneur individuel
 * (micro-entrepreneur compris) et toute autre forme, dont la société dit
 * si elle est commerciale (Societe::$societe_commerciale). Une variante
 * (SELARL, SA coopérative...) prend la forme de sa famille, qui fixe ses
 * règles, et garde son libellé exact (Societe::$forme_juridique_autre).
 *
 * Le capital social s'exige selon la famille de la forme (décision P14.3,
 * art. R123-238 du Code de commerce, voir exigeUnCapital()) : de la SARL
 * et de l'EURL, de la SA, de la SAS et de la SASU, de la SCA et de la SE,
 * sauf quand le capital est variable (décision P14.1, art. L238-3) ; ni de
 * la SNC ni de la SCS, qui l'impriment s'il est saisi. Un entrepreneur
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
     * Le capital est-il exigé de la société pour facturer un professionnel
     * (décisions P14.1 et P14.3) ? Oui pour la SARL, l'EURL, la SA, la SAS,
     * la SASU, la SCA et la SE, dont l'art. R123-238 du Code de commerce
     * veut le capital à côté de la dénomination, sauf pour une société à
     * capital variable (art. L238-3 : elle dit « à capital variable » et n'a
     * pas à indiquer son capital) ; jamais pour la SNC et la SCS, dont la
     * forme seule s'imprime, ni pour l'entrepreneur individuel, qui n'en a
     * pas. Une autre forme que la société dit commerciale suit la famille
     * qui l'exige : les variantes que reconnaît la nomenclature de l'INSEE
     * (SELARL, SA coopérative...) ont déjà la forme de leur famille, et
     * celles qu'elle ne reconnaît pas sont de la plus large, celle de la
     * SARL et des sociétés par actions ; une autre forme qui n'a pas dit si
     * elle est commerciale n'a pas de réponse (null).
     */
    public function exigeUnCapital(?bool $reponsePourUneAutreForme, bool $capitalVariable = false): ?bool
    {
        return match ($this) {
            self::Sarl, self::Eurl, self::Sas, self::Sasu, self::Sa, self::Sca, self::Se => ! $capitalVariable,
            self::Snc, self::Scs, self::EntrepreneurIndividuel => false,
            self::Autre => $reponsePourUneAutreForme === null ? null : $reponsePourUneAutreForme && ! $capitalVariable,
        };
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
