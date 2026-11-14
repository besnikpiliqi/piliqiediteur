<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\ControleSirene;

/**
 * Les avertissements que le code d'activité principale d'une entreprise,
 * lu au répertoire Sirene (voir UniteSirene), fait afficher sur la page du
 * lien d'inscription (voir ConfirmerInscriptionController) et sur celle de
 * l'invitation d'un cabinet (voir pourUneInvitation()), chacun avec les
 * types d'activité qu'il concerne : un type peu probable pour cette
 * activité, une activité exonérée de TVA que le logiciel ne facture pas,
 * ou une limite connue. Ils ne bloquent jamais : seul le cabinet
 * d'expertise comptable est refusé hors du code 69.20Z (voir
 * ControleSirene::exerceLesActivitesComptables()). Le seul endroit des
 * tables de codes et de leurs textes.
 *
 * Seuls les codes de la nomenclature NAF rév. 2 (NAFRev2) s'y lisent : un
 * code absent, inconnu ou d'une autre nomenclature n'avertit de rien, la
 * NAF 2025 n'ayant pas encore de table fixée par l'éditeur. Après
 * l'inscription, le code ne joue plus aucun rôle pour le type.
 */
final class AvertissementsDuCodeDActivite
{
    public const NOMENCLATURE = 'NAFRev2';

    /**
     * Les avertissements de ce code, dans l'ordre de la table.
     *
     * @return list<array{texte: string, types: list<TypeDActivite>}>
     */
    public static function pour(?string $code, ?string $nomenclature): array
    {
        return self::avertissements($code, $nomenclature, 'Votre code d\'activité est 69.20Z (activités comptables) : si vous êtes inscrit à l\'Ordre des experts-comptables, choisissez « '.TypeDActivite::Cabinet->getLabel().' ».');
    }

    /**
     * Les mêmes avertissements, sur la page de l'invitation d'un cabinet
     * d'expertise comptable (voir AccepterLInvitationDuCabinetController),
     * dont le menu n'offre pas le type « Cabinet d'expertise comptable » :
     * pour le code 69.20Z, refuser l'invitation et s'inscrire soi-même.
     *
     * @return list<array{texte: string, types: list<TypeDActivite>}>
     */
    public static function pourUneInvitation(?string $code, ?string $nomenclature): array
    {
        return self::avertissements($code, $nomenclature, 'Votre code d\'activité est 69.20Z (activités comptables). Un cabinet d\'expertise comptable ne s\'inscrit pas par l\'invitation d\'un autre cabinet : si c\'est votre cas, refusez cette invitation et inscrivez votre cabinet vous-même.');
    }

    /**
     * @return list<array{texte: string, types: list<TypeDActivite>}>
     */
    private static function avertissements(?string $code, ?string $nomenclature, string $activitesComptables): array
    {
        if ($code === null || $nomenclature !== self::NOMENCLATURE || preg_match('/^(\d{2})\.(\d)\d[A-Z]$/', $code, $morceaux) !== 1) {
            return [];
        }

        $division = (int) $morceaux[1];
        $groupe = "{$morceaux[1]}.{$morceaux[2]}";
        $application = Reglage::texte('app.name');
        $sansLeCabinet = [TypeDActivite::Commerce, TypeDActivite::Services];
        $entre = static fn (int $de, int $a): bool => $division >= $de && $division <= $a;
        $table = [
            [
                $entre(10, 33) || in_array($division, [45, 46, 47, 56], true) || in_array($groupe, ['95.1', '95.2'], true),
                "Votre code d'activité au répertoire Sirene est {$code} : ces entreprises vendent en général des articles. Avec « ".TypeDActivite::Services->getLabel().' », vous ne pourrez pas en vendre.',
                [TypeDActivite::Services],
            ],
            [
                $entre(10, 32),
                "Votre code d'activité est {$code}, une activité de fabrication : {$application} ne prend pas en charge la vente de ce que vous fabriquez ou préparez vous-même.",
                [TypeDActivite::Commerce],
            ],
            [
                $entre(41, 43),
                "Votre code d'activité est {$code}, une activité du bâtiment. Si la loi exige une assurance professionnelle pour votre activité (la garantie décennale, par exemple), renseignez-la dans l'identité de votre société : {$application} l'imprime sur vos devis et vos factures. {$application} ne prend pas encore en charge la sous-traitance où la TVA est due par le donneur d'ordre (autoliquidation), les factures d'acompte, ni la retenue de garantie.",
                $sansLeCabinet,
            ],
            [
                $code === ControleSirene::CODE_ACTIVITES_COMPTABLES,
                $activitesComptables,
                $sansLeCabinet,
            ],
            [
                $entre(64, 66) || $entre(85, 88),
                "Si votre activité est exonérée de TVA (soins, enseignement, assurance, services financiers…), {$application} ne permet pas de la facturer.",
                $sansLeCabinet,
            ],
            [
                $code === '68.20A',
                "La location de logements est en général exonérée de TVA (art. 261 D du CGI) : {$application} ne permet pas de la facturer.",
                $sansLeCabinet,
            ],
            [
                in_array($code, ['47.91A', '47.91B'], true),
                "Au-delà de 10 000 € par an de ventes à distance à des particuliers d'autres pays de l'Union européenne, la TVA due est celle du pays de l'acheteur (art. 259 D du CGI) : {$application} ne prend pas ces ventes en charge.",
                $sansLeCabinet,
            ],
            [
                in_array($code, ['47.21Z', '47.22Z', '47.23Z'], true),
                "{$application} ne vend pas encore au poids : les quantités sont des nombres entiers.",
                $sansLeCabinet,
            ],
            [
                in_array($code, ['47.26Z', '47.62Z'], true),
                'Les ventes pour le compte de tiers (tabac, presse, jeux) ne sont pas prises en charge.',
                $sansLeCabinet,
            ],
            [
                $division === 55,
                'La taxe de séjour n\'est pas gérée.',
                $sansLeCabinet,
            ],
            [
                $division === 56,
                "{$application} ne prend pas en charge la vente de ce que vous préparez vous-même, ni celle d'un même article acheté à deux taux (sur place et à emporter), ni le titre-restaurant.",
                $sansLeCabinet,
            ],
        ];

        return array_values(array_map(
            static fn (array $ligne): array => ['texte' => $ligne[1], 'types' => $ligne[2]],
            array_filter($table, static fn (array $ligne): bool => $ligne[0]),
        ));
    }
}
