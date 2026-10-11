<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\OuvrirUneReclamationAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * L'ouverture de la réclamation d'une société qui a prouvé sa qualité de
 * représentant alors que son SIREN est validé par une autre (voir
 * OuvrirUneReclamationAction) : le code de la réclamation part à
 * l'adresse officielle de l'entreprise.
 */
#[Signature('editeur:reclamation:ouvrir {societe : Identifiant ou uuid de la société qui réclame} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Ouvre la réclamation d\'un SIREN validé par une autre société et envoie son code par courrier recommandé')]
class OuvrirUneReclamation extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Réclamation de ce SIREN, validé par une autre société : le code de la réclamation partira à l\'adresse officielle de l\'entreprise.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $code = app(OuvrirUneReclamationAction::class)->handle($societe, $editeur);

        return "Réclamation ouverte ; courrier parti (référence {$code->reference_envoi}).";
    }
}
