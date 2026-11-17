<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Jobs\ArchiverPuisSupprimerUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Sur le site de démonstration, chaque heure (lot DEMO-COPIE, C.14) :
 * chaque copie dont la fin est passée part à la suppression, sur la file
 * demonstration-admin (ArchiverPuisSupprimerUneCopie) : « en archivage »,
 * archive relue de sa caisse, « en suppression », DROP DATABASE … WITH
 * (FORCE), dossier de fichiers effacé, ses travaux effacés des files, liens
 * et témoins retirés, « supprimée ». La copie répond déjà « terminée » dès
 * sa fin, avant cette tâche (OuvrirLaCopie). Une archive qui échoue arrête
 * la suppression de cette copie, que l'état publié montre ; le message
 * « supprimer » du site réel n'est qu'une seconde sûreté.
 */
#[Signature('demonstration:supprimer-les-copies-echues')]
#[Description('Archive puis supprime chaque copie de démonstration arrivée à sa fin (30 jours).')]
class SupprimerLesCopiesEchues extends Command
{
    public function handle(): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $references = Copie::on('demo_admin')
            ->whereNotIn('etat', [EtatDUneCopie::Supprimee->value, EtatDUneCopie::EnSuppression->value])
            ->where('fin', '<=', CarbonImmutable::now())
            ->orderBy('fin')->orderBy('id')
            ->pluck('reference')->all();

        foreach ($references as $reference) {
            Bus::dispatch(new ArchiverPuisSupprimerUneCopie(ValeursLues::texte($reference), 'trente_jours'));
        }

        $this->info(count($references).' copie(s) arrivée(s) à leur fin, à supprimer.');

        return self::SUCCESS;
    }
}
