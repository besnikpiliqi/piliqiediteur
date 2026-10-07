<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrouverOuCreerServiceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_un_nouveau_nom_cree_un_service_actif_au_nom_nettoye(): void
    {
        $service = app(TrouverOuCreerServiceAction::class)->handle("  Remplacement \t  écran ");

        $this->assertModelExists($service);
        $this->assertSame('Remplacement écran', $service->nom);
        $this->assertTrue($service->actif);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function autresEcrituresDuMemeNom(): array
    {
        return [
            'minuscules' => ["main d'œuvre"],
            'majuscules' => ["MAIN D'ŒUVRE"],
            'espaces en trop' => ["  Main   d'œuvre "],
            'apostrophe typographique' => ['Main d’œuvre'],
            'oe tapé sur un clavier de PC' => ["Main d'oeuvre"],
            'majuscules sans accent ni ligature' => ["MAIN D'OEUVRE"],
            'trait d’union' => ["Main-d'œuvre"],
        ];
    }

    #[DataProvider('autresEcrituresDuMemeNom')]
    public function test_une_autre_ecriture_d_un_service_existant_le_reprend_sans_doublon(string $saisie): void
    {
        $existant = Service::factory()->create(['nom' => 'Main d\'œuvre']);

        $service = app(TrouverOuCreerServiceAction::class)->handle($saisie);

        $this->assertTrue($service->is($existant));
        $this->assertSame('Main d\'œuvre', $service->nom);
        $this->assertSame(1, Service::query()->count());
    }

    public function test_un_nom_sans_accent_reprend_le_service_accentue(): void
    {
        $existant = Service::factory()->create(['nom' => 'Remplacement écran']);

        $service = app(TrouverOuCreerServiceAction::class)->handle('REMPLACEMENT ECRAN');

        $this->assertTrue($service->is($existant));
        $this->assertSame(1, Service::query()->count());
    }

    public function test_deux_noms_faits_seulement_d_emojis_differents_restent_deux_services(): void
    {
        $telephone = app(TrouverOuCreerServiceAction::class)->handle('📱');

        $deuxTelephones = app(TrouverOuCreerServiceAction::class)->handle('📱📱');

        $this->assertFalse($deuxTelephones->is($telephone));
        $this->assertSame(2, Service::query()->count());
    }

    /**
     * Deux caisses du même magasin créent le même service au même instant :
     * l'autre caisse a inséré le sien entre la recherche et la création.
     */
    public function test_un_service_cree_entre_temps_par_une_autre_caisse_est_repris_et_reactive(): void
    {
        $magasinId = app(TenantContext::class)->id();
        $insere = false;
        Service::creating(function () use (&$insere, $magasinId): void {
            if (! $insere) {
                $insere = true;
                DB::table('services')->insert([
                    'magasin_id' => $magasinId,
                    'nom' => 'Diagnostic',
                    'nom_normalise' => 'diagnostic',
                    'actif' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $service = app(TrouverOuCreerServiceAction::class)->handle('diagnostic');

        $this->assertTrue($insere);
        $this->assertSame('Diagnostic', $service->nom);
        $this->assertTrue($service->fresh()->actif);
        $this->assertSame(1, Service::query()->count());
    }

    public function test_retaper_un_service_archive_le_rend_de_nouveau_propose(): void
    {
        $archive = Service::factory()->archive()->create(['nom' => 'Désoxydation']);

        $service = app(TrouverOuCreerServiceAction::class)->handle('désoxydation');

        $this->assertTrue($service->is($archive));
        $this->assertTrue($archive->fresh()->actif);
    }

    public function test_le_meme_nom_dans_un_autre_magasin_est_un_autre_service(): void
    {
        $serviceAutreMagasin = Service::factory()->create(['nom' => 'Diagnostic']);
        app(TenantContext::class)->set(Magasin::factory()->create());

        $service = app(TrouverOuCreerServiceAction::class)->handle('Diagnostic');

        $this->assertFalse($service->is($serviceAutreMagasin));
        $this->assertNotSame($serviceAutreMagasin->magasin_id, $service->magasin_id);
    }

    public function test_un_nom_vide_est_refuse(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Le nom du service est obligatoire.'));

        try {
            app(TrouverOuCreerServiceAction::class)->handle("  \t ");
        } finally {
            $this->assertSame(0, Service::query()->count());
        }
    }

    public function test_un_nom_de_150_caracteres_accentues_est_accepte(): void
    {
        $service = app(TrouverOuCreerServiceAction::class)->handle(str_repeat('é', 150));

        $this->assertSame(150, mb_strlen($service->fresh()->nom));
    }

    public function test_un_nom_de_plus_de_150_caracteres_est_refuse(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Le nom du service ne doit pas dépasser 150 caractères.'));

        try {
            app(TrouverOuCreerServiceAction::class)->handle(str_repeat('é', 151));
        } finally {
            $this->assertSame(0, Service::query()->count());
        }
    }
}
