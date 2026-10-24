<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Le refus d'une inscription qui attend son code, après le traitement à
 * la main d'un courrier non reçu (voir CloreLInscriptionAction::refuser())
 * : l'inscription est close pour toujours, en lecture seule, ses pièces
 * s'effacent, ses codes s'annulent, sa démonstration se ferme, son
 * propriétaire est prévenu, et son SIREN est libéré.
 */
#[Signature('editeur:societe:refuser {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Refuse et clôt une inscription qui attend son code, ce qui libère son SIREN')]
class RefuserLaSociete extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Refus définitif : l\'inscription sera close, tous ses magasins en lecture seule pour toujours, ses pièces effacées, et son SIREN libéré.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        app(CloreLInscriptionAction::class)->refuser($societe, $editeur);

        return 'Inscription refusée et close.';
    }
}
