<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\CorrigerSirenAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * La correction exceptionnelle par l'éditeur du SIREN d'une société
 * validée, saisi par erreur (voir CorrigerSirenAction), procédure grave
 * lancée par deux opérateurs distincts, après un examen qui a conclu à
 * une erreur : le nouveau SIREN (--siren), le nouveau SIRET de chaque
 * magasin ouvert ou fermé temporairement (--siret=CODE:SIRET, une fois par
 * magasin) et, s'il n'est pas celui que forme le nouveau SIREN, le nouveau
 * n° de TVA (--numero-tva) ; un ancien n° de TVA qui n'était pas celui que
 * formait l'ancien SIREN ne se garde que sur la confirmation qu'il reste
 * celui de l'assujetti unique dont la société est membre
 * (--assujetti-unique). Aucun document émis ne change.
 */
#[Signature('editeur:societe:corriger-siren {societe : Identifiant ou uuid de la société} {--siren= : Le nouveau SIREN} {--siret=* : Le nouveau SIRET de chaque magasin ouvert ou fermé temporairement, CODE:SIRET} {--numero-tva= : Le nouveau n° de TVA, s\'il n\'est pas celui que forme le nouveau SIREN} {--assujetti-unique : L\'ancien n° de TVA, qui n\'était pas celui que formait l\'ancien SIREN, reste celui de l\'assujetti unique dont la société est membre} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Corrige le SIREN d\'une société validée saisi par erreur, après son examen, sans toucher à aucun document émis')]
class CorrigerLeSiren extends ProcedureDeLEditeur
{
    protected function operateursExiges(): int
    {
        return 2;
    }

    protected function resume(Societe $societe): array
    {
        return [
            'Nouveau SIREN : '.((string) $this->option('siren') ?: 'aucun, la procédure le refusera').'.',
            'Nouveaux SIRET : '.(implode(', ', (array) $this->option('siret')) ?: 'aucun').'.',
            'N° de TVA : '.match (true) {
                trim((string) $this->option('numero-tva')) !== '' => trim((string) $this->option('numero-tva')),
                (bool) $this->option('assujetti-unique') => 'l\'ancien, gardé comme celui de l\'assujetti unique',
                default => 'celui que forme le nouveau SIREN, si l\'ancien était celui que formait l\'ancien SIREN',
            }.'.',
            'Les documents émis gardent l\'ancien SIREN qu\'ils ont signé ; les anciens SIRET restent réservés.',
            'Procédure grave : deux opérateurs distincts s\'authentifient chacun par son secret.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $sirets = [];

        foreach ((array) $this->option('siret') as $paire) {
            [$code, $siret] = array_pad(explode(':', (string) $paire, 2), 2, '');
            $sirets[CodeMagasin::normaliser($code)] = (string) preg_replace('/\s+/', '', $siret);
        }

        $numeroTva = trim((string) $this->option('numero-tva'));
        $corrigee = app(CorrigerSirenAction::class)->handle($societe, (string) $this->option('siren'), $sirets, $numeroTva === '' ? null : $numeroTva, $editeur, (bool) $this->option('assujetti-unique'));

        return "SIREN corrigé : {$corrigee->siren} ; les documents émis gardent l'ancien.";
    }
}
