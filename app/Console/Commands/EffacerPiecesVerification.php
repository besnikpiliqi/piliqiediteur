<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Chaque heure (voir bootstrap/app.php), efface ce qui ne doit pas rester
 * d'une pièce de vérification (voir PiecesDeVerification) :
 * - le fichier de chaque pièce qui atteindrait ses trente jours avant le
 *   passage suivant sans décision de l'éditeur : aucune copie ne se garde
 *   au-delà, et la trace de chaque pièce reste. Les pièces se lisent
 *   toutes sociétés confondues, par identifiant croissant (chaque pièce
 *   effacée sortant du filtre, un parcours par pages en sauterait), puis
 *   chacune s'efface dans sa société ;
 * - les fichiers envoyés au formulaire de dépôt, restés en clair dans le
 *   dossier temporaire des envois depuis plus d'une heure sans avoir été
 *   déposés (fenêtre fermée, envoi abandonné) : le dépôt, accepté ou
 *   refusé, efface le sien aussitôt (voir VerificationSociete), et c'est
 *   le seul formulaire d'envoi de fichiers de l'application ;
 * - les dossiers où l'éditeur lit les pièces déchiffrées (voir
 *   editeur:societe:pieces), restés depuis plus d'une heure quand la
 *   commande n'a pas pu les effacer.
 */
#[Signature('app:effacer-pieces-verification')]
#[Description('Efface les pièces de vérification avant leurs 30 jours, et les copies restées des envois et des examens')]
class EffacerPiecesVerification extends Command
{
    /**
     * L'intervalle entre deux passages, et l'âge au-delà duquel un envoi
     * ou un dossier d'examen est tenu pour abandonné.
     */
    public const MINUTES_ENTRE_DEUX_PASSAGES = 60;

    public function handle(PiecesDeVerification $pieces, TenantContext $tenantContext): int
    {
        $effacees = 0;

        PieceVerification::withoutGlobalScope(SocieteScope::class)
            ->whereNull('effacee_le')
            ->where('deposee_le', '<=', now()->addMinutes(self::MINUTES_ENTRE_DEUX_PASSAGES)->subDays(PieceVerification::CONSERVATION_EN_JOURS))
            ->lazyById()
            ->each(function (PieceVerification $piece) use ($pieces, $tenantContext, &$effacees): void {
                $tenantContext->runAsSociete(Societe::query()->findOrFail($piece->societe_id), fn () => $pieces->effacer($piece, PiecesDeVerification::MOTIF_DELAI));
                $effacees++;
            });

        $envois = $this->effacerLesEnvoisAbandonnes();
        $examens = $this->effacerLesDossiersDExamenRestes();

        Log::info('Pièces de vérification effacées.', ['pieces' => $effacees, 'envois_abandonnes' => $envois, 'dossiers_d_examen' => $examens]);
        $this->info("{$effacees} pièce(s), {$envois} envoi(s) abandonné(s) et {$examens} dossier(s) d'examen effacé(s).");

        return self::SUCCESS;
    }

    private function effacerLesEnvoisAbandonnes(): int
    {
        $stockage = FileUploadConfiguration::storage();
        $limite = now()->subMinutes(self::MINUTES_ENTRE_DEUX_PASSAGES)->getTimestamp();

        return collect($stockage->allFiles(FileUploadConfiguration::path()))
            ->filter(fn (string $fichier): bool => $stockage->exists($fichier) && $stockage->lastModified($fichier) <= $limite)
            ->each(fn (string $fichier): bool => $stockage->delete($fichier))
            ->count();
    }

    private function effacerLesDossiersDExamenRestes(): int
    {
        $limite = now()->subMinutes(self::MINUTES_ENTRE_DEUX_PASSAGES)->getTimestamp();

        return collect(PiecesDeVerification::dossiersDExamen())
            ->filter(fn (string $dossier): bool => File::lastModified($dossier) <= $limite)
            ->each(fn (string $dossier): bool => File::deleteDirectory($dossier))
            ->count();
    }
}
