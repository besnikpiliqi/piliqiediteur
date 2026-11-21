<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\ResultatDePhp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Le résultat d'une fonction qui rend false quand elle échoue (voir
 * ResultatDePhp) : un texte ou un flux est rendu tel quel, un texte vide
 * compris ; false lève une exception qui nomme l'opération.
 */
class ResultatDePhpTest extends TestCase
{
    public function test_un_texte_est_rendu_tel_quel_meme_vide(): void
    {
        $this->assertSame('1 234,50', ResultatDePhp::texte('1 234,50', 'Number::format()'));
        $this->assertSame('', ResultatDePhp::texte('', 'Number::format()'));
        $this->assertSame('0', ResultatDePhp::texte('0', 'Number::format()'));
    }

    public function test_un_texte_qui_echoue_leve_une_exception_qui_nomme_l_operation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(ResultatDePhp::ECHEC, 'Number::format()'));

        ResultatDePhp::texte(false, 'Number::format()');
    }

    public function test_un_flux_ouvert_est_rendu_tel_quel(): void
    {
        $ouvert = fopen('php://memory', 'w+');

        try {
            $this->assertSame($ouvert, ResultatDePhp::flux($ouvert, 'fopen()'));
        } finally {
            fclose($ouvert);
        }
    }

    public function test_un_flux_qui_n_a_pas_pu_s_ouvrir_leve_une_exception_qui_nomme_l_operation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(ResultatDePhp::ECHEC, 'fopen()'));

        ResultatDePhp::flux(false, 'fopen()');
    }
}
