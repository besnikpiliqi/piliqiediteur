<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\AnnulerCessionAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * L'annulation par l'éditeur de la cession d'un magasin faite par erreur
 * (voir AnnulerCessionAction), procédure grave lancée par deux opérateurs
 * distincts, seulement si aucune opération n'a suivi la cession : le stock
 * sorti rentre au même coût, et le magasin retrouve son motif de
 * fermeture d'avant la cession, ou devient fermé temporairement s'il était
 * ouvert ; la société le rouvre elle-même.
 */
#[Signature('editeur:magasin:annuler-cession {societe : Identifiant ou uuid de la société} {magasin : Code du magasin dans la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Annule la cession d\'un magasin faite par erreur, tant qu\'aucune opération ne l\'a suivie')]
class AnnulerLaCession extends ProcedureDeLEditeur
{
    protected function operateursExiges(): int
    {
        return 2;
    }

    protected function resume(Societe $societe): array
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin'));

        return [
            $magasin === null
                ? 'Magasin introuvable dans cette société : la procédure le refusera.'
                : "Magasin {$magasin->code} — {$magasin->nom} — SIRET {$magasin->siret} — ".($magasin->aEteCede() ? 'cédé.' : 'pas cédé : la procédure le refusera.'),
            'Le stock sorti à la cession rentrera au même coût, et le magasin retrouvera son motif de fermeture d\'avant la cession (fermé temporairement s\'il était ouvert), seulement si aucune opération n\'a suivi la cession.',
            'Procédure grave : deux opérateurs distincts s\'authentifient chacun par son secret.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin')) ?? throw new LogicException(self::MESSAGE_MAGASIN_INTROUVABLE);
        $motif = app(AnnulerCessionAction::class)->handle($magasin, $editeur);

        $etat = $motif === MotifFermeture::Cessation ? 'fermé pour cessation d\'activité' : 'fermé temporairement';

        return "Cession du magasin {$magasin->code} annulée : son stock est rentré, et il est {$etat}.";
    }
}
