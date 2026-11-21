<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Actions\TerminerLaDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chaque heure, sur le site réel (B.8) : chaque démonstration vivante dont
 * la fin est passée se termine (« 30 jours »), le message « supprimer »
 * part au site de démonstration (qui archive puis supprime de lui-même à
 * la même heure : le message n'est qu'une seconde sûreté), et
 * « Démonstration terminée » s'inscrit au journal d'audit de sa société,
 * au nom du système. La carte revient à « À créer ».
 *
 * Elle tourne hors de tout contexte de société : elle ne lit que
 * l'identifiant et la société des démonstrations échues, par
 * withoutGlobalScopes, puis termine chacune dans le contexte de sa
 * société (voir TerminerLaDemonstrationAction).
 */
#[Signature('demonstration:marquer-les-fins')]
#[Description('Termine les démonstrations dont les 30 jours sont passés')]
class MarquerLesFinsDesDemonstrations extends Command
{
    public function handle(TerminerLaDemonstrationAction $terminer): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);
        $societes = Demonstration::query()
            ->withoutGlobalScopes()
            ->whereIn('etat', [...EtatDeLaDemonstration::valeursEnCours(), EtatDeLaDemonstration::EnEchec->value])
            ->where('fin_le', '<=', now())
            ->orderBy('id')
            ->pluck('societe_id')
            ->unique();

        foreach ($societes as $societeId) {
            $terminer->handle(Societe::query()->findOrFail(ValeursLues::entier($societeId)), CauseDeLaFin::TrenteJours);
        }

        $this->info($societes->count().' démonstration(s) terminée(s).');

        return self::SUCCESS;
    }
}
