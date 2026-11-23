<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Les archives de la caisse des copies gardées six ans (lot DEMO-COPIE,
 * C.18, BOFiP § 155) : chaque jour, celles dont l'échéance est passée
 * sont effacées, fichier et ligne du registre, et chaque purge s'inscrit
 * au journal du site. Seulement sur le site de démonstration, par
 * demo_admin.
 */
#[Signature('demonstration:purger-les-archives')]
#[Description('Efface les archives de la caisse des copies de démonstration gardées six ans.')]
class PurgerLesArchivesDeLaDemonstration extends Command
{
    public function handle(): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $purgees = 0;

        foreach (Archive::on('demo_admin')->where('a_effacer_le', '<=', CarbonImmutable::now())->orderBy('id')->get() as $archive) {
            File::delete($archive->chemin);
            $archive->delete();
            Log::info('Archive de la caisse d\'une copie de démonstration effacée après six ans.', [
                'reference' => $archive->reference,
                'chemin' => $archive->chemin,
                'empreinte' => $archive->empreinte,
                'creee_le' => MessagesSignes::instant($archive->creee_le),
            ]);
            $purgees++;
        }

        $this->info($purgees.' archive(s) effacée(s).');

        return self::SUCCESS;
    }
}
