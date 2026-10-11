<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\InseeSirene;
use App\Integrations\Sirene\SireneIndisponibleException;
use App\Integrations\Sirene\UniteSirene;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * L'API Sirene de l'INSEE (voir InseeSirene), sur des réponses simulées
 * (Http::fake) : aucune requête ne part. Les numéros sont fictifs.
 */
class InseeSireneTest extends TestCase
{
    private const URL = 'https://api.insee.fr/api-sirene/3.11';

    private const CLE = 'cle-d-integration-des-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.sirene.url' => self::URL, 'services.sirene.cle' => self::CLE]);
    }

    /**
     * L'unité et l'établissement se lisent dans leur période en cours, la
     * première, avec la clé d'intégration de l'éditeur dans son en-tête.
     */
    public function test_l_unite_et_l_etablissement_se_lisent_avec_la_cle_de_l_editeur(): void
    {
        Http::fake([
            self::URL.'/siren/000000018' => Http::response(['uniteLegale' => [
                'siren' => '000000018',
                'statutDiffusionUniteLegale' => 'O',
                'periodesUniteLegale' => [
                    ['etatAdministratifUniteLegale' => 'A', 'denominationUniteLegale' => 'SOCIETE FICTIVE', 'categorieJuridiqueUniteLegale' => '5710', 'nicSiegeUniteLegale' => '00012'],
                    ['etatAdministratifUniteLegale' => 'C', 'denominationUniteLegale' => 'ANCIEN NOM'],
                ],
            ]]),
            self::URL.'/siret/00000001800012' => Http::response(['etablissement' => [
                'siren' => '000000018',
                'statutDiffusionEtablissement' => 'O',
                'adresseEtablissement' => [
                    'numeroVoieEtablissement' => '7',
                    'indiceRepetitionEtablissement' => 'B',
                    'typeVoieEtablissement' => 'RUE',
                    'libelleVoieEtablissement' => 'DES LILAS',
                    'complementAdresseEtablissement' => 'BATIMENT C',
                    'codePostalEtablissement' => '69003',
                    'libelleCommuneEtablissement' => 'LYON',
                ],
                'periodesEtablissement' => [['etatAdministratifEtablissement' => 'F'], ['etatAdministratifEtablissement' => 'A']],
            ]]),
        ]);
        $repertoire = new InseeSirene;

        $this->assertEquals(new UniteSirene('000000018', true, 'SOCIETE FICTIVE', '5710', '00012', false), $repertoire->unite('000000018'));
        $this->assertEquals(new EtablissementSirene('00000001800012', '000000018', false, '7 B RUE DES LILAS', 'BATIMENT C', '69003', 'LYON', false), $repertoire->etablissement('00000001800012'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL.'/siren/000000018' && $requete->hasHeader('X-INSEE-Api-Key-Integration', self::CLE));
    }

    /**
     * Un entrepreneur individuel se nomme par son prénom usuel et son nom ;
     * une entreprise en diffusion partielle ne livre ni son nom ni son
     * adresse ([ND]), seulement sa commune.
     */
    public function test_l_entrepreneur_individuel_et_la_diffusion_partielle_se_lisent(): void
    {
        Http::fake([
            self::URL.'/siren/000000026' => Http::response(['uniteLegale' => [
                'prenomUsuelUniteLegale' => 'JEANNE',
                'periodesUniteLegale' => [['etatAdministratifUniteLegale' => 'A', 'nomUniteLegale' => 'DUPONT', 'categorieJuridiqueUniteLegale' => '1000', 'nicSiegeUniteLegale' => '00015']],
            ]]),
            self::URL.'/siren/000000034' => Http::response(['uniteLegale' => [
                'statutDiffusionUniteLegale' => 'P',
                'periodesUniteLegale' => [['etatAdministratifUniteLegale' => 'A', 'denominationUniteLegale' => '[ND]', 'categorieJuridiqueUniteLegale' => '5710', 'nicSiegeUniteLegale' => '00010']],
            ]]),
            self::URL.'/siret/00000003400010' => Http::response(['etablissement' => [
                'statutDiffusionEtablissement' => 'P',
                'adresseEtablissement' => ['libelleVoieEtablissement' => '[ND]', 'codePostalEtablissement' => '[ND]', 'libelleCommuneEtablissement' => 'NANTES'],
                'periodesEtablissement' => [['etatAdministratifEtablissement' => 'A']],
            ]]),
        ]);
        $repertoire = new InseeSirene;

        $this->assertEquals(new UniteSirene('000000026', true, 'JEANNE DUPONT', '1000', '00015', false), $repertoire->unite('000000026'));
        $this->assertEquals(new UniteSirene('000000034', true, null, '5710', '00010', true), $repertoire->unite('000000034'));
        $this->assertEquals(new EtablissementSirene('00000003400010', '000000034', true, null, null, null, 'NANTES', true), $repertoire->etablissement('00000003400010'));
    }

    public function test_un_numero_inconnu_du_repertoire_ne_rend_rien(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['header' => ['statut' => 404]], 404)]);
        $repertoire = new InseeSirene;

        $this->assertSame([null, null], [$repertoire->unite('000000018'), $repertoire->etablissement('00000001800012')]);
    }

    /**
     * Une erreur, une réponse illisible, un service injoignable (réessayé
     * une fois, une demi-seconde plus tard) ou une clé absente rendent le
     * répertoire indisponible ; jamais une entreprise active.
     */
    public function test_une_erreur_une_reponse_illisible_ou_une_cle_absente_rendent_le_repertoire_indisponible(): void
    {
        Sleep::fake();
        Http::fake([
            self::URL.'/siren/000000018' => Http::response('Erreur interne', 500),
            self::URL.'/siren/000000026' => Http::response(['uniteLegale' => ['periodesUniteLegale' => 'illisible']]),
            self::URL.'/siret/00000001800012' => Http::response(['autre' => true]),
            self::URL.'/siren/000000034' => Http::failedConnection(),
        ]);
        $repertoire = new InseeSirene;

        $this->assertThrows(fn () => $repertoire->unite('000000018'), SireneIndisponibleException::class);
        $this->assertThrows(fn () => $repertoire->unite('000000026'), SireneIndisponibleException::class);
        $this->assertThrows(fn () => $repertoire->etablissement('00000001800012'), SireneIndisponibleException::class);
        $this->assertThrows(fn () => $repertoire->unite('000000034'), SireneIndisponibleException::class);
        Sleep::assertSequence([Sleep::for(500)->milliseconds()]);

        config(['services.sirene.cle' => null]);
        $this->assertThrows(fn () => $repertoire->unite('000000042'), SireneIndisponibleException::class);
        Http::assertNotSent(fn (Request $requete): bool => str_ends_with($requete->url(), '/siren/000000042'));
    }

    /**
     * Au-delà de 30 requêtes par minute, le répertoire est tenu pour
     * indisponible sans être appelé.
     */
    public function test_au_dela_de_trente_requetes_par_minute_le_repertoire_n_est_plus_appele(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['header' => ['statut' => 404]], 404)]);
        $repertoire = new InseeSirene;

        foreach (range(1, InseeSirene::REQUETES_PAR_MINUTE) as $requete) {
            $repertoire->unite('000000018');
        }

        $this->assertThrows(fn () => $repertoire->unite('000000018'), SireneIndisponibleException::class);
        Http::assertSentCount(InseeSirene::REQUETES_PAR_MINUTE);
    }
}
