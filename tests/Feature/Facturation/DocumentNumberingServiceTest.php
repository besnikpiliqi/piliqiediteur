<?php

namespace Tests\Feature\Facturation;

use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentNumberingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_numerotation_est_sequentielle_pour_un_meme_magasin_et_type(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        $service = app(DocumentNumberingService::class);

        $numero1 = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $numero2 = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $numero3 = $service->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertSame('FAC-LYON1-2026-000001', $numero1);
        $this->assertSame('FAC-LYON1-2026-000002', $numero2);
        $this->assertSame('FAC-LYON1-2026-000003', $numero3);
    }

    /**
     * @return array<string, array{TypeDocument, string}>
     */
    public static function documentsNumerotes(): array
    {
        return [
            'devis' => [TypeDocument::Devis, 'DEV-LYON1-2026-000001'],
            'facture' => [TypeDocument::Facture, 'FAC-LYON1-2026-000001'],
            'ticket de caisse' => [TypeDocument::TicketCaisse, 'TK-LYON1-2026-000001'],
            'avoir' => [TypeDocument::Avoir, 'AV-LYON1-2026-000001'],
            'facture d\'achat' => [TypeDocument::FactureAchat, 'FA-LYON1-2026-000001'],
            'avoir fournisseur' => [TypeDocument::AvoirFournisseur, 'AF-LYON1-2026-000001'],
            'clôture de caisse' => [TypeDocument::ClotureCaisse, 'Z-LYON1-2026-000001'],
            'soumission d\'e-reporting' => [TypeDocument::SoumissionEReporting, 'ER-LYON1-2026-000001'],
        ];
    }

    /**
     * Chaque numéro de document porte le code de son type, le code du
     * magasin et l'année.
     */
    #[DataProvider('documentsNumerotes')]
    public function test_un_numero_de_document_porte_le_code_du_magasin_et_l_annee(TypeDocument $type, string $numeroAttendu): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);

        $numero = app(DocumentNumberingService::class)->suivant($magasin, $type, '2026');

        $this->assertSame($numeroAttendu, $numero);
    }

    /**
     * Une référence de produit n'est pas un numéro de document : ni code
     * de magasin ni année.
     */
    public function test_une_reference_de_produit_ne_porte_ni_code_de_magasin_ni_annee(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);

        $reference = app(DocumentNumberingService::class)->suivant($magasin, TypeDocument::Produit);

        $this->assertSame('PRD-000001', $reference);
    }

    /**
     * Deux magasins d'une même société ont chacun leurs séries, et leurs
     * codes distinguent leurs numéros : la plateforme, qui contrôle
     * l'unicité sur le SIREN, le numéro et l'année, n'en voit jamais deux
     * pareils.
     */
    public function test_deux_magasins_du_meme_siren_ont_chacun_leur_serie_et_des_numeros_distincts(): void
    {
        $lyon1 = Magasin::factory()->create(['code' => 'LYON1', 'siren' => '732829320', 'siret' => '73282932000074']);
        $lyon2 = Magasin::factory()->deLaSocieteDe($lyon1)->create(['code' => 'LYON2', 'siret' => '73282932000082']);
        $service = app(DocumentNumberingService::class);

        $numeroLyon1 = $service->suivant($lyon1, TypeDocument::Facture, '2026');
        $numeroLyon2 = $service->suivant($lyon2, TypeDocument::Facture, '2026');
        $secondNumeroLyon1 = $service->suivant($lyon1, TypeDocument::Facture, '2026');

        $this->assertSame('FAC-LYON1-2026-000001', $numeroLyon1);
        $this->assertSame('FAC-LYON2-2026-000001', $numeroLyon2);
        $this->assertSame('FAC-LYON1-2026-000002', $secondNumeroLyon1);
    }

    public function test_devis_et_facture_ont_des_sequences_independantes_dans_le_meme_magasin(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        $service = app(DocumentNumberingService::class);

        $numeroDevis = $service->suivant($magasin, TypeDocument::Devis, '2026');
        $numeroFacture = $service->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertSame('DEV-LYON1-2026-000001', $numeroDevis);
        $this->assertSame('FAC-LYON1-2026-000001', $numeroFacture);
    }

    public function test_ticket_caisse_et_facture_ont_des_sequences_independantes_dans_le_meme_magasin(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        $service = app(DocumentNumberingService::class);

        $numeroTicket = $service->suivant($magasin, TypeDocument::TicketCaisse, '2026');
        $numeroFacture = $service->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertSame('TK-LYON1-2026-000001', $numeroTicket);
        $this->assertSame('FAC-LYON1-2026-000001', $numeroFacture);
    }

    public function test_une_periode_differente_redemarre_la_sequence(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        $service = app(DocumentNumberingService::class);

        $numero2026 = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $numero2027 = $service->suivant($magasin, TypeDocument::Facture, '2027');

        $this->assertSame('FAC-LYON1-2026-000001', $numero2026);
        $this->assertSame('FAC-LYON1-2027-000001', $numero2027);
    }

    /**
     * La première série prend le code enregistré du magasin, même quand
     * l'objet qui la demande a été chargé avant un changement de code.
     */
    public function test_une_premiere_serie_prend_le_code_enregistre_du_magasin(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        Magasin::query()->findOrFail($magasin->id)->update(['code' => 'LYON9']);

        $numero = app(DocumentNumberingService::class)->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertSame('FAC-LYON9-2026-000001', $numero);
    }

    /**
     * Deux caisses du même magasin numérotent au même instant le premier
     * document d'une série : l'autre caisse a créé la série juste après la
     * requête qui ne l'a pas trouvée. La création se tente dans un point
     * de sauvegarde, que le refus de la clé unique annule seul : sous
     * PostgreSQL, la transaction reste utilisable et la numérotation
     * continue sur la série de l'autre caisse.
     */
    public function test_une_serie_creee_entre_temps_par_une_autre_caisse_est_reprise(): void
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        $creeeEntreTemps = false;
        DB::listen(function (QueryExecuted $requete) use (&$creeeEntreTemps, $magasin): void {
            if (! $creeeEntreTemps && str_starts_with($requete->sql, 'select') && str_contains($requete->sql, '"sequences_documents"')) {
                $creeeEntreTemps = true;
                DB::table('sequences_documents')->insert([
                    'magasin_id' => $magasin->id,
                    'type_document' => TypeDocument::Facture->value,
                    'period_key' => '2026',
                    'prefixe' => 'FAC-LYON1-2026-',
                    'prochain_numero' => 1,
                    'rembourrage' => 6,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
        $service = app(DocumentNumberingService::class);

        $premier = $service->suivant($magasin, TypeDocument::Facture, '2026');
        $second = $service->suivant($magasin, TypeDocument::Facture, '2026');

        $this->assertTrue($creeeEntreTemps);
        $this->assertSame(['FAC-LYON1-2026-000001', 'FAC-LYON1-2026-000002'], [$premier, $second]);
        $this->assertSame(1, SequenceDocument::query()->where('magasin_id', $magasin->id)->count());
    }
}
