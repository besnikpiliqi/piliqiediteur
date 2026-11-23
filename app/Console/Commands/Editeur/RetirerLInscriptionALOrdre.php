<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Cabinets\Actions\RetirerLInscriptionALOrdreAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Le constat par l'éditeur de la radiation d'un cabinet d'expertise
 * comptable du tableau de l'Ordre (voir RetirerLInscriptionALOrdreAction) :
 * le cabinet n'est plus vérifié, et tous ses liens avec ses clients
 * prennent fin, clients et cabinet prévenus.
 */
#[Signature('editeur:cabinet:retirer-ordre {societe : Identifiant ou uuid du cabinet} {--motif= : Le motif du constat, lu par le propriétaire du cabinet} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Constate la radiation d\'un cabinet d\'expertise comptable de l\'Ordre, ce qui met fin à tous ses liens avec ses clients')]
class RetirerLInscriptionALOrdre extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return [
            'Motif du constat : '.((string) $this->option('motif')),
            'Le cabinet ne sera plus vérifié et tous ses liens avec ses clients prendront fin.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $liens = app(RetirerLInscriptionALOrdreAction::class)->handle($societe, $editeur, (string) $this->option('motif'));

        return "Radiation constatée : {$liens} lien(s) terminé(s).";
    }
}
