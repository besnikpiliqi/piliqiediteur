<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Support\TemoinsEnTableau;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

/**
 * Un témoin que le navigateur envoie sous un nom en tableau
 * (« postes_reconnus[0]=… ») cache à PHP le témoin ordinaire de ce nom (voir
 * TemoinsEnTableau) : la réponse qui pose le témoin d'un poste reconnu ou
 * celui des postes connus efface chacun d'eux, sous le nom que le
 * navigateur envoie, que sa valeur se déchiffre ou non, pour que son
 * nouveau témoin soit lu à la requête suivante.
 */
class TemoinsEnTableauTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque cas part d'un instant figé : un mardi, à 10 h à Paris.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
    }

    /**
     * PHP décide du rangement de chaque nom : en tableau sous ce nom, il
     * s'efface (crochets vides, texte après le crochet, point ou espace
     * avant le nom, sans valeur) ; le témoin ordinaire de ce nom, un autre
     * nom, et un nom qui ne se renverrait pas tel quel (une virgule, une
     * espace au milieu) ne s'effacent pas.
     */
    public function test_chaque_temoin_que_php_range_en_tableau_sous_ce_nom_s_efface_sous_le_nom_envoye(): void
    {
        $requete = Request::create('/', server: ['HTTP_COOKIE' => 'postes_reconnus[0]=a; postes_reconnus[]=b;  postes.reconnus[x][y]=c; postes_reconnus[1]z=d; postes_reconnus=e; postes_reconnus_bis[0]=f; autre=g; postes reconnus[2]=h; postes_reconnus[3,4]=i; postes_reconnus[5]']);

        TemoinsEnTableau::effacer($requete, PostesReconnus::TEMOIN);

        $this->assertSame([
            'postes_reconnus[0]' => true,
            'postes_reconnus[]' => true,
            'postes.reconnus[x][y]' => true,
            'postes_reconnus[1]z' => true,
            'postes_reconnus[5]' => true,
        ], $this->temoinsEnFile());
    }

    public function test_une_requete_sans_temoin_en_tableau_n_efface_rien(): void
    {
        TemoinsEnTableau::effacer(Request::create('/', server: ['HTTP_COOKIE' => 'postes_reconnus=a; autre[0]=b']), PostesReconnus::TEMOIN);
        TemoinsEnTableau::effacer(Request::create('/'), PostesReconnus::TEMOIN);

        $this->assertSame([], $this->temoinsEnFile());
    }

    public function test_reconnaitre_ce_navigateur_efface_le_temoin_des_postes_reconnus_envoye_en_tableau_puis_pose_le_sien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $responsable = $this->responsable($magasin);
        $requete = Request::create('/', cookies: [PostesReconnus::TEMOIN => null], server: ['HTTP_COOKIE' => 'postes_reconnus[0]=indechiffrable; autre=1']);
        Cookie::flushQueuedCookies();

        app(PostesReconnus::class)->reconnaitre($responsable, $magasin, 'Caisse 1', $requete);

        $this->assertSame(['postes_reconnus[0]' => true, PostesReconnus::TEMOIN => false], $this->temoinsEnFile());
    }

    public function test_retenir_un_poste_connu_efface_le_temoin_des_postes_connus_envoye_en_tableau_puis_pose_le_sien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $requete = Request::create('/', cookies: [PostesConnus::TEMOIN => null], server: ['HTTP_COOKIE' => 'postes_connus[0]=indechiffrable']);
        Cookie::flushQueuedCookies();

        app(PostesConnus::class)->retenir($requete, $vendeur);

        $this->assertSame(['postes_connus[0]' => true, PostesConnus::TEMOIN => false], $this->temoinsEnFile());
    }

    /**
     * Les témoins mis en file pour la réponse, dans leur ordre : par nom,
     * s'il s'efface (échu avant l'instant figé du test).
     *
     * @return array<string, bool>
     */
    private function temoinsEnFile(): array
    {
        $temoins = [];

        foreach (Cookie::getQueuedCookies() as $temoin) {
            $temoins[$temoin->getName()] = $temoin->getExpiresTime() !== 0 && $temoin->getExpiresTime() < self::aParis('2026-03-10 10:00:00')->getTimestamp();
        }

        return $temoins;
    }
}
