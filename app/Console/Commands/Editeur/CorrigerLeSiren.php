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
 * n° de TVA (--numero-tva). Aucun document émis ne change.
 */
#[Signature('editeur:societe:corriger-siren {societe : Identifiant ou uuid de la société} {--siren= : Le nouveau SIREN} {--siret=* : Le nouveau SIRET de chaque magasin ouvert ou fermé temporairement, CODE:SIRET} {--numero-tva= : Le nouveau n° de TVA, s\'il n\'est pas celui que forme le nouveau SIREN} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
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
        $corrigee = app(CorrigerSirenAction::class)->handle($societe, (string) $this->option('siren'), $sirets, $numeroTva === '' ? null : $numeroTva, $editeur);

        return "SIREN corrigé : {$corrigee->siren} ; les documents émis gardent l'ancien.";
    }
}
