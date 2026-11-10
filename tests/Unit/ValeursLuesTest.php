<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\ValeursLues;
use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Les valeurs lues là où rien ne dit leur type à l'analyse statique (voir
 * ValeursLues) : chacune est rendue telle quelle quand elle est du type que
 * le code attend, et refusée sinon, avec un message qui nomme le type
 * trouvé, le texte qui n'est pas un nombre ou le champ absent.
 */
class ValeursLuesTest extends TestCase
{
    public function test_un_texte_un_entier_et_un_nombre_ecrit_en_texte_sont_rendus_tels_quels(): void
    {
        $this->assertSame('0', ValeursLues::texte('0'));
        $this->assertSame('', ValeursLues::texte(''));
        $this->assertSame(0, ValeursLues::entier(0));
        $this->assertSame(-12, ValeursLues::entier(-12));
        $this->assertSame('120.00', ValeursLues::nombre('120.00'));
        $this->assertSame('-0.05', ValeursLues::nombre('-0.05'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesTextes(): array
    {
        return [
            'nul' => [null, 'null'],
            'entier' => [12, 'int'],
            'décimal' => [1.5, 'float'],
            'booléen' => [false, 'bool'],
            'tableau' => [['a'], 'array'],
        ];
    }

    #[DataProvider('pasDesTextes')]
    public function test_une_valeur_qui_n_est_pas_un_texte_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_TEXTE, $type));

        ValeursLues::texte($valeur);
    }

    /**
     * Un entier écrit en texte n'est pas un entier : la valeur lue n'est
     * jamais convertie.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesEntiers(): array
    {
        return [
            'nul' => [null, 'null'],
            'entier écrit en texte' => ['12', 'string'],
            'décimal entier' => [12.0, 'float'],
            'booléen' => [true, 'bool'],
        ];
    }

    #[DataProvider('pasDesEntiers')]
    public function test_une_valeur_qui_n_est_pas_un_entier_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_ENTIER, $type));

        ValeursLues::entier($valeur);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pasDesNombres(): array
    {
        return [
            'vide' => [''],
            'virgule décimale' => ['12,50'],
            'monnaie' => ['12.50 €'],
            'lettres' => ['douze'],
        ];
    }

    #[DataProvider('pasDesNombres')]
    public function test_un_texte_qui_n_est_pas_un_nombre_est_refuse(string $texte): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_NOMBRE, $texte));

        ValeursLues::nombre($texte);
    }

    /**
     * Une clé saisie dans un écran est rendue sous chaque forme que le
     * navigateur et Livewire envoient, pour que whereKey() la lise comme
     * find() lisait une seule clé.
     */
    public function test_une_cle_saisie_est_rendue_telle_quelle(): void
    {
        $this->assertSame('12', ValeursLues::cle('12'));
        $this->assertSame(12, ValeursLues::cle(12));
        $this->assertSame('1', ValeursLues::cle('1'));
        $this->assertSame('0', ValeursLues::cle('0'));
        $this->assertSame('', ValeursLues::cle(''));
        $this->assertTrue(ValeursLues::cle(true));
        $this->assertNull(ValeursLues::cle(null));
    }

    /**
     * Un tableau, que find() lisait comme plusieurs clés, et un objet sont
     * refusés.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesCles(): array
    {
        return [
            'tableau de clés' => [[12, 13], 'array'],
            'tableau vide' => [[], 'array'],
            'objet' => [new ArrayIterator([12]), ArrayIterator::class],
        ];
    }

    #[DataProvider('pasDesCles')]
    public function test_une_cle_saisie_qui_n_est_pas_une_seule_cle_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_CLE, $type));

        ValeursLues::cle($valeur);
    }

    public function test_les_champs_d_un_tableau_lu_sont_rendus_tels_quels(): void
    {
        $tableau = ['nom' => 'Caisse 1', 'complement' => null, 'personne_morale' => false, 'adresse' => ['ville' => 'Lyon']];

        $this->assertSame('Caisse 1', ValeursLues::texteDe($tableau, 'nom'));
        $this->assertSame('Caisse 1', ValeursLues::texteOuNulDe($tableau, 'nom'));
        $this->assertNull(ValeursLues::texteOuNulDe($tableau, 'complement'));
        $this->assertFalse(ValeursLues::booleenDe($tableau, 'personne_morale'));
        $this->assertTrue(ValeursLues::booleenDe(['personne_morale' => true], 'personne_morale'));
        $this->assertSame(['ville' => 'Lyon'], ValeursLues::tableauDe($tableau, 'adresse'));
    }

    /**
     * Un champ absent est refusé même là où un champ nul est accepté : la
     * source l'écrit toujours.
     *
     * @return array<string, array{callable(array<array-key, mixed>, string): mixed}>
     */
    public static function lecturesDUnChamp(): array
    {
        return [
            'texte' => [ValeursLues::texteDe(...)],
            'texte ou nul' => [ValeursLues::texteOuNulDe(...)],
            'booléen' => [ValeursLues::booleenDe(...)],
            'tableau' => [ValeursLues::tableauDe(...)],
        ];
    }

    /**
     * @param  callable(array<array-key, mixed>, string): mixed  $lecture
     */
    #[DataProvider('lecturesDUnChamp')]
    public function test_un_champ_absent_est_refuse(callable $lecture): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_CHAMP, 'ville'));

        $lecture(['code_postal' => '69001'], 'ville');
    }

    public function test_un_champ_d_un_autre_type_est_refuse(): void
    {
        $refus = [];

        foreach ([
            fn (): string => ValeursLues::texteDe(['nom' => 12], 'nom'),
            fn (): ?string => ValeursLues::texteOuNulDe(['nom' => false], 'nom'),
            fn (): bool => ValeursLues::booleenDe(['personne_morale' => 1], 'personne_morale'),
            fn (): bool => ValeursLues::booleenDe(['personne_morale' => '0'], 'personne_morale'),
            fn (): array => ValeursLues::tableauDe(['adresse' => 'Lyon'], 'adresse'),
        ] as $lecture) {
            try {
                $lecture();
            } catch (UnexpectedValueException $exception) {
                $refus[] = $exception->getMessage();
            }
        }

        $this->assertSame([
            sprintf(ValeursLues::REFUS_TEXTE, 'int'),
            sprintf(ValeursLues::REFUS_TEXTE, 'bool'),
            sprintf(ValeursLues::REFUS_BOOLEEN, 'int'),
            sprintf(ValeursLues::REFUS_BOOLEEN, 'string'),
            sprintf(ValeursLues::REFUS_TABLEAU, 'string'),
        ], $refus);
    }

    /**
     * Une liste lue garde l'ordre de sa source, sans ses clés, d'un tableau
     * comme d'un itérable (une collection).
     */
    public function test_une_liste_de_textes_ou_d_entiers_garde_son_ordre_sans_ses_cles(): void
    {
        $this->assertSame(['b', 'a'], ValeursLues::textes(['x' => 'b', 'y' => 'a']));
        $this->assertSame([], ValeursLues::textes([]));
        $this->assertSame([3, 1, 2], ValeursLues::entiers(new ArrayIterator([7 => 3, 2 => 1, 5 => 2])));
    }

    public function test_une_liste_qui_contient_une_autre_valeur_est_refusee(): void
    {
        $refus = [];

        foreach ([
            fn (): array => ValeursLues::textes(['a', null]),
            fn (): array => ValeursLues::entiers([1, '2']),
        ] as $lecture) {
            try {
                $lecture();
            } catch (UnexpectedValueException $exception) {
                $refus[] = $exception->getMessage();
            }
        }

        $this->assertSame([sprintf(ValeursLues::REFUS_TEXTE, 'null'), sprintf(ValeursLues::REFUS_ENTIER, 'string')], $refus);
    }
}
