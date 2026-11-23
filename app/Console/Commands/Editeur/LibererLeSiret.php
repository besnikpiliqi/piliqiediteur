<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\LibererLeSiretAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * La libération du SIRET d'un magasin dont le territoire a été mal lu au
 * répertoire Sirene, puis corrigé par l'INSEE, après son premier document
 * numéroté (voir LibererLeSiretAction) : la société l'a fermé pour
 * cessation, et un nouveau magasin peut s'ouvrir sur le même
 * établissement, avec le territoire que le répertoire lit aujourd'hui ;
 * la libération s'inscrit au journal d'audit, et le propriétaire en est
 * prévenu.
 */
#[Signature('editeur:liberer-siret {societe : Identifiant ou uuid de la société} {magasin : Code du magasin dans la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Libère le SIRET d\'un magasin fermé pour cessation dont le territoire a été mal lu au répertoire Sirene, pour un nouveau magasin sur le même établissement')]
class LibererLeSiret extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin'));

        return [
            $magasin === null
                ? 'Magasin introuvable dans cette société : la procédure le refusera.'
                : "Magasin {$magasin->code} — {$magasin->nom} — SIRET {$magasin->siret} — territoire : ".($magasin->territoire?->getLabel() ?? 'aucun').'.',
            'Son SIRET se libérera pour un nouveau magasin de la société sur le même établissement, seulement s\'il est fermé pour cessation, a numéroté un document et que le répertoire Sirene situe aujourd\'hui son établissement dans un autre territoire, que le logiciel gère.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin')) ?? throw new LogicException(self::MESSAGE_MAGASIN_INTROUVABLE);
        $territoire = app(LibererLeSiretAction::class)->handle($magasin, $editeur);

        return "SIRET {$magasin->siret} du magasin {$magasin->code} libéré : la société peut créer un nouveau magasin sur cet établissement, qui prendra son territoire ({$territoire->getLabel()}).";
    }
}
