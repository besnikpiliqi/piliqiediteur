<?php

namespace Tests\Unit;

use App\Domain\Achats\Services\MontantsDuFournisseur;
use PHPUnit\Framework\TestCase;

/**
 * La saisie de ce qu'écrit la facture du fournisseur (voir
 * MontantsDuFournisseur::depuisLaSaisie()) : un taux qui n'est pas un
 * tableau, ou sans taux, ne dit rien ; une TVA, une base ou un total
 * laissés vides non plus ; chaque montant écrit se lit en centimes, par
 * taux en centièmes de point.
 */
class MontantsDuFournisseurTest extends TestCase
{
    public function test_seuls_les_taux_lisibles_et_les_montants_ecrits_comptent(): void
    {
        $montants = MontantsDuFournisseur::depuisLaSaisie([
            'taux' => [
                'taux illisible',
                ['taux_tva' => '', 'montant_tva' => '9.99'],
                ['taux_tva' => '20', 'montant_tva' => '4.00', 'base_ht' => '20.00'],
                ['taux_tva' => '5.5', 'montant_tva' => '', 'base_ht' => null],
            ],
            'total_ttc' => '',
            'confirmation' => '1',
        ]);

        $this->assertSame([2000 => 400], $montants->tvaParTaux);
        $this->assertSame([2000 => 2000], $montants->baseParTaux);
        $this->assertNull($montants->totalTtc);
        $this->assertTrue($montants->confirmation);
    }

    public function test_une_saisie_sans_taux_ni_total_ne_dit_rien(): void
    {
        $montants = MontantsDuFournisseur::depuisLaSaisie(['taux' => null, 'total_ttc' => '24.00']);

        $this->assertSame([[], [], 2400, false], [$montants->tvaParTaux, $montants->baseParTaux, $montants->totalTtc, $montants->confirmation]);
    }

    /**
     * Chaque montant écrit, et la confirmation, distinguent une demande
     * d'une autre (voir ActionsUniques) : le même jeton avec un autre
     * montant est une autre demande.
     */
    public function test_la_saisie_se_retient_tout_entiere_pour_distinguer_une_demande(): void
    {
        $this->assertSame(
            ['tva_par_taux' => [2000 => 400], 'base_par_taux' => [2000 => 2000], 'total_ttc' => 2400, 'confirmation' => true],
            (new MontantsDuFournisseur([2000 => 400], [2000 => 2000], 2400, true))->enTableau(),
        );
    }

    /**
     * Rien d'écrit : ni TVA, ni base, ni total, et rien de confirmé.
     */
    public function test_aucun_montant_ecrit_ne_confirme_rien(): void
    {
        $aucun = MontantsDuFournisseur::aucun();

        $this->assertSame([[], [], null, false], [$aucun->tvaParTaux, $aucun->baseParTaux, $aucun->totalTtc, $aucun->confirmation]);
    }
}
