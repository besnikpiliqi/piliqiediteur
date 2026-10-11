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
 * La validation manuelle d'une société (B4, voir ExaminerLesPiecesAction)
 * : l'éditeur, qui a lu les pièces déposées (editeur:societe:pieces),
 * inscrit son examen, les pièces s'effacent, et le code du courrier
 * recommandé part à l'adresse officielle s'il n'est pas déjà en route.
 * La société n'est validée qu'à la saisie de ce code, si son évaluation le
 * permet : des pièces ne valent jamais seules. Avec --remplacer, le code
 * actif d'une autre inscription du même SIREN s'annule d'abord (voir
 * editeur:courrier:envoyer).
 */
#[Signature('editeur:societe:valider {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTION_REMPLACER.' '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Inscrit l\'examen des pièces d\'une société par l\'éditeur, et lui envoie le code du courrier recommandé')]
class ValiderLaSociete extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        $pieces = app(TenantContext::class)->runAsSociete($societe, fn () => PieceVerification::query()->whereNull('effacee_le')->orderBy('id')->get());

        return [
            'Pièces examinées, effacées ensuite : '.($pieces->isEmpty() ? 'aucune' : $pieces->map(fn (PieceVerification $piece): string => $piece->type->getLabel())->implode(', ')).'.',
            'Le code du courrier recommandé partira à l\'adresse officielle s\'il n\'est pas déjà en route.',
            ...($this->option('remplacer') ? [ProcedureDeLEditeur::RESUME_REMPLACER] : []),
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $code = app(ExaminerLesPiecesAction::class)->handle($societe, $editeur, (bool) $this->option('remplacer'));

        return $code === null
            ? 'Examen inscrit, pièces effacées ; le code du courrier était déjà en route ou saisi.'
            : 'Examen inscrit, pièces effacées ; le code du courrier recommandé est parti (référence '.$code->reference_envoi.').';
    }
}
