<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\ValeurSimple;
use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Une valeur simple lue en texte ou en entier (voir ValeurSimple) : sous
 * chaque forme que le navigateur, Livewire et la base rendent vraiment,
 * le résultat d'une conversion ; un tableau ou un objet est refusé.
 */
class ValeurSimpleTest extends TestCase
{
    /**
     * Chaque forme, avec le texte et l'entier attendus : ceux de (string)
     * et de (int).
     *
     * @return array<string, array{mixed, string, int}>
     */
    public static function formesSaisies(): array
    {
        return [
            'clé en texte' => ['12', '12', 12],
            'clé en nombre' => [12, '12', 12],
            'champ vide' => ['', '', 0],
            'rien' => [null, '', 0],
            'zéro en texte' => ['0', '0', 0],
            'un en texte' => ['1', '1', 1],
            'case cochée' => [true, '1', 1],
            'case décochée' => [false, '', 0],
            'nombre décimal' => [1.5, '1.5', 1],
            'texte libre' => ['abc', 'abc', 0],
        ];
    }

    #[DataProvider('formesSaisies')]
    public function test_une_valeur_simple_est_lue_comme_la_lisait_une_conversion(mixed $valeur, string $texte, int $entier): void
    {
        $this->assertSame($texte, ValeurSimple::enTexte($valeur));
        $this->assertSame($entier, ValeurSimple::enEntier($valeur));
    }

    /**
     * Une lecture facultative rend null pour null, et sinon ce que rend la
     * lecture obligatoire ; simple() rend la valeur telle quelle.
     */
    #[DataProvider('formesSaisies')]
    public function test_une_valeur_simple_facultative_reste_nulle_et_une_valeur_simple_est_rendue_telle_quelle(mixed $valeur, string $texte, int $entier): void
    {
        $this->assertSame($valeur === null ? null : $texte, ValeurSimple::enTexteOuNul($valeur));
        $this->assertSame($valeur === null ? null : $entier, ValeurSimple::enEntierOuNul($valeur));
        $this->assertSame($valeur, ValeurSimple::simple($valeur));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesValeursSimples(): array
    {
        return [
            'tableau de clés' => [['12', '13'], 'array'],
            'tableau vide' => [[], 'array'],
            'objet' => [new ArrayIterator(['12']), ArrayIterator::class],
        ];
    }

    #[DataProvider('pasDesValeursSimples')]
    public function test_un_tableau_ou_un_objet_est_refuse_en_texte(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeurSimple::REFUS, $type));

        ValeurSimple::enTexte($valeur);
    }

    #[DataProvider('pasDesValeursSimples')]
    public function test_un_tableau_ou_un_objet_est_refuse_en_entier(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeurSimple::REFUS, $type));

        ValeurSimple::enEntier($valeur);
    }

    #[DataProvider('pasDesValeursSimples')]
    public function test_un_tableau_ou_un_objet_est_refuse_en_texte_facultatif(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeurSimple::REFUS, $type));

        ValeurSimple::enTexteOuNul($valeur);
    }

    #[DataProvider('pasDesValeursSimples')]
    public function test_un_tableau_ou_un_objet_est_refuse_en_entier_facultatif(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeurSimple::REFUS, $type));

        ValeurSimple::enEntierOuNul($valeur);
    }

    #[DataProvider('pasDesValeursSimples')]
    public function test_un_tableau_ou_un_objet_est_refuse_tel_quel(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeurSimple::REFUS, $type));

        ValeurSimple::simple($valeur);
    }
}
