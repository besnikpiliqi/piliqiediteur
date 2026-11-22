<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\MotsDePasseCourants;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La règle du mot de passe choisi (CLAUDE.md, « Comptes et équipe » ;
 * décision de Claude D76, T36.1 à T36.4) : au moins 12 caractères, ou au
 * moins 8 caractères de trois sortes parmi les majuscules, les minuscules,
 * les chiffres et les signes ; jamais un mot de passe courant, ni
 * l'identifiant du compte ni une chaîne qui le contient, ni un seul
 * caractère répété, ni une suite de chiffres. Sans dépendre d'un
 * identifiant tiré au hasard.
 */
class RegleDuMotDePasseTest extends TestCase
{
    private const IDENTIFIANT = '48210937';

    /**
     * Le seuil de la longueur et celui de la composition : 11 caractères
     * d'une seule sorte ne suffisent pas, 12 suffisent ; 7 caractères de
     * quatre sortes ne suffisent pas, 8 caractères de trois sortes
     * suffisent, 8 de deux sortes non.
     *
     * @return array<string, array{string, bool}>
     */
    public static function seuils(): array
    {
        return [
            '11 minuscules' => ['quillepotea', false],
            '12 minuscules' => ['quillepoteaux', true],
            '12 chiffres tirés au hasard' => ['739102846571', true],
            '12 caractères de deux sortes' => ['violette2704', true],
            '11 caractères de deux sortes' => ['violette270', false],
            '7 caractères de quatre sortes' => ['Aa1!bc9', false],
            '8 caractères de trois sortes' => ['Aa1bcdef', true],
            '8 caractères de trois sortes avec un signe' => ['aa1!bcdf', true],
            '8 caractères de deux sortes' => ['abcdef12', false],
            '8 chiffres' => ['70391826', false],
            'une espace est un signe' => ['mot de1', false],
            '8 caractères avec une espace' => ['mot de 1', true],
            'une lettre accentuée est une lettre' => ['Épaule12', true],
            'vide' => ['', false],
        ];
    }

    #[DataProvider('seuils')]
    public function test_au_moins_douze_caracteres_ou_huit_de_trois_sortes(string $motDePasse, bool $suffit): void
    {
        $this->assertSame($suffit, ! in_array(RegleDuMotDePasse::MESSAGE_TROP_FAIBLE, RegleDuMotDePasse::reproches($motDePasse, null), true), $motDePasse);
    }

    public function test_les_sortes_comptent_les_majuscules_les_minuscules_les_chiffres_et_les_signes(): void
    {
        $this->assertSame(0, RegleDuMotDePasse::sortes(''));
        $this->assertSame(1, RegleDuMotDePasse::sortes('abc'));
        $this->assertSame(2, RegleDuMotDePasse::sortes('abc123'));
        $this->assertSame(3, RegleDuMotDePasse::sortes('Abc123'));
        $this->assertSame(4, RegleDuMotDePasse::sortes('Abc123!'));
        $this->assertSame(4, RegleDuMotDePasse::sortes('Abc 123'));
        $this->assertSame(3, RegleDuMotDePasse::sortes('Éa1'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function motsDePasseCourants(): array
    {
        return [
            'dans la liste anglophone' => ['qwertyuiop'],
            'en majuscules' => ['QWERTYUIOP'],
            'avec la casse mêlée' => ['PassWord1'],
            'avec des espaces autour' => ['  baseball  '],
            'du supplément français' => ['azertyuiop123'],
            'un mois et une année' => ['Novembre2026'],
        ];
    }

    #[DataProvider('motsDePasseCourants')]
    public function test_un_mot_de_passe_courant_est_toujours_refuse(string $motDePasse): void
    {
        $this->assertTrue(MotsDePasseCourants::contient($motDePasse), $motDePasse);
        $this->assertContains(RegleDuMotDePasse::MESSAGE_COURANT, RegleDuMotDePasse::reproches($motDePasse, null), $motDePasse);
    }

    public function test_un_mot_de_passe_qui_n_est_pas_dans_la_liste_n_est_pas_courant(): void
    {
        foreach (['violette-2704-ocre', 'k7qp-2mzr-xa4d', 'Hier soir, la mer était calme.'] as $motDePasse) {
            $this->assertFalse(MotsDePasseCourants::contient($motDePasse), $motDePasse);
            $this->assertSame([], RegleDuMotDePasse::reproches($motDePasse, self::IDENTIFIANT), $motDePasse);
        }
    }

    public function test_la_liste_des_mots_de_passe_courants_n_a_que_des_mots_de_passe_d_au_moins_huit_caracteres_en_minuscules(): void
    {
        $liste = MotsDePasseCourants::liste();

        $this->assertGreaterThan(30000, count($liste));
        $this->assertArrayHasKey('password', $liste);
        $this->assertArrayHasKey('12345678', $liste);

        foreach (array_keys($liste) as $motDePasse) {
            $motDePasse = (string) $motDePasse;
            $this->assertGreaterThanOrEqual(8, strlen($motDePasse), $motDePasse);
            $this->assertSame(mb_strtolower($motDePasse), $motDePasse);
        }
    }

    public function test_ni_l_identifiant_ni_une_chaine_qui_le_contient(): void
    {
        $this->assertContains(RegleDuMotDePasse::MESSAGE_IDENTIFIANT, RegleDuMotDePasse::reproches(self::IDENTIFIANT, self::IDENTIFIANT));
        $this->assertContains(RegleDuMotDePasse::MESSAGE_IDENTIFIANT, RegleDuMotDePasse::reproches('violette-48210937-ocre', self::IDENTIFIANT));
        $this->assertNotContains(RegleDuMotDePasse::MESSAGE_IDENTIFIANT, RegleDuMotDePasse::reproches('violette-4821-ocre', self::IDENTIFIANT));
        $this->assertNotContains(RegleDuMotDePasse::MESSAGE_IDENTIFIANT, RegleDuMotDePasse::reproches('violette-48210937-ocre', null));
    }

    public function test_un_seul_caractere_repete_est_refuse(): void
    {
        $this->assertContains(RegleDuMotDePasse::MESSAGE_REPETE, RegleDuMotDePasse::reproches('aaaaaaaaaaaaaa', null));
        $this->assertContains(RegleDuMotDePasse::MESSAGE_REPETE, RegleDuMotDePasse::reproches('11111111', null));
        $this->assertNotContains(RegleDuMotDePasse::MESSAGE_REPETE, RegleDuMotDePasse::reproches('aaaaaaaaaaaab', null));
    }

    /**
     * Une suite de chiffres, que refuse la règle du mot de passe, est
     * croissante ou décroissante d'un chiffre à chaque pas, de bout en bout :
     * des chiffres qui commencent comme une suite puis s'en écartent, ou
     * qui avancent d'un autre pas, n'en sont pas une.
     *
     * @return array<string, array{string, bool}>
     */
    public static function suites(): array
    {
        return [
            'suite croissante' => ['23456789', true],
            'suite décroissante' => ['98765432', true],
            'suite de dix chiffres' => ['0123456789', true],
            'suite qui s’interrompt à la fin' => ['12345670', false],
            'suite qui s’interrompt au milieu' => ['12346789', false],
            'chiffres de deux en deux' => ['13579135', false],
            'chiffres et lettres' => ['1234567a', false],
        ];
    }

    #[DataProvider('suites')]
    public function test_seule_une_suite_de_chiffres_de_bout_en_bout_est_refusee(string $motDePasse, bool $estUneSuite): void
    {
        $this->assertSame($estUneSuite, in_array(RegleDuMotDePasse::MESSAGE_SUITE, RegleDuMotDePasse::reproches($motDePasse, null), true), $motDePasse);
    }

    public function test_les_messages_et_l_aide_disent_la_meme_regle(): void
    {
        $this->assertStringContainsString('12 caractères', RegleDuMotDePasse::AIDE);
        $this->assertStringContainsString('trois sortes', RegleDuMotDePasse::AIDE);
        $this->assertStringContainsString('courant', RegleDuMotDePasse::AIDE);
        $this->assertStringContainsString('12 caractères', RegleDuMotDePasse::MESSAGE_TROP_FAIBLE);
        $this->assertStringContainsString('8 caractères de trois sortes', RegleDuMotDePasse::MESSAGE_TROP_FAIBLE);
    }
}
