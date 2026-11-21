<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Etat\LectureDeLEtat;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La lecture de l'état publié du site de démonstration par le site réel
 * (lot DEMO-COPIE, C.19, N31) : signature contrôlée par la clé publique de
 * l'état, une alerte à l'éditeur par problème nouveau du jour, et un état
 * mal signé ou vieux de plus de 2 heures est lui-même une alerte.
 */
class LectureDeLEtatTest extends TestCase
{
    /**
     * @var array{cle_id: string, cle_privee: string, cle_publique: string}
     */
    private array $paire;

    private string $reponse = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->paire = MessagesSignes::genererUnePaire();
        config(['demonstration.url' => 'https://piliqi-demo.test', 'demonstration.etat.cle_publique' => $this->paire['cle_publique'], 'editeur.email' => 'editeur@exemple.test']);
        Mail::fake();
        Http::fake(['https://piliqi-demo.test/interne/etat' => fn () => Http::response($this->reponse)]);
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
    }

    public function test_chaque_probleme_nouveau_du_jour_devient_une_seule_alerte(): void
    {
        $this->publier(['La construction du modèle « services » a échoué : disque plein.'], '2026-11-16T08:30:00Z');

        $this->assertSame(['La construction du modèle « services » a échoué : disque plein.'], app(LectureDeLEtat::class)->lire());
        app(LectureDeLEtat::class)->lire();

        Mail::assertSent(AlerteEditeurMail::class, 1);
    }

    public function test_un_etat_mal_signe_ou_trop_vieux_est_une_alerte(): void
    {
        $this->publier([], '2026-11-16T08:30:00Z', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->assertSame(['L\'état du site de démonstration est mal signé ou illisible.'], app(LectureDeLEtat::class)->lire());

        $this->publier([], '2026-11-16T06:59:59Z');
        $this->assertSame(['L\'état du site de démonstration a plus de 2 heures : son planificateur ou ses pages ne tournent plus.'], app(LectureDeLEtat::class)->lire());

        $this->publier([], '2026-11-16T07:00:01Z');
        $this->assertSame([], app(LectureDeLEtat::class)->lire());
        Mail::assertSent(AlerteEditeurMail::class, 2);
    }

    /**
     * @param  list<string>  $problemes
     */
    private function publier(array $problemes, string $emisLe, ?string $clePrivee = null): void
    {
        $etat = MessagesSignes::canonique(['v' => 1, 'emis_le' => $emisLe, 'problemes' => $problemes]);
        $cle = $clePrivee ?? (string) base64_decode($this->paire['cle_privee'], true);
        $this->reponse = (string) json_encode(['etat' => $etat, 'signature' => MessagesSignes::enBase64Url(sodium_crypto_sign_detached($etat, $cle))]);
    }
}
