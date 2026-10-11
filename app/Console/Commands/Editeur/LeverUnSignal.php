<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\LeverUnSignalAction;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * La levée motivée d'un signal de risque par l'éditeur (voir
 * LeverUnSignalAction) : elle ne vaut que pour la part « éditeur » de ce
 * que le signal exige, un courrier exigé en plus restant à faire.
 */
#[Signature('editeur:societe:lever-signal {societe : Identifiant ou uuid de la société} {signal : Le code du signal (diffusion_partielle, essais_repetes...)} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Lève, en le motivant, un signal de risque sur une société en cours de vérification')]
class LeverUnSignal extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Signal levé : '.($this->signal()?->getLabel() ?? 'inconnu').'.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $signal = $this->signal() ?? throw new LogicException('Signal inconnu : '.implode(', ', array_map(fn (SignalDeRisque $signal): string => $signal->value, SignalDeRisque::cases())).'.');

        app(LeverUnSignalAction::class)->lever($societe, $signal, $editeur);

        return $societe->fresh()->estValidee() ? 'Signal levé : la société est validée.' : 'Signal levé.';
    }

    private function signal(): ?SignalDeRisque
    {
        return SignalDeRisque::tryFrom((string) $this->argument('signal'));
    }
}
