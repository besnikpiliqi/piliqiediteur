<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une suite de chiffres, que refuse la règle du mot de passe (CLAUDE.md,
 * « Comptes et équipe »), est croissante ou décroissante d'un chiffre à
 * chaque pas, de bout en bout : des chiffres qui commencent comme une suite
 * puis s'en écartent, ou qui avancent d'un autre pas, n'en sont pas une.
 * Sans dépendre d'un identifiant tiré au hasard, qui ne le vérifiait qu'une
 * fois sur plusieurs.
 */
class RegleDuMotDePasseTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function motsDePasse(): array
    {
        return [
            'suite croissante' => ['23456789', [RegleDuMotDePasse::MESSAGE_SUITE]],
            'suite décroissante' => ['98765432', [RegleDuMotDePasse::MESSAGE_SUITE]],
            'suite qui s’interrompt à la fin' => ['12345670', []],
            'suite qui s’interrompt au milieu' => ['12346789', []],
            'chiffres de deux en deux' => ['13579135', []],
            'chiffres et lettres' => ['1234567a', []],
        ];
    }

    /**
     * @param  list<string>  $reproches
     */
    #[DataProvider('motsDePasse')]
    public function test_seule_une_suite_de_chiffres_de_bout_en_bout_est_refusee(string $motDePasse, array $reproches): void
    {
        $this->assertSame($reproches, RegleDuMotDePasse::reproches($motDePasse, null));
    }
}
