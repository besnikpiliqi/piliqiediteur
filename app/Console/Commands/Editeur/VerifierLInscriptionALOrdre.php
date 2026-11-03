<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Cabinets\Actions\ConstaterLInscriptionALOrdreAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * La vérification par l'éditeur de l'inscription d'un cabinet d'expertise
 * comptable au tableau de l'Ordre (voir ConstaterLInscriptionALOrdreAction),
 * après l'avoir lue dans l'annuaire de l'Ordre : son n° d'inscription et
 * son conseil régional ; avec --activite-controlee, le contrôle de son
 * code d'activité quand le répertoire Sirene n'en donne pas ou le classe
 * dans une autre nomenclature que la NAF rév. 2. Une société qui n'est pas
 * un cabinet est refusée, sans rien écrire.
 */
#[Signature('editeur:cabinet:verifier-ordre {societe : Identifiant ou uuid du cabinet} {--numero= : N° d\'inscription au tableau de l\'Ordre} {--conseil= : Conseil régional de l\'Ordre} {--activite-controlee : Contrôle aussi son code d\'activité, absent ou d\'une autre nomenclature} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Constate l\'inscription d\'un cabinet d\'expertise comptable au tableau de l\'Ordre, et au besoin contrôle son code d\'activité')]
class VerifierLInscriptionALOrdre extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return [
            'Inscription au tableau constatée : n° '.((string) $this->option('numero')).', conseil régional '.((string) $this->option('conseil')).'.',
            $this->option('activite-controlee') ? 'Le code d\'activité du cabinet sera aussi tenu pour contrôlé.' : 'Le code d\'activité du cabinet ne change pas.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        app(ConstaterLInscriptionALOrdreAction::class)->handle($societe, $editeur, (string) $this->option('numero'), (string) $this->option('conseil'), (bool) $this->option('activite-controlee'));

        return 'Inscription à l\'Ordre vérifiée.';
    }
}
