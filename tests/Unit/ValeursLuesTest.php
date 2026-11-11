<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\ValeursLues;
use ArrayIterator;
use EmptyIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use UnexpectedValueException;

/**
 * Les valeurs lues là où rien ne dit leur type à l'analyse statique (voir
 * ValeursLues) : chacune est rendue telle quelle quand elle est du type que
 * le code attend, et refusée sinon, avec un message qui nomme le type
 * trouvé, le texte qui n'est pas un nombre ou le champ absent.
 */
class ValeursLuesTest extends TestCase
{
    public function test_un_texte_et_un_entier_sont_rendus_tels_quels(): void
    {
        $this->assertSame('0', ValeursLues::texte('0'));
        $this->assertSame('', ValeursLues::texte(''));
        $this->assertSame(0, ValeursLues::entier(0));
        $this->assertSame(-12, ValeursLues::entier(-12));
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

    /**
     * Une clé saisie se cherche parmi des fiches rangées sous leur clé
     * comme PHP lit une clé de tableau : un entier ou un texte tel quel
     * (PHP lit lui-même « 12 » comme la clé 12), un nombre décimal privé de
     * ses décimales, un booléen à 1 ou 0, et rien au texte vide.
     *
     * @return array<string, array{mixed, int|string}>
     */
    public static function clesDeTableau(): array
    {
        return [
            'un entier' => [12, 12],
            'un texte' => ['12', '12'],
            'un texte entouré d\'espaces' => [' 12 ', ' 12 '],
            'le texte « 0 »' => ['0', '0'],
            'un texte vide' => ['', ''],
            'rien' => [null, ''],
            'vrai' => [true, 1],
            'faux' => [false, 0],
            'un décimal sans partie décimale' => [12.0, 12],
            'un décimal' => [12.5, 12],
            'un décimal négatif' => [-1.5, -1],
        ];
    }

    #[DataProvider('clesDeTableau')]
    public function test_une_cle_saisie_se_lit_comme_la_cle_de_tableau_que_php_en_fait(mixed $valeur, int|string $cle): void
    {
        $this->assertSame($cle, ValeursLues::cleDeTableau($valeur));
    }

    #[DataProvider('pasDesCles')]
    public function test_une_cle_de_tableau_qui_n_est_pas_une_seule_cle_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_CLE, $type));

        ValeursLues::cleDeTableau($valeur);
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
            'valeur telle quelle' => [ValeursLues::champ(...)],
            'entier ou nul' => [ValeursLues::entierOuNulDe(...)],
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

    public function test_vrai_ou_faux_est_rendu_tel_quel(): void
    {
        $this->assertTrue(ValeursLues::booleen(true));
        $this->assertFalse(ValeursLues::booleen(false));
    }

    /**
     * Ce qui vaudrait vrai ou faux par une conversion (1, « 0 », rien)
     * n'est pas vrai ou faux.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function niVraisNiFaux(): array
    {
        return [
            'un entier' => [1, 'int'],
            'un texte' => ['0', 'string'],
            'rien' => [null, 'null'],
        ];
    }

    #[DataProvider('niVraisNiFaux')]
    public function test_une_valeur_ni_vraie_ni_fausse_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_BOOLEEN, $type));

        ValeursLues::booleen($valeur);
    }

    /**
     * Une quantité saisie arrive en texte du navigateur, ou en entier de
     * la valeur par défaut du champ : rendue telle quelle, sans
     * conversion.
     */
    public function test_un_entier_ou_un_texte_est_rendu_tel_quel(): void
    {
        $this->assertSame(3, ValeursLues::entierOuTexte(3));
        $this->assertSame('3', ValeursLues::entierOuTexte('3'));
        $this->assertSame('', ValeursLues::entierOuTexte(''));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function niEntiersNiTextes(): array
    {
        return [
            'rien' => [null, 'null'],
            'un nombre à virgule' => [1.5, 'float'],
            'un booléen' => [true, 'bool'],
            'un tableau' => [['3'], 'array'],
        ];
    }

    #[DataProvider('niEntiersNiTextes')]
    public function test_une_valeur_ni_entiere_ni_texte_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_ENTIER_OU_TEXTE, $type));

        ValeursLues::entierOuTexte($valeur);
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

    /**
     * Des textes lus sous leur clé gardent cette clé, un entier, et l'ordre
     * de leur source (ce que rend pluck('nom', 'id')).
     */
    public function test_des_textes_par_cle_gardent_leur_cle_et_leur_ordre(): void
    {
        $this->assertSame([7 => 'Lyon', 2 => 'Annecy'], ValeursLues::textesParCle(new ArrayIterator([7 => 'Lyon', 2 => 'Annecy'])));
        $this->assertSame([], ValeursLues::textesParCle([]));
    }

    public function test_des_textes_par_cle_dont_une_cle_ou_une_valeur_est_d_un_autre_type_sont_refuses(): void
    {
        $refus = [];

        foreach ([
            fn (): array => ValeursLues::textesParCle(['lyon' => 'Lyon']),
            fn (): array => ValeursLues::textesParCle([7 => null]),
        ] as $lecture) {
            try {
                $lecture();
            } catch (UnexpectedValueException $exception) {
                $refus[] = $exception->getMessage();
            }
        }

        $this->assertSame([sprintf(ValeursLues::REFUS_ENTIER, 'string'), sprintf(ValeursLues::REFUS_TEXTE, 'null')], $refus);
    }

    public function test_l_entier_facultatif_d_un_champ_est_rendu_tel_quel_ou_nul(): void
    {
        $this->assertSame(0, ValeursLues::entierOuNulDe(['capital_social' => 0], 'capital_social'));
        $this->assertNull(ValeursLues::entierOuNulDe(['capital_social' => null], 'capital_social'));
    }

    public function test_l_entier_facultatif_d_un_autre_type_est_refuse(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_ENTIER, 'string'));

        ValeursLues::entierOuNulDe(['capital_social' => '100000'], 'capital_social');
    }

    public function test_un_champ_present_est_rendu_tel_quel_meme_nul(): void
    {
        $this->assertSame('12.50', ValeursLues::champ(['jusqu_a' => '12.50', 'benefice' => null], 'jusqu_a'));
        $this->assertNull(ValeursLues::champ(['jusqu_a' => '12.50', 'benefice' => null], 'benefice'));
        $this->assertSame([], ValeursLues::champ(['paliers' => []], 'paliers'));
    }

    public function test_l_entier_d_un_champ_est_rendu_tel_quel(): void
    {
        $this->assertSame(0, ValeursLues::entierDe(['sequence' => 0], 'sequence'));
        $this->assertSame(-12, ValeursLues::entierDe(['montant' => -12, 'sequence' => 3], 'montant'));
    }

    public function test_l_entier_d_un_champ_absent_ou_d_un_autre_type_est_refuse(): void
    {
        $refus = [];

        foreach ([
            fn (): int => ValeursLues::entierDe(['montant' => '12'], 'montant'),
            fn (): int => ValeursLues::entierDe(['montant' => 12], 'sequence'),
        ] as $lecture) {
            try {
                $lecture();
            } catch (UnexpectedValueException $exception) {
                $refus[] = $exception->getMessage();
            }
        }

        $this->assertSame([sprintf(ValeursLues::REFUS_ENTIER, 'string'), sprintf(ValeursLues::REFUS_CHAMP, 'sequence')], $refus);
    }

    public function test_un_texte_facultatif_est_rendu_tel_quel_ou_nul(): void
    {
        $this->assertSame('0', ValeursLues::texteOuNul('0'));
        $this->assertSame('', ValeursLues::texteOuNul(''));
        $this->assertNull(ValeursLues::texteOuNul(null));
    }

    public function test_un_texte_facultatif_d_un_autre_type_est_refuse(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_TEXTE, 'int'));

        ValeursLues::texteOuNul(0);
    }

    public function test_un_tableau_est_rendu_tel_quel(): void
    {
        $this->assertSame(['ville' => 'Lyon', 3 => null], ValeursLues::tableau(['ville' => 'Lyon', 3 => null]));
        $this->assertSame([], ValeursLues::tableau([]));
    }

    /**
     * Un itérable (une collection) n'est pas un tableau.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesTableaux(): array
    {
        return [
            'nul' => [null, 'null'],
            'texte' => ['Lyon', 'string'],
            'itérable' => [new ArrayIterator(['Lyon']), ArrayIterator::class],
        ];
    }

    #[DataProvider('pasDesTableaux')]
    public function test_une_valeur_qui_n_est_pas_un_tableau_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_TABLEAU, $type));

        ValeursLues::tableau($valeur);
    }

    public function test_les_champs_d_un_tableau_sont_rendus_tels_quels_dans_leur_ordre(): void
    {
        $this->assertSame(['nom' => 'Caisse 1', 'code' => null], ValeursLues::champs(['nom' => 'Caisse 1', 'code' => null]));
        $this->assertSame([], ValeursLues::champs([]));
    }

    /**
     * Une clé entière, que PHP donne aussi à un nom de champ écrit en
     * chiffres, n'est pas un nom de champ.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesChamps(): array
    {
        return [
            'liste' => [['Caisse 1'], sprintf(ValeursLues::REFUS_CHAMPS, 0)],
            'nom en chiffres' => [['nom' => 'Caisse 1', '12' => 'a'], sprintf(ValeursLues::REFUS_CHAMPS, 12)],
            'texte' => ['nom', sprintf(ValeursLues::REFUS_TABLEAU, 'string')],
        ];
    }

    #[DataProvider('pasDesChamps')]
    public function test_un_tableau_qui_a_une_cle_entiere_ou_une_valeur_qui_n_est_pas_un_tableau_est_refuse(mixed $valeur, string $refus): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($refus);

        ValeursLues::champs($valeur);
    }

    public function test_les_lignes_d_un_repeteur_sont_rendues_sous_leurs_cles_dans_leur_ordre(): void
    {
        $lignes = ['record-7' => ['quantite' => '2', 'produit_id' => 12], 'a3f9' => ['quantite' => 1, 'produit_id' => null]];

        $this->assertSame($lignes, ValeursLues::lignes($lignes));
        $this->assertSame([], ValeursLues::lignes([]));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesLignes(): array
    {
        return [
            'texte' => ['lignes', sprintf(ValeursLues::REFUS_TABLEAU, 'string')],
            'ligne qui n\'est pas un tableau' => [['record-7' => ['quantite' => 2], 'record-8' => '3'], sprintf(ValeursLues::REFUS_TABLEAU, 'string')],
            'ligne en liste' => [['record-7' => ['2', 12]], sprintf(ValeursLues::REFUS_CHAMPS, 0)],
        ];
    }

    #[DataProvider('pasDesLignes')]
    public function test_des_lignes_qui_ne_sont_pas_des_tableaux_de_champs_sont_refusees(mixed $valeur, string $refus): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($refus);

        ValeursLues::lignes($valeur);
    }

    /**
     * La valeur au bout d'un chemin se lit comme $valeur['a']['b'] ?? null :
     * nulle quand un maillon manque ou n'est pas un tableau, telle quelle
     * sinon, même nulle, fausse ou vide.
     */
    public function test_la_valeur_au_bout_d_un_chemin_est_rendue_ou_nulle_quand_un_maillon_manque(): void
    {
        $reponse = ['adresse' => ['source' => 'rne', 'complement' => null, 'voie' => '', 'numero' => 0], 'nom' => 'SAS EXEMPLE'];

        $this->assertSame('rne', ValeursLues::valeurAu($reponse, 'adresse', 'source'));
        $this->assertSame(['source' => 'rne', 'complement' => null, 'voie' => '', 'numero' => 0], ValeursLues::valeurAu($reponse, 'adresse'));
        $this->assertSame('', ValeursLues::valeurAu($reponse, 'adresse', 'voie'));
        $this->assertSame(0, ValeursLues::valeurAu($reponse, 'adresse', 'numero'));
        $this->assertSame($reponse, ValeursLues::valeurAu($reponse));
        $this->assertNull(ValeursLues::valeurAu($reponse, 'adresse', 'complement'));
        $this->assertNull(ValeursLues::valeurAu($reponse, 'adresse', 'ville'));
        $this->assertNull(ValeursLues::valeurAu($reponse, 'nom', 'source'));
        $this->assertNull(ValeursLues::valeurAu(null, 'adresse'));
        $this->assertNull(ValeursLues::valeurAu('rne', 'adresse'));
    }

    public function test_un_objet_de_la_classe_attendue_ou_d_une_classe_qui_en_herite_est_rendu_tel_quel(): void
    {
        $iterateur = new ArrayIterator([12]);
        $recursif = new RecursiveArrayIterator([12]);

        $this->assertSame($iterateur, ValeursLues::instance($iterateur, ArrayIterator::class));
        $this->assertSame($recursif, ValeursLues::instance($recursif, ArrayIterator::class));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function pasDesIterateurs(): array
    {
        return [
            'nul' => [null, 'null'],
            'tableau' => [[12], 'array'],
            'objet d\'une autre classe' => [new EmptyIterator, EmptyIterator::class],
        ];
    }

    #[DataProvider('pasDesIterateurs')]
    public function test_une_valeur_qui_n_est_pas_un_objet_de_la_classe_attendue_est_refusee(mixed $valeur, string $type): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeursLues::REFUS_INSTANCE, ArrayIterator::class, $type));

        ValeursLues::instance($valeur, ArrayIterator::class);
    }
}
