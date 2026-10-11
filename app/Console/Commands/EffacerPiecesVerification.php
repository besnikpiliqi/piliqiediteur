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
use Illuminate\Support\Facades\Log;

/**
 * Chaque jour (voir bootstrap/app.php), efface le fichier de chaque pièce
 * déposée depuis trente jours sans décision de l'éditeur (voir
 * PiecesDeVerification) : aucune copie de pièce ne se garde au-delà. La
 * trace de chaque pièce reste. Les pièces se lisent toutes sociétés
 * confondues, puis chacune s'efface dans sa société.
 */
#[Signature('app:effacer-pieces-verification')]
#[Description('Efface les pièces de vérification déposées depuis plus de 30 jours')]
class EffacerPiecesVerification extends Command
{
    public function handle(PiecesDeVerification $pieces, TenantContext $tenantContext): int
    {
        $effacees = 0;

        PieceVerification::withoutGlobalScope(SocieteScope::class)
            ->whereNull('effacee_le')
            ->where('deposee_le', '<=', now()->subDays(PieceVerification::CONSERVATION_EN_JOURS))
            ->orderBy('id')
            ->each(function (PieceVerification $piece) use ($pieces, $tenantContext, &$effacees): void {
                $tenantContext->runAsSociete(Societe::query()->findOrFail($piece->societe_id), fn () => $pieces->effacer($piece, PiecesDeVerification::MOTIF_DELAI));
                $effacees++;
            });

        Log::info('Pièces de vérification effacées après leur délai.', ['nombre' => $effacees]);
        $this->info("{$effacees} pièce(s) effacée(s).");

        return self::SUCCESS;
    }
}
