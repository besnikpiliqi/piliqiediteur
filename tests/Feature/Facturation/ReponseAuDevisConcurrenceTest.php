<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux gestes au même instant sur un même devis, chacun dans son
 * processus, sur une vraie base PostgreSQL (lot DV) : deux réponses du
 * client par le même lien n'en enregistrent qu'une, et deux
 * transformations en facture n'en créent qu'une. La seconde attend que la
 * première ait rendu le verrou du devis, puis la trouve faite.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ReponseAuDevisConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    #[Group('concurrence')]
    public function test_deux_reponses_simultanees_par_le_meme_lien_n_en_enregistrent_qu_une(): void
    {
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        Mail::fake();
        Notification::fake();
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $devis = $this->devisEnvoye($magasin);
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::responsableDeTest($magasin), $devis, 'client@exemple.fr', null, (string) Str::uuid());
        $jeton = null;
        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($envoi, &$jeton): bool {
            $jeton = $mail->envoi->is($envoi) && preg_match('#/devis/([A-Za-z0-9]{64})"#', $mail->render(), $trouve) === 1 ? $trouve[1] : $jeton;

            return $jeton !== null;
        });
        $refuser = static function () use ($jeton): void {
            app(RepondreAuDevisEnLigneAction::class)->refuser((string) $jeton, 'Trop tard.');
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('devis', 'for update', $refuser, $concurrente);
        app(TenantContext::class)->clear();

        app(RepondreAuDevisEnLigneAction::class)->accepter((string) $jeton, 'Claire Martin', true);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->failed(), $resultat->output());
        $this->assertStringContainsString(LienDuDevis::PLUS_VALABLE, $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([ReponseAuDevis::Acceptation, 'Claire Martin'], [ReponseDuDevis::query()->sole()->reponse, ReponseDuDevis::query()->sole()->signataire]);
        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
    }

    #[Group('concurrence')]
    public function test_deux_transformations_simultanees_ne_creent_qu_une_facture(): void
    {
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $devis = app(AccepterDevisAction::class)->handle(self::auteur(), $this->devisEnvoye($magasin));
        [$magasinId, $devisId] = [$magasin->id, $devis->id];
        $transformer = static function () use ($magasinId, $devisId): void {
            self::transformer($magasinId, $devisId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('devis', 'for update', $transformer, $concurrente);

        self::transformer($magasinId, $devisId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->failed(), $resultat->output());
        $this->assertStringContainsString(CreerFactureDepuisDevisAction::REFUS_DEJA_TRANSFORME, $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([1, StatutDevis::Converti], [Facture::query()->count(), $devis->fresh()->statut]);
    }

    private static function transformer(int $magasinId, int $devisId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(CreerFactureDepuisDevisAction::class)->handle(self::responsableDeTest($magasin), Devis::query()->findOrFail($devisId));
    }

    private function devisEnvoye(Magasin $magasin): Devis
    {
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
    }
}
