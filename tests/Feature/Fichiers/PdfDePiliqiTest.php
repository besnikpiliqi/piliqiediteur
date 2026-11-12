<?php

namespace Tests\Feature\Fichiers;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Fichiers\AnalyseurDePdf;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Les PDF que PILIQI produit lui-même (décision de l'éditeur du
 * 07/11/2026, lot FI) : dompdf n'y exécute ni JavaScript ni PHP, n'y
 * charge rien d'extérieur ni de local hors de ses polices ; un nom de
 * client ou une désignation qui contiendrait du code reste du texte ; et
 * l'analyseur des PDF reçus n'y trouve ni JavaScript ni action
 * automatique.
 */
class PdfDePiliqiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dompdf_n_execute_rien_et_ne_lit_que_ses_polices(): void
    {
        $options = config('dompdf.options');

        $this->assertSame([false, false, false], [$options['enable_javascript'], $options['enable_php'], $options['enable_remote']]);
        $this->assertSame([storage_path('fonts'), base_path('vendor/dompdf/dompdf/lib/fonts')], $options['chroot']);
        $this->assertSame(['data://', 'file://'], array_keys($options['allowed_protocols']));
    }

    /**
     * Un fichier que PILIQI produit lui-même (PDF d'un document, reçu,
     * export du journal des événements) garde son mode d'affichage, avec
     * son type exact, sans que le navigateur en devine un autre ni le
     * garde en cache ; seul un fichier reçu, ou qui en contient, part
     * toujours en pièce jointe, dans un bac à sable.
     */
    public function test_un_fichier_produit_part_avec_son_type_exact_sans_cache(): void
    {
        $this->assertSame(
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'],
            EnTetesDesFichiers::fichierProduit('application/pdf'),
        );
        $this->assertSame(
            ['Content-Type', 'Content-Disposition', 'X-Content-Type-Options', 'Content-Security-Policy', 'Cache-Control', 'Cross-Origin-Resource-Policy'],
            array_keys(EnTetesDesFichiers::fichierRecu('facture.pdf', 'application/pdf')),
        );
    }

    /**
     * Un nom de client et une désignation qui contiennent du code
     * s'impriment tels quels, comme du texte ; l'image et le fichier de
     * réglages locaux qu'ils désignent (une copie d'un .env, avec sa clé)
     * ne sont pas lus, et le PDF ne contient ni JavaScript ni action
     * automatique. Les deux fichiers se rangent dans le dossier temporaire
     * du système sous un nom court : la désignation tient dans sa colonne
     * où que soit le dépôt.
     */
    public function test_un_texte_dangereux_dans_un_document_reste_du_texte(): void
    {
        $image = (string) tempnam(sys_get_temp_dir(), 'img');
        $reglages = (string) tempnam(sys_get_temp_dir(), 'env');
        File::put($image, FichiersDeTest::png());
        File::put($reglages, "APP_KEY=base64:cle-secrete-de-piliqi\n");
        $client = '<script>app.alert(1)</script><img src="file://'.$image.'">';
        $designation = '<link rel="stylesheet" href="file://'.$reglages.'"><span style="background:url(file://'.$image.')">Écran</span>';

        try {
            $pdf = app(DevisPdfGenerator::class)->generer($this->devisEnvoye($client, $designation))->output();
        } finally {
            File::delete([$image, $reglages]);
        }

        $texte = implode(' ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf))));
        $this->assertStringContainsString('<script>app.alert(1)</script>', $texte);
        $this->assertStringContainsString('<link rel="stylesheet"', $texte);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf, 'Aucune image n\'est chargée.');
        $this->assertStringNotContainsString('APP_KEY', $pdf);
        $this->assertStringNotContainsString('cle-secrete-de-piliqi', $pdf);
        app(AnalyseurDePdf::class)->analyser(new PieceDeposee('devis.pdf', $pdf));
    }

    private function devisEnvoye(string $client, string $designation): Devis
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'siren' => '121212120',
            'numero_tva' => 'FR56121212120',
        ])->create(['nom' => 'Boutique du Port', 'siret' => '12121212000020']));
        $produit = Produit::factory()->create(['nom' => $designation, 'prix_vente_ttc' => 120, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(['nom' => $client]), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ['date_validite' => '2026-12-31']);

        return app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
    }
}
