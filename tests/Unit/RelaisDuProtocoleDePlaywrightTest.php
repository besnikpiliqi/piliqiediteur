<?php

namespace Tests\Unit;

use Amp\Websocket\Client\WebsocketConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Browser\Support\RelaisDuProtocoleDePlaywright;

/**
 * Le relais qui adapte chaque appel du module browser de Pest au protocole
 * du serveur de Playwright 1.62 et suivants (voir ProtocoleDePlaywright),
 * sans base ni navigateur : le délai du geste là où le serveur le lit
 * (« metadata.timeout »), et les fichiers d'un champ de fichier par leur
 * contenu, ce que le module ne fait pas.
 */
class RelaisDuProtocoleDePlaywrightTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = sys_get_temp_dir().'/relais-playwright-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            unlink($fichier);
        }

        rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_le_delai_des_parametres_est_recopie_dans_les_metadonnees_sans_rien_changer_d_autre(): void
    {
        $appel = '{"id":"a1","guid":"frame@1","method":"click","params":{"timeout":15000,"selector":"#ok","strict":true,"position":{"x":1.5,"y":2},"arg":{}},"metadata":[]}';

        $this->assertSame(
            '{"id":"a1","guid":"frame@1","method":"click","params":{"timeout":15000,"selector":"#ok","strict":true,"position":{"x":1.5,"y":2},"arg":{}},"metadata":{"timeout":15000}}',
            RelaisDuProtocoleDePlaywright::adapter($appel),
        );
    }

    public function test_un_delai_en_nombre_a_virgule_se_recopie_dans_des_metadonnees_deja_remplies(): void
    {
        $appel = '{"id":"a2","guid":"page@1","method":"goto","params":{"timeout":2500.5,"url":"http://127.0.0.1/"},"metadata":{"title":"Aller"}}';

        $this->assertSame(
            '{"id":"a2","guid":"page@1","method":"goto","params":{"timeout":2500.5,"url":"http:\/\/127.0.0.1\/"},"metadata":{"title":"Aller","timeout":2500.5}}',
            RelaisDuProtocoleDePlaywright::adapter($appel),
        );
    }

    public function test_un_delai_deja_dans_les_metadonnees_n_est_pas_remplace(): void
    {
        $appel = '{"id":"a3","guid":"frame@1","method":"click","params":{"timeout":15000},"metadata":{"timeout":500}}';

        $this->assertSame($appel, RelaisDuProtocoleDePlaywright::adapter($appel));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function messagesSansRienAAdapter(): array
    {
        return [
            'appel sans délai' => ['{"id":"a4","guid":"","method":"initialize","params":{},"metadata":[]}'],
            'appel sans paramètres' => ['{"id":"a5","guid":"","method":"close"}'],
            'délai qui n\'est pas un nombre' => ['{"id":"a6","guid":"","method":"close","params":{"timeout":"15000"},"metadata":[]}'],
            'paramètres en liste' => ['{"id":"a7","guid":"","method":"close","params":[15000],"metadata":[]}'],
            'métadonnées qui ne sont pas un objet' => ['{"id":"a8","guid":"","method":"close","params":{"timeout":15000},"metadata":"x"}'],
            'chemins de fichiers dans un autre appel' => ['{"id":"a9","guid":"","method":"fill","params":{"localPaths":["/tmp/x.txt"]},"metadata":{"timeout":1}}'],
            'chemins qui ne sont pas des textes' => ['{"id":"b1","guid":"","method":"setInputFiles","params":{"localPaths":[1]},"metadata":{"timeout":1}}'],
            'aucun chemin' => ['{"id":"b2","guid":"","method":"setInputFiles","params":{"localPaths":[]},"metadata":{"timeout":1}}'],
            'texte qui n\'est pas du JSON' => ['pas du json'],
            'JSON qui n\'est pas un appel' => ['[1,2,3]'],
        ];
    }

    #[DataProvider('messagesSansRienAAdapter')]
    public function test_un_message_qui_n_a_rien_a_adapter_reste_tel_quel(string $message): void
    {
        $this->assertSame($message, RelaisDuProtocoleDePlaywright::adapter($message));
    }

    public function test_les_fichiers_d_un_champ_de_fichier_partent_par_leur_nom_et_leur_contenu(): void
    {
        $premier = $this->dossier.'/facture 1.pdf';
        $second = $this->dossier.'/pièce.txt';
        file_put_contents($premier, "%PDF-1.4\n\x00\xFF binaire");
        file_put_contents($second, 'contenu accentué');

        $adapte = json_decode(RelaisDuProtocoleDePlaywright::adapter(json_encode([
            'id' => 'c1',
            'guid' => 'frame@1',
            'method' => 'setInputFiles',
            'params' => ['timeout' => 15000, 'selector' => 'input[type=file]', 'strict' => true, 'localPaths' => [$premier, $second]],
            'metadata' => [],
        ], JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'id' => 'c1',
            'guid' => 'frame@1',
            'method' => 'setInputFiles',
            'params' => [
                'timeout' => 15000,
                'selector' => 'input[type=file]',
                'strict' => true,
                'payloads' => [
                    ['name' => 'facture 1.pdf', 'buffer' => base64_encode("%PDF-1.4\n\x00\xFF binaire")],
                    ['name' => 'pièce.txt', 'buffer' => base64_encode('contenu accentué')],
                ],
            ],
            'metadata' => ['timeout' => 15000],
        ], $adapte);
    }

    public function test_un_fichier_qui_n_existe_pas_est_refuse_en_le_nommant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->dossier.'/absent.pdf n\'existe pas ou ne se lit pas');

        RelaisDuProtocoleDePlaywright::adapter('{"id":"d1","guid":"frame@1","method":"setInputFiles","params":{"timeout":1,"localPaths":["'.$this->dossier.'/absent.pdf"]},"metadata":[]}');
    }

    public function test_le_relais_envoie_l_appel_adapte_et_laisse_passer_le_reste(): void
    {
        $connexion = $this->createMock(WebsocketConnection::class);
        $connexion->expects($this->once())->method('sendText')->with('{"id":"e1","guid":"frame@1","method":"click","params":{"timeout":15000},"metadata":{"timeout":15000}}');
        $connexion->expects($this->once())->method('sendBinary')->with('binaire');
        $connexion->expects($this->once())->method('isClosed')->willReturn(true);

        $relais = new RelaisDuProtocoleDePlaywright($connexion);
        $relais->sendText('{"id":"e1","guid":"frame@1","method":"click","params":{"timeout":15000},"metadata":[]}');
        $relais->sendBinary('binaire');

        $this->assertTrue($relais->isClosed());
    }
}
