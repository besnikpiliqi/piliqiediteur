<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\LimitationDesConnexions;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Prévient le propriétaire d'une société, dans sa cloche et par e-mail,
 * qu'un identifiant de connexion d'un compte de sa société a dépassé le
 * seuil d'échecs de connexion (voir LimitationDesConnexions) : une tâche en
 * file, jamais pendant la requête de connexion, dont le délai dirait sinon
 * que l'identifiant existe. Le message nomme le compte, jamais son
 * identifiant.
 */
class AlerterDuSeuilDEchecsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $compteId) {}

    public function handle(): void
    {
        $compte = User::query()->find($this->compteId);
        $societe = $compte?->societe;
        $proprietaire = $societe?->proprietaire;

        if ($compte === null || $societe === null || $proprietaire === null) {
            return;
        }

        $proprietaire->notify(new AvisDeLEquipe(
            $societe,
            'Essais de connexion manqués',
            "Le compte de {$compte->name} a reçu plus de ".LimitationDesConnexions::ECHECS_PAR_SAISIE.' essais de connexion manqués en une heure. Si ce n\'est pas cette personne, quelqu\'un essaie peut-être de deviner son mot de passe : demandez-lui de choisir un mot de passe à elle dans son profil, ou redonnez-lui l\'accès.',
            nature: AvisDeLEquipe::ATTENTION,
            parCourriel: true,
        ));
    }
}
