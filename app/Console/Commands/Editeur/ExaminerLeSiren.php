<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Actions\ExaminerSirenAction;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * L'examen exceptionnel par l'éditeur du SIREN d'une société validée
 * découvert erroné ou usurpé (voir ExaminerSirenAction), une fois saisi,
 * depuis moins de trente jours, le code du courrier d'examen envoyé au
 * représentant légal de l'entité correcte (editeur:courrier:envoyer
 * --motif=examen_siren --siren=<SIREN correct>) : la liste des documents
 * émis concernés, magasin par magasin, affichée au fil de leur lecture,
 * sans en modifier aucun, et la décision motivée (--decision : erreur,
 * usurpation ou rien_a_regulariser) au journal d'audit, avec le résumé de
 * ces documents.
 */
#[Signature('editeur:societe:examiner-siren {societe : Identifiant ou uuid de la société} {--siren-correct= : Le SIREN de l\'entité correcte} {--decision= : erreur, usurpation ou rien_a_regulariser} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Examine le SIREN d\'une société validée découvert erroné ou usurpé, liste les documents concernés et inscrit la décision')]
class ExaminerLeSiren extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return [
            'SIREN correct : '.((string) $this->option('siren-correct') ?: 'aucun, la procédure le refusera').' ; décision : '.($this->decision()?->getLabel() ?? 'inconnue, la procédure la refusera').'.',
            'Les documents émis de chaque magasin réel seront listés, sans en modifier aucun.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $decision = $this->decision() ?? throw new LogicException('Décision inconnue : erreur, usurpation ou rien_a_regulariser.');
        $euros = app(CalculateurLigne::class)->enEuros(...);

        $nombre = app(ExaminerSirenAction::class)->handle($societe, (string) $this->option('siren-correct'), $decision, $editeur, function (array $document) use ($euros): void {
            $regle = $document['type'] === 'avoir' ? 'imputés' : 'réglés';
            $this->line("{$document['magasin']} · {$document['type']} {$document['numero']} du ".Calendrier::afficherJour($document['date'], 'd/m/Y')." · {$document['client']} · {$euros($document['montant_ttc'])} TTC, dont {$euros($document['regle'])} {$regle} · SIREN signé {$document['siren_signe']}");
        });

        return "Examen inscrit : {$nombre} document(s) concerné(s), décision « {$decision->getLabel()} ».";
    }

    private function decision(): ?DecisionExamenSiren
    {
        return DecisionExamenSiren::tryFrom((string) $this->option('decision'));
    }
}
