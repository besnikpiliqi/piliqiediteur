<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\LeverUnSignalAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Le doute de l'éditeur sur une société en cours de vérification (signal
 * decision_editeur, voir LeverUnSignalAction) : il bloque sa validation
 * jusqu'à ce que l'éditeur le lève (editeur:societe:lever-signal).
 */
#[Signature('editeur:societe:signaler {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Constate un doute de l\'éditeur qui bloque la validation d\'une société')]
class SignalerUnDoute extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Doute de l\'éditeur : la société ne sera pas validée tant qu\'il ne sera pas levé.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        app(LeverUnSignalAction::class)->signalerUnDoute($societe, $editeur);

        return 'Doute constaté.';
    }
}
