<?php

namespace App\Domain\Demonstration\Jobs;

use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Le renouvellement du lien d'une copie (lot DEMO-COPIE, C.7), sur la file
 * demonstration-admin (seul demo_admin écrit les liens) : nouvelle
 * empreinte, génération reçue ; l'ancien lien et tous les témoins de la
 * copie, ceux du bouton du propriétaire compris, sont retirés (choix 10).
 * Une génération déjà connue ne change rien.
 */
final class RenouvelerLeLienDUneCopie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $reference,
        public readonly string $empreinte,
        public readonly int $generation,
    ) {
        $this->onConnection('demonstration');
        $this->onQueue(CreerUneCopie::FILE);
    }

    public function handle(): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        DB::connection('demo_admin')->transaction(function (): void {
            $copie = Copie::on('demo_admin')->where('reference', $this->reference)->lockForUpdate()->first();

            if ($copie === null || $this->generation <= $copie->generation_du_lien) {
                return;
            }

            $maintenant = CarbonImmutable::now();
            Lien::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->update(['retire_le' => $maintenant]);
            Lien::on('demo_admin')->create([
                'copie_id' => $copie->id,
                'empreinte' => $this->empreinte,
                'generation' => $this->generation,
                'fin' => $copie->fin,
                'cree_le' => $maintenant,
            ]);
            Temoin::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->update(['retire_le' => $maintenant]);
            $copie->forceFill(['generation_du_lien' => $this->generation])->save();
        });
    }
}
