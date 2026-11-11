<?php

namespace Tests\Feature\Fichiers;

use App\Domain\Tenancy\Enums\RoleMagasin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\FileNotPreviewableException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Les envois temporaires de Livewire, où part d'abord un fichier choisi
 * dans un formulaire (pièce du fournisseur, pièce de la vérification de la
 * société), avant que l'action qui le dépose le contrôle en entier
 * (décision de l'éditeur du 07/11/2026, lot FI) : sur le disque privé,
 * jamais servi par une adresse ; refusés par le serveur, quoi que le
 * navigateur ait laissé passer, s'ils ne sont ni un PDF ni une photo JPEG
 * ou PNG, le type étant lu dans leur contenu ; leur aperçu n'est servi que
 * pour une photo.
 */
class EnvoisTemporairesTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_envois_temporaires_restent_sur_le_disque_prive_jamais_servi(): void
    {
        $this->assertSame('local', config('livewire.temporary_file_upload.disk'));
        $this->assertSame([storage_path('app/private'), false], [config('filesystems.disks.local.root'), config('filesystems.disks.local.serve')]);
        $this->assertFalse(Route::has('storage.local'), 'Aucune adresse ne sert le disque privé.');
        $this->assertFalse(Route::has('storage.local.upload'), 'Aucune adresse n\'écrit sur le disque privé.');
    }

    /**
     * Une page HTML, une image SVG ou un programme sont refusés par le
     * serveur à l'envoi temporaire, même sous un nom en .pdf ou en .png ;
     * rien n'est rangé. Un PDF et des photos JPEG et PNG passent.
     */
    public function test_le_serveur_refuse_un_envoi_qui_n_est_ni_un_pdf_ni_une_photo(): void
    {
        Storage::fake(FileUploadConfiguration::disk());
        $this->actingAs($this->membre($this->ouvrirUneSociete(), RoleMagasin::Responsable));

        foreach ([
            'facture.pdf' => '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>',
            'photo.png' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="1"/></svg>',
            'facture.pdf.exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00",
        ] as $nom => $contenu) {
            $this->envoyer([$this->fichier($nom, $contenu)])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['files.0']);
        }

        $this->assertSame([], Storage::disk(FileUploadConfiguration::disk())->allFiles());

        $this->envoyer([
            $this->fichier('facture.pdf', FichiersDeTest::pdf()),
            $this->fichier('photo.jpg', FichiersDeTest::jpeg()),
            $this->fichier('photo.png', FichiersDeTest::png()),
        ])->assertOk()->assertJsonCount(3, 'paths');
    }

    public function test_le_serveur_refuse_un_envoi_de_plus_de_10_mo(): void
    {
        Storage::fake(FileUploadConfiguration::disk());
        $this->actingAs($this->membre($this->ouvrirUneSociete(), RoleMagasin::Responsable));

        $this->envoyer([$this->fichier('facture.pdf', FichiersDeTest::pdfDeTaille(10 * 1024 * 1024 + 1024))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0']);
    }

    /**
     * L'aperçu d'un envoi temporaire n'est servi que pour une photo JPEG
     * ou PNG : jamais pour un PDF.
     */
    public function test_l_apercu_n_est_servi_que_pour_une_photo(): void
    {
        $disque = Storage::fake(FileUploadConfiguration::disk());
        $disque->put(FileUploadConfiguration::path('facture.pdf'), FichiersDeTest::pdf());
        $disque->put(FileUploadConfiguration::path('photo.png'), FichiersDeTest::png());
        $disque->put(FileUploadConfiguration::path('photo.jpg'), FichiersDeTest::jpeg());

        $pdf = new TemporaryUploadedFile('facture.pdf', FileUploadConfiguration::disk());

        $this->assertSame([false, true, true], [
            $pdf->isPreviewable(),
            (new TemporaryUploadedFile('photo.png', FileUploadConfiguration::disk()))->isPreviewable(),
            (new TemporaryUploadedFile('photo.jpg', FileUploadConfiguration::disk()))->isPreviewable(),
        ]);
        $this->expectException(FileNotPreviewableException::class);
        $pdf->temporaryUrl();
    }

    /**
     * Un fichier envoyé de ce nom et de ce contenu, dont le serveur lit le
     * type dans le contenu, comme celui d'un navigateur (un faux fichier
     * des tests le déduirait de son nom).
     */
    private function fichier(string $nom, string $contenu): UploadedFile
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'envoi-');
        file_put_contents($chemin, $contenu);
        $this->beforeApplicationDestroyed(fn () => @unlink($chemin));

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    /**
     * L'envoi de ces fichiers à l'adresse signée que Livewire donne au
     * navigateur.
     *
     * @param  list<UploadedFile>  $fichiers
     * @return TestResponse<Response>
     */
    private function envoyer(array $fichiers): TestResponse
    {
        return $this->post(app(GenerateSignedUploadUrl::class)->forLocal(), ['files' => $fichiers], ['Accept' => 'application/json']);
    }
}
