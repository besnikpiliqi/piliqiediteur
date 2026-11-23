<?php

namespace Tests\Unit;

use App\Domain\Demonstration\Bases\IdentifiantSql;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le nom d'une base ou d'un rôle du site de démonstration écrit en
 * identifiant SQL dans une instruction que PostgreSQL ne prépare pas :
 * repris caractère par caractère, entre guillemets, et refusé dès qu'il
 * porte autre chose qu'une minuscule, un chiffre ou un tiret bas, jamais
 * échappé.
 */
class IdentifiantSqlTest extends TestCase
{
    public function test_un_nom_de_base_ou_de_role_s_ecrit_entre_guillemets_tel_quel(): void
    {
        $this->assertSame('"demo_copie_01m7m78dy5a23r47q6qqn9jyz7"', IdentifiantSql::de('demo_copie_01m7m78dy5a23r47q6qqn9jyz7'));
        $this->assertSame('"demo_admin"', IdentifiantSql::de('demo_admin'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nomsRefuses(): array
    {
        return [
            'vide' => [''],
            'guillemet' => ['demo"; drop database piliqi; --'],
            'majuscule' => ['Demo_admin'],
            'tiret' => ['demo-admin'],
            'espace' => ['demo admin'],
            'lettre accentuée' => ['démo_admin'],
        ];
    }

    #[DataProvider('nomsRefuses')]
    public function test_un_nom_qui_porte_un_autre_caractere_est_refuse_sans_etre_echappe(string $nom): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(IdentifiantSql::REFUS, $nom));

        IdentifiantSql::de($nom);
    }
}
