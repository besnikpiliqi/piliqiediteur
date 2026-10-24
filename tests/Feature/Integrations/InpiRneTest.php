<?php

namespace Tests\Feature\Integrations;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\InpiRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * L'API du registre national des entreprises de l'INPI (voir InpiRne),
 * sur des réponses simulées (Http::fake) : aucune requête ne part. Les
 * numéros et les personnes sont fictifs.
 */
class InpiRneTest extends TestCase
{
    private const URL = 'https://registre-national-entreprises.inpi.fr/api';

    private const JETON = 'jeton-de-l-editeur-des-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.rne.url' => self::URL, 'services.rne.identifiant' => 'editeur@example.test', 'services.rne.mot_de_passe' => 'mot-de-passe-des-tests']);
    }

    /**
     * Les dirigeants d'une société, personnes physiques ou morales, se
     * lisent avec le jeton obtenu une seule fois par les identifiants de
     * l'éditeur ; seuls les rôles qui représentent l'entreprise en ont le
     * pouvoir.
     */
    public function test_les_dirigeants_et_le_siege_se_lisent_avec_le_jeton_de_l_editeur(): void
    {
        Http::fake([
            self::URL.'/sso/login' => Http::response(['token' => self::JETON]),
            self::URL.'/companies/000000018' => Http::response(self::formaliteDeSociete()),
        ]);
        $rne = new InpiRne;

        $this->assertEquals([
            new RepresentantRne('DUPONT', 'MARTIN', ['Jeanne', 'Marie'], '1980-01', 'Président', true, null, '2020-01-01'),
            new RepresentantRne('HOLDING MARTIN', null, [], null, 'Directeur général', true, '000000117', '2021-06-15'),
            new RepresentantRne('DURAND', null, ['Sophie'], '1970-02', 'Rôle 71', false, null, null),
        ], $rne->representants('000000018'));
        $this->assertEquals(new AdressePostale('SOCIETE FICTIVE', '12 RUE DES LILAS', 'BATIMENT B', '69003', 'LYON', AdressePostale::SOURCE_RNE), $rne->adresseDuSiege('000000018'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL.'/sso/login' && $requete['username'] === 'editeur@example.test' && $requete['password'] === 'mot-de-passe-des-tests');
        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL.'/companies/000000018' && $requete->hasHeader('Authorization', 'Bearer '.self::JETON));
    }

    public function test_l_entrepreneur_individuel_est_le_seul_dirigeant_de_son_entreprise(): void
    {
        Http::fake([
            self::URL.'/sso/login' => Http::response(['token' => self::JETON]),
            self::URL.'/companies/000000026' => Http::response(['formality' => ['content' => ['personnePhysique' => [
                'identite' => ['entrepreneur' => ['descriptionPersonne' => ['nom' => 'BERNARD', 'prenoms' => ['Luc'], 'dateDeNaissance' => '1990-03']]],
            ]]]]),
        ]);

        $this->assertEquals(
            [new RepresentantRne('BERNARD', null, ['Luc'], '1990-03', 'Entrepreneur individuel', true, null, null)],
            (new InpiRne)->representants('000000026'),
        );
    }

    public function test_un_siren_inconnu_du_registre_ne_rend_rien(): void
    {
        Http::fake([
            self::URL.'/sso/login' => Http::response(['token' => self::JETON]),
            self::URL.'/companies/*' => Http::response(['message' => 'Not found'], 404),
        ]);
        $rne = new InpiRne;

        $this->assertSame([[], null], [$rne->representants('000000018'), $rne->adresseDuSiege('000000018')]);
    }

    /**
     * Un jeton refusé, une réponse illisible, un service injoignable
     * (réessayé une fois) ou des identifiants absents rendent le registre
     * indisponible ; jamais une liste de dirigeants vide.
     */
    public function test_un_jeton_refuse_une_reponse_illisible_ou_des_identifiants_absents_rendent_le_registre_indisponible(): void
    {
        Sleep::fake();
        Http::fake([
            self::URL.'/sso/login' => Http::response(['token' => self::JETON]),
            self::URL.'/companies/000000018' => Http::response(['formality' => ['content' => ['personneMorale' => ['composition' => ['pouvoirs' => [['roleEntreprise' => '73']]]]]]]),
            self::URL.'/companies/000000026' => Http::response(['formality' => []]),
            self::URL.'/companies/000000034' => Http::response('Erreur interne', 500),
            self::URL.'/companies/000000042' => Http::failedConnection(),
        ]);
        $rne = new InpiRne;

        foreach (['000000018', '000000026', '000000034', '000000042'] as $siren) {
            $this->assertThrows(fn () => $rne->representants($siren), RneIndisponibleException::class);
        }
        Sleep::assertSequence([Sleep::for(500)->milliseconds()]);

        Http::fake([self::URL.'/sso/login' => Http::response(['message' => 'Invalid credentials'], 401)]);
        $this->assertThrows(fn () => (new InpiRne)->representants('000000018'), RneIndisponibleException::class);

        config(['services.rne.mot_de_passe' => null]);
        $this->assertThrows(fn () => (new InpiRne)->representants('000000059'), RneIndisponibleException::class);
        Http::assertNotSent(fn (Request $requete): bool => str_ends_with($requete->url(), '/companies/000000059'));
    }

    /**
     * La formalité d'une société : une présidente, une holding directrice
     * générale, une commissaire aux comptes (rôle 71) sans pouvoir de
     * représentation, et le siège.
     *
     * @return array<string, mixed>
     */
    private static function formaliteDeSociete(): array
    {
        return ['formality' => ['content' => ['personneMorale' => [
            'identite' => ['entreprise' => ['denomination' => 'SOCIETE FICTIVE']],
            'adresseEntreprise' => ['adresse' => ['numVoie' => '12', 'typeVoie' => 'RUE', 'voie' => 'DES LILAS', 'complementLocalisation' => 'BATIMENT B', 'codePostal' => '69003', 'commune' => 'LYON']],
            'composition' => ['pouvoirs' => [
                ['roleEntreprise' => '73', 'dateEffetRoleDeclarant' => '2020-01-01', 'individu' => ['descriptionPersonne' => ['nom' => 'DUPONT', 'nomUsage' => 'MARTIN', 'prenoms' => ['Jeanne', 'Marie'], 'dateDeNaissance' => '1980-01']]],
                ['roleEntreprise' => 53, 'dateEffetRoleDeclarant' => '2021-06-15', 'entreprise' => ['siren' => '000000117', 'denomination' => 'HOLDING MARTIN']],
                ['roleEntreprise' => '71', 'individu' => ['descriptionPersonne' => ['nom' => 'DURAND', 'prenoms' => ['Sophie'], 'dateDeNaissance' => '1970-02']]],
            ]],
        ]]]];
    }
}
