<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Jobs\PreparerUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Chaque heure, sur le site de démonstration (lot DEMO-COPIE, C.3) : une
 * copie en échec dont l'essai suivant est dû repart (une heure après son
 * dernier échec), et la demande qui attend une place en premier repart
 * aussi, pour le cas où une place s'est libérée sans suppression (plafond
 * relevé, copie en échec). Chaque reprise est un travail de la file
 * demonstration-admin.
 */
#[Signature('demonstration:reprendre-les-copies')]
#[Description('Reprend les copies de démonstration en échec et la demande qui attend une place en premier.')]
class ReprendreLesCopiesDeLaDemonstration extends Command
{
    public function handle(CopiesDeLaDemonstration $copies): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $references = Copie::on('demo_admin')->where('etat', EtatDUneCopie::EnEchec)
            ->where('prochain_essai_le', '<=', CarbonImmutable::now())
            ->orderBy('prochain_essai_le')->orderBy('id')->pluck('reference')->all();
        $enAttente = $copies->enAttenteDUnePlace()[0] ?? null;

        if ($enAttente !== null) {
            $references[] = $enAttente;
        }

        foreach ($references as $reference) {
            Bus::dispatch(new PreparerUneCopie((string) $reference));
        }

        $this->info(count($references).' copie(s) reprise(s).');

        return self::SUCCESS;
    }
}
