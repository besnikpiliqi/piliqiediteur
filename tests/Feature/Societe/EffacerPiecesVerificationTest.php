<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\TestCase;

/**
 * L'effacement horaire de ce qui ne doit pas rester d'une pièce de
 * vérification (voir app:effacer-pieces-verification) : aucune copie
 * d'une pièce ne se garde trente jours après son dépôt, ni un envoi
 * abandonné au formulaire de dépôt, ni un dossier d'examen de l'éditeur
 * resté après une interruption.
 */
class EffacerPiecesVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une pièce déposée le 2 novembre à 10 h, une fois le nouveau courrier
     * parti, est encore là au passage du 2 décembre à 8 h, et effacée à
     * celui de 9 h, le dernier avant ses trente jours.
     */
    public function test_une_piece_s_efface_au_dernier_passage_avant_ses_trente_jours(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $this->travelTo('2026-10-18 10:00:00');
        $societe = $this->inscrireUneSociete();
        $this->travelTo('2026-11-02 10:00:00');
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe);
        $piece = app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe->fresh(), TypePiece::Identite, 'Carte d\'identité de Jeanne Dupont');
        $chemin = (string) $piece->chemin;

        $this->travelTo('2026-12-02 08:00:00');
        $this->artisan('app:effacer-pieces-verification')->assertSuccessful();
        Storage::disk(PiecesDeVerification::DISQUE)->assertExists($chemin);

        $this->travelTo('2026-12-02 09:00:00');
        $this->artisan('app:effacer-pieces-verification')->assertSuccessful();

        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing($chemin);
        $piece = app(TenantContext::class)->runAsSociete($societe, fn () => $piece->fresh());
        $this->assertSame([null, '2026-12-02 09:00:00'], [$piece->chemin, $piece->effacee_le->format('Y-m-d H:i:s')]);
    }

    /**
     * Plus de pièces échues qu'un lot de lecture n'en contient : chaque
     * pièce effacée sort du filtre, et pas une n'est sautée.
     */
    public function test_toutes_les_pieces_echues_s_effacent_en_un_passage_meme_au_dela_d_un_lot(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $societe = $this->inscrireUneSociete();
        $deposeeLe = now()->subDays(PieceVerification::CONSERVATION_EN_JOURS + 1);
        DB::table('pieces_verification')->insert(array_fill(0, 1001, [
            'societe_id' => $societe->id,
            'type' => TypePiece::Kbis->value,
            'chemin' => null,
            'empreinte' => str_repeat('0', 64),
            'deposee_par' => $societe->proprietaire->id,
            'deposee_par_nom' => $societe->proprietaire->name,
            'deposee_le' => $deposeeLe,
            'created_at' => $deposeeLe,
            'updated_at' => $deposeeLe,
        ]));

        $this->artisan('app:effacer-pieces-verification')->assertSuccessful();

        $this->assertSame(0, PieceVerification::withoutGlobalScope(SocieteScope::class)->whereNull('effacee_le')->count());
    }

    /**
     * Un fichier envoyé au formulaire de dépôt et jamais déposé, et un
     * dossier où l'éditeur lisait des pièces déchiffrées, restés depuis
     * plus d'une heure, s'effacent ; ceux d'un envoi ou d'un examen en
     * cours restent. Les dossiers d'examen sont ceux du disque
     * « verification » simulé, propre au processus de test.
     *
     * L'heure de l'application se cale sur l'heure de modification que
     * relisent les fichiers : faketime décale celle-ci (stat) sans décaler
     * celle qu'on y écrit (touch), et seuls les écarts entre fichiers
     * restent exacts.
     */
    public function test_les_envois_abandonnes_et_les_dossiers_d_examen_restes_s_effacent_apres_une_heure(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $envois = FileUploadConfiguration::storage();
        $envois->put(FileUploadConfiguration::path('abandonne.pdf'), 'CNI EN CLAIR');
        $envois->put(FileUploadConfiguration::path('en-cours.pdf'), 'CNI EN CLAIR');
        $abandonne = $envois->path(FileUploadConfiguration::path('abandonne.pdf'));
        $envoiEnCours = $envois->path(FileUploadConfiguration::path('en-cours.pdf'));
        $reste = PiecesDeVerification::nouveauDossierDExamen();
        $examenEnCours = PiecesDeVerification::nouveauDossierDExamen();
        File::ensureDirectoryExists($reste, 0700);
        File::put("{$reste}/piece-1-identite", 'CNI EN CLAIR');
        File::ensureDirectoryExists($examenEnCours, 0700);
        $maintenant = now()->getTimestamp();
        touch($abandonne, $maintenant - 61 * 60);
        touch($reste, $maintenant - 61 * 60);
        touch($envoiEnCours, $maintenant);
        touch($examenEnCours, $maintenant);
        clearstatcache();
        $this->travelTo(CarbonImmutable::createFromTimestamp(filemtime($envoiEnCours)));

        $this->artisan('app:effacer-pieces-verification')->assertSuccessful();

        $this->assertSame([false, true], [File::exists($abandonne), File::exists($envoiEnCours)]);
        $this->assertSame([false, true], [File::isDirectory($reste), File::isDirectory($examenEnCours)]);
        $this->assertSame([$examenEnCours], PiecesDeVerification::dossiersDExamen());
    }
}
