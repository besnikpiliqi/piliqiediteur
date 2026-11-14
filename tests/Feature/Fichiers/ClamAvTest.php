<?php

namespace Tests\Feature\Fichiers;

use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\ClamAv;
use RuntimeException;
use Tests\TestCase;

/**
 * Le pilote ClamAV de l'antivirus (voir ClamAv), sans démon ni réseau : la
 * connexion à clamd est une paire de sockets locales, dont l'autre bout
 * joue clamd (sa réponse écrite d'avance) et relit ce que PILIQI lui a
 * envoyé, octet par octet, selon le protocole INSTREAM.
 */
class ClamAvTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $clamd = null;

    public function test_le_contenu_part_en_morceaux_par_instream_et_une_reponse_ok_le_dit_sain(): void
    {
        $contenu = str_repeat('a', 65536).'fin';

        $resultat = $this->clamAv("stream: OK\0")->analyser($contenu);

        $this->assertTrue($resultat->estSain());
        $this->assertSame("zINSTREAM\0".pack('N', 65536).str_repeat('a', 65536).pack('N', 3).'fin'.pack('N', 0), $this->envoye());
    }

    public function test_une_menace_trouvee_porte_son_nom(): void
    {
        $resultat = $this->clamAv("stream: Win.Test.EICAR_HDB-1 FOUND\0")->analyser('X5O');

        $this->assertSame('Win.Test.EICAR_HDB-1', $resultat->menace);
        $this->assertFalse($resultat->estSain());
    }

    public function test_une_erreur_de_clamd_ou_aucune_reponse_le_tient_pour_injoignable(): void
    {
        foreach (["INSTREAM size limit exceeded. ERROR\0", ''] as $reponse) {
            try {
                $this->clamAv($reponse)->analyser('contenu');
                $this->fail('Une réponse qui n\'est pas un verdict ne juge pas le fichier.');
            } catch (AntivirusInjoignableException $injoignable) {
                $this->assertStringStartsWith('L\'antivirus ne répond pas', $injoignable->getMessage());
            }
        }
    }

    public function test_un_clamd_injoignable_le_dit(): void
    {
        $this->expectException(AntivirusInjoignableException::class);
        $this->expectExceptionMessage('clamd est injoignable à unix:///chemin/qui/n/existe/pas.ctl');

        (new ClamAv('unix:///chemin/qui/n/existe/pas.ctl', 1))->analyser('contenu');
    }

    public function test_la_description_est_la_version_de_clamd(): void
    {
        $this->assertSame('ClamAV 1.4.3/27800/Tue Nov 10 09:00:00 2026', $this->clamAv("ClamAV 1.4.3/27800/Tue Nov 10 09:00:00 2026\0")->description());
        $this->assertSame("zVERSION\0", $this->envoye());
    }

    /**
     * Un pilote ClamAV dont clamd, à l'autre bout, répondra ceci, puis
     * fermera la connexion.
     */
    private function clamAv(string $reponse): ClamAv
    {
        $paire = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($paire === false) {
            throw new RuntimeException('Paire de sockets impossible à créer.');
        }

        [$cotePiliqi, $this->clamd] = $paire;
        fwrite($this->clamd, $reponse);
        stream_socket_shutdown($this->clamd, STREAM_SHUT_WR);

        return new ClamAv('unix:///clamd-des-tests.ctl', 5, function (string $adresse, int $delai) use ($cotePiliqi) {
            $this->assertSame(['unix:///clamd-des-tests.ctl', 5], [$adresse, $delai]);

            return $cotePiliqi;
        });
    }

    /**
     * Ce que PILIQI a envoyé à clamd.
     */
    private function envoye(): string
    {
        return (string) stream_get_contents($this->clamd);
    }
}
