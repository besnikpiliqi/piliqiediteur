<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\ExaminerLesPiecesAction;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Le traitement à la main, par l'éditeur, d'une société dont les courriers
 * recommandés ne sont pas parvenus (voir ExaminerLesPiecesAction) :
 * l'éditeur, qui a lu les pièces déposées (editeur:societe:pieces),
 * inscrit son examen, les pièces s'effacent, et un nouveau courrier part
 * au nom du représentant légal, à l'adresse officielle du siège. La
 * société n'est validée qu'à la saisie de son code : des pièces ne la
 * valident jamais.
 */
#[Signature('editeur:societe:examiner {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Inscrit l\'examen des pièces d\'une société par l\'éditeur, et lui envoie un nouveau courrier recommandé')]
class ExaminerLesPieces extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        $pieces = app(TenantContext::class)->runAsSociete($societe, fn () => PieceVerification::query()->whereNull('effacee_le')->orderBy('id')->get());

        return [
            'Pièces examinées, effacées ensuite : '.($pieces->isEmpty() ? 'aucune' : $pieces->map(fn (PieceVerification $piece): string => $piece->type->getLabel())->implode(', ')).'.',
            'Un nouveau courrier recommandé partira au nom du représentant légal, à l\'adresse officielle du siège.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $code = app(ExaminerLesPiecesAction::class)->handle($societe, $editeur);

        return 'Examen inscrit, pièces effacées ; le courrier recommandé est parti (référence '.$code->reference_envoi.').';
    }
}
