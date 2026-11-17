<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Http\Controllers\Demonstration\EntrerDansLaDemonstrationController;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Tests\DemonstrationTestCase;

/**
 * L'entrée dans une copie et l'arrivée de chaque requête à sa copie (lot
 * DEMO-COPIE, C.4, C.5 et C.6 ; N21, et la part de N2 et de N29 qui en
 * dépend) : le lien pose un témoin HttpOnly, SameSite=Lax, sur /, sans
 * domaine, qui ne dure pas plus que la copie ; un lien faux, ancien ou
 * d'une copie supprimée répond la même chose ; sans témoin, rien ne répond
 * hors des adresses d'entrée ; deux témoins de deux copies ne voient
 * chacun que les données de la leur.
 */
class EntreeParLeLienTest extends DemonstrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/essai-de-la-copie', fn (): array => Magasin::query()->withoutGlobalScopes()->orderBy('nom')->pluck('nom')->all());
    }

    public function test_la_page_du_lien_porte_sa_politique_de_contenu_stricte_et_charge_un_fichier_du_site(): void
    {
        $page = $this->get('/d');

        $page->assertOk();
        $page->assertHeader('Content-Security-Policy', EntrerDansLaDemonstrationController::POLITIQUE_DE_CONTENU);
        $this->assertStringNotContainsString('unsafe-inline', EntrerDansLaDemonstrationController::POLITIQUE_DE_CONTENU);
        $this->assertStringNotContainsString('unsafe-eval', EntrerDansLaDemonstrationController::POLITIQUE_DE_CONTENU);
        $page->assertSee('/js/demonstration/entrer-dans-la-demonstration.js', false);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $page->getContent(), 'Aucun script écrit dans la page.');
        $this->assertDoesNotMatchRegularExpression('/<style\b|\sstyle\s*=/i', view('demonstration.entrer')->render(), 'Aucun style écrit dans la page, pied compris : style-src \'self\' le bloquerait.');
        $page->assertSee('/css/app/pied-du-site.css', false);
        $this->assertFileExists(public_path('css/app/pied-du-site.css'));
        $page->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_le_lien_pose_un_temoin_de_la_copie_puis_mene_au_choix_du_compte(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest();

        $reponse = $this->withHeaders(['Origin' => rtrim((string) config('app.url'), '/')])->postJson('/entrer', ['secret' => $secret]);

        $reponse->assertOk()->assertExactJson(['aller' => '/choisir']);
        $temoin = $reponse->getCookie(TemoinsDesVisites::nom(), false);
        $this->assertNotNull($temoin);
        $this->assertTrue($temoin->isHttpOnly());
        $this->assertSame('lax', $temoin->getSameSite());
        $this->assertSame('/', $temoin->getPath());
        $this->assertNull($temoin->getDomain());
        $this->assertSame($copie->fin->getTimestamp(), $temoin->getExpiresTime(), 'Le témoin ne dure pas plus que la copie.');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $temoin->getValue());
        $this->assertSame(hash('sha256', (string) $temoin->getValue()), Temoin::on('demo_admin')->sole()->empreinte, 'Le registre ne garde que l\'empreinte du témoin.');
    }

    public function test_un_lien_faux_ancien_ou_d_une_copie_supprimee_repond_la_meme_chose(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest();
        $origine = ['Origin' => rtrim((string) config('app.url'), '/')];
        $faux = $this->withHeaders($origine)->postJson('/entrer', ['secret' => MessagesSignes::enBase64Url(random_bytes(32))]);

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'renouveler_le_lien', 'reference' => $copie->reference, 'empreinte_du_lien' => hash('sha256', 'nouveau'), 'generation' => 2]))->assertOk();
        $ancien = $this->withHeaders($origine)->postJson('/entrer', ['secret' => $secret]);
        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::Supprimee->value, 'supprimee_le' => now()]);
        $supprime = $this->withHeaders($origine)->postJson('/entrer', ['secret' => $secret]);

        foreach ([$faux, $ancien, $supprime] as $reponse) {
            $reponse->assertNotFound()->assertExactJson(['message' => 'Introuvable.']);
            $this->assertNull($reponse->getCookie(TemoinsDesVisites::nom(), false));
        }
    }

    public function test_un_lien_faux_attend_la_duree_reglee_et_seul_un_lien_valable_repond_sans_attendre(): void
    {
        ['secret' => $secret] = $this->copieDeTest();
        $origine = ['Origin' => rtrim((string) config('app.url'), '/')];
        config(['auth.timebox_duration' => 1_500_000]);
        Sleep::fake();

        $this->withHeaders($origine)->postJson('/entrer', ['secret' => MessagesSignes::enBase64Url(random_bytes(32))])->assertNotFound();
        $this->withHeaders($origine)->postJson('/entrer', ['secret' => 'mal formé'])->assertNotFound();

        Sleep::assertSleptTimes(2);
        Sleep::assertSlept(fn (CarbonInterval $duree): bool => $duree->totalMicroseconds > 1_000_000 && $duree->totalMicroseconds <= 1_500_000, 2);

        $this->withHeaders($origine)->postJson('/entrer', ['secret' => $secret])->assertOk();

        Sleep::assertSleptTimes(2);
    }

    public function test_l_entree_exige_l_origine_du_site_et_se_limite_a_dix_essais_par_minute(): void
    {
        ['secret' => $secret] = $this->copieDeTest();

        $this->postJson('/entrer', ['secret' => $secret])->assertNotFound();
        $this->withHeaders(['Origin' => 'https://piliqi.test'])->postJson('/entrer', ['secret' => $secret])->assertNotFound();

        $origine = ['Origin' => rtrim((string) config('app.url'), '/')];

        for ($essai = 1; $essai <= EntrerDansLaDemonstrationController::ESSAIS_PAR_MINUTE; $essai++) {
            $this->withHeaders($origine)->postJson('/entrer', ['secret' => 'faux'])->assertNotFound();
        }

        $this->withHeaders($origine)->postJson('/entrer', ['secret' => $secret])
            ->assertTooManyRequests()->assertExactJson(['message' => EntrerDansLaDemonstrationController::TROP_D_ESSAIS]);
        $this->assertSame(0, Temoin::on('demo_admin')->count(), 'Aucune copie ouverte au-delà de la limite.');
    }

    public function test_un_temoin_valable_d_une_autre_copie_fait_d_abord_demander_laquelle_ouvrir(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        ['secret' => $premier] = $this->copieDeTest(TypeDActivite::Commerce, str_repeat('a', 32));
        ['secret' => $second] = $this->copieDeTest(TypeDActivite::Commerce, str_repeat('b', 32));
        $temoin = $this->entrerParLeLien($premier);
        $origine = ['Origin' => rtrim((string) config('app.url'), '/')];

        $this->withCredentials()->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->withHeaders($origine)->postJson('/entrer', ['secret' => $second])
            ->assertConflict()
            ->assertExactJson(['question' => 'Vous êtes déjà dans une autre démonstration (repère K7M2QD, créée le 16/11/2026). Ouvrir celle de ce lien à la place ?']);
        $this->withCredentials()->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->withHeaders($origine)->postJson('/entrer', ['secret' => $second, 'confirme' => true])
            ->assertOk();
    }

    public function test_sans_temoin_rien_ne_repond_hors_des_adresses_d_entree(): void
    {
        foreach (['/', '/app', '/app/login', '/societe', '/cabinet', '/api/v1/factures', '/essai-de-la-copie', '/choisir'] as $adresse) {
            $this->get($adresse)->assertNotFound();
        }

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), MessagesSignes::enBase64Url(random_bytes(32)))->get('/essai-de-la-copie')->assertNotFound();
    }

    public function test_deux_temoins_de_deux_copies_ne_voient_chacun_que_les_donnees_de_la_leur(): void
    {
        ['secret' => $commerce] = $this->copieDeTest(TypeDActivite::Commerce);
        ['secret' => $services] = $this->copieDeTest(TypeDActivite::Services);
        $temoinDuCommerce = $this->entrerParLeLien($commerce);
        $temoinDesServices = $this->entrerParLeLien($services);

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinDuCommerce)->get('/essai-de-la-copie')->assertExactJson(['La République', 'Les Halles']);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinDesServices)->get('/essai-de-la-copie')->assertExactJson(['Conseil Vasseur']);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinDuCommerce)->get('/essai-de-la-copie')->assertExactJson(['La République', 'Les Halles']);
    }

    public function test_une_copie_qui_ne_se_sert_pas_montre_sa_page_d_attente_et_une_copie_finie_dit_qu_elle_est_terminee(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest();
        $temoin = $this->entrerParLeLien($secret);

        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::EnMigration->value]);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/essai-de-la-copie')
            ->assertStatus(503)->assertSee('Mise à jour en cours, une minute')->assertSee('http-equiv="refresh"', false);
        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::Prete->value, 'empreinte_du_schema' => str_repeat('0', 64)]);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/essai-de-la-copie')->assertStatus(503)->assertSee('Mise à jour en cours, une minute');

        $this->travelTo($copie->fin);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/essai-de-la-copie')
            ->assertStatus(410)->assertSee('Cette démonstration est terminée')->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_un_nom_de_base_hors_du_motif_arrete_la_requete_en_francais_et_parait_dans_l_etat_publie(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest();
        $temoin = $this->entrerParLeLien($secret);
        $registre = DB::connection('demo_admin');
        $nom = (string) $copie->nom_de_la_base;
        $registre->statement('alter table copies drop constraint copies_nom_du_motif');

        try {
            $registre->table('copies')->where('id', $copie->id)->update(['nom_de_la_base' => 'piliqi']);

            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/essai-de-la-copie')
                ->assertStatus(503)
                ->assertSee('Votre démonstration est indisponible pour le moment')
                ->assertDontSee('http-equiv="refresh"', false);
        } finally {
            $registre->table('copies')->where('id', $copie->id)->update(['nom_de_la_base' => $nom]);
            $registre->statement("alter table copies add constraint copies_nom_du_motif check (nom_de_la_base is null or nom_de_la_base ~ '^demo_copie_[a-z0-9]{26}\$')");
        }

        $this->assertContains(
            '1 requête(s) arrêtée(s) depuis un jour : le nom de base d\'une copie au registre n\'est pas du motif d\'une copie.',
            app(EtatDuSite::class)->construire()['problemes'],
        );
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/essai-de-la-copie')->assertOk();
    }

    public function test_le_billet_du_bouton_ouvre_la_copie_une_seule_fois_et_depuis_la_seule_page_du_site_reel(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        ['copie' => $copie] = $this->copieDeTest();
        $billet = $this->messageSigne(['aud' => MessagesSignes::AUDIENCE_DE_L_ENTREE, 'quoi' => MessagesSignes::BILLET_D_ENTREE, 'reference' => $copie->reference]);
        $duSiteReel = ['Origin' => 'https://piliqi.test'];

        $this->withHeaders(['Origin' => 'https://autre-site.test'])->post('/entrer-par-le-compte', $billet)->assertNotFound();
        $entree = $this->withHeaders($duSiteReel)->post('/entrer-par-le-compte', $billet);
        $entree->assertRedirect('/choisir')->assertStatus(303);
        $this->assertNotNull($entree->getCookie(TemoinsDesVisites::nom(), false));
        $this->assertSame('bouton', Temoin::on('demo_admin')->sole()->entree);
        $this->withHeaders($duSiteReel)->post('/entrer-par-le-compte', $billet)->assertNotFound();

        $creer = $this->messageSigne(['quoi' => 'creer', 'reference' => $copie->reference]);
        $this->withHeaders($duSiteReel)->post('/entrer-par-le-compte', $creer)->assertNotFound();
        $this->withHeaders($duSiteReel)->post('/entrer-par-le-compte', $this->messageSigne(['aud' => MessagesSignes::AUDIENCE_DE_L_ENTREE, 'quoi' => MessagesSignes::BILLET_D_ENTREE, 'reference' => $copie->reference, 'emis_le' => '2026-11-16T08:58:59Z']))
            ->assertNotFound();
        $this->assertSame(1, Temoin::on('demo_admin')->count());
    }

    public function test_les_pages_fermees_menent_au_choix_du_compte_avec_le_temoin_et_aucun_temoin_xsrf_n_est_pose(): void
    {
        ['secret' => $secret] = $this->copieDeTest();
        $temoin = $this->entrerParLeLien($secret);

        $fermees = ['/app/login', '/societe/login', '/cabinet/login', '/app/password-reset/request', '/app/register', '/code', '/inscription/'.str_repeat('a', 64)];

        foreach ($fermees as $adresse) {
            $this->get($adresse)->assertNotFound();
        }

        foreach ($fermees as $adresse) {
            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get($adresse)->assertRedirect('/choisir');
        }

        Route::middleware('web')->get('/essai-de-la-session', fn (): string => 'ouvert');
        $page = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/essai-de-la-session');
        $page->assertOk();
        $this->assertNull($page->getCookie('XSRF-TOKEN', false), 'Aucun témoin XSRF-TOKEN sur le site de démonstration.');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false);
    }
}
