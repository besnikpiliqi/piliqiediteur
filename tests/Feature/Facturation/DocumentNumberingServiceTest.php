<?php

namespace Tests\Feature\Facturation;

use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_numerotation_est_sequentielle_pour_un_meme_magasin_et_type(): void
    {
        $magasin = Magasin::factory()->create();
        $service = app(DocumentNumberingService::class);

        $numero1 = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $numero2 = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $numero3 = $service->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertSame('FAC-2026-000001', $numero1);
        $this->assertSame('FAC-2026-000002', $numero2);
        $this->assertSame('FAC-2026-000003', $numero3);
    }

    public function test_deux_magasins_ont_des_sequences_independantes_et_peuvent_partager_le_meme_numero(): void
    {
        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $service = app(DocumentNumberingService::class);

        $numeroA1 = $service->suivant($magasinA, TypeDocument::Facture, '2026');
        $numeroB1 = $service->suivant($magasinB, TypeDocument::Facture, '2026');
        $numeroA2 = $service->suivant($magasinA, TypeDocument::Facture, '2026');

        $this->assertSame('FAC-2026-000001', $numeroA1);
        $this->assertSame('FAC-2026-000001', $numeroB1, 'Deux magasins peuvent avoir le même numéro sans collision.');
        $this->assertSame('FAC-2026-000002', $numeroA2);
    }

    public function test_devis_et_facture_ont_des_sequences_independantes_dans_le_meme_magasin(): void
    {
        $magasin = Magasin::factory()->create();
        $service = app(DocumentNumberingService::class);

        $numeroDevis = $service->suivant($magasin, TypeDocument::Devis, '2026');
        $numeroFacture = $service->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertSame('DEV-2026-000001', $numeroDevis);
        $this->assertSame('FAC-2026-000001', $numeroFacture);
    }

    public function test_une_periode_differente_redemarre_la_sequence(): void
    {
        $magasin = Magasin::factory()->create();
        $service = app(DocumentNumberingService::class);

        $numero2026 = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $numero2027 = $service->suivant($magasin, TypeDocument::Facture, '2027');

        $this->assertSame('FAC-2026-000001', $numero2026);
        $this->assertSame('FAC-2027-000001', $numero2027);
    }
}
