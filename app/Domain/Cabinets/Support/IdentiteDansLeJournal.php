<?php

namespace App\Domain\Cabinets\Support;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;

/**
 * Ce que le journal d'audit retient d'une société dans les entrées d'un
 * lien entre un cabinet et un client : son identifiant, son SIREN et sa
 * dénomination ; au journal d'un cabinet, d'une société qui n'a pas
 * accepté sa demande, seulement le SIREN que le cabinet a saisi, ni son
 * identifiant ni rien d'autre d'elle ; pour la déclaration de
 * l'expert-comptable d'une société, l'identité déclarée de son cabinet.
 */
final class IdentiteDansLeJournal
{
    /**
     * @return array{id: int, siren: string, raison_sociale: string|null}
     */
    public static function de(Societe $societe): array
    {
        return ['id' => CleDuModele::de($societe), 'siren' => (string) $societe->siren, 'raison_sociale' => $societe->raison_sociale];
    }

    /**
     * L'identité déclarée du cabinet de l'expert-comptable d'une société :
     * le SIREN déclaré et la dénomination que le répertoire Sirene lui
     * donnait le jour de la déclaration (nulle quand il la masque), sans
     * identifiant de société, la déclaration n'ayant pas de cabinet ; jamais
     * l'adresse ni l'identifiant d'une personne.
     *
     * @return array{siren: string, raison_sociale: string|null}
     */
    public static function declaree(LienCabinet $declaration): array
    {
        return ['siren' => (string) $declaration->siren_cabinet, 'raison_sociale' => $declaration->denomination_cabinet];
    }

    /**
     * @return array{siren: string}
     */
    public static function sirenSeul(Societe $societe): array
    {
        return ['siren' => (string) $societe->siren];
    }

    /**
     * Les magasins qu'un lien ouvre au cabinet, tels que le journal les
     * retient : « tous » (tous les magasins réels du client, présents et
     * futurs), ou chaque magasin choisi, son identifiant et son nom, par
     * identifiant croissant. Le journal du cabinet n'y lit que des magasins
     * qu'il voit désormais.
     *
     * @return 'tous'|list<array{id: int, nom: string}>
     */
    public static function magasinsOuverts(LienCabinet $lien): string|array
    {
        if ($lien->tous_les_magasins) {
            return 'tous';
        }

        return array_values(Magasin::query()
            ->where('societe_id', $lien->societe_id)
            ->whereIn('id', $lien->magasinsChoisis())
            ->orderBy('id')
            ->get(['id', 'nom'])
            ->map(fn (Magasin $magasin): array => ['id' => CleDuModele::de($magasin), 'nom' => (string) $magasin->nom])
            ->all());
    }
}
