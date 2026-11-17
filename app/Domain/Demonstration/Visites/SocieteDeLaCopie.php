<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Demonstration\Models\CompteDuBandeau;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Models\Societe;

/**
 * La société de la copie ouverte (lot DEMO-COPIE, C.8 et C.9) : la société
 * mise en avant de son modèle, celle de tous les comptes du bandeau
 * « Vous êtes : » (comptes_du_bandeau, écrits par le semis du modèle puis
 * à chaque compte créé dans la copie). Lue hors de tout contexte de
 * société, sur toute la base de la copie, seulement pour le bandeau et la
 * page « E-mails qui seraient partis » : c'est l'exception que la puce
 * « Produit » de CLAUDE.md écrit pour le site de démonstration. Un bandeau
 * vide ou qui mêlerait deux sociétés ne désigne aucune société.
 */
final class SocieteDeLaCopie
{
    public static function trouver(): ?Societe
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $societes = CompteDuBandeau::query()->withoutGlobalScopes()->distinct()->pluck('societe_id');

        return $societes->count() === 1 ? Societe::query()->find($societes->first()) : null;
    }
}
