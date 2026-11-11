<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Support\Reglage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Un réglage de l'application (voir Reglage) : rendu tel que son fichier
 * de configuration l'écrit quand il est du type attendu ; absent ou d'un
 * autre type, il est refusé par une exception qui le nomme.
 */
class ReglageTest extends TestCase
{
    public function test_un_reglage_du_type_attendu_est_rendu_tel_quel(): void
    {
        config(['piliqi.essai' => ['texte' => '0', 'vide' => null, 'entier' => 0, 'tableau' => ['a' => 1]]]);

        $this->assertSame('0', Reglage::texte('piliqi.essai.texte'));
        $this->assertSame('0', Reglage::texteOuNul('piliqi.essai.texte'));
        $this->assertNull(Reglage::texteOuNul('piliqi.essai.vide'));
        $this->assertSame(0, Reglage::entier('piliqi.essai.entier'));
        $this->assertSame(['a' => 1], Reglage::tableau('piliqi.essai.tableau'));
    }

    /**
     * Un entier écrit en texte (une variable d'environnement que le fichier
     * ne convertit pas) n'est pas un entier, et un réglage absent n'est
     * d'aucun type.
     *
     * @return array<string, array{callable(string): mixed, string, string}>
     */
    public static function reglagesRefuses(): array
    {
        return [
            'texte absent' => [Reglage::texte(...), 'absent', sprintf(Reglage::REFUS_TEXTE, 'piliqi.essai.absent', 'null')],
            'texte qui est un entier' => [Reglage::texte(...), 'entier', sprintf(Reglage::REFUS_TEXTE, 'piliqi.essai.entier', 'int')],
            'texte facultatif qui est un booléen' => [Reglage::texteOuNul(...), 'booleen', sprintf(Reglage::REFUS_TEXTE_OU_NUL, 'piliqi.essai.booleen', 'bool')],
            'entier écrit en texte' => [Reglage::entier(...), 'texte', sprintf(Reglage::REFUS_ENTIER, 'piliqi.essai.texte', 'string')],
            'entier absent' => [Reglage::entier(...), 'absent', sprintf(Reglage::REFUS_ENTIER, 'piliqi.essai.absent', 'null')],
            'tableau qui est un texte' => [Reglage::tableau(...), 'texte', sprintf(Reglage::REFUS_TABLEAU, 'piliqi.essai.texte', 'string')],
        ];
    }

    /**
     * @param  callable(string): mixed  $lecture
     */
    #[DataProvider('reglagesRefuses')]
    public function test_un_reglage_absent_ou_d_un_autre_type_est_refuse(callable $lecture, string $cle, string $refus): void
    {
        config(['piliqi.essai' => ['texte' => '30', 'entier' => 30, 'booleen' => false]]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($refus);

        $lecture("piliqi.essai.{$cle}");
    }
}
