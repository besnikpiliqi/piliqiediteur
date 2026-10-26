<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * La clôture par l'éditeur d'une inscription qui attend son code (voir
 * CloreLInscriptionAction::parLEditeur()), pour l'un de deux motifs : son
 * refus, après le traitement à la main d'un courrier non reçu (refus), ou
 * un type d'activité choisi par erreur, à la demande du propriétaire
 * (type_errone), qui inscrit alors de nouveau son entreprise avec le bon
 * type. L'inscription est close pour toujours, en lecture seule, ses
 * pièces s'effacent, ses codes s'annulent, sa démonstration se ferme, son
 * propriétaire est prévenu et perd son adresse de connexion, et son SIREN
 * est libéré. Un autre motif est refusé, sans rien écrire.
 */
#[Signature('editeur:societe:clore {societe : Identifiant ou uuid de la société} {--motif= : refus ou type_errone} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Clôt une inscription qui attend son code, pour un refus ou un type d\'activité choisi par erreur, ce qui libère son SIREN')]
class CloreLInscription extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return [
            'Motif : '.($this->motif()?->getLabel() ?? 'inconnu, la procédure le refusera').'.',
            'Clôture définitive : l\'inscription sera close, tous ses magasins en lecture seule pour toujours, ses pièces effacées, et son SIREN libéré.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $motif = $this->motif() ?? throw new LogicException(CloreLInscriptionAction::MESSAGE_MOTIF_INCONNU);
        app(CloreLInscriptionAction::class)->parLEditeur($societe, $editeur, $motif);

        return "Inscription close : {$motif->getLabel()}.";
    }

    /**
     * Le motif demandé, s'il est l'un de ceux de l'éditeur.
     */
    private function motif(): ?MotifCloture
    {
        $motif = MotifCloture::tryFrom((string) $this->option('motif'));

        return in_array($motif, MotifCloture::parLEditeur(), true) ? $motif : null;
    }
}
