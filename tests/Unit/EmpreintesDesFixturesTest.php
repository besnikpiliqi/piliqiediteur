<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * Les deux fixtures de la carte « Écart des prix d'achat » sont figées : le
 * relevé de la maquette validée par l'éditeur (verite.json) et les jeux
 * d'achats tirés une fois pour toutes avec les empreintes de leurs lectures
 * (jeux-au-hasard.json). Leur SHA-256 est écrit ici : ce test devient rouge
 * si l'un d'eux change : un échec du calcul se corrige dans le code, jamais
 * dans une fixture (voir EcartDesPrixDAchatContreLaMaquetteTest et
 * EcartDesPrixDAchatAuHasardTest).
 */
class EmpreintesDesFixturesTest extends TestCase
{
    private const EMPREINTE_DE_LA_VERITE = '1d72c42366045853dff523405a6c93eb03f7960ba77fcbe2f052180e28b4ad9f';

    private const EMPREINTE_DES_JEUX_AU_HASARD = 'b095a206ec6cdc9127d49970c6cc5e2bdd31bce7547c5b9660cd3c110ef40f55';

    public function test_le_releve_de_la_maquette_est_fige(): void
    {
        $this->assertSame(self::EMPREINTE_DE_LA_VERITE, hash_file('sha256', LectureDesFixtures::chemin('verite.json')), 'Une fixture de la carte a changé : la maquette validée et ses jeux sont figés.');
    }

    public function test_les_jeux_au_hasard_sont_figes(): void
    {
        $this->assertSame(self::EMPREINTE_DES_JEUX_AU_HASARD, hash_file('sha256', LectureDesFixtures::chemin('jeux-au-hasard.json')), 'Une fixture de la carte a changé : la maquette validée et ses jeux sont figés.');
    }
}
