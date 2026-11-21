<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Jobs\EnvoyerLeLienDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\MaDemonstration;
use App\Http\Middleware\AjouterLesEnTetesDeSecurite;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * N3 (lot DEMO-COPIE, B.1, B.3 et choix 1) : le lien a 256 bits, en
 * base64url (43 caractères) ; le site réel n'en garde que l'empreinte et
 * ne l'écrit nulle part : ni dans une colonne de la base (journal d'audit,
 * notifications, travaux, jetons des demandes répétées), ni dans
 * l'instantané du composant de la carte ; il ne revient que dans la
 * réponse du geste, sans cache ; le travail de son e-mail est chiffré, et
 * après son dernier échec aucune ligne ne reste dans failed_jobs et le
 * propriétaire est prévenu.
 */
class LienDeLaDemonstrationTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reglerLeSiteDeDemonstration();
    }

    public function test_le_lien_a_256_bits_en_base64url_et_la_base_ne_garde_que_son_empreinte(): void
    {
        Mail::fake();
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $jeton = (string) Str::uuid();

        $creee = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, $jeton, null);
        app(TenantContext::class)->clear();
        app(DemanderUneDemonstrationAction::class)->handle($proprietaire, $jeton, null);
        app(TenantContext::class)->clear();
        $renouvelee = app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', (string) Str::uuid());

        foreach ([(string) $creee->lien, (string) $renouvelee->lien] as $lien) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $lien);
            $this->assertSame(32, strlen((string) base64_decode(strtr($lien, '-_', '+/'), true)));
            $this->assertSame([], $this->colonnesQuiContiennent($lien), 'Le lien ne s\'écrit nulle part dans la base.');
        }

        $this->assertSame(Demonstration::empreinteDuLien((string) $renouvelee->lien), Demonstration::query()->withoutGlobalScopes()->value('empreinte_du_lien'));
    }

    public function test_la_carte_rend_le_lien_dans_la_seule_reponse_du_geste_sans_cache_et_jamais_dans_son_instantane(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $this->actingAs($proprietaire);

        $carte = Livewire::test(MaDemonstration::class, ['societe' => $magasin->societe])->callAction('creer');

        $evenement = collect($carte->effects['dispatches'] ?? [])->firstWhere('name', MaDemonstration::LIEN_TIRE);
        $adresse = (string) ($evenement['params']['lien'] ?? '');
        $this->assertMatchesRegularExpression('~^'.preg_quote(self::ORIGINE_DE_LA_DEMONSTRATION, '~').'/d#[A-Za-z0-9_-]{43}$~', $adresse);
        $lien = substr($adresse, -43);
        $this->assertTrue(request()->attributes->get(AjouterLesEnTetesDeSecurite::SANS_CACHE));
        $this->assertStringNotContainsString($lien, (string) json_encode($carte->snapshot));
        $suivante = $carte->call('$refresh');
        $this->assertStringNotContainsString($lien, (string) json_encode($suivante->snapshot));
        $this->assertSame([], $this->colonnesQuiContiennent($lien));

        $requete = Request::create('/');
        $requete->attributes->set(AjouterLesEnTetesDeSecurite::SANS_CACHE, true);
        $reponse = (new AjouterLesEnTetesDeSecurite)->handle($requete, fn (): Response => new Response('ok'));
        $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
    }

    public function test_le_travail_de_l_e_mail_est_chiffre_et_ne_laisse_aucune_ligne_apres_son_dernier_echec(): void
    {
        $this->assertContains(ShouldBeEncrypted::class, class_implements(EnvoyerLeLienDeLaDemonstration::class));
        config(['queue.default' => 'database']);
        $this->travelTo(self::aParis('2026-11-18 10:00:00'));
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        Event::listen(MessageSending::class, static function (): never {
            throw new RuntimeException('Le serveur d\'envoi refuse la connexion.');
        });

        $creee = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $this->assertSame([], $this->colonnesQuiContiennent((string) $creee->lien), 'La table jobs ne garde le lien que chiffré.');
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'demonstration')->count());

        foreach (['10:00:01', '10:00:40', '10:03:00'] as $heure) {
            $this->travelTo(self::aParis('2026-11-18 '.$heure));
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'demonstration', '--once' => true, '--sleep' => 0, '--tries' => 3]);
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'demonstration', '--once' => true, '--sleep' => 0, '--tries' => 3]);
        }

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%EnvoyerLeLienDeLaDemonstration%')->count());
        $avis = $proprietaire->notifications()->where('type', AvisDeLEquipe::class)->get()->map(fn ($notification): mixed => $notification->data['body'] ?? null)->all();
        $this->assertContains(EnvoyerLeLienDeLaDemonstration::MESSAGE_NON_PARTI, $avis);
    }

    /**
     * Chaque colonne texte de la base où ce texte se trouve, « table.colonne ».
     *
     * @return list<string>
     */
    private function colonnesQuiContiennent(string $texte): array
    {
        $colonnes = DB::select("select table_name, column_name from information_schema.columns where table_schema = 'public' and data_type in ('text', 'character varying', 'character', 'json', 'jsonb')");
        $trouvees = [];

        foreach ($colonnes as $colonne) {
            $table = (string) $colonne->table_name;
            $nom = (string) $colonne->column_name;

            if (DB::table($table)->whereRaw('position(? in "'.$nom.'"::text) > 0', [$texte])->exists()) {
                $trouvees[] = $table.'.'.$nom;
            }
        }

        return $trouvees;
    }
}
