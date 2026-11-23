<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Cabinets\Actions\RefuserLInscriptionALOrdreAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Le refus par l'éditeur de l'inscription d'un cabinet d'expertise
 * comptable au tableau de l'Ordre (voir RefuserLInscriptionALOrdreAction),
 * avec son motif, que le propriétaire du cabinet lit. Une société qui
 * n'est pas un cabinet, ou dont l'inscription est déjà vérifiée, est
 * refusée, sans rien écrire.
 */
#[Signature('editeur:cabinet:refuser-ordre {societe : Identifiant ou uuid du cabinet} {--motif= : Le motif du refus, lu par le propriétaire du cabinet} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Refuse l\'inscription d\'un cabinet d\'expertise comptable au tableau de l\'Ordre, avec son motif')]
class RefuserLInscriptionALOrdre extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Motif du refus : '.((string) $this->option('motif'))];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        app(RefuserLInscriptionALOrdreAction::class)->handle($societe, $editeur, (string) $this->option('motif'));

        return 'Inscription à l\'Ordre refusée.';
    }
}
