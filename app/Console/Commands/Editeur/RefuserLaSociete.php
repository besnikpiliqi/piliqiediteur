<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\RefuserLaSocieteAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Le refus définitif d'une société en cours de vérification (voir
 * RefuserLaSocieteAction) : lecture seule pour toujours, pièces effacées,
 * démonstration fermée, propriétaire prévenu.
 */
#[Signature('editeur:societe:refuser {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Refuse définitivement une société en cours de vérification')]
class RefuserLaSociete extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Refus définitif : tous ses magasins passeront en lecture seule pour toujours, ses pièces s\'effaceront.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        app(RefuserLaSocieteAction::class)->handle($societe, $editeur);

        return 'Société refusée.';
    }
}
