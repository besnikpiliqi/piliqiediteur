<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\CorrigerLeTerritoireAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * La correction du territoire d'un magasin mal lu au répertoire Sirene,
 * puis corrigé par l'INSEE (voir CorrigerLeTerritoireAction) : le
 * territoire que le répertoire lit aujourd'hui pour son établissement
 * confirmé remplace le sien, tant que le magasin n'a numéroté aucun
 * document ; la correction s'inscrit à son journal des événements et au
 * journal d'audit, et le propriétaire en est prévenu.
 */
#[Signature('editeur:corriger-territoire {societe : Identifiant ou uuid de la société} {magasin : Code du magasin dans la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Corrige le territoire d\'un magasin mal lu au répertoire Sirene, tant qu\'il n\'a numéroté aucun document')]
class CorrigerLeTerritoire extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin'));

        return [
            $magasin === null
                ? 'Magasin introuvable dans cette société : la procédure le refusera.'
                : "Magasin {$magasin->code} — {$magasin->nom} — SIRET {$magasin->siret} — territoire actuel : ".($magasin->territoire?->getLabel() ?? 'aucun').'.',
            'Le territoire que le répertoire Sirene lit aujourd\'hui pour son établissement remplacera le sien, avec son taux de TVA par défaut et son heure, seulement s\'il n\'a numéroté aucun document.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin')) ?? throw new LogicException(self::MESSAGE_MAGASIN_INTROUVABLE);
        $territoire = app(CorrigerLeTerritoireAction::class)->handle($magasin, $editeur);

        return "Territoire du magasin {$magasin->code} corrigé : {$territoire->getLabel()}.";
    }
}
