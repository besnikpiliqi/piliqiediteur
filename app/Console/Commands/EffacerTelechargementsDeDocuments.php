<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Toutes les heures, société par société : efface les téléchargements des
 * documents par période dont le lien a expiré (sept jours après leur
 * demande, voir TelechargementDeDocuments), leur fichier puis leur ligne,
 * prêts, en échec ou restés en préparation. Le journal d'audit de la
 * société garde la trace de chaque téléchargement préparé ; les documents
 * eux-mêmes restent dans leurs magasins.
 */
#[Signature('app:effacer-telechargements-de-documents')]
#[Description('Efface les téléchargements des documents par période dont le lien a expiré, fichier compris')]
class EffacerTelechargementsDeDocuments extends Command
{
    public function handle(TenantContext $contexte): int
    {
        $societes = TelechargementDeDocuments::query()->withoutGlobalScopes()->where('expire_le', '<=', now())->distinct()->orderBy('societe_id')->pluck('societe_id');
        $effaces = 0;

        foreach ($societes as $societeId) {
            $effaces += $contexte->runAsSociete(Societe::query()->findOrFail($societeId), function (): int {
                $effaces = 0;

                foreach (TelechargementDeDocuments::query()->where('expire_le', '<=', now())->orderBy('id')->get() as $telechargement) {
                    DB::transaction(function () use ($telechargement): void {
                        $telechargement->delete();

                        if ($telechargement->chemin !== null) {
                            DB::afterCommit(fn () => Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->delete($telechargement->chemin));
                        }
                    });
                    $effaces++;
                }

                return $effaces;
            });
        }

        $this->info("{$effaces} téléchargement(s) effacé(s).");

        return self::SUCCESS;
    }
}
