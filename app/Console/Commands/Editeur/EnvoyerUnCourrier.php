<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * L'envoi par l'éditeur d'un code de confirmation par courrier recommandé à
 * l'adresse officielle d'une entreprise (voir
 * EnvoyerCodeDeConfirmationAction), sans la limite des deux plis par mois
 * qui vaut pour les propriétaires. Avec --remplacer, le code actif d'une
 * autre inscription du même SIREN pour ce motif (celui d'un usurpateur,
 * qui retient le courrier du vrai titulaire) s'annule d'abord, journalisé
 * dans sa société.
 */
#[Signature('editeur:courrier:envoyer {societe : Identifiant ou uuid de la société} {--motif=verification : verification, liberation_siret, revocation ou examen_siren} '.ProcedureDeLEditeur::OPTION_REMPLACER.' '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Envoie un code de confirmation par courrier recommandé à l\'adresse officielle d\'une entreprise')]
class EnvoyerUnCourrier extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return [
            'Courrier recommandé à l\'adresse officielle, lue le jour même au répertoire Sirene (ou au registre national), motif : '.($this->motif()?->getLabel() ?? 'inconnu').'.',
            ...($this->option('remplacer') ? [ProcedureDeLEditeur::RESUME_REMPLACER] : []),
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $motif = $this->motif() ?? throw new LogicException('Motif inconnu.');
        $code = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, $motif, CanalEnvoi::RecommandeAr, editeur: $editeur, remplacerLeCodeEnCours: (bool) $this->option('remplacer'));

        return "Courrier parti (référence {$code->reference_envoi}).";
    }

    private function motif(): ?MotifCode
    {
        return MotifCode::tryFrom((string) $this->option('motif'));
    }
}
