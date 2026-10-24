<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * L'envoi par l'éditeur d'un code de confirmation par courrier recommandé,
 * au nom du représentant légal de l'entreprise, à l'adresse officielle de
 * son siège (voir EnvoyerCodeDeConfirmationAction::parLEditeur()), sans
 * la limite d'un seul nouveau courrier qui vaut pour le propriétaire :
 * pour la vérification d'une inscription qui attend son code, ou pour un
 * motif des procédures postales de l'éditeur. Le code actif du même motif
 * s'annule.
 */
#[Signature('editeur:courrier:envoyer {societe : Identifiant ou uuid de la société} {--motif=verification : verification, recuperation, liberation_siret, revocation ou examen_siren} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Envoie un code de confirmation par courrier recommandé au représentant légal d\'une entreprise')]
class EnvoyerUnCourrier extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return [
            'Courrier recommandé au représentant légal, à l\'adresse officielle du siège, lus le jour même au registre national des entreprises, motif : '.($this->motif()?->getLabel() ?? 'inconnu').'.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $motif = $this->motif() ?? throw new LogicException('Motif inconnu.');
        $code = app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe, $motif, $editeur);

        return "Courrier parti (référence {$code->reference_envoi}).";
    }

    private function motif(): ?MotifCode
    {
        return MotifCode::tryFrom((string) $this->option('motif'));
    }
}
