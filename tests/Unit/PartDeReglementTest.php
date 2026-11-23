<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\PartDeReglement;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une part d'un règlement en plusieurs modes (points 1 et 23) : un montant
 * strictement positif, une référence facultative de cent caractères au
 * plus, un seul mode par part, et chaque bon d'avoir présenté une fois.
 */
class PartDeReglementTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function montantsQuiNeReglentRien(): array
    {
        return [
            'zéro' => [0],
            'négatif' => [-1],
        ];
    }

    #[DataProvider('montantsQuiNeReglentRien')]
    public function test_une_part_sans_montant_positif_est_refusee(int $montant): void
    {
        $this->expectExceptionObject(new LogicException(PartDeReglement::MONTANT_NON_POSITIF));

        new PartDeReglement(ModePaiement::Especes, $montant);
    }

    public function test_un_centime_suffit_a_faire_une_part(): void
    {
        $part = new PartDeReglement(ModePaiement::CarteBancaire, 1);

        $this->assertSame(['mode' => 'carte_bancaire', 'montant' => 1, 'reference' => null], $part->enTableau());
    }

    /**
     * La référence se garde sans les espaces qui l'entourent ; vide, il n'y
     * en a pas.
     */
    public function test_la_reference_se_garde_sans_les_espaces_qui_l_entourent(): void
    {
        $this->assertSame('CHQ 123', (new PartDeReglement(ModePaiement::Cheque, 500, '  CHQ 123 '))->reference);
        $this->assertNull((new PartDeReglement(ModePaiement::Cheque, 500, '   '))->reference);
        $this->assertNull((new PartDeReglement(ModePaiement::Cheque, 500))->reference);
    }

    /**
     * Cent caractères, accents compris (chacun compte pour un, quel que soit
     * le nombre d'octets qui l'écrivent), et les espaces qui l'entourent ne
     * comptent pas ; le cent unième est refusé.
     */
    public function test_la_reference_compte_cent_caracteres_au_plus(): void
    {
        $centAccents = str_repeat('é', PartDeReglement::LONGUEUR_DE_LA_REFERENCE);

        $this->assertSame($centAccents, (new PartDeReglement(ModePaiement::Virement, 500, " {$centAccents} "))->reference);

        $this->expectExceptionObject(new LogicException('La référence d\'une part ne dépasse pas 100 caractères.'));

        new PartDeReglement(ModePaiement::Virement, 500, $centAccents.'x');
    }

    public function test_le_total_additionne_chaque_part(): void
    {
        $this->assertSame(0, PartDeReglement::total([]));
        $this->assertSame(1234, PartDeReglement::total([
            new PartDeReglement(ModePaiement::Especes, 1000),
            new PartDeReglement(ModePaiement::CarteBancaire, 200),
            new PartDeReglement(ModePaiement::Cheque, 34),
        ]));
    }

    public function test_chaque_mode_ne_regle_qu_une_part(): void
    {
        PartDeReglement::refuserUnModeEnDouble([
            new PartDeReglement(ModePaiement::Especes, 1000),
            new PartDeReglement(ModePaiement::CarteBancaire, 200),
        ]);

        $this->expectExceptionObject(new LogicException('Chaque mode ne règle qu\'une part du règlement.'));

        PartDeReglement::refuserUnModeEnDouble([
            new PartDeReglement(ModePaiement::Especes, 1000),
            new PartDeReglement(ModePaiement::CarteBancaire, 200),
            new PartDeReglement(ModePaiement::Especes, 300),
        ]);
    }

    /**
     * Chaque numéro de bon se lit comme la recherche d'un bon le lit : sans
     * les espaces qui l'entourent et en majuscules, accents compris ; la
     * liste rendue garde leur ordre, numérotée à partir de zéro.
     */
    public function test_les_numeros_des_bons_se_lisent_comme_la_recherche_d_un_bon(): void
    {
        $this->assertSame(
            ['AV-LYON1-2026-000001', 'AV-ÉTÉ-2026-000002'],
            PartDeReglement::numerosDesBons(['premier' => ' av-lyon1-2026-000001 ', 'second' => 'av-été-2026-000002']),
        );
        $this->assertSame([], PartDeReglement::numerosDesBons([]));
    }

    public function test_un_numero_de_bon_vide_est_refuse(): void
    {
        $this->expectExceptionObject(new LogicException('Saisissez le n° de chaque bon d\'avoir présenté.'));

        PartDeReglement::numerosDesBons(['AV-LYON1-2026-000001', '  ']);
    }

    /**
     * Un même bon, écrit deux fois autrement (casse, espaces, accents), ne se
     * présente qu'une fois.
     */
    public function test_un_meme_bon_ne_se_presente_qu_une_fois(): void
    {
        $this->expectExceptionObject(new LogicException(PartDeReglement::BON_EN_DOUBLE));

        PartDeReglement::numerosDesBons(['av-été-2026-000002', ' AV-ÉTÉ-2026-000002']);
    }
}
