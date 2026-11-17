<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Sur le site de démonstration, avant la mise en production, après une
 * mise à jour qui ne recrée pas la base réelle (lot DEMO-COPIE, E.4,
 * choix 6) : chaque copie servie est refaite depuis le nouveau modèle de
 * son type, sur la file demonstration-admin, l'une après l'autre (voir
 * RefaireUneCopie) : archive de sa caisse, base supprimée, nouvelle copie,
 * même référence, même lien, même fin ; le compte choisi en dernier de ses
 * témoins est effacé, et la visite suivante lit une fois « PILIQI a été mis
 * à jour le … ».
 */
#[Signature('demonstration:refaire-les-copies')]
#[Description('Refait chaque copie de démonstration depuis le nouveau modèle de son type (mise à jour avant la mise en production)')]
class RefaireLesCopiesDeLaDemonstration extends Command
{
    public function handle(): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $references = Copie::on('demo_admin')
            ->whereIn('etat', [EtatDUneCopie::Prete->value, EtatDUneCopie::EnLectureSeule->value])
            ->orderBy('id')
            ->pluck('reference')->all();

        foreach ($references as $reference) {
            Bus::dispatch(new RefaireUneCopie((string) $reference, FinDesCopies::MOTIF_MISE_A_JOUR));
        }

        $this->info(count($references).' copie(s) à refaire depuis le nouveau modèle.');

        return self::SUCCESS;
    }
}
