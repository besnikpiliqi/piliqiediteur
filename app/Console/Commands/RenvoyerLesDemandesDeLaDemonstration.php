<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sur le site réel (lot DEMO-COPIE, E.9) : le message « creer » de chaque
 * démonstration en cours repart vers le site de démonstration, avec la
 * même référence, le même repère, la même empreinte du lien et la même
 * fin (voir BoiteDEnvoi::renvoyerLaCreation()). Elle sert quand le
 * registre du site de démonstration a été perdu, et, sur le poste, une
 * fois après chaque semis, pour créer les copies des sociétés semées. Elle
 * est idempotente : le site de démonstration répond l'état d'une copie
 * qu'il connaît déjà, sans en créer une seconde.
 *
 * Elle tourne hors de tout contexte de société : elle ne lit que
 * l'identifiant et la société des démonstrations en cours, par
 * withoutGlobalScopes, puis dépose chaque message dans le contexte de la
 * société de la ligne.
 */
#[Signature('demonstration:renvoyer-les-demandes')]
#[Description('Renvoie au site de démonstration la demande de chaque démonstration en cours')]
class RenvoyerLesDemandesDeLaDemonstration extends Command
{
    public function handle(BoiteDEnvoi $boite, TenantContext $contexte): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);
        $demandes = Demonstration::query()
            ->withoutGlobalScopes()
            ->whereIn('etat', EtatDeLaDemonstration::valeursEnCours())
            ->where('fin_le', '>', now())
            ->orderBy('id')
            ->get(['id', 'societe_id']);

        foreach ($demandes as $demande) {
            $societe = Societe::query()->findOrFail($demande->societe_id);
            $contexte->runAsSociete($societe, function () use ($boite, $demande): void {
                $boite->renvoyerLaCreation(Demonstration::query()->findOrFail($demande->getKey()));
            });
        }

        $this->info($demandes->count().' demande(s) renvoyée(s) au site de démonstration.');

        return self::SUCCESS;
    }
}
